<?php
// กำหนด path ให้อัตโนมัติในกรณีที่ include จาก subfolder
$prefix = isset($path_prefix) ? $path_prefix : '';
?>
<style>
    .view-navbar {
        background-color: #0f172a;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        padding: 10px 0;
        font-family: 'Prompt', sans-serif;
    }
    .view-navbar .navbar-brand {
        font-weight: 700;
        color: #d4af37 !important; /* สีทอง */
        font-size: 1.4rem;
        letter-spacing: 0.5px;
    }
    .view-navbar .nav-link {
        color: rgba(255,255,255,0.85) !important;
        font-weight: 500;
        padding: 8px 16px;
        border-radius: 8px;
        transition: all 0.3s;
        margin: 0 5px;
    }
    .view-navbar .nav-link:hover, .view-navbar .nav-link.active {
        background-color: rgba(255,255,255,0.1);
        color: #fff !important;
    }
    .view-navbar .user-badge {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        padding: 5px 15px;
        border-radius: 50px;
        color: #fff;
        font-size: 0.9rem;
    }
</style>

<nav class="navbar navbar-expand-lg view-navbar sticky-top">
    <div class="container-fluid px-4" style="max-width: 1600px;">
        <a class="navbar-brand" href="indexPCT.php">
            <i class="fa-solid fa-eye me-2"></i>ระบบติดตามลมร้อน (Viewer)
        </a>
        <button class="navbar-toggler border-0 shadow-none text-white" type="button" data-bs-toggle="collapse" data-bs-target="#viewNavbar">
            <i class="fa-solid fa-bars fs-3"></i>
        </button>
        
        <div class="collapse navbar-collapse" id="viewNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'indexPCT.php' ? 'active' : ''; ?>" href="indexPCT.php">
                        <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ดภาพรวม
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'history_centralPCT.php' ? 'active' : ''; ?>" href="history_centralPCT.php">
                        <i class="fa-solid fa-book-medical me-1"></i> ทะเบียนผู้ป่วยรวม
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
                <div class="user-badge">
                    <i class="fa-regular fa-circle-user me-1"></i> <?php echo htmlspecialchars($_SESSION['name'] ?? 'Viewer'); ?> 
                    <span class="badge bg-secondary ms-1">ดูได้อย่างเดียว</span>
                </div>
                <a href="<?php echo $prefix; ?>logout.php" class="btn btn-danger btn-sm rounded-pill px-3 shadow-sm fw-bold" style="font-family: 'Prompt', sans-serif;">
                    <i class="fa-solid fa-right-from-bracket me-1"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </div>
</nav>