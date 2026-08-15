<?php
/**
 * POST /api/frontier/receive
 * Inbound SOAP callback FROM Frontier (OAM-3084 callback URL)
 * Frontier posts ASR status updates here — always return 200
 */

$rawXML = file_get_contents('php://input');
$timestamp = gmdate('c');

// Log to file for audit trail
$logDir  = __DIR__ . '/../../logs';
$logFile = $logDir . '/frontier-receive.log';

if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$entry = json_encode([
    'time'    => $timestamp,
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'length'  => strlen($rawXML),
    'payload' => $rawXML,
]) . "\n";

@file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

// Parse key fields from the SOAP response
$pon    = '';
$status = '';
$evcId  = '';

if ($rawXML) {
    $getTag = function($tag) use ($rawXML) {
        if (preg_match('/<(?:[^:>]+:)?' . $tag . '[^>]*>([^<]+)</', $rawXML, $m))
            return trim($m[1]);
        return '';
    };
    $pon    = $getTag('PON');
    $status = $getTag('STATUS') ?: $getTag('REQTYP');
    $evcId  = $getTag('EVCID');
}

// Include DB class if available to update order status
$dbClass = __DIR__ . '/../../frontier-asr-v10/src/FrontierASRClient.php';
// DB update handled via log for now — full DB integration in next sprint

// Log summary
if ($pon) {
    @file_put_contents($logFile,
        json_encode(['time'=>$timestamp,'event'=>'PARSED','pon'=>$pon,'status'=>$status,'evc_id'=>$evcId]) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// Always ACK 200 to Frontier — never let them retry
header('Content-Type: text/xml; charset=UTF-8');
http_response_code(200);
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">';
echo '<soapenv:Body><ack>OK</ack></soapenv:Body>';
echo '</soapenv:Envelope>';
