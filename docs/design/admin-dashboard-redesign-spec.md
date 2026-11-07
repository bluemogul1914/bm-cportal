# Admin Dashboard — Design Refresh Spec

**Task:** #1813 (MUSE) — Design refresh: Blue Mogul portal admin dashboard with company branding
**Created by:** JACKIE | **Priority:** High | **Channel:** Design
**Status:** In progress

## 1. Brand Alignment

Same token set as the login page (Ink `#05070D`, Panel `#11151F`, Command Blue Deep `#1C2B5B`, Command Blue Primary `#2F6BFF`, Command Blue Bright `#5B8DFF`, Service Gold `#C8A24A`, Service Gold Deep `#8A6D2A`, Mute `#5A6474`, Paper `#E9EDF4`). Typography: Inter 300–700.

**Logo:** "BLUEMOGUL" wordmark — "BLUE" in white, "MOGUL" in Command Blue `#2F6BFF`. Mark (M in gradient square). Chevrons in Service Gold. Minimalist, modern, bold.

**Tagline:** "Military precision. Texas hospitality."

## 2. Requirements Coverage

| # | Requirement | Design Decision |
|---|---|---|
| 1 | Brand colors and typography | Apply token set; replace default grays with brand tokens |
| 2 | Sparklines + trend charts on MRR and Total Clients cards | 7-day sparkline (Chart.js) inside each card; trend arrow + % change |
| 3 | Fix empty states (misleading % when 0 clients) | Show "—" or "No data" when denominator is 0; never show "NaN%" or "0%" |
| 4 | Hover/focus states on nav + interactive elements | Command Blue hover bg, Service Gold focus ring, 2px transition |
| 5 | Improve System Status contrast | Ink `#05070D` bg, Paper text, status dots with 4.5:1 contrast |
| 6 | Standardize card icon placement | Icon in top-right corner of each card, 24px, Command Blue |
| 7 | Loading skeleton states | Shimmer skeleton for all cards while data loads |

## 3. Layout

```
┌─────────────────────────────────────────────────────────────┐
│  TOP NAV — Ink bg, logo left, user menu right               │
├─────────────────────────────────────────────────────────────┤
│  SIDEBAR (collapsible)  │  MAIN CONTENT                    │
│  ─────────────────────  │  ┌─────────────────────────────┐  │
│  Dashboard              │  │  Welcome, {name}            │  │
│  Clients                │  └─────────────────────────────┘  │
│  Subscriptions          │  ┌──────┐ ┌──────┐ ┌──────┐      │
│  Tickets                │  │ MRR  │ │Total │ │Open  │      │
│  Invoices               │  │ card │ │Clients│ │Tickets│      │
│  Products               │  └──────┘ └──────┘ └──────┘      │
│  Reports                │  ┌─────────────────────────────┐  │
│  Settings               │  │  Revenue Trend (12mo chart) │  │
│                         │  └─────────────────────────────┘  │
│                         │  ┌─────────────────────────────┐  │
│                         │  │  Top Products (table)        │  │
│                         │  └─────────────────────────────┘  │
│                         │  ┌─────────────────────────────┐  │
│                         │  │  Recent Clients (table)     │  │
│                         │  └─────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## 4. Component Specs

### KPI Cards (MRR, Total Clients, Open Tickets, Unpaid Invoices)
- Background: white, `rounded-xl`, `shadow-sm`, `p-6`
- Icon: top-right, 24px, Command Blue `#2F6BFF`
- Value: 2xl bold, Ink `#05070D`
- Label: small, Mute `#5A6474`
- Sparkline: 7-day, Command Blue line, 40px tall, bottom of card
- Trend: arrow + % change (green up, red down, gray flat)
- Empty state: "—" with "No data yet" subtext

### Revenue Trend Chart
- 12-month bar/line chart (Chart.js)
- Command Blue bars, Command Blue Bright line overlay
- X-axis: month labels, Y-axis: $ revenue
- Hover tooltip: month, revenue, MoM %
- Empty state: "No revenue data for this period"

### Top Products Table
- Columns: Product, Price, Subscribers, Revenue
- Header: Ink bg, Paper text
- Rows: alternating white/light-gray, hover Command Blue/5% bg
- Empty state: "No products configured"

### Recent Clients Table
- Columns: Name, Company, Status, Created
- Same styling as Top Products
- Empty state: "No clients yet — add your first client"

### System Status Widget
- Ink `#05070D` bg, Paper `#E9EDF4` text
- Status dots: green `#1F8A4C` (healthy), yellow `#B7791F` (warning), red `#B3261E` (critical)
- 4.5:1 contrast minimum on all text
- Hover: subtle glow on status dot

### Navigation Sidebar
- Ink `#05070D` bg
- Active item: Command Blue `#2F6BFF` left border (3px) + light bg
- Hover: Mute `#5A6474` bg at 20% opacity
- Focus: Service Gold `#C8A24A` ring
- Icons: Font Awesome, consistent `w-5 h-5`

### Loading Skeleton
- Shimmer animation (`animate-pulse`) on all cards
- Gray-200 placeholders matching card dimensions
- Skeleton lasts until data fetch completes

## 5. Accessibility
- 4.5:1 contrast minimum
- `aria-live="polite"` on dynamic data updates
- Keyboard navigation: Tab through nav, Enter to select
- Focus-visible rings on all interactive elements

## 6. Mobile
- Sidebar collapses to hamburger menu below `lg`
- Cards stack vertically on mobile
- Chart responsive (Chart.js responsive: true)

## 7. Deliverable
- Design spec (this document) ✅
- HTML mockup: `admin-dashboard-redesign.html` (see companion file)
- CSS/component changes: FORGE to implement per spec
- QA: VERITAS (task #1817)

## 8. Handoff
- **MUSE → FORGE** (tasks #1814, #1815, #1816) — implement per this spec
- **VERITAS** (task #1817) — QA review
- Do not deploy without Founder approval
