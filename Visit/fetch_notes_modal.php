<?php
// fetch_notes_modal.php
require_once '../database/db_conn.php';

if (!isset($_GET['id'])) {
    echo "<div class='alert alert-danger'>Invalid Request</div>";
    exit;
}

$ward_detail_id = mysqli_real_escape_string($conn, $_GET['id']);

$sql = "SELECT * FROM ward_progress_notes WHERE ward_detail_id = '$ward_detail_id' ORDER BY note_datetime DESC";
$result = mysqli_query($conn, $sql);

if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $heatStatusColor = ($row['heat_status'] == 'Re-Heat') ? 'text-danger' : 'text-success';
?>
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong><?php echo date('d/m/Y H:i', strtotime($row['note_datetime'])); ?></strong>
            <span class="badge bg-white border border-secondary text-dark"><?php echo $row['doctor_name']; ?></span>
        </div>
        <div class="card-body">
            <div class="mb-2">
                <span class="fw-bold">สถานะความร้อน:</span> 
                <span class="<?php echo $heatStatusColor; ?> fw-bold"><?php echo $row['heat_status']; ?></span>
            </div>
            <div class="p-3 bg-light rounded border mb-2">
                <?php echo nl2br(htmlspecialchars($row['details'])); ?>
            </div>
            <div class="text-end text-muted small">
                แผนการรักษา: <strong><?php echo $row['treatment_result']; ?></strong>
            </div>
        </div>
    </div>
<?php 
    }
} else {
    echo "<div class='text-center text-muted py-4'><i class='fa-regular fa-clipboard me-2'></i> ยังไม่มีบันทึก Progress Note</div>";
}
?>