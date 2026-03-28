<?php
if (!isset($active_page)) {
    $active_page = '';
}
?>
<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container-fluid">
        <button class="btn btn-link text-light d-lg-none me-2" type="button" onclick="toggleSidebar()">
            <i class="bi bi-list fs-4"></i>
        </button>
        <a class="navbar-brand" href="dashboard.php">
            <img src="../pctlogo.png" alt="PCT Logo" height="30" class="d-inline-block align-text-top">
            PCT Admin
        </a>
        <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle"></i> <?php echo $_SESSION['name'] ?? 'User'; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                </ul>
            </li>
        </ul>
    </div>
</nav>

<!-- Sidebar -->
<div class="sidebar">
    <div class="d-flex flex-column h-100">
        <div class="nav flex-column">
            <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_page === 'classes' ? 'active' : ''; ?>" href="classes.php">
                    <i class="bi bi-calendar3"></i> Class Schedules
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_page === 'time_slots' ? 'active' : ''; ?>" href="manage_time_slots.php">
                    <i class="bi bi-clock"></i> Time Slots
                </a>
            </li>
            <a class="nav-link <?php echo $active_page === 'students' ? 'active' : ''; ?>" href="students.php">
                <i class="bi bi-mortarboard"></i> Students
            </a>
            <a class="nav-link <?php echo $active_page === 'enrollments' ? 'active' : ''; ?>" href="manage_enrollments.php">
                <i class="bi bi-person-plus"></i> Enrollments
            </a>
            <a class="nav-link <?php echo $active_page === 'instructors' ? 'active' : ''; ?>" href="instructors.php">
                <i class="bi bi-person-video3"></i> Instructors
            </a>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_page === 'rooms' ? 'active' : ''; ?>" href="rooms.php">
                    <i class="bi bi-door-open"></i> Rooms
                </a>
            </li>
            <a class="nav-link <?php echo $active_page === 'reports' ? 'active' : ''; ?>" href="reports.php">
                <i class="bi bi-file-earmark-text"></i> Reports
            </a>
        </div>
        
        <div class="mt-auto p-3">
            <div class="text-muted small">
                <div>Philippine College of Technology</div>
                <div>Registrar's Portal</div>
            </div>
        </div>
    </div>
</div>

<!-- Common CSS -->
<style>
:root {
    --primary: #2c5d3f;
    --secondary: #7bc26f;
    --sidebar-width: 250px;
    --navbar-height: 60px;
}

body {
    font-family: 'Inter', sans-serif;
    background-color: #f8f9fa;
    padding-top: var(--navbar-height);
}

.navbar {
    background-color: var(--primary);
    height: var(--navbar-height);
    padding: 0 1rem;
    position: fixed;
    top: 0;
    right: 0;
    left: 0;
    z-index: 1030;
}

.sidebar {
    position: fixed;
    top: var(--navbar-height);
    bottom: 0;
    left: 0;
    width: var(--sidebar-width);
    padding: 1rem 0;
    background-color: white;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    z-index: 1020;
    transition: all 0.3s ease-in-out;
}

.main-content {
    margin-left: var(--sidebar-width);
    padding: 2rem;
    transition: all 0.3s ease-in-out;
}

.sidebar .nav-link {
    color: #333;
    padding: 0.8rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.sidebar .nav-link:hover {
    background-color: rgba(0, 0, 0, 0.05);
    color: var(--primary);
}

.sidebar .nav-link.active {
    background-color: var(--primary);
    color: white;
}

.sidebar .nav-link i {
    font-size: 1.1rem;
    width: 1.5rem;
    text-align: center;
}

@media (max-width: 992px) {
    .sidebar {
        margin-left: calc(var(--sidebar-width) * -1);
    }
    
    .sidebar.show {
        margin-left: 0;
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .main-content.sidebar-shown {
        margin-left: var(--sidebar-width);
    }
}

/* Card styles */
.card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.card-header {
    background-color: var(--primary);
    color: white;
    border-radius: 10px 10px 0 0 !important;
    padding: 1rem 1.25rem;
}

.btn-primary {
    background-color: var(--primary);
    border-color: var(--primary);
}

.btn-primary:hover {
    background-color: #234a2a;
    border-color: #234a2a;
}

.btn-secondary {
    background-color: var(--secondary);
    border-color: var(--secondary);
}

.btn-secondary:hover {
    background-color: #69b15e;
    border-color: #69b15e;
}
</style>

<!-- Common JavaScript -->
<script>
function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('show');
    document.querySelector('.main-content').classList.toggle('sidebar-shown');
}

// Close sidebar on click outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.querySelector('.btn-link');
    
    if (window.innerWidth < 992 && 
        !event.target.closest('.sidebar') && 
        !event.target.closest('.btn-link') && 
        sidebar.classList.contains('show')) {
        sidebar.classList.remove('show');
        document.querySelector('.main-content').classList.remove('sidebar-shown');
    }
});
</script> 