<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Removed duplicate debug_to_log() function definition
// All calls to debug_to_log() now use the one from includes/session.php

debug_to_log("=== NEW LOGIN ATTEMPT ===");
debug_to_log("POST data: " . print_r($_POST, true));
debug_to_log("SESSION data before login: " . print_r($_SESSION, true));

// If user is already logged in, redirect to their dashboard
if (is_logged_in()) {
    debug_to_log("User already logged in, redirecting by role");
    redirect_by_role();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    debug_to_log("Login attempt - Login: $login");

    if (empty($login) || empty($password)) {
        $error = "Please fill in all fields";
        debug_to_log("Login failed - Empty fields");
    } else {
        try {
            debug_to_log("Checking database connection...");
            $conn->query("SELECT 1");
            debug_to_log("Database connection OK");

            // First check if the user exists
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
            debug_to_log("Prepared statement created");
            $stmt->execute([$login, $login]);
            debug_to_log("Query executed");
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            debug_to_log("User lookup result: " . ($user ? "Found" : "Not found"));
            if ($user) {
                debug_to_log("Stored hash: " . $user['password']);
                debug_to_log("Password verification result: " . (password_verify($password, $user['password']) ? "Success" : "Failed"));
            }

            if ($user && password_verify($password, $user['password'])) {
                debug_to_log("=== LOGIN ATTEMPT ===");
                debug_to_log("User found in database - ID: " . $user['id']);
                debug_to_log("User role: " . $user['role']);
                
                // Set session variables
                debug_to_log("Setting session variables");
                set_session_vars($user);

                // Preserve per-user notification state on login.
                // This keeps unread items (for example, newly assigned classes) visible
                // until the user explicitly marks them as read or clears them.
                try {
                    $uid = (int)($user['id'] ?? 0);
                    if ($uid > 0) {
                        $stmt = $conn->prepare('SELECT notif_seen_at, notif_cleared_at FROM notification_state WHERE user_id = ?');
                        $stmt->execute([$uid]);
                        $notif_row = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($notif_row) {
                            $_SESSION['notif_seen_at'] = $notif_row['notif_seen_at'] ?? null;
                            $_SESSION['notif_cleared_at'] = $notif_row['notif_cleared_at'] ?? null;
                        } else {
                            try {
                                $_SESSION['notif_seen_at'] = (string)$conn->query('SELECT NOW()')->fetchColumn();
                            } catch (Throwable $e) {
                                $_SESSION['notif_seen_at'] = date('Y-m-d H:i:s');
                            }
                            unset($_SESSION['notif_cleared_at']);

                            $stmt = $conn->prepare('INSERT INTO notification_state (user_id, notif_seen_at, notif_cleared_at) VALUES (?, ?, NULL)');
                            $stmt->execute([$uid, $_SESSION['notif_seen_at']]);
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
                
                // Verify session was set
                debug_to_log("Verifying session after login");
                debug_to_log("Session contents: " . print_r($_SESSION, true));
                
                if (!is_logged_in()) {
                    debug_to_log("ERROR: Session not set after login");
                    $error = "An error occurred during login. Please try again.";
                } else {
                    debug_to_log("Session verified, redirecting to dashboard");
                    
                    // Redirect based on role
                    switch($user['role']) {
                        case 'super_admin':
                            header('Location: ' . app_url('admin/dashboard.php'));
                            break;
                        case 'instructor':
                            header('Location: ' . app_url('instructor/dashboard.php'));
                            break;
                        case 'admin':
                        case 'registrar':
                            header('Location: ' . app_url('registrar/dashboard.php'));
                            break;
                        case 'student':
                            header('Location: ' . app_url('student/dashboard.php'));
                            break;
                        default:
                            header('Location: ' . app_url('index.php'));
                    }
                    debug_to_log("Redirect header sent, exiting");
                    exit();
                }
            } else {
                debug_to_log("Login failed - Invalid credentials");
                $error = "Invalid username, email, or password";
            }
        } catch(PDOException $e) {
            debug_to_log("Database error: " . $e->getMessage());
            error_log("Database error in login: " . $e->getMessage());
            $error = "An error occurred. Please try again later.";
        }
    }
}

debug_to_log("Login page loaded");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        html, body {
            min-height: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
        }

        .bg-circles {
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 255, 255, 0.14) 0 13%, transparent 13.5%),
                radial-gradient(circle at 50% 46%, rgba(255, 255, 255, 0.08) 0 19%, transparent 19.5%),
                radial-gradient(circle at 82% 98%, rgba(255, 255, 255, 0.10) 0 12%, transparent 12.5%),
                linear-gradient(135deg, #0b5f2d 0%, #11823d 52%, #17a94c 100%);
        }
    </style>
</head>
<body class="bg-white text-slate-900">
    <main class="min-h-screen lg:grid lg:grid-cols-[1.15fr_0.85fr]">
        <section class="bg-circles relative overflow-hidden text-white">
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute -left-28 -top-24 h-72 w-72 rounded-full bg-white/10"></div>
                <div class="absolute left-1/4 top-1/4 h-[30rem] w-[30rem] rounded-full bg-white/10"></div>
                <div class="absolute -bottom-28 right-[-20px] h-72 w-72 rounded-full bg-white/10"></div>
            </div>

            <div class="relative flex min-h-[540px] h-full flex-col px-8 py-7 sm:px-10 lg:min-h-screen lg:px-8 lg:py-6">
                <div class="flex items-center gap-3">
                    <img src="../pctlogo.png" alt="PCT Logo" class="h-11 w-11 rounded-full object-contain shadow-[0_0_0_3px_rgba(255,255,255,0.12)]">
                    <div class="leading-tight">
                        <p class="text-[13px] font-semibold text-white/90">Philippine College of Technology</p>
                        <p class="text-[11px] text-white/70">Davao City</p>
                    </div>
                </div>

                <div class="mt-24 max-w-xl lg:mt-36">
                    <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Welcome Back</h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-white/80 sm:text-lg">
                        Sign in to access your class schedules and manage your courses efficiently.
                    </p>

                    <ul class="mt-8 space-y-4 text-sm text-white/90">
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-200 text-[10px] font-extrabold text-emerald-700 shadow-[0_0_0_4px_rgba(167,243,208,0.18)]">✓</span>
                            <span>View and manage class schedules</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-200 text-[10px] font-extrabold text-emerald-700 shadow-[0_0_0_4px_rgba(167,243,208,0.18)]">✓</span>
                            <span>Check room assignments instantly</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-200 text-[10px] font-extrabold text-emerald-700 shadow-[0_0_0_4px_rgba(167,243,208,0.18)]">✓</span>
                            <span>Access instructor information</span>
                        </li>
                    </ul>
                </div>

                <div class="mt-auto pt-14">
                    <div class="relative w-full overflow-hidden rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-[0_18px_40px_rgba(0,0,0,0.18)] sm:px-6 sm:py-5">
                        <div class="absolute inset-0 bg-gradient-to-r from-emerald-50 via-white to-emerald-50"></div>
                        <div class="absolute inset-y-0 right-0 w-2/5 bg-gradient-to-l from-emerald-100/45 to-transparent"></div>
                        <img src="../pctlogo.png" alt="" aria-hidden="true" class="pointer-events-none absolute right-6 top-1/2 h-28 w-28 -translate-y-1/2 object-contain opacity-10 sm:h-32 sm:w-32">
                        <div class="absolute inset-y-4 left-24 right-24 rounded-full border border-emerald-100/40"></div>
                        <div class="relative flex min-h-[100px] items-center gap-5">
                            <img src="../pctlogo.png" alt="PCT Logo" class="h-16 w-16 shrink-0 object-contain sm:h-[4.5rem] sm:w-[4.5rem]">
                            <div class="min-w-0 flex-1 text-center">
                                <p class="text-base font-semibold tracking-wide text-slate-800 sm:text-[20px]">
                                    We Don't Build Hopes, We Build Future
                                </p>
                                <p class="mt-2 text-[11px] font-medium uppercase tracking-[0.34em] text-emerald-500 sm:text-xs">
                                    Philippine College of Technology
                                </p>
                            </div>
                            <div class="hidden h-16 w-16 shrink-0 sm:block"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="flex items-center justify-center bg-white px-5 py-10 sm:px-8 lg:px-10">
            <div class="w-full max-w-[380px] rounded-[28px] border border-slate-100 bg-white px-7 py-8 shadow-[0_20px_45px_rgba(15,23,42,0.10)] sm:px-8">
                <div>
                    <h2 class="text-[32px] font-semibold tracking-tight text-slate-800">Sign In</h2>
                    <p class="mt-1 text-sm text-slate-400">Enter your credentials to continue</p>
                </div>

                <?php if ($error): ?>
                    <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['register_success'])): ?>
                    <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        <?php echo htmlspecialchars($_SESSION['register_success']); ?>
                        <?php unset($_SESSION['register_success']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="mt-7 space-y-5">
                    <div>
                        <label for="login" class="mb-2 block text-sm font-semibold text-slate-700">Username or Email</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5a7.5 7.5 0 0 1 15 0" />
                                </svg>
                            </span>
                            <input
                                type="text"
                                id="login"
                                name="login"
                                autocomplete="username"
                                placeholder="Enter your username or email"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                            <a href="forgot_password.php" class="text-xs font-medium text-emerald-500 hover:text-emerald-600">Forgot password?</a>
                        </div>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V7.875a4.5 4.5 0 1 0-9 0v2.625" />
                                    <rect x="5.5" y="10.5" width="13" height="8" rx="2" ry="2" />
                                </svg>
                            </span>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-11 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                            <button type="button" id="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600" aria-label="Show password">
                                <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z" />
                                    <circle cx="12" cy="12" r="2.75" />
                                </svg>
                                <svg id="eyeClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="hidden h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.7 5.1A10.6 10.6 0 0 1 12 4.5c6 0 9.75 7.5 9.75 7.5a18.5 18.5 0 0 1-3.4 4.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.6 6.6C4 8.6 2.25 12 2.25 12S6 18.75 12 18.75c1 0 1.95-.13 2.83-.38" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 9.5a2.75 2.75 0 0 0 3.9 3.9" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="mt-1 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-emerald-600 to-green-500 text-sm font-semibold text-white shadow-[0_12px_24px_rgba(34,197,94,0.30)] transition hover:-translate-y-0.5 hover:shadow-[0_16px_28px_rgba(34,197,94,0.34)]">
                        Sign In
                    </button>

                    <div class="flex items-center gap-3 py-1">
                        <div class="h-px flex-1 bg-slate-200"></div>
                        <span class="text-xs uppercase tracking-[0.26em] text-slate-400">OR</span>
                        <div class="h-px flex-1 bg-slate-200"></div>
                    </div>

                    <p class="text-center text-sm text-slate-500">
                        Don't have an account? <a href="register.php" class="font-semibold text-emerald-600 hover:text-emerald-700">Register here</a>
                    </p>

                    <a href="../index.php" class="block text-center text-xs text-slate-400 transition hover:text-slate-500">&larr; Back to Home</a>
                </form>
            </div>
        </section>
    </main>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeOpen = document.getElementById('eyeOpen');
        const eyeClosed = document.getElementById('eyeClosed');

        togglePassword.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            eyeOpen.classList.toggle('hidden', !isHidden);
            eyeClosed.classList.toggle('hidden', isHidden);
            togglePassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    </script>
</body>
</html> 