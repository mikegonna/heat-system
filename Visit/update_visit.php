<?php
session_start();
require_once '../database/db_conn.php';

// เปิด Error Reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (isset($_POST['submit_update'])) {
    try {
        // รับค่า ID และ HN/AN เพื่อ Redirect
        $visit_id = $_POST['visit_id'];
        $hn = $_POST['hn'];
        $an = $_POST['an'];

        $recorder_name = $_POST['recorder_name'];
        $record_datetime = str_replace('T', ' ', $_POST['record_datetime']);
        $next_visit_date = !empty($_POST['next_visit_date']) ? $_POST['next_visit_date'] : NULL;

        $urine_color = isset($_POST['urine_color']) ? $_POST['urine_color'] : '';
        $body_temperature = !empty($_POST['body_temperature']) ? $_POST['body_temperature'] : NULL;
        $activity_details = isset($_POST['activity_details']) ? $_POST['activity_details'] : '';

        // จัดการ Checkbox
        $obs_list = isset($_POST['obs']) ? implode(', ', $_POST['obs']) : '-';
        
        $symptoms_list = '-';
        $symptoms_other = isset($_POST['symptoms_other']) ? $_POST['symptoms_other'] : '';

        if (isset($_POST['symptoms'])) {
            $symptoms_arr = $_POST['symptoms'];
            // ถ้าเลือก 'ไม่มีอาการผิดปกติ' ให้ใช้ค่านั้นเลย
            if (in_array('ไม่มีอาการผิดปกติ', $symptoms_arr)) {
                $symptoms_list = 'ไม่มีอาการผิดปกติ';
            } else {
                $symptoms_list = implode(', ', $symptoms_arr);
            }
        }
        
        // ถ้ามีอาการอื่นๆ และไม่ได้ระบุใน list ให้เติมคำว่า "อื่นๆ" (Optional Logic)
        if (!empty($symptoms_other) && strpos($symptoms_list, 'อื่นๆ') === false && $symptoms_list != 'ไม่มีอาการผิดปกติ') {
             // Logic แล้วแต่ความต้องการ ว่าจะเอาคำว่า 'อื่นๆ' ไปต่อท้ายหรือไม่
        }

        // SQL UPDATE
        $sql = "UPDATE home_visits SET 
                checklist_obs = ?, 
                urine_color = ?, 
                body_temperature = ?, 
                activity_details = ?, 
                symptoms = ?, 
                symptoms_other = ?, 
                recorder_name = ?, 
                record_datetime = ?, 
                next_visit_date = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdssssssi", 
            $obs_list, $urine_color, $body_temperature, $activity_details,
            $symptoms_list, $symptoms_other, 
            $recorder_name, $record_datetime, $next_visit_date, 
            $visit_id
        );

        if ($stmt->execute()) {
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'แก้ไขข้อมูลเรียบร้อยแล้ว';
        } else {
            throw new Exception("Execute failed");
        }
        $stmt->close();

    } catch (Exception $e) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'เกิดข้อผิดพลาดในการแก้ไข: ' . $e->getMessage();
    }

    // ส่งกลับไปหน้าเดิม
    header("Location: formVisit.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
    exit();
}
$conn->close();
?>