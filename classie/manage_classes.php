<?php
header("Cache-Control: no-cache, must-revalidate");
session_start();
$admin_session = $_SESSION['auth']['admin'] ?? null;
if (!is_array($admin_session) || empty($admin_session['id'])) {
    header("Location: login.php");
    exit();
}
require_once "config.php";

$current_user_id = (int)$admin_session['id'];
$role_stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$role_stmt->bind_param('i', $current_user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();
$current_role = 'student';
if ($role_result && $role_result->num_rows > 0) {
    $role_row = $role_result->fetch_assoc();
    $current_role = $role_row['role'] ?? 'student';
}
$role_stmt->close();

if ($current_role !== 'admin') {
    if ($current_role === 'teacher') {
        header("Location: teacher.php");
    } else {
        header("Location: student.php");
    }
    exit();
}

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

// Add per-class time threshold columns safely (no-op when columns already exist)
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

$settings_q = $time_conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('on_time_threshold', 'late_threshold')");
$settings_vals = [];
while ($settings_q && $s_row = $settings_q->fetch_assoc()) {
    $settings_vals[$s_row['setting_key']] = $s_row['setting_value'];
}
$on_time_threshold = $settings_vals['on_time_threshold'] ?? '11:00:00';
$late_threshold    = $settings_vals['late_threshold'] ?? '12:00:00';

$sections = [];
$sections_result = $conn->query("SELECT id, section_name FROM sections WHERE is_active = 1 ORDER BY section_name ASC");
while ($sections_result && $section_row = $sections_result->fetch_assoc()) {
    $sections[] = [
        'id' => (int)$section_row['id'],
        'name' => $section_row['section_name'],
    ];
}

$class_error    = '';
$class_success  = '';
$times_error    = '';
$times_success  = '';
$sections_error = '';
$sections_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    $new_code = strtolower(trim($_POST['class_code'] ?? ''));
    $new_name = trim($_POST['class_name'] ?? '');
    $selected_section_ids = $_POST['section_ids'] ?? [];
    $new_section = '';
    $new_section_id = 0;
    $new_sections_csv = '';

    if (!is_array($selected_section_ids)) {
        $selected_section_ids = [];
    }

    $normalized_section_ids = [];
    foreach ($selected_section_ids as $sid) {
        $clean_sid = (int)trim((string)$sid);
        if ($clean_sid > 0) {
            $normalized_section_ids[] = $clean_sid;
        }
    }
    $normalized_section_ids = array_values(array_unique($normalized_section_ids));

    if (empty($normalized_section_ids)) {
        $class_error = 'Please select at least one existing section.';
    } else {
        $section_stmt = $conn->prepare("SELECT id, section_name FROM sections WHERE id = ? AND is_active = 1 LIMIT 1");
        if ($section_stmt) {
            $validated_sections = [];
            foreach ($normalized_section_ids as $sid) {
                $section_stmt->bind_param('i', $sid);
                $section_stmt->execute();
                $section_result = $section_stmt->get_result();
                if ($section_result && $section_result->num_rows > 0) {
                    $section_row = $section_result->fetch_assoc();
                    $validated_sections[] = [
                        'id' => (int)$section_row['id'],
                        'name' => $section_row['section_name'],
                    ];
                }
            }
            $section_stmt->close();

            if (count($validated_sections) !== count($normalized_section_ids)) {
                $class_error = 'One or more selected sections are invalid or inactive.';
            } else {
                $new_section_id = (int)$validated_sections[0]['id'];
                $new_section = $validated_sections[0]['name'];
                $new_sections_csv = implode(',', array_column($validated_sections, 'id'));
            }
        } else {
            $class_error = 'Unable to validate selected sections right now.';
        }
    }

    if ($class_error === '' && ($new_code === '' || $new_name === '')) {
        $class_error = 'Class code and class name are required.';
    } elseif ($class_error === '' && !preg_match('/^[a-z0-9_\-]+$/', $new_code)) {
        $class_error = 'Class code can only contain lowercase letters, numbers, underscore, and dash.';
    } elseif ($class_error === '') {
        $add_on_raw  = trim($_POST['add_on_time'] ?? '');
        $add_late_raw = trim($_POST['add_late'] ?? '');
        // Fall back to global defaults if the submitted times are invalid
        $add_on_time = (preg_match('/^\d{2}:\d{2}$/', $add_on_raw)) ? $add_on_raw . ':00' : $on_time_threshold;
        $add_late    = (preg_match('/^\d{2}:\d{2}$/', $add_late_raw) && $add_late_raw > $add_on_raw) ? $add_late_raw . ':00' : $late_threshold;

        $insert_class_stmt = $time_conn->prepare("INSERT INTO classes_catalog (class_code, class_name, section_name, section_id, sections, on_time_threshold, late_threshold) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_class_stmt->bind_param('sssisss', $new_code, $new_name, $new_section, $new_section_id, $new_sections_csv, $add_on_time, $add_late);
        if ($insert_class_stmt->execute()) {
            $class_success = 'Class added successfully.';
        } else {
            $class_error = 'Unable to add class. Code may already exist.';
        }
        $insert_class_stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_class'])) {
    $remove_code = strtolower(trim($_POST['remove_code'] ?? ''));

    if ($remove_code === '') {
        $class_error = 'Invalid class code for removal.';
    } else {
        $active_count_result = $time_conn->query("SELECT COUNT(*) AS total_active FROM classes_catalog WHERE is_active = 1");
        $active_count_row = $active_count_result ? $active_count_result->fetch_assoc() : ['total_active' => 0];
        $total_active = (int)$active_count_row['total_active'];

        if ($total_active <= 1) {
            $class_error = 'You must keep at least one active class.';
        } else {
            $deactivate_stmt = $time_conn->prepare("UPDATE classes_catalog SET is_active = 0 WHERE class_code = ?");
            $deactivate_stmt->bind_param('s', $remove_code);
            $deactivate_stmt->execute();
            $affected_rows = $deactivate_stmt->affected_rows;
            $deactivate_stmt->close();

            if ($affected_rows > 0) {
                $users_result = $conn->query("SELECT id, class FROM users WHERE role = 'student' AND FIND_IN_SET('" . $conn->real_escape_string($remove_code) . "', class)");
                while ($users_result && $user_row = $users_result->fetch_assoc()) {
                    $current_classes = array_values(array_filter(array_map('trim', explode(',', $user_row['class'])), function ($item) {
                        return $item !== '';
                    }));
                    $updated_classes = array_values(array_filter($current_classes, function ($item) use ($remove_code) {
                        return strtolower($item) !== $remove_code;
                    }));
                    $updated_class_str = implode(',', $updated_classes);

                    $update_user_stmt = $conn->prepare("UPDATE users SET class = ? WHERE id = ?");
                    $update_user_stmt->bind_param('si', $updated_class_str, $user_row['id']);
                    $update_user_stmt->execute();
                    $update_user_stmt->close();
                }

                $class_success = 'Class removed successfully.';
            } else {
                $class_error = 'Unable to remove class. It may already be inactive or missing.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_class_thresholds'])) {
    $tc_code     = strtolower(trim($_POST['times_class_code'] ?? ''));
    $tc_on_raw   = trim($_POST['row_on_time'] ?? '');
    $tc_late_raw = trim($_POST['row_late'] ?? '');

    if ($tc_code === '') {
        $times_error = 'Invalid class code.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $tc_on_raw) || !preg_match('/^\d{2}:\d{2}$/', $tc_late_raw)) {
        $times_error = 'Invalid time format. Please use the time picker.';
    } elseif ($tc_on_raw >= $tc_late_raw) {
        $times_error = '"On Time" cutoff must be earlier than the "Late" cutoff.';
    } else {
        $tc_on_time = $tc_on_raw . ':00';
        $tc_late    = $tc_late_raw . ':00';
        $tc_stmt = $time_conn->prepare("UPDATE classes_catalog SET on_time_threshold = ?, late_threshold = ? WHERE class_code = ?");
        $tc_stmt->bind_param('sss', $tc_on_time, $tc_late, $tc_code);
        $tc_stmt->execute();
        $tc_stmt->close();
        $times_success = 'Time settings updated for class.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_class_sections'])) {
    $edit_code = strtolower(trim($_POST['edit_sections_class_code'] ?? ''));
    $edit_section_ids = $_POST['edit_section_ids'] ?? [];

    if ($edit_code === '') {
        $sections_error = 'Invalid class selected.';
    } else {
        if (!is_array($edit_section_ids)) {
            $edit_section_ids = [];
        }

        $normalized_section_ids = [];
        foreach ($edit_section_ids as $sid) {
            $clean_sid = (int)trim((string)$sid);
            if ($clean_sid > 0) {
                $normalized_section_ids[] = $clean_sid;
            }
        }
        $normalized_section_ids = array_values(array_unique($normalized_section_ids));

        if (empty($normalized_section_ids)) {
            $sections_error = 'Please select at least one section for this class.';
        } else {
            $class_exists_stmt = $time_conn->prepare("SELECT class_code FROM classes_catalog WHERE class_code = ? AND is_active = 1 LIMIT 1");
            if ($class_exists_stmt) {
                $class_exists_stmt->bind_param('s', $edit_code);
                $class_exists_stmt->execute();
                $class_exists_result = $class_exists_stmt->get_result();
                $class_exists = ($class_exists_result && $class_exists_result->num_rows > 0);
                $class_exists_stmt->close();
            } else {
                $class_exists = false;
            }

            if (!$class_exists) {
                $sections_error = 'Class record was not found or is inactive.';
            } else {
                $section_stmt = $conn->prepare("SELECT id, section_name FROM sections WHERE id = ? AND is_active = 1 LIMIT 1");
                if ($section_stmt) {
                    $validated_sections = [];
                    foreach ($normalized_section_ids as $sid) {
                        $section_stmt->bind_param('i', $sid);
                        $section_stmt->execute();
                        $section_result = $section_stmt->get_result();
                        if ($section_result && $section_result->num_rows > 0) {
                            $section_row = $section_result->fetch_assoc();
                            $validated_sections[] = [
                                'id' => (int)$section_row['id'],
                                'name' => $section_row['section_name'],
                            ];
                        }
                    }
                    $section_stmt->close();

                    if (count($validated_sections) !== count($normalized_section_ids)) {
                        $sections_error = 'One or more selected sections are invalid or inactive.';
                    } else {
                        $primary_section_id = (int)$validated_sections[0]['id'];
                        $primary_section_name = $validated_sections[0]['name'];
                        $sections_csv = implode(',', array_column($validated_sections, 'id'));

                        $update_sections_stmt = $time_conn->prepare("UPDATE classes_catalog SET section_id = ?, section_name = ?, sections = ? WHERE class_code = ? AND is_active = 1");
                        if ($update_sections_stmt) {
                            $update_sections_stmt->bind_param('isss', $primary_section_id, $primary_section_name, $sections_csv, $edit_code);
                            if ($update_sections_stmt->execute()) {
                                $sections_success = 'Class sections updated successfully.';
                            } else {
                                $sections_error = 'Unable to update class sections right now.';
                            }
                            $update_sections_stmt->close();
                        } else {
                            $sections_error = 'Unable to update class sections right now.';
                        }
                    }
                } else {
                    $sections_error = 'Unable to validate selected sections right now.';
                }
            }
        }
    }
}

$classes_result = $time_conn->query("SELECT class_code, class_name, section_name, section_id, sections, on_time_threshold, late_threshold, created_at FROM classes_catalog WHERE is_active = 1 ORDER BY class_name ASC");
$classes_catalog = [];
while ($classes_result && $class_row = $classes_result->fetch_assoc()) {
    $classes_catalog[] = $class_row;
}

$section_name_map = [];
foreach ($sections as $section) {
    $section_name_map[(int)$section['id']] = $section['name'];
}

$time_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes - Classiee</title>
    <link rel="stylesheet" href="style.css?v=20260315">
    <style>
        /* Critical modal styles kept inline to avoid stale CSS cache issues. */
        .app-modal-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(2, 6, 23, 0.6);
            z-index: 1300;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .app-modal-overlay.visible {
            opacity: 1;
            visibility: visible;
        }

        .app-modal-card {
            width: 100%;
            max-width: 430px;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: linear-gradient(160deg, #0f172a 0%, #1e293b 100%);
            box-shadow: 0 24px 44px rgba(2, 6, 23, 0.45);
            padding: 20px;
            transform: scale(0.97) translateY(5px);
            transition: transform 0.2s ease;
        }

        .app-modal-overlay.visible .app-modal-card {
            transform: scale(1) translateY(0);
        }

        .app-modal-title {
            margin: 0;
            color: #f8fafc;
            font-size: 1.1rem;
        }

        .app-modal-message {
            margin: 10px 0 0;
            color: #dbeafe;
            font-size: 0.94rem;
            line-height: 1.45;
        }

        .app-modal-actions {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .app-modal-btn {
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            color: #ffffff;
            font-weight: 700;
            cursor: pointer;
        }

        .app-modal-btn-secondary {
            background: #475569;
        }

        .app-modal-btn-danger {
            background: #dc2626;
        }

        .app-modal-btn:hover {
            filter: brightness(1.06);
        }

        @media (max-width: 768px) {
            .app-modal-card {
                max-width: 94vw;
                padding: 18px 16px;
            }
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        <nav class="sidebar">
            <div class="sidebar-header">
                <span class="sidebar-logo">🏫</span>
                <h2>Classiee</h2>
            </div>
            <ul class="nav-links">
                <li><a href="admin.php">🧑‍🎓 Student Tab</a></li>
                <li><a href="admin_teacher.php">👩‍🏫 Teacher Tab</a></li>
                <li><a href="section_management.php">🗂 Section Management</a></li>
                <li class="active"><a href="manage_classes.php">🧩 Manage Classes</a></li>
                <li><a href="login.php">🚪 Logout</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <header class="top-bar">
                <div class="welcome-text">
                    <h1>Manage Classes</h1>
                    <p class="class-info">Create new classes and assign one or more existing sections.</p>
                </div>
            </header>

            <div class="table-card teacher-add-class-card">
                <h3 class="teacher-section-title">Add New Class</h3>
                <?php if ($class_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($class_error); ?></p>
                <?php } ?>
                <?php if ($class_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($class_success); ?></p>
                <?php } ?>
                <form method="post" class="teacher-add-class-form manage-class-inline-form">
                    <input type="text" name="class_code" placeholder="class code (e.g. eng01)" required>
                    <input type="text" name="class_name" placeholder="class name (e.g. ENG 01 - Writing)" required>
                    <details class="teacher-class-dropdown manage-class-sections-dropdown" aria-label="Section selection">
                        <summary class="teacher-class-summary">Select sections</summary>
                        <div class="teacher-class-panel">
                            <?php if (empty($sections)) { ?>
                                <span class="teacher-class-name">No active sections found</span>
                            <?php } else { ?>
                                <?php foreach ($sections as $section) { ?>
                                    <label class="teacher-class-option">
                                        <input type="checkbox" name="section_ids[]" value="<?php echo (int)$section['id']; ?>">
                                        <span class="teacher-class-bullet"></span>
                                        <span class="teacher-class-name"><?php echo htmlspecialchars($section['name']); ?></span>
                                    </label>
                                <?php } ?>
                            <?php } ?>
                        </div>
                    </details>
                    <div class="teacher-settings-group">
                        <label>On Time Cutoff</label>
                        <input type="time" name="add_on_time" value="<?php echo htmlspecialchars(substr($on_time_threshold, 0, 5)); ?>">
                    </div>
                    <div class="teacher-settings-group">
                        <label>Late Cutoff</label>
                        <input type="time" name="add_late" value="<?php echo htmlspecialchars(substr($late_threshold, 0, 5)); ?>">
                    </div>
                    <button type="submit" name="add_class" class="teacher-update-btn" <?php if (empty($sections)) echo 'disabled'; ?>>Add Class</button>
                </form>
            </div>

            <div class="table-card teacher-history-card">
                <h3 class="teacher-section-title">Active Classes</h3>
                <?php if ($sections_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($sections_error); ?></p>
                <?php } ?>
                <?php if ($sections_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($sections_success); ?></p>
                <?php } ?>
                <?php if ($times_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($times_error); ?></p>
                <?php } ?>
                <?php if ($times_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($times_success); ?></p>
                <?php } ?>
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Class Code</th>
                            <th>Class Name</th>
                            <th>Sections</th>
                            <th>Created</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($classes_catalog)) { ?>
                            <tr><td colspan="5" style="text-align: center;">No classes found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($classes_catalog as $class_item) { ?>
                                <?php
                                    $class_section_ids = [];
                                    if (!empty($class_item['sections'])) {
                                        $class_section_ids = array_values(array_filter(array_map('intval', explode(',', (string)$class_item['sections']))));
                                    } elseif (!empty($class_item['section_id'])) {
                                        $class_section_ids = [(int)$class_item['section_id']];
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($class_item['class_code']); ?></td>
                                    <td><?php echo htmlspecialchars($class_item['class_name']); ?></td>
                                    <td>
                                        <?php
                                            $section_names = [];
                                            foreach ($class_section_ids as $sid) {
                                                if (isset($section_name_map[$sid])) {
                                                    $section_names[] = $section_name_map[$sid];
                                                }
                                            }
                                            if (empty($section_names) && !empty($class_item['section_name'])) {
                                                $section_names[] = $class_item['section_name'];
                                            }
                                        ?>
                                        <div class="manage-sections-cell">
                                            <div class="manage-sections-current"><?php echo htmlspecialchars(!empty($section_names) ? implode(', ', $section_names) : 'No section'); ?></div>
                                            <form method="post" class="manage-sections-form">
                                                <input type="hidden" name="edit_sections_class_code" value="<?php echo htmlspecialchars($class_item['class_code']); ?>">
                                                <details class="teacher-class-dropdown teacher-class-dropdown-inline">
                                                    <summary class="teacher-class-summary">Edit sections</summary>
                                                    <div class="teacher-class-panel">
                                                        <?php foreach ($sections as $section) { ?>
                                                            <label class="teacher-class-option">
                                                                <input type="checkbox" name="edit_section_ids[]" value="<?php echo (int)$section['id']; ?>" <?php if (in_array((int)$section['id'], $class_section_ids, true)) echo 'checked'; ?>>
                                                                <span class="teacher-class-bullet"></span>
                                                                <span class="teacher-class-name"><?php echo htmlspecialchars($section['name']); ?></span>
                                                            </label>
                                                        <?php } ?>
                                                    </div>
                                                </details>
                                                <button type="submit" name="save_class_sections" class="teacher-save-times-btn">Save Sections</button>
                                            </form>
                                        </div>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($class_item['created_at'])); ?></td>
                                    <td class="teacher-class-actions-cell">
                                        <form method="post" class="teacher-times-row-form">
                                            <input type="hidden" name="times_class_code" value="<?php echo htmlspecialchars($class_item['class_code']); ?>">
                                            <span class="times-field-wrap">
                                                <label class="times-row-label">On Time</label>
                                                <input type="time" name="row_on_time" value="<?php echo htmlspecialchars(substr($class_item['on_time_threshold'] ?? '11:00:00', 0, 5)); ?>">
                                            </span>
                                            <span class="times-field-wrap">
                                                <label class="times-row-label">Late</label>
                                                <input type="time" name="row_late" value="<?php echo htmlspecialchars(substr($class_item['late_threshold'] ?? '12:00:00', 0, 5)); ?>">
                                            </span>
                                            <button type="submit" name="save_class_thresholds" class="teacher-save-times-btn">Save</button>
                                        </form>
                                        <form method="post" class="teacher-remove-class-form" data-confirm-message="Remove this class? This will also remove it from student enrollments.">
                                            <input type="hidden" name="remove_class" value="1">
                                            <input type="hidden" name="remove_code" value="<?php echo htmlspecialchars($class_item['class_code']); ?>">
                                            <button type="submit" class="teacher-remove-btn">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <div id="confirm-popup" class="app-modal-overlay" aria-hidden="true" hidden>
        <div class="app-modal-card" role="dialog" aria-modal="true" aria-labelledby="confirm-popup-title" aria-describedby="confirm-popup-message">
            <h3 id="confirm-popup-title" class="app-modal-title">Please Confirm</h3>
            <p id="confirm-popup-message" class="app-modal-message">Are you sure you want to continue?</p>
            <div class="app-modal-actions">
                <button type="button" id="confirm-popup-cancel" class="app-modal-btn app-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-popup-ok" class="app-modal-btn app-modal-btn-danger">Remove</button>
            </div>
        </div>
    </div>

    <script>
        const confirmPopup = document.getElementById('confirm-popup');
        const confirmPopupMessage = document.getElementById('confirm-popup-message');
        const confirmPopupCancel = document.getElementById('confirm-popup-cancel');
        const confirmPopupOk = document.getElementById('confirm-popup-ok');
        let pendingConfirmedForm = null;

        function closeConfirmPopup() {
            confirmPopup.classList.remove('visible');
            confirmPopup.setAttribute('aria-hidden', 'true');
            confirmPopup.hidden = true;
            pendingConfirmedForm = null;
        }

        function openConfirmPopup(formElement) {
            pendingConfirmedForm = formElement;
            const customMessage = formElement.getAttribute('data-confirm-message') || 'Are you sure you want to continue?';
            confirmPopupMessage.textContent = customMessage;
            confirmPopup.hidden = false;
            confirmPopup.classList.add('visible');
            confirmPopup.setAttribute('aria-hidden', 'false');
        }

        confirmPopupCancel.addEventListener('click', closeConfirmPopup);

        confirmPopupOk.addEventListener('click', function () {
            if (pendingConfirmedForm) {
                const formToSubmit = pendingConfirmedForm;
                closeConfirmPopup();
                formToSubmit.submit();
            }
        });

        confirmPopup.addEventListener('click', function (event) {
            if (event.target === confirmPopup) {
                closeConfirmPopup();
            }
        });

        document.querySelectorAll('.teacher-remove-class-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.confirmed === '1') {
                    form.dataset.confirmed = '0';
                    return;
                }

                event.preventDefault();
                openConfirmPopup(form);
            });
        });
    </script>
</body>
</html>
