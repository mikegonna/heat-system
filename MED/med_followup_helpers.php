<?php

function ensureMedFollowupColumns($conn)
{
    $columns = [
        'fu_date' => 'DATETIME NULL',
        'cpk' => 'VARCHAR(100) DEFAULT NULL',
        'bun' => 'VARCHAR(100) DEFAULT NULL',
        'cr' => 'VARCHAR(100) DEFAULT NULL',
        'eletroride' => 'VARCHAR(100) DEFAULT NULL',
    ];

    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM med_records LIKE '$column'");
        if (!$result) {
            error_log('Failed to check med_records.' . $column . ': ' . $conn->error);
            return false;
        }
        if ($result->num_rows === 0 && !$conn->query("ALTER TABLE med_records ADD `$column` $definition")) {
            error_log('Failed to add med_records.' . $column . ': ' . $conn->error);
            return false;
        }
    }

    return true;
}

function getMedFollowupDetails($followupDate, $dischargeDate)
{
    if (!is_string($followupDate) || $followupDate === '' || !is_string($dischargeDate) || $dischargeDate === '') {
        return null;
    }

    $followup = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $followupDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        !$followup
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $followup->format('Y-m-d\TH:i') !== $followupDate
    ) {
        return null;
    }

    $dischargeTimestamp = strtotime($dischargeDate);
    if ($dischargeTimestamp === false || $followup->getTimestamp() < $dischargeTimestamp) {
        return null;
    }

    $elapsedSeconds = $followup->getTimestamp() - $dischargeTimestamp;

    return [
        'fu_date' => $followup->format('Y-m-d H:i:s'),
        'med_status' => $elapsedSeconds <= 14 * 24 * 60 * 60 ? 'มาตรงนัด' : 'มาไม่ตรงนัด',
    ];
}
