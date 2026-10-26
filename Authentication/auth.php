<?php
session_start();

if (!isset($_SESSION['User_id'])) {
    header("Location: ../login/login_form.php");
    exit();
}
?>