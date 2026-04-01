<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

// Add search and filter functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Main query (uses schedules; older versions referenced a non-existent `classes` table)
$stmt = $conn->prepare("
    SELECT u.*, 
           COUNT(DISTINCT s.id) as class_count,
           COUNT(DISTINCT e.student_id) as student_count,
           GROUP_CONCAT(DISTINCT c.course_code ORDER BY c.course_code SEPARATOR ', ') as courses
    FROM users u
    LEFT JOIN schedules s ON u.id = s.instructor_id AND s.status = 'active'
    LEFT JOIN courses c ON s.course_id = c.id
    LEFT JOIN enrollments e ON e.schedule_id = s.id AND e.status IN ('enrolled', 'approved')
    WHERE u.role = 'instructor'
    " . (!empty($search) ? "AND (u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search)" : "") . "
    " . ($filter !== 'all' ? 
        ($filter === 'active' ? 
            "AND EXISTS (SELECT 1 FROM schedules s2 WHERE s2.instructor_id = u.id AND s2.status = 'active')" : 
            "AND NOT EXISTS (SELECT 1 FROM schedules s2 WHERE s2.instructor_id = u.id AND s2.status = 'active')"
        ) : "") . "
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
");

if (!empty($search)) {
    $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
}
$stmt->execute();
$instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total counts for filters
$total_instructors = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn();
$active_instructors = $conn->query("
    SELECT COUNT(DISTINCT u.id) 
    FROM users u 
    JOIN schedules s ON u.id = s.instructor_id 
    WHERE u.role = 'instructor' AND s.status = 'active'")->fetchColumn();
$inactive_instructors = $total_instructors - $active_instructors;

function initials_for($first_name, $last_name, $fallback) {
    $fi = strtoupper(substr((string)$first_name, 0, 1));
    $li = strtoupper(substr((string)$last_name, 0, 1));
    $tmp = trim($fi . $li);
    if ($tmp !== '') {
        return $tmp;
    }
    $fallback = (string)$fallback;
    if ($fallback !== '') {
        return strtoupper(substr($fallback, 0, 2));
    }
    return 'IN';
}

function instructor_code($id) {
    return 'INS-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

function instructor_status_label($status) {
    return ($status === 'inactive') ? 'On Leave' : 'Active';
}

function instructor_status_classes($status) {
    if ($status === 'inactive') {
        return 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200';
    }
    return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructors - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>

<body class="bg-slate-50 text-slate-900">
    <?php
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

        $featured_instructors = array_slice($instructors, 0, 4);
        $showing = count($instructors);
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
                    <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-people"></i>
                        <span class="text-sm font-medium">All Users</span>
                    </a>
                    <a href="instructors.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50">
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
            <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50">
                            <i class="bi bi-list text-xl"></i>
                        </button>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-slate-500">Super Admin</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-900">Instructors</span>
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
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold text-slate-900">Instructors</h1>
                        <p class="text-sm text-slate-600"><?php echo (int)$total_instructors; ?> faculty members registered</p>
                    </div>
                    <a href="users.php?add=1&role=instructor" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                        <i class="bi bi-plus"></i>
                        Add Instructor
                    </a>
                </div>

                <section class="mt-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                    <?php foreach ($featured_instructors as $ins): ?>
                        <?php
                            $name = trim(($ins['first_name'] ?? '') . ' ' . ($ins['last_name'] ?? ''));
                            if ($name === '') {
                                $name = (string)($ins['username'] ?? 'Instructor');
                            }
                            $ins_initials = initials_for($ins['first_name'] ?? '', $ins['last_name'] ?? '', $ins['username'] ?? '');
                            $code = instructor_code($ins['id']);
                            $status = (string)($ins['status'] ?? 'active');
                            $status_label = instructor_status_label($status);
                            $status_classes = instructor_status_classes($status);
                            $class_count = (int)($ins['class_count'] ?? 0);
                            $student_count = (int)($ins['student_count'] ?? 0);
                            $email = (string)($ins['email'] ?? '');
                            $q = urlencode($email !== '' ? $email : $name);
                        ?>
                        <article class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-emerald-200">
                            <div class="h-1 bg-emerald-500"></div>
                            <div class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="h-11 w-11 rounded-xl bg-blue-600 text-white flex items-center justify-center font-semibold">
                                            <?php echo htmlspecialchars($ins_initials); ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($name); ?></div>
                                            <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($code); ?></div>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo htmlspecialchars($status_classes); ?>">
                                        <?php echo htmlspecialchars($status_label); ?>
                                    </span>
                                </div>

                                <div class="mt-4 space-y-2 text-sm text-slate-600">
                                    <div class="text-xs font-medium text-slate-500">Department</div>
                                    <div class="text-sm text-slate-700">—</div>
                                    <div class="pt-2 space-y-1">
                                        <div class="flex items-center gap-2 text-sm">
                                            <i class="bi bi-envelope text-slate-400"></i>
                                            <span class="truncate"><?php echo htmlspecialchars($email !== '' ? $email : '—'); ?></span>
                                        </div>
                                        <div class="flex items-center gap-2 text-sm">
                                            <i class="bi bi-telephone text-slate-400"></i>
                                            <span>—</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                    <div class="text-xs text-slate-500"><?php echo $class_count; ?> courses • <?php echo $student_count; ?> students</div>
                                    <div class="flex items-center gap-2">
                                        <a href="users.php?q=<?php echo htmlspecialchars($q); ?>" class="h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Manage in All Users" aria-label="Manage">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="users.php?q=<?php echo htmlspecialchars($q); ?>" class="h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Edit in All Users" aria-label="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="users.php?q=<?php echo htmlspecialchars($q); ?>" class="h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Delete in All Users" aria-label="Delete">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="mt-6 bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="p-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-2 text-sm text-slate-500">
                            <i class="bi bi-funnel"></i>
                            Showing <?php echo (int)$showing; ?> of <?php echo (int)$total_instructors; ?> instructors
                        </div>

                        <form method="GET" class="w-full max-w-md">
                            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>" />
                            <label class="sr-only" for="insSearch">Search instructors</label>
                            <div class="relative">
                                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input id="insSearch" name="search" value="<?php echo htmlspecialchars($search); ?>" type="text" placeholder="Search instructors..." class="w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                            </div>
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Instructor</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Department</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Courses</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Students</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php foreach ($instructors as $ins): ?>
                                    <?php
                                        $name = trim(($ins['first_name'] ?? '') . ' ' . ($ins['last_name'] ?? ''));
                                        if ($name === '') {
                                            $name = (string)($ins['username'] ?? 'Instructor');
                                        }
                                        $ins_initials = initials_for($ins['first_name'] ?? '', $ins['last_name'] ?? '', $ins['username'] ?? '');
                                        $code = instructor_code($ins['id']);
                                        $status = (string)($ins['status'] ?? 'active');
                                        $status_label = instructor_status_label($status);
                                        $status_classes = instructor_status_classes($status);
                                        $student_count = (int)($ins['student_count'] ?? 0);
                                        $courses_str = (string)($ins['courses'] ?? '');
                                        $course_codes = array_values(array_filter(array_map('trim', explode(',', $courses_str))));
                                        $course_preview = array_slice($course_codes, 0, 2);
                                        $course_more = max(0, count($course_codes) - count($course_preview));
                                        $email = (string)($ins['email'] ?? '');
                                        $q = urlencode($email !== '' ? $email : $name);
                                    ?>
                                    <tr>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="h-10 w-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-semibold">
                                                    <?php echo htmlspecialchars($ins_initials); ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($name); ?></div>
                                                    <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($code); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-slate-600">—</td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if (!empty($course_preview)): ?>
                                                    <?php foreach ($course_preview as $cc): ?>
                                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700"><?php echo htmlspecialchars($cc); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if ($course_more > 0): ?>
                                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">+<?php echo $course_more; ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-sm text-slate-500">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-slate-700"><?php echo $student_count; ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo htmlspecialchars($status_classes); ?>">
                                                <?php echo htmlspecialchars($status_label); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-end gap-2">
                                                <a href="users.php?q=<?php echo htmlspecialchars($q); ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Manage in All Users" aria-label="Manage">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="users.php?q=<?php echo htmlspecialchars($q); ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Edit in All Users" aria-label="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="users.php?q=<?php echo htmlspecialchars($q); ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Delete in All Users" aria-label="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($instructors)): ?>
                                    <tr>
                                        <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">No instructors found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
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