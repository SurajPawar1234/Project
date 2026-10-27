<?php
require_once "auth.php";

if ($_SESSION['Role'] !== 'Admin') {
    header("Location: ../member/member_home.php");
    exit();
}
?>