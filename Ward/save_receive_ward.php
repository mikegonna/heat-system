<?php
session_start();
require_once '../database/db_conn.php';

// เปิด Error Reporting เพื่อช่วยดูปัญหา
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }

// 1. เพิ่มให้เช็คทั้ง 2 ปุ่ม (Ward และ ICU)
if (isset($_POST['submit_receive']) || isset($_POST['submit_receive_icu'])) {
    $transactionStarted = false;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        // --- รับค่าพื้นฐาน ---
        // ใช้ ?? เพื่อป้องกัน Error หากไม่มีการส่งค่ามา
        $case_id = (int)($_POST['case_id'] ?? 0);
        $an = $_POST['an'] ?? '';
        $pending_ward_id = (int)($_POST['pending_ward_id'] ?? 0);

        if ($case_id <= 0) {
            throw new Exception("ไม่พบข้อมูลเคสผู้ป่วยที่ต้องการรับเข้า");
        }
        
        $admit_type = $_POST['admit_type'] ?? 'Ward'; 
        
        // 2. รับค่าแพทย์เจ้าของไข้
        $doctor_name = $_POST['doctor_name'] ?? NULL;

        // จัดการวันที่ (ตัดตัว T)
        $admit_date = isset($_POST['admit_date']) ? str_replace('T', ' ', $_POST['admit_date']) : date('Y-m-d H:i:s'); 
        $status = 'Active';

        // --- รับค่า ICU Data ---
        $icu_diagnosis = (!empty($_POST['icu_diagnosis_select']) && $_POST['icu_diagnosis_select'] !== 'other') 
                         ? $_POST['icu_diagnosis_select'] 
                         : (!empty($_POST['icu_diagnosis_other']) ? $_POST['icu_diagnosis_other'] : NULL);

        $icu_core_temp_first = !empty($_POST['icu_core_temp_first']) ? $_POST['icu_core_temp_first'] : NULL;
        $icu_core_temp_30min = !empty($_POST['icu_core_temp_30min']) ? $_POST['icu_core_temp_30min'] : NULL;
        $icu_control_temp    = !empty($_POST['icu_control_temp']) ? $_POST['icu_control_temp'] : NULL;
        
        $icu_time_symptoms   = !empty($_POST['icu_time_symptoms']) ? str_replace('T', ' ', $_POST['icu_time_symptoms']) : NULL;
        $icu_time_hospital   = !empty($_POST['icu_time_hospital']) ? str_replace('T', ' ', $_POST['icu_time_hospital']) : NULL;
        $icu_time_er_to_icu  = !empty($_POST['icu_time_er_to_icu']) ? $_POST['icu_time_er_to_icu'] : NULL;

        // --- รับค่า Ward Data ---
        $initial_diagnosis = (!empty($_POST['initial_diagnosis_select']) && $_POST['initial_diagnosis_select'] !== 'other')
                             ? $_POST['initial_diagnosis_select']
                             : (!empty($_POST['initial_diagnosis_other']) ? $_POST['initial_diagnosis_other'] : NULL);

        // ถ้าเป็น ICU วินิจฉัยแรกรับอาจจะว่าง ให้ดึงจาก ICU มาใส่เลย
        if (!empty($icu_diagnosis) && empty($initial_diagnosis)) {
            $initial_diagnosis = $icu_diagnosis;
        }

        // หาก Ward เคยสร้างเคสและรับผู้ป่วยไว้แล้ว ให้รวมข้อมูล ER เข้ากับเคส Ward เดิม
        if ($pending_ward_id === 0) {
            $stmtSource = $conn->prepare("SELECT c.id, c.hn
                                          FROM cases c
                                          INNER JOIN er_details e ON e.case_id = c.id
                                          WHERE c.id = ? AND e.disposition = 'Admit'
                                          ORDER BY e.id DESC
                                          LIMIT 1");
            $stmtSource->bind_param("i", $case_id);
            $stmtSource->execute();
            $sourceCase = $stmtSource->get_result()->fetch_assoc();
            $stmtSource->close();

            if ($sourceCase) {
                $hn = trim($sourceCase['hn']);
                $stmtWardCase = $conn->prepare("SELECT c.id AS case_id, w.id AS ward_id
                                                FROM cases c
                                                INNER JOIN ward_details w ON w.case_id = c.id
                                                WHERE TRIM(c.hn) = ?
                                                  AND c.id <> ?
                                                  AND w.status = 'Active'
                                                GROUP BY c.id
                                                ORDER BY c.id DESC, MAX(w.id) DESC
                                                LIMIT 2
                                                FOR UPDATE");
                $stmtWardCase->bind_param("si", $hn, $case_id);
                $stmtWardCase->execute();
                $wardCases = $stmtWardCase->get_result();
                $existingWardCase = $wardCases->fetch_assoc();
                $hasMultipleWardCases = $wardCases->fetch_assoc() !== null;
                $stmtWardCase->close();

                if ($hasMultipleWardCases) {
                    throw new Exception("พบเคส Ward ที่ยังรักษาอยู่มากกว่าหนึ่งเคสสำหรับ HN นี้ กรุณาตรวจสอบก่อนรับเข้า");
                }

                if ($existingWardCase) {
                    $existingCaseId = (int)$existingWardCase['case_id'];
                    $existingWardId = (int)$existingWardCase['ward_id'];

                    $stmtSourceWard = $conn->prepare("SELECT id FROM ward_details WHERE case_id = ? LIMIT 1");
                    $stmtSourceWard->bind_param("i", $case_id);
                    $stmtSourceWard->execute();
                    $sourceHasWardRecord = $stmtSourceWard->get_result()->num_rows > 0;
                    $stmtSourceWard->close();
                    if ($sourceHasWardRecord) {
                        throw new Exception("เคส ER มีประวัติ Ward อยู่แล้ว จึงไม่สามารถรวมกับเคส Ward อื่นได้");
                    }

                    $stmtUpdateCase = $conn->prepare("UPDATE cases AS target
                                                      INNER JOIN cases AS source ON source.id = ?
                                                      SET target.patient_id_card = COALESCE(NULLIF(source.patient_id_card, ''), target.patient_id_card),
                                                          target.patient_name = COALESCE(NULLIF(source.patient_name, ''), target.patient_name),
                                                          target.phone = COALESCE(NULLIF(source.phone, ''), target.phone),
                                                          target.age = COALESCE(source.age, target.age),
                                                          target.weight = COALESCE(source.weight, target.weight),
                                                          target.height = COALESCE(source.height, target.height),
                                                          target.bmi = COALESCE(source.bmi, target.bmi),
                                                          target.whoname = COALESCE(NULLIF(source.whoname, ''), target.whoname),
                                                          target.whotel = COALESCE(NULLIF(source.whotel, ''), target.whotel),
                                                          target.person_type = COALESCE(NULLIF(source.person_type, ''), target.person_type),
                                                          target.department = COALESCE(NULLIF(source.department, ''), target.department),
                                                          target.congenital_disease = COALESCE(NULLIF(source.congenital_disease, ''), target.congenital_disease)
                                                      WHERE target.id = ?");
                    $stmtUpdateCase->bind_param("ii", $case_id, $existingCaseId);
                    $stmtUpdateCase->execute();
                    $stmtUpdateCase->close();

                    $stmtMoveER = $conn->prepare("UPDATE er_details SET case_id = ? WHERE case_id = ?");
                    $stmtMoveER->bind_param("ii", $existingCaseId, $case_id);
                    $stmtMoveER->execute();
                    $stmtMoveER->close();

                    $stmtMoveGP = $conn->prepare("UPDATE gp_details SET case_id = ? WHERE case_id = ?");
                    $stmtMoveGP->bind_param("ii", $existingCaseId, $case_id);
                    $stmtMoveGP->execute();
                    $stmtMoveGP->close();

                    $stmtMoveNotes = $conn->prepare("UPDATE ward_progress_notes SET case_id = ? WHERE case_id = ?");
                    $stmtMoveNotes->bind_param("ii", $existingCaseId, $case_id);
                    $stmtMoveNotes->execute();
                    $stmtMoveNotes->close();

                    $stmtDeleteSource = $conn->prepare("DELETE FROM cases WHERE id = ?");
                    $stmtDeleteSource->bind_param("i", $case_id);
                    $stmtDeleteSource->execute();
                    if ($stmtDeleteSource->affected_rows !== 1) {
                        throw new Exception("ไม่สามารถรวมแถวเคส ER เข้ากับเคส Ward เดิมได้");
                    }
                    $stmtDeleteSource->close();

                    $case_id = $existingCaseId;
                    $pending_ward_id = $existingWardId;
                }
            }
        }

        // --- ตรวจสอบความซ้ำซ้อน ---
        $checkSql = "SELECT id FROM ward_details WHERE case_id = ? AND status = 'Active'";
        if ($pending_ward_id > 0) { $checkSql .= " AND id != ?"; }
        
        $stmtCheck = $conn->prepare($checkSql);
        if ($pending_ward_id > 0) {
            $stmtCheck->bind_param("ii", $case_id, $pending_ward_id);
        } else {
            $stmtCheck->bind_param("i", $case_id);
        }
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) {
             throw new Exception("ผู้ป่วยรายนี้ถูกรับเข้าไปแล้ว (Status: รักษาอยู่)");
        }
        $stmtCheck->close();

        // --- บันทึกข้อมูล ---
        if ($pending_ward_id > 0) {
            // [CASE UPDATE]: ย้ายจากแผนกอื่น
            $sql = "UPDATE ward_details SET 
                    an = ?, admit_type = ?, admit_date = ?, doctor_name = ?,
                    
                    icu_core_temp_first = COALESCE(?, icu_core_temp_first), 
                    icu_core_temp_30min = COALESCE(?, icu_core_temp_30min), 
                    icu_control_temp = COALESCE(?, icu_control_temp), 
                    icu_diagnosis = COALESCE(?, icu_diagnosis),
                    icu_time_symptoms = COALESCE(?, icu_time_symptoms), 
                    icu_time_hospital = COALESCE(?, icu_time_hospital), 
                    icu_time_er_to_icu = COALESCE(?, icu_time_er_to_icu), 
                    initial_diagnosis = COALESCE(?, initial_diagnosis), 
                    
                    status = 'Active', 
                    discharge_detail = NULL
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            
            // Param Type: ssss ddssssss s i (รวม 13 ตัว)
            $stmt->bind_param("ssssddssssssi", 
                $an, $admit_type, $admit_date, $doctor_name,
                $icu_core_temp_first, $icu_core_temp_30min, $icu_control_temp, $icu_diagnosis,
                $icu_time_symptoms, $icu_time_hospital, $icu_time_er_to_icu, 
                $initial_diagnosis, 
                $pending_ward_id
            );

        } else {
            // [CASE INSERT]: รับใหม่จาก ER
            $sql = "INSERT INTO ward_details 
                    (case_id, an, admit_type, admit_date, doctor_name, status, 
                     icu_core_temp_first, icu_core_temp_30min, icu_control_temp, icu_diagnosis,
                     icu_time_symptoms, icu_time_hospital, icu_time_er_to_icu, initial_diagnosis) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            
            // Param Type: isssss ddssssss (รวม 14 ตัว)
            $stmt->bind_param("isssssddssssss", 
                $case_id, $an, $admit_type, $admit_date, $doctor_name, $status,
                $icu_core_temp_first, $icu_core_temp_30min, $icu_control_temp, $icu_diagnosis,
                $icu_time_symptoms, $icu_time_hospital, $icu_time_er_to_icu, 
                $initial_diagnosis
            );
        }

        if ($stmt->execute()) {
            $conn->commit();
            $transactionStarted = false;
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = "รับผู้ป่วยเรียบร้อยแล้ว";
            
            // Redirect ตามแผนก
            $current_name = isset($_SESSION['name']) ? strtoupper($_SESSION['name']) : '';
            if ($current_name == 'ICU') {
                header('Location: indexICU.php');
            } else {
                header('Location: indexWard.php');
            }
        } else {
            throw new Exception($stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        // แจ้งเตือน Error
        $_SESSION['statusType'] = 'error';
        $_SESSION['statusMsg'] = "ระบบขัดข้อง: " . $e->getMessage();
        
        $current_name = isset($_SESSION['name']) ? strtoupper($_SESSION['name']) : '';
        if ($current_name == 'ICU') {
            header('Location: indexICU.php');
        } else {
            header('Location: indexWard.php');
        }
        exit();
    }
    
    $conn->close();
} else {
    // ถ้าไม่มีการส่งฟอร์ม ให้ดีดกลับ
    header("Location: ../login.php");
}
?>