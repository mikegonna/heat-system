<?php
    // 1. Config & Check Session
    session_start();

    if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
    require_once '../database/db_conn.php';
    date_default_timezone_set('Asia/Bangkok');

    $ward_id = isset($_GET['id']) ? $_GET['id'] : '';
    if (empty($ward_id)) { die("Invalid ID"); }

    // --- 2. ตรวจสอบ User และแผนก ---
    $current_user_id = $_SESSION['user_id'];
    $sqlUser = "SELECT name, department FROM users WHERE id = '$current_user_id'";
    $resUser = $conn->query($sqlUser);
    $userRow = $resUser->fetch_assoc();
    $current_user_name = $userRow['name']; 
    $current_user_dept = $userRow['department'];

    // เช็คว่าเป็น ICU หรือไม่
    $isICU = (stripos($current_user_name, 'ICU') !== false);

    // ---------------------------------------------------------
    // [กำหนด Theme สีให้สอดคล้องกับ Dashboard]
    // ---------------------------------------------------------
    if ($isICU) {
        $theme_main  = '#FF8C00'; // ส้ม ICU
        $theme_bg    = '#fff7ed';
        $theme_light = '#ffedd5';
    } else {
        $theme_main  = '#327c62'; // เขียวพาสเทล Ward
        $theme_bg    = '#f4fbf7';
        $theme_light = '#e8f6ef';
    }

    // --- 3. ดึงข้อมูลผู้ป่วย (Header) ---
    $sqlInfo = "SELECT w.*, c.patient_name, c.hn, c.age, c.congenital_disease, c.id as case_id,
                       EXISTS (SELECT 1 FROM er_details e WHERE e.case_id = c.id) as has_er_treatment
                FROM ward_details w
                JOIN cases c ON w.case_id = c.id
                WHERE w.id = '$ward_id'";
    $resInfo = $conn->query($sqlInfo);

    if (!$resInfo || $resInfo->num_rows == 0) { die("ไม่พบข้อมูลผู้ป่วย"); }
    $info = $resInfo->fetch_assoc();
    $show_an = !empty($info['an']) ? $info['an'] : '-';

    // --- 4. ส่วนจัดการค้นหา (Search) และแบ่งหน้า (Pagination) ---
    $search_date = isset($_GET['search_date']) ? $_GET['search_date'] : ''; 
    $page        = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit       = 10; 
    $offset      = ($page - 1) * $limit;

    $whereSQL = " WHERE ward_detail_id = '$ward_id' ";
    if (!empty($search_date)) {
        $whereSQL .= " AND DATE(note_datetime) = '$search_date' ";
    }

    $sqlCount = "SELECT COUNT(*) as total FROM ward_progress_notes $whereSQL";
    $resCount = $conn->query($sqlCount);
    $totalRows = $resCount->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);

    $sqlNotes = "SELECT * FROM ward_progress_notes 
                $whereSQL 
                ORDER BY note_datetime DESC 
                LIMIT $offset, $limit";
    $notes = $conn->query($sqlNotes);

    // ข้อมูลสำหรับ Dropdown Diagnosis
    $diagnosis_list = ["Heat Stroke", "Heat Exhaustion", "Rhabdomyolysis (CPK > 1,000)", "Rhabdomyolysis (CPK < 1,000)", "Heat Tetany", "Heat Cramps", "Heat Syncope", "Heat Edema", "Prickly Heat"];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บันทึกอาการ - <?php echo $info['patient_name']; ?></title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .text-theme { color: var(--t-main) !important; }
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

        /* Patient Info Box */
        .patient-info-box {
            background: #fff;
            border-left: 6px solid var(--t-main);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        /* Form Controls */
        .form-label { font-weight: 600; color: var(--text-dark); font-size: 0.9rem; }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 10px 14px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--t-main);
            box-shadow: 0 0 0 3px var(--t-shadow);
        }

        /* Themed input (border สีตามธีม user) */
        .form-control-theme {
            border: 2px solid var(--t-main) !important;
        }
        .form-control-theme:focus {
            border-color: var(--t-main);
            box-shadow: 0 0 0 3px var(--t-shadow);
        }

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
        .btn-outline-theme {
            border: 1.5px solid var(--t-main);
            color: var(--t-main);
            border-radius: 10px;
            background: #fff;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-outline-theme:hover {
            background: var(--t-light);
            color: var(--t-main);
        }

        /* Timeline History Cards */
        .note-card {
            border-radius: 14px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            transition: transform 0.2s;
            background: #fff;
            border-left: 5px solid var(--t-main);
        }
        .note-card:hover { transform: translateX(4px); box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .note-card.reheat { border-left-color: #ef4444; } /* แดงสำหรับ Re-Heat */

        /* Pagination */
        .page-link { color: var(--t-main); border-radius: 8px; margin: 0 2px; border: 1px solid #e2e8f0; }
        .page-item.active .page-link { background-color: var(--t-main); border-color: var(--t-main); color: white; box-shadow: 0 3px 8px var(--t-shadow); }
        .page-item.disabled .page-link { color: #94a3b8; }
    </style>
</head>
<body>

<?php 
    $path_prefix = "../"; 
    if ($isICU) { require_once '../header_icu.php'; } else { require_once '../header_ward.php'; }
?>

<div class="container py-4 mb-5">
    
    <?php if(isset($_SESSION['statusMsg'])): ?>
        <script>
            Swal.fire({
                icon: '<?php echo $_SESSION['statusType']; ?>',
                title: '<?php echo ($_SESSION['statusType']=='success')?'สำเร็จ':'ผิดพลาด'; ?>',
                text: '<?php echo $_SESSION['statusMsg']; ?>',
                timer: 1500,
                showConfirmButton: false
            });
        </script>
        <?php unset($_SESSION['statusMsg']); unset($_SESSION['statusType']); ?>
    <?php endif; ?>

    <!-- 1. Patient Header Info -->
    <div class="patient-info-box d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2">
                <h3 class="fw-bold text-dark font-prompt mb-0"><i class="fa-solid fa-bed-pulse text-theme me-2"></i><?php echo $info['patient_name']; ?></h3>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <span class="badge bg-light text-dark border px-3 py-2 fs-6">HN: <?php echo $info['hn']; ?></span>
                <span class="badge bg-theme-light text-theme border px-3 py-2 fs-6" style="border-color: var(--t-main) !important;">AN: <?php echo $show_an; ?></span>
                <span class="text-muted small ms-2"><i class="fa-solid fa-calendar-check me-1"></i> รับเข้า: <?php echo date('d/m/Y H:i', strtotime($info['admit_date'])); ?></span>
            </div>
            <div class="row g-3 text-muted" style="font-size: 0.95rem;">
                <div class="col-auto"><i class="fa-solid fa-child text-secondary me-1"></i> <strong>อายุ:</strong> <?php echo $info['age']; ?> ปี</div>
                <div class="col-auto"><i class="fa-solid fa-notes-medical text-danger me-1"></i> <strong>โรคประจำตัว:</strong> <?php echo $info['congenital_disease']; ?></div>
                <div class="col-auto">
                    <i class="fa-solid fa-stethoscope text-primary me-1"></i> <strong>วินิจฉัยแรกรับ:</strong>
                    <?php 
                        if ($isICU && !empty($info['icu_diagnosis'])) {
                            echo "<span class='text-danger fw-bold'>{$info['icu_diagnosis']} (ICU)</span>";
                        } elseif (!empty($info['initial_diagnosis'])) {
                            echo "<span class='text-theme fw-bold'>{$info['initial_diagnosis']}</span>";
                        } else { echo "-"; }
                    ?>
                </div>
            </div>
        </div>
        <div class="text-md-end">
            <a href="<?php echo $isICU ? 'indexICU.php' : 'indexWard.php'; ?>" class="btn btn-outline-theme">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าระบบ
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- 2. Form Section -->
        <div class="col-lg-5">
            <div class="card card-custom h-100">
                <div class="card-header-custom">
                    <i class="fa-solid fa-file-medical me-2"></i>แบบบันทึกอาการ / จำหน่าย (Progress Note)
                </div>
                <div class="card-body p-4">
                    <form action="save_ward.php" method="post">
                        <input type="hidden" name="ward_detail_id" value="<?php echo $ward_id; ?>">
                        <input type="hidden" name="case_id" value="<?php echo $info['case_id']; ?>">

                        <div class="mb-4">
                            <label class="form-label">Heat <span class="text-danger">*</span></label>
                            <select name="heat_status" class="form-select bg-light" required>
                                <option value="Non Re-Heat" selected>Non Re-Heat</option>
                                <option value="Re-Heat" class="text-danger fw-bold">Re-Heat</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">บันทึกอาการ/ผลการรักษา <span class="text-danger">*</span></label>
                            <textarea name="details" class="form-control bg-light" rows="5" required placeholder="ระบุอาการ, สัญญาณชีพ, การรักษา..."></textarea>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-theme fs-6">
                                สถานะจำหน่าย / ส่งต่อ <span class="text-danger">*</span><br />
                                <span class="text-danger small">(หากมีการย้าย ward กรุณาเลือก "ย้ายไป ward อื่น")</span>
                            </label>
                            <select name="treatment_result" id="treatment_result" class="form-select" style="border: 2px solid var(--t-main);" required onchange="toggleTreatmentOptions()">
                                <option value="" selected>-- กรุณาเลือกสถานะการรักษา --</option>
                                <option value="Continue">คงการรักษา</option>
                                <option value="Discharge">D/C</option>
                                <option value="Admit">ย้ายไป Ward อื่น</option>
                                <option value="Refer">Refer</option>
                                <option value="Dead">Dead</option>
                            </select>
                        </div>

                        <!-- Dynamic Groups (Hidden by default) -->
                        <div class="mb-3 d-none p-3 bg-theme-light rounded border" style="border-color: var(--t-main) !important;" id="final_dx_group">
                            <label class="form-label text-theme mb-2"><i class="fa-solid fa-stethoscope me-1"></i> วินิจฉัยสุดท้าย (Final Diagnosis) <span class="text-danger">*</span></label>
                            <select name="final_diagnosis_select" id="final_diagnosis_select" class="form-select" onchange="toggleDiagnosis(this, 'final_diagnosis_other')">
                                <option value="">-- เลือกวินิจฉัย --</option>
                                <?php foreach($diagnosis_list as $dx) { echo "<option value='$dx'>$dx</option>"; } ?>
                                <option value="other">อื่นๆ ที่ไม่ใช่ Heat (ระบุ)</option>
                            </select>
                            <input type="text" name="final_diagnosis_other" id="final_diagnosis_other" class="form-control mt-2 d-none" placeholder="ระบุวินิจฉัยอื่นๆ...">
                        </div>

                        <!-- หน่วยงานที่จะให้เยี่ยม (แสดงเฉพาะตอนเลือก D/C) -->
                        <div class="mb-3 d-none p-3 rounded border bg-theme-light" style="border-color: var(--t-main) !important;" id="visit_unit_group">
                            <label class="form-label fw-bold mb-2 text-theme"><i class="fa-solid fa-house-medical-circle-check me-1"></i> หน่วยงานที่จะให้เยี่ยมบ้าน <span class="text-danger">*</span></label>
                            <div class="d-flex flex-wrap gap-3 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="visit_unit" value="CQI" id="unit_cqi">
                                    <label class="form-check-label fw-bold" for="unit_cqi">CQI</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="visit_unit" value="PCU" id="unit_pcu">
                                    <label class="form-check-label fw-bold" for="unit_pcu">PCU</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="visit_unit" value="COC" id="unit_coc">
                                    <label class="form-check-label fw-bold" for="unit_coc">COC</label>
                                </div>
                            </div>
                            <!-- เลือกวันและเวลากรณีที่บันทึกย้อนหลัง -->
                            <label class="form-label fw-bold mb-2 text-theme"><i class="fa-solid fa-clock me-1"></i> วันเวลาที่จำหน่าย <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control form-control-theme" name="discharge_date" value="<?php echo date('Y-m-d\TH:i'); ?>" />
                        </div>

                        <div class="mb-3 d-none p-3 bg-light rounded border" id="admit_group">
                            <label class="form-label text-primary"><i class="fa-solid fa-bed me-1"></i> เลือกแผนกปลายทาง <span class="text-danger">*</span></label>
                            <select name="admit_type" id="admit_type" class="form-select border-primary">
                                <option value="">-- เลือก --</option>
                                <?php
                                $wards = ['Ward ICU', 'Ward A4', 'Ward B3'];
                                foreach ($wards as $w) {
                                    if (stripos($w, $current_user_name) === false) { echo "<option value=\"$w\">$w</option>"; }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-3 d-none p-3 bg-light rounded border" id="refer_group">
                            <label class="form-label text-warning text-dark"><i class="fa-solid fa-ambulance me-1"></i> โรงพยาบาลปลายทาง <span class="text-danger">*</span></label>
                            <input type="text" name="refer_hospital" id="refer_hospital" class="form-control border-warning" placeholder="ระบุชื่อ รพ.">
                        </div>

                        <div class="mb-3 d-none p-3 bg-light rounded border" id="dead_group">
                            <label class="form-label text-danger"><i class="fa-solid fa-book-skull me-1"></i> สาเหตุการเสียชีวิต <span class="text-danger">*</span></label>
                            <input type="text" name="death_cause" id="death_cause" class="form-control border-danger" placeholder="ระบุสาเหตุ">
                        </div>

                        <div class="mb-4">
                            <label class="form-label">ผู้บันทึก <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-user-pen"></i></span>
                                <input type="text" name="doctor_name" class="form-control bg-light" required placeholder="กรอกชื่อผู้บันทึก">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-theme w-100 mt-2 fs-5">
                            <i class="fa-solid fa-save me-2"></i> บันทึกข้อมูล
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 3. History Section -->
        <div class="col-lg-7">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
                <h5 class="font-prompt fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left me-2 text-theme"></i>ประวัติการบันทึกอาการ (Progress Notes)</h5>
                <span class="badge bg-white text-dark border px-3 py-2 fs-6 shadow-sm">ทั้งหมด <?php echo $totalRows; ?> รายการ</span>
            </div>

            <div class="card card-custom mb-3 bg-white">
                <div class="card-body p-3">
                    <form action="" method="GET" class="row g-2 align-items-center justify-content-end">
                        <input type="hidden" name="id" value="<?php echo $ward_id; ?>">
                        <div class="col-auto">
                            <label class="col-form-label small text-muted fw-bold">กรองตามวันที่:</label>
                        </div>
                        <div class="col-auto">
                            <input type="date" name="search_date" class="form-control" value="<?php echo $search_date; ?>">
                        </div>
                        <div class="col-auto d-flex gap-1">
                            <button type="submit" class="btn btn-secondary px-3"><i class="fa-solid fa-search"></i></button>
                            <?php if(!empty($search_date)): ?>
                                <a href="treatmentWard.php?id=<?php echo $ward_id; ?>" class="btn btn-light border text-danger px-3"><i class="fa-solid fa-xmark"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="history-list">
                <?php if ($notes->num_rows > 0): ?>
                    <?php while($note = $notes->fetch_assoc()): ?>
                        <?php $isReheat = ($note['heat_status'] == 'Re-Heat'); ?>
                        <div class="card note-card mb-3 <?php echo $isReheat ? 'reheat' : ''; ?>">
                            <div class="card-body p-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2 border-bottom pb-3">
                                    <div>
                                        <div class="font-prompt fw-bold fs-5 <?php echo $isReheat ? 'text-danger' : 'text-theme'; ?>">
                                            <i class="fa-regular fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($note['note_datetime'])); ?>
                                        </div>
                                        <?php if(!empty($note['Admit_type'])): ?>
                                            <span class="badge bg-light text-secondary border mt-1"><i class="fa-solid fa-bed me-1"></i> <?php echo $note['Admit_type']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-light text-dark border px-3 py-2 shadow-sm"><i class="fa-solid fa-user-pen me-1 text-muted"></i> <?php echo $note['doctor_name']; ?></span>
                                    </div>
                                </div>
                                
                                <div class="row mb-3 g-2">
                                    <div class="col-auto">
                                        <span class="badge <?php echo $isReheat ? 'bg-danger' : 'bg-success'; ?> px-3 py-2 fs-6 shadow-sm">
                                            <i class="fa-solid <?php echo $isReheat ? 'fa-fire' : 'fa-check-circle'; ?> me-1"></i> Heat: <?php echo isset($note['heat_status']) ? $note['heat_status'] : '-'; ?>
                                        </span>
                                    </div>
                                    <div class="col-auto">
                                        <span class="badge bg-light text-dark border px-3 py-2 fs-6 shadow-sm">
                                            <i class="fa-solid fa-clipboard-check me-1 text-primary"></i> ผล: <?php echo $note['treatment_result']; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="p-3 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.95rem; line-height: 1.6; color: #334155;">
                                    <?php echo nl2br(htmlspecialchars($note['details'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>

                    <!-- Pagination Navigation -->
                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                                <a class="page-link" href="?id=<?php echo $ward_id; ?>&page=<?php echo $page-1; ?>&search_date=<?php echo $search_date; ?>">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="page-item <?php if($page == $p) echo 'active'; ?>">
                                <a class="page-link" href="?id=<?php echo $ward_id; ?>&page=<?php echo $p; ?>&search_date=<?php echo $search_date; ?>">
                                    <?php echo $p; ?>
                                </a>
                            </li>
                            <?php endfor; ?>

                            <li class="page-item <?php if($page >= $totalPages) echo 'disabled'; ?>">
                                <a class="page-link" href="?id=<?php echo $ward_id; ?>&page=<?php echo $page+1; ?>&search_date=<?php echo $search_date; ?>">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="card card-custom bg-transparent border-0 shadow-none text-center py-5">
                        <div class="card-body">
                            <i class="fa-regular fa-folder-open fa-3x mb-3 text-muted opacity-50"></i>
                            <h5 class="font-prompt text-muted">ยังไม่มีประวัติการบันทึกอาการ</h5>
                            <?php if(!empty($search_date)) echo "<p class='text-muted small'>สำหรับวันที่ ".date('d/m/Y', strtotime($search_date))."</p>"; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script>
    const hasErTreatment = <?php echo json_encode((bool)$info['has_er_treatment']); ?>;

    function toggleDiagnosis(selectObj, otherInputId) {
        var otherInput = document.getElementById(otherInputId);
        if (selectObj.value === 'other') {
            otherInput.classList.remove('d-none'); otherInput.required = true; otherInput.focus();
        } else {
            otherInput.classList.add('d-none'); otherInput.required = false; otherInput.value = ''; 
        }
    }

    function toggleTreatmentOptions() {
        const treatmentResult = document.getElementById('treatment_result');
        let result = treatmentResult.value;
        if (result === 'Discharge' && !hasErTreatment) {
            treatmentResult.value = '';
            result = '';
            Swal.fire({
                icon: 'warning',
                title: 'ไม่สามารถ D/C ได้',
                text: 'ไม่พบข้อมูลการรักษาจาก ER กรุณาติดต่อ ER ให้ส่งเคสมาก่อนจำหน่ายผู้ป่วย'
            });
        }

        const groups = ['final_dx_group','admit_group','refer_group','dead_group','visit_unit_group'];
        const inputs = ['final_diagnosis_select','admit_type','refer_hospital','death_cause'];

        // Reset
        groups.forEach(id => document.getElementById(id).classList.add('d-none'));
        inputs.forEach(id => document.getElementById(id).required = false);

        if (result === 'Discharge' || result === 'Refer' || result === 'Dead') {
            document.getElementById('final_dx_group').classList.remove('d-none');
            document.getElementById('final_diagnosis_select').required = true;
        }

        if (result === 'Discharge') {
            document.getElementById('visit_unit_group').classList.remove('d-none');
        }
        
        if (result === 'Refer') {
            document.getElementById('refer_group').classList.remove('d-none');
            document.getElementById('refer_hospital').required = true;
        } 
        else if (result === 'Dead') {
            document.getElementById('dead_group').classList.remove('d-none');
            document.getElementById('death_cause').required = true;
        }
        else if (result === 'Admit') {
            document.getElementById('admit_group').classList.remove('d-none');
            document.getElementById('admit_type').required = true;
        }
    }
</script>
</body>
</html>