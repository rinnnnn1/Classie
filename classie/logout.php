<?php
session_start();
require_once "config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = '';
$user_classes = [];

$user_stmt = $conn->prepare("SELECT role, class FROM users WHERE id = ? LIMIT 1");
if ($user_stmt) {
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();

    if ($user_result && $user_result->num_rows > 0) {
        $user_row = $user_result->fetch_assoc();
        $role = $user_row['role'] ?? '';
        $user_classes = array_values(array_filter(array_map('trim', explode(',', $user_row['class'] ?? '')), function ($class_code) {
            return $class_code !== '';
        }));
    }

    $user_stmt->close();
}

if ($role === 'student' && !empty($user_classes)) {
    $current_date = date('Y-m-d');
    $time_conn = connect_time_db();

    if (!$time_conn->connect_error) {
        $time_conn->query("CREATE TABLE IF NOT EXISTS attendance_activation (class VARCHAR(50) NOT NULL, active_date DATE NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (class, active_date))");

        $activation_stmt = $time_conn->prepare("SELECT is_active FROM attendance_activation WHERE class = ? AND active_date = ? LIMIT 1");
        if ($activation_stmt) {
            foreach ($user_classes as $class_code) {
                $activation_stmt->bind_param('ss', $class_code, $current_date);
                $activation_stmt->execute();
                $activation_result = $activation_stmt->get_result();

                if ($activation_result && $activation_result->num_rows > 0) {
                    $activation_row = $activation_result->fetch_assoc();
                    if ((int)$activation_row['is_active'] === 1) {
                        $activation_stmt->close();
                        $time_conn->close();
                        header("Location: student.php?logout_blocked=1");
                        exit();
                    }
                }
            }
            $activation_stmt->close();
        }
        $time_conn->close();
    }
}

session_unset();
session_destroy();

header("Location: login.php");
exit();
