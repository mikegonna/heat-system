<?php
// Ward/fetch_dashboardWard.php
session_start();
require_once '../database/db_conn.php';

header('Content-Type: application/json');

$wardFilter = isset($_GET['ward']) ? $_GET['ward'] : '';
$whereClause = "";

// สร้างเงื่อนไข SQL: ถ้าไม่ใช่ ER ให้กรองตาม admit_type อย่างเดียว
if ($wardFilter !== 'ER' && !empty($wardFilter)) {
    // แก้ไข: ตัด ward_building ออก
    $whereClause = " AND admit_type LIKE '%$wardFilter%' ";
}

// 1. นับยอด Active
$sqlActive = "SELECT COUNT(*) as c FROM ward_details WHERE status = 'Active' $whereClause";
$active = $conn->query($sqlActive)->fetch_assoc()['c'];

// 2. นับยอด Admit ใหม่วันนี้
$today = date('Y-m-d');
$sqlNew = "SELECT COUNT(*) as c FROM ward_details WHERE status = 'Active' AND DATE(admit_date) = '$today' $whereClause";
$new_today = $conn->query($sqlNew)->fetch_assoc()['c'];

// 3. นับยอดจำหน่ายวันนี้
$sqlDischarge = "SELECT COUNT(*) as c FROM ward_details WHERE status IN ('Discharged', 'Dead', 'Refer', 'Admitted') AND DATE(discharge_date) = '$today' $whereClause";
$discharged = $conn->query($sqlDischarge)->fetch_assoc()['c'];

// 4. ดึงรายชื่อล่าสุด 5 คน (ตัด ward_building, ward_room ออกจาก SELECT)
$sqlRecent = "SELECT w.id, w.an, w.admit_date, w.admit_type, c.patient_name, c.hn
              FROM ward_details w
              JOIN cases c ON w.case_id = c.id
              WHERE w.status = 'Active' $whereClause
              ORDER BY w.admit_date DESC LIMIT 5";
$resultRecent = $conn->query($sqlRecent);

$recentData = [];
if ($resultRecent) {
    while($row = $resultRecent->fetch_assoc()) {
        $recentData[] = [
            'id' => $row['id'],
            'an' => $row['an'],
            'hn' => $row['hn'],
            'patient_name' => $row['patient_name'],
            'admit_type' => $row['admit_type'], // ส่งค่า admit_type ไปแทนแผนก/ห้อง
            'admit_date' => date('d/m/Y H:i', strtotime($row['admit_date']))
        ];
    }
}

echo json_encode([
    'active' => $active,
    'new_today' => $new_today,
    'discharged' => $discharged,
    'recent' => $recentData
]);
?>