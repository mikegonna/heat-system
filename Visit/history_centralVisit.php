<?php 
// 1. Config & Session
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php'; 

$current_user_name = strtolower(trim($_SESSION['name'] ?? ''));
$path_prefix = "../"; 

// 💡 ธีมสีชมพูกุหลาบ (PCU / Home Visit)
$themeColor = '#d6728d'; 
$themeDark  = '#b95c76'; 
$themeLight = '#fae8ee'; 
$bgColor    = '#fdf5f7'; 
$dashTitle  = 'PCU / HOME VISIT DASHBOARD'; 

// เช็คการดึง Header ให้ตรงแผนก
$headerFile = '../header_visit.php';
if ($current_user_name == 'er') {
    $themeColor = '#059669'; $bgColor = '#ecfdf5'; $headerFile = '../header_er.php';
} elseif ($current_user_name == 'med') {
    $themeColor = '#0284c7'; $bgColor = '#f0f9ff'; $headerFile = '../header_followup.php';
} elseif ($current_user_name == 'pmr') {
    $themeColor = '#8b5cf6'; $bgColor = '#f5f3ff'; $headerFile = '../header_rehab.php';
} elseif ($current_user_name == 'icu') {
    $themeColor = '#FF8C00'; $bgColor = '#fff7ed'; $headerFile = '../header_icu.php';
}

// 2. รับค่า Search & Pagination
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$search_date = isset($_GET['search_date']) ? mysqli_real_escape_string($conn, $_GET['search_date']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; 
$offset = ($page - 1) * $limit;

// 3. สร้างเงื่อนไข SQL
$whereSQL = " WHERE 1=1 ";
if (!empty($search)) {
    $whereSQL .= " AND (p.hn LIKE '%$search%' OR p.patient_name LIKE '%$search%' OR p.patient_id_card LIKE '%$search%') ";
}
if (!empty($search_date)) {
    $whereSQL .= " AND DATE(m.record_datetime) = '$search_date' "; 
}

// =========================================================
// 🚀 ระบบ EXPORT TO EXCEL
// =========================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $filename = "Visit_Report_" . date('Ymd_His') . ".xls";
    
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $sqlExport = "
        SELECT p.hn, p.patient_id_card, p.patient_name, p.age, p.weight, p.height, p.bmi, p.person_type, p.department,
               m.*, w.admit_date, w.discharge_date, w.admit_type, w.initial_diagnosis, w.final_diagnosis
        FROM home_visits m
        LEFT JOIN ward_details w ON m.an = w.an
        JOIN cases p ON m.hn = p.hn
        $whereSQL
        ORDER BY m.id DESC
    ";
    $resultExport = mysqli_query($conn, $sqlExport);
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-type" content="text/html;charset=utf-8" /></head><body><table border="1">';
    echo '<tr style="background-color: #d6728d; color: white;">';
    echo '<th>HN</th><th>เลขประจำตัวประชาชน</th><th>ชื่อ-นามสกุล</th><th>อายุ</th><th>ประเภทกำลังพล</th><th>สังกัด/หน่วย</th>';
    echo '<th>AN</th><th>แผนกที่ D/C</th><th>วันที่ D/C</th><th>วินิจฉัยสุดท้าย (Final Dx)</th>';
    echo '<th>วันที่เยี่ยมบ้าน</th><th>อาการ</th><th>อุณหภูมิ (°C)</th><th>สีปัสสาวะ</th>';
    echo '</tr>';

    if ($resultExport && mysqli_num_rows($resultExport) > 0) {
        while ($row = mysqli_fetch_assoc($resultExport)) {
            echo '<tr>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$row['hn']) . '</td>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$row['patient_id_card']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['patient_name']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['age']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['person_type']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['department']) . '</td>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$row['an']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['admit_type']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['discharge_date']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['final_diagnosis']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['record_datetime']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['symptoms']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['body_temperature']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['urine_color']) . '</td>';
            echo '</tr>';
        }
    } else { echo '<tr><td colspan="14">ไม่มีข้อมูล</td></tr>'; }
    echo '</table></body></html>';
    exit();
}

// 💡 4. Query นับจำนวนทั้งหมด (Group by case หรือ hn)
$sqlCount = "SELECT COUNT(DISTINCT m.hn) as total 
             FROM home_visits m
             JOIN cases p ON m.hn = p.hn 
             $whereSQL";
$resCount = mysqli_query($conn, $sqlCount);
$rowCount = mysqli_fetch_assoc($resCount);
$totalRows = $rowCount['total'] ?? 0;
$totalPages = ceil($totalRows / $limit);

// 💡 5. Query ดึงข้อมูลจริง 
$sql = "
    SELECT p.id as case_id, p.hn, p.patient_name, p.age, p.person_type, p.department, MAX(m.id) as max_id
    FROM home_visits m 
    JOIN cases p ON m.hn = p.hn 
    $whereSQL 
    GROUP BY p.hn
    ORDER BY max_id DESC 
    LIMIT $offset, $limit
";

$main_result = mysqli_query($conn, $sql);

// 🏥 Function: สร้าง Timeline (Journey)
function getPatientTimeline($conn, $case_id) {
    $steps = [];
    
    $qER = $conn->query("SELECT id, disposition, admit_type, hospital_arrival_datetime, recorded_at FROM er_details WHERE case_id = '$case_id' ORDER BY id DESC LIMIT 1");
    $er_data = null;
    if ($qER && $qER->num_rows > 0) { 
        $er_data = $qER->fetch_assoc();
        $steps[] = ['type' => 'er', 'label' => 'ER', 'icon' => 'fa-truck-medical', 'datetime' => $er_data['hospital_arrival_datetime'] ?? null]; 
    }
    
    $qWard = $conn->query("SELECT admit_type, status, discharge_status, admit_date, discharge_date FROM ward_details WHERE case_id = '$case_id' ORDER BY id ASC");
    $ward_count = 0;
    if ($qWard && $qWard->num_rows > 0) {
        while ($w = $qWard->fetch_assoc()) {
            $ward_count++;
            $j_class = 'default';
            if(stripos($w['admit_type'], 'ICU') !== false) { $j_class = 'icu'; }
            elseif(stripos($w['admit_type'], 'RCU') !== false) { $j_class = 'rcu'; }
            elseif(stripos($w['admit_type'], 'A4') !== false) { $j_class = 'a4'; }
            elseif(stripos($w['admit_type'], 'B3') !== false) { $j_class = 'b3'; }

            $lbl = $w['admit_type'];
            if ($w['status'] == 'AdmitWait') { $lbl .= ' (รอรับ)'; }
            
            $steps[] = ['type' => $j_class, 'label' => $lbl, 'icon' => 'fa-bed-pulse', 'datetime' => $w['admit_date'] ?? null];
            
            if ($w['status'] == 'Discharged') { 
                if ($w['discharge_status'] == 'Dead') {
                    $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
                } elseif ($w['discharge_status'] == 'Refer') {
                    $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
                } else {
                    $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $w['discharge_date'] ?? null]; 
                }
            }
            elseif ($w['status'] == 'Dead') { $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull']; }
            elseif ($w['status'] == 'Refer') { $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance']; }
        }
    }
    
    if ($ward_count == 0 && $er_data && $er_data['disposition'] == 'Admit' && !empty($er_data['admit_type'])) {
        $w_type = $er_data['admit_type'];
        $j_class = 'default';
        if(stripos($w_type, 'ICU') !== false) { $j_class = 'icu'; }
        elseif(stripos($w_type, 'RCU') !== false) { $j_class = 'rcu'; }
        elseif(stripos($w_type, 'A4') !== false) { $j_class = 'a4'; }
        elseif(stripos($w_type, 'B3') !== false) { $j_class = 'b3'; }
        
        $steps[] = ['type' => $j_class, 'label' => $w_type . ' (รอรับ)', 'icon' => 'fa-bed-pulse'];
    }
    if ($ward_count == 0 && $er_data) {
        if ($er_data['disposition'] == 'Dead') {
            $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
        } elseif ($er_data['disposition'] == 'Refer') {
            $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
        } elseif ($er_data['disposition'] == 'Discharge') {
            $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $er_data['recorded_at'] ?? null];
        }
    }
    
    if(empty($steps)) return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';

    // 💡 REVERSE ARRAYS: เพื่อให้สถานะล่าสุดอยู่บนสุด
    $steps = array_reverse($steps);

    // 💡 จัดให้อยู่ชิดซ้าย (align-items-start) เพื่อให้เข้ากับตัวหนังสือในคอลัมน์ของหน้า Visit
    $html = '<div class="journey-box d-flex flex-column align-items-center gap-1 mt-2">';
    foreach ($steps as $index => $step) {
        $html .= '<span class="j-step j-' . $step['type'] . ' text-center w-100" style="max-width: fit-content;">';
        $html .= '<i class="fa-solid ' . $step['icon'] . ' me-1"></i> ' . $step['label'];
        
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
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทะเบียนประวัติผู้ป่วยรวม (Visit)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --theme-main:  <?php echo $themeColor; ?>;
            --theme-dark:  <?php echo $themeDark; ?>;
            --theme-light: <?php echo $themeLight; ?>;
            --theme-bg:    <?php echo $bgColor; ?>;
            --theme-10:    color-mix(in srgb, var(--theme-main) 10%, transparent);
            --theme-20:    color-mix(in srgb, var(--theme-main) 20%, transparent);
            --border:  #e5e7eb;
            --text:    #111827;
            --soft:    #6b7280;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Sarabun', sans-serif;
            background: var(--theme-bg);
            background-image: radial-gradient(ellipse 80% 40% at 50% -15%, var(--theme-light), transparent);
            color: var(--text);
            min-height: 100vh;
        }

        h1, h2, h3, h4, h5, h6 { font-family: 'Prompt', sans-serif; }

        .page-wrap { max-width: 100%; margin: 0 auto; padding: 20px 30px 20px; width: 100%; }
        body > .container, body > .container-fluid { max-width: 100% !important; padding-left: 50px !important; padding-right: 50px !important; }

        .page-hero { background: var(--theme-main); border-radius: 20px; padding: 32px 36px; margin-bottom: 25px; position: relative; overflow: hidden; box-shadow: 0 10px 35px rgba(214,114,141,0.25); }
        .page-hero::before { content: ''; position: absolute; top: -50px; right: -30px; width: 220px; height: 220px; background: rgba(255,255,255,.12); border-radius: 50%; }
        .page-hero::after { content: ''; position: absolute; bottom: -60px; right: 110px; width: 140px; height: 140px; background: rgba(255,255,255,.07); border-radius: 50%; }
        .hero-eyebrow { display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.35); color: #fff; padding: 5px 16px; border-radius: 50px; font-size: .75rem; font-weight: 600; letter-spacing: .06em; margin-bottom: 12px; backdrop-filter: blur(5px); }
        .page-hero h3 { color: #fff; font-size: 1.6rem; font-weight: 700; margin: 0 0 6px; text-shadow: 0 2px 4px rgba(0,0,0,0.12); }
        .page-hero p  { color: rgba(255,255,255,.9); font-size: .9rem; margin: 0; font-weight: 400; }
        .hero-stat { position: absolute; right: 36px; top: 50%; transform: translateY(-50%); text-align: center; }
        .hero-stat .stat-num { font-family: 'Prompt', sans-serif; font-size: 2.5rem; font-weight: 700; color: #fff; line-height: 1; text-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .hero-stat .stat-label { font-size: .75rem; color: rgba(255,255,255,.85); letter-spacing: .06em; font-weight: 500; }

        .main-card { background: #fff; border-radius: 20px; border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 8px 30px rgba(0,0,0,.04); overflow: hidden; animation: fadeUp .4s ease both; }
        @keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

        .search-bar-wrap { padding: 24px 28px 20px; border-bottom: 1px solid var(--border); background: #fbfdfc; }
        .search-bar-wrap .form-label-sm { font-family: 'Prompt', sans-serif; font-size: .72rem; font-weight: 600; color: var(--soft); text-transform: uppercase; margin-bottom: 6px; display: block; }
        .search-bar-wrap .form-control, .search-bar-wrap .input-group-text { border-color: var(--border); border-radius: 10px !important; font-size: .9rem; height: 42px; }
        .search-bar-wrap .form-control:focus { border-color: var(--theme-main); box-shadow: 0 0 0 3px var(--theme-light); outline: none; }
        .search-bar-wrap .input-group-text { background: var(--theme-bg); color: var(--theme-dark); border-right: none; border-radius: 10px 0 0 10px !important; }
        .search-bar-wrap .input-group .form-control { border-left: none; border-radius: 0 10px 10px 0 !important; }
        .btn-search { background: var(--theme-main); color: #fff; border: none; border-radius: 10px; padding: 0 22px; height: 42px; font-weight: 600; font-size: .87rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .2s; }
        .btn-search:hover { filter: brightness(1.1); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0, 0.15); }
        .btn-clear { background: #f1f5f9; color: var(--soft); border: 1.5px solid var(--border); border-radius: 10px; padding: 0 16px; height: 42px; font-weight: 500; font-size: .87rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .2s; }
        .btn-clear:hover { background: #e2e8f0; color: var(--text); }
        .search-active-bar { display: flex; align-items: center; gap: 8px; padding: 8px 28px; background: #fef9c3; border-bottom: 1px solid #fde68a; font-size: .82rem; color: #92400e; flex-wrap: wrap; }

        .registry-table { margin: 0; }
        .registry-table thead th { font-family: 'Prompt', sans-serif; font-size: .85rem; font-weight: 600; color: #475569; background: #f8fafc; border-bottom: 2px solid #e2e8f0; border-top: none; padding: 18px 16px; }
        .registry-table tbody td { padding: 20px 16px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: .95rem; }
        .registry-table tbody tr { cursor: pointer; transition: background .2s ease; }
        .registry-table tbody tr:hover { background: var(--theme-10); }

        .row-num { display: inline-flex; align-items: center; justify-content: center; font-size: .95rem; font-weight: 700; color: #475569; }
        .date-text { font-size: .85rem; color: var(--soft); display: flex; flex-direction: column; gap: 4px; }
        .hn-chip { display: inline-block; font-family: 'Prompt', sans-serif; font-size: .85rem; font-weight: 700; color: var(--theme-dark); background: var(--theme-bg); border: 1px solid var(--theme-20); padding: 4px 10px; border-radius: 6px; }
        .patient-name { font-weight: 700; color: var(--text); font-size: 1.05rem; }
        .patient-type { display: inline-block; margin-top: 6px; font-size: .75rem; color: #64748b; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 4px;}
        .dept-text { font-size: .85rem; color: #6b7280; display: inline-block; margin-top: 4px;}

        /* ไทม์ไลน์ */
        .journey-box { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 4px; margin-top: 2px; }
        .j-step { padding: 3px 10px; border-radius: 50px; font-weight: 600; font-family: 'Prompt', sans-serif; font-size: 0.75rem; white-space: nowrap; border: 1px solid transparent; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .j-arrow { color: #cbd5e1; font-size: 0.65rem; }
        .j-er { background: #fff7ed; color: #ea580c; border-color: #fdba74; } 
        .j-icu { background: #fef2f2; color: #b91c1c; border-color: #fecaca; } 
        .j-rcu { background: #fffbeb; color: #b45309; border-color: #fde68a; } 
        .j-a4 { background: #f5f3ff; color: #6d28d9; border-color: #ede9fe; } 
        .j-b3 { background: #f0fdfa; color: #0f766e; border-color: #ccfbf1; } 
        .j-default { background: #f1f5f9; color: #475569; border-color: #cbd5e1; } 
        .j-dc { background: #ecfdf5; color: #15803d; border-color: #bbf7d0; }
        .j-dead { background: #111827; color: #f9fafb; border-color: #374151; }
        .j-refer { background: #fefce8; color: #a16207; border-color: #fde68a; }

        .st-badge { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 14px; border-radius: 50px; font-family: 'Prompt', sans-serif; font-size: .8rem; font-weight: 600; white-space: nowrap; width: 100%; margin-bottom: 6px;}

        .btn-view { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 10px 16px; background: #fff; color: var(--theme-dark); border: 1px solid var(--theme-light); border-radius: 12px; font-weight: 600; font-size: .85rem; font-family: 'Prompt', sans-serif; transition: all .2s; box-shadow: 0 2px 6px rgba(0,0,0,0.02); width: 100%;}
        .btn-view:hover { background: var(--theme-main); border-color: var(--theme-main); color: #fff; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(214, 114, 141, 0.25);}
        .btn-view i { font-size: 1.2rem; }

        .pagination-wrap { padding: 20px 28px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #fafafa; flex-wrap: wrap; gap: 15px;}
        .page-link { border-radius: 10px !important; border: 1px solid var(--border); color: var(--soft); font-weight: 600; transition: all .2s; margin: 0 2px;}
        .page-link:hover { border-color: var(--theme-main); color: var(--theme-main); background: var(--theme-bg); }
        .page-item.active .page-link { background: var(--theme-main); border-color: var(--theme-main); color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        
        .empty-state { padding: 64px 20px; text-align: center; }
        .empty-state .empty-icon { width: 72px; height: 72px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #d1d5db; margin: 0 auto 18px; }
        .empty-state h6 { font-size: 1rem; color: var(--text); margin-bottom: 6px; font-weight: 600; }
        .empty-state p  { font-size: .85rem; color: var(--soft); margin: 0; }

        /* ==========================================
           🌟 MOBILE RESPONSIVE CSS
           ========================================== */
        @media (max-width: 768px) {
            .page-wrap { padding: 15px; }
            body > .container, body > .container-fluid { padding-left: 15px !important; padding-right: 15px !important; }
            
            .page-hero { padding: 25px 20px; text-align: center; }
            .hero-stat { position: static; transform: none; margin-top: 20px; display: block !important; }
            
            .search-bar-wrap { padding: 20px 15px; }
            .search-bar-wrap .row { flex-direction: column; }
            .search-bar-wrap .col-md-5, .search-bar-wrap .col-md-3, .search-bar-wrap .col-md-auto { width: 100%; margin-bottom: 10px; }
            .search-bar-wrap .ms-auto { margin-left: 0 !important; margin-top: 10px; }
            .btn-search, .btn-clear, .btn-success { width: 100%; justify-content: center; }
            
            .search-active-bar { padding: 12px 15px; flex-direction: column; align-items: flex-start; gap: 4px; }

            .table-responsive { border: none; }
            .registry-table, .registry-table tbody, .registry-table tr, .registry-table td { display: block; width: 100%; }
            .registry-table thead { display: none; }
            
            .registry-table tr {
                margin-bottom: 15px;
                background-color: #fff;
                border: 1px solid var(--border);
                border-radius: 15px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.03);
                padding: 10px 15px;
                position: relative;
            }
            
            .registry-table td {
                text-align: left !important;
                padding: 12px 0 !important;
                border-bottom: 1px dashed #f1f3f5;
                position: relative;
            }
            .registry-table td:last-child { border-bottom: none; padding-bottom: 5px !important; }
            
            .registry-table td::before {
                content: attr(data-label);
                display: block;
                font-size: 0.8rem;
                font-weight: 600;
                color: #a0aec0;
                margin-bottom: 6px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .registry-table td[data-label="#"] { position: absolute; top: 15px; right: 15px; border-bottom: none; padding: 0 !important; width: auto; }
            .registry-table td[data-label="#"]::before { display: none; }
            .row-num { background: #f1f5f9; padding: 4px 10px; border-radius: 8px; font-size: 0.85rem; }
            
            .date-text { flex-direction: row; align-items: center; gap: 10px; }
            
            .btn-view { flex-direction: row; font-size: 1rem; padding: 12px; background: var(--theme-main); color: #fff; border: none;}
            .btn-view:hover { background: var(--theme-dark); }
            
            .pagination-wrap { flex-direction: column; justify-content: center; text-align: center; }
            .pagination { flex-wrap: wrap; justify-content: center; }
        }
    </style>
</head>

<body>

<?php 
    if (file_exists($headerFile)) { require_once $headerFile; }
    else { require_once '../header_ward.php'; }
?>

<div class="page-wrap">
    
    <div class="page-hero">
        <div class="hero-eyebrow">
            <i class="fa-solid fa-circle-dot fa-beat" style="color:#fff;"></i>
            <?php echo $dashTitle; ?>
        </div>
        <h3><i class="fa-solid fa-address-book me-2"></i>ทะเบียนประวัติผู้ป่วยรวม (Visit)</h3>
        <p>ระบบค้นหาและแสดงประวัติการออกเยี่ยมบ้านทั้งหมด (Home Visit Records)</p>
        <div class="hero-stat d-none d-md-block">
            <div class="stat-num"><?php echo number_format($totalRows); ?></div>
            <div class="stat-label">รายการออกเยี่ยมทั้งหมด</div>
        </div>
    </div>

    <div class="main-card">
        <div class="search-bar-wrap">
            <form method="GET">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <span class="form-label-sm"><i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหาผู้ป่วย</span>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="HN, ชื่อ-สกุล หรือเลขบัตรประชาชน..." value="<?php echo htmlspecialchars((string)$search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <span class="form-label-sm"><i class="fa-regular fa-calendar me-1"></i>กรองตามวันที่ติดตาม</span>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-regular fa-calendar-check"></i></span>
                            <input type="date" class="form-control" name="search_date" value="<?php echo htmlspecialchars((string)$search_date); ?>">
                        </div>
                    </div>
                    <div class="col-md-auto">
                        <button class="btn-search btn" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
                        </button>
                    </div>
                    <?php if(!empty($search) || !empty($search_date)): ?>
                    <div class="col-md-auto">
                        <a href="history_centralVisit.php" class="btn-clear btn">
                            <i class="fa-solid fa-rotate-left"></i> ล้างค่า
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 💡 EXPORT BUTTON -->
                    <div class="col-md-auto ms-auto d-flex align-items-end">
                        <a href="?export=excel&search=<?php echo urlencode($search); ?>&search_date=<?php echo urlencode($search_date); ?>" 
                           class="btn btn-success d-flex align-items-center gap-2 shadow-sm" 
                           style="height: 42px; border-radius: 10px; font-family: 'Prompt', sans-serif; font-weight: 600; background-color: var(--theme-main); border-color: var(--theme-main);">
                            <i class="fa-solid fa-file-excel fs-5"></i> ส่งออก Excel
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <?php if(!empty($search) || !empty($search_date)): ?>
        <div class="search-active-bar">
            <i class="fa-solid fa-filter-circle-xmark"></i> กำลังกรองผลลัพธ์
            <?php if(!empty($search)): ?> — คำค้นหา: <strong>"<?php echo htmlspecialchars((string)$search); ?>"</strong><?php endif; ?>
            <?php if(!empty($search_date)): ?> — วันที่: <strong><?php echo date('d/m/Y', strtotime($search_date)); ?></strong><?php endif; ?>
            &nbsp;·&nbsp; พบ <strong><?php echo number_format($totalRows); ?></strong> รายการ
        </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table registry-table align-middle">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="10%" class="text-center">วันที่เยี่ยมล่าสุด</th>
                        <th width="10%">HN / AN</th>
                        <th width="15%">ข้อมูลผู้ป่วย</th>
                        <th width="16%">ข้อมูลการเยี่ยม</th>
                        <th width="12%" class="text-center">วินิจฉัยสุดท้าย</th>
                        <th width="14%" class="text-center">ไทม์ไลน์การรักษา</th>
                        <th width="10%" class="text-center">สถานะติดตาม</th>
                        <th width="9%" class="text-center">แฟ้มผู้ป่วย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($main_result && mysqli_num_rows($main_result) > 0) : ?>
                        <?php $rowNum = $offset + 1; while($row = mysqli_fetch_assoc($main_result)) : ?>
                            <?php
                                $case_id_curr = $row['case_id']; 
                                $max_id = $row['max_id'];
                                
                                // 💡 1. ดึงข้อมูลบันทึก PMR ล่าสุด
                                $q_med = mysqli_query($conn, "SELECT * FROM home_visits WHERE id = '$max_id'");
                                $med_data = mysqli_fetch_assoc($q_med);
                                
                                // 💡 2. ดึงข้อมูลจาก Ward ที่เกี่ยวข้อง
                                $an = $med_data['an'];
                                $q_ward = mysqli_query($conn, "SELECT initial_diagnosis, final_diagnosis, icu_diagnosis FROM ward_details WHERE an = '$an' ORDER BY id DESC LIMIT 1");
                                $ward_data = mysqli_fetch_assoc($q_ward);

                                $an_text = !empty($an) ? $an : '-';
                                $follow_date = !empty($med_data['record_datetime']) ? $med_data['record_datetime'] : '';

                                // สร้างกล่องข้อมูล Visit
                                $symp = !empty($med_data['symptoms']) ? htmlspecialchars($med_data['symptoms']) : '-';
                                $temp = !empty($med_data['body_temperature']) ? $med_data['body_temperature'] . " °C" : '-';
                                $urine = !empty($med_data['urine_color']) ? htmlspecialchars($med_data['urine_color']) : '-';

                                $med_info_html = "<div class='fw-bold mb-1' style='color: var(--theme-dark); font-size: 0.90rem;' title='{$symp}'><i class='fa-solid fa-house-chimney-medical me-1'></i> อาการ: " . mb_strimwidth($symp, 0, 30, '...') . "</div>";
                                $med_info_html .= "<div class='small text-muted'><i class='fa-solid fa-temperature-half text-danger me-1'></i> Temp: <strong class='text-dark'>{$temp}</strong></div>";
                                $med_info_html .= "<div class='small text-muted'><i class='fa-solid fa-droplet text-warning me-1'></i> สีปัสสาวะ: <strong class='text-dark'>{$urine}</strong></div>";

                                // 💡 3. หาวินิจฉัยสุดท้าย
                                $final_dx_display = '-';
                                if ($ward_data) {
                                    if (!empty($ward_data['final_diagnosis'])) {
                                        $final_dx_display = $ward_data['final_diagnosis'];
                                    } elseif (!empty($ward_data['icu_diagnosis'])) {
                                        $final_dx_display = $ward_data['icu_diagnosis'];
                                    } elseif (!empty($ward_data['initial_diagnosis'])) {
                                        $final_dx_display = $ward_data['initial_diagnosis'];
                                    }
                                } else {
                                    // ถ้าไม่มี Ward ลองหาจาก ER
                                    $q_er_dx = mysqli_query($conn, "SELECT FstDx FROM er_details WHERE case_id = '$case_id_curr' ORDER BY id DESC LIMIT 1");
                                    if ($q_er_dx && mysqli_num_rows($q_er_dx) > 0) {
                                        $final_dx_display = mysqli_fetch_assoc($q_er_dx)['FstDx'];
                                    }
                                }

                                if ($final_dx_display === '-' || empty($final_dx_display)) {
                                    $final_dx_html = "<span class='text-muted small'>-</span>";
                                } else {
                                    $final_dx_html = "<div class='fw-bold text-success' style='font-size: 0.85rem;'>".htmlspecialchars($final_dx_display)."</div>";
                                }

                                // 💡 4. ดึง Timeline การรักษาแนวตั้ง (ล่าสุดบนสุด)
                                $journey_html = getPatientTimeline($conn, $row['case_id']);

                                // 💡 5. สถานะติดตาม — นับตาม severity
                                $q_count = mysqli_query($conn, "SELECT COUNT(*) as c FROM home_visits WHERE hn = '{$row['hn']}' AND an = '$an'");
                                $v_count = mysqli_fetch_assoc($q_count)['c'] ?? 0;

                                // หา severity จาก diagnosis
                                $dx_sev = $final_dx_display;
                                $is_severe = preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \(CPK > 1,000\)/i', $dx_sev);
                                $max_visits = $is_severe ? 2 : 1;

                                if ($v_count >= $max_visits) {
                                    $status_badge = '<span class="st-badge bg-success text-white"><i class="fa-solid fa-check-double me-1"></i> เยี่ยมครบแล้ว</span>';
                                } else {
                                    $status_badge = '<span class="st-badge bg-warning text-dark"><i class="fa-solid fa-house-user me-1"></i> เยี่ยมครั้งที่ '.$v_count.'/'.$max_visits.'</span>';
                                }

                                $view_full_history = "../view_patient_full_history.php?hn=" . urlencode((string)$row['hn']);
                            ?>
                            <tr onclick="window.location='<?php echo htmlspecialchars((string)$view_full_history); ?>'">
                                <td data-label="#" class="text-center position-relative align-middle"><span class="row-num"><?php echo $rowNum++; ?></span></td>
                                <td data-label="วันที่ติดตามล่าสุด" class="text-center align-middle">
                                    <div class="date-text text-center">
                                        <span class="fw-bold text-dark"><i class="fa-regular fa-calendar text-muted me-1"></i><?php echo !empty($follow_date) ? date('d/m/Y', strtotime($follow_date)) : '-'; ?></span>
                                        <span><i class="fa-regular fa-clock me-1"></i><?php echo !empty($follow_date) ? date('H:i', strtotime($follow_date)) : '-'; ?> น.</span>
                                    </div>
                                </td>
                                <td data-label="HN / AN" class="align-middle">
                                    <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                        <span class="hn-chip fw-bold text-dark">HN: <?php echo htmlspecialchars((string)$row['hn']); ?></span>
                                        <?php if($an_text !== '-'): ?>
                                            <span class="hn-chip small" style="background: var(--theme-10); border-color: var(--theme-main); color: var(--theme-dark); padding: 2px 8px; border-radius: 4px;">AN: <?php echo htmlspecialchars($an_text); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="ข้อมูลผู้ป่วย" class="align-middle">
                                    <div class="patient-name"><?php echo htmlspecialchars((string)$row['patient_name']); ?></div>
                                    <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars((string)$row['person_type'] ?? '-'); ?></div>
                                    <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars((string)$row['department'] ?? '-'); ?></div>
                                </td>
                                <td data-label="ข้อมูลการเยี่ยม" class="align-middle">
                                    <div class="bg-white p-2 rounded border shadow-sm">
                                        <?php echo $med_info_html; ?>
                                    </div>
                                </td>
                                <td data-label="วินิจฉัยสุดท้าย" class="text-center align-middle">
                                    <?php echo $final_dx_html; ?>
                                </td>
                                <td data-label="ไทม์ไลน์การรักษา" class="text-center align-middle">
                                    <?php echo $journey_html; ?>
                                </td>
                                <td data-label="สถานะติดตาม" class="text-center align-middle">
                                    <div class="d-flex flex-column align-items-center justify-content-center w-100">
                                        <?php echo $status_badge; ?>
                                    </div>
                                </td>
                                <td data-label="แฟ้มผู้ป่วย" class="text-center align-middle" onclick="event.stopPropagation()">
                                    <a href="<?php echo htmlspecialchars((string)$view_full_history); ?>" class="btn-view" title="ดูประวัติเต็ม">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="fa-regular fa-folder-open"></i></div>
                                    <h6>ไม่พบข้อมูล</h6>
                                    <p>ยังไม่มีประวัติ หรือลองปรับเงื่อนไขการค้นหาใหม่อีกครั้ง</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
            <span class="pagination-info">
                แสดง <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $limit, $totalRows)); ?> 
                จาก <?php echo number_format($totalRows); ?> รายการ
            </span>
            <nav>
                <ul class="pagination mb-0">
                    <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                        <?php $prev_url = "?page=" . ($page - 1) . "&search=" . urlencode($search) . "&search_date=" . urlencode($search_date); ?>
                        <a class="page-link" href="<?php echo htmlspecialchars((string)$prev_url); ?>">
                            <i class="fa-solid fa-chevron-left" style="font-size:.7rem;"></i>
                        </a>
                    </li>
                    <?php
                    $range = 2;
                    for ($i = 1; $i <= $totalPages; $i++) {
                        if ($i == 1 || $i == $totalPages || ($i >= $page - $range && $i <= $page + $range)) {
                            $active = ($i == $page) ? 'active' : '';
                            $page_url = "?page=$i&search=" . urlencode($search) . "&search_date=" . urlencode($search_date);
                            echo "<li class='page-item $active'><a class='page-link' href='" . htmlspecialchars((string)$page_url) . "'>$i</a></li>";
                        } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                            echo "<li class='page-item disabled'><span class='page-link' style='font-size:.8rem;'>···</span></li>";
                        }
                    }
                    ?>
                    <li class="page-item <?php if($page >= $totalPages) echo 'disabled'; ?>">
                        <?php $next_url = "?page=" . ($page + 1) . "&search=" . urlencode($search) . "&search_date=" . urlencode($search_date); ?>
                        <a class="page-link" href="<?php echo htmlspecialchars((string)$next_url); ?>">
                            <i class="fa-solid fa-chevron-right" style="font-size:.7rem;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

    </div>
</div>
</body>
<?php if (file_exists('../footer.php')) require_once '../footer.php'; ?>
</html>