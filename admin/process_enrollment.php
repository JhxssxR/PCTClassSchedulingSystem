<?php
session_start();
require_once '../config/database.php';
require_once '../includes/process_enrollment_common.php';

enrollment_process_request($conn, [
    'allowed_roles' => ['super_admin'],
    'redirect_to' => 'enrollments.php',
]);