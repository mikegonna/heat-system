<?php
session_start();
require_once '../database/db_conn.php';
date_default_timezone_set('Asia/Bangkok');

// เปิด Error Reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }

// [ปรับปรุง] หาชื่อแผนกต้นทาง และ แผนก (Admit_type) จากผู้ใช้งานปัจจุบัน
$current_user_id = $_SESSION['user_id'];
$sqlUser = "SELECT name, department FROM users WHERE id = ?";
$stmtUser = $conn->prepare($sqlUser);
$stmtUser->bind_param("i", $current_user_id);
$stmtUser->execute();
$resUser = $stmtUser->get_result();
$userData = $resUser->fetch_assoc();

$sender_ward_name = $userData['name']; // ชื่อย่อ เช่น icu, a4 (ใช้สำหรับ Logic Redirect)
$current_ward_type = $userData['department']; // ชื่อเต็ม เช่น Ward ICU, Ward A4 (ใช้บันทึก Admit_type)

$stmtUser->close();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $ward_detail_id = (int)($_POST['ward_detail_id'] ?? 0);
        $case_id = (int)($_POST['case_id'] ?? 0);
        
        $heat_status = $_POST['heat_status'];
        $details = $_POST['details'];
        $treatment_result = $_POST['treatment_result']; 
        $doctor_name = $_POST['doctor_name'];

        if ($treatment_result === 'Discharge') {
            $sql_check_er = "SELECT 1
                             FROM er_details e
                             INNER JOIN ward_details w ON w.case_id = e.case_id
                             WHERE w.id = ?
                             LIMIT 1";
            $stmt_check_er = $conn->prepare($sql_check_er);
            $stmt_check_er->bind_param("i", $ward_detail_id);
            $stmt_check_er->execute();
            $has_er_treatment = $stmt_check_er->get_result()->num_rows > 0;
            $stmt_check_er->close();

            if (!$has_er_treatment) {
                $_SESSION['statusType'] = 'error';
                $_SESSION['statusMsg'] = 'ไม่สามารถ D/C ได้ เนื่องจากเคสนี้ยังไม่มีข้อมูลการรักษาจาก ER กรุณาบันทึกข้อมูลที่ ER ก่อน';
                header("Location: treatmentWard.php?id=$ward_detail_id");
                exit();
            }
        }

        // --- 1. บันทึก Progress Note (ทำเสมอ) ---
        $sql_note = "INSERT INTO ward_progress_notes 
                     (case_id, ward_detail_id, heat_status, details, treatment_result, doctor_name, note_datetime, Admit_type) 
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)";
        
        $stmt = $conn->prepare($sql_note);
        $stmt->bind_param("iisssss", $case_id, $ward_detail_id, $heat_status, $details, $treatment_result, $doctor_name, $current_ward_type);
        $stmt->execute();
        $stmt->close();

        // --- 2. ถ้าเลือก "คงการรักษา" -> จบการทำงาน แค่บันทึก Note ---
        if ($treatment_result == 'Continue') {
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'บันทึกอาการเรียบร้อยแล้ว';
            header("Location: treatmentWard.php?id=$ward_detail_id");
            exit();
        }

        // --- 3. กรณีจำหน่าย/ย้าย/เสียชีวิต ---
        
        // 3.1 รับค่า Final Diagnosis
        $final_diagnosis = NULL;
        $final_select = isset($_POST['final_diagnosis_select']) ? $_POST['final_diagnosis_select'] : '';
        
        if ($final_select === 'other') {
             $final_diagnosis = isset($_POST['final_diagnosis_other']) ? $_POST['final_diagnosis_other'] : NULL;
        } elseif (!empty($final_select)) {
             $final_diagnosis = $final_select;
        }

        // 3.2 รับค่า Death Cause
        $death_cause = isset($_POST['death_cause']) ? $_POST['death_cause'] : NULL;

        // ตัวแปรอื่นๆ
        $discharge_status = $treatment_result;
        $final_detail = $details;
        $ward_status_update = 'Discharged'; 
        $target_admit_type = '';
        
        // [แก้ไข] ไม่รับค่า discharge_plan และ first_visit_date แล้ว (ให้เป็น NULL)
        $discharge_plan = NULL;
        $first_visit_date = NULL;

        // 3.3 Logic แยกตามสถานะ
        if ($discharge_status == 'Admit') { 
            $discharge_status = 'Admit'; 
            $ward_status_update = 'Admitted'; 
            $target_admit_type = $_POST['admit_type'];
            $final_detail = "ย้ายไป " . $target_admit_type . " : " . $details;

        } elseif ($discharge_status == 'Refer') {
            $ward_status_update = 'Refer';
            $refer_hospital = $_POST['refer_hospital'];
            $final_detail = "Refer ไป " . $refer_hospital . " : " . $details;

        } elseif ($discharge_status == 'Dead') {
            $ward_status_update = 'Dead';
            $detail_add = $death_cause ? " [สาเหตุ: $death_cause]" : "";
            $final_detail = "เสียชีวิต$detail_add : " . $details;
            
        } elseif ($discharge_status == 'Discharge') {
            $final_detail = "จำหน่าย : " . $details;
        }

        // --- 3.4 รับค่า visit_unit (เฉพาะ Discharge) ---
        $visit_unit = '';
        if ($discharge_status == 'Discharge' && !empty($_POST['visit_unit'])) {
            $visit_unit = $_POST['visit_unit']; // radio → string เดี่ยว เช่น "PCU"
        }

        // --- 3.5 รับค่า discharge_date จากฟอร์ม (เฉพาะ Discharge) ---
        if ($discharge_status == 'Discharge' && !empty($_POST['discharge_date'])) {
            // input datetime-local ส่งมาเป็น "YYYY-MM-DDTHH:MM" ต้องแปลงเป็น "YYYY-MM-DD HH:MM:SS" สำหรับ MySQL
            $raw_date = str_replace('T', ' ', $_POST['discharge_date']);
            $parsed_date = strtotime($raw_date);
            $discharge_date = ($parsed_date !== false) ? date('Y-m-d H:i:s', $parsed_date) : date('Y-m-d H:i:s');
        } else {
            $discharge_date = date('Y-m-d H:i:s'); // ค่าเริ่มต้น = เวลาปัจจุบัน (Admit/Refer/Dead)
        }

        // --- 4. SQL UPDATE Ward Details ---
        $sql_update = "UPDATE ward_details 
                       SET status = ?, 
                           discharge_status = ?, 
                           discharge_plan = ?, 
                           first_visit_date = ?, 
                           discharge_detail = ?, 
                           final_diagnosis = ?, 
                           death_cause = ?,
                           visit_unit = ?,
                           discharge_date = ? 
                       WHERE id = ?";

        $stmt_up = $conn->prepare($sql_update);
        $stmt_up->bind_param("sssssssssi", 
            $ward_status_update, 
            $discharge_status, 
            $discharge_plan, 
            $first_visit_date, 
            $final_detail, 
            $final_diagnosis,
            $death_cause,
            $visit_unit,
            $discharge_date,
            $ward_detail_id
        );
        $stmt_up->execute();
        $stmt_up->close();
        
        // --- 5. กรณี Admit (สร้างเคสใหม่ปลายทาง) ---
        if ($discharge_status == 'Admit' && !empty($target_admit_type)) {
            $sql_an = "SELECT an FROM ward_details WHERE id = ?";
            $stmt_an = $conn->prepare($sql_an);
            $stmt_an->bind_param("i", $ward_detail_id);
            $stmt_an->execute();
            $res_an = $stmt_an->get_result();
            $current_an = $res_an->fetch_assoc()['an'];
            $stmt_an->close();

            $Admit_note = "ย้ายจาก " . $current_ward_type . " : " . $details;

            $sql_new = "INSERT INTO ward_details 
                        (case_id, an, admit_type, admit_date, status, discharge_detail) 
                        VALUES (?, ?, ?, NOW(), 'AdmitWait', ?)";
            
            $stmt_new = $conn->prepare($sql_new);
            $stmt_new->bind_param("isss", $case_id, $current_an, $target_admit_type, $Admit_note);
            $stmt_new->execute();
            $stmt_new->close();
        }

        $sqlName = "SELECT patient_name FROM cases WHERE id = '$case_id'";
        $queryName = $conn->query($sqlName);
        $rowName = $queryName->fetch_assoc();
        $patient_name = $rowName['patient_name'];

        // ====== ส่ง Telegram ======
        // if ($discharge_status == 'Discharge' || $discharge_status == 'Refer') {

        //     $botToken = "7403074195:AAFXlSN8JljXJ2Vjvjs7OlylDkzkSs9a_ys"; // ใส่ Telegram bot token ของคุณ
        //     $chatId = "-4745966683"; // ใส่ chat ID ของคุณ

        //     $diagnosis_text = !empty($final_diagnosis) ? $final_diagnosis : '-';

        //     if ($discharge_status == 'Refer') {
        //         $status_text = "Refer ไป " . $refer_hospital;
        //     } else {
        //         $status_text = "จำหน่ายกลับบ้าน (D/C)";
        //     }

        //     $date_now = date("d/m/Y H:i", strtotime($discharge_date));

        //     $message = "📢 แจ้งเตือนผู้ป่วยจำหน่ายจาก $current_ward_type\n\n";
        //     $message .= "👤 ชื่อผู้ป่วย: $patient_name\n";
        //     $message .= "🩺 Final Diagnosis: $diagnosis_text\n";
        //     $message .= "📌 สถานะ: $status_text\n";
        //     $message .= "👨‍⚕️ ผู้บันทึก: $doctor_name\n";
        //     $message .= "🕒 วันที่: $date_now";

        //     $telegramApiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

        //     $data = array(
        //         'chat_id' => $chatId,
        //         'text' => $message
        //     );

        //     // เริ่มส่ง
        //     $ch = curl_init();
        //     curl_setopt($ch, CURLOPT_URL, $telegramApiUrl);
        //     curl_setopt($ch, CURLOPT_POST, true);
        //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //     curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        //     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        //     curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        //     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        //     $response = curl_exec($ch);


        //     // ตรวจสอบผลลัพธ์
        //     if(curl_error($ch)){
        //         echo 'Error: ' . curl_error($ch);
        //     } else {
        //         $responseDecoded = json_decode($response, true);
        //         if ($responseDecoded["ok"]) {
            
        //     } else {
        //         echo "ส่งไม่สำเร็จ: " . $responseDecoded["description"];
        //     }
        //     }
        //     curl_close($ch);
        // }
        $_SESSION['statusType'] = 'success';
        $_SESSION['statusMsg'] = "บันทึกและจำหน่าย $discharge_status ของผู้ป่วย $patient_name เรียบร้อยแล้ว";
        
        if (stripos($sender_ward_name, 'icu') !== false) {
            header("Location: indexICU.php");
        } else {
            header("Location: indexWard.php");
        }
        exit();

    } catch (Exception $e) {
            // ลบของเดิมออกก่อน แล้วใส่บรรทัดนี้แทน
        die("❌ Exception: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
    }
    
}
?>