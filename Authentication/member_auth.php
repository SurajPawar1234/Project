<?php
require_once "auth.php";

if ($_SESSION['role'] !== 'member') {
    header("Location: ../admin/admin_home.php");
    exit();
}
?>