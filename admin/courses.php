<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

// Pagination (6 per page)
$per_page = 6;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$total_courses = (int)$conn->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$total_pages = (int)max(1, (int)ceil($total_courses / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$qs_prev = http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
$qs_next = http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)]));

// Get paginated courses with their schedule and enrollment counts
$stmt = $conn->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM schedules WHERE course_id = c.id AND status = 'active') as schedule_count,
           (SELECT COUNT(*) FROM enrollments e
            JOIN schedules s ON e.schedule_id = s.id
            WHERE s.course_id = c.id AND e.status IN ('approved', 'enrolled')) as enrollment_count
    FROM courses c
    ORDER BY c.course_code
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$card_colors = [
    'bg-blue-600',
    'bg-emerald-600',
    'bg-orange-500',
    'bg-violet-600',
    'bg-sky-600',
    'bg-rose-600',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courses - PCT Class Scheduling</title>
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
                    <a href="courses.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50">
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
                    <a href="../activity.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-clock-history"></i>
                        <span class="text-sm font-medium">Activity</span>
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
                            <span class="font-semibold text-slate-900">Courses</span>
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
                                        <div class="flex items-center gap-3">
                                            <button id="notifMarkRead" type="button" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700">Mark as read</button>
                                            <button id="notifDelete" type="button" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Delete</button>
                                        </div>
                                    </div>
                                    <div class="p-3">
                                        <?php if (empty($notif_items)): ?>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">
                                                No new notifications.
                                            </div>
                                        <?php else: ?>
                                            <div class="space-y-2">
                                                <?php foreach ($notif_items as $it): ?>
                                                    <a href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
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
                        <h1 class="text-2xl font-semibold text-slate-900">Courses</h1>
                        <p class="text-sm text-slate-500"><?php echo (int)$total_courses; ?> courses</p>
                    </div>

                    <button id="addCourseBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                        <i class="bi bi-plus-lg"></i>
                        <span>Add Course</span>
                    </button>
                </div>

                <?php if (isset($_SESSION['success']) || isset($_SESSION['error'])): ?>
                    <div class="mt-4">
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
                                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-6">
                    <div class="relative max-w-md">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input id="courseSearch" type="text" placeholder="Search courses…" class="w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-200" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <?php foreach ($courses as $i => $course): ?>
                        <?php $color = $card_colors[$i % count($card_colors)]; ?>
                        <div class="course-card rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden" data-search="<?php echo htmlspecialchars(strtolower(($course['course_code'] ?? '') . ' ' . ($course['course_name'] ?? ''))); ?>">
                            <div class="p-5 <?php echo $color; ?> text-white">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-xs font-semibold opacity-90"><?php echo htmlspecialchars($course['course_code'] ?? ''); ?></div>
                                        <div class="mt-1 text-lg font-semibold leading-snug"><?php echo htmlspecialchars($course['course_name'] ?? ''); ?></div>
                                    </div>
                                    <div class="h-10 w-10 rounded-xl bg-white/15 flex items-center justify-center">
                                        <i class="bi bi-book text-xl"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="p-5">
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-3 text-center">
                                        <div class="text-lg font-semibold text-slate-900"><?php echo (int)($course['enrollment_count'] ?? 0); ?></div>
                                        <div class="text-xs text-slate-500">Students</div>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-3 text-center">
                                        <div class="text-lg font-semibold text-slate-900"><?php echo (int)($course['schedule_count'] ?? 0); ?></div>
                                        <div class="text-xs text-slate-500">Classes</div>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center justify-between">
                                    <?php if ((int)($course['schedule_count'] ?? 0) > 0): ?>
                                        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 px-3 py-1 text-xs font-semibold">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200 px-3 py-1 text-xs font-semibold">
                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-500"></span>
                                            No classes
                                        </span>
                                    <?php endif; ?>

                                    <div class="flex items-center gap-1.5 text-slate-500">
                                        <a class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" href="classes.php?search=<?php echo urlencode((string)($course['course_code'] ?? '')); ?>" title="View classes">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick='editCourse(<?php echo json_encode($course, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ((int)($course['schedule_count'] ?? 0) === 0): ?>
                                            <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick="deleteCourse(<?php echo (int)$course['id']; ?>)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="mt-8 flex items-center justify-between">
                        <div class="text-sm text-slate-500">Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></div>
                        <div class="flex items-center gap-2">
                            <a href="?<?php echo htmlspecialchars($qs_prev); ?>" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 <?php echo ($page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">Prev</a>
                            <a href="?<?php echo htmlspecialchars($qs_next); ?>" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 <?php echo ($page >= $total_pages) ? 'pointer-events-none opacity-50' : ''; ?>">Next</a>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Add Course Modal -->
    <div id="addCourseModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addCourseModal"></div>
        <div class="relative mx-auto my-10 w-[92%] max-w-xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Add Course</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addCourseModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="process_course.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="add">
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course code</label>
                            <input type="text" name="course_code" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course name</label>
                            <input type="text" name="course_name" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <input type="hidden" name="units" value="3">
                        </div>
                    </div>
                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addCourseModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Add Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Course Modal -->
    <div id="editCourseModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editCourseModal"></div>
        <div class="relative mx-auto my-10 w-[92%] max-w-xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Edit Course</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editCourseModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="process_course.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="course_id" id="edit_course_id">
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course code</label>
                            <input type="text" name="course_code" id="edit_course_code" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course name</label>
                            <input type="text" name="course_name" id="edit_course_name" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <input type="hidden" name="units" id="edit_units" value="3">
                        </div>
                    </div>
                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="editCourseModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Update Course</button>
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

            document.getElementById('addCourseBtn')?.addEventListener('click', function () {
                openModal('addCourseModal');
            });
            document.querySelectorAll('[data-modal-close]')?.forEach(function (b) {
                b.addEventListener('click', function () {
                    const target = b.getAttribute('data-modal-close');
                    if (target) closeModal(target);
                });
            });

            window.editCourse = function (courseData) {
                document.getElementById('edit_course_id').value = courseData.id;
                document.getElementById('edit_course_code').value = courseData.course_code || '';
                document.getElementById('edit_course_name').value = courseData.course_name || '';
                document.getElementById('edit_units').value = courseData.units || courseData.credits || 3;
                openModal('editCourseModal');
            }

            window.deleteCourse = function (courseId) {
                if (confirm('Are you sure you want to delete this course? This action cannot be undone.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'process_course.php';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'course_id';
                    idInput.value = courseId;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            }

            const searchInput = document.getElementById('courseSearch');
            const cards = Array.from(document.querySelectorAll('.course-card'));
            function applySearch() {
                const q = (searchInput?.value || '').trim().toLowerCase();
                cards.forEach(function (c) {
                    const hay = (c.getAttribute('data-search') || '').toLowerCase();
                    c.style.display = (!q || hay.includes(q)) ? '' : 'none';
                });
            }
            searchInput?.addEventListener('input', applySearch);
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