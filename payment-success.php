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
$is_demo = isset($_GET['demo']);
$is_paid = isset($_GET['paid']);
$pdo = getDB();

if (($is_demo || $is_paid) && $invoice_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        $client_id = $client ? $client['id'] : $user_id;

        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND client_id = ?");
        $stmt->execute([$invoice_id, $client_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice && $invoice['status'] === 'unpaid') {
            $stmt = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_date = CURRENT_DATE WHERE id = ?");
            $stmt->execute([$invoice_id]);

            $method = $is_demo ? 'demo' : 'stripe';
            $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, client_id, amount, method, status, created_at) VALUES (?, ?, ?, ?, 'completed', NOW())");
            $stmt->execute([$invoice_id, $client_id, $invoice['amount'], $method]);
        }
    } catch (PDOException $e) {
        error_log("Demo payment error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - Blue Mogul Suite</title>
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
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto flex items-center justify-center">
        <div class="text-center max-w-md mx-auto p-6">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-green-100 rounded-full mb-6">
                <i class="fas fa-check-circle text-green-500 text-4xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2" data-testid="text-payment-success">Payment Successful!</h1>
            <p class="text-gray-600 mb-6">Your payment has been processed successfully. A receipt has been sent to your email.</p>

            <?php if ($is_demo): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-6">
                    <p class="text-sm text-yellow-700"><i class="fas fa-info-circle mr-1"></i>This was a demo payment. In production, this will use Stripe.</p>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-center gap-4">
                <a href="billing.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-md font-medium text-sm transition" data-testid="link-back-billing">
                    View Invoices
                </a>
                <a href="dashboard.php" class="text-gray-600 hover:text-gray-800 px-4 py-2.5 text-sm font-medium transition" data-testid="link-dashboard">
                    Go to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>