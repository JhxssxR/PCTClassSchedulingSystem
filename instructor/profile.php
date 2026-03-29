<?php
require_once '../config/database.php';
require_once '../includes/session.php';

require_role('instructor');
require_once __DIR__ . '/notifications_data.php';

$instructor_id = (int) ($_SESSION['user_id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$instructor_id]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instructor) {
    clear_session();
    header('Location: ../auth/login.php?role=instructor');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!filter_var((string) ($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }

        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $stmt->execute([(string) $_POST['email'], $instructor_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Email is already in use by another user.');
        }

        $params = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'id' => $instructor_id,
        ];

        if ($params['first_name'] === '' || $params['last_name'] === '') {
            throw new Exception('First name and last name are required.');
        }

        $password_sql = '';
        $new_password = (string) ($_POST['new_password'] ?? '');
        if ($new_password !== '') {
            $current_password = (string) ($_POST['current_password'] ?? '');
            $confirm_password = (string) ($_POST['confirm_password'] ?? '');

            if (!password_verify($current_password, (string) ($instructor['password'] ?? ''))) {
                throw new Exception('Current password is incorrect.');
            }
            if (strlen($new_password) < 8) {
                throw new Exception('Password must be at least 8 characters long.');
            }
            if (!preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $new_password)) {
                throw new Exception('Password must contain at least one capital letter and one number.');
            }
            if ($new_password !== $confirm_password) {
                throw new Exception('New passwords do not match.');
            }

            $password_sql = ', password = :password';
            $params['password'] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        $stmt = $conn->prepare("\n            UPDATE users\n            SET first_name = :first_name,\n                last_name = :last_name,\n                email = :email\n                $password_sql\n            WHERE id = :id\n        ");
        $stmt->execute($params);

        $_SESSION['first_name'] = $params['first_name'];
        $_SESSION['last_name'] = $params['last_name'];
        $_SESSION['email'] = $params['email'];

        $_SESSION['success_message'] = 'Profile updated successfully.';
        header('Location: profile.php');
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: profile.php');
        exit();
    }
}

$full_name = trim((string) ($instructor['first_name'] ?? '') . ' ' . (string) ($instructor['last_name'] ?? ''));
if ($full_name === '') {
    $full_name = 'Instructor';
}
$user_initials = strtoupper(substr((string) ($instructor['first_name'] ?? 'I'), 0, 1) . substr((string) ($instructor['last_name'] ?? 'N'), 0, 1));

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
    <title>Update Profile - PCT</title>

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
                        <?php $is_active = $item['key'] === 'profile'; ?>
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
                            <i class="bi bi-layers text-emerald-500"></i>
                            <span class="text-slate-500">Instructor</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-800">Update Profile</span>
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

            <main class="px-4 sm:px-6 py-5">
                <div class="max-w-4xl mx-auto space-y-4">
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            <?php
                                echo htmlspecialchars((string) $_SESSION['success_message']);
                                unset($_SESSION['success_message']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            <?php
                                echo htmlspecialchars((string) $_SESSION['error_message']);
                                unset($_SESSION['error_message']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <section class="rounded-3xl border border-slate-200 bg-white px-4 py-4 sm:px-5 flex items-center gap-4">
                        <div class="h-14 w-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-2xl font-semibold">
                            <?php echo htmlspecialchars($user_initials); ?>
                        </div>
                        <div>
                            <div class="text-[30px] leading-tight font-semibold text-slate-700"><?php echo htmlspecialchars($full_name); ?></div>
                            <div class="text-sm text-slate-400">Instructor - PCT System</div>
                            <div class="text-xs text-slate-400">@<?php echo htmlspecialchars((string) ($instructor['username'] ?? 'instructor')); ?></div>
                        </div>
                    </section>

                    <form method="POST" action="profile.php" class="space-y-3">
                        <section class="rounded-3xl border border-slate-200 bg-white p-5">
                            <div class="flex items-center gap-2 text-slate-600 mb-4">
                                <i class="bi bi-person text-emerald-500"></i>
                                <h2 class="text-lg font-semibold">Personal Information</h2>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-semibold text-slate-600 mb-1">Username</label>
                                    <input type="text" value="<?php echo htmlspecialchars((string) ($instructor['username'] ?? '')); ?>" readonly class="w-full rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-400 cursor-not-allowed" />
                                    <p class="mt-1 text-xs text-slate-400">Username cannot be changed.</p>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-600 mb-1">First Name</label>
                                        <input type="text" name="first_name" required value="<?php echo htmlspecialchars((string) ($instructor['first_name'] ?? '')); ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-600 mb-1">Last Name</label>
                                        <input type="text" name="last_name" required value="<?php echo htmlspecialchars((string) ($instructor['last_name'] ?? '')); ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-3xl border border-slate-200 bg-white p-5">
                            <div class="flex items-center gap-2 text-slate-600 mb-4">
                                <i class="bi bi-envelope text-emerald-500"></i>
                                <h2 class="text-lg font-semibold">Contact Information</h2>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-slate-600 mb-1">Email Address</label>
                                <input type="email" name="email" required value="<?php echo htmlspecialchars((string) ($instructor['email'] ?? '')); ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                            </div>
                        </section>

                        <section class="rounded-3xl border border-slate-200 bg-white p-5">
                            <div class="flex items-center gap-2 text-slate-600 mb-4">
                                <i class="bi bi-lock text-emerald-500"></i>
                                <h2 class="text-lg font-semibold">Change Password</h2>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-semibold text-slate-600 mb-1">Current Password</label>
                                    <div class="relative">
                                        <input type="password" name="current_password" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-11 text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-200 password-field" />
                                        <button type="button" class="password-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" aria-label="Toggle password visibility">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400">Required only if changing password.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-600 mb-1">New Password</label>
                                    <div class="relative">
                                        <input type="password" name="new_password" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-11 text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-200 password-field" />
                                        <button type="button" class="password-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" aria-label="Toggle password visibility">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400">Leave blank to keep current password.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-600 mb-1">Confirm New Password</label>
                                    <div class="relative">
                                        <input type="password" name="confirm_password" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-11 text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-200 password-field" />
                                        <button type="button" class="password-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" aria-label="Toggle password visibility">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                            <i class="bi bi-floppy"></i>
                            Update Profile
                        </button>
                    </form>
                </div>
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

        document.querySelectorAll('.password-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const input = button.parentElement.querySelector('.password-field');
                if (!input) {
                    return;
                }
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                const icon = button.querySelector('i');
                if (icon) {
                    icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
                }
            });
        });
    </script>
</body>
</html>
