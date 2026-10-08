<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

error_reporting(0);

// ตรวจสอบ HN
if (!isset($_GET['hn']) || trim($_GET['hn']) === '') {
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาระบุรหัส HN'
    ]);
    exit;
}

$hn  = trim($_GET['hn']);
$key = "hn";

$apiUrl = "http://10.0.0.8:3000/getHeatstork?key=".$key."&id=".urlencode($hn);

// เริ่ม curl
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// ถ้า curl error
if ($response === false) {
    echo json_encode([
        'success' => false,
        'message' => 'cURL Error',
        'detail'  => $error
    ]);
    exit;
}

// ถ้า API ไม่ตอบ 200
if ($httpCode != 200) {
    echo json_encode([
        'success' => false,
        'message' => 'API Error',
        'http_code' => $httpCode
    ]);
    exit;
}

// แปลง JSON
$data = json_decode($response, true);

// ถ้า JSON decode ไม่ได้
if ($data === null) {
    echo json_encode([
        'success' => false,
        'message' => 'API ไม่ได้ส่ง JSON ที่ถูกต้อง',
        'raw_response' => $response
    ]);
    exit;
}

// เช็คข้อมูลผู้ป่วย
if (!isset($data['PATIENTS']) || count($data['PATIENTS']) == 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ไม่พบข้อมูลผู้ป่วย'
    ]);
    exit;
}

$patient = $data['PATIENTS'][0];

$prefix = isset($patient['prefixName']) ? $patient['prefixName'] : '';
$fname  = isset($patient['firstName']) ? $patient['firstName'] : '';
$lname  = isset($patient['lastName'])  ? $patient['lastName']  : '';

$fullName = trim($prefix.' '.$fname.' '.$lname);

$result = [
    'hn' => isset($patient['HN']) ? $patient['HN'] : '',
    'an' => isset($patient['AN']) ? $patient['AN'] : '',
    'cid' => isset($patient['ID']) ? $patient['ID'] : '',
    'full_name_ready' => $fullName,
    'sex' => isset($patient['SEX']) ? $patient['SEX'] : '',
    'age' => isset($patient['AGE']) ? $patient['AGE'] : '',
    'phone' => isset($patient['TEL']) ? $patient['TEL'] : '',
    'affiliation' => isset($patient['affiliation']) ? $patient['affiliation'] : '',
    'type' => isset($patient['type']) ? $patient['type'] : '',
    'visit_date' => isset($patient['VisitDate']) ? $patient['VisitDate'] : '',
    'WHOname' => isset($patient['WHOname']) ? $patient['WHOname'] : '',
    'WHOTEL' => isset($patient['WHOTEL']) ? $patient['WHOTEL'] : ''
];

echo json_encode([
    'success' => true,
    'data' => $result
]);
?>