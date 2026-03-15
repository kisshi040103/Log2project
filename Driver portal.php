<?php
// ── Session: use project-local tmp folder to avoid XAMPP permission errors on Windows
$sessionPath = __DIR__ . '/tmp';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0777, true);
}
ini_set('session.save_path', $sessionPath);
session_start();

define('SB_URL', 'https://lvvfsgkxpulbpwrpyhuf.supabase.co/rest/v1');
define('SB_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imx2dmZzZ2t4cHVsYnB3cnB5aHVmIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzMxMzEwMDQsImV4cCI6MjA4ODcwNzAwNH0.AGC-gPrNxbqCLpm6EWtCjGjzJjgq228ZNb2i5KTy7JU');

function sb(string $m,string $t,array $b=[],array $p=[]): array {
    $url=SB_URL.'/'.$t; if($p) $url.='?'.http_build_query($p);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$m,
        CURLOPT_HTTPHEADER=>['apikey: '.SB_KEY,'Authorization: Bearer '.SB_KEY,'Content-Type: application/json','Prefer: return=representation'],
        CURLOPT_TIMEOUT=>10]);
    if($b) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($b));
    $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['ok'=>$code<400,'data'=>json_decode($res,true)??[],'code'=>$code];
}
function sbGet(string $t,array $p=[]): array { return sb('GET',$t,[],$p)['data']??[]; }
function sbPost(string $t,array $b): bool    { return sb('POST',$t,$b)['ok']; }
function sbPostFull(string $t,array $b): array { return sb('POST',$t,$b); }
function sbPatch(string $t,array $b,string $id): bool { return sb('PATCH',$t,$b,['id'=>'eq.'.$id])['ok']; }
function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES); }
function peso($n): string { return '₱'.number_format((float)$n,2); }
function nextCode(string $pfx,string $t,string $f): string {
    $rows=sbGet($t,['select'=>$f,'order'=>$f.'.desc']);
    $max=0;
    foreach($rows as $r){
        $v=$r[$f]??'';
        if(strpos($v,$pfx)===0){ $n=(int)substr($v,strlen($pfx)); if($n>$max) $max=$n; }
    }
    return $pfx.str_pad($max+1,3,'0',STR_PAD_LEFT);
}
$today=date('Y-m-d');

// ── LOGOUT ───────────────────────────────────────────────────────────────────
if(isset($_GET['logout'])){
    if(!empty($_SESSION['driver_id'])){
        sb('PATCH','fvm_drivers',['is_online'=>false,'last_seen'=>date('c')],['id'=>'eq.'.$_SESSION['driver_id']]);
    }
    session_destroy(); header('Location: driver%20portal.php'); exit;
}

// ── AJAX: HEARTBEAT (keeps driver marked online while portal is open) ──────────
if(isset($_GET['heartbeat'])){
    header('Content-Type: application/json');
    if(empty($_SESSION['driver_id'])){ echo json_encode(['ok'=>false]); exit; }
    sb('PATCH','fvm_drivers',['is_online'=>true,'last_seen'=>date('c')],['id'=>'eq.'.$_SESSION['driver_id']]);
    echo json_encode(['ok'=>true,'ts'=>date('c')]); exit;
}

// ── AJAX: GPS PING (session-protected) ───────────────────────────────────────
if(isset($_GET['gps_ping'])){
    header('Content-Type: application/json');
    if(empty($_SESSION['driver_id'])){ echo json_encode(['ok'=>false,'error'=>'Unauthenticated']); exit; }
    $in=json_decode(file_get_contents('php://input'),true)??[];
    $tid=$in['trip_id']??null; $lat=(float)($in['lat']??0); $lng=(float)($in['lng']??0);
    $spd=(float)($in['speed']??0); $hdg=(float)($in['heading']??0); $acc=(float)($in['accuracy']??0);
    $arrived=(bool)($in['arrived']??false);
    if(!$tid||($lat===0.0&&$lng===0.0)){ echo json_encode(['ok'=>false,'error'=>'Missing params']); exit; }
    $trip=sbGet('fvm_trips',['id'=>'eq.'.$tid,'select'=>'id,vehicle_id']);
    if(!$trip){ echo json_encode(['ok'=>false,'error'=>'Trip not found']); exit; }
    $vid=$trip[0]['vehicle_id'];
    $mv=$spd<2?'Stopped':($spd<10?'Idle':'Moving');
    $ts=date('c');
    // Always INSERT a new row for full trail history
    sbPost('fvm_trip_tracking',['trip_id'=>$tid,'vehicle_id'=>$vid,'lat'=>$lat,'lng'=>$lng,'speed_kmh'=>$spd,'heading'=>$hdg,'accuracy'=>$acc,'movement_status'=>$mv,'updated_at'=>$ts]);
    // Also PATCH fvm_vehicles so FVM live map sees current position immediately
    $vPatch=['lat'=>$lat,'lng'=>$lng,'location'=>'GPS Active','updated_at'=>$ts];
    sbPatch('fvm_vehicles',$vPatch,$vid);
    // If driver confirmed arrival, add a note to the trip
    if($arrived){
        sbPatch('fvm_trips',['notes'=>'Driver confirmed arrival at '.date('H:i').' on '.date('Y-m-d')],$tid);
    }
    echo json_encode(['ok'=>true,'status'=>$mv,'ts'=>$ts,'arrived'=>$arrived]); exit;
}

// ── AJAX: FUEL LOG ────────────────────────────────────────────────────────────
if(isset($_GET['log_fuel'])){
    ob_start();
    header('Content-Type: application/json');
    if(empty($_SESSION['driver_id'])){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Not logged in']); exit; }
    $in=json_decode(file_get_contents('php://input'),true)??[];
    $vid    = $in['vehicle_id']??null;
    $liters = (float)($in['liters']??0);
    $ppl    = (float)($in['price_per_liter']??0);
    $odo    = (int)($in['odometer_km']??0);
    $station= trim($in['station']??''  );
    $fa     = (float)($in['fuel_after']??0);   // float, not int (e.g. 20.5%)
    if(!$vid||$liters<=0||$ppl<=0){
        ob_end_clean();
        echo json_encode(['ok'=>false,'error'=>'Please fill in vehicle, liters and price per liter.']); exit;
    }
    $total   = round($liters*$ppl,2);
    $logCode = nextCode('F','fvm_fuel_logs','log_code');
    $driverName = $_SESSION['driver_name']??'Driver';

    // 1. Insert into fvm_fuel_logs
    // Note: total_cost may be a GENERATED column (liters * price_per_liter) in Supabase.
    // We omit it from the insert so Postgres computes it automatically.
    // If your schema has it as a plain column, run: ALTER TABLE fvm_fuel_logs ALTER COLUMN total_cost DROP DEFAULT;
    $res=sbPostFull('fvm_fuel_logs',[
        'log_code'        => $logCode,
        'vehicle_id'      => $vid,
        'driver_id'       => $_SESSION['driver_id'],
        'log_date'        => $today,
        'odometer_km'     => $odo,
        'liters'          => $liters,
        'price_per_liter' => $ppl,
        // 'total_cost' omitted — auto-computed by DB as liters * price_per_liter
        'station'         => $station,
        'notes'           => 'Driver self-log by '.$driverName,
    ]);
    // Use our locally-computed $total for the expense record regardless

    $ok=$res['ok'];

    if($ok){
        // 2. Update vehicle mileage + fuel level
        $vPatch=[];
        if($odo>0) $vPatch['mileage']=$odo;
        if($fa>0)  $vPatch['fuel_level']=min(100,(int)round($fa));
        if($vPatch) sbPatch('fvm_vehicles',$vPatch,$vid);

        // 3. Also post to fvm_expenses — FVM admin sees it in Expenses & Analytics
        sbPost('fvm_expenses',[
            'expense_code' => nextCode('E','fvm_expenses','expense_code'),
            'vehicle_id'   => $vid,
            'expense_type' => 'Fuel',
            'amount'       => $total,
            'expense_date' => $today,
            'approved_by'  => $driverName,
            'notes'        => $logCode.' · '.$liters.'L @ \u20b1'.$ppl.' · '.($station?:'-').' · Driver self-log',
        ]);
    }

    $sbError=null;
    if(!$ok){
        $d=$res['data']??[];
        $sbError=$d['message']??($d['hint']??('HTTP '.$res['code']));
    }
    ob_end_clean();
    echo json_encode(['ok'=>$ok,'total'=>$total,'error'=>$sbError,'log_code'=>$logCode]); exit;
}

// ── AJAX: INCIDENT REPORT ─────────────────────────────────────────────────────
if(isset($_GET['submit_report'])){
    ob_start(); // buffer any PHP warnings so they don't corrupt JSON
    header('Content-Type: application/json');
    if(empty($_SESSION['driver_id'])){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Not logged in']); exit; }
    $in=json_decode(file_get_contents('php://input'),true)??[];
    $desc=trim($in['description']??''); $vid=$in['vehicle_id']??null;
    $type=$in['incident_type']??'Other'; $sev=$in['severity']??'Minor';
    $lat=$in['lat']??null; $lng=$in['lng']??null; $tid=$in['trip_id']??null;
    $photoRaw=trim($in['photo_url']??'');
    // Store photo — base64 data URIs are fine for display; strip only if clearly invalid
    $photo=!empty($photoRaw)?$photoRaw:null;
    // Coerce lat/lng to float or null to avoid NOT NULL failures
    $latVal=($lat!==null&&$lat!=='')?  (float)$lat : null;
    $lngVal=($lng!==null&&$lng!=='')?  (float)$lng : null;
    if(!$desc||!$vid){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
    $payload=[
        'incident_code' => nextCode('I','fvm_incidents','incident_code'),
        'vehicle_id'    => $vid,
        'driver_id'     => $_SESSION['driver_id'],
        'trip_id'       => $tid?:null,
        'incident_type' => $type,
        'severity'      => $sev,           // ← real column
        'incident_date' => $today,
        'description'   => $desc,
        'status'        => 'Open',
    ];
    // Write lat/lng and photo as real columns when available
    if($latVal!==null) $payload['lat']=$latVal;
    if($lngVal!==null) $payload['lng']=$lngVal;
    if($photo)         $payload['photo_url']=$photo;

    $res=sbPostFull('fvm_incidents',$payload);
    $ok=$res['ok'];
    if($ok){
        // Deduct behavior score based on type + severity
        $deductTypes=['Accident','Traffic Violation','Injury','Major Breakdown'];
        if(in_array($type,$deductTypes)){
            $deduct=match($sev){
                'Critical'=>15,'Major'=>10,'Moderate'=>5,default=>2
            };
            $d=sbGet('fvm_drivers',['id'=>'eq.'.$_SESSION['driver_id'],'select'=>'behavior_score']);
            if($d) sbPatch('fvm_drivers',['behavior_score'=>max(0,(int)($d[0]['behavior_score']??100)-$deduct)],$_SESSION['driver_id']);
        }
    }
    // Extract actual Supabase error message for easier debugging
    $sbError=null;
    if(!$ok){
        $sbData=$res['data']??[];
        $sbError=$sbData['message']??($sbData['error']??('HTTP '.$res['code']));
    }
    ob_end_clean();
    echo json_encode(['ok'=>$ok,'error'=>$ok?null:$sbError,'http_code'=>$res['code']]); exit;
}

// ── AJAX: DRIVER EXPENSE LOG ─────────────────────────────────────────────────
if(isset($_GET['log_expense'])){
    ob_start();
    header('Content-Type: application/json');
    if(empty($_SESSION['driver_id'])){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Not logged in']); exit; }
    $in   = json_decode(file_get_contents('php://input'),true)??[];
    $vid  = $in['vehicle_id']??null;
    $etype= trim($in['expense_type']??'');
    $amt  = (float)($in['amount']??0);
    $note = trim($in['note']??'');
    $tid  = $in['trip_id']??null;
    if(!$vid||!$etype||$amt<=0){
        ob_end_clean();
        echo json_encode(['ok'=>false,'error'=>'Please fill in vehicle, type and amount.']); exit;
    }
    $driverName = $_SESSION['driver_name']??'Driver';
    $expCode    = nextCode('E','fvm_expenses','expense_code');
    $res = sbPostFull('fvm_expenses',[
        'expense_code' => $expCode,
        'vehicle_id'   => $vid,
        'expense_type' => $etype,
        'amount'       => $amt,
        'expense_date' => $today,
        'approved_by'  => $driverName,
        'notes'        => '[Driver Expense] '.($note?:$etype)
                         .' | Driver: '.$driverName
                         .($tid?' | Trip: '.$tid:''),
    ]);
    $ok = $res['ok'];
    $sbErr = null;
    if(!$ok){ $d=$res['data']??[]; $sbErr=$d['message']??($d['hint']??('HTTP '.$res['code'])); }
    ob_end_clean();
    echo json_encode(['ok'=>$ok,'expense_code'=>$expCode,'error'=>$sbErr]); exit;
}

// ── AJAX: UPDATE MILEAGE ──────────────────────────────────────────────────────
if(isset($_GET['update_mileage'])){
    header('Content-Type: application/json');
    if(empty($_SESSION['driver_id'])){ echo json_encode(['ok'=>false]); exit; }
    $in=json_decode(file_get_contents('php://input'),true)??[];
    $tid=$in['trip_id']??null; $km=(int)($in['mileage_km']??0);
    if(!$tid||$km<=0){ echo json_encode(['ok'=>false]); exit; }
    $ok=sbPatch('fvm_trips',['mileage_km'=>$km],$tid);
    $tr=sbGet('fvm_trips',['id'=>'eq.'.$tid,'select'=>'vehicle_id']);
    if($tr) sbPatch('fvm_vehicles',['mileage'=>$km],$tr[0]['vehicle_id']);
    echo json_encode(['ok'=>$ok]); exit;
}


// ── AJAX: START TRIP ──────────────────────────────────────────────────────────
if(isset($_GET['start_trip'])){
    ob_start(); header('Content-Type: application/json');
    if(empty($_SESSION['driver_id'])){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Not logged in']); exit; }
    $in=json_decode(file_get_contents('php://input'),true)??[];
    $tid=$in['trip_id']??null;
    if(!$tid){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'No trip_id']); exit; }
    // Only start if currently Pending
    $tr=sbGet('fvm_trips',['id'=>'eq.'.$tid,'select'=>'id,status,vehicle_id']);
    if(empty($tr)){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Trip not found']); exit; }
    if($tr[0]['status']!=='Pending'){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Trip already started']); exit; }
    $ok=sbPatch('fvm_trips',['status'=>'In Progress'],$tid);
    sbPatch('fvm_drivers',['status'=>'On Trip'],$_SESSION['driver_id']);
    ob_end_clean(); echo json_encode(['ok'=>$ok]); exit;
}

// ── AJAX: COMPLETE TRIP (photo proof + on-time points) ────────────────────────
// ── AJAX: COMPLETE TRIP ────────────────────────────────────────────────────────
if(isset($_GET['complete_trip'])){
    ob_start(); header('Content-Type: application/json');
    @ini_set('memory_limit','256M');
    if(empty($_SESSION['driver_id'])){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Not logged in']); exit; }

    $rawBody = file_get_contents('php://input');
    if(empty($rawBody)){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Empty request — photo may be too large. Try a smaller image.']); exit; }
    $in = json_decode($rawBody, true);
    if($in === null){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'JSON parse error: '.json_last_error_msg()]); exit; }

    $tid      = trim($in['trip_id']   ?? '');
    $photoUrl = trim($in['photo_url'] ?? '');
    $mileage  = (int)($in['mileage_km'] ?? 0);
    $lat      = isset($in['lat']) && $in['lat'] !== null ? (float)$in['lat'] : null;
    $lng      = isset($in['lng']) && $in['lng'] !== null ? (float)$in['lng'] : null;

    if(!$tid)     { ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'No trip ID.']); exit; }
    if(!$photoUrl){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'No proof photo attached.']); exit; }

    $tr = sbGet('fvm_trips',['id'=>'eq.'.$tid,'select'=>'*']);
    if(empty($tr)){ ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Trip not found.']); exit; }
    $trip = $tr[0];

    // On-time check
    $pointsAwarded = 0; $onTime = false;
    $etaMins      = (int)($trip['eta_minutes'] ?? $trip['dispatch_timer'] ?? 0);
    $dispatchedAt = $trip['dispatched_at'] ?? null;
    if($etaMins > 0 && $dispatchedAt){
        $elapsed = (time() - strtotime($dispatchedAt)) / 60;
        if($elapsed <= $etaMins){ $onTime = true; $pointsAwarded = 2; }
    }

    $completedTs = date('c');

    // Build patch — only columns we know exist + the migration columns
    // Try everything together; if proof_photo_url/completed_at don't exist yet,
    // fall back to status + mileage only (still marks trip done)
    $fullPatch = [
        'status'          => 'Completed',
        'proof_photo_url' => $photoUrl,
        'completed_at'    => $completedTs,
    ];
    if($mileage > 0)  $fullPatch['mileage_km']   = $mileage;
    if($lat !== null) $fullPatch['completed_lat'] = $lat;
    if($lng !== null) $fullPatch['completed_lng'] = $lng;

    $res = sb('PATCH','fvm_trips',$fullPatch,['id'=>'eq.'.$tid]);

    if(!$res['ok']){
        // proof_photo_url / completed_at columns may not exist — try minimal patch
        $minPatch = ['status' => 'Completed'];
        if($mileage > 0) $minPatch['mileage_km'] = $mileage;
        $res2 = sb('PATCH','fvm_trips',$minPatch,['id'=>'eq.'.$tid]);
        if(!$res2['ok']){
            $d  = $res2['data'] ?? [];
            $em = $d['message'] ?? ($d['hint'] ?? ('DB error HTTP '.$res2['code'].'. Run the Migration SQL shown in FVM → Dispatch to add the required columns.'));
            ob_end_clean(); echo json_encode(['ok'=>false,'error'=>$em]); exit;
        }
        // Minimal patch worked — trip is Completed. Photo lost but at least trip closes.
        // Encourage admin to run migration SQL.
    }

    // Update vehicle mileage
    if($mileage > 0) sb('PATCH','fvm_vehicles',['mileage'=>$mileage],['id'=>'eq.'.$trip['vehicle_id']]);

    // Mark driver Available — do NOT include assigned_vehicle_id (column may not exist)
    sb('PATCH','fvm_drivers',['status'=>'Available'],['id'=>'eq.'.$_SESSION['driver_id']]);

    // Award points if on time
    if($pointsAwarded > 0){
        $dRow   = sbGet('fvm_drivers',['id'=>'eq.'.$_SESSION['driver_id'],'select'=>'points']);
        $curPts = (int)($dRow[0]['points'] ?? 0);
        sb('PATCH','fvm_drivers',['points'=>$curPts+$pointsAwarded],['id'=>'eq.'.$_SESSION['driver_id']]);
        @sbPost('fvm_notifications',[
            'driver_id'  => $_SESSION['driver_id'],
            'trip_id'    => $tid,
            'type'       => 'points_awarded',
            'title'      => '🏆 +2 Points Earned!',
            'message'    => 'You completed the trip on time! +2 points added to your score.',
            'is_read'    => false,
            'created_at' => $completedTs,
        ]);
    }

    ob_end_clean();
    echo json_encode(['ok'=>true,'on_time'=>$onTime,'points_awarded'=>$pointsAwarded]); exit;
}

// ── complete_trip_form retired — redirect harmlessly ──────────────────────────
if(isset($_GET['complete_trip_form'])){ header('Location: driver%20portal.php'); exit; }

// ── LOGIN ─────────────────────────────────────────────────────────────────────
$loginError='';
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='login'){
    $code=strtoupper(trim($_POST['driver_code']??''));
    $lic =strtoupper(trim($_POST['license_no']??''));
    if($code&&$lic){
        // Try eq filter first, fallback approach for case sensitivity
        $res=sb('GET','fvm_drivers',[],['driver_code'=>'eq.'.$code,'select'=>'*']);
        $rows=$res['data']??[];
        // Supabase may return array or error object — normalise
        if(!isset($rows[0])&&isset($rows['message'])){$rows=[];}
        if(empty($rows)){
            // Try case-insensitive
            $res2=sb('GET','fvm_drivers',[],['driver_code'=>'ilike.'.$code,'select'=>'*']);
            $rows=$res2['data']??[];
            if(!isset($rows[0])&&isset($rows['message'])){$rows=[];}
        }
        if(!empty($rows)){
            if(strtoupper(trim($rows[0]['license_no']))===$lic){
                $_SESSION['driver_id']  =$rows[0]['id'];
                $_SESSION['driver_name']=$rows[0]['full_name'];
                // Mark driver as online in Supabase so FVM map shows them active
                sb('PATCH','fvm_drivers',['is_online'=>true,'last_seen'=>date('c')],['id'=>'eq.'.$rows[0]['id']]);
                header('Location: driver%20portal.php'); exit;
            } else {
                $loginError='Incorrect License Number. Please check and try again.';
            }
        } else {
            $loginError='Driver Code &ldquo;'.htmlspecialchars($code).'"&rdquo; not found. Check with your admin.';
        }
    } else { $loginError='Please enter both your Driver Code and License Number.'; }
}

// ── AUTH GATE ─────────────────────────────────────────────────────────────────
$loggedIn=!empty($_SESSION['driver_id']);
$driverId=$_SESSION['driver_id']??null;
$driverInfo=null; $activeTrip=null; $activeVehicle=null;
$myTrips=[]; $myFuelLogs=[]; $myIncidents=[]; $myDriverExp=[]; $vehicles=[];
$score=100; $doneTrips=[]; $totalMileage=0; $totalLiters=0.0; $totalFuelCost=0.0; $totalDriverExp=0.0; $openReports=0; $licDays=null; $driverExpByCat=[];

if($loggedIn){
    session_regenerate_id(false); // security: rotate session ID
    $d=sbGet('fvm_drivers',['id'=>'eq.'.$driverId,'select'=>'*']);
    // Guard: Supabase error or driver deleted
    if(empty($d)||!isset($d[0]['id'])){ session_destroy(); header('Location: driver%20portal.php'); exit; }
    $driverInfo=$d[0];
    $vehicles=sbGet('fvm_vehicles',['status'=>'eq.Active','order'=>'vehicle_code.asc','select'=>'*']);
    // Fetch pending and in-progress trips separately to avoid URL-encoding issues with spaces
    $tPending =sbGet('fvm_trips',['driver_id'=>'eq.'.$driverId,'status'=>'eq.Pending','order'=>'scheduled_date.desc','limit'=>1,'select'=>'*']);
    $tInProg  =sbGet('fvm_trips',['driver_id'=>'eq.'.$driverId,'status'=>'eq.In Progress','order'=>'scheduled_date.desc','limit'=>1,'select'=>'*']);
    $tAll     =array_merge($tInProg,$tPending); // In Progress takes priority
    $tRows    =!empty($tAll)?[$tAll[0]]:[];
    $activeTrip=$tRows[0]??null;
    if($activeTrip) foreach($vehicles as $v) if($v['id']===$activeTrip['vehicle_id']){ $activeVehicle=$v; break; }
    $myTrips    =sbGet('fvm_trips',    ['driver_id'=>'eq.'.$driverId,'order'=>'scheduled_date.desc','limit'=>20,'select'=>'*']);
    $myFuelLogs =sbGet('fvm_fuel_logs',['driver_id'=>'eq.'.$driverId,'order'=>'log_date.desc','limit'=>20,'select'=>'*']);
    $myIncidents=sbGet('fvm_incidents',['driver_id'=>'eq.'.$driverId,'order'=>'incident_date.desc','limit'=>20,'select'=>'*']);
    // Driver expenses: fetch from fvm_expenses where notes contains '[Driver Expense]' and approved_by = driver name
    $allDriverExpRaw = sbGet('fvm_expenses',['approved_by'=>'eq.'.$driverInfo['full_name'],'order'=>'expense_date.desc','limit'=>100,'select'=>'*']);
    $myDriverExp = array_values(array_filter($allDriverExpRaw, fn($e)=>str_contains($e['notes']??'','[Driver Expense]')));
    $totalDriverExp = (float)array_sum(array_column($myDriverExp,'amount'));
    $driverExpByCat = [];
    foreach($myDriverExp as $ex){ $et=$ex['expense_type']??'Other'; $driverExpByCat[$et]=($driverExpByCat[$et]??0)+(float)$ex['amount']; }
    $score=(int)($driverInfo['behavior_score']??100);
    $doneTrips=array_values(array_filter($myTrips,fn($t)=>$t['status']==='Completed'));
    $totalMileage=array_sum(array_column($doneTrips,'mileage_km'));
    $totalLiters=(float)array_sum(array_column($myFuelLogs,'liters'));
    $totalFuelCost=(float)array_sum(array_column($myFuelLogs,'total_cost'));
    $openReports=count(array_filter($myIncidents,fn($i)=>!in_array($i['status']??'Open',['Closed'])));
    $licDays=$driverInfo['license_expiry']?(int)floor((strtotime($driverInfo['license_expiry'])-time())/86400):null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>FVM · Driver Portal</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --g900:#14401a;--g800:#1b5e20;--g700:#256427;--g600:#2e7d32;--g500:#388e3c;--g400:#4caf50;--g300:#81c784;
  --dark:#090f0a;--surf:#0f2012;--card:#122814;--bdr:rgba(255,255,255,.09);
  --txt:#fff;--mut:rgba(255,255,255,.5);--sub:rgba(255,255,255,.28);
  --red:#ef4444;--amb:#f59e0b;--blu:#3b82f6;
  --r:16px;--rs:10px;--tr:200ms ease;
}
html{scroll-behavior:smooth;-webkit-tap-highlight-color:transparent;}
body{font-family:'Outfit',sans-serif;background:var(--dark);color:var(--txt);min-height:100vh;-webkit-font-smoothing:antialiased;}
input,select,textarea,button{font-family:inherit;}
::-webkit-scrollbar{width:4px;height:4px;}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:2px;}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}

/* LOGIN */
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
  background:radial-gradient(ellipse 70% 50% at 50% 0%,rgba(46,125,50,.15) 0%,transparent 70%),var(--dark);}
.login-box{width:100%;max-width:380px;animation:fadeUp .35s ease;}
.login-ico{width:52px;height:52px;background:linear-gradient(135deg,var(--g700),var(--g400));border-radius:14px;
  display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 18px;
  box-shadow:0 8px 24px rgba(46,125,50,.35);}
.login-h1{font-family:'DM Serif Display',serif;font-size:28px;text-align:center;margin-bottom:3px;}
.login-sub{font-size:13px;color:var(--mut);text-align:center;margin-bottom:28px;}
.login-card{background:var(--card);border:1px solid var(--bdr);border-radius:20px;padding:28px 24px;}
.err-msg{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:9px;
  padding:10px 14px;font-size:13px;color:#fca5a5;margin-bottom:16px;}
.hint{background:rgba(255,255,255,.04);border:1px solid var(--bdr);border-radius:9px;
  padding:12px 14px;font-size:12px;color:var(--mut);margin-top:14px;line-height:1.65;}
.hint strong{color:var(--g300);}

/* FORMS */
.fg{margin-bottom:14px;}
.fl{display:block;font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--g300);margin-bottom:6px;}
.fi{width:100%;padding:11px 13px;border-radius:var(--rs);background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.11);color:var(--txt);font-size:13.5px;outline:none;
  transition:border-color var(--tr),box-shadow var(--tr);}
.fi:focus{border-color:var(--g400);box-shadow:0 0 0 3px rgba(76,175,80,.14);}
.fi::placeholder{color:var(--sub);}
.fi option{background:#1a2b1c;}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:11px;}
@media(max-width:480px){.fr{grid-template-columns:1fr;}}
.sw{position:relative;}.sw::after{content:'▾';position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--mut);pointer-events:none;font-size:11px;}
textarea.fi{resize:vertical;min-height:86px;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 18px;
  border-radius:var(--rs);border:none;font-size:13px;font-weight:700;cursor:pointer;transition:all var(--tr);white-space:nowrap;}
.bp{background:linear-gradient(135deg,var(--g700),var(--g500));color:#fff;box-shadow:0 4px 14px rgba(46,125,50,.28);}
.bp:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,125,50,.38);}
.bp:active{transform:none;}
.bp:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.bp.full{width:100%;padding:13px;}
.bo{background:transparent;border:1px solid var(--bdr);color:var(--mut);}
.bo:hover{border-color:rgba(255,255,255,.28);color:var(--txt);}
.bd{background:rgba(239,68,68,.09);border:1px solid rgba(239,68,68,.18);color:#fca5a5;}
.bd:hover{background:rgba(239,68,68,.18);}
.bsm{padding:6px 13px;font-size:12px;}

/* LAYOUT */
.hdr{background:var(--surf);border-bottom:1px solid var(--bdr);position:sticky;top:0;z-index:100;transition:box-shadow var(--tr);}
.hdr.scrolled{box-shadow:0 4px 20px rgba(0,0,0,.4);}
.hdr-in{max-width:960px;margin:0 auto;padding:0 20px;height:60px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.hlogo{display:flex;align-items:center;gap:10px;}
.hlogo-m{width:34px;height:34px;background:linear-gradient(135deg,var(--g700),var(--g400));border-radius:9px;
  display:flex;align-items:center;justify-content:center;font-size:16px;}
.hlogo-t{font-family:'DM Serif Display',serif;font-size:16px;}
.hlogo-t span{color:var(--g300);}
.hdrv{display:flex;align-items:center;gap:9px;}
.hav{width:30px;height:30px;border-radius:50%;background:var(--g700);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;}
.hdn{font-size:13px;color:rgba(255,255,255,.8);}
.hdc{font-size:10px;color:var(--g300);}

.nav{background:rgba(0,0,0,.2);border-bottom:1px solid var(--bdr);overflow-x:auto;scrollbar-width:none;}
.nav::-webkit-scrollbar{display:none;}
.nav-in{max-width:960px;margin:0 auto;padding:0 16px;display:flex;}
.nt{padding:12px 15px;font-size:13px;font-weight:600;color:var(--mut);cursor:pointer;white-space:nowrap;
  border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;
  transition:color var(--tr),border-color var(--tr);display:flex;align-items:center;gap:5px;}
.nt:hover:not(.on){color:rgba(255,255,255,.75);}
.nt.on{color:var(--txt);border-bottom-color:var(--g400);}

.pg{max-width:960px;margin:0 auto;padding:22px 20px 80px;}
.sec{display:none;animation:fadeUp .2s ease both;}
.sec.on{display:block;}

/* CARDS */
.card{background:var(--card);border:1px solid var(--bdr);border-radius:var(--r);padding:20px 22px;margin-bottom:16px;}
.ct{border-top:3px solid var(--g500);}
.cta{border-top:3px solid var(--amb);}
.cl{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--g300);margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.clb{width:16px;height:2px;background:var(--g400);border-radius:1px;flex-shrink:0;}

/* STATS */
.sg{display:grid;grid-template-columns:repeat(4,1fr);gap:11px;margin-bottom:18px;}
@media(max-width:580px){.sg{grid-template-columns:1fr 1fr;}}
.st{background:var(--card);border:1px solid var(--bdr);border-radius:var(--rs);padding:15px;}
.st-ico{font-size:20px;margin-bottom:5px;}
.st-v{font-size:22px;font-weight:700;line-height:1;margin-bottom:2px;}
.st-l{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;}
.cg{color:var(--g300);}.ca{color:var(--amb);}.cr{color:var(--red);}.cb{color:var(--blu);}

/* TRIP CARD */
.tc{background:var(--card);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;margin-bottom:16px;}
.tc-stripe{height:3px;background:linear-gradient(90deg,var(--g600),var(--g400));}
.tc-b{padding:19px 21px;}
.tc-h{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:15px;flex-wrap:wrap;}
.tc-code{font-family:'DM Serif Display',serif;font-size:20px;}
.badge{font-size:10px;font-weight:700;padding:3px 10px;border-radius:100px;letter-spacing:.4px;text-transform:uppercase;white-space:nowrap;}
.bp2{background:rgba(99,102,241,.13);color:#a5b4fc;border:1px solid rgba(99,102,241,.2);}
.bpr{background:rgba(245,158,11,.11);color:var(--amb);border:1px solid rgba(245,158,11,.2);}
.bdn{background:rgba(46,125,50,.13);color:var(--g300);border:1px solid rgba(46,125,50,.2);}
.bop{background:rgba(239,68,68,.11);color:#fca5a5;border:1px solid rgba(239,68,68,.2);}
.route-b{background:rgba(255,255,255,.04);border-radius:9px;padding:12px 14px;margin-bottom:13px;}
.rdots{display:flex;align-items:center;gap:7px;}
.rd{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.rd-a{background:var(--g400);}.rd-b{background:rgba(255,255,255,.22);}
.rl{flex:1;height:2px;background:linear-gradient(90deg,var(--g400),rgba(255,255,255,.08));border-radius:1px;}
.rlbl{display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-top:5px;}
.tmeta{display:grid;grid-template-columns:repeat(auto-fit,minmax(88px,1fr));gap:9px;}
.tm{font-size:11.5px;color:var(--mut);}.tm strong{display:block;font-size:13px;color:var(--txt);font-weight:600;margin-top:1px;}

/* GPS */
.gcard{background:var(--card);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;margin-bottom:16px;}
.gh{padding:15px 20px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;}
.gt{font-size:15px;font-weight:700;}
.gbdg{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:4px 12px;border-radius:100px;}
.goff{background:rgba(239,68,68,.09);color:#fca5a5;border:1px solid rgba(239,68,68,.17);}
.gon{background:rgba(34,197,94,.09);color:#4ade80;border:1px solid rgba(34,197,94,.17);}
.gdot{width:7px;height:7px;border-radius:50%;background:currentColor;animation:pulse 1.4s infinite;}
#gps-map{height:420px!important;background:#0d150e;min-height:420px;}
.gstats{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--bdr);}
.gs{padding:12px;text-align:center;border-right:1px solid var(--bdr);}
.gs:last-child{border-right:none;}
.gsv{font-size:17px;font-weight:700;color:var(--g300);}
.gsl{font-size:10px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;margin-top:2px;}
.gctrl{padding:13px 20px;display:flex;gap:8px;flex-wrap:wrap;}
.bgps{flex:1;min-width:130px;padding:12px;border-radius:var(--rs);border:none;font-size:13.5px;font-weight:700;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:all var(--tr);}
.bgps-go{background:linear-gradient(135deg,var(--g700),var(--g400));color:#fff;}
.bgps-go:hover{box-shadow:0 4px 18px rgba(46,125,50,.38);}
.bgps-stop{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.22);color:#fca5a5;}
.bgps-stop:hover{background:rgba(239,68,68,.18);}
.glog{padding:0 20px 12px;font-size:11.5px;color:var(--mut);font-family:monospace;min-height:16px;}

/* REPORT TABS */
.rtbar{display:flex;border-bottom:1px solid var(--bdr);overflow-x:auto;scrollbar-width:none;}
.rtbar::-webkit-scrollbar{display:none;}
.rt{padding:12px 15px;font-size:13px;font-weight:600;color:var(--mut);cursor:pointer;white-space:nowrap;
  border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;transition:all var(--tr);}
.rt.on{color:var(--txt);border-bottom-color:var(--g400);}
.rb{padding:20px 22px;display:none;}.rb.on{display:block;}
.pz{border:2px dashed rgba(255,255,255,.12);border-radius:11px;padding:17px;text-align:center;
  cursor:pointer;transition:border-color var(--tr),background var(--tr);font-size:13px;color:var(--mut);}
.pz:hover{border-color:var(--g400);background:rgba(76,175,80,.04);}
.pprev{width:100%;border-radius:9px;margin-top:11px;max-height:175px;object-fit:cover;display:none;}

/* TABLE */
.dt{width:100%;border-collapse:collapse;}
.dt th{text-align:left;font-size:10px;color:var(--mut);text-transform:uppercase;padding:7px 9px;letter-spacing:.6px;font-weight:700;border-bottom:1px solid var(--bdr);}
.dt td{font-size:12.5px;padding:10px 9px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;}
.dt tr:last-child td{border-bottom:none;}
.dt tr:hover td{background:rgba(255,255,255,.025);}

/* SCORE */
.sr{position:relative;width:110px;height:110px;}
.sr svg{transform:rotate(-90deg);}
.sri{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.srn{font-size:26px;font-weight:700;line-height:1;}.srs{font-size:10px;color:var(--mut);}
.prw{display:flex;align-items:center;gap:10px;margin-bottom:11px;}
.prl{font-size:12.5px;color:rgba(255,255,255,.65);min-width:130px;}
.prb{flex:1;height:5px;background:rgba(255,255,255,.07);border-radius:3px;overflow:hidden;}
.prf{height:100%;border-radius:3px;transition:width .55s ease;}
.prn{font-size:12px;font-weight:700;min-width:30px;text-align:right;}

/* MISC */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(80px);
  background:var(--g800);color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:600;
  box-shadow:0 8px 28px rgba(0,0,0,.45);transition:transform .3s cubic-bezier(.34,1.56,.64,1);
  z-index:999;pointer-events:none;white-space:nowrap;border:1px solid rgba(255,255,255,.09);}
.toast.on{transform:translateX(-50%) translateY(0);}
.toast.err{background:#7f1d1d;}
.empty{text-align:center;padding:34px 20px;color:var(--mut);font-size:14px;}
.eico{font-size:32px;margin-bottom:9px;opacity:.35;}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
@media(max-width:580px){.g2{grid-template-columns:1fr;}}
.cw{height:190px;position:relative;}
</style>
</head>
<body>
<div class="toast" id="toast"></div>

<?php if(!$loggedIn): ?>
<!-- ═══════════════════════════════════════════════════════════════ LOGIN ═══ -->
<div class="login-page">
  <div class="login-box">
    <div class="login-ico">🚛</div>
    <div class="login-h1">Driver Portal</div>
    <div class="login-sub">Fleet Vehicle Management System</div>
    <div class="login-card">
      <?php if($loginError): ?><div class="err-msg">⚠️ <?=e($loginError)?></div><?php endif; ?>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <div class="fg">
          <label class="fl" for="dc">Driver Code</label>
          <input class="fi" type="text" id="dc" name="driver_code" placeholder="e.g. D001"
            value="<?=e($_POST['driver_code']??'')?>" autocomplete="off" required>
        </div>
        <div class="fg">
          <label class="fl" for="ln">License Number</label>
          <input class="fi" type="text" id="ln" name="license_no" placeholder="Your LTO license number"
            autocomplete="off" required>
        </div>
        <button type="submit" class="btn bp full" style="margin-top:8px;">Sign In →</button>
      </form>
      <div class="hint"><strong>How to log in:</strong> Enter your <strong>Driver Code</strong> (e.g. D001) and <strong>LTO License Number</strong> exactly as registered. Contact your fleet admin if you need help.</div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════ PORTAL ═══ -->
<header class="hdr" id="hdr">
  <div class="hdr-in">
    <div class="hlogo"><div class="hlogo-m">🚛</div><span class="hlogo-t">FVM <span>Driver</span></span></div>
    <div class="hdrv">
      <div class="hav"><?=strtoupper(substr($driverInfo['full_name'],0,1))?></div>
      <div><div class="hdn"><?=e(explode(' ',$driverInfo['full_name'])[0])?></div><div class="hdc"><?=e($driverInfo['driver_code']??'')?></div></div>
      <a href="driver%20portal.php?logout=1" class="btn bo bsm">Sign Out</a>
    </div>
  </div>
</header>

<nav class="nav">
  <div class="nav-in">
    <button class="nt on" id="nt-dash"    onclick="goTab('dash')">📊 Dashboard</button>
    <button class="nt" id="nt-gps" onclick="goTab('gps')" style="position:relative;">📡 GPS<?php if($activeTrip&&$activeTrip['status']==="In Progress"): ?><span id="gps-dot" style="position:absolute;top:8px;right:4px;width:8px;height:8px;background:#ef4444;border-radius:50%;animation:pulse 1.4s infinite;"></span><?php endif; ?></button>
    <button class="nt"    id="nt-fuel"    onclick="goTab('fuel')">⛽ Fuel Log</button>
    <button class="nt"    id="nt-report"  onclick="goTab('report')">⚠️ Report</button>
    <button class="nt"    id="nt-perf"    onclick="goTab('perf')">⭐ Performance</button>
    <button class="nt"    id="nt-expenses" onclick="goTab('expenses')">💰 Expenses</button>
    <button class="nt"    id="nt-history" onclick="goTab('history')">📁 History</button>
    <button class="nt"    id="nt-notif"   onclick="goTab('notif')" style="position:relative;">
      🔔 Alerts<span id="notif-badge" style="display:none;position:absolute;top:7px;right:2px;min-width:16px;height:16px;line-height:16px;background:#ef4444;border-radius:8px;font-size:9px;font-weight:700;color:#fff;text-align:center;padding:0 3px;"></span>
    </button>
  </div>
</nav>

<div class="pg">

<!-- ═══ DASHBOARD ════════════════════════════════════════════════════════════ -->
<div class="sec on" id="sec-dash">
  <?php
  $completedPts = $_SESSION['trip_completed'] ?? null;
  unset($_SESSION['trip_completed']);
  if($completedPts !== null):
  ?>
  <div style="background:rgba(76,175,80,.15);border:1.5px solid rgba(76,175,80,.4);border-radius:14px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:14px;">
    <div style="font-size:30px;">✅</div>
    <div>
      <div style="font-size:15px;font-weight:700;color:#4ade80;">Trip Completed!</div>
      <div style="font-size:12px;color:rgba(255,255,255,.6);margin-top:2px;">
        Your proof photo and trip details have been submitted to dispatch.
        <?php if($completedPts>0): ?> <strong style="color:#fbbf24;">+<?=$completedPts?> points earned!</strong><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div style="margin-bottom:18px;">
    <div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--g300);margin-bottom:3px;"><?=date('l, F j, Y')?></div>
    <div style="font-family:'DM Serif Display',serif;font-size:25px;">Good <?=date('H')<12?'morning':(date('H')<18?'afternoon':'evening')?>, <?=e(explode(' ',$driverInfo['full_name'])[0])?>.
    </div>
  </div>

  <?php
  $licVal  =$licDays===null?'N/A':($licDays<0?'Expired':$licDays.' d');
  $licCls  =$licDays===null?'cg':($licDays<0?'cr':($licDays<30?'ca':'cg'));
  $scCls   =$score>=85?'cg':($score>=70?'ca':'cr');
  ?>
  <div class="sg">
    <div class="st"><div class="st-ico">🪪</div><div class="st-v <?=$licCls?>"><?=$licVal?></div><div class="st-l">License Expiry</div></div>
    <div class="st"><div class="st-ico">⭐</div><div class="st-v <?=$scCls?>"><?=$score?>/100</div><div class="st-l">Behavior Score</div></div>
    <div class="st"><div class="st-ico">✅</div><div class="st-v cg"><?=count($doneTrips)?></div><div class="st-l">Done Trips</div></div>
    <div class="st"><div class="st-ico">📋</div><div class="st-v <?=$openReports>0?'ca':'cg'?>"><?=$openReports?></div><div class="st-l">Active Reports</div></div>
  </div>
  <div class="sg" style="grid-template-columns:repeat(3,1fr);margin-bottom:18px;">
    <div class="st" style="cursor:pointer;border-top:3px solid var(--amb);" onclick="goTab('expenses')"><div class="st-ico">💸</div><div class="st-v ca"><?=peso($totalDriverExp)?></div><div class="st-l">My Expenses</div></div>
    <div class="st" style="cursor:pointer;border-top:3px solid var(--blu);" onclick="goTab('fuel')"><div class="st-ico">⛽</div><div class="st-v cb"><?=peso($totalFuelCost)?></div><div class="st-l">Fuel Spent</div></div>
    <div class="st" style="cursor:pointer;" onclick="goTab('history')"><div class="st-ico">🛣️</div><div class="st-v cg"><?=number_format($totalMileage)?> km</div><div class="st-l">Total km</div></div>
  </div>


  <?php if($activeTrip):
    $bc=['Pending'=>'bp2','In Progress'=>'bpr'][$activeTrip['status']]??'bp2';
  ?>
  <?php if($activeTrip['status']==='In Progress'): ?>
  <div id="gps-reminder" style="background:rgba(21,101,192,.12);border:1px solid rgba(21,101,192,.3);border-radius:12px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <div>
      <div style="font-size:13px;font-weight:700;color:#93c5fd;">📡 GPS Sharing Required</div>
      <div style="font-size:12px;color:rgba(255,255,255,.55);margin-top:2px;">Your trip is In Progress — dispatch needs your live location.</div>
    </div>
    <button class="btn bp bsm" onclick="goTab('gps')" style="white-space:nowrap;flex-shrink:0;">Open GPS →</button>
  </div>
  <?php endif; ?>
  <div style="font-family:'DM Serif Display',serif;font-size:17px;margin-bottom:11px;">🗺️ Active Trip</div>
  <div class="tc ct">
    <div class="tc-b">
      <div class="tc-h">
        <div><div class="tc-code"><?=e($activeTrip['trip_code']??'Trip')?></div><?php if($activeTrip['purpose']):?><div style="font-size:12px;color:var(--mut);margin-top:2px;"><?=e($activeTrip['purpose'])?></div><?php endif;?></div>
        <span class="badge <?=$bc?>"><?=e($activeTrip['status'])?></span>
      </div>
      <div class="route-b">
        <div class="rdots"><div class="rd rd-a"></div><div class="rl"></div><div class="rd rd-b"></div></div>
        <div class="rlbl"><span><?=e($activeTrip['origin'])?></span><span><?=e($activeTrip['destination'])?></span></div>
      </div>
      <div class="tmeta">
        <div class="tm">Date <strong><?=e($activeTrip['scheduled_date'])?></strong></div>
        <div class="tm">Time <strong><?=e($activeTrip['scheduled_time']??'—')?></strong></div>
        <div class="tm">Priority <strong><?=e($activeTrip['priority']??'Normal')?></strong></div>
        <?php if($activeVehicle):?>
        <div class="tm">Plate <strong><?=e($activeVehicle['plate'])?></strong></div>
        <div class="tm">Fuel <strong><?=$activeVehicle['fuel_level']?>%</strong></div>
        <?php endif;?>
        <?php
          $atEta=(int)($activeTrip['eta_minutes']??0);
          $atKm=(float)($activeTrip['route_distance_km']??0);
          if($atEta>0): $atH=floor($atEta/60);$atM=$atEta%60;
        ?>
        <div class="tm">ETA <strong style="color:#60a5fa;"><?=$atH>0?$atH.'h ':''?><?=$atM?>min</strong></div>
        <?php endif; if($atKm>0): ?>
        <div class="tm">Distance <strong style="color:var(--g300);"><?=number_format($atKm,1)?> km</strong></div>
        <?php endif; ?>
      </div>
      <?php if(!empty($activeTrip['route_suggestion'])): ?>
      <div style="background:rgba(76,175,80,.08);border:1px solid rgba(76,175,80,.2);border-radius:8px;padding:8px 12px;margin:10px 0;font-size:12px;color:var(--g300);">
        🗺️ <?=e($activeTrip['route_suggestion'])?>
      </div>
      <?php endif;?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;">
        <?php if($activeTrip['status']==='Pending'): ?>
        <button class="btn bsm" id="btn-start-trip"
          onclick="startTrip('<?=e($activeTrip['id'])?>')"
          style="background:linear-gradient(135deg,#1565C0,#1976D2);color:#fff;box-shadow:0 3px 12px rgba(21,101,192,.35);">
          ▶ Start Trip
        </button>
        <?php elseif($activeTrip['status']==='In Progress'): ?>
        <button class="btn bsm" id="btn-complete-trip"
          onclick="openCompleteModal('<?=e($activeTrip['id'])?>')"
          style="background:linear-gradient(135deg,#256427,#388e3c);color:#fff;box-shadow:0 3px 12px rgba(37,100,39,.35);">
          ✅ Mark Complete
        </button>
        <?php endif;?>
        <button class="btn bp bsm" onclick="goTab('gps')">📡 GPS</button>
        <button class="btn bo bsm" onclick="goTab('fuel')">⛽ Fuel</button>
        <button class="btn bo bsm" style="border-color:rgba(251,191,36,.3);color:#fde68a;" onclick="goTab('expenses')">💰 Expenses</button>
        <button class="btn bd bsm" onclick="goTab('report')">⚠️ Report</button>
      </div>
    </div>
  </div>
  <?php else:?>
  <div class="card" style="text-align:center;padding:34px;border-style:dashed;">
    <div style="font-size:30px;opacity:.25;margin-bottom:7px;">🏁</div>
    <div style="color:var(--mut);">No active trip assigned.</div>
    <div style="color:var(--sub);font-size:12px;margin-top:3px;">Contact your fleet admin.</div>
  </div>
  <?php endif;?>

  <div class="g2">
    <div class="card">
      <div class="cl"><span class="clb"></span>⛽ Fuel Summary</div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;"><span style="color:var(--mut)">Total Liters</span><strong><?=number_format($totalLiters,1)?> L</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;"><span style="color:var(--mut)">Total Cost</span><strong class="cr"><?=peso($totalFuelCost)?></strong></div>
      <?php if($totalMileage>0&&$totalLiters>0):?>
      <div style="display:flex;justify-content:space-between;font-size:13px;"><span style="color:var(--mut)">Efficiency</span><strong class="cg"><?=number_format($totalMileage/$totalLiters,1)?> km/L</strong></div>
      <?php endif;?>
    </div>
    <div class="card">
      <div class="cl"><span class="clb"></span>📏 Mileage</div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;"><span style="color:var(--mut)">Trips</span><strong><?=count($myTrips)?></strong></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;"><span style="color:var(--mut)">Km Driven</span><strong class="cg"><?=number_format($totalMileage)?> km</strong></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;"><span style="color:var(--mut)">Incidents</span><strong class="<?=count($myIncidents)>0?'ca':'cg'?>"><?=count($myIncidents)?></strong></div>
    </div>
  </div>
</div>

<!-- ═══ GPS ══════════════════════════════════════════════════════════════════ -->
<div class="sec" id="sec-gps">

<?php if($activeTrip): ?>

<!-- ── GrabMaps-style GPS view ─────────────────────────────────────────── -->
<div id="gps-fullview" style="position:relative;margin:-22px -20px 16px;overflow:hidden;">

  <!-- TOP NAV BANNER (distance + destination, like GrabMaps) -->
  <div id="nav-banner" style="
    position:absolute;top:0;left:0;right:0;z-index:500;
    background:linear-gradient(135deg,#0d47a1,#1565C0);
    color:#fff;padding:14px 18px 12px;
    box-shadow:0 4px 16px rgba(0,0,0,.4);
    display:flex;align-items:center;gap:14px;">
    <div style="width:42px;height:42px;background:rgba(255,255,255,.15);border-radius:10px;
      display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:22px;">&#128641;</div>
    <div style="flex:1;min-width:0;">
      <div id="nav-dist" style="font-family:'DM Serif Display',serif;font-size:26px;line-height:1;font-weight:700;">—</div>
      <div id="nav-street" style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?=e($activeTrip['destination'])?>
      </div>
    </div>
    <div style="text-align:right;flex-shrink:0;">
      <div class="gbdg goff" id="gbdg" style="margin:0;">
        <div class="gdot"></div><span id="gbdg-txt">Offline</span>
      </div>
      <div id="gps-timestamp" style="font-size:10px;color:rgba(255,255,255,.5);margin-top:4px;font-family:monospace;"></div>
    </div>
  </div>

  <!-- MAIN MAP (tall, fullwidth) -->
  <div id="gps-map" style="height:480px;width:100%;background:#0d150e;margin-top:0;"></div>

  <!-- BOTTOM STATS BAR (pinned over map) -->
  <div style="
    position:absolute;bottom:0;left:0;right:0;z-index:500;
    background:rgba(9,15,10,.92);backdrop-filter:blur(10px);
    border-top:1px solid rgba(255,255,255,.1);
    display:grid;grid-template-columns:repeat(4,1fr);">
    <div class="gs"><div class="gsv" id="gs-spd">—</div><div class="gsl">km/h</div></div>
    <div class="gs"><div class="gsv" id="gs-hdg">—</div><div class="gsl">Heading</div></div>
    <div class="gs"><div class="gsv" id="gs-acc">—</div><div class="gsl">Accuracy</div></div>
    <div class="gs"><div class="gsv" id="gs-png">0</div><div class="gsl">Pings</div></div>
  </div>
</div><!-- /gps-fullview -->

<!-- TRIP CARD below map -->
<div style="background:var(--card);border:1px solid var(--bdr);border-radius:14px;padding:14px 18px;margin-bottom:14px;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
    <div>
      <div style="font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--g300);margin-bottom:3px;">
        <?=e($activeTrip['trip_code']??'Active Trip')?>
      </div>
      <div style="font-size:14px;font-weight:700;">
        <?=e($activeTrip['origin'])?> &#8594; <?=e($activeTrip['destination'])?>
      </div>
      <div style="font-size:12px;color:var(--mut);margin-top:3px;">
        &#128197; <?=e($activeTrip['scheduled_date'])?> &nbsp;&#128336; <?=e($activeTrip['scheduled_time']??' ')?>
        <?php if(!empty($activeTrip['dispatched_at'])): ?>
        &nbsp;&#183;&nbsp;<span style="font-family:monospace;">Dispatched <?=e(date('H:i', strtotime($activeTrip['dispatched_at'])))?></span>
        <?php endif; ?>
      </div>
      <?php
        $gEta=(int)($activeTrip['eta_minutes']??0);
        $gKm=(float)($activeTrip['route_distance_km']??0);
        if($gEta>0||$gKm>0):
          $gH=floor($gEta/60);$gM=$gEta%60;
      ?>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:5px;">
        <?php if($gEta>0): ?>
        <span style="font-size:12px;font-weight:700;color:#60a5fa;">🕐 ETA <?=$gH>0?$gH.'h ':''?><?=$gM?>min</span>
        <?php endif; if($gKm>0): ?>
        <span style="font-size:12px;color:var(--g300);">📏 <?=number_format($gKm,1)?> km</span>
        <?php endif; if(!empty($activeTrip['route_suggestion'])): ?>
        <span style="font-size:11px;color:var(--mut);"><?=e($activeTrip['route_suggestion'])?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if(!empty($activeTrip['dispatch_timer'])&&$activeTrip['dispatch_timer']>0): ?>
    <div style="text-align:right;flex-shrink:0;">
      <div style="font-size:10px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;">Timer</div>
      <div style="font-size:15px;font-weight:700;color:var(--amb);font-family:monospace;"
           id="driver-timer" data-dispatched="<?=e($activeTrip['dispatched_at']??'')?>"
           data-timer-min="<?=(int)$activeTrip['dispatch_timer']?>">--:--</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Progress bar -->
  <div id="dist-progress-wrap" style="display:none;">
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--mut);margin-bottom:4px;">
      <span>&#128681; <?=e(mb_substr($activeTrip['origin'],0,20))?></span>
      <span id="dist-to-dest">Calculating…</span>
      <span>&#128205; <?=e(mb_substr($activeTrip['destination'],0,20))?></span>
    </div>
    <div style="height:6px;background:rgba(255,255,255,.07);border-radius:3px;overflow:hidden;">
      <div id="progress-bar" style="height:100%;background:linear-gradient(90deg,#1565C0,#4ade80);border-radius:3px;width:0%;transition:width .8s ease;"></div>
    </div>
  </div>
</div>

<!-- GPS START/STOP BUTTON -->
<div style="margin-bottom:14px;">
  <button class="bgps bgps-go" id="btn-gps" onclick="toggleGPS()"
    style="width:100%;padding:16px;font-size:15px;border-radius:14px;border:none;
      font-family:'Outfit',sans-serif;font-weight:700;cursor:pointer;
      background:linear-gradient(135deg,#1565C0,#1976D2);color:#fff;
      box-shadow:0 4px 18px rgba(21,101,192,.35);display:flex;align-items:center;
      justify-content:center;gap:8px;">
    &#128225; Start Sharing Location
  </button>
  <div class="glog" id="glog"
    style="text-align:center;padding:8px 16px;font-size:11.5px;color:var(--mut);font-family:monospace;">
    Tap Start to share your live location with dispatch
  </div>
</div>

<!-- ODOMETER submit -->
<div class="card ct" style="margin-bottom:16px;">
  <div class="cl"><span class="clb"></span>&#128207; End-of-Trip Odometer</div>
  <div style="font-size:13px;color:var(--mut);margin-bottom:11px;">
    Submit final odometer reading when trip is complete.
  </div>
  <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;">
    <div style="flex:1;min-width:130px;">
      <label class="fl">Odometer (km)</label>
      <input type="number" class="fi" id="odo-in" placeholder="e.g. 48500">
    </div>
    <button class="btn bp" onclick="submitOdo()">Submit</button>
  </div>
</div>

<?php else: ?>
<!-- No active trip -->
<div style="text-align:center;padding:60px 20px;">
  <div style="font-size:52px;opacity:.2;margin-bottom:14px;">&#128506;</div>
  <div style="font-family:'DM Serif Display',serif;font-size:20px;margin-bottom:6px;">No Active Trip</div>
  <div style="color:var(--mut);font-size:13px;">GPS tracking is only available when you have an active trip assigned.<br>Contact your fleet admin.</div>
</div>
<?php endif; ?>

<!-- Trip timer JS for driver card -->
<script>
(function(){
  var timerEl=document.getElementById('driver-timer');
  if(!timerEl)return;
  var dispatched=timerEl.getAttribute('data-dispatched');
  var timerMin=parseInt(timerEl.getAttribute('data-timer-min'))||0;
  if(!dispatched||!timerMin)return;
  function tick(){
    var elapsed=Math.floor((Date.now()-new Date(dispatched).getTime())/1000);
    var remaining=timerMin*60-elapsed;
    if(remaining<=0){
      var over=Math.abs(remaining);
      var m=Math.floor(over/60),s=over%60;
      timerEl.textContent=m+'m '+String(s).padStart(2,'0')+'s OVR';
      timerEl.style.color='#ef4444';
    } else {
      var h=Math.floor(remaining/3600),m2=Math.floor((remaining%3600)/60),s2=remaining%60;
      timerEl.textContent=(h>0?h+'h ':'')+String(m2).padStart(2,'0')+':'+String(s2).padStart(2,'0');
      timerEl.style.color=remaining<300?'#ef4444':(remaining<600?'#f59e0b':'#4ade80');
    }
  }
  tick(); setInterval(tick,1000);
})();
</script>

</div>

<div class="sec" id="sec-fuel">
  <div style="font-family:'DM Serif Display',serif;font-size:23px;margin-bottom:3px;">⛽ Fuel Log</div>
  <div style="font-size:13px;color:var(--mut);margin-bottom:18px;">Log fill-ups — FVM admin sees these automatically</div>
  <div class="card ct">
    <div class="cl"><span class="clb"></span>➕ New Fill-Up</div>
    <div class="fr">
      <div class="fg"><label class="fl">Vehicle</label><div class="sw"><select class="fi" id="fv">
        <?php if($activeVehicle):?><option value="<?=e($activeVehicle['id'])?>" selected><?=e($activeVehicle['plate'])?> (Active Trip)</option><?php endif;?>
        <?php foreach($vehicles as $v): if($activeVehicle&&$v['id']===$activeVehicle['id']) continue;?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> · <?=e($v['make'].' '.$v['model'])?></option><?php endforeach;?>
      </select></div></div>
      <div class="fg"><label class="fl">Station</label><input type="text" class="fi" id="fst" placeholder="e.g. Petron EDSA"></div>
    </div>
    <div class="fr">
      <div class="fg"><label class="fl">Liters</label><input type="number" step="0.1" class="fi" id="flt" placeholder="30.5" oninput="calcF()"></div>
      <div class="fg"><label class="fl">Price / Liter (₱)</label><input type="number" step="0.01" class="fi" id="fpp" placeholder="65.00" oninput="calcF()"></div>
    </div>
    <div class="fr">
      <div class="fg"><label class="fl">Odometer (km)</label><input type="number" class="fi" id="fod" placeholder="45800"></div>
      <div class="fg"><label class="fl">Fuel Level After (%)</label><input type="number" class="fi" id="faf" placeholder="75" min="0" max="100"></div>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:4px;">
      <div id="f-tot" style="font-size:14px;font-weight:700;color:var(--g300);"></div>
      <button class="btn bp" id="btn-fuel" onclick="submitFuel()">💾 Save Fill-Up</button>
    </div>
  </div>
  <div class="card">
    <div class="cl"><span class="clb"></span>📋 My Fuel Records</div>
    <?php if(empty($myFuelLogs)):?>
    <div class="empty"><div class="eico">⛽</div>No fuel logs yet.</div>
    <?php else:?>
    <table class="dt">
      <thead><tr><th>Date</th><th>Vehicle</th><th>Liters</th><th>Total</th><th>Odometer</th><th>Station</th></tr></thead>
      <tbody>
      <?php foreach($myFuelLogs as $fl): $vfl=null; foreach($vehicles as $v) if($v['id']===$fl['vehicle_id']){$vfl=$v;break;}?>
      <tr>
        <td style="color:var(--mut)"><?=e($fl['log_date'])?></td>
        <td><strong><?=e($vfl?$vfl['plate']:'?')?></strong></td>
        <td style="color:var(--amb);font-weight:600"><?=$fl['liters']?> L</td>
        <td style="color:var(--red);font-weight:700"><?=peso($fl['total_cost']??($fl['liters']*$fl['price_per_liter']))?></td>
        <td style="color:var(--mut)"><?=number_format($fl['odometer_km'])?> km</td>
        <td style="color:var(--mut)"><?=e($fl['station']??'—')?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
    <?php endif;?>
  </div>
</div>

<!-- ═══ REPORT ════════════════════════════════════════════════════════════════ -->
<div class="sec" id="sec-report">
  <div style="font-family:'DM Serif Display',serif;font-size:23px;margin-bottom:3px;">⚠️ Submit a Report</div>
  <div style="font-size:13px;color:var(--mut);margin-bottom:18px;">Reports go directly to FVM admin and will be reviewed</div>
  <div class="card cta">
    <div class="rtbar">
      <button class="rt on" id="rt-i" onclick="swRT('i')">🚨 Incident / Accident</button>
      <button class="rt"    id="rt-m" onclick="swRT('m')">🔧 Mechanical Issue</button>
      <button class="rt"    id="rt-o" onclick="swRT('o')">📝 Other</button>
    </div>
    <!-- INCIDENT -->
    <div class="rb on" id="rb-i">
      <div class="fr">
        <div class="fg"><label class="fl">Type</label><div class="sw"><select class="fi" id="i-t"><option value="Accident">🚗 Accident</option><option value="Near Miss">⚠️ Near Miss</option><option value="Traffic Violation">🚦 Traffic Violation</option><option value="Road Hazard">🚧 Road Hazard</option><option value="Injury">🩺 Injury</option></select></div></div>
        <div class="fg"><label class="fl">Severity</label><div class="sw"><select class="fi" id="i-s"><option value="Minor">Minor</option><option value="Moderate">Moderate</option><option value="Major">Major</option><option value="Critical">Critical</option></select></div></div>
      </div>
      <div class="fg"><label class="fl">Vehicle</label><div class="sw"><select class="fi" id="i-v">
        <?php if($activeVehicle):?><option value="<?=e($activeVehicle['id'])?>" selected><?=e($activeVehicle['plate'])?> (Active Trip)</option><?php endif;?>
        <?php foreach($vehicles as $v): if($activeVehicle&&$v['id']===$activeVehicle['id']) continue;?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> · <?=e($v['make'].' '.$v['model'])?></option><?php endforeach;?>
      </select></div></div>
      <div class="fg"><label class="fl">What happened? Be detailed.</label><textarea class="fi" id="i-d" placeholder="Describe the incident: location, time, damages, other parties, injuries..."></textarea></div>
      <div class="fg"><label class="fl">📷 Photo Evidence</label>
        <div class="pz" onclick="document.getElementById('i-pf').click()"><span id="i-pl">📷 Tap to attach a photo</span><input type="file" id="i-pf" accept="image/*" capture="environment" style="display:none" onchange="handleP(this,'i')"><img id="i-pp" class="pprev" alt=""></div>
        <input type="hidden" id="i-pu" value="">
      </div>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button class="btn bp" id="btn-i" onclick="doRep('i')">📤 Submit Report</button>
        <span style="font-size:12px;color:var(--mut)" id="i-gps">📍 GPS will be attached</span>
      </div>
    </div>
    <!-- MECHANICAL -->
    <div class="rb" id="rb-m">
      <div class="fr">
        <div class="fg"><label class="fl">Issue</label><div class="sw"><select class="fi" id="m-t"><option value="Engine Problem">⚙️ Engine Problem</option><option value="Flat Tire">🔴 Flat Tire</option><option value="Brake Issue">🛑 Brake Issue</option><option value="Overheating">🌡️ Overheating</option><option value="Battery Dead">🔋 Battery Dead</option><option value="Fuel Empty">⛽ Fuel Empty</option><option value="Lights/Electrical">💡 Electrical</option><option value="Other Mechanical">🔧 Other</option></select></div></div>
        <div class="fg"><label class="fl">Severity</label><div class="sw"><select class="fi" id="m-s"><option value="Minor">Minor</option><option value="Moderate">Moderate</option><option value="Major">Major</option></select></div></div>
      </div>
      <div class="fg"><label class="fl">Vehicle</label><div class="sw"><select class="fi" id="m-v">
        <?php if($activeVehicle):?><option value="<?=e($activeVehicle['id'])?>" selected><?=e($activeVehicle['plate'])?> (Active Trip)</option><?php endif;?>
        <?php foreach($vehicles as $v): if($activeVehicle&&$v['id']===$activeVehicle['id']) continue;?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?> · <?=e($v['make'].' '.$v['model'])?></option><?php endforeach;?>
      </select></div></div>
      <div class="fg"><label class="fl">Description</label><textarea class="fi" id="m-d" placeholder="Describe the problem. Can you still drive safely?"></textarea></div>
      <div class="fg"><label class="fl">📷 Photo</label>
        <div class="pz" onclick="document.getElementById('m-pf').click()"><span id="m-pl">📷 Tap to attach a photo</span><input type="file" id="m-pf" accept="image/*" capture="environment" style="display:none" onchange="handleP(this,'m')"><img id="m-pp" class="pprev" alt=""></div>
        <input type="hidden" id="m-pu" value="">
      </div>
      <button class="btn bp" id="btn-m" onclick="doRep('m')">📤 Submit Report</button>
    </div>
    <!-- OTHER -->
    <div class="rb" id="rb-o">
      <div class="fr">
        <div class="fg"><label class="fl">Type</label><div class="sw"><select class="fi" id="o-t"><option value="Delay">⏱️ Delay</option><option value="Route Change">🗺️ Route Change</option><option value="Cargo Issue">📦 Cargo Issue</option><option value="Weather Condition">🌧️ Weather</option><option value="Suspicious Activity">👁️ Suspicious</option><option value="Other">📋 Other</option></select></div></div>
        <div class="fg"><label class="fl">Vehicle</label><div class="sw"><select class="fi" id="o-v">
          <?php if($activeVehicle):?><option value="<?=e($activeVehicle['id'])?>" selected><?=e($activeVehicle['plate'])?> (Active Trip)</option><?php endif;?>
          <?php foreach($vehicles as $v): if($activeVehicle&&$v['id']===$activeVehicle['id']) continue;?><option value="<?=e($v['id'])?>"><?=e($v['plate'])?></option><?php endforeach;?>
        </select></div></div>
      </div>
      <div class="fg"><label class="fl">Description</label><textarea class="fi" id="o-d" placeholder="Describe the situation..."></textarea></div>
      <button class="btn bp" id="btn-o" onclick="doRep('o')">📤 Submit Report</button>
    </div>
  </div>
  <!-- My Reports History -->
  <div class="card">
    <div class="cl"><span class="clb"></span>📁 My Reports</div>
    <?php if(empty($myIncidents)):?>
    <div class="empty"><div class="eico">📭</div>No reports submitted yet.</div>
    <?php else:?>
    <table class="dt">
      <thead><tr><th>Code</th><th>Type</th><th>Severity</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($myIncidents as $r):
        $sc=['Open'=>'bop','In Progress'=>'bpr','Under Review'=>'bpr','Assessed'=>'bp2','Budget Pending'=>'bpr','Closed'=>'bdn'][$r['status']]??'bp2';
        $dmgTotal=(float)($r['damage_total']??0);
      ?>
      <tr>
        <td style="color:var(--g300);font-weight:700"><?=e($r['incident_code']??'')?></td>
        <td style="font-weight:600"><?=e($r['incident_type'])?></td>
        <td><span class="badge <?=in_array($r['severity']??'Minor',['Major','Critical'])?'bop':'bp2'?>"><?=e($r['severity']??'Minor')?></span></td>
        <td style="color:var(--mut)"><?=e($r['incident_date'])?></td>
        <td>
          <span class="badge <?=$sc?>"><?=e($r['status'])?></span>
          <?php if($dmgTotal>0):?><div style="font-size:10px;color:var(--red);font-weight:700;margin-top:2px;">₱<?=number_format($dmgTotal,2)?></div><?php endif;?>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
    <?php endif;?>
  </div>
</div>

<!-- ═══ PERFORMANCE ══════════════════════════════════════════════════════════ -->
<div class="sec" id="sec-perf">
  <div style="font-family:'DM Serif Display',serif;font-size:23px;margin-bottom:3px;">⭐ Performance</div>
  <div style="font-size:13px;color:var(--mut);margin-bottom:18px;">Your scorecard and driving stats</div>
  <?php
  $scC=$score>=85?'#4ade80':($score>=70?'#f59e0b':'#f87171');
  $scA=round($score/100*283);
  ?>
  <div class="g2" style="margin-bottom:16px;">
    <div class="card" style="text-align:center;">
      <div class="cl" style="justify-content:center;"><span class="clb"></span>Behavior Score</div>
      <div style="display:flex;flex-direction:column;align-items:center;gap:8px;">
        <div class="sr">
          <svg width="110" height="110" viewBox="0 0 110 110">
            <circle cx="55" cy="55" r="45" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="9"/>
            <circle cx="55" cy="55" r="45" fill="none" stroke="<?=$scC?>" stroke-width="9" stroke-dasharray="<?=$scA?> 283" stroke-linecap="round"/>
          </svg>
          <div class="sri"><div class="srn" style="color:<?=$scC?>"><?=$score?></div><div class="srs">/ 100</div></div>
        </div>
        <div style="font-size:13px;font-weight:600;color:<?=$scC?>"><?=$score>=85?'Excellent':($score>=70?'Good':($score>=55?'Fair':'Needs Work'))?></div>
      </div>
    </div>
    <div class="card">
      <div class="cl"><span class="clb"></span>Driver Info</div>
      <?php foreach([['Name',$driverInfo['full_name']],['Code',$driverInfo['driver_code']??'—'],['License',$driverInfo['license_no']],['Expiry',$driverInfo['license_expiry']??'—'],['Phone',$driverInfo['phone']??'—'],['Status',$driverInfo['status']??'—'],['🏆 Points',(int)($driverInfo['points']??0).' pts']] as [$k,$v]):?>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;gap:8px;"><span style="color:var(--mut);flex-shrink:0"><?=e($k)?></span><strong style="text-align:right"><?=e($v)?></strong></div>
      <?php endforeach;?>
    </div>
  </div>
  <div class="card">
    <div class="cl"><span class="clb"></span>Metrics</div>
    <?php
    $allT=count($myTrips); $dT=count($doneTrips); $iT=count($myIncidents);
    $eff=($totalMileage>0&&$totalLiters>0)?min(100,intval($totalMileage/$totalLiters*10)):0;
    foreach([['Completed Trips',$dT,max(1,$allT),'#4ade80'],['Clean Drives',max(0,$dT-$iT),max(1,$dT),'#60a5fa'],['Fuel Efficiency',$eff,100,'#fbbf24']] as [$l,$v,$mx,$c]):
      $pct=$mx>0?round($v/$mx*100):0;
    ?>
    <div class="prw"><span class="prl"><?=$l?></span><div class="prb"><div class="prf" style="width:<?=$pct?>%;background:<?=$c?>"></div></div><span class="prn" style="color:<?=$c?>"><?=$v?></span></div>
    <?php endforeach;?>
  </div>
  <div class="g2">
    <div class="card"><div class="cl"><span class="clb"></span>Trip Breakdown</div><div class="cw"><canvas id="ct"></canvas></div></div>
    <div class="card"><div class="cl"><span class="clb"></span>Fuel Cost by Month</div><div class="cw"><canvas id="cf"></canvas></div></div>
  </div>
</div>


<!-- ═══ EXPENSES ════════════════════════════════════════════════════════════ -->
<div class="sec" id="sec-expenses">
  <div style="font-family:'DM Serif Display',serif;font-size:23px;margin-bottom:3px;">💰 My Expenses</div>
  <div style="font-size:13px;color:var(--mut);margin-bottom:18px;">Log tolls, parking, repairs and other trip costs — visible to FVM admin</div>

<?php
$_driverExpTypes=[
  'Toll/Misc'     => ['🛣️','#60a5fa'],
  'Fuel'          => ['⛽','#f59e0b'],
  'Maintenance'   => ['🔧','#ef4444'],
  'Parts'         => ['🔩','#f97316'],
  'Insurance'     => ['🛡️','#a78bfa'],
  'Registration'  => ['📋','#22d3ee'],
  'Other'         => ['💬','#9ca3af'],
];
$_pendingExp  = array_filter($myDriverExp, fn($e)=>($e['status']??'Pending')==='Pending');
$_approvedExp = array_filter($myDriverExp, fn($e)=>($e['status']??'Pending')==='Approved');
?>

  <!-- SUMMARY STRIP -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:11px;margin-bottom:18px;">
    <div class="st" style="border-top:3px solid var(--red);">
      <div class="st-ico">💸</div>
      <div class="st-v cr"><?=peso($totalDriverExp)?></div>
      <div class="st-l">Total Logged</div>
    </div>
    <div class="st" style="border-top:3px solid var(--amb);">
      <div class="st-ico">⏳</div>
      <div class="st-v ca"><?=count($_pendingExp)?></div>
      <div class="st-l">Pending Review</div>
    </div>
    <div class="st" style="border-top:3px solid var(--g400);">
      <div class="st-ico">✅</div>
      <div class="st-v cg"><?=count($_approvedExp)?></div>
      <div class="st-l">Approved</div>
    </div>
  </div>

  <!-- LOG NEW EXPENSE -->
  <div class="card ct" style="margin-bottom:16px;">
    <div class="cl"><span class="clb"></span>➕ Log New Expense</div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Expense Type</label>
        <div class="sw"><select class="fi" id="ex-type">
          <?php foreach($_driverExpTypes as $_et=>[$_ico,$_col]):?>
          <option value="<?=e($_et)?>"><?=$_ico?> <?=e($_et)?></option>
          <?php endforeach;?>
        </select></div>
      </div>
      <div class="fg">
        <label class="fl">Amount (₱)</label>
        <input type="number" step="0.01" min="0" class="fi" id="ex-amt" placeholder="e.g. 250.00" oninput="calcEx()">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Vehicle</label>
        <div class="sw"><select class="fi" id="ex-vid">
          <?php if($activeVehicle):?>
          <option value="<?=e($activeVehicle['id'])?>" selected><?=e($activeVehicle['plate'])?> (Active Trip)</option>
          <?php endif;?>
          <?php foreach($vehicles as $_v): if($activeVehicle&&$_v['id']===$activeVehicle['id']) continue;?>
          <option value="<?=e($_v['id'])?>"><?=e($_v['plate'])?> · <?=e($_v['make'].' '.$_v['model'])?></option>
          <?php endforeach;?>
        </select></div>
      </div>
      <div class="fg">
        <label class="fl">Trip (optional)</label>
        <div class="sw"><select class="fi" id="ex-tid">
          <option value="">— Not linked to a trip —</option>
          <?php if($activeTrip):?>
          <option value="<?=e($activeTrip['id'])?>" selected><?=e($activeTrip['trip_code'])?> (Active)</option>
          <?php endif;?>
          <?php foreach(array_slice($myTrips,0,8) as $_t): if($activeTrip&&$_t['id']===$activeTrip['id']) continue;?>
          <option value="<?=e($_t['id'])?>"><?=e($_t['trip_code'])?> — <?=e(mb_substr($_t['origin'],0,12))?> → <?=e(mb_substr($_t['destination'],0,12))?></option>
          <?php endforeach;?>
        </select></div>
      </div>
    </div>
    <div class="fg">
      <label class="fl">Notes / Details</label>
      <input type="text" class="fi" id="ex-note" placeholder="e.g. SLEX Toll Gate 3, Emergency flat repair at Laguna…">
    </div>
    <div class="fg">
      <label class="fl">📷 Receipt Photo (optional)</label>
      <div class="pz" onclick="document.getElementById('ex-pf').click()">
        <span id="ex-pl">📷 Tap to attach receipt photo</span>
        <input type="file" id="ex-pf" accept="image/*" capture="environment" style="display:none" onchange="handleP(this,'ex')">
        <img id="ex-pp" class="pprev" alt="">
      </div>
      <input type="hidden" id="ex-pu" value="">
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:6px;">
      <div id="ex-tot" style="font-size:14px;font-weight:700;color:var(--g300);"></div>
      <button class="btn bp" id="btn-exp" onclick="submitExpense()">💾 Save Expense</button>
    </div>
  </div>

  <!-- BREAKDOWN BY CATEGORY -->
  <?php if(!empty($driverExpByCat)): ?>
  <div class="g2" style="margin-bottom:16px;">
    <div class="card">
      <div class="cl"><span class="clb"></span>📊 By Category</div>
      <?php foreach($driverExpByCat as $_cat=>$_tot):
        [$_ico2,$_col2]=$_driverExpTypes[$_cat]??['📋','#9ca3af'];
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;gap:8px;">
        <span style="font-size:13px;"><?=$_ico2?> <?=e($_cat)?></span>
        <strong style="color:<?=$_col2?>;font-size:13px;"><?=peso($_tot)?></strong>
      </div>
      <?php endforeach;?>
    </div>
    <div class="card">
      <div class="cl"><span class="clb"></span>📈 Distribution</div>
      <div class="cw"><canvas id="exp-donut"></canvas></div>
    </div>
  </div>
  <?php endif;?>

  <!-- HISTORY TABLE -->
  <div class="card">
    <div class="cl"><span class="clb"></span>📋 Expense Records (<?=count($myDriverExp)?>)</div>
    <?php if(empty($myDriverExp)):?>
    <div class="empty">
      <div class="eico">💸</div>
      No expenses logged yet.<br>
      <span style="font-size:12px;color:var(--sub);">Use the form above to log a toll, repair, or other cost.</span>
    </div>
    <?php else:?>
    <div style="overflow-x:auto;">
    <table class="dt">
      <thead>
        <tr>
          <th>Date</th><th>Type</th><th>Amount</th><th>Vehicle</th><th>Note</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($myDriverExp as $_ex):
        $_et2  = $_ex['expense_type']??'Other';
        [$_ico3,$_col3] = $_driverExpTypes[$_et2]??['📋','#9ca3af'];
        $_vex  = null; foreach($vehicles as $_v2) if($_v2['id']===$_ex['vehicle_id']){$_vex=$_v2;break;}
        $_st   = $_ex['status']??'—';
        $_stCls= ['Approved'=>'bdn','Rejected'=>'bop','Pending'=>'bpr'][$_st]??'bp2';
        // Clean notes: strip the [Driver Expense] prefix for display
        $_dispNote = preg_replace('/^\[Driver Expense\]\s*/','', $_ex['notes']??'');
        $_dispNote = preg_replace('/\s*\|\s*(Driver|Trip):.*$/','',$_dispNote);
        if(!$_dispNote) $_dispNote = $_et2;
      ?>
      <tr>
        <td style="color:var(--mut);white-space:nowrap"><?=e($_ex['expense_date']??$today)?></td>
        <td>
          <span style="display:inline-flex;align-items:center;gap:5px;">
            <span><?=$_ico3?></span>
            <span style="font-weight:600;color:<?=$_col3?>"><?=e($_et2)?></span>
          </span>
        </td>
        <td style="font-weight:700;color:var(--red);white-space:nowrap"><?=peso($_ex['amount']??0)?></td>
        <td style="color:var(--mut);font-size:12px"><?=e($_vex?$_vex['plate']:'—')?></td>
        <td style="color:var(--mut);font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
            title="<?=e($_dispNote)?>"><?=e($_dispNote)?></td>
        <td><span class="badge <?=$_stCls?>"><?=$_st?></span></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
    </div>
    <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;">
      <span style="font-size:12px;color:var(--mut);"><?=count($myDriverExp)?> record<?=count($myDriverExp)!==1?'s':''?></span>
      <div style="font-size:15px;font-weight:700;">Total: <span class="cr"><?=peso($totalDriverExp)?></span></div>
    </div>
    <?php endif;?>
  </div>
</div>

<!-- ═══ HISTORY ═══════════════════════════════════════════════════════════════ -->
<div class="sec" id="sec-history">
  <div style="font-family:'DM Serif Display',serif;font-size:23px;margin-bottom:3px;">📁 Trip History</div>
  <div style="font-size:13px;color:var(--mut);margin-bottom:18px;">All your assigned trips</div>
  <?php if(empty($myTrips)):?>
  <div class="card" style="text-align:center;padding:46px;"><div style="font-size:32px;opacity:.25;margin-bottom:9px;">🗂️</div><div style="color:var(--mut);">No trips assigned yet.</div></div>
  <?php else:?>
  <div class="card">
    <div class="cl"><span class="clb"></span>All Trips (<?=count($myTrips)?>)</div>
    <table class="dt">
      <thead><tr><th>Code</th><th>Route</th><th>Date</th><th>Priority</th><th>Km</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($myTrips as $t):
        $bc=['Pending'=>'bp2','In Progress'=>'bpr','Completed'=>'bdn'][$t['status']]??'bp2';
      ?>
      <tr>
        <td style="color:var(--g300);font-weight:600"><?=e($t['trip_code']??'')?></td>
        <td><strong><?=e($t['origin'])?></strong><span style="color:var(--mut)"> → </span><strong><?=e($t['destination'])?></strong><?php if($t['purpose']):?><div style="font-size:11px;color:var(--mut)"><?=e($t['purpose'])?></div><?php endif;?></td>
        <td style="color:var(--mut)"><?=e($t['scheduled_date'])?></td>
        <td><span class="badge <?=$t['priority']==='Urgent'?'bop':'bp2'?>"><?=e($t['priority']??'Normal')?></span></td>
        <td style="color:var(--g300)"><?=$t['mileage_km']>0?number_format($t['mileage_km']):'—'?></td>
        <td><span class="badge <?=$bc?>"><?=e($t['status'])?></span></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <?php endif;?>
</div>

<!-- ═══ NOTIFICATIONS ════════════════════════════════════════════════════════ -->
<div class="sec" id="sec-notif">
  <div style="font-family:'DM Serif Display',serif;font-size:23px;margin-bottom:3px;">🔔 Alerts</div>
  <div style="font-size:13px;color:var(--mut);margin-bottom:18px;">Trip assignments and updates from dispatch</div>
  <div id="notif-list">
    <div class="empty" id="notif-empty" style="display:block;"><div class="eico">🔔</div>No new notifications.</div>
  </div>
</div>

</div><!-- .pg -->

<!-- ═══ COMPLETE TRIP MODAL ═══════════════════════════════════════════════════ -->
<div id="modal-complete" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.8);align-items:flex-end;justify-content:center;">
  <div style="background:var(--surf);border-radius:24px 24px 0 0;padding:28px 24px 40px;width:100%;max-width:520px;animation:fadeUp .3s ease;">
    <div style="font-family:'DM Serif Display',serif;font-size:20px;margin-bottom:4px;">✅ Complete Trip</div>
    <div style="font-size:13px;color:var(--mut);margin-bottom:20px;">Attach a proof photo then submit.</div>
    <div class="fg">
      <label class="fl">📷 Proof Photo (Required)</label>
      <div class="pz" id="cp-zone" onclick="document.getElementById('cp-file').click()">
        <span id="cp-lbl">📷 Tap to take or attach delivery proof</span>
        <input type="file" id="cp-file" accept="image/*" capture="environment" style="display:none" onchange="handleCompletePhoto(this)">
        <img id="cp-prev" class="pprev" alt="" style="display:none;max-height:160px;object-fit:cover;">
      </div>
      <input type="hidden" id="cp-photo-url" value="">
    </div>
    <div class="fg" style="margin-top:12px;">
      <label class="fl">📏 Final Odometer (km) — optional</label>
      <input type="number" class="fi" id="cp-odo" placeholder="e.g. 48500">
    </div>
    <div id="cp-eta-info" style="display:none;background:rgba(76,175,80,.1);border:1px solid rgba(76,175,80,.25);border-radius:10px;padding:10px 14px;margin:12px 0;font-size:13px;color:var(--g300);">
      ⏱️ You are completing within ETA — <strong>+2 points</strong> will be awarded!
    </div>
    <div id="cp-error" style="display:none;background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.3);border-radius:10px;padding:10px 14px;margin:10px 0;font-size:13px;color:#dc2626;word-break:break-word;"></div>
    <div style="display:flex;gap:10px;margin-top:20px;">
      <button type="button" class="btn bo" style="flex:1;" onclick="closeCompleteModal()">Cancel</button>
      <button type="button" class="btn bp" style="flex:2;" id="btn-submit-complete" onclick="submitComplete()">✅ Submit & Complete</button>
    </div>
  </div>
</div>

<script>
/* TABS */
function goTab(n){
  document.querySelectorAll('.nt').forEach(t=>t.classList.remove('on'));
  document.querySelectorAll('.sec').forEach(s=>s.classList.remove('on'));
  var ntEl=document.getElementById('nt-'+n);
  var scEl=document.getElementById('sec-'+n);
  if(ntEl) ntEl.classList.add('on');
  if(scEl) scEl.classList.add('on');
  if(n==='perf') initCharts();
  if(n==='expenses') initExpChart();
  if(n==='gps'&&gpsMap) setTimeout(()=>gpsMap.invalidateSize(),80);
  if(n==='notif'){ pollNotifications(); clearNotifBadge(); }
  if(n==='gps' && typeof gpsOn!=='undefined' && !gpsOn && typeof TID!=='undefined' && TID){
    setTimeout(function(){ if(!gpsOn) startGPS(); }, 600);
  }
}
function swRT(n){
  document.querySelectorAll('.rt').forEach(t=>t.classList.remove('on'));
  document.querySelectorAll('.rb').forEach(b=>b.classList.remove('on'));
  document.getElementById('rt-'+n).classList.add('on');
  document.getElementById('rb-'+n).classList.add('on');
}

/* TOAST */
function toast(msg,type){
  var el=document.getElementById('toast');
  el.textContent=msg; el.className='toast on'+(type==='err'?' err':'');
  clearTimeout(el._t); el._t=setTimeout(()=>el.className='toast',3300);
}

/* PASSIVE GPS for report coords */
var cLat=null,cLng=null;
if(navigator.geolocation){
  navigator.geolocation.getCurrentPosition(function(p){
    cLat=p.coords.latitude; cLng=p.coords.longitude;
    var n=document.getElementById('i-gps');
    if(n) n.textContent='\u{1F4CD} '+cLat.toFixed(5)+', '+cLng.toFixed(5);
  },function(){},{enableHighAccuracy:true,timeout:8000});
}

/* FUEL */
function calcF(){
  var l=parseFloat(document.getElementById('flt').value)||0;
  var p=parseFloat(document.getElementById('fpp').value)||0;
  document.getElementById('f-tot').textContent=(l>0&&p>0)?'Total: ₱'+(l*p).toFixed(2):'';
}
function submitFuel(){
  var vid=document.getElementById('fv').value;
  var l=parseFloat(document.getElementById('flt').value)||0;
  var p=parseFloat(document.getElementById('fpp').value)||0;
  var o=parseInt(document.getElementById('fod').value)||0;
  var s=document.getElementById('fst').value.trim();
  var fa=parseFloat(document.getElementById('faf').value)||0;  // float for decimals like 20.5%
  if(!vid||l<=0||p<=0){toast('Fill in vehicle, liters and price per liter.','err');return;}
  var btn=document.getElementById('btn-fuel');
  btn.disabled=true;btn.textContent='⏳ Saving...';
  fetch('driver%20portal.php?log_fuel=1',{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({vehicle_id:vid,liters:l,price_per_liter:p,odometer_km:o,station:s,fuel_after:fa})
  })
  .then(function(r){
    if(!r.ok) throw new Error('HTTP '+r.status);
    return r.json();
  })
  .then(function(d){
    btn.disabled=false;btn.textContent='💾 Save Fill-Up';
    if(d.ok){
      var code=d.log_code?(' ['+d.log_code+']'):'';
      toast('✅ Fill-up saved'+code+'! Total: ₱'+parseFloat(d.total).toFixed(2)+' — synced to FVM');
      ['flt','fpp','fod','fst','faf'].forEach(function(id){
        var el=document.getElementById(id); if(el) el.value='';
      });
      document.getElementById('f-tot').textContent='';
      // Refresh fuel records list after short delay
      setTimeout(function(){ window.location.reload(); },1400);
    } else {
      var errMsg=d.error||'Supabase insert failed — check debug panel';
      toast('❌ '+errMsg,'err');
      console.error('log_fuel error:',d);
    }
  })
  .catch(function(err){
    btn.disabled=false;btn.textContent='💾 Save Fill-Up';
    toast('❌ '+(err.message||'Network error'),'err');
    console.error('log_fuel fetch error:',err);
  });
}

/* PHOTO */
function handleP(input,pfx){
  var file=input.files[0];if(!file)return;
  var r=new FileReader();
  r.onload=e=>{
    document.getElementById(pfx+'-pp').src=e.target.result;
    document.getElementById(pfx+'-pp').style.display='block';
    document.getElementById(pfx+'-pu').value=e.target.result;
    document.getElementById(pfx+'-pl').textContent='✅ '+file.name;
  };
  r.readAsDataURL(file);
}

/* REPORT */
var tMap={i:['i-t','i-s','i-v','i-d','i-pu','btn-i'],m:['m-t','m-s','m-v','m-d','m-pu','btn-m'],o:['o-t',null,'o-v','o-d',null,'btn-o']};
function doRep(pfx){
  var [tId,sId,vId,dId,puId,btnId]=tMap[pfx];
  var type=document.getElementById(tId)?.value||'Other';
  var sev=sId?document.getElementById(sId)?.value:'Minor';
  var vid=document.getElementById(vId)?.value||'';
  var desc=(document.getElementById(dId)?.value||'').trim();
  var photo=puId?document.getElementById(puId)?.value||'':'';
  if(!desc){toast('Please describe what happened.','err');document.getElementById(dId)?.focus();return;}
  if(!vid){toast('Please select a vehicle.','err');return;}
  var btn=document.getElementById(btnId);
  btn.disabled=true;btn.textContent='⏳ Submitting...';
  fetch('driver%20portal.php?submit_report=1',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({incident_type:type,severity:sev,vehicle_id:vid,
      trip_id:'<?=e($activeTrip?$activeTrip['id']:'')?>',
      description:desc,lat:cLat,lng:cLng,photo_url:photo})})
  .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(d=>{
    btn.disabled=false;btn.textContent='📤 Submit Report';
    if(d.ok){
      toast('✅ Report submitted successfully!');
      // Reset the form fields
      var tEl=document.getElementById(tMap[pfx][0]); if(tEl) tEl.value=tEl.options[0]?.value||'';
      var sEl=tMap[pfx][1]?document.getElementById(tMap[pfx][1]):null; if(sEl) sEl.value=sEl.options[0]?.value||'';
      var dEl=document.getElementById(tMap[pfx][3]); if(dEl) dEl.value='';
      var puEl=tMap[pfx][4]?document.getElementById(tMap[pfx][4]):null; if(puEl) puEl.value='';
      var ppEl=document.getElementById(pfx+'-pp'); if(ppEl){ppEl.src='';ppEl.style.display='none';}
      var plEl=document.getElementById(pfx+'-pl'); if(plEl) plEl.textContent='No file chosen';
      setTimeout(()=>{ window.location.reload(); },1200);
    } else {
      var errMsg=d.error||'Supabase insert failed';
      toast('❌ Submission failed. '+errMsg,'err');
      console.error('submit_report server error:',d);
    }
  }).catch((err)=>{btn.disabled=false;btn.textContent='📤 Submit Report';toast('❌ '+(err.message||'Network error.'),'err');console.error('submit_report error:',err);});
}

/* ODOMETER */
function submitOdo(){
  var km=parseInt(document.getElementById('odo-in').value)||0;
  var tid='<?=e($activeTrip?$activeTrip['id']:'')?>';
  if(!tid){toast('No active trip.','err');return;}
  if(km<=0){toast('Enter a valid odometer reading.','err');return;}
  fetch('driver%20portal.php?update_mileage=1',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({trip_id:tid,mileage_km:km})})
  .then(r=>r.json()).then(d=>{if(d.ok)toast('✅ Odometer saved. FVM updated.');else toast('❌ Failed.','err');})
  .catch(()=>toast('❌ Network error.','err'));
}

/* GPS TRACKER */
<?php if($activeTrip):?>
var gpsOn=false, watchId=null, pings=0;
var gpsMap=null, gpsMarker=null, gpsLine=null, gpsPath=[];
var destMarker=null, originMarker=null, routeLayer=null;
var TID='<?=e($activeTrip["id"])?>';
var TRIP_ORIGIN='<?=addslashes(e($activeTrip["origin"]))?>';
var TRIP_DEST='<?=addslashes(e($activeTrip["destination"]))?>';
var DEST_LAT=<?=!empty($activeTrip["dest_lat"])?(float)$activeTrip["dest_lat"]:'null'?>;
var DEST_LNG=<?=!empty($activeTrip["dest_lng"])?(float)$activeTrip["dest_lng"]:'null'?>;
var ORIGIN_LAT=<?=!empty($activeTrip["origin_lat"])?(float)$activeTrip["origin_lat"]:'null'?>;
var ORIGIN_LNG=<?=!empty($activeTrip["origin_lng"])?(float)$activeTrip["origin_lng"]:'null'?>;
var TOTAL_DIST=null;
var arrivedAlerted=false;
var lastPingSent=-99999;   // first ping fires immediately
// cLat/cLng declared globally above (passive GPS reuses same vars)

// ── Haversine distance in metres ─────────────────────────────────────────────
function haversine(la1,ln1,la2,ln2){
  var R=6371000,r=Math.PI/180;
  var dLat=(la2-la1)*r, dLng=(ln2-ln1)*r;
  var a=Math.sin(dLat/2)*Math.sin(dLat/2)
       +Math.cos(la1*r)*Math.cos(la2*r)*Math.sin(dLng/2)*Math.sin(dLng/2);
  return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

// ── Geocode place name ────────────────────────────────────────────────────────
function geocodePlace(place,cb){
  fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q='
        +encodeURIComponent(place+', Philippines'))
    .then(function(r){return r.json();})
    .then(function(res){
      cb(res&&res[0]?{lat:parseFloat(res[0].lat),lng:parseFloat(res[0].lon)}:null);
    }).catch(function(){cb(null);});
}

// ── Fetch OSRM route ──────────────────────────────────────────────────────────
function fetchRoute(oLat,oLng,dLat,dLng,cb){
  var url='https://router.project-osrm.org/route/v1/driving/'
         +oLng+','+oLat+';'+dLng+','+dLat
         +'?overview=full&geometries=geojson&steps=true';
  fetch(url).then(function(r){return r.json();}).then(function(data){
    if(data.routes&&data.routes[0]){
      TOTAL_DIST=data.routes[0].distance;
      cb(data.routes[0]);
    } else cb(null);
  }).catch(function(){cb(null);});
}

// ── Build driver arrow icon (GrabMaps blue arrow) ─────────────────────────────
function buildDriverIcon(hdg,moving){
  var color=moving?'#1565C0':'#1976D2';
  var shadow=moving?'0 0 0 8px rgba(21,101,192,0.25)':'none';
  var svg='<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 44 44">'
    +'<circle cx="22" cy="22" r="18" fill="'+color+'" stroke="#fff" stroke-width="3" style="filter:drop-shadow(0 2px 6px rgba(0,0,0,.4))"/>'
    +'<polygon points="22,6 27,24 22,20 17,24" fill="#fff" transform="rotate('+hdg+',22,22)"/>'
    +'</svg>';
  return L.divIcon({html:'<div style="filter:drop-shadow(0 3px 8px rgba(0,0,0,.35))">'+svg+'</div>',
    iconSize:[44,44],iconAnchor:[22,22],className:''});
}

// ── Update GrabMaps-style top banner ─────────────────────────────────────────
function updateNavBanner(distToDest,streetName){
  var bannerDist=document.getElementById('nav-dist');
  var bannerStreet=document.getElementById('nav-street');
  if(!bannerDist||!bannerStreet)return;
  if(distToDest===null){bannerDist.textContent='—';bannerStreet.textContent=TRIP_DEST;return;}
  if(distToDest>1000){
    bannerDist.textContent=(distToDest/1000).toFixed(1)+' km';
  } else {
    bannerDist.textContent=Math.round(distToDest)+' m';
  }
  bannerStreet.textContent=streetName||TRIP_DEST;
}

// ── Map init on DOMContentLoaded ─────────────────────────────────────────────
window.addEventListener('DOMContentLoaded',function(){
  var mapEl=document.getElementById('gps-map');
  if(!mapEl)return;

  gpsMap=L.map('gps-map',{
    zoomControl:false,
    attributionControl:false,
    dragging:true,
    tap:true
  }).setView([14.5995,120.9842],15);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(gpsMap);

  // Zoom control bottom-right
  L.control.zoom({position:'bottomright'}).addTo(gpsMap);

  // Live trail — green polyline
  gpsLine=L.polyline([],{color:'#4ade80',weight:5,opacity:.9}).addTo(gpsMap);

  // Marker icons
  var oIco=L.divIcon({html:'<div style="background:#256427;color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:16px;border:3px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.4)">&#128681;</div>',iconSize:[32,32],iconAnchor:[16,16],className:''});
  var dIco=L.divIcon({html:'<div style="background:#dc2626;color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:16px;border:3px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.4)">&#128205;</div>',iconSize:[32,32],iconAnchor:[16,32],className:''});

  var oCoords=null, dCoords=null;

  function tryDrawRoute(){
    if(!oCoords||!dCoords)return;
    fetchRoute(oCoords.lat,oCoords.lng,dCoords.lat,dCoords.lng,function(route){
      if(!route)return;
      if(routeLayer)gpsMap.removeLayer(routeLayer);
      var coords=route.geometry.coordinates.map(function(c){return[c[1],c[0]];});
      // Blue planned route (under the green trail)
      routeLayer=L.polyline(coords,{color:'#1976D2',weight:7,opacity:.55}).addTo(gpsMap);
      routeLayer.bringToBack();
      gpsMap.fitBounds(routeLayer.getBounds(),{padding:[60,60]});
    });
  }

  // Use pre-geocoded coords if available
  if(ORIGIN_LAT&&ORIGIN_LNG){
    oCoords={lat:ORIGIN_LAT,lng:ORIGIN_LNG};
    originMarker=L.marker([ORIGIN_LAT,ORIGIN_LNG],{icon:oIco})
      .bindPopup('<b>&#128681; Origin</b><br>'+TRIP_ORIGIN).addTo(gpsMap);
    tryDrawRoute();
  } else {
    geocodePlace(TRIP_ORIGIN,function(c){
      if(!c)return; oCoords=c;
      originMarker=L.marker([c.lat,c.lng],{icon:oIco})
        .bindPopup('<b>&#128681; Origin</b><br>'+TRIP_ORIGIN).addTo(gpsMap);
      tryDrawRoute();
    });
  }

  if(DEST_LAT&&DEST_LNG){
    dCoords={lat:DEST_LAT,lng:DEST_LNG};
    destMarker=L.marker([DEST_LAT,DEST_LNG],{icon:dIco})
      .bindPopup('<b>&#128205; Destination</b><br>'+TRIP_DEST).addTo(gpsMap);
    tryDrawRoute();
  } else {
    geocodePlace(TRIP_DEST,function(c){
      if(!c)return;
      dCoords=c; DEST_LAT=c.lat; DEST_LNG=c.lng;
      destMarker=L.marker([c.lat,c.lng],{icon:dIco})
        .bindPopup('<b>&#128205; Destination</b><br>'+TRIP_DEST).addTo(gpsMap);
      tryDrawRoute();
    });
  }
});

// ── GPS toggle ────────────────────────────────────────────────────────────────
function toggleGPS(){gpsOn?stopGPS():startGPS();}

function startGPS(){
  if(!navigator.geolocation){toast('GPS not supported on this device.','err');return;}
  gpsOn=true; arrivedAlerted=false;
  lastPingSent=-99999; // reset so first ping fires immediately
  document.getElementById('btn-gps').className='bgps bgps-stop';
  document.getElementById('btn-gps').innerHTML='&#9209;&#65039; Stop Sharing';
  document.getElementById('gbdg').className='gbdg gon';
  document.getElementById('gbdg-txt').textContent='Live';
  var gpsDot=document.getElementById('gps-dot'); if(gpsDot) gpsDot.style.display='none';
  document.getElementById('glog').textContent='Acquiring GPS signal\u2026';

  // First: get a fast initial fix
  navigator.geolocation.getCurrentPosition(onPos, onErr, {
    enableHighAccuracy:true, timeout:10000, maximumAge:0
  });

  // Invalidate map size in case layout shifted
  if(gpsMap){ setTimeout(function(){gpsMap.invalidateSize();},200); }
  // Then watch continuously
  watchId=navigator.geolocation.watchPosition(onPos, onErr, {
    enableHighAccuracy:true, maximumAge:0, timeout:20000
  });
}

function stopGPS(){
  gpsOn=false;
  if(watchId!==null){ navigator.geolocation.clearWatch(watchId); watchId=null; }
  document.getElementById('btn-gps').className='bgps bgps-go';
  document.getElementById('btn-gps').innerHTML='&#128225; Start Sharing';
  document.getElementById('gbdg').className='gbdg goff';
  document.getElementById('gbdg-txt').textContent='Offline';
  var gpsDot=document.getElementById('gps-dot'); if(gpsDot) gpsDot.style.display='block';
  document.getElementById('glog').textContent='Location sharing stopped.';
}

// ── Main GPS position handler ─────────────────────────────────────────────────
function onPos(pos){
  var now=Date.now();
  var lat=pos.coords.latitude;
  var lng=pos.coords.longitude;
  var spdMs=pos.coords.speed;
  var spd=(spdMs!==null&&spdMs!==undefined)?(spdMs*3.6):null;
  var hdg=pos.coords.heading||0;
  var acc=pos.coords.accuracy?Math.round(pos.coords.accuracy):0;
  var moving=(spd!==null&&spd>3);

  // Store current position
  cLat=lat; cLng=lng;

  // ── Update map ──────────────────────────────────────────────────────────────
  var p=[lat,lng];
  var icon=buildDriverIcon(hdg,moving);
  if(!gpsMarker){
    gpsMarker=L.marker(p,{icon:icon,zIndexOffset:1000}).addTo(gpsMap);
  } else {
    gpsMarker.setLatLng(p);
    gpsMarker.setIcon(icon);
  }

  // Accuracy circle
  if(window._accCircle) gpsMap.removeLayer(window._accCircle);
  if(acc>0&&acc<200){
    window._accCircle=L.circle(p,{
      radius:acc,color:'#1976D2',fillColor:'#1976D2',
      fillOpacity:0.1,weight:1
    }).addTo(gpsMap);
  }

  // Trail line
  gpsPath.push(p);
  if(gpsLine) gpsLine.setLatLngs(gpsPath);

  // Smooth follow — pan map to keep driver centred
  if(gpsMap){
    var zoom=gpsMap.getZoom();
    gpsMap.setView(p, zoom<15?16:zoom, {animate:true,duration:0.6,easeLinearity:0.25,noMoveStart:true});
  }

  // ── Stats bar update ────────────────────────────────────────────────────────
  var spdDisplay=spd!==null?Math.round(spd)+'':'—';
  var spdEl=document.getElementById('gs-spd');
  if(spdEl)spdEl.textContent=spdDisplay;
  var hdgEl=document.getElementById('gs-hdg');
  if(hdgEl)hdgEl.textContent=hdg>0?Math.round(hdg)+'\u00b0':'\u2014';
  var accEl=document.getElementById('gs-acc');
  if(accEl)accEl.textContent=acc>0?'\u00b1'+acc+'m':'\u2014';

  // Timestamp
  var tsEl=document.getElementById('gps-timestamp');
  if(tsEl)tsEl.textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit'});

  // ── Distance to destination & nav banner ────────────────────────────────────
  if(DEST_LAT&&DEST_LNG){
    var distToDest=haversine(lat,lng,DEST_LAT,DEST_LNG);
    updateNavBanner(distToDest, null);

    // Progress bar
    var progWrap=document.getElementById('dist-progress-wrap');
    if(progWrap) progWrap.style.display='block';
    var distEl=document.getElementById('dist-to-dest');
    if(distEl){
      distEl.textContent=distToDest>1000
        ?(distToDest/1000).toFixed(1)+' km to '+TRIP_DEST
        :Math.round(distToDest)+' m to '+TRIP_DEST;
    }
    if(ORIGIN_LAT&&ORIGIN_LNG&&TOTAL_DIST){
      var progBar=document.getElementById('progress-bar');
      if(progBar){
        var distFromOrigin=haversine(lat,lng,ORIGIN_LAT,ORIGIN_LNG);
        var pct=Math.min(100,Math.round(distFromOrigin/(distFromOrigin+distToDest)*100));
        progBar.style.width=pct+'%';
      }
    }

    // ── Arrival detection — within 50m ──────────────────────────────────────
    if(!arrivedAlerted&&distToDest<=50){
      arrivedAlerted=true;
      showArrivalAlert();
      return; // skip ping, arrival will send one with arrived=true
    }
  }

  // ── Throttled Supabase ping every 5 seconds ──────────────────────────────
  if((now-lastPingSent)<5000)return;
  lastPingSent=now;
  pings++;
  var pngEl=document.getElementById('gs-png');
  if(pngEl)pngEl.textContent=pings;

  var spdVal=spd!==null?parseFloat(spd.toFixed(1)):0;
  fetch('driver%20portal.php?gps_ping=1',{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({trip_id:TID,lat:lat,lng:lng,speed:spdVal,heading:hdg,accuracy:acc})
  }).then(function(r){return r.json();})
  .then(function(d){
    var logEl=document.getElementById('glog');
    if(logEl){
      if(d.ok){
        logEl.textContent='\u2713 Ping #'+pings+' \u00b7 '+lat.toFixed(5)+', '+lng.toFixed(5)
          +(spd!==null?' \u00b7 '+Math.round(spd)+' km/h':'')
          +' \u00b7 '+new Date().toLocaleTimeString('en-PH');
      } else {
        logEl.textContent='\u26a0 Server error: '+(d.error||'unknown')+' \u2014 retrying\u2026';
      }
    }
  }).catch(function(){
    var logEl=document.getElementById('glog');
    if(logEl)logEl.textContent='\u26a0 Ping failed \u2014 check connection';
  });
}

function onErr(e){
  var msgs={
    1:'Location permission denied \u2014 tap the lock icon in your browser and allow location',
    2:'Position unavailable \u2014 check GPS signal',
    3:'GPS timeout \u2014 ensure you are outdoors or near a window'
  };
  var msg=msgs[e.code]||e.message;
  var logEl=document.getElementById('glog');
  if(logEl)logEl.textContent='\u274c '+msg;
  toast(msg,'err');
  // Don't stop GPS on timeout — retry
  if(e.code!==1){
    setTimeout(function(){
      if(gpsOn&&watchId===null){
        watchId=navigator.geolocation.watchPosition(onPos,onErr,{enableHighAccuracy:true,maximumAge:0,timeout:20000});
      }
    },3000);
  }
}

// ── Arrival alert (GrabMaps style) ───────────────────────────────────────────
function showArrivalAlert(){
  stopGPS();
  if(navigator.vibrate)navigator.vibrate([300,100,300,100,600]);

  var el=document.createElement('div');
  el.id='arrival-alert';
  el.style.cssText='position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;padding:24px;background:rgba(0,0,0,.7);animation:fadeUp .3s ease;';
  el.innerHTML=
    '<div style="background:#0f2012;border:2px solid rgba(76,175,80,.4);border-radius:24px 24px 16px 16px;padding:28px 24px 32px;width:100%;max-width:420px;text-align:center;">'
    +'<div style="width:60px;height:60px;background:linear-gradient(135deg,#256427,#4caf50);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;box-shadow:0 4px 20px rgba(76,175,80,.4);">&#127919;</div>'
    +'<div style="font-family:\'DM Serif Display\',serif;font-size:24px;margin-bottom:5px;">You\'ve Arrived!</div>'
    +'<div style="font-size:13px;color:rgba(255,255,255,.55);margin-bottom:4px;">Destination reached</div>'
    +'<div style="font-size:15px;font-weight:700;color:#4ade80;margin-bottom:4px;">&#128205; '+TRIP_DEST+'</div>'
    +'<div style="font-size:11px;color:rgba(255,255,255,.35);margin-bottom:24px;font-family:monospace;">'+new Date().toLocaleString('en-PH')+'</div>'
    +'<div style="display:flex;gap:10px;">'
    +'<button onclick="document.getElementById(\'arrival-alert\').remove();startGPS();" '
    +'style="flex:1;padding:14px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:transparent;color:rgba(255,255,255,.75);font-family:\'Outfit\',sans-serif;font-size:14px;font-weight:700;cursor:pointer;">Not Yet</button>'
    +'<button onclick="confirmArrival()" '
    +'style="flex:2;padding:14px;border-radius:12px;border:none;background:linear-gradient(135deg,#1565C0,#1976D2);color:#fff;font-family:\'Outfit\',sans-serif;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(21,101,192,.4);">&#10003; Confirm Arrival</button>'
    +'</div></div>';
  document.body.appendChild(el);

  // 3-tone arrival chime
  try{
    var ctx=new(window.AudioContext||window.webkitAudioContext)();
    [[880,0],[1100,0.2],[1320,0.4]].forEach(function(pair){
      var freq=pair[0],delay=pair[1];
      var osc=ctx.createOscillator();var gain=ctx.createGain();
      osc.connect(gain);gain.connect(ctx.destination);
      osc.frequency.value=freq;
      gain.gain.setValueAtTime(0.3,ctx.currentTime+delay);
      gain.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+delay+0.5);
      osc.start(ctx.currentTime+delay);osc.stop(ctx.currentTime+delay+0.5);
    });
  }catch(e){}
  toast('&#128205; You have reached '+TRIP_DEST+'!');
}

function confirmArrival(){
  var el=document.getElementById('arrival-alert');
  if(el)el.remove();

  // Notify FVM with arrived=true flag
  var arrivedLat=cLat||0, arrivedLng=cLng||0;
  fetch('driver%20portal.php?gps_ping=1',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({trip_id:TID,lat:arrivedLat,lng:arrivedLng,speed:0,heading:0,accuracy:0,arrived:true})
  }).catch(function(){});

  toast('&#10003; Arrival confirmed \u2014 dispatch has been notified!');
  goTab('dash');

  // Persistent arrived banner on dashboard
  var note=document.createElement('div');
  note.style.cssText='background:rgba(21,101,192,.12);border:1px solid rgba(21,101,192,.3);border-radius:12px;padding:14px 18px;margin-bottom:14px;font-size:13px;color:#93c5fd;font-weight:600;text-align:center;';
  note.innerHTML='&#127919; Arrived at '+TRIP_DEST+' \u2014 '+new Date().toLocaleTimeString('en-PH')+'. Waiting for dispatch to mark trip complete.';
  var secDash=document.getElementById('sec-dash');
  if(secDash)secDash.prepend(note);
}

// ── Heartbeat: keep driver marked online every 30s while portal is open ──────
setInterval(function(){
  fetch('driver%20portal.php?heartbeat=1',{credentials:'same-origin'}).catch(function(){});
},30000);

<?php else:?>
var gpsMap=null;
<?php endif;?>


/* EXPENSES */
function calcEx(){
  var a=parseFloat(document.getElementById('ex-amt').value)||0;
  var el=document.getElementById('ex-tot');
  if(el) el.textContent=a>0?'Total: \u20b1'+a.toFixed(2):'';
}
function submitExpense(){
  var vid  =document.getElementById('ex-vid').value;
  var etype=document.getElementById('ex-type').value;
  var amt  =parseFloat(document.getElementById('ex-amt').value)||0;
  var note =document.getElementById('ex-note').value.trim();
  var tid  =(document.getElementById('ex-tid')||{}).value||'';
  if(!vid||!etype||amt<=0){toast('Please fill in vehicle, type and amount.','err');return;}
  var btn=document.getElementById('btn-exp');
  btn.disabled=true; btn.textContent='\u23f3 Saving...';
  fetch('driver%20portal.php?log_expense=1',{
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({vehicle_id:vid,expense_type:etype,amount:amt,note:note,trip_id:tid||null})
  })
  .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
  .then(function(d){
    btn.disabled=false; btn.textContent='\ud83d\udcbe Save Expense';
    if(d.ok){
      var code=d.expense_code?' ['+d.expense_code+']':'';
      toast('\u2705 Expense saved'+code+'!');
      document.getElementById('ex-amt').value='';
      document.getElementById('ex-note').value='';
      document.getElementById('ex-tot').textContent='';
      var pp=document.getElementById('ex-pp'); if(pp){pp.src='';pp.style.display='none';}
      var pl=document.getElementById('ex-pl'); if(pl) pl.textContent='\ud83d\udcf7 Tap to attach receipt photo';
      document.getElementById('ex-pu').value='';
      setTimeout(function(){ window.location.reload(); },1200);
    } else {
      toast('\u274c '+(d.error||'Failed to save expense'),'err');
    }
  })
  .catch(function(err){
    btn.disabled=false; btn.textContent='\ud83d\udcbe Save Expense';
    toast('\u274c '+(err.message||'Network error'),'err');
  });
}
var _expChartDone=false;
function initExpChart(){
  if(_expChartDone) return; _expChartDone=true;
  var cv=document.getElementById('exp-donut');
  if(!cv) return;
  var labels=<?php echo json_encode(count($driverExpByCat)?array_keys($driverExpByCat):['No data']); ?>;
  var vals=<?php echo json_encode(count($driverExpByCat)?array_values($driverExpByCat):[1]); ?>;
  var clrs=['#60a5fa','#f59e0b','#ef4444','#f97316','#a78bfa','#22d3ee','#9ca3af'];
  new Chart(cv,{
    type:'doughnut',
    data:{labels:labels,datasets:[{data:vals,backgroundColor:clrs.slice(0,labels.length),borderWidth:0}]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'60%',
      plugins:{legend:{position:'bottom',labels:{color:'#8fa592',font:{size:11},padding:8}}}}
  });
}

/* CHARTS */
var chartsOk=false;
function initCharts(){
  if(chartsOk)return;chartsOk=true;
  <?php
  $cD=count($doneTrips);$cI=count(array_filter($myTrips,fn($t)=>$t['status']==='In Progress'));$cP=count(array_filter($myTrips,fn($t)=>$t['status']==='Pending'));
  $fm=[];foreach($myFuelLogs as $fl){$mo=substr($fl['log_date'],0,7);$fm[$mo]=($fm[$mo]??0)+floatval($fl['total_cost']);}ksort($fm);
  ?>
  var ct=document.getElementById('ct');
  if(ct)new Chart(ct,{type:'doughnut',data:{labels:['Completed','In Progress','Pending'],datasets:[{data:[<?=$cD?>,<?=$cI?>,<?=$cP?>],backgroundColor:['#4ade80','#fbbf24','#a5b4fc'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom',labels:{color:'#8fa592',font:{size:11},padding:10}}}}});
  var cf=document.getElementById('cf');
  if(cf)new Chart(cf,{type:'bar',data:{labels:<?=json_encode(array_keys($fm))?>,datasets:[{data:<?=json_encode(array_values($fm))?>,backgroundColor:'rgba(245,158,11,.65)',borderColor:'#f59e0b',borderWidth:1.5,borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'₱'+v,color:'#627065'},grid:{color:'rgba(255,255,255,.05)'}},x:{ticks:{color:'#627065'},grid:{display:false}}}}});
}

/* START TRIP */
function startTrip(tid){
  var btn=document.getElementById('btn-start-trip');
  if(btn){btn.disabled=true;btn.textContent='⏳ Starting…';}
  fetch('driver%20portal.php?start_trip=1',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({trip_id:tid})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){ toast('🚀 Trip started! Head to GPS tab to begin tracking.'); setTimeout(function(){location.reload();},900); }
    else{ toast('❌ '+(d.error||'Could not start trip'),'err'); if(btn){btn.disabled=false;btn.textContent='▶ Start Trip';} }
  }).catch(function(){
    toast('❌ Network error','err');
    if(btn){btn.disabled=false;btn.textContent='▶ Start Trip';}
  });
}

/* COMPLETE TRIP MODAL */
var _completeTid=null;
<?php if($activeTrip): ?>
var _cEtaMin=<?=(int)($activeTrip['eta_minutes']??$activeTrip['dispatch_timer']??0)?>;
var _cDispatched=<?=json_encode($activeTrip['dispatched_at']??null)?>;
<?php else: ?>
var _cEtaMin=0, _cDispatched=null;
<?php endif; ?>

function openCompleteModal(tid){
  _completeTid=tid;
  var m=document.getElementById('modal-complete');
  if(m){m.style.display='flex';document.body.style.overflow='hidden';}
  // Reset all fields on every open
  document.getElementById('cp-photo-url').value='';
  document.getElementById('cp-lbl').textContent='📷 Tap to take or attach delivery proof';
  var prev=document.getElementById('cp-prev');
  if(prev){prev.src='';prev.style.display='none';}
  var odo=document.getElementById('cp-odo');
  if(odo) odo.value='';
  var errBox=document.getElementById('cp-error');
  if(errBox){errBox.textContent='';errBox.style.display='none';}
  var btn=document.getElementById('btn-submit-complete');
  if(btn){btn.disabled=false;btn.textContent='✅ Submit & Complete';}
  // Show on-time hint if still within ETA
  var hint=document.getElementById('cp-eta-info');
  if(hint&&_cEtaMin>0&&_cDispatched){
    var elapsed=(Date.now()-new Date(_cDispatched).getTime())/60000;
    hint.style.display=elapsed<=_cEtaMin?'block':'none';
  } else if(hint) hint.style.display='none';
}
function closeCompleteModal(){
  var m=document.getElementById('modal-complete');
  if(m){m.style.display='none';document.body.style.overflow='';}
}
function handleCompletePhoto(input){
  if(!input.files||!input.files[0]) return;
  var file=input.files[0];
  var lbl=document.getElementById('cp-lbl');
  var prev=document.getElementById('cp-prev');
  if(lbl) lbl.textContent='⏳ Processing…';
  var reader=new FileReader();
  reader.onload=function(ev){
    var img=new Image();
    img.onload=function(){
      // 800px max, 0.70 quality — keeps payload well under 200KB
      var MAX=800,w=img.width,h=img.height;
      if(w>MAX||h>MAX){if(w>h){h=Math.round(h*MAX/w);w=MAX;}else{w=Math.round(w*MAX/h);h=MAX;}}
      var cv=document.createElement('canvas');cv.width=w;cv.height=h;
      cv.getContext('2d').drawImage(img,0,0,w,h);
      var out=cv.toDataURL('image/jpeg',0.70);
      document.getElementById('cp-photo-url').value=out;
      if(prev){prev.src=out;prev.style.display='block';}
      if(lbl) lbl.textContent='📷 '+file.name+' ('+Math.round(out.length/1024)+'KB)';
    };
    img.src=ev.target.result;
  };
  reader.readAsDataURL(file);
}
function submitComplete(){
  var photo=document.getElementById('cp-photo-url').value;
  var errBox=document.getElementById('cp-error');
  function showErr(msg){
    if(errBox){errBox.textContent=msg;errBox.style.display='block';}
    toast(msg,'err');
  }
  if(!photo){showErr('📷 Please attach a proof photo first.');return;}
  var odo=parseInt(document.getElementById('cp-odo').value)||0;
  var btn=document.getElementById('btn-submit-complete');
  btn.disabled=true; btn.textContent='⏳ Submitting…';
  if(errBox) errBox.style.display='none';

  fetch('driver%20portal.php?complete_trip=1',{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({
      trip_id:_completeTid,
      photo_url:photo,
      mileage_km:odo,
      lat:(typeof cLat!=='undefined'?cLat:null),
      lng:(typeof cLng!=='undefined'?cLng:null)
    })
  })
  .then(function(r){
    if(!r.ok) return r.text().then(function(t){throw new Error('Server '+r.status+': '+t.slice(0,200));});
    return r.json();
  })
  .then(function(d){
    btn.disabled=false; btn.textContent='✅ Submit & Complete';
    if(d.ok){
      closeCompleteModal();
      var msg='✅ Trip completed!';
      if(d.points_awarded>0) msg+=' 🏆 +'+d.points_awarded+' points earned!';
      toast(msg);
      setTimeout(function(){location.reload();},1800);
    } else {
      showErr('❌ '+(d.error||'Submission failed — please try again.'));
      console.error('complete_trip error:',d);
    }
  })
  .catch(function(err){
    btn.disabled=false; btn.textContent='✅ Submit & Complete';
    showErr('❌ '+(err.message||'Network error — check your connection.'));
    console.error('submitComplete error:',err);
  });
}
function prepareSubmit(){submitComplete();return false;}

/* NOTIFICATIONS */
var _notifSeen={};
var _notifCount=0;
<?php if($loggedIn): ?>
var _driverId=<?=json_encode($driverId)?>;
<?php else: ?>
var _driverId=null;
<?php endif; ?>

function clearNotifBadge(){
  _notifCount=0;
  var b=document.getElementById('notif-badge');
  if(b) b.style.display='none';
}
function pollNotifications(){
  if(!_driverId) return;
  fetch('fvm.php?get_notifications=1&driver_id='+encodeURIComponent(_driverId),{credentials:'same-origin'})
  .then(function(r){return r.json();})
  .then(function(d){
    if(!d.ok||!Array.isArray(d.notifications)) return;
    var notifs=d.notifications;
    // Badge
    _notifCount=notifs.length;
    var badge=document.getElementById('notif-badge');
    if(badge){ if(_notifCount>0){badge.textContent=_notifCount;badge.style.display='inline-block';}else{badge.style.display='none';} }
    // Toast new ones
    notifs.forEach(function(n){
      if(!_notifSeen[n.id]){
        _notifSeen[n.id]=true;
        toast('🔔 '+n.title);
        if(n.type==='trip_assigned'){
          var bell=document.getElementById('nt-notif');
          if(bell){bell.style.color='#4ade80';setTimeout(function(){bell.style.color='';},3000);}
        }
      }
    });
    // Rebuild list in the notif tab
    var list=document.getElementById('notif-list');
    var empty=document.getElementById('notif-empty');
    if(!list) return;
    list.querySelectorAll('.nc').forEach(function(c){c.remove();});
    if(notifs.length===0){ if(empty) empty.style.display='block'; return; }
    if(empty) empty.style.display='none';
    notifs.forEach(function(n){
      var tc=n.type==='trip_assigned'?'#60a5fa':(n.type==='points_awarded'?'#4ade80':'#fbbf24');
      var ts=n.created_at?new Date(n.created_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):'';
      var card=document.createElement('div');
      card.className='nc card';
      card.style.cssText='border-left:3px solid '+tc+';margin-bottom:10px;';
      card.dataset.nid = n.id;
      card.innerHTML=
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
        +'<div style="flex:1;">'
        +'<div style="font-size:14px;font-weight:700;margin-bottom:4px;">'+htmlEsc(n.title)+'</div>'
        +'<div style="font-size:13px;color:rgba(255,255,255,.65);line-height:1.5;">'+htmlEsc(n.message)+'</div>'
        +'<div style="font-size:10px;color:var(--mut);margin-top:5px;font-family:monospace;">'+ts+'</div>'
        +'</div>'
        +'<button class="nc-dismiss" style="flex-shrink:0;background:rgba(255,255,255,.07);border:1px solid var(--bdr);border-radius:8px;padding:4px 10px;font-size:11px;color:var(--mut);cursor:pointer;">&#10003;</button>'
        +'</div>';
      card.querySelector('.nc-dismiss').addEventListener('click',function(){dismissNotif(card.dataset.nid);});
      list.appendChild(card);
    });
  }).catch(function(){});
}
function htmlEsc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
function dismissNotif(nid){
  fetch('fvm.php?mark_notif_read=1&notif_id='+encodeURIComponent(nid),{credentials:'same-origin'})
  .then(function(r){return r.json();}).then(function(d){
    if(d.ok){
      var card=document.querySelector('.nc[data-nid="'+nid+'"]');
      // rebuild by repoll
      pollNotifications();
    }
  }).catch(function(){});
}
<?php if($loggedIn): ?>
pollNotifications();
setInterval(pollNotifications,15000);
<?php endif; ?>

/* SCROLL HEADER */
window.addEventListener('scroll',()=>document.getElementById('hdr').classList.toggle('scrolled',scrollY>18));
</script>
<?php endif;?>
</body>
</html>