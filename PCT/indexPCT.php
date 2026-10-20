<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบสิทธิ์ (ต้องล็อกอินและเป็น pct หรือ admin หรือ viewer)
$is_pct = (isset($_SESSION['name']) && strtolower($_SESSION['name']) === 'pct') || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$is_viewer = (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer');

if (!isset($_SESSION['user_id']) || (!$is_pct && !$is_viewer)) { 
    header("Location: ../login.php"); 
    exit(); 
}

if (empty($_SESSION['pct_csrf_token']) || !is_string($_SESSION['pct_csrf_token'])) {
    $_SESSION['pct_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['pct_csrf_token'];

require_once '../database/db_conn.php';

function pct_escape_html($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * render วินิจฉัยแบบ st-badge สีตาม severity
 */
function getDxSeverityBadge($dx_text) {
    if (empty($dx_text) || $dx_text === '-') {
        return '<span class="text-muted small">-</span>';
    }
    $dx_safe = pct_escape_html($dx_text);
    if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis\s*\(CPK\s*>\s*1,?000\)/i', $dx_text)) {
        $style = 'background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;';
        $icon  = 'fa-triangle-exclamation';
    } elseif (preg_match('/Rhabdomyolysis\s*\(CPK\s*<\s*1,?000\)|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $dx_text)) {
        $style = 'background:#fffbeb; color:#b45309; border:1px solid #fde68a;';
        $icon  = 'fa-circle-exclamation';
    } else {
        $style = 'background:#f8fafc; color:#475569; border:1px solid #e2e8f0;';
        $icon  = 'fa-stethoscope';
    }
    return '<span class="st-badge" style="'.$style.'"><i class="fa-solid '.$icon.' me-1"></i>'.$dx_safe.'</span>';
}


// ==============================================================================
// 🚀 Auto-Update Database: สร้างคอลัมน์ status_case อัตโนมัติ
// ==============================================================================
$check_col = $conn->query("SHOW COLUMNS FROM cases LIKE 'status_case'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE cases ADD status_case VARCHAR(50) DEFAULT 'Open' AFTER created_at");
}
$check_comment_col = $conn->query("SHOW COLUMNS FROM cases LIKE 'comment'");
if (!$check_comment_col) {
    error_log('Failed to check cases.comment column: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถตรวจสอบโครงสร้างฐานข้อมูลได้');
}
if ($check_comment_col->num_rows === 0 && !$conn->query("ALTER TABLE cases ADD `comment` TEXT NULL")) {
    error_log('Failed to add cases.comment column: ' . $conn->error);
    http_response_code(500);
    exit('ไม่สามารถเตรียมช่องบันทึกของเคสได้');
}

// ==============================================================================
// 🛠️ Action Handlers (แก้ไขตึก/ปิดเคส/ปลดล็อก) - ป้องกัน Viewer ส่ง POST
// ==============================================================================
$statusMsg = ''; $statusType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pct_action'])) {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
    if (!is_string($posted_csrf_token) || !hash_equals($csrf_token, $posted_csrf_token)) {
        http_response_code(403);
        $statusType = 'error';
        $statusMsg = 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';
    } elseif ($is_viewer) {
        // บล็อก Viewer ไม่ให้ทำ Action
        $statusType = 'error'; $statusMsg = "สิทธิ์ Viewer ไม่สามารถแก้ไขข้อมูลได้";
    } else {
        $action = $_POST['pct_action'];
        if ($action == 'fix_route') {
            $ward_id = mysqli_real_escape_string($conn, $_POST['ward_id']);
            $new_ward = mysqli_real_escape_string($conn, $_POST['new_ward']);
            if ($conn->query("UPDATE ward_details SET admit_type = '$new_ward' WHERE id = '$ward_id'")) {
                $statusType = 'success'; $statusMsg = "ย้ายผู้ป่วยไปที่ $new_ward สำเร็จแล้ว";
            } else { $statusType = 'error'; $statusMsg = "เกิดข้อผิดพลาด: " . $conn->error; }
        }
        if ($action == 'close_case') {
            $case_id = mysqli_real_escape_string($conn, $_POST['case_id']);
            if ($conn->query("UPDATE cases SET status_case = 'Closed' WHERE id = '$case_id'")) {
                $statusType = 'success'; $statusMsg = "ปิดเคสการรักษานี้เรียบร้อยแล้ว";
            } else { $statusType = 'error'; $statusMsg = "เกิดข้อผิดพลาด: " . $conn->error; }
        }
        if ($action == 'reopen_case') {
            $case_id = mysqli_real_escape_string($conn, $_POST['case_id']);
            if ($conn->query("UPDATE cases SET status_case = 'Open' WHERE id = '$case_id'")) {
                $statusType = 'success'; $statusMsg = "ปลดล็อกเคสสำเร็จ (กลับมาเป็นสถานะปกติ)";
            } else { $statusType = 'error'; $statusMsg = "เกิดข้อผิดพลาด: " . $conn->error; }
        }
        if ($action == 'delete_er_waiting') {
            $row_id        = (int)$_POST['row_id'];
            $delete_reason = mysqli_real_escape_string($conn, trim($_POST['delete_reason'] ?? ''));
            if ($row_id > 0) {
                $sql_del = "UPDATE data_import SET status = 2, status_detail = '$delete_reason', deleted_at = NOW() WHERE row_id = $row_id AND status = 0";
                if ($conn->query($sql_del)) {
                    $statusType = 'success'; $statusMsg = "ลบรายการออกจากคิวรอ ER เรียบร้อยแล้ว";
                } else { $statusType = 'error'; $statusMsg = "เกิดข้อผิดพลาด: " . $conn->error; }
            } else {
                $statusType = 'error'; $statusMsg = "ไม่พบ row_id ที่ถูกต้อง";
            }
        }
    }
}

$ptype_options = "";
$res_ptypes = $conn->query("SELECT DISTINCT person_type FROM cases WHERE person_type IS NOT NULL AND person_type != '' ORDER BY person_type ASC");
if ($res_ptypes) {
    while($rp = $res_ptypes->fetch_assoc()) {
        $val = pct_escape_html($rp['person_type']);
        $ptype_options .= "<option value='{$val}'>{$val}</option>";
    }
}

if (!function_exists('getSeverityCategory')) {
    function getSeverityCategory($dx) {
        if (empty($dx) || $dx == '-') return 'Mild';
        if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis.*> ?1,?000/i', $dx))return 'Severe';
        elseif (preg_match('/Prickly Heat|Heat Edema|Heat Syncope|Heat Cramps|Heat Tetany|Rhabdomyolysis.*< ?1,?000/i', $dx)) return 'Moderate';
        return 'Mild';
    }
}

if (!function_exists('getOutcomeState')) {
    function getOutcomeState($dept, $w_stat) {
        if ($dept == 'ER') return 'Wait'; 
        if (in_array($dept, ['ICU', 'RCU', 'A4', 'B3'])) return ($w_stat == 'Active') ? 'Admit' : 'Wait';
        return 'Wait';
    }
}

// ==============================================================================
// 📋 ดึงข้อมูลผู้ป่วยทั้งหมด
// ==============================================================================
$sql_list = "SELECT 
                p.id as case_id, p.hn, p.patient_name, p.department, p.person_type, p.created_at, p.status_case,
                p.`comment` as case_comment,
                (SELECT FstDx FROM gp_details WHERE case_id = p.id ORDER BY id DESC LIMIT 1) as gp_dx,
                (SELECT disposition FROM gp_details WHERE case_id = p.id ORDER BY id DESC LIMIT 1) as gp_disposition,
                (SELECT FstDx FROM er_details WHERE case_id = p.id ORDER BY id DESC LIMIT 1) as er_dx,
                (SELECT disposition FROM er_details WHERE case_id = p.id ORDER BY id DESC LIMIT 1) as er_status,
                (SELECT admit_type FROM er_details WHERE case_id = p.id ORDER BY id DESC LIMIT 1) as er_admit_type,
                (SELECT hospital_arrival_datetime FROM er_details WHERE case_id = p.id ORDER BY id DESC LIMIT 1) as hospital_arrival_datetime,
                w.admit_type as last_ward_type,
                w.status as last_ward_status,
                w.discharge_status as last_ward_dc_status,
                w.id as last_ward_id,
                w.an as an,
                w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis,
                (SELECT med_status FROM med_records WHERE an = w.an ORDER BY record_datetime DESC LIMIT 1) as last_med_status,
                (SELECT pmr_status FROM pmr_records WHERE an = w.an ORDER BY record_datetime DESC LIMIT 1) as last_pmr_status,
                (SELECT COUNT(*) FROM home_visits WHERE an = w.an) as pcu_count
             FROM cases p
             LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = p.id)
             ORDER BY p.id DESC";
$res_list = $conn->query($sql_list);
$all_cases = [];
if($res_list && $res_list->num_rows > 0) {
    while($row = $res_list->fetch_assoc()) { $all_cases[] = $row; }
}

// ---------------------------------------------------------------------------
// 🔍 กำหนดค่า โครงสร้างตัวแปรป้องกัน Undefined Array Key
// ---------------------------------------------------------------------------
$default_year = (int)date('Y');
$normalize_date_filter = static function($value) {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
        return '';
    }
    return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) ? $value : '';
};
$format_thai_date_filter = static function($value) {
    if ($value === '') {
        return '';
    }
    $months = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return sprintf('%02d %s %d', $day, $months[$month], $year + 543);
};
$filter_start_date = array_key_exists('filter_start_date', $_GET)
    ? $normalize_date_filter($_GET['filter_start_date'])
    : sprintf('%04d-10-01', $default_year);
$filter_end_date = array_key_exists('filter_end_date', $_GET)
    ? $normalize_date_filter($_GET['filter_end_date'])
    : sprintf('%04d-09-30', $default_year + 1);
$er_arrival_date_conditions = [];
if ($filter_start_date !== '') {
    $er_arrival_date_conditions[] = "e.hospital_arrival_datetime >= '$filter_start_date'";
}
if ($filter_end_date !== '') {
    $er_arrival_date_conditions[] = "e.hospital_arrival_datetime < DATE_ADD('$filter_end_date', INTERVAL 1 DAY)";
}
$er_arrival_date_filter_sql = empty($er_arrival_date_conditions)
    ? ''
    : ' AND ' . implode(' AND ', $er_arrival_date_conditions);

$ptypes_labels = ['ทหารกองประจำการ', 'กำลังพล ทบ.', 'นอก ทบ.', 'อื่นๆ'];
$months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
$fiscal_reference_date = $filter_start_date !== ''
    ? $filter_start_date
    : ($filter_end_date !== '' ? $filter_end_date : date('Y-m-d'));
$fiscal_start_year = (int)substr($fiscal_reference_date, 0, 4);
if ((int)substr($fiscal_reference_date, 5, 2) < 10) {
    $fiscal_start_year--;
}
$fiscal_month_numbers = array_merge(range(10, 12), range(1, 9));
$months_labels = [];
foreach ($fiscal_month_numbers as $month_number) {
    $month_year = $fiscal_start_year + ($month_number < 10 ? 1 : 0);
    $months_labels[] = $months[$month_number - 1] . ' ' . ($month_year + 543);
}

$heat_keywords_full = ["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"];

if (!function_exists('initStats')) {
    function initStats($heat_arr, $ptypes_arr) {
        $stats = [
            'admit'=>0, 'wait'=>0, 'dc'=>0, 'refer'=>0, 'dead'=>0, 
            'total'=>0, 'ptype'=>array_fill_keys($ptypes_arr, 0),
            'sev'=>['Severe'=>0, 'Moderate'=>0, 'Mild'=>0], 
            'heat_by_type'=>array_fill_keys($heat_arr, 0), 
            'heat_cases'=>array_fill_keys($heat_arr, []),
            'heat_by_month'=>[]
        ];
        foreach($heat_arr as $k) {
            $stats['heat_by_month'][$k] = array_fill(1, 12, 0);
        }
        return $stats;
    }
}

$overview_stats = initStats($heat_keywords_full, $ptypes_labels);
$filtered_overview = [];

$depts = ['GP', 'ER', 'ICU', 'RCU', 'A4', 'B3', 'MED', 'PMR', 'PCU'];
$dept_stats = [];
$grouped_cases = [];
foreach($depts as $d) {
    $dept_stats[$d] = initStats($heat_keywords_full, $ptypes_labels);
    $grouped_cases[strtolower($d)] = [];
}

// ---------------------------------------------------------------------------
// 🔄 ประมวลผลข้อมูล (Loop)
// ---------------------------------------------------------------------------
foreach($all_cases as $row) {
    $is_pct_closed = ($row['status_case'] === 'Closed');
    $w_stat = $row['last_ward_status'] ?? '';
    $w_type = $row['last_ward_type'] ?? '';
    $e_stat = $row['er_status'] ?? '';
    $w_dc_stat = $row['last_ward_dc_status'] ?? '';

    $assigned_depts = [];

    if (!$is_pct_closed) {
        if (empty($row['last_ward_id'])) {
            if (!empty($row['gp_dx']) && empty($row['er_dx']) && empty($e_stat)) {
                // ผู้ป่วยที่จบการรักษาที่ GP (ไม่มีข้อมูล ER หรือ Ward)
                $assigned_depts[] = 'GP';
            } elseif (empty($e_stat) || $e_stat == 'Admit') {
                $assigned_depts[] = 'ER';
            }
        } else {
            if ($w_stat == 'Active' || $w_stat == 'AdmitWait') {
                $t = 'A4';
                if (stripos($w_type, 'ICU') !== false) $t = 'ICU';
                elseif (stripos($w_type, 'RCU') !== false) $t = 'RCU';
                elseif (stripos($w_type, 'B3') !== false) $t = 'B3';
                $assigned_depts[] = strtoupper($t);
            } elseif ($w_stat == 'Discharged') {
                $med_stat = $row['last_med_status'] ?? '';
                $pmr_stat = $row['last_pmr_status'] ?? '';
                $pcu_count = (int)($row['pcu_count'] ?? 0);
                
                if (empty($med_stat)) $assigned_depts[] = 'MED';
                if (!empty($med_stat) && strpos($med_stat, 'นัดพบแพทย์เวชศาสตร์ฟื้นฟู') !== false) {
                    if (empty($pmr_stat) || strpos($pmr_stat, 'สิ้นสุด') === false) $assigned_depts[] = 'PMR';
                }
                        $best_dx_for_pcu = !empty($row['final_diagnosis'])
                        ? $row['final_diagnosis']
                        : (!empty($row['icu_diagnosis'])
                            ? $row['icu_diagnosis']
                            : (!empty($row['initial_diagnosis'])
                                ? $row['initial_diagnosis']
                                : (!empty($row['gp_dx']) ? $row['gp_dx'] : ($row['er_dx'] ?? '-'))
                            )
                        );
                    $sev_for_pcu = getSeverityCategory($best_dx_for_pcu);

                    // ถ้า final_diagnosis มีค่า ต้องเป็น heat เท่านั้น ถึงจะเข้า PCU
                    $is_heat_dx = empty($row['final_diagnosis'])
                        || $sev_for_pcu !== 'Mild'
                        || preg_match('/Heat|Rhabdomyolysis|Prickly/i', $row['final_diagnosis']);

                    if ($is_heat_dx) {
                        $pcu_required = 3;
                        if ($sev_for_pcu === 'Severe')   $pcu_required = 2;
                        if ($sev_for_pcu === 'Moderate') $pcu_required = 1;

                        if ($pcu_count < $pcu_required) $assigned_depts[] = 'PCU';
                    }
            }
        }
    }

    $best_dx = '-';
    $is_terminal = (in_array($w_stat, ['Discharged', 'Refer', 'Dead']) || $is_pct_closed || (empty($row['last_ward_id']) && in_array($e_stat, ['Discharge', 'Refer', 'Dead'])));
    
    if ($is_terminal) {
        $best_dx = !empty($row['final_diagnosis']) 
        ? $row['final_diagnosis'] 
        : (!empty($row['icu_diagnosis']) 
            ? $row['icu_diagnosis'] 
            : (!empty($row['initial_diagnosis']) 
                ? $row['initial_diagnosis'] 
                : (!empty($row['gp_dx']) 
                    ? $row['gp_dx'] 
                    : (!empty($row['er_dx']) ? $row['er_dx'] : '-')
                )
            )
        );
        } else {
        if (!empty($row['last_ward_id'])) {
            if (stripos($w_type, 'ICU') !== false && !empty($row['icu_diagnosis'])) {
                $best_dx = $row['icu_diagnosis'];
            } else {
                $best_dx = !empty($row['initial_diagnosis']) 
                ? $row['initial_diagnosis'] 
                : (!empty($row['gp_dx']) 
                    ? $row['gp_dx'] 
                    : (!empty($row['er_dx']) ? $row['er_dx'] : '-')
                );
            }
        } else {
            $best_dx = !empty($row['gp_dx']) 
            ? $row['gp_dx'] 
            : (!empty($row['er_dx']) ? $row['er_dx'] : '-');
        }
    }
    // ตัวแปรนี้ยังคงเก็บไว้เพื่อการคำนวณกราฟสถิติให้ถูกต้อง
    $row['best_dx_calculated'] = $best_dx;
    $is_heat_case = (bool)preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $best_dx);

    foreach($assigned_depts as $d) {
        $grouped_cases[strtolower($d)][] = $row;
    }

    $matched_dx = null;
    if ($best_dx != '-' && $best_dx != '') {
        $matched_dx = 'อื่นๆ';
        foreach(["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"] as $k) {
            if(stripos($best_dx, $k) !== false) { $matched_dx = $k; break; }
        }
    }
    
    $sev = getSeverityCategory($best_dx);

    $raw_ptype = $row['person_type'] ?? '';
    $raw_dept = $row['department'] ?? '';
    $mapped_ptype = 'อื่นๆ';
    if (strpos($raw_ptype, 'กองประจำการ') !== false || strpos($raw_ptype, 'พลทหาร') !== false) $mapped_ptype = 'ทหารกองประจำการ';
    elseif (strpos($raw_ptype, 'ทบ.') !== false && strpos($raw_ptype, 'นอก') === false) $mapped_ptype = 'กำลังพล ทบ.';
    elseif (strpos($raw_ptype, 'นอก ทบ.') !== false || strpos($raw_dept, 'พลเรือน') !== false) $mapped_ptype = 'นอก ทบ.';

    $created_time = strtotime($row['created_at']);
    $arrival_datetime = $row['hospital_arrival_datetime'] ?? '';
    $arrival_time = (!empty($arrival_datetime) && $arrival_datetime !== '0000-00-00 00:00:00')
        ? strtotime($arrival_datetime)
        : false;
    $pt_time = $arrival_time !== false ? $arrival_time : $created_time;
    $pt_m = date('n', $pt_time);
    
    foreach($assigned_depts as $d) {
        $dept_stats[$d]['sev'][$sev]++;
        $dept_stats[$d]['total']++;
        if ($is_heat_case) $dept_stats[$d]['ptype'][$mapped_ptype]++;
        
        if ($matched_dx) {
            $dept_stats[$d]['heat_by_type'][$matched_dx]++;
            $dept_stats[$d]['heat_cases'][$matched_dx][] = [
                'hn' => $row['hn'] ?? '-',
                'patient_name' => $row['patient_name'] ?? '-',
                'person_type' => $row['person_type'] ?? '-',
                'diagnosis' => $best_dx,
                'created_at' => $row['created_at'] ?? ''
            ];
            if ($is_heat_case) $dept_stats[$d]['heat_by_month'][$matched_dx][$pt_m]++;
        }
        $outState = getOutcomeState($d, $w_stat);
        if ($outState == 'Admit') $dept_stats[$d]['admit']++; else $dept_stats[$d]['wait']++;
    }

    $case_date = $arrival_time !== false ? date('Y-m-d', $arrival_time) : '';
    if (($filter_start_date !== '' || $filter_end_date !== '') && $case_date === '') continue;
    if ($filter_start_date !== '' && $case_date < $filter_start_date) continue;
    if ($filter_end_date !== '' && $case_date > $filter_end_date) continue;
    if ($matched_dx && $is_heat_case) { $overview_stats['heat_by_month'][$matched_dx][$pt_m]++; }
    
    $filtered_overview[] = $row;
    $overview_stats['sev'][$sev]++;
    $overview_stats['total']++;
    if ($is_heat_case) $overview_stats['ptype'][$mapped_ptype]++;
    
    if ($matched_dx) { 
        $overview_stats['heat_by_type'][$matched_dx]++; 
        $overview_stats['heat_cases'][$matched_dx][] = [
            'hn' => $row['hn'] ?? '-',
            'patient_name' => $row['patient_name'] ?? '-',
            'person_type' => $row['person_type'] ?? '-',
            'diagnosis' => $best_dx,
            'created_at' => $row['created_at'] ?? ''
        ];
    }

    if (empty($row['last_ward_id'])) {
        $gp_disp = $row['gp_disposition'] ?? '';
        if ($e_stat == 'Dead' || $gp_disp == 'Dead') $overview_stats['dead']++;
        elseif ($e_stat == 'Refer' || $gp_disp == 'Refer') $overview_stats['refer']++;
        elseif ($e_stat == 'Discharge' || $gp_disp == 'Discharge' || $is_pct_closed) $overview_stats['dc']++;
        else $overview_stats['wait']++;
    } else {
        if ($w_stat == 'Active' && !$is_pct_closed) $overview_stats['admit']++;
        elseif ($w_stat == 'AdmitWait' && !$is_pct_closed) $overview_stats['wait']++;
        else {
            if ($w_dc_stat == 'Dead') $overview_stats['dead']++;
            elseif ($w_dc_stat == 'Refer') $overview_stats['refer']++;
            else $overview_stats['dc']++;
        }
    }
}

// เก็บเคสที่กำลังรักษา (admit + wait) สำหรับ Modal
$active_cases = array_filter($filtered_overview, function($row) {
    $is_pct_closed = ($row['status_case'] === 'Closed');
    if ($is_pct_closed) return false;
    $w_stat = $row['last_ward_status'] ?? '';
    $e_stat = $row['er_status'] ?? '';
    if (!empty($row['last_ward_id']) && in_array($w_stat, ['Active', 'AdmitWait'])) return true;
    if (empty($row['last_ward_id']) && !in_array($e_stat, ['Discharge', 'Refer', 'Dead']) && !empty($e_stat)) return true;
    return false;
});
$active_cases = array_values($active_cases);

// เคสที่จำหน่ายแล้ว
$dc_cases = array_filter($filtered_overview, function($row) {
    $is_pct_closed = ($row['status_case'] === 'Closed');
    $w_stat  = $row['last_ward_status'] ?? '';
    $w_dc    = $row['last_ward_dc_status'] ?? '';
    $e_stat  = $row['er_status'] ?? '';
    $gp_disp = $row['gp_disposition'] ?? '';

    // จำหน่ายจาก Ward (ไม่ใช่ Refer/Dead)
    if (!empty($row['last_ward_id']) && $w_stat == 'Discharged' && !in_array($w_dc, ['Dead','Refer'])) return true;
    // จำหน่ายจาก ER
    if (empty($row['last_ward_id']) && $e_stat == 'Discharge') return true;
    // จำหน่ายจาก GP
    if (empty($row['last_ward_id']) && empty($e_stat) && $gp_disp == 'Discharge') return true;
    // ปิดเคส PCT
    if ($is_pct_closed && empty($row['last_ward_id']) && !in_array($e_stat, ['Refer','Dead'])) return true;

    return false;
});
$dc_cases = array_values($dc_cases);

// จำนวนผู้ป่วยตามช่วงวันที่มาถึงโรงพยาบาล
$res_total_cases = $conn->query("
    SELECT COUNT(p.id) as c
    FROM cases p
    LEFT JOIN er_details e ON e.id = (
        SELECT MAX(e_latest.id) FROM er_details e_latest WHERE e_latest.case_id = p.id
    )
    WHERE 1=1 $er_arrival_date_filter_sql
");
if ($res_total_cases) {
    $total_cases = (int)($res_total_cases->fetch_assoc()['c'] ?? 0);
} else {
    $total_cases = 0;
    error_log('Failed to count cases by hospital arrival date: ' . $conn->error);
}

// จำนวนผู้ป่วยที่ ER ส่ง Admit
$kpi_admit = 0;
$er_admit_cases = [];
$resER_KPI = $conn->query("SELECT COUNT(*) as cnt FROM er_details e WHERE e.disposition = 'Admit' " . $er_arrival_date_filter_sql);
if ($resER_KPI) {
    $kpi_admit = (int)($resER_KPI->fetch_assoc()['cnt'] ?? 0);
} else {
    error_log('Failed to count ER Admit records: ' . $conn->error);
}

$res_er_admit_cases = $conn->query("
    SELECT e.id, e.hospital_arrival_datetime, e.recorded_at, e.FstDx, e.admit_type,
           c.hn, c.patient_name, c.person_type, c.created_at
    FROM er_details e
    INNER JOIN cases c ON c.id = e.case_id
    WHERE e.disposition = 'Admit' " . $er_arrival_date_filter_sql . "
    ORDER BY COALESCE(e.hospital_arrival_datetime, e.recorded_at, c.created_at) DESC, e.id DESC
");
if ($res_er_admit_cases) {
    while ($row = $res_er_admit_cases->fetch_assoc()) {
        $er_admit_cases[] = $row;
    }
} else {
    error_log('Failed to load ER Admit records: ' . $conn->error);
}

// จำนวนเคสที่ควบคุมอุณหภูมิได้: อุณหภูมิแรกรับตั้งแต่ 38°C และหลัง 30 นาทีไม่เกิน 38°C
$kpi_ct_controlled = 0;
$ct_controlled_cases = [];
$res_ct_controlled_kpi = $conn->query("
    SELECT COUNT(DISTINCT e.case_id) AS cnt
    FROM er_details e
    WHERE e.id = (SELECT MAX(e_latest.id) FROM er_details e_latest WHERE e_latest.case_id = e.case_id)
      AND e.ct_initial >= 38
      AND e.ct_30min <= 38
      " . $er_arrival_date_filter_sql . "
");
if ($res_ct_controlled_kpi) {
    $kpi_ct_controlled = (int)($res_ct_controlled_kpi->fetch_assoc()['cnt'] ?? 0);
} else {
    error_log('Failed to count CT controlled cases: ' . $conn->error);
}

$res_ct_controlled_cases = $conn->query("
    SELECT e.id, e.hospital_arrival_datetime, e.recorded_at, e.ct_initial, e.ct_30min, e.FstDx,
           c.hn, c.patient_name, c.person_type, c.created_at
    FROM er_details e
    INNER JOIN cases c ON c.id = e.case_id
    WHERE e.id = (SELECT MAX(e_latest.id) FROM er_details e_latest WHERE e_latest.case_id = e.case_id)
      AND e.ct_initial >= 38
      AND e.ct_30min <= 38
      " . $er_arrival_date_filter_sql . "
    ORDER BY COALESCE(e.hospital_arrival_datetime, e.recorded_at, c.created_at) DESC, e.id DESC
");
if ($res_ct_controlled_cases) {
    while ($row = $res_ct_controlled_cases->fetch_assoc()) {
        $ct_controlled_cases[] = $row;
    }
} else {
    error_log('Failed to load CT controlled cases: ' . $conn->error);
}

// จำนวนผู้ป่วยรอ er รับเข้า
$total_cases_er = $conn->query("SELECT COUNT(row_id) as c FROM data_import WHERE status = 0")->fetch_assoc()['c'];

// ดึงรายชื่อผู้ป่วยรอ ER รับเข้า
$er_waiting_list = [];
$res_er_waiting = $conn->query("SELECT row_id, hn, person_ID, Fname, work_located, data_date FROM data_import WHERE status = 0 ORDER BY data_date DESC");
if ($res_er_waiting && $res_er_waiting->num_rows > 0) {
    while ($ew = $res_er_waiting->fetch_assoc()) {
        $er_waiting_list[] = $ew;
    }
}

$q_w_hist = $conn->query("SELECT admit_type, status, discharge_status FROM ward_details WHERE status IN ('Discharged', 'Refer', 'Dead')");
if($q_w_hist) {
    while($w = $q_w_hist->fetch_assoc()) {
        $t = 'A4';
        if (stripos($w['admit_type'], 'ICU') !== false) $t = 'ICU';
        elseif (stripos($w['admit_type'], 'RCU') !== false) $t = 'RCU';
        elseif (stripos($w['admit_type'], 'B3') !== false) $t = 'B3';
        
        if ($w['discharge_status'] == 'Dead') $dept_stats[$t]['dead']++;
        elseif ($w['discharge_status'] == 'Refer') $dept_stats[$t]['refer']++;
        else $dept_stats[$t]['dc']++;
    }
}

$q_e_hist = $conn->query("SELECT disposition FROM er_details WHERE disposition IN ('Discharge', 'Refer', 'Dead')");
if($q_e_hist) {
    while($e = $q_e_hist->fetch_assoc()) {
        if ($e['disposition'] == 'Dead') $dept_stats['ER']['dead']++;
        elseif ($e['disposition'] == 'Refer') $dept_stats['ER']['refer']++;
        else $dept_stats['ER']['dc']++;
    }
}

$q_gp_hist = $conn->query("SELECT disposition FROM gp_details WHERE disposition IN ('Discharge', 'Refer', 'Dead')");
if($q_gp_hist) {
    while($g = $q_gp_hist->fetch_assoc()) {
        if ($g['disposition'] == 'Dead') $dept_stats['GP']['dead']++;
        elseif ($g['disposition'] == 'Refer') $dept_stats['GP']['refer']++;
        else $dept_stats['GP']['dc']++;
    }
}

// ---------------------------------------------------------------------------
// 🎨 UI Rendering Functions (Premium Modern Look)
// ---------------------------------------------------------------------------

if (!function_exists('renderChartsHtml')) {
    function renderChartsHtml($stats) {
        $heat_json = htmlspecialchars(json_encode($stats['heat_by_type'] ?? []), ENT_QUOTES, 'UTF-8');
        $heat_patients_json = htmlspecialchars(json_encode($stats['heat_cases'] ?? []), ENT_QUOTES, 'UTF-8');
        $month_json = htmlspecialchars(json_encode($stats['heat_by_month'] ?? []), ENT_QUOTES, 'UTF-8');

        // จัดเตรียมข้อมูลสำหรับจำแหนกประเภทบุคคล (Person Type)
        $ptype_data = $stats['ptype'] ?? [];
        $processedPersonData = [];
        $person_keys = ['ทหารกองประจำการ', 'กำลังพล ทบ.', 'นอก ทบ.', 'อื่นๆ'];
        
        foreach($person_keys as $ptype) {
            $pcount = $ptype_data[$ptype] ?? 0;
            $icon = 'fa-user'; 
            $bg = 'linear-gradient(135deg, #64748b, #475569)'; 
            $chartColor = '#64748b';
            
            if($ptype === 'ทหารกองประจำการ') { 
                $icon = 'fa-person-military-rifle'; $bg = 'linear-gradient(135deg, #10b981, #059669)'; $chartColor = '#10b981';
            } elseif($ptype === 'กำลังพล ทบ.') { 
                $icon = 'fa-user-shield'; $bg = 'linear-gradient(135deg, #3b82f6, #2563eb)'; $chartColor = '#3b82f6';
            } elseif($ptype === 'นอก ทบ.') { 
                $icon = 'fa-user-tag'; $bg = 'linear-gradient(135deg, #f59e0b, #d97706)'; $chartColor = '#f59e0b';
            } elseif($ptype === 'อื่นๆ') {
                $icon = 'fa-users-viewfinder'; $bg = 'linear-gradient(135deg, #94a3b8, #64748b)'; $chartColor = '#94a3b8';
            }
            
            $processedPersonData[] = [
                'type' => $ptype,
                'count' => $pcount,
                'icon' => $icon,
                'bg' => $bg,
                'chartColor' => $chartColor
            ];
        }
        $person_json = htmlspecialchars(json_encode($processedPersonData), ENT_QUOTES, 'UTF-8');

        $person_cards_html = "";
        foreach($processedPersonData as $pd) {
            $person_cards_html .= "
            <div class='d-flex align-items-center justify-content-between px-3 py-2 mb-2 rounded-3' style='background: #f8fafc; border: 1px solid #e2e8f0; transition: transform 0.2s ease;' onmouseover=\"this.style.transform='scale(1.02)'\" onmouseout=\"this.style.transform='scale(1)'\">
                <div class='d-flex align-items-center gap-2'>
                    <div class='rounded-2 d-flex align-items-center justify-content-center text-white shadow-sm' style='width: 32px; height: 32px; background: {$pd['bg']}; flex-shrink: 0;'>
                        <i class='fa-solid {$pd['icon']}' style='font-size: 0.85rem;'></i>
                    </div>
                    <span class='fw-bold text-dark' style='font-size: 0.85rem; line-height: 1.2;'>{$pd['type']}</span>
                </div>
                <span class='fw-bold' style='color: {$pd['chartColor']}; font-size: 1.05rem; white-space: nowrap;'>
                    {$pd['count']} <small class='text-muted fw-normal' style='font-size: 0.75rem;'>คน</small>
                </span>
            </div>";
        }

        $sev_severe   = number_format($stats['sev']['Severe']   ?? 0);
        $sev_moderate = number_format($stats['sev']['Moderate'] ?? 0);
        $sev_mild     = number_format($stats['sev']['Mild']     ?? 0);

        return "
        <div class='row g-4 mb-4'>
            <div class='col-12'>
                <div class='chart-card d-flex flex-column'>
                    <h6 class='chart-title'><i class='fa-solid fa-chart-column me-2 text-primary'></i> จำนวนผู้ป่วยจำแนกตามโรคลมร้อน</h6>
                    <div class='chart-container flex-grow-1' style='min-height: 280px;'><canvas class='chart-heat-type' data-chart='{$heat_json}' data-patients='{$heat_patients_json}'></canvas></div>
                    
                    <div class='row g-3 mt-2 justify-content-center'>
                        <div class='col-4'>
                            <div class='py-3 px-2 rounded-4 text-center' style='background: #fc1717;'>
                                <div class='fw-bold mb-1' style='font-size: 0.85rem; color: rgba(255,255,255,0.85);'>รุนแรง</div>
                                <div class='fs-3 fw-bold text-white'>{$sev_severe}</div>
                            </div>
                        </div>
                        <div class='col-4'>
                            <div class='py-3 px-2 rounded-4 text-center' style='background: #ffc107;'>
                                <div class='fw-bold mb-1' style='font-size: 0.85rem; color: #ffffff;'>ไม่รุนแรง</div>
                                <div class='fs-3 fw-bold' style='color: #ffffff;'>{$sev_moderate}</div>
                            </div>
                        </div>
                        <div class='col-4'>
                            <div class='py-3 px-2 rounded-4 text-center' style='background: #10b981;'>
                                <div class='fw-bold mb-1' style='font-size: 0.85rem; color: rgba(255,255,255,0.85);'>อื่นๆ</div>
                                <div class='fs-3 fw-bold text-white'>{$sev_mild}</div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class='row g-4 mb-4'>
            <div class='col-lg-8'>
                <div class='chart-card d-flex flex-column h-100'>
                    <h6 class='chart-title'><i class='fa-solid fa-chart-line me-2 text-primary'></i> แนวโน้มการเกิดโรคลมร้อน (เฉพาะ Heat)</h6>
                    <div class='chart-container'><canvas class='chart-heat-month' data-chart='{$month_json}'></canvas></div>
                </div>
            </div>
            <div class='col-lg-4'>
                <div class='chart-card d-flex flex-column h-100'>
                    <h6 class='chart-title'><i class='fa-solid fa-users me-2 text-primary'></i> จำแนกประเภทบุคคล (เฉพาะ Heat)</h6>
                    
                    <!-- กราฟโดนัทบุคคล -->
                    <div style='position: relative; height: 160px; width: 100%; margin-bottom: 15px;'>
                        <canvas class='chart-person-type' data-person='{$person_json}'></canvas>
                    </div>
                    
                    <!-- การ์ดรายการบุคคล -->
                    <div class='flex-grow-1 d-flex flex-column justify-content-center'>
                        {$person_cards_html}
                    </div>
                </div>
            </div>
        </div>
        ";
    }
}

if (!function_exists('getPatientTimeline')) {
    function getPatientTimeline($conn, $case_id) {
        $qGP = $conn->query("SELECT id FROM gp_details WHERE case_id = '$case_id' LIMIT 1");
        $has_gp = $qGP && $qGP->num_rows > 0;
        $qER = $conn->query("SELECT id FROM er_details WHERE case_id = '$case_id' LIMIT 1");
        $has_er = $qER && $qER->num_rows > 0;
        $qWard = $conn->query("SELECT admit_type, status FROM ward_details WHERE case_id = '$case_id' ORDER BY id ASC");
        
        $steps = [];
        if ($has_gp) {
            $steps[] = "<span class='j-step j-gp'>GP</span>";
        }
        if ($has_er) { 
            $steps[] = "<span class='j-step j-er'>ER</span>"; 
        }
        if ($qWard && $qWard->num_rows > 0) {
            while($w = $qWard->fetch_assoc()) {
                $w_type = $w['admit_type']; $j_class = 'j-default';
                if(stripos($w_type, 'ICU') !== false) { $j_class = 'j-icu'; }
                elseif(stripos($w_type, 'RCU') !== false) { $j_class = 'j-rcu'; }
                elseif(stripos($w['admit_type'], 'A4') !== false) { $j_class = 'j-a4'; }
                elseif(stripos($w['admit_type'], 'B3') !== false) { $j_class = 'j-b3'; }
                $lbl = $w_type;
                if ($w['status'] == 'AdmitWait') $lbl .= ' (รอรับ)';
                $steps[] = "<span class='j-step {$j_class}'>{$lbl}</span>";
            }
        }
        
        $html = "<div class='journey-box'>";
        if (!empty($steps)) {
            // สลับให้ล่าสุดอยู่บนสุด (ซ้ายสุด)
            $steps = array_reverse($steps);
            $html .= implode("<i class='fa-solid fa-arrow-left j-arrow'></i>", $steps);
        } else {
            $html .= "<span class='text-muted small'>-</span>";
        }
        $html .= "</div>";
        return $html;
    }
}

if (!function_exists('renderPatientTable')) {
    function renderPatientTable($cases, $table_id, $title = "รายชื่อผู้ป่วยในความดูแล") {
        global $ptype_options, $conn, $is_viewer; 
        
        $html = '
        <div class="data-card mt-4">
            <div class="data-card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3 flex-grow-1">
                    <div class="icon-circle bg-primary-soft text-primary flex-shrink-0"><i class="fa-solid fa-folder-open"></i></div>
                    <h5 class="mb-0 fw-bold text-dark text-truncate">'.$title.'</h5>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill fs-6 fw-bold whitespace-nowrap">จำนวน '.number_format(count($cases)).' ราย</span>
                    <button type="button" class="btn btn-success btn-sm rounded-pill fw-bold shadow-sm px-3 py-2 whitespace-nowrap" onclick="exportTableToExcel(\''.$table_id.'\', \''.$title.'\')">
                        <i class="fa-solid fa-file-excel me-1"></i> ส่งออกรายงาน (Excel)
                    </button>
                </div>
            </div>
            <div class="p-3 border-bottom bg-light bg-opacity-50">
                <div class="row g-2 w-100 m-0">
                    <div class="col-12 col-md-3">
                        <select class="form-select filter-ptype" data-target="'.$table_id.'"><option value="">🛡️ ประเภทกำลังพล (ทั้งหมด)</option>'.$ptype_options.'</select>
                    </div>
                    <div class="col-12 col-md-3">
                        <select class="form-select filter-dx" data-target="'.$table_id.'">
                            <option value="">🩺 วินิจฉัยโรค Heat (ทั้งหมด)</option>
                            <option value="Prickly Heat">Prickly Heat</option>
                            <option value="Heat Edema">Heat Edema</option>
                            <option value="Heat Syncope">Heat Syncope</option>
                            <option value="Heat Cramps">Heat Cramps</option>
                            <option value="Heat Tetany">Heat Tetany</option>
                            <option value="Rhabdomyolysis (CPK < 1,000)">Rhabdomyolysis (CPK < 1,000)</option>
                            <option value="Rhabdomyolysis (CPK &gt; 1,000)">Rhabdomyolysis (CPK > 1,000)</option>
                            <option value="Heat Exhaustion">Heat Exhaustion</option>
                            <option value="Heat Stroke">Heat Stroke</option>
                            <option value="อื่นๆ">อื่นๆ (ไม่ระบุชัดเจน)</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <select class="form-select filter-sev" data-target="'.$table_id.'"><option value="">🔥 ความรุนแรง (ทั้งหมด)</option><option value="Severe">รุนแรง</option><option value="Moderate">ไม่รุนแรง</option></select>
                    </div>
                    <div class="col-12 col-md-3">
                        <input type="text" class="form-control table-search" data-target="'.$table_id.'" placeholder="🔍 ค้นหา HN, ชื่อ, ตึก...">
                    </div>
                </div>
            </div>
            
            <div class="table-responsive table-wrapper">
                <table class="table table-hover table-pct mb-0" id="'.$table_id.'">
                    <thead style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th width="5%" class="text-center">#</th>
                            <th width="12%">วันที่ถึง รพ.</th>
                            <th width="18%">HN / ข้อมูลผู้ป่วย</th>
                            <th width="20%">วินิจฉัยแรกรับ / วินิจฉัยสุดท้าย</th>
                            <th width="17%">สถานะ / ไทม์ไลน์</th>
                            
                            <th width="15%" class="text-center">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
        ';

        if (empty($cases)) {
            $html .= "<tr>
                        <td colspan='7' class='text-center py-5 text-muted'>
                            <div class='my-4'>
                                <i class='fa-solid fa-folder-open fa-3x mb-3 text-light'></i><br><span class='fw-bold'>ไม่มีข้อมูลผู้ป่วยในรายการนี้</span>
                            </div>
                        </td>
                    </tr>";
        } else {
            $idx = 1;
            foreach ($cases as $row) {
                $is_pct_closed = ($row['status_case'] === 'Closed');
                $is_active_ward = false;
                $ward_id_to_fix = $row['last_ward_id'];
                $case_id = $row['case_id'];
                $has_comment = trim((string)($row['case_comment'] ?? '')) !== '';

                $dx_final = $row['best_dx_calculated'] ?? '-'; 
                $sev_text = 'Mild';
                if (preg_match('/(CPK > 1,000)|Exhaustion|Stroke/i', $dx_final)) { $sev_text = 'Severe'; } 
                elseif (preg_match('/Prickly|Edema|Syncope|Cramps|Tetany|(CPK < 1,000)/i', $dx_final)) { $sev_text = 'Moderate'; }
                $is_exportable_severity = in_array($sev_text, ['Severe', 'Moderate'], true);

                // ================================================================
                // วินิจฉัยแรกรับ (FstDx จาก ER/GP → initial_diagnosis)
                // ================================================================
                $col_fst = '';
                if (!empty($row['er_dx']))             { $col_fst = $row['er_dx']; }
                elseif (!empty($row['gp_dx']))         { $col_fst = $row['gp_dx']; }
                elseif (!empty($row['initial_diagnosis'])) { $col_fst = $row['initial_diagnosis']; }

                // วินิจฉัยสุดท้าย (final_diagnosis → icu → initial → fallback)
                $col_lst = '';
                if (!empty($row['final_diagnosis']))       { $col_lst = $row['final_diagnosis']; }
                elseif (!empty($row['icu_diagnosis']))     { $col_lst = $row['icu_diagnosis']; }
                elseif (!empty($row['initial_diagnosis'])) { $col_lst = $row['initial_diagnosis']; }
                elseif (!empty($row['gp_dx']))             { $col_lst = $row['gp_dx']; }
                elseif (!empty($row['er_dx']))             { $col_lst = $row['er_dx']; }

                $dx_html_format = 
                    "<div class='text-muted' style='font-size:0.7rem;'>วินิจฉัยสุดท้าย</div>" .
                    getDxSeverityBadge($col_lst) .
                    "<div class='d-flex flex-column align-items-start gap-1'>" .
                    "<div class='text-muted' style='font-size:0.7rem;'>วินิจฉัยแรกรับ</div>" .
                    getDxSeverityBadge($col_fst).
                    "</div>";

                $status_html = "";
                if ($is_pct_closed) { $status_html = "<div class='mb-2'><span class='st-badge st-closed'><i class='fa-solid fa-check-double'></i> ปิดเคส (PCT)</span></div>"; }
                elseif ($row['last_ward_status'] == 'Active') { $status_html .= "<span class='st-badge st-admit'><i class='fa-solid fa-bed-pulse'></i> Admit: " . pct_escape_html($row['last_ward_type'] ?? '') . "</span>"; $is_active_ward = true; } 
                elseif ($row['last_ward_status'] == 'AdmitWait') { $status_html .= "<span class='st-badge st-wait'><i class='fa-solid fa-clock'></i> " . pct_escape_html($row['last_ward_type'] ?? '') . "</span>"; $is_active_ward = true;  } 
                elseif ($row['last_ward_status'] == 'Discharged') { $status_html .= "<span class='st-badge st-dc'><i class='fa-solid fa-house-circle-check'></i> D/C จาก " . pct_escape_html($row['last_ward_type'] ?? '') . "</span>"; } 
                elseif ($row['last_ward_status'] == 'Refer') { $status_html .= "<span class='st-badge st-refer'><i class='fa-solid fa-ambulance'></i> Refer จาก " . pct_escape_html($row['last_ward_type'] ?? '') . "</span>"; } 
                elseif ($row['last_ward_status'] == 'Dead') { $status_html .= "<span class='st-badge st-dead'><i class='fa-solid fa-book-skull'></i> เสียชีวิต (" . pct_escape_html($row['last_ward_type'] ?? '') . ")</span>"; } 
                elseif ($row['er_status'] == 'Admit') { $er_dest = !empty($row['er_admit_type']) ? pct_escape_html($row['er_admit_type']) : ''; $status_html .= "<span class='st-badge st-er'><i class='fa-solid fa-truck-medical'></i> ER รอส่ง {$er_dest}</span>"; } 
                elseif (empty($row['er_status']) && !empty($row['gp_dx'])) {
                    $status_html .= "<span class='st-badge st-gp'>
                        <i class='fa-solid fa-stethoscope'></i> D/C จาก GP
                    </span>";
                }
                elseif (empty($row['er_status']) && !empty($row['er_dx'])) { $status_html .= "<span class='st-badge st-wait'><i class='fa-solid fa-hourglass-half'></i> ค้างประเมิน ER</span>"; } 
                elseif ($row['er_status'] == 'Dead') { $status_html .= "<span class='st-badge st-dead'><i class='fa-solid fa-book-skull'></i> เสียชีวิต (ER)</span>"; } 
                else { $status_html .= "<span class='st-badge st-dc'><i class='fa-solid fa-house-medical'></i> D/C จาก ER</span>"; }

                $arrival_datetime = $row['hospital_arrival_datetime'] ?? '';
                $arrival_timestamp = (!empty($arrival_datetime) && $arrival_datetime !== '0000-00-00 00:00:00')
                    ? strtotime($arrival_datetime)
                    : false;
                $date_str = $arrival_timestamp !== false ? date('d/m/Y', $arrival_timestamp) : '-';
                $time_str = $arrival_timestamp !== false ? date('H:i', $arrival_timestamp) : '';
                $pt_type = $row['person_type'] ?? '-';
                $safe_hn = pct_escape_html($row['hn'] ?? '-');
                $safe_patient_name = pct_escape_html($row['patient_name'] ?? '-');
                $safe_pt_type = pct_escape_html($pt_type);
                $safe_department = pct_escape_html($row['department'] ?? '-');
                
                // เก็บค่า data-dx เป็นวินิจฉัยแบบสรุปไว้สำหรับ Filter ค้นหา เพื่อให้ระบบค้นหาและทำสถิติทำงานได้ปกติ
                $f_ptype = pct_escape_html($pt_type); $f_dx = pct_escape_html($dx_final); $f_sev = pct_escape_html($sev_text);
                // วินิจฉัยแรกรับสำหรับ filter
                $fst_sev_text = 'Mild';
                if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis (CPK > 1,000)/i', $col_fst)) { $fst_sev_text = 'Severe'; }
                elseif (preg_match('/Rhabdomyolysis (CPK < 1,000)|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $col_fst)) { $fst_sev_text = 'Moderate'; }
                $f_fst = pct_escape_html($col_fst);
                $f_fstsev = pct_escape_html($fst_sev_text);
                
                // สรุป Timeline สำหรับ Excel
                $qER = $conn->query("SELECT * FROM er_details WHERE case_id = '$case_id' ORDER BY id DESC LIMIT 1");
                $er_full = ($qER && $qER->num_rows > 0) ? $qER->fetch_assoc() : [];
                $er_temp_control = pct_escape_html($er_full['ct_control'] ?? '-');
                $q_ward_all = $conn->query("SELECT * FROM ward_details WHERE case_id = '$case_id' ORDER BY id ASC");
                $ward_records = [];
                $timeline_arr = [];
                if (!empty($er_full)) { $timeline_arr[] = "ER"; }

                if ($q_ward_all && $q_ward_all->num_rows > 0) {
                    while($wa = $q_ward_all->fetch_assoc()) {
                        $ward_records[] = $wa;
                        $lbl = $wa['admit_type'];
                        if ($wa['status'] == 'AdmitWait') $lbl .= ' (รอรับ)';
                        $timeline_arr[] = $lbl;
                    }
                }
                
                // แนบจุดจบและหาวินิจฉัยสุดท้าย
                $last_discharge_status = '-';
                $death_cause = '-';
                $last_final_dx = '-';

                if (!empty($ward_records)) {
                    $last_ward = end($ward_records);
                    $last_final_dx = !empty($last_ward['final_diagnosis']) ? $last_ward['final_diagnosis'] : '-';

                    if ($last_ward['status'] == 'Discharged') { $timeline_arr[] = 'D/C'; $last_discharge_status = 'จำหน่ายกลับ (D/C)' . ($last_ward['admit_type'] ?? '-'); }
                    elseif ($last_ward['status'] == 'Dead') { $timeline_arr[] = 'เสียชีวิต'; $last_discharge_status = 'เสียชีวิต (Dead)'; $death_cause = $last_ward['death_cause'] ?? '-';}
                    elseif ($last_ward['status'] == 'Refer') { $timeline_arr[] = 'Refer'; $last_discharge_status = 'ส่งต่อ (Refer)'; }
                    else { $last_discharge_status = 'กำลังรักษา (Admit)'; }
                } elseif (!empty($er_full)) {
                    if ($er_full['disposition'] == 'Discharge') { $timeline_arr[] = 'D/C'; $last_discharge_status = 'จำหน่ายกลับ (ER)'; }
                    elseif ($er_full['disposition'] == 'Dead') { $timeline_arr[] = 'เสียชีวิต'; $last_discharge_status = 'เสียชีวิต (ER)'; }
                    elseif ($er_full['disposition'] == 'Refer') { $timeline_arr[] = 'Refer'; $last_discharge_status = 'ส่งต่อ (ER)'; }
                    elseif ($er_full['disposition'] == 'Admit') { $last_discharge_status = 'รอ Admit จาก ER'; }
                }
                
                $timeline_str = empty($timeline_arr) ? '-' : implode(" -> ", $timeline_arr);
                $journey_html = getPatientTimeline($conn, $row['case_id']); // สำหรับโชว์บนเว็บ

                // =========================================================
                // 💡 สร้าง Executive Summary Data สำหรับ Excel
                // =========================================================
                $q_pt = $conn->query("SELECT * FROM cases WHERE id = '$case_id'");
                $pt_full = ($q_pt && $q_pt->num_rows > 0) ? $q_pt->fetch_assoc() : [];

                // วิเคราะห์เรื่อง Cooling (ควบคุมอุณหภูมิ)
                $cooling_done = 'No';
                if (($er_full['ct_control'] ?? '') == 'Yes') $cooling_done = 'Yes';

                // หาอุณหภูมิแรกรับ ICU
                $icu_first_temp = '-';
                if (!empty($ward_records)) {
                    foreach ($ward_records as $w) {
                        if (stripos($w['admit_type'], 'ICU') !== false && $icu_first_temp == '-') {
                            $icu_first_temp = !empty($w['icu_core_temp_first']) ? $w['icu_core_temp_first'].' °C' : '-';
                            if (($w['icu_control_temp'] ?? '') == 'Yes' && $cooling_done == 'No') $cooling_done = 'Yes';
                        }
                    }
                }

                // วิเคราะห์เรื่องการติดตาม (Followup Summary)
                $followup_arr = [];
                if (!empty($row['last_med_status'])) $followup_arr[] = "MED: " . $row['last_med_status'];
                if (!empty($row['last_pmr_status'])) $followup_arr[] = "PMR: " . $row['last_pmr_status'];
                if ((int)($row['pcu_count'] ?? 0) > 0) $followup_arr[] = "PCU: ลงพื้นที่เยี่ยม " . $row['pcu_count'] . " ครั้ง";
                $followup_str = empty($followup_arr) ? 'รอการติดตาม / ไม่ระบุ' : implode(" | ", $followup_arr);

                $export_row = [
                    'วันที่เข้ารับบริการ' => $date_str . ' ' . $time_str,
                    'HN' => $row['hn'],
                    'ชื่อ-นามสกุล' => $pt_full['patient_name'] ?? '-',
                    'ประเภทกำลังพล' => $pt_type,
                    'สังกัด' => $pt_full['department'] ?? '-',
                    'ความรุนแรง' => $sev_text,
                    'วินิจฉัยแรกรับ' => $dx_final,
                    'วินิจฉัยสุดท้าย' => $last_final_dx,
                    'สถานะล่าสุด' => strip_tags(str_replace('</i>', '', $status_html)), // ตัด Tag HTML ออก
                    'ไทม์ไลน์การรักษา' => $timeline_str,
                    '[ER] อุณหภูมิแรกรับ' => (!empty($er_full['ct_initial']) ? $er_full['ct_initial'].' °C' : '-'),
                    '[ER] อุณหภูมิ 30 นาที' => (!empty($er_full['ct_30min']) ? $er_full['ct_30min'].' °C' : '-'),
                    '[ER] ควบคุมอุณหภูมิ' => $er_full['ct_control'] ?? '-',
                    '[ICU] อุณหภูมิแรกรับ' => $icu_first_temp,
                    'ได้รับการลดอุณหภูมิ (Cooling)' => $cooling_done,
                    'ผลการรักษา (จำหน่าย)' => $last_discharge_status,
                    'สาเหตุการเสียชีวิต (ถ้ามี)' => $death_cause,
                    'สถานะการติดตามต่อเนื่อง' => $followup_str,
                    'สถานะแฟ้ม PCT' => $is_pct_closed ? 'ปิดเคสเรียบร้อย' : 'กำลังดำเนินการ'
                ];

                $json_full = htmlspecialchars(json_encode($export_row), ENT_QUOTES, 'UTF-8');

                $html .= "
                <tr data-ptype='{$f_ptype}' data-dx='{$f_dx}' data-sev='{$f_sev}' data-fst='{$f_fst}' data-fstsev='{$f_fstsev}' data-exportable-severity='" . ($is_exportable_severity ? 'true' : 'false') . "' data-full='{$json_full}'>
                    <td class='text-center text-muted fw-bold align-middle'>{$idx}</td>
                    <td class='text-muted small'><div class='fw-bold text-dark fs-6 d-date'>{$date_str}</div>" . ($time_str !== '' ? "<span class='d-time'>{$time_str}</span> น." : "") . "</td>
                    <td>
                        <div class='hn-chip mb-2'>
                            HN: <span class='d-hn'>{$safe_hn}</span>
                        </div>
                        <div class='fw-bold text-dark fs-6 d-name'>
                            {$safe_patient_name}
                        </div>
                        <div class='row g-0'>
                            <div class='col small text-muted me-2 d-ptype'>
                                <i class='fa-solid fa-shield-halved me-1 opacity-75 text-primary'></i> {$safe_pt_type}
                            </div>
                            <div class='col small text-muted me-2 d-ptype'>
                                <i class='fa-solid fa-location-dot me-1 opacity-75 text-primary'></i> {$safe_department}
                            </div>
                        </div>
                    </td>
                    <td><div class='dx-text'>{$dx_html_format}</div></td>
                    <td>{$status_html}{$journey_html}</td>
                    <td class='text-center align-middle'>
                        <div class='d-flex flex-column gap-2 align-items-center btn-actions-wrapper'>
                            <button type='button' class='btn-action btn-info js-case-comment " . ($has_comment ? 'has-comment' : '') . "' data-case-id='" . pct_escape_html($case_id) . "' data-patient-name='{$safe_patient_name}' data-has-comment='" . ($has_comment ? '1' : '0') . "'><i class='fa-solid fa-sticky-note'></i> " . ($has_comment ? 'ดูบันทึก' : 'เพิ่ม Note') . "</button>
                            <a href='../view_patient_full_history.php?hn=".urlencode($row['hn'])."' class='btn-action btn-view shadow-sm w-100 justify-content-center'><i class='fa-solid fa-folder-open'></i> เปิดแฟ้ม</a>";

                // แสดงปุ่มจัดการเฉพาะถ้าไม่ใช่ Viewer
                if (!$is_viewer) {
                    if($is_pct_closed) {
                        $html .= "<button class='btn-action btn-reopen shadow-sm w-100 justify-content-center' data-pct-action='reopen-case' data-case-id='" . pct_escape_html($row['case_id'] ?? '') . "' data-patient-name='{$safe_patient_name}'><i class='fa-solid fa-unlock'></i> ปลดล็อกเคส</button>";
                    } else {
                        $html .= "<div class='d-flex gap-2 w-100 flex-column flex-md-row'>";
                        if($is_active_ward && $ward_id_to_fix) {
                            $html .= "<button class='btn-action btn-fix shadow-sm w-100 justify-content-center' title='ย้ายตึกด่วน' data-pct-action='fix-route' data-ward-id='" . pct_escape_html($ward_id_to_fix) . "' data-patient-name='{$safe_patient_name}' data-ward-type='" . pct_escape_html($row['last_ward_type'] ?? '') . "'><i class='fa-solid fa-route'></i> ย้าย</button>";
                        }
                        $html .= "<button class='btn-action btn-close-case shadow-sm w-100 justify-content-center' data-pct-action='close-case' data-case-id='" . pct_escape_html($row['case_id'] ?? '') . "' data-patient-name='{$safe_patient_name}'><i class='fa-solid fa-lock'></i> ปิดเคส</button></div>";
                    }
                }

                $html .= "</div></td></tr>";
                $idx++;
            }
        }
        $html .= '</tbody></table></div>';
        $html .= '<div class="d-flex justify-content-between align-items-center mt-3 px-3 pb-3 pagination-container" id="pagination-'.$table_id.'"></div>';
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('renderDeptTab')) {
    function renderDeptTab($key, $title, $icon, $stats, $table_html) {
        $t = $stats['total'] ?? 0;
        $wait = number_format($stats['wait'] ?? 0); 
        $admit = number_format($stats['admit'] ?? 0); 
        $dc = number_format($stats['dc'] ?? 0); 
        $refer = number_format($stats['refer'] ?? 0); 
        $dead = number_format($stats['dead'] ?? 0);
        
        $is_followup = in_array($key, ['MED','PMR','PCU']);

        $html = "
        <div class='d-flex align-items-center gap-3 mb-4'>
            <div class='icon-circle bg-white text-primary shadow-sm' style='width: 50px; height: 50px; font-size: 1.5rem;'><i class='fa-solid $icon'></i></div>
            <h3 class='fw-bold font-head text-dark mb-0 fs-4'>$title</h3>
        </div>
        " . renderChartsHtml($stats);

        if ($is_followup) {
            $lbl_wait = $key == 'PCU' ? 'รอเยี่ยมบ้าน' : 'รอติดตามอาการ';
            $html .= "
            <div class='row g-3 g-md-4 mb-4'>
                <div class='col-6'>
                    <div class='dept-stat-card' style='border-color: #f59e0b;'>
                        <div class='s-title' style='color: #d97706;'><i class='fa-solid fa-hourglass-half me-1'></i> $lbl_wait</div>
                        <div class='s-val counter-val text-dark'>$wait</div>
                    </div>
                </div>
                <div class='col-6'>
                    <div class='dept-stat-card' style='border-color: #10b981;'>
                        <div class='s-title' style='color: #059669;'><i class='fa-solid fa-check-double me-1'></i> สิ้นสุดการติดตาม</div>
                        <div class='s-val counter-val text-dark'>$dc</div>
                    </div>
                </div>
            </div>
            ";
        } else {
            $html .= "
            <div class='row g-3 g-md-4 mb-4'>
                <div class='col-6 col-md-2'>
                    <div class='dept-stat-card' style='border-color: #f59e0b;'>
                        <div class='s-title' style='color: #d97706;'><i class='fa-solid fa-hourglass-half me-1'></i>รอรับเข้า</div>
                        <div class='s-val counter-val text-dark'>$wait</div>
                    </div>
                </div>
                <div class='col-6 col-md-3'>
                    <div class='dept-stat-card' style='border-color: #3b82f6;'>
                        <div class='s-title' style='color: #2563eb;'><i class='fa-solid fa-bed-pulse me-1'></i> Admit (รักษา)</div>
                        <div class='s-val counter-val text-dark'>$admit</div>
                    </div>
                </div>
                <div class='col-6 col-md-3'>
                    <div class='dept-stat-card' style='border-color: #10b981;'>
                        <div class='s-title' style='color: #059669;'><i class='fa-solid fa-house-circle-check me-1'></i> จำหน่าย (D/C)</div>
                        <div class='s-val counter-val text-dark'>$dc</div>
                    </div>
                </div>
                <div class='col-6 col-md-2'>
                    <div class='dept-stat-card' style='border-color: #8b5cf6;'>
                        <div class='s-title' style='color: #7c3aed;'><i class='fa-solid fa-ambulance me-1'></i> ส่งต่อ (Refer)</div>
                        <div class='s-val counter-val text-dark'>$refer</div>
                    </div>
                </div>
                <div class='col-12 col-md-2'>
                    <div class='dept-stat-card' style='border-color: #e11d48;'>
                        <div class='s-title' style='color: #be123c;'><i class='fa-solid fa-book-skull me-1'></i> เสียชีวิต</div>
                        <div class='s-val counter-val text-dark'>$dead</div>
                    </div>
                </div>
            </div>
            ";
        }

        $html .= $table_html;
        return $html;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $is_viewer ? 'Viewer Dashboard' : 'PCT Dashboard'; ?></title>
    
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if ($is_viewer): ?><script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script><?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>

    <style>
        :root {
            --pct-bg: #f8fafc;
            --pct-card: #ffffff;
            --pct-text: #1e293b;
            --pct-text-light: #64748b;
            
            --pct-primary: #0f172a;
            --pct-primary-soft: #f1f5f9;
            --pct-accent: #3b82f6;
            
            --border: #e2e8f0;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04); 
            --shadow-hover: 0 10px 25px rgba(15, 23, 42, 0.08);
            --rad: 20px; 
        }

        body { font-family: 'Sarabun', sans-serif; background-color: var(--pct-bg); color: var(--pct-text); }
        h1, h2, h3, h4, h5, h6, .font-head { font-family: 'Prompt', sans-serif; }

        .pct-hero { background: #ffffff; border: 1px solid var(--border); padding: 40px 45px; border-radius: var(--rad); box-shadow: var(--shadow-soft); margin-bottom: 30px; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .hero-title { font-size: 2.2rem; font-weight: 700; color: var(--pct-primary); margin: 10px 0 0; line-height: 1.3;}
        .total-case-card { display: flex; align-items: center; gap: 20px; background: var(--pct-primary-soft); padding: 20px 30px; border-radius: 16px; border: 1px solid var(--border); }
        .total-case-card-er { display: flex; align-items: center; gap: 20px; background: var(--pct-primary-soft); padding: 20px 30px; border-radius: 16px; border: 1px solid var(--border); }
        .total-case-card .icon-box { width: 60px; height: 60px; border-radius: 50%; background: #ffffff; color: var(--pct-accent); font-size: 1.8rem; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-soft); flex-shrink: 0; }
        
        .nav-segmented { background: #ffffff; padding: 8px; border-radius: 50px; box-shadow: var(--shadow-soft); border: 1px solid var(--border); display: flex; gap: 5px; margin-bottom: 30px; overflow-x: auto; max-width: 100%; white-space: nowrap; -webkit-overflow-scrolling: touch; }
        .nav-segmented::-webkit-scrollbar { display: none; }
        .nav-segmented .nav-item { flex: 0 0 auto; }
        .nav-segmented .nav-link { color: var(--pct-text-light); font-family: 'Prompt'; font-weight: 600; font-size: 0.95rem; border-radius: 40px; padding: 10px 24px; transition: all 0.3s ease; border: none; background: transparent; }
        .nav-segmented .nav-link:hover { color: var(--pct-primary); background: var(--pct-primary-soft); }
        .nav-segmented .nav-link.active { background: var(--pct-primary); color: #fff; box-shadow: 0 4px 10px rgba(15, 23, 42, 0.2); }

        .chart-card { background: var(--pct-card); border-radius: var(--rad); box-shadow: var(--shadow-soft); border: 1px solid var(--border); padding: 30px; height: 100%; transition: transform 0.3s ease;}
        .chart-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
        .chart-title { font-family: 'Prompt'; font-size: 1.1rem; font-weight: 700; color: var(--pct-text); margin-bottom: 25px; display: flex; align-items: center; border-bottom: 2px solid var(--border); padding-bottom: 15px;}
        .chart-container { position: relative; height: 320px; width: 100%; }
        
        .total-case-card-er:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
        .kpi-card { background: var(--pct-card); border-radius: var(--rad); box-shadow: var(--shadow-soft); border: 1px solid var(--border); padding: 25px; display: flex; align-items: center; gap: 20px; transition: transform 0.3s ease; height: 100%;}
        .kpi-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }

        .dept-stat-card { background: #fff; border-radius: 16px; padding: 24px; border: 1px solid var(--border); border-left: 6px solid; box-shadow: var(--shadow-soft); transition: 0.2s; height: 100%;}
        .dept-stat-card:hover { box-shadow: var(--shadow-hover); transform: translateY(-2px);}
        .dept-stat-card .s-title { font-size: 0.9rem; font-weight: 700; font-family: 'Prompt'; margin-bottom: 8px; }
        .dept-stat-card .s-val { font-size: 2.6rem; font-weight: 700; font-family: 'Prompt'; line-height: 1; }
        .icon-circle { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

        .data-card { background: var(--pct-card); border-radius: var(--rad); box-shadow: var(--shadow-soft); border: 1px solid var(--border); overflow: hidden; margin-top: 10px; }
        .data-card-header { padding: 20px 25px; display: flex; justify-content: space-between; align-items: center; background: #ffffff; flex-wrap: wrap;}
        .table-wrapper { max-height: 700px; overflow-y: auto; }
        .table-pct thead th { background-color: var(--pct-primary-soft); color: var(--pct-text-light); font-family: 'Prompt'; font-weight: 600; padding: 16px 20px; text-transform: uppercase; font-size: 0.8rem; border-bottom: 1px solid var(--border); letter-spacing: 0.05em; white-space: nowrap;}
        .table-pct tbody td { padding: 16px 20px; vertical-align: middle; border-bottom: 1px solid var(--border); font-size: 0.95rem; color: var(--pct-text);}
        .table-pct tbody tr { transition: background 0.2s ease; }
        .table-pct tbody tr:hover { background-color: var(--pct-primary-soft); }
        
        .st-badge { padding: 6px 14px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;}
        .st-closed { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;}
        .st-admit { background-color: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .st-wait { background-color: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .st-er { background-color: #fef2f2; color: #e11d48; border: 1px solid #fecaca; }
        .st-dc { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .st-gp{ background: #e0f2fe; color: #0369a1; }
        
        .badge-severe { background-color: #fef2f2; color: #e11d48; border: 1px solid #fecaca; }
        .badge-moderate { background-color: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .badge-mild { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }

        .journey-box { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-top: 8px; }
        .j-step { padding: 4px 10px; border-radius: 50px; font-weight: 600; font-family: 'Prompt', sans-serif; font-size: 0.75rem; white-space: nowrap; border: 1px solid transparent; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .j-arrow { color: #cbd5e1; font-size: 0.65rem; }
        .j-er { background: #fff7ed; color: #ea580c; border-color: #ffedd5; } 
        .j-icu { background: #fef2f2; color: #e11d48; border-color: #fecaca; } 
        .j-rcu { background: #fffbeb; color: #d97706; border-color: #fde68a; } 
        .j-a4 { background: #eff6ff; color: #3b82f6; border-color: #bfdbfe; } 
        .j-b3 { background: #ecfdf5; color: #059669; border-color: #a7f3d0; } 
        .j-gp { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .j-default { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }

        .hn-chip { display: inline-block; font-family: 'Prompt', sans-serif; font-size: 0.85rem; font-weight: 700; color: var(--pct-text); background: var(--pct-primary-soft); border: 1px solid var(--border); padding: 4px 10px; border-radius: 8px;}
        .dx-text { font-size: 0.85rem; color: #475569; background: #ffffff; padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); display: inline-block; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; box-shadow: var(--shadow-sm);}
        
        .btn-action { border-radius: 10px; font-size: 0.85rem; font-weight: 600; padding: 8px 14px; display: inline-flex; align-items: center; gap: 6px; border: none; transition: 0.2s; white-space: nowrap;}
        .btn-action:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
        .btn-view { background: var(--pct-primary-soft); color: var(--pct-text); border: 1px solid var(--border);}
        .btn-view:hover { background: var(--pct-primary); color: #fff; border-color: var(--pct-primary);}
        .btn-fix { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .btn-info { background: #ecfdfc; color: #046694; border: 1px solid #a7f3d0; }
        .btn-info.has-comment { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .case-comment-modal .modal-content { border: 0; border-radius: 22px; overflow: hidden; box-shadow: 0 24px 70px rgba(15, 23, 42, .2); }
        .case-comment-modal .modal-header { padding: 22px 26px; color: #fff; background: linear-gradient(135deg, #0f766e, #0e7490); border: 0; }
        .case-comment-modal .modal-body { padding: 24px 26px; }
        .case-comment-modal .comment-field { min-height: 220px; resize: vertical; border: 1px solid #cbd5e1; border-radius: 14px; padding: 14px 16px; line-height: 1.7; }
        .case-comment-modal .comment-field:focus { border-color: #0e7490; box-shadow: 0 0 0 .2rem rgba(14, 116, 144, .14); }
        .btn-close-case { background: #fef2f2; color: #e11d48; border: 1px solid #fecaca; }
        .btn-reopen { background: #ffffff; color: var(--pct-text); border: 1px solid var(--border); }
        
        .form-select, .form-control { border-radius: 12px; border-color: var(--border); padding: 10px 14px; font-size: 0.95rem; background-color: #fff; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);}
        .form-select:focus, .form-control:focus { border-color: var(--pct-accent); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }

        @media (max-width: 768px) {
            .pct-hero { padding: 25px 20px; flex-direction: column; align-items: center; text-align: center; }
            .hero-title { font-size: 1.6rem; margin-bottom: 20px; }
            .total-case-card { width: 100%; justify-content: center; padding: 15px; }
            .total-case-card .icon-box { width: 50px; height: 50px; font-size: 1.4rem; }
            .total-case-card .fs-1 { font-size: 2rem !important; }
            .nav-segmented { padding: 4px; margin-bottom: 20px; }
            .nav-segmented .nav-link { padding: 8px 16px; font-size: 0.85rem; }
            .chart-card { padding: 20px 15px; }
            .chart-title { font-size: 0.95rem; margin-bottom: 15px; }
            .chart-container { height: 280px; }
            .kpi-card { flex-direction: row; padding: 15px; gap: 15px; }
            .kpi-card .icon-circle { width: 45px !important; height: 45px !important; font-size: 1.4rem !important; }
            .kpi-card h2 { font-size: 1.5rem !important; }
            .kpi-card h6 { font-size: 0.8rem !important; }
            .dept-stat-card { padding: 15px 12px; border-left-width: 4px; }
            .dept-stat-card .s-title { font-size: 0.8rem; }
            .dept-stat-card .s-val { font-size: 1.8rem; }
            .data-card-header { flex-direction: column; align-items: flex-start; gap: 10px; padding: 15px; }
            .data-card-header h5 { font-size: 1.1rem; }
            .table-wrapper { max-height: 500px; }
            .table-pct thead th { padding: 10px; font-size: 0.75rem; }
            .table-pct tbody td { padding: 10px; font-size: 0.85rem; }
            .hn-chip { font-size: 0.75rem; padding: 2px 8px; }
            .d-name { font-size: 0.9rem !important; }
            .d-date { font-size: 0.9rem !important; }
            .d-ptype { font-size: 0.75rem; }
            .dx-text { max-width: 150px; font-size: 0.75rem; padding: 4px 8px; }
            .st-badge { font-size: 0.7rem; padding: 4px 10px; }
            .btn-actions-wrapper { flex-direction: row !important; flex-wrap: wrap; }
            .btn-action { padding: 6px 10px; font-size: 0.75rem; width: auto !important; flex-grow: 1;}
        }
    </style>
</head>
<body>

<?php 
// โหลด Header ตาม Role (Viewer หรือ PCT)
$path_prefix = "../"; 
if ($is_viewer) {
    require_once '../header_view.php';
} else {
    require_once '../header_pct.php';
}
?>

<div class="container-fluid py-4 mb-5" style="max-width: 1600px;">
    
    <?php if($statusMsg) { ?>
        <script>
            Swal.fire({
                icon: <?= json_encode(in_array($statusType, ['success', 'error'], true) ? $statusType : 'error') ?>,
                title: 'ผลการดำเนินการ',
                text: <?= json_encode($statusMsg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                timer: 2500,
                showConfirmButton: false
            });
        </script>
    <?php } ?>

    <!-- 1. Hero Header -->
    <div class="pct-hero d-flex justify-content-between align-items-start flex-wrap gap-3">
        <!-- ฝั่งซ้าย -->
        <div class="text-start">
            <span class="badge <?php echo $is_viewer ? 'bg-secondary' : 'bg-primary'; ?> text-white mb-2 shadow-sm px-3 py-2 rounded-pill fs-6">
                <i class="fa-solid <?php echo $is_viewer ? 'fa-eye' : 'fa-chess-king'; ?>"></i>
                <?php echo $is_viewer ? 'VIEWER' : 'PCT'; ?>
            </span>
            <h2 class="hero-title">ระบบติดตามผลการรักษาโรคลมร้อน</h2>
        </div>
        <!-- ฝั่งขวา -->
        <div class="d-flex gap-3 flex-wrap justify-content-end">
            <!-- รอรับเข้า ER -->
            <div class="total-case-card-er" style="cursor:pointer;" onclick="openErWaitingModal()" title="คลิกดูรายชื่อผู้ป่วยรอ ER รับเข้า">
                <div class="text-center">
                    <span class="d-block text-muted small fw-bold">
                        ผู้ป่วยรอ ER รับเข้า (ราย)
                        <i class="fa-solid fa-arrow-up-right-from-square ms-1 text-muted"
                            style="font-size:0.75rem;"></i>
                    </span>
                    <span class="d-block fs-1 fw-bold text-dark counter-val">
                        <?php echo number_format($total_cases_er); ?>
                    </span>
                </div>
            </div>
            <!-- ผู้ป่วยสะสม -->
            <div class="total-case-card">
                <div class="text-center">
                    <span class="d-block text-muted small fw-bold">
                        <i class="fa-solid fa-hospital-user"></i>
                        ผู้ป่วยสะสมทั้งหมด (ราย)
                    </span>
                    <span class="d-block fs-1 fw-bold text-dark counter-val">
                        <?php echo number_format($total_cases); ?>
                    </span>
                    <!-- จำแนกผู้ป่วย -->
                    <div class="d-flex gap-2 flex-wrap mt-2 justify-content-center">
                        <span class="badge rounded-pill px-3 py-2"
                            style="background:#fef2f2; color:#e11d48; font-size:0.78rem; font-weight:700; border:1px solid #fecaca;">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            รุนแรง:
                            <?php echo number_format($overview_stats['sev']['Severe'] ?? 0); ?>
                            คน
                        </span>
                        <span class="badge rounded-pill px-3 py-2"
                            style="background:#fffbeb; color:#d97706; font-size:0.78rem; font-weight:700; border:1px solid #fde68a;">
                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                            ไม่รุนแรง:
                            <?php echo number_format($overview_stats['sev']['Moderate'] ?? 0); ?>
                            คน
                        </span>
                        <span class="badge rounded-pill px-3 py-2"
                            style="background:#ecfdf5; color:#059669; font-size:0.78rem; font-weight:700; border:1px solid #a7f3d0;">
                            <i class="fa-solid fa-circle-check me-1"></i>
                            อื่นๆ:
                            <?php echo number_format($overview_stats['sev']['Mild'] ?? 0); ?>
                            คน
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. TAB NAVIGATION (Segmented Control) -->
    <ul class="nav nav-segmented" id="pctMainTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview"><i class="fa-solid fa-globe me-1"></i> ภาพรวม</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-gp"><i class="fa-solid fa-user-doctor me-1"></i> GP</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-er"><i class="fa-solid fa-truck-medical me-1"></i> ER</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-icu"><i class="fa-solid fa-heart-pulse me-1"></i> ICU</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rcu"><i class="fa-solid fa-bed me-1"></i> RCU</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-a4"><i class="fa-solid fa-bed me-1"></i> A4</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-b3"><i class="fa-solid fa-bed me-1"></i> B3</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-med"><i class="fa-solid fa-stethoscope me-1"></i> MED</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pmr"><i class="fa-solid fa-person-walking-with-cane me-1"></i> PMR</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pcu"><i class="fa-solid fa-house-medical me-1"></i> PCU</button></li>
    </ul>

    <!-- 3. TAB CONTENT -->
    <div class="tab-content" id="pctTabContent">
        
        <!-- ==================== TAB: OVERVIEW ==================== -->
        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
            
            <!-- 📌 ตัวกรองช่วงวันที่ -->
            <form method="GET" class="row g-2 g-md-3 mb-4 bg-white p-3 p-md-4 rounded-4 shadow-sm border align-items-end" style="border-color: var(--border) !important;">
                <div class="col-12 col-md-3">
                    <label for="filter_start_date" class="form-label small fw-bold text-dark mb-1 mb-md-2"><i class="fa-regular fa-calendar-days me-1 text-muted"></i> วันที่เริ่มต้น</label>
                    <div class="input-group">
                        <input type="text" id="filter_start_date_display" class="form-control" value="<?= htmlspecialchars($format_thai_date_filter($filter_start_date), ENT_QUOTES, 'UTF-8') ?>" placeholder="เลือกวันที่" readonly aria-hidden="true">
                        <button type="button" class="btn btn-outline-secondary position-relative" aria-label="เปิดปฏิทินวันที่เริ่มต้น">
                            <i class="fa-regular fa-calendar"></i>
                            <input type="date" id="filter_start_date" name="filter_start_date" value="<?= htmlspecialchars($filter_start_date, ENT_QUOTES, 'UTF-8') ?>" aria-label="วันที่เริ่มต้น" style="position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer;">
                        </button>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label for="filter_end_date" class="form-label small fw-bold text-dark mb-1 mb-md-2"><i class="fa-regular fa-calendar me-1 text-muted"></i> วันที่สิ้นสุด</label>
                    <div class="input-group">
                        <input type="text" id="filter_end_date_display" class="form-control" value="<?= htmlspecialchars($format_thai_date_filter($filter_end_date), ENT_QUOTES, 'UTF-8') ?>" placeholder="เลือกวันที่" readonly aria-hidden="true">
                        <button type="button" class="btn btn-outline-secondary position-relative" aria-label="เปิดปฏิทินวันที่สิ้นสุด">
                            <i class="fa-regular fa-calendar"></i>
                            <input type="date" id="filter_end_date" name="filter_end_date" value="<?= htmlspecialchars($filter_end_date, ENT_QUOTES, 'UTF-8') ?>" aria-label="วันที่สิ้นสุด" style="position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer;">
                        </button>
                    </div>
                </div>
                <div class="col-12 col-md-3 mt-3 mt-md-0">
                    <button type="submit" class="btn w-100 fw-bold shadow-sm" style="background-color: var(--pct-primary); color: white; height: 48px; border-radius: 12px;">
                        <i class="fa-solid fa-filter me-1"></i> ประมวลผล
                    </button>
                </div>
                <?php if ($filter_start_date !== '' || $filter_end_date !== ''): ?>
                <div class="col-12 col-md-3 mt-2 mt-md-0">
                    <a href="indexPCT.php" class="btn btn-light w-100 border fw-bold shadow-sm" style="height: 48px; border-radius: 12px; color: var(--pct-text-light);">
                        <i class="fa-solid fa-rotate-left me-1"></i> ล้างค่า
                    </a>
                </div>
                <?php endif; ?>
            </form>

            <div class="row g-3 g-md-4 mb-4">
                <div class="col-6 col-md-3">
                    <div class="kpi-card" onclick="openActiveCasesModal()" style="cursor:pointer;" title="คลิกดูรายชื่อผู้ป่วยกำลังรักษา">
                        <div class="icon-circle" style="width:65px; height:65px; font-size:2rem; background:#eff6ff; color:#3b82f6;"><i class="fa-solid fa-bed-pulse"></i></div>
                        <div>
                            <h6 class="text-muted fw-bold mb-1 font-prompt" style="font-size:0.95rem;">
                                กำลังรักษา <i class="fa-solid fa-arrow-up-right-from-square ms-1 text-primary" style="font-size:0.75rem;"></i>
                            </h6>
                            <h2 class="m-0 font-prompt fw-bold text-dark counter-val"><?php echo number_format(($overview_stats['admit'] ?? 0) + ($overview_stats['wait'] ?? 0)); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="kpi-card" onclick="openDcCasesModal()" style="cursor:pointer;" title="คลิกดูรายชื่อผู้ป่วยที่จำหน่ายแล้ว">
                        <div class="icon-circle" style="width:65px; height:65px; font-size:2rem; background:#ecfdf5; color:#10b981;"><i class="fa-solid fa-house-circle-check"></i></div>
                        <div>
                            <h6 class="text-muted fw-bold mb-1 font-prompt" style="font-size:0.95rem;">
                                จำหน่ายแล้ว <i class="fa-solid fa-arrow-up-right-from-square ms-1 text-success" style="font-size:0.75rem;"></i>
                            </h6>
                            <h2 class="m-0 font-prompt fw-bold text-dark counter-val"><?php echo number_format($overview_stats['dc'] ?? 0); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="icon-circle" style="width:65px; height:65px; font-size:2rem; background:#fffbeb; color:#f59e0b;"><i class="fa-solid fa-ambulance"></i></div>
                        <div><h6 class="text-muted fw-bold mb-1 font-prompt" style="font-size:0.95rem;">ส่งต่อ (Refer)</h6><h2 class="m-0 font-prompt fw-bold text-dark counter-val"><?php echo number_format($overview_stats['refer'] ?? 0); ?></h2></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="icon-circle" style="width:65px; height:65px; font-size:2rem; background:#fef2f2; color:#e11d48;"><i class="fa-solid fa-book-skull"></i></div>
                        <div><h6 class="text-muted fw-bold mb-1 font-prompt" style="font-size:0.95rem;">เสียชีวิต</h6><h2 class="m-0 font-prompt fw-bold text-dark counter-val"><?php echo number_format($overview_stats['dead'] ?? 0); ?></h2></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="kpi-card" onclick="openErAdmitModal()" style="cursor:pointer;" title="คลิกดูรายชื่อผู้ป่วยที่ ER ส่ง Admit">
                        <div class="icon-circle" style="width:65px; height:65px; font-size:2rem; background:#eef2ff; color:#6366f1;"><i class="fa-solid fa-hospital"></i></div>
                        <div><h6 class="text-muted fw-bold mb-1 font-prompt" style="font-size:0.95rem;">
                                Admit (สะสม) <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size:0.75rem; color:#6366f1;"></i>
                            </h6><h2 class="m-0 font-prompt fw-bold text-dark counter-val"><?php echo number_format($kpi_admit); ?></h2></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="kpi-card" onclick="openCtControlledModal()" style="cursor:pointer;" title="คลิกดูรายชื่อเคสที่ควบคุมอุณหภูมิได้">
                        <div class="icon-circle" style="width:65px; height:65px; font-size:2rem; background:#eef2ff; color:#6366f1;"><i class="fa-solid fa-thermometer-half"></i></div>
                        <div><h6 class="text-muted fw-bold mb-1 font-prompt" style="font-size:0.95rem;">
                                CT (ER) ที่ควบคุมได้ (สะสม) <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size:0.75rem; color:#6366f1;"></i>
                            </h6><h2 class="m-0 font-prompt fw-bold text-dark counter-val"><?php echo number_format($kpi_ct_controlled); ?></h2></div>
                    </div>
                </div>
            </div>

            <?php echo renderChartsHtml($overview_stats); ?>

            <?php echo renderPatientTable($filtered_overview, 'table-overview', 'แฟ้มประวัติผู้ป่วยทั้งหมด'); ?>
        </div>

        <div class="tab-pane fade" id="tab-gp" role="tabpanel">
            <?php echo renderDeptTab('GP', 'ห้องตรวจโรคทั่วไป (GP)', 'fa-user-doctor', $dept_stats['GP'], renderPatientTable($grouped_cases['gp'], 'table-gp', 'รายชื่อผู้ป่วย GP')); ?>
        </div>
        <div class="tab-pane fade" id="tab-er" role="tabpanel">
            <?php echo renderDeptTab('ER', 'ห้องฉุกเฉิน (ER)', 'fa-truck-medical', $dept_stats['ER'], renderPatientTable($grouped_cases['er'], 'table-er', 'รายชื่อผู้ป่วย ER (รอส่งตัว / ค้างประเมิน)')); ?>
        </div>
        <div class="tab-pane fade" id="tab-icu" role="tabpanel">
            <?php echo renderDeptTab('ICU', 'หอผู้ป่วยวิกฤต (ICU)', 'fa-heart-pulse', $dept_stats['ICU'], renderPatientTable($grouped_cases['icu'], 'table-icu', 'รายชื่อผู้ป่วย ICU (Active / Waitlist)')); ?>
        </div>
        <div class="tab-pane fade" id="tab-rcu" role="tabpanel">
            <?php echo renderDeptTab('RCU', 'หอผู้ป่วย RCU', 'fa-bed', $dept_stats['RCU'], renderPatientTable($grouped_cases['rcu'], 'table-rcu', 'รายชื่อผู้ป่วย RCU (Active / Waitlist)')); ?>
        </div>
        <div class="tab-pane fade" id="tab-a4" role="tabpanel">
            <?php echo renderDeptTab('A4', 'หอผู้ป่วย A4 (VIP4)', 'fa-bed', $dept_stats['A4'], renderPatientTable($grouped_cases['a4'], 'table-a4', 'รายชื่อผู้ป่วย Ward A4 (Active / Waitlist)')); ?>
        </div>
        <div class="tab-pane fade" id="tab-b3" role="tabpanel">
            <?php echo renderDeptTab('B3', 'หอผู้ป่วย B3', 'fa-bed', $dept_stats['B3'], renderPatientTable($grouped_cases['b3'], 'table-b3', 'รายชื่อผู้ป่วย Ward B3 (Active / Waitlist)')); ?>
        </div>
        <div class="tab-pane fade" id="tab-med" role="tabpanel">
            <?php echo renderDeptTab('MED', 'อายุรกรรม (MED)', 'fa-stethoscope', $dept_stats['MED'], renderPatientTable($grouped_cases['med'], 'table-med', 'รายชื่อผู้ป่วยรอติดตามอาการ (Pending Follow-up)')); ?>
        </div>
        <div class="tab-pane fade" id="tab-pmr" role="tabpanel">
            <?php echo renderDeptTab('PMR', 'เวชศาสตร์ฟื้นฟู (PMR)', 'fa-person-walking-with-cane', $dept_stats['PMR'], renderPatientTable($grouped_cases['pmr'], 'table-pmr', 'รายชื่อผู้ป่วยรอทำกายภาพบำบัด (Pending PMR)')); ?>
        </div>
        <div class="tab-pane fade" id="tab-pcu" role="tabpanel">
            <?php echo renderDeptTab('PCU', 'เยี่ยมบ้าน (PCU)', 'fa-house-medical', $dept_stats['PCU'], renderPatientTable($grouped_cases['pcu'], 'table-pcu', 'รายชื่อผู้ป่วยรอลงพื้นที่เยี่ยมบ้าน (Pending Visit)')); ?>
        </div>

    </div>
</div>

<!-- ==========================================
     MODALS (แสดงเมื่อไม่ใช่ Viewer เท่านั้น)
     ========================================== -->
<?php if(!$is_viewer): ?>
<div class="modal fade" id="fixRouteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <form action="indexPCT.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= pct_escape_html($csrf_token) ?>">
                <input type="hidden" name="pct_action" value="fix_route">
                <input type="hidden" name="ward_id" id="fix_ward_id">
                <div class="modal-header" style="background: #fffbeb; border-bottom: 1px solid #fde68a; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                    <h5 class="modal-title fw-bold font-head fs-6" style="color: #d97706;"><i class="fa-solid fa-route me-2"></i> แก้ไขตึกปลายทาง</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert bg-white border shadow-sm mb-4" style="border-radius: 16px;">
                        <span class="text-muted small d-block mb-1">ผู้ป่วย</span>
                        <strong id="fix_patient_name" class="text-dark fs-6"></strong><br>
                        <span class="text-muted small d-block mt-2 mb-1">ตึกปัจจุบัน</span>
                        <span id="fix_current_ward" class="badge badge-severe px-3 py-2 fs-6"></span>
                    </div>
                    <label class="form-label fw-bold text-dark">ย้ายไปที่ตึกใหม่:</label>
                    <select name="new_ward" class="form-select" style="height: 50px;" required>
                        <option value="">-- เลือกตึก --</option>
                        <option value="Ward ICU">Ward ICU</option>
                        <option value="Ward RCU">Ward RCU</option>
                        <option value="Ward A4">Ward A4</option>
                        <option value="Ward B3">Ward B3</option>
                    </select>
                </div>
                <div class="modal-footer bg-light" style="border-radius: 0 0 24px 24px; padding: 15px 25px;">
                    <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn fw-bold shadow-sm px-4 rounded-pill" style="background: #f59e0b; color:white;"><i class="fa-solid fa-save me-1"></i> ยืนยันย้ายตึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="closeCaseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <form action="indexPCT.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= pct_escape_html($csrf_token) ?>">
                <input type="hidden" name="pct_action" value="close_case"><input type="hidden" name="case_id" id="close_case_id">
                <div class="modal-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                    <h5 class="modal-title fw-bold font-head fs-6" style="color: #e11d48;"><i class="fa-solid fa-lock me-2"></i> ยืนยันการปิดเคส</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div style="width: 70px; height: 70px; background: #fef2f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                        <i class="fa-solid fa-shield-check fa-2x" style="color: #e11d48;"></i>
                    </div>
                    <p class="mb-2 text-dark fs-6">คุณต้องการ <strong>"ปิดเคส"</strong> ผู้ป่วย<br><span id="close_patient_name" class="fs-6 fw-bold mt-2 d-block" style="color: #e11d48;"></span></p>
                    <div class="alert bg-light border small text-muted text-start mt-4 mb-0" style="border-radius:12px; font-size: 0.8rem;"><i class="fa-solid fa-info-circle text-info me-1"></i> สถานะการจำหน่ายจะยังคงอยู่ ระบบเพียงแค่เพิ่มสัญลักษณ์ปิดจบแฟ้มงานนี้ของ PCT ให้สมบูรณ์</div>
                </div>
                <div class="modal-footer bg-light justify-content-center" style="border-radius: 0 0 24px 24px; padding: 15px 25px;">
                    <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn text-white fw-bold shadow-sm px-4 rounded-pill" style="background: #e11d48;"><i class="fa-solid fa-lock me-1"></i> ปิดเคส</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="reopenModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <form action="indexPCT.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= pct_escape_html($csrf_token) ?>">
                <input type="hidden" name="pct_action" value="reopen_case"><input type="hidden" name="case_id" id="reopen_case_id">
                <div class="modal-header" style="background: #f1f5f9; border-bottom: 1px solid #cbd5e1; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                    <h5 class="modal-title fw-bold font-head fs-6" style="color: var(--pct-text-light);"><i class="fa-solid fa-unlock me-2"></i> ยกเลิกการปิดเคส</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div style="width: 70px; height: 70px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                        <i class="fa-solid fa-lock-open fa-2x" style="color: var(--pct-text-light);"></i>
                    </div>
                    <p class="text-dark fs-6 mb-0">คุณต้องการปลดล็อกเคสผู้ป่วย <br><strong id="reopen_patient_name" class="fs-6 mt-2 d-block" style="color: var(--pct-primary);"></strong> <br>ให้กลับมามีสถานะ <b>Active</b> อีกครั้ง ใช่หรือไม่?</p>
                </div>
                <div class="modal-footer bg-light justify-content-center" style="border-radius: 0 0 24px 24px; padding: 15px 25px;">
                    <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn text-white fw-bold shadow-sm px-4 rounded-pill" style="background: var(--pct-primary);"><i class="fa-solid fa-check-circle me-1"></i> ปลดล็อกเคส</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: รายชื่อผู้ป่วยกำลังรักษา -->
<div class="modal fade" id="activeCasesModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-header" style="background: #eff6ff; border-bottom: 1px solid #bfdbfe; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                <h5 class="modal-title fw-bold font-head" style="color: #2563eb;">
                    <i class="fa-solid fa-bed-pulse me-2"></i> รายชื่อผู้ป่วยกำลังรักษา
                    <span class="badge bg-primary ms-2"><?php echo count($active_cases); ?> ราย</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.9rem;">
                        <thead style="position:sticky; top:0; z-index:5; background:#f8fafc;">
                            <tr>
                                <th class="px-4 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">#</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">HN / ชื่อผู้ป่วย</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">ประเภท</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">วินิจฉัย</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">สถานะ / ตึก</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">วันที่รับ</th>
                                <th class="px-3 py-3 text-center text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($active_cases)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-bed fa-3x mb-3 d-block text-light"></i>
                                    ไม่มีผู้ป่วยที่กำลังรักษาในขณะนี้
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_cases as $i => $row):
                                $w_stat = $row['last_ward_status'] ?? '';
                                $w_type = $row['last_ward_type']   ?? '';
                                $e_stat = $row['er_status']        ?? '';
                                $dx     = $row['best_dx_calculated'] ?? '-';
                                $arrival = !empty($row['hospital_arrival_datetime'])
                                           ? $row['hospital_arrival_datetime']
                                           : $row['created_at'];

                                if ($w_stat == 'Active') {
                                    $badge = "<span class='st-badge st-admit'><i class='fa-solid fa-bed-pulse'></i> Admit: " . htmlspecialchars($w_type) . "</span>";
                                } elseif ($w_stat == 'AdmitWait') {
                                    $badge = "<span class='st-badge st-wait'><i class='fa-solid fa-clock'></i> รอรับ: " . htmlspecialchars($w_type) . "</span>";
                                } else {
                                    $er_dest = !empty($row['er_admit_type']) ? htmlspecialchars($row['er_admit_type']) : '';
                                    $badge = "<span class='st-badge st-er'><i class='fa-solid fa-truck-medical'></i> ER รอส่ง {$er_dest}</span>";
                                }
                            ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td class="px-4 py-3 text-muted fw-bold"><?= $i + 1 ?></td>
                                <td class="px-3 py-3">
                                    <div class="hn-chip mb-1">HN: <?= htmlspecialchars($row['hn']) ?></div>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['patient_name']) ?></div>
                                </td>
                                <td class="px-3 py-3 small text-muted"><?= htmlspecialchars($row['person_type'] ?? '-') ?></td>
                                <td class="px-3 py-3">
                                    <div class="dx-text" title="<?= htmlspecialchars($dx) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($dx, 0, 35, '...')) ?>
                                    </div>
                                </td>
                                <td class="px-3 py-3"><?= $badge ?></td>
                                <td class="px-3 py-3 small text-muted" style="white-space:nowrap;">
                                    <?= date('d/m/Y', strtotime($arrival)) ?><br>
                                    <span class="text-muted"><?= date('H:i', strtotime($arrival)) ?> น.</span>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <a href="../view_patient_full_history.php?hn=<?= urlencode($row['hn']) ?>"
                                       class="btn-action btn-view shadow-sm">
                                        <i class="fa-solid fa-folder-open"></i> เปิดแฟ้ม
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light" style="border-radius: 0 0 24px 24px;">
                <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: รายชื่อผู้ป่วยที่จำหน่ายแล้ว -->
<div class="modal fade" id="dcCasesModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-header" style="background: #ecfdf5; border-bottom: 1px solid #a7f3d0; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                <h5 class="modal-title fw-bold font-head" style="color: #059669;">
                    <i class="fa-solid fa-house-circle-check me-2"></i> รายชื่อผู้ป่วยที่จำหน่ายแล้ว
                    <span class="badge bg-success ms-2"><?php echo count($dc_cases); ?> ราย</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.9rem;">
                        <thead style="position:sticky; top:0; z-index:5; background:#f8fafc;">
                            <tr>
                                <th class="px-4 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">#</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">HN / ชื่อผู้ป่วย</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">ประเภท</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">วินิจฉัย</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">จำหน่ายจาก</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">วันที่รับ</th>
                                <th class="px-3 py-3 text-center text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($dc_cases)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-house-circle-check fa-3x mb-3 d-block text-light"></i>
                                    ไม่มีผู้ป่วยที่จำหน่ายในช่วงเวลานี้
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dc_cases as $i => $row):
                                $w_stat  = $row['last_ward_status'] ?? '';
                                $w_type  = $row['last_ward_type']   ?? '';
                                $e_stat  = $row['er_status']        ?? '';
                                $gp_disp = $row['gp_disposition']   ?? '';
                                $dx      = $row['best_dx_calculated'] ?? '-';
                                $arrival = !empty($row['hospital_arrival_datetime'])
                                           ? $row['hospital_arrival_datetime']
                                           : $row['created_at'];

                                if (!empty($row['last_ward_id']) && $w_stat == 'Discharged') {
                                    $dc_from = "<span class='st-badge st-dc'><i class='fa-solid fa-house-circle-check'></i> D/C จาก " . htmlspecialchars($w_type) . "</span>";
                                } elseif ($e_stat == 'Discharge') {
                                    $dc_from = "<span class='st-badge st-dc'><i class='fa-solid fa-house-medical'></i> D/C จาก ER</span>";
                                } elseif ($gp_disp == 'Discharge') {
                                    $dc_from = "<span class='st-badge st-gp'><i class='fa-solid fa-stethoscope'></i> D/C จาก GP</span>";
                                } else {
                                    $dc_from = "<span class='st-badge st-closed'><i class='fa-solid fa-check-double'></i> ปิดเคส PCT</span>";
                                }
                            ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td class="px-4 py-3 text-muted fw-bold"><?= $i + 1 ?></td>
                                <td class="px-3 py-3">
                                    <div class="hn-chip mb-1">HN: <?= htmlspecialchars($row['hn']) ?></div>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['patient_name']) ?></div>
                                </td>
                                <td class="px-3 py-3 small text-muted"><?= htmlspecialchars($row['person_type'] ?? '-') ?></td>
                                <td class="px-3 py-3">
                                    <div class="dx-text" title="<?= htmlspecialchars($dx) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($dx, 0, 35, '...')) ?>
                                    </div>
                                </td>
                                <td class="px-3 py-3"><?= $dc_from ?></td>
                                <td class="px-3 py-3 small text-muted" style="white-space:nowrap;">
                                    <?= date('d/m/Y', strtotime($arrival)) ?><br>
                                    <span class="text-muted"><?= date('H:i', strtotime($arrival)) ?> น.</span>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <a href="../view_patient_full_history.php?hn=<?= urlencode($row['hn']) ?>"
                                       class="btn-action btn-view shadow-sm">
                                        <i class="fa-solid fa-folder-open"></i> เปิดแฟ้ม
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light" style="border-radius: 0 0 24px 24px;">
                <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> ปิด
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: รายชื่อผู้ป่วย Admit (สะสม)) -->
<div class="modal fade" id="erAdmitModal" tabindex="-1" aria-labelledby="erAdmitModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-header" style="background: #eef2ff; border-bottom: 1px solid #c7d2fe; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                <h5 class="modal-title fw-bold font-head" id="erAdmitModalTitle" style="color: #4f46e5;">
                    <i class="fa-solid fa-hospital me-2"></i> รายชื่อผู้ป่วย Admit (สะสม)
                    <span class="badge ms-2" style="background:#6366f1; color:#fff;"><?= number_format($kpi_admit) ?> รายการ</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.9rem;">
                        <thead style="position:sticky; top:0; z-index:5; background:#f8fafc;">
                            <tr>
                                <th class="px-4 py-3">#</th>
                                <th class="px-3 py-3">HN / ชื่อผู้ป่วย</th>
                                <th class="px-3 py-3" width="13%">ประเภทกำลังพล</th>
                                <th class="px-3 py-3">วินิจฉัยแรกรับ</th>
                                <th class="px-3 py-3">ส่ง Admit ไปยัง</th>
                                <th class="px-3 py-3">วันที่รับบริการ</th>
                                <th class="px-3 py-3 text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($er_admit_cases)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-hospital fa-3x mb-3 d-block text-light"></i>
                                    ไม่พบรายการผู้ป่วย Admit จาก ER
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($er_admit_cases as $i => $row):
                                $er_arrival = $row['hospital_arrival_datetime'] ?: ($row['recorded_at'] ?: $row['created_at']);
                            ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td class="px-4 py-3 text-muted fw-bold"><?= $i + 1 ?></td>
                                <td class="px-3 py-3">
                                    <div class="hn-chip mb-1">HN: <?= htmlspecialchars((string)($row['hn'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($row['patient_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="px-3 py-3 small text-muted"><?= htmlspecialchars((string)($row['person_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-3 py-3"><div class="dx-text"><?= htmlspecialchars((string)($row['FstDx'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></td>
                                <td class="px-3 py-3">
                                    <?php
                                        if ($row['admit_type'] == 'Ward A4') {
                                            echo "<span class='st-badge st-a4' style='background-color: #abf89c; color: #000;'><i class='fa-solid fa-bed'></i> Ward A4</span>";
                                        } elseif ($row['admit_type'] == 'Ward B3') {
                                            echo "<span class='st-badge st-b3' style='background-color: #ffde7c; color: #000;'><i class='fa-solid fa-bed'></i> Ward B3</span>";
                                        } elseif ($row['admit_type'] == 'Ward ICU') {
                                            echo "<span class='st-badge st-icu' style='background-color: #dc7235; color: #fff;'><i class='fa-solid fa-bed-pulse'></i> Ward ICU</span>";
                                        }else {
                                            echo "ไม่พบข้อมูล";
                                        }
                                    ?>
                                </td>
                                <td class="px-3 py-3 small text-muted" style="white-space:nowrap;">
                                    <?= $er_arrival ? date('d/m/Y', strtotime($er_arrival)) : '-' ?><br>
                                    <?php if ($er_arrival): ?><span><?= date('H:i', strtotime($er_arrival)) ?> น.</span><?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <a href="../view_patient_full_history.php?hn=<?= urlencode((string)$row['hn']) ?>" class="btn-action btn-view shadow-sm">
                                        <i class="fa-solid fa-folder-open"></i> เปิดแฟ้ม
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light" style="border-radius: 0 0 24px 24px;">
                <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ctControlledModal" tabindex="-1" aria-labelledby="ctControlledModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-header" style="background:#ecfeff; border-bottom:1px solid #a5f3fc; border-radius:24px 24px 0 0; padding:20px 25px;">
                <h5 class="modal-title fw-bold font-head" id="ctControlledModalTitle" style="color:#0e7490;">
                    <i class="fa-solid fa-temperature-half me-2"></i> รายชื่อเคส CT (ER) ที่ควบคุมได้ (สะสม)
                    <span class="badge ms-2" style="background:#0891b2; color:#fff;"><?= number_format($kpi_ct_controlled) ?> รายการ</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.9rem;">
                        <thead style="position:sticky; top:0; z-index:5; background:#f8fafc;">
                            <tr>
                                <th class="px-4 py-3">#</th>
                                <th class="px-3 py-3">HN / ชื่อผู้ป่วย</th>
                                <th class="px-3 py-3" width="14%">ประเภทกำลังพล</th>
                                <th class="px-3 py-3">วินิจฉัยแรกรับ</th>
                                <th class="px-3 py-3 text-center">CT แรกรับ</th>
                                <th class="px-3 py-3 text-center">CT 30 นาที</th>
                                <th class="px-3 py-3">วันที่รับบริการ</th>
                                <th class="px-3 py-3 text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($ct_controlled_cases)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-temperature-half fa-3x mb-3 d-block text-light"></i>
                                    ไม่พบเคสที่มี CT แรกรับตั้งแต่ 38°C และ CT 30 นาทีไม่เกิน 38°C
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ct_controlled_cases as $i => $row):
                                $ct_arrival = $row['hospital_arrival_datetime'] ?: ($row['recorded_at'] ?: $row['created_at']);
                                $safe_hn = htmlspecialchars((string)($row['hn'] ?? '-'), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td class="px-4 py-3 text-muted fw-bold"><?= $i + 1 ?></td>
                                <td class="px-3 py-3">
                                    <div class="hn-chip mb-1">HN: <?= $safe_hn ?></div>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($row['patient_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="px-3 py-3 small text-muted"><?= htmlspecialchars((string)($row['person_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-3 py-3"><div class="dx-text"><?= htmlspecialchars((string)($row['FstDx'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></td>
                                <td class="px-3 py-3 text-center fw-bold"><?= htmlspecialchars((string)$row['ct_initial'], ENT_QUOTES, 'UTF-8') ?> °C</td>
                                <td class="px-3 py-3 text-center fw-bold text-success"><?= htmlspecialchars((string)$row['ct_30min'], ENT_QUOTES, 'UTF-8') ?> °C</td>
                                <td class="px-3 py-3 small text-muted" style="white-space:nowrap;">
                                    <?= $ct_arrival ? date('d/m/Y', strtotime($ct_arrival)) : '-' ?><br>
                                    <?php if ($ct_arrival): ?><span><?= date('H:i', strtotime($ct_arrival)) ?> น.</span><?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <a href="../view_patient_full_history.php?hn=<?= urlencode((string)($row['hn'] ?? '')) ?>" class="btn-action btn-view shadow-sm">
                                        <i class="fa-solid fa-folder-open"></i> เปิดแฟ้ม
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light" style="border-radius:0 0 24px 24px;">
                <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function openErAdmitModal() {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('erAdmitModal')).show();
    }

    function openCtControlledModal() {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ctControlledModal')).show();
    }

    // Modal ผู้ป่วยที่รักษาอยู่
    function openActiveCasesModal() {
        new bootstrap.Modal(document.getElementById('activeCasesModal')).show();
    }

    // Modal ผู้ป่วยที่จำหน่ายแล้ว
    function openDcCasesModal() {
        new bootstrap.Modal(document.getElementById('dcCasesModal')).show();
    }

    // ==========================================
    // 📊 ระบบ Pagination (แบ่งหน้าตาราง)
    // ==========================================
    const ROWS_PER_PAGE = 10;
    const tablePagination = {};

    function initTablePagination() {
        document.querySelectorAll('.table-pct').forEach(table => {
            let tableId = table.id;
            if (tableId) {
                tablePagination[tableId] = { currentPage: 1 };
                let rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    if(!row.querySelector('td[colspan]')) {
                        row.dataset.matchFilter = 'true'; // กำหนดค่าเริ่มต้นว่าตรงกับเงื่อนไขกรอง
                    }
                });
                paginateTable(tableId);
            }
        });
    }

    function paginateTable(tableId) {
        let table = document.getElementById(tableId);
        if (!table) return;
        
        let state = tablePagination[tableId];
        let rows = Array.from(table.querySelectorAll('tbody tr')).filter(r => !r.querySelector('td[colspan]'));
        let matchedRows = rows.filter(r => r.dataset.matchFilter !== 'false');
        
        let totalPages = Math.ceil(matchedRows.length / ROWS_PER_PAGE);
        if (totalPages === 0) totalPages = 1;
        if (state.currentPage > totalPages) state.currentPage = totalPages;
        if (state.currentPage < 1) state.currentPage = 1;
        
        let startIndex = (state.currentPage - 1) * ROWS_PER_PAGE;
        let endIndex = startIndex + ROWS_PER_PAGE;
        
        // ซ่อนทั้งหมดก่อน แล้วเปิดเฉพาะที่อยู่ในหน้าปัจจุบัน
        rows.forEach(r => r.style.display = 'none');
        matchedRows.slice(startIndex, endIndex).forEach(r => r.style.display = '');

        renderPaginationControls(tableId, totalPages, state.currentPage);
    }

    function renderPaginationControls(tableId, totalPages, currentPage) {
        let container = document.getElementById('pagination-' + tableId);
        if (!container) return;

        let html = `
            <span class="text-muted small fw-bold"><i class="fa-solid fa-list me-1"></i> หน้า ${currentPage} จาก ${totalPages}</span>
            <div class="btn-group shadow-sm" style="border-radius: 8px;">
                <button class="btn btn-light border text-dark btn-sm px-3" onclick="changePage('${tableId}', -1)" ${currentPage === 1 ? 'disabled' : ''}><i class="fa-solid fa-chevron-left me-1"></i> ย้อนกลับ</button>
                <button class="btn btn-light border text-dark btn-sm px-3" onclick="changePage('${tableId}', 1)" ${currentPage === totalPages ? 'disabled' : ''}>ถัดไป <i class="fa-solid fa-chevron-right ms-1"></i></button>
            </div>
        `;
        container.innerHTML = html;
    }

    function changePage(tableId, direction) {
        if (tablePagination[tableId]) {
            tablePagination[tableId].currentPage += direction;
            paginateTable(tableId);
        }
    }

    // ==========================================
    // 📊 ฟังก์ชันส่งออก Excel แบบ Executive Summary (อัปเดตใหม่รองรับ Pagination)
    // ==========================================
    function exportTableToExcel(tableId, title) {
        let table = document.getElementById(tableId);
        if (!table) return;

        let rows = table.querySelectorAll('tbody tr');
        let data = [];
        let count = 1;

        rows.forEach(row => {
            if (row.querySelector('td[colspan]')) return; 
            // เช็คว่าแถวนั้นถูกกรองออกหรือไม่ (ถ้าถูกกรองออกจะไม่ส่งออกไป Excel)
            if (row.dataset.matchFilter === 'false') return;
            if (row.dataset.exportableSeverity !== 'true') return;

            let fullDataStr = row.getAttribute('data-full');
            if (fullDataStr) {
                try {
                    let rowData = JSON.parse(fullDataStr);
                    let finalRow = { 'ลำดับ': count++, ...rowData };
                    data.push(finalRow);
                } catch(e) {
                    console.error('JSON Parse error', e);
                }
            }
        });

        if(data.length === 0) {
            Swal.fire('แจ้งเตือน', 'ไม่มีข้อมูลสำหรับส่งออก หรืออาจถูกกรองออกทั้งหมด', 'warning');
            return;
        }

        let wb = XLSX.utils.book_new();
        let ws = XLSX.utils.json_to_sheet(data);
        let headers = Object.keys(data[0]);
        let hnColumn = headers.indexOf('HN');
        let severityColumn = Object.keys(data[0]).indexOf('ความรุนแรง');
        headers.forEach((header, columnIndex) => {
            let cell = ws[XLSX.utils.encode_cell({ r: 0, c: columnIndex })];
            if (cell) {
                cell.s = cell.s || {};
                cell.s.font = Object.assign({}, cell.s.font || {}, { bold: true });
            }
        });
        let hnCounts = new Map();
        data.forEach(row => {
            let hn = String(row['HN'] || '').trim();
            if (hn && hn !== '-') hnCounts.set(hn, (hnCounts.get(hn) || 0) + 1);
        });

        if (hnColumn >= 0) {
            data.forEach((row, rowIndex) => {
                let hn = String(row['HN'] || '').trim();
                if (hnCounts.get(hn) < 2) return;
                let cell = ws[XLSX.utils.encode_cell({ r: rowIndex + 1, c: hnColumn })];
                if (!cell) return;
                cell.s = cell.s || {};
                cell.s.font = Object.assign({}, cell.s.font || {}, { color: { rgb: 'FFFF0000' } });
            });
        }

        let severityStyles = {
            Severe: { fill: { patternType: 'solid', fgColor: { rgb: 'FFFF0000' } }, font: { bold: true, color: { rgb: 'FFFFFFFF' } } },
            Moderate: { fill: { patternType: 'solid', fgColor: { rgb: 'FFFFFFCC' } }, font: { bold: true, color: { rgb: 'FF7C5A00' } } },
            Mild: { fill: { patternType: 'solid', fgColor: { rgb: 'FFDCDCDC' } }, font: { bold: true, color: { rgb: 'FF475569' } } }
        };
        if (severityColumn >= 0) {
            data.forEach((row, rowIndex) => {
                let cell = ws[XLSX.utils.encode_cell({ r: rowIndex + 1, c: severityColumn })];
                let style = severityStyles[row['ความรุนแรง']];
                if (cell && style) cell.s = style;
            });
            ws['!autofilter'] = {
                ref: XLSX.utils.encode_range({
                    s: { r: 0, c: severityColumn },
                    e: { r: data.length, c: severityColumn }
                })
            };
        }

        let wscols = [
            {wch: 8}, {wch: 18}, {wch: 12}, {wch: 25}, {wch: 18}, {wch: 20}, {wch: 12}, 
            {wch: 28}, {wch: 28}, {wch: 20}, {wch: 35}, {wch: 15}, {wch: 15}, {wch: 15}, 
            {wch: 20}, {wch: 20}, {wch: 20}, {wch: 35}, {wch: 15}
        ];
        ws['!cols'] = wscols;

        XLSX.utils.book_append_sheet(wb, ws, "Executive Summary");

        let d = new Date();
        let dateStr = d.getFullYear() + "-" + String(d.getMonth()+1).padStart(2,'0') + "-" + String(d.getDate()).padStart(2,'0');
        let fileName = "PCT_ExecutiveReport_" + title.replace(/[^a-zA-Z0-9ก-๙_]/g, '') + "_" + dateStr + ".xlsx";

        XLSX.writeFile(wb, fileName);
    }

    // ==========================================
    // ระบบกรองข้อมูล (อัปเดตใหม่รองรับ Pagination)
    // ==========================================
    function applyPCTFilters(targetId) {
        let table = document.getElementById(targetId);
        if (!table) return;
        let wrapper = table.closest('.data-card');
        
        let searchInput = wrapper.querySelector('.table-search');
        let ptypeSelect = wrapper.querySelector('.filter-ptype');
        let dxSelect = wrapper.querySelector('.filter-dx');
        let sevSelect = wrapper.querySelector('.filter-sev');

        let searchText = searchInput ? searchInput.value.toLowerCase() : '';
        let ptypeVal = ptypeSelect ? ptypeSelect.value.toLowerCase() : '';
        let dxVal = dxSelect ? dxSelect.value.toLowerCase() : '';
        let sevVal = sevSelect ? sevSelect.value.toLowerCase() : '';

        let rows = table.querySelectorAll('tbody tr');
        let heatList = ["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat"];
        
        rows.forEach(row => {
            if(row.querySelector('td[colspan]')) return; 

            let textContent = row.innerText.toLowerCase();
            let rowPtype = (row.getAttribute('data-ptype') || '').toLowerCase();
            let rowDx = (row.getAttribute('data-dx') || '').toLowerCase();
            let rowSev = (row.getAttribute('data-sev') || '').toLowerCase();

            let matchText = (searchText === '' || textContent.includes(searchText));
            let matchPtype = (ptypeVal === '' || rowPtype === ptypeVal);
            let rowFstSev = (row.getAttribute('data-fstsev') || '').toLowerCase();
            let rowFst = (row.getAttribute('data-fst') || '').toLowerCase();
            let matchSev = (sevVal === '' || rowSev === sevVal || rowFstSev === sevVal);

            let isOtherHeat = !heatList.some(h => rowDx.includes(h.toLowerCase())) && rowDx !== '-';
            let isOtherHeatFst = !heatList.some(h => rowFst.includes(h.toLowerCase())) && rowFst !== '-';
            let matchDx = false;
            if (dxVal === '') {
                matchDx = true;
            } else if (dxVal === 'อื่นๆ') {
                matchDx = isOtherHeat || isOtherHeatFst;
            } else {
                matchDx = rowDx.includes(dxVal) || rowFst.includes(dxVal);
            }

            // ซ่อนหรือแสดงผล จะให้ Pagination จัดการ จึงเก็บสถานะผ่าน dataset ไว้
            if (matchText && matchPtype && matchDx && matchSev) {
                row.dataset.matchFilter = 'true';
            } else {
                row.dataset.matchFilter = 'false';
            }
        });

        // หลังจากกรอง ให้กลับไปหน้า 1 และคำนวณ Pagination ใหม่
        if (tablePagination[targetId]) {
            tablePagination[targetId].currentPage = 1;
        }
        paginateTable(targetId);
    }

    // ==========================================
    // Event Listener การทำงานเริ่มต้น
    // ==========================================
    document.addEventListener("DOMContentLoaded", () => {
        // เริ่มต้นระบบ Pagination ทันทีเมื่อโหลดหน้าเว็บ
        initTablePagination();

        const thaiMonthNames = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        [
            ['filter_start_date', 'filter_start_date_display'],
            ['filter_end_date', 'filter_end_date_display']
        ].forEach(([dateId, displayId]) => {
            const dateInput = document.getElementById(dateId);
            const displayInput = document.getElementById(displayId);
            if (!dateInput || !displayInput) return;

            dateInput.addEventListener('change', () => {
                if (!dateInput.value) {
                    displayInput.value = '';
                    return;
                }
                const [year, month, day] = dateInput.value.split('-').map(Number);
                displayInput.value = `${String(day).padStart(2, '0')} ${thaiMonthNames[month - 1]} ${year + 543}`;
            });
        });

        // โค้ด Animation เคาน์เตอร์
        const counters = document.querySelectorAll('.counter-val');
        const speed = 100;
        counters.forEach(counter => {
            const target = +counter.innerText.replace(/,/g, '');
            counter.setAttribute('data-count', '0');
            counter.innerText = '0';
            const updateCount = () => {
                const count = +counter.getAttribute('data-count');
                const inc = target / speed;
                if (count < target) {
                    let next = count + inc;
                    if (next > target) next = target;
                    counter.setAttribute('data-count', next);
                    counter.innerText = Math.floor(next).toLocaleString();
                    setTimeout(updateCount, 15);
                } else {
                    counter.innerText = target.toLocaleString();
                }
            };
            setTimeout(updateCount, 300);
        });
    });

    document.querySelectorAll('.table-search').forEach(el => {
        el.addEventListener('input', function() { applyPCTFilters(this.getAttribute('data-target')); });
    });
    document.querySelectorAll('.filter-ptype, .filter-dx, .filter-sev').forEach(el => {
        el.addEventListener('change', function() { applyPCTFilters(this.getAttribute('data-target')); });
    });

    <?php if(!$is_viewer): ?>
    function openFixRouteModal(id, name, ward) { document.getElementById('fix_ward_id').value = id; document.getElementById('fix_patient_name').innerText = name; document.getElementById('fix_current_ward').innerText = ward; new bootstrap.Modal(document.getElementById('fixRouteModal')).show(); }
    function openCloseCaseModal(id, name) { document.getElementById('close_case_id').value = id; document.getElementById('close_patient_name').innerText = name; new bootstrap.Modal(document.getElementById('closeCaseModal')).show(); }
    function openReopenModal(id, name) { document.getElementById('reopen_case_id').value = id; document.getElementById('reopen_patient_name').innerText = name; new bootstrap.Modal(document.getElementById('reopenModal')).show(); }

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-pct-action]');
        if (!button) return;

        const data = button.dataset;
        if (data.pctAction === 'fix-route') {
            openFixRouteModal(data.wardId, data.patientName, data.wardType);
        } else if (data.pctAction === 'close-case') {
            openCloseCaseModal(data.caseId, data.patientName);
        } else if (data.pctAction === 'reopen-case') {
            openReopenModal(data.caseId, data.patientName);
        } else if (data.pctAction === 'delete-er-waiting') {
            openDeleteErWaiting(data.rowId, data.patientName);
        }
    });
    <?php endif; ?>

    Chart.defaults.font.family = 'Sarabun';
    Chart.defaults.color = '#64748b';
    
    const isMobileChart = window.innerWidth <= 768;
    const legendPosition = isMobileChart ? 'bottom' : 'right';
    const legendPadding = isMobileChart ? 10 : 15;
    
    // 💡 จัดรูปแบบกราฟแท่งใหม่ 10 แท่ง (Premium Dark Pastel Palette)
    const heatLabelsFull = <?= json_encode($heat_keywords_full) ?>;
    const heatColorsFull = {
        'Heat Stroke': '#fc1717', 'Heat Exhaustion': '#fc1717', 'Rhabdomyolysis (CPK > 1,000)': '#fc1717', 'Rhabdomyolysis (CPK < 1,000)': '#ffc107',
        'Heat Tetany': '#ffc107', 'Heat Cramps': '#ffc107', 'Heat Syncope': '#ffc107',
        'Heat Edema': '#ffc107', 'Prickly Heat': '#ffc107', 'อื่นๆ': '#10b981'
    };

    function hexToRgbaJS(hex, opacity) {
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        let r = parseInt(hex.substring(0,2), 16), g = parseInt(hex.substring(2,4), 16), b = parseInt(hex.substring(4,6), 16);
        return `rgba(${r},${g},${b},${opacity})`;
    }

    function openHeatDiseasePatients(disease, patients) {
        const modalElement = document.getElementById('heatDiseasePatientsModal');
        const tbody = document.getElementById('heatDiseasePatientsTbody');
        const title = document.getElementById('heatDiseasePatientsDisease');
        const count = document.getElementById('heatDiseasePatientsCount');

        title.textContent = disease;
        count.textContent = `${patients.length} ราย`;
        tbody.replaceChildren();

        if (patients.length === 0) {
            const row = tbody.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 6;
            cell.className = 'text-center py-4 text-muted';
            cell.textContent = 'ไม่พบรายชื่อผู้ป่วย';
        } else {
            patients.forEach((patient, index) => {
                const row = tbody.insertRow();
                [
                    index + 1,
                    patient.hn || '-',
                    patient.patient_name || '-',
                    patient.person_type || '-',
                    patient.diagnosis || '-'
                ].forEach(value => {
                    row.insertCell().textContent = value;
                });
                const dateCell = row.insertCell();
                const historyLink = document.createElement('a');
                historyLink.href = `../view_patient_full_history.php?hn=${encodeURIComponent(patient.hn || '')}`;
                historyLink.innerHTML = '<button class="btn-action btn-view shadow-sm"><i class="fa-solid fa-folder-open"></i> เปิดแฟ้ม</button>' || '-';
                dateCell.appendChild(historyLink);
            });
        }

        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    // --- กราฟ Heat Type (Bar) ---
    document.querySelectorAll('.chart-heat-type').forEach(canvas => {
        let rawData = JSON.parse(canvas.getAttribute('data-chart'));
        let patientsByDisease = JSON.parse(canvas.getAttribute('data-patients') || '{}');
        let dataArr = [];
        let bgColors = [];
        heatLabelsFull.forEach(dx => {
            dataArr.push(rawData[dx] || 0);
            bgColors.push(heatColorsFull[dx] || '#8E9DAE');
        });

        canvas.style.cursor = 'pointer';
        new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { 
                labels: heatLabelsFull, 
                datasets: [{
                    label: 'จำนวนผู้ป่วย (ราย)',
                    data: dataArr,
                    backgroundColor: bgColors,
                    borderRadius: 4,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                onClick: (event, activeElements, chart) => {
                    if (activeElements.length === 0) return;
                    const disease = chart.data.labels[activeElements[0].index];
                    openHeatDiseasePatients(disease, patientsByDisease[disease] || []);
                },
                onHover: (event, activeElements) => {
                    event.native.target.style.cursor = activeElements.length ? 'pointer' : 'default';
                },
                plugins: { 
                    legend: { display: false },
                    tooltip: { titleFont: { family: 'Prompt', size: 14 }, bodyFont: { family: 'Sarabun', size: 13 }, backgroundColor: 'rgba(255, 255, 255, 0.95)', titleColor: '#1e293b', bodyColor: '#475569', borderColor: '#e2e8f0', borderWidth: 1, padding: 12, cornerRadius: 12, boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)' }
                },
                scales: { 
                    x: { grid: { display: false }, ticks: { font: { family: 'Sarabun', size: isMobileChart ? 9 : 11 }, color: '#1e293b', maxRotation: 45, minRotation: 45 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, border: { display: false }, grid: { color: '#f1f5f9', drawTicks: false } }
                }
            }
        });
    });

    // --- กราฟ Month (Line) ---
    document.querySelectorAll('.chart-heat-month').forEach(canvas => {
        let ctx = canvas.getContext('2d');
        let rawData = JSON.parse(canvas.getAttribute('data-chart'));
        let datasets = [];
        const heatKeywords = ["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"];
        
        let totalByMonth = [];
        for (const m of <?= json_encode($fiscal_month_numbers) ?>) {
            let sum = 0;
            heatKeywords.forEach(dx => {
                sum += (rawData[dx]?.[m] || 0);
            });
            totalByMonth.push(sum);
        }

        let colorHex = '#e11d48'; // ใช้สีแดงเข้มเป็นตัวแทนยอดรวม
        let gradient = ctx.createLinearGradient(0, 0, 0, 350);
        gradient.addColorStop(0, hexToRgbaJS(colorHex, 0.4));
        gradient.addColorStop(1, hexToRgbaJS(colorHex, 0.02));

        datasets.push({
            label: 'ผู้ป่วยโรค Heat (รวมทั้งหมด)', 
            data: totalByMonth,
            borderColor: colorHex, 
            backgroundColor: gradient,
            borderWidth: 3, 
            pointRadius: 4, 
            pointBackgroundColor: '#fff', 
            pointBorderColor: colorHex, 
            pointBorderWidth: 2, 
            pointHoverRadius: 6,
            tension: 0.4, 
            fill: true
        });

        new Chart(ctx, {
            type: 'line',
            data: { labels: <?= json_encode($months_labels) ?>, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { 
                    legend: { position: legendPosition, labels: {font:{family:'Sarabun', size:12}, boxWidth: 12, padding: legendPadding, usePointStyle: true, pointStyle: 'circle'} },
                    tooltip: { titleFont: { family: 'Prompt', size: 14 }, bodyFont: { family: 'Sarabun', size: 13 }, backgroundColor: 'rgba(255, 255, 255, 0.95)', titleColor: '#1e293b', bodyColor: '#475569', borderColor: '#e2e8f0', borderWidth: 1, padding: 12, cornerRadius: 12, boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)' }
                },
                scales: { 
                    x: { grid: { display: false }, ticks: { font: { family: 'Sarabun', size: isMobileChart ? 10 : 12 }, color: '#64748b' } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, border: { display: false }, grid: { color: '#f1f5f9', drawTicks: false } }
                }
            }
        });
    });

    // --- กราฟ Person Type (Doughnut) ใหม่ ---
    document.querySelectorAll('.chart-person-type').forEach(canvas => {
        let personDataJS = JSON.parse(canvas.getAttribute('data-person'));
        
        const personTypeLabels = personDataJS.map(d => `${d.type} (${d.count} คน)`);
        const personTypeCounts = personDataJS.map(d => d.count);
        const personTypeColors = personDataJS.map(d => d.chartColor);
        
        const totalPersons = personTypeCounts.reduce((a, b) => a + b, 0);

        let chartData = personTypeCounts;
        let chartBg = personTypeColors;
        let chartLabels = personTypeLabels;

        // ถ้าไม่มีข้อมูลเลย ให้แสดงกราฟสีเทา
        if (totalPersons === 0) {
            chartData = [1];
            chartBg = ['#e2e8f0'];
            chartLabels = ['ไม่มีข้อมูลผู้ป่วย'];
        }

        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: chartLabels,
                datasets: [{ 
                    data: chartData, 
                    backgroundColor: chartBg, 
                    borderWidth: 3, 
                    borderColor: '#ffffff', 
                    hoverOffset: 5 
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { 
                    // ซ่อน Legend พื้นฐาน เพราะใช้การ์ดด้านล่างเป็น Legend แล้ว
                    legend: { display: false },
                    tooltip: { 
                        titleFont: { family: 'Prompt', size: 14 }, 
                        bodyFont: { family: 'Sarabun', size: 13 }, 
                        backgroundColor: 'rgba(10, 9, 9, 0.95)',
                        callbacks: {
                            label: function(context) {
                                if (totalPersons === 0) return ' 0 คน';
                                return ` ${context.raw} คน`;
                            }
                        }
                    }
                },
                cutout: '65%'
            }
        });
    });

</script>
<div class="modal fade" id="heatDiseasePatientsModal" tabindex="-1" aria-labelledby="heatDiseasePatientsTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-header" style="background: #eff6ff; border-bottom: 1px solid #bfdbfe; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                <h5 class="modal-title fw-bold font-head text-primary" id="heatDiseasePatientsTitle">
                    รายชื่อผู้ป่วย: <span id="heatDiseasePatientsDisease"></span>
                    <span class="badge bg-primary ms-2" id="heatDiseasePatientsCount">0 ราย</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4 py-3">#</th>
                                <th class="px-3 py-3">HN</th>
                                <th class="px-3 py-3">ชื่อ-นามสกุล</th>
                                <th class="px-3 py-3">ประเภทกำลังพล</th>
                                <th class="px-3 py-3">การวินิจฉัย</th>
                                <th class="px-3 py-3">เปิดแฟ้ม</th>
                            </tr>
                        </thead>
                        <tbody id="heatDiseasePatientsTbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light" style="border-radius: 0 0 24px 24px;">
                <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>
<!-- Modal: รายชื่อผู้ป่วยรอ ER รับเข้า -->
<div class="modal fade" id="erWaitingModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca; border-radius: 24px 24px 0 0; padding: 20px 25px;">
                <h5 class="modal-title fw-bold font-head" style="color: #e11d48;">
                    <i class="fa-solid fa-truck-medical me-2"></i> รายชื่อผู้ป่วยรอ ER รับเข้า
                    <span class="badge ms-2" style="background:#e11d48; color:#fff;"><?php echo count($er_waiting_list); ?> ราย</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Search bar -->
                <div class="px-4 pt-3 pb-2">
                    <input type="text" id="erWaitingSearch" class="form-control" placeholder="🔍 ค้นหา HN, ชื่อ, เลขบัตร, หน่วยงาน..." onkeyup="filterErWaitingTable()">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="erWaitingTable" style="font-size:0.9rem;">
                        <thead style="position:sticky; top:0; z-index:5; background:#f8fafc;">
                            <tr>
                                <th class="px-4 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">#</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">HN</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">เลขประจำตัว</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">ชื่อ-นามสกุล</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">หน่วยที่ฝึก</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">วันที่มีอาการ</th>
                                <th class="px-3 py-3 text-muted" style="font-size:0.8rem; font-weight:700; white-space:nowrap;">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="erWaitingTbody">
                        <?php if (empty($er_waiting_list)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-truck-medical fa-3x mb-3 d-block" style="color:#fecaca;"></i>
                                    ไม่มีผู้ป่วยรอ ER รับเข้าในขณะนี้
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($er_waiting_list as $i => $ew): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td class="px-4 py-3 text-muted fw-bold"><?= $i + 1 ?></td>
                                <td class="px-3 py-3">
                                    <span class="hn-chip"><?= htmlspecialchars($ew['hn'] ?? '-') ?></span>
                                </td>
                                <td class="px-3 py-3 small text-muted" style="white-space:nowrap;">
                                    <?= htmlspecialchars($ew['person_ID'] ?? '-') ?>
                                </td>
                                <td class="px-3 py-3 fw-bold text-dark">
                                    <?= htmlspecialchars($ew['Fname'] ?? '-') ?>
                                </td>
                                <td class="px-3 py-3 small text-muted">
                                    <?= htmlspecialchars($ew['work_located'] ?? '-') ?>
                                </td>
                                <td class="px-3 py-3 small text-muted" style="white-space:nowrap;">
                                    <?php
                                        $dd = $ew['data_date'] ?? '';
                                        echo $dd ? date('d/m/Y : H:i', strtotime($dd)) : '-';
                                    ?>
                                </td>
                                <td class="px-3 py-3 small">
                                    <button class="btn btn-sm btn-outline-danger"
                                            data-pct-action="delete-er-waiting"
                                            data-row-id="<?= (int)$ew['row_id'] ?>"
                                            data-patient-name="<?= pct_escape_html($ew['Fname'] ?? '-') ?>">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light" style="border-radius: 0 0 24px 24px;">
                <button type="button" class="btn btn-view px-4 rounded-pill" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function openErWaitingModal() {
        new bootstrap.Modal(document.getElementById('erWaitingModal')).show();
    }

    function filterErWaitingTable() {
        const input = document.getElementById('erWaitingSearch').value.toLowerCase();
        const rows = document.querySelectorAll('#erWaitingTbody tr');
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(input) ? '' : 'none';
        });
    }

    function openDeleteErWaiting(rowId, patientName) {
        document.getElementById('deleteErRowId').value = rowId;
        document.getElementById('deleteErReason').value = '';
        document.getElementById('deleteErPatientName').textContent = patientName;
        document.getElementById('deleteErReasonError').classList.add('d-none');
        new bootstrap.Modal(document.getElementById('deleteErWaitingModal')).show();
    }

    function submitDeleteErWaiting() {
        const reason = document.getElementById('deleteErReason').value.trim();
        if (!reason) {
            document.getElementById('deleteErReasonError').classList.remove('d-none');
            document.getElementById('deleteErReason').focus();
            return;
        }
        document.getElementById('deleteErReasonError').classList.add('d-none');
        // copy ค่าเหตุผลไปใส่ hidden input ก่อน submit เสมอ
        document.getElementById('deleteErReasonHidden').value = reason;
        document.getElementById('deleteErWaitingForm').submit();
    }
</script>

<!-- Modal: ยืนยันการลบพร้อมกรอกเหตุผล -->
<div class="modal fade" id="deleteErWaitingModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca; border-radius: 18px 18px 0 0;">
                <h5 class="modal-title fw-bold font-head" style="color: #e11d48;">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> ยืนยันการลบรายการ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-1 text-muted small">กำลังลบรายการของ:</p>
                <p class="fw-bold text-dark fs-6 mb-3" id="deleteErPatientName">-</p>
                <label class="form-label fw-bold small text-danger">
                    <i class="fa-solid fa-comment-dots me-1"></i> เหตุผลในการลบ <span class="text-danger">*</span>
                </label>
                <textarea id="deleteErReason"
                          class="form-control"
                          rows="3"
                          placeholder="ระบุเหตุผล เช่น เคสที่ไม่ใช่ Heat, เป็นเคสของ GP..."></textarea>
                <div id="deleteErReasonError" class="text-danger small mt-1 d-none">
                    <i class="fa-solid fa-circle-exclamation me-1"></i> กรุณาระบุเหตุผลก่อนลบ
                </div>
            </div>
            <div class="modal-footer bg-light" style="border-radius: 0 0 18px 18px;">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> ยกเลิก
                </button>
                <button type="button" class="btn btn-danger px-4" onclick="submitDeleteErWaiting()">
                    <i class="fa-solid fa-trash me-1"></i> ยืนยันลบ
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Form ซ่อนสำหรับส่ง POST -->
<form id="deleteErWaitingForm" method="POST">
    <input type="hidden" name="csrf_token" value="<?= pct_escape_html($csrf_token) ?>">
    <input type="hidden" name="pct_action" value="delete_er_waiting">
    <input type="hidden" name="row_id" id="deleteErRowId">
    <input type="hidden" name="delete_reason" id="deleteErReasonHidden">
</form>

<div class="modal fade case-comment-modal" id="caseCommentModal" tabindex="-1" aria-labelledby="caseCommentModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="small opacity-75 mb-1"><i class="fa-solid fa-notes-medical me-1"></i> บันทึกประจำเคส</div>
                    <h5 class="modal-title fw-bold mb-0" id="caseCommentModalTitle">Note</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="rounded-3 bg-light border p-3 mb-3">
                    <div class="text-muted small">ผู้ป่วย</div>
                    <div class="fw-bold text-dark" id="caseCommentPatient">-</div>
                </div>
                <label for="caseCommentText" class="form-label fw-bold text-dark">รายละเอียดบันทึก</label>
                <textarea class="form-control comment-field" id="caseCommentText" placeholder="พิมพ์บันทึกเพิ่มเติมสำหรับเคสนี้..." disabled></textarea>
                <div class="small text-muted mt-2" id="caseCommentHint"><?= $is_viewer ? 'โหมด Viewer สามารถดูบันทึกได้เท่านั้น' : 'บันทึกนี้จะแสดงให้ผู้มีสิทธิ์ดูข้อมูลเคสเห็น' ?></div>
                <div class="alert alert-danger mt-3 mb-0 d-none" id="caseCommentError" role="alert"></div>
            </div>
            <div class="modal-footer border-0 bg-light px-4 py-3">
                <button type="button" class="btn btn-light border rounded-pill px-4" data-bs-dismiss="modal">ปิด</button>
                <?php if (!$is_viewer): ?>
                    <button type="button" class="btn btn-primary rounded-pill px-4" id="saveCaseComment" disabled>
                        <i class="fa-solid fa-floppy-disk me-1"></i> บันทึก Note
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const modalElement = document.getElementById('caseCommentModal');
        const commentModal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const commentField = document.getElementById('caseCommentText');
        const patientField = document.getElementById('caseCommentPatient');
        const title = document.getElementById('caseCommentModalTitle');
        const errorElement = document.getElementById('caseCommentError');
        const saveButton = document.getElementById('saveCaseComment');
        const csrfToken = <?= json_encode($csrf_token) ?>;
        let activeCaseId = null;

        function showCommentError(message) {
            errorElement.textContent = message;
            errorElement.classList.remove('d-none');
        }

        async function readResponse(response) {
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่');
            }
            return result;
        }

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('.js-case-comment');
            if (!button) return;

            activeCaseId = button.dataset.caseId;
            patientField.textContent = button.dataset.patientName || '-';
            title.textContent = saveButton
                ? (button.dataset.hasComment === '1' ? 'ดู / แก้ไข Note' : 'เพิ่ม Note')
                : 'ดู Note';
            commentField.value = '';
            commentField.disabled = true;
            errorElement.classList.add('d-none');
            if (saveButton) saveButton.disabled = true;
            commentModal.show();
            const requestedCaseId = activeCaseId;

            try {
                const response = await fetch('case_comment.php?case_id=' + encodeURIComponent(requestedCaseId), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                });
                const result = await readResponse(response);
                if (activeCaseId !== requestedCaseId) return;
                commentField.value = result.comment;
                commentField.disabled = <?= $is_viewer ? 'true' : 'false' ?>;
                if (saveButton) saveButton.disabled = false;
            } catch (error) {
                if (activeCaseId === requestedCaseId) showCommentError(error.message);
            }
        });

        if (saveButton) {
            saveButton.addEventListener('click', async () => {
                if (!activeCaseId) return;
                const savedCaseId = activeCaseId;
                const savedComment = commentField.value;
                saveButton.disabled = true;
                errorElement.classList.add('d-none');

                const payload = new FormData();
                payload.append('case_id', savedCaseId);
                payload.append('comment', savedComment);
                payload.append('csrf_token', csrfToken);

                try {
                    const response = await fetch('case_comment.php', {
                        method: 'POST',
                        body: payload,
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });
                    await readResponse(response);
                    document.querySelectorAll('.js-case-comment').forEach((button) => {
                        if (button.dataset.caseId !== savedCaseId) return;
                        button.dataset.hasComment = savedComment.trim() ? '1' : '0';
                        button.classList.toggle('has-comment', Boolean(savedComment.trim()));
                        button.innerHTML = '<i class="fa-solid fa-sticky-note"></i> ' + (savedComment.trim() ? 'ดูบันทึก' : 'เพิ่ม Note');
                    });
                    if (activeCaseId === savedCaseId) {
                        commentModal.hide();
                        Swal.fire({ icon: 'success', title: 'บันทึก Note เรียบร้อยแล้ว', timer: 1600, showConfirmButton: false });
                    }
                } catch (error) {
                    if (activeCaseId === savedCaseId) showCommentError(error.message);
                } finally {
                    saveButton.disabled = false;
                }
            });
        }
    })();
</script>

</body>
</html>