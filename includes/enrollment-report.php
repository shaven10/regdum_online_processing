<?php

function enrollmentReportYearColumns(): array {
    return ['1st Year', '2nd Year', '3rd Year', '4th Year'];
}

function defaultEnrollmentReportAcademicYear(): string {
    require_once __DIR__ . '/academic-term.php';
    return getActiveSchoolYear();
}

function defaultEnrollmentReportSemester(): string {
    require_once __DIR__ . '/academic-term.php';
    return getActiveSemester();
}

function enrollmentReportSemesterHeading(?string $semester): string {
    return match ($semester) {
        '1st_semester' => 'FIRST SEMESTER',
        '2nd_semester' => 'SECOND SEMESTER',
        'summer'       => 'SUMMER / MIDYEAR',
        default        => 'ALL TERMS',
    };
}

function enrollmentReportNormalizeSex(?string $sex): string {
    $sex = strtoupper(trim((string) $sex));
    if (in_array($sex, ['M', 'MALE'], true)) {
        return 'M';
    }
    if (in_array($sex, ['F', 'FEMALE'], true)) {
        return 'F';
    }
    return 'U';
}

function enrollmentReportYearBucket(?string $yearLevel): string {
    $year = strtolower(trim((string) $yearLevel));
    $year = str_replace(['year', 'yr', '.', '-'], ' ', $year);
    $year = trim(preg_replace('/\s+/', ' ', $year) ?? $year);

    return match ($year) {
        '1', 'i', '1st', 'first', '1st year', 'first year' => '1st Year',
        '2', 'ii', '2nd', 'second', '2nd year', 'second year' => '2nd Year',
        '3', 'iii', '3rd', 'third', '3rd year', 'third year' => '3rd Year',
        '4', 'iv', '4th', 'fourth', '4th year', 'fourth year' => '4th Year',
        default => 'other',
    };
}

function enrollmentReportEmptyCounts(): array {
    $counts = ['other' => ['M' => 0, 'F' => 0, 'U' => 0]];
    foreach (enrollmentReportYearColumns() as $year) {
        $counts[$year] = ['M' => 0, 'F' => 0, 'U' => 0];
    }
    return $counts;
}

function enrollmentReportAddCount(array &$counts, string $year, string $sex): void {
    if (!isset($counts[$year])) {
        $year = 'other';
    }
    if (!isset($counts[$year][$sex])) {
        $sex = 'U';
    }
    $counts[$year][$sex]++;
}

function enrollmentReportCountTotal(array $counts): int {
    $total = 0;
    foreach ($counts as $sexes) {
        $total += (int) ($sexes['M'] ?? 0) + (int) ($sexes['F'] ?? 0) + (int) ($sexes['U'] ?? 0);
    }
    return $total;
}

function enrollmentReportMergeCounts(array $a, array $b): array {
    foreach ($b as $year => $sexes) {
        foreach (['M', 'F', 'U'] as $sex) {
            $a[$year][$sex] = (int) ($a[$year][$sex] ?? 0) + (int) ($sexes[$sex] ?? 0);
        }
    }
    return $a;
}

function enrollmentReportCollegeCatalog(): array {
    $ste = ['section' => 'undergrad', 'college' => 'School of Teacher Education (STE)', 'order' => 30];
    $socje = ['section' => 'undergrad', 'college' => 'School of Criminal Justice Education (SoCJE)', 'order' => 60];

    return [
        'MAGDEV' => ['section' => 'graduate', 'college' => 'GRADUATE STUDIES', 'order' => 10],
        'MAED'   => ['section' => 'graduate', 'college' => 'GRADUATE STUDIES', 'order' => 10],
        'MAEDELT' => ['section' => 'graduate', 'college' => 'GRADUATE STUDIES', 'order' => 10],
        'MBA'    => ['section' => 'graduate', 'college' => 'GRADUATE STUDIES', 'order' => 10],
        'MPA'    => ['section' => 'graduate', 'college' => 'GRADUATE STUDIES', 'order' => 10],
        'EDD'    => ['section' => 'graduate', 'college' => 'GRADUATE STUDIES', 'order' => 10],
        'PHD'    => ['section' => 'graduate', 'college' => 'GRADUATE STUDIES', 'order' => 10],
        'BSA'    => ['section' => 'undergrad', 'college' => 'School of Agriculture, Forestry and Environmental Studies (SAFES)', 'order' => 20],
        'BSAF'   => ['section' => 'undergrad', 'college' => 'School of Agriculture, Forestry and Environmental Studies (SAFES)', 'order' => 20],
        'BSF'    => ['section' => 'undergrad', 'college' => 'School of Agriculture, Forestry and Environmental Studies (SAFES)', 'order' => 20],
        'BSEN'   => ['section' => 'undergrad', 'college' => 'School of Agriculture, Forestry and Environmental Studies (SAFES)', 'order' => 20],
        'BAT'    => $ste,
        'BATS'   => $ste,
        'BEED'   => $ste,
        'BSED'   => $ste,
        'BPED'   => $ste,
        'BPE'    => $ste,
        'BECED'  => $ste,
        'BTLED'  => $ste,
        'BAELS'  => ['section' => 'undergrad', 'college' => 'School of Arts and Sciences (SAS)', 'order' => 40],
        'ABELS'  => ['section' => 'undergrad', 'college' => 'School of Arts and Sciences (SAS)', 'order' => 40],
        'BSIT'   => ['section' => 'undergrad', 'college' => 'School of Computing Studies (SCS)', 'order' => 50],
        'BSCS'   => ['section' => 'undergrad', 'college' => 'School of Computing Studies (SCS)', 'order' => 50],
        'BSIS'   => ['section' => 'undergrad', 'college' => 'School of Computing Studies (SCS)', 'order' => 50],
        'BSCRIM' => $socje,
        'BSCRIMINOLOGY' => $socje,
        'CRIM'   => $socje,
        'BSISM'  => $socje,
        'ISM'    => $socje,
        'BSN'    => ['section' => 'undergrad', 'college' => 'School of Nursing', 'order' => 70],
        'BSBA'   => ['section' => 'undergrad', 'college' => 'School of Business and Management', 'order' => 80],
        'BSACC'  => ['section' => 'undergrad', 'college' => 'School of Business and Management', 'order' => 80],
        'BSHM'   => ['section' => 'undergrad', 'college' => 'School of Business and Management', 'order' => 80],
    ];
}

function enrollmentReportProgramMeta(string $code, string $name = ''): array {
    $catalog = enrollmentReportCollegeCatalog();
    $codeKey = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? $code);
    $displayCode = $code !== '' ? $code : $codeKey;

    if ($codeKey !== '' && isset($catalog[$codeKey])) {
        return $catalog[$codeKey] + ['code' => $displayCode];
    }

    $keys = array_keys($catalog);
    usort($keys, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($keys as $key) {
        if ($codeKey !== '' && str_starts_with($codeKey, $key)) {
            return $catalog[$key] + ['code' => $displayCode];
        }
    }

    $haystack = strtoupper($code . ' ' . $name);
    $keywordMap = [
        'INDUSTRIAL SECURITY' => 'BSISM',
        'CRIMINOLOGY'         => 'BSCRIM',
        'PHYSICAL EDUCATION'  => 'BPED',
        'ELEMENTARY EDUCATION'=> 'BEED',
        'SECONDARY EDUCATION' => 'BSED',
        'ARTS IN TEACHING'    => 'BAT',
        'TEACHER EDUCATION'   => 'BAT',
    ];
    foreach ($keywordMap as $needle => $catalogKey) {
        if (str_contains($haystack, $needle) && isset($catalog[$catalogKey])) {
            return $catalog[$catalogKey] + ['code' => $displayCode !== '' ? $displayCode : $catalogKey];
        }
    }
    if (preg_match('/\b(GRADE\s*[7-9]|GRADE\s*1[0-2]|JUNIOR HIGH|SENIOR HIGH|SHS|JHS)\b/', $haystack)) {
        return [
            'section' => 'highschool',
            'college' => 'HIGH SCHOOL DEPARTMENT',
            'order'   => 90,
            'code'    => $code !== '' ? $code : $name,
        ];
    }
    if (preg_match('/\b(MA|MS|MBA|MPA|MAGDEV|MAED|EDD|PHD|DOCTOR|MASTER|GRADUATE)\b/', $haystack)) {
        return [
            'section' => 'graduate',
            'college' => 'GRADUATE STUDIES',
            'order'   => 10,
            'code'    => $code !== '' ? $code : $name,
        ];
    }

    return [
        'section' => 'undergrad',
        'college' => 'Other Undergraduate Programs',
        'order'   => 85,
        'code'    => $code !== '' ? $code : ($name !== '' ? $name : 'UNLISTED'),
    ];
}

function enrollmentReportSectionLabel(string $section): string {
    return match ($section) {
        'graduate'   => 'GRADUATE SCHOOL',
        'highschool' => 'HIGH SCHOOL DEPARTMENT',
        default      => 'UNDERGRADUATE',
    };
}

function getEnrollmentByCourseReport(array $filters): array {
    ensureAcademicProgramsSchema();
    ensureCampusesSchema();
    ensureEnrollmentStatuses();
    ensureStudentImportProfileFields();

    $yearOptions = schoolYearOptions();
    $semesterOptions = semesterOptions();
    $academicYear = trim((string) ($filters['academic_year'] ?? defaultEnrollmentReportAcademicYear()));
    if ($academicYear !== '' && !isset($yearOptions[$academicYear])) {
        $academicYear = defaultEnrollmentReportAcademicYear();
    }
    $semester = trim((string) ($filters['semester'] ?? defaultEnrollmentReportSemester()));
    if ($semester !== '' && !isset($semesterOptions[$semester])) {
        $semester = defaultEnrollmentReportSemester();
    }
    $campusId = (int) ($filters['campus_id'] ?? 0);
    $asOf = trim((string) ($filters['as_of'] ?? date('Y-m-d')));
    if ($asOf === '' || strtotime($asOf) === false) {
        $asOf = date('Y-m-d');
    }

    $db = getDB();
    $where = [
        "r.name = 'student'",
        'u.is_active = 1',
        "sp.enrollment_status = 'enrolled'",
    ];
    $params = [];

    if ($academicYear !== '') {
        $where[] = 'sp.current_academic_year = ?';
        $params[] = $academicYear;
    }
    if ($semester !== '') {
        $where[] = 'sp.current_semester = ?';
        $params[] = $semester;
    }
    if ($campusId > 0) {
        $where[] = 'sp.origin_campus_id = ?';
        $params[] = $campusId;
    }

    $stmt = $db->prepare('SELECT u.id, sp.course, sp.course_id, sp.year_level, sp.major, sp.sex,
            ap.code AS program_code, ap.name AS program_name, ap.sort_order
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN academic_programs ap ON ap.id = sp.course_id
        WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $programs = [];
    foreach ($students as $student) {
        $code = trim((string) ($student['program_code'] ?? ''));
        $name = trim((string) ($student['program_name'] ?? $student['course'] ?? ''));
        if ($code === '' && $name === '') {
            $code = 'UNLISTED';
            $name = 'Unspecified Program';
        }
        $meta = enrollmentReportProgramMeta($code, $name);
        $programKey = (int) ($student['course_id'] ?? 0) > 0
            ? 'id:' . (int) $student['course_id']
            : 'name:' . strtoupper($name !== '' ? $name : $code);
        $major = trim((string) ($student['major'] ?? ''));
        $majorKey = $major === '' ? '' : strtoupper($major);
        $year = enrollmentReportYearBucket($student['year_level'] ?? '');
        $sex = enrollmentReportNormalizeSex($student['sex'] ?? '');

        if (!isset($programs[$programKey])) {
            $programs[$programKey] = [
                'code'       => $meta['code'] ?: $code,
                'name'       => $name,
                'label'      => $code !== '' ? $code : $name,
                'section'    => $meta['section'],
                'college'    => $meta['college'],
                'order'      => $meta['order'],
                'sort_order' => (int) ($student['sort_order'] ?? 999),
                'counts'     => enrollmentReportEmptyCounts(),
                'majors'     => [],
            ];
        }

        enrollmentReportAddCount($programs[$programKey]['counts'], $year, $sex);

        if ($majorKey !== '') {
            if (!isset($programs[$programKey]['majors'][$majorKey])) {
                $programs[$programKey]['majors'][$majorKey] = [
                    'label'  => $major,
                    'counts' => enrollmentReportEmptyCounts(),
                ];
            }
            enrollmentReportAddCount($programs[$programKey]['majors'][$majorKey]['counts'], $year, $sex);
        }
    }

    uasort($programs, static function (array $a, array $b): int {
        return [$a['order'], $a['sort_order'], $a['label']] <=> [$b['order'], $b['sort_order'], $b['label']];
    });

    $colleges = [];
    foreach ($programs as $program) {
        $collegeKey = $program['section'] . '|' . $program['college'];
        if (!isset($colleges[$collegeKey])) {
            $colleges[$collegeKey] = [
                'section'  => $program['section'],
                'college'  => $program['college'],
                'order'    => $program['order'],
                'counts'   => enrollmentReportEmptyCounts(),
                'programs' => [],
            ];
        }
        $colleges[$collegeKey]['counts'] = enrollmentReportMergeCounts($colleges[$collegeKey]['counts'], $program['counts']);
        $colleges[$collegeKey]['programs'][] = $program;
    }

    $sectionOrder = ['graduate' => 1, 'undergrad' => 2, 'highschool' => 3];
    uasort($colleges, static function (array $a, array $b) use ($sectionOrder): int {
        return [$sectionOrder[$a['section']] ?? 9, $a['order'], $a['college']]
            <=> [$sectionOrder[$b['section']] ?? 9, $b['order'], $b['college']];
    });

    $rows = [];
    $sectionTotals = [
        'graduate'   => enrollmentReportEmptyCounts(),
        'undergrad'  => enrollmentReportEmptyCounts(),
        'highschool' => enrollmentReportEmptyCounts(),
    ];
    $grandCounts = enrollmentReportEmptyCounts();
    $lastSection = '';

    foreach ($colleges as $college) {
        if ($lastSection !== '' && $lastSection !== $college['section'] && $lastSection === 'undergrad') {
            $rows[] = [
                'type'   => 'section-total',
                'label'  => 'Total',
                'counts' => $sectionTotals['undergrad'],
                'total'  => enrollmentReportCountTotal($sectionTotals['undergrad']),
            ];
        }
        if ($lastSection !== $college['section'] && $college['section'] === 'undergrad') {
            $rows[] = [
                'type'  => 'section-banner',
                'label' => 'UNDERGRAD',
            ];
        }

        $collegeTotal = enrollmentReportCountTotal($college['counts']);
        $rows[] = [
            'type'  => 'college-header',
            'label' => $college['college'] . ' — ' . number_format($collegeTotal),
        ];

        foreach ($college['programs'] as $program) {
            $hasMajors = !empty($program['majors']);
            $rows[] = [
                'type'   => 'course',
                'label'  => $hasMajors ? ($program['label'] . ' Total') : $program['label'],
                'counts' => $program['counts'],
                'total'  => enrollmentReportCountTotal($program['counts']),
            ];
            if ($hasMajors) {
                uasort($program['majors'], static fn($a, $b) => strcasecmp($a['label'], $b['label']));
                foreach ($program['majors'] as $major) {
                    $rows[] = [
                        'type'   => 'major',
                        'label'  => $major['label'],
                        'counts' => $major['counts'],
                        'total'  => enrollmentReportCountTotal($major['counts']),
                    ];
                }
            }
        }

        $sectionTotals[$college['section']] = enrollmentReportMergeCounts(
            $sectionTotals[$college['section']],
            $college['counts']
        );
        $grandCounts = enrollmentReportMergeCounts($grandCounts, $college['counts']);
        $lastSection = $college['section'];
    }

    if ($lastSection === 'undergrad') {
        $rows[] = [
            'type'   => 'section-total',
            'label'  => 'Total',
            'counts' => $sectionTotals['undergrad'],
            'total'  => enrollmentReportCountTotal($sectionTotals['undergrad']),
        ];
    }

    $summary = [];
    foreach (['highschool' => 'HIGH SCHOOL DEPARTMENT', 'graduate' => 'GRADUATE SCHOOL', 'undergrad' => 'UNDERGRADUATE'] as $section => $label) {
        $total = enrollmentReportCountTotal($sectionTotals[$section]);
        if ($total > 0 || !empty(array_filter($colleges, static fn($c) => $c['section'] === $section))) {
            $summary[] = ['label' => $label, 'total' => $total];
        }
    }

    $campusName = '';
    if ($campusId > 0) {
        $campus = getCampusById($campusId);
        $campusName = $campus['name'] ?? '';
    }

    return [
        'filters' => [
            'academic_year' => $academicYear,
            'semester'      => $semester,
            'campus_id'     => $campusId,
            'as_of'         => $asOf,
        ],
        'rows'           => $rows,
        'summary'        => $summary,
        'grand_total'    => enrollmentReportCountTotal($grandCounts),
        'student_count'  => count($students),
        'campus_name'    => $campusName,
        'year_columns'   => enrollmentReportYearColumns(),
        'heading'        => [
            'title'    => 'ENROLMENT BY COURSE, YEAR LEVEL',
            'sy'       => $academicYear !== '' ? $academicYear : 'All School Years',
            'semester' => enrollmentReportSemesterHeading($semester),
            'as_of'    => date('F j, Y', strtotime($asOf)),
        ],
    ];
}

function enrollmentReportExportHeaders(array $yearColumns): array {
    $headers = ['College / Course'];
    foreach ($yearColumns as $year) {
        $headers[] = $year . ' M';
        $headers[] = $year . ' F';
    }
    $headers[] = 'TOTAL';
    $headers[] = 'Grand Total';
    return $headers;
}

function enrollmentReportExportRows(array $report): array {
    $rows = [];
    $years = $report['year_columns'];
    foreach ($report['rows'] as $row) {
        if (in_array($row['type'], ['college-header', 'section-banner'], true)) {
            $line = [$row['label']];
            $pad = (count($years) * 2) + 2;
            $rows[] = array_merge($line, array_fill(0, $pad, ''));
            continue;
        }
        $line = [$row['label']];
        foreach ($years as $year) {
            $line[] = (int) ($row['counts'][$year]['M'] ?? 0);
            $line[] = (int) ($row['counts'][$year]['F'] ?? 0);
        }
        $line[] = (int) ($row['total'] ?? 0);
        $line[] = (int) ($row['total'] ?? 0);
        $rows[] = $line;
    }
    $rows[] = ['GRAND TOTAL', ...array_fill(0, count($years) * 2, ''), $report['grand_total'], $report['grand_total']];
    return $rows;
}

function enrollmentReportFormatCount(int $count): string {
    return $count === 0 ? '0' : number_format($count);
}

function renderEnrollmentReportMatrix(array $report, string $variant = 'screen'): void {
    $years = $report['year_columns'];
    $colspanYears = count($years) * 2;
    ?>
    <table class="enrollment-report-table<?= $variant === 'print' ? ' enrollment-report-print-table' : ' data-table' ?>">
        <thead>
            <tr>
                <th class="enrollment-report-course-col" rowspan="2">Course</th>
                <?php foreach ($years as $year): ?>
                    <th class="enrollment-report-year-col" colspan="2"><?= e($year) ?></th>
                <?php endforeach; ?>
                <th class="enrollment-report-total-col" rowspan="2">TOTAL</th>
                <th class="enrollment-report-grand-col" rowspan="2">Grand<br>Total</th>
            </tr>
            <tr>
                <?php foreach ($years as $_year): ?>
                    <th class="enrollment-report-mf-col">M</th>
                    <th class="enrollment-report-mf-col">F</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($report['rows'])): ?>
                <tr>
                    <td colspan="<?= 3 + $colspanYears ?>" class="text-center text-muted">
                        No active enrolled students match this school year and semester.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($report['rows'] as $row): ?>
                    <?php if ($row['type'] === 'college-header' || $row['type'] === 'section-banner'): ?>
                        <tr class="enrollment-report-<?= e($row['type']) ?>">
                            <td colspan="<?= 3 + $colspanYears ?>"><?= e($row['label']) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr class="enrollment-report-<?= e($row['type']) ?>">
                            <td class="enrollment-report-course-col"><?= e($row['label']) ?></td>
                            <?php foreach ($years as $year): ?>
                            <td class="num enrollment-report-mf-col"><?= e(enrollmentReportFormatCount((int) ($row['counts'][$year]['M'] ?? 0))) ?></td>
                            <td class="num enrollment-report-mf-col"><?= e(enrollmentReportFormatCount((int) ($row['counts'][$year]['F'] ?? 0))) ?></td>
                        <?php endforeach; ?>
                        <td class="num enrollment-report-total-col"><strong><?= e(enrollmentReportFormatCount((int) ($row['total'] ?? 0))) ?></strong></td>
                        <td class="num enrollment-report-grand-col"><strong><?= e(enrollmentReportFormatCount((int) ($row['total'] ?? 0))) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

function getEnrollmentListReport(array $filters): array {
    ensureAcademicProgramsSchema();
    ensureCampusesSchema();
    ensureEnrollmentStatuses();
    ensureStudentImportProfileFields();

    $yearOptions = schoolYearOptions();
    $semesterOptions = semesterOptions();
    $academicYear = trim((string) ($filters['academic_year'] ?? defaultEnrollmentReportAcademicYear()));
    if ($academicYear !== '' && !isset($yearOptions[$academicYear])) {
        $academicYear = defaultEnrollmentReportAcademicYear();
    }
    $semester = trim((string) ($filters['semester'] ?? defaultEnrollmentReportSemester()));
    if ($semester !== '' && !isset($semesterOptions[$semester])) {
        $semester = defaultEnrollmentReportSemester();
    }
    $campusId = (int) ($filters['campus_id'] ?? 0);
    $courseId = (int) ($filters['course_id'] ?? 0);
    $asOf = trim((string) ($filters['as_of'] ?? date('Y-m-d')));
    if ($asOf === '' || strtotime($asOf) === false) {
        $asOf = date('Y-m-d');
    }

    $db = getDB();
    $where = [
        "r.name = 'student'",
        'u.is_active = 1',
        "sp.enrollment_status = 'enrolled'",
    ];
    $params = [];

    if ($academicYear !== '') {
        $where[] = 'sp.current_academic_year = ?';
        $params[] = $academicYear;
    }
    if ($semester !== '') {
        $where[] = 'sp.current_semester = ?';
        $params[] = $semester;
    }
    if ($campusId > 0) {
        $where[] = 'sp.origin_campus_id = ?';
        $params[] = $campusId;
    }
    if ($courseId > 0) {
        $where[] = 'sp.course_id = ?';
        $params[] = $courseId;
    }

    $stmt = $db->prepare('SELECT u.id, u.first_name, u.last_name, u.middle_name, u.student_id, u.email, u.phone,
            sp.course, sp.course_id, sp.year_level, sp.major, sp.sex, sp.civil_status,
            ap.code AS program_code, ap.name AS program_name, ap.sort_order
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN academic_programs ap ON ap.id = sp.course_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY COALESCE(ap.sort_order, 999), COALESCE(ap.code, sp.course), u.last_name, u.first_name, u.middle_name, u.student_id');
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $groups = [];
    foreach ($rows as $student) {
        $code = trim((string) ($student['program_code'] ?? ''));
        $name = trim((string) ($student['program_name'] ?? $student['course'] ?? ''));
        if ($code === '' && $name === '') {
            $code = 'UNLISTED';
            $name = 'Unspecified Program';
        }
        $meta = enrollmentReportProgramMeta($code, $name);
        $programKey = (int) ($student['course_id'] ?? 0) > 0
            ? 'id:' . (int) $student['course_id']
            : 'name:' . strtoupper($name !== '' ? $name : $code);

        if (!isset($groups[$programKey])) {
            $groups[$programKey] = [
                'code'       => $meta['code'] ?: $code,
                'name'       => $name,
                'label'      => $code !== '' ? $code : $name,
                'college'    => $meta['college'],
                'order'      => $meta['order'],
                'sort_order' => (int) ($student['sort_order'] ?? 999),
                'students'   => [],
                'male'       => 0,
                'female'     => 0,
                'unknown'    => 0,
            ];
        }

        $sex = enrollmentReportNormalizeSex($student['sex'] ?? '');
        if ($sex === 'M') {
            $groups[$programKey]['male']++;
        } elseif ($sex === 'F') {
            $groups[$programKey]['female']++;
        } else {
            $groups[$programKey]['unknown']++;
        }

        $student['display_name'] = studentRecordName($student);
        $student['display_last'] = normalizePersonName($student['last_name'] ?? '');
        $student['display_first'] = normalizePersonName($student['first_name'] ?? '');
        $student['display_middle'] = normalizePersonName($student['middle_name'] ?? '');
        $student['sex_label'] = $sex === 'U' ? '—' : $sex;
        $groups[$programKey]['students'][] = $student;
    }

    uasort($groups, static function (array $a, array $b): int {
        return [$a['order'], $a['sort_order'], $a['label']] <=> [$b['order'], $b['sort_order'], $b['label']];
    });

    foreach ($groups as &$group) {
        $group['total'] = count($group['students']);
    }
    unset($group);

    $campusName = '';
    if ($campusId > 0) {
        $campus = getCampusById($campusId);
        $campusName = $campus['name'] ?? '';
    }

    $courseLabel = '';
    if ($courseId > 0) {
        $program = getAcademicProgramById($courseId);
        if ($program) {
            $courseLabel = trim(($program['code'] ?? '') . ' — ' . ($program['name'] ?? ''), ' —');
        }
    }

    return [
        'filters' => [
            'academic_year' => $academicYear,
            'semester'      => $semester,
            'campus_id'     => $campusId,
            'course_id'     => $courseId,
            'as_of'         => $asOf,
        ],
        'groups'        => array_values($groups),
        'student_count' => count($rows),
        'campus_name'   => $campusName,
        'course_label'  => $courseLabel,
        'heading'       => [
            'title'    => 'ENROLMENT LIST PER COURSE',
            'sy'       => $academicYear !== '' ? $academicYear : 'All School Years',
            'semester' => enrollmentReportSemesterHeading($semester),
            'as_of'    => date('F j, Y', strtotime($asOf)),
        ],
    ];
}

function paginateEnrollmentListGroups(array $groups, int $offset, int $limit): array {
    $offset = max(0, $offset);
    $limit = max(1, $limit);
    $skipped = 0;
    $taken = 0;
    $pageGroups = [];

    foreach ($groups as $group) {
        if ($taken >= $limit) {
            break;
        }

        $students = $group['students'] ?? [];
        $count = count($students);
        if ($count === 0) {
            continue;
        }

        if ($skipped + $count <= $offset) {
            $skipped += $count;
            continue;
        }

        $start = max(0, $offset - $skipped);
        $take = min($limit - $taken, $count - $start);
        $slice = array_slice($students, $start, $take);
        foreach ($slice as $i => $student) {
            $slice[$i]['row_number'] = $start + $i + 1;
        }

        $group['students'] = $slice;
        $pageGroups[] = $group;
        $taken += $take;
        $skipped += $count;
    }

    return $pageGroups;
}

function enrollmentListExportHeaders(): array {
    return ['Course', 'No.', 'ID No.', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Year', 'Major', 'Email', 'Mobile #'];
}

function enrollmentListExportRows(array $report): array {
    $rows = [];
    foreach ($report['groups'] as $group) {
        $course = trim($group['label'] . ' — ' . $group['name'], ' —');
        foreach ($group['students'] as $i => $student) {
            $rows[] = [
                $course,
                $i + 1,
                $student['student_id'] ?? '',
                $student['display_last'] ?? normalizePersonName($student['last_name'] ?? ''),
                $student['display_first'] ?? normalizePersonName($student['first_name'] ?? ''),
                $student['display_middle'] ?? normalizePersonName($student['middle_name'] ?? ''),
                $student['sex_label'] ?? '',
                $student['year_level'] ?? '',
                $student['major'] ?? '',
                $student['email'] ?? '',
                $student['phone'] ?? '',
            ];
        }
    }
    return $rows;
}
