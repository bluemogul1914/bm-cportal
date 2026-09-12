# Frontier ASR — Public Pre-Qualification for fiber.bluemogul.us

**Date:** 2026-09-11 · **Author:** Aria · **Repo:** `bluemogul1914/bm-cportal` (deployed to portal.bluemogul.us)

## Coverage area (updated 2026-09-12)

Blue Mogul fiber service area is a **350-mile radius around Houston, TX**, spanning the
**Frontier Communications + Cox Communications** footprints. First target client is in the
**DFW (Dallas-Fort Worth)** area — not Missouri City. The qualify page is already
address-agnostic (it POSTs whatever street/city/state/ZIP the visitor enters to Frontier ASR),
so DFW works today with zero code change.

- **Frontier** — wired (`frontier-qualify.php` → Frontier ASR CTEST).
- **Cox** — NOT yet integrated. Cox availability needs its own pre-qual path (Cox uses a
  different wholesale/reseller API than Frontier ASR). Open follow-up; see Notes below.

## What was built

A public "Check Fiber Availability" page that lets **anyone** type an address and get a
pre-qualification answer from Frontier's ASR service — no portal login required.

| Route | Purpose |
|---|---|
| `GET/POST /portal/frontier-qualify` | Full branded page (navy hero, footer, trust row) |
| `GET/POST /portal/frontier-qualify?embed=1` | Chrome-stripped version for iframe embedding |

### Files changed
- **`frontier-qualify.php`** (NEW) — the public page: address form (street/city/state/zip),
  optional contact capture, lead persistence to `frontier_orders`, real Frontier SOAP call,
  result rendering (available / unavailable / checking).
- **`server/index.ts`** (1-line change) — added `frontier-qualify.php` to `ALLOWED_PHP_FILES`
  so the `/portal/:file` router serves it publicly.

### How it works
1. Visitor submits address (+ optional name/phone/email).
2. Lead is INSERTed into `frontier_orders` (status `PREQUAL_CHECKING`, source `fiber.bluemogul.us`).
3. Page calls Frontier CTEST via the **confirmed v10 SOAP client**
   (`frontier-asr-v10/src/FrontierASRClient.php`, `processSyncRequest` + `<in0>`/`<PreOrder>` envelope).
4. Result → status update + visitor-facing outcome:
   - `AVAIL=Y` → "Great news — fiber is available!" (status `PREQUAL_AVAILABLE`)
   - `AVAIL=N` → "Not yet available at this address" (status `PREQUAL_UNAVAILABLE`)
   - SOAP fault / no answer → "We're checking your address" + **Reference #** (status stays
     `PREQUAL_CHECKING`) — the lead is still captured and a rep follows up.
5. Every submission gets a PON reference so admin can track it in the Frontier Admin tab.

### Verified (2026-09-11)
- `php -l frontier-qualify.php` — no syntax errors.
- All 5 render branches tested with a mocked SOAP client (available / unavailable /
  checking / network-error / validation) — all pass.
- `npx tsc --noEmit` — `server/index.ts` clean (only pre-existing `routes.ts` errors remain).
- Live reachability: `POST https://portal.bluemogul.us/api/frontier/preorder` returns a real
  Frontier CTEST response (currently `WSASRTML004` — see below).

## ⚠️ Known issue: Frontier WSASRTML004 (pre-order payload unconfirmed)

Frontier's CTEST currently returns **`WSASRTML004 "Unknown error. Please contact support."`**
for the pre-order payload — both the v10 `<in0>/<PreOrder>` envelope AND the portal's older
`<string>` envelope produce it. This was already escalated to Frontier (email thread with
Kimberly, 2026-08-18, file `configs/frontier-reply-kimberly-20260818.md`); their app team has
not yet confirmed the payload shape against CTEST.

**Impact on this feature:** the page degrades gracefully — every visitor's address is still
saved as a lead with a reference number, and the member of the team follows up by phone.
The moment Frontier confirms the correct pre-order schema, only the SOAP client needs a tweak;
the page itself needs zero changes. Order flow (`processAsyncRequest`) is a separate path and
is unaffected by this pre-order fault.

## Deploy steps (portal.bluemogul.us — hills-01)

```bash
cd ~/bm-cportal-build && git pull origin main
./deploy-portal.sh        # or blue-mogul-deploy.sh portal (build + compose-swap + verify)
```

Then verify:
```bash
curl -s https://portal.bluemogul.us/portal/frontier-qualify | head -c 200
# → <!DOCTYPE html> ... Check Fiber Availability
```

## Embedding on fiber.bluemogul.us

Add to the fiber site where the "Check your address" CTA lives (replace `fiber.bluemogul.us`
in frame-ancestors below with the real fiber domain):

```html
<!-- Blue Mogul Fiber — Frontier Pre-Qualification Widget -->
<iframe
  src="https://portal.bluemogul.us/portal/frontier-qualify?embed=1"
  width="100%"
  height="620"
  frameborder="0"
  scrolling="auto"
  style="border-radius:12px;max-width:560px;display:block;margin:0 auto"
  title="Check Fiber Availability">
</iframe>
```

### Lead tracking
Submissions appear in the portal's **Frontier ASR admin** (`/portal/admin-frontier.php` →
Track / Pre-Qualify tabs) with status:
- `PREQUAL_AVAILABLE` — fiber confirmed at that address → call the lead, close the sale
- `PREQUAL_UNAVAILABLE` — not there yet → add to expansion waitlist
- `PREQUAL_CHECKING` — Frontier fault / unconverted → manual follow-up queue

## Notes / follow-ups
- [ ] **Cox coverage integration (NEW 2026-09-12)** — add a Cox pre-qual path alongside
      Frontier (DFW first client). Cox has a separate wholesale/reseller API; confirm the
      correct endpoint + payload with Cox's partner team, then mirror the `frontier-qualify.php`
      pattern (lead capture → status → follow-up queue).
- [ ] Frontier app team: confirm `processSyncRequest` pre-order payload (respond on the
      Kimberly thread — the exact envelope is in that file).
- [ ] When fiber.bluemogul.us is reachable, wire the iframe + ensure its server sends
      `Content-Security-Policy: frame-ancestors https://fiber.bluemogul.us` (or add the
      portal to its allowed frame-parents).
- [ ] Optional: flip the page to `PRODUCTION` endpoint (`ep.frontier.com`) once Frontier
      confirms CTEST — change `'environment' => 'TEST'` in `frontier-qualify.php` and the
      admin settings.
- [ ] Optional: honey-pot honeypot field + rate limit on the public POST to prevent bot spam.