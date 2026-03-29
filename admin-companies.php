<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

// ── AJAX: return companies as JSON ────────────────────────────────────────────
if (isset($_GET['ajax_companies'])) {
    $list = [];
    try { $list = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
    header('Content-Type: application/json');
    echo json_encode(['companies' => $list]);
    exit;
}

$success_msg = '';
$error_msg   = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_company') {
            $name  = trim($_POST['name']      ?? '');
            $web   = trim($_POST['website']   ?? '');
            $phone = trim($_POST['phone']     ?? '');
            $email = trim($_POST['email']     ?? '');
            $ind   = trim($_POST['industry']  ?? '');
            $city  = trim($_POST['city']      ?? '');
            $state = trim($_POST['state_prov']?? '');
            $addr  = trim($_POST['address']   ?? '');
            $notes = trim($_POST['notes']     ?? '');
            if (!$name) {
                $error_msg = 'Company name is required.';
            } else {
                try {
                    $pdo->prepare("INSERT INTO companies (name,website,phone,email,industry,city,state_prov,address,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$name,$web,$phone,$email,$ind,$city,$state,$addr,$notes]);
                    $success_msg = "Company \"$name\" added.";
                } catch (Exception $e) { $error_msg = $e->getMessage(); }
            }

        } elseif ($action === 'edit_company') {
            $id    = (int)($_POST['id']        ?? 0);
            $name  = trim($_POST['name']       ?? '');
            $web   = trim($_POST['website']    ?? '');
            $phone = trim($_POST['phone']      ?? '');
            $email = trim($_POST['email']      ?? '');
            $ind   = trim($_POST['industry']   ?? '');
            $city  = trim($_POST['city']       ?? '');
            $state = trim($_POST['state_prov'] ?? '');
            $addr  = trim($_POST['address']    ?? '');
            $notes = trim($_POST['notes']      ?? '');
            if (!$name || !$id) {
                $error_msg = 'Company name is required.';
            } else {
                try {
                    $pdo->prepare("UPDATE companies SET name=?,website=?,phone=?,email=?,industry=?,city=?,state_prov=?,address=?,notes=?,updated_at=NOW() WHERE id=?")
                        ->execute([$name,$web,$phone,$email,$ind,$city,$state,$addr,$notes,$id]);
                    $success_msg = "Company updated.";
                } catch (Exception $e) { $error_msg = $e->getMessage(); }
            }

        } elseif ($action === 'delete_company') {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $pdo->prepare("UPDATE leads SET company_id=NULL WHERE company_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$id]);
                $success_msg = 'Company deleted.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
        }
    }
}

// ── Fetch companies ────────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$where     = $search ? "WHERE LOWER(c.name) LIKE LOWER(?)" : "";
$bind      = $search ? ['%'.$search.'%'] : [];
$companies = [];
try {
    $st = $pdo->prepare("
        SELECT c.*, COUNT(l.id) AS lead_count
        FROM companies c
        LEFT JOIN leads l ON l.company_id=c.id
        $where
        GROUP BY c.id
        ORDER BY c.name
    ");
    $st->execute($bind);
    $companies = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $error_msg = $e->getMessage(); }

$total = count($companies);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Companies — Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<!-- Header -->
<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 flex items-center gap-2" data-testid="text-page-title">
                <span class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center"><i class="fas fa-building text-white text-sm"></i></span>
                Companies
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">Manage your CRM companies linked to leads</p>
        </div>
        <button onclick="openAddModal()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-company">
            <i class="fas fa-plus mr-2"></i>Add Company
        </button>
    </div>
</header>

<div class="p-6">
    <?php if ($success_msg): ?>
    <div class="mb-5 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
        <i class="fas fa-check-circle mr-3"></i><?= htmlspecialchars($success_msg) ?>
    </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="mb-5 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
        <i class="fas fa-exclamation-circle mr-3"></i><?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <!-- Stat + Search row -->
    <div class="flex flex-col sm:flex-row gap-4 mb-6">
        <div class="bg-white border border-gray-200 rounded-lg px-5 py-4 flex items-center gap-4">
            <span class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
                <i class="fas fa-building text-indigo-600"></i>
            </span>
            <div>
                <p class="text-2xl font-bold text-gray-900" data-testid="text-total"><?= $total ?></p>
                <p class="text-xs text-gray-400">Total companies</p>
            </div>
        </div>
        <form method="GET" class="flex-1 flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search companies..."
                   class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                   data-testid="input-search">
            <button type="submit" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm transition" data-testid="button-search">
                <i class="fas fa-search mr-1"></i>Search
            </button>
            <?php if ($search): ?>
            <a href="admin-companies.php" class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm hover:bg-gray-50 transition">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-lg border border-gray-200">
        <?php if (empty($companies)): ?>
        <div class="p-14 text-center text-gray-400">
            <i class="fas fa-building text-4xl mb-3"></i>
            <p class="font-medium text-base"><?= $search ? 'No companies match your search.' : 'No companies yet.' ?></p>
            <p class="text-sm mt-1"><?= $search ? '' : 'Click "Add Company" to create your first one.' ?></p>
            <?php if (!$search): ?>
            <button onclick="openAddModal()" class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                <i class="fas fa-plus mr-2"></i>Add First Company
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="table-companies">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Company</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Industry</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Contact</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Location</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Leads</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($companies as $co): ?>
                    <tr class="hover:bg-gray-50" data-testid="row-company-<?= $co['id'] ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold shrink-0">
                                    <?= strtoupper(substr($co['name'],0,2)) ?>
                                </span>
                                <div>
                                    <p class="font-semibold text-gray-900"><?= htmlspecialchars($co['name']) ?></p>
                                    <?php if ($co['website']): ?>
                                    <a href="<?= htmlspecialchars($co['website']) ?>" target="_blank" class="text-xs text-blue-500 hover:underline truncate max-w-[160px] block">
                                        <?= htmlspecialchars(preg_replace('#^https?://#','',$co['website'])) ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-sm">
                            <?= $co['industry'] ? htmlspecialchars($co['industry']) : '<span class="text-gray-300">—</span>' ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($co['phone']): ?><p class="text-gray-600 text-xs font-mono"><?= htmlspecialchars($co['phone']) ?></p><?php endif; ?>
                            <?php if ($co['email']): ?><p class="text-gray-500 text-xs"><?= htmlspecialchars($co['email']) ?></p><?php endif; ?>
                            <?php if (!$co['phone'] && !$co['email']): ?><span class="text-gray-300">—</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">
                            <?php
                            $loc = array_filter([
                                $co['city'] ?? '',
                                $co['state_prov'] ?? '',
                            ]);
                            echo $loc ? htmlspecialchars(implode(', ', $loc)) : '<span class="text-gray-300">—</span>';
                            ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($co['lead_count'] > 0): ?>
                            <a href="admin-leads-list.php?company_id=<?= $co['id'] ?>"
                               class="inline-block px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold hover:bg-blue-200">
                                <?= $co['lead_count'] ?>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-300 text-xs">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button onclick='openEditModal(<?= json_encode($co) ?>)'
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md transition mr-1"
                                    data-testid="button-edit-<?= $co['id'] ?>">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button onclick="confirmDelete(<?= $co['id'] ?>, '<?= htmlspecialchars(addslashes($co['name'])) ?>')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs bg-red-50 hover:bg-red-100 text-red-600 rounded-md transition"
                                    data-testid="button-delete-<?= $co['id'] ?>">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- ADD MODAL                                                       -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="add-modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeAddModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4 pointer-events-none">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg pointer-events-auto">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900"><i class="fas fa-building text-indigo-500 mr-2"></i>Add Company</h3>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_company">
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Company Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required placeholder="Acme Corp"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                               data-testid="input-add-name">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Website</label>
                        <input type="url" name="website" placeholder="https://example.com"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Industry</label>
                        <input type="text" name="industry" placeholder="Technology, Healthcare…"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone</label>
                        <input type="tel" name="phone" placeholder="(555) 555-0100"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email</label>
                        <input type="email" name="email" placeholder="info@company.com"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">City</label>
                        <input type="text" name="city" placeholder="Houston"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">State / Province</label>
                        <input type="text" name="state_prov" placeholder="TX"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Address</label>
                        <input type="text" name="address" placeholder="123 Main St, Suite 100"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any notes about this company…"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeAddModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-add">
                        <i class="fas fa-save mr-1"></i>Save Company
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- EDIT MODAL                                                      -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="edit-modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeEditModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4 pointer-events-none">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg pointer-events-auto">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900"><i class="fas fa-edit text-indigo-500 mr-2"></i>Edit Company</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" class="p-6 space-y-4" id="edit-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_company">
                <input type="hidden" name="id" id="edit-id">
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Company Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="edit-name" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                               data-testid="input-edit-name">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Website</label>
                        <input type="url" name="website" id="edit-website"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Industry</label>
                        <input type="text" name="industry" id="edit-industry"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone</label>
                        <input type="tel" name="phone" id="edit-phone"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email</label>
                        <input type="email" name="email" id="edit-email"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">City</label>
                        <input type="text" name="city" id="edit-city"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">State / Province</label>
                        <input type="text" name="state_prov" id="edit-state"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Address</label>
                        <input type="text" name="address" id="edit-address"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Notes</label>
                        <textarea name="notes" id="edit-notes" rows="2"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-edit">
                        <i class="fas fa-save mr-1"></i>Update Company
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE FORM (hidden) -->
<form id="delete-form" method="POST" class="hidden">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_company">
    <input type="hidden" name="id"     id="delete-id">
</form>

</div><!-- /flex-1 -->
</div><!-- /flex -->

<script>
function openAddModal()  { document.getElementById('add-modal').classList.remove('hidden'); }
function closeAddModal() { document.getElementById('add-modal').classList.add('hidden'); }
function closeEditModal(){ document.getElementById('edit-modal').classList.add('hidden'); }

function openEditModal(co) {
    document.getElementById('edit-id').value       = co.id       || '';
    document.getElementById('edit-name').value     = co.name     || '';
    document.getElementById('edit-website').value  = co.website  || '';
    document.getElementById('edit-industry').value = co.industry || '';
    document.getElementById('edit-phone').value    = co.phone    || '';
    document.getElementById('edit-email').value    = co.email    || '';
    document.getElementById('edit-city').value     = co.city     || '';
    document.getElementById('edit-state').value    = co.state_prov || '';
    document.getElementById('edit-address').value  = co.address  || '';
    document.getElementById('edit-notes').value    = co.notes    || '';
    document.getElementById('edit-modal').classList.remove('hidden');
}

function confirmDelete(id, name) {
    if (!confirm('Delete company "' + name + '"?\nLeads linked to this company will be unlinked.')) return;
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-form').submit();
}
</script>
</body>
</html>
