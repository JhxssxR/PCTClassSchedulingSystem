<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'admin', 'registrar'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

$class_id = $_GET['id'] ?? null;
if (!$class_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Class ID is required']);
    exit();
}

try {
    // Get class details
    $stmt = $conn->prepare("
        SELECT 
            s.*,
            c.course_code,
            c.course_name,
            c.credits,
            CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
            i.email as instructor_email,
            cr.room_number,
            cr.building,
            cr.capacity,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            u.email as created_by_email,
            (SELECT COUNT(*) FROM enrollments e WHERE e.schedule_id = s.id AND e.status IN ('approved', 'enrolled')) as enrolled_students
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN users i ON s.instructor_id = i.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        throw new Exception("Class not found");
    }

    if (empty($class['end_time']) && !empty($class['start_time'])) {
        $t = strtotime('1970-01-01 ' . $class['start_time']);
        if ($t !== false) {
            $class['end_time'] = date('H:i:s', $t + (120 * 60));
        }
    }

    // Check if enrollments has created_at
    $stmt_check = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name='enrollments' AND column_name='created_at'");
    $has_created_at = $stmt_check->rowCount() > 0;
    
    $created_at_select = $has_created_at ? 'e.created_at' : 'NULL as created_at';

    // Get enrolled students
    // We check if student_id exists on users (it usually doesn't by default but just in case we select u.id)
    $stmt = $conn->prepare("
        SELECT 
            e.id as enrollment_id,
            {$created_at_select},
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            s.email as student_email,
            s.username as student_id
        FROM enrollments e
        JOIN users s ON e.student_id = s.id
        WHERE e.schedule_id = ? AND e.status IN ('approved', 'enrolled')
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$class_id]);
    $enrolled_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare response
    $response = [
        'class' => $class,
        'students' => $enrolled_students
    ];

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
