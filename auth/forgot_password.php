<?php
session_start();
require_once '../config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        try {
            $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);

            $success = "If this email exists in our system, we've sent password reset instructions.";
        } catch (PDOException $e) {
            error_log('Forgot Password Error: ' . $e->getMessage());
            $error = 'An error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - PCT Class Scheduling</title>
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
                    <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Password Recovery</h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-white/80 sm:text-lg">
                        Don't worry - it happens to the best of us. We'll help you get back into your account quickly.
                    </p>

                    <ol class="mt-8 space-y-4 text-sm text-white/90">
                        <li class="flex items-start gap-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/12 text-xs font-semibold text-white/90">01</span>
                            <span class="pt-1">Enter your registered email</span>
                        </li>
                        <li class="flex items-start gap-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/12 text-xs font-semibold text-white/90">02</span>
                            <span class="pt-1">Check your inbox for the reset link</span>
                        </li>
                        <li class="flex items-start gap-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/12 text-xs font-semibold text-white/90">03</span>
                            <span class="pt-1">Set a new secure password</span>
                        </li>
                    </ol>
                </div>

                <div class="mt-auto pt-14">
                    <div class="relative w-full overflow-hidden rounded-2xl border border-white/10 bg-white/10 px-4 py-4 shadow-[0_18px_40px_rgba(0,0,0,0.18)] backdrop-blur-sm sm:px-6 sm:py-5">
                        <div class="absolute inset-0 bg-gradient-to-r from-white/5 via-white/10 to-white/5"></div>
                        <div class="relative flex min-h-[84px] items-center gap-4">
                            <img src="../pctlogo.png" alt="PCT Logo" class="h-14 w-14 shrink-0 object-contain sm:h-16 sm:w-16">
                            <div class="min-w-0 flex-1 text-center">
                                <p class="text-[13px] font-medium leading-6 text-white/80 sm:text-[15px]">
                                    Tip: Check your spam or junk folder if you don't see the email within a few minutes.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="flex items-center justify-center bg-white px-5 py-10 sm:px-8 lg:px-10">
            <div class="w-full max-w-[420px] rounded-[28px] border border-slate-100 bg-white px-7 py-8 shadow-[0_20px_45px_rgba(15,23,42,0.10)] sm:px-8">
                <div class="mb-6 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-[0_12px_24px_rgba(34,197,94,0.30)]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                        <rect x="3.5" y="5.5" width="17" height="13" rx="2.25" ry="2.25" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6" />
                    </svg>
                </div>

                <div>
                    <h2 class="text-[32px] font-semibold tracking-tight text-slate-800">Forgot Password?</h2>
                    <p class="mt-1 text-sm text-slate-400">Enter the email linked to your account and we'll send you a reset link.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="mt-7 space-y-5" novalidate>
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
                                placeholder="Enter your registered email"
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                required
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50/80 pl-11 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            >
                        </div>
                    </div>

                    <button type="submit" class="mt-1 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-emerald-600 to-green-500 text-sm font-semibold text-white shadow-[0_12px_24px_rgba(34,197,94,0.30)] transition hover:-translate-y-0.5 hover:shadow-[0_16px_28px_rgba(34,197,94,0.34)]">
                        Send Reset Link
                    </button>

                    <div class="flex items-center gap-3 py-1">
                        <div class="h-px flex-1 bg-slate-200"></div>
                        <span class="text-xs uppercase tracking-[0.26em] text-slate-400">OR</span>
                        <div class="h-px flex-1 bg-slate-200"></div>
                    </div>

                    <a href="login.php" class="inline-flex h-11 w-full items-center justify-center rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-slate-600 transition hover:border-emerald-300 hover:text-emerald-600">
                        &larr; Back to Sign In
                    </a>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
