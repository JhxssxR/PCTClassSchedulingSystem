<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an instructor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header('Location: ../auth/login.php?role=instructor');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate and sanitize input
        $subject_code = trim($_POST['subject_code']);
        $subject_name = trim($_POST['subject_name']);
        $schedule_day = trim($_POST['schedule_day']);
        $schedule_time = trim($_POST['schedule_time']);
        $room = trim($_POST['room']);
        $max_students = (int)$_POST['max_students'];

        // Validate required fields
        if (empty($subject_code) || empty($subject_name) || empty($schedule_day) || 
            empty($schedule_time) || empty($room) || $max_students < 1) {
            throw new Exception("All fields are required and maximum students must be at least 1");
        }

        // Validate schedule day
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        if (!in_array($schedule_day, $valid_days)) {
            throw new Exception("Invalid schedule day");
        }

        // Check for schedule conflicts
        $stmt = $conn->prepare("
            SELECT id FROM classes 
            WHERE instructor_id = ? 
            AND schedule_day = ? 
            AND schedule_time = ? 
            AND status = 'active'
        ");
        $stmt->execute([$_SESSION['user_id'], $schedule_day, $schedule_time]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("You already have a class scheduled at this time");
        }

        // Check if room is available at the specified time
        $stmt = $conn->prepare("
            SELECT id FROM classes 
            WHERE room = ? 
            AND schedule_day = ? 
            AND schedule_time = ? 
            AND status = 'active'
        ");
        $stmt->execute([$room, $schedule_day, $schedule_time]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Room is already occupied at this time");
        }

        // Insert new class
        $stmt = $conn->prepare("
            INSERT INTO classes (
                instructor_id, subject_code, subject_name, schedule_day, 
                schedule_time, room, max_students, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $subject_code,
            $subject_name,
            $schedule_day,
            $schedule_time,
            $room,
            $max_students
        ]);

        $_SESSION['success_message'] = "Class added successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Redirect back to dashboard
header('Location: dashboard.php#classes');
exit(); 