<?php
error_reporting(0); // ปิด error ป้องกัน UI แตก
require_once 'database/db_conn.php';

$ward_id = isset($_GET['ward_id']) ? (int)$_GET['ward_id'] : 0;
$date = isset($_GET['date']) ? mysqli_real_escape_string($conn, $_GET['date']) : '';

if ($ward_id === 0) {
    echo "<div class='text-danger text-center py-3'>ไม่พบรหัสอ้างอิงการรักษา</div>";
    exit;
}

// สร้าง Query สำหรับดึง Progress Notes ของ Ward ID นั้นๆ
$sql = "SELECT * FROM ward_progress_notes WHERE ward_detail_id = '$ward_id'";
if (!empty($date)) {
    $sql .= " AND DATE(note_datetime) = '$date'";
}
$sql .= " ORDER BY note_datetime DESC";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    // วนลูปสร้าง HTML กลับไปแสดงผล
    while ($row = $result->fetch_assoc()) {
        $isReheat = ($row['heat_status'] == 'Re-Heat');
        $borderClass = $isReheat ? 'border-danger' : 'border-success';
        $badgeClass = $isReheat ? 'bg-danger' : 'bg-success';
        $icon = $isReheat ? 'fa-fire' : 'fa-check-circle';

        echo '<div class="card mb-3 shadow-sm ' . $borderClass . '" style="border-left-width: 4px; text-align: left;">';
        echo '  <div class="card-body p-3">';
        echo '    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">';
        echo '      <div class="fw-bold" style="color: #334155;"><i class="fa-regular fa-clock me-1"></i> ' . date('d/m/Y H:i', strtotime($row['note_datetime'])) . ' น.</div>';
        echo '      <span class="badge bg-light text-dark border"><i class="fa-solid fa-user-pen text-muted me-1"></i> ' . htmlspecialchars($row['doctor_name']) . '</span>';
        echo '    </div>';
        echo '    <div class="mb-2">';
        echo '      <span class="badge ' . $badgeClass . ' me-2 shadow-sm"><i class="fa-solid ' . $icon . ' me-1"></i> Heat: ' . htmlspecialchars($row['heat_status'] ?? '-') . '</span>';
        echo '      <span class="badge bg-secondary shadow-sm"><i class="fa-solid fa-clipboard-check me-1"></i> ผล: ' . htmlspecialchars($row['treatment_result']) . '</span>';
        echo '    </div>';
        echo '    <div class="bg-light p-2 rounded small text-dark mt-2 border border-dashed" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.5;">' . htmlspecialchars($row['details']) . '</div>';
        echo '  </div>';
        echo '</div>';
    }
} else {
    // ถ้าไม่มีข้อมูล
    echo '<div class="text-center text-muted py-4 bg-light rounded border border-dashed">';
    echo '  <i class="fa-regular fa-folder-open fa-2x mb-2 opacity-50"></i><br>';
    echo '  <span class="fw-bold">ไม่พบข้อมูลบันทึกอาการ</span>';
    if (!empty($date)) {
        echo '<br><small>สำหรับวันที่ ' . date('d/m/Y', strtotime($date)) . '</small>';
    }
    echo '</div>';
}
?>