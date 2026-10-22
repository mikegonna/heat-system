<?php
function normalizeOccDateTime($value)
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    if (
        !$date
        || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i') !== $value
    ) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}

function getOccFollowupStatus($followupDate, $dischargeDate)
{
    $followup = normalizeOccDateTime($followupDate);
    if (!$followup || !is_string($dischargeDate) || $dischargeDate === '') {
        return null;
    }

    $dischargeTimestamp = strtotime($dischargeDate);
    $followupTimestamp = strtotime($followup);
    if ($dischargeTimestamp === false || $followupTimestamp < $dischargeTimestamp) {
        return null;
    }

    return $followupTimestamp - $dischargeTimestamp <= 14 * 24 * 60 * 60
        ? 'มาตรงนัด'
        : 'มาไม่ตรงนัด';
}

function canEditOccRecords()
{
    $userName = strtolower((string)($_SESSION['name'] ?? ''));
    $userRole = strtolower((string)($_SESSION['role'] ?? ''));
    return in_array($userName, ['occ', 'pct'], true) || $userRole === 'admin';
}
