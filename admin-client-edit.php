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

$success_msg = '';
$error_msg = '';
$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_client') {
    require_csrf();
    
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip = trim($_POST['zip'] ?? '');
    $notes_raw = trim($_POST['notes'] ?? '');
    $notes_tags_hidden = $_POST['notes_tags_hidden'] ?? '';
    $existing_tags = json_decode($notes_tags_hidden, true) ?: [];
    $notes = $notes_raw;
    if (!empty($existing_tags)) {
        $notes = $notes_raw . ' [TAGS:' . json_encode($existing_tags) . ']';
    }
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $credit_balance = floatval($_POST['credit_balance'] ?? 0);
    $parent_client_id = intval($_POST['parent_client_id'] ?? 0);
    if ($parent_client_id <= 0 || $parent_client_id === $client_id) $parent_client_id = null;
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');

    if (empty($name) || empty($email)) {
        $error_msg = 'Name and email are required.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, company = ?, address = ?, city = ?, state = ?, zip = ?, notes = ?, latitude = ?, longitude = ?, credit_balance = ?, parent_id = ?, linkedin_url = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $email, $phone, $company, $address, $city, $state, $zip, $notes, $latitude ?: null, $longitude ?: null, $credit_balance, $parent_client_id, $linkedin_url ?: null, $client_id]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'client_updated', 'client', $client_id, 'Updated client: ' . $name, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_msg = 'Client updated successfully!';
        } catch (PDOException $e) {
            error_log("Client update error: " . $e->getMessage());
            $error_msg = 'Failed to update client.';
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        portal_redirect('/portal/admin-clients.php');
    }

    $all_clients = $pdo->query("SELECT id, name, company FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Client edit error: " . $e->getMessage());
    portal_redirect('/portal/admin-clients.php');
}

$client_notes_raw = $client['notes'] ?? '';
$client_tags = [];
$client_notes_clean = $client_notes_raw;
if (preg_match('/\[TAGS:(.*?)\]/', $client_notes_raw, $m)) {
    $client_tags = json_decode($m[1], true) ?: [];
    $client_notes_clean = trim(preg_replace('/\[TAGS:.*?\]/', '', $client_notes_raw));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit <?php echo htmlspecialchars($client['name']); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <a href="admin-client-detail.php?id=<?php echo $client_id; ?>" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-2xl font-semibold text-gray-900">Edit Client</h1>
                </div>
            </div>
        </header>

        <div class="p-6 max-w-3xl">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                    <a href="admin-client-detail.php?id=<?php echo $client_id; ?>" class="ml-auto text-green-700 underline text-sm">View Client</a>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="admin-client-edit.php?id=<?php echo $client_id; ?>" class="space-y-6">
                            <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_client">

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Client Information</h2>
                    </div>
                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($client['name']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-name">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($client['email']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-email">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-phone">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                                <input type="text" name="company" value="<?php echo htmlspecialchars($client['company'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-company">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><svg class="inline-block w-4 h-4 mr-1" style="fill:#0A66C2;vertical-align:-2px" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>LinkedIn Profile URL</label>
                            <input type="url" name="linkedin_url" value="<?php echo htmlspecialchars($client['linkedin_url'] ?? ''); ?>" placeholder="https://www.linkedin.com/in/username" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-linkedin-url">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <input type="text" name="address" value="<?php echo htmlspecialchars($client['address'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-address">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                                <input type="text" name="city" value="<?php echo htmlspecialchars($client['city'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-city">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">State</label>
                                <input type="text" name="state" value="<?php echo htmlspecialchars($client['state'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-state">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">ZIP Code</label>
                                <input type="text" name="zip" value="<?php echo htmlspecialchars($client['zip'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-zip">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-building text-primary mr-2"></i>Account Hierarchy</h2>
                    </div>
                    <div class="p-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Parent Account (Master Account)</label>
                            <p class="text-xs text-gray-500 mb-2">Assign this client as a sub-account under a business/master account.</p>
                            <select name="parent_client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-parent-account">
                                <option value="0">— No Parent (This is a Master Account) —</option>
                                <?php foreach ($all_clients as $c): ?>
                                    <?php if ($c['id'] !== $client_id): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($client['parent_id'] ?? 0) == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['name']); ?><?php if ($c['company']) echo ' (' . htmlspecialchars($c['company']) . ')'; ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-map-marker-alt text-red-500 mr-2"></i>Location</h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Latitude</label>
                                <input type="text" name="latitude" value="<?php echo htmlspecialchars($client['latitude'] ?? ''); ?>" placeholder="e.g. 29.7604" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-latitude">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Longitude</label>
                                <input type="text" name="longitude" value="<?php echo htmlspecialchars($client['longitude'] ?? ''); ?>" placeholder="e.g. -95.3698" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-longitude">
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mt-2"><i class="fas fa-info-circle mr-1"></i>Used for the map on the client detail page. If empty, the city name will be used for an approximate location.</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-dollar-sign text-green-500 mr-2"></i>Billing</h2>
                    </div>
                    <div class="p-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Credit Balance ($)</label>
                            <input type="number" name="credit_balance" step="0.01" value="<?php echo number_format((float)($client['credit_balance'] ?? 0), 2, '.', ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-credit-balance">
                            <p class="text-xs text-gray-400 mt-1">Applied against future invoices.</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-sticky-note text-yellow-500 mr-2"></i>Notes</h2>
                    </div>
                    <div class="p-6">
                        <textarea name="notes" rows="4" placeholder="Internal notes about this client..." class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-notes"><?php echo htmlspecialchars($client_notes_clean); ?></textarea>
                        <?php if (!empty($client_tags)): ?>
                        <input type="hidden" name="notes_tags_hidden" value="<?php echo htmlspecialchars(json_encode($client_tags)); ?>">
                        <p class="text-xs text-gray-400 mt-1">Tags: <?php echo htmlspecialchars(implode(', ', $client_tags)); ?> (managed on client detail page)</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-3">
                    <a href="admin-client-detail.php?id=<?php echo $client_id; ?>" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition">Cancel</a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-save-client">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
