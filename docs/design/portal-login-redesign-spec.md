# Portal Login Page — Design Refresh Spec

**Task:** #1812 (MUSE) — Design refresh: Blue Mogul portal login page with company branding
**Created by:** JACKIE | **Priority:** High | **Channel:** Design
**Status:** In progress

## 1. Brand Alignment (from Blue Mogul SOUL)

| Token | Value | Usage |
|---|---|---|
| Ink | `#05070D` | Primary background (near-black, softer than pure black) |
| Panel | `#11151F` | Card/panel backgrounds |
| Command Blue (Deep) | `#1C2B5B` | Deep brand, headers |
| Command Blue (Primary) | `#2F6BFF` | Primary brand, CTA, "MOGUL" accent |
| Command Blue (Bright) | `#5B8DFF` | Hover/active states, highlights |
| Service Gold | `#C8A24A` | Accent — star, CTA, chevrons, "veteran-owned" mark |
| Service Gold (Deep) | `#8A6D2A` | Deep gold accent |
| Mute | `#5A6474` | Secondary text, borders |
| Paper | `#E9EDF4` | Text, highlights (light surfaces) |

**Logo:** "BLUEMOGUL" wordmark — "BLUE" in white, "MOGUL" in Command Blue `#2F6BFF`. All uppercase, sans-serif. Plus mark (M in gradient square `#1C2B5B`→`#2F6BFF`). Chevrons `▲▲▲ TEXAS ▼▼▼` in Service Gold `#C8A24A`. Minimalist, modern, bold.

**Tagline:** "Military precision. Texas hospitality."

**Typography:** Inter (300–800). Headings: 700–800. Body: 400–500.
**Logo file:** `/assets/img/logo.png` (existing). Vectorize to SVG for crispness.

## 2. Requirements Coverage

| # | Requirement | Design Decision |
|---|---|---|
| 1 | Brand colors, logo, typography consistently | Apply the token table above; Command Blue `#2F6BFF` primary, Service Gold `#C8A24A` accent; logo centered in left panel |
| 2 | Secondary accent color for interactive elements | Service Gold `#C8A24A` for focus rings, hover states, links |
| 3 | Improve role tab differentiation (Staff/Dealer/Client) | Three distinct icons + color-coded active state (Command Blue / Service Gold) with clear selected indicator |
| 4 | Standardize iconography | Font Awesome 6 (existing) — single set, consistent `w-5 h-5` sizing |
| 5 | Password visibility toggle, focus states, error handling, loading states | Toggle button with eye/eye-slash; `focus-visible:ring-2`; inline error banner; spinner on submit |
| 6 | 'Forgot Password' link | Already present — restyle to Service Gold accent, ensure 4.5:1 contrast |
| 7 | Mobile responsiveness | Single-column stack below `lg`, full-width inputs, 48px touch targets |

## 3. Layout

```
┌─────────────────────────────────────────────────────────────┐
│  LEFT PANEL (lg:w-1/2) — dark navy, brand gradient          │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  Logo (centered) + tagline                          │    │
│  │  "Welcome to Blue Mogul Suite"                      │    │
│  │  "Veteran-owned · Black-owned · Texas"              │    │
│  │                                                     │    │
│  │  [ Staff ]  [ Dealer ]  [ Client ]   role tabs      │    │
│  │                                                     │    │
│  │  ┌─────────────────────────────────────────────┐    │    │
│  │  │  Email or Username                          │    │    │
│  │  │  ───────────────────────────────────────    │    │    │
│  │  │  Password  [👁]                             │    │    │
│  │  │  ───────────────────────────────────────    │    │    │
│  │  │  ☐ Remember me     Forgot password?        │    │    │
│  │  │  [  Sign In  ]   (loading spinner state)    │    │    │
│  │  └─────────────────────────────────────────────┘    │    │
│  │  Error banner (inline, aria-live="assertive")        │    │
│  └─────────────────────────────────────────────────────┘    │
│  RIGHT PANEL (lg:w-1/2) — light, brand illustration         │
│  (optional hero image / community photo)                    │
└─────────────────────────────────────────────────────────────┘
```

## 4. Component Specs

### Role tabs
- 3 tabs: Staff, Dealer, Client
- Active: Command Blue `#2F6BFF` bg, white text, 2px border
- Inactive: transparent bg, Mute `#5A6474` text, 1px gray border
- Hover (inactive): Command Blue border, white text
- Focus: 2px Service Gold `#C8A24A` ring
- Icons: `fa-user-tie`, `fa-handshake`, `fa-user` (consistent `w-5 h-5`)

### Inputs
- Border: 2px `#E9EDF4` (Paper) → focus `#2F6BFF` (Command Blue) with `ring-2 ring-[#5B8DFF]/30`
- Padding: `px-4 py-3` (48px touch target on mobile)
- Background: white; placeholder: Mute `#5A6474` at 60% opacity
- Error state: border `#B3261E` (red), error text below input

### Password toggle
- Button: absolute right-4, vertically centered
- Icon: `fa-eye` / `fa-eye-slash`
- `aria-label="Toggle password visibility"`, `aria-pressed` state
- Focus ring: Service Gold

### CTA button
- Command Blue `#2F6BFF` bg, white text, `rounded-xl`, `py-3 px-6`
- Hover: darken 10% (`#1C2B5B`)
- Active: scale 0.98
- Loading: spinner + "Signing in…" text, disabled state

### Error banner
- Red `#B3261E` left border (4px), light red bg `#FEE2E2`, dark red text
- `aria-live="assertive"`, `role="alert"`
- Auto-dismiss after 5s or on input change

### Loading skeleton
- Shimmer animation on form inputs while auth check runs
- `bg-gray-200 animate-pulse` placeholders

## 5. Accessibility
- Skip-to-content link (already present)
- `role="tablist"`, `aria-selected` on tabs
- `aria-live="assertive"` on error
- Focus-visible rings on all interactive elements
- 4.5:1 contrast minimum on all text
- Mobile: 48px touch targets, single-column layout

## 6. Mobile Breakpoints
- `< lg` (1024px): single column, full-width inputs, larger tap targets
- `lg+`: two-column split (form left, hero right)

## 7. Deliverable
- Design spec (this document) ✅
- HTML mockup: `portal-login-redesign.html` (see companion file)
- CSS/component changes: FORGE to implement per spec
- QA: VERITAS (task #1817)

## 8. Handoff
- **MUSE → FORGE** (task #1814) — implement per this spec
- **VERITAS** (task #1817) — QA review
- Do not deploy without Founder approval
