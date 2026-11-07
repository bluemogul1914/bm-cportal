# Service Offer: Business VoIP / Unified Communications

**Offer Code:** BM-VOIP-001  
**Last Updated:** 2026-10-27  
**Source Tier:** Foundation (Fiber + Managed IT) and standalone VoIP  
**Pricing Source:** wholesale_rate_card_2026-09-27.csv (Voip.ms contracted rates)  
**Verification Status:** Wholesale rates verified as contracted; retail rates verified from live portal.

---

## 1. Overview

Blue Mogul Business VoIP delivers cloud-hosted voice, SMS, and advanced call-center features over
Voip.ms's carrier-grade SIP trunking platform. Each plan includes local/toll-free DIDs, minutes,
an advanced IVR/auto-attendant, call recording, conference bridging, and CRM integration.

This offer covers standalone VoIP service. When bundled with Business Fiber (BM-FIBER-001) or
Managed IT (BM-MSP-001), the VoIP component retains the same wholesale cost and margin profile.

## 2. Service Tiers (Verified)

All VoIP plans are priced per "seat" (an active extension/user). Minutes are shared across the
account (not per-extension).

| Tier | Extensions | Minutes included | Wholesale cost* | Retail price (portal) | Gross margin |
|---|---|---|---|---|---|
| **V1** | 5 | Unlimited domestic | $4.25/seat | $49/mo (Starter) | **91.3%** |
| **V2** | 15 | Unlimited domestic | $12.75/seat | $89/mo (Business) | **85.4%** |
| **V3** | 50 | Unlimited domestic | $42.50/seat | $149/mo (Professional) | **71.5%** |
| **V4** | Unlimited | Unlimited domestic + Canada | $42.50/seat | $299/mo (Enterprise) | **85.8%** |

*Wholesale cost = VoIP.ms DID ($0.85) + per-minute usage at 400 min/seat × $0.0085/min +
call recording ($2.00) + conference bridge ($5.00) + call analytics ($3.00).
Source: `wholesale_rate_card_2026-09-27.csv`, Voip.ms contracted rates.

**Markup basis:** VoIP number markup policy is 400–500% (from catalog_3tier_v2_2026-09-27.md).
The actual retail markup ranges from 85% to 91% because the retail price includes advanced
features (IVR, call recording, analytics, CRM integration, conference bridge) that are priced
bundled rather than itemized per seat.

## 3. What's Included

All VoIP plans include:
- Local DID phone number ($4.99 retail; $0.85 wholesale)
- Unlimited domestic calling (US/Canada for Professional+ tiers)
- Voicemail-to-email transcription
- Mobile app (iOS/Android)
- Call forwarding, transfer, and hold
- Advanced IVR / auto-attendant
- Call recording (optional per-extension)
- Conference bridge (up to 25 participants; $19.99 retail)
- Call analytics dashboard
- Ring groups and queue management (Professional+)
- CRM integration (Salesforce, HubSpot — Professional+ only)

## 4. Add-On Features (Verified Pricing)

| Feature | Wholesale | Retail | GM |
|---|---|---|---|
| Additional local DID | $0.85/mo | $4.99/mo | 83.0% |
| Toll-free DID (800/888) | $2.00/mo | $9.99/mo | 79.9% |
| Vanity number | $3.00/mo | $14.99/mo | 79.9% |
| Additional VoIP extension | $4.25/mo | $8.00/mo | 46.5% |
| International calling (per-min, varies) | $0.0085–$0.05/min | $0.02–$0.10/min | varies |
| Fax to email | $2.00/mo | $9.99/mo | 79.9% |

## 5. Service Terms

| Term | Detail |
|---|---|
| Contract | Month-to-month (default); 12-month term available for 5% discount |
| Billing | Monthly in advance via ACH or credit card |
| Setup | One-time activation: $0 (promotional); normally $39.99 |
| Port-in | Free (1 included per account); $5 per additional port |
| Support | 24/7/365 business-hour phone support; 24/7 network ops |
| Provisioning | 1–3 business days for new numbers; 5–10 for port-ins |
| SLA | 99.9% network uptime (Voip.ms carrier SLA); not subject to our 99.95% hardware SLA |

## 6. Coverage

Voip.ms operates a nationwide US/Canada SIP trunking network with redundant POPs. Service quality
is dependent on the customer's internet connection (we recommend Fiber 500+ for ≥10 seats).

## 7. Verification Notes

- Wholesale costs are from `wholesale_rate_card_2026-09-27.csv` (Nextcloud /Pricing/), sourced
  from Blue Mogul MSP-Complete Telecommunications Guide v1.0 dated 2026-01-30, under Voip.ms
  contracted rates.
- Retail prices are from the live bm-cportal product catalog (`product_catalog.md`).
- Voip.ms unlimited USA/Canada plan wholesale cost is **UNPRICED** in the current rate card —
  the model uses per-minute at $0.0085/min × 400 min/seat. This is an open item requiring the
  first Voip.ms invoice to close.
- RMM monitoring and network monitoring (relevant if bundled) are BENCHMARK costs at $3.50/endpoint,
  not yet contracted.
