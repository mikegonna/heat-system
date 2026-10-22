<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';
require_once '../database/fiscal_year.php';

// --- FILTER & PAGINATION ---
$search = isset($_GET['search']) ? $_GET['search'] : '';
$fiscal_date_range = getFiscalDateRange($_GET);
$start_date = $fiscal_date_range['start_date'];
$end_date = $fiscal_date_range['end_date'];
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'ongoing';

// ตั้งค่าหน้า
$limit = 10;
$page_w = isset($_GET['page_w']) ? max(1, (int)$_GET['page_w']) : 1;
$page_h = isset($_GET['page_h']) ? max(1, (int)$_GET['page_h']) : 1;
$offset_w = ($page_w - 1) * $limit;
$offset_h = ($page_h - 1) * $limit;

// เงื่อนไขการค้นหา
$filterSQL = "";
if (!empty($search)) {
    $filterSQL .= " AND (c.hn LIKE '%$search%' OR w.an LIKE '%$search%' OR c.patient_name LIKE '%$search%') ";
}
$filterSQL .= " AND DATE(COALESCE(w.discharge_date, w.admit_date)) BETWEEN '$start_date' AND '$end_date' ";
$severeDiagnosisSQL = "COALESCE(NULLIF(w.final_diagnosis, ''), NULLIF(w.icu_diagnosis, ''), NULLIF(w.initial_diagnosis, ''))
                       REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis[[:space:]]*[(]CPK[[:space:]]*>[[:space:]]*1,?000[)]'";

// ---------------------------------------------------------------------------
// Helper Function: สร้าง Timeline (ใหม่ - ล่าสุดอยู่บนสุด)
// ---------------------------------------------------------------------------
function getPatientTimeline($conn, $case_id) {
    $steps = [];
    
    $qER = $conn->query("SELECT id, disposition, admit_type FROM er_details WHERE case_id = '$case_id' ORDER BY id DESC LIMIT 1");
    $er_data = null;
    if ($qER && $qER->num_rows > 0) { 
        $er_data = $qER->fetch_assoc();
        $steps[] = ['type' => 'er', 'label' => 'ER', 'icon' => 'fa-truck-medical']; 
    }
    
    $qWard = $conn->query("SELECT admit_type, status, discharge_status FROM ward_details WHERE case_id = '$case_id' ORDER BY id ASC");
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
            
            $steps[] = ['type' => $j_class, 'label' => $lbl, 'icon' => 'fa-bed-pulse'];
            
            if ($w['status'] == 'Discharged') { 
                if ($w['discharge_status'] == 'Dead') {
                    $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
                } elseif ($w['discharge_status'] == 'Refer') {
                    $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
                } else {
                    $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check']; 
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
    
    if(empty($steps)) return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';

    // 💡 REVERSE ARRAYS: เพื่อให้สถานะล่าสุดอยู่บนสุด
    $steps = array_reverse($steps);

    $html = '<div class="journey-box d-flex flex-column align-items-center gap-1">';
    foreach ($steps as $index => $step) {
        $html .= '<span class="j-step j-' . $step['type'] . ' text-center w-100" style="max-width: 120px;"><i class="fa-solid ' . $step['icon'] . ' me-1"></i> ' . $step['label'] . '</span>';
        if ($index < count($steps) - 1) {
            $html .= '<i class="fa-solid fa-arrow-up j-arrow my-1" style="font-size: 0.65rem; color: #cbd5e1;"></i>';
        }
    }
    $html .= '</div>';
    
    return $html;
}

// ---------------------------------------------------------------------------
// QUERY 1: รอติดตามอาการ (Ongoing / Waitlist)
// ---------------------------------------------------------------------------
$baseWait = "FROM ward_details w
             JOIN cases c ON w.case_id = c.id
             LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
             WHERE w.status = 'Discharged' 
             AND $severeDiagnosisSQL
             AND (SELECT m.pmr FROM med_records m
                  WHERE m.an = w.an
                  ORDER BY m.record_datetime DESC, m.id DESC
                  LIMIT 1) = '1'
             AND EXISTS (SELECT 1 FROM occ_records o WHERE o.an = w.an)
             AND (SELECT COUNT(*) FROM pmr_records pr WHERE pr.an = w.an) = 0
             $filterSQL";

$qCountWait = $conn->query("SELECT COUNT(DISTINCT w.id) as total_w " . $baseWait);
$total_w = $qCountWait ? $qCountWait->fetch_assoc()['total_w'] : 0;
$totalPages_w = ceil($total_w / $limit);

$sqlWait = "SELECT w.case_id, w.an, w.discharge_date, w.status as ward_status, w.admit_date, w.admit_type as current_ward,
               c.hn as hn, c.patient_name, c.age, c.phone, c.department, c.person_type,
               e.cause_factor, e.risk_factors, e.FstDx as er_dx, e.recorded_at as er_time,
               w.icu_diagnosis, w.final_diagnosis, w.initial_diagnosis,
               (SELECT COUNT(*) FROM pmr_records pr WHERE pr.an = w.an) as track_count
            $baseWait
            GROUP BY w.id
            ORDER BY w.discharge_date DESC
            LIMIT $offset_w, $limit";
$resWait = $conn->query($sqlWait);

// ---------------------------------------------------------------------------
// QUERY 2: ประวัติการติดตาม (History)
// ---------------------------------------------------------------------------
$baseHist = "FROM ward_details w
             JOIN cases c ON w.case_id = c.id
             LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
             WHERE w.status = 'Discharged'
             AND $severeDiagnosisSQL
             AND (SELECT COUNT(*) FROM pmr_records pr WHERE pr.an = w.an) > 0
             $filterSQL";

$qCountHist = $conn->query("SELECT COUNT(DISTINCT w.id) as total_h " . $baseHist);
$total_h = $qCountHist ? $qCountHist->fetch_assoc()['total_h'] : 0;
$totalPages_h = ceil($total_h / $limit);

$sqlHist = "SELECT w.case_id, w.an, w.discharge_date, w.status as ward_status, w.admit_date, w.admit_type as current_ward,
               c.hn as hn, c.patient_name, c.age, c.phone, c.department, c.person_type,
               e.cause_factor, e.risk_factors, e.FstDx as er_dx, e.recorded_at as er_time,
               w.icu_diagnosis, w.final_diagnosis, w.initial_diagnosis,
               (SELECT COUNT(*) FROM pmr_records pr WHERE pr.an = w.an) as track_count,
               (SELECT MAX(record_datetime) FROM pmr_records pr WHERE pr.an = w.an) as last_track,
               (SELECT pmr_status FROM pmr_records pr WHERE pr.an = w.an ORDER BY record_datetime DESC LIMIT 1) as pmr_status,
               (SELECT next_pmr_date FROM pmr_records pr WHERE pr.an = w.an ORDER BY record_datetime DESC LIMIT 1) as latest_next_date
            $baseHist
            GROUP BY w.id
            ORDER BY last_track DESC
            LIMIT $offset_h, $limit";
$resHist = $conn->query($sqlHist);

function getPaginationUrl($tab, $page_w, $page_h, $search, $start_date, $end_date) {
    return "?tab={$tab}&page_w={$page_w}&page_h={$page_h}&search=" . urlencode($search) . "&start_date={$start_date}&end_date={$end_date}";
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบนัดติดตามอาการ (PMR)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --v-primary: #8b5cf6; /* สีม่วง PMR */
            --v-primary-hover: #7c3aed;
            --v-bg: #f5f3ff; 
            --v-light: #ede9fe;
            --v-text: #0f172a; 
            --border-soft: #ddd6fe;
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--v-bg); color: var(--v-text); }
        h1, h2, h3, h4, h5, .btn, th, .nav-link { font-family: 'Prompt', sans-serif; }
        
        .page-header { 
            background: white; padding: 20px 30px; border-radius: 15px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-bottom: 25px; 
            border-left: 6px solid var(--v-primary); 
            display: flex; justify-content: space-between; align-items: center; 
        }

        .nav-tabs { border-bottom: 2px solid #dee2e6; flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
        .nav-tabs::-webkit-scrollbar { display: none; }
        .nav-tabs .nav-link { border: none; color: #6c757d; font-weight: 500; padding: 12px 25px; border-radius: 10px 10px 0 0; transition: all 0.3s ease; white-space: nowrap;}
        .nav-tabs .nav-link:hover { color: var(--v-primary); background-color: var(--v-light); }
        .nav-tabs .nav-link.active { color: #fff !important; background-color: var(--v-primary) !important; box-shadow: 0 -4px 12px rgba(139, 92, 246, 0.2); }
        
        .card-visit { border: none; border-radius: 0 15px 15px 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.05); overflow: hidden; background: #fff;}
        
        /* ── TABLE STYLES ── */
        .registry-table { margin: 0; }
        .registry-table thead th {
            font-family: 'Prompt', sans-serif; font-size: .8rem; font-weight: 600;
            letter-spacing: .03em; text-transform: uppercase; color: #fff;
            background: var(--v-primary); border-bottom: none; border-top: none; padding: 16px;
        }
        .registry-table tbody td { padding: 18px 16px; vertical-align: middle; border-bottom: 1px solid #f3f4f6; font-size: .9rem; }
        .registry-table tbody tr:last-child td { border-bottom: none; }
        .registry-table tbody tr { transition: background .15s; }
        .registry-table tbody tr:hover { background: #faf5ff; }
        
        .row-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; background: #f3f4f6; border-radius: 8px;
            font-size: .8rem; font-weight: 600; color: #64748b;
        }
        
        .btn-theme { background-color: var(--v-primary); color: white; border-radius: 8px; padding: 6px 15px; transition: 0.2s; border: none;}
        .btn-theme:hover { background-color: var(--v-primary-hover); color: white !important; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(139, 92, 246, 0.2);}
        
        .btn-outline-theme { background-color: transparent; color: var(--v-primary); border: 1.5px solid var(--v-primary); border-radius: 8px; padding: 6px 15px; transition: 0.2s; font-weight: 600;}
        .btn-outline-theme:hover { background-color: var(--v-light); color: var(--v-primary-hover); transform: translateY(-1px); }
        
        .text-theme { color: var(--v-primary) !important; }

        /* ── CHIPS & LABELS ── */
        .hn-chip {
            display: inline-block; font-family: 'Prompt', sans-serif; font-size: .85rem; font-weight: 700;
            color: var(--v-primary-hover); background: var(--v-light); border: 1px solid var(--border-soft);
            padding: 3px 10px; border-radius: 6px; letter-spacing: .03em; margin-bottom: 4px;
        }
        .patient-name { font-weight: 700; color: var(--v-text); font-size: 1rem; }
        .patient-type { display: inline-block; margin-top: 4px; font-size: .75rem; color: #64748b; }
        .dept-text { font-size: .82rem; color: #64748b; display: block; margin-top: 2px;}

        .er-dx-text {
            font-size: 0.85rem; color: #374151; background: #f8fafc;
            padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border-soft);
            display: inline-block; max-width: 100%; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px;
        }

        /* ── JOURNEY TIMELINE ── */
        .journey-box { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 4px; margin-top: 2px; }
        .j-step { 
            padding: 4px 10px; border-radius: 50px; font-weight: 600; font-family: 'Prompt', sans-serif; 
            font-size: 0.72rem; white-space: nowrap; border: 1px solid transparent; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .j-arrow { color: #cbd5e1; font-size: 0.65rem; }
        .j-er      { background: #fff7ed; color: #c2410c; border-color: #fdba74; } 
        .j-icu     { background: #fef2f2; color: #b91c1c; border-color: #fecaca; } 
        .j-rcu     { background: #fffbeb; color: #b45309; border-color: #fde68a; } 
        .j-a4      { background: #f5f3ff; color: #6d28d9; border-color: #ede9fe; } 
        .j-b3      { background: #f0fdfa; color: #0f766e; border-color: #ccfbf1; } 
        .j-default { background: #f1f5f9; color: #475569; border-color: #cbd5e1; } 

        .st-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 50px;
            font-family: 'Prompt', sans-serif; font-size: .75rem; font-weight: 600; white-space: nowrap;
        }

        @keyframes pulse-shake {
            0% { transform: scale(1); }
            10% { transform: scale(1.05) rotate(2deg); }
            20% { transform: scale(1.05) rotate(-2deg); }
            30% { transform: scale(1) rotate(0); }
            100% { transform: scale(1); }
        }
        .shake-alert { animation: pulse-shake 2s infinite ease-in-out; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }

        /* ── Pagination ── */
        .pagination { margin: 0; }
        .pagination .page-link { color: var(--v-primary); border-radius: 8px; margin: 0 3px; border: 1px solid var(--border-soft); font-family: 'Prompt', sans-serif; font-size: 0.85rem; font-weight: 500;}
        .pagination .page-item.active .page-link { background-color: var(--v-primary); border-color: var(--v-primary); color: white; box-shadow: 0 3px 8px rgba(139, 92, 246, 0.2); }
        .pagination .page-item.disabled .page-link { color: #94a3b8; background-color: #f8fafc; border-color: #e2e8f0; pointer-events: none;}
        
        .empty-state { padding: 64px 20px; text-align: center; }
        .empty-state .empty-icon {
            width: 72px; height: 72px; background: #f3f4f6; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem; color: #d1d5db; margin: 0 auto 18px;
        }
        .empty-state h6 { font-size: 1rem; color: var(--v-text); margin-bottom: 6px; font-weight: 600; }
        .empty-state p  { font-size: .85rem; color: #64748b; margin: 0; }

        /* ==========================================
           🌟 MOBILE RESPONSIVE CSS (Card View)
           ========================================== */
        @media (max-width: 991px) {
            .page-header { flex-direction: column; align-items: flex-start; padding: 15px; }
            .page-header > div:last-child { width: 100%; margin-top: 15px; display: flex; justify-content: space-between; align-items: center; }
            
            .search-bar-wrap .row { flex-direction: column; }
            .search-bar-wrap .col-md-4, .search-bar-wrap .col-md-3, .search-bar-wrap .col-md-2 { width: 100%; margin-bottom: 10px; }

            .table-responsive { border: none; }
            
            /* แปลง <table> เป็น Block / Card */
            .registry-table, .registry-table tbody, .registry-table tr, .registry-table td { display: block; width: 100%; }
            .registry-table thead { display: none; } 
            
            .registry-table tr {
                margin-bottom: 15px;
                background-color: #fff;
                border: 1px solid var(--border-soft);
                border-radius: 12px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.03);
                padding: 10px;
                position: relative;
            }
            
            .registry-table td {
                text-align: left !important;
                padding: 10px 5px !important;
                border-bottom: 1px dashed #f1f3f5;
                position: relative;
            }
            .registry-table td:last-child { border-bottom: none; padding-bottom: 5px !important; }
            
            /* สร้าง Label ให้บนมือถือ */
            .registry-table td::before {
                content: attr(data-label);
                display: block;
                font-size: 0.8rem;
                font-weight: 600;
                color: var(--v-primary);
                margin-bottom: 5px;
            }

            /* ตำแหน่งตัวเลข # บนการ์ดมือถือ */
            .registry-table td[data-label="#"] { position: absolute; top: 10px; right: 10px; padding: 0 !important; border: none; width: auto; }
            .registry-table td[data-label="#"]::before { display: none; }

            /* ปรับปุ่มบนมือถือให้กดง่ายเต็มความกว้าง */
            .registry-table .btn-theme, .registry-table .btn-outline-theme { width: 100%; margin-top: 5px; padding: 10px; font-size: 1rem; }
            
            .journey-box { justify-content: flex-start; }
            .st-badge { width: 100%; justify-content: flex-start; padding: 8px 15px;}
            
            .card-footer { flex-direction: column !important; align-items: stretch !important; gap: 15px !important; }
            .pagination { justify-content: center; width: 100%; flex-wrap: wrap; }
        }
    </style>
    <script>
        function updateUrlTab(tabName) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
        }
    </script>
</head>
<body>

<?php 
    $path_prefix = "../"; 
    if(file_exists('../header_rehab.php')) require_once '../header_rehab.php'; 
    else echo "<nav class='navbar navbar-dark bg-primary shadow-sm'><div class='container'><a class='navbar-brand'>PMR Dashboard</a></div></nav>";
?>

<div class="container-fluid px-2 px-md-4 py-3 py-md-4" style="max-width: 1450px;">
    
    <div class="page-header">
        <div>
            <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-person-walking-with-cane me-2 text-theme"></i>ระบบนัดติดตามอาการ (PMR)</h4>
            <small class="text-muted mt-2 d-block"><i class="fa-solid fa-user-doctor me-1"></i> ผู้ใช้งาน: เวชศาสตร์ฟื้นฟู</small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span style="background-color: var(--v-primary); color: white;" class="badge rounded-pill px-4 py-2 fs-6 shadow-sm"><i class="fa-regular fa-calendar me-2"></i> ปีงบประมาณ พ.ศ. <?php echo $fiscal_date_range['year_label']; ?></span>
        </div>
    </div>

    <!-- กล่องค้นหา -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 15px;">
        <div class="card-body bg-white rounded search-bar-wrap" style="border-radius: 15px;">
            <form method="GET" action="">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted fw-bold">ค้นหา (ชื่อ, HN, AN)</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" placeholder="..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted fw-bold">ตั้งแต่วันที่ (Discharge)</label>
                        <input type="date" name="start_date" class="form-control shadow-sm" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted fw-bold">ถึงวันที่</label>
                        <input type="date" name="end_date" class="form-control shadow-sm" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-theme w-100 shadow-sm" style="height: 38px;"><i class="fa-solid fa-search me-1"></i> ค้นหา</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- แถบเมนู Tabs -->
    <ul class="nav nav-tabs mb-3 border-0 flex-nowrap overflow-auto" id="pmrTab" role="tablist" style="-webkit-overflow-scrolling: touch; padding-bottom: 2px;">
        <li class="nav-item">
            <button class="nav-link <?php echo ($active_tab == 'ongoing') ? 'active' : ''; ?> d-flex align-items-center text-nowrap" id="ongoing-tab" data-bs-toggle="tab" data-bs-target="#ongoing" type="button" onclick="updateUrlTab('ongoing')">
                <i class="fa-solid fa-spinner me-2"></i>รอการฟื้นฟู
                <?php if($total_w > 0): ?>
                    <span class="badge bg-warning text-dark rounded-pill ms-2 shadow-sm"><?php echo $total_w; ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?php echo ($active_tab == 'history') ? 'active' : ''; ?> d-flex align-items-center text-nowrap" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" onclick="updateUrlTab('history')">
                <i class="fa-solid fa-check-double me-2"></i>ประวัติการติดตาม 
            </button>
        </li>
    </ul>

    <div class="tab-content" id="pmrTabContent">
        
        <!-- 🔴 ONGOING TAB (รอติดตามอาการ) -->
        <div class="tab-pane fade <?php echo ($active_tab == 'ongoing') ? 'show active' : ''; ?>" id="ongoing" role="tabpanel">
            <div class="card card-visit">
                <div class="table-responsive border-bottom">
                    <table class="table registry-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="4%" class="text-center">#</th>
                                <th width="10%">HN / AN</th>
                                <th width="18%">ข้อมูลผู้ป่วย</th>
                                <th width="15%">วินิจฉัยแรกรับ</th>
                                <th width="12%">วินิจฉัยสุดท้าย</th>
                                <th width="15%" class="text-center">ไทม์ไลน์การรักษา</th>
                                <th width="18%">สถานะ</th>
                                <th width="8%" class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resWait && $resWait->num_rows > 0): ?>
                                <?php $rowNum_w = $offset_w + 1; ?>
                                <?php while($row = $resWait->fetch_assoc()): ?>
                                    <?php 
                                        $timelineHTML = getPatientTimeline($conn, $row['case_id']);
                                        $t_count = (int)$row['track_count'];
                                        
                                        $status_badge = "";
                                        $btn_text = "บันทึกฟื้นฟู";

                                        if ($t_count == 0) {
                                            $status_badge = '<div class="st-badge bg-warning text-dark shake-alert mt-2"><i class="fa-solid fa-clock me-1"></i> รอรับฟื้นฟูครั้งแรก</div>';
                                        } else {
                                            $status_badge = '<div class="st-badge bg-info text-dark mt-2"><i class="fa-solid fa-user-doctor me-1"></i> บันทึกแล้ว ('.$t_count.' ครั้ง)</div>';
                                        }
                                    ?>
                                    <tr>
                                        <td data-label="#" class="text-center"><span class="row-num"><?php echo $rowNum_w++; ?></span></td>
                                        <td data-label="HN / AN">
                                            <div class="hn-chip">HN: <?php echo htmlspecialchars($row['hn']); ?></div>
                                            <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #475569;">AN: <?php echo htmlspecialchars($row['an']); ?></div>
                                        </td>
                                        <td data-label="ข้อมูลผู้ป่วย">
                                            <div class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></div>
                                            <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['person_type'] ?? '-'); ?></div>
                                            <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars($row['department'] ?? '-'); ?></div>
                                        </td>
                                        <td data-label="วินิจฉัยแรกรับ">
                                            <div class="d-flex flex-column gap-1">
                                                <?php if(!empty($row['er_dx'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['er_dx']); ?>"><strong class="text-danger">ER:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['er_dx'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['icu_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['icu_diagnosis']); ?>"><strong class="text-warning" style="color: #b45309 !important;">ICU:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['icu_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(empty($row['er_dx']) && empty($row['icu_diagnosis'])) echo "<span class='text-muted small'>-</span>"; ?>
                                            </div>
                                        </td>
                                        <td data-label="วินิจฉัยสุดท้าย">
                                            <div class="fw-bold text-dark fs-6"><?php echo !empty($row['final_diagnosis']) ? htmlspecialchars($row['final_diagnosis']) : '-'; ?></div>
                                        </td>
                                        <td data-label="ไทม์ไลน์การรักษา" class="text-center align-middle">
                                            <?php echo $timelineHTML; ?>
                                            <div class="mt-2 text-danger small fw-bold"><i class="fa-regular fa-clock me-1"></i> D/C: <?php echo date('d/m/y', strtotime($row['discharge_date'])); ?></div>
                                        </td>
                                        <td data-label="สถานะ" class="align-middle">
                                            <?php echo $status_badge; ?>
                                        </td>
                                        <td data-label="จัดการ" class="text-center align-middle">
                                            <?php 
                                                $pmr_url = "formPMR.php?hn=" . urlencode($row['hn']) . "&an=" . urlencode($row['an']); 
                                            ?>
                                            <a href="<?php echo htmlspecialchars($pmr_url); ?>" class="btn btn-theme text-white btn-sm rounded-pill px-3 shadow-sm fw-bold w-100">
                                                <i class="fa-solid fa-person-walking-with-cane me-1"></i> <?php echo $btn_text; ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <div class="empty-icon"><i class="fa-solid fa-file-medical"></i></div>
                                            <h6>ไม่พบข้อมูล</h6>
                                            <p>ไม่พบผู้ป่วยที่ถูกส่งต่อเวชศาสตร์ฟื้นฟูในระบบ</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination (Ongoing Tab) -->
                <?php if ($totalPages_w > 1): ?>
                <div class="card-footer bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-center border-0 gap-3">
                    <small class="text-muted fw-bold">แสดง <?php echo $offset_w + 1; ?> ถึง <?php echo min($offset_w + $limit, $total_w); ?> จากทั้งหมด <?php echo $total_w; ?> รายการ</small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php if($page_w <= 1) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="<?php echo getPaginationUrl('ongoing', $page_w - 1, $page_h, $search, $start_date, $end_date); ?>"><i class="fa-solid fa-chevron-left me-1"></i> ย้อนกลับ</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link fw-bold text-dark bg-light border-0">หน้า <?php echo $page_w; ?> / <?php echo $totalPages_w; ?></span></li>
                            <li class="page-item <?php if($page_w >= $totalPages_w) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="<?php echo getPaginationUrl('ongoing', $page_w + 1, $page_h, $search, $start_date, $end_date); ?>">ถัดไป <i class="fa-solid fa-chevron-right ms-1"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- 🟢 HISTORY TAB (ประวัติการติดตาม) -->
        <div class="tab-pane fade <?php echo ($active_tab == 'history') ? 'show active' : ''; ?>" id="history" role="tabpanel">
            <div class="card card-visit">
                <div class="table-responsive border-bottom">
                    <table class="table registry-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="4%" class="text-center">#</th>
                                <th width="10%">HN / AN</th>
                                <th width="18%">ข้อมูลผู้ป่วย</th>
                                <th width="15%">วินิจฉัยแรกรับ</th>
                                <th width="12%">วินิจฉัยสุดท้าย</th>
                                <th width="15%" class="text-center">ไทม์ไลน์การรักษา</th>
                                <th width="18%" class="text-center">สถานะล่าสุด</th>
                                <th width="8%" class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resHist && $resHist->num_rows > 0): ?>
                                <?php $rowNum_h = $offset_h + 1; ?>
                                <?php while($row = $resHist->fetch_assoc()): ?>
                                    <?php 
                                        $timelineHTML = getPatientTimeline($conn, $row['case_id']);
                                        $hist_badge = '<div class="st-badge bg-success text-white shadow-sm mt-2"><i class="fa-solid fa-check-circle me-1"></i> ประเมินแล้ว</div>';
                                    ?>
                                    <tr>
                                        <td data-label="#" class="text-center"><span class="row-num"><?php echo $rowNum_h++; ?></span></td>
                                        <td data-label="HN / AN">
                                            <div class="hn-chip">HN: <?php echo htmlspecialchars($row['hn']); ?></div>
                                            <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #475569;">AN: <?php echo htmlspecialchars($row['an']); ?></div>
                                        </td>
                                        <td data-label="ข้อมูลผู้ป่วย">
                                            <div class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></div>
                                            <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['person_type'] ?? '-'); ?></div>
                                            <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars($row['department'] ?? '-'); ?></div>
                                        </td>
                                        <td data-label="วินิจฉัยแรกรับ">
                                            <div class="d-flex flex-column gap-1">
                                                <?php if(!empty($row['er_dx'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['er_dx']); ?>"><strong class="text-danger">ER:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['er_dx'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['icu_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['icu_diagnosis']); ?>"><strong class="text-warning" style="color: #b45309 !important;">ICU:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['icu_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(empty($row['er_dx']) && empty($row['icu_diagnosis'])) echo "<span class='text-muted small'>-</span>"; ?>
                                            </div>
                                        </td>
                                        <td data-label="วินิจฉัยสุดท้าย">
                                            <div class="fw-bold text-dark fs-6"><?php echo !empty($row['final_diagnosis']) ? htmlspecialchars($row['final_diagnosis']) : '-'; ?></div>
                                        </td>
                                        <td data-label="ไทม์ไลน์การรักษา" class="text-center align-middle">
                                            <?php echo $timelineHTML; ?>
                                        </td>
                                        <td data-label="สถานะล่าสุด" class="text-center align-middle">
                                            <div class="mb-1">
                                                <?php echo $hist_badge; ?>
                                            </div>
                                            <div class="fw-bold small text-theme mt-2 bg-theme-light p-1 rounded border d-inline-block px-2 text-md-center w-100" style="border-color: var(--v-light) !important;">
                                                <i class="fa-solid fa-clock-rotate-left me-1"></i>บันทึกล่าสุด:<br><?php echo date('d/m/y', strtotime($row['last_track'])); ?>
                                            </div>
                                        </td>
                                        <td data-label="จัดการ" class="text-center align-middle">
                                            <?php 
                                                $hist_url = "formPMR.php?hn=" . urlencode($row['hn']) . "&an=" . urlencode($row['an']); 
                                            ?>
                                            <a href="<?php echo htmlspecialchars($hist_url); ?>" class="btn btn-outline-theme btn-sm rounded-pill px-3 shadow-sm w-100">
                                                <i class="fa-solid fa-folder-open me-1"></i> ประวัติ
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <div class="empty-icon"><i class="fa-regular fa-folder-open"></i></div>
                                            <h6>ไม่พบข้อมูล</h6>
                                            <p>ยังไม่มีประวัติการบันทึกข้อมูลฟื้นฟู</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination (History Tab) -->
                <?php if ($totalPages_h > 1): ?>
                <div class="card-footer bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-center border-0 gap-3">
                    <small class="text-muted fw-bold">แสดง <?php echo $offset_h + 1; ?> ถึง <?php echo min($offset_h + $limit, $total_h); ?> จากทั้งหมด <?php echo $total_h; ?> รายการ</small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php if($page_h <= 1) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="<?php echo getPaginationUrl('history', $page_w, $page_h - 1, $search, $start_date, $end_date); ?>"><i class="fa-solid fa-chevron-left me-1"></i> ย้อนกลับ</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link fw-bold text-dark bg-light border-0">หน้า <?php echo $page_h; ?> / <?php echo $totalPages_h; ?></span></li>
                            <li class="page-item <?php if($page_h >= $totalPages_h) echo 'disabled'; ?>">
                                <a class="page-link shadow-sm" href="<?php echo getPaginationUrl('history', $page_w, $page_h + 1, $search, $start_date, $end_date); ?>">ถัดไป <i class="fa-solid fa-chevron-right ms-1"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

</body>
<?php if(file_exists('../footer.php')) require_once '../footer.php'; ?>
</html>