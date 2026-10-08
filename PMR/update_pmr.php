<?php
session_start();
require_once '../database/db_conn.php';

if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $dt = $_POST['record_datetime'];
    $st = $_POST['pmr_status'];
    $nd = !empty($_POST['next_pmr_date']) ? $_POST['next_pmr_date'] : NULL;

    $sql = "UPDATE pmr_records SET record_datetime=?, pmr_status=?, next_pmr_date=? WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $dt, $st, $nd, $id);
    
    if ($stmt->execute()) {
        echo "<script>alert('แก้ไขสำเร็จ'); window.location='dashboardPCT.php';</script>";
    } else {
        echo "Error: " . $conn->error;
    }
}
?>