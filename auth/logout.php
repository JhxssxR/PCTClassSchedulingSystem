<?php
session_start();
require_once '../includes/activity_log.php';

if (isset($_SESSION['user_id'])) {
	activity_log_write('logout', (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? ''), [
		'message' => 'Signed out',
	]);
}

session_destroy();
header('Location: ../index.php');
exit();
?> 