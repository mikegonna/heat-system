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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$hn = is_string($_POST['hn'] ?? null) ? trim($_POST['hn']) : '';
$an = is_string($_POST['an'] ?? null) ? trim($_POST['an']) : '';
$fuDate = normalizeOccDateTime($_POST['fu_date'] ?? null);
$recordDate = normalizeOccDateTime($_POST['record_date'] ?? null);
$note = is_string($_POST['occ_note'] ?? null) ? trim($_POST['occ_note']) : '';
$recorderName = is_string($_POST['recorder_name'] ?? null) ? trim($_POST['recorder_name']) : '';
$redirect = 'Location: formOCC.php?hn=' . urlencode($hn) . '&an=' . urlencode($an);
$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
$respond = static function ($type, $message, $httpStatus = 200) use ($isAjaxRequest, $redirect) {
    if (!$isAjaxRequest && $httpStatus >= 500) {
        http_response_code($httpStatus);
        exit($message);
    }

    if ($isAjaxRequest) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => $type === 'success', 'message' => $message],
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
        exit();
    }

    $_SESSION['statusType'] = $type;
    $_SESSION['statusMsg'] = $message;
    header($redirect);
    exit();
};

if (
    !isset($_POST['update_occ'])
    || !$id
    || $id < 1
    || $hn === ''
    || $an === ''
    || !$fuDate
    || !$recordDate
    || $recorderName === ''
) {
    $respond('error', 'กรุณากรอกข้อมูล OCC ที่จำเป็นให้ครบถ้วนและตรวจสอบรูปแบบวันที่', 422);
}

$caseStmt = $conn->prepare(
    'SELECT c.status_case, w.discharge_date
     FROM occ_records o
     JOIN cases c ON c.hn = o.hn
     JOIN ward_details w ON w.case_id = c.id AND w.an = o.an
     WHERE o.id = ? AND o.hn = ? AND o.an = ?
     ORDER BY w.id DESC
     LIMIT 1'
);
if (!$caseStmt) {
    error_log('Failed to prepare OCC edit case lookup: ' . $conn->error);
    $respond('error', 'ไม่สามารถตรวจสอบข้อมูลเคสได้', 500);
}
$caseStmt->bind_param('iss', $id, $hn, $an);
if (!$caseStmt->execute()) {
    error_log('Failed to execute OCC edit case lookup: ' . $caseStmt->error);
    $caseStmt->close();
    $respond('error', 'ไม่สามารถตรวจสอบข้อมูลเคสได้', 500);
}
$case = $caseStmt->get_result()->fetch_assoc();
$caseStmt->close();

if (!$case || $case['status_case'] === 'Closed') {
    $respond('error', !$case ? 'ไม่พบรายการ OCC ที่ต้องการแก้ไข' : 'ไม่สามารถแก้ไขได้ เนื่องจากเคสถูกปิดแล้ว', !$case ? 404 : 422);
}

$status = getOccFollowupStatus($_POST['fu_date'] ?? null, $case['discharge_date'] ?? null);
if (!$status) {
    $respond('error', 'กรุณาระบุวันที่ Follow-up ที่ไม่ก่อนวันจำหน่าย และตรวจสอบวันจำหน่ายของเคส', 422);
}

$stmt = $conn->prepare(
    'UPDATE occ_records
     SET fu_date = ?, occ_status = ?, occ_note = ?, recorder_name = ?, record_date = ?
     WHERE id = ? AND hn = ? AND an = ?'
);
if (!$stmt) {
    error_log('Failed to prepare OCC update: ' . $conn->error);
    $respond('error', 'ไม่สามารถเตรียมแก้ไขข้อมูล OCC ได้', 500);
}
$stmt->bind_param('sssssiss', $fuDate, $status, $note, $recorderName, $recordDate, $id, $hn, $an);
if (!$stmt->execute()) {
    error_log('Failed to update OCC record: ' . $stmt->error);
    $stmt->close();
    $respond('error', 'เกิดข้อผิดพลาดขณะบันทึกการแก้ไข OCC', 500);
}
$stmt->close();

$respond('success', 'แก้ไขข้อมูล OCC สำเร็จ');
