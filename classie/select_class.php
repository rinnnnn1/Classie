<?php
session_start();
require_once "config.php";

$student_session = $_SESSION['auth']['student'] ?? null;
if ((!is_array($student_session) || empty($student_session['id'])) && isset($_SESSION['user_id'])) {
    $legacy_user_id = (int)$_SESSION['user_id'];
    if ($legacy_user_id > 0) {
        $legacy_stmt = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
        if ($legacy_stmt) {
            $legacy_stmt->bind_param('i', $legacy_user_id);
            $legacy_stmt->execute();
            $legacy_result = $legacy_stmt->get_result();
            if ($legacy_result && $legacy_result->num_rows > 0) {
                $legacy_row = $legacy_result->fetch_assoc();
                if (($legacy_row['role'] ?? '') === 'student') {
                    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
                        $_SESSION['auth'] = [];
                    }
                    $_SESSION['auth']['student'] = [
                        'id' => (int)$legacy_row['id'],
                        'name' => $legacy_row['name'] ?? 'Student',
                    ];
                    $student_session = $_SESSION['auth']['student'];
                }
            }
            $legacy_stmt->close();
        }
    }
}

if (!is_array($student_session) || empty($student_session['id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$student_session['id'];
$student_name = $student_session['name'] ?? 'Student';
$class_options = [];
$select_error = '';

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

$time_conn->query("INSERT IGNORE INTO classes_catalog (class_code, class_name, section_name) VALUES
    ('cs101', 'CS 101 - Programming', 'Section A'),
    ('math02', 'MATH 02 - Calculus', 'Section B'),
    ('hist01', 'HIST 01 - Philippines History', 'Section C')");

$class_display_map = [];
$classes_result = $time_conn->query("SELECT class_code, class_name FROM classes_catalog WHERE is_active = 1 ORDER BY class_name ASC");
while ($classes_result && $class_row = $classes_result->fetch_assoc()) {
    $class_display_map[strtolower((string)$class_row['class_code'])] = $class_row['class_name'];
}

$user_stmt = $conn->prepare("SELECT name, role, class, section_id FROM users WHERE id = ? LIMIT 1");
if ($user_stmt) {
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_result && $user_result->num_rows > 0) {
        $user_row = $user_result->fetch_assoc();
        $student_name = $user_row['name'] ?? $student_name;
        $role = $user_row['role'] ?? 'student';
        if ($role !== 'student') {
            $user_stmt->close();
            if ($role === 'admin') {
                header("Location: admin.php");
            } else {
                header("Location: teacher.php");
            }
            exit();
        }

        $section_id = (int)($user_row['section_id'] ?? 0);
        if ($section_id > 0) {
            $section_classes_stmt = $time_conn->prepare("SELECT class_code, class_name FROM classes_catalog WHERE is_active = 1 AND (section_id = ? OR FIND_IN_SET(?, sections)) ORDER BY class_name ASC");
            if ($section_classes_stmt) {
                $section_id_str = (string)$section_id;
                $section_classes_stmt->bind_param('is', $section_id, $section_id_str);
                $section_classes_stmt->execute();
                $section_classes_result = $section_classes_stmt->get_result();
                while ($section_classes_result && $section_class_row = $section_classes_result->fetch_assoc()) {
                    $clean_code = strtolower(trim((string)$section_class_row['class_code']));
                    if ($clean_code !== '') {
                        $class_options[$clean_code] = $section_class_row['class_name'] ?: ($class_display_map[$clean_code] ?? strtoupper($clean_code));
                    }
                }
                $section_classes_stmt->close();
            }
        }

        // Backward compatibility: keep classes explicitly saved on users.class.
        $raw_classes = explode(',', (string)($user_row['class'] ?? ''));
        foreach ($raw_classes as $code) {
            $clean_code = strtolower(trim($code));
            if ($clean_code !== '') {
                $class_options[$clean_code] = $class_display_map[$clean_code] ?? strtoupper($clean_code);
            }
        }
    }
    $user_stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_student_class'])) {
    $selected_class = strtolower(trim($_POST['selected_class'] ?? ''));
    if ($selected_class === '' || !isset($class_options[$selected_class])) {
        $select_error = 'Please select a valid class first.';
    } else {
        $_SESSION['student_selected_class'] = $selected_class;
        header("Location: student.php");
        exit();
    }
}

$time_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Your Class - Classiee</title>
    <link rel="stylesheet" href="style.css?v=20260511">
</head>
<body class="auth-body">
    <div class="container auth-container">
        <div class="form-box auth-card active select-class-card">
            <div class="school-icon">🏫</div>
            <h2>Select Class</h2>
            <p class="select-class-subtitle"><?php echo htmlspecialchars($student_name); ?>, choose the class you want to attend first.</p>

            <?php if ($select_error !== '') { ?>
                <p class="error-message"><?php echo htmlspecialchars($select_error); ?></p>
            <?php } ?>

            <form action="select_class.php" method="post" class="select-class-form" autocomplete="off">
                <div class="class-list">
                    <div class="class-list-header">
                        <p class="class-list-title">Your Assigned Classes</p>
                        <span class="class-count"><?php echo count($class_options); ?> total</span>
                    </div>

                    <?php if (empty($class_options)) { ?>
                        <p class="class-list-empty">No classes assigned yet. Please contact your admin.</p>
                    <?php } else { ?>
                        <?php foreach ($class_options as $class_code => $class_name) { ?>
                            <label class="class-option" data-class-option>
                                <input type="radio" name="selected_class" value="<?php echo htmlspecialchars($class_code); ?>" required>
                                <span class="class-option-main"><?php echo htmlspecialchars($class_name); ?></span>
                                <span class="class-option-code"><?php echo strtoupper(htmlspecialchars($class_code)); ?></span>
                            </label>
                        <?php } ?>
                    <?php } ?>
                </div>

                <button type="submit" name="select_student_class" class="login-btn" <?php if (empty($class_options)) echo 'disabled'; ?>>Continue to Attendance</button>
            </form>
        </div>
    </div>

    <script>
        const optionLabels = document.querySelectorAll('[data-class-option]');
        optionLabels.forEach((label) => {
            const input = label.querySelector('input[type="radio"]');
            if (!input) return;

            const syncState = () => {
                label.classList.toggle('selected', input.checked);
            };

            input.addEventListener('change', () => {
                optionLabels.forEach((l) => l.classList.remove('selected'));
                syncState();
            });

            syncState();
        });
    </script>
</body>
</html>