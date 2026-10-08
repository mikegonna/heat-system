<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

if (!isset($_GET['id'])) { header("Location: dashboardPCT.php"); exit(); }
$id = intval($_GET['id']);

$sql = "SELECT p.*, c.patient_name, c.hn FROM pmr_records p JOIN cases c ON p.hn = c.hn WHERE p.id = $id";
$result = $conn->query($sql);
if ($result->num_rows == 0) { echo "ไม่พบข้อมูล"; exit(); }
$row = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แก้ไขข้อมูล PMR</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500&display=swap" rel="stylesheet">
    <style>body{font-family:'Sarabun',sans-serif;background:#f0f2f5;}</style>
</head>
<body>
<?php 
    $path_prefix = "../"; // <--- เพิ่มบรรทัดนี้
    if(file_exists('../header_rehab.php')) {
        require_once '../header_rehab.php';
    } else {
        echo "<nav class='navbar navbar-dark bg-primary shadow-sm'><div class='container'><a class='navbar-brand'>MED Dashboard</a></div></nav>";
    }
?>
    <div class="container py-5">
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark"><h5>แก้ไขข้อมูล PMR</h5></div>
            <div class="card-body">
                <form action="update_pmr.php" method="POST">
                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                    <div class="mb-3">
                        <label>ผู้ป่วย (HN: <?php echo $row['hn']; ?>)</label>
                        <input type="text" class="form-control" value="<?php echo $row['patient_name']; ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label>วันที่บันทึก</label>
                        <input type="datetime-local" name="record_datetime" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($row['record_datetime'])); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>สถานะ PMR</label>
                        <input type="text" name="pmr_status" class="form-control" value="<?php echo $row['pmr_status']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>นัดครั้งถัดไป</label>
                        <input type="date" name="next_pmr_date" class="form-control" value="<?php echo $row['next_pmr_date']; ?>">
                    </div>
                    <button type="submit" name="update" class="btn btn-warning">บันทึกการแก้ไข</button>
                    <a href="dashboardPCT.php" class="btn btn-secondary">ยกเลิก</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>