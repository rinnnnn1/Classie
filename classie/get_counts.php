<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once "config.php";

// Separate connection for time_db
$time_conn = connect_time_db();
if ($time_conn->connect_error) {
    die(json_encode(['error' => 'DB connection failed']));
}

$selected_class = $_GET['class'] ?? 'cs101';
$current_date = date('Y-m-d');

// Fetch thresholds from settings table (same as teacher.php)
$settings_query = $time_conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('on_time_threshold', 'late_threshold')");
$settings = [];
while ($row = $settings_query->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$on_time_threshold = $settings['on_time_threshold'] ?? '11:00:00';
$late_threshold = $settings['late_threshold'] ?? '12:00:00';

// Get all students from users_db
$students_query = $conn->query("SELECT id FROM users WHERE role = 'student'");

// Get attendance
$attendance_query = $time_conn->prepare("SELECT user_id, attendance_time FROM attendance WHERE class = ? AND attendance_date = ?");
$attendance_query->bind_param('ss', $selected_class, $current_date);
$attendance_query->execute();
$attendance_result = $attendance_query->get_result();
$attendance = [];
while ($row = $attendance_result->fetch_assoc()) {
    $attendance[$row['user_id']] = $row['attendance_time'];
}
$attendance_query->close();

$present_count = 0;
$late_count = 0;
$absent_count = 0;

while ($student = $students_query->fetch_assoc()) {
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
$conn->close();

header('Content-Type: application/json');
echo json_encode([
    'present' => $present_count,
    'late' => $late_count,
    'absent' => $absent_count
]);
?>