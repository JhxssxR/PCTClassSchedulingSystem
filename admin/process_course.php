<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

function table_exists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add':
                // Validate required fields
                if (empty($_POST['course_code']) || empty($_POST['course_name']) || empty($_POST['units'])) {
                    throw new Exception('All fields are required');
                }

                // Check if course code already exists
                $stmt = $conn->prepare("SELECT id FROM courses WHERE course_code = ?");
                $stmt->execute([$_POST['course_code']]);
                if ($stmt->fetch()) {
                    throw new Exception('Course code already exists');
                }

                // Add new course
                $stmt = $conn->prepare("\n                    INSERT INTO courses (course_code, course_name, units)\n                    VALUES (?, ?, ?)\n                ");
                $stmt->execute([
                    $_POST['course_code'],
                    $_POST['course_name'],
                    $_POST['units']
                ]);

                $_SESSION['success'] = 'Course added successfully';
                break;

            case 'edit':
                // Validate required fields
                if (empty($_POST['course_id']) || empty($_POST['course_code']) || 
                    empty($_POST['course_name']) || empty($_POST['units'])) {
                    throw new Exception('All fields are required');
                }

                // Check if course code already exists (excluding current course)
                $stmt = $conn->prepare("\n                    SELECT id FROM courses \n                    WHERE course_code = ? AND id != ?\n                ");
                $stmt->execute([$_POST['course_code'], $_POST['course_id']]);
                if ($stmt->fetch()) {
                    throw new Exception('Course code already exists');
                }

                // Update course
                $stmt = $conn->prepare("\n                    UPDATE courses \n                    SET course_code = ?, course_name = ?, units = ?\n                    WHERE id = ?\n                ");
                $stmt->execute([
                    $_POST['course_code'],
                    $_POST['course_name'],
                    $_POST['units'],
                    $_POST['course_id']
                ]);

                $_SESSION['success'] = 'Course updated successfully';
                break;

            case 'delete':
                // Validate course ID
                if (empty($_POST['course_id'])) {
                    throw new Exception('Course ID is required');
                }

                $course_id = (int)$_POST['course_id'];
                if ($course_id <= 0) {
                    throw new Exception('Invalid course');
                }

                $conn->beginTransaction();

                try {
                    // Do not allow deleting courses with enrolled students.
                    if (table_exists($conn, 'enrollments') && table_exists($conn, 'schedules')) {
                        $stmt = $conn->prepare("\n                            SELECT COUNT(*)\n                            FROM enrollments e\n                            INNER JOIN schedules s ON s.id = e.schedule_id\n                            WHERE s.course_id = ?\n                              AND e.status IN ('approved', 'enrolled')\n                        ");
                        $stmt->execute([$course_id]);
                        if ((int)$stmt->fetchColumn() > 0) {
                            throw new Exception('Cannot delete course with enrolled students');
                        }
                    }

                    // Do not allow deleting courses that still have active schedules.
                    $stmt = $conn->prepare("\n                        SELECT COUNT(*) FROM schedules \n                        WHERE course_id = ? AND status = 'active'\n                    ");
                    $stmt->execute([$course_id]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete course with active schedules');
                    }

                    // Remove dependent rows first to satisfy foreign key constraints.
                    if (table_exists($conn, 'enrollments') && table_exists($conn, 'schedules')) {
                        $stmt = $conn->prepare("\n                            DELETE e FROM enrollments e\n                            INNER JOIN schedules s ON s.id = e.schedule_id\n                            WHERE s.course_id = ?\n                        ");
                        $stmt->execute([$course_id]);
                    }

                    if (table_exists($conn, 'schedule_templates')) {
                        $stmt = $conn->prepare('DELETE FROM schedule_templates WHERE course_id = ?');
                        $stmt->execute([$course_id]);
                    }

                    if (table_exists($conn, 'schedules')) {
                        $stmt = $conn->prepare('DELETE FROM schedules WHERE course_id = ?');
                        $stmt->execute([$course_id]);
                    }

                    $stmt = $conn->prepare('DELETE FROM courses WHERE id = ?');
                    $stmt->execute([$course_id]);
                    if ($stmt->rowCount() < 1) {
                        throw new Exception('Course not found');
                    }

                    $conn->commit();
                } catch (PDOException $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }

                    if ((string)$e->getCode() === '23000') {
                        throw new Exception('Cannot delete course because related records still exist.');
                    }

                    throw $e;
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    throw $e;
                }

                $_SESSION['success'] = 'Course deleted successfully';
                break;

            default:
                throw new Exception('Invalid action');
        }

        header('Location: courses.php');
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: courses.php');
        exit();
    }
} else {
    header('Location: courses.php');
    exit();
}