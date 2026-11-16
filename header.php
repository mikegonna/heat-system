<?php
if (!isset($path_prefix)) { $path_prefix = ""; }
$er_link = ($path_prefix === "../") ? "" : "ER/";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบบริหารจัดการโรคลมร้อน - รพ.อานันทมหิดล</title>
    
    <link href="<?php echo $path_prefix; ?>bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f8f9fa; display: flex; flex-direction: column; min-height: 100vh; }
        .main-content { flex: 1; }
        .navbar-brand { font-weight: 600; letter-spacing: 0.5px; }
    </style>
</head>
<body>

<nav style="background-color: #005461;" class="navbar navbar-expand-lg navbar-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center lh-1" href="#">
    <img src="<?php echo $path_prefix; ?>img/AnanLogo.png" width="120" class="me-1">
    <span>
        <span class="d-block fw-bold fs-5">ระบบบริหารจัดการโรคลมร้อน</span>
        <span class="d-block text-white" style="font-size: 0.85rem;">โรงพยาบาลอานันทมหิดล</span>
    </span>
</a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">

            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <button class="nav-link border-0 bg-transparent" type="button" data-bs-toggle="modal" data-bs-target="#releaseNotesModal">
                        <i class="bi bi-megaphone"></i> อัปเดตระบบ <span class="badge bg-warning text-dark">v3.4</span>
                    </button>
                </li>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo isset($_SESSION['name']) ? $_SESSION['name'] : 'User'; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item text-danger" href="<?php echo $path_prefix; ?>logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="modal fade" id="releaseNotesModal" tabindex="-1" aria-labelledby="releaseNotesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header text-white" style="background-color: #005461;">
                <div>
                    <h5 class="modal-title" id="releaseNotesModalLabel">
                        <i class="bi bi-journal-text me-2"></i>รายละเอียดการปรับปรุงระบบ
                    </h5>
                    <small class="text-white-50">ประวัติเวอร์ชันตั้งแต่ 3.2</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <section class="mb-4">
                    <h6 class="fw-bold text-primary">Version 3.4 <span class="badge bg-primary">ปัจจุบัน</span></h6>
                    <ul class="mb-0">
                        <li>ปรับปรุงการแสดงจำนวนเคสและรายการเคสใน PCT, PMR และ Visit</li>
                        <li>เพิ่มวันนัดติดตามในหน้าบันทึกและรายการผู้ป่วย PMR</li>
                        <li>ปรับปรุงการส่งออกข้อมูล Excel และการทำงานของผู้ใช้ OCC</li>
                        <li>แก้ไขขั้นตอนจำหน่ายผู้ป่วยจาก Ward รวมถึงกรณีวินิจฉัยสุดท้ายเป็นโรคลมร้อน</li>
                    </ul>
                </section>
                <section class="mb-4">
                    <h6 class="fw-bold text-primary">Version 3.3</h6>
                    <ul class="mb-0">
                        <li>เพิ่มบันทึกความคิดเห็นเคสสำหรับ PCT</li>
                        <li>ปรับปรุงแบบฟอร์มติดตามของ MED พร้อมข้อมูลผลตรวจและวันติดตาม</li>
                        <li>เพิ่มและปรับปรุงผู้ใช้ OCC รวมถึงขั้นตอนการทำงานและแบบฟอร์ม</li>
                    </ul>
                </section>
                <section>
                    <h6 class="fw-bold text-primary">Version 3.2</h6>
                    <ul class="mb-0">
                        <li>ปรับกราฟ Heat ให้แสดงเดือนตามปีงบประมาณที่เลือก</li>
                        <li>เพิ่มป้ายแสดงประเภทการรับไว้รักษา</li>
                        <li>ปรับปรุงการตรวจสอบการรับผู้ป่วยเข้า Ward และข้อมูลผู้ติดต่อ</li>
                        <li>ปรับสีหน้าการรักษาใน Ward ให้อ่านง่ายขึ้น</li>
                    </ul>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo $path_prefix; ?>bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>

<div class="main-content container mt-4"></div>
