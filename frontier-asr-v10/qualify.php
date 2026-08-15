<?php
/**
 * qualify.php — Public-facing "Check Availability" page
 * Embeddable on fiber.bluemogul.biz via iframe or direct link.
 * Submits an ASR Pre-Order to Frontier TEST/PROD and shows result.
 */

declare(strict_types=1);

$pluginDir = __DIR__;
$dataDir   = $pluginDir . '/data';

require_once $pluginDir . '/src/Logger.php';
require_once $pluginDir . '/src/OrderManager.php';
require_once $pluginDir . '/src/FrontierASRClient.php';

$logger       = new Logger($dataDir . '/logs');
$orderManager = new OrderManager($dataDir);

$configFile = $dataDir . '/config.json';
$config     = file_exists($configFile)
    ? (json_decode(file_get_contents($configFile), true) ?: [])
    : [];
$config = array_merge(['environment' => 'TEST', 'ccna' => 'BMR', 'source_ip' => '149.28.124.240'], $config);

$result  = null;
$pon     = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $address = trim($_POST['address'] ?? '');
    $city    = trim($_POST['city']    ?? '');
    $state   = trim($_POST['state']   ?? '');
    $zip     = trim($_POST['zip']     ?? '');
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $phone   = trim($_POST['phone']   ?? '');

    if ($address && $city && $state && $zip) {
        $orderData = [
            'type'          => 'PRE-ORDER',
            'address_line1' => $address,
            'city'          => $city,
            'state'         => $state,
            'zip'           => $zip,
            'contact_name'  => $name  ?: 'Web Inquiry',
            'contact_email' => $email ?: '',
            'contact_phone' => $phone ?: '',
            'source'        => 'fiber.bluemogul.biz',
        ];

        $order  = $orderManager->create($orderData);
        $client = new FrontierASRClient($config, $logger);
        $res    = $client->sendPreOrder($order);

        $orderManager->updateStatus($order['pon'], $res['success'] ? 'CHECKING' : 'CHECK_FAILED');

        $pon    = $order['pon'];
        $result = $res['success'] ? 'success' : 'error';
        if (!$res['success']) {
            $error = 'Unable to check availability at this time. Please call us at (346) 309-5514.';
        }
    } else {
        $error = 'Please fill in all address fields.';
    }
}

// Allow embedding from fiber.bluemogul.biz
header('X-Frame-Options: ALLOW-FROM https://fiber.bluemogul.biz');
header('Content-Security-Policy: frame-ancestors https://fiber.bluemogul.biz https://uisp.bluemogul.us');

$isEmbed = isset($_GET['embed']); // ?embed=1 strips outer chrome for iframe use
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Check Fiber Availability — Blue Mogul</title>
<style>
  :root{--blue:#1565c0;--green:#2e7d32;--red:#c62828;--bm-orange:#f57c00}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',Arial,sans-serif;background:<?= $isEmbed ? 'transparent' : '#f0f4f8' ?>;color:#222;min-height:<?= $isEmbed ? 'auto' : '100vh' ?>}

  <?php if(!$isEmbed): ?>
  .page-wrap{min-height:100vh;display:flex;flex-direction:column}
  .hero{background:linear-gradient(135deg,#0d47a1 0%,#1976d2 60%,#0288d1 100%);color:#fff;padding:48px 24px;text-align:center}
  .hero h1{font-size:32px;font-weight:800;margin-bottom:8px}
  .hero h1 span{color:#ffcc02}
  .hero p{font-size:16px;opacity:.9;max-width:500px;margin:0 auto}
  <?php endif; ?>

  .form-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);padding:32px;max-width:520px;margin:<?= $isEmbed ? '0' : '-32px auto 40px' ?>;width:100%}
  <?php if($isEmbed): ?>.form-card{box-shadow:none;border-radius:0;padding:16px}<?php endif; ?>
  .form-card h2{font-size:20px;color:var(--blue);margin-bottom:6px}
  .form-card .sub{color:#666;font-size:13px;margin-bottom:20px}
  .section-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#999;font-weight:bold;margin:20px 0 8px}
  label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:4px;margin-top:12px}
  input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;transition:border-color .2s}
  input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(21,101,192,.1)}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px}
  .submit-btn{width:100%;margin-top:20px;padding:14px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;transition:background .2s}
  .submit-btn:hover{background:#0d47a1}
  .submit-btn:disabled{background:#90caf9;cursor:not-allowed}

  .result-box{border-radius:8px;padding:20px;text-align:center;margin-top:0}
  .result-success{background:#e8f5e9;border:1px solid #a5d6a7}
  .result-success h3{color:var(--green);font-size:20px;margin-bottom:8px}
  .result-error{background:#ffebee;border:1px solid #ef9a9a}
  .result-error h3{color:var(--red);font-size:18px;margin-bottom:8px}
  .result-box p{color:#555;font-size:14px;line-height:1.6}
  .result-box .ref{font-size:12px;color:#999;margin-top:12px}
  .check-another{margin-top:16px;padding:10px 20px;background:var(--blue);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:bold}

  .trust-row{display:flex;justify-content:center;gap:24px;margin:20px 0;flex-wrap:wrap}
  .trust-item{display:flex;align-items:center;gap:6px;font-size:13px;color:#555}
  .trust-item span{font-size:18px}

  <?php if(!$isEmbed): ?>
  footer{background:#1a237e;color:#fff;text-align:center;padding:20px;font-size:13px;margin-top:auto}
  footer a{color:#90caf9;text-decoration:none}
  <?php endif; ?>
</style>
</head>
<body>

<?php if(!$isEmbed): ?>
<div class="page-wrap">
<div class="hero">
  <h1>🔌 Check <span>Fiber</span> Availability</h1>
  <p>Find out if Blue Mogul high-speed fiber service is available at your address.</p>
</div>
<?php endif; ?>

<div style="<?= $isEmbed ? '' : 'display:flex;justify-content:center;padding:0 16px' ?>">
<div class="form-card">

<?php if($result === 'success'): ?>
  <div class="result-box result-success">
    <div style="font-size:48px">🎉</div>
    <h3>Great News!</h3>
    <p>We're checking fiber availability at your address with Frontier. A Blue Mogul representative will contact you within <strong>1 business day</strong> to confirm availability and discuss service options.</p>
    <p style="margin-top:12px">Questions? Call us at <strong>(346) 309-5514</strong></p>
    <div class="ref">Reference #: <?= htmlspecialchars($pon ?? '') ?></div>
  </div>
  <button class="check-another" onclick="location.href='?'">Check Another Address</button>

<?php elseif($result === 'error'): ?>
  <div class="result-box result-error">
    <div style="font-size:48px">😔</div>
    <h3>Something Went Wrong</h3>
    <p><?= htmlspecialchars($error ?? 'Please try again or contact us directly.') ?></p>
    <p style="margin-top:12px"><strong>(346) 309-5514</strong> | tracy.williams@bluemogul.biz</p>
  </div>
  <button class="check-another" onclick="location.href='?'">Try Again</button>

<?php else: ?>
  <h2>Check Service Availability</h2>
  <p class="sub">Enter your address to see if fiber service is available in your area.</p>

  <?php if(!empty($error)): ?>
    <div style="background:#ffebee;color:#c62828;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" id="qualForm">
    <div class="section-label">Service Address</div>
    <div>
      <label>Street Address *</label>
      <input type="text" name="address" placeholder="123 Main Street" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
    </div>
    <div class="row3" style="margin-top:0">
      <div><label>City *</label><input type="text" name="city" required value="<?= htmlspecialchars($_POST['city'] ?? '') ?>"></div>
      <div><label>State *</label><input type="text" name="state" placeholder="TX" maxlength="2" required value="<?= htmlspecialchars($_POST['state'] ?? 'TX') ?>"></div>
      <div><label>ZIP *</label><input type="text" name="zip" placeholder="77002" maxlength="5" required value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>"></div>
    </div>

    <div class="section-label" style="margin-top:20px">Your Contact Info <span style="font-weight:normal;text-transform:none;font-size:11px">(optional)</span></div>
    <div class="row2">
      <div><label>Name</label><input type="text" name="name" placeholder="Your name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"></div>
      <div><label>Phone</label><input type="tel" name="phone" placeholder="(346) 000-0000" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"></div>
    </div>
    <div>
      <label>Email</label>
      <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>

    <button type="submit" class="submit-btn" id="submitBtn">🔍 Check Availability</button>
  </form>

  <div class="trust-row">
    <div class="trust-item"><span>⚡</span> Fast Results</div>
    <div class="trust-item"><span>🔒</span> Secure</div>
    <div class="trust-item"><span>🇺🇸</span> Veteran-Owned</div>
    <div class="trust-item"><span>📍</span> Houston, TX</div>
  </div>
<?php endif; ?>

</div>
</div>

<?php if(!$isEmbed): ?>
<footer>
  <strong>Blue Mogul Enterprise LLC</strong> — Broadband | Voice | Web | Managed-IT | IT-Support<br>
  Houston, TX 77002 | <a href="tel:3463095514">(346) 309-5514</a> | <a href="mailto:tracy.williams@bluemogul.biz">tracy.williams@bluemogul.biz</a><br>
  <a href="https://www.bluemogul.biz">www.bluemogul.biz</a> · 100% Veteran-Owned
</footer>
</div>
<?php endif; ?>

<script>
document.getElementById('qualForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = '⏳ Checking...';
});
</script>
</body>
</html>
