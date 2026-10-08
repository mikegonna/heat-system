<?php 
// 1. Config & Session
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

$is_pct = (isset($_SESSION['name']) && strtolower($_SESSION['name']) === 'pct') || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$is_viewer = (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer');

if (!isset($_SESSION['user_id']) || (!$is_pct && !$is_viewer)) { 
    header("Location: ../login.php"); 
    exit(); 
}

require_once '../database/db_conn.php'; 

$path_prefix = "../";
$themeColor = '#1d4ed8'; // สีน้ำเงินหลักของ PCT
$themeDark  = '#1e3a8a'; 
$themeLight = '#dbeafe'; 
$bgColor    = '#eff6ff'; 

// 2. รับค่า Search & Pagination
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$search_date = isset($_GET['search_date']) ? mysqli_real_escape_string($conn, $_GET['search_date']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; 
$offset = ($page - 1) * $limit;

// สร้างเงื่อนไข SQL
$whereSQL = " WHERE 1=1 ";

if (!empty($search)) {
    $whereSQL .= " AND (hn LIKE '%$search%' OR patient_name LIKE '%$search%' OR patient_id_card LIKE '%$search%') ";
}
if (!empty($search_date)) {
    $whereSQL .= " AND DATE(created_at) = '$search_date' "; 
}

// =========================================================
// 🚀 ระบบ EXPORT TO EXCEL
// =========================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $filename = "PCT_All_History_Report_" . date('Ymd_His') . ".xls";
    
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $sqlExport = "SELECT * FROM cases $whereSQL ORDER BY id DESC";
    $resultExport = mysqli_query($conn, $sqlExport);
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-type" content="text/html;charset=utf-8" /></head><body><table border="1">';
    echo '<tr style="background-color: #1d4ed8; color: white;">';
    echo '<th>รหัสเคส (ID)</th><th>HN</th><th>เลขประจำตัวประชาชน</th><th>ชื่อ-นามสกุล</th><th>อายุ</th><th>ประเภทกำลังพล</th><th>สังกัด/หน่วย</th>';
    echo '<th>วันที่เข้าระบบ</th><th>สถานะเคส (PCT)</th>';
    echo '</tr>';

    if ($resultExport && mysqli_num_rows($resultExport) > 0) {
        while ($row = mysqli_fetch_assoc($resultExport)) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string)$row['id']) . '</td>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$row['hn']) . '</td>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$row['patient_id_card']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['patient_name']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['age']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['person_type']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['department']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['created_at']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['status_case']) . '</td>';
            echo '</tr>';
        }
    } else { echo '<tr><td colspan="9">ไม่มีข้อมูล</td></tr>'; }
    echo '</table></body></html>';
    exit();
}

// 💡 4. Query นับจำนวนทั้งหมด
$sqlCount = "SELECT COUNT(id) as total FROM cases $whereSQL";
$resCount = mysqli_query($conn, $sqlCount);
$rowCount = mysqli_fetch_assoc($resCount);
$totalRows = $rowCount['total'] ?? 0;
$totalPages = ceil($totalRows / $limit);

// 💡 5. Query ดึงข้อมูลจริง
$sql = "
        SELECT c.*,
            (SELECT COUNT(*) FROM cases WHERE hn = c.hn) as hn_count
        FROM cases c
        $whereSQL
        ORDER BY id DESC 
        LIMIT $offset, $limit
        ";
$main_result = mysqli_query($conn, $sql);

// 🏥 Function: สร้าง Timeline (Journey) ให้เรียงแนวตั้งและล่าสุดอยู่บนสุด ↑
function getPatientTimeline($conn, $case_id) {
    $steps = [];
    $qER = $conn->query("SELECT id, disposition, admit_type, hospital_arrival_datetime, recorded_at FROM er_details WHERE case_id = '$case_id' ORDER BY id DESC LIMIT 1");
    $er_data = null;
    if ($qER && $qER->num_rows > 0) { 
        $er_data = $qER->fetch_assoc();
        $steps[] = ['type' => 'er', 'label' => 'ER', 'icon' => 'fa-truck-medical', 'datetime' => $er_data['hospital_arrival_datetime']]; 
    }
    
    $qWard = $conn->query("SELECT admit_type, status, discharge_status, discharge_date, admit_date FROM ward_details WHERE case_id = '$case_id' ORDER BY id ASC");
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
            $steps[] = ['type' => $j_class, 'label' => $lbl, 'icon' => 'fa-bed-pulse', 'datetime' => $w['admit_date']];
            
            if ($w['status'] == 'Discharged') { 
                if ($w['discharge_status'] == 'Dead') { $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull']; } 
                elseif ($w['discharge_status'] == 'Refer') { $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance']; } 
                else { $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $w['discharge_date']]; }
            } elseif ($w['status'] == 'Dead') { $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull']; } 
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
        if ($er_data['disposition'] == 'Discharge') {
            $steps[] = ['type' => 'dc',    'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $er_data['recorded_at']];
        } elseif ($er_data['disposition'] == 'Dead') {
            $steps[] = ['type' => 'dead',  'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
        } elseif ($er_data['disposition'] == 'Refer') {
            $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
        }
    }
    
    if(empty($steps)) return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';

    // 💡 กลับลำดับเอาอันล่าสุดขึ้นบนสุด
    $steps = array_reverse($steps);

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
    <title>แฟ้มประวัติผู้ป่วยรวม (PCT)</title>
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
        body { font-family: 'Sarabun', sans-serif; background: var(--theme-bg); color: var(--text); min-height: 100vh; }
        h1, h2, h3, h4, h5, h6 { font-family: 'Prompt', sans-serif; }

        .page-wrap { max-width: 100%; margin: 0 auto; padding: 20px 30px 20px; width: 100%; }
        body > .container, body > .container-fluid { max-width: 100% !important; padding-left: 50px !important; padding-right: 50px !important; }

        .page-hero { background: linear-gradient(135deg, var(--theme-dark) 0%, var(--theme-main) 60%, #3b82f6 100%); border-radius: 20px; padding: 32px 36px; margin-bottom: 25px; position: relative; overflow: hidden; box-shadow: 0 10px 35px rgba(29, 78, 216, 0.2); }
        .page-hero::before { content: ''; position: absolute; top: -50px; right: -30px; width: 220px; height: 220px; background: rgba(255,255,255,.15); border-radius: 50%; }
        .page-hero::after { content: ''; position: absolute; bottom: -60px; right: 110px; width: 140px; height: 140px; background: rgba(255,255,255,.08); border-radius: 50%; }
        .hero-eyebrow { display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.3); color: #fff; padding: 5px 16px; border-radius: 50px; font-size: .75rem; font-weight: 600; letter-spacing: .06em; margin-bottom: 12px; backdrop-filter: blur(5px); }
        .page-hero h3 { color: #fff; font-size: 1.6rem; font-weight: 700; margin: 0 0 6px; text-shadow: 0 2px 4px rgba(0,0,0,0.15); }
        .page-hero p  { color: rgba(255,255,255,.95); font-size: .9rem; margin: 0; font-weight: 400; }

        .main-card { background: #fff; border-radius: 20px; border: 1px solid rgba(29, 78, 216, 0.1); box-shadow: 0 8px 30px rgba(0,0,0,.04); overflow: hidden; animation: fadeUp .4s ease both; }
        @keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

        .search-bar-wrap { padding: 24px 28px 20px; border-bottom: 1px solid var(--border); background: #fbfdfc; }
        .search-bar-wrap .form-label-sm { font-family: 'Prompt', sans-serif; font-size: .72rem; font-weight: 600; color: var(--soft); text-transform: uppercase; margin-bottom: 6px; display: block; }
        .search-bar-wrap .form-control, .search-bar-wrap .input-group-text { border-color: var(--border); border-radius: 10px !important; font-size: .9rem; height: 42px; }
        .search-bar-wrap .form-control:focus { border-color: var(--theme-main); box-shadow: 0 0 0 3px var(--theme-light); outline: none; }
        .search-bar-wrap .input-group-text { background: var(--theme-bg); color: var(--theme-dark); border-right: none; border-radius: 10px 0 0 10px !important; }
        .search-bar-wrap .input-group .form-control { border-left: none; border-radius: 0 10px 10px 0 !important; }
        .btn-search { background: var(--theme-main); color: #fff; border: none; border-radius: 10px; padding: 0 22px; height: 42px; font-weight: 600; font-size: .87rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .2s; }
        .btn-search:hover { filter: brightness(1.1); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(29, 78, 216, 0.25); }
        .btn-clear { background: #f1f5f9; color: var(--soft); border: 1.5px solid var(--border); border-radius: 10px; padding: 0 16px; height: 42px; font-weight: 500; font-size: .87rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .2s; }
        .btn-clear:hover { background: #e2e8f0; color: var(--text); }
        .search-active-bar { display: flex; align-items: center; gap: 8px; padding: 8px 28px; background: #fef9c3; border-bottom: 1px solid #fde68a; font-size: .82rem; color: #92400e; flex-wrap: wrap; }

        .registry-table { margin: 0; }
        .registry-table thead th { font-family: 'Prompt', sans-serif; font-size: .85rem; font-weight: 600; color: #475569; background: #f8fafc; border-bottom: 2px solid #e2e8f0; border-top: none; padding: 18px 16px; }
        .registry-table tbody td { padding: 20px 16px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: .95rem; }
        .registry-table tbody tr { cursor: pointer; transition: background .2s ease; }
        .registry-table tbody tr:hover { background: var(--theme-10); }

        .row-num { display: inline-flex; align-items: center; justify-content: center; font-size: .95rem; font-weight: 700; color: #475569; }
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

        .btn-view { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 10px 16px; background: #fff; color: var(--theme-dark); border: 1px solid var(--theme-light); border-radius: 12px; font-weight: 600; font-size: .85rem; font-family: 'Prompt', sans-serif; transition: all .2s; box-shadow: 0 2px 6px rgba(0,0,0,0.02); width: 100%;}
        .btn-view:hover { background: var(--theme-main); border-color: var(--theme-main); color: #fff; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(29, 78, 216, 0.25);}
        
        .pagination-wrap { padding: 20px 28px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #fafafa; flex-wrap: wrap; gap: 15px;}
        .page-link { border-radius: 10px !important; border: 1px solid var(--border); color: var(--soft); font-weight: 600; transition: all .2s; margin: 0 2px;}
        .page-link:hover { border-color: var(--theme-main); color: var(--theme-main); background: var(--theme-bg); }
        .page-item.active .page-link { background: var(--theme-main); border-color: var(--theme-main); color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        
        .empty-state { padding: 64px 20px; text-align: center; }
        .empty-state .empty-icon { width: 72px; height: 72px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #d1d5db; margin: 0 auto 18px; }
        .empty-state h6 { font-size: 1rem; color: var(--text); margin-bottom: 6px; font-weight: 600; }
        .empty-state p  { font-size: .85rem; color: var(--soft); margin: 0; }
    </style>
</head>

<body>

<?php require_once '../header_pct.php'; ?>

<div class="page-wrap">
    
    <div class="page-hero">
        <div class="hero-eyebrow">
            <i class="fa-solid fa-folder-open me-2"></i> ALL PATIENT HISTORY
        </div>
        <h3>แฟ้มประวัติผู้ป่วยรวมทั้งหมด (ส่วนกลาง)</h3>
        <p>ค้นหาและเรียกดูประวัติการรักษาของผู้ป่วยโรคลมร้อนแบบครบวงจรครอบคลุมทุกแผนก</p>
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
                        <span class="form-label-sm"><i class="fa-regular fa-calendar me-1"></i>กรองตามวันที่เข้าระบบ</span>
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
                        <a href="history_centralPCT.php" class="btn-clear btn">
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
                        <th width="10%">วันที่เข้าระบบ</th>
                        <th width="8%">HN</th>
                        <th width="14%">ข้อมูลผู้ป่วย</th>
                        <!-- 💡 เปลี่ยนสัดส่วนความกว้างใหม่ เพิ่มวินิจฉัยสุดท้าย -->
                        <th width="14%">วินิจฉัยแรกรับ</th>
                        <th width="12%" class="text-center">วินิจฉัยสุดท้าย</th>
                        <th width="13%" class="text-center">ไทม์ไลน์การรักษา</th>
                        <th width="10%" class="text-center">สถานะเคส</th>
                        <th width="10%" class="text-center">การจัดการ PCT</th>
                        <th width="5%" class="text-center">แฟ้มประวัติ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($main_result && mysqli_num_rows($main_result) > 0) : ?>
                        <?php $rowNum = $offset + 1; while($row = mysqli_fetch_assoc($main_result)) : ?>
                            <?php
                                $c_id = $row['id']; 
                                
                                // หาข้อมูลวันที่แรกรับ และ วินิจฉัยแรกรับ (Initial Dx)
                                $initial_dx_display = '-';
                                $first_date = $row['created_at'];

                                $q_er_first = mysqli_query($conn, "SELECT FstDx, recorded_at FROM er_details WHERE case_id = '$c_id' ORDER BY id ASC LIMIT 1");
                                if ($q_er_first && mysqli_num_rows($q_er_first) > 0) {
                                    $er_data = mysqli_fetch_assoc($q_er_first);
                                    $initial_dx_display = $er_data['FstDx'];
                                    $first_date = $er_data['recorded_at'];
                                } else {
                                    $q_ward_first = mysqli_query($conn, "SELECT initial_diagnosis FROM ward_details WHERE case_id = '$c_id' ORDER BY id ASC LIMIT 1");
                                    if ($q_ward_first && mysqli_num_rows($q_ward_first) > 0) {
                                        $initial_dx_display = mysqli_fetch_assoc($q_ward_first)['initial_diagnosis'];
                                    }
                                }
                                
                                $initial_dx_html = "<div class='text-danger fw-bold small' style='font-size: 0.85rem;'>".htmlspecialchars($initial_dx_display)."</div>";

                                // 💡 หาวินิจฉัยสุดท้าย (Final Diagnosis)
                                $final_dx_display = '-';
                                $q_ward_last = mysqli_query($conn, "SELECT final_diagnosis, icu_diagnosis, initial_diagnosis FROM ward_details WHERE case_id = '$c_id' ORDER BY id DESC LIMIT 1");
                                if ($q_ward_last && mysqli_num_rows($q_ward_last) > 0) {
                                    $w_last = mysqli_fetch_assoc($q_ward_last);
                                    if (!empty($w_last['final_diagnosis'])) $final_dx_display = $w_last['final_diagnosis'];
                                    elseif (!empty($w_last['icu_diagnosis'])) $final_dx_display = $w_last['icu_diagnosis'];
                                    elseif (!empty($w_last['initial_diagnosis'])) $final_dx_display = $w_last['initial_diagnosis'];
                                }
                                if ($final_dx_display === '-') {
                                    // ถ้าไม่มี Ward ลองหาจาก ER ใบสุดท้าย
                                    $q_er_last = mysqli_query($conn, "SELECT FstDx FROM er_details WHERE case_id = '$c_id' ORDER BY id DESC LIMIT 1");
                                    if ($q_er_last && mysqli_num_rows($q_er_last) > 0) {
                                        $final_dx_display = mysqli_fetch_assoc($q_er_last)['FstDx'];
                                    }
                                }

                                if ($final_dx_display === '-' || empty($final_dx_display)) {
                                    $final_dx_html = "<span class='text-muted small'>-</span>";
                                } else {
                                    $final_dx_html = "<div class='fw-bold text-success' style='font-size: 0.85rem;'>".htmlspecialchars($final_dx_display)."</div>";
                                }

                                // 💡 ดึง Timeline การรักษาแนวตั้ง (ล่าสุดบนสุด)
                                $journey_html = getPatientTimeline($conn, $c_id);

                                // 💡 สถานะของเคส (Open / Closed)
                                $is_closed = ($row['status_case'] === 'Closed');
                                $status_badge = $is_closed 
                                    ? '<span class="badge bg-success shadow-sm w-100 py-2" style="font-size: 0.8rem;"><i class="fa-solid fa-lock me-1"></i> ปิดเคสแล้ว</span>' 
                                    : '<span class="badge bg-warning text-dark shadow-sm w-100 py-2" style="font-size: 0.8rem;"><i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังรักษา</span>';

                                $view_full_history = "../view_patient_full_history.php?hn=" . urlencode((string)$row['hn']);
                            ?>
                            <tr onclick="window.location='<?php echo htmlspecialchars((string)$view_full_history); ?>'">
                                <td class="text-center text-muted fw-bold align-middle"><span class="row-num"><?php echo $rowNum++; ?></span></td>
                                <td class="align-middle">
                                    <div class="fw-bold text-dark"><i class="fa-regular fa-calendar text-muted me-1"></i><?php echo date('d/m/Y', strtotime($first_date)); ?></div>
                                    <div class="small text-muted"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($first_date)); ?> น.</div>
                                </td>
                                <td class="align-middle">
                                    <span class="hn-chip text-dark fw-bold">HN: <?php echo htmlspecialchars((string)$row['hn']); ?></span>
                                </td>
                                <td class="align-middle">
                                    <div class="patient-name <?php echo ($row['hn_count'] > 1) ? 'text-warning' : ''; ?>">
                                        <?php echo htmlspecialchars((string)$row['patient_name']); ?>
                                    </div>
                                    <div class="patient-type">
                                        <i class="fa-solid fa-tag me-1"></i>
                                        <?php echo htmlspecialchars((string)$row['person_type'] ?? '-'); ?>
                                    </div>
                                    <div class="dept-text">
                                        <i class="fa-regular fa-building me-1"></i>
                                        <?php echo htmlspecialchars((string)$row['department'] ?? '-'); ?>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <?php echo $initial_dx_html; ?>
                                </td>
                                <td class="text-center align-middle">
                                    <?php echo $final_dx_html; ?>
                                </td>
                                <td class="text-center align-middle">
                                    <?php echo $journey_html; ?>
                                </td>
                                <td class="text-center align-middle">
                                    <div class="d-flex flex-column align-items-center justify-content-center w-100">
                                        <?php echo $status_badge; ?>
                                    </div>
                                </td>

                                <!-- ส่วนปุ่มจัดการ (Action) -->
                                <td class="text-center align-middle" onclick="event.stopPropagation()">
                                    <?php if (!$is_viewer): ?>
                                        <form method="POST" action="history_centralPCT.php" class="d-inline" onsubmit="return confirm('ยืนยันการดำเนินการ?');">
                                            <input type="hidden" name="case_id" value="<?php echo $c_id; ?>">
                                            <?php if ($is_closed): ?>
                                                <input type="hidden" name="action" value="open_case">
                                                <button type="submit" class="btn btn-sm btn-outline-primary fw-bold shadow-sm w-100" title="คลิกเพื่อเปิดเคสแก้ไขข้อมูล"><i class="fa-solid fa-lock-open"></i> ปลดล็อกเคส</button>
                                            <?php else: ?>
                                                <input type="hidden" name="action" value="close_case">
                                                <button type="submit" class="btn btn-sm btn-success fw-bold shadow-sm w-100" title="คลิกเพื่อปิดเคส (จบการรักษา)"><i class="fa-solid fa-lock"></i> ปิดเคส</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Viewer Mode</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center align-middle" onclick="event.stopPropagation()">
                                    <a href="<?php echo htmlspecialchars((string)$view_full_history); ?>" class="btn-view" title="ดูประวัติเต็ม">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="fa-regular fa-folder-open"></i></div>
                                    <h6>ไม่พบข้อมูล</h6>
                                    <p>ยังไม่มีประวัติในระบบ หรือลองปรับเงื่อนไขการค้นหาใหม่อีกครั้ง</p>
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