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
from typing import Optional

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel
from netmiko import ConnectHandler

# The shared secret Laravel must send in the "X-Collector-Key" header.
# It is read from the environment (set in the .env / systemd file). If it is
# left as the default, the service still runs but is effectively unprotected —
# always set a real value in production.
API_KEY = os.environ.get("COLLECTOR_API_KEY", "change-me")

app = FastAPI(title="OLT SSH/Telnet Collector", version="1.0")


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
      Huawei OLT over Telnet -> "huawei_telnet"  (try "generic_telnet" if needed)
      Anything else          -> "generic"  /  "generic_telnet"
    """
    if req.device_type:
        return req.device_type
    if req.protocol == "telnet":
        return "huawei_telnet"
    return "huawei_smartax"


def _connect(req: OltRequest):
    return ConnectHandler(
        device_type=_device_type(req),
        host=req.host,
        username=req.username,
        password=req.password,
        port=req.port or (23 if req.protocol == "telnet" else 22),
        fast_cli=False,
        conn_timeout=20,
        read_timeout_override=60,
    )


def _auth(key: Optional[str]):
    if key != API_KEY:
        raise HTTPException(status_code=401, detail="Bad or missing collector API key")


def _run_commands(req: OltRequest, commands: list[str]) -> str:
    """Open one session, run several commands, return all output joined."""
    out = []
    with _connect(req) as conn:
        try:
            conn.enable()          # harmless if the device has no enable mode
        except Exception:
            pass
        # Disable terminal paging so long tables aren't broken by "---- More ----".
        for prep in ("scroll 512", "screen-length 0 temporary"):
            try:
                conn.send_command(prep, read_timeout=15)
            except Exception:
                pass
        for cmd in commands:
            out.append(f"### {cmd}\n" + conn.send_command(cmd, read_timeout=90))
    return "\n".join(out)


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
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"OLT connection/command failed: {e}")


@app.post("/onu/optical")
def onu_optical(req: OltRequest, x_collector_key: Optional[str] = Header(None)):
    """Return parsed ONT optical power (Rx/Tx in dBm)."""
    _auth(x_collector_key)
    cmd = _huawei_optical_command(req)
    try:
        text = _run_commands(req, [cmd])
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
