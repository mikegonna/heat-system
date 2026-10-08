<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';
date_default_timezone_set('Asia/Bangkok');

// --- Helper: Class สีตามความรุนแรง ---
function getSeverityClass($dx) {
    if (empty($dx)) return '';
    $severe = ['Heat Rhabdomyolysis', 'Heat Exhaustion', 'Heat Stroke', 'Heat Exertional Rh'];
    foreach ($severe as $k) { if (stripos($dx, $k) !== false) return 'table-danger'; }
    $moderate = ['Heat Rash', 'Heat Edema', 'Heat Cramp', 'Heat Syncope', 'Heat Tetany'];
    foreach ($moderate as $k) { if (stripos($dx, $k) !== false) return 'table-warning'; }
    return '';
}

// ... (ส่วน SQL เตรียมข้อมูลกราฟ Overview ด้านบน คงเดิม ไม่ต้องแก้) ...
// 1. Ward Counts
$wardCounts = ['ICU' => 0, 'RCU' => 0, 'A4' => 0, 'B3' => 0];
$resWard = $conn->query("SELECT admit_type, COUNT(*) as count FROM ward_details WHERE status = 'Active' GROUP BY admit_type");
while($row = $resWard->fetch_assoc()) {
    $name = $row['admit_type'];
    if (stripos($name, 'ICU') !== false) $wardCounts['ICU'] += $row['count'];
    elseif (stripos($name, 'RCU') !== false) $wardCounts['RCU'] += $row['count'];
    elseif (stripos($name, 'A4') !== false) $wardCounts['A4'] += $row['count'];
    elseif (stripos($name, 'B3') !== false) $wardCounts['B3'] += $row['count'];
}
$totalActive = array_sum($wardCounts);

// 2. Person Type
$typeStats = ['ทหารกองประจำการ' => 0, 'กำลังพล ทบ.' => 0, 'นอก ทบ.' => 0, 'อื่นๆ' => 0];
$resType = $conn->query("SELECT c.person_type, COUNT(*) as count FROM ward_details w JOIN cases c ON w.case_id = c.id WHERE w.status = 'Active' GROUP BY c.person_type");
while($row = $resType->fetch_assoc()) {
    $t = trim($row['person_type']);
    if (isset($typeStats[$t])) $typeStats[$t] += $row['count']; else $typeStats['อื่นๆ'] += $row['count'];
}

// 3. Outcomes
$outcomeStats = ['Discharge' => 0, 'Refer' => 0, 'Dead' => 0];
$resOutcomeW = $conn->query("SELECT discharge_status, COUNT(*) as count FROM ward_details WHERE discharge_date IS NOT NULL GROUP BY discharge_status");
while($row = $resOutcomeW->fetch_assoc()) {
    $st = $row['discharge_status'];
    if (stripos($st, 'Discharge') !== false) $outcomeStats['Discharge'] += $row['count'];
    elseif (stripos($st, 'Refer') !== false) $outcomeStats['Refer'] += $row['count'];
    elseif (stripos($st, 'Dead') !== false) $outcomeStats['Dead'] += $row['count'];
}
$resOutcomeER = $conn->query("SELECT COUNT(*) as count FROM er_details WHERE disposition = 'Dead'");
$outcomeStats['Dead'] += $resOutcomeER->fetch_assoc()['count'];

// 4. Severity
$resSev = $conn->query("SELECT final_diagnosis FROM ward_details WHERE final_diagnosis IS NOT NULL AND final_diagnosis != ''");
$sevStats = ['Severe' => 0, 'Moderate' => 0, 'Mild' => 0];
$totalDx = 0;
$severeKeys = ['Heat Rhabdomyolysis', 'Heat Exhaustion', 'Heat Stroke', 'Heat Exertional Rh'];
$modKeys = ['Heat Rash', 'Heat Edema', 'Heat Cramp', 'Heat Syncope', 'Heat Tetany'];
while($row = $resSev->fetch_assoc()) {
    $dx = trim($row['final_diagnosis']);
    if(empty($dx)) continue;
    $isSevere = false; $isMod = false; 
    foreach($severeKeys as $k) { if(stripos($dx, $k) !== false) { $sevStats['Severe']++; $isSevere = true; break; } }
    if(!$isSevere) { foreach($modKeys as $k) { if(stripos($dx, $k) !== false) { $sevStats['Moderate']++; $isMod = true; break; } } }
    if(!$isSevere && !$isMod) { $sevStats['Mild']++; }
    $totalDx++;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>PCT Dashboard</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f0f2f5; }
        h1, h2, h3, h4, h5, .nav-link, .modal-title, .card-title { font-family: 'Prompt', sans-serif; }
        .stat-card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .icon-circle { width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .progress-thin { height: 8px; border-radius: 4px; }
        .nav-pills .nav-link { color: #555; background: #fff; border: 1px solid #dee2e6; margin-right: 5px; border-radius: 8px; }
        .nav-pills .nav-link.active { background-color: #0d6efd; color: white; border-color: #0d6efd; box-shadow: 0 4px 6px rgba(13,110,253,0.3); }
        .table-hover tbody tr:hover { background-color: rgba(0,0,0,0.02); }
        .btn-action { border-radius: 20px; padding: 5px 15px; font-size: 0.85rem; }
    </style>
</head>
<body>

<?php 
    // Header
    if(file_exists('../header_pct.php')) { require_once '../header_pct.php'; } 
    elseif(file_exists('../header_ward.php')) { require_once '../header_ward.php'; }
?>

<div class="container-fluid py-4 px-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-primary mb-0"><i class="fa-solid fa-chart-pie me-2"></i>PCT Dashboard</h4>
            <p class="text-muted small mb-0">ศูนย์ปฏิบัติการข้อมูลผู้ป่วย Heat Stroke</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()"><i class="fa-solid fa-rotate"></i> Refresh</button>
    </div>

    <ul class="nav nav-pills mb-4" id="pctTab" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview"><i class="fa-solid fa-gauge-high me-2"></i>ภาพรวม (Overview)</button></li>
        <li class="nav-item ms-auto border-start mx-2"></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-er">ER</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ward">Ward</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-icu">ICU</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-visit">เยี่ยมบ้าน</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-med">MED</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pmr">PMR</button></li>
    </ul>

    <div class="tab-content" id="pctTabContent">
        
        <div class="tab-pane fade show active" id="tab-overview">
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6"><div class="card stat-card bg-white border-bottom border-4 border-danger"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted small fw-bold mb-1">Ward ICU</h6><h2 class="mb-0 fw-bold text-danger"><?php echo $wardCounts['ICU']; ?></h2></div><div class="icon-circle bg-danger bg-opacity-10 text-danger"><i class="fa-solid fa-heart-pulse"></i></div></div></div></div></div>
                <div class="col-md-3 col-6"><div class="card stat-card bg-white border-bottom border-4 border-warning"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted small fw-bold mb-1">Ward RCU</h6><h2 class="mb-0 fw-bold text-warning"><?php echo $wardCounts['RCU']; ?></h2></div><div class="icon-circle bg-warning bg-opacity-10 text-warning"><i class="fa-solid fa-lungs"></i></div></div></div></div></div>
                <div class="col-md-3 col-6"><div class="card stat-card bg-white border-bottom border-4 border-primary"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted small fw-bold mb-1">Ward A4</h6><h2 class="mb-0 fw-bold text-primary"><?php echo $wardCounts['A4']; ?></h2></div><div class="icon-circle bg-primary bg-opacity-10 text-primary"><i class="fa-solid fa-user-nurse"></i></div></div></div></div></div>
                <div class="col-md-3 col-6"><div class="card stat-card bg-white border-bottom border-4 border-success"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted small fw-bold mb-1">Ward B3</h6><h2 class="mb-0 fw-bold text-success"><?php echo $wardCounts['B3']; ?></h2></div><div class="icon-circle bg-success bg-opacity-10 text-success"><i class="fa-solid fa-hospital-user"></i></div></div></div></div></div>
            </div>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="card stat-card h-100"><div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold text-dark"><i class="fa-solid fa-users me-2"></i>ประเภทผู้ป่วย</h6></div><div class="card-body pt-0"><ul class="list-group list-group-flush"><?php $types = [['name'=>'ทหารกองประจำการ','color'=>'success'],['name'=>'กำลังพล ทบ.','color'=>'primary'],['name'=>'นอก ทบ.','color'=>'secondary'],['name'=>'อื่นๆ','color'=>'info']]; foreach($types as $t) { $cnt = $typeStats[$t['name']]; $pct = ($totalActive>0)?($cnt/$totalActive)*100:0; echo "<li class='list-group-item border-0 px-0'><div class='d-flex justify-content-between mb-1'><span>{$t['name']}</span><span class='fw-bold'>$cnt</span></div><div class='progress progress-thin'><div class='progress-bar bg-{$t['color']}' style='width: $pct%'></div></div></li>"; } ?></ul></div></div>
                </div>
                <div class="col-lg-4">
                    <div class="card stat-card h-100"><div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold text-dark"><i class="fa-solid fa-clipboard-check me-2"></i>สรุปผล (สะสม)</h6></div><div class="card-body d-flex flex-column gap-3 pt-0"><div class="d-flex align-items-center bg-light p-3 rounded"><div class="icon-circle bg-success text-white me-3"><i class="fa-solid fa-house-chimney"></i></div><h6 class="mb-0 flex-grow-1 fw-bold">กลับบ้าน</h6><h4 class="mb-0 fw-bold text-success"><?php echo $outcomeStats['Discharge']; ?></h4></div><div class="d-flex align-items-center bg-light p-3 rounded"><div class="icon-circle bg-warning text-dark me-3"><i class="fa-solid fa-truck-medical"></i></div><h6 class="mb-0 flex-grow-1 fw-bold">ส่งต่อ</h6><h4 class="mb-0 fw-bold text-warning"><?php echo $outcomeStats['Refer']; ?></h4></div><div class="d-flex align-items-center bg-light p-3 rounded"><div class="icon-circle bg-dark text-white me-3"><i class="fa-solid fa-skull"></i></div><h6 class="mb-0 flex-grow-1 fw-bold">เสียชีวิต</h6><h4 class="mb-0 fw-bold text-dark"><?php echo $outcomeStats['Dead']; ?></h4></div></div></div>
                </div>
                <div class="col-lg-4">
                    <div class="card stat-card h-100"><div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold text-dark"><i class="fa-solid fa-temperature-full me-2"></i>ระดับความรุนแรง (Final Dx)</h6></div><div class="card-body pt-0"><?php $levels = [['l'=>'Severe','v'=>$sevStats['Severe'],'c'=>'danger'],['l'=>'Moderate','v'=>$sevStats['Moderate'],'c'=>'warning'],['l'=>'Mild','v'=>$sevStats['Mild'],'c'=>'success']]; foreach($levels as $l) { $pct = ($totalDx>0)?($l['v']/$totalDx)*100:0; echo "<div class='mb-3'><div class='d-flex justify-content-between mb-1'><span class='text-{$l['c']} fw-bold'>{$l['l']}</span><b>{$l['v']}</b></div><div class='progress progress-thin'><div class='progress-bar bg-{$l['c']}' style='width: $pct%'></div></div></div>"; } ?></div></div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-er">
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light"><tr><th>วันที่</th><th>HN</th><th>ผู้ป่วย</th><th>อาการ (FstDx)</th><th>จัดการ</th></tr></thead>
                            <tbody>
                                <?php
                                $sql = "SELECT e.*, c.hn, c.patient_name FROM er_details e JOIN cases c ON e.case_id = c.id WHERE e.id IN (SELECT MAX(id) FROM er_details GROUP BY case_id) ORDER BY e.recorded_at DESC";
                                $res = $conn->query($sql);
                                while($row = $res->fetch_assoc()):
                                ?>
                                <tr class="<?php echo getSeverityClass($row['FstDx']); ?>">
                                    <td><?php echo date('d/m/Y H:i', strtotime($row['recorded_at'])); ?></td>
                                    <td><span class="fw-bold text-primary"><?php echo $row['hn']; ?></span></td>
                                    <td><?php echo $row['patient_name']; ?></td>
                                    <td><?php echo $row['FstDx']; ?></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm rounded-pill" onclick="showDetails('ER', '<?php echo $row['hn']; ?>')"><i class="fa-solid fa-list-ul"></i> ประวัติ</button>
                                        <a href="../ER/edit_treatmentER.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm rounded-pill text-dark"><i class="fa-solid fa-pen"></i> แก้ไข</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-ward">
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-header bg-white py-3">
                    <div class="row align-items-center">
                        <div class="col-auto"><label class="fw-bold">เลือกหอผู้ป่วย:</label></div>
                        <div class="col-auto">
                            <select class="form-select w-auto shadow-sm border-primary" id="wardFilter" onchange="filterWardTable()">
                                <option value="all">ทั้งหมด (General Wards)</option>
                                <option value="Ward A4">Ward A4</option>
                                <option value="Ward B3">Ward B3</option>
                                <option value="Ward RCU">Ward RCU</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tableWard">
                            <thead class="bg-light"><tr><th>วันที่รับ</th><th>HN</th><th>ผู้ป่วย</th><th>แผนก</th><th>วินิจฉัย</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                            <tbody>
                                <?php
                                $sql = "SELECT w.*, c.hn, c.patient_name FROM ward_details w JOIN cases c ON w.case_id = c.id WHERE w.admit_type LIKE 'Ward%' AND w.admit_type NOT LIKE '%ICU%' AND w.id IN (SELECT MAX(id) FROM ward_details WHERE admit_type LIKE 'Ward%' AND admit_type NOT LIKE '%ICU%' GROUP BY case_id) ORDER BY w.admit_date DESC";
                                $res = $conn->query($sql);
                                while($row = $res->fetch_assoc()):
                                ?>
                                <tr class="ward-row" data-ward="<?php echo $row['admit_type']; ?>">
                                    <td><?php echo date('d/m/Y', strtotime($row['admit_date'])); ?></td>
                                    <td><?php echo $row['hn']; ?></td>
                                    <td><?php echo $row['patient_name']; ?></td>
                                    <td><span class="badge bg-success"><?php echo $row['admit_type']; ?></span></td>
                                    <td><?php echo $row['initial_diagnosis']; ?></td>
                                    <td><span class="badge bg-warning text-dark">Admit</span></td>
                                    <td>
                                        <button class="btn btn-success btn-sm rounded-pill" onclick="showDetails('WARD', '<?php echo $row['hn']; ?>')"><i class="fa-solid fa-list-ul"></i> ประวัติ</button>
                                        <a href="../Ward/edit_treatmentWard.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm rounded-pill text-dark"><i class="fa-solid fa-pen"></i> แก้ไข</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-icu">
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light"><tr><th>วันที่รับ</th><th>HN</th><th>ผู้ป่วย</th><th>Temp</th><th>วินิจฉัย</th><th>จัดการ</th></tr></thead>
                            <tbody>
                                <?php
                                $sql = "SELECT w.*, c.hn, c.patient_name FROM ward_details w JOIN cases c ON w.case_id = c.id WHERE w.admit_type LIKE '%ICU%' AND w.id IN (SELECT MAX(id) FROM ward_details WHERE admit_type LIKE '%ICU%' GROUP BY case_id) ORDER BY w.admit_date DESC";
                                $res = $conn->query($sql);
                                while($row = $res->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($row['admit_date'])); ?></td>
                                    <td><?php echo $row['hn']; ?></td>
                                    <td><?php echo $row['patient_name']; ?></td>
                                    <td class="text-danger fw-bold"><?php echo $row['icu_core_temp_first']; ?> °C</td>
                                    <td><?php echo $row['icu_diagnosis']; ?></td>
                                    <td>
                                        <button class="btn btn-info btn-sm rounded-pill text-white" onclick="showDetails('ICU', '<?php echo $row['hn']; ?>')"><i class="fa-solid fa-list-ul"></i> ประวัติ</button>
                                        <a href="../Ward/edit_treatmentWard.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm rounded-pill text-dark"><i class="fa-solid fa-pen"></i> แก้ไข</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-visit">
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light"><tr><th>วันที่เยี่ยม</th><th>HN</th><th>ผู้ป่วย</th><th>อาการ/ผลการเยี่ยม</th><th>ผู้บันทึก</th><th>จัดการ</th></tr></thead>
                            <tbody>
                                <?php
                                $sql = "SELECT h.*, c.patient_name, c.hn FROM home_visits h JOIN cases c ON h.hn = c.hn ORDER BY h.record_datetime DESC";
                                if($conn->query("SHOW TABLES LIKE 'home_visits'")->num_rows > 0) {
                                    $res = $conn->query($sql);
                                    while($row = $res->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($row['record_datetime'])); ?></td>
                                    <td><?php echo $row['hn']; ?></td>
                                    <td><?php echo $row['patient_name']; ?></td>
                                    <td><?php echo $row['symptoms']; ?></td>
                                    <td><?php echo $row['recorder_name']; ?></td>
                                    <td>
                                        <button class="btn btn-secondary btn-sm rounded-pill" onclick="showDetails('VISIT', '<?php echo $row['hn']; ?>')"><i class="fa-solid fa-file-lines"></i> ดู</button>
                                        <a href="../Visit/edit_visit.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm rounded-pill text-dark"><i class="fa-solid fa-pen"></i> แก้ไข</a>
                                    </td>
                                </tr>
                                <?php endwhile; } else { echo "<tr><td colspan='6' class='text-center text-muted'>- ไม่พบข้อมูล -</td></tr>"; } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-med">
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light"><tr><th>วันที่บันทึก</th><th>HN</th><th>ผู้ป่วย</th><th>สถานะ MED</th><th>นัดครั้งถัดไป</th><th>จัดการ</th></tr></thead>
                            <tbody>
                                <?php
                                $sql = "SELECT m.*, c.patient_name, c.hn FROM med_records m JOIN cases c ON m.hn = c.hn ORDER BY m.record_datetime DESC";
                                if($conn->query("SHOW TABLES LIKE 'med_records'")->num_rows > 0) {
                                    $res = $conn->query($sql);
                                    while($row = $res->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($row['record_datetime'])); ?></td>
                                    <td><?php echo $row['hn']; ?></td>
                                    <td><?php echo $row['patient_name']; ?></td>
                                    <td><span class="badge bg-info text-dark"><?php echo $row['med_status']; ?></span></td>
                                    <td><?php echo $row['next_med_date'] ? date('d/m/Y', strtotime($row['next_med_date'])) : '-'; ?></td>
                                    <td>
                                        <button class="btn btn-info text-white btn-sm rounded-pill" onclick="showDetails('MED', '<?php echo $row['hn']; ?>')"><i class="fa-solid fa-notes-medical"></i> ดู</button>
                                        <a href="../MED/edit_med.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm rounded-pill text-dark"><i class="fa-solid fa-pen"></i> แก้ไข</a>
                                    </td>
                                </tr>
                                <?php endwhile; } else { echo "<tr><td colspan='6' class='text-center text-muted'>- ไม่พบข้อมูล -</td></tr>"; } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-pmr">
            <div class="card border-0 shadow-sm mt-2">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light"><tr><th>วันที่บันทึก</th><th>HN</th><th>ผู้ป่วย</th><th>สถานะ PMR</th><th>นัดครั้งถัดไป</th><th>จัดการ</th></tr></thead>
                            <tbody>
                                <?php
                                $sql = "SELECT p.*, c.patient_name, c.hn FROM pmr_records p JOIN cases c ON p.hn = c.hn ORDER BY p.record_datetime DESC";
                                if($conn->query("SHOW TABLES LIKE 'pmr_records'")->num_rows > 0) {
                                    $res = $conn->query($sql);
                                    while($row = $res->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($row['record_datetime'])); ?></td>
                                    <td><?php echo $row['hn']; ?></td>
                                    <td><?php echo $row['patient_name']; ?></td>
                                    <td><span class="badge bg-warning text-dark"><?php echo $row['pmr_status']; ?></span></td>
                                    <td><?php echo $row['next_pmr_date'] ? date('d/m/Y', strtotime($row['next_pmr_date'])) : '-'; ?></td>
                                    <td>
                                        <button class="btn btn-warning btn-sm rounded-pill" onclick="showDetails('PMR', '<?php echo $row['hn']; ?>')"><i class="fa-solid fa-person-walking"></i> ดู</button>
                                        <a href="../PMR/edit_pmr.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm rounded-pill text-dark"><i class="fa-solid fa-pen"></i> แก้ไข</a>
                                    </td>
                                </tr>
                                <?php endwhile; } else { echo "<tr><td colspan='6' class='text-center text-muted'>- ไม่พบข้อมูล -</td></tr>"; } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">รายละเอียดการรักษา</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light" id="modalContent">
                <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
            </div>
        </div>
    </div>
</div>
<script>
    function filterWardTable() {
        var filter = document.getElementById("wardFilter").value;
        var rows = document.querySelectorAll("#tableWard tbody tr");
        rows.forEach(row => {
            var wardName = row.getAttribute("data-ward");
            if (filter === "all" || wardName.includes(filter)) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });
    }

    function showDetails(type, hn) {
        var modal = new bootstrap.Modal(document.getElementById('detailModal'));
        document.getElementById('modalTitle').innerText = 'ประวัติ: ' + type + ' (HN: ' + hn + ')';
        modal.show();

        var contentDiv = document.getElementById('modalContent');
        contentDiv.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p>กำลังโหลดข้อมูล...</p></div>';

        fetch('get_pct_details.php?hn=' + hn + '&type=' + type)
            .then(response => response.text())
            .then(html => {
                contentDiv.innerHTML = html;
            })
            .catch(err => {
                contentDiv.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาด: ' + err + '</div>';
            });
    }
</script>

</body>
</html>