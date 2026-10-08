# Deploying the "all data from every OLT" update to the live server

Server: `auth.antbd.net:81`, project path `/var/www/app/fttx`.
Everything below is copy–paste; lines starting with `#` are comments.

## 1. Pull the code and migrate

```bash
cd /var/www/app/fttx
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force                              # adds ONU fields, CLI fields, diagnostic_runs
php artisan db:seed --class=RolesAndPermissionsSeeder   # adds the new "olt.diagnose" permission (safe to re-run)
npm ci && npm run build                                  # new UI (Diagnostics page, OLT Rx column, CLI tags)
php artisan optimize:clear
```

Make sure the queue worker and scheduler are running (they already were for
the SNMP sync). If they run under systemd/supervisor, restart them so they
load the new code:

```bash
sudo systemctl restart fttx-queue        # or: supervisorctl restart fttx-queue:*   (use your service name)
php artisan queue:restart
```

The scheduler must run `php artisan schedule:work` (or a cron calling
`schedule:run` every minute). It now also runs `olt:cli-enrich --due`.

## 2. Install the CLI collector (once)

Follow `dev_resources/python/README.md` steps 1–6 (about 5 minutes). Then add
to `/var/www/app/fttx/.env`:

```env
OLT_COLLECTOR_URL=http://127.0.0.1:8800
OLT_COLLECTOR_KEY=<same key as /opt/olt-collector/.env>
OLT_COLLECTOR_TIMEOUT=1500
```

and run `php artisan optimize:clear` again.

## 3. Verify from the browser

1. Open **Diagnostics** (left menu). The badge "CLI collector online" must be green.
2. Pick **DU-OLT (MA5683T)** → tab **Driver dry-run** → Run. Within a minute the
   output shows how many ONUs now carry rx/tx, OLT-rx, distance, MAC, model.
3. **OLT → Edit → CLI access**: enter the OLT CLI user/password, protocol
   Telnet (or SSH), tick **Enable CLI enrichment**, save.
4. Diagnostics → **CLI enrichment** → Run (dry run). Check the parsed counts,
   then run again with **Save**. From now on it repeats automatically every
   60 min (configurable per OLT).
5. Re-sync each OLT once (OLT page → **Sync now**) so the new SNMP columns are
   filled: OLT Rx power, ONU MAC, model, last-down time/cause, router MAC via FDB.

## 4. Vendor clean-up suggested by the inventory

| OLT | Change |
|---|---|
| MOHAKHALI-OLT (V3.1.8), DHANMONDI-OLT, JURAIN-OLT (V1.0) | vendor is "Generic" → set to **VSOL** (these are VSOL firmwares), then Sync. |
| EASKARTON-OLT, WARI-RESELLER-OLT (FD1608S) | set vendor to **C-Data**, run Diagnostics → "All probes" so the C-Data OID map can be filled in. |
| CYBERWORLD-OLT-1 (P3608), CYBERWORLD-OLT-2 (P3310C) | BDCOM **EPON**: set PON type to EPON (or use Test connection to auto-detect), then Sync. |
| RAMPURA-OLT (model "42444") | run Test connection to refresh the model string. |

## 5. Rolling back

```bash
cd /var/www/app/fttx
php artisan migrate:rollback --step=3
git checkout <previous-commit>
npm run build && php artisan optimize:clear
```
