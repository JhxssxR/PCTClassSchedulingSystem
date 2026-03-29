<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Check if user is logged in and has instructor role
require_role('instructor');

require_once __DIR__ . '/notifications_data.php';

// Check if schedule_id is provided
if (!isset($_GET['schedule_id'])) {
    header('Location: dashboard.php');
    exit();
}

$schedule_id = $_GET['schedule_id'];

try {
    // Get schedule details and verify ownership
    $stmt = $conn->prepare("
        SELECT s.*, c.course_code, c.course_name, cl.room_number
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cl ON s.classroom_id = cl.id
            WHERE s.id = ? AND s.instructor_id = ?
    ");
    $stmt->execute([$schedule_id, $_SESSION['user_id']]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$schedule) {
        $_SESSION['error_message'] = "Schedule not found or access denied.";
        header('Location: dashboard.php');
        exit();
    }

    // Get enrolled students
    $stmt = $conn->prepare("
        SELECT u.*, e.enrolled_at, e.status
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        WHERE e.schedule_id = ?
        ORDER BY u.last_name, u.first_name
    ");
    error_log("Executing student query for schedule_id: " . $schedule_id);
    $stmt->execute([$schedule_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Found " . count($students) . " students in total");

    // Debug: Check enrollment statuses
    $status_stmt = $conn->prepare("
        SELECT status, COUNT(*) as count 
        FROM enrollments 
        WHERE schedule_id = ? 
        GROUP BY status
    ");
    $status_stmt->execute([$schedule_id]);
    $status_counts = $status_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Enrollment status counts: " . print_r($status_counts, true));

} catch(PDOException $e) {
    error_log("Error in view_students.php: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred while loading the student list.";
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Students - <?php echo htmlspecialchars($schedule['course_code']); ?></title>
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

        @media print {
            .no-print {
                display: none !important;
            }
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1"><?php echo htmlspecialchars($schedule['course_code'] . ' - ' . $schedule['course_name']); ?></h2>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($schedule['day_of_week'] . ' at ' . date('g:i A', strtotime($schedule['start_time']))); ?>
                    </p>
                </div>
                <div class="d-flex gap-2 no-print">
                    <a href="export_students.php?schedule_id=<?php echo urlencode($schedule_id); ?>" class="btn btn-outline-success">
                        <i class="bi bi-file-earmark-excel"></i> Export
                    </a>
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Schedule Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Schedule Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Schedule:</strong></p>
                            <p><?php echo htmlspecialchars($schedule['day_of_week'] . ' at ' . date('g:i A', strtotime($schedule['start_time']))); ?></p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Room:</strong></p>
                            <p>Room <?php echo htmlspecialchars($schedule['room_number']); ?></p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Enrolled Students:</strong></p>
                            <p><?php echo count($students); ?> / <?php echo $schedule['max_students']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Enrolled Students</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($students)): ?>
                        <p class="text-muted text-center py-3">No students enrolled yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Enrolled Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $student['status'] === 'approved' ? 'success' : 
                                                        ($student['status'] === 'pending' ? 'warning' : 
                                                        ($student['status'] === 'dropped' ? 'danger' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst($student['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($student['enrolled_at'])) {
                                                    echo date('M d, Y', strtotime($student['enrolled_at']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 