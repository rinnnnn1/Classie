<?php
header("Cache-Control: no-cache, must-revalidate");
session_start();
$admin_session = $_SESSION['auth']['admin'] ?? null;
if (!is_array($admin_session) || empty($admin_session['id'])) {
    header("Location: login.php");
    exit();
}
require_once "config.php";

$admin_id = (int)$admin_session['id'];
$admin_name = $admin_session['name'] ?? 'Admin';

$role_stmt = $conn->prepare("SELECT name, role FROM users WHERE id = ? LIMIT 1");
$role_stmt->bind_param('i', $admin_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();
$current_role = 'student';
if ($role_result && $role_result->num_rows > 0) {
    $role_row = $role_result->fetch_assoc();
    $admin_name = $role_row['name'] ?? $admin_name;
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

$teacher_error = '';
$teacher_success = '';
$assignment_error = '';
$assignment_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_teacher_account'])) {
    $new_name = trim($_POST['new_teacher_name'] ?? '');
    $new_username = trim($_POST['new_teacher_username'] ?? '');
    $new_password = $_POST['new_teacher_password'] ?? '';

    if ($new_name === '' || $new_username === '' || $new_password === '') {
        $teacher_error = 'Please fill in all teacher account fields.';
    } elseif (strlen($new_password) < 6) {
        $teacher_error = 'Password must be at least 6 characters long.';
    } else {
        $exists_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($exists_stmt) {
            $exists_stmt->bind_param('s', $new_username);
            $exists_stmt->execute();
            $already_exists = $exists_stmt->get_result()->num_rows > 0;
            $exists_stmt->close();

            if ($already_exists) {
                $teacher_error = 'Username already exists.';
            }
        } else {
            $teacher_error = 'Unable to validate account right now.';
        }

        if ($teacher_error === '') {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $create_stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'teacher')");
            if ($create_stmt) {
                $create_stmt->bind_param('sss', $new_name, $new_username, $password_hash);
                if ($create_stmt->execute()) {
                    $teacher_success = 'Teacher account created successfully.';
                } else {
                    $teacher_error = 'Unable to create teacher account right now.';
                }
                $create_stmt->close();
            } else {
                $teacher_error = 'Unable to create teacher account right now.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_teacher_classes'])) {
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $selected_classes = $_POST['class_codes'] ?? [];

    if ($teacher_id <= 0) {
        $assignment_error = 'Invalid teacher selected.';
    } else {
        if (!is_array($selected_classes)) {
            $selected_classes = [];
        }

        $normalized_classes = [];
        foreach ($selected_classes as $code) {
            $clean_code = strtolower(trim((string)$code));
            if ($clean_code !== '') {
                $normalized_classes[] = $clean_code;
            }
        }
        $normalized_classes = array_values(array_unique($normalized_classes));
        $class_str = implode(',', $normalized_classes);

        $teacher_stmt = $conn->prepare("SELECT id, class FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
        $teacher_stmt->bind_param('i', $teacher_id);
        $teacher_stmt->execute();
        $teacher_result = $teacher_stmt->get_result();

        if (!$teacher_result || $teacher_result->num_rows === 0) {
            $assignment_error = 'Teacher record was not found.';
        } else {
            $teacher_row = $teacher_result->fetch_assoc();
            $existing_classes = array_values(array_filter(array_map('trim', explode(',', (string)$teacher_row['class'])), function ($item) {
                return $item !== '';
            }));

            $merged_classes = array_values(array_unique(array_merge($existing_classes, $normalized_classes)));
            $class_str = implode(',', $merged_classes);

            $update_stmt = $conn->prepare("UPDATE users SET class = ? WHERE id = ? AND role = 'teacher'");
            if ($update_stmt) {
                $update_stmt->bind_param('si', $class_str, $teacher_id);
                if ($update_stmt->execute()) {
                    $assignment_success = 'Teacher classes updated successfully.';
                } else {
                    $assignment_error = 'Unable to update teacher classes right now.';
                }
                $update_stmt->close();
            } else {
                $assignment_error = 'Unable to update teacher classes right now.';
            }
        }
        $teacher_stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_teacher_classes'])) {
    $teacher_id = (int)($_POST['teacher_id_add'] ?? 0);
    $new_classes = $_POST['class_codes_add'] ?? [];

    if ($teacher_id <= 0) {
        $assignment_error = 'Invalid teacher selected.';
    } else {
        if (!is_array($new_classes)) {
            $new_classes = [];
        }

        $normalized_new = [];
        foreach ($new_classes as $code) {
            $clean_code = strtolower(trim((string)$code));
            if ($clean_code !== '') {
                $normalized_new[] = $clean_code;
            }
        }
        $normalized_new = array_values(array_unique($normalized_new));

        $teacher_stmt = $conn->prepare("SELECT class FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
        if ($teacher_stmt) {
            $teacher_stmt->bind_param('i', $teacher_id);
            $teacher_stmt->execute();
            $teacher_result = $teacher_stmt->get_result();

            if (!$teacher_result || $teacher_result->num_rows === 0) {
                $assignment_error = 'Teacher record was not found.';
            } else {
                $teacher_row = $teacher_result->fetch_assoc();
                $existing_classes = array_values(array_filter(array_map('trim', explode(',', (string)$teacher_row['class'])), function ($item) {
                    return $item !== '';
                }));

                $merged_classes = array_values(array_unique(array_merge($existing_classes, $normalized_new)));
                $class_str = implode(',', $merged_classes);

                $update_stmt = $conn->prepare("UPDATE users SET class = ? WHERE id = ? AND role = 'teacher'");
                if ($update_stmt) {
                    $update_stmt->bind_param('si', $class_str, $teacher_id);
                    if ($update_stmt->execute()) {
                        $assignment_success = 'Class(es) added to teacher successfully.';
                    } else {
                        $assignment_error = 'Unable to add classes right now.';
                    }
                    $update_stmt->close();
                } else {
                    $assignment_error = 'Unable to add classes right now.';
                }
            }
            $teacher_stmt->close();
        } else {
            $assignment_error = 'Unable to update classes right now.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_teacher_class'])) {
    $teacher_id = (int)($_POST['teacher_id_remove'] ?? 0);
    $class_code_remove = strtolower(trim((string)($_POST['class_code_remove'] ?? '')));

    if ($teacher_id <= 0) {
        $assignment_error = 'Invalid teacher selected.';
    } elseif ($class_code_remove === '') {
        $assignment_error = 'Invalid class selected.';
    } else {
        $teacher_stmt = $conn->prepare("SELECT class FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
        if ($teacher_stmt) {
            $teacher_stmt->bind_param('i', $teacher_id);
            $teacher_stmt->execute();
            $teacher_result = $teacher_stmt->get_result();

            if (!$teacher_result || $teacher_result->num_rows === 0) {
                $assignment_error = 'Teacher record was not found.';
            } else {
                $teacher_row = $teacher_result->fetch_assoc();
                $existing_classes = array_values(array_filter(array_map('trim', explode(',', (string)$teacher_row['class'])), function ($item) {
                    return $item !== '';
                }));
                $updated_classes = array_values(array_filter($existing_classes, function ($item) use ($class_code_remove) {
                    return strtolower($item) !== $class_code_remove;
                }));

                $class_str = implode(',', $updated_classes);

                $update_stmt = $conn->prepare("UPDATE users SET class = ? WHERE id = ? AND role = 'teacher'");
                if ($update_stmt) {
                    $update_stmt->bind_param('si', $class_str, $teacher_id);
                    if ($update_stmt->execute()) {
                        $assignment_success = 'Class removed from teacher successfully.';
                    } else {
                        $assignment_error = 'Unable to remove class right now.';
                    }
                    $update_stmt->close();
                } else {
                    $assignment_error = 'Unable to remove class right now.';
                }
            }
            $teacher_stmt->close();
        } else {
            $assignment_error = 'Unable to update classes right now.';
        }
    }
}

$active_classes = [];
$classes_result = $time_conn->query("SELECT class_code, class_name, section_name FROM classes_catalog WHERE is_active = 1 ORDER BY class_name ASC");
while ($classes_result && $class_row = $classes_result->fetch_assoc()) {
    $active_classes[] = $class_row;
}

$class_name_map = [];
foreach ($active_classes as $class_item) {
    $class_name_map[strtolower((string)$class_item['class_code'])] = $class_item['class_name'];
}

$teachers = [];
$teacher_result = $conn->query("SELECT id, name, email, class FROM users WHERE role = 'teacher' ORDER BY name ASC");
while ($teacher_result && $teacher_row = $teacher_result->fetch_assoc()) {
    $teachers[] = $teacher_row;
}

$time_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Teacher Tab</title>
    <link rel="stylesheet" href="style.css?v=20260510">
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
                <li class="active"><a href="#">👩‍🏫 Teacher Tab</a></li>
                <li><a href="section_management.php">🗂 Section Management</a></li>
                <li><a href="manage_classes.php">🧩 Manage Classes</a></li>
                <li><a href="login.php">🚪 Logout</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <header class="top-bar">
                <div class="welcome-text">
                    <h1>Teacher Tab</h1>
                    <p class="class-info">Register teachers and assign multiple classes to them.</p>
                </div>
            </header>

            <div class="table-card teacher-history-card teacher-classes-overview-card" style="margin-bottom: 18px;">
                <h3 class="teacher-section-title">Teacher Classes Overview</h3>
                <table class="dashboard-table teacher-classes-overview-table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Assigned Classes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)) { ?>
                            <tr><td colspan="2" style="text-align: center;">No teachers found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($teachers as $teacher) { ?>
                                <?php
                                    $teacher_classes = array_values(array_filter(array_map('trim', explode(',', (string)$teacher['class'])), function ($item) {
                                        return $item !== '';
                                    }));
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($teacher['name']); ?></strong></td>
                                    <td>
                                        <?php if (!empty($teacher_classes)) { ?>
                                            <div class="teacher-class-chip-wrap">
                                                <?php foreach ($teacher_classes as $class_code) { ?>
                                                    <span class="teacher-class-chip">
                                                        <?php echo htmlspecialchars($class_name_map[strtolower($class_code)] ?? strtoupper($class_code)); ?>
                                                    </span>
                                                <?php } ?>
                                            </div>
                                        <?php } else { ?>
                                            <span style="color: #94a3b8;">No classes assigned</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card teacher-history-card" style="margin-bottom: 18px;">
                <h3 class="teacher-section-title">Teacher Registration</h3>
                <?php if ($teacher_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($teacher_error); ?></p>
                <?php } ?>
                <?php if ($teacher_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($teacher_success); ?></p>
                <?php } ?>
                <form method="post" class="teacher-filters-form" style="width: 100%; justify-content: flex-start;" autocomplete="off" id="teacher-reg-form">
                    <input type="text" name="new_teacher_name" placeholder="Full name" required autocomplete="off">
                    <input type="text" name="new_teacher_username" placeholder="Username" required autocomplete="off">
                    <input type="password" name="new_teacher_password" placeholder="Password" minlength="6" required autocomplete="new-password">
                    <button type="submit" name="create_teacher_account" class="teacher-update-btn">Create Teacher</button>
                </form>
            </div>

            <div class="table-card teacher-history-card" style="margin-bottom: 18px;">
                <h3 class="teacher-section-title">Add Class(es) to Teacher</h3>
                <?php if ($assignment_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($assignment_error); ?></p>
                <?php } ?>
                <?php if ($assignment_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($assignment_success); ?></p>
                <?php } ?>
                <form method="post" class="teacher-filters-form" style="width: 100%; justify-content: flex-start; flex-wrap: wrap;">
                    <select name="teacher_id_add" required style="flex: 1; min-width: 200px;">
                        <option value="" selected disabled>Select teacher</option>
                        <?php foreach ($teachers as $teacher) { ?>
                            <option value="<?php echo (int)$teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                        <?php } ?>
                    </select>
                    <details class="teacher-class-dropdown" aria-label="Class selection">
                        <summary class="teacher-class-summary">Choose classes</summary>
                        <div class="teacher-class-panel">
                            <?php foreach ($active_classes as $class_item) { ?>
                                <label class="teacher-class-option">
                                    <input type="checkbox" name="class_codes_add[]" value="<?php echo htmlspecialchars($class_item['class_code']); ?>">
                                    <span class="teacher-class-bullet"></span>
                                    <span class="teacher-class-name"><?php echo htmlspecialchars($class_item['class_name']); ?></span>
                                </label>
                            <?php } ?>
                        </div>
                    </details>
                    <button type="submit" name="add_teacher_classes" class="teacher-update-btn">Add Classes</button>
                </form>
                <p style="color: #666; font-size: 12px; margin-top: 10px;"><em>Note: Selected classes will be added without removing existing classes.</em></p>
            </div>

            <div class="table-card teacher-history-card" style="margin-bottom: 18px;">
                <h3 class="teacher-section-title">Remove Class from Teacher</h3>
                <?php if ($assignment_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($assignment_error); ?></p>
                <?php } ?>
                <?php if ($assignment_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($assignment_success); ?></p>
                <?php } ?>
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Email</th>
                            <th>Assigned Classes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)) { ?>
                            <tr><td colspan="3" style="text-align: center;">No teachers found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($teachers as $teacher) { ?>
                                <?php
                                    $teacher_classes = array_values(array_filter(array_map('trim', explode(',', (string)$teacher['class'])), function ($item) {
                                        return $item !== '';
                                    }));
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($teacher['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                    <td>
                                        <?php if (!empty($teacher_classes)) { ?>
                                            <?php foreach ($teacher_classes as $class_code) { ?>
                                                <form method="post" style="display: inline; margin-right: 6px;">
                                                    <input type="hidden" name="teacher_id_remove" value="<?php echo (int)$teacher['id']; ?>">
                                                    <input type="hidden" name="class_code_remove" value="<?php echo htmlspecialchars($class_code); ?>">
                                                    <button type="submit" name="remove_teacher_class" style="display: inline-block; background: #007bff; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; border: none; cursor: pointer; margin-bottom: 4px;">
                                                        <?php
                                                            $class_label = strtoupper($class_code);
                                                            foreach ($active_classes as $active_class) {
                                                                if (strtolower((string)$active_class['class_code']) === strtolower((string)$class_code)) {
                                                                    $class_label = $active_class['class_name'];
                                                                    break;
                                                                }
                                                            }
                                                            echo htmlspecialchars($class_label);
                                                        ?> ✕
                                                    </button>
                                                </form>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <span style="color: #999;">-</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card teacher-history-card">
                <h3 class="teacher-section-title">Set Teacher Classes</h3>
                <?php if ($assignment_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($assignment_error); ?></p>
                <?php } ?>
                <?php if ($assignment_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($assignment_success); ?></p>
                <?php } ?>

                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Username</th>
                            <th>Assigned Classes</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)) { ?>
                            <tr><td colspan="4" style="text-align: center;">No teachers found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($teachers as $teacher) { ?>
                                <?php
                                    $teacher_classes = array_values(array_filter(array_map('trim', explode(',', (string)$teacher['class'])), function ($item) {
                                        return $item !== '';
                                    }));
                                    $form_id = 'teacher-classes-form-' . (int)$teacher['id'];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($teacher['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                    <td>
                                        <details class="teacher-class-dropdown teacher-class-dropdown-inline">
                                            <summary class="teacher-class-summary">Choose classes</summary>
                                            <div class="teacher-class-panel">
                                                <?php foreach ($active_classes as $class_item) { ?>
                                                    <?php $class_code = $class_item['class_code']; ?>
                                                    <label class="teacher-class-option">
                                                        <input type="checkbox" name="class_codes[]" value="<?php echo htmlspecialchars($class_code); ?>" form="<?php echo htmlspecialchars($form_id); ?>" <?php if (in_array($class_code, $teacher_classes, true)) echo 'checked'; ?>>
                                                        <span class="teacher-class-bullet"></span>
                                                        <span class="teacher-class-name"><?php echo htmlspecialchars($class_item['class_name']); ?></span>
                                                    </label>
                                                <?php } ?>
                                            </div>
                                        </details>
                                    </td>
                                    <td style="text-align: right;">
                                        <form id="<?php echo htmlspecialchars($form_id); ?>" method="post" class="admin-assignment-form">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher['id']; ?>">
                                            <button type="submit" name="save_teacher_classes" class="teacher-update-btn">Save Classes</button>
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
    <script>
        // Clear form fields on page load and prevent autofill
        window.addEventListener('load', function() {
            const form = document.getElementById('teacher-reg-form');
            if (form) {
                form.reset();
                // Clear fields after a delay to override browser autofill
                setTimeout(function() {
                    form.reset();
                    form.querySelectorAll('input, select, textarea').forEach(field => {
                        field.value = '';
                    });
                }, 100);
            }
        });
        
        // Also prevent autofill on form focus
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('teacher-reg-form');
            if (form) {
                form.querySelectorAll('input').forEach(input => {
                    input.addEventListener('focus', function() {
                        this.value = '';
                    });
                });
            }
        });
    </script>
</body>
</html>
