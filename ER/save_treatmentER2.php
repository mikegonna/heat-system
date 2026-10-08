<?php
// save_treatmentER.php
session_start();
// เปิดแสดง Error เพื่อให้เห็นปัญหาชัดเจน
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once '../database/db_conn.php';
require_once '../database/case_entry_guard.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$statusMsg = "";
$statusType = "";

if (isset($_POST['submit_treatment'])) {
    try {
        $user_id = $_SESSION['user_id'];
        $hn = $_POST['hn']; 

        $target_case_id = resolveCaseForNewHNEntry($conn, $hn, true);


        // --- รับค่าจากฟอร์ม พร้อมดักจับค่าว่างป้องกัน Error ---
        $patient_id_card = $_POST['patient_id_card'] ?? '';
        $patient_name = $_POST['patient_name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $age = empty($_POST['age']) ? NULL : (int)$_POST['age']; 
        $weight = empty($_POST['weight']) ? NULL : (float)$_POST['weight'];
        $height = empty($_POST['height']) ? NULL : (float)$_POST['height'];
        $bmi = empty($_POST['bmi']) ? NULL : (float)$_POST['bmi'];
        $whoname = $_POST['whoname'] ?? '';
        $whotel = $_POST['whotel'] ?? '';
        $department = $_POST['department'] ?? '';
        $congenital_disease = $_POST['congenital_disease'] ?? '';
        
        $person_type = $_POST['person_type'] ?? '';
        if ($person_type == 'อื่นๆ') $person_type = $_POST['other_person_type'] ?? '';

        $arrival_time = $_POST['arrival_time'] ?? '';
        $hospital_arrival_datetime = empty($_POST['hospital_arrival_datetime']) ? NULL : $_POST['hospital_arrival_datetime']; 
        $first_aid = $_POST['first_aid'] ?? '';
        $ct_initial = !empty($_POST['ct_initial']) ? $_POST['ct_initial'] : NULL;
        $ct_30min = !empty($_POST['ct_30min']) ? $_POST['ct_30min'] : NULL;
        $ct_control = $_POST['ct_control'] ?? ""; 

        $FstDx = "";
        $FstDx_select = $_POST['FstDx_select'] ?? '';
        if ($FstDx_select === 'other') {
            $FstDx = $_POST['FstDx_other'] ?? '';
        } elseif (!empty($FstDx_select)) {
            $FstDx = $FstDx_select;
        }

        $disposition = $_POST['disposition'] ?? ''; 
        $admit_type = $_POST['admit_type'] ?? null;
        $refer_hospital = $_POST['refer_hospital'] ?? "";
        $death_cause = !empty($_POST['death_cause']) ? $_POST['death_cause'] : NULL; 
        
        $treatment_result = $_POST['treatment_result'] ?? '';
        $reporter_name = $_POST['reporter_name'] ?? '';

        $cause_factor = $_POST['cause_factor'] ?? '';
        if ($cause_factor == 'อื่นๆ') $cause_factor = $_POST['other_cause_factor'] ?? '';

        $risk_factors = isset($_POST['risk_factors']) ? implode(", ", $_POST['risk_factors']) : "ไม่มีปัจจัยเสี่ยงระบุ";


        // 💡 [แก้ไขตรรกะการบันทึก] แยก Insert กรณีเคสใหม่ หรือ Update กรณีสวมรอย
        $case_id = 0;

        if ($target_case_id > 0) {
            // [กรณี Ward สร้างไปก่อนแล้ว] ให้อัปเดตข้อมูลทั่วไปแทน เพื่อให้แฟ้มประวัติแม่นยำที่สุด
            $case_id = $target_case_id;
            $update_case = "UPDATE cases SET patient_id_card=?, patient_name=?, phone=?, age=?, weight=?, height=?, bmi=?, person_type=?, department=?, congenital_disease=? WHERE id=?";
            $stmt_case = $conn->prepare($update_case);
            $stmt_case->bind_param("sssidddsssi", $patient_id_card, $patient_name, $phone, $age, $weight, $height, $bmi, $person_type, $department, $congenital_disease, $case_id);
            if (!$stmt_case->execute()) { throw new Exception("Error updating case: " . $stmt_case->error); }
            $stmt_case->close();
        } else {
            // [กรณี ER เริ่มต้นคนแรก] สร้างแฟ้มผู้ป่วยใหม่ในระบบ
            $insert_case = "INSERT INTO cases (hn, patient_id_card, patient_name, phone, age, weight, height, bmi, whoname, whotel, person_type, department, congenital_disease, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt_case = $conn->prepare($insert_case);
            $stmt_case->bind_param("ssssidddsssss", $hn, $patient_id_card, $patient_name, $phone, $age, $weight, $height, $bmi, $whoname, $whotel, $person_type, $department, $congenital_disease);
            if (!$stmt_case->execute()) { throw new Exception("Error inserting case: " . $stmt_case->error); }
            $case_id = $stmt_case->insert_id;
            $stmt_case->close();
        }


        // รับค่า visit_unit (เฉพาะตอน Discharge)
        $visit_unit = ($disposition === 'Discharge' && !empty($_POST['visit_unit'])) ? $_POST['visit_unit'] : '';

        // รับค่า "เวลาที่จำหน่ายผู้ป่วย" (เฉพาะตอน Discharge) กันไม่ให้ค่าว่าง/รูปแบบผิดหลุดไปเป็น 0000-00-00
        $er_discharge_date = NULL;
        if ($disposition === 'Discharge' && !empty($_POST['er_discharge_date'])) {
            // input datetime-local ส่งมาเป็น "YYYY-MM-DDTHH:MM(:SS)" ต้องแปลง T เป็นช่องว่างให้ตรง format ของ MySQL
            $raw_discharge = str_replace('T', ' ', $_POST['er_discharge_date']);
            $ts = strtotime($raw_discharge);
            $er_discharge_date = ($ts !== false) ? date('Y-m-d H:i:s', $ts) : NULL;
        }

        // บันทึกฟอร์มรายละเอียดของ ER ลงตาราง er_details (อ้างอิงไปที่ $case_id ที่ถูกต้องเสมอ)
        $sql_er = "INSERT INTO er_details 
                (case_id, user_id, cause_factor, first_aid, arrival_time, hospital_arrival_datetime,
                 ct_initial, ct_30min, ct_control, FstDx, disposition, death_cause, admit_type, refer_hospital, treatment_result,
                  risk_factors, reporter_name, visit_unit, er_discharge_date, recorded_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $conn->prepare($sql_er);
        $stmt->bind_param("iissssddssssssssss" . "s", 
            $case_id, 
            $user_id, 
            $cause_factor, 
            $first_aid, 
            $arrival_time, 
            $hospital_arrival_datetime,
            $ct_initial, 
            $ct_30min, 
            $ct_control, 
            $FstDx, 
            $disposition, 
            $death_cause, 
            $admit_type, 
            $refer_hospital, 
            $treatment_result, 
            $risk_factors, 
            $reporter_name,
            $visit_unit,
            $er_discharge_date
        );

        if ($stmt->execute()) {
            $statusType = "success";
            $statusMsg = "บันทึกข้อมูล $patient_name เรียบร้อยแล้ว";

            $row_id = $_POST['row_id'] ?? 0;
            if ($row_id > 0) {
                $sql_status = "UPDATE data_import SET status = 1 WHERE row_ID = ?";
                $stmt_status = $conn->prepare($sql_status);
                $stmt_status->bind_param("i", $row_id);
                $stmt_status->execute();
            }

        } else {
            throw new Exception("Error inserting ER details: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        $statusType = "error";
        $statusMsg = "ไม่สามารถบันทึกได้: " . $e->getMessage();
    }
}


// ====== Telegram Config ======
// $botToken = "7403074195:AAFXlSN8JljXJ2Vjvjs7OlylDkzkSs9a_ys"; // ใส่ Telegram bot token ของคุณ
// $chatId = "-4745966683"; // ใส่ chat ID ของคุณ

// // ====== รับค่าจาก form ======
// $patient_name = $_POST['patient_name'] ?? '';
// $fstDx = $_POST['FstDx_select'] ?? '';
// $fstDx_other = $_POST['FstDx_other'] ?? '';

// $person_type = $_POST['person_type'] ?? '';
// $other_person_type = $_POST['other_person_type'] ?? '';

// $department = $_POST['department'] ?? '';
// $disposition = $_POST['disposition'] ?? '';
// $admit_type = $_POST['admit_type'] ?? '';
// $recorded_at = $_POST['recorded_at'] ?? '';

// // ====== จัดการค่าที่เป็น "อื่นๆ" ======
// if ($fstDx === 'other') {
//     $fstDx = $fstDx_other;
// }

// if ($person_type === 'อื่นๆ') {
//     $person_type = $other_person_type;
// }

// // ====== แปลงวันที่ให้อ่านง่าย ======
// if (!empty($recorded_at)) {
//     $date = date("d/m/Y H:i", strtotime($recorded_at));
// } else {
//     $date = "-";
// }

// // ====== จัดข้อความ ======
// $message = "📢 แจ้งทราบผู้ป่วยจำหน่ายจาก ER\n\n";
// $message .= "👤 ชื่อ: $patient_name\n";
// $message .= "🩺 วินิจฉัย: $fstDx\n";
// $message .= "🎖 ประเภทกำลังพล: $person_type\n";
// $message .= "🏢 สังกัด: $department\n";
// if ($disposition === 'Admit' && !empty($admit_type)) {
//     $message .= "📌 สถานะ: $disposition ($admit_type)\n";
// } else {
//     $message .= "📌 สถานะ: $disposition\n";
// }
// $message .= "🕒 บันทึกเมื่อ: $date\n";
// $message .= "https://anan-hosp.go.th/ananHeat2";

// // ====== ส่งเข้า Telegram ======
// $telegramApiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

// $data = [
//     'chat_id' => $chatId,
//     'text' => $message,
//     'parse_mode' => 'HTML' // หรือจะใช้ Markdown ก็ได้
// ];

// // เริ่มส่ง
// $ch = curl_init();
// curl_setopt($ch, CURLOPT_URL, $telegramApiUrl);
// curl_setopt($ch, CURLOPT_POST, true);
// curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
// curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
// $response = curl_exec($ch);


// // ตรวจสอบผลลัพธ์
// if(curl_error($ch)){
//     echo 'Error: ' . curl_error($ch);
// } else {
//     $responseDecoded = json_decode($response, true);
//     if ($responseDecoded["ok"]) {
 
// } else {
//     echo "ส่งไม่สำเร็จ: " . $responseDecoded["description"];
// }
// }
// curl_close($ch);


?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สถานะการบันทึก</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> body { font-family: 'Sarabun', sans-serif; background-color: #f8fafc; } </style>
</head>
<body>
    <?php if ($statusType == 'success') { ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'สำเร็จ!',
                text: '<?php echo addslashes($statusMsg); ?>',
                confirmButtonColor: '#10b981',
                confirmButtonText: 'ตกลง'
            }).then((result) => {
                window.location.href = 'indexER.php'; 
            });
        </script>
    <?php } elseif ($statusType == 'error') { ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: '<?php echo addslashes($statusMsg); ?>',
                confirmButtonText: 'กลับไปแก้ไข',
                confirmButtonColor: '#e11d48'
            }).then(() => {
                window.history.back();
            });
        </script>
    <?php } else { ?>
        <script>window.location.href = 'indexER.php';</script>
    <?php } ?>
</body>
</html>