<?php
header("Cache-Control: no-cache, must-revalidate"); // Prevent caching
session_start();
$teacher_session = $_SESSION['auth']['teacher'] ?? null;
if (!is_array($teacher_session) || empty($teacher_session['id'])) {
    header("Location: login.php");
    exit();
}
require_once "config.php";

$teacher_id = (int)$teacher_session['id'];
$teacher_name = $teacher_session['name'] ?? 'Teacher';
$role_stmt = $conn->prepare("SELECT name, role FROM users WHERE id = ? LIMIT 1");
$role_stmt->bind_param('i', $teacher_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();
$current_role = 'student';
if ($role_result && $role_result->num_rows > 0) {
    $role_row = $role_result->fetch_assoc();
    $teacher_name = $role_row['name'] ?? $teacher_name;
    $current_role = $role_row['role'] ?? 'student';
}
$role_stmt->close();

if ($current_role !== 'teacher') {
    if ($current_role === 'admin') {
        header("Location: admin_teacher.php");
    } else {
        header("Location: student.php");
    }
    exit();
}

// Separate connection for time_db
$time_conn = connect_time_db();
if ($time_conn->connect_error) {
    die("Time DB connection failed: " . $time_conn->connect_error);
}

$time_conn->query("CREATE TABLE IF NOT EXISTS classes_catalog (
    class_code VARCHAR(50) PRIMARY KEY,
    class_name VARCHAR(120) NOT NULL,
    section_name VARCHAR(80) DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$time_conn->query("INSERT IGNORE INTO classes_catalog (class_code, class_name, section_name) VALUES
    ('cs101', 'CS 101 - Programming', 'Section A'),
    ('math02', 'MATH 02 - Calculus', 'Section B'),
    ('hist01', 'HIST 01 - Philippines History', 'Section C')");

$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS on_time_threshold VARCHAR(8) NOT NULL DEFAULT '11:00:00'");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS late_threshold VARCHAR(8) NOT NULL DEFAULT '12:00:00'");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS section_id INT NULL");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS sections VARCHAR(255) NULL");

$time_conn->query("CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(120) NOT NULL
)");
$time_conn->query("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('on_time_threshold', '11:00:00'),
    ('late_threshold', '12:00:00')");

// Fetch current thresholds from database
$settings_query = $time_conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('on_time_threshold', 'late_threshold')");
$settings = [];
while ($row = $settings_query->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$on_time_threshold = $settings['on_time_threshold'] ?? '11:00:00';
$late_threshold = $settings['late_threshold'] ?? '12:00:00';

$selected_class = $_GET['class'] ?? '';
$selected_class = $_POST['class'] ?? $selected_class;
$current_date = date('Y-m-d');
$history_days = max(1, min(60, (int)($_GET['history_days'] ?? 7)));
$history_days = max(1, min(60, (int)($_POST['history_days'] ?? $history_days)));

$time_conn->query("CREATE TABLE IF NOT EXISTS attendance_activation (class VARCHAR(50) NOT NULL, active_date DATE NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (class, active_date))");

$classes_catalog = [];
$assigned_class_codes = [];

$teacher_section_stmt = $conn->prepare("SELECT class FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
if ($teacher_section_stmt) {
    $teacher_section_stmt->bind_param('i', $teacher_id);
    $teacher_section_stmt->execute();
    $teacher_section_result = $teacher_section_stmt->get_result();
    if ($teacher_section_result && $teacher_section_result->num_rows > 0) {
        $teacher_row = $teacher_section_result->fetch_assoc();
        $raw_classes = explode(',', (string)$teacher_row['class']);
        foreach ($raw_classes as $code) {
            $clean_code = strtolower(trim($code));
            if ($clean_code !== '') {
                $assigned_class_codes[$clean_code] = true;
            }
        }

    }
    $teacher_section_stmt->close();
}

if (!empty($assigned_class_codes)) {
    $classes_sql = "SELECT class_code, class_name, section_name, section_id, sections, on_time_threshold, late_threshold FROM classes_catalog WHERE is_active = 1 ORDER BY class_name ASC";
    $classes_result = $time_conn->query($classes_sql);
    while ($classes_result && $class_row = $classes_result->fetch_assoc()) {
        $class_code_key = strtolower(trim((string)$class_row['class_code']));
        $matches_class_code = ($class_code_key !== '' && isset($assigned_class_codes[$class_code_key]));

        if ($matches_class_code) {
            $classes_catalog[] = $class_row;
        }
    }
}

$monitor_notice = '';
if (empty($classes_catalog)) {
    $monitor_notice = 'No classes assigned yet. Ask admin to assign your classes.';
}

$valid_codes = array_column($classes_catalog, 'class_code');
if (!empty($valid_codes) && !in_array($selected_class, $valid_codes, true)) {
    $selected_class = $classes_catalog[0]['class_code'];
} elseif (empty($valid_codes)) {
    $selected_class = '';
}

$selected_class_info = null;
foreach ($classes_catalog as $class_item) {
    if ($class_item['class_code'] === $selected_class) {
        $selected_class_info = $class_item;
        break;
    }
}

// Use the selected class's own time thresholds (fall back to global settings)
if ($selected_class_info) {
    if (!empty($selected_class_info['on_time_threshold'])) $on_time_threshold = $selected_class_info['on_time_threshold'];
    if (!empty($selected_class_info['late_threshold']))    $late_threshold    = $selected_class_info['late_threshold'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_attendance']) && $selected_class !== '') {
    $new_state = isset($_POST['new_state']) && $_POST['new_state'] === '1' ? 1 : 0;

    $toggle_stmt = $time_conn->prepare("INSERT INTO attendance_activation (class, active_date, is_active) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = CURRENT_TIMESTAMP");
    $toggle_stmt->bind_param('ssi', $selected_class, $current_date, $new_state);
    $toggle_stmt->execute();
    $toggle_stmt->close();

    header("Location: teacher.php?class=" . urlencode($selected_class) . "&history_days=" . $history_days);
    exit();
}

$attendance_is_active = false;
$students = [];
$attendance = [];
$history_by_date = [];

if ($selected_class !== '') {
    $activation_stmt = $time_conn->prepare("SELECT is_active FROM attendance_activation WHERE class = ? AND active_date = ? LIMIT 1");
    $activation_stmt->bind_param('ss', $selected_class, $current_date);
    $activation_stmt->execute();
    $activation_result = $activation_stmt->get_result();
    if ($activation_result && $activation_result->num_rows > 0) {
        $activation_row = $activation_result->fetch_assoc();
        $attendance_is_active = ((int)$activation_row['is_active'] === 1);
    }
    $activation_stmt->close();

    // Include students enrolled in the selected class.
    $students_query = $conn->prepare("SELECT id, name FROM users WHERE role = 'student' AND FIND_IN_SET(?, class)");
    $students_query->bind_param('s', $selected_class);
    $students_query->execute();
    $students_result = $students_query->get_result();
    while ($student = $students_result->fetch_assoc()) {
        $students[] = $student;
    }
    $students_query->close();
}
$total_students = count($students);

// Get attendance for today and selected class from time_db
if ($selected_class !== '') {
    $attendance_query = $time_conn->prepare("SELECT user_id, attendance_time FROM attendance WHERE class = ? AND attendance_date = ?");
    $attendance_query->bind_param('ss', $selected_class, $current_date);
    $attendance_query->execute();
    $attendance_result = $attendance_query->get_result();
    while ($row = $attendance_result->fetch_assoc()) {
        $attendance[$row['user_id']] = $row['attendance_time'];
    }
    $attendance_query->close();
}

$history_result = false;
if ($selected_class !== '') {
    $history_query = $time_conn->prepare("SELECT user_id, attendance_date, attendance_time FROM attendance WHERE class = ? AND attendance_date >= DATE_SUB(?, INTERVAL ? DAY)");
    $history_query->bind_param('ssi', $selected_class, $current_date, $history_days);
    $history_query->execute();
    $history_result = $history_query->get_result();
}

$student_ids = [];
foreach ($students as $student_row) {
    $student_ids[(int)$student_row['id']] = true;
}

$start_timestamp = strtotime("-" . ($history_days - 1) . " days", strtotime($current_date));
for ($i = 0; $i < $history_days; $i++) {
    $date_key = date('Y-m-d', strtotime("+{$i} days", $start_timestamp));
    $history_by_date[$date_key] = ['present' => 0, 'late' => 0, 'absent' => $total_students];
}

$seen_user_date = [];
if ($history_result) {
    while ($row = $history_result->fetch_assoc()) {
        $user_id = (int)$row['user_id'];
        $date_key = $row['attendance_date'];

        if (!isset($student_ids[$user_id]) || !isset($history_by_date[$date_key])) {
            continue;
        }

        $seen_key = $user_id . '|' . $date_key;
        if (isset($seen_user_date[$seen_key])) {
            continue;
        }
        $seen_user_date[$seen_key] = true;

        $time_value = $row['attendance_time'];
        if ($time_value <= $on_time_threshold) {
            $history_by_date[$date_key]['present']++;
            $history_by_date[$date_key]['absent']--;
        } elseif ($time_value <= $late_threshold) {
            $history_by_date[$date_key]['late']++;
            $history_by_date[$date_key]['absent']--;
        }
    }
    $history_query->close();
}

$present_count = 0;
$late_count = 0;
$absent_count = 0;

// Calculate counts
foreach ($students as $student) {
    if (isset($attendance[$student['id']])) {
        if ($attendance[$student['id']] <= $on_time_threshold) {
            $present_count++;
        } elseif ($attendance[$student['id']] <= $late_threshold) {
            $late_count++;
        } else {
            $absent_count++;
        }
    } else {
        $absent_count++;
    }
}

$time_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Monitor - Classiee</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        <nav class="sidebar">
            <div class="sidebar-header">
                <span class="sidebar-logo">🏫</span>
                <h2>Classiee</h2>
            </div>
            <ul class="nav-links">
                <li class="active"><a href="#">📊 Attendance Monitor</a></li>
                <li><a href="login.php">🚪 Logout</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <header class="top-bar">
                <div class="welcome-text">
                    <h1>Teacher Class Monitoring</h1>
                    <p class="class-info">Welcome, <?php echo htmlspecialchars($teacher_name); ?></p>
                    <?php if ($monitor_notice !== '') { ?>
                        <p class="teacher-inline-error"><?php echo htmlspecialchars($monitor_notice); ?></p>
                    <?php } ?>
                    <div class="teacher-controls-row">
                        <form method="get" class="teacher-filters-form">
                            <label for="class-select">Assigned Classes:</label>
                            <select id="class-select" name="class" <?php if (empty($classes_catalog)) echo 'disabled'; ?>>
                                <?php if (empty($classes_catalog)) { ?>
                                    <option value="" selected disabled>No assigned classes</option>
                                <?php } else { ?>
                                    <?php foreach ($classes_catalog as $class_item) { ?>
                                        <option value="<?php echo htmlspecialchars($class_item['class_code']); ?>" <?php if ($selected_class === $class_item['class_code']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($class_item['class_name']); ?>
                                        </option>
                                    <?php } ?>
                                <?php } ?>
                            </select>
                            <label for="history-days">History:</label>
                            <select id="history-days" name="history_days">
                                <option value="7" <?php if ($history_days === 7) echo 'selected'; ?>>7 days</option>
                                <option value="14" <?php if ($history_days === 14) echo 'selected'; ?>>14 days</option>
                                <option value="30" <?php if ($history_days === 30) echo 'selected'; ?>>30 days</option>
                            </select>
                        </form>
                        <div class="teacher-cutoff-display" aria-label="Selected class cutoff times">
                            <span class="teacher-cutoff-pill">On-time: <?php echo $selected_class !== '' ? date('h:i A', strtotime($on_time_threshold)) : '--'; ?></span>
                            <span class="teacher-cutoff-pill">Late: <?php echo $selected_class !== '' ? date('h:i A', strtotime($late_threshold)) : '--'; ?></span>
                        </div>
                        <div class="teacher-activation-panel">
                            <span class="activation-badge <?php echo $attendance_is_active ? 'active' : 'inactive'; ?>">
                                <?php echo $selected_class === '' ? 'No Assigned Class' : ($attendance_is_active ? 'Attendance Active' : 'Attendance Inactive'); ?>
                            </span>
                            <form method="post" class="teacher-activation-form">
                                <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
                                <input type="hidden" name="history_days" value="<?php echo $history_days; ?>">
                                <input type="hidden" name="new_state" value="<?php echo $attendance_is_active ? '0' : '1'; ?>">
                                <button type="submit" name="toggle_attendance" class="teacher-update-btn" <?php if ($selected_class === '') echo 'disabled'; ?>>
                                    <?php echo $selected_class === '' ? 'Awaiting Assignment' : ($attendance_is_active ? 'Deactivate Attendance' : 'Activate Attendance'); ?>
                                </button>
                            </form>
                        </div>
                        <p id="class-info" class="class-info"><?php 
                        if ($selected_class_info) {
                            $section_display = trim($selected_class_info['section_name']) !== '' ? $selected_class_info['section_name'] : 'General Section';
                            echo htmlspecialchars($selected_class_info['class_name']) . ' | ' . htmlspecialchars($section_display) . ' | ' . date('F j, Y');
                        } else {
                            echo 'Select one of your assigned classes';
                        }
                        ?></p>
                    </div>
                </div>
            </header>

            <div class="stats-row">
                <div class="stat-card present-border">
                    <span class="label">Present</span>
                    <span class="value"><?php echo $present_count; ?></span>
                </div>
                <div class="stat-card late-border">
                    <span class="label">Late</span>
                    <span class="value"><?php echo $late_count; ?></span>
                </div>
                <div class="stat-card absent-border">
                    <span class="label">Absent</span>
                    <span class="value"><?php echo $absent_count; ?></span>
                </div>
            </div>

            <div class="table-card">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>ID Number</th>
                            <th>Time In</th>
                            <th style="text-align: right;">Current Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $id_counter = 1;
                        foreach ($students as $student) {
                            $id = '#2024-' . str_pad($id_counter, 3, '0', STR_PAD_LEFT);
                            if (isset($attendance[$student['id']])) {
                                $time_in = date('h:i A', strtotime($attendance[$student['id']]));
                                if ($attendance[$student['id']] <= $on_time_threshold) {
                                    $status = 'Present';
                                    $status_class = 'present';
                                } elseif ($attendance[$student['id']] <= $late_threshold) {
                                    $status = 'Late';
                                    $status_class = 'late';
                                } else {
                                    $status = 'Absent';
                                    $status_class = 'absent';
                                }
                            } else {
                                $time_in = '--:--';
                                $status = 'Absent';
                                $status_class = 'absent';
                            }
                            echo "<tr>
                                <td>{$student['name']}</td>
                                <td>{$id}</td>
                                <td>{$time_in}</td>
                                <td style='text-align: right;'><span class='status-pill {$status_class}'>{$status}</span></td>
                            </tr>";
                            $id_counter++;
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card teacher-history-card">
                <h3 class="teacher-section-title">Attendance History (Last <?php echo $history_days; ?> days)</h3>
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Present</th>
                            <th>Late</th>
                            <th>Absent/After Cutoff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (count($history_by_date) === 0) {
                            echo "<tr><td colspan='4' style='text-align:center;'>No history yet for this class.</td></tr>";
                        } else {
                            krsort($history_by_date);
                            foreach ($history_by_date as $date_key => $counts) {
                                echo "<tr>
                                    <td>" . date('M j, Y', strtotime($date_key)) . "</td>
                                    <td>{$counts['present']}</td>
                                    <td>{$counts['late']}</td>
                                    <td>{$counts['absent']}</td>
                                </tr>";
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    <script src="script.js"></script>
    <script>
        function scheduleMidnightRefresh() {
            const now = new Date();
            const nextMidnight = new Date(now);
            nextMidnight.setHours(24, 0, 0, 0);
            const msUntilMidnight = nextMidnight.getTime() - now.getTime();

            setTimeout(() => {
                window.location.reload();
            }, msUntilMidnight + 1000);
        }

        scheduleMidnightRefresh();

        const classSelect = document.getElementById('class-select');
        const historyDaysSelect = document.getElementById('history-days');
        const filtersForm = document.querySelector('.teacher-filters-form');

        classSelect.addEventListener('change', () => {
            filtersForm.submit();
        });

        historyDaysSelect.addEventListener('change', () => {
            filtersForm.submit();
        });
    </script>
</body>
</html>