<?php
require_once '../database/db_conn.php';
session_start();

// ตรวจสอบ Login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}


if (isset($_SESSION['statusMsg'])) {
    echo "<div class='{$_SESSION['statusType']}'>{$_SESSION['statusMsg']}</div>";

    // ลบออกหลังแสดง
    unset($_SESSION['statusType']);
    unset($_SESSION['statusMsg']);
}

$hn = isset($_GET['hn']) ? base64_decode($_GET['hn']) : '';
$rowid = isset($_GET['rowid']) ? base64_decode($_GET['rowid']) : '';

// --- [ส่วนที่ 1] เตรียมตัวแปร Auto Fill (ค่าเริ่มต้นว่าง) ---
$auto_name = "";
$auto_cid = "";
$auto_age = "";
$auto_symptoms = "";
$auto_hn = "$hn";
$auto_department = "";
$auto_person_type = ""; 

// --- [ส่วนที่ 2] รับค่าจากกระดิ่งแจ้งเตือน (Method GET) ---
if (isset($_GET['autofill'])) {
    $auto_name = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : '';
    $auto_cid = isset($_GET['cid']) ? htmlspecialchars($_GET['cid']) : '';
    $auto_age = isset($_GET['age']) ? htmlspecialchars($_GET['age']) : '';
    $auto_symptoms = isset($_GET['symptoms']) ? htmlspecialchars($_GET['symptoms']) : '';
    $auto_department = isset($_GET['unit']) ? htmlspecialchars($_GET['unit']) : '';
    
    // แปลงรหัสประเภทบุคคล (Code -> Thai Value) ให้ตรงกับ value ใน <option>
    $type_code = isset($_GET['type']) ? $_GET['type'] : '';
    if ($type_code == 'MILITARY') $auto_person_type = 'ทหารกองประจำการ';
    elseif ($type_code == 'OFFICER') $auto_person_type = 'กำลังพล ทบ.'; // ปรับให้ตรงกับ option ในฟอร์ม
    elseif ($type_code == 'CIVILIAN') $auto_person_type = 'นอก ทบ.';
    else $auto_person_type = 'อื่นๆ';

    $auto_hn = isset($_GET['hn']) ? htmlspecialchars($_GET['hn']) : '';
}


?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกข้อมูล - รพ.อานันทมหิดล</title>

    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- css -->
    <link href="CSS/style.css" rel="stylesheet">


</head>
<body>

<?php $path_prefix = "../"; require_once '../header_gp.php';?>

<div class="form-page-wrap">

    <div class="form-hero">
        <div class="hero-badge"><i class="fa-solid fa-circle-dot fa-beat" style="color:#86efac;"></i> GP · รพ.อานันทมหิดล</div>
        <h2><i class="fa-solid fa-file-medical me-2"></i>บันทึกข้อมูลการรักษาพยาบาล</h2>
        <p>ระบบบริหารจัดการโรคลมร้อน</p>
        <i class="fa-solid fa-heart-pulse hero-icon"></i>
    </div>

    <div class="main-card">
        <div class="card-body">
            <form action="save_treatmentER.php" method="post" id="erTreatmentForm">
                <input type="hidden" name="row_id" value="<?php echo $rowid; ?>">
                <!-- ══════════════════════════════
                     SECTION 1: ค้นหาและระบุตัวตน
                ══════════════════════════════ -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">1</div>
                        <h6 class="section-title"><i class="fa-solid fa-magnifying-glass"></i> ค้นหาและระบุตัวตน</h6>
                    </div>
                    <div class="section-body">
                        <div class="hn-search-box">
                            <div class="hn-field">
                                <label class="form-label">ค้นหา HN (ดึงข้อมูล)</label>
                                <div class="d-flex gap-2">
                                    <input type="text" id="search_hn" class="form-control" placeholder="กรอกเลข HN เช่น 1111/68" value="<?php echo $auto_hn; ?>">
                                    <button type="button" class="btn btn-check-pmk" onclick="fetchPatientFromAPI()">
                                        <i class="fa-solid fa-cloud-arrow-down me-1"></i> ดึงข้อมูล
                                    </button>
                                </div>
                            </div>
                            <div class="cid-field">
                                <label class="form-label label-required">เลขประจำตัวประชาชน (13 หลัก)</label>
                                <input type="text" name="patient_id_card" class="form-control" maxlength="13" required placeholder="x-xxxx-xxxxx-xx-x" value="<?php echo $auto_cid; ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════
                     SECTION 2: ข้อมูลทั่วไปผู้ป่วย
                ══════════════════════════════ -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">2</div>
                        <h6 class="section-title"><i class="fa-solid fa-id-card"></i> ข้อมูลทั่วไปผู้ป่วย</h6>
                    </div>
                    <div class="section-body">
                        <div class="row">
                            <div class="col-md-4 mb-field">
                                <label class="form-label label-required">HN</label>
                                <input type="text" name="hn" class="form-control" required placeholder="ระบุ HN" value="<?php echo $auto_hn; ?>">
                            </div>
                            <div class="col-md-8 mb-field">
                                <label class="form-label label-required">ยศ ชื่อ-นามสกุล</label>
                                <input type="text" name="patient_name" class="form-control" required placeholder="ระบุชื่อ-นามสกุลเต็ม" value="<?php echo $auto_name; ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3 mb-field">
                                <label class="form-label label-required">เพศ</label>
                                <input type="text" name="sex" id="input_sex" class="form-control" placeholder="–">
                            </div>
                            <div class="col-md-3 mb-field">
                                <label class="form-label label-required">อายุ (ปี)</label>
                                <input type="number" name="age" class="form-control" required placeholder="–" value="<?php echo $auto_age; ?>">
                            </div>
                            <div class="col-md-6 mb-field">
                                <label class="form-label">เบอร์โทรศัพท์</label>
                                <div class="input-icon-wrap">
                                    <i class="fa-solid fa-phone input-prefix-icon"></i>
                                    <input type="tel" name="phone" class="form-control" placeholder="ถ้าไม่มีใส่ -">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-field">
                                <label class="form-label">น้ำหนัก (kg)</label>
                                <input type="number" step="0.1" name="weight" id="weight" class="form-control" placeholder="–" oninput="calculateBMI()">
                            </div>
                            <div class="col-md-4 mb-field">
                                <label class="form-label">ส่วนสูง (cm)</label>
                                <input type="number" step="0.1" name="height" id="height" class="form-control" placeholder="–" oninput="calculateBMI()">
                            </div>
                            <div class="col-md-4 mb-field">
                                <label class="form-label">BMI</label>
                                <input type="text" name="bmi" id="bmi" class="form-control" readonly placeholder="–">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-field">
                                <label class="form-label label-required">ประเภทกำลังพล</label>
                                <select name="person_type" id="person_type" class="form-select" onchange="toggleOther(this, 'other_person_type')" required>
                                    <option value="">— เลือกประเภท —</option>
                                    <option value="ทหารกองประจำการ" <?php if($auto_person_type=='ทหารกองประจำการ') echo 'selected'; ?>>ทหารกองประจำการ</option>
                                    <option value="กำลังพล ทบ." <?php if($auto_person_type=='กำลังพล ทบ.') echo 'selected'; ?>>กำลังพล ทบ.</option>
                                    <option value="นอก ทบ." <?php if($auto_person_type=='นอก ทบ.') echo 'selected'; ?>>นอก ทบ.</option>
                                    <option value="อื่นๆ" <?php if($auto_person_type=='อื่นๆ') echo 'selected'; ?>>อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="other_person_type" id="other_person_type" class="form-control mt-2 d-none" placeholder="โปรดระบุ">
                            </div>
                            <div class="col-md-6 mb-field">
                                <label class="form-label label-required">สังกัด/หน่วย</label>
                                <input type="text" name="department" class="form-control" required value="<?php echo $auto_department; ?>">
                            </div>
                        </div>

                        <div class="mb-field mt-4">
                            <label class="form-label">โรคประจำตัว</label>
                            <input type="text" name="congenital_disease" class="form-control" placeholder="ถ้าไม่มีให้ใส่ -">
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════
                     SECTION 3: 
                ══════════════════════════════ -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">3</div>
                        <h6 class="section-title"><i class="fa-solid fa-temperature-half"></i> บันทึกอาการ</h6>
                    </div>
                    <div class="section-body">
                        <div class="row">
                            <div class="col-md-6 mb-field">
                                <label class="form-label label-required">ปัจจัยการเจ็บป่วย</label>
                                <select name="cause_factor" id="cause_factor" class="form-select" onchange="toggleOther(this, 'other_cause_factor')" required>
                                    <option value="">— เลือกปัจจัย —</option>
                                    <option value="ฝึกทางการทหาร">ฝึกทางการทหาร</option>
                                    <option value="ออกกำลังกายกลางวันในที่แจ้ง">ออกกำลังกายกลางวันในที่แจ้ง</option>
                                    <option value="ทำงานกลางแจ้ง">ทำงานกลางแจ้ง</option>
                                    <option value="อื่นๆ">อื่นๆ (ระบุ)</option>
                                </select>
                                <input type="text" name="other_cause_factor" id="other_cause_factor" class="form-control mt-2 d-none" placeholder="โปรดระบุ">
                            </div>
                            <div class="col-md-3 mb-field">
                                <label class="form-label">ปฐมพยาบาลเบื้องต้น</label>
                                <select name="first_aid" class="form-select">
                                    <option value="">— เลือกคำตอบ —</option>
                                    <option value="Yes">ใช่</option>
                                    <option value="No">ไม่</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-field">
                                <label class="form-label">วัน/เวลาที่ผู้ป่วยมีอาการ</label>
                                <input type="datetime-local" name="arrival_time" id="arrival_time" class="form-control"
                                    step="1" onfocus="stopArrivalUpdate()" onchange="stopArrivalUpdate()">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-field">
                                <label class="form-label">วัน/เวลาที่ถึงโรงพยาบาล</label>
                                <input type="datetime-local" name="hospital_arrival_datetime" id="hospital_arrival_datetime" class="form-control"
                                    step="1" onfocus="stopArrivalUpdate()" onchange="stopArrivalUpdate()">
                            </div>
                            <div class="col-md-3 mb-field">
                                <label class="form-label">CoreTemp แรกรับ (°C)</label>
                                <input type="number" step="0.1" max="50" name="ct_initial" id="ct_initial" class="form-control" placeholder="0.0" oninput="limitCoreTemp(this)">
                            </div>
                            <div class="col-md-3 mb-field">
                                <label class="form-label">CoreTemp นาทีที่ 30 (°C)</label>
                                <input type="number" step="0.1" max="50" name="ct_30min" id="ct_30min" class="form-control" placeholder="0.0" oninput="limitCoreTemp(this); checkTempControl();">
                            </div>
                            <div class="col-md-3 mb-field">
                                <label class="form-label">อุณหภูมิตามเกณฑ์หรือไม่</label>
                                <input type="text" name="ct_control" id="ct_control" class="form-control" required readonly placeholder="— รอผล —">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-field">
                                <label class="form-label label-required">วินิจฉัยแรกรับ</label>
                                <select name="FstDx_select" id="FstDx_select" class="form-select" onchange="toggleERDiagnosis(this)" required>
                                    <option value="">— เลือกวินิจฉัย —</option>
                                    <option value="Prickly Heat">Prickly Heat</option>
                                    <option value="Heat Edema">Heat Edema</option>
                                    <option value="Heat Syncope">Heat Syncope</option>
                                    <option value="Heat Cramps">Heat Cramps</option>
                                    <option value="Heat Tetany">Heat Tetany</option>
                                    <option value="Rhabdomyolysis (CPK < 1,000)">Rhabdomyolysis (CPK < 1,000)</option>
                                    <option value="Rhabdomyolysis (CPK &gt; 1,000)">Rhabdomyolysis (CPK > 1,000)</option>
                                    <option value="Heat Exhaustion">Heat Exhaustion</option>
                                    <option value="Heat Stroke">Heat Stroke</option>
                                    <option value="other">อื่นๆ ที่ไม่ใช่ Heat (ระบุ)</option>
                                </select>
                                <input type="text" name="FstDx_other" id="FstDx_other" class="form-control mt-2 d-none" placeholder="ระบุวินิจฉัยอื่นๆ...">
                            </div>
                            <div class="col-md-8 mb-field">
                                <label class="form-label">หมายเหตุ</label>
                                <textarea name="treatment_result" class="form-control"><?php echo $auto_symptoms; ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════
                     SECTION 4: ปัจจัยเสี่ยง
                ══════════════════════════════ -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">4</div>
                        <h6 class="section-title"><i class="fa-solid fa-triangle-exclamation"></i> ปัจจัยเสี่ยง Heat Injury</h6>
                    </div>
                    <div class="section-body">
                        <div class="risk-grid">
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
                                foreach ($risks as $i => $risk):
                                    $idx_r = $i + 1;
                            ?>
                            <label class="risk-chip-label">
                                <input type="checkbox" name="risk_factors[]" value="<?php echo $risk; ?>" id="risk<?php echo $idx_r; ?>">
                                <span class="risk-num"><?php echo $idx_r; ?>.</span><?php echo $risk; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════
                     SECTION 5: ข้อมูลการส่งรายงาน
                ══════════════════════════════ -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-num">5</div>
                        <h6 class="section-title"><i class="fa-solid fa-clipboard-list"></i> ข้อมูลการส่งรายงานและการจำหน่าย</h6>
                    </div>
                    <div class="section-body">
                        <div class="row">
                            <div class="col-md-6 mb-field">
                                <label class="form-label label-required">ผู้ส่งข้อมูล</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user-pen"></i></span>
                                    <input type="text" name="reporter_name" class="form-control" placeholder="กรอกชื่อผู้บันทึก" required>
                                </div>
                            </div>
                            <div class="col-md-6 mb-field">
                                <label class="form-label">วันที่บันทึกข้อมูล</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-clock"></i></span>
                                    <input type="datetime-local" name="recorded_at" id="recorded_at" class="form-control"
                                        step="1" onfocus="stopRecordedUpdate()" onchange="stopRecordedUpdate()">
                                </div>
                            </div>
                        </div>

                        <div class="mb-field">
                            <label class="form-label label-required" style="margin-bottom:12px;">สถานะการจำหน่าย</label>
                            <div class="disposition-row">
                                <div class="disposition-option dis-discharge">
                                    <input type="radio" name="disposition" id="dis_discharge" value="Discharge" onchange="toggleDisposition()">
                                    <label for="dis_discharge">
                                        <i class="fa-solid fa-house-medical-circle-check"></i>
                                        Discharge
                                    </label>
                                </div>
                                <div class="disposition-option dis-admit">
                                    <input type="radio" name="disposition" id="dis_admit" value="Admit" onchange="toggleDisposition()">
                                    <label for="dis_admit">
                                        <i class="fa-solid fa-bed-pulse"></i>
                                        Admit
                                    </label>
                                </div>
                                <div class="disposition-option dis-refer">
                                    <input type="radio" name="disposition" id="dis_refer" value="Refer" onchange="toggleDisposition()">
                                    <label for="dis_refer">
                                        <i class="fa-solid fa-truck-medical"></i>
                                        Refer
                                    </label>
                                </div>
                                <div class="disposition-option dis-dead">
                                    <input type="radio" name="disposition" id="dis_dead" value="Dead" onchange="toggleDisposition()">
                                    <label for="dis_dead">
                                        <i class="fa-solid fa-book-skull"></i>
                                        Dead
                                    </label>
                                </div>
                            </div>
                            <!-- hidden original select for form compat -->
                            <select name="disposition" id="disposition" class="d-none" required>
                                <option value="">-- เลือกสถานะ --</option>
                                <option value="Discharge">Discharge</option>
                                <option value="Admit">Admit</option>
                                <option value="Refer">Refer</option>
                                <option value="Dead">Dead</option>
                            </select>
                        </div>

                        <!-- Admit Details -->
                        <div class="sub-expand-box d-none" id="admit_options_group">
                            <label class="form-label label-required" style="color:#0284c7;">ประเภท Admit</label>
                            <select name="admit_type" id="admit_type" class="form-select" onchange="toggleWardDetails()">
                                <option value="">— เลือกหอผู้ป่วย —</option>
                                <option value="Ward ICU">Ward ICU</option>
                                <option value="Ward RCU">Ward RCU</option>
                                <option value="Ward A4">Ward A4</option>
                                <option value="Ward B3">Ward B3</option>
                            </select>
                        </div>

                        <!-- Refer Details -->
                        <div class="sub-expand-box d-none" id="refer_details_group">
                            <label class="form-label label-required" style="color:#d97706;">ชื่อโรงพยาบาลที่ส่งต่อ</label>
                            <input type="text" name="refer_hospital" id="refer_hospital" class="form-control" placeholder="ระบุชื่อโรงพยาบาล">
                        </div>

                        <!-- Dead Details -->
                        <div class="sub-expand-box d-none" id="dead_details_group">
                            <label class="form-label text-danger">ระบุสาเหตุการเสียชีวิต</label>
                            <input type="text" name="death_cause" id="death_cause" class="form-control border-danger" placeholder="เช่น Heat Stroke with Multi-organ failure">
                        </div>

                        <!-- Visit Unit (แสดงเฉพาะตอนเลือก Discharge) -->
                        <div class="sub-expand-box d-none" id="visit_unit_group" style="background:#f0fdf4; border-color:#86efac !important;">
                            <label class="form-label fw-bold mb-2" style="color:#16a34a;"><i class="fa-solid fa-house-medical-circle-check me-1"></i> หน่วยงานที่จะให้เยี่ยมบ้าน <span class="text-danger">*</span></label>
                            <div class="d-flex flex-wrap gap-3 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input visit-unit-radio" type="radio" name="visit_unit" value="CQI" id="unit_cqi">
                                    <label class="form-check-label fw-bold" for="unit_cqi">CQI</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input visit-unit-radio" type="radio" name="visit_unit" value="PCU" id="unit_pcu">
                                    <label class="form-check-label fw-bold" for="unit_pcu">PCU</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input visit-unit-radio" type="radio" name="visit_unit" value="COC" id="unit_coc">
                                    <label class="form-check-label fw-bold" for="unit_coc">COC</label>
                                </div>
                            </div>
                            <label class="form-label fw-bold mb-2 mt-3 text-success"><i class="fa-solid fa-clock me-1"></i>วันเวลาที่จำหน่ายผู้ป่วย <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="er_discharge_date" id="er_discharge_date"
                                step="1" onfocus="stopErDischargeUpdate()" onchange="stopErDischargeUpdate()">
                        </div>
                    </div>
                </div>
                
                <!-- Form Footer -->
                <div class="form-footer">
                    <button type="reset" class="btn btn-secondary-theme">
                        <i class="fa-solid fa-rotate-left"></i> ล้างข้อมูล
                    </button>
                    <button type="submit" name="submit_treatment" class="btn btn-theme">
                        <i class="fa-solid fa-paper-plane"></i> บันทึกข้อมูล
                    </button>
                </div>

            </form>
        </div><!-- /card-body -->
    </div><!-- /main-card -->
</div><!-- /form-page-wrap -->

<script>
    const autoHN = "<?= htmlspecialchars($auto_hn) ?>";

    // --- ฟังก์ชันดึงข้อมูลจาก API ของ รพ. ---
    function fetchPatientFromAPI() {
        let hn = document.getElementById('search_hn').value.trim();
        
        if (hn === '') {
            Swal.fire({ icon: 'warning', title: 'แจ้งเตือน', text: 'กรุณากรอกรหัส HN ก่อนกดค้นหา' });
            return;
        }

        // แสดง Loading สวยๆ
        Swal.fire({
            title: 'กำลังดึงข้อมูลผู้ป่วย...',
            text: 'เชื่อมต่อฐานข้อมูล HIS',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        // 💡 แก้ไข 1: เปลี่ยนชื่อไฟล์ให้ตรงกับที่สร้างไว้ (fetch_patient_api.php)
        fetch('../fetch_patient_api.php?hn=' + encodeURIComponent(hn))
        .then(async response => {
            // ดักจับ Error กรณีหาไฟล์ไม่เจอ หรือ Server Error
            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status} - ไม่พบไฟล์ API Proxy หรือเซิร์ฟเวอร์มีปัญหา`);
            }
            
            const text = await response.text();
            try {
                // พยายามแปลงข้อความให้เป็น JSON
                return JSON.parse(text);
            } catch (e) {
                console.error("Raw response:", text);
                throw new Error("ไฟล์ PHP ไม่ได้ส่งข้อมูลกลับมาเป็น JSON (กรุณาเช็ค Error ใน Console)");
            }
        })
        .then(res => {
            if (res.success) { // 💡 เช็ค .success แทน .status แบบที่เขียนไว้ในไฟล์ PHP
                let pt = res.data;
                
                // กรอกข้อมูลลงในฟอร์ม
                document.querySelector('input[name="hn"]').value = pt.hn || pt.HN || '';
                document.querySelector('input[name="patient_id_card"]').value = pt.cid || pt.ID || '';
                document.querySelector('input[name="patient_name"]').value = pt.full_name_ready || pt.full_name || '';
                document.querySelector('input[name="age"]').value = pt.age || pt.AGE || '';
                document.querySelector('input[name="phone"]').value = pt.phone || pt.TEL || '';
                document.querySelector('input[name="department"]').value = pt.department || pt.affiliation || '';

                // แปลงตัวอักษรเพศให้ดูง่าย
                let sexText = pt.sex || pt.SEX || '';
                if(sexText === 'M') sexText = 'ชาย (Male)';
                if(sexText === 'F') sexText = 'หญิง (Female)';
                document.querySelector('input[name="sex"]').value = sexText || '';

                // จัดการประเภทกำลังพลให้ตรงกับ Dropdown
                let personTypeSelect = document.getElementById('person_type');
                let otherInput = document.getElementById('other_person_type');
                let apiType = pt.type_raw || pt.type || '';
                let apiAff = pt.department || pt.affiliation || '';
                
                let mappedType = "อื่นๆ";
                if (apiAff.includes("พลเรือน")) {
                    mappedType = "นอก ทบ.";
                } else if (apiType.includes("กองประจำการ")) {
                    mappedType = "ทหารกองประจำการ";
                } else if (apiType.includes("ทบ.")) {
                    mappedType = "กำลังพล ทบ.";
                }

                personTypeSelect.value = mappedType;
                if (mappedType === "อื่นๆ") {
                    otherInput.classList.remove('d-none');
                    otherInput.required = true;
                    let rawTxt = apiType;
                    if(apiAff) rawTxt += " (" + apiAff + ")";
                    otherInput.value = rawTxt;
                } else {
                    otherInput.classList.add('d-none');
                    otherInput.required = false;
                    otherInput.value = '';
                }

                // 💡 Auto-Select "Admit" and "Ward" if AN exists in HIS
                let apiAn = pt.an || pt.AN || '';
                if (apiAn && apiAn !== 'null/null' && apiAn.toLowerCase() !== 'null') {
                    let apiWard = pt.ward_his || pt.Ward || '';
                    let mappedWard = '';
                    let apiWardUpper = apiWard.toUpperCase();

                    // แปลงค่าตามเงื่อนไข (VIP4 = A4, B3 = B3, ICU = ICU, RCU = RCU)
                    if (apiWardUpper.includes('VIP4') || apiWardUpper.includes('A4')) {
                        mappedWard = 'Ward A4';
                    } else if (apiWardUpper.includes('B3')) {
                        mappedWard = 'Ward B3';
                    } else if (apiWardUpper.includes('ICU')) {
                        mappedWard = 'Ward ICU';
                    } else if (apiWardUpper.includes('RCU')) {
                        mappedWard = 'Ward RCU';
                    }

                    if (mappedWard !== '') {
                        // เลือก Radio "Admit"
                        let admitRadio = document.getElementById('dis_admit');
                        if (admitRadio) {
                            admitRadio.checked = true;
                            toggleDisposition(); // เปิดโชว์ dropdown แผนก
                            // เลือกแผนก
                            let admitTypeSelect = document.getElementById('admit_type');
                            if (admitTypeSelect) {
                                admitTypeSelect.value = mappedWard;
                                admitTypeSelect.classList.add('autofilled');
                                setTimeout(() => admitTypeSelect.classList.remove('autofilled'), 2000);
                            }
                        }
                    }
                }

                // 💡 จัดการ วันเวลาถึงโรงพยาบาล (VisitDate)
                let erHospitalInput = document.getElementById('hospital_arrival_datetime');
                let vDate = pt.visit_date || pt.VisitDate;
                if (erHospitalInput && vDate && vDate !== 'null') {
                    try {
                        // แปลงวันที่ให้อยู่ในฟอร์แมต YYYY-MM-DDTHH:mm
                        let d = new Date(vDate);
                        if (!isNaN(d.getTime())) {
                            let yyyy = d.getFullYear();
                            let mm = String(d.getMonth() + 1).padStart(2, '0');
                            let dd = String(d.getDate()).padStart(2, '0');
                            let hh = String(d.getHours()).padStart(2, '0');
                            let mins = String(d.getMinutes()).padStart(2, '0');

                            erHospitalInput.value = `${yyyy}-${mm}-${dd}T${hh}:${mins}`;
                            
                            // หยุดการทำงานของเวลา Auto เพื่อไม่ให้ทับค่าจาก API
                            isAutoArrival = false; 
                            
                            erHospitalInput.classList.add('autofilled');
                            setTimeout(() => erHospitalInput.classList.remove('autofilled'), 2000);
                        }
                    } catch(e) {
                        console.error('Date parse error', e);
                    }
                }

                // Highlight ช่องที่โดนกรอกอัตโนมัติ
                document.querySelectorAll('input[value]:not([value=""]), input:not([value=""])').forEach(el => {
                    if(el.value !== "" && el.id !== 'search_hn') {
                        el.classList.add('autofilled');
                        setTimeout(() => el.classList.remove('autofilled'), 2000);
                    }
                });

                // แจ้งเตือนความสำเร็จ
                Swal.fire({ 
                    icon: 'success', 
                    title: 'ดึงข้อมูลสำเร็จ', 
                    text: 'พบข้อมูลผู้ป่วย: ' + (pt.full_name_ready || pt.full_name),
                    timer: 1500,
                    showConfirmButton: false
                });

            } else {
                Swal.fire({ icon: 'error', title: 'ไม่พบข้อมูล', text: res.message });
                
                // เคลียร์ช่องถ้าหาไม่เจอ
                document.querySelector('input[name="patient_name"]').value = '';
                document.querySelector('input[name="patient_id_card"]').value = '';
                document.querySelector('input[name="age"]').value = '';
                document.querySelector('input[name="phone"]').value = '';
                document.querySelector('input[name="department"]').value = '';
                document.querySelector('input[name="sex"]').value = '';
            }
        })
        .catch(err => {
            console.error('API Error:', err);
            Swal.fire({ icon: 'error', title: 'ข้อผิดพลาดระบบ', text: err.message });
        });
        
    }

    // --- ตัวแปรควบคุมการวิ่งของเวลา (True=วิ่ง, False=หยุด) ---
    let isAutoArrival = true;   
    let isAutoRecorded = true;  
    let isAutoErDischarge = true; // 💡 ควบคุมการอัปเดตอัตโนมัติของ "เวลาที่จำหน่ายผู้ป่วย"

    function stopArrivalUpdate() { isAutoArrival = false; }
    function stopRecordedUpdate() { isAutoRecorded = false; }
    function stopErDischargeUpdate() { isAutoErDischarge = false; } // 💡 หยุดวิ่งเมื่อผู้ใช้แก้ไขเอง

    function updateDateTime() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        
        const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}:${seconds}`;
        
        if (isAutoArrival) {
            const hospitalInput = document.getElementById('hospital_arrival_datetime'); 
            if (hospitalInput) hospitalInput.value = currentDateTime;
        }
        if (isAutoRecorded) {
            const recordedInput = document.getElementById('recorded_at'); 
            if (recordedInput) recordedInput.value = currentDateTime;
        }
        // 💡 อัปเดต "เวลาที่จำหน่ายผู้ป่วย" ตามเวลาเครื่อง จนกว่าผู้ใช้จะแก้ไขเอง
        if (isAutoErDischarge) {
            const erDischargeInput = document.getElementById('er_discharge_date');
            if (erDischargeInput) erDischargeInput.value = currentDateTime;
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        updateDateTime(); 
        setInterval(updateDateTime, 1000); 
    });

    function calculateBMI() {
        let w = parseFloat(document.getElementById('weight').value);
        let h = parseFloat(document.getElementById('height').value) / 100;
        if (w > 0 && h > 0) {
            let bmi = w / (h * h);
            document.getElementById('bmi').value = bmi.toFixed(2);
        } else {
            document.getElementById('bmi').value = '';
        }
    }

    function limitCoreTemp(input) {
        if (input.value !== "") {
            let val = parseFloat(input.value);
            if (val > 50) input.value = 50;
        }
    }

    function toggleOther(select, targetId) {
        let target = document.getElementById(targetId);
        if (select.value === 'อื่นๆ') {
            target.classList.remove('d-none');
            target.required = true;
            target.focus();
        } else {
            target.classList.add('d-none');
            target.required = false;
            target.value = ''; 
        }
    }

    function checkTempControl() {
        let tempVal = document.getElementById('ct_30min').value;
        let controlInput = document.getElementById('ct_control');
        let temp = parseFloat(tempVal);

        controlInput.classList.remove('temp-result-ok', 'temp-result-bad');
        if (isNaN(temp)) {
            controlInput.value = "";
        } else if (temp <= 38) {
            controlInput.value = "✓ เป็นไปตามมาตรฐาน";
            controlInput.classList.add('temp-result-ok');
        } else {
            controlInput.value = "✗ ไม่เป็นไปตามมาตรฐาน";
            controlInput.classList.add('temp-result-bad');
        }
    }

    function toggleDisposition() {
        const radios = document.querySelectorAll('input[name="disposition"][type="radio"]');
        const hiddenSelect = document.getElementById('disposition');
        let val = '';
        radios.forEach(r => { if(r.checked) val = r.value; });
        if(hiddenSelect) hiddenSelect.value = val;

        const admitGroup     = document.getElementById('admit_options_group');
        const admitType      = document.getElementById('admit_type');
        const referGroup     = document.getElementById('refer_details_group');
        const referInput     = document.getElementById('refer_hospital');
        const deadGroup      = document.getElementById('dead_details_group');
        const deadInput      = document.getElementById('death_cause');
        const visitUnitGroup = document.getElementById('visit_unit_group');
        const visitUnitRadios = document.querySelectorAll('.visit-unit-radio');
        const erDischargeInput = document.getElementById('er_discharge_date');

        admitGroup.classList.add('d-none'); referGroup.classList.add('d-none'); 
        deadGroup.classList.add('d-none'); visitUnitGroup.classList.add('d-none');
        admitType.required = false; referInput.required = false; deadInput.required = false;

        // 💡 เริ่มต้น: ปิดการบังคับกรอกหน่วยเยี่ยมบ้าน/เวลาจำหน่าย ไว้ก่อนเสมอ
        visitUnitRadios.forEach(r => r.required = false);
        if (erDischargeInput) erDischargeInput.required = false;

        if (val === 'Admit') { admitGroup.classList.remove('d-none'); admitType.required = true; } 
        else if (val === 'Refer') { referGroup.classList.remove('d-none'); referInput.required = true; } 
        else if (val === 'Dead') { deadGroup.classList.remove('d-none'); deadInput.required = true; }
        else if (val === 'Discharge') { 
            visitUnitGroup.classList.remove('d-none');
            // 💡 เมื่อเลือก Discharge บังคับให้เลือกหน่วยที่ให้เยี่ยม และระบุเวลาที่จำหน่ายทุกครั้ง
            visitUnitRadios.forEach(r => r.required = true);
            if (erDischargeInput) {
                erDischargeInput.required = true;
                // ถ้ายังไม่เคยมีค่า ให้เติมเวลาปัจจุบันให้ทันที (ผู้ใช้ยังแก้ไขได้ภายหลัง)
                if (!erDischargeInput.value) updateDateTime();
            }
        }
    }

    function toggleWardDetails() {} // Hook ไว้เผื่อใช้

    function toggleERDiagnosis(select) {
        var otherInput = document.getElementById('FstDx_other');
        if (select.value === 'other') {
            otherInput.classList.remove('d-none'); otherInput.required = true; otherInput.focus();
        } else {
            otherInput.classList.add('d-none'); otherInput.required = false; otherInput.value = ''; 
        }
    }

    // 💡 กันเหนียวอีกชั้น: ตอนกดบันทึกจริง ถ้าเป็น Discharge ต้องเลือกหน่วยเยี่ยมบ้าน + มีเวลาจำหน่าย
    document.getElementById('erTreatmentForm').addEventListener('submit', function(e) {
        const dispositionVal = document.getElementById('disposition').value;
        if (dispositionVal === 'Discharge') {
            const visitUnitChecked = document.querySelector('input[name="visit_unit"]:checked');
            const erDischargeInput = document.getElementById('er_discharge_date');

            if (!visitUnitChecked) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'กรอกข้อมูลไม่ครบ', text: 'กรุณาเลือกหน่วยงานที่จะให้เยี่ยมบ้าน ก่อนบันทึกข้อมูล' });
                return false;
            }
            if (erDischargeInput && !erDischargeInput.value) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'กรอกข้อมูลไม่ครบ', text: 'กรุณาระบุเวลาที่จำหน่ายผู้ป่วย ก่อนบันทึกข้อมูล' });
                return false;
            }
        }
    });

    // 💡 แก้ไข 2: เปลี่ยนจาก Listener เป็น document.addEventListener
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('autofill')) {
            Swal.fire({
                icon: 'success', title: 'รับเคสสำเร็จ!',
                text: 'ระบบได้ดึงข้อมูลผู้ป่วยลงในฟอร์มเรียบร้อยแล้ว',
                timer: 1500, showConfirmButton: false
            });
            const inputs = document.querySelectorAll('input[value]:not([value=""])');
            inputs.forEach(input => {
                input.classList.add('autofilled');
                setTimeout(() => input.classList.remove('autofilled'), 2000);
            });
        }
    });

    // ดึงอัตโนมัติเมื่อคลิกจัดการผู้ป่วย
    window.addEventListener('DOMContentLoaded', function () {

        if (autoHN !== '') {
            document.getElementById('search_hn').value = autoHN;
            fetchPatientFromAPI();
        }
    });
</script>

<?php require_once '../footer.php'; ?>
</body>
</html>