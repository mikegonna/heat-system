<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php'; 

// เปิด Error Reporting เพื่อดักจับปัญหา
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (isset($_POST['update_treatment'])) {
    try {
        $er_id = intval($_POST['id']); // ID ของ er_details

        // --- 1. รับค่าจากฟอร์ม ---
        $hn = $_POST['hn'];
        $patient_id_card = $_POST['patient_id_card'];
        $patient_name = $_POST['patient_name'];
        $phone = $_POST['phone'];
        $age = $_POST['age'];
        $weight = $_POST['weight'];
        $height = $_POST['height'];
        $bmi = $_POST['bmi'];
        $department = $_POST['department'];
        $congenital_disease = $_POST['congenital_disease'];
        
        $person_type = $_POST['person_type'];
        if ($person_type == 'อื่นๆ') $person_type = $_POST['other_person_type'];

        $arrival_time = $_POST['arrival_time'];
        $first_aid = $_POST['first_aid'];
        
        $ct_initial = (isset($_POST['ct_initial']) && $_POST['ct_initial'] !== '') ? $_POST['ct_initial'] : NULL;
        $ct_30min = (isset($_POST['ct_30min']) && $_POST['ct_30min'] !== '') ? $_POST['ct_30min'] : NULL;
        $ct_control = isset($_POST['ct_control']) ? $_POST['ct_control'] : ""; 
        
        $FstDx_select = $_POST['FstDx_select'];
        if ($FstDx_select === 'other') $FstDx = $_POST['FstDx_other']; else $FstDx = $FstDx_select;
        
        $disposition = $_POST['disposition']; 
        $treatment_result = $_POST['treatment_result'];
        
        // รับค่า Admit/Refer/Dead
        $admit_type = isset($_POST['admit_type']) ? $_POST['admit_type'] : null;
        $refer_hospital = isset($_POST['refer_hospital']) ? $_POST['refer_hospital'] : "";
        $death_cause = isset($_POST['death_cause']) ? $_POST['death_cause'] : ""; // เพิ่มการรับค่า

        $cause_factor = $_POST['cause_factor'];
        if ($cause_factor == 'อื่นๆ') $cause_factor = $_POST['other_cause_factor'];

        $risk_factors = isset($_POST['risk_factors']) ? implode(", ", $_POST['risk_factors']) : "ไม่มีปัจจัยเสี่ยงระบุ";

        // --- 2. หา case_id ---
        $find_case = $conn->query("SELECT case_id FROM er_details WHERE id = $er_id");
        if($find_case->num_rows == 0){ throw new Exception("ไม่พบข้อมูล ID นี้ในระบบ"); }
        $case_row = $find_case->fetch_assoc();
        $case_id = $case_row['case_id'];

        // --- 3. Update ตาราง patients ---
        $sql_case = "UPDATE cases SET 
                     patient_id_card=?, hn=?, patient_name=?, phone=?, age=?, 
                     weight=?, height=?, bmi=?, person_type=?, department=?, congenital_disease=?
                     WHERE id=?";
        $stmt1 = $conn->prepare($sql_case);
        $stmt1->bind_param("ssssidddsssi", 
            $patient_id_card, $hn, $patient_name, $phone, $age, 
            $weight, $height, $bmi, $person_type, $department, $congenital_disease, 
            $case_id
        );
        $stmt1->execute();
        $stmt1->close();

        // --- 4. Update ตาราง er_details ---
        // [แก้ไข] เพิ่ม death_cause และลบ ward_building ออก
        $sql_er = "UPDATE er_details SET 
                   cause_factor=?, first_aid=?, arrival_time=?, ct_initial=?, ct_30min=?, ct_control=?,
                   FstDx=?, disposition=?, death_cause=?, admit_type=?, refer_hospital=?,
                   treatment_result=?, risk_factors=? 
                   WHERE id=?";
                   
        $stmt2 = $conn->prepare($sql_er);
        
        // Bind Params: sssddsssssssi (14 ตัว)
        $stmt2->bind_param("sssddsssssssi", 
            $cause_factor, $first_aid, $arrival_time, $ct_initial, $ct_30min, $ct_control,
            $FstDx, $disposition, $death_cause, $admit_type, $refer_hospital,
            $treatment_result, $risk_factors,
            $er_id
        );
        
        $stmt2->execute();
        $stmt2->close();

        // --- SUCCESS ---
        $_SESSION['statusType'] = 'success';
        $_SESSION['statusMsg'] = 'อัปเดตข้อมูลเรียบร้อยแล้ว';
        header("Location: history_central.php");
        exit();

    } catch (Exception $e) {
        // --- ERROR ---
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        header("Location: edit_treatmentER.php?id=" . $_POST['id']);
        exit();
    }
} else {
    header("Location: history_central.php");
    exit();
}
?>