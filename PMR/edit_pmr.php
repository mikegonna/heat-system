<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../database/db_conn.php';
require_once __DIR__ . '/pmr_helpers.php';
if (!canEditPmrRecords()) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์แก้ไขข้อมูล PMR');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('รหัสรายการไม่ถูกต้อง');
}

$stmt = $conn->prepare(
    'SELECT p.id, p.hn, p.an, p.pmr_status, p.rte, p.next_pmr_date, p.pmr_note, p.fu_date,
            p.recorder_name, p.record_datetime, c.patient_name, c.status_case, w.discharge_date
     FROM pmr_records p
     JOIN ward_details w ON w.an = p.an
     JOIN cases c ON c.id = w.case_id AND c.hn = p.hn
     WHERE p.id = ?
     ORDER BY w.id DESC
     LIMIT 1'
);
if (!$stmt) {
    error_log('Failed to prepare PMR record lookup: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถดึงข้อมูล PMR ได้');
}
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    error_log('Failed to execute PMR record lookup: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit('ไม่สามารถดึงข้อมูล PMR ได้');
}
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    http_response_code(404);
    exit('ไม่พบรายการ PMR');
}
$isCaseClosed = $record['status_case'] === 'Closed';
$isModalRequest = ($_GET['modal'] ?? '') === '1';
$backUrl = 'formPMR.php?hn=' . urlencode($record['hn']) . '&an=' . urlencode($record['an']);
$updateUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/PMR/edit_pmr.php')), '/') . '/update_pmr.php';
?>
<?php if (!$isModalRequest): ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แก้ไขบันทึก PMR</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../OCC/occ_status.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f3ff; font-family: 'Sarabun', sans-serif; }
        h1, h2, h3, h4, h5, .btn { font-family: 'Prompt', sans-serif; }
        .text-theme { color: #8b5cf6 !important; }
    </style>
</head>
<body>
<main class="container py-5" style="max-width: 800px;">
    <?php $path_prefix = '../'; require '../header_rehab.php'; ?>
<?php else: ?>
<div class="modal-header text-white" style="background:#7c3aed;">
    <div>
        <h5 class="modal-title mb-0">แก้ไขบันทึก PMR</h5>
        <small><?php echo htmlspecialchars((string)$record['patient_name'], ENT_QUOTES, 'UTF-8'); ?> (HN: <?php echo htmlspecialchars((string)$record['hn'], ENT_QUOTES, 'UTF-8'); ?>, AN: <?php echo htmlspecialchars((string)$record['an'], ENT_QUOTES, 'UTF-8'); ?>)</small>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
</div>
<?php endif; ?>
<?php if (!$isModalRequest): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header text-white" style="background:#7c3aed;"><h5 class="mb-0">แก้ไขบันทึก PMR</h5></div>
        <div class="card-body p-4">
            <p class="text-muted">ผู้ป่วย: <?php echo htmlspecialchars((string)$record['patient_name'], ENT_QUOTES, 'UTF-8'); ?> (HN: <?php echo htmlspecialchars((string)$record['hn'], ENT_QUOTES, 'UTF-8'); ?>, AN: <?php echo htmlspecialchars((string)$record['an'], ENT_QUOTES, 'UTF-8'); ?>)</p>
<?php else: ?>
    <form action="<?php echo htmlspecialchars($updateUrl, ENT_QUOTES, 'UTF-8'); ?>" method="POST" data-pmr-edit-form class="d-flex flex-column flex-grow-1">
        <div class="modal-body">
            <div data-record-edit-message></div>
<?php endif; ?>
            <?php if ($isCaseClosed): ?>
                <div class="alert alert-secondary"><i class="fa-solid fa-lock me-2"></i>เคสถูกปิดแล้ว ไม่สามารถแก้ไขข้อมูลได้</div>
            <?php endif; ?>
            <?php if (!$isModalRequest): ?>
            <form action="<?php echo htmlspecialchars($updateUrl, ENT_QUOTES, 'UTF-8'); ?>" method="POST" data-pmr-edit-form>
            <?php endif; ?>
                <input type="hidden" name="id" value="<?php echo (int)$record['id']; ?>">
                <input type="hidden" name="hn" value="<?php echo htmlspecialchars((string)$record['hn'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="an" value="<?php echo htmlspecialchars((string)$record['an'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                    <label for="pmr_fu_date" class="form-label fw-bold">วันที่และเวลาที่มา Follow-up <span class="text-danger">*</span></label>
                    <input type="datetime-local" id="pmr_fu_date" name="fu_date" class="form-control" data-occ-followup-date data-discharge-date="<?php echo htmlspecialchars((string)($record['discharge_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo !empty($record['fu_date']) ? date('Y-m-d\TH:i', strtotime($record['fu_date'])) : ''; ?>" required <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                    <div class="form-text">ระบบคำนวณสถานะจากวันที่จำหน่าย: ภายใน 14 วัน = มาตรงนัด, เกิน 14 วัน = มาไม่ตรงนัด</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-list-check me-2"></i>ผลการฟื้นฟูผู้ป่วย</label>
                    <input type="hidden" name="pmr_status" data-occ-status value="<?php echo htmlspecialchars((string)$record['pmr_status'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="disposition-row">
                        <div class="disposition-option dis-discharge">
                            <input type="radio" id="pmr_edit_on_time" data-occ-status-value="มาตรงนัด" disabled>
                            <label for="pmr_edit_on_time"><i class="fa-solid fa-check"></i>มาตรงนัด</label>
                        </div>
                        <div class="disposition-option dis-admit">
                            <input type="radio" id="pmr_edit_late" data-occ-status-value="มาไม่ตรงนัด" disabled>
                            <label for="pmr_edit_late"><i class="fa-solid fa-circle-xmark"></i>มาไม่ตรงนัด</label>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Return to Exercise (RTE) <span class="text-danger">*</span></label>
                    <div class="disposition-row rte-options">
                        <div class="disposition-option dis-discharge">
                            <input type="radio" id="pmr_edit_rte_yes" name="rte" value="1" required <?php echo (int)$record['rte'] === 1 ? 'checked' : ''; ?> <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                            <label for="pmr_edit_rte_yes"><i class="fa-solid fa-person-running"></i>ใช่</label>
                        </div>
                        <div class="disposition-option dis-admit">
                            <input type="radio" id="pmr_edit_rte_no" name="rte" value="0" <?php echo isset($record['rte']) && (int)$record['rte'] === 0 ? 'checked' : ''; ?> <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                            <label for="pmr_edit_rte_no"><i class="fa-solid fa-ban"></i>ยังไม่ใช่</label>
                        </div>
                    </div>
                </div>
                <!-- <div class="mb-3">
                    <label for="next_pmr_date" class="form-label fw-bold">วันที่นัด PMR ครั้งถัดไป</label>
                    <input type="date" id="next_pmr_date" name="next_pmr_date" class="form-control" value="<?php echo htmlspecialchars((string)($record['next_pmr_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                </div> -->
                <div class="mb-3">
                    <label for="pmr_note" class="form-label fw-bold">บันทึกเพิ่มเติม</label>
                    <textarea id="pmr_note" name="pmr_note" class="form-control" rows="4" <?php echo $isCaseClosed ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)$record['pmr_note'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="recorder_name" class="form-label fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                        <input type="text" id="recorder_name" name="recorder_name" class="form-control" maxlength="255" value="<?php echo htmlspecialchars((string)$record['recorder_name'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-6">
                        <label for="record_datetime" class="form-label fw-bold">เวลาบันทึก <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="record_datetime" name="record_datetime" class="form-control" value="<?php echo !empty($record['record_datetime']) ? date('Y-m-d\TH:i', strtotime($record['record_datetime'])) : ''; ?>" required <?php echo $isCaseClosed ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <?php if (!$isModalRequest): ?>
                <div class="d-flex gap-2 mt-4">
                    <?php if (!$isCaseClosed): ?>
                        <button type="submit" name="update_pmr" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>บันทึกการแก้ไข</button>
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
                <button type="submit" name="update_pmr" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>บันทึกการแก้ไข</button>
            <?php endif; ?>
        </div>
    </form>
                <?php endif; ?>
<?php if (!$isModalRequest): ?>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="../OCC/occ_followup_status.js"></script>
<script>window.initOccFollowupStatus(document);</script>
</body>
</html>
<?php endif; ?>
