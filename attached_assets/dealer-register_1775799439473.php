<?php
/**
 * dealer-register.php
 * Public dealer signup page — no session required
 * On success: status = 'pending', admin approves via admin panel
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// Redirect if already logged in as dealer
if (!empty($_SESSION['role']) && $_SESSION['role'] === 'dealer') {
    header('Location: /portal/dealer-dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/dealer-header.php'; // for get_db() + dollars()

$success = $error = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $company   = trim($_POST['company']   ?? '');
    $markets   = trim($_POST['markets']   ?? '');

    if (!$full_name || !$email) {
        $error = 'Full name and email address are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $pdo = get_db();
            $dealer_code = 'DLR-' . str_pad(random_int(10000,99999),5,'0',STR_PAD_LEFT);
            $ins = $pdo->prepare(
                "INSERT INTO dealers (dealer_code, full_name, email, phone, company, notes)
                 VALUES (?,?,?,?,?,?)"
            );
            $ins->execute([
                $dealer_code, $full_name, $email,
                $phone ?: null, $company ?: null,
                $markets ? "Markets: $markets" : null
            ]);
            $submitted = true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                $error = 'That email address is already registered. <a href="/portal/login.php" style="color:var(--blue);">Sign in here</a>.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dealer / ISO Registration — Blue Mogul</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *{margin:0;padding:0;box-sizing:border-box;}
    :root{
      --navy:#0a1628;--blue:#1a56a0;--blue-lt:#4a9eff;
      --border:#e2e8f0;--bg:#f4f6f9;--text:#1a202c;
      --text-m:#4a5568;--text-lt:#718096;
      --green:#15893e;--green-bg:#e6f4ec;--green-text:#14532d;
      --red:#c53030;--red-bg:#fff5f5;--red-text:#742a2a;
      --amber-bg:#fef3e2;--amber-text:#7a4f0d;--amber:#b45309;
      --font:'DM Sans',system-ui,sans-serif;
      --radius:8px;--radius-lg:12px;
    }
    body{font-family:var(--font);background:var(--bg);min-height:100vh;display:flex;flex-direction:column;}

    .top-bar{background:var(--navy);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;}
    .wordmark{font-size:15px;font-weight:600;letter-spacing:.08em;color:var(--blue-lt);}
    .top-link{font-size:12px;color:#4a7ab5;text-decoration:none;}
    .top-link:hover{color:var(--blue-lt);}

    .page{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px;}
    .wrap{width:100%;max-width:520px;}

    .hero{text-align:center;margin-bottom:28px;}
    .hero h1{font-size:22px;font-weight:600;color:var(--text);margin-bottom:8px;}
    .hero p{font-size:14px;color:var(--text-m);line-height:1.6;}

    .card{background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:28px 32px;margin-bottom:16px;}

    .form-group{margin-bottom:14px;}
    .form-label{display:block;font-size:12px;font-weight:500;color:var(--text-m);margin-bottom:5px;}
    .form-control{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font);font-size:13px;color:var(--text);outline:none;transition:border-color .15s;}
    .form-control:focus{border-color:var(--blue);}
    .form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

    .btn-primary{display:block;width:100%;padding:11px;background:var(--blue);color:#fff;border:none;border-radius:var(--radius);font-family:var(--font);font-size:14px;font-weight:600;cursor:pointer;text-align:center;transition:background .15s;}
    .btn-primary:hover{background:#164888;}

    .alert{padding:12px 16px;border-radius:var(--radius);font-size:13px;margin-bottom:16px;border-left:3px solid;}
    .alert-error{background:var(--red-bg);border-color:var(--red);color:var(--red-text);}
    .alert-success{background:var(--green-bg);border-color:var(--green);color:var(--green-text);}

    .perks{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;}
    .perk{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;}
    .perk-label{font-size:12px;font-weight:600;color:var(--text);margin-bottom:2px;}
    .perk-sub{font-size:11px;color:var(--text-lt);}

    .terms{font-size:11px;color:var(--text-lt);text-align:center;margin-top:12px;line-height:1.6;}

    .success-card{text-align:center;padding:40px 32px;}
    .success-ico{width:56px;height:56px;background:var(--green-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
    .success-card h2{font-size:18px;font-weight:600;color:var(--text);margin-bottom:8px;}
    .success-card p{font-size:14px;color:var(--text-m);line-height:1.6;}

    @media(max-width:540px){
      .card{padding:20px;}
      .form-grid-2,.perks{grid-template-columns:1fr;}
    }
  </style>
</head>
<body>

<div class="top-bar">
  <span class="wordmark">BLUEMOGUL</span>
  <a href="/portal/login.php" class="top-link">Already registered? Sign in →</a>
</div>

<div class="page">
  <div class="wrap">

    <?php if ($submitted): ?>

    <!-- SUCCESS STATE -->
    <div class="card success-card">
      <div class="success-ico">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#15893e" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h2>Application received!</h2>
      <p>
        Thanks for applying to the Blue Mogul dealer program.<br>
        We'll review your application and email you at <strong><?= htmlspecialchars($_POST['email'] ?? '') ?></strong>
        within 1 business day.
      </p>
      <p style="margin-top:12px;font-size:13px;color:var(--text-lt);">
        Questions? Email <a href="mailto:support@bluemogul.biz" style="color:var(--blue);">support@bluemogul.biz</a>
        or call (346) 309-5514.
      </p>
    </div>

    <?php else: ?>

    <div class="hero">
      <h1>Join the Blue Mogul Dealer Program</h1>
      <p>Earn spiffs on every activation — fiber, wireless, internet, and TV bundles.<br>No inventory. No contracts. 100% prepaid.</p>
    </div>

    <!-- PERKS -->
    <div class="perks">
      <div class="perk">
        <div class="perk-label">Up to $150 per activation</div>
        <div class="perk-sub">Fiber, wireless, Xfinity & more</div>
      </div>
      <div class="perk">
        <div class="perk-label">Weekly ACH payouts</div>
        <div class="perk-sub">Every Friday — no waiting</div>
      </div>
      <div class="perk">
        <div class="perk-label">Gold tier bonus</div>
        <div class="perk-sub">+20% spiff at 10 activations/mo</div>
      </div>
      <div class="perk">
        <div class="perk-label">TX · LA · NC markets</div>
        <div class="perk-sub">+ nationwide wireless & streaming</div>
      </div>
    </div>

    <!-- REGISTRATION FORM -->
    <div class="card">

      <?php if ($error): ?>
      <div class="alert alert-error"><?= $error ?></div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Full name <span style="color:var(--red);">*</span></label>
            <input type="text" name="full_name" class="form-control" required
                   placeholder="First Last"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Email address <span style="color:var(--red);">*</span></label>
            <input type="email" name="email" class="form-control" required
                   placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="tel" name="phone" class="form-control"
                   placeholder="(713) 000-0000"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Company / DBA</label>
            <input type="text" name="company" class="form-control"
                   placeholder="Your business name"
                   value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Target markets / service areas</label>
          <input type="text" name="markets" class="form-control"
                 placeholder="e.g. Houston TX, Baton Rouge LA, Charlotte NC"
                 value="<?= htmlspecialchars($_POST['markets'] ?? '') ?>">
        </div>

        <button type="submit" class="btn-primary">Apply to become a dealer →</button>
      </form>

      <p class="terms">
        By applying you agree to Blue Mogul's dealer terms of service.
        Applications are reviewed within 1 business day.
      </p>
    </div>

    <?php endif; ?>
  </div>
</div>

</body>
</html>
