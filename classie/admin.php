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

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS assigned_teacher_id INT NULL");

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

$assign_error = '';
$assign_success = '';
$account_error = '';
$account_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_student_account'])) {
    $new_name = trim($_POST['new_name'] ?? '');
    $new_username = trim($_POST['new_username'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $new_section_id = (int)($_POST['new_section_id'] ?? 0);
    $new_role = 'student';

    if ($new_name === '' || $new_username === '' || $new_password === '' || $new_section_id <= 0) {
        $account_error = 'Please fill in all account fields.';
    } elseif (strlen($new_password) < 6) {
        $account_error = 'Password must be at least 6 characters long.';
    } else {
        $section_exists_stmt = $conn->prepare("SELECT id FROM sections WHERE id = ? AND is_active = 1 LIMIT 1");
        if ($section_exists_stmt) {
            $section_exists_stmt->bind_param('i', $new_section_id);
            $section_exists_stmt->execute();
            $valid_section = $section_exists_stmt->get_result()->num_rows > 0;
            $section_exists_stmt->close();

            if (!$valid_section) {
                $account_error = 'Selected section is invalid.';
            }
        } else {
            $account_error = 'Unable to validate section right now.';
        }

        if ($account_error !== '') {
            // Skip account creation when section validation fails.
        } else {
        $exists_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($exists_stmt) {
            $exists_stmt->bind_param('s', $new_username);
            $exists_stmt->execute();
            $exists_exists = $exists_stmt->get_result()->num_rows > 0;
            $exists_stmt->close();

            if ($exists_exists) {
                $account_error = 'Username already exists.';
            }
        } else {
            $account_error = 'Unable to validate account right now.';
        }

        if ($account_error === '') {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $create_stmt = $conn->prepare("INSERT INTO users (name, email, password, role, section_id) VALUES (?, ?, ?, ?, ?)");
            if ($create_stmt) {
                $create_stmt->bind_param('ssssi', $new_name, $new_username, $password_hash, $new_role, $new_section_id);
                if ($create_stmt->execute()) {
                    $account_success = 'Student account created successfully.';
                } else {
                    $account_error = 'Unable to create account right now.';
                }
                $create_stmt->close();
            } else {
                $account_error = 'Unable to create account right now.';
            }
        }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student_section'])) {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $section_id_edit = (int)($_POST['section_id_edit'] ?? 0);

    if ($student_id <= 0) {
        $assign_error = 'Invalid student selected.';
    } elseif ($section_id_edit <= 0) {
        $assign_error = 'Please select a valid section.';
    } else {
        $student_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        if ($student_stmt) {
            $student_stmt->bind_param('i', $student_id);
            $student_stmt->execute();
            $student_exists = $student_stmt->get_result()->num_rows > 0;
            $student_stmt->close();

            if (!$student_exists) {
                $assign_error = 'Student record was not found.';
            }
        } else {
            $assign_error = 'Unable to validate student right now.';
        }

        if ($assign_error === '') {
            $section_stmt = $conn->prepare("SELECT id FROM sections WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($section_stmt) {
                $section_stmt->bind_param('i', $section_id_edit);
                $section_stmt->execute();
                $section_exists = $section_stmt->get_result()->num_rows > 0;
                $section_stmt->close();

                if (!$section_exists) {
                    $assign_error = 'Selected section is invalid.';
                }
            } else {
                $assign_error = 'Unable to validate section right now.';
            }
        }

        if ($assign_error === '') {
            $update_stmt = $conn->prepare("UPDATE users SET section_id = ? WHERE id = ? AND role = 'student'");
            if ($update_stmt) {
                $update_stmt->bind_param('ii', $section_id_edit, $student_id);
                if ($update_stmt->execute()) {
                    $assign_success = 'Student section updated successfully.';
                } else {
                    $assign_error = 'Unable to update section right now.';
                }
                $update_stmt->close();
            } else {
                $assign_error = 'Unable to update section right now.';
            }
        }
    }
}

$active_classes = [];
$classes_result = $time_conn->query("SELECT class_code, class_name, section_name FROM classes_catalog WHERE is_active = 1 ORDER BY class_name ASC");
while ($classes_result && $class_row = $classes_result->fetch_assoc()) {
    $active_classes[] = $class_row;
}

$sections = [];
$sections_result = $conn->query("SELECT id, section_code, section_name FROM sections WHERE is_active = 1 ORDER BY section_name ASC");
while ($sections_result && $section_row = $sections_result->fetch_assoc()) {
    $sections[] = $section_row;
}

$students = [];
$student_query = "
    SELECT s.id, s.name, s.email, s.class, s.section_id, sec.section_name AS section_name
    FROM users s
    LEFT JOIN sections sec ON sec.id = s.section_id
    WHERE s.role = 'student'
    ORDER BY s.name ASC
";
$student_result = $conn->query($student_query);
while ($student_result && $student_row = $student_result->fetch_assoc()) {
    $students[] = $student_row;
}

$time_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Student Assignment</title>
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
                <li class="active"><a href="#">🧑‍🎓 Student Tab</a></li>
                <li><a href="admin_teacher.php">👩‍🏫 Teacher Tab</a></li>
                <li><a href="section_management.php">🗂 Section Management</a></li>
                <li><a href="manage_classes.php">🧩 Manage Classes</a></li>
                <li><a href="login.php">🚪 Logout</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <header class="top-bar">
                <div class="welcome-text">
                    <h1>Student Tab</h1>
                    <p class="class-info">Register students and assign their classes.</p>
                </div>
            </header>

            <div class="table-card teacher-history-card" style="margin-bottom: 18px;">
                <h3 class="teacher-section-title">Student Registration</h3>
                <?php if ($account_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($account_error); ?></p>
                <?php } ?>
                <?php if ($account_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($account_success); ?></p>
                <?php } ?>
                <form method="post" class="teacher-filters-form" style="width: 100%; justify-content: flex-start;" autocomplete="off" id="student-reg-form">
                    <input type="text" name="new_name" placeholder="Full name" required autocomplete="off">
                    <input type="text" name="new_username" placeholder="Username" required autocomplete="off">
                    <input type="password" name="new_password" placeholder="Password" minlength="6" required autocomplete="new-password">
                    <select name="new_section_id" required>
                        <option value="" selected disabled>Select section</option>
                        <?php foreach ($sections as $section) { ?>
                            <option value="<?php echo (int)$section['id']; ?>"><?php echo htmlspecialchars($section['section_name']); ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" name="create_student_account" class="teacher-update-btn">Create Student</button>
                </form>
            </div>

            <div class="table-card teacher-history-card">
                <h3 class="teacher-section-title">Assignments</h3>
                <?php if ($assign_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($assign_error); ?></p>
                <?php } ?>
                <?php if ($assign_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($assign_success); ?></p>
                <?php } ?>

                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Email</th>
                            <th>Section</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)) { ?>
                            <tr><td colspan="4" style="text-align: center;">No students found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($students as $student) { ?>
                                <?php
                                    $form_id = 'section-form-' . (int)$student['id'];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($student['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                    <td>
                                        <select name="section_id_edit" class="admin-assignment-select" form="<?php echo htmlspecialchars($form_id); ?>" required>
                                            <option value="" disabled <?php if (empty($student['section_id'])) echo 'selected'; ?>>Select section</option>
                                            <?php foreach ($sections as $section) { ?>
                                                <option value="<?php echo (int)$section['id']; ?>" <?php if ((int)$student['section_id'] === (int)$section['id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($section['section_name']); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                    <td style="text-align: right;">
                                        <form id="<?php echo htmlspecialchars($form_id); ?>" method="post" class="admin-assignment-form">
                                            <input type="hidden" name="student_id" value="<?php echo (int)$student['id']; ?>">
                                            <button type="submit" name="save_student_section" class="teacher-update-btn">Save</button>
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
            const form = document.getElementById('student-reg-form');
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
            const form = document.getElementById('student-reg-form');
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
