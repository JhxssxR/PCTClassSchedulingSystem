<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    $stmt = $conn->prepare("
                        INSERT INTO time_slots (slot_name, start_time, duration_minutes)
                        VALUES (:name, :start_time, :duration_minutes)
                    ");
                    $stmt->execute([
                        'name' => $_POST['slot_name'],
                        'start_time' => $_POST['start_time'],
                        'duration_minutes' => $_POST['duration_minutes']
                    ]);
                    $_SESSION['success'] = "Time slot added successfully.";
                    break;

                case 'edit':
                    $stmt = $conn->prepare("
                        UPDATE time_slots 
                        SET slot_name = :name,
                            start_time = :start_time,
                            duration_minutes = :duration_minutes,
                            is_active = :is_active
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'id' => $_POST['slot_id'],
                        'name' => $_POST['slot_name'],
                        'start_time' => $_POST['start_time'],
                        'duration_minutes' => $_POST['duration_minutes'],
                        'is_active' => isset($_POST['is_active']) ? 1 : 0
                    ]);
                    $_SESSION['success'] = "Time slot updated successfully.";
                    break;

                case 'delete':
                    $stmt = $conn->prepare("DELETE FROM time_slots WHERE id = ?");
                    $stmt->execute([$_POST['slot_id']]);
                    $_SESSION['success'] = "Time slot deleted successfully.";
                    break;
            }
        }
        header('Location: manage_timeslots.php');
        exit();
    } catch (PDOException $e) {
        error_log("Error in time slot management: " . $e->getMessage());
        $_SESSION['error'] = "An error occurred while processing your request.";
    }
}

// Get all time slots
$stmt = $conn->query("SELECT * FROM time_slots ORDER BY start_time");
$time_slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Time Slots - PCT Scheduling System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include '../includes/header.php'; ?>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col">
                <h2>Manage Time Slots</h2>
            </div>
            <div class="col text-end">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSlotModal">
                    <i class="bi bi-plus-circle"></i> Add Time Slot
                </button>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Start Time</th>
                                <th>Duration</th>
                                <th>End Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($time_slots as $slot): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($slot['slot_name']); ?></td>
                                <td><?php echo date('h:i A', strtotime($slot['start_time'])); ?></td>
                                <td><?php echo $slot['duration_minutes']; ?> minutes</td>
                                <td><?php 
                                    $end_time = strtotime($slot['start_time'] . ' +' . $slot['duration_minutes'] . ' minutes');
                                    echo date('h:i A', $end_time); 
                                ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $slot['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $slot['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="editSlot(<?php echo htmlspecialchars(json_encode($slot)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteSlot(<?php echo $slot['id']; ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Time Slot Modal -->
    <div class="modal fade" id="addSlotModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Time Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="manage_timeslots.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Slot Name</label>
                            <input type="text" class="form-control" name="slot_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control" name="start_time" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration (minutes)</label>
                            <select class="form-control" name="duration_minutes" required>
                                <option value="45">45 minutes</option>
                                <option value="60">1 hour</option>
                                <option value="90" selected>1.5 hours</option>
                                <option value="120">2 hours</option>
                                <option value="180">3 hours</option>
                                <option value="240">4 hours</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Time Slot</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Time Slot Modal -->
    <div class="modal fade" id="editSlotModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Time Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="manage_timeslots.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="slot_id" id="edit_slot_id">
                        <div class="mb-3">
                            <label class="form-label">Slot Name</label>
                            <input type="text" class="form-control" name="slot_name" id="edit_slot_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control" name="start_time" id="edit_start_time" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration (minutes)</label>
                            <select class="form-control" name="duration_minutes" id="edit_duration_minutes" required>
                                <option value="45">45 minutes</option>
                                <option value="60">1 hour</option>
                                <option value="90">1.5 hours</option>
                                <option value="120">2 hours</option>
                                <option value="180">3 hours</option>
                                <option value="240">4 hours</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="is_active" id="edit_is_active">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSlotModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Time Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="manage_timeslots.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="slot_id" id="delete_slot_id">
                        <p>Are you sure you want to delete this time slot?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editSlot(slot) {
            document.getElementById('edit_slot_id').value = slot.id;
            document.getElementById('edit_slot_name').value = slot.slot_name;
            document.getElementById('edit_start_time').value = slot.start_time;
            document.getElementById('edit_duration_minutes').value = slot.duration_minutes;
            document.getElementById('edit_is_active').checked = slot.is_active == 1;
            new bootstrap.Modal(document.getElementById('editSlotModal')).show();
        }

        function deleteSlot(id) {
            document.getElementById('delete_slot_id').value = id;
            new bootstrap.Modal(document.getElementById('deleteSlotModal')).show();
        }

        // Add preview of end time when duration or start time changes
        function updateEndTimePreview(startInput, durationInput, previewElement) {
            if (startInput.value) {
                const startTime = new Date('2000-01-01T' + startInput.value);
                const durationMinutes = parseInt(durationInput.value);
                const endTime = new Date(startTime.getTime() + durationMinutes * 60000);
                previewElement.textContent = endTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } else {
                previewElement.textContent = '--:--';
            }
        }

        // Add event listeners for both add and edit forms
        document.addEventListener('DOMContentLoaded', function() {
            const forms = ['add', 'edit'];
            forms.forEach(formType => {
                const startInput = document.querySelector(`#${formType === 'edit' ? 'edit_' : ''}start_time`);
                const durationInput = document.querySelector(`#${formType === 'edit' ? 'edit_' : ''}duration_minutes`);
                if (startInput && durationInput) {
                    const previewElement = document.createElement('div');
                    previewElement.className = 'form-text';
                    previewElement.id = `${formType}_end_time_preview`;
                    durationInput.parentNode.appendChild(previewElement);

                    const updatePreview = () => updateEndTimePreview(startInput, durationInput, previewElement);
                    startInput.addEventListener('change', updatePreview);
                    durationInput.addEventListener('change', updatePreview);
                }
            });
        });
    </script>
</body>
</html> 