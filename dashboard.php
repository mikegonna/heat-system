<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once 'database/db_conn.php';

// ---------------------------------------------------------
// 1. Config & Theme
// ---------------------------------------------------------
$current_user_name = strtolower($_SESSION['name']); 
$current_user_role = $_SESSION['role'] ?? 'user';

$is_er = ($current_user_name === 'er');
$is_gp = ($current_user_name === 'gp');
$is_med = ($current_user_name === 'med');
$is_pmr = ($current_user_name === 'pmr');
$is_pcu   = ($current_user_name === 'pcu');
$is_coc   = ($current_user_name === 'coc');
$is_visit = ($is_pcu || $is_coc);
$is_ward = (!$is_er && !$is_gp && !$is_med && !$is_pmr && !$is_visit && $current_user_role !== 'admin' && $current_user_name !== 'pct' && $current_user_name !== 'view');

// ตั้งค่า Theme พื้นฐาน
$theme_color = '#327c62'; $theme_bg = '#f4fbf7'; $theme_light = '#e8f6ef'; $header_file = 'header_ward.php'; $main_link = 'Ward/indexWard.php'; $title_text = 'หอผู้ป่วย ('.strtoupper($current_user_name).')';

if ($is_er) { 
    $theme_color = '#059669'; $theme_bg = '#ecfdf5'; $theme_light = '#d1fae5'; $header_file = 'header_er.php'; $main_link = 'ER/indexER.php'; $title_text = 'ห้องฉุกเฉิน (ER)'; 
}elseif ($is_gp) { 
    $theme_color = '#059669'; $theme_bg = '#ecfdf5'; $theme_light = '#d1fae5'; $header_file = 'header_gp.php'; $main_link = 'GP/indexER.php'; $title_text = 'ห้องตรวจโรคทั่วไป'; 
}elseif ($is_med) { 
    $theme_color = '#0284c7'; $theme_bg = '#f0f9ff'; $theme_light = '#e0f2fe'; $header_file = 'header_followup.php'; $main_link = 'MED/indexMED.php'; $title_text = 'ระบบติดตามอาการ (MED)'; 
} elseif ($is_pmr) { 
    $theme_color = '#8b5cf6'; $theme_bg = '#f5f3ff'; $theme_light = '#ede9fe'; $header_file = 'header_rehab.php'; $main_link = 'PMR/indexPMR.php'; $title_text = 'เวชศาสตร์ฟื้นฟู (PMR)'; 
} elseif ($is_pcu) { 
    $theme_color = '#d6728d'; $theme_bg = '#fdf5f7'; $theme_light = '#fae8ee'; $header_file = 'header_visit.php'; $main_link = 'Visit/formVisit.php'; $title_text = 'ระบบเยี่ยมบ้าน (PCU)'; 
} elseif ($is_coc) { 
    $theme_color = '#d6728d'; $theme_bg = '#fdf5f7'; $theme_light = '#fae8ee'; $header_file = 'header_visit.php'; $main_link = 'Visit/formVisit.php'; $title_text = 'ระบบเยี่ยมบ้าน (COC)'; 
} elseif ($current_user_name === 'icu') {
    $theme_color = '#fb923c'; $theme_bg = '#fff7ed'; $theme_light = '#ffedd5'; $header_file = 'header_icu.php'; $main_link = 'Ward/indexICU.php'; $title_text = 'หอผู้ป่วยวิกฤต (ICU)';
} elseif ($current_user_name === 'pct') {
    $theme_color = '#1d4ed8'; $theme_bg = '#eff6ff'; $theme_light = '#dbeafe'; $header_file = 'header_ward.php'; $main_link = 'Ward/indexWard.php'; $title_text = 'PCT';
}

// ---------------------------------------------------------
// 2. Pagination & Data Initialization
// ---------------------------------------------------------
$limit = 5;
$page_wait = isset($_GET['page_wait']) ? max(1, (int)$_GET['page_wait']) : 1;
$page_hist = isset($_GET['page_hist']) ? max(1, (int)$_GET['page_hist']) : 1;
$offset_wait = ($page_wait - 1) * $limit;
$offset_hist = ($page_hist - 1) * $limit;

function getPageLink($key, $val) {
    $params = $_GET;
    $params[$key] = $val;
    return '?' . http_build_query($params);
}

/**
 * render วินิจฉัยแรกรับ / สุดท้าย แบบ st-badge เหมือน final_status_display
 * Severe   → พื้นแดงอ่อน (#fef2f2) ตัวหนังสือแดงเข้ม
 * Moderate → พื้นเหลืองอ่อน (#fffbeb) ตัวหนังสือน้ำตาล
 * อื่นๆ    → พื้นเทาอ่อน (#f8fafc) ตัวหนังสือเทา
 */
function getDxSeverityBadge($dx_text) {
    if (empty($dx_text) || $dx_text === '-') {
        return '<span class="text-muted small">-</span>';
    }
    $dx_safe = htmlspecialchars($dx_text);
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

$total_wait = 0; $total_hist = 0;
// ใช้ช่วงปีงบประมาณเดียวกับหน้า PCT: 1 ต.ค. ถึง 30 ก.ย. ของปีถัดไป
$default_fiscal_start_year = (int)date('Y');
$default_start_date = sprintf('%04d-10-01', $default_fiscal_start_year);
$default_end_date = sprintf('%04d-09-30', $default_fiscal_start_year + 1);

function sanitize_date($value, string $fallback): string {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
        return $fallback;
    }

    return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
        ? $value
        : $fallback;
}

$start_date = array_key_exists('start_date', $_GET)
    ? sanitize_date($_GET['start_date'], $default_start_date)
    : $default_start_date;
$end_date = array_key_exists('end_date', $_GET)
    ? sanitize_date($_GET['end_date'], $default_end_date)
    : $default_end_date;

$severityCounts = ['Severe' => 0, 'Moderate' => 0, 'Other' => 0];
$severityDetails = ['Severe' => [], 'Moderate' => [], 'Other' => []];
$personTypeCounts = [];
$waitlist = []; 
$history_list = []; 

// Data สำหรับกราฟสไตล์ PCT
$heat_keywords_full = ["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"];
$stats_heat_by_type = array_fill_keys($heat_keywords_full, 0);
$stats_heat_by_month = [];
foreach($heat_keywords_full as $k) { $stats_heat_by_month[$k] = array_fill(1, 12, 0); }

$fiscal_start_timestamp = strtotime($start_date);
$fiscal_start_year = (int)date('Y', $fiscal_start_timestamp);
if ((int)date('n', $fiscal_start_timestamp) < 10) {
    $fiscal_start_year--;
}
$fiscal_year_label = $fiscal_start_year + 544;
$months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
$fiscal_month_numbers = array_merge(range(10, 12), range(1, 9));
$months_labels = [];
foreach ($fiscal_month_numbers as $month_number) {
    $month_year = $fiscal_start_year + ($month_number < 10 ? 1 : 0);
    $months_labels[] = $months[$month_number - 1] . ' ' . ($month_year + 543);
}

// ===================================================================================
// 🟢 ER Logic
// ===================================================================================
if ($is_er) {
    $allWaitlist = [];
    $total_wait = 0; $kpi_wait = 0; $waitlist = [];

    $sqlimport = "SELECT * FROM data_import WHERE `status` = 0";
    $resultimport = $conn->query($sqlimport);

    if ($resultimport) {
        while ($row = $resultimport->fetch_assoc()) {
            $waitlist[] = $row;
        }
    }

    $total_wait = count($waitlist);
    $kpi_wait = $total_wait;

    $sqlPerson = "SELECT c.person_type, COUNT(DISTINCT e.case_id) as count FROM er_details e JOIN cases c ON e.case_id = c.id WHERE DATE(e.hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date' GROUP BY c.person_type";
    $resPerson = $conn->query($sqlPerson);
    if($resPerson) while ($r = $resPerson->fetch_assoc()) $personTypeCounts[!empty($r['person_type']) ? $r['person_type'] : 'ไม่ระบุ'] = $r['count'];

        $sqlSev = "
            SELECT e.FstDx, e.hospital_arrival_datetime as pt_date
            FROM er_details e
            JOIN cases c ON e.case_id = c.id
            JOIN (
                SELECT e_sub.case_id, MAX(e_sub.id) as max_id 
                FROM er_details e_sub 
                GROUP BY e_sub.case_id
            ) latest ON e.id = latest.max_id
            WHERE DATE(e.hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date'
        ";
    $resSev = $conn->query($sqlSev);
    
    // 💡 SAFEGUARD: ป้องกัน 500 Error
    $q_kpi_total = $conn->query("SELECT COUNT(*) as c FROM er_details WHERE DATE(hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date'");
    $kpi_total = $q_kpi_total ? $q_kpi_total->fetch_assoc()['c'] : 0;
    
    $kpi_admit = 0; $kpi_dc = 0;
    
    if($resSev) {
        while($row = $resSev->fetch_assoc()) {
            $dx = trim($row['FstDx'] ?? ''); if(empty($dx) || $dx == '-') continue;
            
            // จัดกลุ่ม Severity
            if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis\s*\(CPK\s*>\s*1,?000\)/i', $dx)) {
                $severityCounts['Severe']++; 
                $severityDetails['Severe'][$dx] = ($severityDetails['Severe'][$dx] ?? 0) + 1;

            } elseif (preg_match('/Rhabdomyolysis\s*\(CPK\s*<\s*1,?000\)|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $dx)) {
                $severityCounts['Moderate']++; 
                $severityDetails['Moderate'][$dx] = ($severityDetails['Moderate'][$dx] ?? 0) + 1;
            } else {
                $severityCounts['Other']++;
                $severityDetails['Other'][$dx] = ($severityDetails['Other'][$dx] ?? 0) + 1;
            }

            // จัดกลุ่มสำหรับกราฟ PCT
            $pt_date = $row['pt_date'] ?? date('Y-m-d');
            $pt_m = (int)date('n', strtotime($pt_date));
            $matched_dx = 'อื่นๆ';
            foreach(["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"] as $k) {
                if(stripos($dx, $k) !== false) { $matched_dx = $k; break; }
            }
            $stats_heat_by_type[$matched_dx] = ($stats_heat_by_type[$matched_dx] ?? 0) + 1;
            $stats_heat_by_month[$matched_dx][$pt_m] = ($stats_heat_by_month[$matched_dx][$pt_m] ?? 0) + 1;
        }
    }
    $resER_KPI = $conn->query("SELECT disposition, COUNT(*) as cnt FROM er_details WHERE DATE(hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date' GROUP BY disposition");
    if($resER_KPI) while($r = $resER_KPI->fetch_assoc()) { if($r['disposition']=='Admit') $kpi_admit=$r['cnt']; if($r['disposition']=='Discharge') $kpi_dc=$r['cnt']; }

    $sqlHistBase = "FROM er_details e JOIN cases c ON e.case_id = c.id WHERE DATE(e.hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date'";
    
    // 💡 SAFEGUARD: ป้องกัน 500 Error
    $qCount = $conn->query("SELECT COUNT(*) as total $sqlHistBase");
    $total_hist = $qCount ? $qCount->fetch_assoc()['total'] : 0;

    $sqlHist = "SELECT e.*, c.* 
                $sqlHistBase 
                ORDER BY e.recorded_at DESC LIMIT $offset_hist, $limit";
    $resHist = $conn->query($sqlHist);
} 
// ===================================================================================
// 🟢 GP Logic
// ===================================================================================
elseif ($is_gp) {
    $allWaitlist = [];
    $total_wait = 0; $kpi_wait = 0; $waitlist = [];

    //ส่วนที่มาจาก สแกนใบหน้า
    $sqlimport = "SELECT * FROM data_import WHERE `status` = 0 ORDER BY data_date DESC";
    $resultimport = $conn->query($sqlimport);

    if ($resultimport) {
        while ($row = $resultimport->fetch_assoc()) {
            $allWaitlist[] = $row;
        }
    }
    //จบส่วนที่มาจากสแกนใบหน้า

    $total_wait = count($allWaitlist);
    $kpi_wait   = $total_wait;
    $waitlist   = array_slice($allWaitlist, $offset_wait, $limit);

    $sqlPerson = "SELECT c.person_type, COUNT(DISTINCT g.case_id) as count FROM gp_details g JOIN cases c ON g.case_id = c.id WHERE DATE(g.hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date' GROUP BY c.person_type";
    $resPerson = $conn->query($sqlPerson);
    if($resPerson) while ($r = $resPerson->fetch_assoc()) $personTypeCounts[!empty($r['person_type']) ? $r['person_type'] : 'ไม่ระบุ'] = $r['count'];

        $sqlSev = "
            SELECT g.FstDx, g.hospital_arrival_datetime as pt_date
            FROM gp_details g
            JOIN cases c ON g.case_id = c.id
            JOIN (
                SELECT g_sub.case_id, MAX(g_sub.id) as max_id 
                FROM gp_details g_sub 
                GROUP BY g_sub.case_id
            ) latest ON g.id = latest.max_id
            WHERE DATE(g.hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date'
        ";
    $resSev = $conn->query($sqlSev);
    
    // 💡 SAFEGUARD: ป้องกัน 500 Error
    $q_kpi_total = $conn->query("SELECT COUNT(*) as c FROM gp_details WHERE DATE(hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date'");
    $kpi_total = $q_kpi_total ? $q_kpi_total->fetch_assoc()['c'] : 0;
    
    $kpi_admit = 0; $kpi_dc = 0;
    
    if($resSev) {
        while($row = $resSev->fetch_assoc()) {
            $dx = trim($row['FstDx'] ?? ''); if(empty($dx) || $dx == '-') continue;
            
            // จัดกลุ่ม Severity
            if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis\s*\(CPK\s*>\s*1,?000\)/i', $dx)) {
                $severityCounts['Severe']++; 
                $severityDetails['Severe'][$dx] = ($severityDetails['Severe'][$dx] ?? 0) + 1;

            } elseif (preg_match('/Rhabdomyolysis\s*\(CPK\s*<\s*1,?000\)|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $dx)) {
                $severityCounts['Moderate']++; 
                $severityDetails['Moderate'][$dx] = ($severityDetails['Moderate'][$dx] ?? 0) + 1;
            } else {
                $severityCounts['Other']++;
                $severityDetails['Other'][$dx] = ($severityDetails['Other'][$dx] ?? 0) + 1;
            }

            // จัดกลุ่มสำหรับกราฟ PCT
            $pt_date = $row['pt_date'] ?? date('Y-m-d');
            $pt_m = (int)date('n', strtotime($pt_date));
            $matched_dx = 'อื่นๆ';
            foreach(["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"] as $k) {
                if(stripos($dx, $k) !== false) { $matched_dx = $k; break; }
            }
            $stats_heat_by_type[$matched_dx] = ($stats_heat_by_type[$matched_dx] ?? 0) + 1;
            $stats_heat_by_month[$matched_dx][$pt_m] = ($stats_heat_by_month[$matched_dx][$pt_m] ?? 0) + 1;
        }
    }
    $resER_KPI = $conn->query("SELECT disposition, COUNT(*) as cnt FROM gp_details WHERE DATE(hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date' GROUP BY disposition");
    if($resER_KPI) while($r = $resER_KPI->fetch_assoc()) { if($r['disposition']=='Admit') $kpi_admit=$r['cnt']; if($r['disposition']=='Discharge') $kpi_dc=$r['cnt']; }

    $sqlHistBase = "FROM gp_details g JOIN cases c ON g.case_id = c.id WHERE DATE(g.hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date'";
    
    // 💡 SAFEGUARD: ป้องกัน 500 Error
    $qCount = $conn->query("SELECT COUNT(*) as total $sqlHistBase");
    $total_hist = $qCount ? $qCount->fetch_assoc()['total'] : 0;

    $sqlHist = "SELECT g.*, c.* 
                $sqlHistBase 
                ORDER BY g.recorded_at DESC LIMIT $offset_hist, $limit";
    $resHist = $conn->query($sqlHist);
}
// ===================================================================================
// 🔵 Follow-up Logic (MED, PMR)
// ===================================================================================
elseif ($is_med || $is_pmr) {
    $target_table = ''; $date_field = 'record_datetime';
    if($is_med) { $target_table = 'med_records'; }
    elseif($is_pmr) { $target_table = 'pmr_records'; }

    if ($is_pmr) {
        $base_cond = "w.status = 'Discharged' AND EXISTS (SELECT 1 FROM med_records m WHERE m.an = w.an AND m.med_status LIKE '%นัดพบแพทย์เวชศาสตร์ฟื้นฟู%')";
    } else {
        $base_cond = "w.status = 'Discharged' AND EXISTS (SELECT 1 FROM ward_progress_notes wpn WHERE wpn.ward_detail_id = w.id AND wpn.treatment_result LIKE 'Discharge%')";
    }

    $sqlWaitBase = "FROM ward_details w JOIN cases c ON w.case_id = c.id LEFT JOIN er_details e ON w.case_id = e.case_id WHERE $base_cond AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date' AND w.an NOT IN (SELECT an FROM $target_table)";
    
    // 💡 SAFEGUARD: ป้องกัน 500 Error
    $qWaitCount = $conn->query("SELECT COUNT(DISTINCT w.id) as c $sqlWaitBase");
    $total_wait = $qWaitCount ? $qWaitCount->fetch_assoc()['c'] : 0;
    $kpi_wait = $total_wait;

    $sqlW = "SELECT w.an, w.discharge_date, c.hn, c.patient_name, c.age, c.person_type, c.department, w.discharge_detail, 
                    w.case_id, e.FstDx as er_dx, e.risk_factors, w.icu_diagnosis, w.final_diagnosis, w.initial_diagnosis
             $sqlWaitBase 
             GROUP BY w.id
             ORDER BY w.discharge_date DESC LIMIT $offset_wait, $limit";
    $resW = $conn->query($sqlW);
    if($resW) while($row = $resW->fetch_assoc()) $waitlist[] = $row;

    $qTracked = $conn->query("SELECT COUNT(DISTINCT r.an) as c FROM $target_table r JOIN ward_details w ON r.an = w.an WHERE DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date'");
    $kpi_tracked = $qTracked ? $qTracked->fetch_assoc()['c'] : 0;
    $kpi_total = $kpi_wait + $kpi_tracked;

    $sqlHistBase = "
        FROM $target_table r 
        JOIN ward_details w ON r.an = w.an 
        JOIN cases c ON w.case_id = c.id 
        LEFT JOIN er_details e ON w.case_id = e.case_id
        JOIN (
            SELECT an, MAX(id) as max_id FROM $target_table GROUP BY an
        ) as latest_r ON r.id = latest_r.max_id
        WHERE DATE(r.$date_field) BETWEEN '$start_date' AND '$end_date'
    ";
    
    // 💡 SAFEGUARD: ป้องกัน 500 Error
    $qHistCount = $conn->query("SELECT COUNT(DISTINCT r.id) as total $sqlHistBase");
    $total_hist = $qHistCount ? $qHistCount->fetch_assoc()['total'] : 0;

    $sqlHist = "
        SELECT r.*, w.an, w.discharge_date, c.hn, c.patient_name, c.age, c.person_type, c.department,
               e.FstDx as er_dx, e.risk_factors,
               w.icu_diagnosis, w.final_diagnosis, w.initial_diagnosis, w.case_id
        $sqlHistBase
        GROUP BY r.id 
        ORDER BY r.$date_field DESC LIMIT $offset_hist, $limit
    ";
    $resHist = $conn->query($sqlHist);

    $sqlPerson = "SELECT c.person_type, COUNT(DISTINCT w.an) as count FROM ward_details w JOIN cases c ON w.case_id = c.id WHERE $base_cond AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date' GROUP BY c.person_type";
    $resPerson = $conn->query($sqlPerson);
    if($resPerson) while ($r = $resPerson->fetch_assoc()) $personTypeCounts[!empty($r['person_type']) ? $r['person_type'] : 'ไม่ระบุ'] = $r['count'];

    $base_cond_sub = str_replace('w.', 'w_sub.', $base_cond);

    $sqlWardBase = "
                    FROM ward_details w
                    JOIN cases c ON w.case_id = c.id
                    WHERE $base_cond
                    AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date'
                ";

    $sqlSev = "
        SELECT COALESCE(
            w.final_diagnosis, 
            w.icu_diagnosis, 
            w.initial_diagnosis
        ) as dx,
        w.admit_date as pt_date
        $sqlWardBase
        AND w.id = (
            SELECT MAX(w_sub.id)
            FROM ward_details w_sub
            WHERE w_sub.an = w.an
            AND $base_cond_sub
        )
    ";
    $resSev = $conn->query($sqlSev);
    if($resSev) {
        while($row = $resSev->fetch_assoc()) {
            $dx = trim($row['dx'] ?? ''); if(empty($dx) || $dx == '-') continue;
            
             // จัดกลุ่ม Severity
            if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \(CPK > 1,000\)/i', $dx)) {
                $severityCounts['Severe']++; 
                $severityDetails['Severe'][$dx] = ($severityDetails['Severe'][$dx] ?? 0) + 1;

            } elseif (preg_match('/Rhabdomyolysis \(CPK < 1,000\)|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $dx)) {
                $severityCounts['Moderate']++; 
                $severityDetails['Moderate'][$dx] = ($severityDetails['Moderate'][$dx] ?? 0) + 1;
            } else {
                $severityCounts['Other']++;
                $severityDetails['Other'][$dx] = ($severityDetails['Other'][$dx] ?? 0) + 1;
            }

            // จัดกลุ่มสำหรับกราฟ PCT
            $pt_date = $row['pt_date'] ?? date('Y-m-d');
            $pt_m = (int)date('n', strtotime($pt_date));
            $matched_dx = 'อื่นๆ';
            foreach(["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"] as $k) {
                if(stripos($dx, $k) !== false) { $matched_dx = $k; break; }
            }
            if ($matched_dx !== '') {
                $stats_heat_by_type[$matched_dx] = ($stats_heat_by_type[$matched_dx] ?? 0) + 1;
                $stats_heat_by_month[$matched_dx][$pt_m] = ($stats_heat_by_month[$matched_dx][$pt_m] ?? 0) + 1;
            }
        }
    }
}
// ===================================================================================
// 🌸 Follow-up Logic (PCU/COC)
// ===================================================================================
elseif ($is_visit) {

    // Base JOIN สำหรับใช้ร่วมกัน (เฉพาะเคสที่จำหน่ายแล้วเท่านั้น)
    $visitJoinBase = "FROM cases c
                    LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
                    LEFT JOIN gp_details gp ON gp.id = (SELECT MAX(id) FROM gp_details WHERE case_id = c.id)
                    LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = c.id)
                    WHERE IFNULL(e.disposition, '') NOT IN ('Dead', 'Refer')
                    AND IFNULL(w.discharge_status, '') NOT IN ('Dead', 'Refer')
                    AND DATE(COALESCE(w.admit_date, e.hospital_arrival_datetime, gp.hospital_arrival_datetime)) BETWEEN '$start_date' AND '$end_date'
                    AND (e.id IS NOT NULL OR w.id IS NOT NULL)
                    AND (
                        (w.id IS NOT NULL AND w.status = 'Discharged')
                        OR (w.id IS NULL AND e.id IS NOT NULL AND e.disposition = 'Discharge')
                    )
                    AND COALESCE(w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis, e.FstDx, gp.FstDx, '')
                    REGEXP 'Heat Stroke|Heat Exhaustion|Heat Syncope|Heat Cramps|Heat Tetany|Heat Edema|Prickly Heat|Rhabdomyolysis'
                    ";

    // Block 2: ยังไม่เคยเยี่ยมเลย (= 0) → ตารางแรก
    // กรอง visit_unit ตาม user (pcu เห็นเฉพาะ PCU, coc เห็นเฉพาะ COC)
    // หรือตาม tab ที่เลือก (เฉพาะ admin/pct ที่เห็นทั้งหมด)
    $visit_tab = $_GET['visit_tab'] ?? 'all';
    $visit_unit_filter = '';
    if ($is_pcu) {
        // PCU user: default เห็น PCU + CQI + เคสเก่าที่ยังไม่ระบุ
        if ($visit_tab === 'pcu') {
            $visit_unit_filter = "AND (COALESCE(w.visit_unit, e.visit_unit, '') IN ('PCU', ''))";
        } elseif ($visit_tab === 'cqi') {
            $visit_unit_filter = "AND COALESCE(w.visit_unit, e.visit_unit, '') = 'CQI'";
        } else {
            // tab 'all' → เห็น PCU, CQI และเคสที่ยังไม่ระบุ visit_unit
            $visit_unit_filter = "AND (COALESCE(w.visit_unit, e.visit_unit, '') IN ('PCU', 'CQI', ''))";
        }
    } elseif ($is_coc) {
        // COC user: เห็น COC และเคสที่ยังไม่ระบุ visit_unit (ไม่มี tab)
        $visit_unit_filter = "AND (COALESCE(w.visit_unit, e.visit_unit, '') IN ('COC', ''))";
    } elseif ($visit_tab === 'pcu') {
        $visit_unit_filter = "AND COALESCE(w.visit_unit, e.visit_unit, '') = 'PCU'";
    } elseif ($visit_tab === 'coc') {
        $visit_unit_filter = "AND COALESCE(w.visit_unit, e.visit_unit, '') = 'COC'";
    } elseif ($visit_tab === 'cqi') {
        $visit_unit_filter = "AND COALESCE(w.visit_unit, e.visit_unit, '') = 'CQI'";
    }

    $sqlWaitlistBase = "$visitJoinBase
                    AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn) = 0
                    $visit_unit_filter";

    $qBlock2 = $conn->query("SELECT COUNT(DISTINCT c.id) as c $sqlWaitlistBase");
    $kpi_wait = $qBlock2 ? (int)$qBlock2->fetch_assoc()['c'] : 0;
    $total_wait = $kpi_wait;

    $sqlW = "SELECT 
               c.id as case_id, c.hn, c.patient_name, c.age, c.phone, c.department, c.person_type,
               e.FstDx as er_dx, e.risk_factors, e.recorded_at as er_time, e.disposition as er_status,
               IFNULL(e.visit_unit, '') as er_visit_unit,
               w.an, w.admit_date, w.discharge_date, w.status as ward_status, w.admit_type as current_ward, w.discharge_status as ward_dc_status,
               w.icu_diagnosis, w.final_diagnosis, w.initial_diagnosis,
               IFNULL(w.visit_unit, '') as ward_visit_unit,
               COALESCE(w.visit_unit, e.visit_unit, '') as visit_unit,
               (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn) as visit_count_er
             $sqlWaitlistBase
             ORDER BY COALESCE(w.discharge_date, w.admit_date, e.recorded_at, c.created_at) DESC 
             LIMIT $offset_wait, $limit";
    $resW = $conn->query($sqlW);
    if($resW) while($row = $resW->fetch_assoc()) $waitlist[] = $row;

    // Block 3: อยู่ระหว่างติดตาม = Severe เยี่ยมแล้ว 1 ครั้ง (ยังเหลืออีก 1)
    $sqlInProgressBase = "$visitJoinBase
                    AND COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                        REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
                    AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn) = 1";
    $qBlock3 = $conn->query("SELECT COUNT(DISTINCT c.id) as c $sqlInProgressBase");
    $kpi_tracked = $qBlock3 ? (int)$qBlock3->fetch_assoc()['c'] : 0;

    // Block 4: สิ้นสุดการติดตาม = Severe ครบ 2 ครั้ง + Moderate ครบ 1 ครั้ง
    $sqlDoneBase = "$visitJoinBase
                    AND (
                        (
                            COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                                REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
                            AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn) >= 2
                        )
                        OR
                        (
                            NOT COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                                REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
                            AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn) >= 1
                        )
                    )";
    $qBlock4 = $conn->query("SELECT COUNT(DISTINCT c.id) as c $sqlDoneBase");
    $kpi_done = $qBlock4 ? (int)$qBlock4->fetch_assoc()['c'] : 0;

    // Block 1: ยอดรวมทั้งหมด
    $kpi_total = $kpi_wait + $kpi_tracked + $kpi_done;

    // sqlWaitBase ยังคงไว้สำหรับ กราฟ/สถิติ
    $sqlWaitBase = $visitJoinBase;
        
    $sqlHistBase = "
                FROM home_visits r 
                JOIN cases c ON r.hn = c.hn 
                LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = c.id)
                LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
                WHERE r.id = (
                    SELECT MAX(r2.id) 
                    FROM home_visits r2 
                    WHERE r2.hn = r.hn
                )
                AND DATE(r.record_datetime) BETWEEN '$start_date' AND '$end_date'
            ";
    
    // 💡 SAFEGUARD: ป้องกัน 500 Error
    $qHistCount = $conn->query("SELECT COUNT(DISTINCT r.id) as total $sqlHistBase");
    $total_hist = ($qHistCount && $qHistCount->num_rows > 0) ? (int)$qHistCount->fetch_assoc()['total'] : 0;

    $sqlHist = "
                SELECT r.*, w.an, w.discharge_date, c.hn, c.patient_name, c.age, 
                    c.person_type, c.department,
                    e.FstDx as er_dx, e.risk_factors,
                    w.icu_diagnosis, w.final_diagnosis, w.initial_diagnosis, c.id as case_id
                $sqlHistBase
                ORDER BY r.record_datetime DESC 
                LIMIT $offset_hist, $limit
            ";
    $resHist = $conn->query($sqlHist);

    $sqlPerson = "SELECT c.person_type, COUNT(DISTINCT c.id) as count $sqlWaitBase GROUP BY c.person_type";
    $resPerson = $conn->query($sqlPerson);
    if($resPerson) while ($r = $resPerson->fetch_assoc()) $personTypeCounts[!empty($r['person_type']) ? $r['person_type'] : 'ไม่ระบุ'] = $r['count'];

    $sqlSev = "SELECT COALESCE(w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis, e.FstDx) as dx, COALESCE(w.discharge_date, w.admit_date, e.recorded_at, c.created_at) as pt_date $sqlWaitBase";
    $resSev = $conn->query($sqlSev);
    if($resSev) {
        while($row = $resSev->fetch_assoc()) {
            $dx = trim(isset($row['dx']) ? $row['dx'] : ''); if(empty($dx) || $dx == '-') continue;
            
            if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \(CPK > 1,000\)/i', $dx)) {
                $severityCounts['Severe']++; 
                $severityDetails['Severe'][$dx] = (isset($severityDetails['Severe'][$dx]) ? $severityDetails['Severe'][$dx] : 0) + 1;

            } elseif (preg_match('/Rhabdomyolysis \(CPK < 1,000\)|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $dx)) {
                $severityCounts['Moderate']++; 
                $severityDetails['Moderate'][$dx] = (isset($severityDetails['Moderate'][$dx]) ? $severityDetails['Moderate'][$dx] : 0) + 1;
            } else {
                $severityCounts['Other']++;
                $severityDetails['Other'][$dx] = (isset($severityDetails['Other'][$dx]) ? $severityDetails['Other'][$dx] : 0) + 1;
            }

            // จัดกลุ่มสำหรับกราฟ PCT
            $pt_date = isset($row['pt_date']) ? $row['pt_date'] : date('Y-m-d');
            $pt_m = (int)date('n', strtotime($pt_date));
            $matched_dx = 'อื่นๆ';
            foreach(["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"] as $k) {
                if(stripos($dx, $k) !== false) { $matched_dx = $k; break; }
            }
            $stats_heat_by_type[$matched_dx] = (isset($stats_heat_by_type[$matched_dx]) ? $stats_heat_by_type[$matched_dx] : 0) + 1;
            $stats_heat_by_month[$matched_dx][$pt_m] = (isset($stats_heat_by_month[$matched_dx][$pt_m]) ? $stats_heat_by_month[$matched_dx][$pt_m] : 0) + 1;
        }
    }
}
// ===================================================================================
// 🟤 Ward & ICU Logic
// ===================================================================================
else {
        $wardFilter = "";
        if ($current_user_role !== 'admin' && $current_user_name !== 'pct') $wardFilter = " AND w.admit_type LIKE '%$current_user_name%' ";
        $erWaitFilter = ($wardFilter !== "") ? " AND e.admit_type LIKE '%$current_user_name%' " : "";

        $sqlKPI = "SELECT SUM(CASE WHEN w.status = 'Active' THEN 1 ELSE 0 END) as active_patients, SUM(CASE WHEN w.status IN ('Discharged', 'Refer', 'Dead') THEN 1 ELSE 0 END) as discharged_patients FROM ward_details w WHERE 1=1 $wardFilter AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date'";
        
        // 💡 SAFEGUARD: ป้องกัน 500 Error หาก Query คืนค่า false
        $q_kpi = $conn->query($sqlKPI);
        $kpi = $q_kpi ? $q_kpi->fetch_assoc() : null;
        $kpi_active = $kpi['active_patients'] ?? 0;
        $kpi_dc = $kpi['discharged_patients'] ?? 0;

        $sqlPerson = "SELECT c.person_type, COUNT(DISTINCT w.case_id) as count FROM ward_details w JOIN cases c ON w.case_id = c.id WHERE 1=1 $wardFilter AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date' GROUP BY c.person_type";
        $resPerson = $conn->query($sqlPerson);
        if($resPerson) while ($r = $resPerson->fetch_assoc()) $personTypeCounts[!empty($r['person_type']) ? $r['person_type'] : 'ไม่ระบุ'] = $r['count'];

        $allWaitlist = [];
        $resWaitER = $conn->query("SELECT c.hn, c.patient_name, e.FstDx as diagnosis, e.recorded_at as wait_time, 'จาก ER' as from_dept, e.case_id FROM er_details e JOIN cases c ON e.case_id = c.id WHERE e.disposition = 'Admit' AND e.FstDx NOT LIKE 'รับย้ายจาก%' AND DATE(e.hospital_arrival_datetime) BETWEEN '$start_date' AND '$end_date' $erWaitFilter AND NOT EXISTS (SELECT 1 FROM ward_details w WHERE w.case_id = c.id)");
        if($resWaitER) while($r = $resWaitER->fetch_assoc()) $allWaitlist[] = $r;
        
        $resWaitTrans = $conn->query("SELECT w.id as ward_id, w.case_id, c.hn, c.patient_name, w.discharge_detail, w.admit_date as wait_time FROM ward_details w JOIN cases c ON w.case_id = c.id WHERE w.status = 'AdmitWait' AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date' $wardFilter");
        if($resWaitTrans) {
            while($r = $resWaitTrans->fetch_assoc()) {
                $prevDx = "";
                $qPrev = $conn->query("SELECT initial_diagnosis, icu_diagnosis, final_diagnosis FROM ward_details WHERE case_id = '{$r['case_id']}' AND id < '{$r['ward_id']}' ORDER BY id DESC LIMIT 1");
                if($qPrev && $qPrev->num_rows > 0) {
                    $prevRow = $qPrev->fetch_assoc();
                    $prevDx = !empty($prevRow['final_diagnosis']) ? $prevRow['final_diagnosis'] : (!empty($prevRow['icu_diagnosis']) ? $prevRow['icu_diagnosis'] : $prevRow['initial_diagnosis']);
                }
                if(empty($prevDx)) {
                    $qER = $conn->query("SELECT FstDx FROM er_details WHERE case_id = '{$r['case_id']}' LIMIT 1");
                    if($qER && $qER->num_rows > 0) { $erRow = $qER->fetch_assoc(); $prevDx = $erRow['FstDx']; }
                }
                $r['diagnosis'] = !empty($prevDx) ? $prevDx : '-';
                $r['from_dept'] = $r['discharge_detail'];
                $allWaitlist[] = $r;
            }
        }
        
        // 💡 SAFEGUARD: ป้องกัน usort ล้มเหลวถ้าเวลาเป็นค่าว่าง
        usort($allWaitlist, function($a, $b) { 
            $timeA = !empty($a['wait_time']) ? strtotime($a['wait_time']) : 0;
            $timeB = !empty($b['wait_time']) ? strtotime($b['wait_time']) : 0;
            return $timeB <=> $timeA; 
        });
        
        $total_wait = count($allWaitlist);
        $kpi_wait = $total_wait;
        $waitlist = array_slice($allWaitlist, $offset_wait, $limit);

        $wardFilterSub = str_replace('w.', 'w_sub.', $wardFilter);

        $sqlHistBase = "FROM ward_details w JOIN cases c ON w.case_id = c.id WHERE 1=1 $wardFilter AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date'";
        
        // 💡 SAFEGUARD: ป้องกัน 500 Error
        $qHistCount = $conn->query("SELECT COUNT(*) as total $sqlHistBase");
        $total_hist = $qHistCount ? $qHistCount->fetch_assoc()['total'] : 0;

        $sqlHist = "SELECT w.*, c.hn, c.patient_name, c.age, c.department, c.person_type 
                    $sqlHistBase 
                    ORDER BY w.admit_date DESC LIMIT $offset_hist, $limit";
        $resHist = $conn->query($sqlHist);

        $sqlSev = "
            SELECT w.status, w.final_diagnosis, w.initial_diagnosis, w.icu_diagnosis, 
                w.admit_date as pt_date
            $sqlHistBase
            AND w.id = (
                SELECT MAX(w_sub.id) 
                FROM ward_details w_sub 
                WHERE w_sub.case_id = w.case_id 
                $wardFilterSub
            )
        ";
        
        $resSev = $conn->query($sqlSev);
        if($resSev) {
            while($row = $resSev->fetch_assoc()) {
                $raw_dx = '';
                if (in_array($row['status'], ['Discharged', 'Refer', 'Dead']) && !empty($row['final_diagnosis'])) {
                    $raw_dx = $row['final_diagnosis'];
                } else {
                    if ($current_user_name === 'icu') {
                        $raw_dx = !empty($row['icu_diagnosis']) ? $row['icu_diagnosis'] : ($row['initial_diagnosis'] ?? '');
                    } else {
                        $raw_dx = $row['initial_diagnosis'] ?? '';
                    }
                }

                $dx = trim($raw_dx);
                if(empty($dx) || $dx == '-') continue;
                
                // จัดกลุ่ม Severity
                if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \(CPK > 1,000\)/i', $dx)) {
                    $severityCounts['Severe']++; 
                    $severityDetails['Severe'][$dx] = ($severityDetails['Severe'][$dx] ?? 0) + 1;

                } elseif (preg_match('/Rhabdomyolysis \(CPK < 1,000\)|Heat Tetany|Heat Cramps|Heat Syncope|Heat Edema|Prickly Heat/i', $dx)) {
                    $severityCounts['Moderate']++; 
                    $severityDetails['Moderate'][$dx] = ($severityDetails['Moderate'][$dx] ?? 0) + 1;
                } else {
                    $severityCounts['Other']++;
                    $severityDetails['Other'][$dx] = ($severityDetails['Other'][$dx] ?? 0) + 1;
                }

                // จัดกลุ่มสำหรับกราฟ PCT
                $pt_date = $row['pt_date'] ?? date('Y-m-d');
                $pt_m = (int)date('n', strtotime($pt_date));
                $matched_dx = 'อื่นๆ';
                foreach(["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"] as $k) {
                    if(stripos($dx, $k) !== false) { $matched_dx = $k; break; }
                }
                if ($matched_dx !== '') {
                    $stats_heat_by_type[$matched_dx] = ($stats_heat_by_type[$matched_dx] ?? 0) + 1;
                    $stats_heat_by_month[$matched_dx][$pt_m] = ($stats_heat_by_month[$matched_dx][$pt_m] ?? 0) + 1;
                }
            }
        }
}

// -----------------------------------------------------------------------------------
// 🏥 Function: สร้าง Timeline (Journey) สำหรับทุกแผนก [แก้ไขให้ล่าสุดอยู่บนสุด]
// -----------------------------------------------------------------------------------
function getPatientTimeline($conn, $case_id) {
    $steps = [];

    // ===== GP =====
    $qGP = $conn->query("SELECT id, disposition, admit_type, hospital_arrival_datetime, recorded_at
                         FROM gp_details 
                         WHERE case_id = '$case_id' 
                         ORDER BY id DESC LIMIT 1");

    if (!$qGP) {
        return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';
    }

    $gp_data = ($qGP && $qGP->num_rows > 0) ? $qGP->fetch_assoc() : null;

    // ===== ER =====
    $qER = $conn->query("SELECT id, disposition, admit_type, hospital_arrival_datetime, recorded_at
                         FROM er_details 
                         WHERE case_id = '$case_id' 
                         ORDER BY id DESC LIMIT 1");

    if (!$qER) {
        return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';
    }

    $er_data = ($qER && $qER->num_rows > 0) ? $qER->fetch_assoc() : null;

    // ===== WARD =====
    $qWard = $conn->query("SELECT admit_type, status, discharge_status, admit_date, discharge_date
                           FROM ward_details 
                           WHERE case_id = '$case_id' 
                           ORDER BY id ASC");

    if (!$qWard) {
        return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';
    }

    $ward_count = 0;
    $ward_steps = [];

    if ($qWard && $qWard->num_rows > 0) {
        while ($w = $qWard->fetch_assoc()) {
            $ward_count++;

            $j_class = 'default';
            if(stripos($w['admit_type'], 'ICU') !== false) { $j_class = 'icu'; }
            elseif(stripos($w['admit_type'], 'RCU') !== false) { $j_class = 'rcu'; }
            elseif(stripos($w['admit_type'], 'A4') !== false) { $j_class = 'a4'; }
            elseif(stripos($w['admit_type'], 'B3') !== false) { $j_class = 'b3'; }

            $lbl = $w['admit_type'];

            $ward_steps[] = ['type' => $j_class, 'label' => $lbl, 'icon' => 'fa-bed-pulse', 'datetime' => $w['admit_date'] ?? null];

            if ($w['status'] == 'Discharged') { 
                if ($w['discharge_status'] == 'Dead') {
                    $ward_steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
                } elseif ($w['discharge_status'] == 'Refer') {
                    $ward_steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
                } else {
                    $ward_steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $w['discharge_date'] ?? null]; 
                }
            }
            elseif ($w['status'] == 'Dead') {
                $ward_steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
            }
            elseif ($w['status'] == 'Refer') {
                $ward_steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
            }
        }
    }

    // =====  DECISION POINT =====

    //  CASE 1: มี ER หรือ Ward → ใช้ ER flow
    if ($er_data || $ward_count > 0) {

        if ($er_data) {
            $steps[] = ['type' => 'er', 'label' => 'ER', 'icon' => 'fa-truck-medical', 'datetime' => $er_data['hospital_arrival_datetime'] ?? null];
        }

        // ward steps ต่อจาก ER
        $steps = array_merge($steps, $ward_steps);

        // fallback กรณี ER admit แต่ยังไม่มี ward
        if ($ward_count == 0 && $er_data && $er_data['disposition'] == 'Admit' && !empty($er_data['admit_type'])) {
            $w_type = $er_data['admit_type'];

            $j_class = 'default';
            if(stripos($w_type, 'ICU') !== false) { $j_class = 'icu'; }
            elseif(stripos($w_type, 'RCU') !== false) { $j_class = 'rcu'; }
            elseif(stripos($w_type, 'A4') !== false) { $j_class = 'a4'; }
            elseif(stripos($w_type, 'B3') !== false) { $j_class = 'b3'; }

            $steps[] = ['type' => $j_class, 'label' => $w_type, 'icon' => 'fa-bed-pulse'];
        }
     if ($ward_count == 0 && $er_data) {
        if ($er_data['disposition'] == 'Discharge') {
            $steps[] = ['type' => 'dc',    'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $er_data['recorded_at'] ?? null];
        } elseif ($er_data['disposition'] == 'Dead') {
            $steps[] = ['type' => 'dead',  'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
        } elseif ($er_data['disposition'] == 'Refer') {
            $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
        }
    }

    }
    //  CASE 2: ไม่มี ER และไม่มี Ward → ใช้ GP
    elseif ($gp_data) {

        $steps[] = ['type' => 'gp', 'label' => 'GP', 'icon' => 'fa-user-doctor', 'datetime' => $gp_data['hospital_arrival_datetime'] ?? null];

        if ($gp_data['disposition'] == 'Discharge') {
            $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $gp_data['recorded_at'] ?? null];
        }
        elseif ($gp_data['disposition'] == 'Refer') {
            $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
        }
    }

    if(empty($steps)) return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';

    //  reverse เพื่อเอาล่าสุดขึ้นบน
    $steps = array_reverse($steps);

    $html = '<div class="journey-box d-flex flex-column align-items-center gap-1 mt-2">';
    foreach ($steps as $index => $step) {
        $html .= '<span class="j-step j-' . $step['type'] . ' text-center w-100" style="max-width: fit-content;">';
        $html .= '<i class="fa-solid ' . $step['icon'] . ' me-1"></i> ' . htmlspecialchars((string)$step['label'], ENT_QUOTES, 'UTF-8');
        
        // แสดงวันที่ใต้ label
        if (!empty($step['datetime'])) {
            $formatted = date('d/m/Y H:i', strtotime($step['datetime']));
            $html .= '<br><small style="font-size: 0.7rem; opacity: 0.8;">' . $formatted . '</small>';
        }
        
        $html .= '</span>';
        
        if ($index < count($steps) - 1) {
            $html .= '<i class="fa-solid fa-arrow-up j-arrow my-1" style="font-size: 0.65rem; color: #cbd5e1;"></i>';
        }
    }
    $html .= '</div>';

    return $html;
}

$is_multi_record = ($is_visit || $is_pmr || $is_med);

// -----------------------------------------------------------------------------------
// 🧹 จัดกลุ่ม Person Type (บังคับโชว์ 4 รายการเสมอ แม้ว่า count จะเป็น 0)
// -----------------------------------------------------------------------------------
$finalPersonCounts = [
    'ทหารกองประจำการ' => 0,
    'กำลังพล ทบ.' => 0,
    'นอก ทบ.' => 0,
    'อื่นๆ' => 0
];

foreach ($personTypeCounts as $ptype => $pcount) {
    $ptype = trim((string)$ptype);
    if (empty($ptype) || $ptype === '-' || $ptype === 'ไม่ระบุ') {
        $finalPersonCounts['อื่นๆ'] += $pcount;
        continue;
    }

    if ($ptype === 'ทหารกองประจำการ' || strpos($ptype, 'พลทหาร') !== false) {
        $finalPersonCounts['ทหารกองประจำการ'] += $pcount;
    } elseif ($ptype === 'กำลังพล ทบ.' || strpos($ptype, 'สัญญาบัตร') !== false || strpos($ptype, 'ประทวน') !== false || strpos($ptype, 'นักเรียน') !== false) {
        $finalPersonCounts['กำลังพล ทบ.'] += $pcount;
    } elseif ($ptype === 'นอก ทบ.' || strpos($ptype, 'พลเรือน') !== false || strpos($ptype, 'ครอบครัว') !== false || strpos($ptype, 'ลูกจ้าง') !== false || strpos($ptype, 'พนักงาน') !== false) {
        $finalPersonCounts['นอก ทบ.'] += $pcount;
    } else {
        $finalPersonCounts['อื่นๆ'] += $pcount;
    }
}

// 💡 ไม่ใช้ arsort() แล้ว เพื่อบังคับให้เรียงลำดับ ทหาร -> ทบ -> นอก ทบ -> อื่นๆ เสมอ

// เตรียมข้อมูลสำหรับการ์ด และสีกราฟโดนัทให้ตรงกัน
$processedPersonData = [];
foreach($finalPersonCounts as $ptype => $pcount) {
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

// แปลง Data สู่ JSON สำหรับส่งให้ JavaScript วาดกราฟ
$heat_json = htmlspecialchars(json_encode($stats_heat_by_type), ENT_QUOTES, 'UTF-8');
$month_json = htmlspecialchars(json_encode($stats_heat_by_month), ENT_QUOTES, 'UTF-8');

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo strtoupper($current_user_name); ?></title>
    <link href="bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

    <link href="CSS/style2.css" rel="stylesheet">
    <style>
        :root {
            --w-primary:  <?php echo $theme_color; ?>;
            --w-bg:       <?php echo $theme_bg; ?>;
            --w-light:    <?php echo $theme_light ?? '#e0f2fe'; ?>;
            --w-primary-10: <?php echo $theme_color; ?>1a;
            --w-primary-20: <?php echo $theme_color; ?>33;
            --border-soft: #e2e8f0;
        }
    </style>
</head>
<body>

<?php 
    $path_prefix = ""; 
    if(file_exists($header_file)) require_once $header_file;
    else echo "<nav class='navbar navbar-dark bg-dark'><div class='container-fluid'><a class='navbar-brand'>System Menu</a></div></nav>";
?>

<div class="container py-4 mb-5">
    <div class="d-flex justify-content-between align-items-end mb-4 pb-3" style="border-bottom: 1px solid var(--w-primary-20);">

        <!-- LEFT -->
        <div>
            <div class="page-title-badge">
                <i class="fa-solid fa-chart-pie"></i>
                <?php echo strtoupper($current_user_name); ?> Dashboard
            </div>

            <h3 class="font-prompt fw-bold mb-1 text-dark" style="font-size:1.6rem;">
                <?php echo $title_text; ?>
            </h3>
        </div>

        <!--  การจัดการต่างๆ  -->
        <div class="d-flex gap-2">

            <!-- ระบบยืนยันตัวตน -->
            <?php if($is_er): ?>
                
            <?php endif; ?>
        
            <!-- แจ้งเตือน Heat -->    
            <?php if($is_er || $is_visit):?>
                <a href="noti_telegram.php?user=<?php echo $current_user_name; ?>" class="btn btn-outline-theme">
                    แจ้งเตือน Heat
                </a>
            <?php endif; ?>
            
            <!-- ไปหน้าจัดการ -->
            <a href="<?php echo $main_link; ?>" class="btn btn-outline-theme">
                <i class="fa-solid fa-list-check me-2"></i>ไปหน้าระบบจัดการ
            </a>

        </div>

    </div>

    <div class="row g-3 mb-4">
        <?php if ($is_er): ?>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-user-check"></i></div><div><div class="kpi-label">รับเข้าระบบในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_total; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-bed-pulse"></i></div><div><div class="kpi-label">ส่ง Admit ต่อในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_admit; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-house-medical"></i></div><div><div class="kpi-label">จำหน่ายกลับบ้านในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_dc; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-danger"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-bell fa-shake"></i></div><div><div class="kpi-label" style="color:#ef4444;">รอ ER รับเข้า</div><div class="kpi-value" style="color:#ef4444;"><?php echo $kpi_wait; ?> <small>ราย</small></div></div></div></div></div>
        
        <?php elseif ($is_gp): ?>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-user-check"></i></div><div><div class="kpi-label">รับเข้าระบบในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_total; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-bed-pulse"></i></div><div><div class="kpi-label">ส่ง Admit ต่อในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_admit; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-house-medical"></i></div><div><div class="kpi-label">จำหน่ายกลับบ้านในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_dc; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-danger"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-bell fa-shake"></i></div><div><div class="kpi-label" style="color:#ef4444;">รอ GP รับเข้า</div><div class="kpi-value" style="color:#ef4444;"><?php echo $kpi_wait; ?> <small>ราย</small></div></div></div></div></div>

        <?php elseif ($is_visit): ?>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-users"></i></div><div><div class="kpi-label">ยอดผู้ป่วยที่ต้องดูแลทั้งหมด</div><div class="kpi-value"><?php echo $kpi_total; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-danger"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi-label" style="color:#ef4444;">รอดำเนินการ</div><div class="kpi-value"><?php echo $kpi_wait; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-warning"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3" style="background:#fffbeb;color:#f59e0b;"><i class="fa-solid fa-clock"></i></div><div><div class="kpi-label" style="color:#f59e0b;">อยู่ระหว่างติดตาม</div><div class="kpi-value"><?php echo $kpi_tracked; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-success"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-clipboard-check"></i></div><div><div class="kpi-label" style="color:#16a34a;">สิ้นสุดการติดตาม</div><div class="kpi-value"><?php echo $kpi_done; ?> <small>ราย</small></div></div></div></div></div>

        <?php elseif ($is_med || $is_pmr): ?>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-users"></i></div><div><div class="kpi-label">ยอดผู้ป่วยที่ต้องดูแลทั้งหมด</div><div class="kpi-value"><?php echo $kpi_total; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-warning"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-clock"></i></div><div><div class="kpi-label" style="color:#f59e0b;">รอติดตาม/เยี่ยม ครั้งแรก</div><div class="kpi-value"><?php echo $kpi_wait; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-clipboard-check"></i></div><div><div class="kpi-label">ติดตาม/เยี่ยมแล้ว (สะสม)</div><div class="kpi-value"><?php echo $kpi_tracked; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-danger"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi-label" style="color:#ef4444;">ประวัติระดับ Severe ในกลุ่ม</div><div class="kpi-value" style="color:#ef4444;"><?php echo $severityCounts['Severe']; ?> <small>ราย</small></div></div></div></div></div>
        
        <?php else: ?>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-bed-pulse"></i></div><div><div class="kpi-label">กำลัง Admit ในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_active; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-right-from-bracket"></i></div><div><div class="kpi-label">จำหน่ายแล้วในปีงบประมาณ</div><div class="kpi-value"><?php echo $kpi_dc; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-warning"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="kpi-label" style="color:#f59e0b;">รอรับเข้า</div><div class="kpi-value"><?php echo $kpi_wait; ?> <small>ราย</small></div></div></div></div></div>
            <div class="col-md-3"><div class="card kpi-card border-danger"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi-label" style="color:#ef4444;">ประวัติระดับ Severe สะสม</div><div class="kpi-value" style="color:#ef4444;"><?php echo $severityCounts['Severe']; ?> <small>ราย</small></div></div></div></div></div>
        <?php endif; ?>
    </div>

    <!-- ============================================== -->
    <!-- กราฟแบบ PCT (แยกตามแผนก) -->
    <!-- ============================================== -->
    <div class='row g-4 mb-4'>
        <div class='col-12'>
            <div class='chart-container d-flex flex-column'>
                <div class="chart-section-title d-flex flex-wrap align-items-center justify-content-between gap-3">

                    <div class="d-flex align-items-center gap-2">
                        <div class="chart-icon-wrap">
                            <i class="fa-solid fa-chart-column"></i>
                        </div>
                        <h6 class="font-prompt fw-bold mb-0">
                            จำนวนผู้ป่วยจำแนกตามโรคลมร้อน ใน <?php echo strtoupper($current_user_name); ?>
                        </h6>
                    </div>
                        <form method="GET" class="d-flex flex-wrap align-items-end gap-2">
                            <span class="badge bg-success text-white py-2">ปีงบประมาณ พ.ศ. <?php echo $fiscal_year_label; ?></span>
                            <div>
                                <label class="font-prompt small">วันที่เริ่มต้น</label>
                                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8'); ?>" />
                            </div>
                            <div>
                                <label class="font-prompt small">วันที่สิ้นสุด</label>
                                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8'); ?>" />
                            </div>
                            <div>
                                <button type="submit" class="font-prompt btn btn-sm btn-success">
                                    <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
                                </button>
                                <button type="button" class="font-prompt btn btn-sm btn-outline-secondary" onclick="window.location.href='dashboard.php'">
                                    <i class="fa-solid fa-rotate-left"></i> รีเซ็ต
                                </button>
                            </div>
                        </form>
                </div>
                <div style='position: relative; height: 300px; width: 100%;'><canvas id='chart-heat-type' data-chart='<?php echo $heat_json; ?>'></canvas></div>
                
                <!-- Premium Deep Matte Pastel Cards สำหรับความรุนแรงของโรค -->
                <div class='row g-3 mt-4 justify-content-center'>
                    <div class='col-4 col-md-4'>
                        <div class='py-3 px-2 p-md-4 rounded-4 text-center h-100 d-flex flex-column justify-content-center' style='background: #fc1717; transition: transform 0.3s ease;'>
                            <div class='fw-bold mb-1' style='font-size: 0.85rem; line-height: 1.2; color: rgba(255,255,255,0.85); letter-spacing: 0.5px;'>รุนแรง</div>
                            <div class='fs-3 fw-bold text-white' style='line-height: 1; text-shadow: 0 2px 4px rgba(0,0,0,0.15);'><?php echo $severityCounts['Severe']; ?></div>
                        </div>
                    </div>
                    <div class='col-4 col-md-4'>
                        <div class='py-3 px-2 p-md-4 rounded-4 text-center h-100 d-flex flex-column justify-content-center' style='background: #ffc107; transition: transform 0.3s ease;'>
                            <div class='fw-bold mb-1' style='font-size: 0.85rem; line-height: 1.2; color: rgba(255,255,255,0.9); letter-spacing: 0.5px;'>ไม่รุนแรง</div>
                            <div class='fs-3 fw-bold text-white' style='line-height: 1; text-shadow: 0 2px 4px rgba(0,0,0,0.15);'><?php echo $severityCounts['Moderate']; ?></div>
                        </div>
                    </div>
                    <div class='col-4 col-md-4'>
                        <div class='py-3 px-2 p-md-4 rounded-4 text-center h-100 d-flex flex-column justify-content-center' style='background: #64748b; transition: transform 0.3s ease;'>
                            <div class='fw-bold mb-1' style='font-size: 0.85rem; line-height: 1.2; color: rgba(255,255,255,0.85); letter-spacing: 0.5px;'>อื่นๆ</div>
                            <div class='fs-3 fw-bold text-white' style='line-height: 1; text-shadow: 0 2px 4px rgba(0,0,0,0.15);'><?php echo $severityCounts['Other']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class='row g-4 mb-4'>
        <div class='col-lg-8'>
            <div class='chart-container d-flex flex-column'>
                <div class="chart-section-title">
                    <div class="chart-icon-wrap"><i class="fa-solid fa-chart-line"></i></div>
                    <h6 class="font-prompt fw-bold mb-0 flex-grow-1">แนวโน้มการเกิดโรคลมร้อน</h6>
                </div>
                <div style='position: relative; height: 350px; width: 100%;'><canvas id='chart-heat-month' data-chart='<?php echo $month_json; ?>'></canvas></div>
            </div>
        </div>
        
        <div class='col-lg-4'>
            <div class='chart-container d-flex flex-column h-100'>
                <div class="chart-section-title">
                    <div class="chart-icon-wrap"><i class="fa-solid fa-users"></i></div>
                    <h6 class="font-prompt fw-bold mb-0 flex-grow-1">จำแนกประเภทบุคคล</h6>
                </div>
                
                <!-- กราฟโดนัท -->
                <div style='position: relative; height: 160px; width: 100%; margin-bottom: 15px;'>
                    <canvas id='personTypeChart'></canvas>
                </div>
                
                <!-- การ์ดรายการบุคคล (ย่อขนาดให้พอดี ไม่ต้องเลื่อน) -->
                <div class="flex-grow-1 d-flex flex-column justify-content-center">
                    <?php if(!empty($processedPersonData)): ?>
                        <?php foreach($processedPersonData as $pd): ?>
                            <div class="d-flex align-items-center justify-content-between px-3 py-2 mb-2 rounded-3" style="background: #f8fafc; border: 1px solid #e2e8f0; transition: transform 0.2s ease;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-2 d-flex align-items-center justify-content-center text-white shadow-sm" style="width: 32px; height: 32px; background: <?php echo $pd['bg']; ?>; flex-shrink: 0;">
                                        <i class="fa-solid <?php echo $pd['icon']; ?>" style="font-size: 0.85rem;"></i>
                                    </div>
                                    <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.2;"><?php echo htmlspecialchars($pd['type']); ?></span>
                                </div>
                                <span class="fw-bold text-theme ms-2" style="font-size: 1.05rem; white-space: nowrap;">
                                    <?php echo $pd['count']; ?> <small class="text-muted fw-normal" style="font-size: 0.75rem;">คน</small>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-3 d-flex flex-column align-items-center justify-content-center h-100">
                            <i class="fa-solid fa-users-slash fs-3 mb-2 opacity-25"></i>
                            <div class="small">ไม่มีข้อมูลกำลังพล</div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-12">
            <div class="table-custom">
                <div class="table-header-band <?php echo ($is_er || $is_gp || $is_ward) ? 'danger-band' : 'primary-band'; ?> d-flex justify-content-between align-items-center">
                    <h6 class="font-prompt fw-bold mb-0 text-white">
                        <i class="fa-solid <?php echo ($is_er || $is_gp || $is_ward) ? 'fa-bell' : 'fa-clipboard-list'; ?> me-2"></i>
                        <?php 
                            $wl_title = 'รายการผู้ป่วยแจ้งเตือน รอรับเข้า';
                            if ($is_er) $wl_title = 'รอ ER รับเข้า (รอข้อมูลแจ้งเตือน)';
                            if ($is_gp) $wl_title = 'รอ GP รับเข้า (รอข้อมูลแจ้งเตือน)';
                            if ($is_med || $is_pmr || $is_visit) $wl_title = 'รายการผู้ป่วย รอติดตามครั้งแรก (Pending First Record)';
                            echo $wl_title;
                        ?>
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($is_visit): ?>
                        <div class="d-flex gap-1">
                            <?php if ($is_pcu): ?>
                                <!-- PCU user: ทั้งหมด / PCU / CQI -->
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['visit_tab' => 'all'])); ?>" 
                                   class="btn btn-sm rounded-pill fw-bold <?php echo (($_GET['visit_tab'] ?? 'all') === 'all') ? 'bg-white text-dark' : 'btn-outline-light text-white'; ?>">
                                    ทั้งหมด
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['visit_tab' => 'pcu'])); ?>" 
                                   class="btn btn-sm rounded-pill fw-bold <?php echo (($_GET['visit_tab'] ?? '') === 'pcu') ? 'bg-white text-dark' : 'btn-outline-light text-white'; ?>">
                                    PCU
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['visit_tab' => 'cqi'])); ?>" 
                                   class="btn btn-sm rounded-pill fw-bold <?php echo (($_GET['visit_tab'] ?? '') === 'cqi') ? 'bg-white text-dark' : 'btn-outline-light text-white'; ?>">
                                    CQI
                                </a>
                            <?php elseif (!$is_coc): ?>
                                <!-- admin/pct: แสดงทุก tab -->
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['visit_tab' => 'all'])); ?>" 
                                   class="btn btn-sm rounded-pill fw-bold <?php echo (($_GET['visit_tab'] ?? 'all') === 'all') ? 'bg-white text-dark' : 'btn-outline-light text-white'; ?>">
                                    ทั้งหมด
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['visit_tab' => 'pcu'])); ?>" 
                                   class="btn btn-sm rounded-pill fw-bold <?php echo (($_GET['visit_tab'] ?? '') === 'pcu') ? 'bg-white text-dark' : 'btn-outline-light text-white'; ?>">
                                    PCU
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['visit_tab' => 'coc'])); ?>" 
                                   class="btn btn-sm rounded-pill fw-bold <?php echo (($_GET['visit_tab'] ?? '') === 'coc') ? 'bg-white text-dark' : 'btn-outline-light text-white'; ?>">
                                    COC
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['visit_tab' => 'cqi'])); ?>" 
                                   class="btn btn-sm rounded-pill fw-bold <?php echo (($_GET['visit_tab'] ?? '') === 'cqi') ? 'bg-white text-dark' : 'btn-outline-light text-white'; ?>">
                                    CQI
                                </a>
                            <?php endif; ?>
                            <?php /* COC user: ไม่แสดง tab (เห็นเฉพาะ COC อย่างเดียว) */ ?>
                        </div>
                        <?php endif; ?>
                        <span class="badge bg-white text-dark px-3 py-2 rounded-pill fs-6" style="font-family:'Prompt',sans-serif;font-weight:700;">
                            <?php echo number_format($total_wait); ?> รายการ
                        </span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="background: #fff;">
                        <thead class="bg-light sticky-top shadow-sm">
                            <tr>
                                <th width="4%" class="text-center">#</th>
                                <?php if($is_med || $is_pmr || $is_visit): ?>
                                    <th width="12%">HN / AN</th>
                                    <th width="18%">ข้อมูลผู้ป่วย</th>
                                    <th width="15%">วินิจฉัยแรกรับ</th>
                                    <th width="20%" class="text-center">ไทม์ไลน์การรักษา</th>
                                    <th width="15%">วินิจฉัยสุดท้าย</th>
                                <?php elseif($is_er || $is_gp): ?>
                                    <th width="10%">HN</th>
                                    <th width="14%">เลขบัตร ปชช.</th>
                                    <th width="14%">ชื่อ - สกุล</th>
                                    <th width="18%">หน่วยที่ฝึก</th>
                                    <th width="18%">วันที่รับเข้า</th>
                                <?php else: ?>
                                    <th width="12%">เวลาที่ส่ง/รับย้าย</th>
                                    <th width="12%">HN / AN</th>
                                    <th width="18%">ชื่อ-สกุล</th>
                                    <th width="15%">วินิจฉัย/เหตุผล</th>
                                    <th width="20%" class="text-center">ไทม์ไลน์การรักษา</th>
                                    <th width="10%">ส่งจาก</th>
                                <?php endif; ?>
                                <th width="10%" class='text-center'>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($waitlist)): ?>
                                <?php foreach($waitlist as $idx => $w): ?>
                                    <tr>
                                        <td class="text-center text-muted fw-bold"><?php echo $offset_wait + $idx + 1; ?></td>
                                        
                                        <?php if($is_med || $is_pmr || $is_visit): ?>
                                            <td>
                                                <div class="hn-chip">HN: <?php echo htmlspecialchars($w['hn']); ?></div>
                                                <?php if(!empty($w['an'])): ?>
                                                    <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #475569;">AN: <?php echo htmlspecialchars($w['an']); ?></div>
                                                <?php else: ?>
                                                    <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #9ca3af;">AN: -</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="patient-name"><?php echo htmlspecialchars($w['patient_name']); ?></div>
                                                <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($w['person_type'] ?? '-'); ?></div>
                                                <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars($w['department'] ?? '-'); ?></div>
                                                <?php
                                                    if ($is_visit){
                                                        $vu = !empty($w['visit_unit']) ? $w['visit_unit'] : '';
                                                        $vu_style = 'display:block;border-radius:6px;padding:3px 8px;font-size:0.7rem;margin-top:4px;';
                                                        if ($vu === 'PCU') {
                                                            echo '<div style="'.$vu_style.'background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;"><i class="fa-solid fa-house-chimney-medical me-1"></i> หน่วยที่เยี่ยม: PCU</div>';
                                                        } elseif ($vu === 'CQI') {
                                                            echo '<div style="'.$vu_style.'background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;"><i class="fa-solid fa-chart-line me-1"></i> หน่วยที่เยี่ยม: CQI</div>';
                                                        } elseif ($vu === 'COC') {
                                                            echo '<div style="'.$vu_style.'background:#fff7ed;color:#9a3412;border:1px solid #fdba74;"><i class="fa-solid fa-stethoscope me-1"></i> หน่วยที่เยี่ยม: COC</div>';
                                                        } else {
                                                            echo '<div style="'.$vu_style.'background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;"><i class="fa-regular fa-circle-question me-1"></i> ยังไม่ได้ระบุหน่วย</div>';
                                                        }
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    <?php if(!empty($w['er_dx'])): ?>
                                                        <div class="dx-text small" title="<?php echo htmlspecialchars($w['er_dx']); ?>"><strong class="text-danger">ER:</strong> <?php echo htmlspecialchars(mb_strimwidth($w['er_dx'], 0, 30, '...')); ?></div>
                                                    <?php endif; ?>
                                                    <?php if(!empty($w['icu_diagnosis'])): ?>
                                                        <div class="dx-text small" title="<?php echo htmlspecialchars($w['icu_diagnosis']); ?>"><strong class="text-warning" style="color: #b45309 !important;">ICU:</strong> <?php echo htmlspecialchars(mb_strimwidth($w['icu_diagnosis'], 0, 30, '...')); ?></div>
                                                    <?php endif; ?>
                                                    <?php if(!empty($w['initial_diagnosis'])): ?>
                                                        <div class="dx-text small" title="<?php echo htmlspecialchars($w['initial_diagnosis']); ?>"><strong class="text-primary">Ward:</strong> <?php echo htmlspecialchars(mb_strimwidth($w['initial_diagnosis'], 0, 30, '...')); ?></div>
                                                    <?php endif; ?>
                                                    <?php if(empty($w['er_dx']) && empty($w['icu_diagnosis']) && empty($w['initial_diagnosis'])) echo "<span class='text-muted small'>-</span>"; ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php echo getPatientTimeline($conn, $w['case_id']); ?>
                                                <?php if(!empty($w['discharge_date'])): ?>
                                                    <div class="mt-2 text-center text-danger small fw-bold"><i class="fa-regular fa-clock me-1"></i> จำหน่ายแล้ว</div>
                                                <?php elseif(isset($w['er_status']) && $w['er_status'] == 'Discharge'): ?>
                                                    <div class="mt-2 text-center text-danger small fw-bold"><i class="fa-regular fa-clock me-1"></i> จำหน่ายแล้ว</div>
                                                <?php elseif($is_visit): ?>
                                                    <div class="mt-2 text-center text-warning small fw-bold"><i class="fa-solid fa-bed-pulse me-1"></i> กำลังรักษา</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $w_dx = '-';
                                                    if (!empty($w['final_diagnosis'])) {
                                                        $w_dx = $w['final_diagnosis'];
                                                    } elseif (!empty($w['icu_diagnosis'])) {
                                                        $w_dx = $w['icu_diagnosis'];
                                                    } elseif (!empty($w['initial_diagnosis'])) {
                                                        $w_dx = $w['initial_diagnosis'];
                                                    } elseif (!empty($w['er_dx'])) {
                                                        $w_dx = $w['er_dx'];
                                                    }
                                                ?>
                                                <div class="fw-bold text-success fs-6"><?php echo htmlspecialchars($w_dx); ?></div>
                                                <div class="mt-2">
                                                    <span class="badge bg-danger text-white" style="font-size:0.7rem;">
                                                        <i class="fa-solid fa-home me-1"></i> รอติดตามครั้งแรก
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($is_visit): ?>
                                                    <a href="Visit/formVisit.php?hn=<?php echo urlencode($w['hn']) ;?>" class="btn btn-sm btn-theme text-white fw-bold rounded-pill px-3 shadow-sm w-100">
                                                        บันทึกข้อมูล
                                                    </a>
                                                <?php else : ?>
                                                    <a href="MED/formMED.php?hn=<?php echo urlencode($w['hn']);?>&an=<?php echo urlencode($w['an']);?>" class="btn btn-sm btn-theme text-white fw-bold rounded-pill px-3 shadow-sm w-100">
                                                        บันทึกข้อมูล
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            
                                        <?php elseif($is_er): ?>
                                            <td><div class="fw-bold text-primary"><?php echo htmlspecialchars($w['hn'] ?? '-'); ?></div></td>
                                            <td><?php echo htmlspecialchars($w['person_ID'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($w['Fname'] ?? '-'); ?></td>
                                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($w['work_located'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($w['data_date'] ?? '-'); ?></td>
                                            <?php
                                                $hn = base64_encode($w['hn']);
                                                $rowid = base64_encode($w['row_ID']);
                                            ?>
                                            <td class="text-center">
                                                <a href="ER/indexER.php?hn=<?php echo urlencode($hn); ?>&rowid=<?php echo urlencode($rowid); ?>" 
                                                class="btn btn-sm btn-outline-theme rounded-pill px-3 shadow-sm fw-bold w-100">
                                                ไปหน้าจัดการ
                                                </a>
                                            </td>

                                        <?php elseif($is_gp): ?>
                                            <td><div class="fw-bold text-primary"><?php echo htmlspecialchars($w['hn'] ?? '-'); ?></div></td>
                                            <td><?php echo htmlspecialchars($w['person_ID'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($w['Fname'] ?? '-'); ?></td>
                                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($w['work_located'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($w['data_date'] ?? '-'); ?></td>
                                            <td class="text-center">
                                                <a href="GP/indexER.php?HN=<?php echo $w['hn']; ?>&rowid=<?php echo $w['row_ID']; ?>" class="btn btn-sm btn-outline-theme rounded-pill px-3 shadow-sm fw-bold w-100">ไปหน้าจัดการ</a>
                                            </td>
                                            
                                        <?php else: ?>
                                            <td class="text-danger fw-bold"><i class="fa-regular fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($w['wait_time'])); ?></td>
                                            <td class="fw-bold text-primary"><?php echo $w['hn']; ?></td>
                                            <td><?php echo $w['patient_name']; ?></td>
                                            <td><span class="badge bg-white text-danger border border-danger p-2"><?php echo $w['diagnosis']; ?></span></td>
                                            <td class="text-center">
                                                <?php echo getPatientTimeline($conn, $w['case_id']); ?>
                                            </td>
                                            <td><span class="badge bg-white text-dark border p-2"><i class="fa-solid fa-truck-medical me-1 text-primary"></i> <?php echo $w['from_dept']; ?></span></td>
                                            <td class="text-center">
                                                <a href="<?php echo $main_link; ?>" class="btn btn-sm btn-outline-theme rounded-pill px-3">ไปหน้าจัดการ</a>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="<?php echo ($is_med||$is_pmr||$is_visit) ? 7 : ($is_er || $is_gp ? 9 : 7); ?>" class="text-center py-5"><div class="text-muted"><i class="fa-solid fa-check-circle fs-2 text-success mb-2 opacity-50"></i><br>ไม่มีผู้ป่วยรอคิวในขณะนี้</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if($total_wait > $limit): ?>
                <div class="p-3 border-top bg-light d-flex justify-content-between align-items-center">
                    <small class="text-muted">แสดง <?php echo $offset_wait + 1; ?> - <?php echo min($offset_wait + $limit, $total_wait); ?> จาก <?php echo $total_wait; ?> รายการ</small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo ($page_wait <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getPageLink('page_wait', $page_wait - 1); ?>">« ก่อนหน้า</a>
                            </li>
                            <li class="page-item <?php echo ($offset_wait + $limit >= $total_wait) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getPageLink('page_wait', $page_wait + 1); ?>">ถัดไป »</a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="table-custom mb-4">
        <div class="table-header-band primary-band">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <h6 class="font-prompt fw-bold mb-0 text-white">
                        <i class="fa-solid fa-clock-rotate-left me-2"></i>ประวัติการจัดการข้อมูลผู้ป่วย
                    </h6>
                </div>
                <div class="col-md-7">
                    <form method="GET" class="d-flex justify-content-end gap-2">
                        <span class="badge bg-success text-white py-2 align-self-center">ปีงบประมาณ พ.ศ. <?php echo $fiscal_year_label; ?></span>
                        <div class="input-group input-group-sm" style="max-width: 200px;">
                            <span class="input-group-text bg-white border-end-0"><i class="fa-regular fa-calendar"></i></span>
                            <input type="date" name="start_date" class="form-control border-start-0 ps-0" value="<?php echo htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="input-group input-group-sm" style="max-width: 200px;">
                            <span class="input-group-text bg-white border-end-0">ถึง</span>
                            <input type="date" name="end_date" class="form-control border-start-0 ps-0" value="<?php echo htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <button type="submit" class="btn btn-sm btn-light text-dark px-3 fw-bold shadow-sm">กรองข้อมูล</button>
                    </form>
                </div>
            </div>
        </div>

       <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr class="text-center">
                        <th width="4%">#</th>
                        <th width="10%">วันที่รับบริการ</th>
                        <th width="8%"><?php if($is_er){ echo 'HN';} else{ echo 'HN / AN';} ?></th>
                        <th width="15%">ชื่อผู้ป่วย</th>
                        <?php $is_multi_record = ($is_visit || $is_pmr || $is_med); ?>
                        <th width="<?php echo $is_multi_record ? '16%' : '14%'; ?>"><?php echo ($is_er || $is_gp)?'ข้อมูลรับเข้า':(($current_user_name === 'icu')?'ข้อมูลรับเข้า ICU':(($is_ward)?'วินิจฉัย/เหตุผล':'ผลการประเมิน')); ?></th>
                        <th width="12%" class="text-center">วินิจฉัยแรกรับ / สุดท้าย</th>
                        <th width="<?php echo $is_multi_record ? '8%' : '11%'; ?>" class="text-center">ไทม์ไลน์การรักษา</th>
                        <th width="10%" class="text-center">สถานะปัจจุบัน</th>
                        <th width="10%" class="text-center">ประวัติเต็ม</th>
                    </tr>
                </thead>
                 <tbody>
                    <?php if($resHist && $resHist->num_rows > 0): ?>
                        <?php $idx=0; while($h = $resHist->fetch_assoc()): ?>
                        <?php
                            // -----------------------------
                            // REAL-TIME STATUS LOGIC
                            // -----------------------------

                            $statusBadge = ''; 
                            $dx = ''; 
                            $time_col = ''; 

                            // รับค่า case_id แบบปลอดภัย
                            $c_id = isset($h['case_id']) ? $h['case_id'] : '';

                            // ถ้าไม่มี case_id ไม่ต้อง query
                            if (empty($c_id)) {
                                $final_status_display = '<span class="text-muted small">- ไม่มีข้อมูล -</span>';
                            } else {

                            // -----------------------------
                            // QUERY WARD
                            // -----------------------------
                            $rt_badge = '<span class="st-badge st-none"><i class="fa-solid fa-minus"></i> ไม่มีข้อมูล</span>';

                            $q_rt_ward = $conn->query("SELECT admit_type, status, discharge_status FROM ward_details WHERE case_id = '$c_id' ORDER BY id DESC LIMIT 1");
                            $rt_w = ($q_rt_ward && $q_rt_ward->num_rows > 0) ? $q_rt_ward->fetch_assoc() : null;

                            if ($rt_w) {
                                if ($rt_w['status'] == 'Active') {
                                    $rt_badge = '<span class="st-badge st-admit"><i class="fa-solid fa-bed-pulse me-1"></i> Admit: '.$rt_w['admit_type'].'</span>';
                                } elseif ($rt_w['status'] == 'AdmitWait') {
                                    $rt_badge = '<span class="st-badge st-wait"><i class="fa-solid fa-bed-pulse me-1"></i> '.$rt_w['admit_type'].'</span>';
                                } else {
                                    if (isset($rt_w['discharge_status']) && $rt_w['discharge_status'] == 'Dead') {
                                        $rt_badge = '<span class="st-badge st-dead"><i class="fa-solid fa-book-skull me-1"></i> เสียชีวิต</span>';
                                    } elseif (isset($rt_w['discharge_status']) && $rt_w['discharge_status'] == 'Refer') {
                                        $rt_badge = '<span class="st-badge st-refer"><i class="fa-solid fa-ambulance me-1"></i> Refer</span>';
                                    } else {
                                        $rt_badge = '<span class="st-badge st-dc"><i class="fa-solid fa-house-circle-check me-1"></i> D/C: '.$rt_w['admit_type'].'</span>';
                                    }
                                }

                            } elseif($is_visit || $is_er) {
                                $q_rt_er = $conn->query("SELECT disposition, admit_type FROM er_details WHERE case_id = '$c_id' ORDER BY id DESC LIMIT 1");
                                $rt_e = ($q_rt_er && $q_rt_er->num_rows > 0) ? $q_rt_er->fetch_assoc() : null;
                                if ($rt_e) {
                                    $disposition = isset($rt_e['disposition']) ? $rt_e['disposition'] : '';
                                    $admit_type  = isset($rt_e['admit_type']) ? $rt_e['admit_type'] : '';
                                    if ($disposition == 'Dead') {
                                        $rt_badge = '<span class="st-badge st-dead"><i class="fa-solid fa-book-skull me-1"></i> เสียชีวิต (ER)</span>';
                                    } elseif ($disposition == 'Refer') {
                                        $rt_badge = '<span class="st-badge st-refer"><i class="fa-solid fa-ambulance me-1"></i> Refer (ER)</span>';
                                    } elseif ($disposition == 'Admit') {
                                        $rt_badge = '<span class="st-badge st-er-wait" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;"><i class="fa-solid fa-bed-pulse me-1"></i> Admit '.$admit_type.'</span>';
                                    } else {
                                        $rt_badge = '<span class="st-badge st-dc"><i class="fa-solid fa-house-circle-check me-1"></i> D/C (ER)</span>';
                                    }
                                }
                            } elseif($is_gp) {
                                $q_rt_gp = $conn->query("SELECT disposition, admit_type FROM gp_details WHERE case_id = '$c_id' ORDER BY id DESC LIMIT 1");
                                $gp_e = ($q_rt_gp && $q_rt_gp->num_rows > 0) ? $q_rt_gp->fetch_assoc() : null;
                                if ($gp_e) {
                                    $disposition = isset($gp_e['disposition']) ? $gp_e['disposition'] : '';
                                    $admit_type  = isset($gp_e['admit_type']) ? $gp_e['admit_type'] : '';
                                    if ($disposition == 'Dead') {
                                        $rt_badge = '<span class="st-badge st-dead"><i class="fa-solid fa-book-skull me-1"></i> เสียชีวิต (opd GP)</span>';
                                    } elseif ($disposition == 'Refer') {
                                        $rt_badge = '<span class="st-badge st-refer"><i class="fa-solid fa-ambulance me-1"></i> Refer (opd GP)</span>';
                                    } elseif ($disposition == 'Admit') {
                                        $rt_badge = '<span class="st-badge st-er-wait" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;"><i class="fa-solid fa-bed-pulse me-1"></i> Admit '.$admit_type.'</span>';
                                    } else {
                                        $rt_badge = '<span class="st-badge st-dc"><i class="fa-solid fa-house-circle-check me-1"></i> D/C (OPD GP)</span>';
                                    }
                                }
                            }

                            // -----------------------------
                            // FOLLOW-UP BADGE
                            // -----------------------------
                            $followup_badge = '';

                            if ($is_visit) {
                                $hn_hist = $h['hn'];
                                $qCount = $conn->query("SELECT COUNT(*) as c FROM home_visits WHERE hn = '$hn_hist'");
                                $visit_done = ($qCount && $qCount->num_rows > 0) ? (int)$qCount->fetch_assoc()['c'] : 0;

                                // หา severity จาก diagnosis
                                $h_dx = $h['final_diagnosis'] ?? $h['icu_diagnosis'] ?? $h['initial_diagnosis'] ?? $h['er_dx'] ?? '';
                                $h_is_severe = preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \(CPK > 1,000\)/i', $h_dx);
                                $h_max = $h_is_severe ? 2 : 1;

                                if ($visit_done >= $h_max) {
                                    $followup_badge = "<div class='mt-2'><span class='badge bg-success text-white shadow-sm w-100 py-1' style='font-size:0.7rem;'>
                                        <i class='fa-solid fa-check-double me-1'></i> เยี่ยมครบ {$h_max} ครั้งแล้ว
                                    </span></div>";
                                } else {
                                    $followup_badge = "<div class='mt-2'><span class='badge bg-warning text-dark shadow-sm w-100 py-1' style='font-size:0.7rem;'>
                                        <i class='fa-solid fa-clock me-1'></i> เยี่ยมแล้ว {$visit_done}/{$h_max} ครั้ง
                                    </span></div>";
                                }
                            } elseif ($is_pmr) {
                                $followup_badge = "<div class='mt-2'><span class='badge bg-success text-white shadow-sm w-100 py-1' style='font-size:0.7rem;'><i class='fa-solid fa-check-double me-1'></i> สิ้นสุดฟื้นฟู</span></div>";
                            } elseif ($is_med) {
                                $followup_badge = "<div class='mt-2'><span class='badge bg-success text-white shadow-sm w-100 py-1' style='font-size:0.7rem;'><i class='fa-solid fa-check-double me-1'></i> สิ้นสุดติดตาม</span></div>";
                            }

                            // -----------------------------
                            // FINAL OUTPUT
                            // -----------------------------
                            $final_status_display = "<div class='d-flex flex-column align-items-center justify-content-center'>{$rt_badge}{$followup_badge}</div>";

                            } // end if(!empty($c_id))

                            // ข้อมูลรับเข้า
                            if($is_er || $is_gp) {
                                    $time_col = !empty($h['hospital_arrival_datetime']) ? date('d/m/Y H:i', strtotime($h['hospital_arrival_datetime'])) : '-';
                                    
                                    $dx .= "<div class='small text-muted mb-1' style='font-size: 0.8rem;'><i class='fa-solid fa-temperature-half text-warning me-1'></i>Temp: <strong class='text-dark'>".($h['ct_initial'] ?? '-')."</strong> / 30m: <strong class='text-dark'>".($h['ct_30min'] ?? '-')."</strong></div>";
                                    $dx .= "<div class='small text-muted mb-1' style='font-size: 0.8rem;' title='".htmlspecialchars($h['first_aid']??'')."'><i class='fa-solid fa-kit-medical text-primary me-1'></i>ปฐมพยาบาล: <span class='text-dark'>".htmlspecialchars(mb_strimwidth($h['first_aid']??'-', 0, 25, '...'))."</span></div>";
                                    $dx .= "<div class='small text-muted mb-1' style='font-size: 0.8rem;' title='".htmlspecialchars($h['cause_factor']??'')."'><i class='fa-solid fa-sun text-danger me-1'></i>ปัจจัย: <span class='text-dark'>".htmlspecialchars(mb_strimwidth($h['cause_factor']??'-', 0, 25, '...'))."</span></div>";
                                    $dx .= "<div class='small text-muted' style='font-size: 0.8rem;' title='".htmlspecialchars($h['risk_factors']??'')."'><i class='fa-solid fa-triangle-exclamation text-danger me-1'></i>เสี่ยง: <span class='text-dark'>".htmlspecialchars(mb_strimwidth($h['risk_factors']??'-', 0, 25, '...'))."</span></div>";
                            }elseif($is_ward) {
                                $time_col = !empty($h['admit_date']) ? date('d/m/Y H:i', strtotime($h['admit_date'])) : '-';
                                if ($current_user_name === 'icu') {
                                    $dxText = !empty($h['icu_diagnosis']) ? $h['icu_diagnosis'] : (!empty($h['initial_diagnosis']) ? $h['initial_diagnosis'] : '-');
                                    $icu_time = !empty($h['icu_time_er_to_icu']) ? $h['icu_time_er_to_icu'] . ' นาที' : '-';
                                    $icu_ct1 = !empty($h['icu_core_temp_first']) ? $h['icu_core_temp_first'] . ' °C' : '-';
                                    $icu_ct2 = !empty($h['icu_core_temp_30min']) ? $h['icu_core_temp_30min'] . ' °C' : '-';
                                    $icu_ctrl = !empty($h['icu_control_temp']) ? $h['icu_control_temp'] : '-';
                                    
                                    $dx = "<div class='fw-bold text-warning mb-2' style='color: #b45309 !important; font-size: 0.95rem;' title='".htmlspecialchars($dxText)."'><i class='fa-solid fa-heart-pulse me-1'></i> ".htmlspecialchars(mb_strimwidth($dxText, 0, 35, '...'))."</div>";
                                    $dx .= "<div class='small text-muted mb-1' style='font-size: 0.8rem;'><i class='fa-solid fa-temperature-half text-danger me-1'></i>Temp: <strong class='text-dark'>{$icu_ct1}</strong> / 30m: <strong class='text-dark'>{$icu_ct2}</strong></div>";
                                    $dx .= "<div class='small text-muted mb-1' style='font-size: 0.8rem;'><i class='fa-solid fa-snowflake text-primary me-1'></i>Control Temp: <span class='text-dark fw-bold'>{$icu_ctrl}</span></div>";
                                    $dx .= "<div class='small text-muted' style='font-size: 0.8rem;'><i class='fa-solid fa-stopwatch text-secondary me-1'></i>ER -> ICU: <span class='text-dark'>{$icu_time}</span></div>";
                                } else {
                                    $dxText = !empty($h['final_diagnosis']) ? $h['final_diagnosis'] : (!empty($h['icu_diagnosis']) ? $h['icu_diagnosis'] : ($h['initial_diagnosis'] ?? '-'));
                                    $dx = "<strong class='text-danger'>" . htmlspecialchars((string)$dxText, ENT_QUOTES, 'UTF-8') . "</strong>";
                                }
                            } elseif($is_visit) {
                                $time_col = '<div class="visit-date-badge shadow-sm">เยี่ยมครั้งล่าสุด :'.date('d/m/Y', strtotime($h['record_datetime'])).'</div>';
                                
                                $hn_hist = $h['hn'];
                                $qVisits = $conn->query("SELECT COUNT(*) as cnt FROM home_visits WHERE hn = '$hn_hist'");
                                $visit_count_modal = ($qVisits && $qVisits->num_rows > 0) ? (int)$qVisits->fetch_assoc()['cnt'] : 0;

                                $dx = "
                                    <button type='button' class='btn btn-sm btn-outline-theme rounded-pill px-3 fw-bold shadow-sm'
                                        onclick='openVisitModal(\"" . htmlspecialchars($hn_hist, ENT_QUOTES) . "\", \"" . htmlspecialchars($h['patient_name'], ENT_QUOTES) . "\")'>
                                        <i class='fa-solid fa-notes-medical me-1'></i> ดูผล ({$visit_count_modal} ครั้ง)
                                    </button>
                                ";
                            } elseif($is_pmr) {
                                $time_col = '<div class="visit-date-badge shadow-sm"><i class="fa-solid fa-clock-rotate-left me-1"></i> ฟื้นฟูล่าสุด:<br>'.date('d/m/Y', strtotime($h['record_datetime'])).'</div>';
                                
                                // --- 🟣 PMR เรียงตามฟอร์มที่บันทึก ---
                                $an_hist = $h['an'];
                                $qRecords = $conn->query("SELECT * FROM pmr_records WHERE an = '$an_hist' ORDER BY record_datetime ASC");
                                $dx = '<div class="d-flex flex-column gap-1">';
                                $v_c = 1;
                                if($qRecords && $qRecords->num_rows > 0) {
                                    while($v = $qRecords->fetch_assoc()) {
                                        $status = !empty($v['pmr_status']) ? htmlspecialchars($v['pmr_status']) : '-';
                                        $note = !empty($v['pmr_note']) ? htmlspecialchars($v['pmr_note']) : '-';
                                        $v_date = date('d/m/y', strtotime($v['record_datetime']));
                                        
                                        $dx .= "<div class='small text-dark pb-1 mb-1 border-bottom border-light'>";
                                        $dx .= "<strong class='text-theme'>ครั้งที่ {$v_c} ({$v_date}):</strong><br>";
                                        $dx .= "<span class='text-muted'>ผลประเมิน:</span> <span class='text-primary fw-bold'>{$status}</span>";
                                        if($note !== '-') {
                                            $dx .= " | <span class='text-muted'>บันทึก:</span> {$note}";
                                        }
                                        $dx .= "</div>";
                                        $v_c++;
                                    }
                                } else {
                                    $dx .= "<span class='text-muted small'>ไม่มีข้อมูลการฟื้นฟู</span>";
                                }
                                $dx .= '</div>';

                            } elseif($is_med) {
                                $time_col = '<div class="visit-date-badge shadow-sm"><i class="fa-solid fa-clock-rotate-left me-1"></i> ติดตามล่าสุด:<br>'.date('d/m/Y', strtotime($h['record_datetime'])).'</div>';
                                
                                // --- 🔵 MED เรียงตามฟอร์มที่บันทึก ---
                                $an_hist = $h['an'];
                                $qRecords = $conn->query("SELECT * FROM med_records WHERE an = '$an_hist' ORDER BY record_datetime ASC");
                                $dx = '<div class="d-flex flex-column gap-1">';
                                $v_c = 1;
                                if($qRecords && $qRecords->num_rows > 0) {
                                    while($v = $qRecords->fetch_assoc()) {
                                        $status = !empty($v['med_status']) ? htmlspecialchars($v['med_status']) : '-';
                                        $note = !empty($v['med_note']) ? htmlspecialchars($v['med_note']) : '-';
                                        $v_date = date('d/m/y', strtotime($v['record_datetime']));
                                        
                                        $dx .= "<div class='small text-dark pb-1 mb-1 border-bottom border-light'>";
                                        $dx .= "<strong class='text-theme'>ครั้งที่ {$v_c} ({$v_date}):</strong><br>";
                                        $dx .= "<span class='text-muted'>ผลประเมิน:</span> <span class='text-primary fw-bold'>{$status}</span>";
                                        if($note !== '-') {
                                            $dx .= " | <span class='text-muted'>บันทึก:</span> {$note}";
                                        }
                                        $dx .= "</div>";
                                        $v_c++;
                                    }
                                } else {
                                    $dx .= "<span class='text-muted small'>ไม่มีข้อมูลติดตามอาการ</span>";
                                }
                                $dx .= '</div>';

                            } else {
                                $time_col = date('d/m/Y H:i', strtotime($h['record_datetime'] ?? $h['created_at'] ?? 'now'));
                                $dx = "<span class='text-muted small'>ไม่มีข้อมูล</span>";
                            }
                            // End ข้อมูลรับเข้า

                            $final_dx_display = '-';
                                if (!empty($h['final_diagnosis'])) {
                                    $final_dx_display = $h['final_diagnosis'];
                                } elseif (!empty($h['icu_diagnosis'])) {
                                    $final_dx_display = $h['icu_diagnosis'];
                                } elseif (!empty($h['initial_diagnosis'])) {
                                    $final_dx_display = $h['initial_diagnosis'];
                                } elseif (!empty($h['er_dx'])) { // กรณี Join ข้อมูล
                                    $final_dx_display = $h['er_dx'];
                                } elseif (!empty($h['FstDx'])) { // กรณีอยู่ในหน้า ER โดยตรง
                                    $final_dx_display = $h['FstDx'];
                                } else {
                                    echo '-';
                                }
                        ?>
                        <tr class="text-center">
                            <td class="text-muted fw-bold"><?php echo $offset_hist + $idx + 1; ?></td>
                            <td><?php echo $time_col ;?></td>
                            <td>
                                <div class="fw-bold text-primary"><?php echo $h['hn']; ?></div>
                                <?php if(isset($h['an']) && !empty($h['an'])): ?><div class="small text-muted"><?php echo $h['an']; ?></div><?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo $h['patient_name']; ?></div>
                                <div class="small text-muted mt-1"><i class="fa-solid fa-sitemap me-1"></i> <?php echo $h['department'] ?? '-'; ?></div>
                                <?php if($is_visit || $is_pmr || $is_med): ?>
                                    <div class="badge bg-light text-dark border mt-1"><?php echo $h['person_type']; ?></div>
                                <?php endif; ?>
                                <?php
                                    if ($is_visit){
                                        $vu = !empty($w['visit_unit']) ? $w['visit_unit'] : '';
                                        $vu_style = 'display:block;border-radius:6px;padding:3px 8px;font-size:0.7rem;margin-top:4px;';
                                        if ($vu === 'PCU') {
                                            echo '<div style="'.$vu_style.'background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;"><i class="fa-solid fa-house-chimney-medical me-1"></i> หน่วยเยี่ยม: PCU</div>';
                                        } elseif ($vu === 'CQI') {
                                            echo '<div style="'.$vu_style.'background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;"><i class="fa-solid fa-chart-line me-1"></i> หน่วยเยี่ยม: CQI</div>';
                                        } elseif ($vu === 'COC') {
                                            echo '<div style="'.$vu_style.'background:#fff7ed;color:#9a3412;border:1px solid #fdba74;"><i class="fa-solid fa-stethoscope me-1"></i> หน่วยเยี่ยม: COC</div>';
                                        } else {
                                            echo '<div style="'.$vu_style.'background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;"><i class="fa-regular fa-circle-question me-1"></i> ยังไม่ระบุหน่วยเยี่ยม</div>';
                                        }
                                    }
                                ?>
                            </td>
                            <td><?php echo $dx; ?></td>
                            <?php
                                // --- คำนวณค่า วินิจฉัยแรกรับ และ วินิจฉัยสุดท้าย ---
                                if ($is_er || $is_gp) {
                                    $col_fst = $h['FstDx'] ?? '';
                                    $col_lst = $h['LstDx'] ?? '';
                                } else {
                                    // วินิจฉัยแรกรับ: initial_diagnosis → er_dx → FstDx
                                    $col_fst = '';
                                    if (!empty($h['initial_diagnosis'])) {
                                        $col_fst = $h['initial_diagnosis'];
                                    } elseif (!empty($h['er_dx'])) {
                                        $col_fst = $h['er_dx'];
                                    } elseif (!empty($h['FstDx'])) {
                                        $col_fst = $h['FstDx'];
                                    }
                                    // วินิจฉัยสุดท้าย
                                    $col_lst = $final_dx_display;
                                }
                            ?>
                            <td class="text-center align-middle">
                                <div class="journey-box d-flex flex-column align-items-center gap-1 mt-1">
                                    <div class="text-muted" style="font-size:0.7rem;">วินิจฉัยสุดท้าย</div>
                                    <?php echo getDxSeverityBadge($col_lst); ?>

                                    <div class="text-muted" style="font-size:0.7rem;">วินิจฉัยแรกรับ</div>
                                    <span class="st-badge" style="background:transparent; color:#475569; border:1px solid #cbd5e1;"><?php echo !empty($col_fst) ? htmlspecialchars($col_fst) : '-'; ?></span>
                                </div>
                            </td>
                            <td>
                                <?php echo getPatientTimeline($conn, $h['case_id']); ?>
                            </td>
                            <td><?php echo $final_status_display ;?></td>
                            <td>
                                <a href="view_patient_full_history.php?hn=<?php echo $h['hn']; ?>" class="btn btn-sm btn-light border text-primary shadow-sm" title="ดูประวัติการรักษาทั้งหมด">
                                    <i class="fa-solid fa-folder-open"></i> เปิดแฟ้ม
                                </a>
                            </td>

                        </tr>
                    <?php $idx++; endwhile ; ?>
                     <?php else: ?>
                        <tr><td colspan="10" class="text-center py-5"><div class="text-muted"><i class="fa-solid fa-folder-open fs-2 mb-3 opacity-25"></i><br>ไม่พบข้อมูลประวัติในช่วงเวลาที่เลือก</div></td></tr>
                    <?php endif ; ?>
                </tbody>
            </table>
        </div>

        <?php if($total_hist > $limit): ?>
        <div class="p-3 border-top bg-light d-flex justify-content-between align-items-center">
            <small class="text-muted">แสดง <?php echo $offset_hist + 1; ?> - <?php echo min($offset_hist + $limit, $total_hist); ?> จาก <?php echo $total_hist; ?> รายการ</small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo ($page_hist <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo getPageLink('page_hist', $page_hist - 1); ?>">« ก่อนหน้า</a>
                    </li>
                    <li class="page-item <?php echo ($offset_hist + $limit >= $total_hist) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo getPageLink('page_hist', $page_hist + 1); ?>">ถัดไป »</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Script วาดกราฟ PCT-Style สำหรับ Dashboard รายตึก -->
<script>
    Chart.defaults.font.family = 'Sarabun';
    Chart.defaults.color = '#64748b';
    
    const isMobileChart = window.innerWidth <= 768;
    const legendPosition = 'bottom';
    const legendPadding = isMobileChart ? 10 : 15;
    
    const heatLabelsFull = <?= json_encode($heat_keywords_full) ?>;
    const heatColorsFull = {
        'Heat Stroke': '#fc1717', 'Heat Exhaustion': '#fc1717', 'Rhabdomyolysis (CPK > 1,000)': '#fc1717', 'Rhabdomyolysis (CPK < 1,000)': '#ffc107',
        'Heat Tetany': '#ffc107', 'Heat Cramps': '#ffc107', 'Heat Syncope': '#ffc107',
        'Heat Edema': '#ffc107', 'Prickly Heat': '#ffc107', 'อื่นๆ': '#8E9DAE'
    };

    function hexToRgbaJS(hex, opacity) {
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        let r = parseInt(hex.substring(0,2), 16), g = parseInt(hex.substring(2,4), 16), b = parseInt(hex.substring(4,6), 16);
        return `rgba(${r},${g},${b},${opacity})`;
    }

    // 1. กราฟ Bar จำนวนผู้ป่วยจำแหนกตามโรคลมร้อน
    let heatCanvas = document.getElementById('chart-heat-type');
    if (heatCanvas) {
    let rawDataHeat = JSON.parse(heatCanvas.getAttribute('data-chart'));
    let dataArrHeat = [];
    let bgColorsHeat = [];
    heatLabelsFull.forEach(dx => {
        dataArrHeat.push(rawDataHeat[dx] || 0);
        bgColorsHeat.push(heatColorsFull[dx] || '#8E9DAE');
    });

    new Chart(heatCanvas.getContext('2d'), {
        type: 'bar',
        data: { 
            labels: heatLabelsFull, 
            datasets: [{
                label: 'จำนวนผู้ป่วย (ราย)', data: dataArrHeat, backgroundColor: bgColorsHeat, borderRadius: 4, borderWidth: 0
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
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
    } // end if (heatCanvas)

    // 2. กราฟ Line แนวโน้มรายเดือน (ยอดรวมเส้นเดียว)
    let monthCanvas = document.getElementById('chart-heat-month');
    if (monthCanvas) {
    let ctxMonth = monthCanvas.getContext('2d');
    let rawDataMonth = JSON.parse(monthCanvas.getAttribute('data-chart'));
    let datasetsMonth = [];
    const heatKeywords = ["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat", "อื่นๆ"];
    
    let totalDataArr = [];
    const fiscalMonths = <?= json_encode($fiscal_month_numbers) ?>;
    fiscalMonths.forEach(m => {
        let monthTotal = 0;
        heatKeywords.forEach(dx => {
            monthTotal += (rawDataMonth[dx]?.[m] || 0);
        });
        totalDataArr.push(monthTotal);
    });

    let colorHex = '#e11d48'; 
    let gradient = ctxMonth.createLinearGradient(0, 0, 0, 350);
    gradient.addColorStop(0, hexToRgbaJS(colorHex, 0.4));
    gradient.addColorStop(1, hexToRgbaJS(colorHex, 0.02));

    datasetsMonth.push({
        label: 'ผู้ป่วยโรค Heat (รวมทุกประเภท)', 
        data: totalDataArr,
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

    new Chart(ctxMonth, {
        type: 'line',
        data: { labels: <?= json_encode($months_labels) ?>, datasets: datasetsMonth },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { 
                legend: { position: legendPosition, labels: {font:{family:'Sarabun', size:11}, boxWidth: 12, padding: legendPadding, usePointStyle: true, pointStyle: 'circle'} },
                tooltip: { titleFont: { family: 'Prompt', size: 14 }, bodyFont: { family: 'Sarabun', size: 13 }, backgroundColor: 'rgba(255, 255, 255, 0.95)', titleColor: '#1e293b', bodyColor: '#475569', borderColor: '#e2e8f0', borderWidth: 1, padding: 12, cornerRadius: 12, boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)' }
            },
            scales: { 
                x: { grid: { display: false }, ticks: { font: { family: 'Sarabun', size: isMobileChart ? 10 : 12 }, color: '#64748b' } },
                y: { beginAtZero: true, ticks: { stepSize: 1 }, border: { display: false }, grid: { color: '#f1f5f9', drawTicks: false } }
            }
        }
    });
    } // end if (monthCanvas)

    // 3. กราฟ Doughnut ความรุนแรง (Severity)
    let sevCanvas = document.getElementById('chart-sev');
    if (sevCanvas) {
    let dataArrSev = JSON.parse(sevCanvas.getAttribute('data-chart'));
    new Chart(sevCanvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Severe (รุนแรง)', 'Moderate (ปานกลาง)', 'Mild (เล็กน้อย/อื่นๆ)'],
            datasets: [{
                data: dataArrSev,
                backgroundColor: ['#CD6B77', '#D19553', '#6C9582'], borderWidth: 3, borderColor: '#ffffff', hoverOffset: 5
            }]
        },
        options: { 
            responsive: true, maintainAspectRatio: false, 
            plugins: { 
                legend: { position: legendPosition, labels: {font:{family:'Sarabun', size:11}, color:'#1e293b', usePointStyle: true, padding: legendPadding} },
                tooltip: { titleFont: { family: 'Prompt', size: 14 }, bodyFont: { family: 'Sarabun', size: 13 }, backgroundColor: 'rgba(255, 255, 255, 0.95)', titleColor: '#1e293b', bodyColor: '#475569', borderColor: '#e2e8f0', borderWidth: 1, padding: 12, cornerRadius: 12, boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)' }
            }, 
            cutout: '65%' 
        }
    });
    } // end if (sevCanvas)

    // 4. กราฟ Doughnut ประเภทบุคคล พร้อมนำ Data ไปผูกกับการ์ดด้านล่างให้สีตรงกัน
    const personDataJS = <?php echo json_encode($processedPersonData); ?>;
    
    // สร้าง Array เตรียมให้กราฟโดนัท
    const personTypeLabelsArr = personDataJS.map(d => `${d.type} (${d.count} คน)`);
    const personTypeCountsArr = personDataJS.map(d => d.count);
    const personTypeColorsArr = personDataJS.map(d => d.chartColor);
    
    const totalPersons = personTypeCountsArr.reduce((a, b) => a + b, 0);

    if (document.getElementById('personTypeChart')) {
        let chartData = personTypeCountsArr;
        let chartBg = personTypeColorsArr;
        let chartLabels = personTypeLabelsArr;

        // 💡 กรณีที่ไม่มีข้อมูลเป็น 0 ทั้งหมด จะวาดกราฟสีเทาเพื่อไม่ให้แหว่ง
        if (totalPersons === 0) {
            chartData = [1];
            chartBg = ['#e2e8f0'];
            chartLabels = ['ไม่มีข้อมูลผู้ป่วย'];
        }

        new Chart(document.getElementById('personTypeChart'), {
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
                    // ซ่อน Legend พื้นฐานของ Chart.js เพราะเราใช้ Card ด้านล่างเป็น Legend แล้ว
                    legend: { display: false },
                    tooltip: { 
                        titleFont: { family: 'Prompt', size: 14 }, 
                        bodyFont: { family: 'Sarabun', size: 13 }, 
                        backgroundColor: 'rgba(0, 0, 0, 0.95)',
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
    }

</script>

<?php require_once 'footer.php'; ?>

<!-- ====== Modal แสดงผลการเยี่ยมบ้าน ====== -->
<div class="modal fade" id="visitModal" tabindex="-1" aria-labelledby="visitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-4 shadow">

            <div class="modal-header" style="background: var(--w-primary); color: #fff;">
                <h5 class="modal-title font-prompt fw-bold" id="visitModalLabel">
                    <i class="fa-solid fa-house-medical-circle-check me-2"></i>
                    ผลการเยี่ยมบ้าน: <span id="visitModalPatientName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4" id="visitModalBody">
                <!-- ข้อมูลถูก inject ที่นี่ -->
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-spinner fa-spin fs-2 mb-2"></i><br>กำลังโหลด...
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4"
                    data-bs-dismiss="modal">ปิด</button>
                <a id="visitModalEditBtn" href="#" class="btn btn-theme rounded-pill px-4 text-white fw-bold">
                    <i class="fa-solid fa-pen-to-square me-1"></i> บันทึก/แก้ไขข้อมูล
                </a>
            </div>

        </div>
    </div>
</div>

<script>
    function openVisitModal(hn, patientName) {
        // ตั้งชื่อผู้ป่วยใน Header
        document.getElementById('visitModalPatientName').textContent = patientName;

        // ตั้ง link ปุ่มแก้ไข
        document.getElementById('visitModalEditBtn').href = 'Visit/formVisit.php?hn=' + encodeURIComponent(hn);

        // Reset body
        document.getElementById('visitModalBody').innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="fa-solid fa-spinner fa-spin fs-2 mb-2"></i><br>กำลังโหลด...
            </div>`;

        // เปิด Modal
        const modal = new bootstrap.Modal(document.getElementById('visitModal'));
        modal.show();

        // Fetch ข้อมูลเยี่ยมบ้านผ่าน AJAX
        fetch('ajax/get_visit_detail.php?hn=' + encodeURIComponent(hn))
            .then(res => res.json())
            .then(data => {
                renderVisitModal(data, hn);
            })
            .catch(() => {
                document.getElementById('visitModalBody').innerHTML = `
                    <div class="alert alert-danger rounded-3">
                        <i class="fa-solid fa-circle-exclamation me-2"></i>เกิดข้อผิดพลาดในการโหลดข้อมูล
                    </div>`;
            });
    }

    // [SECURITY FIX] Escape HTML to prevent Stored XSS
    function escapeHtml(str) {
        if (str === null || str === undefined || str === '') return '-';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function renderVisitModal(visits, hn) {
        const body = document.getElementById('visitModalBody');

        if (!visits || visits.length === 0) {
            body.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-folder-open fs-2 mb-3 opacity-25"></i><br>
                    ยังไม่มีข้อมูลการเยี่ยมบ้าน
                </div>`;
            return;
        }

        // Progress bar ครั้งที่เยี่ยม
        const done = Math.min(visits.length, 3);
        const pct  = Math.round((done / 3) * 100);
        const pctColor = done >= 3 ? 'bg-success' : (done >= 2 ? 'bg-warning' : 'bg-danger');

        let html = `
            <div class="mb-4">
                <div class="d-flex justify-content-between mb-1">
                    <small class="fw-bold text-dark font-prompt">ความคืบหน้าการเยี่ยม</small>
                    <small class="text-muted">${done}/3 ครั้ง</small>
                </div>
                <div class="progress rounded-pill" style="height: 10px;">
                    <div class="progress-bar ${pctColor} rounded-pill" style="width: ${pct}%;"></div>
                </div>
            </div>
            <div class="d-flex flex-column gap-3">
        `;

        visits.forEach((v, i) => {
            const visitDate = v.record_datetime
                ? new Date(v.record_datetime).toLocaleDateString('th-TH', {year:'numeric',month:'short',day:'numeric'})
                : '-';
            // [SECURITY FIX] Escape all user-supplied data before inserting into HTML
            const temp    = v.body_temperature ? `${escapeHtml(v.body_temperature)} °C` : '-';
            const urine   = escapeHtml(v.urine_color);
            const symp    = escapeHtml(v.symptoms);
            const note    = escapeHtml(v.visit_note);
            const result  = escapeHtml(v.visit_result);

            // สีขอบตามครั้งที่
            const borderColors = ['#3b82f6','#f59e0b','#10b981'];
            const iconColors   = ['#3b82f6','#f59e0b','#10b981'];
            const bc = borderColors[i] || '#94a3b8';
            const ic = iconColors[i]   || '#94a3b8';

            html += `
                <div class="rounded-4 p-3 shadow-sm" style="border-left: 4px solid ${bc}; background: #f8fafc;">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="fw-bold font-prompt" style="color: ${bc};">
                            <i class="fa-solid fa-house-medical me-2" style="color:${ic};"></i>
                            เยี่ยมครั้งที่ ${i + 1}
                        </div>
                        <span class="badge rounded-pill text-white" style="background:${bc}; font-size:0.75rem;">
                            ${visitDate}
                        </span>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="small text-muted mb-1">
                                <i class="fa-solid fa-temperature-half text-danger me-1"></i>อุณหภูมิร่างกาย
                            </div>
                            <div class="fw-bold text-danger">${temp}</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted mb-1">
                                <i class="fa-solid fa-droplet text-warning me-1"></i>สีปัสสาวะ
                            </div>
                            <div class="fw-bold text-warning" style="color:#b45309!important;">${urine}</div>
                        </div>
                        <div class="col-12">
                            <div class="small text-muted mb-1">
                                <i class="fa-solid fa-comment-medical text-primary me-1"></i>อาการ
                            </div>
                            <div class="text-dark">${symp}</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted mb-1">
                                <i class="fa-solid fa-clipboard-check text-success me-1"></i>ผลการเยี่ยม
                            </div>
                            <div class="fw-bold text-success">${result}</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted mb-1">
                                <i class="fa-regular fa-note-sticky text-secondary me-1"></i>บันทึกเพิ่มเติม
                            </div>
                            <div class="text-dark">${note}</div>
                        </div>
                    </div>
                </div>
            `;
        });

        html += `</div>`;
        body.innerHTML = html;
    }
</script>
</body>
</html>