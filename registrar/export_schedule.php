<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    // Get all schedules with related information
    $stmt = $conn->prepare("
        SELECT s.*, 
               c.course_code,
               c.course_name,
               cr.room_number,
               cr.capacity,
               u.first_name as instructor_first_name,
               u.last_name as instructor_last_name
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        JOIN users u ON s.instructor_id = u.id
        ORDER BY s.day_of_week ASC, s.start_time ASC
    ");
    $stmt->execute();
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="class_schedules_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    // Start Excel content
    echo "Day\tTime\tCourse\tSubject\tInstructor\tRoom\tCapacity\tStatus\n";

    // Add data rows
    foreach ($schedules as $schedule) {
        $time = date('g:i A', strtotime($schedule['start_time'])) . ' - ' . 
                date('g:i A', strtotime($schedule['end_time']));
        $instructor = $schedule['instructor_first_name'] . ' ' . $schedule['instructor_last_name'];
        
        echo implode("\t", [
            $schedule['day_of_week'],
            $time,
            $schedule['course_code'],
            $schedule['course_name'],
            $instructor,
            'Room ' . $schedule['room_number'],
            $schedule['capacity'],
            ucfirst($schedule['status'])
        ]) . "\n";
    }

} catch (PDOException $e) {
    error_log("Export Error: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while exporting schedules.";
    header('Location: classes.php');
    exit();
} 