<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'dealer' && !($_SESSION['is_admin'] ?? false))) {
    portal_redirect('/portal');
}
$user_name  = $_SESSION['user_name'] ?? 'Dealer';
$user_email = $_SESSION['user_email'] ?? '';
$user_id    = $_SESSION['user_id'];
$pdo = getDB();
$dealer = $pdo->prepare("SELECT * FROM dealers WHERE user_id=?"); $dealer->execute([$user_id]); $dealer = $dealer->fetch(PDO::FETCH_ASSOC);
if (!$dealer) portal_redirect('/portal/dealer-dashboard.php');
$dealer_id = $dealer['id'];

// Products & spiff table
$products = [
    ['name'=>'Frontier Fiber',          'markets'=>'TX, LA, NC',       'base'=>100,'gold'=>120,'notes'=>'Per install, prepaid monthly',  'icon'=>'fa-wifi',       'color'=>'text-red-500'],
    ['name'=>'Xfinity Prepaid Internet','markets'=>'Xfinity markets',  'base'=>35, 'gold'=>42, 'notes'=>'No credit check required',       'icon'=>'fa-broadcast-tower','color'=>'text-blue-500'],
    ['name'=>'Verizon Prepaid Wireless','markets'=>'Nationwide',        'base'=>30, 'gold'=>36, 'notes'=>'Per activation',                 'icon'=>'fa-mobile-alt', 'color'=>'text-red-600'],
    ['name'=>'Black Wireless',          'markets'=>'Nationwide',        'base'=>20, 'gold'=>24, 'notes'=>'Per activation',                 'icon'=>'fa-sim-card',   'color'=>'text-gray-700'],
    ['name'=>'TravelSim / eSIM',        'markets'=>'Worldwide',         'base'=>15, 'gold'=>18, 'notes'=>'Digital fulfillment',            'icon'=>'fa-globe',      'color'=>'text-green-500'],
    ['name'=>'Sling TV',                'markets'=>'Nationwide',        'base'=>12, 'gold'=>14, 'notes'=>'Per new subscriber',             'icon'=>'fa-tv',         'color'=>'text-orange-500'],
];

// Load knowledge base articles
$articles = $pdo->query("SELECT id, title, category, created_at FROM knowledge_articles ORDER BY category, title")->fetchAll(PDO::FETCH_ASSOC);
$by_cat = [];
foreach ($articles as $a) { $by_cat[$a['category'] ?? 'General'][] = $a; }

$tab = $_GET['tab'] ?? 'spiffs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Training & Spiffs — Blue Mogul Partner</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/dealer-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4">
        <p class="text-xs text-gray-400">Partner Portal /</p>
        <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-book-open text-orange-500 mr-2"></i>Training & Product Docs</h1>
    </div>
</header>

<div class="p-6 space-y-6">

    <!-- Tabs -->
    <div class="flex border-b border-gray-200 gap-1">
        <a href="?tab=spiffs" class="px-4 py-2.5 text-sm font-medium border-b-2 transition <?= $tab==='spiffs' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>" data-testid="tab-spiffs">
            <i class="fas fa-dollar-sign mr-1"></i>Products & Spiffs
        </a>
        <a href="?tab=kb" class="px-4 py-2.5 text-sm font-medium border-b-2 transition <?= $tab==='kb' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>" data-testid="tab-kb">
            <i class="fas fa-graduation-cap mr-1"></i>Knowledge Base
        </a>
        <a href="?tab=howto" class="px-4 py-2.5 text-sm font-medium border-b-2 transition <?= $tab==='howto' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>" data-testid="tab-howto">
            <i class="fas fa-question-circle mr-1"></i>How-To Guide
        </a>
    </div>

    <?php if ($tab === 'spiffs'): ?>
    <!-- Products & Spiffs Table -->
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
        <i class="fas fa-star mr-2 text-yellow-500"></i>
        <strong>Gold Tier Dealers</strong> receive <strong>+20%</strong> on all activations (10 completed orders/month). Spiff amounts shown are base tier.
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Spiff Schedule</h3>
            <p class="text-xs text-gray-500 mt-0.5">Updated monthly · Commissions release within 24 hrs of confirmed prepaid activation · Payouts via ACH every Friday</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Service</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Markets</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Base Spiff</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                            <span class="text-yellow-600"><i class="fas fa-star mr-1"></i>Gold Spiff</span>
                        </th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($products as $p): ?>
                    <tr class="hover:bg-gray-50 transition" data-testid="row-product-<?= strtolower(str_replace(' ','-',$p['name'])) ?>">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <i class="fas <?= $p['icon'] ?> <?= $p['color'] ?> text-sm"></i>
                                </div>
                                <span class="font-semibold text-gray-900"><?= htmlspecialchars($p['name']) ?></span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-gray-600 text-xs"><?= htmlspecialchars($p['markets']) ?></td>
                        <td class="px-5 py-4"><span class="font-bold text-green-700 text-base">$<?= $p['base'] ?></span></td>
                        <td class="px-5 py-4"><span class="font-bold text-yellow-600 text-base">$<?= $p['gold'] ?></span></td>
                        <td class="px-5 py-4 text-gray-500 text-xs"><?= htmlspecialchars($p['notes']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tier Comparison -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center"><i class="fas fa-user text-blue-600 text-sm"></i></div>
                <span class="font-semibold text-gray-900">Base Tier</span>
            </div>
            <p class="text-sm text-gray-600">Standard spiff rates for all new dealers.</p>
            <p class="text-xs text-gray-400 mt-2">0-4 activations/month</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-8 h-8 bg-gray-200 rounded-lg flex items-center justify-center"><i class="fas fa-medal text-gray-600 text-sm"></i></div>
                <span class="font-semibold text-gray-900">Silver Tier</span>
            </div>
            <p class="text-sm text-gray-600">5+ activations per month unlocks priority support.</p>
            <p class="text-xs text-gray-400 mt-2">5-9 activations/month</p>
        </div>
        <div class="bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-xl p-5 text-white">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-8 h-8 bg-yellow-300/30 rounded-lg flex items-center justify-center"><i class="fas fa-star text-white text-sm"></i></div>
                <span class="font-bold">Gold Tier</span>
            </div>
            <p class="text-sm text-yellow-100">+20% spiff on ALL activations. Top priority support.</p>
            <p class="text-xs text-yellow-200 mt-2">10+ activations/month</p>
        </div>
    </div>

    <?php elseif ($tab === 'kb'): ?>
    <!-- Knowledge Base -->
    <?php if ($by_cat): ?>
    <div class="space-y-5">
        <?php foreach ($by_cat as $cat => $arts): ?>
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                <h3 class="font-semibold text-gray-700 text-sm"><i class="fas fa-folder-open text-yellow-500 mr-2"></i><?= htmlspecialchars($cat) ?></h3>
            </div>
            <div class="divide-y divide-gray-50">
                <?php foreach ($arts as $a): ?>
                <a href="admin-knowledge.php?view=<?= $a['id'] ?>" target="_blank" class="flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition" data-testid="link-article-<?= $a['id'] ?>">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-file-alt text-blue-400 text-sm"></i>
                        <span class="text-sm text-gray-800 hover:text-blue-600"><?= htmlspecialchars($a['title']) ?></span>
                    </div>
                    <i class="fas fa-external-link-alt text-gray-300 text-xs"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl border border-gray-200 px-5 py-16 text-center text-gray-400">
        <i class="fas fa-book text-4xl mb-3 block"></i>
        <p class="text-sm">No knowledge base articles yet. Contact your Blue Mogul representative.</p>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- How-To Guide -->
    <div class="space-y-4">
        <?php foreach ([
            ['icon'=>'fa-user-plus','color'=>'bg-blue-100 text-blue-600','title'=>'Step 1: Register a new client',
             'body'=>'Go to <strong>My Customers</strong> → Add Customer. Enter the client\'s name, contact info, and service address. Mark them as a "Lead" until the order is confirmed.'],
            ['icon'=>'fa-clipboard-list','color'=>'bg-green-100 text-green-600','title'=>'Step 2: Submit the order',
             'body'=>'Go to <strong>Submit Order</strong>. Select the product line — the spiff amount will be shown automatically. Fill in client info and submit. Your spiff will appear as "Pending" instantly.'],
            ['icon'=>'fa-dollar-sign','color'=>'bg-yellow-100 text-yellow-600','title'=>'Step 3: Commission is released',
             'body'=>'Once the client\'s service is confirmed and activated (within 24 hrs of prepaid activation), your commission moves from Pending → Approved and is queued for the next Friday ACH payout.'],
            ['icon'=>'fa-university','color'=>'bg-purple-100 text-purple-600','title'=>'Step 4: Get paid via ACH',
             'body'=>'Add your bank info in <strong>Payouts & ACH</strong>. Funds are automatically sent every Friday. You can also request an early payout at any time if your balance is approved.'],
            ['icon'=>'fa-star','color'=>'bg-orange-100 text-orange-600','title'=>'Bonus: Earn Gold Tier',
             'body'=>'Complete 10+ activations in a calendar month to unlock Gold Tier. Gold dealers earn <strong>+20%</strong> on every spiff for that entire month. Keep the streak to maintain it!'],
        ] as $step): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-5 flex gap-4">
            <div class="w-10 h-10 rounded-xl <?= $step['color'] ?> flex items-center justify-center flex-shrink-0">
                <i class="fas <?= $step['icon'] ?>"></i>
            </div>
            <div>
                <h4 class="font-semibold text-gray-900"><?= $step['title'] ?></h4>
                <p class="text-sm text-gray-600 mt-1"><?= $step['body'] ?></p>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="bg-blue-50 border border-blue-200 rounded-xl p-5 text-sm text-blue-800">
            <p class="font-semibold mb-1"><i class="fas fa-headset mr-2"></i>Need help?</p>
            <p>Contact your Blue Mogul account rep at <a href="mailto:<?= htmlspecialchars(ADMIN_EMAIL) ?>" class="underline"><?= htmlspecialchars(ADMIN_EMAIL) ?></a> or call <a href="tel:<?= preg_replace('/\D/','',SUPPORT_PHONE) ?>" class="underline"><?= htmlspecialchars(SUPPORT_PHONE) ?></a>.</p>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>
</div>
</body>
</html>
