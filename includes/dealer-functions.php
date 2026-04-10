<?php
/**
 * includes/dealer-functions.php
 * Blue Mogul bm-cportal — Dealer module shared functions (no HTML output)
 * Include this directly in pages that need functions but not the sidebar HTML.
 */

if (!function_exists('get_db')) {
    function get_db() {
        static $pdo;
        if ($pdo) return $pdo;
        if (!function_exists('getDB')) {
            $cfg = dirname(__FILE__) . '/../config.php';
            if (file_exists($cfg)) require_once $cfg;
        }
        if (function_exists('getDB')) {
            $pdo = getDB();
            return $pdo;
        }
        $dsn = getenv('DATABASE_URL');
        if (!$dsn) throw new RuntimeException('DATABASE_URL not set');
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE         => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }
}

if (!function_exists('dealer_auth')) {
    function dealer_auth() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $is_dealer = (!empty($_SESSION['role'])      && $_SESSION['role']      === 'dealer')
                  || (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'dealer');
        if (!$is_dealer) {
            header('Location: /portal/index.php?session_expired=1');
            exit;
        }
        if (empty($_SESSION['dealer_id']) && !empty($_SESSION['user_id'])) {
            try {
                $r = get_db()->prepare("SELECT id FROM dealers WHERE user_id=?");
                $r->execute([$_SESSION['user_id']]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                if ($row) $_SESSION['dealer_id'] = $row['id'];
            } catch (Exception $e) {}
        }
    }
}

if (!function_exists('dealer_me')) {
    function dealer_me() {
        if (!empty($_SESSION['dealer_cache'])) return $_SESSION['dealer_cache'];
        $dealer_id = $_SESSION['dealer_id'] ?? null;
        if (!$dealer_id) {
            header('Location: /portal/index.php?session_expired=1');
            exit;
        }
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            "SELECT d.id,
                    COALESCE(d.dealer_code, d.referral_code) AS dealer_code,
                    COALESCE(d.full_name, u.name, 'Dealer')  AS full_name,
                    COALESCE(d.email,     u.email)           AS email,
                    COALESCE(d.company,   d.company_name)    AS company,
                    d.tier, d.activations_mtd, d.status,
                    d.ach_routing, d.ach_account, d.phone, d.notes
             FROM dealers d
             LEFT JOIN users u ON d.user_id = u.id
             WHERE d.id = ?"
        );
        $stmt->execute([$dealer_id]);
        $dealer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dealer) {
            session_destroy();
            header('Location: /portal/index.php?session_expired=1');
            exit;
        }
        $_SESSION['dealer_cache'] = $dealer;
        return $dealer;
    }
}

if (!function_exists('dollars')) {
    function dollars($cents) {
        return number_format((int)$cents / 100, 2);
    }
}

if (!function_exists('tier_badge')) {
    function tier_badge($tier) {
        $map = [
            'base'   => ['label' => 'Base',   'class' => 'badge-gray'],
            'silver' => ['label' => 'Silver', 'class' => 'badge-silver'],
            'gold'   => ['label' => 'Gold',   'class' => 'badge-gold'],
        ];
        $t = $map[$tier] ?? $map['base'];
        return '<span class="badge ' . $t['class'] . '">' . $t['label'] . '</span>';
    }
}

if (!function_exists('status_badge')) {
    function status_badge($status) {
        $map = [
            'submitted'         => 'badge-gray',
            'payment_confirmed' => 'badge-blue',
            'activating'        => 'badge-blue',
            'activated'         => 'badge-green',
            'cancelled'         => 'badge-red',
            'pending'           => 'badge-amber',
            'approved'          => 'badge-green',
            'paid'              => 'badge-teal',
            'processing'        => 'badge-blue',
            'sent'              => 'badge-teal',
            'failed'            => 'badge-red',
            'active'            => 'badge-green',
            'suspended'         => 'badge-red',
            'reversed'          => 'badge-red',
        ];
        $class = $map[$status] ?? 'badge-gray';
        return '<span class="badge ' . $class . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
    }
}

if (!function_exists('nav_active')) {
    function nav_active($path) {
        return strpos($_SERVER['PHP_SELF'] ?? '', $path) !== false ? 'active' : '';
    }
}
