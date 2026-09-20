# Standalone Deployment Guide

Portal.bluemogul.us is now a **self-contained package**: the app ships with its own
PostgreSQL via Docker Compose, so it runs anywhere Docker runs — no Neon, no Coolify,
no external database required.

## Quick start

```bash
cp .env.example .env          # set POSTGRES_PASSWORD, SESSION_SECRET, APP_URL
docker compose up -d --build
```

The app boots, waits for Postgres to be healthy, **auto-runs migrations and seed on
first boot**, and serves on `http://localhost:3000`.

- First-run admin setup: open `/setup.php` (e.g. `http://localhost:3000/setup.php`)
- The portal login is at `/portal` (the app redirects `/` → portal login)

## What's inside

| Service | Image | Purpose |
|---|---|---|
| `app` | built from `Dockerfile` | Node 20 (Express/TS) that wraps PHP CLI per request — the whole portal: CRM, billing, projects, tickets, services, networks, products, monitoring, D&H ordering |
| `db` | `postgres:18-alpine` | Bundled PostgreSQL, data persisted in the `portal_pgdata` volume. Major version tracks production (Neon 18.6): `pg_restore` requires a server at least as new as the dump. |
| `backup` | `postgres:18-alpine` | Nightly `pg_dump -Fc` into the `portal_backups` volume, verifies the newest archive is readable, then prunes past `BACKUP_RETENTION_DAYS` |

The Express server wraps a fresh `php` CLI subprocess per request (that's the
architecture — `$_SESSION` is ephemeral and round-trips through the Node session;
don't convert to php-fpm expecting it to "just work").

## Configuration

Everything is in `.env` (template: `.env.example`):

- **Required:** `POSTGRES_PASSWORD`, `SESSION_SECRET` (use `openssl rand -hex 32`),
  `PORTAL_SECRET` (credential-vault key material — required if credentials are used, and
  changing it makes existing vault entries unreadable), `APP_URL`
- **Optional:** `ANYTHINGLLM_URL` (portal AI assistant prefers AnythingLLM over Ollama when set)
- **Optional provider keys:** Stripe, Twenty CRM — most integrations (D&H, Hetzner, Vultr,
  JumpCloud, etc.) store their own credentials in the `system_settings` table via the admin UI,
  so no env vars needed for them.

## Upgrading

```bash
git pull origin main
docker compose up -d --build --pull always
```

Migrations run automatically at boot — no manual `psql` step.

## Backups

The `backup` service runs a nightly logical dump automatically (see the table above).
To take one on demand, or to verify what exists:

```bash
# on-demand dump, written into the portal_backups volume
docker compose exec backup /bin/sh -c '. /backup/run-backup.sh' 2>/dev/null || \
  docker compose exec db pg_dump -U portal -Fc bluemogul_portal > portal-$(date -u +%FT%TZ).dump

# list what the service has kept
docker compose exec backup ls -lh /backups

# verify the newest archive is restorable
docker compose exec backup sh -c 'pg_restore --list "$(ls -1t /backups/portal-*.dump | head -1)" | head'
```

**A backup that only lives on this host does not survive losing this host.** Copy the
volume off-host on a schedule you control, e.g.:

```bash
docker run --rm -v bm-cportal_portal_backups:/b -v /srv/offsite:/o alpine \
  sh -c 'cp -u /b/portal-*.dump /o/ && ls -1t /o/portal-*.dump | tail -n +15 | xargs -r rm -f'
```

## Migrating from Neon to the bundled stack

The application database is portable: a logical dump and restore is the whole
procedure. Use a client whose major version is **at least the server's** — a PG16
`pg_dump` cannot read a PG18 server, which is what Neon runs.

```bash
# 0. Put the source credentials in an env-file (keeps them off any command line).
#    Keys: PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE PGSSLMODE=require
#    chmod 600 that file.

# 1. Dump from Neon with a matching (or newer) client.
docker run --rm --env-file /tmp/.bmedb -v "$PWD/dump:/dump" postgres:18-alpine \
  pg_dump -Fc --no-owner --no-acl -f /dump/portal.dump

# 2. Start ONLY the database here, so the restore lands in an empty instance.
docker compose up -d db

# 3. Restore (the app's own migrations are idempotent and run at boot afterwards).
cat dump/portal.dump | docker compose exec -T db pg_restore -U portal -d bluemogul_portal \
  --no-owner --no-acl --single-transaction

# 4. Bring up the app and verify it against the real data.
docker compose up -d --build
curl -s "http://localhost:3000/?health"
docker compose exec db psql -U portal -d bluemogul_portal -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'"
```

Verify against the source before cutting over: table count, and row counts for a few
tables you know (`clients`, `invoices`, `tickets`, `crm_leads`). If they match and the
portal renders real records, the cutover is a DNS/tunnel change, not a data project.

## Production notes (Blue Mogul fleet)

- **Decision 2026-09-20 (Tracey): this bundled stack is the portal's production target.**
  Coolify and Neon are retired for the portal once the cutover is verified — the product
  must stand up on any Docker host we control, without Neon's account or Coolify's control
  plane. Coolify auto-deploy from `main` is no longer the deploy path of record.
- Target: any Docker host — `bme-hills-00-main` is the current test/cutover host.
- Edge/TLS: front with the Cloudflare tunnel (remote-managed on hills-00-main) or any
  reverse proxy; `APP_URL` should be the public `https://portal.bluemogul.us`.
- Expose only the app port; the `db` and `backup` services are on the internal `portal`
  network only.

## Deployment history (for reference)

- 2026-09-03 → 2026-09-20: Coolify app `bm-cportal` (hills-01, auto-deploy from `main`) with
  a Neon database. Superseded by the bundled stack above.