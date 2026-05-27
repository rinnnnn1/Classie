<?php
session_start();
require_once "config.php";

header('Content-Type: application/json');

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
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = (int)$student_session['id'];
$current_date = date('Y-m-d');

$time_conn = connect_time_db();
if ($time_conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Time DB connection failed']);
    exit();
}

$time_conn->query("CREATE TABLE IF NOT EXISTS attendance_activation (class VARCHAR(50) NOT NULL, active_date DATE NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (class, active_date))");

$user_stmt = $conn->prepare("SELECT class FROM users WHERE id = ? LIMIT 1");
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_classes = [];

if ($user_result && $user_result->num_rows > 0) {
    $user_row = $user_result->fetch_assoc();
    $user_classes = array_values(array_filter(array_map('trim', explode(',', $user_row['class'] ?? '')), function ($class_code) {
        return $class_code !== '';
    }));
}
$user_stmt->close();

$activation_map = [];
if (!empty($user_classes)) {
    $activation_stmt = $time_conn->prepare("SELECT is_active FROM attendance_activation WHERE class = ? AND active_date = ? LIMIT 1");
    foreach ($user_classes as $class_code) {
        $activation_stmt->bind_param('ss', $class_code, $current_date);
        $activation_stmt->execute();
        $activation_result = $activation_stmt->get_result();

        $activation_map[$class_code] = false;
        if ($activation_result && $activation_result->num_rows > 0) {
            $activation_row = $activation_result->fetch_assoc();
            $activation_map[$class_code] = ((int)$activation_row['is_active'] === 1);
        }
    }
    $activation_stmt->close();
}

$time_conn->close();

// Keep response shape simple for client polling.
echo json_encode([
    'status' => 'ok',
    'activation_map' => $activation_map,
    'server_time' => date('c')
]);
