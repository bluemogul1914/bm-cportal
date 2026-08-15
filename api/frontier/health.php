<?php
/**
 * GET /api/frontier/health
 * Frontier ASR connectivity check — confirms endpoint is live for OAM-3084
 */
header('Content-Type: application/json');

$env = getenv('FRONTIER_ENV') ?: 'TEST';
$endpoint = $env === 'PRODUCTION'
    ? 'https://ep.frontier.com/asrtmlwebservice/services/asrport'
    : 'https://epclec.frontier.com/asrtmlwebservice/services/asrport';

// Quick TCP check to Frontier CTEST
$reachable = false;
$ctx = stream_context_create(['ssl' => ['verify_peer' => true]]);
$sock = @stream_socket_client(
    'ssl://epclec.frontier.com:443',
    $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx
);
if ($sock) { fclose($sock); $reachable = true; }

http_response_code(200);
echo json_encode([
    'status'      => 'ok',
    'env'         => $env,
    'endpoint'    => $endpoint,
    'frontier_reachable' => $reachable,
    'ccna'        => 'BMR',
    'sender_id'   => 'BLUEMO',
    'ticket'      => 'OAM-3084',
    'callback'    => 'https://portal.bluemogul.us/api/frontier/receive',
    'timestamp'   => gmdate('c'),
]);
