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

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!is_array($student_session) || empty($student_session['id'])) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Please log in first.']);
        exit;
    }

    header("Location: login.php");
    exit();
}

// Separate connection for time_db
$time_conn = connect_time_db();
if ($time_conn->connect_error) {
    die("Time DB connection failed: " . $time_conn->connect_error);
}

$time_conn->query("CREATE TABLE IF NOT EXISTS attendance_activation (class VARCHAR(50) NOT NULL, active_date DATE NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (class, active_date))");
$time_conn->query("CREATE TABLE IF NOT EXISTS classes_catalog (class_code VARCHAR(50) PRIMARY KEY, class_name VARCHAR(120) NOT NULL, section_name VARCHAR(80) DEFAULT '', is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$time_conn->query("INSERT IGNORE INTO classes_catalog (class_code, class_name, section_name) VALUES
    ('cs101', 'CS 101 - Programming', 'Section A'),
    ('math02', 'MATH 02 - Calculus', 'Section B'),
    ('hist01', 'HIST 01 - Philippines History', 'Section C')");

$selected_class = $_POST['class'] ?? 'N/A';
$class_name = $selected_class;

$class_name_stmt = $time_conn->prepare("SELECT class_name FROM classes_catalog WHERE class_code = ? LIMIT 1");
$class_name_stmt->bind_param('s', $selected_class);
$class_name_stmt->execute();
$class_name_result = $class_name_stmt->get_result();
if ($class_name_result && $class_name_result->num_rows > 0) {
    $class_name_row = $class_name_result->fetch_assoc();
    $class_name = $class_name_row['class_name'];
}
$class_name_stmt->close();
$now = new DateTime('now');
$db_date = $now->format('Y-m-d');
$db_time = $now->format('H:i:s');
$attendance_date = $now->format('F j, Y');
$attendance_time = $now->format('h:i A');

$response = [
    'status' => 'error',
    'message' => 'Unable to record attendance.',
    'class_name' => $class_name,
    'attendance_date' => $attendance_date,
    'attendance_time' => $attendance_time
];

// Store in time_db
if (is_array($student_session) && !empty($student_session['id'])) {
    $user_id = (int)$student_session['id'];

    $activation_stmt = $time_conn->prepare("SELECT is_active FROM attendance_activation WHERE class = ? AND active_date = ? LIMIT 1");
    $activation_stmt->bind_param('ss', $selected_class, $db_date);
    $activation_stmt->execute();
    $activation_result = $activation_stmt->get_result();
    $is_active = 0;
    if ($activation_result && $activation_result->num_rows > 0) {
        $activation_row = $activation_result->fetch_assoc();
        $is_active = (int)$activation_row['is_active'];
    }
    $activation_stmt->close();

    if ($is_active !== 1) {
        $response['status'] = 'inactive';
        $response['message'] = 'Attendance is not active yet for this class. Please wait for your teacher.';
    } else {

        $check_stmt = $time_conn->prepare("SELECT attendance_time FROM attendance WHERE user_id = ? AND class = ? AND attendance_date = ? LIMIT 1");
        $check_stmt->bind_param('iss', $user_id, $selected_class, $db_date);
        $check_stmt->execute();
        $existing_result = $check_stmt->get_result();

        if ($existing_result && $existing_result->num_rows > 0) {
            $existing = $existing_result->fetch_assoc();
            $response['status'] = 'duplicate';
            $response['message'] = 'Attendance is already marked for this class today.';
            $response['attendance_time'] = date('h:i A', strtotime($existing['attendance_time']));
        } else {
            $stmt = $time_conn->prepare("INSERT INTO attendance (user_id, class, attendance_date, attendance_time) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $user_id, $selected_class, $db_date, $db_time);
            if ($stmt->execute()) {
                $response['status'] = 'success';
                $response['message'] = 'Attendance marked successfully.';
            }
            $stmt->close();
        }

        $check_stmt->close();
    }
} else {
    $response['message'] = 'Please log in first.';
}

$time_conn->close();

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Status</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="auth-body attendance-page">
    <div class="attendance-card">
        <div class="icon-circle">
            <i class="fas <?php echo $response['status'] === 'success' ? 'fa-check' : 'fa-info'; ?>"></i>
        </div>
        <h1><?php echo $response['status'] === 'success' ? 'Attendance Marked!' : 'Attendance Notice'; ?></h1>
        <p><?php echo htmlspecialchars($response['message']); ?></p>
        
        <div class="details">
            <span><strong>Class:</strong> <?php echo $class_name; ?></span>
            <span><strong>Date:</strong> <?php echo $attendance_date; ?></span>
            <span><strong>Time:</strong> <?php echo htmlspecialchars($response['attendance_time']); ?></span>
        </div>

        <button class="done-btn" name="done" onclick="window.location.href='student.php'">Back to Home</button>
    </div>
</body>
</html>