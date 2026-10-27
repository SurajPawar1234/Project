<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Locate the existing project database connection file. */
$conn = $conn ?? null;
$connectionLoaded = ($conn instanceof mysqli);
$baseDirectories = [__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))];
$connectionNames = [
    'Connection/db.php', 'connection/db.php',
    'Database/db.php', 'database/db.php',
    'Databse/db.php', 'db.php'
];

if (!$connectionLoaded) {
    foreach ($baseDirectories as $baseDirectory) {
        foreach ($connectionNames as $connectionName) {
            $connectionPath = $baseDirectory . DIRECTORY_SEPARATOR . $connectionName;
            if (!is_file($connectionPath)) {
                continue;
            }
            try {
                require_once $connectionPath;
            } catch (Throwable $connectionException) {
                error_log('Forgot password connection error: ' . $connectionException->getMessage());
            }
            if (isset($conn) && $conn instanceof mysqli) {
                $connectionLoaded = true;
                break 2;
            }
        }
    }
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$email = '';
$username = '';
$errors = [];
$formMessage = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($username === '' || strlen($username) < 5 || strlen($username) > 25 || !preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9@.]+$/', $username)) {
        $errors['username'] = 'Username must be 5–25 characters and use letters, numbers, @ or .';
    }

    /* Keep the same password rules as the current Member Registration page. */
    if (strlen($password) < 5 || strlen($password) > 25 || !preg_match('/^[A-Z][A-Za-z0-9@.]*$/', $password)) {
        $errors['password'] = 'Password must be 5–25 characters, start with a capital letter, and contain only letters, numbers, @ or .';
    }

    if ($confirmPassword === '' || $confirmPassword !== $password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!$errors && !$connectionLoaded) {
        $formMessage = 'The database connection could not be loaded. Check that Connection/db.php exists and defines $conn.';
    } elseif (!$errors) {
        try {
            /*
             * Verify that the provided email belongs to the same account as the username.
             * Member emails are stored in `member`; administrator emails are stored in `admin`.
             */
            $check = $conn->prepare(
                "SELECT l.User_id
                 FROM login AS l
                 LEFT JOIN member AS m ON m.User_id = l.User_id
                 LEFT JOIN admin AS a ON a.User_id = l.User_id
                 WHERE l.Username = ?
                   AND ((l.Role = 'Member' AND m.Email = ?) OR (l.Role = 'Admin' AND a.Email = ?))
                 LIMIT 1"
            );
            $check->bind_param('sss', $username, $email, $email);
            $check->execute();
            $check->bind_result($userId);
            $accountFound = $check->fetch();
            $check->close();

            if (!$accountFound) {
                $formMessage = 'The username and email address do not match an existing account. Please check both fields.';
            } else {
                /*
                 * The existing project login compares the stored password directly, so this
                 * reset keeps the current format for compatibility. Upgrade login and reset
                 * together to password_hash()/password_verify() before production use.
                 */
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare('UPDATE login SET Password = ? WHERE User_id = ?');
                    $update->bind_param('si', $password, $userId);
                    $update->execute();
                    $updatedRows = $update->affected_rows;
                    $update->close();
                    $conn->commit();

                    if ($updatedRows >= 0) {
                        $formMessage = 'Password updated successfully. You can now log in with your new password.';
                        $messageType = 'success';
                        $email = '';
                        $username = '';
                    } else {
                        $formMessage = 'The password could not be updated. Please try again.';
                    }
                } catch (Throwable $updateException) {
                    $conn->rollback();
                    throw $updateException;
                }
            }
        } catch (Throwable $databaseException) {
            error_log('Forgot password update error: ' . $databaseException->getMessage());
            $formMessage = 'A database error occurred. Please check the database connection and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password</title>
<style>
body {
    margin-top: 50px;
    font-family: Arial, sans-serif;
    text-align: center;
    background-image: url("background.jpg");
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
}
form {
    width: 450px;
    min-height: 480px;
    max-width: calc(100% - 32px);
    margin: auto;
    padding: 40px 30px;
    border: 2px solid black;
    background-color: white;
    border-radius: 8px;
    box-sizing: border-box;
}
h1 { margin-top: 30px; margin-bottom: 30px; color: #333; }
label { display: block; text-align: left; font-weight: bold; margin-bottom: 5px; }
input[type='email'], input[type="text"], input[type="password"] {
    padding: 10px;
    font-size: 16px;
    margin-bottom: 8px;
    width: 100%;
    box-sizing: border-box;
    background-color: #E8F0FE;
    border: 1px solid #aaa;
    border-radius: 4px;
}
input[type='email']:focus, input[type="text"]:focus, input[type="password"]:focus { border: 1px solid #555; outline: none; }
.field-error { color: red; font-size: 13px; text-align: left; display: block; margin: 0 0 10px; }
input.invalid { border: 2px solid red !important; }
.form-message { padding: 10px 12px; margin: 0 0 18px; border-radius: 4px; text-align: left; font-size: 14px; line-height: 1.4; }
.form-message.error { color: #9b1c1c; background: #fff0f0; border: 1px solid #e7b8b8; }
.form-message.success { color: #21663a; background: #edf8f0; border: 1px solid #b8dfc2; }
.show-password { display: flex; align-items: center; gap: 8px; margin: 10px 0 20px; text-align: left; }
.show-password input[type="checkbox"] { width: auto; margin: 0; }
.show-password label { display: inline; font-weight: normal; margin: 0; cursor: pointer; }
input[type="submit"] { font-size: 18px; background-color: #333; color: white; padding: 10px 25px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
input[type="submit"]:hover { background-color: #555; }
.back-login { display: inline-block; margin-top: 18px; color: #333; font-size: 14px; text-decoration: none; }
.back-login:hover { text-decoration: underline; }
@media (max-width: 520px) { body { margin-top: 20px; } form { padding: 28px 20px; } h1 { margin-top: 20px; } }
</style>
</head>
<body>
<h1>Reset Password</h1>
<form action="forgot_password.php" method="post" novalidate>
    <?php if ($formMessage !== ''): ?>
        <div class="form-message <?= h($messageType) ?>" role="status"><?= h($formMessage) ?></div>
    <?php endif; ?>

    <label for="email">Email:</label>
    <input type="email" id="email" name="email" minlength="5" maxlength="100" required
           value="<?= h($email) ?>" placeholder="Enter Email" autocomplete="email"
           class="<?= isset($errors['email']) ? 'invalid' : '' ?>" aria-describedby="emailError">
    <?php if (isset($errors['email'])): ?><span class="field-error" id="emailError"><?= h($errors['email']) ?></span><?php endif; ?>

    <label for="username">Username:</label>
    <input type="text" id="username" name="username" minlength="5" maxlength="25"
           pattern="(?=.*[A-Za-z])[A-Za-z0-9@.]+" required value="<?= h($username) ?>"
           placeholder="Enter Username" autocomplete="username"
           class="<?= isset($errors['username']) ? 'invalid' : '' ?>" aria-describedby="usernameError">
    <?php if (isset($errors['username'])): ?><span class="field-error" id="usernameError"><?= h($errors['username']) ?></span><?php endif; ?>

    <label for="password">New Password:</label>
    <input type="password" id="password" name="password" minlength="5" maxlength="25"
           pattern="[A-Z][A-Za-z0-9@.]*" title="Start with a capital letter; use letters, numbers, @ or . without spaces."
           placeholder="Create New Password" autocomplete="new-password" required
           class="<?= isset($errors['password']) ? 'invalid' : '' ?>" aria-describedby="passwordError">
    <?php if (isset($errors['password'])): ?><span class="field-error" id="passwordError"><?= h($errors['password']) ?></span><?php endif; ?>

    <label for="confirm_password">Confirm Password:</label>
    <input type="password" id="confirm_password" name="confirm_password" minlength="5" maxlength="25"
           pattern="[A-Z][A-Za-z0-9@.]*" title="Please re-enter your password."
           placeholder="Confirm New Password" autocomplete="new-password" required
           class="<?= isset($errors['confirm_password']) ? 'invalid' : '' ?>" aria-describedby="confirmPasswordError">
    <?php if (isset($errors['confirm_password'])): ?><span class="field-error" id="confirmPasswordError"><?= h($errors['confirm_password']) ?></span><?php endif; ?>

    <div class="show-password">
        <input type="checkbox" id="show">
        <label for="show">Show Passwords</label>
    </div>

    <input type="submit" id="submitbutton" value="Update Password">
    <a href="login_form.php" class="back-login">Back to Login</a>
</form>
<script>
(function () {
    const showCheckbox = document.getElementById('show');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    showCheckbox.addEventListener('change', function () {
        const type = this.checked ? 'text' : 'password';
        password.type = type;
        confirmPassword.type = type;
    });
})();
</script>
</body>
</html>
