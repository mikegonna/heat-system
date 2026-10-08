<?php

function resolveCaseForNewHNEntry(mysqli $conn, string $hn, bool $allowWardMerge = false): int
{
    $hn = trim($hn);

    if ($allowWardMerge) {
        $stmt = $conn->prepare("SELECT c.id
                                FROM cases c
                                INNER JOIN ward_details w ON w.case_id = c.id
                                WHERE TRIM(c.hn) = ?
                                  AND w.status IN ('Active', 'Admitted', 'AdmitWait')
                                ORDER BY c.id DESC, w.id DESC
                                LIMIT 1");
        $stmt->bind_param("s", $hn);
        $stmt->execute();
        $activeWardCase = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($activeWardCase) {
            $activeWardCaseId = (int)$activeWardCase['id'];
            $stmt = $conn->prepare("SELECT id FROM er_details WHERE case_id = ?
                                    UNION ALL
                                    SELECT id FROM gp_details WHERE case_id = ?
                                    LIMIT 1");
            $stmt->bind_param("ii", $activeWardCaseId, $activeWardCaseId);
            $stmt->execute();
            $hasErOrGpRecord = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$hasErOrGpRecord) {
                return $activeWardCaseId;
            }

            throw new RuntimeException("HN นี้มีเคสที่ยังรักษาตัวอยู่ใน Ward และมีข้อมูล ER/GP แล้ว ไม่สามารถเปิดเคสซ้ำได้");
        }
    }

    $stmt = $conn->prepare("SELECT id FROM cases WHERE hn = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("s", $hn);
    $stmt->execute();
    $latestCase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$latestCase) {
        return 0;
    }

    $caseId = (int)$latestCase['id'];

    $stmt = $conn->prepare("SELECT id, status, discharge_status, discharge_date
                            FROM ward_details
                            WHERE case_id = ?
                            ORDER BY id DESC
                            LIMIT 1");
    $stmt->bind_param("i", $caseId);
    $stmt->execute();
    $latestWard = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM er_details WHERE case_id = ?
                            UNION ALL
                            SELECT id FROM gp_details WHERE case_id = ?
                            LIMIT 1");
    $stmt->bind_param("ii", $caseId, $caseId);
    $stmt->execute();
    $hasErOrGpRecord = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($latestWard && in_array($latestWard['status'], ['Active', 'Admitted', 'AdmitWait'], true)) {
        if ($allowWardMerge && !$hasErOrGpRecord) {
            return $caseId;
        }

        throw new RuntimeException("HN นี้มีเคสที่ยังรักษาตัวอยู่ใน Ward ไม่สามารถเปิดเคสซ้ำได้");
    }

    if (
        $latestWard
        && $latestWard['discharge_status'] === 'Discharge'
        && $latestWard['status'] === 'Discharge'
    ) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT disposition FROM (
                                SELECT disposition, recorded_at, id FROM er_details WHERE case_id = ?
                                UNION ALL
                                SELECT disposition, recorded_at, id FROM gp_details WHERE case_id = ?
                            ) AS visit_records
                            ORDER BY recorded_at DESC, id DESC
                            LIMIT 1");
    $stmt->bind_param("ii", $caseId, $caseId);
    $stmt->execute();
    $latestDisposition = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($latestDisposition && $latestDisposition['disposition'] === 'Discharge') {
        return 0;
    }

    throw new RuntimeException("HN นี้ยังไม่ Discharge จึงไม่สามารถเปิดเคสซ้ำได้");
}
