<?php
session_start();
require_once '../config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $email = trim($_POST['email']);
    $role = $_GET['role'] ?? 'student'; // Default to student if no role specified

    // Split full name into first and last name
    $name_parts = explode(' ', $fullname, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

    // Validation
    if (empty($fullname) || empty($password) || empty($confirm_password) || empty($email) || empty($username)) {
        $error = "Please fill in all fields";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
        $error = "Password must be at least 8 characters long, contain at least one capital letter and one number";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (empty($last_name)) {
        $error = "Please enter your full name (first name and last name)";
    } else {
        try {
            // Check if username exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                $error = "Username already exists";
            } else {
                // Check if email exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->rowCount() > 0) {
                    $error = "Email already exists";
                } else {
                    // Create new user
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, password, email, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
                    
                    if ($stmt->execute([$username, $hashed_password, $email, $role, $first_name, $last_name])) {
                        // Redirect to login page with success message
                        $_SESSION['register_success'] = "Registration successful! Please login with your credentials.";
                        header("Location: login.php?role=" . urlencode($role));
                        exit();
                    } else {
                        $error = "Registration failed. Please try again.";
                    }
                }
            }
        } catch(PDOException $e) {
            error_log("Registration Error: " . $e->getMessage());
            $error = "An error occurred during registration. Please try again later.";
        }
    }
}

// Get role from URL parameter
$role = isset($_GET['role']) ? $_GET['role'] : 'student';
if (!in_array($role, ['super_admin', 'admin', 'instructor', 'student'])) {
    header('Location: ../index.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - PCT Bajada Classroom Scheduling System</title>
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }

        .register-container {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .register-header img {
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

        .password-requirements {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <img src="../pctlogo.png" alt="PCT Logo">
            <h2>Register as <?php echo ucfirst($role); ?></h2>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <div class="mb-3">
                <label for="fullname" class="form-label">Full Name</label>
                <input type="text" class="form-control" id="fullname" name="fullname" 
                       value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>"
                       placeholder="Enter your first and last name" required>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                       placeholder="Enter your email address" required>
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" 
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                       placeholder="Choose a username" required>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" 
                       pattern="^(?=.*[A-Z])(?=.*\d).{8,}$" required>
                <div class="password-requirements">
                    Password must be at least 8 characters long, contain at least one capital letter and one number
                </div>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">Register</button>
            </div>
        </form>
        
        <div class="mt-3 text-center">
            <p>Already have an account? <a href="login.php?role=<?php echo htmlspecialchars($role); ?>">Login here</a></p>
            <p><a href="../index.php">Back to Home</a></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Real-time password validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const form = document.querySelector('form');

        form.addEventListener('submit', function(event) {
            if (!password.value.match(/^(?=.*[A-Z])(?=.*\d).{8,}$/)) {
                event.preventDefault();
                alert('Password must be at least 8 characters long, contain at least one capital letter and one number');
            } else if (password.value !== confirmPassword.value) {
                event.preventDefault();
                alert('Passwords must match');
            }
        });
    </script>
</body>
</html> 