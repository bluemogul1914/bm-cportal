<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$client_id = intval($_GET['id'] ?? 0);
if ($client_id <= 0) {
    portal_redirect('/portal/admin-clients.php');
}

$active_tab = $_GET['tab'] ?? 'overview';
$pdo = getDB();
$error_msg = '';
$success_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'add_log_entry') {
        $log_text = trim($_POST['log_text'] ?? '');
        $log_tags = $_POST['log_tags'] ?? '';
        if ($log_text) {
            try {
                $details_data = json_encode(['message' => $log_text, 'tags' => $log_tags]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'client_note', 'client', $client_id, $details_data, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = 'Log entry added.';
            } catch (\Exception $e) {
                $error_msg = 'Failed to add log entry.';
            }
        }
    }

    if ($action === 'add_tag') {
        $tag = trim($_POST['tag'] ?? '');
        if ($tag) {
            try {
                $existing = $pdo->prepare("SELECT notes FROM clients WHERE id = ?");
                $existing->execute([$client_id]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                $notes = $row['notes'] ?? '';
                $tags_json = [];
                if (preg_match('/\[TAGS:(.*?)\]/', $notes, $m)) {
                    $tags_json = json_decode($m[1], true) ?: [];
                    $notes = preg_replace('/\[TAGS:.*?\]/', '', $notes);
                }
                if (!in_array($tag, $tags_json)) $tags_json[] = $tag;
                $notes = trim($notes) . ' [TAGS:' . json_encode($tags_json) . ']';
                $pdo->prepare("UPDATE clients SET notes = ? WHERE id = ?")->execute([$notes, $client_id]);
                $success_msg = 'Tag added.';
            } catch (\Exception $e) {
                $error_msg = 'Failed to add tag.';
            }
        }
    }

    if ($action === 'remove_tag') {
        $tag = trim($_POST['tag'] ?? '');
        if ($tag) {
            try {
                $existing = $pdo->prepare("SELECT notes FROM clients WHERE id = ?");
                $existing->execute([$client_id]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                $notes = $row['notes'] ?? '';
                $tags_json = [];
                if (preg_match('/\[TAGS:(.*?)\]/', $notes, $m)) {
                    $tags_json = json_decode($m[1], true) ?: [];
                    $notes = preg_replace('/\[TAGS:.*?\]/', '', $notes);
                }
                $tags_json = array_values(array_filter($tags_json, fn($t) => $t !== $tag));
                $notes = trim($notes) . (count($tags_json) > 0 ? ' [TAGS:' . json_encode($tags_json) . ']' : '');
                $pdo->prepare("UPDATE clients SET notes = ? WHERE id = ?")->execute([trim($notes), $client_id]);
                $success_msg = 'Tag removed.';
            } catch (\Exception $e) {
                $error_msg = 'Failed to remove tag.';
            }
        }
    }

    if ($action === 'unassign_cloud') {
        $instance_id = intval($_POST['instance_id'] ?? 0);
        if ($instance_id > 0) {
            try {
                $pdo->prepare("UPDATE vultr_instances SET client_id = NULL WHERE id = ? AND client_id = ?")->execute([$instance_id, $client_id]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'cloud_unassigned', 'client', $client_id, 'Unassigned cloud instance #' . $instance_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = 'Cloud instance unassigned from client.';
            } catch (\Exception $e) {
                $error_msg = 'Failed to unassign cloud instance.';
            }
        }
    }

    if ($action === 'update_credit') {
        $credit = floatval($_POST['credit_amount'] ?? 0);
        try {
            $pdo->prepare("UPDATE clients SET credit_balance = ? WHERE id = ?")->execute([$credit, $client_id]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$user_id, 'credit_updated', 'client', $client_id, 'Credit balance updated to $' . number_format($credit, 2), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_msg = 'Credit balance updated.';
        } catch (\Exception $e) {
            $error_msg = 'Failed to update credit.';
        }
    }

    if ($action === 'send_portal_invite') {
        require_once 'includes/email.php';
        try {
            $cl_row = $pdo->prepare("SELECT c.*, u.id as uid FROM clients c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
            $cl_row->execute([$client_id]);
            $cl = $cl_row->fetch(PDO::FETCH_ASSOC);
            $target_email = $cl['email'];
            $target_name  = $cl['name'];
            $uid = $cl['uid'] ?? null;

            if (!$uid) {
                $temp_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                $ins = $pdo->prepare("INSERT INTO users (email, password, name, is_admin, role, status) VALUES (?, ?, ?, 'f', 'client', 'active') RETURNING id");
                $ins->execute([$target_email, $temp_pass, $target_name]);
                $uid = $ins->fetchColumn();
                $pdo->prepare("UPDATE clients SET user_id = ? WHERE id = ?")->execute([$uid, $client_id]);
                $client['user_id'] = $uid;
            }

            $token  = bin2hex(random_bytes(32));
            $hashed = hash('sha256', $token);
            $pdo->prepare("UPDATE users SET remember_token = ?, remember_token_expires = NOW() + INTERVAL '72 hours' WHERE id = ?")->execute([$hashed, $uid]);

            $scheme     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $base_url   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'portal.bluemogul.biz');
            $reset_link = $base_url . '/portal/reset-password.php?token=' . $token;

            $body = '<p style="color:#374151;font-size:14px;line-height:1.6;">Hi ' . htmlspecialchars($target_name) . ',</p>
<p style="color:#374151;font-size:14px;line-height:1.6;">Your <strong>Blue Mogul client portal</strong> account is ready. Click the button below to set your password and get started.</p>
<p style="text-align:center;margin:28px 0;">
  <a href="' . $reset_link . '" style="background:#1a56db;color:#ffffff;padding:13px 32px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;display:inline-block;">Set My Password &amp; Log In</a>
</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;width:120px;">Portal URL</td>
      <td style="padding:8px 12px;font-size:14px;color:#111827;">' . $base_url . '/portal</td></tr>
  <tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;font-size:13px;color:#6b7280;">Login Email</td>
      <td style="padding:8px 12px;font-size:14px;color:#111827;">' . htmlspecialchars($target_email) . '</td></tr>
</table>
<p style="color:#6b7280;font-size:12px;">This link expires in <strong>72 hours</strong>. If you did not expect this email you can safely ignore it.</p>';

            $html   = email_template('Welcome to Your Client Portal', $body);
            $result = send_email($target_email, 'Your Blue Mogul Portal Access', $html);

            if ($result['success'] ?? false) {
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'portal_invite_sent', 'client', $client_id, 'Portal invite sent to ' . $target_email, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = 'Portal invite sent to ' . htmlspecialchars($target_email) . '. Link expires in 72 hours.';
            } else {
                $error_msg = 'Account created but email failed: ' . ($result['error'] ?? 'Unknown error') . '. Share this link manually: ' . $reset_link;
            }
        } catch (\Exception $e) {
            $error_msg = 'Failed to send invite: ' . $e->getMessage();
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT c.*, u.email as user_email, u.created_at as user_created_at FROM clients c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        portal_redirect('/portal/admin-clients.php');
    }

    $parent_client = null;
    if (!empty($client['parent_client_id'])) {
        $stmt = $pdo->prepare("SELECT id, name, company, email FROM clients WHERE id = ?");
        $stmt->execute([$client['parent_client_id']]);
        $parent_client = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $sub_accounts = [];
    $stmt = $pdo->prepare("SELECT id, name, email, company, status FROM clients WHERE parent_client_id = ? ORDER BY name ASC");
    $stmt->execute([$client_id]);
    $sub_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT t.* FROM tickets t WHERE t.client_id = ? ORDER BY t.created_at DESC");
    $stmt->execute([$client_id]);
    $all_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT i.* FROM invoices i WHERE i.client_id = ? ORDER BY i.created_at DESC");
    $stmt->execute([$client_id]);
    $all_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT s.*, p.name as product_name, p.price, p.billing_period FROM subscriptions s JOIN products p ON s.product_id = p.id WHERE s.client_id = ? ORDER BY s.status ASC, s.created_at DESC");
    $stmt->execute([$client_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT d.* FROM documents d WHERE d.client_id = ? ORDER BY d.created_at DESC");
    $stmt->execute([$client_id]);
    $all_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM invoices WHERE client_id = ? AND status = 'unpaid'");
    $stmt->execute([$client_id]);
    $outstanding = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM invoices WHERE client_id = ? AND status = 'paid'");
    $stmt->execute([$client_id]);
    $total_paid = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $pdo->prepare("SELECT p.amount, p.created_at as payment_date FROM payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.client_id = ? ORDER BY p.created_at DESC");
    $stmt->execute([$client_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $all_client_logs = [];
    $stmt = $pdo->prepare("SELECT al.*, u.email as user_email FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE (al.entity_type = 'client' AND al.entity_id = ?) OR al.user_id = (SELECT user_id FROM clients WHERE id = ?) ORDER BY al.created_at DESC LIMIT 50");
    $stmt->execute([$client_id, $client_id]);
    $all_client_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $projects = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE client_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$client_id]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    $devices = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM network_devices WHERE client_id = ? ORDER BY hostname ASC");
        $stmt->execute([$client_id]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    $cloud_instances = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM vultr_instances WHERE client_id = ? ORDER BY label");
        $stmt->execute([$client_id]);
        $cloud_instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    $client_notes = $client['notes'] ?? '';
    $client_tags = [];
    if (preg_match('/\[TAGS:(.*?)\]/', $client_notes, $m)) {
        $client_tags = json_decode($m[1], true) ?: [];
        $client_notes = trim(preg_replace('/\[TAGS:.*?\]/', '', $client_notes));
    }

    $open_tickets = array_filter($all_tickets, fn($t) => $t['status'] !== 'closed');
    $recent_invoices = array_slice($all_invoices, 0, 5);
    $active_services = array_filter($services, fn($s) => $s['status'] === 'active');
    $services_total = 0;
    foreach ($active_services as $as) $services_total += (float)($as['price'] ?? 0);

    $credit_balance = (float)($client['credit_balance'] ?? 0);

    $next_invoice_date = null;
    if (count($all_invoices) > 0) {
        $last = $all_invoices[0];
        $last_date = strtotime($last['due_date'] ?? $last['created_at']);
        $next_invoice_date = date('m/d/Y', strtotime('+30 days', $last_date));
    }

} catch (PDOException $e) {
    error_log("Client detail error: " . $e->getMessage());
    portal_redirect('/portal/admin-clients.php');
}

$initials = strtoupper(substr($client['name'], 0, 1)) . (strpos($client['name'], ' ') !== false ? strtoupper(substr(strstr($client['name'], ' '), 1, 1)) : '');
$lat = $client['latitude'] ?? '';
$lng = $client['longitude'] ?? '';
$has_location = !empty($lat) && !empty($lng);
if (!$has_location && !empty($client['city']) && !empty($client['state'])) {
    $city_coords = [
        'houston' => ['29.7604', '-95.3698'],
        'dallas' => ['32.7767', '-96.7970'],
        'austin' => ['30.2672', '-97.7431'],
        'san antonio' => ['29.4241', '-98.4936'],
        'bossier city' => ['32.5160', '-93.7321'],
        'shreveport' => ['32.5252', '-93.7502'],
        'new york' => ['40.7128', '-74.0060'],
        'los angeles' => ['34.0522', '-118.2437'],
        'chicago' => ['41.8781', '-87.6298'],
    ];
    $city_lower = strtolower(trim($client['city']));
    if (isset($city_coords[$city_lower])) {
        $lat = $city_coords[$city_lower][0];
        $lng = $city_coords[$city_lower][1];
        $has_location = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($client['name']); ?> - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">

        <header class="bg-white border-b border-gray-200 sticky top-0 z-30">
            <div class="px-6 py-3 flex items-center justify-between">
                <div class="flex items-center gap-2 text-sm text-gray-500">
                    <a href="admin-clients.php" class="hover:text-primary transition" data-testid="link-clients-breadcrumb">Clients</a>
                    <i class="fas fa-chevron-right text-[10px]"></i>
                    <?php if ($parent_client): ?>
                    <a href="admin-client-detail.php?id=<?php echo $parent_client['id']; ?>" class="hover:text-primary transition"><?php echo htmlspecialchars($parent_client['name']); ?></a>
                    <i class="fas fa-chevron-right text-[10px]"></i>
                    <?php endif; ?>
                    <span class="text-gray-900 font-medium" data-testid="text-breadcrumb-name"><?php echo htmlspecialchars($client['name']); ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <a href="admin-client-edit.php?id=<?php echo $client_id; ?>" class="px-3 py-1.5 bg-primary hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition" data-testid="link-edit-client">
                        <i class="fas fa-edit mr-1"></i>Edit Client
                    </a>
                </div>
            </div>
            <div class="px-6 pb-2 flex gap-1 overflow-x-auto">
                <?php
                $tabs = [
                    'overview' => 'Overview',
                    'invoices' => 'Invoices',
                    'payments' => 'Payments',
                    'documents' => 'Documents',
                    'tickets' => 'Tickets',
                    'network' => 'Network',
                    'cloud' => 'Cloud',
                    'projects' => 'Projects',
                ];
                foreach ($tabs as $tk => $tv):
                    $tab_active = ($active_tab === $tk) ? 'border-b-2 border-primary text-primary font-medium' : 'text-gray-500 hover:text-gray-700';
                ?>
                    <a href="?id=<?php echo $client_id; ?>&tab=<?php echo $tk; ?>" class="px-4 py-2 text-sm transition <?php echo $tab_active; ?>" data-testid="tab-<?php echo $tk; ?>"><?php echo $tv; ?></a>
                <?php endforeach; ?>
            </div>
        </header>

        <div class="p-6">
            <?php if ($error_msg): ?>
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="text-error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
            <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="text-success"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>

            <?php if ($active_tab === 'overview'): ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 mb-1">Account Balance</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-account-balance">$<?php echo number_format($outstanding, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 mb-1">Cash Received</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-cash">$<?php echo number_format($total_paid, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 mb-1">Outstanding</p>
                    <p class="text-2xl font-bold <?php echo $outstanding > 0 ? 'text-red-600' : 'text-green-600'; ?>" data-testid="text-outstanding">$<?php echo number_format($outstanding, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 mb-1">Credit Balance</p>
                    <p class="text-2xl font-bold text-blue-600" data-testid="text-credit-balance">$<?php echo number_format($credit_balance, 2); ?></p>
                </div>
            </div>

            <div class="text-xs text-gray-500 mb-6 flex items-center gap-4 flex-wrap">
                <span><i class="fas fa-calendar-alt mr-1"></i>Expected payment: $<?php echo number_format($services_total, 2); ?> / month</span>
                <?php if ($next_invoice_date): ?>
                <span>Next invoicing day: <?php echo $next_invoice_date; ?></span>
                <?php endif; ?>
                <span><i class="fas fa-cube mr-1"></i><?php echo count($active_services); ?> active service(s)</span>
                <span><i class="fas fa-ticket-alt mr-1"></i><?php echo count($open_tickets); ?> open ticket(s)</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                <div class="lg:col-span-3 space-y-6">

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Services</h2>
                            <a href="admin-services.php" class="text-primary text-xs hover:underline"><i class="fas fa-plus mr-1"></i>Add</a>
                        </div>
                        <?php if (empty($services)): ?>
                        <div class="p-6 text-center text-gray-400 text-sm">No services found.</div>
                        <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($services as $s): ?>
                            <div class="px-5 py-3 flex items-center justify-between" data-testid="row-service-<?php echo $s['id']; ?>">
                                <div class="flex items-center gap-3">
                                    <div class="w-1.5 h-8 rounded-full <?php echo $s['status'] === 'active' ? 'bg-green-500' : ($s['status'] === 'suspended' ? 'bg-yellow-500' : 'bg-red-400'); ?>"></div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($s['product_name']); ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo htmlspecialchars(($s['billing_period'] ?? 'monthly') === 'monthly' ? '1 month' : $s['billing_period']); ?>
                                            | <i class="fas fa-user text-blue-400 text-[10px]"></i> <?php echo htmlspecialchars($client['name']); ?>
                                            | <span class="<?php echo $s['status'] === 'active' ? 'text-green-600' : 'text-red-500'; ?>"><?php echo ucfirst($s['status']); ?></span>
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-semibold text-gray-900">$<?php echo number_format((float)$s['price'], 2); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="px-5 py-2 bg-gray-50 border-t border-gray-200 flex justify-between text-xs text-gray-600">
                            <span>Total Monthly</span>
                            <span class="font-semibold">$<?php echo number_format($services_total, 2); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($has_location): ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200">
                            <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Location</h2>
                        </div>
                        <div id="client-map" class="h-48 rounded-b-lg" data-testid="map-location"></div>
                    </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Logs</h2>
                            <div class="flex items-center gap-2 text-xs text-gray-400">
                                <span><?php echo count($all_client_logs); ?> entries</span>
                            </div>
                        </div>
                        <div class="p-4">
                            <form method="POST" class="mb-4">
                            <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_log_entry">
                                <div class="flex items-start gap-2 mb-2">
                                    <div class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0 mt-3"></div>
                                    <textarea name="log_text" rows="3" placeholder="New log entry..." class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-y" data-testid="input-log-entry"></textarea>
                                    <button type="submit" class="px-3 py-2 bg-primary text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition mt-0" data-testid="button-add-log">Submit</button>
                                </div>
                                <div class="ml-4 flex items-center gap-2">
                                    <label class="text-[10px] text-gray-400 uppercase">Tags:</label>
                                    <select name="log_tags" class="text-xs border border-gray-200 rounded px-2 py-0.5 text-gray-600" data-testid="select-log-tags">
                                        <option value="">None</option>
                                        <option value="billing">Billing</option>
                                        <option value="support">Support</option>
                                        <option value="sales">Sales</option>
                                        <option value="technical">Technical</option>
                                        <option value="onboarding">Onboarding</option>
                                        <option value="followup">Follow-up</option>
                                    </select>
                                </div>
                            </form>
                            <div class="space-y-3 max-h-[500px] overflow-y-auto" data-testid="log-entries-list">
                                <?php if (empty($all_client_logs)): ?>
                                <p class="text-center text-gray-400 text-sm py-4">No activity yet.</p>
                                <?php else: ?>
                                <?php foreach (array_slice($all_client_logs, 0, 20) as $log): ?>
                                <div class="flex items-start gap-3 text-sm" data-testid="row-log-<?php echo $log['id']; ?>">
                                    <div class="flex-shrink-0 mt-1">
                                        <?php
                                        $log_action = $log['action'] ?? '';
                                        $icon_map = [
                                            'create' => 'fa-plus-circle text-green-500',
                                            'added' => 'fa-plus-circle text-green-500',
                                            'update' => 'fa-edit text-blue-500',
                                            'edit' => 'fa-edit text-blue-500',
                                            'delete' => 'fa-times-circle text-red-500',
                                            'cancel' => 'fa-times-circle text-red-500',
                                            'login' => 'fa-sign-in-alt text-indigo-500',
                                            'note' => 'fa-sticky-note text-yellow-500',
                                            'credit' => 'fa-dollar-sign text-green-600',
                                            'invoice' => 'fa-file-invoice text-blue-400',
                                            'payment' => 'fa-credit-card text-green-500',
                                            'ticket' => 'fa-ticket-alt text-purple-500',
                                        ];
                                        $icon = 'fa-circle text-gray-400';
                                        foreach ($icon_map as $key => $ic) {
                                            if (strpos($log_action, $key) !== false) { $icon = $ic; break; }
                                        }
                                        echo '<i class="fas ' . $icon . ' text-xs"></i>';
                                        ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs font-semibold text-gray-600"><?php
                                                $le = $log['user_email'] ?? '';
                                                echo htmlspecialchars($le ? explode('@', $le)[0] : 'System');
                                            ?></span>
                                            <?php
                                            $details = $log['details'] ?? '';
                                            $log_msg = $details;
                                            $log_tag_display = '';
                                            if (is_string($details) && substr($details, 0, 1) === '{') {
                                                $decoded = json_decode($details, true);
                                                if ($decoded) {
                                                    $log_msg = $decoded['message'] ?? $details;
                                                    $log_tag_display = $decoded['tags'] ?? '';
                                                }
                                            } elseif (is_string($details) && substr($details, 0, 1) === '"') {
                                                $decoded = json_decode($details, true);
                                                if (is_string($decoded)) $log_msg = $decoded;
                                            }
                                            ?>
                                            <span class="text-xs text-gray-700"><?php echo htmlspecialchars($log_msg); ?></span>
                                            <?php if ($log_tag_display): ?>
                                            <span class="px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded text-[10px] font-medium"><?php echo htmlspecialchars($log_tag_display); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-[10px] text-gray-400">
                                            #<?php echo $log['id']; ?> &middot;
                                            <?php echo htmlspecialchars($log_action); ?> &middot;
                                            <?php echo date('n/j/Y g:i:s a', strtotime($log['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (count($all_client_logs) > 20): ?>
                            <p class="text-xs text-gray-400 mt-3 text-center">Showing 20 of <?php echo count($all_client_logs); ?> entries</p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="lg:col-span-2 space-y-6">

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="p-5">
                            <div class="flex items-start gap-4">
                                <div class="bg-primary text-white rounded-full h-14 w-14 flex items-center justify-center font-bold text-xl flex-shrink-0" data-testid="text-client-avatar">
                                    <?php echo $initials; ?>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-client-name"><?php echo htmlspecialchars($client['name']); ?></h2>
                                        <a href="admin-client-edit.php?id=<?php echo $client_id; ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-pencil-alt text-xs"></i></a>
                                    </div>
                                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-[10px] font-semibold uppercase"><?php echo ucfirst($client['status'] ?? 'active'); ?></span>
                                        <?php foreach ($client_tags as $tag): ?>
                                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-[10px] font-medium group inline-flex items-center gap-1" data-testid="tag-<?php echo htmlspecialchars($tag); ?>">
                                            <?php echo htmlspecialchars($tag); ?>
                                            <form method="POST" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="remove_tag"><input type="hidden" name="tag" value="<?php echo htmlspecialchars($tag); ?>"><button type="submit" class="text-blue-400 hover:text-red-500 hidden group-hover:inline text-[10px]"><i class="fas fa-times"></i></button></form>
                                        </span>
                                        <?php endforeach; ?>
                                        <form method="POST" class="inline-flex items-center gap-1">
                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="add_tag">
                                            <input type="text" name="tag" placeholder="+ Tag" class="w-14 px-1 py-0 border-0 border-b border-dashed border-gray-300 text-[10px] text-gray-500 focus:outline-none focus:border-blue-500 bg-transparent" data-testid="input-add-tag">
                                        </form>
                                    </div>

                                    <?php if ($parent_client): ?>
                                    <div class="mb-2 px-2 py-1.5 bg-amber-50 border border-amber-200 rounded-lg">
                                        <p class="text-[10px] text-amber-600 uppercase font-semibold mb-0.5">Sub-Account of</p>
                                        <a href="admin-client-detail.php?id=<?php echo $parent_client['id']; ?>" class="text-sm font-medium text-amber-800 hover:underline" data-testid="link-parent-account">
                                            <i class="fas fa-building mr-1 text-xs"></i><?php echo htmlspecialchars($parent_client['company'] ?: $parent_client['name']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <div class="grid grid-cols-2 gap-y-2 text-sm mt-3">
                                        <div>
                                            <span class="text-[10px] text-gray-400 uppercase">ID</span>
                                            <p class="font-medium text-gray-700" data-testid="text-client-id"><?php echo $client_id; ?></p>
                                        </div>
                                        <?php if ($client['company']): ?>
                                        <div>
                                            <span class="text-[10px] text-gray-400 uppercase">Company</span>
                                            <p class="font-medium text-gray-700"><?php echo htmlspecialchars($client['company']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2">
                                        <span class="text-[10px] text-gray-400 uppercase">Email</span>
                                        <p class="text-sm"><a href="mailto:<?php echo htmlspecialchars($client['email']); ?>" class="text-blue-600 hover:underline" data-testid="text-client-email"><?php echo htmlspecialchars($client['email']); ?></a></p>
                                    </div>
                                    <?php if ($client['phone']): ?>
                                    <div class="mt-2">
                                        <span class="text-[10px] text-gray-400 uppercase">Phone</span>
                                        <p class="text-sm text-gray-700"><?php echo htmlspecialchars($client['phone']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($client['address']): ?>
                                    <div class="mt-2">
                                        <span class="text-[10px] text-gray-400 uppercase">Address</span>
                                        <p class="text-sm text-gray-700"><?php echo htmlspecialchars($client['address']); ?><?php if ($client['city'] || $client['state'] || $client['zip']) echo '<br>' . htmlspecialchars(implode(', ', array_filter([$client['city'], $client['state'], $client['zip']]))); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($client_notes): ?>
                                    <div class="mt-3 pt-3 border-t border-gray-100">
                                        <span class="text-[10px] text-gray-400 uppercase">Notes</span>
                                        <p class="text-sm text-gray-600 mt-0.5"><?php echo nl2br(htmlspecialchars($client_notes)); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <div class="mt-3 pt-3 border-t border-gray-100 text-xs text-gray-400">
                                        Created: <?php echo date('M d, Y', strtotime($client['created_at'])); ?>
                                    </div>

                                    <div class="mt-3 pt-3 border-t border-gray-100">
                                        <span class="text-[10px] text-gray-400 uppercase font-semibold">Portal Access</span>
                                        <?php if (!empty($client['user_id'])): ?>
                                        <div class="mt-1.5 flex items-center justify-between gap-2">
                                            <div>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-green-100 text-green-700 text-[10px] font-semibold" data-testid="badge-portal-active"><i class="fas fa-check-circle"></i> Account Linked</span>
                                                <?php if (!empty($client['user_email'])): ?>
                                                <p class="text-[11px] text-gray-500 mt-0.5"><?php echo htmlspecialchars($client['user_email']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="send_portal_invite">
                                                <button type="submit" class="text-[11px] text-blue-600 hover:underline whitespace-nowrap" data-testid="button-resend-invite" onclick="return confirm('Resend portal invite to <?php echo addslashes($client['email']); ?>?')">Resend Invite</button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                        <div class="mt-1.5 flex items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] font-semibold" data-testid="badge-portal-none"><i class="fas fa-exclamation-circle"></i> No Portal Account</span>
                                            <form method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="send_portal_invite">
                                                <button type="submit" class="text-[11px] text-blue-600 hover:underline whitespace-nowrap" data-testid="button-send-invite">Send Invite</button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($sub_accounts)): ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><i class="fas fa-users text-primary mr-1"></i>Sub-Accounts</h3>
                            <span class="text-xs text-gray-400"><?php echo count($sub_accounts); ?> account(s)</span>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($sub_accounts as $sub): ?>
                            <a href="admin-client-detail.php?id=<?php echo $sub['id']; ?>" class="flex items-center px-5 py-3 hover:bg-gray-50 transition" data-testid="row-sub-account-<?php echo $sub['id']; ?>">
                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                    <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center text-xs font-bold text-gray-600">
                                        <?php echo strtoupper(substr($sub['name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($sub['name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($sub['email']); ?></p>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold <?php echo ($sub['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>"><?php echo ucfirst($sub['status'] ?? 'active'); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Invoices</h3>
                            <a href="?id=<?php echo $client_id; ?>&tab=invoices" class="text-xs text-blue-600 hover:underline">See All</a>
                        </div>
                        <?php if (empty($recent_invoices)): ?>
                        <div class="p-4 text-center text-gray-400 text-sm">No invoices.</div>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm" data-testid="table-invoices-mini">
                                <thead>
                                    <tr class="text-[10px] text-gray-400 uppercase">
                                        <th class="px-4 py-2 font-medium">Invoice #</th>
                                        <th class="px-4 py-2 font-medium">Total</th>
                                        <th class="px-4 py-2 font-medium">Amount Due</th>
                                        <th class="px-4 py-2 font-medium">Due</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($recent_invoices as $inv): ?>
                                    <tr class="hover:bg-gray-50" data-testid="row-inv-<?php echo $inv['id']; ?>">
                                        <td class="px-4 py-2">
                                            <a href="admin-invoice-detail.php?id=<?php echo $inv['id']; ?>" class="text-blue-600 hover:underline font-medium"><?php echo htmlspecialchars($inv['invoice_number']); ?></a>
                                            <?php if ($inv['status'] === 'unpaid'): ?>
                                            <span class="ml-1 px-1.5 py-0.5 bg-orange-100 text-orange-700 rounded text-[10px] font-semibold">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-2 text-gray-700">$<?php echo number_format((float)$inv['amount'], 2); ?></td>
                                        <td class="px-4 py-2 font-semibold"><?php echo $inv['status'] === 'paid' ? '$0.00' : '$' . number_format((float)$inv['amount'], 2); ?></td>
                                        <td class="px-4 py-2 text-gray-500 text-xs"><?php
                                            $due = strtotime($inv['due_date'] ?? $inv['created_at']);
                                            $diff = $due - time();
                                            if ($inv['status'] === 'paid') echo '<span class="text-green-600">Paid</span>';
                                            elseif ($diff < 0) echo '<span class="text-red-600 font-medium">Overdue</span>';
                                            else echo 'due in ' . ceil($diff / 86400) . ' days';
                                        ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($recent_invoices)): ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Next Invoice Preview</h3>
                        </div>
                        <div class="p-4">
                            <?php
                            $last_inv = $recent_invoices[0];
                            $period_start = date('n/j/Y', strtotime($last_inv['due_date'] ?? $last_inv['created_at']));
                            $period_end = date('n/j/Y', strtotime('+30 days', strtotime($last_inv['due_date'] ?? $last_inv['created_at'])));
                            ?>
                            <div class="flex items-center gap-4 mb-3">
                                <div>
                                    <p class="text-xs text-gray-500">Period</p>
                                    <p class="text-sm font-medium text-gray-900"><?php echo $period_start; ?> - <?php echo $period_end; ?></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3 text-center">
                                <div>
                                    <p class="text-[10px] text-gray-400 uppercase">Created</p>
                                    <p class="text-xs font-medium"><?php echo date('n/j/Y', strtotime($last_inv['created_at'])); ?></p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-gray-400 uppercase">Due</p>
                                    <p class="text-xs font-medium"><?php echo date('n/j/Y', strtotime($last_inv['due_date'] ?? $last_inv['created_at'])); ?></p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-gray-400 uppercase">Amount</p>
                                    <p class="text-lg font-bold text-gray-900">$<?php echo number_format($services_total, 2); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Credits</h3>
                        </div>
                        <div class="p-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm text-gray-600">Current Balance</span>
                                <span class="text-lg font-bold text-blue-600" data-testid="text-credit-inline">$<?php echo number_format($credit_balance, 2); ?></span>
                            </div>
                            <form method="POST" class="flex items-center gap-2">
                            <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_credit">
                                <input type="number" name="credit_amount" step="0.01" value="<?php echo number_format($credit_balance, 2, '.', ''); ?>" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-credit-amount">
                                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700" data-testid="button-update-credit">Update</button>
                            </form>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Active Tickets</h3>
                            <a href="admin-tickets.php" class="text-xs text-blue-600 hover:underline">Add Ticket</a>
                        </div>
                        <?php if (empty($open_tickets)): ?>
                        <div class="p-6 text-center">
                            <i class="fas fa-check-circle text-green-400 text-2xl mb-2"></i>
                            <p class="text-sm text-gray-500">No active tickets</p>
                            <p class="text-xs text-gray-400">All issues resolved</p>
                        </div>
                        <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach (array_slice(array_values($open_tickets), 0, 5) as $t): ?>
                            <a href="admin-ticket-detail.php?id=<?php echo $t['id']; ?>" class="flex items-center px-5 py-3 hover:bg-gray-50 transition" data-testid="row-ticket-<?php echo $t['id']; ?>">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($t['subject']); ?></p>
                                    <p class="text-xs text-gray-500">#<?php echo $t['id']; ?> &middot; <?php echo ucfirst(str_replace('_', ' ', $t['priority'] ?? 'medium')); ?></p>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold <?php
                                    echo match($t['status']) {
                                        'open' => 'bg-blue-100 text-blue-700',
                                        'in_progress' => 'bg-yellow-100 text-yellow-700',
                                        default => 'bg-gray-100 text-gray-700'
                                    };
                                ?>"><?php echo ucfirst(str_replace('_', ' ', $t['status'])); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($projects)): ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Projects</h3>
                            <a href="admin-projects.php" class="text-xs text-blue-600 hover:underline">See All</a>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <?php foreach (array_slice($projects, 0, 5) as $pj): ?>
                            <a href="admin-project-detail.php?id=<?php echo $pj['id']; ?>" class="flex items-center px-5 py-3 hover:bg-gray-50 transition" data-testid="row-project-<?php echo $pj['id']; ?>">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($pj['name'] ?? ''); ?></p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <div class="flex-1 bg-gray-200 rounded-full h-1.5 max-w-[100px]">
                                            <div class="bg-primary rounded-full h-1.5" style="width: <?php echo intval($pj['progress'] ?? 0); ?>%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-400"><?php echo intval($pj['progress'] ?? 0); ?>%</span>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold <?php
                                    echo match($pj['status'] ?? '') {
                                        'active','in_progress' => 'bg-blue-100 text-blue-700',
                                        'completed' => 'bg-green-100 text-green-700',
                                        'on_hold' => 'bg-yellow-100 text-yellow-700',
                                        default => 'bg-gray-100 text-gray-700'
                                    };
                                ?>"><?php echo ucfirst(str_replace('_', ' ', $pj['status'] ?? 'planning')); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php elseif ($active_tab === 'invoices'): ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-file-invoice-dollar text-primary mr-2"></i>All Invoices (<?php echo count($all_invoices); ?>)</h2>
                    <a href="admin-invoice-add.php?client_id=<?php echo $client_id; ?>" class="px-3 py-1.5 bg-primary text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition" data-testid="button-add-invoice"><i class="fas fa-plus mr-1"></i>New Invoice</a>
                </div>
                <?php if (empty($all_invoices)): ?>
                <div class="p-8 text-center text-gray-400"><i class="fas fa-file-invoice text-3xl mb-2"></i><p>No invoices found.</p></div>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-5 border-b border-gray-100">
                    <div class="p-3 bg-green-50 rounded-lg">
                        <p class="text-xs text-green-600 font-medium">Total Paid</p>
                        <p class="text-xl font-bold text-green-700">$<?php echo number_format($total_paid, 2); ?></p>
                    </div>
                    <div class="p-3 bg-orange-50 rounded-lg">
                        <p class="text-xs text-orange-600 font-medium">Outstanding</p>
                        <p class="text-xl font-bold text-orange-700">$<?php echo number_format($outstanding, 2); ?></p>
                    </div>
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <p class="text-xs text-blue-600 font-medium">Credits</p>
                        <p class="text-xl font-bold text-blue-700">$<?php echo number_format($credit_balance, 2); ?></p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left" data-testid="table-all-invoices">
                        <thead>
                            <tr class="bg-gray-50 text-xs text-gray-500 uppercase">
                                <th class="px-5 py-3 font-medium">Invoice #</th>
                                <th class="px-5 py-3 font-medium">Date</th>
                                <th class="px-5 py-3 font-medium">Due Date</th>
                                <th class="px-5 py-3 font-medium">Amount</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($all_invoices as $inv): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo date('M d, Y', strtotime($inv['created_at'])); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo date('M d, Y', strtotime($inv['due_date'] ?? $inv['created_at'])); ?></td>
                                <td class="px-5 py-3 text-sm font-semibold">$<?php echo number_format((float)$inv['amount'], 2); ?></td>
                                <td class="px-5 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?php echo $inv['status'] === 'paid' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                                <td class="px-5 py-3"><a href="admin-invoice-detail.php?id=<?php echo $inv['id']; ?>" class="text-blue-600 hover:underline text-xs font-medium"><i class="fas fa-eye mr-1"></i>View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($active_tab === 'payments'): ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-credit-card text-green-500 mr-2"></i>Payment History (<?php echo count($payments); ?>)</h2>
                </div>
                <?php if (empty($payments)): ?>
                <div class="p-8 text-center text-gray-400"><i class="fas fa-credit-card text-3xl mb-2"></i><p>No payments recorded.</p></div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left" data-testid="table-payments">
                        <thead>
                            <tr class="bg-gray-50 text-xs text-gray-500 uppercase">
                                <th class="px-5 py-3 font-medium">Date</th>
                                <th class="px-5 py-3 font-medium">Amount</th>
                                <th class="px-5 py-3 font-medium">Method</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($payments as $p): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-sm text-gray-700"><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                                <td class="px-5 py-3 text-sm font-semibold text-green-700">$<?php echo number_format((float)$p['amount'], 2); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-500">Stripe</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($active_tab === 'documents'): ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-folder text-yellow-500 mr-2"></i>Documents (<?php echo count($all_documents); ?>)</h2>
                </div>
                <?php if (empty($all_documents)): ?>
                <div class="p-8 text-center text-gray-400"><i class="fas fa-folder-open text-3xl mb-2"></i><p>No documents found.</p></div>
                <?php else: ?>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($all_documents as $doc): ?>
                    <div class="flex items-center px-5 py-3 hover:bg-gray-50" data-testid="row-doc-<?php echo $doc['id']; ?>">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <?php
                            $ext = strtolower(pathinfo($doc['name'] ?? '', PATHINFO_EXTENSION));
                            $icon = match(true) {
                                in_array($ext, ['pdf']) => 'fa-file-pdf text-red-500',
                                in_array($ext, ['doc','docx']) => 'fa-file-word text-blue-500',
                                in_array($ext, ['xls','xlsx']) => 'fa-file-excel text-green-500',
                                in_array($ext, ['jpg','jpeg','png','gif']) => 'fa-file-image text-purple-500',
                                default => 'fa-file text-gray-400',
                            };
                            ?>
                            <i class="fas <?php echo $icon; ?> text-lg"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($doc['name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($doc['category'] ?? 'General'); ?> &middot; <?php echo date('M d, Y', strtotime($doc['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($active_tab === 'tickets'): ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-ticket-alt text-primary mr-2"></i>All Tickets (<?php echo count($all_tickets); ?>)</h2>
                </div>
                <?php if (empty($all_tickets)): ?>
                <div class="p-8 text-center text-gray-400"><i class="fas fa-ticket-alt text-3xl mb-2"></i><p>No tickets found.</p></div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left" data-testid="table-all-tickets">
                        <thead>
                            <tr class="bg-gray-50 text-xs text-gray-500 uppercase">
                                <th class="px-5 py-3 font-medium">ID</th>
                                <th class="px-5 py-3 font-medium">Subject</th>
                                <th class="px-5 py-3 font-medium">Priority</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Created</th>
                                <th class="px-5 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($all_tickets as $t): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-5 py-3 text-sm text-gray-500">#<?php echo $t['id']; ?></td>
                                <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($t['subject']); ?></td>
                                <td class="px-5 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?php
                                    echo match($t['priority'] ?? 'medium') {
                                        'high','urgent' => 'bg-red-100 text-red-700',
                                        'medium' => 'bg-yellow-100 text-yellow-700',
                                        'low' => 'bg-green-100 text-green-700',
                                        default => 'bg-gray-100 text-gray-700'
                                    };
                                ?>"><?php echo ucfirst($t['priority'] ?? 'Medium'); ?></span></td>
                                <td class="px-5 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?php
                                    echo match($t['status']) {
                                        'open' => 'bg-blue-100 text-blue-700',
                                        'in_progress' => 'bg-yellow-100 text-yellow-700',
                                        'closed' => 'bg-gray-100 text-gray-700',
                                        default => 'bg-gray-100 text-gray-700'
                                    };
                                ?>"><?php echo ucfirst(str_replace('_', ' ', $t['status'])); ?></span></td>
                                <td class="px-5 py-3 text-sm text-gray-500"><?php echo date('M d, Y', strtotime($t['created_at'])); ?></td>
                                <td class="px-5 py-3"><a href="admin-ticket-detail.php?id=<?php echo $t['id']; ?>" class="text-blue-600 hover:underline text-xs font-medium"><i class="fas fa-eye mr-1"></i>View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($active_tab === 'network'): ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-network-wired text-primary mr-2"></i>Network Devices (<?php echo count($devices); ?>)</h2>
                    <a href="admin-network.php" class="text-xs text-blue-600 hover:underline">Manage in Network Docs</a>
                </div>
                <?php if (empty($devices)): ?>
                <div class="p-8 text-center text-gray-400">
                    <i class="fas fa-network-wired text-3xl mb-2"></i>
                    <p>No network devices assigned.</p>
                    <p class="text-xs mt-1"><a href="admin-network.php" class="text-blue-600 hover:underline">Add devices in Network Docs</a></p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left" data-testid="table-network-devices">
                        <thead>
                            <tr class="bg-gray-50 text-xs text-gray-500 uppercase">
                                <th class="px-5 py-3 font-medium">Hostname</th>
                                <th class="px-5 py-3 font-medium">IP Address</th>
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($devices as $dev): ?>
                            <tr class="hover:bg-gray-50" data-testid="row-device-<?php echo $dev['id']; ?>">
                                <td class="px-5 py-3 text-sm font-medium text-gray-900">
                                    <i class="fas <?php
                                        echo match($dev['device_type'] ?? '') {
                                            'router' => 'fa-route text-blue-500',
                                            'switch' => 'fa-project-diagram text-green-500',
                                            'access_point','ap' => 'fa-wifi text-purple-500',
                                            'firewall' => 'fa-shield-alt text-red-500',
                                            'server' => 'fa-server text-gray-600',
                                            default => 'fa-hdd text-gray-400',
                                        };
                                    ?> mr-2"></i>
                                    <?php echo htmlspecialchars($dev['hostname'] ?? '—'); ?>
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 font-mono"><?php echo htmlspecialchars($dev['ip_address'] ?? '—'); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo ucfirst(str_replace('_', ' ', $dev['device_type'] ?? '—')); ?></td>
                                <td class="px-5 py-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo ($dev['status'] ?? 'online') === 'online' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                        <?php echo ucfirst($dev['status'] ?? 'online'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($active_tab === 'cloud'): ?>

            <?php
            $cloud_total_cost = 0;
            $cloud_total_bandwidth_used = 0;
            $cloud_total_bandwidth_allowed = 0;
            foreach ($cloud_instances as $ci) {
                $cloud_total_cost += (float)($ci['cost_per_month'] ?? 0);
                $cloud_total_bandwidth_used += (float)($ci['current_bandwidth'] ?? 0);
                $cloud_total_bandwidth_allowed += (float)($ci['allowed_bandwidth'] ?? 0);
            }
            ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 mb-1">Total Instances</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-cloud-total-instances"><?php echo count($cloud_instances); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 mb-1">Monthly Cost</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-cloud-monthly-cost">$<?php echo number_format($cloud_total_cost, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 mb-1">Bandwidth Used / Allowed</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-cloud-bandwidth"><?php echo number_format($cloud_total_bandwidth_used, 1); ?> / <?php echo number_format($cloud_total_bandwidth_allowed, 1); ?> GB</p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cloud text-primary mr-2"></i>Cloud Instances (<?php echo count($cloud_instances); ?>)</h2>
                    <a href="admin-vultr.php" class="px-3 py-1.5 bg-primary text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition" data-testid="link-manage-vultr"><i class="fas fa-server mr-1"></i>Manage in Vultr</a>
                </div>
                <?php if (empty($cloud_instances)): ?>
                <div class="p-8 text-center text-gray-400" data-testid="text-cloud-empty">
                    <i class="fas fa-cloud text-3xl mb-2"></i>
                    <p>No cloud instances assigned.</p>
                    <p class="text-xs mt-1">Assign instances from the <a href="admin-vultr.php" class="text-blue-600 hover:underline">Vultr Cloud page</a>.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left" data-testid="table-cloud-instances">
                        <thead>
                            <tr class="bg-gray-50 text-xs text-gray-500 uppercase">
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Label</th>
                                <th class="px-5 py-3 font-medium">OS</th>
                                <th class="px-5 py-3 font-medium">IP Address</th>
                                <th class="px-5 py-3 font-medium">Specs</th>
                                <th class="px-5 py-3 font-medium">Region</th>
                                <th class="px-5 py-3 font-medium">Plan</th>
                                <th class="px-5 py-3 font-medium">Cost/Mo</th>
                                <th class="px-5 py-3 font-medium">Bandwidth</th>
                                <th class="px-5 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($cloud_instances as $ci): ?>
                            <tr class="hover:bg-gray-50 transition" data-testid="row-cloud-<?php echo $ci['id']; ?>">
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full <?php echo ($ci['power_status'] ?? '') === 'running' ? 'bg-green-500' : 'bg-red-400'; ?>"></span>
                                        <span class="text-xs font-medium <?php echo ($ci['power_status'] ?? '') === 'running' ? 'text-green-700' : 'text-red-600'; ?>"><?php echo ucfirst($ci['power_status'] ?? 'stopped'); ?></span>
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ci['label'] ?? '—'); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($ci['os'] ?? '—'); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-600 font-mono"><?php echo htmlspecialchars($ci['main_ip'] ?? '—'); ?></td>
                                <td class="px-5 py-3 text-xs text-gray-600">
                                    <?php echo intval($ci['vcpu_count'] ?? 0); ?> vCPU / <?php echo intval($ci['ram'] ?? 0); ?> MB / <?php echo intval($ci['disk'] ?? 0); ?> GB
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($ci['region'] ?? '—'); ?></td>
                                <td class="px-5 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($ci['plan'] ?? '—'); ?></td>
                                <td class="px-5 py-3 text-sm font-semibold text-gray-900">$<?php echo number_format((float)($ci['cost_per_month'] ?? 0), 2); ?></td>
                                <td class="px-5 py-3">
                                    <?php
                                    $bw_used = (float)($ci['current_bandwidth'] ?? 0);
                                    $bw_allowed = (float)($ci['allowed_bandwidth'] ?? 1);
                                    $bw_pct = $bw_allowed > 0 ? min(100, ($bw_used / $bw_allowed) * 100) : 0;
                                    ?>
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 bg-gray-200 rounded-full h-1.5">
                                            <div class="rounded-full h-1.5 <?php echo $bw_pct > 80 ? 'bg-red-500' : ($bw_pct > 50 ? 'bg-yellow-500' : 'bg-green-500'); ?>" style="width: <?php echo number_format($bw_pct, 1); ?>%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-500"><?php echo number_format($bw_used, 1); ?>/<?php echo number_format($bw_allowed, 1); ?> GB</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Unassign this instance from the client?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="unassign_cloud">
                                        <input type="hidden" name="instance_id" value="<?php echo $ci['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-medium" data-testid="button-unassign-cloud-<?php echo $ci['id']; ?>">
                                            <i class="fas fa-unlink mr-1"></i>Unassign
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($active_tab === 'projects'): ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-project-diagram text-primary mr-2"></i>Projects (<?php echo count($projects); ?>)</h2>
                    <a href="admin-projects.php" class="px-3 py-1.5 bg-primary text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition"><i class="fas fa-plus mr-1"></i>New Project</a>
                </div>
                <?php if (empty($projects)): ?>
                <div class="p-8 text-center text-gray-400">
                    <i class="fas fa-project-diagram text-3xl mb-2"></i>
                    <p>No projects assigned.</p>
                    <p class="text-xs mt-1"><a href="admin-projects.php" class="text-blue-600 hover:underline">Create a project</a></p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($projects as $pj): ?>
                    <a href="admin-project-detail.php?id=<?php echo $pj['id']; ?>" class="flex items-center px-5 py-4 hover:bg-gray-50 transition" data-testid="row-project-<?php echo $pj['id']; ?>">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($pj['name'] ?? ''); ?></p>
                            <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($pj['description'] ?? ''); ?></p>
                            <div class="flex items-center gap-3 mt-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-2 max-w-[200px]">
                                    <div class="bg-primary rounded-full h-2" style="width: <?php echo intval($pj['progress'] ?? 0); ?>%"></div>
                                </div>
                                <span class="text-xs text-gray-500"><?php echo intval($pj['progress'] ?? 0); ?>%</span>
                                <span class="text-xs text-gray-400">Created <?php echo date('M d, Y', strtotime($pj['created_at'])); ?></span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold ml-3 <?php
                            echo match($pj['status'] ?? '') {
                                'active','in_progress' => 'bg-blue-100 text-blue-700',
                                'completed' => 'bg-green-100 text-green-700',
                                'on_hold' => 'bg-yellow-100 text-yellow-700',
                                default => 'bg-gray-100 text-gray-700'
                            };
                        ?>"><?php echo ucfirst(str_replace('_', ' ', $pj['status'] ?? 'planning')); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($active_tab === 'overview' && $has_location): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var lat = parseFloat(<?php echo json_encode($lat); ?>);
    var lng = parseFloat(<?php echo json_encode($lng); ?>);
    if (isNaN(lat) || isNaN(lng)) return;
    var map = L.map('client-map').setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
    var popupText = <?php echo json_encode(htmlspecialchars($client['name']) . '<br>' . htmlspecialchars(implode(', ', array_filter([$client['address'] ?? '', $client['city'] ?? '', $client['state'] ?? ''])))); ?>;
    L.marker([lat, lng]).addTo(map).bindPopup(popupText);
    setTimeout(function() { map.invalidateSize(); }, 200);
});
</script>
<?php endif; ?>
</body>
</html>
