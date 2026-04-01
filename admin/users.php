<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

function normalize_year_level_value($value): ?int {
    $y = (int)$value;
    return ($y >= 1 && $y <= 4) ? $y : null;
}

// Schema compatibility: add users.year_level if missing.
try {
    $has_year_level = false;
    $cols_stmt = $conn->prepare('DESCRIBE users');
    $cols_stmt->execute();
    foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (($r['Field'] ?? '') === 'year_level') {
            $has_year_level = true;
            break;
        }
    }
    if (!$has_year_level) {
        $conn->exec('ALTER TABLE users ADD COLUMN year_level TINYINT NULL AFTER role');
    }
} catch (Throwable $e) {
    error_log('Could not ensure users.year_level column: ' . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    // Hash password
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

                    $db_now = null;
                    try {
                        $db_now = (string)$conn->query('SELECT NOW()')->fetchColumn();
                    } catch (Throwable $e) {
                        $db_now = date('Y-m-d H:i:s');
                    }

                    // Detect optional timestamp columns for compatibility
                    $user_cols = [];
                    try {
                        $cols_stmt = $conn->prepare('DESCRIBE users');
                        $cols_stmt->execute();
                        foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            if (!empty($r['Field'])) {
                                $user_cols[$r['Field']] = true;
                            }
                        }
                    } catch (Throwable $e) {
                        // Ignore schema detection issues; fall back to base insert
                        $user_cols = [];
                    }
                    
                    // Add new user
                    $insert_cols = ['username', 'password', 'email', 'role', 'first_name', 'last_name'];
                    $insert_vals = [':username', ':password', ':email', ':role', ':first_name', ':last_name'];
                    $params = [
                        'username' => trim((string)($_POST['username'] ?? '')),
                        'password' => $password,
                        'email' => trim((string)($_POST['email'] ?? '')),
                        'role' => (string)($_POST['role'] ?? ''),
                        'first_name' => trim((string)($_POST['first_name'] ?? '')),
                        'last_name' => trim((string)($_POST['last_name'] ?? '')),
                    ];

                    $role = strtolower(trim((string)$params['role']));
                    if ($role === 'admin') {
                        $role = 'super_admin';
                    }
                    $allowed_roles = ['student', 'instructor', 'registrar', 'super_admin'];
                    if (!in_array($role, $allowed_roles, true)) {
                        throw new Exception('Invalid role selected.');
                    }
                    $params['role'] = $role;
                    $year_level = normalize_year_level_value($_POST['year_level'] ?? null);
                    if ($role === 'student' && $year_level === null) {
                        throw new Exception('Year level is required for students.');
                    }

                    if (isset($user_cols['year_level'])) {
                        $insert_cols[] = 'year_level';
                        $insert_vals[] = ':year_level';
                        $params['year_level'] = ($role === 'student') ? $year_level : null;
                    }

                    if (isset($user_cols['status'])) {
                        $insert_cols[] = 'status';
                        $insert_vals[] = ':status';
                        $params['status'] = 'active';
                    }

                    if (isset($user_cols['created_at'])) {
                        $insert_cols[] = 'created_at';
                        $insert_vals[] = ':created_at';
                        $params['created_at'] = $db_now;
                    }

                    if (isset($user_cols['updated_at'])) {
                        $insert_cols[] = 'updated_at';
                        $insert_vals[] = ':updated_at';
                        $params['updated_at'] = $db_now;
                    }

                    $stmt = $conn->prepare(
                        'INSERT INTO users (' . implode(', ', $insert_cols) . ') VALUES (' . implode(', ', $insert_vals) . ')'
                    );
                    $stmt->execute($params);
                    $_SESSION['success'] = "User added successfully.";
                    break;

                case 'edit':
                    $db_now = null;
                    try {
                        $db_now = (string)$conn->query('SELECT NOW()')->fetchColumn();
                    } catch (Throwable $e) {
                        $db_now = date('Y-m-d H:i:s');
                    }

                    $params = [
                        'id' => $_POST['user_id'],
                        'username' => $_POST['username'],
                        'email' => $_POST['email'],
                        'role' => $_POST['role'],
                        'first_name' => $_POST['first_name'],
                        'last_name' => $_POST['last_name']
                    ];

                    $role = strtolower(trim((string)$params['role']));
                    if ($role === 'admin') {
                        $role = 'super_admin';
                    }
                    $allowed_roles = ['student', 'instructor', 'registrar', 'super_admin'];
                    if (!in_array($role, $allowed_roles, true)) {
                        throw new Exception('Invalid role selected.');
                    }
                    $params['role'] = $role;
                    $year_level = normalize_year_level_value($_POST['year_level'] ?? null);
                    if ($role === 'student' && $year_level === null) {
                        throw new Exception('Year level is required for students.');
                    }

                    // Update password only if provided
                    $passwordSql = '';
                    if (!empty($_POST['password'])) {
                        $params['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $passwordSql = ', password = :password';
                    }

                    // Optionally update updated_at for compatibility
                    $updatedAtSql = '';
                    $yearLevelSql = '';
                    try {
                        $cols_stmt = $conn->prepare('DESCRIBE users');
                        $cols_stmt->execute();
                        $has_updated_at = false;
                        $has_year_level = false;
                        foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            if (($r['Field'] ?? null) === 'updated_at') {
                                $has_updated_at = true;
                            }
                            if (($r['Field'] ?? null) === 'year_level') {
                                $has_year_level = true;
                            }
                        }
                        if ($has_updated_at) {
                            $params['updated_at'] = $db_now;
                            $updatedAtSql = ', updated_at = :updated_at';
                        }
                        if ($has_year_level) {
                            $params['year_level'] = ($role === 'student') ? $year_level : null;
                            $yearLevelSql = ', year_level = :year_level';
                        }
                    } catch (Throwable $e) {
                        // ignore
                    }

                    // Update user
                    $stmt = $conn->prepare("
                        UPDATE users 
                        SET username = :username,
                            email = :email,
                            role = :role,
                            first_name = :first_name,
                            last_name = :last_name" . $passwordSql . $yearLevelSql . $updatedAtSql . "
                        WHERE id = :id
                    ");
                    $stmt->execute($params);
                    $_SESSION['success'] = "User updated successfully.";
                    break;

                case 'delete':
                    try {
                        if (!isset($_POST['user_id']) || !isset($_POST['user_role'])) {
                            throw new Exception("Missing required parameters for deletion.");
                        }

                        $conn->beginTransaction();
                        
                        $user_id = $_POST['user_id'];
                        $role = $_POST['user_role'];
                        
                        error_log("Starting deletion process for user ID: $user_id, Role: $role");
                        
                        // First, check if the user exists
                        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $user = $stmt->fetch();
                        
                        if (!$user) {
                            throw new Exception("User not found with ID: $user_id");
                        }

                        // Delete related enrollments if user is a student
                        if ($role === 'student') {
                            $stmt = $conn->prepare("DELETE FROM enrollments WHERE student_id = ?");
                            $stmt->execute([$user_id]);
                            error_log("Deleted enrollments: " . $stmt->rowCount());
                        }
                        
                        // Check for schedules (if instructor)
                        if ($role === 'instructor') {
                            // Check for active schedules
                            $stmt = $conn->prepare("
                                SELECT s.id, c.course_code, c.course_name 
                                FROM schedules s
                                JOIN courses c ON s.course_id = c.id
                                WHERE s.instructor_id = ? AND s.status = 'active'
                            ");
                            $stmt->execute([$user_id]);
                            $active_classes = $stmt->fetchAll();
                            
                            if (count($active_classes) > 0) {
                                $class_list = array_map(function($class) {
                                    return $class['course_code'] . ' - ' . $class['course_name'];
                                }, $active_classes);
                                throw new Exception("Cannot delete instructor with active classes:\n" . implode("\n", $class_list));
                            }

                            // To delete an instructor, we must remove ALL schedules that reference them
                            // (even cancelled/completed), because schedules.instructor_id is a NOT NULL FK.
                            $stmt = $conn->prepare("SELECT id FROM schedules WHERE instructor_id = ?");
                            $stmt->execute([$user_id]);
                            $schedule_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                            if (!empty($schedule_ids)) {
                                $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));

                                // Delete enrollments tied to these schedules first (FK enrollments.schedule_id -> schedules.id)
                                $stmt = $conn->prepare("DELETE FROM enrollments WHERE schedule_id IN ($placeholders)");
                                $stmt->execute($schedule_ids);
                                error_log("Deleted schedule enrollments: " . $stmt->rowCount());

                                // Now delete schedules
                                $stmt = $conn->prepare("DELETE FROM schedules WHERE id IN ($placeholders)");
                                $stmt->execute($schedule_ids);
                                error_log("Deleted schedules: " . $stmt->rowCount());
                            }
                        }
                        
                        // Delete the user
                        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $rowCount = $stmt->rowCount();
                        
                        if ($rowCount === 0) {
                            throw new Exception("Failed to delete user. No rows affected.");
                        }
                        
                        $conn->commit();
                        $_SESSION['success'] = "User deleted successfully.";
                        error_log("Successfully deleted user ID: $user_id");
                        
                    } catch (PDOException $e) {
                        $conn->rollBack();
                        error_log("Database error while deleting user: " . $e->getMessage());
                        $_SESSION['error'] = "Database error: " . $e->getMessage();
                    } catch (Exception $e) {
                        $conn->rollBack();
                        error_log("Error while deleting user: " . $e->getMessage());
                        $_SESSION['error'] = $e->getMessage();
                    }
                    
                    header("Location: users.php");
                    exit();
                    break;
            }
        }
        header('Location: users.php');
        exit();
    } catch (PDOException $e) {
        error_log("Error in user management: " . $e->getMessage());
        $friendly = null;
        $sqlState = $e->getCode();
        $driverCode = null;
        $driverMsg = null;
        if (is_array($e->errorInfo ?? null)) {
            $driverCode = $e->errorInfo[1] ?? null;
            $driverMsg = $e->errorInfo[2] ?? null;
        }

        // Common MySQL errors
        if ($sqlState === '23000' || $driverCode === 1062) {
            $friendly = 'Username or email already exists.';
        } elseif ($driverCode === 1364 && is_string($driverMsg) && preg_match("/Field '([^']+)' doesn't have a default value/i", $driverMsg, $m)) {
            $friendly = 'Database schema requires a value for: ' . $m[1] . '.';
        }

        $_SESSION['error'] = $friendly ?: ('Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

// All Users table pagination (10 rows per page)
$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE 1=1");
$count_stmt->execute();
$total_users = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_users / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// Get paginated users with dependencies
$stmt = $conn->prepare(" 
    SELECT DISTINCT u.*,
           COALESCE(
               CASE 
                   WHEN u.role = 'instructor' THEN (
                       SELECT COUNT(*) 
                       FROM schedules 
                       WHERE instructor_id = u.id 
                       AND status = 'active'
                   )
                   WHEN u.role = 'student' THEN (
                       SELECT COUNT(*) 
                       FROM enrollments 
                       WHERE student_id = u.id 
                       AND status = 'enrolled'
                   )
                   ELSE 0
               END,
               0
           ) as dependencies
    FROM users u 
    WHERE 1=1
    ORDER BY u.role, u.last_name, u.first_name
    LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$from_record = $total_users > 0 ? ($offset + 1) : 0;
$to_record = $total_users > 0 ? min($offset + count($users), $total_users) : 0;

$query_params = $_GET;
unset($query_params['page']);
$pagination_url = function (int $target_page) use ($query_params): string {
    $params = $query_params;
    $params['page'] = $target_page;
    $qs = http_build_query($params);
    return 'users.php' . ($qs !== '' ? ('?' . $qs) : '');
};

$pagination_window = 2;
$pagination_start = max(1, $page - $pagination_window);
$pagination_end = min($total_pages, $page + $pagination_window);

// Debug log
error_log("Fetched users list - Count: " . count($users));
foreach ($users as $user) {
    error_log("User in list - ID: {$user['id']}, Role: {$user['role']}, Name: {$user['first_name']} {$user['last_name']}");
}

function role_label($role) {
    switch ($role) {
        case 'super_admin':
            return 'Super Admin';
        case 'registrar':
            return 'Registrar';
        case 'admin':
            return 'Super Admin';
        case 'instructor':
            return 'Instructor';
        case 'student':
            return 'Student';
        default:
            return ucfirst((string)$role);
    }
}

function role_badge_classes($role) {
    switch ($role) {
        case 'super_admin':
        case 'admin':
            return 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200';
        case 'registrar':
            return 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-200';
        case 'instructor':
            return 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200';
        case 'student':
        default:
            return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Users - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>

<body class="bg-slate-50 text-slate-900">
    <?php
        $today_label = date('l, F j, Y');
        $user_initials = 'SA';
        $full_name = 'Super Admin';
        if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
            $full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
            $first = strtoupper(substr($_SESSION['first_name'] ?? '', 0, 1));
            $last = strtoupper(substr($_SESSION['last_name'] ?? '', 0, 1));
            $user_initials = trim($first . $last);
            if ($user_initials === '') {
                $user_initials = 'SA';
            }
        }
    ?>

    <div class="min-h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full lg:translate-x-0 transition-transform bg-emerald-950 text-emerald-50 border-r border-emerald-900/40">
            <div class="h-16 px-6 flex items-center gap-3 border-b border-emerald-900/40">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-9 w-9 rounded-full bg-emerald-50/10 object-contain" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">PCT Super Admin</div>
                    <div class="text-xs text-emerald-100/70">Management Portal</div>
                </div>
            </div>

            <div class="px-4 py-4">
                <div class="text-[11px] tracking-widest text-emerald-100/60 px-3 mb-2">NAVIGATION</div>

                <nav class="space-y-1">
                    <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-speedometer2"></i>
                        <span class="text-sm font-medium">Dashboard</span>
                    </a>
                    <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50">
                        <i class="bi bi-people"></i>
                        <span class="text-sm font-medium">All Users</span>
                    </a>
                    <a href="instructors.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-person-video3"></i>
                        <span class="text-sm font-medium">Instructors</span>
                    </a>
                    <a href="students.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-mortarboard"></i>
                        <span class="text-sm font-medium">Students</span>
                    </a>
                    <a href="classes.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-book"></i>
                        <span class="text-sm font-medium">Classes</span>
                    </a>
                    <a href="classrooms.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-door-open"></i>
                        <span class="text-sm font-medium">Classrooms</span>
                    </a>
                    <a href="subjects.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-journal-bookmark"></i>
                        <span class="text-sm font-medium">Subjects</span>
                    </a>
                    <a href="courses.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-journal-text"></i>
                        <span class="text-sm font-medium">Courses</span>
                    </a>
                    <a href="schedules.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-calendar3"></i>
                        <span class="text-sm font-medium">Schedules</span>
                    </a>
                    <a href="enrollments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-person-plus"></i>
                        <span class="text-sm font-medium">Enrollments</span>
                    </a>
                    <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-file-earmark-text"></i>
                        <span class="text-sm font-medium">Reports</span>
                    </a>
                    <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-gear"></i>
                        <span class="text-sm font-medium">Settings</span>
                    </a>
                </nav>
            </div>

            <div class="absolute bottom-0 left-0 right-0 p-4">
                <a href="../auth/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/15 border border-transparent hover:border-rose-400/20">
                    <i class="bi bi-box-arrow-right text-rose-300"></i>
                    <span class="text-sm font-semibold">Logout</span>
                </a>
            </div>
        </aside>

        <!-- Overlay (mobile) -->
        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

        <!-- Main -->
        <div id="contentWrap" class="min-h-screen transition-all duration-300">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50">
                            <i class="bi bi-list text-xl"></i>
                        </button>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-slate-500">Super Admin</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-900">All Users</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="relative hidden sm:block">
                            <button id="notifBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50" aria-label="Notifications" aria-haspopup="menu" aria-expanded="false" aria-controls="notifMenu">
                                <span class="relative">
                                    <i class="bi bi-bell text-lg text-slate-700"></i>
                                    <span id="notifDot" class="absolute -right-1 -top-1 min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white <?php echo (($notif_unread_total ?? 0) > 0) ? '' : 'hidden'; ?>"><?php echo htmlspecialchars($notif_badge_label ?? ''); ?></span>
                                </span>
                            </button>

                            <div id="notifMenu" class="absolute right-0 mt-2 w-80 hidden" role="menu" aria-label="Notifications">
                                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                                    <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900">Notifications</div>
                                            <div class="text-xs text-slate-500">Updates and reminders</div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button id="notifMarkRead" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                <i class="bi bi-check2"></i>
                                                <span>Mark as read</span>
                                            </button>
                                            <button id="notifDelete" type="button" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white h-8 w-8 text-slate-700 hover:bg-slate-50" aria-label="Delete notifications">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="p-4">
                                        <?php if (empty($notif_items)): ?>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                                No new notifications.
                                            </div>
                                        <?php else: ?>
                                            <div class="space-y-2">
                                                <?php foreach ($notif_items as $it): ?>
                                                    <a href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="mt-0.5 inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                                                                <i class="bi <?php echo htmlspecialchars($it['icon'] ?? 'bi-bell'); ?>"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($it['title'] ?? 'Notification'); ?></div>
                                                                <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-3 text-xs text-slate-500">
                                            <?php
                                                $ni = (int)($notif_new_instructors ?? 0);
                                                $ne = (int)($notif_new_enrollments ?? 0);
                                                echo htmlspecialchars($ni . ' new instructor(s), ' . $ne . ' new enrollment(s) since last check.');
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:flex items-center gap-3">
                            <div class="h-10 w-10 rounded-full bg-emerald-600 text-white flex items-center justify-center font-semibold">
                                <?php echo htmlspecialchars($user_initials); ?>
                            </div>
                            <div class="text-left">
                                <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($full_name); ?></div>
                                <div class="text-xs text-slate-500">PCT System</div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-6">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 flex items-start justify-between gap-3">
                        <div class="text-sm font-medium">
                            <?php
                                echo htmlspecialchars($_SESSION['success']);
                                unset($_SESSION['success']);
                            ?>
                        </div>
                        <button type="button" class="shrink-0 h-8 w-8 inline-flex items-center justify-center rounded-lg hover:bg-emerald-100" aria-label="Close" onclick="this.parentElement.remove()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800 flex items-start justify-between gap-3 whitespace-pre-line">
                        <div class="text-sm font-medium">
                            <?php
                                echo htmlspecialchars($_SESSION['error']);
                                unset($_SESSION['error']);
                            ?>
                        </div>
                        <button type="button" class="shrink-0 h-8 w-8 inline-flex items-center justify-center rounded-lg hover:bg-rose-100" aria-label="Close" onclick="this.parentElement.remove()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900">All Users</h1>
                        <p class="text-sm text-slate-600">Search, filter, and manage system accounts.</p>
                    </div>
                    <button id="addUserBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                        <i class="bi bi-plus-circle"></i>
                        Add User
                    </button>
                </div>

                <section class="mt-5 bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="p-4 flex flex-col lg:flex-row lg:items-center gap-3 lg:justify-between">
                        <div class="w-full lg:max-w-md">
                            <label class="sr-only" for="userSearch">Search users</label>
                            <div class="relative">
                                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input id="userSearch" type="text" placeholder="Search name, username, email…" class="w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="role-pill inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold bg-emerald-600 text-white" data-role="all">All</button>
                            <button type="button" class="role-pill inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-role="student">Students</button>
                            <button type="button" class="role-pill inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-role="instructor">Instructors</button>
                            <button type="button" class="role-pill inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-role="registrar">Registrar</button>
                            <button type="button" class="role-pill inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-role="super_admin">Super Admin</button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">User</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Email</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Role</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Dependencies</th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="usersBody" class="divide-y divide-slate-100 bg-white">
                                <?php foreach ($users as $user): ?>
                                    <?php
                                        $first_name = (string)($user['first_name'] ?? '');
                                        $last_name = (string)($user['last_name'] ?? '');
                                        $username = (string)($user['username'] ?? '');
                                        $email = (string)($user['email'] ?? '');
                                        $role = (string)($user['role'] ?? '');
                                        if ($role === 'admin') {
                                            $role = 'super_admin';
                                        }

                                        $full = trim($first_name . ' ' . $last_name);
                                        if ($full === '') {
                                            $full = $username !== '' ? $username : 'Unknown';
                                        }

                                        $initials = 'U';
                                        $fi = strtoupper(substr($first_name, 0, 1));
                                        $li = strtoupper(substr($last_name, 0, 1));
                                        $tmp = trim($fi . $li);
                                        if ($tmp !== '') {
                                            $initials = $tmp;
                                        } elseif ($username !== '') {
                                            $initials = strtoupper(substr($username, 0, 1));
                                        }

                                        $dependencies = (int)($user['dependencies'] ?? 0);
                                        $dependency_label = 'No dependencies';
                                        if ($dependencies > 0) {
                                            if ($role === 'instructor') {
                                                $dependency_label = $dependencies . ' active classes';
                                            } elseif ($role === 'student') {
                                                $dependency_label = $dependencies . ' active enrollments';
                                            } else {
                                                $dependency_label = $dependencies . ' dependencies';
                                            }
                                        }

                                        $search_blob = strtolower(trim($full . ' ' . $username . ' ' . $email . ' ' . $role));
                                        $user_payload = [
                                            'id' => $user['id'],
                                            'role' => $role,
                                            'first_name' => $first_name,
                                            'last_name' => $last_name,
                                            'username' => $username,
                                            'email' => $email,
                                            'year_level' => (string)($user['year_level'] ?? '')
                                        ];

                                        $can_manage = ($role !== 'super_admin' || (int)$user['id'] !== (int)($_SESSION['user_id'] ?? 0));
                                    ?>
                                    <tr class="user-row" data-role="<?php echo htmlspecialchars($role); ?>" data-search="<?php echo htmlspecialchars($search_blob); ?>">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="h-10 w-10 rounded-full bg-emerald-100 text-emerald-900 flex items-center justify-center font-semibold">
                                                    <?php echo htmlspecialchars($initials); ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($full); ?></div>
                                                    <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($username); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-slate-700"><?php echo htmlspecialchars($email); ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo htmlspecialchars(role_badge_classes($role)); ?>">
                                                <?php echo htmlspecialchars(role_label($role)); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($dependencies > 0): ?>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-slate-900 text-white">
                                                    <?php echo htmlspecialchars($dependency_label); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-sm text-slate-500">No dependencies</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-end gap-2">
                                                <?php if ($can_manage): ?>
                                                    <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" onclick="editUser(<?php echo htmlspecialchars(json_encode($user_payload), ENT_QUOTES, 'UTF-8'); ?>)" aria-label="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($dependencies === 0): ?>
                                                        <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100" onclick="deleteUser(<?php echo (int)$user['id']; ?>, '<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>')" aria-label="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-400" title="Cannot delete: has dependencies" aria-hidden="true">
                                                            <i class="bi bi-trash"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-xs text-slate-400">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-4 py-3 text-xs text-slate-400 border-t border-slate-100">
                        Showing <?php echo (int)$from_record; ?> to <?php echo (int)$to_record; ?> of <?php echo (int)$total_users; ?> records
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="px-4 py-3 border-t border-slate-100 flex items-center justify-between gap-3">
                            <div class="text-xs text-slate-400">Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></div>
                            <div class="flex items-center gap-1.5">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo htmlspecialchars($pagination_url($page - 1)); ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">Prev</a>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-400">Prev</span>
                                <?php endif; ?>

                                <?php for ($p = $pagination_start; $p <= $pagination_end; $p++): ?>
                                    <a href="<?php echo htmlspecialchars($pagination_url($p)); ?>" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-semibold <?php echo $p === $page ? 'bg-emerald-600 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'; ?>">
                                        <?php echo (int)$p; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo htmlspecialchars($pagination_url($page + 1)); ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">Next</a>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-400">Next</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addUserModal"></div>
        <div class="relative mx-auto my-8 w-full max-w-lg px-4">
            <div class="rounded-2xl bg-white border border-slate-200 shadow-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <div class="text-base font-semibold text-slate-900">Add User</div>
                        <div class="text-sm text-slate-600">Create a new account.</div>
                    </div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addUserModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="users.php" method="POST" class="p-5 space-y-4">
                    <input type="hidden" name="action" value="add">

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Role</label>
                        <select id="add_role" name="role" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300">
                            <option value="student">Student</option>
                            <option value="instructor">Instructor</option>
                            <option value="registrar">Registrar</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>

                    <div id="add_year_level_group">
                        <label class="block text-sm font-medium text-slate-700">Year Level</label>
                        <select id="add_year_level" name="year_level" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300">
                            <option value="">Select year level</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">First Name</label>
                            <input type="text" name="first_name" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Last Name</label>
                            <input type="text" name="last_name" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Username</label>
                        <input type="text" name="username" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Email</label>
                        <input type="email" name="email" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Password</label>
                        <input type="password" name="password" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>

                    <div class="pt-2 flex items-center justify-end gap-2">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addUserModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editUserModal"></div>
        <div class="relative mx-auto my-8 w-full max-w-lg px-4">
            <div class="rounded-2xl bg-white border border-slate-200 shadow-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <div class="text-base font-semibold text-slate-900">Edit User</div>
                        <div class="text-sm text-slate-600">Update user information.</div>
                    </div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editUserModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="users.php" method="POST" class="p-5 space-y-4">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="edit_user_id">

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Role</label>
                        <select name="role" id="edit_role" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300">
                            <option value="student">Student</option>
                            <option value="instructor">Instructor</option>
                            <option value="registrar">Registrar</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>

                    <div id="edit_year_level_group">
                        <label class="block text-sm font-medium text-slate-700">Year Level</label>
                        <select name="year_level" id="edit_year_level" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300">
                            <option value="">Select year level</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">First Name</label>
                            <input type="text" name="first_name" id="edit_first_name" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Last Name</label>
                            <input type="text" name="last_name" id="edit_last_name" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Username</label>
                        <input type="text" name="username" id="edit_username" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Email</label>
                        <input type="email" name="email" id="edit_email" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">New Password <span class="text-slate-400 font-normal">(leave blank to keep current)</span></label>
                        <input type="password" name="password" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>

                    <div class="pt-2 flex items-center justify-end gap-2">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="editUserModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const btn = document.getElementById('sidebarBtn');
            const contentWrap = document.getElementById('contentWrap');
            let desktopExpanded = true;

            function isDesktop() {
                return window.innerWidth >= 1024;
            }

            function ensureSidebarCompactStyles() {
                if (document.getElementById('adminSidebarCompactStyles')) return;
                const style = document.createElement('style');
                style.id = 'adminSidebarCompactStyles';
                style.textContent = '#sidebar.sidebar-compact .leading-tight,#sidebar.sidebar-compact nav a span,#sidebar.sidebar-compact .absolute.bottom-0 a span{display:none;}#sidebar.sidebar-compact .px-4.py-4 > div:first-child{display:none;}#sidebar.sidebar-compact .h-16,#sidebar.sidebar-compact nav a,#sidebar.sidebar-compact .absolute.bottom-0 a{justify-content:center;padding-left:.5rem;padding-right:.5rem;}#sidebar.sidebar-compact nav a i{font-size:1.38rem;}';
                document.head.appendChild(style);
            }

            function setSidebarOpen(open) {
                if (!sidebar) return;

                if (isDesktop()) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.toggle('sidebar-compact', !open);
                    const desktopWidth = open ? 250 : 86;
                    sidebar.style.width = desktopWidth + 'px';
                    if (contentWrap) {
                        contentWrap.style.marginLeft = desktopWidth + 'px';
                    }
                } else {
                    sidebar.classList.remove('sidebar-compact');
                    sidebar.style.width = '250px';
                    sidebar.classList.toggle('-translate-x-full', !open);
                    if (contentWrap) {
                        contentWrap.style.marginLeft = '0px';
                    }
                }

                if (overlay) {
                    overlay.classList.toggle('hidden', !open || isDesktop());
                }
            }

            function applyLayoutState() {
                if (isDesktop()) {
                    setSidebarOpen(desktopExpanded);
                    return;
                }
                setSidebarOpen(false);
            }

            function isSidebarOpen() {
                if (!sidebar) return false;
                return isDesktop()
                    ? !sidebar.classList.contains('sidebar-compact')
                    : !sidebar.classList.contains('-translate-x-full');
            }

            btn?.addEventListener('click', function () {
                const currentlyOpen = isSidebarOpen();
                if (isDesktop()) {
                    desktopExpanded = !currentlyOpen;
                    setSidebarOpen(desktopExpanded);
                    return;
                }
                setSidebarOpen(!currentlyOpen);
            });

            overlay?.addEventListener('click', function () {
                setSidebarOpen(false);
            });

            window.openSidebar = function () { setSidebarOpen(true); };
            window.closeSidebar = function () { setSidebarOpen(false); };

            ensureSidebarCompactStyles();
            window.addEventListener('resize', applyLayoutState);
            applyLayoutState();

            function openModal(id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('hidden');
                el.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            }

            function closeModal(id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.add('hidden');
                el.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }

            document.getElementById('addUserBtn')?.addEventListener('click', function () {
                openModal('addUserModal');
            });

            const addRole = document.getElementById('add_role');
            const addYearGroup = document.getElementById('add_year_level_group');
            const addYear = document.getElementById('add_year_level');
            const editRole = document.getElementById('edit_role');
            const editYearGroup = document.getElementById('edit_year_level_group');
            const editYear = document.getElementById('edit_year_level');

            function syncYearLevelVisibility(roleSelect, groupEl, yearSelect) {
                if (!roleSelect || !groupEl || !yearSelect) return;
                const isStudent = (roleSelect.value || '').toLowerCase() === 'student';
                groupEl.classList.toggle('hidden', !isStudent);
                yearSelect.required = isStudent;
                if (!isStudent) {
                    yearSelect.value = '';
                }
            }

            addRole?.addEventListener('change', function () {
                syncYearLevelVisibility(addRole, addYearGroup, addYear);
            });

            editRole?.addEventListener('change', function () {
                syncYearLevelVisibility(editRole, editYearGroup, editYear);
            });

            syncYearLevelVisibility(addRole, addYearGroup, addYear);
            syncYearLevelVisibility(editRole, editYearGroup, editYear);

            document.querySelectorAll('[data-modal-close]')?.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = btn.getAttribute('data-modal-close');
                    if (target) closeModal(target);
                });
            });

            window.editUser = function (userData) {
                document.getElementById('edit_user_id').value = userData.id;
                const normalizedRole = (userData.role === 'admin') ? 'super_admin' : userData.role;
                document.getElementById('edit_role').value = normalizedRole;
                document.getElementById('edit_year_level').value = userData.year_level || '';
                document.getElementById('edit_first_name').value = userData.first_name;
                document.getElementById('edit_last_name').value = userData.last_name;
                document.getElementById('edit_username').value = userData.username;
                document.getElementById('edit_email').value = userData.email;
                syncYearLevelVisibility(editRole, editYearGroup, editYear);
                openModal('editUserModal');
            }

            window.deleteUser = function (userId, userRole) {
                if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'users.php';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete';

                    const userIdInput = document.createElement('input');
                    userIdInput.type = 'hidden';
                    userIdInput.name = 'user_id';
                    userIdInput.value = userId;

                    const userRoleInput = document.createElement('input');
                    userRoleInput.type = 'hidden';
                    userRoleInput.name = 'user_role';
                    userRoleInput.value = userRole;

                    form.appendChild(actionInput);
                    form.appendChild(userIdInput);
                    form.appendChild(userRoleInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            }

            const searchInput = document.getElementById('userSearch');
            const rolePills = Array.from(document.querySelectorAll('.role-pill'));
            const rows = Array.from(document.querySelectorAll('.user-row'));
            let activeRole = 'all';

            function applyFilters() {
                const q = (searchInput?.value || '').trim().toLowerCase();
                rows.forEach(function (row) {
                    const role = (row.getAttribute('data-role') || '').toLowerCase();
                    const blob = (row.getAttribute('data-search') || '').toLowerCase();
                    const matchRole = activeRole === 'all' || role === activeRole;
                    const matchSearch = q === '' || blob.includes(q);
                    row.hidden = !(matchRole && matchSearch);
                });
            }

            rolePills.forEach(function (pill) {
                pill.addEventListener('click', function () {
                    activeRole = pill.getAttribute('data-role') || 'all';
                    rolePills.forEach(function (p) {
                        p.classList.remove('bg-emerald-600', 'text-white');
                        p.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-700');
                    });
                    pill.classList.add('bg-emerald-600', 'text-white');
                    pill.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-700');
                    applyFilters();
                });
            });

            searchInput?.addEventListener('input', applyFilters);
            applyFilters();

            // Deep-link helpers (used by Instructors/Students pages)
            try {
                const params = new URLSearchParams(window.location.search);
                const q = (params.get('q') || '').trim();
                if (q && searchInput) {
                    searchInput.value = q;
                }
                if (params.get('add') === '1') {
                    const role = (params.get('role') || '').toLowerCase();
                    const roleSelect = document.getElementById('add_role');
                    if (roleSelect && role) {
                        const allowed = new Set(['student', 'instructor', 'registrar', 'super_admin']);
                        if (allowed.has(role)) {
                            roleSelect.value = role;
                            syncYearLevelVisibility(addRole, addYearGroup, addYear);
                        }
                    }
                    openModal('addUserModal');
                }
            } catch (e) {
                // no-op
            }

            applyFilters();
        })();

        (function () {
            const btn = document.getElementById('notifBtn');
            const menu = document.getElementById('notifMenu');
            const dot = document.getElementById('notifDot');
            if (!btn || !menu) return;

            function isOpen() {
                return !menu.classList.contains('hidden');
            }

            const markBtn = document.getElementById('notifMarkRead');
            const delBtn = document.getElementById('notifDelete');

            async function postAction(action) {
                try {
                    const res = await fetch('notifications_seen.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + encodeURIComponent(action)
                    });
                    return res.ok;
                } catch (_) {
                    return false;
                }
            }

            function openMenu() {
                menu.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
            }

            function closeMenu() {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }

            function toggleMenu() {
                if (isOpen()) closeMenu();
                else openMenu();
            }

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMenu();
            });

            markBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('seen');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            delBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('delete');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            document.addEventListener('click', function (e) {
                if (!isOpen()) return;
                const target = e.target;
                if (!(target instanceof Element)) return;
                if (menu.contains(target) || btn.contains(target)) return;
                closeMenu();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
            });
        })();
    </script>
</body>
</html>