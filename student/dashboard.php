<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../auth/login.php?role=student');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

// Get student information and class schedule in a single query
try {
    // Get student info
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch();

    // Check if there are any active schedules
    $schedule_check = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE status = 'active'");
    $schedule_check->execute();
    $active_schedules = $schedule_check->fetchColumn();
    error_log("Number of active schedules: " . $active_schedules);

    // Get class schedule
    $stmt = $conn->prepare("
        SELECT 
            c.course_code as subject_code,
            c.course_name as subject_name,
            s.day_of_week as schedule_day,
            s.start_time as schedule_time,
            cr.room_number as room,
            i.first_name as instructor_first_name,
            i.last_name as instructor_last_name,
            s.status as schedule_status
        FROM enrollments e
        JOIN schedules s ON e.schedule_id = s.id
        JOIN courses c ON s.course_id = c.id
        JOIN users i ON s.instructor_id = i.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        WHERE e.student_id = ? 
        AND e.status = 'approved'
        AND s.status = 'active'
        ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
                 s.start_time
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $schedule = $stmt->fetchAll();
    
    // Add debug logging
    error_log("Student ID: " . $_SESSION['user_id']);
    error_log("Number of classes found: " . count($schedule));
    
    // Check if there are any enrollments at all for this student
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ?");
    $check_stmt->execute([$_SESSION['user_id']]);
    $total_enrollments = $check_stmt->fetchColumn();
    error_log("Total enrollments for student: " . $total_enrollments);
    
    // Check enrollments by status
    $status_stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM enrollments WHERE student_id = ? GROUP BY status");
    $status_stmt->execute([$_SESSION['user_id']]);
    $status_counts = $status_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Enrollment status counts: " . print_r($status_counts, true));

    // Group classes by day for better organization
    $schedule_by_day = [];
    foreach ($schedule as $class) {
        $schedule_by_day[$class['schedule_day']][] = $class;
    }
} catch(PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $schedule = [];
    $schedule_by_day = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Schedule - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c5d3f;
            --secondary: #7bc26f;
        }
        body {
            background-color: #f8f9fa;
            padding-top: 56px;
        }
        .navbar {
            background-color: var(--primary);
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .day-header {
            background-color: var(--primary);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .class-card {
            border-left: 4px solid var(--secondary);
            margin-bottom: 15px;
        }
        .time-badge {
            background-color: var(--secondary);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        .subject-code {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--primary);
        }
        .subject-name {
            font-size: 0.9rem;
            color: #666;
        }
        .schedule-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0;
        }
        .schedule-icon {
            color: var(--primary);
            width: 20px;
        }
        @media print {
            .no-print {
                display: none;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }
            .day-header {
                border: 1px solid #ddd;
                color: #000;
                background-color: #f8f9fa !important;
                break-after: avoid;
            }
            .class-card {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark fixed-top no-print">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <img src="../pctlogo.png" alt="PCT Logo" height="30" class="d-inline-block align-text-top">
                PCT Student Portal
            </a>
            <div class="d-flex align-items-center">
                <div class="dropdown me-2">
                    <button class="btn btn-outline-light position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?php echo (($notif_unread_total ?? 0) > 0) ? '' : 'd-none'; ?>">
                            <?php echo htmlspecialchars($notif_badge_label ?? ''); ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end p-0" style="width: 360px;">
                        <li class="px-3 py-2 border-bottom d-flex align-items-center justify-content-between">
                            <span class="fw-semibold">Notifications</span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" onclick="pctNotifMarkRead(event)">Mark as read</button>
                                <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none p-0" onclick="pctNotifDelete(event)">Delete</button>
                            </div>
                        </li>
                        <?php if (empty($notif_items)): ?>
                            <li class="px-3 py-3 text-muted small">No new notifications</li>
                        <?php else: ?>
                            <?php foreach ($notif_items as $it): ?>
                                <li>
                                    <a class="dropdown-item py-2" href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>">
                                        <div class="d-flex gap-2">
                                            <div class="text-muted"><i class="bi <?php echo htmlspecialchars($it['icon'] ?? 'bi-bell'); ?>"></i></div>
                                            <div class="flex-grow-1">
                                                <div class="small fw-semibold"><?php echo htmlspecialchars($it['title'] ?? ''); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <button class="btn btn-outline-light me-2" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Schedule
                </button>
                <div class="dropdown">
                    <button class="btn btn-link text-light text-decoration-none dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($student['first_name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="bi bi-person"></i> Profile
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../auth/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Student Info Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col">
                        <h4 class="mb-1"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                        <p class="text-muted mb-0">Student ID: <?php echo htmlspecialchars($student['id']); ?></p>
                    </div>
                    <div class="col-auto no-print">
                        <span class="badge bg-primary">
                            <?php echo count($schedule); ?> Classes
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Section -->
        <?php if (empty($schedule)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-calendar-x display-1 text-muted"></i>
                    <h5 class="mt-3">No Classes Scheduled</h5>
                    <p class="text-muted">Please contact the registrar's office for assistance.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($schedule_by_day as $day => $classes): ?>
                <div class="day-header">
                    <h5 class="mb-0"><i class="bi bi-calendar-day"></i> <?php echo htmlspecialchars($day); ?></h5>
                </div>
                <?php foreach ($classes as $class): ?>
                    <div class="card class-card">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="subject-code"><?php echo htmlspecialchars($class['subject_code']); ?></div>
                                    <div class="subject-name"><?php echo htmlspecialchars($class['subject_name']); ?></div>
                                    <div class="schedule-info">
                                        <i class="bi bi-clock-fill schedule-icon"></i>
                                        <span><?php echo date('g:i A', strtotime($class['schedule_time'])); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="schedule-info">
                                        <i class="bi bi-door-open-fill schedule-icon"></i>
                                        <span>Room <?php echo htmlspecialchars($class['room']); ?></span>
                                    </div>
                                    <div class="schedule-info">
                                        <i class="bi bi-person-video3 schedule-icon"></i>
                                        <span><?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="schedule-info">
                                        <i class="bi bi-calendar3 schedule-icon"></i>
                                        <span>
                                            <?php echo htmlspecialchars($class['semester'] ?? 'Current Semester'); ?><br>
                                            <small class="text-muted">SY <?php echo htmlspecialchars($class['school_year'] ?? date('Y')); ?></small>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Profile Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form action="update_profile.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="fullname" 
                                   value="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($student['email']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password (leave blank to keep current)</label>
                            <input type="password" class="form-control" name="new_password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        async function pctPostNotifAction(action) {
            const res = await fetch('notifications_seen.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action })
            });
            return res.ok;
        }

        async function pctNotifMarkRead(e) {
            if (e) e.preventDefault();
            await pctPostNotifAction('seen');
            window.location.reload();
        }

        async function pctNotifDelete(e) {
            if (e) e.preventDefault();
            await pctPostNotifAction('delete');
            window.location.reload();
        }
    </script>
</body>
</html> 