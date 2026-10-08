# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Start full dev stack (server + queue + logs + vite, concurrently)
composer dev

# Or individually:
php artisan serve       # Laravel dev server
npm run dev             # Vite asset watcher

# Run tests
composer test           # or: php artisan test

# Run a single test file
php artisan test tests/Feature/ExampleTest.php

# Fresh setup (install deps, migrate, build assets)
composer setup

# Database migrations
php artisan migrate
php artisan migrate:fresh --seed

# Vite production build
npm run build
```

## Development Environment

- **Local server**: Laragon (Windows) with **PHP 7.4** and **PHP 8.3** installed side by side. The default `php` on PATH is 7.4 — this project **requires 8.3**.
- **PHP 8.3 binary**: `E:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`. Run Artisan/Composer/tests with it, or switch Laragon's active PHP to 8.3 so `php` resolves correctly.
- **Required PHP extensions** (enabled in that build's `php.ini`): `snmp`, `sockets`, `pdo_mysql`, `pdo_sqlite`, `openssl`. SNMP is mandatory — it is how the app polls OLTs.
- **Database**: MySQL `fttx` (see `.env`), served by Laragon's MySQL. Tests use in-memory SQLite (`phpunit.xml`).
- Net-SNMP prints harmless `Cannot find module (… MIB)` warnings on every CLI run because we use numeric OIDs; prefix commands with `MIBS=` to silence them.

## Testing against real OLTs (IMPORTANT — read before tuning OIDs or CLI parsers)

**The development machine has NO access to real OLTs and cannot run live SNMP/SSH/Telnet.**
Do not assume you can verify OID values locally. Two ways to get real device output:

1. **Diagnostics page (preferred)** — `/diagnostics` in the live app (permission `olt.diagnose`,
   Super Admin always). Pick an OLT, then: *SNMP walk* (vendor probe catalogue or a custom OID),
   *Driver dry-run* (what the sync would store + driver notes), *CLI command* (raw commands through
   the collector), *CLI enrichment* (profile dry-run / save). Runs are queued (`RunDiagnosticJob`,
   needs `queue:work`) and the full output is stored in `diagnostic_runs` and copied to
   `dev_resources/debug/`. The live app at `http://auth.antbd.net:81/` is logged in in the user's
   Chrome, so Claude can read the output there directly.
2. **CLI fallback** — `php artisan olt:snmp-debug {id} [--oid=…]` / `php artisan olt:collect {id} --raw="…"`
   on the server write dumps to `dev_resources/debug/`; the user pastes them back.

Then tune `config/olt.php` (OID maps, walk tuning, CLI command templates are all config) and confirm
with another run. Keep raw dumps and vendor MIBs under `dev_resources/` (`dev_resources/mib/`:
`bdcom/`, `vol/` = VSOL) so OID work survives across sessions.

## Running the app

```bash
php artisan serve              # web UI
php artisan queue:work         # REQUIRED: processes sync jobs (queue=database)
php artisan schedule:work      # continuous OLT polling (olt:sync --due every minute)
php artisan olt:sync --sync    # sync all OLTs inline (debug; bypasses the queue)
php artisan olt:sync {id}      # queue a sync for one OLT
php artisan olt:cli-enrich {id} --sync   # CLI (SSH/Telnet) enrichment inline; --due for the scheduler form
php artisan olt:collect {id} --raw="display version" --prep   # raw CLI through the collector
```
The CLI collector (`dev_resources/python/collector.py`, FastAPI + paramiko + own telnet client) runs on the
server as a systemd service (`README.md` there). `python dev_resources/python/tests/test_collector.py` runs
it against a fake MA5683T telnet server locally (no hardware).

Seeded demo logins (password `password`): `admin@fttx.test` (Super Admin), `noc@fttx.test` (NOC Admin), `staff@fttx.test` (Employee).

## Tech Stack

- **Laravel 13** (PHP 8.3+) — framework, routing, Eloquent ORM, Blade templates
- **Livewire 4** — reactive server-side UI components (no full-page JS framework)
- **TailwindCSS 4** — utility-first CSS, configured via `resources/css/app.css`
- **Vite 8** — asset bundling (`vite.config.js`)
- **Laravel AI** — AI integration package (0.8.x)
- **SQLite** by default (dev); MySQL configurable via `.env`

## Architecture

```
routes/web.php          → HTTP routes
routes/console.php      → Artisan-only commands
app/Http/Controllers/   → Request handlers
app/Models/             → Eloquent models (User.php is the starter)
app/Livewire/           → Livewire components (add here as the app grows)
resources/views/        → Blade templates
database/migrations/    → Schema history
bootstrap/app.php       → Application bootstrapping (middleware, routing, providers)
```

**Data flow**: Request → `public/index.php` → `bootstrap/app.php` → route matched in `routes/web.php` → Controller or Livewire component → Blade view.

Session, cache, and queue all default to the **database** driver. Switch the queue to Redis in `.env` for production scale (200+ OLTs).

## OLT Monitoring Domain (the core of this app)

This is an OLT/ONU monitoring + reporting platform. It polls fiber OLTs over **SNMP** (fast, vs. slow SSH), stores ports/ONUs, exposes a dashboard, and serves an external lookup API.

**SNMP polling pipeline** (`app/Services/`):
```
SnmpClient (ext-snmp wrapper, numeric OIDs, v1/v2c/v3)
  └─ VendorDriver (interface) ── AbstractVendorDriver (shared SNMP logic)
        ├─ HuaweiDriver / BdcomDriver / VsolDriver / GenericDriver
        └─ OltSimulator (fake data when olt.is_simulated)
  VendorDriverManager  → resolves driver by olt.vendor
  OltSyncService       → orchestrates fetch → persist → rollups → SyncLog
  BridgeFdbResolver    → customer (router) MACs from the bridge FDB (BDCOM NMS-MAC / Q-BRIDGE / BRIDGE)
  SnmpProbeCatalog     → per-vendor OID probe list used by Diagnostics + olt:snmp-debug

CLI enrichment (app/Services/Olt/Cli/) — for data SNMP can't give on some firmwares:
  OltCollectorClient   → HTTP to the local Python collector (dev_resources/python, transport only)
  CliProfile           → per-vendor command templates (config olt.cli.vendors.*) + PHP parsers
        └─ HuaweiCliProfile (MA5600T/MA5683T: `display ont optical-info … all`, `display mac-address port …`)
  OltCliEnrichService  → collect → parse → merge into existing ONU rows (never creates ONUs)
  EnrichOltCliJob      → queued per OLT (`olt:cli-enrich --due` every minute; per-OLT cli_interval)
```
- **ONU columns** (`onus`): `rx_power` (ONU Rx), `tx_power` (ONU Tx), `olt_rx_power` (OLT Rx of that ONU),
  `distance`, `serial_number`, `mac_address` (customer/router MAC; `mac_source` = snmp|fdb|cli, `mac_count`),
  `onu_mac` (ONU's own MAC), `model`, `online_since`, `last_down_at`/`last_down_cause`, `cli_synced_at`.
  Last-known MAC/serial/model are preserved when a sync returns null (offline ONUs vanish from the FDB).
  For CLI-enabled OLTs, an SNMP sync that returns **no** optical/MAC data leaves those columns alone.
- **Config-driven walk tuning**: `vendors.*.walk.<column>` = `per_port` (walk one PON port at a time),
  `max_repetitions`, `timeout`, `retries`; `vendors.*.fallback_oids` are tried when a table is empty.
  Huawei's on-demand optical table (.51) needs this (one big GETBULK walk times out → "0 rows").
- **Vendor OIDs are config-driven** in `config/olt.php` (`vendors.*.oids`). GPON ONU OIDs differ per vendor AND firmware — the shipped values are starting points; verify against real devices with `snmpwalk` and tune in config (no code change). System/interface OIDs are standard and reliable.
- **PON type (GPON/EPON)** is a per-OLT field (`olts.pon_type`). GPON and EPON expose ONUs through different SNMP tables, so each vendor may define `vendors.*.pon_types.{gpon|epon}` overrides in `config/olt.php` (their keys replace the vendor defaults for a matching OLT). `AbstractVendorDriver::config()` resolves the right map from the OLT's `pon_type`. Set it in the OLT form, or leave it on "Auto-detect": **"Test connection" sniffs GPON vs EPON from `ifDescr` and saves it**, setting `pon_type_auto_detected = true` (the UI shows an "auto" badge). A manually chosen pon_type is never overwritten by auto-detection.
- **VSOL** (`VsolDriver`, enterprise 37950): ONUs are enumerated from IF-MIB (`ifDescr` "GPONxxONUyy" + `ifOperStatus`) — this online/offline spine works on all firmwares. Serial / optical power / description are read from the **V1600G GPON tree** `.6.1.1.*` (CONFIRMED on live OLTs), indexed by `[pon.onu]` and joined to the IF-MIB ONUs: serial = gOnuDetailInfoSn `.6.1.1.4.1.5`, Tx = gOnuOpticalInfo `.6.1.1.3.1.6`, Rx = `.6.1.1.3.1.7` (OCTET STRINGs already in dBm, e.g. "-16.60", so `power_divisor = 1`). Firmware matters: **optical power needs V3.x+** — it returns 0 rows on older V2.1.16 (serial/status still work). OLT-Rx = `.6.1.1.3.1.8`, distance = `gOnuRttTable .6.1.1.12.1.3` (in the MIB; verify unit), model `.6.1.1.4.1.17`, ONU MAC `priOnuInfoOnuMac .6.1.8.1.1.5`; customer MACs via Q-BRIDGE FDB. VSOL **EPON** uses the V1600D tree `.5.12.*` incl. learned-MAC table `.5.12.1.26.1.5` (index `pon.onu.n`).
- **Huawei** (`hwGponDeviceOntOpticalDdmInfoTable` `.51.1.*`, indexed `[port.onu]`): Tx = `.51.1.3`, Rx = `.51.1.4`, OLT-Rx = `.51.1.6` (dBm × 100, signed; `2147483647` = offline → null). CONFIRMED on MA5800-X2 V100R022. Walked **per PON port** with a 15 s timeout (config `walk`). **0 rows on MA5683T V800R018** in the old single-walk probe — the per-port walk and the LINE-COMMON fallback table (`2011.6.158.1.1.1.2.1.{16,21,26}`) are the first things to check on Diagnostics; if both are empty, enable **CLI enrichment** for that OLT (Telnet/SSH, `display ont optical-info`). Online-since = `.46.1.22` LastUpTime (`.46.1.23` is LastDOWNTime — the old config had this wrong), down cause `.46.1.24`, ONT MAC `.45.1.10`, model `.45.1.4`, MAC count `.46.1.21`. Huawei exposes **no customer MAC table** over SNMP → CLI (`display mac-address port F/S/P`, VPI column = ONT ID).
- **BDCOM GPON (GP3600)**: status/optical tables `3320.10.3.3/4` CONFIRMED; `gponOnuInfoTable 3320.10.3.1.1.{28 ONU MAC, 3 model}` from the MIB (verify). Customer MACs via NMS-MAC `fdbReadByPortTable 3320.152.1.1.3` (index `ifIndex.vlan.mac`) with Q-BRIDGE fallback. **BDCOM EPON (P3310/P3608)**: `3320.101.10.1.1.{3 MAC, 26 status (0/1/3 online, 2/5 offline, 4 lost), 27 distance}`, OLT-Rx `3320.101.108.1.3` (0.1 dBm); ONU-side rx/tx `3320.101.10.5.1.5/6` unverified.
- **C-Data** (`CDataDriver`, enterprise 17409 / 34592): registered vendor; ports + online/offline work via standard MIBs, but the ONU/optical OID map in `config/olt.php` (`vendors.cdata`) is an empty placeholder pending a hardware `olt:snmp-debug` discovery walk (the command ships a C-Data probe). Fill the OIDs there once a dump shows the populated tree.
- **Simulation mode** (`olt.is_simulated` column or `OLT_SIMULATE` env) generates realistic stable data so the full app works without hardware. Demo OLTs are seeded this way.
- **Drivers normalise** every vendor's raw SNMP into `OnuInfo`/`PortInfo`/`SystemInfo` DTOs, so persistence is vendor-agnostic. To add a vendor: add an OID map + driver class in `config/olt.php` + `app/Services/Olt/Drivers/`.

**Sync at scale**: `SyncOltJob` is queued per-OLT with `WithoutOverlapping` (no stacking). The scheduler (`routes/console.php`) runs `olt:sync --due` every minute, respecting each OLT's `sync_interval`. `SyncOnuJob` does targeted single-ONU refreshes (manual / API).

**Diagnostics** (`app/Livewire/Diagnostics`, `RunDiagnosticJob`, `DiagnosticRun` model): see "Testing against real OLTs" above.

**RBAC**: single role per user (`users.role_id`) → role has many permissions. Source of truth is `app/Support/Permissions.php` (used by seeder, the `Gate`, and the role UI). Enforced via the `permission:` route middleware and `@can()` Blade checks. Super Admin bypasses all checks (`Gate::before`).

**External API** (`routes/api.php`, `/api/v1/*`): authenticated by API key+secret (`AuthenticateApiClient` middleware, `X-Api-Key`/`X-Api-Secret` headers), scoped by abilities, rate-limited per key. Endpoints: `onu/lookup` (by serial or MAC), `sync/olt`, `sync/onu`. Secrets are hashed; the plain secret is shown once on creation.

**Encryption**: OLT SNMP/SSH credentials are encrypted at rest via Eloquent `encrypted` casts on the `Olt` model.

## Key Patterns

- **Livewire components** live in `app/Livewire/` with paired views in `resources/views/livewire/`. Generate with `php artisan make:livewire ComponentName`.
- **Models** use Eloquent; factories in `database/factories/`, seeders in `database/seeders/`.
- **Service Providers**: `AppServiceProvider` (`app/Providers/`) is the place for global bindings and boot-time setup.
- **Assets**: JS entrypoint is `resources/js/app.js`; CSS entrypoint is `resources/css/app.css`. Both are bundled by Vite and referenced via `@vite()` in Blade.
- **Environment**: Copy `.env.example` → `.env` and run `php artisan key:generate` for a new setup.

## UI Guidelines

- **Mobile-first is required** — every page, layout, and Livewire component must be mobile view friendly.
- Design and build for small screens first, then enhance for tablet and desktop breakpoints.
- Use responsive Tailwind utilities (`sm:`, `md:`, `lg:`) for layout, typography, spacing, and navigation — avoid fixed widths that break on narrow viewports.
- Test touch targets, readable font sizes, and usable navigation on mobile before considering a page complete.
- Do not ship pages that require horizontal scrolling, overflow hidden content, or desktop-only interactions on mobile.
