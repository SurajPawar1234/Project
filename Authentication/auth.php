<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login_form.php");
    exit();
}
?>