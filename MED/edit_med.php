<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

if (!isset($_GET['id'])) { header("Location: dashboardPCT.php"); exit(); }
$id = intval($_GET['id']);

$sql = "SELECT m.*, c.patient_name, c.hn FROM med_records m JOIN cases c ON m.hn = c.hn WHERE m.id = $id";
$result = $conn->query($sql);
if ($result->num_rows == 0) { echo "ไม่พบข้อมูล"; exit(); }
$row = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แก้ไขข้อมูล MED</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500&display=swap" rel="stylesheet">
    <style>body{font-family:'Sarabun',sans-serif;background:#f0f2f5;}</style>
</head>
<body>
    <?php require_once '../header_pct.php'; ?>
    <div class="container py-5">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white"><h5>แก้ไขข้อมูล MED</h5></div>
            <div class="card-body">
                <form action="update_med.php" method="POST">
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
                        <label>สถานะ MED</label>
                        <input type="text" name="med_status" class="form-control" value="<?php echo $row['med_status']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>นัดครั้งถัดไป</label>
                        <input type="date" name="next_med_date" class="form-control" value="<?php echo $row['next_med_date']; ?>">
                    </div>
                    <button type="submit" name="update" class="btn btn-warning">บันทึกการแก้ไข</button>
                    <a href="dashboardPCT.php" class="btn btn-secondary">ยกเลิก</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>