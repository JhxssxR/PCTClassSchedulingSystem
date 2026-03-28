<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Check if user is logged in and has instructor role
require_role('instructor');

require_once __DIR__ . '/notifications_data.php';

// Get instructor's schedule
$stmt = $conn->prepare("
    SELECT 
        s.*,
        c.course_code,
        c.course_name,
        cl.room_number,
        COUNT(e.id) as enrolled_students
    FROM schedules s
    JOIN courses c ON s.course_id = c.id
    JOIN classrooms cl ON s.classroom_id = cl.id
    LEFT JOIN enrollments e ON s.id = e.schedule_id AND e.status = 'approved'
    WHERE s.instructor_id = :instructor_id
    AND s.status = 'active'
    GROUP BY s.id
    ORDER BY 
        FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
        s.start_time
");

$stmt->execute(['instructor_id' => $_SESSION['user_id']]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's schedule
$today = date('l');
$stmt = $conn->prepare("
    SELECT 
        s.*,
        c.course_code,
        c.course_name,
        cl.room_number,
        COUNT(e.id) as enrolled_students
    FROM schedules s
    JOIN courses c ON s.course_id = c.id
    JOIN classrooms cl ON s.classroom_id = cl.id
    LEFT JOIN enrollments e ON s.id = e.schedule_id AND e.status = 'approved'
    WHERE s.instructor_id = :instructor_id
    AND s.status = 'active'
    AND s.day_of_week = :day_of_week
    GROUP BY s.id
    ORDER BY s.start_time
");

$stmt->execute([
    'instructor_id' => $_SESSION['user_id'],
    'day_of_week' => $today
]);
$today_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group schedules by day
$schedule_by_day = [];
foreach ($schedules as $schedule) {
    $day = $schedule['day_of_week'];
    if (!isset($schedule_by_day[$day])) {
        $schedule_by_day[$day] = [];
    }
    $schedule_by_day[$day][] = $schedule;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c5d3f;
            --secondary: #7bc26f;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }

        .navbar {
            background-color: var(--primary);
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background-color: var(--primary);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #234a2a;
            border-color: #234a2a;
        }

        .schedule-card {
            transition: transform 0.2s;
        }

        .schedule-card:hover {
            transform: translateY(-5px);
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'my_schedule';
    include '../includes/sidebar.php'; 
    ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Today's Schedule -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Today's Schedule (<?php echo $today; ?>)</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($today_schedule)): ?>
                                <p class="text-muted text-center py-3">No classes scheduled for today.</p>
                            <?php else: ?>
                                <div class="row g-4">
                                    <?php foreach($today_schedule as $schedule): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card schedule-card h-100">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <?php echo htmlspecialchars($schedule['course_code'] . ' - ' . $schedule['course_name']); ?>
                                                    </h6>
                                                    <p class="card-text">
                                                        <i class="bi bi-clock"></i>
                                                        <?php 
                                                        echo date('g:i A', strtotime($schedule['start_time'])) . ' - ' . 
                                                             date('g:i A', strtotime($schedule['start_time'] . ' +2 hours'));
                                                        ?>
                                                    </p>
                                                    <p class="card-text">
                                                        <i class="bi bi-geo-alt"></i>
                                                        Room <?php echo htmlspecialchars($schedule['room_number']); ?>
                                                    </p>
                                                    <p class="card-text">
                                                        <i class="bi bi-people"></i>
                                                        <?php echo $schedule['enrolled_students']; ?> / <?php echo $schedule['max_students']; ?> Students
                                                    </p>
                                                    <a href="view_students.php?schedule_id=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-person-lines-fill"></i> View Students
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly Schedule -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Weekly Schedule</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                            foreach ($days as $day):
                                $day_schedules = $schedule_by_day[$day] ?? [];
                            ?>
                                <div class="mb-4">
                                    <h6 class="mb-3"><?php echo $day; ?></h6>
                                    <?php if (empty($day_schedules)): ?>
                                        <p class="text-muted">No classes scheduled</p>
                                    <?php else: ?>
                                        <div class="row g-4">
                                            <?php foreach ($day_schedules as $schedule): ?>
                                                <div class="col-md-6 col-lg-4">
                                                    <div class="card schedule-card h-100">
                                                        <div class="card-body">
                                                            <h6 class="card-title">
                                                                <?php echo htmlspecialchars($schedule['course_code'] . ' - ' . $schedule['course_name']); ?>
                                                            </h6>
                                                            <p class="card-text">
                                                                <i class="bi bi-clock"></i>
                                                                <?php 
                                                                echo date('g:i A', strtotime($schedule['start_time'])) . ' - ' . 
                                                                     date('g:i A', strtotime($schedule['start_time'] . ' +2 hours'));
                                                                ?>
                                                            </p>
                                                            <p class="card-text">
                                                                <i class="bi bi-geo-alt"></i>
                                                                Room <?php echo htmlspecialchars($schedule['room_number']); ?>
                                                            </p>
                                                            <p class="card-text">
                                                                <i class="bi bi-people"></i>
                                                                <?php echo $schedule['enrolled_students']; ?> / <?php echo $schedule['max_students']; ?> Students
                                                            </p>
                                                            <a href="view_students.php?schedule_id=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-primary">
                                                                <i class="bi bi-person-lines-fill"></i> View Students
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 