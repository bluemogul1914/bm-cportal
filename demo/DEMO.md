# Blue Mogul Suite — DEMO / TRAINING instance

A second, fully isolated copy of the portal for training **outside agents, dealers
and future staff**. It is not production and shares nothing with production except
the shape of the data.

| | Production | Demo / training |
|---|---|---|
| URL | `portal.bluemogul.us` | `demo.bluemogul.us` (see "Ingress") |
| Host | **h00** `10.0.0.2` | **h01** `10.0.0.4` |
| Path | `/opt/portal-standalone/app` | `/opt/portal-demo` |
| Origin | `127.0.0.1:3100` | `10.0.0.4:3200` |
| Database | its own bundled Postgres | **its own** bundled Postgres, separate volume |
| Secrets | production `SESSION_SECRET` / `PORTAL_SECRET` | **its own**, generated fresh |
| Data | live customer data | scrubbed copy — no PII, no credentials, no real money |

Compose project name is `portal-demo`, so its containers (`portal-demo-app-1`,
`portal-demo-db-1`) and volumes (`portal-demo_portal_pgdata`) are disjoint from
production's (`app-app-1`, `app-db-1`, `app_portal_*`) even though both use the
same `docker-compose.yml`.

## Why it is safe to let outsiders use it

The scrub (`demo/scrub-demo.sql`) enforces five properties, and **fails loudly**
rather than shipping a dirty demo:

1. **No live credentials.** `provider_settings` (41 live vendor keys), the
   credential vault, device credentials, per-user SMTP and the accounting mirrors
   (`wave_*`, `xero_*`) are truncated. `system_settings` rows whose key looks like
   a credential are deleted. Every provider env var in `.env` is deliberately blank.
   Verified in the boot log: `Failed to initialize Stripe: Stripe not configured`,
   `[network-sync] action1: ... Action1 not configured`.
2. **No customer PII.** Every text column is masked according to what the column
   name says it holds — names, emails, phones, postal addresses, job titles. Real
   identifiers are additionally swept (in three case variants) out of free-text
   prose such as ticket subjects and project notes.
3. **No client infrastructure.** Domains, hostnames, IPs, MACs and serials are
   replaced. Raw vendor JSON payloads (`network_assets.raw`,
   `hostwinds_products.raw`) are dropped outright — they carry names, emails and
   occasionally keys, and a column-name scanner cannot classify a blob.
4. **No real money.** Wallet balances, invoice amounts, MRR, subscription prices,
   commissions and quotes are randomised.
5. **No replayable sessions or passwords.** The `session` table is emptied and
   every surviving login's password hash is replaced with the single demo password.

Independently verified by pulling the real identifiers **out of production** and
hunting for them in the demo database: **0 whole-word matches**.

## Operating it

```sh
cd /opt/portal-demo
sudo docker compose ps                     # status
sudo docker compose logs --tail=50 app     # app log
sudo docker compose restart app            # restart the app only
sudo docker compose up -d --no-build app   # start it
sudo docker compose down                   # stop (data survives in the volume)
```

The demo runs the **same image as production** (`portal-demo-app:latest`, loaded
from the production image), so training behaviour matches the real thing without a
rebuild step drifting.

### Reset before every training session

```sh
sh demo/reset-demo.sh
```

Drops and recreates the demo database from the pristine snapshot
(`dump/demo-clean.dump`) and restarts the app. Takes seconds. Production is never
touched. **Use this before each session** so trainees never inherit each other's
junk data.

### Refresh from production (when the demo should look current)

```sh
# 1. dump production, restore into the demo's own database
ssh root@10.0.0.2 'sudo docker exec app-db-1 pg_dump -U portal -d bluemogul_portal \
    -Fc --no-owner --no-acl' \
  | ssh root@10.0.0.4 'sudo docker exec -i portal-demo-db-1 pg_restore -U portal \
    -d bluemogul_portal --no-owner --no-acl --single-transaction'

# 2. re-scrub (also rebuilds the clean snapshot). Keep the app running first so
#    the row the app creates for itself on boot is included.
sh demo/seed-demo.sh
```

### Credentials

```
Demo login : admin@bluemogul.biz
Password   : see /opt/portal-demo/DEMO-CREDENTIALS.txt
```

All 12 demo accounts share that one password. `admin@bluemogul.biz` is the only
address left unmasked: it is Blue Mogul's own bootstrap admin, and the app
re-creates it on boot whenever it is missing — masking it would make the app
insert a fresh unmasked row on every restart.

## Ingress — `demo.bluemogul.us`

The Cloudflare tunnel is **remotely managed** and its connector runs on **h00**
(`cloudflared-ii02yuku4gjcfmhp2xw7eql9`, host networking). It reaches the demo over
the private network, so one public hostname is all that is needed:

> Zero Trust → Networks → Tunnels → **`BME-HIlls-Coolify`** → Public Hostnames →
> **Add a public hostname**
> * Subdomain: `demo`, Domain: `bluemogul.us`
> * Service: **HTTP**, URL: **`10.0.0.4:3200`**

Nothing else changes; the existing `portal.bluemogul.us` rule is untouched. Verify
with `curl -s -o /dev/null -w '%{http_code}' https://demo.bluemogul.us/portal` → 200.

**Until that entry exists**, the demo is reachable only from h00 and from h01
itself (see the firewall below). That is deliberate.

### Port lockdown

`portal-demo-firewall.service` (enabled, survives reboot) installs one
`DOCKER-USER` rule so that **only h00** — the host running the tunnel connector —
may reach the demo's container port from off-host. Proven by control:

| Probe | Result |
|---|---|
| from h00 (tunnel path) | **200** ✓ |
| from h02 (another node in the fleet) | **000** ✓ |
| from h01 itself (local proxy path) | **200** ✓ |
| from h01's public IP `5.78.87.79:3200` | **000** ✓ |

It matches the **container** port (3000), not the published port (3200), because
DNAT rewrites the destination before `DOCKER-USER` is consulted — a `--dport 3200`
rule would silently match nothing. It is scoped by the demo network's bridge
interface so no other container on h01 is affected.

## Known limitations (be honest with trainees)

- **Accounting mirrors are empty** — `wave_*` and `xero_*` were truncated, so those
  admin screens show nothing. Deliberate: they carry real customer names and money.
- **Integrations are inert** — by design. Sync buttons, Stripe and vendor
  provisioning will not do anything in the demo.
- **Network Docs / domains / certificates** keep their rows with masked values, so
  the pages render but the content is synthetic.
- **The demo is writable** (`PORTAL_SAFE_MODE=false`) so trainees can create records.
  Reset it when they are done.
- Anything a trainee creates lives only in the demo database and is destroyed by
  `reset-demo.sh`.