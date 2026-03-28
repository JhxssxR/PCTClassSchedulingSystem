<?php
require 'config/database.php';

// One-time seed script: replaces the contents of the `courses` table
// with the official course/program list provided by the user.
// Safety: refuses to run if schedules exist.

function table_columns(PDO $conn, string $table): array {
    $stmt = $conn->prepare("DESCRIBE {$table}");
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    return $cols;
}

$official_courses = [
    ['code' => 'BTLEd', 'name' => 'Bachelor of Technology & Livelihood Education'],
    ['code' => 'BEE', 'name' => 'Bachelor of Elementary Education'],

    ['code' => 'BSEd-ENG', 'name' => 'Bachelor of Secondary Education major in English'],
    ['code' => 'BSEd-MATH', 'name' => 'Bachelor of Secondary Education major in Mathematics'],

    ['code' => 'TCP', 'name' => 'Teacher Certificate Program'],

    ['code' => 'BSCA', 'name' => 'Bachelor of Science in Custom Administration'],
    ['code' => 'BSA', 'name' => 'Bachelor of Science in Business Accountancy'],

    ['code' => 'BSBA-FM', 'name' => 'Bachelor of Science in Business Administration major in Financial Management'],
    ['code' => 'BSBA-HRDM', 'name' => 'Bachelor of Science in Business Administration major in Human Resource Development Management'],
    ['code' => 'BSBA-OM', 'name' => 'Bachelor of Science in Business Administration major in Operations Management'],
    ['code' => 'BSBA-DM', 'name' => 'Bachelor of Science in Business Administration major in Digital marketing'],
    ['code' => 'BSBA-CM', 'name' => 'Bachelor of Science in Business Administration major in Cooperative Management'],

    ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology'],
    ['code' => 'DIT', 'name' => 'Diploma in Information Technology'],
    ['code' => 'ACT', 'name' => 'Associate in Computer Technology'],

    ['code' => 'BSHM', 'name' => 'Bachelor of Science in Hospitality Management'],
    ['code' => 'BSTM', 'name' => 'Bachelor of Science in Tourism Management'],

    ['code' => 'DAET', 'name' => 'Diploma in Automotive Engineering Technology'],
    ['code' => 'DHRT', 'name' => 'Diploma in Hotel & Restaurant Technology'],

    ['code' => 'PN', 'name' => 'Practical Nursing'],
    ['code' => 'HRS', 'name' => 'Hotel and Restaurant Services'],
    ['code' => 'TM', 'name' => 'Tourism Management'],
    ['code' => 'HCS', 'name' => 'Health Care Services'],

    ['code' => 'TMI', 'name' => 'Trainers Methodology I'],

    ['code' => 'JLP', 'name' => 'Japanese Language Program'],
    ['code' => 'IELTS', 'name' => 'IELTS'],
    ['code' => 'TESOL', 'name' => 'TESOL'],
    ['code' => 'ESL', 'name' => 'ESL'],
];

try {
    $cols = table_columns($conn, 'courses');
    $units_col = isset($cols['units']) ? 'units' : (isset($cols['credits']) ? 'credits' : null);
    if ($units_col === null) {
        throw new Exception('Cannot find a units/credits column in courses table.');
    }

    // Safety: do not wipe if schedules exist (avoids breaking schedule->course links).
    $schedules_exist = (int)$conn->query('SELECT COUNT(*) FROM schedules')->fetchColumn();
    if ($schedules_exist > 0) {
        throw new Exception('Schedules already exist. For safety, this script will not replace courses. Delete schedules first if you really want to reset courses.');
    }

    $conn->beginTransaction();

    $conn->exec('DELETE FROM courses');

    $stmt = $conn->prepare("INSERT INTO courses (course_code, course_name, {$units_col}) VALUES (?, ?, ?)");
    foreach ($official_courses as $c) {
        // These are program offerings (not unit-bearing subjects), but the system requires a value.
        $stmt->execute([$c['code'], $c['name'], 1]);
    }

    $conn->commit();

    header('Content-Type: text/plain; charset=UTF-8');
    echo 'SUCCESS: Seeded ' . count($official_courses) . " courses and removed old records.\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
