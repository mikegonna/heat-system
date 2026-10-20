<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function case_comment_response($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$is_pct = (isset($_SESSION['name']) && strtolower((string)$_SESSION['name']) === 'pct')
    || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$is_viewer = isset($_SESSION['role']) && $_SESSION['role'] === 'viewer';

if (!isset($_SESSION['user_id']) || (!$is_pct && !$is_viewer)) {
    case_comment_response(401, ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบใหม่']);
}

require_once '../database/db_conn.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $case_id = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT);
    if (!$case_id || $case_id < 1) {
        case_comment_response(400, ['success' => false, 'message' => 'ไม่พบรหัสเคสที่ถูกต้อง']);
    }

    $statement = $conn->prepare('SELECT `comment` FROM cases WHERE id = ?');
    if (!$statement) {
        error_log('Failed to prepare case comment read: ' . $conn->error);
        case_comment_response(500, ['success' => false, 'message' => 'ไม่สามารถโหลดบันทึกได้']);
    }
    $statement->bind_param('i', $case_id);
    if (!$statement->execute()) {
        error_log('Failed to load case comment: ' . $statement->error);
        $statement->close();
        case_comment_response(500, ['success' => false, 'message' => 'ไม่สามารถโหลดบันทึกได้']);
    }
    $statement->bind_result($comment);
    if (!$statement->fetch()) {
        $statement->close();
        case_comment_response(404, ['success' => false, 'message' => 'ไม่พบเคสนี้']);
    }
    $statement->close();
    case_comment_response(200, ['success' => true, 'comment' => $comment ?? '']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    case_comment_response(405, ['success' => false, 'message' => 'ไม่รองรับคำขอนี้']);
}

if ($is_viewer || !$is_pct) {
    case_comment_response(403, ['success' => false, 'message' => 'สิทธิ์ Viewer ไม่สามารถแก้ไขบันทึกได้']);
}

$posted_csrf_token = $_POST['csrf_token'] ?? '';
if (
    !is_string($posted_csrf_token)
    || empty($_SESSION['pct_csrf_token'])
    || !hash_equals((string)$_SESSION['pct_csrf_token'], $posted_csrf_token)
) {
    case_comment_response(403, ['success' => false, 'message' => 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณาโหลดหน้าใหม่']);
}

$case_id = filter_input(INPUT_POST, 'case_id', FILTER_VALIDATE_INT);
$comment = $_POST['comment'] ?? null;
if (!$case_id || $case_id < 1 || !is_string($comment)) {
    case_comment_response(400, ['success' => false, 'message' => 'กรุณาตรวจสอบข้อมูลที่ส่งมา']);
}
if (strlen($comment) > 60000) {
    case_comment_response(413, ['success' => false, 'message' => 'บันทึกยาวเกินกำหนด กรุณาลดความยาวแล้วลองอีกครั้ง']);
}

$statement = $conn->prepare('UPDATE cases SET `comment` = ? WHERE id = ?');
if (!$statement) {
    error_log('Failed to prepare case comment save: ' . $conn->error);
    case_comment_response(500, ['success' => false, 'message' => 'ไม่สามารถบันทึกข้อมูลได้']);
}
$statement->bind_param('si', $comment, $case_id);
if (!$statement->execute()) {
    error_log('Failed to save case comment: ' . $statement->error);
    $statement->close();
    case_comment_response(500, ['success' => false, 'message' => 'เกิดข้อผิดพลาดขณะบันทึกข้อมูล']);
}
$statement->close();

case_comment_response(200, ['success' => true, 'comment' => $comment]);
