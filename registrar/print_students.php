<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin or super admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.username,
            COUNT(e.id) AS enrolled_classes
        FROM users u
        LEFT JOIN enrollments e ON e.student_id = u.id
        WHERE u.role = 'student'
        GROUP BY u.id, u.first_name, u.last_name, u.email, u.username
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Print students error: ' . $e->getMessage());
    die('An error occurred while loading the student list.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List Report - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                font-size: 12px;
            }
            .table {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <h4 class="mb-0">Student List Report</h4>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                Print List
            </button>
        </div>

        <div class="mb-3">
            <h5>Philippine College of Technology</h5>
            <p class="mb-0">Generated on: <?php echo date('F d, Y h:i A'); ?></p>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Username</th>
                        <th>Enrolled Classes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="5" class="text-center">No students found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo (int) $student['id']; ?></td>
                                <td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['email']); ?></td>
                                <td><?php echo htmlspecialchars($student['username']); ?></td>
                                <td><?php echo (int) $student['enrolled_classes']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
