<?php
session_start();
require_once 'database/db_conn.php'; 

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'viewer' && $_SESSION['role'] !== 'admin')) {
    header("Location: login.php");
    exit();
}

// ==================================================================
// ส่วนที่ 1: การดึงข้อมูล (Data Fetching)
// ==================================================================

// 1.1 ผู้ป่วยรายวัน (วันนี้)
$sql_daily = "SELECT COUNT(*) as count FROM cases WHERE DATE(created_at) = CURDATE()";
$res_daily = $conn->query($sql_daily);
$daily_count = $res_daily->fetch_assoc()['count'];

// 1.2 ผู้ป่วยสะสมทั้งหมด
$sql_total = "SELECT COUNT(*) as count FROM cases";
$res_total = $conn->query($sql_total);
$total_count = $res_total->fetch_assoc()['count'];

// 1.3 ผู้ป่วยรายวัน แยกตามประเภทบุคคล
$daily_types = ['ทหารกองประจำการ' => 0, 'กำลังพล ทบ.' => 0, 'นอก ทบ.' => 0, 'อื่นๆ' => 0];
$sql_daily_type = "SELECT person_type, COUNT(*) as count FROM cases WHERE DATE(created_at) = CURDATE() GROUP BY person_type";
$res_daily_type = $conn->query($sql_daily_type);
if ($res_daily_type) {
    while ($row = $res_daily_type->fetch_assoc()) {
        $type = trim($row['person_type']);
        if (array_key_exists($type, $daily_types)) {
            $daily_types[$type] = $row['count'];
        } else {
            $daily_types['อื่นๆ'] += $row['count'];
        }
    }
}

// 1.4 แยกความรุนแรง (Severity Diagnosis)
$severe_diag = ['Heat Rhabdomyolysis', 'Heat Exhaustion', 'Heat Exertional Rh', 'Heat Stroke'];
$moderate_diag = ['Heat Rash', 'Heat Edema', 'Heat Cramp', 'Heat Syncope', 'Heat Tetany'];

$severity_data = [
    'Severe'   => ['count' => 0, 'diseases' => []],
    'Moderate' => ['count' => 0, 'diseases' => []],
    'Mild'     => ['count' => 0, 'diseases' => []]
];

$sql_severity = "SELECT final_diagnosis, COUNT(*) as d_count 
                 FROM ward_details 
                 WHERE final_diagnosis IS NOT NULL AND final_diagnosis != ''
                 GROUP BY final_diagnosis";
$res_severity = $conn->query($sql_severity);

$total_diag_count = 0;

if ($res_severity) {
    while ($row = $res_severity->fetch_assoc()) {
        $d = trim($row['final_diagnosis']);
        $c = intval($row['d_count']);
        
        $group = 'Mild'; 
        if (in_array($d, $severe_diag)) {
            $group = 'Severe';
        } elseif (in_array($d, $moderate_diag)) {
            $group = 'Moderate';
        }
        
        $severity_data[$group]['count'] += $c;
        $severity_data[$group]['diseases'][$d] = $c; 
        $total_diag_count += $c;
    }
}

// 1.5 จำนวนผู้ป่วย Active แยกตามสถานที่
$active_wards = ['A4' => 0, 'B3' => 0, 'ICU' => 0, 'RCU' => 0, 'Admit' => 0];

$sql_active = "SELECT admit_type, COUNT(*) as count 
               FROM ward_details 
               WHERE status = 'Active' 
               GROUP BY admit_type";
$res_active = $conn->query($sql_active);

if ($res_active) {
    while ($row = $res_active->fetch_assoc()) {
        $loc = trim($row['admit_type']);
        $matched = false;
        foreach ($active_wards as $key => $val) {
            if (stripos($loc, $key) !== false) { 
                $active_wards[$key] += $row['count'];
                $matched = true;
                break; 
            }
        }
        if (!$matched) {
            $active_wards['Admit'] += $row['count'];
        }
    }
}

// ==================================================================
// ส่วนที่ 2: ดึงข้อมูลตารางต่างๆ (Report Tables)
// ==================================================================

// 2.1 [แก้ไขใหม่] Progress Note List (ดึงรายเคส Admit แทนราย Note)
// ใช้ SQL แบบเดียวกับ view_progress_notes.php
$sql_notes = "SELECT w.id as ward_id, c.hn, c.patient_name, w.admit_date, 
                     w.an, w.admit_type, w.status
              FROM ward_details w
              JOIN cases c ON w.case_id = c.id
              ORDER BY w.admit_date DESC LIMIT 50";
$result_notes = $conn->query($sql_notes);

// 2.2 เยี่ยมบ้าน
$sql_visit = "SELECT h.*, c.patient_name 
              FROM home_visits h 
              LEFT JOIN cases c ON h.hn = c.hn 
              ORDER BY h.record_datetime DESC LIMIT 50";
$result_visit = $conn->query($sql_visit);

// 2.3 กายภาพ
$sql_rehab = "SELECT r.*, c.patient_name 
              FROM rehab_records r 
              LEFT JOIN cases c ON r.hn = c.hn 
              ORDER BY r.record_datetime DESC LIMIT 50";
$result_rehab = $conn->query($sql_rehab);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Dashboard ผู้บริหาร - รพ.อานันทมหิดล</title>
    <link href="bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #0c784b;
            --bg-light: #f4f6f9;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: var(--bg-light);
        }
        h1, h2, h3, h4, h5, .nav-link, .modal-title {
            font-family: 'Prompt', sans-serif;
        }
        
        .navbar-custom { background-color: var(--primary-color); box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        
        /* Stats Cards */
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            background: #fff;
            height: 100%;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon {
            width: 50px; height: 50px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 24px;
        }
        .bg-daily { background-color: #e3f2fd; color: #1976d2; }
        .bg-total { background-color: #e8f5e9; color: #2e7d32; }
        .bg-ward { background-color: #fff3e0; color: #ef6c00; }

        /* Severity Cards */
        .severity-card {
            border: none; border-radius: 16px; background: #fff;
            box-shadow: 0 10px 20px rgba(0,0,0,0.05); overflow: hidden; height: 100%;
            transition: all 0.3s ease;
        }
        .severity-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); }
        .sev-header {
            padding: 12px 15px; color: white; font-weight: 600;
            display: flex; justify-content: space-between; align-items: center;
        }
        .bg-gradient-severe { background: linear-gradient(135deg, #FF512F 0%, #DD2476 100%); }
        .bg-gradient-mod    { background: linear-gradient(135deg, #F09819 0%, #EDDE5D 100%); color: #444; }
        .bg-gradient-mild   { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        
        .disease-pill {
            display: inline-flex; justify-content: space-between; align-items: center;
            padding: 6px 12px; margin: 4px; border-radius: 50px; font-size: 0.85rem;
            background-color: #f8f9fa; border: 1px solid #e9ecef; color: #555;
            transition: background 0.2s;
        }
        .disease-pill:hover { background-color: #e9ecef; }
        .disease-count {
            background: #fff; padding: 2px 8px; border-radius: 12px;
            font-weight: bold; font-size: 0.75rem; margin-left: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .progress-slim { height: 6px; border-radius: 3px; background: rgba(0,0,0,0.05); margin-top: 5px; }
        
        /* Tables & Content */
        .content-box {
            background: white; border-radius: 12px; padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05); min-height: 400px;
        }
        .nav-tabs .nav-link { color: #6c757d; border: none; padding: 12px 20px; font-weight: 500; }
        .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 3px solid var(--primary-color); background: transparent; }
        .table thead th { background-color: #f8f9fa; color: #495057; font-weight: 600; border-bottom: 2px solid #e9ecef; }
        .ward-badge { font-size: 1.1em; font-weight: bold; }
        
        /* Modal */
        .modal-header { background-color: var(--primary-color); color: white; }
        .modal-body { background-color: #f8f9fa; }

    </style>
</head>
<body>
    <?php $path_prefix = ""; require_once 'header.php';?>
    
    <div class="container-fluid px-4 py-4">
        
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="text-muted mb-1">ผู้ป่วยรายใหม่ (วันนี้)</h6>
                            <h2 class="mb-0 fw-bold"><?php echo $daily_count; ?></h2>
                        </div>
                        <div class="stat-icon bg-daily"><i class="fa-solid fa-user-plus"></i></div>
                    </div>
                    <div class="mt-2 border-top pt-2">
                        <small class="text-muted fw-bold d-block mb-1">แยกตามประเภท:</small>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach($daily_types as $type => $count): ?>
                                <li class="d-flex justify-content-between py-1 border-bottom-dashed">
                                    <span><?php echo $type; ?></span>
                                    <span class="fw-bold text-primary"><?php echo $count; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-md-5 mb-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h6 class="text-muted mb-1">ผู้ป่วย Active (Admit)</h6>
                            <small class="text-muted">แยกตามแผนก/แผนก</small>
                        </div>
                        <div class="stat-icon bg-ward"><i class="fa-solid fa-bed-pulse"></i></div>
                    </div>
                    <div class="row g-2 text-center mt-2">
                        <?php foreach($active_wards as $ward => $count): ?>
                        <div class="col">
                            <div class="border rounded p-2 bg-light h-100">
                                <small class="d-block text-muted mb-1" style="font-size:0.75rem;"><?php echo $ward; ?></small>
                                <span class="ward-badge text-primary"><?php echo $count; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card stat-card p-3 d-flex flex-column justify-content-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">ผู้ป่วยสะสมทั้งหมด</h6>
                            <h2 class="mb-0 fw-bold"><?php echo $total_count; ?></h2>
                        </div>
                        <div class="stat-icon bg-total"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div class="mt-3 text-center text-muted small">
                        <i class="fa-regular fa-calendar-check me-1"></i> ข้อมูลตั้งแต่เริ่มระบบ
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <h5 class="text-secondary fw-bold mb-3 ms-1"><i class="fa-solid fa-notes-medical me-2"></i> สรุปความรุนแรงตามผลวินิจฉัย</h5>
            </div>
            
            <div class="col-md-4 mb-3">
                <div class="severity-card border">
                    <div class="sev-header bg-gradient-severe">
                        <span><i class="fa-solid fa-triangle-exclamation me-2"></i>Severe (รุนแรง)</span>
                        <span class="badge bg-white text-danger rounded-pill shadow-sm"><?php echo $severity_data['Severe']['count']; ?> ราย</span>
                    </div>
                    <div class="p-3">
                        <?php $pct = ($total_diag_count > 0) ? ($severity_data['Severe']['count'] / $total_diag_count) * 100 : 0; ?>
                        <div class="d-flex justify-content-between small text-muted mb-1"><span>สัดส่วน</span><span><?php echo number_format($pct, 1); ?>%</span></div>
                        <div class="progress progress-slim mb-3"><div class="progress-bar bg-danger" style="width: <?php echo $pct; ?>%"></div></div>
                        <div class="d-flex flex-wrap">
                            <?php foreach($severity_data['Severe']['diseases'] as $name => $num): ?>
                                <div class="disease-pill"><?php echo $name; ?><span class="disease-count text-danger"><?php echo $num; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="severity-card border">
                    <div class="sev-header bg-gradient-mod">
                        <span style="color:#5a4a00"><i class="fa-solid fa-temperature-half me-2"></i>Moderate (ปานกลาง)</span>
                        <span class="badge bg-white text-warning rounded-pill shadow-sm" style="color:#b48608"><?php echo $severity_data['Moderate']['count']; ?> ราย</span>
                    </div>
                    <div class="p-3">
                        <?php $pct = ($total_diag_count > 0) ? ($severity_data['Moderate']['count'] / $total_diag_count) * 100 : 0; ?>
                        <div class="d-flex justify-content-between small text-muted mb-1"><span>สัดส่วน</span><span><?php echo number_format($pct, 1); ?>%</span></div>
                        <div class="progress progress-slim mb-3"><div class="progress-bar bg-warning" style="width: <?php echo $pct; ?>%"></div></div>
                        <div class="d-flex flex-wrap">
                            <?php foreach($severity_data['Moderate']['diseases'] as $name => $num): ?>
                                <div class="disease-pill"><?php echo $name; ?><span class="disease-count text-warning"><?php echo $num; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="severity-card border">
                    <div class="sev-header bg-gradient-mild">
                        <span><i class="fa-solid fa-leaf me-2"></i>Mild (เล็กน้อย/อื่นๆ)</span>
                        <span class="badge bg-white text-success rounded-pill shadow-sm"><?php echo $severity_data['Mild']['count']; ?> ราย</span>
                    </div>
                    <div class="p-3">
                        <?php $pct = ($total_diag_count > 0) ? ($severity_data['Mild']['count'] / $total_diag_count) * 100 : 0; ?>
                        <div class="d-flex justify-content-between small text-muted mb-1"><span>สัดส่วน</span><span><?php echo number_format($pct, 1); ?>%</span></div>
                        <div class="progress progress-slim mb-3"><div class="progress-bar bg-success" style="width: <?php echo $pct; ?>%"></div></div>
                        <div class="d-flex flex-wrap">
                            <?php foreach($severity_data['Mild']['diseases'] as $name => $num): ?>
                                <div class="disease-pill"><?php echo $name; ?><span class="disease-count text-success"><?php echo $num; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div> 

        <div class="content-box">
            <h4 class="mb-4 text-secondary"><i class="fa-solid fa-table-list me-2"></i>รายงานข้อมูลการรักษา (ล่าสุด 50 รายการ)</h4>
            
            <ul class="nav nav-tabs mb-3" id="reportTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="note-tab" data-bs-toggle="tab" data-bs-target="#note" type="button" role="tab"><i class="fa-solid fa-notes-medical me-2"></i>Progress Note</button></li>
                <li class="nav-item"><button class="nav-link" id="visit-tab" data-bs-toggle="tab" data-bs-target="#visit" type="button" role="tab"><i class="fa-solid fa-house-medical me-2"></i>เยี่ยมบ้าน</button></li>
                <li class="nav-item"><button class="nav-link" id="rehab-tab" data-bs-toggle="tab" data-bs-target="#rehab" type="button" role="tab"><i class="fa-solid fa-person-walking me-2"></i>กายภาพบำบัด</button></li>
            </ul>

            <div class="tab-content" id="reportTabsContent">
                
                <div class="tab-pane fade show active" id="note" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th width="15%">วันที่รับเข้า</th>
                                    <th width="15%">AN / HN</th>
                                    <th width="25%">ชื่อ-สกุล</th>
                                    <th width="20%">ประเภท Admit</th>
                                    <th width="10%">สถานะ</th>
                                    <th width="15%" class="text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($result_notes->num_rows > 0): ?>
                                    <?php while($row = $result_notes->fetch_assoc()): ?>
                                    <?php 
                                        $statusBadge = ($row['status'] == 'Active') 
                                            ? '<span class="badge bg-success bg-opacity-10 text-light border border-success px-3">Active</span>' 
                                            : '<span class="badge bg-secondary bg-opacity-10 text-light border px-3">จำหน่าย</span>';
                                    ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($row['admit_date'])); ?></td>
                                        <td>
                                            <div class="fw-bold text-primary fs-6">AN: <?php echo htmlspecialchars($row['an']); ?></div>
                                            <div class="text-muted small">HN: <?php echo htmlspecialchars($row['hn']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['admit_type']); ?></div>
                                        </td>
                                        <td><?php echo $statusBadge; ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm" onclick="openNoteModal(<?php echo $row['ward_id']; ?>)">
                                                <i class="fa-solid fa-eye me-1"></i> ดูประวัติ
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">ไม่พบข้อมูล Progress Note</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="visit" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>วันที่บันทึก</th>
                                    <th>HN</th>
                                    <th>ชื่อ-สกุล</th>
                                    <th>อุณหภูมิ (°C)</th>
                                    <th>สีปัสสาวะ</th>
                                    <th>อาการ/รายละเอียด</th>
                                    <th>นัดครั้งถัดไป</th>
                                    <th>ผู้บันทึก</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($result_visit->num_rows > 0): ?>
                                    <?php while($row = $result_visit->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($row['record_datetime'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['hn']); ?></td>
                                        <td><?php echo htmlspecialchars($row['patient_name'] ?? '-'); ?></td>
                                        <td class="fw-bold text-danger"><?php echo htmlspecialchars($row['body_temperature']); ?></td>
                                        <td><?php echo htmlspecialchars($row['urine_color']); ?></td>
                                        <td><?php echo htmlspecialchars($row['symptoms'] . ' ' . $row['symptoms_other']); ?></td>
                                        <td><?php echo ($row['next_visit_date']) ? date('d/m/Y', strtotime($row['next_visit_date'])) : '-'; ?></td>
                                        <td><?php echo htmlspecialchars($row['recorder_name']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">ไม่พบข้อมูลการเยี่ยมบ้าน</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="rehab" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>วันที่บันทึก</th>
                                    <th>HN</th>
                                    <th>ชื่อ-สกุล</th>
                                    <th>สถานะการรักษา</th>
                                    <th>บันทึกเพิ่มเติม</th>
                                    <th>นัดกายภาพถัดไป</th>
                                    <th>ผู้บันทึก</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($result_rehab->num_rows > 0): ?>
                                    <?php while($row = $result_rehab->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($row['record_datetime'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['hn']); ?></td>
                                        <td><?php echo htmlspecialchars($row['patient_name'] ?? '-'); ?></td>
                                        <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($row['rehab_status']); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['rehab_note']); ?></td>
                                        <td><?php echo ($row['next_rehab_date']) ? date('d/m/Y', strtotime($row['next_rehab_date'])) : '-'; ?></td>
                                        <td><?php echo htmlspecialchars($row['recorder_name']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center py-4 text-muted">ไม่พบข้อมูลกายภาพบำบัด</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div> 
        </div> 
    </div> 

    <div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title fs-6"><i class="fa-solid fa-file-medical me-2"></i>ประวัติการรักษา (Progress Note History)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalContent">
                    </div>
            </div>
        </div>
    </div>

    <?php $path_prefix = ""; require_once 'footer.php';?>
    <script src="bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function openNoteModal(wardId) {
            var myModal = new bootstrap.Modal(document.getElementById('noteModal'));
            myModal.show();
            
            // แสดง Loading ระหว่างรอข้อมูล
            document.getElementById('modalContent').innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">กำลังโหลดข้อมูลประวัติ...</p>
                </div>
            `;

            // เรียกไฟล์ fetch_note_history.php เพื่อเอาเฉพาะเนื้อหา Modal
            // (ต้องสร้างไฟล์ fetch_note_history.php แยกต่างหาก หรือใช้ view_progress_notes.php ถ้ามันรองรับ AJAX)
            fetch(`fetch_note_history.php?ward_id=${wardId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modalContent').innerHTML = html;
                })
                .catch(err => {
                    document.getElementById('modalContent').innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>';
                    console.error(err);
                });
        }
    </script>
</body>
</html>