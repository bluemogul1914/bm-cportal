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
| `db` | `postgres:16-alpine` | Bundled PostgreSQL, data persisted in the `portal_pgdata` volume |

The Express server wraps a fresh `php` CLI subprocess per request (that's the
architecture — `$_SESSION` is ephemeral and round-trips through the Node session;
don't convert to php-fpm expecting it to "just work").

## Configuration

Everything is in `.env` (template: `.env.example`):

- **Required:** `POSTGRES_PASSWORD`, `SESSION_SECRET` (use `openssl rand -hex 32`), `APP_URL`
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

```bash
docker compose exec db pg_dump -U portal bluemogul_portal > portal-backup-$(date +%F).sql
```

## Production notes (Blue Mogul fleet)

- Target: any Docker host (hills-01 currently) — **Coolify backend is down**
  (2026-09-03), so use manual `docker compose` deploys.
- Edge/TLS: front with the Cloudflare tunnel (remote-managed on hills-00-main) or any
  reverse proxy; `APP_URL` should be the public `https://portal.bluemogul.us`.
- Expose only the app port; the `db` service is on the internal `portal` network only.

## Current production deployment (for reference)

Live at `https://portal.bluemogul.us`, Coolify app `bm-client-portal` (hills-01,
auto-deploys from `main`). This compose file is the path to decouple from Coolify
when ready.