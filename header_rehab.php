<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($path_prefix)) { $path_prefix = ""; }

$root_path = $path_prefix != "" ? $path_prefix : "../"; 
$db_paths = [$root_path . 'database/db_conn.php', 'database/db_conn.php', '../database/db_conn.php'];
foreach ($db_paths as $path) { if (file_exists($path)) { require_once $path; break; } }
require_once __DIR__ . '/database/fiscal_year.php';
$fiscal_date_range = getFiscalDateRange($_GET);

// ดึงเคสรอรับเข้า PMR ที่ยังไม่มีบันทึกฟื้นฟู
$incoming_pmr = [];
if (isset($conn)) {
    $sql = "SELECT w.an, c.patient_name, w.discharge_date, w.discharge_detail
            FROM ward_details w
            JOIN cases c ON w.case_id = c.id
            WHERE w.status = 'Discharged'
            AND DATE(COALESCE(w.discharge_date, w.admit_date)) BETWEEN '{$fiscal_date_range['start_date']}' AND '{$fiscal_date_range['end_date']}'
            AND EXISTS (SELECT 1 FROM ward_progress_notes wpn
                        WHERE wpn.ward_detail_id = w.id
                        AND wpn.treatment_result LIKE 'Discharge%')
            AND w.an NOT IN (SELECT an FROM pmr_records)
            ORDER BY w.discharge_date DESC";
    $result = $conn->query($sql);
    if($result) while($row = $result->fetch_assoc()) $incoming_pmr[] = $row;
}
$pmr_count = count($incoming_pmr);
?>

<style>
    /* PMR Theme: Elegant Soft Violet (สีม่วงอ่อนหรูหรา) */
    :root {
        --p-primary: #8b5cf6; /* สีม่วงหลัก */
        --p-dark: #7c3aed;    /* สีม่วงเข้ม (Hover) */
        --p-light: #c4b5fd;   /* สีม่วงสว่าง */
        --p-soft: #f5f3ff;    /* สีม่วงอ่อนมากสำหรับ Hover บนมือถือ */
    }

    .navbar-pmr-theme { 
        background: linear-gradient(135deg, var(--p-dark) 0%, var(--p-primary) 50%, var(--p-light) 100%); 
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
        color: var(--p-dark) !important; 
        font-weight: 700; 
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    }
    
    /* User Profile Pill */
    .user-pill-pmr {
        background: rgba(0, 0, 0, 0.15);
        border-radius: 50px;
        padding: 6px 16px 6px 6px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.3s ease;
        backdrop-filter: blur(5px);
    }
    .user-pill-pmr:hover {
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
        color: #fde68a !important; 
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
    
    .noti-dropdown { width: 360px; }
    
    /* การ์ดรายการเคสใน Dropdown */
    .case-card { transition: all 0.2s; border-left: 4px solid transparent; }
    .case-card:hover { background-color: var(--p-soft); border-left: 4px solid var(--p-primary); }

    /* Scrollbar สำหรับ Dropdown */
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: var(--p-primary); }

    /* ==========================================
       🌟 MOBILE RESPONSIVE CSS
       ========================================== */
    @media (max-width: 991px) {
        /* เปลี่ยนสีพื้นหลัง Navbar พับเก็บให้เป็นกล่องสีขาวโค้งมน */
        .navbar-collapse {
            background-color: #ffffff;
            border-radius: 15px;
            padding: 15px;
            margin-top: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid #f1f5f9;
        }
        
        /* ปรับเมนูหลักให้ตัวอักษรเป็นสีม่วงเข้มและเรียงชิดซ้าย */
        .nav-link-custom {
            color: var(--p-dark) !important;
            margin: 4px 0;
            padding: 12px 20px !important;
            border-radius: 10px;
            display: block;
        }
        .nav-link-custom:hover, .nav-link-custom.active {
            background-color: var(--p-soft);
            color: var(--p-dark) !important;
            box-shadow: none;
            transform: none;
        }
        
        /* เส้นแบ่งระหว่างเมนูหลักกับเมนูโปรไฟล์/แจ้งเตือน */
        .navbar-nav.ms-auto {
            border-top: 1px solid #f1f5f9;
            margin-top: 10px;
            padding-top: 15px;
            align-items: stretch !important; /* ยืดให้เต็มความกว้าง */
            gap: 10px !important;
        }

        /* ปรับปุ่มกระดิ่งแจ้งเตือนให้เป็นกล่องกดง่าย */
        .bell-link {
            display: flex !important;
            align-items: center;
            background-color: var(--p-soft);
            color: var(--p-dark) !important;
            border-radius: 10px;
            padding: 12px 20px !important;
            text-decoration: none;
        }
        .bell-link i { color: var(--p-dark) !important; margin-right: 10px; }
        .bell-link .badge {
            position: static !important;
            transform: none !important;
            margin-left: auto; /* ดันตัวเลขไปชิดขวาสุด */
        }
        .bell-active { color: var(--p-dark) !important; }

        /* ปรับ Dropdown ให้เต็มจอ */
        .noti-dropdown { width: 100% !important; margin-top: 5px !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }

        /* ปรับปุ่มโปรไฟล์ User */
        .user-pill-pmr {
            background-color: #f8fafc !important;
            color: var(--p-dark) !important;
            border-radius: 10px;
            padding: 10px 20px !important;
            border: 1px solid #e2e8f0;
            justify-content: flex-start;
        }
        .user-avatar { background-color: var(--p-primary) !important; color: white !important; }
        .user-pill-pmr:hover { background-color: #f1f5f9 !important; border-color: #cbd5e1; }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-pmr-theme shadow-sm mb-4">
  <div class="container-fluid px-3">
    <a class="navbar-brand d-flex align-items-center gap-3" href="<?php echo $path_prefix; ?>dashboard.php">
        <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px; color: var(--p-primary);">
            <img src="<?php echo $path_prefix; ?>img/AnanLogo.png" width="50" alt="Logo">
        </div>
        <div class="d-flex flex-column text-white">
            <span class="fw-bolder" style="font-size: 1.25rem; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">PMR</span>
            <span style="font-size: 0.75rem; opacity: 0.9; font-weight: 300;">เวชศาสตร์ฟื้นฟู</span>
        </div>
    </a>
    
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarPMR">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarPMR">
      <ul class="navbar-nav me-auto ms-lg-4 gap-1">
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>dashboard.php">
                <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ด
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'indexPMR.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>PMR/indexPMR.php">
                <i class="fa-solid fa-heart-pulse me-1"></i> จัดการเวชศาสตร์ฟื้นฟู
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'history_centralPmr.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>PMR/history_centralPmr.php">
                <i class="fa-solid fa-address-book me-1"></i> ประวัติการรักษา
            </a>
        </li>
      </ul>
      
      <ul class="navbar-nav ms-auto align-items-center gap-3">
            <li class="nav-item dropdown me-lg-3 w-100">
                <a class="nav-link text-white position-relative p-2 bell-link" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-bell fa-lg <?php echo ($pmr_count > 0) ? 'bell-active' : ''; ?>"></i>
                    <span class="d-lg-none fw-bold text-dark">รอรับฟื้นฟูครั้งแรก</span>
                    <?php if ($pmr_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark shadow-sm fw-bold">
                            <?php echo $pmr_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern noti-dropdown">
                    <li class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clipboard-list me-2" style="color: var(--p-primary);"></i>รอรับฟื้นฟูครั้งแรก</h6>
                        <span class="badge bg-warning text-dark rounded-pill px-2 py-1"><?php echo $pmr_count; ?></span>
                    </li>
                    <div class="custom-scrollbar" style="max-height: 350px; overflow-y: auto;">
                    <?php if ($pmr_count > 0): ?>
                        <?php foreach ($incoming_pmr as $case): ?>
                            <li>
                                <a class="dropdown-item p-3 border-bottom case-card" href="<?php echo $path_prefix; ?>PMR/indexPMR.php?search=<?php echo urlencode($case['an']); ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong class="text-dark d-block mb-1"><?php echo htmlspecialchars($case['patient_name']); ?></strong>
                                            <span class="badge bg-secondary bg-opacity-10 text-dark border">AN: <?php echo $case['an']; ?></span>
                                        </div>
                                        <div class="text-end">
                                            <small class="fw-bold d-block" style="color: var(--p-primary);"><i class="fa-regular fa-clock me-1"></i>D/C: <?php echo date('d/m/y', strtotime($case['discharge_date'])); ?></small>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><div class="dropdown-item p-4 text-center text-muted"><i class="fa-solid fa-check-circle fs-3 text-success opacity-50 mb-2"></i><br>ไม่มีรายการรอการฟื้นฟู</div></li>
                    <?php endif; ?>
                    </div>
                </ul>
            </li>
            
            <?php if (isset($_SESSION['user_id'])): ?>
            <li class="nav-item dropdown w-100">
                <a class="nav-link dropdown-toggle user-pill-pmr d-flex align-items-center text-white text-decoration-none w-100" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-2 shadow-sm user-avatar" style="width: 32px; height: 32px; color: var(--p-primary);">
                        <i class="fa-solid fa-user-doctor"></i>
                    </div>
                    <span class="me-1 fw-medium pe-1" style="font-size: 0.95rem;">
                        <?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'PMR Staff'; ?>
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