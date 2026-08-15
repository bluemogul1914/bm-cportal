<?php
/**
 * widget.php — Rendered inside UCRM client detail page as a widget panel.
 * Receives client data via GET params from UCRM and pre-fills the ASR order form.
 */

declare(strict_types=1);

$pluginDir = __DIR__;
$dataDir   = $pluginDir . '/data';

require_once $pluginDir . '/src/Logger.php';
require_once $pluginDir . '/src/OrderManager.php';

$logger       = new Logger($dataDir . '/logs');
$orderManager = new OrderManager($dataDir);

$configFile = $dataDir . '/config.json';
$config     = file_exists($configFile)
    ? (json_decode(file_get_contents($configFile), true) ?: [])
    : [];
$config = array_merge(['environment' => 'TEST', 'ccna' => 'BMR'], $config);

// UCRM passes client context via GET
$clientId    = (int) ($_GET['clientId']    ?? 0);
$clientName  = htmlspecialchars($_GET['clientName']  ?? '');
$address     = htmlspecialchars($_GET['street1']      ?? '');
$city        = htmlspecialchars($_GET['city']         ?? '');
$state       = htmlspecialchars($_GET['state']        ?? '');
$zip         = htmlspecialchars($_GET['zip']          ?? '');
$accountNum  = htmlspecialchars($_GET['accountNumber']?? '');

// Build the public.php base URL
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'uisp.bluemogul.us';
$baseUrl = "{$scheme}://{$host}/crm/_plugins/frontier-asr/public.php";

// Recent orders for this client
$allOrders     = $orderManager->recent(100);
$clientOrders  = array_filter($allOrders, fn($o) => ($o['client_id'] ?? 0) == $clientId);
$clientOrders  = array_values($clientOrders);
?>
<style>
  .fasr-widget{font-family:Arial,sans-serif;font-size:13px;color:#222}
  .fasr-widget h3{font-size:14px;font-weight:bold;color:#1565c0;margin-bottom:12px;display:flex;align-items:center;gap:8px}
  .fasr-widget .env{background:#e3f0fb;color:#1565c0;font-size:10px;padding:1px 7px;border-radius:10px;font-weight:bold}
  .fasr-widget label{display:block;font-weight:bold;font-size:12px;margin-bottom:3px;margin-top:10px;color:#444}
  .fasr-widget input,.fasr-widget select{width:100%;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px}
  .fasr-widget .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .fasr-widget .row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
  .fasr-widget .btns{display:flex;gap:8px;margin-top:14px}
  .fasr-widget button{padding:7px 16px;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:bold}
  .btn-order{background:#2e7d32;color:#fff}.btn-order:hover{background:#1b5e20}
  .btn-check{background:#1565c0;color:#fff}.btn-check:hover{background:#0d47a1}
  .fasr-widget .result{margin-top:10px;padding:8px 12px;border-radius:4px;font-size:12px}
  .fasr-widget .ok{background:#e8f5e9;color:#2e7d32}
  .fasr-widget .err{background:#ffebee;color:#c62828}
  .fasr-widget .orders-list{margin-top:14px;border-top:1px solid #eee;padding-top:12px}
  .fasr-widget .orders-list h4{font-size:12px;color:#888;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
  .fasr-widget table{width:100%;border-collapse:collapse;font-size:12px}
  .fasr-widget th{background:#f5f5f5;padding:5px 8px;text-align:left;border-bottom:1px solid #eee}
  .fasr-widget td{padding:5px 8px;border-bottom:1px solid #f5f5f5}
  .pill{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:bold}
  .p-sub{background:#e3f0fb;color:#1565c0}.p-ok{background:#e8f5e9;color:#2e7d32}
  .p-err{background:#ffebee;color:#c62828}.p-uk{background:#f5f5f5;color:#666}
  .fasr-widget .sep{font-size:11px;color:#aaa;margin:10px 0 4px;text-transform:uppercase;letter-spacing:.5px}
</style>

<div class="fasr-widget">
  <h3>🔌 Frontier ASR Order <span class="env"><?= htmlspecialchars($config['environment']) ?></span></h3>

  <?php if($address): ?>
  <!-- Quick Pre-Order Check -->
  <div style="background:#f0f7ff;border:1px solid #bbdefb;border-radius:4px;padding:10px;margin-bottom:12px">
    <strong style="font-size:12px">📍 Service Address:</strong>
    <span style="color:#444;font-size:12px"><?= $address ?>, <?= $city ?>, <?= $state ?> <?= $zip ?></span>
    <br>
    <button class="btn-check" style="margin-top:8px;padding:5px 12px;font-size:12px" onclick="quickPreOrder()">🔍 Check Availability</button>
    <span id="quickResult" style="font-size:12px;margin-left:8px"></span>
  </div>
  <?php endif; ?>

  <!-- Order Form -->
  <div class="sep">New ASR Order</div>
  <div class="row2">
    <div>
      <label>Activity</label>
      <select id="w_activity">
        <option value="N">N — New Install</option>
        <option value="C">C — Change</option>
        <option value="D">D — Disconnect</option>
      </select>
    </div>
    <div>
      <label>Account Number</label>
      <input type="text" id="w_an" value="<?= $accountNum ?>" placeholder="Frontier AN">
    </div>
  </div>
  <div>
    <label>Address</label>
    <input type="text" id="w_addr" value="<?= $address ?>" placeholder="123 Main St">
  </div>
  <div class="row3" style="margin-top:8px">
    <div><label>City</label><input type="text" id="w_city" value="<?= $city ?>"></div>
    <div><label>State</label><input type="text" id="w_state" value="<?= $state ?>" maxlength="2"></div>
    <div><label>ZIP</label><input type="text" id="w_zip" value="<?= $zip ?>"></div>
  </div>
  <div>
    <label>Desired Due Date</label>
    <input type="text" id="w_ddd" placeholder="YYYY-MM-DD">
  </div>

  <div class="btns">
    <button class="btn-check" onclick="submitPreOrder()">🔍 Pre-Order Check</button>
    <button class="btn-order" onclick="submitOrder()">🚀 Submit Order</button>
  </div>
  <div id="w_result"></div>

  <?php if(!empty($clientOrders)): ?>
  <div class="orders-list">
    <h4>Orders for this client</h4>
    <table>
      <thead><tr><th>PON</th><th>Type</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach(array_slice($clientOrders,0,5) as $o):
        $s=$o['status']??'UNKNOWN';
        $cls=match(true){$s==='COMPLETED'=>'p-ok',str_contains(strtolower($s),'error')=>'p-err',str_contains(strtolower($s),'submit')=>'p-sub',default=>'p-uk'}; ?>
        <tr>
          <td><?= htmlspecialchars($o['pon']??'') ?></td>
          <td><?= htmlspecialchars($o['type']??'') ?></td>
          <td><span class="pill <?= $cls ?>"><?= htmlspecialchars($s) ?></span></td>
          <td><?= htmlspecialchars(substr($o['created_at']??'',0,10)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
const BASE = '<?= $baseUrl ?>';
const CLIENT_ID = <?= $clientId ?>;

function getOrderData(type) {
  return {
    type,
    client_id:      CLIENT_ID,
    activity_code:  document.getElementById('w_activity').value,
    account_number: document.getElementById('w_an').value,
    address_line1:  document.getElementById('w_addr').value,
    city:           document.getElementById('w_city').value,
    state:          document.getElementById('w_state').value,
    zip:            document.getElementById('w_zip').value,
    desired_due_date: document.getElementById('w_ddd').value,
    contact_name:   '<?= htmlspecialchars($config['contact_name'] ?? 'Tracy Williams') ?>',
    contact_phone:  '<?= htmlspecialchars($config['contact_phone'] ?? '3463095514') ?>',
    contact_email:  '<?= htmlspecialchars($config['contact_email'] ?? 'tracy.williams@bluemogul.biz') ?>',
  };
}

async function submitOrder() {
  const r = document.getElementById('w_result');
  r.innerHTML = '<em style="color:#888">Sending order to Frontier...</em>';
  try {
    const res = await fetch(BASE + '?action=send', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(getOrderData('ORDER'))
    });
    const j = await res.json();
    r.innerHTML = j.success
      ? `<div class="result ok">✅ Order submitted! PON: <strong>${j.pon}</strong></div>`
      : `<div class="result err">❌ Failed (HTTP ${j.http_code})</div>`;
  } catch(e) { r.innerHTML = `<div class="result err">Error: ${e.message}</div>`; }
}

async function submitPreOrder() {
  const r = document.getElementById('w_result');
  r.innerHTML = '<em style="color:#888">Checking availability...</em>';
  try {
    const res = await fetch(BASE + '?action=send', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(getOrderData('PRE-ORDER'))
    });
    const j = await res.json();
    r.innerHTML = j.success
      ? `<div class="result ok">✅ Pre-order sent! PON: <strong>${j.pon}</strong></div>`
      : `<div class="result err">❌ Failed (HTTP ${j.http_code})</div>`;
  } catch(e) { r.innerHTML = `<div class="result err">Error: ${e.message}</div>`; }
}

async function quickPreOrder() {
  const r = document.getElementById('quickResult');
  r.textContent = 'Checking...';
  try {
    const data = {
      type: 'PRE-ORDER', client_id: CLIENT_ID,
      address_line1: '<?= addslashes($address) ?>',
      city: '<?= addslashes($city) ?>', state: '<?= addslashes($state) ?>', zip: '<?= addslashes($zip) ?>'
    };
    const res = await fetch(BASE + '?action=send', {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data)
    });
    const j = await res.json();
    r.innerHTML = j.success ? '✅ Request sent to Frontier!' : '❌ Failed';
    r.style.color = j.success ? '#2e7d32' : '#c62828';
  } catch(e) { r.textContent = 'Error: ' + e.message; r.style.color='#c62828'; }
}
</script>
