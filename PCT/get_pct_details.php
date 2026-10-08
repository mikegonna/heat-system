<?php
require_once '../database/db_conn.php';
date_default_timezone_set('Asia/Bangkok');

$hn = $_GET['hn'] ?? '';
$type = $_GET['type'] ?? '';

if (!$hn || !$type) { echo "<div class='alert alert-danger'>ข้อมูลไม่ครบถ้วน</div>"; exit; }

// --- 1. ER DETAILS ---
if ($type == 'ER') {
    $sql = "SELECT * FROM er_details e JOIN cases c ON e.case_id = c.id WHERE c.hn = '$hn' ORDER BY e.recorded_at DESC";
    $res = $conn->query($sql);
    echo "<h6 class='fw-bold text-primary mb-3'><i class='fa-solid fa-clock-rotate-left'></i> ประวัติการเข้ารับบริการ ER</h6>";
    
    if ($res->num_rows == 0) { echo "<p class='text-muted text-center'>ไม่พบประวัติ ER</p>"; return; }

    echo "<div class='card shadow-sm'><div class='list-group list-group-flush'>";
    while($row = $res->fetch_assoc()) {
        echo "<div class='list-group-item p-3'>";
        echo "<div class='d-flex justify-content-between mb-2'>
                <span class='fw-bold text-dark'>".date('d/m/Y H:i', strtotime($row['recorded_at']))."</span>
                <span class='badge bg-secondary'>".$row['disposition']."</span>
              </div>";
        echo "<div class='small text-muted mb-1'><strong>วินิจฉัย:</strong> ".$row['FstDx']."</div>";
        echo "<div class='small text-muted'><strong>สาเหตุ:</strong> ".$row['cause_factor']." | <strong>First Aid:</strong> ".$row['first_aid']."</div>";
        echo "</div>";
    }
    echo "</div></div>";
}

// --- 2. WARD DETAILS ---
else if ($type == 'WARD') {
    $sql = "SELECT * FROM ward_details w JOIN cases c ON w.case_id = c.id 
            WHERE c.hn = '$hn' AND w.admit_type LIKE 'Ward%' ORDER BY w.admit_date DESC";
    $res = $conn->query($sql);
    echo "<h6 class='fw-bold text-success mb-3'><i class='fa-solid fa-bed'></i> ประวัติการนอน Ward (A4/B3/RCU)</h6>";

    if ($res->num_rows == 0) { echo "<p class='text-muted text-center'>ไม่พบประวัติ Ward</p>"; return; }

    while($row = $res->fetch_assoc()) {
        echo "<div class='card mb-3 shadow-sm border-start border-success border-5'>
                <div class='card-body'>
                    <h6 class='fw-bold text-success'>AN: ".$row['an']." (" . $row['admit_type'] . ")</h6>
                    <p class='small mb-1'><i class='fa-regular fa-calendar'></i> รับเข้า: ".date('d/m/Y H:i', strtotime($row['admit_date']))."</p>
                    <p class='small mb-1'><strong>Dx แรกรับ:</strong> ".$row['initial_diagnosis']."</p>
                    <p class='small mb-1'><strong>Dx สุดท้าย:</strong> ".$row['final_diagnosis']."</p>
                    <p class='small mb-0'><strong>สถานะ:</strong> ".$row['status']." ".($row['discharge_date'] ? "(D/C: ".date('d/m/Y', strtotime($row['discharge_date'])).")" : "")."</p>
                </div>
              </div>";
    }
}

// --- 3. ICU DETAILS ---
else if ($type == 'ICU') {
    $sql = "SELECT * FROM ward_details w JOIN cases c ON w.case_id = c.id 
            WHERE c.hn = '$hn' AND w.admit_type LIKE 'ICU%' ORDER BY w.admit_date DESC";
    $res = $conn->query($sql);
    echo "<h6 class='fw-bold text-danger mb-3'><i class='fa-solid fa-heart-pulse'></i> ประวัติการรักษา ICU</h6>";

    if ($res->num_rows == 0) { echo "<p class='text-muted text-center'>ไม่พบประวัติ ICU</p>"; return; }

    while($row = $res->fetch_assoc()) {
        echo "<div class='card mb-3 shadow-sm border-start border-danger border-5'>
                <div class='card-body'>
                    <div class='d-flex justify-content-between'>
                        <h6 class='fw-bold text-danger'>AN: ".$row['an']."</h6>
                        <span class='badge bg-danger'>Temp: ".$row['icu_core_temp_first']."°C</span>
                    </div>
                    <p class='small mb-1 mt-2'><i class='fa-regular fa-calendar'></i> รับเข้า: ".date('d/m/Y H:i', strtotime($row['admit_date']))."</p>
                    <p class='small mb-1'><strong>ICU วินิจฉัยแรกรับ:</strong> ".$row['icu_diagnosis']."</p>
                    <p class='small mb-0'><strong>Control Temp:</strong> ".$row['icu_control_temp']." (30min: ".$row['icu_core_temp_30min']."°C)</p>
                </div>
              </div>";
    }
}

// --- 4. VISIT DETAILS ---
else if ($type == 'VISIT') {
    $sql = "SELECT * FROM home_visits WHERE hn = '$hn' ORDER BY record_datetime DESC";
    $res = $conn->query($sql);
    echo "<h6 class='fw-bold text-secondary mb-3'><i class='fa-solid fa-house-medical'></i> ประวัติการเยี่ยมบ้าน</h6>";

    if ($res->num_rows == 0) { echo "<p class='text-muted text-center'>ไม่พบประวัติเยี่ยมบ้าน</p>"; return; }

    echo "<div class='table-responsive'><table class='table table-bordered table-sm small'>
            <thead class='bg-light'><tr><th>วันที่</th><th>อาการ</th><th>การสังเกต</th><th>ผู้บันทึก</th></tr></thead><tbody>";
    while($row = $res->fetch_assoc()) {
        echo "<tr>
                <td>".date('d/m/Y H:i', strtotime($row['record_datetime']))."</td>
                <td>".$row['symptoms']."</td>
                <td>".$row['checklist_obs']."</td>
                <td>".$row['recorder_name']."</td>
              </tr>";
    }
    echo "</tbody></table></div>";
}

// --- 5. MED DETAILS ---
else if ($type == 'MED') {
    $sql = "SELECT * FROM med_records WHERE hn = '$hn' ORDER BY record_datetime DESC";
    $res = $conn->query($sql);
    echo "<h6 class='fw-bold text-info mb-3'><i class='fa-solid fa-user-doctor'></i> ประวัติการติดตาม MED</h6>";

    if ($res->num_rows == 0) { echo "<p class='text-muted text-center'>ไม่พบประวัติ MED</p>"; return; }

    echo "<ul class='list-group'>";
    while($row = $res->fetch_assoc()) {
        echo "<li class='list-group-item'>
                <div class='fw-bold'>".date('d/m/Y', strtotime($row['record_datetime']))."</div>
                <div>สถานะ: <span class='text-info'>".$row['med_status']."</span></div>
                <div class='small text-muted'>Note: ".$row['med_note']."</div>
                ".($row['next_med_date'] ? "<div class='small text-danger'>นัดต่อ: ".date('d/m/Y', strtotime($row['next_med_date']))."</div>" : "")."
              </li>";
    }
    echo "</ul>";
}

// --- 6. PMR DETAILS ---
else if ($type == 'PMR') {
    $sql = "SELECT * FROM pmr_records WHERE hn = '$hn' ORDER BY record_datetime DESC";
    $res = $conn->query($sql);
    echo "<h6 class='fw-bold text-warning text-dark mb-3'><i class='fa-solid fa-person-walking'></i> ประวัติการฟื้นฟู PMR</h6>";

    if ($res->num_rows == 0) { echo "<p class='text-muted text-center'>ไม่พบประวัติ PMR</p>"; return; }

    echo "<ul class='list-group'>";
    while($row = $res->fetch_assoc()) {
        echo "<li class='list-group-item'>
                <div class='fw-bold'>".date('d/m/Y', strtotime($row['record_datetime']))."</div>
                <div>สถานะ: <span class='text-warning text-dark fw-bold'>".$row['pmr_status']."</span></div>
                <div class='small text-muted'>Note: ".$row['pmr_note']."</div>
                ".($row['next_pmr_date'] ? "<div class='small text-danger'>นัดต่อ: ".date('d/m/Y', strtotime($row['next_pmr_date']))."</div>" : "")."
              </li>";
    }
    echo "</ul>";
}
?>