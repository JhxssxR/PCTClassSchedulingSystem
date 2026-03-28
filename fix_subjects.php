<?php
require 'config/database.php';

// One-time migration script:
// - Creates a `subjects` table if missing
// - Seeds the subjects from the hardcoded list used on the Subjects pages
//
// Safe to run multiple times: uses UNIQUE(subject_code) and INSERT IGNORE.

$subjects = [
    ['code' => 'GE1', 'name' => 'Understanding the Self'],
    ['code' => 'GE2', 'name' => 'Reading Philippine History'],
    ['code' => 'GE3', 'name' => 'Contemporary World'],
    ['code' => 'CC101', 'name' => 'Introduction to Computing'],
    ['code' => 'CC102', 'name' => 'Fundamentals of Programming 1'],
    ['code' => 'NSTP 1', 'name' => 'National Service Training Program'],
    ['code' => 'PATHFit1', 'name' => 'Movement Competency Training'],

    ['code' => 'GE4', 'name' => 'Mathematics in the Modern World'],
    ['code' => 'GE5', 'name' => 'Purposive Communication'],
    ['code' => 'GE6', 'name' => 'Art Appreciation'],
    ['code' => 'CC103', 'name' => 'Intermediate Programming'],
    ['code' => 'MS101', 'name' => 'Discrete Mathematics'],
    ['code' => 'HCI101', 'name' => 'Human Computer Interaction'],
    ['code' => 'NSTP 2', 'name' => 'National Service Training Program'],
    ['code' => 'PATHFit2', 'name' => 'Exercise-based Fitness Activities'],

    ['code' => 'GE7', 'name' => 'Science Technology and Society'],
    ['code' => 'CC104', 'name' => 'Data Structure & Algorithms'],
    ['code' => 'IPT101', 'name' => 'Integrative Programming Technologies'],
    ['code' => 'MS102', 'name' => 'Quantitative Methods (inc. Modeling/Sim)'],
    ['code' => 'IOT', 'name' => 'Internet of Things'],
    ['code' => 'ML', 'name' => 'Machine Learning'],
    ['code' => 'Elec 1', 'name' => 'Elective 1 (Integrative Programming Tech 2)'],
    ['code' => 'PATHFit3', 'name' => 'Martial Arts'],

    ['code' => 'GE8', 'name' => 'Ethics'],
    ['code' => 'CC105', 'name' => 'Information Management'],
    ['code' => 'IAS101', 'name' => 'Information Assurance & Security'],
    ['code' => 'NET101', 'name' => 'Networking'],
    ['code' => 'ID', 'name' => 'Introduction to Data Science'],
    ['code' => 'AMP', 'name' => 'Advance Mobile Programming'],
    ['code' => 'Elec 2', 'name' => 'Elective 2 (Platform Technologies 1)'],
    ['code' => 'PATHFit4', 'name' => 'Group Exercises, Aerobics, Yoga'],

    ['code' => 'GE9', 'name' => 'Life and Works of Rizal'],
    ['code' => 'GE10', 'name' => 'Living in the IT Era'],
    ['code' => 'NET102', 'name' => 'Networking'],
    ['code' => 'SIA101', 'name' => 'System Integration and Architecture'],
    ['code' => 'IM101', 'name' => 'Fundamentals of Database Systems'],
    ['code' => 'Elec 3', 'name' => 'Elective 3 (Web System Technologies)'],

    ['code' => 'GE11', 'name' => 'The Entrepreneurial Mind'],
    ['code' => 'GE12', 'name' => 'Gender and Society'],
    ['code' => 'CC106', 'name' => 'App Development & Emerging Tech'],
    ['code' => 'SP101', 'name' => 'Social Professional Issues'],
    ['code' => 'FC', 'name' => 'Fundamentals of Cybersecurity'],
    ['code' => 'CC', 'name' => 'Cloud Computing'],
    ['code' => 'Elec 4', 'name' => 'Elective 4 (System Integration & Architecture 2)'],

    ['code' => 'CAP101', 'name' => 'Capstone Project 1 (Research)'],
    ['code' => 'IAS102', 'name' => 'Information Assurance & Security'],

    ['code' => 'CAP102', 'name' => 'Capstone Project 2 (Research)'],
    ['code' => 'SA101', 'name' => 'System Administration & Maintenance'],
];

try {
    $started_tx = false;
    try {
        $started_tx = $conn->beginTransaction();
    } catch (Throwable $e) {
        // Some environments may not allow starting a transaction here; proceed best-effort.
        $started_tx = false;
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject_code VARCHAR(50) NOT NULL UNIQUE,
        subject_name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $conn->prepare('INSERT IGNORE INTO subjects (subject_code, subject_name) VALUES (?, ?)');
    $inserted = 0;
    foreach ($subjects as $s) {
        $stmt->execute([$s['code'], $s['name']]);
        $inserted += (int)$stmt->rowCount();
    }

    if (isset($conn) && $conn->inTransaction()) {
        $conn->commit();
    }

    header('Content-Type: text/plain; charset=UTF-8');
    echo "SUCCESS: subjects table ready. Inserted {$inserted} new subject(s).\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
