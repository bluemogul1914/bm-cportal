<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

$lead_id = (int)($_GET['id'] ?? 0);
if (!$lead_id) { header("Location: admin-leads-list.php"); exit; }

$lead = $pdo->prepare("SELECT * FROM leads WHERE id=?");
$lead->execute([$lead_id]);
$lead = $lead->fetch(PDO::FETCH_ASSOC);
if (!$lead) { header("Location: admin-leads-list.php"); exit; }

$active_tab = $_GET['tab'] ?? 'information';
$success_msg = ''; $error_msg = '';

// SMTP config
$smtp = leads_smtp_settings($pdo);
$smtp_configured = !empty($smtp['host']) && !empty($smtp['username']);

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        // Update information
        if ($action === 'update_lead') {
            $fields = ['full_name','pipeline_status','owner','phone','email','source','partner','location','city','street','zip_code','geo_data','custom_status','notes','linkedin_url'];
            $set = []; $vals = [];
            foreach ($fields as $f) {
                $set[] = "$f=?";
                $vals[] = trim($_POST[$f] ?? '');
            }
            $vals[] = $lead_id;
            $pdo->prepare("UPDATE leads SET ".implode(',',$set).", updated_at=NOW() WHERE id=?")->execute($vals);
            $pdo->prepare("INSERT INTO lead_activities (lead_id,action,actor) VALUES (?,?,?)")->execute([$lead_id,'Update lead information',$_SESSION['user_name']??'Admin']);
            $lead = $pdo->prepare("SELECT * FROM leads WHERE id=?"); $lead->execute([$lead_id]); $lead = $lead->fetch(PDO::FETCH_ASSOC);
            $success_msg = 'Lead updated successfully.';
            $active_tab = 'information';

        // Update pipeline status
        } elseif ($action === 'set_pipeline') {
            $stat = $_POST['pipeline_status'] ?? '';
            if ($stat) {
                $pdo->prepare("UPDATE leads SET pipeline_status=?,updated_at=NOW() WHERE id=?")->execute([$stat,$lead_id]);
                $pdo->prepare("INSERT INTO lead_activities (lead_id,action,actor) VALUES (?,?,?)")->execute([$lead_id,"Pipeline changed to: $stat",$_SESSION['user_name']??'Admin']);
                $lead['pipeline_status'] = $stat;
                $success_msg = "Pipeline updated to ".ucwords(str_replace('_',' ',$stat)).".";
            }

        // Update deal value
        } elseif ($action === 'update_deal') {
            $val = (float)($_POST['deal_value'] ?? 0);
            $pdo->prepare("UPDATE leads SET deal_value=?,updated_at=NOW() WHERE id=?")->execute([$val,$lead_id]);
            $lead['deal_value'] = $val;
            $success_msg = 'Deal value updated.';

        // Add todo
        } elseif ($action === 'add_todo') {
            $todo = trim($_POST['todo'] ?? '');
            $sched = trim($_POST['scheduled_at'] ?? '') ?: null;
            if ($todo) {
                $pdo->prepare("INSERT INTO lead_todos (lead_id,todo,scheduled_at) VALUES (?,?,?)")->execute([$lead_id,$todo,$sched]);
                $success_msg = 'To-do added.';
            }

        // Add comment/message
        } elseif ($action === 'send_message') {
            $body    = trim($_POST['body'] ?? '');
            $subject = trim($_POST['subject'] ?? "Re: {$lead['full_name']}");
            $is_int  = isset($_POST['is_internal']);
            if ($body) {
                $pdo->prepare("INSERT INTO lead_messages (lead_id,direction,subject,body,is_internal,sender) VALUES (?,?,?,?,?,?)")
                    ->execute([$lead_id,'outbound',$subject,$body,$is_int,$_SESSION['user_name']??'Admin']);
                $pdo->prepare("UPDATE leads SET last_contacts=NOW(),last_comments=? WHERE id=?")->execute([substr($body,0,200),$lead_id]);
                $pdo->prepare("INSERT INTO lead_activities (lead_id,action,actor) VALUES (?,?,?)")->execute([$lead_id,'Sent message',$_SESSION['user_name']??'Admin']);
                $success_msg = 'Message sent.';
            }
            $active_tab = 'communication';

        // Add quote
        } elseif ($action === 'add_quote') {
            $status  = $_POST['q_status']   ?? 'new';
            $docdate = $_POST['doc_date']    ?? date('Y-m-d');
            $valid   = $_POST['valid_until'] ?? date('Y-m-d',strtotime('+10 days'));
            $dval    = (float)($_POST['deal_value'] ?? 0);
            $note    = trim($_POST['note']   ?? '');
            $memo    = trim($_POST['memo']   ?? '');
            $items   = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
            $tot     = (float)($_POST['total'] ?? 0);
            $tax_amt = $tot * 0.21;
            $tot_tax = $tot + $tax_amt;
            // Generate quote number
            $max_quote = $pdo->query("SELECT COALESCE(MAX(id),0) FROM lead_quotes")->fetchColumn();
            $qnum = date('Y').str_pad($max_quote+1,6,'0',STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO lead_quotes (lead_id,quote_number,status,document_date,valid_until,deal_value,note,memo,items,total_without_tax,tax_amount,total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$lead_id,$qnum,$status,$docdate,$valid,$dval,$note,$memo,json_encode($items),$tot,$tax_amt,$tot_tax]);
            $success_msg = "Quote #$qnum created.";
            $active_tab = 'quotes';

        // Upload document
        } elseif ($action === 'upload_document') {
            $title  = trim($_POST['doc_title']  ?? 'Untitled');
            $source = trim($_POST['doc_source'] ?? '');
            $desc   = trim($_POST['doc_desc']   ?? '');
            $pdo->prepare("INSERT INTO lead_documents (lead_id,title,source,description,created_by) VALUES (?,?,?,?,?)")
                ->execute([$lead_id,$title,$source,$desc,$_SESSION['user_name']??'Admin']);
            $success_msg = 'Document record added.';
            $active_tab = 'documents';
        }
    }
}

// Load related data
$todos    = $pdo->prepare("SELECT * FROM lead_todos WHERE lead_id=? ORDER BY created_at DESC"); $todos->execute([$lead_id]); $todos=$todos->fetchAll(PDO::FETCH_ASSOC);
$activities=$pdo->prepare("SELECT * FROM lead_activities WHERE lead_id=? ORDER BY created_at DESC LIMIT 20"); $activities->execute([$lead_id]); $activities=$activities->fetchAll(PDO::FETCH_ASSOC);
$quotes   = $pdo->prepare("SELECT * FROM lead_quotes WHERE lead_id=? ORDER BY created_at DESC"); $quotes->execute([$lead_id]); $quotes=$quotes->fetchAll(PDO::FETCH_ASSOC);
$documents= $pdo->prepare("SELECT * FROM lead_documents WHERE lead_id=? ORDER BY created_at DESC"); $documents->execute([$lead_id]); $documents=$documents->fetchAll(PDO::FETCH_ASSOC);
$messages = $pdo->prepare("SELECT * FROM lead_messages WHERE lead_id=? ORDER BY created_at ASC"); $messages->execute([$lead_id]); $messages=$messages->fetchAll(PDO::FETCH_ASSOC);

$status_labels = ['new_enquiry'=>'NEW ENQUIRY','qualification'=>'QUALIFICATION','activation'=>'ACTIVATION','won'=>'WON','lost'=>'LOST'];
$status_badge  = ['new_enquiry'=>'bg-yellow-100 text-yellow-800 border border-yellow-300',
                  'qualification'=>'bg-blue-100 text-blue-800 border border-blue-300',
                  'activation'=>'bg-green-100 text-green-800 border border-green-300',
                  'won'=>'bg-emerald-600 text-white','lost'=>'bg-red-500 text-white'];
$q_status_colors = ['new'=>'bg-blue-500','sent'=>'bg-indigo-500','on_review'=>'bg-yellow-500','accepted'=>'bg-green-500','denied'=>'bg-red-500'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($lead['full_name']) ?> — Lead — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<!-- Header -->
<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-start gap-4 justify-between flex-wrap">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="admin-leads-dashboard.php" class="hover:text-blue-600">Leads</a> /
                <a href="admin-leads-list.php" class="hover:text-blue-600">List</a> /
            </nav>
            <h1 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-gray-500 rounded-full flex items-center justify-center text-white text-sm font-bold"><?= strtoupper(substr($lead['full_name'],0,2)) ?></span>
                <?= htmlspecialchars($lead['full_name']) ?>
                (<?= str_pad($lead['id'],6,'0',STR_PAD_LEFT) ?> - <?= $lead['id'] ?>)
            </h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="?id=<?= intval($lead_id)-1 ?>&tab=<?= $active_tab ?>" class="w-8 h-8 border border-gray-300 rounded flex items-center justify-center text-gray-500 hover:bg-gray-50"><i class="fas fa-chevron-left text-xs"></i></a>
            <a href="?id=<?= intval($lead_id)+1 ?>&tab=<?= $active_tab ?>" class="w-8 h-8 border border-gray-300 rounded flex items-center justify-center text-gray-500 hover:bg-gray-50"><i class="fas fa-chevron-right text-xs"></i></a>
        </div>
    </div>

    <!-- Lead bar -->
    <div class="px-6 pb-4 flex items-center gap-4 flex-wrap">
        <form method="POST" class="flex items-center gap-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_deal">
            <select class="border border-gray-300 rounded px-3 py-1.5 text-sm" name="owner_display" onchange="">
                <option><?= htmlspecialchars($lead['owner'] ?? 'Me') ?></option>
            </select>
            <div class="flex items-center gap-1">
                <span class="text-xl font-bold text-gray-900">$<?= number_format((float)($lead['deal_value']??0),2) ?></span>
                <button type="button" onclick="document.getElementById('deal-modal').classList.remove('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-pencil-alt text-xs"></i></button>
            </div>
        </form>
        <!-- Pipeline buttons -->
        <div class="flex gap-1 ml-auto flex-wrap">
            <?php foreach ($status_labels as $k=>$v):
                $active_stat = $lead['pipeline_status'] === $k;
                $cls = $active_stat ? ($status_badge[$k]??'bg-blue-600 text-white').' font-bold' : 'bg-gray-100 text-gray-500 hover:bg-gray-200 border border-gray-200';
            ?>
            <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_pipeline">
                <input type="hidden" name="pipeline_status" value="<?= $k ?>">
                <button type="submit" class="px-3 py-1.5 rounded text-xs font-semibold transition <?= $cls ?>" data-testid="btn-pipeline-<?= $k ?>">
                    <?= $v ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-0 border-t border-gray-200">
        <?php foreach ([
            ['information','Information'],
            ['documents','Documents'],
            ['quotes','Quotes'],
            ['communication','Communication'],
            ['linkedin','🔗 LinkedIn'],
        ] as [$t,$l]): ?>
        <a href="?id=<?= $lead_id ?>&tab=<?= $t ?>"
            class="px-6 py-3 text-sm font-medium border-b-2 transition <?= $active_tab===$t ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
            <?= $l ?>
        </a>
        <?php endforeach; ?>
        <!-- Action buttons -->
        <div class="ml-auto flex items-center gap-2 px-4">
            <button class="px-3 py-1.5 border border-gray-300 text-sm text-gray-700 rounded hover:bg-gray-50">Actions ▾</button>
            <button class="px-3 py-1.5 border border-gray-300 text-sm text-gray-700 rounded hover:bg-gray-50">Tasks ▾</button>
            <button class="px-3 py-1.5 border border-gray-300 text-sm text-gray-700 rounded hover:bg-gray-50">Tickets ▾</button>
            <button class="px-3 py-1.5 bg-gray-600 text-sm text-white rounded hover:bg-gray-700">Convert</button>
            <button form="lead-form" type="submit" class="px-3 py-1.5 bg-blue-600 text-sm text-white rounded hover:bg-blue-700">Save</button>
        </div>
    </div>
</header>

<div class="p-6">
<?php if ($success_msg): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg"><?= htmlspecialchars($success_msg) ?></div><?php endif; ?>
<?php if ($error_msg): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg"><?= htmlspecialchars($error_msg) ?></div><?php endif; ?>

<!-- ═══════ INFORMATION TAB ═══════ -->
<?php if ($active_tab === 'information'): ?>
<form method="POST" id="lead-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_lead">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main fields -->
        <div class="lg:col-span-2 space-y-5">
            <div class="bg-white rounded-lg border border-gray-200 p-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Information</h3>
                <?php $fields = [
                    ['Full name','full_name','text'],['Phone number','phone','tel'],
                    ['Email','email','email'],['Source','source','text'],
                    ['Partner','partner','text'],['Location','location','text'],
                    ['City','city','text'],['Street','street','text'],
                    ['ZIP Code','zip_code','text'],['Geo data','geo_data','text'],
                ]; foreach ($fields as [$lbl,$nm,$tp]): ?>
                <div class="grid grid-cols-3 items-center gap-4">
                    <label class="text-sm font-medium text-gray-500 text-right"><?= $lbl ?></label>
                    <div class="col-span-2">
                        <input type="<?= $tp ?>" name="<?= $nm ?>" value="<?= htmlspecialchars($lead[$nm]??'') ?>"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 <?= $nm==='phone'||$nm==='geo_data'?'font-mono':'' ?>">
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="grid grid-cols-3 items-center gap-4">
                    <label class="text-sm font-medium text-gray-500 text-right">Notes</label>
                    <div class="col-span-2">
                        <textarea name="notes" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><?= htmlspecialchars($lead['notes']??'') ?></textarea>
                    </div>
                </div>
                <input type="hidden" name="pipeline_status" value="<?= htmlspecialchars($lead['pipeline_status']??'new_enquiry') ?>">
                <input type="hidden" name="owner" value="<?= htmlspecialchars($lead['owner']??'Me') ?>">
                <input type="hidden" name="custom_status" value="<?= htmlspecialchars($lead['custom_status']??'customer') ?>">
            </div>

            <!-- Map section -->
            <?php if ($lead['geo_data'] || $lead['city']): ?>
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 flex items-center justify-between border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-map-marked-alt text-blue-500 mr-2"></i>Maps</h3>
                    <button type="button" onclick="document.getElementById('map-section').classList.toggle('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-chevron-up"></i></button>
                </div>
                <div id="map-section">
                    <div id="lead-map" style="height:240px"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right: comments & activity -->
        <div class="space-y-5">
            <div class="bg-white rounded-lg border border-gray-200 p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-900">Comments / To-Dos</h3>
                    <button type="button" onclick="document.getElementById('todo-modal').classList.remove('hidden')" class="w-6 h-6 bg-gray-100 hover:bg-gray-200 rounded text-gray-500 text-xs"><i class="fas fa-plus"></i></button>
                </div>
                <?php if (empty($todos)): ?>
                <p class="text-sm text-gray-400">No comments.</p>
                <?php else: ?>
                <div class="space-y-2">
                <?php foreach ($todos as $td): ?>
                <div class="flex items-start gap-2 p-2 bg-gray-50 rounded text-xs">
                    <i class="fas fa-circle-dot text-blue-400 mt-0.5"></i>
                    <div><p class="text-gray-700"><?= htmlspecialchars($td['todo']) ?></p><?php if ($td['scheduled_at']): ?><p class="text-gray-400"><?= date('Y-m-d H:i',strtotime($td['scheduled_at'])) ?></p><?php endif; ?></div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Communication</h3>
                <?php if (empty($messages)): ?>
                <p class="text-sm text-gray-400">No messages.</p>
                <?php else: ?>
                <div class="space-y-2">
                <?php foreach (array_slice($messages,-3) as $m): ?>
                <div class="text-xs p-2 bg-gray-50 rounded">
                    <p class="font-medium text-gray-700"><?= htmlspecialchars($m['sender']??'Admin') ?></p>
                    <p class="text-gray-500 truncate"><?= htmlspecialchars(substr($m['body'],0,80)) ?></p>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Activity / Comments section -->
    <div class="mt-6 bg-white rounded-lg border border-gray-200">
        <div class="flex border-b border-gray-200">
            <button type="button" class="px-6 py-3 text-sm font-medium text-blue-700 border-b-2 border-blue-600">Activity</button>
            <button type="button" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700">Comments</button>
        </div>
        <div class="p-5">
            <h4 class="text-sm font-semibold text-gray-700 mb-3">Recent activities</h4>
            <?php if (empty($activities)): ?>
            <p class="text-sm text-gray-400">No recent activities.</p>
            <?php else: ?>
            <div class="space-y-3">
            <?php foreach ($activities as $a): ?>
            <div class="flex items-start gap-3">
                <div class="w-7 h-7 bg-gray-200 rounded-full flex items-center justify-center shrink-0 text-xs font-bold text-gray-600"><?= strtoupper(substr($a['actor']??'S',0,1)) ?></div>
                <div>
                    <p class="text-sm text-gray-700"><span class="font-medium"><?= htmlspecialchars($a['actor']??'System') ?></span> <?= htmlspecialchars($a['action']??'') ?></p>
                    <p class="text-xs text-gray-400"><?= $a['created_at'] ? date('Y-m-d H:i:s',strtotime($a['created_at'])) : '' ?></p>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- ═══════ DOCUMENTS TAB ═══════ -->
<?php elseif ($active_tab === 'documents'): ?>
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">Show</span>
            <select class="border border-gray-300 rounded px-2 py-1 text-sm"><option>100</option></select>
            <span class="text-sm text-gray-500">entries</span>
        </div>
        <div class="flex gap-2">
            <button onclick="document.getElementById('doc-modal').classList.remove('hidden')" class="px-3 py-1.5 border border-gray-300 text-sm text-gray-700 rounded hover:bg-gray-50">
                <i class="fas fa-upload mr-1"></i>Upload
            </button>
            <button class="px-3 py-1.5 border border-gray-300 text-sm text-gray-700 rounded hover:bg-gray-50">
                <i class="fas fa-file-contract mr-1"></i>Generate / Contract
            </button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-documents">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">ID</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Added by</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Status</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Source</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Title</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Date</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Description</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($documents)): ?>
                <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No data available in table</td></tr>
                <?php else: ?>
                <?php foreach ($documents as $d): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-gray-500"><?= $d['id'] ?></td>
                    <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($d['created_by']??'') ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700"><?= htmlspecialchars($d['status']??'active') ?></span></td>
                    <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($d['source']??'') ?></td>
                    <td class="px-4 py-3 text-gray-800"><?= htmlspecialchars($d['title']??'') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= $d['doc_date'] ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($d['description']??'') ?></td>
                    <td class="px-4 py-3"><button class="text-xs text-gray-400 hover:text-gray-600">···</button></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400">
        Showing 0 to <?= count($documents) ?> of <?= count($documents) ?> entries
    </div>
</div>

<!-- ═══════ QUOTES TAB ═══════ -->
<?php elseif ($active_tab === 'quotes'): ?>
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">Show</span>
            <select class="border border-gray-300 rounded px-2 py-1 text-sm"><option>100</option></select>
            <span class="text-sm text-gray-500">entries</span>
        </div>
        <button onclick="document.getElementById('quote-modal').classList.remove('hidden')" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700" data-testid="button-add-quote">
            <i class="fas fa-plus mr-1"></i>Add quote
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-quotes">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Status</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">ID</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Number</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Document date</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500">Total</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Valid until</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($quotes)): ?>
                <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No data available in table</td></tr>
                <?php else: ?>
                <?php foreach ($quotes as $q):
                    $qcol = $q_status_colors[$q['status']??'new'] ?? 'bg-gray-400';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-semibold <?= $qcol ?> text-white"><?= ucfirst(str_replace('_',' ',$q['status'])) ?></span></td>
                    <td class="px-4 py-3 font-mono text-gray-500"><?= $q['id'] ?></td>
                    <td class="px-4 py-3 font-mono text-gray-700"><?= htmlspecialchars($q['quote_number']??'') ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= $q['document_date'] ?></td>
                    <td class="px-4 py-3 text-right font-semibold">$<?= number_format((float)($q['total']??0),2) ?></td>
                    <td class="px-4 py-3 text-gray-400"><?= $q['valid_until'] ?></td>
                    <td class="px-4 py-3"><button class="text-xs text-gray-400 hover:text-gray-600">···</button></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Quote totals -->
    <div class="px-6 py-4 border-t border-gray-100">
        <h4 class="text-sm font-semibold text-gray-900 mb-3">Totals</h4>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            <?php $qstatuses = ['new'=>'New','sent'=>'Sent','on_review'=>'On review','accepted'=>'Accepted','denied'=>'Denied','Total'=>'Total'];
            foreach (['new','sent','on_review','accepted','denied'] as $qs):
                $qamt = count(array_filter($quotes,fn($q)=>$q['status']===$qs));
                $qtot = array_sum(array_map(fn($q)=>$q['status']===$qs?(float)$q['total']:0,$quotes));
            ?>
            <div class="flex items-center gap-3">
                <span class="px-2 py-0.5 rounded text-xs font-semibold <?= $q_status_colors[$qs]??'bg-gray-400' ?> text-white"><?= ucfirst(str_replace('_',' ',$qs)) ?></span>
                <span class="text-gray-500"><?= $qamt ?></span>
                <span class="ml-auto text-gray-700">$<?= number_format($qtot,2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══════ COMMUNICATION TAB ═══════ -->
<?php elseif ($active_tab === 'communication'): ?>
<div class="bg-white rounded-lg border border-gray-200">
    <?php if (!$smtp_configured): ?>
    <div class="mx-5 mt-5 flex items-center justify-between bg-red-50 border border-red-200 px-4 py-3 rounded-lg text-sm text-red-700">
        <span><i class="fas fa-exclamation-triangle mr-2"></i><strong>Warning!</strong> SMTP server not configured. You can't send messages! <a href="admin-smtp-settings.php" class="underline font-semibold">Setup SMTP server</a> in your profile</span>
        <button onclick="this.closest('div').remove()" class="text-red-400 hover:text-red-600"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>

    <div class="flex border-b border-gray-200 mt-4">
        <button class="px-6 py-3 text-sm font-medium text-blue-700 border-b-2 border-blue-600">Internal</button>
    </div>

    <div class="p-5">
        <!-- Lead email header -->
        <div class="flex items-center gap-3 mb-4">
            <span class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($lead['full_name']) ?></span>
            <?php if ($lead['email']): ?>
            <span class="text-sm text-gray-500">&lt;<?= htmlspecialchars($lead['email']) ?>&gt;</span>
            <?php endif; ?>
            <div class="ml-auto flex gap-2">
                <button class="px-2 py-1 text-xs border border-gray-300 rounded text-gray-500 hover:bg-gray-50"><i class="fas fa-times"></i></button>
                <select class="border border-gray-300 rounded px-2 py-1 text-xs text-gray-500">
                    <option>All</option><option>Internal</option><option>Outbound</option>
                </select>
                <?php if ($smtp_configured): ?>
                <button onclick="document.getElementById('msg-modal').classList.remove('hidden')" class="px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700" data-testid="button-send-email">
                    <i class="fas fa-paper-plane mr-1"></i>Send email
                </button>
                <?php else: ?>
                <button onclick="document.getElementById('msg-modal').classList.remove('hidden')" class="px-3 py-1 bg-gray-400 text-white text-xs rounded cursor-not-allowed" title="Configure SMTP first">
                    <i class="fas fa-paper-plane mr-1"></i>Send email
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Message list -->
        <?php if (empty($messages)): ?>
        <div class="py-10 text-center text-gray-400">
            <i class="fas fa-envelope-open text-4xl mb-2"></i>
            <p class="text-sm">No messages yet</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
        <?php foreach ($messages as $m): ?>
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-sm text-gray-800"><?= htmlspecialchars($m['sender']??'Admin') ?></span>
                    <?php if ($m['is_internal']): ?><span class="px-1.5 py-0.5 bg-yellow-100 text-yellow-700 text-xs rounded">Internal</span><?php endif; ?>
                </div>
                <span class="text-xs text-gray-400"><?= $m['created_at'] ? date('Y-m-d H:i',strtotime($m['created_at'])) : '' ?></span>
            </div>
            <?php if ($m['subject']): ?><p class="text-xs font-medium text-gray-500 mb-1">Subject: <?= htmlspecialchars($m['subject']) ?></p><?php endif; ?>
            <p class="text-sm text-gray-700 whitespace-pre-wrap"><?= htmlspecialchars($m['body']) ?></p>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($active_tab === 'linkedin'): ?>
<?php
$li_url_lead  = $lead['linkedin_url'] ?? '';
$li_data_lead = !empty($lead['linkedin_data']) ? (is_string($lead['linkedin_data']) ? json_decode($lead['linkedin_data'], true) : $lead['linkedin_data']) : null;
$proxycurl_cfg = defined('PROXYCURL_API_KEY') && PROXYCURL_API_KEY !== '';
?>
<div class="space-y-5 max-w-3xl">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-3">
            <svg class="w-6 h-6 flex-shrink-0" style="fill:#0A66C2" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
            <h2 class="text-base font-semibold text-gray-900">LinkedIn Profile</h2>
            <?php if ($li_data_lead): ?><span class="ml-auto text-xs text-green-600 bg-green-50 px-2 py-0.5 rounded-full font-medium"><i class="fas fa-check-circle mr-1"></i>Data fetched</span><?php endif; ?>
        </div>
        <div class="p-5 space-y-4">
            <div class="flex gap-2 items-end">
                <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-500 mb-1">LinkedIn Profile URL (person or company)</label>
                    <input type="url" id="li-url-input" value="<?= htmlspecialchars($li_url_lead) ?>" placeholder="https://www.linkedin.com/in/username  or  /company/name"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-linkedin-url-lead">
                </div>
                <button onclick="liSaveUrl()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm rounded-lg border border-gray-300 transition" data-testid="button-save-linkedin-url">Save URL</button>
                <?php if ($li_url_lead): ?>
                <a href="<?= htmlspecialchars($li_url_lead) ?>" target="_blank" class="px-4 py-2 bg-[#0A66C2] hover:bg-[#0856A8] text-white text-sm rounded-lg transition flex items-center gap-1" data-testid="link-open-linkedin">
                    <svg class="w-4 h-4" style="fill:white" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                    Open
                </a>
                <?php endif; ?>
            </div>
            <?php if (!$proxycurl_cfg): ?>
            <div class="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
                <i class="fas fa-info-circle mt-0.5 flex-shrink-0"></i>
                <div>Add <code class="bg-amber-100 px-1 rounded">PROXYCURL_API_KEY</code> to your environment variables to enable automated LinkedIn data fetching. <a href="https://nubela.co/proxycurl" target="_blank" class="underline">Get a key at ProxyCurl</a>.</div>
            </div>
            <?php else: ?>
            <div class="flex flex-wrap gap-2 items-center">
                <?php if ($li_url_lead): ?>
                <button onclick="liFetch()" class="px-4 py-2 bg-[#0A66C2] hover:bg-[#0856A8] text-white text-sm rounded-lg transition flex items-center gap-2" data-testid="button-fetch-linkedin">
                    <i class="fas fa-sync-alt"></i> Fetch Profile Data
                </button>
                <?php endif; ?>
                <button onclick="liSearchByEmail()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm rounded-lg border border-gray-300 transition"><i class="fas fa-envelope mr-1"></i>Lookup by Email</button>
                <button onclick="liSearchByName()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm rounded-lg border border-gray-300 transition"><i class="fas fa-user mr-1"></i>Lookup by Name</button>
            </div>
            <?php endif; ?>
            <div id="li-status" class="hidden text-sm"></div>
        </div>
    </div>

    <?php if ($li_data_lead): ?>
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="h-20 bg-gradient-to-r from-[#0A66C2] to-[#0856A8]"></div>
        <div class="px-6 pb-6 -mt-10">
            <?php if (!empty($li_data_lead['profile_pic_url'])): ?>
            <img src="<?= htmlspecialchars($li_data_lead['profile_pic_url']) ?>" class="w-20 h-20 rounded-full border-4 border-white shadow object-cover mb-3" onerror="this.style.display='none'" data-testid="img-linkedin-photo">
            <?php else: ?>
            <div class="w-20 h-20 rounded-full border-4 border-white shadow bg-gray-200 flex items-center justify-center mb-3"><i class="fas fa-user text-3xl text-gray-400"></i></div>
            <?php endif; ?>
            <h3 class="text-xl font-bold text-gray-900" data-testid="text-linkedin-name"><?= htmlspecialchars($li_data_lead['full_name'] ?? $li_data_lead['name'] ?? '') ?></h3>
            <?php if (!empty($li_data_lead['headline'])): ?><p class="text-gray-600 mt-0.5" data-testid="text-linkedin-headline"><?= htmlspecialchars($li_data_lead['headline']) ?></p><?php endif; ?>
            <div class="flex flex-wrap gap-3 mt-2 text-sm text-gray-500">
                <?php if (!empty($li_data_lead['city']) || !empty($li_data_lead['country_full_name'])): ?>
                <span><i class="fas fa-map-marker-alt text-[#0A66C2] mr-1"></i><?= htmlspecialchars(implode(', ', array_filter([$li_data_lead['city'] ?? '', $li_data_lead['country_full_name'] ?? '']))) ?></span>
                <?php endif; ?>
                <?php if (!empty($li_data_lead['connections'])): ?><span><i class="fas fa-users text-[#0A66C2] mr-1"></i><?= number_format($li_data_lead['connections']) ?>+ connections</span><?php endif; ?>
            </div>
        </div>
        <?php if (!empty($li_data_lead['summary'])): ?>
        <div class="px-6 py-4 border-t border-gray-100">
            <h4 class="text-sm font-semibold text-gray-700 mb-2">About</h4>
            <p class="text-sm text-gray-600 leading-relaxed"><?= nl2br(htmlspecialchars(substr($li_data_lead['summary'], 0, 500))) ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($li_data_lead['experiences'])): ?>
        <div class="px-6 py-4 border-t border-gray-100">
            <h4 class="text-sm font-semibold text-gray-700 mb-3">Experience</h4>
            <div class="space-y-3">
            <?php foreach (array_slice($li_data_lead['experiences'], 0, 4) as $exp): ?>
            <div class="flex gap-3">
                <div class="w-10 h-10 rounded bg-gray-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-building text-gray-400 text-sm"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($exp['title'] ?? '') ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($exp['company'] ?? '') ?></p>
                    <?php if (!empty($exp['starts_at'])): ?><p class="text-xs text-gray-400"><?= ($exp['starts_at']['month']??'?') . '/' . ($exp['starts_at']['year']??'?') ?> – <?= !empty($exp['ends_at']) ? ($exp['ends_at']['month']??'?') . '/' . ($exp['ends_at']['year']??'?') : 'Present' ?></p><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex justify-between items-center text-xs text-gray-400">
            <span>Data cached from ProxyCurl</span>
            <button onclick="liClearData()" class="text-red-500 hover:text-red-700 transition" data-testid="button-clear-linkedin-data"><i class="fas fa-trash-alt mr-1"></i>Clear cached data</button>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-lg border border-gray-200 p-8 text-center text-gray-400">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-30" style="fill:#0A66C2" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
        <p>No profile data yet.</p>
        <p class="text-xs mt-1">Set a LinkedIn URL above and click "Fetch Profile Data".</p>
    </div>
    <?php endif; ?>
</div>

<script>
const liEntityType = 'lead';
const liEntityId   = <?= $lead_id ?>;
const liEntityEmail = <?= json_encode($lead['email'] ?? '') ?>;
const liEntityName  = <?= json_encode($lead['full_name'] ?? '') ?>;

function liStatus(msg, type='info') {
    const el = document.getElementById('li-status');
    el.className = 'text-sm px-3 py-2 rounded-lg ' + (type==='error' ? 'bg-red-50 text-red-700' : type==='ok' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700');
    el.textContent = msg;
    el.classList.remove('hidden');
}
function liSaveUrl() {
    const url = document.getElementById('li-url-input').value.trim();
    liStatus('Saving URL…');
    fetch('/portal/api/linkedin-save-url', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({entity_type:liEntityType,entity_id:liEntityId,linkedin_url:url}) })
    .then(r=>r.json()).then(d=>{ if(d.success){liStatus('URL saved.',  'ok');setTimeout(()=>location.reload(),1200);}else liStatus('Error: '+d.error,'error'); }).catch(()=>liStatus('Network error','error'));
}
function liFetch() {
    const url = document.getElementById('li-url-input').value.trim();
    liStatus('Fetching from LinkedIn via ProxyCurl…');
    fetch('/portal/api/linkedin-lookup', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({linkedin_url:url,entity_type:liEntityType,entity_id:liEntityId}) })
    .then(r=>r.json()).then(d=>{ if(d.success){liStatus('Profile fetched!','ok');setTimeout(()=>location.reload(),1200);}else liStatus('Error: '+d.error,'error'); }).catch(()=>liStatus('Network error','error'));
}
function liSearchByEmail() {
    liStatus('Searching LinkedIn by email…');
    fetch('/portal/api/linkedin-lookup', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({search_email:liEntityEmail,entity_type:liEntityType,entity_id:liEntityId}) })
    .then(r=>r.json()).then(d=>{ if(d.success){liStatus('Profile found!','ok');setTimeout(()=>location.reload(),1200);}else liStatus('Error: '+d.error,'error'); }).catch(()=>liStatus('Network error','error'));
}
function liSearchByName() {
    liStatus('Searching LinkedIn by name…');
    fetch('/portal/api/linkedin-lookup', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({search_name:liEntityName,entity_type:liEntityType,entity_id:liEntityId}) })
    .then(r=>r.json()).then(d=>{ if(d.success){liStatus('Profile found!','ok');setTimeout(()=>location.reload(),1200);}else liStatus('Error: '+d.error,'error'); }).catch(()=>liStatus('Network error','error'));
}
function liClearData() {
    if(!confirm('Clear cached LinkedIn data?')) return;
    fetch('/portal/api/linkedin-save-url', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({entity_type:liEntityType,entity_id:liEntityId,linkedin_url:document.getElementById('li-url-input').value,clear_data:true}) })
    .then(r=>r.json()).then(d=>{ if(d.success)location.reload();else liStatus('Error: '+d.error,'error'); });
}
</script>

<?php endif; ?>
</div><!-- /p-6 -->

<!-- ─── Deal value modal ─── -->
<div id="deal-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-80 p-5">
        <h3 class="font-semibold text-gray-900 mb-3">Update Deal Value</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_deal">
            <input type="number" name="deal_value" value="<?= number_format((float)($lead['deal_value']??0),2,'.','') ?>" step="0.01" min="0"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-deal-value">
            <div class="flex gap-2 justify-end">
                <button type="button" onclick="document.getElementById('deal-modal').classList.add('hidden')" class="px-3 py-1.5 border border-gray-300 text-gray-600 rounded text-sm hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── To-Do modal ─── -->
<div id="todo-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-96 p-5">
        <h3 class="font-semibold text-gray-900 mb-3"><i class="fas fa-check-square text-blue-500 mr-2"></i>Add To-Do</h3>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_todo">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Task</label>
                <input type="text" name="todo" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-todo">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Scheduled (optional)</label>
                <input type="datetime-local" name="scheduled_at" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex gap-2 justify-end">
                <button type="button" onclick="document.getElementById('todo-modal').classList.add('hidden')" class="px-3 py-1.5 border border-gray-300 text-gray-600 rounded text-sm">Cancel</button>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm" data-testid="button-save-todo">Add</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Message modal ─── -->
<div id="msg-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-5">
        <h3 class="font-semibold text-gray-900 mb-3"><i class="fas fa-paper-plane text-blue-500 mr-2"></i>Send Message</h3>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_message">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Subject</label>
                <input type="text" name="subject" value="Re: <?= htmlspecialchars($lead['full_name']) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Message</label>
                <textarea name="body" rows="5" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-message-body"></textarea>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" name="is_internal" class="rounded"> Internal note</label>
            <div class="flex gap-2 justify-end">
                <button type="button" onclick="document.getElementById('msg-modal').classList.add('hidden')" class="px-3 py-1.5 border border-gray-300 text-gray-600 rounded text-sm">Cancel</button>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm" data-testid="button-send-msg">Send</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Quote modal ─── -->
<div id="quote-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="font-semibold text-gray-900 text-lg">Create quote</h3>
            <button onclick="document.getElementById('quote-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_quote">
            <input type="hidden" name="items_json" id="items-json" value="[]">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="q_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="new">New</option><option value="sent">Sent</option>
                        <option value="on_review">On review</option><option value="accepted">Accepted</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Number</label>
                    <input type="text" value="<?= date('Y').str_pad(count($quotes)+1,6,'0',STR_PAD_LEFT) ?>" readonly class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm font-mono text-gray-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Document date</label>
                    <input type="date" name="doc_date" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid until</label>
                    <input type="date" name="valid_until" value="<?= date('Y-m-d',strtotime('+10 days')) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Set deal value</label>
                    <input type="number" name="deal_value" value="0.0000" step="0.0001" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" id="q-deal-val">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Note</label>
                    <textarea name="note" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Note for lead"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Memo</label>
                    <textarea name="memo" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Memo for you"></textarea>
                </div>
            </div>
            <!-- Line items -->
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <table class="w-full text-sm" id="quote-items">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-3 py-2 w-6"></th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Description</th>
                        <th class="px-3 py-2 text-xs font-semibold text-gray-500 w-16">Qty</th>
                        <th class="px-3 py-2 text-xs font-semibold text-gray-500 w-12">Unit</th>
                        <th class="px-3 py-2 text-xs font-semibold text-gray-500 w-20">Price</th>
                        <th class="px-3 py-2 text-xs font-semibold text-gray-500 w-28">TAX</th>
                        <th class="px-3 py-2 text-xs font-semibold text-gray-500 w-20">With TAX</th>
                        <th class="px-3 py-2 text-xs font-semibold text-gray-500 w-20">Total</th>
                        <th class="px-3 py-2 w-6"></th>
                    </tr></thead>
                    <tbody id="quote-items-body">
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2 text-gray-300"><i class="fas fa-grip-vertical"></i></td>
                        <td class="px-3 py-2"><input type="text" placeholder="Describe or select one-time" class="w-full border border-gray-200 rounded px-2 py-1 text-xs focus:outline-none"></td>
                        <td class="px-3 py-2"><input type="number" value="1" min="1" class="w-full border border-gray-200 rounded px-2 py-1 text-xs font-mono text-center focus:outline-none item-qty"></td>
                        <td class="px-3 py-2"><input type="text" value="1" class="w-full border border-gray-200 rounded px-2 py-1 text-xs font-mono text-center focus:outline-none"></td>
                        <td class="px-3 py-2"><input type="number" value="0.0000" step="0.0001" min="0" class="w-full border border-gray-200 rounded px-2 py-1 text-xs font-mono text-right focus:outline-none item-price"></td>
                        <td class="px-3 py-2"><select class="w-full border border-gray-200 rounded px-1 py-1 text-xs focus:outline-none"><option>21% (Tax 21%)</option><option>0%</option></select></td>
                        <td class="px-3 py-2 text-xs font-mono text-right item-with-tax">0.0000</td>
                        <td class="px-3 py-2 text-xs font-mono font-semibold text-right item-total">0.00 $</td>
                        <td class="px-3 py-2"><button type="button" onclick="addQuoteRow()" class="text-blue-500 hover:text-blue-700"><i class="fas fa-plus text-xs"></i></button></td>
                    </tr>
                    </tbody>
                </table>
                <div class="px-3 py-2 border-t border-gray-100">
                    <button type="button" onclick="addQuoteRow()" class="text-xs text-blue-500 hover:underline"><i class="fas fa-plus mr-1"></i>Add more items</button>
                </div>
                <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 text-right space-y-1 text-sm">
                    <div class="flex justify-end gap-8"><span class="text-gray-500">Total without TAX</span><span id="q-sub" class="font-semibold">0.00 $</span></div>
                    <div class="flex justify-end gap-8"><span class="text-gray-500">TAX</span><span id="q-tax" class="font-semibold">0.00 $</span></div>
                    <div class="flex justify-end gap-8 text-base"><span class="font-bold text-gray-900">Total:</span><span id="q-total" class="font-bold text-gray-900">0.00 $</span></div>
                    <input type="hidden" name="total" id="q-total-hidden" value="0">
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('quote-modal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 text-gray-700 rounded text-sm hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700" data-testid="button-create-quote">Add</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Document modal ─── -->
<div id="doc-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-96 p-5">
        <h3 class="font-semibold text-gray-900 mb-3"><i class="fas fa-upload text-blue-500 mr-2"></i>Upload Document</h3>
        <form method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_document">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Title</label>
                <input type="text" name="doc_title" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Source</label>
                <input type="text" name="doc_source" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                <textarea name="doc_desc" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
            </div>
            <div class="flex gap-2 justify-end">
                <button type="button" onclick="document.getElementById('doc-modal').classList.add('hidden')" class="px-3 py-1.5 border border-gray-300 text-gray-600 rounded text-sm">Cancel</button>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm">Upload</button>
            </div>
        </form>
    </div>
</div>

</div><!-- /flex-1 -->
</div><!-- /flex -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// Map
<?php if ($lead['geo_data'] || $lead['city']): ?>
(function(){
    const geo = '<?= htmlspecialchars($lead['geo_data']??'') ?>'.trim();
    let lat=29.7604, lng=-95.3698;
    if (geo.includes(',')) { const p=geo.split(','); lat=parseFloat(p[0])||lat; lng=parseFloat(p[1])||lng; }
    const map = L.map('lead-map').setView([lat,lng],12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap contributors'}).addTo(map);
    L.marker([lat,lng]).addTo(map).bindPopup('<?= htmlspecialchars(addslashes($lead['full_name'])) ?>');
})();
<?php endif; ?>

// Quote calculations
function calcQuote() {
    let sub=0;
    document.querySelectorAll('#quote-items-body tr').forEach(row=>{
        const qty=parseFloat(row.querySelector('.item-qty')?.value||0);
        const price=parseFloat(row.querySelector('.item-price')?.value||0);
        const line=qty*price;
        const tax=line*0.21;
        row.querySelector('.item-with-tax') && (row.querySelector('.item-with-tax').textContent=(line*0.21).toFixed(4));
        row.querySelector('.item-total')    && (row.querySelector('.item-total').textContent=(line+tax).toFixed(2)+' $');
        sub+=line;
    });
    const tax=sub*0.21;
    document.getElementById('q-sub').textContent=sub.toFixed(2)+' $';
    document.getElementById('q-tax').textContent=tax.toFixed(2)+' $';
    document.getElementById('q-total').textContent=(sub+tax).toFixed(2)+' $';
    document.getElementById('q-total-hidden').value=(sub+tax).toFixed(4);
    document.getElementById('q-deal-val').value=sub.toFixed(4);
}
function addQuoteRow() {
    const row = document.querySelector('#quote-items-body tr').cloneNode(true);
    row.querySelectorAll('input').forEach(i=>{if(i.type!=='button')i.value=i.type==='number'?(i.classList.contains('item-price')?'0.0000':'1'):'';});
    row.querySelectorAll('.item-with-tax,.item-total').forEach(c=>c.textContent='0.0000');
    document.getElementById('quote-items-body').appendChild(row);
    row.querySelectorAll('.item-qty,.item-price').forEach(i=>i.addEventListener('input',calcQuote));
}
document.querySelectorAll('.item-qty,.item-price').forEach(i=>i.addEventListener('input',calcQuote));

// Modal click-outside close
['deal-modal','todo-modal','msg-modal','quote-modal','doc-modal'].forEach(id=>{
    document.getElementById(id)?.addEventListener('click',function(e){if(e.target===this)this.classList.add('hidden');});
});
</script>
</body>
</html>
