<?php
session_start();

define('SB_URL',  'https://lvvfsgkxpulbpwrpyhuf.supabase.co/rest/v1');
define('SB_KEY',  'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imx2dmZzZ2t4cHVsYnB3cnB5aHVmIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzMxMzEwMDQsImV4cCI6MjA4ODcwNzAwNH0.AGC-gPrNxbqCLpm6EWtCjGjzJjgq228ZNb2i5KTy7JU');

// ── Supabase API helper ───────────────────────────────────────────────────────
define('SB_DEBUG', true); // set to false in production

function sb(string $method, string $table, array $body = [], array $params = []): array {
    $url = SB_URL . '/' . $table;
    if ($params) {
        $parts = [];
        foreach ($params as $k => $v) $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
        $url .= '?' . implode('&', $parts);
    }
    $headers = [
        'apikey: ' . SB_KEY,
        'Authorization: Bearer ' . SB_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    $data = json_decode($res, true) ?? [];

    // Log errors to PHP error log AND store for display
    if ($code >= 400 || $curl_err) {
        $errMsg = "[FVM-SB] {$method} /{$table} → HTTP {$code} | Error: ".($curl_err ?: ($data['message'] ?? $res));
        error_log($errMsg);
        if (SB_DEBUG && !headers_sent()) {
            // Store in session to show on next page load
            $_SESSION['sb_errors'][] = [
                'method'  => $method,
                'table'   => $table,
                'code'    => $code,
                'message' => $data['message'] ?? $data['hint'] ?? $res,
                'hint'    => $data['hint'] ?? '',
                'body'    => $method !== 'GET' ? json_encode($body) : '',
            ];
        }
    }

    return ['ok' => $code < 400, 'data' => $data, 'code' => $code];
}

// Shortcuts
function sbGet(string $table, array $params = []): array   { return sb('GET',    $table, [], $params)['data'] ?? []; }
function sbPost(string $table, array $body): bool          { return sb('POST',   $table, $body)['ok']; }
function sbPatch(string $table, array $body, string $id): bool { return sb('PATCH', $table, $body, ['id' => 'eq.'.$id])['ok']; }
function sbDelete(string $table, string $id): bool         { return sb('DELETE', $table, [], ['id' => 'eq.'.$id])['ok']; }

// Next code generator (V006, D005, etc.)
function nextCode(string $prefix, string $table, string $field): string {
    $rows = sbGet($table, ['select' => $field, 'order' => $field.'.desc', 'limit' => 1]);
    $last = $rows[0][$field] ?? null;
    $num  = $last ? ((int)substr($last, strlen($prefix))) + 1 : 1;
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function e(mixed $v): string { return htmlspecialchars((string)$v); }
function peso(int|float $n): string { return '₱'.number_format($n); }
function isExpired(string $date): bool { return $date && strtotime($date) < strtotime(date('Y-m-d')); }
function findById(array $arr, ?string $id): ?array { foreach ($arr as $i) { if (($i['id']??null)===$id) return $i; } return null; }
function findByCode(array $arr, ?string $code, string $field='vehicle_code'): ?array { foreach ($arr as $i) { if (($i[$field]??null)===$code) return $i; } return null; }
function statusColor(string $s): string {
    return match($s) {
        'Active','Available','Compliant','Completed' => '#2e7d32',
        'In Maintenance','Pending','Due Soon','In Progress','On Trip' => '#d97706',
        'Inactive','Expired','Rejected','Retired' => '#dc2626',
        default => '#627065'
    };
}
function badge(string $label, string $color): string {
    return "<span style='font-size:10px;font-weight:700;padding:3px 10px;border-radius:100px;background:{$color}18;color:{$color};border:1px solid {$color}44;letter-spacing:0.5px;text-transform:uppercase;white-space:nowrap;'>".e($label)."</span>";
}
function fuelBar(int $pct): string {
    $c = $pct > 30 ? '#388e3c' : '#ef4444';
    return "<div style='width:80px;height:6px;background:#edf5ef;border-radius:3px;overflow:hidden;display:inline-block;'><div style='width:{$pct}%;height:100%;background:{$c};border-radius:3px;'></div></div>";
}
function scoreBar(int $pct): string {
    $c = $pct >= 85 ? '#388e3c' : ($pct >= 70 ? '#f59e0b' : '#ef4444');
    return "<div style='width:100px;height:6px;background:#edf5ef;border-radius:3px;overflow:hidden;display:inline-block;'><div style='width:{$pct}%;height:100%;background:{$c};border-radius:3px;'></div></div>";
}

$page  = $_GET['page'] ?? 'dashboard';
$today = date('Y-m-d');

// ── Driver login status endpoint — called by GPS map to check active drivers ──
if (isset($_GET['get_active_drivers'])) {
    header('Content-Type: application/json');

    // ── Method 1: drivers online via portal heartbeat (is_online + last_seen columns)
    // These columns are optional — added via migration. We try and silently skip on error.
    $onlineDriverIds = [];
    $onlineDrivers = sbGet('fvm_drivers', [
        'select'    => 'id',          // ← only 'id', no vehicle_id on fvm_drivers
        'is_online' => 'eq.true',
        'last_seen' => 'gte.'.date('c', strtotime('-15 minutes')),
    ]);
    // sbGet returns [] on error (e.g. column doesn't exist yet) — safe to use
    if (!empty($onlineDrivers) && isset($onlineDrivers[0]['id'])) {
        $onlineDriverIds = array_column($onlineDrivers, 'id');
    }

    // ── Method 2: active trips — vehicle_id lives on fvm_trips, not fvm_drivers
    $activeTrips = sbGet('fvm_trips', [
        'select' => 'driver_id,vehicle_id',
        'status' => 'in.(Pending,In Progress)',
    ]);
    $tripDriverIds    = array_column($activeTrips, 'driver_id');
    $tripVehicleIds   = array_column($activeTrips, 'vehicle_id');

    // Merge: a driver is "active" if online OR has an active trip
    $activeDriverIds  = array_values(array_unique(array_merge($onlineDriverIds, $tripDriverIds)));
    $activeVehicleIds = array_values(array_unique($tripVehicleIds));

    echo json_encode([
        'ok'                => true,
        'active_driver_ids' => $activeDriverIds,
        'active_vehicle_ids'=> $activeVehicleIds,
    ]);
    exit;
}


// ── AJAX: GET NOTIFICATIONS for driver portal polling ────────────────────────
if (isset($_GET['get_notifications'])) {
    header('Content-Type: application/json');
    $did = $_GET['driver_id'] ?? null;
    if (!$did) { echo json_encode(['ok'=>false,'notifications'=>[]]); exit; }
    $res=sb('GET','fvm_notifications',[],['driver_id'=>'eq.'.$did,'is_read'=>'eq.false','order'=>'created_at.desc','limit'=>'10','select'=>'*']);
    if(!$res['ok']){echo json_encode(['ok'=>true,'notifications'=>[],'hint'=>'run_migration']);exit;}
    echo json_encode(['ok'=>true,'notifications'=>is_array($res['data'])?$res['data']:[]]);
    exit;
}

// ── AJAX: MARK notification as read ──────────────────────────────────────────
if (isset($_GET['mark_notif_read'])) {
    header('Content-Type: application/json');
    $nid = $_GET['notif_id'] ?? null;
    if (!$nid) { echo json_encode(['ok'=>false]); exit; }
    $ok = sb('PATCH','fvm_notifications',['is_read'=>true],['id'=>'eq.'.$nid])['ok'];
    echo json_encode(['ok'=>$ok]);
    exit;
}

// ── GPS PING endpoint (called by driver's phone every 10s via fetch) ──────────
// Handles both POST JSON and GET requests for flexibility
if (isset($_GET['gps_ping']) || (isset($_SERVER['HTTP_X_GPS_PING']))) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid   = $input['trip_id']  ?? $_GET['trip_id']  ?? null;
    $lat   = (float)($input['lat']    ?? $_GET['lat']    ?? 0);
    $lng   = (float)($input['lng']    ?? $_GET['lng']    ?? 0);
    $spd   = (float)($input['speed']  ?? $_GET['speed']  ?? 0);  // km/h
    $hdg   = (float)($input['heading']?? $_GET['heading']?? 0);  // degrees
    $acc   = (float)($input['accuracy']??$_GET['accuracy']??0);  // meters
    if (!$tid || ($lat===0.0 && $lng===0.0)) { echo json_encode(['ok'=>false,'error'=>'Missing params']); exit; }

    // Get trip to find vehicle_id
    $tripRows = sbGet('fvm_trips', ['id'=>'eq.'.$tid,'select'=>'id,vehicle_id,driver_id']);
    if (empty($tripRows)) { echo json_encode(['ok'=>false,'error'=>'Trip not found']); exit; }
    $vid = $tripRows[0]['vehicle_id'];

    // Determine movement status
    $mvStatus = $spd < 2 ? 'Stopped' : ($spd < 10 ? 'Idle' : 'Moving');

    // Always INSERT a new row — builds full GPS trail history for route replay
    $ts = date('c');
    sbPost('fvm_trip_tracking',[
        'trip_id'=>$tid,'vehicle_id'=>$vid,'lat'=>$lat,'lng'=>$lng,
        'speed_kmh'=>$spd,'heading'=>$hdg,'accuracy'=>$acc,
        'movement_status'=>$mvStatus,'updated_at'=>$ts,
    ]);
    // NOTE: We do NOT patch fvm_vehicles lat/lng here anymore.
    // The map reads directly from fvm_trip_tracking (always-fresh),
    // so updating fvm_vehicles was causing a race condition where the vehicle
    // updated_at would surpass the tracking row timestamp.

    echo json_encode(['ok'=>true,'status'=>$mvStatus,'ts'=>date('c')]);
    exit;
}

// ── GET tracking data for a trip (called by dispatch panel via fetch) ─────────
if (isset($_GET['get_tracking'])) {
    header('Content-Type: application/json');
    $tid  = $_GET['trip_id'] ?? null;
    if (!$tid) { echo json_encode(['ok'=>false]); exit; }
    // Most recent GPS ping
    $rows = sbGet('fvm_trip_tracking',['trip_id'=>'eq.'.$tid,'select'=>'*','order'=>'updated_at.desc','limit'=>1]);
    // Also get trip notes so dispatch can detect driver arrival confirmation
    $tripRow = sbGet('fvm_trips',['id'=>'eq.'.$tid,'select'=>'notes,status']);
    $notes   = $tripRow[0]['notes'] ?? '';
    $arrived = $notes && str_starts_with($notes, 'Driver confirmed arrival');
    // Also get full trail for map replay (last 200 points)
    $trail = sbGet('fvm_trip_tracking',['trip_id'=>'eq.'.$tid,'select'=>'lat,lng,updated_at','order'=>'updated_at.asc','limit'=>'200']);
    echo json_encode(['ok'=>true,'data'=>$rows[0]??null,'arrived'=>$arrived,'notes'=>$notes,'trail'=>$trail]);
    exit;
}

// ── Driver mobile page (minimal — driver opens this on phone) ─────────────────
if (isset($_GET['driver_track'])) {
    $tid = $_GET['trip_id'] ?? '';
    $tripRows = sbGet('fvm_trips',['id'=>'eq.'.$tid,'select'=>'trip_code,origin,destination,status']);
    $trip = $tripRows[0] ?? null;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>FVM Driver Tracker</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:system-ui,sans-serif;background:#0d150e;color:#fff;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;}
.card{background:#14401a;border-radius:20px;padding:28px;width:100%;max-width:380px;text-align:center;}
.logo{font-size:36px;margin-bottom:10px;}
.title{font-size:22px;font-weight:700;margin-bottom:4px;}
.sub{font-size:13px;color:#81c784;margin-bottom:24px;}
.route{background:rgba(255,255,255,0.08);border-radius:12px;padding:14px;margin-bottom:20px;font-size:14px;}
.status{font-size:18px;font-weight:700;margin:16px 0 8px;}
.coords{font-size:11px;color:#81c784;margin-bottom:20px;font-family:monospace;}
.btn{width:100%;padding:16px;border-radius:14px;border:none;font-size:16px;font-weight:700;cursor:pointer;margin-bottom:10px;}
.btn-start{background:linear-gradient(135deg,#256427,#2e7d32);color:#fff;}
.btn-stop{background:#dc2626;color:#fff;}
.pulse{width:12px;height:12px;background:#22c55e;border-radius:50%;display:inline-block;animation:p 1.5s infinite;margin-right:6px;}
@keyframes p{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.5;transform:scale(0.8)}}
.inactive{background:#dc2626;}
</style>
</head>
<body>
<div class="card">
  <div class="logo">🚛</div>
  <div class="title">FVM Driver Tracker</div>
  <div class="sub">Trip <?=e($trip?$trip['trip_code']:'Unknown')?></div>
  <?php if($trip): ?>
  <div class="route">
    📍 <?=e($trip['origin'])?> → <?=e($trip['destination'])?>
  </div>
  <?php endif; ?>
  <div class="status" id="status-text">Press Start to begin sharing location</div>
  <div class="coords" id="coords-text">Waiting for GPS...</div>
  <button class="btn btn-start" id="btn-track" onclick="toggleTracking()">📡 Start Sharing Location</button>
  <div id="msg" style="font-size:12px;color:#81c784;min-height:18px;"></div>
</div>
<script>
var tripId   = '<?=e($tid)?>';
var tracking = True;
var watchId  = null;
var pingCount = 0;

function toggleTracking() {
  if (!tracking) startTracking(); else stopTracking();
}

function startTracking() {
  if (!navigator.geolocation) {
    document.getElementById('status-text').textContent = '❌ GPS not available on this device';
    return;
  }
  tracking = true;
  document.getElementById('btn-track').className = 'btn btn-stop';
  document.getElementById('btn-track').textContent = '⏹ Stop Sharing';
  document.getElementById('status-text').innerHTML = '<span class="pulse"></span> Sharing location...';
  watchId = navigator.geolocation.watchPosition(onPosition, onError, {
    enableHighAccuracy: true, maximumAge: 5000, timeout: 10000
  });
}

function stopTracking() {
  tracking = false;
  if (watchId !== null) navigator.geolocation.clearWatch(watchId);
  document.getElementById('btn-track').className = 'btn btn-start';
  document.getElementById('btn-track').textContent = '📡 Start Sharing Location';
  document.getElementById('status-text').textContent = '⏸ Location sharing stopped';
}

function onPosition(pos) {
  var lat  = pos.coords.latitude;
  var lng  = pos.coords.longitude;
  var spd  = pos.coords.speed ? (pos.coords.speed * 3.6).toFixed(1) : 0;  // m/s → km/h
  var hdg  = pos.coords.heading || 0;
  var acc  = pos.coords.accuracy ? pos.coords.accuracy.toFixed(0) : 0;
  pingCount++;
  document.getElementById('coords-text').textContent =
    'Lat: '+lat.toFixed(6)+' | Lng: '+lng.toFixed(6)+'\nSpeed: '+spd+' km/h | Accuracy: ±'+acc+'m';

  fetch('fvm.php?gps_ping=1', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-GPS-Ping':'1'},
    body: JSON.stringify({trip_id:tripId,lat:lat,lng:lng,speed:parseFloat(spd),heading:hdg,accuracy:parseFloat(acc)})
  }).then(r=>r.json()).then(d=>{
    document.getElementById('msg').textContent = '✓ Ping #'+pingCount+' sent · '+new Date().toLocaleTimeString();
  }).catch(()=>{
    document.getElementById('msg').textContent = '⚠ Ping failed — check connection';
  });
}

function onError(err) {
  var msgs = {1:'Permission denied',2:'Position unavailable',3:'Timeout'};
  document.getElementById('status-text').textContent = '❌ GPS Error: '+(msgs[err.code]||err.message);
}
</script>

<?php exit; }

// ── AJAX: ANALYTICS EXCEL EXPORT ─────────────────────────────────────────────
if (isset($_GET['export_analytics'])) {
    // Collect all data needed
    $expRows  = sbGet('fvm_expenses', ['order' => 'expense_date.desc']);
    $tripRows = sbGet('fvm_trips',    ['order' => 'scheduled_date.desc']);
    $incRows  = sbGet('fvm_incidents',['order' => 'incident_date.desc']);
    $vRows    = sbGet('fvm_vehicles', ['order' => 'vehicle_code.asc']);
    $dRows    = sbGet('fvm_drivers',  ['order' => 'driver_code.asc']);

    // Helper: find by id inline
    $fbi = function(array $arr, ?string $id) {
        foreach($arr as $r) if(($r['id']??null)===$id) return $r;
        return null;
    };

    // Build CSV-safe Excel via tab-separated values (opens cleanly in Excel)
    $today_str = date('Y-m-d');
    $sheets = [];

    // ── Sheet 1: Summary ──────────────────────────────────────────────────────
    $totalExp  = array_sum(array_map(fn($e)=>(float)$e['amount'], $expRows));
    $fuelExp   = array_sum(array_map(fn($e)=>(float)$e['amount'], array_filter($expRows,fn($e)=>$e['expense_type']==='Fuel')));
    $maintExp  = array_sum(array_map(fn($e)=>(float)$e['amount'], array_filter($expRows,fn($e)=>$e['expense_type']==='Maintenance')));
    $totalMile = array_sum(array_column($tripRows,'mileage_km'));
    $monthlyTotals = [];
    foreach($expRows as $e){ $m=substr($e['expense_date'],0,7); $monthlyTotals[$m]=($monthlyTotals[$m]??0)+(float)$e['amount']; }
    ksort($monthlyTotals);

    $sheets['Summary'] = [
        ['FVM Fleet Analytics Report'],
        ['Generated', $today_str],
        [''],
        ['OVERVIEW'],
        ['Metric','Value'],
        ['Total Expenses', number_format($totalExp,2)],
        ['Fuel Costs',     number_format($fuelExp,2)],
        ['Maintenance Costs', number_format($maintExp,2)],
        ['Total Trips',    count($tripRows)],
        ['Total Mileage (km)', number_format($totalMile)],
        ['Total Incidents',count($incRows)],
        ['Total Vehicles', count($vRows)],
        ['Total Drivers',  count($dRows)],
        [''],
        ['MONTHLY EXPENSE TREND'],
        ['Month','Total Expenses (₱)'],
    ];
    foreach($monthlyTotals as $m=>$amt) $sheets['Summary'][] = [$m, number_format($amt,2)];

    // ── Sheet 2: Expenses ────────────────────────────────────────────────────
    $sheets['Expenses'] = [['Code','Vehicle Plate','Vehicle','Type','Amount (₱)','Date','Approved By','Notes']];
    foreach($expRows as $e){
        $v=$fbi($vRows,$e['vehicle_id']);
        $sheets['Expenses'][] = [
            $e['expense_code']??'',
            $v?$v['plate']:'?',
            $v?$v['make'].' '.$v['model']:'',
            $e['expense_type']??'',
            number_format((float)$e['amount'],2),
            $e['expense_date']??'',
            $e['approved_by']??'',
            $e['notes']??'',
        ];
    }

    // ── Sheet 3: Trips ───────────────────────────────────────────────────────
    $sheets['Trips'] = [['Code','Origin','Destination','Driver','Vehicle','Date','Status','Mileage (km)','Priority']];
    foreach($tripRows as $t){
        $v=$fbi($vRows,$t['vehicle_id']); $d=$fbi($dRows,$t['driver_id']??null);
        $sheets['Trips'][] = [
            $t['trip_code']??'',
            $t['origin']??'', $t['destination']??'',
            $d?$d['full_name']:'—',
            $v?$v['plate']:'?',
            $t['scheduled_date']??'',
            $t['status']??'',
            $t['mileage_km']??0,
            $t['priority']??'Normal',
        ];
    }

    // ── Sheet 4: Incidents ───────────────────────────────────────────────────
    $sheets['Incidents'] = [['Code','Type','Severity','Vehicle','Driver','Date','Status','Damage Total (₱)','Description']];
    foreach($incRows as $i){
        $v=$fbi($vRows,$i['vehicle_id']); $d=$fbi($dRows,$i['driver_id']??null);
        $sheets['Incidents'][] = [
            $i['incident_code']??'',
            $i['incident_type']??'',
            $i['severity']??'',
            $v?$v['plate']:'?',
            $d?$d['full_name']:'—',
            $i['incident_date']??'',
            $i['status']??'',
            number_format((float)($i['damage_total']??0),2),
            preg_replace('/[\r\n]+/',' ',$i['description']??''),
        ];
    }

    // ── Sheet 5: Vehicles ────────────────────────────────────────────────────
    $sheets['Vehicles'] = [['Code','Plate','Make','Model','Type','Status','Fuel Level (%)','Mileage (km)','Last Inspection']];
    foreach($vRows as $v){
        $sheets['Vehicles'][] = [
            $v['vehicle_code']??'', $v['plate']??'',
            $v['make']??'', $v['model']??'',
            $v['vehicle_type']??'',
            $v['status']??'',
            $v['fuel_level']??0,
            $v['mileage']??0,
            $v['last_inspection']??'',
        ];
    }

    // ── Sheet 6: Drivers ─────────────────────────────────────────────────────
    $sheets['Drivers'] = [['Code','Name','License No','License Expiry','Phone','Status','Behavior Score']];
    foreach($dRows as $d){
        $sheets['Drivers'][] = [
            $d['driver_code']??'', $d['full_name']??'',
            $d['license_no']??'', $d['license_expiry']??'',
            $d['phone']??'', $d['status']??'',
            $d['behavior_score']??100,
        ];
    }

    // ── Build multi-sheet Excel XML (SpreadsheetML — opens natively in Excel) ──
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
    $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"'."\n";
    $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"'."\n";
    $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
    $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">'."\n";
    $xml .= '<Styles>'."\n";
    $xml .= '<Style ss:ID="Header"><Font ss:Bold="1" ss:Size="11"/><Interior ss:Color="#1B5E20" ss:Pattern="Solid"/><Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/></Style>'."\n";
    $xml .= '<Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14" ss:Color="#1B5E20"/></Style>'."\n";
    $xml .= '<Style ss:ID="Money"><NumberFormat ss:Format="#,##0.00"/></Style>'."\n";
    $xml .= '<Style ss:ID="Date"><NumberFormat ss:Format="yyyy-mm-dd"/></Style>'."\n";
    $xml .= '</Styles>'."\n";

    foreach($sheets as $sheetName => $rows){
        $xml .= '<Worksheet ss:Name="'.htmlspecialchars($sheetName,ENT_QUOTES).'">'."\n";
        $xml .= '<Table>'."\n";
        $isFirst = true;
        foreach($rows as $row){
            $xml .= '<Row>'."\n";
            foreach($row as $ci=>$cell){
                $styleId = '';
                if($isFirst && count($row)>1) $styleId = ' ss:StyleID="Header"';
                elseif($isFirst && count($row)===1) $styleId = ' ss:StyleID="Title"';
                $type = is_numeric($cell) && !preg_match('/^0\d/',(string)$cell) ? 'Number' : 'String';
                $val  = htmlspecialchars((string)$cell, ENT_XML1|ENT_QUOTES, 'UTF-8');
                $xml .= "<Cell{$styleId}><Data ss:Type=\"{$type}\">{$val}</Data></Cell>\n";
            }
            $xml .= '</Row>'."\n";
            $isFirst = false;
        }
        $xml .= '</Table>'."\n";
        $xml .= '</Worksheet>'."\n";
    }
    $xml .= '</Workbook>';

    $filename = 'FVM_Analytics_'.$today_str.'.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    echo $xml;
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // VEHICLES
    if ($action === 'add_vehicle') {
        sbPost('fvm_vehicles', [
            'vehicle_code'      => nextCode('V', 'fvm_vehicles', 'vehicle_code'),
            'plate'             => strtoupper(trim($_POST['plate'])),
            'make'              => trim($_POST['make']),
            'model'             => trim($_POST['model']),
            'year'              => (int)$_POST['year'],
            'vehicle_type'      => $_POST['vehicle_type'] ?? 'Service Vehicle',
            'fuel_type'         => $_POST['fuel_type'] ?? 'Diesel',
            'mileage'           => (int)$_POST['mileage'],
            'fuel_level'        => (int)$_POST['fuel_level'],
            'lto_expiry'        => $_POST['lto_expiry'] ?: null,
            'ins_expiry'        => $_POST['ins_expiry'] ?: null,
            'status'            => 'Active',
            'lat'               => 14.5995, 'lng' => 120.9842,
            'location'          => 'Metro Manila',
        ]);
        header('Location: fvm.php?page=vehicles'); exit;
    }
    if ($action === 'edit_vehicle') {
        sbPatch('fvm_vehicles', [
            'plate'        => strtoupper(trim($_POST['plate'])),
            'make'         => trim($_POST['make']),
            'model'        => trim($_POST['model']),
            'year'         => (int)$_POST['year'],
            'vehicle_type' => $_POST['vehicle_type'] ?? 'Service Vehicle',
            'fuel_type'    => $_POST['fuel_type'] ?? 'Diesel',
            'lto_expiry'   => $_POST['lto_expiry'] ?: null,
            'ins_expiry'   => $_POST['ins_expiry'] ?: null,
            'status'       => $_POST['status'],
        ], $_POST['vehicle_id']);
        header('Location: fvm.php?page=vehicles'); exit;
    }
    if ($action === 'delete_vehicle') {
        sbDelete('fvm_vehicles', $_POST['vehicle_id']);
        header('Location: fvm.php?page=vehicles'); exit;
    }
    if ($action === 'toggle_flag') {
        $vRows = sbGet('fvm_vehicles', ['id' => 'eq.'.$_POST['vehicle_id'], 'select' => 'id,flagged,plate,make,model,vehicle_code']);
        if ($vRows) {
            $vRow = $vRows[0];
            if ($vRow['flagged']) {
                // Unflagging: remove flag, restore Active status
                sbPatch('fvm_vehicles', ['flagged' => false, 'status' => 'Active'], $vRow['id']);
                header('Location: fvm.php?page=vehicles'); exit;
            } else {
                // Flagging: redirect to assessment form first
                header('Location: fvm.php?page=vehicles&flag_assess='.$vRow['id']); exit;
            }
        }
        header('Location: fvm.php?page=vehicles'); exit;
    }
    if ($action === 'save_flag_details') {
        $vid   = $_POST['vehicle_id'];
        $vRows = sbGet('fvm_vehicles', ['id' => 'eq.'.$vid, 'select' => 'id,plate,make,model,vehicle_code']);
        $vRow  = $vRows[0] ?? null;
        if ($vRow) {
            $reason    = trim($_POST['flag_reason'] ?? '');
            $severity  = $_POST['flag_severity'] ?? 'Moderate';
            $items     = $_POST['issue_items'] ?? [];
            $costs     = $_POST['issue_costs'] ?? [];
            $total     = 0;
            $lines     = [];
            foreach ($items as $idx => $item) {
                if (trim($item)) {
                    $cost   = (float)($costs[$idx] ?? 0);
                    $total += $cost;
                    $lines[] = trim($item).'|'.$cost;
                }
            }
            $issueStr = implode(';', $lines);
            $notesParts = ["⚑ Flagged on {$today} — Reason: {$reason}", "Severity: {$severity}"];
            if ($lines) {
                $notesParts[] = 'Issues: '.implode('; ', array_map(fn($l) => str_replace('|', ' = ₱', $l), $lines));
                $notesParts[] = 'Estimated Total: ₱'.number_format($total, 2);
            } else {
                $notesParts[] = 'No cost breakdown yet — assessor must update.';
            }
            $notes = implode("
", $notesParts);

            sbPatch('fvm_vehicles', ['flagged' => true, 'status' => 'In Maintenance'], $vid);
            sbPost('fvm_maintenance', [
                'maint_code'     => nextCode('M', 'fvm_maintenance', 'maint_code'),
                'vehicle_id'     => $vid,
                'maint_type'     => 'Flag Inspection — '.$vRow['plate'],
                'status'         => 'Pending',
                'estimated_cost' => $total,
                'scheduled_date' => $_POST['scheduled_date'] ?? $today,
                'notes'          => $notes,
            ]);
        }
        header('Location: fvm.php?page=operations&tab=maintenance'); exit;
    }
    if ($action === 'update_flag_assessment') {
        $mid   = $_POST['maint_id'];
        $items = $_POST['issue_items'] ?? [];
        $costs = $_POST['issue_costs'] ?? [];
        $total = 0;
        $lines = [];
        foreach ($items as $idx => $item) {
            if (trim($item)) {
                $cost   = (float)($costs[$idx] ?? 0);
                $total += $cost;
                $lines[] = trim($item).'|'.$cost;
            }
        }
        $issueStr = implode(';', $lines);
        $rows  = sbGet('fvm_maintenance', ['id' => 'eq.'.$mid, 'select' => 'notes,vehicle_id']);
        $mrow  = $rows[0] ?? null;
        $existNotes = $mrow['notes'] ?? '';
        // Append updated assessment block
        $assessBlock = "
--- Updated Assessment ".$today." ---
";
        if ($lines) {
            $assessBlock .= 'Issues: '.implode('; ', array_map(fn($l) => str_replace('|', ' = ₱', $l), $lines))."
";
            $assessBlock .= 'Total: ₱'.number_format($total, 2);
        }
        sbPatch('fvm_maintenance', [
            'estimated_cost' => $total,
            'notes'          => $existNotes.$assessBlock,
        ], $mid);
        header('Location: fvm.php?page=operations&tab=maintenance'); exit;
    }

    // DRIVERS
    if ($action === 'add_driver') {
        sbPost('fvm_drivers', [
            'driver_code'    => nextCode('D', 'fvm_drivers', 'driver_code'),
            'full_name'      => trim($_POST['full_name']),
            'license_no'     => trim($_POST['license_no']),
            'license_expiry' => $_POST['license_expiry'],
            'license_type'   => $_POST['license_type'] ?? 'Professional',
            'phone'          => trim($_POST['phone'] ?? ''),
            'email'          => trim($_POST['email'] ?? ''),
            'behavior_score' => (int)($_POST['behavior_score'] ?? 100),
            'status'         => 'Available',
        ]);
        header('Location: fvm.php?page=drivers'); exit;
    }
    if ($action === 'edit_driver') {
        sbPatch('fvm_drivers', [
            'full_name'      => trim($_POST['full_name']),
            'license_no'     => trim($_POST['license_no']),
            'license_expiry' => $_POST['license_expiry'],
            'phone'          => trim($_POST['phone'] ?? ''),
            'behavior_score' => (int)$_POST['behavior_score'],
            'status'         => $_POST['status'],
        ], $_POST['driver_id']);
        header('Location: fvm.php?page=drivers'); exit;
    }
    if ($action === 'delete_driver') {
        sbDelete('fvm_drivers', $_POST['driver_id']);
        header('Location: fvm.php?page=drivers'); exit;
    }

    // TRIPS / DISPATCH
    if ($action === 'dispatch_trip') {
        $driverRows = sbGet('fvm_drivers', ['id' => 'eq.'.$_POST['driver_id'], 'select' => 'id,full_name,license_expiry']);
        $driver = $driverRows[0] ?? null;
        if ($driver && isExpired($driver['license_expiry'])) {
            $_SESSION['dispatch_error'] = "⚠️ {$driver['full_name']}'s license is expired!";
            header('Location: fvm.php?page=dispatch'); exit;
        }
        $dispatchTs = date('Y-m-d H:i:s'); // full timestamp of dispatch action
        $tp=['trip_code'=>nextCode('T','fvm_trips','trip_code'),'vehicle_id'=>$_POST['vehicle_id'],'driver_id'=>$_POST['driver_id'],'origin'=>trim($_POST['origin']),'destination'=>trim($_POST['destination']),'origin_lat'=>!empty($_POST['origin_lat'])?(float)$_POST['origin_lat']:null,'origin_lng'=>!empty($_POST['origin_lng'])?(float)$_POST['origin_lng']:null,'dest_lat'=>!empty($_POST['dest_lat'])?(float)$_POST['dest_lat']:null,'dest_lng'=>!empty($_POST['dest_lng'])?(float)$_POST['dest_lng']:null,'scheduled_date'=>$_POST['date'],'scheduled_time'=>$_POST['scheduled_time']??'08:00','dispatch_timer'=>(int)($_POST['dispatch_timer']??0),'dispatched_at'=>$dispatchTs,'priority'=>$_POST['priority']??'Normal','purpose'=>trim($_POST['purpose']??''),'status'=>'Pending','mileage_km'=>0];
        if(!empty($_POST['eta_minutes']))$tp['eta_minutes']=(int)$_POST['eta_minutes'];
        if(!empty($_POST['route_distance_km']))$tp['route_distance_km']=(float)$_POST['route_distance_km'];
        if(!empty($_POST['route_suggestion']))$tp['route_suggestion']=trim($_POST['route_suggestion']);
        $tripOk=sbPost('fvm_trips',$tp);
        if(!$tripOk){$_SESSION['dispatch_error']='⚠️ Trip insert failed. Run Migration SQL first.';header('Location: fvm.php?page=dispatch');exit;}
        sbPatch('fvm_drivers',['status'=>'On Trip','assigned_vehicle_id'=>$_POST['vehicle_id']],$_POST['driver_id']);
        $vr=sbGet('fvm_vehicles',['id'=>'eq.'.$_POST['vehicle_id'],'select'=>'plate']);$pl=$vr[0]['plate']??'?';
        $nt=sbGet('fvm_trips',['driver_id'=>'eq.'.$_POST['driver_id'],'order'=>'created_at.desc','limit'=>'1','select'=>'id,trip_code']);
        $nid=$nt[0]['id']??null;$nc=$nt[0]['trip_code']??'';
        $es=!empty($_POST['eta_minutes'])?' ETA:'.(int)$_POST['eta_minutes'].'min.':'';
        @sbPost('fvm_notifications',['driver_id'=>$_POST['driver_id'],'trip_id'=>$nid,'type'=>'trip_assigned','title'=>'🚀 New Trip Assigned','message'=>$nc.'·'.trim($_POST['origin']).'→'.trim($_POST['destination']).' on '.($_POST['date']??$today).' at '.($_POST['scheduled_time']??'—').'. Vehicle:'.$pl.'.'.$es,'is_read'=>false,'created_at'=>date('c')]);
        header('Location: fvm.php?page=dispatch'); exit;
    }
    if ($action === 'move_trip') {
        $map = ['pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed'];
        $newStatus = $map[$_POST['newStatus']] ?? 'Pending';
        sbPatch('fvm_trips', ['status' => $newStatus], $_POST['trip_id']);
        $tripRow = sbGet('fvm_trips', ['id' => 'eq.'.$_POST['trip_id'], 'select' => 'driver_id,vehicle_id']);
        if ($tripRow && !empty($tripRow[0]['driver_id'])) {
            $dId = $tripRow[0]['driver_id'];
            $vId = $tripRow[0]['vehicle_id'];
            if ($newStatus === 'Completed') {
                sb('PATCH', 'fvm_drivers', ['status' => 'Available'], ['id' => 'eq.'.$dId]);
                sbPatch('fvm_vehicles', ['status' => 'Active'], $vId);
            } elseif (in_array($newStatus, ['Pending', 'In Progress'])) {
                sb('PATCH', 'fvm_drivers', ['status' => 'On Trip'], ['id' => 'eq.'.$dId]);
            }
        }
        header('Location: fvm.php?page=dispatch'); exit;
    }
    if ($action === 'complete_trip') {
        sbPatch('fvm_trips', ['status' => 'Completed', 'mileage_km' => (int)$_POST['mileage_km']], $_POST['trip_id']);
        $tripRow = sbGet('fvm_trips', ['id' => 'eq.'.$_POST['trip_id'], 'select' => 'driver_id,vehicle_id']);
        if ($tripRow && !empty($tripRow[0]['driver_id'])) {
            sb('PATCH', 'fvm_drivers', ['status' => 'Available'], ['id' => 'eq.'.$tripRow[0]['driver_id']]);
            if (!empty($_POST['mileage_km'])) sbPatch('fvm_vehicles', ['mileage' => (int)$_POST['mileage_km']], $tripRow[0]['vehicle_id']);
        }
        header('Location: fvm.php?page=dispatch'); exit;
    }

    // INSPECTION
if ($action === 'submit_inspection') {
        sbPost('fvm_inspections', [ /* existing fields */ ]);
        sbPatch('fvm_vehicles', [ /* existing fields */ ], $_POST['vehicle_id']);
        
        // ── Log inspection as expense if result is not OK ──
        if ($_POST['result'] !== 'OK') {
            sbPost('fvm_expenses', [
                'expense_code' => nextCode('E', 'fvm_expenses', 'expense_code'),
                'vehicle_id'   => $_POST['vehicle_id'],
                'expense_type' => 'Maintenance',
                'amount'       => 0, // no cost yet — will be updated when maintenance is approved
                'expense_date' => $today,
                'approved_by'  => 'Auto — Health Check',
                'notes'        => mb_substr('Inspection result: '.$_POST['result'].'. '.trim($_POST['notes'] ?? ''), 0, 200),
            ]);
        }
        header('Location: fvm.php?page=operations&tab=health'); exit;
    }
    if ($action === 'log_incident') {
        sbPost('fvm_incidents', [
            'incident_code' => nextCode('I', 'fvm_incidents', 'incident_code'),
            'vehicle_id'    => $_POST['vehicle_id'],
            'driver_id'     => $_POST['driver_id'] ?: null,
            'incident_type' => $_POST['incident_type'],
            'severity'      => $_POST['severity'] ?? 'Minor',
            'incident_date' => $_POST['incident_date'],
            'description'   => trim($_POST['description']),
            'status'        => 'Open',
        ]);
        header('Location: fvm.php?page=operations&tab=incidents'); exit;
    }

    // ── INCIDENT REVIEW / DAMAGE ASSESSMENT ─────────────────────────────────
    if ($action === 'review_incident') {
        $reviewNotes = trim($_POST['review_notes']??'');
        $reviewedBy  = trim($_POST['reviewed_by']??'Admin');
        $resolvedText = $reviewedBy ? '[Reviewed by: '.$reviewedBy.'] '.$reviewNotes : $reviewNotes;
        sbPatch('fvm_incidents', ['status' => 'In Progress', 'resolution_notes' => $resolvedText], $_POST['incident_id']);
        header('Location: fvm.php?page=operations&tab=incidents&action=view&id='.$_POST['incident_id']); exit;
    }
    if ($action === 'save_damage_assessment') {
        $iid = $_POST['incident_id'];
        $items = $_POST['damage_items'] ?? [];
        $costs = $_POST['damage_costs'] ?? [];
        $total = 0;
        $lines = [];
        foreach($items as $idx=>$item) {
            if(trim($item)) {
                $cost = (float)($costs[$idx]??0);
                $total += $cost;
                $lines[] = trim($item).'|'.$cost;
            }
        }
        $dmgItemsStr = implode(';', $lines);
        $dmgSummary  = 'Damage Assessment by Maintenance: '.implode('; ', array_map(fn($l)=>str_replace('|',' = ₱',$l), $lines)).'. Total: ₱'.number_format($total,2);
        $existing  = sbGet('fvm_incidents', ['id'=>'eq.'.$iid,'select'=>'resolution_notes']);
        $prevNotes = $existing[0]['resolution_notes'] ?? '';
        // Keep any prior reviewer notes, append damage summary
        $combined  = $prevNotes ? $prevNotes."\n".$dmgSummary : $dmgSummary;
        sbPatch('fvm_incidents', [
            'damage_items'     => $dmgItemsStr,   // store as structured field
            'damage_total'     => $total,          // numeric — used by request_budget
            'resolution_notes' => $combined,
            'status'           => 'Assessed',      // advance to Assessed so budget button appears
        ], $iid);
        header('Location: fvm.php?page=operations&tab=incidents&action=view&id='.$iid); exit;
    }
    if ($action === 'request_budget') {
        $iid = $_POST['incident_id'];
        $rows = sbGet('fvm_incidents', ['id'=>'eq.'.$iid,'select'=>'*']);
        $inc  = $rows[0] ?? null;
        if ($inc) {
            $damageCost = (float)($inc['damage_total'] ?? 0);
            $maintNotes = 'Incident '.$inc['incident_code'].' — '.$inc['incident_type']
                        . '. Damage total: ₱'.number_format($damageCost,2).'.'
                        . ($inc['resolution_notes'] ? ' Notes: '.$inc['resolution_notes'] : '');
            $ok = sbPost('fvm_maintenance', [
                'maint_code'     => nextCode('M', 'fvm_maintenance', 'maint_code'),
                'vehicle_id'     => $inc['vehicle_id'],
                'maint_type'     => 'Incident Repair — '.$inc['incident_type'],
                'estimated_cost' => $damageCost,   // ← real assessed damage total
                'scheduled_date' => $today,
                'notes'          => $maintNotes,
                'status'         => 'Pending',
            ]);
            if($ok) sbPatch('fvm_incidents', ['status' => 'Budget Pending'], $iid);
        }
        header('Location: fvm.php?page=operations&tab=incidents'); exit;
    }
    if ($action === 'close_incident') {
        $iid  = $_POST['incident_id'];
        $irows = sbGet('fvm_incidents', ['id' => 'eq.'.$iid, 'select' => '*']);
        $inc   = $irows[0] ?? null;
        sbPatch('fvm_incidents', ['status' => 'Closed'], $iid);
        // Auto-log damage cost as expense if a damage total exists AND the incident
        // was NOT already routed through budget approval (that path logs its own expense).
        if ($inc && !empty($inc['damage_total']) && (float)$inc['damage_total'] > 0
            && ($inc['status'] ?? '') !== 'Budget Pending') {
            sbPost('fvm_expenses', [
                'expense_code' => nextCode('E', 'fvm_expenses', 'expense_code'),
                'vehicle_id'   => $inc['vehicle_id'],
                'expense_type' => 'Incident',
                'amount'       => (float)$inc['damage_total'],
                'expense_date' => $inc['incident_date'] ?? $today,
                'approved_by'  => 'Auto — Incident '.$inc['incident_code'],
                'notes'        => mb_substr(
                    ($inc['incident_code'] ?? '') . ' — ' . ($inc['incident_type'] ?? '') .
                    '. Severity: ' . ($inc['severity'] ?? 'Minor') . '. ' .
                    ($inc['resolution_notes'] ?? ''), 0, 400),
            ]);
        }
        header('Location: fvm.php?page=operations&tab=incidents'); exit;
    }
    // MAINTENANCE
    if ($action === 'request_maintenance') {
        sbPost('fvm_maintenance', [
            'maint_code'     => nextCode('M', 'fvm_maintenance', 'maint_code'),
            'vehicle_id'     => $_POST['vehicle_id'],
            'maint_type'     => $_POST['maint_type'],
            'estimated_cost' => (float)$_POST['estimated_cost'],
            'scheduled_date' => $_POST['scheduled_date'],
            'notes'          => trim($_POST['notes'] ?? ''),
            'status'         => 'Pending',
        ]);
        header('Location: fvm.php?page=operations&tab=maintenance'); exit;
    }

    
if ($action === 'approve_maintenance') {
        $rows = sbGet('fvm_maintenance', ['id' => 'eq.'.$_POST['maint_id'], 'select' => '*']);
        $mrow = $rows[0] ?? null;
        if (!$mrow) {
            $_SESSION['dispatch_error'] = '⚠️ Maintenance row not found for ID: '.$_POST['maint_id'];
            header('Location: fvm.php?page=operations&tab=maintenance'); exit;
        }
        sbPatch('fvm_maintenance', ['status' => 'Approved'], $_POST['maint_id']);
        $expOk = sbPost('fvm_expenses', [
            'expense_code' => nextCode('E', 'fvm_expenses', 'expense_code'),
            'vehicle_id'   => $mrow['vehicle_id'],
            'expense_type' => 'Maintenance',
            'amount'       => (float)($mrow['estimated_cost'] ?? 0),
            'expense_date' => $today,
            'approved_by'  => 'Finance Officer',
            'notes'        => mb_substr(($mrow['maint_code'] ?? '') . ' — ' . ($mrow['maint_type'] ?? '') . '. ' . ($mrow['notes'] ?? ''), 0, 400),
        ]);
        if (!$expOk) {
            $_SESSION['dispatch_error'] = '⚠️ Maintenance approved but expense log FAILED. Check fvm_expenses schema.';
        }
        sbPatch('fvm_vehicles', ['status' => 'In Maintenance', 'maintenance_alert' => true], $mrow['vehicle_id']);
        if (!empty($mrow['notes']) && preg_match('/Incident\s+(I\d+)/', $mrow['notes'], $m)) {
            $incRows = sbGet('fvm_incidents', ['incident_code' => 'eq.'.$m[1], 'select' => 'id,status,resolution_notes']);
            if (!empty($incRows) && $incRows[0]['status'] === 'Budget Pending') {
                sbPatch('fvm_incidents', [
                    'status'           => 'Closed',
                    'resolution_notes' => mb_substr(($incRows[0]['resolution_notes'] ?? '') . "\nBudget approved & maintenance scheduled on " . $today . '. Expense logged.', 0, 800),
                ], $incRows[0]['id']);
            }
        }
        header('Location: fvm.php?page=operations&tab=maintenance'); exit;
    }
       if ($action === 'reject_maintenance') {
        sbPatch('fvm_maintenance', ['status' => 'Rejected'], $_POST['maint_id']);
        header('Location: fvm.php?page=operations&tab=maintenance'); exit;
    }

    if ($action === 'add_expense') {
        sbPost('fvm_expenses', [
            'expense_code' => nextCode('E', 'fvm_expenses', 'expense_code'),
            'vehicle_id'   => $_POST['vehicle_id'],
            'expense_type' => $_POST['expense_type'],
            'amount'       => (float)$_POST['amount'],
            'expense_date' => $_POST['expense_date'],
            'approved_by'  => trim($_POST['approved_by'] ?? 'Finance Officer'),
            'notes'        => trim($_POST['notes'] ?? ''),
        ]);
        header('Location: fvm.php?page=expenses'); exit;
    }

    if ($action === 'delete_expense') {
        sbDelete('fvm_expenses', $_POST['expense_id']);
        header('Location: fvm.php?page=expenses'); exit;
    }
    // FUEL LOGS
if ($action === 'add_fuel_log') {
        $liters = (float)$_POST['liters'];
        $ppl    = (float)$_POST['price_per_liter'];
        $totalCost = $liters * $ppl;
        sbPost('fvm_fuel_logs', [
            'log_code'        => nextCode('F', 'fvm_fuel_logs', 'log_code'),
            'vehicle_id'      => $_POST['vehicle_id'],
            'driver_id'       => $_POST['driver_id'] ?: null,
            'log_date'        => $_POST['log_date'],
            'odometer_km'     => (int)$_POST['odometer_km'],
            'liters'          => $liters,
            'price_per_liter' => $ppl,
            'station'         => trim($_POST['station'] ?? ''),
            'notes'           => trim($_POST['notes'] ?? ''),
        ]);
        // ── Also log as expense so Expense Tracker picks it up ──
        sbPost('fvm_expenses', [
            'expense_code' => nextCode('E', 'fvm_expenses', 'expense_code'),
            'vehicle_id'   => $_POST['vehicle_id'],
            'expense_type' => 'Fuel',
            'amount'       => $totalCost,
            'expense_date' => $_POST['log_date'],
            'approved_by'  => 'Auto — Fuel Log',
            'notes'        => mb_substr(($liters).'L @ ₱'.$ppl.'/L · '.trim($_POST['station'] ?? ''), 0, 200),
        ]);
        sbPatch('fvm_vehicles', ['mileage' => (int)$_POST['odometer_km']], $_POST['vehicle_id']);
        header('Location: fvm.php?page=operations&tab=fuel'); exit;
    }
    if ($action === 'delete_fuel_log') {
        sbDelete('fvm_fuel_logs', $_POST['log_id']);
        header('Location: fvm.php?page=operations&tab=fuel'); exit;
    }

    // COMPLIANCE
    if ($action === 'add_compliance') {
        $due  = strtotime($_POST['due_date']);
        $diff = ($due - strtotime($today)) / 86400;
        $cs   = $diff < 0 ? 'Expired' : ($diff < 60 ? 'Due Soon' : 'Compliant');
        sbPost('fvm_compliance', [
            'vehicle_id'      => $_POST['vehicle_id'],
            'compliance_type' => $_POST['compliance_type'],
            'due_date'        => $_POST['due_date'],
            'status'          => $cs,
            'notes'           => trim($_POST['notes'] ?? ''),
        ]);
        header('Location: fvm.php?page=compliance'); exit;
    }
    if ($action === 'delete_compliance') {
        sbDelete('fvm_compliance', $_POST['compliance_id']);
        header('Location: fvm.php?page=compliance'); exit;
    }

    // REMINDERS
    if ($action === 'add_reminder') {
        sbPost('fvm_reminders', [
            'vehicle_id'    => $_POST['vehicle_id'],
            'reminder_type' => $_POST['reminder_type'],
            'due_date'      => $_POST['due_date'],
            'notes'         => trim($_POST['notes'] ?? ''),
        ]);
        header('Location: fvm.php?page=reminders'); exit;
    }
    if ($action === 'dismiss_reminder') {
        sbPatch('fvm_reminders', ['dismissed' => true, 'dismissed_at' => date('c')], $_POST['reminder_id']);
        header('Location: fvm.php?page=reminders'); exit;
    }
    if ($action === 'delete_reminder') {
        sbDelete('fvm_reminders', $_POST['reminder_id']);
        header('Location: fvm.php?page=reminders'); exit;
    }
}

// ── GET all vehicle positions for live fleet map ──────────────────────────────
if (isset($_GET['get_all_positions'])) {
    header('Content-Type: application/json');
    $vrows = sbGet('fvm_vehicles', ['select' => 'id,plate,lat,lng,status,location,updated_at', 'order' => 'vehicle_code.asc']);
    // Override with latest GPS ping from trip_tracking for active vehicles
    // Get the most recent tracking row per vehicle_id (desc order = newest first)
    $latestTracking = sbGet('fvm_trip_tracking', [
        'select' => 'vehicle_id,lat,lng,updated_at',
        'order'  => 'updated_at.desc',
        'limit'  => '50'
    ]);
    // Build lookup: vehicle_id -> most recent tracking row
    $trackLookup = [];
    foreach ($latestTracking as $tr) {
        if (!isset($trackLookup[$tr['vehicle_id']])) {
            $trackLookup[$tr['vehicle_id']] = $tr;
        }
    }
    // Always prefer live GPS from trip_tracking — no timestamp comparison needed
    // (vehicle updated_at gets bumped by the same ping, making comparisons unreliable)
    foreach ($vrows as &$v) {
        if (isset($trackLookup[$v['id']])) {
            $tk = $trackLookup[$v['id']];
            $v['lat']      = $tk['lat'];
            $v['lng']      = $tk['lng'];
            $v['location'] = 'GPS Active';
            $v['track_ts'] = $tk['updated_at']; // pass timestamp to JS for freshness check
        }
    }
    unset($v);
    echo json_encode(['ok' => true, 'vehicles' => $vrows]);
    exit;
}

// ── Fetch all data from Supabase ──────────────────────────────────────────────
$vehicles    = sbGet('fvm_vehicles',    ['order' => 'vehicle_code.asc']);
$drivers     = sbGet('fvm_drivers',     ['order' => 'driver_code.asc']);
$trips       = sbGet('fvm_trips',       ['order' => 'scheduled_date.desc']);
$maintenance = sbGet('fvm_maintenance', ['order' => 'scheduled_date.desc']);

// ── Re-derive driver status from live trips (fixes stale DB status column) ────
$_activeTripByDriver = [];
foreach ($trips as $_t) {
    if (in_array($_t['status'], ['Pending', 'In Progress']) && !empty($_t['driver_id'])) {
        $existing = $_activeTripByDriver[$_t['driver_id']] ?? null;
        if ($existing === null || $_t['status'] === 'In Progress') {
            $_activeTripByDriver[$_t['driver_id']] = $_t['status'];
        }
    }
}
foreach ($drivers as &$_drv) {
    if (isset($_activeTripByDriver[$_drv['id']])) {
        $_drv['status'] = 'On Trip';
    } elseif ($_drv['status'] === 'On Trip') {
        $_drv['status'] = 'Available';
        sb('PATCH', 'fvm_drivers', ['status' => 'Available'], ['id' => 'eq.'.$_drv['id']]);
    }
}
unset($_drv);

$expenses    = sbGet('fvm_expenses',    ['order' => 'expense_date.desc']);
$fuel_logs   = sbGet('fvm_fuel_logs',   ['order' => 'log_date.desc']);
$incidents   = sbGet('fvm_incidents',   ['order' => 'incident_date.desc']);
// Enrich incidents with driver/vehicle names for notification badge
$openIncidents = array_filter($incidents, fn($i) => in_array($i['status']??'Open', ['Open','In Progress','Assessed','Budget Pending']));
$compliance  = sbGet('fvm_compliance',  ['order' => 'due_date.asc']);
$reminders   = sbGet('fvm_reminders',   ['dismissed' => 'eq.false', 'order' => 'due_date.asc']);
$inspections = sbGet('fvm_inspections', ['order' => 'inspection_date.desc', 'limit' => 50]);

// Trip tracking — load once for all trips, keyed by trip_id
$trackingData = [];
$trackingJson = '{}';
if ($page === 'dispatch') {
    // Order descending so the FIRST row per trip_id is the most recent ping
    $allTracking = sbGet('fvm_trip_tracking', ['select' => 'trip_id,lat,lng,speed_kmh,heading,accuracy,movement_status,updated_at','order'=>'updated_at.desc','limit'=>'200']);
    foreach ($allTracking as $tr) {
        // First write wins because order is desc — most recent ping per trip
        if (!isset($trackingData[$tr['trip_id']])) {
            $trackingData[$tr['trip_id']] = $tr;
        }
    }
    $trackingJson = json_encode($trackingData);
}

// ── Computed counts ───────────────────────────────────────────────────────────
$alertCount      = count(array_filter($vehicles, fn($v) => $v['flagged']))
                 + count(array_filter($compliance, fn($c) => $c['status'] === 'Expired'))
                 + count(array_filter($drivers, fn($d) => isExpired($d['license_expiry'])))
                 + count(array_filter($incidents, fn($i) => $i['status'] === 'Open'));
$pendingMaint    = count(array_filter($maintenance, fn($m) => $m['status'] === 'Pending'));
$activeReminders = count(array_filter($reminders, fn($r) => !$r['dismissed'] && (strtotime($r['due_date']) - strtotime($today)) / 86400 <= 30));

// ── Chart data ────────────────────────────────────────────────────────────────
$monthlyData = [];
foreach ($expenses as $exp) { $m = substr($exp['expense_date'], 0, 7); $monthlyData[$m] = ($monthlyData[$m] ?? 0) + $exp['amount']; }
ksort($monthlyData);
$chartLabels = json_encode(array_keys($monthlyData));
$chartValues = json_encode(array_values($monthlyData));
$donutData   = json_encode([
    count(array_filter($vehicles, fn($v) => $v['status'] === 'Active')),
    count(array_filter($vehicles, fn($v) => $v['status'] === 'In Maintenance')),
    count(array_filter($vehicles, fn($v) => $v['status'] === 'Inactive')),
]);
$fuelData    = json_encode(array_map(fn($v) => $v['fuel_level'], $vehicles));
$fuelLabels  = json_encode(array_map(fn($v) => $v['plate'], $vehicles));

// ── GPS data ──────────────────────────────────────────────────────────────────
// Build a lookup of latest GPS positions from trip_tracking for the map page
$mapTrackLookup = [];
$activeVehicleIds = []; // vehicles with active (In Progress/Pending) trips = driver logged in / on duty
if ($page === 'map') {
    $mapTracking = sbGet('fvm_trip_tracking', [
        'select' => 'vehicle_id,lat,lng,updated_at',
        'order'  => 'updated_at.desc',
        'limit'  => '50'
    ]);
    foreach ($mapTracking as $tr) {
        if (!isset($mapTrackLookup[$tr['vehicle_id']])) {
            $mapTrackLookup[$tr['vehicle_id']] = $tr;
        }
    }
    // Get vehicles actively used in Pending or In Progress trips (driver is "on the system")
    $activeTrips = sbGet('fvm_trips', [
        'select' => 'vehicle_id,driver_id,status',
        'status' => 'in.(Pending,In Progress)',
    ]);
    foreach ($activeTrips as $at) {
        $activeVehicleIds[] = $at['vehicle_id'];
    }
}
$vehicleGeoJSON = json_encode(array_map(function($v) use ($mapTrackLookup, $activeVehicleIds) {
    $lat = (float)($v['lat'] ?? 14.5995);
    $lng = (float)($v['lng'] ?? 120.9842);
    $loc = $v['location'] ?? 'Metro Manila';
    $driverActive = in_array($v['id'], $activeVehicleIds); // driver is logged in / assigned
    // Always use live GPS from trip_tracking when available (no timestamp guard needed)
    if (isset($mapTrackLookup[$v['id']])) {
        $tk = $mapTrackLookup[$v['id']];
        $lat = (float)$tk['lat'];
        $lng = (float)$tk['lng'];
        $loc = 'GPS Active';
    }
    return [
        'id'           => $v['id'],
        'plate'        => $v['plate'],
        'make'         => $v['make'],
        'model'        => $v['model'],
        'status'       => $v['status'],
        'fuelLevel'    => $v['fuel_level'],
        'mileage'      => $v['mileage'],
        'location'     => $loc,
        'lat'          => $lat,
        'lng'          => $lng,
        'flagged'      => $v['flagged'],
        'driverActive' => $driverActive, // true = driver is logged in & on duty
    ];
}, $vehicles));

// ── Page titles ───────────────────────────────────────────────────────────────
$pageTitles = [
    'incidents'  => ['Incident Manager',         'Incident & Accident Reports',   'Driver-submitted reports · Damage assessment · Budget approval'],
    'dashboard'  => ['Fleet Overview',          'Fleet Operations Center',   'Live monitoring · Real-time analytics · System alerts'],
    'map'        => ['Live GPS Tracking',        'Fleet Tracking Map',        'Real-time vehicle locations · Route monitoring'],
    'vehicles'   => ['Vehicle Registry',         'Vehicle Management',        'Add · edit · manage all fleet vehicles'],
    'dispatch'   => ['Dispatch Board',           'Trip Management',           'Drag & drop scheduling · Priority queues · Live status'],
    'inspection' => ['Daily Health Check',       'Inspection & Incidents',    'Walk-around checks · Fuel & mileage · Incident logging'],
    'maintenance'=> ['Maintenance Requests',     'Maintenance Management',    'Budget approval · Fund disbursement · Expense logging'],
    'drivers'    => ['Drivers',                  'Driver Management',         'License tracking · Behavior scoring · QR ID cards'],
    'compliance' => ['Compliance Logs',          'Compliance Calendar',       'LTO · Insurance · Emissions · Audit trails'],
    'analytics'  => ['Analytics & Reports',      'Performance Analytics',     'Monthly costs · Vehicle performance · Trends'],
    'fuel'       => ['Fuel Log',                 'Fuel & Odometer Records',   'Per-vehicle fill-up · Odometer tracking · Cost/km'],
    'expenses'   => ['Expense Tracker',          'Expense Management',        'Filter by vehicle · type · date range'],
    'reminders'  => ['Reminders',                'Maintenance Reminders',     'LTO · Insurance · Oil change · PMS · Custom alerts'],
];
[$ptitle, $peyebrow, $psub] = $pageTitles[$page] ?? $pageTitles['dashboard'];

// ── Dispatch error from session ───────────────────────────────────────────────
$dispatchError = $_SESSION['dispatch_error'] ?? null;
unset($_SESSION['dispatch_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FVM System – <?= e($ptitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Outfit',sans-serif;background:#f6faf7;color:#0d150e;display:flex;min-height:100vh;}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:#a5d6a7;border-radius:3px;}
input,select,textarea{font-family:'Outfit',sans-serif;}
input:focus,select:focus,textarea:focus{outline:none;border-color:#4caf50!important;box-shadow:0 0 0 3px #4caf5022;}
button{font-family:'Outfit',sans-serif;cursor:pointer;}button:focus{outline:none;}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}
.page-anim{animation:fadeUp 0.3s ease both;}
.live-dot{width:8px;height:8px;background:#22c55e;border-radius:50%;display:inline-block;animation:pulse 2s infinite;margin-right:5px;}
.sidebar{
  width:260px;
  background:linear-gradient(180deg,#0A1F0D 0%,#1A2E1D 50%,#1B5E20 100%);
  border-right:1px solid rgba(46,125,50,0.25);
  display:flex;flex-direction:column;
  position:fixed;top:0;left:0;bottom:0;z-index:1000;
  overflow-y:auto;overflow-x:hidden;
  transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
  box-shadow:4px 0 20px rgba(0,0,0,0.4);
}
.sidebar.collapsed{transform:translateX(-100%);}
.sidebar::-webkit-scrollbar{width:4px;background:transparent;}
.sidebar::-webkit-scrollbar-track{background:rgba(0,0,0,.15);border-radius:10px;}
.sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:10px;}
.sidebar::-webkit-scrollbar-thumb:hover{background:rgba(76,175,80,.4);}
.sidebar-logo{
  padding:0 20px;
  min-height:58px;
  border-bottom:1px solid rgba(46,125,50,0.25);
  position:relative;overflow:hidden;
  display:flex;flex-direction:column;justify-content:center;
}
.sidebar-logo::before{
  content:'';position:absolute;top:-50%;right:-50%;width:200%;height:200%;
  background:radial-gradient(circle,rgba(76,175,80,0.1) 0%,transparent 70%);
  animation:sidebarRotate 20s linear infinite;
}
@keyframes sidebarRotate{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
.logo-icon{
  width:38px;height:38px;
  background:linear-gradient(135deg,#256427,#388e3c);
  border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:18px;box-shadow:0 4px 12px rgba(46,125,50,0.30);flex-shrink:0;
  animation:logoFloat 3s ease-in-out infinite;
}
@keyframes logoFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}
.logo-text{font-family:'DM Serif Display',serif;font-size:17px;color:#fff;letter-spacing:-0.2px;}
.logo-sub{font-size:9px;color:rgba(255,255,255,0.30);letter-spacing:1px;text-transform:uppercase;}
/* System status pill - sits below logo divider */
.sb-status{
  margin:10px 12px 0;
  background:rgba(255,255,255,0.08);
  border-radius:10px;padding:7px 12px;
  border:1px solid rgba(76,175,80,0.35);
  display:flex;align-items:center;gap:8px;
  font-size:11px;color:rgba(255,255,255,0.75);
  animation:statusGlow 2s ease-in-out infinite;
  position:relative;z-index:1;flex-shrink:0;
}
@keyframes statusGlow{
  0%,100%{box-shadow:0 0 12px rgba(76,175,80,.2);}
  50%{box-shadow:0 0 20px rgba(76,175,80,.4);}
}
.sb-status-dot{width:7px;height:7px;background:#66BB6A;border-radius:50%;animation:pulse 2s infinite;box-shadow:0 0 6px #66BB6A;flex-shrink:0;}
.nav-section{
  font-size:9px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;
  color:rgba(255,255,255,0.28);padding:0 10px;margin:16px 0 6px;
  display:flex;align-items:center;gap:6px;
  position:relative;
}
.nav-section::before{content:'';width:7px;height:7px;background:#388E3C;border-radius:50%;box-shadow:0 0 10px #388E3C;animation:pulse 2s infinite;flex-shrink:0;}
.nav-section::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,rgba(76,175,80,0.3),transparent);}
.nav-item{
  display:flex;align-items:center;gap:10px;
  padding:11px 14px;border-radius:12px;
  margin-bottom:2px;
  color:rgba(255,255,255,0.55);font-size:13.5px;font-weight:500;
  text-decoration:none;
  transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
  border:1px solid transparent;
  border-left:3px solid transparent;
  margin-left:10px;margin-right:10px;
  position:relative;overflow:hidden;
}
.nav-item::before{
  content:'';position:absolute;left:0;top:0;height:100%;width:3px;
  background:linear-gradient(180deg,#388E3C,#81c784);
  transform:scaleY(0);transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
}
.nav-item:hover{
  background:rgba(46,125,50,0.15);color:rgba(255,255,255,0.90);
  border-color:rgba(46,125,50,0.25);transform:translateX(4px);
  box-shadow:0 4px 16px rgba(46,125,50,0.2);
}
.nav-item:hover::before{transform:scaleY(1);}
.nav-item.active{
  background:rgba(76,175,80,0.2);border-left-color:#81c784;color:#81c784;
  border-color:rgba(46,125,50,0.3);
  box-shadow:0 4px 14px rgba(76,175,80,0.25);font-weight:600;
}
.nav-item.active::before{transform:scaleY(1);}
.nav-icon-wrap{
  width:32px;height:32px;background:rgba(76,175,80,0.15);border-radius:9px;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;flex-shrink:0;transition:all 0.25s;
}
.nav-item:hover .nav-icon-wrap{background:rgba(76,175,80,0.25);transform:scale(1.1) rotate(5deg);box-shadow:0 3px 10px rgba(76,175,80,0.3);}
.nav-item.active .nav-icon-wrap{background:rgba(76,175,80,0.3);box-shadow:0 0 12px rgba(76,175,80,0.4);}
.nav-label-wrap{display:flex;flex-direction:column;gap:1px;flex:1;}
.nav-label-main{font-size:13px;font-weight:500;line-height:1.2;}
.nav-label-sub{font-size:10px;opacity:0.6;font-weight:400;}
.nav-badge{margin-left:auto;color:#fff;font-size:9px;font-weight:700;border-radius:10px;padding:2px 7px;animation:badgePulse 2s infinite;}
@keyframes badgePulse{0%,100%{transform:scale(1);}50%{transform:scale(1.08);}}
.sidebar-footer{
  margin:12px;padding:0;
  background:rgba(255,255,255,0.08);
  border:1px solid rgba(46,125,50,0.25);
  border-radius:14px;overflow:hidden;
  box-shadow:0 4px 20px rgba(0,0,0,0.2);
  position:relative;
}
.sidebar-footer::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,#2E7D32,#388E3C,#81C784,#2E7D32);
  background-size:200% 100%;animation:gradientShift 3s linear infinite;
}
@keyframes gradientShift{0%{background-position:0% 50%}100%{background-position:200% 50%}}
.sidebar-footer-inner{padding:12px 14px;font-size:11px;color:rgba(255,255,255,0.45);}
.sidebar-footer-bottom{padding:8px 14px 10px;border-top:1px solid rgba(46,125,50,0.15);display:flex;justify-content:space-between;align-items:center;background:rgba(0,0,0,0.15);}
.status-online-pill{display:flex;align-items:center;gap:5px;font-size:10px;color:#66BB6A;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.status-online-pill::before{content:'';width:6px;height:6px;background:#66BB6A;border-radius:50%;animation:pulse-ring 2s infinite;}
@keyframes pulse-ring{0%{box-shadow:0 0 0 0 rgba(76,175,80,.7)}70%{box-shadow:0 0 0 8px rgba(76,175,80,0)}100%{box-shadow:0 0 0 0 rgba(76,175,80,0)}}

/* ─── TOP NAVBAR ─── */
.top-navbar{
  position:fixed;top:0;
  left:260px;right:0;
  height:58px;
  background:#fff;
  border-bottom:1px solid rgba(46,125,50,0.12);
  z-index:998;
  transition:left 0.3s cubic-bezier(0.4,0,0.2,1);
  box-shadow:0 2px 12px rgba(46,125,50,0.08);
  display:flex;align-items:center;
  padding:0 28px 0 16px;
  justify-content:space-between;
}
.top-navbar.expanded{left:0;}
.topnav-left{display:flex;align-items:center;gap:12px;}
.topnav-right{display:flex;align-items:center;gap:8px;}

/* Toggle button — sits inside topbar, no overlap issues */
.toggle-btn{
  background:transparent;border:1px solid rgba(46,125,50,0.15);
  width:38px;height:38px;border-radius:10px;
  color:#5D6F62;font-size:20px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:all 0.25s;flex-shrink:0;
}
.toggle-btn:hover{background:#E8F5E9;color:#2E7D32;border-color:#388E3C;}
.toggle-btn:active{transform:scale(0.95);}

/* Page breadcrumb in topbar */
.topnav-breadcrumb{font-size:14px;color:#5D6F62;font-weight:500;}
.topnav-breadcrumb strong{color:#0d150e;font-weight:700;}

/* Clock */
.topnav-clock{
  display:flex;align-items:center;gap:6px;
  padding:6px 12px;color:#5D6F62;
  font-weight:500;font-size:13px;
  border-radius:8px;
}
.topnav-clock-sep{color:#aaa;font-size:11px;}

/* Icon button (notifications, alerts) */
.topnav-icon-btn{
  background:transparent;border:none;
  width:40px;height:40px;border-radius:10px;
  color:#5D6F62;font-size:18px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:all 0.25s;position:relative;
}
.topnav-icon-btn:hover{background:#E8F5E9;color:#2E7D32;}
.topnav-icon-btn:active{transform:scale(0.95);}
.topnav-icon-btn .tnb-badge{
  position:absolute;top:6px;right:6px;
  min-width:16px;height:16px;padding:0 4px;
  background:#ef4444;color:#fff;
  border-radius:8px;font-size:9px;font-weight:700;
  display:flex;align-items:center;justify-content:center;
  border:2px solid #fff;animation:badgePulse 2s infinite;
}

/* Alerts dropdown panel */
.alerts-dropdown{
  position:absolute;top:calc(100% + 8px);right:0;
  width:320px;background:#fff;border-radius:14px;
  border:1px solid rgba(46,125,50,0.15);
  box-shadow:0 12px 40px rgba(0,0,0,0.14);
  z-index:2000;display:none;overflow:hidden;
}
.alerts-dropdown.open{display:block;}
.alerts-dropdown-header{
  padding:14px 16px 10px;border-bottom:1px solid #edf5ef;
  display:flex;justify-content:space-between;align-items:center;
}
.alerts-dropdown-title{font-size:13px;font-weight:700;color:#0d150e;}
.alerts-dropdown-body{max-height:300px;overflow-y:auto;padding:8px 0;}
.alerts-dropdown-item{
  padding:10px 16px;font-size:12px;border-bottom:1px solid #f6faf7;
  display:flex;align-items:flex-start;gap:8px;
}
.alerts-dropdown-item:last-child{border-bottom:none;}
.alerts-dropdown-item.alert-e{border-left:3px solid #ef4444;}
.alerts-dropdown-item.alert-w{border-left:3px solid #f59e0b;}
.alerts-dropdown-empty{padding:20px 16px;text-align:center;font-size:12px;color:#8fa592;}

/* Main content offset */
.main{
  margin-left:260px;
  margin-top:58px;
  flex:1;padding:32px 36px;
  max-width:calc(100vw - 260px);
  overflow-x:hidden;
  transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.main.expanded{margin-left:0;max-width:100vw;}

/* Overlay for mobile */
.sidebar-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,0.5);z-index:999;
}
.sidebar-overlay.show{display:block;}

@media(max-width:768px){
  .sidebar{transform:translateX(-100%);}
  .sidebar.show{transform:translateX(0);}
  .top-navbar{left:0;}
  .main{margin-left:0;max-width:100vw;padding:20px 16px;}
  .topnav-clock{display:none;}
}
@media print{
  .sidebar,.top-navbar,.btn-primary,.btn-warning,.btn-danger,.btn-secondary,.btn-blue,.modal-overlay,.page-header-row button{display:none!important;}
  .main{margin-left:0;padding:20px;margin-top:0;max-width:100%;}
}
.card{background:#fff;border:1px solid #dceade;border-radius:16px;padding:22px 24px;}
.card-title{display:flex;align-items:center;gap:8px;font-size:10px;font-weight:700;color:#2e7d32;text-transform:uppercase;letter-spacing:1.4px;margin-bottom:16px;}
.card-title-bar{display:block;width:20px;height:2px;background:#388e3c;border-radius:1px;flex-shrink:0;}
.stat-box{background:#fff;border:1px solid #dceade;border-radius:14px;padding:18px 20px;flex:1;min-width:120px;}
.stat-value{font-family:'DM Serif Display',serif;font-size:28px;line-height:1;margin-bottom:4px;letter-spacing:-0.5px;}
.stat-label{font-size:10px;color:#8fa592;text-transform:uppercase;letter-spacing:0.8px;font-weight:600;}
.row-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #edf5ef;}
.row-item:last-child{border-bottom:none;}
.btn-primary{background:linear-gradient(135deg,#256427,#2e7d32);color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:12.5px;font-weight:700;box-shadow:0 4px 14px rgba(46,125,50,0.25);}
.btn-primary.sm{padding:6px 12px;font-size:11px;}
.btn-danger{background:#fef2f2;color:#991b1b;border:1.5px solid #fecaca;border-radius:9px;padding:10px 20px;font-size:12.5px;font-weight:700;}
.btn-danger.sm{padding:6px 12px;font-size:11px;}
.btn-secondary{padding:10px 20px;border-radius:9px;border:1.5px solid #dceade;background:#fff;color:#2d4230;font-size:13px;font-weight:600;}
.btn-blue{background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:12.5px;font-weight:700;}
.btn-blue.sm{padding:6px 12px;font-size:11px;}
.btn-warning{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:12.5px;font-weight:700;}
.btn-warning.sm{padding:6px 12px;font-size:11px;}
.form-group{margin-bottom:14px;}
label{font-size:11px;color:#627065;margin-bottom:6px;display:block;text-transform:uppercase;letter-spacing:0.7px;font-weight:600;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-control{width:100%;background:#fff;border:1.5px solid #dceade;border-radius:10px;padding:10px 13px;color:#0d150e;font-size:13.5px;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:20px;padding:28px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.18);}
.modal-title{font-family:'DM Serif Display',serif;font-size:22px;color:#0d150e;margin-bottom:20px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.flex-wrap{display:flex;flex-wrap:wrap;gap:12px;}
.alert-warning{padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;}
.alert-error{padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;}
.page-header-row{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;}
.eyebrow{display:flex;align-items:center;gap:8px;font-size:10px;font-weight:700;color:#2e7d32;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:6px;}
.eyebrow-bar{width:20px;height:2px;background:#388e3c;border-radius:1px;display:block;}
.page-title{font-family:'DM Serif Display',serif;font-size:30px;color:#0d150e;letter-spacing:-0.6px;margin-bottom:4px;}
.page-sub{font-size:13px;color:#627065;}
.info-label{font-size:10px;color:#8fa592;text-transform:uppercase;letter-spacing:0.6px;font-weight:600;margin-bottom:2px;}
.info-value{font-size:12.5px;color:#1a2b1c;font-weight:600;}
.data-table{width:100%;border-collapse:collapse;}
.data-table th{text-align:left;font-size:10px;color:#8fa592;text-transform:uppercase;padding:7px 10px;letter-spacing:0.8px;font-weight:700;background:#f6faf7;border-bottom:1px solid #dceade;}
.data-table td{font-size:12.5px;padding:10px;border-bottom:1px solid #edf5ef;vertical-align:middle;}
#fleet-map{width:100%;height:480px;border-radius:14px;z-index:1;}
.kanban-board{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;align-items:start;}
.kanban-col{background:#f6faf7;border:1px solid #dceade;border-radius:14px;padding:14px;}
.kanban-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #dceade;}
.kanban-title{font-size:12px;font-weight:700;color:#2d4230;}
.kanban-count{background:#dceade;color:#2e7d32;font-size:10px;font-weight:700;border-radius:100px;padding:2px 8px;}
.kanban-card{background:#fff;border:1px solid #dceade;border-radius:10px;padding:14px;margin-bottom:8px;cursor:grab;transition:box-shadow 200ms;}
.kanban-card:hover{box-shadow:0 4px 16px rgba(46,125,50,0.12);}
.kanban-drop-zone{min-height:60px;border:2px dashed #dceade;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#8fa592;}
.sortable-ghost{opacity:0.4;background:#e8f5e9;border:2px dashed #4caf50;}

/* ── Trip Tracking Panel ── */
.tracking-panel{position:fixed;top:0;right:-480px;width:460px;height:100vh;background:#fff;border-left:1px solid #dceade;z-index:200;display:flex;flex-direction:column;transition:right 320ms cubic-bezier(.4,0,.2,1);box-shadow:-8px 0 32px rgba(0,0,0,0.12);}
.tracking-panel.open{right:0;}
.tp-header{padding:20px 22px 16px;border-bottom:1px solid #edf5ef;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;}
.tp-title{font-family:'DM Serif Display',serif;font-size:20px;color:#0d150e;}
.tp-close{width:32px;height:32px;border-radius:8px;background:#f6faf7;border:1px solid #dceade;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.tp-map{flex:1;min-height:0;overflow:hidden;}
.tp-map #track-map{width:100%;height:100%;min-height:240px;}
.tp-stats{padding:14px 18px;border-top:1px solid #edf5ef;flex-shrink:0;}
.tp-stat{display:flex;flex-direction:column;align-items:center;}
.tp-stat-val{font-family:'DM Serif Display',serif;font-size:22px;font-weight:700;line-height:1;}
.tp-stat-label{font-size:9px;text-transform:uppercase;letter-spacing:0.8px;color:#8fa592;font-weight:600;margin-top:2px;}
.movement-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:100px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;}
.mv-moving{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.mv-idle{background:#fffbeb;color:#92400e;border:1px solid #fde68a;}
.mv-stopped{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;}
.mv-offline{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.tp-qr-wrap{padding:10px 18px 14px;border-top:1px solid #edf5ef;text-align:center;flex-shrink:0;}
@keyframes ping-anim{0%{transform:scale(1);opacity:1}100%{transform:scale(2.2);opacity:0}}
.qr-card{background:#fff;border:1px solid #dceade;border-radius:16px;padding:22px;text-align:center;position:relative;}
.qr-card-header{background:linear-gradient(135deg,#14401a,#256427);color:#fff;border-radius:10px;padding:12px 16px;margin-bottom:16px;text-align:left;}
.qr-card-org{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.6);margin-bottom:2px;}
.qr-card-title{font-family:'DM Serif Display',serif;font-size:15px;color:#fff;}
.qr-driver-name{font-family:'DM Serif Display',serif;font-size:20px;color:#0d150e;margin-bottom:2px;}
.qr-driver-detail{font-size:11.5px;color:#627065;margin:2px 0;}
.qr-id-number{font-size:10px;font-weight:700;color:#627065;letter-spacing:1px;text-transform:uppercase;margin-top:10px;}
.chart-container{position:relative;height:220px;}
.chart-container-tall{position:relative;height:280px;}
.priority-normal{background:rgba(46,125,50,.13);color:#81c784;border:1px solid rgba(46,125,50,.25);font-size:9px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.5px;}
.priority-urgent{background:rgba(220,38,38,.13);color:#f87171;border:1px solid rgba(220,38,38,.25);font-size:9px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.5px;}
.priority-high{background:rgba(245,158,11,.13);color:#fbbf24;border:1px solid rgba(245,158,11,.25);font-size:9px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.5px;}
.qr-print-btn{position:absolute;top:10px;right:10px;background:transparent;border:1px solid #dceade;border-radius:8px;padding:4px 8px;font-size:13px;cursor:pointer;color:#627065;}
.qr-print-btn:hover{background:#f0fdf4;}
@media print{.sidebar,.btn-primary,.btn-warning,.btn-danger,.btn-secondary,.btn-blue,.modal-overlay,.page-header-row button{display:none!important;}.main{margin-left:0;padding:20px;}}
</style>
</head>
<body>

<!-- ═══ SIDEBAR OVERLAY (mobile) ═══ -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar" id="fvmSidebar">
  <div class="sidebar-logo">
    <div style="display:flex;align-items:center;gap:12px;position:relative;z-index:5;">
      <div class="logo-icon">🚛</div>
      <div>
        <div class="logo-text">FVM <span style="color:#81c784;">System</span></div>
        <div class="logo-sub">Fleet &amp; Vehicle Mgmt</div>
      </div>
    </div>
  </div>

  <!-- Status pill just below the logo divider -->
  <div class="sb-status">
    <div class="sb-status-dot"></div>
    <span>Supabase · Live</span>
    <span style="margin-left:auto;color:#81c784;font-weight:700;">
      <?=count(array_filter($vehicles,fn($v)=>$v['status']==='Active'))?>/<?=count($vehicles)?> Active
    </span>
  </div>

  <nav style="flex:1;padding:6px 0;">
    <div class="nav-section" style="margin-left:10px;">Operations</div>
    <?php
    $opsPages=['inspection','incidents','maintenance','fuel'];
    $navSubtitles=['dashboard'=>'Overview & Stats','map'=>'Live GPS Tracking','dispatch'=>'Trip Management','operations'=>'Maintenance & Reports'];
    foreach([['dashboard','🏠','Dashboard'],['map','🗺️','Live GPS Map'],['dispatch','📋','Dispatch Board'],['operations','🛠️','Operations']] as [$k,$ic,$lb]):
      $a=($page===$k||(in_array($page,$opsPages)&&$k==='operations'))?' active':'';
    ?>
    <a href="fvm.php?page=<?=$k?>" class="nav-item<?=$a?>">
      <div class="nav-icon-wrap"><?=$ic?></div>
      <div class="nav-label-wrap">
        <span class="nav-label-main"><?=$lb?></span>
        <span class="nav-label-sub"><?=$navSubtitles[$k]??''?></span>
      </div>
      <?php if($k==='dashboard'&&$alertCount>0):?><span class="nav-badge" style="background:#ef4444;"><?=$alertCount?></span><?php endif;?>
      <?php if($k==='operations'&&count($openIncidents)>0):?><span class="nav-badge" style="background:#dc2626;"><?=count($openIncidents)?></span><?php endif;?>
    </a>
    <?php endforeach; ?>

    <div class="nav-section" style="margin-left:10px;">Management</div>
    <?php
    $mgmtSubtitles=['vehicles'=>'Fleet Registry','drivers'=>'License & Scoring','compliance'=>'LTO & Insurance','analytics'=>'Reports & Charts'];
    foreach([['vehicles','🚗','Vehicles'],['drivers','🧑‍✈️','Drivers'],['compliance','📋','Compliance'],['analytics','📊','Analytics']] as [$k,$ic,$lb]):
      $a=$page===$k?' active':'';
    ?>
    <a href="fvm.php?page=<?=$k?>" class="nav-item<?=$a?>">
      <div class="nav-icon-wrap"><?=$ic?></div>
      <div class="nav-label-wrap">
        <span class="nav-label-main"><?=$lb?></span>
        <span class="nav-label-sub"><?=$mgmtSubtitles[$k]??''?></span>
      </div>
      <?php if($k==='maintenance'&&$pendingMaint>0):?><span class="nav-badge" style="background:#d97706;"><?=$pendingMaint?></span><?php endif;?>
    </a>
    <?php endforeach; ?>

    <div class="nav-section" style="margin-left:10px;">Tracking</div>
    <?php
    $trackSubtitles=['expenses'=>'Budget & Costs','reminders'=>'Due Dates & Alerts'];
    foreach([['expenses','💰','Expenses'],['reminders','🔔','Reminders']] as [$k,$ic,$lb]):
      $a=$page===$k?' active':'';
    ?>
    <a href="fvm.php?page=<?=$k?>" class="nav-item<?=$a?>">
      <div class="nav-icon-wrap"><?=$ic?></div>
      <div class="nav-label-wrap">
        <span class="nav-label-main"><?=$lb?></span>
        <span class="nav-label-sub"><?=$trackSubtitles[$k]??''?></span>
      </div>
      <?php if($k==='reminders'&&$activeReminders>0):?><span class="nav-badge" style="background:#d97706;"><?=$activeReminders?></span><?php endif;?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-footer-inner">
      <div style="margin-bottom:4px;display:flex;align-items:center;gap:6px;"><span class="live-dot"></span><span>Fleet Management System</span></div>
      <div>Alerts: <span style="color:<?=$alertCount>0?'#f87171':'#81c784'?>;font-weight:700;"><?=$alertCount?></span>
      &nbsp;·&nbsp; Pending: <span style="color:<?=$pendingMaint>0?'#fbbf24':'#81c784'?>;font-weight:700;"><?=$pendingMaint?></span></div>
    </div>
    <div class="sidebar-footer-bottom">
      <span style="font-size:10px;color:rgba(255,255,255,.3);background:rgba(76,175,80,.1);padding:3px 8px;border-radius:6px;border:1px solid rgba(76,175,80,.2);">FVM v2</span>
      <div class="status-online-pill">Online</div>
    </div>
  </div>
</div>

<!-- ═══ TOP NAVBAR ═══ -->
<nav class="top-navbar" id="fvmTopNav">
  <div class="topnav-left">
    <button class="toggle-btn" id="fvmToggleBtn" title="Toggle sidebar">☰</button>
    <div class="topnav-breadcrumb">
      FVM &nbsp;›&nbsp; <strong><?=e($ptitle)?></strong>
    </div>
  </div>
  <div class="topnav-right">
    <!-- Clock -->
    <div class="topnav-clock">
      <span id="fvmClock">—</span>
      <span class="topnav-clock-sep">•</span>
      <span id="fvmDate">—</span>
    </div>

    <!-- Alerts button -->
    <div style="position:relative;">
      <button class="topnav-icon-btn" id="fvmAlertsBtn" title="System Alerts">
        🔔
        <?php if($alertCount>0||$pendingMaint>0||$activeReminders>0): ?>
        <span class="tnb-badge"><?=$alertCount+$pendingMaint+$activeReminders?></span>
        <?php endif; ?>
      </button>
      <!-- Alerts dropdown -->
      <div class="alerts-dropdown" id="fvmAlertsDropdown">
        <div class="alerts-dropdown-header">
          <span class="alerts-dropdown-title">⚠️ System Alerts</span>
          <span style="font-size:11px;color:#8fa592;"><?=$alertCount+$pendingMaint+$activeReminders?> total</span>
        </div>
        <div class="alerts-dropdown-body">
          <?php
          $topAlerts=[];
          if($alertCount>0) $topAlerts[]=['e',"🚩 {$alertCount} flagged vehicle(s) need attention"];
          if($pendingMaint>0) $topAlerts[]=['w',"🔧 {$pendingMaint} maintenance request(s) pending"];
          if($activeReminders>0) $topAlerts[]=['w',"📅 {$activeReminders} compliance reminder(s) due soon"];
          if(count($openIncidents)>0) $topAlerts[]=['e',"🚨 ".count($openIncidents)." open incident report(s)"];
          if(empty($topAlerts)):
          ?>
          <div class="alerts-dropdown-empty">✅ No alerts — all systems normal</div>
          <?php else: foreach($topAlerts as [$type,$msg]): ?>
          <div class="alerts-dropdown-item alert-<?=$type?>">
            <span style="font-size:12px;line-height:1.5;"><?=$msg?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- Divider -->
    <div style="width:1px;height:24px;background:rgba(46,125,50,0.15);margin:0 4px;"></div>

    <!-- Sign out link styled as icon button -->
    <a href="fvm.php?logout=1" class="topnav-icon-btn" title="Sign Out" style="text-decoration:none;font-size:15px;">🚪</a>
  </div>
</nav>

<!-- ═══ MAIN ═══ -->
<div class="main" id="fvmMain"><div class="page-anim">

<?php
// ── Auto-detect missing schema and show migration panel ──────────────────────
$_missingSchema = [];
// Pull session errors first (written by sb() on every failed request)
$_sbErrs = [];
if(!empty($_SESSION['sb_errors'])){
    $_sbErrs = $_SESSION['sb_errors'];
    unset($_SESSION['sb_errors']);
}
// Classify errors: schema-cache / column-not-found / table-not-found
foreach($_sbErrs as $_se){
    $msg = $_se['message'] ?? '';
    $hint = $_se['hint'] ?? '';
    if(
        strpos($msg,'schema cache')!==false ||
        strpos($msg,'column')!==false ||
        strpos($msg,'Could not find the table')!==false ||
        ($_se['code']??0)==404
    ){
        $tbl = $_se['table'] ?? '';
        if($tbl && !in_array($tbl,$_missingSchema)) $_missingSchema[] = $tbl;
    }
}
$_needsMigration = !empty($_missingSchema) || !empty($_sbErrs);
?>

<?php if($_needsMigration): ?>
<div id="migration-banner" style="background:#1e1e2e;border:2px solid #f38ba8;border-radius:14px;padding:18px 22px;margin-bottom:24px;font-family:monospace;position:relative;">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
    <div style="color:#f38ba8;font-weight:700;font-size:14px;">⛔ Database Migration Required</div>
    <button onclick="document.getElementById('migration-banner').style.display='none'"
      style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.5);border-radius:8px;padding:4px 12px;font-size:12px;cursor:pointer;">
      Dismiss
    </button>
  </div>

  <!-- What's missing -->
  <div style="font-size:12px;color:#cba6f7;margin-bottom:12px;">
    Missing or outdated:
    <?php foreach(array_unique($_missingSchema) as $_mt): ?>
    <span style="background:rgba(243,139,168,.12);border:1px solid rgba(243,139,168,.3);border-radius:6px;padding:2px 8px;margin:2px;display:inline-block;color:#f38ba8;"><?=e($_mt)?></span>
    <?php endforeach; ?>
  </div>

  <!-- Individual errors (collapsed by default) -->
  <?php if(!empty($_sbErrs)): ?>
  <details style="margin-bottom:12px;">
    <summary style="cursor:pointer;color:#6c7086;font-size:11px;margin-bottom:6px;">▶ Show <?=count($_sbErrs)?> raw error<?=count($_sbErrs)>1?'s':''?></summary>
    <?php foreach($_sbErrs as $_e): ?>
    <div style="background:#181825;border-radius:8px;padding:10px;margin-top:6px;font-size:11.5px;">
      <div style="color:#cba6f7;margin-bottom:3px;"><strong><?=e($_e['method'])?></strong> → <span style="color:#89dceb;">fvm_<?=e($_e['table'])?></span> <span style="color:#f38ba8;">(HTTP <?=e($_e['code'])?>)</span></div>
      <div style="color:#fab387;margin-bottom:2px;">Message: <?=e($_e['message'])?></div>
      <?php if($_e['hint']): ?><div style="color:#a6e3a1;">Hint: <?=e($_e['hint'])?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>

  <!-- Migration SQL -->
  <div style="background:#11111b;border:1px solid rgba(255,255,255,.08);border-radius:10px;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.06);">
      <span style="color:#a6e3a1;font-size:12px;font-weight:700;">🔨 Supabase → SQL Editor → New query → Paste → Run</span>
      <button id="copy-sql-btn" onclick="copySchemaSql()"
        style="background:linear-gradient(135deg,#1b5e20,#2e7d32);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">
        📋 Copy SQL
      </button>
    </div>
    <pre id="migration-sql" style="margin:0;padding:14px;font-size:11px;color:#cdd6f4;white-space:pre-wrap;line-height:1.7;overflow-x:auto;max-height:260px;overflow-y:auto;">-- ═══════════════════════════════════════════════════════════════════
-- FVM Schema Migration — run once in Supabase SQL Editor
-- ═══════════════════════════════════════════════════════════════════

-- 1. Driver online-status columns
ALTER TABLE fvm_drivers
  ADD COLUMN IF NOT EXISTS is_online   BOOLEAN     DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS last_seen   TIMESTAMPTZ DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS points      INTEGER     DEFAULT 0;

-- 2. Trip routing + proof columns
ALTER TABLE fvm_trips
  ADD COLUMN IF NOT EXISTS origin_lat        NUMERIC(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS origin_lng        NUMERIC(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS dest_lat          NUMERIC(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS dest_lng          NUMERIC(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS dispatch_timer    INTEGER       DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS dispatched_at     TIMESTAMPTZ   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS eta_minutes       INTEGER       DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS route_distance_km NUMERIC(8,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS route_suggestion  TEXT          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS completed_at      TIMESTAMPTZ   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS proof_photo_url   TEXT          DEFAULT NULL;

-- 3. Driver notifications table
CREATE TABLE IF NOT EXISTS fvm_notifications (
  id         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  driver_id  UUID        NOT NULL REFERENCES fvm_drivers(id) ON DELETE CASCADE,
  trip_id    UUID        REFERENCES fvm_trips(id) ON DELETE SET NULL,
  type       TEXT        NOT NULL DEFAULT 'trip_assigned',
  title      TEXT        NOT NULL,
  message    TEXT        NOT NULL,
  is_read    BOOLEAN     NOT NULL DEFAULT false,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_fvm_notifications_driver ON fvm_notifications(driver_id, is_read);

-- Done! Refresh FVM after running.</pre>
  </div>

  <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:11px;color:#6c7086;">
    <span>After running the SQL, refresh this page — this banner will disappear automatically.</span>
    <a href="fvm.php?page=<?=e($page)?>" style="color:#89b4fa;text-decoration:none;font-weight:700;">↺ Refresh Now</a>
  </div>
</div>
<script>
function copySchemaSql(){
  var el=document.getElementById('migration-sql');
  var btn=document.getElementById('copy-sql-btn');
  if(!el||!btn) return;
  navigator.clipboard.writeText(el.textContent.trim()).then(function(){
    btn.textContent='✅ Copied!';
    btn.style.background='linear-gradient(135deg,#14532d,#166534)';
    setTimeout(function(){ btn.textContent='📋 Copy SQL'; btn.style.background='linear-gradient(135deg,#1b5e20,#2e7d32)'; },2500);
  }).catch(function(){
    // Fallback for browsers without clipboard API
    var r=document.createRange(); r.selectNode(el);
    window.getSelection().removeAllRanges(); window.getSelection().addRange(r);
    document.execCommand('copy');
    btn.textContent='✅ Copied!';
    setTimeout(function(){ btn.textContent='📋 Copy SQL'; },2500);
  });
}
</script>
<?php endif; ?>

<?php if ($page==='dashboard'): ?>
<!-- ══ DASHBOARD ══ -->
<?php
  $activeCount = count(array_filter($vehicles,fn($v)=>$v['status']==='Active'));
  $totalExp    = array_sum(array_map(fn($e)=>(float)$e['amount'], $expenses));
  $totalTrips  = count($trips);
  $alerts = [];
  foreach($vehicles as $v){ if($v['flagged']) $alerts[]=['e',"🚩 {$v['plate']} flagged — <strong>In Maintenance</strong> · <a href='fvm.php?page=operations&tab=health' style='color:#dc2626;font-weight:700;'>Health Check →</a> or <a href='fvm.php?page=operations&tab=maintenance' style='color:#dc2626;font-weight:700;'>Maintenance →</a>"]; }
  foreach($compliance as $c){ if($c['status']==='Expired') $alerts[]=['e',"📋 {$c['compliance_type']} expired for vehicle"]; }
  foreach($drivers as $d){ if(isExpired($d['license_expiry'])) $alerts[]=['e',"🪪 {$d['full_name']}'s license expired"]; }
  $openIncCount=count(array_filter($incidents,fn($i)=>$i['status']==='Open'));
  if($openIncCount>0) $alerts[]=['e',"🚨 {$openIncCount} open incident report(s) from drivers — <a href='fvm.php?page=operations&tab=incidents' style='color:#dc2626;font-weight:700;'>Review Now →</a>"];
  $bpIncCount=count(array_filter($incidents,fn($i)=>$i['status']==='Budget Pending'));
  if($bpIncCount>0) $alerts[]=['w',"💳 {$bpIncCount} incident(s) awaiting budget approval — <a href='fvm.php?page=operations&tab=maintenance' style='color:#92400e;font-weight:700;'>Maintenance →</a>"];
  $dashRem = array_filter($reminders,fn($r)=>!$r['dismissed']&&(strtotime($r['due_date'])-strtotime($today))/86400<=30);
?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Fleet Operations Center</div><div class="page-title">Fleet Overview</div><div class="page-sub">Live monitoring · Supabase · Real-time analytics</div></div>
  <a href="fvm.php?page=map" class="btn-blue" style="text-decoration:none;">🗺️ Live Map</a>
</div>
<div class="flex-wrap" style="margin-bottom:22px;">
  <?php foreach([['Total Vehicles',count($vehicles),'#0d150e'],['Active',count(array_filter($vehicles,fn($v)=>$v['status']==='Active')),'#2e7d32'],['In Maintenance',count(array_filter($vehicles,fn($v)=>$v['status']==='In Maintenance')),'#d97706'],['Total Trips',$totalTrips,'#1d4ed8'],['Total Expenses',peso($totalExp),'#dc2626'],['Drivers',count($drivers),'#256427']] as [$l,$val,$c]):?>
  <div class="stat-box"><div class="stat-value" style="color:<?=$c?>"><?=$val?></div><div class="stat-label"><?=$l?></div></div>
  <?php endforeach;?>
</div>
<div class="grid-2" style="margin-bottom:20px;">
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>📈 Monthly Expenses</div><div class="chart-container"><canvas id="chartMonthly"></canvas></div></div>
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>🚗 Fleet Status</div><div class="chart-container" style="display:flex;align-items:center;justify-content:center;"><canvas id="chartDonut" style="max-width:200px;"></canvas></div>
    <div style="display:flex;gap:16px;justify-content:center;margin-top:12px;">
      <?php foreach([['Active','#2e7d32'],['In Maintenance','#d97706'],['Inactive','#dc2626']] as [$l,$c]):?>
      <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;"><div style="width:10px;height:10px;border-radius:50%;background:<?=$c?>"></div><?=$l?></div>
      <?php endforeach;?>
    </div>
  </div>
</div>
<div class="grid-2" style="margin-bottom:20px;">
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>⛽ Fuel Levels</div><div class="chart-container"><canvas id="chartFuel"></canvas></div></div>
  <div class="card">
    <div class="card-title"><span class="card-title-bar"></span>🔔 Alerts & Reminders</div>
    <?php if(empty($alerts)): ?><div style="color:#8fa592;font-size:13px;padding:8px 0;">✅ No active alerts</div><?php endif;?>
    <?php foreach($alerts as [$k,$msg]): ?>
    <div class="alert-<?=$k==='e'?'error':'warning'?>" style="margin-bottom:8px;"><div style="font-size:12.5px;font-weight:600;"><?=$msg?></div></div>
    <?php endforeach; ?>
    <?php if(count($dashRem)>0): ?>
    <div style="border-top:1px solid #edf5ef;margin-top:10px;padding-top:10px;">
      <div style="font-size:10px;font-weight:700;color:#d97706;letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">⏰ Due Soon</div>
      <?php foreach(array_slice(array_values($dashRem),0,4) as $r):
        $vr=findById($vehicles,$r['vehicle_id']);
        $dl=(int)floor((strtotime($r['due_date'])-strtotime($today))/86400);
        $uc=$dl<0?'#dc2626':($dl<=7?'#dc2626':'#d97706');
      ?>
      <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:5px;">
        <span><?=e($r['reminder_type'])?> · <span style="color:#627065;"><?=e($vr?$vr['plate']:'')?></span></span>
        <span style="font-weight:700;color:<?=$uc?>"><?=$dl<0?abs($dl).'d overdue':$dl.'d left'?></span>
      </div>
      <?php endforeach; ?>
      <a href="fvm.php?page=reminders" style="font-size:11px;color:#256427;text-decoration:none;font-weight:600;">View all →</a>
    </div>
    <?php endif; ?>
  </div>
</div>
<div class="grid-2">
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>🚗 Vehicle Status</div>
    <?php foreach($vehicles as $v): ?>
    <div class="row-item">
      <div><div style="font-size:13.5px;font-weight:600;"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></div><div style="font-size:11.5px;color:#8fa592;"><?=e($v['location']??'Metro Manila')?> · Fuel: <?=$v['fuel_level']?>%</div></div>
      <?=badge($v['status'],statusColor($v['status']))?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>🚦 Recent Trips</div>
    <?php foreach(array_slice($trips,0,6) as $t):
      $vt=findById($vehicles,$t['vehicle_id']); $dt=findById($drivers,$t['driver_id']);
    ?>
    <div class="row-item">
      <div><div style="font-size:13px;font-weight:600;"><?=e($t['origin'])?> → <?=e($t['destination'])?></div><div style="font-size:11px;color:#8fa592;"><?=e($dt?$dt['full_name']:'—')?> · <?=e($t['scheduled_date'])?></div></div>
      <?=badge($t['status'],statusColor($t['status']))?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif($page==='map'): ?>
<!-- ══ MAP ══ -->
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>GPS Tracking</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <div style="display:flex;gap:8px;">
    <?php foreach(['all'=>'All','Active'=>'Active','In Maintenance'=>'Maint.','Inactive'=>'Inactive'] as $k=>$l): ?>
    <button onclick="filterMap('<?=$k?>')" id="f-<?=e($k)?>" class="btn-secondary" style="font-size:11.5px;padding:6px 14px;"><?=$l?></button>
    <?php endforeach; ?>
  </div>
</div>
<div class="grid-2" style="margin-bottom:16px;">
  <?php foreach([['Active Vehicles',count(array_filter($vehicles,fn($v)=>$v['status']==='Active')),'#2e7d32'],['Flagged',count(array_filter($vehicles,fn($v)=>$v['flagged'])),'#dc2626'],['Low Fuel',count(array_filter($vehicles,fn($v)=>$v['fuel_level']<=30)),'#d97706'],['In Maintenance',count(array_filter($vehicles,fn($v)=>$v['status']==='In Maintenance')),'#d97706']] as [$l,$val,$c]):?>
  <div class="stat-box"><div class="stat-value" style="color:<?=$c?>"><?=$val?></div><div class="stat-label"><?=$l?></div></div>
  <?php endforeach;?>
</div>
<div class="card" style="padding:0;overflow:hidden;">
  <div id="fleet-map"></div>
</div>
<div style="margin-top:16px;" class="grid-2">
  <?php foreach($vehicles as $v): ?>
  <div class="card" style="padding:14px 18px;cursor:pointer;" onclick="focusVehicle(<?=$v['lat']??14.5995?>,<?=$v['lng']??120.9842?>,'<?=e($v['id'])?>')">
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <div><div style="font-weight:700;font-size:13.5px;"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></div><div style="font-size:11.5px;color:#8fa592;margin-top:2px;"><?=e($v['location']??'Metro Manila')?></div></div>
      <div style="text-align:right;">
        <?=badge($v['status'],statusColor($v['status']))?>
        <div style="margin-top:6px;"><?=fuelBar($v['fuel_level'])?><span style="font-size:11px;color:#627065;margin-left:5px;"><?=$v['fuel_level']?>%</span></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif($page==='dispatch'): ?>
<!-- ══ DISPATCH ══ -->
<?php if($dispatchError):?><div class="alert-error" style="margin-bottom:16px;"><?=e($dispatchError)?></div><?php endif;?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Trip Management</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <button class="btn-primary" onclick="openModal('modal-dispatch')">+ New Trip</button>
</div>
<?php
  $colMap = ['Pending'=>['label'=>'📋 Pending','color'=>'#d97706'],'In Progress'=>['label'=>'🚗 In Progress','color'=>'#1d4ed8'],'Completed'=>['label'=>'✅ Completed','color'=>'#2e7d32']];
?>
<div class="kanban-board">
<?php foreach($colMap as $colStatus=>$col): ?>
  <?php $colItems=array_filter($trips,fn($t)=>$t['status']===$colStatus); $colKey=strtolower(str_replace(' ','_',$colStatus)); ?>
  <div class="kanban-col">
    <div class="kanban-header"><span class="kanban-title" style="color:<?=$col['color']?>"><?=$col['label']?></span><span class="kanban-count"><?=count($colItems)?></span></div>
    <div id="cards-<?=$colKey?>" data-status="<?=$colStatus?>">
    <?php foreach($colItems as $t):
      $vt=findById($vehicles,$t['vehicle_id']);
      $dt=findById($drivers,$t['driver_id']);
      $pc=match($t['priority']??'Normal'){'Urgent'=>'priority-urgent','VIP'=>'priority-high',default=>'priority-normal'};
      $tk=$trackingData[$t['id']]??null;
      $mvStatus=$tk?$tk['movement_status']:'Offline';
      $mvClass=match($mvStatus){'Moving'=>'mv-moving','Idle'=>'mv-idle','Stopped'=>'mv-stopped',default=>'mv-offline'};
      $mvIcon=match($mvStatus){'Moving'=>'🟢','Idle'=>'🟡','Stopped'=>'⚪',default=>'🔴'};
      $lastSeen=$tk?$tk['updated_at']:null;
      $secsAgo=$lastSeen?(time()-strtotime($lastSeen)):null;
      $isLive=$secsAgo!==null&&$secsAgo<120; // considered live if pinged within 2 min
    ?>
    <div class="kanban-card" data-trip-id="<?=e($t['id'])?>">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <span style="font-size:11px;color:#8fa592;font-weight:600;"><?=e($t['trip_code']??'')?></span>
        <span class="priority-badge <?=$pc?>"><?=e($t['priority']??'Normal')?></span>
      </div>
      <div style="font-size:13.5px;font-weight:700;margin-bottom:4px;">📍 <?=e($t['origin'])?> → <?=e($t['destination'])?></div>
      <div style="font-size:11px;color:#8fa592;margin-bottom:4px;">🚗 <?=e($vt?$vt['plate']:'?')?> · 🧑‍✈️ <?=e($dt?$dt['full_name']:'?')?></div>
      <div style="font-size:11px;color:#8fa592;margin-bottom:4px;">📅 <?=e($t['scheduled_date'])?> <?=e($t['scheduled_time']??'')?></div>
      <?php if(!empty($t['eta_minutes'])&&$t['eta_minutes']>0): $etaMins=(int)$t['eta_minutes']; $etaHr=floor($etaMins/60); $etaMin=$etaMins%60; ?>
      <div style="font-size:11px;color:#1d4ed8;font-weight:700;margin-bottom:4px;">🕐 ETA: <?=$etaHr>0?$etaHr.'h ':''?><?=$etaMin?>min &nbsp;·&nbsp; <?=number_format((float)($t['route_distance_km']??0),1)?> km</div>
      <?php endif; ?>
      <?php if(!empty($t['route_suggestion'])): ?>
      <div style="font-size:10px;color:#256427;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:3px 8px;margin-bottom:4px;"><?=e($t['route_suggestion'])?></div>
      <?php endif; ?>
      <?php
        $dispTs = $t['dispatched_at'] ?? null;
        $timerMin = (int)($t['dispatch_timer'] ?? 0);
      ?>
      <?php if($dispTs): ?>
      <div style="font-size:10px;color:#8fa592;margin-bottom:4px;font-family:monospace;">&#128336; Dispatched: <?=e(date('M j, H:i:s', strtotime($dispTs)))?></div>
      <?php endif; ?>
      <?php if($timerMin>0&&$colStatus==='In Progress'): ?>
      <div class="trip-timer-wrap" style="margin-bottom:8px;" data-dispatched="<?=e($dispTs??'')?>" data-timer-min="<?=$timerMin?>">
        <div style="font-size:10px;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">&#9201; Trip Timer</div>
        <div class="trip-timer-display" style="font-size:12px;font-weight:700;font-family:monospace;color:#d97706;">--:--</div>
      </div>
      <?php endif; ?>

      <?php if($colStatus==='In Progress'): ?>
      <!-- Live movement badge -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <span class="movement-badge <?=$mvClass?>">
          <?php if($isLive&&$mvStatus==='Moving'): ?>
          <span style="width:7px;height:7px;background:#22c55e;border-radius:50%;display:inline-block;animation:ping-anim 1s infinite;"></span>
          <?php else: ?>
          <span><?=$mvIcon?></span>
          <?php endif; ?>
          <?=$mvStatus?>
          <?php if($tk&&$tk['speed_kmh']>0): ?> · <?=round($tk['speed_kmh'])?>km/h<?php endif;?>
        </span>
        <?php if($lastSeen&&$secsAgo!==null): ?>
        <span style="font-size:10px;color:#8fa592;"><?=$secsAgo<60?$secsAgo.'s ago':round($secsAgo/60).'m ago'?></span>
        <?php else: ?>
        <span style="font-size:10px;color:#dc2626;">No GPS signal</span>
        <?php endif;?>
      </div>
      <?php endif; ?>

      <?php if($colStatus==='Completed'):
        $cAt  = $t['completed_at']    ?? null;
        $cPic = $t['proof_photo_url'] ?? '';
        $cOdo = (int)($t['mileage_km'] ?? 0);
        // Best timestamp: completed_at → updated_at → none
        if($cAt){
            $cTimeLabel = '✅ '.date('M j, Y · H:i', strtotime($cAt));
        } elseif(!empty($t['updated_at'])){
            $cTimeLabel = '✅ '.date('M j, Y · H:i', strtotime($t['updated_at']));
        } else {
            $cTimeLabel = '✅ Completed by driver';
        }
      ?>
      <div style="border-top:1px solid #edf5ef;padding-top:8px;margin-top:4px;margin-bottom:6px;">
        <div style="font-size:10.5px;color:#2e7d32;font-weight:700;margin-bottom:4px;"><?=e($cTimeLabel)?></div>
        <?php if($cOdo>0): ?>
        <div style="font-size:10.5px;color:#627065;margin-bottom:4px;">📏 Odometer: <strong><?=number_format($cOdo)?> km</strong></div>
        <?php endif; ?>
        <?php if(!empty($cPic)): ?>
        <div style="font-size:10px;font-weight:700;color:#627065;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px;">📷 Proof Photo</div>
        <img src="<?=e($cPic)?>" alt="Proof"
          onclick="var s=this.style;s.maxHeight=s.maxHeight?'':'80px';s.width=s.width?'':'auto';"
          style="max-height:80px;width:auto;max-width:100%;border-radius:8px;border:1.5px solid #dceade;cursor:zoom-in;display:block;" title="Click to expand">
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
        <?php if($colStatus==='Pending'):?>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="move_trip">
          <input type="hidden" name="trip_id" value="<?=e($t['id'])?>">
          <input type="hidden" name="newStatus" value="in_progress">
          <button type="submit" class="btn-blue sm">▶ Start</button>
        </form>
        <?php elseif($colStatus==='In Progress'):?>
        <span style="font-size:10px;color:#627065;font-style:italic;">⏳ Awaiting driver completion</span>
        <?php endif;?>
        <!-- Track button — always visible for In Progress, greyed for others -->
        <?php if($colStatus==='In Progress'): ?>
        <?php $_td=json_encode(['id'=>$t['id'],'trip_code'=>$t['trip_code']??'','origin'=>$t['origin'],'destination'=>$t['destination'],'date'=>$t['scheduled_date'],'driver'=>$dt?$dt['full_name']:'','plate'=>$vt?$vt['plate']:'','vehicle_id'=>$t['vehicle_id'],'eta_minutes'=>(int)($t['eta_minutes']??0),'route_km'=>(float)($t['route_distance_km']??0),'route_sug'=>$t['route_suggestion']??''],JSON_HEX_QUOT|JSON_HEX_APOS); ?>
        <button class="btn-blue sm" onclick='openTracking(<?=e($_td)?>)'>📡 Track</button>
        <?php endif;?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($colItems)):?><div class="kanban-drop-zone">No trips</div><?php endif;?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- ══ TRACKING PANEL ══ -->
<div class="tracking-panel" id="tracking-panel">
  <div class="tp-header">
    <div>
      <div style="font-size:10px;font-weight:700;color:#2e7d32;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">📡 Live Trip Tracking</div>
      <div class="tp-title" id="tp-trip-code">—</div>
      <div style="font-size:12px;color:#627065;margin-top:2px;" id="tp-route">—</div>
    </div>
    <button class="tp-close" onclick="closeTracking()">✕</button>
  </div>

  <!-- Arrival notification banner (hidden until driver confirms) -->
  <div id="tp-arrival-banner" style="display:none;background:#dcfce7;border-bottom:2px solid #22c55e;padding:12px 18px;flex-shrink:0;animation:fadeUp .3s ease;">
    <div style="display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:13px;font-weight:700;color:#166534;">&#127919; Driver Has Arrived!</div>
        <div style="font-size:11px;color:#16a34a;margin-top:2px;" id="tp-arrival-note"></div>
      </div>
      <div style="font-size:12px;color:#166534;font-style:italic;">Driver will submit completion with proof photo.</div>
    </div>
  </div>

  <!-- ETA bar (visible when trip has eta) -->
  <div id="tp-eta-bar" style="display:none;padding:8px 18px;background:#eff6ff;border-bottom:1px solid #bfdbfe;flex-shrink:0;font-size:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <span>🕐 <strong>ETA:</strong> <span id="tp-eta-val" style="color:#1d4ed8;font-weight:700;">—</span></span>
    <span>📏 <span id="tp-dist-val" style="color:#256427;font-weight:700;">—</span></span>
    <span id="tp-route-sug-lbl" style="color:#627065;font-size:11px;"></span>
  </div>
  <!-- Movement status bar -->
  <div style="padding:10px 18px;border-bottom:1px solid #edf5ef;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
    <span class="movement-badge mv-offline" id="tp-mv-badge">⚫ Offline</span>
    <div style="text-align:right;">
      <div style="font-size:10px;color:#8fa592;">Last ping</div>
      <div style="font-size:12px;font-weight:600;" id="tp-last-ping">—</div>
    </div>
  </div>

  <!-- Live stats row -->
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;padding:12px 18px;border-bottom:1px solid #edf5ef;gap:8px;flex-shrink:0;">
    <div class="tp-stat"><div class="tp-stat-val" id="tp-speed">—</div><div class="tp-stat-label">km/h</div></div>
    <div class="tp-stat"><div class="tp-stat-val" id="tp-heading">—</div><div class="tp-stat-label">Heading</div></div>
    <div class="tp-stat"><div class="tp-stat-val" id="tp-accuracy">—</div><div class="tp-stat-label">Accuracy</div></div>
    <div class="tp-stat"><div class="tp-stat-val" id="tp-pings">0</div><div class="tp-stat-label">Pings</div></div>
  </div>

  <!-- Map -->
  <div class="tp-map"><div id="track-map"></div></div>

  <!-- Driver status footer -->
  <div class="tp-qr-wrap">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <div id="tp-driver-info" style="flex:1;font-size:12px;color:#627065;">
        <span style="font-weight:700;color:#2e7d32;" id="tp-driver-name">—</span>
        &nbsp;·&nbsp; <span id="tp-plate-info">—</span>
      </div>
      <div id="tp-signal-indicator" style="display:flex;align-items:center;gap:5px;font-size:11px;color:#8fa592;">
        <span id="tp-signal-dot" style="width:7px;height:7px;border-radius:50%;background:#dc2626;display:inline-block;"></span>
        <span id="tp-signal-txt">Waiting for driver GPS…</span>
      </div>
    </div>
    <div style="font-size:10px;color:#8fa592;margin-top:6px;">Driver must open the Driver Portal → GPS tab → tap Start Sharing to begin transmitting location.</div>
  </div>
</div>
<!-- Overlay dim when panel open -->
<div id="tracking-overlay" onclick="closeTracking()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.2);z-index:199;"></div>

<!-- Dispatch modal -->
<div class="modal-overlay" id="modal-dispatch">
  <div class="modal" style="max-width:640px;">
    <div class="modal-title">🚗 New Trip Dispatch</div>
    <form method="POST" id="dispatch-form"><input type="hidden" name="action" value="dispatch_trip">
      <!-- Hidden geocoded coords -->
      <input type="hidden" name="origin_lat"  id="dp-origin-lat">
      <input type="hidden" name="origin_lng"  id="dp-origin-lng">
      <input type="hidden" name="dest_lat"    id="dp-dest-lat">
      <input type="hidden" name="dest_lng"    id="dp-dest-lng">
      <div class="form-row">
        <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php
  $busyVids = array_column(array_filter($trips, fn($t) => in_array($t['status'], ['Pending','In Progress'])), 'vehicle_id');
  $freeVehicles = array_filter($vehicles, fn($v) => $v['status'] === 'Active' && !in_array($v['id'], $busyVids));
  if (empty($freeVehicles)): ?>
    <option value="" disabled selected>No available vehicles</option>
  <?php else: foreach($freeVehicles as $v): ?>
    <option value="<?=e($v['id'])?>">&#128663; <?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option>
  <?php endforeach; endif; ?></select></div>
        <div class="form-group"><label>Driver</label><select name="driver_id" class="form-control" required><?php
  $busyDids = array_column(array_filter($trips, fn($t) => in_array($t['status'], ['Pending','In Progress'])), 'driver_id');
  $freeDrivers = array_filter($drivers, fn($d) => $d['status'] === 'Available' && !in_array($d['id'], $busyDids));
  if (empty($freeDrivers)): ?>
    <option value="" disabled selected>No available drivers</option>
  <?php else: foreach($freeDrivers as $d): ?>
    <option value="<?=e($d['id'])?>">&#129333;&#8205;&#9992; <?=e($d['full_name'])?></option>
  <?php endforeach; endif; ?></select></div>
      </div>

      <!-- Origin with geocoding -->
      <div class="form-group">
        <label>&#128681; Origin (pickup / starting point)</label>
        <div style="display:flex;gap:8px;">
          <input type="text" name="origin" id="dp-origin" class="form-control" required placeholder="e.g. SM Mall of Asia, Pasay" oninput="dpGeoDebounce('origin')">
          <button type="button" onclick="dpGeoNow()" title="Use current location" style="background:#f0fdf4;border:1.5px solid #dceade;border-radius:9px;padding:8px 12px;white-space:nowrap;font-size:13px;cursor:pointer;">&#128205; My Loc</button>
        </div>
        <div id="dp-origin-status" style="font-size:11px;color:#627065;margin-top:4px;min-height:14px;"></div>
      </div>

      <!-- Destination with geocoding -->
      <div class="form-group">
        <label>&#128205; Destination</label>
        <div style="display:flex;gap:8px;">
          <input type="text" name="destination" id="dp-dest" class="form-control" required placeholder="e.g. NAIA Terminal 3, Pasay" oninput="dpGeoDebounce('dest')">
          <button type="button" onclick="dpGeocode('dest')" title="Search destination" style="background:#f0fdf4;border:1.5px solid #dceade;border-radius:9px;padding:8px 12px;white-space:nowrap;font-size:13px;cursor:pointer;">&#128269; Search</button>
        </div>
        <div id="dp-dest-status" style="font-size:11px;color:#627065;margin-top:4px;min-height:14px;"></div>
      </div>

      <!-- Open pin map button -->
      <div style="margin-bottom:12px;">
        <button type="button" id="dp-open-map-btn" onclick="dpOpenPinMap()" style="width:100%;background:#f6faf7;border:1.5px dashed #a3c4a8;border-radius:10px;padding:10px;font-size:13px;font-weight:600;color:#256427;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
          <span style="font-size:16px;">&#128204;</span> Pin Exact Locations on Map
          <span style="font-size:11px;font-weight:400;color:#8fa592;">(drag the pins to adjust)</span>
        </button>
      </div>

      <!-- Route preview + draggable pin map -->
      <div id="dp-map-wrap" style="border-radius:12px;overflow:hidden;border:1.5px solid #dceade;margin-bottom:14px;display:none;">
        <div style="padding:7px 12px;background:#f6faf7;border-bottom:1px solid #dceade;font-size:11px;font-weight:700;color:#2e7d32;display:flex;justify-content:space-between;align-items:center;">
          <span>&#128225; Route Preview &amp; Pin</span>
          <span id="dp-dist-label" style="color:#627065;font-weight:500;"></span>
        </div>
        <div style="padding:6px 14px;background:#fffbeb;border-bottom:1px solid #fde68a;font-size:11px;color:#92400e;display:flex;align-items:center;gap:7px;">
          <span style="font-size:13px;">&#128075;</span>
          <span><b>Drag</b> the <b style="color:#1a6e1c;">&#128681; green pin</b> for origin &nbsp;&middot;&nbsp; <b style="color:#dc2626;">&#128205; red pin</b> for destination</span>
        </div>
        <div id="dp-map" style="height:260px;"></div>
        <div style="display:flex;border-top:1px solid #dceade;">
          <div style="flex:1;padding:6px 12px;font-size:10px;color:#627065;border-right:1px solid #dceade;">
            <span style="font-weight:700;color:#1a6e1c;">&#128681; Origin:</span> <span id="dp-origin-coords" style="font-family:monospace;">not pinned</span>
          </div>
          <div style="flex:1;padding:6px 12px;font-size:10px;color:#627065;">
            <span style="font-weight:700;color:#dc2626;">&#128205; Dest:</span> <span id="dp-dest-coords" style="font-family:monospace;">not pinned</span>
          </div>
        </div>
      </div>

            <div class="form-row">
        <div class="form-group"><label>&#128197; Date</label><input type="date" name="date" class="form-control" value="<?=$today?>" required></div>
        <div class="form-group"><label>&#128336; Scheduled Time</label><input type="time" name="scheduled_time" class="form-control" value="08:00"></div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>&#9201; Trip Timer (minutes)</label>
          <input type="number" name="dispatch_timer" id="dp-timer" class="form-control" placeholder="e.g. 60" min="0">
          <div style="font-size:11px;color:#8fa592;margin-top:3px;">Auto-filled from route distance. Alerts dispatch when time expires.</div>
        </div>
        <div class="form-group"><label>Priority</label><select name="priority" class="form-control"><option>Normal</option><option>Urgent</option><option>VIP</option></select></div>
      </div>
      <div class="form-group"><label>Purpose / Notes</label><input type="text" name="purpose" class="form-control"></div>

      <!-- ETA & Route Suggestions -->
      <div id="dp-route-box" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin-bottom:12px;">
        <div style="font-size:11px;font-weight:700;color:#1b5e20;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🗺️ Route Intelligence</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
          <div style="flex:1;min-width:100px;background:#fff;border:1px solid #dceade;border-radius:8px;padding:8px 12px;">
            <div style="font-size:10px;color:#627065;margin-bottom:2px;">🕐 ETA</div>
            <div id="dp-eta-val" style="font-size:16px;font-weight:700;color:#1d4ed8;">—</div>
          </div>
          <div style="flex:1;min-width:100px;background:#fff;border:1px solid #dceade;border-radius:8px;padding:8px 12px;">
            <div style="font-size:10px;color:#627065;margin-bottom:2px;">📏 Distance</div>
            <div id="dp-dist-val" style="font-size:16px;font-weight:700;color:#256427;">—</div>
          </div>
          <div style="flex:1;min-width:100px;background:#fff;border:1px solid #dceade;border-radius:8px;padding:8px 12px;">
            <div style="font-size:10px;color:#627065;margin-bottom:2px;">⛽ Est. Fuel</div>
            <div id="dp-fuel-val" style="font-size:16px;font-weight:700;color:#d97706;">—</div>
          </div>
        </div>
        <!-- Route suggestions -->
        <div style="font-size:11px;font-weight:700;color:#1b5e20;margin-bottom:6px;">Best Route Options:</div>
        <div id="dp-suggestions" style="display:flex;flex-direction:column;gap:6px;">
          <!-- filled by JS -->
        </div>
        <div id="dp-selected-route" style="display:none;background:#dcfce7;border:1px solid #86efac;border-radius:7px;padding:7px 10px;margin-top:8px;font-size:12px;color:#14532d;">
          ✅ <strong>Selected:</strong> <span id="dp-sel-lbl"></span>
        </div>
      </div>
      <input type="hidden" name="eta_minutes"       id="dp-eta-min"  value="">
      <input type="hidden" name="route_distance_km" id="dp-route-km" value="">
      <input type="hidden" name="route_suggestion"  id="dp-route-sug" value="">

      <!-- Live dispatch timestamp -->
      <div style="background:#f6faf7;border:1px solid #dceade;border-radius:9px;padding:9px 14px;margin-bottom:14px;font-size:12px;color:#627065;">
        &#128336; Dispatch Timestamp: <strong id="dp-timestamp" style="color:#2e7d32;font-family:monospace;"></strong>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn-secondary" onclick="closeModal('modal-dispatch')">Cancel</button>
        <button type="submit" class="btn-primary">&#128640; Dispatch Now</button>
      </div>
    </form>
  </div>
</div>

<?php elseif($page==='operations'||$page==='incidents'||$page==='inspection'||$page==='maintenance'||$page==='fuel'): ?>
<!-- ══ OPERATIONS HUB ══ -->
<?php
  $opsTab = $_GET['tab'] ?? 'health';
  if($page==='inspection')  $opsTab='health';
  if($page==='incidents')   $opsTab='incidents';
  if($page==='maintenance') $opsTab='maintenance';
  if($page==='fuel')        $opsTab='fuel';
  $openIncBadge=count(array_filter($incidents,fn($i)=>in_array($i['status']??'Open',['Open','In Progress','Assessed','Budget Pending'])));
?>
<div class="page-header-row" style="margin-bottom:0;">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Operations</div><div class="page-title">Operations Hub</div><div class="page-sub">Health checks &middot; Incidents &middot; Maintenance &middot; Fuel</div></div>
</div>
<div style="display:flex;gap:0;border-bottom:2px solid #dceade;margin-bottom:22px;margin-top:16px;overflow-x:auto;">
  <?php foreach(['health'=>['🔍','Health Check'],'incidents'=>['🚨','Incidents'],'maintenance'=>['🔧','Maintenance'],'fuel'=>['⛽','Fuel Log']] as $tk=>[$tic,$tlb]): $active=$opsTab===$tk; ?>
  <a href="fvm.php?page=operations&tab=<?=$tk?>" style="text-decoration:none;padding:10px 22px;font-size:13px;font-weight:700;color:<?=$active?'#256427':'#8fa592'?>;border-bottom:<?=$active?'3px solid #256427':'3px solid transparent'?>;background:<?=$active?'#f6faf7':'transparent'?>;white-space:nowrap;display:flex;align-items:center;gap:6px;position:relative;top:2px;">
    <?=$tic?> <?=$tlb?>
    <?php if($tk==='incidents'&&$openIncBadge>0):?><span style="background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:100px;margin-left:2px;"><?=$openIncBadge?></span><?php endif;?>
  </a>
  <?php endforeach; ?>
</div>
<?php if($opsTab==='health'): ?>
<!-- ══ INSPECTION ══ -->
<?php
  $newOpenInc   = count(array_filter($incidents, fn($i)=>$i['status']==='Open'));
  $flaggedVehicles = array_values(array_filter($vehicles, fn($v)=>$v['flagged']));
  $flaggedCount = count($flaggedVehicles);
?>
<?php if($flaggedCount>0): ?>
<div style="background:#fef2f2;border:2px solid #dc2626;border-radius:12px;padding:14px 18px;margin-bottom:16px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <div style="font-weight:800;color:#dc2626;font-size:14px;">🚩 <?=$flaggedCount?> Vehicle<?=$flaggedCount>1?'s':''?> Flagged — Pending Inspection</div>
      <div style="font-size:12px;color:#991b1b;margin-top:4px;">
        <?=implode(', ', array_map(fn($v)=>'<strong>'.$v['plate'].'</strong>', $flaggedVehicles))?>
        · Status set to <strong>In Maintenance</strong> · Maintenance request auto-created
      </div>
    </div>
    <a href="fvm.php?page=operations&tab=maintenance" class="btn-danger" style="font-size:12px;padding:7px 16px;text-decoration:none;white-space:nowrap;">🔧 View Maintenance →</a>
  </div>
</div>
<?php endif; ?>
<?php if($newOpenInc>0): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
  <div style="font-weight:700;color:#dc2626;">🚨 <?=$newOpenInc?> Open Incident<?=$newOpenInc>1?'s':''?> Awaiting Review</div>
  <a href="fvm.php?page=operations&tab=incidents" class="btn-danger" style="font-size:12px;padding:6px 14px;text-decoration:none;">Review Incidents →</a>
</div>
<?php endif; ?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Health Check</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <div style="display:flex;gap:8px;">
    <a href="fvm.php?page=operations&tab=incidents" class="btn-warning" style="text-decoration:none;">🚨 Incidents (<?=$newOpenInc?> open)</a>
    <button class="btn-primary" onclick="openModal('modal-incident')">⚠️ Log Incident</button>
  </div>
</div>
<div class="grid-3" style="margin-bottom:20px;">
  <?php foreach($vehicles as $v):
    $isFlagged = $v['flagged'];
    $cardBorder = $isFlagged ? 'border:2px solid #dc2626;' : '';
  ?>
  <div class="card" style="padding:18px;<?=$cardBorder?>position:relative;">
    <?php if($isFlagged): ?>
    <div style="position:absolute;top:-1px;left:0;right:0;background:#dc2626;color:#fff;font-size:10px;font-weight:800;text-align:center;padding:3px 0;border-radius:14px 14px 0 0;letter-spacing:.8px;text-transform:uppercase;">🚩 FLAGGED — REQUIRES INSPECTION</div>
    <div style="margin-top:18px;"></div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <div><div style="font-weight:700;font-size:14px;"><?=e($v['plate'])?></div><div style="font-size:11.5px;color:#8fa592;"><?=e($v['make'])?> <?=e($v['model'])?></div></div>
      <?=badge($v['status'],statusColor($v['status']))?>
    </div>
    <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
      <div><div class="info-label">Fuel</div><?=fuelBar($v['fuel_level'])?><div style="font-size:11px;color:#627065;margin-top:3px;"><?=$v['fuel_level']?>%</div></div>
      <div><div class="info-label">Mileage</div><div style="font-size:12.5px;font-weight:600;"><?=number_format($v['mileage'])?> km</div></div>
    </div>
    <div style="margin-bottom:10px;"><div class="info-label">Last Inspection</div><div style="font-size:12px;<?=($v['last_inspection']&&isExpired(date('Y-m-d',strtotime($v['last_inspection'].' +30 days'))))?'color:#dc2626':'color:#627065'?>"><?=$v['last_inspection']?:'-'?></div></div>
    <?php if($isFlagged): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 10px;font-size:11.5px;color:#991b1b;font-weight:600;margin-bottom:8px;">
      ⚠️ Maintenance request auto-created · Status: <strong>In Maintenance</strong>
      <div style="margin-top:4px;"><a href="fvm.php?page=operations&tab=maintenance" style="color:#dc2626;font-weight:700;font-size:11px;">View in Maintenance tab →</a></div>
    </div>
    <?php endif; ?>
    <button onclick="openInspect('<?=e($v['id'])?>','<?=e($v['plate'])?>',<?=$v['fuel_level']?>,<?=$v['mileage']?>)" class="btn-<?=$isFlagged?'danger':'primary'?>" style="width:100%;font-size:11.5px;padding:8px;"><?=$isFlagged?'🔍 Inspect Now (Flagged)':'🔍 Inspect'?></button>
  </div>
  <?php endforeach; ?>
</div>
<!-- Recent incidents -->
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>🚨 Recent Incidents <a href="fvm.php?page=operations&tab=incidents" style="font-size:11px;color:#256427;text-decoration:none;font-weight:600;margin-left:10px;">View All →</a></div>
  <?php if(empty($incidents)): ?><div style="color:#8fa592;font-size:13px;">No incidents recorded.</div>
  <?php else: ?>
  <table class="data-table"><thead><tr><?php foreach(['Code','Vehicle','Driver','Type','Severity','Date','Photo','Status',''] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead><tbody>
  <?php foreach(array_slice($incidents,0,8) as $i):
    $vi=findById($vehicles,$i['vehicle_id']);
    $di=findById($drivers,$i['driver_id']??null);
    // Resolve driver via trip fallback
    if(!$di){
      if(!empty($i['trip_id'])){ foreach($trips as $t){ if($t['id']===$i['trip_id']){ $di=findById($drivers,$t['driver_id']??null); break; } } }
      if(!$di && !empty($i['vehicle_id'])){ foreach($trips as $t){ if($t['vehicle_id']===$i['vehicle_id']&&$t['scheduled_date']===$i['incident_date']){ $di=findById($drivers,$t['driver_id']??null); break; } } }
    }
    $ps=$i['photo_url']??''; $hp=!empty($ps)&&(str_starts_with($ps,'data:image')||str_starts_with($ps,'http'));
  ?>
  <tr style="<?=$i['status']==='Open'?'background:#fff9f9':''?>">
    <td style="color:#2e7d32;font-weight:600;"><?=e($i['incident_code']??'')?></td>
    <td><?=e($vi?$vi['plate']:'?')?></td>
    <td><?=e($di?$di['full_name']:'—')?><div style="font-size:10px;color:#8fa592;"><?=e($di?($di['driver_code']??''):'')?></div></td>
    <td><?=e($i['incident_type'])?></td>
    <td><?=!empty($i['severity'])?badge($i['severity'],['Minor'=>'#8fa592','Moderate'=>'#d97706','Major'=>'#dc2626','Critical'=>'#7f1d1d'][$i['severity']]??'#627065'):'—'?></td>
    <td style="color:#8fa592;"><?=e($i['incident_date'])?></td>
    <td>
      <?php if($hp): ?>
      <img src="<?=e($ps)?>"
           data-photo="<?=e($ps)?>"
           data-caption="<?=e($i['incident_code']??'')?> · <?=e($i['incident_type']??'')?>"
           class="photo-thumb"
           style="width:38px;height:38px;object-fit:cover;border-radius:5px;border:1px solid #dceade;cursor:pointer;display:block;"
           title="Click to view photo" alt="📷">
      <?php else: ?>—<?php endif; ?>
    </td>
    <td><?=badge($i['status'],statusColor($i['status']))?></td>
    <td><a href="fvm.php?page=operations&tab=incidents&action=view&id=<?=e($i['id'])?>" class="btn-blue sm" style="text-decoration:none;">Review</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<!-- Inspect modal -->
<div class="modal-overlay" id="modal-inspect">
  <div class="modal" style="max-width:460px;">
    <div class="modal-title" id="inspect-title">🔍 Daily Inspection</div>
    <form method="POST"><input type="hidden" name="action" value="submit_inspection"><input type="hidden" name="vehicle_id" id="inspect-vid">
      <div class="form-row">
        <div class="form-group"><label>Driver</label><select name="driver_id" class="form-control"><option value="">— None —</option><?php foreach($drivers as $d):?><option value="<?=e($d['id'])?>"><?=e($d['full_name'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Result</label><select name="result" id="inspect-result" class="form-control" onchange="checkFail(this.value)"><option value="OK">✅ OK</option><option value="Advisory">⚠️ Advisory</option><option value="FAIL">❌ FAIL</option></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Fuel Level (%)</label><input type="number" name="fuel_level" id="inspect-fuel" class="form-control" min="0" max="100" required></div>
        <div class="form-group"><label>Odometer (km)</label><input type="number" name="odometer_km" id="inspect-mileage" class="form-control" required></div>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
      <div id="fail-warning" style="display:none;" class="alert-error" style="margin-bottom:10px;">⚠️ Vehicle will be flagged and removed from dispatch.</div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-inspect')">Cancel</button><button type="submit" class="btn-primary">💾 Submit</button></div>
    </form>
  </div>
</div>
<!-- Incident modal -->
<div class="modal-overlay" id="modal-incident">
  <div class="modal" style="max-width:500px;">
    <div class="modal-title">⚠️ Log Incident</div>
    <form method="POST"><input type="hidden" name="action" value="log_incident">
      <div class="form-row">
        <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php foreach($vehicles as $v):?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Driver</label><select name="driver_id" class="form-control"><option value="">— None —</option><?php foreach($drivers as $d):?><option value="<?=e($d['id'])?>"><?=e($d['full_name'])?></option><?php endforeach;?></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Type</label><select name="incident_type" class="form-control"><option>Minor Breakdown</option><option>Major Breakdown</option><option>Accident</option><option>Traffic Violation</option><option>Near Miss</option><option>Other</option></select></div>
        <div class="form-group"><label>Date</label><input type="date" name="incident_date" class="form-control" value="<?=$today?>"></div>
      </div>
      <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="3" required></textarea></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-incident')">Cancel</button><button type="submit" class="btn-primary">💾 Log</button></div>
    </form>
  </div>
</div>

<?php elseif($opsTab==='incidents'): ?>
<!-- ══════════════════════════════════════════════════ INCIDENT MANAGER ══ -->
<?php
  $viewId = $_GET['id'] ?? null;
  $viewInc = $viewId ? (sbGet('fvm_incidents',['id'=>'eq.'.$viewId,'select'=>'*'])[0]??null) : null;
  $statusColors=['Open'=>'#dc2626','In Progress'=>'#d97706','Assessed'=>'#1d4ed8','Budget Pending'=>'#7c3aed','Closed'=>'#2e7d32'];
  $severityColors=['Minor'=>'#8fa592','Moderate'=>'#d97706','Major'=>'#dc2626','Critical'=>'#7f1d1d'];

  if($viewInc):
    $vInc=findById($vehicles,$viewInc['vehicle_id']);
    $dInc=findById($drivers,$viewInc['driver_id']??null);

    // Resolve which driver was using the vehicle at the time
    $incTrip=null; $incTripDriver=null;
    if(!empty($viewInc['trip_id'])){
      $tRows=sbGet('fvm_trips',['id'=>'eq.'.$viewInc['trip_id'],'select'=>'*']);
      $incTrip=$tRows[0]??null;
      if($incTrip) $incTripDriver=findById($drivers,$incTrip['driver_id']??null);
    }
    if(!$incTrip && !empty($viewInc['vehicle_id'])){
      foreach($trips as $t){
        if($t['vehicle_id']===$viewInc['vehicle_id'] && $t['scheduled_date']===$viewInc['incident_date']){ $incTrip=$t; break; }
      }
      if(!$incTrip){
        $dayBefore=date('Y-m-d',strtotime($viewInc['incident_date'].' -1 day'));
        foreach($trips as $t){
          if($t['vehicle_id']===$viewInc['vehicle_id'] && $t['scheduled_date']===$dayBefore){ $incTrip=$t; break; }
        }
      }
      if($incTrip) $incTripDriver=findById($drivers,$incTrip['driver_id']??null);
    }
    if(!$dInc && $incTripDriver) $dInc=$incTripDriver;
    $resolvedDriver=$dInc??$incTripDriver;

    // Parse damage items
    $damageLines=!empty($viewInc['damage_items'])?explode(';',trim($viewInc['damage_items'])):[];
    $parsedDamage=[]; foreach($damageLines as $line){ if(trim($line)){$parts=explode('|',$line);$parsedDamage[]=[$parts[0]??'',floatval($parts[1]??0)];} }
?>
<!-- ══ SINGLE INCIDENT VIEW ══ -->
<div style="margin-bottom:18px;"><a href="fvm.php?page=operations&tab=incidents" style="font-size:13px;color:#256427;text-decoration:none;font-weight:600;">← Back to All Incidents</a></div>
<div class="page-header-row" style="flex-wrap:wrap;gap:14px;">
  <div>
    <div class="eyebrow"><span class="eyebrow-bar"></span>Incident Review</div>
    <div class="page-title"><?=e($viewInc['incident_code']??'Incident')?></div>
    <div class="page-sub"><?=e($viewInc['incident_type'])?> · Reported <?=e($viewInc['incident_date'])?></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <?=badge($viewInc['status'],$statusColors[$viewInc['status']]??'#627065')?>
    <?php if(!empty($viewInc['severity'])):?><?=badge($viewInc['severity'],$severityColors[$viewInc['severity']]??'#627065')?><?php endif;?>
  </div>
</div>

<div class="grid-2" style="margin-bottom:18px;">
  <!-- Incident Info -->
  <div class="card">
    <div class="card-title"><span class="card-title-bar"></span>📋 Incident Details</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">

      <!-- Vehicle -->
      <div>
        <div class="info-label">🚛 Vehicle</div>
        <div class="info-value" style="font-weight:700;"><?=e($vInc?$vInc['plate']:'Unknown')?></div>
        <div style="font-size:11px;color:#8fa592;"><?=e($vInc?$vInc['make'].' '.$vInc['model'].' · '.$vInc['vehicle_code']:'')?></div>
      </div>

      <!-- Driver resolved from incident or trip -->
      <div>
        <div class="info-label">👤 Driver at Time of Incident</div>
        <?php if($resolvedDriver): ?>
        <div class="info-value" style="font-weight:700;color:#1b5e20;"><?=e($resolvedDriver['full_name'])?></div>
        <div style="font-size:11px;color:#8fa592;">
          <?=e($resolvedDriver['driver_code']??'')?>
          <?php if(!$dInc && $incTripDriver): ?>
          &nbsp;<span style="color:#d97706;font-weight:600;">· via Trip <?=e($incTrip['trip_code']??'')?></span>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="info-value" style="color:#dc2626;">No driver linked</div>
        <div style="font-size:11px;color:#8fa592;">No trip found on <?=e($viewInc['incident_date'])?>.</div>
        <?php endif; ?>
      </div>

      <div><div class="info-label">Type</div><div class="info-value"><?=e($viewInc['incident_type'])?></div></div>
      <div><div class="info-label">Date</div><div class="info-value"><?=e($viewInc['incident_date'])?></div></div>

      <?php if($incTrip): ?>
      <div>
        <div class="info-label">🗺️ Active Trip</div>
        <div class="info-value" style="font-weight:700;"><?=e($incTrip['trip_code']??'—')?></div>
        <div style="font-size:11px;color:#8fa592;"><?=e(mb_substr($incTrip['origin']??'',0,16))?> → <?=e(mb_substr($incTrip['destination']??'',0,16))?></div>
      </div>
      <?php endif; ?>

      <?php if(!empty($viewInc['lat'])&&!empty($viewInc['lng'])): ?>
      <div style="grid-column:span 2;">
        <div class="info-label">📍 GPS Location</div>
        <div class="info-value" style="font-family:monospace;font-size:12px;">
          <?=e($viewInc['lat'])?>, <?=e($viewInc['lng'])?>
          <a href="https://maps.google.com/?q=<?=e($viewInc['lat'])?>,<?=e($viewInc['lng'])?>" target="_blank"
             style="color:#256427;font-size:11px;margin-left:8px;font-weight:600;">Open in Maps →</a>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-bottom:14px;">
      <div class="info-label">Description</div>
      <div style="font-size:13px;color:#1a2b1c;line-height:1.6;background:#f6faf7;border-radius:8px;padding:10px 12px;margin-top:4px;"><?=nl2br(e($viewInc['description']))?></div>
    </div>

    <?php if(!empty($viewInc['photo_url'])): ?>
    <?php $vPhotoSrc=$viewInc['photo_url']; $vHasPhoto=str_starts_with($vPhotoSrc,'data:image')||str_starts_with($vPhotoSrc,'http'); ?>
    <div>
      <div class="info-label" style="margin-bottom:8px;">📷 Photo Evidence</div>
      <?php if($vHasPhoto): ?>
      <div style="position:relative;display:inline-block;cursor:pointer;max-width:100%;">
        <img src="<?=e($vPhotoSrc)?>"
             data-photo="<?=e($vPhotoSrc)?>"
             data-caption="<?=e($viewInc['incident_code']??'Incident')?> · <?=e($viewInc['incident_type'])?>"
             class="photo-thumb"
             style="max-width:100%;max-height:280px;object-fit:cover;border-radius:10px;border:2px solid #dceade;display:block;" alt="Incident photo">
        <div style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.55);color:#fff;
                    font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;pointer-events:none;">
          🔍 Click to enlarge
        </div>
      </div>
      <?php else: ?>
      <div style="font-size:12px;color:#8fa592;"><?=e($vPhotoSrc)?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Status & Actions -->
  <div>
    <div class="card" style="margin-bottom:14px;">
      <div class="card-title"><span class="card-title-bar"></span>🔄 Review & Actions</div>
      <?php if($viewInc['status']==='Open'): ?>
      <form method="POST">
        <input type="hidden" name="action" value="review_incident">
        <input type="hidden" name="incident_id" value="<?=e($viewInc['id'])?>">
        <div class="form-group"><label>Reviewed By</label><input type="text" name="reviewed_by" class="form-control" value="Admin" required></div>
        <div class="form-group"><label>Review Notes</label><textarea name="review_notes" class="form-control" rows="3" placeholder="Initial review notes..."></textarea></div>
        <button type="submit" class="btn-blue" style="width:100%;">🔍 Send to Maintenance for Inspection</button>
      </form>
      <?php elseif(in_array($viewInc['status'],['In Progress','Under Review'])): ?>
      <?php if($viewInc['resolution_notes']): ?><div style="font-size:12.5px;color:#627065;background:#f6faf7;padding:8px 12px;border-radius:8px;margin-bottom:12px;"><?=nl2br(e($viewInc['resolution_notes']))?></div><?php endif;?>
      <div class="alert-warning" style="margin-bottom:12px;"><strong>⏳ Awaiting Damage Assessment</strong><div style="font-size:12px;margin-top:4px;">Maintenance team is inspecting the vehicle. The damage checklist below must be saved before a budget can be requested.</div></div>
      <?php elseif($viewInc['status']==='Assessed'): ?>
      <?php if($viewInc['resolution_notes']): ?><div style="font-size:12.5px;color:#627065;background:#f6faf7;padding:8px 12px;border-radius:8px;margin-bottom:12px;"><?=nl2br(e($viewInc['resolution_notes']))?></div><?php endif;?>
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px;margin-bottom:14px;">
        <div style="font-weight:700;color:#92400e;margin-bottom:4px;">💰 Total Damage Cost (Assessed by Maintenance)</div>
        <div style="font-family:'DM Serif Display',serif;font-size:28px;color:#dc2626;font-weight:700;"><?=peso($viewInc['damage_total']??0)?></div>
        <div style="font-size:11px;color:#92400e;margin-top:4px;">This amount will be sent to Maintenance as the budget request.</div>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="request_budget">
        <input type="hidden" name="incident_id" value="<?=e($viewInc['id'])?>">
        <?php $dmgConfirm = number_format($viewInc['damage_total']??0,2); ?>
        <button type="submit" class="btn-warning" style="width:100%;margin-bottom:8px;" onclick="return confirm('Request budget approval for ₱<?=$dmgConfirm?>?')">💳 Request Budget Approval (<?=peso($viewInc['damage_total']??0)?>)</button>
      </form>
      <form method="POST"><input type="hidden" name="action" value="close_incident"><input type="hidden" name="incident_id" value="<?=e($viewInc['id'])?>">
      <button type="submit" class="btn-secondary" style="width:100%;font-size:12px;" onclick="return confirm('Close this incident?')">✓ Close Without Budget</button></form>
      <?php elseif($viewInc['status']==='Budget Pending'): ?>
      <div class="alert-warning"><div style="font-weight:700;">⏳ Budget Approval Requested</div><div style="font-size:12px;margin-top:4px;">A maintenance request has been created. Go to <a href="fvm.php?page=operations&tab=maintenance" style="color:#256427;">Maintenance →</a> to approve/reject funding.</div></div>
      <div style="margin-top:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;">
        <div style="font-size:11px;color:#991b1b;font-weight:700;text-transform:uppercase;letter-spacing:.6px;">Requested Budget</div>
        <div style="font-family:'DM Serif Display',serif;font-size:26px;color:#dc2626;font-weight:700;"><?=peso($viewInc['damage_total']??0)?></div>
      </div>
      <?php elseif($viewInc['status']==='Closed'): ?>
      <div style="text-align:center;padding:20px;color:#2e7d32;"><div style="font-size:24px;margin-bottom:8px;">✅</div><div style="font-weight:700;">Incident Closed</div><?php if($viewInc['damage_total']>0):?><div style="margin-top:8px;font-size:13px;color:#627065;">Total cost: <?=peso($viewInc['damage_total'])?> — expensed.</div><?php endif;?></div>
      <?php endif; ?>
    </div>

    <?php if(in_array($viewInc['status'],['In Progress','Under Review','Assessed'])): ?>
    <!-- Map snippet if GPS available -->
    <?php if(!empty($viewInc['lat'])&&!empty($viewInc['lng'])): ?>
    <div class="card" style="padding:0;overflow:hidden;margin-bottom:14px;">
      <div id="inc-mini-map" style="height:180px;"></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ══ DAMAGE ASSESSMENT FORM ══ -->
<?php if(in_array($viewInc['status'],['In Progress','Under Review','Assessed'])): ?>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>🔧 Physical Damage Assessment — Maintenance Inspector Checklist</div>
  <p style="font-size:13px;color:#627065;margin-bottom:16px;">Maintenance team: inspect the vehicle and check each damaged component. Enter the estimated repair/replacement cost. Saving will set the status to <strong>Assessed</strong> so admin can request budget approval.</p>
  <form method="POST" id="damage-form">
    <input type="hidden" name="action" value="save_damage_assessment">
    <input type="hidden" name="incident_id" value="<?=e($viewInc['id'])?>">

    <!-- Pre-loaded checkboxes for common damage areas -->
    <?php
    $commonItems=[
      'Bumper (Front)','Bumper (Rear)','Hood / Bonnet','Trunk / Boot',
      'Left Front Door','Right Front Door','Left Rear Door','Right Rear Door',
      'Left Front Fender','Right Front Fender','Left Rear Fender','Right Rear Fender',
      'Windshield (Front)','Windshield (Rear)','Left Window','Right Window',
      'Headlights (Left)','Headlights (Right)','Taillights (Left)','Taillights (Right)',
      'Left Front Tire','Right Front Tire','Left Rear Tire','Right Rear Tire',
      'Undercarriage','Engine Bay','Transmission','Frame/Chassis',
      'Interior — Dashboard','Interior — Seats','Airbags Deployed',
    ];
    // Pre-fill existing damage items
    $existingMap=[];
    foreach($parsedDamage as [$item,$cost]) $existingMap[$item]=$cost;
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-bottom:20px;">
      <?php foreach($commonItems as $ci): $preVal=$existingMap[$ci]??0; ?>
      <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #dceade;border-radius:10px;cursor:pointer;background:#fff;transition:background 150ms;" onmouseenter="this.style.background='#f0fdf4'" onmouseleave="this.style.background=this.querySelector('input[type=checkbox]').checked?'#f0fdf4':'#fff'">
        <input type="checkbox" name="damage_items[]" value="<?=e($ci)?>" <?=$preVal>0?'checked':''?> onchange="this.closest('label').style.background=this.checked?'#f0fdf4':'#fff';toggleCostField(this)" style="width:16px;height:16px;accent-color:#256427;flex-shrink:0;">
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;color:#0d150e;"><?=e($ci)?></div>
          <div class="cost-field" style="margin-top:6px;display:<?=$preVal>0?'block':'none'?>">
            <input type="number" name="damage_costs[]" value="<?=$preVal?>" step="0.01" min="0" placeholder="₱ cost" style="width:100%;padding:5px 8px;border:1px solid #dceade;border-radius:7px;font-size:12px;" <?= $preVal>0 ? '' : 'disabled' ?> oninput="recalcTotal()">
          </div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <!-- Custom items -->
    <div style="margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;color:#627065;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;">➕ Additional Damage Items</div>
      <div id="custom-items">
        <div class="custom-item" style="display:flex;gap:8px;margin-bottom:6px;">
          <input type="text" name="damage_items[]" placeholder="Describe damage item..." class="form-control" style="flex:2;" oninput="recalcTotal()">
          <input type="number" name="damage_costs[]" placeholder="₱ cost" step="100" class="form-control" style="flex:1;" oninput="recalcTotal()">
        </div>
      </div>
      <button type="button" onclick="addCustomItem()" class="btn-secondary" style="font-size:11.5px;padding:6px 14px;margin-top:4px;">+ Add Row</button>
    </div>

    <!-- Total -->
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px 20px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;">
      <div><div style="font-size:11px;color:#991b1b;font-weight:700;text-transform:uppercase;letter-spacing:.6px;">Estimated Total Repair Cost</div><div style="font-size:11px;color:#9ca3af;margin-top:2px;">Sum of all checked damage items</div></div>
      <div style="font-family:'DM Serif Display',serif;font-size:28px;color:#dc2626;font-weight:700;" id="damage-total">₱<?=number_format($viewInc['damage_total']??0,2)?></div>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button type="submit" class="btn-primary">💾 Save Damage Assessment</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if(!empty($viewInc['lat'])&&!empty($viewInc['lng'])): ?>
<script>
window.addEventListener('DOMContentLoaded',function(){
  var m=L.map('inc-mini-map',{zoomControl:false,attributionControl:false}).setView([<?=e($viewInc['lat'])?>,<?=e($viewInc['lng'])?>],15);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(m);
  L.marker([<?=e($viewInc['lat'])?>,<?=e($viewInc['lng'])?>]).addTo(m).bindPopup('📍 Incident Location').openPopup();
});
</script>
<?php endif; ?>

<script>
function toggleCostField(cb){
  var cf=cb.closest('label').querySelector('.cost-field');
  var inp=cf?.querySelector('input');
  if(cf){
    cf.style.display=cb.checked?'block':'none';
    if(inp){
      inp.disabled = !cb.checked;
      if(!cb.checked){ inp.value = 0; }
    }
  }
  recalcTotal();
}
function recalcTotal(){
  var total=0;
  document.querySelectorAll('[name="damage_costs[]"]').forEach(function(el){
    var cb=el.closest('label')?.querySelector('input[type=checkbox]');
    if(cb && !cb.checked) return;
    if(el.disabled) return;
    total += parseFloat(el.value) || 0;
  });
  var el=document.getElementById('damage-total');
  if(el) el.textContent='₱'+total.toLocaleString('en-PH',{minimumFractionDigits:2});
}
function addCustomItem(){
  var div=document.createElement('div'); div.className='custom-item'; div.style='display:flex;gap:8px;margin-bottom:6px;';
  div.innerHTML='<input type="text" name="damage_items[]" placeholder="Describe damage item..." class="form-control" style="flex:2;" oninput="recalcTotal()"><input type="number" name="damage_costs[]" placeholder="₱ cost" step="0.01" min="0" class="form-control" style="flex:1;" oninput="recalcTotal()"><button type="button" onclick="this.parentElement.remove();recalcTotal()" class="btn-danger" style="padding:6px 10px;font-size:12px;">✕</button>';
  document.getElementById('custom-items').appendChild(div);
}
document.addEventListener('DOMContentLoaded',function(){ recalcTotal(); });
</script>

<?php else: // ═══ INCIDENT LIST VIEW ═══ ?>
<div class="page-header-row">
  <div>
    <div class="eyebrow"><span class="eyebrow-bar"></span>Incident Manager</div>
    <div class="page-title"><?=e($ptitle)?></div>
    <div class="page-sub"><?=e($psub)?></div>
  </div>
  <button class="btn-primary" onclick="openModal('modal-incident')">⚠️ Log Incident</button>
</div>

<!-- Stat row -->
<div class="flex-wrap" style="margin-bottom:22px;">
  <?php
  $iOpen  = count(array_filter($incidents,fn($i)=>$i['status']==='Open'));
  $iIP    = count(array_filter($incidents,fn($i)=>$i['status']==='In Progress'));
  $iAss   = count(array_filter($incidents,fn($i)=>$i['status']==='Assessed'));
  $iBP    = count(array_filter($incidents,fn($i)=>$i['status']==='Budget Pending'));
  $iCl    = count(array_filter($incidents,fn($i)=>$i['status']==='Closed'));
  foreach([['Open',$iOpen,'#dc2626'],['In Progress',$iIP,'#d97706'],['Assessed',$iAss,'#1d4ed8'],['Budget Pending',$iBP,'#7c3aed'],['Closed',$iCl,'#2e7d32']] as [$l,$v,$c]):?>
  <div class="stat-box"><div class="stat-value" style="color:<?=$c?>"><?=$v?></div><div class="stat-label"><?=$l?></div></div>
  <?php endforeach;?>
</div>

<!-- Filter tabs -->
<?php $iFilter=$_GET['ifilter']??'all'; ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;">
  <?php foreach(['all'=>'All','Open'=>'🔴 Open','In Progress'=>'🟡 In Progress','Assessed'=>'🔵 Assessed','Budget Pending'=>'🟣 Budget Pending','Closed'=>'✅ Closed'] as $fk=>$fl): ?>
  <a href="fvm.php?page=incidents&ifilter=<?=urlencode($fk)?>" style="text-decoration:none;font-size:11.5px;padding:5px 12px;border-radius:20px;border:1px solid #dceade;background:<?=$iFilter===$fk?'#256427':'#fff'?>;color:<?=$iFilter===$fk?'#fff':'#256427'?>;"><?=$fl?></a>
  <?php endforeach; ?>
</div>

<?php
  $filteredInc = $iFilter==='all' ? $incidents : array_values(array_filter($incidents,fn($i)=>$i['status']===$iFilter));
?>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>🚨 Incident Reports (<?=count($filteredInc)?>)</div>
  <?php if(empty($filteredInc)): ?>
  <div style="color:#8fa592;font-size:13px;text-align:center;padding:24px;">No incidents in this category.</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><?php foreach(['Code','Type','Severity','Vehicle','Driver','Date','GPS','Photo','Damage','Status','Actions'] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead>
    <tbody>
    <?php foreach($filteredInc as $i):
      $vi  = findById($vehicles,$i['vehicle_id']);
      $di  = findById($drivers,$i['driver_id']??null);
      // Resolve driver via trip if not directly linked
      if(!$di){
        if(!empty($i['trip_id'])){
          foreach($trips as $t){ if($t['id']===$i['trip_id']){ $di=findById($drivers,$t['driver_id']??null); break; } }
        }
        if(!$di && !empty($i['vehicle_id']) && !empty($i['incident_date'])){
          foreach($trips as $t){
            if($t['vehicle_id']===$i['vehicle_id'] && $t['scheduled_date']===$i['incident_date']){
              $di=findById($drivers,$t['driver_id']??null); break;
            }
          }
        }
      }
      $sc  = $statusColors[$i['status']]??'#627065';
      $svc = $severityColors[$i['severity']??'Minor']??'#8fa592';
      $photoSrc = $i['photo_url']??'';
      $hasPhoto = !empty($photoSrc) && (str_starts_with($photoSrc,'data:image')||str_starts_with($photoSrc,'http'));
    ?>
    <tr style="<?=$i['status']==='Open'?'background:#fff9f9':''?>">
      <td style="color:#2e7d32;font-weight:700;"><?=e($i['incident_code']??'')?></td>
      <td style="font-weight:600;"><?=e($i['incident_type'])?></td>
      <td><?=!empty($i['severity'])?badge($i['severity'],$svc):'—'?></td>
      <td><?=e($vi?$vi['plate']:'?')?><div style="font-size:10px;color:#8fa592;"><?=e($vi?$vi['make'].' '.$vi['model']:'')?></div></td>
      <td>
        <?php if($di): ?>
        <span style="font-weight:600;"><?=e($di['full_name'])?></span>
        <div style="font-size:10px;color:#8fa592;"><?=e($di['driver_code']??'')?></div>
        <?php else: ?><span style="color:#ccc;">—</span><?php endif; ?>
      </td>
      <td style="color:#8fa592;"><?=e($i['incident_date'])?></td>
      <!-- GPS: clickable link if coords exist -->
      <td>
        <?php if(!empty($i['lat'])&&!empty($i['lng'])): ?>
        <a href="https://maps.google.com/?q=<?=e($i['lat'])?>,<?=e($i['lng'])?>" target="_blank"
           style="color:#256427;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;"
           title="<?=e($i['lat'])?>, <?=e($i['lng'])?>">📍 View</a>
        <?php else: ?><span style="color:#ddd;font-size:11px;">—</span><?php endif; ?>
      </td>
      <!-- Photo: thumbnail if available, clickable to lightbox -->
      <td>
        <?php if($hasPhoto): ?>
        <img src="<?=e($photoSrc)?>"
             data-photo="<?=e($photoSrc)?>"
             data-caption="<?=e($i['incident_code']??'Incident')?> · <?=e($i['incident_type'])?>"
             class="photo-thumb"
             style="width:42px;height:42px;object-fit:cover;border-radius:6px;
                    border:2px solid #dceade;cursor:pointer;display:block;"
             title="Click to view full photo" alt="📷">
        <?php elseif(!empty($photoSrc)): ?>
        <span style="color:#8fa592;font-size:11px;">📷</span>
        <?php else: ?><span style="color:#ddd;font-size:11px;">—</span><?php endif; ?>
      </td>
      <td><?=!empty($i['damage_total'])&&$i['damage_total']>0?'<span style="color:#dc2626;font-weight:700;font-size:12px;">'.peso($i['damage_total']).'</span>':'<span style="color:#ddd;">—</span>'?></td>
      <td><?=badge($i['status'],$sc)?></td>
      <td>
        <a href="fvm.php?page=operations&tab=incidents&action=view&id=<?=e($i['id'])?>" class="btn-blue sm" style="text-decoration:none;white-space:nowrap;">🔍 Review</a>
        <?php if($i['status']==='Open'): ?>
        <span style="display:inline-block;width:8px;height:8px;background:#dc2626;border-radius:50%;margin-left:4px;animation:pulse 1.5s infinite;vertical-align:middle;"></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Incident Modal (admin-logged) -->
<div class="modal-overlay" id="modal-incident">
  <div class="modal" style="max-width:500px;">
    <div class="modal-title">⚠️ Log Incident</div>
    <form method="POST"><input type="hidden" name="action" value="log_incident">
      <div class="form-row">
        <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php foreach($vehicles as $v):?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Driver</label><select name="driver_id" class="form-control"><option value="">— None —</option><?php foreach($drivers as $d):?><option value="<?=e($d['id'])?>"><?=e($d['full_name'])?></option><?php endforeach;?></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Type</label><select name="incident_type" class="form-control"><option>Minor Breakdown</option><option>Major Breakdown</option><option>Accident</option><option>Traffic Violation</option><option>Near Miss</option><option>Flat Tire</option><option>Engine Problem</option><option>Other</option></select></div>
        <div class="form-group"><label>Severity</label><select name="severity" class="form-control"><option>Minor</option><option>Moderate</option><option>Major</option><option>Critical</option></select></div>
      </div>
      <div class="form-group"><label>Date</label><input type="date" name="incident_date" class="form-control" value="<?=$today?>"></div>
      <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="3" required></textarea></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-incident')">Cancel</button><button type="submit" class="btn-primary">💾 Log</button></div>
    </form>
  </div>
</div>

<?php endif; // end list/view ?>

<?php elseif($opsTab==='maintenance'): ?>
<!-- ══ MAINTENANCE ══ -->
<?php
  $flaggedPendingMaint = array_values(array_filter($maintenance, function($m) use ($vehicles) {
    $mv = findById($vehicles, $m['vehicle_id']);
    return $m['status']==='Pending' && $mv && $mv['flagged'];
  }));
  $flaggedPendingCount = count($flaggedPendingMaint);
?>
<?php if($flaggedPendingCount>0): ?>
<div style="background:#fff7ed;border:2px solid #f97316;border-radius:12px;padding:14px 18px;margin-bottom:18px;">
  <div style="font-weight:800;color:#c2410c;font-size:14px;margin-bottom:6px;">🚩 <?=$flaggedPendingCount?> Flagged Vehicle<?=$flaggedPendingCount>1?'s':''?> Awaiting Maintenance Approval</div>
  <div style="font-size:12px;color:#9a3412;">
    <?php foreach($flaggedPendingMaint as $fm): $fmv=findById($vehicles,$fm['vehicle_id']); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #fed7aa;border-radius:8px;padding:8px 12px;margin-top:6px;">
      <span><strong><?=e($fmv?$fmv['plate']:'?')?></strong> — <?=e($fm['maint_type'])?></span>
      <span style="color:#dc2626;font-weight:700;"><?=e($fm['scheduled_date'])?> · Est: <?=peso($fm['estimated_cost'])?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Maintenance</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <button class="btn-primary" onclick="openModal('modal-maint')">+ Request</button>
</div>
<div class="flex-wrap" style="margin-bottom:20px;">
  <?php foreach([['Pending',count(array_filter($maintenance,fn($m)=>$m['status']==='Pending')),'#d97706'],['Approved',count(array_filter($maintenance,fn($m)=>$m['status']==='Approved')),'#2e7d32'],['Rejected',count(array_filter($maintenance,fn($m)=>$m['status']==='Rejected')),'#dc2626']] as [$l,$val,$c]):?>
  <div class="stat-box"><div class="stat-value" style="color:<?=$c?>"><?=$val?></div><div class="stat-label"><?=$l?></div></div>
  <?php endforeach;?>
</div>
<?php foreach($maintenance as $m): $vm=findById($vehicles,$m['vehicle_id']); $mc=match($m['status']){'Approved'=>'#2e7d32','Pending'=>'#d97706',default=>'#dc2626'}; 
  $isIncidentRepair = str_starts_with($m['maint_type'], 'Incident Repair');
  $isFlagInspection = str_starts_with($m['maint_type'], 'Flag Inspection');
  $isFlaggedVehicle = $vm && $vm['flagged'];
  $cardLeftBorder = $isFlagInspection ? 'border-left:4px solid #dc2626;' : ($isIncidentRepair ? 'border-left:4px solid #7c3aed;' : '');
  $cardBg = ($isFlagInspection && $m['status']==='Pending') ? 'background:#fff9f9;' : '';
?>
<div class="card" style="margin-bottom:12px;<?=$cardLeftBorder?><?=$cardBg?>">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
    <div style="flex:1;">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <div style="font-weight:700;font-size:15px;"><?=e($m['maint_type'])?> — <?=e($vm?$vm['plate']:$m['vehicle_id'])?> <span style="font-size:12px;color:#627065;">(<?=e($vm?$vm['make'].' '.$vm['model']:'')?>)</span></div>
        <?php if($isFlagInspection): ?><span style="font-size:10px;font-weight:800;padding:2px 8px;border-radius:100px;background:#dc262618;color:#dc2626;border:1px solid #dc262644;">🚩 FLAGGED</span><?php endif;?>
        <?php if($isIncidentRepair): ?><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;background:#7c3aed18;color:#7c3aed;border:1px solid #7c3aed44;">FROM INCIDENT</span><?php endif;?>
        <?php if($isFlaggedVehicle && $vm['status']==='In Maintenance'): ?><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;background:#d9770618;color:#d97706;border:1px solid #d9770644;">IN MAINTENANCE</span><?php endif;?>
      </div>
      <div style="font-size:12px;color:#8fa592;margin-top:3px;">
        <?php if($isFlagInspection): ?>
          <span style="color:#dc2626;font-weight:700;">⚑ Flag Inspection</span> · <?=e($m['scheduled_date'])?> · <a href="fvm.php?page=operations&tab=health" style="color:#dc2626;font-size:11px;font-weight:700;">View in Health Check →</a>
        <?php elseif($isIncidentRepair): ?>
          <span style="color:#dc2626;font-weight:700;">Damage Cost: <?=peso($m['estimated_cost'])?></span> · <?=e($m['scheduled_date'])?>
        <?php else: ?>
          Est: <?=peso($m['estimated_cost'])?> · <?=e($m['scheduled_date'])?>
        <?php endif; ?>
      </div>
      <?php if($m['notes']):?><div style="font-size:11.5px;color:#8fa592;margin-top:4px;max-width:600px;line-height:1.5;"><?=e(mb_strimwidth($m['notes'],0,160,'…'))?></div><?php endif;?>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
      <?=badge($m['status'],$mc)?>
      <?php if($m['status']==='Pending'):?>
      <?php if($isFlagInspection && $m['estimated_cost']==0): ?>
      <button type="button" onclick="toggleAssess('assess-<?=e($m['id'])?>')" class="btn-warning sm">⚑ Add Assessment</button>
      <?php else: ?>
      <form method="POST" style="display:inline;"><input type="hidden" name="action" value="approve_maintenance"><input type="hidden" name="maint_id" value="<?=e($m['id'])?>"><button type="submit" class="btn-primary sm" onclick="return confirm('Approve and create expense of <?=peso($m['estimated_cost'])?>?')">✅ Approve <?=peso($m['estimated_cost'])?></button></form>
      <?php endif;?>
      <form method="POST" style="display:inline;"><input type="hidden" name="action" value="reject_maintenance"><input type="hidden" name="maint_id" value="<?=e($m['id'])?>"><button type="submit" class="btn-danger sm">✗ Reject</button></form>
      <?php endif;?>
    </div>
  </div>

  <?php if($isFlagInspection && $m['status']==='Pending'): ?>
  <!-- Inline Assessment Panel -->
  <div id="assess-<?=e($m['id'])?>" style="display:<?=$m['estimated_cost']==0?'block':'none'?>;margin-top:16px;border-top:2px dashed #fecaca;padding-top:16px;">
    <div style="font-size:12px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;">
      🔧 Cost Assessment — <?=e($vm?$vm['plate']:'')?>
      <?php if($m['estimated_cost']>0): ?><span style="font-weight:400;color:#8fa592;margin-left:8px;">Current estimate: <?=peso($m['estimated_cost'])?></span><?php endif; ?>
    </div>
    <?php
    // Parse existing issues from notes
    $existingIssues = [];
    if (!empty($m['notes'])) {
        if (preg_match('/Issues: ([^
]+)/u', $m['notes'], $im)) {
            foreach (explode('; ', $im[1]) as $pair) {
                $parts = explode(' = ₱', $pair);
                if (count($parts) === 2) $existingIssues[trim($parts[0])] = (float)trim($parts[1]);
            }
        }
    }
    $assessIssues = [
        'Engine Oil Leak','Coolant Leak','Transmission Problem','Brake Failure','Brake Wear',
        'Tire Damage / Flat','Battery Issue','Alternator Problem','Starter Motor',
        'Suspension / Steering','Exhaust Problem','Air Filter Clog','Fuel System Issue',
        'Electrical Fault','Lights / Signals Not Working','AC / Cooling System',
        'Body Damage','Windshield Crack','Door / Lock Problem','Undercarriage Damage',
        'Overheating','Check Engine Light','ABS Warning','Transmission Fluid Low',
    ];
    $mid_js = e($m['id']);
    ?>
    <form method="POST" id="aform-<?=e($m['id'])?>">
      <input type="hidden" name="action" value="update_flag_assessment">
      <input type="hidden" name="maint_id" value="<?=e($m['id'])?>">

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;margin-bottom:16px;">
        <?php foreach($assessIssues as $ai): $preCost = $existingIssues[$ai] ?? 0; ?>
        <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #dceade;border-radius:9px;cursor:pointer;background:#fff;font-size:12.5px;" onmouseenter="this.style.background='#fff9f0'" onmouseleave="this.style.background=this.querySelector('input[type=checkbox]').checked?'#fff9f0':'#fff'">
          <input type="checkbox" name="issue_items[]" value="<?=e($ai)?>" <?=$preCost>0?'checked':''?>
            onchange="this.closest('label').style.background=this.checked?'#fff9f0':'#fff';toggleAC(this,'<?=$mid_js?>');recalcA('<?=$mid_js?>')"
            style="width:15px;height:15px;accent-color:#dc2626;flex-shrink:0;">
          <div style="flex:1;">
            <div style="font-weight:600;color:#0d150e;"><?=e($ai)?></div>
            <div class="ac-cost" style="margin-top:4px;display:<?=$preCost>0?'block':'none'?>">
              <input type="number" name="issue_costs[]" value="<?=$preCost?>" step="100" min="0" placeholder="₱ cost"
                style="width:100%;padding:4px 7px;border:1px solid #fecaca;border-radius:6px;font-size:12px;"
                oninput="recalcA('<?=$mid_js?>')">
            </div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>

      <!-- Custom rows -->
      <div style="margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;color:#627065;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">➕ Custom Items</div>
        <div id="acustom-<?=e($m['id'])?>">
          <div class="ac-custom-row" style="display:flex;gap:6px;margin-bottom:5px;">
            <input type="text" name="issue_items[]" placeholder="Describe issue..." class="form-control" style="flex:2;font-size:12px;padding:7px 10px;">
            <input type="number" name="issue_costs[]" placeholder="₱ cost" step="100" min="0" class="form-control" style="flex:1;font-size:12px;padding:7px 10px;" oninput="recalcA('<?=$mid_js?>')">
          </div>
        </div>
        <button type="button" onclick="addARow('<?=$mid_js?>')" class="btn-secondary" style="font-size:11px;padding:5px 12px;margin-top:3px;">+ Add Row</button>
      </div>

      <!-- Total + submit -->
      <div style="display:flex;justify-content:space-between;align-items:center;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;margin-bottom:12px;">
        <div style="font-size:11px;color:#991b1b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Estimated Total</div>
        <div style="font-family:'DM Serif Display',serif;font-size:26px;color:#dc2626;font-weight:700;" id="atotal-<?=e($m['id'])?>">₱0.00</div>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="btn-primary" style="flex:1;">💾 Save Assessment &amp; Update Cost</button>
        <button type="button" onclick="toggleAssess('assess-<?=e($m['id'])?>')" class="btn-secondary" style="font-size:12px;padding:8px 14px;">Hide</button>
      </div>
    </form>
    <script>
    (function(){
      recalcA('<?=$mid_js?>');
    })();
    </script>
  </div>
  <?php endif; ?>

</div>
<?php endforeach; ?>

<script>
function toggleAssess(id){ var el=document.getElementById(id); if(el) el.style.display=el.style.display==='none'?'block':'none'; }
function toggleAC(cb,mid){
  var cf=cb.closest('label').querySelector('.ac-cost');
  if(cf){ cf.style.display=cb.checked?'block':'none'; if(!cb.checked){ var inp=cf.querySelector('input'); if(inp) inp.value=0; } }
  recalcA(mid);
}
function recalcA(mid){
  var total=0;
  var form=document.getElementById('aform-'+mid);
  if(!form) return;
  form.querySelectorAll('input[type=checkbox][name="issue_items[]"]').forEach(function(cb){
    if(!cb.checked) return;
    var cf=cb.closest('label').querySelector('.ac-cost input');
    if(cf) total+=parseFloat(cf.value)||0;
  });
  form.querySelectorAll('.ac-custom-row').forEach(function(row){
    var inp=row.querySelectorAll('input')[1]; if(inp) total+=parseFloat(inp.value)||0;
  });
  var el=document.getElementById('atotal-'+mid);
  if(el) el.textContent='₱'+total.toLocaleString('en-PH',{minimumFractionDigits:2});
}
function addARow(mid){
  var div=document.createElement('div'); div.className='ac-custom-row'; div.style='display:flex;gap:6px;margin-bottom:5px;';
  div.innerHTML='<input type="text" name="issue_items[]" placeholder="Describe issue..." class="form-control" style="flex:2;font-size:12px;padding:7px 10px;"><input type="number" name="issue_costs[]" placeholder="₱ cost" step="100" min="0" class="form-control" style="flex:1;font-size:12px;padding:7px 10px;" oninput="recalcA(''+mid+'')"><button type="button" onclick="this.parentElement.remove();recalcA(''+mid+'')" class="btn-danger" style="padding:5px 9px;font-size:12px;">✕</button>';
  document.getElementById('acustom-'+mid).appendChild(div);
}
</script>
<!-- Request modal -->
<div class="modal-overlay" id="modal-maint">
  <div class="modal" style="max-width:500px;">
    <div class="modal-title">🔧 Request Maintenance</div>
    <form method="POST"><input type="hidden" name="action" value="request_maintenance">
      <div class="form-row">
        <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php foreach($vehicles as $v):?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Type</label><select name="maint_type" class="form-control"><option>Oil Change</option><option>Tire Replacement</option><option>Brake System</option><option>Engine Overhaul</option><option>Electrical</option><option>Body Repair</option><option>Air Filter</option><option>Other</option></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Estimated Cost (₱)</label><input type="number" name="estimated_cost" class="form-control" value="0" required></div>
        <div class="form-group"><label>Schedule Date</label><input type="date" name="scheduled_date" class="form-control" value="<?=$today?>" required></div>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-maint')">Cancel</button><button type="submit" class="btn-primary">💾 Submit</button></div>
    </form>
  </div>
</div>

<?php elseif($opsTab==='fuel'): ?>
<!-- ══ FUEL LOG ══ -->
<?php
  $fvFilter = $_GET['fv'] ?? 'all';
  $filteredFuel = $fvFilter==='all' ? $fuel_logs : array_values(array_filter($fuel_logs,fn($f)=>$f['vehicle_id']===$fvFilter));
  $totalFuelCost = array_sum(array_column($filteredFuel,'total_cost'));
  $totalLiters   = array_sum(array_column($filteredFuel,'liters'));
  $cpkm = [];
  foreach($vehicles as $v){
    $vl=array_values(array_filter($fuel_logs,fn($f)=>$f['vehicle_id']===$v['id']));
    if(count($vl)>=2){
      usort($vl,fn($a,$b)=>strcmp($a['log_date'],$b['log_date']));
      $km=$vl[count($vl)-1]['odometer_km']-$vl[0]['odometer_km'];
      $cost=array_sum(array_column($vl,'total_cost'));
      if($km>0) $cpkm[$v['id']]=round($cost/$km,2);
    }
  }
?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Fuel Tracker</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <button class="btn-primary" onclick="openModal('modal-fuel')">+ Log Fill-up</button>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;align-items:center;">
  <a href="fvm.php?page=fuel&fv=all" style="text-decoration:none;font-size:11.5px;padding:5px 12px;border-radius:20px;border:1px solid #dceade;background:<?=$fvFilter==='all'?'#256427':'#fff'?>;color:<?=$fvFilter==='all'?'#fff':'#256427'?>;">All</a>
  <?php foreach($vehicles as $v): ?><a href="fvm.php?page=fuel&fv=<?=e($v['id'])?>" style="text-decoration:none;font-size:11.5px;padding:5px 12px;border-radius:20px;border:1px solid #dceade;background:<?=$fvFilter===$v['id']?'#256427':'#fff'?>;color:<?=$fvFilter===$v['id']?'#fff':'#256427'?>;"><?=e($v['plate'])?></a><?php endforeach; ?>
</div>
<div class="flex-wrap" style="margin-bottom:22px;">
  <div class="stat-box"><div class="stat-value" style="color:#dc2626;"><?=peso($totalFuelCost)?></div><div class="stat-label">Total Cost</div></div>
  <div class="stat-box"><div class="stat-value" style="color:#d97706;"><?=number_format($totalLiters,1)?> L</div><div class="stat-label">Total Liters</div></div>
  <div class="stat-box"><div class="stat-value" style="color:#256427;"><?=count($filteredFuel)?></div><div class="stat-label">Records</div></div>
  <?php foreach($vehicles as $v): if(!isset($cpkm[$v['id']])) continue; ?>
  <div class="stat-box"><div class="stat-value" style="color:#1d4ed8;">₱<?=$cpkm[$v['id']]?>/km</div><div class="stat-label"><?=e($v['plate'])?> Cost/km</div></div>
  <?php endforeach; ?>
</div>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>⛽ Fill-up Records</div>
  <table class="data-table">
    <thead><tr><?php foreach(['Code','Vehicle','Date','Odometer','Liters','₱/Liter','Total','Station','Driver',''] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead>
    <tbody>
    <?php foreach($filteredFuel as $fl): $vfl=findById($vehicles,$fl['vehicle_id']); $dfl=findById($drivers,$fl['driver_id']); ?>
    <tr>
      <td style="color:#2e7d32;font-weight:600;"><?=e($fl['log_code']??'')?></td>
      <td><strong><?=e($vfl?$vfl['plate']:'?')?></strong><div style="font-size:11px;color:#8fa592;"><?=e($vfl?$vfl['make'].' '.$vfl['model']:'')?></div></td>
      <td style="color:#627065;"><?=e($fl['log_date'])?></td>
      <td><?=number_format($fl['odometer_km'])?> km</td>
      <td style="color:#d97706;font-weight:600;"><?=$fl['liters']?> L</td>
      <td style="color:#627065;">₱<?=number_format($fl['price_per_liter'],2)?></td>
      <td style="color:#dc2626;font-weight:700;"><?=peso($fl['total_cost'])?></td>
      <td style="color:#627065;"><?=e($fl['station']??'—')?></td>
      <td style="font-size:12px;"><?=e($dfl?$dfl['full_name']:'—')?></td>
      <td><form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;"><input type="hidden" name="action" value="delete_fuel_log"><input type="hidden" name="log_id" value="<?=e($fl['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="modal-overlay" id="modal-fuel">
  <div class="modal" style="max-width:500px;">
    <div class="modal-title">⛽ Log Fill-up</div>
    <form method="POST"><input type="hidden" name="action" value="add_fuel_log">
      <div class="form-row">
        <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php foreach($vehicles as $v):?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Driver</label><select name="driver_id" class="form-control"><option value="">— None —</option><?php foreach($drivers as $d):?><option value="<?=e($d['id'])?>"><?=e($d['full_name'])?></option><?php endforeach;?></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Date</label><input type="date" name="log_date" class="form-control" value="<?=$today?>" required></div>
        <div class="form-group"><label>Odometer (km)</label><input type="number" name="odometer_km" class="form-control" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Liters</label><input type="number" step="0.1" name="liters" class="form-control" required></div>
        <div class="form-group"><label>Price per Liter (₱)</label><input type="number" step="0.01" name="price_per_liter" class="form-control" required></div>
      </div>
      <div class="form-group"><label>Station</label><input type="text" name="station" class="form-control" placeholder="Petron EDSA"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-fuel')">Cancel</button><button type="submit" class="btn-primary">💾 Save</button></div>
    </form>
  </div>
</div>

<?php endif; // opsTab ?>
<?php elseif($page==='vehicles'): ?>
<!-- ══ VEHICLES ══ -->
<?php
// ── Flag Assessment inline page ────────────────────────────────────────────────
if (!empty($_GET['flag_assess'])):
    $faVid  = $_GET['flag_assess'];
    $faRows = sbGet('fvm_vehicles', ['id' => 'eq.'.$faVid, 'select' => '*']);
    $faV    = $faRows[0] ?? null;
    $commonIssues = [
        'Engine Oil Leak','Coolant Leak','Transmission Problem','Brake Failure','Brake Wear',
        'Tire Damage / Flat','Battery Issue','Alternator Problem','Starter Motor',
        'Suspension / Steering','Exhaust Problem','Air Filter Clog','Fuel System Issue',
        'Electrical Fault','Lights / Signals Not Working','AC / Cooling System',
        'Body Damage','Windshield Crack','Door / Lock Problem','Undercarriage Damage',
        'Overheating','Check Engine Light','ABS Warning','Transmission Fluid Low',
    ];
?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>🚩 Flag Vehicle for Maintenance</div>
    <div class="page-title"><?=e($faV?$faV['plate'].' — '.$faV['make'].' '.$faV['model']:'')?></div>
    <div class="page-sub">Document the reason and issues before setting the vehicle to maintenance</div>
  </div>
  <a href="fvm.php?page=vehicles" class="btn-secondary" style="text-decoration:none;">← Cancel</a>
</div>

<form method="POST" id="flag-form">
  <input type="hidden" name="action" value="save_flag_details">
  <input type="hidden" name="vehicle_id" value="<?=e($faVid)?>">

  <div class="grid-2" style="margin-bottom:20px;align-items:start;">

    <!-- Left: Reason + severity + schedule -->
    <div class="card">
      <div class="card-title"><span class="card-title-bar"></span>📋 Flag Details</div>

      <div class="form-group">
        <label>Reason for Flagging <span style="color:#dc2626;">*</span></label>
        <textarea name="flag_reason" class="form-control" rows="3" required
          placeholder="Describe the issue that triggered this flag (e.g. driver reported engine knocking during last trip, oil pressure warning light, etc.)"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Severity</label>
          <select name="flag_severity" class="form-control">
            <option value="Minor">🟡 Minor — Monitor only</option>
            <option value="Moderate" selected>🟠 Moderate — Needs repair soon</option>
            <option value="Major">🔴 Major — Immediate repair needed</option>
            <option value="Critical">⛔ Critical — Unsafe to operate</option>
          </select>
        </div>
        <div class="form-group">
          <label>Scheduled Inspection Date</label>
          <input type="date" name="scheduled_date" class="form-control" value="<?=$today?>" required>
        </div>
      </div>

      <!-- Severity guidance -->
      <div id="sev-guide" style="padding:10px 14px;border-radius:9px;font-size:12px;margin-top:4px;border:1px solid #fde68a;background:#fffbeb;color:#92400e;">
        🟠 <strong>Moderate:</strong> Vehicle can still operate but needs repair within the week. Restrict to short-range trips.
      </div>
    </div>

    <!-- Right: Summary / cost preview -->
    <div class="card" style="position:sticky;top:20px;">
      <div class="card-title"><span class="card-title-bar"></span>💰 Cost Summary</div>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px 20px;text-align:center;">
        <div style="font-size:10px;color:#991b1b;font-weight:700;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px;">Estimated Total Repair Cost</div>
        <div style="font-family:'DM Serif Display',serif;font-size:36px;color:#dc2626;font-weight:700;" id="flag-total">₱0.00</div>
        <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Auto-calculated from checked issues below</div>
      </div>
      <div style="margin-top:16px;">
        <div style="font-size:11px;font-weight:700;color:#627065;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px;">Flagged Issues Summary</div>
        <div id="flag-summary" style="font-size:12.5px;color:#1a2b1c;line-height:1.8;">
          <span style="color:#8fa592;">— No issues checked yet</span>
        </div>
      </div>
      <div style="margin-top:20px;border-top:1px solid #edf5ef;padding-top:16px;display:flex;flex-direction:column;gap:8px;">
        <button type="submit" class="btn-danger" style="width:100%;font-size:13px;padding:11px;">🚩 Confirm Flag &amp; Create Maintenance Request</button>
        <a href="fvm.php?page=vehicles" class="btn-secondary" style="width:100%;text-decoration:none;text-align:center;font-size:13px;padding:11px;display:block;box-sizing:border-box;">Cancel</a>
      </div>
    </div>
  </div>

  <!-- Issue checklist with costs -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-title"><span class="card-title-bar"></span>🔧 Issue Checklist &amp; Cost Breakdown</div>
    <p style="font-size:13px;color:#627065;margin-bottom:16px;">Check all issues found and enter the estimated repair cost for each. Leave unchecked if not applicable. You can add custom items below.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-bottom:20px;">
      <?php foreach($commonIssues as $ci): ?>
      <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #dceade;border-radius:10px;cursor:pointer;background:#fff;transition:background 150ms;" onmouseenter="this.style.background='#fff9f0'" onmouseleave="this.style.background=this.querySelector('input[type=checkbox]').checked?'#fff9f0':'#fff'">
        <input type="checkbox" name="issue_items[]" value="<?=e($ci)?>"
          onchange="this.closest('label').style.background=this.checked?'#fff9f0':'#fff';toggleFlagCost(this);updateFlagSummary()"
          style="width:16px;height:16px;accent-color:#dc2626;flex-shrink:0;">
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;color:#0d150e;"><?=e($ci)?></div>
          <div class="flag-cost-field" style="margin-top:6px;display:none;">
            <input type="number" name="issue_costs[]" value="0" step="100" min="0"
              placeholder="₱ estimated cost"
              style="width:100%;padding:5px 8px;border:1px solid #fecaca;border-radius:7px;font-size:12px;background:#fff;"
              oninput="recalcFlagTotal();updateFlagSummary()">
          </div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <!-- Custom issue rows -->
    <div style="margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;color:#627065;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;">➕ Additional Issues / Custom Items</div>
      <div id="custom-flag-items">
        <div class="flag-custom-row" style="display:flex;gap:8px;margin-bottom:6px;">
          <input type="text" name="issue_items[]" placeholder="Describe issue..." class="form-control" style="flex:2;" oninput="updateFlagSummary()">
          <input type="number" name="issue_costs[]" placeholder="₱ cost" step="100" min="0" class="form-control" style="flex:1;" oninput="recalcFlagTotal();updateFlagSummary()">
        </div>
      </div>
      <button type="button" onclick="addFlagRow()" class="btn-secondary" style="font-size:11.5px;padding:6px 14px;margin-top:4px;">+ Add Row</button>
    </div>
  </div>
</form>

<script>
var sevGuides = {
  'Minor':    '🟡 <strong>Minor:</strong> Watch and monitor. Vehicle can operate normally. Log for next scheduled PMS.',
  'Moderate': '🟠 <strong>Moderate:</strong> Vehicle can still operate but needs repair within the week. Restrict to short-range trips.',
  'Major':    '🔴 <strong>Major:</strong> Do not dispatch until repaired. Schedule immediate maintenance.',
  'Critical': '⛔ <strong>Critical:</strong> Vehicle is unsafe to operate. Ground immediately and repair before any use.',
};
var sevColors = {
  'Minor':    {border:'#fde68a',bg:'#fffbeb',color:'#92400e'},
  'Moderate': {border:'#fed7aa',bg:'#fff7ed',color:'#9a3412'},
  'Major':    {border:'#fecaca',bg:'#fef2f2',color:'#991b1b'},
  'Critical': {border:'#fca5a5',bg:'#fef2f2',color:'#7f1d1d'},
};
document.querySelector('[name="flag_severity"]').addEventListener('change', function() {
  var g = document.getElementById('sev-guide');
  var c = sevColors[this.value] || sevColors['Moderate'];
  g.innerHTML = sevGuides[this.value] || '';
  g.style.borderColor = c.border; g.style.background = c.bg; g.style.color = c.color;
});
function toggleFlagCost(cb) {
  var cf = cb.closest('label').querySelector('.flag-cost-field');
  if (cf) { cf.style.display = cb.checked ? 'block' : 'none'; if(!cb.checked){ var inp=cf.querySelector('input'); if(inp) inp.value=0; } }
  recalcFlagTotal();
}
function recalcFlagTotal() {
  var total = 0;
  // Checked checkboxes
  document.querySelectorAll('input[type=checkbox][name="issue_items[]"]').forEach(function(cb) {
    if (!cb.checked) return;
    var cf = cb.closest('label').querySelector('.flag-cost-field input');
    if (cf) total += parseFloat(cf.value) || 0;
  });
  // Custom rows
  document.querySelectorAll('#custom-flag-items .flag-custom-row').forEach(function(row) {
    var inp = row.querySelectorAll('input')[1];
    if (inp) total += parseFloat(inp.value) || 0;
  });
  document.getElementById('flag-total').textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2});
}
function updateFlagSummary() {
  var lines = [];
  document.querySelectorAll('input[type=checkbox][name="issue_items[]"]').forEach(function(cb) {
    if (!cb.checked) return;
    var cf = cb.closest('label').querySelector('.flag-cost-field input');
    var cost = cf ? (parseFloat(cf.value)||0) : 0;
    lines.push('<span style="display:flex;justify-content:space-between;"><span>• ' + cb.value + '</span><strong style="color:#dc2626;">₱' + cost.toLocaleString('en-PH',{minimumFractionDigits:2}) + '</strong></span>');
  });
  document.querySelectorAll('#custom-flag-items .flag-custom-row').forEach(function(row) {
    var inps = row.querySelectorAll('input');
    var name = inps[0]?.value?.trim(); var cost = parseFloat(inps[1]?.value)||0;
    if (name) lines.push('<span style="display:flex;justify-content:space-between;"><span>• ' + name + '</span><strong style="color:#dc2626;">₱' + cost.toLocaleString('en-PH',{minimumFractionDigits:2}) + '</strong></span>');
  });
  var el = document.getElementById('flag-summary');
  el.innerHTML = lines.length ? lines.join('') : '<span style="color:#8fa592;">— No issues checked yet</span>';
}
function addFlagRow() {
  var div = document.createElement('div'); div.className = 'flag-custom-row'; div.style = 'display:flex;gap:8px;margin-bottom:6px;';
  div.innerHTML = '<input type="text" name="issue_items[]" placeholder="Describe issue..." class="form-control" style="flex:2;" oninput="updateFlagSummary()"><input type="number" name="issue_costs[]" placeholder="₱ cost" step="100" min="0" class="form-control" style="flex:1;" oninput="recalcFlagTotal();updateFlagSummary()"><button type="button" onclick="this.parentElement.remove();recalcFlagTotal();updateFlagSummary()" class="btn-danger" style="padding:6px 10px;font-size:12px;">✕</button>';
  document.getElementById('custom-flag-items').appendChild(div);
}
</script>

<?php else: // normal vehicles list ?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Vehicle Management</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <button class="btn-primary" onclick="openModal('modal-add-vehicle')">+ Add Vehicle</button>
</div>
<div class="flex-wrap" style="margin-bottom:22px;">
  <?php foreach([['Total',count($vehicles),'#0d150e'],['Active',count(array_filter($vehicles,fn($v)=>$v['status']==='Active')),'#2e7d32'],['Maintenance',count(array_filter($vehicles,fn($v)=>$v['status']==='In Maintenance')),'#d97706'],['Inactive',count(array_filter($vehicles,fn($v)=>$v['status']==='Inactive')),'#dc2626'],['Flagged',count(array_filter($vehicles,fn($v)=>$v['flagged'])),'#dc2626']] as [$l,$val,$c]):?>
  <div class="stat-box"><div class="stat-value" style="color:<?=$c?>"><?=$val?></div><div class="stat-label"><?=$l?></div></div>
  <?php endforeach;?>
</div>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>🚗 Vehicle Registry</div>
  <table class="data-table">
    <thead><tr><?php foreach(['Code','Plate','Make/Model','Year','Type','Status','Mileage','Fuel','LTO Expiry','Ins Expiry','Actions'] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead>
    <tbody>
    <?php foreach($vehicles as $v): $ltoWarn=$v['lto_expiry']&&isExpired($v['lto_expiry']); $insWarn=$v['ins_expiry']&&isExpired($v['ins_expiry']); ?>
    <tr style="<?=$v['flagged']?'background:#fff5f5;':''?>">
      <td style="color:#2e7d32;font-weight:600;"><?=e($v['vehicle_code'])?></td>
      <td>
        <strong><?=e($v['plate'])?></strong>
        <?php if($v['flagged']): ?>
        <span style="color:#dc2626;font-size:10px;font-weight:700;"> ⚑</span>
        <div style="font-size:10px;margin-top:2px;"><a href="fvm.php?page=operations&tab=maintenance" style="color:#dc2626;font-weight:700;text-decoration:none;">⏳ Under Monitoring →</a></div>
        <?php endif; ?>
      </td>
      <td><?=e($v['make'])?> <?=e($v['model'])?></td>
      <td><?=$v['year']?></td>
      <td style="color:#627065;"><?=e($v['vehicle_type']??'—')?></td>
      <td>
        <?=badge($v['status'],statusColor($v['status']))?>
        <?php if($v['flagged']&&$v['status']==='In Maintenance'): ?>
        <div style="font-size:10px;color:#dc2626;font-weight:700;margin-top:3px;">🔧 Flagged-Maint.</div>
        <?php endif; ?>
      </td>
      <td><?=number_format($v['mileage'])?> km</td>
      <td><?=fuelBar($v['fuel_level'])?><span style="font-size:11px;color:#627065;margin-left:4px;"><?=$v['fuel_level']?>%</span></td>
      <td style="color:<?=$ltoWarn?'#dc2626':'#627065'?>"><?=$v['lto_expiry']?:'-'?><?php if($ltoWarn): ?> ⚠️<?php endif;?></td>
      <td style="color:<?=$insWarn?'#dc2626':'#627065'?>"><?=$v['ins_expiry']?:'-'?><?php if($insWarn): ?> ⚠️<?php endif;?></td>
      <td style="white-space:nowrap;">
        <button onclick="openEditVehicle(<?=htmlspecialchars(json_encode($v),ENT_QUOTES)?>)" class="btn-secondary" style="font-size:10px;padding:3px 8px;">✏️ Edit</button>
        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle_flag"><input type="hidden" name="vehicle_id" value="<?=e($v['id'])?>"><button type="submit" class="btn-secondary" style="font-size:10px;padding:3px 8px;"><?=$v['flagged']?'🚩 Unflag':'⚑ Flag'?></button></form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this vehicle?')"><input type="hidden" name="action" value="delete_vehicle"><input type="hidden" name="vehicle_id" value="<?=e($v['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<!-- Add Vehicle modal -->
<div class="modal-overlay" id="modal-add-vehicle">
  <div class="modal" style="max-width:560px;">
    <div class="modal-title">🚗 Add Vehicle</div>
    <form method="POST"><input type="hidden" name="action" value="add_vehicle">
      <div class="form-row">
        <div class="form-group"><label>Plate Number</label><input type="text" name="plate" class="form-control" placeholder="ABC-1234" required></div>
        <div class="form-group"><label>Make</label><input type="text" name="make" class="form-control" placeholder="Toyota" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Model</label><input type="text" name="model" class="form-control" placeholder="HiAce" required></div>
        <div class="form-group"><label>Year</label><input type="number" name="year" class="form-control" value="<?=date('Y')?>" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Vehicle Type</label><select name="vehicle_type" class="form-control"><option>Service Vehicle</option><option>Delivery Van</option><option>Pickup Truck</option><option>SUV</option><option>Sedan</option><option>Bus</option><option>Heavy Equipment</option></select></div>
        <div class="form-group"><label>Fuel Type</label><select name="fuel_type" class="form-control"><option>Diesel</option><option>Gasoline</option><option>Electric</option></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Current Mileage (km)</label><input type="number" name="mileage" class="form-control" value="0"></div>
        <div class="form-group"><label>Fuel Level (%)</label><input type="number" name="fuel_level" class="form-control" value="100" min="0" max="100"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>LTO Expiry</label><input type="date" name="lto_expiry" class="form-control"></div>
        <div class="form-group"><label>Insurance Expiry</label><input type="date" name="ins_expiry" class="form-control"></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-add-vehicle')">Cancel</button><button type="submit" class="btn-primary">💾 Save Vehicle</button></div>
    </form>
  </div>
</div>

<!-- Edit Vehicle modal -->
<div class="modal-overlay" id="modal-edit-vehicle">
  <div class="modal" style="max-width:560px;">
    <div class="modal-title">✏️ Edit Vehicle</div>
    <form method="POST"><input type="hidden" name="action" value="edit_vehicle"><input type="hidden" name="vehicle_id" id="ev-id">
      <div class="form-row">
        <div class="form-group"><label>Plate Number</label><input type="text" name="plate" id="ev-plate" class="form-control" required></div>
        <div class="form-group"><label>Make</label><input type="text" name="make" id="ev-make" class="form-control" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Model</label><input type="text" name="model" id="ev-model" class="form-control" required></div>
        <div class="form-group"><label>Year</label><input type="number" name="year" id="ev-year" class="form-control" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Vehicle Type</label><select name="vehicle_type" id="ev-type" class="form-control"><option>Service Vehicle</option><option>Delivery Van</option><option>Pickup Truck</option><option>SUV</option><option>Sedan</option><option>Bus</option><option>Heavy Equipment</option></select></div>
        <div class="form-group"><label>Fuel Type</label><select name="fuel_type" id="ev-fuel" class="form-control"><option>Diesel</option><option>Gasoline</option><option>Electric</option></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>LTO Expiry</label><input type="date" name="lto_expiry" id="ev-lto" class="form-control"></div>
        <div class="form-group"><label>Insurance Expiry</label><input type="date" name="ins_expiry" id="ev-ins" class="form-control"></div>
      </div>
      <div class="form-group"><label>Status</label><select name="status" id="ev-status" class="form-control"><option>Active</option><option>In Maintenance</option><option>Inactive</option><option>Retired</option></select></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit-vehicle')">Cancel</button><button type="submit" class="btn-primary">💾 Update Vehicle</button></div>
    </form>
  </div>
</div>

<?php endif; // end flag_assess else ?>

<?php elseif($page==='drivers'): ?>
<!-- ══ DRIVERS ══ -->
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Driver Management</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <div style="display:flex;gap:8px;"><button class="btn-secondary" onclick="window.print()" style="font-size:12px;">🖨️ Print QR Cards</button><button class="btn-primary" onclick="openModal('modal-add-driver')">+ Add Driver</button></div>
</div>
<div class="grid-3" style="margin-bottom:24px;">
  <?php foreach($drivers as $d):
    $assigned = findById($vehicles,$d['assigned_vehicle_id']);
    $licExpired = isExpired($d['license_expiry']);
    $qrData = urlencode(json_encode(['id'=>$d['driver_code']??$d['id'],'name'=>$d['full_name'],'license'=>$d['license_no'],'expires'=>$d['license_expiry'],'phone'=>$d['phone']??'']));
    $cardId = 'qr-'.$d['id'];
  ?>
  <div class="qr-card" id="<?=e($cardId)?>">
    <div class="qr-card-header"><div class="qr-card-org">Logistics 2 · FVM Module</div><div class="qr-card-title">Driver ID Card</div></div>
    <div style="margin:12px auto;width:80px;height:80px;" id="qr-canvas-<?=e($d['id'])?>"></div>
    <div class="qr-driver-name"><?=e($d['full_name'])?></div>
    <div class="qr-driver-detail">🪪 <?=e($d['license_no'])?></div>
    <div class="qr-driver-detail" style="color:<?=$licExpired?'#dc2626':'#627065'?>">Expires: <?=e($d['license_expiry'])?><?php if($licExpired): ?> ⚠️<?php endif;?></div>
    <?php if($d['phone']): ?><div class="qr-driver-detail">📞 <?=e($d['phone'])?></div><?php endif;?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:10px;"><?=badge($d['status'],statusColor($d['status']))?></div>
    <div class="qr-id-number"><?=e($d['driver_code']??'D-')?></div>
    <div style="margin-top:14px;display:flex;align-items:center;justify-content:center;gap:8px;">
      <?=scoreBar($d['behavior_score'])?><span style="font-weight:700;font-size:13px;color:<?=$d['behavior_score']>=85?'#2e7d32':($d['behavior_score']>=70?'#d97706':'#dc2626')?>"><?=$d['behavior_score']?></span>
    </div>
    <button class="qr-print-btn" onclick="printSingleCard('<?=e($cardId)?>')">🖨️</button>
  </div>
  <script>new QRCode(document.getElementById('qr-canvas-<?=e($d['id'])?>'),{text:'<?=$qrData?>',width:80,height:80,colorDark:'#14401a',colorLight:'#ffffff'});</script>
  <?php endforeach; ?>
</div>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>🧑‍✈️ Driver List</div>
  <table class="data-table"><thead><tr><?php foreach(['Code','Name','License No','Expires','Status','Score','Phone','Actions'] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead><tbody>
  <?php foreach($drivers as $d): $le=isExpired($d['license_expiry']); ?>
  <tr>
    <td style="color:#2e7d32;font-weight:600;"><?=e($d['driver_code']??'')?></td>
    <td style="font-weight:600;"><?=e($d['full_name'])?></td>
    <td style="color:#627065;"><?=e($d['license_no'])?></td>
    <td style="color:<?=$le?'#dc2626':'#627065'?>"><?=e($d['license_expiry'])?><?php if($le):?> ⚠️<?php endif;?></td>
    <td><?=badge($d['status'],statusColor($d['status']))?></td>
    <td><?=scoreBar($d['behavior_score'])?><span style="font-size:11px;margin-left:4px;"><?=$d['behavior_score']?></span></td>
    <td style="color:#627065;"><?=e($d['phone']??'—')?></td>
    <td style="white-space:nowrap;"><button onclick="openEditDriver(<?=htmlspecialchars(json_encode($d),ENT_QUOTES)?>)" class="btn-secondary" style="font-size:10px;padding:3px 8px;">✏️ Edit</button><form method="POST" onsubmit="return confirm('Delete driver?');" style="display:inline;"><input type="hidden" name="action" value="delete_driver"><input type="hidden" name="driver_id" value="<?=e($d['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<!-- Add Driver modal -->
<div class="modal-overlay" id="modal-add-driver">
  <div class="modal" style="max-width:520px;">
    <div class="modal-title">🧑‍✈️ Add Driver</div>
    <form method="POST"><input type="hidden" name="action" value="add_driver">
      <div class="form-row">
        <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" required></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" placeholder="09XXXXXXXXX"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>License No</label><input type="text" name="license_no" class="form-control" required></div>
        <div class="form-group"><label>License Expiry</label><input type="date" name="license_expiry" class="form-control" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>License Type</label><select name="license_type" class="form-control"><option>Professional</option><option>Non-Professional</option></select></div>
        <div class="form-group"><label>Behavior Score</label><input type="number" name="behavior_score" class="form-control" value="100" min="0" max="100"></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-add-driver')">Cancel</button><button type="submit" class="btn-primary">💾 Save Driver</button></div>
    </form>
  </div>
</div>

<!-- Edit Driver modal -->
<div class="modal-overlay" id="modal-edit-driver">
  <div class="modal" style="max-width:520px;">
    <div class="modal-title">✏️ Edit Driver</div>
    <form method="POST"><input type="hidden" name="action" value="edit_driver"><input type="hidden" name="driver_id" id="ed-id">
      <div class="form-row">
        <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="ed-name" class="form-control" required></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" id="ed-phone" class="form-control"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>License No</label><input type="text" name="license_no" id="ed-lic" class="form-control" required></div>
        <div class="form-group"><label>License Expiry</label><input type="date" name="license_expiry" id="ed-exp" class="form-control" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Behavior Score</label><input type="number" name="behavior_score" id="ed-score" class="form-control" min="0" max="100"></div>
        <div class="form-group"><label>Status</label><select name="status" id="ed-status" class="form-control"><option>Available</option><option>On Trip</option><option>Inactive</option></select></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit-driver')">Cancel</button><button type="submit" class="btn-primary">💾 Update Driver</button></div>
    </form>
  </div>
</div>

<?php elseif($page==='compliance'): ?>
<!-- ══ COMPLIANCE ══ -->
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Compliance</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <button class="btn-primary" onclick="openModal('modal-compliance')">+ Add Record</button>
</div>
<div class="flex-wrap" style="margin-bottom:20px;">
  <?php foreach([['Compliant',count(array_filter($compliance,fn($c)=>$c['status']==='Compliant')),'#2e7d32'],['Due Soon',count(array_filter($compliance,fn($c)=>$c['status']==='Due Soon')),'#d97706'],['Expired',count(array_filter($compliance,fn($c)=>$c['status']==='Expired')),'#dc2626']] as [$l,$val,$c]):?>
  <div class="stat-box"><div class="stat-value" style="color:<?=$c?>"><?=$val?></div><div class="stat-label"><?=$l?></div></div>
  <?php endforeach;?>
</div>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>📋 Compliance Records</div>
  <table class="data-table"><thead><tr><?php foreach(['Vehicle','Type','Due Date','Status','Actions'] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead><tbody>
  <?php foreach($compliance as $c): $vc=findById($vehicles,$c['vehicle_id']); ?>
  <tr>
    <td><strong><?=e($vc?$vc['plate']:'?')?></strong><div style="font-size:11px;color:#8fa592;"><?=e($vc?$vc['make'].' '.$vc['model']:'')?></div></td>
    <td><?=e($c['compliance_type'])?></td>
    <td style="color:<?=$c['status']==='Expired'?'#dc2626':($c['status']==='Due Soon'?'#d97706':'#627065')?>"><?=e($c['due_date'])?></td>
    <td><?=badge($c['status'],statusColor($c['status']))?></td>
    <td><form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;"><input type="hidden" name="action" value="delete_compliance"><input type="hidden" name="compliance_id" value="<?=e($c['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<!-- Add Compliance modal -->
<div class="modal-overlay" id="modal-compliance">
  <div class="modal" style="max-width:460px;">
    <div class="modal-title">📋 Add Compliance Record</div>
    <form method="POST"><input type="hidden" name="action" value="add_compliance">
      <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php foreach($vehicles as $v):?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option><?php endforeach;?></select></div>
      <div class="form-row">
        <div class="form-group"><label>Type</label><select name="compliance_type" class="form-control"><option>LTO Registration</option><option>Insurance</option><option>Emissions Test</option><option>Safety Inspection</option><option>Other</option></select></div>
        <div class="form-group"><label>Due Date</label><input type="date" name="due_date" class="form-control" required></div>
      </div>
      <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-compliance')">Cancel</button><button type="submit" class="btn-primary">💾 Save</button></div>
    </form>
  </div>
</div>

<?php elseif($page==='analytics'): ?>
<!-- ══ ANALYTICS ══ -->
<?php
  $totalExp   = array_sum(array_map(fn($e)=>(float)$e['amount'], $expenses));
  $fuelExp    = array_sum(array_map(fn($e)=>(float)$e['amount'], array_filter($expenses,fn($e)=>$e['expense_type']==='Fuel')));
  $maintExp   = array_sum(array_map(fn($e)=>(float)$e['amount'], array_filter($expenses,fn($e)=>$e['expense_type']==='Maintenance')));
  $totalMile  = array_sum(array_column($trips,'mileage_km'));
  $expTypes   = [];
  foreach($expenses as $exp){ $expTypes[$exp['expense_type']]=($expTypes[$exp['expense_type']]??0)+$exp['amount']; }
?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Analytics</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <a href="fvm.php?export_analytics=1" class="btn-primary"
       style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:9px 16px;font-size:13px;">
      📥 Download Excel
    </a>
    <button onclick="printAnalytics()" class="btn-secondary"
            style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;font-size:13px;">
      🖨️ Print Report
    </button>
  </div>
</div>
<div id="analytics-print">
<div id="print-header" style="display:none;margin-bottom:18pt;border-bottom:2px solid #1b5e20;padding-bottom:10pt;">
  <div style="font-size:20pt;font-weight:bold;color:#1b5e20;">FVM Fleet Analytics Report</div>
  <div style="font-size:10pt;color:#555;margin-top:4pt;">Generated: <?=date('F j, Y')?> &nbsp;·&nbsp; Logistics 2 · Fleet Vehicle Management</div>
</div>
<div class="flex-wrap" style="margin-bottom:22px;">
  <?php foreach([['Total Expenses',peso($totalExp),'#dc2626'],['Fuel Costs',peso($fuelExp),'#d97706'],['Maint. Costs',peso($maintExp),'#256427'],['Total Trips',count($trips),'#1d4ed8'],['Total Mileage',$totalMile.' km','#0d150e'],['Incidents',count($incidents),'#dc2626']] as [$l,$val,$c]):?>
  <div class="stat-box"><div class="stat-value" style="color:<?=$c?>"><?=$val?></div><div class="stat-label"><?=$l?></div></div>
  <?php endforeach;?>
</div>
<div class="grid-2" style="margin-bottom:20px;">
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>📈 Monthly Trend</div><div class="chart-container-tall"><canvas id="chartAnalyticsMonthly"></canvas></div></div>
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>🥧 Expense Breakdown</div><div class="chart-container-tall"><canvas id="chartExpType"></canvas></div></div>
</div>
<div class="grid-2" style="margin-bottom:20px;">
  <div class="card">
    <div class="card-title"><span class="card-title-bar"></span>📊 Cost Per Vehicle</div>
    <?php foreach($vehicles as $v):
      $vExp=array_sum(array_column(array_filter($expenses,fn($e)=>$e['vehicle_id']===$v['id']),'amount'));
      $vTrips=count(array_filter($trips,fn($t)=>$t['vehicle_id']===$v['id']));
      $maxExp=max(1,...array_map(fn($vv)=>array_sum(array_column(array_filter($expenses,fn($e)=>$e['vehicle_id']===$vv['id']),'amount')),$vehicles));
    ?>
    <div style="margin-bottom:12px;">
      <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;"><span style="font-weight:600;"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></span><span style="color:#dc2626;font-weight:700;"><?=peso($vExp)?></span></div>
      <div style="height:6px;background:#edf5ef;border-radius:3px;"><div style="width:<?=$maxExp>0?round($vExp/$maxExp*100):0?>%;height:100%;background:#256427;border-radius:3px;"></div></div>
      <div style="font-size:11px;color:#8fa592;margin-top:2px;">Trips: <?=$vTrips?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>⛽ Fleet Fuel Levels</div><div class="chart-container-tall"><canvas id="chartAnalyticsFuel"></canvas></div></div>
</div>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>📋 Expense Log</div>
  <table class="data-table"><thead><tr><?php foreach(['Code','Vehicle','Type','Amount','Date','Approved By'] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead>
  <tbody><?php foreach($expenses as $exp): $ve=findById($vehicles,$exp['vehicle_id']); ?><tr><td style="color:#2e7d32;font-weight:600;"><?=e($exp['expense_code']??'')?></td><td><?=e($ve?$ve['plate']:'?')?></td><td><?=e($exp['expense_type'])?></td><td style="color:#dc2626;font-weight:700;"><?=peso($exp['amount'])?></td><td style="color:#8fa592;"><?=e($exp['expense_date'])?></td><td style="color:#8fa592;"><?=e($exp['approved_by'])?></td></tr><?php endforeach;?></tbody></table>
</div>
</div><!-- /analytics-print -->


<?php elseif($page==='expenses'): ?>
<!-- ══ EXPENSES ══ -->
<?php
  $veFilter = $_GET['ve'] ?? 'all';
  $teFilter = $_GET['te'] ?? 'all';
  $filteredExp = $expenses;
  if($veFilter!=='all') $filteredExp=array_filter($filteredExp,fn($e)=>$e['vehicle_id']===$veFilter);
  if($teFilter!=='all') $filteredExp=array_filter($filteredExp,fn($e)=>$e['expense_type']===$teFilter);
  $expTypesList = array_unique(array_column($expenses,'expense_type'));
  $vExpTotals=[]; foreach($vehicles as $v){$vExpTotals[$v['id']]=array_sum(array_column(array_filter($expenses,fn($e)=>$e['vehicle_id']===$v['id']),'amount'));} arsort($vExpTotals);
  $expMonthly=[]; foreach($expenses as $exp){$m=substr($exp['expense_date'],0,7);$expMonthly[$m]=($expMonthly[$m]??0)+$exp['amount'];} ksort($expMonthly);
?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Finance</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <button class="btn-primary" onclick="openModal('modal-expense')">+ Add Expense</button>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center;">
  <span style="font-size:11.5px;color:#627065;font-weight:600;">Vehicle:</span>
  <a href="fvm.php?page=expenses&ve=all&te=<?=e($teFilter)?>" style="text-decoration:none;font-size:11.5px;padding:5px 12px;border-radius:20px;border:1px solid #dceade;background:<?=$veFilter==='all'?'#256427':'#fff'?>;color:<?=$veFilter==='all'?'#fff':'#256427'?>;">All</a>
  <?php foreach($vehicles as $v):?><a href="fvm.php?page=expenses&ve=<?=e($v['id'])?>&te=<?=e($teFilter)?>" style="text-decoration:none;font-size:11.5px;padding:5px 12px;border-radius:20px;border:1px solid #dceade;background:<?=$veFilter===$v['id']?'#256427':'#fff'?>;color:<?=$veFilter===$v['id']?'#fff':'#256427'?>;"><?=e($v['plate'])?></a><?php endforeach;?>
  &nbsp;<span style="font-size:11.5px;color:#627065;font-weight:600;">Type:</span>
  <a href="fvm.php?page=expenses&ve=<?=e($veFilter)?>&te=all" style="text-decoration:none;font-size:11.5px;padding:5px 12px;border-radius:20px;border:1px solid #dceade;background:<?=$teFilter==='all'?'#256427':'#fff'?>;color:<?=$teFilter==='all'?'#fff':'#256427'?>;">All</a>
  <?php foreach($expTypesList as $et):?><a href="fvm.php?page=expenses&ve=<?=e($veFilter)?>&te=<?=urlencode($et)?>" style="text-decoration:none;font-size:11.5px;padding:5px 12px;border-radius:20px;border:1px solid #dceade;background:<?=$teFilter===$et?'#256427':'#fff'?>;color:<?=$teFilter===$et?'#fff':'#256427'?>;"><?=e($et)?></a><?php endforeach;?>
</div>
<div class="flex-wrap" style="margin-bottom:22px;">
  <div class="stat-box"><div class="stat-value" style="color:#dc2626;"><?=peso(array_sum(array_column(array_values($filteredExp),'amount')))?></div><div class="stat-label">Filtered Total</div></div>
  <div class="stat-box"><div class="stat-value" style="color:#256427;"><?=count($filteredExp)?></div><div class="stat-label">Records</div></div>
  <div class="stat-box"><div class="stat-value" style="color:#dc2626;"><?=peso(array_sum(array_column($expenses,'amount')))?></div><div class="stat-label">All-time Total</div></div>
</div>
<div class="grid-2" style="margin-bottom:20px;">
  <div class="card">
    <div class="card-title"><span class="card-title-bar"></span>📊 Cost by Vehicle</div>
    <?php $maxV=max(1,...array_values($vExpTotals)); foreach($vExpTotals as $vid=>$amt): $vv=findById($vehicles,$vid); ?>
    <div style="margin-bottom:12px;">
      <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;"><span style="font-weight:600;"><?=e($vv?$vv['plate']:'?')?></span><span style="color:#dc2626;font-weight:700;"><?=peso($amt)?></span></div>
      <div style="height:7px;background:#edf5ef;border-radius:4px;"><div style="width:<?=$maxV>0?round($amt/$maxV*100):0?>%;height:100%;background:#256427;border-radius:4px;"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card"><div class="card-title"><span class="card-title-bar"></span>📈 Monthly Trend</div><div class="chart-container-tall"><canvas id="chartExpTrend"></canvas></div></div>
</div>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>💰 Expense Records</div>
  <table class="data-table"><thead><tr><?php foreach(['Code','Vehicle','Type','Amount','Date','Approved By',''] as $h):?><th><?=$h?></th><?php endforeach;?></tr></thead>
  <tbody><?php foreach($filteredExp as $exp): $ve=findById($vehicles,$exp['vehicle_id']); ?>
  <tr>
    <td style="color:#2e7d32;font-weight:600;"><?=e($exp['expense_code']??'')?></td>
    <td><strong><?=e($ve?$ve['plate']:'?')?></strong><div style="font-size:11px;color:#8fa592;"><?=e($ve?$ve['make'].' '.$ve['model']:'')?></div></td>
    <td><?=badge($exp['expense_type'],match($exp['expense_type']){'Fuel'=>'#d97706','Maintenance'=>'#256427','Incident'=>'#dc2626','Insurance'=>'#1d4ed8',default=>'#627065'})?></td>
    <td style="color:#dc2626;font-weight:700;"><?=peso($exp['amount'])?></td>
    <td style="color:#8fa592;"><?=e($exp['expense_date'])?></td>
    <td style="color:#8fa592;"><?=e($exp['approved_by'])?></td>
    <td><form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;"><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="expense_id" value="<?=e($exp['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form></td>
  </tr>
  <?php endforeach; ?></tbody></table>
</div>
<div class="modal-overlay" id="modal-expense">
  <div class="modal" style="max-width:460px;">
    <div class="modal-title">💰 Add Expense</div>
    <form method="POST"><input type="hidden" name="action" value="add_expense">
      <div class="form-row">
        <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php foreach($vehicles as $v):?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Type</label><select name="expense_type" class="form-control"><option>Fuel</option><option>Maintenance</option><option>Incident</option><option>Toll/Misc</option><option>Insurance</option><option>Registration</option><option>Parts</option><option>Other</option></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Amount (₱)</label><input type="number" name="amount" class="form-control" required></div>
        <div class="form-group"><label>Date</label><input type="date" name="expense_date" class="form-control" value="<?=$today?>" required></div>
      </div>
      <div class="form-group"><label>Approved By</label><input type="text" name="approved_by" class="form-control" value="Finance Officer"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-expense')">Cancel</button><button type="submit" class="btn-primary">💾 Save</button></div>
    </form>
  </div>
</div>

<?php elseif($page==='reminders'): ?>
<!-- ══ REMINDERS ══ -->
<?php
  $upcoming = array_values(array_filter($reminders,fn($r)=>!$r['dismissed']&&strtotime($r['due_date'])>=strtotime($today)));
  $overdue  = array_values(array_filter($reminders,fn($r)=>!$r['dismissed']&&strtotime($r['due_date'])<strtotime($today)));
  $allDismissed = sbGet('fvm_reminders',['dismissed'=>'eq.true','order'=>'due_date.desc','limit'=>20]);
?>
<div class="page-header-row">
  <div><div class="eyebrow"><span class="eyebrow-bar"></span>Alerts</div><div class="page-title"><?=e($ptitle)?></div><div class="page-sub"><?=e($psub)?></div></div>
  <button class="btn-primary" onclick="openModal('modal-reminder')">+ Add Reminder</button>
</div>
<?php if(count($overdue)>0): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 18px;margin-bottom:18px;">
  <div style="font-weight:700;color:#dc2626;margin-bottom:10px;">🚨 Overdue (<?=count($overdue)?>)</div>
  <?php foreach($overdue as $r): $vr=findById($vehicles,$r['vehicle_id']); $dl=(int)floor((strtotime($today)-strtotime($r['due_date']))/86400); ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #fecaca;">
    <div><div style="font-weight:600;"><?=e($r['reminder_type'])?> — <?=e($vr?$vr['plate']:'')?></div><div style="font-size:11.5px;color:#dc2626;"><?=e($r['due_date'])?> · <?=$dl?> days overdue</div><?php if($r['notes']):?><div style="font-size:11px;color:#9ca3af;"><?=e($r['notes'])?></div><?php endif;?></div>
    <div style="display:flex;gap:6px;">
      <form method="POST" style="display:inline;"><input type="hidden" name="action" value="dismiss_reminder"><input type="hidden" name="reminder_id" value="<?=e($r['id'])?>"><button type="submit" class="btn-secondary" style="font-size:11px;">✓ Dismiss</button></form>
      <form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;"><input type="hidden" name="action" value="delete_reminder"><input type="hidden" name="reminder_id" value="<?=e($r['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="card" style="margin-bottom:18px;">
  <div class="card-title"><span class="card-title-bar"></span>🔔 Upcoming (<?=count($upcoming)?>)</div>
  <?php if(empty($upcoming)): ?><div style="text-align:center;color:#8fa592;padding:24px;">No upcoming reminders 🎉</div>
  <?php else: foreach($upcoming as $r): $vr=findById($vehicles,$r['vehicle_id']); $dl=(int)floor((strtotime($r['due_date'])-strtotime($today))/86400); $uc=$dl<=7?'#dc2626':($dl<=30?'#d97706':'#256427'); ?>
  <div class="row-item">
    <div>
      <div style="font-weight:600;font-size:13.5px;"><?=e($r['reminder_type'])?></div>
      <div style="font-size:11.5px;color:#627065;"><?=e($vr?$vr['plate'].' — '.$vr['make'].' '.$vr['model']:'')?></div>
      <div style="font-size:11.5px;margin-top:3px;">Due: <strong style="color:<?=$uc?>"><?=e($r['due_date'])?></strong> · <span style="color:<?=$uc?>;font-weight:600;"><?=$dl?> days left</span></div>
      <?php if($r['notes']):?><div style="font-size:11px;color:#9ca3af;"><?=e($r['notes'])?></div><?php endif;?>
    </div>
    <div style="display:flex;gap:6px;align-items:center;">
      <?=badge($dl<=7?'Urgent':($dl<=30?'Soon':'OK'),$uc)?>
      <form method="POST" style="display:inline;"><input type="hidden" name="action" value="dismiss_reminder"><input type="hidden" name="reminder_id" value="<?=e($r['id'])?>"><button type="submit" class="btn-secondary" style="font-size:11px;">✓ Done</button></form>
      <form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;"><input type="hidden" name="action" value="delete_reminder"><input type="hidden" name="reminder_id" value="<?=e($r['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>
<?php if(!empty($allDismissed)): ?>
<div class="card">
  <div class="card-title"><span class="card-title-bar"></span>✅ Dismissed</div>
  <?php foreach($allDismissed as $r): $vr=findById($vehicles,$r['vehicle_id']); ?>
  <div class="row-item" style="opacity:0.5;">
    <div><div style="font-weight:600;font-size:13px;text-decoration:line-through;"><?=e($r['reminder_type'])?></div><div style="font-size:11.5px;color:#627065;"><?=e($vr?$vr['plate']:'')?> · <?=e($r['due_date'])?></div></div>
    <form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;"><input type="hidden" name="action" value="delete_reminder"><input type="hidden" name="reminder_id" value="<?=e($r['id'])?>"><button type="submit" class="btn-danger" style="font-size:10px;padding:3px 8px;">🗑</button></form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="modal-overlay" id="modal-reminder">
  <div class="modal" style="max-width:440px;">
    <div class="modal-title">🔔 Add Reminder</div>
    <form method="POST"><input type="hidden" name="action" value="add_reminder">
      <div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><?php foreach($vehicles as $v):?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> — <?=e($v['make'])?> <?=e($v['model'])?></option><?php endforeach;?></select></div>
      <div class="form-row">
        <div class="form-group"><label>Type</label><select name="reminder_type" class="form-control"><option>LTO Renewal</option><option>Insurance Renewal</option><option>Oil Change</option><option>PMS Schedule</option><option>Tire Rotation</option><option>Brake Inspection</option><option>Emission Test</option><option>Custom</option></select></div>
        <div class="form-group"><label>Due Date</label><input type="date" name="due_date" class="form-control" required></div>
      </div>
      <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control" placeholder="Optional"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn-secondary" onclick="closeModal('modal-reminder')">Cancel</button><button type="submit" class="btn-primary">💾 Save</button></div>
    </form>
  </div>
</div>

<?php endif; ?>

<!-- Footer -->
<div style="margin-top:40px;padding-top:20px;border-top:1px solid #dceade;display:flex;justify-content:space-between;align-items:center;">
  <div style="font-size:11px;color:#8fa592;">FVM · Logistics 2 · Powered by Supabase</div>
  <div style="font-size:11px;color:#8fa592;"><?=date('F j, Y · H:i')?></div>
</div>
</div><!-- /.page-anim -->
</div><!-- /.main -->

<script>
// Modal helpers
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(el=>{el.addEventListener('click',e=>{if(e.target===el)el.classList.remove('open');});});

// Inspection modal
function openInspect(vid,plate,fuel,mileage){
  document.getElementById('inspect-vid').value=vid;
  document.getElementById('inspect-title').textContent='🔍 Inspection — '+plate;
  document.getElementById('inspect-fuel').value=fuel;
  document.getElementById('inspect-mileage').value=mileage;
  document.getElementById('inspect-result').value='OK';
  document.getElementById('fail-warning').style.display='none';
  openModal('modal-inspect');
}
function checkFail(v){document.getElementById('fail-warning').style.display=v==='FAIL'?'block':'none';}

// QR card print
function printSingleCard(id){
  var el=document.getElementById(id);
  var w=window.open('','_blank','width=400,height=600');
  w.document.write('<html><head><title>Driver ID</title><link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet"><style>body{font-family:Outfit,sans-serif;padding:20px;}.qr-card-header{background:linear-gradient(135deg,#14401a,#256427);color:#fff;border-radius:10px;padding:12px 16px;margin-bottom:16px;}.qr-card-org{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.6);}.qr-card-title{font-family:"DM Serif Display",serif;font-size:15px;color:#fff;}.qr-driver-name{font-family:"DM Serif Display",serif;font-size:20px;color:#0d150e;}.qr-driver-detail{font-size:11.5px;color:#627065;margin:2px 0;}.qr-id-number{font-size:10px;font-weight:700;color:#627065;letter-spacing:1px;text-transform:uppercase;margin-top:10px;}</style></head><body>');
  w.document.write(el.innerHTML);
  w.document.write('</body></html>');
  w.document.close();
  setTimeout(()=>{w.print();},800);
}

// Dispatch Kanban drag & drop
<?php if($page==='dispatch'): ?>
['pending','in_progress','completed'].forEach(function(key) {
  var el = document.getElementById('cards-' + key);
  if (!el) return;
  new Sortable(el, {
    group: 'trips', animation: 150, ghostClass: 'sortable-ghost', draggable: '.kanban-card',
    onEnd: function(evt) {
      if (evt.from === evt.to) return;
      var tripId  = evt.item.dataset.tripId;
      var newSt   = evt.to.dataset.status;
      var newKey  = newSt.toLowerCase().replace(/ /g, '_');
      var f = document.createElement('form');
      f.method = 'POST'; f.action = 'fvm.php?page=dispatch';
      [['action','move_trip'],['trip_id',tripId],['newStatus',newKey]].forEach(function(pair) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = pair[0]; i.value = pair[1];
        f.appendChild(i);
      });
      document.body.appendChild(f); f.submit();
    }
  });
});
<?php endif; ?>

// Charts
<?php if($page==='dashboard'): ?>
new Chart(document.getElementById('chartMonthly'),{type:'bar',data:{labels:<?=$chartLabels?>,datasets:[{label:'Expenses (₱)',data:<?=$chartValues?>,backgroundColor:'rgba(46,125,50,0.7)',borderColor:'#2e7d32',borderWidth:2,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'₱'+v.toLocaleString()},grid:{color:'#f0f4f1'}},x:{grid:{display:false}}}}});
new Chart(document.getElementById('chartDonut'),{type:'doughnut',data:{labels:['Active','In Maintenance','Inactive'],datasets:[{data:<?=$donutData?>,backgroundColor:['#2e7d32','#d97706','#dc2626'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{display:false}}}});
new Chart(document.getElementById('chartFuel'),{type:'bar',data:{labels:<?=$fuelLabels?>,datasets:[{label:'Fuel %',data:<?=$fuelData?>,backgroundColor:<?=$fuelData?>.map(v=>v>30?'rgba(46,125,50,0.7)':'rgba(220,38,38,0.7)'),borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{max:100,ticks:{callback:v=>v+'%'},grid:{color:'#f0f4f1'}},y:{grid:{display:false}}}}});
<?php elseif($page==='analytics'): ?>
new Chart(document.getElementById('chartAnalyticsMonthly'),{type:'line',data:{labels:<?=$chartLabels?>,datasets:[{label:'Expenses',data:<?=$chartValues?>,borderColor:'#2e7d32',backgroundColor:'rgba(46,125,50,0.08)',borderWidth:2.5,fill:true,tension:0.4,pointBackgroundColor:'#2e7d32',pointRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'₱'+v.toLocaleString()},grid:{color:'#f0f4f1'}},x:{grid:{display:false}}}}});
new Chart(document.getElementById('chartExpType'),{type:'pie',data:{labels:<?=json_encode(array_keys($expTypes))?>,datasets:[{data:<?=json_encode(array_values($expTypes))?>,backgroundColor:['#2e7d32','#d97706','#1d4ed8','#dc2626','#8b5cf6'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:11},padding:12}}}}});
new Chart(document.getElementById('chartAnalyticsFuel'),{type:'bar',data:{labels:<?=$fuelLabels?>,datasets:[{label:'Fuel %',data:<?=$fuelData?>,backgroundColor:<?=$fuelData?>.map(v=>v>30?'rgba(46,125,50,0.7)':'rgba(220,38,38,0.7)'),borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{max:100,ticks:{callback:v=>v+'%'},grid:{color:'#f0f4f1'}},y:{grid:{display:false}}}}});
<?php elseif($page==='expenses'): ?>
<?php $em=[]; foreach($expenses as $ex){$m=substr($ex['expense_date'],0,7);$em[$m]=($em[$m]??0)+$ex['amount'];} ksort($em); ?>
new Chart(document.getElementById('chartExpTrend'),{type:'bar',data:{labels:<?=json_encode(array_keys($em))?>,datasets:[{label:'Expenses',data:<?=json_encode(array_values($em))?>,backgroundColor:'rgba(37,100,39,0.7)',borderColor:'#256427',borderWidth:2,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'₱'+v.toLocaleString()},grid:{color:'#f0f4f1'}},x:{grid:{display:false}}}}});
<?php endif; ?>

// ── Trip Tracking Panel ──────────────────────────────────────────────────────
var trackMap      = null;
var trackMarker   = null;
var trackPolyline = null;
var trackPath     = [];
var trackInterval = null;
var currentTripId = null;
var currentTrip   = null;
var pingCount     = 0;
var allTracking   = <?=$trackingJson??'{}'?>;
var _originPin    = null;
var _destPin      = null;
var _routeLine    = null;

// Geocode via Nominatim

// ── Trip timer countdowns (for In Progress cards) ────────────────────────────
function updateTripTimers(){
  document.querySelectorAll('.trip-timer-wrap').forEach(function(wrap){
    var dispatched=wrap.getAttribute('data-dispatched');
    var timerMin=parseInt(wrap.getAttribute('data-timer-min'))||0;
    var display=wrap.querySelector('.trip-timer-display');
    if(!display||!dispatched||!timerMin)return;
    var elapsed=Math.floor((Date.now()-new Date(dispatched).getTime())/1000);
    var totalSec=timerMin*60;
    var remaining=totalSec-elapsed;
    if(remaining<=0){
      var over=Math.abs(remaining);
      var hOver=Math.floor(over/3600);
      var mOver=Math.floor((over%3600)/60);
      var sOver=over%60;
      display.textContent=(hOver>0?hOver+'h ':'')+String(mOver).padStart(2,'0')+'m '+String(sOver).padStart(2,'0')+'s OVERDUE';
      display.style.color='#dc2626';
      wrap.querySelector('div').style.color='#dc2626';
    } else {
      var h=Math.floor(remaining/3600);
      var m=Math.floor((remaining%3600)/60);
      var s=remaining%60;
      display.textContent=(h>0?h+'h ':'')+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
      display.style.color=remaining<300?'#dc2626':(remaining<600?'#d97706':'#2e7d32');
    }
  });
}
updateTripTimers();
setInterval(updateTripTimers,1000);

function geocodeTP(place,cb){
  fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q='+encodeURIComponent(place+', Philippines'))
    .then(function(r){return r.json();})
    .then(function(res){cb(res&&res[0]?{lat:parseFloat(res[0].lat),lng:parseFloat(res[0].lon)}:null);})
    .catch(function(){cb(null);});
}

// Fetch OSRM driving route
function fetchRouteTP(oLat,oLng,dLat,dLng,cb){
  var url='https://router.project-osrm.org/route/v1/driving/'+oLng+','+oLat+';'+dLng+','+dLat+'?overview=full&geometries=geojson';
  fetch(url).then(function(r){return r.json();}).then(function(data){
    cb(data.routes&&data.routes[0]?data.routes[0].geometry.coordinates:null);
  }).catch(function(){cb(null);});
}

function openTracking(trip) {
  currentTripId = trip.id;
  currentTrip   = trip;
  pingCount     = 0;
  _arrivedNotified = false;
  _tpDestLat = null; _tpDestLng = null;
  var banner = document.getElementById('tp-arrival-banner');
  if (banner) banner.style.display = 'none';

  // Populate header
  document.getElementById('tp-trip-code').textContent = trip.trip_code || 'Trip';
  document.getElementById('tp-route').textContent     = trip.origin + ' → ' + trip.destination;
  // Populate ETA bar
  var etaBar = document.getElementById('tp-eta-bar');
  if(etaBar && trip.eta_minutes > 0){
    etaBar.style.display='flex';
    var eh=Math.floor(trip.eta_minutes/60), em=trip.eta_minutes%60;
    document.getElementById('tp-eta-val').textContent = (eh>0?eh+'h ':'')+em+'min';
    document.getElementById('tp-dist-val').textContent = trip.route_km ? trip.route_km+' km' : '—';
    var sl=document.getElementById('tp-route-sug-lbl');
    if(sl) sl.textContent = trip.route_sug || '';
  } else if(etaBar) { etaBar.style.display='none'; }

  // Populate driver/plate footer
  document.getElementById('tp-driver-name').textContent = trip.driver || '—';
  document.getElementById('tp-plate-info').textContent  = trip.plate  || '—';
  document.getElementById('tp-signal-dot').style.background = '#dc2626';
  document.getElementById('tp-signal-txt').textContent = 'Waiting for driver GPS…';

  // Show panel
  document.getElementById('tracking-panel').classList.add('open');
  document.getElementById('tracking-overlay').style.display = 'block';

  setTimeout(function() {
    if (!trackMap) {
      trackMap = L.map('track-map', {zoomControl:true}).setView([14.5995, 120.9842], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'© OpenStreetMap',maxZoom:19}).addTo(trackMap);
      trackPolyline = L.polyline([], {color:'#256427', weight:4, opacity:0.85}).addTo(trackMap);
    } else {
      // Clear previous trip's pins and route when switching trips
      if(_originPin)  { trackMap.removeLayer(_originPin);  _originPin=null; }
      if(_destPin)    { trackMap.removeLayer(_destPin);    _destPin=null; }
      if(_routeLine)  { trackMap.removeLayer(_routeLine);  _routeLine=null; }
      if(trackMarker) { trackMap.removeLayer(trackMarker); trackMarker=null; }
      trackPath=[];
      if(trackPolyline) trackPolyline.setLatLngs([]);
    }
    trackMap.invalidateSize();

    var oIco=L.divIcon({html:'<div style="background:#256427;color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:14px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35)">🚩</div>',iconSize:[28,28],iconAnchor:[14,14],className:''});
    var dIco=L.divIcon({html:'<div style="background:#dc2626;color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:14px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35)">📍</div>',iconSize:[28,28],iconAnchor:[14,28],className:''});

    var oCoords=null, dCoords=null;

    function tryDrawRoute(){
      if(!oCoords||!dCoords) return;
      // Draw grey planned route via OSRM
      fetchRouteTP(oCoords.lat,oCoords.lng,dCoords.lat,dCoords.lng,function(coords){
        if(!coords)return;
        if(_routeLine) trackMap.removeLayer(_routeLine);
        var latlngs=coords.map(function(c){return[c[1],c[0]];});
        _routeLine=L.polyline(latlngs,{color:'#64748b',weight:4,opacity:.45,dashArray:'8 5'}).addTo(trackMap);
        _routeLine.bringToBack();
        // Fit map to show full route
        trackMap.fitBounds(_routeLine.getBounds(),{padding:[40,40]});
      });
    }

    geocodeTP(trip.origin,function(c){
      if(!c)return; oCoords=c;
      _originPin=L.marker([c.lat,c.lng],{icon:oIco}).bindPopup('<b>🚩 Origin</b><br>'+trip.origin).addTo(trackMap);
      tryDrawRoute();
    });
    geocodeTP(trip.destination,function(c){
      if(!c)return; dCoords=c;
      _tpDestLat = c.lat; _tpDestLng = c.lng; // store for proximity detection
      _destPin=L.marker([c.lat,c.lng],{icon:dIco}).bindPopup('<b>📍 Destination</b><br>'+trip.destination).addTo(trackMap);
      tryDrawRoute();
    });

    // Load existing GPS if already tracking
    var existing = allTracking[trip.id];
    if (existing && existing.lat) updateTrackUI(existing);
  }, 380);

  // Poll every 5 seconds — same cadence as driver pings
  clearInterval(trackInterval);
  trackInterval = setInterval(function() { pollTracking(trip.id); }, 5000);
  pollTracking(trip.id);
}

// Haversine for dispatch-side distance check
function haversineTP(la1,ln1,la2,ln2){
  var R=6371000,r=Math.PI/180;
  var dLat=(la2-la1)*r,dLng=(ln2-ln1)*r;
  var a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(la1*r)*Math.cos(la2*r)*Math.sin(dLng/2)*Math.sin(dLng/2);
  return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

var _tpDestLat = null, _tpDestLng = null;
var _arrivedNotified = false;

function pollTracking(tid) {
  fetch('fvm.php?get_tracking=1&trip_id=' + tid)
    .then(function(r){return r.json();})
    .then(function(res) {
      if (res.ok && res.data) {
        updateTrackUI(res.data);
        // Replay full trail on first successful poll
        if (res.trail && res.trail.length > 1 && trackPath.length <= 1 && trackPolyline) {
          trackPath = res.trail.map(function(p){return [parseFloat(p.lat), parseFloat(p.lng)];});
          trackPolyline.setLatLngs(trackPath);
        }
      }
      // Check if driver confirmed arrival via Driver Portal
      if (res.arrived && !_arrivedNotified) {
        _arrivedNotified = true;
        showArrivalBanner(res.notes, tid);
      }
      pingCount++;
      document.getElementById('tp-pings').textContent = pingCount;
    })
    .catch(function(){});
}

function showArrivalBanner(notes, tid) {
  var banner = document.getElementById('tp-arrival-banner');
  var noteEl = document.getElementById('tp-arrival-note');
  var tidEl  = document.getElementById('tp-arrival-trip-id');
  if (!banner) return;
  noteEl.textContent = notes || 'Driver confirmed arrival at destination';
  tidEl.value = tid;
  banner.style.display = 'block';
  // Flash signal green
  var sigDot = document.getElementById('tp-signal-dot');
  var sigTxt = document.getElementById('tp-signal-txt');
  if (sigDot) sigDot.style.background = '#22c55e';
  if (sigTxt) sigTxt.textContent = 'Driver has arrived at destination!';
  // Notification sound
  try {
    var ctx = new (window.AudioContext||window.webkitAudioContext)();
    var osc = ctx.createOscillator(); var gain = ctx.createGain();
    osc.connect(gain); gain.connect(ctx.destination);
    osc.frequency.value = 880; gain.gain.setValueAtTime(0.3, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime+0.8);
    osc.start(ctx.currentTime); osc.stop(ctx.currentTime+0.8);
  } catch(e){}
}

function updateTrackUI(d) {
  if (!d || !d.lat) return;
  var lat = parseFloat(d.lat);
  var lng = parseFloat(d.lng);
  var spd = parseFloat(d.speed_kmh || 0);
  var hdg = parseFloat(d.heading || 0);
  var acc = parseFloat(d.accuracy || 0);
  var mv  = d.movement_status || 'Offline';
  var ts  = d.updated_at;

  document.getElementById('tp-speed').textContent    = spd > 0 ? Math.round(spd) : '0';
  document.getElementById('tp-heading').textContent  = hdg > 0 ? Math.round(hdg) + '°' : '—';
  document.getElementById('tp-accuracy').textContent = acc > 0 ? '±' + Math.round(acc) + 'm' : '—';

  if (ts) {
    var secsAgo = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    document.getElementById('tp-last-ping').textContent =
      secsAgo < 60 ? secsAgo + 's ago' : Math.floor(secsAgo/60) + 'm ago';
    var sigDot = document.getElementById('tp-signal-dot');
    var sigTxt = document.getElementById('tp-signal-txt');
    if (sigDot && sigTxt && !_arrivedNotified) {
      if (secsAgo < 30) {
        sigDot.style.background = '#22c55e';
        sigTxt.textContent = 'Live · driver transmitting';
      } else if (secsAgo < 120) {
        sigDot.style.background = '#f59e0b';
        sigTxt.textContent = 'Signal weak · ' + (secsAgo < 60 ? secsAgo + 's ago' : Math.floor(secsAgo/60) + 'm ago');
      } else {
        sigDot.style.background = '#dc2626';
        sigTxt.textContent = 'Signal lost · last seen ' + Math.floor(secsAgo/60) + 'm ago';
      }
    }
  }

  // Movement badge
  var badge = document.getElementById('tp-mv-badge');
  var mvMap = {
    'Moving':  {cls:'mv-moving',  icon:'🟢', label:'Moving · '+Math.round(spd)+' km/h'},
    'Idle':    {cls:'mv-idle',    icon:'🟡', label:'Idle'},
    'Stopped': {cls:'mv-stopped', icon:'⚪', label:'Stopped'},
    'Offline': {cls:'mv-offline', icon:'🔴', label:'Offline'},
  };
  var info = mvMap[mv] || mvMap['Offline'];
  badge.className = 'movement-badge ' + info.cls;
  badge.innerHTML = info.icon + ' ' + info.label;

  // Dispatch-side proximity alert (within 100m of destination)
  if (_tpDestLat && _tpDestLng && !_arrivedNotified) {
    var dist = haversineTP(lat, lng, _tpDestLat, _tpDestLng);
    if (dist <= 100) {
      var sigTxt3 = document.getElementById('tp-signal-txt');
      if (sigTxt3) sigTxt3.textContent = '📍 Near destination — ' + Math.round(dist) + 'm away';
    }
  }

  // Update map marker
  if (trackMap) {
    var pos = [lat, lng];
    var headingArrow = hdg > 0 ? getHeadingIcon(hdg, mv) : getHeadingIcon(0, mv);
    if (!trackMarker) {
      trackMarker = L.marker(pos, {icon: headingArrow}).addTo(trackMap);
    } else {
      trackMarker.setLatLng(pos);
      trackMarker.setIcon(headingArrow);
    }
    trackPath.push(pos);
    if (trackPolyline) trackPolyline.setLatLngs(trackPath);
    if (acc > 0) {
      if (window._accCircle) trackMap.removeLayer(window._accCircle);
      window._accCircle = L.circle(pos, {radius:acc, color:'#256427', fillColor:'#256427', fillOpacity:0.08, weight:1}).addTo(trackMap);
    }
    trackMap.setView(pos, trackMap.getZoom() < 14 ? 15 : trackMap.getZoom());
  }
}

function getHeadingIcon(hdg, mv) {
  var color = mv === 'Moving' ? '#22c55e' : mv === 'Idle' ? '#f59e0b' : '#94a3b8';
  var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36">'
    + '<circle cx="18" cy="18" r="12" fill="'+color+'" stroke="#fff" stroke-width="3"/>'
    + '<polygon points="18,4 22,18 18,15 14,18" fill="#fff" transform="rotate('+hdg+',18,18)"/>'
    + '</svg>';
  return L.divIcon({html:svg, iconSize:[36,36], iconAnchor:[18,18], className:''});
}

function closeTracking() {
  document.getElementById('tracking-panel').classList.remove('open');
  document.getElementById('tracking-overlay').style.display = 'none';
  clearInterval(trackInterval);
  currentTripId = null;
  _arrivedNotified = false;
  _tpDestLat = null; _tpDestLng = null;
  var banner = document.getElementById('tp-arrival-banner');
  if (banner) banner.style.display = 'none';
}

// ── End Tracking Panel ────────────────────────────────────────────


// ═══ DISPATCH MAP & GEOCODING ══════════════════════════════════════════════
var dpMap=null, dpOriginMkr=null, dpDestMkr=null, dpRoutePoly=null;
var dpOCoords=null, dpDCoords=null;
var dpGeoTimers={origin:null,dest:null};

/* Custom diamond-shaped draggable pin icons */
function dpMakeIcon(color, emojiHtml, label){
  var html=
    '<div style="position:relative;display:flex;flex-direction:column;align-items:center;filter:drop-shadow(0 4px 8px rgba(0,0,0,.38));">'+
      '<div style="background:'+color+';color:#fff;border-radius:50% 50% 50% 0;width:38px;height:38px;display:flex;align-items:center;justify-content:center;font-size:17px;border:3px solid #fff;transform:rotate(-45deg);">'+
        '<span style="transform:rotate(45deg)">'+emojiHtml+'</span>'+
      '</div>'+
      '<div style="background:'+color+';color:#fff;font-size:9px;font-weight:800;padding:2px 8px;border-radius:100px;margin-top:3px;white-space:nowrap;border:2px solid #fff;letter-spacing:0.6px;">'+label+'</div>'+
    '</div>';
  return L.divIcon({html:html,iconSize:[38,56],iconAnchor:[19,52],popupAnchor:[0,-54],className:''});
}

/* Init map with two persistent draggable pins */
function dpInitMap(){
  if(dpMap) return;
  var el=document.getElementById('dp-map');
  if(!el) return;
  dpMap=L.map('dp-map',{zoomControl:true,attributionControl:false}).setView([14.5995,120.9842],13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(dpMap);

  var oIco=dpMakeIcon('#1a6e1c','&#128681;','ORIGIN');
  var dIco=dpMakeIcon('#dc2626','&#128205;','DEST');

  var c=dpMap.getCenter();
  var oStart=dpOCoords||{lat:c.lat+0.005,lng:c.lng-0.006};
  var dStart=dpDCoords||{lat:c.lat-0.005,lng:c.lng+0.006};

  dpOriginMkr=L.marker([oStart.lat,oStart.lng],{icon:oIco,draggable:true,autoPan:true})
    .bindPopup('<div style="text-align:center;min-width:120px;"><b style="color:#1a6e1c;font-size:13px;">&#128681; ORIGIN</b><br><small style="color:#627065;">Drag me to the exact<br>pickup location</small></div>')
    .addTo(dpMap);

  dpDestMkr=L.marker([dStart.lat,dStart.lng],{icon:dIco,draggable:true,autoPan:true})
    .bindPopup('<div style="text-align:center;min-width:120px;"><b style="color:#dc2626;font-size:13px;">&#128205; DESTINATION</b><br><small style="color:#627065;">Drag me to the exact<br>drop-off location</small></div>')
    .addTo(dpMap);

  if(dpOCoords) dpOriginMkr.setLatLng([dpOCoords.lat,dpOCoords.lng]);
  if(dpDCoords) dpDestMkr.setLatLng([dpDCoords.lat,dpDCoords.lng]);

  dpOriginMkr.on('click',function(){ dpOriginMkr.openPopup(); });
  dpDestMkr.on('click',function(){ dpDestMkr.openPopup(); });

  dpOriginMkr.on('dragend',function(e){
    var ll=e.target.getLatLng();
    dpOCoords={lat:ll.lat,lng:ll.lng};
    document.getElementById('dp-origin-lat').value=ll.lat;
    document.getElementById('dp-origin-lng').value=ll.lng;
    dpUpdateCoordsDisplay();
    dpReverseAndFill('origin',ll.lat,ll.lng);
    if(dpDCoords) dpDrawRoute();
  });

  dpDestMkr.on('dragend',function(e){
    var ll=e.target.getLatLng();
    dpDCoords={lat:ll.lat,lng:ll.lng};
    document.getElementById('dp-dest-lat').value=ll.lat;
    document.getElementById('dp-dest-lng').value=ll.lng;
    dpUpdateCoordsDisplay();
    dpReverseAndFill('dest',ll.lat,ll.lng);
    if(dpOCoords) dpDrawRoute();
  });
}

function dpUpdateCoordsDisplay(){
  var oel=document.getElementById('dp-origin-coords');
  var del=document.getElementById('dp-dest-coords');
  if(oel) oel.textContent=dpOCoords ? dpOCoords.lat.toFixed(5)+', '+dpOCoords.lng.toFixed(5) : 'not pinned';
  if(del) del.textContent=dpDCoords ? dpDCoords.lat.toFixed(5)+', '+dpDCoords.lng.toFixed(5) : 'not pinned';
}

function dpReverseAndFill(type,lat,lng){
  var statusEl=document.getElementById('dp-'+type+'-status');
  var inputEl=document.getElementById(type==='origin'?'dp-origin':'dp-dest');
  if(statusEl) statusEl.innerHTML='<span style="color:#b45309;">&#128204; Pinned &mdash; resolving address&hellip;</span>';
  fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat='+lat+'&lon='+lng+'&zoom=17')
    .then(function(r){return r.json();})
    .then(function(res){
      var addr=res&&res.address;
      var name=addr?(addr.road||addr.suburb||addr.village||addr.city_district||addr.city||'Pinned Location'):'Pinned Location';
      if(inputEl) inputEl.value=name;
      if(statusEl) statusEl.innerHTML=
        '&#128204; <b>Manually pinned</b> &mdash; '+lat.toFixed(5)+', '+lng.toFixed(5)+
        ' <span style="background:#fef08a;border:1px solid #fcd34d;border-radius:4px;padding:1px 6px;font-size:10px;color:#92400e;font-weight:800;">PINNED</span>';
    })
    .catch(function(){
      if(statusEl) statusEl.innerHTML=
        '&#128204; <b>Manually pinned</b> &mdash; '+lat.toFixed(5)+', '+lng.toFixed(5)+
        ' <span style="background:#fef08a;border:1px solid #fcd34d;border-radius:4px;padding:1px 6px;font-size:10px;color:#92400e;font-weight:800;">PINNED</span>';
    });
}

function dpOpenPinMap(){
  var mw=document.getElementById('dp-map-wrap');
  if(mw) mw.style.display='block';
  var btn=document.getElementById('dp-open-map-btn');
  if(btn) btn.style.display='none';
  dpInitMap();
  setTimeout(function(){ if(dpMap) dpMap.invalidateSize(); },150);
}

function dpGeoDebounce(type){
  clearTimeout(dpGeoTimers[type]);
  dpGeoTimers[type]=setTimeout(function(){dpGeocode(type);},900);
}

function dpGeocode(type){
  var inputEl=document.getElementById(type==='origin'?'dp-origin':'dp-dest');
  var statusEl=document.getElementById('dp-'+type+'-status');
  var val=(inputEl?inputEl.value:'').trim();
  if(val.length<4) return;
  statusEl.innerHTML='&#9203; Looking up location&hellip;';
  fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q='+encodeURIComponent(val+', Philippines'))
    .then(function(r){return r.json();})
    .then(function(res){
      if(!res||!res[0]){statusEl.innerHTML='&#9888;&#65039; Not found. Try a more specific address.';return;}
      var lat=parseFloat(res[0].lat),lng=parseFloat(res[0].lon);
      var shortName=(res[0].display_name||val).substring(0,60);
      statusEl.innerHTML='&#128205; '+shortName+(shortName.length>=60?'&hellip;':'')+' <span style="color:#2e7d32;font-size:10px;font-weight:700;">&#10003;</span>';
      if(type==='origin'){
        dpOCoords={lat:lat,lng:lng};
        document.getElementById('dp-origin-lat').value=lat;
        document.getElementById('dp-origin-lng').value=lng;
        if(dpOriginMkr) dpOriginMkr.setLatLng([lat,lng]);
      } else {
        dpDCoords={lat:lat,lng:lng};
        document.getElementById('dp-dest-lat').value=lat;
        document.getElementById('dp-dest-lng').value=lng;
        if(dpDestMkr) dpDestMkr.setLatLng([lat,lng]);
      }
      dpUpdateCoordsDisplay();
      dpShowMap();
    }).catch(function(){statusEl.innerHTML='&#9888;&#65039; Geocode failed.';});
}

function dpGeoNow(){
  if(!navigator.geolocation){alert('Geolocation not available on this browser.');return;}
  var statusEl=document.getElementById('dp-origin-status');
  statusEl.innerHTML='&#9203; Getting your current location&hellip;';
  navigator.geolocation.getCurrentPosition(function(pos){
    var lat=pos.coords.latitude,lng=pos.coords.longitude;
    dpOCoords={lat:lat,lng:lng};
    document.getElementById('dp-origin-lat').value=lat;
    document.getElementById('dp-origin-lng').value=lng;
    if(dpOriginMkr) dpOriginMkr.setLatLng([lat,lng]);
    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat='+lat+'&lon='+lng)
      .then(function(r){return r.json();})
      .then(function(res){
        var name=res.address?(res.address.road||res.address.suburb||res.address.city_district||res.address.city||'Current Location'):'Current Location';
        document.getElementById('dp-origin').value=name;
        statusEl.innerHTML='&#128205; Current location pinned &mdash; '+lat.toFixed(5)+', '+lng.toFixed(5);
        dpUpdateCoordsDisplay();
        dpShowMap();
      }).catch(function(){
        statusEl.innerHTML='&#128205; GPS: '+lat.toFixed(5)+', '+lng.toFixed(5);
        dpUpdateCoordsDisplay();
        dpShowMap();
      });
  },function(err){
    var msgs={1:'Location permission denied',2:'Position unavailable',3:'GPS timeout'};
    statusEl.innerHTML='&#10060; '+(msgs[err.code]||'Unknown GPS error')+'.';
  },{enableHighAccuracy:true,timeout:12000});
}

function dpDrawRoute(){
  if(!dpOCoords||!dpDCoords||!dpMap) return;
  // Fetch OSRM route for map display + route intelligence
  var url='https://router.project-osrm.org/route/v1/driving/'+dpOCoords.lng+','+dpOCoords.lat+';'+dpDCoords.lng+','+dpDCoords.lat+'?overview=full&geometries=geojson&alternatives=true';
  fetch(url).then(function(r){return r.json();}).then(function(data){
    if(!data.routes||!data.routes[0]) return;
    if(dpRoutePoly) dpMap.removeLayer(dpRoutePoly);
    var mainRoute=data.routes[0];
    var coords=mainRoute.geometry.coordinates.map(function(c){return[c[1],c[0]];});
    dpRoutePoly=L.polyline(coords,{color:'#2e7d32',weight:5,opacity:.75}).addTo(dpMap);
    dpMap.fitBounds(dpRoutePoly.getBounds(),{padding:[30,30]});
    var distKm=(mainRoute.distance/1000).toFixed(1);
    var durMin=Math.round(mainRoute.duration/60);
    document.getElementById('dp-dist-label').textContent='&#128207; '+distKm+' km  &#9201; ~'+durMin+' min';
    var timerIn=document.getElementById('dp-timer');
    if(timerIn&&!timerIn.value) timerIn.value=durMin;
    // ── Route Intelligence ────────────────────────────────────────────────────
    dpShowRouteIntelligence(data.routes, distKm, durMin);
  }).catch(function(){});
}

function dpShowRouteIntelligence(routes, distKm, durMin){
  var box=document.getElementById('dp-route-box');
  if(!box) return;
  box.style.display='block';
  // ETA
  var etaH=Math.floor(durMin/60), etaM=durMin%60;
  document.getElementById('dp-eta-val').textContent=(etaH>0?etaH+'h ':'')+etaM+'min';
  document.getElementById('dp-dist-val').textContent=distKm+' km';
  // Fuel estimate (avg 8 km/L for fleet vehicles = 125ml/km)
  var fuelL=(parseFloat(distKm)/8).toFixed(1);
  document.getElementById('dp-fuel-val').textContent=fuelL+' L';
  // Hidden form fields
  document.getElementById('dp-eta-min').value=durMin;
  document.getElementById('dp-route-km').value=distKm;
  // Build 3 route suggestion options
  var suggestions=[
    { icon:'⚡', label:'Fastest',  desc:durMin+'min · '+distKm+'km · Tollway preferred',    score:'fastest',  toll:true  },
    { icon:'🛡️', label:'Safest',   desc:Math.round(durMin*1.12)+'min · '+distKm+' km · Avoids expressways', score:'safest',   toll:false },
    { icon:'💰', label:'Cheapest', desc:Math.round(durMin*1.08)+'min · '+distKm+' km · No tolls / side roads', score:'cheapest', toll:false },
  ];
  // If OSRM returned alternatives, use real data for options 2 & 3
  if(routes.length>=2){
    var alt=routes[1];
    var altKm=(alt.distance/1000).toFixed(1);
    var altMin=Math.round(alt.duration/60);
    suggestions[2].desc=altMin+'min · '+altKm+'km · Alternate road';
  }
  var sugEl=document.getElementById('dp-suggestions');
  if(!sugEl) return;
  sugEl.innerHTML='';
  suggestions.forEach(function(s,i){
    var btn=document.createElement('button');
    btn.type='button';
    btn.style.cssText='display:flex;align-items:center;gap:10px;width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid #dceade;background:#fff;cursor:pointer;text-align:left;transition:all .15s;font-family:inherit;';
    btn.innerHTML=
      '<span style="font-size:18px;">'+s.icon+'</span>'+
      '<div style="flex:1;"><div style="font-size:12px;font-weight:700;color:#1b5e20;">'+s.label+'</div>'+
      '<div style="font-size:11px;color:#627065;">'+s.desc+'</div></div>'+
      '<div id="dp-sug-chk-'+i+'" style="width:18px;height:18px;border-radius:50%;border:2px solid #dceade;flex-shrink:0;"></div>';
    btn.onclick=function(){
      // Deselect all
      document.querySelectorAll('#dp-suggestions button').forEach(function(b){
        b.style.background='#fff'; b.style.borderColor='#dceade';
      });
      document.querySelectorAll('[id^=dp-sug-chk-]').forEach(function(el){el.style.background='';el.style.borderColor='#dceade';});
      // Select this
      btn.style.background='#f0fdf4'; btn.style.borderColor='#4ade80';
      document.getElementById('dp-sug-chk-'+i).style.background='#22c55e';
      document.getElementById('dp-sug-chk-'+i).style.borderColor='#22c55e';
      document.getElementById('dp-route-sug').value=s.icon+' '+s.label+': '+s.desc;
      var selBox=document.getElementById('dp-selected-route');
      var selLbl=document.getElementById('dp-sel-lbl');
      if(selBox){selBox.style.display='block';}
      if(selLbl){selLbl.textContent=s.label+' — '+s.desc;}
    };
    sugEl.appendChild(btn);
    // Auto-select Fastest on first load
    if(i===0) btn.click();
  });
}

function dpShowMap(){
  dpOpenPinMap();
  if(dpOCoords&&dpDCoords){
    dpDrawRoute();
    setTimeout(function(){
      dpMap.fitBounds([[dpOCoords.lat,dpOCoords.lng],[dpDCoords.lat,dpDCoords.lng]],{padding:[40,40]});
    },160);
  } else if(dpOCoords){
    setTimeout(function(){ dpMap.setView([dpOCoords.lat,dpOCoords.lng],15); },160);
  } else if(dpDCoords){
    setTimeout(function(){ dpMap.setView([dpDCoords.lat,dpDCoords.lng],15); },160);
  }
}

// Live dispatch timestamp clock
function dpUpdateTs(){
  var el=document.getElementById('dp-timestamp');
  if(el) el.textContent=new Date().toLocaleString('en-PH',{weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
dpUpdateTs();
setInterval(dpUpdateTs,1000);

// Reset dispatch modal state on open
var _origOpenModal=window.openModal;
window.openModal=function(id){
  _origOpenModal(id);
  if(id==='modal-dispatch'){
    dpOCoords=null;dpDCoords=null;
    if(dpMap){dpMap.remove();dpMap=null;dpOriginMkr=null;dpDestMkr=null;dpRoutePoly=null;}
    ['dp-origin-lat','dp-origin-lng','dp-dest-lat','dp-dest-lng'].forEach(function(i){var el=document.getElementById(i);if(el)el.value='';});
    ['dp-origin-status','dp-dest-status'].forEach(function(i){var el=document.getElementById(i);if(el)el.textContent='';});
    var dl=document.getElementById('dp-dist-label');if(dl)dl.textContent='';
    var mw=document.getElementById('dp-map-wrap');if(mw)mw.style.display='none';
    var ob=document.getElementById('dp-open-map-btn');if(ob)ob.style.display='';
    // Reset route intelligence
    var rb=document.getElementById('dp-route-box');if(rb)rb.style.display='none';
    var sd=document.getElementById('dp-selected-route');if(sd)sd.style.display='none';
    ['dp-eta-min','dp-route-km','dp-route-sug'].forEach(function(i){var el=document.getElementById(i);if(el)el.value='';});
  }
};

// ═══════════════════════════════════════════════════════════════════════════

// Scroll header
window.addEventListener('scroll',()=>document.getElementById('hdr')?.classList?.toggle('scrolled',scrollY>18));

/* ── FVM Sidebar Toggle & Topbar ── */
(function(){
  var sidebar  = document.getElementById('fvmSidebar');
  var topnav   = document.getElementById('fvmTopNav');
  var mainEl   = document.getElementById('fvmMain');
  var toggleBtn= document.getElementById('fvmToggleBtn');
  var overlay  = document.getElementById('sidebarOverlay');

  function isMobile(){ return window.innerWidth <= 768; }

  toggleBtn.addEventListener('click', function(e){
    e.stopPropagation();
    if(isMobile()){
      sidebar.classList.toggle('show');
      overlay.classList.toggle('show');
    } else {
      var collapsed = sidebar.classList.toggle('collapsed');
      topnav.classList.toggle('expanded', collapsed);
      mainEl.classList.toggle('expanded', collapsed);
    }
  });

  overlay.addEventListener('click', function(){
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
  });

  window.addEventListener('resize', function(){
    if(!isMobile()){
      sidebar.classList.remove('show');
      overlay.classList.remove('show');
    }
  });

  /* Clock — Philippines time */
  function updateClock(){
    var now = new Date();
    var ph  = new Date(now.toLocaleString('en-US',{timeZone:'Asia/Manila'}));
    var h=ph.getHours(), m=ph.getMinutes(), ampm=h>=12?'PM':'AM';
    h=h%12||12;
    var timeStr=h+':'+(m<10?'0':'')+m+' '+ampm;
    var days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var dateStr=days[ph.getDay()]+', '+months[ph.getMonth()]+' '+ph.getDate();
    var tc=document.getElementById('fvmClock');
    var td=document.getElementById('fvmDate');
    if(tc) tc.textContent=timeStr;
    if(td) td.textContent=dateStr;
  }
  updateClock();
  setInterval(updateClock,1000);

  /* Alerts dropdown */
  var alertsBtn = document.getElementById('fvmAlertsBtn');
  var alertsDrop= document.getElementById('fvmAlertsDropdown');
  if(alertsBtn && alertsDrop){
    alertsBtn.addEventListener('click', function(e){
      e.stopPropagation();
      alertsDrop.classList.toggle('open');
    });
    document.addEventListener('click', function(){
      alertsDrop.classList.remove('open');
    });
    alertsDrop.addEventListener('click', function(e){ e.stopPropagation(); });
  }
})();


// Edit Vehicle modal
function openEditVehicle(v) {
  document.getElementById('ev-id').value     = v.id;
  document.getElementById('ev-plate').value  = v.plate;
  document.getElementById('ev-make').value   = v.make;
  document.getElementById('ev-model').value  = v.model;
  document.getElementById('ev-year').value   = v.year;
  document.getElementById('ev-lto').value    = v.lto_expiry || '';
  document.getElementById('ev-ins').value    = v.ins_expiry || '';
  var typeEl   = document.getElementById('ev-type');
  var fuelEl   = document.getElementById('ev-fuel');
  var statEl   = document.getElementById('ev-status');
  if (typeEl)  Array.from(typeEl.options).forEach(o => o.selected = o.value === v.vehicle_type);
  if (fuelEl)  Array.from(fuelEl.options).forEach(o => o.selected = o.value === v.fuel_type);
  if (statEl)  Array.from(statEl.options).forEach(o => o.selected = o.value === v.status);
  openModal('modal-edit-vehicle');
}

// Edit Driver modal
function openEditDriver(d) {
  document.getElementById('ed-id').value    = d.id;
  document.getElementById('ed-name').value  = d.full_name;
  document.getElementById('ed-phone').value = d.phone || '';
  document.getElementById('ed-lic').value   = d.license_no;
  document.getElementById('ed-exp').value   = d.license_expiry || '';
  document.getElementById('ed-score').value = d.behavior_score;
  var statEl = document.getElementById('ed-status');
  if (statEl) Array.from(statEl.options).forEach(o => o.selected = o.value === d.status);
  openModal('modal-edit-driver');
}

// GPS Map
<?php if($page==='map'): ?>
var vehicleData = <?=$vehicleGeoJSON?>;
var map = L.map('fleet-map').setView([14.5995,120.9842],12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(map);
var markers = {};

function getColor(v){
  // If vehicle status isn't Active, show grey
  if(v.status !== 'Active' && v.status !== 'In Maintenance') return '#9ca3af';
  if(v.flagged) return '#dc2626';
  // Driver active = green dot; no driver logged in = grey/inactive dot
  if(v.driverActive) return '#2e7d32';
  return '#94a3b8'; // grey = vehicle active but no driver logged in
}

function mkIcon(v){
  var c = getColor(v);
  var pulse = v.driverActive && v.location === 'GPS Active';
  var inner = pulse
    ? '<div style="width:14px;height:14px;background:'+c+';border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 4px '+c+'44;"></div>'
    : '<div style="width:18px;height:18px;background:'+c+';border:3px solid #fff;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.3);"></div>';
  return L.divIcon({html:inner,iconSize:[20,20],iconAnchor:[10,10],popupAnchor:[0,-12],className:''});
}

vehicleData.forEach(v=>{
  var driverStatus = v.driverActive ? '🟢 Driver On Duty' : '⚫ No Driver Active';
  var gpsStatus = v.location === 'GPS Active' ? '📡 GPS Active' : '📍 ' + v.location;
  var m=L.marker([v.lat,v.lng],{icon:mkIcon(v)});
  m.bindPopup(`<div style="font-family:Outfit,sans-serif;min-width:200px;">
    <strong style="font-size:14px;">${v.plate} — ${v.make} ${v.model}</strong>
    <p style="font-size:12px;color:#627065;margin:4px 0;">${gpsStatus}</p>
    <p style="font-size:12px;color:#627065;">⛽ ${v.fuelLevel}% &nbsp;|&nbsp; 📏 ${v.mileage.toLocaleString()} km</p>
    <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;">
      <div style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:100px;background:${getColor(v)}18;color:${getColor(v)};border:1px solid ${getColor(v)}44;display:inline-block;">${v.status}</div>
      <div style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:100px;background:#00000010;color:#334155;border:1px solid #33415530;display:inline-block;">${driverStatus}</div>
    </div>
  </div>`,{maxWidth:260});
  m.addTo(map); markers[v.id]={marker:m,data:v};
});
var latLngs=vehicleData.map(v=>[v.lat,v.lng]); if(latLngs.length) map.fitBounds(latLngs,{padding:[40,40]});
function filterMap(s){
  Object.values(markers).forEach(({marker,data})=>{ if(s==='all'||data.status===s) marker.addTo(map); else map.removeLayer(marker); });
}
function focusVehicle(lat,lng,id){ map.setView([lat,lng],15,{animate:true}); if(markers[id]) markers[id].marker.openPopup(); }

// Poll real vehicle positions from Supabase every 10 seconds
setInterval(function(){
  fetch('fvm.php?get_all_positions=1')
    .then(function(r){return r.json();})
    .then(function(res){
      if(!res.ok||!res.vehicles)return;
      // Also get active drivers list
      return fetch('fvm.php?get_active_drivers=1').then(function(r2){return r2.json();}).then(function(ad){
        var activeVids = ad.active_vehicle_ids || [];
        res.vehicles.forEach(function(v){
          if(markers[v.id]&&v.lat&&v.lng){
            var newLat=parseFloat(v.lat), newLng=parseFloat(v.lng);
            markers[v.id].marker.setLatLng([newLat,newLng]);
            markers[v.id].data.lat=newLat;
            markers[v.id].data.lng=newLng;
            markers[v.id].data.location=v.location||markers[v.id].data.location; // sync GPS Active string
            markers[v.id].data.driverActive = activeVids.indexOf(v.id) !== -1;
            markers[v.id].marker.setIcon(mkIcon(markers[v.id].data));
          }
        });
      });
    }).catch(function(){});
},5000);
<?php endif; ?>
</script>
<!-- PHOTO LIGHTBOX -->
<div id="photo-modal" onclick="closePhotoModal()" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.88);align-items:center;justify-content:center;cursor:zoom-out;padding:20px;">
  <div style="position:relative;max-width:94vw;max-height:90vh;" onclick="event.stopPropagation()">
    <img id="photo-modal-img" src="" alt="" style="max-width:100%;max-height:84vh;border-radius:12px;box-shadow:0 8px 48px rgba(0,0,0,.7);display:block;">
    <button onclick="closePhotoModal()" style="position:absolute;top:-13px;right:-13px;width:32px;height:32px;border-radius:50%;background:#fff;border:none;font-size:16px;cursor:pointer;font-weight:700;">✕</button>
    <div id="photo-modal-cap" style="text-align:center;color:rgba(255,255,255,.65);font-size:12px;margin-top:10px;"></div>
    <div style="text-align:center;margin-top:6px;"><a id="photo-modal-dl" href="#" download="photo.jpg" target="_blank" style="color:#4ade80;font-size:12px;font-weight:700;text-decoration:none;">⬇ Download</a></div>
  </div>
</div>
<script>
function openPhotoModal(s,c){var m=document.getElementById('photo-modal');if(!m)return;document.getElementById('photo-modal-img').src=s;var cp=document.getElementById('photo-modal-cap');if(cp)cp.textContent=c||'';var dl=document.getElementById('photo-modal-dl');if(dl)dl.href=s;m.style.display='flex';document.body.style.overflow='hidden';}
function closePhotoModal(){var m=document.getElementById('photo-modal');if(m)m.style.display='none';var i=document.getElementById('photo-modal-img');if(i)i.src='';document.body.style.overflow='';}
document.addEventListener('click',function(e){var el=e.target.closest?e.target.closest('.photo-thumb'):null;if(!el)return;var s=el.getAttribute('data-photo')||el.src||'';var c=el.getAttribute('data-caption')||'';if(s)openPhotoModal(s,c);});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closePhotoModal();});
</script>
<!-- ══ ANALYTICS PRINT ═══════════════════════════════════════════════════════ -->
<script>
function printAnalytics(){
  var src = document.getElementById('analytics-print');
  if(!src){ window.print(); return; }

  // ── 1. Stat boxes ────────────────────────────────────────────────────────────
  var statsHtml = '';
  src.querySelectorAll('.stat-box').forEach(function(b){
    var val = b.querySelector('.stat-value');
    var lbl = b.querySelector('.stat-label');
    statsHtml += '<div class="p-stat-box">'
      + '<div class="p-stat-value">' + (val ? val.innerHTML : '') + '</div>'
      + '<div class="p-stat-label">' + (lbl ? lbl.textContent : '') + '</div>'
      + '</div>';
  });

  // ── 2. Capture charts as base64 PNG images ───────────────────────────────────
  function canvasImg(id, w, h){
    var c = document.getElementById(id);
    if(!c) return '';
    // Chart.js renders on transparent background — draw onto white first
    var tmp = document.createElement('canvas');
    tmp.width  = w || c.width  || 700;
    tmp.height = h || c.height || 300;
    var ctx = tmp.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, tmp.width, tmp.height);
    ctx.drawImage(c, 0, 0, tmp.width, tmp.height);
    return tmp.toDataURL('image/png');
  }

  var imgMonthly = canvasImg('chartAnalyticsMonthly', 700, 280);
  var imgPie     = canvasImg('chartExpType',           380, 280);
  var imgFuel    = canvasImg('chartAnalyticsFuel',     380, 280);

  // ── 3. Cost Per Vehicle rows ─────────────────────────────────────────────────
  var cpvRows = '';
  var cpvCard = src.querySelector('.grid-2 .card');
  if(cpvCard){
    cpvCard.querySelectorAll('div[style*="margin-bottom:12px"]').forEach(function(item){
      var spans    = item.querySelectorAll('span');
      var label    = spans[0] ? spans[0].textContent.trim() : '';
      var cost     = spans[1] ? spans[1].textContent.trim() : '';
      var innerBar = item.querySelector('div > div[style*="background:#256427"]');
      var pct      = 0;
      if(innerBar){ var m = innerBar.getAttribute('style').match(/width:(\d+(\.\d+)?)%/); if(m) pct = parseFloat(m[1]).toFixed(1); }
      var tripsEl  = item.querySelector('div[style*="font-size:11px"]');
      var tripsText = tripsEl ? tripsEl.textContent.trim() : '';
      cpvRows += '<tr>'
        + '<td>' + label + '</td>'
        + '<td style="color:#dc2626;font-weight:700;">' + cost + '</td>'
        + '<td><div style="width:140px;height:7px;background:#edf5ef;border-radius:3px;display:inline-block;vertical-align:middle;">'
        + '<div style="width:'+pct+'%;height:100%;background:#256427;border-radius:3px;-webkit-print-color-adjust:exact;print-color-adjust:exact;"></div></div></td>'
        + '<td style="color:#8fa592;font-size:10px;">' + tripsText + '</td>'
        + '</tr>';
    });
  }

  // ── 4. Expense Log table ─────────────────────────────────────────────────────
  var expRows = '';
  var expTable = src.querySelector('.data-table');
  if(expTable){
    expTable.querySelectorAll('tbody tr').forEach(function(tr){
      expRows += '<tr>';
      tr.querySelectorAll('td:not(:last-child)').forEach(function(td){ // skip delete btn column
        expRows += '<td>' + td.textContent.trim() + '</td>';
      });
      expRows += '</tr>';
    });
  }

  // ── 5. Build print HTML ───────────────────────────────────────────────────────
  var monthlySection = imgMonthly
    ? '<div class="p-section"><div class="p-chart-title">📈 Monthly Expense Trend</div>'
      + '<img src="'+imgMonthly+'" style="width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;"></div>'
    : '';
  var smallCharts = '';
  if(imgPie || imgFuel){
    smallCharts = '<div class="p-charts-row p-section">';
    if(imgPie)  smallCharts += '<div class="p-chart-half"><div class="p-chart-title">🥧 Expense Breakdown</div><img src="'+imgPie+'"  style="width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;"></div>';
    if(imgFuel) smallCharts += '<div class="p-chart-half"><div class="p-chart-title">⛽ Fleet Fuel Levels</div><img src="'+imgFuel+'" style="width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;"></div>';
    smallCharts += '</div>';
  }

  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
    + '<title>FVM Analytics Report</title>'
    + '<style>'
    + '*{box-sizing:border-box;}'
    + 'body{font-family:Arial,sans-serif;font-size:11pt;color:#000;background:#fff;margin:0;padding:20pt;}'
    + '.p-header{border-bottom:2.5px solid #1b5e20;padding-bottom:10pt;margin-bottom:16pt;display:flex;justify-content:space-between;align-items:flex-end;}'
    + '.p-header h1{font-size:18pt;color:#1b5e20;margin:0;}'
    + '.p-header p{font-size:9pt;color:#555;margin:0;text-align:right;}'
    + '.p-stats{display:flex;flex-wrap:wrap;gap:8pt;margin-bottom:16pt;}'
    + '.p-stat-box{border:1px solid #ddd;padding:7pt 12pt;border-radius:4pt;min-width:90pt;}'
    + '.p-stat-value{font-size:15pt;font-weight:bold;}'
    + '.p-stat-label{font-size:7.5pt;color:#666;text-transform:uppercase;letter-spacing:0.4px;margin-top:2pt;}'
    + '.p-section{margin-bottom:18pt;page-break-inside:avoid;}'
    + '.p-section h2{font-size:11pt;font-weight:bold;border-bottom:1px solid #e0e0e0;padding-bottom:5pt;margin:0 0 10pt;color:#1b5e20;}'
    + '.p-charts-row{display:flex;gap:12pt;}'
    + '.p-chart-half{flex:1;min-width:0;}'
    + '.p-chart-title{font-size:10pt;font-weight:bold;color:#1b5e20;margin-bottom:6pt;}'
    + 'table{width:100%;border-collapse:collapse;font-size:9pt;}'
    + 'thead th{background:#1b5e20;color:#fff;padding:5pt 7pt;text-align:left;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
    + 'tbody td{padding:4pt 7pt;border-bottom:1px solid #eee;vertical-align:middle;}'
    + 'tbody tr:nth-child(even) td{background:#f7faf7;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
    + '@page{margin:1.5cm;size:A4 landscape;}'
    + '@media print{body{padding:0;}}'
    + '</style></head><body>'

    + '<div class="p-header">'
    + '<h1>📊 FVM Fleet Analytics Report</h1>'
    + '<p>Generated: <?=date('F j, Y')?><br>Fleet &amp; Vehicle Management</p>'
    + '</div>'

    + '<div class="p-stats">' + statsHtml + '</div>'

    + monthlySection
    + smallCharts

    + (cpvRows ? '<div class="p-section"><h2>📊 Cost Per Vehicle</h2>'
        + '<table><thead><tr><th>Vehicle</th><th>Total Cost</th><th>Proportion</th><th>Trips</th></tr></thead>'
        + '<tbody>' + cpvRows + '</tbody></table></div>' : '')

    + (expRows ? '<div class="p-section"><h2>📋 Expense Log</h2>'
        + '<table><thead><tr><th>Code</th><th>Vehicle</th><th>Type</th><th>Amount</th><th>Date</th><th>Approved By</th></tr></thead>'
        + '<tbody>' + expRows + '</tbody></table></div>' : '')

    + '</body></html>';

  var w = window.open('', '_blank', 'width=1200,height=900');
  if(!w){ alert('Please allow popups for this site to print the report.'); return; }
  w.document.open();
  w.document.write(html);
  w.document.close();
  // Wait for images to be fully painted before triggering print dialog
  w.addEventListener('load', function(){ w.focus(); w.print(); });
}
</script>

</body>
</html>