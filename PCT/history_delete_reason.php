<?php
// ==========================================
// history_delete_reason.php
// แสดงรายการที่ถูกลบออกจากคิวรอ ER พร้อมเหตุผล
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

$is_pct    = (isset($_SESSION['name']) && strtolower($_SESSION['name']) === 'pct') || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$is_viewer = (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer');

if (!isset($_SESSION['user_id']) || (!$is_pct && !$is_viewer)) {
    header("Location: ../login.php");
    exit();
}

require_once '../database/db_conn.php';

// ─── Restore Handler ──────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'restore' && $is_pct) {
    $restore_id = isset($_POST['row_ID']) ? (int)$_POST['row_ID'] : 0;
    if ($restore_id > 0) {
        $sqlRestore = "UPDATE data_import SET status = 0, deleted_at = NULL, status_detail = NULL WHERE row_ID = $restore_id AND status = 2";
        $resRestore = mysqli_query($conn, $sqlRestore);
        if ($resRestore && mysqli_affected_rows($conn) > 0) {
            echo json_encode(['success' => true, 'message' => 'กู้คืนรายการเรียบร้อยแล้ว']);
        } else {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรายการหรือเกิดข้อผิดพลาด']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'row_ID ไม่ถูกต้อง']);
    }
    exit();
}

$themeColor = '#dc2626'; // แดง — สื่อถึง "ลบแล้ว"
$themeDark  = '#991b1b';
$themeLight = '#fecaca';
$bgColor    = '#fef2f2';

// ─── Search & Pagination ──────────────────────────────────────────
$search      = isset($_GET['search'])      ? mysqli_real_escape_string($conn, trim($_GET['search']))      : '';
$search_date = isset($_GET['search_date']) ? mysqli_real_escape_string($conn, trim($_GET['search_date'])) : '';
$page        = isset($_GET['page'])        ? max(1, (int)$_GET['page'])                                   : 1;
$limit       = 15;
$offset      = ($page - 1) * $limit;

$whereSQL = " WHERE status = 2 ";
if (!empty($search)) {
    $whereSQL .= " AND (hn LIKE '%$search%' OR Fname LIKE '%$search%' OR person_ID LIKE '%$search%' OR work_located LIKE '%$search%') ";
}
if (!empty($search_date)) {
    $whereSQL .= " AND DATE(deleted_at) = '$search_date' ";
}

// ─── Export Excel ─────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "DeletedER_Reason_Report_" . date('Ymd_His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $sqlExp = "SELECT * FROM data_import $whereSQL ORDER BY deleted_at DESC, row_ID DESC";
    $resExp = mysqli_query($conn, $sqlExp);

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
               xmlns:x="urn:schemas-microsoft-com:office:excel"
               xmlns="http://www.w3.org/TR/REC-html40">
          <head><meta http-equiv="Content-type" content="text/html;charset=utf-8" /></head>
          <body><table border="1">';
    echo '<tr style="background-color:#991b1b; color:white;">
            <th>#</th>
            <th>HN</th>
            <th>เลขประจำตัว</th>
            <th>ชื่อ-นามสกุล</th>
            <th>หน่วยที่ฝึก</th>
            <th>วันที่มีอาการ</th>
            <th>วันที่ถูกลบ</th>
            <th>เหตุผลที่ลบ</th>
          </tr>';

    $n = 1;
    if ($resExp && mysqli_num_rows($resExp) > 0) {
        while ($r = mysqli_fetch_assoc($resExp)) {
            echo '<tr>';
            echo '<td>' . $n++ . '</td>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)($r['hn']          ?? '-')) . '</td>';
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)($r['person_ID']   ?? '-')) . '</td>';
            echo '<td>'                                   . htmlspecialchars((string)($r['Fname']        ?? '-')) . '</td>';
            echo '<td>'                                   . htmlspecialchars((string)($r['work_located'] ?? '-')) . '</td>';
            echo '<td>'                                   . htmlspecialchars((string)($r['data_date']    ?? '-')) . '</td>';
            echo '<td>'                                   . htmlspecialchars((string)($r['deleted_at']   ?? '-')) . '</td>';
            echo '<td>'                                   . htmlspecialchars((string)($r['status_detail'] ?? '-')) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="8">ไม่มีข้อมูล</td></tr>';
    }
    echo '</table></body></html>';
    exit();
}

// ─── Count ────────────────────────────────────────────────────────
$sqlCount  = "SELECT COUNT(row_ID) as total FROM data_import $whereSQL";
$resCount  = mysqli_query($conn, $sqlCount);
$totalRows = (int)(mysqli_fetch_assoc($resCount)['total'] ?? 0);
$totalPages = ($totalRows > 0) ? (int)ceil($totalRows / $limit) : 1;

// ─── Main Query ───────────────────────────────────────────────────
$sqlMain = "SELECT * FROM data_import $whereSQL ORDER BY deleted_at DESC, row_ID DESC LIMIT $offset, $limit";
$resMain = mysqli_query($conn, $sqlMain);

// ─── สถิติเร็ว ─────────────────────────────────────────────────────
$statsQ   = mysqli_query($conn, "SELECT COUNT(row_ID) as total, DATE(deleted_at) as d FROM data_import WHERE status = 2 GROUP BY DATE(deleted_at) ORDER BY d DESC LIMIT 1");
$latestDay = $statsQ ? mysqli_fetch_assoc($statsQ) : null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติรายการที่ถูกลบ (ER Waiting)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --theme-main:  <?php echo $themeColor; ?>;
            --theme-dark:  <?php echo $themeDark; ?>;
            --theme-light: <?php echo $themeLight; ?>;
            --theme-bg:    <?php echo $bgColor; ?>;
            --theme-10:    color-mix(in srgb, var(--theme-main) 10%, transparent);
            --theme-20:    color-mix(in srgb, var(--theme-main) 20%, transparent);
            --border:  #e5e7eb;
            --text:    #111827;
            --soft:    #6b7280;
        }

        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; background: var(--theme-bg); color: var(--text); min-height: 100vh; }
        h1,h2,h3,h4,h5,h6 { font-family: 'Prompt', sans-serif; }

        .page-wrap { max-width: 100%; margin: 0 auto; padding: 20px 30px; width: 100%; }

        /* ─── Hero ─── */
        .page-hero {
            background: linear-gradient(135deg, <?php echo $themeDark; ?> 0%, <?php echo $themeColor; ?> 60%, #f87171 100%);
            border-radius: 20px; padding: 32px 36px; margin-bottom: 25px;
            position: relative; overflow: hidden;
            box-shadow: 0 10px 35px rgba(220, 38, 38, 0.2);
        }
        .page-hero::before { content:''; position:absolute; top:-50px; right:-30px; width:220px; height:220px; background:rgba(255,255,255,.15); border-radius:50%; }
        .page-hero::after  { content:''; position:absolute; bottom:-60px; right:110px; width:140px; height:140px; background:rgba(255,255,255,.08); border-radius:50%; }
        .hero-eyebrow { display:inline-flex; align-items:center; gap:7px; background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.3); color:#fff; padding:5px 16px; border-radius:50px; font-size:.75rem; font-weight:600; letter-spacing:.06em; margin-bottom:12px; backdrop-filter:blur(5px); }
        .page-hero h3 { color:#fff; font-size:1.6rem; font-weight:700; margin:0 0 6px; text-shadow:0 2px 4px rgba(0,0,0,.15); }
        .page-hero p  { color:rgba(255,255,255,.9); font-size:.9rem; margin:0; }

        /* ─── Stat Pill ─── */
        .stat-pill { display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.3); color:#fff; padding:8px 18px; border-radius:50px; font-size:.85rem; font-weight:600; backdrop-filter:blur(4px); }
        .stat-pill strong { font-size:1.2rem; }

        /* ─── Main Card ─── */
        .main-card { background:#fff; border-radius:20px; border:1px solid rgba(220,38,38,.1); box-shadow:0 8px 30px rgba(0,0,0,.04); overflow:hidden; animation:fadeUp .4s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

        /* ─── Search Bar ─── */
        .search-bar-wrap { padding:24px 28px 20px; border-bottom:1px solid var(--border); background:#fffafa; }
        .search-bar-wrap .form-label-sm { font-family:'Prompt',sans-serif; font-size:.72rem; font-weight:600; color:var(--soft); text-transform:uppercase; margin-bottom:6px; display:block; }
        .search-bar-wrap .form-control,
        .search-bar-wrap .input-group-text { border-color:var(--border); border-radius:10px !important; font-size:.9rem; height:42px; }
        .search-bar-wrap .form-control:focus { border-color:var(--theme-main); box-shadow:0 0 0 3px #fecaca; outline:none; }
        .search-bar-wrap .input-group-text { background:var(--theme-bg); color:var(--theme-dark); border-right:none; border-radius:10px 0 0 10px !important; }
        .search-bar-wrap .input-group .form-control { border-left:none; border-radius:0 10px 10px 0 !important; }
        .btn-search { background:var(--theme-main); color:#fff; border:none; border-radius:10px; padding:0 22px; height:42px; font-weight:600; font-size:.87rem; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all .2s; }
        .btn-search:hover { filter:brightness(1.1); color:#fff; transform:translateY(-1px); box-shadow:0 4px 10px rgba(220,38,38,.25); }
        .btn-clear { background:#f1f5f9; color:var(--soft); border:1.5px solid var(--border); border-radius:10px; padding:0 16px; height:42px; font-weight:500; font-size:.87rem; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all .2s; }
        .btn-clear:hover { background:#e2e8f0; color:var(--text); }
        .search-active-bar { display:flex; align-items:center; gap:8px; padding:8px 28px; background:#fef9c3; border-bottom:1px solid #fde68a; font-size:.82rem; color:#92400e; flex-wrap:wrap; }

        /* ─── Table ─── */
        .registry-table { margin:0; }
        .registry-table thead th { font-family:'Prompt',sans-serif; font-size:.85rem; font-weight:600; color:#475569; background:#f8fafc; border-bottom:2px solid #e2e8f0; border-top:none; padding:18px 16px; }
        .registry-table tbody td { padding:18px 16px; vertical-align:middle; border-bottom:1px solid #f1f5f9; font-size:.95rem; }
        .registry-table tbody tr { transition:background .15s; }
        .registry-table tbody tr:hover { background:#fff5f5; }

        .row-num { display:inline-flex; align-items:center; justify-content:center; font-size:.95rem; font-weight:700; color:#475569; }
        .hn-chip { display:inline-block; font-family:'Prompt',sans-serif; font-size:.85rem; font-weight:700; color:var(--theme-dark); background:var(--theme-bg); border:1px solid var(--theme-20); padding:4px 10px; border-radius:6px; }

        /* ─── Delete badge ─── */
        .del-badge { display:inline-flex; align-items:center; gap:5px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:3px 10px; border-radius:50px; font-size:.78rem; font-weight:700; font-family:'Prompt',sans-serif; }

        /* ─── Reason box ─── */
        .reason-box { background:#fff5f5; border:1px solid #fecaca; border-left:4px solid #dc2626; border-radius:8px; padding:10px 14px; font-size:.88rem; color:#7f1d1d; line-height:1.55; }
        .reason-box.empty { background:#f9fafb; border-color:#e5e7eb; border-left-color:#9ca3af; color:#9ca3af; font-style:italic; }

        /* ─── Date display ─── */
        .date-main  { font-weight:700; color:var(--text); font-size:.9rem; }
        .date-sub   { font-size:.78rem; color:var(--soft); margin-top:2px; }

        /* ─── Patient info ─── */
        .patient-name { font-weight:700; color:var(--text); font-size:1rem; }
        .patient-sub  { font-size:.8rem; color:#64748b; margin-top:3px; }

        /* ─── Pagination ─── */
        .pagination-wrap { padding:20px 28px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; background:#fafafa; flex-wrap:wrap; gap:15px; }
        .page-link { border-radius:10px !important; border:1px solid var(--border); color:var(--soft); font-weight:600; transition:all .2s; margin:0 2px; }
        .page-link:hover { border-color:var(--theme-main); color:var(--theme-main); background:var(--theme-bg); }
        .page-item.active .page-link { background:var(--theme-main); border-color:var(--theme-main); color:#fff; box-shadow:0 4px 10px rgba(0,0,0,.1); }

        /* ─── Empty state ─── */
        .empty-state { padding:64px 20px; text-align:center; }
        .empty-state .empty-icon { width:72px; height:72px; background:#f3f4f6; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.8rem; color:#d1d5db; margin:0 auto 18px; }
        .empty-state h6 { font-size:1rem; color:var(--text); margin-bottom:6px; font-weight:600; }
        .empty-state p  { font-size:.85rem; color:var(--soft); margin:0; }

        /* ─── Tooltip-style deleted_at chip ─── */
        .deleted-chip { display:inline-flex; align-items:center; gap:4px; background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; padding:3px 9px; border-radius:6px; font-size:.78rem; font-weight:600; white-space:nowrap; }
    </style>
</head>
<body>

<?php if (file_exists('../header_pct.php')) require_once '../header_pct.php'; ?>

<div class="page-wrap">

    <!-- ─── Hero ─── -->
    <div class="page-hero">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <div class="hero-eyebrow">
                    <i class="fa-solid fa-trash-can me-1"></i> DELETED ER WAITING
                </div>
                <h3><i class="fa-solid fa-clock-rotate-left me-2" style="opacity:.85;"></i>ประวัติรายการที่ถูกลบออกจากคิวรอ ER</h3>
                <p>รายชื่อผู้ป่วยที่ถูกนำออกจากคิวรอ ER รับเข้า พร้อมเหตุผลที่บันทึกไว้</p>
            </div>
            <div class="text-center" style="line-height:1.2;">
                <div style="font-size:3.5rem; font-weight:800; color:#fff; font-family:'Prompt',sans-serif; text-shadow:0 2px 8px rgba(0,0,0,.2);">
                    <?php echo number_format($totalRows); ?>
                </div>
                <div style="font-size:.8rem; color:rgba(255,255,255,.85); font-weight:500;">รายการที่ถูกลบ</div>
                <?php if ($latestDay): ?>
                <div style="font-size:.78rem; color:rgba(255,255,255,.7); margin-top:4px;">
                    <i class="fa-regular fa-calendar-xmark me-1"></i>
                    ล่าสุด <?php echo date('d/m/Y', strtotime($latestDay['d'])); ?>
                    (<?php echo number_format($latestDay['total']); ?> ราย)
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── Main Card ─── -->
    <div class="main-card">

        <!-- Search Bar -->
        <div class="search-bar-wrap">
            <form method="GET">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <span class="form-label-sm"><i class="fa-solid fa-magnifying-glass me-1"></i>ค้นหา</span>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                            <input type="text" class="form-control" name="search"
                                   placeholder="HN, ชื่อ-สกุล, เลขประจำตัว หรือหน่วยที่ฝึก..."
                                   value="<?php echo htmlspecialchars((string)$search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <span class="form-label-sm"><i class="fa-regular fa-calendar-xmark me-1"></i>วันที่ถูกลบ</span>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-regular fa-calendar"></i></span>
                            <input type="date" class="form-control" name="search_date"
                                   value="<?php echo htmlspecialchars((string)$search_date); ?>">
                        </div>
                    </div>
                    <div class="col-md-auto">
                        <button class="btn-search btn" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
                        </button>
                    </div>
                    <?php if (!empty($search) || !empty($search_date)): ?>
                    <div class="col-md-auto">
                        <a href="history_delete_reason.php" class="btn-clear btn">
                            <i class="fa-solid fa-rotate-left"></i> ล้างค่า
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Export Excel -->
                    <div class="col-md-auto ms-auto d-flex align-items-end">
                        <a href="?export=excel&search=<?php echo urlencode($search); ?>&search_date=<?php echo urlencode($search_date); ?>"
                           class="btn d-flex align-items-center gap-2 shadow-sm"
                           style="height:42px; border-radius:10px; font-family:'Prompt',sans-serif; font-weight:600; background:var(--theme-main); border-color:var(--theme-main); color:#fff;">
                            <i class="fa-solid fa-file-excel fs-5"></i> ส่งออก Excel
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Active Filter Bar -->
        <?php if (!empty($search) || !empty($search_date)): ?>
        <div class="search-active-bar">
            <i class="fa-solid fa-filter-circle-xmark"></i> กำลังกรองผลลัพธ์
            <?php if (!empty($search)): ?>
             — คำค้นหา: <strong>"<?php echo htmlspecialchars((string)$search); ?>"</strong>
            <?php endif; ?>
            <?php if (!empty($search_date)): ?>
             — วันที่ถูกลบ: <strong><?php echo date('d/m/Y', strtotime($search_date)); ?></strong>
            <?php endif; ?>
            &nbsp;·&nbsp; พบ <strong><?php echo number_format($totalRows); ?></strong> รายการ
        </div>
        <?php endif; ?>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table registry-table align-middle">
                <thead>
                    <tr>
                        <th width="4%"  class="text-center">#</th>
                        <th width="8%">HN</th>
                        <th width="11%">เลขประจำตัว</th>
                        <th width="15%">ชื่อ-นามสกุล / หน่วยที่ฝึก</th>
                        <th width="12%">วันที่มีอาการ</th>
                        <th width="13%">วันที่ถูกลบ</th>
                        <th width="30%">เหตุผลที่ลบ</th>
                        <th width="13%">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($resMain && mysqli_num_rows($resMain) > 0):
                    $rowNum = $offset + 1;
                    while ($row = mysqli_fetch_assoc($resMain)):
                        $reason     = trim($row['status_detail'] ?? '');
                        $deleted_at = $row['deleted_at'] ?? '';
                        $data_date  = $row['data_date']  ?? '';
                ?>
                    <tr>
                        <td class="text-center"><span class="row-num"><?php echo $rowNum++; ?></span></td>

                        <td>
                            <span class="hn-chip"><?php echo htmlspecialchars((string)($row['hn'] ?? '-')); ?></span>
                        </td>

                        <td class="small text-muted" style="white-space:nowrap; font-family:'Prompt',sans-serif;">
                            <?php echo htmlspecialchars((string)($row['person_ID'] ?? '-')); ?>
                        </td>

                        <td>
                            <div class="patient-name"><?php echo htmlspecialchars((string)($row['Fname'] ?? '-')); ?></div>
                            <?php if (!empty($row['work_located'])): ?>
                            <div class="patient-sub">
                                <i class="fa-regular fa-building me-1"></i>
                                <?php echo htmlspecialchars((string)$row['work_located']); ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($data_date && $data_date !== '0000-00-00 00:00:00'): ?>
                                <div class="date-main"><?php echo date('d/m/Y', strtotime($data_date)); ?></div>
                                <div class="date-sub"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($data_date)); ?> น.</div>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($deleted_at && $deleted_at !== '0000-00-00 00:00:00'): ?>
                                <div class="deleted-chip mb-1">
                                    <i class="fa-solid fa-trash-can"></i>
                                    <?php echo date('d/m/Y', strtotime($deleted_at)); ?>
                                </div>
                                <div class="date-sub"><i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($deleted_at)); ?> น.</div>
                            <?php else: ?>
                                <span class="text-muted small">ไม่บันทึกเวลา</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($reason)): ?>
                                <div class="reason-box">
                                    <i class="fa-solid fa-comment-dots me-1" style="color:#dc2626;"></i>
                                    <?php echo nl2br(htmlspecialchars($reason)); ?>
                                </div>
                            <?php else: ?>
                                <div class="reason-box empty">
                                    <i class="fa-solid fa-circle-minus me-1"></i> ไม่ได้ระบุเหตุผล
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_pct): ?>
                            <button class="btn btn-sm btn-primary btn-restore"
                                    data-id="<?php echo (int)$row['row_ID']; ?>"
                                    data-name="<?php echo htmlspecialchars((string)($row['Fname'] ?? '')); ?>">
                                <i class="fa-solid fa-rotate-left me-1"></i> กู้คืน
                            </button>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class="fa-regular fa-trash-can"></i>
                                </div>
                                <h6>ไม่พบรายการที่ถูกลบ</h6>
                                <p>ยังไม่มีรายการที่ถูกลบออกจากคิวรอ ER หรือลองปรับเงื่อนไขการค้นหาใหม่</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
            <span style="font-size:.85rem; color:var(--soft);">
                แสดง <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $limit, $totalRows)); ?>
                จาก <?php echo number_format($totalRows); ?> รายการ
            </span>
            <nav>
                <ul class="pagination mb-0">
                    <li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
                        <?php $prev_url = "?page=" . ($page - 1) . "&search=" . urlencode($search) . "&search_date=" . urlencode($search_date); ?>
                        <a class="page-link" href="<?php echo htmlspecialchars((string)$prev_url); ?>">
                            <i class="fa-solid fa-chevron-left" style="font-size:.7rem;"></i>
                        </a>
                    </li>
                    <?php
                    $range = 2;
                    for ($i = 1; $i <= $totalPages; $i++) {
                        if ($i == 1 || $i == $totalPages || ($i >= $page - $range && $i <= $page + $range)) {
                            $active   = ($i == $page) ? 'active' : '';
                            $page_url = "?page=$i&search=" . urlencode($search) . "&search_date=" . urlencode($search_date);
                            echo "<li class='page-item $active'><a class='page-link' href='" . htmlspecialchars((string)$page_url) . "'>$i</a></li>";
                        } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                            echo "<li class='page-item disabled'><span class='page-link' style='font-size:.8rem;'>···</span></li>";
                        }
                    }
                    ?>
                    <li class="page-item <?php if ($page >= $totalPages) echo 'disabled'; ?>">
                        <?php $next_url = "?page=" . ($page + 1) . "&search=" . urlencode($search) . "&search_date=" . urlencode($search_date); ?>
                        <a class="page-link" href="<?php echo htmlspecialchars((string)$next_url); ?>">
                            <i class="fa-solid fa-chevron-right" style="font-size:.7rem;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

    </div><!-- /.main-card -->
</div><!-- /.page-wrap -->

<?php if (file_exists('../footer.php')) require_once '../footer.php'; ?>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.btn-restore').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var rowId  = this.dataset.id;
        var name   = this.dataset.name || 'รายการนี้';
        var btnEl  = this;

        Swal.fire({
            title: 'ยืนยันการกู้คืน',
            html: 'ต้องการกู้คืน <strong>' + name + '</strong><br>กลับเข้าคิวรอ ER ใช่หรือไม่?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor:  '#6b7280',
            confirmButtonText:  '<i class="fa-solid fa-rotate-left me-1"></i> ยืนยัน กู้คืน',
            cancelButtonText:   'ยกเลิก',
            reverseButtons: true
        }).then(function(result) {
            if (!result.isConfirmed) return;

            btnEl.disabled = true;
            btnEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังกู้คืน...';

            var formData = new FormData();
            formData.append('action', 'restore');
            formData.append('row_ID', rowId);

            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'กู้คืนสำเร็จ!',
                        text: data.message,
                        confirmButtonColor: '#16a34a',
                        timer: 2000,
                        timerProgressBar: true
                    }).then(function() {
                        location.reload();
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message, confirmButtonColor: '#dc2626' });
                    btnEl.disabled = false;
                    btnEl.innerHTML = '<i class="fa-solid fa-rotate-left me-1"></i> กู้คืน';
                }
            })
            .catch(function() {
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', confirmButtonColor: '#dc2626' });
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fa-solid fa-rotate-left me-1"></i> กู้คืน';
            });
        });
    });
});
</script>
</body>
</html>