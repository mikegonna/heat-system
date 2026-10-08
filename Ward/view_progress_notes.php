<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

// --- ดึงข้อมูล User ปัจจุบันเพื่อใช้ตรวจสอบสิทธิ์ ---
$current_user_id = $_SESSION['user_id'];
$sqlUser = "SELECT name, role FROM users WHERE id = '$current_user_id'";
$queryUser = $conn->query($sqlUser);
$currentUser = $queryUser->fetch_assoc();
$current_user_name = $currentUser['name']; // เช่น icu, a4, b3
$current_user_role = $currentUser['role']; // admin, user, viewer

// ตรวจสอบว่าเป็น ICU หรือไม่ เพื่อกำหนดสี
$isICU = (strtoupper($current_user_name) == 'ICU');

// --------------------------------------------------------------------------------
// ส่วนที่ 1: AJAX Handler (สำหรับโหลดข้อมูลใส่ Popup)
// --------------------------------------------------------------------------------
if (isset($_GET['ajax_id'])) {
    $ward_id = intval($_GET['ajax_id']);
    
    // รับค่าวันที่สำหรับกรอง
    $filter_date = isset($_GET['date']) ? mysqli_real_escape_string($conn, $_GET['date']) : '';

    // 1. ดึงข้อมูล Ward Details (รวมถึงข้อมูลการจำหน่าย)
    $sqlInfo = "SELECT w.*, c.patient_name, c.hn, c.age, c.patient_id_card, c.congenital_disease, c.phone, c.department
                FROM ward_details w
                JOIN cases c ON w.case_id = c.id
                WHERE w.id = '$ward_id'";
    $resInfo = $conn->query($sqlInfo);
    
    if ($resInfo->num_rows == 0) { echo "<div class='alert alert-danger'>ไม่พบข้อมูล</div>"; exit; }
    
    $info = $resInfo->fetch_assoc();

    // [SECURITY CHECK]
    if ($current_user_role !== 'admin' && $current_user_role !== 'viewer' && $current_user_name !== 'pct') {
        if (stripos($info['admit_type'], $current_user_name) === false) {
            echo "<div class='alert alert-danger text-center p-4'>
                    <i class='fa-solid fa-ban fa-3x text-danger mb-3'></i><br>
                    <h4>คุณไม่มีสิทธิ์เข้าถึงข้อมูลของแผนกอื่น</h4>
                    <p class='text-muted'>({$info['admit_type']})</p>
                  </div>";
            exit;
        }
    }

    // 2. ดึงประวัติ Progress Note (เพิ่มเงื่อนไขวันที่)
    $sqlNotes = "SELECT * FROM ward_progress_notes WHERE ward_detail_id = '$ward_id' ";
    
    if (!empty($filter_date)) {
        $sqlNotes .= " AND DATE(note_datetime) = '$filter_date' ";
    }

    $sqlNotes .= " ORDER BY note_datetime DESC";
    
    $notes = $conn->query($sqlNotes);
    ?>
    
    <div class="report-content p-2">
        <div class="d-flex justify-content-between align-items-end border-bottom pb-3 mb-3" style="border-color: var(--ward-primary) !important;">
            <div>
                <h4 class="fw-bold mb-0" style="color: var(--ward-primary);">Progress Note Report</h4>
                <small class="text-muted">โรงพยาบาลอานันทมหิดล | <?php echo $info['admit_type']; ?></small>
            </div>
            <div class="text-end">
                <span class="badge fs-5 border text-dark" style="background-color: var(--ward-light);">AN: <?php echo $info['an']; ?></span>
                <div class="small text-muted mt-1">สถานะ: <?php echo $info['status']; ?></div>
            </div>
        </div>

        <div class="row g-2 mb-4 small bg-light p-3 rounded border">
            <div class="col-md-6"><strong style="color: var(--ward-primary);">ชื่อ-สกุล:</strong> <?php echo $info['patient_name']; ?></div>
            <div class="col-md-3"><strong style="color: var(--ward-primary);">อายุ:</strong> <?php echo $info['age']; ?> ปี</div>
            <div class="col-md-3"><strong style="color: var(--ward-primary);">HN:</strong> <?php echo $info['hn']; ?></div>
            
            <div class="col-md-6"><strong style="color: var(--ward-primary);">สังกัด:</strong> <?php echo $info['department']; ?></div>
            <div class="col-md-6"><strong style="color: var(--ward-primary);">โรคประจำตัว:</strong> <?php echo $info['congenital_disease']; ?></div>
            
            <div class="col-md-6"><strong style="color: var(--ward-primary);">Admit:</strong> <?php echo date('d/m/Y H:i', strtotime($info['admit_date'])); ?></div>
            <div class="col-md-6"><strong style="color: var(--ward-primary);">แผนก:</strong> <?php echo $info['admit_type']; ?></div>

            <div class="col-md-12 mt-2 border-top pt-2">
                <strong style="color: var(--ward-primary);">วินิจฉัยแรกรับ:</strong> 
                <?php 
                    if (!empty($info['icu_diagnosis'])) {
                        echo "<span class='text-danger'>{$info['icu_diagnosis']} (ICU)</span>";
                    } else {
                        echo "<span class='text-dark'>" . (!empty($info['initial_diagnosis']) ? $info['initial_diagnosis'] : '-') . "</span>";
                    }
                ?>
            </div>
            
            <?php if(!empty($info['final_diagnosis'])): ?>
            <div class="col-md-12 mt-1">
                <strong class="text-danger">วินิจฉัยสุดท้าย:</strong> 
                <span class="text-dark fw-bold"><?php echo $info['final_diagnosis']; ?></span>
            </div>
            <?php endif; ?>

            <?php if($info['status'] != 'Active' && !empty($info['discharge_status'])): ?>
            <div class="col-md-12 mt-2 border-top pt-2">
                <strong style="color: var(--ward-primary);">สถานะจำหน่าย:</strong> 
                <span class="badge bg-secondary"><?php echo $info['discharge_status']; ?></span>
                
                <?php if ($info['discharge_status'] == 'Admit' && !empty($info['discharge_detail'])): ?>
                    <span class="ms-2 text-dark"><i class="fa-solid fa-share me-1"></i><strong>ปลายทาง:</strong> <span class="text-primary"><?php echo $info['discharge_detail']; ?></span></span>
                <?php elseif ($info['discharge_status'] == 'Refer' && !empty($info['discharge_detail'])): ?>
                    <span class="ms-2 text-dark"><i class="fa-solid fa-hospital me-1"></i><strong>ส่งต่อ รพ.:</strong> <span class="text-primary"><?php echo $info['discharge_detail']; ?></span></span>
                <?php elseif ($info['discharge_status'] == 'Dead' && !empty($info['death_cause'])): ?>
                    <span class="ms-2 text-danger"><i class="fa-solid fa-skull me-1"></i><strong>สาเหตุ:</strong> <?php echo $info['death_cause']; ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2 no-print">
            <h6 class="fw-bold text-secondary mb-0"><i class="fa-solid fa-list-check me-2"></i>บันทึกอาการรายวัน</h6>
            
            <div class="input-group input-group-sm" style="width: 250px;">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-filter"></i></span>
                <input type="date" class="form-control border-start-0 ps-0" 
                       value="<?php echo $filter_date; ?>" 
                       onchange="loadModalData(<?php echo $ward_id; ?>, this.value)"
                       title="เลือกวันที่เพื่อกรองข้อมูล">
                <?php if(!empty($filter_date)): ?>
                    <button class="btn btn-secondary" onclick="loadModalData(<?php echo $ward_id; ?>, '')">ล้าง</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm small">
                <thead class="bg-light">
                    <tr>
                        <th width="15%">วัน/เวลา</th>
                        <th width="15%">Heat Status</th>
                        <th>รายละเอียดอาการ</th>
                        <th width="20%">ผลการรักษา/ปลายทาง</th> <th width="15%">ผู้บันทึก</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($notes->num_rows > 0): ?>
                        <?php while($note = $notes->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('d/m/Y', strtotime($note['note_datetime'])); ?></strong><br>
                                    <span class="text-muted"><?php echo date('H:i', strtotime($note['note_datetime'])); ?> น.</span>
                                </td>
                                <td>
                                    <?php 
                                        $heatColor = ($note['heat_status'] == 'Re-Heat') ? 'text-danger' : 'text-success';
                                        echo "<span class='fw-bold $heatColor'>".(isset($note['heat_status']) ? $note['heat_status'] : '-')."</span>";
                                    ?>
                                </td>
                                <td style="white-space: pre-line;"><?php echo $note['details']; ?></td>
                                <td>
                                    <span class="badge bg-info text-dark border">
                                        <?php echo isset($note['treatment_result']) ? $note['treatment_result'] : '-'; ?>
                                    </span>
                                    
                                    <?php 
                                        $tr = $note['treatment_result'];
                                        if ($tr == 'Admit' && !empty($info['discharge_detail']) && strpos($info['discharge_detail'], 'ย้าย') !== false) {
                                            echo "<div class='text-muted mt-1' style='font-size: 0.85em;'><i class='fa-solid fa-share fa-sm me-1'></i><strong>ปลายทาง:</strong> ".$info['discharge_detail']."</div>";
                                        } elseif ($tr == 'Refer' && !empty($info['discharge_detail'])) {
                                            echo "<div class='text-primary mt-1' style='font-size: 0.85em;'><i class='fa-solid fa-hospital fa-sm me-1'></i><strong>ส่งต่อ:</strong> ".$info['discharge_detail']."</div>";
                                        } elseif ($tr == 'Dead' && !empty($info['death_cause'])) {
                                            echo "<div class='text-danger mt-1' style='font-size: 0.85em;'><i class='fa-solid fa-skull fa-sm me-1'></i><strong>สาเหตุ:</strong> ".$info['death_cause']."</div>";
                                        } elseif ($tr == 'Discharged' && !empty($info['discharge_detail'])) {
                                            echo "<div class='text-muted mt-1' style='font-size: 0.85em;'><strong>Note:</strong> ".$info['discharge_detail']."</div>";
                                        }
                                    ?>
                                </td>
                                <td class="text-center"><?php echo $note['doctor_name']; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">
                            <i class="fa-regular fa-calendar-xmark fa-2x mb-2"></i><br>
                            ไม่พบข้อมูลบันทึกอาการ <?php echo !empty($filter_date) ? "ในวันที่เลือก" : ""; ?>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mt-4 text-center no-print d-flex justify-content-center gap-2">
            <?php if($info['status'] == 'Active' && stripos($info['admit_type'], $current_user_name) !== false): ?>
            <a href="treatmentWard.php?id=<?php echo $ward_id; ?>" class="btn btn-sm text-white" style="background-color: var(--ward-primary);">
                <i class="fa-solid fa-pen-to-square me-1"></i> ไปหน้าบันทึกอาการ
            </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-print me-1"></i> พิมพ์หน้านี้
            </button>
        </div>
    </div>
    <?php
    exit(); // จบการทำงานของ AJAX
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการ Progress Note - ระบบ Ward</title>
    
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root { 
            --ward-primary: <?php echo $isICU ? '#fb923c' : '#0c784b'; ?>; 
            --ward-light: <?php echo $isICU ? '#fed7aa' : '#e0f2f1'; ?>;
        }
        
        body { 
            font-family: 'Sarabun', sans-serif; 
            background-color: <?php echo $isICU ? '#fff7ed' : '#fbfff1'; ?>; 
        }
        h1, h2, h3, h4, h5, .btn { font-family: 'Prompt', sans-serif; }
        
        .search-card {
            background: white; border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-left: 5px solid var(--ward-primary);
        }
        .table-card {
            background: white; border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden;
        }
        .table thead th {
            background-color: var(--ward-primary); color: white;
            font-weight: 500; border: none; padding: 12px;
        }
        .table tbody tr:hover { background-color: var(--ward-light); cursor: pointer; }
        
        /* Modal Styles */
        .modal-header { background-color: var(--ward-primary); color: white; }
        .modal-title { font-family: 'Prompt', sans-serif; }
        .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        
        .btn-theme {
            background-color: var(--ward-primary);
            color: white;
            border: none;
        }
        .btn-theme:hover { opacity: 0.9; color: white; }
        .text-theme { color: var(--ward-primary) !important; }

        /* CSS สำหรับการพิมพ์ (Print Stylesheet) */
        @media print {
            body > * {
                display: none !important;
            }

            #noteModal {
                display: block !important;
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                background-color: white !important;
                z-index: 9999 !important;
            }

            #noteModal .modal-dialog {
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                transform: none !important; 
            }

            #noteModal .modal-content {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
            }

            #noteModal .modal-body {
                padding: 0 !important;
                overflow: visible !important;
            }

            #modalContent, #modalContent * {
                visibility: visible !important;
            }

            .modal-header, .modal-footer, .no-print, .btn, .input-group {
                display: none !important;
            }

            .table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            
            tr {
                page-break-inside: avoid; 
            }
            
            body, .card, .table, td, th {
                background-color: white !important;
                color: black !important;
            }
            
            a[href]:after {
                content: none !important;
            }
        }
    </style>
</head>

<?php 
    $path_prefix = "../"; 
    if (isset($_SESSION['name']) && strtoupper($_SESSION['name']) == 'ICU') {
        require_once '../header_icu.php';
    } else {
        require_once '../header_ward.php';
    }
?>

<div class="container py-4">
    
    <div class="search-card p-4 mb-4 no-print">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-file-medical me-2 text-theme"></i>ทะเบียน Progress Note</h4>
                <p class="text-muted mb-0 small">
                    <?php 
                        if ($current_user_role == 'admin') echo "แสดงข้อมูลทั้งหมด (Admin Mode)";
                        else echo "แสดงเฉพาะผู้ป่วยของ: <span class='fw-bold text-theme'>" . strtoupper($current_user_name) . "</span>";
                    ?>
                </p>
            </div>
            <div class="col-md-6 mt-3 mt-md-0">
                <form action="" method="GET">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 bg-light" 
                               placeholder="ค้นหา AN, HN หรือ ชื่อผู้ป่วย..." 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        <button type="submit" class="btn btn-theme px-4">ค้นหา</button>
                        <?php if(isset($_GET['search']) && $_GET['search'] != ''): ?>
                            <a href="view_progress_notes.php" class="btn btn-outline-secondary">ล้างค่า</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="table-card no-print">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th width="15%">วันที่รับเข้า</th>
                        <th width="15%">AN / HN</th> 
                        <th width="25%">ชื่อ-สกุล</th>
                        <th width="20%">ประเภท Admit</th>
                        <th width="10%">สถานะ</th>
                        <th width="15%" class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
                    $whereSQL = " WHERE 1=1 ";
                    
                    if ($search != '') {
                        $whereSQL .= " AND (c.hn LIKE '%$search%' OR c.patient_name LIKE '%$search%' OR w.an LIKE '%$search%') ";
                    }

                    if ($current_user_role !== 'admin' && $current_user_role !== 'viewer' && $current_user_name !== 'pct') {
                        $whereSQL .= " AND w.admit_type LIKE '%$current_user_name%' ";
                    }

                    $sql = "SELECT w.id as ward_id, c.hn, c.patient_name, w.admit_date, 
                                   w.an, w.admit_type, w.status
                            FROM ward_details w
                            JOIN cases c ON w.case_id = c.id
                            $whereSQL
                            ORDER BY w.admit_date DESC LIMIT 50";
                            
                    $result = $conn->query($sql);

                if ($result && $result->num_rows > 0) { 
                    while($row = $result->fetch_assoc()) {
                        $statusBadge = ($row['status'] == 'Active') 
                            ? '<span class="badge bg-success bg-opacity-10 text-white border border-success px-3">Active</span>'
                            : '<span class="badge bg-secondary bg-opacity-10 text-white border px-3">จำหน่าย</span>';
                            
                            echo "<tr>
                                <td>".date('d/m/Y', strtotime($row['admit_date']))."</td>
                                <td>
                                    <div class='fw-bold fs-6 text-theme'>AN: {$row['an']}</div>
                                    <div class='text-muted small'>HN: {$row['hn']}</div>
                                </td>
                                <td>{$row['patient_name']}</td>
                                <td>
                                    <div class='fw-bold text-dark'>{$row['admit_type']}</div>
                                </td>
                                <td>{$statusBadge}</td>
                                <td class='text-center'>
                                    <button class='btn btn-sm rounded-pill px-3 shadow-sm btn-theme' onclick='openNoteModal({$row['ward_id']})'>
                                        <i class='fa-solid fa-eye me-1'></i> Progress Notes
                                    </button>
                                </td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' class='text-center py-5 text-muted'>
                                <i class='fa-solid fa-folder-open fa-2x mb-3'></i><br>
                                ไม่พบข้อมูล
                              </td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6"><i class="fa-solid fa-file-medical me-2"></i>รายละเอียด Progress Note</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalContent">
                </div>
        </div>
    </div>
</div>

<script>
    function openNoteModal(wardId) {
        var myModal = new bootstrap.Modal(document.getElementById('noteModal'));
        myModal.show();
        loadModalData(wardId, ''); // เรียกโหลดข้อมูลครั้งแรก (ไม่กรอง)
    }

    // ฟังก์ชันโหลดข้อมูล (ใช้ร่วมกันทั้งเปิดครั้งแรก และตอนเปลี่ยนวันที่)
    function loadModalData(wardId, date) {
        var contentDiv = document.getElementById('modalContent');
        
        // ทำให้ดูจางลงเล็กน้อยขณะโหลด (UX)
        contentDiv.style.opacity = '0.6';
        
        // ถ้าเป็นการเปิดครั้งแรก (ยังไม่มีข้อมูล) ให้แสดง Spinner
        if(contentDiv.innerHTML.trim() == '') {
            contentDiv.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-theme" role="status"></div>
                    <p class="mt-2 text-muted">กำลังโหลดข้อมูล...</p>
                </div>
            `;
            contentDiv.style.opacity = '1';
        }

        fetch(`view_progress_notes.php?ajax_id=${wardId}&date=${date}`)
            .then(response => response.text())
            .then(html => {
                contentDiv.innerHTML = html;
                contentDiv.style.opacity = '1';
            })
            .catch(err => {
                contentDiv.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>';
                console.error(err);
            });
    }
</script>
<?php $path_prefix = "../"; require_once '../footer.php'; ?>
</body>
</html>