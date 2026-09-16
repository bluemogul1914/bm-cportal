# Blue Mogul Suite — ISP Gap Backlog (Splynx-class parity)

Generated: 2026-09-10 · **Rewritten for PREPAID model: 2026-09-12** · Source of truth for JCode feature work · One PR per item
Branches: `feat/<slug>-20260912+` on top of `main`. When done: Aria reviews the diff, commits, deploys.

## Coverage (as of 2026-09-12)
- Service area = **350-mile radius around Houston, TX** spanning **Frontier + Cox** footprints.
- First fiber client is **DFW** (Dallas-Fort Worth) — NOT Missouri City.
- Frontier pre-qual: wired (`frontier-qualify.php` → ASR). Cox pre-qual: BLOCKED pending Cox API docs (MC task 1137, expected ~Sep 19).

## Billing model — PREPAID (this changes everything)
All Blue Mogul billing is **prepaid**: client pays first → credit balance → service active
until balance runs out → top-up → renew. There is **no postpaid AR aging, no dunning,
no late fees, no proration, no collections**.

Consequence: do NOT build a postpaid invoice-chasing engine. Build a **prepaid balance ledger**:
- invoices are *receipts for top-ups*, not bills
- the "recurring" job is a *low-balance / renewal reminder*, not an invoice generator
- auto-suspend fires when balance hits $0 (not when an invoice is N days overdue)

## P0 — Revenue core (prepaid)

### 1. Prepaid balance ledger + top-up flow — REPLACES postpaid recurring engine
The `feat/recurring-invoices-20260910` branch landed a **postpaid** recurring-invoice engine
(schema + API + admin UI). It is the wrong shape. Keep the good parts, reshape the rest:
- **Client credit balance** field + transaction ledger (every top-up/renewal appends a dated entry)
- **Top-up flow**: Stripe checkout adds credit → auto-creates `INV-XXXXX` receipt PDF → balance updates
- **Low-balance reminder**: Node-side scheduler (pattern `server/network-sync.ts` cron — do NOT use a
  PHP cron; PHP sessions are ephemeral in this Node-wrapped architecture) sends ONE reminder email when
  balance falls below a threshold, then a final notice at $0
- **Auto-suspend at $0**: set client `status` flag (clear on top-up) — no manual Generate Now required
- **Auto-top-up (optional)**: Stripe saved-card auto-charge when enabled per-client
- Idempotency guard: a top-up that fails must not double-credit; a reminder must not double-send
Acceptance: client tops up via Stripe → receipt PDF + balance updated; at $0 → suspended flag set +
one final notice sent; top-up clears flag. No postpaid "past due" logic anywhere.

### 2. Branded receipt/invoice PDFs
- Branded PDF (logo, INV-XXXXX, itemized lines, tax, footer template) downloadable from admin
  invoice detail AND client portal invoice view. Used for top-up receipts (item #1) and any manual invoice.
Acceptance: a client can download a branded PDF of any receipt/invoice from their portal.

### 2b. Prepaid multi-service subscriptions (Fiber / VoIP / V2Cloud / RMM / Managed-IT)
Extends #1 so a client's prepaid wallet funds MULTIPLE service lines they subscribe to.
**Decisions (user, 2026-09-16 — "your recommendations"):** ONE shared wallet per
client funding all lines; full month prepaid at activation + every 30 days; service-line
categories use the exact names **Fiber / VoIP / V2Cloud / RMM / Managed-IT**; whole-account
suspend at $0 (per-service suspend is a UI toggle that drops that line out of the charge).

Model:
- Products carry `category` in one of the five names (re-map the existing
  Internet→Fiber, Cloud→V2Cloud, Managed IT→RMM+Managed-IT as needed) with a monthly `price`.
- `subscriptions` (client_id→product_id, status, mrr) is the client↔service link — one row per
  service per client. "Linked to the client account" = these rows.
- New scheduler step `chargeMonthlySubscriptions(pool)` runs before `checkAllBalances`:
  per client with ≥1 active subscription, `monthlyTotal = SUM(mrr)`; charge when
  `clients.last_charged_at` is NULL (first active sub → charge immediately) or
  `now - last_charged_at >= 30 days`. Charge via existing `recordTransaction(type:'charge')`
  so the wallet drops and the existing low-balance/suspend-at-$0/top-up-reactivate logic
  is untouched. `recordTransaction` already floors at $0 → that IS the suspend trigger.
- Schema: add `clients.last_charged_at timestamptz` (nullable) via `ADD COLUMN IF NOT EXISTS`.
- Wire the new step into `POST /api/admin/balance-check/run` AND the daily cron, before balances.
- P1 admin **Client Services** page: per client, list subscriptions grouped by line with an
  Active/Suspended toggle + monthly total, beside the prepaid balance (extends
  `admin-billing-reminders.php`).
- P1 client-portal **My Services** view: their services, balance, top-up.

Acceptance: a client with Fiber+VoIP+RMM subscriptions receives ONE monthly charge = the sum
once every 30 days; at $0 the account suspends (blocks login); a top-up reactivates it; a
service suspended in the UI drops out of the next monthly charge.

## P1 — Field ops (before Alaska installs)

### 3. Field work orders + checklists + scheduling
- Job/task records linked to client, ticket, or network site (per Splynx v6.0 pattern); calendar view;
  checklist templates (install, survey, decommission)
- Tie into existing `projects`/`tickets`
Acceptance: create a work order from a ticket, attach an install checklist, see it on a calendar,
mark items done with timestamps.

### 4. Inventory with deployed-status
- Extend `assets`: statuses (received → assigned → deployed), link to network site, supplier + PO
  reference, per-site equipment list
Acceptance: every airMAX CPE/radio has a status; SEARHC site survey equipment appears under its network site.

## P2 — Finish original plan

### 5. Time tracking UI + reports (tables `project_time_entries` / `ticket_time_entries` already exist — mostly surfacing)
### 6. Service contracts / SLAs (contract record + SLA terms + ticket SLA timer)
### 7. PO approval flow + client API (Vonda's SEARHC workspace + MCP agents both benefit) + ITFlow data migration

## P3 — Network depth

### 8. Network site map + IP pools + UISP live sync into Network Docs
- Pull devices/sites from UISP API into `network_devices` (UISP is deployed; `portal_integration`
  API token is live; `UISP_API_KEY` + `UISP_URL` are in the portal container env)
- Each client record should show their actual radio/ONT alongside their prepaid balance
Acceptance: UISP devices appear in Network Docs; a client's UISP device is linked to their account.

## Blocked / waiting
- **Cox pre-qual integration (MC task 1137)** — BLOCKED until Cox API docs arrive (~Sep 19,
  Marcella Jones thread). Do not start. When unblocked: mirror `frontier-qualify.php` pattern
  (lead capture → Cox availability → status → follow-up queue) + dual-check Frontier/Cox per address.

## Explicitly OUT OF SCOPE (anti-Splynx list — do not build)
- TR-069 ACS (UISP manages the airMAX fleet — that IS the ACS equivalent)
- RADIUS server (UISP does AAA for airMAX; revisit only when Missouri City FTTH needs PPPoE auth at ~50+ subs)
- Hotspot/vouchers (UISP native)
- VoIP per-minute CDR billing (flat-rate plans; switch does the rating)
- Reseller sub-portals (dealer program covers it)
- Data caps/top-ups, MDU MPSK (no usage-based plans or MDU market yet)
- Postpaid dunning / AR aging / collections / late fees (all billing is prepaid)
