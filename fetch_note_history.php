<?php
require_once 'database/db_conn.php';

if (isset($_GET['ward_id'])) {
    $ward_id = $_GET['ward_id'];

    // ดึงประวัติ Note ของ ward_detail_id นั้นๆ
    $sql = "SELECT * FROM ward_progress_notes WHERE ward_detail_id = ? ORDER BY note_datetime DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $ward_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo '<div class="list-group">';
        while ($row = $result->fetch_assoc()) {
            echo '<div class="list-group-item list-group-item-action">';
            echo '<div class="d-flex w-100 justify-content-between">';
            echo '<h6 class="mb-1 text-primary fw-bold">' . htmlspecialchars($row['heat_status']) . '</h6>';
            echo '<small class="text-muted">' . date('d/m/Y H:i', strtotime($row['note_datetime'])) . '</small>';
            echo '</div>';
            echo '<p class="mb-1">' . nl2br(htmlspecialchars($row['details'])) . '</p>';
            echo '<small class="text-muted"><i class="fa-solid fa-user-nurse me-1"></i> ผู้บันทึก: ' . htmlspecialchars($row['doctor_name']) . '</small>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<div class="text-center py-4 text-muted"><i class="fa-solid fa-file-circle-xmark fa-3x mb-3"></i><br>ไม่พบประวัติการบันทึก Note สำหรับเคสนี้</div>';
    }
}
?>