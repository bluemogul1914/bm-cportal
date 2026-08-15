<?php
/**
 * OrderManager
 * Simple JSON file-based persistence for ASR orders.
 * In production, swap this out for a database-backed store.
 */
class OrderManager {

    private string $dataFile;

    public function __construct(string $dataDir) {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        $this->dataFile = rtrim($dataDir, '/') . '/orders.json';
        if (!file_exists($this->dataFile)) {
            file_put_contents($this->dataFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function create(array $orderData): array {
        $orders = $this->all();
        $pon    = $orderData['pon'] ?? $this->generatePON();

        $order = array_merge([
            'pon'          => $pon,
            'type'         => 'ORDER',           // ORDER | PRE-ORDER
            'activity_code'=> 'N',
            'status'       => 'SUBMITTED',
            'address_line1'=> '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'account_number'=> '',
            'contact_name' => 'Tracy Williams',
            'contact_phone'=> '3463095514',
            'contact_email'=> 'tracy.williams@bluemogul.biz',
            'desired_due_date' => '',
            'circuit_id'   => '',
            'errors'       => [],
            'remarks'      => '',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'frontier_response' => null,
        ], $orderData, ['pon' => $pon]);

        $orders[$pon] = $order;
        $this->save($orders);
        return $order;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function all(): array {
        $json = file_get_contents($this->dataFile);
        return json_decode($json, true) ?: [];
    }

    public function find(string $pon): ?array {
        return $this->all()[$pon] ?? null;
    }

    public function recent(int $limit = 50): array {
        $orders = array_values($this->all());
        usort($orders, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return array_slice($orders, 0, $limit);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function updateStatus(string $pon, string $status): void {
        $orders = $this->all();
        if (isset($orders[$pon])) {
            $orders[$pon]['status']     = $status;
            $orders[$pon]['updated_at'] = date('Y-m-d H:i:s');
            $this->save($orders);
        }
    }

    public function updateFromResponse(array $parsed): void {
        $pon = $parsed['pon'] ?? '';
        if (empty($pon)) return;

        $orders = $this->all();

        if (!isset($orders[$pon])) {
            // Frontier sent a response for an order we don't have locally — create stub
            $orders[$pon] = [
                'pon'        => $pon,
                'type'       => 'ORDER',
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }

        $orders[$pon]['status']             = $this->mapStatus($parsed['status'] ?? '');
        $orders[$pon]['circuit_id']         = $parsed['circuit_id']  ?? $orders[$pon]['circuit_id'] ?? '';
        $orders[$pon]['errors']             = $parsed['errors']       ?? [];
        $orders[$pon]['remarks']            = $parsed['remarks']      ?? '';
        $orders[$pon]['frontier_response']  = $parsed;
        $orders[$pon]['updated_at']         = date('Y-m-d H:i:s');

        $this->save($orders);
    }

    public function setBillingResult(string $pon, array $results): void {
        $orders = $this->all();
        if (isset($orders[$pon])) {
            $orders[$pon]['billing_result'] = $results;
            $orders[$pon]['updated_at']     = date('Y-m-d H:i:s');
            $this->save($orders);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function save(array $orders): void {
        file_put_contents($this->dataFile, json_encode($orders, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function generatePON(): string {
        return 'BMR-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }

    private function mapStatus(string $frontierStatus): string {
        $map = [
            'COMP' => 'COMPLETED',
            'JDTO' => 'DUE DATE JEOPARDY',
            'SUSP' => 'SUSPENDED',
            'CANC' => 'CANCELLED',
            'RECV' => 'RECEIVED',
            'ERR'  => 'ERROR',
        ];
        $upper = strtoupper($frontierStatus);
        return $map[$upper] ?? $upper ?: 'UNKNOWN';
    }
}
