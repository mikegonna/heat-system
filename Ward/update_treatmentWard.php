<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../database/db_conn.php';

// เปิด Error Reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$statusType = "";
$statusMsg = "";
// ตั้งค่าเริ่มต้นของ Redirect 
$redirect_url = "history_central.php"; 

if (isset($_POST['update_ward'])) {
    try {
        $ward_id = $_POST['ward_id'];
        $case_id = $_POST['case_id'];

        // รับค่า Target จากฟอร์ม เพื่อให้รู้ว่าควรเด้งกลับไปที่ไหน (เช่น indexICU.php หรือ indexWard.php)
        if (isset($_POST['redirect_target']) && !empty($_POST['redirect_target'])) {
            $redirect_url = $_POST['redirect_target'];
        }

        // 1. รับค่า patients (ข้อมูลทั่วไป)
        $hn = $_POST['hn'];
        $patient_id_card = $_POST['patient_id_card'];
        $patient_name = $_POST['patient_name'];
        
        $sql_case = "UPDATE cases SET hn=?, patient_id_card=?, patient_name=? WHERE id=?";
        $stmt1 = $conn->prepare($sql_case);
        $stmt1->bind_param("sssi", $hn, $patient_id_card, $patient_name, $case_id);
        $stmt1->execute();
        $stmt1->close();

        // 2. รับค่า ward_details (ข้อมูลการรักษา)
        $an = $_POST['an'];
        $admit_date = str_replace('T', ' ', $_POST['admit_date']);
        $doctor_name = isset($_POST['doctor_name']) ? $_POST['doctor_name'] : '';
        
        // จัดการ Initial Diagnosis
        $initial_diagnosis = $_POST['initial_diagnosis_select'];
        if ($initial_diagnosis === 'other') {
            $initial_diagnosis = $_POST['initial_diagnosis_other'];
        }

        // จัดการ ICU Fields (ป้องกันค่าว่าง)
        $icu_core_temp_first = !empty($_POST['icu_core_temp_first']) ? $_POST['icu_core_temp_first'] : NULL;
        $icu_core_temp_30min = !empty($_POST['icu_core_temp_30min']) ? $_POST['icu_core_temp_30min'] : NULL;
        $icu_control_temp = !empty($_POST['icu_control_temp']) ? $_POST['icu_control_temp'] : NULL;
        
        // จัดการ ICU Diagnosis แบบ Dropdown
        $icu_diagnosis = NULL;
        if (isset($_POST['icu_diagnosis_select'])) {
            $icu_diagnosis = $_POST['icu_diagnosis_select'];
            if ($icu_diagnosis === 'other') {
                $icu_diagnosis = $_POST['icu_diagnosis_other'];
            }
        }

        // อัปเดตตาราง ward_details (ไม่ยุ่งกับ discharge status)
        $sql_ward = "UPDATE ward_details SET 
                     an=?, admit_date=?, doctor_name=?, initial_diagnosis=?,
                     icu_core_temp_first=?, icu_core_temp_30min=?, icu_control_temp=?, icu_diagnosis=?
                     WHERE id=?";
                     
        $stmt2 = $conn->prepare($sql_ward);
        
        // Param Types: ssss ddss i (รวม 9 ตัว)
        $stmt2->bind_param("ssssddssi", 
            $an, $admit_date, $doctor_name, $initial_diagnosis,
            $icu_core_temp_first, $icu_core_temp_30min, $icu_control_temp, $icu_diagnosis,
            $ward_id
        );

        if ($stmt2->execute()) {
            // ตั้งค่าสถานะเพื่อนำไปแสดงผลด้านล่างของไฟล์
            $statusType = "success";
            $statusMsg = "บันทึกการแก้ไขข้อมูลผู้ป่วยสำเร็จเรียบร้อย";
        } else {
            throw new Exception($stmt2->error);
        }
        $stmt2->close();

    } catch (Exception $e) {
        $statusType = "error";
        $statusMsg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
} else {
    // ถ้าไม่ได้กด Submit มาให้เด้งกลับเลย
    header("Location: ../dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สถานะการบันทึก</title>
    <!-- นำเข้า SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        body { font-family: 'Sarabun', sans-serif; background-color: #f8fafc; } 
    </style>
</head>
<body>
    <?php if ($statusType == 'success') { ?>
        <script>
            // แสดง SweetAlert เมื่อสำเร็จ
            Swal.fire({
                icon: 'success',
                title: 'สำเร็จ!',
                text: '<?php echo addslashes($statusMsg); ?>',
                confirmButtonColor: '#327c62', // ใช้สีเขียวของ Ward 
                confirmButtonText: 'ตกลง'
            }).then((result) => {
                // หลังจากกดตกลง ให้เด้งกลับไปที่หน้าเดิม
                window.location.href = '<?php echo $redirect_url; ?>'; 
            });
        </script>
    <?php } elseif ($statusType == 'error') { ?>
        <script>
            // แสดง SweetAlert เมื่อมีข้อผิดพลาด
            Swal.fire({
                icon: 'error',
                title: 'ไม่สามารถบันทึกได้',
                text: '<?php echo addslashes($statusMsg); ?>',
                confirmButtonText: 'กลับไปแก้ไข',
                confirmButtonColor: '#e11d48'
            }).then(() => {
                // ย้อนกลับ 1 หน้าเพื่อไม่ให้ข้อมูลฟอร์มหาย
                window.history.back();
            });
        </script>
    <?php } ?>
</body>
</html>