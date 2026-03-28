<?php
require 'config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $stmt = $conn->query("SELECT COALESCE(year_level, 0) AS year_level, COUNT(*) AS c FROM subjects GROUP BY COALESCE(year_level, 0) ORDER BY COALESCE(year_level, 0)");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Subjects count by year_level\n";
    echo "===========================\n";
    foreach ($rows as $r) {
        $y = (int)($r['year_level'] ?? 0);
        $c = (int)($r['c'] ?? 0);
        echo $y . "\t" . $c . "\n";
    }

    echo "\nSample Year 1 subjects\n";
    echo "----------------------\n";
    $stmt = $conn->prepare("SELECT subject_code, subject_name FROM subjects WHERE year_level = 1 ORDER BY subject_code LIMIT 10");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo ($r['subject_code'] ?? '') . "\t" . ($r['subject_name'] ?? '') . "\n";
    }

    echo "\nSample Year 2 subjects\n";
    echo "----------------------\n";
    $stmt = $conn->prepare("SELECT subject_code, subject_name FROM subjects WHERE year_level = 2 ORDER BY subject_code LIMIT 10");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo ($r['subject_code'] ?? '') . "\t" . ($r['subject_name'] ?? '') . "\n";
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
