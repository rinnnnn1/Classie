<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once "config.php";

// Verify admin authentication
$admin_session = $_SESSION['auth']['admin'] ?? null;
if (!is_array($admin_session) || empty($admin_session['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
curl -v -X POST "https://your-app.railway.app/api_admin_teacher.php?action=login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"yourpassword"}'
$admin_id = (int)$admin_session['id'];
$action = $_GET['action'] ?? '';

// Verify admin role
$role_stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$role_stmt->bind_param('i', $admin_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();
if (!$role_result || $role_result->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Not an admin']);
    exit();
}
$role_row = $role_result->fetch_assoc();
if ($role_row['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Not an admin']);
    exit();
}
$role_stmt->close();

$time_conn = connect_time_db();

// Initialize classes catalog
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

// GET: List all teachers
if ($action === 'get_teachers') {
    $teachers = [];
    $result = $conn->query("SELECT id, name, email, class FROM users WHERE role = 'teacher' ORDER BY name ASC");
    while ($result && $row = $result->fetch_assoc()) {
        $teachers[] = $row;
    }

    $active_classes = [];
    $class_result = $time_conn->query("SELECT class_code, class_name, section_name FROM classes_catalog WHERE is_active = 1 ORDER BY class_name ASC");
    while ($class_result && $class_row = $class_result->fetch_assoc()) {
        $active_classes[] = $class_row;
    }

    echo json_encode(['success' => true, 'teachers' => $teachers, 'classes' => $active_classes]);
    exit();
}

// POST: Create teacher
if ($action === 'create_teacher') {
    $data = json_decode(file_get_contents('php://input'), true);
    $new_name = trim($data['name'] ?? '');
    $new_username = trim($data['username'] ?? '');
    $new_password = $data['password'] ?? '';

    if ($new_name === '' || $new_username === '' || $new_password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit();
    }

    if (strlen($new_password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        exit();
    }

    $exists_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $exists_stmt->bind_param('s', $new_username);
    $exists_stmt->execute();
    if ($exists_stmt->get_result()->num_rows > 0) {
        $exists_stmt->close();
        http_response_code(400);
        echo json_encode(['error' => 'Username already exists']);
        exit();
    }
    $exists_stmt->close();

    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $create_stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'teacher')");
    $create_stmt->bind_param('sss', $new_name, $new_username, $password_hash);
    if ($create_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Teacher created']);
        $create_stmt->close();
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create teacher']);
        $create_stmt->close();
    }
    exit();
}

// POST: Add classes to teacher
if ($action === 'add_classes') {
    $data = json_decode(file_get_contents('php://input'), true);
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_codes = $data['class_codes'] ?? [];

    if ($teacher_id <= 0 || !is_array($class_codes) || empty($class_codes)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit();
    }

    $normalized = [];
    foreach ($class_codes as $code) {
        $clean = strtolower(trim((string)$code));
        if ($clean !== '') {
            $normalized[] = $clean;
        }
    }
    $normalized = array_values(array_unique($normalized));

    $teacher_stmt = $conn->prepare("SELECT class FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
    $teacher_stmt->bind_param('i', $teacher_id);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Teacher not found']);
        $teacher_stmt->close();
        exit();
    }

    $teacher_row = $teacher_result->fetch_assoc();
    $existing = array_values(array_filter(array_map('trim', explode(',', (string)$teacher_row['class'])), fn($x) => $x !== ''));
    $merged = array_values(array_unique(array_merge($existing, $normalized)));
    $class_str = implode(',', $merged);

    $update_stmt = $conn->prepare("UPDATE users SET class = ? WHERE id = ? AND role = 'teacher'");
    $update_stmt->bind_param('si', $class_str, $teacher_id);
    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Classes added']);
        $update_stmt->close();
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add classes']);
        $update_stmt->close();
    }
    $teacher_stmt->close();
    exit();
}

// POST: Remove class from teacher
if ($action === 'remove_class') {
    $data = json_decode(file_get_contents('php://input'), true);
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_code = strtolower(trim((string)($data['class_code'] ?? '')));

    if ($teacher_id <= 0 || $class_code === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit();
    }

    $teacher_stmt = $conn->prepare("SELECT class FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
    $teacher_stmt->bind_param('i', $teacher_id);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Teacher not found']);
        $teacher_stmt->close();
        exit();
    }

    $teacher_row = $teacher_result->fetch_assoc();
    $existing = array_values(array_filter(array_map('trim', explode(',', (string)$teacher_row['class'])), fn($x) => $x !== ''));
    $updated = array_values(array_filter($existing, fn($x) => strtolower($x) !== $class_code));
    $class_str = implode(',', $updated);

    $update_stmt = $conn->prepare("UPDATE users SET class = ? WHERE id = ? AND role = 'teacher'");
    $update_stmt->bind_param('si', $class_str, $teacher_id);
    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Class removed']);
        $update_stmt->close();
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to remove class']);
        $update_stmt->close();
    }
    $teacher_stmt->close();
    exit();
}

// POST: Admin login
if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0 || ($row = $result->fetch_assoc()) && $row['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or not an admin']);
        $stmt->close();
        exit();
    }

    if (!password_verify($password, $row['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid password']);
        $stmt->close();
        exit();
    }

    $_SESSION['auth']['admin'] = ['id' => $row['id'], 'name' => $row['name']];
    echo json_encode(['success' => true, 'admin_id' => $row['id'], 'admin_name' => $row['name']]);
    $stmt->close();
    exit();
}

// POST: Logout
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out']);
    exit();
}

$time_conn->close();
http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
?>
