<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an instructor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

// Get instructor's classes with enrollment statistics
$stmt = $conn->prepare("
    SELECT 
        s.*,
        c.course_code,
        c.course_name,
        c.credits,
        cl.room_number,
        COUNT(DISTINCT e.id) as enrolled_students,
        GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') as student_names
    FROM schedules s
    JOIN courses c ON s.course_id = c.id
    JOIN classrooms cl ON s.classroom_id = cl.id
    LEFT JOIN enrollments e ON s.id = e.schedule_id
    LEFT JOIN users u ON e.student_id = u.id
    WHERE s.instructor_id = :instructor_id
    AND s.status = 'active'
    GROUP BY s.id
    ORDER BY c.course_code, s.day_of_week, s.start_time
");

$stmt->execute(['instructor_id' => $_SESSION['user_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Log class data
error_log("Classes data: " . print_r($classes, true));

// Calculate statistics
$total_classes = count($classes);
$total_students = array_sum(array_column($classes, 'enrolled_students'));
$avg_class_size = $total_classes > 0 ? round($total_students / $total_classes, 1) : 0;

// Debug: Log statistics
error_log("Total classes: " . $total_classes);
error_log("Total students: " . $total_students);
error_log("Average class size: " . $avg_class_size);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classes - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .class-card {
            border-left: 4px solid #2c5d3f;
            transition: transform 0.2s;
        }
        .class-card:hover {
            transform: translateY(-2px);
        }
        .stat-card {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'classes';
    include '../includes/sidebar.php'; 
    ?>

    <div class="main-content">
        <div class="container py-4">
            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <h6 class="text-muted mb-2">Total Classes</h6>
                        <h3 class="mb-0"><?php echo $total_classes; ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <h6 class="text-muted mb-2">Total Students</h6>
                        <h3 class="mb-0"><?php echo $total_students; ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <h6 class="text-muted mb-2">Average Class Size</h6>
                        <h3 class="mb-0"><?php echo $avg_class_size; ?></h3>
                    </div>
                </div>
            </div>

            <!-- Classes List -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">My Classes</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($classes)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    You don't have any classes assigned yet.
                                </div>
                            <?php else: ?>
                                <div class="row g-4">
                                    <?php foreach($classes as $class): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card class-card h-100">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <?php echo htmlspecialchars($class['course_code'] . ' - ' . $class['course_name']); ?>
                                                    </h6>
                                                    <p class="card-text">
                                                        <i class="bi bi-calendar"></i>
                                                        <?php echo $class['day_of_week']; ?>
                                                    </p>
                                                    <p class="card-text">
                                                        <i class="bi bi-clock"></i>
                                                        <?php 
                                                        echo date('g:i A', strtotime($class['start_time'])) . ' - ' . 
                                                             date('g:i A', strtotime($class['start_time'] . ' +2 hours'));
                                                        ?>
                                                    </p>
                                                    <p class="card-text">
                                                        <i class="bi bi-geo-alt"></i>
                                                        Room <?php echo htmlspecialchars($class['room_number']); ?>
                                                    </p>
                                                    <p class="card-text">
                                                        <i class="bi bi-people"></i>
                                                        <?php echo $class['enrolled_students']; ?> / <?php echo $class['max_students']; ?> Students
                                                    </p>
                                                    <p class="card-text">
                                                        <i class="bi bi-book"></i>
                                                        <?php echo $class['credits']; ?> Credits
                                                    </p>
                                                    <div class="mt-3">
                                                        <a href="view_students.php?schedule_id=<?php echo $class['id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="bi bi-person-lines-fill"></i> View Students
                                                        </a>
                                                    </div>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 