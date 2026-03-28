<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin or super admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.username,
            COUNT(e.id) AS enrolled_classes
        FROM users u
        LEFT JOIN enrollments e ON e.student_id = u.id
        WHERE u.role = 'student'
        GROUP BY u.id, u.first_name, u.last_name, u.email, u.username
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Export students error: ' . $e->getMessage());
    die('An error occurred while exporting students.');
}

$filename = 'students_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'Username', 'Enrolled Classes']);

foreach ($students as $student) {
    fputcsv($output, [
        $student['id'],
        $student['first_name'],
        $student['last_name'],
        $student['email'],
        $student['username'],
        $student['enrolled_classes']
    ]);
}

fclose($output);
exit();
?>
