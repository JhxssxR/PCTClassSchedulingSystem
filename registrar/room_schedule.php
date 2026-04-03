<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: rooms.php');
    exit();
}

try {
    // Get room information
    $stmt = $conn->prepare("
        SELECT room_number, capacity, room_type, status
        FROM classrooms
        WHERE id = ?
    ");
    $stmt->execute([$_GET['id']]);
    $room = $stmt->fetch();

    if (!$room) {
        throw new Exception("Room not found");
    }

    // Get room's schedule
    $stmt = $conn->prepare("
        SELECT 
            s.id,
            s.day_of_week,
            s.start_time,
            ADDTIME(s.start_time, '02:00:00') as end_time,
            c.course_code,
            c.course_name,
            u.first_name,
            u.last_name,
            COUNT(e.id) as enrolled_students
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN users u ON s.instructor_id = u.id
        LEFT JOIN enrollments e ON s.id = e.schedule_id AND e.status = 'enrolled'
        WHERE s.classroom_id = ? AND s.status = 'active'
        GROUP BY s.id
        ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                s.start_time
    ");
    $stmt->execute([$_GET['id']]);
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
    error_log("Error in room schedule: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while fetching the schedule.";
    header('Location: rooms.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Schedule - PCT</title>
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
            <h2>Room Schedule</h2>
            <div>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Schedule
                </button>
                <a href="rooms.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Rooms
                </a>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Room Information</h5>
                <p class="mb-1"><strong>Room Number:</strong> <?php echo htmlspecialchars($room['room_number']); ?></p>
                <p class="mb-1"><strong>Type:</strong> <?php echo htmlspecialchars($room['room_type']); ?></p>
                <p class="mb-1"><strong>Capacity:</strong> <?php echo $room['capacity']; ?> students</p>
                <p class="mb-0">
                    <strong>Status:</strong> 
                    <span class="badge bg-<?php echo $room['status'] === 'active' ? 'success' : 'danger'; ?>">
                        <?php echo ucfirst($room['status']); ?>
                    </span>
                </p>
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
                                    <i class="bi bi-person"></i> 
                                    Instructor: <?php echo htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name']); ?>
                                </p>
                                <p class="mb-0">
                                    <i class="bi bi-people"></i> 
                                    <?php echo $schedule['enrolled_students']; ?> students enrolled
                                </p>
                            </div>
                            <?php if (!end($day_schedules) !== $schedule): ?>
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