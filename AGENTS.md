# Blue Mogul Portal (bm-cportal)

Full-stack client/admin portal: Express + React (Vite) + PHP server-side pages, PostgreSQL (Neon live DB), PHP 8.4.

## Architecture

- **Repo root** = portal PHP pages (they are NOT under a `/portal/` subdir).
- `server/index.ts` → build → `dist/index.cjs` (`npm run build`). Production runs `node dist/index.cjs` with `NODE_ENV=production`.
  - `ALLOWED_PHP_FILES` gates `/portal/:file` serving. **Any new admin page must be added there**.
  - Convenience redirects map `/admin-<name>.php` → `/portal/admin-<name>.php` (mirror the frontier/dandh ones).
- PHP is invoked via `execFile("php", ...)`. Install with `sudo apt-get install -y php-cli php-pgsql php-mbstring php-curl php-xml`.
- After `npm run build`, **re-copy `dist/table.sql`** from `node_modules/connect-pg-simple/table.sql` (build wipes it; connect-pg-simple needs it).
- Config constants live in `config.php`, environment-overridable via `getenv()`.
- DB access via `getDB()` in `config.php` — parses `DATABASE_URL` (Neon pooler URL). `system_settings(key,value)` is the generic key/value store used by integrations.

## Live environment

- Two instances: ports **12000** and **12001**. Both run with live Neon `DATABASE_URL` + `NODE_ENV=production`.
- Health check: `curl -s -H "User-Agent: healthcheck" http://localhost:<port>/` → `{"status":"ok","portal":"/portal"}`.
- Work hosts: `work-1-pekbsfakgwfmictc.prod-runtime.all-hands.dev` (12000) and `work-2-...` (12001). Log in at `/portal`.
- "Failed to initialize Stripe: Stripe not configured" on startup is a **non-fatal warning** (no Stripe keys configured) — ignore.
- Sessions are per-work-host.

## Integrations

### D&H Distributing (`admin-dandh.php`)
- OAuth2 client-credentials: token `POST https://test.auth.dandh.com/api/oauth/token` (test) or `https://auth.dandh.com/api/oauth/token` (live). Form-encoded `grant_type=client_credentials&client_id&client_secret&scope` (scope `resource.READ resource.WRITE` works; omitting scope grants `resource.READ resource.WRITE resource.ADMIN`). Token cached in `system_settings` (`dandh_token`, `dandh_token_expires`).
- **The test environment uses a SEPARATE auth host `test.auth.dandh.com`** — `auth.dandh.com` returns `invalid_client` for test creds even though they're valid. `admin-dandh.php` picks the auth host by `$dh_env`.
- API base: `https://test.api.dandh.com` (test) or `https://api.dandh.com` (live), prefix `/customerOrderManagement/v2`. Requires `Authorization: Bearer <token>` + `dandh-tenant` header.
- **`dandh-tenant` header must be a D&H company code**: `dhus` (US), `dhca` (Canada), `dsc` (SCALE). The literal `D&H` causes HTTP 400. Default is `dhus`.
- `accountNumber` in paths must be the **10-digit D&H account number bound to the OAuth client** (regex `^\d{10}$`). Guessing accounts returns HTTP 403. No account-discovery endpoint exists — the account must come from D&H.
- Credentials: `DANDH_CLIENT_ID` / `DANDH_CLIENT_SECRET` / `DANDH_ACCOUNT_NUMBER` / `DANDH_API_URL` / `DANDH_AUTH_URL` / `DANDH_TENANT` in config.php; overridable in UI (stored in `system_settings` with `dandh_*` keys).
- Provided test creds (`5b8c4293-...`, `74947838-...`) ARE VALID on `test.auth.dandh.com` — token acquisition works ("Connected — OAuth access token acquired."). Account `3054540000` (dhus/test) is configured and VERIFIED: price (`TI83PLUS` → $98.00), availability (total 3388), item inquiry ("TI 83 Plus Graphics Calculator"), and order-tracking endpoints all return real data through the app.
- Key API endpoints: `GET /customers/{accountNumber}/items/{itemId}/price?quantity=N`, `.../items/{itemId}/availability`, `.../items/{itemId}/priceAndAvailability`, `.../carriers`, `.../items`, `.../salesOrders/tracking`, `POST .../salesOrders`.
- **No free-text item search exists in the D&H API.** The only list endpoint is `GET /customers/{accountNumber}/items` (paginated via `scrollId` + `hasNext`; `pageSize` up to 200). `admin-dandh.php` implements a bounded client-side scan (`dh_search_items()`) that pages the catalog (6 pages / ~1200 items per request ≈ 7–9s) and filters on description/vendor/itemId/vendorItemId/UPC. Pagination state round-trips through **hidden POST fields** (`dh_scroll`, `dh_scanned`, `dh_prev_q`) — DO NOT use PHP `$_SESSION` for it: the Node PHP wrapper injects a fresh PHP process per request and loses `$_SESSION` writes.
- **The `/items` catalog is customer-scoped and NOT complete:** TI-83 Plus (`TI83PLUS`) is purchasable/priced (item-inquiry returns "TI 83 Plus Graphics Calculator", price $98.00) but never appears in the `/items` list even scanning all 10,000 catalog entries. Free-text search will only find items that D&H exposes in the browsable list.
- **Node PHP wrapper quirk:** `executePhpFile` in `server/index.ts` sets `$_SERVER['REQUEST_METHOD']='POST'` only for POST routes; on GET it leaves `REQUEST_METHOD` **unset**. Test `($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'` (not `=== 'GET'`) for GET-only logic.

### Other integrations
ResellerClub (`admin-resellerclub.php`) uses `provider_settings`; Hetzner (`admin-hetzner.php`) uses `system_settings`. Follow these patterns for new integrations (tools: price/avail lookup + Settings tab + `Test API` button).

## Testing notes

- To verify an admin page, insert a temporary super-admin into `users` (email/password/name/is_admin=true/role='super-admin'/status='active'), log in via `/portal`, then **delete the user** when done. Password must be a bcrypt hash generated with PHP `password_hash()`.