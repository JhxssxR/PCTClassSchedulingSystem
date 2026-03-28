<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Check if user is logged in and has instructor role
require_role('instructor');

if (!isset($_GET['schedule_id']) || $_GET['schedule_id'] === '') {
    http_response_code(400);
    exit('Missing schedule ID.');
}

$schedule_id = $_GET['schedule_id'];

try {
    // Verify schedule ownership
    $stmt = $conn->prepare("
        SELECT s.id, c.course_code, c.course_name
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        WHERE s.id = ? AND s.instructor_id = ? AND s.status = 'active'
    ");
    $stmt->execute([$schedule_id, $_SESSION['user_id']]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$schedule) {
        http_response_code(403);
        exit('Schedule not found or access denied.');
    }

    // Get enrolled students for the class
    $stmt = $conn->prepare("
        SELECT u.first_name, u.last_name, u.email, e.status, e.enrolled_at
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        WHERE e.schedule_id = ?
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute([$schedule_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Instructor export students error: ' . $e->getMessage());
    http_response_code(500);
    exit('An error occurred while exporting students.');
}

$safe_course = preg_replace('/[^A-Za-z0-9_-]/', '_', $schedule['course_code']);
$filename = 'class_students_' . $safe_course . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fputcsv($output, ['Course', 'Student Name', 'Email', 'Status', 'Enrolled Date']);

foreach ($students as $student) {
    fputcsv($output, [
        $schedule['course_code'] . ' - ' . $schedule['course_name'],
        $student['last_name'] . ', ' . $student['first_name'],
        $student['email'],
        ucfirst($student['status']),
        !empty($student['enrolled_at']) ? date('Y-m-d', strtotime($student['enrolled_at'])) : ''
    ]);
}

fclose($output);
exit();
?>
