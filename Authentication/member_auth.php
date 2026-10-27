<?php
require_once "auth.php";

if ($_SESSION['Role'] !== 'Member') {
    header("Location: ../admin/admin_home.php");
    exit();
}
?>