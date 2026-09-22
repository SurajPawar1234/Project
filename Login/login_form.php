<?php

/* =========================================
   START SESSION
   ========================================= */

session_start();


/* =========================================
   DATABASE CONNECTION
   ========================================= */

require_once "../Connection/db.php";


/* =========================================
   PROCESS LOGIN ONLY WHEN FORM IS SUBMITTED
   ========================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    /* =========================================
       GET FORM DATA
       ========================================= */

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";


    /* =========================================
       CHECK EMPTY VALUES
       ========================================= */

    if ($username === "" || $password === "") {
        die("Please enter Username and Password.");
    }


    /* =========================================
       PHP USERNAME VALIDATION
       ========================================= */

    if (strlen($username) < 5 || strlen($username) > 25) {
        die("Username must be between 5 and 25 characters.");
    }

    if (!preg_match("/^[A-Za-z0-9@.]+$/", $username)) {
        die("Username can contain only letters, numbers, @ and .");
    }


    /* =========================================
       PHP PASSWORD VALIDATION
       ========================================= */

    if (strlen($password) < 5 || strlen($password) > 12) {
        die("Password must be between 5 and 12 characters.");
    }

    if (!preg_match("/^[A-Za-z0-9@.]+$/", $password)) {
        die("Password can contain only letters, numbers, @ and .");
    }


    /* =========================================
       CHECK USERNAME IN DATABASE
       ========================================= */

    $sql = "SELECT User_id, Username, Password, Role
            FROM login
            WHERE Username = ?";


    $stmt = $conn->prepare($sql);


    if (!$stmt) {
        die("Database query failed.");
    }


    $stmt->bind_param("s", $username);

    $stmt->execute();

    $result = $stmt->get_result();


    /* =========================================
       CHECK ACCOUNT
       ========================================= */

    if ($result->num_rows === 1) {


        $user = $result->fetch_assoc();


        /* =====================================
           CHECK PASSWORD
           ===================================== */

        if ($password === $user["Password"]) {


            /* =================================
               CREATE LOGIN SESSION
               ================================= */

            $_SESSION["User_id"] = $user["User_id"];
            $_SESSION["Username"] = $user["Username"];
            $_SESSION["Role"] = $user["Role"];


            /* =================================
               REDIRECT ACCORDING TO ROLE
               ================================= */

            if ($user["Role"] === "Admin") {

                header("Location: ../Admin/Admin_Home.html");
                exit();

            }


            elseif ($user["Role"] === "Member") {

                header("Location: ../Member/member_home.html");
                exit();

            }


            else {

                session_destroy();

                die("Invalid account role.");

            }

        }


        else {

            die("Invalid username or password.");

        }

    }


    else {

        die("Invalid username or password.");

    }


    $stmt->close();

}


$conn->close();

?>


<!DOCTYPE html>

<html>

<head>

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Login Form</title>


    <style>

        .show-password {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 15px;
        }


        body {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;

            font-family: Arial;

            min-height: 100vh;

            background-image: url("Images/library0.png");
            background-repeat: no-repeat;
            background-position: center;
            background-size: cover;
        }


        form {
            width: 270px;

            padding: 33px;

            text-align: center;

            border: 2px solid black;

            background-color: whitesmoke;
        }


        input[type="text"],
        input[type="password"] {

            padding: 8px;

            font-size: 16px;

            margin-bottom: 12px;

            width: 100%;

            box-sizing: border-box;
        }


        input[type="submit"] {

            font-size: 18px;

            background-color: white;

            color: black;

            padding: 10px 25px;

            cursor: pointer;
        }


        label {

            display: block;

            text-align: left;

            margin-bottom: -10px;
        }

    </style>

</head>


<body>


<h1>Login Form</h1>


<form method="post"
      onsubmit="return validateLogin()">


    <!-- USERNAME -->

    <label for="username">
        Username:
    </label>

    <br>

    <input
        type="text"
        id="username"
        name="username"

        minlength="5"
        maxlength="25"

        pattern="[A-Za-z0-9@.]+"

        title="Username can contain only letters, numbers, @ and ."

        placeholder="Enter Username"

        autocomplete="username"

        required
    >

    <br>
    <br>


    <!-- PASSWORD -->

    <label for="password">
        Password:
    </label>

    <br>

    <input
        type="password"
        id="password"
        name="password"

        minlength="5"
        maxlength="12"

        pattern="[A-Za-z0-9@.]+"

        title="Password can contain only letters, numbers, @ and . (No spaces allowed)"

        placeholder="Enter Password"

        autocomplete="current-password"

        required
    >


    <!-- SHOW PASSWORD -->

    <div class="show-password">

        <input
            type="checkbox"
            id="show"
            onclick="showpassword()"
        >

        <label for="show">
            Show Password
        </label>

    </div>


    <br>


    <!-- LOGIN BUTTON -->

    <input
        type="submit"
        id="submitbutton"
        value="Login"
    >


    <br>
    <br>


    <!-- LINKS -->

    <a href="ForgotPassword.html">
        Forgot Password?
    </a>

    <br>
    <br>

    <a href="Registration.html">
        Create New Account
    </a>


</form>



<script>


/* =========================================
   SHOW / HIDE PASSWORD
   ========================================= */

function showpassword() {

    var password =
        document.getElementById("password");


    if (password.type === "password") {

        password.type = "text";

    }

    else {

        password.type = "password";

    }

}



/* =========================================
   LOGIN JAVASCRIPT VALIDATION
   ========================================= */

function validateLogin() {


    /* GET VALUES */

    var username =
        document.getElementById("username").value.trim();

    var password =
        document.getElementById("password").value;


    /* =========================================
       USERNAME EMPTY
       ========================================= */

    if (username === "") {

        alert("Please enter your username.");

        document.getElementById("username").focus();

        return false;
    }


    /* =========================================
       USERNAME LENGTH
       ========================================= */

    if (username.length < 5 ||
        username.length > 25) {

        alert(
            "Username must be between 5 and 25 characters."
        );

        document.getElementById("username").focus();

        return false;
    }


    /* =========================================
       USERNAME CHARACTER VALIDATION
       ========================================= */

    var usernamePattern =
        /^[A-Za-z0-9@.]+$/;


    if (!usernamePattern.test(username)) {

        alert(
            "Username can contain only letters, numbers, @ and ."
        );

        document.getElementById("username").focus();

        return false;
    }


    /* =========================================
       PASSWORD EMPTY
       ========================================= */

    if (password === "") {

        alert("Please enter your password.");

        document.getElementById("password").focus();

        return false;
    }


    /* =========================================
       PASSWORD LENGTH
       ========================================= */

    if (password.length < 5 ||
        password.length > 12) {

        alert(
            "Password must be between 5 and 12 characters."
        );

        document.getElementById("password").focus();

        return false;
    }


    /* =========================================
       PASSWORD CHARACTER VALIDATION
       ========================================= */

    var passwordPattern =
        /^[A-Za-z0-9@.]+$/;


    if (!passwordPattern.test(password)) {

        alert(
            "Password can contain only letters, numbers, @ and . (No spaces allowed)"
        );

        document.getElementById("password").focus();

        return false;
    }


    /* =========================================
       VALIDATION SUCCESSFUL
       ========================================= */

    return true;

}

</script>


</body>

</html>