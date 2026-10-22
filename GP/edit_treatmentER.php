<?php 
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); } 
require_once '../database/db_conn.php';  

if (isset($_GET['id'])) {
    $id = intval($_GET['id']); // ID ของ er_details
    
    // ดึงข้อมูล
    $query = "SELECT c.*, e.*, c.id AS master_case_id, e.id AS er_record_id 
              FROM er_details e 
              JOIN cases c ON e.case_id = c.id 
              WHERE e.id = $id";
              
    $result = mysqli_query($conn, $query);
    if (mysqli_num_rows($result) == 0) {
        echo "<script>alert('ไม่พบข้อมูล'); window.location='history_central.php';</script>";
        exit();
    }
    $row = mysqli_fetch_assoc($result);
    
    // --- จัดการ Checkbox ---
    $risk_factors_string = $row['risk_factors'];
    $risk_factors_array = array_map('trim', explode(",", $risk_factors_string));

    // --- จัดการ Logic ช่อง 'อื่นๆ' ---
    $std_person_types = ['ทหารกองประจำการ', 'กำลังพล ทบ.', 'นอก ทบ.'];
    $person_val = $row['person_type'];
    $is_other_person = !in_array($person_val, $std_person_types) && !empty($person_val);
    
    $std_causes = ['ฝึกทางการทหาร', 'ออกกำลังกายกลางวันในที่แจ้ง', 'ทำงานกลางแจ้ง'];
    $cause_val = $row['cause_factor'];
    $is_other_cause = !in_array($cause_val, $std_causes) && !empty($cause_val);

    $std_diagnoses = [
        "Heat Rash", "Heat Edema", "Heat Cramp", "Heat Syncope", 
        "Heat Tetany", "Heat Rhabdomyolysis", "Heat Exhaustion", 
        "Heat Exertional Rh", "Heat Stroke"
    ];
    $dx_val = $row['FstDx'];
    
    if (in_array($dx_val, $std_diagnoses)) {
        $selected_dx = $dx_val;
        $other_dx_val = "";
        $is_other_dx = false;
    } else {
        $selected_dx = "other";
        $other_dx_val = $dx_val;
        $is_other_dx = true;
    }

} else {
    header('location: history_central.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แก้ไขข้อมูลการรักษา (ER)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Prompt:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f0f2f0; }
        h1, h2, h3, h4, h5, .card-header, .btn { font-family: 'Prompt', sans-serif; }
        .card-header-military { background-color: #0c784b !important; color: white !important; }
        .section-title { font-size: 1.1rem; font-weight: 600; color: #0c784b; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-top: 25px; margin-bottom: 15px; }
        .form-label { color: #0c784b; font-weight: 500; }
    </style>
</head>

<?php 
    $path_prefix = "../"; 
    // เลือก Header
    $user_role = isset($_SESSION['name']) ? strtolower($_SESSION['name']) : '';
    if ($user_role == 'icu') require_once '../header_icu.php';
    elseif ($user_role == 'pct') require_once '../header_pct.php';
    elseif ($user_role == 'er') require_once '../header_er.php';
    elseif ($user_role == 'gp') require_once '../header_gp.php';
    else require_once '../header_ward.php'; 
?>

<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card border-0 shadow-sm">
            <?php 
                // ตรวจสอบสิทธิ์เพื่อกำหนดลิงก์ปุ่มยกเลิก
                $cancel_link = "history_central.php"; // ค่าเริ่มต้น (สำหรับ ER)
                if (isset($_SESSION['name']) && strtolower($_SESSION['name']) == 'pct') {
                    $cancel_link = "../PCT/dashboardPCT.php"; // ถ้าเป็น PCT ให้กลับไป Dashboard PCT
                }
            ?>

            <div class="card-header card-header-military d-flex justify-content-between align-items-center p-3">
                <h4 class="mb-0"><i class="fa-solid fa-pen-to-square me-2"></i> แก้ไขข้อมูล ER</h4>
                
                <a href="<?php echo $cancel_link; ?>" class="btn btn-light btn-sm text-success fw-bold"> 
                    <i class="fa-solid fa-xmark"></i> ยกเลิก
                </a>
            </div>
                <div class="card-body p-4">
                    
                    <form action="update_treatmentER.php" method="post">
                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">

                        <div class="section-title">1. ข้อมูลทั่วไป</div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">เลขบัตรประชาชน</label>
                                <input type="text" name="patient_id_card" class="form-control" value="<?php echo $row['patient_id_card']; ?>" maxlength="13">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">HN</label>
                                <input type="text" name="hn" class="form-control" value="<?php echo $row['hn']; ?>" required>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">ชื่อ-นามสกุล</label>
                                <input type="text" name="patient_name" class="form-control" value="<?php echo $row['patient_name']; ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">เบอร์โทรศัพท์</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo $row['phone']; ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">อายุ (ปี)</label>
                                <input type="number" name="age" class="form-control" value="<?php echo $row['age']; ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">น้ำหนัก (kg)</label>
                                <input type="number" step="0.1" name="weight" id="weight" class="form-control" value="<?php echo $row['weight']; ?>" oninput="calculateBMI()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">ส่วนสูง (cm)</label>
                                <input type="number" step="0.1" name="height" id="height" class="form-control" value="<?php echo $row['height']; ?>" oninput="calculateBMI()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">BMI</label>
                                <input type="text" name="bmi" id="bmi" class="form-control bg-light" value="<?php echo $row['bmi']; ?>" readonly>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">ประเภทบุคคล</label>
                                <select name="person_type" id="person_type" class="form-select" onchange="toggleOther(this, 'other_person_type')">
                                    <option value="ทหารกองประจำการ" <?php if($person_val == 'ทหารกองประจำการ') echo 'selected'; ?>>ทหารกองประจำการ</option>
                                    <option value="กำลังพล ทบ." <?php if($person_val == 'กำลังพล ทบ.') echo 'selected'; ?>>กำลังพล ทบ.</option>
                                    <option value="นอก ทบ." <?php if($person_val == 'นอก ทบ.') echo 'selected'; ?>>นอก ทบ.</option>
                                    <option value="อื่นๆ" <?php if($is_other_person) echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="other_person_type" id="other_person_type" class="form-control mt-2 <?php echo $is_other_person ? '' : 'd-none'; ?>" value="<?php echo $is_other_person ? $person_val : ''; ?>" placeholder="โปรดระบุ">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">สังกัด</label>
                                <input type="text" name="department" class="form-control" value="<?php echo $row['department']; ?>">
                            </div>
                        </div>

                        <div class="section-title">2. ข้อมูลการรักษา (ER)</div>
                        <div class="mb-3">
                            <label class="form-label">โรคประจำตัว</label>
                            <input type="text" name="congenital_disease" class="form-control" value="<?php echo $row['congenital_disease']; ?>">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">สาเหตุ</label>
                                <select name="cause_factor" id="cause_factor" class="form-select" onchange="toggleOther(this, 'other_cause_factor')">
                                    <option value="ฝึกทางการทหาร" <?php if($cause_val == 'ฝึกทางการทหาร') echo 'selected'; ?>>ฝึกทางการทหาร</option>
                                    <option value="ออกกำลังกายกลางวันในที่แจ้ง" <?php if($cause_val == 'ออกกำลังกายกลางวันในที่แจ้ง') echo 'selected'; ?>>ออกกำลังกายกลางวันในที่แจ้ง</option>
                                    <option value="ทำงานกลางแจ้ง" <?php if($cause_val == 'ทำงานกลางแจ้ง') echo 'selected'; ?>>ทำงานกลางแจ้ง</option>
                                    <option value="อื่นๆ" <?php if($is_other_cause) echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="other_cause_factor" id="other_cause_factor" class="form-control mt-2 <?php echo $is_other_cause ? '' : 'd-none'; ?>" value="<?php echo $is_other_cause ? $cause_val : ''; ?>" placeholder="โปรดระบุ">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">ปฐมพยาบาล?</label>
                                <select name="first_aid" class="form-select">
                                    <option value="Yes" <?php if($row['first_aid'] == 'Yes') echo 'selected'; ?>>ใช่</option>
                                    <option value="No" <?php if($row['first_aid'] == 'No') echo 'selected'; ?>>ไม่</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">เวลาถึง รพ.</label>
                                <input type="time" name="arrival_time" class="form-control" value="<?php echo $row['arrival_time']; ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">CoreTemp แรกรับ</label>
                                <input type="number" step="0.1" name="ct_initial" class="form-control" value="<?php echo $row['ct_initial']; ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">CoreTemp 30 นาที</label>
                                <input type="number" step="0.1" name="ct_30min" id="ct_30min" class="form-control" value="<?php echo $row['ct_30min']; ?>" oninput="checkTempControl()">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">วินิจฉัย (ER)</label>
                                <select name="FstDx_select" id="FstDx_select" class="form-select" onchange="toggleERDiagnosis(this)" required>
                                    <option value="">-- เลือก --</option>
                                    <?php 
                                    foreach ($std_diagnoses as $dx) {
                                        $sel = ($selected_dx == $dx) ? 'selected' : '';
                                        echo "<option value='$dx' $sel>$dx</option>";
                                    }
                                    ?>
                                    <option value="other" <?php if($is_other_dx) echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="FstDx_other" id="FstDx_other" class="form-control mt-2 <?php echo $is_other_dx ? '' : 'd-none'; ?>" value="<?php echo $other_dx_val; ?>" placeholder="ระบุวินิจฉัย...">
                            </div>
                        </div>

                        <div class="alert alert-secondary d-flex align-items-center mb-4">
                            <i class="fa-solid fa-lock me-3 fa-2x"></i>
                            <div class="w-100">
                                <strong>ส่วนจำหน่าย (Disposition) ถูกล็อก</strong><br>
                                <small>สถานะปัจจุบัน: 
                                    <span class="badge bg-dark"><?php echo $row['disposition']; ?></span>
                                    <?php 
                                        if($row['disposition']=='Admit') echo " -> " . $row['admit_type'];
                                        elseif($row['disposition']=='Refer') echo " -> " . $row['refer_hospital'];
                                        elseif($row['disposition']=='Dead') echo " (เสียชีวิต)";
                                    ?>
                                </small>
                            </div>
                        </div>

                        <input type="hidden" name="disposition" value="<?php echo $row['disposition']; ?>">
                        <input type="hidden" name="ct_control" id="ct_control" value="<?php echo $row['ct_control']; ?>">

                        <?php if($row['disposition'] == 'Admit'): ?>
                        <?php endif; ?>

                        <?php if($row['disposition'] == 'Refer'): ?>
                            <div class="mb-3">
                                <label class="form-label">โรงพยาบาลปลายทาง</label>
                                <input type="text" name="refer_hospital" class="form-control" value="<?php echo $row['refer_hospital']; ?>">
                            </div>
                        <?php endif; ?>

                        <?php if($row['disposition'] == 'Dead'): ?>
                            <div class="mb-3">
                                <label class="form-label text-danger">สาเหตุการเสียชีวิต</label>
                                <input type="text" name="death_cause" class="form-control" value="<?php echo $row['death_cause']; ?>">
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">ผลการรักษา / Note</label>
                            <textarea name="treatment_result" class="form-control" rows="2"><?php echo $row['treatment_result']; ?></textarea>
                        </div>

                        <div class="section-title mt-4">3. ปัจจัยเสี่ยง</div>
                        <div class="row bg-white border p-3 rounded mx-1" style="border-style: dashed !important; border-color: #0c784b !important;">
                            <?php 
                                $risks = [
                                    "ไม่มีปัจจัยเสี่ยง", "พักผ่อนไม่เพียงพอ", "มีประวัติใช้ยาเสพติด (ก่อนฝึก)",
                                    "มีประวัติใช้ยาเสพติด (ระหว่างฝึก)", "ดื่มแอลกอฮอล์", "BMI > 28",
                                    "BMI > 30 หรือ นน. > 100 กก.", "มีไข้ 36.8-37.2 °C", "มีไข้ > 37.2 °C",
                                    "ท้องเสีย/เป็นหวัด (ช่วงฝึก)", "มีโรคประจำตัวเสี่ยงต่อโรคร้อน", 
                                    "ทานยาที่ลดการระบายความร้อน", "บาดเจ็บ/ถูกทำร้ายร่างกาย",
                                    "บริจาคโลหิตภายใน 3 วัน", "นน.ลด 2-5 กก. ใน 1 สัปดาห์", 
                                    "นน.ลด > 5 กก. ใน 1 สัปดาห์", "ประวัติฮีทสโตรก (เคยนอน รพ.)",
                                    "เจ็บป่วยฝึกไม่ได้ (มีใบรับรองแพทย์)"
                                ];
                                foreach ($risks as $index => $risk) {
                                    $checked = in_array($risk, $risk_factors_array) ? "checked" : "";
                                    echo "<div class='col-md-6 mb-2'><div class='form-check'><input class='form-check-input' type='checkbox' name='risk_factors[]' value='$risk' id='risk$index' $checked><label class='form-check-label' for='risk$index'>".($index+1).". $risk</label></div></div>";
                                }
                            ?>
                        </div>

                        <div class="d-flex justify-content-center gap-3 mt-5">
                            <button type="submit" name="update_treatment" class="btn btn-primary px-5 py-2 shadow-sm" style="background-color: #0c784b; border:none;">
                                <i class="fa-solid fa-save me-2"></i> อัปเดตข้อมูล
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function calculateBMI() {
        let w = document.getElementById('weight').value;
        let h = document.getElementById('height').value / 100;
        if (w > 0 && h > 0) document.getElementById('bmi').value = (w / (h * h)).toFixed(2);
    }
    function toggleOther(select, targetId) {
        let target = document.getElementById(targetId);
        if (select.value === 'อื่นๆ') { target.classList.remove('d-none'); target.required = true; target.focus(); } 
        else { target.classList.add('d-none'); target.required = false; }
    }
    function checkTempControl() {
        let val = parseFloat(document.getElementById('ct_30min').value);
        let input = document.getElementById('ct_control');
        if (isNaN(val)) { input.value = ""; } 
        else if (val <= 36.5) { input.value = " เป็นไปตามมาตรฐาน"; } 
        else { input.value = " ไม่เป็นไปตามมาตรฐาน"; }
    }
    function toggleERDiagnosis(select) {
        var otherInput = document.getElementById('FstDx_other');
        if (select.value === 'other') { otherInput.classList.remove('d-none'); otherInput.required = true; } 
        else { otherInput.classList.add('d-none'); otherInput.required = false; }
    }
    // Init
    checkTempControl();
</script>
</body>
</html>