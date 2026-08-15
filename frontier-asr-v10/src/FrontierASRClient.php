<?php
/**
 * FrontierASRClient — v10
 *
 * Sends ASR UOM requests to Frontier via SOAP (Apache Axis / RPC-literal).
 *
 * CONFIRMED WORKING STRUCTURE (from CTEST debug sessions Apr 2026):
 *   - Namespace  : java:asr.webservice.wisor.com
 *   - Pre-Order  : processSyncRequest  → <in0> CDATA-wrapped <PreOrder> XML
 *   - Order      : processAsyncRequest → <in0> CDATA-wrapped <ASR> XML
 *   - TML Header : Sender=BLUEMO, Receiver=FRONTIER, mustUnderstand="0"
 *   - CCNA       : BMR  |  SCM: SCM378
 */
class FrontierASRClient {

    const TEST_URL = 'https://epclec.frontier.com/asrtmlwebservice/services/asrport';
    const PROD_URL = 'https://ep.frontier.com/asrtmlwebservice/services/asrport';

    const SENDER   = 'BLUEMO';
    const RECEIVER = 'FRONTIER';
    const SCM      = 'SCM378';

    private string $endpointUrl;
    private string $ccna;
    private string $sourceIp;
    private Logger $logger;

    public function __construct(array $config, Logger $logger) {
        $this->endpointUrl = ($config['environment'] === 'PRODUCTION') ? self::PROD_URL : self::TEST_URL;
        $this->ccna        = strtoupper(trim($config['ccna']      ?? 'BMR'));
        $this->sourceIp    = $config['source_ip'] ?? '149.28.124.240';
        $this->logger      = $logger;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** Send a full ASR Order (processAsyncRequest) */
    public function sendOrder(array $orderData): array {
        $soap = $this->buildOrderEnvelope($orderData);
        return $this->post($soap, 'ORDER');
    }

    /** Send an ASR Pre-Order / availability check (processSyncRequest) */
    public function sendPreOrder(array $orderData): array {
        $soap = $this->buildPreOrderEnvelope($orderData);
        return $this->post($soap, 'PRE-ORDER');
    }

    // -------------------------------------------------------------------------
    // Envelope Builders
    // -------------------------------------------------------------------------

    /**
     * Pre-Order: processSyncRequest
     * Checks address availability before committing a full order.
     * Inner payload: <PreOrder> with CCNA, PON, and location fields.
     */
    private function buildPreOrderEnvelope(array $d): string {
        $messageId = 'BMR-PRE-' . strtoupper(substr(md5(uniqid()), 0, 8));
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $ccna  = $this->ccna;
        $pon   = $d['pon']           ?? ('PREQ-' . strtoupper(substr(md5(uniqid()), 0, 8)));
        $addr  = $d['address_line1'] ?? '';
        $city  = $d['city']          ?? '';
        $state = $d['state']         ?? '';
        $zip   = $d['zip']           ?? '';

        $innerXml = implode('', [
            '<PreOrder>',
            '<CCNA>'     . htmlspecialchars($ccna,  ENT_XML1) . '</CCNA>',
            '<PON>'      . htmlspecialchars($pon,   ENT_XML1) . '</PON>',
            '<LOCADDR>'  . htmlspecialchars($addr,  ENT_XML1) . '</LOCADDR>',
            '<LOCCITY>'  . htmlspecialchars($city,  ENT_XML1) . '</LOCCITY>',
            '<LOCSTATE>' . htmlspecialchars($state, ENT_XML1) . '</LOCSTATE>',
            '<LOCZIP>'   . htmlspecialchars($zip,   ENT_XML1) . '</LOCZIP>',
            '</PreOrder>',
        ]);

        return $this->wrapEnvelope($messageId, $timestamp, 'processSyncRequest', $innerXml);
    }

    /**
     * Full Order: processAsyncRequest
     * Submits a new service, change, or disconnect order to Frontier.
     * Inner payload: <ASR> with all required ATIS-T1.413 fields.
     */
    private function buildOrderEnvelope(array $d): string {
        $messageId = 'BMR-ORD-' . strtoupper(substr(md5(uniqid()), 0, 8));
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $ccna         = $this->ccna;
        $pon          = $d['pon']              ?? ('ASR-' . strtoupper(substr(md5(uniqid()), 0, 8)));
        $actCode      = strtoupper($d['activity_code']   ?? 'N');
        $an           = $d['account_number']   ?? '';
        $ddd          = $d['desired_due_date'] ?? '';
        $addr         = $d['address_line1']    ?? '';
        $city         = $d['city']             ?? '';
        $state        = $d['state']            ?? '';
        $zip          = $d['zip']              ?? '';
        $contactName  = $d['contact_name']     ?? 'Tracy Williams';
        $contactPhone = $d['contact_phone']    ?? '3463095514';
        $contactEmail = $d['contact_email']    ?? 'tracy.williams@bluemogul.biz';
        $serviceCode  = $this->resolveServiceCode($d['service_plan'] ?? '');

        $innerXml = implode('', [
            '<ASR>',
            '<CCNA>'     . htmlspecialchars($ccna,         ENT_XML1) . '</CCNA>',
            '<PON>'      . htmlspecialchars($pon,          ENT_XML1) . '</PON>',
            '<ACTCD>'    . htmlspecialchars($actCode,      ENT_XML1) . '</ACTCD>',
            '<SCM>'      . self::SCM . '</SCM>',
            ($an  ? '<AN>'  . htmlspecialchars($an,  ENT_XML1) . '</AN>'  : ''),
            ($ddd ? '<DDD>' . htmlspecialchars($ddd, ENT_XML1) . '</DDD>' : ''),
            '<LOCADDR>'  . htmlspecialchars($addr,         ENT_XML1) . '</LOCADDR>',
            '<LOCCITY>'  . htmlspecialchars($city,         ENT_XML1) . '</LOCCITY>',
            '<LOCSTATE>' . htmlspecialchars($state,        ENT_XML1) . '</LOCSTATE>',
            '<LOCZIP>'   . htmlspecialchars($zip,          ENT_XML1) . '</LOCZIP>',
            ($serviceCode ? '<TERS>' . htmlspecialchars($serviceCode, ENT_XML1) . '</TERS>' : ''),
            '<CONTNM>'   . htmlspecialchars($contactName,  ENT_XML1) . '</CONTNM>',
            '<CONTNO>'   . htmlspecialchars($contactPhone, ENT_XML1) . '</CONTNO>',
            '<CONTEM>'   . htmlspecialchars($contactEmail, ENT_XML1) . '</CONTEM>',
            '</ASR>',
        ]);

        return $this->wrapEnvelope($messageId, $timestamp, 'processAsyncRequest', $innerXml);
    }

    /**
     * Wrap inner XML into the full SOAP envelope with TML header.
     *
     * KEY RULES (learned from CTEST):
     *  1. Parameter element must be <in0>, NOT <string> — Axis dispatches on element name
     *  2. Inner XML must be CDATA-wrapped, NOT entity-encoded — avoids SAXException
     *  3. TML mustUnderstand must be "0" — Frontier ignores auth in CTEST
     */
    private function wrapEnvelope(string $messageId, string $timestamp, string $operation, string $innerXml): string {
        $xmlDecl = chr(60) . chr(63) . 'xml version="1.0" encoding="UTF-8"' . chr(63) . chr(62);

        return $xmlDecl . "\n" . implode("\n", [
            '<soapenv:Envelope',
            '    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"',
            '    xmlns:xsd="http://www.w3.org/2001/XMLSchema"',
            '    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">',
            '  <soapenv:Header>',
            '    <tml:TMLHeader xmlns:tml="http://tml.t1m1.org/tML.Transport.xsd" soapenv:mustUnderstand="0">',
            '      <tml:Version>1.0</tml:Version>',
            '      <tml:Sender>'    . self::SENDER   . '</tml:Sender>',
            '      <tml:Receiver>'  . self::RECEIVER . '</tml:Receiver>',
            '      <tml:MessageId>' . htmlspecialchars($messageId, ENT_XML1) . '</tml:MessageId>',
            '      <tml:Timestamp>' . $timestamp     . '</tml:Timestamp>',
            '      <tml:MessageType>ASR</tml:MessageType>',
            '    </tml:TMLHeader>',
            '  </soapenv:Header>',
            '  <soapenv:Body>',
            '    <ns1:' . $operation . ' xmlns:ns1="java:asr.webservice.wisor.com">',
            '      <in0><![CDATA[' . $innerXml . ']]></in0>',
            '    </ns1:' . $operation . '>',
            '  </soapenv:Body>',
            '</soapenv:Envelope>',
        ]);
    }

    // -------------------------------------------------------------------------
    // HTTP POST via cURL
    // -------------------------------------------------------------------------

    private function post(string $soapBody, string $type): array {
        $this->logger->info("Sending {$type} to Frontier: " . $this->endpointUrl);

        $ch = curl_init($this->endpointUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $soapBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=UTF-8',
                'SOAPAction: ""',
                'Content-Length: ' . strlen($soapBody),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("cURL error sending {$type}: {$error}");
            return ['success' => false, 'error' => $error, 'http_code' => 0];
        }

        $this->logger->info("Frontier responded HTTP {$httpCode} for {$type}");

        $success = ($httpCode === 200);
        $parsed  = $this->parseResponse($response);

        $summary = $parsed['fault_code']
            ? "FAULT {$parsed['fault_code']}: {$parsed['fault_string']}"
            : "status={$parsed['status']} pon={$parsed['pon']}";
        $this->logger->info("{$type} response: {$summary}");

        return [
            'success'   => $success && empty($parsed['fault_code']),
            'http_code' => $httpCode,
            'response'  => $response,
            'parsed'    => $parsed,
        ];
    }

    // -------------------------------------------------------------------------
    // Response Parser — handles SOAP faults AND success responses
    // -------------------------------------------------------------------------

    private function parseResponse(string $xml): array {
        $result = [
            'status'       => '',
            'pon'          => '',
            'errors'       => [],
            'available'    => null,    // pre-order: true/false/null
            'fault_code'   => '',
            'fault_string' => '',
            'raw'          => $xml,
        ];

        if (empty($xml)) return $result;

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) {
            $result['fault_string'] = 'Could not parse XML response';
            return $result;
        }

        // ── SOAP Fault ────────────────────────────────────────────────────────
        $faultCode   = $doc->xpath('//faultcode');
        $faultString = $doc->xpath('//faultstring');
        if (!empty($faultCode)) {
            $code = (string) $faultCode[0];
            // Strip axis namespace prefix e.g. "ns1:Client" → "Client"
            $code = strpos($code, ':') !== false ? substr($code, strpos($code, ':') + 1) : $code;
            $result['fault_code']   = $code;
            $result['fault_string'] = !empty($faultString) ? (string) $faultString[0] : 'Unknown fault';
            $result['status']       = 'FAULT';
            return $result;
        }

        // ── Pre-Order / Sync Success: return element contains inner XML ───────
        $returnNode = $doc->xpath('//*[local-name()="return"]');
        if (!empty($returnNode)) {
            $returnText = (string) $returnNode[0];
            $innerDoc   = simplexml_load_string($returnText);
            if ($innerDoc) {
                $result['status'] = (string) ($innerDoc->xpath('//*[local-name()="STATUS"]')[0] ?? 'RECEIVED');
                $result['pon']    = (string) ($innerDoc->xpath('//*[local-name()="PON"]')[0]    ?? '');
                $avail            = (string) ($innerDoc->xpath('//*[local-name()="AVAIL"]')[0]  ?? '');
                $result['available'] = ($avail === 'Y') ? true : (($avail === 'N') ? false : null);
                $errNodes         = $innerDoc->xpath('//*[local-name()="ERRCD"]');
                $result['errors'] = array_map('strval', $errNodes ?: []);
            } else {
                $result['status'] = trim($returnText) ?: 'RECEIVED';
            }
            return $result;
        }

        // ── Async Order Acknowledgement ───────────────────────────────────────
        $status = $doc->xpath('//*[local-name()="STATUS"]');
        $pon    = $doc->xpath('//*[local-name()="PON"]');
        $errors = $doc->xpath('//*[local-name()="ERRCD"]');

        $result['status'] = !empty($status) ? (string) $status[0] : 'RECEIVED';
        $result['pon']    = !empty($pon)    ? (string) $pon[0]    : '';
        $result['errors'] = array_map('strval', $errors ?: []);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Map service plan labels to Frontier TERS codes.
     * Confirm these against Frontier's product guide — update as needed.
     */
    private function resolveServiceCode(string $plan): string {
        $map = [
            'fixed_wireless_50'     => 'FW050',
            'fixed_wireless_100'    => 'FW100',
            'residential_fiber_100' => 'RF100',
            'residential_fiber_500' => 'RF500',
            'residential_fiber_gig' => 'RF1G',
            'business_fiber_500'    => 'BF500',
            'business_fiber_gig'    => 'BF1G',
            'enterprise_fiber_10g'  => 'BF10G',
        ];
        return $map[strtolower($plan)] ?? '';
    }
}
