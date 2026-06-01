<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
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

                $course_cols = [];
                foreach (get_table_columns($conn, 'courses') as $r) {
                    $col_name = $r['Field'] ?? $r['column_name'] ?? '';
                    if ($col_name !== '') {
                        $course_cols[$col_name] = true;
                    }
                }

                // Build dynamic insert – always supply both units AND credits if they exist
                $fields = ['course_code' => $_POST['course_code'], 'course_name' => $_POST['course_name']];
                if (isset($course_cols['units']))   $fields['units']   = (int)$_POST['units'];
                if (isset($course_cols['credits'])) $fields['credits'] = (int)$_POST['units'];
                if (isset($course_cols['created_at'])) $fields['created_at'] = date('Y-m-d H:i:s');

                $col_names = array_keys($fields);
                $placeholders = array_fill(0, count($fields), '?');
                $sql = 'INSERT INTO courses (' . implode(', ', $col_names) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_values($fields));

                $_SESSION['success'] = 'Course added successfully';
                break;

            case 'edit':
                // Validate required fields
                if (empty($_POST['course_id']) || empty($_POST['course_code']) || 
                    empty($_POST['course_name']) || empty($_POST['units'])) {
                    throw new Exception('All fields are required');
                }

                // Check if course code already exists (excluding current course)
                $stmt = $conn->prepare("
                    SELECT id FROM courses 
                    WHERE course_code = ? AND id != ?
                ");
                $stmt->execute([$_POST['course_code'], $_POST['course_id']]);
                if ($stmt->fetch()) {
                    throw new Exception('Course code already exists');
                }
                
                $course_cols = [];
                foreach (get_table_columns($conn, 'courses') as $r) {
                    $col_name = $r['Field'] ?? $r['column_name'] ?? '';
                    if ($col_name !== '') {
                        $course_cols[$col_name] = true;
                    }
                }

                // Update – always set both units AND credits if they exist
                $set_parts = ['course_code = ?', 'course_name = ?'];
                $params_u = [$_POST['course_code'], $_POST['course_name']];
                if (isset($course_cols['units']))   { $set_parts[] = 'units = ?';   $params_u[] = (int)$_POST['units']; }
                if (isset($course_cols['credits'])) { $set_parts[] = 'credits = ?'; $params_u[] = (int)$_POST['units']; }
                $params_u[] = $_POST['course_id'];

                $stmt = $conn->prepare('UPDATE courses SET ' . implode(', ', $set_parts) . ' WHERE id = ?');
                $stmt->execute($params_u);

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
                        $stmt = $conn->prepare("
                            DELETE FROM enrollments 
                            WHERE schedule_id IN (
                                SELECT id FROM schedules WHERE course_id = ?
                            )
                        ");
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