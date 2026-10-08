<?php
// fetch_pmk.php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$hn = $_GET['hn'] ?? '';

if (empty($hn)) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ HN']);
    exit;
}

// ===================================================================================
// [ส่วนที่เพิ่มใหม่] MOCK DATA ZONE (สำหรับทดสอบปุ่มดึงข้อมูล)
// ถ้ากรอก HN เป็น 999, 888, หรือ test จะส่งข้อมูลจำลองกลับไปทันที
// ===================================================================================
if ($hn === '999' || $hn === '888' || strtolower($hn) === 'test') {
    
    // สร้างข้อมูลสมมติตาม HN ที่กรอก
    $mockData = [
        'cid' => '1103700123456',
        'hn' => $hn,
        'full_name' => ($hn === '999') ? 'พลทหาร ทดสอบ ระบบจำลอง' : 'นาย สมมติ ตัวอย่าง',
        'phone' => '089-999-8888',
        'age' => 22,
        'weight' => 65.5,
        'height' => 175,
        'person_type' => ($hn === '999') ? 'ทหารกองประจำการ' : 'พลเรือน',
        'department' => ($hn === '999') ? 'ร.31 พัน.3 รอ.' : '-',
        'congenital_disease' => '-'
    ];

    // ส่ง JSON กลับไปให้หน้า indexER.php ทันที
    echo json_encode(['status' => 'success', 'data' => $mockData]);
    exit; // จบการทำงาน ไม่ต้องไปต่อฐานข้อมูลจริง
}
// ===================================================================================


// --------------------------------------------------------
// ส่วนเชื่อมต่อฐานข้อมูลจริง (ทำงานเมื่อไม่ใช่ HN จำลอง)
// --------------------------------------------------------
$USE_MOCK_DATA = false; 

$DB_HOST = "localhost";
$DB_NAME = "heat_mn";
$DB_USER = "root";
$DB_PASS = "";

try {
    // เชื่อมต่อฐานข้อมูล Heat_MN ของเราเอง
    $dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8";
    $conn = new PDO($dsn, $DB_USER, $DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ดึงข้อมูลจากตาราง patients (เอาล่าสุด)
    $sql = "SELECT patient_id_card, hn, patient_name, phone, age, weight, height, person_type, department, congenital_disease 
            FROM cases 
            WHERE hn = :hn 
            ORDER BY id DESC LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':hn' => $hn]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $data = [
            'cid' => $result['patient_id_card'],
            'hn' => $result['hn'],
            'full_name' => $result['patient_name'],
            'phone' => $result['phone'],
            'age' => $result['age'],
            'weight' => $result['weight'], 
            'height' => $result['height'],
            'person_type' => $result['person_type'], 
            'department' => $result['department'],
            'congenital_disease' => $result['congenital_disease']
        ];

        echo json_encode(['status' => 'success', 'data' => $data]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบประวัติ HN: ' . $hn . ' ในระบบ']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>