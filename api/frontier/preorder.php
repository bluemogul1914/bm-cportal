<?php
/**
 * POST /api/frontier/preorder
 * Address availability check via processSyncRequest
 *
 * Body (JSON): { address, city, state, zip, pon? }
 */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Include confirmed-working SOAP client from existing v10 files
require_once __DIR__ . '/../../frontier-asr-v10/src/FrontierASRClient.php';

$body = json_decode(file_get_contents($_SERVER['REQUEST_BODY_FILE'] ?? 'php://input'), true) ?: [];
$address = trim($body['address'] ?? '');
$city    = trim($body['city']    ?? '');
$state   = trim($body['state']  ?? '');
$zip     = trim($body['zip']    ?? '');

if (!$address || !$city || !$state || !$zip) {
    http_response_code(400);
    echo json_encode(['error' => 'address, city, state, zip required']);
    exit;
}

$env   = getenv('FRONTIER_ENV') ?: 'TEST';
$pon   = $body['pon'] ?? ('PREQ-' . strtoupper(substr(md5(uniqid()), 0, 8)));
$ccna  = 'BMR';
$icsc  = 'FV03';
$asogv = '70';
$pnum  = 'EPAV007BMRSCM378';

// Build inner XML using confirmed sampleASRPON.xml structure
$now     = new DateTime('now', new DateTimeZone('UTC'));
$msgId   = $pon;
$ts      = $now->format('Y-m-d\TH:i:sP');
$dateSent = $now->format('Ymd');
$timeSent = $now->format('His');

$xe = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');

$innerXml = implode('', [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<ASR_SERVICE_REQUEST xmlns="http://www.atis.org/OBF/ASR/UOM-ASR">',
    '<header><senderid>BLUEMO</senderid><receiverid>FRONTIER</receiverid></header>',
    '<HDR>',
      '<MESSAGE_ID>' . $xe($msgId)   . '</MESSAGE_ID>',
      '<CCNA>'       . $xe($ccna)    . '</CCNA>',
      '<MSG_TIMESTAMP>' . $xe($ts)   . '</MSG_TIMESTAMP>',
      '<ICSC>'       . $xe($icsc)    . '</ICSC>',
      '<ASOG_VER>'   . $xe($asogv)   . '</ASOG_VER>',
      '<SERVICETYPE>Standalone EVC:STANDALONE_EVC_SVC</SERVICETYPE>',
    '</HDR>',
    '<STANDALONE_EVC_SVC>',
      '<ASR>',
        '<ADMIN>',
          '<CCNA>'    . $xe($ccna)     . '</CCNA>',
          '<PON>'     . $xe($pon)      . '</PON>',
          '<VER>00</VER>',
          '<ICSC>'    . $xe($icsc)     . '</ICSC>',
          '<D_SENT>'  . $xe($dateSent) . '</D_SENT>',
          '<T_SENT>'  . $xe($timeSent) . '</T_SENT>',
          '<REQTYP>PC</REQTYP>',
          '<ACT>N</ACT>',
          '<EVCI>A</EVCI><RTR>F</RTR><PIU>100</PIU><QTY>1</QTY><BAN>E</BAN>',
        '</ADMIN>',
        '<BILLING>',
          '<ACNA>' . $xe($ccna) . '</ACNA>',
          '<FUSF>E</FUSF>',
          '<PNUM>' . $xe($pnum) . '</PNUM>',
        '</BILLING>',
        '<CONTACT>',
          '<INIT>Tracy Williams</INIT>',
          '<INITIATOR_TEL>3463095514</INITIATOR_TEL>',
          '<INIT_EMAIL>tracy.williams@bluemogul.biz</INIT_EMAIL>',
          '<DSGCON>Tracy Williams</DSGCON><DSGCON_TEL>3463095514</DSGCON_TEL>',
          '<IMPCON>Tracy Williams</IMPCON><IMPCON_TEL>3463095514</IMPCON_TEL>',
        '</CONTACT>',
      '</ASR>',
      '<EVC><EVC_DETAILS>',
        '<EVCNUM>0001</EVCNUM><NC>VLP-</NC><NUT>02</NUT>',
        '<UNI_MAPPING><UREF>01</UREF><UACT>N</UACT><NCI>02VLN.VP</NCI>',
          '<LREF_MAPPING><LREF>1</LREF><LOSACT>N</LOSACT><LOS>RT</LOS></LREF_MAPPING>',
        '</UNI_MAPPING>',
      '</EVC_DETAILS></EVC>',
    '</STANDALONE_EVC_SVC>',
    '</ASR_SERVICE_REQUEST>',
]);

// Build SOAP envelope
$endpoint  = $env === 'PRODUCTION'
    ? 'https://ep.frontier.com/asrtmlwebservice/services/asrport'
    : 'https://epclec.frontier.com/asrtmlwebservice/services/asrport';

$messageId = 'BMR-' . strtoupper(substr(md5(uniqid()), 0, 8));
$soapTs    = gmdate('Y-m-d\TH:i:s\Z');

$envelope  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
    . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
    . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
    . '  <soapenv:Header>' . "\n"
    . '    <tml:TMLHeader xmlns:tml="http://tml.t1m1.org/tML.Transport.xsd" soapenv:mustUnderstand="0">' . "\n"
    . '      <tml:Version>1.0</tml:Version>' . "\n"
    . '      <tml:Sender>BLUEMO</tml:Sender>' . "\n"
    . '      <tml:Receiver>FRONTIER</tml:Receiver>' . "\n"
    . '      <tml:MessageId>' . $messageId . '</tml:MessageId>' . "\n"
    . '      <tml:Timestamp>' . $soapTs . '</tml:Timestamp>' . "\n"
    . '      <tml:MessageType>ASR</tml:MessageType>' . "\n"
    . '    </tml:TMLHeader>' . "\n"
    . '  </soapenv:Header>' . "\n"
    . '  <soapenv:Body>' . "\n"
    . '    <ns1:processSyncRequest xmlns:ns1="java:asr.webservice.wisor.com">' . "\n"
    . '      <string><![CDATA[' . $innerXml . ']]></string>' . "\n"
    . '    </ns1:processSyncRequest>' . "\n"
    . '  </soapenv:Body>' . "\n"
    . '</soapenv:Envelope>';

// Send via cURL
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $envelope,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: text/xml; charset=UTF-8',
        'SOAPAction: ""',
        'Content-Length: ' . strlen($envelope),
    ],
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// Parse response
$faultCode = $faultString = '';
$available = null;
$respPon   = '';

if ($response) {
    $getTag = function($tag) use ($response) {
        if (preg_match('/<(?:[^:>]+:)?' . $tag . '[^>]*>([^<]+)</', $response, $m))
            return trim($m[1]);
        return '';
    };
    $fc = $getTag('faultcode');
    if ($fc) {
        $faultCode   = strpos($fc, ':') !== false ? substr($fc, strpos($fc,':')+1) : $fc;
        $faultString = $getTag('faultstring');
    }
    $avail     = $getTag('AVAIL');
    $available = $avail === 'Y' ? true : ($avail === 'N' ? false : null);
    $respPon   = $getTag('PON');
}

$success = $httpCode === 200 && !$faultCode;
http_response_code($success ? 200 : 502);
echo json_encode([
    'success'     => $success,
    'http_code'   => $httpCode,
    'pon'         => $respPon ?: $pon,
    'available'   => $available,
    'fault_code'  => $faultCode  ?: null,
    'fault'       => $faultString ?: null,
    'env'         => $env,
    'curl_error'  => $curlErr ?: null,
]);
