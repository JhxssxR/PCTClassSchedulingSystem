<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/activity_log.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: auth/login.php');
    exit();
}

$role = (string)($_SESSION['role'] ?? '');
$full_name = get_user_name() ?: ucfirst(str_replace('_', ' ', $role));
$_SESSION['full_name'] = $full_name;

$all_activity_items = activity_log_read(300);
if ($role !== 'super_admin') {
    $session_user_id = (int)($_SESSION['user_id'] ?? 0);
    $all_activity_items = array_values(array_filter($all_activity_items, static function (array $item) use ($session_user_id): bool {
        return (int)($item['user_id'] ?? 0) === $session_user_id;
    }));
}

$summary = [
    'total' => count($all_activity_items),
    'logins' => 0,
    'changes' => 0,
    'last' => null,
];

foreach ($all_activity_items as $item) {
    $event = (string)($item['event'] ?? '');
    if (strpos($event, 'login') === 0) {
        $summary['logins']++;
    }
    if (strpos($event, 'schedule') !== false || strpos($event, 'enrollment') !== false) {
        $summary['changes']++;
    }
    if ($summary['last'] === null) {
        $summary['last'] = $item;
    }
}

$nav_items = [];
if ($role === 'super_admin') {
    $nav_items = [
        ['label' => 'Dashboard', 'href' => 'admin/dashboard.php', 'icon' => 'bi-speedometer2'],
        ['label' => 'All Users', 'href' => 'admin/users.php', 'icon' => 'bi-people'],
        ['label' => 'Instructors', 'href' => 'admin/instructors.php', 'icon' => 'bi-person-video3'],
        ['label' => 'Students', 'href' => 'admin/students.php', 'icon' => 'bi-mortarboard'],
        ['label' => 'Schedules', 'href' => 'admin/schedules.php', 'icon' => 'bi-calendar3'],
        ['label' => 'Reports', 'href' => 'admin/reports.php', 'icon' => 'bi-file-earmark-text'],
    ];
} elseif (in_array($role, ['admin', 'registrar'], true)) {
    $nav_items = [
        ['label' => 'Dashboard', 'href' => 'registrar/dashboard.php', 'icon' => 'bi-speedometer2'],
        ['label' => 'Schedules', 'href' => 'registrar/schedules.php', 'icon' => 'bi-calendar3'],
        ['label' => 'Enrollments', 'href' => 'registrar/enrollments.php', 'icon' => 'bi-person-check'],
        ['label' => 'Reports', 'href' => 'registrar/reports.php', 'icon' => 'bi-file-earmark-text'],
    ];
} elseif ($role === 'instructor') {
    $nav_items = [
        ['label' => 'Dashboard', 'href' => 'instructor/dashboard.php', 'icon' => 'bi-speedometer2'],
        ['label' => 'My Schedule', 'href' => 'instructor/my_schedule.php', 'icon' => 'bi-calendar3'],
        ['label' => 'My Classes', 'href' => 'instructor/my_classes.php', 'icon' => 'bi-journal-text'],
        ['label' => 'Update Profile', 'href' => 'instructor/profile.php', 'icon' => 'bi-person-gear'],
    ];
} elseif ($role === 'student') {
    $nav_items = [
        ['label' => 'Dashboard', 'href' => 'student/dashboard.php', 'icon' => 'bi-speedometer2'],
        ['label' => 'My Schedule', 'href' => 'student/my_schedule.php', 'icon' => 'bi-calendar3'],
        ['label' => 'My Subjects', 'href' => 'student/my_subjects.php', 'icon' => 'bi-book'],
        ['label' => 'Manage Account', 'href' => 'student/manage_account.php', 'icon' => 'bi-person-gear'],
    ];
}

$role_label = ucfirst(str_replace('_', ' ', $role));
$last_activity_text = 'No activity yet';
if (!empty($summary['last'])) {
    $last_activity_text = (string)($summary['last']['timestamp'] ?? '');
}

function activity_page_status_class(string $event): string {
    if (strpos($event, 'login') === 0) {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }
    if ($event === 'logout') {
        return 'bg-slate-100 text-slate-700 ring-slate-200';
    }
    if (strpos($event, 'schedule') !== false) {
        return 'bg-blue-50 text-blue-700 ring-blue-200';
    }
    if (strpos($event, 'enrollment') !== false) {
        return 'bg-indigo-50 text-indigo-700 ring-indigo-200';
    }
    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

function activity_page_icon(string $event): string {
    return activity_icon($event);
}

function activity_page_label(string $event): string {
    return activity_label($event);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity - PCT Class Scheduling</title>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
        }
        html, body {
            height: 100%;
        }
        body {
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(16, 185, 129, 0.18), transparent 32%),
                radial-gradient(circle at top right, rgba(5, 150, 105, 0.14), transparent 26%),
                linear-gradient(180deg, #f8fdfa 0%, #f3f8f5 42%, #eef6f1 100%);
        }
        .glass {
            background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(16px);
        }
        .noise {
            background-image: linear-gradient(rgba(255,255,255,0.06), rgba(255,255,255,0.06));
        }
        .sidebar-scroll::-webkit-scrollbar {
            width: 8px;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(16, 185, 129, 0.25);
            border-radius: 999px;
        }
    </style>
</head>
<body class="text-slate-800">
    <div class="min-h-screen lg:flex">
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-[290px] -translate-x-full border-r border-emerald-900/10 bg-emerald-950 text-emerald-50 shadow-2xl shadow-emerald-950/20 transition-transform duration-300 lg:translate-x-0">
            <div class="flex h-20 items-center gap-3 border-b border-white/10 px-5">
                <img src="pctlogo.png" alt="PCT Logo" class="h-11 w-11 rounded-2xl bg-white/10 object-contain p-1">
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold tracking-tight">PCT Class Scheduling</div>
                    <div class="truncate text-xs text-emerald-100/70"><?php echo htmlspecialchars($role_label); ?> Portal</div>
                </div>
            </div>

            <div class="sidebar-scroll h-[calc(100vh-160px)] overflow-y-auto px-4 py-4">
                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/60">Signed in as</div>
                    <div class="mt-2 text-lg font-semibold text-white"><?php echo htmlspecialchars($full_name); ?></div>
                    <div class="mt-1 text-sm text-emerald-100/70"><?php echo htmlspecialchars($role_label); ?></div>
                </div>

                <div class="mt-5 px-2 text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-100/50">Navigation</div>
                <nav class="mt-3 space-y-1.5">
                    <?php foreach ($nav_items as $item): ?>
                        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="flex items-center gap-3 rounded-2xl px-3 py-3 text-sm text-emerald-100/85 transition hover:bg-white/10 hover:text-white">
                            <i class="bi <?php echo htmlspecialchars($item['icon']); ?> text-base"></i>
                            <span class="font-medium"><?php echo htmlspecialchars($item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <a href="activity.php" class="flex items-center gap-3 rounded-2xl bg-white/10 px-3 py-3 text-sm font-semibold text-white ring-1 ring-inset ring-white/10">
                        <i class="bi bi-clock-history text-base"></i>
                        <span>Activity</span>
                    </a>
                </nav>
            </div>

            <div class="absolute inset-x-0 bottom-0 border-t border-white/10 p-4">
                <a href="auth/logout.php" class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-3 py-3 text-sm font-semibold text-rose-100 transition hover:bg-rose-500/10 hover:text-white">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <div id="overlay" class="fixed inset-0 z-30 hidden bg-slate-950/40 lg:hidden"></div>

        <main class="flex-1 lg:ml-[290px]">
            <header class="sticky top-0 z-20 border-b border-white/60 bg-white/70 backdrop-blur-xl">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-3">
                        <button id="sidebarBtn" type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-emerald-900/10 bg-white text-emerald-900 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md lg:hidden" aria-label="Toggle sidebar">
                            <i class="bi bi-list text-xl"></i>
                        </button>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700/80">System Activity</div>
                            <div class="text-sm text-slate-500">Time-stamped actions across the portal</div>
                        </div>
                    </div>

                    <div class="hidden items-center gap-2 sm:flex">
                        <a href="<?php echo $role === 'super_admin' ? 'admin/dashboard.php' : ($role === 'instructor' ? 'instructor/dashboard.php' : ($role === 'student' ? 'student/dashboard.php' : 'registrar/dashboard.php')); ?>" class="inline-flex items-center gap-2 rounded-2xl border border-emerald-900/10 bg-white px-4 py-2 text-sm font-semibold text-emerald-900 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                            <i class="bi bi-speedometer2"></i>
                            Dashboard
                        </a>
                        <a href="auth/logout.php" class="inline-flex items-center gap-2 rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 hover:shadow-md">
                            <i class="bi bi-box-arrow-right"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </header>

            <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <section class="relative overflow-hidden rounded-[28px] border border-emerald-900/10 bg-gradient-to-br from-emerald-950 via-emerald-900 to-emerald-800 text-white shadow-[0_24px_80px_rgba(6,95,70,0.25)]">
                    <div class="absolute inset-0 noise opacity-30"></div>
                    <div class="absolute -right-20 -top-20 h-72 w-72 rounded-full bg-white/10 blur-3xl"></div>
                    <div class="absolute -bottom-24 left-1/3 h-72 w-72 rounded-full bg-emerald-300/10 blur-3xl"></div>

                    <div class="relative p-6 sm:p-8 lg:p-10">
                        <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                            <div class="max-w-3xl">
                                <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold tracking-[0.2em] text-emerald-100/80 uppercase">
                                    <i class="bi bi-clock-history"></i>
                                    Activity Timeline
                                </div>
                                <h1 class="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">See every important action in one place.</h1>
                                <p class="mt-4 max-w-2xl text-sm leading-7 text-emerald-50/80 sm:text-base">Logins, logouts, schedule changes, and enrollment updates are recorded with full date and time. Super admin sees the whole system, while other roles see their own history.</p>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-3 lg:min-w-[420px]">
                                <div class="rounded-3xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                                    <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/60">Activities</div>
                                    <div class="mt-2 text-3xl font-semibold"><?php echo number_format($summary['total']); ?></div>
                                </div>
                                <div class="rounded-3xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                                    <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/60">Logins</div>
                                    <div class="mt-2 text-3xl font-semibold"><?php echo number_format($summary['logins']); ?></div>
                                </div>
                                <div class="rounded-3xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                                    <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/60">Changes</div>
                                    <div class="mt-2 text-3xl font-semibold"><?php echo number_format($summary['changes']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mt-6 grid gap-4 md:grid-cols-3">
                    <div class="glass rounded-[24px] border border-emerald-900/10 p-5 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                                <i class="bi bi-clock-history text-xl"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Recent activity</div>
                                <div class="text-xs text-slate-500">Sorted newest first</div>
                            </div>
                        </div>
                        <div class="mt-4 text-2xl font-semibold text-slate-900"><?php echo number_format($summary['total']); ?></div>
                        <div class="text-sm text-slate-500">Visible records</div>
                    </div>

                    <div class="glass rounded-[24px] border border-emerald-900/10 p-5 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                                <i class="bi bi-box-arrow-in-right text-xl"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Authentication</div>
                                <div class="text-xs text-slate-500">Login and logout events</div>
                            </div>
                        </div>
                        <div class="mt-4 text-2xl font-semibold text-slate-900"><?php echo number_format($summary['logins']); ?></div>
                        <div class="text-sm text-slate-500">Recorded sign-ins</div>
                    </div>

                    <div class="glass rounded-[24px] border border-emerald-900/10 p-5 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                                <i class="bi bi-pencil-square text-xl"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">System changes</div>
                                <div class="text-xs text-slate-500">Schedules and enrollments</div>
                            </div>
                        </div>
                        <div class="mt-4 text-2xl font-semibold text-slate-900"><?php echo number_format($summary['changes']); ?></div>
                        <div class="text-sm text-slate-500">Recorded updates</div>
                    </div>
                </section>

                <section class="mt-6 overflow-hidden rounded-[28px] border border-emerald-900/10 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
                    <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-slate-900">Activity Feed</h2>
                            <p class="text-sm text-slate-500">Each entry includes a timestamp, event label, and details.</p>
                        </div>
                        <div class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100">
                            <i class="bi bi-shield-check"></i>
                            <?php echo htmlspecialchars($role_label); ?> view
                        </div>
                    </div>

                    <div class="divide-y divide-slate-100">
                        <?php if (!empty($all_activity_items)): ?>
                            <?php foreach ($all_activity_items as $item): ?>
                                <?php
                                    $event = (string)($item['event'] ?? '');
                                    $details = (array)($item['details'] ?? []);
                                    $message = '';
                                    if (!empty($details['message'])) {
                                        $message = (string)$details['message'];
                                    } elseif (!empty($details)) {
                                        $message = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                                    }
                                    $when = (string)($item['timestamp'] ?? '');
                                    $role_text = (string)($item['role'] ?? '');
                                    $badge_class = activity_page_status_class($event);
                                ?>
                                <article class="flex flex-col gap-4 px-5 py-5 sm:px-6 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="flex items-start gap-4">
                                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl <?php echo htmlspecialchars($badge_class); ?> ring-1 ring-inset">
                                            <i class="bi <?php echo htmlspecialchars(activity_page_icon($event)); ?> text-lg"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="inline-flex items-center rounded-full <?php echo htmlspecialchars($badge_class); ?> px-3 py-1 text-xs font-semibold ring-1 ring-inset"><?php echo htmlspecialchars(activity_page_label($event)); ?></span>
                                                <?php if ($role_text !== ''): ?>
                                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $role_text))); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-3 text-base font-semibold text-slate-900"><?php echo htmlspecialchars((string)($item['name'] ?? '')); ?></div>
                                            <div class="mt-1 text-sm leading-6 text-slate-500">
                                                <?php echo htmlspecialchars($message !== '' ? $message : 'No additional details recorded.'); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="shrink-0 rounded-2xl bg-slate-50 px-4 py-3 text-right ring-1 ring-inset ring-slate-100">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Timestamp</div>
                                        <div class="mt-1 text-sm font-semibold text-slate-800"><?php echo htmlspecialchars($when !== '' ? $when : 'N/A'); ?></div>
                                        <div class="mt-1 text-xs text-slate-400"><?php echo htmlspecialchars((string)($item['ip'] ?? '')); ?></div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="px-5 py-16 text-center sm:px-6">
                                <div class="mx-auto grid h-16 w-16 place-items-center rounded-3xl bg-emerald-50 text-emerald-700">
                                    <i class="bi bi-clock-history text-2xl"></i>
                                </div>
                                <h3 class="mt-5 text-xl font-semibold text-slate-900">No activity yet</h3>
                                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">New entries will appear here once users sign in or the system records schedule and enrollment changes.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            const btn = document.getElementById('sidebarBtn');

            if (!sidebar || !overlay || !btn) {
                return;
            }

            const openSidebar = () => {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            };

            const closeSidebar = () => {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            };

            btn.addEventListener('click', function () {
                if (sidebar.classList.contains('-translate-x-full')) {
                    openSidebar();
                } else {
                    closeSidebar();
                }
            });

            overlay.addEventListener('click', closeSidebar);
        })();
    </script>
</body>
</html>
