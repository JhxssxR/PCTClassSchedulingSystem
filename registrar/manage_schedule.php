<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: classes.php');
    exit();
}

try {
    if (isset($_POST['class_id'])) {
        // Update existing schedule
        $stmt = $conn->prepare("
            UPDATE schedules s
            JOIN courses c ON s.course_id = c.id
            JOIN classrooms cr ON s.classroom_id = cr.id
            SET c.course_code = :course_code,
                c.course_name = :course_name,
                s.instructor_id = :instructor_id,
                s.time_slot_id = :time_slot_id,
                cr.room_number = :room_number,
                cr.capacity = :capacity,
                s.status = :status
            WHERE s.id = :schedule_id
        ");
        
        $stmt->execute([
            'course_code' => $_POST['subject_code'],
            'course_name' => $_POST['subject_name'],
            'instructor_id' => $_POST['instructor_id'],
            'time_slot_id' => $_POST['time_slot_id'],
            'room_number' => $_POST['room'],
            'capacity' => $_POST['max_students'],
            'status' => $_POST['status'],
            'schedule_id' => $_POST['class_id']
        ]);

        $_SESSION['success'] = "Class schedule updated successfully.";
    } else {
        // Create new schedule
        // First, add the course
        $stmt = $conn->prepare("
            INSERT INTO courses (course_code, course_name, credits)
            VALUES (:course_code, :course_name, :credits)
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
        ");
        $stmt->execute([
            'course_code' => $_POST['subject_code'],
            'course_name' => $_POST['subject_name'],
            'credits' => $_POST['credits'] ?? 3
        ]);
        $course_id = $conn->lastInsertId();

        // Then, add or get the classroom
        $stmt = $conn->prepare("
            INSERT INTO classrooms (room_number, capacity, building, room_type)
            VALUES (:room_number, :capacity, :building, :room_type)
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
        ");
        $stmt->execute([
            'room_number' => $_POST['room'],
            'capacity' => $_POST['max_students'],
            'building' => $_POST['building'] ?? 'Main Building',
            'room_type' => $_POST['room_type'] ?? 'lecture'
        ]);
        $classroom_id = $conn->lastInsertId();

        // Finally, create the schedule
        $stmt = $conn->prepare("
            INSERT INTO schedules (
                course_id, instructor_id, classroom_id,
                time_slot_id, semester, academic_year, status
            ) VALUES (
                :course_id, :instructor_id, :classroom_id,
                :time_slot_id, :semester, :academic_year, :status
            )
        ");
        
        $stmt->execute([
            'course_id' => $course_id,
            'instructor_id' => $_POST['instructor_id'],
            'classroom_id' => $classroom_id,
            'time_slot_id' => $_POST['time_slot_id'],
            'semester' => $_POST['semester'] ?? 'First',
            'academic_year' => $_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1),
            'status' => $_POST['status'] ?? 'active'
        ]);

        $_SESSION['success'] = "New class schedule created successfully.";
    }
    
    header('Location: classes.php');
    exit();
    
} catch (PDOException $e) {
    error_log("Error in schedule management: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while processing your request.";
    header('Location: classes.php');
    exit();
} 