<?php
session_start();
date_default_timezone_set('Asia/Bangkok');
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

$current_user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'เจ้าหน้าที่';

// รับค่าจาก URL
$hn = isset($_GET['hn']) ? $_GET['hn'] : '';

$patient = null;
$history = null;
$visit_count = 0; // จำนวนครั้งที่เยี่ยมไปแล้ว

if ($hn) {

    $sqlPt = "SELECT 
                c.patient_name, c.age, c.department, c.congenital_disease, c.phone, c.status_case,
                e.recorded_at as er_time,
                e.FstDx as er_dx,
                e.cause_factor, 
                e.risk_factors,
                w.discharge_date,
                w.final_diagnosis,
                w.initial_diagnosis,
                w.first_visit_date,
                w.an,
                (SELECT doctor_name FROM ward_progress_notes WHERE ward_detail_id = w.id ORDER BY note_datetime DESC LIMIT 1) AS doctor_name,
                (SELECT details FROM ward_progress_notes WHERE ward_detail_id = w.id ORDER BY note_datetime DESC LIMIT 1) AS last_note_details
            FROM cases c
            LEFT JOIN er_details e ON c.id = e.case_id
            LEFT JOIN ward_details w ON c.id = w.case_id
            WHERE c.hn = ?
            ORDER BY e.id DESC LIMIT 1";

    $stmt = $conn->prepare($sqlPt);
    $stmt->bind_param("s", $hn);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();

    $sqlHist = "SELECT * FROM home_visits 
                WHERE hn = ?
                ORDER BY record_datetime DESC";

    $stmtH = $conn->prepare($sqlHist);
    $stmtH->bind_param("s", $hn);
    $stmtH->execute();
    $history = $stmtH->get_result();
    $visit_count = $history->num_rows;
}

$is_case_closed = ($patient && $patient['status_case'] === 'Closed');
$disabled_attr = $is_case_closed ? 'disabled' : '';

// === กำหนด Severity จาก diagnosis ===
$dx = $patient['final_diagnosis'] ?? $patient['initial_diagnosis'] ?? $patient['er_dx'] ?? '';

$severity = 'Moderate'; // default
if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \(CPK > 1,000\)/i', $dx)) {
    $severity = 'Severe';
}

// === Logic วันนัดตาม severity ===
$next_visit_val = "";
$next_visit_label = "";
$show_next_visit_input = false;
$current_target_date_hint = "";

if (!empty($patient['discharge_date'])) {
    $dc_date = $patient['discharge_date'];
} elseif (!empty($patient['er_time'])) {
    $dc_date = $patient['er_time'];
}

if (!empty($dc_date)) {

    if ($severity === 'Severe') {
        // Severe: เยี่ยม 2 ครั้ง (day3, day6)
        if ($visit_count == 0) {
            $current_target_date_hint = date('d/m/Y', strtotime($dc_date . ' +3 days'));
            $next_visit_val  = date('Y-m-d', strtotime($dc_date . ' +6 days'));
            $next_visit_label = "กำหนดนัดครั้งที่ 2 (Day 6)";
            $show_next_visit_input = true;

        } elseif ($visit_count == 1) {
            $current_target_date_hint = date('d/m/Y', strtotime($dc_date . ' +6 days'));
            $show_next_visit_input = false; // จบ

        } else {
            $current_target_date_hint = "เยี่ยมเพิ่มเติม";
            $show_next_visit_input = false;
        }

    } else {
        // Moderate: เยี่ยม 1 ครั้ง (day3)
        if ($visit_count == 0) {
            $current_target_date_hint = date('d/m/Y', strtotime($dc_date . ' +3 days'));
            $show_next_visit_input = false; // จบหลังครั้งแรก

        } else {
            $current_target_date_hint = "เยี่ยมเพิ่มเติม";
            $show_next_visit_input = false;
        }
    }

} else {
    $current_target_date_hint = "-";
    $show_next_visit_input = false;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บันทึกการเยี่ยมบ้าน</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --v-primary: #d6728d; 
            --v-primary-hover: #b95c76;
            --v-bg: #fdf5f7; 
            --v-light: #fae8ee;
            --v-text: #2c3e50; 
            --border-soft: #f2cad7;
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--v-bg); color: var(--v-text); }
        h1, h2, h3, h4, h5, .btn, .card-header-custom { font-family: 'Prompt', sans-serif; }
        
        .text-theme { color: var(--v-primary) !important; }
        .bg-theme { background-color: var(--v-primary) !important; }
        .bg-theme-light { background-color: var(--v-light) !important; }

        .btn-theme { background-color: var(--v-primary); color: white; border-radius: 8px; transition: all 0.2s; border: none;}
        .btn-theme:hover { background-color: var(--v-primary-hover); color: white; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(214, 114, 141, 0.25);}
        .btn-outline-theme { border: 1.5px solid var(--v-primary); color: var(--v-primary); border-radius: 8px; background: #fff; transition: all 0.2s;}
        .btn-outline-theme:hover { background-color: var(--v-light); color: var(--v-primary-hover); }

        .patient-info-box {
            background: #fff; border-left: 6px solid var(--v-primary); border-radius: 16px;
            padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px;
        }

        .card-custom { border: none; border-radius: 18px; box-shadow: 0 6px 20px rgba(0,0,0,0.04); background: #fff; overflow: hidden; }
        .card-header-custom { 
            background: linear-gradient(135deg, var(--v-primary) 0%, color-mix(in srgb, var(--v-primary) 80%, black) 100%);
            color: white; border-radius: 18px 18px 0 0 !important; padding: 16px 20px; font-weight: 600; border-bottom: none;
        }

        .timeline { border-left: 2px solid var(--border-soft); padding-left: 20px; position: relative; margin-left: 10px; }
        .timeline-item { position: relative; margin-bottom: 30px; }
        .timeline-marker { position: absolute; left: -26px; top: 0; width: 14px; height: 14px; border-radius: 50%; background: var(--v-primary); border: 2px solid #fff; box-shadow: 0 0 0 2px var(--v-primary); }
        .disposition-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 8px; }
        .disposition-option { flex: 1; min-width: 130px; border-radius: 12px;}
        @media (max-width: 500px) { .disposition-option { min-width: 45%; } }

        .disposition-option input[type="radio"] { display: none; border-radius: 12px;}
        .disposition-option label {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
            padding: 14px 10px; border: 2px solid var(--border); border-radius: var(--radius-md);
            cursor: pointer; font-size: 0.9rem; font-weight: 600; font-family: 'Prompt',sans-serif;
            color: var(--text-soft); background: #fafafa; transition: all 0.18s ease; text-align: center; height: 100%; border-radius: 12px;
        }
        .disposition-option label i { font-size: 1.5rem; margin-bottom: 2px; }
        .disposition-option label:hover { border-color: var(--er-green); color: var(--er-green); background: var(--er-green-pale); }
        .disposition-option input:checked + label { border-color: var(--er-green); background: var(--er-green); box-shadow: 0 4px 14px var(--er-green-20); }
        .disposition-option.dis-discharge input:checked + label { color: #000000; background: #feffed; border-color: #5e5f5c; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.2); border-radius: 12px;}
        .disposition-option.dis-admit input:checked + label { color: #000000; background: #f9ff83; border-color: #f9ff83; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.2); border-radius: 12px;}
        .disposition-option.dis-refer input:checked + label { color: #ffffff; background: #d97706; border-color: #d97706; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.2); border-radius: 12px;}
        .disposition-option.dis-dead  input:checked + label { color: #fff; background: #5d4204; border-color: #5d4204; box-shadow: 0 4px 14px rgba(55, 65, 81, 0.2); border-radius: 12px;}
    </style>
</head>
<body>
    
<?php 
    $path_prefix = "../"; 
    if(file_exists('../header_visit.php')) require_once '../header_visit.php'; 
    else echo "<div class='alert alert-warning'>ไม่พบไฟล์ header_visit.php</div>";
?>

    <?php if(isset($_SESSION['statusMsg'])) { ?>
        <script>
            Swal.fire({
                icon: '<?php echo $_SESSION['statusType']; ?>',
                title: '<?php echo ($_SESSION['statusType']=='success') ? 'สำเร็จ' : 'แจ้งเตือน'; ?>',
                text: '<?php echo $_SESSION['statusMsg']; ?>',
                timer: 1500,
                showConfirmButton: false
            });
        </script>
    <?php unset($_SESSION['statusMsg']); unset($_SESSION['statusType']); } ?>

    <div class="container-fluid px-4 py-4" style="max-width: 1400px;">

        <?php if ($is_case_closed): ?>
            <div class="alert alert-secondary text-center mb-4 shadow-sm rounded-3" style="border: 1px dashed #6c757d;">
                <i class="fa-solid fa-lock me-2"></i>
                <strong>เคสถูกปิดแล้ว (Closed):</strong> ไม่สามารถเพิ่มหรือแก้ไขข้อมูลการเยี่ยมบ้านได้
            </div>
        <?php endif; ?>
        
        <!-- 1. Patient Header Info -->
        <div class="patient-info-box d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h3 class="fw-bold text-dark font-prompt mb-0"><i class="fa-solid fa-house-user text-theme me-2"></i><?php echo htmlspecialchars($patient['patient_name'] ?? '-'); ?></h3>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge bg-light text-dark border px-3 py-2 fs-6">HN: <?php echo htmlspecialchars($hn); ?></span>
                    <span class="badge bg-theme-light text-theme border px-3 py-2 fs-6" style="border-color: var(--v-primary) !important;">AN: <?php echo !empty($patient['an']) ? htmlspecialchars($patient['an']) : '-'; ?></span>
                    <span class="text-muted small ms-2"><i class="fa-solid fa-calendar-check me-1"></i> วันที่จำหน่าย: <?php echo !empty($patient['discharge_date']) ? date('d/m/Y', strtotime($patient['discharge_date'])) : '-'; ?></span>
                </div>
                <div class="row g-3 text-muted" style="font-size: 0.95rem;">
                    <div class="col-auto"><i class="fa-solid fa-child text-secondary me-1"></i> <strong>อายุ:</strong> <?php echo htmlspecialchars($patient['age'] ?? '-'); ?> ปี</div>
                    <div class="col-auto"><i class="fa-solid fa-notes-medical text-danger me-1"></i> <strong>โรคประจำตัว:</strong> <?php echo htmlspecialchars($patient['congenital_disease'] ?? '-'); ?></div>
                    <div class="col-auto">
                        <i class="fa-solid fa-stethoscope text-primary me-1"></i> <strong>วินิจฉัยสุดท้าย:</strong>
                        <span class="fw-bold text-danger">
                            <?php 
                                if (!empty($patient['final_diagnosis'])) {
                                    echo htmlspecialchars($patient['final_diagnosis']);
                                } elseif (!empty($patient['last_note_details'])) {
                                    echo htmlspecialchars($patient['last_note_details']);
                                } else {
                                    echo '-';
                                }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="text-md-end">
                <a href="<?php echo $path_prefix; ?>Visit/indexVisit.php" class="btn btn-outline-theme px-4 py-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> กลับรายการ
                </a>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- History Section -->
            <div class="col-lg-5 mb-4 order-lg-2">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold text-dark py-3 border-bottom d-flex justify-content-between align-items-center">
                        <div><i class="fa-solid fa-history me-2 text-theme"></i>ประวัติการเยี่ยมบ้าน</div>
                        <span class="badge bg-light text-dark border shadow-sm">ทั้งหมด <?php echo $visit_count; ?> รายการ</span>
                    </div>
                    <div class="card-body bg-theme-light" style="max-height: 800px; overflow-y: auto;">
                        <?php if ($history && $history->num_rows > 0): ?>
                            <div class="timeline mt-2">
                                <?php while($row = $history->fetch_assoc()): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="fw-bold text-theme">
                                        <?php echo date('d/m/Y', strtotime($row['record_datetime'])); ?>
                                        <small class="text-muted ms-2 fw-normal"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($row['record_datetime'])); ?> น.</small>
                                    </div>
                                    <div class="card mt-2 border-0 shadow-sm rounded-3">
                                        <div class="card-body p-3">
                                            <div class="mb-2">
                                                <strong>อาการ:</strong> 
                                                <span class="<?php echo ($row['symptoms']=='ไม่มีอาการผิดปกติ') ? 'text-success':'text-danger'; ?>">
                                                    <?php echo htmlspecialchars($row['symptoms']); ?>
                                                </span>
                                                <?php if($row['symptoms_other']) echo "<div class='small text-muted ps-3'>- ".htmlspecialchars($row['symptoms_other'])."</div>"; ?>
                                            </div>
                                            <div class="small bg-light p-2 rounded border">
                                                <div><i class="fa-solid fa-list-check me-1 text-secondary"></i> <?php echo htmlspecialchars($row['checklist_obs']); ?></div>
                                                <?php if($row['body_temperature']) echo "<div><i class='fa-solid fa-temperature-half me-1 text-warning'></i> Temp: ".htmlspecialchars($row['body_temperature'])." °C</div>"; ?>
                                                <?php if($row['activity_details']) echo "<div><i class='fa-solid fa-person-walking me-1 text-success'></i> ".htmlspecialchars($row['activity_details'])."</div>"; ?>
                                                <?php if($row['urine_color']) echo "<div><i class='fa-solid fa-droplet me-1 text-info'></i> ปัสสาวะ: ".htmlspecialchars($row['urine_color'])."</div>"; ?>
                                            </div>
                                            
                                            <div class="mt-3 d-flex justify-content-between align-items-end">
                                                <small class="text-muted"><i class="fa-solid fa-user-pen me-1"></i><?php echo htmlspecialchars($row['recorder_name']); ?></small>
                                                
                                                <div class="d-flex gap-2 align-items-center">
                                                    <?php if($row['next_visit_date']): ?>
                                                        <span class="badge bg-white border border-primary text-primary shadow-sm"><i class="fa-regular fa-calendar-plus me-1"></i> นัด: <?php echo date('d/m/Y', strtotime($row['next_visit_date'])); ?></span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- ซ่อนปุ่มแก้ไขถ้าปิดเคสแล้ว -->
                                                    <?php if(!$is_case_closed): ?>
                                                    <button type="button" class="btn btn-outline-warning btn-sm shadow-sm"
                                                        onclick='openEditModal(<?php echo json_encode($row); ?>)'>
                                                        <i class="fa-solid fa-pen"></i> แก้ไข
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted bg-white rounded-3 border border-dashed shadow-sm">
                                <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
                                <p>ยังไม่มีบันทึกการเยี่ยมบ้าน</p>
                                <div class="alert bg-theme-light text-theme border d-inline-block small px-3 py-2 fw-bold shadow-sm">
                                    <i class="fa-solid fa-calendar-check me-1"></i> กำหนดเยี่ยมครั้งแรก: 
                                    <?php echo !empty($patient['first_visit_date']) ? date('d/m/Y', strtotime($patient['first_visit_date'])) : (isset($dc_date) ? date('d/m/Y', strtotime($dc_date . ' + 3 days')) : '-'); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Form Section -->
            <div class="col-lg-7 order-lg-1">
                <div class="card card-custom shadow border-0 h-100">
                    <div class="card-header-custom">
                        <i class="fa-solid fa-pen-to-square me-2"></i>บันทึกการเยี่ยมบ้าน
                    </div>
                    <div class="card-body p-4">
                        <form action="save_visit.php" method="POST">
                            <input type="hidden" name="hn" value="<?php echo htmlspecialchars($hn); ?>">
                            <input type="hidden" name="an" value="<?php echo htmlspecialchars($patient['an'] ?? ''); ?>">

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-1 me-2"></i>การสังเกตและสัญญาณชีพ</label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="form-check bg-light p-3 rounded-3 border transition-all">
                                            <input class="form-check-input ms-1" type="checkbox" name="obs[]" value="มีไข้" id="obs_fever" onchange="toggleInput('fever_group', 'body_temp',this)" <?php echo $disabled_attr; ?>>
                                            <label class="form-check-label fw-bold text-danger ms-2" for="obs_fever">มีไข้</label>
                                            <div id="fever_group" class="mt-2 d-none">
                                                <input type="number" step="0.1" name="body_temperature" id="body_temp" class="form-control form-control-sm" placeholder="ระบุอุณหภูมิ (°C)" <?php echo $disabled_attr; ?>>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check bg-light p-3 rounded-3 border transition-all">
                                            <input class="form-check-input ms-1" type="checkbox" name="obs[]" value="ทำกิจกรรม" id="obs_act" onchange="toggleInput('act_group', 'act_detail', this)" <?php echo $disabled_attr; ?>>
                                            <label class="form-check-label fw-bold text-success ms-2" for="obs_act">ทำกิจกรรมได้</label>
                                            <div id="act_group" class="mt-2 d-none">
                                                <input type="text" name="activity_details" id="act_detail" class="form-control form-control-sm" placeholder="ระบุกิจกรรม..." <?php echo $disabled_attr; ?>>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12 mt-3">
                                        <label class="form-label small fw-bold text-secondary">สีปัสสาวะ</label>
                                        <div class="disposition-row">
                                            <div class="disposition-option dis-discharge">
                                                <input type="radio" name="urine_color" id="dis_discharge" value="สีใส" <?php echo $disabled_attr; ?>>
                                                <label for="dis_discharge">
                                                    <i class="fa-solid fa-glass-water me-1"></i> สีใส
                                                </label>
                                            </div>
                                            <div class="disposition-option dis-admit">
                                                <input type="radio" name="urine_color" id="dis_admit" value="สีเหลืองอ่อน" <?php echo $disabled_attr; ?>>
                                                <label for="dis_admit">
                                                    <i class="fa-solid fa-glass-water me-1"></i> สีเหลืองอ่อน
                                                </label>
                                            </div>
                                            <div class="disposition-option dis-refer">
                                                <input type="radio" name="urine_color" id="dis_refer" value="สีเหลืองเข้ม" <?php echo $disabled_attr; ?>>
                                                <label for="dis_refer">
                                                    <i class="fa-solid fa-glass-water me-1"></i> สีเหลืองเข้ม
                                                </label>
                                            </div>
                                            <div class="disposition-option dis-dead">
                                                <input type="radio" name="urine_color" id="dis_dead" value="สีน้ำตาลโคล่า" <?php echo $disabled_attr; ?>>
                                                <label for="dis_dead">
                                                    <i class="fa-solid fa-glass-water me-1"></i> สีน้ำตาลโคล่า
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-2 me-2"></i>อาการผิดปกติ</label>
                                <div class="form-check mb-3 bg-light p-2 rounded border border-success border-opacity-25">
                                    <input class="form-check-input ms-2" type="checkbox" name="symptoms[]" value="ไม่มีอาการผิดปกติ" id="sym_none" onchange="toggleSymptoms(this)" <?php echo $disabled_attr; ?>>
                                    <label class="form-check-label text-success fw-bold ms-2" for="sym_none">ไม่มีอาการผิดปกติ (Normal)</label>
                                </div>
                                <div class="row g-2 ps-3 border-start border-3 border-light ms-1">
                                    <?php 
                                    $syms = ['ปวดศีรษะ', 'มึนงง', 'หน้ามืด', 'ปวดเมื่อยกล้ามเนื้อ', 'อ่อนเพลีย', 'จุกแน่นหน้าอก', 'คลื่นไส้', 'อาเจียน', 'ทานไม่ได้', 'ชัก'];
                                    foreach($syms as $s) {
                                        echo "<div class='col-md-4'><div class='form-check'><input class='form-check-input abn border-secondary' type='checkbox' name='symptoms[]' value='$s' $disabled_attr><label class='form-check-label'>$s</label></div></div>";
                                    }
                                    ?>
                                    <div class="col-12 mt-3">
                                        <div class="form-check">
                                            <input class="form-check-input abn border-secondary" type="checkbox" name="symptoms[]" value="other" onchange="toggleInput('other_grp', 'sym_other', this)" <?php echo $disabled_attr; ?>>
                                            <label class="form-check-label fw-bold">อื่นๆ (ระบุ)</label>
                                        </div>
                                        <div id="other_grp" class="d-none mt-2">
                                            <input type="text" name="symptoms_other" id="sym_other" class="form-control form-control-sm" placeholder="ระบุอาการเพิ่มเติม..." <?php echo $disabled_attr; ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-3 me-2"></i>การลงชื่อและนัดหมาย</label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                                        <div class="input-group shadow-sm">
                                            <span class="input-group-text bg-light"><i class="fa-solid fa-user-pen text-muted"></i></span>
                                            <input type="text" name="recorder_name" class="form-control" required placeholder="กรอกชื่อผู้บันทึก" <?php echo $disabled_attr; ?>>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">วันเวลาที่เยี่ยม <span class="text-danger">*</span></label>
                                        <input type="datetime-local" name="record_datetime" class="form-control shadow-sm" value="<?php echo date('Y-m-d\TH:i'); ?>" required <?php echo $disabled_attr; ?>>
                                        <?php if($current_target_date_hint): ?>
                                            <div class="form-text text-theme fw-bold mt-1"><i class="fa-solid fa-bullseye me-1"></i> เป้าหมายวันนี้: <?php echo $current_target_date_hint; ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-12 mt-3">
                                        <?php if($show_next_visit_input): ?>
                                            <div class="p-3 bg-theme-light rounded-3 border" style="border-color: var(--v-primary) !important;">
                                                <label class="form-label fw-bold text-theme"><i class="fa-regular fa-calendar-plus me-2"></i><?php echo $next_visit_label; ?></label>
                                                <input type="date" name="next_visit_date" class="form-control bg-white shadow-sm" value="<?php echo $next_visit_val; ?>" required <?php echo $disabled_attr; ?>>
                                                <div class="form-text text-muted mt-2"><i class="fa-solid fa-robot me-1"></i>ระบบคำนวณวันนัดให้อัตโนมัติ (สามารถแก้ไขได้)</div>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-success border-success mt-2 mb-0 shadow-sm d-flex align-items-center">
                                                <i class="fa-solid fa-flag-checkered fa-2x me-3 opacity-50"></i> 
                                                <div>
                                                    <strong>สิ้นสุดการติดตาม (End of Follow-up)</strong>
                                                    <div class="small mt-1">ครบกำหนดการเยี่ยม หรือสิ้นสุดกระบวนการแล้ว</div>
                                                </div>
                                                <input type="hidden" name="next_visit_date" value="">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if($is_case_closed): ?>
                                <button type="button" class="btn btn-secondary btn-lg w-100 shadow mt-2" disabled>
                                    <i class="fa-solid fa-lock me-2"></i> เคสถูกปิดแล้ว (Closed)
                                </button>
                            <?php else: ?>
                                <button type="submit" name="submit_visit" class="btn btn-theme btn-lg w-100 shadow mt-2">
                                    <i class="fa-solid fa-save me-2"></i> บันทึกผลการเยี่ยมบ้าน
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Edit -->
    <div class="modal fade" id="editVisitModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <form action="update_visit.php" method="POST">
                    <div class="modal-header bg-theme text-white" style="border-radius: 16px 16px 0 0;">
                        <h5 class="modal-title font-prompt"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขบันทึกการเยี่ยมบ้าน</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="visit_id" id="edit_visit_id">
                        <input type="hidden" name="hn" value="<?php echo htmlspecialchars($hn); ?>">
                        <input type="hidden" name="an" value="<?php echo htmlspecialchars($patient['an'] ?? ''); ?>">

                        <div class="mb-4">
                            <label class="fw-bold text-theme mb-2">1. การสังเกตและสัญญาณชีพ</label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-check border p-3 rounded bg-light">
                                        <input class="form-check-input" type="checkbox" name="obs[]" value="มีไข้" id="edit_obs_fever">
                                        <label class="form-check-label text-danger fw-bold">มีไข้</label>
                                        <input type="number" step="0.1" name="body_temperature" id="edit_body_temp" class="form-control mt-2" placeholder="ระบุอุณหภูมิ (°C)">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check border p-3 rounded bg-light">
                                        <input class="form-check-input" type="checkbox" name="obs[]" value="ทำกิจกรรม" id="edit_obs_act">
                                        <label class="form-check-label text-success fw-bold">ทำกิจกรรมได้</label>
                                        <input type="text" name="activity_details" id="edit_act_detail" class="form-control mt-2" placeholder="ระบุกิจกรรม">
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <label class="small fw-bold">สีปัสสาวะ</label>
                                    <input type="text" name="urine_color" id="edit_urine_color" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="fw-bold text-theme mb-2">2. อาการผิดปกติ</label>
                            <div class="form-check mb-3 bg-light p-2 rounded border border-success border-opacity-25">
                                <input class="form-check-input ms-2" type="checkbox" name="symptoms[]" value="ไม่มีอาการผิดปกติ" id="edit_sym_none" onchange="toggleEditSymptoms(this)">
                                <label class="form-check-label text-success fw-bold ms-2">ไม่มีอาการผิดปกติ</label>
                            </div>
                            <div class="row g-2 ps-3 border-start">
                                <?php 
                                $syms = ['ปวดศีรษะ', 'มึนงง', 'หน้ามืด', 'ปวดเมื่อยกล้ามเนื้อ', 'อ่อนเพลีย', 'จุกแน่นหน้าอก', 'คลื่นไส้', 'อาเจียน', 'ทานไม่ได้', 'ชัก'];
                                foreach($syms as $i => $s) {
                                    echo "<div class='col-md-4'><div class='form-check'><input class='form-check-input edit-abn' type='checkbox' name='symptoms[]' value='$s' id='edit_sym_$i'><label class='form-check-label'>$s</label></div></div>";
                                }
                                ?>
                                <div class="col-12 mt-2">
                                    <label class="form-label small fw-bold">อาการอื่นๆ</label>
                                    <input type="text" name="symptoms_other" id="edit_sym_other" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="small fw-bold">ผู้บันทึก</label>
                                <input type="text" name="recorder_name" id="edit_recorder_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="small fw-bold">วันที่เยี่ยม</label>
                                <input type="datetime-local" name="record_datetime" id="edit_record_datetime" class="form-control" required>
                            </div>
                            
                            <div class="col-md-12 mt-3">
                                <div class="p-3 bg-theme-light rounded border border-theme">
                                    <label class="small text-theme fw-bold mb-1"><i class="fa-regular fa-calendar-plus me-1"></i>การนัดหมายครั้งต่อไป (แก้ไข)</label>
                                    <input type="date" name="next_visit_date" id="edit_next_visit_inp" class="form-control" placeholder="ไม่มีนัดหมาย">
                                    <div class="form-text small mt-1 text-muted">หากต้องการยกเลิกนัดให้เว้นว่างไว้</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light" style="border-radius: 0 0 16px 16px;">
                        <button type="button" class="btn btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" name="submit_update" class="btn btn-warning text-dark fw-bold px-4 rounded-pill shadow-sm"><i class="fa-solid fa-save me-1"></i> บันทึกการแก้ไข</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function toggleInput(groupId, inputId, checkbox) {
            const group = document.getElementById(groupId);
            const input = document.getElementById(inputId);
            if (checkbox.checked) {
                group.classList.remove('d-none');
                input.required = true;
                input.focus();
            } else {
                group.classList.add('d-none');
                input.required = false;
                input.value = '';
            }
        }

        function toggleSymptoms(noneCb) {
            const abns = document.querySelectorAll('.abn');
            const otherGrp = document.getElementById('other_grp');
            const otherInp = document.getElementById('sym_other');
            
            if (noneCb.checked) {
                abns.forEach(cb => { cb.checked = false; cb.disabled = true; });
                otherGrp.classList.add('d-none');
                otherInp.value = '';
            } else {
                abns.forEach(cb => cb.disabled = false);
            }
        }

        document.querySelectorAll('.abn').forEach(cb => {
            cb.addEventListener('change', () => { if(cb.checked) document.getElementById('sym_none').checked = false; });
        });

        function openEditModal(data) {
            document.getElementById('edit_visit_id').value = data.id;
            document.getElementById('edit_body_temp').value = data.body_temperature || '';
            document.getElementById('edit_act_detail').value = data.activity_details || '';
            document.getElementById('edit_urine_color').value = data.urine_color || '';
            document.getElementById('edit_sym_other').value = data.symptoms_other || '';
            document.getElementById('edit_recorder_name').value = data.recorder_name || '';
            
            if(data.record_datetime) {
                document.getElementById('edit_record_datetime').value = data.record_datetime.replace(' ', 'T');
            }
            
            document.getElementById('edit_next_visit_inp').value = data.next_visit_date || '';

            document.getElementById('edit_obs_fever').checked = false;
            document.getElementById('edit_obs_act').checked = false;
            if (data.checklist_obs) {
                if (data.checklist_obs.includes('มีไข้')) document.getElementById('edit_obs_fever').checked = true;
                if (data.checklist_obs.includes('ทำกิจกรรม')) document.getElementById('edit_obs_act').checked = true;
            }

            document.getElementById('edit_sym_none').checked = false;
            document.querySelectorAll('.edit-abn').forEach(cb => {
                cb.checked = false;
                cb.disabled = false;
            });

            if (data.symptoms) {
                if (data.symptoms.includes('ไม่มีอาการผิดปกติ')) {
                    document.getElementById('edit_sym_none').checked = true;
                    toggleEditSymptoms(document.getElementById('edit_sym_none'));
                } else {
                    let syms = data.symptoms.split(', ');
                    document.querySelectorAll('.edit-abn').forEach(cb => {
                        if (syms.some(s => s.trim() === cb.value)) {
                            cb.checked = true;
                        }
                    });
                }
            }

            var myModal = new bootstrap.Modal(document.getElementById('editVisitModal'));
            myModal.show();
        }

        function toggleEditSymptoms(noneCb) {
            const abns = document.querySelectorAll('.edit-abn');
            if (noneCb.checked) {
                abns.forEach(cb => { cb.checked = false; cb.disabled = true; });
            } else {
                abns.forEach(cb => cb.disabled = false);
            }
        }
        
        document.querySelectorAll('.edit-abn').forEach(cb => {
            cb.addEventListener('change', () => { 
                if(cb.checked) {
                    document.getElementById('edit_sym_none').checked = false; 
                }
            });
        });
    </script>
</body>
</html>