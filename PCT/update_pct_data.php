<?php
session_start();
require_once '../database/db_conn.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $type = $_POST['table_type'];
    $id = $_POST['record_id'];
    
    // เตรียมตัวแปร SQL
    $sql = "";
    $types = "";
    $params = [];

    if ($type === 'ER') {
        $sql = "UPDATE er_details SET FstDx = ?, disposition = ?, admit_type = ? WHERE id = ?";
        $types = "sssi";
        $params = [$_POST['FstDx'], $_POST['disposition'], $_POST['admit_type'], $id];

    } elseif ($type === 'WARD') {
        $sql = "UPDATE ward_details SET initial_diagnosis = ?, final_diagnosis = ?, status = ? WHERE id = ?";
        $types = "sssi";
        $params = [$_POST['initial_diagnosis'], $_POST['final_diagnosis'], $_POST['status'], $id];

    } elseif ($type === 'ICU') {
        $sql = "UPDATE ward_details SET initial_diagnosis = ?, final_diagnosis = ?, status = ?, icu_core_temp_first = ? WHERE id = ?";
        $types = "sssdi";
        $params = [$_POST['initial_diagnosis'], $_POST['final_diagnosis'], $_POST['status'], $_POST['icu_core_temp_first'], $id];

    } elseif ($type === 'MED') {
        $next_date = !empty($_POST['next_med_date']) ? $_POST['next_med_date'] : NULL;
        $sql = "UPDATE med_records SET med_status = ?, med_note = ?, next_med_date = ? WHERE id = ?";
        $types = "sssi";
        $params = [$_POST['med_status'], $_POST['med_note'], $next_date, $id];

    } elseif ($type === 'PMR') {
        $next_date = !empty($_POST['next_pmr_date']) ? $_POST['next_pmr_date'] : NULL;
        $sql = "UPDATE pmr_records SET pmr_status = ?, pmr_note = ?, next_pmr_date = ? WHERE id = ?";
        $types = "sssi";
        $params = [$_POST['pmr_status'], $_POST['pmr_note'], $next_date, $id];

    } elseif ($type === 'VISIT') {
        $sql = "UPDATE home_visits SET symptoms = ?, checklist_obs = ?, body_temperature = ? WHERE id = ?";
        $types = "ssdi";
        $params = [$_POST['symptoms'], $_POST['checklist_obs'], $_POST['body_temperature'], $id];
    }

    // Execute Update
    if (!empty($sql)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $_SESSION['statusType'] = 'success';
            $_SESSION['statusMsg'] = 'แก้ไขข้อมูลสำเร็จ';
        } else {
            $_SESSION['statusType'] = 'error';
            $_SESSION['statusMsg'] = 'เกิดข้อผิดพลาด: ' . $stmt->error;
        }
        $stmt->close();
    }

    header("Location: dashboardPCT.php");
    exit();
}
?>