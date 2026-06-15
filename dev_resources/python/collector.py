"""
OLT SSH/Telnet Collector
========================

A tiny web service that logs into an OLT over SSH or Telnet, runs the vendor's
command-line commands, and returns the result as JSON. Its job is to fetch the
things SNMP CANNOT give us on old OLTs — mainly:

  * user / CPE MAC addresses
  * ONT optical power (Rx/Tx) on old firmware like Huawei MA5683T V800R018

The Laravel app calls this service locally (http://127.0.0.1:8800). Laravel
sends the OLT address + login + which task to run; this service does the SSH/
Telnet work and hands back clean JSON. Laravel never has to speak Telnet itself.

SECURITY: run this bound to 127.0.0.1 ONLY (see the README), and protect it with
the COLLECTOR_API_KEY header so nothing else on the box can use it.

The one part you may edit later is the "PARSERS" section near the bottom, once you have seen real OLT output via
the /raw endpoint. The README explains exactly how.
"""

import os
import re
import time
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeout
from typing import Optional

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel
from netmiko import ConnectHandler
from netmiko.huawei.huawei import HuaweiTelnet
from netmiko.ssh_dispatcher import CLASS_MAPPER

# The shared secret Laravel must send in the "X-Collector-Key" header.
# It is read from the environment (set in the .env / systemd file). If it is
# left as the default, the service still runs but is effectively unprotected —
# always set a real value in production.
API_KEY = os.environ.get("COLLECTOR_API_KEY", "change-me")
SESSION_LOG = os.environ.get("COLLECTOR_SESSION_LOG")  # optional netmiko trace file
JOB_TIMEOUT = int(os.environ.get("COLLECTOR_JOB_TIMEOUT", "240"))  # wall-clock cap per HTTP call

# Huawei MA5683T prompts end with > or # (sometimes after banner text).
HUAWEI_PROMPT_RE = re.compile(r"[>#]\s*$")
# Incomplete "display" command interactive menu (we landed here if a space broke the line).
HUAWEI_DISPLAY_MENU_RE = re.compile(r"\{\s*<cr>\|", re.IGNORECASE)

app = FastAPI(title="OLT SSH/Telnet Collector", version="1.0")


class HuaweiOltTelnet(HuaweiTelnet):
    """Huawei MA5600/MA5683T telnet login, without auto screen-length in session prep.

    Stock huawei_telnet calls disable_paging() during connect, which fails on
    many MA5683T builds. generic_telnet does not know Huawei login prompts and
    only echoes typed text (display + bell + version). This driver fixes both.
    """

    def session_preparation(self) -> None:
        # set_base_prompt() can block on noisy banners — keep it short.
        try:
            self.set_base_prompt(pattern=r"[>#]")
        except Exception:
            self.base_prompt = ">"
            self.prompt = r"\>"


# Register once so ConnectHandler(device_type="huawei_olt_telnet", ...) works.
CLASS_MAPPER["huawei_olt_telnet"] = HuaweiOltTelnet


def _is_huawei_telnet(device_type: str) -> bool:
    return device_type in ("huawei_olt_telnet", "huawei_telnet")


def _huawei_telnet_line(command: str, style: str = "space") -> str:
    """Build a command line for MA5683T telnet.

    style="space" (default): the normal way — words separated by spaces, then
        ENTER. Correct for multi-word commands like
        'display ont optical-info 0/1 0 all'.
    style="tab": send TAB between words instead of space. Only needed on odd
        firmware where a space triggers early tab-completion; can itself pop up
        completion menus on multi-word commands, so it is NOT the default.
    """
    command = command.strip()
    if style == "tab":
        parts = command.split()
        if len(parts) <= 1:
            return (parts[0] if parts else "") + "\r"
        return parts[0] + "".join("\t" + p for p in parts[1:]) + "\r"
    return command + "\r"


def _strip_async_alarms(text: str) -> str:
    """Remove Huawei async alarm/event blocks the OLT prints into the session.

    These start with a line like '! FAULT MINOR ...' followed by indented
    continuation lines (ALARM NAME / PARAMETERS / ...). They corrupt command
    output, so we drop the whole block. (We also try to suppress them at the
    source with 'undo alarm output all' during session prep.)
    """
    cleaned = []
    in_alarm = False
    for line in text.splitlines():
        if re.match(r"^\s*!\s*(FAULT|RESUME|ALARM|EVENT|NOTIFICATION)", line, re.IGNORECASE):
            in_alarm = True
            continue
        if in_alarm:
            # Continuation lines are blank or indented; a non-indented line ends it.
            if line.strip() == "" or line[:1].isspace():
                continue
            in_alarm = False
        cleaned.append(line)
    return "\n".join(cleaned)


def _drain_channel(conn, seconds: float = 1.5) -> str:
    """Read any login banners / async alarms before the first command."""
    return _read_channel_until_prompt(conn, read_timeout=seconds, idle_seconds=0.4)


def _abort_to_idle(conn, read_timeout: float = 5) -> None:
    """Ctrl+C out of partial commands / interactive menus back to MA5683T>."""
    for _ in range(3):
        conn.write_channel("\x03")
        time.sleep(0.12)
    _read_channel_until_prompt(conn, read_timeout=read_timeout, idle_seconds=0.4)


def _read_channel_until_prompt(conn, read_timeout: float, idle_seconds: float = 2.0) -> str:
    """Read telnet output until Huawei prompt or timeout (no regex prompt matching)."""
    output = ""
    deadline = time.monotonic() + read_timeout
    idle_deadline = None

    while time.monotonic() < deadline:
        chunk = conn.read_channel()
        if chunk:
            output += chunk
            idle_deadline = None
            if "---- More" in chunk or "----More" in chunk:
                conn.write_channel(" ")
                continue
            tail = output.splitlines()[-1] if output.splitlines() else ""
            if HUAWEI_PROMPT_RE.search(tail):
                break
            if HUAWEI_DISPLAY_MENU_RE.search(tail):
                break
        else:
            if idle_deadline is None:
                idle_deadline = time.monotonic() + idle_seconds
            elif time.monotonic() >= idle_deadline:
                break
            time.sleep(0.15)

    return output


def _send_channel(conn, command: str, read_timeout: float = 60) -> str:
    """Low-level send for Huawei telnet — avoids send_command prompt hangs."""
    _abort_to_idle(conn)
    conn.write_channel(_huawei_telnet_line(command))
    output = _read_channel_until_prompt(conn, read_timeout=read_timeout)

    # Still in the 'display' submenu or only saw async alarms — retry once.
    if HUAWEI_DISPLAY_MENU_RE.search(output) or (
        "version" in command.lower() and "VERSION" not in output.upper() and "VRP" not in output
    ):
        _abort_to_idle(conn)
        conn.write_channel(_huawei_telnet_line(command))
        output = _read_channel_until_prompt(conn, read_timeout=read_timeout)

    return output


# ---------------------------------------------------------------------------
# Request shape: what Laravel sends us for every call.
# ---------------------------------------------------------------------------
class OltRequest(BaseModel):
    host: str                       # OLT IP, e.g. "172.16.29.5"
    username: str                   # OLT login user
    password: str                   # OLT login password
    protocol: str = "ssh"           # "ssh" or "telnet"
    port: Optional[int] = None      # defaults: 22 for ssh, 23 for telnet
    device_type: Optional[str] = None  # advanced: override netmiko driver
    command: Optional[str] = None   # only used by /raw

    # Some OLTs (Huawei) need a port + ONT id to target one ONU; optional.
    frame_slot_port: Optional[str] = None  # e.g. "0/1/0"
    ont_id: Optional[int] = None


# ---------------------------------------------------------------------------
# Connection helpers
# ---------------------------------------------------------------------------
def _device_type(req: OltRequest) -> str:
    """Pick the netmiko 'driver' for this OLT.

    Recommended values:
      Huawei OLT over SSH    -> "huawei_smartax"
      Huawei OLT over Telnet -> "huawei_olt_telnet" (default)
      Legacy / debug         -> "huawei_telnet", "generic_telnet"
    """
    if req.device_type:
        return req.device_type
    if req.protocol == "telnet":
        return "huawei_olt_telnet"
    return "huawei_smartax"


def _connect(req: OltRequest):
    device_type = _device_type(req)
    kwargs = dict(
        device_type=device_type,
        host=req.host,
        username=req.username,
        password=req.password,
        port=req.port or (23 if req.protocol == "telnet" else 22),
        fast_cli=False,
        conn_timeout=30,
        auth_timeout=30,
        read_timeout_override=60,
        global_cmd_verify=False,
    )
    if SESSION_LOG:
        kwargs["session_log"] = SESSION_LOG
        kwargs["session_log_record"] = True
    return ConnectHandler(**kwargs)


def _prep_session(conn, device_type: str, protocol: str) -> None:
    """Disable paging after login. Failures are ignored (model/firmware vary)."""
    if _is_huawei_telnet(device_type):
        _drain_channel(conn, seconds=2)
        # MA5683T V800R018: prefer scroll; screen-length often missing or interactive.
        prep_commands = ("scroll 512", "screen-length 0 temporary", "screen-length 0")
    else:
        prep_commands = ("screen-length 0 temporary", "screen-length 0", "scroll 512")

    for prep in prep_commands:
        try:
            if _is_huawei_telnet(device_type):
                _send_channel(conn, prep, read_timeout=8)
            else:
                conn.send_command(prep, read_timeout=10, cmd_verify=False)
        except Exception:
            pass


def _command_timeout(command: str) -> float:
    """Per-command read budget (seconds)."""
    if "mac-address all" in command:
        return 180.0
    if "optical-info" in command and " all" in command:
        return 120.0
    return 45.0


def _send(conn, device_type: str, command: str) -> str:
    read_timeout = _command_timeout(command)
    if _is_huawei_telnet(device_type):
        return _send_channel(conn, command, read_timeout=read_timeout)
    return conn.send_command(command, read_timeout=int(read_timeout), cmd_verify=False)


def _run_commands_body(req: OltRequest, commands: list[str]) -> str:
    """Open one session, run several commands, return all output joined."""
    out = []
    device_type = _device_type(req)
    protocol = "telnet" if req.protocol == "telnet" else "ssh"
    with _connect(req) as conn:
        if protocol != "telnet":
            try:
                conn.enable()
            except Exception:
                pass
        _prep_session(conn, device_type, protocol)
        for cmd in commands:
            out.append(f"### {cmd}\n" + _send(conn, device_type, cmd))
    return "\n".join(out)


def _run_commands(req: OltRequest, commands: list[str]) -> str:
    """Wall-clock cap so Laravel always gets a JSON error instead of hanging."""
    with ThreadPoolExecutor(max_workers=1) as pool:
        future = pool.submit(_run_commands_body, req, commands)
        try:
            return future.result(timeout=JOB_TIMEOUT)
        except FuturesTimeout as exc:
            raise HTTPException(
                status_code=504,
                detail=f"OLT session timed out after {JOB_TIMEOUT}s (login or command still running)",
            ) from exc


def _auth(key: Optional[str]):
    if key != API_KEY:
        raise HTTPException(status_code=401, detail="Bad or missing collector API key")


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------
@app.get("/health")
def health():
    """Quick check that the service is up. No login is performed."""
    return {"status": "ok"}


@app.post("/raw")
def raw(req: OltRequest, x_collector_key: Optional[str] = Header(None)):
    """DISCOVERY TOOL. Run ANY command and get the raw text back.

    Use this first: send the real command for your OLT, look at the output, then
    adjust the parsers below (or send the output to your developer)."""
    _auth(x_collector_key)
    if not req.command:
        raise HTTPException(status_code=400, detail="`command` is required for /raw")
    try:
        return {"output": _run_commands(req, [req.command])}
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"OLT connection/command failed: {e}")


@app.post("/onu/optical")
def onu_optical(req: OltRequest, x_collector_key: Optional[str] = Header(None)):
    """Return parsed ONT optical power (Rx/Tx in dBm)."""
    _auth(x_collector_key)
    cmd = _huawei_optical_command(req)
    try:
        text = _run_commands(req, [cmd])
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"OLT connection/command failed: {e}")
    return {"command": cmd, "rows": parse_huawei_optical(text), "raw": text}


@app.post("/onu/mac")
def onu_mac(req: OltRequest, x_collector_key: Optional[str] = Header(None)):
    """Return parsed CPE/user MAC addresses learned behind the ONUs."""
    _auth(x_collector_key)
    cmd = _huawei_mac_command(req)
    try:
        text = _run_commands(req, [cmd])
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"OLT connection/command failed: {e}")
    return {"command": cmd, "rows": parse_huawei_mac(text), "raw": text}


# ===========================================================================
# PARSERS  — the only part you are likely to edit, AFTER seeing real /raw output
# ===========================================================================
#
# Different Huawei firmwares print these tables slightly differently. The regexes
# below cover the common MA5600/MA5683T format. If your output looks different,
# run /raw, copy the text, and adjust the patterns (or send it to your developer
# and they will adjust these for you).

def _huawei_optical_command(req: OltRequest) -> str:
    # Whole-port dump (all ONTs on a PON port). Needs frame_slot_port like "0/1/0".
    fsp = req.frame_slot_port or "0/1/0"
    if req.ont_id is not None:
        return f"display ont optical-info {fsp} {req.ont_id}"
    return f"display ont optical-info {fsp} all"


def _huawei_mac_command(req: OltRequest) -> str:
    # Learned MAC table. On MA5600: "display mac-address all" (can be large) or
    # per ONT: "display mac-address ont-id <fsp> <ont-id>".
    if req.frame_slot_port and req.ont_id is not None:
        return f"display mac-address ont-id {req.frame_slot_port} {req.ont_id}"
    return "display mac-address all"


def parse_huawei_optical(text: str) -> list[dict]:
    """Extract Rx/Tx power. Matches lines/blocks containing both values.

    Typical Huawei block:
        ONT-ID :  1
        Rx optical power(dBm) :  -18.55
        Tx optical power(dBm) :  2.31
    """
    rows = []
    # Strategy: find each ONT-ID, then the nearest Rx/Tx values after it.
    blocks = re.split(r"ONT[-\s]?ID\s*[:=]\s*(\d+)", text, flags=re.IGNORECASE)
    # re.split keeps the captured ont-id as separate items: [pre, id, body, id, body, ...]
    for i in range(1, len(blocks), 2):
        ont_id = blocks[i]
        body = blocks[i + 1] if i + 1 < len(blocks) else ""
        rx = re.search(r"Rx\s*(?:optical\s*)?power.*?(-?\d+\.\d+)", body, re.IGNORECASE)
        tx = re.search(r"Tx\s*(?:optical\s*)?power.*?(-?\d+\.\d+)", body, re.IGNORECASE)
        if rx or tx:
            rows.append({
                "ont_id": int(ont_id),
                "rx_power": float(rx.group(1)) if rx else None,
                "tx_power": float(tx.group(1)) if tx else None,
            })

    # Fallback: single-line "... -18.55 ... 2.31 ..." table rows.
    if not rows:
        for m in re.finditer(
            r"(-?\d+\.\d+)\s+(-?\d+\.\d+)", text
        ):
            a, b = float(m.group(1)), float(m.group(2))
            # Rx is the negative one, Tx the small positive one.
            rx_val, tx_val = (a, b) if a < b else (b, a)
            rows.append({"ont_id": None, "rx_power": rx_val, "tx_power": tx_val})
    return rows


def parse_huawei_mac(text: str) -> list[dict]:
    """Extract MAC addresses and (when present) the port/ONT they sit behind.

    Matches MACs in Huawei dotted form (00e0-fc12-3456) or colon/hyphen form.
    """
    rows = []
    mac_re = re.compile(
        r"([0-9A-Fa-f]{4}[-.][0-9A-Fa-f]{4}[-.][0-9A-Fa-f]{4}"      # 00e0-fc12-3456
        r"|(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2})"                  # 00:e0:fc:12:34:56
    )
    for line in text.splitlines():
        m = mac_re.search(line)
        if not m:
            continue
        # Try to pick up a "0/1/0" frame/slot/port and an ont id on the same line.
        fsp = re.search(r"\b(\d+/\d+/\d+)\b", line)
        ont = re.search(r"\bont[-\s]?id?\s*[:=]?\s*(\d+)\b", line, re.IGNORECASE)
        rows.append({
            "mac": _normalise_mac(m.group(1)),
            "frame_slot_port": fsp.group(1) if fsp else None,
            "ont_id": int(ont.group(1)) if ont else None,
        })
    return rows


def _normalise_mac(raw: str) -> str:
    hexonly = re.sub(r"[^0-9A-Fa-f]", "", raw).upper()
    return ":".join(hexonly[i:i + 2] for i in range(0, 12, 2)) if len(hexonly) == 12 else raw.upper()
