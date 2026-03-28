<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

$success_msg = ''; $error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token.';
    } else {
        $full_name       = trim($_POST['full_name']       ?? '');
        $pipeline_status = trim($_POST['pipeline_status'] ?? 'new_enquiry');
        $owner           = trim($_POST['owner']           ?? 'Me');
        $phone           = trim($_POST['phone']           ?? '');
        $email           = trim($_POST['email']           ?? '');
        $source          = trim($_POST['source']          ?? '');
        $partner         = trim($_POST['partner']         ?? '');
        $location        = trim($_POST['location']        ?? '');
        $city            = trim($_POST['city']            ?? '');
        $street          = trim($_POST['street']          ?? '');
        $zip_code        = trim($_POST['zip_code']        ?? '');
        $geo_data        = trim($_POST['geo_data']        ?? '');
        $custom_status   = trim($_POST['custom_status']   ?? 'customer');

        if (!$full_name) {
            $error_msg = 'Full name is required.';
        } else {
            try {
                $st = $pdo->prepare("INSERT INTO leads (full_name,pipeline_status,owner,phone,email,source,partner,location,city,street,zip_code,geo_data,custom_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id");
                $st->execute([$full_name,$pipeline_status,$owner,$phone,$email,$source,$partner,$location,$city,$street,$zip_code,$geo_data,$custom_status]);
                $lead_id = $st->fetchColumn();
                // Generate lead number
                $pdo->prepare("UPDATE leads SET lead_number=LPAD(id::text,6,'0') WHERE id=?")->execute([$lead_id]);
                // Log activity
                $pdo->prepare("INSERT INTO lead_activities (lead_id,action,actor) VALUES (?,?,?)")->execute([$lead_id,"Create lead with name: $full_name",$_SESSION['user_name']??'Admin']);
                header("Location: admin-leads-view.php?id=$lead_id&added=1");
                exit;
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
            }
        }
    }
}

// Sources for dropdown (existing unique sources + add new)
$existing_sources = $pdo->query("SELECT DISTINCT source FROM leads WHERE source IS NOT NULL AND source!='' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);
$partners         = $pdo->query("SELECT DISTINCT partner FROM leads WHERE partner IS NOT NULL AND partner!='' ORDER BY partner")->fetchAll(PDO::FETCH_COLUMN);
$locations        = $pdo->query("SELECT DISTINCT location FROM leads WHERE location IS NOT NULL AND location!='' ORDER BY location")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Add Lead — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-0.5">
                <a href="admin-leads-dashboard.php" class="hover:text-blue-600">Leads</a> /
            </nav>
            <h1 class="text-2xl font-semibold text-gray-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center"><i class="fas fa-user-tag text-white text-sm"></i></span>
                Add lead
            </h1>
        </div>
        <a href="admin-leads-list.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">
            <i class="fas fa-arrow-left mr-2"></i>Back to List
        </a>
    </div>
</header>

<div class="p-6 max-w-3xl">
<?php if ($error_msg): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg"><?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<div class="bg-white rounded-lg border border-gray-200 p-8">
    <form method="POST" class="space-y-5" id="add-lead-form">
        <?= csrf_field() ?>

        <!-- Full name -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Full name</label>
            <div class="col-span-2">
                <input type="text" name="full_name" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-full-name">
            </div>
        </div>

        <!-- Pipeline status -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Pipeline status</label>
            <div class="col-span-2">
                <select name="pipeline_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="select-pipeline-status">
                    <option value="new_enquiry">New enquiry</option>
                    <option value="qualification">Qualification</option>
                    <option value="activation">Activation</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                </select>
            </div>
        </div>

        <!-- Owner -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Owner</label>
            <div class="col-span-2">
                <select name="owner" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="select-owner">
                    <option value="Me">Me</option>
                    <option value="<?= htmlspecialchars($user_name ?? 'Admin') ?>"><?= htmlspecialchars($user_name ?? 'Admin') ?></option>
                </select>
            </div>
        </div>

        <!-- Phone -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Phone number</label>
            <div class="col-span-2">
                <input type="tel" name="phone"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-phone">
            </div>
        </div>

        <!-- Email -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Email</label>
            <div class="col-span-2">
                <input type="email" name="email"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-email">
            </div>
        </div>

        <!-- Source -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Source</label>
            <div class="col-span-2">
                <input type="text" name="source" list="source-list" placeholder="Select the source or create a new one"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-source">
                <datalist id="source-list">
                    <?php foreach ($existing_sources as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <!-- Partner -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Partner</label>
            <div class="col-span-2">
                <input type="text" name="partner" list="partner-list"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-partner">
                <datalist id="partner-list">
                    <?php foreach ($partners as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <!-- Location -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Location</label>
            <div class="col-span-2">
                <input type="text" name="location" list="location-list"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-location">
                <datalist id="location-list">
                    <?php foreach ($locations as $l): ?><option value="<?= htmlspecialchars($l) ?>"><?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <!-- City -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">City</label>
            <div class="col-span-2">
                <input type="text" name="city"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-city">
            </div>
        </div>

        <!-- Street -->
        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">Street</label>
            <div class="col-span-2">
                <input type="text" name="street"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-street">
            </div>
        </div>

        <!-- Show more fields -->
        <div id="more-fields" class="hidden space-y-5">
            <div class="grid grid-cols-3 items-center gap-4">
                <label class="text-sm font-medium text-gray-600 text-right">ZIP Code</label>
                <div class="col-span-2">
                    <input type="text" name="zip_code"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-zip">
                </div>
            </div>
            <div class="grid grid-cols-3 items-center gap-4">
                <label class="text-sm font-medium text-gray-600 text-right">Geo data</label>
                <div class="col-span-2">
                    <input type="text" name="geo_data" placeholder="lat,lng"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-geodata">
                </div>
            </div>
            <div class="grid grid-cols-3 items-center gap-4">
                <label class="text-sm font-medium text-gray-600 text-right">Custom status</label>
                <div class="col-span-2">
                    <select name="custom_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="customer">Customer</option>
                        <option value="prospect">Prospect</option>
                        <option value="partner">Partner</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div></div>
            <div class="col-span-2 flex items-center gap-4">
                <button type="button" onclick="document.getElementById('more-fields').classList.toggle('hidden')"
                    class="text-blue-500 hover:text-blue-700 text-sm font-medium">
                    <i class="fas fa-chevron-down mr-1"></i>Show more fields
                </button>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 pt-2">
            <div></div>
            <div class="col-span-2">
                <button type="submit" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-lead">
                    Add
                </button>
            </div>
        </div>
    </form>
</div>
</div>
</div>
</div>
</body>
</html>
