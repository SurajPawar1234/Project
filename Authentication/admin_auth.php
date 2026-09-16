<?php
require_once "auth.php";

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../member/member_home.php");
    exit();
}
?>