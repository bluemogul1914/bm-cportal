<?php
/**
 * FrontierASRReceiver
 * Parses inbound SOAP responses posted by Frontier to our CLEC endpoint.
 * On COMP (completed) status, triggers UCRM billing automation.
 */
class FrontierASRReceiver {

    private Logger       $logger;
    private OrderManager $orderManager;
    private array        $config;

    public function __construct(Logger $logger, OrderManager $orderManager, array $config = []) {
        $this->logger       = $logger;
        $this->orderManager = $orderManager;
        $this->config       = $config;
    }

    public function handle(string $rawBody, string $action = 'order'): string {
        $this->logger->info("Received inbound Frontier SOAP [{$action}]");
        $this->logger->debug("Payload: " . $rawBody);

        $parsed = $this->parse($rawBody);

        if (empty($parsed)) {
            $this->logger->error("Failed to parse Frontier response body.");
            return $this->soapFault('Client', 'Unable to parse request body.');
        }

        $this->orderManager->updateFromResponse($parsed);

        $pon    = $parsed['pon']    ?? 'UNKNOWN';
        $status = $parsed['status'] ?? '';

        $this->logger->info("Processed response for PON: {$pon} — Status: {$status}");

        // Trigger billing automation when Frontier confirms circuit is complete
        if (strtoupper($status) === 'COMP') {
            $this->logger->info("[Billing] Frontier COMP for PON {$pon} — triggering UCRM billing.");
            $this->triggerBilling($pon, $parsed);
        }

        return $this->soapAck($pon);
    }

    private function triggerBilling(string $pon, array $parsed): void {
        require_once __DIR__ . '/UcrmBillingManager.php';

        $order = $this->orderManager->find($pon);
        if (!$order) {
            $this->logger->error("[Billing] Order {$pon} not found — billing skipped.");
            return;
        }

        if (!empty($parsed['circuit_id'])) {
            $order['circuit_id'] = $parsed['circuit_id'];
        }

        $billing = new UcrmBillingManager($this->config, $this->logger);
        $results = $billing->handleProvisioned($order);

        $this->logger->info("[Billing] Results for PON {$pon}: " . json_encode($results));
        $this->orderManager->setBillingResult($pon, $results);
    }

    private function parse(string $xml): array {
        if (empty(trim($xml))) return [];

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) {
            $this->logger->error('XML parse error: ' . print_r(libxml_get_errors(), true));
            return [];
        }

        $doc->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $doc->registerXPathNamespace('asr',  'http://www.atis.org/tml/asr');

        return [
            'pon'           => (string) ($doc->xpath('//asr:PON')[0]          ?? ''),
            'status'        => (string) ($doc->xpath('//asr:Status')[0]       ?? ''),
            'activity_code' => (string) ($doc->xpath('//asr:ActivityCode')[0] ?? ''),
            'ccna'          => (string) ($doc->xpath('//asr:CCNA')[0]         ?? ''),
            'due_date'      => (string) ($doc->xpath('//asr:DDD')[0]          ?? ''),
            'circuit_id'    => (string) ($doc->xpath('//asr:CircuitID')[0]    ?? ''),
            'errors'        => array_map('strval', $doc->xpath('//asr:ErrorCode') ?: []),
            'remarks'       => (string) ($doc->xpath('//asr:Remarks')[0]      ?? ''),
            'received_at'   => date('Y-m-d H:i:s'),
            'raw'           => $xml,
        ];
    }

    private function soapAck(string $pon): string {
        $pon = htmlspecialchars($pon);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <ASRAcknowledgement>
      <Status>Received</Status>
      <PON>{$pon}</PON>
      <Timestamp>{$this->now()}</Timestamp>
    </ASRAcknowledgement>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function soapFault(string $code, string $message): string {
        $message = htmlspecialchars($message);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <soapenv:Fault>
      <faultcode>{$code}</faultcode>
      <faultstring>{$message}</faultstring>
    </soapenv:Fault>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
