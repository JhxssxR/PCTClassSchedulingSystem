<?php
require_once '../config/database.php';

if (!isset($_GET['class_id'])) {
    die('Class ID is required');
}

$class_id = $_GET['class_id'];

// Get class details
$stmt = $conn->prepare("
    SELECT c.*, 
           i.first_name as instructor_first_name, 
           i.last_name as instructor_last_name
    FROM classes c
    LEFT JOIN users i ON c.instructor_id = i.id
    WHERE c.id = ?
");
$stmt->execute([$class_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    die('Class not found');
}

// Get enrolled students
$stmt = $conn->prepare("
    SELECT u.*, e.date_enrolled
    FROM users u
    JOIN enrollments e ON u.id = e.student_id
    WHERE e.class_id = ? AND e.status = 'enrolled'
    ORDER BY u.last_name, u.first_name
");
$stmt->execute([$class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid">
    <!-- Class Details Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h4><?php echo htmlspecialchars($class['subject_code'] . ' - ' . $class['subject_name']); ?></h4>
            <p class="mb-1">
                <strong>Instructor:</strong> 
                <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
            </p>
            <p class="mb-1">
                <strong>Schedule:</strong> 
                <?php echo htmlspecialchars($class['schedule_day'] . ' ' . date('g:i A', strtotime($class['schedule_time']))); ?>
            </p>
            <p class="mb-1">
                <strong>Room:</strong> 
                <?php echo htmlspecialchars($class['room']); ?>
            </p>
            <p class="mb-0">
                <strong>Enrolled:</strong> 
                <?php echo count($students); ?>/<?php echo $class['max_students']; ?> students
            </p>
        </div>
    </div>

    <!-- Student List -->
    <div class="row">
        <div class="col-12">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Year Level</th>
                        <th>Email</th>
                        <th>Date Enrolled</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($students as $index => $student): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($student['username']); ?></td>
                        <td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                        <td>Year <?php echo htmlspecialchars($student['year_level']); ?></td>
                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($student['date_enrolled'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No students enrolled</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div> 