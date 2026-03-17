<?php
/**
 * PortalOrderManager — stores Frontier ASR orders in PostgreSQL (portal DB).
 */
class PortalOrderManager {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function create(array $orderData, ?int $clientId = null): string {
        $pon = $orderData['pon'] ?? $this->generatePON();
        $this->pdo->prepare("INSERT INTO frontier_orders
            (pon, client_id, activity_code, address_line1, city, state, zip,
             account_number, desired_due_date, contact_name, contact_phone,
             contact_email, status, type, raw_request, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'PENDING',?,?,NOW(),NOW())")
            ->execute([
                $pon,
                $clientId,
                $orderData['activity_code'] ?? 'N',
                $orderData['address_line1']  ?? '',
                $orderData['city']           ?? '',
                $orderData['state']          ?? '',
                $orderData['zip']            ?? '',
                $orderData['account_number'] ?? '',
                $orderData['desired_due_date'] ?? '',
                $orderData['contact_name']   ?? '',
                $orderData['contact_phone']  ?? '',
                $orderData['contact_email']  ?? '',
                $orderData['type']           ?? 'ORDER',
                json_encode($orderData),
            ]);
        return $pon;
    }

    public function updateFromFrontierResponse(string $pon, array $parsed): void {
        $status    = $this->mapStatus($parsed['status'] ?? '');
        $circuitId = $parsed['circuit_id'] ?? '';
        $errors    = json_encode($parsed['errors'] ?? []);
        $remarks   = $parsed['remarks'] ?? '';
        $raw       = json_encode($parsed);

        $this->pdo->prepare("UPDATE frontier_orders SET
            status = ?, circuit_id = ?, errors = ?, remarks = ?,
            raw_response = ?, updated_at = NOW()
            WHERE pon = ?")
            ->execute([$status, $circuitId, $errors, $remarks, $raw, $pon]);
    }

    public function setBillingResult(string $pon, array $results): void {
        $this->pdo->prepare("UPDATE frontier_orders SET billing_result = ?, updated_at = NOW() WHERE pon = ?")
            ->execute([json_encode($results), $pon]);
    }

    public function get(string $pon): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM frontier_orders WHERE pon = ?");
        $stmt->execute([$pon]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function recent(int $limit = 100, ?int $clientId = null): array {
        if ($clientId !== null) {
            $stmt = $this->pdo->prepare("SELECT fo.*, c.name as client_name FROM frontier_orders fo LEFT JOIN clients c ON fo.client_id = c.id WHERE fo.client_id = ? ORDER BY fo.created_at DESC LIMIT ?");
            $stmt->execute([$clientId, $limit]);
        } else {
            $stmt = $this->pdo->prepare("SELECT fo.*, c.name as client_name FROM frontier_orders fo LEFT JOIN clients c ON fo.client_id = c.id ORDER BY fo.created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function counts(): array {
        $rows = $this->pdo->query("SELECT status, COUNT(*) as cnt FROM frontier_orders GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['total' => 0];
        foreach ($rows as $r) {
            $counts[$r['status']] = (int)$r['cnt'];
            $counts['total'] += (int)$r['cnt'];
        }
        return $counts;
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
        return $map[$upper] ?? ($upper ?: 'UNKNOWN');
    }
}
