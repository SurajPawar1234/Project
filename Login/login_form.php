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
   FUNCTION: REDIRECT BACK TO LOGIN PAGE
   WITH POPUP MESSAGE
   ========================================= */

function redirectWithError($message)
{
    $url = $_SERVER["PHP_SELF"] . "?error=" . urlencode($message);
    header("Location: " . $url);
    exit();
}


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
       EMPTY FIELD VALIDATION
       ========================================= */

    if ($username === "" && $password === "") {
        redirectWithError("Please enter your username and password.");
    }

    if ($username === "") {
        redirectWithError("Please enter your username.");
    }

    if ($password === "") {
        redirectWithError("Please enter your password.");
    }


    /* =========================================
       USERNAME VALIDATION
       ========================================= */

    if (strlen($username) < 5 || strlen($username) > 25) {
        redirectWithError(
            "Username must be between 5 and 25 characters."
        );
    }


    /* Username must contain at least one letter */

    if (!preg_match("/[A-Za-z]/", $username)) {
        redirectWithError(
            "Username must contain at least one letter."
        );
    }


    /* Allowed characters */

    if (!preg_match("/^[A-Za-z0-9@.]+$/", $username)) {
        redirectWithError(
            "Username can contain only letters, numbers, @ and ."
        );
    }


    /* =========================================
       PASSWORD VALIDATION
       ========================================= */

    if (strlen($password) < 5 || strlen($password) > 25) {
        redirectWithError(
            "Password must be between 5 and 25 characters."
        );
    }


    /* First character must be uppercase */

    if (!preg_match("/^[A-Z]/", $password)) {
        redirectWithError(
            "Password must start with a capital letter."
        );
    }


    /* Password must contain at least one letter */

    if (!preg_match("/[A-Za-z]/", $password)) {
        redirectWithError(
            "Password must contain at least one letter."
        );
    }


    /* Allowed characters */

    if (!preg_match("/^[A-Za-z0-9@.]+$/", $password)) {
        redirectWithError(
            "Password can contain only letters, numbers, @ and . (No spaces allowed)"
        );
    }


    /* =========================================
       CHECK USERNAME IN DATABASE
       ========================================= */

    $sql = "SELECT User_id, Username, Password, Role
            FROM login
            WHERE Username = ?";

    $stmt = $conn->prepare($sql);


    if (!$stmt) {
        redirectWithError("Database query failed.");
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


            $stmt->close();
            $conn->close();


            /* =================================
               REDIRECT ACCORDING TO ROLE
               ================================= */

           if ($user["Role"] === "Admin") {

    echo "
    <script>
        alert('Login successful!');
        window.location.href = '../Admin/Admin_Home.html';
    </script>
    ";

    exit();
}


elseif ($user["Role"] === "Member") {

    echo "
    <script>
        alert('Login successful!');
        window.location.href = '../Member/member_home.html';
    </script>
    ";

    exit();
}


            else {

                session_destroy();

                header(
                    "Location: " .
                    $_SERVER["PHP_SELF"] .
                    "?error=" .
                    urlencode("Invalid account role.")
                );

                exit();
            }

        }


        else {

            $stmt->close();
            $conn->close();

            redirectWithError(
                "Invalid username or password."
            );
        }

    }


    else {

        $stmt->close();
        $conn->close();

        redirectWithError(
            "Invalid username or password."
        );
    }

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

            margin-bottom: 5px;

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


        .required {
            color: red;
        }


        /* =========================================
           SHOW PASSWORD
           ========================================= */

        .show-password {
            display: flex;
            align-items: center;
            gap: 5px;

            margin-top: 12px;
            margin-bottom: 15px;
        }


        .show-password input {
            width: auto;
            margin: 0;
        }


        .show-password label {
            margin: 0;
        }


        /* =========================================
           VALIDATION ERROR
           ========================================= */

        .error {
            color: red;

            font-size: 14px;

            text-align: left;

            display: block;

            margin-top: 3px;

            margin-bottom: 10px;
        }


        input.invalid {
            border: 2px solid red;
        }


        input.valid {
            border: 2px solid green;
        }

    </style>

</head>


<body>


<h1>Login Form</h1>


<form method="post"
      id="loginForm">


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

        title="Username must be 5–25 characters and contain at least one letter. Only letters, numbers, @ and . are allowed."

        placeholder="Enter Username"

        autocomplete="username"

        
    >


    <span
        class="error"
        id="usernameError">
    </span>


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
        maxlength="25"

        pattern="[A-Za-z0-9@.]+"

        title="Password must be 5–25 characters, start with a capital letter, contain letters, and contain no spaces."

        placeholder="Enter Password"

        autocomplete="current-password"

        
    >


    <span
        class="error"
        id="passwordError">
    </span>


    <!-- SHOW PASSWORD -->

    <div class="show-password">

        <input
            type="checkbox"
            id="show"
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

    <a href="Forgot_password.html">
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
   GET ELEMENTS
   ========================================= */

const form =
    document.getElementById("loginForm");

const usernameInput =
    document.getElementById("username");

const passwordInput =
    document.getElementById("password");


/* =========================================
   SET ERROR
   ========================================= */

function setError(input, errorId, message) {

    document.getElementById(errorId).textContent =
        message;

    input.classList.add("invalid");

    input.classList.remove("valid");
}


/* =========================================
   SET SUCCESS
   ========================================= */

function setSuccess(input, errorId) {

    document.getElementById(errorId).textContent =
        "";

    input.classList.remove("invalid");

    input.classList.add("valid");
}


/* =========================================
   VALIDATE USERNAME
   ========================================= */

function validateUsername() {

    const username =
        usernameInput.value.trim();


    /* Empty */

    if (username === "") {

        setError(
            usernameInput,
            "usernameError",
            "Please enter your username."
        );

        return false;
    }


    /* Length */

    if (
        username.length < 5 ||
        username.length > 25
    ) {

        setError(
            usernameInput,
            "usernameError",
            "Username must be between 5 and 25 characters."
        );

        return false;
    }


    /* At least one letter */

    if (!/[A-Za-z]/.test(username)) {

        setError(
            usernameInput,
            "usernameError",
            "Username must contain at least one letter."
        );

        return false;
    }


    /* Allowed characters */

    if (!/^[A-Za-z0-9@.]+$/.test(username)) {

        setError(
            usernameInput,
            "usernameError",
            "Username can contain only letters, numbers, @ and ."
        );

        return false;
    }


    setSuccess(
        usernameInput,
        "usernameError"
    );

    return true;
}


/* =========================================
   VALIDATE PASSWORD
   ========================================= */

function validatePassword() {

    const password =
        passwordInput.value;


    /* Empty */

    if (password === "") {

        setError(
            passwordInput,
            "passwordError",
            "Please enter your password."
        );

        return false;
    }


    /* Length */

    if (
        password.length < 5 ||
        password.length > 25
    ) {

        setError(
            passwordInput,
            "passwordError",
            "Password must be between 5 and 25 characters."
        );

        return false;
    }


    /* First character must be uppercase */

    if (!/^[A-Z]/.test(password)) {

        setError(
            passwordInput,
            "passwordError",
            "Password must start with a capital letter."
        );

        return false;
    }


    /* Must contain a letter */

    if (!/[A-Za-z]/.test(password)) {

        setError(
            passwordInput,
            "passwordError",
            "Password must contain at least one letter."
        );

        return false;
    }


    /* Allowed characters */

    if (!/^[A-Za-z0-9@.]+$/.test(password)) {

        setError(
            passwordInput,
            "passwordError",
            "Password can contain only letters, numbers, @ and . (No spaces allowed)"
        );

        return false;
    }


    setSuccess(
        passwordInput,
        "passwordError"
    );

    return true;
}


/* =========================================
   SHOW / HIDE PASSWORD
   ========================================= */

document
    .getElementById("show")
    .addEventListener(
        "change",
        function () {

            passwordInput.type =
                this.checked
                ? "text"
                : "password";

        }
    );


/* =========================================
   LIVE VALIDATION
   ========================================= */

usernameInput.addEventListener(
    "input",
    validateUsername
);


passwordInput.addEventListener(
    "input",
    validatePassword
);


/* =========================================
   FINAL FORM VALIDATION
   ========================================= */

form.addEventListener(
    "submit",
    function(event) {

        const usernameValid =
            validateUsername();

        const passwordValid =
            validatePassword();


        if (
            !usernameValid ||
            !passwordValid
        ) {

            event.preventDefault();
        }

    }
);


/* =========================================
   SERVER-SIDE LOGIN ERROR POPUP
   ========================================= */

<?php if (isset($_GET["error"]) && $_GET["error"] !== ""): ?>

window.addEventListener(
    "load",
    function() {

        const message =
            <?php echo json_encode($_GET["error"]); ?>;

        alert(message);


        /* Remove error from URL */

        window.history.replaceState(
            {},
            document.title,
            window.location.pathname
        );

    }
);

<?php endif; ?>


</script>


</body>

</html>