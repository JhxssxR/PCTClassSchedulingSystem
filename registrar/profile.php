<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = 'User account not found.';
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $first_name = trim((string)($_POST['first_name'] ?? ''));
        $last_name = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($first_name === '' || $last_name === '' || $email === '') {
            throw new Exception('Please fill out all required fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        $params = [
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':email' => $email,
            ':id' => (int)$_SESSION['user_id'],
        ];

        $password_sql = '';
        $new_password = (string)($_POST['new_password'] ?? '');
        $current_password = (string)($_POST['current_password'] ?? '');
        if (trim($new_password) !== '') {
            if (trim($current_password) === '') {
                throw new Exception('Please enter your current password to set a new password.');
            }
            if (!password_verify($current_password, (string)($user['password'] ?? ''))) {
                throw new Exception('Current password is incorrect.');
            }
            $password_sql = ', password = :password';
            $params[':password'] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        $stmt = $conn->prepare(
            'UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email' . $password_sql . ' WHERE id = :id'
        );
        $stmt->execute($params);

        // Keep session name in sync
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;

        $_SESSION['success'] = 'Profile updated successfully.';
        header('Location: profile.php');
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        // Keep user values in the form
        $user['first_name'] = $first_name ?? ($user['first_name'] ?? '');
        $user['last_name'] = $last_name ?? ($user['last_name'] ?? '');
        $user['email'] = $email ?? ($user['email'] ?? '');
    }
}

$page_title = 'Manage Account';
$breadcrumbs = 'Registrar / Manage Account';
$active_page = 'manage_account';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="flex items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Manage Account</h1>
        <p class="text-sm text-slate-500">Manage your profile and password</p>
    </div>

    <button form="profileForm" type="submit" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
        <i class="bi bi-save"></i>
        Save Changes
    </button>
</div>

<?php if (isset($_SESSION['success']) || isset($_SESSION['error'])): ?>
    <div class="mt-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
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

<form id="profileForm" action="profile.php" method="POST" class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200">
            <div class="text-base font-semibold text-slate-900">My Profile</div>
            <div class="text-sm text-slate-500">Basic account information</div>
        </div>
        <div class="p-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Username</label>
                <input type="text" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars((string)($user['username'] ?? '')); ?>" readonly>
                <div class="mt-1 text-xs text-slate-500">Username cannot be changed</div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">First Name</label>
                    <input type="text" name="first_name" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars((string)($user['first_name'] ?? '')); ?>" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Last Name</label>
                    <input type="text" name="last_name" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars((string)($user['last_name'] ?? '')); ?>" required>
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Email</label>
                <input type="email" name="email" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars((string)($user['email'] ?? '')); ?>" required>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200">
            <div class="text-base font-semibold text-slate-900">Security</div>
            <div class="text-sm text-slate-500">Change password (optional)</div>
        </div>
        <div class="p-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Current Password</label>
                <input type="password" name="current_password" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" autocomplete="current-password">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">New Password</label>
                <input type="password" name="new_password" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" autocomplete="new-password">
                <div class="mt-1 text-xs text-slate-500">Leave blank to keep current password</div>
            </div>
        </div>
    </section>
</form>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
