<?php

    // Keep all attendance timestamps consistent across pages and DB writes.
    date_default_timezone_set('Asia/Manila');

    function fail_db_connection(string $dbName, string $dbType = 'database'): void {
        http_response_code(503);
        $message = sprintf(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>%s Unavailable</title><style>body{font-family:Segoe UI,Tahoma,sans-serif;background:#f6f8fb;color:#1f2937;margin:0;padding:32px}.card{max-width:700px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.08)}h1{margin-top:0;font-size:24px}p{line-height:1.5}.hint{margin-top:14px;color:#374151}</style></head><body><div class="card"><h1>%s is currently unavailable</h1><p>Could not connect to <strong>%s</strong>. Please make sure the service is running, then refresh this page.</p><p class="hint">If the connection details are custom, update environment variables in config.php.</p></div></body></html>',
            htmlspecialchars($dbType, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($dbType, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8')
        );
        echo $message;
        exit();
    }

    $db_driver = strtolower(getenv('DB_DRIVER') ?: getenv('DATABASE_DRIVER') ?: 'mysql');
    $use_mongo = in_array($db_driver, ['mongodb', 'mongo'], true) || filter_var(getenv('USE_MONGO'), FILTER_VALIDATE_BOOLEAN);

    $db_host = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: getenv('MYSQLHOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: getenv('MYSQL_USER') ?: getenv('MYSQLUSER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: getenv('MYSQL_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '123';
    $db_port = (int)(getenv('DB_PORT') ?: getenv('MYSQL_PORT') ?: getenv('MYSQLPORT') ?: 3306);
    $db_name = getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: getenv('MYSQL_DATABASE') ?: 'users_db';
    $time_db_name = getenv('TIME_DB') ?: getenv('DB_NAME_TIME') ?: getenv('DB_DATABASE_TIME') ?: getenv('TIME_DATABASE') ?: 'time_db';

    $mongo_uri = getenv('MONGO_URI') ?: getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017';
    $mongo_db_name = getenv('MONGO_DB') ?: getenv('MONGO_DATABASE') ?: 'users_db';
    $mongo_time_db_name = getenv('MONGO_TIME_DB') ?: getenv('MONGO_DATABASE_TIME') ?: 'time_db';

    if ($use_mongo) {
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }

        if (!class_exists('MongoDB\\Client')) {
            fail_db_connection('MongoDB client or extension', 'MongoDB');
        }

        try {
            $mongo = new MongoDB\Client($mongo_uri);
            $mongo_db = $mongo->selectDatabase($mongo_db_name);
            $mongo_time_db = $mongo->selectDatabase($mongo_time_db_name);
            $mongo_db->command(['ping' => 1]);
        } catch (Throwable $exception) {
            fail_db_connection($mongo_uri, 'MongoDB');
        }

        function get_mongo_client(): MongoDB\Client {
            global $mongo;
            return $mongo;
        }

        function get_mongo_db(): MongoDB\Database {
            global $mongo_db;
            return $mongo_db;
        }

        function get_mongo_time_db(): MongoDB\Database {
            global $mongo_time_db;
            return $mongo_time_db;
        }

        function get_mongo_collection(string $name, bool $timeDb = false): MongoDB\Collection {
            return $timeDb ? get_mongo_time_db()->selectCollection($name) : get_mongo_db()->selectCollection($name);
        }

        function connect_time_db(): MongoDB\Database {
            return get_mongo_time_db();
        }

        $conn = get_mongo_db();

        $usersCollection = get_mongo_collection('users');
        $usersCollection->createIndex(['email' => 1], ['unique' => true]);
        $sectionsCollection = get_mongo_collection('sections');
        $sectionsCollection->createIndex(['section_code' => 1], ['unique' => true]);
        $classesCollection = get_mongo_collection('classes_catalog');
        $classesCollection->createIndex(['class_code' => 1], ['unique' => true]);

        $sectionsCollection->updateOne(
            ['section_code' => 'sec-a'],
            ['$setOnInsert' => ['section_name' => 'Section A', 'is_active' => true, 'created_at' => new MongoDB\BSON\UTCDateTime()]],
            ['upsert' => true]
        );
        $sectionsCollection->updateOne(
            ['section_code' => 'sec-b'],
            ['$setOnInsert' => ['section_name' => 'Section B', 'is_active' => true, 'created_at' => new MongoDB\BSON\UTCDateTime()]],
            ['upsert' => true]
        );
        $sectionsCollection->updateOne(
            ['section_code' => 'sec-c'],
            ['$setOnInsert' => ['section_name' => 'Section C', 'is_active' => true, 'created_at' => new MongoDB\BSON\UTCDateTime()]],
            ['upsert' => true]
        );

        $admin_email = getenv('ADMIN_EMAIL') ?: '';
        $admin_password = getenv('ADMIN_PASSWORD') ?: '';
        $admin_name = getenv('ADMIN_NAME') ?: 'Administrator';

        if ($admin_email !== '' && $admin_password !== '') {
            $admin_email = trim($admin_email);
            $admin_name = trim($admin_name) ?: 'Administrator';
            $existingAdmin = $usersCollection->findOne(['role' => 'admin']);
            if ($existingAdmin === null) {
                $usersCollection->updateOne(
                    ['email' => $admin_email],
                    ['$setOnInsert' => [
                        'name' => $admin_name,
                        'email' => $admin_email,
                        'password' => password_hash($admin_password, PASSWORD_DEFAULT),
                        'role' => 'admin',
                        'created_at' => new MongoDB\BSON\UTCDateTime(),
                    ]],
                    ['upsert' => true]
                );
            }
        }

    } else {
        // Avoid raw mysqli exceptions from bubbling to the browser.
        mysqli_report(MYSQLI_REPORT_OFF);

        $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
        if ($conn->connect_error) {
            fail_db_connection($db_name, 'MySQL');
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

        $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('student','teacher','admin') NOT NULL DEFAULT 'student'");
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS section_id INT NULL");
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS sections VARCHAR(255) NULL");

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
    }

    function connect_time_db() {
        global $use_mongo, $db_host, $db_user, $db_pass, $db_port, $db_name, $time_db_name, $mongo_time_db;

        if ($use_mongo) {
            return $mongo_time_db;
        }

        $c = @new mysqli($db_host, $db_user, $db_pass, $time_db_name, $db_port);
        if ($c->connect_error) {
            if ($time_db_name !== $db_name) {
                $c = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
                if ($c->connect_error) {
                    fail_db_connection($time_db_name, 'MySQL');
                }
            } else {
                fail_db_connection($time_db_name, 'MySQL');
            }
        }

        return $c;
    }
?>