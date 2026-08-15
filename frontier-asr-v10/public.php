<?php
/**
 * public.php — UCRM Public Plugin Entry Point
 *
 * Browser (GET, no action or UI tab):  renders full ordering dashboard
 * Frontier SOAP callbacks (POST):      ?action=receive | ?action=frontier_preorder
 * Internal JSON API (POST):            ?action=send
 * Internal JSON API (GET):             ?action=status&pon= | ?action=orders
 */

declare(strict_types=1);

$pluginDir = __DIR__;
$dataDir   = $pluginDir . '/data';
$logDir    = $dataDir   . '/logs';

require_once $pluginDir . '/src/Logger.php';
require_once $pluginDir . '/src/OrderManager.php';
require_once $pluginDir . '/src/FrontierASRClient.php';
require_once $pluginDir . '/src/FrontierASRReceiver.php';

$logger       = new Logger($logDir);
$orderManager = new OrderManager($dataDir);

$configFile = $dataDir . '/config.json';
$config     = file_exists($configFile)
    ? (json_decode(file_get_contents($configFile), true) ?: [])
    : [];
$config = array_merge([
    'environment'   => 'TEST',
    'ccna'          => 'BMR',
    'source_ip'     => '149.28.124.240',
    'contact_name'  => 'Tracy Williams',
    'contact_phone' => '3463095514',
    'contact_email' => 'tracy.williams@bluemogul.biz',
], $config);

// ── Router ───────────────────────────────────────────────────────────────────

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? 'dashboard';

// Public qualify / check-availability page (fiber.bluemogul.biz)
if ($action === 'qualify') {
    handleQualify($method, $config, $dataDir, $orderManager, $logger);
    exit;
}

// UI tabs served as HTML
$uiTabs = ['dashboard', 'new_order', 'preorder', 'settings', 'logs', 'save_settings'];
if (in_array($action, $uiTabs)) {
    handleUI($action, $method, $config, $configFile, $dataDir, $orderManager, $logger);
    exit;
}

// API / SOAP routes
header('Content-Type: text/xml; charset=UTF-8');

switch ($action) {

    case 'receive':
    case 'frontier_preorder':
        if ($method !== 'POST') { http_response_code(405); echo soapFault('Client', 'Method Not Allowed'); exit; }
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) { http_response_code(400); echo soapFault('Client', 'Empty body'); exit; }
        $receiver = new FrontierASRReceiver($logger, $orderManager, $config);
        echo $receiver->handle($rawBody, $action);
        break;

    case 'send':
        if ($method !== 'POST') { jsonResponse(['error' => 'POST required'], 405); exit; }
        $body      = json_decode(file_get_contents('php://input'), true) ?: [];
        $orderType = strtoupper($body['type'] ?? 'ORDER');
        $order     = $orderManager->create($body);
        $client    = new FrontierASRClient($config, $logger);
        $result    = ($orderType === 'PRE-ORDER') ? $client->sendPreOrder($order) : $client->sendOrder($order);
        $status    = $result['success'] ? 'SUBMITTED' : 'SEND_FAILED';
        $orderManager->updateStatus($order['pon'], $status);
        jsonResponse(['success' => $result['success'], 'pon' => $order['pon'], 'status' => $status, 'http_code' => $result['http_code'], 'parsed' => $result['parsed'] ?? []]);
        break;

    case 'status':
        $pon   = $_GET['pon'] ?? '';
        $order = $pon ? $orderManager->find($pon) : null;
        jsonResponse($order ?: ['error' => 'Not found'], $order ? 200 : 404);
        break;

    case 'orders':
        jsonResponse($orderManager->recent(100));
        break;

    case 'clients':
        // Proxy to UCRM REST API — returns client list for the search dropdown
        header('Content-Type: application/json');
        $apiKey = $config['ucrm_api_key'] ?? '';
        $q      = trim($_GET['q'] ?? '');
        if (!$apiKey) { echo json_encode(['error' => 'UCRM API key not configured in Settings']); exit; }
        $ucrmBase = 'https://uisp.bluemogul.us/crm/api/v1.0';
        $url = $ucrmBase . '/clients?limit=20' . ($q ? '&search=' . urlencode($q) : '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-Auth-App-Key: ' . $apiKey, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !$resp) { echo json_encode([]); exit; }
        $raw = json_decode($resp, true) ?: [];
        // Normalize — each client may have multiple addresses; grab primary
        $out = [];
        foreach ($raw as $c) {
            $addrs = $c['addresses'] ?? [];
            $primary = null;
            foreach ($addrs as $a) { if ($a['isPrimary'] ?? false) { $primary = $a; break; } }
            if (!$primary && !empty($addrs)) $primary = $addrs[0];
            $out[] = [
                'id'            => $c['id'],
                'name'          => trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')) ?: ($c['companyName'] ?? 'Unknown'),
                'email'         => $c['contacts'][0]['email'] ?? '',
                'phone'         => $c['contacts'][0]['phone'] ?? '',
                'accountNumber' => $c['userIdent'] ?? '',
                'street1'       => $primary['street1'] ?? '',
                'city'          => $primary['city'] ?? '',
                'state'         => $primary['stateId'] ?? 'TX',
                'zip'           => $primary['zip'] ?? '',
            ];
        }
        echo json_encode($out);
        exit;

    default:
        // fallback to dashboard
        handleUI('dashboard', $method, $config, $configFile, $dataDir, $orderManager, $logger);
}

// ── UI Handler ────────────────────────────────────────────────────────────────

function handleUI(string $action, string $method, array &$config, string $configFile, string $dataDir, OrderManager $orderManager, Logger $logger): void {
    $message = '';
    $tab     = $action;

    if ($method === 'POST' && $action === 'save_settings') {
        $config['environment']   = $_POST['environment']    ?? 'TEST';
        $config['ccna']          = strtoupper(trim($_POST['ccna'] ?? 'BMR'));
        $config['source_ip']     = trim($_POST['source_ip'] ?? '');
        $config['contact_name']  = trim($_POST['contact_name']  ?? '');
        $config['contact_phone'] = trim($_POST['contact_phone'] ?? '');
        $config['contact_email'] = trim($_POST['contact_email'] ?? '');
        if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        $message = 'Settings saved successfully.';
        $tab = 'settings';
    }

    $orders    = $orderManager->recent(100);
    $logs      = $logger->tail(100);
    $scheme    = 'https'; // Force HTTPS — UISP runs behind SSL termination proxy
    $host      = $_SERVER['HTTP_HOST'] ?? 'uisp.bluemogul.us';
    $scriptUri = strtok($_SERVER['REQUEST_URI'] ?? '/crm/_plugins/frontier-asr/public.php', '?');
    $base      = "{$scheme}://{$host}{$scriptUri}";

    $total     = count($orders);
    $completed = count(array_filter($orders, fn($o) => ($o['status'] ?? '') === 'COMPLETED'));
    $errors    = count(array_filter($orders, fn($o) => stripos($o['status'] ?? '', 'error') !== false || stripos($o['status'] ?? '', 'fail') !== false));
    $pending   = $total - $completed - $errors;

    header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Frontier ASR — Blue Mogul</title>
<style>
  :root{--blue:#1565c0;--blue-d:#0d47a1;--blue-l:#e3f0fb;--green:#2e7d32;--red:#c62828;--orange:#e65100;--border:#ddd;--text:#222}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,sans-serif;font-size:14px;color:var(--text);background:#f5f7fa}
  header{background:var(--blue);color:#fff;padding:14px 24px;display:flex;align-items:center;gap:12px}
  header h1{font-size:18px;font-weight:bold}
  .badge{display:inline-block;padding:2px 10px;border-radius:4px;font-size:11px;font-weight:bold;background:rgba(255,255,255,.2)}
  nav{background:#fff;border-bottom:2px solid var(--border);padding:0 24px;display:flex}
  nav a{display:inline-block;padding:10px 18px;text-decoration:none;color:#555;border-bottom:3px solid transparent;margin-bottom:-2px;font-weight:500;font-size:13px}
  nav a.active{color:var(--blue);border-color:var(--blue)}
  nav a:hover{color:var(--blue)}
  .wrap{max-width:1100px;margin:24px auto;padding:0 20px}
  .card{background:#fff;border:1px solid var(--border);border-radius:6px;padding:20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .card h2{font-size:15px;color:var(--blue);border-bottom:1px solid #eee;padding-bottom:8px;margin-bottom:16px}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
  .stat{background:#fff;border:1px solid var(--border);border-radius:6px;padding:18px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .stat .n{font-size:32px;font-weight:bold;color:var(--blue)}
  .stat .l{font-size:12px;color:#888;margin-top:4px}
  .msg{background:#e8f5e9;border:1px solid #a5d6a7;color:var(--green);padding:10px 16px;border-radius:4px;margin-bottom:16px}
  label{display:block;font-weight:bold;margin-bottom:4px;margin-top:14px;font-size:13px}
  input,select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:14px}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
  button,.btn{display:inline-block;padding:9px 20px;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:bold;text-decoration:none}
  .btn-blue{background:var(--blue);color:#fff}.btn-blue:hover{background:var(--blue-d)}
  .btn-green{background:var(--green);color:#fff}
  .btn-sm{padding:4px 10px;font-size:12px}
  .mt{margin-top:16px}
  table{width:100%;border-collapse:collapse}
  th{background:var(--blue-l);text-align:left;padding:8px 12px;font-size:13px;border-bottom:2px solid var(--border)}
  td{padding:8px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
  tr:hover td{background:#fafcff}
  .pill{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:bold}
  .p-sub{background:#e3f0fb;color:var(--blue)}.p-ok{background:#e8f5e9;color:var(--green)}
  .p-err{background:#ffebee;color:var(--red)}.p-uk{background:#f5f5f5;color:#666}
  .code{background:#1e1e1e;color:#80cbc4;padding:10px 14px;border-radius:4px;font-family:monospace;font-size:13px;word-break:break-all;margin-top:4px}
  .logbox{background:#1e1e1e;color:#ccc;padding:12px;border-radius:4px;font-family:monospace;font-size:12px;max-height:420px;overflow-y:auto}
  .logbox div{padding:1px 0}
  .le{color:#ef9a9a}.li{color:#a5d6a7}.ld{color:#90caf9}
  .sep{margin-top:20px;padding-top:12px;border-top:1px solid #eee;color:#666;font-size:13px;font-weight:bold}
  .empty{color:#aaa;padding:24px 0;text-align:center}
  .client-search-bar{background:#fffde7;border:1px solid #ffe082;border-radius:6px;padding:14px 16px;margin-bottom:18px}
  .client-search-bar h3{font-size:13px;color:#e65100;margin-bottom:10px;font-weight:bold}
  .cs-row{display:flex;gap:10px;align-items:flex-end}
  .cs-row input{flex:1;padding:8px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px}
  .cs-results{margin-top:10px;border:1px solid #ddd;border-radius:4px;background:#fff;max-height:180px;overflow-y:auto;display:none}
  .cs-item{padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f5f5f5;display:flex;justify-content:space-between;align-items:center}
  .cs-item:hover{background:#e3f0fb}
  .cs-item .cs-name{font-weight:bold;color:#1565c0}
  .cs-item .cs-addr{font-size:11px;color:#888}
  .cs-filled{background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:8px 12px;font-size:12px;margin-top:8px;display:none}
</style>
</head>
<body>
<header>
  <h1>🔌 Frontier ASR UOM Ordering</h1>
  <span class="badge"><?= htmlspecialchars($config['environment']) ?> ENV</span>
  <span style="margin-left:auto;font-size:12px;opacity:.8">Blue Mogul Enterprise LLC</span>
</header>

<nav>
<?php $tabs=['dashboard'=>'📊 Dashboard','new_order'=>'➕ New Order','preorder'=>'🔍 Pre-Order','settings'=>'⚙️ Settings','logs'=>'📋 Logs'];
foreach($tabs as $k=>$label): ?>
  <a href="<?= $base ?>?action=<?= $k ?>" class="<?= $tab===$k?'active':'' ?>"><?= $label ?></a>
<?php endforeach; ?>
</nav>

<div class="wrap">
<?php if($message): ?><div class="msg">✅ <?= htmlspecialchars($message) ?></div><?php endif; ?>

<?php if($tab==='dashboard'): ?>
<div class="stats">
  <div class="stat"><div class="n"><?= $total ?></div><div class="l">Total Orders</div></div>
  <div class="stat"><div class="n" style="color:var(--green)"><?= $completed ?></div><div class="l">Completed</div></div>
  <div class="stat"><div class="n" style="color:var(--orange)"><?= $pending ?></div><div class="l">In Progress</div></div>
  <div class="stat"><div class="n" style="color:var(--red)"><?= $errors ?></div><div class="l">Errors</div></div>
</div>
<div class="card">
  <h2>Recent Orders</h2>
  <?php if(empty($orders)): ?>
    <div class="empty">No orders yet. <a href="<?= $base ?>?action=new_order">Submit your first order →</a></div>
    <div style="text-align:center;margin-top:8px"><a href="<?= $base ?>?action=preorder" class="btn btn-blue btn-sm">🔍 Check Address Availability</a></div>
  <?php else: ?>
  <table>
    <thead><tr><th>PON</th><th>Type</th><th>Address</th><th>Status</th><th>Circuit ID</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach($orders as $o):
      $s=$o['status']??'UNKNOWN';
      $cls=match(true){$s==='COMPLETED'=>'p-ok',str_contains(strtolower($s),'error')||str_contains(strtolower($s),'fail')=>'p-err',str_contains(strtolower($s),'submit')=>'p-sub',default=>'p-uk'}; ?>
    <tr>
      <td><strong><?= htmlspecialchars($o['pon']??'') ?></strong></td>
      <td><?= htmlspecialchars($o['type']??'ORDER') ?></td>
      <td><?= htmlspecialchars(trim(($o['address_line1']??'').', '.($o['city']??'').', '.($o['state']??''),', ')) ?></td>
      <td><span class="pill <?= $cls ?>"><?= htmlspecialchars($s) ?></span></td>
      <td><?= htmlspecialchars($o['circuit_id']??'—') ?></td>
      <td><?= htmlspecialchars(substr($o['created_at']??'',0,16)) ?></td>
      <td><a href="<?= $base ?>?action=dashboard&view=<?= urlencode($o['pon']??'') ?>" class="btn btn-blue btn-sm">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php if(!empty($_GET['view'])):
  $d=$orderManager->find($_GET['view']); if($d): ?>
<div class="card">
  <h2>Order Detail — <?= htmlspecialchars($d['pon']) ?></h2>
  <pre style="background:#f5f5f5;padding:14px;border-radius:4px;overflow:auto;font-size:12px"><?= htmlspecialchars(json_encode($d,JSON_PRETTY_PRINT)) ?></pre>
</div>
<?php endif; endif; ?>

<?php elseif($tab==='new_order'): ?>
<div class="card">
  <h2>Submit New ASR Order to Frontier</h2>

  <!-- Client Lookup -->
  <div class="client-search-bar">
    <h3>👤 Fill from CRM Client (optional)</h3>
    <div class="cs-row">
      <input type="text" id="o_csq" placeholder="Search by client name or address..." oninput="csSearch(this,'o')" autocomplete="off">
      <button type="button" class="btn btn-blue btn-sm" onclick="csClear('o')">Clear</button>
    </div>
    <div class="cs-results" id="o_csres"></div>
    <div class="cs-filled" id="o_csfill"></div>
  </div>

  <form id="oForm">
    <div class="row2">
      <div><label>Activity Code</label>
        <select name="activity_code">
          <option value="N">N — New Install</option>
          <option value="C">C — Change</option>
          <option value="D">D — Disconnect</option>
        </select></div>
      <div><label>Account Number (AN)</label><input type="text" name="account_number" placeholder="Frontier account number"></div>
    </div>
    <div class="row2">
      <div><label>Desired Due Date</label><input type="text" name="desired_due_date" placeholder="YYYY-MM-DD"></div>
      <div><label>PON <span style="font-weight:normal;color:#888">(auto-generated if blank)</span></label><input type="text" name="pon"></div>
    </div>
    <div class="row2" style="margin-top:0">
      <div>
        <label>UCRM Client ID <span style="font-weight:normal;color:#888">(for billing automation)</span></label>
        <input type="text" name="client_id" id="o_client_id" placeholder="Auto-filled from Client Lookup above">
        <p style="font-size:11px;color:#888;margin-top:3px">Required to auto-activate service &amp; invoice on Frontier confirmation.</p>
      </div>
      <div>
        <label>UCRM Service ID <span style="font-weight:normal;color:#888">(service to activate)</span></label>
        <input type="text" name="service_id" id="o_service_id" placeholder="Find at Client → Services → click service → ID in URL">
      </div>
    </div>
    <div class="sep">Service Address</div>
    <div><label>Address Line 1</label><input type="text" name="address_line1" placeholder="123 Main St"></div>
    <div class="row3" style="margin-top:12px">
      <div><label>City</label><input type="text" name="city"></div>
      <div><label>State</label><input type="text" name="state" placeholder="TX" maxlength="2"></div>
      <div><label>ZIP</label><input type="text" name="zip"></div>
    </div>
    <div class="sep">Contact</div>
    <div class="row3">
      <div><label>Name</label><input type="text" name="contact_name" value="<?= htmlspecialchars($config['contact_name']) ?>"></div>
      <div><label>Phone</label><input type="text" name="contact_phone" value="<?= htmlspecialchars($config['contact_phone']) ?>"></div>
      <div><label>Email</label><input type="text" name="contact_email" value="<?= htmlspecialchars($config['contact_email']) ?>"></div>
    </div>
    <div class="mt">
      <button type="submit" class="btn btn-green">🚀 Send Order to Frontier (<?= htmlspecialchars($config['environment']) ?>)</button>
    </div>
    <div id="orderResult"></div>
  </form>
</div>
<script>
document.getElementById('oForm').addEventListener('submit',async function(e){
  e.preventDefault();
  const r=document.getElementById('orderResult');
  r.innerHTML='<em style="color:#888">Sending to Frontier...</em>';
  const data=Object.fromEntries(new FormData(this));data.type='ORDER';
  try{
    const res=await fetch('<?= $base ?>?action=send',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
    const j=await res.json();
    r.innerHTML=j.success
      ?`<div class="msg">✅ Order sent! PON: <strong>${j.pon}</strong> — Status: ${j.status} <a href="<?= $base ?>?action=dashboard" style="margin-left:12px">View Dashboard →</a></div>`
      :`<div style="background:#ffebee;padding:10px;border-radius:4px;color:var(--red)">❌ Send failed (HTTP ${j.http_code}). Check Logs tab.</div>`;
  }catch(err){r.innerHTML=`<div style="color:red">Error: ${err.message}</div>`;}
});
</script>

<?php elseif($tab==='preorder'): ?>
<div class="card">
  <h2>ASR Pre-Order — Service Availability Check</h2>
  <p style="color:#666;margin-bottom:16px">Verify Frontier can provision service at an address before submitting a full order.</p>

  <!-- Client Lookup -->
  <div class="client-search-bar">
    <h3>👤 Fill from CRM Client (optional)</h3>
    <div class="cs-row">
      <input type="text" id="p_csq" placeholder="Search by client name or address..." oninput="csSearch(this,'p')" autocomplete="off">
      <button type="button" class="btn btn-blue btn-sm" onclick="csClear('p')">Clear</button>
    </div>
    <div class="cs-results" id="p_csres"></div>
    <div class="cs-filled" id="p_csfill"></div>
  </div>

  <form id="pForm">
    <div><label>Address Line 1</label><input type="text" name="address_line1" placeholder="123 Main St"></div>
    <div class="row3" style="margin-top:12px">
      <div><label>City</label><input type="text" name="city"></div>
      <div><label>State</label><input type="text" name="state" placeholder="TX" maxlength="2"></div>
      <div><label>ZIP</label><input type="text" name="zip"></div>
    </div>
    <div style="margin-top:12px;max-width:300px"><label>PON <span style="font-weight:normal;color:#888">(optional)</span></label><input type="text" name="pon"></div>
    <div class="mt"><button type="submit" class="btn btn-blue">🔍 Check Availability</button></div>
    <div id="preResult"></div>
  </form>
</div>
<script>
document.getElementById('pForm').addEventListener('submit',async function(e){
  e.preventDefault();
  const r=document.getElementById('preResult');
  r.innerHTML='<em style="color:#888">Checking...</em>';
  const data=Object.fromEntries(new FormData(this));data.type='PRE-ORDER';
  try{
    const res=await fetch('<?= $base ?>?action=send',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
    const j=await res.json();
    r.innerHTML=j.success
      ?`<div class="msg">✅ Pre-order sent! PON: <strong>${j.pon}</strong> — Awaiting Frontier response.</div>`
      :`<div style="background:#ffebee;padding:10px;border-radius:4px;color:var(--red)">❌ Failed (HTTP ${j.http_code}). Check Logs.</div>`;
  }catch(err){r.innerHTML=`<div style="color:red">Error: ${err.message}</div>`;}
});
</script>

<?php elseif($tab==='settings'): ?>
<div class="card">
  <h2>Plugin Configuration</h2>
  <form method="post" action="<?= $base ?>?action=save_settings">
    <div class="row2">
      <div><label>Environment</label>
        <select name="environment">
          <option value="TEST" <?= $config['environment']==='TEST'?'selected':'' ?>>TEST (Sandbox)</option>
          <option value="PRODUCTION" <?= $config['environment']==='PRODUCTION'?'selected':'' ?>>PRODUCTION</option>
        </select></div>
      <div><label>CCNA</label><input type="text" name="ccna" value="<?= htmlspecialchars($config['ccna']) ?>"></div>
    </div>
    <div><label>Source IP</label><input type="text" name="source_ip" value="<?= htmlspecialchars($config['source_ip']) ?>"></div>
    <div class="sep">Default Contact</div>
    <div class="row3">
      <div><label>Name</label><input type="text" name="contact_name" value="<?= htmlspecialchars($config['contact_name']) ?>"></div>
      <div><label>Phone</label><input type="text" name="contact_phone" value="<?= htmlspecialchars($config['contact_phone']) ?>"></div>
      <div><label>Email</label><input type="text" name="contact_email" value="<?= htmlspecialchars($config['contact_email']) ?>"></div>
    </div>
    <div class="sep">Billing Automation</div>
    <div class="row2">
      <div>
        <label>Setup Fee Amount ($) <span style="font-weight:normal;color:#888">(0 = disabled)</span></label>
        <input type="number" name="setup_fee" step="0.01" min="0" value="<?= htmlspecialchars($config['setup_fee'] ?? '0') ?>" placeholder="e.g. 99.00">
        <p style="font-size:11px;color:#888;margin-top:3px">Auto-invoiced when Frontier confirms circuit (COMP status).</p>
      </div>
      <div>
        <label>Invoice Due (days after issue)</label>
        <input type="number" name="invoice_maturity_days" min="1" max="90" value="<?= htmlspecialchars($config['invoice_maturity_days'] ?? '14') ?>">
      </div>
    </div>
    <div style="background:#e3f0fb;border:1px solid #90caf9;padding:10px 14px;border-radius:4px;font-size:12px;margin-top:10px;color:#0d47a1">
      <strong>How billing automation works:</strong><br>
      When Frontier sends a <code>COMP</code> (completed) confirmation to your endpoint, the plugin automatically:
      <ol style="margin:6px 0 0 18px;line-height:1.8">
        <li>Activates the client's UCRM service (Quoted → Active) — billing cycle begins</li>
        <li>Creates a one-time setup fee invoice (if amount above &gt; 0)</li>
        <li>Adds the Frontier Circuit ID to the service note</li>
        <li>Logs a note on the client's CRM record</li>
      </ol>
      Requires: UCRM API Key + Client ID and Service ID on the order.
    </div>
    <div class="sep">UCRM Integration</div>
    <div>
      <label>UCRM API Key <span style="font-weight:normal;color:#888">(enables Client Lookup in order forms)</span></label>
      <input type="text" name="ucrm_api_key" value="<?= htmlspecialchars($config['ucrm_api_key'] ?? '') ?>" placeholder="Paste your UCRM API key here">
      <p style="font-size:12px;color:#888;margin-top:5px">
        Generate at: <strong>UISP → System → Users → API keys → Add new</strong>.
        Once set, you can search CRM clients by name on the New Order and Pre-Order tabs to auto-fill their address.
      </p>
    </div>
    <?php if(!empty($config['ucrm_api_key'])): ?>
    <div style="background:#e8f5e9;border:1px solid #a5d6a7;padding:8px 12px;border-radius:4px;font-size:12px;margin-top:8px;color:#2e7d32">
      ✅ API key configured — Client Lookup is active on New Order and Pre-Order tabs.
    </div>
    <?php else: ?>
    <div style="background:#fff3e0;border:1px solid #ffe082;padding:8px 12px;border-radius:4px;font-size:12px;margin-top:8px;color:#e65100">
      ⚠️ No API key set — Client Lookup is disabled. Add your UCRM API key above to enable it.
    </div>
    <?php endif; ?>
    <div class="mt"><button type="submit" class="btn btn-blue">💾 Save Settings</button></div>
  </form>
</div>
<div class="card">
  <h2>Live CLEC Endpoint URLs (for Frontier ticket OAM-3084)</h2>
  <label>ORDER Endpoint</label>
  <div class="code"><?= htmlspecialchars($base) ?>?action=receive</div>
  <label style="margin-top:12px">PRE-ORDER Endpoint</label>
  <div class="code"><?= htmlspecialchars($base) ?>?action=frontier_preorder</div>
  <label style="margin-top:12px">Certificate Common Name</label>
  <div class="code"><?= htmlspecialchars($host) ?></div>
</div>

<?php elseif($tab==='logs'): ?>
<div class="card">
  <h2>Plugin Logs <span style="font-size:12px;font-weight:normal;color:#888">(newest first)</span></h2>
  <?php if(empty($logs)): ?>
    <div class="empty">No log entries yet. Activity appears here once orders are sent or received.</div>
  <?php else: ?>
  <div class="logbox">
    <?php foreach($logs as $line):
      $cls=str_contains($line,'[ERROR]')?'le':(str_contains($line,'[DEBUG]')?'ld':'li'); ?>
      <div class="<?= $cls ?>"><?= htmlspecialchars($line) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>
<script>
// ── UCRM Client Search ──────────────────────────────────────────────────────
let csTimer = {};
const BASE_URL = '<?= $base ?>';

async function csSearch(el, prefix) {
  const q = el.value.trim();
  const res = document.getElementById(prefix + '_csres');
  if (q.length < 2) { res.style.display = 'none'; return; }
  clearTimeout(csTimer[prefix]);
  csTimer[prefix] = setTimeout(async () => {
    res.innerHTML = '<div class="cs-item" style="color:#888">Searching...</div>';
    res.style.display = 'block';
    try {
      const r = await fetch(BASE_URL + '?action=clients&q=' + encodeURIComponent(q));
      const data = await r.json();
      if (!data.length) {
        res.innerHTML = '<div class="cs-item" style="color:#aaa">No clients found</div>';
        return;
      }
      res.innerHTML = data.map(c => {
        const addr = [c.street1, c.city, c.state, c.zip].filter(Boolean).join(', ');
        return `<div class="cs-item" onclick="csFill('${prefix}', ${JSON.stringify(c).replace(/'/g,"\'")})">`
          + `<span><span class="cs-name">${c.name}</span><br><span class="cs-addr">${addr || 'No address on file'}</span></span>`
          + `<span style="font-size:11px;color:#1565c0">Select →</span></div>`;
      }).join('');
    } catch(e) {
      res.innerHTML = '<div class="cs-item" style="color:red">Error: ' + e.message + '</div>';
    }
  }, 350);
}

function csFill(prefix, client) {
  const form = document.getElementById(prefix === 'o' ? 'oForm' : 'pForm');
  const set = (name, val) => { const el = form.querySelector('[name="'+name+'"]'); if (el && val) el.value = val; };
  set('address_line1', client.street1);
  set('city', client.city);
  set('state', client.state);
  set('zip', client.zip);
  if (prefix === 'o') {
    set('contact_name', client.name);
    set('contact_email', client.email);
    set('contact_phone', client.phone);
    set('account_number', client.accountNumber || '');
    // Also fill client_id for billing automation
    const cidEl = document.getElementById('o_client_id');
    if (cidEl) cidEl.value = client.id || '';
  }
  const res = document.getElementById(prefix + '_csres');
  res.style.display = 'none';
  document.getElementById(prefix + '_csq').value = client.name;
  const fill = document.getElementById(prefix + '_csfill');
  fill.style.display = 'block';
  fill.innerHTML = '✅ Filled from: <strong>' + client.name + '</strong> — '
    + [client.street1, client.city, client.state, client.zip].filter(Boolean).join(', ');
}

function csClear(prefix) {
  document.getElementById(prefix + '_csq').value = '';
  document.getElementById(prefix + '_csres').style.display = 'none';
  document.getElementById(prefix + '_csfill').style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', e => {
  ['o','p'].forEach(p => {
    const res = document.getElementById(p + '_csres');
    if (res && !res.contains(e.target) && e.target.id !== p + '_csq') res.style.display = 'none';
  });
});
</script>
</body>
</html>
<?php
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function soapFault(string $code, string $msg): string {
    $msg = htmlspecialchars($msg);
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body><soapenv:Fault><faultcode>{$code}</faultcode><faultstring>{$msg}</faultstring></soapenv:Fault></soapenv:Body>
</soapenv:Envelope>
XML;
}

function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
}

// ── Public Qualify / Check Availability Handler ───────────────────────────────

function handleQualify(string $method, array $config, string $dataDir, OrderManager $orderManager, Logger $logger): void {
    require_once __DIR__ . '/src/FrontierASRClient.php';

    $result = null;
    $pon    = null;
    $error  = null;
    $isEmbed = isset($_GET['embed']);

    if ($method === 'POST') {
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
            if (!$res['success']) $error = 'Unable to check availability right now. Please call us at (346) 309-5514.';
        } else {
            $error = 'Please fill in all address fields.';
        }
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: ALLOW-FROM https://fiber.bluemogul.biz');
    $addr   = htmlspecialchars($_POST['address'] ?? '');
    $city_v = htmlspecialchars($_POST['city']    ?? '');
    $state_v= htmlspecialchars($_POST['state']   ?? 'TX');
    $zip_v  = htmlspecialchars($_POST['zip']     ?? '');
    $name_v = htmlspecialchars($_POST['name']    ?? '');
    $phone_v= htmlspecialchars($_POST['phone']   ?? '');
    $email_v= htmlspecialchars($_POST['email']   ?? '');
    // Force HTTPS — UISP runs behind SSL termination, $_SERVER['HTTPS'] may not be set
    $host     = $_SERVER['HTTP_HOST'] ?? 'uisp.bluemogul.us';
    $selfBase = 'https://' . $host . '/crm/_plugins/frontier-asr/public.php?action=qualify' . ($isEmbed ? '&embed=1' : '');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Check Fiber Availability — Blue Mogul</title>
<style>
  :root{--blue:#1565c0;--orange:#f57c00;--green:#2e7d32;--red:#c62828}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',Arial,sans-serif;background:<?= $isEmbed?'transparent':'#0a1628'?>;color:#222;min-height:<?= $isEmbed?'auto':'100vh'?>}
  <?php if(!$isEmbed): ?>
  .hero{background:linear-gradient(135deg,#0d1b3e 0%,#1565c0 100%);color:#fff;padding:48px 24px;text-align:center}
  .hero h1{font-size:32px;font-weight:800;margin-bottom:8px}.hero h1 span{color:#f57c00}
  .hero p{font-size:16px;opacity:.85;max-width:500px;margin:0 auto}
  <?php endif; ?>
  .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.15);padding:28px;max-width:520px;margin:<?= $isEmbed?'0':'−32px auto 40px'?>;width:100%}
  <?php if($isEmbed): ?>.card{box-shadow:none;border-radius:0;padding:16px}<?php endif; ?>
  .card h2{font-size:20px;color:var(--blue);margin-bottom:4px}.card .sub{color:#666;font-size:13px;margin-bottom:18px}
  label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:3px;margin-top:12px}
  input,select{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px}
  input:focus,select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(21,101,192,.1)}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
  .submit-btn{width:100%;margin-top:18px;padding:14px;background:var(--orange);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer}
  .submit-btn:hover{background:#e65100}.submit-btn:disabled{opacity:.6;cursor:not-allowed}
  .result{border-radius:8px;padding:20px;text-align:center}
  .result-ok{background:#e8f5e9;border:1px solid #a5d6a7}.result-ok h3{color:var(--green);font-size:20px;margin-bottom:8px}
  .result-err{background:#ffebee;border:1px solid #ef9a9a}.result-err h3{color:var(--red);font-size:18px;margin-bottom:8px}
  .result p{color:#555;font-size:14px;line-height:1.6}.result .ref{font-size:11px;color:#aaa;margin-top:10px}
  .again{margin-top:14px;padding:9px 20px;background:var(--blue);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:bold}
  .trust{display:flex;flex-wrap:wrap;gap:16px;justify-content:center;margin-top:16px}
  .trust span{font-size:12px;color:#888}
  .errmsg{background:#ffebee;color:var(--red);padding:8px 12px;border-radius:5px;font-size:13px;margin-bottom:10px}
  <?php if(!$isEmbed): ?>footer{background:#0d1b3e;color:#aaa;text-align:center;padding:18px;font-size:12px}footer a{color:#64b5f6}<?php endif; ?>
</style>
</head>
<body>
<?php if(!$isEmbed): ?><div class="hero"><h1>🔌 Check <span>Fiber</span> Availability</h1><p>See if Blue Mogul high-speed fiber is available at your address.</p></div><?php endif; ?>
<div style="<?= $isEmbed?'':'display:flex;justify-content:center;padding:20px 16px 40px' ?>">
<div class="card">
<?php if($result==='success'): ?>
  <div class="result result-ok">
    <div style="font-size:42px">🎉</div>
    <h3>Great News!</h3>
    <p>We're checking availability at your address. A Blue Mogul rep will contact you within <strong>1 business day</strong> to discuss your options.</p>
    <p style="margin-top:10px">Questions? Call <strong>(346) 309-5514</strong></p>
    <?php if($pon): ?><div class="ref">Reference #: <?= htmlspecialchars($pon) ?></div><?php endif; ?>
  </div>
  <button class="again" onclick="location.href='<?= $selfBase ?>'">Check Another Address</button>
<?php elseif($result==='error'): ?>
  <div class="result result-err">
    <div style="font-size:42px">😔</div>
    <h3>Something Went Wrong</h3>
    <p><?= htmlspecialchars($error ?? 'Please try again or call us.') ?></p>
  </div>
  <button class="again" onclick="location.href='<?= $selfBase ?>'">Try Again</button>
<?php else: ?>
  <h2>Check Service Availability</h2>
  <p class="sub">Enter your address to see if fiber is available.</p>
  <?php if($error): ?><div class="errmsg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" action="<?= $selfBase ?>" id="qf">
    <div class="row2">
      <div><label>Full Name</label><input type="text" name="name" placeholder="Your name" value="<?= $name_v ?>"></div>
      <div><label>Phone</label><input type="tel" name="phone" placeholder="(346) 000-0000" value="<?= $phone_v ?>"></div>
    </div>
    <div><label>Email</label><input type="email" name="email" placeholder="you@example.com" value="<?= $email_v ?>"></div>
    <label>Street Address *</label><input type="text" name="address" placeholder="123 Main St" required value="<?= $addr ?>">
    <div class="row3" style="margin-top:0">
      <div><label>City *</label><input type="text" name="city" required value="<?= $city_v ?>"></div>
      <div><label>State *</label><input type="text" name="state" maxlength="2" required value="<?= $state_v ?: 'TX' ?>"></div>
      <div><label>ZIP *</label><input type="text" name="zip" maxlength="5" required value="<?= $zip_v ?>"></div>
    </div>
    <button type="submit" class="submit-btn" id="sb" onclick="this.disabled=true;this.textContent='⏳ Checking...';this.form.submit()">🔍 Check Availability</button>
    <div class="trust"><span>⚡ Fast Results</span><span>🔒 Secure</span><span>🇺🇸 Veteran-Owned</span><span>📍 Houston, TX</span></div>
    <p style="font-size:11px;color:#aaa;margin-top:10px;text-align:center">By submitting, you agree to receive calls or texts from Blue Mogul Fiber.</p>
  </form>
<?php endif; ?>
</div></div>
<?php if(!$isEmbed): ?><footer>Blue Mogul Enterprise LLC — <a href="tel:3463095514">(346) 309-5514</a> | <a href="mailto:tracy.williams@bluemogul.biz">tracy.williams@bluemogul.biz</a> | 100% Veteran-Owned</footer><?php endif; ?>
</body></html>
<?php
}
