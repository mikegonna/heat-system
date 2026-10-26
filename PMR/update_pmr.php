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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$hn = is_string($_POST['hn'] ?? null) ? trim($_POST['hn']) : '';
$an = is_string($_POST['an'] ?? null) ? trim($_POST['an']) : '';
$fuDate = normalizeOccDateTime($_POST['fu_date'] ?? null);
$recordDate = normalizeOccDateTime($_POST['record_datetime'] ?? null);
$rteValue = $_POST['rte'] ?? null;
$rte = is_string($rteValue) && in_array($rteValue, ['0', '1'], true) ? (int)$rteValue : null;
$nextPmrDate = empty($_POST['next_pmr_date']) ? null : normalizePmrDate($_POST['next_pmr_date']);
$note = is_string($_POST['pmr_note'] ?? null) ? trim($_POST['pmr_note']) : '';
$recorderName = is_string($_POST['recorder_name'] ?? null) ? trim($_POST['recorder_name']) : '';
$redirect = 'Location: formPMR.php?hn=' . urlencode($hn) . '&an=' . urlencode($an);
$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
$respond = static function ($success, $message, $httpStatus = 200) use ($isAjaxRequest, $redirect) {
    if ($isAjaxRequest) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => $success, 'message' => $message],
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
        exit();
    }

    if ($httpStatus >= 500) {
        http_response_code($httpStatus);
        exit($message);
    }

    $_SESSION['statusType'] = $success ? 'success' : 'error';
    $_SESSION['statusMsg'] = $message;
    header($redirect);
    exit();
};

if (
    !isset($_POST['update_pmr'])
    || !$id
    || $id < 1
    || $hn === ''
    || $an === ''
    || !$fuDate
    || !$recordDate
    || $rte === null
    || (!empty($_POST['next_pmr_date']) && !$nextPmrDate)
    || $recorderName === ''
) {
    $respond(false, 'กรุณากรอกข้อมูล PMR ที่จำเป็นให้ครบถ้วนและตรวจสอบรูปแบบวันที่', 422);
}

$caseStmt = $conn->prepare(
    'SELECT c.status_case, w.discharge_date
     FROM pmr_records p
     JOIN ward_details w ON w.an = p.an
     JOIN cases c ON c.id = w.case_id AND c.hn = p.hn
     WHERE p.id = ? AND p.hn = ? AND p.an = ?
     ORDER BY w.id DESC
     LIMIT 1'
);
if (!$caseStmt) {
    error_log('Failed to prepare PMR edit case lookup: ' . $conn->error);
    $respond(false, 'ไม่สามารถตรวจสอบข้อมูลเคสได้', 500);
}
$caseStmt->bind_param('iss', $id, $hn, $an);
if (!$caseStmt->execute()) {
    error_log('Failed to execute PMR edit case lookup: ' . $caseStmt->error);
    $caseStmt->close();
    $respond(false, 'ไม่สามารถตรวจสอบข้อมูลเคสได้', 500);
}
$case = $caseStmt->get_result()->fetch_assoc();
$caseStmt->close();

if (!$case || $case['status_case'] === 'Closed') {
    $respond(false, !$case ? 'ไม่พบรายการ PMR ที่ต้องการแก้ไข' : 'ไม่สามารถแก้ไขได้ เนื่องจากเคสถูกปิดแล้ว', !$case ? 404 : 422);
}

$status = getOccFollowupStatus($_POST['fu_date'] ?? null, $case['discharge_date'] ?? null);
if (!$status) {
    $respond(false, 'กรุณาระบุวันที่ Follow-up ที่ไม่ก่อนวันจำหน่าย และตรวจสอบวันจำหน่ายของเคส', 422);
}

$stmt = $conn->prepare(
    'UPDATE pmr_records
     SET fu_date = ?, record_datetime = ?, pmr_status = ?, rte = ?, next_pmr_date = ?, pmr_note = ?, recorder_name = ?
     WHERE id = ? AND hn = ? AND an = ?'
);
if (!$stmt) {
    error_log('Failed to prepare PMR update: ' . $conn->error);
    $respond(false, 'ไม่สามารถเตรียมแก้ไขข้อมูล PMR ได้', 500);
}
$stmt->bind_param('sssisssiss', $fuDate, $recordDate, $status, $rte, $nextPmrDate, $note, $recorderName, $id, $hn, $an);
if (!$stmt->execute()) {
    error_log('Failed to update PMR record: ' . $stmt->error);
    $stmt->close();
    $respond(false, 'เกิดข้อผิดพลาดขณะบันทึกการแก้ไข PMR', 500);
}
$stmt->close();

$respond(true, 'แก้ไขข้อมูล PMR สำเร็จ');
