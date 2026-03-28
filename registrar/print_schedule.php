<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    // Get all schedules with related information
    $stmt = $conn->prepare("
        SELECT s.*, 
               c.course_code,
               c.course_name,
               cr.room_number,
               cr.capacity,
               u.first_name as instructor_first_name,
               u.last_name as instructor_last_name,
               TIME_FORMAT(ADDTIME(s.start_time, SEC_TO_TIME(120 * 60)), '%H:%i:%s') as end_time
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        JOIN users u ON s.instructor_id = u.id
        ORDER BY s.day_of_week ASC, s.start_time ASC
    ");
    $stmt->execute();
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group schedules by day
    $schedules_by_day = [];
    foreach ($schedules as $schedule) {
        $schedules_by_day[$schedule['day_of_week']][] = $schedule;
    }
} catch (PDOException $e) {
    error_log("Print Error: " . $e->getMessage());
    die("An error occurred while loading schedules.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Schedule Report - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none;
            }
            .page-break {
                page-break-before: always;
            }
            body {
                font-size: 12pt;
            }
            .table {
                font-size: 11pt;
            }
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .header img {
            max-width: 100px;
            margin-bottom: 1rem;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .day-header {
            background-color: #2c5d3f;
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Print Button -->
        <div class="text-end mb-4 no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Schedule
            </button>
        </div>

        <!-- Header -->
        <div class="header">
            <img src="../pctlogo.png" alt="PCT Logo">
            <h1>Philippine College of Technology</h1>
            <h2>Class Schedule Report</h2>
            <p>Generated on: <?php echo date('F d, Y h:i A'); ?></p>
        </div>

        <!-- Schedule by Day -->
        <?php foreach ($schedules_by_day as $day => $day_schedules): ?>
            <div class="day-header">
                <h4 class="mb-0"><?php echo htmlspecialchars($day); ?></h4>
            </div>
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Course</th>
                            <th>Subject</th>
                            <th>Instructor</th>
                            <th>Room</th>
                            <th>Capacity</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($day_schedules as $schedule): ?>
                            <tr>
                                <td>
                                    <?php 
                                    echo date('g:i A', strtotime($schedule['start_time'])) . ' - ' . 
                                         date('g:i A', strtotime($schedule['end_time'])); 
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($schedule['course_code']); ?></td>
                                <td><?php echo htmlspecialchars($schedule['course_name']); ?></td>
                                <td>
                                    <?php 
                                    echo htmlspecialchars($schedule['instructor_first_name'] . ' ' . 
                                                         $schedule['instructor_last_name']); 
                                    ?>
                                </td>
                                <td>Room <?php echo htmlspecialchars($schedule['room_number']); ?></td>
                                <td><?php echo htmlspecialchars($schedule['capacity']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $schedule['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($schedule['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- Summary -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Schedule Summary</h5>
                        <table class="table table-sm">
                            <tr>
                                <td>Total Classes:</td>
                                <td><?php echo count($schedules); ?></td>
                            </tr>
                            <tr>
                                <td>Active Classes:</td>
                                <td><?php echo count(array_filter($schedules, function($s) { return $s['status'] === 'active'; })); ?></td>
                            </tr>
                            <tr>
                                <td>Inactive Classes:</td>
                                <td><?php echo count(array_filter($schedules, function($s) { return $s['status'] === 'inactive'; })); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 