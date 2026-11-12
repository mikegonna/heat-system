<?php
require_once __DIR__ . '/../OCC/occ_helpers.php';

function canEditPmrRecords()
{
    $userName = strtolower((string)($_SESSION['name'] ?? ''));
    $userRole = strtolower((string)($_SESSION['role'] ?? ''));
    return in_array($userName, ['pmr', 'pct'], true) || $userRole === 'admin';
}

function normalizePmrDate($value)
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (
        !$date
        || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    return $date->format('Y-m-d');
}

function getPmrFollowupStatus($followupDate, $referenceDate, $isFirstAppointment)
{
    if ($isFirstAppointment) {
        return getOccFollowupStatus($followupDate, $referenceDate);
    }

    $followup = normalizeOccDateTime($followupDate);
    $nextAppointmentDate = normalizePmrDate($referenceDate);
    if (!$followup || !$nextAppointmentDate) {
        return null;
    }

    return substr($followup, 0, 10) <= $nextAppointmentDate
        ? 'มาตรงนัด'
        : 'มาไม่ตรงนัด';
}
