<?php 
// 1. Config & Session
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php'; 

// 2. รับค่า Search & Pagination
$search = $_GET['search'] ?? '';

$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; 
$offset = ($page - 1) * $limit;

// เงื่อนไข SQL
$whereSQL = " WHERE 1=1 ";

if (!empty($search)) {
    $search = mysqli_real_escape_string($conn, $search);
    $whereSQL .= " AND (
        p.hn LIKE '%$search%' 
        OR p.patient_name LIKE '%$search%' 
        OR p.patient_id_card LIKE '%$search%'
    ) ";
}

if (!empty($start_date) && !empty($end_date)) {
    $start_date = mysqli_real_escape_string($conn, $start_date);
    $end_date   = mysqli_real_escape_string($conn, $end_date);

    $whereSQL .= " AND DATE(g.recorded_at) BETWEEN '$start_date' AND '$end_date' ";
}

// =========================================================
// 🚀 ระบบ EXPORT TO EXCEL
// =========================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $filename = "GP_Report_" . date('Ymd_His') . ".xls";
    
    // ตั้งค่า Header ให้เว็บบราวเซอร์รู้ว่านี่คือไฟล์ Excel
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    // ดึงข้อมูลตามเงื่อนไข (ไม่จำกัดจำนวน Limit)
    $sqlExport = "
        SELECT p.hn, p.patient_id_card, p.patient_name, p.age, p.weight, p.height, p.bmi, p.person_type, p.department,
               g.*
        FROM gp_details g 
        JOIN cases p ON g.case_id = p.id 
        $whereSQL 
        ORDER BY g.id DESC
    ";
    $resultExport = mysqli_query($conn, $sqlExport);
    
    // สร้างตาราง HTML เพื่อแปลงเป็นโครงสร้าง Excel
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-type" content="text/html;charset=utf-8" /></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<tr style="background-color: #059669; color: white;">';
    echo '<th>HN</th>';
    echo '<th>เลขประจำตัวประชาชน</th>';
    echo '<th>ชื่อ-นามสกุล</th>';
    echo '<th>อายุ</th>';
    echo '<th>น้ำหนัก</th>';
    echo '<th>ส่วนสูง</th>';
    echo '<th>BMI</th>';
    echo '<th>ประเภทกำลังพล</th>';
    echo '<th>สังกัด/หน่วย</th>';
    echo '<th>วันที่บันทึก (ER)</th>';
    echo '<th>เวลาเริ่มมีอาการ</th>';
    echo '<th>เวลาถึงโรงพยาบาล</th>';
    echo '<th>วินิจฉัยแรกรับ (ER)</th>';
    echo '<th>ปัจจัยการเจ็บป่วย</th>';
    echo '<th>ปัจจัยเสี่ยง</th>';
    echo '<th>อุณหภูมิแรกรับ (°C)</th>';
    echo '<th>อุณหภูมิ 30 นาที (°C)</th>';
    echo '<th>ควบคุมอุณหภูมิ</th>';
    echo '<th>ปฐมพยาบาลเบื้องต้น</th>';
    echo '<th>ผู้บันทึก</th>';
    echo '<th>สถานะการจำหน่าย</th>';
    echo '<th>Admit ที่</th>';
    echo '<th>รพ.ที่ส่งต่อ</th>';
    echo '</tr>';

    if ($resultExport && mysqli_num_rows($resultExport) > 0) {
        while ($row = mysqli_fetch_assoc($resultExport)) {
            echo '<tr>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$row['hn']) . '</td>';
            // mso-number-format:'\@' ป้องกันไม่ให้ Excel แปลงเลขบัตรยาวๆ เป็นตัวเลขวิทยาศาสตร์ (E+)
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$row['patient_id_card']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['patient_name']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['age']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['weight']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['height']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['bmi']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['person_type']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['department']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['recorded_at']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['arrival_time']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['hospital_arrival_datetime']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['FstDx']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['cause_factor']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['risk_factors']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['ct_initial']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['ct_30min']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['ct_control']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['first_aid']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['reporter_name']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['disposition']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['admit_type']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['refer_hospital']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="23">ไม่มีข้อมูล</td></tr>';
    }
    echo '</table>';
    echo '</body></html>';
    exit(); // สั่งหยุดการทำงาน เพื่อไม่ให้หน้าเว็บปกติถูกพิมพ์ลงไปในไฟล์ Excel ด้วย
}

// 💡 4. Query นับจำนวนทั้งหมด
$sqlCount = "SELECT COUNT(g.id) as total 
             FROM gp_details g 
             JOIN cases p ON g.case_id = p.id 
             $whereSQL";
$resCount = mysqli_query($conn, $sqlCount);
$totalRows = ($resCount) ? mysqli_fetch_assoc($resCount)['total'] : 0;
$totalPages = ceil($totalRows / $limit);

// 💡 5. Query ดึงข้อมูลจริง
$sql = "
    SELECT g.*, p.hn, p.patient_name, p.age, p.person_type, p.department 
    FROM gp_details g 
    JOIN cases p ON g.case_id = p.id 
    $whereSQL 
    ORDER BY g.id DESC 
    LIMIT $offset, $limit
";

$main_result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทะเบียนประวัติผู้ป่วยรวม (Central ER)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ══════════════════════════════════════════
           ROOT & BASE
        ══════════════════════════════════════════ */
        :root {
            --g:       #059669;
            --g-dark:  #047857;
            --g-deep:  #047857;
            --g-light: #d1fae5;
            --g-pale:  #ecfdf5;
            --g-10:    rgba(5,150,105,.10);
            --g-20:    rgba(5,150,105,.18);
            --border:  #e5e7eb;
            --text:    #111827;
            --soft:    #6b7280;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Sarabun', sans-serif;
            background: #f0fdf4;
            background-image: radial-gradient(ellipse 80% 40% at 50% -15%, #a7f3d0, transparent);
            color: var(--text);
            min-height: 100vh;
        }
        h1,h2,h3,h4,h5,h6 { font-family: 'Prompt', sans-serif; }

        .page-wrap { max-width: 100%; margin: 0 auto; padding: 20px 30px 20px; width: 100%; box-sizing: border-box;}
        body > .container, body > .container-fluid { max-width: 100% !important; padding-left: 50px !important; padding-right: 50px !important; }

        /* ══════════════════════════════════════════
           PAGE HERO
        ══════════════════════════════════════════ */
        .page-hero { background: linear-gradient(135deg, var(--g-deep) 0%, var(--g) 55%, #10b981 100%); border-radius: 20px; padding: 32px 36px; margin-bottom: 28px; position: relative; overflow: hidden; box-shadow: 0 8px 32px rgba(5,150,105,.22); }
        .page-hero::before { content: ''; position: absolute; top: -50px; right: -30px; width: 220px; height: 220px; background: rgba(255,255,255,.06); border-radius: 50%; }
        .page-hero::after { content: ''; position: absolute; bottom: -60px; right: 110px; width: 140px; height: 140px; background: rgba(255,255,255,.04); border-radius: 50%; }
        .hero-eyebrow { display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.22); color: #fff; padding: 4px 14px; border-radius: 50px; font-size: .75rem; font-weight: 500; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 10px; }
        .page-hero h3 { color: #fff; font-size: 1.55rem; font-weight: 700; margin: 0 0 4px; }
        .page-hero p  { color: rgba(255,255,255,.68); font-size: .88rem; margin: 0; }
        .hero-stat { position: absolute; right: 36px; top: 50%; transform: translateY(-50%); text-align: center; }
        .hero-stat .stat-num { font-family: 'Prompt', sans-serif; font-size: 2.4rem; font-weight: 700; color: #fff; line-height: 1; }
        .hero-stat .stat-label { font-size: .75rem; color: rgba(255,255,255,.65); letter-spacing: .06em; text-transform: uppercase; }

        /* ══════════════════════════════════════════
           MAIN CARD & SEARCH
        ══════════════════════════════════════════ */
        .main-card { background: #fff; border-radius: 20px; border: 1px solid rgba(5,150,105,.08); box-shadow: 0 4px 24px rgba(0,0,0,.06); overflow: hidden; animation: fadeUp .4s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(16px);} to{opacity:1;transform:translateY(0);} }

        .search-bar-wrap { padding: 24px 28px 20px; border-bottom: 1px solid var(--border); background: #fafafa; }
        .search-bar-wrap .form-label-sm { font-family: 'Prompt', sans-serif; font-size: .72rem; font-weight: 600; color: var(--soft); letter-spacing: .05em; text-transform: uppercase; margin-bottom: 6px; display: block; }
        .search-bar-wrap .form-control, .search-bar-wrap .input-group-text { border-color: var(--border); border-radius: 10px !important; font-family: 'Sarabun', sans-serif; font-size: .9rem; height: 42px; }
        .search-bar-wrap .form-control { background: #fff; transition: all .2s; }
        .search-bar-wrap .form-control:focus { border-color: var(--g); background: #fff; box-shadow: 0 0 0 3px var(--g-10); outline: none; }
        .search-bar-wrap .input-group-text { background: var(--g-pale); color: var(--g-dark); border-color: var(--border); border-right: none; border-radius: 10px 0 0 10px !important; }
        .search-bar-wrap .input-group .form-control { border-left: none; border-radius: 0 10px 10px 0 !important; }
        .btn-search { background: var(--g); color: #fff; border: none; border-radius: 10px; padding: 0 22px; height: 42px; font-family: 'Prompt', sans-serif; font-weight: 600; font-size: .87rem; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; }
        .btn-search:hover { filter: brightness(1.1); color: #fff; transform: translateY(-1px); }
        .btn-clear { background: #f1f5f9; color: var(--soft); border: 1.5px solid var(--border); border-radius: 10px; padding: 0 16px; height: 42px; font-family: 'Prompt', sans-serif; font-weight: 500; font-size: .87rem; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; }
        .btn-clear:hover { background: #e2e8f0; color: var(--text); }
        .search-active-bar { display: flex; align-items: center; gap: 8px; padding: 8px 28px; background: #fef9c3; border-bottom: 1px solid #fde68a; font-size: .82rem; color: #92400e; }

        /* ══════════════════════════════════════════
           TABLE
        ══════════════════════════════════════════ */
        .registry-table { margin: 0; }
        .registry-table thead th { font-family: 'Prompt', sans-serif; font-size: .75rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--soft); background: #f9fafb; border-bottom: 1px solid var(--border); border-top: none; padding: 13px 16px; }
        .registry-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f3f4f6; font-size: .9rem; }
        .registry-table tbody tr:last-child td { border-bottom: none; }
        .registry-table tbody tr { cursor: pointer; transition: background .15s; }
        .registry-table tbody tr:hover { background: var(--g-pale); }
        .registry-table tbody tr:hover td { color: var(--g-deep); }

        .row-num { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; background: #f3f4f6; border-radius: 6px; font-size: .75rem; font-weight: 600; color: var(--soft); }
        .hn-chip { display: inline-block; font-family: 'Prompt', sans-serif; font-size: .82rem; font-weight: 700; color: var(--g-dark); background: var(--g-pale); border: 1px solid var(--g-light); padding: 3px 10px; border-radius: 6px; letter-spacing: .03em; }
        .patient-name { font-weight: 700; color: var(--text); font-size: .92rem; }
        .patient-type { display: inline-block; margin-top: 2px; font-size: .75rem; color: var(--soft); }
        .dept-text { font-size: .80rem; color: #6b7280; display: inline-block; margin-top: 2px;}
        .date-text { font-size: .82rem; color: var(--soft); display: flex; align-items: center; gap: 5px; }

        /* ══════════════════════════════════════════
           JOURNEY & ER DX
        ══════════════════════════════════════════ */
        .journey-box { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-top: 2px; }
        .j-step { padding: 3px 8px; border-radius: 50px; font-weight: 600; font-family: 'Prompt', sans-serif; font-size: 0.75rem; white-space: nowrap; border: 1px solid transparent; }
        .j-arrow { color: #9ca3af; font-size: 0.65rem; }
        
        .j-er      { background: #fff7ed; color: #FF8C00; border-color: #fdba74; } 
        .j-icu     { background: #fef2f2; color: #b91c1c; border-color: #fecaca; } 
        .j-rcu     { background: #fffbeb; color: #b45309; border-color: #fde68a; } 
        .j-a4      { background: #f5f3ff; color: #6d28d9; border-color: #ede9fe; } 
        .j-b3      { background: #f0fdfa; color: #0f766e; border-color: #ccfbf1; } 
        .j-default { background: #f1f5f9; color: #475569; border-color: #cbd5e1; } 

        .er-dx-text { font-size: 0.85rem; color: #374151; background: #f8fafc; padding: 6px 10px; border-radius: 6px; border: 1px solid #e2e8f0; display: inline-block; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; }

        /* ══════════════════════════════════════════
           STATUS BADGES
        ══════════════════════════════════════════ */
        .st-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 20px; font-family: 'Prompt', sans-serif; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .st-admit   { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .st-wait    { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
        .st-dc      { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .st-refer   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .st-dead    { background: #1f2937; color: #f9fafb; border: 1px solid #374151; }
        .st-er-wait { background: #fff1f2; color: #b91c1c; border: 1px solid #fecaca; }
        .st-none    { background: #f9fafb; color: #9ca3af; border: 1px solid #e5e7eb; }

        .btn-view { display: inline-flex; align-items: center; gap: 5px; padding: 5px 14px; background: var(--g-pale); color: var(--g-dark); border: 1.5px solid var(--g-light); border-radius: 8px; font-family: 'Prompt', sans-serif; font-size: .78rem; font-weight: 600; transition: all .18s; white-space: nowrap; }
        .btn-view:hover { background: var(--g); color: #fff; border-color: var(--g); box-shadow: 0 3px 10px var(--g-20); transform: translateY(-1px); }

        .empty-state { padding: 64px 20px; text-align: center; }
        .empty-state .empty-icon { width: 72px; height: 72px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #d1d5db; margin: 0 auto 18px; }
        .empty-state h6 { font-size: 1rem; color: var(--text); margin-bottom: 6px; }
        .empty-state p  { font-size: .85rem; color: var(--soft); margin: 0; }

        .pagination-wrap { padding: 18px 28px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #fafafa; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: .82rem; color: var(--soft); }
        .pagination { margin: 0; gap: 4px; }
        .page-link { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 9px !important; border: 1.5px solid var(--border); color: var(--soft); font-family: 'Prompt', sans-serif; font-size: .82rem; font-weight: 600; transition: all .15s; padding: 0; }
        .page-link:hover { border-color: var(--g); color: var(--g); background: var(--g-pale); }
        .page-item.active .page-link { background: var(--g); border-color: var(--g); color: #fff; box-shadow: 0 2px 8px var(--g-20); }
        .page-item.disabled .page-link { opacity: .4; pointer-events: none; }
    </style>
</head>

<body>

<?php 
    $path_prefix = "../"; 
    require_once '../header_gp.php';
?>

<div class="page-wrap">

    <!-- ── Hero ──────────────────────────────────── -->
    <div class="page-hero">
        <div class="hero-eyebrow">
            <i class="fa-solid fa-circle-dot fa-beat" style="color:#6ee7b7;"></i>
            Central Registry
        </div>
        <h3><i class="fa-solid fa-address-book me-2"></i>ทะเบียนรายชื่อผู้ป่วย (รายครั้งที่เข้า GP)</h3>
        <p>ระบบค้นหาประวัติรายเคส</p>
        <div class="hero-stat d-none d-md-block">
            <div class="stat-num"><?php echo number_format($totalRows); ?></div>
            <div class="stat-label">รายการเข้ารักษา</div>
        </div>
    </div>

    <!-- ถ้ามี Error จาก SQL จะแสดงตรงนี้ให้รู้ทันที -->
    <?php if(!$main_result): ?>
        <div class="alert alert-danger m-3 shadow-sm border-danger">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> SQL Error:</strong> <?php echo mysqli_error($conn); ?>
        </div>
    <?php endif; ?>

    <div class="main-card">
        <div class="search-bar-wrap">
            <form method="GET">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <span class="form-label-sm"><i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหาผู้ป่วย</span>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                            <input type="text" class="form-control" name="search"
                                placeholder="HN, ชื่อ-สกุล หรือเลขบัตรประชาชน..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <span class="form-label-sm"><i class="fa-regular fa-calendar me-1"></i>วันที่เริ่มต้น</span>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-regular fa-calendar"></i></span>
                            <input type="date" class="form-control" name="start_date"
                                value="<?= htmlspecialchars($start_date ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <span class="form-label-sm"><i class="fa-regular fa-calendar me-1"></i>วันที่สิ้นสุด</span>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-regular fa-calendar"></i></span>
                            <input type="date" class="form-control" name="end_date"
                                value="<?= htmlspecialchars($end_date ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-auto">
                        <button class="btn-search btn" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
                        </button>
                    </div>
                    <?php if(!empty($search) || !empty($start_date) || !empty($end_date)): ?>
                    <div class="col-md-auto">
                        <a href="history_centralER.php" class="btn-clear btn">
                            <i class="fa-solid fa-rotate-left"></i> ล้างค่า
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 💡 EXPORT BUTTON (เพิ่มใหม่ตรงนี้) -->
                    <div class="col-md-auto ms-auto d-flex align-items-end">
                        <a href="?export=excel&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" 
                           class="btn btn-success d-flex align-items-center gap-2 shadow-sm" 
                           style="height: 42px; border-radius: 10px; font-family: 'Prompt', sans-serif; font-weight: 600;">
                            <i class="fa-solid fa-file-excel fs-5"></i> ส่งออก Excel
                        </a>
                    </div>

                </div>
            </form>
        </div>

        <?php if(!empty($search) || !empty($search_date)): ?>
        <div class="search-active-bar">
            <i class="fa-solid fa-filter-circle-xmark"></i>
            กำลังกรองผลลัพธ์
            <?php if(!empty($search)): ?> — คำค้นหา: <strong>"<?php echo htmlspecialchars($search); ?>"</strong><?php endif; ?>
            <?php if(!empty($search_date)): ?> — วันที่: <strong><?php echo date('d/m/Y', strtotime($search_date)); ?></strong><?php endif; ?>
            &nbsp;·&nbsp; พบ <strong><?php echo number_format($totalRows); ?></strong> รายการ
        </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table registry-table">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="12%">วันที่รับบริการ</th>
                        <th width="10%">HN / AN</th>
                        <th width="16%">ข้อมูลผู้ป่วย</th>
                        <th width="18%">ข้อมูลรับเข้า GP</th>
                        <th width="12%" class="text-center">วินิจฉัยแรกรับ</th>
                        <th width="10%" class="text-center">ไทม์ไลน์</th>
                        <th width="10%" class="text-center">สถานะล่าสุด</th>
                        <th width="8%" class="text-center">ประวัติ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($main_result && mysqli_num_rows($main_result) > 0) : ?>
                        <?php $rowNum = $offset + 1; while($row = mysqli_fetch_assoc($main_result)) : ?>
                            <?php
                                // ตัวแปรแกนหลักของรอบนี้
                                $case_id_curr = $row['case_id']; 
                                $er_date = !empty($row['recorded_at']) ? $row['recorded_at'] : '';
                                $er_date_cond = !empty($er_date) ? " AND admit_date >= '$er_date' " : "";

                                // ----------------------------------------------------
                                // 1. ดึงข้อมูล Ward ครั้งสุดท้ายเพื่อทำ สถานะล่าสุด และ วินิจฉัยสุดท้าย
                                // ----------------------------------------------------
                                $q_ward_last = mysqli_query($conn, "SELECT admit_type, status, discharge_status, final_diagnosis, icu_diagnosis, initial_diagnosis 
                                                                    FROM ward_details 
                                                                    WHERE case_id = '$case_id_curr' $er_date_cond 
                                                                    ORDER BY id DESC LIMIT 1");
                                $w_data_last = ($q_ward_last && mysqli_num_rows($q_ward_last) > 0) ? mysqli_fetch_assoc($q_ward_last) : null;

                                // หา AN เพื่อแสดง (เอาจาก Ward แรกที่แอดมิทในรอบนี้)
                                $q_ward_first = mysqli_query($conn, "SELECT an FROM ward_details WHERE case_id = '$case_id_curr' $er_date_cond ORDER BY id ASC LIMIT 1");
                                $w_data_first = ($q_ward_first && mysqli_num_rows($q_ward_first) > 0) ? mysqli_fetch_assoc($q_ward_first) : null;
                                $an_text = ($w_data_first && !empty($w_data_first['an'])) ? $w_data_first['an'] : '-';

                                // ----------------------------------------------------
                                // 2. จัดรูปแบบข้อมูล ER
                                // ----------------------------------------------------
                                $er_dx = !empty($row['FstDx']) ? (string)$row['FstDx'] : '-';
                                $er_dx_short = mb_strlen($er_dx) > 30 ? mb_substr($er_dx, 0, 30) . '...' : $er_dx;
                                
                                $ct_init = (isset($row['ct_initial']) && $row['ct_initial'] !== '') ? $row['ct_initial'] : '-';
                                $ct_30 = (isset($row['ct_30min']) && $row['ct_30min'] !== '') ? $row['ct_30min'] : '-';
                                $cause = !empty($row['cause_factor']) ? $row['cause_factor'] : '-';
                                $first_aid = !empty($row['first_aid']) ? htmlspecialchars($row['first_aid']) : '-';
                                $risk = !empty($row['risk_factors']) ? $row['risk_factors'] : '-';

                                // ----------------------------------------------------
                                // 3. สร้าง Timeline (Journey) แบบล่าสุดอยู่บนสุด ↑
                                // ----------------------------------------------------
                                $steps = [];

                                $q_er = mysqli_query($conn, "
                                    SELECT id 
                                    FROM er_details 
                                    WHERE case_id = '$case_id_curr' 
                                    ORDER BY id DESC LIMIT 1
                                ");
                                $has_er = ($q_er && mysqli_num_rows($q_er) > 0);

                                $q_gp = mysqli_query($conn, "
                                    SELECT id 
                                    FROM gp_details 
                                    WHERE case_id = '$case_id_curr' 
                                    ORDER BY id DESC LIMIT 1
                                ");
                                $has_gp = ($q_gp && mysqli_num_rows($q_gp) > 0);

                                if ($has_er) {
                                    $steps[] = ['type' => 'er', 'label' => 'ER', 'icon' => 'fa-truck-medical'];

                                } elseif ($has_gp) {
                                    $steps[] = ['type' => 'gp', 'label' => 'GP', 'icon' => 'fa-user-doctor'];
                                }

                                $q_ward_journey = mysqli_query($conn, "
                                    SELECT admit_type, status, discharge_status 
                                    FROM ward_details 
                                    WHERE case_id = '$case_id_curr' $er_date_cond 
                                    ORDER BY id ASC
                                ");

                                if ($q_ward_journey && mysqli_num_rows($q_ward_journey) > 0) {
                                    while($w = mysqli_fetch_assoc($q_ward_journey)) {

                                        $w_type = $w['admit_type'];
                                        $j_class = 'default';

                                        if(stripos($w_type, 'ICU') !== false) { $j_class = 'icu'; }
                                        elseif(stripos($w_type, 'RCU') !== false) { $j_class = 'rcu'; }
                                        elseif(stripos($w_type, 'A4') !== false) { $j_class = 'a4'; }
                                        elseif(stripos($w_type, 'B3') !== false) { $j_class = 'b3'; }

                                        $lbl = $w_type;
                                        if ($w['status'] == 'AdmitWait') { $lbl .= ' (รอรับ)'; }

                                        $steps[] = ['type' => $j_class, 'label' => $lbl, 'icon' => 'fa-bed-pulse'];

                                        if ($w['status'] == 'Discharged') {
                                            if ($w['discharge_status'] == 'Dead') {
                                                $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
                                            } elseif ($w['discharge_status'] == 'Refer') {
                                                $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
                                            } else {
                                                $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check']; 
                                            }
                                        } elseif ($w['status'] == 'Dead') {
                                            $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
                                        } elseif ($w['status'] == 'Refer') {
                                            $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
                                        }
                                    }
                                }

                                $steps = array_reverse($steps);

                                $journey_html = '<div class="journey-box d-flex flex-column align-items-center gap-1">';
                                foreach ($steps as $index => $step) {
                                    $journey_html .= '<span class="j-step j-' . $step['type'] . ' text-center w-100" style="max-width: 120px;">
                                                        <i class="fa-solid ' . $step['icon'] . ' me-1"></i> ' . $step['label'] . '
                                                    </span>';

                                    if ($index < count($steps) - 1) {
                                        $journey_html .= '<i class="fa-solid fa-arrow-up j-arrow my-1" style="font-size: 0.65rem; color: #cbd5e1;"></i>';
                                    }
                                }
                                $journey_html .= '</div>';

                                // ----------------------------------------------------
                                // 4. Status Logic & 🏥 หาวินิจฉัยสุดท้าย (Final Diagnosis)
                                // ----------------------------------------------------
                                $status_badge = '<span class="st-badge st-none"><i class="fa-solid fa-minus"></i> ไม่มีข้อมูล</span>';
                                $final_dx_display = '-';

                                if ($w_data_last) {
                                    // หา Status
                                    $w_status = $w_data_last['status'];
                                    if ($w_status == 'Active') {
                                        $status_badge = '<span class="st-badge st-admit"><i class="fa-solid fa-bed-pulse"></i> Admit: '.$w_data_last['admit_type'].'</span>';
                                    } elseif ($w_status == 'AdmitWait') {
                                        $status_badge = '<span class="st-badge st-wait"><i class="fa-solid fa-clock"></i> รอเข้า: '.$w_data_last['admit_type'].'</span>';
                                    } else {
                                        $d_status = $w_data_last['discharge_status'];
                                        if ($d_status == 'Dead') {
                                            $status_badge = '<span class="st-badge st-dead"><i class="fa-solid fa-book-skull"></i> เสียชีวิต ('.$w_data_last['admit_type'].')</span>';
                                        } elseif ($d_status == 'Refer') {
                                            $status_badge = '<span class="st-badge st-refer"><i class="fa-solid fa-ambulance"></i> Refer ('.$w_data_last['admit_type'].')</span>';
                                        } else {
                                            $status_badge = '<span class="st-badge st-dc"><i class="fa-solid fa-house-circle-check"></i> D/C: '.$w_data_last['admit_type'].'</span>';
                                        }
                                    }

                                    // หา Final DX
                                    if (!empty($w_data_last['final_diagnosis'])) {
                                        $final_dx_display = $w_data_last['final_diagnosis'];
                                    } elseif (!empty($w_data_last['icu_diagnosis'])) {
                                        $final_dx_display = $w_data_last['icu_diagnosis'];
                                    } elseif (!empty($w_data_last['initial_diagnosis'])) {
                                        $final_dx_display = $w_data_last['initial_diagnosis'];
                                    }
                                } else {
                                    // ไม่มีข้อมูล Ward, จบที่ ER
                                    $er_disp = isset($row['disposition']) ? $row['disposition'] : '';
                                    if ($er_disp == 'Dead') {
                                        $status_badge = '<span class="st-badge st-dead"><i class="fa-solid fa-book-skull"></i> เสียชีวิต (GP)</span>';
                                    } elseif ($er_disp == 'Refer') {
                                        $status_badge = '<span class="st-badge st-refer"><i class="fa-solid fa-ambulance"></i> Refer (GP)</span>';
                                    } elseif ($er_disp == 'Admit') {
                                        $status_badge = '<span class="st-badge st-er-wait"><i class="fa-solid fa-bed-pulse"></i> Admit : '.$row['admit_type'].'</span>';
                                    } elseif ($er_disp == 'Discharge') {
                                        $status_badge = '<span class="st-badge st-dc"><i class="fa-solid fa-house-circle-check"></i> D/C (GP)</span>';
                                    } else {
                                        $status_badge = '<span class="st-badge st-none"><i class="fa-solid fa-check"></i> ER ประเมินแล้ว</span>';
                                    }
                                }

                                // ถ้าไม่มีข้อมูลการวินิจฉัยจาก Ward เลย ให้ใช้ของ ER
                                if ($final_dx_display === '-' || empty($final_dx_display)) {
                                    $final_dx_display = !empty($row['FstDx']) ? $row['FstDx'] : '-';
                                }

                                // จัด Format HTML ให้สวยงาม
                                if ($final_dx_display === '-' || empty($final_dx_display)) {
                                    $final_dx_html = "<span class='text-muted small'>-</span>";
                                } else {
                                    $final_dx_html = "<div class='fw-bold text-success' style='font-size: 0.85rem;'>".htmlspecialchars($final_dx_display)."</div>";
                                }

                            ?>
                            <tr onclick="window.location='../view_patient_full_history.php?hn=<?php echo urlencode((string)$row['hn']); ?>'">
                                <td class="text-center align-middle"><span class="row-num"><?php echo $rowNum++; ?></span></td>
                                <td class="align-middle">
                                    <span class="date-text">
                                        <i class="fa-regular fa-calendar"></i>
                                        <?php echo !empty($er_date) ? date('d/m/Y', strtotime($er_date)) : '-'; ?><br>
                                        <small><i class="fa-regular fa-clock me-1"></i><?php echo !empty($er_date) ? date('H:i', strtotime($er_date)) : '-'; ?> น.</small>
                                    </span>
                                </td>
                                <td class="align-middle">
                                    <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                        <span class="hn-chip">HN: <?php echo htmlspecialchars($row['hn']); ?></span>
                                        <?php if($an_text !== '-'): ?>
                                            <span class="hn-chip" style="background: #e0f2fe; border-color: #bae6fd; color: #0284c7;">AN: <?php echo htmlspecialchars($an_text); ?></span>
                                        <?php else: ?>
                                            <span class="hn-chip" style="background: #f3f4f6; border-color: #e5e7eb; color: #9ca3af;">AN: -</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <div class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></div>
                                    <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['person_type'] ?? '-'); ?></div>
                                    <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars($row['department'] ?? '-'); ?></div>
                                </td>
                                
                                <td class="align-middle">
                                        <!--
                                            <div class="er-dx-text mb-2" title="<?php echo htmlspecialchars($er_dx); ?>">
                                        <strong class="text-danger">DX:</strong> <?php echo htmlspecialchars($er_dx_short); ?>
                                    </div>
                                        -->
                                    <div class="small text-muted mb-1" style="font-size: 0.8rem;">
                                        <i class="fa-solid fa-temperature-half text-warning me-1"></i>Temp: <strong class="text-dark"><?php echo $ct_init; ?></strong> / 30m: <strong class="text-dark"><?php echo $ct_30; ?></strong>
                                    </div>
                                    <div class="small text-muted mb-1" style="font-size: 0.8rem;" title="<?php echo htmlspecialchars($first_aid); ?>">
                                        <i class="fa-solid fa-kit-medical text-primary me-1"></i>ปฐมพยาบาล: <span class="text-dark"><?php echo htmlspecialchars(mb_strimwidth($first_aid, 0, 25, '...')); ?></span>
                                    </div>
                                    <div class="small text-muted mb-1" style="font-size: 0.8rem;" title="<?php echo htmlspecialchars($cause); ?>">
                                        <i class="fa-solid fa-sun text-danger me-1"></i>ปัจจัย: <span class="text-dark"><?php echo htmlspecialchars(mb_strimwidth($cause, 0, 25, '...')); ?></span>
                                    </div>
                                    <div class="small text-muted" style="font-size: 0.8rem;" title="<?php echo htmlspecialchars($risk); ?>">
                                        <i class="fa-solid fa-triangle-exclamation text-danger me-1"></i>เสี่ยง: <span class="text-dark"><?php echo htmlspecialchars(mb_strimwidth($risk, 0, 25, '...')); ?></span>
                                    </div>
                                </td>

                                <td class="text-center align-middle text-success"><?php echo $row['FstDx']; ?></td>
                                <td class="text-center align-middle"><?php echo $journey_html; ?></td>
                                <td class="text-center align-middle"><?php echo $status_badge; ?></td>
                                <td class="text-center align-middle" onclick="event.stopPropagation()">
                                    <a href="../view_patient_full_history.php?hn=<?php echo urlencode((string)$row['hn']); ?>" class="btn-view">
                                        <i class="fa-solid fa-folder-open"></i> เปิดแฟ้ม
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
                                    <p>ลองปรับเงื่อนไขการค้นหาแล้วลองใหม่อีกครั้ง</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
            <span class="pagination-info">
                แสดง <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $limit, $totalRows)); ?>
                จาก <?php echo number_format($totalRows); ?> รายการ &nbsp;·&nbsp; หน้า <?php echo $page; ?>/<?php echo $totalPages; ?>
            </span>
            <nav>
                <ul class="pagination">
                    <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&search_date=<?php echo $search_date; ?>">
                            <i class="fa-solid fa-chevron-left" style="font-size:.7rem;"></i>
                        </a>
                    </li>
                    <?php
                    $range = 2;
                    for ($i = 1; $i <= $totalPages; $i++) {
                        if ($i == 1 || $i == $totalPages || ($i >= $page - $range && $i <= $page + $range)) {
                            $active = ($i == $page) ? 'active' : '';
                            echo "<li class='page-item $active'><a class='page-link' href='?page=$i&search=".urlencode($search)."&search_date=$search_date'>$i</a></li>";
                        } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                            echo "<li class='page-item disabled'><span class='page-link' style='font-size:.8rem;'>···</span></li>";
                        }
                    }
                    ?>
                    <li class="page-item <?php if($page >= $totalPages) echo 'disabled'; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&search_date=<?php echo $search_date; ?>">
                            <i class="fa-solid fa-chevron-right" style="font-size:.7rem;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

    </div><!-- /main-card -->
</div><!-- /page-wrap -->

</body>
<?php require_once '../footer.php'; ?>
</html>