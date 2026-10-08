<?php
// login.php
session_start();


require_once 'database/db_conn.php';

$loginError = "";

if (isset($_POST['login'])) {
    $name = $_POST['name'];
    $password = $_POST['password'];

    // ตรวจสอบชื่อผู้ใช้
    $stmt = $conn->prepare("SELECT * FROM users WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // ตรวจสอบรหัสผ่าน (รองรับทั้ง Hash และ Plain Text)
        $checkPassword = false;
        if (password_verify($password, $row['password'])) {
            $checkPassword = true;
        } elseif ($password === $row['password']) {
            $checkPassword = true; 
        }

        if ($checkPassword) {
            // เก็บ Session
            $_SESSION['user_id'] = $row['id']; 
            $_SESSION['name'] = $row['name'];
            $_SESSION['department'] = $row['department'];
            $_SESSION['role'] = $row['role'];

            // [แก้ไขจุดเชื่อมโยงหน้า] ตรวจสอบชื่อเพื่อแยกเส้นทาง
            if ($row['name'] == 'er') {
                header("Location: dashboard.php"); 
            } elseif ($row['name'] == 'gp') {
                header("Location: dashboard.php"); 
            }elseif ($row['name'] == 'a4') {
                header("Location: dashboard.php"); 
            } elseif ($row['name'] == 'b3') {
                header("Location: dashboard.php"); 
            } elseif ($row['name'] == 'icu') {
                header("Location: dashboard.php");
            } elseif ($row['name'] == 'rcu') {
                header("Location: dashboard.php");
            } elseif ($row['name'] == 'pcu') {
                header("Location: Visit/indexVisit.php");
            } elseif ($row['name'] == 'coc') {
                header("Location: Visit/indexVisit.php");  
            } elseif ($row['name'] == 'med') {
                header("Location: dashboard.php");      
            } elseif ($row['name'] == 'pmr') {
                header("Location: dashboard.php");  
            } elseif ($row['name'] == 'view') {
                header("Location: PCT/indexPCT.php");
            } elseif ($row['name'] == 'pct') {
                header("Location: PCT/indexPCT.php");
            }
            exit(); 
        } else {
            $loginError = "รหัสผ่านไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง";
        }
    } else {
        $loginError = "ไม่พบผู้ใช้งานชื่อนี้ในระบบ";
    }
}
?>

<?php 
// เรียกใช้งาน Header (โครงสร้าง HTML ส่วนบนจะมาจากไฟล์นี้)
$path_prefix = ""; 
require_once 'header.php';
?>

<div class="d-flex align-items-center justify-content-center w-100 flex-grow-1" style="min-height: 70vh;">
    <div class="card shadow-sm w-100 border-0" style="max-width: 450px; border-radius: 12px; overflow: hidden;">
        <div class="card-header text-white text-center py-4" style="background-color: #005461; border-bottom: 4px solid rgba(0,0,0,0.1);">
            <h4 class="m-0 font-prompt fw-bold"><i class="fa-solid fa-right-to-bracket me-2"></i>เข้าสู่ระบบ</h4>
            <small class="text-white-50 font-sarabun">ระบบจัดการโรคลมร้อน (Heat Stroke)</small>
        </div>
        <div class="card-body p-4 p-md-5 bg-white">
            <form action="login.php" method="POST">
                <div class="mb-4">
                    <label class="form-label text-secondary fw-bold small">ชื่อผู้ใช้งาน (Username)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="name" class="form-control border-start-0 bg-light" placeholder="กรอกชื่อผู้ใช้งาน" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary fw-bold small">รหัสผ่าน (Password)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" class="form-control border-start-0 bg-light" required placeholder="กรอกรหัสผ่าน">
                    </div>
                </div>
                <button type="submit" name="login" class="btn text-white w-100 fw-bold py-2 shadow-sm font-prompt" style="background-color: #005461; border-radius: 8px;">
                    เข้าสู่ระบบ
                </button>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($loginError)): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                title: 'เข้าสู่ระบบไม่สำเร็จ',
                text: '<?php echo $loginError; ?>',
                icon: 'error',
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#d33'
            });
        });
    </script>
<?php endif; ?>

<?php 
// เรียกใช้งาน Footer (ปิดโครงสร้าง HTML)
require_once 'footer.php'; 
?>