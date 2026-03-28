<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'update') {
            // General settings (strings)
            $school_name = trim((string)filter_input(INPUT_POST, 'school_name', FILTER_UNSAFE_RAW) ?? '');
            $school_short_name = trim((string)filter_input(INPUT_POST, 'school_short_name', FILTER_UNSAFE_RAW) ?? '');
            $school_address = trim((string)filter_input(INPUT_POST, 'school_address', FILTER_UNSAFE_RAW) ?? '');
            $contact_email_raw = trim((string)filter_input(INPUT_POST, 'contact_email', FILTER_UNSAFE_RAW) ?? '');
            $contact_phone = trim((string)filter_input(INPUT_POST, 'contact_phone', FILTER_UNSAFE_RAW) ?? '');
            $school_website_raw = trim((string)filter_input(INPUT_POST, 'school_website', FILTER_UNSAFE_RAW) ?? '');
            $school_description = trim((string)filter_input(INPUT_POST, 'school_description', FILTER_UNSAFE_RAW) ?? '');

            $contact_email = '';
            if ($contact_email_raw !== '') {
                $validated = filter_var($contact_email_raw, FILTER_VALIDATE_EMAIL);
                if ($validated === false) {
                    throw new Exception('Contact email must be a valid email address');
                }
                $contact_email = $validated;
            }

            $school_website = '';
            if ($school_website_raw !== '') {
                $validated = filter_var($school_website_raw, FILTER_VALIDATE_URL);
                if ($validated === false) {
                    throw new Exception('Website must be a valid URL');
                }
                $school_website = $validated;
            }

            if ($school_name === '' || strlen($school_name) > 200) {
                throw new Exception('School name is required (max 200 characters)');
            }
            if ($school_short_name === '' || strlen($school_short_name) > 50) {
                throw new Exception('Short name is required (max 50 characters)');
            }
            if (strlen($school_address) > 255) {
                throw new Exception('Address must be 255 characters or less');
            }
            if (strlen($contact_phone) > 50) {
                throw new Exception('Contact phone must be 50 characters or less');
            }
            if (strlen($school_description) > 2000) {
                throw new Exception('School description must be 2000 characters or less');
            }

            // Validate and sanitize input
            $max_enrollments = filter_input(INPUT_POST, 'max_enrollments', FILTER_VALIDATE_INT);
            $enrollment_approval = filter_input(INPUT_POST, 'enrollment_approval', FILTER_VALIDATE_INT);
            $default_class_duration = filter_input(INPUT_POST, 'default_class_duration', FILTER_VALIDATE_INT);
            $break_time = filter_input(INPUT_POST, 'break_time', FILTER_VALIDATE_INT);
            $email_notifications = filter_input(INPUT_POST, 'email_notifications', FILTER_VALIDATE_INT);
            $notification_days = filter_input(INPUT_POST, 'notification_days', FILTER_VALIDATE_INT);

            // Validate input ranges
            if ($max_enrollments < 1 || $max_enrollments > 10) {
                throw new Exception('Maximum enrollments must be between 1 and 10');
            }
            if ($default_class_duration < 30 || $default_class_duration > 180 || $default_class_duration % 30 !== 0) {
                throw new Exception('Default class duration must be between 30 and 180 minutes in 30-minute increments');
            }
            if ($break_time < 0 || $break_time > 60 || $break_time % 5 !== 0) {
                throw new Exception('Break time must be between 0 and 60 minutes in 5-minute increments');
            }
            if ($notification_days < 0 || $notification_days > 7) {
                throw new Exception('Notification days must be between 0 and 7');
            }

            // Begin transaction
            $conn->beginTransaction();

            // Update settings
            $settings = [
                'school_name' => $school_name,
                'school_short_name' => $school_short_name,
                'school_address' => $school_address,
                'contact_email' => $contact_email,
                'contact_phone' => $contact_phone,
                'school_website' => $school_website,
                'school_description' => $school_description,
                'max_enrollments' => $max_enrollments,
                'enrollment_approval' => $enrollment_approval,
                'default_class_duration' => $default_class_duration,
                'break_time' => $break_time,
                'email_notifications' => $email_notifications,
                'notification_days' => $notification_days
            ];

            $stmt = $conn->prepare("INSERT INTO settings (`key`, `value`) 
                                  VALUES (:key, :value) 
                                  ON DUPLICATE KEY UPDATE `value` = :value");

            foreach ($settings as $key => $value) {
                $stmt->execute([
                    ':key' => $key,
                    ':value' => $value
                ]);
            }

            // Commit transaction
            $conn->commit();

            $_SESSION['success'] = 'Settings updated successfully';
        } else {
            throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = $e->getMessage();
    }
}

// Redirect back to settings page
header('Location: settings.php');
exit(); 