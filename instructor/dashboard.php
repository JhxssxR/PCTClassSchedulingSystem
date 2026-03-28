<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and has instructor role
require_role('instructor');

require_once __DIR__ . '/notifications_data.php';

// Initialize variables
$instructor = null;
$total_classes = 0;
$total_students = 0;
$today_classes = 0;
$today_schedule = [];
$all_classes = [];
$upcoming_classes = [];
$class_stats = [];
$today = date('l');

try {
    // Get instructor information
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'instructor'");
    $stmt->execute([$_SESSION['user_id']]);
    $instructor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$instructor) {
        clear_session();
        header('Location: ../auth/login.php?role=instructor');
        exit();
    }

    // Get instructor's statistics
    // Total classes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM schedules 
        WHERE instructor_id = ? AND status = 'active'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $total_classes = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Total students
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.student_id) as count 
        FROM enrollments e 
        JOIN schedules s ON e.schedule_id = s.id 
        WHERE s.instructor_id = ? AND e.status = 'approved'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $total_students = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Today's classes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM schedules 
        WHERE instructor_id = ? AND day_of_week = ? AND status = 'active'
    ");
    $stmt->execute([$_SESSION['user_id'], $today]);
    $today_classes = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Get today's schedule with more details
    $stmt = $conn->prepare("
        SELECT 
            s.*,
            c.course_code,
            c.course_name,
            c.credits,
            cl.room_number,
            cl.capacity,
            (SELECT COUNT(*) FROM enrollments e WHERE e.schedule_id = s.id AND e.status = 'approved') as enrolled_students,
            (SELECT COUNT(*) FROM enrollments e WHERE e.schedule_id = s.id AND e.status = 'waitlisted') as waitlisted_students
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cl ON s.classroom_id = cl.id
        WHERE s.instructor_id = ? 
        AND s.day_of_week = ? 
        AND s.status = 'active'
        ORDER BY s.start_time ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $today]);
    $today_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug log the schedule data
    error_log("Today's schedule data: " . print_r($today_schedule, true));

    // Get upcoming classes
    $upcomingClasses = [];
    try {
        $stmt = $conn->prepare("
            SELECT s.*, c.course_code, c.course_name, r.room_number, b.building_name
            FROM schedules s
            JOIN courses c ON s.course_id = c.id
            JOIN rooms r ON s.room_id = r.id
            JOIN buildings b ON r.building_id = b.id
            WHERE s.instructor_id = ?
            AND s.day_of_week = ?
            AND s.start_time > ?
            ORDER BY s.start_time ASC
            LIMIT 5
        ");
        
        $stmt->execute([
            $_SESSION['user_id'],
            $today,
            date('Y-m-d H:i:s')
        ]);
        
        $upcomingClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        debug_to_log("Found " . count($upcomingClasses) . " upcoming classes");
    } catch (PDOException $e) {
        debug_to_log("Error fetching upcoming classes: " . $e->getMessage());
    }

    // Get class statistics
    $stmt = $conn->prepare("
        SELECT 
            c.course_code,
            c.course_name,
            COUNT(DISTINCT s.id) as total_sections,
            COUNT(DISTINCT e.student_id) as total_students,
            AVG(
                (SELECT COUNT(*) FROM enrollments e2 
                 WHERE e2.schedule_id = s.id AND e2.status = 'approved')
            ) as avg_enrollment
        FROM courses c
        JOIN schedules s ON c.id = s.course_id
        LEFT JOIN enrollments e ON s.id = e.schedule_id AND e.status = 'approved'
        WHERE s.instructor_id = ? AND s.status = 'active'
        GROUP BY c.id
        ORDER BY total_students DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $class_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(Exception $e) {
    error_log("Error in instructor dashboard: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred while loading the dashboard. Please try again later.";
    header('Location: ../auth/logout.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c5d3f;
            --secondary: #7bc26f;
            --accent: #e8f5e9;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
        }

        .navbar {
            background-color: var(--primary);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            color: white !important;
            font-weight: 600;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.85) !important;
            font-weight: 500;
        }

        .nav-link:hover {
            color: white !important;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background-color: var(--primary);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 600;
        }

        .stats-card {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
        }

        .stats-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }

        .schedule-card {
            border-left: 4px solid var(--primary);
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            font-weight: 500;
        }

        .btn-primary:hover {
            background-color: #234a2a;
            border-color: #234a2a;
        }

        .progress {
            height: 8px;
            border-radius: 4px;
        }

        .progress-bar {
            background-color: var(--secondary);
        }

        .badge {
            font-weight: 500;
            padding: 0.5em 0.8em;
        }

        .table th {
            background-color: var(--accent);
            font-weight: 600;
        }

        .welcome-section {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .quick-action-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: background-color 0.2s;
        }

        .quick-action-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
            color: white;
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'dashboard';
    include '../includes/sidebar.php'; 
    ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h2>Welcome, <?php echo htmlspecialchars($instructor['first_name']); ?>!</h2>
                <p class="mb-0">Here's your teaching schedule and class overview for today.</p>
                <div class="quick-actions">
                    <a href="my_schedule.php" class="quick-action-btn">
                        <i class="bi bi-calendar3"></i> View Full Schedule
                    </a>
                    <a href="my_classes.php" class="quick-action-btn">
                        <i class="bi bi-journal-text"></i> My Classes
                    </a>
                    <a href="profile.php" class="quick-action-btn">
                        <i class="bi bi-person"></i> Update Profile
                    </a>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="card stats-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Total Classes</h6>
                                    <h2 class="card-title mb-0"><?php echo $total_classes; ?></h2>
                                </div>
                                <i class="bi bi-journal-text stats-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card stats-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Total Students</h6>
                                    <h2 class="card-title mb-0"><?php echo $total_students; ?></h2>
                                </div>
                                <i class="bi bi-people stats-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card stats-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">Today's Classes</h6>
                                    <h2 class="card-title mb-0"><?php echo $today_classes; ?></h2>
                                </div>
                                <i class="bi bi-calendar-check stats-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Today's Schedule -->
                <div class="col-md-8 mb-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Today's Schedule (<?php echo $today; ?>)</h5>
                            <span class="badge bg-light text-dark">
                                <?php echo count($today_schedule); ?> Classes
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($today_schedule)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    No classes scheduled for today.
                                </div>
                            <?php else: ?>
                                <?php foreach($today_schedule as $schedule): ?>
                                    <div class="card schedule-card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title mb-1">
                                                        <?php echo htmlspecialchars($schedule['course_code'] . ' - ' . $schedule['course_name']); ?>
                                                    </h6>
                                                    <p class="card-text mb-1">
                                                        <i class="bi bi-clock"></i>
                                                        <?php 
                                                        echo date('g:i A', strtotime($schedule['start_time'])) . ' - ' . 
                                                             date('g:i A', strtotime($schedule['start_time'] . ' +2 hours'));
                                                        ?>
                                                    </p>
                                                    <p class="card-text mb-1">
                                                        <i class="bi bi-geo-alt"></i>
                                                        Room <?php echo htmlspecialchars($schedule['room_number']); ?>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-primary mb-2">
                                                        <?php echo $schedule['enrolled_students']; ?> / <?php echo $schedule['max_students']; ?> Students
                                                    </span>
                                                    <?php if ($schedule['waitlisted_students'] > 0): ?>
                                                        <span class="badge bg-warning text-dark">
                                                            <?php echo $schedule['waitlisted_students']; ?> Waitlisted
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="progress mt-2">
                                                <div class="progress-bar" role="progressbar" 
                                                     style="width: <?php echo ($schedule['enrolled_students'] / $schedule['max_students']) * 100; ?>%">
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <a href="view_students.php?schedule_id=<?php echo htmlspecialchars($schedule['id']); ?>" 
                                                   class="btn btn-sm btn-primary"
                                                   onclick="console.log('Viewing students for schedule: <?php echo $schedule['id']; ?>')">
                                                    <i class="bi bi-person-lines-fill"></i> View Students
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Classes and Stats -->
                <div class="col-md-4">
                    <!-- Upcoming Classes -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Upcoming Classes</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcomingClasses)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    No upcoming classes.
                                </div>
                            <?php else: ?>
                                <?php foreach($upcomingClasses as $class): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($class['course_code']); ?></h6>
                                            <small class="text-muted">
                                                <?php echo $class['day_of_week']; ?> at 
                                                <?php echo date('g:i A', strtotime($class['start_time'])); ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-primary">
                                            <?php echo $class['enrolled_students']; ?> Students
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Class Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Class Statistics</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($class_stats)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    No class statistics available.
                                </div>
                            <?php else: ?>
                                <?php foreach($class_stats as $stat): ?>
                                    <div class="mb-3">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($stat['course_code']); ?></h6>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <?php echo $stat['total_sections']; ?> Sections
                                            </small>
                                            <small class="text-muted">
                                                Avg: <?php echo round($stat['avg_enrollment']); ?> Students
                                            </small>
                                        </div>
                                        <div class="progress mt-1">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: <?php echo ($stat['total_students'] / ($stat['total_sections'] * 30)) * 100; ?>%">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 