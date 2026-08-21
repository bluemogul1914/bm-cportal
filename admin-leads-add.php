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
        $company_id      = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
        // Resolve company name from ID
        $company_name    = '';
        if ($company_id) {
            try {
                $cn = $pdo->prepare("SELECT name FROM companies WHERE id=?");
                $cn->execute([$company_id]);
                $company_name = $cn->fetchColumn() ?: '';
            } catch (Exception $e) {}
        }
        $linkedin_url = trim($_POST['linkedin_url'] ?? '');

        if (!$full_name) {
            $error_msg = 'Full name is required.';
        } else {
            try {
                $st = $pdo->prepare("INSERT INTO leads (full_name,pipeline_status,owner,phone,email,source,partner,location,city,street,zip_code,geo_data,custom_status,company_id,company_name,linkedin_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id");
                $st->execute([$full_name,$pipeline_status,$owner,$phone,$email,$source,$partner,$location,$city,$street,$zip_code,$geo_data,$custom_status,$company_id,$company_name,$linkedin_url ?: null]);
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
// Companies for dropdown
$companies = [];
try { $companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
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
    <link rel="stylesheet" href="/assets/css/style.css">
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

        <!-- Company -->
        <div class="grid grid-cols-3 items-start gap-4">
            <label class="text-sm font-medium text-gray-600 text-right pt-2">Company</label>
            <div class="col-span-2">
                <div class="flex gap-2">
                    <select name="company_id" id="company-select"
                            class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            data-testid="select-company">
                        <option value="">— None —</option>
                        <?php foreach ($companies as $co): ?>
                        <option value="<?= $co['id'] ?>"><?= htmlspecialchars($co['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="openQuickAddCompany()"
                            class="px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg text-sm font-medium transition whitespace-nowrap"
                            title="Add new company" data-testid="button-quick-add-company">
                        <i class="fas fa-plus mr-1"></i>New
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Optional — link this lead to a company</p>
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

        <div class="grid grid-cols-3 items-center gap-4">
            <label class="text-sm font-medium text-gray-600 text-right">
                <svg class="inline-block w-4 h-4 mr-0.5" style="fill:#0A66C2;vertical-align:-2px" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                LinkedIn URL
            </label>
            <div class="col-span-2">
                <input type="url" name="linkedin_url" placeholder="https://www.linkedin.com/in/username"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-linkedin-url">
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

<!-- ── Quick-Add Company Modal ─────────────────────────────────────────────── -->
<div id="quick-company-modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeQuickAddCompany()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4 pointer-events-none">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md pointer-events-auto">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-building text-indigo-500 mr-2"></i>Quick-Add Company</h3>
                <button type="button" onclick="closeQuickAddCompany()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-5 space-y-3" id="qac-body">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Company Name <span class="text-red-500">*</span></label>
                    <input type="text" id="qac-name" placeholder="Acme Corp"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           data-testid="input-qac-name">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone</label>
                        <input type="tel" id="qac-phone" placeholder="(555) 555-0100"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email</label>
                        <input type="email" id="qac-email" placeholder="info@company.com"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Industry</label>
                    <input type="text" id="qac-industry" placeholder="Technology, Healthcare…"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <p id="qac-error" class="hidden text-sm text-red-600"></p>
            </div>
            <div class="px-5 pb-5 flex justify-end gap-3">
                <button type="button" onclick="closeQuickAddCompany()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">Cancel</button>
                <button type="button" onclick="saveQuickCompany()" id="qac-save"
                        class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition"
                        data-testid="button-qac-save">
                    <i class="fas fa-save mr-1"></i>Save &amp; Select
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openQuickAddCompany()  { document.getElementById('quick-company-modal').classList.remove('hidden'); document.getElementById('qac-name').focus(); }
function closeQuickAddCompany() { document.getElementById('quick-company-modal').classList.add('hidden'); }

async function saveQuickCompany() {
    const name     = document.getElementById('qac-name').value.trim();
    const phone    = document.getElementById('qac-phone').value.trim();
    const email    = document.getElementById('qac-email').value.trim();
    const industry = document.getElementById('qac-industry').value.trim();
    const errEl    = document.getElementById('qac-error');
    const saveBtn  = document.getElementById('qac-save');

    if (!name) { errEl.textContent = 'Company name is required.'; errEl.classList.remove('hidden'); return; }
    errEl.classList.add('hidden');

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Saving…';

    // Use a hidden form to POST via AJAX (avoids CSRF issues by posting the existing token)
    const csrf = document.querySelector('input[name="_csrf_token"]')?.value || '';
    const body = new URLSearchParams({ action:'add_company', name, phone, email, industry, _csrf_token: csrf });

    try {
        const res  = await fetch('admin-companies.php', { method:'POST', body, headers:{'Content-Type':'application/x-www-form-urlencoded'} });
        // Re-fetch company list from API to get the new ID
        const listRes  = await fetch('admin-companies.php?ajax_companies=1');
        const listJson = await listRes.json().catch(() => null);

        if (listJson && listJson.companies) {
            const sel = document.getElementById('company-select');
            // Rebuild options
            while (sel.options.length > 1) sel.remove(1);
            listJson.companies.forEach(co => {
                const opt = new Option(co.name, co.id);
                sel.add(opt);
            });
            // Select the latest (highest ID among newly added)
            const newest = listJson.companies.reduce((a,b) => +a.id > +b.id ? a : b, {id:0});
            sel.value = newest.id;
        }
        closeQuickAddCompany();
        document.getElementById('qac-name').value = document.getElementById('qac-phone').value = document.getElementById('qac-email').value = document.getElementById('qac-industry').value = '';
    } catch(e) {
        errEl.textContent = 'Save failed — please try again.';
        errEl.classList.remove('hidden');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i>Save &amp; Select';
    }
}
</script>
</body>
</html>
