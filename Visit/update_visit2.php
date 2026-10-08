<?php
session_start();
require_once '../database/db_conn.php';

if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $dt = $_POST['record_datetime'];
    $sym = $_POST['symptoms'];
    $rec = $_POST['recorder_name'];

    $sql = "UPDATE home_visits SET record_datetime=?, symptoms=?, recorder_name=? WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $dt, $sym, $rec, $id);
    
    if ($stmt->execute()) {
        echo "<script>alert('แก้ไขสำเร็จ'); window.location='dashboardPCT.php';</script>";
    } else {
        echo "Error: " . $conn->error;
    }
}
?>