<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$success_msg = '';
$error_msg = '';
$new_client_id = 0;
$pdo = getDB();

$form_data = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'company' => '',
    'address' => '',
    'city' => '',
    'state' => '',
    'zip' => '',
    'notes' => '',
    'latitude' => '',
    'longitude' => '',
    'credit_balance' => '0.00',
    'parent_client_id' => 0,
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_client') {
    require_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip = trim($_POST['zip'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $credit_balance = floatval($_POST['credit_balance'] ?? 0);
    $parent_client_id = intval($_POST['parent_client_id'] ?? 0);
    if ($parent_client_id <= 0) $parent_client_id = null;

    $form_data = compact('name', 'email', 'phone', 'company', 'address', 'city', 'state', 'zip', 'notes', 'latitude', 'longitude', 'credit_balance', 'parent_client_id');

    if (empty($name) || empty($email)) {
        $error_msg = 'Name and email are required.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, company, address, city, state, zip, notes, latitude, longitude, credit_balance, parent_client_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) RETURNING id");
            $stmt->execute([$name, $email, $phone, $company, $address, $city, $state, $zip, $notes, $latitude ?: null, $longitude ?: null, $credit_balance, $parent_client_id]);
            $new_client_id = $stmt->fetchColumn();
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'client_created', 'client', $new_client_id, 'Created client: ' . $name, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_msg = 'Client created successfully!';
            $form_data = [
                'name' => '', 'email' => '', 'phone' => '', 'company' => '',
                'address' => '', 'city' => '', 'state' => '', 'zip' => '',
                'notes' => '', 'latitude' => '', 'longitude' => '',
                'credit_balance' => '0.00', 'parent_client_id' => 0,
            ];
        } catch (PDOException $e) {
            error_log("Client create error: " . $e->getMessage());
            if (strpos($e->getMessage(), 'clients_email_unique') !== false || strpos($e->getMessage(), 'duplicate key') !== false) {
                $error_msg = 'A client with this email address already exists.';
            } else {
                $error_msg = 'Failed to create client.';
            }
        }
    }
}

try {
    $all_clients = $pdo->query("SELECT id, name, company FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Client add load error: " . $e->getMessage());
    $all_clients = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
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
                    <a href="admin-clients.php" class="text-gray-400 hover:text-gray-600 transition" data-testid="link-back-clients"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-2xl font-semibold text-gray-900">Add Client</h1>
                </div>
            </div>
        </header>

        <div class="p-6 max-w-3xl">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                    <?php if ($new_client_id): ?>
                    <a href="admin-client-detail.php?id=<?php echo $new_client_id; ?>" class="ml-auto text-green-700 underline text-sm" data-testid="link-view-client">View Client</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="admin-client-add.php" class="space-y-6">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_client">

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Client Information</h2>
                    </div>
                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($form_data['name']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-name">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-email">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($form_data['phone']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-phone">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                                <input type="text" name="company" value="<?php echo htmlspecialchars($form_data['company']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-company">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <input type="text" name="address" value="<?php echo htmlspecialchars($form_data['address']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-address">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                                <input type="text" name="city" value="<?php echo htmlspecialchars($form_data['city']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-city">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">State</label>
                                <input type="text" name="state" value="<?php echo htmlspecialchars($form_data['state']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-state">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">ZIP Code</label>
                                <input type="text" name="zip" value="<?php echo htmlspecialchars($form_data['zip']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-zip">
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
                                <option value="<?php echo $c['id']; ?>" <?php echo ($form_data['parent_client_id'] ?? 0) == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?><?php if ($c['company']) echo ' (' . htmlspecialchars($c['company']) . ')'; ?>
                                </option>
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
                                <input type="text" name="latitude" value="<?php echo htmlspecialchars($form_data['latitude']); ?>" placeholder="e.g. 29.7604" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-latitude">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Longitude</label>
                                <input type="text" name="longitude" value="<?php echo htmlspecialchars($form_data['longitude']); ?>" placeholder="e.g. -95.3698" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-longitude">
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
                            <input type="number" name="credit_balance" step="0.01" value="<?php echo number_format((float)($form_data['credit_balance'] ?? 0), 2, '.', ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-credit-balance">
                            <p class="text-xs text-gray-400 mt-1">Applied against future invoices.</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-sticky-note text-yellow-500 mr-2"></i>Notes</h2>
                    </div>
                    <div class="p-6">
                        <textarea name="notes" rows="4" placeholder="Internal notes about this client..." class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-notes"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-3">
                    <a href="admin-clients.php" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition" data-testid="button-cancel">Cancel</a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-save-client">
                        <i class="fas fa-plus mr-2"></i>Create Client
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
