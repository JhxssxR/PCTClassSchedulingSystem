<?php
require 'config/database.php';

// One-time seeder script:
// - Adds the provided Education/Program courses into the `courses` table
// - Safe to run multiple times (uses INSERT IGNORE on unique course_code where possible)
//
// NOTE: If your `courses` table uses `credits` or `units`, we set it to 1 (system requires a value).

$courses_to_add = [
    ['code' => 'BTLED', 'name' => 'Bachelor of Technology & Livelihood Education'],
    ['code' => 'BEED', 'name' => 'Bachelor of Elementary Education'],
    ['code' => 'BSED-ENG', 'name' => 'Bachelor of Secondary Education major in English'],
    ['code' => 'BSED-MATH', 'name' => 'Bachelor of Secondary Education major in Mathematics'],
    ['code' => 'TCP', 'name' => 'Teacher Certificate Program'],

    ['code' => 'BSCA', 'name' => 'Bachelor of Science in Custom Administration'],
    ['code' => 'BSBAA', 'name' => 'Bachelor of Science in Business Accountancy'],
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

    ['code' => 'TM1', 'name' => 'Trainers Methodology I'],
    ['code' => 'JLP', 'name' => 'Japanese Language Program'],
    ['code' => 'IELTS', 'name' => 'IELTS'],
    ['code' => 'TESOL', 'name' => 'TESOL'],
    ['code' => 'ESL', 'name' => 'ESL'],
];

try {
    // Determine units/credits column if present
    $course_cols_stmt = $conn->prepare('DESCRIBE courses');
    $course_cols_stmt->execute();
    $course_cols = [];
    foreach ($course_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $course_cols[$r['Field']] = true;
    }
    $units_col = isset($course_cols['units']) ? 'units' : (isset($course_cols['credits']) ? 'credits' : null);

    // If course_code is UNIQUE, INSERT IGNORE will safely dedupe.
    $inserted = 0;

    if ($units_col) {
        $stmt = $conn->prepare("INSERT IGNORE INTO courses (course_code, course_name, {$units_col}) VALUES (?, ?, ?)");
        foreach ($courses_to_add as $c) {
            $stmt->execute([$c['code'], $c['name'], 1]);
            $inserted += (int)$stmt->rowCount();
        }
    } else {
        $stmt = $conn->prepare('INSERT IGNORE INTO courses (course_code, course_name) VALUES (?, ?)');
        foreach ($courses_to_add as $c) {
            $stmt->execute([$c['code'], $c['name']]);
            $inserted += (int)$stmt->rowCount();
        }
    }

    header('Content-Type: text/plain; charset=UTF-8');
    echo "SUCCESS: Inserted {$inserted} new course(s).\n";
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
