<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

try {
    // Get schedule statistics
    $stats = [
        'total_classes' => 0,
        'active_classes' => 0,
        'inactive_classes' => 0,
        'room_utilization' => [],
        'instructor_load' => [],
        'day_distribution' => [],
        'time_distribution' => []
    ];
    
    // Get total classes
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
        FROM schedules
    ");
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_classes'] = $counts['total'];
    $stats['active_classes'] = $counts['active'];
    $stats['inactive_classes'] = $counts['inactive'];
    
    // Get room utilization
    $stmt = $conn->query("
        SELECT 
            cr.room_number,
            COUNT(s.id) as class_count,
            cr.capacity
        FROM classrooms cr
        LEFT JOIN schedules s ON cr.id = s.classroom_id AND s.status = 'active'
        GROUP BY cr.id
        ORDER BY class_count DESC
    ");
    $stats['room_utilization'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get instructor load
    $stmt = $conn->query("
        SELECT 
            u.first_name,
            u.last_name,
            COUNT(s.id) as class_count
        FROM users u
        LEFT JOIN schedules s ON u.id = s.instructor_id AND s.status = 'active'
        WHERE u.role = 'instructor'
        GROUP BY u.id
        ORDER BY class_count DESC
    ");
    $stats['instructor_load'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get day distribution
    $stmt = $conn->query("
        SELECT 
            day_of_week,
            COUNT(*) as class_count
        FROM schedules
        WHERE status = 'active'
        GROUP BY day_of_week
        ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')
    ");
    $stats['day_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get time distribution
    $stmt = $conn->query("
        SELECT 
            HOUR(start_time) as hour,
            COUNT(*) as class_count
        FROM schedules
        WHERE status = 'active'
        GROUP BY HOUR(start_time)
        ORDER BY hour
    ");
    $stats['time_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error in schedule analytics: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while loading analytics.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Analytics - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <h2 class="mb-4">Schedule Analytics</h2>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Overview Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Total Classes</h5>
                            <h2 class="card-text"><?php echo $stats['total_classes']; ?></h2>
                            <p class="text-muted">
                                <?php echo $stats['active_classes']; ?> Active / 
                                <?php echo $stats['inactive_classes']; ?> Inactive
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Room Utilization</h5>
                            <h2 class="card-text">
                                <?php 
                                $total_rooms = count($stats['room_utilization']);
                                $used_rooms = count(array_filter($stats['room_utilization'], function($r) {
                                    return $r['class_count'] > 0;
                                }));
                                echo $used_rooms . '/' . $total_rooms;
                                ?>
                            </h2>
                            <p class="text-muted">Rooms in Use</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Instructor Load</h5>
                            <h2 class="card-text">
                                <?php 
                                $total_instructors = count($stats['instructor_load']);
                                $active_instructors = count(array_filter($stats['instructor_load'], function($i) {
                                    return $i['class_count'] > 0;
                                }));
                                echo $active_instructors . '/' . $total_instructors;
                                ?>
                            </h2>
                            <p class="text-muted">Active Instructors</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="row">
                <!-- Room Utilization Chart -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Room Utilization</h5>
                            <div class="chart-container">
                                <canvas id="roomChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Instructor Load Chart -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Instructor Load</h5>
                            <div class="chart-container">
                                <canvas id="instructorChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Day Distribution Chart -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Class Distribution by Day</h5>
                            <div class="chart-container">
                                <canvas id="dayChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Time Distribution Chart -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Class Distribution by Time</h5>
                            <div class="chart-container">
                                <canvas id="timeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Room Utilization Chart
        new Chart(document.getElementById('roomChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($stats['room_utilization'], 'room_number')); ?>,
                datasets: [{
                    label: 'Classes per Room',
                    data: <?php echo json_encode(array_column($stats['room_utilization'], 'class_count')); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        // Instructor Load Chart
        new Chart(document.getElementById('instructorChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($i) {
                    return $i['first_name'] . ' ' . $i['last_name'];
                }, $stats['instructor_load'])); ?>,
                datasets: [{
                    label: 'Classes per Instructor',
                    data: <?php echo json_encode(array_column($stats['instructor_load'], 'class_count')); ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.5)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        // Day Distribution Chart
        new Chart(document.getElementById('dayChart'), {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($stats['day_distribution'], 'day_of_week')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($stats['day_distribution'], 'class_count')); ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.5)',
                        'rgba(54, 162, 235, 0.5)',
                        'rgba(255, 206, 86, 0.5)',
                        'rgba(75, 192, 192, 0.5)',
                        'rgba(153, 102, 255, 0.5)',
                        'rgba(255, 159, 64, 0.5)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
        
        // Time Distribution Chart
        new Chart(document.getElementById('timeChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($t) {
                    return $t['hour'] . ':00';
                }, $stats['time_distribution'])); ?>,
                datasets: [{
                    label: 'Classes per Hour',
                    data: <?php echo json_encode(array_column($stats['time_distribution'], 'class_count')); ?>,
                    fill: false,
                    borderColor: 'rgba(153, 102, 255, 1)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html> 