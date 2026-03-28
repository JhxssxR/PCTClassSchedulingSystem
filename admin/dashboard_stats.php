<?php
require_once '../config/database.php';
require_once '../includes/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_logged_in() || !has_role('super_admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit();
}

try {
    $total_users = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $students = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
    $instructors = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn();

    $active_classes = (int)$conn->query("SELECT COUNT(*) FROM schedules WHERE status = 'active'")->fetchColumn();

    // Enrollment-related stats are tied to active schedules to reflect what's currently running.
    $stmt = $conn->query("
        SELECT
            COUNT(*) AS total_enrollments,
            COUNT(DISTINCT e.student_id) AS enrolled_students
        FROM enrollments e
        JOIN schedules sch ON e.schedule_id = sch.id
        WHERE sch.status = 'active'
          AND e.status IN ('enrolled', 'approved')
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_enrollments' => 0, 'enrolled_students' => 0];

    $total_enrollments = (int)($row['total_enrollments'] ?? 0);
    $enrolled_students = (int)($row['enrolled_students'] ?? 0);

    $courses = (int)$conn->query("SELECT COUNT(*) FROM courses")->fetchColumn();

    echo json_encode([
        'total_users' => $total_users,
        'students' => $students,
        'instructors' => $instructors,
        'active_classes' => $active_classes,
        'total_enrollments' => $total_enrollments,
        'enrolled_students' => $enrolled_students,
        'courses' => $courses,
        'server_time' => date('c'),
    ]);
} catch (PDOException $e) {
    error_log('Error in dashboard_stats.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
