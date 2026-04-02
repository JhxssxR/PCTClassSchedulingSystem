<?php
session_start();
require_once '../config/database.php';

$error = '';
$allowed_roles = ['super_admin', 'admin', 'instructor', 'student'];
$role = $_GET['role'] ?? 'student';

if (!in_array($role, $allowed_roles, true)) {
    header('Location: ../index.php');
    exit();
}

$roleLabel = ucwords(str_replace('_', ' ', $role));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $email = trim($_POST['email'] ?? '');

    $name_parts = explode(' ', $fullname, 2);
    $first_name = $name_parts[0] ?? '';
    $last_name = $name_parts[1] ?? '';

    if (empty($fullname) || empty($password) || empty($confirm_password) || empty($email) || empty($username)) {
        $error = 'Please fill in all fields';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
        $error = 'Password must be at least 8 characters long, contain at least one capital letter and one number';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } elseif (empty($last_name)) {
        $error = 'Please enter your full name (first name and last name)';
    } else {
        try {
            $stmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                $error = 'Username already exists';
            } else {
                $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->rowCount() > 0) {
                    $error = 'Email already exists';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('INSERT INTO users (username, password, email, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)');

                    if ($stmt->execute([$username, $hashed_password, $email, $role, $first_name, $last_name])) {
                        $_SESSION['register_success'] = 'Registration successful! Please login with your credentials.';
                        header('Location: login.php?role=' . urlencode($role));
                        exit();
                    }

                    $error = 'Registration failed. Please try again.';
                }
            }
        } catch (PDOException $e) {
            error_log('Registration Error: ' . $e->getMessage());
            $error = 'An error occurred during registration. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - PCT Class Scheduling</title>
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
                    <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Join PCT Today</h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-white/80 sm:text-lg">
                        Create your account and get access to schedules, courses, and your academic journey.
                    </p>

                    <ol class="mt-8 space-y-4 text-sm text-white/90">
                        <li class="flex items-start gap-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/12 text-xs font-semibold text-white/90">01</span>
                            <span class="pt-1">Create your account</span>
                        </li>
                        <li class="flex items-start gap-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/12 text-xs font-semibold text-white/90">02</span>
                            <span class="pt-1">Set up your profile</span>
                        </li>
                        <li class="flex items-start gap-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/12 text-xs font-semibold text-white/90">03</span>
                            <span class="pt-1">Access your classes</span>
                        </li>
                    </ol>
                </div>

                <div class="mt-auto pt-14">
                    <div class="relative w-full overflow-hidden rounded-2xl border border-white/10 bg-white/10 px-4 py-4 shadow-[0_18px_40px_rgba(0,0,0,0.18)] backdrop-blur-sm sm:px-6 sm:py-5">
                        <div class="absolute inset-0 bg-gradient-to-r from-white/5 via-white/10 to-white/5"></div>
                        <div class="relative flex min-h-[84px] items-center gap-4">
                            <img src="../pctlogo.png" alt="PCT Logo" class="h-14 w-14 shrink-0 object-contain sm:h-16 sm:w-16">
                            <div class="min-w-0 flex-1 text-center">
                                <p class="text-[13px] font-medium italic leading-6 text-white/80 sm:text-[15px]">
                                    Education is the most powerful weapon which you can use to change the world.
                                </p>
                                <p class="mt-2 text-[11px] uppercase tracking-[0.28em] text-white/60 sm:text-xs">Nelson Mandela</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="flex items-center justify-center bg-white px-5 py-10 sm:px-8 lg:px-10">
            <div class="w-full max-w-[420px] rounded-[28px] border border-slate-100 bg-white px-7 py-8 shadow-[0_20px_45px_rgba(15,23,42,0.10)] sm:px-8">
                <div>
                    <h2 class="text-[32px] font-semibold tracking-tight text-slate-800">Create Account</h2>
                    <p class="mt-1 text-sm text-slate-400">Register as a <?php echo htmlspecialchars(strtolower($roleLabel)); ?> to get started</p>
                </div>

                <?php if ($error): ?>
                    <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="?role=<?php echo urlencode($role); ?>" id="registerForm" class="mt-7 space-y-5" novalidate>
                    <div>
                        <label for="fullname" class="mb-2 block text-sm font-semibold text-slate-700">Full Name</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5a7.5 7.5 0 0 1 15 0" />
                                </svg>
                            </span>
                            <input
                                type="text"
                                id="fullname"
                                name="fullname"
                                autocomplete="name"
                                placeholder="Enter your first and last name"
                                value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Email Address</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <rect x="3.5" y="5.5" width="17" height="13" rx="2.25" ry="2.25" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6" />
                                </svg>
                            </span>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                autocomplete="email"
                                placeholder="Enter your email address"
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="username" class="mb-2 block text-sm font-semibold text-slate-700">Username</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5a7.5 7.5 0 0 1 15 0" />
                                </svg>
                            </span>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                autocomplete="username"
                                placeholder="Choose a username"
                                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password</label>
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
                                autocomplete="new-password"
                                placeholder="Create a strong password"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-11 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                            <button type="button" id="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600" aria-label="Show password">
                                <svg id="passwordEyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z" />
                                    <circle cx="12" cy="12" r="2.75" />
                                </svg>
                                <svg id="passwordEyeClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="hidden h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.7 5.1A10.6 10.6 0 0 1 12 4.5c6 0 9.75 7.5 9.75 7.5a18.5 18.5 0 0 1-3.4 4.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.6 6.6C4 8.6 2.25 12 2.25 12S6 18.75 12 18.75c1 0 1.95-.13 2.83-.38" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 9.5a2.75 2.75 0 0 0 3.9 3.9" />
                                </svg>
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-slate-400">Must be at least 8 characters with one capital letter and one number.</p>
                    </div>

                    <div>
                        <label for="confirm_password" class="mb-2 block text-sm font-semibold text-slate-700">Confirm Password</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V7.875a4.5 4.5 0 1 0-9 0v2.625" />
                                    <rect x="5.5" y="10.5" width="13" height="8" rx="2" ry="2" />
                                </svg>
                            </span>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                autocomplete="new-password"
                                placeholder="Re-enter your password"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-11 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                            <button type="button" id="toggleConfirmPassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600" aria-label="Show password confirmation">
                                <svg id="confirmPasswordEyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z" />
                                    <circle cx="12" cy="12" r="2.75" />
                                </svg>
                                <svg id="confirmPasswordEyeClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="hidden h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.7 5.1A10.6 10.6 0 0 1 12 4.5c6 0 9.75 7.5 9.75 7.5a18.5 18.5 0 0 1-3.4 4.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.6 6.6C4 8.6 2.25 12 2.25 12S6 18.75 12 18.75c1 0 1.95-.13 2.83-.38" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 9.5a2.75 2.75 0 0 0 3.9 3.9" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="mt-1 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-emerald-600 to-green-500 text-sm font-semibold text-white shadow-[0_12px_24px_rgba(34,197,94,0.30)] transition hover:-translate-y-0.5 hover:shadow-[0_16px_28px_rgba(34,197,94,0.34)]">
                        Register as <?php echo htmlspecialchars($roleLabel); ?>
                    </button>

                    <div class="flex items-center gap-3 py-1">
                        <div class="h-px flex-1 bg-slate-200"></div>
                        <span class="text-xs uppercase tracking-[0.26em] text-slate-400">OR</span>
                        <div class="h-px flex-1 bg-slate-200"></div>
                    </div>

                    <p class="text-center text-sm text-slate-500">
                        Already have an account? <a href="login.php?role=<?php echo urlencode($role); ?>" class="font-semibold text-emerald-600 hover:text-emerald-700">Sign in here</a>
                    </p>

                    <a href="../index.php" class="block text-center text-xs text-slate-400 transition hover:text-slate-500">&larr; Back to Home</a>
                </form>
            </div>
        </section>
    </main>

    <script>
        const registerForm = document.getElementById('registerForm');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');

        const setupToggle = (buttonId, inputId, openId, closedId) => {
            const button = document.getElementById(buttonId);
            const input = document.getElementById(inputId);
            const openIcon = document.getElementById(openId);
            const closedIcon = document.getElementById(closedId);

            button.addEventListener('click', () => {
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                openIcon.classList.toggle('hidden', !isHidden);
                closedIcon.classList.toggle('hidden', isHidden);
                button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        };

        setupToggle('togglePassword', 'password', 'passwordEyeOpen', 'passwordEyeClosed');
        setupToggle('toggleConfirmPassword', 'confirm_password', 'confirmPasswordEyeOpen', 'confirmPasswordEyeClosed');

        registerForm.addEventListener('submit', function(event) {
            const passwordPattern = /^(?=.*[A-Z])(?=.*\d).{8,}$/;

            if (!passwordPattern.test(passwordInput.value)) {
                event.preventDefault();
                alert('Password must be at least 8 characters long, contain at least one capital letter and one number');
            } else if (passwordInput.value !== confirmPasswordInput.value) {
                event.preventDefault();
                alert('Passwords must match');
            }
        });
    </script>
</body>
</html>
