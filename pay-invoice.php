<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$invoice_id = intval($_GET['id'] ?? 0);
if ($invoice_id <= 0) {
    echo '<script>window.location="/portal/billing.php";</script>';
    exit();
}

$pdo = getDB();
$error_msg = '';

try {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_id = $client ? $client['id'] : $user_id;

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND client_id = ? AND status = 'unpaid'");
    $stmt->execute([$invoice_id, $client_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        echo '<script>window.location="/portal/billing.php";</script>';
        exit();
    }

    $stripe_key = defined('STRIPE_PUBLIC_KEY') ? STRIPE_PUBLIC_KEY : '';

} catch (PDOException $e) {
    error_log("Pay invoice error: " . $e->getMessage());
    echo '<script>window.location="/portal/billing.php";</script>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Invoice - Blue Mogul Client Portal</title>
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
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <a href="billing.php" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-2xl font-semibold text-gray-900">Pay Invoice</h1>
                </div>
            </div>
        </header>

        <div class="p-6 max-w-lg mx-auto">
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-6 py-5 bg-gray-50 border-b border-gray-200 text-center">
                    <p class="text-sm text-gray-500 mb-1">Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                    <p class="text-4xl font-bold text-gray-900">$<?php echo number_format($invoice['amount'], 2); ?></p>
                    <?php if ($invoice['due_date']): ?>
                        <p class="text-sm text-gray-500 mt-2">
                            Due <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
                            <?php if (strtotime($invoice['due_date']) < time()): ?>
                                <span class="text-red-600 font-medium">(Overdue)</span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="p-6">
                    <div class="space-y-3 mb-6">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Invoice Number</span>
                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Date Issued</span>
                            <span class="font-medium text-gray-900"><?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></span>
                        </div>
                        <div class="flex justify-between text-sm border-t border-gray-100 pt-3">
                            <span class="text-gray-900 font-semibold">Total Due</span>
                            <span class="text-gray-900 font-bold text-lg">$<?php echo number_format($invoice['amount'], 2); ?></span>
                        </div>
                    </div>

                    <button onclick="processPayment()" id="pay-button" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-md font-semibold text-sm transition flex items-center justify-center gap-2" data-testid="button-process-payment">
                        <i class="fas fa-lock"></i>
                        <span>Pay $<?php echo number_format($invoice['amount'], 2); ?> with Stripe</span>
                    </button>

                    <p class="text-xs text-gray-400 text-center mt-4">
                        <i class="fas fa-shield-alt mr-1"></i>Payments are securely processed by Stripe
                    </p>
                </div>
            </div>

            <a href="billing.php" class="block text-center text-sm text-gray-500 hover:text-gray-700 mt-4 transition" data-testid="link-back-billing">
                <i class="fas fa-arrow-left mr-1"></i>Back to Billing
            </a>
        </div>
    </div>
</div>

<script>
async function processPayment() {
    const btn = document.getElementById('pay-button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';

    try {
        const response = await fetch('/api/create-checkout-session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                invoice_id: <?php echo $invoice_id; ?>
            })
        });

        const data = await response.json();

        if (data.url) {
            window.location.href = data.url;
        } else if (data.demo) {
            window.location.href = '/portal/payment-success.php?id=<?php echo $invoice_id; ?>&demo=1';
        } else {
            throw new Error(data.error || 'Payment failed');
        }
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-lock mr-2"></i>Pay $<?php echo number_format($invoice['amount'], 2); ?> with Stripe';
        alert('Payment error: ' + err.message);
    }
}
</script>
</body>
</html>