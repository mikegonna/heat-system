<?php
function getFiscalDateRange(array $query): array
{
    $today = new DateTimeImmutable('today');
    $fiscalStartYear = (int)$today->format('Y');
    if ((int)$today->format('n') < 10) {
        $fiscalStartYear--;
    }

    $defaultStartDate = sprintf('%04d-10-01', $fiscalStartYear);
    $defaultEndDate = sprintf('%04d-09-30', $fiscalStartYear + 1);
    $startDate = sanitizeFiscalDate($query['start_date'] ?? null, $defaultStartDate);
    $endDate = sanitizeFiscalDate($query['end_date'] ?? null, $defaultEndDate);

    return [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'year_label' => (int)substr($startDate, 0, 4)
            + ((int)substr($startDate, 5, 2) < 10 ? 543 : 544)
    ];
}

function sanitizeFiscalDate($value, string $fallback): string
{
    if (
        !is_string($value)
        || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)
        || !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
    ) {
        return $fallback;
    }

    return $value;
}
