<?php
require_once "auth.php";

if ($_SESSION['role'] !== 'Member') {
    header("Location: ../admin/admin_home.php");
    exit();
}
?>