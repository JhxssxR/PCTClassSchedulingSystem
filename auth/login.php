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
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    debug_to_log("Login attempt - Username: $username");

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields";
        debug_to_log("Login failed - Empty fields");
    } else {
        try {
            debug_to_log("Checking database connection...");
            $conn->query("SELECT 1");
            debug_to_log("Database connection OK");

            // First check if the user exists
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
            debug_to_log("Prepared statement created");
            $stmt->execute([$username]);
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
                $error = "Invalid username or password";
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c5d3f;
            --secondary: #7bc26f;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header img {
            width: 120px;
            margin-bottom: 1rem;
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #234a2a;
            border-color: #234a2a;
        }

        .form-control:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 0.25rem rgba(123, 194, 111, 0.25);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="../pctlogo.png" alt="PCT Logo">
            <h2>Login</h2>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['register_success'])): ?>
            <div class="alert alert-success"><?php 
                echo htmlspecialchars($_SESSION['register_success']); 
                unset($_SESSION['register_success']); // Clear the message after displaying
            ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Login</button>
            </div>
        </form>
        
        <div class="mt-3 text-center">
            <a href="<?php echo htmlspecialchars(app_url('index.php')); ?>" class="text-decoration-none">Back to Home</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 