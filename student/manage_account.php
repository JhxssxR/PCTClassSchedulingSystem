<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../auth/login.php?role=student');
    exit();
}

$student_id = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, first_name, last_name, username, email, student_id, year_level FROM users WHERE id = ? AND role = 'student' LIMIT 1");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

if (!$student) {
    session_unset();
    session_destroy();
    header('Location: ../auth/login.php?role=student');
    exit();
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $first_name = trim((string) ($_POST['first_name'] ?? ''));
        $last_name = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $new_password = trim((string) ($_POST['new_password'] ?? ''));
        $confirm_password = trim((string) ($_POST['confirm_password'] ?? ''));

        if ($first_name === '' || $last_name === '' || $email === '') {
            throw new Exception('First name, last name, and email are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        $email_stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $email_stmt->execute([$email, $student_id]);
        if ($email_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Email is already in use by another account.');
        }

        $params = [
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':email' => $email,
            ':id' => $student_id,
        ];

        $password_sql = '';
        if ($new_password !== '') {
            if (strlen($new_password) < 8) {
                throw new Exception('Password must be at least 8 characters long.');
            }
            if (!preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $new_password)) {
                throw new Exception('Password must contain at least one capital letter and one number.');
            }
            if ($new_password !== $confirm_password) {
                throw new Exception('New password and confirm password do not match.');
            }

            $password_sql = ', password = :password';
            $params[':password'] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        $update_stmt = $conn->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email' . $password_sql . ' WHERE id = :id');
        $update_stmt->execute($params);

        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['email'] = $email;

        $student['first_name'] = $first_name;
        $student['last_name'] = $last_name;
        $student['email'] = $email;

        $success_message = 'Account updated successfully.';
    } catch (Throwable $e) {
        $error_message = $e->getMessage();
    }
}

$first_name = (string) ($student['first_name'] ?? ($_SESSION['first_name'] ?? 'Student'));
$last_name = (string) ($student['last_name'] ?? ($_SESSION['last_name'] ?? ''));
$username = (string) ($student['username'] ?? ($_SESSION['username'] ?? 'student'));
$full_name = trim($first_name . ' ' . $last_name);
if ($full_name === '') {
    $full_name = 'Student';
}

$student_id_label = trim((string) ($student['student_id'] ?? ''));
if ($student_id_label === '') {
    $student_id_label = 'ID-' . str_pad((string) $student_id, 4, '0', STR_PAD_LEFT);
}

$year_level_raw = (string) ($student['year_level'] ?? '');
if (is_numeric($year_level_raw)) {
    $year_level_label = 'Year ' . (int) $year_level_raw;
} else {
    $year_level_label = $year_level_raw !== '' ? $year_level_raw : 'Year level not set';
}

$user_initials = strtoupper(substr($first_name, 0, 1) . substr($last_name !== '' ? $last_name : $username, 0, 1));
if ($user_initials === '') {
    $user_initials = 'ST';
}
?>
<!doctype html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Account - PCT Student</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] }
                }
            }
        };
    </script>

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
<body class="bg-slate-100 text-slate-800 antialiased">
    <div class="min-h-screen">
        <aside id="studentSidebar" class="sidebar-shell fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-300 bg-gradient-to-b from-emerald-950 via-emerald-900 to-emerald-950 text-emerald-50 border-r border-emerald-800/60">
            <div class="sidebar-brand-row h-20 px-5 flex items-center gap-3 border-b border-emerald-800/70">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-10 w-10 rounded-full object-contain bg-white/10" />
                <div class="sidebar-brand-copy">
                    <div class="text-sm font-semibold leading-tight">PCT Student</div>
                    <div class="text-xs text-emerald-200/80">Student Portal</div>
                </div>
            </div>

            <div class="px-4 py-4">
                <div class="sidebar-nav-title px-2 text-[11px] tracking-widest text-emerald-200/60">NAVIGATION</div>
                <nav class="mt-3 space-y-1.5">
                    <a href="dashboard.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-grid"></i><span class="sidebar-label">Dashboard</span></span></a>
                    <a href="my_schedule.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-calendar3"></i><span class="sidebar-label">My Schedule</span></span></a>
                    <a href="my_subjects.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-book"></i><span class="sidebar-label">My Subjects</span></span></a>
                    <a href="../activity.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-clock-history"></i><span class="sidebar-label">Activity</span></span></a>
                    <a href="manage_account.php" class="sidebar-nav-link flex items-center justify-between rounded-xl bg-emerald-700/45 px-3 py-2.5 text-sm font-medium text-emerald-50">
                        <span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-person-gear"></i><span class="sidebar-label">Manage Account</span></span>
                        <span class="sidebar-active-dot h-1.5 w-1.5 rounded-full bg-emerald-200"></span>
                    </a>
                </nav>
            </div>

            <div class="absolute inset-x-0 bottom-0 p-4 border-t border-emerald-800/70">
                <a href="../auth/logout.php" class="sidebar-logout-link inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-rose-200 hover:bg-rose-500/10 hover:text-rose-100">
                    <i class="bi bi-box-arrow-left"></i>
                    <span class="sidebar-label">Logout</span>
                </a>
            </div>
        </aside>

        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/45 lg:hidden"></div>

        <div id="contentWrap" class="min-h-screen transition-all duration-300">
            <header class="sticky top-0 z-20 h-16 border-b border-slate-200 bg-white/90 backdrop-blur">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3 text-sm text-slate-500">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Toggle menu">
                            <i class="bi bi-list text-lg"></i>
                        </button>
                        <span class="hidden sm:inline">Student</span>
                        <span class="hidden sm:inline text-slate-300">/</span>
                        <span class="font-semibold text-slate-700">Manage Account</span>
                    </div>

                    <div class="inline-flex items-center gap-2 rounded-xl border border-emerald-100 bg-white px-3 py-1.5 text-xs text-slate-600">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-500 text-white font-semibold"><?php echo htmlspecialchars($user_initials); ?></span>
                        <span class="hidden sm:inline"><?php echo htmlspecialchars($full_name); ?></span>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-5">
                <div class="mx-auto max-w-3xl space-y-4">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h1 class="text-2xl font-semibold text-slate-800">Manage Account</h1>
                                <p class="text-sm text-slate-500">Update your profile details and password.</p>
                            </div>
                            <div class="text-right text-xs text-slate-500">
                                <div><?php echo htmlspecialchars($student_id_label); ?></div>
                                <div><?php echo htmlspecialchars($year_level_label); ?></div>
                            </div>
                        </div>
                    </section>

                    <?php if ($success_message !== ''): ?>
                        <section class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            <?php echo htmlspecialchars($success_message); ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($error_message !== ''): ?>
                        <section class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            <?php echo htmlspecialchars($error_message); ?>
                        </section>
                    <?php endif; ?>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
                        <form method="post" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="first_name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">First Name</label>
                                    <input id="first_name" name="first_name" type="text" value="<?php echo htmlspecialchars($first_name); ?>" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none" />
                                </div>
                                <div>
                                    <label for="last_name" class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Last Name</label>
                                    <input id="last_name" name="last_name" type="text" value="<?php echo htmlspecialchars($last_name); ?>" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none" />
                                </div>
                            </div>

                            <div>
                                <label for="email" class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Email</label>
                                <input id="email" name="email" type="email" value="<?php echo htmlspecialchars((string) ($student['email'] ?? '')); ?>" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none" />
                            </div>

                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-4">
                                <div class="text-sm font-semibold text-slate-700">Change Password (Optional)</div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label for="new_password" class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">New Password</label>
                                        <input id="new_password" name="new_password" type="password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none" />
                                    </div>
                                    <div>
                                        <label for="confirm_password" class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Confirm Password</label>
                                        <input id="confirm_password" name="confirm_password" type="password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none" />
                                    </div>
                                </div>
                                <p class="text-xs text-slate-500">Use at least 8 characters with one capital letter and one number.</p>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                                    <i class="bi bi-check2-circle"></i>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            </main>
        </div>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('studentSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const sidebarBtn = document.getElementById('sidebarBtn');
            const contentWrap = document.getElementById('contentWrap');

            if (!sidebar || !overlay || !sidebarBtn || !contentWrap) {
                return;
            }

            let isMobileOpen = false;
            let isDesktopCompact = false;

            function applyDesktopState() {
                if (window.innerWidth >= 1024) {
                    sidebar.classList.toggle('sidebar-compact', isDesktopCompact);
                    contentWrap.style.marginLeft = isDesktopCompact ? '86px' : '250px';
                    sidebar.classList.remove('-translate-x-full');
                    overlay.classList.add('hidden');
                    isMobileOpen = false;
                } else {
                    sidebar.classList.remove('sidebar-compact');
                    contentWrap.style.marginLeft = '0';
                    sidebar.classList.toggle('-translate-x-full', !isMobileOpen);
                    overlay.classList.toggle('hidden', !isMobileOpen);
                }
            }

            sidebarBtn.addEventListener('click', function () {
                if (window.innerWidth >= 1024) {
                    isDesktopCompact = !isDesktopCompact;
                } else {
                    isMobileOpen = !isMobileOpen;
                }
                applyDesktopState();
            });

            overlay.addEventListener('click', function () {
                isMobileOpen = false;
                applyDesktopState();
            });

            window.addEventListener('resize', applyDesktopState);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && window.innerWidth < 1024 && isMobileOpen) {
                    isMobileOpen = false;
                    applyDesktopState();
                }
            });

            applyDesktopState();
        })();
    </script>
</body>
</html>
