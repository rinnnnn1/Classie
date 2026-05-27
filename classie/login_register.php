<?php
session_start();
require_once "config.php";

// Enable errors for development (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Public registration is disabled.
if (isset($_POST['register-btn'])) {
    header("Location: login.php");
    exit();
}

// LOGIN
if (isset($_POST['login-btn'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
    if ($stmt === false) {
        $_SESSION['login_error'] = 'Server error: ' . $conn->error;
        $_SESSION['active_form'] = 'login';
        header("Location: login.php");
        exit();
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
                $_SESSION['auth'] = [];
            }

            $role_key = strtolower((string)($user['role'] ?? ''));
            $_SESSION['auth'][$role_key] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
            ];

            // Backward compatibility for pages still using legacy keys.
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['active_role'] = $role_key;
            $stmt->close();
            if ($user['role'] === 'teacher') {
                header("Location: teacher.php");
                exit();
            } elseif ($user['role'] === 'admin') {
                header("Location: admin.php");
                exit();
            } elseif ($user['role'] === 'student') {
                unset($_SESSION['student_selected_class']);
                header("Location: select_class.php");
                exit();
            }

            unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['active_role']);
            $_SESSION['login_error'] = 'Your account role is not configured. Please contact an administrator.';
            $_SESSION['active_form'] = 'login';
            header("Location: login.php");
            exit();
        }
    }
    $stmt->close();
    $_SESSION['login_error'] = 'Invalid email or password.';
    $_SESSION['active_form'] = 'login';
    header("Location: login.php");
    exit();
}
?>