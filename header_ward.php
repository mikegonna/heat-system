<?php
// header_ward.php
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// ตรวจสอบ Path
$root_path = isset($path_prefix) ? $path_prefix : '../'; 
$db_paths = [$root_path . 'database/db_conn.php', 'database/db_conn.php', '../database/db_conn.php'];
foreach ($db_paths as $path) { if (file_exists($path)) { require_once $path; break; } }

// --- [REAL DATA QUERY] ---
$incoming_patients = [];
$current_ward = isset($_SESSION['name']) ? $_SESSION['name'] : '';

if ($current_ward != '' && $current_ward != 'admin' && $current_ward != 'pct' && isset($conn)) {
    // 💡 1. จาก ER -> Ward (ลบการตรวจเช็คเวลาออก เช็คจากเคสที่มีเลย)
    $sqlER = "SELECT c.patient_name, e.FstDx as diagnosis, e.recorded_at as time_ref, 'ER' as from_dept 
              FROM er_details e 
              JOIN cases c ON e.case_id = c.id
              WHERE e.disposition = 'Admit' 
              AND e.admit_type LIKE '%$current_ward%'
              AND NOT EXISTS (SELECT 1 FROM ward_details w WHERE w.case_id = c.id)
              ORDER BY e.recorded_at DESC";
    $resER = $conn->query($sqlER);
    if($resER) while($r = $resER->fetch_assoc()) $incoming_patients[] = $r;

    // 💡 2. รับย้าย (Transfer)
    $sqlWard = "SELECT c.patient_name, w.discharge_detail as diagnosis, w.admit_date as time_ref, 'Ward' as from_dept 
                FROM ward_details w 
                JOIN cases c ON w.case_id = c.id
                WHERE w.status = 'AdmitWait' 
                AND w.admit_type LIKE '%$current_ward%'
                AND NOT EXISTS (SELECT 1 FROM ward_details w2 WHERE w2.case_id = w.case_id AND w2.id > w.id)
                ORDER BY w.admit_date DESC";
    $resWard = $conn->query($sqlWard);
    if($resWard) while($r = $resWard->fetch_assoc()) $incoming_patients[] = $r;
}

usort($incoming_patients, function($a, $b) {
    return strtotime($b['time_ref']) - strtotime($a['time_ref']);
});
$case_count = count($incoming_patients);
?>

<style>
    /* 🌿 WARD Theme: Emerald Green */
    :root {
        --ward-primary: #10b981; /* สีเขียว Emerald 500 */
        --ward-dark: #047857;    /* สีเขียวเข้ม Emerald 700 */
        --ward-light: #d1fae5;   /* สีเขียวสว่าง Emerald 100 */
        --ward-soft: #ecfdf5;    /* พื้นหลังสีเขียวอ่อนเวลา Hover */
    }

    .navbar-ward {
        background: linear-gradient(135deg, var(--ward-dark) 0%, var(--ward-primary) 50%, var(--ward-light) 100%);
        border-bottom: 1px solid rgba(255,255,255,0.15);
        padding: 0.8rem 1rem;
    }
    .nav-link-ward {
        color: rgba(255,255,255,0.9) !important;
        margin: 0 5px;
        padding: 8px 18px !important;
        border-radius: 50px;
        transition: 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        font-weight: 500;
    }
    .nav-link-ward:hover { background: rgba(255,255,255,0.15); transform: translateY(-2px); }
    .nav-link-ward.active {
        background: #fff; color: var(--ward-dark) !important; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }
    .user-pill-ward {
        background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50px; padding: 6px 16px 6px 6px; transition: all 0.3s;
        backdrop-filter: blur(5px);
    }
    .user-pill-ward:hover { background: rgba(0,0,0,0.25); border-color: rgba(255,255,255,0.4); }
    
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
        border: none; border-radius: 16px; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        padding: 0; overflow: hidden; margin-top: 15px !important; border: 1px solid rgba(0,0,0,0.05);
    }
    
    .noti-dropdown { width: 350px; }

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
        
        /* ปรับเมนูหลักให้ตัวอักษรเป็นสีเขียวเข้มและเรียงชิดซ้าย */
        .nav-link-ward {
            color: var(--ward-dark) !important;
            margin: 4px 0;
            padding: 12px 20px !important;
            border-radius: 10px;
            display: block;
        }
        .nav-link-ward:hover, .nav-link-ward.active {
            background-color: var(--ward-soft);
            color: var(--ward-dark) !important;
            box-shadow: none;
            transform: none;
        }
        
        /* เส้นแบ่งระหว่างเมนูหลักกับเมนูโปรไฟล์/แจ้งเตือน */
        .navbar-nav.ms-auto {
            border-top: 1px solid #f1f5f9;
            margin-top: 10px;
            padding-top: 15px;
            align-items: stretch !important;
            gap: 10px !important;
        }

        /* ปรับปุ่มกระดิ่งแจ้งเตือนให้เป็นกล่องกดง่าย */
        .bell-link {
            display: flex !important;
            align-items: center;
            background-color: var(--ward-soft);
            color: var(--ward-dark) !important;
            border-radius: 10px;
            padding: 12px 20px !important;
            text-decoration: none;
        }
        .bell-link i { color: var(--ward-dark) !important; margin-right: 10px; }
        .bell-link .noti-badge {
            position: static !important;
            transform: none !important;
            background-color: var(--ward-dark) !important;
            color: white !important;
            margin-left: auto; /* ดันตัวเลขไปชิดขวาสุด */
        }
        .bell-active { color: var(--ward-dark) !important; }

        /* ปรับ Dropdown ให้เต็มจอ */
        .noti-dropdown { width: 100% !important; margin-top: 5px !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }

        /* ปรับปุ่มโปรไฟล์ User */
        .user-pill-ward {
            background-color: #f8fafc !important;
            color: var(--ward-dark) !important;
            border-radius: 10px;
            padding: 10px 20px !important;
            border: 1px solid #e2e8f0;
            justify-content: flex-start;
        }
        .user-avatar { background-color: var(--ward-primary) !important; color: white !important; }
        .user-pill-ward:hover { background-color: #f1f5f9 !important; border-color: #cbd5e1; }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-ward shadow-sm">
    <div class="container-fluid px-3">
        <a class="navbar-brand d-flex align-items-center gap-3" href="<?php echo $root_path; ?>dashboard.php">
            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px; color: var(--ward-dark);">
                <img src="<?php echo $path_prefix; ?>img/AnanLogo.png" width="50" alt="Logo">
            </div>
            <div class="d-flex flex-column text-white">
                <span class="fw-bolder" style="font-size: 1.25rem; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.15);">WARD <?php echo $current_user_name  ;?></span>
                <span style="font-size: 0.75rem; opacity: 0.95; font-weight: 500;">หอผู้ป่วยใน (<?php echo strtoupper($current_ward); ?>)</span>
            </div>
        </a>

        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarWard">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarWard">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-1">
                <li class="nav-item">
                    <a class="nav-link nav-link-ward <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" 
                       href="<?php echo $root_path; ?>dashboard.php"><i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ด</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-ward <?php echo basename($_SERVER['PHP_SELF']) == 'indexWard.php' ? 'active' : ''; ?>" 
                       href="<?php echo $root_path; ?>Ward/indexWard.php"><i class="fa-solid fa-bed-pulse me-1"></i> จัดการผู้ป่วย</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-ward <?php echo basename($_SERVER['PHP_SELF']) == 'history_centralWard.php' ? 'active' : ''; ?>" 
                       href="<?php echo $root_path; ?>Ward/history_centralWard.php"><i class="fa-solid fa-address-book me-1"></i> ประวัติการรักษา</a>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center gap-3">
                <!-- กระดิ่งแจ้งเตือน -->
                <li class="nav-item dropdown me-lg-3 w-100">
                    <a class="nav-link text-white position-relative p-2 bell-link" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-bell fa-lg <?php echo ($case_count > 0) ? 'bell-active' : ''; ?>"></i>
                        <span class="d-lg-none fw-bold text-dark">เคสรอรับเข้า</span>
                        <?php if ($case_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-white text-dark shadow-sm fw-bold noti-badge" style="color: var(--ward-dark) !important;">
                                <?php echo $case_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern noti-dropdown">
                        <li class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-file-import me-2" style="color: var(--ward-primary);"></i>เคสรอรับเข้า</h6>
                            <span class="badge rounded-pill px-2 py-1" style="background-color: var(--ward-light); color: var(--ward-dark);"><?php echo $case_count; ?></span>
                        </li>
                        <div style="max-height: 350px; overflow-y: auto;">
                        <?php if ($case_count > 0): ?>
                            <?php foreach ($incoming_patients as $case): ?>
                                <li>
                                    <a class="dropdown-item p-3 border-bottom" href="<?php echo $root_path; ?>Ward/indexWard.php" style="transition: all 0.2s;">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong style="color: var(--ward-dark);"><i class="fa-solid fa-user-plus me-1"></i> <?php echo htmlspecialchars($case['patient_name']); ?></strong>
                                                <div class="small text-muted mt-1 text-truncate" style="max-width: 200px;">
                                                    <?php echo htmlspecialchars($case['diagnosis']); ?>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <small class="fw-bold d-block" style="color: var(--ward-primary);"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($case['time_ref'])); ?> น.</small>
                                                <small class="text-muted" style="font-size: 0.7rem;"><?php echo $case['from_dept']; ?></small>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li><div class="dropdown-item p-4 text-center text-muted"><i class="fa-solid fa-check-circle fs-3 text-success opacity-50 mb-2"></i><br>ไม่มีเคสรอรับเข้า</div></li>
                        <?php endif; ?>
                        </div>
                    </ul>
                </li>
                        
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown w-100">
                        <a class="nav-link dropdown-toggle user-pill-ward d-flex align-items-center text-white text-decoration-none w-100" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-2 shadow-sm user-avatar" style="width: 32px; height: 32px; color: var(--ward-dark);">
                                <i class="fa-solid fa-user-nurse"></i>
                            </div>
                            <span class="me-1 fw-medium pe-1" style="font-size: 0.95rem;">
                                <?php echo isset($_SESSION['name']) ? htmlspecialchars(strtoupper($_SESSION['name'])) : 'Ward Staff'; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern mt-2 p-2 w-100">
                            <li><a class="dropdown-item rounded text-danger fw-bold py-2" href="<?php echo $root_path; ?>logout.php"><i class="fa-solid fa-power-off me-2"></i> ออกจากระบบ</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<script src="<?php echo $root_path; ?>bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>