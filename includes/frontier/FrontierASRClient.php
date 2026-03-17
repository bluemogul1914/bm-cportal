<?php
/**
 * FrontierASRClient
 * Sends ASR UOM Order and Pre-Order requests to Frontier via SOAP.
 */
class FrontierASRClient {

    const TEST_URL  = 'https://epclec.frontier.com/asrtmlwebservice/services/asrport';
    const PROD_URL  = 'https://ep.frontier.com/asrtmlwebservice/services/asrport';

    private string $endpointUrl;
    private string $ccna;
    private string $sourceIp;
    private $logger;

    public function __construct(array $config, $logger) {
        $this->endpointUrl = ($config['environment'] === 'PRODUCTION') ? self::PROD_URL : self::TEST_URL;
        $this->ccna        = $config['ccna']      ?? 'BMR';
        $this->sourceIp    = $config['source_ip'] ?? '149.28.124.240';
        $this->logger      = $logger;
    }

    /**
     * Send an ASR Order request to Frontier.
     */
    public function sendOrder(array $orderData): array {
        $soap = $this->buildOrderEnvelope($orderData);
        return $this->post($soap, 'ORDER');
    }

    /**
     * Send an ASR Pre-Order (availability check) to Frontier.
     */
    public function sendPreOrder(array $orderData): array {
        $soap = $this->buildPreOrderEnvelope($orderData);
        return $this->post($soap, 'PRE-ORDER');
    }

    // -------------------------------------------------------------------------
    // SOAP Envelope Builders
    // -------------------------------------------------------------------------

    private function buildOrderEnvelope(array $d): string {
        $activityCode = htmlspecialchars($d['activity_code'] ?? 'N');  // N=New, C=Change, D=Disconnect
        $ccna         = htmlspecialchars($this->ccna);
        $pon          = htmlspecialchars($d['pon']           ?? '');    // Purchase Order Number
        $an           = htmlspecialchars($d['account_number']?? '');
        $ddd          = htmlspecialchars($d['desired_due_date'] ?? '');
        $addressLine1 = htmlspecialchars($d['address_line1'] ?? '');
        $city         = htmlspecialchars($d['city']          ?? '');
        $state        = htmlspecialchars($d['state']         ?? '');
        $zip          = htmlspecialchars($d['zip']           ?? '');
        $contactName  = htmlspecialchars($d['contact_name']  ?? 'Tracy Williams');
        $contactPhone = htmlspecialchars($d['contact_phone'] ?? '3463095514');
        $contactEmail = htmlspecialchars($d['contact_email'] ?? 'tracy.williams@bluemogul.biz');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:asr="http://www.atis.org/tml/asr">
  <soapenv:Header/>
  <soapenv:Body>
    <asr:ASRRequest>
      <asr:Header>
        <asr:ActivityCode>{$activityCode}</asr:ActivityCode>
        <asr:CCNA>{$ccna}</asr:CCNA>
        <asr:PON>{$pon}</asr:PON>
        <asr:AN>{$an}</asr:AN>
        <asr:DDD>{$ddd}</asr:DDD>
      </asr:Header>
      <asr:ServiceAddress>
        <asr:AddressLine1>{$addressLine1}</asr:AddressLine1>
        <asr:City>{$city}</asr:City>
        <asr:State>{$state}</asr:State>
        <asr:Zip>{$zip}</asr:Zip>
      </asr:ServiceAddress>
      <asr:Contact>
        <asr:Name>{$contactName}</asr:Name>
        <asr:Phone>{$contactPhone}</asr:Phone>
        <asr:Email>{$contactEmail}</asr:Email>
      </asr:Contact>
    </asr:ASRRequest>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function buildPreOrderEnvelope(array $d): string {
        $ccna         = htmlspecialchars($this->ccna);
        $pon          = htmlspecialchars($d['pon']           ?? '');
        $addressLine1 = htmlspecialchars($d['address_line1'] ?? '');
        $city         = htmlspecialchars($d['city']          ?? '');
        $state        = htmlspecialchars($d['state']         ?? '');
        $zip          = htmlspecialchars($d['zip']           ?? '');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:asr="http://www.atis.org/tml/asr">
  <soapenv:Header/>
  <soapenv:Body>
    <asr:ASRPreOrderRequest>
      <asr:Header>
        <asr:CCNA>{$ccna}</asr:CCNA>
        <asr:PON>{$pon}</asr:PON>
      </asr:Header>
      <asr:ServiceAddress>
        <asr:AddressLine1>{$addressLine1}</asr:AddressLine1>
        <asr:City>{$city}</asr:City>
        <asr:State>{$state}</asr:State>
        <asr:Zip>{$zip}</asr:Zip>
      </asr:ServiceAddress>
    </asr:ASRPreOrderRequest>
  </soapenv:Body>
</soapenv:Envelope>
XML;
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
        $this->logger->debug("Response: " . $response);

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'response'  => $response,
            'parsed'    => $this->parseResponse($response),
        ];
    }

    private function parseResponse(string $xml): array {
        if (empty($xml)) return [];
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) return ['raw' => $xml];

        $doc->registerXPathNamespace('asr', 'http://www.atis.org/tml/asr');
        $status = (string) ($doc->xpath('//asr:Status')[0] ?? '');
        $pon    = (string) ($doc->xpath('//asr:PON')[0]    ?? '');
        $errs   = $doc->xpath('//asr:ErrorCode');
        $errors = array_map('strval', $errs ?: []);

        return compact('status', 'pon', 'errors');
    }
}
