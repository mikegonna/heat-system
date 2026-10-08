<?php
require_once '../database/db_conn.php';

echo "<h3>🔍 ตรวจสอบข้อมูลในตาราง ward_details (Status = Active)</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr style='background:#eee;'>
        <th>ID</th>
        <th>Case ID</th>
        <th>AN</th>
        <th>Admit Type (ใน DB)</th>
        <th>Status</th>
        <th>Case Info (Join)</th>
      </tr>";

// ดึงข้อมูล Ward Details ที่ Active ทั้งหมด โดยไม่สน Filter
$sql = "SELECT w.*, c.patient_name 
        FROM ward_details w 
        LEFT JOIN cases c ON w.case_id = c.id 
        WHERE w.status = 'Active'";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        // เช็คว่า Admit Type มีคำว่า ICU ไหม
        $hasICU = (strpos($row['admit_type'], 'ICU') !== false) ? '✅' : '❌';
        
        // เช็คว่า JOIN cases เจอไหม
        $patientstatus = !empty($row['patient_name']) ? "✅ เจอ: ".$row['patient_name'] : "❌ ไม่พบ Case ID นี้";

        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['case_id']}</td>";
        echo "<td>{$row['an']}</td>";
        echo "<td>[{$row['admit_type']}] $hasICU</td>"; // ใส่ [] ครอบเพื่อดูว่ามีช่องว่างเกินไหม
        echo "<td>{$row['status']}</td>";
        echo "<td>{$patientstatus}</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='6'>ไม่พบข้อมูล Status = Active เลย</td></tr>";
}
echo "</table>";
?>