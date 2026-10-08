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
<div class="main-content container mt-4"></div>
