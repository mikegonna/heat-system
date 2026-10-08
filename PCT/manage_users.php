<?php
// เปิด Error เพื่อตรวจสอบ
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// ป้องกันการเข้าถึงหากไม่ได้ล็อกอินหรือไม่ได้เป็น admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') { 
    echo "<script>alert('ปฏิเสธการเข้าถึง: เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น!'); window.history.back();</script>";
    exit(); 
}

// 💡 แก้ไขปัญหา Path หักล้าง - ค้นหาไฟล์ฐานข้อมูลแบบยืดหยุ่น 
$db_paths = ['../database/db_conn.php', '../../database/db_conn.php', 'database/db_conn.php'];
$conn_found = false;
foreach ($db_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $conn_found = true;
        break;
    }
}
if (!$conn_found) {
    die("<div style='color:red; text-align:center; padding:50px;'><h3>Error: ไม่พบไฟล์ฐานข้อมูล</h3><p>ระบบไม่สามารถหาไฟล์เชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบโครงสร้างโฟลเดอร์</p></div>");
}

// ==============================================================================
// 🔍 ตรวจสอบชื่อคอลัมน์ทั้งหมดของตาราง users เพื่อป้องกัน Error Unknown column 100%
// ==============================================================================
$col_username = ''; // ค่าเริ่มต้น
$has_username = false;
$has_department = false;

$result_cols = $conn->query("SHOW COLUMNS FROM users");
if ($result_cols) {
    $columns = [];
    while ($row = $result_cols->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    // เช็คคอลัมน์ชื่อผู้ใช้
    if (in_array('user_name', $columns)) {
        $col_username = 'user_name';
        $has_username = true;
    } elseif (in_array('username', $columns)) {
        $col_username = 'username';
        $has_username = true;
    }
    
    // เช็คคอลัมน์แผนก
    if (in_array('department', $columns)) {
        $has_department = true;
    }
}

$statusMsg = '';
$statusType = '';

// ==============================================================================
// 🛠️ ส่วนจัดการข้อมูล (Action Handlers) เพิ่ม / แก้ไข / ลบ
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // 🟢 เพิ่มผู้ใช้ใหม่
    if ($action === 'add') {
        // ไม่ทำการ hash รหัสผ่านแล้ว บันทึกเป็น plain text
        $password = mysqli_real_escape_string($conn, trim($_POST['password'] ?? ''));
        $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
        $role = mysqli_real_escape_string($conn, $_POST['role'] ?? 'user');
        $department = mysqli_real_escape_string($conn, $_POST['department'] ?? '');
        
        $is_duplicate = false;
        if ($has_username) {
            $username = mysqli_real_escape_string($conn, trim($_POST['username'] ?? ''));
            $check = $conn->query("SELECT id FROM users WHERE $col_username = '$username'");
            if ($check && $check->num_rows > 0) $is_duplicate = true;
        } else {
            $check = $conn->query("SELECT id FROM users WHERE name = '$name'");
            if ($check && $check->num_rows > 0) $is_duplicate = true;
        }

        if ($is_duplicate) {
            $statusType = 'error'; $statusMsg = 'ชื่อผู้ใช้งาน (หรือชื่อ) นี้มีในระบบแล้ว';
        } else {
            $cols = "password, name, role";
            $vals = "'$password', '$name', '$role'";
            
            if ($has_username) {
                $cols .= ", $col_username";
                $vals .= ", '$username'";
            }
            if ($has_department) {
                $cols .= ", department";
                $vals .= ", '$department'";
            }
            
            if ($conn->query("INSERT INTO users ($cols) VALUES ($vals)")) {
                $statusType = 'success'; $statusMsg = 'เพิ่มผู้ใช้งานสำเร็จเรียบร้อย';
            } else {
                $statusType = 'error'; $statusMsg = 'เกิดข้อผิดพลาด: ' . $conn->error;
            }
        }
    } 
    
    // 🟠 แก้ไขผู้ใช้
    elseif ($action === 'edit') {
        $id = (int)($_POST['user_id'] ?? 0);
        $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
        $role = mysqli_real_escape_string($conn, $_POST['role'] ?? 'user');
        $department = mysqli_real_escape_string($conn, $_POST['department'] ?? '');
        
        $is_duplicate = false;
        if ($has_username) {
            $username = mysqli_real_escape_string($conn, trim($_POST['username'] ?? ''));
            $check = $conn->query("SELECT id FROM users WHERE $col_username = '$username' AND id != $id");
            if ($check && $check->num_rows > 0) $is_duplicate = true;
        } else {
            $check = $conn->query("SELECT id FROM users WHERE name = '$name' AND id != $id");
            if ($check && $check->num_rows > 0) $is_duplicate = true;
        }

        if ($is_duplicate) {
            $statusType = 'error'; $statusMsg = 'ชื่อผู้ใช้งาน (หรือชื่อ) นี้มีคนอื่นใช้แล้ว';
        } else {
            $updateSql = "UPDATE users SET name='$name', role='$role'";
            
            if ($has_username) {
                $updateSql .= ", $col_username='$username'";
            }
            if ($has_department) {
                $updateSql .= ", department='$department'";
            }
            
            // หากมีการกรอกรหัสผ่านใหม่ ให้อัปเดตแบบ plain text ไม่ต้องเข้ารหัส
            if (!empty($_POST['password'])) {
                $password = mysqli_real_escape_string($conn, trim($_POST['password']));
                $updateSql .= ", password='$password'";
            }
            
            $updateSql .= " WHERE id=$id";
            
            if ($conn->query($updateSql)) {
                $statusType = 'success'; $statusMsg = 'แก้ไขข้อมูลผู้ใช้งานสำเร็จ';
                
                // หากแก้ชื่อตัวเอง อัปเดต Session ให้อัตโนมัติ
                if ($id == $_SESSION['user_id']) {
                    $_SESSION['name'] = $_POST['name'] ?? '';
                    $_SESSION['role'] = $_POST['role'] ?? '';
                }
            } else {
                $statusType = 'error'; $statusMsg = 'เกิดข้อผิดพลาด: ' . $conn->error;
            }
        }
    } 
    
    // 🔴 ลบผู้ใช้ (นี่คือส่วนที่โค้ดเดิมขาดหายไป)
    elseif ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id == $_SESSION['user_id']) {
            $statusType = 'error'; $statusMsg = 'ไม่อนุญาตให้ลบบัญชีของตัวเองในขณะที่ใช้งานอยู่';
        } else {
            if ($conn->query("DELETE FROM users WHERE id=$id")) {
                $statusType = 'success'; $statusMsg = 'ลบผู้ใช้งานสำเร็จ';
            } else {
                $statusType = 'error'; $statusMsg = 'เกิดข้อผิดพลาด: ' . $conn->error;
            }
        }
    }
} // <--- วงเล็บปิดตรงนี้แหละครับที่หายไปทำให้จอขาว

// =====================================
// 📋 ดึงรายชื่อผู้ใช้ทั้งหมด (ใช้ SELECT * เพื่อป้องกันปัญหาชื่อคอลัมน์ไม่ตรง)
// =====================================
$users = [];
$res = $conn->query("SELECT * FROM users ORDER BY id DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        // จัดรูปแบบคอลัมน์ให้คงที่ เพื่อนำไปใช้กับหน้าจอ
        if ($has_username) {
            $row['username'] = $row[$col_username] ?? '-';
        } else {
            $row['username'] = '-';
        }
        $row['department'] = $row['department'] ?? '-';
        $users[] = $row;
    }
}

$depts = ['ER', 'Ward ICU', 'Ward RCU', 'Ward A4', 'Ward B3', 'MED', 'PMR', 'PCU', 'PCT', 'ศูนย์คอมพิวเตอร์', 'อื่นๆ'];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>จัดการผู้ใช้งานระบบ (User Management)</title>
    
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --pct-gold: #d4af37; 
            --pct-navy: #0f2027; 
            --pct-navy-light: #2c5364;
            --pct-blue: #1e3a8a;
            --bg-body: #f8fafc;
            --border-color: #e2e8f0;
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-body); color: #333; }
        h1, h2, h3, h4, h5, h6, .font-head { font-family: 'Prompt', sans-serif; }

        /* --- Hero Dashboard --- */
        .pct-hero {
            background: linear-gradient(135deg, var(--pct-navy) 0%, var(--pct-blue) 100%);
            color: white; padding: 35px 40px; border-radius: 20px;
            box-shadow: 0 10px 30px rgba(15, 32, 39, 0.2); margin-bottom: 25px;
            position: relative; overflow: hidden;
        }
        .pct-hero::after {
            content: '\f0c0'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            position: absolute; right: -10px; top: -10px; font-size: 15rem; color: rgba(255,255,255,0.05);
            transform: rotate(-15deg); pointer-events: none;
        }
        .hero-title { font-size: 2.2rem; font-weight: 700; color: var(--pct-gold); margin-bottom: 5px; text-shadow: 0 2px 4px rgba(0,0,0,0.3); line-height: 1.2;}
        
        /* --- Table Section --- */
        .data-card { background: white; border-radius: 20px; box-shadow: 0 5px 25px rgba(0,0,0,0.05); border: 1px solid var(--border-color); overflow: hidden; margin-top: 25px; }
        .data-card-header { background: #fff; padding: 20px 25px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;}
        
        /* Table Optimization */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-pct thead th { background-color: #f8fafc; color: var(--pct-navy); font-family: 'Prompt'; font-weight: 600; padding: 15px 20px; text-transform: uppercase; font-size: 0.85rem; border-bottom: 2px solid var(--border-color); white-space: nowrap;}
        .table-pct tbody td { padding: 15px 20px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
        .table-pct tbody tr:hover { background-color: #f8fafc; }
        
        .row-num { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #e2e8f0; border-radius: 8px; font-size: .75rem; font-weight: 700; color: #475569; }

        /* Badges */
        .badge-role-admin { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-size: 0.75rem;}
        .badge-role-user { background-color: #dcfce7; color: #166534; border: 1px solid #86efac; font-size: 0.75rem;}

        /* Buttons */
        .btn-theme { background: var(--pct-navy); color: #fff; border: none; border-radius: 10px; padding: 10px 24px; font-family: 'Prompt', sans-serif; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 8px; transition: all .2s; }
        .btn-theme:hover { background: var(--pct-blue); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(30, 58, 138, 0.3); }
        
        .btn-action { border-radius: 8px; font-size: 0.8rem; font-weight: 600; padding: 6px 12px; display: inline-flex; align-items: center; justify-content: center; gap: 5px; border: none; transition: 0.2s; white-space: nowrap;}
        .btn-action:hover { transform: translateY(-1px); }
        .btn-edit { background: #fffbeb; color: #d97706; border: 1px solid #fcd34d; }
        .btn-edit:hover { background: #fef3c7; }
        .btn-delete { background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; }
        .btn-delete:hover { background: #fee2e2; }

        /* Custom Modal Form */
        .modal-body .form-label { font-weight: bold; color: var(--pct-navy); font-size: 0.85rem; margin-bottom: 0.25rem;}
        .modal-body .form-control, .modal-body .form-select { border-radius: 10px; border-color: #cbd5e1; padding: 10px 15px;}
        .modal-body .form-control:focus, .modal-body .form-select:focus { border-color: var(--pct-blue); box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1); }

        /* ══════════════════════════════════════════
           📱 MOBILE RESPONSIVE TWEAKS
        ══════════════════════════════════════════ */
        @media (max-width: 768px) {
            .pct-hero { padding: 25px 20px; text-align: center; }
            .hero-title { font-size: 1.6rem; }
            .pct-hero .btn { width: 100%; margin-top: 15px; justify-content: center; }
            
            .data-card-header { flex-direction: column; align-items: flex-start; padding: 15px; }
            .data-card-header .badge { align-self: flex-start; }
            
            .table-pct thead th { padding: 12px 10px; font-size: 0.75rem; }
            .table-pct tbody td { padding: 12px 10px; }
            
            .val-name { font-size: 0.95rem; }
            .val-dept { font-size: 0.8rem; }
            
            /* จัดกลุ่มปุ่มเป็นแนวตั้งในจอมือถือ */
            .action-group { flex-direction: column; gap: 6px !important; }
            .btn-action { width: 100%; padding: 8px 10px; font-size: 0.75rem; }
        }
    </style>
</head>
<body>

<?php 
    // ดึงเมนูส่วนหัว
    if(file_exists('../header_pct.php')) { $path_prefix = "../"; require_once '../header_pct.php'; } 
    elseif(file_exists('../header_ward.php')) { $path_prefix = "../"; require_once '../header_ward.php'; } 
?>

<div class="container py-4 mb-5" style="max-width: 1400px;">
    
    <!-- แจ้งเตือนเมื่อทำรายการเสร็จ -->
    <?php if($statusMsg): ?>
        <script>
            Swal.fire({ icon: '<?php echo $statusType; ?>', title: 'แจ้งเตือน', text: '<?php echo $statusMsg; ?>', timer: 2500, showConfirmButton: false });
        </script>
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="pct-hero">
        <div class="row align-items-center position-relative" style="z-index: 1;">
            <div class="col-md-8 text-md-start">
                <div class="hero-title"><i class="fa-solid fa-users-gear me-2"></i>ระบบจัดการผู้ใช้งาน</div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <button class="btn btn-light text-dark rounded-pill px-4 py-2 shadow-sm font-head fw-bold border-0" onclick="new bootstrap.Modal(document.getElementById('addUserModal')).show()">
                    <i class="fa-solid fa-user-plus text-primary me-2"></i> เพิ่มบัญชีผู้ใช้ใหม่
                </button>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="data-card">
        <div class="data-card-header">
            <h5 class="mb-0 font-head fw-bold" style="color: var(--pct-navy);">
                <i class="fa-solid fa-table-list me-2 text-primary"></i> รายชื่อผู้ใช้งานในระบบ
            </h5>
            <span class="badge bg-light text-dark border px-3 py-2 fs-6 shadow-sm">
                ทั้งหมด <?php echo count($users); ?> บัญชี
            </span>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover table-pct mb-0">
                <thead>
                    <tr>
                        <th width="10%" class="text-center">ลำดับ</th>
                        <th width="45%">ชื่อ-นามสกุล / แผนก</th>
                        <th width="20%" class="text-center">สิทธิ์ (Role)</th>
                        <th width="25%" class="text-center">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fa-solid fa-users-slash fa-3x mb-3 opacity-25"></i><br>ไม่พบข้อมูลผู้ใช้งาน</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $index => $u): ?>
                            <?php 
                                // 💡 เตรียม Json ให้ JavaScript แบบปลอดภัย
                                $safe_row = [
                                    'id' => $u['id'],
                                    'name' => $u['name'],
                                    'department' => $u['department'],
                                    'role' => $u['role']
                                ];
                                if ($has_username) {
                                    $safe_row['username'] = $u['username'];
                                }
                                $json_data = htmlspecialchars(json_encode($safe_row), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td class="text-center fw-bold text-muted align-middle"><span class="row-num"><?php echo $index + 1; ?></span></td>
                                
                                <td class="align-middle">
                                    <div class="text-dark fw-bold val-name"><?php echo htmlspecialchars($u['name']); ?></div>
                                    <?php if($u['department'] !== '-'): ?>
                                        <div class="text-muted mt-1 val-dept"><i class="fa-regular fa-building me-1 opacity-75"></i><?php echo htmlspecialchars($u['department']); ?></div>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center align-middle">
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge badge-role-admin px-3 py-2 rounded-pill"><i class="fa-solid fa-user-shield me-1"></i> Admin</span>
                                    <?php else: ?>
                                        <span class="badge badge-role-user px-3 py-2 rounded-pill"><i class="fa-solid fa-user me-1"></i> User</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center align-middle">
                                    <div class="d-flex justify-content-center gap-2 action-group">
                                        <!-- 💡 ปุ่มแก้ไข: ส่งข้อมูล JSON ผ่าน Data Attribute แบบปลอดภัย -->
                                        <button type="button" class="btn btn-light border btn-action text-primary shadow-sm btn-edit" 
                                                data-json="<?php echo $json_data; ?>"
                                                onclick="openEditModal(this)" title="แก้ไขข้อมูล">
                                            <i class="fa-solid fa-pen"></i> แก้ไข
                                        </button>
                                        
                                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-danger btn-action shadow-sm btn-delete" onclick="confirmDelete(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>')">
                                                <i class="fa-solid fa-trash-can"></i> ลบ
                                            </button>
                                        <?php else: ?>
                                            <button class="btn bg-light text-muted border border-dashed btn-action" disabled title="ไม่สามารถลบตัวเองได้">
                                                <i class="fa-solid fa-lock"></i> ลบ
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =====================================
     MODALS
====================================== -->

<!-- 1. Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header bg-primary text-white" style="border-radius: 16px 16px 0 0; padding: 15px 20px;">
                    <h5 class="modal-title font-head fw-bold fs-6"><i class="fa-solid fa-user-plus me-2"></i> เพิ่มบัญชีผู้ใช้งานใหม่</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <?php if ($has_username): ?>
                    <div class="mb-3">
                        <label class="form-label text-dark">ชื่อเข้าสู่ระบบ<span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required autocomplete="off" placeholder="ใช้สำหรับเข้าสู่ระบบ">
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label text-dark">รหัสผ่าน<span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-dark">ชื่อแผนก<span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="เช่น หอผู้ป่วยอายุรกรรมหญิง">
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label">แผนก / สังกัด <span class="text-danger">*</span></label>
                            <select name="department" class="form-select" required>
                                <option value="">-- เลือกแผนก --</option>
                                <?php foreach($depts as $d) echo "<option value='$d'>$d</option>"; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-danger">สิทธิ์การใช้งาน (Role) <span class="text-danger">*</span></label>
                            <select name="role" class="form-select border-danger" required>
                                <option value="user" selected>User (ทั่วไป)</option>
                                <option value="admin">Admin (ผู้ดูแลระบบ)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white" style="border-radius: 0 0 16px 16px; padding: 15px 20px;">
                    <button type="button" class="btn btn-light border rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-save me-1"></i> ยืนยันเพิ่มข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2. Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="modal-header" style="background: #fffbeb; border-bottom: 2px solid #fde68a; border-radius: 16px 16px 0 0; padding: 15px 20px;">
                    <h5 class="modal-title font-head text-dark fw-bold fs-6"><i class="fa-solid fa-user-pen me-2"></i> แก้ไขข้อมูลผู้ใช้งาน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    
                    <div class="alert bg-white border border-info mb-4" style="border-radius: 12px; padding: 10px 15px;">
                        <div class="d-flex">
                            <i class="fa-solid fa-circle-info text-info mt-1 me-2"></i>
                            <span class="small text-muted" style="line-height: 1.4;"><strong>รหัสผ่าน:</strong> หากไม่ต้องการเปลี่ยนให้เว้นช่องรหัสผ่านไว้</span>
                        </div>
                    </div>
                    
                    <?php if ($has_username): ?>
                    <div class="mb-3">
                        <label class="form-label text-dark">ชื่อเข้าสู่ระบบ <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="edit_username" class="form-control fw-bold" required autocomplete="off">
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label text-primary">ตั้งรหัสผ่านใหม่ </label>
                        <input type="password" name="password" id="edit_password" class="form-control border-primary" placeholder="เว้นว่างหากไม่เปลี่ยน" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-dark">ชื่อแผนก <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label">แผนก / สังกัด <span class="text-danger">*</span></label>
                            <select name="department" id="edit_department" class="form-select" required>
                                <option value="">-- เลือกแผนก --</option>
                                <?php foreach($depts as $d) echo "<option value='$d'>$d</option>"; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-danger">สิทธิ์การใช้งาน (Role) <span class="text-danger">*</span></label>
                            <select name="role" id="edit_role" class="form-select border-danger" required>
                                <option value="user">User (ทั่วไป)</option>
                                <option value="admin">Admin (ผู้ดูแลระบบ)</option>
                                <option value="viewer">Viewer (ผู้เยี่ยมชม)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white" style="border-radius: 0 0 16px 16px; padding: 15px 20px;">
                    <button type="button" class="btn btn-light border rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold text-dark shadow-sm"><i class="fa-solid fa-save me-1"></i> บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form ซ่อนสำหรับลบ -->
<form id="deleteUserForm" method="POST">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<script>
    // 💡 1. ฟังก์ชันดึงค่าเก่ามาใส่ในช่อง Modal แก้ไขอัตโนมัติ
    function openEditModal(btn) {
        try {
            let jsonString = btn.getAttribute('data-json');
            let data = JSON.parse(jsonString);
            
            document.getElementById('edit_user_id').value = data.id || '';
            if (document.getElementById('edit_username')) {
                document.getElementById('edit_username').value = data.username || '';
            }
            document.getElementById('edit_name').value = data.name || '';
            
            // จัดการ Select แผนก (ดึงจากฐานข้อมูลมาโชว์ให้อัตโนมัติ)
            let deptSelect = document.getElementById('edit_department');
            if (data.department) {
                // หากแผนกของ User ไม่มีอยู่ใน List ปัจจุบัน ให้เพิ่มออปชันใหม่เข้าไปอัตโนมัติ
                let exists = Array.from(deptSelect.options).some(opt => opt.value === data.department);
                if (!exists && data.department !== '-') {
                    let newOption = new Option(data.department, data.department);
                    deptSelect.add(newOption);
                }
                deptSelect.value = (data.department === '-') ? '' : data.department;
            } else {
                deptSelect.value = '';
            }
            
            // จัดการ Select สิทธิ์
            let roleSelect = document.getElementById('edit_role');
            roleSelect.value = data.role || 'user';
            
            // ล้างรหัสผ่านทุกครั้งที่เปิด
            document.getElementById('edit_password').value = '';
            
            // สั่งเปิด Modal
            var editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
            editModal.show();
        } catch (e) {
            console.error("Error parsing JSON:", e);
            alert("เกิดข้อผิดพลาดในการโหลดข้อมูล JSON");
        }
    }

    // 💡 2. ฟังก์ชันยืนยันการลบ
    function confirmDelete(id, name) {
        Swal.fire({
            title: 'ยืนยันการลบผู้ใช้งาน?',
            html: `คุณต้องการลบบัญชีของ <b>${name}</b> ออกจากระบบใช่หรือไม่?<br><small class="text-danger">ข้อมูลที่ถูกลบจะไม่สามารถกู้คืนได้</small>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> ยืนยันการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_user_id').value = id;
                document.getElementById('deleteUserForm').submit();
            }
        });
    }
</script>

</body>
</html>