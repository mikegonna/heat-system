<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($path_prefix)) { $path_prefix = ""; }

$root_path = $path_prefix != "" ? $path_prefix : "../"; 
$db_paths = [$root_path . 'database/db_conn.php', 'database/db_conn.php', '../database/db_conn.php'];
foreach ($db_paths as $path) { if (file_exists($path)) { require_once $path; break; } }
require_once __DIR__ . '/database/fiscal_year.php';
$fiscal_date_range = getFiscalDateRange($_GET);

// ดึงเคสรอรับเข้า (AdmitWait) ของ ICU (รวมทั้งจาก ER และ รับย้ายจากแผนกอื่น)
$incoming_icu = [];
if (isset($conn)) {
    // 💡 1. รับจาก ER โดยตรง (ลบเช็คเวลาออก)
    $sqlER = "SELECT c.patient_name, e.FstDx as diagnosis, e.recorded_at as time_ref, 'ส่งตรงจาก ER' as from_dept, c.hn 
              FROM er_details e 
              JOIN cases c ON e.case_id = c.id
              WHERE e.disposition = 'Admit' 
              AND (e.admit_type = 'ICU' OR e.admit_type LIKE '%ICU%')
              AND DATE(e.recorded_at) BETWEEN '{$fiscal_date_range['start_date']}' AND '{$fiscal_date_range['end_date']}'
              AND e.FstDx NOT LIKE 'รับย้ายจาก%' 
              AND NOT EXISTS (SELECT 1 FROM ward_details w WHERE w.case_id = c.id)";
    $resER = $conn->query($sqlER);
    if($resER) while($r = $resER->fetch_assoc()) $incoming_icu[] = $r;

    // 💡 2. รับย้ายจากแผนกอื่น (Ward Transfer)
    $sqlWard = "SELECT c.patient_name, w.discharge_detail as diagnosis, w.admit_date as time_ref, 'รับย้ายจากแผนก' as from_dept, w.an 
                FROM ward_details w 
                JOIN cases c ON w.case_id = c.id
                WHERE w.status = 'AdmitWait' 
                AND w.admit_type LIKE '%ICU%'
                AND DATE(w.admit_date) BETWEEN '{$fiscal_date_range['start_date']}' AND '{$fiscal_date_range['end_date']}'
                AND NOT EXISTS (SELECT 1 FROM ward_details w2 WHERE w2.case_id = w.case_id AND w2.id > w.id)";
    $resWard = $conn->query($sqlWard);
    if($resWard) while($r = $resWard->fetch_assoc()) $incoming_icu[] = $r;
    
    // เรียงลำดับเวลา
    usort($incoming_icu, function($a, $b) {
        return strtotime($b['time_ref']) - strtotime($a['time_ref']);
    });
}
$icu_count = count($incoming_icu);
?>

<style>
    /* ICU Theme: Soft Apricot / Peach Orange (สีส้มอ่อนหรูหรา) */
    :root {
        --i-primary: #fb923c; 
        --i-dark: #ea580c;    
        --i-light: #fed7aa;   
    }

    .navbar-icu-theme { 
        background: linear-gradient(135deg, var(--i-dark) 0%, var(--i-primary) 50%, var(--i-light) 100%); 
        border-bottom: 1px solid rgba(255, 255, 255, 0.2); 
        padding: 0.8rem 1rem; 
    }
    
    .nav-link-custom { 
        color: rgba(255, 255, 255, 0.95) !important; 
        font-weight: 500; 
        border-radius: 50px; 
        padding: 8px 18px !important; 
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); 
        margin: 0 4px;
    }
    .nav-link-custom:hover { 
        background-color: rgba(255, 255, 255, 0.2); 
        transform: translateY(-2px); 
    }
    .nav-link-custom.active { 
        background-color: #ffffff; 
        color: var(--i-dark) !important; 
        font-weight: 700; 
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    
    /* User Profile Pill */
    .user-pill-icu {
        background: rgba(0, 0, 0, 0.1);
        border-radius: 50px;
        padding: 6px 16px 6px 6px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        transition: all 0.3s ease;
        backdrop-filter: blur(5px);
    }
    .user-pill-icu:hover {
        background: rgba(0, 0, 0, 0.2);
        border-color: rgba(255, 255, 255, 0.5);
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
    
    .case-card { transition: all 0.2s; border-left: 4px solid transparent; }
    .case-card:hover { background-color: #fffaf5; border-left: 4px solid var(--i-primary); }

    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: var(--i-primary); }

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
        
        /* ปรับเมนูหลักให้ตัวอักษรเป็นสีส้มเข้มและเรียงชิดซ้าย */
        .nav-link-custom {
            color: var(--i-dark) !important;
            margin: 4px 0;
            padding: 12px 20px !important;
            border-radius: 10px;
            display: block;
        }
        .nav-link-custom:hover, .nav-link-custom.active {
            background-color: #fff5eb;
            color: var(--i-dark) !important;
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
            background-color: #fff5eb;
            color: var(--i-dark) !important;
            border-radius: 10px;
            padding: 12px 20px !important;
            text-decoration: none;
        }
        .bell-link i { color: var(--i-dark) !important; margin-right: 10px; }
        .bell-link .noti-badge {
            position: static !important;
            transform: none !important;
            background-color: var(--i-dark) !important;
            color: white !important;
            margin-left: auto; /* ดันตัวเลขไปชิดขวาสุด */
        }
        .bell-active { color: var(--i-dark) !important; }

        /* ปรับ Dropdown ให้เต็มจอ */
        .noti-dropdown { width: 100%; margin-top: 5px !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }

        /* ปรับปุ่มโปรไฟล์ User */
        .user-pill-icu {
            background-color: #f8fafc !important;
            color: var(--i-dark) !important;
            border-radius: 10px;
            padding: 10px 20px !important;
            border: 1px solid #e2e8f0;
            justify-content: flex-start;
        }
        .user-avatar { background-color: var(--i-primary) !important; color: white !important; }
        .user-pill-icu:hover { background-color: #f1f5f9 !important; border-color: #cbd5e1; }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-icu-theme shadow-sm mb-4">
  <div class="container-fluid px-3">
    <a class="navbar-brand d-flex align-items-center gap-3" href="<?php echo $path_prefix; ?>dashboard.php">
        <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px; color: var(--i-dark);">
            <img src="<?php echo $path_prefix; ?>img/AnanLogo.png" width="50" alt="Logo">
        </div>
        <div class="d-flex flex-column text-white">
            <span class="fw-bolder" style="font-size: 1.25rem; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.15);">ICU</span>
            <span style="font-size: 0.75rem; opacity: 0.95; font-weight: 500;">หอผู้ป่วยวิกฤต</span>
        </div>
    </a>
    
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarICU">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarICU">
      <ul class="navbar-nav me-auto ms-lg-4 gap-1">
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>dashboard.php">
                <i class="fa-solid fa-chart-pie me-1 w-20"></i> แดชบอร์ด
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'indexICU.php' || basename($_SERVER['PHP_SELF']) == 'indexWard.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>Ward/indexICU.php">
                <i class="fa-solid fa-bed-pulse me-1"></i> จัดการผู้ป่วย (ICU)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?php echo (basename($_SERVER['PHP_SELF']) == 'history_centralICU.php') ? 'active' : ''; ?>" href="<?php echo $path_prefix; ?>Ward/history_centralICU.php">
                <i class="fa-solid fa-address-book me-1"></i> ประวัติการรักษา
            </a>
        </li>
      </ul>
      
      <ul class="navbar-nav ms-auto align-items-center gap-3">
            <!-- กระดิ่งแจ้งเตือน -->
            <li class="nav-item dropdown me-lg-3 w-100">
                <a class="nav-link text-white position-relative p-2 bell-link" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-bell fa-lg <?php echo ($icu_count > 0) ? 'bell-active' : ''; ?>"></i>
                    <span class="d-lg-none fw-bold">การแจ้งเตือน (รอรับเข้า)</span>
                    <?php if ($icu_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-white text-dark shadow-sm fw-bold noti-badge" style="color: var(--i-dark) !important;">
                            <?php echo $icu_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern noti-dropdown">
                    <li class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-bed-pulse me-2" style="color: var(--i-dark);"></i>รอรับเข้า ICU</h6>
                        <span class="badge rounded-pill px-2 py-1" style="background-color: var(--i-light); color: var(--i-dark);"><?php echo $icu_count; ?></span>
                    </li>
                    <div class="custom-scrollbar" style="max-height: 350px; overflow-y: auto;">
                    <?php if ($icu_count > 0): ?>
                        <?php foreach ($incoming_icu as $case): ?>
                            <li>
                                <a class="dropdown-item p-3 border-bottom case-card" href="<?php echo $path_prefix; ?>Ward/indexICU.php">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong class="text-dark d-block mb-1"><i class="fa-solid fa-user-plus me-1 text-primary"></i> <?php echo htmlspecialchars($case['patient_name']); ?></strong>
                                            <div class="small text-muted text-truncate" style="max-width: 200px;">
                                                <i class="fa-solid fa-stethoscope me-1"></i> <?php echo htmlspecialchars($case['diagnosis']); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <small class="fw-bold d-block" style="color: var(--i-dark);"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($case['time_ref'])); ?> น.</small>
                                            <small class="badge bg-light text-dark border mt-1" style="font-size: 0.65rem;"><?php echo $case['from_dept']; ?></small>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><div class="dropdown-item p-4 text-center text-muted"><i class="fa-solid fa-check-circle fs-3 text-success opacity-50 mb-2"></i><br>ไม่มีผู้ป่วยรอรับเข้า</div></li>
                    <?php endif; ?>
                    </div>
                    
                    <a href="<?php echo $path_prefix; ?>dashboard.php" class="dropdown-item text-center bg-light text-primary py-2 small fw-bold border-top">
                        ไปหน้าจัดการผู้ป่วย (Dashboard)
                    </a>
                </ul>
            </li>
            
            <?php if (isset($_SESSION['user_id'])): ?>
            <li class="nav-item dropdown w-100">
                <a class="nav-link dropdown-toggle user-pill-icu d-flex align-items-center text-white text-decoration-none w-100" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-2 shadow-sm user-avatar" style="width: 32px; height: 32px; color: var(--i-dark);">
                        <i class="fa-solid fa-user-doctor"></i>
                    </div>
                    <span class="me-1 fw-medium pe-1 user-name" style="font-size: 0.95rem;">
                        <?php echo isset($_SESSION['name']) ? htmlspecialchars(strtoupper($_SESSION['name'])) : 'ICU Staff'; ?>
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