<?php
/**
 * frontier-receive.php — Public endpoint for Frontier SOAP callbacks.
 * Provide this URL to Frontier Connectivity Management:
 *   https://portal.bluemogul.biz/portal/frontier-receive.php?action=receive
 */
require_once __DIR__ . '/config.php';

$pdo = getDB();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS frontier_orders (id SERIAL PRIMARY KEY, pon VARCHAR(50) UNIQUE NOT NULL, client_id INTEGER, activity_code VARCHAR(5) DEFAULT 'N', address_line1 VARCHAR(255) DEFAULT '', city VARCHAR(100) DEFAULT '', state VARCHAR(50) DEFAULT '', zip VARCHAR(20) DEFAULT '', account_number VARCHAR(100) DEFAULT '', desired_due_date DATE, contact_name VARCHAR(255) DEFAULT '', contact_phone VARCHAR(50) DEFAULT '', contact_email VARCHAR(255) DEFAULT '', status VARCHAR(50) DEFAULT 'PENDING', type VARCHAR(20) DEFAULT 'ORDER', circuit_id VARCHAR(100) DEFAULT '', errors TEXT DEFAULT '[]', remarks TEXT DEFAULT '', raw_request TEXT, raw_response TEXT, billing_result TEXT, invoice_id INTEGER, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW())");
    $pdo->exec("CREATE TABLE IF NOT EXISTS frontier_logs (id SERIAL PRIMARY KEY, level VARCHAR(20) DEFAULT 'info', message TEXT, created_at TIMESTAMP DEFAULT NOW())");
} catch (Exception $e) {}

require_once __DIR__ . '/includes/frontier/PortalOrderManager.php';

$action   = $_GET['action'] ?? 'receive';
$rawBody  = file_get_contents('php://input');
$orderMgr = new PortalOrderManager($pdo);

$log = function(string $level, string $msg) use ($pdo) {
    try { $pdo->prepare("INSERT INTO frontier_logs (level, message) VALUES (?,?)")->execute([$level, $msg]); } catch (Exception $e) {}
};

$log('info', "Frontier callback: action={$action} size=" . strlen($rawBody));

function parseASRResponse(string $xml): array {
    if (empty(trim($xml))) return [];
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    if (!$doc) return [];
    $doc->registerXPathNamespace('asr', 'http://www.atis.org/tml/asr');
    return [
        'pon'        => (string)($doc->xpath('//asr:PON')[0]       ?? ''),
        'status'     => (string)($doc->xpath('//asr:Status')[0]    ?? ''),
        'circuit_id' => (string)($doc->xpath('//asr:CircuitID')[0] ?? ''),
        'errors'     => array_map('strval', $doc->xpath('//asr:ErrorCode') ?: []),
        'remarks'    => (string)($doc->xpath('//asr:Remarks')[0]   ?? ''),
        'available'  => strtoupper((string)($doc->xpath('//asr:Availability')[0] ?? '')) === 'YES',
    ];
}

if ($action === 'receive') {
    $parsed = parseASRResponse($rawBody);
    $pon    = $parsed['pon'] ?? '';

    if ($pon) {
        $orderMgr->updateFromFrontierResponse($pon, $parsed);
        $log('info', "Updated PON {$pon} status={$parsed['status']}");

        $status = strtoupper($parsed['status'] ?? '');
        if ($status === 'COMP' || $status === 'COMPLETED') {
            $order = $orderMgr->get($pon);
            if ($order && $order['client_id'] && !$order['invoice_id']) {
                try {
                    $cid   = (int)$order['client_id'];
                    $today = date('Y-m-d');
                    $due   = date('Y-m-d', strtotime('+30 days'));
                    $desc  = 'Frontier Broadband Service — PON: ' . $pon
                           . ' | Circuit: ' . ($parsed['circuit_id'] ?? 'N/A')
                           . ' | ' . trim("{$order['address_line1']}, {$order['city']}, {$order['state']}");

                    $pdo->prepare("INSERT INTO invoices (client_id, status, issue_date, due_date, notes, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())")
                        ->execute([$cid, 'unpaid', $today, $due, $desc]);
                    $invoiceId = $pdo->lastInsertId();
                    $pdo->prepare("UPDATE frontier_orders SET invoice_id=?, updated_at=NOW() WHERE pon=?")->execute([$invoiceId, $pon]);
                    $log('info', "Invoice #{$invoiceId} auto-created for client {$cid}");
                } catch (Exception $e) {
                    $log('error', 'Invoice auto-create failed: ' . $e->getMessage());
                }
            }
        }
    }

    $ponEsc = htmlspecialchars($pon ?: 'UNKNOWN');
    http_response_code(200);
    header('Content-Type: text/xml');
    echo <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body><ASRAcknowledgement><Status>Received</Status><PON>{$ponEsc}</PON></ASRAcknowledgement></soapenv:Body>
</soapenv:Envelope>
XML;

} elseif ($action === 'preorder') {
    $parsed = parseASRResponse($rawBody);
    $log('info', 'Pre-order callback: ' . json_encode($parsed));
    http_response_code(200);
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><ack>OK</ack></soapenv:Body></soapenv:Envelope>';

} else {
    http_response_code(400);
    echo 'Unknown action';
}
