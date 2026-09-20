# Blue Mogul Suite → ITFlow parity build plan

Created 2026-09-18 · Owner: Tracey (decisions) / Hermes dev (build) · One slice per branch+PR, `main` = deploy.
Source of truth for "what ITFlow has that the portal does not" · Companion to
`docs/isp-gap-backlog.md` (ISP/Splynx gaps) and `docs/splynx-parity-matrix.md` (standing assessment).

## Objective

Bring every capability ITFlow exposes (read from its live schema, 102 tables, 6 modules:
`module_client`, `module_support`, `module_credential`, `module_sales`, `module_financial`,
`module_reporting`) into Blue Mogul Suite, **without** regressing the things the portal does
better (prepaid wallet, carrier ASR pre-qual, D&H ordering, dealer program, ISP network stack).

Accounting source of record: **Wave Accounting** (GraphQL `gql.waveapps.com/graphql/public`,
bearer token in `system_settings.wave_token`, OAuth client id/secret also stored).

## How each slice is built (house rules)

- Tables added idempotently in `server/migrations.ts` (`CREATE TABLE IF NOT EXISTS`,
  `ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`) — never a destructive ALTER.
- New `admin-*.php` pages MUST be added to `ALLOWED_PHP_FILES` in `server/index.ts` **and**
  `includes/admin-sidebar.php`, or they 404 / are invisible.
- A page's "action" buttons POST to a Node endpoint on the loopback listener
  (`http://127.0.0.1:$PORT`) forwarding the `connect.sid` cookie — the pattern in
  `admin-billing-reminders.php`. Never call an external API directly from PHP.
- External pulls are wrapped in a try/catch per section and report
  `<section>: <error>` in the result, so one dead sub-feed cannot blank a whole page.
- Gate: `npm run check` **and** `npm run build` green (fix pre-existing errors, don't bracket),
  `php -l` on every new page, then live evidence after deploy.

## Phase 1 — Credential & licensing security (highest client-requested)

| Slice | Data model | Endpoints / pages | Acceptance |
|---|---|---|---|
| **1a Credential vault** | `credentials` (client_id, asset_id, service_id, label, username, secret_cipher, otp_secret_cipher, url, notes, category, key_version, rotation_days, last_rotated_at, created_by), `credential_tags` | `GET /api/admin/credentials[/status]`, `POST /api/admin/credentials`, `POST /api/admin/credentials/:id/{reveal,rotate,update}`, `GET /api/admin/clients/:id/credentials`, `admin-credentials.php` (sidebar: Credentials) | **BUILT + VERIFIED 2026-09-20** (commit `5dc8359`). AES-256-GCM, key = HKDF(PORTAL_SECRET) and never stored; list is metadata-only (proved: plaintext and ciphertext both absent from the API response); reveal/rotate each write `activity_log`; unauth 403; rotate makes the old value unrecoverable |
| **1b Software licences** | `software` (name, vendor, licence_type), `software_keys` (software_id, client_id, asset_id, key_cipher, seats, expires_at, cost) | `admin-software.php` + API | Per-client licence list with seats/expiry; expiring-in-30-days alert |
| **1c Domain & certificate expiry** | `domains` (client_id, name, registrar, expires_at, auto_renew), `domain_history`, `certificates` (hostname, issuer, expires_at, last_checked_at), `certificate_history` | Node prober (RDAP + TLS handshake) on the daily cron, `admin-domains.php` | Daily check; expiry buckets 30/14/7 days surface on the dashboard; history rows appended on change |

## Phase 2 — Financials (Wave) — **SLICE 1 SHIPPED 2026-09-18**

| Slice | Data model | Endpoints / pages | Status |
|---|---|---|---|
| **2a Wave mirror** | `wave_businesses`, `wave_accounts`, `wave_customers`, `wave_invoices`, `wave_payments`, `wave_vendors`, `wave_sync_runs` | `GET /api/admin/wave/status`, `GET /api/admin/wave/businesses`, `POST /api/admin/wave/sync`, `POST /api/admin/wave/settings`, `admin-financials.php` | **Built** (commit `edf3ed6`+). Live: 238 accounts, 28 customers, 58 invoices, 60 payments, 3 vendors; invoiced/collected $21,161.85 |
| **2b Expenses & AP** | portal-side `expenses` (vendor, category, amount, date, receipt_doc_id, billable, client_id), `expense_categories` | `admin-expenses.php` + API | **Blocked by Wave**: the public GraphQL API does not expose expenses/transactions. Options: (a) manual entry + receipt upload in the portal, (b) Wave CSV export import, (c) request Wave partner API access |
| **2c Budgets, taxes, discount codes** | `budgets` (period, category, amount), `taxes` (name, rate, jurisdiction), `discount_codes` (code, type, value, expires_at, max_uses) | `admin-finance-settings.php` + apply logic at checkout | Codes apply on Stripe checkout; taxes reflected on portal invoices |
| **2d Per-client financials** | — | Wave panel on `admin-client-detail.php` (invoices + outstanding + payments for the matched client) | Wave customer → portal client match is live (email → name → substring); show the client's real Wave balance |
| **2e Xero ledger mirror** | `xero_organisation`, `xero_contacts`, `xero_invoices`, `xero_payments`, `xero_accounts`, `xero_sync_runs` | `GET /portal/api/xero/status`, `GET /portal/api/xero/data` (live reports), `POST /portal/api/xero/{test,sync,settings,disconnect}`, `admin-xero.php` | **Built 2026-09-18**; tenant supplied by the OAuth 2.0 Web app bridge (below) |

### Xero facts that shaped 2e (verified live 2026-09-18)

- The Xero app is a **custom connection**: `client_credentials` grant, **no redirect URI, no
  consent screen**, and a mandatory `Xero-tenant-id` header on every call. Setting the app's
  Redirect URI has no effect on this app type.
- `GET /connections` **cannot** discover the tenant: with a valid bearer token it returns
  `400 "Xero-User-Id and/or Xero-Tenant-Id header must be supplied"`, and with a dummy header
  it returns `200 []`. The tenant id is read from developer.xero.com → app → connected
  organisation. (Xero's "call /connections after OAuth" doc applies to authorization-code apps.)
- Credentials live in `provider_settings` — a **key/value** table `(provider, key_name,
  key_value)`. The previous Xero code read a `provider_name`/`settings` JSONB shape that never
  existed, so it always rendered "Not Connected" and could never store a token.
- The app carries 41 scopes including the full accounting set, so invoices, payments, contacts,
  accounts and reports are all available once the tenant is set.

### OAuth 2.0 Web app bridge (added 2026-09-18)

The Tenant ID has a second, scriptable route. A **Web app** (`Blue Mogul Portal (Web app)`,
app id `f9323485-4fb5-4d2e-a2b0-553885d518b0`) uses the authorization-code grant, gets a
**refresh token**, and — unlike the custom connection — `GET https://api.xero.com/connections`
returns `tenantId`. Authorising it once fills the custom connection's missing value.

- Code: `server/xero-oauth.ts`; routes `GET /portal/api/xero/oauth/{connect,callback,status}`,
  `POST /portal/api/xero/oauth/{settings,disconnect}`; UI card on `admin-xero.php`.
- Config lives under `provider = 'xero_oauth'` in the same `provider_settings` k/v table, so the
  working custom-connection rows (`provider = 'xero'`) are never overwritten.
- Default redirect URI: `https://portal.bluemogul.us/portal/api/xero/callback` — **must be
  byte-identical to the app's Configuration page**, and the 16 requested scopes must all be ticked there.
- On callback the tenant is stored on the `xero_oauth` row and **back-filled into the custom
  connection only if that `tenant_id` is empty** (so a deliberate value is never clobbered).
- Access tokens last 30 minutes; the refresh token rotates on every refresh, so the new one must
  be persisted each time (it is).

**Open decision (2d/2e):** Wave and Xero are both mirrored now. Decide which is the ledger of
record for the portal's client-facing financial panels — Xero (full ledger + reports) is the
better candidate; Wave then serves as a secondary revenue view.

## Phase 3 — Support depth

| Slice | Data model | Status |
|---|---|---|
| **3a Custom ticket statuses** | `ticket_statuses` (name, colour, is_closed, sort) + `tickets.status_id` | Not started |
| **3b Watchers / CC** | `ticket_watchers` (ticket_id, user_id/email) | Not started |
| **3c Saved views** | `ticket_views` (name, owner, filter_json, shared) | Not started |
| **3d Ticket ↔ asset links** | `ticket_assets` | Not started |
| **3e Ticket history** | `ticket_history` (ticket_id, actor, field, old, new, at) written from every mutation | Not started |
| **3f Recurring tickets** | `recurring_tickets` (+ tasks/assets), scheduler creates tickets on schedule | Not started |

## Phase 4 — CMDB depth (assets)

| Slice | Data model | Status |
|---|---|---|
| **4a Racks / rack units** | `racks`, `rack_units` (rack_id, u_position, asset_id) | Not started |
| **4b Interfaces & links** | `asset_interfaces`, `asset_interface_links` (switch port ↔ device) | Not started |
| **4c Asset history + tags** | `asset_history` (append on every change), `asset_tags` | Not started |
| **4d Custom fields** | `custom_fields` (entity, name, type, options), `custom_values` (entity, entity_id, field_id, value) — renders on client/asset/ticket forms | Not started |

## Phase 5 — Work management

| Slice | Data model | Status |
|---|---|---|
| **5a Project & task templates** | `project_templates` (+ `project_template_ticket_templates`), `task_templates`, `task_approvals` | Not started |
| **5b Shared calendar + trips** | `calendars`, `calendar_events`, `calendar_event_attendees`, `trips` (mileage) — work-order calendar already exists | Not started |
| **5c Document templates + versioning** | `document_templates`, `document_versions`, `folders`, `shared_items` (expiring share links) | Not started |
| **5d SLA assignment + breach history** | `sla_assignments`, `sla_history`, timer from `sla_terms` on tickets | Terms exist; assignment/history not started |

## Phase 6 — Platform plumbing & governance

| Slice | Data model | Status |
|---|---|---|
| **6a Email queue with retry** | `email_queue` (to, subject, body, attempts, next_attempt_at, status) + worker | Not started |
| **6b Notifications** | `notifications` (user_id, kind, payload, read_at) | Table exists, unused |
| **6c Login audit + per-record history** | `auth_logs`, `history` | `activity_log` covers part |
| **6d Per-user-per-client RBAC** | `user_client_permissions`, `api_keys` as first-class records | Roles matrix exists; per-client scoping not started |
| **6e Saved payment methods** | `client_saved_payment_methods` (Stripe PM id) | Needed for auto-top-up |
| **6f Vendors + AP documents** | `vendors`, `vendor_documents`, `vendor_credentials` | Wave vendors mirrored (3); no doc/credential store |

## Shipped since this plan was written (not ITFlow-parity items)

- **Dealer multi-tenancy (P1-P4)**: `dealer_users` tenant roles, sales attribution on orders, dealer-owned leads with
  lead→order auto-fill, dealer customers promoted to real portal clients (`clients.dealer_id`), per-rep commission
  attribution + reporting, two payout rails (PayPal, Stripe Connect).
- **Hostwinds sell loop**: reseller provisioning from a paid order (prepaid wallet and Stripe paths), credentials
  emailed, retry endpoint. Blocked on reseller credit.
- **Portal-wide fix**: `PDO::ATTR_EMULATE_PREPARES => true` in `config.php` — ended the `cached plan must not change
  result type` class of 500s/truncated pages that had silently broken several pages.
- **Read-only client snapshot for dealers** on the dealer customer page (services, invoice status, open tickets),
  gated on `clients.dealer_id`.

## Decisions needed from Tracey

1. **Expenses path** (2b) — portal manual entry + receipts (recommended, ships now) vs Wave CSV import vs Wave partner API request.
2. **Credential vault key** — derive from `PORTAL_SECRET` (no new secret to manage, recommended) or a dedicated `VAULT_KEY`.
3. **Phase order** — Phase 1 (security) first as planned, or jump to 2d/3 for client-visible wins.
4. **Wave business scope** — mirror only *Blue Mogul Enterprise, LLC* (default) or all four businesses (Blue Mogul, GEMCOM, Personal, Sigma Phoenix)?

## Risk register

- **Wave `Money.value` is comma-formatted** (`"2,350.00"`) — `parseFloat` truncates to `2`. Always use
  `minorUnitValue / 10^currency.exponent`. This bug shipped into the first sync run and was caught by
  reconciliation (invoiced $11,123.05 vs payments $21,161.85) — re-verify totals after any change to
  the Wave pull.
- **Credential vault crypto**: a wrong encryption choice is unrecoverable and leaks are non-revocable.
  Needs the key decision before code, and a migration path if the key rotates.
- **Net-new mutating endpoints** (reveal, rotate, expense approve) must be reviewed before deploy —
  the standing rule is no endpoint that mutates state beyond the immediate fix without approval.
- **Shared-file contention**: every slice touches `server/migrations.ts`, `includes/admin-sidebar.php`
  and `ALLOWED_PHP_FILES`. Slices are built sequentially, never in parallel worktrees.
