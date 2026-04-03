<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar or the instructor themselves
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$instructor_id = isset($_GET['id']) ? $_GET['id'] : $_SESSION['user_id'];

// If not a registrar, only allow viewing own schedule
if (!in_array($_SESSION['role'], ['admin', 'registrar'], true) && $_SESSION['user_id'] != $instructor_id) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    // Get instructor information
    $stmt = $conn->prepare("
        SELECT first_name, last_name, email
        FROM users
        WHERE id = ? AND role = 'instructor'
    ");
    $stmt->execute([$instructor_id]);
    $instructor = $stmt->fetch();

    if (!$instructor) {
        throw new Exception("Instructor not found");
    }

    // Get instructor's schedule
    $stmt = $conn->prepare("
        SELECT 
            s.id,
            ts.slot_name,
            ts.start_time,
            TIME_FORMAT(ADDTIME(ts.start_time, SEC_TO_TIME(ts.duration_minutes * 60)), '%H:%i:%s') as end_time,
            c.course_code,
            c.course_name,
            cr.room_number,
            COUNT(e.id) as enrolled_students
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        JOIN time_slots ts ON s.time_slot_id = ts.id
        LEFT JOIN enrollments e ON s.id = e.schedule_id AND e.status = 'enrolled'
        WHERE s.instructor_id = ?
        GROUP BY s.id
        ORDER BY ts.start_time
    ");
    $stmt->execute([$instructor_id]);
    $schedules = $stmt->fetchAll();

    // Group schedules by time slot
    $schedule_by_slot = [];
    foreach ($schedules as $schedule) {
        $slot = $schedule['slot_name'];
        if (!isset($schedule_by_slot[$slot])) {
            $schedule_by_slot[$slot] = [];
        }
        $schedule_by_slot[$slot][] = $schedule;
    }

} catch (Exception $e) {
    error_log("Error in instructor schedule: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while fetching the schedule.";
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'instructors.php' : '../instructor/dashboard.php'));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Schedule - PCT</title>
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
            <h2>Instructor Schedule</h2>
            <div>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Schedule
                </button>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="instructors.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Instructors
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Instructor Information</h5>
                <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?></p>
                <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($instructor['email']); ?></p>
            </div>
        </div>

        <div class="row">
            <?php
            $slots = array_keys($schedule_by_slot);
            foreach ($slots as $slot):
                $slot_schedules = $schedule_by_slot[$slot] ?? [];
            ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card schedule-card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><?php echo $slot; ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($slot_schedules)): ?>
                            <p class="text-muted">No classes scheduled</p>
                        <?php else: ?>
                            <?php foreach ($slot_schedules as $schedule): ?>
                            <div class="mb-3">
                                <h6 class="mb-1"><?php echo htmlspecialchars($schedule['course_code'] . ' - ' . $schedule['course_name']); ?></h6>
                                <p class="mb-1">
                                    <i class="bi bi-clock"></i> 
                                    <?php echo date('g:i A', strtotime($schedule['start_time'])); ?> - <?php echo date('g:i A', strtotime($schedule['end_time'])); ?>
                                </p>
                                <p class="mb-1">
                                    <i class="bi bi-door-open"></i> 
                                    Room <?php echo htmlspecialchars($schedule['room_number']); ?>
                                </p>
                                <p class="mb-0">
                                    <i class="bi bi-people"></i> 
                                    <?php echo $schedule['enrolled_students']; ?> students enrolled
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