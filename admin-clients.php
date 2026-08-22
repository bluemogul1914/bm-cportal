<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$pdo_early  = getDB();

/* ── Add Client (modal form POST) ──────────────────────────────────────── */
$modal_error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_client') {
    require_csrf();
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name']  ?? '');
    $title_val     = trim($_POST['title']      ?? '');
    $job_title     = trim($_POST['job_title']  ?? '');
    $tags_raw      = trim($_POST['tags']       ?? '');
    $name          = trim("$first_name $last_name");
    if ($name === '') $name = trim($_POST['name'] ?? '');

    $phones_json  = $_POST['phones_json']  ?? '[]';
    $emails_json  = $_POST['emails_json']  ?? '[]';
    $socials_json = $_POST['socials_json'] ?? '[]';
    $phones_arr   = json_decode($phones_json,  true) ?: [];
    $emails_arr   = json_decode($emails_json,  true) ?: [];
    $socials_arr  = json_decode($socials_json, true) ?: [];

    $email = '';
    foreach ($emails_arr as $e) { if (!empty($e['value'])) { $email = $e['value']; break; } }
    if (empty($email)) $email = trim($_POST['email_fallback'] ?? '');
    $phone = '';
    foreach ($phones_arr as $p) { if (!empty($p['value'])) { $phone = $p['value']; break; } }
    $linkedin_url = '';
    foreach ($socials_arr as $s) {
        if (isset($s['type']) && strtolower($s['type']) === 'linkedin' && !empty($s['value'])) {
            $linkedin_url = $s['value']; break;
        }
    }
    $company        = trim($_POST['company'] ?? '');
    $crm_company_id = intval($_POST['crm_company_id'] ?? 0) ?: null;
    $address        = trim($_POST['address'] ?? '');
    $city           = trim($_POST['city'] ?? '');
    $state_val      = trim($_POST['state'] ?? '');
    $zip            = trim($_POST['zip'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');
    $credit_balance = floatval($_POST['credit_balance'] ?? 0);
    $parent_id      = intval($_POST['parent_client_id'] ?? 0) ?: null;

    $tags_arr = array_filter(array_map('trim', explode(',', $tags_raw)));
    $tags_pg  = empty($tags_arr) ? null : '{' . implode(',', array_map(fn($t) => '"' . str_replace('"', '\\"', $t) . '"', $tags_arr)) . '}';

    if (empty($name)) {
        $modal_error = 'First name is required.';
    } else {
        try {
            $stmt = $pdo_early->prepare("
                INSERT INTO clients
                  (name, first_name, last_name, title, job_title,
                   email, phone, company, crm_company_id,
                   address, city, state, zip,
                   notes, credit_balance, parent_id,
                   linkedin_url, phones, emails, social_links, tags,
                   created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?::jsonb,?::jsonb,?,NOW(),NOW())
                RETURNING id
            ");
            $stmt->execute([
                $name, $first_name, $last_name, $title_val, $job_title,
                $email, $phone, $company, $crm_company_id,
                $address, $city, $state_val, $zip,
                $notes, $credit_balance, $parent_id,
                $linkedin_url ?: null,
                $phones_json, $emails_json, $socials_json, $tags_pg,
            ]);
            $new_id = $stmt->fetchColumn();
            $pdo_early->prepare("INSERT INTO activity_log (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$user_id,'client_created','client',$new_id,"Created client: $name",$_SERVER['REMOTE_ADDR']??'0.0.0.0']);
            portal_redirect("/portal/admin-client-detail.php?id=$new_id");
        } catch (PDOException $e) {
            $modal_error = str_contains($e->getMessage(),'duplicate key')
                ? 'A client with this email already exists.'
                : 'Error: ' . $e->getMessage();
        }
    }
}

/* ── Companies & clients for modal pickers ─────────────────────────────── */
try { $all_companies = $pdo_early->query("SELECT id,name FROM crm_companies ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $all_companies=[]; }
try { $all_clients   = $pdo_early->query("SELECT id,name,company FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $all_clients=[]; }

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

try {
    $pdo = getDB();

    $where_clauses = [];
    $params = [];

    if ($search) {
        $where_clauses[] = "(name ILIKE ? OR email ILIKE ? OR company ILIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if ($status_filter) {
        $where_clauses[] = "status = ?";
        $params[] = $status_filter;
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM clients $where_sql");
    $count_stmt->execute($params);
    $total_clients = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $total_pages = ceil($total_clients / $limit);

    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $stmt = $pdo->prepare("SELECT * FROM clients $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($query_params);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE) THEN 1 ELSE 0 END) as new_this_month
        FROM clients
    ");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Clients page error: " . $e->getMessage());
    $clients = [];
    $total_clients = 0;
    $total_pages = 0;
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'new_this_month' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management - Blue Mogul Admin</title>

    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#1a56db', secondary: '#0d1b3e' },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">

    <div class="flex h-screen overflow-hidden">

        <?php include 'includes/admin-sidebar.php'; ?>

        <div class="flex-1 overflow-y-auto">

            <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900">Client Management</h1>
                            <p class="text-sm text-gray-600 mt-1">Manage all your clients and accounts</p>
                        </div>
                        <button onclick="document.getElementById('add-person-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-add-client">
                            <i class="fas fa-plus mr-2"></i>Add New Client
                        </button>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Total Clients</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Active</p>
                        <p class="text-3xl font-bold text-green-600"><?php echo $stats['active']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Inactive</p>
                        <p class="text-3xl font-bold text-gray-600"><?php echo $stats['inactive']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">New This Month</p>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $stats['new_this_month']; ?></p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4">
                        <div class="flex-1 min-w-[200px]">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search clients..." 
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium transition">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <?php if ($search || $status_filter): ?>
                            <a href="admin-clients.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md font-medium transition">
                                <i class="fas fa-times mr-2"></i>Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        <input type="checkbox" class="rounded border-gray-300" data-testid="checkbox-all">
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Client Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Primary Location</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Primary Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($clients)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                            <i class="fas fa-users text-4xl mb-3 text-gray-400"></i>
                                            <p class="font-medium">No clients found</p>
                                            <p class="text-sm mt-1">Add your first client to get started</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($clients as $client): ?>
                                        <?php
                                            $company_name = !empty($client['company']) ? $client['company'] : $client['name'];
                                            $company_initial = strtoupper(substr($company_name, 0, 1));
                                            $location_parts = [];
                                            if (!empty($client['address'])) $location_parts[] = $client['address'];
                                            $city_state = [];
                                            if (!empty($client['city'])) $city_state[] = $client['city'];
                                            if (!empty($client['state'])) $city_state[] = $client['state'];
                                            if (!empty($client['zip'])) $city_state[] = $client['zip'];
                                            if (!empty($city_state)) $location_parts[] = implode(' ', $city_state);
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="row-client-<?php echo $client['id']; ?>">
                                            <td class="px-6 py-4">
                                                <input type="checkbox" class="rounded border-gray-300" data-testid="checkbox-client-<?php echo $client['id']; ?>">
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-start">
                                                    <div class="bg-blue-100 rounded-lg h-10 w-10 flex items-center justify-center font-bold text-sm text-blue-600 mr-3 flex-shrink-0 mt-0.5">
                                                        <?php echo $company_initial; ?>
                                                    </div>
                                                    <div>
                                                        <a href="admin-client-detail.php?id=<?php echo $client['id']; ?>" class="font-semibold text-blue-600 hover:text-blue-800 hover:underline" data-testid="link-client-<?php echo $client['id']; ?>">
                                                            <?php echo htmlspecialchars($company_name); ?>
                                                        </a>
                                                        <?php if (!empty($client['company']) && $client['company'] !== $client['name']): ?>
                                                            <p class="text-xs text-gray-500 mt-0.5"><i class="fas fa-user text-gray-400 mr-1"></i><?php echo htmlspecialchars($client['name']); ?></p>
                                                        <?php endif; ?>
                                                        <?php if (!empty($client['contact_person'])): ?>
                                                            <p class="text-xs text-blue-500 mt-0.5"><i class="fas fa-id-badge text-blue-300 mr-1"></i><?php echo htmlspecialchars($client['contact_person']); ?></p>
                                                        <?php endif; ?>
                                                        <div class="flex items-center gap-2 mt-1">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium <?php echo ($client['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                                <?php echo ucfirst($client['status'] ?? 'active'); ?>
                                                            </span>
                                                            <?php if (!empty($client['client_code'])): ?>
                                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-semibold bg-blue-50 text-blue-700 border border-blue-200" data-testid="badge-client-code-<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['client_code']); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-[10px] text-gray-400">ID: #<?php echo $client['id']; ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-[10px] text-gray-400 mt-0.5">Created: <?php echo date('Y-m-d', strtotime($client['created_at'])); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if (!empty($location_parts)): ?>
                                                    <div class="text-sm text-gray-700">
                                                        <?php if (!empty($client['address'])): ?>
                                                            <p><i class="fas fa-map-marker-alt text-gray-400 mr-1"></i><?php echo htmlspecialchars($client['address']); ?></p>
                                                        <?php endif; ?>
                                                        <?php if (!empty($city_state)): ?>
                                                            <p class="text-gray-500"><?php echo htmlspecialchars(implode(', ', array_filter([$client['city'] ?? '', ($client['state'] ?? '') . ' ' . ($client['zip'] ?? '')]))); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm">
                                                    <p class="font-medium text-blue-600" data-testid="text-contact-<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></p>
                                                    <?php if (!empty($client['phone'])): ?>
                                                        <p class="text-gray-600 mt-0.5"><i class="fas fa-phone text-gray-400 text-xs mr-1"></i><?php echo htmlspecialchars($client['phone']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($client['email'])): ?>
                                                        <p class="text-gray-500 mt-0.5"><i class="fas fa-envelope text-gray-400 text-xs mr-1"></i><?php echo htmlspecialchars($client['email']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo ($client['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>" data-testid="badge-status-<?php echo $client['id']; ?>">
                                                    <span class="h-1.5 w-1.5 rounded-full <?php echo ($client['status'] ?? 'active') === 'active' ? 'bg-green-500' : 'bg-gray-500'; ?> mr-1.5"></span>
                                                    <?php echo ucfirst($client['status'] ?? 'Active'); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                                <a href="admin-client-detail.php?id=<?php echo $client['id']; ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md text-blue-600 hover:bg-blue-50 transition" title="View" data-testid="button-view-<?php echo $client['id']; ?>">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="admin-client-edit.php?id=<?php echo $client['id']; ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md text-gray-600 hover:bg-gray-100 transition" title="Edit" data-testid="button-edit-<?php echo $client['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                            <div class="text-sm text-gray-600">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_clients); ?> of <?php echo $total_clients; ?> clients
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                        Previous
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="px-3 py-2 <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-md text-sm">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                        Next
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
        function deleteClient(id) {
            if (confirm('Are you sure you want to delete this client? This action cannot be undone.')) {
                fetch('/api/clients.php?id=' + id, { method: 'DELETE' })
                .then(r => r.json())
                .then(d => { if (d.success) location.reload(); else alert('Error deleting client'); });
            }
        }
    </script>

<!-- ── New Person Modal ──────────────────────────────────────────────────── -->
<div id="add-person-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl w-full max-w-xl shadow-2xl flex flex-col max-h-[92vh]">
        <div class="flex items-center justify-between px-5 py-4 border-b flex-shrink-0">
            <h3 class="text-lg font-semibold text-gray-900">New Person</h3>
            <button onclick="document.getElementById('add-person-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600" data-testid="button-close-person-modal"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" id="personForm" class="overflow-y-auto">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_client">
            <input type="hidden" name="phones_json"  id="p_phones_json"  value="[]">
            <input type="hidden" name="emails_json"  id="p_emails_json"  value="[]">
            <input type="hidden" name="socials_json" id="p_socials_json" value="[]">
            <input type="hidden" name="email_fallback" id="p_email_fallback">

            <?php if ($modal_error): ?>
            <div class="mx-5 mt-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-2 rounded-md"><?= htmlspecialchars($modal_error) ?></div>
            <?php endif; ?>

            <div class="p-5 space-y-4">
                <!-- Name row -->
                <div class="flex gap-2 items-start">
                    <div style="width:80px;flex-shrink:0">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                        <select name="title" class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="select-person-title">
                            <option value=""></option>
                            <option>Mr</option><option>Mrs</option><option>Ms</option>
                            <option>Dr</option><option>Prof</option><option>Rev</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                        <input type="text" name="first_name" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-person-first-name" autofocus>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                        <input type="text" name="last_name" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-person-last-name">
                    </div>
                </div>

                <!-- Job Title & Organisation -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Job Title</label>
                        <input type="text" name="job_title" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-person-job-title">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Organisation</label>
                        <select name="crm_company_id" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="select-person-org">
                            <option value="">— Find an organisation —</option>
                            <?php foreach ($all_companies as $co): ?>
                            <option value="<?= $co['id'] ?>"><?= htmlspecialchars($co['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="company" class="mt-1 w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Or type company name" data-testid="input-person-company">
                    </div>
                </div>

                <!-- Tags -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tags</label>
                    <div class="border border-gray-300 rounded-md px-3 py-2 min-h-[38px] flex flex-wrap gap-1 items-center cursor-text" id="pTagContainer" onclick="document.getElementById('pTagInput').focus()">
                        <input id="pTagInput" class="outline-none text-sm flex-1 min-w-[80px] bg-transparent" placeholder="Type and press Enter…" data-testid="input-person-tags">
                    </div>
                    <input type="hidden" name="tags" id="pTagsHidden">
                </div>

                <!-- Contact Details -->
                <div class="border-t border-gray-100 pt-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Contact Details</div>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone Numbers</label>
                            <div id="p-phones-container"></div>
                            <span class="text-xs text-blue-600 cursor-pointer hover:underline" onclick="pAddRow('phones')" data-testid="button-person-add-phone">+ Add another phone number</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email Addresses</label>
                            <div id="p-emails-container"></div>
                            <span class="text-xs text-blue-600 cursor-pointer hover:underline" onclick="pAddRow('emails')" data-testid="button-person-add-email">+ Add another email address</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Websites &amp; Social Networks</label>
                            <div id="p-socials-container"></div>
                            <span class="text-xs text-blue-600 cursor-pointer hover:underline" onclick="pAddRow('socials')" data-testid="button-person-add-social">+ Add another website address</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <input type="text" name="address" placeholder="Street address" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none mb-2" data-testid="input-person-address">
                            <div class="grid grid-cols-3 gap-2">
                                <input type="text" name="city"  placeholder="City"  class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-person-city">
                                <input type="text" name="state" placeholder="State" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-person-state">
                                <input type="text" name="zip"   placeholder="ZIP"   class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="input-person-zip">
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" data-testid="textarea-person-notes"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-3 px-5 py-4 border-t bg-gray-50 rounded-b-xl flex-shrink-0">
                <button type="button" onclick="document.getElementById('add-person-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800" data-testid="button-cancel-person">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium" data-testid="button-submit-person">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── Person modal: dynamic row helpers ────────────────────────────────── */
const pRowConfig = {
    phones:  { types:['Mobile','Work','Home','Other'],   placeholder:'Phone number',   jsonId:'p_phones_json',  containerId:'p-phones-container' },
    emails:  { types:['Work','Personal','Other'],         placeholder:'Email address',  jsonId:'p_emails_json',  containerId:'p-emails-container' },
    socials: { types:['Website','LinkedIn','Twitter / X','Facebook','Instagram','Other'], placeholder:'URL', jsonId:'p_socials_json', containerId:'p-socials-container' },
};
const pState = { phones:[], emails:[], socials:[] };

function pSyncJson(type) {
    document.getElementById(pRowConfig[type].jsonId).value = JSON.stringify(pState[type]);
    if (type==='emails') {
        const first = pState.emails.find(e=>e.value);
        document.getElementById('p_email_fallback').value = first?.value||'';
    }
}

function pAddRow(type, val='', selType='') {
    const cfg = pRowConfig[type];
    const idx = pState[type].length;
    const entry = { value: val, type: selType||cfg.types[0] };
    pState[type].push(entry);
    const wrap = document.createElement('div');
    wrap.className = 'flex gap-2 items-center mb-2';
    wrap.innerHTML = `
        <input type="text" value="${val}" placeholder="${cfg.placeholder}"
            class="flex-1 border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
            oninput="pState['${type}'][${idx}].value=this.value;pSyncJson('${type}')">
        <select class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" style="width:115px"
            onchange="pState['${type}'][${idx}].type=this.value;pSyncJson('${type}')">
            ${cfg.types.map(t=>`<option${t===entry.type?' selected':''}>${t}</option>`).join('')}
        </select>
        <button type="button" class="text-gray-300 hover:text-red-500 text-lg leading-none"
            onclick="this.parentElement.remove();pState['${type}'].splice(${idx},1);pSyncJson('${type}')">×</button>`;
    document.getElementById(cfg.containerId).appendChild(wrap);
    wrap.querySelector('input').focus();
    pSyncJson(type);
}

/* Tags */
const pTags = [];
document.getElementById('pTagInput').addEventListener('keydown', e => {
    if (e.key==='Enter'||e.key===',') {
        e.preventDefault();
        const v = e.target.value.trim().replace(/,$/,'');
        if (!v||pTags.includes(v)) return;
        pTags.push(v);
        const chip = document.createElement('span');
        chip.className = 'inline-flex items-center gap-1 bg-blue-50 text-blue-700 rounded-full px-2.5 py-0.5 text-xs';
        chip.innerHTML = `${v} <button type="button" onclick="pTags.splice(pTags.indexOf('${v.replace(/'/g,"\\'")}'),1);this.parentElement.remove();document.getElementById('pTagsHidden').value=pTags.join(',')" class="text-blue-400 hover:text-blue-700 text-xs">✕</button>`;
        document.getElementById('pTagContainer').insertBefore(chip, e.target);
        e.target.value='';
        document.getElementById('pTagsHidden').value = pTags.join(',');
    }
});

/* Auto-open on error */
<?php if ($modal_error): ?>
document.getElementById('add-person-modal').classList.remove('hidden');
<?php endif; ?>
</script>

</body>
</html>