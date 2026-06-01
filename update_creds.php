<?php
require 'config/database.php';

try {
    // Update emails for all users to use @pctdavao.edu.ph
    if (is_pgsql()) {
        $conn->exec("UPDATE users SET email = split_part(email, '@', 1) || '@pctdavao.edu.ph' WHERE email LIKE '%@%'");
    } else {
        $conn->exec("UPDATE users SET email = CONCAT(SUBSTRING_INDEX(email, '@', 1), '@pctdavao.edu.ph') WHERE email LIKE '%@%'");
    }
    echo "<h3>Emails successfully updated to @pctdavao.edu.ph!</h3>";

    // Set passwords
    $stmt = $conn->query("SELECT id, role FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");

    $passwords = [
        'registrar' => password_hash('registrar@12345', PASSWORD_DEFAULT),
        'instructor' => password_hash('instructor@12345', PASSWORD_DEFAULT),
        'student' => password_hash('student@12345', PASSWORD_DEFAULT),
        'super_admin' => password_hash('admin@12345', PASSWORD_DEFAULT),
        'admin' => password_hash('admin@12345', PASSWORD_DEFAULT)
    ];

    $counts = 0;
    foreach ($users as $user) {
        $role = $user['role'];
        if (isset($passwords[$role])) {
            $updateStmt->execute([
                'password' => $passwords[$role],
                'id' => $user['id']
            ]);
            $counts++;
        }
    }
    echo "<h3>Passwords successfully updated for $counts users!</h3>";
    echo "<p>Please let me know once you see this message so I can delete this file for security.</p>";

} catch (Exception $e) {
    echo "<h3>Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
}
