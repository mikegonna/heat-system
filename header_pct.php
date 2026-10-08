<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// กำหนด Path Prefix กรณี Include จากโฟลเดอร์ย่อย
$base_path = isset($path_prefix) ? $path_prefix : '../';
$current_page = basename($_SERVER['PHP_SELF']);

// ดึงชื่อผู้ใช้งาน
$user_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'Administrator';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'admin';
?>

<style>
    /* PCT / Admin Theme: Royal Blue & Gold (สีน้ำเงินพรีเมียมตัดทอง) */
    :root {
        --pct-primary: #4338ca; /* สีน้ำเงินหลัก */
        --pct-dark: #312e81;    /* สีน้ำเงินเข้ม */
        --pct-light: #818cf8;   /* สีน้ำเงินสว่าง */
        --pct-gold: #d4af37;    /* สีทองพรีเมียม */
        --pct-soft: #e0e7ff;    /* สีน้ำเงินอ่อนมากสำหรับ Hover บนมือถือ */
    }

    .navbar-pct-theme { 
        background: linear-gradient(135deg, var(--pct-dark) 0%, var(--pct-primary) 50%, var(--pct-light) 100%); 
        border-bottom: 3px solid var(--pct-gold); 
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
        color: var(--pct-dark) !important; 
        font-weight: 700; 
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    }
    
    /* User Profile Pill */
    .user-pill-pct {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 50px;
        padding: 6px 16px 6px 6px;
        border: 1px solid rgba(212, 175, 55, 0.4); /* ขอบสีทองจางๆ */
        transition: all 0.3s ease;
        backdrop-filter: blur(5px);
    }
    .user-pill-pct:hover {
        background: rgba(0, 0, 0, 0.3);
        border-color: var(--pct-gold);
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
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            border: 1px solid #f1f5f9;
        }
        
        /* ปรับเมนูหลักให้ตัวอักษรเป็นสีน้ำเงินเข้มและเรียงชิดซ้าย */
        .nav-link-custom {
            color: var(--pct-dark) !important;
            margin: 4px 0;
            padding: 12px 20px !important;
            border-radius: 10px;
            display: block;
        }
        .nav-link-custom:hover, .nav-link-custom.active {
            background-color: var(--pct-soft);
            color: var(--pct-dark) !important;
            box-shadow: none;
            transform: none;
        }
        
        /* เส้นแบ่งระหว่างเมนูหลักกับเมนูโปรไฟล์ */
        .navbar-nav.ms-auto {
            border-top: 1px solid #f1f5f9;
            margin-top: 10px;
            padding-top: 15px;
            align-items: stretch !important;
        }

        /* ปรับปุ่มโปรไฟล์ User */
        .user-pill-pct {
            background-color: #f8fafc !important;
            color: var(--pct-dark) !important;
            border-radius: 10px;
            padding: 10px 20px !important;
            border: 1px solid #e2e8f0;
            justify-content: flex-start;
            width: 100%;
        }
        .user-avatar { background-color: var(--pct-soft) !important; color: var(--pct-gold) !important; }
        .user-pill-pct:hover { background-color: #f1f5f9 !important; border-color: #cbd5e1; }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-pct-theme shadow-sm mb-4">
  <div class="container-fluid px-3">
    <!-- Brand & Logo -->
    <a class="navbar-brand d-flex align-items-center gap-3" href="<?= $base_path ?>indexPCT.php">
        <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px; color: var(--pct-gold);">
            <img src="<?= $base_path ?>img/AnanLogo.png" width="50" alt="Logo">
        </div>
        <div class="d-flex flex-column text-white">
            <span class="fw-bolder" style="font-size: 1.25rem; letter-spacing: 0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.3); color: var(--pct-gold);">PCT</span>
            <span style="font-size: 0.75rem; opacity: 0.95; font-weight: 500;">ศูนย์ควบคุมและบริหารจัดการ</span>
        </div>
    </a>
    
    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarPCT">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarPCT">
      <!-- Main Menu -->
      <ul class="navbar-nav me-auto ms-lg-4 gap-1">
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?= ($current_page == 'indexPCT.php') ? 'active' : '' ?>" href="<?= $base_path ?>PCT/indexPCT.php">
                <i class="fa-solid fa-chart-pie me-1"></i> แดชบอร์ด
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?= ($current_page == 'history_centralPCT.php') ? 'active' : '' ?>" href="<?= $base_path ?>PCT/history_centralPCT.php">
                <i class="fa-solid fa-address-book me-1"></i> ทะเบียนผู้ป่วยรวม
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?= ($current_page == 'history_delete_reason.php') ? 'active' : '' ?>" href="<?= $base_path ?>PCT/history_delete_reason.php">
                <i class="fa-solid fa-trash me-1"></i> ประวัติการลบข้อมูล
            </a>
        </li>
        <?php if($user_role == 'admin'): ?>
        <li class="nav-item">
            <a class="nav-link nav-link-custom <?= ($current_page == 'manage_users.php') ? 'active' : '' ?>" href="<?= $base_path ?>PCT/manage_users.php">
                <i class="fa-solid fa-users-gear me-1"></i> จัดการผู้ใช้งาน
            </a>
        </li>
        <?php endif; ?>
      </ul>
      
      <!-- Right Menu (User Profile) -->
      <ul class="navbar-nav ms-auto align-items-center">
        <li class="nav-item dropdown w-100">
            <a class="nav-link dropdown-toggle user-pill-pct d-flex align-items-center text-white text-decoration-none w-100" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-2 shadow-sm user-avatar" style="width: 32px; height: 32px; color: var(--pct-gold);">
                    <i class="fa-solid fa-crown"></i>
                </div>
                <div class="d-flex flex-column text-start me-2 pe-1">
                    <span class="fw-bold lh-1" style="font-size: 0.85rem;">
                        <?= htmlspecialchars(strtoupper($user_name)) ?>
                    </span>
                    <span style="font-size: 0.65rem; opacity: 0.8;">Hello, PCT</span>
                </div>
            </a>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-modern mt-2 p-2 w-100">
                <li class="px-3 py-2 text-muted small border-bottom mb-2">
                    เข้าสู่ระบบในชื่อ <strong class="text-dark"><?= htmlspecialchars($user_name) ?></strong>
                </li>
                <li>
                    <a class="dropdown-item rounded text-danger fw-bold py-2" href="<?= $base_path ?>logout.php">
                        <i class="fa-solid fa-power-off me-2"></i> ออกจากระบบ
                    </a>
                </li>
            </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<script src="<?= $base_path ?>bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>