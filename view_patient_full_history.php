<?php 
    // ==========================================
    // Centralized & Full Page: view_patient_full_history.php 
    // ==========================================
    session_start();

    if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

    // 1. เชื่อมต่อฐานข้อมูล
    $db_paths = ['database/db_conn.php', '../database/db_conn.php', '../../database/db_conn.php'];
    $conn = null;
    foreach ($db_paths as $path) {
        if (file_exists($path)) { require_once $path; $conn_found = true; break; }
    }
    if (!$conn) { die("<div class='alert alert-danger text-center m-5'><h3>Error: Database Connection Failed</h3><p>ไม่พบไฟล์ db_conn.php</p></div>"); }

    require_once __DIR__ . '/MED/med_followup_helpers.php';
    if (!ensureMedFollowupColumns($conn)) {
        http_response_code(500);
        exit('ไม่สามารถเตรียมข้อมูลผลตรวจ MED ได้ กรุณาตรวจสอบบันทึกข้อผิดพลาดของระบบ');
    }

    if (!isset($_GET['hn']) || empty($_GET['hn'])) {
        die("<div class='alert alert-danger text-center mt-5'>ไม่พบรหัส HN</div>");
    }
    $hn = mysqli_real_escape_string($conn, $_GET['hn']);

    // ดึงสิทธิ์ผู้ใช้งาน (รองรับทั้งการเช็คจาก name และ role)
    $user_role = strtolower($_SESSION['name'] ?? '');
    $session_role = strtolower($_SESSION['role'] ?? '');

    $is_admin_or_pct = ($user_role === 'pct' || $session_role === 'admin');
    $is_viewer = ($user_role === 'view' || $user_role === 'viewer' || $session_role === 'viewer');

    // 🟢 กำหนดสิทธิ์การแก้ไขตามแผนก (Admin/PCT แก้ได้ทั้งหมด, Viewer จะเป็น false ทั้งหมด)
    $can_edit_er    = $is_admin_or_pct || $user_role === 'er';
    $can_edit_gp    = $is_admin_or_pct || $user_role === 'gp';
    $can_edit_ward  = $is_admin_or_pct || in_array($user_role, ['a4', 'b3', 'icu', 'rcu']);
    $can_edit_med   = $is_admin_or_pct || $user_role === 'med';
    $can_edit_occ   = $is_admin_or_pct || $user_role === 'occ';
    $can_edit_pmr   = $is_admin_or_pct || $user_role === 'pmr';
    $can_edit_visit = $is_admin_or_pct || in_array($user_role, ['pcu', 'coc']);

    // ==============================================================
    // 📋 เตรียมตัวแปร Array สำหรับ Dropdown/Checkbox ให้เหมือนหน้าฟอร์ม
    // ==============================================================
    $diag_list = ["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"];
    $cause_list = ["ฝึกทางการทหาร", "ออกกำลังกายกลางวันในที่แจ้ง", "ทำงานกลางแจ้ง"];
    $risk_list = ["ไม่มีปัจจัยเสี่ยง", "พักผ่อนไม่เพียงพอ", "มีประวัติใช้ยาเสพติด (ก่อนฝึก)", "มีประวัติใช้ยาเสพติด (ระหว่างฝึก)", "ดื่มแอลกอฮอล์", "BMI > 28", "BMI > 30 หรือ นน. > 100 กก.", "มีไข้ 36.8-37.2 °C", "มีไข้ > 37.2 °C", "ท้องเสีย/เป็นหวัด (ช่วงฝึก)", "มีโรคประจำตัวเสี่ยงต่อโรคร้อน", "ทานยาที่ลดการระบายความร้อน", "บาดเจ็บ/ถูกทำร้ายร่างกาย", "บริจาคโลหิตภายใน 3 วัน", "นน.ลด 2-5 กก. ใน 1 สัปดาห์", "นน.ลด > 5 กก. ใน 1 สัปดาห์", "ประวัติฮีทสโตรก (เคยนอน รพ.)", "เจ็บป่วยฝึกไม่ได้ (มีใบรับรองแพทย์)"];
    $med_status_list = ["มาตรงนัด", "มาไม่ตรงนัด"];
    $pmr_status_list = ["มาตรงนัด", "มาไม่ตรงนัด"];
    $pcu_sym_list = ['ปวดศีรษะ', 'มึนงง', 'หน้ามืด', 'ปวดเมื่อยกล้ามเนื้อ', 'อ่อนเพลีย', 'จุกแน่นหน้าอก', 'คลื่นไส้', 'อาเจียน', 'ทานไม่ได้', 'ชัก'];

    // ==========================================
    // 🔴 1. ประมวลผลการลบ "ทั้งเคส/ทั้งไทม์ไลน์" (Delete Entire Case) - เฉพาะ Admin/PCT
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pct_delete_case_submit']) && $is_admin_or_pct) {
        $del_case_id = (int)$_POST['delete_case_id'];
        
        $ans = [];
        $q_ans = mysqli_query($conn, "SELECT an FROM ward_details WHERE case_id = '$del_case_id'");
        if ($q_ans) {
            while($r = mysqli_fetch_assoc($q_ans)) { if(!empty($r['an'])) $ans[] = "'" . mysqli_real_escape_string($conn, $r['an']) . "'"; }
        }
        
        if (!empty($ans)) {
            $an_str = implode(',', $ans);
            mysqli_query($conn, "DELETE FROM home_visits WHERE an IN ($an_str)");
            mysqli_query($conn, "DELETE FROM pmr_records WHERE an IN ($an_str)");
            mysqli_query($conn, "DELETE FROM med_records WHERE an IN ($an_str)");
            mysqli_query($conn, "DELETE FROM occ_records WHERE an IN ($an_str)");
        }
        
        mysqli_query($conn, "DELETE FROM er_details WHERE case_id = '$del_case_id'");
        mysqli_query($conn, "DELETE FROM gp_details WHERE case_id = '$del_case_id'");
        mysqli_query($conn, "DELETE FROM ward_progress_notes WHERE ward_detail_id IN (SELECT id FROM ward_details WHERE case_id = '$del_case_id')");
        mysqli_query($conn, "DELETE FROM ward_details WHERE case_id = '$del_case_id'");
        mysqli_query($conn, "DELETE FROM cases WHERE id = '$del_case_id'");
        
        $_SESSION['statusMsg'] = "ลบประวัติการรักษา (ทั้งไทม์ไลน์) สำเร็จเรียบร้อย";
        $_SESSION['statusType'] = "success";

        // 🟢 เช็คว่ามีเคสการรักษาอื่นของผู้ป่วยคนนี้เหลืออยู่อีกไหม
        $check_remaining = mysqli_query($conn, "SELECT id FROM cases WHERE hn = '$hn'");
        if (mysqli_num_rows($check_remaining) > 0) {
            // ถ้ายังมีเคสอื่นเหลืออยู่ ให้กลับมาหน้าเดิม
            header("Location: view_patient_full_history.php?hn=" . urlencode($hn));
        } else {
            // 🟢 ถ้าลบจนไม่เหลือเคสแล้ว ให้เด้งกลับไปยังหน้าลิสต์ที่กดเข้ามา พร้อมแสดง Alert ที่หน้านั้นเลย
            $return_url = $_SESSION['history_return_url'] ?? '';
            if (empty($return_url) || strpos($return_url, 'javascript') !== false) {
                $return_url = 'history_centralPCT.php'; // fallback ถ้าหักกลับไม่ได้
            }
            header("Location: " . $return_url);
        }
        exit();
    }

    // ==========================================
    // 🔴 2. ประมวลผลการอัปเดตข้อมูล (ตรวจสอบสิทธิ์ตาม Edit Type)
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_edit_submit'])) {
        if ($is_viewer) { die("Permission Denied: Viewer role cannot modify data."); }

        $edit_type = $_POST['edit_type'];
        $record_id = (int)$_POST['record_id'];

    // 🔴 ป้องกันการแก้ไขถ้าเคสถูกปิดแล้ว (ยกเว้น PCT/Admin)
    if (!$is_admin_or_pct && in_array($edit_type, ['ER', 'GP', 'WARD', 'MED', 'PMR', 'OCC', 'VISIT'])) {
        $case_id_check = null;
        if ($edit_type === 'ER') {
            $q = mysqli_query($conn, "SELECT case_id FROM er_details WHERE id=$record_id");
            if ($r = mysqli_fetch_assoc($q)) $case_id_check = $r['case_id'];
        }elseif ($edit_type === 'GP') {
            $q = mysqli_query($conn, "SELECT case_id FROM gp_details WHERE id=$record_id");
            if ($r = mysqli_fetch_assoc($q)) $case_id_check = $r['case_id'];
        } elseif ($edit_type === 'WARD') {
            $q = mysqli_query($conn, "SELECT case_id FROM ward_details WHERE id=$record_id");
            if ($r = mysqli_fetch_assoc($q)) $case_id_check = $r['case_id'];
        } elseif (in_array($edit_type, ['MED', 'PMR', 'OCC'])) {
            $table = ['MED' => 'med_records', 'PMR' => 'pmr_records', 'OCC' => 'occ_records'][$edit_type];
            $q = mysqli_query($conn, "SELECT w.case_id FROM $table m JOIN ward_details w ON m.an=w.an WHERE m.id=$record_id LIMIT 1");
            if ($r = mysqli_fetch_assoc($q)) $case_id_check = $r['case_id'];
        } elseif ($edit_type === 'VISIT') {
            $q = mysqli_query($conn, "SELECT w.case_id FROM home_visits m JOIN ward_details w ON m.an=w.an WHERE m.id=$record_id LIMIT 1");
            if ($r = mysqli_fetch_assoc($q)) $case_id_check = $r['case_id'];
        }

        if ($case_id_check) {
            $q_case = mysqli_query($conn, "SELECT status_case FROM cases WHERE id=$case_id_check");
            if ($r_case = mysqli_fetch_assoc($q_case)) {
                if ($r_case['status_case'] === 'Closed') {
                    $_SESSION['statusMsg'] = "ไม่สามารถแก้ไขข้อมูลได้ เนื่องจากเคสการรักษานี้ถูกปิดแล้ว (Closed)";
                    $_SESSION['statusType'] = "error";
                    header("Location: view_patient_full_history.php?hn=" . urlencode($hn));
                    exit();
                }
            }
        }
    }
    
    if ($edit_type === 'ER' && $can_edit_er) {
        $FstDx = ($_POST['FstDx_select'] === 'other') ? $_POST['FstDx_other'] : $_POST['FstDx_select'];
        $cause_factor = ($_POST['cause_factor_select'] === 'other') ? $_POST['cause_factor_other'] : $_POST['cause_factor_select'];
        $risk_factors = isset($_POST['risk_factors']) ? implode(', ', $_POST['risk_factors']) : '';
        
        $FstDx = mysqli_real_escape_string($conn, $FstDx);
        $cause_factor = mysqli_real_escape_string($conn, $cause_factor);
        $risk_factors = mysqli_real_escape_string($conn, $risk_factors);
        $ct_initial = mysqli_real_escape_string($conn, $_POST['ct_initial']);
        $ct_30min = mysqli_real_escape_string($conn, $_POST['ct_30min']);
        $ct_control = mysqli_real_escape_string($conn, $_POST['ct_control']);
        $first_aid = mysqli_real_escape_string($conn, $_POST['first_aid']);
        $treatment_result = mysqli_real_escape_string($conn, $_POST['treatment_result'] ?? '');
        
        $arrival_time = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['arrival_time']));
        $hospital_arrival_datetime = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['hospital_arrival_datetime']));
        
        // ตรวจ disposition ปัจจุบันจาก DB
        $q_disp_er = mysqli_query($conn, "SELECT disposition FROM er_details WHERE id=$record_id");
        $disp_er = ($q_disp_er && $r_disp = mysqli_fetch_assoc($q_disp_er)) ? $r_disp['disposition'] : '';
        $recorded_at_er_sql = '';
        if ($disp_er === 'Discharge' && !empty($_POST['recorded_at_discharge'])) {
            $recorded_at_val = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['recorded_at_discharge']));
            $recorded_at_er_sql = ", recorded_at='$recorded_at_val'";
        }

        $sqlUp = "UPDATE er_details SET 
            FstDx='$FstDx', ct_initial='$ct_initial', ct_30min='$ct_30min', ct_control='$ct_control',
            first_aid='$first_aid', cause_factor='$cause_factor', risk_factors='$risk_factors',
            arrival_time='$arrival_time', hospital_arrival_datetime='$hospital_arrival_datetime',
            treatment_result='$treatment_result'
            $recorded_at_er_sql
            WHERE id=$record_id";
        mysqli_query($conn, $sqlUp);

    } elseif ($edit_type === 'GP' && $can_edit_gp) {

        $FstDx = ($_POST['FstDx_select'] === 'other') ? $_POST['FstDx_other'] : $_POST['FstDx_select'];
        $cause_factor = ($_POST['cause_factor_select'] === 'other') ? $_POST['cause_factor_other'] : $_POST['cause_factor_select'];
        $risk_factors = isset($_POST['risk_factors']) ? implode(', ', $_POST['risk_factors']) : '';
        
        $FstDx = mysqli_real_escape_string($conn, $FstDx);
        $cause_factor = mysqli_real_escape_string($conn, $cause_factor);
        $risk_factors = mysqli_real_escape_string($conn, $risk_factors);
        $ct_initial = mysqli_real_escape_string($conn, $_POST['ct_initial']);
        $ct_30min = mysqli_real_escape_string($conn, $_POST['ct_30min']);
        $ct_control = mysqli_real_escape_string($conn, $_POST['ct_control']);
        $first_aid = mysqli_real_escape_string($conn, $_POST['first_aid']);
        $treatment_result = mysqli_real_escape_string($conn, $_POST['treatment_result'] ?? '');
        
        $arrival_time = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['arrival_time']));
        $hospital_arrival_datetime = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['hospital_arrival_datetime']));
        
        // ตรวจ disposition ปัจจุบันจาก DB
        $q_disp_gp = mysqli_query($conn, "SELECT disposition FROM gp_details WHERE id=$record_id");
        $disp_gp = ($q_disp_gp && $r_disp_gp = mysqli_fetch_assoc($q_disp_gp)) ? $r_disp_gp['disposition'] : '';
        $recorded_at_gp_sql = '';
        if ($disp_gp === 'Discharge' && !empty($_POST['recorded_at_discharge'])) {
            $recorded_at_val_gp = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['recorded_at_discharge']));
            $recorded_at_gp_sql = ", recorded_at='$recorded_at_val_gp'";
        }

        $sqlUp = "UPDATE gp_details SET 
            FstDx='$FstDx', ct_initial='$ct_initial', ct_30min='$ct_30min', ct_control='$ct_control',
            first_aid='$first_aid', cause_factor='$cause_factor', risk_factors='$risk_factors',
            arrival_time='$arrival_time', hospital_arrival_datetime='$hospital_arrival_datetime',
            treatment_result='$treatment_result'
            $recorded_at_gp_sql
            WHERE id=$record_id";
        mysqli_query($conn, $sqlUp);

    } elseif ($edit_type === 'WARD') {
        $allowed_ward = false;
        if ($is_admin_or_pct) {
            $allowed_ward = true;
        } elseif (in_array($user_role, ['a4', 'b3', 'icu', 'rcu'])) {
            $chk_q = mysqli_query($conn, "SELECT admit_type FROM ward_details WHERE id=$record_id");
            if ($chk_q && $rChk = mysqli_fetch_assoc($chk_q)) {
                $rec_ward = strtolower($rChk['admit_type'] ?? '');
                if (strpos($rec_ward, $user_role) !== false) { $allowed_ward = true; }
            }
        }

        if ($allowed_ward) {
            $chk_an = mysqli_query($conn, "SELECT an FROM ward_details WHERE id=$record_id");
            $old_an = ($chk_an && $rChkAn = mysqli_fetch_assoc($chk_an)) ? $rChkAn['an'] : '';

            $initial_diagnosis = ($_POST['initial_diagnosis_select'] === 'other') ? $_POST['initial_diagnosis_other'] : $_POST['initial_diagnosis_select'];
            $initial_diagnosis = mysqli_real_escape_string($conn, $initial_diagnosis);
            $final_diagnosis = ($_POST['final_diagnosis_select'] === 'other') ? $_POST['final_diagnosis_other'] : $_POST['final_diagnosis_select'];
            $final_diagnosis = mysqli_real_escape_string($conn, $final_diagnosis);
            $an = mysqli_real_escape_string($conn, $_POST['an']);
            $admit_date = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['admit_date']));
            $discharge_date = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['discharge_date']));
            $doctor_name = mysqli_real_escape_string($conn, $_POST['doctor_name']);
            
            $sqlUp = "UPDATE ward_details SET initial_diagnosis='$initial_diagnosis', final_diagnosis='$final_diagnosis', an='$an', admit_date='$admit_date', discharge_date='$discharge_date', doctor_name='$doctor_name'";

            if (isset($_POST['icu_diagnosis_select']) && !isset($_POST['icu_diagnosis_select']['disabled'])) {
                $icu_diagnosis = ($_POST['icu_diagnosis_select'] === 'other') ? $_POST['icu_diagnosis_other'] : $_POST['icu_diagnosis_select'];
                $icu_diagnosis = mysqli_real_escape_string($conn, $icu_diagnosis);
                $icu_core_temp_first = mysqli_real_escape_string($conn, $_POST['icu_core_temp_first'] ?? '');
                $icu_core_temp_30min = mysqli_real_escape_string($conn, $_POST['icu_core_temp_30min'] ?? '');
                $icu_control_temp = mysqli_real_escape_string($conn, $_POST['icu_control_temp'] ?? '');
                $icu_time_er_to_icu = mysqli_real_escape_string($conn, $_POST['icu_time_er_to_icu'] ?? '');
                
                $sqlUp .= ", icu_diagnosis='$icu_diagnosis', icu_core_temp_first='$icu_core_temp_first', icu_core_temp_30min='$icu_core_temp_30min', icu_control_temp='$icu_control_temp', icu_time_er_to_icu='$icu_time_er_to_icu'";
            }
            
            $sqlUp .= " WHERE id=$record_id";
            mysqli_query($conn, $sqlUp);

            // อัปเดต AN ในตารางที่เกี่ยวข้องหากมีการแก้ไข AN
            if (!empty($old_an) && $old_an !== $an && !empty($an)) {
                $old_an_esc = mysqli_real_escape_string($conn, $old_an);
                mysqli_query($conn, "UPDATE home_visits SET an='$an' WHERE an='$old_an_esc'");
                mysqli_query($conn, "UPDATE pmr_records SET an='$an' WHERE an='$old_an_esc'");
                mysqli_query($conn, "UPDATE med_records SET an='$an' WHERE an='$old_an_esc'");
                mysqli_query($conn, "UPDATE occ_records SET an='$an' WHERE an='$old_an_esc'");
            }
        } else {
            $_SESSION['statusMsg'] = "ไม่อนุญาตให้แก้ไขฟอร์มของหอผู้ป่วยอื่น (อนุญาตเฉพาะ PCT/Admin)";
            $_SESSION['statusType'] = "error";
            header("Location: view_patient_full_history.php?hn=" . urlencode($hn));
            exit();
        }

    } elseif ($edit_type === 'MED' && $can_edit_med) {
        $med_status = mysqli_real_escape_string($conn, $_POST['med_status']);
        $med_note = mysqli_real_escape_string($conn, $_POST['med_note']);
        $cpk = mysqli_real_escape_string($conn, $_POST['cpk'] ?? '');
        $bun = mysqli_real_escape_string($conn, $_POST['bun'] ?? '');
        $cr = mysqli_real_escape_string($conn, $_POST['cr'] ?? '');
        $eletroride = mysqli_real_escape_string($conn, $_POST['eletroride'] ?? '');
        $next_med_date = (strpos($med_status, 'สิ้นสุด') !== false || empty($_POST['next_med_date'])) ? 'NULL' : "'" . mysqli_real_escape_string($conn, $_POST['next_med_date']) . "'";
        $record_datetime = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['record_datetime']));
        $recorder_name = mysqli_real_escape_string($conn, $_POST['recorder_name']);

        $sqlUp = "UPDATE med_records SET med_status='$med_status', med_note='$med_note', cpk='$cpk', bun='$bun', cr='$cr', eletroride='$eletroride', next_med_date=$next_med_date, record_datetime='$record_datetime', recorder_name='$recorder_name' WHERE id=$record_id";
        mysqli_query($conn, $sqlUp);

    } elseif ($edit_type === 'PMR' && $can_edit_pmr) {
        $pmr_status = mysqli_real_escape_string($conn, $_POST['pmr_status']);
        $pmr_note = mysqli_real_escape_string($conn, $_POST['pmr_note']);
        $next_pmr_date = (strpos($pmr_status, 'สิ้นสุด') !== false || empty($_POST['next_pmr_date'])) ? 'NULL' : "'" . mysqli_real_escape_string($conn, $_POST['next_pmr_date']) . "'";
        $record_datetime = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['record_datetime']));
        $recorder_name = mysqli_real_escape_string($conn, $_POST['recorder_name']);

        $sqlUp = "UPDATE pmr_records SET pmr_status='$pmr_status', pmr_note='$pmr_note', next_pmr_date=$next_pmr_date, record_datetime='$record_datetime', recorder_name='$recorder_name' WHERE id=$record_id";
        mysqli_query($conn, $sqlUp);

    } elseif ($edit_type === 'VISIT' && $can_edit_visit) {
        $checklist_obs = isset($_POST['obs']) ? implode(', ', $_POST['obs']) : '';
        $symptoms_arr = $_POST['symptoms'] ?? [];
        if (in_array('other', $symptoms_arr)) {
            $other_idx = array_search('other', $symptoms_arr);
            $symptoms_arr[$other_idx] = trim($_POST['symptoms_other']);
        }
        $symptoms = implode(', ', $symptoms_arr);

        $checklist_obs = mysqli_real_escape_string($conn, $checklist_obs);
        $symptoms = mysqli_real_escape_string($conn, $symptoms);
        $body_temperature = mysqli_real_escape_string($conn, $_POST['body_temperature']);
        $urine_color = mysqli_real_escape_string($conn, $_POST['urine_color']);
        $activity_details = mysqli_real_escape_string($conn, $_POST['activity_details']);
        $next_visit_date = empty($_POST['next_visit_date']) ? 'NULL' : "'" . mysqli_real_escape_string($conn, $_POST['next_visit_date']) . "'";
        
        $record_datetime = mysqli_real_escape_string($conn, str_replace('T', ' ', $_POST['record_datetime']));
        $recorder_name = mysqli_real_escape_string($conn, $_POST['recorder_name']);
        
        $sqlUp = "UPDATE home_visits SET checklist_obs='$checklist_obs', body_temperature='$body_temperature', urine_color='$urine_color', symptoms='$symptoms', activity_details='$activity_details', next_visit_date=$next_visit_date, record_datetime='$record_datetime', recorder_name='$recorder_name' WHERE id=$record_id";
        mysqli_query($conn, $sqlUp);
    } elseif ($edit_type === 'PATIENT' && $is_admin_or_pct) {
        $patient_name = mysqli_real_escape_string($conn, $_POST['patient_name']);
        $patient_id_card = mysqli_real_escape_string($conn, $_POST['patient_id_card']);
        $person_type = mysqli_real_escape_string($conn, $_POST['person_type']);
        $department = mysqli_real_escape_string($conn, $_POST['department']);
        $age = (int)$_POST['age'];
        $weight = (float)$_POST['weight'];
        $height = (float)$_POST['height'];
        $bmi = (float)$_POST['bmi'];
        $congenital_disease = mysqli_real_escape_string($conn, $_POST['congenital_disease']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);

        $sqlUp = "UPDATE cases SET 
            patient_name='$patient_name', patient_id_card='$patient_id_card', 
            person_type='$person_type', department='$department', 
            age=$age, weight=$weight, height=$height, bmi=$bmi, 
            congenital_disease='$congenital_disease', phone='$phone'
            WHERE id=$record_id";
        mysqli_query($conn, $sqlUp);
    }
    
    $_SESSION['statusMsg'] = "บันทึกการแก้ไขข้อมูลสำเร็จ";
    $_SESSION['statusType'] = "success";
    header("Location: view_patient_full_history.php?hn=" . urlencode($hn));
    exit();
}


// --- 2. Dynamic Theme Setup (ปรับสีตามแผนก) ---
$t_main = '#327c62'; $t_dark = '#1e4d3c'; $t_bg = '#f4fbf7'; $t_light = '#e8f6ef'; 
$t_grad = 'linear-gradient(135deg, #327c62 0%, #1e4d3c 100%)';
$dept_name = 'หอผู้ป่วยใน (Ward)';

if ($user_role == 'er') { 
    $t_main = '#059669'; $t_dark = '#047857'; $t_bg = '#ecfdf5'; $t_light = '#d1fae5'; 
    $t_grad = 'linear-gradient(135deg, #10b981 0%, #047857 100%)';
    $dept_name = '(ER)';
}elseif ($user_role == 'gp') { 
    $t_main = '#7b9605'; $t_dark = '#6c7804'; $t_bg = '#fbfdec'; $t_light = '#f4fad1'; 
    $t_grad = 'linear-gradient(135deg, #a8b910 0%, #6c7804 100%)';
    $dept_name = '(GP)';
}elseif ($user_role == 'med') { 
    $t_main = '#0284c7'; $t_dark = '#0369a1'; $t_bg = '#f0f9ff'; $t_light = '#e0f2fe'; 
    $t_grad = 'linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%)';
    $dept_name = '(MED)';
} elseif ($user_role == 'pmr') { 
    $t_main = '#8b5cf6'; $t_dark = '#7c3aed'; $t_bg = '#f5f3ff'; $t_light = '#ede9fe'; 
    $t_grad = 'linear-gradient(135deg, #a855f7 0%, #6d28d9 100%)';
    $dept_name = '(PMR)';
} elseif ($user_role == 'pcu' || $user_role == 'coc') { 
    $t_main = '#d6728d'; $t_dark = '#b95c76'; $t_bg = '#fdf5f7'; $t_light = '#fae8ee'; 
    $t_grad = 'linear-gradient(135deg, #f472b6 0%, #be185d 100%)';
    $dept_name = '(PCU)';
} elseif ($user_role == 'icu') { 
    $t_main = '#fb923c'; $t_dark = '#ea580c'; $t_bg = '#fff7ed'; $t_light = '#ffedd5'; 
    $t_grad = 'linear-gradient(135deg, #f97316 0%, #c2410c 100%)';
    $dept_name = '(ICU)';
} elseif ($is_admin_or_pct) {
    $t_main = '#1d4ed8'; $t_dark = '#1e3a8a'; $t_bg = '#eff6ff'; $t_light = '#dbeafe'; 
    $t_grad = 'linear-gradient(135deg, #3b82f6 0%, #1e3a8a 100%)';
    $dept_name = '(PCT)';
} elseif ($is_viewer) {
    $t_main = '#64748b'; $t_dark = '#475569'; $t_bg = '#f8fafc'; $t_light = '#f1f5f9'; 
    $t_grad = 'linear-gradient(135deg, #94a3b8 0%, #475569 100%)';
    $dept_name = '(ผู้เยี่ยมชม / Viewer)';
}

// เช็ค URL ก่อนหน้าเผื่อต้องกดย้อนกลับ
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], basename($_SERVER['PHP_SELF'])) === false) {
        $_SESSION['history_return_url'] = $_SERVER['HTTP_REFERER'];
    }
}
$return_url = $_SESSION['history_return_url'] ?? 'javascript:history.back()';

// 3. ดึงประวัติทั้งหมด
$respatients = mysqli_query($conn, "SELECT * FROM cases WHERE hn = '$hn' ORDER BY id DESC");
$patients = [];
if ($respatients && mysqli_num_rows($respatients) > 0) {
    while($row = mysqli_fetch_assoc($respatients)) { $patients[] = $row; }
}
$total_patients = count($patients);

// 🟢 ปรับปรุงการจัดการเมื่อไม่พบประวัติ ให้แสดง HTML สวยงามพร้อมรัน Script Alert ถ้ามี
if ($total_patients === 0) {
    $alert_script = "";
    if(isset($_SESSION['statusMsg'])){
        $alert_script = "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: '{$_SESSION['statusType']}',
                    title: '" . ($_SESSION['statusType'] === "success" ? "สำเร็จ" : "ข้อผิดพลาด") . "',
                    text: '{$_SESSION['statusMsg']}',
                    timer: 3500,
                    showConfirmButton: false,
                    backdrop: `rgba(0,0,123,0.1)`
                });
            });
        </script>";
        unset($_SESSION['statusMsg']);
        unset($_SESSION['statusType']);
    }

    die("<!DOCTYPE html><html lang='th'>
    <head>
        <meta charset='UTF-8'>
        <title>ไม่พบประวัติการรักษา</title>
        <link href='bootstrap-5.0.2-dist/css/bootstrap.min.css' rel='stylesheet'>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
        <link href='https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap' rel='stylesheet'>
        <style>body{background-color:#f0f2f5; font-family:'Sarabun', sans-serif;}</style>
    </head>
    <body>
        $alert_script
        <div class='container mt-5'>
            <div class='alert bg-white shadow-sm border-0 text-center p-5 rounded-3 mx-auto' style='max-width: 600px;'>
                <i class='fa-solid fa-folder-open fa-4x text-muted mb-3 opacity-50'></i>
                <h3 class='text-danger'>ไม่พบประวัติการรักษา</h3>
                <p class='text-muted fs-5'>ผู้ป่วย HN: <strong>" . htmlspecialchars((string)$hn) . "</strong> ไม่มีข้อมูลประวัติการรักษาในระบบ หรือข้อมูลถูกลบทิ้งไปหมดแล้ว</p>
                <a href='" . htmlspecialchars((string)$return_url) . "' class='btn btn-primary mt-3 px-4 py-2 rounded-pill'><i class='fa-solid fa-arrow-left me-2'></i> กลับหน้าหลัก</a>
            </div>
        </div>
    </body></html>");
}

$ptInfo = $patients[0];
$bmi_latest = "-";
if (!empty($ptInfo['weight']) && !empty($ptInfo['height'])) {
    $h_m = $ptInfo['height'] / 100;
    if ($h_m > 0) $bmi_latest = number_format($ptInfo['weight'] / ($h_m * $h_m), 2);
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เวชระเบียนประวัติ: <?php echo htmlspecialchars((string)($ptInfo['patient_name'] ?? '')); ?></title>
    
    <link href="bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="CSS/style.css" rel="stylesheet">
    <link href="OCC/occ_status.css" rel="stylesheet">
    <style>
                :root {
            --t-main: <?php echo $t_main; ?>;
            --t-dark: <?php echo $t_dark; ?>;
            --t-grad: <?php echo $t_grad; ?>;
            --t-bg: #f8f9fa;
            --t-text: #212529;
            --t-muted: #6c757d;
        }
    </style>
    
</head>
<body>

<?php if(isset($_SESSION['statusMsg'])): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                icon: '<?php echo $_SESSION['statusType']; ?>',
                title: '<?php echo ($_SESSION['statusType'] === "success" ? "สำเร็จ" : "ข้อผิดพลาด"); ?>',
                text: '<?php echo $_SESSION['statusMsg']; ?>',
                timer: 3500,
                showConfirmButton: false,
                backdrop: `rgba(0,0,123,0.1)`
            });
        });
    </script>
<?php 
    unset($_SESSION['statusMsg']); 
    unset($_SESSION['statusType']); 
endif; 
?>

<!-- TOPBAR -->
<div class="topbar no-print">
    <div class="container-fluid px-4 d-flex justify-content-between align-items-center" style="max-width: 1350px; margin: 0 auto;">
        <div class="font-head fw-bold fs-5 d-flex align-items-center gap-2" style="color: var(--t-text);">
            <i class="fa-solid fa-file-medical text-theme" style="background: var(--t-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i> 
            ระบบเวชระเบียนประวัติผู้ป่วย <span class="badge bg-secondary fw-normal ms-2 shadow-sm"><?php echo htmlspecialchars((string)$dept_name); ?></span>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn-action btn-print"><i class="fa-solid fa-print"></i> พิมพ์เวชระเบียน</button>
            <a href="<?php echo htmlspecialchars((string)$return_url); ?>" class="btn-action btn-back"><i class="fa-solid fa-arrow-left"></i> กลับ</a>
        </div>
    </div>
</div>

<div class="profile-wrapper animate-fade">
    
    <div class="print-only mb-3 text-center" style="border-bottom: 2px solid #000; padding-bottom: 10px;">
        <h2 class="font-head" style="margin: 0; font-size: 18pt; font-weight: bold;">แบบบันทึกเวชระเบียนผู้ป่วย (Medical Record)</h2>
        <p style="margin: 5px 0 0; font-size: 12pt;">โรงพยาบาลอานันทมหิดล | พิมพ์เมื่อ: <?php echo date('d/m/Y H:i'); ?></p>
    </div>

    <!-- 🟢 PATIENT PROFILE (FORMAL HEADER) -->
    <div class="profile-card" id="profileCard">
        <div class="p-header">
            <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                <div class="patient-avatar no-print">
                    <i class="fa-solid fa-hospital-user"></i>
                </div>
                <div>
                    <div class="text-muted small fw-bold mb-1">ข้อมูลผู้ป่วย (PATIENT INFORMATION)</div>
                    <h1 class="p-name d-flex align-items-center justify-content-center justify-content-md-start gap-3">
                        <span id="prof-name"><?php echo htmlspecialchars((string)($ptInfo['patient_name'] ?? '-')); ?></span>
                        <?php if($is_admin_or_pct): ?>
                            <button id="btnEditProfile" class="btn btn-sm btn-outline-secondary bg-white text-dark no-print fs-6 py-1 px-2 shadow-sm rounded-pill" data-json='<?php echo htmlspecialchars(json_encode($ptInfo), ENT_QUOTES, 'UTF-8'); ?>' onclick="openRecordEdit('PATIENT', this)">
                                <i class="fa-solid fa-pen"></i> แก้ไขข้อมูล
                            </button>
                        <?php endif; ?>
                    </h1>
                </div>
            </div>
            <div class="text-end">
                <div class="fw-bold fs-4 text-dark">HN: <span class="text-primary"><?php echo htmlspecialchars((string)($ptInfo['hn'] ?? '-')); ?></span></div>
                <div class="text-muted small">เลข ปชช: <span id="prof-idcard"><?php echo htmlspecialchars((string)($ptInfo['patient_id_card'] ?? '-')); ?></span></div>
            </div>
        </div>
        
        <div class="p-stats-grid">
            <div class="stat-item">
                <div class="s-lbl"><i class="fa-solid fa-id-badge me-2 opacity-50"></i> ประเภทกำลังพล</div>
                <div class="s-val" id="prof-ptype"><?php echo htmlspecialchars((string)($ptInfo['person_type'] ?? '-')); ?></div>
            </div>
            <div class="stat-item">
                <div class="s-lbl"><i class="fa-solid fa-building-user me-2 opacity-50"></i> สังกัด/หน่วย</div>
                <div class="s-val" id="prof-dept"><?php echo htmlspecialchars((string)($ptInfo['department'] ?? '-')); ?></div>
            </div>
            <div class="stat-item" style="flex: 0 0 15%;">
                <div class="s-lbl"><i class="fa-solid fa-cake-candles me-2 opacity-50"></i> อายุ</div>
                <div class="s-val"><span id="prof-age" class="text-primary fw-bold"><?php echo htmlspecialchars((string)($ptInfo['age'] ?? '-')); ?></span> <small class="text-muted">ปี</small></div>
            </div>
            <div class="stat-item" style="flex: 0 0 15%;">
                <div class="s-lbl"><i class="fa-solid fa-weight-scale me-2 opacity-50"></i> นน./ส่วนสูง</div>
                <div class="s-val"><span id="prof-weight"><?php echo htmlspecialchars((string)($ptInfo['weight'] ?? '-')); ?></span> / <span id="prof-height"><?php echo htmlspecialchars((string)($ptInfo['height'] ?? '-')); ?></span></div>
            </div>
            <div class="stat-item" style="flex: 0 0 15%;">
                <div class="s-lbl"><i class="fa-solid fa-calculator me-2 opacity-50"></i> BMI</div>
                <div class="s-val" id="prof-bmi"><?php echo htmlspecialchars((string)$bmi_latest); ?></div>
            </div>
            <div class="stat-item">
                <div class="s-lbl text-danger"><i class="fa-solid fa-notes-medical me-2 opacity-50"></i> โรคประจำตัว</div>
                <div class="s-val text-danger fw-bold" id="prof-disease"><?php echo htmlspecialchars((string)($ptInfo['congenital_disease'] ?? '-')); ?></div>
            </div>
            <div class="stat-item">
                <div class="s-lbl"><i class="fa-solid fa-phone me-2 opacity-50"></i> เบอร์โทรศัพท์</div>
                <div class="s-val" id="prof-phone"><?php echo htmlspecialchars((string)($ptInfo['phone'] ?? '-')); ?></div>
            </div>
            <div class="stat-item">
                <div class="s-lbl"><i class="fa-solid fa-person me-2 opacity-50"></i> ผู้นำส่ง/เบอร์ผู้นำส่ง</div>
                <div class="s-val"><span id="prof-weight"><?php echo htmlspecialchars((string)($ptInfo['whoname'] ?? '-')); ?></span> / <span id="prof-height"><?php echo htmlspecialchars((string)($ptInfo['whotel'] ?? '-')); ?></span></div>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <?php if ($total_patients > 0): ?>
    <div class="d-flex align-items-center mb-3 no-print animate-fade" style="animation-delay: 0.1s;">
        <h6 class="font-head fw-bold mb-0 me-3 text-secondary"><i class="fa-solid fa-layer-group me-2"></i>ประวัติการรักษา (Admissions):</h6>
    </div>
    <ul class="nav nav-pills mb-4 flex-nowrap overflow-auto no-print animate-fade" id="caseTabs" role="tablist" style="padding-bottom: 10px; animation-delay: 0.1s;">
        <?php foreach($patients as $index => $case): 
            $case_num = $total_patients - $index;
            $is_active = ($index === 0) ? 'active' : '';
            $tab_id = "case-" . $case['id'];

            $c_w = (float)($case['weight'] ?? 0);
            $c_h = (float)($case['height'] ?? 0);
            $c_bmi = "-";
            if ($c_w > 0 && $c_h > 0) $c_bmi = number_format($c_w / (($c_h/100)*($c_h/100)), 2);
            elseif (!empty($case['bmi'])) $c_bmi = $case['bmi'];
        ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $is_active; ?> me-2 mb-2 text-start position-relative" 
                id="tab-<?php echo $tab_id; ?>" data-bs-toggle="pill" data-bs-target="#pane-<?php echo $tab_id; ?>" 
                type="button" role="tab"
                data-age="<?php echo htmlspecialchars((string)($case['age'] ?? '-')); ?>"
                data-weight="<?php echo htmlspecialchars((string)($case['weight'] ?? '-')); ?>"
                data-height="<?php echo htmlspecialchars((string)($case['height'] ?? '-')); ?>"
                data-bmi="<?php echo htmlspecialchars((string)$c_bmi); ?>"
                data-phone="<?php echo htmlspecialchars((string)($case['phone'] ?? '-')); ?>"
                data-disease="<?php echo htmlspecialchars((string)($case['congenital_disease'] ?? '-')); ?>"
                data-dept="<?php echo htmlspecialchars((string)($case['department'] ?? '-')); ?>"
                data-ptype="<?php echo htmlspecialchars((string)($case['person_type'] ?? '-')); ?>"
                data-name="<?php echo htmlspecialchars((string)($case['patient_name'] ?? '-')); ?>"
                data-idcard="<?php echo htmlspecialchars((string)($case['patient_id_card'] ?? '-')); ?>"
                data-fulljson='<?php echo htmlspecialchars(json_encode($case), ENT_QUOTES, 'UTF-8'); ?>'
                onclick="updateProfileData(this)"
                style="min-width: 140px;"
            >
                <strong>ครั้งที่ <?php echo $case_num; ?></strong> <br>
                <small style="opacity: 0.8;"><i class="fa-regular fa-calendar me-1"></i><?php echo date('d/m/Y', strtotime($case['created_at'])); ?></small>
                <?php if($case['status_case'] === 'Closed'): ?>
                    <span class="position-absolute top-0 start-100 translate-middle p-2 bg-secondary border border-light rounded-circle" title="เคสนี้ถูกปิดแล้ว (Closed)">
                        <span class="visually-hidden">Closed Case</span>
                    </span>
                <?php endif; ?>
            </button>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- TABS CONTENT (ฟอร์มเวชระเบียน) -->
    <div class="tab-content animate-fade" id="caseTabsContent" style="animation-delay: 0.2s;">
        <?php foreach($patients as $index => $case): 
            $case_num = $total_patients - $index;
            $is_active = ($index === 0) ? 'show active' : '';
            $tab_id = "case-" . $case['id'];
            $case_id = $case['id'];
            
            // 📌 ตัวแปรเช็คว่าอนุญาตให้แก้ไขไหม (ถ้าเคส Closed คนอื่นจะแก้ไม่ได้ ยกเว้น PCT/Admin)
            $is_case_closed = ($case['status_case'] === 'Closed');
            $can_edit_this_case = (!$is_case_closed || $is_admin_or_pct);
            
            $nodes = [];
            
            // 1. ER
            $qER = mysqli_query($conn, "SELECT * FROM er_details WHERE case_id = '$case_id'");
            if($qER) {
                while ($er = mysqli_fetch_assoc($qER)) { 
                    // เรียงตามเวลาที่มาถึง รพ. (hospital_arrival_datetime)
                    $er_ts = (!empty($er['hospital_arrival_datetime']) && $er['hospital_arrival_datetime'] !== '0000-00-00 00:00:00') 
                             ? strtotime($er['hospital_arrival_datetime']) 
                             : strtotime($er['recorded_at']);
                    $nodes[] = ['type'=>'ER', 'ts'=>$er_ts, 'updated_ts'=>strtotime($er['recorded_at']), 'data'=>$er]; 
                }
            }
            // 1.1 GP
            $qGP = mysqli_query($conn, "SELECT * FROM gp_details WHERE case_id = '$case_id'");
            if($qGP) {
                while ($gp = mysqli_fetch_assoc($qGP)) {

                    $gp_ts = (!empty($gp['visit_datetime']) && $gp['visit_datetime'] !== '0000-00-00 00:00:00')
                            ? strtotime($gp['visit_datetime'])
                            : strtotime($gp['recorded_at']);

                    $nodes[] = [
                        'type' => 'GP',
                        'ts' => $gp_ts,
                        'updated_ts' => strtotime($gp['recorded_at']),
                        'data' => $gp
                    ];
                }
            }
            // 2. WARD
            $ans = [];
            $qWard = mysqli_query($conn, "SELECT * FROM ward_details WHERE case_id = '$case_id' ORDER BY id ASC");
            if($qWard) {
                while ($w = mysqli_fetch_assoc($qWard)) {
                    if (!empty($w['an'])) $ans[] = "'".$w['an']."'";
                    $wid = $w['id'];
                    $qReheat = mysqli_query($conn, "SELECT id FROM ward_progress_notes WHERE ward_detail_id = '$wid' AND heat_status = 'Re-Heat' LIMIT 1");
                    $w['has_reheat'] = ($qReheat && mysqli_num_rows($qReheat) > 0);
                    
                    // เรียงตามเวลาที่ Admit (admit_date)
                    $ward_ts = (!empty($w['admit_date']) && $w['admit_date'] !== '0000-00-00 00:00:00') 
                               ? strtotime($w['admit_date']) 
                               : time();
                    $nodes[] = ['type'=>'WARD', 'ts'=>$ward_ts, 'updated_ts'=>strtotime($w['admit_date']), 
                                'discharge_ts'=>(!empty($w['discharge_date']) && $w['discharge_date'] !== '0000-00-00 00:00:00') ? strtotime($w['discharge_date']) : null, 'data'=>$w];
                }
            }

            // 3. Follow-ups
            if (!empty($ans)) {
                $an_str = implode(',', $ans);
                $tables = [
                    'MED' => ['med_records', 'record_datetime'],
                    'PMR' => ['pmr_records', 'record_datetime'],
                    'VISIT' => ['home_visits', 'record_datetime'],
                    'OCC' => ['occ_records', 'record_date']
                ];
                foreach($tables as $tKey => [$tName, $dateColumn]) {
                    $dateSelect = $tKey === 'OCC' ? ', record_date AS record_datetime' : '';
                    $qOther = mysqli_query($conn, "SELECT *$dateSelect FROM $tName WHERE an IN ($an_str) ORDER BY $dateColumn ASC");
                    if($qOther && mysqli_num_rows($qOther) > 0) {
                        $grouped = [];
                        while ($o = mysqli_fetch_assoc($qOther)) { $grouped[$o['an']][] = $o; }
                        foreach ($grouped as $an_k => $recs) {
                            $latest_rec = $recs[count($recs)-1];
                            $nodes[] = [ 'type' => $tKey.'_GROUP', 'ts' => strtotime($latest_rec['record_datetime']), 'updated_ts' => strtotime($latest_rec['record_datetime']), 'an' => $an_k, 'records' => array_reverse($recs) ];
                        }
                    }
                }
            }

            usort($nodes, function($a, $b) {
                $ta = ($a['ts'] !== false && $a['ts'] !== null) ? $a['ts'] : 0;
                $tb = ($b['ts'] !== false && $b['ts'] !== null) ? $b['ts'] : 0;
                return $tb <=> $ta;
            });
        ?>
        
        <div class="tab-pane fade <?php echo $is_active; ?>" id="pane-<?php echo $tab_id; ?>" role="tabpanel">
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div class="font-head fw-bold fs-5 text-dark d-flex align-items-center flex-wrap gap-2">
                    <span><i class="fa-solid fa-timeline me-2 text-primary"></i>ข้อมูลไทม์ไลน์การรักษา ครั้งที่ <?php echo $case_num; ?></span>
                    <?php if ($is_case_closed): ?>
                        <span class="badge bg-secondary shadow-sm px-3" style="font-size: 0.85rem; border-radius: 20px;"><i class="fa-solid fa-lock me-1"></i> ปิดเคสโดย PCT แล้ว</span>
                    <?php endif; ?>
                </div>
                <?php if ($is_admin_or_pct): ?>
                    <button class="btn btn-sm btn-outline-danger border-0 fw-bold no-print shadow-sm bg-white" onclick="confirmDeleteCase(<?php echo $case_id; ?>, <?php echo $case_num; ?>)">
                        <i class="fa-solid fa-trash-can me-1"></i> ลบข้อมูลรอบการรักษานี้ทั้งหมด
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($is_case_closed && !$is_admin_or_pct): ?>
                <div class="alert bg-white border d-flex align-items-center mb-4 shadow-sm" style="border-left: 5px solid #6c757d !important; border-radius: 10px;">
                    <i class="fa-solid fa-lock fa-2x me-3 text-secondary opacity-50"></i>
                    <div>
                        <strong class="d-block text-danger fs-6">เคสถูกประทับตราปิด (Closed) โดย PCT แล้ว</strong>
                        <span class="text-muted small">คุณสามารถเปิดดูประวัติและไทม์ไลน์การรักษาได้เพียงอย่างเดียว ไม่สามารถแก้ไขฟอร์มใดๆ ได้ หากต้องการแก้ไขกรุณาติดต่อศูนย์ PCT</span>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="timeline-container">
                <?php if (count($nodes) == 0): ?>
                    <div class="alert alert-light border text-center p-5 text-muted rounded-3 shadow-sm">
                        <i class="fa-solid fa-box-open fa-3x mb-3 opacity-25"></i><br>
                        ยังไม่มีข้อมูลบันทึกในรอบการรักษานี้
                    </div>
                <?php endif; ?>

                <?php foreach($nodes as $node): 
                    $type = $node['type'];
                    $title = ''; $doc = '-'; $an_badge = '';
                    $cardClass = ''; $iconClass = ''; $faIcon = '';
                    
                    if($type == 'ER') {
                        $d = $node['data']; $title = 'บันทึกข้อมูลฉุกเฉิน'; $doc = $d['reporter_name'] ?? '-';
                        $cardClass = 'card-er'; $iconClass = 'icon-er'; $faIcon = 'fa-truck-medical';
                    }elseif($type == 'GP') {
                        $d = $node['data']; $title = 'บันทึกข้อมูลห้องตรวจโรคทั่วไป'; $doc = $d['reporter_name'] ?? '-';
                        $cardClass = 'card-gp'; $iconClass = 'icon-gp'; $faIcon = 'fa-user-doctor';
                    } elseif($type == 'WARD') {
                        $d = $node['data']; $title = 'บันทึกผู้ป่วยใน - ' . ($d['admit_type'] ?? 'Ward');
                        $doc = $d['doctor_name'] ?? '-'; if(!empty($d['an'])) $an_badge = $d['an'];
                        $cardClass = 'card-ward'; $iconClass = 'icon-ward'; $faIcon = 'fa-bed-pulse';
                    } elseif(strpos($type, '_GROUP') !== false) {
                        $baseType = str_replace('_GROUP', '', $type);
                        $doc = count($node['records']) . ' รายการ'; $an_badge = $node['an'];
                        if($baseType=='MED') { 
                            $title='บันทึกอายุรกรรม (Follow-up)'; $cardClass = 'card-med'; $iconClass = 'icon-med'; $faIcon = 'fa-stethoscope'; 
                        } elseif($baseType=='PMR') { 
                            $title='บันทึกเวชศาสตร์ฟื้นฟู (PMR Follow-up)'; $cardClass = 'card-pmr'; $iconClass = 'icon-pmr'; $faIcon = 'fa-person-walking-with-cane'; 
                        } elseif($baseType=='VISIT') { 
                            $title='บันทึกเยี่ยมบ้าน (Home Visit)'; $cardClass = 'card-visit'; $iconClass = 'icon-visit'; $faIcon = 'fa-house-medical'; 
                        } elseif($baseType=='OCC') {
                            $title='บันทึกอาชีวเวชกรรม (OCC Follow-up)'; $cardClass = 'card-med'; $iconClass = 'icon-med'; $faIcon = 'fa-briefcase-medical';
                        }
                    }
                    $time_str = date('H:i', $node['ts']);
                    $date_str = date('d/m/Y', $node['ts']);
                    $updated_str = isset($node['updated_ts']) ? date('d/m/Y H:i', $node['updated_ts']) : date('d/m/Y H:i', $node['ts']);
                ?>
                
                <div class="timeline-node">
                    <div class="timeline-icon-box">
                        <div class="timeline-icon <?php echo $iconClass; ?>"><i class="fa-solid <?php echo $faIcon; ?>"></i></div>
                        <div class="timeline-time"><?php echo $time_str; ?> น.</div>
                    </div>
                    
                    <div class="form-card <?php echo $cardClass; ?>">
                        <div class="form-header">
                            <div>
                                <div class="form-title">
                                    <?php echo htmlspecialchars((string)$title); ?>
                                    <?php if($type == 'WARD' && !empty($d['has_reheat'])) echo '<span class="badge bg-danger ms-2 shadow-sm" style="font-size:0.75rem;"><i class="fa-solid fa-fire me-1"></i> Re-Heat</span>'; ?>
                                    <?php if($an_badge): ?> <span class="badge bg-white text-dark border ms-2 font-monospace shadow-sm">AN: <?php echo htmlspecialchars((string)$an_badge); ?></span><?php endif; ?>
                                </div>
                                <div class="small text-muted mt-1"><i class="fa-regular fa-calendar-days me-1"></i> วันที่: <?php echo $date_str; ?> | 
                                    <i class="fa-solid fa-clock-rotate-left mx-1"></i> อัปเดตล่าสุด: <?php echo $updated_str; ?> | 
                                    <i class="fa-solid fa-user-check mx-1"></i> ผู้บันทึก: <?php echo htmlspecialchars((string)$doc); ?></div>
                                </div>
                            
                            <div class="no-print">
                                <?php 
                                    $can_edit_header = false;
                                    // ตรวจสอบเงื่อนไขว่าอนุญาตให้แสดงปุ่มแก้ไขหรือไม่ (ถ้าเคสปิดแล้ว ให้แค่ PCT เห็น)
                                    if ($can_edit_this_case) {
                                        if($type == 'ER' && $can_edit_er) $can_edit_header = true;
                                        if($type == 'GP' && $can_edit_gp) $can_edit_header = true;
                                        if($type == 'WARD') {
                                            if ($is_admin_or_pct) {
                                                $can_edit_header = true;
                                            } elseif (in_array($user_role, ['a4', 'b3', 'icu', 'rcu'])) {
                                                $rec_ward = strtolower($d['admit_type'] ?? '');
                                                if (strpos($rec_ward, $user_role) !== false) {
                                                    $can_edit_header = true;
                                                }
                                            }
                                        }
                                    }
                                    
                                    if($can_edit_header && isset($node['data'])): 
                                        $attrData = htmlspecialchars(json_encode($node['data']), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <button class="btn-edit-inline" data-json="<?php echo $attrData; ?>" onclick="openRecordEdit('<?php echo $type; ?>', this)">
                                        <i class="fa-solid fa-pen me-1"></i> แก้ไขฟอร์ม
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    
                    <div class="form-body">
                        <?php if($type == 'ER'): ?>
                            <div class="fm-row">
                                <div class="fm-col-half"><div class="fm-label">วัน/เวลาที่ผู้ป่วยมีอาการ</div><div class="fm-value"><?php echo htmlspecialchars((string)(str_replace('T', ' ', $d['arrival_time'] ?? '-'))); ?></div></div>
                                <div class="fm-col-half"><div class="fm-label">เวลาถึง รพ.</div><div class="fm-value"><?php echo htmlspecialchars((string)(str_replace('T', ' ', $d['hospital_arrival_datetime'] ?? '-'))); ?></div></div>
                            </div>
                            <div class="fm-row">
                                <div class="fm-col-half"><div class="fm-label">ปัจจัยการเจ็บป่วย</div><div class="fm-value"><?php echo htmlspecialchars((string)($d['cause_factor'] ?? '-')); ?></div></div>
                                <div class="fm-col-half"><div class="fm-label text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> ปัจจัยเสี่ยง</div><div class="fm-value text-danger small fw-bold"><?php echo htmlspecialchars((string)($d['risk_factors'] ?? '-')); ?></div></div>
                            </div>
                            
                            <div class="form-section-title"><i class="fa-solid fa-temperature-half me-1 text-primary"></i> ข้อมูลอุณหภูมิและการปฐมพยาบาล (Temperature & First Aid)</div>
                            
                            <div class="fm-multi-col">
                                <div class="fm-inner-col"><div class="fm-label px-2">Temp แรกรับ</div><div class="fm-value alert-text px-2"><?php echo htmlspecialchars((string)($d['ct_initial'] ?? '-')); ?> °C</div></div>
                                <div class="fm-inner-col"><div class="fm-label px-2">Temp 30 นาที</div><div class="fm-value px-2"><?php echo htmlspecialchars((string)($d['ct_30min'] ?? '-')); ?> °C</div></div>
                                <div class="fm-inner-col">
                                    <div class="fm-label px-2">Control Temp</div>
                                    <div class="fm-value px-2">
                                        <?php 
                                            $ct_ctrl = $d['ct_control'] ?? '-';
                                            if (strpos($ct_ctrl, '✓ เป็นไปตามมาตรฐาน') !== false) {
                                                echo '<span class="text-success fw-bold">' . htmlspecialchars((string)$ct_ctrl) . '</span>';
                                            } elseif (strpos($ct_ctrl, '✗ ไม่เป็นไปตามมาตรฐาน') !== false) {
                                                echo '<span class="text-danger fw-bold">' . htmlspecialchars((string)$ct_ctrl) . '</span>';
                                            } else {
                                                echo htmlspecialchars((string)$ct_ctrl);
                                            }
                                        ?>
                                    </div>
                                </div>
                                <div class="fm-inner-col"><div class="fm-label px-2">ปฐมพยาบาลมา</div><div class="fm-value px-2"><?php echo htmlspecialchars((string)($d['first_aid'] ?? '-')); ?></div></div>
                            </div>

                            <div class="form-section-title"><i class="fa-solid fa-stethoscope me-1 text-primary"></i> การวินิจฉัยและการจำหน่าย (Diagnosis & Disposition)</div>
                            <div class="fm-row" style="flex-wrap: nowrap; gap: 12px;">
                                <div class="fm-col-half" style="flex: 1 1 0; min-width: 0;">
                                    <div class="fm-label text-danger">การวินิจฉัย (FstDx)</div>
                                    <div class="fm-value alert-text"><?php echo nl2br(htmlspecialchars((string)($d['FstDx'] ?? '-'))); ?></div>
                                </div>
                                <div class="fm-col-half" style="flex: 1 1 0; min-width: 0;">
                                    <div class="fm-label">การจำหน่าย (Disposition)</div>
                                    <div class="fm-value text-primary fw-bold">
                                        <?php 
                                            echo htmlspecialchars((string)($d['disposition'] ?? '-'));
                                            if ($d['disposition'] === 'Admit' && !empty($d['admit_type'])) echo " <i class='fa-solid fa-arrow-right mx-1'></i> " . htmlspecialchars((string)$d['admit_type']);
                                            elseif ($d['disposition'] === 'Refer' && !empty($d['refer_hospital'])) echo " <i class='fa-solid fa-arrow-right mx-1'></i> " . htmlspecialchars((string)$d['refer_hospital']);
                                        ?>
                                    </div>
                                </div>
                                <div class="fm-col-half" style="flex: 1 1 0; min-width: 0;">
                                    <div class="fm-label">วัน/เวลาที่จำหน่าย</div>
                                    <div class="fm-value">
                                        <?php echo htmlspecialchars((string)(str_replace('T', ' ', $d['er_discharge_date'] ?? '-'))) ;?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="fm-block">
                                <div class="fm-block-label">ผลการรักษา (Treatment Result):</div>
                                <div class="fm-value p-0"><?php echo nl2br(htmlspecialchars((string)($d['treatment_result'] ?? '-'))); ?></div>
                            </div>

                        <?php elseif($type == 'GP'): ?>
                            <div class="fm-row">
                                <div class="fm-col-half"><div class="fm-label">วัน/เวลาที่ผู้ป่วยมีอาการ</div><div class="fm-value"><?php echo htmlspecialchars((string)(str_replace('T', ' ', $d['arrival_time'] ?? '-'))); ?></div></div>
                                <div class="fm-col-half"><div class="fm-label">เวลาถึง รพ.</div><div class="fm-value"><?php echo htmlspecialchars((string)(str_replace('T', ' ', $d['hospital_arrival_datetime'] ?? '-'))); ?></div></div>
                            </div>
                            <div class="fm-row">
                                <div class="fm-col-half"><div class="fm-label">ปัจจัยการเจ็บป่วย</div><div class="fm-value"><?php echo htmlspecialchars((string)($d['cause_factor'] ?? '-')); ?></div></div>
                                <div class="fm-col-half"><div class="fm-label text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> ปัจจัยเสี่ยง</div><div class="fm-value text-danger small fw-bold"><?php echo htmlspecialchars((string)($d['risk_factors'] ?? '-')); ?></div></div>
                            </div>
                            
                            <div class="form-section-title"><i class="fa-solid fa-temperature-half me-1 text-primary"></i> ข้อมูลอุณหภูมิและการปฐมพยาบาล (Temperature & First Aid)</div>
                            
                            <div class="fm-multi-col">
                                <div class="fm-inner-col"><div class="fm-label px-2">Temp แรกรับ</div><div class="fm-value alert-text px-2"><?php echo htmlspecialchars((string)($d['ct_initial'] ?? '-')); ?> °C</div></div>
                                <div class="fm-inner-col"><div class="fm-label px-2">Temp 30 นาที</div><div class="fm-value px-2"><?php echo htmlspecialchars((string)($d['ct_30min'] ?? '-')); ?> °C</div></div>
                                <div class="fm-inner-col">
                                    <div class="fm-label px-2">Control Temp</div>
                                    <div class="fm-value px-2">
                                        <?php 
                                            $ct_ctrl = $d['ct_control'] ?? '-';
                                            if (strpos($ct_ctrl, '✓ เป็นไปตามมาตรฐาน') !== false) {
                                                echo '<span class="text-success fw-bold">' . htmlspecialchars((string)$ct_ctrl) . '</span>';
                                            } elseif (strpos($ct_ctrl, '✗ ไม่เป็นไปตามมาตรฐาน') !== false) {
                                                echo '<span class="text-danger fw-bold">' . htmlspecialchars((string)$ct_ctrl) . '</span>';
                                            } else {
                                                echo htmlspecialchars((string)$ct_ctrl);
                                            }
                                        ?>
                                    </div>
                                </div>
                                <div class="fm-inner-col"><div class="fm-label px-2">ปฐมพยาบาลมา</div><div class="fm-value px-2"><?php echo htmlspecialchars((string)($d['first_aid'] ?? '-')); ?></div></div>
                            </div>

                            <div class="form-section-title"><i class="fa-solid fa-stethoscope me-1 text-primary"></i> การวินิจฉัยและการจำหน่าย (Diagnosis & Disposition)</div>
                            <div class="fm-row">
                                <div class="fm-col-half"><div class="fm-label text-danger">การวินิจฉัย (FstDx)</div><div class="fm-value alert-text"><?php echo nl2br(htmlspecialchars((string)($d['FstDx'] ?? '-'))); ?></div></div>
                                <div class="fm-col-half">
                                    <div class="fm-label">การจำหน่าย (Disposition)</div>
                                    <div class="fm-value text-primary fw-bold">
                                        <?php 
                                            echo htmlspecialchars((string)($d['disposition'] ?? '-'));
                                            if ($d['disposition'] === 'Admit' && !empty($d['admit_type'])) echo " <i class='fa-solid fa-arrow-right mx-1'></i> " . htmlspecialchars((string)$d['admit_type']);
                                            elseif ($d['disposition'] === 'Refer' && !empty($d['refer_hospital'])) echo " <i class='fa-solid fa-arrow-right mx-1'></i> " . htmlspecialchars((string)$d['refer_hospital']);
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="fm-block">
                                <div class="fm-block-label">ผลการรักษา (Treatment Result):</div>
                                <div class="fm-value p-0"><?php echo nl2br(htmlspecialchars((string)($d['treatment_result'] ?? '-'))); ?></div>
                            </div>

                        <?php elseif($type == 'WARD'): ?>
                            <div class="fm-row">
                                    <div class="fm-label">วินิจฉัยแรกรับ (First Diagnosis)</div>
                                    <div class="fm-value fw-bold"><?php echo htmlspecialchars((string)(!empty($d['initial_diagnosis']) ? $d['initial_diagnosis'] : '-')); ?></div>
                                    <div class="fm-label text-danger">วินิจฉัยสุดท้าย (Final Diagnosis)</div>
                                    <div class="fm-value fw-bold text-danger"><?php echo htmlspecialchars((string)(!empty($d['final_diagnosis']) ? $d['final_diagnosis'] : '-')); ?></div>
                            </div>
                            
                            <?php if(!empty($d['icu_diagnosis']) || !empty($d['icu_core_temp_first'])): ?>
                                <div class="form-section-title" style="background:#fff3cd; color:#856404; border-color:#ffeeba;"><i class="fa-solid fa-heart-pulse me-1"></i> ข้อมูลเฉพาะหอผู้ป่วยวิกฤต (ICU Data)</div>
                                <div class="fm-row">
                                    <div class="fm-col-half"><div class="fm-label">วินิจฉัยเข้า ICU</div><div class="fm-value alert-text fw-bold"><?php echo htmlspecialchars((string)($d['icu_diagnosis'] ?? '-')); ?></div></div>
                                    <div class="fm-col-half"><div class="fm-label">เวลา ER -> ICU</div><div class="fm-value"><?php echo htmlspecialchars((string)($d['icu_time_er_to_icu'] ?? '-')); ?> นาที</div></div>
                                </div>
                                <div class="fm-multi-col">
                                    <div class="fm-inner-col"><div class="fm-label px-2">CoreTemp แรกรับ</div><div class="fm-value alert-text px-2"><?php echo htmlspecialchars((string)($d['icu_core_temp_first'] ?? '-')); ?> °C</div></div>
                                    <div class="fm-inner-col"><div class="fm-label px-2">CoreTemp 30m</div><div class="fm-value px-2"><?php echo htmlspecialchars((string)($d['icu_core_temp_30min'] ?? '-')); ?> °C</div></div>
                                    <div class="fm-inner-col"><div class="fm-label px-2">Control Temp</div><div class="fm-value px-2"><?php echo htmlspecialchars((string)($d['icu_control_temp'] ?? '-')); ?></div></div>
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($d['discharge_date'])): ?>
                                <div class="form-section-title" style="background:#d1e7dd; color:#0f5132; border-color:#badbcc;"><i class="fa-solid fa-file-signature me-1"></i> สรุปการจำหน่าย (Discharge Summary)</div>
                                <div class="fm-row">
                                    <div class="fm-col-half"><div class="fm-label">วัน/เวลา จำหน่าย</div><div class="fm-value"><?php echo date('d/m/Y H:i', strtotime($d['discharge_date'])); ?></div></div>
                                    <div class="fm-col-half"><div class="fm-label">สถานภาพการจำหน่าย</div><div class="fm-value text-success fw-bold"><i class="fa-regular fa-circle-check me-1"></i><?php echo htmlspecialchars((string)($d['discharge_status'] ?? '-')); ?></div></div>
                                </div>
                                <div class="fm-row">
                                    <div class="fm-label text-success">การวินิจฉัยขั้นสุดท้าย (Final Dx)</div>
                                    <div class="fm-value text-success fw-bold" style="font-size: 1.1rem;"><?php echo htmlspecialchars((string)($d['final_diagnosis'] ?? '-')); ?></div>
                                </div>
                                <div class="fm-block">
                                    <div class="fm-block-label">รายละเอียด/คำแนะนำ:</div>
                                    <div class="fm-value p-0"><?php echo nl2br(htmlspecialchars((string)($d['discharge_detail'] ?? '-'))); ?></div>
                                    <?php if(!empty($d['death_cause'])) echo "<div class='text-danger mt-3 bg-red-50 p-2 rounded'><i class='fa-solid fa-skull me-1'></i> สาเหตุการตาย: ".htmlspecialchars((string)$d['death_cause'])."</div>"; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="p-3 bg-white no-print" style="border-radius: 0 0 10px 10px;">
                                <button class="btn-notes" onclick="toggleNotes(<?php echo $d['id']; ?>)">
                                    <i class="fa-solid fa-list-check me-2"></i> ดูบันทึกอาการรายวัน (Progress Notes) <i class="fa-solid fa-caret-down ms-1"></i>
                                </button>
                                <div id="notes-screen-<?php echo $d['id']; ?>" class="notes-panel p-3">
                                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                        <strong class="text-primary font-head"><i class="fa-solid fa-clipboard me-1"></i> บันทึกพยาบาล / แพทย์</strong>
                                        <div class="d-flex gap-2">
                                            <input type="date" class="form-control form-control-sm border-secondary date-filter-<?php echo $d['id']; ?>">
                                            <button class="btn btn-sm btn-primary" onclick="loadNotes(<?php echo $d['id']; ?>)">ค้นหา</button>
                                        </div>
                                    </div>
                                    <div class="notes-target-<?php echo $d['id']; ?> text-center p-4 text-muted bg-light rounded">คลิกปุ่มค้นหาเพื่อโหลดข้อมูล</div>
                                </div>
                            </div>
                            <div id="notes-print-<?php echo $d['id']; ?>" class="print-only" style="margin-top: 15px; display: none; break-inside: auto;">
                                <div class="print-notes-header" style="background: #e9ecef; padding: 8px; text-align: center; font-weight: bold; border:1.5px solid #000; border-bottom:1px solid #000;">บันทึกอาการรายวัน (Progress Notes) - <?php echo htmlspecialchars((string)$d['admit_type']); ?> AN: <?php echo htmlspecialchars((string)$d['an']); ?></div>
                                <div class="notes-target-<?php echo $d['id']; ?>" style="border:1.5px solid #000; border-top:none; padding:10px; break-inside: auto;"></div>
                            </div>

                        <?php elseif(strpos($type, '_GROUP') !== false): 
                            $baseType = str_replace('_GROUP', '', $type);
                            $recs = $node['records'];
                        ?>
                            <?php foreach($recs as $idx => $r): ?>
                                <div class="form-section-title d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <i class="fa-regular fa-calendar-check me-1 text-primary"></i> <span>ครั้งที่ <?php echo count($recs)-$idx; ?> - <?php echo date('d/m/Y H:i', strtotime($r['record_datetime'])); ?></span>
                                        <span class="ms-md-3 text-muted fw-normal d-block d-md-inline mt-1 mt-md-0"><i class="fa-solid fa-user-pen me-1"></i> ผู้บันทึก: <?php echo htmlspecialchars((string)($r['recorder_name'] ?? '-')); ?></span>
                                    </div>
                                    
                                    <div class="no-print">
                                        <?php 
                                            $can_edit_sub = false;
                                            // 🟢 ตรวจสอบเงื่อนไขว่าอนุญาตให้แสดงปุ่มแก้ไขหรือไม่
                                            if ($can_edit_this_case) {
                                                if($baseType == 'MED' && $can_edit_med) $can_edit_sub = true;
                                                if($baseType == 'OCC' && $can_edit_occ) $can_edit_sub = true;
                                                if($baseType == 'PMR' && $can_edit_pmr) $can_edit_sub = true;
                                                if($baseType == 'VISIT' && $can_edit_visit) $can_edit_sub = true;
                                            }
                                            
                                            if($can_edit_sub):
                                                $subAttrData = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                                        ?>
                                            <?php if ($baseType === 'OCC'): ?>
                                                <a class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.8rem; border-radius: 4px;" href="OCC/edit_occ.php?id=<?php echo (int)$r['id']; ?>" data-occ-edit-url="OCC/edit_occ.php?id=<?php echo (int)$r['id']; ?>">
                                                    <i class="fa-solid fa-pen"></i> แก้ไข
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.8rem; border-radius: 4px;" data-json="<?php echo $subAttrData; ?>" onclick="openRecordEdit('<?php echo $baseType; ?>', this)">
                                                    <i class="fa-solid fa-pen"></i> แก้ไข
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if($baseType == 'MED' || $baseType == 'PMR'): ?>
                                    <div class="fm-row">
                                        <div class="fm-col-half"><div class="fm-label">ผลการประเมิน</div><div class="fm-value text-primary fw-bold"><?php echo htmlspecialchars((string)($r[strtolower($baseType).'_status'] ?? '-')); ?></div></div>
                                        <div class="fm-col-half"><div class="fm-label text-danger">วันนัดครั้งต่อไป</div><div class="fm-value text-danger fw-bold"><i class="fa-regular fa-calendar-plus me-1"></i><?php echo !empty($r['next_'.$baseType.'_date']) ? date('d/m/Y', strtotime($r['next_'.$baseType.'_date'])) : '-'; ?></div></div>
                                    </div>
                                    <?php if ($baseType === 'MED'): ?>
                                        <div class="fm-row">
                                            <?php
                                                $med_lab_fields = ['CPK' => 'cpk', 'BUN' => 'bun', 'Cr' => 'cr', 'Electrolyte' => 'eletroride'];
                                                foreach ($med_lab_fields as $lab_label => $lab_field):
                                                    $lab_value = $r[$lab_field] ?? '';
                                            ?>
                                                <div class="fm-col-half">
                                                    <div class="fm-label"><?php echo $lab_label; ?></div>
                                                    <div class="fm-value"><?php echo $lab_value !== '' ? htmlspecialchars((string)$lab_value, ENT_QUOTES, 'UTF-8') : '-'; ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="fm-block">
                                        <div class="fm-block-label">บันทึกเพิ่มเติม:</div>
                                        <div class="fm-value p-0"><?php echo nl2br(htmlspecialchars((string)($r[strtolower($baseType).'_note'] ?? '-'))); ?></div>
                                    </div>
                                <?php elseif($baseType == 'OCC'): ?>
                                    <div class="fm-row">
                                        <div class="fm-col-half">
                                            <div class="fm-label">วันที่ Follow-up</div>
                                            <div class="fm-value"><?php echo !empty($r['fu_date']) ? date('d/m/Y H:i', strtotime($r['fu_date'])) : '-'; ?></div>
                                        </div>
                                        <div class="fm-col-half">
                                            <div class="fm-label">สถานะ OCC</div>
                                            <div class="fm-value text-primary fw-bold"><?php echo htmlspecialchars((string)($r['occ_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                    </div>
                                    <div class="fm-block">
                                        <div class="fm-block-label">บันทึก OCC:</div>
                                        <div class="fm-value p-0"><?php echo nl2br(htmlspecialchars((string)($r['occ_note'] ?? '-'), ENT_QUOTES, 'UTF-8')); ?></div>
                                    </div>
                                
                                <?php elseif($baseType == 'VISIT'): ?>
                                    <div class="fm-row">
                                        <div class="fm-col-half"><div class="fm-label">อุณหภูมิ / ปัสสาวะ</div><div class="fm-value"><span class="text-danger fw-bold"><?php echo htmlspecialchars((string)($r['body_temperature'] ?? '-')); ?> °C</span> <span class="text-muted mx-1">/</span> <span class="text-warning fw-bold"><?php echo htmlspecialchars((string)($r['urine_color'] ?? '-')); ?></span></div></div>
                                        <div class="fm-col-half"><div class="fm-label text-danger">วันนัดเยี่ยมต่อไป</div><div class="fm-value text-danger fw-bold"><i class="fa-regular fa-calendar-plus me-1"></i><?php echo !empty($r['next_visit_date']) ? date('d/m/Y', strtotime($r['next_visit_date'])) : '-'; ?></div></div>
                                    </div>
                                    <div class="fm-row">
                                        <div class="fm-col-half"><div class="fm-label">อาการที่พบ</div><div class="fm-value"><?php echo htmlspecialchars((string)($r['symptoms'] ?? '-')); ?></div></div>
                                        <div class="fm-col-half"><div class="fm-label">กิจกรรม</div><div class="fm-value"><?php echo htmlspecialchars((string)($r['activity_details'] ?? '-')); ?></div></div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div> <!-- ปิดกล่อง Form Body ให้ครบสมบูรณ์ตรงนี้ -->
                </div> <!-- ปิดกล่อง Form Card ให้ครบสมบูรณ์ตรงนี้ -->
            </div> <!-- ปิดกล่อง Timeline Node ให้ครบสมบูรณ์ตรงนี้ -->
                
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ==============================================================
     MODALS สำหรับแก้ไขข้อมูล (ซ่อนไว้เสมอ จะเปิดด้วย JS)
     ============================================================== -->
<!-- ฟอร์มซ่อนสำหรับการลบข้อมูลทั้งไทม์ไลน์ (Admin/PCT Only) -->
<?php if($is_admin_or_pct): ?>
<form id="pctDeleteCaseForm" method="POST">
    <input type="hidden" name="pct_delete_case_submit" value="1">
    <input type="hidden" name="delete_case_id" id="delete_case_id">
</form>
<?php endif; ?>

<!-- 1. Modal WARD (แก้ข้อมูลแผนก - ล็อกการจำหน่ายไว้ตามรีเควส) -->
<div class="modal fade" id="modalEditWARD" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header" style="background: var(--t-grad); color:white; border-bottom: none;">
                    <h5 class="modal-title font-head"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขข้อมูลหอผู้ป่วย (Inpatient Form)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="record_edit_submit" value="1">
                    <input type="hidden" name="edit_type" value="WARD">
                    <input type="hidden" name="record_id" id="edit_ward_id">
                    
                    <div class="row g-3 mb-3 p-3 bg-white border rounded shadow-sm mx-0">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">
                                AN <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" name="an" id="edit_ward_an" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">แพทย์เจ้าของไข้</label>
                            <input type="text" class="form-control" name="doctor_name" id="edit_ward_doctor_name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">
                                วันที่รับเข้ารักษา <span class="text-danger">*</span>
                            </label>
                            <input type="datetime-local" class="form-control" name="admit_date" id="edit_ward_admit_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">วันที่จำหน่าย</label>
                            <input type="datetime-local" class="form-control" name="discharge_date" id="edit_ward_discharge_date">
                        </div>
                        <div class="col-12 mt-3">
                            <label class="form-label fw-bold" style="color:var(--t-dark);">
                                วินิจฉัยแรกรับ (First Diagnosis)
                            </label>

                            <select name="initial_diagnosis_select"
                                    id="edit_ward_initial_sel"
                                    class="form-select"
                                    onchange="toggleEditOther(this, 'edit_ward_initial_oth')">

                                <option value="">-- เลือกวินิจฉัย --</option>

                                <?php foreach($diag_list as $dx) echo "<option value=\"$dx\">$dx</option>"; ?>

                                <option value="other">อื่นๆ ที่ไม่ใช่ Heat (ระบุ)</option>
                            </select>

                            <input type="text"
                                name="initial_diagnosis_other"
                                id="edit_ward_initial_oth"
                                class="form-control mt-2 d-none"
                                placeholder="ระบุวินิจฉัยอื่นๆ...">
                        </div>

                        <div class="col-12 mt-3">
                            <label class="form-label fw-bold text-success">
                                <i class="fa-solid fa-file-signature me-1"></i>
                                วินิจฉัยขั้นสุดท้าย (Final Diagnosis)
                            </label>

                            <select name="final_diagnosis_select"
                                    id="edit_ward_final_sel"
                                    class="form-select border-success"
                                    onchange="toggleEditOther(this, 'edit_ward_final_oth')">

                                <option value="">-- เลือกวินิจฉัยสุดท้าย --</option>

                                <?php foreach($diag_list as $dx) echo "<option value=\"$dx\">$dx</option>"; ?>

                                <option value="other">อื่นๆ ที่ไม่ใช่ Heat (ระบุ)</option>
                            </select>

                            <input type="text"
                                name="final_diagnosis_other"
                                id="edit_ward_final_oth"
                                class="form-control mt-2 d-none border-success"
                                placeholder="ระบุวินิจฉัยสุดท้ายอื่นๆ...">
                        </div>

                    </div>

                    <!-- ซ่อนโชว์สำหรับข้อมูล ICU -->
                    <div class="card border-warning mb-3 shadow-sm" id="ward_icu_block" style="display:none;">
                        <div class="card-header bg-warning text-dark fw-bold"><i class="fa-solid fa-heart-pulse"></i> ข้อมูลเฉพาะกรณีเข้า ICU</div>
                        <div class="card-body bg-white">

                            <!-- วินิจฉัย -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold">วินิจฉัยแรกรับจาก ER</label>
                                <select name="icu_diagnosis_select" id="edit_ward_icu_dx_sel" class="form-select form-select-sm" onchange="toggleEditOther(this, 'edit_ward_icu_dx_oth')">
                                     <option value="">-- เลือกวินิจฉัย --</option> 
                                     <?php foreach($diag_list as $dx) echo "<option value=\"$dx\">$dx</option>"; ?> 
                                     <option value="other">อื่นๆ (ระบุ)</option> 
                                </select>
                                <input type="text" name="icu_diagnosis_other" id="edit_ward_icu_dx_oth"
                                 class="form-control form-control-sm mt-2 d-none" placeholder="ระบุวินิจฉัย ICU...">
                            </div>
                            <!-- เวลา -->
                            <div class="row g-2 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">วัน/เวลาที่มีอาการ</label>
                                    <input type="datetime-local"
                                        name="icu_time_symptoms"
                                        id="edit_ward_time_symptoms"
                                        class="form-control form-control-sm">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">เวลาที่ถึง ER</label>
                                    <input type="datetime-local"
                                        name="icu_time_hospital"
                                        id="edit_ward_time_hospital"
                                        class="form-control form-control-sm">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">เวลา ER → ICU (นาที)</label>
                                    <input type="number"
                                        name="icu_time_er_to_icu"
                                        id="edit_ward_time"
                                        class="form-control form-control-sm">
                                </div>
                            </div>

                            <!-- อุณหภูมิ -->
                            <div class="row g-2 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">CoreTemp แรกรับ</label>
                                    <input type="number" step="0.1"
                                        name="icu_core_temp_first"
                                        id="edit_ward_ct1"
                                        class="form-control form-control-sm">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">CoreTemp 30 นาที</label>
                                    <input type="number" step="0.1"
                                        name="icu_core_temp_30min"
                                        id="edit_ward_ct30"
                                        class="form-control form-control-sm">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">Control Temp ใน 24 ชม.</label>
                                    <select name="icu_control_temp"
                                        id="edit_ward_ctrl"
                                        class="form-select form-select-sm">

                                        <option value="">-- ไม่ระบุ --</option>
                                        <option value="Yes">ได้ตามเกณฑ์</option>
                                        <option value="No">ไม่ได้ตามเกณฑ์</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                    <!-- แจ้งเตือนล็อกการจำหน่าย -->
                    <div class="alert alert-lock d-flex align-items-center mt-3 mb-0 bg-white" style="border: 1px dashed #cbd5e1; border-left: 4px solid #64748b; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                        <div class="me-3"><i class="fa-solid fa-lock fa-2x text-secondary opacity-50"></i></div>
                        <div>
                            <strong class="text-secondary d-block mb-1">ส่วนการจำหน่ายถูกล็อก (Discharge Locked)</strong>
                            <span class="text-muted small">เพื่อรักษาความถูกต้องของ Timeline ระบบไม่อนุญาตให้แก้ผลการจำหน่ายในหน้านี้ หากต้องการแก้ไขแผนการรักษา กรุณาลบข้อมูลรอบนี้แล้วสร้างใหม่</span>
                        </div>
                    </div>

                <div class="modal-footer bg-white border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-save"></i> บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2. Modal ER -->
<div class="modal fade" id="modalEditER" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header" style="background: var(--t-grad); color:white; border-bottom: none;">
                    <h5 class="modal-title font-head"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขข้อมูลห้องฉุกเฉิน (ER Form)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="record_edit_submit" value="1">
                    <input type="hidden" name="edit_type" value="ER">
                    <input type="hidden" name="record_id" id="edit_er_id">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6 bg-white p-3 border rounded shadow-sm">
                            <label class="form-label small fw-bold text-muted">วัน/เวลาที่ผู้ป่วยมีอาการ</label>
                            <input type="datetime-local" step="1" class="form-control" name="arrival_time" id="edit_er_arr">
                        </div>
                        <div class="col-md-6 bg-white p-3 border rounded shadow-sm">
                            <label class="form-label small fw-bold text-muted">เวลาถึง รพ.</label>
                            <input type="datetime-local" step="1" class="form-control" name="hospital_arrival_datetime" id="edit_er_hosp">
                        </div>
                    </div>

                    <div class="bg-white p-3 border rounded mb-3 shadow-sm">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-danger">วินิจฉัยแรกรับ ER (FstDx)</label>
                            <select name="FstDx_select" id="edit_er_fstdx_sel" class="form-select border-danger" onchange="toggleEditOther(this, 'edit_er_fstdx_oth')" required>
                                <option value="">-- เลือกวินิจฉัย --</option>
                                <?php foreach($diag_list as $dx) echo "<option value=\"$dx\">$dx</option>"; ?>
                                <option value="other">อื่นๆ ที่ไม่ใช่ Heat (ระบุ)</option>
                            </select>
                            <input type="text" name="FstDx_other" id="edit_er_fstdx_oth" class="form-control border-danger mt-2 d-none" placeholder="ระบุวินิจฉัยอื่นๆ...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">ปัจจัยการเจ็บป่วย</label>
                            <select name="cause_factor_select" id="edit_er_cause_sel" class="form-select form-select-sm" onchange="toggleEditOther(this, 'edit_er_cause_oth')">
                                <option value="">-- เลือกปัจจัย --</option>
                                <?php foreach($cause_list as $c) echo "<option value=\"$c\">$c</option>"; ?>
                                <option value="other">อื่นๆ (ระบุ)</option>
                            </select>
                            <input type="text" name="cause_factor_other" id="edit_er_cause_oth" class="form-control form-control-sm mt-2 d-none" placeholder="โปรดระบุ">
                        </div>
                    </div>

                    <div class="mb-3 p-3 bg-white border rounded shadow-sm">
                        <label class="form-label small fw-bold text-danger mb-2"><i class="fa-solid fa-triangle-exclamation"></i> ปัจจัยเสี่ยง</label>
                        <div class="row g-2">
                            <?php foreach ($risk_list as $idx => $risk): ?>
                            <div class="col-md-4">
                                <div class="form-check form-check-sm">
                                    <input class="form-check-input pct-er-risk" type="checkbox" name="risk_factors[]" value="<?php echo $risk; ?>" id="pct_risk_<?php echo $idx; ?>">
                                    <label class="form-check-label small" for="pct_risk_<?php echo $idx; ?>"><?php echo $risk; ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="row g-2 mb-3 bg-white p-3 border rounded mx-0 shadow-sm">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Temp แรกรับ</label>
                            <input type="number" step="0.1" class="form-control form-control-sm" name="ct_initial" id="edit_er_ct1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Temp 30 นาที</label>
                            <input type="number" step="0.1" class="form-control form-control-sm" name="ct_30min" id="edit_er_ct30">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Control Temp</label>
                            <select name="ct_control" id="edit_er_ctrl" class="form-select form-select-sm" style="background-color: #f8f9fa;">
                                <option value="">-- รอผล --</option>
                                <option value="✓ เป็นไปตามมาตรฐาน">✓ เป็นไปตามมาตรฐาน</option>
                                <option value="✗ ไม่เป็นไปตามมาตรฐาน">✗ ไม่เป็นไปตามมาตรฐาน</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">ปฐมพยาบาล</label>
                            <select name="first_aid" id="edit_er_firstaid" class="form-select form-select-sm">
                                <option value="">-- เลือก --</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3 bg-white p-3 border rounded shadow-sm">
                        <label class="form-label small fw-bold text-primary">ผลการรักษา (Treatment Result)</label>
                        <textarea name="treatment_result" id="edit_er_treatment_result" class="form-control" rows="3" placeholder="ระบุผลการรักษา..."></textarea>
                    </div>

                    <div id="edit_er_discharge_block" class="d-none mb-3 bg-white p-3 border border-success rounded shadow-sm">
                        <label class="form-label small fw-bold text-success">
                            <i class="fa-solid fa-calendar-check me-1"></i>
                            วันเวลาจำหน่าย (Discharge Date/Time)
                        </label>
                        <input type="datetime-local" step="1" class="form-control border-success" name="recorded_at_discharge" id="edit_er_recorded_at">
                        
                    </div>

                </div>
                <div class="modal-footer bg-white border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-save"></i> บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditGP" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header" style="background: var(--t-grad); color:white; border-bottom: none;">
                    <h5 class="modal-title font-head">
                        <i class="fa-solid fa-pen-to-square me-2"></i>
                        แก้ไขข้อมูล GP Form
                    </h5>

                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4 bg-light">

                    <input type="hidden" name="record_edit_submit" value="1">
                    <input type="hidden" name="edit_type" value="GP">
                    <input type="hidden" name="record_id" id="edit_gp_id">

                    <div class="row g-3 mb-3">

                        <div class="col-md-6 bg-white p-3 border rounded shadow-sm">
                            <label class="form-label small fw-bold text-muted">
                                วัน/เวลาที่ผู้ป่วยมีอาการ
                            </label>

                            <input type="datetime-local"
                                   step="1"
                                   class="form-control"
                                   name="arrival_time"
                                   id="edit_gp_arr">
                        </div>

                        <div class="col-md-6 bg-white p-3 border rounded shadow-sm">
                            <label class="form-label small fw-bold text-muted">
                                เวลาถึง รพ.
                            </label>

                            <input type="datetime-local"
                                   step="1"
                                   class="form-control"
                                   name="hospital_arrival_datetime"
                                   id="edit_gp_hosp">
                        </div>

                    </div>

                    <div class="bg-white p-3 border rounded mb-3 shadow-sm">

                        <div class="mb-3">
                            <label class="form-label fw-bold text-danger">
                                วินิจฉัยแรกรับ GP (FstDx)
                            </label>

                            <select name="FstDx_select"
                                    id="edit_gp_fstdx_sel"
                                    class="form-select border-danger"
                                    onchange="toggleEditOther(this, 'edit_gp_fstdx_oth')"
                                    required>

                                <option value="">-- เลือกวินิจฉัย --</option>

                                <?php foreach($diag_list as $dx) echo "<option value=\"$dx\">$dx</option>"; ?>

                                <option value="other">
                                    อื่นๆ ที่ไม่ใช่ Heat (ระบุ)
                                </option>
                            </select>

                            <input type="text"
                                   name="FstDx_other"
                                   id="edit_gp_fstdx_oth"
                                   class="form-control border-danger mt-2 d-none"
                                   placeholder="ระบุวินิจฉัยอื่นๆ...">
                        </div>

                        <div class="mb-3">

                            <label class="form-label small fw-bold">
                                ปัจจัยการเจ็บป่วย
                            </label>

                            <select name="cause_factor_select"
                                    id="edit_gp_cause_sel"
                                    class="form-select form-select-sm"
                                    onchange="toggleEditOther(this, 'edit_gp_cause_oth')">

                                <option value="">-- เลือกปัจจัย --</option>

                                <?php foreach($cause_list as $c) echo "<option value=\"$c\">$c</option>"; ?>

                                <option value="other">
                                    อื่นๆ (ระบุ)
                                </option>
                            </select>

                            <input type="text"
                                   name="cause_factor_other"
                                   id="edit_gp_cause_oth"
                                   class="form-control form-control-sm mt-2 d-none"
                                   placeholder="โปรดระบุ">
                        </div>

                    </div>

                    <div class="mb-3 p-3 bg-white border rounded shadow-sm">

                        <label class="form-label small fw-bold text-danger mb-2">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            ปัจจัยเสี่ยง
                        </label>

                        <div class="row g-2">

                            <?php foreach ($risk_list as $idx => $risk): ?>

                            <div class="col-md-4">

                                <div class="form-check form-check-sm">

                                    <input class="form-check-input pct-gp-risk"
                                           type="checkbox"
                                           name="risk_factors[]"
                                           value="<?php echo $risk; ?>"
                                           id="pct_gp_risk_<?php echo $idx; ?>">

                                    <label class="form-check-label small"
                                           for="pct_gp_risk_<?php echo $idx; ?>">

                                        <?php echo $risk; ?>

                                    </label>

                                </div>

                            </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                    <div class="row g-2 mb-3 bg-white p-3 border rounded mx-0 shadow-sm">

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">
                                Temp แรกรับ
                            </label>

                            <input type="number"
                                   step="0.1"
                                   class="form-control form-control-sm"
                                   name="ct_initial"
                                   id="edit_gp_ct1">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold">
                                Temp 30 นาที
                            </label>

                            <input type="number"
                                   step="0.1"
                                   class="form-control form-control-sm"
                                   name="ct_30min"
                                   id="edit_gp_ct30">
                        </div>

                        <div class="col-md-3">

                            <label class="form-label small fw-bold">
                                Control Temp
                            </label>

                            <select name="ct_control"
                                    id="edit_gp_ctrl"
                                    class="form-select form-select-sm"
                                    style="background-color: #f8f9fa;">

                                <option value="">-- รอผล --</option>
                                <option value="✓ เป็นไปตามมาตรฐาน">
                                    ✓ เป็นไปตามมาตรฐาน
                                </option>
                                <option value="✗ ไม่เป็นไปตามมาตรฐาน">
                                    ✗ ไม่เป็นไปตามมาตรฐาน
                                </option>
                            </select>

                        </div>

                        <div class="col-md-3">

                            <label class="form-label small fw-bold">
                                ปฐมพยาบาล
                            </label>

                            <select name="first_aid"
                                    id="edit_gp_firstaid"
                                    class="form-select form-select-sm">

                                <option value="">-- เลือก --</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>

                            </select>

                        </div>

                    </div>

                    <div class="mb-3 bg-white p-3 border rounded shadow-sm">

                        <label class="form-label small fw-bold text-primary">
                            ผลการรักษา (Treatment Result)
                        </label>

                        <textarea name="treatment_result"
                                  id="edit_gp_treatment_result"
                                  class="form-control"
                                  rows="3"
                                  placeholder="ระบุผลการรักษา..."></textarea>

                    </div>

                    <div id="edit_gp_discharge_block" class="d-none mb-3 bg-white p-3 border border-success rounded shadow-sm">
                        <label class="form-label small fw-bold text-success">
                            <i class="fa-solid fa-calendar-check me-1"></i>
                            วันเวลาจำหน่าย (Discharge Date/Time)
                        </label>
                        <input type="datetime-local" step="1" class="form-control border-success" name="recorded_at_discharge" id="edit_gp_recorded_at">
                    </div>

                </div>

                <div class="modal-footer bg-white border-top">

                    <button type="button"
                            class="btn btn-light border"
                            data-bs-dismiss="modal">
                        ยกเลิก
                    </button>

                    <button type="submit"
                            class="btn btn-success px-4">

                        <i class="fa-solid fa-save"></i>
                        บันทึกการแก้ไข

                    </button>

                </div>
            </form>
        </div>
    </div>
</div>

<!-- 3. Modal MED / PMR (ใช้ฟอร์มแนวคิดเดียวกัน) -->
<div class="modal fade" id="modalEditFollowup" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header" style="background: var(--t-grad); color:white; border-bottom: none;">
                    <h5 class="modal-title font-head" id="followupModalTitle"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขบันทึกติดตาม</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="record_edit_submit" value="1">
                    <input type="hidden" name="edit_type" id="edit_followup_type">
                    <input type="hidden" name="record_id" id="edit_followup_id">
                    
                    <div class="bg-white p-3 border rounded mb-3 shadow-sm">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">สถานะผลประเมิน</label>
                            <select name="med_status" id="edit_followup_status" class="form-select" onchange="checkFollowupStatus()"></select>
                            <!-- จะแปลง name กลับตอน submit ด้วย JS -->
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">บันทึกเพิ่มเติม</label>
                            <textarea class="form-control" name="med_note" id="edit_followup_note" rows="3"></textarea>
                        </div>
                        <div class="mb-2" id="followup_next_wrapper" style="display:none;">
                            <label class="form-label small fw-bold text-danger">วันที่นัดครั้งต่อไป</label>
                            <input type="date" class="form-control" name="next_med_date" id="edit_followup_next">
                        </div>
                        <div class="row g-2 d-none" id="edit_followup_med_labs">
                            <div class="col-6">
                                <label class="form-label small fw-bold">CPK</label>
                                <input type="text" class="form-control" name="cpk" id="edit_followup_cpk">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">BUN</label>
                                <input type="text" class="form-control" name="bun" id="edit_followup_bun">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Cr</label>
                                <input type="text" class="form-control" name="cr" id="edit_followup_cr">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Electrolyte</label>
                                <input type="text" class="form-control" name="eletroride" id="edit_followup_eletroride">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 bg-white p-3 border rounded mx-0 shadow-sm">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                            <input type="text" name="recorder_name" id="edit_followup_recorder" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">เวลาบันทึก <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="record_datetime" id="edit_followup_datetime" class="form-control form-control-sm" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-save"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 5. Modal VISIT (เยี่ยมบ้าน) -->
<div class="modal fade" id="modalEditVISIT" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header" style="background: var(--t-grad); color:white; border-bottom: none;">
                    <h5 class="modal-title font-head"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขฟอร์มเยี่ยมบ้าน (Home Visit)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="record_edit_submit" value="1">
                    <input type="hidden" name="edit_type" value="VISIT">
                    <input type="hidden" name="record_id" id="edit_visit_id">
                    
                    <div class="row g-3 mb-3 bg-white p-3 border rounded mx-0 shadow-sm">
                        <div class="col-md-4">
                            <div class="form-check p-2 rounded bg-light border">
                                <input class="form-check-input pct-visit-obs ms-1" type="checkbox" name="obs[]" value="มีไข้" id="pct_obs_fever">
                                <label class="form-check-label text-danger fw-bold ms-2" for="pct_obs_fever">มีไข้ (°C)</label>
                            </div>
                            <input type="number" step="0.1" class="form-control mt-1 form-control-sm" name="body_temperature" id="edit_visit_temp" placeholder="อุณหภูมิ">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check p-2 rounded bg-light border">
                                <input class="form-check-input pct-visit-obs ms-1" type="checkbox" name="obs[]" value="ทำกิจกรรม" id="pct_obs_act">
                                <label class="form-check-label text-success fw-bold ms-2" for="pct_obs_act">ทำกิจกรรมได้</label>
                            </div>
                            <input type="text" class="form-control mt-1 form-control-sm" name="activity_details" id="edit_visit_act" placeholder="ระบุกิจกรรม">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-warning mb-1">สีปัสสาวะ</label>
                            <input type="text" class="form-control form-control-sm mt-1" name="urine_color" id="edit_visit_urine">
                        </div>
                    </div>
                    
                    <div class="mb-3 p-3 bg-white border rounded shadow-sm">
                        <label class="form-label small fw-bold text-danger mb-2">อาการที่พบ (Checklist)</label>
                        <div class="form-check mb-2 border-bottom pb-2">
                            <input class="form-check-input" type="checkbox" name="symptoms[]" value="ไม่มีอาการผิดปกติ" id="pct_sym_none" onchange="toggleEditVisitSymptoms(this)">
                            <label class="form-check-label text-success fw-bold" for="pct_sym_none">ไม่มีอาการผิดปกติ</label>
                        </div>
                        <div class="row g-2">
                            <?php foreach($pcu_sym_list as $i => $s): ?>
                            <div class="col-md-4">
                                <div class="form-check form-check-sm">
                                    <input class="form-check-input pct-visit-sym" type="checkbox" name="symptoms[]" value="<?php echo $s; ?>" id="pct_sym_<?php echo $i; ?>">
                                    <label class="form-check-label small" for="pct_sym_<?php echo $i; ?>"><?php echo $s; ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="col-12 mt-2">
                                <div class="form-check form-check-sm mb-1">
                                    <input class="form-check-input pct-visit-sym" type="checkbox" name="symptoms[]" value="other" id="pct_sym_oth_check" onchange="toggleEditOther(this, 'edit_visit_symp_oth')">
                                    <label class="form-check-label small fw-bold" for="pct_sym_oth_check">อื่นๆ (ระบุ)</label>
                                </div>
                                <input type="text" name="symptoms_other" id="edit_visit_symp_oth" class="form-control form-control-sm d-none" placeholder="ระบุอาการ...">
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 bg-white p-3 border rounded mx-0 shadow-sm">
                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-danger">วันที่นัดครั้งต่อไป</label>
                            <input type="date" class="form-control form-control-sm" name="next_visit_date" id="edit_visit_next">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                            <input type="text" name="recorder_name" id="edit_visit_recorder" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">เวลาบันทึก <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="record_datetime" id="edit_visit_datetime" class="form-control form-control-sm" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-save"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 6. Modal PATIENT (แก้ไขข้อมูลผู้ป่วย) -->
<div class="modal fade" id="modalEditPatient" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header" style="background: var(--t-grad); color:white; border-bottom: none;">
                    <h5 class="modal-title font-head"><i class="fa-solid fa-user-pen me-2"></i>แก้ไขข้อมูลผู้ป่วย (Patient Information)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="record_edit_submit" value="1">
                    <input type="hidden" name="edit_type" value="PATIENT">
                    <input type="hidden" name="record_id" id="edit_patient_id">
                    
                    <div class="row g-3 bg-white p-3 border rounded mx-0 mb-3 shadow-sm">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="patient_name" id="edit_patient_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">เลขบัตรประชาชน</label>
                            <input type="text" class="form-control" name="patient_id_card" id="edit_patient_id_card">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ประเภทกำลังพล</label>
                            <input type="text" class="form-control" name="person_type" id="edit_person_type">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">สังกัด/หน่วย</label>
                            <input type="text" class="form-control" name="department" id="edit_department">
                        </div>
                    </div>

                    <div class="row g-3 bg-white p-3 border rounded mx-0 mb-3 shadow-sm">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">อายุ (ปี)</label>
                            <input type="number" class="form-control" name="age" id="edit_age">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">น้ำหนัก (กก.)</label>
                            <input type="number" step="0.1" class="form-control" name="weight" id="edit_weight">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">ส่วนสูง (ซม.)</label>
                            <input type="number" step="0.1" class="form-control" name="height" id="edit_height">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">BMI</label>
                            <input type="number" step="0.01" class="form-control bg-light" name="bmi" id="edit_bmi" readonly tabindex="-1">
                        </div>
                    </div>

                    <div class="row g-3 bg-white p-3 border rounded mx-0 shadow-sm">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-danger">โรคประจำตัว</label>
                            <input type="text" class="form-control" name="congenital_disease" id="edit_disease">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">เบอร์โทรศัพท์</label>
                            <input type="text" class="form-control" name="phone" id="edit_phone">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-save"></i> บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="occEditModal" tabindex="-1" aria-labelledby="occEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content"></div>
    </div>
</div>
<script src="<?php echo isset($path_prefix) ? $path_prefix : ''; ?>bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo isset($path_prefix) ? $path_prefix : ''; ?>OCC/occ_followup_status.js"></script>
<script src="<?php echo isset($path_prefix) ? $path_prefix : ''; ?>OCC/occ_edit_modal.js"></script>
<script>
    // JS Lists for Dynamic Followup Modal
    const medList = <?php echo json_encode($med_status_list); ?>;
    const pmrList = <?php echo json_encode($pmr_status_list); ?>;

    function fmtDate(dt) {
        if(!dt || dt === '0000-00-00 00:00:00' || dt === '-') return '';
        return dt.replace(' ', 'T').substring(0, 16);
    }

    function setSelectOrOther(selectId, otherId, val) {
        let sel = document.getElementById(selectId);
        let oth = document.getElementById(otherId);
        if(!sel || !oth) return;
        if (!val) { sel.value = ''; oth.value = ''; oth.classList.add('d-none'); return; }
        let exists = Array.from(sel.options).some(opt => opt.value === val);
        if (exists) { sel.value = val; oth.value = ''; oth.classList.add('d-none'); } 
        else { sel.value = 'other'; oth.value = val; oth.classList.remove('d-none'); }
    }

    function setCheckboxes(checkboxSelector, valueString) {
        let cbs = document.querySelectorAll(checkboxSelector);
        cbs.forEach(cb => cb.checked = false); 
        if (!valueString) return;
        let vals = valueString.split(',').map(s => s.trim());
        cbs.forEach(cb => { if (vals.includes(cb.value)) cb.checked = true; });
    }

    // 🔴 ฟังก์ชันหลักในการเปิด Modal ตามประเภท
    function openRecordEdit(type, btnElement) {
        try {
            let data = JSON.parse(btnElement.getAttribute('data-json'));
            
            if (type === 'WARD') {
                document.getElementById('edit_ward_id').value = data.id || '';
                document.getElementById('edit_ward_an').value = data.an || '';
                document.getElementById('edit_ward_admit_date').value = fmtDate(data.admit_date);
                document.getElementById('edit_ward_discharge_date').value = fmtDate(data.discharge_date);
                document.getElementById('edit_ward_doctor_name').value = data.doctor_name || '';
                setSelectOrOther('edit_ward_initial_sel', 'edit_ward_initial_oth', data.initial_diagnosis);
                setSelectOrOther('edit_ward_final_sel', 'edit_ward_final_oth', data.final_diagnosis);
                
                let icuBlock = document.getElementById('ward_icu_block');
                if (icuBlock) {
                    let icuInputs = icuBlock.querySelectorAll('input, select');
                    if (data.admit_type && data.admit_type.toUpperCase().includes('ICU')) {
                        icuBlock.style.display = 'block';
                        icuInputs.forEach(el => el.disabled = false);
                        setSelectOrOther('edit_ward_icu_dx_sel', 'edit_ward_icu_dx_oth', data.icu_diagnosis);
                        document.getElementById('edit_ward_ct1').value = data.icu_core_temp_first || '';
                        document.getElementById('edit_ward_ct30').value = data.icu_core_temp_30min || '';
                        document.getElementById('edit_ward_ctrl').value = data.icu_control_temp || '';
                        document.getElementById('edit_ward_time').value = data.icu_time_er_to_icu || '';
                    } else {
                        icuBlock.style.display = 'none';
                        icuInputs.forEach(el => el.disabled = true);
                    }
                }
                new bootstrap.Modal(document.getElementById('modalEditWARD')).show();
                
            } else if (type === 'PATIENT') {
                document.getElementById('edit_patient_id').value = data.id || '';
                document.getElementById('edit_patient_name').value = data.patient_name || '';
                document.getElementById('edit_patient_id_card').value = data.patient_id_card || '';
                document.getElementById('edit_person_type').value = data.person_type || '';
                document.getElementById('edit_department').value = data.department || '';
                document.getElementById('edit_age').value = data.age || '';
                document.getElementById('edit_weight').value = data.weight || '';
                document.getElementById('edit_height').value = data.height || '';
                document.getElementById('edit_bmi').value = data.bmi || '';
                document.getElementById('edit_disease').value = data.congenital_disease || '';
                document.getElementById('edit_phone').value = data.phone || '';
                new bootstrap.Modal(document.getElementById('modalEditPatient')).show();

            } else if (type === 'ER') {
                document.getElementById('edit_er_id').value = data.id || '';
                document.getElementById('edit_er_arr').value = fmtDate(data.arrival_time);
                document.getElementById('edit_er_hosp').value = fmtDate(data.hospital_arrival_datetime);
                setSelectOrOther('edit_er_fstdx_sel', 'edit_er_fstdx_oth', data.FstDx);
                setSelectOrOther('edit_er_cause_sel', 'edit_er_cause_oth', data.cause_factor);
                setCheckboxes('.pct-er-risk', data.risk_factors);
                document.getElementById('edit_er_ct1').value = data.ct_initial || '';
                document.getElementById('edit_er_ct30').value = data.ct_30min || '';
                document.getElementById('edit_er_ctrl').value = data.ct_control || '';
                document.getElementById('edit_er_firstaid').value = data.first_aid || '';
                document.getElementById('edit_er_treatment_result').value = data.treatment_result || '';

                // แสดง field วันเวลาจำหน่ายเฉพาะ Disposition = Discharge
                const erDcBlock = document.getElementById('edit_er_discharge_block');
                if (data.disposition === 'Discharge') {
                    erDcBlock.classList.remove('d-none');
                    document.getElementById('edit_er_recorded_at').value = fmtDate(data.recorded_at);
                } else {
                    erDcBlock.classList.add('d-none');
                    document.getElementById('edit_er_recorded_at').value = '';
                }

                new bootstrap.Modal(document.getElementById('modalEditER')).show();

            }else if (type === 'GP') {
                document.getElementById('edit_gp_id').value = data.id || '';
                document.getElementById('edit_gp_arr').value = fmtDate(data.arrival_time);
                document.getElementById('edit_gp_hosp').value = fmtDate(data.hospital_arrival_datetime);
                setSelectOrOther('edit_gp_fstdx_sel', 'edit_gp_fstdx_oth', data.FstDx);
                setSelectOrOther('edit_gp_cause_sel', 'edit_gp_cause_oth', data.cause_factor);
                setCheckboxes('.pct-gp-risk', data.risk_factors);
                document.getElementById('edit_gp_ct1').value = data.ct_initial || '';
                document.getElementById('edit_gp_ct30').value = data.ct_30min || '';
                document.getElementById('edit_gp_ctrl').value = data.ct_control || '';
                document.getElementById('edit_gp_firstaid').value = data.first_aid || '';
                document.getElementById('edit_gp_treatment_result').value = data.treatment_result || '';

                // แสดง field วันเวลาจำหน่ายเฉพาะ Disposition = Discharge
                const gpDcBlock = document.getElementById('edit_gp_discharge_block');
                if (data.disposition === 'Discharge') {
                    gpDcBlock.classList.remove('d-none');
                    document.getElementById('edit_gp_recorded_at').value = fmtDate(data.recorded_at);
                } else {
                    gpDcBlock.classList.add('d-none');
                    document.getElementById('edit_gp_recorded_at').value = '';
                }

                new bootstrap.Modal(document.getElementById('modalEditGP')).show();
            } else if (type === 'MED' || type === 'PMR') {
                let lowerT = type.toLowerCase();
                document.getElementById('followupModalTitle').innerHTML = `<i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขบันทึก ${type}`;
                document.getElementById('edit_followup_type').value = type;
                document.getElementById('edit_followup_id').value = data.id || '';
                
                let sel = document.getElementById('edit_followup_status');
                sel.name = lowerT + '_status';
                sel.innerHTML = '';
                let list = (type === 'MED') ? medList : pmrList;
                list.forEach(m => { sel.innerHTML += `<option value="${m}">${m}</option>`; });
                
                sel.value = data[lowerT + '_status'] || '';
                
                document.getElementById('edit_followup_note').name = lowerT + '_note';
                document.getElementById('edit_followup_note').value = data[lowerT + '_note'] || '';
                
                document.getElementById('edit_followup_next').name = 'next_' + lowerT + '_date';
                document.getElementById('edit_followup_next').value = data['next_' + lowerT + '_date'] || '';

                const medLabFields = document.getElementById('edit_followup_med_labs');
                medLabFields.classList.toggle('d-none', type !== 'MED');
                ['cpk', 'bun', 'cr', 'eletroride'].forEach(field => {
                    const input = document.getElementById('edit_followup_' + field);
                    input.disabled = type !== 'MED';
                    input.value = type === 'MED' ? (data[field] || '') : '';
                });
                
                document.getElementById('edit_followup_recorder').value = data.recorder_name || '';
                document.getElementById('edit_followup_datetime').value = fmtDate(data.record_datetime);
                
                checkFollowupStatus();
                new bootstrap.Modal(document.getElementById('modalEditFollowup')).show();

            } else if (type === 'VISIT') {
                document.getElementById('edit_visit_id').value = data.id || '';
                document.getElementById('edit_visit_temp').value = data.body_temperature || '';
                document.getElementById('edit_visit_urine').value = data.urine_color || '';
                document.getElementById('edit_visit_act').value = data.activity_details || '';
                document.getElementById('edit_visit_next').value = data.next_visit_date || '';
                document.getElementById('edit_visit_recorder').value = data.recorder_name || '';
                document.getElementById('edit_visit_datetime').value = fmtDate(data.record_datetime);
                setCheckboxes('.pct-visit-obs', data.checklist_obs);
                
                let symps = data.symptoms || '';
                document.getElementById('pct_sym_none').checked = false;
                document.getElementById('pct_sym_oth_check').checked = false;
                document.getElementById('edit_visit_symp_oth').classList.add('d-none');
                document.getElementById('edit_visit_symp_oth').value = '';
                
                let cbs = document.querySelectorAll('.pct-visit-sym');
                cbs.forEach(cb => { cb.checked = false; cb.disabled = false; });

                if (symps === 'ไม่มีอาการผิดปกติ') {
                    document.getElementById('pct_sym_none').checked = true;
                    toggleEditVisitSymptoms(document.getElementById('pct_sym_none'));
                } else if (symps !== '') {
                    let sympArr = symps.split(',').map(s => s.trim());
                    cbs.forEach(cb => {
                        if (cb.value !== 'other' && sympArr.includes(cb.value)) {
                            cb.checked = true;
                            sympArr = sympArr.filter(item => item !== cb.value);
                        }
                    });
                    if (sympArr.length > 0 && sympArr[0] !== '') {
                        document.getElementById('pct_sym_oth_check').checked = true;
                        let othInp = document.getElementById('edit_visit_symp_oth');
                        othInp.value = sympArr.join(', ');
                        othInp.classList.remove('d-none');
                    }
                }
                new bootstrap.Modal(document.getElementById('modalEditVISIT')).show();
            }
        } catch (e) {
            console.error(e); alert("เกิดข้อผิดพลาดในการโหลดข้อมูล");
        }
    }

    function toggleEditOther(el, targetId) {
        let t = document.getElementById(targetId);
        if (el.value === 'other' || el.checked) {
            t.classList.remove('d-none'); t.required = true; t.focus();
        } else {
            t.classList.add('d-none'); t.required = false; t.value = '';
        }
    }

    function toggleEditVisitSymptoms(noneCb) {
        const syms = document.querySelectorAll('.pct-visit-sym');
        const othInp = document.getElementById('edit_visit_symp_oth');
        if (noneCb.checked) {
            syms.forEach(cb => { cb.checked = false; cb.disabled = true; });
            othInp.classList.add('d-none'); othInp.value = '';
        } else {
            syms.forEach(cb => cb.disabled = false);
        }
    }

    document.querySelectorAll('.pct-visit-sym').forEach(cb => {
        cb.addEventListener('change', () => { 
            if(cb.checked) document.getElementById('pct_sym_none').checked = false;
        });
    });

    // คำนวณ BMI อัตโนมัติเมื่อแก้น้ำหนัก/ส่วนสูง
    function calcEditBMI() {
        let w = parseFloat(document.getElementById('edit_weight').value);
        let h = parseFloat(document.getElementById('edit_height').value) / 100;
        let bmiInput = document.getElementById('edit_bmi');
        if (w > 0 && h > 0) {
            bmiInput.value = (w / (h * h)).toFixed(2);
        } else {
            bmiInput.value = '';
        }
    }
    document.getElementById('edit_weight').addEventListener('input', calcEditBMI);
    document.getElementById('edit_height').addEventListener('input', calcEditBMI);

    document.getElementById('edit_er_ct30').addEventListener('input', function() {
        let val = parseFloat(this.value);
        let ctrl = document.getElementById('edit_er_ctrl'); 
        
        if (!isNaN(val)) {
            if (val <= 36.5) {
                ctrl.value = '✓ เป็นไปตามมาตรฐาน'; // เปลี่ยนการเข้าถึงเป็น .value ให้ select ทำงานได้ถูก
                ctrl.style.color = 'green';
                ctrl.style.fontWeight = 'bold'; // เพิ่มตัวหนาให้เห็นชัดๆ
            } else {
                ctrl.value = '✗ ไม่เป็นไปตามมาตรฐาน'; // เปลี่ยนเป็น .value
                ctrl.style.color = 'red';
                ctrl.style.fontWeight = 'bold';
            }
        } else {
            ctrl.value = '';
            ctrl.style.color = ''; 
        }
    });

    function checkFollowupStatus() {
        let val = document.getElementById('edit_followup_status').value;
        let wrap = document.getElementById('followup_next_wrapper');
        let inp = document.getElementById('edit_followup_next');
        if(val && val.includes('สิ้นสุด')) { wrap.classList.add('d-none'); inp.value = ''; } 
        else { wrap.classList.remove('d-none'); }
    }

    // ฟังก์ชันลบทั้งเคส
    function confirmDeleteCase(caseId, caseNum) {
        Swal.fire({
            title: 'ยืนยันการลบประวัติการรักษา?',
            html: `คุณกำลังจะลบ <b>ประวัติการรักษาครั้งที่ ${caseNum}</b><br><span class='text-danger'>ข้อมูล ER, Ward, และการติดตามทั้งหมดในรอบนี้จะถูกลบทิ้งอย่างถาวร!</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fa-solid fa-trash me-1"></i> ลบทิ้งทั้งหมด',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_case_id').value = caseId;
                document.getElementById('pctDeleteCaseForm').submit();
            }
        });
    }

    // ฟังก์ชันเปลี่ยน Tab โปรไฟล์
    function updateProfileData(btn) {
        const profileCard = document.getElementById('profileCard');
        profileCard.style.opacity = '0.6';
        setTimeout(() => {
            let nameEl = document.getElementById('prof-name');
            if(nameEl) nameEl.innerText = btn.getAttribute('data-name') || '-';
            
            let idcardEl = document.getElementById('prof-idcard');
            if(idcardEl) idcardEl.innerText = btn.getAttribute('data-idcard') || '-';

            document.getElementById('prof-age').innerText = btn.getAttribute('data-age') || '-';
            document.getElementById('prof-weight').innerText = btn.getAttribute('data-weight') || '-';
            document.getElementById('prof-height').innerText = btn.getAttribute('data-height') || '-';
            document.getElementById('prof-bmi').innerText = btn.getAttribute('data-bmi') || '-';
            document.getElementById('prof-phone').innerText = btn.getAttribute('data-phone') || '-';
            document.getElementById('prof-disease').innerText = btn.getAttribute('data-disease') || '-';
            document.getElementById('prof-dept').innerText = btn.getAttribute('data-dept') || '-';
            document.getElementById('prof-ptype').innerText = btn.getAttribute('data-ptype') || '-';
            
            let btnEdit = document.getElementById('btnEditProfile');
            if (btnEdit) btnEdit.setAttribute('data-json', btn.getAttribute('data-fulljson'));

            profileCard.style.opacity = '1';
        }, 150);
    }

    // โหลด Progress Notes
    function toggleNotes(id) {
        let panel = document.getElementById('notes-screen-' + id);
        let printPanel = document.getElementById('notes-print-' + id);
        if (panel.style.display === 'block') {
            panel.style.display = 'none';
            if(printPanel) printPanel.style.display = 'none';
        } else {
            panel.style.display = 'block';
            if(printPanel) printPanel.style.display = 'block';
            loadNotes(id);
        }
    }

    function loadNotes(id) {
        let dateInput = document.querySelector('.date-filter-' + id);
        let dateVal = dateInput ? dateInput.value : '';
        let targets = document.querySelectorAll('.notes-target-' + id);
        
        targets.forEach(el => el.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-primary spinner-border-sm me-2"></div> กำลังดึงข้อมูล...</div>');
        
        let basePath = '<?php echo isset($path_prefix) ? $path_prefix : ""; ?>';
        fetch(basePath + `fetch_notes_content.php?ward_id=${id}&date=${dateVal}`)
        .then(res => { if(!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
        .then(data => {
            let html = (data.trim() === '') ? '<div class="text-center text-muted py-3">ไม่พบข้อมูลบันทึกอาการ</div>' : data;
            targets.forEach(el => el.innerHTML = html);
        })
        .catch(err => {
            targets.forEach(el => el.innerHTML = '<div class="text-danger text-center"><i class="fa-solid fa-triangle-exclamation mb-2 fa-2x"></i><br>เกิดข้อผิดพลาดในการโหลดข้อมูลไฟล์ fetch_notes_content.php</div>');
            console.error('Fetch Error:', err);
        });
    }

    // 🖨️ จัดการสลับลำดับ Timeline ก่อนพิมพ์
    window.addEventListener('beforeprint', () => {
        document.querySelectorAll('.timeline-container').forEach(container => {
            let nodes = Array.from(container.querySelectorAll('.timeline-node'));
            nodes.reverse().forEach(node => container.appendChild(node));
        });
    });

    window.addEventListener('afterprint', () => {
        document.querySelectorAll('.timeline-container').forEach(container => {
            let nodes = Array.from(container.querySelectorAll('.timeline-node'));
            nodes.reverse().forEach(node => container.appendChild(node));
        });
    });
</script>
</body>
</html>