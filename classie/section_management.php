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
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS section_id INT NULL");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS sections VARCHAR(255) NULL");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS on_time_threshold VARCHAR(8) NOT NULL DEFAULT '11:00:00'");
$time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS late_threshold VARCHAR(8) NOT NULL DEFAULT '12:00:00'");

$conn->query("CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_code VARCHAR(30) NOT NULL UNIQUE,
    section_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$section_error = '';
$section_success = '';
$section_remove_error = '';
$section_remove_success = '';
$class_section_remove_error = '';
$class_section_remove_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_section'])) {
    $section_code = strtolower(trim($_POST['section_code'] ?? ''));
    $section_name = trim($_POST['section_name'] ?? '');

    if ($section_code === '' || $section_name === '') {
        $section_error = 'Section code and section name are required.';
    } elseif (!preg_match('/^[a-z0-9_\-]+$/', $section_code)) {
        $section_error = 'Section code can only contain lowercase letters, numbers, underscore, and dash.';
    } else {
        $section_stmt = $conn->prepare("INSERT INTO sections (section_code, section_name) VALUES (?, ?)");
        if ($section_stmt) {
            $section_stmt->bind_param('ss', $section_code, $section_name);
            if ($section_stmt->execute()) {
                $section_success = 'Section added successfully.';
            } else {
                $section_error = 'Unable to add section. Code may already exist.';
            }
            $section_stmt->close();
        } else {
            $section_error = 'Unable to add section right now.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_section'])) {
    $remove_section_id = (int)($_POST['remove_section_id'] ?? 0);

    if ($remove_section_id <= 0) {
        $section_remove_error = 'Invalid section selected.';
    } else {
        $section_stmt = $conn->prepare("SELECT id, section_name FROM sections WHERE id = ? AND is_active = 1 LIMIT 1");
        if (!$section_stmt) {
            $section_remove_error = 'Unable to validate section right now.';
        } else {
            $section_stmt->bind_param('i', $remove_section_id);
            $section_stmt->execute();
            $section_result = $section_stmt->get_result();

            if (!$section_result || $section_result->num_rows === 0) {
                $section_remove_error = 'Section record was not found or is already inactive.';
            } else {
                $section_row = $section_result->fetch_assoc();

                $deactivate_stmt = $conn->prepare("UPDATE sections SET is_active = 0 WHERE id = ? LIMIT 1");
                $clear_students_stmt = $conn->prepare("UPDATE users SET section_id = NULL WHERE section_id = ?");
                $classes_result = $time_conn->query("SELECT class_code, section_id, sections, section_name, class_name FROM classes_catalog WHERE is_active = 1");

                $related_classes = [];
                while ($classes_result && $class_row = $classes_result->fetch_assoc()) {
                    $related_classes[] = $class_row;
                }

                $update_classes_stmt = $time_conn->prepare("UPDATE classes_catalog SET section_id = ?, section_name = ?, sections = ? WHERE class_code = ? LIMIT 1");

                if ($deactivate_stmt && $clear_students_stmt && $update_classes_stmt) {
                    $deactivate_stmt->bind_param('i', $remove_section_id);
                    $clear_students_stmt->bind_param('i', $remove_section_id);

                    $deactivate_ok = $deactivate_stmt->execute();
                    $clear_students_ok = $clear_students_stmt->execute();

                    if ($deactivate_ok && $clear_students_ok) {
                        foreach ($related_classes as $class_item) {
                            $existing_sections = [];
                            if (!empty($class_item['sections'])) {
                                $existing_sections = array_filter(array_map('intval', explode(',', $class_item['sections'])));
                            } elseif (!empty($class_item['section_id'])) {
                                $existing_sections = [(int)$class_item['section_id']];
                            }

                            $remaining_sections = array_values(array_diff($existing_sections, [$remove_section_id]));
                            if (empty($remaining_sections)) {
                                continue;
                            }

                            $remaining_section_ids = implode(',', $remaining_sections);
                            $new_primary_id = (int)$remaining_sections[0];
                            $new_primary_name = $class_item['class_name'];

                            $section_name_stmt = $conn->prepare("SELECT section_name FROM sections WHERE id = ? AND is_active = 1 LIMIT 1");
                            if ($section_name_stmt) {
                                $section_name_stmt->bind_param('i', $new_primary_id);
                                $section_name_stmt->execute();
                                $section_name_result = $section_name_stmt->get_result();
                                if ($section_name_result && $section_name_result->num_rows > 0) {
                                    $section_name_row = $section_name_result->fetch_assoc();
                                    $new_primary_name = $section_name_row['section_name'];
                                }
                                $section_name_stmt->close();
                            }

                            $update_classes_stmt->bind_param('isss', $new_primary_id, $new_primary_name, $remaining_section_ids, $class_item['class_code']);
                            $update_classes_stmt->execute();
                        }

                        $section_remove_success = 'Section removed successfully.';
                    } else {
                        $section_remove_error = 'Unable to remove section right now.';
                    }

                    $deactivate_stmt->close();
                    $clear_students_stmt->close();
                    $update_classes_stmt->close();
                } else {
                    $section_remove_error = 'Unable to prepare section removal right now.';
                }
            }

            $section_stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_class_section'])) {
    $remove_class_code = strtolower(trim($_POST['remove_class_code'] ?? ''));
    $remove_section_id = (int)($_POST['remove_class_section_id'] ?? 0);

    if ($remove_class_code === '' || $remove_section_id <= 0) {
        $class_section_remove_error = 'Invalid class or section selected.';
    } else {
        $class_stmt = $time_conn->prepare("SELECT class_code, class_name, section_id, sections FROM classes_catalog WHERE class_code = ? AND is_active = 1 LIMIT 1");
        if (!$class_stmt) {
            $class_section_remove_error = 'Unable to validate class right now.';
        } else {
            $class_stmt->bind_param('s', $remove_class_code);
            $class_stmt->execute();
            $class_result = $class_stmt->get_result();

            if (!$class_result || $class_result->num_rows === 0) {
                $class_section_remove_error = 'Class record was not found or is inactive.';
            } else {
                $class_row = $class_result->fetch_assoc();
                $current_sections = [];
                if (!empty($class_row['sections'])) {
                    $current_sections = array_values(array_filter(array_map('intval', explode(',', $class_row['sections']))));
                } elseif (!empty($class_row['section_id'])) {
                    $current_sections = [(int)$class_row['section_id']];
                }

                if (!in_array($remove_section_id, $current_sections, true)) {
                    $class_section_remove_error = 'That section is not attached to this class.';
                } else {
                    $remaining_sections = array_values(array_diff($current_sections, [$remove_section_id]));
                    $updated_sections = !empty($remaining_sections) ? implode(',', $remaining_sections) : '';
                    $new_primary_id = !empty($remaining_sections) ? (int)$remaining_sections[0] : 0;
                    $new_primary_name = !empty($remaining_sections) ? $class_row['class_name'] : '';

                    if (!empty($remaining_sections)) {
                        $primary_stmt = $conn->prepare("SELECT section_name FROM sections WHERE id = ? AND is_active = 1 LIMIT 1");
                        if ($primary_stmt) {
                            $primary_stmt->bind_param('i', $new_primary_id);
                            $primary_stmt->execute();
                            $primary_result = $primary_stmt->get_result();
                            if ($primary_result && $primary_result->num_rows > 0) {
                                $primary_row = $primary_result->fetch_assoc();
                                $new_primary_name = $primary_row['section_name'];
                            }
                            $primary_stmt->close();
                        }
                    }

                    $update_stmt = $time_conn->prepare("UPDATE classes_catalog SET section_id = ?, section_name = ?, sections = ? WHERE class_code = ? LIMIT 1");
                    if ($update_stmt) {
                        $update_stmt->bind_param('isss', $new_primary_id, $new_primary_name, $updated_sections, $remove_class_code);
                        if ($update_stmt->execute()) {
                            $class_section_remove_success = 'Section removed from class successfully.';
                        } else {
                            $class_section_remove_error = 'Unable to remove section from class right now.';
                        }
                        $update_stmt->close();
                    } else {
                        $class_section_remove_error = 'Unable to remove section from class right now.';
                    }
                }
            }

            $class_stmt->close();
        }
    }
}

$sections = [];
$sections_result = $conn->query("SELECT id, section_code, section_name, created_at FROM sections WHERE is_active = 1 ORDER BY section_name ASC");
while ($sections_result && $section_row = $sections_result->fetch_assoc()) {
    $sections[] = $section_row;
}

$classes = [];
$classes_result = $time_conn->query("SELECT class_code, class_name, section_name, sections, created_at FROM classes_catalog WHERE is_active = 1 ORDER BY section_name ASC, class_name ASC");
while ($classes_result && $class_row = $classes_result->fetch_assoc()) {
    $classes[] = $class_row;
}

$time_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Section Management - Classiee</title>
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
                <li><a href="admin_teacher.php">👩‍🏫 Teacher Tab</a></li>
                <li class="active"><a href="#">🗂 Section Management</a></li>
                <li><a href="manage_classes.php">🧩 Manage Classes</a></li>
                <li><a href="login.php">🚪 Logout</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <header class="top-bar">
                <div class="welcome-text">
                    <h1>Section Management</h1>
                    <p class="class-info">Manage sections and class-section mappings.</p>
                </div>
            </header>

            <div class="table-card teacher-history-card" style="margin-bottom: 18px;">
                <h3 class="teacher-section-title">Add Section</h3>
                <?php if ($section_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($section_error); ?></p>
                <?php } ?>
                <?php if ($section_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($section_success); ?></p>
                <?php } ?>
                <?php if ($section_remove_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($section_remove_error); ?></p>
                <?php } ?>
                <?php if ($section_remove_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($section_remove_success); ?></p>
                <?php } ?>
                <form method="post" class="teacher-filters-form" style="width: 100%; justify-content: flex-start;">
                    <input type="text" name="section_code" placeholder="section code (e.g. sec-d)" required>
                    <input type="text" name="section_name" placeholder="section name (e.g. Section D)" required>
                    <button type="submit" name="add_section" class="teacher-update-btn">Add Section</button>
                </form>
            </div>

            <div class="table-card teacher-history-card" style="margin-bottom: 18px;">
                <h3 class="teacher-section-title">Active Sections</h3>
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Section Code</th>
                            <th>Section Name</th>
                            <th>Created</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sections)) { ?>
                            <tr><td colspan="4" style="text-align: center;">No sections found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($sections as $section) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($section['section_code']); ?></td>
                                    <td><?php echo htmlspecialchars($section['section_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($section['created_at'])); ?></td>
                                    <td style="text-align: right;">
                                        <form method="post" onsubmit="return confirm('Remove this section? It will be deactivated and removed from related classes and students.');" style="display: inline;">
                                            <input type="hidden" name="remove_section_id" value="<?php echo (int)$section['id']; ?>">
                                            <button type="submit" name="remove_section" class="teacher-update-btn" style="background: #dc2626;">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card teacher-history-card">
                <h3 class="teacher-section-title">Active Classes by Section</h3>
                <?php if ($class_section_remove_error !== '') { ?>
                    <p class="teacher-inline-error"><?php echo htmlspecialchars($class_section_remove_error); ?></p>
                <?php } ?>
                <?php if ($class_section_remove_success !== '') { ?>
                    <p class="teacher-inline-success"><?php echo htmlspecialchars($class_section_remove_success); ?></p>
                <?php } ?>
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>Class Code</th>
                            <th>Class Name</th>
                            <th>Sections</th>
                            <th>Created</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($classes)) { ?>
                            <tr><td colspan="5" style="text-align: center;">No classes found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($classes as $class_item) { ?>
                                <?php
                                    $class_sections = [];
                                    if (!empty($class_item['sections'])) {
                                        $class_sections = array_values(array_filter(array_map('intval', explode(',', $class_item['sections']))));
                                    } elseif (!empty($class_item['section_id'])) {
                                        $class_sections = [(int)$class_item['section_id']];
                                    }
                                    $class_section_names = [];
                                    foreach ($class_sections as $sid) {
                                        foreach ($sections as $section) {
                                            if ((int)$section['id'] === (int)$sid) {
                                                $class_section_names[] = [
                                                    'id' => (int)$section['id'],
                                                    'name' => $section['section_name'],
                                                ];
                                                break;
                                            }
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($class_item['class_code']); ?></td>
                                    <td><?php echo htmlspecialchars($class_item['class_name']); ?></td>
                                    <td>
                                        <?php if (!empty($class_section_names)) { ?>
                                            <?php foreach ($class_section_names as $class_section) { ?>
                                                <form method="post" style="display: inline-block; margin-right: 6px; margin-bottom: 6px;">
                                                    <input type="hidden" name="remove_class_code" value="<?php echo htmlspecialchars($class_item['class_code']); ?>">
                                                    <input type="hidden" name="remove_class_section_id" value="<?php echo (int)$class_section['id']; ?>">
                                                    <button type="submit" name="remove_class_section" style="display: inline-block; background: #28a745; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; border: none; cursor: pointer;">
                                                        <?php echo htmlspecialchars($class_section['name']); ?> ✕
                                                    </button>
                                                </form>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php echo htmlspecialchars($class_item['section_name'] ?: 'General Section'); ?>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($class_item['created_at'])); ?></td>
                                    <td style="text-align: right;"></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
