# OLT SSH/Telnet Collector (v2) — Setup Guide

A small helper service (Python) that logs into an OLT over **SSH or Telnet**,
runs CLI commands, and hands the raw text back to the Laravel app. The app does
all the parsing (`app/Services/Olt/Cli`), so you normally never edit Python.

**Why we need it:** some OLT firmwares do not expose ONT **optical power** or
**customer (router) MAC addresses** over SNMP — e.g. **Huawei MA5683T
V800R018**. The collector reads them the way a human would: by logging in and
typing commands.

```
  Laravel app  ──HTTP (127.0.0.1:8800)──►  collector.py  ──SSH/Telnet──►  OLT
```

* Laravel sends: OLT IP + login + protocol + list of commands.
* The collector logs in once, runs the commands (handling paging / menus),
  returns each command's raw output as JSON.
* Laravel parses the text and merges optical power / MACs into the ONU rows.

The collector listens only on `127.0.0.1`, so it is **not** reachable from
the internet. OLT passwords are sent per request and never stored.

---

## Linux server (Ubuntu / Debian) — step by step

Run these on the production server (the machine where the Laravel site runs).

### 1. Install Python

```bash
sudo apt update
sudo apt install -y python3 python3-pip python3-venv
python3 --version        # 3.9 or newer is fine
```

### 2. Create the folder and copy the files

```bash
sudo mkdir -p /opt/olt-collector
sudo chown -R www-data:www-data /opt/olt-collector

# Copy from the deployed project (adjust the path if yours differs)
sudo cp /var/www/app/fttx/dev_resources/python/collector.py      /opt/olt-collector/
sudo cp /var/www/app/fttx/dev_resources/python/requirements.txt  /opt/olt-collector/
sudo cp /var/www/app/fttx/dev_resources/python/.env.example      /opt/olt-collector/.env
sudo chown -R www-data:www-data /opt/olt-collector
```

### 3. Create the sandbox (venv) and install the libraries

```bash
cd /opt/olt-collector
sudo -u www-data python3 -m venv venv
sudo -u www-data ./venv/bin/pip install -r requirements.txt
```

### 4. Set the secret key

```bash
openssl rand -hex 24          # copy the printed value
sudo nano /opt/olt-collector/.env
```

Replace `change-me-to-a-long-random-string` with the value you copied.
Save (**Ctrl+O**, **Enter**) and exit (**Ctrl+X**).

Put the **same** value into the Laravel `.env` (`/var/www/app/fttx/.env`):

```env
OLT_COLLECTOR_URL=http://127.0.0.1:8800
OLT_COLLECTOR_KEY=paste-the-same-key-here
OLT_COLLECTOR_TIMEOUT=1500
```

### 5. Test it by hand once

```bash
cd /opt/olt-collector
set -a; source .env; set +a
./venv/bin/uvicorn collector:app --host 127.0.0.1 --port 8800
```

You should see `Uvicorn running on http://127.0.0.1:8800`. In a **second**
SSH window:

```bash
curl http://127.0.0.1:8800/health
# → {"status":"ok","version":"2.0.0",...}
```

Then press **Ctrl+C** in the first window and continue with step 6.
(The real OLT test is done from the app's **Diagnostics** page — no curl
needed.)

### 6. Run it permanently as a service

```bash
sudo cp /var/www/app/fttx/dev_resources/python/olt-collector.service /etc/systemd/system/
sudo nano /etc/systemd/system/olt-collector.service   # check User= and the paths
sudo systemctl daemon-reload
sudo systemctl enable --now olt-collector
sudo systemctl status olt-collector                   # press Q to exit
```

Logs: `sudo journalctl -u olt-collector -n 100 -f`

### 7. Upgrading the collector later

```bash
sudo cp /var/www/app/fttx/dev_resources/python/collector.py /opt/olt-collector/
sudo systemctl restart olt-collector
```

---

## Windows server

1. Install Python 3.12 from <https://www.python.org/downloads/windows/>
   (tick **"Add python.exe to PATH"**).
2. Create `C:\olt-collector`, copy `collector.py`, `requirements.txt` and
   `.env.example` (rename to `.env`) into it.
3. In PowerShell:
   ```powershell
   cd C:\olt-collector
   python -m venv venv
   .\venv\Scripts\Activate.ps1
   pip install -r requirements.txt
   ```
4. Edit `.env` and set `COLLECTOR_API_KEY`.
5. Test: `uvicorn collector:app --host 127.0.0.1 --port 8800` then in another
   window `curl http://127.0.0.1:8800/health`.
6. Run as a service with NSSM (<https://nssm.cc/download>):
   ```powershell
   .\nssm.exe install OltCollector "C:\olt-collector\venv\Scripts\uvicorn.exe" "collector:app --host 127.0.0.1 --port 8800 --timeout-keep-alive 1800"
   .\nssm.exe set OltCollector AppDirectory "C:\olt-collector"
   .\nssm.exe set OltCollector AppEnvironmentExtra COLLECTOR_API_KEY=your-key COLLECTOR_JOB_TIMEOUT=1500
   .\nssm.exe start OltCollector
   ```

---

## Using it from the app

1. **OLT → Edit → "CLI access"**: enter the OLT's CLI username/password,
   choose **SSH or Telnet**, set the port (22/23), tick **Enable CLI
   enrichment**, save.
2. **Diagnostics page** (left menu): pick the OLT, tab **CLI command**, run
   `display version` (Huawei) / `show version` (BDCOM, VSOL). The "CLI
   collector" badge at the top must be green. The raw output appears below.
3. Tab **CLI enrichment → Run** (dry run): shows what the parser extracted
   (rx/tx/OLT-rx per ONT, MACs per ONT) next to the raw text. If the counts
   look right, tick **Save** and run again, or just wait: the scheduler runs
   `olt:cli-enrich --due` every minute and processes CLI-enabled OLTs on their
   CLI interval (default 60 min).
4. The OLT page shows a "CLI enrichment" status line and the ONU table marks
   CLI-refreshed values with a small **CLI** tag.

CLI command templates live in `config/olt.php` → `cli.vendors.<vendor>`; the
parsers live in `app/Services/Olt/Cli/Profiles/`.

---

## API (for developers)

`POST /run` — header `X-Collector-Key: <key>`, JSON body:

```json
{
  "host": "10.100.200.54", "username": "root", "password": "…",
  "protocol": "telnet", "port": 23, "vendor": "huawei",
  "prep": ["enable", "undo smart", "undo interactive", "scroll 512", "config"],
  "commands": ["interface gpon 0/1", "display ont optical-info 0 all", "quit", "display mac-address port 0/1/0"],
  "options": {"char_delay": 0.01, "command_timeout": 180, "login_timeout": 40, "prompt_regex": null}
}
```

Response: `{"outputs":[{"command":"…","output":"…","error":null,"duration_ms":1234}, …],
"prompt":"MA5683T(config)#","login_log":"…","duration_ms":…}`

`GET /health` — liveness. `POST /raw` — v1-compatible single command.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Badge says **collector offline** | `sudo systemctl status olt-collector`; `curl http://127.0.0.1:8800/health`; check `OLT_COLLECTOR_URL` in Laravel `.env`. |
| `401 Bad or missing collector API key` | `OLT_COLLECTOR_KEY` (Laravel) ≠ `COLLECTOR_API_KEY` (collector `.env`). Restart both after changing. |
| `OLT rejected the login` | Wrong CLI username/password on the OLT edit page, or the account has no CLI rights. |
| `could not detect the CLI prompt` | Look at the *login / prep transcript* in the Diagnostics output; set `prompt_regex` in `config/olt.php` → `cli.vendors.<vendor>` if the prompt is unusual. |
| Commands come back mangled over Telnet (`displayversion`) | The OLT drops characters typed too fast. Raise `char_delay` (Diagnostics → "Per-character delay", try `0.03`), then set it permanently in `config/olt.php` → `cli.vendors.<vendor>.char_delay`. |
| `% Unknown command` for `display mac-address port …` | That firmware lacks the per-port form. Set `'use_mac_all' => true` in `config/olt.php` (uses `display mac-address all`). |
| `504 OLT session exceeded …` | Raise `COLLECTOR_JOB_TIMEOUT` (collector `.env`) and `OLT_COLLECTOR_TIMEOUT` (Laravel `.env`), or use SSH instead of Telnet. |
| SSH fails with `no matching key exchange` | Old OLT. The collector already enables legacy KEX/ciphers; make sure `paramiko` is 3.x (`./venv/bin/pip show paramiko`). |

Run the local self-test any time: `python tests/test_collector.py` (uses a
fake MA5683T telnet server, no hardware needed).
