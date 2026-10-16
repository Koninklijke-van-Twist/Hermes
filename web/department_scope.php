<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

function normalize(string $value): string
{
    return strtoupper(trim($value));
}

function code_matches(string $value, string $code): bool
{
    $normalizedValue = normalize($value);
    if ($normalizedValue === '' || $code === '') {
        return false;
    }

    if ($normalizedValue === $code) {
        return true;
    }

    $pattern = '/(^|[^A-Z0-9])' . preg_quote($code, '/') . '([^A-Z0-9]|$)/';
    return preg_match($pattern, $normalizedValue) === 1;
}

function matches_code_filter(array $row, array $fields, string $code): bool
{
    if ($code === '') {
        return true;
    }

    foreach ($fields as $field) {
        $value = (string) ($row[$field] ?? '');
        if (code_matches($value, $code)) {
            return true;
        }
    }

    return false;
}

function matches_allowed_department_codes(array $row, array $fields, array $allowedCodes): bool
{
    if (empty($allowedCodes)) {
        return true;
    }

    foreach ($allowedCodes as $code) {
        if (matches_code_filter($row, $fields, (string) $code)) {
            return true;
        }
    }

    return false;
}

function matches_department_scope(array $row, array $fields, string $selectedCode, array $allowedCodes): bool
{
    if ($selectedCode !== '') {
        if (!empty($allowedCodes) && !in_array($selectedCode, $allowedCodes, true)) {
            return false;
        }

        return matches_code_filter($row, $fields, $selectedCode);
    }

    return matches_allowed_department_codes($row, $fields, $allowedCodes);
}
