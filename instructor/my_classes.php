<?php
require_once '../config/database.php';
require_once '../includes/session.php';

require_role('instructor');
require_once __DIR__ . '/notifications_data.php';

function ins_cls_course_chip(string $courseCode): string {
    if (preg_match('/\d+/', $courseCode, $matches)) {
        return $matches[0];
    }
    $plain = preg_replace('/[^A-Za-z0-9]/', '', $courseCode);
    return strtoupper(substr((string) $plain, 0, 3));
}

function ins_cls_day_abbrev(string $day): string {
    $map = [
        'Monday' => 'M',
        'Tuesday' => 'T',
        'Wednesday' => 'W',
        'Thursday' => 'Th',
        'Friday' => 'F',
        'Saturday' => 'Sat',
    ];
    return $map[$day] ?? substr($day, 0, 1);
}

function ins_cls_time_label(string $time): string {
    return date('g:i A', strtotime($time));
}

$instructor_id = (int) ($_SESSION['user_id'] ?? 0);
$stmt = $conn->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$instructor_id]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$full_name = trim((string) ($instructor['first_name'] ?? ($_SESSION['first_name'] ?? '')) . ' ' . (string) ($instructor['last_name'] ?? ($_SESSION['last_name'] ?? '')));
if ($full_name === '') {
    $full_name = 'Instructor';
}
$user_initials = strtoupper(substr((string) ($instructor['first_name'] ?? 'I'), 0, 1) . substr((string) ($instructor['last_name'] ?? 'N'), 0, 1));

$stmt = $conn->prepare("\n    SELECT\n        s.id,\n        s.course_id,\n        s.day_of_week,\n        s.start_time,\n        s.max_students,\n        c.course_code,\n        c.course_name,\n        cl.room_number,\n        COUNT(CASE WHEN e.status = 'approved' THEN e.id END) AS enrolled_students\n    FROM schedules s\n    JOIN courses c ON s.course_id = c.id\n    JOIN classrooms cl ON s.classroom_id = cl.id\n    LEFT JOIN enrollments e ON s.id = e.schedule_id\n    WHERE s.instructor_id = :instructor_id\n      AND s.status = 'active'\n    GROUP BY s.id\n    ORDER BY c.course_code, FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), s.start_time\n");
$stmt->execute(['instructor_id' => $instructor_id]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classes = [];
foreach ($schedules as $row) {
    $key = (string) ($row['course_id'] ?? $row['id']);

    if (!isset($classes[$key])) {
        $classes[$key] = [
            'course_code' => (string) ($row['course_code'] ?? ''),
            'course_name' => (string) ($row['course_name'] ?? ''),
            'room_number' => (string) ($row['room_number'] ?? ''),
            'start_time' => (string) ($row['start_time'] ?? ''),
            'max_students' => (int) ($row['max_students'] ?? 0),
            'enrolled_students' => 0,
            'days' => [],
        ];
    }

    $classes[$key]['enrolled_students'] += (int) ($row['enrolled_students'] ?? 0);

    $day = (string) ($row['day_of_week'] ?? '');
    if ($day !== '' && !in_array($day, $classes[$key]['days'], true)) {
        $classes[$key]['days'][] = $day;
    }
}

$day_order = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
foreach ($classes as &$class) {
    usort($class['days'], function ($a, $b) use ($day_order) {
        return ($day_order[$a] ?? 99) <=> ($day_order[$b] ?? 99);
    });

    $abbr = array_map('ins_cls_day_abbrev', $class['days']);
    $class['days_label'] = implode('', $abbr);
    if ($class['days_label'] === '') {
        $class['days_label'] = '-';
    }

    $class['end_time'] = date('g:i A', strtotime($class['start_time'] . ' +2 hours'));

    if ($class['max_students'] > 0) {
        $class['progress'] = (int) round(min(100, (($class['enrolled_students'] / $class['max_students']) * 100)));
    } else {
        $class['progress'] = 0;
    }
}
unset($class);

$class_list = array_values($classes);

$total_classes = count($class_list);
$total_students = array_sum(array_map(static function ($row) {
    return (int) ($row['enrolled_students'] ?? 0);
}, $class_list));
$avg_class_size = $total_classes > 0 ? (int) round($total_students / $total_classes) : 0;

$nav_items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'bi-grid'],
    ['key' => 'schedule', 'label' => 'My Schedule', 'href' => 'my_schedule.php', 'icon' => 'bi-calendar3'],
    ['key' => 'classes', 'label' => 'My Classes', 'href' => 'my_classes.php', 'icon' => 'bi-book'],
    ['key' => 'profile', 'label' => 'Update Profile', 'href' => 'profile.php', 'icon' => 'bi-person-circle'],
];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Classes - PCT</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://unpkg.com/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background: radial-gradient(circle at 10% 5%, #f7faf9 0%, #f3f6f7 45%, #edf1f4 100%);
        }

        .sidebar-scroll {
            scrollbar-width: thin;
        }

        .sidebar-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.24);
            border-radius: 999px;
        }

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
        .sidebar-compact .sidebar-logout-link {
            justify-content: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .sidebar-compact .sidebar-nav-link {
            justify-content: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .sidebar-compact .sidebar-nav-content {
            justify-content: center;
            gap: 0;
            width: 100%;
        }

        .sidebar-compact .sidebar-nav-link i {
            font-size: 1.38rem;
        }
    </style>
</head>
<body class="font-sans text-slate-900">
    <div class="min-h-screen flex">
        <aside id="sidebar" class="sidebar-shell fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-300 bg-gradient-to-b from-emerald-950 via-emerald-950 to-emerald-900 text-emerald-50 border-r border-emerald-800/70">
            <div class="sidebar-brand-row h-20 px-4 flex items-center gap-3 border-b border-emerald-800/80">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-11 w-11 rounded-full object-contain bg-emerald-50/10 p-1" />
                <div class="sidebar-brand-copy">
                    <div class="text-sm font-semibold leading-tight">PCT Instructor</div>
                    <div class="text-xs text-emerald-100/75">Instructor's Portal</div>
                </div>
            </div>

            <div class="h-[calc(100vh-136px)] overflow-y-auto sidebar-scroll px-3 py-5">
                <div class="sidebar-nav-title text-[11px] tracking-[0.18em] text-emerald-100/55 px-3 mb-3">NAVIGATION</div>
                <nav class="space-y-1.5">
                    <?php foreach ($nav_items as $item): ?>
                        <?php $is_active = $item['key'] === 'classes'; ?>
                        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="sidebar-nav-link group flex items-center justify-between rounded-xl px-3 py-3 <?php echo $is_active ? 'bg-emerald-700/35 text-emerald-50 shadow-[inset_0_0_0_1px_rgba(110,231,183,0.18)]' : 'text-emerald-100/80 hover:text-emerald-50 hover:bg-emerald-800/35'; ?>">
                            <span class="sidebar-nav-content flex items-center gap-3">
                                <i class="bi <?php echo htmlspecialchars($item['icon']); ?> text-sm"></i>
                                <span class="sidebar-label text-sm font-medium"><?php echo htmlspecialchars($item['label']); ?></span>
                            </span>
                            <?php if ($is_active): ?>
                                <span class="sidebar-active-dot h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="h-14 px-3 border-t border-emerald-800/80 flex items-center">
                <a href="../auth/logout.php" class="sidebar-logout-link w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/10">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="sidebar-label text-sm font-semibold">Logout</span>
                </a>
            </div>
        </aside>

        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

        <div id="contentWrap" class="flex-1 min-h-screen transition-all duration-300">
            <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" aria-label="Toggle sidebar">
                            <i class="bi bi-list text-xl"></i>
                        </button>

                        <div class="hidden sm:flex items-center gap-2 text-sm">
                            <i class="bi bi-x-lg text-slate-400"></i>
                            <i class="bi bi-layers text-emerald-500"></i>
                            <span class="text-slate-500">Instructor</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-800">My Classes</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <button id="notifBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50" aria-label="Notifications" aria-haspopup="menu" aria-expanded="false">
                                <span class="relative">
                                    <i class="bi bi-bell text-lg text-slate-700"></i>
                                    <span id="notifDot" class="absolute -right-1 -top-1 min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white <?php echo (($notif_unread_total ?? 0) > 0) ? '' : 'hidden'; ?>">
                                        <?php echo htmlspecialchars($notif_badge_label ?? ''); ?>
                                    </span>
                                </span>
                            </button>

                            <div id="notifMenu" class="absolute right-0 mt-2 w-80 hidden" role="menu" aria-label="Notifications">
                                <div class="rounded-2xl border border-slate-200 bg-white shadow-lg overflow-hidden">
                                    <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-2">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900">Notifications</div>
                                            <div class="text-xs text-slate-500">Class updates and reminders</div>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <button id="notifMarkRead" type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                                <i class="bi bi-check2"></i>
                                                <span>Read</span>
                                            </button>
                                            <button id="notifDelete" type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Delete notifications">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="p-3 max-h-80 overflow-y-auto">
                                        <?php if (empty($notif_items)): ?>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">No new notifications.</div>
                                        <?php else: ?>
                                            <div class="space-y-2">
                                                <?php foreach ($notif_items as $item): ?>
                                                    <a href="<?php echo htmlspecialchars($item['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-700">
                                                                <i class="bi <?php echo htmlspecialchars($item['icon'] ?? 'bi-bell'); ?>"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($item['title'] ?? 'Notification'); ?></div>
                                                                <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($item['subtitle'] ?? ''); ?></div>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-sm font-bold">
                                <?php echo htmlspecialchars($user_initials); ?>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900 leading-tight"><?php echo htmlspecialchars($full_name); ?></div>
                                <div class="text-xs text-slate-500">Instructor</div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-5 space-y-4">
                <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <article class="rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-2xl bg-emerald-50 text-emerald-600 inline-flex items-center justify-center text-xl"><i class="bi bi-book"></i></div>
                            <div>
                                <div class="text-[34px] leading-none font-semibold text-slate-700"><?php echo $total_classes; ?></div>
                                <div class="text-sm text-slate-400">Total Classes</div>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-2xl bg-indigo-50 text-indigo-500 inline-flex items-center justify-center text-xl"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="text-[34px] leading-none font-semibold text-slate-700"><?php echo $total_students; ?></div>
                                <div class="text-sm text-slate-400">Total Students</div>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-2xl bg-amber-50 text-amber-500 inline-flex items-center justify-center text-xl"><i class="bi bi-mortarboard"></i></div>
                            <div>
                                <div class="text-[34px] leading-none font-semibold text-slate-700"><?php echo $avg_class_size; ?></div>
                                <div class="text-sm text-slate-400">Average Class Size</div>
                            </div>
                        </div>
                    </article>
                </section>

                <section class="space-y-3">
                    <?php if (empty($class_list)): ?>
                        <div class="rounded-2xl border border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">You don't have classes assigned yet.</div>
                    <?php else: ?>
                        <?php foreach ($class_list as $class): ?>
                            <article class="rounded-3xl border border-slate-200 bg-white px-5 py-4">
                                <div class="grid grid-cols-[auto,1fr,140px,24px] gap-4 items-center">
                                    <div class="h-12 w-12 rounded-2xl bg-emerald-600 text-white inline-flex items-center justify-center text-sm font-semibold">
                                        <?php echo htmlspecialchars(ins_cls_course_chip((string) $class['course_code'])); ?>
                                    </div>

                                    <div class="min-w-0">
                                        <div class="text-[30px] leading-tight font-semibold text-slate-700 truncate"><?php echo htmlspecialchars((string) $class['course_name']); ?></div>
                                        <div class="mt-1 text-sm text-slate-400 flex flex-wrap gap-3 items-center">
                                            <span><i class="bi bi-clock"></i> <?php echo htmlspecialchars((string) $class['days_label']); ?> <?php echo htmlspecialchars(ins_cls_time_label((string) $class['start_time'])); ?>-<?php echo htmlspecialchars((string) $class['end_time']); ?></span>
                                            <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string) $class['room_number']); ?></span>
                                            <span><i class="bi bi-people"></i> <?php echo (int) $class['enrolled_students']; ?> students</span>
                                        </div>
                                    </div>

                                    <div class="justify-self-end w-full">
                                        <div class="flex items-center justify-between text-sm text-slate-400">
                                            <span class="font-semibold">Progress</span>
                                            <span class="font-semibold text-slate-500"><?php echo (int) $class['progress']; ?>%</span>
                                        </div>
                                        <div class="mt-1 h-2 rounded-full bg-slate-100">
                                            <div class="h-2 rounded-full bg-emerald-500" style="width: <?php echo (int) $class['progress']; ?>%;"></div>
                                        </div>
                                    </div>

                                    <button type="button" class="text-slate-300 hover:text-slate-500" aria-label="View class details">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const sidebarBtn = document.getElementById('sidebarBtn');
            const overlay = document.getElementById('sidebarOverlay');
            const contentWrap = document.getElementById('contentWrap');
            let desktopExpanded = true;

            function isDesktop() {
                return window.innerWidth >= 1024;
            }

            function setSidebarOpen(open) {
                if (isDesktop()) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.remove('lg:-translate-x-full');
                    sidebar.classList.toggle('sidebar-compact', !open);
                    const desktopWidth = open ? 250 : 86;
                    sidebar.style.width = desktopWidth + 'px';

                    if (contentWrap) {
                        contentWrap.style.marginLeft = desktopWidth + 'px';
                    }
                } else {
                    sidebar.classList.remove('sidebar-compact');
                    sidebar.classList.remove('lg:-translate-x-full');
                    sidebar.classList.toggle('-translate-x-full', !open);
                    sidebar.style.width = '250px';

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
                return isDesktop()
                    ? !sidebar.classList.contains('sidebar-compact')
                    : !sidebar.classList.contains('-translate-x-full');
            }

            if (sidebarBtn) {
                sidebarBtn.addEventListener('click', function () {
                    const currentlyOpen = isSidebarOpen();

                    if (isDesktop()) {
                        desktopExpanded = !currentlyOpen;
                        setSidebarOpen(desktopExpanded);
                        return;
                    }

                    setSidebarOpen(!currentlyOpen);
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function () {
                    setSidebarOpen(false);
                });
            }

            window.addEventListener('resize', applyLayoutState);
            applyLayoutState();
        })();

        (function () {
            const btn = document.getElementById('notifBtn');
            const menu = document.getElementById('notifMenu');
            const markRead = document.getElementById('notifMarkRead');
            const delBtn = document.getElementById('notifDelete');

            if (!btn || !menu) {
                return;
            }

            function closeMenu() {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }

            function toggleMenu() {
                const isHidden = menu.classList.contains('hidden');
                if (isHidden) {
                    menu.classList.remove('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                } else {
                    closeMenu();
                }
            }

            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggleMenu();
            });

            menu.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            document.addEventListener('click', closeMenu);

            async function postAction(action) {
                const response = await fetch('notifications_seen.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: action })
                });
                return response.ok;
            }

            if (markRead) {
                markRead.addEventListener('click', async function (event) {
                    event.preventDefault();
                    await postAction('seen');
                    window.location.reload();
                });
            }

            if (delBtn) {
                delBtn.addEventListener('click', async function (event) {
                    event.preventDefault();
                    await postAction('delete');
                    window.location.reload();
                });
            }
        })();
    </script>
</body>
</html>
