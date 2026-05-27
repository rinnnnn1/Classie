<?php

    // Keep all attendance timestamps consistent across pages and DB writes.
    date_default_timezone_set('Asia/Manila');

    // Avoid raw mysqli exceptions from bubbling to the browser.
    mysqli_report(MYSQLI_REPORT_OFF);

    function fail_db_connection(string $dbName): void {
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Database Unavailable</title><style>body{font-family:Segoe UI,Tahoma,sans-serif;background:#f6f8fb;color:#1f2937;margin:0;padding:32px}.card{max-width:700px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.08)}h1{margin-top:0;font-size:24px}p{line-height:1.5}.hint{margin-top:14px;color:#374151}</style></head><body><div class="card"><h1>Database is currently unavailable</h1><p>Could not connect to <strong>' . htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') . '</strong>. Please make sure MySQL is running in XAMPP, then refresh this page.</p><p class="hint">If MySQL uses a custom port, update DB_PORT (or the fallback port) in config.php.</p></div></body></html>';
        exit();
    }

    // DB credentials — set these as environment variables on Railway.
    // Falls back to XAMPP localhost defaults for local development.
    $db_host = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: getenv('MYSQLHOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: getenv('MYSQL_USER') ?: getenv('MYSQLUSER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: getenv('MYSQL_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '123';
    $db_port = (int)(getenv('DB_PORT') ?: getenv('MYSQL_PORT') ?: getenv('MYSQLPORT') ?: 3306);
    $db_name = getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: getenv('MYSQL_DATABASE') ?: 'users_db';
    $time_db_name = getenv('TIME_DB') ?: getenv('DB_NAME_TIME') ?: getenv('DB_DATABASE_TIME') ?: getenv('TIME_DATABASE') ?: 'time_db';

    $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
    if ($conn->connect_error) {
        fail_db_connection($db_name);
    }

    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('student','teacher','admin') NOT NULL DEFAULT 'student',
        section_id INT NULL,
        sections VARCHAR(255) NULL,
        class VARCHAR(255) NULL,
        assigned_teacher_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Optional admin bootstrap via environment variables for new Railway deployments.
    $admin_email = getenv('ADMIN_EMAIL') ?: '';
    $admin_password = getenv('ADMIN_PASSWORD') ?: '';
    $admin_name = getenv('ADMIN_NAME') ?: 'Administrator';
    if ($admin_email !== '' && $admin_password !== '') {
        $admin_email = trim($admin_email);
        $admin_name = trim($admin_name) ?: 'Administrator';
        $check_admin = $conn->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        if ($check_admin) {
            $check_admin->execute();
            $has_admin = $check_admin->get_result()->num_rows > 0;
            $check_admin->close();
            if (!$has_admin) {
                $admin_hash = password_hash($admin_password, PASSWORD_DEFAULT);
                $create_admin = $conn->prepare("INSERT IGNORE INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
                if ($create_admin) {
                    $create_admin->bind_param('sss', $admin_name, $admin_email, $admin_hash);
                    $create_admin->execute();
                    $create_admin->close();
                }
            }
        }
    }

    // Keep role schema compatible with admin routing.
    $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('student','teacher','admin') NOT NULL DEFAULT 'student'");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS section_id INT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS sections VARCHAR(255) NULL");
        // Keep time_db schema compatible with multi-section classes.
        $time_conn = connect_time_db();
        if (!$time_conn->connect_error) {
            $time_conn->query("ALTER TABLE classes_catalog ADD COLUMN IF NOT EXISTS sections VARCHAR(255) NULL");
            $time_conn->close();
        }

    $conn->query("CREATE TABLE IF NOT EXISTS sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_code VARCHAR(30) NOT NULL UNIQUE,
        section_name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("INSERT IGNORE INTO sections (section_code, section_name) VALUES
        ('sec-a', 'Section A'),
        ('sec-b', 'Section B'),
        ('sec-c', 'Section C')");

    // Helper: open a connection to time_db using the same credentials.
    function connect_time_db(): mysqli {
        global $db_host, $db_user, $db_pass, $db_port, $db_name, $time_db_name;
        $c = @new mysqli($db_host, $db_user, $db_pass, $time_db_name, $db_port);
        if ($c->connect_error) {
            if ($time_db_name !== $db_name) {
                $c = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
                if ($c->connect_error) {
                    fail_db_connection($time_db_name);
                }
            } else {
                fail_db_connection($time_db_name);
            }
        }
        return $c;
    }
?>