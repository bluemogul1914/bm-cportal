# Blue Mogul Suite — Splynx parity matrix

Generated 2026-09-18 · Basis: live system (repo `bm-cportal` main tip `b1befa1`,
running container image tag verified identical to main), Neon schema probed directly,
Splynx capability list from splynx.com + wiki.splynx.com module docs.

Companion to `docs/isp-gap-backlog.md` (which is the *work list*). This file is the
*standing assessment*: what we have, what we deliberately don't, and what is a real hole.

## How to read the status column

- **Shipped** — in `main`, deployed, schema confirmed live.
- **Partial** — works but narrower than Splynx (fewer rails, less depth).
- **Out of scope** — a deliberate decision, documented in the backlog's anti-Splynx list.
- **Gap** — something a paying ISP client would expect that we do not have.

## Coverage context

- Service area: 350-mile radius around Houston, TX (Frontier + Cox footprints).
- First fiber client: DFW. Frontier pre-qual wired (`frontier-qualify.php` → ASR).
- Cox pre-qual: blocked on Cox API docs (MC task 1137).
- Billing model: **prepaid only** — client tops up a wallet, service runs until balance
  hits $0, auto-suspend, top-up reactivates. No postpaid AR, dunning, late fees,
  proration or collections, by design.

## Matrix

| Splynx capability | Status | Evidence |
|---|---|---|
| Recurring & prepaid billing | **Shipped (prepaid)** | `clients.credit_balance` / `last_charged_at`, `transaction_ledger`, `chargeMonthlySubscriptions` (`server/balance-scheduler.ts`) |
| Multi-service bundle billing | **Shipped — ahead of Splynx** | one wallet funds Fiber / VoIP / V2Cloud / RMM / Managed-IT lines; `subscriptions`, `admin-client-services.php` |
| Invoice-gated activation (order → invoice → paid → active) | **Shipped** | `POST /api/admin/service-invoice/checkout`, `processPendingServiceInvoices` |
| Branded receipt / invoice PDFs | **Shipped** | `server/receipt-pdf.ts`, `invoice_footer_templates`, `invoice_sequences` |
| Payment channels | **Partial** | Stripe live only (no PayPal / ACH / local rails) |
| Accounting sync (QuickBooks / Sage / Xero) | **Gap** | `admin-xero.php` exists but is in no sidebar — orphan page, no live connector |
| RADIUS server | **Out of scope** | UISP does AAA for the airMAX fleet |
| TR-069 ACS | **Out of scope** | UISP is the ACS equivalent |
| IPAM (IPv4/IPv6) | **Shipped** | `ip_pools`, `ip_allocations`, `admin-ip-pools.php` |
| Network sites & maps | **Shipped** | `network_sites`, `admin-network-map.php` |
| Bandwidth management / QoE / caps | **Out of scope** | UISP side |
| Monitoring / backups / change control | **Partial** | `admin-monitoring.php` + 6-source device sync — see "Integration syncs" below |
| CRM (accounts, leads, deals, quotes, companies, contacts) | **Shipped** | `admin-crm.php`, `admin-leads-*`, `crm_deals`, `crm_companies` |
| Customer self-service portal | **Shipped** | 13 client pages incl. `dashboard`, `billing`, `services`, `tickets`, `documents`, `projects`, `client-voip`, `client-wallet`, `frontier-qualify` |
| White-label mobile app (client + field tech) | **Gap — largest** | no PWA manifest / Capacitor / Expo anywhere; web portal only |
| Reseller / dealer sub-portal | **Shipped — ahead of Splynx** | `dealer-*` suite: register, orders, commissions, payouts, spiffs, training, per-dealer SMTP |
| Communications: email / SMS / WhatsApp / calls | **Partial** | email deep (`email_log`, sequences, campaigns, SMTP); SMS only via the VoIP provider; **no WhatsApp** |
| Ticketing + automation + SLA | **Shipped** | `tickets`, `ticket_time_entries`, `admin-automation.php`, `service_contracts` + `sla_terms`, knowledge base |
| Field services: work orders, calendars, checklists | **Shipped** | `work_orders`, `work_order_checklist_templates`, month/week/Splynx-style day timeline |
| Inventory (tracking, sites, suppliers, POs) | **Shipped** | `assets` (deployed-status), `purchase_orders` + `po_line_items` + approval flow |
| Time tracking & reports | **Shipped** | `project_time_entries`, `admin-time-tracking.php`, `admin-reports.php` |
| VoIP per-minute CDR billing | **Out of scope** | flat-rate plans; the switch does the rating |

## Where Mogul Suite is ahead of Splynx

- **Frontier ASR / provider-agnostic pre-qualification** — address → availability → lead →
  follow-up queue (`frontier-qualify.php`, `api/frontier/*`, `frontier_orders`/`frontier_logs`).
  Splynx has no carrier-ASR concept.
- **D&H distribution ordering** — cart → D&H POST → portal invoice (`server/dh-api.ts`,
  `admin-dandh.php`). Still on the D&H **test** host.
- **AI layer** — AI agents + assistant pages, Hermes/BMAI bridge, Mission Control.
- **Managed-services billing** — RMM / V2Cloud / Managed-IT lines billable from the same
  prepaid wallet; Splynx is ISP-only.

## Integration syncs (Network Docs population)

The daily sync (`server/network-sync.ts`, 6 sources → `network_assets`) ran green but
Network Docs (`admin-network.php`) stayed empty. Root causes found 2026-09-18, all
verified by direct API probe from inside the portal container:

| Source | Root cause | Resolution |
|---|---|---|
| **all sources** | Sync wrote `network_assets`; Network Docs reads `network_devices`. Both tables existed, so nothing errored — the data landed where the UI never looks. | `upsertAsset` now mirrors every asset into `network_devices` (keyed `source` + `external_id`, migration adds both columns + unique index). Verified idempotent against live DB. |
| Action1 | OAuth call passed `action1_api_key` as the **client secret** → HTTP 401. The real secret is `action1_client_secret` (probe: 401 with one, 200 with the other). | Uses `action1_client_secret` (env `ACTION1_CLIENT_SECRET`), falls back to the old key. **Remaining blocker:** the token authenticates but `/endpoints` returns 403 — the API key lacks the Endpoints permission in the Action1 console (`/users`, `/me`, `/organizations` all 200). Needs a permission toggle, not code. |
| JumpCloud | Code read `jumpcloud_api_key`, DB stores `JUMPCLOUD_API_KEY` → empty; plus `x-org-id` triggers 404 "selected organization not found", and `/api/v2` 404s for this key. | Case-insensitive settings lookup + v2→v1 base fallback + `results` parsing; org header dropped. **Tenant really has 0 systems** (v1 `/systems` → `totalCount: 0`; 1 user = the API key itself), so 0 rows is correct, not a bug. |
| VoIP.ms | Code read `voip_api_*`, DB stores `voip_ms_*` → fell back to stale env values → `invalid_credentials`. | Reads `voip_ms_username` / `voip_ms_password` / `voip_ms_api_key` (old names kept as aliases). **All stored credential combinations still return `invalid_credentials`** — needs a valid VoIP.ms API password from the account (IP is not the issue; that returns a distinct `ip_not_enabled`). |
| Hostwinds | Same case mismatch (`HOSTWINDS_API_*`), so the source reported "not configured" without ever calling out. | Case-insensitive lookup resolves the creds. **Their endpoint answers HTTP 200 `{"result":0,"msg":"No API KEY in request"}` for every documented field-name shape** (`apikey`, `api_key`, `key`, `identifier`+`secret`, both action casings) — needs the exact field names/credential type from the Hostwinds panel page. Low priority: no Hostwinds services in use. |
| Hetzner | No `hetzner_api_token` in `system_settings` and no `HETZNER_API_TOKEN` in the container env → "not configured". | Not a code bug — the token is missing. (3 Hetzner servers in `network_assets` came from an earlier run that had one.) Needs the token re-added. |
| UISP | Works: key authenticates, `/nms/api/v2.1/sites` returns 1 site (BM Remote). `/nms/api/v2.1/devices` returns `[]`. | Not a bug — **no devices are provisioned in this UISP instance yet**. The sync now says so explicitly instead of reporting silence. |

## Open items (in priority order)

1. **`network_devices` population** — mirror shipped; UISP needs devices provisioned, and
   Action1 needs the Endpoints permission enabled, before the table fills beyond Hetzner.
2. **Mobile app** (white-label client + field tech) — nothing started.
3. **Accounting connector** — decide Xero (page exists) vs QuickBooks, then wire it.
4. **Second payment rail** — PayPal and/or ACH to reduce Stripe-only risk.
5. **WhatsApp / real SMS gateway** — email + VoIP SMS only today.
6. **RADIUS/PPPoE** — revisit when Missouri City FTTH needs PPPoE auth at ~50+ subs.
7. **Frontier + D&H out of TEST** — production credentials pending.
