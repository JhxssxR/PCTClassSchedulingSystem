<?php
// Get the current role
$role = $_SESSION['role'] ?? '';
$active_page = $active_page ?? '';

// Common sidebar structure
?>
<div class="sidebar">
    <?php if ($role === 'instructor'): ?>
        <div class="sidebar-top d-flex align-items-center justify-content-between mb-3">
            <div class="fw-semibold">Instructor</div>
            <div class="position-relative">
                <button type="button" id="notifBtn" class="btn btn-link text-white p-0 position-relative" aria-label="Notifications">
                    <i class="bi bi-bell fs-5"></i>
                    <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?php echo (($notif_unread_total ?? 0) > 0) ? '' : 'd-none'; ?>">
                        <?php echo htmlspecialchars($notif_badge_label ?? ''); ?>
                    </span>
                </button>
                <div id="notifMenu" class="card notif-menu shadow" style="display:none;">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
                        <span class="fw-semibold">Notifications</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-link text-decoration-none" id="notifMarkRead">Mark as read</button>
                            <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none" id="notifDelete">Delete</button>
                        </div>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if (empty($notif_items)): ?>
                            <div class="list-group-item small text-muted">No new notifications</div>
                        <?php else: ?>
                            <?php foreach ($notif_items as $it): ?>
                                <a class="list-group-item list-group-item-action" href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>">
                                    <div class="d-flex gap-2">
                                        <div class="text-muted"><i class="bi <?php echo htmlspecialchars($it['icon'] ?? 'bi-bell'); ?>"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($it['title'] ?? ''); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="nav flex-column">
        <?php if ($role === 'super_admin'): ?>
            <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="../admin/dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a class="nav-link <?php echo $active_page === 'users' ? 'active' : ''; ?>" href="../admin/users.php">
                <i class="bi bi-people"></i> All Users
            </a>
            <a class="nav-link <?php echo $active_page === 'instructors' ? 'active' : ''; ?>" href="../admin/instructors.php">
                <i class="bi bi-person-badge"></i> Instructors
            </a>
            <a class="nav-link <?php echo $active_page === 'students' ? 'active' : ''; ?>" href="../admin/students.php">
                <i class="bi bi-mortarboard"></i> Students
            </a>
            <a class="nav-link <?php echo $active_page === 'settings' ? 'active' : ''; ?>" href="../admin/settings.php">
                <i class="bi bi-gear"></i> Settings
            </a>
        <?php elseif (in_array($role, ['admin', 'registrar'], true)): ?>
            <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="../registrar/dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a class="nav-link <?php echo $active_page === 'classes' ? 'active' : ''; ?>" href="../registrar/classes.php">
                <i class="bi bi-calendar3"></i> Class Schedules
            </a>
            <a class="nav-link <?php echo $active_page === 'timeslots' ? 'active' : ''; ?>" href="../registrar/manage_timeslots.php">
                <i class="bi bi-clock"></i> Time Slots
            </a>
            <a class="nav-link <?php echo $active_page === 'excluded_days' ? 'active' : ''; ?>" href="../registrar/manage_excluded_days.php">
                <i class="bi bi-calendar-x"></i> Excluded Days
            </a>
            <a class="nav-link <?php echo $active_page === 'enrollments' ? 'active' : ''; ?>" href="../registrar/enrollments.php">
                <i class="bi bi-person-check"></i> Enrollments
            </a>
        <?php elseif ($role === 'instructor'): ?>
            <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="../instructor/dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a class="nav-link <?php echo $active_page === 'schedule' ? 'active' : ''; ?>" href="../instructor/my_schedule.php">
                <i class="bi bi-calendar3"></i> My Schedule
            </a>
            <a class="nav-link <?php echo $active_page === 'classes' ? 'active' : ''; ?>" href="../instructor/my_classes.php">
                <i class="bi bi-journal-text"></i> My Classes
            </a>
            <a class="nav-link <?php echo $active_page === 'profile' ? 'active' : ''; ?>" href="../instructor/profile.php">
                <i class="bi bi-person"></i> Update Profile
            </a>
        <?php endif; ?>
        <a class="nav-link" href="../auth/logout.php">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>
</div>

<style>
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: 250px;
    background-color: var(--primary);
    padding: 1rem;
    color: white;
    z-index: 1000;
}

.sidebar .nav-link {
    color: rgba(255, 255, 255, 0.85);
    padding: 0.8rem 1rem;
    border-radius: 5px;
    margin-bottom: 0.5rem;
    transition: all 0.3s ease;
}

.sidebar .nav-link:hover {
    color: white;
    background-color: rgba(255, 255, 255, 0.1);
}

.sidebar .nav-link.active {
    color: white;
    background-color: rgba(255, 255, 255, 0.2);
}

.sidebar .nav-link i {
    margin-right: 0.5rem;
}

.main-content {
    margin-left: 250px;
    padding: 2rem;
}

.notif-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    width: 320px;
    z-index: 1200;
}
</style> 

<?php if ($role === 'instructor'): ?>
<script>
(function () {
    const btn = document.getElementById('notifBtn');
    const menu = document.getElementById('notifMenu');
    const markRead = document.getElementById('notifMarkRead');
    const delBtn = document.getElementById('notifDelete');

    if (!btn || !menu) return;

    function closeMenu() {
        menu.style.display = 'none';
    }

    function toggleMenu() {
        menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleMenu();
    });

    document.addEventListener('click', function () {
        closeMenu();
    });

    menu.addEventListener('click', function (e) {
        e.stopPropagation();
    });

    async function postAction(action) {
        const res = await fetch('notifications_seen.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action })
        });
        return res.ok;
    }

    if (markRead) {
        markRead.addEventListener('click', async function (e) {
            e.preventDefault();
            await postAction('seen');
            window.location.reload();
        });
    }

    if (delBtn) {
        delBtn.addEventListener('click', async function (e) {
            e.preventDefault();
            await postAction('delete');
            window.location.reload();
        });
    }
})();
</script>
<?php endif; ?>