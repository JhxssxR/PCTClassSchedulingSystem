<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

function table_exists(PDO $conn, string $table): bool {
    // MariaDB/MySQL does not reliably support placeholders in SHOW TABLES.
    // Use information_schema instead (safe parameterization).
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

$settings_array = [
    'school_name' => 'Philippine College of Technology',
    'school_short_name' => 'PCT',
    'school_address' => 'Davao City, Philippines',
    'contact_email' => 'registrar@pct.edu.ph',
    'contact_phone' => '+63 82 123 4567',
    'school_website' => 'https://pct.edu.ph',
    'school_description' => 'Philippine College of Technology is a leading institution in Davao City offering quality education in technology and engineering.',
    'max_enrollments' => 6,
    'enrollment_approval' => '1',
    'default_class_duration' => 120,
    'break_time' => 15,
    'email_notifications' => '1',
    'notification_days' => 1,
];

$settings_missing = false;
if (table_exists($conn, 'settings')) {
    try {
        $stmt = $conn->prepare('SELECT `key`, `value` FROM settings');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
            if (isset($setting['key'])) {
                $settings_array[(string)$setting['key']] = (string)($setting['value'] ?? '');
            }
        }
    } catch (PDOException $e) {
        $settings_missing = true;
    }
} else {
    $settings_missing = true;
}

$user_initials = 'SA';
$full_name = 'Super Admin';
if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
    $full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $first = strtoupper(substr((string)($_SESSION['first_name'] ?? ''), 0, 1));
    $last = strtoupper(substr((string)($_SESSION['last_name'] ?? ''), 0, 1));
    $user_initials = trim($first . $last);
    if ($user_initials === '') $user_initials = 'SA';
}

$php_version = phpversion();
$mysql_version = $conn->getAttribute(PDO::ATTR_SERVER_VERSION);
$server_software = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
$database_name = (string)($conn->query('SELECT DATABASE()')->fetchColumn() ?? '');
$server_name = (string)($_SERVER['SERVER_NAME'] ?? '');
$server_protocol = (string)($_SERVER['SERVER_PROTOCOL'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-900">

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
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-speedometer2"></i><span class="text-sm font-medium">Dashboard</span></a>
                <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-people"></i><span class="text-sm font-medium">All Users</span></a>
                <a href="instructors.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-person-video3"></i><span class="text-sm font-medium">Instructors</span></a>
                <a href="students.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-mortarboard"></i><span class="text-sm font-medium">Students</span></a>
                <a href="classes.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-book"></i><span class="text-sm font-medium">Classes</span></a>
                <a href="classrooms.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-door-open"></i><span class="text-sm font-medium">Classrooms</span></a>
                <a href="subjects.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-journal-bookmark"></i><span class="text-sm font-medium">Subjects</span></a>
                <a href="courses.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-journal-text"></i><span class="text-sm font-medium">Courses</span></a>
                <a href="schedules.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-calendar3"></i><span class="text-sm font-medium">Schedules</span></a>
                <a href="enrollments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-person-plus"></i><span class="text-sm font-medium">Enrollments</span></a>
                <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-file-earmark-text"></i><span class="text-sm font-medium">Reports</span></a>
                <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50"><i class="bi bi-gear"></i><span class="text-sm font-medium">Settings</span></a>
            </nav>
        </div>

        <div class="absolute bottom-0 left-0 right-0 p-4">
            <a href="../auth/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/15 border border-transparent hover:border-rose-400/20">
                <i class="bi bi-box-arrow-right text-rose-300"></i>
                <span class="text-sm font-semibold">Logout</span>
            </a>
        </div>
    </aside>

    <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

    <!-- Main -->
    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
            <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button id="sidebarBtn" type="button" class="lg:hidden inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50">
                        <i class="bi bi-list text-xl"></i>
                    </button>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-slate-500">Super Admin</span>
                        <span class="text-slate-300">/</span>
                        <span class="font-semibold text-slate-900">Settings</span>
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
                                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">No new notifications.</div>
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
                        <div class="h-10 w-10 rounded-full bg-emerald-600 text-white flex items-center justify-center font-semibold"><?php echo htmlspecialchars($user_initials); ?></div>
                        <div class="text-left">
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($full_name); ?></div>
                            <div class="text-xs text-slate-500">PCT System</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="px-4 sm:px-6 py-6">
            <div>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold text-slate-900">Settings</h1>
                        <p class="text-sm text-slate-500">System configuration and preferences</p>
                    </div>
                    <button form="settingsForm" type="submit" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                        <i class="bi bi-save"></i>
                        Save Changes
                    </button>
                </div>
            </div>

            <?php if (isset($_SESSION['success']) || isset($_SESSION['error']) || $settings_missing): ?>
                <div class="mt-4">
                    <?php if ($settings_missing): ?>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">
                            Settings table/values were missing or unreadable; default values are shown.
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="<?php echo $settings_missing ? 'mt-3 ' : ''; ?>rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
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

            <form id="settingsForm" action="process_settings.php" method="POST" class="mt-6 grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-5">
                <input type="hidden" name="action" value="update">

                <!-- Settings menu -->
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-3">
                    <nav class="space-y-1">
                        <button type="button" class="settings-tab w-full flex items-center justify-between px-3 py-2.5 rounded-xl bg-emerald-50 text-emerald-900" data-tab="general">
                            <span class="flex items-center gap-2 text-sm font-semibold"><i class="bi bi-house"></i> General</span>
                            <i class="bi bi-chevron-down"></i>
                        </button>
                        <button type="button" class="settings-tab w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50" data-tab="academic">
                            <span class="flex items-center gap-2 text-sm font-semibold"><i class="bi bi-calendar2"></i> Academic Year</span>
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button type="button" class="settings-tab w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50" data-tab="notifications">
                            <span class="flex items-center gap-2 text-sm font-semibold"><i class="bi bi-bell"></i> Notifications</span>
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button type="button" class="settings-tab w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50" data-tab="security">
                            <span class="flex items-center gap-2 text-sm font-semibold"><i class="bi bi-shield-check"></i> Security</span>
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button type="button" class="settings-tab w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50" data-tab="database">
                            <span class="flex items-center gap-2 text-sm font-semibold"><i class="bi bi-database"></i> Database</span>
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button type="button" class="settings-tab w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50" data-tab="system">
                            <span class="flex items-center gap-2 text-sm font-semibold"><i class="bi bi-gear"></i> System</span>
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </nav>
                </div>

                <!-- Panels -->
                <div class="min-w-0">
                    <!-- General -->
                    <section id="tab-general" class="tab-panel rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-200">
                            <div class="text-base font-semibold text-slate-900">General Settings</div>
                        </div>
                        <div class="p-5 space-y-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">School Name</label>
                                <input type="text" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="school_name" value="<?php echo htmlspecialchars((string)($settings_array['school_name'] ?? '')); ?>" required>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Short Name</label>
                                <input type="text" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="school_short_name" value="<?php echo htmlspecialchars((string)($settings_array['school_short_name'] ?? '')); ?>" required>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Address</label>
                                <input type="text" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="school_address" value="<?php echo htmlspecialchars((string)($settings_array['school_address'] ?? '')); ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Contact Email</label>
                                <input type="email" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="contact_email" value="<?php echo htmlspecialchars((string)($settings_array['contact_email'] ?? '')); ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Contact Phone</label>
                                <input type="text" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="contact_phone" value="<?php echo htmlspecialchars((string)($settings_array['contact_phone'] ?? '')); ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Website</label>
                                <input type="url" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="school_website" value="<?php echo htmlspecialchars((string)($settings_array['school_website'] ?? '')); ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">School Description</label>
                                <textarea class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="school_description" rows="3"><?php echo htmlspecialchars((string)($settings_array['school_description'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <!-- Academic Year (placeholder) -->
                    <section id="tab-academic" class="tab-panel hidden rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-200">
                            <div class="text-base font-semibold text-slate-900">Academic Year</div>
                        </div>
                        <div class="p-5 text-sm text-slate-600">No academic year settings configured yet.</div>
                    </section>

                    <!-- Notifications (existing settings) -->
                    <section id="tab-notifications" class="tab-panel hidden rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-200">
                            <div class="text-base font-semibold text-slate-900">Notifications</div>
                        </div>
                        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Email Notifications</label>
                                <select class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="email_notifications" required>
                                    <option value="1" <?php echo ((string)($settings_array['email_notifications'] ?? '1')) === '1' ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="0" <?php echo ((string)($settings_array['email_notifications'] ?? '1')) === '0' ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Notification Days Before Class</label>
                                <input type="number" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="notification_days" value="<?php echo htmlspecialchars((string)($settings_array['notification_days'] ?? 1)); ?>" min="0" max="7" required>
                            </div>
                        </div>
                    </section>

                    <!-- Security (placeholder) -->
                    <section id="tab-security" class="tab-panel hidden rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-200">
                            <div class="text-base font-semibold text-slate-900">Security</div>
                        </div>
                        <div class="p-5 text-sm text-slate-600">No security settings configured yet.</div>
                    </section>

                    <!-- Database (system information) -->
                    <section id="tab-database" class="tab-panel hidden rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-200">
                            <div class="text-base font-semibold text-slate-900">Database</div>
                        </div>
                        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div class="text-xs uppercase tracking-wider text-slate-500">Runtime</div>
                                <div class="mt-2 space-y-2 text-sm">
                                    <div class="flex items-center justify-between gap-3"><span class="text-slate-600">PHP Version</span><span class="font-semibold text-slate-900"><?php echo htmlspecialchars((string)$php_version); ?></span></div>
                                    <div class="flex items-center justify-between gap-3"><span class="text-slate-600">MySQL Version</span><span class="font-semibold text-slate-900"><?php echo htmlspecialchars((string)$mysql_version); ?></span></div>
                                </div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div class="text-xs uppercase tracking-wider text-slate-500">Database</div>
                                <div class="mt-2 space-y-2 text-sm">
                                    <div class="flex items-center justify-between gap-3"><span class="text-slate-600">Name</span><span class="font-semibold text-slate-900"><?php echo htmlspecialchars($database_name); ?></span></div>
                                    <div class="flex items-center justify-between gap-3"><span class="text-slate-600">Server</span><span class="font-semibold text-slate-900"><?php echo htmlspecialchars($server_name); ?></span></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- System (existing enrollment/schedule defaults) -->
                    <section id="tab-system" class="tab-panel hidden rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-200">
                            <div class="text-base font-semibold text-slate-900">System</div>
                        </div>
                        <div class="p-5 space-y-6">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Enrollment Settings</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-1">Maximum Enrollments per Student</label>
                                        <input type="number" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="max_enrollments" value="<?php echo htmlspecialchars((string)($settings_array['max_enrollments'] ?? 6)); ?>" min="1" max="10" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-1">Enrollment Approval Required</label>
                                        <select class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="enrollment_approval" required>
                                            <option value="1" <?php echo ((string)($settings_array['enrollment_approval'] ?? '1')) === '1' ? 'selected' : ''; ?>>Yes</option>
                                            <option value="0" <?php echo ((string)($settings_array['enrollment_approval'] ?? '1')) === '0' ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Schedule Settings</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-1">Default Class Duration (minutes)</label>
                                        <input type="number" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="default_class_duration" value="<?php echo htmlspecialchars((string)($settings_array['default_class_duration'] ?? 120)); ?>" min="30" max="180" step="30" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-1">Break Time Between Classes (minutes)</label>
                                        <input type="number" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" name="break_time" value="<?php echo htmlspecialchars((string)($settings_array['break_time'] ?? 15)); ?>" min="0" max="60" step="5" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </form>
        </main>
    </div>
</div>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('sidebarBtn');

    function openSidebar() {
        sidebar?.classList.remove('-translate-x-full');
        overlay?.classList.remove('hidden');
    }
    function closeSidebar() {
        sidebar?.classList.add('-translate-x-full');
        overlay?.classList.add('hidden');
    }
    btn?.addEventListener('click', function () {
        if (sidebar.classList.contains('-translate-x-full')) openSidebar();
        else closeSidebar();
    });
    overlay?.addEventListener('click', closeSidebar);

    const tabs = Array.from(document.querySelectorAll('.settings-tab'));
    const panels = {
        general: document.getElementById('tab-general'),
        academic: document.getElementById('tab-academic'),
        notifications: document.getElementById('tab-notifications'),
        security: document.getElementById('tab-security'),
        database: document.getElementById('tab-database'),
        system: document.getElementById('tab-system')
    };

    function setTab(name) {
        tabs.forEach(t => {
            const active = t.getAttribute('data-tab') === name;
            t.classList.toggle('bg-emerald-50', active);
            t.classList.toggle('text-emerald-900', active);
            t.classList.toggle('text-slate-700', !active);
        });
        Object.keys(panels).forEach(k => {
            panels[k]?.classList.toggle('hidden', k !== name);
        });
    }

    tabs.forEach(t => {
        t.addEventListener('click', () => setTab(t.getAttribute('data-tab')));
    });

    setTab('general');
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
