<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

// Get system statistics
try {
    $stats = [
        'total_users' => $conn->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'students' => $conn->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
        'instructors' => $conn->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn(),
        'active_classes' => $conn->query("SELECT COUNT(*) FROM classes WHERE status = 'active'")->fetchColumn(),
        'total_enrollments' => $conn->query("SELECT COUNT(*) FROM enrollments WHERE status = 'enrolled'")->fetchColumn()
    ];
} catch(PDOException $e) {
    error_log("Error in admin dashboard: " . $e->getMessage());
    $stats = ['total_users' => 0, 'students' => 0, 'instructors' => 0, 'active_classes' => 0, 'total_enrollments' => 0];
}

// Get recent activities
try {
    $stmt = $conn->query("
        SELECT 
            e.id,
            e.created_at,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            c.subject_code,
            c.subject_name,
            e.status
        FROM enrollments e
        JOIN users s ON e.student_id = s.id
        JOIN classes c ON e.class_id = c.id
        ORDER BY e.created_at DESC
        LIMIT 5
    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching recent activities: " . $e->getMessage());
    $recent_activities = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <meta name="description" content="Classroom Scheduling System - Administrative Dashboard"/>
    <title>Super Admin Dashboard - Enhanced</title>

    <!-- Preload critical assets -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style"/>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" as="style"/>
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" as="style"/>

    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        :root {
            --primary: #2c5d3f;    /* PCT Green */
            --secondary: #7bc26f;   /* Light Green */
            --accent: #e3e8d8;     /* Light Gray-Green */
            --muted: #8a9a95;      /* Muted Green */
            --light-bg: #fdfdf9;   /* Off-White */
            --text-color: #344335;
        }

        body {
            background-color: var(--light-bg);
            color: var(--text-color);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            background-color: var(--primary);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .navbar-brand {
            color: #fff !important;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .navbar-brand img {
            height: 40px;
            width: auto;
        }

        .nav-link {
            color: #fff !important;
            opacity: 0.9;
        }

        .nav-link:hover,
        .nav-link.active {
            opacity: 1;
        }

        .main-content {
            flex: 1;
            padding: 2rem 0;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .section-tab {
            display: none;
        }

        .section-tab.active {
            display: block;
        }

        .settings-tab {
            display: none;
        }

        .settings-tab.active {
            display: block;
        }

        .toast-container {
            z-index: 1050;
        }

        @media (max-width: 768px) {
            .navbar-brand {
                font-size: 1.2rem;
            }
            .navbar-brand img {
                height: 30px;
            }
        }

        /* Dark mode styles */
        body.dark-mode {
            background-color: #212529;
            color: #f8f9fa;
        }

        body.dark-mode .card {
            background-color: #2c3034;
            border-color: #373b3e;
        }

        body.dark-mode .nav-tabs {
            border-color: #373b3e;
        }

        body.dark-mode .nav-tabs .nav-link.active {
            background-color: #2c3034;
            border-color: #373b3e;
            color: #f8f9fa;
        }

        body.dark-mode .table {
            color: #f8f9fa;
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: #2c3034;
            border-color: #373b3e;
            color: #f8f9fa;
        }

        body.dark-mode .form-control:focus,
        body.dark-mode .form-select:focus {
            background-color: #2c3034;
            border-color: #0d6efd;
            color: #f8f9fa;
        }

        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-calendar-check me-2"></i>
                Classroom Scheduling System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" data-section="section-dashboard" href="#">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-section="section-users" href="#">
                            <i class="bi bi-people"></i> Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-section="section-schedules" href="#">
                            <i class="bi bi-calendar3"></i> Schedules
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-section="section-logs" href="#">
                            <i class="bi bi-journal-text"></i> Activity Logs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-section="section-settings" href="#">
                            <i class="bi bi-gear"></i> Settings
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center">
                    <button class="btn btn-light me-2" id="toggleDark">
                        <i class="bi bi-moon"></i>
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown"onclick="toggleSidebar()>
                            <i class="bi bi-person-circle"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#"><i class="bi bi-person"></i> Profile</a></li>
                            <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container py-4">
        <div class="content">
            <!-- Dashboard Section -->
            <div class="section-tab active" id="section-dashboard">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title">Welcome to Admin Dashboard</h4>
                                <p class="card-text">Manage your classroom scheduling system efficiently.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Replace chart with scheduling metrics -->
            <div class="row mt-4">
                <div class="col-md-6 mb-3">
                    <div class="card p-4">
                        <h5><i class="bi bi-building me-2"></i>Room Utilization</h5>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Capacity</th>
                                        <th>Usage</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Room 301</td>
                                        <td>40</td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: 75%;" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100">75%</div>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-success">Active</span></td>
                                    </tr>
                                    <tr>
                                        <td>Lab 102</td>
                                        <td>30</td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-warning" role="progressbar" style="width: 90%;" aria-valuenow="90" aria-valuemin="0" aria-valuemax="100">90%</div>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-warning">Near Full</span></td>
                                    </tr>
                                    <tr>
                                        <td>Room 201</td>
                                        <td>35</td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: 45%;" aria-valuenow="45" aria-valuemin="0" aria-valuemax="100">45%</div>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-info">Available</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="card p-4">
                        <h5><i class="bi bi-clock-history me-2"></i>Today's Schedule Overview</h5>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Time Slot</th>
                                        <th>Occupied Rooms</th>
                                        <th>Available Rooms</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>8:00 AM - 10:00 AM</td>
                                        <td>10</td>
                                        <td>5</td>
                                    </tr>
                                    <tr>
                                        <td>10:00 AM - 12:00 PM</td>
                                        <td>12</td>
                                        <td>3</td>
                                    </tr>
                                    <tr>
                                        <td>1:00 PM - 3:00 PM</td>
                                        <td>8</td>
                                        <td>7</td>
                                    </tr>
                                    <tr>
                                        <td>3:00 PM - 5:00 PM</td>
                                        <td>6</td>
                                        <td>9</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card p-4">
                        <h5><i class="bi bi-exclamation-triangle me-2"></i>Scheduling Alerts</h5>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between align-items-center text-warning">
                                <div>
                                    <i class="bi bi-exclamation-circle me-2"></i>
                                    Room 301 is reaching maximum capacity for Monday sessions
                                </div>
                                <span class="badge bg-warning rounded-pill">Warning</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center text-info">
                                <div>
                                    <i class="bi bi-info-circle me-2"></i>
                                    3 new schedule requests pending approval
                                </div>
                                <span class="badge bg-info rounded-pill">Info</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center text-danger">
                                <div>
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    Schedule conflict detected in Lab 102 for Thursday
                                </div>
                                <span class="badge bg-danger rounded-pill">Urgent</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Users -->
            <div class="section-tab" id="section-users">
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="bi bi-people-fill me-2"></i>Manage Users</h4>
                        <div>
                            <button class="btn btn-outline-primary me-2" id="usersPrintBtn">
                                <i class="bi bi-printer"></i> Print List
                            </button>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                <i class="bi bi-person-plus"></i> Add User
                            </button>
                        </div>
                    </div>

                    <!-- Enhanced Filters -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input class="form-control" id="userSearch" placeholder="Search users..." type="search"/>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="roleFilter">
                                <option value="">All Roles</option>
                                <option value="Student">Student</option>
                                <option value="Instructor">Instructor</option>
                                <option value="Admin">Admin</option>
                                <option value="Registrar">Registrar</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="departmentFilter">
                                <option value="">All Departments</option>
                                <option value="Computer Science">Computer Science</option>
                                <option value="Engineering">Engineering</option>
                                <option value="Mathematics">Mathematics</option>
                                <option value="Physics">Physics</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="On Leave">On Leave</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-secondary" id="exportExcel">
                                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                                </button>
                                <button class="btn btn-outline-secondary" id="exportPDF">
                                    <i class="bi bi-file-earmark-pdf"></i> Export PDF
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="userTable">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" class="form-check-input" id="selectAllUsers"/> ID</th>
                                    <th>Name <i class="bi bi-arrow-down-up"></i></th>
                                    <th>Role <i class="bi bi-arrow-down-up"></i></th>
                                    <th>Department</th>
                                    <th>Contact</th>
                                    <th>Schedule Load</th>
                                    <th>Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="userTableBody">
                                <tr>
                                    <td><input type="checkbox" class="form-check-input"/> USR001</td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://via.placeholder.com/32" class="rounded-circle me-2" alt=""/>
                                            Diana Cruz
                                        </div>
                                    </td>
                                    <td>Instructor</td>
                                    <td>Computer Science</td>
                                    <td>
                                        <div>diana@example.com</div>
                                        <div class="small text-muted">+1234567890</div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: 75%"></div>
                                            </div>
                                            <span class="small">15/20</span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td class="no-print">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" title="View Schedule">
                                                <i class="bi bi-calendar2-week"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" title="Edit User">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" title="Deactivate">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><input type="checkbox" class="form-check-input"/> USR002</td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://via.placeholder.com/32" class="rounded-circle me-2" alt=""/>
                                            John Smith
                                        </div>
                                    </td>
                                    <td>Student</td>
                                    <td>Engineering</td>
                                    <td>
                                        <div>john@example.com</div>
                                        <div class="small text-muted">+1234567891</div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: 45%"></div>
                                            </div>
                                            <span class="small">9/20</span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td class="no-print">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" title="View Schedule">
                                                <i class="bi bi-calendar2-week"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" title="Edit User">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" title="Deactivate">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="text-muted small">
                            Showing <span id="currentEntries">1-10</span> of <span id="totalEntries">50</span> entries
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm" id="pagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Add User Modal -->
            <div class="modal fade" id="addUserModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Add New User</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addUserForm" class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" required/>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" required/>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role</label>
                                    <select class="form-select" required>
                                        <option value="">Select Role</option>
                                        <option>Student</option>
                                        <option>Instructor</option>
                                        <option>Admin</option>
                                        <option>Registrar</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <select class="form-select" required>
                                        <option value="">Select Department</option>
                                        <option>Computer Science</option>
                                        <option>Engineering</option>
                                        <option>Mathematics</option>
                                        <option>Physics</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact Number</label>
                                    <input type="tel" class="form-control"/>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Maximum Schedule Load</label>
                                    <input type="number" class="form-control" min="1" max="30"/>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Additional Notes</label>
                                    <textarea class="form-control" rows="3"></textarea>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" form="addUserForm" class="btn btn-success">Add User</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Modal -->
            <div class="modal fade" id="bulkActionsModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Bulk Actions</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="list-group">
                                <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    Export Selected Users
                                    <i class="bi bi-file-earmark-arrow-down"></i>
                                </button>
                                <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    Deactivate Selected Users
                                    <i class="bi bi-person-x"></i>
                                </button>
                                <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    Change Department
                                    <i class="bi bi-building"></i>
                                </button>
                                <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    Update Schedule Load
                                    <i class="bi bi-calendar-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedules -->
            <div class="section-tab" id="section-schedules">
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="bi bi-calendar-check-fill me-2"></i>Manage Schedules</h4>
                        <div>
                            <button class="btn btn-outline-primary me-2" id="schedulesPrintBtn">
                                <i class="bi bi-printer"></i> Print Schedule
                            </button>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
                                <i class="bi bi-plus-circle"></i> Add Schedule
                            </button>
                        </div>
                    </div>

                    <!-- Schedule View Options -->
                    <div class="row mb-4">
                        <div class="col-md-9">
                            <div class="btn-group">
                                <button class="btn btn-outline-secondary active" data-view="table">
                                    <i class="bi bi-table"></i> Table View
                                </button>
                                <button class="btn btn-outline-secondary" data-view="calendar">
                                    <i class="bi bi-calendar3"></i> Calendar View
                                </button>
                                <button class="btn btn-outline-secondary" data-view="timeline">
                                    <i class="bi bi-clock"></i> Timeline View
                                </button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-secondary" id="exportScheduleExcel">
                                    <i class="bi bi-file-earmark-excel"></i> Export
                                </button>
                                <button class="btn btn-outline-secondary" id="importSchedule" data-bs-toggle="modal" data-bs-target="#importScheduleModal">
                                    <i class="bi bi-file-earmark-arrow-up"></i> Import
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced Filters -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input class="form-control" id="scheduleSearch" placeholder="Search schedules..." type="search"/>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="subjectFilter">
                                <option value="">All Subjects</option>
                                <option>Math 101</option>
                                <option>Physics 201</option>
                                <option>Chemistry 101</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="roomFilter">
                                <option value="">All Rooms</option>
                                <option>Room 301</option>
                                <option>Lab 102</option>
                                <option>Room 201</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="instructorFilter">
                                <option value="">All Instructors</option>
                                <option>Diana Cruz</option>
                                <option>John Smith</option>
                                <option>Emily Garcia</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <input type="date" class="form-control" id="scheduleDate"/>
                                <button class="btn btn-outline-secondary" type="button">Today</button>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule Views Container -->
                    <div class="schedule-views">
                        <!-- Table View -->
                        <div class="view-container active" id="tableView">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="scheduleTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th><input type="checkbox" class="form-check-input" id="selectAllSchedules"/> ID</th>
                                            <th>Subject</th>
                                            <th>Room</th>
                                            <th>Instructor</th>
                                            <th>Day</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                            <th class="no-print">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><input type="checkbox" class="form-check-input"/> SCH001</td>
                                            <td>Math 101</td>
                                            <td>Room 301</td>
                                            <td>Diana Cruz</td>
                                            <td>Monday</td>
                                            <td>10:00 AM - 12:00 PM</td>
                                            <td><span class="badge bg-success">Active</span></td>
                                            <td class="no-print">
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" title="View Details" data-bs-toggle="modal" data-bs-target="#viewScheduleModal">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline-secondary" title="Edit Schedule">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-outline-danger" title="Cancel Schedule">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div class="text-muted small">
                                    Showing <span id="currentSchedules">1-10</span> of <span id="totalSchedules">50</span> schedules
                                </div>
                                <nav>
                                    <ul class="pagination pagination-sm" id="schedulePagination"></ul>
                                </nav>
                            </div>
                        </div>

                        <!-- Calendar View -->
                        <div class="view-container" id="calendarView">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">Room Legend</h6>
                                            <div class="room-legend">
                                                <div class="d-flex align-items-center mb-2">
                                                    <div class="color-box bg-success"></div>
                                                    <span class="ms-2">Available</span>
                                                </div>
                                                <div class="d-flex align-items-center mb-2">
                                                    <div class="color-box bg-danger"></div>
                                                    <span class="ms-2">Occupied</span>
                                                </div>
                                                <div class="d-flex align-items-center">
                                                    <div class="color-box bg-warning"></div>
                                                    <span class="ms-2">Maintenance</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <div class="calendar-container">
                                        <!-- Calendar will be rendered here by JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Timeline View -->
                        <div class="view-container" id="timelineView">
                            <div class="timeline-container">
                                <div class="timeline-header">
                                    <div class="time-slots">
                                        <div class="time-slot">8:00 AM</div>
                                        <div class="time-slot">9:00 AM</div>
                                        <div class="time-slot">10:00 AM</div>
                                        <div class="time-slot">11:00 AM</div>
                                        <div class="time-slot">12:00 PM</div>
                                        <div class="time-slot">1:00 PM</div>
                                        <div class="time-slot">2:00 PM</div>
                                        <div class="time-slot">3:00 PM</div>
                                        <div class="time-slot">4:00 PM</div>
                                        <div class="time-slot">5:00 PM</div>
                                    </div>
                                </div>
                                <div class="timeline-body">
                                    <!-- Rooms will be listed here with their schedules -->
                                    <div class="room-timeline">
                                        <div class="room-label">Room 301</div>
                                        <div class="schedule-blocks">
                                            <div class="schedule-block" style="left: 20%; width: 15%;">Math 101</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Add Schedule Modal -->
                    <div class="modal fade" id="addScheduleModal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Add New Schedule</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="addScheduleForm" class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Subject</label>
                                            <select class="form-select" required>
                                                <option value="">Select Subject</option>
                                                <option>Math 101</option>
                                                <option>Physics 201</option>
                                                <option>Chemistry 101</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Room</label>
                                            <select class="form-select" required>
                                                <option value="">Select Room</option>
                                                <option>Room 301</option>
                                                <option>Lab 102</option>
                                                <option>Room 201</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Instructor</label>
                                            <select class="form-select" required>
                                                <option value="">Select Instructor</option>
                                                <option>Diana Cruz</option>
                                                <option>John Smith</option>
                                                <option>Emily Garcia</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Day</label>
                                            <select class="form-select" required>
                                                <option value="">Select Day</option>
                                                <option>Monday</option>
                                                <option>Tuesday</option>
                                                <option>Wednesday</option>
                                                <option>Thursday</option>
                                                <option>Friday</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Start Time</label>
                                            <input type="time" class="form-control" required/>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">End Time</label>
                                            <input type="time" class="form-control" required/>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Additional Notes</label>
                                            <textarea class="form-control" rows="3"></textarea>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" form="addScheduleForm" class="btn btn-success">Add Schedule</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Import Schedule Modal -->
                    <div class="modal fade" id="importScheduleModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Import Schedule</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="importScheduleForm">
                                        <div class="mb-3">
                                            <label class="form-label">Import File (Excel/CSV)</label>
                                            <input type="file" class="form-control" accept=".xlsx,.xls,.csv" required/>
                                        </div>
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="overwriteExisting"/>
                                                <label class="form-check-label" for="overwriteExisting">
                                                    Overwrite existing schedules
                                                </label>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" form="importScheduleForm" class="btn btn-primary">Import</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- View Schedule Modal -->
                    <div class="modal fade" id="viewScheduleModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Schedule Details</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="schedule-details">
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Schedule ID:</div>
                                            <div class="col-8">SCH001</div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Subject:</div>
                                            <div class="col-8">Math 101</div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Room:</div>
                                            <div class="col-8">Room 301</div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Instructor:</div>
                                            <div class="col-8">Diana Cruz</div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Day & Time:</div>
                                            <div class="col-8">Monday, 10:00 AM - 12:00 PM</div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Status:</div>
                                            <div class="col-8"><span class="badge bg-success">Active</span></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Created:</div>
                                            <div class="col-8">2025-05-20 09:00 AM</div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-4 text-muted">Last Modified:</div>
                                            <div class="col-8">2025-05-20 09:00 AM</div>
                                        </div>
                                        <div class="row">
                                            <div class="col-4 text-muted">Notes:</div>
                                            <div class="col-8">Regular class schedule for Math 101</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings Section -->
            <div class="section-tab" id="section-settings">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="card-title mb-0"><i class="bi bi-gear me-2"></i>Settings</h4>
                            <div class="btn-group">
                                <button class="btn btn-primary" id="saveSettingsBtn">
                                    <i class="bi bi-save"></i> Save Changes
                                </button>
                                <button class="btn btn-outline-danger" id="resetSettingsBtn">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </button>
                            </div>
                        </div>

                        <!-- Settings Navigation -->
                        <ul class="nav nav-tabs mb-4">
                            <li class="nav-item">
                                <a class="nav-link active" data-settings-tab="general" href="#">
                                    <i class="bi bi-sliders"></i> General
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-settings-tab="notifications" href="#">
                                    <i class="bi bi-bell"></i> Notifications
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-settings-tab="security" href="#">
                                    <i class="bi bi-shield-lock"></i> Security
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-settings-tab="backup" href="#">
                                    <i class="bi bi-cloud-arrow-up"></i> Backup
                                </a>
                            </li>
                        </ul>

                        <!-- Settings Form -->
                        <form id="settingsForm">
                            <!-- General Settings -->
                            <div class="settings-tab active" id="settings-general">
                                <div class="mb-3">
                                    <label class="form-label">Theme</label>
                                    <select class="form-select" name="theme" id="themeSelector">
                                        <option value="light">Light</option>
                                        <option value="dark">Dark</option>
                                        <option value="auto">Auto (System)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Language</label>
                                    <select class="form-select" name="language">
                                        <option value="en">English</option>
                                        <option value="es">Spanish</option>
                                        <option value="fr">French</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Time Zone</label>
                                    <select class="form-select" name="timezone">
                                        <option value="UTC">UTC</option>
                                        <option value="EST">Eastern Time</option>
                                        <option value="PST">Pacific Time</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Notifications Settings -->
                            <div class="settings-tab" id="settings-notifications">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="emailNotifications" id="emailNotifications">
                                        <label class="form-check-label">Email Notifications</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="pushNotifications" id="pushNotifications">
                                        <label class="form-check-label">Push Notifications</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Security Settings -->
                            <div class="settings-tab" id="settings-security">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="twoFactorAuth" id="twoFactorAuth">
                                        <label class="form-check-label">Two-Factor Authentication</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Session Timeout (minutes)</label>
                                    <input type="number" class="form-control" name="sessionTimeout" min="5" max="120" value="30">
                                </div>
                            </div>

                            <!-- Backup Settings -->
                            <div class="settings-tab" id="settings-backup">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="autoBackup" id="autoBackup">
                                        <label class="form-check-label">Automatic Backup</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Backup Frequency</label>
                                    <select class="form-select" name="backupFrequency">
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Activity Logs -->
            <div class="section-tab" id="section-logs">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="card-title mb-0"><i class="bi bi-journal-text me-2"></i>Activity Logs</h4>
                            <div class="btn-group">
                                <button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="bi bi-download"></i> Export
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" id="exportCSV">Export as CSV</a></li>
                                    <li><a class="dropdown-item" href="#" id="exportExcel">Export as Excel</a></li>
                                    <li><a class="dropdown-item" href="#" id="exportPDF">Export as PDF</a></li>
                                </ul>
                                <button class="btn btn-outline-primary" id="logsTablePrintBtn">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <button class="btn btn-outline-danger" id="clearLogsBtn">
                                    <i class="bi bi-trash"></i> Clear
                                </button>
                            </div>
                        </div>

                        <!-- Stats Cards -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="card bg-primary text-white">
                                    <div class="card-body">
                                        <h6 class="card-title">Total Logs</h6>
                                        <h3 class="counter" data-target="1234">1,234</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body">
                                        <h6 class="card-title">Today's Logs</h6>
                                        <h3 class="counter" data-target="56">56</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-info text-white">
                                    <div class="card-body">
                                        <h6 class="card-title">Active Users</h6>
                                        <h3 class="counter" data-target="12">12</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning text-white">
                                    <div class="card-body">
                                        <h6 class="card-title">Security Alerts</h6>
                                        <h3 class="counter" data-target="0">0</h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Search and Filters -->
                        <div class="row mb-4">
                            <div class="col">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="logsSearch" placeholder="Search logs...">
                                </div>
                            </div>
                        </div>

                        <!-- Logs Table -->
                        <div class="table-responsive">
                            <table class="table table-hover" id="logsTable">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Details</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="logsTableBody">
                                    <!-- Sample log entries -->
                                    <tr>
                                        <td>2024-03-20 10:30:15</td>
                                        <td>admin</td>
                                        <td>Settings Update</td>
                                        <td>Updated system settings</td>
                                        <td><span class="badge bg-success">Success</span></td>
                                    </tr>
                                    <tr>
                                        <td>2024-03-20 10:15:00</td>
                                        <td>john.doe</td>
                                        <td>Login</td>
                                        <td>User login successful</td>
                                        <td><span class="badge bg-success">Success</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="py-3 bg-light mt-auto">
        <div class="container">
            <p class="text-center mb-0">&copy; 2024 PCT Bajada - Classroom Scheduling System. All rights reserved.</p>
        </div>
    </footer>

    <!-- Toast Container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.main-content').classList.toggle('sidebar-shown');
        }
        // Initialize all modules
        document.addEventListener('DOMContentLoaded', () => {
            // Show/Hide sections function
            function showSection(sectionId) {
                // Hide all sections first
                document.querySelectorAll('.section-tab').forEach(section => {
                    section.classList.remove('active');
                });

                // Show the selected section
                const targetSection = document.getElementById(sectionId);
                if (targetSection) {
                    targetSection.classList.add('active');
                }

                // Update navigation active state
                    document.querySelectorAll('.nav-link').forEach(link => {
                        link.classList.remove('active');
                        if (link.getAttribute('data-section') === sectionId) {
                            link.classList.add('active');
                        }
                    });
            }

            // Add click event listeners to all navigation links
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const targetSection = link.getAttribute('data-section');
                    if (targetSection) {
                        showSection(targetSection);
            }
                });
            });

            // Show dashboard section by default
            showSection('section-dashboard');

            // Initialize other components
            const themeManager = new ThemeManager();
            const settingsManager = new SettingsManager();
            const activityLogsManager = new ActivityLogsManager();

            // Wire up admin print buttons
            document.getElementById('usersPrintBtn')?.addEventListener('click', () => {
                window.print();
            });

            document.getElementById('schedulesPrintBtn')?.addEventListener('click', () => {
                window.print();
            });
                });

        // Theme Manager Implementation
        class ThemeManager {
            constructor() {
                this.toggleBtn = document.getElementById('toggleDark');
                this.init();
            }

            init() {
                // Check saved theme preference
                const darkMode = localStorage.getItem('darkMode') === 'true';
                if (darkMode) {
                    document.body.classList.add('dark-mode');
                }

                // Setup toggle button
                this.toggleBtn?.addEventListener('click', () => {
                    document.body.classList.toggle('dark-mode');
                    localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
                });
            }
        }

        // Activity Logs Manager Implementation
        class ActivityLogsManager {
            constructor() {
                this.table = document.getElementById('logsTable');
                this.tbody = document.getElementById('logsTableBody');
                this.exportBtn = document.getElementById('exportLogs');
                this.clearBtn = document.getElementById('clearLogsBtn');
                this.searchInput = document.getElementById('logsSearch');
                this.init();
            }

            init() {
                // Setup event listeners
                this.setupEventListeners();
                // Load initial data
                this.loadInitialData();
                // Setup export functionality
                this.setupExport();
            }

            setupEventListeners() {
                // Search functionality
                this.searchInput?.addEventListener('input', (e) => {
                    const searchTerm = e.target.value.toLowerCase();
                    const rows = this.tbody?.querySelectorAll('tr');
                    rows?.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });

                // Clear logs functionality
                this.clearBtn?.addEventListener('click', () => {
                    if (confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
                        this.clearLogs();
                    }
                });

                // Print functionality
                document.getElementById('logsTablePrintBtn')?.addEventListener('click', () => {
                    window.print();
                });
            }

            loadInitialData() {
                // Update counters
                const counters = document.querySelectorAll('.counter');
                counters.forEach(counter => {
                    const target = counter.getAttribute('data-target');
                    if (target) {
                        counter.textContent = target;
                    }
                });
            }

            setupExport() {
                // CSV Export
                document.getElementById('exportCSV')?.addEventListener('click', () => {
                    this.exportToCSV();
                });
            }

            clearLogs() {
                if (this.tbody) {
                    this.tbody.innerHTML = '';
                    // Reset counters
                    document.querySelectorAll('.counter').forEach(counter => {
                        counter.textContent = '0';
                    });
                    this.showToast('Success', 'All logs have been cleared.');
                }
            }

            exportToCSV() {
                if (!this.table) return;

                const rows = Array.from(this.table.querySelectorAll('tr'));
                const csvContent = rows
                    .map(row => {
                        return Array.from(row.cells)
                            .map(cell => cell.textContent.trim())
                            .join(',');
                    })
                    .join('\\n');

                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'activity_logs.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }

            showToast(title, message) {
                const toastHtml = `
                    <div class="toast" role="alert">
                        <div class="toast-header">
                            <strong class="me-auto">${title}</strong>
                            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                        </div>
                        <div class="toast-body">${message}</div>
                    </div>
                `;

                const toastContainer = document.querySelector('.toast-container') || 
                    (() => {
                        const container = document.createElement('div');
                        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                        document.body.appendChild(container);
                        return container;
                    })();

                toastContainer.insertAdjacentHTML('beforeend', toastHtml);
                const toast = new bootstrap.Toast(toastContainer.lastElementChild);
                toast.show();
            }
        }

        // Settings Manager Implementation
        class SettingsManager {
            constructor() {
                this.form = document.getElementById('settingsForm');
                this.saveBtn = document.getElementById('saveSettingsBtn');
                this.resetBtn = document.getElementById('resetSettingsBtn');
                this.init();
            }

            init() {
                this.loadSavedSettings();
                this.setupEventListeners();
            }

            loadSavedSettings() {
                const savedSettings = localStorage.getItem('settings');
                if (savedSettings) {
                    const settings = JSON.parse(savedSettings);
                    Object.entries(settings).forEach(([key, value]) => {
                        const input = this.form?.querySelector(`[name="${key}"]`);
                        if (input) {
                            if (input.type === 'checkbox') {
                                input.checked = value;
                            } else {
                                input.value = value;
                            }
                        }
                    });
                }
            }

            setupEventListeners() {
                // Save settings
                this.saveBtn?.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.saveSettings();
                });

                // Reset settings
                this.resetBtn?.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (confirm('Are you sure you want to reset all settings? This action cannot be undone.')) {
                        this.resetSettings();
                    }
                });

                // Tab navigation
                document.querySelectorAll('[data-settings-tab]').forEach(tab => {
                    tab.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.switchTab(tab.getAttribute('data-settings-tab'));
                    });
                });
            }

            saveSettings() {
                if (!this.form) return;

                const formData = new FormData(this.form);
                const settings = {};
                formData.forEach((value, key) => {
                    settings[key] = value;
                });

                localStorage.setItem('settings', JSON.stringify(settings));
                this.showToast('Success', 'Settings saved successfully');
            }

            resetSettings() {
                if (!this.form) return;

                this.form.reset();
                localStorage.removeItem('settings');
                this.showToast('Success', 'Settings reset to defaults');
            }

            switchTab(tabId) {
                // Hide all tabs
                document.querySelectorAll('.settings-tab').forEach(tab => {
                    tab.classList.remove('active');
                });

                // Show selected tab
                const selectedTab = document.getElementById(`settings-${tabId}`);
                if (selectedTab) {
                    selectedTab.classList.add('active');
                }

                // Update navigation
                document.querySelectorAll('[data-settings-tab]').forEach(tab => {
                    tab.classList.remove('active');
                    if (tab.getAttribute('data-settings-tab') === tabId) {
                        tab.classList.add('active');
                    }
                });
            }

            showToast(title, message) {
                const toastHtml = `
                    <div class="toast" role="alert">
                        <div class="toast-header">
                            <strong class="me-auto">${title}</strong>
                            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                        </div>
                        <div class="toast-body">${message}</div>
                    </div>
                `;

                const toastContainer = document.querySelector('.toast-container') || 
                    (() => {
                        const container = document.createElement('div');
                        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                        document.body.appendChild(container);
                        return container;
                    })();

                toastContainer.insertAdjacentHTML('beforeend', toastHtml);
                const toast = new bootstrap.Toast(toastContainer.lastElementChild);
                toast.show();
            }
        }
    </script>
</body>
</html>