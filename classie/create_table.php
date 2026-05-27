<?php
// Connection for time_db
require_once __DIR__ . '/config.php';
$time_conn = connect_time_db();
if ($time_conn->connect_error) {
    die("Time DB connection failed: " . $time_conn->connect_error);
}

$sql = "CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    class VARCHAR(50) NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_time TIME NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance_per_day (user_id, class, attendance_date),
    INDEX idx_class_date (class, attendance_date)
)";

if ($time_conn->query($sql) === TRUE) {
    echo "Table attendance created successfully in time_db";
} else {
    echo "Error creating table: " . $time_conn->error;
}

$activation_sql = "CREATE TABLE IF NOT EXISTS attendance_activation (
    class VARCHAR(50) NOT NULL,
    active_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (class, active_date)
)";

if ($time_conn->query($activation_sql) === TRUE) {
    echo "\nTable attendance_activation created successfully in time_db";
} else {
    echo "\nError creating attendance_activation table: " . $time_conn->error;
}

$classes_sql = "CREATE TABLE IF NOT EXISTS classes_catalog (
    class_code VARCHAR(50) PRIMARY KEY,
    class_name VARCHAR(120) NOT NULL,
    section_name VARCHAR(80) DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";

if ($time_conn->query($classes_sql) === TRUE) {
    echo "\nTable classes_catalog created successfully in time_db";
    $time_conn->query("INSERT IGNORE INTO classes_catalog (class_code, class_name, section_name) VALUES
        ('cs101', 'CS 101 - Programming', 'Section A'),
        ('math02', 'MATH 02 - Calculus', 'Section B'),
        ('hist01', 'HIST 01 - Philippines History', 'Section C')");
} else {
    echo "\nError creating classes_catalog table: " . $time_conn->error;
}

$time_conn->close();
?>