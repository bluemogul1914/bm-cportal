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

$success_msg = '';
$error_msg = '';
$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $due_date = $_POST['due_date'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if ($client_id <= 0 || $amount <= 0) {
        $error_msg = 'Client and amount are required.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(REPLACE(invoice_number, 'INV-', '') AS INTEGER)), 0) + 1 as next_num FROM invoices WHERE invoice_number LIKE 'INV-%'");
            $stmt->execute();
            $next = $stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
            $invoice_number = 'INV-' . str_pad($next, 5, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO invoices (client_id, invoice_number, amount, status, due_date, created_at) VALUES (?, ?, ?, 'unpaid', ?, NOW())");
            $stmt->execute([$client_id, $invoice_number, $amount, !empty($due_date) ? $due_date : null]);
            $success_msg = "Invoice $invoice_number created successfully!";
        } catch (PDOException $e) {
            error_log("Invoice creation error: " . $e->getMessage());
            $error_msg = 'Failed to create invoice.';
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, company FROM clients ORDER BY name");
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clients = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Invoice - Admin</title>
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
                    <a href="admin-invoices.php" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-2xl font-semibold text-gray-900">Create Invoice</h1>
                </div>
            </div>
        </header>

        <div class="p-6 max-w-2xl">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                    <a href="admin-invoices.php" class="ml-auto text-green-700 underline text-sm">View Invoices</a>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Invoice Details</h2>
                </div>
                <form method="POST" action="admin-invoice-add.php" class="p-6 space-y-5">
                    <input type="hidden" name="action" value="create_invoice">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                        <select name="client_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-client">
                            <option value="">Select a client...</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> <?php echo $c['company'] ? '(' . htmlspecialchars($c['company']) . ')' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount ($) *</label>
                            <input type="number" name="amount" step="0.01" min="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0.00" data-testid="input-amount">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                            <input type="date" name="due_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-due-date">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description / Notes</label>
                        <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional invoice notes..." data-testid="textarea-description"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-3">
                        <a href="admin-invoices.php" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition">Cancel</a>
                        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-create-invoice">
                            <i class="fas fa-plus mr-2"></i>Create Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>