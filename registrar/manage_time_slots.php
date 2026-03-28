<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

// Define fixed time slots
$time_slots = [
    ['id' => 1, 'slot_name' => 'Morning 1', 'start_time' => '08:00:00', 'end_time' => '10:00:00'],
    ['id' => 2, 'slot_name' => 'Morning 2', 'start_time' => '10:00:00', 'end_time' => '12:00:00'],
    ['id' => 3, 'slot_name' => 'Morning 3', 'start_time' => '12:00:00', 'end_time' => '14:00:00'],
    ['id' => 4, 'slot_name' => 'Afternoon 1', 'start_time' => '14:00:00', 'end_time' => '16:00:00'],
    ['id' => 5, 'slot_name' => 'Afternoon 2', 'start_time' => '16:00:00', 'end_time' => '18:00:00'],
    ['id' => 6, 'slot_name' => 'Evening 1', 'start_time' => '18:00:00', 'end_time' => '20:00:00'],
    ['id' => 7, 'slot_name' => 'Evening 2', 'start_time' => '20:00:00', 'end_time' => '22:00:00']
];

// Get schedule usage for each time slot
$schedule_usage = [];
foreach ($time_slots as $slot) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM schedules 
        WHERE start_time = :start_time 
        AND status = 'active'
    ");
    $stmt->execute(['start_time' => $slot['start_time']]);
    $schedule_usage[$slot['id']] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Slots - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .time-slot-card {
            border-left: 4px solid #2c5d3f;
            transition: transform 0.2s;
        }
        .time-slot-card:hover {
            transform: translateY(-2px);
        }
        .usage-badge {
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'time_slots';
    include 'includes/sidebar.php'; 
    ?>

    <div class="main-content">
        <div class="container py-4">
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Fixed Time Slots</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                The system uses fixed 2-hour time slots for all classes. These slots cannot be modified.
                            </div>
                            
                            <div class="row g-4">
                                <?php foreach($time_slots as $slot): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card time-slot-card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title"><?php echo htmlspecialchars($slot['slot_name']); ?></h6>
                                            <p class="card-text">
                                                <i class="bi bi-clock"></i>
                                                <?php 
                                                echo date('g:i A', strtotime($slot['start_time'])) . ' - ' . 
                                                     date('g:i A', strtotime($slot['end_time']));
                                                ?>
                                            </p>
                                            <p class="card-text">
                                                <span class="badge bg-primary usage-badge">
                                                    <i class="bi bi-calendar-check"></i>
                                                    <?php echo $schedule_usage[$slot['id']]; ?> Active Classes
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 