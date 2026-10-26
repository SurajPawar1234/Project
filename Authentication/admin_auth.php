<?php
require_once "auth.php";

if ($_SESSION['role'] !== 'Admin') {
    header("Location: ../member/member_home.php");
    exit();
}
?>