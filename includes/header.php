<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PCT Class Scheduling System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
            padding: 1rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar .nav-link {
            color: #333;
            padding: 0.5rem 1rem;
            margin-bottom: 0.25rem;
            border-radius: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .sidebar .nav-link:hover {
            background-color: #e9ecef;
        }
        
        .sidebar .nav-link.active {
            background-color: #0d6efd;
            color: white;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 2rem;
            flex: 1;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">PCT Scheduling</a>
            <?php if (isset($_SESSION['user_id'])): ?>
            <div class="navbar-text text-white">
                Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!
            </div>
            <?php endif; ?>
        </div>
    </nav>
    <div class="sidebar">
        <div class="user-info">
            <img src="../pctlogo.png" alt="PCT Logo" style="width: 100px; margin-bottom: 10px;">
            <h5><?php echo $_SESSION['full_name']; ?></h5>
            <small><?php echo ucfirst($_SESSION['role']); ?></small>
        </div>
        <nav class="nav flex-column">
            <?php if ($_SESSION['role'] === 'super_admin'): ?>
                <a href="../admin/dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="../admin/users.php" class="nav-link"><i class="bi bi-people"></i> Manage Users</a>
                <a href="../admin/courses.php" class="nav-link"><i class="bi bi-book"></i> Manage Courses</a>
                <a href="../admin/classrooms.php" class="nav-link"><i class="bi bi-building"></i> Manage Classrooms</a>
                <a href="../admin/schedules.php" class="nav-link"><i class="bi bi-calendar3"></i> View Schedules</a>
                <a href="../admin/reports.php" class="nav-link"><i class="bi bi-file-text"></i> Reports</a>
            <?php elseif (in_array($_SESSION['role'], ['admin', 'registrar'], true)): ?>
                <a href="../registrar/dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="../registrar/schedules.php" class="nav-link"><i class="bi bi-calendar3"></i> Manage Schedules</a>
                <a href="../registrar/courses.php" class="nav-link"><i class="bi bi-book"></i> Manage Courses</a>
                <a href="../registrar/enrollments.php" class="nav-link"><i class="bi bi-person-check"></i> Enrollments</a>
                <a href="../registrar/reports.php" class="nav-link"><i class="bi bi-file-text"></i> Reports</a>
            <?php elseif ($_SESSION['role'] === 'instructor'): ?>
                <a href="../instructor/dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="../instructor/schedule.php" class="nav-link"><i class="bi bi-calendar3"></i> My Schedule</a>
                <a href="../instructor/classes.php" class="nav-link"><i class="bi bi-journal-text"></i> My Classes</a>
            <?php elseif ($_SESSION['role'] === 'student'): ?>
                <a href="../student/dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="../student/schedule.php" class="nav-link"><i class="bi bi-calendar3"></i> My Schedule</a>
                <a href="../student/courses.php" class="nav-link"><i class="bi bi-book"></i> Available Courses</a>
                <a href="../student/enrollment.php" class="nav-link"><i class="bi bi-person-check"></i> Enrollment</a>
            <?php endif; ?>
            <a href="../auth/logout.php" class="nav-link mt-4"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </nav>
    </div>
    <div class="content"> 