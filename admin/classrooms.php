<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

// Schedules schema compatibility (some DB versions don't store end_time)
$schedule_cols_stmt = $conn->prepare('DESCRIBE schedules');
$schedule_cols_stmt->execute();
$schedule_cols = [];
foreach ($schedule_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $schedule_cols[$r['Field']] = true;
}

if (isset($schedule_cols['end_time'])) {
    $end_time_expr_s0 = 's0.end_time';
} elseif (isset($schedule_cols['duration_minutes'])) {
    $end_time_expr_s0 = 'ADDTIME(s0.start_time, SEC_TO_TIME(s0.duration_minutes * 60))';
} else {
    $end_time_expr_s0 = 'ADDTIME(s0.start_time, SEC_TO_TIME(120 * 60))';
}

// Get all classrooms + active schedule counts + a sample active schedule (for card display)
$stmt = $conn->prepare("
    SELECT
        c.*,
        (SELECT COUNT(*) FROM schedules s WHERE s.classroom_id = c.id AND s.status = 'active') AS schedule_count,
        s0.id AS schedule_id,
        s0.day_of_week AS schedule_day,
        s0.start_time AS schedule_start,
        {$end_time_expr_s0} AS schedule_end,
        co.course_code AS schedule_course_code,
        co.course_name AS schedule_course_name
    FROM classrooms c
    LEFT JOIN schedules s0
        ON s0.id = (
            SELECT s2.id
            FROM schedules s2
            WHERE s2.classroom_id = c.id AND s2.status = 'active'
            ORDER BY s2.day_of_week, s2.start_time
            LIMIT 1
        )
    LEFT JOIN courses co ON co.id = s0.course_id
    ORDER BY c.room_number
");
$stmt->execute();
$classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

function time_range_label($start, $end) {
    if (!$start || !$end) return '';
    return date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end));
}

function room_type_label($room_type) {
    $room_type = strtolower(trim((string)$room_type));
    if ($room_type === 'lecture') return 'Lecture Room';
    if ($room_type === 'laboratory' || $room_type === 'computer') return 'Computer Lab';
    if ($room_type === 'conference') return 'Seminar Hall';
    return ucfirst($room_type);
}

function room_type_pill_classes($room_type) {
    $room_type = strtolower(trim((string)$room_type));
    if ($room_type === 'lecture') return 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200';
    if ($room_type === 'laboratory' || $room_type === 'computer') return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200';
    if ($room_type === 'conference') return 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200';
    return 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200';
}

function room_state($row) {
    $status = strtolower((string)($row['status'] ?? 'active'));
    if ($status === 'maintenance' || $status === 'inactive') return 'maintenance';
    $sc = (int)($row['schedule_count'] ?? 0);
    if ($sc > 0) return 'occupied';
    return 'available';
}

function state_badge($state) {
    if ($state === 'available') return ['Available', 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200'];
    if ($state === 'occupied') return ['Occupied', 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200'];
    return ['Maintenance', 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200'];
}

$available_count = 0;
$occupied_count = 0;
$maintenance_count = 0;
foreach ($classrooms as $r) {
    $st = room_state($r);
    if ($st === 'available') $available_count++;
    else if ($st === 'occupied') $occupied_count++;
    else $maintenance_count++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classrooms - PCT Class Scheduling</title>
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
                    <a href="classrooms.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50">
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
                            <span class="font-semibold text-slate-900">Classrooms</span>
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
                        <h1 class="text-2xl font-semibold text-slate-900">Classrooms</h1>
                        <p class="text-sm text-slate-500"><?php echo (int)count($classrooms); ?> rooms registered</p>
                    </div>

                    <button id="addRoomBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                        <i class="bi bi-plus-lg"></i>
                        <span>Add Room</span>
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

                <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-emerald-300">
                        <div class="text-3xl font-semibold text-emerald-800"><?php echo (int)$available_count; ?></div>
                        <div class="mt-1 text-sm text-emerald-700"><span class="inline-block h-2 w-2 rounded-full bg-emerald-500 mr-2"></span>Available</div>
                    </div>
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-rose-300">
                        <div class="text-3xl font-semibold text-rose-800"><?php echo (int)$occupied_count; ?></div>
                        <div class="mt-1 text-sm text-rose-700"><span class="inline-block h-2 w-2 rounded-full bg-rose-500 mr-2"></span>Occupied</div>
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-amber-300">
                        <div class="text-3xl font-semibold text-amber-800"><?php echo (int)$maintenance_count; ?></div>
                        <div class="mt-1 text-sm text-amber-700"><span class="inline-block h-2 w-2 rounded-full bg-amber-500 mr-2"></span>Maintenance</div>
                    </div>
                </div>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm p-4 sm:p-5">
                    <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                        <div class="relative flex-1">
                            <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input id="roomSearch" type="text" placeholder="Search rooms…" class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 py-2.5 text-sm outline-none focus:bg-white focus:ring-2 focus:ring-emerald-200" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="room-pill px-3 py-2 rounded-xl text-sm font-semibold border border-slate-200 bg-emerald-600 text-white" data-type="all">All</button>
                            <button type="button" class="room-pill px-3 py-2 rounded-xl text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-type="lecture">Lecture Room</button>
                            <button type="button" class="room-pill px-3 py-2 rounded-xl text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-type="computer">Computer Lab</button>
                            <button type="button" class="room-pill px-3 py-2 rounded-xl text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-type="conference">Seminar Hall</button>
                            <button type="button" class="room-pill px-3 py-2 rounded-xl text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-type="laboratory">Laboratory</button>
                        </div>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    <?php foreach ($classrooms as $room): ?>
                        <?php
                            $state = room_state($room);
                            [$badge_label, $badge_classes] = state_badge($state);
                            $top = ($state === 'available') ? 'bg-emerald-500' : (($state === 'occupied') ? 'bg-rose-500' : 'bg-amber-500');
                            $type_key = strtolower(trim((string)($room['room_type'] ?? '')));
                            $search = strtolower(trim((string)($room['room_number'] ?? '') . ' ' . (string)($room['building'] ?? '') . ' ' . room_type_label($room['room_type'] ?? '') ));
                        ?>
                        <div class="room-card rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden" data-type="<?php echo htmlspecialchars($type_key); ?>" data-search="<?php echo htmlspecialchars($search); ?>">
                            <div class="h-1.5 <?php echo $top; ?>"></div>
                            <div class="p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-lg font-semibold text-slate-900"><?php echo htmlspecialchars($room['room_number'] ?? ''); ?></div>
                                        <div class="mt-1">
                                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?php echo room_type_pill_classes($room['room_type'] ?? ''); ?>">
                                                <?php echo htmlspecialchars(room_type_label($room['room_type'] ?? '')); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?php echo $badge_classes; ?>"><?php echo htmlspecialchars($badge_label); ?></span>
                                </div>

                                <div class="mt-4 space-y-2 text-sm text-slate-600">
                                    <div class="flex items-center gap-2"><i class="bi bi-people text-slate-400"></i><span>Capacity: <?php echo (int)($room['capacity'] ?? 0); ?></span></div>
                                    <?php if (!empty($room['building'])): ?>
                                        <div class="flex items-center gap-2"><i class="bi bi-building text-slate-400"></i><span><?php echo htmlspecialchars($room['building']); ?></span></div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                                    <?php if (!empty($room['schedule_id'])): ?>
                                        <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars(($room['schedule_course_name'] ?? '') . ' (' . ($room['schedule_course_code'] ?? '') . ')'); ?></div>
                                        <div class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars(($room['schedule_day'] ?? '') . ' • ' . time_range_label($room['schedule_start'] ?? '', $room['schedule_end'] ?? '')); ?></div>
                                    <?php else: ?>
                                        <div class="text-sm text-slate-600">No active class</div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-4 flex items-center justify-end gap-2 text-slate-500">
                                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick='editRoom(<?php echo json_encode($room, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ((int)($room['schedule_count'] ?? 0) === 0): ?>
                                        <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick="deleteRoom(<?php echo (int)$room['id']; ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Room Modal -->
    <div id="addRoomModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addRoomModal"></div>
        <div class="relative mx-auto my-10 w-[92%] max-w-xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Add Room</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addRoomModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="process_classroom.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="add">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Room number</label>
                            <input type="text" name="room_number" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Capacity</label>
                            <input type="number" name="capacity" min="1" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Room type</label>
                            <select name="room_type" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="lecture">Lecture Room</option>
                                <option value="computer">Computer Lab</option>
                                <option value="conference">Seminar Hall</option>
                                <option value="laboratory">Laboratory</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                            <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="active" selected>Active</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addRoomModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Add Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Room Modal -->
    <div id="editRoomModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editRoomModal"></div>
        <div class="relative mx-auto my-10 w-[92%] max-w-xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Edit Room</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editRoomModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="process_classroom.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="classroom_id" id="edit_classroom_id">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Room number</label>
                            <input type="text" name="room_number" id="edit_room_number" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Capacity</label>
                            <input type="number" name="capacity" id="edit_capacity" min="1" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Room type</label>
                            <select name="room_type" id="edit_room_type" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="lecture">Lecture Room</option>
                                <option value="computer">Computer Lab</option>
                                <option value="conference">Seminar Hall</option>
                                <option value="laboratory">Laboratory</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                            <select name="status" id="edit_status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="active">Active</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="editRoomModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Update Room</button>
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

            document.getElementById('addRoomBtn')?.addEventListener('click', function () {
                openModal('addRoomModal');
            });
            document.querySelectorAll('[data-modal-close]')?.forEach(function (b) {
                b.addEventListener('click', function () {
                    const target = b.getAttribute('data-modal-close');
                    if (target) closeModal(target);
                });
            });

            window.editRoom = function (roomData) {
                document.getElementById('edit_classroom_id').value = roomData.id;
                document.getElementById('edit_room_number').value = roomData.room_number || '';
                document.getElementById('edit_capacity').value = roomData.capacity || 30;
                document.getElementById('edit_room_type').value = roomData.room_type || 'lecture';
                document.getElementById('edit_status').value = roomData.status || 'active';
                openModal('editRoomModal');
            }

            window.deleteRoom = function (roomId) {
                if (confirm('Are you sure you want to delete this room? This action cannot be undone.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'process_classroom.php';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'classroom_id';
                    idInput.value = roomId;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            }

            const searchInput = document.getElementById('roomSearch');
            const pills = Array.from(document.querySelectorAll('.room-pill'));
            const cards = Array.from(document.querySelectorAll('.room-card'));
            let activeType = 'all';

            function applyFilters() {
                const q = (searchInput?.value || '').trim().toLowerCase();
                cards.forEach(function (card) {
                    const type = (card.getAttribute('data-type') || '').toLowerCase();
                    const hay = (card.getAttribute('data-search') || '').toLowerCase();
                    const typeOk = (activeType === 'all') || (type === activeType) || (activeType === 'computer' && type === 'laboratory');
                    const searchOk = (!q) || hay.includes(q);
                    card.style.display = (typeOk && searchOk) ? '' : 'none';
                });
            }

            searchInput?.addEventListener('input', applyFilters);
            pills.forEach(function (p) {
                p.addEventListener('click', function () {
                    activeType = p.getAttribute('data-type') || 'all';
                    pills.forEach(function (x) {
                        x.classList.remove('bg-emerald-600', 'text-white');
                        x.classList.add('bg-white', 'text-slate-700');
                    });
                    p.classList.add('bg-emerald-600', 'text-white');
                    p.classList.remove('bg-white', 'text-slate-700');
                    applyFilters();
                });
            });

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