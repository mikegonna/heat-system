<?php
date_default_timezone_set('Asia/Bangkok');
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
$form_status_type = $_SESSION['statusType'] ?? '';
$form_status_message = $_SESSION['statusMsg'] ?? '';
unset($_SESSION['statusType'], $_SESSION['statusMsg']);
require_once '../database/db_conn.php';

// --- 1. ตรวจสอบแผนกและกำหนด Theme ---
$user_name = isset($_SESSION['name']) ? strtolower($_SESSION['name']) : '';
$isICU = ($user_name === 'icu');

// กำหนดชื่อ Ward ปลายทาง
$my_ward_val = "";
if ($user_name == 'icu') $my_ward_val = "Ward ICU";
elseif ($user_name == 'rcu') $my_ward_val = "Ward RCU";
elseif ($user_name == 'a4') $my_ward_val = "Ward A4";
elseif ($user_name == 'b3') $my_ward_val = "Ward B3";
else $my_ward_val = "Ward " . strtoupper($user_name);

// กำหนดตัวแปรสี Theme แบบ Dynamic
if ($isICU) {
    $t_main = '#FF8C00'; // ส้ม ICU (Orange-600)
    $t_dark = '#FF8C00';
    $t_light = '#ffedd5';
    $t_pale = '#fff7ed';
    $t_rgb = '234, 88, 12';
    $hero_icon = 'fa-heart-pulse';
    $dept_label = 'หอผู้ป่วยวิกฤต (ICU)';
    $header_file = '../header_icu.php';
} else {
    $t_main = '#327c62'; // เขียวพาสเทล Ward (Sage Green)
    $t_dark = '#2a6952';
    $t_light = '#e8f6ef';
    $t_pale = '#f4fbf7';
    $t_rgb = '50, 124, 98';
    $hero_icon = 'fa-bed-pulse';
    $dept_label = 'หอผู้ป่วยใน ('.strtoupper($user_name).')';
    $header_file = '../header_ward.php';
}

// รายการโรค
$diagnosis_list = [
    "Prickly Heat", "Heat Edema", "Heat Syncope", "Heat Cramps", 
    "Heat Tetany", "Rhabdomyolysis (CPK < 1,000)", "Rhabdomyolysis (CPK > 1,000)", 
    "Heat Exhaustion", "Heat Stroke"
];

// --- 2. ดึงข้อมูลเคสจาก ER (ถ้ามีการส่ง case_id มา) ---
$case_data = [];
if (isset($_GET['case_id'])) {
    $cid = mysqli_real_escape_string($conn, $_GET['case_id']);
    $sqlCase = "SELECT p.*, e.FstDx 
                FROM cases p 
                JOIN er_details e ON p.id = e.case_id 
                WHERE p.id = '$cid'";
    $resCase = $conn->query($sqlCase);
    if ($resCase && $resCase->num_rows > 0) {
        $case_data = $resCase->fetch_assoc();
    }
}

// ฟังก์ชันช่วยดึงค่า
function getVal($key, $data) {
    return isset($data[$key]) ? htmlspecialchars($data[$key]) : '';
}

// เช็คค่า person_type สำหรับ pre-select
$auto_person_type = getVal('person_type', $case_data);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รับผู้ป่วยใหม่ - <?php echo strtoupper($user_name); ?></title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ═══════════════════════════════════════════
           DYNAMIC THEME VARIABLES
        ═══════════════════════════════════════════ */
        :root {
            --w-main:  <?php echo $t_main; ?>;
            --w-dark:  <?php echo $t_dark; ?>;
            --w-light: <?php echo $t_light; ?>;
            --w-pale:  <?php echo $t_pale; ?>;
            --w-rgb:   <?php echo $t_rgb; ?>;
            --text-dark: #0f2027;
            --text-mid:  #374151;
            --border:    #e5e7eb;
            --shadow-sm: 0 2px 8px rgba(var(--w-rgb), 0.08);
            --shadow-md: 0 6px 20px rgba(var(--w-rgb), 0.12);
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background-color: var(--w-pale);
            background-image: radial-gradient(ellipse 70% 40% at 50% -10%, var(--w-light), transparent);
            color: var(--text-mid);
            min-height: 100vh;
        }
        h1,h2,h3,h4,h5,h6, .btn, .form-label, .section-title { font-family: 'Prompt', sans-serif; }

        /* ═══════════════════════════════════════════
           PAGE LAYOUT & HERO
        ═══════════════════════════════════════════ */
        .form-page-wrap { max-width: 1100px; margin: 0 auto; padding: 32px 15px 80px; }

        .btn-action {
            border-radius: 50px; padding: 8px 20px; font-weight: 600; transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 8px; border: none; font-size: 0.9rem;
        }

        .btn-back { background: #fff; border: 1px solid #cbd5e1; color: var(--t-text); }
        .btn-back:hover { background: #f1f5f9; }

        .form-hero {
            background: linear-gradient(135deg, var(--w-dark) 0%, var(--w-main) 100%);
            border-radius: 20px; padding: 35px 40px; margin-bottom: 30px;
            position: relative; overflow: hidden; box-shadow: var(--shadow-md); color: white;
        }
        .form-hero::before { content: ''; position: absolute; top: -50px; right: -30px; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; }
        .hero-badge {
            display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3); padding: 5px 16px; border-radius: 50px;
            font-size: 0.8rem; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 12px;
            backdrop-filter: blur(5px);
        }
        .form-hero h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 5px; text-shadow: 0 2px 4px rgba(0,0,0,0.15);}
        .form-hero p { color: rgba(255,255,255,0.85); font-size: 0.95rem; margin: 0; }
        .hero-icon { position: absolute; right: 40px; top: 50%; transform: translateY(-50%); font-size: 4.5rem; color: rgba(255,255,255,0.15); }

        /* 💡 ดีไซน์ปุ่มย้อนกลับมุมบนขวาแบบพรีเมียม 💡 */
        .btn-hero-back {
            position: absolute;
            top: 25px;
            right: 30px;
            background: #ffffff;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            color: #000000;
            padding: 8px 20px;
            border-radius: 50px;
            font-family: 'Prompt', sans-serif;
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            z-index: 10;
        }
        .btn-hero-back:hover {
            background: rgba(255, 255, 255, 0.3);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        /* ═══════════════════════════════════════════
           FORM CARDS & SECTIONS
        ═══════════════════════════════════════════ */
        .main-card { background: #fff; border-radius: 20px; box-shadow: var(--shadow-sm); border: 1px solid rgba(var(--w-rgb), 0.1); }
        .card-body { padding: 40px; }

        .form-section { background: #fff; border: 1px solid var(--border); border-radius: 16px; margin-bottom: 25px; overflow: hidden; transition: box-shadow 0.2s; }
        .form-section:hover { box-shadow: var(--shadow-sm); border-color: rgba(var(--w-rgb), 0.3); }
        
        .section-header { display: flex; align-items: center; gap: 14px; padding: 16px 24px; background: var(--w-pale); border-bottom: 1px solid var(--w-light); }
        .section-num {
            width: 32px; height: 32px; background: var(--w-main); color: #fff; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-size: 0.9rem; font-weight: 700; box-shadow: 0 2px 8px rgba(var(--w-rgb), 0.3);
        }
        .section-title { font-size: 1.1rem; font-weight: 600; color: var(--w-dark); margin: 0; }
        .section-body { padding: 25px; }

        /* ═══════════════════════════════════════════
           INPUTS & CONTROLS
        ═══════════════════════════════════════════ */
        .form-label { font-weight: 600; font-size: 0.85rem; color: var(--text-mid); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 8px; display: block; }
        .label-required::after { content: ' *'; color: #ef4444; font-size: 0.9em; }

        .form-control, .form-select {
            border-radius: 10px; border: 1.5px solid var(--border); padding: 10px 14px;
            font-size: 0.95rem; font-family: 'Sarabun', sans-serif; background: #fafafa; transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus { border-color: var(--w-main); background: #fff; box-shadow: 0 0 0 3px var(--w-light); outline: none; }
        .form-control[readonly] { background: #f8fafc; color: var(--text-mid); border-color: var(--border); }

        .input-group .btn { border-radius: 0 10px 10px 0; font-family: 'Prompt'; font-weight: 500; }
        
        #bmi { background: var(--w-pale) !important; color: var(--w-dark) !important; font-weight: 700; font-size: 1.1rem; text-align: center; border-color: var(--w-light) !important; }

        /* การไฮไลท์สีช่องเวลาดึงข้อมูล API สำเร็จ */
        .autofilled { animation: autofillPulse 2s ease; }
        @keyframes autofillPulse {
            0%   { background-color: #fef9c3; border-color: #f59e0b; color: #b45309; }
            50%  { background-color: var(--w-light); border-color: var(--w-main); color: var(--w-dark); }
            100% { background-color: #fafafa; border-color: var(--border); color: var(--text-mid); }
        }

        /* ═══════════════════════════════════════════
           BUTTONS
        ═══════════════════════════════════════════ */
        .btn-theme { background: var(--w-main); color: #fff; border: none; border-radius: 10px; padding: 12px 36px; font-size: 1rem; font-weight: 600; box-shadow: 0 4px 12px rgba(var(--w-rgb), 0.25); transition: 0.2s; }
        .btn-theme:hover { background: var(--w-dark); color: #fff; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(var(--w-rgb), 0.3); }
        .btn-outline-theme { border: 1.5px solid var(--border); background: #fff; color: var(--text-mid); border-radius: 10px; padding: 12px 28px; font-weight: 600; transition: 0.2s; }
        .btn-outline-theme:hover { background: #f1f5f9; border-color: #cbd5e1; }

        .btn-fetch { background: var(--w-light); color: var(--w-dark); border: 1.5px solid var(--w-main); font-weight: 600; transition: 0.2s; padding: 0 20px; }
        .btn-fetch:hover { background: var(--w-main); color: #fff; }

        /* ICU Specific Styling */
        .icu-block { background: #fff5f5; border: 1.5px solid #fecaca; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .icu-block .form-label { color: #b91c1c; }
        .icu-block .form-control { border-color: #fca5a5; }
        
        .sub-group { background: #f8fafc; border: 1.5px solid var(--border); padding: 15px; border-radius: 12px; margin-top: 10px; }
        
        @media (max-width: 768px) {
            .form-hero { padding: 25px; }
            .card-body { padding: 20px; }
            .section-body { padding: 15px; }
            .btn-hero-back { top: 15px; right: 15px; padding: 6px 14px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>

<div class="form-page-wrap">

    <div class="form-hero">
        <a href="<?php echo $isICU ? 'indexICU.php' : 'indexWard.php'; ?>" class="btn-hero-back">
            <i class="fa-solid fa-arrow-left"></i> กลับ
        </a>
        
        <div class="hero-badge"><i class="fa-solid fa-circle-dot fa-beat" style="color: <?php echo $isICU ? '#fde68a' : '#86efac'; ?>;"></i> <?php echo strtoupper($user_name); ?> · ADMISSION</div>
        <h2><i class="fa-solid <?php echo $hero_icon; ?> me-2"></i>รับผู้ป่วยใหม่เข้าสู่แผนก</h2>
        <p>กรุณากรอกข้อมูลเพื่อเปิดแฟ้มประวัติการรักษาผู้ป่วยใน (Admit) ในแผนก <?php echo $dept_label; ?></p>
        <i class="fa-solid <?php echo $hero_icon; ?> hero-icon"></i>
    </div>

    <div class="main-card">
        <div class="card-body">
            <form action="save_ward_direct.php" method="post">
                
                <?php if(!empty($case_data)): ?>
                    <input type="hidden" name="existing_case_id" value="<?php echo $case_data['id']; ?>">
                <?php endif; ?>

                <!-- SECTION 1: SEARCH & IDENTIFICATION -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">1</div>
                        <h6 class="section-title">ค้นหาและระบุตัวตนผู้ป่วย</h6>
                    </div>
                    <div class="section-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">ค้นหา HN (ดึงข้อมูลจากระบบ HIS API)</label>
                                <div class="input-group shadow-sm">
                                    <input type="text" name="search_hn" id="search_hn" class="form-control border-primary" placeholder="กรอกเลข HN เช่น 1111/68" 
                                           value="<?php echo getVal('hn', $case_data); ?>" <?php echo !empty($case_data) ? 'readonly' : ''; ?>>
                                    <button type="button" class="btn btn-fetch" onclick="fetchPatientFromAPI()" <?php echo !empty($case_data) ? 'disabled' : ''; ?>>
                                        <i class="fa-solid fa-cloud-arrow-down me-1"></i> ดึงข้อมูล
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label label-required">เลขประจำตัวประชาชน (13 หลัก)</label>
                                <input type="text" name="patient_id_card" id="patient_id_card" class="form-control" maxlength="13" required placeholder="x-xxxx-xxxxx-xx-x" 
                                       value="<?php echo getVal('patient_id_card', $case_data); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: PATIENT INFO -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">2</div>
                        <h6 class="section-title">ข้อมูลทั่วไปผู้ป่วย</h6>
                    </div>
                    <div class="section-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label label-required">HN (Hospital Number)</label>
                                <input type="text" name="hn" id="input_hn" class="form-control" required placeholder="ระบุ HN" value="<?php echo getVal('hn', $case_data); ?>">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label label-required">ยศ ชื่อ-นามสกุล</label>
                                <input type="text" name="patient_name" id="input_patient_name" class="form-control" required placeholder="ระบุชื่อ-สกุลเต็ม" value="<?php echo getVal('patient_name', $case_data); ?>">
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">เพศ</label>
                                <input type="text" name="sex" id="input_sex" class="form-control bg-light" placeholder="กรอกเพศ">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label label-required">อายุ (ปี)</label>
                                <input type="number" name="age" id="input_age" class="form-control" required placeholder="กรอกอายุ" value="<?php echo getVal('age', $case_data); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">เบอร์โทรศัพท์ติดต่อ</label>
                                <input type="tel" name="phone" id="input_phone" class="form-control" placeholder="กรอกเบอร์โทรศัพท์" value="<?php echo getVal('phone', $case_data); ?>">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">น้ำหนัก (kg)</label>
                                <input type="number" step="0.1" name="weight" id="weight" class="form-control" placeholder="กรอกน้ำหนัก" oninput="calculateBMI()" value="<?php echo getVal('weight', $case_data); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">ส่วนสูง (cm)</label>
                                <input type="number" step="0.1" name="height" id="height" class="form-control" placeholder="กรอกส่วนสูง" oninput="calculateBMI()" value="<?php echo getVal('height', $case_data); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">ค่าดัชนีมวลกาย (BMI)</label>
                                <input type="text" name="bmi" id="bmi" class="form-control" readonly  value="<?php echo getVal('bmi', $case_data); ?>">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label label-required">ประเภทกำลังพล</label>
                                <select name="person_type" id="person_type" class="form-select" onchange="toggleOther(this, 'other_person_type')" required>
                                    <option value="">-- เลือกประเภท --</option>
                                    <option value="ทหารกองประจำการ" <?php if($auto_person_type=='ทหารกองประจำการ') echo 'selected'; ?>>ทหารกองประจำการ</option>
                                    <option value="กำลังพล ทบ." <?php if($auto_person_type=='กำลังพล ทบ.') echo 'selected'; ?>>กำลังพล ทบ.</option>
                                    <option value="นอก ทบ." <?php if($auto_person_type=='นอก ทบ.') echo 'selected'; ?>>นอก ทบ.</option>
                                    <option value="อื่นๆ" <?php if(!in_array($auto_person_type, ['', 'ทหารกองประจำการ', 'กำลังพล ทบ.', 'นอก ทบ.'])) echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="other_person_type" id="other_person_type" 
                                       class="form-control mt-2 <?php echo (!in_array($auto_person_type, ['', 'ทหารกองประจำการ', 'กำลังพล ทบ.', 'นอก ทบ.'])) ? '' : 'd-none'; ?>" 
                                       placeholder="โปรดระบุ" value="<?php echo (!in_array($auto_person_type, ['', 'ทหารกองประจำการ', 'กำลังพล ทบ.', 'นอก ทบ.'])) ? $auto_person_type : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label label-required">สังกัด (นขต.)</label>
                                <input type="text" name="department" id="input_department" class="form-control" required placeholder="ระบุสังกัด" value="<?php echo getVal('department', $case_data); ?>">
                            </div>
                        </div>

                        <div class="mb-2 mt-3">
                            <label class="form-label">โรคประจำตัว</label>
                            <input type="text" name="congenital_disease" class="form-control" placeholder="ถ้าไม่มีให้ใส่ -" value="<?php echo getVal('congenital_disease', $case_data); ?>">
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: ADMISSION DETAILS -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">3</div>
                        <h6 class="section-title">ข้อมูลบันทึกอาการ</h6>
                    </div>
                    <div class="section-body">
                        
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label label-required">เลข AN</label>
                                <input type="text" name="an" id="input_an" class="form-control fw-bold border-primary" required placeholder="ระบุ AN">
                                <div class="form-text text-primary small"><i class="fa-solid fa-circle-info"></i> หากผู้ป่วยมี AN ระบบจะดึงมาใส่ให้อัตโนมัติ</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label label-required">วันเวลาที่รับเข้า ICU</label>
                                <input type="datetime-local" name="admit_date" id="admit_date" class="form-control" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">แพทย์เจ้าของไข้</label>
                                <input type="text" name="doctor_name" class="form-control" placeholder="ระบุชื่อแพทย์">
                            </div>
                        </div>

                        <?php 
                            $er_dx = getVal('FstDx', $case_data);
                            $selected_dx = ""; $other_dx = "";
                            if (in_array($er_dx, $diagnosis_list)) { $selected_dx = $er_dx; } 
                            elseif (!empty($er_dx)) { $selected_dx = "other"; $other_dx = $er_dx; }
                        ?>

                        <?php if($isICU): ?>
                            <!-- ICU SPECIFIC FIELDS -->
                            <div class="icu-block">
                                <h6 class="fw-bold mb-3"><i class="fa-solid fa-heart-pulse me-2"></i> ข้อมูลเฉพาะแผนกวิกฤต (ICU)</h6>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label small">วัน/เวลาที่ผู้ป่วยมีอาการ</label>
                                        <input type="datetime-local" name="icu_time_symptoms" class="form-control bg-white" value="">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">เวลาที่ถึง ER</label>
                                        <input type="datetime-local" name="icu_time_hospital" id="icu_time_hospital" class="form-control bg-white" value="">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">ระยะเวลา ER -> ICU (นาที)</label>
                                        <input type="text" name="icu_time_er_to_icu" class="form-control bg-white" placeholder="" readonly >
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label small">CoreTemp แรกรับ (°C)</label>
                                        <input type="number" step="0.1" name="icu_core_temp_first" class="form-control bg-white" placeholder="0.0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">CoreTemp 30 นาที (°C)</label>
                                        <input type="number" step="0.1" name="icu_core_temp_30min" class="form-control bg-white" placeholder="0.0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">ควบคุม Control Temp ใน 24 ชม.</label>
                                        <select name="icu_control_temp" class="form-select bg-white">
                                            <option value="">-- เลือก --</option>
                                            <option value="Yes">ได้ตามเกณฑ์</option>
                                            <option value="No">ไม่ได้ตามเกณฑ์</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label label-required text-danger">วินิจฉัยแรกรับ (ER)</label>
                                <select name="icu_diagnosis_select" class="form-select" onchange="toggleOther(this, 'icu_diagnosis_other')" required>
                                    <option value="">-- เลือกวินิจฉัย --</option>
                                    <?php foreach($diagnosis_list as $dx): ?>
                                        <option value='<?php echo $dx; ?>' <?php if($selected_dx == $dx) echo 'selected'; ?>><?php echo $dx; ?></option>
                                    <?php endforeach; ?>
                                    <option value="other" <?php if($selected_dx == 'other') echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="icu_diagnosis_other" id="icu_diagnosis_other" 
                                       class="form-control mt-2 border-danger <?php echo ($selected_dx == 'other') ? '' : 'd-none'; ?>" 
                                       placeholder="ระบุวินิจฉัย ICU..." value="<?php echo $other_dx; ?>">
                            </div>
                        <?php else: ?>
                            <!-- GENERAL WARD FIELDS -->
                            <div class="mb-2">
                                <label class="form-label label-required" style="color: var(--w-main);">วินิจฉัยแรกรับ (Ward Diagnosis)</label>
                                <select name="initial_diagnosis_select" class="form-select" onchange="toggleOther(this, 'initial_diagnosis_other')" required>
                                    <option value="">-- เลือกวินิจฉัย --</option>
                                    <?php foreach($diagnosis_list as $dx): ?>
                                        <option value='<?php echo $dx; ?>' <?php if($selected_dx == $dx) echo 'selected'; ?>><?php echo $dx; ?></option>
                                    <?php endforeach; ?>
                                    <option value="other" <?php if($selected_dx == 'other') echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="initial_diagnosis_other" id="initial_diagnosis_other" 
                                       class="form-control mt-2 <?php echo ($selected_dx == 'other') ? '' : 'd-none'; ?>" 
                                       placeholder="ระบุวินิจฉัย..." value="<?php echo $other_dx; ?>">
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- SECTION 4: DISPOSITION (ล็อกไว้ที่ Admit เสมอ) -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">4</div>
                        <h6 class="section-title">ผู้บันทึกและการจำหน่าย</h6>
                    </div>
                    <div class="section-body">
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label label-required">สถานะผู้ป่วย</label>
                                <select name="treatment_result" id="treatment_result" class="form-select bg-light" style="border: 2px solid var(--w-main);" readonly required>
                                    <option value="Admit" selected>Admit (รับเข้าแผนก)</option>
                                </select>
                            </div>

                            <!-- Admit To Where -->
                            <div class="col-md-8" id="admit_options">
                                <label class="form-label">Admit เข้าสู่แผนก</label>
                                <select class="form-select bg-light" disabled>
                                    <option value="">-- เลือก Ward ปลายทาง --</option>
                                    <option value="Ward ICU" <?php if($my_ward_val == 'Ward ICU') echo 'selected'; ?>>Ward ICU</option>
                                    <option value="Ward RCU" <?php if($my_ward_val == 'Ward RCU') echo 'selected'; ?>>Ward RCU</option>
                                    <option value="Ward A4" <?php if($my_ward_val == 'Ward A4') echo 'selected'; ?>>Ward A4</option>
                                    <option value="Ward B3" <?php if($my_ward_val == 'Ward B3') echo 'selected'; ?>>Ward B3</option>
                                </select>
                                <!-- ส่งค่าจริงไปยัง Form -->
                                <input type="hidden" name="admit_type" value="<?php echo $my_ward_val; ?>">
                                <div class="form-text mt-1 text-primary"><i class="fa-solid fa-circle-info me-1"></i> ระบบล็อกแผนกปลายทางเป็นแผนกของคุณโดยอัตโนมัติ</div>
                                
                                <!-- 💡 แจ้งเตือนแผนกปัจจุบันจาก API -->
                                <div id="api_current_ward_display" class="form-text mt-2 text-danger d-none fw-bold p-2 bg-danger bg-opacity-10 border border-danger rounded">
                                    <i class="fa-solid fa-location-dot me-1"></i> ขณะนี้ผู้ป่วยอยู่: <span id="api_ward_name" class="text-dark"></span> <span class="text-muted fw-normal" style="font-size:0.85em;">(ตามระบบ HIS)</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="d-flex justify-content-center gap-3 mt-5 border-top pt-4">
                    <button type="submit" name="submit_ward_direct" class="btn btn-theme px-5 py-2">
                        <i class="fa-solid fa-save me-2"></i> บันทึกข้อมูลเข้าระบบ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>

//ฟังก์ชั่นคำนวณเวลาจาก er to icu
function calculateDiff() {
    let er = document.getElementById('icu_time_hospital').value;
    let icu = document.getElementById('admit_date').value;

    if (er && icu) {
        let erTime = new Date(er);
        let icuTime = new Date(icu);

        let diffMs = erTime - icuTime; // ต่างกันเป็น ms
        let diffMin = Math.floor(diffMs / (1000 * 60)); // แปลงเป็นนาที

        if (diffMin >= 0) {
            document.querySelector('[name="icu_time_er_to_icu"]').value = diffMin;
        } else {
            document.querySelector('[name="icu_time_er_to_icu"]').value = '';
        }
    }
}

// trigger ตอนเลือกเวลา
document.getElementById('icu_time_hospital').addEventListener('change', calculateDiff);
document.getElementById('admit_date').addEventListener('change', calculateDiff);

    // --- ฟังก์ชันดึงข้อมูลจาก API ---
    function fetchPatientFromAPI() {
        let hn = document.getElementById('search_hn').value.trim();
        
        if (!hn) {
            Swal.fire({ icon: 'warning', title: 'แจ้งเตือน', text: 'กรุณากรอกเลข HN ที่ต้องการค้นหา', confirmButtonColor: 'var(--w-main)' });
            return;
        }

        Swal.fire({
            title: 'กำลังเชื่อมต่อฐานข้อมูล...',
            text: 'กรุณารอสักครู่',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        // ยิงไปที่ Proxy
        fetch('../fetch_patient_api.php?hn=' + encodeURIComponent(hn))
            .then(async response => {
                if (!response.ok) throw new Error(`HTTP Error ${response.status}`);
                const text = await response.text();
                try { return JSON.parse(text); } 
                catch (e) { throw new Error("ข้อมูลที่ได้รับไม่ใช่ JSON"); }
            })
            .then(res => {
                if (res.success || res.status === 'success') {
                    let pt = res.data;
                    
                    // กรอกข้อมูลทั่วไป
                    document.querySelector('input[name="patient_id_card"]').value = pt.cid || pt.ID || '';
                    document.querySelector('input[name="hn"]').value = pt.hn || pt.HN || '';
                    document.querySelector('input[name="patient_name"]').value = pt.full_name_ready || pt.full_name || '';
                    document.querySelector('input[name="phone"]').value = pt.phone || pt.TEL || '';
                    document.querySelector('input[name="age"]').value = pt.age || pt.AGE || '';
                    document.querySelector('input[name="department"]').value = pt.department || pt.affiliation || '';
                    
                    // 💡 ดักจับ AN จาก API
                    let apiAn = pt.an || pt.AN || '';
                    if (apiAn === 'null/null' || apiAn.toLowerCase() === 'null') { apiAn = ''; }
                    if (apiAn !== '') { 
                        document.querySelector('input[name="an"]').value = apiAn; 
                    }

                    // 💡 ดักจับ วันที่รับเข้าแผนก (Admit Date) จาก API
                    let admitDateInput = document.getElementById('admit_date');
                    let aDate = pt.admit_date_his || pt.visit_date || pt.AdmitDate || pt.VisitDate; // 💡 ดึงจาก HIS มาใส่ให้ชัวร์
                    if (admitDateInput && aDate && aDate !== 'null') {
                        try {
                            let d = new Date(aDate);
                            if (!isNaN(d.getTime())) {
                                let yyyy = d.getFullYear();
                                let mm = String(d.getMonth() + 1).padStart(2, '0');
                                let dd = String(d.getDate()).padStart(2, '0');
                                let hh = String(d.getHours()).padStart(2, '0');
                                let mins = String(d.getMinutes()).padStart(2, '0');
                                admitDateInput.value = `${yyyy}-${mm}-${dd}T${hh}:${mins}`;
                            }
                        } catch(e) { console.error('Admit Date parse error', e); }
                    }

                    // 💡 ดักจับ วันเวลาถึงโรงพยาบาล (VisitDate) สำหรับฟอร์ม ICU เท่านั้น
                    let icuHospitalInput = document.getElementById('icu_time_hospital');
                    let vDate = pt.visit_date || pt.VisitDate;
                    if (icuHospitalInput && vDate && vDate !== 'null') {
                        try {
                            let d = new Date(vDate);
                            if (!isNaN(d.getTime())) {
                                let yyyy = d.getFullYear();
                                let mm = String(d.getMonth() + 1).padStart(2, '0');
                                let dd = String(d.getDate()).padStart(2, '0');
                                let hh = String(d.getHours()).padStart(2, '0');
                                let mins = String(d.getMinutes()).padStart(2, '0');
                                icuHospitalInput.value = `${yyyy}-${mm}-${dd}T${hh}:${mins}`;
                            }
                        } catch(e) { console.error('Visit Date parse error', e); }
                    }

                    // 💡 ดักจับ แผนกปัจจุบันจาก API (Ward)
                    let apiWard = pt.ward_his || pt.Ward || '';
                    let currentWardDisplay = document.getElementById('api_current_ward_display');
                    let apiWardName = document.getElementById('api_ward_name');
                    
                    if (apiWard && apiWard !== 'null') {
                        if (apiWardName && currentWardDisplay) {
                            apiWardName.textContent = apiWard;
                            currentWardDisplay.classList.remove('d-none');
                            currentWardDisplay.classList.add('autofilled');
                            setTimeout(() => currentWardDisplay.classList.remove('autofilled'), 2000);
                        }
                    } else {
                        if (currentWardDisplay) currentWardDisplay.classList.add('d-none');
                    }

                    // เพศ
                    let apiSex = pt.sex || pt.SEX || '';
                    let sexText = apiSex;
                    if(apiSex === 'M') sexText = 'ชาย (Male)';
                    if(apiSex === 'F') sexText = 'หญิง (Female)';
                    document.querySelector('input[name="sex"]').value = sexText;

                    // จัดการ Select Person Type
                    let pType = pt.type_raw || pt.type || '';
                    let pAff = pt.department || pt.affiliation || '';
                    let selectPerson = document.querySelector('select[name="person_type"]');
                    let otherPerson = document.querySelector('input[name="other_person_type"]');
                    
                    let mappedType = "อื่นๆ";
                    if (pAff.includes("พลเรือน")) mappedType = "นอก ทบ.";
                    else if (pType.includes("กองประจำการ")) mappedType = "ทหารกองประจำการ";
                    else if (pType.includes("ทบ.")) mappedType = "กำลังพล ทบ.";

                    selectPerson.value = mappedType;
                    if (mappedType === "อื่นๆ") {
                        otherPerson.classList.remove('d-none');
                        otherPerson.required = true;
                        otherPerson.value = pType + (pAff ? " (" + pAff + ")" : "");
                    } else {
                        otherPerson.classList.add('d-none');
                        otherPerson.required = false;
                        otherPerson.value = '';
                    }

                    // ไฮไลท์ช่อง (Visual Feedback)
                    document.querySelectorAll('input[value]:not([value=""]), input:not([value=""])').forEach(el => {
                        if(el.value !== "" && el.id !== 'search_hn' && el.type !== 'hidden') {
                            el.classList.add('autofilled');
                            setTimeout(() => el.classList.remove('autofilled'), 2000);
                        }
                    });

                    Swal.fire({
                        icon: 'success',
                        title: 'พบข้อมูลผู้ป่วย',
                        text: 'ดึงข้อมูลเรียบร้อยแล้ว: ' + (pt.full_name_ready || pt.full_name),
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        title: 'ไม่พบข้อมูล',
                        text: res.message || 'ไม่พบประวัติ HN นี้ในระบบ',
                        icon: 'error',
                        confirmButtonColor: '#d33'
                    });
                }
            })
            .catch(err => {
                Swal.close();
                console.error(err);
                Swal.fire({
                    title: 'เกิดข้อผิดพลาด',
                    text: 'ไม่สามารถเชื่อมต่อกับ Server ได้: ' + err.message,
                    icon: 'error',
                    confirmButtonColor: '#d33'
                });
            });
    }

    // --- ฟังก์ชันคำนวณและ UI ---
    function calculateBMI() {
        let w = parseFloat(document.getElementById('weight').value);
        let h = parseFloat(document.getElementById('height').value) / 100;
        if(w > 0 && h > 0) {
            let bmi = (w / (h * h)).toFixed(2);
            document.getElementById('bmi').value = bmi;
        } else {
            document.getElementById('bmi').value = '';
        }
    }

    function toggleOther(select, targetId) {
        let target = document.getElementById(targetId);
        if (select.value === 'other' || select.value === 'อื่นๆ') {
            target.classList.remove('d-none');
            target.required = true;
            target.focus();
        } else {
            target.classList.add('d-none');
            target.required = false;
            target.value = ''; 
        }
    }
</script>

<?php if ($form_status_message !== ''): ?>
<script>
    Swal.fire({
        icon: <?php echo json_encode($form_status_type === 'success' ? 'success' : 'error'); ?>,
        title: <?php echo json_encode($form_status_type === 'success' ? 'สำเร็จ' : 'ไม่สามารถรับผู้ป่วยได้'); ?>,
        text: <?php echo json_encode($form_status_message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        confirmButtonColor: 'var(--w-main)'
    });
</script>
<?php endif; ?>

</body>
</html>