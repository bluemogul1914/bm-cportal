<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

$filter_partner  = $_GET['partner']  ?? '';
$filter_location = $_GET['location'] ?? '';
$filter_status   = $_GET['status']   ?? '';
$filter_search   = trim($_GET['search_lead'] ?? '');

$where=[]; $params=[];
if ($filter_partner)  { $where[]="partner=?"; $params[]=$filter_partner; }
if ($filter_location) { $where[]="location=?"; $params[]=$filter_location; }
if ($filter_status)   { $where[]="pipeline_status=?"; $params[]=$filter_status; }
if ($filter_search)   { $where[]="full_name ILIKE ?"; $params[]="%$filter_search%"; }
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$leads = $pdo->prepare("SELECT id,full_name,geo_data,city,pipeline_status,email,phone,partner,location FROM leads $where_sql ORDER BY created_at DESC LIMIT 500");
$leads->execute($params);
$leads = $leads->fetchAll(PDO::FETCH_ASSOC);

$map_leads = array_filter($leads, fn($l)=>!empty($l['geo_data'])||!empty($l['city']));
$partners  = $pdo->query("SELECT DISTINCT partner FROM leads WHERE partner IS NOT NULL AND partner!='' ORDER BY partner")->fetchAll(PDO::FETCH_COLUMN);
$locations = $pdo->query("SELECT DISTINCT location FROM leads WHERE location IS NOT NULL AND location!='' ORDER BY location")->fetchAll(PDO::FETCH_COLUMN);

$status_colors = ['new_enquiry'=>'blue','qualification'=>'cyan','activation'=>'green','won'=>'darkgreen','lost'=>'red'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Leads Map — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="/assets/css/admin.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-hidden flex flex-col">

<header class="bg-white border-b border-gray-200">
    <div class="px-6 py-4">
        <p class="text-xs text-gray-400">Leads /</p>
        <h1 class="text-2xl font-semibold text-gray-900 flex items-center gap-2">
            <span class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center"><i class="fas fa-map-marked-alt text-white text-sm"></i></span>
            Maps
        </h1>
    </div>
</header>

<div class="flex flex-1 overflow-hidden">
    <!-- Map -->
    <div class="flex-1 relative">
        <div id="leads-map" class="w-full h-full"></div>
        <!-- Map attribution overlay fix -->
    </div>

    <!-- Right panel -->
    <div class="w-64 bg-white border-l border-gray-200 flex flex-col overflow-y-auto p-4 space-y-4">
        <form method="GET" class="space-y-3">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Partner</label>
                <select name="partner" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none">
                    <option value="">All</option>
                    <?php foreach ($partners as $p): ?><option value="<?= htmlspecialchars($p) ?>" <?= $filter_partner===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Location</label>
                <select name="location" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none">
                    <option value="">All</option>
                    <?php foreach ($locations as $l): ?><option value="<?= htmlspecialchars($l) ?>" <?= $filter_location===$l?'selected':'' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Status</label>
                <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none">
                    <option value="">All</option>
                    <option value="new_enquiry"   <?= $filter_status==='new_enquiry'?'selected':'' ?>>New enquiry</option>
                    <option value="qualification" <?= $filter_status==='qualification'?'selected':'' ?>>Qualification</option>
                    <option value="activation"    <?= $filter_status==='activation'?'selected':'' ?>>Activation</option>
                    <option value="won"           <?= $filter_status==='won'?'selected':'' ?>>Won</option>
                    <option value="lost"          <?= $filter_status==='lost'?'selected':'' ?>>Lost</option>
                </select>
            </div>
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-apply">
                Apply
            </button>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Search Lead</label>
                <div class="flex gap-1">
                    <input type="text" name="search_lead" value="<?= htmlspecialchars($filter_search) ?>"
                        class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none" data-testid="input-search-lead">
                    <button type="submit" class="px-2 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm"><i class="fas fa-search text-gray-500"></i></button>
                </div>
            </div>
            <a href="?tab=maps" class="block w-full text-center px-4 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm hover:bg-gray-50 transition">Reset</a>
        </form>

        <!-- Legend -->
        <div class="pt-3 border-t border-gray-100">
            <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Legend</p>
            <div class="flex items-center gap-2 text-sm text-gray-600">
                <span class="inline-block w-4 h-4 rounded-full bg-blue-500 border-2 border-white shadow"></span>Lead
            </div>
        </div>

        <!-- Leads count -->
        <div class="pt-3 border-t border-gray-100 text-xs text-gray-400">
            <?= count($map_leads) ?> lead<?= count($map_leads)!==1?'s':'' ?> on map
        </div>
    </div>
</div>

</div><!-- /flex-1 col -->
</div><!-- /flex row -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('leads-map').setView([29.7604,-95.3698],7);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(map);

const markers = <?php
$pts = [];
foreach ($map_leads as $l) {
    $geo = trim($l['geo_data']??'');
    $lat = null; $lng = null;
    if ($geo && strpos($geo,',')!==false) {
        $parts = explode(',',$geo);
        $lat = (float)$parts[0]; $lng = (float)$parts[1];
    } elseif ($l['city']) {
        // For demo: use random offset from Houston
        $lat = 29.7604 + (rand(-100,100)/100); $lng = -95.3698 + (rand(-100,100)/100);
    }
    if ($lat && $lng) {
        $pts[] = ['id'=>$l['id'],'name'=>$l['full_name'],'lat'=>$lat,'lng'=>$lng,'status'=>$l['pipeline_status'],'phone'=>$l['phone']??'','email'=>$l['email']??''];
    }
}
echo json_encode($pts);
?>;

const colorMap = {
    'new_enquiry':'#3b82f6','qualification':'#0ea5e9','activation':'#22c55e','won':'#15803d','lost':'#ef4444'
};

markers.forEach(m => {
    const color = colorMap[m.status] || '#6b7280';
    const icon = L.divIcon({
        html: `<div style="width:14px;height:14px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>`,
        iconSize:[14,14], iconAnchor:[7,7], popupAnchor:[0,-8], className:''
    });
    L.marker([m.lat,m.lng],{icon}).addTo(map)
        .bindPopup(`<div class="text-sm"><strong>${m.name}</strong><br>${m.phone||''}<br><a href="admin-leads-view.php?id=${m.id}" style="color:#3b82f6">View lead</a></div>`);
});
</script>
</body>
</html>
