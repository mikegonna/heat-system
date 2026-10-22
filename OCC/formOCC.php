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
        'SELECT c.patient_name, c.age, c.department, c.status_case, w.discharge_date, w.status
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>บันทึกอาชีวเวชกรรม (OCC)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f9ff; color: #0f172a; font-family: 'Sarabun', sans-serif; }
        h1, h2, h3, h4, h5, .btn { font-family: 'Prompt', sans-serif; }
        .theme-text { color: #0369a1; }
        .patient-card, .record-card { border: 0; border-radius: 16px; box-shadow: 0 4px 18px rgba(15, 23, 42, .06); }
        .patient-card { border-left: 5px solid #0284c7; }
        .section-title { color: #0369a1; border-bottom: 1px solid #e2e8f0; padding-bottom: .6rem; }
    </style>
</head>
<body>
<?php
$path_prefix = '../';
if (file_exists('../header_followup.php')) {
    require_once '../header_followup.php';
}
?>

<main class="container py-4" style="max-width: 1200px;">
    <?php if (isset($_SESSION['statusMsg'])): ?>
        <div class="alert alert-<?php echo ($_SESSION['statusType'] ?? '') === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars((string)$_SESSION['statusMsg'], ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
        </div>
        <?php unset($_SESSION['statusMsg'], $_SESSION['statusType']); ?>
    <?php endif; ?>

    <?php if (!$patient): ?>
        <div class="alert alert-danger text-center p-5">
            <i class="fa-solid fa-circle-exclamation fa-2x mb-3"></i>
            <h4>ไม่พบข้อมูลผู้ป่วย</h4>
            <p>กรุณาตรวจสอบ HN และ AN อีกครั้ง</p>
            <a href="indexOCC.php" class="btn btn-outline-danger">กลับหน้ารายการ OCC</a>
        </div>
    <?php else: ?>
        <?php if ($isCaseClosed): ?>
            <div class="alert alert-secondary"><i class="fa-solid fa-lock me-2"></i>เคสถูกปิดแล้ว ไม่สามารถเพิ่มหรือแก้ไขข้อมูลได้</div>
        <?php elseif (!$canEditRecords): ?>
            <div class="alert alert-warning"><i class="fa-solid fa-lock me-2"></i>บัญชีนี้ไม่มีสิทธิ์บันทึกหรือแก้ไขข้อมูล OCC</div>
        <?php endif; ?>

        <section class="patient-card bg-white p-4 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <h3 class="mb-2"><?php echo htmlspecialchars((string)$patient['patient_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-light text-dark border fs-6">HN: <?php echo htmlspecialchars($hn, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="badge bg-info text-dark fs-6">AN: <?php echo htmlspecialchars($an, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="badge bg-light text-dark border">อายุ: <?php echo htmlspecialchars((string)($patient['age'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="badge bg-light text-dark border">หน่วยงาน: <?php echo htmlspecialchars((string)($patient['department'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="text-md-end text-muted">
                    <div>วันที่จำหน่าย: <?php echo !empty($patient['discharge_date']) ? date('d/m/Y H:i', strtotime($patient['discharge_date'])) : '-'; ?></div>
                    <div>สถานะ: <?php echo htmlspecialchars((string)($patient['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </section>

        <div class="row g-4">
            <section class="col-lg-7">
                <div class="record-card card h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 theme-text"><i class="fa-solid fa-pen-to-square me-2"></i>บันทึก OCC</h5>
                    </div>
                    <div class="card-body p-4">
                        <form action="save_OCC.php" method="POST">
                            <input type="hidden" name="hn" value="<?php echo htmlspecialchars($hn, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="an" value="<?php echo htmlspecialchars($an, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="mb-3">
                                <label for="fu_date" class="form-label fw-bold">วันที่ Follow-up <span class="text-danger">*</span></label>
                                <input type="datetime-local" id="fu_date" name="fu_date" class="form-control" required <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                            </div>
                            <div class="mb-3">
                                <label for="occ_status" class="form-label fw-bold">สถานะ OCC <span class="text-danger">*</span></label>
                                <input type="text" id="occ_status" name="occ_status" class="form-control" maxlength="255" required <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                            </div>
                            <div class="mb-3">
                                <label for="occ_note" class="form-label fw-bold">บันทึก OCC</label>
                                <textarea id="occ_note" name="occ_note" class="form-control" rows="4" <?php echo $isReadOnly ? 'disabled' : ''; ?>></textarea>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="recorder_name" class="form-label fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                                    <input type="text" id="recorder_name" name="recorder_name" class="form-control" maxlength="255" value="<?php echo htmlspecialchars($recorderName, ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="record_date" class="form-label fw-bold">วันเวลาที่บันทึก <span class="text-danger">*</span></label>
                                    <input type="datetime-local" id="record_date" name="record_date" class="form-control" value="<?php echo htmlspecialchars($recordDate, ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                            <button type="submit" name="submit_occ" class="btn btn-primary w-100 mt-4" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                <i class="fa-solid fa-floppy-disk me-2"></i>บันทึกข้อมูล OCC
                            </button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="col-lg-5">
                <div class="record-card card h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 theme-text"><i class="fa-solid fa-clock-rotate-left me-2"></i>ประวัติ OCC</h5>
                        <span class="badge bg-light text-dark border"><?php echo $history ? $history->num_rows : 0; ?> รายการ</span>
                    </div>
                    <div class="card-body" style="max-height: 720px; overflow-y: auto;">
                        <?php if ($history && $history->num_rows > 0): ?>
                            <?php while ($record = $history->fetch_assoc()): ?>
                                <article class="border rounded-3 p-3 mb-3">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="fw-bold"><?php echo date('d/m/Y H:i', strtotime($record['record_date'])); ?></div>
                                            <div class="small text-muted">Follow-up: <?php echo !empty($record['fu_date']) ? date('d/m/Y H:i', strtotime($record['fu_date'])) : '-'; ?></div>
                                        </div>
                                        <?php if (!$isCaseClosed && canEditOccRecords()): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="edit_occ.php?id=<?php echo (int)$record['id']; ?>" aria-label="แก้ไขรายการ">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fw-bold theme-text mt-3"><?php echo htmlspecialchars((string)($record['occ_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($record['occ_note'])): ?>
                                        <div class="bg-light rounded p-2 mt-2"><?php echo nl2br(htmlspecialchars((string)$record['occ_note'], ENT_QUOTES, 'UTF-8')); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted mt-2"><i class="fa-solid fa-user-pen me-1"></i><?php echo htmlspecialchars((string)$record['recorder_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </article>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">ยังไม่มีประวัติ OCC</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>
</main>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
