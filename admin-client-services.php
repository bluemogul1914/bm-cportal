<?php
/**
 * Client Services — admin view of per-client subscriptions in the prepaid model.
 *
 * Lists every client with their subscriptions grouped by service line (product
 * category). Admins can add a service to a client and toggle a subscription
 * between Active and Suspended.
 *
 * All services are prepaid — the client's wallet funds their active services,
 * and the account is suspended at $0. A top-up restores it.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$pdo = getDB();
$success_message = '';
$error_message = '';

// Loopback origin for calling the Node API (Stripe service-invoice checkout)
$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    // ── Toggle subscription status ────────────────────────────────────
    if ($action === 'set_status') {
        $subId = (int)($_POST['subscription_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($subId > 0 && in_array($status, ['active', 'suspended'], true)) {
            $stmt = $pdo->prepare("UPDATE subscriptions SET status = :s, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':s' => $status, ':id' => $subId]);
            $success_message = 'Subscription status updated to ' . $status . '.';
        } else {
            $error_message = 'Invalid subscription or status.';
        }
    }

    // ── Add a service to a client (order → invoice → paid → active) ────
    elseif ($action === 'add_subscription') {
        $clientId  = (int)($_POST['client_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($clientId <= 0 || $productId <= 0) {
            $error_message = 'Invalid client or product selection.';
        } else {
            $pStmt = $pdo->prepare("SELECT name, price FROM products WHERE id = :id");
            $pStmt->execute([':id' => $productId]);
            $product = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                $error_message = 'Product not found.';
            } else {
                $price = (float)$product['price'];

                $bStmt = $pdo->prepare("SELECT COALESCE(credit_balance,0) AS bal FROM clients WHERE id = :id");
                $bStmt->execute([':id' => $clientId]);
                $balance = (float)($bStmt->fetchColumn() ?: 0);

                // 1) Create a PENDING subscription (not active until the invoice is paid)
                $stmt = $pdo->prepare("
                    INSERT INTO subscriptions (client_id, product_id, status, start_date, mrr, created_at, updated_at)
                    VALUES (:client_id, :product_id, 'pending', CURRENT_DATE, :price, NOW(), NOW())
                    RETURNING id
                ");
                $stmt->execute([':client_id' => $clientId, ':product_id' => $productId, ':price' => $price]);
                $subscriptionId = (int)$stmt->fetchColumn();

                // 2) Invoice number via invoice_sequences
                $seqStmt = $pdo->prepare("
                    INSERT INTO invoice_sequences (client_id, seq) VALUES (:cid, 1)
                    ON CONFLICT (client_id) DO UPDATE SET seq = invoice_sequences.seq + 1
                    RETURNING seq
                ");
                $seqStmt->execute([':cid' => $clientId]);
                $seq = (int)$seqStmt->fetchColumn();
                $invoiceNumber = "INV-{$clientId}-" . date('Ymd') . "-" . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

                // 3) Create an UNPAID invoice linked to the subscription
                $items = json_encode([['name' => $product['name'], 'description' => 'First month service', 'amount' => $price]]);
                $invStmt = $pdo->prepare("
                    INSERT INTO invoices (client_id, invoice_number, amount, tax, total, status, paid_date, items, notes, subscription_id, created_at)
                    VALUES (:cid, :invnum, :amount, '0.00', :amount2, 'unpaid', NULL, :items, :notes, :subid, NOW())
                ");
                $invStmt->execute([
                    ':cid'     => $clientId,
                    ':invnum'  => $invoiceNumber,
                    ':amount'  => $price,
                    ':amount2' => $price,
                    ':items'   => $items,
                    ':notes'   => 'Service order: ' . $product['name'],
                    ':subid'   => $subscriptionId,
                ]);
                $invoiceId = (int)$pdo->lastInsertId();

                if ($balance >= $price) {
                    // WALLET SETTLE: debit the prepaid balance, mark paid, activate
                    $after = $balance - $price;
                    $pdo->prepare("UPDATE clients SET credit_balance = :b, last_charged_at = NOW(), updated_at = NOW() WHERE id = :id")
                        ->execute([':b' => $after, ':id' => $clientId]);
                    $pdo->prepare("
                        INSERT INTO transaction_ledger (client_id, type, amount, balance_before, balance_after, description, metadata, created_at)
                        VALUES (:cid, 'charge', :amt, :before, :after, :desc, :meta, NOW())
                    ")->execute([
                        ':cid'    => $clientId,
                        ':amt'    => $price,
                        ':before' => $balance,
                        ':after'  => $after,
                        ':desc'   => 'First month - ' . $product['name'],
                        ':meta'   => json_encode(['method' => 'service_order', 'subscription_id' => $subscriptionId, 'invoice_id' => $invoiceId]),
                    ]);
                    $pdo->prepare("UPDATE invoices SET status = 'paid', paid_date = CURRENT_DATE, paid_at = NOW() WHERE id = :id")
                        ->execute([':id' => $invoiceId]);
                    $pdo->prepare("UPDATE subscriptions SET status = 'active', updated_at = NOW() WHERE id = :id")
                        ->execute([':id' => $subscriptionId]);
                    $success_message = $product['name'] . ' activated — $' . number_format($price, 2) . ' paid from the prepaid balance.';
                } else {
                    // INSUFFICIENT BALANCE: stay pending + unpaid, mint a Stripe Checkout
                    $payUrl = '';
                    $ch = curl_init($internalOrigin . '/api/admin/service-invoice/checkout');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_TIMEOUT        => 30,
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                        CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
                        CURLOPT_POSTFIELDS     => json_encode(['invoice_id' => $invoiceId]),
                    ]);
                    $resp = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $cerr = curl_error($ch);
                    curl_close($ch);
                    if (!$cerr && $http === 200) {
                        $data = json_decode((string)$resp, true);
                        if (!empty($data['url'])) { $payUrl = $data['url']; }
                    }
                    if ($payUrl !== '') {
                        $success_message = 'Invoice ' . $invoiceNumber . ' created for $' . number_format($price, 2)
                            . ' — <a href="' . htmlspecialchars($payUrl) . '" target="_blank" class="underline text-blue-600">Pay now to activate</a>.';
                    } else {
                        $success_message = 'Invoice ' . $invoiceNumber . ' created for $' . number_format($price, 2)
                            . ' (unpaid). The service activates once it is paid.';
                    }
                }
            }
        }
    }

    // ── Pay a service invoice (mints Stripe Checkout, then redirects) ────
    elseif ($action === 'pay_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            $error_message = 'Invalid invoice.';
        } else {
            $ch = curl_init($internalOrigin . '/api/admin/service-invoice/checkout');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
                CURLOPT_POSTFIELDS     => json_encode(['invoice_id' => $invoiceId]),
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            $data = json_decode((string)$resp, true);
            if (!$cerr && $http === 200 && !empty($data['url'])) {
                header('Location: ' . $data['url']);
                exit;
            } else {
                $error_message = 'Could not create a payment link. Please try again.';
            }
        }
    }
}

// ── Load data ────────────────────────────────────────────────────────────

// All clients with prepaid balance and summed active MRR
$clients = $pdo->query("
    SELECT c.id, c.name, c.email, c.credit_balance, c.status,
           COALESCE(SUM(s.mrr) FILTER (WHERE s.status='active'), 0) AS monthly_total
    FROM clients c
    LEFT JOIN subscriptions s ON s.client_id = c.id
    GROUP BY c.id
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

// All active products for "add service" dropdowns
$products = $pdo->query("
    SELECT id, name, category, price FROM products WHERE active = true
    ORDER BY category, name
")->fetchAll(PDO::FETCH_ASSOC);

// Per-client subscriptions — lazy-loaded inside the loop
function getClientSubscriptions(PDO $pdo, int $clientId): array {
    $stmt = $pdo->prepare("
        SELECT s.id, s.status, s.mrr, s.start_date, p.name, p.category,
               i.id AS invoice_id, i.invoice_number, i.status AS invoice_status
        FROM subscriptions s
        JOIN products p ON p.id = s.product_id
        LEFT JOIN LATERAL (
            SELECT id, invoice_number, status FROM invoices
            WHERE subscription_id = s.id ORDER BY id DESC LIMIT 1
        ) i ON true
        WHERE s.client_id = :id
        ORDER BY p.category, p.name
    ");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Services - Blue Mogul Admin</title>
    <meta name="description" content="Link and manage each client's Fiber, VoIP, V2Cloud, RMM and Managed-IT services (prepaid).">

    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">

    <script src="/assets/js/tailwind-shared.js"></script>
    <script>
        tailwind.config = window.bmTailwindConfig;
    </script>
</head>
<body class="bg-gray-50 font-sans">

    <div class="flex h-screen overflow-hidden">

        <?php include 'includes/admin-sidebar.php'; ?>

        <div class="flex-1 overflow-y-auto">

            <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">
                                <i class="fas fa-boxes mr-2 text-blue-600"></i>Client Services
                            </h1>
                            <p class="text-sm text-gray-600 mt-1">Link and manage each client's Fiber, VoIP, V2Cloud, RMM and Managed-IT services (prepaid).</p>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-6">

    <?php if ($success_message): ?>
        <div class="mb-5 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="mb-5 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-800">
        <i class="fas fa-info-circle mr-2"></i>
        <strong>How this works:</strong> services are prepaid — a client's wallet funds their active services. The monthly charge is the sum
        of their active service rates. The account is suspended at $0.00, and a top-up restores it automatically.
    </div>

    <?php if (!$clients): ?>
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-8 text-center text-gray-500">
            No clients found.
        </div>
    <?php endif; ?>

    <?php foreach ($clients as $c):
        $balance     = (float)($c['credit_balance'] ?? 0);
        $monthly     = (float)($c['monthly_total'] ?? 0);
        $isSuspended = ($c['status'] ?? 'active') === 'suspended';
        $subs        = getClientSubscriptions($pdo, (int)$c['id']);
    ?>
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm mb-6">
            <!-- Card header -->
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-3">
                    <div class="leading-tight">
                        <div class="font-semibold text-gray-900"><?= htmlspecialchars($c['name']) ?></div>
                        <div class="text-gray-500 text-sm"><?= htmlspecialchars($c['email'] ?? '') ?></div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-3 py-1 text-xs font-medium rounded-full <?= $isSuspended ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>">
                        <?= $isSuspended ? 'Suspended' : 'Active' ?>
                    </span>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">Balance</div>
                        <div class="font-mono text-sm font-semibold <?= $balance <= 0 ? 'text-red-700' : 'text-green-700' ?>">
                            $<?= number_format($balance, 2) ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">Monthly</div>
                        <div class="font-mono text-sm font-semibold text-gray-900">$<?= number_format($monthly, 2) ?>/mo</div>
                    </div>
                </div>
            </div>

            <!-- Subscriptions table -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Service</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Line</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Rate</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                    <?php if (!$subs): ?>
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-gray-500">No services linked yet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($subs as $s): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-900"><?= htmlspecialchars($s['name']) ?></td>
                            <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($s['category']) ?></td>
                            <td class="px-5 py-3 text-right font-mono text-gray-900">$<?= number_format((float)$s['mrr'], 2) ?></td>
                            <td class="px-5 py-3">
                                <?php if ($s['status'] === 'active'): ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">Active</span>
                                <?php elseif ($s['status'] === 'pending'): ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700">Pending</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <?php if ($s['status'] === 'pending'): ?>
                                    <?php if (!empty($s['invoice_number'])): ?>
                                        <span class="text-xs text-gray-500 mr-2"><?= htmlspecialchars($s['invoice_number']) ?> (<?= htmlspecialchars($s['invoice_status'] ?? 'unpaid') ?>)</span>
                                    <?php endif; ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="pay_invoice">
                                        <input type="hidden" name="invoice_id" value="<?= (int)($s['invoice_id'] ?? 0) ?>">
                                        <button type="submit" class="rounded-md bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 text-xs font-medium">Pay now</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="subscription_id" value="<?= (int)$s['id'] ?>">
                                        <?php if ($s['status'] === 'active'): ?>
                                            <input type="hidden" name="status" value="suspended">
                                            <button type="submit" class="text-red-600 border border-red-200 hover:bg-red-50 rounded-md px-3 py-1 text-xs font-medium"
                                                    onclick="return confirm('Suspend this service?')">Suspend</button>
                                        <?php else: ?>
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="text-green-600 border border-green-200 hover:bg-green-50 rounded-md px-3 py-1 text-xs font-medium"
                                                    onclick="return confirm('Activate this service?')">Activate</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add service form -->
            <div class="px-5 py-3 border-t border-gray-200 bg-gray-50">
                <form method="POST" class="flex items-center gap-3 flex-wrap">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="add_subscription">
                    <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                    <span class="text-sm text-gray-600 font-medium">Add a service:</span>
                    <select name="product_id" required class="bg-white border border-gray-300 rounded-md px-3 py-1.5 text-sm text-gray-900 min-w-[200px]">
                        <option value="">— Select —</option>
                        <?php
                        $currentCategory = '';
                        foreach ($products as $p):
                            if ($p['category'] !== $currentCategory):
                                if ($currentCategory !== ''): ?>
                                    </optgroup>
                                <?php endif;
                                $currentCategory = $p['category']; ?>
                                <optgroup label="<?= htmlspecialchars($p['category']) ?>">
                            <?php endif; ?>
                            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?> ($<?= number_format((float)$p['price'], 2) ?>)</option>
                        <?php endforeach;
                        if ($currentCategory !== ''): ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <button type="submit" class="text-gray-600 border border-gray-300 hover:bg-gray-50 rounded-md px-3 py-1.5 text-sm">Add</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

            </div>
        </div>
    </div>

</body>
</html>