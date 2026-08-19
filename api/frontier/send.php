<?php
/**
 * Frontier ASR Module - Outbound Full Order (processAsyncRequest)
 * Processes complete orders after pre-order phase is complete.
 *
 * Endpoint: /api/frontier/send.php
 * Method: POST
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 405, 'message' => 'POST required']);
    exit;
}

define('FRONTIER_ENV', 'TEST');
define('FRONTIER_ENDPOINT', 'https://epclec.frontier.com/asrtmlwebservice/services/asrport');
define('TML_SENDER', 'BLUEMO');
define('TML_RECEIVER', 'FRONTIER');
define('SOURCE_IP', '5.78.87.79');
define('CALLBACK_URL', 'https://portal.bluemogul.us/api/frontier/receive');

// Database configuration
$db_host = 'portal-db';
$db_name = 'bm_client_portal';
$db_user = 'portal_user';
$db_pass = 'f1088d31161df3221d34ff201ec1e19c';
$db_port = '5432';

// Configuration for Frontier SOAP client
define('FRONTIER_WSDL', '/var/www/html/FrontierASR.wsdl');
define('FRONTIER_USERNAME', 'BLUEMO');
define('FRONTIER_PASSWORD', 'SCM378');

function log_message($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
    @file_put_contents('/var/log/frontier/send.log', $log_entry, FILE_APPEND | LOCK_EX);
}

try {
    // Get request body — support both Node.js proxy (REQUEST_BODY_FILE) and direct PHP (php://input)
    $raw_body = file_get_contents($_SERVER['REQUEST_BODY_FILE'] ?? 'php://input');
    $request_data = json_decode($raw_body, true);

    if (!$request_data) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'code' => 400, 'message' => 'Invalid JSON']);
        exit;
    }

    $order_id = $request_data['order_id'] ?? '';
    $customer_id = $request_data['customer_id'] ?? '';
    $order_type = $request_data['order_type'] ?? 'async';
    $order_value = $request_data['order_value'] ?? 0;
    $order_data_xml = $request_data['order_data_xml'] ?? '';
    $request_source = $request_data['request_source'] ?? '';

    // Validate required fields
    if (empty($order_id) || empty($customer_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'code' => 400, 'message' => 'Missing required fields: order_id, customer_id']);
        exit;
    }

    // Check if order exists and is eligible for processing
    $pdo = new PDO(
        "pgsql:host=$db_host;dbname=$db_name;port=$db_port",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    // Check order exists
    $stmt = $pdo->prepare("SELECT id, order_id, customer_id, order_type, status FROM frontier_orders WHERE order_id = :order_id");
    $stmt->execute([':order_id' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'code' => 404, 'message' => 'Order not found']);
        exit;
    }

    // Check if order is eligible for async processing
    $eligible_statuses = ['preorder', 'available', 'pending', 'approved'];
    if (!in_array($order['status'], $eligible_statuses)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['status' => 'error', 'code' => 409, 'message' => 'Order cannot be processed in current state: ' . $order['status']]);
        exit;
    }

    // Prepare order data for Frontier processing
    $order_data = [
        'order_id' => $order_id,
        'customer_id' => $customer_id,
        'order_type' => $order_type,
        'order_value' => $order_value,
        'request_source' => $request_source ?: 'system',
        'request_timestamp' => date('c'),
        'tml_sender' => TML_SENDER,
        'tml_receiver' => TML_RECEIVER,
        'source_ip' => SOURCE_IP,
        'callback_url' => CALLBACK_URL
    ];

    // Build XML payload for Frontier SOAP service
    $xml_payload = build_soap_envelope($order_data);

    // Call Frontier ASR service to process the order
    $result = process_order_with_frontier($xml_payload);

    if ($result['status'] === 'error') {
        $pdo->rollBack();
        http_response_code($result['code'] ?? 500);
        echo json_encode($result);
        exit;
    }

    // Update order status in database
    $stmt = $pdo->prepare(
        "UPDATE frontier_orders SET status = 'processing', order_xml_data = :xml_data WHERE order_id = :order_id"
    );
    $stmt->execute([
        ':xml_data' => $xml_payload,
        ':order_id' => $order_id
    ]);

    // Send callback to Frontier
    $ch = curl_init(CALLBACK_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($result));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Request-Id: ' . uniqid()
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    $pdo->commit();

    log_message('INFO', "Order $order_id processed successfully with status: " . $result['order_status']);

    // Prepare response
    $response_data = [
        'status' => 'success',
        'code' => 200,
        'message' => 'Order processed successfully',
        'order_id' => $order_id,
        'customer_id' => $customer_id,
        'order_status' => $result['order_status'] ?? 'processed',
        'result_code' => $result['result_code'] ?? 'SUCCESS',
        'processing_time' => $result['processing_time'] ?? '0s'
    ];

    echo json_encode($response_data);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_message('ERROR', 'Database error in send: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'code' => 500, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    log_message('ERROR', 'Exception in send: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'code' => 500, 'message' => $e->getMessage()]);
}

function build_soap_envelope($order_data) {
    $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><SOAP-ENV:Envelope xmlns:SOAP-ENV=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:java=\"java:asr.webservice.wisor.com\"></SOAP-ENV:Envelope>");

    $xml->addChild('SOAP-ENV:Header', '');
    $body = $xml->addChild('SOAP-ENV:Body');

    $order_element = $body->addChild('processAsyncRequest', '');
    $order_element->addChild('order_id', $order_data['order_id']);
    $order_element->addChild('customer_id', $order_data['customer_id']);
    $order_element->addChild('order_type', $order_data['order_type']);
    $order_element->addChild('order_value', $order_data['order_value']);
    $order_element->addChild('tml_sender', $order_data['tml_sender']);
    $order_element->addChild('tml_receiver', $order_data['tml_receiver']);
    $order_element->addChild('source_ip', $order_data['source_ip']);
    $order_element->addChild('callback_url', $order_data['callback_url']);

    $result = $xml->asXML();

    return $result;
}

function process_order_with_frontier($xml_payload) {
    $client_url = FRONTIER_ENDPOINT;

    $ch = curl_init($client_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/xml; charset=UTF-8',
        'SOAPAction: "http://java:asr.webservice.wisor.com/processAsyncRequest"',
        'Authorization: Basic ' . base64_encode(FRONTIER_USERNAME . ':' . FRONTIER_PASSWORD),
        'X-Request-Id: ' . uniqid()
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200) {
        log_message('ERROR', "Frontier API returned HTTP $http_code: $curl_error");

        return [
            'status' => 'error',
            'code' => $http_code,
            'message' => "Frontier API error: $curl_error",
            'http_code' => $http_code
        ];
    }

    try {
        $response_xml = new SimpleXMLElement($response);
        $result_code = (string)$response_xml->result->code ?? '';
        $result_message = (string)$response_xml->result->message ?? '';
        $order_status = (string)$response_xml->order_status ?? 'unknown';
        $processing_time = (string)$response_xml->processing_time ?? '0s';

        log_message('INFO', "Order processed with result: $result_code - $result_message");

        return [
            'status' => 'success',
            'order_status' => $order_status,
            'result_code' => $result_code,
            'result_message' => $result_message,
            'processing_time' => $processing_time,
            'http_code' => $http_code
        ];

    } catch (Exception $e) {
        log_message('ERROR', 'Failed to parse Frontier response: ' . $e->getMessage());

        return [
            'status' => 'error',
            'code' => 500,
            'message' => 'Failed to parse Frontier response: ' . $e->getMessage(),
            'http_code' => $http_code,
            'raw_response' => $response
        ];
    }
}
