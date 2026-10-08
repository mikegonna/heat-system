<?php
session_start();
require_once '../database/db_conn.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (isset($_POST['submit_pmr'])) {
    try {
        $hn = $_POST['hn'];
        $an = $_POST['an'];
        $pmr_status = $_POST['pmr_status'];
        
        // รับค่าวันที่นัด (ถ้าไม่มีจะเป็น NULL)
        $next_pmr_date = !empty($_POST['next_pmr_date']) ? $_POST['next_pmr_date'] : NULL;
        
        $pmr_note = $_POST['pmr_note'];
        $recorder_name = $_POST['recorder_name'];
        $record_datetime = str_replace('T', ' ', $_POST['record_datetime']);

        // Insert ลงตาราง pmr_records
        $sql = "INSERT INTO pmr_records 
                (hn, an, pmr_status, next_pmr_date, pmr_note, recorder_name, record_datetime) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        // Bind parameters (7 ตัว)
        $stmt->bind_param("sssssss", $hn, $an, $pmr_status, $next_pmr_date, $pmr_note, $recorder_name, $record_datetime);

        if ($stmt->execute()) {
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'บันทึกข้อมูล PMR สำเร็จ';
        }
        $stmt->close();

    } catch (Exception $e) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'Error: ' . $e->getMessage();
    }
    
    // ส่งกลับไปหน้าเดิม
    header("Location: formPMR.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
    exit();
}
?>