<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    // Get all enrollments with related information
    $stmt = $conn->prepare("
        SELECT 
            s.first_name as student_first_name,
            s.last_name as student_last_name,
            s.email as student_email,
            c.course_code,
            c.course_name,
            sch.day_of_week,
            sch.start_time,
            TIME_FORMAT(ADDTIME(sch.start_time, SEC_TO_TIME(120 * 60)), '%H:%i:%s') as end_time,
            u.first_name as instructor_first_name,
            u.last_name as instructor_last_name,
            cr.room_number,
            e.status,
            e.enrolled_at,
            e.dropped_at,
            e.rejected_at
        FROM enrollments e
        JOIN users s ON e.student_id = s.id
        JOIN schedules sch ON e.schedule_id = sch.id
        JOIN courses c ON sch.course_id = c.id
        JOIN users u ON sch.instructor_id = u.id
        JOIN classrooms cr ON sch.classroom_id = cr.id
        ORDER BY e.enrolled_at DESC
    ");
    $stmt->execute();
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="enrollments_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    // Start Excel content
    echo "Student Name\tStudent Email\tCourse\tSchedule\tInstructor\tRoom\tStatus\tDate\n";

    // Add data rows
    foreach ($enrollments as $enrollment) {
        $student_name = $enrollment['student_last_name'] . ', ' . $enrollment['student_first_name'];
        $course = $enrollment['course_code'] . ' - ' . $enrollment['course_name'];
        $schedule = $enrollment['day_of_week'] . ' ' . 
                   date('g:i A', strtotime($enrollment['start_time'])) . ' - ' . 
                   date('g:i A', strtotime($enrollment['end_time']));
        $instructor = $enrollment['instructor_last_name'] . ', ' . $enrollment['instructor_first_name'];
        $status = ucfirst($enrollment['status']);
        
        // Determine the relevant date based on status
        $date = '';
        switch ($enrollment['status']) {
            case 'approved':
            case 'enrolled':
                $date = date('M d, Y', strtotime($enrollment['enrolled_at']));
                break;
            case 'dropped':
                $date = date('M d, Y', strtotime($enrollment['dropped_at']));
                break;
            case 'rejected':
                $date = date('M d, Y', strtotime($enrollment['rejected_at']));
                break;
            default:
                $date = !empty($enrollment['enrolled_at']) ? date('M d, Y', strtotime($enrollment['enrolled_at'])) : '';
                break;
        }

        echo implode("\t", [
            $student_name,
            $enrollment['student_email'],
            $course,
            $schedule,
            $instructor,
            'Room ' . $enrollment['room_number'],
            $status,
            $date
        ]) . "\n";
    }

} catch (PDOException $e) {
    error_log("Export Error: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while exporting enrollments.";
    header('Location: manage_enrollments.php');
    exit();
} 