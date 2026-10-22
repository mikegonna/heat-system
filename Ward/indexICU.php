<?php
date_default_timezone_set('Asia/Bangkok');

// เปิดแสดง Error
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }

$current_user_name = isset($_SESSION['name']) ? strtolower($_SESSION['name']) : '';

// ถ้าไม่ใช่ ICU (หรือ Admin) เผลอเข้ามาหน้านี้ ให้ดีดกลับไป indexWard.php
if ($current_user_name !== 'icu' && $_SESSION['role'] !== 'admin') {
    header("Location: indexWard.php");
    exit();
}

require_once '../database/db_conn.php';
require_once '../database/fiscal_year.php';

$fiscal_date_range = getFiscalDateRange($_GET);
$start_date = $fiscal_date_range['start_date'];
$end_date = $fiscal_date_range['end_date'];

$user_id = $_SESSION['user_id'];
$sqlUser = "SELECT * FROM users WHERE id = '$user_id'";
$queryUser = $conn->query($sqlUser);
$userData = $queryUser->fetch_assoc();
$current_user_dept = $userData['department'] ?? '-';
$current_user_role = $userData['role'];

// --- ฟังก์ชันแบ่งหน้า (Pagination Helper) ---
$limit = 10;
$page_wait = isset($_GET['page_wait']) ? max(1, (int)$_GET['page_wait']) : 1;
$page_active = isset($_GET['page_active']) ? max(1, (int)$_GET['page_active']) : 1;
$offset_wait = ($page_wait - 1) * $limit;
$offset_active = ($page_active - 1) * $limit;

function getPageLink($key, $val) {
    $params = $_GET;
    $params[$key] = $val;
    return '?' . http_build_query($params);
}

// ฟังก์ชันสำหรับตรวจสอบและคืนค่า Class สีตามความรุนแรง
function getHeatSeverityClass($diagnosis) {
    if (empty($diagnosis)) return '';
    $severeKeywords = ['Heat Rhabdomyolysis', 'Heat Exhaustion', 'Heat Exertional Rh', 'Heat Stroke'];
    foreach ($severeKeywords as $keyword) { if (stripos($diagnosis, $keyword) !== false) return 'row-severe'; }
    $moderateKeywords = ['Heat Rash', 'Heat Edema', 'Heat Cramp', 'Heat Syncope', 'Heat Tetany'];
    foreach ($moderateKeywords as $keyword) { if (stripos($diagnosis, $keyword) !== false) return 'row-moderate'; }
    return 'row-mild';
}

// --- คำนวณจำนวนเคสรอรับเข้า ICU (Notifications) ---
$filterConditionWait = " AND (e.admit_type = 'ICU' OR e.admit_type LIKE '%ICU%') ";
$filterConditionActive = " AND w.admit_type LIKE '%ICU%' ";
$filterConditionWait .= " AND DATE(e.recorded_at) BETWEEN '$start_date' AND '$end_date' ";
$filterConditionActive .= " AND DATE(w.admit_date) BETWEEN '$start_date' AND '$end_date' ";

// ดึงข้อมูลรอรับเข้า (รวมจาก ER และ รับย้าย) เพื่อมาทำ Pagination แบบ Array รวม
$all_waitlist = [];

// 1. ดึงจาก ER
$sqlER = "SELECT c.id as case_id, c.hn, c.patient_name, c.age, c.department, 
            e.FstDx, e.recorded_at, e.admit_type, e.id as er_id,
            e.ct_initial, e.ct_30min, e.first_aid, e.arrival_time, e.hospital_arrival_datetime
        FROM er_details e
        JOIN cases c ON e.case_id = c.id
        WHERE e.disposition = 'Admit' 
        $filterConditionWait 
        AND e.FstDx NOT LIKE 'รับย้ายจาก%' 
        AND NOT EXISTS (
            SELECT 1 FROM ward_details w WHERE w.case_id = c.id 
        )";
$resER = $conn->query($sqlER);
if ($resER && $resER->num_rows > 0) {
    while($r = $resER->fetch_assoc()) {
        $r['source'] = 'er';
        $r['sort_time'] = $r['recorded_at'];
        $all_waitlist[] = $r;
    }
}

// 2. ดึงจาก รับย้าย (Transfer)
$sqlTrans = "SELECT w.id as ward_id, c.id as case_id, c.hn, c.patient_name, c.age, c.department,
            w.admit_date as Admit_date, w.admit_type, w.discharge_detail as reason, w.an
            FROM ward_details w
            JOIN cases c ON w.case_id = c.id
            WHERE w.status = 'AdmitWait'
            AND NOT EXISTS (
                SELECT 1 FROM ward_details w2 WHERE w2.case_id = w.case_id AND w2.id > w.id
            )
            $filterConditionActive";
$resTrans = $conn->query($sqlTrans);
if ($resTrans && $resTrans->num_rows > 0) {
    while($r = $resTrans->fetch_assoc()) {
        // หาประวัติวินิจฉัยเดิม
        $actual_dx = ''; $prev_ward_name = 'Ward ทั่วไป';
        $sqlPrev = "SELECT initial_diagnosis, final_diagnosis, admit_type FROM ward_details WHERE case_id = '{$r['case_id']}' AND id < '{$r['ward_id']}' ORDER BY id DESC LIMIT 1";
        $resPrev = $conn->query($sqlPrev);
        if ($resPrev && $resPrev->num_rows > 0) {
            $prev = $resPrev->fetch_assoc();
            $prev_ward_name = $prev['admit_type'];
            $actual_dx = !empty($prev['final_diagnosis']) ? $prev['final_diagnosis'] : $prev['initial_diagnosis'];
        }
        if (empty($actual_dx)) {
            $sqlER2 = "SELECT FstDx FROM er_details WHERE case_id = '{$r['case_id']}' ORDER BY id DESC LIMIT 1";
            $resER2 = $conn->query($sqlER2);
            if ($resER2 && $resER2->num_rows > 0) { $actual_dx = $resER2->fetch_assoc()['FstDx']; }
        }

        // หาค่าจาก ER ประกอบ
        $er_ct_init = '-'; $er_ct_30 = '-';
        $sqlER_info = "SELECT ct_initial, ct_30min FROM er_details WHERE case_id = '{$r['case_id']}' ORDER BY id DESC LIMIT 1";
        $resER_info = $conn->query($sqlER_info);
        if ($resER_info && $resER_info->num_rows > 0) {
            $er_info = $resER_info->fetch_assoc();
            $er_ct_init = !empty($er_info['ct_initial']) ? $er_info['ct_initial'] : '-';
            $er_ct_30 = !empty($er_info['ct_30min']) ? $er_info['ct_30min'] : '-';
        }

        $r['source'] = 'transfer';
        $r['sort_time'] = $r['Admit_date'];
        $r['actual_dx'] = $actual_dx;
        $r['prev_ward_name'] = $prev_ward_name;
        $r['er_ct_init'] = $er_ct_init;
        $r['er_ct_30'] = $er_ct_30;
        $all_waitlist[] = $r;
    }
}

// รวม ยอดรวม และ ตัดแบ่งหน้า (Waitlist)
$countTotalWait = count($all_waitlist);
usort($all_waitlist, function($a, $b) { return strtotime($b['sort_time']) - strtotime($a['sort_time']); });
$paginated_waitlist = array_slice($all_waitlist, $offset_wait, $limit);

// นับยอด Active ICU สำหรับ Pagination
$sqlActiveCount = "SELECT COUNT(*) as c FROM ward_details w WHERE w.status = 'Active' $filterConditionActive";
$total_active = $conn->query($sqlActiveCount)->fetch_assoc()['c'];

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบหอผู้ป่วยวิกฤต (ICU)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { 
            --icu-primary: #ea580c; /* ปรับเป็นสีส้มไหม้ที่ดูพรีเมียมขึ้น */
            --icu-primary-hover: #c2410c; 
            --icu-bg: #fff7ed; 
            --icu-light: #ffedd5; 
            --icu-text: #1e293b; 
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--icu-bg); color: var(--icu-text); }
        h1, h2, h3, h4, h5, .btn, .nav-link, .modal-title { font-family: 'Prompt', sans-serif; }
        
        .page-header { 
            background: white; padding: 20px 30px; border-radius: 15px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-bottom: 25px; 
            border-left: 6px solid var(--icu-primary); 
            display: flex; justify-content: space-between; align-items: center; 
        }
        
        .nav-tabs { border-bottom: 2px solid #e2e8f0; flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
        .nav-tabs::-webkit-scrollbar { display: none; }
        .nav-tabs .nav-link { border: none; color: #64748b; font-weight: 500; padding: 12px 25px; border-radius: 12px 12px 0 0; transition: all 0.3s ease; white-space: nowrap; margin-right: 5px;}
        .nav-tabs .nav-link:hover { color: var(--icu-primary); background-color: var(--icu-light); }
        .nav-tabs .nav-link.active { color: #fff !important; background-color: var(--icu-primary) !important; box-shadow: 0 -4px 12px rgba(234, 88, 12, 0.2); }
        
        .card-icu { border: none; border-radius: 0 15px 15px 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.05); overflow: hidden; }
        
        /* สไตล์ตารางพื้นฐาน (Desktop) */
        .table-icu thead th { background-color: var(--icu-primary); color: white; font-weight: 500; border: none; padding: 15px; white-space: nowrap; font-size: 0.9rem;}
        .table-icu tbody td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem;}
        .table-icu tbody tr:hover { background-color: var(--icu-light); }
        
        /* ปุ่มจัดการ (SaaS Style) */
        .btn-action-custom {
            border-radius: 10px; padding: 6px 14px; font-size: 0.85rem; font-weight: 600;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            transition: all 0.2s ease; border: none; white-space: nowrap;
        }
        .btn-action-custom:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        
        .btn-receive { background: linear-gradient(135deg, var(--icu-primary), var(--icu-primary-hover)); color: white; }
        .btn-receive:hover { color: white; box-shadow: 0 4px 12px rgba(234, 88, 12, 0.3); }
        
        .btn-soft-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .btn-soft-success:hover { background: #166534; color: white; }
        
        .btn-soft-info { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
        .btn-soft-info:hover { background: #0369a1; color: white; }
        
        .btn-soft-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
        .btn-soft-warning:hover { background: #ca8a04; color: white; }

        .btn-icu-outline { border: 1.5px solid var(--icu-primary); color: var(--icu-primary); background: white; border-radius: 10px; padding: 8px 18px; font-weight: 600; transition: 0.2s; white-space: nowrap; }
        .btn-icu-outline:hover { background: var(--icu-light); color: var(--icu-primary-hover); }

        .row-severe { background-color: #fef2f2 !important; border-left: 4px solid #ef4444; }
        .row-moderate { background-color: #fffbeb !important; border-left: 4px solid #f59e0b; }
        .row-mild { background-color: #fff7ed !important; border-left: 4px solid var(--icu-primary); }

        .detail-box { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
        
        .form-control:focus, .form-select:focus { border-color: var(--icu-primary); box-shadow: 0 0 0 0.25rem rgba(234, 88, 12, 0.25); }

        /* Pagination Styles */
        .page-link { color: var(--icu-primary); border-radius: 8px; margin: 0 2px; font-weight: 500;}
        .page-item.active .page-link { background-color: var(--icu-primary); border-color: var(--icu-primary); color: white; }

        /* ==========================================
           🌟 MOBILE RESPONSIVE CSS (แปลง Table เป็น Card)
           ========================================== */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: flex-start; padding: 15px; }
            .page-header-actions { width: 100%; display: flex; justify-content: space-between; margin-top: 15px; flex-wrap: wrap; gap: 10px; }
            .page-header-actions .btn { flex-grow: 1; text-align: center; }
            
            .card-icu { border-radius: 10px; }
            .table-responsive { border: none; }
            
            /* แปลง <table> เป็น Block / Card */
            .table-icu, .table-icu tbody, .table-icu tr, .table-icu td { display: block; width: 100%; }
            .table-icu thead { display: none; } /* ซ่อนหัวตาราง */
            
            .table-icu tr {
                margin-bottom: 15px; background-color: #fff;
                border: 1px solid #e2e8f0; border-radius: 12px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.04); padding: 5px;
            }
            
            .table-icu td {
                text-align: left !important; padding: 12px 15px !important;
                border-bottom: 1px dashed #f1f5f9; position: relative;
            }
            .table-icu td:last-child { border-bottom: none; }
            
            /* สร้าง Label จำลองสำหรับมือถือจาก data-label */
            .table-icu td::before {
                content: attr(data-label); display: block; font-size: 0.75rem;
                font-weight: 700; color: #94a3b8; margin-bottom: 5px;
                text-transform: uppercase; letter-spacing: 0.5px;
            }

            .table-icu td.text-center { text-align: left !important; }
            .btn-action-custom { width: 100%; padding: 10px; font-size: 0.95rem; justify-content: center;}
            .action-buttons-grid { display: flex; flex-direction: column; gap: 8px; width: 100%; }
        }
    </style>
    
    <script>
        function formatForDatetimeLocal(dtStr) {
            if (!dtStr || dtStr === '' || dtStr === '0000-00-00 00:00:00') return '';
            return dtStr.replace(' ', 'T').substring(0, 16);
        }

        window.openReceiveModal = function(button) {
            var caseId = button.getAttribute('data-case-id');
            var hn = button.getAttribute('data-hn');
            var name = button.getAttribute('data-name');
            var admitType = button.getAttribute('data-admit-type');
            var pendingId = button.getAttribute('data-pending-id') || '';
            var an = button.getAttribute('data-an') || '';
            var diagnosis = button.getAttribute('data-diagnosis') || ''; 
            
            var arrTime = button.getAttribute('data-arr-time') || '';
            var hospTime = button.getAttribute('data-hosp-time') || '';
            
            // ❌ เอาค่า ct1 และ ct30 จาก ER ออก ไม่ต้องดึงมาใส่ฟอร์ม
            // ปล่อยว่างให้ ICU กรอกเองตาม Requirement
            
            var modalEl = document.getElementById('receiveModal');
            if (!modalEl) { alert('Error: ไม่พบ Modal'); return; }

            modalEl.querySelector('#modalCaseId').value = caseId;
            modalEl.querySelector('#modalPendingId').value = pendingId;
            modalEl.querySelector('#modalHN').textContent = hn;
            modalEl.querySelector('#modalPatientName').textContent = name;

            var form = modalEl.querySelector('form');
            if(form) form.reset(); // เคลียร์ฟอร์มทุกครั้ง (ทำให้ช่องอุณหภูมิว่างเปล่า)

            var anInput = modalEl.querySelector('input[name="an"]');
            if(anInput) anInput.value = an;
            
            var admitInput = modalEl.querySelector('#modalAdmitType');
            if(admitInput) admitInput.value = admitType;

            var admitDateInput = modalEl.querySelector('input[name="admit_date"]');
            if(admitDateInput) {
                var now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                admitDateInput.value = now.toISOString().slice(0,16);
            }

            var arrInput = modalEl.querySelector('input[name="icu_time_symptoms"]');
            if(arrInput && arrTime !== '-') arrInput.value = formatForDatetimeLocal(arrTime);

            var hospInput = modalEl.querySelector('input[name="icu_time_hospital"]');
            if(hospInput && hospTime !== '-') hospInput.value = formatForDatetimeLocal(hospTime);

            var dxSelect = modalEl.querySelector('select[name="icu_diagnosis_select"]');
            var dxOther = modalEl.querySelector('#modal_icu_diagnosis_other');
            
            if (dxSelect) {
                var isMatched = false;
                for (var i = 0; i < dxSelect.options.length; i++) {
                    if (dxSelect.options[i].value.trim() === diagnosis.trim() && diagnosis.trim() !== '') {
                        isMatched = true;
                        break;
                    }
                }

                if (isMatched) {
                    dxSelect.value = diagnosis;
                    if(dxOther) { dxOther.classList.add('d-none'); dxOther.value = ''; }
                } else if (diagnosis !== '' && diagnosis !== '-') {
                    dxSelect.value = 'other';
                    if(dxOther) { dxOther.classList.remove('d-none'); dxOther.value = diagnosis; }
                } else {
                    dxSelect.value = '';
                    if(dxOther) { dxOther.classList.add('d-none'); dxOther.value = ''; }
                }
            }

            try { var myModal = new bootstrap.Modal(modalEl); myModal.show(); } catch (e) { console.error(e); }
        };

        window.toggleModalICUDiagnosis = function(select) {
            var otherInput = document.getElementById('modal_icu_diagnosis_other');
            if(select.value === 'other') { otherInput.classList.remove('d-none'); otherInput.required = true; otherInput.focus(); } 
            else { otherInput.classList.add('d-none'); otherInput.required = false; otherInput.value = ''; }
        };

        window.fetchRealAN = function() {
            var hnElement = document.getElementById('modalHN');
            if (!hnElement) return;
            var hn = hnElement.textContent.trim();

            if (!hn || hn === '' || hn === '...') {
                Swal.fire({ icon: 'warning', title: 'แจ้งเตือน', text: 'ไม่พบรหัส HN กรุณาลองใหม่อีกครั้ง' });
                return;
            }

            var btn = document.getElementById('btnFetchAN');
            var originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>...';
            btn.disabled = true;

            fetch('../fetch_patient_api.php?hn=' + encodeURIComponent(hn))
                .then(response => response.json())
                .then(data => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;

                    if (data.success && data.data && data.data.an) {
                        let pt = data.data;
                        document.getElementById('modalAN').value = pt.an;
                        Swal.fire({ icon: 'success', title: 'ดึงข้อมูลสำเร็จ', text: 'พบข้อมูลการ Admit จากระบบ HIS', timer: 2000, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'ไม่พบประวัติ Admit', text: data.message || 'ไม่พบข้อมูล AN/AdmitDate สำหรับ HN นี้' });
                    }
                })
                .catch(error => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    Swal.fire({ icon: 'error', title: 'ข้อผิดพลาด', text: 'ไม่สามารถเชื่อมต่อฐานข้อมูล HIS ได้' });
                });
        };

            //ฟังก์ชั่นคำนวณเวลาจาก er to icu
            function calculateDiff() {
                let er = document.getElementById('icu_time_hospital').value;
                let icu = document.getElementById('modalAdmitDate').value;

                if (er && icu) {
                    let erTime = new Date(er);
                    let icuTime = new Date(icu);

                    let diffMs = icuTime - erTime;
                    let diffMin = Math.floor(diffMs / (1000 * 60)); // แปลงเป็นนาที

                    if (diffMin >= 0) {
                        document.querySelector('[name="icu_time_er_to_icu"]').value = diffMin;
                    } else {
                        document.querySelector('[name="icu_time_er_to_icu"]').value = '';
                    }
                }
            }
            // trigger ตอนเลือกเวลา
            document.addEventListener('DOMContentLoaded', function () {
            
            document.getElementById('icu_time_hospital').addEventListener('input', calculateDiff);
            document.getElementById('modalAdmitDate').addEventListener('input', calculateDiff);
            calculateDiff();
        });
        
    </script>
</head>
<body> 
<?php 
    $path_prefix = "../"; 
    require_once '../header_icu.php'; 
?>

    <!-- MODAL รับเข้า ICU -->
    <div class="modal fade" id="receiveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form action="save_receive_ward.php" method="POST">
                    <div class="modal-header" style="background: linear-gradient(135deg, var(--icu-primary), var(--icu-primary-hover)); color: white; border-radius: 10px 10px 0 0; padding: 20px 25px;">
                        <h5 class="modal-title fw-bold"><i class="fa-solid fa-heart-pulse me-2"></i> รับเข้าหอผู้ป่วยวิกฤต (ICU)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        
                        <div class="alert border d-flex align-items-center mb-4 shadow-sm" style="background-color: var(--icu-bg); border-color: var(--icu-light); border-radius: 12px;">
                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width:50px; height:50px;">
                                <i class="fa-solid fa-user-injured fa-xl" style="color: var(--icu-primary);"></i>
                            </div>
                            <div>
                                <h5 class="mb-1 fw-bold" style="color: var(--icu-text);" id="modalPatientName">...</h5>
                                <span class="badge bg-primary text-white shadow-sm">HN: <span id="modalHN">...</span></span>
                            </div>
                        </div>

                        <input type="hidden" name="case_id" id="modalCaseId">
                        <input type="hidden" name="pending_ward_id" id="modalPendingId">
                        <input type="hidden" name="is_icu" value="1"> 

                        <div class="row g-3 mb-4">
                            <div class="col-md-6 col-12">
                                <label class="form-label fw-bold text-secondary">เลข AN <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" name="an" id="modalAN" class="form-control border-primary" required placeholder="ระบุเลข AN">
                                    <button type="button" id="btnFetchAN" class="btn text-white fw-bold" style="background-color:var(--icu-primary);" onclick="fetchRealAN()"><i class="fa-solid fa-cloud-arrow-down me-1"></i> ดึง HIS</button>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label fw-bold text-secondary">วันที่รับเข้ารักษาICU <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="admit_date" id="modalAdmitDate" class="form-control border-primary" required>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label fw-bold text-secondary">แผนกที่เข้าพัก</label>
                                <input type="text" name="admit_type" id="modalAdmitType" class="form-control bg-light text-muted" readonly>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label fw-bold text-secondary">แพทย์เจ้าของไข้</label>
                                <input type="text" name="doctor_name" class="form-control" placeholder="ระบุชื่อแพทย์">
                            </div>
                        </div>

                        <!-- กล่องให้พยาบาล ICU กรอกเองล้วนๆ ไม่ดึงจาก ER -->
                        <div class="p-3 mb-4 rounded-3 border" style="background-color: #f8fafc; border-color: #cbd5e1 !important;">
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
                            <label class="form-label fw-bold text-danger">วินิจฉัยแรกรับเข้า ICU <span class="text-danger">*</span></label>
                            <select name="icu_diagnosis_select" class="form-select border-danger" onchange="toggleModalICUDiagnosis(this)" required>
                                <option value="" selected disabled>-- เลือกวินิจฉัย --</option>
                                <option value="Prickly Heat">Prickly Heat</option>
                                <option value="Heat Edema">Heat Edema</option>
                                <option value="Heat Syncope">Heat Syncope</option>
                                <option value="Heat Cramps">Heat Cramps</option>
                                <option value="Heat Tetany">Heat Tetany</option>
                                <option value="Rhabdomyolysis (CPK &lt; 1,000)">Rhabdomyolysis (CPK &lt; 1,000)</option>
                                <option value="Rhabdomyolysis (CPK &gt; 1,000)">Rhabdomyolysis (CPK &gt; 1,000)</option>
                                <option value="Heat Exhaustion">Heat Exhaustion</option>
                                <option value="Heat Stroke">Heat Stroke</option>
                                <option value="other">อื่นๆ (ระบุ)</option>
                            </select>
                            <input type="text" name="icu_diagnosis_other" id="modal_icu_diagnosis_other" class="form-control border-danger mt-2 d-none" placeholder="ระบุวินิจฉัยอื่นๆ...">
                        </div>

                    </div>
                    <div class="modal-footer bg-light border-top-0" style="border-radius: 0 0 10px 10px; padding: 15px 25px;">
                        <button type="button" class="btn btn-light border px-4 rounded-pill fw-bold text-muted" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" name="submit_receive" class="btn btn-receive px-5 rounded-pill fw-bold"><i class="fa-solid fa-save me-1"></i> ยืนยันการรับเข้า</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MAIN PAGE CONTENT -->
    <div class="container py-4 mb-5">
        <?php if(isset($_SESSION['statusMsg'])) { ?>
            <script>
                Swal.fire({
                    icon: '<?php echo $_SESSION['statusType']; ?>',
                    title: '<?php echo ($_SESSION['statusType']=='success') ? 'สำเร็จ' : 'แจ้งเตือน'; ?>',
                    text: '<?php echo $_SESSION['statusMsg']; ?>',
                    timer: 2000,
                    showConfirmButton: false
                });
            </script>
        <?php unset($_SESSION['statusMsg']); unset($_SESSION['statusType']); } ?>

        <div class="page-header">
            <div class="d-flex align-items-center">
                <div class="bg-light bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-heart-pulse fa-2x" style="color: var(--icu-primary);"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-1 text-dark">ระบบจัดการหอผู้ป่วยวิกฤต (ICU)</h4>
                    <span class="text-muted small"><i class="fa-solid fa-user-doctor me-1"></i> แผนกเข้าสู่ระบบ: <?php echo $current_user_dept; ?></span>
                </div>
            </div>
            <div class="page-header-actions">
                <a href="formWard.php" class="btn btn-icu-outline shadow-sm rounded-pill px-4"><i class="fa-solid fa-user-plus me-1"></i> รับใหม่ (Manual)</a>
            </div>
        </div>

        <form method="GET" class="card border-0 shadow-sm mb-3 p-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted fw-bold">ตั้งแต่วันที่</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted fw-bold">ถึงวันที่</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-icu-outline w-100">แสดงปีงบประมาณ พ.ศ. <?php echo $fiscal_date_range['year_label']; ?></button>
                </div>
            </div>
        </form>

        <ul class="nav nav-tabs border-0" id="icuTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active d-flex align-items-center" id="combined-tab" data-bs-toggle="tab" data-bs-target="#combined" type="button">
                    <i class="fa-solid fa-file-import me-2"></i>รอรับเข้า
                    <?php if($countTotalWait > 0): ?>
                        <span class="badge bg-warning rounded-pill ms-2 shadow-sm" style="font-size: 0.75rem;"><?php echo $countTotalWait; ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link d-flex align-items-center" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button">
                    <i class="fa-solid fa-bed-pulse me-2"></i>ผู้ป่วยใน ICU
                    <?php if($total_active > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-2 shadow-sm" style="font-size: 0.75rem;"><?php echo $total_active; ?></span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>

        <div class="card card-icu">
            <div class="card-body p-0">
                <div class="tab-content" id="icuTabContent">
                    
                    <!-- TAB: รอรับเข้า ICU -->
                    <div class="tab-pane fade show active" id="combined" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-icu mb-0">
                                <thead>
                                    <tr>
                                        <th width="5%" class="text-center">ลำดับ</th>
                                        <th width="12%">วันที่ส่ง</th>
                                        <th width="20%">HN / ชื่อผู้ป่วย</th>
                                        <th width="10%">ต้นทาง</th>
                                        <th width="35%">รายละเอียด</th>
                                        <th width="18%" class="text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                    if (!empty($paginated_waitlist)) {
                                        foreach ($paginated_waitlist as $idx => $row) {
                                            $no = $offset_wait + $idx + 1; // ลำดับเคส

                                            $safe_name = htmlspecialchars($row['patient_name'], ENT_QUOTES);
                                            $safe_hn = htmlspecialchars($row['hn'], ENT_QUOTES);
                                            $safe_case_id = $row['case_id']; 
                                            $safe_admit_type = htmlspecialchars($row['admit_type'], ENT_QUOTES);
                                            
                                            // Check Source (ER or Transfer)
                                            if ($row['source'] == 'er') {
                                                $show_diagnosis = $row['FstDx'];
                                                $safe_diagnosis = htmlspecialchars($row['FstDx'], ENT_QUOTES); 
                                                $rowClass = getHeatSeverityClass($show_diagnosis);
                                                $safe_ward_id = ""; 
                                                $safe_an = ""; 
                                                
                                                $safe_arr_time = htmlspecialchars($row['arrival_time'] ?? '', ENT_QUOTES);
                                                $safe_hosp_time = htmlspecialchars($row['hospital_arrival_datetime'] ?? '', ENT_QUOTES);

                                                $ct_init = !empty($row['ct_initial']) ? $row['ct_initial'] : '-';
                                                $ct_30 = !empty($row['ct_30min']) ? $row['ct_30min'] : '-';
                                                $fa = !empty($row['first_aid']) ? htmlspecialchars($row['first_aid']) : '-';

                                                $source_badge = "<span class='badge bg-danger text-white border shadow-sm px-3 py-2'><i class='fa-solid fa-truck-medical me-1'></i> ส่งจาก ER</span>";
                                                
                                                $detail_html = "
                                                    <div class='detail-box'>
                                                        <div class='fw-bold text-danger mb-2' style='font-size:0.95rem;'><i class='fa-solid fa-stethoscope me-1'></i> วินิจฉัย: {$show_diagnosis}</div>
                                                        <div class='row g-2'>
                                                            <div class='col-12'>
                                                                <span class='badge bg-warning text-dark me-2'><i class='fa-solid fa-temperature-half me-1'></i> Temp (จาก ER)</span> 
                                                                <span class='small'>แรกรับ: <strong class='text-dark'>{$ct_init} °C</strong> | 30 นาที: <strong class='text-dark'>{$ct_30} °C</strong></span>
                                                            </div>
                                                            <div class='col-12'>
                                                                <span class='badge bg-primary text-white me-2'><i class='fa-solid fa-kit-medical me-1'></i> ปฐมพยาบาล</span> 
                                                                <span class='small text-dark'>{$fa}</span>
                                                            </div>
                                                        </div>
                                                    </div>";
                                            } else {
                                                // Transfer
                                                $safe_ward_id = $row['ward_id'];
                                                $safe_an = htmlspecialchars($row['an'], ENT_QUOTES);
                                                $show_diagnosis = !empty($row['actual_dx']) ? $row['actual_dx'] : '-';
                                                $safe_diagnosis = htmlspecialchars($row['actual_dx'], ENT_QUOTES); 
                                                $show_reason = !empty($row['reason']) ? $row['reason'] : 'อาการแย่ลง / ประเมินซ้ำ';
                                                $rowClass = getHeatSeverityClass($show_diagnosis);
                                                
                                                $safe_arr_time = ''; $safe_hosp_time = '';
                                                
                                                $source_badge = "<span class='badge bg-warning text-dark border shadow-sm px-3 py-2'><i class='fa-solid fa-right-left me-1'></i> รับย้าย</span>";
                                                
                                                $detail_html = "
                                                    <div class='detail-box'>
                                                        <div class='fw-bold text-danger mb-1' style='font-size:0.9rem;'><i class='fa-solid fa-stethoscope me-1'></i> วินิจฉัย ({$row['prev_ward_name']}): <span class='text-dark'>{$show_diagnosis}</span></div>
                                                        <div class='text-danger small fw-bold mb-2 pb-2 border-bottom border-light'><i class='fa-solid fa-arrow-right-to-bracket me-1'></i> เหตุผล: <span class='text-dark'>{$show_reason}</span></div>
                                                        <div class='row g-2'>
                                                            <div class='col-12'>
                                                                <span class='badge bg-warning text-dark me-2'><i class='fa-solid fa-temperature-half me-1'></i> ER Temp เดิม</span> 
                                                                <span class='small'>แรกรับ: <strong class='text-dark'>{$row['er_ct_init']} °C</strong> | 30 นาที: <strong class='text-dark'>{$row['er_ct_30']} °C</strong></span>
                                                            </div>
                                                        </div>
                                                    </div>";
                                            }

                                            echo "<tr class='$rowClass'>
                                                <td class='text-center text-muted fw-bold' data-label='ลำดับ'>{$no}</td>
                                                <td data-label='วันที่ส่ง / เวลา'>
                                                    <div class='fw-bold text-dark'>" . date('d/m/Y', strtotime($row['sort_time'])) . "</div>
                                                    <div class='text-muted small mt-1'><i class='fa-regular fa-clock me-1'></i>" . date('H:i', strtotime($row['sort_time'])) . " น.</div>
                                                </td>
                                                <td data-label='ข้อมูลผู้ป่วย'>
                                                    <div class='fw-bold text-primary mb-1'><span class='badge bg-primary bg-opacity-10 text-light border border-primary border-opacity-25'>{$row['hn']}</span></div>
                                                    <div class='fw-bold text-dark fs-6'>{$row['patient_name']}</div>
                                                    <div class='small text-muted mt-1'><i class='fa-solid fa-sitemap me-1 opacity-75'></i>{$row['department']}</div>
                                                </td>
                                                <td data-label='ต้นทาง'>
                                                    {$source_badge}
                                                </td>
                                                <td data-label='รายละเอียดและอาการ'>
                                                    {$detail_html}
                                                </td>
                                                <td class='text-center' data-label='จัดการ'>
                                                    <button type='button' class='btn btn-action-custom btn-receive w-100 shadow-sm' 
                                                            onclick='openReceiveModal(this); calculateDiff();'
                                                            data-case-id='{$safe_case_id}' 
                                                            data-hn='{$safe_hn}'
                                                            data-name='{$safe_name}' 
                                                            data-admit-type='{$safe_admit_type}'
                                                            data-pending-id='{$safe_ward_id}'
                                                            data-diagnosis='{$safe_diagnosis}' 
                                                            data-an='{$safe_an}'
                                                            data-arr-time='{$safe_arr_time}'
                                                            data-hosp-time='{$safe_hosp_time}'> 
                                                            <i class='fa-solid fa-right-to-bracket'></i> รับเข้า
                                                    </button>
                                                </td>
                                            </tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='6' class='text-center py-5 text-muted' style='display:table-cell;'><i class='fa-solid fa-bed fs-2 mb-3 opacity-50'></i><br>ไม่มีรายการรอรับเข้า ICU</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination รอรับเข้า -->
                        <?php if($countTotalWait > $limit): ?>
                        <div class="p-3 bg-white border-top d-flex flex-column flex-md-row justify-content-between align-items-center">
                            <span class="small text-muted mb-2 mb-md-0">แสดง <?php echo $offset_wait + 1; ?> - <?php echo min($offset_wait + $limit, $countTotalWait); ?> จากทั้งหมด <?php echo $countTotalWait; ?> รายการ</span>
                            <nav>
                                <ul class="pagination pagination-sm mb-0 shadow-sm">
                                    <li class="page-item <?php echo ($page_wait <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link px-3" href="<?php echo getPageLink('page_wait', $page_wait - 1); ?>">« ย้อนกลับ</a>
                                    </li>
                                    <li class="page-item <?php echo ($offset_wait + $limit >= $countTotalWait) ? 'disabled' : ''; ?>">
                                        <a class="page-link px-3" href="<?php echo getPageLink('page_wait', $page_wait + 1); ?>">ถัดไป »</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- TAB: ผู้ป่วยใน ICU -->
                    <div class="tab-pane fade" id="active" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-icu mb-0">
                                <thead>
                                    <tr>
                                        <th width="5%" class="text-center">ลำดับ</th>
                                        <th width="12%">วันที่รับเข้า</th>
                                        <th width="20%">HN / ชื่อผู้ป่วย</th>
                                        <th width="33%">ข้อมูลรับเข้า ICU</th>
                                        <th width="30%" class="text-center">จัดการการรักษา</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sql2 = "SELECT w.id as ward_id, c.id as case_id, c.hn, c.patient_name, c.age, c.department,
                                            w.an, w.admit_type, w.admit_date, w.icu_diagnosis, w.initial_diagnosis,
                                            w.icu_core_temp_first, w.icu_core_temp_30min, w.icu_control_temp, w.icu_time_er_to_icu
                                            FROM ward_details w
                                            JOIN cases c ON w.case_id = c.id
                                            WHERE w.status = 'Active'
                                            $filterConditionActive
                                            ORDER BY w.admit_date DESC
                                            LIMIT $offset_active, $limit";
                                    $result2 = $conn->query($sql2);

                                    if ($result2 && $result2->num_rows > 0) {
                                        $idx = 0;
                                        while($row = $result2->fetch_assoc()) {
                                            $no = $offset_active + $idx + 1;
                                            $searchKey = !empty($row['an']) ? $row['an'] : $row['hn'];
                                            $active_dx = !empty($row['icu_diagnosis']) ? $row['icu_diagnosis'] : (!empty($row['initial_diagnosis']) ? $row['initial_diagnosis'] : '-');
                                            $rowClass = getHeatSeverityClass($active_dx);
                                            
                                            $icu_ct1 = !empty($row['icu_core_temp_first']) ? $row['icu_core_temp_first'] . " °C" : '-';
                                            $icu_ct30 = !empty($row['icu_core_temp_30min']) ? $row['icu_core_temp_30min'] . " °C" : '-';
                                            $icu_ctrl = !empty($row['icu_control_temp']) ? $row['icu_control_temp'] : '-';

                                            $dx_html = "<div class='fw-bold mb-2' style='color: var(--icu-primary); font-size: 0.95rem;' title='{$active_dx}'><i class='fa-solid fa-heart-pulse me-1'></i> วินิจฉัย: " . mb_strimwidth($active_dx, 0, 35, '...') . "</div>";
                                            $dx_html .= "<div class='row g-2 mt-1'>";
                                            $dx_html .= "<div class='col-12'><span class='badge bg-danger text-white me-2'><i class='fa-solid fa-temperature-half me-1'></i> ICU Temp</span> <span class='small'>แรกรับ: <strong class='text-dark'>{$icu_ct1}</strong> | 30 นาที: <strong class='text-dark'>{$icu_ct30}</strong></span></div>";
                                            $dx_html .= "<div class='col-12'><span class='badge bg-info text-dark me-2'><i class='fa-solid fa-snowflake me-1'></i> Control Temp</span> <span class='small fw-bold text-dark'>{$icu_ctrl}</span></div>";
                                            $dx_html .= "</div>";

                                            echo "<tr class='$rowClass'>
                                                <td class='text-center text-muted fw-bold' data-label='ลำดับ'>{$no}</td>
                                                <td data-label='วันที่รับเข้า'>
                                                    <div class='fw-bold text-dark'>" . date('d/m/Y', strtotime($row['admit_date'])) . "</div>
                                                    <div class='text-muted small mt-1'><i class='fa-regular fa-clock me-1'></i>" . date('H:i', strtotime($row['admit_date'])) . " น.</div>
                                                </td>
                                                <td data-label='ข้อมูลผู้ป่วย'>
                                                    <div class='fw-bold text-primary mb-1'><span class='badge bg-primary bg-opacity-10 text-light border border-primary border-opacity-25'>AN: {$row['an']}</span></div>
                                                    <div class='fw-bold text-dark fs-6'>{$row['patient_name']}</div>
                                                    <div class='text-muted small mt-1'>HN: {$row['hn']}</div>
                                                </td>
                                                <td class='align-top' data-label='ข้อมูลรักษา ICU'>
                                                    <div class='detail-box'>
                                                        {$dx_html}
                                                    </div>
                                                </td>
                                                <td class='text-center align-middle' data-label='จัดการ'>
                                                    <div class='action-buttons-grid d-flex flex-wrap justify-content-center gap-2'>
                                                        <a href='treatmentWard.php?id={$row['ward_id']}' class='btn btn-action-custom btn-soft-success w-100 mb-1' title='บันทึกอาการ (Progress Note)'>
                                                            <i class='fa-solid fa-notes-medical me-1'></i> บันทึกอาการ
                                                        </a>
                                                        <a href='view_progress_notes.php?search={$searchKey}' class='btn btn-action-custom btn-soft-info' style='flex:1;' title='ดู Progress Note'>
                                                            <i class='fa-solid fa-folder-open'></i> ประวัติบันทึกอาการ
                                                        </a>
                                                        <a href='edit_treatmentWard.php?id={$row['ward_id']}' class='btn btn-action-custom btn-soft-warning' style='flex:1;' title='แก้ไขข้อมูลพื้นฐาน'>
                                                            <i class='fa-solid fa-pen'></i> แก้ไข
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>";
                                            $idx++;
                                        }
                                    } else {
                                        echo "<tr><td colspan='5' class='text-center py-5 text-muted' style='display:table-cell;'><i class='fa-solid fa-bed fs-2 mb-3 opacity-50'></i><br>ไม่มีผู้ป่วยกำลังรักษาใน ICU</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination ผู้ป่วยใน ICU -->
                        <?php if($total_active > $limit): ?>
                        <div class="p-3 bg-white border-top d-flex flex-column flex-md-row justify-content-between align-items-center">
                            <span class="small text-muted mb-2 mb-md-0">แสดง <?php echo $offset_active + 1; ?> - <?php echo min($offset_active + $limit, $total_active); ?> จากทั้งหมด <?php echo $total_active; ?> รายการ</span>
                            <nav>
                                <ul class="pagination pagination-sm mb-0 shadow-sm">
                                    <li class="page-item <?php echo ($page_active <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link px-3" href="<?php echo getPageLink('page_active', $page_active - 1); ?>">« ย้อนกลับ</a>
                                    </li>
                                    <li class="page-item <?php echo ($offset_active + $limit >= $total_active) ? 'disabled' : ''; ?>">
                                        <a class="page-link px-3" href="<?php echo getPageLink('page_active', $page_active + 1); ?>">ถัดไป »</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div> 
            </div>
        </div>
    </div>
        
</body>
<?php $path_prefix = "../"; require_once '../footer.php'; ?>