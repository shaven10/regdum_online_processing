<?php

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/student.php';

function ensureAcademicTermSettings(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensureRequirementDefaultsSchema();

    if (getAppSetting('active_school_year', '') === '') {
        setAppSetting('active_school_year', guessDefaultSchoolYear());
    }
    if (getAppSetting('active_semester', '') === '') {
        setAppSetting('active_semester', guessDefaultSemester());
    }
}

function guessDefaultSchoolYear(): string {
    $options = buildSchoolYearOptions();
    return (string) array_key_first($options);
}

function guessDefaultSemester(): string {
    $month = (int) date('n');
    if ($month >= 4 && $month <= 5) {
        return 'summer';
    }
    if ($month >= 6 && $month <= 10) {
        return '1st_semester';
    }
    return '2nd_semester';
}

/**
 * Raw school-year option list (does not depend on saved settings).
 */
function buildSchoolYearOptions(?string $includeYear = null): array {
    $current = (int) date('Y');
    $month = (int) date('n');
    $startYear = $month >= 6 ? $current : $current - 1;
    $options = [];

    // Current school year first (used as the default active term).
    $currentValue = $startYear . '-' . ($startYear + 1);
    $options[$currentValue] = $currentValue;

    // Next school year for early planning.
    $nextFrom = $startYear + 1;
    $nextValue = $nextFrom . '-' . ($nextFrom + 1);
    $options[$nextValue] = $nextValue;

    // Prior school years.
    for ($i = 1; $i < 15; $i++) {
        $from = $startYear - $i;
        $value = $from . '-' . ($from + 1);
        $options[$value] = $value;
    }

    $includeYear = trim((string) $includeYear);
    if ($includeYear !== '' && !isset($options[$includeYear]) && isValidSchoolYearValue($includeYear)) {
        $options = [$includeYear => $includeYear] + $options;
    }

    return $options;
}

function isValidSchoolYearValue(string $year): bool {
    if (!preg_match('/^(\d{4})-(\d{4})$/', $year, $matches)) {
        return false;
    }

    return ((int) $matches[2] === (int) $matches[1] + 1);
}

function isValidSemesterValue(string $semester): bool {
    return array_key_exists($semester, semesterOptions());
}

function getActiveSchoolYear(): string {
    ensureAcademicTermSettings();
    $saved = trim(getAppSetting('active_school_year', ''));
    if ($saved !== '' && isValidSchoolYearValue($saved)) {
        return $saved;
    }

    return guessDefaultSchoolYear();
}

function getActiveSemester(): string {
    ensureAcademicTermSettings();
    $saved = trim(getAppSetting('active_semester', ''));
    if ($saved !== '' && isValidSemesterValue($saved)) {
        return $saved;
    }

    return guessDefaultSemester();
}

function getActiveAcademicTerm(): array {
    return [
        'school_year' => getActiveSchoolYear(),
        'semester' => getActiveSemester(),
        'semester_label' => semesterLabel(getActiveSemester()),
    ];
}

function saveActiveAcademicTerm(string $schoolYear, string $semester): void {
    ensureAcademicTermSettings();

    $schoolYear = trim($schoolYear);
    $semester = trim($semester);

    if (!isValidSchoolYearValue($schoolYear)) {
        throw new InvalidArgumentException('Please select a valid school year.');
    }
    if (!isValidSemesterValue($semester)) {
        throw new InvalidArgumentException('Please select a valid semester.');
    }

    setAppSetting('active_school_year', $schoolYear);
    setAppSetting('active_semester', $semester);
}

function activeAcademicTermSummary(): string {
    $term = getActiveAcademicTerm();
    return $term['semester_label'] . ', S.Y. ' . $term['school_year'];
}
