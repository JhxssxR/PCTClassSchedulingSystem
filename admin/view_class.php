<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Check if user is logged in and is an admin
if (!is_logged_in() || !has_role('super_admin')) {
    header('Location: ../auth/login.php?role=super_admin');
    exit();
}

// Get class ID from URL
$class_id = $_GET['id'] ?? null;
if (!$class_id) {
    $_SESSION['error'] = "Class ID is required";
    header('Location: classes.php');
    exit();
}

// Get class details with related information
try {
    $stmt = $conn->prepare("
        SELECT 
            s.*,
            c.course_code,
            c.course_name,
            c.credits,
            CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
            i.email as instructor_email,
            cr.room_number,
            cr.building,
            cr.capacity,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            u.email as created_by_email,
            (SELECT COUNT(*) FROM enrollments e WHERE e.schedule_id = s.id AND e.status = 'approved') as enrolled_students
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        JOIN users i ON s.instructor_id = i.id
        JOIN classrooms cr ON s.classroom_id = cr.id
        JOIN users u ON s.created_by = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        throw new Exception("Class not found");
    }

    // Schema compatibility: some DB versions don't store schedules.end_time.
    if (empty($class['end_time']) && !empty($class['start_time'])) {
        $t = strtotime('1970-01-01 ' . $class['start_time']);
        if ($t !== false) {
            $class['end_time'] = date('H:i:s', $t + (120 * 60));
        }
    }

    // Get enrolled students
    $stmt = $conn->prepare("
        SELECT 
            e.*,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            s.email as student_email,
            s.student_id
        FROM enrollments e
        JOIN users s ON e.student_id = s.id
        WHERE e.schedule_id = ? AND e.status = 'approved'
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$class_id]);
    $enrolled_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: classes.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Class - Super Admin Dashboard</title>
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
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #234a2a;
            border-color: #234a2a;
        }

        .table th {
            background-color: var(--primary);
            color: white;
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'classes';
    include '../includes/sidebar.php'; 
    ?>

    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Class Details</h2>
                <div>
                    <a href="classes.php" class="btn btn-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Classes
                    </a>
                    <a href="edit_class.php?id=<?php echo $class_id; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Edit Class
                    </a>
                </div>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Course Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Course Code:</strong> <?php echo htmlspecialchars($class['course_code']); ?></p>
                                    <p><strong>Course Name:</strong> <?php echo htmlspecialchars($class['course_name']); ?></p>
                                    <p><strong>Credits:</strong> <?php echo htmlspecialchars($class['credits']); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Semester:</strong> <?php echo htmlspecialchars($class['semester']); ?></p>
                                    <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($class['academic_year']); ?></p>
                                    <p><strong>Status:</strong> 
                                        <span class="badge bg-<?php 
                                            echo $class['status'] === 'active' ? 'success' : 
                                                ($class['status'] === 'cancelled' ? 'danger' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($class['status']); ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Schedule Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Day:</strong> <?php echo htmlspecialchars($class['day_of_week']); ?></p>
                                    <p><strong>Time:</strong> 
                                        <?php 
                                        echo date('g:i A', strtotime($class['start_time'])) . ' - ' . 
                                             date('g:i A', strtotime($class['end_time']));
                                        ?>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Location:</strong> 
                                        <?php echo htmlspecialchars($class['building'] . ' - Room ' . $class['room_number']); ?>
                                    </p>
                                    <p><strong>Capacity:</strong> 
                                        <?php echo $class['enrolled_students']; ?> / <?php echo $class['max_students']; ?> students
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Enrolled Students</h5>
                            <?php if (empty($enrolled_students)): ?>
                                <p class="text-muted">No students enrolled in this class.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student ID</th>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Enrollment Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($enrolled_students as $student): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['student_email']); ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Instructor Information</h5>
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($class['instructor_name']); ?></p>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($class['instructor_email']); ?></p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Class Information</h5>
                            <p><strong>Created By:</strong> <?php echo htmlspecialchars($class['created_by_name']); ?></p>
                            <p><strong>Created On:</strong> <?php echo date('M d, Y', strtotime($class['created_at'])); ?></p>
                            <?php if ($class['updated_at']): ?>
                                <p><strong>Last Updated:</strong> <?php echo date('M d, Y', strtotime($class['updated_at'])); ?></p>
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