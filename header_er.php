<?php
// header_er.php
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// ตรวจสอบว่ามีการตั้งค่า path_prefix มาจากไฟล์หลักหรือไม่ ถ้าไม่มีให้เป็นค่าว่าง (Root)
if (!isset($path_prefix)) { $path_prefix = ""; }

$root_path = $path_prefix != "" ? $path_prefix : "../"; 
$db_paths = [$root_path . 'database/db_conn.php', 'database/db_conn.php', '../database/db_conn.php'];
foreach ($db_paths as $path) { if (file_exists($path)) { require_once $path; break; } }
require_once __DIR__ . '/database/fiscal_year.php';
$fiscal_date_range = getFiscalDateRange($_GET);

// --- [ดึงข้อมูลจริงจากฐานข้อมูล] ---
$incoming_patients = [];
if (isset($conn)) {
    // 💡 แก้ไข: นำเงื่อนไขเช็คเวลา w.admit_date >= e.recorded_at ออกเช่นเดียวกัน
    // ค้นหาเคส ER ที่ยังค้างประเมิน (ยังไม่สรุปผล) หรือ สั่ง Admit แล้วแต่แผนกยังไม่ได้รับเข้า
    $sql = "SELECT * FROM data_import WHERE `status` = 0
            AND DATE(data_date) BETWEEN '{$fiscal_date_range['start_date']}' AND '{$fiscal_date_range['end_date']}'
            ORDER BY data_date DESC";
            
    $result = $conn->query($sql);
    if($result) {
        while($row = $result->fetch_assoc()) {
            $incoming_patients[] = [
                'row_id' => $row['row_ID'],
                'hn' => $row['hn'],
                'ID' => $row['person_ID'],
                'Fname' => $row['Fname'],
                'located' => $row['work_located'],
                'time' => date('H:i', strtotime($row['data_date']))
            ];
        }
    }
}
$case_count = count($incoming_patients);
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
                <span class="fw-bolder" style="font-size: 1.25rem; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">ER SYSTEM</span>
                <span style="font-size: 0.75rem; opacity: 0.9; font-weight: 300;">แผนกฉุกเฉิน รพ.อานันทมหิดล</span>
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
                       href="<?php echo $path_prefix; ?>ER/indexER.php">
                       <i class="fa-solid fa-file-medical me-2"></i>บันทึกข้อมูล
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'history_centralER.php' || basename($_SERVER['PHP_SELF']) == 'history_central.php' ? 'active' : ''; ?>" 
                       href="<?php echo $path_prefix; ?>ER/history_centralER.php">
                       <i class="fa-solid fa-clock-rotate-left me-2"></i>ประวัติการรักษา
                    </a>
                </li>
            </ul>

            <!-- Right side Tools & Profile -->
            <ul class="navbar-nav ms-auto align-items-center gap-3">
                
                <!-- Notification Bell -->
                <li class="nav-item dropdown">
                    <a class="nav-link text-white position-relative p-2" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-bell fa-xl hover-scale <?php echo ($case_count > 0) ? 'bell-active' : ''; ?>"></i>
                        <?php if($case_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge">
                                <?php echo $case_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern" style="width: 380px;">
                        <!-- Dropdown Header -->
                        <div class="bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">
                                <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-circle me-2 d-inline-flex">
                                    <i class="fa-solid fa-ambulance"></i>
                                </div>
                                ค้างประเมิน / รอส่งตัว
                            </h6>
                            <span class="badge bg-danger rounded-pill px-2 py-1"><?php echo $case_count; ?> รายการ</span>
                        </div>

                        <!-- Dropdown Body (Scrollable) -->
                        <div class="custom-scrollbar" style="max-height: 400px; overflow-y: auto;">
                            <?php if($case_count > 0): ?>
                                <?php foreach ($incoming_patients as $case): ?>
                                    <div class="dropdown-item p-3 border-bottom case-card text-wrap">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <strong class="text-dark"><i class="fa-solid fa-user me-1 text-muted"></i> <?php echo htmlspecialchars($case['Fname']); ?></strong>
                                            <small class="text-danger fw-bold"><i class="fa-regular fa-clock me-1"></i><?php echo $case['time']; ?> น.</small>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <span class="badge bg-light text-dark border">HN: <?php echo htmlspecialchars($case['hn']); ?></span>
                                            <a href="<?= $path_prefix; ?>ER/indexER.php?hn=<?php echo urlencode(base64_encode($case['hn'])); ?>&rowid=<?php echo urlencode(base64_encode($case['row_id'])); ?>"
                                                class="btn btn-sm btn-outline-success rounded-pill px-3 py-1" style="font-size:0.75rem;"><i class="fa-solid fa-arrow-right"></i> ไปหน้าจัดการ</a>
                                        </div>
                                        <div class="small text-muted mt-2 text-truncate" style="max-width: 100%;"><i class="fa-solid fa-stethoscope me-1"></i> <?php echo htmlspecialchars($case['located']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-4 text-center text-muted">
                                    <i class="fa-regular fa-face-smile-beam fa-2x mb-2 text-success opacity-50"></i>
                                    <p class="mb-0 small">จัดการเคสเรียบร้อยแล้วทั้งหมด</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Dropdown Footer -->
                        <a href="<?php echo $path_prefix; ?>dashboard.php" class="dropdown-item text-center bg-light text-primary py-2 small fw-bold border-top">
                            ดูรายละเอียดในหน้า Dashboard
                        </a>
                    </ul>
                </li>
                
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