<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../database/db_conn.php';
require_once __DIR__ . '/occ_helpers.php';

$hn = is_string($_GET['hn'] ?? null) ? trim($_GET['hn']) : '';
$an = is_string($_GET['an'] ?? null) ? trim($_GET['an']) : '';
$patient = null;
$history = null;

if ($hn !== '' && $an !== '') {
    $patientStmt = $conn->prepare(
        'SELECT c.patient_name, c.age, c.department, c.status_case, c.phone, c.weight, c.height,
                w.discharge_date, w.final_diagnosis, w.status,
                (SELECT details FROM ward_progress_notes wp
                 WHERE wp.ward_detail_id = w.id
                 ORDER BY wp.note_datetime DESC
                 LIMIT 1) AS last_note_details
         FROM cases c
         JOIN ward_details w ON w.case_id = c.id
         WHERE c.hn = ? AND w.an = ?
         ORDER BY w.id DESC
         LIMIT 1'
    );
    if (!$patientStmt) {
        error_log('Failed to prepare OCC patient lookup: ' . $conn->error);
        http_response_code(500);
        exit('ไม่สามารถดึงข้อมูลผู้ป่วยได้');
    }
    $patientStmt->bind_param('ss', $hn, $an);
    if (!$patientStmt->execute()) {
        error_log('Failed to execute OCC patient lookup: ' . $patientStmt->error);
        $patientStmt->close();
        http_response_code(500);
        exit('ไม่สามารถดึงข้อมูลผู้ป่วยได้');
    }
    $patient = $patientStmt->get_result()->fetch_assoc();
    $patientStmt->close();

    if ($patient) {
        $historyStmt = $conn->prepare(
            'SELECT id, hn, an, fu_date, occ_status, occ_note, recorder_name, record_date
             FROM occ_records
             WHERE hn = ? AND an = ?
             ORDER BY record_date DESC, id DESC'
        );
        if (!$historyStmt) {
            error_log('Failed to prepare OCC history lookup: ' . $conn->error);
            http_response_code(500);
            exit('ไม่สามารถดึงประวัติ OCC ได้');
        }
        $historyStmt->bind_param('ss', $hn, $an);
        if (!$historyStmt->execute()) {
            error_log('Failed to execute OCC history lookup: ' . $historyStmt->error);
            $historyStmt->close();
            http_response_code(500);
            exit('ไม่สามารถดึงประวัติ OCC ได้');
        }
        $history = $historyStmt->get_result();
    }
}

$isCaseClosed = $patient && $patient['status_case'] === 'Closed';
$canEditRecords = canEditOccRecords();
$isReadOnly = $isCaseClosed || !$canEditRecords;
$recorderName = is_string($_SESSION['name'] ?? null) ? $_SESSION['name'] : '';
$recordDate = date('Y-m-d\TH:i');
$caseBmi = '-';
if ($patient && !empty($patient['weight']) && !empty($patient['height']) && (float)$patient['height'] > 0) {
    $heightMeters = (float)$patient['height'] / 100;
    $caseBmi = number_format((float)$patient['weight'] / ($heightMeters * $heightMeters), 2);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>บันทึกอาชีวเวชกรรม (OCC)</title>
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
            cursor: not-allowed; font-size: 0.9rem; font-weight: 600; font-family: 'Prompt',sans-serif;
            color: var(--text-soft); background: #fafafa; transition: all 0.18s ease; text-align: center; height: 100%;
        }
        .disposition-option label i { font-size: 1.5rem; margin-bottom: 2px; }
        .disposition-option input:checked + label { border-color: var(--er-green); background: var(--er-green); color: #fff; box-shadow: 0 4px 14px var(--er-green-20); }
        .disposition-option.dis-discharge input:checked + label { background: #03a103; border-color: #03a103; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.2); }
        .disposition-option.dis-admit input:checked + label { background: #c70202; border-color: #c70202; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.2); }
    </style>
</head>
<body>
<?php
$path_prefix = '../';
if (file_exists('../header_followup.php')) {
    require_once '../header_followup.php';
}
?>

<main class="container-fluid px-4 py-4" style="max-width: 1400px;">
    <?php if (isset($_SESSION['statusMsg'])): ?>
        <script>
            Swal.fire({
                icon: <?php echo json_encode($_SESSION['statusType'] ?? 'error', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                title: <?php echo json_encode(($_SESSION['statusType'] ?? '') === 'success' ? 'สำเร็จ' : 'แจ้งเตือน', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                text: <?php echo json_encode((string)$_SESSION['statusMsg'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                timer: 1500,
                showConfirmButton: false
            });
        </script>
        <?php unset($_SESSION['statusMsg'], $_SESSION['statusType']); ?>
    <?php endif; ?>

    <?php if (!$patient): ?>
        <div class="alert alert-danger text-center p-5 shadow-sm rounded-4">
            <i class="fa-solid fa-circle-exclamation fa-3x mb-3"></i>
            <h4 class="font-prompt fw-bold">ไม่พบข้อมูลผู้ป่วย</h4>
            <p>กรุณาตรวจสอบ HN และ AN อีกครั้ง</p>
            <a href="indexOCC.php" class="btn btn-outline-danger mt-2 rounded-pill px-4">กลับหน้ารายการ OCC</a>
        </div>
        <?php exit(); ?>
    <?php else: ?>
        <?php if ($isCaseClosed): ?>
            <div class="alert alert-secondary text-center mb-4 shadow-sm rounded-3" style="border: 1px dashed #6c757d;">
                <i class="fa-solid fa-lock me-2"></i><strong>เคสถูกปิดแล้ว (Closed):</strong> ไม่สามารถเพิ่มหรือแก้ไขข้อมูลได้
            </div>
        <?php elseif (!$canEditRecords): ?>
            <div class="alert alert-warning text-center mb-4 shadow-sm rounded-3">
                <i class="fa-solid fa-lock me-2"></i><strong>คำเตือน:</strong> บัญชีนี้ไม่มีสิทธิ์บันทึกหรือแก้ไขข้อมูล OCC
            </div>
        <?php endif; ?>

        <?php if (!$isCaseClosed && $patient['status'] !== 'Discharged'): ?>
            <div class="alert alert-warning text-center mb-4 border-warning shadow-sm rounded-3">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                <strong>คำเตือน:</strong> ผู้ป่วยรายนี้สถานะปัจจุบันคือ
                <span class="badge bg-warning text-dark"><?php echo htmlspecialchars((string)($patient['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                (ยังไม่ใช่ Discharged)
            </div>
        <?php endif; ?>

        <div class="patient-info-box d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h3 class="fw-bold text-dark mb-0"><i class="fa-solid fa-user-doctor text-theme me-2"></i><?php echo htmlspecialchars((string)$patient['patient_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge bg-light text-dark border px-3 py-2 fs-6">HN: <?php echo htmlspecialchars($hn, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="badge bg-theme-light text-theme border px-3 py-2 fs-6" style="border-color: var(--v-primary) !important;">AN: <?php echo htmlspecialchars($an, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="text-muted small ms-2"><i class="fa-solid fa-calendar-check me-1"></i> วันที่จำหน่าย: <?php echo !empty($patient['discharge_date']) ? date('d/m/Y H:i', strtotime($patient['discharge_date'])) : '-'; ?></span>
                </div>
                <div class="row g-3 text-muted" style="font-size: 0.95rem;">
                    <div class="col-auto"><i class="fa-solid fa-child text-secondary me-1"></i> <strong>อายุ:</strong> <?php echo htmlspecialchars((string)($patient['age'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> ปี</div>
                    <div class="col-auto"><i class="fa-solid fa-phone text-secondary me-1"></i> <strong>โทร:</strong> <?php echo htmlspecialchars((string)($patient['phone'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-auto"><i class="fa-solid fa-calculator text-secondary me-1"></i> <strong>BMI:</strong> <span class="text-danger fw-bold"><?php echo $caseBmi; ?></span></div>
                    <div class="col-auto">
                        <i class="fa-solid fa-stethoscope text-primary me-1"></i> <strong>วินิจฉัยสุดท้าย:</strong>
                        <span class="fw-bold text-danger"><?php echo htmlspecialchars((string)($patient['final_diagnosis'] ?: $patient['last_note_details'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
            </div>
            <div class="text-md-end">
                <a href="<?php echo $path_prefix; ?>OCC/indexOCC.php" class="btn btn-outline-theme px-4 py-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> กลับรายการ
                </a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5 mb-4 order-lg-2">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold text-dark py-3 border-bottom d-flex justify-content-between align-items-center">
                        <div><i class="fa-solid fa-history me-2 text-theme"></i>ประวัติการติดตาม (OCC)</div>
                        <span class="badge bg-light text-dark border shadow-sm">ทั้งหมด <?php echo $history ? $history->num_rows : 0; ?> รายการ</span>
                    </div>
                    <div class="card-body bg-theme-light" style="max-height: 800px; overflow-y: auto;">
                        <?php if ($history && $history->num_rows > 0): ?>
                            <div class="timeline mt-2">
                            <?php while ($record = $history->fetch_assoc()): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="fw-bold text-theme">
                                        <?php echo date('d/m/Y', strtotime($record['record_date'])); ?>
                                        <small class="text-muted ms-2 fw-normal"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($record['record_date'])); ?> น.</small>
                                    </div>
                                    <div class="card mt-2 border-0 shadow-sm rounded-3 position-relative">
                                        <?php if (!$isCaseClosed && $canEditRecords): ?>
                                            <div class="position-absolute top-0 end-0 mt-2 me-2" style="z-index: 10;">
                                                <a class="btn btn-sm btn-outline-theme border-0" href="edit_occ.php?id=<?php echo (int)$record['id']; ?>" title="แก้ไขประวัตินี้">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <div class="card-body p-3">
                                            <div class="mb-2 pe-4">
                                                <span class="badge bg-theme-light text-theme border border-theme w-100 text-start px-3 py-2 fs-6" style="white-space: normal; line-height: 1.4;">
                                                    <i class="fa-solid fa-clipboard-check me-1"></i> <?php echo htmlspecialchars((string)($record['occ_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($record['fu_date'])): ?>
                                                <div class="small text-secondary bg-light p-2 rounded border mb-2">
                                                    <div><strong>วันที่ Follow-up:</strong> <?php echo date('d/m/Y H:i', strtotime($record['fu_date'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($record['occ_note'])): ?>
                                                <div class="small text-secondary mt-2 bg-light p-2 rounded border border-dashed">
                                                    <strong class="text-dark d-block mb-1"><i class="fa-solid fa-note-sticky me-1 text-warning"></i> บันทึกเพิ่มเติม:</strong>
                                                    <?php echo nl2br(htmlspecialchars((string)$record['occ_note'], ENT_QUOTES, 'UTF-8')); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="mt-3 d-flex justify-content-between align-items-end">
                                                <small class="text-muted"><i class="fa-solid fa-user-pen me-1"></i><?php echo htmlspecialchars((string)$record['recorder_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                <small class="text-muted"><i class="fa-regular fa-calendar-check me-1"></i>บันทึก: <?php echo date('d/m/Y H:i', strtotime($record['record_date'])); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted bg-white rounded-3 border border-dashed shadow-sm">
                                <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
                                <p>ยังไม่มีบันทึกการประเมินอาชีวเวชกรรม</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 order-lg-1">
                <div class="card card-custom shadow border-0 h-100">
                    <div class="card-header-custom">
                        <i class="fa-solid fa-pen-to-square me-2"></i>บันทึกการประเมิน OCC (Follow-up) ครั้งที่ <?php echo ($history ? $history->num_rows : 0) + 1; ?>
                    </div>
                    <div class="card-body p-4">
                        <form action="save_OCC.php" method="POST">
                            <input type="hidden" name="hn" value="<?php echo htmlspecialchars($hn, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="an" value="<?php echo htmlspecialchars($an, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5">
                                    <i class="fa-solid fa-calendar-days me-2"></i>วันที่และเวลาที่มา Follow-up <span class="text-danger">*</span>
                                </label>
                                <input type="datetime-local" name="fu_date" id="fu_date" class="form-control shadow-sm" required <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                <div class="form-text">ระบบเลือกสถานะให้อัตโนมัติจากวันที่จำหน่าย: ภายใน 14 วัน = มาตรงนัด, เกิน 14 วัน = มาไม่ตรงนัด</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5">
                                    <i class="fa-solid fa-list-check me-2"></i>ผลการติดตามผู้ป่วย
                                </label>
                                <input type="hidden" id="occ_status" name="occ_status">
                                <div class="disposition-row">
                                    <div class="disposition-option dis-discharge">
                                        <input type="radio" id="occ_on_time" disabled>
                                        <label for="occ_on_time"><i class="fa-solid fa-check"></i>มาตรงนัด</label>
                                    </div>
                                    <div class="disposition-option dis-admit">
                                        <input type="radio" id="occ_late" disabled>
                                        <label for="occ_late"><i class="fa-solid fa-circle-xmark"></i>มาไม่ตรงนัด</label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-notes-medical me-2"></i>บันทึกเพิ่มเติม <span class="text-danger">*</span></label>
                                <textarea name="occ_note" id="occ_note" class="form-control bg-light p-3" rows="2" placeholder="ระบุอาการ, แผนการรักษา หรือรายละเอียดเพิ่มเติม..." <?php echo $isReadOnly ? 'disabled' : ''; ?>></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-signature me-2"></i>การลงชื่อและเวลา</label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                                        <div class="input-group shadow-sm">
                                            <span class="input-group-text bg-light"><i class="fa-solid fa-user-pen text-muted"></i></span>
                                            <input type="text" name="recorder_name" class="form-control" maxlength="255" required placeholder="กรอกชื่อแพทย์/พยาบาล" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">เวลาบันทึก <span class="text-danger">*</span></label>
                                        <input type="datetime-local" name="record_date" class="form-control shadow-sm" value="<?php echo htmlspecialchars($recordDate, ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            </div>

                            <?php if ($isCaseClosed): ?>
                                <button type="button" class="btn btn-secondary btn-lg w-100 shadow mt-2" disabled>
                                    <i class="fa-solid fa-lock me-2"></i> เคสถูกปิดแล้ว (Closed)
                                </button>
                            <?php else: ?>
                                <button type="submit" name="submit_occ" class="btn btn-theme btn-lg w-100 shadow mt-2" <?php echo !$canEditRecords ? 'disabled' : ''; ?>>
                                    <i class="fa-solid fa-save me-2"></i> บันทึกผลการประเมิน
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script>
    const dischargeDate = <?php echo json_encode($patient['discharge_date'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const followupInput = document.getElementById('fu_date');
    const occStatusInput = document.getElementById('occ_status');
    const onTimeRadio = document.getElementById('occ_on_time');
    const lateRadio = document.getElementById('occ_late');

    function updateOccStatus() {
        const value = followupInput.value;
        if (!value) {
            followupInput.setCustomValidity('');
            occStatusInput.value = '';
            onTimeRadio.checked = false;
            lateRadio.checked = false;
            return;
        }

        const followupTime = parseWallClock(value);
        const dischargeTime = parseWallClock(dischargeDate);
        if (!Number.isFinite(followupTime) || !Number.isFinite(dischargeTime)) {
            followupInput.setCustomValidity('ไม่พบวันที่จำหน่ายหรือวันที่ Follow-up ที่ถูกต้อง');
            occStatusInput.value = '';
            onTimeRadio.checked = false;
            lateRadio.checked = false;
            return;
        }

        if (followupTime < dischargeTime) {
            followupInput.setCustomValidity('วันที่ Follow-up ต้องไม่ก่อนวันจำหน่าย');
            occStatusInput.value = '';
            onTimeRadio.checked = false;
            lateRadio.checked = false;
            return;
        }

        followupInput.setCustomValidity('');
        const status = followupTime - dischargeTime <= 14 * 24 * 60 * 60 * 1000 ? 'มาตรงนัด' : 'มาไม่ตรงนัด';
        occStatusInput.value = status;
        onTimeRadio.checked = status === 'มาตรงนัด';
        lateRadio.checked = status === 'มาไม่ตรงนัด';
    }

    followupInput.addEventListener('change', updateOccStatus);
    followupInput.addEventListener('input', updateOccStatus);

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
</script>
</body>
</html>
