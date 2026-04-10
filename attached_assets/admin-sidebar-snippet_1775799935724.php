<?php
/**
 * admin-sidebar-snippet.php
 * ─────────────────────────────────────────────────────────────
 * ADD THESE ITEMS to your existing admin sidebar include
 * (admin-sidebar.php or wherever your sidebar nav lives).
 *
 * Find the section where you have your existing nav items
 * (Invoices, Services, Clients, etc.) and paste the block below
 * in the appropriate position — after Clients or before Integrations.
 *
 * The pending badge count queries the DB once at sidebar load.
 * ─────────────────────────────────────────────────────────────
 */

// ── Paste this PHP block near the top of your sidebar include ─
// (after your existing $pdo or DB connection is available)

$_dealer_pending = 0;
try {
    $dp = get_db()->query("SELECT COUNT(*) FROM dealers WHERE status='pending'");
    $_dealer_pending = (int)$dp->fetchColumn();
} catch (\Exception $e) {}

// ── Paste this HTML block into your sidebar nav ───────────────
// Add it after your existing client/invoice nav section.
?>

<!-- ═══════════════════════════════════════════════════════════
     DEALER PROGRAM — add this block to admin-sidebar.php
     ═══════════════════════════════════════════════════════════ -->
<div class="nav-section">Dealer program</div>

<a href="/portal/admin/admin-dealers.php"
   class="nav-item <?= strpos($_SERVER['PHP_SELF'],'admin-dealers')!==false&&strpos($_SERVER['PHP_SELF'],'detail')===false?'active':'' ?>">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
    <circle cx="9" cy="7" r="4"/>
    <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
  </svg>
  All dealers
  <?php if ($_dealer_pending > 0): ?>
  <span style="margin-left:auto;background:#dc2626;color:#fff;font-size:10px;font-weight:700;
               padding:1px 7px;border-radius:20px;line-height:1.6;">
    <?= $_dealer_pending ?>
  </span>
  <?php endif; ?>
</a>

<a href="/portal/admin/admin-dealer-payouts.php"
   class="nav-item <?= strpos($_SERVER['PHP_SELF'],'admin-dealer-payouts')!==false?'active':'' ?>">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
    <rect x="1" y="4" width="22" height="16" rx="2"/>
    <line x1="1" y1="10" x2="23" y2="10"/>
  </svg>
  Payout queue
  <?php
  // Show amber dot if there are processing payouts needing attention
  try {
    $proc = get_db()->query("SELECT COUNT(*) FROM dealer_payouts WHERE status='processing'")->fetchColumn();
    if ($proc > 0):
  ?>
  <span style="margin-left:auto;background:#d97706;color:#fff;font-size:10px;font-weight:700;
               padding:1px 7px;border-radius:20px;line-height:1.6;">
    <?= $proc ?>
  </span>
  <?php
    endif;
  } catch (\Exception $e) {}
  ?>
</a>
<!-- ═══════════════════════════════════════════════════════════ -->
