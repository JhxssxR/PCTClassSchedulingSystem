<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

function subject_filter_key(string $value): string {
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    return trim($normalized, '-');
}

function subject_year_label(int $year): string {
    if ($year === 1) {
        return '1st Year';
    }
    if ($year === 2) {
        return '2nd Year';
    }
    if ($year === 3) {
        return '3rd Year';
    }
    return $year . 'th Year';
}

$default_department = 'Information Technology Education';

$subject_sections = [
    [
        'id' => 'y1s1',
        'year' => 1,
        'semester' => '1st Semester',
        'gradient' => 'from-blue-600 to-cyan-500',
        'items' => [
            ['code' => 'GE1', 'name' => 'Understanding the Self', 'type' => 'GE', 'units' => 3],
            ['code' => 'GE2', 'name' => 'Reading Philippine History', 'type' => 'GE', 'units' => 3],
            ['code' => 'GE3', 'name' => 'Contemporary World', 'type' => 'GE', 'units' => 3],
            ['code' => 'CC101', 'name' => 'Introduction to Computing', 'type' => 'Core', 'units' => 3],
            ['code' => 'CC102', 'name' => 'Fundamentals of Programming 1', 'type' => 'Core', 'units' => 3],
            ['code' => 'NSTP 1', 'name' => 'National Service Training Program', 'type' => 'NSTP', 'units' => 3],
            ['code' => 'PATHFit1', 'name' => 'Movement Competency Training', 'type' => 'PE', 'units' => 2],
        ],
    ],
    [
        'id' => 'y1s2',
        'year' => 1,
        'semester' => '2nd Semester',
        'gradient' => 'from-blue-600 to-cyan-500',
        'items' => [
            ['code' => 'GE4', 'name' => 'Mathematics in the Modern World', 'type' => 'GE', 'units' => 3],
            ['code' => 'GE5', 'name' => 'Purposive Communication', 'type' => 'GE', 'units' => 3],
            ['code' => 'GE6', 'name' => 'Art Appreciation', 'type' => 'GE', 'units' => 3],
            ['code' => 'CC103', 'name' => 'Intermediate Programming', 'type' => 'Core', 'units' => 3],
            ['code' => 'MS101', 'name' => 'Discrete Mathematics', 'type' => 'Core', 'units' => 3],
            ['code' => 'HCI101', 'name' => 'Human Computer Interaction', 'type' => 'Core', 'units' => 3],
            ['code' => 'NSTP 2', 'name' => 'National Service Training Program', 'type' => 'NSTP', 'units' => 3],
            ['code' => 'PATHFit2', 'name' => 'Exercise-based Fitness Activities', 'type' => 'PE', 'units' => 2],
        ],
    ],
    [
        'id' => 'y2s1',
        'year' => 2,
        'semester' => '1st Semester',
        'gradient' => 'from-emerald-600 to-teal-500',
        'items' => [
            ['code' => 'GE7', 'name' => 'Science Technology and Society', 'type' => 'GE', 'units' => 3],
            ['code' => 'CC104', 'name' => 'Data Structure & Algorithms', 'type' => 'Core', 'units' => 3],
            ['code' => 'IPT101', 'name' => 'Integrative Programming Technologies', 'type' => 'Core', 'units' => 3],
            ['code' => 'MS102', 'name' => 'Quantitative Methods (inc. Modeling/Sim)', 'type' => 'Core', 'units' => 3],
            ['code' => 'IOT', 'name' => 'Internet of Things', 'type' => 'Core', 'units' => 3],
            ['code' => 'ML', 'name' => 'Machine Learning', 'type' => 'Core', 'units' => 3],
            ['code' => 'Elec 1', 'name' => 'Elective 1 (Integrative Programming Tech 2)', 'type' => 'Elective', 'units' => 3],
            ['code' => 'PATHFit3', 'name' => 'Martial Arts', 'type' => 'PE', 'units' => 2],
        ],
    ],
    [
        'id' => 'y2s2',
        'year' => 2,
        'semester' => '2nd Semester',
        'gradient' => 'from-emerald-600 to-cyan-500',
        'items' => [
            ['code' => 'GE8', 'name' => 'Ethics', 'type' => 'GE', 'units' => 3],
            ['code' => 'CC105', 'name' => 'Information Management', 'type' => 'Core', 'units' => 3],
            ['code' => 'IAS101', 'name' => 'Information Assurance & Security', 'type' => 'Core', 'units' => 3],
            ['code' => 'NET101', 'name' => 'Networking', 'type' => 'Core', 'units' => 3],
            ['code' => 'ID', 'name' => 'Introduction to Data Science', 'type' => 'Core', 'units' => 3],
            ['code' => 'AMP', 'name' => 'Advance Mobile Programming', 'type' => 'Core', 'units' => 3],
            ['code' => 'Elec 2', 'name' => 'Elective 2 (Platform Technologies 1)', 'type' => 'Elective', 'units' => 3],
            ['code' => 'PATHFit4', 'name' => 'Group Exercises, Aerobics, Yoga', 'type' => 'PE', 'units' => 2],
        ],
    ],
    [
        'id' => 'y3s1',
        'year' => 3,
        'semester' => '1st Semester',
        'gradient' => 'from-violet-600 to-fuchsia-500',
        'items' => [
            ['code' => 'GE9', 'name' => 'Life and Works of Rizal', 'type' => 'GE', 'units' => 3],
            ['code' => 'GE10', 'name' => 'Living in the IT Era', 'type' => 'GE', 'units' => 3],
            ['code' => 'NET102', 'name' => 'Networking', 'type' => 'Core', 'units' => 3],
            ['code' => 'SIA101', 'name' => 'System Integration and Architecture', 'type' => 'Core', 'units' => 3],
            ['code' => 'IM101', 'name' => 'Fundamentals of Database Systems', 'type' => 'Core', 'units' => 3],
            ['code' => 'Elec 3', 'name' => 'Elective 3 (Web System Technologies)', 'type' => 'Elective', 'units' => 3],
        ],
    ],
    [
        'id' => 'y3s2',
        'year' => 3,
        'semester' => '2nd Semester',
        'gradient' => 'from-purple-600 to-pink-500',
        'items' => [
            ['code' => 'GE11', 'name' => 'The Entrepreneurial Mind', 'type' => 'GE', 'units' => 3],
            ['code' => 'GE12', 'name' => 'Gender and Society', 'type' => 'GE', 'units' => 3],
            ['code' => 'CC106', 'name' => 'App Development & Emerging Tech', 'type' => 'Core', 'units' => 3],
            ['code' => 'SP101', 'name' => 'Social Professional Issues', 'type' => 'Core', 'units' => 3],
            ['code' => 'FC', 'name' => 'Fundamentals of Cybersecurity', 'type' => 'Core', 'units' => 3],
            ['code' => 'CC', 'name' => 'Cloud Computing', 'type' => 'Core', 'units' => 3],
            ['code' => 'Elec 4', 'name' => 'Elective 4 (System Integration & Architecture 2)', 'type' => 'Elective', 'units' => 3],
        ],
    ],
    [
        'id' => 'y4sum',
        'year' => 4,
        'semester' => 'Summer Class',
        'gradient' => 'from-amber-500 to-orange-500',
        'items' => [
            ['code' => 'CAP101', 'name' => 'Capstone Project 1 (Research)', 'type' => 'Capstone', 'units' => 3],
            ['code' => 'IAS102', 'name' => 'Information Assurance & Security', 'type' => 'Core', 'units' => 3],
        ],
    ],
    [
        'id' => 'y4s1',
        'year' => 4,
        'semester' => '1st Semester',
        'gradient' => 'from-indigo-600 to-blue-500',
        'items' => [
            ['code' => 'CAP102', 'name' => 'Capstone Project 2 (Research)', 'type' => 'Capstone', 'units' => 3],
            ['code' => 'SA101', 'name' => 'System Administration & Maintenance', 'type' => 'Core', 'units' => 3],
        ],
    ],
];

foreach ($subject_sections as &$section_seed_ref) {
    if (!isset($section_seed_ref['department']) || trim((string)$section_seed_ref['department']) === '') {
        $section_seed_ref['department'] = $default_department;
    }
}
unset($section_seed_ref);

$default_subject_map = [];
$default_subject_key_map = [];
foreach ($subject_sections as $section_seed) {
    foreach (($section_seed['items'] ?? []) as $item_seed) {
        $seed_code = trim((string)($item_seed['code'] ?? ''));
        if ($seed_code === '') {
            continue;
        }
        $seed_department = trim((string)($section_seed['department'] ?? $default_department));
        if ($seed_department === '') {
            $seed_department = $default_department;
        }
        $seed_code_key = strtoupper($seed_code);
        $default_subject_map[$seed_code_key] = [
            'code' => $seed_code,
            'name' => trim((string)($item_seed['name'] ?? '')),
            'year_level' => (int)($section_seed['year'] ?? 0),
            'semester' => trim((string)($section_seed['semester'] ?? '1st Semester')),
            'subject_type' => trim((string)($item_seed['type'] ?? 'Core')),
            'department' => $seed_department,
            'units' => max(1, (int)($item_seed['units'] ?? 3)),
        ];
        $default_subject_key_map[$seed_code_key . '|' . strtolower($seed_department)] = true;
    }
}

// Schema compatibility for subject unit management.
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject_code VARCHAR(50) NOT NULL UNIQUE,
        subject_name VARCHAR(255) NOT NULL,
        year_level TINYINT NULL,
        units INT NOT NULL DEFAULT 3,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    error_log('Could not ensure subjects table (admin subjects): ' . $e->getMessage());
}

try {
    $conn->exec("ALTER TABLE subjects ADD COLUMN year_level TINYINT NULL AFTER subject_name");
} catch (Throwable $e) {
    // ignore if column already exists
}
try {
    $conn->exec("ALTER TABLE subjects ADD COLUMN units INT NOT NULL DEFAULT 3 AFTER year_level");
} catch (Throwable $e) {
    // ignore if column already exists
}

try {
    $conn->exec("ALTER TABLE subjects ADD COLUMN semester VARCHAR(30) NULL AFTER year_level");
} catch (Throwable $e) {
    // ignore if column already exists
}
try {
    $conn->exec("ALTER TABLE subjects ADD COLUMN subject_type VARCHAR(30) NULL AFTER semester");
} catch (Throwable $e) {
    // ignore if column already exists
}
try {
    $conn->exec("ALTER TABLE subjects ADD COLUMN department VARCHAR(120) NULL AFTER subject_type");
} catch (Throwable $e) {
    // ignore if column already exists
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_subject') {
    try {
        $subject_code = strtoupper(trim((string)($_POST['subject_code'] ?? '')));
        $subject_name = trim((string)($_POST['subject_name'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $year_level = (int)($_POST['year_level'] ?? 0);
        $semester = trim((string)($_POST['semester'] ?? ''));
        $subject_type = trim((string)($_POST['subject_type'] ?? ''));
        $units = (int)($_POST['units'] ?? 0);

        if ($subject_code === '' || $subject_name === '') {
            throw new Exception('Subject code and name are required.');
        }
        if ($department === '') {
            throw new Exception('Department is required.');
        }
        if ($year_level < 1 || $year_level > 4) {
            throw new Exception('Year level must be between 1 and 4.');
        }

        $allowed_semesters = ['1st Semester', '2nd Semester', 'Summer Class'];
        if (!in_array($semester, $allowed_semesters, true)) {
            throw new Exception('Please select a valid semester.');
        }

        $allowed_types = ['GE', 'Core', 'Elective', 'NSTP', 'PE', 'Capstone'];
        if (!in_array($subject_type, $allowed_types, true)) {
            throw new Exception('Please select a valid subject type.');
        }

        if ($units < 1 || $units > 10) {
            throw new Exception('Units must be between 1 and 10.');
        }

        $check_stmt = $conn->prepare("SELECT id FROM subjects WHERE UPPER(subject_code) = :code AND LOWER(COALESCE(department, '')) = LOWER(:department) LIMIT 1");
        $check_stmt->execute([
            'code' => $subject_code,
            'department' => $department,
        ]);

        if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('This subject code already exists for the selected department.');
        }

        $insert_stmt = $conn->prepare('INSERT INTO subjects (subject_code, subject_name, year_level, semester, subject_type, department, units) VALUES (:code, :name, :year_level, :semester, :subject_type, :department, :units)');
        $insert_stmt->execute([
            'code' => $subject_code,
            'name' => $subject_name,
            'year_level' => $year_level,
            'semester' => $semester,
            'subject_type' => $subject_type,
            'department' => $department,
            'units' => $units,
        ]);

        $_SESSION['success'] = 'Subject added successfully.';
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: subjects.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_subject') {
    try {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        if ($subject_id <= 0) {
            throw new Exception('Invalid subject selected for deletion.');
        }

        $has_schedule_subject_id = false;
        try {
            $cols_stmt = $conn->query('DESCRIBE schedules');
            foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $col_row) {
                if (($col_row['Field'] ?? '') === 'subject_id') {
                    $has_schedule_subject_id = true;
                    break;
                }
            }
        } catch (Throwable $e) {
            $has_schedule_subject_id = false;
        }

        if ($has_schedule_subject_id) {
            $sched_stmt = $conn->prepare('SELECT COUNT(*) FROM schedules WHERE subject_id = :subject_id');
            $sched_stmt->execute(['subject_id' => $subject_id]);
            if ((int)$sched_stmt->fetchColumn() > 0) {
                throw new Exception('Subject cannot be deleted because it is already used in schedules.');
            }
        }

        $delete_stmt = $conn->prepare('DELETE FROM subjects WHERE id = :id');
        $delete_stmt->execute(['id' => $subject_id]);

        if ($delete_stmt->rowCount() === 0) {
            throw new Exception('Subject not found or already removed.');
        }

        $_SESSION['success'] = 'Subject deleted successfully.';
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: subjects.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_subject_units') {
    try {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $units = (int)($_POST['units'] ?? 0);

        if ($subject_id <= 0) {
            throw new Exception('Please select a valid subject.');
        }
        if ($units < 1 || $units > 10) {
            throw new Exception('Units must be between 1 and 10.');
        }

        $stmt = $conn->prepare('UPDATE subjects SET units = :units WHERE id = :id');
        $stmt->execute([
            'units' => $units,
            'id' => $subject_id,
        ]);

        if ($stmt->rowCount() === 0) {
            $check_stmt = $conn->prepare('SELECT id FROM subjects WHERE id = :id LIMIT 1');
            $check_stmt->execute(['id' => $subject_id]);
            if (!$check_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Selected subject was not found.');
            }
        }

        $_SESSION['success'] = 'Subject units updated successfully.';
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: subjects.php');
    exit();
}

// Ensure DB has all reference subjects while preserving manually changed units.
try {
    $find_stmt = $conn->prepare("SELECT id, units, semester, subject_type, department FROM subjects WHERE UPPER(subject_code) = :code AND LOWER(COALESCE(department, '')) = LOWER(:department) LIMIT 1");
    $insert_stmt = $conn->prepare('INSERT INTO subjects (subject_code, subject_name, year_level, semester, subject_type, department, units) VALUES (:code, :name, :year_level, :semester, :subject_type, :department, :units)');
    $update_stmt = $conn->prepare("UPDATE subjects SET subject_name = :name, year_level = COALESCE(year_level, :year_level), semester = COALESCE(NULLIF(semester, ''), :semester), subject_type = COALESCE(NULLIF(subject_type, ''), :subject_type), department = COALESCE(NULLIF(department, ''), :department), units = COALESCE(NULLIF(units, 0), :units) WHERE id = :id");

    foreach ($default_subject_map as $seed_meta) {
        $find_stmt->execute([
            'code' => strtoupper((string)$seed_meta['code']),
            'department' => (string)($seed_meta['department'] ?? $default_department),
        ]);
        $existing_subject = $find_stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_subject) {
            $update_stmt->execute([
                'id' => (int)$existing_subject['id'],
                'name' => $seed_meta['name'],
                'year_level' => $seed_meta['year_level'] > 0 ? $seed_meta['year_level'] : null,
                'semester' => $seed_meta['semester'],
                'subject_type' => $seed_meta['subject_type'],
                'department' => $seed_meta['department'] !== '' ? $seed_meta['department'] : $default_department,
                'units' => $seed_meta['units'],
            ]);
            continue;
        }

        $insert_stmt->execute([
            'code' => $seed_meta['code'],
            'name' => $seed_meta['name'],
            'year_level' => $seed_meta['year_level'] > 0 ? $seed_meta['year_level'] : null,
            'semester' => $seed_meta['semester'],
            'subject_type' => $seed_meta['subject_type'],
            'department' => $seed_meta['department'] !== '' ? $seed_meta['department'] : $default_department,
            'units' => $seed_meta['units'],
        ]);
    }
} catch (Throwable $e) {
    error_log('Subject seed sync warning (admin subjects): ' . $e->getMessage());
}

$db_subject_meta_by_key = [];
$all_subject_options = [];
try {
    $subjects_stmt = $conn->query("SELECT id, subject_code, subject_name, year_level, COALESCE(NULLIF(semester, ''), '1st Semester') AS semester, COALESCE(NULLIF(subject_type, ''), 'Core') AS subject_type, COALESCE(NULLIF(department, ''), '') AS department, COALESCE(NULLIF(units, 0), 3) AS units FROM subjects ORDER BY department, year_level, semester, subject_code, subject_name");
    $all_subject_options = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_subject_options as $subject_row) {
        $subject_code_key = strtoupper(trim((string)($subject_row['subject_code'] ?? '')));
        if ($subject_code_key === '') {
            continue;
        }
        $row_department = trim((string)($subject_row['department'] ?? ''));
        if ($row_department === '') {
            $row_department = $default_department;
        }
        $db_subject_meta_by_key[$subject_code_key . '|' . strtolower($row_department)] = [
            'id' => (int)($subject_row['id'] ?? 0),
            'units' => (int)($subject_row['units'] ?? 3),
            'subject_name' => trim((string)($subject_row['subject_name'] ?? '')),
            'semester' => trim((string)($subject_row['semester'] ?? '')),
            'subject_type' => trim((string)($subject_row['subject_type'] ?? '')),
            'department' => $row_department,
        ];
    }
} catch (Throwable $e) {
    error_log('Could not load subjects with units (admin subjects): ' . $e->getMessage());
}

foreach ($subject_sections as &$section_ref) {
    if (!isset($section_ref['department']) || trim((string)$section_ref['department']) === '') {
        $section_ref['department'] = $default_department;
    }
}
unset($section_ref);

foreach ($subject_sections as &$section_ref) {
    $section_department = trim((string)($section_ref['department'] ?? $default_department));
    if ($section_department === '') {
        $section_department = $default_department;
    }
    $section_ref['department'] = $section_department;

    foreach ($section_ref['items'] as &$item_ref) {
        $item_code_key = strtoupper(trim((string)($item_ref['code'] ?? '')));
        $item_ref['subject_id'] = 0;
        $meta_key = $item_code_key . '|' . strtolower($section_department);
        if ($item_code_key !== '' && isset($db_subject_meta_by_key[$meta_key])) {
            $meta = $db_subject_meta_by_key[$meta_key];
            $item_ref['subject_id'] = (int)($meta['id'] ?? 0);
            $item_ref['units'] = (int)$meta['units'];
            if (($meta['subject_name'] ?? '') !== '') {
                $item_ref['name'] = $meta['subject_name'];
            }
            if (($meta['subject_type'] ?? '') !== '') {
                $item_ref['type'] = $meta['subject_type'];
            }
        }
    }
    unset($item_ref);
}
unset($section_ref);

$dynamic_gradients = ['from-slate-700 to-slate-500', 'from-cyan-600 to-blue-500', 'from-teal-600 to-emerald-500', 'from-fuchsia-600 to-violet-500', 'from-rose-600 to-pink-500'];
$section_lookup = [];
foreach ($subject_sections as $idx => $section_ref) {
    $lookup_key = subject_filter_key((string)($section_ref['department'] ?? $default_department)) . '|' . (int)($section_ref['year'] ?? 1) . '|' . subject_filter_key((string)($section_ref['semester'] ?? '1st Semester'));
    $section_lookup[$lookup_key] = $idx;
}

foreach ($all_subject_options as $subject_row) {
    $subject_code = trim((string)($subject_row['subject_code'] ?? ''));
    $subject_code_key = strtoupper($subject_code);
    $subject_id = (int)($subject_row['id'] ?? 0);
    if ($subject_code_key === '') {
        continue;
    }

    $year_level = (int)($subject_row['year_level'] ?? 1);
    if ($year_level < 1 || $year_level > 4) {
        $year_level = 1;
    }

    $semester = trim((string)($subject_row['semester'] ?? ''));
    if ($semester === '') {
        $semester = '1st Semester';
    }

    $department = trim((string)($subject_row['department'] ?? ''));
    if ($department === '') {
        $department = $default_department;
    }

    $type = trim((string)($subject_row['subject_type'] ?? ''));
    if ($type === '') {
        $type = 'Core';
    }

    if (isset($default_subject_key_map[$subject_code_key . '|' . strtolower($department)])) {
        continue;
    }

    $section_key = subject_filter_key($department) . '|' . $year_level . '|' . subject_filter_key($semester);
    if (!isset($section_lookup[$section_key])) {
        $new_section_index = count($subject_sections);
        $subject_sections[] = [
            'id' => 'dept_' . subject_filter_key($department) . '_y' . $year_level . '_' . subject_filter_key($semester),
            'year' => $year_level,
            'semester' => $semester,
            'department' => $department,
            'gradient' => $dynamic_gradients[$new_section_index % count($dynamic_gradients)],
            'items' => [],
        ];
        $section_lookup[$section_key] = $new_section_index;
    }

    $subject_sections[$section_lookup[$section_key]]['items'][] = [
        'subject_id' => $subject_id,
        'code' => $subject_code,
        'name' => trim((string)($subject_row['subject_name'] ?? '')),
        'type' => $type,
        'units' => max(1, (int)($subject_row['units'] ?? 3)),
    ];
}

$category_order = ['GE', 'Core', 'Elective', 'NSTP', 'PE', 'Capstone'];
$category_meta = [
    'GE' => ['dot' => 'bg-blue-500', 'pill' => 'bg-sky-100 text-sky-700', 'chip_active' => 'border-blue-300 bg-blue-50 text-blue-700'],
    'Core' => ['dot' => 'bg-emerald-500', 'pill' => 'bg-emerald-100 text-emerald-700', 'chip_active' => 'border-emerald-300 bg-emerald-50 text-emerald-700'],
    'Elective' => ['dot' => 'bg-violet-500', 'pill' => 'bg-violet-100 text-violet-700', 'chip_active' => 'border-violet-300 bg-violet-50 text-violet-700'],
    'NSTP' => ['dot' => 'bg-teal-500', 'pill' => 'bg-teal-100 text-teal-700', 'chip_active' => 'border-teal-300 bg-teal-50 text-teal-700'],
    'PE' => ['dot' => 'bg-amber-500', 'pill' => 'bg-amber-100 text-amber-700', 'chip_active' => 'border-amber-300 bg-amber-50 text-amber-700'],
    'Capstone' => ['dot' => 'bg-rose-500', 'pill' => 'bg-rose-100 text-rose-700', 'chip_active' => 'border-rose-300 bg-rose-50 text-rose-700'],
];

$total_subjects = 0;
$total_units = 0;
$category_counts = array_fill_keys($category_order, 0);
$department_counts = [];

foreach ($subject_sections as &$section) {
    $section_department = trim((string)($section['department'] ?? ''));
    if ($section_department === '') {
        $section_department = $default_department;
    }
    $section['department'] = $section_department;

    $section_units = 0;
    $section_types = [];
    $search_parts = ['Year ' . (int)$section['year'], (string)$section['semester'], $section_department];

    foreach ($section['items'] as $item) {
        $units = (int)($item['units'] ?? 0);
        $type = (string)($item['type'] ?? 'Core');
        $section_units += $units;
        $section_types[$type] = true;
        if (isset($category_counts[$type])) {
            $category_counts[$type] += 1;
        }
        $search_parts[] = (string)($item['code'] ?? '');
        $search_parts[] = (string)($item['name'] ?? '');
    }

    $ordered_types = [];
    foreach ($category_order as $cat) {
        if (isset($section_types[$cat])) {
            $ordered_types[] = $cat;
        }
    }

    $section['subject_count'] = count($section['items']);
    $section['unit_count'] = $section_units;
    $section['type_list'] = $ordered_types;
    $section['title'] = subject_year_label((int)$section['year']) . ' • ' . (string)$section['semester'];
    $section['department_key'] = subject_filter_key($section_department);
    $section['search_blob'] = strtolower(implode(' ', $search_parts));

    $total_subjects += $section['subject_count'];
    $total_units += $section_units;

    if (!isset($department_counts[$section_department])) {
        $department_counts[$section_department] = 0;
    }
    $department_counts[$section_department] += $section['subject_count'];
}
unset($section);

ksort($department_counts, SORT_NATURAL | SORT_FLAG_CASE);

$core_subjects = (int)($category_counts['Core'] ?? 0);
$ge_subjects = (int)($category_counts['GE'] ?? 0);

$user_initials = 'SA';
$full_name = 'Super Admin';
if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
    $full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $first = strtoupper(substr((string)($_SESSION['first_name'] ?? ''), 0, 1));
    $last = strtoupper(substr((string)($_SESSION['last_name'] ?? ''), 0, 1));
    $user_initials = trim($first . $last);
    if ($user_initials === '') {
        $user_initials = 'SA';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full lg:translate-x-0 transition-transform bg-emerald-950 text-emerald-50 border-r border-emerald-900/40">
            <div class="h-16 px-6 flex items-center gap-3 border-b border-emerald-900/40">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-9 w-9 rounded-full bg-emerald-50/10 object-contain" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">PCT Super Admin</div>
                    <div class="text-xs text-emerald-100/70">Management Portal</div>
                </div>
            </div>

            <div class="px-4 py-4">
                <div class="text-[11px] tracking-widest text-emerald-100/60 px-3 mb-2">NAVIGATION</div>
                <nav class="space-y-1">
                    <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-speedometer2"></i>
                        <span class="text-sm font-medium">Dashboard</span>
                    </a>
                    <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-people"></i>
                        <span class="text-sm font-medium">All Users</span>
                    </a>
                    <a href="instructors.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-person-video3"></i>
                        <span class="text-sm font-medium">Instructors</span>
                    </a>
                    <a href="students.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-mortarboard"></i>
                        <span class="text-sm font-medium">Students</span>
                    </a>
                    <a href="classes.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-book"></i>
                        <span class="text-sm font-medium">Classes</span>
                    </a>
                    <a href="classrooms.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-door-open"></i>
                        <span class="text-sm font-medium">Classrooms</span>
                    </a>
                    <a href="subjects.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50">
                        <i class="bi bi-journal-bookmark"></i>
                        <span class="text-sm font-medium">Subjects</span>
                    </a>
                    <a href="courses.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-journal-text"></i>
                        <span class="text-sm font-medium">Courses</span>
                    </a>
                    <a href="schedules.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-calendar3"></i>
                        <span class="text-sm font-medium">Schedules</span>
                    </a>
                    <a href="enrollments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-person-plus"></i>
                        <span class="text-sm font-medium">Enrollments</span>
                    </a>
                    <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-file-earmark-text"></i>
                        <span class="text-sm font-medium">Reports</span>
                    </a>
                    <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-gear"></i>
                        <span class="text-sm font-medium">Settings</span>
                    </a>
                    <a href="../activity.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-clock-history"></i>
                        <span class="text-sm font-medium">Activity</span>
                    </a>
                </nav>
            </div>

            <div class="absolute bottom-0 left-0 right-0 p-4">
                <a href="../auth/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/15 border border-transparent hover:border-rose-400/20">
                    <i class="bi bi-box-arrow-right text-rose-300"></i>
                    <span class="text-sm font-semibold">Logout</span>
                </a>
            </div>
        </aside>

        <!-- Overlay (mobile) -->
        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

        <!-- Main -->
        <div id="contentWrap" class="min-h-screen transition-all duration-300">
            <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50" aria-label="Toggle sidebar">
                            <i class="bi bi-list text-xl"></i>
                        </button>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-slate-500">Super Admin</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-900">Subjects</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="relative hidden sm:block">
                            <button id="notifBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50" aria-label="Notifications" aria-haspopup="menu" aria-expanded="false" aria-controls="notifMenu">
                                <span class="relative">
                                    <i class="bi bi-bell text-lg text-slate-700"></i>
                                    <span id="notifDot" class="absolute -right-1 -top-1 min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white <?php echo (($notif_unread_total ?? 0) > 0) ? '' : 'hidden'; ?>"><?php echo htmlspecialchars($notif_badge_label ?? ''); ?></span>
                                </span>
                            </button>

                            <div id="notifMenu" class="absolute right-0 mt-2 w-80 hidden" role="menu" aria-label="Notifications">
                                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                                    <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900">Notifications</div>
                                            <div class="text-xs text-slate-500">Updates and reminders</div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button id="notifMarkRead" type="button" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700">Mark as read</button>
                                            <button id="notifDelete" type="button" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Delete</button>
                                        </div>
                                    </div>
                                    <div class="p-3">
                                        <?php if (empty($notif_items)): ?>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">No new notifications.</div>
                                        <?php else: ?>
                                            <div class="space-y-2">
                                                <?php foreach ($notif_items as $it): ?>
                                                    <a href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                                                <i class="bi <?php echo htmlspecialchars($it['icon'] ?? 'bi-bell'); ?>"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($it['title'] ?? 'Notification'); ?></div>
                                                                <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-3 text-xs text-slate-500">
                                            <?php
                                                $ni = (int)($notif_new_instructors ?? 0);
                                                $ne = (int)($notif_new_enrollments ?? 0);
                                                echo htmlspecialchars($ni . ' new instructor(s), ' . $ne . ' new enrollment(s) since last check.');
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:flex items-center gap-3">
                            <div class="h-10 w-10 rounded-full bg-emerald-600 text-white flex items-center justify-center font-semibold">
                                <?php echo htmlspecialchars($user_initials); ?>
                            </div>
                            <div class="text-left">
                                <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($full_name); ?></div>
                                <div class="text-xs text-slate-500">PCT System</div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-6">
                <div class="mx-auto max-w-[1280px] space-y-5">
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                            <?php
                                echo htmlspecialchars((string)$_SESSION['success']);
                                unset($_SESSION['success']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
                            <?php
                                echo htmlspecialchars((string)$_SESSION['error']);
                                unset($_SESSION['error']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h1 class="text-[2.05rem] leading-tight font-semibold text-slate-900">Subjects</h1>
                            <p class="text-base text-slate-500">Reference list of curriculum subjects.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="openAddSubjectModalBtn" class="inline-flex items-center gap-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 shadow-sm hover:bg-blue-100">
                                <i class="bi bi-plus-circle"></i>
                                <span>Add Subject</span>
                            </button>
                            <button type="button" id="openEditUnitsModalBtn" class="inline-flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700 shadow-sm hover:bg-emerald-100">
                                <i class="bi bi-pencil-square"></i>
                                <span>Edit Subject Units</span>
                            </button>
                            <button type="button" id="collapseAllBtn" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 shadow-sm hover:bg-slate-50">
                                <i class="bi bi-chevron-down text-xs"></i>
                                <span>Collapse All</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
                        <div class="rounded-2xl border border-emerald-100 bg-white px-5 py-4 shadow-[0_1px_1px_rgba(15,23,42,0.03),0_10px_24px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-1 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)]">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><i class="bi bi-book"></i></span>
                                <div>
                                    <div class="text-4xl leading-none font-semibold text-slate-800"><?php echo (int)$total_subjects; ?></div>
                                    <div class="text-sm text-slate-400">Total Subjects</div>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-blue-100 bg-white px-5 py-4 shadow-[0_1px_1px_rgba(15,23,42,0.03),0_10px_24px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-1 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)]">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-blue-100 text-blue-600"><i class="bi bi-mortarboard"></i></span>
                                <div>
                                    <div class="text-4xl leading-none font-semibold text-slate-800"><?php echo (int)$total_units; ?></div>
                                    <div class="text-sm text-slate-400">Total Units</div>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-violet-100 bg-white px-5 py-4 shadow-[0_1px_1px_rgba(15,23,42,0.03),0_10px_24px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-1 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)]">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-violet-100 text-violet-600"><i class="bi bi-journal-bookmark"></i></span>
                                <div>
                                    <div class="text-4xl leading-none font-semibold text-slate-800"><?php echo (int)$core_subjects; ?></div>
                                    <div class="text-sm text-slate-400">Core Subjects</div>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-amber-100 bg-white px-5 py-4 shadow-[0_1px_1px_rgba(15,23,42,0.03),0_10px_24px_rgba(15,23,42,0.05)] transition-all duration-200 hover:-translate-y-1 hover:shadow-[0_12px_28px_rgba(15,23,42,0.10)]">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-amber-100 text-amber-600"><i class="bi bi-book-half"></i></span>
                                <div>
                                    <div class="text-4xl leading-none font-semibold text-slate-800"><?php echo (int)$ge_subjects; ?></div>
                                    <div class="text-sm text-slate-400">GE Subjects</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <?php foreach ($category_order as $cat): ?>
                            <?php $meta = $category_meta[$cat]; ?>
                            <button type="button" data-category-chip="<?php echo htmlspecialchars(strtolower($cat)); ?>" data-chip-active-class="<?php echo htmlspecialchars((string)($meta['chip_active'] ?? '')); ?>" class="subject-chip inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-500 hover:border-slate-300">
                                <span class="h-2.5 w-2.5 rounded-full <?php echo htmlspecialchars($meta['dot']); ?>"></span>
                                <span><?php echo htmlspecialchars($cat); ?></span>
                                <span data-category-count class="text-slate-400">(<?php echo (int)($category_counts[$cat] ?? 0); ?>)</span>
                            </button>
                        <?php endforeach; ?>
                        <button type="button" id="clearFiltersBtn" class="hidden inline-flex items-center gap-2 rounded-full border border-slate-300 bg-slate-50 px-3 py-1.5 text-sm font-semibold text-slate-500 hover:bg-slate-100">
                            <i class="bi bi-x-lg text-xs"></i>
                            <span>Clear</span>
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <?php foreach ($department_counts as $department_name => $department_total): ?>
                            <?php $department_key = subject_filter_key((string)$department_name); ?>
                            <button type="button" data-department-chip="<?php echo htmlspecialchars($department_key); ?>" data-department-label="<?php echo htmlspecialchars((string)$department_name); ?>" class="department-chip inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-500 hover:border-slate-300">
                                <i class="bi bi-diagram-3 text-xs"></i>
                                <span><?php echo htmlspecialchars((string)$department_name); ?></span>
                                <span class="text-slate-400">(<?php echo (int)$department_total; ?>)</span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div class="relative w-full max-w-xl">
                            <i class="bi bi-search pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input id="subjectSearch" type="search" placeholder="Search subjects or codes..." class="h-12 w-full rounded-2xl border border-slate-300 bg-white pl-11 pr-4 text-sm text-slate-700 outline-none ring-0 placeholder:text-slate-400 focus:border-blue-400" />
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500">
                                <i class="bi bi-funnel"></i>
                            </button>
                            <?php for ($year = 1; $year <= 4; $year++): ?>
                                <button type="button" data-year-btn="<?php echo $year; ?>" class="year-filter-btn inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-500 hover:border-slate-300 hover:text-slate-700">Year <?php echo $year; ?></button>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="subjectsAccordion" class="space-y-4">
                        <?php foreach ($subject_sections as $section): ?>
                            <?php
                                $panel_id = 'panel_' . $section['id'];
                                $type_tokens = [];
                                foreach ($section['type_list'] as $type_name) {
                                    $type_tokens[] = strtolower($type_name);
                                }
                            ?>
                            <section
                                class="subject-section"
                                data-section-card
                                data-year="<?php echo (int)$section['year']; ?>"
                                data-department="<?php echo htmlspecialchars((string)($section['department_key'] ?? '')); ?>"
                                data-types="<?php echo htmlspecialchars(implode(',', $type_tokens)); ?>"
                                data-search="<?php echo htmlspecialchars($section['search_blob']); ?>"
                                data-default-subject-count="<?php echo (int)$section['subject_count']; ?>"
                                data-default-unit-count="<?php echo (int)$section['unit_count']; ?>"
                            >
                                <button
                                    type="button"
                                    data-section-toggle
                                    data-target="<?php echo htmlspecialchars($panel_id); ?>"
                                    class="group flex w-full items-center justify-between rounded-[22px] bg-gradient-to-r <?php echo htmlspecialchars($section['gradient']); ?> px-6 py-4 text-left text-white shadow-[0_8px_20px_rgba(15,23,42,0.12)] transition hover:brightness-105"
                                >
                                    <div class="flex items-center gap-4">
                                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white/15 text-xl ring-1 ring-white/25">
                                            <i class="bi bi-mortarboard"></i>
                                        </span>
                                        <div>
                                            <div class="text-2xl font-semibold leading-tight"><?php echo htmlspecialchars($section['title']); ?></div>
                                            <div class="text-sm font-medium text-white/80"><span data-section-subject-count><?php echo (int)$section['subject_count']; ?></span> subjects · <span data-section-unit-count><?php echo (int)$section['unit_count']; ?></span> units</div>
                                            <div class="mt-1 inline-flex rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-semibold text-white/95"><?php echo htmlspecialchars((string)$section['department']); ?></div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <?php foreach ($section['type_list'] as $type_name): ?>
                                            <span class="hidden md:inline-flex rounded-full bg-white/25 px-2.5 py-1 text-xs font-semibold text-white"><?php echo htmlspecialchars($type_name); ?></span>
                                        <?php endforeach; ?>
                                        <i data-chevron class="bi bi-chevron-down text-sm transition-transform"></i>
                                    </div>
                                </button>

                                <div id="<?php echo htmlspecialchars($panel_id); ?>" data-section-panel class="hidden rounded-b-[22px] border border-t-0 border-slate-200 bg-white px-4 pb-4 pt-4 shadow-sm">
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                        <?php foreach ($section['items'] as $item): ?>
                                            <?php $item_meta = $category_meta[$item['type']] ?? ['pill' => 'bg-slate-100 text-slate-700']; ?>
                                            <article
                                                class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-slate-700 shadow-sm"
                                                data-subject-row
                                                data-item-type="<?php echo htmlspecialchars(strtolower((string)$item['type'])); ?>"
                                                data-item-search="<?php echo htmlspecialchars(strtolower((string)$item['code'] . ' ' . (string)$item['name'] . ' ' . (string)$item['type'] . ' ' . (string)$section['department'])); ?>"
                                                data-item-units="<?php echo (int)$item['units']; ?>"
                                            >
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="flex items-center gap-2">
                                                        <span class="h-2.5 w-2.5 rounded-full <?php echo htmlspecialchars($category_meta[$item['type']]['dot'] ?? 'bg-slate-400'); ?>"></span>
                                                        <span class="inline-flex items-center rounded-full border border-slate-300 bg-white px-2.5 py-0.5 text-xs font-semibold tracking-wide text-slate-600"><?php echo htmlspecialchars($item['code']); ?></span>
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <span class="inline-flex rounded-full border border-current/20 px-2.5 py-0.5 text-xs font-semibold <?php echo htmlspecialchars($item_meta['pill']); ?>"><?php echo htmlspecialchars($item['type']); ?></span>
                                                        <?php if ((int)($item['subject_id'] ?? 0) > 0): ?>
                                                            <form method="POST" action="subjects.php" onsubmit="return confirm('Delete this subject? This action cannot be undone.');">
                                                                <input type="hidden" name="action" value="delete_subject" />
                                                                <input type="hidden" name="subject_id" value="<?php echo (int)$item['subject_id']; ?>" />
                                                                <button type="submit" class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100" aria-label="Delete subject" title="Delete subject">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mt-3 text-[1.05rem] font-medium leading-snug text-slate-800"><?php echo htmlspecialchars($item['name']); ?></div>
                                                <div class="mt-1 text-xs font-medium text-slate-500"><?php echo htmlspecialchars((string)$section['department']); ?></div>
                                                <div class="mt-4 flex items-center justify-between">
                                                    <div class="inline-flex items-center gap-1.5 text-sm text-slate-500">
                                                        <i class="bi bi-book text-xs"></i>
                                                        <span><?php echo (int)$item['units']; ?> units</span>
                                                    </div>
                                                    <div class="inline-flex gap-1 text-rose-300">
                                                        <i class="bi bi-dot"></i><i class="bi bi-dot"></i><i class="bi bi-dot"></i>
                                                    </div>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <div id="subjectsEmptyState" class="hidden rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-slate-500">
                        No subjects match your current filters.
                    </div>

                    <div id="subjectsFilterSummary" class="hidden rounded-2xl border border-slate-200 bg-white px-6 py-5 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-slate-600">
                            <div id="subjectsFilterSummaryText" class="text-2xl leading-none font-medium text-slate-800"></div>
                            <span id="subjectsFilterSummaryBadge" class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-4 py-1.5 text-sm font-semibold text-rose-600"></span>
                        </div>
                    </div>

                    <div id="addSubjectModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
                        <div class="absolute inset-0 bg-slate-900/50" data-add-subject-close="1"></div>
                        <div class="relative mx-auto mt-10 w-full max-w-2xl px-4">
                            <div class="rounded-2xl border border-slate-200 bg-white shadow-xl overflow-hidden max-h-[88vh] flex flex-col">
                                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                    <div>
                                        <h2 class="text-lg font-semibold text-slate-900">Add Subject</h2>
                                        <p class="text-sm text-slate-500">Create a department-specific subject entry.</p>
                                    </div>
                                    <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100" data-add-subject-close="1" aria-label="Close">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>

                                <form method="POST" action="subjects.php" class="space-y-4 px-5 py-5 overflow-y-auto">
                                    <input type="hidden" name="action" value="add_subject" />

                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700">Subject Code</label>
                                            <input type="text" name="subject_code" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-200/50" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700">Units</label>
                                            <input type="number" name="units" min="1" max="10" value="3" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-200/50" />
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700">Subject Name</label>
                                        <input type="text" name="subject_name" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-200/50" />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700">Department</label>
                                        <input type="text" name="department" list="subjectDepartments" required value="<?php echo htmlspecialchars($default_department); ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-200/50" />
                                        <datalist id="subjectDepartments">
                                            <?php foreach (array_keys($department_counts) as $department_option): ?>
                                                <option value="<?php echo htmlspecialchars((string)$department_option); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700">Year Level</label>
                                            <select name="year_level" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-200/50">
                                                <option value="1">1st Year</option>
                                                <option value="2">2nd Year</option>
                                                <option value="3">3rd Year</option>
                                                <option value="4">4th Year</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700">Semester</label>
                                            <select name="semester" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-200/50">
                                                <option value="1st Semester">1st Semester</option>
                                                <option value="2nd Semester">2nd Semester</option>
                                                <option value="Summer Class">Summer Class</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700">Type</label>
                                            <select name="subject_type" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-200/50">
                                                <?php foreach ($category_order as $type_option): ?>
                                                    <option value="<?php echo htmlspecialchars($type_option); ?>"><?php echo htmlspecialchars($type_option); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-end gap-2 pt-1">
                                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-add-subject-close="1">Cancel</button>
                                        <button type="submit" class="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Add Subject</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div id="editUnitsModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
                        <div class="absolute inset-0 bg-slate-900/50" data-edit-units-close="1"></div>
                        <div class="relative mx-auto mt-16 w-full max-w-lg px-4">
                            <div class="rounded-2xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                    <div>
                                        <h2 class="text-lg font-semibold text-slate-900">Edit Subject Units</h2>
                                        <p class="text-sm text-slate-500">Select any subject and update its units.</p>
                                    </div>
                                    <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100" data-edit-units-close="1" aria-label="Close">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>

                                <form method="POST" action="subjects.php" class="space-y-4 px-5 py-5">
                                    <input type="hidden" name="action" value="update_subject_units" />

                                    <div>
                                        <label for="unitSubjectSelect" class="block text-sm font-semibold text-slate-700">Subject</label>
                                        <select id="unitSubjectSelect" name="subject_id" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200/50">
                                            <?php foreach ($all_subject_options as $subject_option): ?>
                                                <option value="<?php echo (int)$subject_option['id']; ?>" data-current-units="<?php echo (int)$subject_option['units']; ?>">
                                                    <?php
                                                        $subject_department_label = trim((string)($subject_option['department'] ?? ''));
                                                        if ($subject_department_label === '') {
                                                            $subject_department_label = $default_department;
                                                        }
                                                        echo htmlspecialchars((string)$subject_option['subject_code'] . ' - ' . (string)$subject_option['subject_name'] . ' (' . $subject_department_label . ')');
                                                    ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="unitValueInput" class="block text-sm font-semibold text-slate-700">Units</label>
                                        <input id="unitValueInput" type="number" name="units" min="1" max="10" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200/50" />
                                    </div>

                                    <div class="flex items-center justify-end gap-2 pt-1">
                                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-edit-units-close="1">Cancel</button>
                                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Save Units</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const btn = document.getElementById('sidebarBtn');
            const contentWrap = document.getElementById('contentWrap');
            let desktopExpanded = true;

            function isDesktop() {
                return window.innerWidth >= 1024;
            }

            function ensureSidebarCompactStyles() {
                if (document.getElementById('adminSidebarCompactStyles')) return;
                const style = document.createElement('style');
                style.id = 'adminSidebarCompactStyles';
                style.textContent = '#sidebar.sidebar-compact .leading-tight,#sidebar.sidebar-compact nav a span,#sidebar.sidebar-compact .absolute.bottom-0 a span{display:none;}#sidebar.sidebar-compact .px-4.py-4 > div:first-child{display:none;}#sidebar.sidebar-compact .h-16,#sidebar.sidebar-compact nav a,#sidebar.sidebar-compact .absolute.bottom-0 a{justify-content:center;padding-left:.5rem;padding-right:.5rem;}#sidebar.sidebar-compact nav a i{font-size:1.38rem;}';
                document.head.appendChild(style);
            }

            function setSidebarOpen(open) {
                if (!sidebar) return;

                if (isDesktop()) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.toggle('sidebar-compact', !open);
                    const desktopWidth = open ? 250 : 86;
                    sidebar.style.width = desktopWidth + 'px';
                    if (contentWrap) {
                        contentWrap.style.marginLeft = desktopWidth + 'px';
                    }
                } else {
                    sidebar.classList.remove('sidebar-compact');
                    sidebar.style.width = '250px';
                    sidebar.classList.toggle('-translate-x-full', !open);
                    if (contentWrap) {
                        contentWrap.style.marginLeft = '0px';
                    }
                }

                if (overlay) {
                    overlay.classList.toggle('hidden', !open || isDesktop());
                }
            }

            function applyLayoutState() {
                if (isDesktop()) {
                    setSidebarOpen(desktopExpanded);
                    return;
                }
                setSidebarOpen(false);
            }

            function isSidebarOpen() {
                if (!sidebar) return false;
                return isDesktop()
                    ? !sidebar.classList.contains('sidebar-compact')
                    : !sidebar.classList.contains('-translate-x-full');
            }

            btn?.addEventListener('click', function () {
                const currentlyOpen = isSidebarOpen();
                if (isDesktop()) {
                    desktopExpanded = !currentlyOpen;
                    setSidebarOpen(desktopExpanded);
                    return;
                }
                setSidebarOpen(!currentlyOpen);
            });

            overlay?.addEventListener('click', function () {
                setSidebarOpen(false);
            });

            window.openSidebar = function () { setSidebarOpen(true); };
            window.closeSidebar = function () { setSidebarOpen(false); };

            ensureSidebarCompactStyles();
            window.addEventListener('resize', applyLayoutState);
            applyLayoutState();
        })();

        (function () {
            const btn = document.getElementById('notifBtn');
            const menu = document.getElementById('notifMenu');
            const dot = document.getElementById('notifDot');
            if (!btn || !menu) return;

            function isOpen() {
                return !menu.classList.contains('hidden');
            }

            const markBtn = document.getElementById('notifMarkRead');
            const delBtn = document.getElementById('notifDelete');

            async function postAction(action) {
                try {
                    const res = await fetch('notifications_seen.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + encodeURIComponent(action)
                    });
                    return res.ok;
                } catch (_) {
                    return false;
                }
            }

            function openMenu() {
                menu.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
            }

            function closeMenu() {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }

            function toggleMenu() {
                if (isOpen()) closeMenu();
                else openMenu();
            }

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMenu();
            });

            markBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('seen');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            delBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('delete');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            document.addEventListener('click', function (e) {
                if (!isOpen()) return;
                const target = e.target;
                if (!(target instanceof Element)) return;
                if (menu.contains(target) || btn.contains(target)) return;
                closeMenu();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
            });
        })();

        (function () {
            const openBtn = document.getElementById('openAddSubjectModalBtn');
            const modal = document.getElementById('addSubjectModal');
            const closeEls = Array.from(document.querySelectorAll('[data-add-subject-close]'));

            if (!openBtn || !modal) {
                return;
            }

            function openModal() {
                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            }

            function closeModal() {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }

            openBtn.addEventListener('click', openModal);
            closeEls.forEach(function (el) {
                el.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });
        })();

        (function () {
            const openBtn = document.getElementById('openEditUnitsModalBtn');
            const modal = document.getElementById('editUnitsModal');
            const closeEls = Array.from(document.querySelectorAll('[data-edit-units-close]'));
            const subjectSelect = document.getElementById('unitSubjectSelect');
            const unitsInput = document.getElementById('unitValueInput');

            if (!openBtn || !modal || !subjectSelect || !unitsInput) {
                return;
            }

            function syncUnitsFromSelection() {
                const selectedOption = subjectSelect.options[subjectSelect.selectedIndex];
                const selectedUnits = parseInt(selectedOption?.getAttribute('data-current-units') || '3', 10) || 3;
                unitsInput.value = String(selectedUnits);
            }

            function openModal() {
                syncUnitsFromSelection();
                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            }

            function closeModal() {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }

            openBtn.addEventListener('click', openModal);
            closeEls.forEach(function (el) {
                el.addEventListener('click', closeModal);
            });
            subjectSelect.addEventListener('change', syncUnitsFromSelection);

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });
        })();

        (function () {
            const sectionCards = Array.from(document.querySelectorAll('[data-section-card]'));
            const searchInput = document.getElementById('subjectSearch');
            const emptyState = document.getElementById('subjectsEmptyState');
            const yearButtons = Array.from(document.querySelectorAll('[data-year-btn]'));
            const categoryButtons = Array.from(document.querySelectorAll('[data-category-chip]'));
            const departmentButtons = Array.from(document.querySelectorAll('[data-department-chip]'));
            const collapseBtn = document.getElementById('collapseAllBtn');
            const clearBtn = document.getElementById('clearFiltersBtn');
            const summaryWrap = document.getElementById('subjectsFilterSummary');
            const summaryText = document.getElementById('subjectsFilterSummaryText');
            const summaryBadge = document.getElementById('subjectsFilterSummaryBadge');
            let activeYear = '';
            let activeCategory = '';
            let activeDepartment = '';
            const categoryLabelByKey = {
                ge: 'GE',
                core: 'Core',
                elective: 'Elective',
                nstp: 'NSTP',
                pe: 'PE',
                capstone: 'Capstone'
            };
            const defaultCategoryCounts = {};
            const departmentLabelByKey = {};

            categoryButtons.forEach(function (btn) {
                const key = btn.getAttribute('data-category-chip') || '';
                const countEl = btn.querySelector('[data-category-count]');
                const rawCount = countEl ? (countEl.textContent || '') : '';
                const parsed = parseInt(rawCount.replace(/[^0-9]/g, ''), 10);
                defaultCategoryCounts[key] = Number.isFinite(parsed) ? parsed : 0;
            });

            departmentButtons.forEach(function (btn) {
                const key = btn.getAttribute('data-department-chip') || '';
                const label = btn.getAttribute('data-department-label') || '';
                if (key !== '') {
                    departmentLabelByKey[key] = label;
                }
            });

            function updateYearButtonStyles() {
                yearButtons.forEach(function (btn) {
                    const year = btn.getAttribute('data-year-btn') || '';
                    const isActive = activeYear === year;
                    btn.classList.toggle('bg-slate-900', isActive);
                    btn.classList.toggle('text-white', isActive);
                    btn.classList.toggle('border-slate-900', isActive);
                    btn.classList.toggle('bg-white', !isActive);
                    btn.classList.toggle('text-slate-500', !isActive);
                    btn.classList.toggle('border-slate-200', !isActive);
                });
            }

            function updateCategoryButtonStyles() {
                categoryButtons.forEach(function (btn) {
                    const cat = btn.getAttribute('data-category-chip') || '';
                    const isActive = activeCategory === cat;
                    const activeClasses = (btn.getAttribute('data-chip-active-class') || '').split(' ').filter(Boolean);
                    activeClasses.forEach(function (cls) {
                        btn.classList.toggle(cls, isActive);
                    });
                    btn.classList.toggle('border-slate-200', !isActive);
                    btn.classList.toggle('bg-white', !isActive);
                    btn.classList.toggle('text-slate-500', !isActive);
                });
            }

            function updateDepartmentButtonStyles() {
                departmentButtons.forEach(function (btn) {
                    const dep = btn.getAttribute('data-department-chip') || '';
                    const isActive = activeDepartment === dep;
                    btn.classList.toggle('bg-slate-900', isActive);
                    btn.classList.toggle('text-white', isActive);
                    btn.classList.toggle('border-slate-900', isActive);
                    btn.classList.toggle('bg-white', !isActive);
                    btn.classList.toggle('text-slate-500', !isActive);
                    btn.classList.toggle('border-slate-200', !isActive);
                });
            }

            function filterSections() {
                const query = (searchInput?.value || '').trim().toLowerCase();
                let visibleCount = 0;
                let visibleSemesters = 0;
                const contextualCategoryCounts = {};
                const forceOpen = activeCategory !== '' || activeDepartment !== '' || query !== '';
                const hasFilters = activeCategory !== '' || activeDepartment !== '' || activeYear !== '' || query !== '';

                sectionCards.forEach(function (card) {
                    const year = card.getAttribute('data-year') || '';
                    const department = card.getAttribute('data-department') || '';
                    const panel = card.querySelector('[data-section-panel]');
                    const toggleBtn = card.querySelector('[data-section-toggle]');
                    const chevron = toggleBtn?.querySelector('[data-chevron]');
                    const rows = Array.from(card.querySelectorAll('[data-subject-row]'));
                    const subjectCountEl = card.querySelector('[data-section-subject-count]');
                    const unitCountEl = card.querySelector('[data-section-unit-count]');

                    const yearMatch = !activeYear || year === activeYear;
                    const departmentMatch = !activeDepartment || department === activeDepartment;
                    let rowMatches = 0;
                    let rowUnits = 0;

                    rows.forEach(function (row) {
                        const rowType = row.getAttribute('data-item-type') || '';
                        const rowSearch = row.getAttribute('data-item-search') || '';
                        const rowUnit = parseInt(row.getAttribute('data-item-units') || '0', 10) || 0;
                        const contextMatch = yearMatch && departmentMatch && (query === '' || rowSearch.indexOf(query) !== -1);
                        if (contextMatch) {
                            contextualCategoryCounts[rowType] = (contextualCategoryCounts[rowType] || 0) + 1;
                        }
                        const categoryMatch = !activeCategory || rowType === activeCategory;
                        const showRow = contextMatch && categoryMatch;

                        row.classList.toggle('hidden', !showRow);
                        if (showRow) {
                            rowMatches += 1;
                            rowUnits += rowUnit;
                        }
                    });

                    const showSection = yearMatch && departmentMatch && rowMatches > 0;
                    card.classList.toggle('hidden', !showSection);
                    if (showSection) {
                        visibleCount += rowMatches;
                        visibleSemesters += 1;
                    }

                    if (subjectCountEl) {
                        subjectCountEl.textContent = String(rowMatches);
                    }
                    if (unitCountEl) {
                        unitCountEl.textContent = String(rowUnits);
                    }

                    if (!showSection && panel) {
                        panel.classList.add('hidden');
                        if (chevron) {
                            chevron.classList.remove('rotate-180');
                        }
                    } else if (forceOpen && panel) {
                        panel.classList.remove('hidden');
                        if (chevron) {
                            chevron.classList.add('rotate-180');
                        }
                    }
                });

                if (emptyState) {
                    emptyState.classList.toggle('hidden', visibleCount > 0);
                }

                clearBtn?.classList.toggle('hidden', !hasFilters);

                categoryButtons.forEach(function (btn) {
                    const key = btn.getAttribute('data-category-chip') || '';
                    const countEl = btn.querySelector('[data-category-count]');
                    if (!countEl) {
                        return;
                    }

                    const contextualCount = contextualCategoryCounts[key] || 0;
                    const displayCount = hasFilters ? contextualCount : (defaultCategoryCounts[key] || 0);
                    countEl.textContent = '(' + displayCount + ')';

                    const shouldHide = hasFilters && contextualCount === 0 && activeCategory !== key;
                    btn.classList.toggle('hidden', shouldHide);
                });

                if (summaryWrap && summaryText && summaryBadge) {
                    if (hasFilters && visibleCount > 0) {
                        const subjectWord = visibleCount === 1 ? 'subject' : 'subjects';
                        const semesterWord = visibleSemesters === 1 ? 'semester' : 'semesters';
                        summaryText.textContent = 'Showing ' + visibleCount + ' ' + subjectWord + ' across ' + visibleSemesters + ' ' + semesterWord + ' (filtered)';

                        let badgeText = visibleCount + ' Subjects';
                        if (activeCategory) {
                            badgeText = visibleCount + ' ' + (categoryLabelByKey[activeCategory] || activeCategory);
                        } else if (activeDepartment) {
                            badgeText = departmentLabelByKey[activeDepartment] || activeDepartment;
                        } else if (activeYear) {
                            badgeText = 'Year ' + activeYear;
                        }
                        summaryBadge.textContent = badgeText;
                        summaryWrap.classList.remove('hidden');
                    } else {
                        summaryWrap.classList.add('hidden');
                    }
                }
            }

            yearButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const selectedYear = btn.getAttribute('data-year-btn') || '';
                    activeYear = (activeYear === selectedYear) ? '' : selectedYear;
                    updateYearButtonStyles();
                    filterSections();
                });
            });

            categoryButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const cat = btn.getAttribute('data-category-chip') || '';
                    if (!cat) {
                        return;
                    }
                    activeCategory = (activeCategory === cat) ? '' : cat;
                    updateCategoryButtonStyles();
                    filterSections();
                });
            });

            departmentButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const dep = btn.getAttribute('data-department-chip') || '';
                    if (!dep) {
                        return;
                    }
                    activeDepartment = (activeDepartment === dep) ? '' : dep;
                    updateDepartmentButtonStyles();
                    filterSections();
                });
            });

            clearBtn?.addEventListener('click', function () {
                activeYear = '';
                activeCategory = '';
                activeDepartment = '';
                if (searchInput) {
                    searchInput.value = '';
                }
                updateYearButtonStyles();
                updateCategoryButtonStyles();
                updateDepartmentButtonStyles();
                filterSections();
            });

            searchInput?.addEventListener('input', filterSections);

            document.querySelectorAll('[data-section-toggle]').forEach(function (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    const targetId = toggleBtn.getAttribute('data-target');
                    if (!targetId) {
                        return;
                    }
                    const panel = document.getElementById(targetId);
                    const chevron = toggleBtn.querySelector('[data-chevron]');
                    if (!panel) {
                        return;
                    }
                    const willOpen = panel.classList.contains('hidden');
                    panel.classList.toggle('hidden', !willOpen);
                    if (chevron) {
                        chevron.classList.toggle('rotate-180', willOpen);
                    }
                });
            });

            collapseBtn?.addEventListener('click', function () {
                const allPanels = Array.from(document.querySelectorAll('[id^="panel_"]'));
                const anyOpen = allPanels.some(function (panel) {
                    return !panel.classList.contains('hidden');
                });

                allPanels.forEach(function (panel) {
                    panel.classList.toggle('hidden', anyOpen);
                    const toggleBtn = document.querySelector('[data-target="' + panel.id + '"]');
                    const chevron = toggleBtn?.querySelector('[data-chevron]');
                    if (chevron) {
                        chevron.classList.toggle('rotate-180', !anyOpen);
                    }
                });
            });

            updateYearButtonStyles();
            updateCategoryButtonStyles();
            updateDepartmentButtonStyles();
            filterSections();
        })();
    </script>
</body>
</html>
