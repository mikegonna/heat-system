<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';
require_once __DIR__ . '/med_followup_helpers.php';

if (!ensureMedFollowupColumns($conn)) {
    http_response_code(500);
    exit('ไม่สามารถเตรียมข้อมูลการติดตาม MED ได้ กรุณาตรวจสอบบันทึกข้อผิดพลาดของระบบ');
}

// === ดักจับ POST เพื่อแก้ไขข้อมูล MED ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_med_submit'])) {
    $record_id = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);
    $hn_post = is_string($_POST['hn'] ?? null) ? $_POST['hn'] : '';
    $an_post = is_string($_POST['an'] ?? null) ? $_POST['an'] : '';

    // 🔒 Security Check: ตรวจสอบอีกครั้งว่าเคสปิดไปแล้วหรือไม่
    $chk_case = $conn->prepare("SELECT c.status_case, w.discharge_date FROM med_records m JOIN ward_details w ON m.an = w.an JOIN cases c ON w.case_id = c.id WHERE m.id = ? AND m.hn = ? AND m.an = ? ORDER BY w.id DESC LIMIT 1");
    if (!$chk_case) {
        error_log('Failed to prepare MED edit case check: ' . $conn->error);
        http_response_code(500);
        exit('ไม่สามารถตรวจสอบข้อมูลเคสได้');
    }
    $chk_case->bind_param('iss', $record_id, $hn_post, $an_post);
    if (!$chk_case->execute()) {
        error_log('Failed to check MED edit case: ' . $chk_case->error);
        $chk_case->close();
        http_response_code(500);
        exit('ไม่สามารถตรวจสอบข้อมูลเคสได้');
    }
    $case_result = $chk_case->get_result();
    $case_data = $case_result->fetch_assoc();
    $chk_case->close();

    if (!$case_data) {
        $_SESSION['statusMsg'] = 'ไม่พบประวัติหรือข้อมูลจำหน่ายของเคสนี้';
        $_SESSION['statusType'] = 'error';
        header("Location: formMED.php?hn=" . urlencode($hn_post) . "&an=" . urlencode($an_post));
        exit();
    }
    if ($case_data['status_case'] === 'Closed') {
        $_SESSION['statusMsg'] = "ไม่สามารถแก้ไขได้ เนื่องจากเคสถูกปิด (Closed) ไปแล้ว";
        $_SESSION['statusType'] = "error";
        header("Location: formMED.php?hn=" . urlencode($hn_post) . "&an=" . urlencode($an_post));
        exit();
    }

    $followup = getMedFollowupDetails($_POST['fu_date'] ?? null, $case_data['discharge_date'] ?? null);
    if ($record_id < 1 || !$followup) {
        $_SESSION['statusMsg'] = 'กรุณาระบุวัน Follow-up ที่ไม่ก่อนวันจำหน่าย และตรวจสอบวันจำหน่ายของเคส';
        $_SESSION['statusType'] = 'error';
        header("Location: formMED.php?hn=" . urlencode($hn_post) . "&an=" . urlencode($an_post));
        exit();
    }

    $occ = ($_POST['occupational'] ?? '0') === '1' ? '1' : '0';
    $pmr = ($_POST['pmr'] ?? '0') === '1' ? '1' : '0';
    $cpk = is_string($_POST['cpk'] ?? null) ? $_POST['cpk'] : '';
    $bun = is_string($_POST['bun'] ?? null) ? $_POST['bun'] : '';
    $cr = is_string($_POST['cr'] ?? null) ? $_POST['cr'] : '';
    $eletroride = is_string($_POST['eletroride'] ?? null) ? $_POST['eletroride'] : '';
    $med_note = is_string($_POST['med_note'] ?? null) ? $_POST['med_note'] : '';
    $record_datetime = is_string($_POST['record_datetime'] ?? null) ? str_replace('T', ' ', $_POST['record_datetime']) : '';
    $recorder_name = is_string($_POST['recorder_name'] ?? null) ? $_POST['recorder_name'] : '';

    $update = $conn->prepare('UPDATE med_records SET fu_date = ?, med_status = ?, occupational = ?, pmr = ?, cpk = ?, bun = ?, cr = ?, eletroride = ?, med_note = ?, record_datetime = ?, recorder_name = ? WHERE id = ? AND hn = ? AND an = ?');
    if (!$update) {
        error_log('Failed to prepare MED history update: ' . $conn->error);
        http_response_code(500);
        exit('ไม่สามารถเตรียมบันทึกการแก้ไขได้');
    }
    $update->bind_param(
        'sssssssssssiss',
        $followup['fu_date'],
        $followup['med_status'],
        $occ,
        $pmr,
        $cpk,
        $bun,
        $cr,
        $eletroride,
        $med_note,
        $record_datetime,
        $recorder_name,
        $record_id,
        $hn_post,
        $an_post
    );
    if (!$update->execute()) {
        error_log('Failed to update MED history: ' . $update->error);
        $update->close();
        $_SESSION['statusMsg'] = 'เกิดข้อผิดพลาดขณะบันทึกการแก้ไข';
        $_SESSION['statusType'] = 'error';
        header("Location: formMED.php?hn=" . urlencode($hn_post) . "&an=" . urlencode($an_post));
        exit();
    }
    $update->close();

    $_SESSION['statusMsg'] = "แก้ไขข้อมูลประวัติสำเร็จ";
    $_SESSION['statusType'] = "success";
    header("Location: formMED.php?hn=" . urlencode($hn_post) . "&an=" . urlencode($an_post));
    exit();
}

$hn = isset($_GET['hn']) ? $_GET['hn'] : '';
$an = isset($_GET['an']) ? $_GET['an'] : '';

$patient = null;
$history = null;
$track_count = 0;

if ($hn && $an) {
    // 1. ดึงข้อมูล Patient Header (เพิ่ม c.status_case เข้ามาเช็ค)
    $sqlPt = "SELECT c.patient_name, c.age, c.department, c.congenital_disease, c.phone, c.person_type, c.status_case,
                     c.weight, c.height,
                     w.discharge_date, w.final_diagnosis, w.initial_diagnosis, w.first_visit_date, w.status,
                     er.cause_factor, er.risk_factors,
                     (SELECT doctor_name FROM ward_progress_notes wp WHERE wp.ward_detail_id = w.id ORDER BY wp.note_datetime DESC LIMIT 1) AS doctor_name,
                     (SELECT details FROM ward_progress_notes wp WHERE wp.ward_detail_id = w.id ORDER BY wp.note_datetime DESC LIMIT 1) AS last_note_details
              FROM cases c
              JOIN ward_details w ON c.id = w.case_id
              LEFT JOIN er_details er ON w.case_id = er.case_id
              WHERE c.hn = ? AND w.an = ?
              ORDER BY w.id DESC LIMIT 1"; 
              
    $stmt = $conn->prepare($sqlPt);
    $stmt->bind_param("ss", $hn, $an);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 2. ดึง History MED
    $sqlHist = "SELECT * FROM med_records WHERE hn = ? AND an = ? ORDER BY record_datetime DESC";
    $stmtH = $conn->prepare($sqlHist);
    $stmtH->bind_param("ss", $hn, $an);
    $stmtH->execute();
    $history = $stmtH->get_result();
    $track_count = $history->num_rows;
}

$is_discharged = ($patient && $patient['status'] === 'Discharged');
$is_case_closed = ($patient && $patient['status_case'] === 'Closed');
$disabled_attr = $is_case_closed ? 'disabled' : '';

// คำนวณ BMI
$case_bmi = "-";
if (!empty($patient['weight']) && !empty($patient['height'])) {
    $h_m = $patient['height'] / 100;
    if ($h_m > 0) $case_bmi = number_format($patient['weight'] / ($h_m * $h_m), 2);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บันทึกอายุรกรรม (MED)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --v-primary: #0284c7; /* สีฟ้า MED */
            --v-primary-hover: #0369a1;
            --v-bg: #f0f9ff; 
            --v-light: #e0f2fe;
            --v-text: #0f172a; 
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--v-bg); color: var(--v-text); }
        h1, h2, h3, h4, h5, .btn, .card-header-custom { font-family: 'Prompt', sans-serif; }
        
        .text-theme { color: var(--v-primary) !important; }
        .bg-theme { background-color: var(--v-primary) !important; }
        .bg-theme-light { background-color: var(--v-light) !important; }
        .bg-theme-info { background-color: #ffffff !important; border-color: #4dd0e1 !important; }

        .btn-theme { background-color: var(--v-primary); color: white; border-radius: 8px; transition: all 0.2s; border: none;}
        .btn-theme:hover { background-color: var(--v-primary-hover); color: white; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(2, 132, 199, 0.2);}
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

        .timeline { border-left: 2px solid #bae6fd; padding-left: 20px; position: relative; margin-left: 10px; }
        .timeline-item { position: relative; margin-bottom: 30px; }
        .timeline-marker { position: absolute; left: -26px; top: 0; width: 14px; height: 14px; border-radius: 50%; background: var(--v-primary); border: 2px solid #fff; box-shadow: 0 0 0 2px var(--v-primary); }
        .disposition-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 8px; }
        .disposition-option { flex: 1; min-width: 130px; }
        @media (max-width: 500px) { .disposition-option { min-width: 45%; } }

        .disposition-option input[type="radio"] { display: none; }
        .disposition-option label {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
            padding: 14px 10px; border: 2px solid var(--border); border-radius: var(--radius-md);
            cursor: pointer; font-size: 0.9rem; font-weight: 600; font-family: 'Prompt',sans-serif;
            color: var(--text-soft); background: #fafafa; transition: all 0.18s ease; text-align: center; height: 100%;
        }
        .disposition-option label i { font-size: 1.5rem; margin-bottom: 2px; }
        .disposition-option label:hover { border-color: var(--er-green); color: var(--er-green); background: var(--er-green-pale); }
        .disposition-option input:checked + label { border-color: var(--er-green); background: var(--er-green); color: #fff; box-shadow: 0 4px 14px var(--er-green-20); }
        .disposition-option.dis-discharge input:checked + label { background: #03a103; border-color: #03a103; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.2); }
        .disposition-option.dis-admit input:checked + label { background: #c70202; border-color: #c70202; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.2); }
    </style>
</head>
<body>
    
<?php 
    $path_prefix = "../"; 
    if(file_exists('../header_followup.php')) require_once '../header_followup.php'; 
    else echo "<nav class='navbar navbar-dark bg-primary shadow-sm'><div class='container'><a class='navbar-brand'>MED Dashboard</a></div></nav>";
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
        
        <?php if (!$patient): ?>
            <div class="alert alert-danger text-center p-5 shadow-sm rounded-4">
                <i class="fa-solid fa-circle-exclamation fa-3x mb-3"></i>
                <h4 class="font-prompt fw-bold">ไม่พบข้อมูลผู้ป่วย</h4>
                <p>กรุณาตรวจสอบ HN หรือ AN อีกครั้ง</p>
                <a href="indexMED.php" class="btn btn-outline-danger mt-2 rounded-pill px-4">กลับหน้าหลัก</a>
            </div>
            <?php exit(); ?>
        <?php endif; ?>

        <?php if ($is_case_closed): ?>
            <div class="alert alert-secondary text-center mb-4 shadow-sm rounded-3" style="border: 1px dashed #6c757d;">
                <i class="fa-solid fa-lock me-2"></i>
                <strong>เคสถูกปิดแล้ว (Closed):</strong> ไม่สามารถเพิ่มหรือแก้ไขข้อมูลการประเมินได้
            </div>
        <?php elseif (!$is_discharged): ?>
            <div class="alert alert-warning text-center mb-4 border-warning shadow-sm rounded-3">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                <strong>คำเตือน:</strong> ผู้ป่วยรายนี้สถานะปัจจุบันคือ 
                <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($patient['status'] ?? '-'); ?></span> 
                (ยังไม่ใช่ Discharged)
            </div>
        <?php endif; ?>

        <!-- 1. Patient Header Info -->
        <div class="patient-info-box d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h3 class="fw-bold text-dark font-prompt mb-0"><i class="fa-solid fa-user-doctor text-theme me-2"></i><?php echo htmlspecialchars($patient['patient_name'] ?? '-'); ?></h3>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge bg-light text-dark border px-3 py-2 fs-6">HN: <?php echo htmlspecialchars($hn); ?></span>
                    <span class="badge bg-theme-light text-theme border px-3 py-2 fs-6" style="border-color: var(--v-primary) !important;">AN: <?php echo htmlspecialchars($an); ?></span>
                    <span class="text-muted small ms-2"><i class="fa-solid fa-calendar-check me-1"></i> วันที่จำหน่าย: <?php echo !empty($patient['discharge_date']) ? date('d/m/Y H:i', strtotime($patient['discharge_date'])) : '-'; ?></span>
                </div>
                <div class="row g-3 text-muted" style="font-size: 0.95rem;">
                    <div class="col-auto"><i class="fa-solid fa-child text-secondary me-1"></i> <strong>อายุ:</strong> <?php echo $patient['age'] ?? '-'; ?> ปี</div>
                    <div class="col-auto"><i class="fa-solid fa-phone text-secondary me-1"></i> <strong>โทร:</strong> <?php echo $patient['phone'] ?? '-'; ?></div>
                    <div class="col-auto"><i class="fa-solid fa-calculator text-secondary me-1"></i> <strong>BMI:</strong> <span class="text-danger fw-bold"><?php echo $case_bmi; ?></span></div>
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
                <a href="<?php echo $path_prefix; ?>MED/indexMED.php" class="btn btn-outline-theme px-4 py-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> กลับรายการ
                </a>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- History Section -->
            <div class="col-lg-5 mb-4 order-lg-2">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold text-dark py-3 border-bottom d-flex justify-content-between align-items-center">
                        <div><i class="fa-solid fa-history me-2 text-theme"></i>ประวัติการติดตาม (MED)</div>
                        <span class="badge bg-light text-dark border shadow-sm">ทั้งหมด <?php echo $track_count; ?> รายการ</span>
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
                                    <div class="card mt-2 border-0 shadow-sm rounded-3 position-relative">
                                        <!-- ปุ่มแก้ไขประวัติ ซ่อนเมื่อปิดเคส -->
                                        <?php if(!$is_case_closed): ?>
                                        <div class="position-absolute top-0 end-0 mt-2 me-2" style="z-index: 10;">
                                            <button class="btn btn-sm btn-outline-theme border-0" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8"); ?>)' title="แก้ไขประวัตินี้">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                        <div class="card-body p-3">
                                            <div class="mb-2 pe-4">
                                                <span class="badge bg-theme-light text-theme border border-theme w-100 text-start px-3 py-2 fs-6" style="white-space: normal; line-height: 1.4;">
                                                    <i class="fa-solid fa-clipboard-check me-1"></i> <?php echo htmlspecialchars($row['med_status']); ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($row['fu_date']) || !empty($row['cpk']) || !empty($row['bun']) || !empty($row['cr']) || !empty($row['eletroride'])): ?>
                                                <div class="small text-secondary bg-light p-2 rounded border mb-2">
                                                    <?php if (!empty($row['fu_date'])): ?>
                                                        <div><strong>วันที่ Follow-up:</strong> <?php echo date('d/m/Y H:i', strtotime($row['fu_date'])); ?></div>
                                                    <?php endif; ?>
                                                    <?php
                                                        $lab_results = [];
                                                        foreach (['CPK' => 'cpk', 'BUN' => 'bun', 'Cr' => 'cr', 'Electrolyte' => 'eletroride'] as $lab_label => $lab_key) {
                                                            if (isset($row[$lab_key]) && $row[$lab_key] !== '') {
                                                                $lab_results[] = $lab_label . ': ' . htmlspecialchars((string)$row[$lab_key], ENT_QUOTES, 'UTF-8');
                                                            }
                                                        }
                                                    ?>
                                                    <?php if ($lab_results): ?><div><strong>ผลตรวจ:</strong> <?php echo implode(' | ', $lab_results); ?></div><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if($row['med_note']): ?>
                                                <div class="small text-secondary mt-2 bg-light p-2 rounded border border-dashed">
                                                    <strong class="text-dark d-block mb-1"><i class="fa-solid fa-note-sticky me-1 text-warning"></i> บันทึกเพิ่มเติม:</strong>
                                                    <?php echo nl2br(htmlspecialchars($row['med_note'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="mt-3 d-flex justify-content-between align-items-end">
                                                <small class="text-muted"><i class="fa-solid fa-user-pen me-1"></i><?php echo htmlspecialchars($row['recorder_name']); ?></small>
                                                
                                                <?php if($row['next_med_date']): ?>
                                                    <span class="badge bg-white border border-danger text-danger shadow-sm py-1 px-2"><i class="fa-regular fa-calendar-plus me-1"></i> นัด PMR: <?php echo date('d/m/Y', strtotime($row['next_med_date'])); ?></span>
                                                <?php endif; ?>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted bg-white rounded-3 border border-dashed shadow-sm">
                                <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
                                <p>ยังไม่มีบันทึกการประเมินอายุรกรรม</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Form Section -->
            <div class="col-lg-7 order-lg-1">
                <div class="card card-custom shadow border-0 h-100">
                    <div class="card-header-custom">
                        <i class="fa-solid fa-pen-to-square me-2"></i>บันทึกการประเมิน (Follow-up) ครั้งที่ <?php echo $track_count + 1; ?>
                    </div>
                    <div class="card-body p-4">
                        <form action="save_MED.php" method="POST">
                            <input type="hidden" name="hn" value="<?php echo htmlspecialchars($hn); ?>">
                            <input type="hidden" name="an" value="<?php echo htmlspecialchars($an); ?>">

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5">
                                    <i class="fa-solid fa-calendar-days me-2"></i>วันที่และเวลาที่มา Follow-up <span class="text-danger">*</span>
                                </label>
                                <input type="datetime-local" name="fu_date" id="fu_date" class="form-control shadow-sm" required <?php echo $disabled_attr; ?>>
                                <div class="form-text">ระบบเลือกสถานะให้อัตโนมัติจากวันที่จำหน่าย: ภายใน 14 วัน = มาตรงนัด, เกิน 14 วัน = มาไม่ตรงนัด</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5">
                                    <i class="fa-solid fa-list-check me-2"></i>ผลการติดตามผู้ป่วย
                                </label>
                                <div class="disposition-row">
                                    <div class="disposition-option dis-discharge">
                                        <input type="radio" name="med_status" id="dis_discharge" value="มาตรงนัด" onchange="updateMainFollowupStatus()" required>
                                        <label for="dis_discharge">
                                            <i class="fa-solid fa-check"></i>
                                            มาตรงนัด
                                        </label>
                                    </div>
                                    <div class="disposition-option dis-admit">
                                        <input type="radio" name="med_status" id="dis_admit" value="มาไม่ตรงนัด" onchange="updateMainFollowupStatus()">
                                        <label for="dis_admit">
                                            <i class="fa-solid fa-circle-xmark"></i>
                                            มาไม่ตรงนัด
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!---
                                <div class="mb-4 d-none p-3 bg-theme-light rounded-3 border" id="med_date_group" style="border-color: var(--v-primary) !important;">
                                    <label class="form-label text-theme fw-bold"><i class="fa-regular fa-calendar-plus me-2"></i>ระบุวันที่นัดส่งต่อเวชศาสตร์ฟื้นฟู <span class="text-danger">*</span></label>
                                    <input type="date" name="next_med_date" id="next_med_date" class="form-control bg-white shadow-sm border-theme" <?php //echo $disabled_attr; ?>>
                                </div>
                            --->
                            <div class="mb-4 d-none p-3 bg-theme-info rounded-3 border" id="med_other">
                                <div class="row g-3 mb-3">
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">CPK</label>
                                        <input type="text" name="cpk" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">BUN</label>
                                        <input type="text" name="bun" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">Cr</label>
                                        <input type="text" name="cr" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">Electrolyte</label>
                                        <input type="text" name="eletroride" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-notes-medical me-2"></i>บันทึกเพิ่มเติม <span class="text-danger">*</span></label>
                                <textarea name="med_note" class="form-control bg-light p-3" rows="2" placeholder="ระบุอาการ, แผนการรักษา หรือรายละเอียดเพิ่มเติม..." <?php echo $disabled_attr; ?>></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-signature me-2"></i>การลงชื่อและเวลา</label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                                        <div class="input-group shadow-sm">
                                            <span class="input-group-text bg-light"><i class="fa-solid fa-user-pen text-muted"></i></span>
                                            <input type="text" name="recorder_name" class="form-control" required placeholder="กรอกชื่อแพทย์/พยาบาล" <?php echo $disabled_attr; ?>>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">เวลาบันทึก <span class="text-danger">*</span></label>
                                        <input type="datetime-local" name="record_datetime" class="form-control shadow-sm" value="<?php echo date('Y-m-d\TH:i'); ?>" required <?php echo $disabled_attr; ?>>
                                    </div>
                                </div>
                            </div>

                            <?php if($is_case_closed): ?>
                                <button type="button" class="btn btn-secondary btn-lg w-100 shadow mt-2" disabled>
                                    <i class="fa-solid fa-lock me-2"></i> เคสถูกปิดแล้ว (Closed)
                                </button>
                            <?php else: ?>
                                <button type="submit" name="submit_med" class="btn btn-theme btn-lg w-100 shadow mt-2">
                                    <i class="fa-solid fa-save me-2"></i> บันทึกผลการประเมิน
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Edit MED -->
    <div class="modal fade" id="modalEditMED" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form method="POST">
                    <div class="modal-header bg-theme text-white border-bottom-0">
                        <h5 class="modal-title font-prompt"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขบันทึกอายุรกรรม</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <input type="hidden" name="edit_med_submit" value="1">
                        <input type="hidden" name="record_id" id="edit_med_id">
                        <input type="hidden" name="hn" value="<?php echo htmlspecialchars($hn); ?>">
                        <input type="hidden" name="an" value="<?php echo htmlspecialchars($an); ?>">
                        <input type="hidden" name="occupational" id="edit_occupational">
                        <input type="hidden" name="pmr" id="edit_pmr">
                        
                        <div class="mb-3">
                            <label for="edit_fu_date" class="form-label small fw-bold text-theme">วันที่และเวลาที่มา Follow-up <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="fu_date" id="edit_fu_date" class="form-control border-theme" required>
                            <div class="form-text">สถานะจะคำนวณอัตโนมัติเทียบกับวันที่จำหน่าย (ภายใน 14 วัน = มาตรงนัด)</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-theme">ผลการติดตามผู้ป่วย <span class="text-danger">*</span></label>
                            <div class="disposition-row">
                                <div class="disposition-option dis-discharge">
                                    <input type="radio" name="med_status" id="edit_dis_discharge" value="มาตรงนัด" onchange="updateEditFollowupStatus()" required>
                                    <label for="edit_dis_discharge">
                                        <i class="fa-solid fa-check"></i>
                                        มาตรงนัด
                                    </label>
                                </div>
                                <div class="disposition-option dis-admit">
                                    <input type="radio" name="med_status" id="edit_dis_admit" value="มาไม่ตรงนัด" onchange="updateEditFollowupStatus()">
                                    <label for="edit_dis_admit">
                                        <i class="fa-solid fa-circle-xmark"></i>
                                        มาไม่ตรงนัด
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-4 d-none p-3 bg-theme-info rounded-3 border" id="edit_med_other">
                                <div class="row g-3 mb-3">
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">CPK</label>
                                        <input type="text" name="cpk" id="edit_cpk" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">BUN</label>
                                        <input type="text" name="bun" id="edit_bun" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">Cr</label>
                                        <input type="text" name="cr" id="edit_cr" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                    <div class="col">
                                        <label class="form-label text-theme fw-bold">Electrolyte</label>
                                        <input type="text" name="eletroride" id="edit_eletroride" class="form-control bg-white shadow-sm border-theme" <?php echo $disabled_attr; ?>>
                                    </div>
                                </div>
                            </div>
                            <!-- 

                            <div class="mb-3 d-none p-3 bg-theme-light rounded border border-theme" id="edit_med_date_group">
                                <label class="form-label small fw-bold text-danger">วันที่นัดส่งต่อเวชศาสตร์ฟื้นฟู <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="next_med_date" id="edit_med_next_date">
                            </div>
                            
                            -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-theme">บันทึกเพิ่มเติม</label>
                            <textarea class="form-control border-theme" name="med_note" id="edit_med_note" rows="3"></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-theme">ผู้บันทึก <span class="text-danger">*</span></label>
                                <input type="text" name="recorder_name" id="edit_med_recorder" class="form-control border-theme" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-theme">เวลาบันทึก <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="record_datetime" id="edit_med_datetime" class="form-control border-theme" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-theme px-4"><i class="fa-solid fa-save me-1"></i> บันทึกการแก้ไข</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="<?php echo isset($path_prefix) ? $path_prefix : ''; ?>bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleDateInput(type, val, group_id, input_id) {
            var dateGroup = document.getElementById(group_id);
            var dateInput = document.getElementById(input_id);

            if (val.includes("ระบุวันที่") || val.includes("นัดพบแพทย์") || val.includes("ต่อเนื่อง")) {
                dateGroup.classList.remove('d-none');
                dateInput.required = true; 
            } else {
                dateGroup.classList.add('d-none');
                dateInput.required = false;
                dateInput.value = ''; 
            }
        }

        // จัดการ Modal Edit
        function openEditModal(data) {
            document.getElementById('edit_med_id').value = data.id;
            document.getElementById('edit_occupational').value = data.occupational || '0';
            document.getElementById('edit_pmr').value = data.pmr || '0';
            const followupInput = document.getElementById('edit_fu_date');
            followupInput.value = fmtDate(data.fu_date);

            if (followupInput.value) {
                updateEditFollowupStatus();
            } else {
                document.getElementById('edit_dis_discharge').checked = data.med_status === 'มาตรงนัด';
                document.getElementById('edit_dis_admit').checked = data.med_status === 'มาไม่ตรงนัด';
            }

            document.getElementById('edit_med_note').value = data.med_note || '';
            document.getElementById('edit_med_recorder').value = data.recorder_name || '';
            document.getElementById('edit_med_datetime').value = fmtDate(data.record_datetime);
            document.getElementById('edit_cpk').value = data.cpk || '';
            document.getElementById('edit_bun').value = data.bun || '';
            document.getElementById('edit_cr').value = data.cr || '';
            document.getElementById('edit_eletroride').value = data.eletroride || '';

            toggleEdit();

            var myModal = new bootstrap.Modal(document.getElementById('modalEditMED'));
            myModal.show();
        }

        function toggleEditDateInput() {
            var val = document.getElementById('edit_med_status').value;
            var group = document.getElementById('edit_med_date_group');
            var input = document.getElementById('edit_med_next_date');
            
            if (val.includes('ระบุวันที่') || val.includes('นัดพบแพทย์')) {
                group.classList.remove('d-none');
                input.required = true;
            } else {
                group.classList.add('d-none');
                input.required = false;
                input.value = '';
            }
        }

        function fmtDate(dt) {
            if(!dt || dt === '0000-00-00 00:00:00' || dt === '-') return '';
            return dt.replace(' ', 'T').substring(0, 16);
        }

        function toggleDisposition() {
            const showLabs = document.getElementById('dis_discharge').checked || document.getElementById('dis_admit').checked;
            document.getElementById('med_other').classList.toggle('d-none', !showLabs);
        }

        function toggleEdit() {
            const showLabs = document.getElementById('edit_dis_discharge').checked || document.getElementById('edit_dis_admit').checked;
            document.getElementById('edit_med_other').classList.toggle('d-none', !showLabs);
        }

        const dischargeDate = <?= json_encode($patient['discharge_date'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const fourteenDaysInMilliseconds = 14 * 24 * 60 * 60 * 1000;

        function parseWallClock(value) {
            const parts = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(value));
            if (!parts) return NaN;

            const year = Number(parts[1]);
            const month = Number(parts[2]);
            const day = Number(parts[3]);
            const hour = Number(parts[4]);
            const minute = Number(parts[5]);
            const second = Number(parts[6] || 0);
            const timestamp = Date.UTC(year, month - 1, day, hour, minute, second);
            const parsed = new Date(timestamp);
            if (
                parsed.getUTCFullYear() !== year
                || parsed.getUTCMonth() !== month - 1
                || parsed.getUTCDate() !== day
                || parsed.getUTCHours() !== hour
                || parsed.getUTCMinutes() !== minute
                || parsed.getUTCSeconds() !== second
            ) {
                return NaN;
            }
            return timestamp;
        }

        function setFollowupStatus(input, onTimeRadio, lateRadio, updateLabs) {
            if (!input.value) {
                input.setCustomValidity('');
                onTimeRadio.checked = false;
                lateRadio.checked = false;
                updateLabs();
                return;
            }

            const followupTimestamp = parseWallClock(input.value);
            const dischargeTimestamp = parseWallClock(dischargeDate);
            if (!Number.isFinite(followupTimestamp) || !Number.isFinite(dischargeTimestamp)) {
                input.setCustomValidity('ไม่พบวันที่จำหน่ายหรือวันที่ Follow-up ที่ถูกต้อง');
                onTimeRadio.checked = false;
                lateRadio.checked = false;
                updateLabs();
                return;
            }

            const elapsed = followupTimestamp - dischargeTimestamp;
            if (elapsed < 0) {
                input.setCustomValidity('วันที่ Follow-up ต้องไม่ก่อนวันจำหน่าย');
                onTimeRadio.checked = false;
                lateRadio.checked = false;
            } else {
                input.setCustomValidity('');
                onTimeRadio.checked = elapsed <= fourteenDaysInMilliseconds;
                lateRadio.checked = elapsed > fourteenDaysInMilliseconds;
            }
            updateLabs();
        }

        function updateMainFollowupStatus() {
            setFollowupStatus(
                document.getElementById('fu_date'),
                document.getElementById('dis_discharge'),
                document.getElementById('dis_admit'),
                toggleDisposition
            );
        }

        function updateEditFollowupStatus() {
            setFollowupStatus(
                document.getElementById('edit_fu_date'),
                document.getElementById('edit_dis_discharge'),
                document.getElementById('edit_dis_admit'),
                toggleEdit
            );
        }

        document.getElementById('fu_date').addEventListener('change', updateMainFollowupStatus);
        document.getElementById('edit_fu_date').addEventListener('change', updateEditFollowupStatus);
    </script>
</body>
</html>