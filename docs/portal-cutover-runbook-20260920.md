# Portal cutover runbook — Neon + Coolify → bundled stack

Target: move `portal.bluemogul.us` off Neon (managed Postgres) + Coolify (h01) onto the
bundled `docker-compose` stack, tonight (owner-confirmed 2026-09-20, exact minute TBC).

## What actually serves the site today (verified 2026-09-20)

```
Cloudflare DNS → cloudflared tunnel  (container on h00, remotely managed via TUNNEL_TOKEN)
              → http://10.0.0.4:80   (Coolify's shared Caddy proxy on h01)
              → portal container :3000  (Coolify app bfilnjc276rcurk3mk4ur421)
              → Neon Postgres (ep-twilight-union-a6wqiyu6-pooler.us-west-2.aws.neon.tech, PG 18.6)
```

Probes that established this, from the tunnel host (h00):

| Origin probed | Result |
|---|---|
| `http://10.0.0.4:80/portal` + `Host: portal.bluemogul.us` | **200, `<title>Login - Blue Mogul Suite`** ← this is the tunnel's origin |
| `https://10.0.0.4/portal` | 503 |
| `http://10.0.0.4:3000` | 302 (the app itself, not the proxy path) |
| `http://localhost:3100/?health` on h00 | 200 `{"status":"ok"}` ← the bundled stack, already verified |

Tunnel identity: account `baa354b71bd9482ca4aeea0807116f04`, tunnel
`e9bf80e3-058a-4c8d-95be-ce803289567b`.

## ⚠️ Blocker: the ingress change needs rights we do not have — **RESOLVED 2026-09-21**

**Owner chose option B (2026-09-21): he makes the one-field change in the Cloudflare dashboard.**
The agent does not rewrite the tunnel configuration.

Cloudflare access via Composio was established in parallel and is now available for the rest of the
work (DNS, zones, tunnel status, post-cutover verification): composio Platform connection
`ca_WxY83L1Q6ITj` (toolkit `cloudflare`, auth config `ac_Q4eC2IHtuFG0`, status ACTIVE), proven with a
read-only `CLOUDFLARE_LIST_TUNNELS` call, log id `log_7g2UovCi-A0v`. The call returned the tunnel as
name **`BME-HIlls-Coolify`**, `config_src: cloudflare` (remotely managed), status `healthy`, account
tag `baa354b71bd9482ca4aeea0807116f04`.

**Why the agent does not perform the write:** `CLOUDFLARE_UPDATE_TUNNEL_CONFIGURATION` **replaces the
entire ingress list**, and the Cloudflare toolkit exposes **no tool that reads the current
configuration** (verified against the live toolkit, 20 tools, only `LIST_TUNNELS` and
`UPDATE_TUNNEL_CONFIGURATION` are tunnel-related). This tunnel also fronts `paperclip.bluemogul.us`
and others, so a config rebuilt from inference could take unrelated services down to move one line.
There are also **two** tunnels on the account — this one (remote-managed, from h00) and a
locally-configured one originating at `5.78.116.116` — so confirm the name before editing.

Owner's edit: Zero Trust → Networks → Tunnels → **`BME-HIlls-Coolify`** → Public Hostnames →
`portal.bluemogul.us` — change the service from `http://10.0.0.4:80` to **`http://127.0.0.1:3100`**.
Rollback is the same edit in reverse (seconds). No DNS change, no cert change.

**Why `127.0.0.1` and not `10.0.0.2`:** the `cloudflared` container runs with **host networking**
(`docker inspect` → network `host`; its namespace shows h00's own interfaces), so it shares h00's
network namespace and reaches loopback directly. Binding the app to loopback keeps the tunnel working
while making the origin unreachable from the LAN *and* the internet. Verified by entering the tunnel's
own namespace: `nsenter -t <cloudflared pid> -n curl http://127.0.0.1:3100/?health` → **200**.

Caveat: this couples the origin to `cloudflared` staying host-networked. If Coolify ever re-networks
that container, set `BIND_ADDRESS` back to `10.0.0.2` — the `DOCKER-USER` backstop still blocks
off-host access, so that is a safe fallback rather than an exposure.

### Superseded finding

The tunnel is remotely managed (`TUNNEL_TOKEN`, no local config file, no mounts), so its ingress rules
live in Cloudflare and cannot be edited from the filesystem. Our `CF_API_TOKEN` **verifies as active
but returns `1001 Not authorized`** on
`GET /accounts/{acct}/cfd_tunnel/{tunnel}/configurations`.

Why not just reuse port 80 on h01: `10.0.0.4:80` is Coolify's **shared** proxy — Rocket.Chat,
Jitsi and the other h01 apps resolve through it. Freeing that port would take all of them down,
so the portal must move to its own origin (`h00:3100`) and the ingress rule must change.

## Pre-flight (before the window)

- [x] Bundled stack built, booted and verified on h00 (`10.0.0.2:3100`) against a full restore
- [x] Backup service proven (writes, verifies, prunes)
- [x] **Production secrets copied across** (done 2026-09-21): `PORTAL_SECRET` and `SESSION_SECRET` in
      the h00 `.env` are byte-identical to the values in the production container, and verified in the
      running container's env. The vault resolves its key (the credentials endpoint answers `403
      Admin only`, not a 500), and identical `SESSION_SECRET` means staff sessions survive the switch.
- [x] **`APP_URL=https://portal.bluemogul.us`** set and confirmed in the running container, so payment
      return URLs and emailed links are correct after the switch. `PORTAL_SAFE_MODE=false` (writes on).
- [x] **Env completeness — the whole functional set, not just the secrets.** Do not compare only
      secrets: diff every env var NAME the production container carries against what the staging
      container actually receives, because the app silently loses whole features without them. On
      2026-09-21 staging had 8 vars against production's 38 and booted with *"Failed to initialize
      Stripe: Stripe not configured"* — payments would have broken the instant traffic moved. 23
      functional values were copied across (Stripe trio, admin password hash, Mission Control,
      Action1, JumpCloud, HubSpot, ITFlow, OpenRouter, VoIP, `FRONTIER_ENV`, `DEBUG_MODE`), and the
      compose gained the passthroughs it had been missing. **Read the app's boot log; "healthy" hides
      this entirely.** After the fix: 61 env vars in the container, and the log shows
      `Stripe schema ready` / `StripeSync initialized` / `Stripe data synced`.
- [x] **Origin access scoped — see "Host hardening" below.** The stack is bound to loopback only: the
      internet reaches `5.78.207.215:3100` → `000`, the LAN → `000`, and the tunnel's own netns → `200`.
      The restored database carries 41 `provider_settings` rows (live provider credentials), so this
      matters.
- [ ] Confirm nothing is mid-write: 7 active subscriptions and 1 client with a non-zero wallet
      balance are real money state.

## Host hardening (tiers 2 and 3, done 2026-09-21)

**Tier 3 — the origin is off every external interface.** `BIND_ADDRESS=127.0.0.1` in the h00 `.env`.
Chain of evidence: listener is `127.0.0.1:3100`; h00 loopback `200`; h00's LAN IP `000`; another LAN
host `000`; the public IP `000`; the tunnel's namespace `200`. This is as close to "no host port" as is
reachable here, because the `cloudflared` container is **host-networked** — such a container cannot also
join a bridge network, so a service-name origin is unavailable with this connector. Achieving that
would mean running our own non-host-networked connector on a shared docker network: a larger change to
attempt once the portal has settled.

**Tier 2 — `portal-origin-firewall.service`** (enabled, active, survives reboot) installs one
`DOCKER-USER` rule dropping traffic to the app network's container port. Two details that are easy to
get wrong:

- It matches the **container port (3000)**, not the host port (3100): DNAT rewrites the destination
  before `DOCKER-USER` is consulted, so a `--dport 3100` rule would silently match nothing.
- It is scoped by the app network's bridge interface (`-o br-f1170bf18095`), so no other container on
  h00 that listens on 3000 internally can be affected, and `coolify-proxy`'s published `:80/:443/:8080`
  are untouched.

Proven by control: the same LAN probe returned `200` before the rule and `000` after, while a
host-originated request (the tunnel's path) still returns `200` — off-host traffic traverses `FORWARD`
(blocked); host-originated traffic goes through `docker-proxy` (allowed). That is why the bind address
is the real control and this rule is the backstop.

## The window (target: under 15 minutes)

1. **Announce + freeze writes.** Tell staff to stop saving; the portal stays readable.
2. **Fresh dump** (the current copy is from 04:43Z and would lose everything since):
   ```sh
   docker run --rm --env-file /tmp/.bmedb -v /tmp/portaldump:/dump postgres:18-alpine \
     pg_dump -Fc --no-owner --no-acl -f /dump/portal.dump
   ```
3. **Restore into the bundled stack** (dropping the previous copy first so counts are trustworthy):
   ```sh
   ssh root@10.0.0.2 'cd /opt/portal-standalone/app && \
     docker compose exec -T db psql -U portal -d postgres -c "DROP DATABASE IF EXISTS bluemogul_portal WITH (FORCE);" && \
     docker compose exec -T db psql -U portal -d postgres -c "CREATE DATABASE bluemogul_portal OWNER portal;" && \
     docker compose exec -T db pg_restore -U portal -d bluemogul_portal --no-owner --no-acl --single-transaction' \
     < /tmp/portaldump/portal.dump
   ```
4. **Verify the restore before switching traffic** — counts must match the source
   (`tables=128 clients=9 invoices=12 tickets=6 crm_leads=511 wave_invoices=58 activity_log=338
   ledger_rows=8 active_subs=7 wallet_sum=42.02` at 2026-09-21T21:35Z — **re-read the source at cutover
   time rather than trusting these numbers**, they move daily),
   and the wallet balance of the funded client must match to the cent.
5. **Owner flips the ingress** — dashboard: `portal.bluemogul.us` from `http://10.0.0.4:80` to
   `http://127.0.0.1:3100`. **This must happen AFTER step 3/4, never before:** flipping first sends
   live traffic to the previous copy of the database, and every write made in that window exists only
   in the bundled DB while Neon keeps the newer data — a rollback would then lose those writes.
6. **Verify through the public URL** (not the LAN):
   `curl -s -o /dev/null -w '%{http_code}' https://portal.bluemogul.us/portal` → 200, page title
   `Login - Blue Mogul Suite`, and `/?health` → `{"status":"ok"}`.
7. **Stop the Coolify app** on h01 (`docker stop bfilnjc276rcurk3mk4ur421-034253614229`) so nothing
   can still write to Neon. Leave the container and Neon data in place.
8. **Log in** as staff, open a client with a balance, and confirm the wallet/invoice numbers match
   what you saw before the freeze.

## Rollback (keep this fast — it is why we keep Neon)

1. Flip the ingress back to `http://10.0.0.4:80`.
2. `docker start bfilnjc276rcurk3mk4ur421-034253614229` on h01.
3. Confirm `https://portal.bluemogul.us/portal` → 200 on Neon again.

**Any write made after the switch exists only in the bundled database** and would be lost by a
rollback — so decide within minutes, not hours.

## After the window

- Keep Neon in place, read-only in practice, until you are satisfied (a few days).
- Confirm the nightly backup service has produced its first post-cutover dump, and copy it
  off-host (`DEPLOY.md` → Backups).
- Remove the Coolify application registration for the portal once you are past rollback.
- The portal's own MCP bridge still runs on **h02** (unit `portal-mcp-bridge.service`, port 3150)
  while the app now runs on h00 — a cross-host dependency worth folding into the compose later.
