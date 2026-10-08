<?php
// 1. เปิดแสดง Error เพื่อแก้ปัญหาหน้าขาว
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';
date_default_timezone_set('Asia/Bangkok');

// 2. ปิด SQL Strict Mode ชั่วคราว
$conn->query("SET SESSION sql_mode = ''");

// --- จัดการตัวแปรค้นหา ---
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; 
$offset = ($page - 1) * $limit;

// --- ส่วนที่ 1: Active Case & Waiting List ---
$wardsData = [
    'ICU' => ['total' => 0, 'waiting' => 0, 'color' => 'danger', 'icon' => 'fa-heart-pulse', 'types' => []],
    'RCU' => ['total' => 0, 'waiting' => 0, 'color' => 'warning', 'icon' => 'fa-lungs', 'types' => []],
    'A4'  => ['total' => 0, 'waiting' => 0, 'color' => 'primary', 'icon' => 'fa-user-nurse', 'types' => []],
    'B3'  => ['total' => 0, 'waiting' => 0, 'color' => 'success', 'icon' => 'fa-hospital-user', 'types' => []]
];

foreach ($wardsData as $k => $v) {
    $wardsData[$k]['types'] = [ 'ทหารกองประจำการ' => 0, 'กำลังพล ทบ.' => 0, 'นอก ทบ.' => 0, 'อื่นๆ' => 0 ];
}

// Active Count
$sqlActive = "SELECT w.admit_type, p.person_type, COUNT(*) as count 
              FROM ward_details w 
              JOIN cases p ON w.case_id = p.id 
              WHERE w.status = 'Active' 
              GROUP BY w.admit_type, p.person_type";
$resActive = $conn->query($sqlActive);
if($resActive) {
    while($row = $resActive->fetch_assoc()) {
        $wardNameFull = $row['admit_type'];
        $type = trim($row['person_type']);
        $count = $row['count'];
        $wardKey = '';
        if (stripos($wardNameFull, 'ICU') !== false) $wardKey = 'ICU';
        elseif (stripos($wardNameFull, 'RCU') !== false) $wardKey = 'RCU';
        elseif (stripos($wardNameFull, 'A4') !== false) $wardKey = 'A4';
        elseif (stripos($wardNameFull, 'B3') !== false) $wardKey = 'B3';

        if ($wardKey && isset($wardsData[$wardKey])) {
            $wardsData[$wardKey]['total'] += $count;
            if ($type == 'ทหารกองประจำการ') $wardsData[$wardKey]['types']['ทหารกองประจำการ'] += $count;
            elseif ($type == 'ข้าราชการ' || $type == 'ลูกจ้าง') $wardsData[$wardKey]['types']['กำลังพล ทบ.'] += $count;
            elseif ($type == 'พลเรือน' || $type == 'ครอบครัว') $wardsData[$wardKey]['types']['นอก ทบ.'] += $count;
            else $wardsData[$wardKey]['types']['อื่นๆ'] += $count;
        }
    }
}

// Waiting Count
$sqlWait = "SELECT admit_type, COUNT(DISTINCT case_id) as count 
            FROM er_details 
            WHERE disposition = 'Admit' 
            AND case_id NOT IN (SELECT case_id FROM ward_details) 
            GROUP BY admit_type";
$resWait = $conn->query($sqlWait);
if($resWait) {
    while($row = $resWait->fetch_assoc()) {
        $wardNameFull = $row['admit_type'];
        $count = $row['count'];
        $wardKey = '';
        if (stripos($wardNameFull, 'ICU') !== false) $wardKey = 'ICU';
        elseif (stripos($wardNameFull, 'RCU') !== false) $wardKey = 'RCU';
        elseif (stripos($wardNameFull, 'A4') !== false) $wardKey = 'A4';
        elseif (stripos($wardNameFull, 'B3') !== false) $wardKey = 'B3';

        if ($wardKey && isset($wardsData[$wardKey])) {
            $wardsData[$wardKey]['waiting'] += $count;
        }
    }
}

// --- ส่วนที่ 2: สถิติรายเดือน ---
$dateConditionAdmit = " MONTH(w.admit_date) = '$month' AND YEAR(w.admit_date) = '$year' ";
$dateConditionDisch = " MONTH(w.discharge_date) = '$month' AND YEAR(w.discharge_date) = '$year' ";

$countAdmitMonth = $conn->query("SELECT COUNT(DISTINCT w.an) as count FROM ward_details w WHERE $dateConditionAdmit")->fetch_assoc()['count'];
$countDischMonth = $conn->query("SELECT COUNT(DISTINCT w.an) as count FROM ward_details w WHERE $dateConditionDisch AND (discharge_status LIKE 'Discharge%' OR discharge_status = 'Refer')")->fetch_assoc()['count'];
$countDeadMonth = $conn->query("SELECT COUNT(DISTINCT w.an) as count FROM ward_details w WHERE $dateConditionDisch AND discharge_status = 'Dead'")->fetch_assoc()['count'];
$countTotalAll = $conn->query("SELECT COUNT(DISTINCT w.an) as count FROM ward_details w")->fetch_assoc()['count'];

// --- ส่วนที่ 3: กราฟ ---
$chartAdmitData = array_fill(1, 12, 0);
$chartDischData = array_fill(1, 12, 0);

$sqlChartAdmit = "SELECT MONTH(admit_date) as m, COUNT(DISTINCT an) as count FROM ward_details WHERE YEAR(admit_date) = '$year' GROUP BY MONTH(admit_date)";
$resChartAdmit = $conn->query($sqlChartAdmit);
while($r = $resChartAdmit->fetch_assoc()) { $chartAdmitData[$r['m']] = $r['count']; }

$sqlChartDisch = "SELECT MONTH(discharge_date) as m, COUNT(DISTINCT an) as count FROM ward_details WHERE YEAR(discharge_date) = '$year' AND (discharge_status LIKE 'Discharge%' OR discharge_status = 'Refer') GROUP BY MONTH(discharge_date)";
$resChartDisch = $conn->query($sqlChartDisch);
while($r = $resChartDisch->fetch_assoc()) { $chartDischData[$r['m']] = $r['count']; }

// --- ส่วนที่ 4: ตารางรายชื่อ (1 AN = 1 บรรทัด และเช็คสถานะล่าสุด) ---
$whereSQL = " (MONTH(w.admit_date) = '$month' AND YEAR(w.admit_date) = '$year') ";
if (!empty($search)) {
    $whereSQL .= " AND (p.hn LIKE '%$search%' OR p.patient_name LIKE '%$search%' OR w.an LIKE '%$search%') ";
}

// 4.1 นับจำนวน
$sqlCount = "SELECT COUNT(DISTINCT w.case_id) as total FROM ward_details w JOIN cases p ON w.case_id = p.id WHERE $whereSQL";
$resCount = $conn->query($sqlCount);
$totalRows = $resCount->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// 4.2 ดึงข้อมูล (GROUP BY CaseID เพื่อไม่ให้ซ้ำ)
$sqlList = "SELECT w.case_id, w.admit_date as first_admit, p.hn, p.patient_name, p.person_type, w.an 
            FROM ward_details w 
            JOIN cases p ON w.case_id = p.id 
            WHERE $whereSQL 
            GROUP BY w.case_id 
            ORDER BY w.admit_date DESC 
            LIMIT $offset, $limit";
$resList = $conn->query($sqlList);

$thai_months = [
    '01'=>'มกราคม', '02'=>'กุมภาพันธ์', '03'=>'มีนาคม', '04'=>'เมษายน', '05'=>'พฤษภาคม', '06'=>'มิถุนายน',
    '07'=>'กรกฎาคม', '08'=>'สิงหาคม', '09'=>'กันยายน', '10'=>'ตุลาคม', '11'=>'พฤศจิกายน', '12'=>'ธันวาคม'
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Heat Stroke</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <style>
        :root { --primary-color: #0d6efd; --success-color: #0c784b; --light-bg: #fbfff1; }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--light-bg); color: #333; }
        h1, h2, h3, h4, h5, .font-prompt { font-family: 'Prompt', sans-serif; }

        .dashboard-header {
            background: linear-gradient(135deg, #0c784b 100%, #146c43 10%);
            color: white; padding: 10px 0; margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(25, 135, 84, 0.2); border-radius: 0 0 20px 20px;
        }
        .stat-card { border: none; border-radius: 15px; background: white; box-shadow: 0 5px 20px rgba(0,0,0,0.05); transition: transform 0.3s ease; height: 100%; overflow: hidden; position: relative; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .card-body { padding: 20px; }
        .icon-circle { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 10px; }
        .ward-detail-row { display: flex; justify-content: space-between; font-size: 0.85rem; color: #666; margin-bottom: 4px; border-bottom: 1px dashed #eee; }
        .waiting-badge { position: absolute; top: 15px; right: 15px; background-color: #dc3545; color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; box-shadow: 0 3px 10px rgba(220, 53, 69, 0.4); z-index: 10; }
        
        .search-card { background: white; border-radius: 50px; padding: 10px 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e9ecef; }
        .custom-select, .form-control { border-radius: 20px; padding: 8px 20px; }
        .btn-search { border-radius: 20px; padding: 8px 30px; font-weight: 600; background-color: #0c784b; color: white; border: none; }
        .btn-search:hover { background-color: #146c43; color: white; }

        .monthly-box { background: white; border-radius: 15px; padding: 20px; text-align: center; border-left: 5px solid #ccc; box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
        .monthly-box.blue { border-left-color: #0d6efd; }
        .monthly-box.green { border-left-color: #0c784b; }
        .monthly-box.red { border-left-color: #dc3545; }
        .monthly-box.dark { border-left-color: #343a40; }
        .stat-value { font-size: 2.5rem; font-weight: bold; line-height: 1; margin: 10px 0; }
        .stat-label { font-size: 0.9rem; color: #666; font-weight: 500; }

        .graph-card { background: white; border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); padding: 20px; border: none; margin-bottom: 30px; }
        .table-card { background: white; border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); padding: 20px; border: none; }
        .table thead th { background-color: #f8f9fa; color: #444; font-weight: 600; padding: 15px; }
        .table tbody td { vertical-align: middle; padding: 15px; }
        
        .pagination .page-link { border-radius: 50%; margin: 0 5px; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; color: #0c784b; border: 1px solid #dee2e6; }
        .pagination .page-item.active .page-link { background-color: #0c784b; border-color: #0c784b; color: white; }
    </style>
</head>
<body>

<?php 
    if(isset($_SESSION['name']) && $_SESSION['name'] == 'icu') require_once '../header_icu.php';
    else require_once '../header_ward.php'; 
?>
<div class="dashboard-header text-center">
    <h2 class="font-prompt fw-bold mb-0"><i class="fa-solid fa-chart-line me-2"></i>Dashboard สรุปสถานการณ์</h2>
    <small class="opacity-75">ข้อมูลสถิติผู้ป่วยโรคลมร้อนรายแผนกและภาพรวม</small>
</div>

<div class="container pb-5">

    <div class="row g-4 mb-5">
        <?php foreach ($wardsData as $wardName => $data): ?>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card border-bottom border-4 border-<?php echo $data['color']; ?>">
                <?php if ($data['waiting'] > 0): ?>
                <div class="waiting-badge animate__animated animate__pulse animate__infinite">
                    <i class="fa-solid fa-bell me-1"></i> +<?php echo $data['waiting']; ?> รอรับ
                </div>
                <?php endif; ?>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="font-prompt fw-bold text-secondary mb-0">Ward <?php echo $wardName; ?></h5>
                            <span class="text-muted small">ผู้ป่วย Active</span>
                        </div>
                    </div>
                    <div class="text-center mb-3">
                        <h1 class="fw-bold text-<?php echo $data['color']; ?>" style="font-size: 3.5rem;"><?php echo $data['total']; ?></h1>
                    </div>
                    <div class="bg-light rounded p-2">
                        <?php foreach($data['types'] as $type => $val): ?>
                        <div class="ward-detail-row"><span><?php echo $type; ?></span><span class="fw-bold text-dark"><?php echo $val; ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row justify-content-center mb-5">
        <div class="col-lg-10">
            <div class="search-card">
                <form action="" method="GET">
                    <div class="row align-items-center g-2">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill ps-3"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-start-0 rounded-end-pill" placeholder="ค้นหา AN, HN หรือ ชื่อ-นามสกุล..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select name="month" class="form-select">
                                <?php foreach($thai_months as $mVal => $mName): ?>
                                    <option value="<?php echo $mVal; ?>" <?php if($month == $mVal) echo 'selected'; ?>><?php echo $mName; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="year" class="form-select">
                                <?php for($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php if($year == $y) echo 'selected'; ?>><?php echo $y+543; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-search w-100 shadow-sm">ค้นหา</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-3 col-6">
            <div class="monthly-box blue">
                <div class="icon-circle bg-primary bg-opacity-10 text-primary mx-auto mb-2" style="width: 40px; height: 40px; font-size: 1.2rem;"><i class="fa-solid fa-hospital-user"></i></div>
                <div class="stat-value text-primary"><?php echo $countAdmitMonth; ?></div>
                <div class="stat-label">รับเข้า (เดือนนี้)</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="monthly-box green">
                <div class="icon-circle bg-success bg-opacity-10 text-success mx-auto mb-2" style="width: 40px; height: 40px; font-size: 1.2rem;"><i class="fa-solid fa-right-from-bracket"></i></div>
                <div class="stat-value text-success"><?php echo $countDischMonth; ?></div>
                <div class="stat-label">ส่งออก (เดือนนี้)</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="monthly-box red">
                <div class="icon-circle bg-danger bg-opacity-10 text-danger mx-auto mb-2" style="width: 40px; height: 40px; font-size: 1.2rem;"><i class="fa-solid fa-skull-crossbones"></i></div>
                <div class="stat-value text-danger"><?php echo $countDeadMonth; ?></div>
                <div class="stat-label">เสียชีวิต (เดือนนี้)</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="monthly-box dark">
                <div class="icon-circle bg-dark bg-opacity-10 text-dark mx-auto mb-2" style="width: 40px; height: 40px; font-size: 1.2rem;"><i class="fa-solid fa-chart-pie"></i></div>
                <div class="stat-value text-dark"><?php echo $countTotalAll; ?></div>
                <div class="stat-label">ยอดสะสมทั้งหมด</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-6">
            <div class="graph-card h-100">
                <h5 class="font-prompt fw-bold mb-3 text-center" style="color: #4BC0C0;">
                    <i class="fa-solid fa-chart-simple me-2"></i>สถิติการเข้ารักษา (ปี <?php echo $year+543; ?>)
                </h5>
                <div style="height: 300px;">
                    <canvas id="admitChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="graph-card h-100">
                <h5 class="font-prompt fw-bold mb-3 text-center" style="color: #0d6efd;">
                    <i class="fa-solid fa-chart-pie me-2"></i>สถิติการส่งออกคนไข้ (ปี <?php echo $year+543; ?>)
                </h5>
                <div style="height: 300px;">
                    <canvas id="dischChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-card">
        <h4 class="font-prompt fw-bold mb-4 text-center text-success"><i class="fa-solid fa-list me-2"></i>รายชื่อตามที่ค้นหา</h4>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">#</th>
                        <th width="15%">วันที่ Admit</th>
                        <th width="10%">AN</th>
                        <th width="10%">HN</th>
                        <th width="20%">ชื่อ-นามสกุล</th>
                        <th width="15%">ประเภท</th>
                        <th width="10%" class="text-center">แผนกปัจจุบัน</th>
                        <th width="10%" class="text-center">สถานะ</th>
                        <th width="15%" class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($totalRows > 0): ?>
                        <?php 
                        $i = $offset + 1; 
                        while($row = $resList->fetch_assoc()): 
                            // [สำคัญ] ดึงสถานะล่าสุด (Current Status) ของ Case ID นี้
                            $cid = $row['case_id'];
                            $qLatest = $conn->query("SELECT * FROM ward_details WHERE case_id = '$cid' ORDER BY id DESC LIMIT 1");
                            $latest = $qLatest->fetch_assoc();
                            
                            $currentWard = $latest['admit_type'];
                            $currentStatus = $latest['status'];
                            $dischargeStatus = $latest['discharge_status'];
                            
                            // จัดการแสดงผล Badge
                            $badgeColor = 'secondary';
                            $statusText = $currentStatus;
                            
                            if ($currentStatus == 'Active') {
                                $badgeColor = 'primary';
                                $statusText = '<i class="fa-solid fa-bed me-1"></i>Admit';
                            } elseif ($currentStatus == 'AdmitWait') {
                                $badgeColor = 'warning text-dark';
                                $statusText = '<i class="fa-solid fa-spinner fa-spin me-1"></i>รอรับ';
                            } elseif ($currentStatus == 'Discharged' || $currentStatus == 'Refer' || $currentStatus == 'Dead') {
                                $statusText = $dischargeStatus;
                                if ($dischargeStatus == 'Discharge') { 
                                    $badgeColor = 'info text-dark'; 
                                    $statusText = '<i class="fa-solid fa-house-user me-1"></i>D/C';
                                } elseif ($dischargeStatus == 'Refer') { 
                                    $badgeColor = 'warning text-dark'; 
                                    $statusText = '<i class="fa-solid fa-ambulance me-1"></i>Refer';
                                } elseif ($dischargeStatus == 'Dead') { 
                                    $badgeColor = 'danger'; 
                                    $statusText = '<i class="fa-solid fa-skull-crossbones me-1"></i>Dead';
                                }
                            }
                        ?>
                        <tr>
                            <td class="text-center fw-bold text-muted"><?php echo $i++; ?></td>
                            <td>
                                <div class="fw-bold"><?php echo date('d/m/Y', strtotime($row['first_admit'])); ?></div>
                                <div class="small text-muted"><?php echo date('H:i', strtotime($row['first_admit'])); ?> น.</div>
                            </td>
                            <td class="fw-bold text-info"><?php echo $row['an']; ?></td>
                            <td class="fw-bold text-secondary"><?php echo $row['hn']; ?></td>
                            <td><div class="fw-bold text-dark"><?php echo $row['patient_name']; ?></div></td>
                            <td><span class="badge bg-light text-dark border"><?php echo $row['person_type']; ?></span></td>
                            <td class="text-center">
                                <span class="badge rounded-pill bg-primary px-3 shadow-sm"><?php echo $currentWard; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $badgeColor; ?> rounded-pill px-3"><?php echo $statusText; ?></span>
                            </td>
                            <td class="text-center">
                                <a href="../view_patient_full_history.php?hn=<?php echo $row['hn']; ?>" class="btn btn-sm btn-outline-success rounded-pill px-3 shadow-sm"><i class="fa-solid fa-file-medical me-1"></i> ประวัติ</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">ไม่พบข้อมูลในช่วงเวลานี้</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-center mt-4">
            <nav>
                <ul class="pagination">
                    <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>&search=<?php echo $search; ?>"><i class="fa-solid fa-chevron-left"></i></a>
                    </li>
                    <?php for($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?php if($page == $p) echo 'active'; ?>">
                        <a class="page-link shadow-sm" href="?page=<?php echo $p; ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>&search=<?php echo $search; ?>"><?php echo $p; ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php if($page >= $totalPages) echo 'disabled'; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>&search=<?php echo $search; ?>"><i class="fa-solid fa-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const admitData = <?php echo json_encode(array_values($chartAdmitData)); ?>;
    const dischData = <?php echo json_encode(array_values($chartDischData)); ?>;
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    new Chart(document.getElementById('admitChart'), {
        type: 'bar',
        data: {
            labels: months,
            datasets: [{
                label: 'จำนวนการเข้ารักษา (คน)',
                data: admitData,
                backgroundColor: 'rgba(75, 192, 192, 0.6)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('dischChart'), {
        type: 'bar',
        data: {
            labels: months,
            datasets: [{
                label: 'จำนวนการส่งออกคนไข้ (คน)',
                data: dischData,
                backgroundColor: 'rgba(13, 110, 253, 0.6)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });
</script>

</body>
</html>