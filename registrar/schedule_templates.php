<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'save_template':
                    // Save schedule template
                    $stmt = $conn->prepare("
                        INSERT INTO schedule_templates (
                            name, description, course_id, instructor_id,
                            classroom_id, day_of_week, start_time, end_time,
                            created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $_POST['template_name'],
                        $_POST['description'],
                        $_POST['course_id'],
                        $_POST['instructor_id'],
                        $_POST['classroom_id'],
                        $_POST['day_of_week'],
                        $_POST['start_time'],
                        $_POST['end_time'],
                        $_SESSION['user_id']
                    ]);
                    
                    $_SESSION['success'] = "Schedule template saved successfully.";
                    break;
                    
                case 'apply_template':
                    // Apply template to create new schedules
                    $template_id = $_POST['template_id'];
                    $semester = $_POST['semester'];
                    $academic_year = $_POST['academic_year'];
                    
                    // Get template details
                    $stmt = $conn->prepare("SELECT * FROM schedule_templates WHERE id = ?");
                    $stmt->execute([$template_id]);
                    $template = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($template) {
                        // Create new schedule from template
                        $stmt = $conn->prepare("
                            INSERT INTO schedules (
                                course_id, instructor_id, classroom_id,
                                day_of_week, start_time, end_time,
                                semester, academic_year, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                        ");
                        
                        $stmt->execute([
                            $template['course_id'],
                            $template['instructor_id'],
                            $template['classroom_id'],
                            $template['day_of_week'],
                            $template['start_time'],
                            $template['end_time'],
                            $semester,
                            $academic_year
                        ]);
                        
                        $_SESSION['success'] = "Schedule created from template successfully.";
                    }
                    break;
                    
                case 'delete_template':
                    // Delete template
                    $stmt = $conn->prepare("DELETE FROM schedule_templates WHERE id = ?");
                    $stmt->execute([$_POST['template_id']]);
                    $_SESSION['success'] = "Template deleted successfully.";
                    break;
            }
        }
        header('Location: schedule_templates.php');
        exit();
    } catch (PDOException $e) {
        error_log("Error in schedule templates: " . $e->getMessage());
        $_SESSION['error'] = "An error occurred while processing your request.";
    }
}

// Get all templates
$stmt = $conn->query("
    SELECT t.*, 
           c.course_code, c.course_name,
           u.first_name, u.last_name,
           cr.room_number
    FROM schedule_templates t
    JOIN courses c ON t.course_id = c.id
    JOIN users u ON t.instructor_id = u.id
    JOIN classrooms cr ON t.classroom_id = cr.id
    ORDER BY t.created_at DESC
");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all courses for dropdown
$stmt = $conn->query("SELECT * FROM courses ORDER BY course_code");
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all instructors for dropdown
$stmt = $conn->query("SELECT * FROM users WHERE role = 'instructor' ORDER BY last_name, first_name");
$instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all rooms for dropdown
$stmt = $conn->query("SELECT * FROM classrooms ORDER BY room_number");
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define time slots
$time_slots = [
    '08:00' => '8:00 AM - 10:00 AM',
    '10:00' => '10:00 AM - 12:00 PM',
    '13:00' => '1:00 PM - 3:00 PM',
    '15:00' => '3:00 PM - 5:00 PM'
];

// Define days
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Templates - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Schedule Templates</h2>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
                    <i class="bi bi-plus-circle"></i> Create Template
                </button>
            </div>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Templates Grid -->
            <div class="row">
                <?php foreach ($templates as $template): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($template['name']); ?></h5>
                            <p class="card-text text-muted"><?php echo htmlspecialchars($template['description']); ?></p>
                            
                            <div class="mb-3">
                                <strong>Course:</strong><br>
                                <?php echo htmlspecialchars($template['course_code'] . ' - ' . $template['course_name']); ?>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Instructor:</strong><br>
                                <?php echo htmlspecialchars($template['first_name'] . ' ' . $template['last_name']); ?>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Schedule:</strong><br>
                                <?php echo htmlspecialchars($template['day_of_week'] . ' ' . 
                                    date('g:i A', strtotime($template['start_time'])) . ' - ' . 
                                    date('g:i A', strtotime($template['end_time']))); ?>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Room:</strong><br>
                                Room <?php echo htmlspecialchars($template['room_number']); ?>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary btn-sm" 
                                        onclick="applyTemplate(<?php echo $template['id']; ?>)">
                                    <i class="bi bi-calendar-plus"></i> Apply Template
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" 
                                        onclick="deleteTemplate(<?php echo $template['id']; ?>)">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($templates)): ?>
                <div class="col-12">
                    <div class="text-center text-muted">
                        <i class="bi bi-calendar-x display-4"></i>
                        <p class="mt-2">No templates found</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Create Template Modal -->
    <div class="modal fade" id="templateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Schedule Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_template">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Template Name</label>
                            <input type="text" class="form-control" name="template_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Course</label>
                            <select class="form-select" name="course_id" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>">
                                        <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Instructor</label>
                            <select class="form-select" name="instructor_id" required>
                                <option value="">Select Instructor</option>
                                <?php foreach ($instructors as $instructor): ?>
                                    <option value="<?php echo $instructor['id']; ?>">
                                        <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Room</label>
                            <select class="form-select" name="classroom_id" required>
                                <option value="">Select Room</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>">
                                        Room <?php echo htmlspecialchars($room['room_number']); ?> 
                                        (Capacity: <?php echo $room['capacity']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Day</label>
                            <select class="form-select" name="day_of_week" required>
                                <?php foreach ($days as $day): ?>
                                    <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Time Slot</label>
                            <select class="form-select" name="start_time" required>
                                <option value="">Select Time Slot</option>
                                <?php foreach ($time_slots as $time => $label): ?>
                                    <option value="<?php echo $time; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Template</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Apply Template Modal -->
    <div class="modal fade" id="applyTemplateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Apply Schedule Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="apply_template">
                    <input type="hidden" name="template_id" id="apply_template_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Semester</label>
                            <select class="form-select" name="semester" required>
                                <option value="First">First Semester</option>
                                <option value="Second">Second Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <input type="text" class="form-control" name="academic_year" 
                                   value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function applyTemplate(templateId) {
            document.getElementById('apply_template_id').value = templateId;
            new bootstrap.Modal(document.getElementById('applyTemplateModal')).show();
        }
        
        function deleteTemplate(templateId) {
            if (confirm('Are you sure you want to delete this template?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_template';
                
                const templateInput = document.createElement('input');
                templateInput.type = 'hidden';
                templateInput.name = 'template_id';
                templateInput.value = templateId;
                
                form.appendChild(actionInput);
                form.appendChild(templateInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html> 