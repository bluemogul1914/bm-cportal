<?php
/**
 * frontier-qualify.php — PUBLIC "Check Fiber Availability" page (no login)
 *
 * Served at:  /portal/frontier-qualify  (also /portal/frontier-qualify.php)
 * Embeddable: /portal/frontier-qualify?embed=1  (for fiber.bluemogul.us iframe)
 *
 * Flow:
 *   GET  → branded address form (street / city / state / zip + optional contact)
 *   POST → persist lead to frontier_orders, call Frontier processSyncRequest
 *          (confirmed v10 <in0>/<PreOrder> envelope), render result.
 *
 * Until Frontier's app team confirms the pre-order payload against CTEST
 * (WSASRTML004 outstanding since 2026-08-18), faults are handled gracefully:
 * the lead is still captured and the visitor gets a reference number.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/prequal.php';

header('Content-Type: text/html; charset=UTF-8');

$isEmbed = isset($_GET['embed']);

// ── Session vars (prefill after a POST render) ───────────────────────────────
$prev = [
    'address' => trim($_POST['address'] ?? ''),
    'city'    => trim($_POST['city']    ?? ''),
    'state'   => strtoupper(trim($_POST['state'] ?? 'TX')),
    'zip'     => trim($_POST['zip']     ?? ''),
    'name'    => trim($_POST['name']    ?? ''),
    'phone'   => trim($_POST['phone']   ?? ''),
    'email'   => trim($_POST['email']   ?? ''),
];

// ── Client-aware prefill + tagging ──────────────────────────────────────────
// If a portal client is logged in, tag any lead with their client_id
// (frontier_orders.client_id) and prefill their address on the initial GET.
// qualify_db() is a top-level fn (hoisted), safe to call here.
$loggedClientId = null;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (!empty($_SESSION['user_id'])) {
    try {
        $pq = qualify_db();
        if ($pq) {
            $cst = $pq->prepare("SELECT id, address, city, state, zip, email FROM clients WHERE user_id = ? LIMIT 1");
            $cst->execute([$_SESSION['user_id']]);
            $pc = $cst->fetch(PDO::FETCH_ASSOC);
            if ($pc) {
                $loggedClientId = (int)$pc['id'];
                if (!$isPost) {
                    $street = trim((string)($pc['address'] ?? ''));
                    if ($street) $prev['address'] = $street;
                    if (!empty($pc['city']))  $prev['city']  = $pc['city'];
                    if (!empty($pc['state'])) $prev['state'] = strtoupper($pc['state']);
                    if (!empty($pc['zip']))   $prev['zip']   = $pc['zip'];
                    if (!empty($pc['email'])) $prev['email'] = $pc['email'];
                }
            }
        }
    } catch (Throwable $e) { /* prefill best-effort */ }
}

$result   = null;   // 'available' | 'unavailable' | 'checking' | 'error'
$message  = null;
$pon      = null;
$formErr  = null;

// ── Non-fatal DB helper ─────────────────────────────────────────────────────
// Unlike config.php's getDB() (which die()s on failure), this returns null so a
// DB blip never blanks the visitor-facing qualify page. Mirrors getDB()'s env
// precedence: DATABASE_URL (portal container) then DB_* constants.
function qualify_db(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $databaseUrl = getenv('DATABASE_URL');
        if ($databaseUrl) {
            $parts = parse_url($databaseUrl);
            $host   = $parts['host'] ?? 'localhost';
            $port   = $parts['port'] ?? 5432;
            $dbname = ltrim($parts['path'] ?? '', '/');
            $user   = $parts['user'] ?? '';
            $pass   = $parts['pass'] ?? '';
            $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $pdo = new PDO(
                DB_TYPE . ':host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
    } catch (Throwable $e) {
        return null;
    }
    return $pdo;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$prev['address'] || !$prev['city'] || !$prev['state'] || !$prev['zip']) {
        $formErr = 'Please fill in your full service address (street, city, state, ZIP).';
    } else {
        $pdo = qualify_db();

        // Provider selection: single by default (Frontier), or 'all' for a dual
        // Frontier+Cox check. Cox is a pending placeholder until its API docs.
        $requested = trim($_POST['provider'] ?? 'Frontier');
        $providers = ($requested === 'all' || $requested === 'ALL')
            ? array_keys(prequal_providers())
            : [$requested];

        $address = [
            'address' => $prev['address'], 'city' => $prev['city'],
            'state'   => $prev['state'],   'zip'   => $prev['zip'],
            'name'    => $prev['name'],    'phone' => $prev['phone'],
            'email'   => $prev['email'],
        ];

        // Persist a lead per provider, run each adapter, collect results.
        $results = prequal_check_many($pdo, $address, $providers, $loggedClientId);

        // First result drives the existing single-result display.
        $first = reset($results);
        $result = $first['available'] === true ? 'available'
                : ($first['available'] === false ? 'unavailable' : 'checking');
        $message = $first['message'];
        $pon     = $first['pon'];
        $multiResults = count($results) > 1 ? $results : null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check Fiber Availability — Blue Mogul Fiber</title>
<style>
  :root{--navy:#0d1b3e;--blue:#1565c0;--gold:#f57c00;--green:#2e7d32;--red:#c62828;--ink:#222;--muted:#666}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',Arial,sans-serif;background:<?= $isEmbed ? 'transparent' : '#f0f4f8' ?>;color:var(--ink);min-height:100vh}
  <?php if(!$isEmbed): ?>
  .hero{background:linear-gradient(135deg,var(--navy) 0%,var(--blue) 100%);color:#fff;padding:44px 24px;text-align:center}
  .hero h1{font-size:30px;font-weight:800;margin-bottom:8px}
  .hero h1 span{color:var(--gold)}
  .hero p{font-size:15px;opacity:.9;max-width:520px;margin:0 auto}
  <?php endif; ?>
  .card{background:#fff;border-radius:12px;box-shadow:<?= $isEmbed ? 'none' : '0 4px 24px rgba(0,0,0,.1)' ?>;padding:<?= $isEmbed ? '16px' : '32px' ?>;max-width:520px;margin:<?= $isEmbed ? '0' : '-28px auto 40px' ?>;width:100%}
  .card h2{font-size:20px;color:var(--blue);margin-bottom:4px}
  .card .sub{color:var(--muted);font-size:13px;margin-bottom:18px}
  label{display:block;font-size:13px;font-weight:600;color:#444;margin:12px 0 4px}
  input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;transition:border-color .2s}
  input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(21,101,192,.1)}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
  .sec{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#999;font-weight:bold;margin:18px 0 2px}
  .btn{width:100%;margin-top:18px;padding:14px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;transition:background .2s}
  .btn:hover{background:var(--navy)}
  .btn:disabled{opacity:.6;cursor:not-allowed}
  .result{border-radius:8px;padding:20px;text-align:center}
  .r-ok{background:#e8f5e9;border:1px solid #a5d6a7}.r-ok h3{color:var(--green)}
  .r-no{background:#fff3e0;border:1px solid #ffe0b2}.r-no h3{color:#e65100}
  .r-wait{background:#e3f0fb;border:1px solid #90caf9}.r-wait h3{color:var(--blue)}
  .r-err{background:#ffebee;border:1px solid #ef9a9a}.r-err h3{color:var(--red)}
  .result h3{font-size:19px;margin-bottom:8px}
  .result p{color:#555;font-size:14px;line-height:1.6}
  .result .ref{font-size:12px;color:#999;margin-top:12px;word-break:break-all}
  .again{margin-top:14px;padding:9px 20px;background:var(--blue);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:bold}
  .again:hover{background:var(--navy)}
  .errbar{background:#ffebee;color:var(--red);padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:16px}
  .trust{display:flex;flex-wrap:wrap;gap:16px;justify-content:center;margin-top:18px}
  .trust span{font-size:12px;color:#888}
  .fine{font-size:11px;color:#aaa;margin-top:10px;text-align:center}
  <?php if(!$isEmbed): ?>
  footer{background:var(--navy);color:#aaa;text-align:center;padding:18px;font-size:12px;margin-top:auto}
  footer a{color:#64b5f6;text-decoration:none}
  .page-wrap{min-height:100vh;display:flex;flex-direction:column}
  <?php endif; ?>
</style>
</head>
<body>
<?php if(!$isEmbed): ?><div class="page-wrap"><?php endif; ?>

<?php if(!$isEmbed): ?>
<div class="hero">
  <h1>🔌 Check <span>Fiber</span> Availability</h1>
  <p>See if Blue Mogul high-speed fiber is available at your address.</p>
</div>
<?php endif; ?>

<div style="<?= $isEmbed ? '' : 'display:flex;justify-content:center;padding:0 16px' ?>">
<div class="card">

<?php if($result === 'available'): ?>
  <div class="result r-ok">
    <div style="font-size:44px">🎉</div>
    <h3>Great news — fiber is available here!</h3>
    <p>Blue Mogul fiber can serve your address. A Blue Mogul representative will reach out within <strong>1 business day</strong> to set up your service.</p>
    <p style="margin-top:10px">Questions? Call <strong><a href="tel:3463095514" style="color:var(--green)">(346) 309-5514</a></strong></p>
    <?php if($pon): ?><div class="ref">Reference #: <?= htmlspecialchars($pon) ?></div><?php endif; ?>
  </div>
  <button class="again" onclick="location.href='?'">Check Another Address</button>

<?php elseif($result === 'unavailable'): ?>
  <div class="result r-no">
    <div style="font-size:44px">📍</div>
    <h3>Not yet available at this address</h3>
    <p>Fiber isn't currently available at this location, but we're expanding fast. Leave your contact info and we'll let you know when service reaches you.</p>
    <p style="margin-top:10px">Call <strong><a href="tel:3463095514" style="color:#e65100">(346) 309-5514</a></strong> to discuss options</p>
    <?php if($pon): ?><div class="ref">Reference #: <?= htmlspecialchars($pon) ?></div><?php endif; ?>
  </div>
  <button class="again" onclick="location.href='?'">Check Another Address</button>

<?php elseif($result === 'checking' || $result === 'error'): ?>
  <div class="result r-wait">
    <div style="font-size:44px">⏳</div>
    <h3>We're checking your address</h3>
    <p>We received your request and a Blue Mogul specialist will confirm availability and contact you within <strong>1 business day</strong>.</p>
    <p style="margin-top:10px">Questions? Call <strong><a href="tel:3463095514" style="color:var(--blue)">(346) 309-5514</a></strong></p>
    <?php if($pon): ?><div class="ref">Reference #: <?= htmlspecialchars($pon) ?></div><?php endif; ?>
  </div>
  <button class="again" onclick="location.href='?'">Check Another Address</button>

<?php if(!empty($multiResults)): ?>
  <div class="result" style="background:#f5f7fa;border:1px solid #dde3ec;margin-top:12px;text-align:left">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:#888;font-weight:bold;margin-bottom:8px">Per-provider results</div>
    <?php foreach($multiResults as $p): ?>
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid #eef1f6">
        <strong style="color:var(--navy)"><?= htmlspecialchars($p['provider']) ?></strong>
        <span style="color:<?= $p['available']===true?'var(--green)':($p['available']===false?'#e65100':'var(--blue)') ?>">
          <?= $p['available']===true?'Available':($p['available']===false?'Not available':'Pending') ?>
        </span>
      </div>
      <?php if($p['message']): ?><div style="font-size:12px;color:#888;margin-top:2px"><?= htmlspecialchars($p['message']) ?></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php else: ?>
  <h2>Check Service Availability</h2>
  <p class="sub">Enter your business or home address to see if fiber is available in your area.</p>

  <?php if($formErr): ?><div class="errbar"><?= htmlspecialchars($formErr) ?></div><?php endif; ?>
  <?php if($message): ?><div class="errbar" style="background:#e3f0fb;color:var(--blue)"><?= htmlspecialchars($message) ?></div><?php endif; ?>

  <form method="post" id="qf">
    <div class="sec">Service Address</div>
    <label>Street Address *</label>
    <input type="text" name="address" placeholder="123 Main Street" required value="<?= htmlspecialchars($prev['address']) ?>">
    <div class="row3" style="margin-top:0">
      <div><label>City *</label><input type="text" name="city" required value="<?= htmlspecialchars($prev['city']) ?>"></div>
      <div><label>State *</label><input type="text" name="state" maxlength="2" required value="<?= htmlspecialchars($prev['state'] ?? 'TX') ?>"></div>
      <div><label>ZIP *</label><input type="text" name="zip" maxlength="5" required value="<?= htmlspecialchars($prev['zip']) ?>"></div>
    </div>

    <div class="sec">Your Contact Info <span style="text-transform:none;font-weight:normal;font-size:11px">(optional)</span></div>
    <div class="row2">
      <div><label>Name</label><input type="text" name="name" placeholder="Your name" value="<?= htmlspecialchars($prev['name']) ?>"></div>
      <div><label>Phone</label><input type="tel" name="phone" placeholder="(346) 000-0000" value="<?= htmlspecialchars($prev['phone']) ?>"></div>
    </div>
    <label>Email</label>
    <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($prev['email']) ?>">

    <button type="submit" class="btn" id="sb">🔍 Check Availability</button>

    <div class="trust">
      <span>⚡ Fast Results</span><span>🔒 Secure</span>
      <span>🇺🇸 Veteran-Owned</span><span>📍 Houston, TX + 350-mile coverage (Frontier & Cox)</span>
    </div>
    <p class="fine">By submitting, you agree to receive a call or text from Blue Mogul Fiber about your request.</p>
  </form>
<?php endif; ?>

</div>
</div>

<?php if(!$isEmbed): ?>
<footer>
  <strong>Blue Mogul Enterprise LLC</strong> — Broadband | Voice | Web | Managed-IT | IT-Support<br>
  Houston, Texas | <a href="tel:3463095514">(346) 309-5514</a> | <a href="mailto:tracy.williams@bluemogul.biz">tracy.williams@bluemogul.biz</a> · 100% Veteran-Owned
</footer>
</div>
<?php endif; ?>

<script>
document.getElementById('sb')?.addEventListener('click', function(){ this.disabled = true; this.textContent = '⏳ Checking...'; });
</script>
</body>
</html>