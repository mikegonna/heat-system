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
    exit('ไม่มีสิทธิ์บันทึกข้อมูล PMR');
}

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

if (
    !isset($_POST['submit_pmr'])
    || $hn === ''
    || $an === ''
    || !$fuDate
    || !$recordDate
    || $rte === null
    || (!empty($_POST['next_pmr_date']) && !$nextPmrDate)
    || $recorderName === ''
) {
    $_SESSION['statusType'] = 'error';
    $_SESSION['statusMsg'] = 'กรุณากรอกข้อมูล PMR ที่จำเป็นให้ครบถ้วนและตรวจสอบรูปแบบวันที่';
    header($redirect);
    exit();
}

$caseStmt = $conn->prepare(
    'SELECT c.status_case, w.discharge_date
     FROM cases c
     JOIN ward_details w ON w.case_id = c.id
     WHERE c.hn = ? AND w.an = ?
     ORDER BY w.id DESC
     LIMIT 1'
);
if (!$caseStmt) {
    error_log('Failed to prepare PMR case lookup: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถตรวจสอบข้อมูลเคสได้');
}
$caseStmt->bind_param('ss', $hn, $an);
if (!$caseStmt->execute()) {
    error_log('Failed to execute PMR case lookup: ' . $caseStmt->error);
    $caseStmt->close();
    http_response_code(500);
    exit('ไม่สามารถตรวจสอบข้อมูลเคสได้');
}
$case = $caseStmt->get_result()->fetch_assoc();
$caseStmt->close();

if (!$case || $case['status_case'] === 'Closed') {
    $_SESSION['statusType'] = 'error';
    $_SESSION['statusMsg'] = !$case ? 'ไม่พบเคสของผู้ป่วยตาม HN และ AN ที่ระบุ' : 'ไม่สามารถบันทึกข้อมูลได้ เนื่องจากเคสถูกปิดแล้ว';
    header($redirect);
    exit();
}

$previousStmt = $conn->prepare(
    'SELECT next_pmr_date
     FROM pmr_records
     WHERE hn = ? AND an = ?
     ORDER BY record_datetime DESC, id DESC
     LIMIT 1'
);
if (!$previousStmt) {
    error_log('Failed to prepare previous PMR appointment lookup: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถตรวจสอบวันนัด PMR ก่อนหน้าได้');
}
$previousStmt->bind_param('ss', $hn, $an);
if (!$previousStmt->execute()) {
    error_log('Failed to execute previous PMR appointment lookup: ' . $previousStmt->error);
    $previousStmt->close();
    http_response_code(500);
    exit('ไม่สามารถตรวจสอบวันนัด PMR ก่อนหน้าได้');
}
$previousRecord = $previousStmt->get_result()->fetch_assoc();
$previousStmt->close();
$isFirstAppointment = !$previousRecord;
$referenceDate = $isFirstAppointment
    ? ($case['discharge_date'] ?? null)
    : ($previousRecord['next_pmr_date'] ?? null);
$status = getPmrFollowupStatus($_POST['fu_date'] ?? null, $referenceDate, $isFirstAppointment);
if (!$status) {
    $_SESSION['statusType'] = 'error';
    $_SESSION['statusMsg'] = !$isFirstAppointment && empty($referenceDate)
        ? 'ไม่พบวันที่นัดครั้งถัดไปในบันทึก PMR ก่อนหน้า กรุณาแก้ไขบันทึกก่อนจึงจะบันทึกครั้งนี้ได้'
        : 'กรุณาตรวจสอบวันที่ Follow-up และวันนัด PMR ก่อนหน้า';
    header($redirect);
    exit();
}

$stmt = $conn->prepare(
    'INSERT INTO pmr_records (hn, an, pmr_status, RTE, next_pmr_date, pmr_note, recorder_name, fu_date, record_datetime)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    error_log('Failed to prepare PMR insert: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถเตรียมบันทึกข้อมูล PMR ได้');
}
$stmt->bind_param('sssisssss', $hn, $an, $status, $rte, $nextPmrDate, $note, $recorderName, $fuDate, $recordDate);
if (!$stmt->execute()) {
    error_log('Failed to save PMR record: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit('เกิดข้อผิดพลาดขณะบันทึกข้อมูล PMR');
}
$stmt->close();

$_SESSION['statusType'] = 'success';
$_SESSION['statusMsg'] = 'บันทึกข้อมูล PMR สำเร็จ';
header($redirect);
exit();
