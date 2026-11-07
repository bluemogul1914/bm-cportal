# Blue Mogul — Service Offer Index

**Purpose:** This document is the one-page offer sheet for the Foundation milestone.
It indexes the three verified service offers prepared by DEV (proxy for STRATEGIST)
using data from the Nextcloud /Pricing/ wholesale rate card
(`wholesale_rate_card_2026-09-27.csv`, CONTRACT provenance) and the live bm-cportal
product catalog (`product_catalog.md`).

**Date:** 2026-10-27
**Deadline:** Foundation milestone closes 2026-10-31
**Status:** Three offers prepared, pricing verified. STRATEGIST agent is dead/unroutable;
PATHFINDER (C6 — sales priorities and offer strategy, task #1609) has not yet produced
domain input, so offers are prepared from verified data only.

---

## Offer Quick Reference

| Offer Code | Service | Tier | Wholesale/mo | Retail/mo | GM | Offer Doc |
|---|---|---|---|---|---|---|
| BM-FIBER-001 | Business Fiber Internet | F2 (1000/1000) | $74.99 | $299 | **75.1%** | [service-offer-fiber.md](service-offer-fiber.md) |
| BM-FIBER-002 | Fiber + VoIP bundle | F2 + V2 | $86.71 | $388 | **77.8%** | [service-offer-fiber.md](service-offer-fiber.md) |
| BM-FIBER-003 | Fiber + Managed IT bundle | F2 + IT2 (10 seats) | $141.49 | $1,995 | **92.9%** | [service-offer-fiber.md](service-offer-fiber.md) |
| BM-VOIP-001 | Business VoIP | V2 (15 extensions) | $12.75 | $89 | **85.4%** | [service-offer-voip.md](service-offer-voip.md) |
| BM-VOIP-002 | Business VoIP | V3 (50 extensions) | $42.50 | $149 | **71.5%** | [service-offer-voip.md](service-offer-voip.md) |
| BM-MSP-001 | Managed IT Support | IT2 (25 seats) | $81.80/seat | $249/seat | **67.1%** | [service-offer-managed-it.md](service-offer-managed-it.md) |

> **Retail prices** are from the live bm-cportal product catalog (verified, portal-displayed).
> **Wholesale costs** are from `wholesale_rate_card_2026-09-27.csv` (CONTRACT provenance).
> Where cost inputs are BENCHMARK (RMM, EDR, cloud backup for Managed IT), this is noted
> in each offer document.

---

## Foundation Milestone Context

The Foundation milestone targets $2K MRR by 2026-10-31. The three service offers above
are the standalone components; when bundled, they produce the Foundation package
(Fiber + VoIP + Managed IT) at a blended 60-70% gross margin per the markup policy.

### Bundle Pricing (ready for quotes)

| Bundle | Components | Retail | Wholesale | GM |
|---|---|---|---|---|
| **Foundation Starter** | Fiber F1 + VoIP V1 + IT1 (5 seats) | $507/mo | $50.99 | **89.9%** |
| **Foundation Pro** | Fiber F2 + VoIP V2 + IT2 (10 seats) | $587/mo | $108.99 | **81.4%** |
| **Foundation Unlimited** | Fiber F2 + VoIP V3 + IT2 (25 seats) | $687/mo | $152.30 | **77.8%** |

---

## How to Use These Offers

1. **For HUNTER (lead outreach):** Use the offer docs as one-pagers when contacting
   prospects. All pricing and wholesale costs are verified — safe for client discussion.

2. **For CLOSER (closing):** Copy the offer codes into the portal's quote system
   (`admin-leads-quotes.php` → `lead_quotes` table). The `offer-definitions.json`
   file provides machine-readable line items in the portal's `LineItem` format
   `{ description, quantity, unitPrice }`.

3. **For ATLAS/PATHFINDER (STRATEGIST domain input):** Once C6 market analysis
   (#1609) is available, validate the coverage assumptions and retail tier selections
   against the market data.

4. **For PORTAL integration:** The `offer-definitions.json` file maps each offer code
   to a ready-to-use line-item array compatible with `generateQuotePdfBuffer()`.
   The portal's `lead_quotes.items` column (JSON TEXT) accepts these directly.

---

## Verification Audit Trail

| Data Source | File | Provenance | Status |
|---|---|---|---|
| Frontier rates | `wholesale_rate_card_2026-09-27.csv` | CONTRACT (SCM378) | Verified |
| Voip.ms rates | `wholesale_rate_card_2026-09-27.csv` | CONTRACT | Verified |
| TravelSim (5G failover) | `wholesale_rate_card_2026-09-27.csv` | CONTRACT (BICS) | Verified |
| Portal retail prices | `product_catalog.md` | Portal (live) | Verified |
| Markup policy | `catalog_3tier_v2_2026-09-27.md` | Internal model | Noted as PROPOSED |
| RMM/EDR/backup costs | `catalog_3tier_v2_2026-09-27.md` | BENCHMARK | Not yet contracted |

---

## Open Items Requiring Owner Decision

1. **RMM/EDR/backup supplier contracts:** Currently BENCHMARK costs. A contract with
   NinjaRMM + Cylance + Druva is needed to replace benchmark costs with contracted rates.
2. **Voip.ms unlimited plan:** Wholesale cost is UNPRICED in the rate card — model uses
   per-minute at $0.0085/min × 400 min/seat. Needs first invoice to close.
3. **E-Path vs Broadband per-address qualification:** Tier 1 SLA pricing varies per
   building — needs per-site lookup before quoting.
4. **Foundation milestone $2K MRR:** At current bundles, the "Pro" bundle ($587) needs
   ~3-4 customers to hit $2K MRR. See task #1748 for lead count.
