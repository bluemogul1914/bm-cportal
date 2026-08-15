<?php
/**
 * main.php — UCRM Admin Plugin Page
 * Provides: Settings, New Order form, Orders dashboard, Log viewer
 */

declare(strict_types=1);

$pluginDir = __DIR__;
$dataDir   = $pluginDir . '/data';
$logDir    = $dataDir   . '/logs';

require_once $pluginDir . '/src/Logger.php';
require_once $pluginDir . '/src/OrderManager.php';
require_once $pluginDir . '/src/FrontierASRClient.php';

$logger       = new Logger($logDir);
$orderManager = new OrderManager($dataDir);

$configFile = $dataDir . '/config.json';
$config     = file_exists($configFile)
    ? (json_decode(file_get_contents($configFile), true) ?: [])
    : [];
$config = array_merge([
    'environment' => 'TEST',
    'ccna'        => 'BMR',
    'source_ip'   => '149.28.124.240',
    'contact_name' => 'Tracy Williams',
    'contact_phone'=> '3463095514',
    'contact_email'=> 'tracy.williams@bluemogul.biz',
], $config);

$message = '';
$tab     = $_GET['tab'] ?? 'dashboard';

// ── Handle Settings Save ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_settings') {
    $config['environment']   = $_POST['environment']    ?? 'TEST';
    $config['ccna']          = strtoupper(trim($_POST['ccna'] ?? 'BMR'));
    $config['source_ip']     = trim($_POST['source_ip'] ?? '');
    $config['contact_name']  = trim($_POST['contact_name']  ?? '');
    $config['contact_phone'] = trim($_POST['contact_phone'] ?? '');
    $config['contact_email'] = trim($_POST['contact_email'] ?? '');

    if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    $message = 'Settings saved.';
    $tab = 'settings';
}

$orders = $orderManager->recent(100);
$logs   = $logger->tail(100);

// Derive public base URL
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'uisp.bluemogul.us';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$publicUrl = "{$scheme}://{$host}{$scriptDir}/public.php";

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Frontier ASR UOM Ordering</title>
<style>
  :root {
    --blue:#1565c0; --blue-light:#e3f0fb; --green:#2e7d32; --red:#c62828;
    --orange:#e65100; --gray:#f5f5f5; --border:#ddd; --text:#222;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 14px; color: var(--text); background: #fafafa; }
  header { background: var(--blue); color: #fff; padding: 14px 24px; display: flex; align-items: center; gap: 12px; }
  header h1 { font-size: 20px; }
  header .env-badge { background: #fff3; border-radius: 4px; padding: 2px 10px; font-size: 12px; font-weight: bold; }
  nav { display: flex; gap: 0; border-bottom: 2px solid var(--border); background: #fff; padding: 0 24px; }
  nav a { display: inline-block; padding: 10px 18px; text-decoration: none; color: #555; border-bottom: 3px solid transparent; margin-bottom: -2px; font-weight: 500; }
  nav a.active { color: var(--blue); border-color: var(--blue); }
  .container { max-width: 1100px; margin: 24px auto; padding: 0 24px; }
  .card { background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 20px; margin-bottom: 20px; }
  .card h2 { font-size: 16px; margin-bottom: 16px; color: var(--blue); border-bottom: 1px solid var(--border); padding-bottom: 8px; }
  .msg { background: #e8f5e9; border: 1px solid #a5d6a7; color: var(--green); padding: 10px 16px; border-radius: 4px; margin-bottom: 16px; }
  label { display: block; font-weight: bold; margin-bottom: 4px; margin-top: 12px; }
  input[type=text], input[type=email], select { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
  button, .btn { display: inline-block; padding: 9px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; }
  .btn-primary { background: var(--blue); color: #fff; }
  .btn-success { background: var(--green); color: #fff; }
  .btn-sm { padding: 4px 10px; font-size: 12px; }
  .btn-primary:hover { background: #0d47a1; }
  .mt { margin-top: 16px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: var(--blue-light); text-align: left; padding: 8px 12px; font-size: 13px; border-bottom: 2px solid var(--border); }
  td { padding: 8px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
  tr:hover td { background: #fafafa; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
  .badge-submitted { background: #e3f0fb; color: var(--blue); }
  .badge-completed { background: #e8f5e9; color: var(--green); }
  .badge-error     { background: #ffebee; color: var(--red); }
  .badge-unknown   { background: #f5f5f5; color: #666; }
  .endpoint-box { background: #1e1e1e; color: #80cbc4; padding: 12px 16px; border-radius: 4px; font-family: monospace; font-size: 13px; word-break: break-all; }
  .log-box { background: #1e1e1e; color: #ccc; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; }
  .log-box div { padding: 1px 0; }
  .log-ERROR { color: #ef9a9a; }
  .log-INFO  { color: #a5d6a7; }
  .log-DEBUG { color: #90caf9; }
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
  .stat { background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 16px; text-align: center; }
  .stat .num { font-size: 28px; font-weight: bold; color: var(--blue); }
  .stat .lbl { font-size: 12px; color: #666; margin-top: 4px; }
</style>
</head>
<body>

<header>
  <h1>🔌 Frontier ASR UOM Ordering</h1>
  <span class="env-badge"><?= htmlspecialchars($config['environment']) ?> ENVIRONMENT</span>
</header>

<nav>
  <a href="?tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>">Dashboard</a>
  <a href="?tab=new_order" class="<?= $tab==='new_order'?'active':'' ?>">New Order</a>
  <a href="?tab=preorder"  class="<?= $tab==='preorder' ?'active':'' ?>">Pre-Order Check</a>
  <a href="?tab=settings"  class="<?= $tab==='settings' ?'active':'' ?>">Settings</a>
  <a href="?tab=logs"      class="<?= $tab==='logs'     ?'active':'' ?>">Logs</a>
</nav>

<div class="container">

<?php if ($message): ?>
  <div class="msg"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php /* ── DASHBOARD ─────────────────────────────────────────────────────── */ ?>
<?php if ($tab === 'dashboard'): ?>

  <?php
    $total     = count($orders);
    $completed = count(array_filter($orders, fn($o) => ($o['status']??'') === 'COMPLETED'));
    $errors    = count(array_filter($orders, fn($o) => str_contains(strtolower($o['status']??''), 'error') || str_contains(strtolower($o['status']??''), 'fail')));
    $pending   = $total - $completed - $errors;
  ?>
  <div class="stats">
    <div class="stat"><div class="num"><?= $total ?></div><div class="lbl">Total Orders</div></div>
    <div class="stat"><div class="num" style="color:var(--green)"><?= $completed ?></div><div class="lbl">Completed</div></div>
    <div class="stat"><div class="num" style="color:var(--orange)"><?= $pending ?></div><div class="lbl">In Progress</div></div>
    <div class="stat"><div class="num" style="color:var(--red)"><?= $errors ?></div><div class="lbl">Errors</div></div>
  </div>

  <div class="card">
    <h2>Recent Orders</h2>
    <?php if (empty($orders)): ?>
      <p style="color:#888">No orders yet. <a href="?tab=new_order">Create your first order →</a></p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>PON</th><th>Type</th><th>Address</th><th>Status</th><th>Circuit ID</th><th>Created</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td><strong><?= htmlspecialchars($o['pon'] ?? '') ?></strong></td>
          <td><?= htmlspecialchars($o['type'] ?? 'ORDER') ?></td>
          <td><?= htmlspecialchars(trim(($o['address_line1']??'') . ', ' . ($o['city']??'') . ', ' . ($o['state']??''), ', ')) ?></td>
          <td><?php
            $s = $o['status'] ?? 'UNKNOWN';
            $cls = match(true) {
              $s === 'COMPLETED'  => 'completed',
              str_contains(strtolower($s), 'error') || str_contains(strtolower($s), 'fail') => 'error',
              str_contains(strtolower($s), 'submit') => 'submitted',
              default => 'unknown'
            };
            echo "<span class=\"badge badge-{$cls}\">" . htmlspecialchars($s) . "</span>";
          ?></td>
          <td><?= htmlspecialchars($o['circuit_id'] ?? '—') ?></td>
          <td><?= htmlspecialchars(substr($o['created_at'] ?? '', 0, 16)) ?></td>
          <td><a href="?tab=dashboard&view=<?= urlencode($o['pon']??'') ?>" class="btn btn-primary btn-sm">View</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php /* Order detail view */ ?>
  <?php if (!empty($_GET['view'])): ?>
    <?php $detail = $orderManager->find($_GET['view']); ?>
    <?php if ($detail): ?>
    <div class="card">
      <h2>Order Detail — <?= htmlspecialchars($detail['pon']) ?></h2>
      <pre style="background:#f5f5f5;padding:12px;border-radius:4px;overflow:auto;font-size:12px"><?= htmlspecialchars(json_encode($detail, JSON_PRETTY_PRINT)) ?></pre>
    </div>
    <?php endif; ?>
  <?php endif; ?>

<?php /* ── NEW ORDER ─────────────────────────────────────────────────────── */ ?>
<?php elseif ($tab === 'new_order'): ?>
  <div class="card">
    <h2>Submit New ASR Order</h2>
    <form id="orderForm">
      <div class="form-row">
        <div>
          <label>Activity Code</label>
          <select name="activity_code">
            <option value="N">N — New Install</option>
            <option value="C">C — Change</option>
            <option value="D">D — Disconnect</option>
          </select>
        </div>
        <div>
          <label>Account Number (AN)</label>
          <input type="text" name="account_number" placeholder="Frontier account number">
        </div>
      </div>
      <div class="form-row">
        <div>
          <label>Desired Due Date</label>
          <input type="text" name="desired_due_date" placeholder="YYYY-MM-DD">
        </div>
        <div>
          <label>Purchase Order Number (PON) <span style="font-weight:normal;color:#888">(auto-generated if blank)</span></label>
          <input type="text" name="pon" placeholder="Leave blank to auto-generate">
        </div>
      </div>

      <h3 style="margin-top:20px;margin-bottom:4px;color:#555">Service Address</h3>
      <div>
        <label>Address Line 1</label>
        <input type="text" name="address_line1" placeholder="123 Main St">
      </div>
      <div class="form-row-3" style="margin-top:12px">
        <div>
          <label>City</label>
          <input type="text" name="city">
        </div>
        <div>
          <label>State</label>
          <input type="text" name="state" placeholder="TX" maxlength="2">
        </div>
        <div>
          <label>ZIP</label>
          <input type="text" name="zip">
        </div>
      </div>

      <h3 style="margin-top:20px;margin-bottom:4px;color:#555">Contact</h3>
      <div class="form-row-3">
        <div>
          <label>Name</label>
          <input type="text" name="contact_name" value="<?= htmlspecialchars($config['contact_name']) ?>">
        </div>
        <div>
          <label>Phone</label>
          <input type="text" name="contact_phone" value="<?= htmlspecialchars($config['contact_phone']) ?>">
        </div>
        <div>
          <label>Email</label>
          <input type="text" name="contact_email" value="<?= htmlspecialchars($config['contact_email']) ?>">
        </div>
      </div>

      <div class="mt">
        <button type="submit" class="btn btn-success">🚀 Send Order to Frontier (<?= htmlspecialchars($config['environment']) ?>)</button>
      </div>
      <div id="orderResult" style="margin-top:16px"></div>
    </form>
  </div>

  <script>
  document.getElementById('orderForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const result = document.getElementById('orderResult');
    result.innerHTML = '<em>Sending to Frontier...</em>';
    const data = Object.fromEntries(new FormData(this));
    data.type = 'ORDER';
    try {
      const r = await fetch('public.php?action=send', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
      });
      const j = await r.json();
      if (j.success) {
        result.innerHTML = `<div class="msg">✅ Order submitted! PON: <strong>${j.pon}</strong> — Status: ${j.status}</div>`;
      } else {
        result.innerHTML = `<div style="background:#ffebee;padding:10px;border-radius:4px;color:#c62828">❌ Send failed (HTTP ${j.http_code}). Check Logs tab.</div>`;
      }
    } catch(err) {
      result.innerHTML = `<div style="color:red">Error: ${err.message}</div>`;
    }
  });
  </script>

<?php /* ── PRE-ORDER ─────────────────────────────────────────────────────── */ ?>
<?php elseif ($tab === 'preorder'): ?>
  <div class="card">
    <h2>ASR Pre-Order (Availability Check)</h2>
    <p style="color:#666;margin-bottom:16px">Check service availability at an address before submitting a full order.</p>
    <form id="preorderForm">
      <div>
        <label>Address Line 1</label>
        <input type="text" name="address_line1" placeholder="123 Main St">
      </div>
      <div class="form-row" style="margin-top:12px">
        <div><label>City</label><input type="text" name="city"></div>
        <div><label>State</label><input type="text" name="state" placeholder="TX" maxlength="2"></div>
      </div>
      <div class="form-row" style="margin-top:12px">
        <div><label>ZIP</label><input type="text" name="zip"></div>
        <div><label>PON (optional)</label><input type="text" name="pon" placeholder="Auto-generated"></div>
      </div>
      <div class="mt">
        <button type="submit" class="btn btn-primary">🔍 Check Availability</button>
      </div>
      <div id="preResult" style="margin-top:16px"></div>
    </form>
  </div>

  <script>
  document.getElementById('preorderForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const result = document.getElementById('preResult');
    result.innerHTML = '<em>Checking availability...</em>';
    const data = Object.fromEntries(new FormData(this));
    data.type = 'PRE-ORDER';
    try {
      const r = await fetch('public.php?action=send', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
      });
      const j = await r.json();
      result.innerHTML = j.success
        ? `<div class="msg">✅ Pre-order sent! PON: <strong>${j.pon}</strong></div>`
        : `<div style="background:#ffebee;padding:10px;border-radius:4px;color:#c62828">❌ Failed (HTTP ${j.http_code}). Check Logs.</div>`;
    } catch(err) {
      result.innerHTML = `<div style="color:red">Error: ${err.message}</div>`;
    }
  });
  </script>

<?php /* ── SETTINGS ──────────────────────────────────────────────────────── */ ?>
<?php elseif ($tab === 'settings'): ?>
  <div class="card">
    <h2>Plugin Settings</h2>
    <form method="post">
      <input type="hidden" name="_action" value="save_settings">
      <div class="form-row">
        <div>
          <label>Environment</label>
          <select name="environment">
            <option value="TEST"       <?= $config['environment']==='TEST'       ?'selected':'' ?>>TEST (Sandbox)</option>
            <option value="PRODUCTION" <?= $config['environment']==='PRODUCTION' ?'selected':'' ?>>PRODUCTION</option>
          </select>
        </div>
        <div>
          <label>CCNA</label>
          <input type="text" name="ccna" value="<?= htmlspecialchars($config['ccna']) ?>" placeholder="BMR">
        </div>
      </div>
      <div>
        <label>Source IP (your server's public IP)</label>
        <input type="text" name="source_ip" value="<?= htmlspecialchars($config['source_ip']) ?>">
      </div>
      <h3 style="margin-top:20px;margin-bottom:4px;color:#555">Default Contact</h3>
      <div class="form-row-3">
        <div><label>Name</label><input type="text" name="contact_name" value="<?= htmlspecialchars($config['contact_name']) ?>"></div>
        <div><label>Phone</label><input type="text" name="contact_phone" value="<?= htmlspecialchars($config['contact_phone']) ?>"></div>
        <div><label>Email</label><input type="text" name="contact_email" value="<?= htmlspecialchars($config['contact_email']) ?>"></div>
      </div>
      <div class="mt">
        <button type="submit" class="btn btn-primary">💾 Save Settings</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Your CLEC Endpoint URLs</h2>
    <p style="margin-bottom:10px;color:#555">Provide these to Frontier Connectivity Management (Barb) for the ASR form:</p>
    <label>ORDER Endpoint (TEST & PRODUCTION)</label>
    <div class="endpoint-box"><?= htmlspecialchars($publicUrl) ?>?action=receive</div>
    <label style="margin-top:12px">PRE-ORDER Endpoint</label>
    <div class="endpoint-box"><?= htmlspecialchars($publicUrl) ?>?action=preorder</div>
    <label style="margin-top:12px">Certificate Common Name</label>
    <div class="endpoint-box"><?= htmlspecialchars($host) ?></div>
  </div>

<?php /* ── LOGS ──────────────────────────────────────────────────────────── */ ?>
<?php elseif ($tab === 'logs'): ?>
  <div class="card">
    <h2>Plugin Logs (last 100 entries, newest first)</h2>
    <?php if (empty($logs)): ?>
      <p style="color:#888">No log entries yet.</p>
    <?php else: ?>
    <div class="log-box">
      <?php foreach ($logs as $line):
        $cls = str_contains($line, '[ERROR]') ? 'log-ERROR' : (str_contains($line, '[DEBUG]') ? 'log-DEBUG' : 'log-INFO');
      ?>
        <div class="<?= $cls ?>"><?= htmlspecialchars($line) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

<?php endif; ?>
</div>
</body>
</html>
