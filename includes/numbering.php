<?php
/**
 * Unified numbering system for Blue Mogul Suite.
 * Generates numbers using the bm_prefix_registry / next_bm_code() PostgreSQL function.
 * Format: {PREFIX}-{YYYYMMDD}-{NNNN} (e.g., BMA-20261104-0001)
 */

function generate_number(string $prefix, ?PDO $pdo = null): string {
    if ($pdo === null) {
        $pdo = getDB();
    }
    if (!$pdo) {
        return $prefix . '-' . date('Ymd') . '-0001';
    }

    try {
        $stmt = $pdo->prepare("SELECT next_bm_code(?) AS code");
        $stmt->execute([$prefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['code'] ?? ($prefix . '-' . date('Ymd') . '-0001');
    } catch (Exception $e) {
        error_log("generate_number failed: " . $e->getMessage());
        return $prefix . '-' . date('Ymd') . '-0001';
    }
}
?>