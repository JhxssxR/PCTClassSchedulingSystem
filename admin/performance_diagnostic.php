<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

$cache_dir = sys_get_temp_dir();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Diagnostic</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold text-slate-900 mb-8">Performance Diagnostic</h1>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-slate-900 mb-4">Cache Status</h2>
            <div class="space-y-3">
                <?php
                $cache_files = [
                    'pct_schedules_cache.json' => 'Schedules Cache',
                    'pct_dashboard_stats_cache.json' => 'Dashboard Stats Cache',
                    'pct_students_cache.json' => 'Students Cache',
                    'pct_instructors_cache.json' => 'Instructors Cache',
                ];
                
                foreach ($cache_files as $filename => $label) {
                    $filepath = $cache_dir . DIRECTORY_SEPARATOR . $filename;
                    $exists = file_exists($filepath);
                    $size = $exists ? filesize($filepath) : 0;
                    $age = $exists ? (time() - filemtime($filepath)) : 'N/A';
                    $fresh = ($age !== 'N/A' && $age < 300) ? true : false;
                    
                    $status_color = $fresh ? 'bg-emerald-50 text-emerald-700' : ($exists ? 'bg-amber-50 text-amber-700' : 'bg-slate-50 text-slate-500');
                    $status_text = $fresh ? '✓ Fresh' : ($exists ? '⚠ Stale' : '✗ Missing');
                    
                    echo "<div class='p-4 rounded-lg $status_color border border-current border-opacity-20'>";
                    echo "<p class='font-semibold'>$label</p>";
                    echo "<p class='text-sm mt-1'>Status: $status_text</p>";
                    if ($exists) {
                        echo "<p class='text-sm'>Size: " . number_format($size) . " bytes | Age: {$age}s</p>";
                    }
                    echo "</div>";
                }
                ?>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-slate-900 mb-4">Database Performance</h2>
            <div class="space-y-3">
                <?php
                $queries = [
                    ['SELECT COUNT(*) FROM users', 'User Count'],
                    ['SELECT COUNT(*) FROM schedules', 'Schedule Count'],
                    ['SELECT COUNT(*) FROM enrollments', 'Enrollment Count'],
                ];
                
                foreach ($queries as [$sql, $label]) {
                    $start = microtime(true);
                    try {
                        $result = $conn->query($sql)->fetchColumn();
                        $time = (microtime(true) - $start) * 1000;
                        $color = $time < 100 ? 'bg-emerald-50 text-emerald-700' : ($time < 500 ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700');
                        echo "<div class='p-4 rounded-lg $color border border-current border-opacity-20'>";
                        echo "<p class='font-semibold'>$label</p>";
                        echo "<p class='text-sm mt-1'>Result: $result | Query Time: " . number_format($time, 2) . "ms</p>";
                        echo "</div>";
                    } catch (Exception $e) {
                        echo "<div class='p-4 rounded-lg bg-rose-50 text-rose-700 border border-current border-opacity-20'>";
                        echo "<p class='font-semibold'>$label - ERROR</p>";
                        echo "<p class='text-sm mt-1'>" . $e->getMessage() . "</p>";
                        echo "</div>";
                    }
                }
                ?>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-slate-900 mb-4">System Info</h2>
            <div class="space-y-2 text-sm">
                <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                <p><strong>Temp Directory:</strong> <?php echo $cache_dir; ?></p>
                <p><strong>Temp Dir Writable:</strong> <?php echo is_writable($cache_dir) ? '✓ Yes' : '✗ No'; ?></p>
                <p><strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></p>
                <p><strong>Memory Limit:</strong> <?php echo ini_get('memory_limit'); ?></p>
                <p><strong>Max Execution Time:</strong> <?php echo ini_get('max_execution_time'); ?>s</p>
            </div>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 text-blue-900">
            <p class="text-sm"><strong>Note:</strong> If cache files are missing, they will be created on first page visit. If all queries are slow (&gt;500ms), consider checking database connection or applying additional indexes.</p>
        </div>
    </div>
</body>
</html>
