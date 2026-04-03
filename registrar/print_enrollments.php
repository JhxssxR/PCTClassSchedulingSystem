<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    // Get all enrollments with related information
    $stmt = $conn->prepare("
        SELECT 
            s.first_name as student_first_name,
            s.last_name as student_last_name,
            s.email as student_email,
            c.course_code,
            c.course_name,
            sch.day_of_week,
            sch.start_time,
            TIME_FORMAT(ADDTIME(sch.start_time, SEC_TO_TIME(120 * 60)), '%H:%i:%s') as end_time,
            u.first_name as instructor_first_name,
            u.last_name as instructor_last_name,
            cr.room_number,
            e.status,
            e.enrolled_at,
            e.dropped_at,
            e.rejected_at
        FROM enrollments e
        JOIN users s ON e.student_id = s.id
        JOIN schedules sch ON e.schedule_id = sch.id
        JOIN courses c ON sch.course_id = c.id
        JOIN users u ON sch.instructor_id = u.id
        JOIN classrooms cr ON sch.classroom_id = cr.id
        ORDER BY e.enrolled_at DESC
    ");
    $stmt->execute();
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Print Error: " . $e->getMessage());
    die("An error occurred while loading enrollments.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollments Report - PCT</title>
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
        .status-badge {
            font-size: 0.8rem;
            padding: 0.35em 0.65em;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Print Button -->
        <div class="text-end mb-4 no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>

        <!-- Header -->
        <div class="header">
            <img src="../pctlogo.png" alt="PCT Logo">
            <h1>Philippine College of Technology</h1>
            <h2>Enrollment Report</h2>
            <p>Generated on: <?php echo date('F d, Y h:i A'); ?></p>
        </div>

        <!-- Enrollments Table -->
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>Course</th>
                        <th>Schedule</th>
                        <th>Instructor</th>
                        <th>Room</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrollments as $enrollment): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($enrollment['student_last_name'] . ', ' . $enrollment['student_first_name']); ?></td>
                        <td><?php echo htmlspecialchars($enrollment['student_email']); ?></td>
                        <td><?php echo htmlspecialchars($enrollment['course_code'] . ' - ' . $enrollment['course_name']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($enrollment['day_of_week'] . ' ' . 
                                date('g:i A', strtotime($enrollment['start_time'])) . ' - ' . 
                                date('g:i A', strtotime($enrollment['end_time']))); ?>
                        </td>
                        <td><?php echo htmlspecialchars($enrollment['instructor_last_name'] . ', ' . $enrollment['instructor_first_name']); ?></td>
                        <td>Room <?php echo htmlspecialchars($enrollment['room_number']); ?></td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $enrollment['status'] === 'approved' ? 'success' : 
                                    ($enrollment['status'] === 'pending' ? 'warning' : 
                                    ($enrollment['status'] === 'dropped' ? 'danger' : 'secondary')); 
                            ?> status-badge">
                                <?php echo ucfirst($enrollment['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            switch ($enrollment['status']) {
                                case 'approved':
                                case 'enrolled':
                                    echo date('M d, Y', strtotime($enrollment['enrolled_at']));
                                    break;
                                case 'dropped':
                                    echo date('M d, Y', strtotime($enrollment['dropped_at']));
                                    break;
                                case 'rejected':
                                    echo date('M d, Y', strtotime($enrollment['rejected_at']));
                                    break;
                                default:
                                    echo !empty($enrollment['enrolled_at']) ? date('M d, Y', strtotime($enrollment['enrolled_at'])) : 'N/A';
                                    break;
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Summary -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Enrollment Summary</h5>
                        <table class="table table-sm">
                            <tr>
                                <td>Total Enrollments:</td>
                                <td><?php echo count($enrollments); ?></td>
                            </tr>
                            <tr>
                                <td>Active Enrollments:</td>
                                <td><?php echo count(array_filter($enrollments, function($e) { return in_array($e['status'], ['approved', 'enrolled']); })); ?></td>
                            </tr>
                            <tr>
                                <td>Dropped Classes:</td>
                                <td><?php echo count(array_filter($enrollments, function($e) { return $e['status'] === 'dropped'; })); ?></td>
                            </tr>
                            <tr>
                                <td>Rejected Enrollments:</td>
                                <td><?php echo count(array_filter($enrollments, function($e) { return $e['status'] === 'rejected'; })); ?></td>
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