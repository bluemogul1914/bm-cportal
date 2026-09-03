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

$success = ''; $error = '';

// CSRF guard for all POST actions on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

// Add customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    $type    = in_array($_POST['type']??'', ['lead','client']) ? $_POST['type'] : 'lead';
    if (!$name) { $error = 'Name is required.'; }
    else {
        $pdo->prepare("INSERT INTO dealer_customers (dealer_id,type,name,email,phone,company,address,notes) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$dealer_id,$type,$name,$email,$phone,$company,$address,$notes]);
        $success = 'Customer added.';
    }
}
// Convert type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_id'])) {
    $cid  = (int)$_POST['convert_id'];
    $type = $_POST['new_type'] ?? 'client';
    $pdo->prepare("UPDATE dealer_customers SET type=?,updated_at=NOW() WHERE id=? AND dealer_id=?")->execute([$type,$cid,$dealer_id]);
    $success = 'Customer updated.';
}
// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $pdo->prepare("DELETE FROM dealer_customers WHERE id=? AND dealer_id=?")->execute([(int)$_POST['delete_id'],$dealer_id]);
    $success = 'Customer removed.';
}

$filter_type = $_GET['type'] ?? '';
$search      = trim($_GET['q'] ?? '');
$page = max(1,(int)($_GET['page']??1)); $per=20;
$where = ["dealer_id=?"]; $params=[$dealer_id];
if ($filter_type) { $where[]="type=?"; $params[]=$filter_type; }
if ($search)      { $where[]="(name ILIKE ? OR email ILIKE ? OR company ILIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }
$wsql=implode(' AND ',$where);
$t=$pdo->prepare("SELECT COUNT(*) FROM dealer_customers WHERE $wsql"); $t->execute($params); $total=(int)$t->fetchColumn();
$total_pages=max(1,ceil($total/$per)); $offset=($page-1)*$per;
$q=$pdo->prepare("SELECT * FROM dealer_customers WHERE $wsql ORDER BY updated_at DESC LIMIT $per OFFSET $offset"); $q->execute($params);
$customers=$q->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT COUNT(*) FROM dealer_customers WHERE dealer_id=? AND type='lead'"); $s->execute([$dealer_id]); $total_leads=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT COUNT(*) FROM dealer_customers WHERE dealer_id=? AND type='client'"); $s->execute([$dealer_id]); $total_clients=(int)$s->fetchColumn();

$show_form = isset($_GET['new']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Customers — Blue Mogul Partner</title>
<link rel="stylesheet" href="/assets/css/tailwind.css">
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
    <div class="px-6 py-4 flex items-center justify-between flex-wrap gap-3">
        <div>
            <p class="text-xs text-gray-400">Partner Portal /</p>
            <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-users text-purple-500 mr-2"></i>My Customers</h1>
        </div>
        <button onclick="document.getElementById('add-form').classList.toggle('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition flex items-center gap-2" data-testid="button-add-customer">
            <i class="fas fa-user-plus"></i> Add Customer
        </button>
    </div>
</header>

<div class="p-6 space-y-5">

<?php if ($success): ?><div class="bg-green-50 border border-green-200 rounded-xl p-3 text-green-800 text-sm flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 rounded-xl p-3 text-red-700 text-sm"><i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-2xl font-bold text-gray-900"><?= $total_leads ?></p>
        <p class="text-sm text-gray-500">Leads</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-2xl font-bold text-gray-900"><?= $total_clients ?></p>
        <p class="text-sm text-gray-500">Clients</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center col-span-2 sm:col-span-1">
        <p class="text-2xl font-bold text-gray-900"><?= $total_leads + $total_clients ?></p>
        <p class="text-sm text-gray-500">Total</p>
    </div>
</div>

<!-- Add Form -->
<div id="add-form" class="<?= ($show_form || $error) ? '' : 'hidden' ?>">
<div class="bg-white rounded-xl border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-900 mb-4"><i class="fas fa-user-plus text-purple-500 mr-2"></i>Add Customer / Lead</h3>
    <form method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?= csrf_field() ?>
        <input type="hidden" name="add_customer" value="1">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
            <div class="flex gap-3">
                <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="type" value="lead" checked class="accent-blue-600"> <span class="text-sm text-gray-700">Lead</span></label>
                <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="type" value="client" class="accent-blue-600"> <span class="text-sm text-gray-700">Client</span></label>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
            <input type="text" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="First Last" required data-testid="input-cust-name">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="email@example.com" data-testid="input-cust-email">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
            <input type="tel" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="(555) 000-0000" data-testid="input-cust-phone">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
            <input type="text" name="company" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="Company name" data-testid="input-cust-company">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
            <input type="text" name="address" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="Street, City, State ZIP" data-testid="input-cust-address">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" placeholder="Any relevant notes..." data-testid="input-cust-notes"></textarea>
        </div>
        <div class="sm:col-span-2 flex gap-3">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2 rounded-lg transition text-sm" data-testid="button-save-customer">Save Customer</button>
            <button type="button" onclick="document.getElementById('add-form').classList.add('hidden')" class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-5 py-2 rounded-lg transition text-sm">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Filters + List -->
<div class="bg-white rounded-xl border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-semibold text-gray-900">Customer List <span class="text-gray-400 font-normal text-sm">(<?= $total ?>)</span></h3>
        <form method="get" class="flex flex-wrap gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-search">
            <select name="type" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()" data-testid="select-filter-type">
                <option value="">All</option>
                <option value="lead" <?= $filter_type==='lead'?'selected':'' ?>>Leads</option>
                <option value="client" <?= $filter_type==='client'?'selected':'' ?>>Clients</option>
            </select>
            <button type="submit" class="bg-gray-100 hover:bg-gray-200 px-3 py-1.5 rounded-lg text-sm"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <?php if ($customers): ?>
    <div class="divide-y divide-gray-50">
        <?php foreach ($customers as $c): ?>
        <div class="px-5 py-4 flex items-center justify-between gap-4 hover:bg-gray-50 transition" data-testid="row-customer-<?= $c['id'] ?>">
            <div class="flex items-center gap-4 min-w-0">
                <div class="w-9 h-9 rounded-full <?= $c['type']==='client' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?> flex items-center justify-center font-bold text-sm flex-shrink-0">
                    <?= strtoupper(substr($c['name'],0,1)) ?>
                </div>
                <div class="min-w-0">
                    <a href="dealer-customer-detail.php?id=<?= $c['id'] ?>" class="font-medium text-gray-900 hover:text-blue-600 truncate block" data-testid="link-customer-<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                    <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($c['company'] ?? $c['email'] ?? '') ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <span class="text-xs px-2.5 py-1 rounded-full font-semibold <?= $c['type']==='client' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>"><?= ucfirst($c['type']) ?></span>
                <a href="dealer-customer-detail.php?id=<?= $c['id'] ?>" class="text-gray-400 hover:text-blue-600 text-sm" title="View"><i class="fas fa-eye"></i></a>
                <form method="post" class="inline" onsubmit="return confirm('Remove this customer?')">
        <?= csrf_field() ?>
                    <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="text-gray-400 hover:text-red-600 text-sm" data-testid="button-delete-customer-<?= $c['id'] ?>"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
        <p class="text-sm text-gray-500">Page <?= $page ?> of <?= $total_pages ?></p>
        <div class="flex gap-2">
            <?php if ($page>1): ?><a href="?page=<?=$page-1?>&type=<?=urlencode($filter_type)?>&q=<?=urlencode($search)?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">← Prev</a><?php endif; ?>
            <?php if ($page<$total_pages): ?><a href="?page=<?=$page+1?>&type=<?=urlencode($filter_type)?>&q=<?=urlencode($search)?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">Next →</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="px-5 py-16 text-center text-gray-400">
        <i class="fas fa-users text-4xl mb-3 block"></i>
        <p class="text-sm">No customers yet.</p>
        <button onclick="document.getElementById('add-form').classList.remove('hidden')" class="mt-3 text-sm text-blue-600 hover:underline">Add your first customer →</button>
    </div>
    <?php endif; ?>
</div>
</div>
</div>
</div>
</body>
</html>
