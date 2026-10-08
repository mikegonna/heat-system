<?php
session_start();
require_once '../database/db_conn.php';

// เปิด Error Reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (isset($_POST['submit_visit'])) {
    try {
        // รับค่าพื้นฐาน
        $hn = $_POST['hn'];
        $an = $_POST['an'];
        $recorder_name = $_POST['recorder_name'];
        $record_datetime = str_replace('T', ' ', $_POST['record_datetime']);
        $next_visit_date = !empty($_POST['next_visit_date']) ? $_POST['next_visit_date'] : NULL;

        $urine_color = isset($_POST['urine_color']) ? $_POST['urine_color'] : '';
        $body_temperature = !empty($_POST['body_temperature']) ? $_POST['body_temperature'] : NULL;
        $activity_details = isset($_POST['activity_details']) ? $_POST['activity_details'] : '';

        $obs_list = isset($_POST['obs']) ? implode(', ', $_POST['obs']) : '-';
        
        $symptoms_list = '-';
        $symptoms_other = '';

        if (isset($_POST['symptoms'])) {
            $symptoms_arr = $_POST['symptoms'];
            if (($key = array_search('other', $symptoms_arr)) !== false) {
                unset($symptoms_arr[$key]);
                $symptoms_other = $_POST['symptoms_other'];
            }
            if (!empty($symptoms_arr)) {
                $symptoms_list = implode(', ', $symptoms_arr);
            } else {
                if (!empty($symptoms_other)) $symptoms_list = 'อื่นๆ';
            }
        }

        $sql = "INSERT INTO home_visits 
                (hn, an, checklist_obs, urine_color, body_temperature, activity_details, 
                 symptoms, symptoms_other, recorder_name, record_datetime, next_visit_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssdssssss", 
            $hn, $an, $obs_list, $urine_color, $body_temperature, $activity_details,
            $symptoms_list, $symptoms_other, 
            $recorder_name, $record_datetime, $next_visit_date
        );

        if ($stmt->execute()) {
            // [แก้ไข] เก็บสถานะลง Session แล้ว Redirect แบบ PHP พร้อมส่ง HN/AN กลับไป
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'บันทึกข้อมูลการเยี่ยมบ้านเรียบร้อยแล้ว';
            
            // ส่งกลับไปหน้าเดิม พร้อมข้อมูล HN และ AN เพื่อให้แสดงผลทันที
            header("Location: formVisit.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
            exit();
        } else {
            throw new Exception("Execute failed");
        }
        $stmt->close();

    } catch (Exception $e) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        // ส่งกลับไปหน้าเดิม (กรณี Error)
        header("Location: formVisit.php?hn=" . urlencode($hn) . "&an=" . urlencode($an));
        exit();
    }
}
$conn->close();
?>