# OLT SSH/Telnet Collector — Step-by-Step Setup

This is a small helper program (written in Python) that logs into an OLT over
**SSH or Telnet**, runs the OLT's command-line commands, and gives the result
back to our Laravel app as clean data.

**Why we need it:** some OLTs (for example the **Huawei MA5683T** on old
firmware) do **not** expose ONT **optical power** or **user/CPE MAC addresses**
over SNMP. The only way to read them is to log in like a human and type
commands. This collector does that automatically.

> You do **not** need to know Python. Just copy–paste the commands below in
> order. Lines starting with `#` are comments/explanations — you don't type
> those. Everything else you can paste exactly as written, only changing the
> few values that are clearly marked (like folder paths or the OLT password).

---

## How it fits together (the big picture)

```
  Laravel app  ──HTTP──►  Python collector  ──SSH/Telnet──►  OLT
 (our website)            (this program,                    (Huawei, etc.)
                          runs on the SAME server)
```

1. Laravel asks the collector: "give me the optical power for OLT 10".
2. The collector logs into that OLT, runs the command, reads the text.
3. The collector turns the text into tidy JSON and returns it to Laravel.

The collector listens only on `127.0.0.1` (the server talking to itself), so it
is **not** reachable from the internet. OLT passwords never leave the server.

---

## What you need before starting

- Access to your **production server** (the machine where the Laravel site runs).
- The server's login (SSH for Linux, or Remote Desktop for Windows).
- An OLT that the server can reach on the network, plus its **login user and
  password** and whether it uses **SSH (port 22)** or **Telnet (port 23)**.

Pick your server type and follow that section:

- **Linux server (Ubuntu/Debian)** → start at **Step 1 (Linux)**. *(Most
  production Laravel servers are Linux.)*
- **Windows server** → jump to **Step 1 (Windows)** near the bottom.

---

# LINUX SERVER (Ubuntu / Debian)

## Step 1 (Linux) — Open a terminal on the server

Log into your server over SSH (from your PC):

```bash
ssh your-user@your-server-ip
```

If you don't know how, ask your hosting provider for "SSH access". You'll end up
at a black screen with a prompt — that's the terminal. Type the commands below
there.

## Step 2 (Linux) — Install Python and helper tools

```bash
# Update the list of available software
sudo apt update

# Install Python, its package installer, and the "venv" tool (a clean sandbox)
sudo apt install -y python3 python3-pip python3-venv

# Check it worked — this should print a version like "Python 3.10.x"
python3 --version
```

## Step 3 (Linux) — Create the collector folder and copy the files

We'll keep the collector in `/opt/olt-collector`.

```bash
# Create the folder
sudo mkdir -p /opt/olt-collector

# Make your user the owner so you can edit files without sudo every time
sudo chown -R $USER:$USER /opt/olt-collector

# Go into it
cd /opt/olt-collector
```

Now copy **`collector.py`**, **`requirements.txt`**, and **`.env.example`**
(the files next to this README, inside `dev_resources/python/`) into
`/opt/olt-collector/`.

If your project code is already on the server, just copy them from there:

```bash
# Adjust the path to where your project lives on the server
cp /var/www/fttx/dev_resources/python/collector.py        /opt/olt-collector/
cp /var/www/fttx/dev_resources/python/requirements.txt    /opt/olt-collector/
cp /var/www/fttx/dev_resources/python/.env.example        /opt/olt-collector/.env
```

## Step 4 (Linux) — Create the sandbox and install the libraries

A "virtual environment" (venv) keeps these Python libraries separate from the
rest of the system, so nothing else breaks.

```bash
cd /opt/olt-collector

# Create the sandbox (a folder called "venv")
python3 -m venv venv

# Turn it on (your prompt will now start with "(venv)")
source venv/bin/activate

# Install the three libraries the collector needs
pip install -r requirements.txt
```

## Step 5 (Linux) — Set your secret key

Open the `.env` file and set a long random password that Laravel will use to
talk to the collector:

```bash
# Generate a random key and see it
openssl rand -hex 24

# Open the file to edit it (nano is a simple editor)
nano .env
```

In the editor, replace `change-me-to-a-long-random-string` with the random value
you generated. Save with **Ctrl+O**, then **Enter**, then exit with **Ctrl+X**.

Keep this key — you'll put the **same** value into Laravel's `.env` later.

## Step 6 (Linux) — Test it by hand first

Start the collector manually to make sure it runs:

```bash
cd /opt/olt-collector
source venv/bin/activate     # if not already on
uvicorn collector:app --host 127.0.0.1 --port 8800
```

You should see `Uvicorn running on http://127.0.0.1:8800`. Leave it running and
**open a second SSH window** to test it:

```bash
# 1) Health check — should print {"status":"ok"}
curl http://127.0.0.1:8800/health
```

Now the real test — **discovery**. This logs into your OLT and runs ONE command,
returning the raw text. Replace the OLT details and the key:

```bash
curl -X POST http://127.0.0.1:8800/raw \
  -H "Content-Type: application/json" \
  -H "X-Collector-Key: PASTE-YOUR-KEY-HERE" \
  -d '{
        "host": "172.16.29.5",
        "username": "admin",
        "password": "OLT-PASSWORD",
        "protocol": "telnet",
        "command": "display version"
      }'
```

- If you get text back from the OLT — **it works!** 🎉
- Common fixes: wrong `protocol` (try `"ssh"` vs `"telnet"`), wrong port (add
  `"port": 23`), or wrong `device_type` (add `"device_type": "generic_telnet"`).

Stop the manual run with **Ctrl+C** when done — Step 7 makes it run permanently.

## Step 7 (Linux) — Make it run automatically (as a service)

So it starts on boot and restarts if it crashes:

```bash
# Copy the service template into place
sudo cp /opt/olt-collector/olt-collector.service /etc/systemd/system/   # if you copied it here
or sudo cp /var/www/app/fttx/dev_resources/python/olt-collector.service /etc/systemd/system/
# (or copy it from your project: .../dev_resources/python/olt-collector.service)

# IMPORTANT: open it and check the User= and paths match your server
sudo nano /etc/systemd/system/olt-collector.service

# Load and start it
sudo systemctl daemon-reload
sudo systemctl enable --now olt-collector

# Check it's running (press Q to exit the status view)
sudo systemctl status olt-collector
```

To see logs if something's wrong: `sudo journalctl -u olt-collector -n 50`.

➡ **Now jump to "Discover the right commands" below.**

---

# WINDOWS SERVER

## Step 1 (Windows) — Install Python

1. Download Python from <https://www.python.org/downloads/windows/> (get the
   latest **3.12** "Windows installer 64-bit").
2. Run the installer. On the first screen, **tick "Add python.exe to PATH"**,
   then click **Install Now**.
3. Open **PowerShell** (Start menu → type "PowerShell") and check:
   ```powershell
   python --version
   ```

## Step 2 (Windows) — Create the folder and copy files

```powershell
# Create a folder
New-Item -ItemType Directory -Force C:\olt-collector
cd C:\olt-collector
```

Copy `collector.py`, `requirements.txt`, and `.env.example` from the project's
`dev_resources\python\` folder into `C:\olt-collector\`. Rename `.env.example`
to `.env`.

## Step 3 (Windows) — Sandbox + libraries

```powershell
cd C:\olt-collector
python -m venv venv
.\venv\Scripts\Activate.ps1      # prompt now shows "(venv)"
pip install -r requirements.txt
```

> If `Activate.ps1` is blocked, run PowerShell **as Administrator** once and
> execute: `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`, then retry.

## Step 4 (Windows) — Set your secret key

Open `C:\olt-collector\.env` in Notepad and replace the key with any long random
text. Save it. Remember this value for Laravel later.

## Step 5 (Windows) — Test it

```powershell
cd C:\olt-collector
.\venv\Scripts\Activate.ps1
uvicorn collector:app --host 127.0.0.1 --port 8800
```

Leave it running, open a **second** PowerShell window and test:

```powershell
# Health
curl http://127.0.0.1:8800/health

# Discovery (replace OLT details + key). One line:
curl -Method POST http://127.0.0.1:8800/raw -Headers @{ "X-Collector-Key" = "PASTE-YOUR-KEY" } -ContentType "application/json" -Body '{ "host":"172.16.29.5","username":"admin","password":"OLT-PASSWORD","protocol":"telnet","command":"display version" }'
```

Press **Ctrl+C** to stop.

## Step 6 (Windows) — Run it permanently

Easiest way is **NSSM** (a tiny tool that runs any program as a Windows service):

1. Download NSSM from <https://nssm.cc/download>, unzip, copy `nssm.exe` to
   `C:\olt-collector\`.
2. In an **Administrator** PowerShell:
   ```powershell
   cd C:\olt-collector
   .\nssm.exe install OltCollector "C:\olt-collector\venv\Scripts\uvicorn.exe" "collector:app --host 127.0.0.1 --port 8800"
   .\nssm.exe set OltCollector AppDirectory "C:\olt-collector"
   .\nssm.exe start OltCollector
   ```
3. It will now start automatically with Windows. Manage it in
   **services.msc** (look for "OltCollector").

➡ **Continue below.**

---

# Discover the right commands (do this once per OLT model)

Every OLT prints its tables a little differently. Before the parsed endpoints
(`/onu/optical`, `/onu/mac`) can work, we need to see the **real output** once.

Use the `/raw` endpoint to run the actual OLT commands and look at the text.
Common **Huawei MA5600/MA5683T** commands to try:

| Goal                | Command to put in `"command"`                          |
|---------------------|--------------------------------------------------------|
| Optical, one PON    | `display ont optical-info 0/1/0 all`                   |
| Optical, one ONU    | `display ont optical-info 0/1/0 1`                     |
| MAC, everything     | `display mac-address all`                              |
| MAC, one ONU        | `display mac-address ont-id 0/1/0 1`                   |
| ONU list on a port  | `display ont info 0/1/0 all`                           |

> Tip: `0/1/0` means frame 0 / slot 1 / port 0. Use a slot/port that actually
> has ONUs (check your ifName list — e.g. "GPON 0/1/0").

Run one with `/raw` (see the test command in Step 6) and **copy the output**.

- If the parsed `/onu/optical` or `/onu/mac` endpoints already return sensible
  rows — you're done.
- If not, **send the `/raw` output to your developer** (or paste it into
  `dev_resources/debug/`). The parsing logic lives in the **PARSERS** section at
  the bottom of `collector.py` and is easy to adjust to match your exact format.
  After editing `collector.py`, restart the service:
  - Linux: `sudo systemctl restart olt-collector`
  - Windows: `.\nssm.exe restart OltCollector`

---

# Connecting Laravel to the collector (for your developer)

Once `/raw` works and the parsers are tuned, wire it into the app. Add to the
Laravel `.env`:

```env
OLT_COLLECTOR_URL=http://127.0.0.1:8800
OLT_COLLECTOR_KEY=the-same-key-you-set-in-the-python-.env
```

Then a thin service in Laravel calls it (example):

```php
use Illuminate\Support\Facades\Http;

$res = Http::withHeaders(['X-Collector-Key' => config('services.olt_collector.key')])
    ->timeout(120)
    ->post(config('services.olt_collector.url').'/onu/optical', [
        'host' => $olt->ip_address,
        'username' => $olt->ssh_username,
        'password' => $olt->ssh_password,   // decrypted by the model cast
        'protocol' => 'telnet',             // or 'ssh'
        'frame_slot_port' => '0/1/0',
        'ont_id' => 1,
    ])->throw()->json();

// $res['rows'] => [['ont_id'=>1,'rx_power'=>-18.55,'tx_power'=>2.31], ...]
```

The results can then be merged into the existing `OnuInfo` data during sync, so
the rest of the app (dashboard, API) needs no changes. This is best run from a
**queued job** (it's slower than SNMP), only for OLTs/firmwares where SNMP
can't provide the data.

---

# Security checklist (important)

- ✅ The service listens on `127.0.0.1` only — never `0.0.0.0` in production.
- ✅ A strong `COLLECTOR_API_KEY` is set in `.env` and required on every call.
- ✅ The server's firewall does not expose port `8800` to the internet.
- ✅ OLT credentials are sent per-request and not stored in the collector.

---

# Quick troubleshooting

| Symptom                                   | Likely fix |
|-------------------------------------------|------------|
| `curl http://127.0.0.1:8800/health` fails | Service not running. Start it (Step 6/7) and check logs. |
| `401 Bad or missing collector API key`    | The `X-Collector-Key` header doesn't match the `.env` key. |
| `OLT connection/command failed`           | Wrong protocol/port/credentials. Try `"protocol":"ssh"` ↔ `"telnet"`, add `"port":23`, or `"device_type":"generic_telnet"`. |
| `Pattern not detected: 'screen-length'`   | Old Huawei telnet firmware. Update `collector.py` (uses `generic_telnet` now) and `sudo systemctl restart olt-collector`. |
| Telnet logs in but output looks cut off   | Paging. The collector tries to disable it; some models need a different command — tell your developer the model. |
| `ModuleNotFoundError`                      | The venv isn't active or libraries weren't installed. `source venv/bin/activate` then `pip install -r requirements.txt`. |

---

*Files in this folder:* `collector.py` (the program), `requirements.txt`
(libraries), `.env.example` (settings template), `olt-collector.service`
(Linux auto-start template), `README.md` (this guide).
