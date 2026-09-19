<?php
/**
 * includes/dealer-header.php
 * Blue Mogul bm-cportal — Shared dealer page header + sidebar
 *
 * Usage at top of every dealer page (from project root):
 *   $page_title = 'Page Name';
 *   require_once __DIR__ . '/includes/dealer-header.php';
 *   dealer_auth();
 *   $dealer = dealer_me();
 */

require_once __DIR__ . '/dealer-functions.php';

if (!isset($page_title)) $page_title = 'Dealer Portal';
if (session_status() === PHP_SESSION_NONE) session_start();
$_dealer = isset($_SESSION['dealer_id']) ? dealer_me() : ['full_name' => 'Dealer', 'dealer_code' => '', 'tier' => 'base'];
$_initials = strtoupper(
    substr($_dealer['full_name'], 0, 1) .
    (strpos($_dealer['full_name'], ' ') !== false
        ? substr(strrchr($_dealer['full_name'], ' '), 1, 1)
        : '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?> — Blue Mogul Dealer</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/tailwind.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="/assets/js/tailwind-shared.js"></script>
  <script>tailwind.config = window.bmTailwindConfig;</script>
  <style>
    *{margin:0;padding:0;box-sizing:border-box;}
    :root{
      --navy:#0a1628;--navy2:#0d1f3c;--navy3:#112347;
      --blue:#1a56a0;--blue-lt:#4a9eff;
      --white:#ffffff;--bg:#f4f6f9;
      --border:#e2e8f0;--border-dark:#1e3a5f;
      --text:#1a202c;--text-m:#4a5568;--text-lt:#718096;
      --green:#15893e;--green-bg:#e6f4ec;--green-text:#14532d;
      --amber:#b45309;--amber-bg:#fef3e2;--amber-text:#7a4f0d;
      --red:#c53030;--red-bg:#fff5f5;--red-text:#742a2a;
      --blue-bg:#e6f1fb;--blue-text:#0c447c;
      --teal:#0d9488;--teal-bg:#e6f7f6;--teal-text:#0f4c48;
      --silver-bg:#f1f5f9;--silver-text:#475569;
      --gold-bg:#fef3c7;--gold-text:#92400e;
      --sidebar-w:220px;
      --font:'DM Sans',system-ui,sans-serif;
      --radius:8px;--radius-lg:12px;
    }
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:flex;}

    /* ── SIDEBAR ── */
    .sidebar{width:var(--sidebar-w);min-width:var(--sidebar-w);background:var(--navy);display:flex;flex-direction:column;min-height:100vh;position:sticky;top:0;}
    .sidebar-logo{padding:18px 18px 14px;border-bottom:1px solid var(--border-dark);}
    .sidebar-logo .wordmark{font-size:14px;font-weight:600;letter-spacing:.08em;color:var(--blue-lt);}
    .sidebar-logo .panel-label{font-size:10px;color:#4a7ab5;margin-top:2px;}
    .sidebar-nav{flex:1;padding:10px 10px;overflow-y:auto;}
    .nav-section{font-size:9px;letter-spacing:.15em;text-transform:uppercase;color:#3a5a82;padding:10px 8px 4px;font-weight:600;}
    .nav-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:var(--radius);color:#6b8bb5;font-size:12.5px;font-weight:500;text-decoration:none;margin-bottom:1px;transition:background .12s,color .12s;}
    .nav-item:hover{background:rgba(74,158,255,.08);color:#a8c4e0;}
    .nav-item.active{background:rgba(74,158,255,.12);color:var(--blue-lt);}
    .nav-item svg{width:15px;height:15px;flex-shrink:0;opacity:.7;}
    .nav-item.active svg{opacity:1;}
    .sidebar-foot{padding:12px 12px;border-top:1px solid var(--border-dark);}
    .dealer-chip{display:flex;align-items:center;gap:9px;}
    .dealer-av{width:30px;height:30px;border-radius:50%;background:#1e3a5f;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:var(--blue-lt);flex-shrink:0;}
    .dealer-name{font-size:12px;font-weight:500;color:#c0d0e8;line-height:1.3;}
    .dealer-code{font-size:10px;color:#4a7ab5;font-family:monospace;}
    .logout-link{display:block;margin-top:8px;font-size:11px;color:#3a5a82;text-decoration:none;padding:4px 10px;}
    .logout-link:hover{color:#6b8bb5;}

    /* ── MAIN ── */
    .main{flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;}
    .topbar{background:var(--white);border-bottom:1px solid var(--border);padding:13px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;}
    .topbar-title{font-size:16px;font-weight:600;color:var(--text);}
    .topbar-sub{font-size:12px;color:var(--text-lt);margin-top:1px;}
    .topbar-right{display:flex;align-items:center;gap:10px;}
    .page-body{padding:24px;flex:1;}

    /* ── STAT CARDS ── */
    .stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
    .stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px 18px;}
    .stat-icon{width:32px;height:32px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;margin-bottom:10px;}
    .stat-label{font-size:11px;color:var(--text-lt);font-weight:500;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
    .stat-value{font-size:24px;font-weight:600;color:var(--text);line-height:1;}
    .stat-sub{font-size:11px;color:var(--text-lt);margin-top:4px;}
    .stat-up{color:var(--green);}

    /* ── CARDS ── */
    .card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:16px;}
    .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
    .card-title{font-size:13px;font-weight:600;color:var(--text);}
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;}

    /* ── TABLES ── */
    table{width:100%;border-collapse:collapse;font-size:12.5px;}
    th{text-align:left;font-size:11px;font-weight:600;color:var(--text-lt);padding:8px 12px;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;}
    td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text);vertical-align:middle;}
    tr:last-child td{border-bottom:none;}
    tr:hover td{background:#fafbfd;}

    /* ── BADGES ── */
    .badge{font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;display:inline-block;}
    .badge-green{background:var(--green-bg);color:var(--green-text);}
    .badge-amber{background:var(--amber-bg);color:var(--amber-text);}
    .badge-blue{background:var(--blue-bg);color:var(--blue-text);}
    .badge-red{background:var(--red-bg);color:var(--red-text);}
    .badge-gray{background:#f1f5f9;color:#475569;}
    .badge-teal{background:var(--teal-bg);color:var(--teal-text);}
    .badge-silver{background:var(--silver-bg);color:var(--silver-text);}
    .badge-gold{background:var(--gold-bg);color:var(--gold-text);}

    /* ── FORMS ── */
    .form-group{margin-bottom:14px;}
    .form-label{display:block;font-size:12px;font-weight:500;color:var(--text-m);margin-bottom:5px;}
    .form-control{width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font);font-size:13px;color:var(--text);background:var(--white);outline:none;transition:border-color .15s;}
    .form-control:focus{border-color:var(--blue);}
    .form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

    /* ── BUTTONS ── */
    .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--radius);font-family:var(--font);font-size:13px;font-weight:500;cursor:pointer;border:none;transition:all .15s;text-decoration:none;}
    .btn-primary{background:var(--blue);color:var(--white);}
    .btn-primary:hover{background:#14478a;}
    .btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-m);}
    .btn-outline:hover{border-color:var(--blue);color:var(--blue);}
    .btn-sm{padding:5px 12px;font-size:12px;}

    /* ── ALERTS ── */
    .alert{padding:12px 16px;border-radius:var(--radius);font-size:13px;margin-bottom:16px;border-left:3px solid;}
    .alert-info{background:var(--blue-bg);border-color:var(--blue);color:var(--blue-text);}
    .alert-success{background:var(--green-bg);border-color:var(--green);color:var(--green-text);}
    .alert-warning{background:var(--amber-bg);border-color:var(--amber);color:var(--amber-text);}

    /* ── PROGRESS ── */
    .progress-track{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;}
    .progress-fill{height:100%;background:var(--blue);border-radius:3px;transition:width .4s;}

    /* ── SYSTEM STATUS ── */
    .sys-status{display:flex;align-items:center;gap:6px;font-size:10px;color:#3a5a82;margin-bottom:8px;}
    .status-dot{width:6px;height:6px;border-radius:50%;background:#22c55e;flex-shrink:0;}

    @media(max-width:900px){
      .stat-grid{grid-template-columns:1fr 1fr;}
      .two-col{grid-template-columns:1fr;}
    }
  </style>
</head>
<body>

<!-- ── SIDEBAR (canonical implementation — same scheme as the admin/client sidebars) ── -->
<?php
// dealer-sidebar.php reads $dealer['referral_code']; this header tracks $_dealer.
if (!isset($dealer)) { $dealer = ['referral_code' => $_dealer['dealer_code'] ?? '']; }
include __DIR__ . '/dealer-sidebar.php';
?>

<!-- ── MAIN starts; each page adds topbar + page-body ── -->
<div class="main">
