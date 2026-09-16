<?php
/**
 * Shared service-ordering helper — the single source of truth for "add a service".
 *
 * Invoice-gated activation: ordering a service ALWAYS creates a PENDING
 * subscription plus an UNPAID first-month invoice. It only becomes ACTIVE once
 * that invoice is paid:
 *   - from the prepaid wallet  (balance >= price  -> debit wallet, mark paid, activate)
 *   - otherwise via Stripe      (caller mints a Checkout for the invoice; the
 *     Node poll processPendingServiceInvoices() activates it on payment)
 *
 * Every add-service path (admin-services.php, admin-client-services.php, and any
 * future one) MUST call order_service() so no service can be created without an
 * invoice.
 *
 * @param string $internalOrigin Loopback Node origin (for minting a Stripe Checkout when the wallet is short).
 * @param ?string $startDate     Subscription start date (defaults to today).
 * @return array{
 *   status:string, subscription_id:int, invoice_id:int, invoice_number:string,
 *   amount:float, product_name:string, balance_after?:float, pay_url:string
 * }
 */
function order_service(PDO $pdo, int $clientId, int $productId, string $internalOrigin = '', ?string $startDate = null): array
{
    // 0) product + client balance
    $pStmt = $pdo->prepare("SELECT name, price FROM products WHERE id = :id");
    $pStmt->execute([':id' => $productId]);
    $product = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    $price = (float)$product['price'];

    $bStmt = $pdo->prepare("SELECT COALESCE(credit_balance, 0) AS bal FROM clients WHERE id = :id");
    $bStmt->execute([':id' => $clientId]);
    $balance = (float)($bStmt->fetchColumn() ?: 0);

    // 1) PENDING subscription (not active until the invoice is paid)
    $stmt = $pdo->prepare("
        INSERT INTO subscriptions (client_id, product_id, status, start_date, mrr, created_at, updated_at)
        VALUES (:client_id, :product_id, 'pending', :start_date, :price, NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([
        ':client_id'  => $clientId,
        ':product_id' => $productId,
        ':start_date' => $startDate ?: date('Y-m-d'),
        ':price'      => $price,
    ]);
    $subscriptionId = (int)$stmt->fetchColumn();

    // 2) invoice number via invoice_sequences
    $seqStmt = $pdo->prepare("
        INSERT INTO invoice_sequences (client_id, seq) VALUES (:cid, 1)
        ON CONFLICT (client_id) DO UPDATE SET seq = invoice_sequences.seq + 1
        RETURNING seq
    ");
    $seqStmt->execute([':cid' => $clientId]);
    $seq = (int)$seqStmt->fetchColumn();
    $invoiceNumber = "INV-{$clientId}-" . date('Ymd') . "-" . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    // 3) UNPAID invoice linked to the subscription
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

    $result = [
        'status'         => 'pending',
        'subscription_id' => $subscriptionId,
        'invoice_id'     => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'amount'         => $price,
        'product_name'   => $product['name'],
        'pay_url'        => '',
    ];

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

        $result['status'] = 'active';
        $result['balance_after'] = $after;
    } elseif ($internalOrigin !== '') {
        // INSUFFICIENT BALANCE: mint a Stripe Checkout for the invoice (best effort)
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
            if (!empty($data['url'])) {
                $result['pay_url'] = $data['url'];
            }
        }
    }

    return $result;
}

/** Human-readable summary of an order_service() result, for flash messages. */
function order_service_message(array $r): string
{
    if (($r['status'] ?? '') === 'active') {
        return htmlspecialchars($r['product_name']) . ' activated — $' . number_format((float)$r['amount'], 2)
            . ' paid from the prepaid balance.';
    }
    $base = 'Invoice ' . htmlspecialchars($r['invoice_number']) . ' created for $' . number_format((float)$r['amount'], 2);
    if (!empty($r['pay_url'])) {
        return $base . ' — <a href="' . htmlspecialchars($r['pay_url'])
            . '" target="_blank" class="underline text-blue-600">Pay now to activate</a>.';
    }
    return $base . ' (unpaid). ' . htmlspecialchars($r['product_name']) . ' is Pending and activates once the invoice is paid.';
}