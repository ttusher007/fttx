"""
End-to-end test of collector.py against the fake MA5683T telnet server.
Run:  python tests/test_collector.py   (from dev_resources/python, with the venv active)
"""

from __future__ import annotations

import os
import sys
import threading
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))

import fake_olt_server  # noqa: E402
from collector import RunOptions, RunRequest, _execute  # noqa: E402

PORT = 2323


def main() -> int:
    threading.Thread(target=fake_olt_server.serve, args=(PORT,), daemon=True).start()
    time.sleep(0.3)

    req = RunRequest(
        host="127.0.0.1", port=PORT, username="root", password="admin", protocol="telnet", vendor="huawei",
        prep=["enable", "undo smart", "undo interactive", "undo alarm output all", "scroll 512", "config"],
        commands=[
            "display version",
            "interface gpon 0/1",
            "display ont optical-info 0 all",
            "quit",
            "display mac-address port 0/1/0",
            "display nonsense",
            "display version ",
        ],
        options=RunOptions(command_timeout=10, login_timeout=10, char_delay=0.0),
    )
    res = _execute(req)
    outs = {o["command"]: o for o in res["outputs"]}

    def check(cond: bool, msg: str) -> None:
        print(("PASS " if cond else "FAIL ") + msg)
        if not cond:
            raise SystemExit(1)

    check(res["prompt"].startswith("MA5683T"), f"prompt detected: {res['prompt']}")
    check("V800R018" in outs["display version"]["output"], "display version output captured")
    check("More" not in outs["display version"]["output"], "paging prompt removed")
    check("-19.37" in outs["display ont optical-info 0 all"]["output"] and "Total: 4" in outs["display ont optical-info 0 all"]["output"], "optical table complete")
    check("1c3b-f3aa-0001" in outs["display mac-address port 0/1/0"]["output"], "mac table complete")
    check(outs["display nonsense"]["error"] is not None, f"unknown command flagged: {outs['display nonsense']['error']}")
    check("V800R018" in outs["display version "]["output"], "interactive {<cr>} menu auto-answered")
    check(not any("MA5683T" in o["output"].split("\n")[-1] for o in res["outputs"] if o["output"]), "prompts stripped from outputs")
    print("ALL PASS", res["duration_ms"], "ms")

    # Paging ON (no scroll) must also work.
    req2 = RunRequest(host="127.0.0.1", port=PORT, username="root", password="admin", protocol="telnet", vendor="huawei",
                      prep=["enable"], commands=["display version"], options=RunOptions(command_timeout=10, login_timeout=10))
    res2 = _execute(req2)
    check("Uptime" in res2["outputs"][0]["output"] and "More" not in res2["outputs"][0]["output"], "paged output stitched together")

    # Bad password must raise a clear error.
    try:
        _execute(RunRequest(host="127.0.0.1", port=PORT, username="root", password="bad", protocol="telnet", vendor="huawei",
                            commands=["display version"], options=RunOptions(login_timeout=5)))
        check(False, "bad password should fail")
    except RuntimeError as exc:
        check("rejected" in str(exc), f"bad password rejected: {exc}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
