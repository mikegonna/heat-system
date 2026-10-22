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
    exit('ไม่มีสิทธิ์บันทึกข้อมูล OCC');
}

$hn = is_string($_POST['hn'] ?? null) ? trim($_POST['hn']) : '';
$an = is_string($_POST['an'] ?? null) ? trim($_POST['an']) : '';
$fuDate = normalizeOccDateTime($_POST['fu_date'] ?? null);
$recordDate = normalizeOccDateTime($_POST['record_date'] ?? null);
$status = is_string($_POST['occ_status'] ?? null) ? trim($_POST['occ_status']) : '';
$note = is_string($_POST['occ_note'] ?? null) ? trim($_POST['occ_note']) : '';
$recorderName = is_string($_POST['recorder_name'] ?? null) ? trim($_POST['recorder_name']) : '';

$redirect = 'Location: formOCC.php?hn=' . urlencode($hn) . '&an=' . urlencode($an);
if (!isset($_POST['submit_occ']) || $hn === '' || $an === '' || !$fuDate || !$recordDate || $status === '' || $recorderName === '') {
    $_SESSION['statusType'] = 'error';
    $_SESSION['statusMsg'] = 'กรุณากรอกข้อมูล OCC ที่จำเป็นให้ครบถ้วนและตรวจสอบรูปแบบวันที่';
    header($redirect);
    exit();
}

$caseStmt = $conn->prepare(
    'SELECT c.status_case
     FROM cases c
     JOIN ward_details w ON w.case_id = c.id
     WHERE c.hn = ? AND w.an = ?
     ORDER BY w.id DESC
     LIMIT 1'
);
if (!$caseStmt) {
    error_log('Failed to prepare OCC case lookup: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถตรวจสอบข้อมูลเคสได้');
}
$caseStmt->bind_param('ss', $hn, $an);
if (!$caseStmt->execute()) {
    error_log('Failed to execute OCC case lookup: ' . $caseStmt->error);
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

$stmt = $conn->prepare(
    'INSERT INTO occ_records (hn, an, fu_date, occ_status, occ_note, recorder_name, record_date)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    error_log('Failed to prepare OCC insert: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถเตรียมบันทึกข้อมูล OCC ได้');
}
$stmt->bind_param('sssssss', $hn, $an, $fuDate, $status, $note, $recorderName, $recordDate);
if (!$stmt->execute()) {
    error_log('Failed to save OCC record: ' . $stmt->error);
    $stmt->close();
    $_SESSION['statusType'] = 'error';
    $_SESSION['statusMsg'] = 'เกิดข้อผิดพลาดขณะบันทึกข้อมูล OCC';
    header($redirect);
    exit();
}
$stmt->close();

$_SESSION['statusType'] = 'success';
$_SESSION['statusMsg'] = 'บันทึกข้อมูล OCC สำเร็จ';
header($redirect);
exit();
