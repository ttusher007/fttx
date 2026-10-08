"""
OLT SSH/Telnet Collector (v2)
=============================

A tiny local web service that logs into an OLT over SSH or Telnet, runs a
list of CLI commands in ONE session and returns the raw text of each command
as JSON. It is *transport only* — all parsing happens in the Laravel app
(app/Services/Olt/Cli), so tuning commands or parsers never requires editing
or restarting this service.

Why it exists: some firmwares do not expose everything over SNMP (Huawei
MA5683T V800R018 has no per-ONT optical table; customer MACs are not in any
Huawei MIB). The only way to read those is to log in like a human would.

Design notes
------------
* Own minimal Telnet client (socket + IAC negotiation) instead of netmiko /
  telnetlib: old Huawei OLTs are picky about how fast characters arrive, so we
  can type with a per-character delay, and telnetlib was removed in Python 3.13.
* SSH through paramiko's interactive shell, with legacy KEX/ciphers enabled
  (MA5600T-era devices only speak diffie-hellman-group1 / ssh-rsa / CBC).
* Prompt detection is generic ("...>", "...#", "...]") and can be overridden
  per request with `options.prompt_regex`.
* Paging prompts ("---- More ----", "--More--", "Press any key") are answered
  automatically; Huawei's "{ <cr>|... }:" parameter menus get an ENTER.
* Every HTTP call is capped by COLLECTOR_JOB_TIMEOUT so Laravel always gets a
  JSON answer instead of hanging.

SECURITY: bind to 127.0.0.1 ONLY and protect it with COLLECTOR_API_KEY (the
Laravel app sends it in the X-Collector-Key header).
"""

from __future__ import annotations

import os
import re
import select
import socket
import time
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeout
from typing import Optional

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

VERSION = "2.0.0"

API_KEY = os.environ.get("COLLECTOR_API_KEY", "change-me")
JOB_TIMEOUT = int(os.environ.get("COLLECTOR_JOB_TIMEOUT", "1500"))  # wall-clock cap per HTTP call
SESSION_LOG = os.environ.get("COLLECTOR_SESSION_LOG")  # optional file: full transcript of every session

app = FastAPI(title="OLT SSH/Telnet Collector", version=VERSION)


# ---------------------------------------------------------------------------
# Request / response shapes
# ---------------------------------------------------------------------------
class RunOptions(BaseModel):
    char_delay: Optional[float] = None       # seconds between characters when typing (telnet default 0.01)
    command_timeout: Optional[float] = None  # max seconds to wait for a command to finish (default 120)
    login_timeout: Optional[float] = None    # max seconds for the login phase (default 40)
    prompt_regex: Optional[str] = None       # regex that matches the END of a prompt line
    enable_password: Optional[str] = None    # if "enable" asks for a password


class RunRequest(BaseModel):
    host: str
    username: str
    password: str
    protocol: str = "ssh"            # "ssh" | "telnet"
    port: Optional[int] = None       # default 22 / 23
    vendor: Optional[str] = None     # huawei | bdcom | vsol | ... (picks sensible defaults)
    prep: list[str] = Field(default_factory=list)      # run after login, output not returned (still logged)
    commands: list[str] = Field(default_factory=list)  # data commands, output returned per command
    options: RunOptions = Field(default_factory=RunOptions)
    # Backwards compatibility with the v1 /raw endpoint.
    command: Optional[str] = None


# ---------------------------------------------------------------------------
# Prompt / paging patterns
# ---------------------------------------------------------------------------
# A prompt is a short last line ending in >, #, or ] (optionally followed by a
# space). Examples: "MA5683T>", "MA5683T(config)#", "MA5683T(config-if-gpon-0/1)#",
# "Switch_config#", "OLT(config)#", "<HUAWEI>", "[HUAWEI]".
DEFAULT_PROMPT_RE = re.compile(r"^[^\r\n]{0,80}?[>#\]]\s?$", re.MULTILINE)

MORE_RE = re.compile(
    r"-{2,}\s*More\s*(?:\(\s*Press\s+'?Q'?\s+to\s+break\s*\))?\s*-{2,}"   # Huawei "---- More ( Press 'Q' to break ) ----"
    r"|--\s*More\s*--"                                                   # Cisco-like "--More--"
    r"|Press any key to continue"
    r"|\(Q to quit\)",
    re.IGNORECASE,
)
# Huawei parameter menu: "{ <cr>|backplane<K>|frameid/slotid<S><Length 1-15> }:"
HUAWEI_MENU_RE = re.compile(r"\{\s*<cr>[^}]*\}\s*:\s*$", re.MULTILINE)
# Yes/no confirmations we are happy to answer "y" to (only for display-style questions).
CONFIRM_RE = re.compile(r"\(y/n\)\s*\[?[yn]?\]?\s*:?\s*$|Are you sure[^\n]*\?\s*$", re.IGNORECASE | re.MULTILINE)

LOGIN_USER_RE = re.compile(r"(?:user\s*name|username|login)\s*:\s*$", re.IGNORECASE | re.MULTILINE)
LOGIN_PASS_RE = re.compile(r"password\s*:\s*$", re.IGNORECASE | re.MULTILINE)
LOGIN_FAIL_RE = re.compile(
    r"Reenter times|Username or password invalid|Login incorrect|Authentication failed|Access denied|invalid user|Bad password",
    re.IGNORECASE,
)

ANSI_RE = re.compile(r"\x1b\[[0-9;?]*[A-Za-z]|\x1b[=>]")


def _clean(text: str) -> str:
    """Strip ANSI escapes, NULs, bells, carriage returns and backspace echoes."""
    text = ANSI_RE.sub("", text)
    text = text.replace("\x00", "").replace("\x07", "")
    # Backspace handling: "abc\x08\x08\x08   \x08\x08\x08" → cursor games used when a More prompt is erased.
    while "\x08" in text:
        text = re.sub(r"[^\x08]\x08", "", text, count=1) if re.search(r"[^\x08]\x08", text) else text.replace("\x08", "")
    return text.replace("\r\n", "\n").replace("\r", "\n")


# ---------------------------------------------------------------------------
# Transports
# ---------------------------------------------------------------------------
class TelnetTransport:
    """Minimal Telnet client: handles IAC option negotiation (refusing
    everything except ECHO/SGA from the server) and exposes read/write."""

    IAC, DONT, DO, WONT, WILL, SB, SE = 255, 254, 253, 252, 251, 250, 240
    ECHO, SGA, TTYPE, NAWS = 1, 3, 24, 31

    def __init__(self, host: str, port: int, timeout: float = 30.0):
        self.sock = socket.create_connection((host, port), timeout=timeout)
        self.sock.setblocking(False)
        self._iac_buf = b""

    def read(self, timeout: float) -> str:
        r, _, _ = select.select([self.sock], [], [], timeout)
        if not r:
            return ""
        try:
            data = self.sock.recv(65535)
        except (BlockingIOError, InterruptedError):
            return ""
        if not data:
            raise ConnectionError("Telnet connection closed by the OLT")
        return self._negotiate(self._iac_buf + data)

    def _negotiate(self, data: bytes) -> str:
        out = bytearray()
        i = 0
        self._iac_buf = b""
        while i < len(data):
            b = data[i]
            if b != self.IAC:
                out.append(b)
                i += 1
                continue
            if i + 1 >= len(data):
                self._iac_buf = data[i:]
                break
            cmd = data[i + 1]
            if cmd == self.IAC:
                out.append(self.IAC)
                i += 2
                continue
            if cmd in (self.DO, self.DONT, self.WILL, self.WONT):
                if i + 2 >= len(data):
                    self._iac_buf = data[i:]
                    break
                opt = data[i + 2]
                if cmd == self.DO:
                    # We refuse to do anything (no TTYPE/NAWS) — plain dumb terminal.
                    self._send_raw(bytes([self.IAC, self.WONT, opt]))
                elif cmd == self.WILL:
                    reply = self.DO if opt in (self.ECHO, self.SGA) else self.DONT
                    self._send_raw(bytes([self.IAC, reply, opt]))
                i += 3
                continue
            if cmd == self.SB:
                end = data.find(bytes([self.IAC, self.SE]), i)
                if end == -1:
                    self._iac_buf = data[i:]
                    break
                i = end + 2
                continue
            i += 2  # other 2-byte commands (NOP, GA, ...)
        return out.decode("latin-1")

    def _send_raw(self, b: bytes) -> None:
        try:
            self.sock.sendall(b)
        except OSError:
            pass

    def write(self, text: str, char_delay: float = 0.0) -> None:
        data = text.encode("latin-1", errors="replace").replace(b"\xff", b"\xff\xff")
        if char_delay <= 0:
            self.sock.sendall(data)
            return
        for ch in data:
            self.sock.sendall(bytes([ch]))
            time.sleep(char_delay)

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass


class SshTransport:
    """paramiko interactive shell with legacy algorithms enabled."""

    def __init__(self, host: str, port: int, username: str, password: str, timeout: float = 30.0):
        import paramiko  # imported lazily so the telnet-only path has no hard dependency at import time

        sock = socket.create_connection((host, port), timeout=timeout)
        self.transport = paramiko.Transport(sock)
        self.transport.banner_timeout = timeout
        self.transport.handshake_timeout = timeout
        opts = self.transport.get_security_options()
        try:
            opts.kex = tuple(
                list(opts.kex)
                + [k for k in ("diffie-hellman-group1-sha1", "diffie-hellman-group14-sha1", "diffie-hellman-group-exchange-sha1") if k not in opts.kex]
            )
            opts.ciphers = tuple(
                list(opts.ciphers)
                + [c for c in ("aes128-cbc", "aes256-cbc", "3des-cbc", "aes192-cbc") if c not in opts.ciphers]
            )
            opts.key_types = tuple(list(opts.key_types) + [k for k in ("ssh-rsa", "ssh-dss") if k not in opts.key_types])
        except ValueError:
            pass  # algorithm unknown to this paramiko build — fine
        self.transport.start_client(timeout=timeout)
        try:
            self.transport.auth_password(username, password)
        except paramiko.BadAuthenticationType:
            # Some OLTs only offer keyboard-interactive.
            self.transport.auth_interactive(username, lambda title, instr, prompts: [password for _ in prompts])
        self.chan = self.transport.open_session()
        self.chan.get_pty(term="vt100", width=512, height=1000)
        self.chan.invoke_shell()
        self.chan.settimeout(0.0)

    def read(self, timeout: float) -> str:
        r, _, _ = select.select([self.chan], [], [], timeout)
        if not r:
            return ""
        if self.chan.recv_ready():
            data = self.chan.recv(65535)
            if not data:
                raise ConnectionError("SSH channel closed by the OLT")
            return data.decode("latin-1")
        if self.chan.closed or self.chan.eof_received:
            raise ConnectionError("SSH channel closed by the OLT")
        return ""

    def write(self, text: str, char_delay: float = 0.0) -> None:
        data = text.encode("latin-1", errors="replace")
        if char_delay <= 0:
            self.chan.sendall(data)
            return
        for ch in data:
            self.chan.sendall(bytes([ch]))
            time.sleep(char_delay)

    def close(self) -> None:
        try:
            self.chan.close()
        finally:
            try:
                self.transport.close()
            except Exception:
                pass


# ---------------------------------------------------------------------------
# Session driver (vendor-agnostic)
# ---------------------------------------------------------------------------
class Session:
    def __init__(self, req: RunRequest):
        self.req = req
        self.protocol = "telnet" if req.protocol == "telnet" else "ssh"
        self.port = req.port or (23 if self.protocol == "telnet" else 22)
        vendor = (req.vendor or "").lower()
        o = req.options
        self.char_delay = o.char_delay if o.char_delay is not None else (0.01 if self.protocol == "telnet" else 0.0)
        self.command_timeout = o.command_timeout or 120.0
        self.login_timeout = o.login_timeout or 40.0
        self.prompt_re = re.compile(o.prompt_regex, re.MULTILINE) if o.prompt_regex else DEFAULT_PROMPT_RE
        self.huawei = vendor == "huawei"
        self.log: list[str] = []
        self.prompt: str = ""
        self.transport = None

    # -- low level ----------------------------------------------------------
    def _log(self, text: str) -> None:
        if text:
            self.log.append(text)
            if SESSION_LOG:
                try:
                    with open(SESSION_LOG, "a", encoding="utf-8", errors="replace") as fh:
                        fh.write(text)
                except OSError:
                    pass

    def _write(self, text: str) -> None:
        self._log(f"\n>>> {text!r}\n")
        self.transport.write(text, self.char_delay)

    def _is_prompt_line(self, line: str) -> bool:
        line = line.rstrip()
        if not line or len(line) > 90:
            return False
        return bool(self.prompt_re.search(line))

    def _read_until_prompt(self, timeout: float, idle: float = 1.0, answer_pages: bool = True) -> str:
        """Read until the last line looks like a prompt, or `idle` seconds pass
        with no data after at least one byte, or `timeout` is exceeded."""
        buf = ""
        deadline = time.monotonic() + timeout
        last_data = time.monotonic()
        while time.monotonic() < deadline:
            chunk = self.transport.read(0.2)
            if chunk:
                self._log(chunk)
                buf += chunk
                last_data = time.monotonic()
                clean = _clean(buf)
                tail = clean.rstrip("\n ").split("\n")[-1] if clean.strip() else ""
                if answer_pages and MORE_RE.search(tail):
                    self.transport.write(" ", 0.0)
                    buf = MORE_RE.sub("", buf) if len(buf) < 200_000 else buf
                    continue
                if self.huawei and HUAWEI_MENU_RE.search(clean[-300:]):
                    self.transport.write("\r", 0.0)
                    continue
                if answer_pages and CONFIRM_RE.search(clean[-200:]):
                    self.transport.write("y\r", 0.0)
                    continue
                if self._is_prompt_line(tail):
                    # Short grace period: a prompt-looking line might still be output in flight.
                    extra = self.transport.read(0.15)
                    if extra:
                        buf += extra
                        self._log(extra)
                        continue
                    return _clean(buf)
            else:
                if buf and time.monotonic() - last_data > idle:
                    return _clean(buf)
        return _clean(buf)

    # -- login -----------------------------------------------------------------
    def connect(self) -> None:
        if self.protocol == "telnet":
            self.transport = TelnetTransport(self.req.host, self.port, timeout=min(30.0, self.login_timeout))
            self._telnet_login()
        else:
            self.transport = SshTransport(self.req.host, self.port, self.req.username, self.req.password, timeout=min(30.0, self.login_timeout))
            text = self._read_until_prompt(self.login_timeout, idle=1.5)
            if LOGIN_PASS_RE.search(text):  # some OLTs ask again inside the shell
                self._write(self.req.password + "\r")
                text = self._read_until_prompt(self.login_timeout, idle=1.5)
            self._capture_prompt(text)
        if not self.prompt:
            # Nudge with ENTER and try once more.
            self._write("\r")
            self._capture_prompt(self._read_until_prompt(10, idle=1.0))
        if not self.prompt:
            raise RuntimeError("Logged in but could not detect the CLI prompt. Transcript tail: " + repr(_clean("".join(self.log))[-400:]))

    def _telnet_login(self) -> None:
        deadline = time.monotonic() + self.login_timeout
        sent_user = sent_pass = False
        buf = ""
        while time.monotonic() < deadline:
            chunk = self.transport.read(0.3)
            if chunk:
                self._log(chunk)
                buf += chunk
            clean = _clean(buf)
            if LOGIN_FAIL_RE.search(clean):
                raise RuntimeError("OLT rejected the login (bad username/password?). Transcript tail: " + repr(clean[-300:]))
            tail = clean.rstrip(" ").split("\n")[-1] if clean else ""
            if not sent_user and LOGIN_USER_RE.search(tail):
                self._write(self.req.username + "\r")
                sent_user = True
                buf = ""
                continue
            if not sent_pass and LOGIN_PASS_RE.search(tail):
                self._write(self.req.password + "\r")
                sent_pass = True
                buf = ""
                continue
            if sent_pass and self._is_prompt_line(tail):
                self._capture_prompt(clean)
                return
            if not chunk and not sent_user and time.monotonic() - deadline > -self.login_timeout + 3 and not buf:
                # Nothing arrived yet: some devices wait for us first.
                self._write("\r")
        raise RuntimeError("Telnet login timed out (no prompt). Transcript tail: " + repr(_clean(buf)[-300:]))

    def _capture_prompt(self, text: str) -> None:
        lines = [l for l in text.rstrip("\n ").split("\n") if l.strip()]
        if lines and self._is_prompt_line(lines[-1]):
            self.prompt = lines[-1].strip()

    # -- commands ----------------------------------------------------------------
    def run(self, command: str, timeout: Optional[float] = None) -> tuple[str, Optional[str]]:
        """Send one command and return (output_without_echo_and_prompt, error)."""
        timeout = timeout or self.command_timeout
        self._write(command + "\r")
        raw = self._read_until_prompt(timeout, idle=max(2.0, min(8.0, timeout / 10)))

        lines = raw.split("\n")
        # Drop the echoed command (first non-empty line that contains it).
        for i, line in enumerate(lines[:3]):
            if command.strip() and command.strip() in line:
                lines = lines[i + 1:]
                break
        # Drop the trailing prompt.
        while lines and not lines[-1].strip():
            lines.pop()
        if lines and self._is_prompt_line(lines[-1]):
            self.prompt = lines[-1].strip()
            lines.pop()
        output = "\n".join(lines).strip("\n")

        error = None
        if re.search(r"^\s*(%|Error:|\^\s*$)", output, re.MULTILINE) and re.search(
            r"Unknown command|Incomplete command|Wrong parameter|Unrecognized|Invalid input|Parameter error|Too many parameters|Failure", output, re.IGNORECASE
        ):
            error = next((l.strip() for l in lines if re.search(r"Unknown|Incomplete|Wrong|Unrecognized|Invalid|error|Failure", l, re.IGNORECASE)), "command rejected")
        elif not output and timeout and len(raw) == 0:
            error = f"no output within {timeout:.0f}s"
        return output, error

    def close(self) -> None:
        if self.transport:
            try:
                self._write("quit\r") if False else None  # (never auto-quit: some OLTs ask y/n and hang)
            finally:
                self.transport.close()


# ---------------------------------------------------------------------------
# Job runner with wall-clock cap
# ---------------------------------------------------------------------------
def _execute(req: RunRequest) -> dict:
    started = time.monotonic()
    session = Session(req)
    outputs = []
    try:
        session.connect()
        login_log = _clean("".join(session.log))[-6000:]

        for cmd in req.prep:
            session.log.clear()
            out, err = session.run(cmd, timeout=min(30.0, session.command_timeout))
            login_log += f"\n### prep: {cmd}\n{out}" + (f"\nERROR: {err}" if err else "")
            if session.req.options.enable_password and re.search(r"password", out, re.IGNORECASE):
                session._write(session.req.options.enable_password + "\r")
                session._read_until_prompt(10)

        for cmd in req.commands:
            t0 = time.monotonic()
            session.log.clear()
            try:
                out, err = session.run(cmd)
            except ConnectionError as exc:
                outputs.append({"command": cmd, "output": "", "error": f"connection lost: {exc}", "duration_ms": int((time.monotonic() - t0) * 1000)})
                break
            outputs.append({"command": cmd, "output": out, "error": err, "duration_ms": int((time.monotonic() - t0) * 1000)})
    finally:
        session.close()

    return {
        "outputs": outputs,
        "prompt": session.prompt,
        "login_log": login_log if "login_log" in locals() else _clean("".join(session.log))[-6000:],
        "duration_ms": int((time.monotonic() - started) * 1000),
        "protocol": session.protocol,
        "version": VERSION,
    }


def _run_capped(req: RunRequest) -> dict:
    with ThreadPoolExecutor(max_workers=1) as pool:
        future = pool.submit(_execute, req)
        try:
            return future.result(timeout=JOB_TIMEOUT)
        except FuturesTimeout as exc:
            raise HTTPException(status_code=504, detail=f"OLT session exceeded {JOB_TIMEOUT}s (COLLECTOR_JOB_TIMEOUT)") from exc


def _auth(key: Optional[str]) -> None:
    if key != API_KEY:
        raise HTTPException(status_code=401, detail="Bad or missing collector API key")


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------
@app.get("/health")
def health():
    return {"status": "ok", "version": VERSION, "job_timeout": JOB_TIMEOUT}


@app.post("/run")
def run(req: RunRequest, x_collector_key: Optional[str] = Header(None)):
    """Run `prep` then `commands` in one login session; return each command's raw output."""
    _auth(x_collector_key)
    if not req.commands and req.command:
        req.commands = [req.command]
    if not req.commands:
        raise HTTPException(status_code=400, detail="`commands` is required")
    if len(req.commands) > 400:
        raise HTTPException(status_code=400, detail="too many commands (max 400)")
    try:
        return _run_capped(req)
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001 — surface everything as a JSON error
        raise HTTPException(status_code=502, detail=f"OLT connection/command failed: {exc}")


@app.post("/raw")
def raw(req: RunRequest, x_collector_key: Optional[str] = Header(None)):
    """v1-compatible: run ONE command, return {"output": ...}."""
    _auth(x_collector_key)
    cmd = req.command or (req.commands[0] if req.commands else None)
    if not cmd:
        raise HTTPException(status_code=400, detail="`command` is required for /raw")
    req.commands = [cmd]
    try:
        res = _run_capped(req)
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=f"OLT connection/command failed: {exc}")
    out = res["outputs"][0] if res["outputs"] else {"output": ""}
    return {"output": out.get("output", ""), "error": out.get("error"), "prompt": res.get("prompt"), "login_log": res.get("login_log")}
