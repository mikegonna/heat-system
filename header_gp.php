<?php
// header_er.php
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// ตรวจสอบว่ามีการตั้งค่า path_prefix มาจากไฟล์หลักหรือไม่ ถ้าไม่มีให้เป็นค่าว่าง (Root)
if (!isset($path_prefix)) { $path_prefix = ""; }

$root_path = $path_prefix != "" ? $path_prefix : "../"; 
$db_paths = [$root_path . 'database/db_conn.php', 'database/db_conn.php', '../database/db_conn.php'];
foreach ($db_paths as $path) { if (file_exists($path)) { require_once $path; break; } }
?>

<style>
    /* ตัวแปรสีหลัก */
    :root {
        --er-primary: #059669;
        --er-dark: #059669;
        --er-light: #129c62;
    }

    /* แถบนำทางหลัก */
    .navbar-er {
        background: linear-gradient(135deg, var(--er-dark) 0%, var(--er-primary) 50%, var(--er-light) 100%);
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        padding: 0.6rem 1.5rem;
    }

    /* โลโก้และชื่อแบรนด์ */
    .brand-logo-bg {
        background: rgba(255, 255, 255, 0.9);
        border-radius: 8px;
        padding: 2px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        transition: transform 0.3s ease;
    }
    .navbar-brand:hover .brand-logo-bg {
        transform: scale(1.05);
    }

    /* เมนูลิงก์ */
    .nav-link-modern {
        color: rgba(255, 255, 255, 0.85) !important;
        font-weight: 500;
        padding: 8px 18px !important;
        margin: 0 4px;
        border-radius: 20px;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        font-size: 0.95rem;
    }
    .nav-link-modern:hover {
        background: rgba(255, 255, 255, 0.15);
        color: #ffffff !important;
    }
    .nav-link-modern.active {
        background: #ffffff;
        color: var(--er-primary) !important;
        font-weight: 700;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    }

    /* ปุ่มโปรไฟล์ผู้ใช้ */
    .user-pill {
        background: rgba(0, 0, 0, 0.15);
        border-radius: 50px;
        padding: 6px 16px 6px 6px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.3s ease;
        backdrop-filter: blur(5px);
    }
    .user-pill:hover {
        background: rgba(0, 0, 0, 0.25);
        border-color: rgba(255, 255, 255, 0.4);
    }
    .user-avatar {
        width: 34px; 
        height: 34px;
        background: linear-gradient(135deg, #ffffff 0%, #f0f0f0 100%);
        color: var(--er-primary);
        border-radius: 50%;
        display: inline-flex; 
        align-items: center; 
        justify-content: center;
        margin-right: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    /* ไอคอนกระดิ่งแจ้งเตือนพื้นฐาน */
    .hover-scale {
        transition: transform 0.3s ease;
        display: inline-block;
    }
    .nav-link:hover .hover-scale:not(.bell-active) {
        transform: scale(1.15) rotate(5deg);
    }
    .notification-badge {
        border: 2px solid var(--er-primary);
        font-size: 0.65rem;
        padding: 0.35em 0.5em;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    /* Animation กระดิ่งสั่นสมจริง */
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

    /* เมนู Dropdown หรูหรา */
    .dropdown-menu-modern {
        border: none;
        border-radius: 16px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        padding: 0;
        overflow: hidden;
        margin-top: 15px !important;
        border: 1px solid rgba(0,0,0,0.05);
    }
    .dropdown-menu-modern .dropdown-item {
        transition: background 0.2s ease;
    }
    .dropdown-menu-modern .dropdown-item:hover {
        background-color: #f8f9fa;
    }

    /* การ์ดรายการเคสใน Dropdown */
    .case-card {
        transition: all 0.2s;
        border-left: 4px solid transparent;
    }
    .case-card:hover {
        background-color: #f8fbfa;
        border-left: 4px solid var(--er-light);
    }

    /* Scrollbar สำหรับ Dropdown */
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: var(--er-light); }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-er shadow-sm sticky-top">
    <div class="container-fluid px-2 px-md-4">
        <!-- Logo & Brand -->
        <a class="navbar-brand d-flex align-items-center gap-3" href="<?php echo $path_prefix; ?>dashboard.php">
            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px;">
                <img src="<?php echo $path_prefix; ?>img/AnanLogo.png" width="50" alt="Logo">
            </div>
            <div class="d-flex flex-column text-white">
                <span class="fw-bolder" style="font-size: 1.25rem; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">OPD GP</span>
                <span style="font-size: 0.75rem; opacity: 0.9; font-weight: 300;">ห้องตรวจโรคทั่วไป รพ.อานันทมหิดล</span>
            </div>
        </a>
        
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <!-- Main Navigation -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-5 gap-1">
                <li class="nav-item">
                    <a class="nav-link nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" 
                       href="<?php echo $path_prefix; ?>dashboard.php">
                       <i class="fa-solid fa-chart-pie me-2"></i>แดชบอร์ด
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'indexER.php' ? 'active' : ''; ?>" 
                       href="<?php echo $path_prefix; ?>GP/indexER.php">
                       <i class="fa-solid fa-file-medical me-2"></i>บันทึกข้อมูล
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'history_centralER.php' || basename($_SERVER['PHP_SELF']) == 'history_central.php' ? 'active' : ''; ?>" 
                       href="<?php echo $path_prefix; ?>GP/history_centralER.php">
                       <i class="fa-solid fa-clock-rotate-left me-2"></i>ประวัติการรักษา
                    </a>
                </li>
            </ul>

            <!-- Right side Tools & Profile -->
            <ul class="navbar-nav ms-auto align-items-center gap-3">
                
                <!-- User Profile -->
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle user-pill d-flex align-items-center text-white text-decoration-none" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar">
                                <i class="fa-solid fa-user-doctor fs-6"></i>
                            </div>
                            <span class="me-1 fw-medium pe-1" style="font-size: 0.95rem;">
                                <?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'ER Staff'; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern mt-2 p-2" style="min-width: 200px;">
                            <li class="px-3 py-2 text-muted small border-bottom mb-2">
                                เข้าสู่ระบบในชื่อ 
                                <strong class="text-dark"><?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'ER Staff'; ?></strong>
                            </li>
                            <li>
                                <a class="dropdown-item rounded text-danger fw-bold py-2" href="<?php echo $path_prefix; ?>logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i> ออกจากระบบ
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Scripts -->
<script src="<?php echo $path_prefix; ?>bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>