<?php
/**
 * UcrmBillingManager
 *
 * Called by FrontierASRReceiver when Frontier sends a COMP (completed)
 * confirmation for an ASR order. Handles:
 *   1. Activating the client's service in UCRM (Quoted → Active)
 *   2. Creating a one-time setup fee invoice item (if configured)
 *   3. Logging all billing actions
 */
class UcrmBillingManager {

    private string $apiKey;
    private string $baseUrl;
    private Logger $logger;
    private array  $config;

    // UCRM service status IDs
    const STATUS_QUOTED    = 4;
    const STATUS_ACTIVE    = 1;
    const STATUS_SUSPENDED = 2;
    const STATUS_ENDED     = 3;

    public function __construct(array $config, Logger $logger) {
        $this->config  = $config;
        $this->apiKey  = $config['ucrm_api_key'] ?? '';
        $this->baseUrl = 'https://uisp.bluemogul.us/crm/api/v1.0';
        $this->logger  = $logger;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public entry point — called on Frontier COMP confirmation
    // ─────────────────────────────────────────────────────────────────────────

    public function handleProvisioned(array $order): array {
        $results = [];

        if (empty($this->apiKey)) {
            $this->logger->error('[Billing] UCRM API key not configured — skipping billing automation.');
            return ['error' => 'UCRM API key not configured'];
        }

        $clientId  = $order['client_id']   ?? null;
        $serviceId = $order['service_id']  ?? null;
        $pon       = $order['pon']         ?? 'UNKNOWN';
        $circuitId = $order['circuit_id']  ?? '';

        $this->logger->info("[Billing] Frontier confirmed PON {$pon} — beginning billing automation.");

        // ── Step 1: Activate the client's service ───────────────────────────
        if ($serviceId) {
            $activateResult = $this->activateService((int)$serviceId, $circuitId);
            $results['activate'] = $activateResult;
            if ($activateResult['success']) {
                $this->logger->info("[Billing] Service ID {$serviceId} activated for client ID {$clientId}.");
            } else {
                $this->logger->error("[Billing] Failed to activate service {$serviceId}: " . ($activateResult['error'] ?? 'unknown'));
            }
        } else {
            $this->logger->info("[Billing] No service_id on order {$pon} — skipping service activation.");
            $results['activate'] = ['skipped' => 'No service_id on order'];
        }

        // ── Step 2: Add one-time setup fee if configured ─────────────────────
        $setupFee = (float)($this->config['setup_fee'] ?? 0);
        if ($clientId && $setupFee > 0) {
            $feeResult = $this->addSetupFeeInvoice((int)$clientId, $setupFee, $pon, $circuitId);
            $results['setup_fee'] = $feeResult;
            if ($feeResult['success']) {
                $this->logger->info("[Billing] Setup fee \${$setupFee} invoiced on client ID {$clientId}.");
            } else {
                $this->logger->error("[Billing] Failed to create setup fee invoice: " . ($feeResult['error'] ?? 'unknown'));
            }
        } else {
            $results['setup_fee'] = ['skipped' => 'No setup fee configured or no client_id'];
        }

        // ── Step 3: Add note to client log ───────────────────────────────────
        if ($clientId) {
            $this->addClientNote((int)$clientId,
                "Frontier circuit provisioned. PON: {$pon}" . ($circuitId ? " | Circuit ID: {$circuitId}" : ''));
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Activate a client service (set status to Active = 1)
    // Optionally stores the Frontier Circuit ID as the service note
    // ─────────────────────────────────────────────────────────────────────────

    private function activateService(int $serviceId, string $circuitId = ''): array {
        // First GET the service so we can PATCH only what changed
        $service = $this->apiGet("/clients/services/{$serviceId}");
        if (!$service) {
            return ['success' => false, 'error' => "Service {$serviceId} not found in UCRM"];
        }

        $patch = ['status' => self::STATUS_ACTIVE];
        if ($circuitId) {
            $existing = $service['note'] ?? '';
            $patch['note'] = trim($existing . "\nFrontier Circuit ID: {$circuitId}");
        }

        $result = $this->apiPatch("/clients/services/{$serviceId}", $patch);
        return ['success' => $result !== null, 'service' => $result];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Create a one-time invoice with a setup fee line item
    // ─────────────────────────────────────────────────────────────────────────

    private function addSetupFeeInvoice(int $clientId, float $amount, string $pon, string $circuitId): array {
        $label = 'Frontier Fiber Setup Fee';
        if ($pon)       $label .= " (PON: {$pon})";
        if ($circuitId) $label .= " | Circuit: {$circuitId}";

        $payload = [
            'clientId'       => $clientId,
            'status'         => 1,           // 1 = unpaid / draft sent
            'items'          => [[
                'label'      => $label,
                'quantity'   => 1,
                'price'      => $amount,
                'unit'       => '',
                'tax1Id'     => null,
                'tax2Id'     => null,
                'tax3Id'     => null,
            ]],
            'maturityDays'   => (int)($this->config['invoice_maturity_days'] ?? 14),
            'notes'          => "Auto-generated by Frontier ASR plugin upon circuit confirmation.",
        ];

        $result = $this->apiPost('/invoices', $payload);
        return ['success' => $result !== null, 'invoice' => $result];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Add a log note on the client's UCRM record
    // ─────────────────────────────────────────────────────────────────────────

    private function addClientNote(int $clientId, string $message): void {
        $this->apiPost('/client-logs', [
            'clientId' => $clientId,
            'message'  => $message,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UCRM REST API helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function apiGet(string $path): ?array {
        return $this->apiRequest('GET', $path);
    }

    private function apiPost(string $path, array $body): ?array {
        return $this->apiRequest('POST', $path, $body);
    }

    private function apiPatch(string $path, array $body): ?array {
        return $this->apiRequest('PATCH', $path, $body);
    }

    private function apiRequest(string $method, string $path, ?array $body = null): ?array {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [
            'X-Auth-App-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->logger->error("[Billing] cURL error on {$method} {$path}: {$err}");
            return null;
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->error("[Billing] API {$method} {$path} returned HTTP {$status}: {$resp}");
            return null;
        }

        return json_decode($resp, true);
    }
}
