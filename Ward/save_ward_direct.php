<?php
session_start();
require_once '../database/db_conn.php';

// เปิดการแจ้งเตือน Error ของ MySQLi ให้โยนเป็น Exception (ช่วยให้ Try/Catch ทำงานได้)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id'])) { 
    header("Location: ../login.php"); 
    exit(); 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ward_direct'])) {
    try {
        // =========================================================
        // 1. รับค่าข้อมูลทั่วไปของผู้ป่วย (Patient Info) ครบทุกช่อง
        // =========================================================
        $hn = trim($_POST['hn'] ?? '');
        $patient_id_card = trim($_POST['patient_id_card'] ?? '');
        $patient_name = trim($_POST['patient_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '-');
        
        // แปลงเป็นตัวเลขเพื่อความปลอดภัย (หากค่าว่างให้เป็น NULL เพื่อไม่ให้ Error ใน DB)
        $age = (!empty($_POST['age'])) ? (int)$_POST['age'] : NULL;
        $weight = (!empty($_POST['weight'])) ? (float)$_POST['weight'] : NULL;
        $height = (!empty($_POST['height'])) ? (float)$_POST['height'] : NULL;
        $bmi = (!empty($_POST['bmi'])) ? (float)$_POST['bmi'] : NULL;
        
        // จัดการ ประเภทกำลังพล (หากเลือก 'อื่นๆ' ให้ดึงค่าจากช่องกรอกพิมพ์เอง)
        $person_type = trim($_POST['person_type'] ?? '-');
        if ($person_type === 'อื่นๆ' || $person_type === 'other') {
            $person_type = trim($_POST['other_person_type'] ?? '-');
        }
        
        $department = trim($_POST['department'] ?? '-');
        $congenital_disease = trim($_POST['congenital_disease'] ?? '-');

        // =========================================================
        // 2. จัดการบันทึก / อัปเดต ข้อมูลในตาราง cases (แฟ้มหลักผู้ป่วย)
        // =========================================================
        $case_id = 0;
        
        if (!empty($_POST['existing_case_id'])) {
            // 🟢 กรณี 2.1: มี case_id ส่งมา (เป็นผู้ป่วยที่ส่งต่อมาจาก ER)
            $case_id = (int)$_POST['existing_case_id'];
            
            // "อัปเดต" ข้อมูลทั่วไป เผื่อว่าพยาบาล Ward มีการแก้ไขข้อมูลน้ำหนัก ส่วนสูง หรืออื่นๆ
            $stmtUp = $conn->prepare("UPDATE cases SET patient_id_card=?, patient_name=?, phone=?, age=?, weight=?, height=?, bmi=?, person_type=?, department=?, congenital_disease=? WHERE id=?");
            $stmtUp->bind_param("sssidddsssi", $patient_id_card, $patient_name, $phone, $age, $weight, $height, $bmi, $person_type, $department, $congenital_disease, $case_id);
            $stmtUp->execute();
            $stmtUp->close();
            
        } else {
            // 🟢 กรณี 2.2: ไม่มี case_id ส่งมา (เป็นการรับเข้ารักษาตรงแบบ Direct Admit ค้นหาผ่าน API)
            if ($hn !== '') {
                $stmtActiveWard = $conn->prepare("SELECT c.id AS case_id, c.patient_name, w.admit_type, w.admit_date
                                                  FROM cases c
                                                  INNER JOIN ward_details w ON w.case_id = c.id
                                                  WHERE TRIM(c.hn) = ? AND w.status = 'Active'
                                                  ORDER BY w.admit_date DESC, w.id DESC");
                $stmtActiveWard->bind_param("s", $hn);
                $stmtActiveWard->execute();
                $activeWardRecords = $stmtActiveWard->get_result();
                $activeWardDetails = [];
                while ($activeWard = $activeWardRecords->fetch_assoc()) {
                    $admitDate = !empty($activeWard['admit_date'])
                        ? date('d/m/Y H:i', strtotime($activeWard['admit_date']))
                        : '-';
                    $activeWardDetails[] = $activeWard['admit_type'] . ' (รับเมื่อวันที่ ' . $admitDate . ')';
                }
                $stmtActiveWard->close();

                if ($activeWardDetails) {
                    throw new RuntimeException(
                        "พบ HN นี้มีรายการ Ward ที่ยังรักษาอยู่: " . implode(', ', $activeWardDetails)
                        . " กรุณาตรวจสอบว่าเป็นการรักษาครั้งเดิมก่อนบันทึกหรือไม่ "
                    );
                }
            }

            $status_case = 'Open';
            $stmtIns = $conn->prepare("INSERT INTO cases (hn, patient_id_card, patient_name, phone, age, weight, height, bmi, person_type, department, congenital_disease, status_case) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param("ssssidddssss", $hn, $patient_id_card, $patient_name, $phone, $age, $weight, $height, $bmi, $person_type, $department, $congenital_disease, $status_case);
            $stmtIns->execute();
            $case_id = $stmtIns->insert_id; // ได้ case_id ใหม่มาใช้งานต่อ
            $stmtIns->close();
        }

        // =========================================================
        // 3. รับค่าและเตรียมข้อมูลตาราง ward_details
        // =========================================================
        $an = trim($_POST['an'] ?? '');
        $admit_date = !empty($_POST['admit_date']) ? str_replace('T', ' ', $_POST['admit_date']) : date('Y-m-d H:i:s');
        $treatment_result = $_POST['treatment_result'] ?? 'Admit';
        $admit_type = !empty($_POST['admit_type']) ? $_POST['admit_type'] : $_SESSION['name'];
        $doctor_name = trim($_POST['doctor_name'] ?? '');

        // วินิจฉัยแรกรับ (สำหรับ Ward ทั่วไป)
        $initial_diagnosis = NULL;
        if (!empty($_POST['initial_diagnosis_select'])) {
            $initial_diagnosis = ($_POST['initial_diagnosis_select'] === 'other') ? ($_POST['initial_diagnosis_other'] ?? NULL) : $_POST['initial_diagnosis_select'];
        }
        
        // วินิจฉัยแรกรับ และตัวแปรเฉพาะของ (ICU)
        $icu_diagnosis = NULL;
        $icu_core_temp_first = NULL;
        $icu_core_temp_30min = NULL;
        $icu_control_temp = NULL;
        $icu_time_symptoms = NULL;
        $icu_time_hospital = NULL;
        $icu_time_er_to_icu = NULL;

        if (stripos($admit_type, 'icu') !== false) {
            if (!empty($_POST['icu_diagnosis_select'])) {
                $icu_diagnosis = ($_POST['icu_diagnosis_select'] === 'other') ? ($_POST['icu_diagnosis_other'] ?? NULL) : $_POST['icu_diagnosis_select'];
            }
            $icu_core_temp_first = !empty($_POST['icu_core_temp_first']) ? (float)$_POST['icu_core_temp_first'] : NULL;
            $icu_core_temp_30min = !empty($_POST['icu_core_temp_30min']) ? (float)$_POST['icu_core_temp_30min'] : NULL;
            $icu_control_temp = !empty($_POST['icu_control_temp']) ? $_POST['icu_control_temp'] : NULL;
            $icu_time_symptoms = !empty($_POST['icu_time_symptoms']) ? str_replace('T', ' ', $_POST['icu_time_symptoms']) : NULL;
            $icu_time_hospital = !empty($_POST['icu_time_hospital']) ? str_replace('T', ' ', $_POST['icu_time_hospital']) : NULL;
            $icu_time_er_to_icu = !empty($_POST['icu_time_er_to_icu']) ? $_POST['icu_time_er_to_icu'] : NULL;
        }

        // หากเป็นผู้ป่วยที่ส่งมาจาก ER ให้อัปเดตตาราง ER ว่าถูก Admit เข้าตึกนี้เรียบร้อยแล้ว
        if (!empty($_POST['existing_case_id'])) {
            $stmtER = $conn->prepare("UPDATE er_details SET disposition='Admit', admit_type=? WHERE case_id=? ORDER BY id DESC LIMIT 1");
            $stmtER->bind_param("si", $admit_type, $case_id);
            $stmtER->execute();
            $stmtER->close();
        }

        // =========================================================
        // 4. บันทึกลง ward_details (ประวัติการเข้าพักในตึก)
        // =========================================================
        $status = 'Active';
        $stmtWard = $conn->prepare("INSERT INTO ward_details 
            (case_id, an, admit_type, admit_date, initial_diagnosis, treatment_result, status, doctor_name, 
             icu_core_temp_first, icu_core_temp_30min, icu_control_temp, icu_diagnosis, icu_time_symptoms, icu_time_hospital, icu_time_er_to_icu) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // ผูกตัวแปร (i=int, s=string, d=double/float)
        $stmtWard->bind_param("isssssssddsssss", 
            $case_id, $an, $admit_type, $admit_date, $initial_diagnosis, $treatment_result, $status, $doctor_name,
            $icu_core_temp_first, $icu_core_temp_30min, $icu_control_temp, $icu_diagnosis, $icu_time_symptoms, $icu_time_hospital, $icu_time_er_to_icu
        );
        $stmtWard->execute();
        $ward_detail_id = $stmtWard->insert_id;
        $stmtWard->close();

        // =========================================================
        // 5. สร้างบันทึกความก้าวหน้า (Progress Note) เริ่มต้นแบบอัตโนมัติ
        // =========================================================
        $note_details = "รับผู้ป่วยใหม่เข้าแผนก " . $admit_type;
        $stmtNote = $conn->prepare("INSERT INTO ward_progress_notes (case_id, ward_detail_id, Admit_type, heat_status, details, treatment_result, doctor_name) VALUES (?, ?, ?, 'Non Re-Heat', ?, 'Admit', ?)");
        $stmtNote->bind_param("iisss", $case_id, $ward_detail_id, $admit_type, $note_details, $doctor_name);
        $stmtNote->execute();
        $stmtNote->close();

        // =========================================================
        // 6. บันทึกสำเร็จ กลับหน้าจอหลัก
        // =========================================================
        $_SESSION['statusType'] = 'success';
        $_SESSION['statusMsg'] = "รับผู้ป่วยใหม่ (Admit) และบันทึกข้อมูลเรียบร้อยแล้ว";

        $current_name = isset($_SESSION['name']) ? strtoupper($_SESSION['name']) : '';
        if ($current_name == 'ICU') {
            header("Location: indexICU.php");
        } else {
            header("Location: indexWard.php");
        }
        exit();

    } catch (Exception $e) {
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'เกิดข้อผิดพลาดฐานข้อมูล: ' . $e->getMessage();
        header("Location: formWard.php");
        exit();
    }
} else {
    // หากเข้าหน้านี้โดยตรงโดยไม่กด Submit ให้เด้งกลับ
    header("Location: formWard.php");
    exit();
}
?>