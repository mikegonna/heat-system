<?php
    session_start();
    if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(); }
    require_once '../database/db_conn.php';
    header('Content-Type: application/json; charset=utf-8');

    $hn = isset($_GET['hn']) ? $conn->real_escape_string(trim($_GET['hn'])) : '';
    if (empty($hn)) { echo json_encode([]); exit(); }

    $result = $conn->query("
        SELECT 
            *
        FROM home_visits
        WHERE hn = '$hn'
        ORDER BY record_datetime ASC
    ");

    $rows = [];
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);