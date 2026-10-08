<?php
// 1. เปิดแสดง Error
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
require_once '../database/db_conn.php';

// --- 💡 ตรวจสอบประเภทผู้ใช้ ---
$current_user_name = strtolower($_SESSION['name'] ?? '');
$is_pcu = ($current_user_name === 'pcu');
$is_coc = ($current_user_name === 'coc');

// --- 💡 Visit Unit Tab (สำหรับ PCU: ทั้งหมด / PCU / CQI) ---
$visit_tab = isset($_GET['visit_tab']) ? $_GET['visit_tab'] : 'all';

// --- 💡 COC Sub-tab (สำหรับ COC: ทั้งหมด / COC) ---
$coc_tab = isset($_GET['coc_tab']) ? $_GET['coc_tab'] : 'coc';

// --- 💡 สร้าง visit_unit filter ---
$visit_unit_filter = '';
if ($is_pcu) {
    if ($visit_tab === 'pcu') {
        $visit_unit_filter = "AND (COALESCE(w.visit_unit, e.visit_unit, '') IN ('PCU', ''))";
    } elseif ($visit_tab === 'cqi') {
        $visit_unit_filter = "AND COALESCE(w.visit_unit, e.visit_unit, '') = 'CQI'";
    } else {
        // tab 'all' → เห็น PCU, CQI และเคสที่ยังไม่ระบุ visit_unit
        $visit_unit_filter = "AND (COALESCE(w.visit_unit, e.visit_unit, '') IN ('PCU', 'CQI', ''))";
    }
} elseif ($is_coc) {
    if ($coc_tab === 'all') {
        // ทั้งหมด → เห็นทุก visit_unit (ไม่กรอง)
        $visit_unit_filter = "";
    } else {
        // tab 'coc' (default) → เห็นเฉพาะ COC
        $visit_unit_filter = "AND COALESCE(w.visit_unit, e.visit_unit, '') = 'COC'";
    }
}

// --- สร้าง visit_unit filter สำหรับ query ที่ไม่มี w (ER-only query) ---
$visit_unit_filter_er = '';
// --- สร้าง visit_unit filter สำหรับ query ที่มีเฉพาะ w ไม่มี e (Ward-only query) ---
$visit_unit_filter_ward = '';
if ($is_pcu) {
    if ($visit_tab === 'pcu') {
        $visit_unit_filter_er = "AND (COALESCE(e.visit_unit, '') IN ('PCU', ''))";
        $visit_unit_filter_ward = "AND (COALESCE(w.visit_unit, '') IN ('PCU', ''))";
    } elseif ($visit_tab === 'cqi') {
        $visit_unit_filter_er = "AND COALESCE(e.visit_unit, '') = 'CQI'";
        $visit_unit_filter_ward = "AND COALESCE(w.visit_unit, '') = 'CQI'";
    } else {
        $visit_unit_filter_er = "AND (COALESCE(e.visit_unit, '') IN ('PCU', 'CQI', ''))";
        $visit_unit_filter_ward = "AND (COALESCE(w.visit_unit, '') IN ('PCU', 'CQI', ''))";
    }
} elseif ($is_coc) {
    if ($coc_tab === 'all') {
        $visit_unit_filter_er = "";
        $visit_unit_filter_ward = "";
    } else {
        $visit_unit_filter_er = "AND COALESCE(e.visit_unit, '') = 'COC'";
        $visit_unit_filter_ward = "AND COALESCE(w.visit_unit, '') = 'COC'";
    }
}

// --- 💡 FILTER ---
$search = isset($_GET['search']) ? $_GET['search'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'wait'; // จดจำแท็บที่เปิดอยู่

// --- 💡 PAGINATION SETUP (จำกัด 10 เคสต่อหน้า) ---
$limit = 10;
$w_page = isset($_GET['w_page']) ? max(1, (int)$_GET['w_page']) : 1;
$w_offset = ($w_page - 1) * $limit;

$h_page = isset($_GET['h_page']) ? max(1, (int)$_GET['h_page']) : 1;
$h_offset = ($h_page - 1) * $limit;

// เงื่อนไขการค้นหา
// $filterSQL    = ใช้กับ query ที่มี JOIN ward_details (w) และ er_details (e)
// $filterSQL_er = ใช้กับ query ที่มีเฉพาะ er_details (e) ไม่มี w
$filterSQL    = "";
$filterSQL_er = "";

if (!empty($search)) {
    $filterSQL    .= " AND (c.hn LIKE '%$search%' OR w.an LIKE '%$search%' OR c.patient_name LIKE '%$search%') ";
    $filterSQL_er .= " AND (c.hn LIKE '%$search%' OR c.patient_name LIKE '%$search%') ";
}
if (!empty($start_date) && !empty($end_date)) {
    $filterSQL    .= " AND DATE(COALESCE(w.discharge_date, w.admit_date, e.recorded_at, c.created_at)) BETWEEN '$start_date' AND '$end_date' ";
    $filterSQL_er .= " AND DATE(COALESCE(e.recorded_at, c.created_at)) BETWEEN '$start_date' AND '$end_date' ";
} elseif (!empty($start_date)) {
    $filterSQL    .= " AND DATE(COALESCE(w.discharge_date, w.admit_date, e.recorded_at, c.created_at)) >= '$start_date' ";
    $filterSQL_er .= " AND DATE(COALESCE(e.recorded_at, c.created_at)) >= '$start_date' ";
} elseif (!empty($end_date)) {
    $filterSQL    .= " AND DATE(COALESCE(w.discharge_date, w.admit_date, e.recorded_at, c.created_at)) <= '$end_date' ";
    $filterSQL_er .= " AND DATE(COALESCE(e.recorded_at, c.created_at)) <= '$end_date' ";
}

function getSeverity($dx) {
    if (preg_match('/Heat Stroke|Heat Exhaustion|Rhabdomyolysis \(CPK > 1,000\)/i', $dx)) {
        return 'Severe'; // เยี่ยม 2 ครั้ง
    }
    return 'Moderate'; // เยี่ยม 1 ครั้ง
}

function getMaxVisits($severity) {
    return $severity === 'Severe' ? 2 : 1;
}

// คำนวณ badge วันนัดเยี่ยม เทียบกับวันนี้ realtime
// $dc_date = discharge_date หรือ er_time
// $visit_count = จำนวนครั้งที่เยี่ยมไปแล้ว
// $severity = 'Severe' | 'Moderate'
function getVisitDueBadge($dc_date, $visit_count, $severity) {
    if (empty($dc_date)) return '';

    $max_visits = ($severity === 'Severe') ? 2 : 1;

    // ถ้าเยี่ยมครบแล้ว ไม่ต้องแสดง
    if ($visit_count >= $max_visits) return '';

    // กำหนด target day ตาม visit ที่ยังค้างอยู่
    // ครั้งที่ 1 = Day 3, ครั้งที่ 2 = Day 6
    $target_day = ($visit_count == 0) ? 3 : 6;
    $target_ts  = strtotime($dc_date . " +{$target_day} days");
    $today_ts   = strtotime(date('Y-m-d'));
    $diff       = (int)(($today_ts - $target_ts) / 86400); // บวก=เลย, ลบ=ยังไม่ถึง

    if ($diff > 0) {
        return "<div class='st-badge bg-danger text-white w-100 mt-1'>"
             . "<i class='fa-solid fa-circle-exclamation me-1'></i>"
             . "เลยกำหนดการเยี่ยมครั้งแรกแล้ว {$diff} วัน</div>";
    } elseif ($diff == 0) {
        return "<div class='st-badge w-100 mt-1' style='background:#fff3cd;color:#92400e;border:1px solid #fcd34d;'>"
             . "<i class='fa-solid fa-bell me-1'></i>"
             . "ถึงกำหนดเยี่ยมวันนี้!</div>";
    } else {
        $days_left = abs($diff);
        return "<div class='st-badge bg-light text-muted border w-100 mt-1'>"
             . "<i class='fa-regular fa-calendar me-1'></i>"
             . "นัดเยี่ยมครั้งแรก : อีก {$days_left} วัน</div>";
    }
}
// ---------------------------------------------------------------------------
// Helper Function: สร้าง Timeline (ใหม่ - ล่าสุดอยู่บนสุด)
// ---------------------------------------------------------------------------
function getPatientTimeline($conn, $case_id) {
    $steps = [];
    
    $qER = $conn->query("SELECT id, disposition, admit_type, hospital_arrival_datetime, recorded_at FROM er_details WHERE case_id = '$case_id' ORDER BY id DESC LIMIT 1");
    $er_data = null;
    if ($qER && $qER->num_rows > 0) { 
        $er_data = $qER->fetch_assoc();
        $steps[] = ['type' => 'er', 'label' => 'ER', 'icon' => 'fa-truck-medical', 'datetime' => $er_data['hospital_arrival_datetime'] ?? null]; 
    }
    
    $qWard = $conn->query("SELECT admit_type, status, discharge_status, admit_date, discharge_date FROM ward_details WHERE case_id = '$case_id' ORDER BY id ASC");
    $ward_count = 0;
    if ($qWard && $qWard->num_rows > 0) {
        while ($w = $qWard->fetch_assoc()) {
            $ward_count++;
            $j_class = 'default';
            if(stripos($w['admit_type'], 'ICU') !== false) { $j_class = 'icu'; }
            elseif(stripos($w['admit_type'], 'RCU') !== false) { $j_class = 'rcu'; }
            elseif(stripos($w['admit_type'], 'A4') !== false) { $j_class = 'a4'; }
            elseif(stripos($w['admit_type'], 'B3') !== false) { $j_class = 'b3'; }

            $lbl = $w['admit_type'];
            if ($w['status'] == 'AdmitWait') { $lbl .= ' (รอรับ)'; }
            
            $steps[] = ['type' => $j_class, 'label' => $lbl, 'icon' => 'fa-bed-pulse', 'datetime' => $w['admit_date'] ?? null];
            
            if ($w['status'] == 'Discharged') { 
                if ($w['discharge_status'] == 'Dead') {
                    $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
                } elseif ($w['discharge_status'] == 'Refer') {
                    $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
                } else {
                    $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $w['discharge_date'] ?? null]; 
                }
            }
            elseif ($w['status'] == 'Dead') { $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull']; }
            elseif ($w['status'] == 'Refer') { $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance']; }
        }
    }
    
    if ($ward_count == 0 && $er_data && $er_data['disposition'] == 'Admit' && !empty($er_data['admit_type'])) {
        $w_type = $er_data['admit_type'];
        $j_class = 'default';
        if(stripos($w_type, 'ICU') !== false) { $j_class = 'icu'; }
        elseif(stripos($w_type, 'RCU') !== false) { $j_class = 'rcu'; }
        elseif(stripos($w_type, 'A4') !== false) { $j_class = 'a4'; }
        elseif(stripos($w_type, 'B3') !== false) { $j_class = 'b3'; }
        
        $steps[] = ['type' => $j_class, 'label' => $w_type . ' (รอรับ)', 'icon' => 'fa-bed-pulse'];
    }
    if ($ward_count == 0 && $er_data) {
        if ($er_data['disposition'] == 'Dead') {
            $steps[] = ['type' => 'dead', 'label' => 'เสียชีวิต', 'icon' => 'fa-book-skull'];
        } elseif ($er_data['disposition'] == 'Refer') {
            $steps[] = ['type' => 'refer', 'label' => 'Refer', 'icon' => 'fa-ambulance'];
        } elseif ($er_data['disposition'] == 'Discharge') {
            $steps[] = ['type' => 'dc', 'label' => 'D/C', 'icon' => 'fa-house-circle-check', 'datetime' => $er_data['recorded_at'] ?? null];
        }
    }
    
    if(empty($steps)) return '<span class="text-muted small">- ไม่มีข้อมูล -</span>';

    // 💡 REVERSE ARRAYS: เพื่อให้สถานะล่าสุดอยู่บนสุด
    $steps = array_reverse($steps);

    // 💡 จัดให้อยู่ชิดซ้าย (align-items-start) เพื่อให้เข้ากับตัวหนังสือในคอลัมน์ของหน้า Visit
    $html = '<div class="journey-box d-flex flex-column align-items-center gap-1 mt-2">';
    foreach ($steps as $index => $step) {
        $html .= '<span class="j-step j-' . $step['type'] . ' text-center w-100" style="max-width: fit-content;">';
        $html .= '<i class="fa-solid ' . $step['icon'] . ' me-1"></i> ' . $step['label'];
        
        // แสดงวันที่ใต้ label
        if (!empty($step['datetime'])) {
            $formatted = date('d/m/Y H:i', strtotime($step['datetime']));
            $html .= '<br><small style="font-size: 0.7rem; opacity: 0.8;">' . $formatted . '</small>';
        }
        
        $html .= '</span>';
        
        if ($index < count($steps) - 1) {
            $html .= '<i class="fa-solid fa-arrow-up j-arrow my-1" style="font-size: 0.65rem; color: #cbd5e1;"></i>';
        }
    }
    $html .= '</div>';
    
    return $html;
}

// ---------------------------------------------------------------------------
// QUERY 1: D/C FROM ER
// ---------------------------------------------------------------------------
$sqlWait_Base = "SELECT
    c.id as case_id,
    c.hn,
    c.patient_name,
    c.age,
    c.phone,
    c.department,
    c.person_type,

    e.FstDx as er_dx,
    e.risk_factors,
    e.recorded_at as er_time,
    e.disposition as er_status,
    COALESCE(e.visit_unit, '') as visit_unit,

    -- นับการเยี่ยม (ER) - ผูกกับ case_id เพื่อกันนับปนกับ Ward
    (SELECT COUNT(*) 
     FROM home_visits hv 
     WHERE hv.hn = c.hn 
       AND (hv.an IS NULL OR hv.an = '')
       AND NOT EXISTS (
           SELECT 1 FROM ward_details wd 
           WHERE wd.case_id = c.id AND wd.an = hv.an
       )
    ) as visit_count_er

FROM cases c

LEFT JOIN er_details e 
    ON e.id = (
        SELECT MAX(id) 
        FROM er_details 
        WHERE case_id = c.id
    )

WHERE 
    e.id IS NOT NULL
    AND e.disposition = 'Discharge'

    -- กัน Dead / Refer (จริง ๆ Discharge ก็กันอยู่แล้ว แต่เผื่อ)
    AND IFNULL(e.disposition, '') NOT IN ('Dead', 'Refer')
    AND e.FstDx REGEXP 'Heat Stroke|Heat Exhaustion|Heat Syncope|Heat Cramps|Heat Tetany|Heat Edema|Prickly Heat|Rhabdomyolysis'
    $visit_unit_filter_er
    AND (
        SELECT COUNT(*) 
        FROM home_visits hv 
        WHERE hv.hn = c.hn 
          AND (hv.an IS NULL OR hv.an = '')
          AND NOT EXISTS (
              SELECT 1 FROM ward_details wd 
              WHERE wd.case_id = c.id AND wd.an = hv.an
          )
    ) < (
        CASE 
            WHEN COALESCE(e.FstDx, '') REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
            THEN 2
            ELSE 1
        END
    )

    $filterSQL_er
";

// นับจำนวนแถวทั้งหมดของ รอเยี่ยม
$countWaitQuery = $conn->query("SELECT COUNT(*) as t FROM ($sqlWait_Base) AS sub");
$totalWait = $countWaitQuery ? $countWaitQuery->fetch_assoc()['t'] : 0;
$w_totalPages = ceil($totalWait / $limit);

// ดึงข้อมูลตาม Limit และ Offset
$sqlWait = "$sqlWait_Base 
ORDER BY 
    e.recorded_at DESC
LIMIT $limit OFFSET $w_offset";
$resWait = $conn->query($sqlWait);

// ---------------------------------------------------------------------------
// QUERY 2: D/C FROM WARD
// ---------------------------------------------------------------------------
       $sqlWard_Base = "SELECT
        c.id as case_id,
        c.hn,
        c.patient_name,
        c.age,
        c.phone,
        c.department,
        c.person_type,

        w.an,
        w.admit_date,
        w.discharge_date,
        w.status as ward_status,
        w.admit_type as current_ward,
        w.discharge_status as ward_dc_status,

        w.icu_diagnosis,
        w.final_diagnosis,
        w.initial_diagnosis,
        COALESCE(w.visit_unit, '') as visit_unit,

        -- นับการเยี่ยม (Ward: กรอง AN เพื่อกันนับ visit ER ปน)
        (SELECT COUNT(*) 
        FROM home_visits hv 
        WHERE hv.hn = c.hn
          AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')
        ) as visit_count_ward

    FROM cases c

    LEFT JOIN ward_details w 
        ON w.id = (
            SELECT MAX(id) 
            FROM ward_details 
            WHERE case_id = c.id
        )

    WHERE 
        w.id IS NOT NULL

        AND w.status = 'Discharged'
        AND IFNULL(w.discharge_status, '') NOT IN ('Dead', 'Refer')
        AND COALESCE(w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis, '') 
        REGEXP 'Heat Stroke|Heat Exhaustion|Heat Syncope|Heat Cramps|Heat Tetany|Heat Edema|Prickly Heat|Rhabdomyolysis'
        $visit_unit_filter_ward
        AND (
            SELECT COUNT(*) 
            FROM home_visits hv 
            WHERE hv.hn = c.hn
              AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')
        ) < (
            CASE 
                WHEN COALESCE(w.final_diagnosis, w.icu_diagnosis, w.initial_diagnosis, '') REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
                THEN 2
                ELSE 1
            END
        )

        $filterSQL
    ";
    $countWardQuery = $conn->query("
                                    SELECT COUNT(*) as t 
                                    FROM ($sqlWard_Base) AS sub
                                ");

    $totalWard = $countWardQuery ? $countWardQuery->fetch_assoc()['t'] : 0;
    $ward_totalPages = ceil($totalWard / $limit);
    $sqlWard = "$sqlWard_Base 
        ORDER BY 
            w.discharge_date DESC
        LIMIT $limit OFFSET $w_offset";

$resWard = $conn->query($sqlWard);

// ---------------------------------------------------------------------------
// QUERY 3: ประวัติการเยี่ยม (HISTORY TAB)
// ---------------------------------------------------------------------------
$sqlHist_Base = "SELECT 
                c.id as case_id, c.hn, c.patient_name, c.age, c.phone, c.department, c.person_type,
                e.cause_factor, e.risk_factors, e.FstDx as er_dx, e.recorded_at as er_time, e.disposition as er_status,
                w.an, w.discharge_date, w.status as ward_status, w.admit_date,
                w.icu_diagnosis, w.final_diagnosis, w.initial_diagnosis,
                COALESCE(w.visit_unit, e.visit_unit, '') as visit_unit,
                (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) as visit_count_ward,
                0 as visit_count_er,
                -- ระบุ severity ใน query เพื่อใช้แสดงผล
                CASE 
                    WHEN COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                         REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
                    THEN 'Severe'
                    ELSE 'Moderate'
                END as severity_label
        FROM cases c
        LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
        LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = c.id)
        WHERE 
            (
                (w.id IS NOT NULL AND w.status = 'Discharged' AND IFNULL(w.discharge_status,'') NOT IN ('Dead','Refer'))
                OR
                (w.id IS NULL AND e.id IS NOT NULL AND e.disposition = 'Discharge')
            )
            AND (
                -- Moderate ครบ 1 ครั้ง: ต้องระบุ pattern ชัดเจนว่าเป็น Heat กลุ่มไหน
                (
                    COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                        REGEXP 'Heat Syncope|Heat Cramps|Heat Tetany|Heat Edema|Prickly Heat|Rhabdomyolysis \\(CPK < 1,000\\)'
                    AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) >= 1
                )
                OR
                -- Severe ครบ 2 ครั้ง: ยังคงเหมือนเดิม
                (
                    COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                        REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
                    AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) >= 2
                )
            )
            $visit_unit_filter
        $filterSQL";

// นับจำนวนแถวทั้งหมดของ ประวัติ
$countHistQuery = $conn->query("SELECT COUNT(*) as t FROM ($sqlHist_Base) AS sub");
$totalHist = $countHistQuery ? $countHistQuery->fetch_assoc()['t'] : 0;
$h_totalPages = ceil($totalHist / $limit);

// ดึงข้อมูลตาม Limit และ Offset
$sqlHist = "$sqlHist_Base ORDER BY COALESCE(w.discharge_date, e.recorded_at, c.created_at) DESC LIMIT $limit OFFSET $h_offset";
$resHist = $conn->query($sqlHist);

// ---------------------------------------------------------------------------
// KPI SUMMARY (นับตาม severity: Severe=2 ครั้ง, Moderate=1 ครั้ง)
// ---------------------------------------------------------------------------
// รอดำเนินการ = ยังไม่มีการเยี่ยมเลย (ทุก severity)
$kpi_wait_q = $conn->query("SELECT COUNT(*) as t FROM (
    SELECT c.id FROM cases c
    LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
    LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = c.id)
    WHERE (
        (w.id IS NOT NULL AND w.status = 'Discharged' AND IFNULL(w.discharge_status,'') NOT IN ('Dead','Refer'))
        OR (w.id IS NULL AND e.id IS NOT NULL AND e.disposition = 'Discharge')
    )AND COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '')
    REGEXP 'Heat Stroke|Heat Exhaustion|Heat Syncope|Heat Cramps|Heat Tetany|Heat Edema|Prickly Heat|Rhabdomyolysis'
    $visit_unit_filter
    AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) = 0
) AS sub");
$kpi_wait = $kpi_wait_q ? $kpi_wait_q->fetch_assoc()['t'] : 0;

// อยู่ระหว่างติดตาม = เยี่ยมแล้วแต่ยังไม่ครบตาม severity
// Moderate เยี่ยม 0 (อยู่ใน wait แล้ว), Severe เยี่ยม 1 ครั้ง
$kpi_tracked_q = $conn->query("SELECT COUNT(*) as t FROM (
    SELECT c.id FROM cases c
    LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
    LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = c.id)
    WHERE (
        (w.id IS NOT NULL AND w.status = 'Discharged' AND IFNULL(w.discharge_status,'') NOT IN ('Dead','Refer'))
        OR (w.id IS NULL AND e.id IS NOT NULL AND e.disposition = 'Discharge')
    )
    AND COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
        REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
    $visit_unit_filter
    AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) = 1
) AS sub");
$kpi_tracked = $kpi_tracked_q ? $kpi_tracked_q->fetch_assoc()['t'] : 0;

// สิ้นสุดการติดตาม = Severe ครบ 2 ครั้ง + Moderate ครบ 1 ครั้ง
$kpi_done_q = $conn->query("SELECT COUNT(*) as t FROM (
    SELECT c.id FROM cases c
    LEFT JOIN er_details e ON e.id = (SELECT MAX(id) FROM er_details WHERE case_id = c.id)
    LEFT JOIN ward_details w ON w.id = (SELECT MAX(id) FROM ward_details WHERE case_id = c.id)
    WHERE (
        (w.id IS NOT NULL AND w.status = 'Discharged' AND IFNULL(w.discharge_status,'') NOT IN ('Dead','Refer'))
        OR (w.id IS NULL AND e.id IS NOT NULL AND e.disposition = 'Discharge')
    )
    $visit_unit_filter
    AND (
        -- Severe ครบ 2 ครั้ง
        (
            COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                REGEXP 'Heat Stroke|Heat Exhaustion|Rhabdomyolysis \\\\(CPK > 1,000\\\\)'
            AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) >= 2
        )
        OR
        -- Moderate ครบ 1 ครั้ง
        (
            COALESCE(w.final_diagnosis, w.initial_diagnosis, e.FstDx, '') 
                REGEXP 'Heat Syncope|Heat Cramps|Heat Tetany|Heat Edema|Prickly Heat|Rhabdomyolysis \\\\(CPK < 1,000\\\\)'
            AND (SELECT COUNT(*) FROM home_visits hv WHERE hv.hn = c.hn AND (hv.an = w.an OR hv.an IS NULL OR hv.an = '')) >= 1
        )
    )
) AS sub");
$kpi_done = $kpi_done_q ? $kpi_done_q->fetch_assoc()['t'] : 0;

$kpi_total = $kpi_wait + $kpi_tracked + $kpi_done;
?>


<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทะเบียนผู้ป่วยเยี่ยมบ้าน (<?php echo $is_coc ? 'COC' : 'PCU'; ?>)</title>
    <link href="../bootstrap-5.0.2-dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --v-primary: #d6728d; 
            --v-primary-hover: #b95c76;
            --v-bg: #fdf5f7; 
            --v-light: #fae8ee;
            --v-text: #2c3e50; 
            --border-soft: #f2cad7;
        }
    </style>
    <link href="css/style.css" rel="stylesheet">
    <script>
        function alertNotDischarged(hn, statusText) {
            Swal.fire({
                icon: 'info',
                title: 'ยังรับเคสไม่ได้',
                text: 'ผู้ป่วย HN ' + hn + ' ' + statusText + ' กรุณารอแพทย์จำหน่าย (Discharge) ก่อน',
                confirmButtonColor: '#d6728d',
                confirmButtonText: 'รับทราบ'
            });
        }
    </script>
</head>
<body>

<?php 
    $path_prefix = "../"; 
    if(file_exists('../header_visit.php')) require_once '../header_visit.php'; 
    else echo "<div class='alert alert-warning'>ไม่พบไฟล์ header_visit.php</div>";
?>

<div class="container-fluid px-2 px-md-4 py-3 py-md-4" style="max-width: 1450px;">
    
    <div class="page-header">
        <div>
            <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-house-user me-2 text-theme"></i>ระบบติดตามเยี่ยมบ้าน <?php echo $is_coc ? '(COC)' : '(PCU)'; ?></h4>
            <small class="text-muted mt-2 d-block"><i class="fa-solid fa-user-nurse me-1"></i> ผู้ใช้งาน: <?php echo $is_coc ? 'COC' : 'PCU'; ?></small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span style="background-color: var(--v-primary); color: white;" class="badge rounded-pill px-4 py-2 fs-6 shadow-sm"><i class="fa-regular fa-calendar me-2"></i> <?php echo date('d M Y'); ?></span>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card kpi-card border-pink"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-users"></i></div><div><div class="kpi-label">ยอดผู้ป่วยที่ต้องดูแลทั้งหมด</div><div class="kpi-value"><?php echo $kpi_total; ?> <small>ราย</small></div></div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card border-danger"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi-label" style="color:#ef4444;">รอดำเนินการ</div><div class="kpi-value"><?php echo $kpi_wait; ?> <small>ราย</small></div></div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card border-warning"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3" style="background:#fffbeb;color:#f59e0b;"><i class="fa-solid fa-clock"></i></div><div><div class="kpi-label" style="color:#f59e0b;">อยู่ระหว่างติดตาม</div><div class="kpi-value"><?php echo $kpi_tracked; ?> <small>ราย</small></div></div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card border-success"><div class="card-body d-flex align-items-center"><div class="kpi-icon me-3" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-clipboard-check"></i></div><div><div class="kpi-label" style="color:#16a34a;">สิ้นสุดการติดตาม</div><div class="kpi-value"><?php echo $kpi_done; ?> <small>ราย</small></div></div></div></div></div>
    </div>

    <!-- กล่องค้นหา -->

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 15px;">
        <div class="card-body bg-white rounded" style="border-radius: 15px;">
            <form method="GET" action="">
                <!-- ส่งค่า Tab ปัจจุบันไปด้วยตอนค้นหา -->
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                <input type="hidden" name="visit_tab" value="<?php echo htmlspecialchars($visit_tab); ?>">
                <input type="hidden" name="coc_tab" value="<?php echo htmlspecialchars($coc_tab); ?>">
                
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted fw-bold">ค้นหา (ชื่อ, HN, AN)</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" name="search" class="form-control border-start-0" placeholder="..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted fw-bold">ตั้งแต่วันที่ (Admit/D/C)</label>
                        <input type="date" name="start_date" class="form-control shadow-sm" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted fw-bold">ถึงวันที่</label>
                        <input type="date" name="end_date" class="form-control shadow-sm" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-theme w-100 shadow-sm" style="height: 38px;"><i class="fa-solid fa-search me-1"></i> ค้นหา</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- หน่วยเยี่ยม Sub-tabs (PCU only: ทั้งหมด / PCU / CQI) -->
    <?php if ($is_pcu): ?>
    <div class="d-flex gap-2 mb-3 align-items-center">
        <span class="small fw-bold text-muted me-1"><i class="fa-solid fa-filter me-1"></i>หน่วยเยี่ยม:</span>
        <?php 
            $base_params = $_GET;
            unset($base_params['visit_tab']);
        ?>
        <a href="?<?php echo http_build_query(array_merge($base_params, ['visit_tab' => 'all'])); ?>" 
           class="btn btn-sm rounded-pill fw-bold <?php echo ($visit_tab === 'all') ? 'btn-theme text-white shadow-sm' : 'btn-outline-secondary'; ?>">
            <i class="fa-solid fa-layer-group me-1"></i> ทั้งหมด
        </a>
        <a href="?<?php echo http_build_query(array_merge($base_params, ['visit_tab' => 'pcu'])); ?>" 
           class="btn btn-sm rounded-pill fw-bold <?php echo ($visit_tab === 'pcu') ? 'btn-theme text-white shadow-sm' : 'btn-outline-secondary'; ?>">
            <i class="fa-solid fa-house-chimney-medical me-1"></i> PCU
        </a>
        <a href="?<?php echo http_build_query(array_merge($base_params, ['visit_tab' => 'cqi'])); ?>" 
           class="btn btn-sm rounded-pill fw-bold <?php echo ($visit_tab === 'cqi') ? 'btn-theme text-white shadow-sm' : 'btn-outline-secondary'; ?>">
            <i class="fa-solid fa-chart-line me-1"></i> CQI
        </a>
    </div>
    <?php endif; ?>

    <!-- หน่วยเยี่ยม Sub-tabs (COC: COC / ทั้งหมด) -->
    <?php if ($is_coc): ?>
    <div class="d-flex gap-2 mb-3 align-items-center">
        <span class="small fw-bold text-muted me-1"><i class="fa-solid fa-filter me-1"></i>หน่วยเยี่ยม:</span>
        <?php 
            $base_params_coc = $_GET;
            unset($base_params_coc['coc_tab']);
        ?>
        <a href="?<?php echo http_build_query(array_merge($base_params_coc, ['coc_tab' => 'coc'])); ?>" 
           class="btn btn-sm rounded-pill fw-bold <?php echo ($coc_tab === 'coc') ? 'btn-theme text-white shadow-sm' : 'btn-outline-secondary'; ?>">
            <i class="fa-solid fa-stethoscope me-1"></i> COC
        </a>
        <a href="?<?php echo http_build_query(array_merge($base_params_coc, ['coc_tab' => 'all'])); ?>" 
           class="btn btn-sm rounded-pill fw-bold <?php echo ($coc_tab === 'all') ? 'btn-theme text-white shadow-sm' : 'btn-outline-secondary'; ?>">
            <i class="fa-solid fa-layer-group me-1"></i> ทั้งหมด
        </a>
    </div>
    <?php endif; ?>

    <!-- แถบเมนู Tabs -->
    <ul class="nav nav-tabs mb-3 border-0 flex-nowrap overflow-auto" id="visitTab" role="tablist" style="-webkit-overflow-scrolling: touch; padding-bottom: 2px;">
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center text-nowrap <?php echo $active_tab == 'wait' ? 'active' : ''; ?>" 
            href="?tab=wait&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&visit_tab=<?php echo urlencode($visit_tab); ?>&coc_tab=<?php echo urlencode($coc_tab); ?>">
                <i class="fa-solid fa-clock me-2"></i>จำหน่ายจาก ER
                <?php if($totalWait > 0): ?>
                    <span class="badge bg-warning text-dark rounded-pill ms-2 shadow-sm">
                        <?php echo $totalWait; ?>
                    </span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center text-nowrap <?php echo $active_tab == 'ward' ? 'active' : ''; ?>" 
            href="?tab=ward&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&visit_tab=<?php echo urlencode($visit_tab); ?>&coc_tab=<?php echo urlencode($coc_tab); ?>">
                <i class="fa-solid fa-bed me-2"></i>จำหน่ายจาก Ward
                <?php if($totalWard > 0): ?>
                    <span class="badge bg-primary rounded-pill ms-2 shadow-sm">
                        <?php echo $totalWard; ?>
                    </span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center text-nowrap <?php echo $active_tab == 'hist' ? 'active' : ''; ?>" 
            href="?tab=hist&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&visit_tab=<?php echo urlencode($visit_tab); ?>&coc_tab=<?php echo urlencode($coc_tab); ?>">
                <i class="fa-solid fa-folder-open me-2"></i>ทะเบียน/ประวัติ 
                <?php if($totalHist > 0): ?>
                    <span class="badge bg-success rounded-pill ms-2 shadow-sm">
                        <?php echo $totalHist; ?>
                    </span>
                <?php endif; ?>
            </a>
        </li>

    </ul>

    <div class="tab-content" id="visitTabContent">
        
        <!-- 🔴 TAB 1: D/C ER -->
        <div class="tab-pane fade <?php echo $active_tab == 'wait' ? 'show active' : ''; ?>" id="wait" role="tabpanel">
            <div class="card card-visit">
                <div class="table-responsive">
                    <table class="table registry-table align-middle">
                        <thead>
                            <tr>
                                <th width="5%" class="text-center">#</th>
                                <th width="12%">HN / AN</th>
                                <th width="18%">ข้อมูลผู้ป่วย</th>
                                <th width="20%">วินิจฉัยและไทม์ไลน์</th>
                                <th width="20%">ผลการเยี่ยมบ้าน</th>
                                <th width="15%">สถานะผู้ป่วย</th>
                                <th width="15%" class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resWait && $resWait->num_rows > 0): ?>
                                <?php $rowNum = $w_offset + 1; ?>
                                <?php while($row = $resWait->fetch_assoc()): ?>
                                    <?php 
                                        $timelineHTML = getPatientTimeline($conn, $row['case_id']);
                                        $an_text = $row['an'] ?? '';
                                        
                                        // 💡 ดึงประวัติการเยี่ยม 1-3 ครั้ง 💡
                                        $eval_html = '<div class="d-flex flex-column gap-2">';
                                        $hn_safe = mysqli_real_escape_string($conn, $row['hn']);
                                        $an_safe = mysqli_real_escape_string($conn, $an_text);
                                        
                                        if (!empty($an_safe)) {
                                            $qVisits = $conn->query("SELECT * FROM home_visits WHERE an = '$an_safe' ORDER BY record_datetime ASC");
                                        } else {
                                            $qVisits = $conn->query("SELECT * FROM home_visits WHERE hn = '$hn_safe' AND (an IS NULL OR an = '') ORDER BY record_datetime ASC");
                                        }

                                        if($qVisits && $qVisits->num_rows > 0) {
                                            $v_c = 1;
                                            while($v = $qVisits->fetch_assoc()) {
                                                $temp = !empty($v['body_temperature']) ? $v['body_temperature']." °C" : '-';
                                                $urine = !empty($v['urine_color']) ? htmlspecialchars($v['urine_color']) : '-';
                                                $symp = !empty($v['symptoms']) ? htmlspecialchars($v['symptoms']) : '-';
                                                $v_date = date('d/m/y', strtotime($v['record_datetime']));
                                                $symp_color = ($symp == 'ไม่มีอาการผิดปกติ') ? '#15803d' : '#dc2626';

                                                $eval_html .= "<div class='small pb-2 border-bottom border-light' style='line-height: 1.5;'>";
                                                $eval_html .= "<strong class='text-theme'>ครั้งที่ {$v_c} ({$v_date}):</strong><br>";
                                                $eval_html .= "<span class='text-muted'>อาการ:</span> <span style='color: {$symp_color};'>{$symp}</span> | ";
                                                $eval_html .= "<span class='text-muted'>Temp:</span> <span class='text-danger'>{$temp}</span><br>";
                                                $eval_html .= "<span class='text-muted'>สีปัสสาวะ:</span> <strong>{$urine}</strong>";
                                                $eval_html .= "</div>";
                                                $v_c++;
                                            }
                                        } else {
                                            $eval_html .= "<span class='text-muted small'>- ยังไม่มีข้อมูลประเมิน -</span>";
                                        }
                                        $eval_html .= '</div>';

                                        // 💡 สถานะ Location และการล็อกปุ่มเยี่ยมบ้าน 💡
                                        $v_count = (int)$row['visit_count_er'];

                                        $loc_badge = "";
                                        $is_dc = false;
                                        $alert_text = "";
                                        $lock_btn_text = "รอ D/C จาก ER";

                                        // เช็คสถานะ ER เท่านั้น
                                        if (isset($row['er_status']) && $row['er_status'] == 'Discharge') {
                                            $is_dc = true;
                                            $loc_badge = '<div class="st-badge st-dc mb-1 w-100">
                                                <i class="fa-solid fa-house-circle-check me-1"></i> D/C กลับบ้าน (จาก ER)
                                            </div>';
                                        } else {
                                            $alert_text = "ยังอยู่แผนก ER";
                                            $lock_btn_text = "รอ D/C จาก ER";
                                            $loc_badge = '<div class="st-badge st-er mb-1 w-100">
                                                <i class="fa-solid fa-truck-medical me-1"></i> อยู่แผนก ER
                                            </div>';
                                        }

                                        // หา dx จากข้อมูลแถวนั้น
                                        $dx_for_severity = $row['final_diagnosis'] 
                                            ?? $row['icu_diagnosis'] 
                                            ?? $row['er_dx'] 
                                            ?? '';

                                        $severity   = getSeverity($dx_for_severity);
                                        $max_visits = getMaxVisits($severity);

                                        $visit_badge = "";
                                        $btn_text = "";

                                        if ($is_dc) {
                                            if ($v_count == 0) {
                                                $visit_badge = '<div class="st-badge bg-danger text-white shake-alert w-100"><i class="fa-solid fa-house-chimney-medical me-1"></i> รอเยี่ยมครั้งแรก</div>';
                                                $btn_text = "บันทึก (1/{$max_visits})";
                                            } elseif ($v_count < $max_visits) {
                                                $visit_badge = '<div class="st-badge bg-warning text-dark shake-alert w-100"><i class="fa-solid fa-house-chimney-medical me-1"></i> รอเยี่ยมครั้งที่ ' . ($v_count + 1) . '</div>';
                                                $btn_text = "บันทึก (" . ($v_count + 1) . "/{$max_visits})";
                                            } else {
                                                $visit_badge = '<div class="st-badge bg-success text-white w-100"><i class="fa-regular fa-calendar-check me-1"></i> ครบ ' . $max_visits . ' ครั้ง</div>';
                                                $btn_text = "ดูประวัติ";
                                            }
                                        } else {
                                            $visit_badge = '<div class="st-badge bg-secondary text-white w-100"><i class="fa-solid fa-user-clock me-1"></i> ยังไม่เริ่มเยี่ยม</div>';
                                        }

                                        $due_badge = $is_dc ? getVisitDueBadge($row['er_time'], $v_count, $severity) : '';
                                        $status_badge = $loc_badge . $visit_badge . $due_badge;
                                    ?>
                                    <tr>
                                        <td data-label="#">
                                            <div class="row-num"><?php echo $rowNum++; ?></div>
                                        </td>
                                        <td data-label="HN / AN">
                                            <div class="hn-chip">HN: <?php echo htmlspecialchars($row['hn']); ?></div>
                                            <?php if(!empty($an_text)): ?>
                                                <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #475569;">AN: <?php echo htmlspecialchars($an_text); ?></div>
                                            <?php else: ?>
                                                <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #9ca3af;">AN: -</div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="ข้อมูลผู้ป่วย">
                                            <div class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></div>
                                            <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['person_type']); ?></div>
                                            <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars($row['department'] ?? '-'); ?></div>
                                            <?php
                                                $vu = $row['visit_unit'] ?? '';
                                                if ($vu === 'PCU') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;font-size:0.7rem;"><i class="fa-solid fa-house-chimney-medical me-1"></i> หน่วยเยี่ยม: PCU</div>';
                                                } elseif ($vu === 'CQI') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;font-size:0.7rem;"><i class="fa-solid fa-chart-line me-1"></i> หน่วยเยี่ยม: CQI</div>';
                                                } elseif ($vu === 'COC') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74;font-size:0.7rem;"><i class="fa-solid fa-stethoscope me-1"></i> หน่วยเยี่ยม: COC</div>';
                                                } else {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;font-size:0.7rem;"><i class="fa-regular fa-circle-question me-1"></i> ยังไม่ระบุหน่วย</div>';
                                                }
                                            ?>
                                        </td>
                                        <td data-label="วินิจฉัยและไทม์ไลน์" class="align-middle">
                                            <div class="d-flex flex-column gap-1 mb-2">
                                                <?php if(!empty($row['final_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['final_diagnosis']); ?>"><strong class="text-primary">WARD:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['final_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['er_dx'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['er_dx']); ?>"><strong class="text-danger">ER:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['er_dx'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['icu_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['icu_diagnosis']); ?>"><strong class="text-warning" style="color: #b45309 !important;">ICU:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['icu_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo $timelineHTML; ?>
                                        </td>
                                        <td data-label="ผลการเยี่ยมบ้าน">
                                            <?php echo $eval_html; ?>
                                        </td>
                                        <td data-label="สถานะผู้ป่วย">
                                            <div class="d-flex flex-column gap-1 align-items-start">
                                                <?php echo $status_badge; ?>
                                            </div>
                                        </td>
                                        <td class="text-center" data-label="จัดการ">
                                            <?php if ($is_dc): ?>
                                                <?php 
                                                    $visit_url = "formVisit.php?hn=" . urlencode($row['hn']); 
                                                ?>
                                                <a href="<?php echo htmlspecialchars($visit_url); ?>" class="btn btn-theme text-white btn-sm rounded-pill px-3 shadow-sm fw-bold">
                                                    <i class="fa-solid fa-house-medical-circle-check me-1"></i> <?php echo $btn_text; ?>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-light border btn-sm rounded-pill px-3 shadow-sm text-muted fw-bold" 
                                                        onclick="alertNotDischarged('<?php echo htmlspecialchars($row['hn']); ?>', '<?php echo htmlspecialchars($alert_text); ?>')">
                                                    <i class="fa-solid fa-lock me-1"></i> <?php echo $lock_btn_text; ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fa-solid fa-house-chimney-medical fa-3x mb-3 opacity-25"></i><br>ไม่พบข้อมูลผู้ป่วยที่ต้องเยี่ยมในระบบ</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination สำหรับ TAB WAIT -->
                <?php if ($w_totalPages > 1): ?>
                <div class="p-3 border-top bg-light d-flex flex-column flex-md-row justify-content-between align-items-center gap-2" style="border-radius: 0 0 15px 15px;">
                    <span class="small text-muted fw-bold">แสดง <?= ($w_offset + 1) ?> ถึง <?= min($w_offset + $limit, $totalWait) ?> จาก <?= number_format($totalWait) ?> รายการ</span>
                    <div class="btn-group shadow-sm">
                        <a href="?tab=wait&w_page=<?= max(1, $w_page - 1) ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&visit_tab=<?= urlencode($visit_tab) ?>&coc_tab=<?= urlencode($coc_tab) ?>" class="btn btn-outline-theme btn-sm <?= $w_page <= 1 ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-left me-1"></i> ย้อนกลับ
                        </a>
                        <span class="btn btn-outline-theme btn-sm disabled bg-white">หน้า <?= $w_page ?> / <?= $w_totalPages ?></span>
                        <a href="?tab=wait&w_page=<?= min($w_totalPages, $w_page + 1) ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&visit_tab=<?= urlencode($visit_tab) ?>&coc_tab=<?= urlencode($coc_tab) ?>" class="btn btn-outline-theme btn-sm <?= $w_page >= $w_totalPages ? 'disabled' : '' ?>">
                            ถัดไป <i class="fa-solid fa-chevron-right ms-1"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 🔴 TAB 2: D/C ward -->
        <div class="tab-pane fade <?php echo $active_tab == 'ward' ? 'show active' : ''; ?>" id="ward" role="tabpanel">
            <div class="card card-visit">
                <div class="table-responsive">
                    <table class="table registry-table align-middle">
                        <thead>
                            <tr>
                                <th width="5%" class="text-center">#</th>
                                <th width="12%">HN / AN</th>
                                <th width="18%">ข้อมูลผู้ป่วย</th>
                                <th width="20%">วินิจฉัยและไทม์ไลน์</th>
                                <th width="20%">ผลการเยี่ยมบ้าน</th>
                                <th width="15%">สถานะผู้ป่วย</th>
                                <th width="15%" class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resWard && $resWard->num_rows > 0): ?>
                                <?php $rowNum = $w_offset + 1; ?>
                                <?php while($row = $resWard->fetch_assoc()): ?>
                                    <?php 
                                        $timelineHTML = getPatientTimeline($conn, $row['case_id']);
                                        $an_text = $row['an'] ?? '';
                                        
                                        // 💡 ดึงประวัติการเยี่ยม 1-3 ครั้ง 💡
                                        $eval_html = '<div class="d-flex flex-column gap-2">';
                                        $hn_safe = mysqli_real_escape_string($conn, $row['hn']);
                                        $an_safe = mysqli_real_escape_string($conn, $an_text);
                                        
                                        if (!empty($an_safe)) {
                                            $qVisits = $conn->query("SELECT * FROM home_visits WHERE an = '$an_safe' ORDER BY record_datetime ASC");
                                        } else {
                                            $qVisits = $conn->query("SELECT * FROM home_visits WHERE hn = '$hn_safe' AND (an IS NULL OR an = '') ORDER BY record_datetime ASC");
                                        }

                                        if($qVisits && $qVisits->num_rows > 0) {
                                            $v_c = 1;
                                            while($v = $qVisits->fetch_assoc()) {
                                                $temp = !empty($v['body_temperature']) ? $v['body_temperature']." °C" : '-';
                                                $urine = !empty($v['urine_color']) ? htmlspecialchars($v['urine_color']) : '-';
                                                $symp = !empty($v['symptoms']) ? htmlspecialchars($v['symptoms']) : '-';
                                                $v_date = date('d/m/y', strtotime($v['record_datetime']));
                                                $symp_color = ($symp == 'ไม่มีอาการผิดปกติ') ? '#15803d' : '#dc2626';

                                                $eval_html .= "<div class='small pb-2 border-bottom border-light' style='line-height: 1.5;'>";
                                                $eval_html .= "<strong class='text-theme'>ครั้งที่ {$v_c} ({$v_date}):</strong><br>";
                                                $eval_html .= "<span class='text-muted'>อาการ:</span> <span style='color: {$symp_color};'>{$symp}</span> | ";
                                                $eval_html .= "<span class='text-muted'>Temp:</span> <span class='text-danger'>{$temp}</span><br>";
                                                $eval_html .= "<span class='text-muted'>สีปัสสาวะ:</span> <strong>{$urine}</strong>";
                                                $eval_html .= "</div>";
                                                $v_c++;
                                            }
                                        } else {
                                            $eval_html .= "<span class='text-muted small'>- ยังไม่มีข้อมูลประเมิน -</span>";
                                        }
                                        $eval_html .= '</div>';

                                        // 💡 สถานะ Location และการล็อกปุ่มเยี่ยมบ้าน 💡
                                        // Ward ใช้ AN เป็นหลัก ถ้าไม่มี AN fallback ไป ER count
                                        $v_count = !empty($an_text) ? (int)$row['visit_count_ward'] : (int)$row['visit_count_er'];
                                        $loc_badge = "";
                                        $is_dc = false;
                                        $alert_text = "";
                                        $lock_btn_text = "รอ D/C";

                                        if (!empty($row['ward_status'])) {
                                            if ($row['ward_status'] == 'Discharged') {
                                                $is_dc = true;
                                                $loc_badge = '<div class="st-badge st-dc mb-1 w-100"><i class="fa-solid fa-house-circle-check me-1"></i> D/C กลับบ้าน (' . $row['current_ward'] . ')</div>';
                                            } else {
                                                $alert_text = "กำลังรักษาในแผนก " . $row['current_ward'];
                                                $lock_btn_text = "รอ D/C แผนก";
                                                $loc_badge = '<div class="st-badge st-admit mb-1 w-100"><i class="fa-solid fa-bed-pulse me-1"></i> รักษาในแผนก ' . $row['current_ward'] . '</div>';
                                            }
                                        } else {
                                            if (isset($row['er_status']) && $row['er_status'] == 'Discharge') {
                                                $is_dc = true;
                                                $loc_badge = '<div class="st-badge st-dc mb-1 w-100"><i class="fa-solid fa-house-circle-check me-1"></i> D/C กลับบ้าน (จาก ER)</div>';
                                            } else {
                                                $alert_text = "ยังอยู่แผนก ER";
                                                $lock_btn_text = "รอ D/C จาก ER";
                                                $loc_badge = '<div class="st-badge st-er mb-1 w-100"><i class="fa-solid fa-truck-medical me-1"></i> อยู่แผนก ER</div>';
                                            }
                                        }

                                        // หา dx จากข้อมูลแถวนั้น
                                        $dx_for_severity = $row['final_diagnosis'] 
                                            ?? $row['icu_diagnosis'] 
                                            ?? $row['er_dx'] 
                                            ?? '';

                                        $severity   = getSeverity($dx_for_severity);
                                        $max_visits = getMaxVisits($severity);

                                        $visit_badge = "";
                                        $btn_text = "";

                                        if ($is_dc) {
                                            if ($v_count == 0) {
                                                $visit_badge = '<div class="st-badge bg-danger text-white shake-alert w-100"><i class="fa-solid fa-house-chimney-medical me-1"></i> รอเยี่ยมครั้งแรก</div>';
                                                $btn_text = "บันทึก (1/{$max_visits})";
                                            } elseif ($v_count < $max_visits) {
                                                $visit_badge = '<div class="st-badge bg-warning text-dark shake-alert w-100"><i class="fa-solid fa-house-chimney-medical me-1"></i> รอเยี่ยมครั้งที่ ' . ($v_count + 1) . '</div>';
                                                $btn_text = "บันทึก (" . ($v_count + 1) . "/{$max_visits})";
                                            } else {
                                                $visit_badge = '<div class="st-badge bg-success text-white w-100"><i class="fa-regular fa-calendar-check me-1"></i> ครบ ' . $max_visits . ' ครั้ง</div>';
                                                $btn_text = "ดูประวัติ";
                                            }
                                        } else {
                                            $visit_badge = '<div class="st-badge bg-secondary text-white w-100"><i class="fa-solid fa-user-clock me-1"></i> ยังไม่เริ่มเยี่ยม</div>';
                                        }

                                        $dc_for_badge = !empty($row['discharge_date']) ? $row['discharge_date'] : ($row['er_time'] ?? '');
                                        $due_badge = $is_dc ? getVisitDueBadge($dc_for_badge, $v_count, $severity) : '';
                                        $status_badge = $loc_badge . $visit_badge . $due_badge;
                                    ?>
                                    <tr>
                                        <td data-label="#">
                                            <div class="row-num"><?php echo $rowNum++; ?></div>
                                        </td>
                                        <td data-label="HN / AN">
                                            <div class="hn-chip">HN: <?php echo htmlspecialchars($row['hn']); ?></div>
                                            <?php if(!empty($an_text)): ?>
                                                <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #475569;">AN: <?php echo htmlspecialchars($an_text); ?></div>
                                            <?php else: ?>
                                                <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #9ca3af;">AN: -</div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="ข้อมูลผู้ป่วย">
                                            <div class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></div>
                                            <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['person_type']); ?></div>
                                            <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars($row['department'] ?? '-'); ?></div>
                                            <?php
                                                $vu = $row['visit_unit'] ?? '';
                                                if ($vu === 'PCU') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;font-size:0.7rem;"><i class="fa-solid fa-house-chimney-medical me-1"></i> หน่วยเยี่ยม: PCU</div>';
                                                } elseif ($vu === 'CQI') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;font-size:0.7rem;"><i class="fa-solid fa-chart-line me-1"></i> หน่วยเยี่ยม: CQI</div>';
                                                } elseif ($vu === 'COC') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74;font-size:0.7rem;"><i class="fa-solid fa-stethoscope me-1"></i> หน่วยเยี่ยม: COC</div>';
                                                } else {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;font-size:0.7rem;"><i class="fa-regular fa-circle-question me-1"></i> ยังไม่ระบุหน่วยเยี่ยม</div>';
                                                }
                                            ?>
                                        </td>
                                        <td data-label="วินิจฉัยและไทม์ไลน์" class="align-middle">
                                            <div class="d-flex flex-column gap-1 mb-2">
                                                <?php if(!empty($row['final_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['final_diagnosis']); ?>"><strong class="text-primary">WARD:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['final_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['er_dx'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['er_dx']); ?>"><strong class="text-danger">ER:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['er_dx'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['icu_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['icu_diagnosis']); ?>"><strong class="text-warning" style="color: #b45309 !important;">ICU:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['icu_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo $timelineHTML; ?>
                                        </td>
                                        <td data-label="ผลการเยี่ยมบ้าน">
                                            <?php echo $eval_html; ?>
                                        </td>
                                        <td data-label="สถานะผู้ป่วย">
                                            <div class="d-flex flex-column gap-1 align-items-start">
                                                <?php echo $status_badge; ?>
                                            </div>
                                        </td>
                                        <td class="text-center" data-label="จัดการ">
                                            <?php if ($is_dc): ?>
                                                <?php 
                                                    $visit_url = "formVisit.php?hn=" . urlencode($row['hn']); 
                                                    
                                                ?>
                                                <a href="<?php echo htmlspecialchars($visit_url); ?>" class="btn btn-theme text-white btn-sm rounded-pill px-3 shadow-sm fw-bold">
                                                    <i class="fa-solid fa-house-medical-circle-check me-1"></i> <?php echo $btn_text; ?>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-light border btn-sm rounded-pill px-3 shadow-sm text-muted fw-bold" 
                                                        onclick="alertNotDischarged('<?php echo htmlspecialchars($row['hn']); ?>', '<?php echo htmlspecialchars($alert_text); ?>')">
                                                    <i class="fa-solid fa-lock me-1"></i> <?php echo $lock_btn_text; ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fa-solid fa-house-chimney-medical fa-3x mb-3 opacity-25"></i><br>ไม่พบข้อมูลผู้ป่วยที่ต้องเยี่ยมในระบบ</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination สำหรับ TAB WAIT -->
                <?php if ($w_totalPages > 1): ?>
                <div class="p-3 border-top bg-light d-flex flex-column flex-md-row justify-content-between align-items-center gap-2" style="border-radius: 0 0 15px 15px;">
                    <span class="small text-muted fw-bold">แสดง <?= ($w_offset + 1) ?> ถึง <?= min($w_offset + $limit, $totalWait) ?> จาก <?= number_format($totalWait) ?> รายการ</span>
                    <div class="btn-group shadow-sm">
                        <a href="?tab=wait&w_page=<?= max(1, $w_page - 1) ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&visit_tab=<?= urlencode($visit_tab) ?>&coc_tab=<?= urlencode($coc_tab) ?>" class="btn btn-outline-theme btn-sm <?= $w_page <= 1 ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-left me-1"></i> ย้อนกลับ
                        </a>
                        <span class="btn btn-outline-theme btn-sm disabled bg-white">หน้า <?= $w_page ?> / <?= $w_totalPages ?></span>
                        <a href="?tab=wait&w_page=<?= min($w_totalPages, $w_page + 1) ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&visit_tab=<?= urlencode($visit_tab) ?>&coc_tab=<?= urlencode($coc_tab) ?>" class="btn btn-outline-theme btn-sm <?= $w_page >= $w_totalPages ? 'disabled' : '' ?>">
                            ถัดไป <i class="fa-solid fa-chevron-right ms-1"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 🟢 TAB 3: HIST (ทะเบียน/ประวัติเดิม) -->
        <div class="tab-pane fade <?php echo $active_tab == 'hist' ? 'show active' : ''; ?>" id="hist" role="tabpanel">
            <div class="card card-visit">
                <div class="table-responsive">
                    <table class="table registry-table align-middle">
                        <thead>
                            <tr>
                                <th width="5%" class="text-center">#</th>
                                <th width="12%">HN / AN</th>
                                <th width="18%">ข้อมูลผู้ป่วย</th>
                                <th width="20%">วินิจฉัยและไทม์ไลน์</th>
                                <th width="25%">ผลการเยี่ยมบ้าน</th>
                                <th width="10%">สถานะล่าสุด</th>
                                <th width="10%" class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resHist && $resHist->num_rows > 0): ?>
                                <?php $rowNumHist = $h_offset + 1; ?>
                                <?php while($row = $resHist->fetch_assoc()): ?>
                                    <?php 
                                        $timelineHTML = getPatientTimeline($conn, $row['case_id']);
                                        $an_text = $row['an'] ?? '';
                                        $v_count = (int)$row['visit_count_ward'];
                                        $row_severity = $row['severity_label'] ?? 'Moderate';
                                        $row_max = ($row_severity === 'Severe') ? 2 : 1;

                                        // Severity badge
                                        $sev_badge = ($row_severity === 'Severe')
                                            ? '<div class="st-badge bg-danger text-white w-100 mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Severe</div>'
                                            : '<div class="st-badge bg-warning text-dark w-100 mb-1"><i class="fa-solid fa-circle-exclamation me-1"></i> Moderate</div>';

                                        if ($v_count == 0) {
                                            $hist_badge = '<div class="st-badge bg-secondary text-white shadow-sm mt-1 w-100"><i class="fa-solid fa-house-chimney-medical me-1"></i> ยังไม่มีการเยี่ยม</div>';
                                        } elseif ($v_count < $row_max) {
                                            $hist_badge = '<div class="st-badge bg-warning text-dark shadow-sm mt-1 w-100"><i class="fa-solid fa-house-chimney-medical me-1"></i> เยี่ยมแล้ว '.$v_count.'/'.$row_max.' ครั้ง</div>';
                                        } else {
                                            $hist_badge = '<div class="st-badge bg-success text-white shadow-sm mt-1 w-100"><i class="fa-solid fa-check-double me-1"></i> สิ้นสุดการเยี่ยม (ครบ '.$row_max.' ครั้ง)</div>';
                                        }

                                        // 💡 ดึงประวัติการเยี่ยม (History: ใช้ hn เสมอ) 💡
                                        $eval_html = '<div class="d-flex flex-column gap-2">';
                                        $hn_safe = mysqli_real_escape_string($conn, $row['hn']);
                                        $qVisits = $conn->query("SELECT * FROM home_visits WHERE hn = '$hn_safe' ORDER BY record_datetime ASC");

                                        if($qVisits && $qVisits->num_rows > 0) {
                                            $v_c = 1;
                                            while($v = $qVisits->fetch_assoc()) {
                                                $temp = !empty($v['body_temperature']) ? $v['body_temperature']." °C" : '-';
                                                $urine = !empty($v['urine_color']) ? htmlspecialchars($v['urine_color']) : '-';
                                                $symp = !empty($v['symptoms']) ? htmlspecialchars($v['symptoms']) : '-';
                                                $v_date = date('d/m/y', strtotime($v['record_datetime']));
                                                $symp_color = ($symp == 'ไม่มีอาการผิดปกติ') ? '#15803d' : '#dc2626';

                                                $eval_html .= "<div class='small pb-2 border-bottom border-light' style='line-height: 1.5;'>";
                                                $eval_html .= "<strong class='text-theme'>ครั้งที่ {$v_c} ({$v_date}):</strong><br>";
                                                $eval_html .= "<span class='text-muted'>อาการ:</span> <span style='color: {$symp_color};'>{$symp}</span> | ";
                                                $eval_html .= "<span class='text-muted'>Temp:</span> <span class='text-danger'>{$temp}</span><br>";
                                                $eval_html .= "<span class='text-muted'>สีปัสสาวะ:</span> <strong>{$urine}</strong>";
                                                $eval_html .= "</div>";
                                                $v_c++;
                                            }
                                        } else {
                                            $eval_html .= "<span class='text-muted small'>- ยังไม่มีข้อมูลประเมิน -</span>";
                                        }
                                        $eval_html .= '</div>';
                                    ?>
                                    <tr>
                                        <td data-label="#">
                                            <div class="row-num"><?php echo $rowNumHist++; ?></div>
                                        </td>
                                        <td data-label="HN / AN">
                                            <div class="hn-chip">HN: <?php echo htmlspecialchars($row['hn']); ?></div>
                                            <?php if(!empty($an_text)): ?>
                                                <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #475569;">AN: <?php echo htmlspecialchars($an_text); ?></div>
                                            <?php else: ?>
                                                <div class="hn-chip" style="background: #f8fafc; border-color: #e2e8f0; color: #9ca3af;">AN: -</div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="ข้อมูลผู้ป่วย">
                                            <div class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></div>
                                            <div class="patient-type"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['person_type']); ?></div>
                                            <div class="dept-text"><i class="fa-regular fa-building me-1"></i><?php echo htmlspecialchars($row['department'] ?? '-'); ?></div>
                                            <?php
                                                $vu = $row['visit_unit'] ?? '';
                                                if ($vu === 'PCU') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;font-size:0.7rem;"><i class="fa-solid fa-house-chimney-medical me-1"></i> หน่วยที่เยี่ยม: PCU</div>';
                                                } elseif ($vu === 'CQI') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;font-size:0.7rem;"><i class="fa-solid fa-chart-line me-1"></i> หน่วยที่เยี่ยม: CQI</div>';
                                                } elseif ($vu === 'COC') {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74;font-size:0.7rem;"><i class="fa-solid fa-stethoscope me-1"></i> หน่วยที่เยี่ยม: COC</div>';
                                                } else {
                                                    echo '<div class="st-badge mt-1 w-100" style="background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;font-size:0.7rem;"><i class="fa-regular fa-circle-question me-1"></i> ยังไม่ได้ระบุหน่วย</div>';
                                                }
                                            ?>
                                        </td>
                                        <td data-label="วินิจฉัยแรกรับและไทม์ไลน์" class="align-middle">
                                            <div class="d-flex flex-column gap-1 mb-2">
                                                <?php if(!empty($row['final_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['final_diagnosis']); ?>"><strong class="text-primary">WARD:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['final_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['er_dx'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['er_dx']); ?>"><strong class="text-danger">ER:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['er_dx'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($row['icu_diagnosis'])): ?>
                                                    <div class="er-dx-text" title="<?php echo htmlspecialchars($row['icu_diagnosis']); ?>"><strong class="text-warning" style="color: #b45309 !important;">ICU:</strong> <?php echo htmlspecialchars(mb_strimwidth($row['icu_diagnosis'], 0, 30, '...')); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo $timelineHTML; ?>
                                        </td>
                                        <td data-label="ผลการเยี่ยมบ้าน">
                                            <?php echo $eval_html; ?>
                                        </td>
                                        <td data-label="สถานะล่าสุด">
                                            <div>
                                                <?php echo $sev_badge; ?>
                                                <?php echo $hist_badge; ?>
                                            </div>
                                        </td>
                                        <td class="text-center" data-label="จัดการ">
                                            <?php 
                                                $hist_url = "../view_patient_full_history.php?hn=" . urlencode($row['hn']);
                                            ?>
                                            <a href="<?php echo htmlspecialchars($hist_url); ?>" class="btn btn-outline-theme btn-sm rounded-pill px-3 shadow-sm w-100">
                                                <i class="fa-solid fa-folder-open me-1"></i> ดูประวัติ
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i><br>ยังไม่มีประวัติการเยี่ยมบ้านที่เสร็จสมบูรณ์</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination สำหรับ TAB HISTORY -->
                <?php if ($h_totalPages > 1): ?>
                <div class="p-3 border-top bg-light d-flex flex-column flex-md-row justify-content-between align-items-center gap-2" style="border-radius: 0 0 15px 15px;">
                    <span class="small text-muted fw-bold">แสดง <?= ($h_offset + 1) ?> ถึง <?= min($h_offset + $limit, $totalHist) ?> จาก <?= number_format($totalHist) ?> รายการ</span>
                    <div class="btn-group shadow-sm">
                        <a href="?tab=hist&h_page=<?= max(1, $h_page - 1) ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&visit_tab=<?= urlencode($visit_tab) ?>&coc_tab=<?= urlencode($coc_tab) ?>" class="btn btn-outline-theme btn-sm <?= $h_page <= 1 ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-left me-1"></i> ย้อนกลับ
                        </a>
                        <span class="btn btn-outline-theme btn-sm disabled bg-white">หน้า <?= $h_page ?> / <?= $h_totalPages ?></span>
                        <a href="?tab=hist&h_page=<?= min($h_totalPages, $h_page + 1) ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&visit_tab=<?= urlencode($visit_tab) ?>&coc_tab=<?= urlencode($coc_tab) ?>" class="btn btn-outline-theme btn-sm <?= $h_page >= $h_totalPages ? 'disabled' : '' ?>">
                            ถัดไป <i class="fa-solid fa-chevron-right ms-1"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
</body>
<?php if(file_exists('../footer.php')) require_once '../footer.php'; ?>
</html>