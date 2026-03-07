<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$is_admin = true;
$pdo = getDB();

$success_msg = '';
$error_msg = '';
$tab = $_GET['tab'] ?? 'leads';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_lead') {
        $name = trim($_POST['lead_name'] ?? '');
        $email = trim($_POST['lead_email'] ?? '');
        $phone = trim($_POST['lead_phone'] ?? '');
        $company = trim($_POST['lead_company'] ?? '');
        $source = $_POST['lead_source'] ?? 'manual';
        $notes = trim($_POST['lead_notes'] ?? '');
        if ($name) {
            $pdo->prepare("INSERT INTO crm_leads (name, email, phone, company, source, notes) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$name, $email, $phone, $company, $source, $notes]);
            $success_msg = "Lead '$name' added successfully.";
        } else {
            $error_msg = 'Lead name is required.';
        }
        $tab = 'leads';
    }

    if ($action === 'update_lead_status') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $status = $_POST['status'] ?? 'new';
        if ($lead_id) {
            $pdo->prepare("UPDATE crm_leads SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $lead_id]);
            $success_msg = 'Lead status updated.';
        }
        $tab = 'leads';
    }

    if ($action === 'delete_lead') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        if ($lead_id) {
            $pdo->prepare("DELETE FROM crm_leads WHERE id = ?")->execute([$lead_id]);
            $success_msg = 'Lead deleted.';
        }
        $tab = 'leads';
    }

    if ($action === 'convert_lead') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        if ($lead_id) {
            $lead = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ?");
            $lead->execute([$lead_id]);
            $lead = $lead->fetch(PDO::FETCH_ASSOC);
            if ($lead) {
                $temp_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (email, password, name, is_admin, role, status) VALUES (?, ?, ?, false, 'client', 'active') ON CONFLICT (email) DO NOTHING")
                    ->execute([$lead['email'] ?: $lead['name'] . '@pending.local', $temp_pass, $lead['name']]);
                $user = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $user->execute([$lead['email'] ?: $lead['name'] . '@pending.local']);
                $user = $user->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $pdo->prepare("INSERT INTO clients (user_id, name, email, phone, company, status) VALUES (?, ?, ?, ?, ?, 'active') ON CONFLICT DO NOTHING")
                        ->execute([$user['id'], $lead['name'], $lead['email'], $lead['phone'], $lead['company']]);
                }
                $pdo->prepare("UPDATE crm_leads SET status = 'won', updated_at = NOW() WHERE id = ?")->execute([$lead_id]);
                $success_msg = "Lead '{$lead['name']}' converted to client.";
            }
        }
        $tab = 'leads';
    }

    if ($action === 'add_campaign') {
        $name = trim($_POST['campaign_name'] ?? '');
        $type = $_POST['campaign_type'] ?? 'email';
        $subject = trim($_POST['campaign_subject'] ?? '');
        $content = trim($_POST['campaign_content'] ?? '');
        $target = $_POST['campaign_target'] ?? 'all_clients';
        $start = $_POST['campaign_start'] ?? null;
        $end = $_POST['campaign_end'] ?? null;
        if ($name) {
            $pdo->prepare("INSERT INTO crm_campaigns (name, type, subject, content, target_audience, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$name, $type, $subject, $content, $target, $start ?: null, $end ?: null, $_SESSION['user_id']]);
            $success_msg = "Campaign '$name' created.";
        } else {
            $error_msg = 'Campaign name is required.';
        }
        $tab = 'campaigns';
    }

    if ($action === 'update_campaign_status') {
        $campaign_id = (int)($_POST['campaign_id'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        if ($campaign_id) {
            $pdo->prepare("UPDATE crm_campaigns SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $campaign_id]);
            $success_msg = 'Campaign status updated.';
        }
        $tab = 'campaigns';
    }

    if ($action === 'delete_campaign') {
        $campaign_id = (int)($_POST['campaign_id'] ?? 0);
        if ($campaign_id) {
            $pdo->prepare("DELETE FROM crm_campaigns WHERE id = ?")->execute([$campaign_id]);
            $success_msg = 'Campaign deleted.';
        }
        $tab = 'campaigns';
    }

    if ($action === 'add_meeting') {
        $title = trim($_POST['meeting_title'] ?? '');
        $client_id = (int)($_POST['meeting_client_id'] ?? 0);
        $client_name = trim($_POST['meeting_client_name'] ?? '');
        $type = $_POST['meeting_type'] ?? 'consultation';
        $date = $_POST['meeting_date'] ?? '';
        $time = $_POST['meeting_time'] ?? '09:00';
        $duration = (int)($_POST['meeting_duration'] ?? 60);
        $location = trim($_POST['meeting_location'] ?? '');
        $notes = trim($_POST['meeting_notes'] ?? '');
        if ($title && $date) {
            $scheduled = $date . ' ' . $time . ':00';
            if ($client_id && !$client_name) {
                $c = $pdo->prepare("SELECT name, company FROM clients WHERE id = ?");
                $c->execute([$client_id]);
                $c = $c->fetch(PDO::FETCH_ASSOC);
                $client_name = $c ? ($c['company'] ?: $c['name']) : '';
            }
            $pdo->prepare("INSERT INTO crm_meetings (title, client_id, client_name, meeting_type, scheduled_at, duration_minutes, location, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$title, $client_id ?: null, $client_name, $type, $scheduled, $duration, $location, $notes, $_SESSION['user_id']]);
            $success_msg = "Meeting '$title' scheduled.";
        } else {
            $error_msg = 'Meeting title and date are required.';
        }
        $tab = 'meetings';
    }

    if ($action === 'update_meeting_status') {
        $meeting_id = (int)($_POST['meeting_id'] ?? 0);
        $status = $_POST['status'] ?? 'scheduled';
        if ($meeting_id) {
            $pdo->prepare("UPDATE crm_meetings SET status = ? WHERE id = ?")->execute([$status, $meeting_id]);
            $success_msg = 'Meeting status updated.';
        }
        $tab = 'meetings';
    }

    if ($action === 'delete_meeting') {
        $meeting_id = (int)($_POST['meeting_id'] ?? 0);
        if ($meeting_id) {
            $pdo->prepare("DELETE FROM crm_meetings WHERE id = ?")->execute([$meeting_id]);
            $success_msg = 'Meeting deleted.';
        }
        $tab = 'meetings';
    }
}

$leads = $pdo->query("SELECT * FROM crm_leads ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$campaigns = $pdo->query("SELECT * FROM crm_campaigns ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$meetings = $pdo->query("SELECT * FROM crm_meetings ORDER BY scheduled_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$clients_list = [];
try { $clients_list = $pdo->query("SELECT id, name, company FROM clients WHERE status = 'active' ORDER BY company, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$recent_tickets = [];
try { $recent_tickets = $pdo->query("SELECT t.id, t.subject, t.status, t.priority, t.created_at, c.name as client_name, c.company FROM tickets t LEFT JOIN clients c ON t.client_id = c.id ORDER BY t.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$recent_messages = [];
try { $recent_messages = $pdo->query("SELECT cm.*, cc.name as channel_name FROM chat_messages cm LEFT JOIN chat_channels cc ON cm.room = cc.slug ORDER BY cm.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$lead_stats = ['new' => 0, 'contacted' => 0, 'qualified' => 0, 'lost' => 0, 'won' => 0];
foreach ($leads as $l) { $lead_stats[$l['status']] = ($lead_stats[$l['status']] ?? 0) + 1; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-bullhorn text-blue-500 mr-2"></i>CRM</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Leads, campaigns, meetings, and inbox</p>
                </div>
            </div>
            <div class="px-6 flex gap-1 border-t border-gray-100">
                <?php foreach (['leads' => 'Leads', 'campaigns' => 'Campaigns', 'meetings' => 'Meetings', 'inbox' => 'Inbox'] as $k => $v): ?>
                    <a href="?tab=<?= $k ?>" class="px-4 py-3 text-sm font-medium border-b-2 transition <?= $tab === $k ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>" data-testid="tab-<?= $k ?>"><?= $v ?></a>
                <?php endforeach; ?>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-3"></i><?= htmlspecialchars($success_msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-3"></i><?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'leads'): ?>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <?php
                $stat_colors = ['new' => 'blue', 'contacted' => 'yellow', 'qualified' => 'purple', 'lost' => 'red', 'won' => 'green'];
                foreach ($lead_stats as $status => $count):
                    $color = $stat_colors[$status] ?? 'gray';
                ?>
                <div class="bg-white rounded-lg border border-gray-200 p-4 text-center" data-testid="stat-<?= $status ?>">
                    <p class="text-xs font-semibold text-gray-500 uppercase"><?= ucfirst($status) ?></p>
                    <p class="text-2xl font-bold text-<?= $color ?>-600"><?= $count ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">All Leads</h2>
                <button onclick="document.getElementById('add-lead-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-lead">
                    <i class="fas fa-plus mr-1"></i>Add Lead
                </button>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <?php if (empty($leads)): ?>
                    <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-user-plus text-gray-300 text-2xl mb-2 block"></i>No leads yet. Click "Add Lead" to get started.</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-leads">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Company</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Source</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Created</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($leads as $lead):
                                $sc = ['new'=>'blue','contacted'=>'yellow','qualified'=>'purple','lost'=>'red','won'=>'green'][$lead['status']] ?? 'gray';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="lead-row-<?= $lead['id'] ?>">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($lead['name']) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($lead['email'] ?? '') ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($lead['company'] ?? '') ?></td>
                                <td class="px-4 py-3"><span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded"><?= htmlspecialchars($lead['source']) ?></span></td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_lead_status">
                                        <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1 bg-<?= $sc ?>-50 text-<?= $sc ?>-700 border-<?= $sc ?>-200" data-testid="select-lead-status-<?= $lead['id'] ?>">
                                            <?php foreach (['new','contacted','qualified','lost','won'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500"><?= date('M d, Y', strtotime($lead['created_at'])) ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <?php if ($lead['status'] !== 'won'): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Convert this lead to a client?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="convert_lead">
                                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                            <button type="submit" class="text-green-600 hover:text-green-800 text-sm" title="Convert to Client" data-testid="button-convert-<?= $lead['id'] ?>"><i class="fas fa-user-check"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this lead?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_lead">
                                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800 text-sm" title="Delete" data-testid="button-delete-lead-<?= $lead['id'] ?>"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div id="add-lead-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg w-full max-w-lg mx-4 shadow-xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <h3 class="text-lg font-semibold">Add New Lead</h3>
                        <button onclick="document.getElementById('add-lead-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_lead">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                            <input type="text" name="lead_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-name">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" name="lead_email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-email">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="text" name="lead_phone" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-phone">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                                <input type="text" name="lead_company" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-lead-company">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
                                <select name="lead_source" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-lead-source">
                                    <option value="manual">Manual</option>
                                    <option value="website">Website</option>
                                    <option value="referral">Referral</option>
                                    <option value="cold_call">Cold Call</option>
                                    <option value="social_media">Social Media</option>
                                    <option value="advertisement">Advertisement</option>
                                    <option value="email_campaign">Email Campaign</option>
                                    <option value="partner">Partner</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea name="lead_notes" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-lead-notes"></textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="document.getElementById('add-lead-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-lead">Add Lead</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'campaigns'): ?>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Campaigns</h2>
                <button onclick="document.getElementById('add-campaign-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-campaign">
                    <i class="fas fa-plus mr-1"></i>Create Campaign
                </button>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <?php if (empty($campaigns)): ?>
                    <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-bullhorn text-gray-300 text-2xl mb-2 block"></i>No campaigns yet.</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-campaigns">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Target</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Dates</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Stats</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($campaigns as $c):
                                $sc = ['draft'=>'gray','active'=>'green','paused'=>'yellow','completed'=>'blue','cancelled'=>'red'][$c['status']] ?? 'gray';
                                $tc = ['email'=>'blue','sms'=>'green','call'=>'yellow','social'=>'purple'][$c['type']] ?? 'gray';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="campaign-row-<?= $c['id'] ?>">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($c['name']) ?></td>
                                <td class="px-4 py-3"><span class="text-xs bg-<?= $tc ?>-100 text-<?= $tc ?>-700 px-2 py-0.5 rounded"><?= ucfirst($c['type']) ?></span></td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_campaign_status">
                                        <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1 bg-<?= $sc ?>-50 text-<?= $sc ?>-700 border-<?= $sc ?>-200">
                                            <?php foreach (['draft','active','paused','completed','cancelled'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600"><?= str_replace('_', ' ', ucfirst($c['target_audience'])) ?></td>
                                <td class="px-4 py-3 text-xs text-gray-500"><?= $c['start_date'] ? date('M d', strtotime($c['start_date'])) : 'N/A' ?><?= $c['end_date'] ? ' - ' . date('M d', strtotime($c['end_date'])) : '' ?></td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    <span title="Sent"><?= (int)$c['sent_count'] ?> sent</span> /
                                    <span title="Opened"><?= (int)$c['open_count'] ?> opened</span>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this campaign?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_campaign">
                                        <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm" data-testid="button-delete-campaign-<?= $c['id'] ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div id="add-campaign-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg w-full max-w-lg mx-4 shadow-xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <h3 class="text-lg font-semibold">Create Campaign</h3>
                        <button onclick="document.getElementById('add-campaign-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_campaign">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Campaign Name *</label>
                            <input type="text" name="campaign_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-name">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="campaign_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-campaign-type">
                                    <option value="email">Email</option>
                                    <option value="sms">SMS</option>
                                    <option value="call">Call</option>
                                    <option value="social">Social Media</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Target Audience</label>
                                <select name="campaign_target" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-campaign-target">
                                    <option value="all_clients">All Clients</option>
                                    <option value="active_clients">Active Clients</option>
                                    <option value="leads">Leads</option>
                                    <option value="inactive_clients">Inactive Clients</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                            <input type="text" name="campaign_subject" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-subject">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                            <textarea name="campaign_content" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-campaign-content"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input type="date" name="campaign_start" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-start">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input type="date" name="campaign_end" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-campaign-end">
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="document.getElementById('add-campaign-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-campaign">Create Campaign</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'meetings'): ?>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Meetings & Schedule</h2>
                <button onclick="document.getElementById('add-meeting-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-meeting">
                    <i class="fas fa-plus mr-1"></i>Schedule Meeting
                </button>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <?php if (empty($meetings)): ?>
                    <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-calendar-alt text-gray-300 text-2xl mb-2 block"></i>No meetings scheduled.</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-meetings">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Title</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date & Time</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Duration</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($meetings as $m):
                                $mc = ['scheduled'=>'blue','completed'=>'green','cancelled'=>'red','no_show'=>'yellow'][$m['status']] ?? 'gray';
                                $tc = ['consultation'=>'blue','onboarding'=>'green','support'=>'yellow','review'=>'purple','sales'=>'indigo'][$m['meeting_type']] ?? 'gray';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="meeting-row-<?= $m['id'] ?>">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($m['title']) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($m['client_name'] ?? 'N/A') ?></td>
                                <td class="px-4 py-3"><span class="text-xs bg-<?= $tc ?>-100 text-<?= $tc ?>-700 px-2 py-0.5 rounded"><?= ucfirst($m['meeting_type']) ?></span></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= date('M d, Y g:i A', strtotime($m['scheduled_at'])) ?></td>
                                <td class="px-4 py-3 text-xs text-gray-500"><?= (int)$m['duration_minutes'] ?> min</td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_meeting_status">
                                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="text-xs border rounded px-2 py-1 bg-<?= $mc ?>-50 text-<?= $mc ?>-700 border-<?= $mc ?>-200">
                                            <?php foreach (['scheduled','completed','cancelled','no_show'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $m['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this meeting?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_meeting">
                                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm" data-testid="button-delete-meeting-<?= $m['id'] ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div id="add-meeting-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg w-full max-w-lg mx-4 shadow-xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <h3 class="text-lg font-semibold">Schedule Meeting</h3>
                        <button onclick="document.getElementById('add-meeting-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_meeting">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                            <input type="text" name="meeting_title" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-title">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                                <select name="meeting_client_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-meeting-client">
                                    <option value="">-- Select Client --</option>
                                    <?php foreach ($clients_list as $cl): ?>
                                        <option value="<?= $cl['id'] ?>"><?= htmlspecialchars(($cl['company'] ?: $cl['name'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="meeting_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-meeting-type">
                                    <option value="consultation">Consultation</option>
                                    <option value="onboarding">Onboarding</option>
                                    <option value="support">Support</option>
                                    <option value="review">Review</option>
                                    <option value="sales">Sales</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date *</label>
                                <input type="date" name="meeting_date" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-date">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Time</label>
                                <input type="time" name="meeting_time" value="09:00" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-time">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Duration</label>
                                <select name="meeting_duration" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-meeting-duration">
                                    <option value="30">30 min</option>
                                    <option value="60" selected>1 hour</option>
                                    <option value="90">1.5 hours</option>
                                    <option value="120">2 hours</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                            <input type="text" name="meeting_location" placeholder="Zoom, Office, Phone..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-meeting-location">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea name="meeting_notes" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="textarea-meeting-notes"></textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="document.getElementById('add-meeting-modal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-meeting">Schedule Meeting</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'inbox'): ?>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Inbox</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-ticket-alt text-blue-500 mr-2"></i>Recent Tickets</h3>
                    </div>
                    <?php if (empty($recent_tickets)): ?>
                        <div class="p-6 text-center text-gray-500 text-sm">No recent tickets.</div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($recent_tickets as $t):
                            $sc = ['open'=>'green','in_progress'=>'blue','closed'=>'gray'][$t['status']] ?? 'gray';
                            $pc = ['low'=>'gray','medium'=>'yellow','high'=>'red','critical'=>'red'][$t['priority']] ?? 'gray';
                        ?>
                        <a href="admin-ticket-detail.php?id=<?= $t['id'] ?>" class="block px-6 py-3 hover:bg-gray-50 transition" data-testid="inbox-ticket-<?= $t['id'] ?>">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($t['subject']) ?></p>
                                <span class="text-xs bg-<?= $sc ?>-100 text-<?= $sc ?>-700 px-2 py-0.5 rounded ml-2 flex-shrink-0"><?= ucfirst(str_replace('_',' ',$t['status'])) ?></span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($t['client_name'] ?? 'Unknown') ?> &middot; <?= date('M d, g:i A', strtotime($t['created_at'])) ?></p>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-comments text-blue-500 mr-2"></i>Recent Chat Messages</h3>
                    </div>
                    <?php if (empty($recent_messages)): ?>
                        <div class="p-6 text-center text-gray-500 text-sm">No recent messages.</div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($recent_messages as $msg): ?>
                        <div class="px-6 py-3 hover:bg-gray-50" data-testid="inbox-message-<?= $msg['id'] ?>">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($msg['user_name'] ?? 'Unknown') ?></p>
                                <span class="text-xs text-gray-400">#<?= htmlspecialchars($msg['channel_name'] ?? $msg['room'] ?? '') ?></span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1 truncate"><?= htmlspecialchars(substr($msg['message'] ?? '', 0, 100)) ?></p>
                            <p class="text-xs text-gray-400 mt-1"><?= date('M d, g:i A', strtotime($msg['created_at'])) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
