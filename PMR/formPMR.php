<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

$hn = isset($_GET['hn']) ? $_GET['hn'] : '';
$an = isset($_GET['an']) ? $_GET['an'] : '';

$patient = null;
$history = null;
$track_count = 0;

// ตรวจสอบค่า HN และ AN
if ($hn && $an) {
    // ดึงข้อมูลผู้ป่วย
    $sqlPt = "SELECT c.patient_name, c.age, c.department, c.congenital_disease, c.phone, c.person_type,
                     c.weight, c.height,
                     w.discharge_date, w.final_diagnosis, w.initial_diagnosis, w.first_visit_date, w.status,
                     er.cause_factor, er.risk_factors,
                     (SELECT doctor_name FROM ward_progress_notes wp WHERE wp.ward_detail_id = w.id ORDER BY wp.note_datetime DESC LIMIT 1) AS doctor_name,
                     (SELECT details FROM ward_progress_notes wp WHERE wp.ward_detail_id = w.id ORDER BY wp.note_datetime DESC LIMIT 1) AS last_note_details
              FROM cases c
              JOIN ward_details w ON c.id = w.case_id
              LEFT JOIN er_details er ON w.case_id = er.case_id
              WHERE c.hn = ? AND w.an = ?
              ORDER BY w.id DESC LIMIT 1"; 
              
    $stmt = $conn->prepare($sqlPt);
    $stmt->bind_param("ss", $hn, $an);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ดึงประวัติ PMR
    $sqlHist = "SELECT * FROM pmr_records WHERE hn = ? AND an = ? ORDER BY record_datetime DESC";
    $stmtH = $conn->prepare($sqlHist);
    $stmtH->bind_param("ss", $hn, $an);
    $stmtH->execute();
    $history = $stmtH->get_result();
    $track_count = $history->num_rows;
}

$is_discharged = ($patient && $patient['status'] === 'Discharged');

// คำนวณ BMI
$case_bmi = "-";
if (!empty($patient['weight']) && !empty($patient['height'])) {
    $h_m = $patient['height'] / 100;
    if ($h_m > 0) $case_bmi = number_format($patient['weight'] / ($h_m * $h_m), 2);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บันทึกเวชศาสตร์ฟื้นฟู (PMR)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --v-primary: #8b5cf6; /* สีม่วง PMR */
            --v-primary-hover: #7c3aed;
            --v-bg: #f5f3ff; 
            --v-light: #ede9fe;
            --v-text: #0f172a; 
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--v-bg); color: var(--v-text); }
        h1, h2, h3, h4, h5, .btn, .card-header-custom { font-family: 'Prompt', sans-serif; }
        
        .text-theme { color: var(--v-primary) !important; }
        .bg-theme { background-color: var(--v-primary) !important; }
        .bg-theme-light { background-color: var(--v-light) !important; }

        .btn-theme { background-color: var(--v-primary); color: white; border-radius: 8px; transition: all 0.2s; border: none;}
        .btn-theme:hover { background-color: var(--v-primary-hover); color: white; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(139, 92, 246, 0.2);}
        .btn-outline-theme { border: 1.5px solid var(--v-primary); color: var(--v-primary); border-radius: 8px; background: #fff; transition: all 0.2s;}
        .btn-outline-theme:hover { background-color: var(--v-light); color: var(--v-primary-hover); }

        .patient-info-box {
            background: #fff; border-left: 6px solid var(--v-primary); border-radius: 16px;
            padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px;
        }

        .card-custom { border: none; border-radius: 18px; box-shadow: 0 6px 20px rgba(0,0,0,0.04); background: #fff; overflow: hidden; }
        .card-header-custom { 
            background: linear-gradient(135deg, var(--v-primary) 0%, color-mix(in srgb, var(--v-primary) 80%, black) 100%);
            color: white; border-radius: 18px 18px 0 0 !important; padding: 16px 20px; font-weight: 600; border-bottom: none;
        }

        .timeline { border-left: 2px solid #ddd6fe; padding-left: 20px; position: relative; margin-left: 10px; }
        .timeline-item { position: relative; margin-bottom: 30px; }
        .timeline-marker { position: absolute; left: -26px; top: 0; width: 14px; height: 14px; border-radius: 50%; background: var(--v-primary); border: 2px solid #fff; box-shadow: 0 0 0 2px var(--v-primary); }
    </style>
</head>
<body>
    
<?php 
    $path_prefix = "../"; 
    if(file_exists('../header_rehab.php')) require_once '../header_rehab.php'; 
    else echo "<nav class='navbar navbar-dark bg-primary shadow-sm'><div class='container'><a class='navbar-brand'>PMR Dashboard</a></div></nav>";
?>

    <?php if(isset($_SESSION['statusMsg'])) { ?>
        <script>
            Swal.fire({
                icon: '<?php echo $_SESSION['statusType']; ?>',
                title: '<?php echo ($_SESSION['statusType']=='success') ? 'สำเร็จ' : 'แจ้งเตือน'; ?>',
                text: '<?php echo $_SESSION['statusMsg']; ?>',
                timer: 1500,
                showConfirmButton: false
            });
        </script>
    <?php unset($_SESSION['statusMsg']); unset($_SESSION['statusType']); } ?>

    <div class="container-fluid px-4 py-4" style="max-width: 1400px;">
        
        <?php if (!$patient): ?>
            <div class="alert alert-danger text-center p-5 shadow-sm rounded-4">
                <i class="fa-solid fa-circle-exclamation fa-3x mb-3"></i>
                <h4 class="font-prompt fw-bold">ไม่พบข้อมูลผู้ป่วย</h4>
                <p>กรุณาตรวจสอบ HN หรือ AN อีกครั้ง</p>
                <a href="indexPMR.php" class="btn btn-outline-danger mt-2 rounded-pill px-4">กลับหน้าหลัก</a>
            </div>
            <?php exit(); ?>
        <?php endif; ?>

        <?php if (!$is_discharged): ?>
            <div class="alert alert-warning text-center mb-4 border-warning shadow-sm rounded-3">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                <strong>คำเตือน:</strong> ผู้ป่วยรายนี้สถานะปัจจุบันคือ 
                <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($patient['status'] ?? '-'); ?></span> 
                (ยังไม่ใช่ Discharged)
            </div>
        <?php endif; ?>

        <!-- 1. Patient Header Info -->
        <div class="patient-info-box d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h3 class="fw-bold text-dark font-prompt mb-0"><i class="fa-solid fa-person-walking-with-cane text-theme me-2"></i><?php echo htmlspecialchars($patient['patient_name'] ?? '-'); ?></h3>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge bg-light text-dark border px-3 py-2 fs-6">HN: <?php echo htmlspecialchars($hn); ?></span>
                    <span class="badge bg-theme-light text-theme border px-3 py-2 fs-6" style="border-color: var(--v-primary) !important;">AN: <?php echo htmlspecialchars($an); ?></span>
                    <span class="text-muted small ms-2"><i class="fa-solid fa-calendar-check me-1"></i> วันที่จำหน่าย: <?php echo !empty($patient['discharge_date']) ? date('d/m/Y H:i', strtotime($patient['discharge_date'])) : '-'; ?></span>
                </div>
                <div class="row g-3 text-muted" style="font-size: 0.95rem;">
                    <div class="col-auto"><i class="fa-solid fa-child text-secondary me-1"></i> <strong>อายุ:</strong> <?php echo htmlspecialchars($patient['age'] ?? '-'); ?> ปี</div>
                    <div class="col-auto"><i class="fa-solid fa-phone text-secondary me-1"></i> <strong>โทร:</strong> <?php echo htmlspecialchars($patient['phone'] ?? '-'); ?></div>
                    <div class="col-auto"><i class="fa-solid fa-calculator text-secondary me-1"></i> <strong>BMI:</strong> <span class="text-danger fw-bold"><?php echo $case_bmi; ?></span></div>
                    <div class="col-auto">
                        <i class="fa-solid fa-stethoscope text-primary me-1"></i> <strong>วินิจฉัยสุดท้าย:</strong>
                        <span class="fw-bold text-danger">
                            <?php 
                                if (!empty($patient['final_diagnosis'])) {
                                    echo htmlspecialchars($patient['final_diagnosis']);
                                } elseif (!empty($patient['last_note_details'])) {
                                    echo htmlspecialchars($patient['last_note_details']);
                                } else {
                                    echo '-';
                                }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="text-md-end">
                <a href="<?php echo $path_prefix; ?>PMR/indexPMR.php" class="btn btn-outline-theme px-4 py-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> กลับรายการ
                </a>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- History Section -->
            <div class="col-lg-5 mb-4 order-lg-2">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold text-dark py-3 border-bottom d-flex justify-content-between align-items-center">
                        <div><i class="fa-solid fa-history me-2 text-theme"></i>ประวัติการฟื้นฟู (PMR)</div>
                        <span class="badge bg-light text-dark border shadow-sm">ทั้งหมด <?php echo $track_count; ?> รายการ</span>
                    </div>
                    <div class="card-body bg-theme-light" style="max-height: 800px; overflow-y: auto;">
                        <?php if ($history && $history->num_rows > 0): ?>
                            <div class="timeline mt-2">
                                <?php while($row = $history->fetch_assoc()): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="fw-bold text-theme">
                                        <?php echo date('d/m/Y', strtotime($row['record_datetime'])); ?>
                                        <small class="text-muted ms-2 fw-normal"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($row['record_datetime'])); ?> น.</small>
                                    </div>
                                    <div class="card mt-2 border-0 shadow-sm rounded-3">
                                        <div class="card-body p-3">
                                            <div class="mb-2">
                                                <span class="badge bg-theme-light text-theme border border-theme w-100 text-start px-3 py-2 fs-6" style="white-space: normal; line-height: 1.4;">
                                                    <i class="fa-solid fa-clipboard-check me-1"></i> <?php echo htmlspecialchars($row['pmr_status']); ?>
                                                </span>
                                            </div>
                                            
                                            <?php if($row['pmr_note']): ?>
                                                <div class="small text-secondary mt-2 bg-light p-2 rounded border border-dashed">
                                                    <strong class="text-dark d-block mb-1"><i class="fa-solid fa-note-sticky me-1 text-warning"></i> บันทึกเพิ่มเติม:</strong>
                                                    <?php echo nl2br(htmlspecialchars($row['pmr_note'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="mt-3 d-flex justify-content-between align-items-end">
                                                <small class="text-muted"><i class="fa-solid fa-user-pen me-1"></i><?php echo htmlspecialchars($row['recorder_name']); ?></small>
                                                
                                                <?php if($row['next_pmr_date']): ?>
                                                    <span class="badge bg-white border border-danger text-danger shadow-sm py-1 px-2"><i class="fa-regular fa-calendar-plus me-1"></i> นัดครั้งถัดไป: <?php echo date('d/m/Y', strtotime($row['next_pmr_date'])); ?></span>
                                                <?php endif; ?>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted bg-white rounded-3 border border-dashed shadow-sm">
                                <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
                                <p>ยังไม่มีบันทึกการประเมินเวชศาสตร์ฟื้นฟู</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Form Section -->
            <div class="col-lg-7 order-lg-1">
                <div class="card card-custom shadow border-0 h-100">
                    <div class="card-header-custom">
                        <i class="fa-solid fa-pen-to-square me-2"></i>บันทึกอาการ ครั้งที่ <?php echo $track_count + 1; ?>
                    </div>
                    <div class="card-body p-4">
                        <form action="save_pmr.php" method="POST">
                            <input type="hidden" name="hn" value="<?php echo htmlspecialchars($hn); ?>">
                            <input type="hidden" name="an" value="<?php echo htmlspecialchars($an); ?>">

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-list-check me-2"></i>ผลการฟื้นฟูผู้ป่วย</label>
                                <!-- 🔴 แก้ไข value ให้ตรงกับฐานข้อมูลและ JavaScript ตรงนี้ -->
                                <select name="pmr_status" id="pmr_status" class="form-select bg-light p-3" required onchange="toggleDateInput('pmr')" style="border: 2px solid var(--v-primary); font-weight: 600;">
                                    <option value="" selected disabled>-- เลือกผลการฟื้นฟู --</option>
                                    <option value="มาตามนัด อาการปกติ สิ้นสุดการฟื้นฟู" class="text-success">มาตามนัด อาการปกติ สิ้นสุดการฟื้นฟู</option>
                                    <option value="มาตามนัด นัดฟื้นฟูต่อเนื่อง" class="text-primary">มาตามนัด นัดฟื้นฟูต่อเนื่อง (ระบุวันที่)</option>
                                    <option value="มาไม่ตรงตามนัด อาการปกติ สิ้นสุดการฟื้นฟู" class="text-warning text-dark">มาไม่ตรงตามนัด อาการปกติ สิ้นสุดการฟื้นฟู</option>
                                    <option value="มาไม่ตรงตามนัด นัดฟื้นฟูต่อเนื่อง" class="text-danger">มาไม่ตรงตามนัด นัดฟื้นฟูต่อเนื่อง (ระบุวันที่)</option>
                                    <option value="ไม่มาตามนัด" class="text-danger">ไม่มาตามนัด</option>
                                </select>
                            </div>

                            <div class="mb-4 d-none p-3 bg-theme-light rounded-3 border" id="pmr_date_group" style="border-color: var(--v-primary) !important;">
                                <label class="form-label text-theme fw-bold"><i class="fa-regular fa-calendar-plus me-2"></i>ระบุวันที่นัดครั้งต่อไป <span class="text-danger">*</span></label>
                                <input type="date" name="next_pmr_date" id="next_pmr_date" class="form-control bg-white shadow-sm border-theme">
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-notes-medical me-2"></i>บันทึกเพิ่มเติม (Progress Note)</label>
                                <textarea name="pmr_note" class="form-control bg-light p-3" rows="4" placeholder="ระบุอาการ, แผนการฟื้นฟู หรือรายละเอียดเพิ่มเติม..."></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold border-bottom pb-2 w-100 mb-3 text-theme fs-5"><i class="fa-solid fa-signature me-2"></i>การลงชื่อและเวลา</label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ผู้บันทึก <span class="text-danger">*</span></label>
                                        <div class="input-group shadow-sm">
                                            <span class="input-group-text bg-light"><i class="fa-solid fa-user-pen text-muted"></i></span>
                                            <input type="text" name="recorder_name" class="form-control" required placeholder="กรอกชื่อแพทย์/พยาบาล">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">เวลาบันทึก <span class="text-danger">*</span></label>
                                        <input type="datetime-local" name="record_datetime" class="form-control shadow-sm" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" name="submit_pmr" class="btn btn-theme btn-lg w-100 shadow mt-2">
                                <i class="fa-solid fa-save me-2"></i> บันทึกผลการฟื้นฟู
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        function toggleDateInput(type) {
            var statusId = type + '_status';
            var dateGroupId = type + '_date_group';
            var dateInputId = 'next_' + type + '_date';

            var val = document.getElementById(statusId).value;
            var dateGroup = document.getElementById(dateGroupId);
            var dateInput = document.getElementById(dateInputId);

            // 🔴 ปรับ Logic ให้ค้นหาคำว่า "ต่อเนื่อง" อย่างเดียวก็เพียงพอ
            if (val.includes("ต่อเนื่อง")) {
                dateGroup.classList.remove('d-none');
                dateInput.required = true; 
            } else {
                dateGroup.classList.add('d-none');
                dateInput.required = false;
                dateInput.value = ''; 
            }
        }
    </script>
</body>
</html>