<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin   = true;

$success_msg   = '';
$error_msg     = '';
$new_client_id = 0;
$pdo = getDB();

/* ── Load crm_companies for Organisation picker ────────────────────────── */
try {
    $all_companies = $pdo->query("SELECT id, name FROM crm_companies ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $all_companies = []; }

try {
    $all_clients = $pdo->query("SELECT id, name, company FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $all_clients = []; }

/* ── POST handler ──────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_client') {
    require_csrf();

    $first_name     = trim($_POST['first_name'] ?? '');
    $last_name      = trim($_POST['last_name']  ?? '');
    $title          = trim($_POST['title']       ?? '');
    $job_title      = trim($_POST['job_title']   ?? '');
    $tags_raw       = trim($_POST['tags']        ?? '');

    $name  = trim("$first_name $last_name");
    if ($name === '') $name = trim($_POST['name'] ?? '');

    /* phones / emails / social links (JSON arrays from hidden inputs) */
    $phones_json  = $_POST['phones_json']  ?? '[]';
    $emails_json  = $_POST['emails_json']  ?? '[]';
    $socials_json = $_POST['socials_json'] ?? '[]';

    $phones_arr  = json_decode($phones_json,  true) ?: [];
    $emails_arr  = json_decode($emails_json,  true) ?: [];
    $socials_arr = json_decode($socials_json, true) ?: [];

    /* Primary email/phone for backward-compat columns */
    $email = '';
    foreach ($emails_arr as $e) { if (!empty($e['value'])) { $email = $e['value']; break; } }
    if (empty($email)) $email = trim($_POST['email_fallback'] ?? '');

    $phone = '';
    foreach ($phones_arr as $p) { if (!empty($p['value'])) { $phone = $p['value']; break; } }

    /* LinkedIn from social links */
    $linkedin_url = '';
    foreach ($socials_arr as $s) {
        if (isset($s['type']) && strtolower($s['type']) === 'linkedin' && !empty($s['value'])) {
            $linkedin_url = $s['value']; break;
        }
    }

    $company         = trim($_POST['company']    ?? '');
    $crm_company_id  = intval($_POST['crm_company_id'] ?? 0) ?: null;
    $address         = trim($_POST['address']    ?? '');
    $city            = trim($_POST['city']       ?? '');
    $state           = trim($_POST['state']      ?? '');
    $zip             = trim($_POST['zip']        ?? '');
    $notes           = trim($_POST['notes']      ?? '');
    $credit_balance  = floatval($_POST['credit_balance'] ?? 0);
    $parent_id       = intval($_POST['parent_client_id'] ?? 0) ?: null;

    /* tags → postgres array */
    $tags_arr = array_filter(array_map('trim', explode(',', $tags_raw)));
    $tags_pg  = '{' . implode(',', array_map(fn($t) => '"' . str_replace('"', '\\"', $t) . '"', $tags_arr)) . '}';
    if (empty($tags_arr)) $tags_pg = null;

    if (empty($name) || empty($email)) {
        $error_msg = 'First name/last name and at least one email address are required.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO clients
                  (name, first_name, last_name, title, job_title,
                   email, phone, company, crm_company_id,
                   address, city, state, zip,
                   notes, credit_balance, parent_id,
                   linkedin_url, phones, emails, social_links, tags,
                   created_at, updated_at)
                VALUES
                  (?,?,?,?,?,
                   ?,?,?,?,
                   ?,?,?,?,
                   ?,?,?,
                   ?,?::jsonb,?::jsonb,?::jsonb,?,
                   NOW(),NOW())
                RETURNING id
            ");
            $stmt->execute([
                $name, $first_name, $last_name, $title, $job_title,
                $email, $phone, $company, $crm_company_id,
                $address, $city, $state, $zip,
                $notes, $credit_balance, $parent_id,
                $linkedin_url ?: null,
                $phones_json, $emails_json, $socials_json,
                $tags_pg,
            ]);
            $new_client_id = $stmt->fetchColumn();

            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$user_id, 'client_created', 'client', $new_client_id, "Created client: $name", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

            $success_msg = 'Client created successfully!';
        } catch (PDOException $e) {
            error_log("Client create error: " . $e->getMessage());
            if (strpos($e->getMessage(), 'duplicate key') !== false) {
                $error_msg = 'A client with this email address already exists.';
            } else {
                $error_msg = 'Failed to create client: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client — Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>
        .capsule-label { @apply block text-sm font-medium text-gray-700 mb-1; }
        .capsule-input { @apply w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none; }
        .capsule-section { @apply bg-white rounded-lg border border-gray-200 mb-5; }
        .capsule-section-header { @apply px-5 py-3 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider; }
        .capsule-section-body { @apply p-5 space-y-4; }
        .row-entry { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
        .row-entry input { flex:1; }
        .row-entry select { width:130px; flex-shrink:0; }
        .btn-remove { color:#9ca3af; cursor:pointer; flex-shrink:0; }
        .btn-remove:hover { color:#ef4444; }
        .btn-add-row { font-size:0.8rem; color:#1a56db; cursor:pointer; display:inline-flex; align-items:center; gap:4px; margin-top:2px; }
        .btn-add-row:hover { text-decoration:underline; }
        .tag-chip { display:inline-flex; align-items:center; gap:4px; background:#eff6ff; color:#1d4ed8; border-radius:9999px; padding:2px 10px; font-size:0.78rem; margin:2px; }
        .tag-chip button { color:#93c5fd; font-size:10px; }
        .tag-chip button:hover { color:#1d4ed8; }
    </style>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center gap-3">
                <a href="admin-clients.php" class="text-gray-400 hover:text-gray-600 transition" data-testid="link-back-clients"><i class="fas fa-arrow-left"></i></a>
                <h1 class="text-xl font-semibold text-gray-900">New Person</h1>
            </div>
        </header>

        <div class="p-6 max-w-2xl">
            <?php if ($success_msg): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-4 flex items-center" data-testid="alert-success">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success_msg) ?>
                <?php if ($new_client_id): ?>
                <a href="admin-client-detail.php?id=<?= $new_client_id ?>" class="ml-auto underline text-sm" data-testid="link-view-client">View Client →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-4 flex items-center" data-testid="alert-error">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error_msg) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="admin-client-add.php" id="newPersonForm" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_client">
                <input type="hidden" name="phones_json"  id="phones_json"  value="[]">
                <input type="hidden" name="emails_json"  id="emails_json"  value="[]">
                <input type="hidden" name="socials_json" id="socials_json" value="[]">

                <!-- Name row -->
                <div class="capsule-section">
                    <div class="capsule-section-body">
                        <div class="flex gap-3 items-start">
                            <div style="width:90px;flex-shrink:0">
                                <label class="capsule-label">Title</label>
                                <select name="title" class="capsule-input" data-testid="select-title">
                                    <option value=""></option>
                                    <option>Mr</option><option>Mrs</option><option>Ms</option>
                                    <option>Dr</option><option>Prof</option><option>Rev</option>
                                </select>
                            </div>
                            <div class="flex-1">
                                <label class="capsule-label">First Name *</label>
                                <input type="text" name="first_name" required class="capsule-input" data-testid="input-first-name" autocomplete="given-name">
                            </div>
                            <div class="flex-1">
                                <label class="capsule-label">Last Name</label>
                                <input type="text" name="last_name" class="capsule-input" data-testid="input-last-name" autocomplete="family-name">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="capsule-label">Job Title</label>
                                <input type="text" name="job_title" class="capsule-input" data-testid="input-job-title">
                            </div>
                            <div>
                                <label class="capsule-label">Organisation</label>
                                <select name="crm_company_id" class="capsule-input" data-testid="select-organisation">
                                    <option value="">— Find an organisation —</option>
                                    <?php foreach ($all_companies as $co): ?>
                                    <option value="<?= $co['id'] ?>"><?= htmlspecialchars($co['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="company" class="capsule-input mt-1" placeholder="Or type company name" data-testid="input-company">
                            </div>
                        </div>

                        <!-- Tags -->
                        <div>
                            <label class="capsule-label">Tags</label>
                            <div class="border border-gray-300 rounded-md px-3 py-2 min-h-[38px] flex flex-wrap gap-1 items-center cursor-text" id="tagContainer" onclick="document.getElementById('tagInput').focus()">
                                <input id="tagInput" class="outline-none text-sm flex-1 min-w-[80px] bg-transparent" placeholder="Type and press Enter…" data-testid="input-tags">
                            </div>
                            <input type="hidden" name="tags" id="tagsHidden">
                        </div>

                        <!-- Parent Account -->
                        <div>
                            <label class="capsule-label">Parent Account</label>
                            <select name="parent_client_id" class="capsule-input" data-testid="select-parent-account">
                                <option value="0">— None —</option>
                                <?php foreach ($all_clients as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?><?= $c['company'] ? ' (' . htmlspecialchars($c['company']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Contact Details -->
                <div class="capsule-section">
                    <div class="capsule-section-header">Contact Details</div>
                    <div class="capsule-section-body">

                        <!-- Phone Numbers -->
                        <div>
                            <label class="capsule-label">Phone Numbers</label>
                            <div id="phones-container"></div>
                            <span class="btn-add-row" onclick="addRow('phones')" data-testid="button-add-phone">
                                <i class="fas fa-plus text-xs"></i> Add another phone number
                            </span>
                        </div>

                        <!-- Email Addresses -->
                        <div>
                            <label class="capsule-label">Email Addresses</label>
                            <div id="emails-container"></div>
                            <span class="btn-add-row" onclick="addRow('emails')" data-testid="button-add-email">
                                <i class="fas fa-plus text-xs"></i> Add another email address
                            </span>
                            <input type="hidden" name="email_fallback" id="email_fallback">
                        </div>

                        <!-- Websites & Social Networks -->
                        <div>
                            <label class="capsule-label">Websites &amp; Social Networks</label>
                            <div id="socials-container"></div>
                            <span class="btn-add-row" onclick="addRow('socials')" data-testid="button-add-social">
                                <i class="fas fa-plus text-xs"></i> Add another website address
                            </span>
                        </div>

                        <!-- Address -->
                        <div>
                            <label class="capsule-label">Address</label>
                            <input type="text" name="address" placeholder="Street address" class="capsule-input mb-2" data-testid="input-address">
                            <div class="grid grid-cols-3 gap-2">
                                <input type="text" name="city"  placeholder="City"     class="capsule-input" data-testid="input-city">
                                <input type="text" name="state" placeholder="State"    class="capsule-input" data-testid="input-state">
                                <input type="text" name="zip"   placeholder="ZIP"      class="capsule-input" data-testid="input-zip">
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Billing & Notes -->
                <div class="capsule-section">
                    <div class="capsule-section-header">Billing &amp; Notes</div>
                    <div class="capsule-section-body">
                        <div>
                            <label class="capsule-label">Credit Balance ($)</label>
                            <input type="number" name="credit_balance" step="0.01" value="0.00" class="capsule-input" data-testid="input-credit-balance">
                        </div>
                        <div>
                            <label class="capsule-label">Notes</label>
                            <textarea name="notes" rows="3" placeholder="Internal notes…" class="capsule-input" data-testid="input-notes"></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pb-8">
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-save-client">Save</button>
                    <a href="admin-clients.php" class="px-5 py-2 text-gray-600 hover:text-gray-800 text-sm font-medium" data-testid="button-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ── Dynamic row config ─────────────────────────────────────────── */
const rowConfig = {
    phones:  { types: ['Mobile','Work','Home','Other'],   placeholder: 'Phone number' },
    emails:  { types: ['Work','Personal','Other'],         placeholder: 'Email address' },
    socials: { types: ['Website','LinkedIn','Twitter / X','Facebook','Instagram','GitHub','YouTube','Other'], placeholder: 'URL' },
};

const state = { phones: [], emails: [], socials: [] };

function buildTypeSelect(kind, idx) {
    const s = document.createElement('select');
    s.className = 'border border-gray-300 rounded-md px-2 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none bg-white';
    s.style.width = '140px';
    rowConfig[kind].types.forEach(t => {
        const o = document.createElement('option');
        o.value = t; o.textContent = t; s.appendChild(o);
    });
    s.addEventListener('change', () => { state[kind][idx].type = s.value; syncJSON(); });
    return s;
}

function addRow(kind, value='', type='') {
    const idx = state[kind].length;
    state[kind].push({ value, type: type || rowConfig[kind].types[0] });

    const container = document.getElementById(kind + '-container');
    const row = document.createElement('div');
    row.className = 'row-entry';
    row.dataset.idx = idx;

    const inp = document.createElement('input');
    inp.type = kind === 'emails' ? 'email' : (kind === 'socials' ? 'url' : 'text');
    inp.placeholder = rowConfig[kind].placeholder;
    inp.value = value;
    inp.className = 'border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none flex-1';
    inp.dataset.testid = `input-${kind}-${idx}`;
    inp.addEventListener('input', () => {
        state[kind][idx].value = inp.value;
        if (kind === 'emails' && idx === 0) document.getElementById('email_fallback').value = inp.value;
        syncJSON();
    });

    const sel = buildTypeSelect(kind, idx);
    if (type) sel.value = type;
    state[kind][idx].type = sel.value;

    const rm = document.createElement('span');
    rm.className = 'btn-remove';
    rm.innerHTML = '<i class="fas fa-times"></i>';
    rm.title = 'Remove';
    rm.addEventListener('click', () => {
        state[kind].splice(idx, 1);
        row.remove();
        /* re-index would be complex — just splice and re-render */
        syncJSON();
    });

    row.appendChild(inp);
    row.appendChild(sel);
    row.appendChild(rm);
    container.appendChild(row);
    syncJSON();
}

function syncJSON() {
    document.getElementById('phones_json').value  = JSON.stringify(state.phones.filter(r=>r.value));
    document.getElementById('emails_json').value  = JSON.stringify(state.emails.filter(r=>r.value));
    document.getElementById('socials_json').value = JSON.stringify(state.socials.filter(r=>r.value));
}

/* seed one row of each */
addRow('phones'); addRow('emails'); addRow('socials');

/* ── Tags ─────────────────────────────────────────────────────────── */
const tags = [];
const tagInput = document.getElementById('tagInput');
const tagContainer = document.getElementById('tagContainer');
const tagsHidden = document.getElementById('tagsHidden');

function addTag(val) {
    val = val.trim();
    if (!val || tags.includes(val)) return;
    tags.push(val);
    const chip = document.createElement('span');
    chip.className = 'tag-chip';
    chip.innerHTML = `${val} <button type="button" onclick="removeTag('${val.replace(/'/g,"\\'")}',this.parentElement)" title="Remove">✕</button>`;
    tagContainer.insertBefore(chip, tagInput);
    tagsHidden.value = tags.join(',');
}

function removeTag(val, chip) {
    const i = tags.indexOf(val);
    if (i !== -1) tags.splice(i, 1);
    chip.remove();
    tagsHidden.value = tags.join(',');
}

tagInput.addEventListener('keydown', e => {
    if (['Enter','Tab',','].includes(e.key)) {
        e.preventDefault();
        addTag(tagInput.value);
        tagInput.value = '';
    }
});
tagInput.addEventListener('blur', () => { if (tagInput.value) { addTag(tagInput.value); tagInput.value = ''; } });

/* ── Form validation ──────────────────────────────────────────────── */
document.getElementById('newPersonForm').addEventListener('submit', function(e) {
    syncJSON();
    const hasEmail = state.emails.some(r => r.value.trim());
    const firstName = document.querySelector('[name="first_name"]').value.trim();
    if (!firstName) { e.preventDefault(); alert('First name is required.'); return; }
    if (!hasEmail) {
        const fb = document.getElementById('email_fallback').value.trim();
        if (!fb) { e.preventDefault(); alert('At least one email address is required.'); return; }
    }
});
</script>
</body>
</html>
