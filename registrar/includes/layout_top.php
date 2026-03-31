<?php
if (!isset($page_title)) {
    $page_title = 'Registrar';
}
if (!isset($breadcrumbs)) {
    $breadcrumbs = 'Registrar';
}
if (!isset($active_page)) {
    $active_page = '';
}

require_once __DIR__ . '/../notifications_data.php';


$__is_super_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';

$__registrar_nav = [
    [
        'key' => 'dashboard',
        'label' => 'Dashboard',
        'href_registrar' => 'dashboard.php',
        'href_admin' => '../admin/dashboard.php',
        'icon' => 'bi-speedometer2',
    ],
    [
        'key' => 'instructors',
        'label' => 'Instructors',
        'href_registrar' => 'instructors.php',
        'href_admin' => '../admin/instructors.php',
        'icon' => 'bi-person-video3',
    ],
    [
        'key' => 'students',
        'label' => 'Students',
        'href_registrar' => 'students.php',
        'href_admin' => '../admin/students.php',
        'icon' => 'bi-mortarboard',
    ],
    [
        'key' => 'classes',
        'label' => 'Classes',
        'href_registrar' => 'classes.php',
        'href_admin' => '../admin/classes.php',
        'icon' => 'bi-book',
    ],
    [
        'key' => 'classrooms',
        'label' => 'Classrooms',
        'href_registrar' => 'rooms.php',
        'href_admin' => '../admin/classrooms.php',
        'icon' => 'bi-door-open',
    ],
    [
        'key' => 'subjects',
        'label' => 'Subjects',
        'href_registrar' => 'subjects.php',
        'href_admin' => '../admin/subjects.php',
        'icon' => 'bi-journal-bookmark',
    ],
    [
        'key' => 'courses',
        'label' => 'Courses',
        'href_registrar' => 'courses.php',
        'href_admin' => '../admin/courses.php',
        'icon' => 'bi-journal-text',
    ],
    [
        'key' => 'schedules',
        'label' => 'Schedules',
        'href_registrar' => 'schedules.php',
        'href_admin' => '../admin/schedules.php',
        'icon' => 'bi-calendar3',
    ],
    [
        'key' => 'enrollments',
        'label' => 'Enrollments',
        'href_registrar' => 'manage_enrollments.php',
        'href_admin' => '../admin/enrollments.php',
        'icon' => 'bi-person-plus',
    ],
    [
        'key' => 'reports',
        'label' => 'Reports',
        'href_registrar' => 'reports.php',
        'href_admin' => '../admin/reports.php',
        'icon' => 'bi-file-earmark-text',
    ],
    [
        'key' => 'settings',
        'label' => 'Settings',
        'href_registrar' => 'profile.php',
        'href_admin' => '../admin/settings.php',
        'icon' => 'bi-gear',
    ],
];

$__full_name = 'Registrar';
$__user_initials = 'RA';
if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
    $__full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $first = strtoupper(substr((string)($_SESSION['first_name'] ?? ''), 0, 1));
    $last = strtoupper(substr((string)($_SESSION['last_name'] ?? ''), 0, 1));
    $__user_initials = trim($first . $last);
    if ($__user_initials === '') {
        $__user_initials = 'RA';
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($page_title); ?> - PCT Registrar</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] }
                }
            }
        };
    </script>

    <!-- Keep Bootstrap CSS available for legacy pages still using Bootstrap classes -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .sidebar-shell {
            width: 250px;
        }
        .sidebar-compact .sidebar-brand-copy,
        .sidebar-compact .sidebar-nav-title,
        .sidebar-compact .sidebar-label,
        .sidebar-compact .sidebar-active-dot {
            display: none;
        }
        .sidebar-compact .sidebar-brand-row,
        .sidebar-compact .sidebar-logout-link,
        .sidebar-compact .sidebar-nav-link {
            justify-content: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        .sidebar-compact .sidebar-nav-link i {
            font-size: 1.38rem;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar-shell fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform bg-emerald-950 text-emerald-50 border-r border-emerald-900/40">
            <div class="sidebar-brand-row h-16 px-6 flex items-center gap-3 border-b border-emerald-900/40">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-9 w-9 rounded-full bg-emerald-50/10 object-contain" />
                <div class="sidebar-brand-copy leading-tight">
                    <div class="text-sm font-semibold">PCT Registrar</div>
                    <div class="text-xs text-emerald-100/70">Registrar Portal</div>
                </div>
            </div>

            <div class="px-4 py-4">
                <div class="sidebar-nav-title text-[11px] tracking-widest text-emerald-100/60 px-3 mb-2">NAVIGATION</div>
                <nav class="space-y-1">
                    <?php foreach ($__registrar_nav as $item):
                        $is_active = ($active_page === $item['key']);
                        // Use Registrar pages for registrar/admin roles.
                        // Admin (super_admin) can jump to admin pages.
                        $href = $__is_super_admin ? $item['href_admin'] : $item['href_registrar'];
                        $link_class = $is_active
                            ? 'bg-emerald-900/40 text-emerald-50'
                            : 'text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30';
                    ?>
                        <a href="<?php echo htmlspecialchars($href); ?>" class="sidebar-nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl <?php echo $link_class; ?>">
                            <i class="bi <?php echo htmlspecialchars($item['icon']); ?>"></i>
                            <span class="sidebar-label text-sm font-medium"><?php echo htmlspecialchars($item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="absolute bottom-0 left-0 right-0 p-4">
                <a href="../auth/logout.php" class="sidebar-logout-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/15 border border-transparent hover:border-rose-400/20">
                    <i class="bi bi-box-arrow-right text-rose-300"></i>
                    <span class="sidebar-label text-sm font-semibold">Logout</span>
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
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50" aria-label="Toggle sidebar">
                            <i class="bi bi-list text-xl"></i>
                        </button>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-slate-500">Registrar</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($page_title); ?></span>
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
                                        <div class="mb-2 flex justify-end gap-1 <?php echo empty($notif_items) ? 'hidden' : ''; ?>">
                                            <button id="notifScrollUp" type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" aria-label="Scroll notifications up">
                                                <i class="bi bi-chevron-up"></i>
                                            </button>
                                            <button id="notifScrollDown" type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" aria-label="Scroll notifications down">
                                                <i class="bi bi-chevron-down"></i>
                                            </button>
                                        </div>

                                        <div id="notifList" class="max-h-80 overflow-auto pr-1 space-y-2">
                                            <?php if (empty($notif_items)): ?>
                                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">No new notifications.</div>
                                            <?php else: ?>
                                                <?php foreach ($notif_items as $it): ?>
                                                    <a href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                                                <i class="bi <?php echo htmlspecialchars($it['icon'] ?? 'bi-bell'); ?>"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($it['title'] ?? 'Notification'); ?></div>
                                                                <div class="text-xs text-slate-500 line-clamp-2"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mt-3 text-xs text-slate-500">
                                            <?php
                                                $ni = (int)($notif_new_instructors ?? 0);
                                                $ns = (int)($notif_new_students ?? 0);
                                                $ne = (int)($notif_new_enrollments ?? 0);
                                                echo htmlspecialchars($ni . ' new instructor(s), ' . $ns . ' new student(s), ' . $ne . ' new enrollment(s) since last check.');
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:flex items-center gap-3">
                            <div class="h-10 w-10 rounded-full bg-emerald-600 text-white flex items-center justify-center font-semibold">
                                <?php echo htmlspecialchars($__user_initials); ?>
                            </div>
                            <div class="text-left">
                                <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($__full_name); ?></div>
                                <div class="text-xs text-slate-500">PCT System</div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-6">
