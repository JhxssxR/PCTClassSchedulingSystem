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

function normalize_room_type(string $room_type): string {
    $room_type = strtolower(trim($room_type));
    // Map UI-friendly values to known enums.
    if ($room_type === 'computer' || $room_type === 'computer lab' || $room_type === 'lab') {
        return 'laboratory';
    }
    if ($room_type === 'seminar' || $room_type === 'seminar hall') {
        return 'conference';
    }
    if (!in_array($room_type, ['lecture', 'laboratory', 'conference'], true)) {
        return 'lecture';
    }
    return $room_type;
}

function normalize_room_status(string $status): string {
    $status = strtolower(trim($status));
    if (!in_array($status, ['active', 'maintenance', 'inactive'], true)) {
        return 'active';
    }
    return $status;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: rooms.php');
    exit();
}

$action = $_POST['action'] ?? '';

try {
    $cols = table_columns($conn, 'classrooms');

    switch ($action) {
        case 'add': {
            if (empty($_POST['room_number']) || empty($_POST['capacity']) || empty($_POST['room_type'])) {
                throw new Exception('All fields are required');
            }

            $room_number = trim((string)$_POST['room_number']);
            $capacity = (int)$_POST['capacity'];
            $room_type = normalize_room_type((string)$_POST['room_type']);
            $status = isset($_POST['status']) ? normalize_room_status((string)$_POST['status']) : 'active';
            $building = trim((string)($_POST['building'] ?? 'Main Building'));

            $stmt = $conn->prepare('SELECT id FROM classrooms WHERE room_number = ?');
            $stmt->execute([$room_number]);
            if ($stmt->fetch()) {
                throw new Exception('Room number already exists');
            }

            $fields = ['room_number' => $room_number, 'capacity' => $capacity, 'room_type' => $room_type];
            if (isset($cols['status'])) {
                $fields['status'] = $status;
            }
            if (isset($cols['building'])) {
                $fields['building'] = ($building === '' ? 'Main Building' : $building);
            }
            if (isset($cols['created_by'])) {
                $fields['created_by'] = (int)$_SESSION['user_id'];
            }

            $col_names = array_keys($fields);
            $placeholders = array_fill(0, count($fields), '?');
            $sql = 'INSERT INTO classrooms (' . implode(', ', $col_names) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_values($fields));

            $_SESSION['success'] = 'Room added successfully';
            break;
        }

        case 'edit': {
            if (empty($_POST['classroom_id']) || empty($_POST['room_number']) || empty($_POST['capacity']) || empty($_POST['room_type'])) {
                throw new Exception('All fields are required');
            }

            $classroom_id = (int)$_POST['classroom_id'];
            $room_number = trim((string)$_POST['room_number']);
            $capacity = (int)$_POST['capacity'];
            $room_type = normalize_room_type((string)$_POST['room_type']);
            $status = isset($_POST['status']) ? normalize_room_status((string)$_POST['status']) : 'active';
            $building = trim((string)($_POST['building'] ?? 'Main Building'));

            $stmt = $conn->prepare('SELECT id FROM classrooms WHERE room_number = ? AND id != ?');
            $stmt->execute([$room_number, $classroom_id]);
            if ($stmt->fetch()) {
                throw new Exception('Room number already exists');
            }

            $set = ['room_number' => $room_number, 'capacity' => $capacity, 'room_type' => $room_type];
            if (isset($cols['status'])) {
                $set['status'] = $status;
            }
            if (isset($cols['building'])) {
                $set['building'] = ($building === '' ? 'Main Building' : $building);
            }

            $assignments = [];
            $values = [];
            foreach ($set as $k => $v) {
                $assignments[] = "$k = ?";
                $values[] = $v;
            }
            $values[] = $classroom_id;
            $sql = 'UPDATE classrooms SET ' . implode(', ', $assignments) . ' WHERE id = ?';
            $stmt = $conn->prepare($sql);
            $stmt->execute($values);

            $_SESSION['success'] = 'Room updated successfully';
            break;
        }

        case 'delete': {
            if (empty($_POST['classroom_id'])) {
                throw new Exception('Room ID is required');
            }
            $classroom_id = (int)$_POST['classroom_id'];

            $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE classroom_id = ? AND status = 'active'");
            $stmt->execute([$classroom_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('Cannot delete room with active schedules');
            }

            $stmt = $conn->prepare('DELETE FROM classrooms WHERE id = ?');
            $stmt->execute([$classroom_id]);

            $_SESSION['success'] = 'Room deleted successfully';
            break;
        }

        default:
            throw new Exception('Invalid action');
    }

    header('Location: rooms.php');
    exit();

} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: rooms.php');
    exit();
}
