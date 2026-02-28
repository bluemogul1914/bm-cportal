<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    echo '<script>window.location="/portal";</script>';
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$client_id = intval($_GET['id'] ?? 0);
if ($client_id <= 0) {
    echo '<script>window.location="/portal/admin-clients.php";</script>';
    exit();
}

$pdo = getDB();

try {
    $stmt = $pdo->prepare("SELECT c.*, u.email as user_email, u.created_at as user_created_at FROM clients c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        echo '<script>window.location="/portal/admin-clients.php";</script>';
        exit();
    }

    $stmt = $pdo->prepare("SELECT t.* FROM tickets t WHERE t.client_id = ? ORDER BY t.created_at DESC LIMIT 10");
    $stmt->execute([$client_id]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT i.* FROM invoices i WHERE i.client_id = ? ORDER BY i.created_at DESC LIMIT 10");
    $stmt->execute([$client_id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT s.*, p.name as product_name, p.price FROM subscriptions s JOIN products p ON s.product_id = p.id WHERE s.client_id = ? ORDER BY s.status ASC, s.created_at DESC");
    $stmt->execute([$client_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT d.* FROM documents d WHERE d.client_id = ? ORDER BY d.created_at DESC LIMIT 10");
    $stmt->execute([$client_id]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(mrr), 0) as total_mrr FROM subscriptions WHERE client_id = ? AND status = 'active'");
    $stmt->execute([$client_id]);
    $total_mrr = $stmt->fetch(PDO::FETCH_ASSOC)['total_mrr'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM invoices WHERE client_id = ? AND status = 'unpaid'");
    $stmt->execute([$client_id]);
    $outstanding = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

} catch (PDOException $e) {
    error_log("Client detail error: " . $e->getMessage());
    echo '<script>window.location="/portal/admin-clients.php";</script>';
    exit();
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
            <div class="px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="admin-clients.php" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-arrow-left"></i></a>
                    <div class="bg-blue-600 text-white rounded-full h-10 w-10 flex items-center justify-center font-bold text-lg">
                        <?php echo strtoupper(substr($client['name'], 0, 1)); ?>
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($client['name']); ?></h1>
                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($client['company'] ?? ''); ?></p>
                    </div>
                </div>
                <a href="admin-client-edit.php?id=<?php echo $client_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="link-edit-client">
                    <i class="fas fa-edit mr-2"></i>Edit Client
                </a>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">MRR</p>
                    <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($total_mrr, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Outstanding</p>
                    <p class="text-2xl font-bold <?php echo $outstanding > 0 ? 'text-red-600' : 'text-gray-900'; ?>">$<?php echo number_format($outstanding, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active Services</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($services, fn($s) => $s['status'] === 'active')); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Open Tickets</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($tickets, fn($t) => $t['status'] !== 'closed')); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Tickets</h2>
                            <span class="text-sm text-gray-500"><?php echo count($tickets); ?> total</span>
                        </div>
                        <?php if (empty($tickets)): ?>
                            <div class="p-6 text-center text-gray-500 text-sm">No tickets found.</div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($tickets as $t): ?>
                                    <a href="admin-ticket-detail.php?id=<?php echo $t['id']; ?>" class="flex items-center px-6 py-3 hover:bg-gray-50 transition">
                                        <div class="flex-1 min-w-0">
                                            <p class="font-medium text-gray-900 text-sm truncate"><?php echo htmlspecialchars($t['subject']); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($t['created_at'])); ?></p>
                                        </div>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                                            echo match($t['status']) {
                                                'open' => 'bg-blue-100 text-blue-700',
                                                'in_progress' => 'bg-yellow-100 text-yellow-700',
                                                'closed' => 'bg-gray-100 text-gray-700',
                                                default => 'bg-gray-100 text-gray-700'
                                            };
                                        ?>"><?php echo ucfirst(str_replace('_', ' ', $t['status'])); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Invoices</h2>
                            <span class="text-sm text-gray-500"><?php echo count($invoices); ?> total</span>
                        </div>
                        <?php if (empty($invoices)): ?>
                            <div class="p-6 text-center text-gray-500 text-sm">No invoices found.</div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($invoices as $inv): ?>
                                    <a href="admin-invoice-detail.php?id=<?php echo $inv['id']; ?>" class="flex items-center justify-between px-6 py-3 hover:bg-gray-50 transition">
                                        <div>
                                            <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($inv['invoice_number']); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($inv['created_at'])); ?></p>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="font-semibold text-gray-900 text-sm">$<?php echo number_format($inv['amount'], 2); ?></span>
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                                                echo $inv['status'] === 'paid' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700';
                                            ?>"><?php echo ucfirst($inv['status']); ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900">Services</h2>
                        </div>
                        <?php if (empty($services)): ?>
                            <div class="p-6 text-center text-gray-500 text-sm">No services found.</div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($services as $s): ?>
                                    <div class="flex items-center justify-between px-6 py-3">
                                        <div>
                                            <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($s['product_name']); ?></p>
                                            <p class="text-xs text-gray-500">$<?php echo number_format($s['price'], 2); ?>/mo</p>
                                        </div>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                                            echo $s['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                                        ?>"><?php echo ucfirst($s['status']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-900">Contact Information</h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <div>
                                <p class="text-xs text-gray-500">Email</p>
                                <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($client['email']); ?></p>
                            </div>
                            <?php if ($client['phone']): ?>
                            <div>
                                <p class="text-xs text-gray-500">Phone</p>
                                <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($client['phone']); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($client['company']): ?>
                            <div>
                                <p class="text-xs text-gray-500">Company</p>
                                <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($client['company']); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($client['address']): ?>
                            <div>
                                <p class="text-xs text-gray-500">Address</p>
                                <p class="font-medium text-gray-900 text-sm">
                                    <?php echo htmlspecialchars($client['address']); ?>
                                    <?php if ($client['city'] || $client['state'] || $client['zip']): ?>
                                        <br><?php echo htmlspecialchars(implode(', ', array_filter([$client['city'], $client['state'], $client['zip']]))); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="text-xs text-gray-500">Client Since</p>
                                <p class="font-medium text-gray-900 text-sm"><?php echo date('M d, Y', strtotime($client['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($documents)): ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-900">Documents</h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($documents as $doc): ?>
                                <div class="px-6 py-3 flex items-center gap-3">
                                    <i class="fas fa-file text-gray-400"></i>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-medium text-gray-900 text-sm truncate"><?php echo htmlspecialchars($doc['name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>