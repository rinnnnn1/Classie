<?php
session_start();

// Enable errors  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$error = [
    'login' => $_SESSION['login_error'] ?? '',
];

unset($_SESSION['login_error'], $_SESSION['active_form']);

function showError($error) {
    return !empty($error) ? '<p class="error-message">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>' : '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classie</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">
    <div class="container auth-container">
        <div class="form-box auth-card active" id="login-form">
            <form action="login_register.php" method="post" id="login-form-fields" autocomplete="off">
                <div class="school-icon">🏫</div>
                <h2>Classiee</h2>

                <?= showError($error['login']); ?>

                <p>Log In </p>
                <input type="text" name="email" placeholder="Email" required autocomplete="off">
                <input type="password" name="password" placeholder="Password" required autocomplete="new-password">
                <button class="login-btn" type="submit" name="login-btn">Log In</button>
            </form>
        </div>
    </div>
    <script>
        window.addEventListener('load', function() {
            const form = document.getElementById('login-form-fields');
            if (!form) {
                return;
            }

            form.reset();
            setTimeout(function() {
                const emailField = form.querySelector('input[name="email"]');
                const passwordField = form.querySelector('input[name="password"]');
                if (emailField) {
                    emailField.value = '';
                }
                if (passwordField) {
                    passwordField.value = '';
                }
            }, 100);
        });
    </script>
</body>
</html>