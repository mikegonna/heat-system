<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

// --- 1. ตรวจสอบสิทธิ์และกำหนดธีมสี (Theme Configuration) ---
$user_role = isset($_SESSION['name']) ? strtolower($_SESSION['name']) : '';
$current_user_name = strtoupper($user_role); // สำหรับ header_ward.php
$path_prefix = "../";

// ค่าเริ่มต้น (Default - Ward Theme: Sage Green)
$theme_main  = '#327c62'; 
$theme_bg    = '#f4fbf7';
$theme_light = '#e8f6ef';
$headerFile  = '../header_ward.php';
$cancelLink  = 'indexWard.php';

// กำหนดค่าตามแผนกเพื่อความสวยงาม
if ($user_role == 'icu') {
    $theme_main  = '#fb923c'; // ส้มอ่อน ICU (Soft Apricot)
    $theme_bg    = '#fff7ed';
    $theme_light = '#ffedd5';
    $headerFile  = '../header_icu.php';
    $cancelLink  = 'indexICU.php';
} elseif ($user_role == 'er') {
    $theme_main  = '#059669'; // เขียว ER
    $theme_bg    = '#ecfdf5';
    $theme_light = '#d1fae5';
    $headerFile  = '../header_er.php';
    $cancelLink  = '../dashboard.php';
} elseif ($user_role == 'pct') {
    $theme_main  = '#1d4ed8'; // น้ำเงิน PCT
    $theme_bg    = '#eff6ff';
    $theme_light = '#dbeafe';
    $headerFile  = '../header_pct.php';
    $cancelLink  = '../PCT/indexPCT.php';
} elseif ($user_role == 'med') {
    $theme_main  = '#0284c7'; // ฟ้า MED
    $theme_bg    = '#f0f9ff';
    $theme_light = '#e0f2fe';
    $headerFile  = '../header_followup.php';
    $cancelLink  = '../MED/indexMED.php';
} elseif ($user_role == 'pmr') {
    $theme_main  = '#8b5cf6'; // ม่วง PMR
    $theme_bg    = '#f5f3ff';
    $theme_light = '#ede9fe';
    $headerFile  = '../header_rehab.php';
    $cancelLink  = '../PMR/indexPMR.php';
} elseif ($user_role == 'pcu' || $user_role == 'coc') {
    $theme_main  = '#d6728d'; // ชมพูพาสเทล Visit
    $theme_bg    = '#fdf5f7';
    $theme_light = '#fae8ee';
    $headerFile  = '../header_visit.php';
    $cancelLink  = '../Visit/indexVisit.php';
}

// --- 2. ดึงข้อมูลเพื่อแก้ไข ---
if (isset($_GET['an'])) {
    $an = $conn->real_escape_string($_GET['an']);
    
    // ดึงข้อมูลผู้ป่วยและข้อมูลการ Admit
    $sql = "SELECT 
                w.id as ward_id, w.an, w.admit_type, w.admit_date, w.status,
                w.initial_diagnosis, w.final_diagnosis, w.discharge_date,
                w.discharge_status, w.discharge_plan, w.discharge_detail,
                w.doctor_name, w.treatment_result, w.death_cause,
                w.icu_core_temp_first, w.icu_core_temp_30min,
                w.icu_control_temp, w.icu_diagnosis,
                w.icu_time_symptoms, w.icu_time_hospital, w.icu_time_er_to_icu,
                c.id as case_id, c.hn, c.patient_id_card, c.patient_name,
                c.phone, c.age, c.weight, c.height, c.bmi,
                c.person_type, c.department, c.congenital_disease, c.created_at
            FROM ward_details w 
            JOIN cases c ON w.case_id = c.id 
            WHERE w.an = '$an'
            ORDER BY w.id DESC LIMIT 1";
    $result = $conn->query($sql);

    if (!$result || $result->num_rows == 0) {
        // ใช้ SweetAlert กรณีไม่พบข้อมูล
        echo "<!DOCTYPE html><html lang='th'><head><meta charset='UTF-8'><script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script><style>body { font-family: 'Sarabun', sans-serif; }</style></head><body>
              <script>Swal.fire({icon: 'error', title: 'ไม่พบข้อมูล', text: 'ไม่พบข้อมูลการรักษาที่ต้องการแก้ไข', confirmButtonText: 'ตกลง', confirmButtonColor: '#e11d48'}).then(() => { window.location='$cancelLink'; });</script>
              </body></html>";
        exit();
    }
    $row = $result->fetch_assoc();
    
    // เช็คว่าเป็น ICU หรือไม่ (เพื่อโชว์ฟอร์มเฉพาะ)
    $isICUData = (strpos($row['admit_type'], 'ICU') !== false);
    
    // รายการโรค
    $diagnosis_list = [
        "Prickly Heat", "Heat Edema", "Heat Syncope", "Heat Cramps",
        "Heat Tetany", "Rhabdomyolysis (CPK < 1,000)", "Rhabdomyolysis (CPK > 1,000)",
        "Heat Exhaustion", "Heat Stroke"
    ];
    
    // [1] จัดการค่า Initial Diagnosis
    $dx_val = $row['initial_diagnosis'];
    $is_other_dx = !in_array($dx_val, $diagnosis_list) && !empty($dx_val);

    // [2] จัดการค่า ICU Diagnosis
    $icu_dx_val = $row['icu_diagnosis'];
    $is_other_icu_dx = !in_array($icu_dx_val, $diagnosis_list) && !empty($icu_dx_val);

} else {
    header("Location: $cancelLink");
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขข้อมูลผู้ป่วย</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --t-main:  <?php echo $theme_main; ?>;
            --t-bg:    <?php echo $theme_bg; ?>;
            --t-light: <?php echo $theme_light; ?>;
            --t-hover: color-mix(in srgb, var(--t-main) 80%, black);
            --t-shadow: color-mix(in srgb, var(--t-main) 20%, transparent);
            --text-dark: #2c3e50;
        }

        body { 
            font-family: 'Sarabun', sans-serif; 
            background-color: var(--t-bg); 
            background-image: radial-gradient(ellipse 80% 40% at 50% -15%, var(--t-light), transparent);
            min-height: 100vh;
        }
        h1, h2, h3, h4, h5, h6, .font-prompt { font-family: 'Prompt', sans-serif; }

        /* Typography & Colors */
        .text-theme { color: var(--t-hover) !important; }
        .bg-theme-light { background-color: var(--t-light) !important; }

        /* Cards */
        .card-custom { 
            border: none; 
            border-radius: 18px; 
            box-shadow: 0 6px 20px rgba(0,0,0,0.04); 
            background: #fff;
            overflow: hidden;
        }
        .card-header-custom { 
            background: linear-gradient(135deg, var(--t-main) 0%, color-mix(in srgb, var(--t-main) 80%, black) 100%);
            color: white; 
            border-radius: 18px 18px 0 0 !important; 
            padding: 16px 20px;
            font-family: 'Prompt', sans-serif;
            font-weight: 600;
            border-bottom: none;
        }

        /* Form Controls */
        .form-label { font-weight: 600; color: var(--text-dark); font-size: 0.9rem; }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 10px 14px;
            font-size: 0.95rem;
            transition: all 0.2s;
            background-color: #f8fafc;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--t-main);
            box-shadow: 0 0 0 3px var(--t-shadow);
            background-color: #fff;
        }

        /* Section Title */
        .section-title {
            color: var(--t-hover);
            font-weight: 700;
            border-bottom: 2px solid var(--t-light);
            padding-bottom: 10px;
            margin-bottom: 20px;
            margin-top: 30px;
            font-family: 'Prompt', sans-serif;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
        }
        .section-title:first-child { margin-top: 0; }

        /* Buttons */
        .btn-theme { 
            background-color: var(--t-main); 
            color: white; 
            border-radius: 10px;
            font-family: 'Prompt', sans-serif;
            font-weight: 500;
            padding: 10px 20px;
            transition: all 0.25s;
            border: none;
        }
        .btn-theme:hover { 
            background-color: var(--t-hover); 
            color: white; 
            transform: translateY(-2px);
            box-shadow: 0 6px 15px var(--t-shadow);
        }
        .btn-outline-secondary {
            border-radius: 10px;
            padding: 10px 20px;
            font-family: 'Prompt', sans-serif;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-outline-secondary:hover { background-color: #e2e8f0; transform: translateY(-1px); }

        /* Alert Box */
        .alert-lock {
            background-color: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-left: 5px solid #94a3b8;
            border-radius: 12px;
            padding: 20px;
        }
    </style>
</head>
<body>

<?php 
    if(file_exists($headerFile)) {
        require_once $headerFile; 
    } else {
        require_once '../header_ward.php';
    }
?>

<div class="container py-5" style="max-width: 900px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="font-prompt fw-bold text-dark mb-0">
            <i class="fa-solid fa-pen-to-square text-theme me-2"></i>ระบบแก้ไขข้อมูลผู้ป่วย
        </h3>
        <a href="<?php echo $cancelLink; ?>" class="btn btn-outline-secondary btn-sm rounded-pill shadow-sm px-3">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
    </div>

    <div class="card card-custom">
        <div class="card-header-custom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fs-5"><i class="fa-solid fa-user-injured me-2"></i> ข้อมูลผู้ป่วย: <?php echo htmlspecialchars($row['patient_name']); ?></h5>
            <span class="badge bg-white px-3 py-2 rounded-pill fs-6 shadow-sm" style="color: var(--t-hover);">HN: <?php echo $row['hn']; ?></span>
        </div>
        <div class="card-body p-4 p-md-5">
            <form action="update_treatmentWard.php" method="POST">
                <input type="hidden" name="ward_id" value="<?php echo $row['ward_id']; ?>">
                <input type="hidden" name="case_id" value="<?php echo $row['case_id']; ?>">
                <!-- ส่ง Target กลับไปให้หน้า Update รู้ว่าต้องเด้งกลับไปไหน -->
                <input type="hidden" name="redirect_target" value="<?php echo $cancelLink; ?>">

                <h5 class="section-title"><i class="fa-solid fa-address-card me-2 opacity-75"></i> 1. ข้อมูลทั่วไป</h5>
                <div class="row g-3 mb-2">
                    <div class="col-md-4">
                        <label class="form-label">รหัส HN <span class="text-danger">*</span></label>
                        <input type="text" name="hn" class="form-control" value="<?php echo $row['hn']; ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">เลขบัตรประชาชน</label>
                        <input type="text" name="patient_id_card" class="form-control" value="<?php echo $row['patient_id_card']; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" name="patient_name" class="form-control" value="<?php echo htmlspecialchars($row['patient_name']); ?>" required>
                    </div>
                </div>
                
                <input type="hidden" name="phone" value="<?php echo $row['phone']; ?>">
                <input type="hidden" name="age" value="<?php echo $row['age']; ?>">
                <input type="hidden" name="person_type" value="<?php echo $row['person_type']; ?>">
                <input type="hidden" name="department" value="<?php echo $row['department']; ?>">

                <h5 class="section-title"><i class="fa-solid fa-procedures me-2 opacity-75"></i> 2. ข้อมูลการรักษา <span class="badge bg-theme-light text-theme border ms-2 fw-normal fs-6 px-3 py-1"><?php echo htmlspecialchars($row['admit_type']); ?></span></h5>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">เลข AN <span class="text-danger">*</span></label>
                        <input type="text" name="an" class="form-control" value="<?php echo htmlspecialchars($row['an']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">วันที่รับเข้ารักษา</label>
                        <input type="datetime-local" name="admit_date" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($row['admit_date'])); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">แพทย์เจ้าของไข้</label>
                        <input type="text" name="doctor_name" class="form-control" value="<?php echo htmlspecialchars($row['doctor_name']); ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">วินิจฉัยแรกรับ (First Diagnosis)</label>
                    <select name="initial_diagnosis_select" class="form-select" onchange="toggleOther(this, 'other_dx')">
                        <option value="">-- เลือก --</option>
                        <?php 
                        foreach($diagnosis_list as $dx) {
                            $sel = ($dx == $row['initial_diagnosis']) ? 'selected' : '';
                            echo "<option value='$dx' $sel>$dx</option>";
                        }
                        ?>
                        <option value="other" <?php if($is_other_dx) echo 'selected'; ?>>อื่นๆ ที่ไม่ใช่ Heat (ระบุ)</option>
                    </select>
                    <input type="text" name="initial_diagnosis_other" id="other_dx" 
                           class="form-control mt-2 shadow-sm <?php echo $is_other_dx ? '' : 'd-none'; ?>" 
                           value="<?php echo $is_other_dx ? htmlspecialchars($row['initial_diagnosis']) : ''; ?>" 
                           placeholder="ระบุวินิจฉัย...">
                </div>

                <?php if($isICUData): ?>
                <!-- โซนข้อมูลเฉพาะของ ICU -->
                <div class="p-4 mb-4 bg-theme-light border rounded-4 shadow-sm" style="border-color: var(--t-main) !important;">
                    <h5 class="fw-bold mb-3 d-flex align-items-center" style="color: var(--t-hover);">
                        <i class="fa-solid fa-heart-pulse fa-lg me-2"></i> ข้อมูลเฉพาะแผนก ICU
                    </h5>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">CoreTemp แรกรับ (°C)</label>
                            <input type="number" step="0.1" name="icu_core_temp_first" class="form-control bg-white" value="<?php echo htmlspecialchars($row['icu_core_temp_first'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CoreTemp 30 นาที (°C)</label>
                            <input type="number" step="0.1" name="icu_core_temp_30min" class="form-control bg-white" value="<?php echo htmlspecialchars($row['icu_core_temp_30min'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">การทำ Control Temp</label>
                            <select name="icu_control_temp" class="form-select bg-white">
                                <option value="" <?php if(empty($row['icu_control_temp'])) echo 'selected'; ?>>-- ไม่ระบุ --</option>
                                <option value="Yes" <?php if($row['icu_control_temp']=='Yes') echo 'selected'; ?>>ทำ (Yes)</option>
                                <option value="No" <?php if($row['icu_control_temp']=='No') echo 'selected'; ?>>ไม่ทำ (No)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วินิจฉัยแรกรับเข้า ICU</label>
                            <select name="icu_diagnosis_select" class="form-select bg-white" onchange="toggleOther(this, 'other_icu_dx')">
                                <option value="">-- เลือก --</option>
                                <?php 
                                foreach($diagnosis_list as $dx) {
                                    $sel = ($dx == $row['icu_diagnosis']) ? 'selected' : '';
                                    echo "<option value='$dx' $sel>$dx</option>";
                                }
                                ?>
                                <option value="other" <?php if($is_other_icu_dx) echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                            </select>
                            <input type="text" name="icu_diagnosis_other" id="other_icu_dx" 
                                   class="form-control mt-2 shadow-sm bg-white <?php echo $is_other_icu_dx ? '' : 'd-none'; ?>" 
                                   value="<?php echo $is_other_icu_dx ? htmlspecialchars($row['icu_diagnosis']) : ''; ?>" 
                                   placeholder="ระบุวินิจฉัย ICU...">
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- โซนล็อคสถานะการจำหน่าย -->
                <div class="alert alert-lock d-flex align-items-center mt-5 mb-0">
                    <div class="me-4"><i class="fa-solid fa-lock fa-3x opacity-50 text-secondary"></i></div>
                    <div>
                        <strong class="text-secondary fs-5 font-prompt d-block mb-1">ส่วนการจำหน่ายถูกล็อก (Locked)</strong>
                        <span class="text-muted" style="font-size: 0.95rem;">ไม่สามารถแก้ไขสถานะการจำหน่ายหรือรายละเอียดการ Discharge จากหน้านี้ได้ หากมีข้อผิดพลาดกรุณาแจ้ง Admin เพื่อดำเนินการแก้ไข</span>
                    </div>
                </div>
                
                <input type="hidden" name="status" value="<?php echo $row['status']; ?>">

                <div class="d-flex justify-content-center gap-3 mt-5 border-top pt-4">
                    <a href="<?php echo $cancelLink; ?>" class="btn btn-outline-secondary px-4 py-2 fw-bold bg-white text-dark shadow-sm"> 
                        <i class="fa-solid fa-xmark me-2"></i> ยกเลิก
                    </a>
                    
                    <button type="submit" name="update_ward" class="btn btn-theme px-5 py-2 fw-bold shadow-sm">
                        <i class="fa-solid fa-save me-2"></i> บันทึกการแก้ไข
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleOther(select, targetId) {
        var input = document.getElementById(targetId);
        if(select.value === 'other') {
            input.classList.remove('d-none');
            input.focus();
        } else {
            input.classList.add('d-none');
        }
    }
</script>

</body>
</html>