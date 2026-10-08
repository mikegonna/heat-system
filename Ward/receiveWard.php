<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

$case_id = $_GET['case_id'];
$sql = "SELECT c.* FROM cases c WHERE c.id = '$case_id'";
$row = $conn->query($sql)->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <title>รับผู้ป่วยเข้าแผนก</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&display=swap" rel="stylesheet">
    <style> body { font-family: 'Prompt', sans-serif; background: #fbfff1; } </style>
</head>
<body>
<div class="container py-5">
    <div class="card shadow border-0" style="max-width: 600px; margin: auto;">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0">รับผู้ป่วยเข้าหอผู้ป่วย (Admit Ward)</h4>
        </div>
        <div class="card-body p-4">
            <h5 class="fw-bold"><?php echo $row['patient_name']; ?> (HN: <?php echo $row['hn']; ?>)</h5>
            <hr>
            <form action="save_receive_ward.php" method="post">
                <input type="hidden" name="submit_receive" value="1">
                <input type="hidden" name="case_id" value="<?php echo $case_id; ?>">
                
                <div class="mb-3">
                    <label class="form-label fw-bold text-primary">เลข AN (Admission Number)</label>
                     <input type="text" name="an" class="form-control" required placeholder="ระบุเลข AN ที่ออกให้ผู้ป่วย">
                </div> 
                
                <div class="mb-3">
                    <label class="form-label">วันที่รับเข้า</label>
                    <input type="datetime-local" name="admit_date" class="form-control" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-success w-100 py-2">ยืนยันการรับเข้า</button>
                    <a href="indexWard.php" class="btn btn-light w-100 mt-2">ยกเลิก</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
<?php 
    $path_prefix = "../"; 
    require_once '../footer.php'; 
?>
</html>