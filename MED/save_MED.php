<?php
session_start();
date_default_timezone_set('Asia/Bangkok');
require_once '../database/db_conn.php';
require_once __DIR__ . '/med_followup_helpers.php';

// [ถูกต้อง] เช็คปุ่ม submit_med
if (isset($_POST['submit_med'])) {
    $hn = is_string($_POST['hn'] ?? null) ? $_POST['hn'] : '';
    $an = is_string($_POST['an'] ?? null) ? $_POST['an'] : '';

    if (!ensureMedFollowupColumns($conn)) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'ไม่สามารถเตรียมข้อมูลการติดตาม MED ได้';
        header("Location: formMED.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
        exit();
    }

    $caseStmt = $conn->prepare("SELECT c.status_case, w.discharge_date FROM cases c JOIN ward_details w ON w.case_id = c.id WHERE c.hn = ? AND w.an = ? ORDER BY w.id DESC LIMIT 1");
    if (!$caseStmt) {
        error_log('Failed to prepare MED follow-up case lookup: ' . $conn->error);
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'ไม่สามารถตรวจสอบข้อมูลเคสได้';
        header("Location: formMED.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
        exit();
    }
    $caseStmt->bind_param('ss', $hn, $an);
    if (!$caseStmt->execute()) {
        error_log('Failed to look up MED follow-up case: ' . $caseStmt->error);
        $caseStmt->close();
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'ไม่สามารถตรวจสอบข้อมูลเคสได้';
        header("Location: formMED.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
        exit();
    }
    $caseResult = $caseStmt->get_result();
    $caseData = $caseResult->fetch_assoc();
    $caseStmt->close();

    if (!$caseData || $caseData['status_case'] === 'Closed') {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = !$caseData ? 'ไม่พบเคสหรือวันที่จำหน่ายของผู้ป่วย' : 'ไม่สามารถบันทึกได้ เนื่องจากเคสถูกปิดแล้ว';
        header("Location: formMED.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
        exit();
    }

    $followup = getMedFollowupDetails($_POST['fu_date'] ?? null, $caseData['discharge_date'] ?? null);
    if (!$followup) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'กรุณาระบุวัน Follow-up ที่ไม่ก่อนวันจำหน่าย และตรวจสอบวันจำหน่ายของเคส';
        header("Location: formMED.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
        exit();
    }

    try {
        //$next_med_date = !empty($_POST['next_med_date']) ? $_POST['next_med_date'] : NULL;
        $occ = $_POST['occupational'] ?? '0';
        $pmr = $_POST['pmr'] ?? '0';
        $med_note = $_POST['med_note'] ?? '';
        $cpk = $_POST['cpk'] ?? '';
        $bun = $_POST['bun'] ?? '';
        $cr = $_POST['cr'] ?? '';
        $eletroride = $_POST['eletroride'] ?? '';
        $recorder_name = $_POST['recorder_name'] ?? '';
        $record_datetime = isset($_POST['record_datetime']) ? str_replace('T', ' ', $_POST['record_datetime']) : '';

        // Insert ลงตาราง med_records
        $sql = "INSERT INTO med_records 
                (hn, an, fu_date, med_status, occupational, pmr, med_note, cpk, bun, cr, eletroride, recorder_name, record_datetime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Failed to prepare MED follow-up insert: ' . $conn->error);
            throw new RuntimeException('ไม่สามารถเตรียมบันทึกข้อมูล MED ได้');
        }
        $stmt->bind_param("sssssssssssss", $hn, $an, $followup['fu_date'], $followup['med_status'], $occ, $pmr, $med_note, $cpk, $bun, $cr, $eletroride, $recorder_name, $record_datetime);

        if ($stmt->execute()) {
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'บันทึกข้อมูล MED สำเร็จ';
        } else {
            error_log('Failed to save MED follow-up: ' . $stmt->error);
            $_SESSION['statusType'] = 'error';
            $_SESSION['statusMsg'] = 'เกิดข้อผิดพลาดขณะบันทึกข้อมูล MED';
        }
        $stmt->close();

    } catch (Exception $e) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'Error: ' . $e->getMessage();
    }
    
    // Redirect กลับไปที่หน้า formFollowup.php
    header("Location: formMED.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
    exit();
}
?>