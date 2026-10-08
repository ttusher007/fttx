"""
Fake Huawei MA5683T Telnet server, used to test collector.py without hardware.

Emulates: login prompts, >/#/(config)# prompts, "---- More ----" paging,
the "{ <cr>|... }:" parameter menu for a trailing-space command, unknown
command errors, and the optical / mac-address table formats.

Run standalone:  python fake_olt_server.py 2323
"""

from __future__ import annotations

import socket
import sys
import threading

OPTICAL = """  -----------------------------------------------------------------------------
  ONT-ID  Rx power(dBm)  Tx power(dBm)  OLT Rx ONT power(dBm)  Temperature(C)  Voltage(V)  Bias current(mA)
  -----------------------------------------------------------------------------
  0        -19.37         2.41           -22.80                 44              3.28        12
  1        -21.05         2.33           -24.11                 47              3.30        11
  2        -              -              -                      -               -           -
  5        -17.80         2.90           -20.10                 45              3.27        10
  -----------------------------------------------------------------------------
  Total: 4, online: 3
"""

MAC = """  -------------------------------------------------------------------------
  SRV-P BUNDLE TYPE MAC            MAC TYPE F /S /P  VPI  VCI   VLAN ID
  INDEX INDEX
  -------------------------------------------------------------------------
      0     -   gpon 00e0-fc12-3456 dynamic 0 /1 /0  0    1     100
     12     -   gpon e4a7-c5b2-1234 dynamic 0 /1 /0  1    1     100
     13     -   gpon e4a7-c5b2-9999 dynamic 0 /1 /0  1    2     200
     20     -   gpon 1c3b-f3aa-0001 dynamic 0 /1 /0  5    1     100
  -------------------------------------------------------------------------
  Total: 4
"""


def _page(text: str, lines_per_page: int) -> list[str]:
    lines = text.split("\n")
    return ["\n".join(lines[i:i + lines_per_page]) for i in range(0, len(lines), lines_per_page)]


class Handler(threading.Thread):
    def __init__(self, conn: socket.socket):
        super().__init__(daemon=True)
        self.conn = conn
        self.mode = "user"  # user | enable | config | if-gpon
        self.scroll = 0  # 0 = paging on
        self.ifgpon = None

    def send(self, s: str) -> None:
        self.conn.sendall(s.replace("\n", "\r\n").encode())

    def readline(self) -> str:
        buf = b""
        while not buf.endswith(b"\r") and not buf.endswith(b"\n"):
            ch = self.conn.recv(1)
            if not ch:
                raise ConnectionError
            if ch == b"\xff":  # swallow IAC sequences
                self.conn.recv(2)
                continue
            buf += ch
        if buf.endswith(b"\r"):
            try:
                self.conn.settimeout(0.05)
                nxt = self.conn.recv(1)
                if nxt not in (b"\n", b"\x00", b""):
                    buf += nxt
            except socket.timeout:
                pass
            finally:
                self.conn.settimeout(None)
        return buf.decode(errors="replace").rstrip("\r\n\x00")

    def prompt(self) -> str:
        return {"user": "MA5683T>", "enable": "MA5683T#", "config": "MA5683T(config)#",
                "if-gpon": f"MA5683T(config-if-gpon-{self.ifgpon})#"}[self.mode]

    def paged(self, text: str) -> None:
        if self.scroll:
            self.send(text)
            return
        pages = _page(text, 6)
        for i, page in enumerate(pages):
            self.send(page)
            if i < len(pages) - 1:
                self.send("\n  ---- More ( Press 'Q' to break ) ----")
                self.conn.recv(1)
                self.send("\r" + " " * 40 + "\r")
        self.send("\n")

    def run(self) -> None:
        try:
            self.send("\n  Warning: Telnet is not a secure protocol.\n\n>>User name:")
            user = self.readline()
            self.send("\n>>User password:")
            pw = self.readline()
            if user != "root" or pw != "admin":
                self.send("\n  Reenter times: 1\n>>User name:")
                return
            self.send("\n\n  Huawei Integrated Access Software (MA5683T).\n  Copyright(C) Huawei Technologies Co., Ltd. 2002-2016. All rights reserved.\n\n")
            while True:
                self.send(self.prompt())
                line = self.readline()
                self.send(line + "\n")  # echo
                cmd = line.strip()
                if cmd == "":
                    continue
                if cmd == "enable" and self.mode == "user":
                    self.mode = "enable"
                elif cmd == "config" and self.mode == "enable":
                    self.mode = "config"
                elif cmd == "quit":
                    self.mode = {"if-gpon": "config", "config": "enable", "enable": "user", "user": "user"}[self.mode]
                elif cmd.startswith("scroll"):
                    self.scroll = 512
                elif cmd in ("undo smart", "undo interactive", "undo alarm output all"):
                    pass
                elif cmd.startswith("interface gpon ") and self.mode == "config":
                    self.ifgpon = cmd.split()[-1]
                    self.mode = "if-gpon"
                elif cmd == "display version":
                    self.paged("  VERSION : MA5683TV800R018C10\n  PATCH   : SPC200 HP2001\n  PRODUCT : MA5683T\n  Uptime is 100 day(s), 3 hour(s)\n")
                elif cmd == "display version ":  # trailing space → interactive menu
                    self.send("{ <cr>|backplane<K>|frameid/slotid<S><Length 1-15> }:")
                    choice = self.readline()
                    self.send("\n")
                    self.paged("  VERSION : MA5683TV800R018C10\n")
                elif cmd.startswith("display ont optical-info") and self.mode == "if-gpon":
                    self.paged(OPTICAL)
                elif cmd.startswith("display mac-address port"):
                    self.paged(MAC)
                elif cmd == "display board 0":
                    self.paged("  SlotID  BoardName  Status  SubType0  SubType1  Online/Offline\n  1       H802GPBD   Normal\n  7       H801SCUN   Active_normal\n")
                else:
                    self.send("                   ^\n  % Unknown command, the error locates at '^'\n")
        except (ConnectionError, OSError):
            pass
        finally:
            self.conn.close()


def serve(port: int) -> None:
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", port))
    srv.listen(5)
    while True:
        conn, _ = srv.accept()
        Handler(conn).start()


if __name__ == "__main__":
    serve(int(sys.argv[1]) if len(sys.argv) > 1 else 2323)
