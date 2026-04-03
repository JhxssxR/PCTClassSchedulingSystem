<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar or the student themselves
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$student_id = isset($_GET['id']) ? $_GET['id'] : $_SESSION['user_id'];

// If not a registrar, only allow viewing own schedule
if (!in_array($_SESSION['role'], ['admin', 'registrar'], true) && $_SESSION['user_id'] != $student_id) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    // Get student information
    $stmt = $conn->prepare("
        SELECT first_name, last_name, email
        FROM users
        WHERE id = ? AND role = 'student'
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        throw new Exception("Student not found");
    }

    // Get student's schedule
    $stmt = $conn->prepare("
        SELECT 
            s.day_of_week,
            s.start_time,
            TIME_FORMAT(ADDTIME(s.start_time, SEC_TO_TIME(120 * 60)), '%H:%i:%s') as end_time,
            c.course_code,
            c.course_name,
            cr.room_number,
            CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM enrollments e
        JOIN schedules s ON e.schedule_id = s.id
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        JOIN users u ON s.instructor_id = u.id
        WHERE e.student_id = ? AND e.status = 'enrolled'
        ORDER BY 
            FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
            s.start_time
    ");
    $stmt->execute([$student_id]);
    $schedules = $stmt->fetchAll();

    // Group schedules by day
    $schedule_by_day = [];
    foreach ($schedules as $schedule) {
        $day = $schedule['day_of_week'];
        if (!isset($schedule_by_day[$day])) {
            $schedule_by_day[$day] = [];
        }
        $schedule_by_day[$day][] = $schedule;
    }

} catch (Exception $e) {
    error_log("Error in student schedule: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while fetching the schedule.";
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'students.php' : '../student/dashboard.php'));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Schedule - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .schedule-card {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h2>Student Schedule</h2>
            <div>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Schedule
                </button>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="students.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Students
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Student Information</h5>
                <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
            </div>
        </div>

        <div class="row">
            <?php
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            foreach ($days as $day):
                $day_schedules = $schedule_by_day[$day] ?? [];
            ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card schedule-card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><?php echo $day; ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($day_schedules)): ?>
                            <p class="text-muted">No classes scheduled</p>
                        <?php else: ?>
                            <?php foreach ($day_schedules as $schedule): ?>
                            <div class="mb-3">
                                <h6 class="mb-1"><?php echo htmlspecialchars($schedule['course_code'] . ' - ' . $schedule['course_name']); ?></h6>
                                <p class="mb-1">
                                    <i class="bi bi-clock"></i> 
                                    <?php echo date('g:i A', strtotime($schedule['start_time'])) . ' - ' . date('g:i A', strtotime($schedule['end_time'])); ?>
                                </p>
                                <p class="mb-1">
                                    <i class="bi bi-person-video3"></i> 
                                    <?php echo htmlspecialchars($schedule['instructor_name']); ?>
                                </p>
                                <p class="mb-0">
                                    <i class="bi bi-door-open"></i> 
                                    Room <?php echo htmlspecialchars($schedule['room_number']); ?>
                                </p>
                            </div>
                            <?php if (!$loop->last): ?>
                            <hr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 