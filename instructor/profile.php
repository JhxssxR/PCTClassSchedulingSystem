<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an instructor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header('Location: ../auth/login.php?role=instructor');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

// Get instructor data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate email
        if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }

        // Check if email is already used by another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$_POST['email'], $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Email is already in use by another user");
        }

        $params = [
            'first_name' => $_POST['first_name'],
            'last_name' => $_POST['last_name'],
            'email' => $_POST['email'],
            'id' => $_SESSION['user_id']
        ];

        // Update password only if provided
        $passwordSql = '';
        if (!empty($_POST['new_password'])) {
            // Verify current password
            if (!password_verify($_POST['current_password'], $instructor['password'])) {
                throw new Exception("Current password is incorrect.");
            }

            // Validate new password
            if (strlen($_POST['new_password']) < 8) {
                throw new Exception("Password must be at least 8 characters long");
            }
            if (!preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $_POST['new_password'])) {
                throw new Exception("Password must contain at least one capital letter and one number");
            }
            if ($_POST['new_password'] !== $_POST['confirm_password']) {
                throw new Exception("New passwords do not match");
            }

            $params['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $passwordSql = ', password = :password';
        }

        // Update user
        $stmt = $conn->prepare("
            UPDATE users 
            SET first_name = :first_name,
                email = :email,
                last_name = :last_name" . $passwordSql . "
            WHERE id = :id
        ");
        $stmt->execute($params);
        
        $_SESSION['success_message'] = "Profile updated successfully.";
        header('Location: profile.php');
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - PCT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c5d3f;
            --secondary: #7bc26f;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }

        .navbar {
            background-color: var(--primary);
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background-color: var(--primary);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #234a2a;
            border-color: #234a2a;
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'profile';
    include '../includes/sidebar.php'; 
    ?>

    <div class="main-content">
        <div class="container">
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">My Profile</h5>
                        </div>
                        <div class="card-body">
                            <?php if (isset($_SESSION['success_message'])): ?>
                                <div class="alert alert-success">
                                    <?php 
                                    echo $_SESSION['success_message'];
                                    unset($_SESSION['success_message']);
                                    ?>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($_SESSION['error_message'])): ?>
                                <div class="alert alert-danger">
                                    <?php 
                                    echo $_SESSION['error_message'];
                                    unset($_SESSION['error_message']);
                                    ?>
                                </div>
                            <?php endif; ?>

                            <form action="profile.php" method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($instructor['username']); ?>" readonly>
                                    <small class="text-muted">Username cannot be changed</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">First Name</label>
                                    <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($instructor['first_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($instructor['last_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($instructor['email']); ?>" required>
                                </div>
                                <hr>
                                <h6>Change Password</h6>
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" class="form-control" name="current_password">
                                    <small class="text-muted">Required only if changing password</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control" name="new_password">
                                    <small class="text-muted">Leave blank to keep current password</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password">
                                </div>
                                <button type="submit" class="btn btn-primary">Update Profile</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 