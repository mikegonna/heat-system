<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../database/db_conn.php';
require_once __DIR__ . '/occ_helpers.php';
if (!canEditOccRecords()) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์แก้ไขข้อมูล OCC');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('รหัสรายการไม่ถูกต้อง');
}

$stmt = $conn->prepare(
    'SELECT o.id, o.hn, o.an, o.fu_date, o.occ_status, o.occ_note, o.recorder_name, o.record_date,
            c.patient_name, c.status_case, w.discharge_date
     FROM occ_records o
     JOIN cases c ON c.hn = o.hn
    JOIN ward_details w ON w.case_id = c.id AND w.an = o.an
    WHERE o.id = ?
    ORDER BY w.id DESC
     LIMIT 1'
);
if (!$stmt) {
    error_log('Failed to prepare OCC record lookup: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถดึงข้อมูล OCC ได้');
}
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    error_log('Failed to execute OCC record lookup: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit('ไม่สามารถดึงข้อมูล OCC ได้');
}
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    http_response_code(404);
    exit('ไม่พบรายการ OCC');
}
$isCaseClosed = $record['status_case'] === 'Closed';
$isModalRequest = ($_GET['modal'] ?? '') === '1';
$backUrl = 'formOCC.php?hn=' . urlencode($record['hn']) . '&an=' . urlencode($record['an']);
$updateUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/OCC/edit_occ.php')), '/') . '/update_occ.php';
?>
<?php if (!$isModalRequest): ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แก้ไขบันทึก OCC</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="occ_status.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f9ff; font-family: 'Sarabun', sans-serif; }
        h1, h2, h3, h4, h5, .btn { font-family: 'Prompt', sans-serif; }
        .text-theme { color: #0284c7 !important; }
    </style>
</head>
<body>
<main class="container py-5" style="max-width: 800px;">
<?php else: ?>
<div class="modal-header bg-primary text-white">
    <div>
        <h5 class="modal-title mb-0">แก้ไขบันทึก OCC</h5>
        <small><?php echo htmlspecialchars((string)$record['patient_name'], ENT_QUOTES, 'UTF-8'); ?> (HN: <?php echo htmlspecialchars((string)$record['hn'], ENT_QUOTES, 'UTF-8'); ?>, AN: <?php echo htmlspecialchars((string)$record['an'], ENT_QUOTES, 'UTF-8'); ?>)</small>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
</div>
<?php endif; ?>
    <?php if (!$isModalRequest): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white"><h5 class="mb-0">แก้ไขบันทึก OCC</h5></div>
        <div class="card-body p-4">
            <p class="text-muted">ผู้ป่วย: <?php echo htmlspecialchars((string)$record['patient_name'], ENT_QUOTES, 'UTF-8'); ?> (HN: <?php echo htmlspecialchars((string)$record['hn'], ENT_QUOTES, 'UTF-8'); ?>, AN: <?php echo htmlspecialchars((string)$record['an'], ENT_QUOTES, 'UTF-8'); ?>)</p>
            <?php if ($isCaseClosed): ?>
                <div class="alert alert-secondary"><i class="fa-solid fa-lock me-2"></i>เคสถูกปิดแล้ว ไม่สามารถแก้ไขข้อมูลได้</div>
            <?php endif; ?>
            <form action="<?php echo htmlspecialchars($updateUrl, ENT_QUOTES, 'UTF-8'); ?>" method="POST" data-occ-edit-form>
    <?php else: ?>
    <form action="<?php echo htmlspecialchars($updateUrl, ENT_QUOTES, 'UTF-8'); ?>" method="POST" data-occ-edit-form class="d-flex flex-column flex-grow-1">
        <div class="modal-body">
            <div data-record-edit-message></div>
            <?php if ($isCaseClosed): ?>
                <div class="alert alert-secondary"><i class="fa-solid fa-lock me-2"></i>เคสถูกปิดแล้ว ไม่สามารถแก้ไขข้อมูลได้</div>
            <?php endif; ?>
    <?php endif; ?>
                <input type="hidden" name="id" value="<?php echo (int)$record['id']; ?>">
                <input type="hidden" name="hn" value="<?php echo htmlspecialchars((string)$record['hn'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="an" value="<?php echo htmlspecialchars((string)$record['an'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                    <label for="fu_date" class="form-label fw-bold">วันที่ Follow-up <span class="text-danger">*</span></label>
                    <input type="datetime-local" id="fu_date" name="fu_date" class="form-control" data-occ-followup-date data-discharge-date="<?php echo htmlspecialchars((string)($record['discharge_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo !empty($record['fu_date']) ? date('Y-m-d\TH:i', strtotime($record['fu_date'])) : ''; ?>" required <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5">
                        <i class="fa-solid fa-list-check me-2"></i>ผลการติดตามผู้ป่วย
                    </label>
                    <input type="hidden" id="occ_status" name="occ_status" data-occ-status value="<?php echo htmlspecialchars((string)$record['occ_status'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="disposition-row">
                        <div class="disposition-option dis-discharge">
                            <input type="radio" id="edit_occ_on_time" data-occ-status-value="มาตรงนัด" disabled>
                            <label for="edit_occ_on_time"><i class="fa-solid fa-check"></i>มาตรงนัด</label>
                        </div>
                        <div class="disposition-option dis-admit">
                            <input type="radio" id="edit_occ_late" data-occ-status-value="มาไม่ตรงนัด" disabled>
                            <label for="edit_occ_late"><i class="fa-solid fa-circle-xmark"></i>มาไม่ตรงนัด</label>
                        </div>
                    </div>
                    <div class="form-text">ระบบคำนวณจากวันและเวลาจำหน่าย: ภายใน 14 วัน = มาตรงนัด, เกิน 14 วัน = มาไม่ตรงนัด</div>
                </div>
                <div class="mb-3">
                    <label for="occ_note" class="form-label fw-bold">บันทึก OCC</label>
                    <textarea id="occ_note" name="occ_note" class="form-control" rows="4" <?php echo $isCaseClosed ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)$record['occ_note'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="recorder_name" class="form-label fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                        <input type="text" id="recorder_name" name="recorder_name" class="form-control" maxlength="255" value="<?php echo htmlspecialchars((string)$record['recorder_name'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-6">
                        <label for="record_date" class="form-label fw-bold">วันเวลาที่บันทึก <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="record_date" name="record_date" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($record['record_date'])); ?>" required <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <?php if (!$isModalRequest): ?>
                <div class="d-flex gap-2 mt-4">
                    <?php if (!$isCaseClosed): ?>
                        <button type="submit" name="update_occ" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>บันทึกการแก้ไข</button>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">กลับหน้าประวัติ</a>
                </div>
                </form>
        </div>
    </div>
</main>
                <?php else: ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <?php if (!$isCaseClosed): ?>
                <button type="submit" name="update_occ" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>บันทึกการแก้ไข</button>
            <?php endif; ?>
        </div>
    </form>
                <?php endif; ?>
<?php if (!$isModalRequest): ?>
<script src="occ_followup_status.js"></script>
<script>window.initOccFollowupStatus(document);</script>
</body>
</html>
<?php endif; ?>
