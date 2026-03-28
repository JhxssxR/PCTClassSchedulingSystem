<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if class_id is provided
if (!isset($_GET['class_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Class ID is required']);
    exit();
}

try {
    // Get class details and instructor information
    $stmt = $conn->prepare("
        SELECT s.*, c.course_code, c.course_name, cr.room_number,
               u.email, u.first_name, u.last_name
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        JOIN users u ON s.instructor_id = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$_GET['class_id']]);
    $class = $stmt->fetch();

    if (!$class) {
        throw new Exception('Class not found');
    }

    // Format the schedule information
    $schedule_info = "Course: {$class['course_code']} - {$class['course_name']}\n";
    $schedule_info .= "Day: {$class['day_of_week']}\n";
    $schedule_info .= "Time: " . date('h:i A', strtotime($class['start_time'])) . "\n";
    $schedule_info .= "Room: {$class['room_number']}\n";
    $schedule_info .= "Start Date: " . date('M j, Y', strtotime($class['start_date'])) . "\n";
    $schedule_info .= "End Date: " . date('M j, Y', strtotime($class['end_date'])) . "\n";

    // In a real application, you would send an email here
    // For now, we'll just simulate it
    $to = $class['email'];
    $subject = "Class Schedule Notification";
    $message = "Dear {$class['first_name']} {$class['last_name']},\n\n";
    $message .= "This is to notify you about your class schedule:\n\n";
    $message .= $schedule_info;
    $message .= "\nBest regards,\nRegistrar's Office";

    // Log the notification attempt
    error_log("Notification sent to {$to} for class {$class['course_code']}");

    // Return success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Notification sent to {$class['first_name']} {$class['last_name']} ({$class['email']})"
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
} 