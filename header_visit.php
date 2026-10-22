<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($path_prefix)) { $path_prefix = ""; }

$root_path = $path_prefix != "" ? $path_prefix : "../"; 
$db_paths = [$root_path . 'database/db_conn.php', 'database/db_conn.php', '../database/db_conn.php'];
foreach ($db_paths as $path) { if (file_exists($path)) { require_once $path; break; } }
require_once __DIR__ . '/database/fiscal_year.php';
$fiscal_date_range = getFiscalDateRange($_GET);
$visit_service = strtolower(trim($_SESSION['name'] ?? '')) === 'coc' ? 'COC' : 'PCU';

// 💡 ดึงเคสรอเยี่ยมบ้าน (เฉพาะที่จำหน่ายแล้วเท่านั้น)
$incoming_visit = [];
if (isset($conn)) {
    $sql = "SELECT c.hn, c.patient_name, w.an,
               e.recorded_at as er_time, w.admit_date, w.discharge_date,
               e.disposition as er_status, w.status as ward_status, w.admit_type as current_ward,
               COALESCE(w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis, e.FstDx, '') as diagnosis,
               (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) as visit_count
        FROM cases c
        LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
        LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = c.id)
        WHERE 
            IFNULL(e.disposition, '') NOT IN ('Dead', 'Refer')
            AND IFNULL(w.discharge_status, '') NOT IN ('Dead', 'Refer')
            AND (e.id IS NOT NULL OR w.id IS NOT NULL)
            AND DATE(COALESCE(w.discharge_date, e.recorded_at, c.created_at)) BETWEEN '{$fiscal_date_range['start_date']}' AND '{$fiscal_date_range['end_date']}'
            AND (
                (w.id IS NOT NULL AND w.status = 'Discharged')
                OR (w.id IS NULL AND e.id IS NOT NULL AND e.disposition = 'Discharge')
            )
            -- กรองเฉพาะ Heat
            AND COALESCE(w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis, e.FstDx, '')
                REGEXP 'Heat Stroke|Heat Exhaustion|Heat Syncope|Heat Cramps|Heat Tetany|Heat Edema|Prickly Heat|Rhabdomyolysis'
            -- เยี่ยมยังไม่ครบตาม severity
            AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) < (
                CASE 
                    WHEN COALESCE(w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis, e.FstDx, '')
                        REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
                    THEN 2  -- Severe
                    ELSE 1  -- Moderate
                END
            )
        ORDER BY COALESCE(w.discharge_date, e.recorded_at) DESC";
                
    $result = $conn->query($sql);
    if($result) while($row = $result->fetch_assoc()) $incoming_visit[] = $row;
}
$visit_count = count($incoming_visit);
?>

<style>
    /* Visit Theme: Soft Elegant Pink (ชมพูละมุนหรูหรา) */
    :root {
        --v-primary: #d6728d; /* สีชมพูหลัก */
        --v-dark: #b95c76;    /* สีชมพูเข้มสำหรับ Hover/Gradient */
        --v-light: #f2cad7;   /* สีชมพูอ่อนสำหรับขอบและพื้นหลัง */
        --v-soft: #fdf2f8;    /* สีชมพูอ่อนมากสำหรับ Hover บนมือถือ */
    }

    .navbar-visit-theme { 
        background: linear-gradient(135deg, var(--v-dark) 0%, var(--v-primary) 50%, var(--v-light) 100%); 
        border-bottom: 1px solid rgba(255, 255, 255, 0.1); 
        padding: 0.8rem 1rem; 
    }
    .nav-link-custom { 
        color: rgba(255, 255, 255, 0.9) !important; 
        font-weight: 500; 
        border-radius: 50px; 
        padding: 8px 18px !important; 
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); 
        margin: 0 4px;
    }
    .nav-link-custom:hover { 
        background-color: rgba(255, 255, 255, 0.15); 
        transform: translateY(-2px); 
    }
    .nav-link-custom.active { 
        background-color: #ffffff; 
        color: var(--v-primary) !important; 
        font-weight: 700; 
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    }
    
    /* User Profile Pill */
    .user-pill-visit {
        background: rgba(0, 0, 0, 0.15);
        border-radius: 50px;
        padding: 6px 16px 6px 6px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.3s ease;
        backdrop-filter: blur(5px);
    }
    .user-pill-visit:hover {
        background: rgba(0, 0, 0, 0.25);
        border-color: rgba(255, 255, 255, 0.4);
    }

    /* กระดิ่งสั่นเมื่อมีเคส */
    @keyframes bell-shake {
        0%   { transform: rotate(0); }
        10%  { transform: rotate(15deg); }
        20%  { transform: rotate(-10deg); }
        30%  { transform: rotate(10deg); }
        40%  { transform: rotate(-10deg); }
        50%  { transform: rotate(0); }
        100% { transform: rotate(0); }
    }
    .bell-active { 
        display: inline-block;
        transform-origin: top center;
        animation: bell-shake 1.5s infinite; 
        color: #fffbeb !important; 
    }

    .dropdown-menu-modern {
        border: none;
        border-radius: 16px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        padding: 0;
        overflow: hidden;
        margin-top: 15px !important;
        border: 1px solid rgba(0,0,0,0.05);
    }
    
    .noti-dropdown { width: 380px; }
    
    /* การ์ดรายการเคสใน Dropdown */
    .case-card { transition: all 0.2s; border-left: 4px solid transparent; }
    .case-card:hover { background-color: var(--v-soft); border-left: 4px solid var(--v-primary); }

    /* Scrollbar สำหรับ Dropdown */
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: var(--v-primary); }

    /* ==========================================
       🌟 MOBILE RESPONSIVE CSS
       ========================================== */
    @media (max-width: 991px) {
        .navbar-collapse {
            background-color: #ffffff;
            border-radius: 15px;
            padding: 15px;
            margin-top: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid #f1f5f9;
        }
        .nav-link-custom {
            color: var(--v-dark) !important;
            margin: 4px 0;
            padding: 12px 20px !important;
            border-radius: 10px;
            display: block;
        }
        .nav-link-custom:hover, .nav-link-custom.active {
            background-color: var(--v-soft);
            color: var(--v-dark) !important;
            box-shadow: none;
            transform: none;
        }
        .navbar-nav.ms-auto {
            border-top: 1px solid #f1f5f9;
            margin-top: 10px;
            padding-top: 15px;
            align-items: stretch !important;
            gap: 10px !important;
        }
        .bell-link {
            display: flex !important;
            align-items: center;
            background-color: var(--v-soft);
            color: var(--v-dark) !important;
            border-radius: 10px;
            padding: 12px 20px !important;
            text-decoration: none;
        }
        .bell-link i { color: var(--v-dark) !important; margin-right: 10px; }
        .bell-link .badge {
            position: static !important;
            transform: none !important;
            margin-left: auto;
        }
        .bell-active { color: var(--v-dark) !important; }
        .noti-dropdown { width: 100% !important; margin-top: 5px !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .user-pill-visit {
            background-color: #f8fafc !important;
            color: var(--v-dark) !important;
            border-radius: 10px;
            padding: 10px 20px !important;
            border: 1px solid #e2e8f0;
            justify-content: flex-start;
        }
        .user-avatar { background-color: var(--v-primary) !important; color: white !important; }
        .user-pill-visit:hover { background-color: #f1f5f9 !important; border-color: #cbd5e1; }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-visit-theme shadow-sm mb-4">
  <div class="container-fluid px-3">
    <a class="navbar-brand d-flex align-items-center gap-3" href="<?php echo $path_prefix; ?>dashboard.php">
        <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px;">
            <img src="<?php echo $path_prefix; ?>img/AnanLogo.png" width="50" alt="Logo">
        </div>
        <div class="d-flex flex-column text-white">
            <span class="fw-bolder" style="font-size: 1.25rem; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);"><?php echo $visit_service; ?></span>
            <span style="font-size: 0.75rem; opacity: 0.9; font-weight: 300;">ระบบติดตามเยี่ยมบ้าน</span>
        </div>
    </a>
    
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarVisit">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarVisit">
      <ul class="navbar-nav me-auto ms-lg-4 gap-1">
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>dashboard.php">
                <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ด
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'indexVisit.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>Visit/indexVisit.php">
                <i class="fa-solid fa-house-chimney-medical me-1"></i> จัดการเยี่ยมบ้าน
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'history_centralVisit.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>Visit/history_centralVisit.php">
                <i class="fa-solid fa-address-book me-1"></i> ประวัติการเยี่ยม
            </a>
        </li>
      </ul>
      
      <ul class="navbar-nav ms-auto align-items-center gap-3">
            <li class="nav-item dropdown me-lg-3 w-100">
                <a class="nav-link text-white position-relative p-2 bell-link" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-bell fa-lg <?php echo ($visit_count > 0) ? 'bell-active' : ''; ?>"></i>
                    <span class="d-lg-none fw-bold text-dark">รายการที่ต้องติดตาม</span>
                    <?php if ($visit_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark shadow-sm fw-bold">
                            <?php echo $visit_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern noti-dropdown">
                    <li class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-house-user text-theme me-2"></i>รายการที่ต้องติดตาม (<?php echo $visit_service; ?>)</h6>
                        <span class="badge bg-warning text-dark rounded-pill px-2 py-1"><?php echo $visit_count; ?></span>
                    </li>
                    <div class="custom-scrollbar" style="max-height: 350px; overflow-y: auto;">
                    <?php if ($visit_count > 0): ?>
                        <?php foreach ($incoming_visit as $case): ?>
                            <?php 
                                $status_text = "";
                                $time_text = "";
                                $v_count = (int)$case['visit_count'];
                                $is_severe = preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)/i', $case['diagnosis']);
                                $max_visits = $is_severe ? 2 : 1;
                                

                                if ($v_count == 0) {
                                    $status_text = '<span class="badge bg-success text-white border">
                                        <i class="fa-solid fa-house-circle-check me-1"></i> รอเยี่ยมครั้งแรก
                                    </span>';
                                } else {
                                    $status_text = '<span class="badge bg-info text-dark border">
                                        <i class="fa-solid fa-house-chimney-medical me-1"></i> เยี่ยมแล้ว '.$v_count.'/'.$max_visits.' ครั้ง
                                    </span>';
                                }

                                if (!empty($case['discharge_date'])) {
                                    // จำหน่ายจาก Ward
                                    if ($v_count > 0) {
                                        $status_text = '<span class="badge bg-info text-dark border"><i class="fa-solid fa-house-chimney-medical me-1"></i> เยี่ยม ('.$v_count.'/'.$max_visits.')</span>';
                                    } else {
                                        $status_text = '<span class="badge bg-success text-white border"><i class="fa-solid fa-house-circle-check me-1"></i> รอเยี่ยมครั้งแรก</span>';
                                    }
                                    $time_text = "D/C: " . date('d/m/y', strtotime($case['discharge_date']));
                                } else {
                                    // จำหน่ายจาก ER (ไม่มี ward)
                                    if ($v_count > 0) {
                                        $status_text = '<span class="badge bg-info text-dark border"><i class="fa-solid fa-house-chimney-medical me-1"></i> เยี่ยม ('.$v_count.'/'.$max_visits.')</span>';
                                    } else {
                                        $status_text = '<span class="badge bg-success text-white border"><i class="fa-solid fa-house-circle-check me-1"></i> รอเยี่ยมครั้งแรก</span>';
                                    }
                                    $time_text = "D/C (ER): " . date('d/m/y', strtotime($case['er_time']));
                                }
                                
                                $search_query = !empty($case['an']) ? $case['an'] : $case['hn'];
                            ?>
                            <li>
                                <a class="dropdown-item p-3 border-bottom case-card" href="<?php echo $path_prefix; ?>Visit/formVisit.php?hn=<?php echo $case['hn']; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong class="text-dark d-block mb-1"><?php echo htmlspecialchars($case['patient_name']); ?></strong>
                                            <?php if(!empty($case['an'])): ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-dark border">AN: <?php echo $case['an']; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-dark border">HN: <?php echo $case['hn']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <?php echo $status_text; ?>
                                            <small class="text-muted fw-bold d-block mt-1"><i class="fa-regular fa-clock me-1"></i><?php echo $time_text; ?></small>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><div class="dropdown-item p-4 text-center text-muted"><i class="fa-solid fa-check-circle fs-3 text-success opacity-50 mb-2"></i><br>ไม่มีรายการรอเยี่ยม</div></li>
                    <?php endif; ?>
                    </div>
                </ul>
            </li>
            
            <?php if (isset($_SESSION['user_id'])): ?>
            <li class="nav-item dropdown w-100">
                <a class="nav-link dropdown-toggle user-pill-visit d-flex align-items-center text-white text-decoration-none w-100" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-2 shadow-sm user-avatar" style="width: 32px; height: 32px; color: var(--v-primary);">
                        <i class="fa-solid fa-user-nurse"></i>
                    </div>
                    <span class="me-1 fw-medium pe-1" style="font-size: 0.95rem;">
                        <?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'PCU Staff'; ?>
                    </span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern mt-2 p-2 w-100">
                    <li><a class="dropdown-item rounded text-danger fw-bold py-2" href="<?php echo $path_prefix; ?>logout.php"><i class="fa-solid fa-power-off me-2"></i> ออกจากระบบ</a></li>
                </ul>
            </li>
            <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<script src="<?php echo $path_prefix; ?>bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>