<?php
session_start();
require_once '../database/db_conn.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// [ถูกต้อง] เช็คปุ่ม submit_med
if (isset($_POST['submit_med'])) {
    try {
        $hn = $_POST['hn'];
        $an = $_POST['an'];
        $med_status = $_POST['med_status'];
        
        //$next_med_date = !empty($_POST['next_med_date']) ? $_POST['next_med_date'] : NULL;
        $occ = $_POST['occupational'] ?? '0';
        $pmr = $_POST['pmr'] ?? '0';
        $med_note = $_POST['med_note'];
        $recorder_name = $_POST['recorder_name'];
        $record_datetime = str_replace('T', ' ', $_POST['record_datetime']);

        // Insert ลงตาราง med_records
        $sql = "INSERT INTO med_records 
                (hn, an, med_status, occupational, pmr, med_note, recorder_name, record_datetime) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssss", $hn, $an, $med_status, $occ, $pmr, $med_note, $recorder_name, $record_datetime);

        if ($stmt->execute()) {
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'บันทึกข้อมูล MED สำเร็จ';
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