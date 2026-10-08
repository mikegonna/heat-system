<?php
session_start();
require_once '../database/db_conn.php';

// เปิด Error Reporting เพื่อ Debug
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (isset($_POST['submit_discharge'])) {
    try {
        $ward_detail_id = $_POST['ward_detail_id'];
        $case_id = $_POST['case_id']; // รับ case_id มาด้วยเพื่อใช้ตอน Admit
        
        $discharge_status = $_POST['discharge_status']; // Admit, Discharge, Refer, Dead
        $discharge_date = str_replace('T', ' ', $_POST['discharge_date']);
        $discharge_detail = $_POST['discharge_detail'];
        
        // --- 1. รับค่า Final Diagnosis (Dropdown/Input) ---
        $final_diagnosis = NULL;
        if (!empty($_POST['final_diagnosis_select']) && $_POST['final_diagnosis_select'] !== 'other') {
            $final_diagnosis = $_POST['final_diagnosis_select'];
        } elseif (!empty($_POST['final_diagnosis_other'])) {
            $final_diagnosis = $_POST['final_diagnosis_other'];
        } elseif (!empty($_POST['final_diagnosis'])) { 
            // เผื่อกรณี Input ธรรมดา (Fallback)
            $final_diagnosis = $_POST['final_diagnosis'];
        }

        // --- 2. รับค่า Death Cause (สาเหตุการเสียชีวิต) ---
        $death_cause = isset($_POST['death_cause']) ? $_POST['death_cause'] : NULL;
        
        // ตัวแปรอื่นๆ
        $discharge_plan = isset($_POST['discharge_plan']) ? $_POST['discharge_plan'] : NULL;
        $refer_hospital = isset($_POST['refer_hospital']) ? $_POST['refer_hospital'] : NULL;
        $target_admit_type = isset($_POST['admit_type']) ? $_POST['admit_type'] : NULL;
        
        $status_update = $discharge_status; // ค่าเริ่มต้น (Discharge, Dead)

        // --- 3. จัดการ Logic ตามสถานะ ---
        if ($discharge_status == 'Dead') {
            $status_update = 'Dead';
            // เพิ่มรายละเอียดลงใน discharge_detail ด้วยเพื่อความชัดเจน
            if ($death_cause) {
                $discharge_detail .= " [เสียชีวิตสาเหตุ: $death_cause]";
            }
            
        } elseif ($discharge_status == 'Refer') {
            $status_update = 'Refer';
            $discharge_detail .= " (Refer ไป: $refer_hospital)";
            
        } elseif ($discharge_status == 'Admit') {
            // การย้ายแผนก
            $status_update = 'Admitted'; 
            $discharge_status = 'Admit'; // เปลี่ยนคำให้ตรงกับ Database ENUM
            $discharge_detail .= " (ย้ายไป: $target_admit_type)";
        }

        // --- 4. SQL UPDATE ข้อมูลใน Ward Details ---
        $sql = "UPDATE ward_details SET 
                status = ?, 
                discharge_status = ?, 
                discharge_date = ?, 
                discharge_detail = ?, 
                final_diagnosis = ?, 
                discharge_plan = ?,
                death_cause = ? 
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssssi", 
            $status_update, 
            $discharge_status, 
            $discharge_date, 
            $discharge_detail, 
            $final_diagnosis, 
            $discharge_plan,
            $death_cause, 
            $ward_detail_id
        );
        
        if ($stmt->execute()) {
            
            // --- 5. กรณี Admit (ย้ายแผนก) ให้สร้าง Record ใหม่ให้แผนกปลายทาง ---
            if ($status_update == 'Admitted' && !empty($target_admit_type)) {
                // ดึง AN เดิม
                $sql_an = "SELECT an FROM ward_details WHERE id = ?";
                $stmt_an = $conn->prepare($sql_an);
                $stmt_an->bind_param("i", $ward_detail_id);
                $stmt_an->execute();
                $res_an = $stmt_an->get_result();
                $current_an = $res_an->fetch_assoc()['an'];
                $stmt_an->close();

                // หาชื่อแผนกต้นทาง (ผู้ใช้งานปัจจุบัน)
                $sender_id = $_SESSION['user_id'];
                $resUser = $conn->query("SELECT name FROM users WHERE id = '$sender_id'");
                $sender_name = $resUser->fetch_assoc()['name'];

                $Admit_note = "ย้ายจาก " . $sender_name . " : " . $discharge_detail;

                // สร้าง Record ใหม่ สถานะ AdmitWait
                $sql_new = "INSERT INTO ward_details 
                            (case_id, an, admit_type, admit_date, status, discharge_detail) 
                            VALUES (?, ?, ?, NOW(), 'AdmitWait', ?)";
                
                $stmt_new = $conn->prepare($sql_new);
                $stmt_new->bind_param("isss", $case_id, $current_an, $target_admit_type, $Admit_note);
                $stmt_new->execute();
                $stmt_new->close();
            }

            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'จำหน่ายผู้ป่วยเรียบร้อยแล้ว';
        } else {
            throw new Exception($stmt->error);
        }
        
        $stmt->close();

        // Redirect กลับหน้าเดิม (Referer)
        if (isset($_SERVER["HTTP_REFERER"])) {
            header("Location: " . $_SERVER["HTTP_REFERER"]);
        } else {
            // Fallback
            if (isset($_SESSION['name']) && $_SESSION['name'] == 'ICU') {
                header("Location: indexICU.php");
            } else {
                header("Location: indexWard.php");
            }
        }
        exit();

    } catch (Exception $e) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = 'Error: ' . $e->getMessage();
        header("Location: " . $_SERVER["HTTP_REFERER"]);
        exit();
    }
} else {
    header("Location: ../login.php");
    exit();
}
?>