<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

function table_columns(PDO $conn, string $table): array {
    $stmt = $conn->prepare("DESCRIBE {$table}");
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    return $cols;
}

function table_exists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: courses.php');
    exit();
}

$action = $_POST['action'] ?? '';

try {
    $cols = table_columns($conn, 'courses');
    $units_col = isset($cols['units']) ? 'units' : (isset($cols['credits']) ? 'credits' : 'units');

    switch ($action) {
        case 'add': {
            if (empty($_POST['course_code']) || empty($_POST['course_name']) || empty($_POST['units'])) {
                throw new Exception('All fields are required');
            }

            $course_code = trim((string)$_POST['course_code']);
            $course_name = trim((string)$_POST['course_name']);
            $units = (int)$_POST['units'];

            if ($units <= 0) {
                throw new Exception('Units must be greater than 0');
            }

            $stmt = $conn->prepare('SELECT id FROM courses WHERE course_code = ?');
            $stmt->execute([$course_code]);
            if ($stmt->fetch()) {
                throw new Exception('Course code already exists');
            }

            $fields = [
                'course_code' => $course_code,
                'course_name' => $course_name,
                $units_col => $units,
            ];
            if (isset($cols['created_by'])) {
                $fields['created_by'] = (int)$_SESSION['user_id'];
            }

            $col_names = array_keys($fields);
            $placeholders = array_fill(0, count($fields), '?');
            $sql = 'INSERT INTO courses (' . implode(', ', $col_names) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_values($fields));

            $_SESSION['success'] = 'Course added successfully';
            break;
        }

        case 'edit': {
            if (empty($_POST['course_id']) || empty($_POST['course_code']) || empty($_POST['course_name']) || empty($_POST['units'])) {
                throw new Exception('All fields are required');
            }

            $course_id = (int)$_POST['course_id'];
            $course_code = trim((string)$_POST['course_code']);
            $course_name = trim((string)$_POST['course_name']);
            $units = (int)$_POST['units'];

            if ($course_id <= 0) {
                throw new Exception('Invalid course');
            }
            if ($units <= 0) {
                throw new Exception('Units must be greater than 0');
            }

            $stmt = $conn->prepare('SELECT id FROM courses WHERE course_code = ? AND id != ?');
            $stmt->execute([$course_code, $course_id]);
            if ($stmt->fetch()) {
                throw new Exception('Course code already exists');
            }

            $set = [
                'course_code' => $course_code,
                'course_name' => $course_name,
                $units_col => $units,
            ];

            $assignments = [];
            $values = [];
            foreach ($set as $k => $v) {
                $assignments[] = "$k = ?";
                $values[] = $v;
            }
            $values[] = $course_id;

            $sql = 'UPDATE courses SET ' . implode(', ', $assignments) . ' WHERE id = ?';
            $stmt = $conn->prepare($sql);
            $stmt->execute($values);

            $_SESSION['success'] = 'Course updated successfully';
            break;
        }

        case 'delete': {
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
                    $stmt = $conn->prepare("\n                        SELECT COUNT(*)\n                        FROM enrollments e\n                        INNER JOIN schedules s ON s.id = e.schedule_id\n                        WHERE s.course_id = ?\n                          AND e.status IN ('approved', 'enrolled')\n                    ");
                    $stmt->execute([$course_id]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete course with enrolled students');
                    }
                }

                // Do not allow deleting courses that still have active schedules.
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE course_id = ? AND status = 'active'");
                $stmt->execute([$course_id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception('Cannot delete course with active schedules');
                }

                // Remove dependent rows first to satisfy foreign key constraints.
                if (table_exists($conn, 'enrollments') && table_exists($conn, 'schedules')) {
                    $stmt = $conn->prepare("\n                        DELETE e FROM enrollments e\n                        INNER JOIN schedules s ON s.id = e.schedule_id\n                        WHERE s.course_id = ?\n                    ");
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
        }

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
