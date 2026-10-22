<?php
session_start();
require_once '../database/db_conn.php';

if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $dt = $_POST['record_datetime'];
    $st = $_POST['med_status'];
    $nd = !empty($_POST['next_med_date']) ? $_POST['next_med_date'] : NULL;

    $sql = "UPDATE med_records SET record_datetime=?, med_status=?, next_med_date=? WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $dt, $st, $nd, $id);
    
    if ($stmt->execute()) {
        echo "<script>alert('แก้ไขสำเร็จ'); window.location='dashboardPCT.php';</script>";
    } else {
        echo "Error: " . $conn->error;
    }
}
?>