<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Locate the existing project database connection file. */
$conn = $conn ?? null;
$connectionLoaded = ($conn instanceof mysqli);
$baseDirectories = [__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))];
$connectionNames = [
    'Connection/db.php', 'connection/db.php',
    'Database/db.php', 'database/db.php', 'Databse/db.php',
    'db.php'
];

if (!$connectionLoaded) {
    foreach ($baseDirectories as $baseDirectory) {
        foreach ($connectionNames as $connectionName) {
            $connectionPath = $baseDirectory . DIRECTORY_SEPARATOR . $connectionName;
            if (is_file($connectionPath)) {
                try {
                    require_once $connectionPath;
                } catch (Throwable $connectionException) {
                    error_log('Registration database connection error: ' . $connectionException->getMessage());
                }
                if (isset($conn) && $conn instanceof mysqli) {
                    $connectionLoaded = true;
                    break 2;
                }
            }
        }
    }
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$values = [
    'first_name' => '', 'last_name' => '', 'gender' => '',
    'email' => '', 'phoneno' => '', 'address' => '', 'username' => ''
];
$fieldErrors = [
    'first_name' => '', 'last_name' => '', 'gender' => '', 'email' => '',
    'phoneno' => '', 'address' => '', 'username' => '', 'password' => '', 'confirm_password' => ''
];
$formError = '';
$registrationSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    foreach ($values as $field => $unused) {
        $values[$field] = trim((string)($_POST[$field] ?? ''));
    }
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (strlen($values['first_name']) < 2 || strlen($values['first_name']) > 25 || !preg_match('/^[A-Za-z]+$/', $values['first_name'])) {
        $fieldErrors['first_name'] = 'First name must contain 2–25 letters only.';
    }
    if (strlen($values['last_name']) < 2 || strlen($values['last_name']) > 25 || !preg_match('/^[A-Za-z]+$/', $values['last_name'])) {
        $fieldErrors['last_name'] = 'Last name must contain 2–25 letters only.';
    }
    if (!in_array($values['gender'], ['Male', 'Female', 'Other'], true)) {
        $fieldErrors['gender'] = 'Please select your gender.';
    }
    if ($values['email'] === '' || strlen($values['email']) > 30 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Please enter a valid email address (maximum 30 characters).';
    }
    if (!preg_match('/^[0-9]{10}$/', $values['phoneno'])) {
        $fieldErrors['phoneno'] = 'Phone number must contain exactly 10 digits.';
    }
    if (strlen($values['address']) < 10 || strlen($values['address']) > 200) {
        $fieldErrors['address'] = 'Address must contain 10–200 characters.';
    }
    if (strlen($values['username']) < 5 || strlen($values['username']) > 25 || !preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9@.]+$/', $values['username'])) {
        $fieldErrors['username'] = 'Username must be 5–25 characters and use letters, numbers, @ or .';
    }
    if (strlen($password) < 5 || strlen($password) > 25 || !preg_match('/^[A-Z][A-Za-z0-9@.]*$/', $password)) {
        $fieldErrors['password'] = 'Password must be 5–25 characters, start with a capital letter, and use only letters, numbers, @ or .';
    }
    if ($confirmPassword === '' || $confirmPassword !== $password) {
        $fieldErrors['confirm_password'] = 'Passwords do not match.';
    }

    $hasFieldErrors = count(array_filter($fieldErrors, static fn($message) => $message !== '')) > 0;

    if (!$hasFieldErrors && !$connectionLoaded) {
        $formError = 'The database connection could not be loaded. Check that Connection/db.php exists and defines $conn.';
    } elseif (!$hasFieldErrors) {
        $transactionStarted = false;
        try {
            $conn->begin_transaction();
            $transactionStarted = true;

            $usernameCheck = $conn->prepare('SELECT User_id FROM login WHERE Username = ? LIMIT 1');
            $usernameCheck->bind_param('s', $values['username']);
            $usernameCheck->execute();
            $usernameCheck->store_result();
            if ($usernameCheck->num_rows > 0) {
                $fieldErrors['username'] = 'This username is already registered. Please choose another.';
            }
            $usernameCheck->close();

            $memberEmailCheck = $conn->prepare('SELECT Member_id FROM member WHERE Email = ? LIMIT 1');
            $memberEmailCheck->bind_param('s', $values['email']);
            $memberEmailCheck->execute();
            $memberEmailCheck->store_result();
            $memberEmailExists = $memberEmailCheck->num_rows > 0;
            $memberEmailCheck->close();

            $adminEmailCheck = $conn->prepare('SELECT Admin_id FROM admin WHERE Email = ? LIMIT 1');
            $adminEmailCheck->bind_param('s', $values['email']);
            $adminEmailCheck->execute();
            $adminEmailCheck->store_result();
            $adminEmailExists = $adminEmailCheck->num_rows > 0;
            $adminEmailCheck->close();
            if ($memberEmailExists || $adminEmailExists) {
                $fieldErrors['email'] = 'This email address is already registered. Please use another.';
            }

            $hasFieldErrors = count(array_filter($fieldErrors, static fn($message) => $message !== '')) > 0;
            if ($hasFieldErrors) {
                $conn->rollback();
                $transactionStarted = false;
            } else {
                /*
                 * This project currently stores passwords in the format used by its existing login page.
                 * Upgrade registration and login together to password_hash()/password_verify() before production use.
                 */
                $role = 'Member';
                $loginInsert = $conn->prepare('INSERT INTO login (Username, Password, Role) VALUES (?, ?, ?)');
                $loginInsert->bind_param('sss', $values['username'], $password, $role);
                $loginInsert->execute();
                $userId = (int)$conn->insert_id;
                $loginInsert->close();

                $memberInsert = $conn->prepare(
                    'INSERT INTO member (User_id, First_name, Last_name, Gender, Email, Address, Phone_no, Current_Issue, Total_issue)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)'
                );
                $memberInsert->bind_param(
                    'issssss', $userId, $values['first_name'], $values['last_name'],
                    $values['gender'], $values['email'], $values['address'], $values['phoneno']
                );
                $memberInsert->execute();
                $memberInsert->close();
                $conn->commit();
                $transactionStarted = false;
                $registrationSuccess = true;
            }
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
            }
            error_log('Member registration failed: ' . $exception->getMessage());
            if ((int)$exception->getCode() === 1062) {
                $formError = 'This username or email is already registered. Please use different details.';
            } else {
                $formError = 'Registration could not be completed. Check the database connection and the login/member table structure.';
            }
        }
    }
}
?>



<!DOCTYPE html>

<html>



<head>

<title>Registration</title>



<style>



body {

    margin-top: 50px;

    font-family: Arial;

    text-align: center;

    background-image: url(Images/library0.png);

    background-size: cover;

}



form {

    width: 500px;

    margin: auto;

    padding: 30px;

    border: 2px solid black;

    background-color: white;

    border-radius: 8px;

}



h1 {

    margin-bottom: 30px;

    color: #333;

}



label {

    display: block;

    text-align: left;

    font-weight: bold;

    margin-bottom: 5px;

}



input,

select,

textarea {

    width: 100%;

    padding: 10px;

    font-size: 16px;

    margin-bottom: 15px;

    box-sizing: border-box;

    border: 1px solid #aaa;

    border-radius: 4px;

}



input:focus,

select:focus,

textarea:focus {

    border: 1px solid #555;

}



.form-server-error { margin: 0 0 15px; }

.error {

    color: red;

    font-size: 14px;

    text-align: left;

    display: block;

    margin-top: -10px;

    margin-bottom: 10px;

}



input.invalid,

select.invalid,

textarea.invalid {

    border: 2px solid red;

}



input.valid,

select.valid,

textarea.valid {

    border: 2px solid green;

}



.show-password {

    display: flex;

    align-items: center;

    text-align: left;

    margin-bottom: 15px;

}



.show-password input {

    width: auto;

    margin: 0 5px 0 0;

}



.show-password label {

    margin: 0;

}



input[type="submit"] {

    width: auto;

    padding: 10px 25px;

    font-size: 18px;

    background-color: #333;

    color: white;

    border: none;

    cursor: pointer;

}



input[type="submit"]:hover {

    background-color: #555;

}



/* Back to Login Button */



.back-login {

    display: inline-block;

    margin-top: 10px;

    padding: 10px 25px;

    font-size: 16px;

    background-color: #777;

    color: white;

    text-decoration: none;

    border-radius: 4px;

    border: none;

    cursor: pointer;

}





</style>



</head>



<body>



<h1>Registration</h1>



<form

    id="registrationForm"

    action="register.php"

    method="post"

    novalidate

>
<?php if ($formError !== ''): ?>
    <div class="error form-server-error" role="alert"><?= h($formError) ?></div>
<?php endif; ?>




    <!-- FIRST NAME -->



    <label for="first_name">First Name:</label>



    <input

        type="text"

        id="first_name"

        name="first_name" value="<?= h($values['first_name']) ?>"

        maxlength="25"

        placeholder="Enter First Name"

        required

    >



    <span class="error" id="firstNameError"><?= h($fieldErrors['first_name']) ?></span>





    <!-- LAST NAME -->



    <label for="last_name">Last Name:</label>



    <input

        type="text"

        id="last_name"

        name="last_name" value="<?= h($values['last_name']) ?>"

        maxlength="25"

        placeholder="Enter Last Name"

        required

    >



    <span class="error" id="lastNameError"><?= h($fieldErrors['last_name']) ?></span>





    <!-- GENDER -->



    <label for="gender">Gender:</label>



    <select id="gender" name="gender" required>



        <option value="" <?= $values['gender'] === '' ? 'selected' : '' ?> disabled>Select Gender</option>



        <option value="Male" <?= $values['gender'] === 'Male' ? 'selected' : '' ?>>

            Male

        </option>



        <option value="Female" <?= $values['gender'] === 'Female' ? 'selected' : '' ?>>

            Female

        </option>



        <option value="Other" <?= $values['gender'] === 'Other' ? 'selected' : '' ?>>

            Other

        </option>



    </select>



    <span class="error" id="genderError"><?= h($fieldErrors['gender']) ?></span>





    <!-- EMAIL -->



    <label for="email">Email:</label>



    <input

        type="email"

        id="email"

        name="email" value="<?= h($values['email']) ?>"

        maxlength="30"

        placeholder="Enter Email"

        required

    >



    <span class="error" id="emailError"><?= h($fieldErrors['email']) ?></span>





    <!-- PHONE -->



    <label for="phoneno">Phone Number:</label>



    <input

        type="tel"

        id="phoneno"

        name="phoneno" value="<?= h($values['phoneno']) ?>"

        maxlength="10"

        placeholder="Enter Mobile Number"

        required

    >



    <span class="error" id="phoneError"><?= h($fieldErrors['phoneno']) ?></span>





    <!-- ADDRESS -->



    <label for="address">Address:</label>



    <textarea

        id="address"

        name="address"

        rows="3"

        placeholder="Enter Full Address"

        required

    ><?= h($values['address']) ?></textarea>



    <span class="error" id="addressError"><?= h($fieldErrors['address']) ?></span>





    <!-- USERNAME -->



    <label for="username">Username:</label>



    <input

        type="text"

        id="username"

        name="username" value="<?= h($values['username']) ?>"

        minlength="5"

        maxlength="25"

        pattern="(?=.*[A-Za-z])[A-Za-z0-9\@.]+"

        title="Username must contain at least one letter and can contain letters, numbers, @ and ."

        placeholder="Enter New Username"

        required

    >



    <span class="error" id="usernameError"><?= h($fieldErrors['username']) ?></span>





    <!-- PASSWORD -->



    <label for="password">Password:</label>



    <input

        type="password"

        id="password"

        name="password"

        minlength="5"

        maxlength="25"

        pattern="[A-Z][A-Za-z0-9\@.]*"

        title="Password must start with a capital letter and can contain letters, numbers, @ and . (No spaces allowed)"

        placeholder="Create New Password"

        required

    >



    <span class="error" id="passwordError"><?= h($fieldErrors['password']) ?></span>





    <!-- CONFIRM PASSWORD -->



    <label for="confirm_password">

        Confirm Password:

    </label>



    <input

        type="password"

        id="confirm_password"

        name="confirm_password"

        minlength="5"

        maxlength="25"

        pattern="[A-Z][A-Za-z0-9\@.]*"

        title="Password must start with a capital letter and can contain letters, numbers, @ and . (No spaces allowed)"

        placeholder="Confirm Password"

        required

    >



    <span

        class="error"

        id="confirmPasswordError"

    ><?= h($fieldErrors['confirm_password']) ?></span>





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





    <input

        type="submit"

        value="Create Account"

        name="signup"

    >



        <!-- BACK TO LOGIN -->

    <br>

    <a

    href="login_form.php"

    class="back-login"

    >

    Back to Login

    </a>



</form>





<script>



const form = document.getElementById("registrationForm");



const firstNameInput =

    document.getElementById("first_name");



const lastNameInput =

    document.getElementById("last_name");



const genderInput =

    document.getElementById("gender");



const emailInput =

    document.getElementById("email");



const phoneInput =

    document.getElementById("phoneno");



const addressInput =

    document.getElementById("address");



const usernameInput =

    document.getElementById("username");



const passwordInput =

    document.getElementById("password");



const confirmPasswordInput =

    document.getElementById("confirm_password");





function setError(input, errorId, message) {



    document.getElementById(errorId).textContent = message;



    input.classList.add("invalid");



    input.classList.remove("valid");

}





function setSuccess(input, errorId) {



    document.getElementById(errorId).textContent = "";



    input.classList.remove("invalid");



    input.classList.add("valid");

}





/* FIRST NAME */



function validateFirstName() {



    const firstName =

        firstNameInput.value.trim();



    if (firstName === "") {



        setError(

            firstNameInput,

            "firstNameError",

            "Please enter your first name."

        );



        return false;

    }



    if (firstName.length < 2) {



        setError(

            firstNameInput,

            "firstNameError",

            "First name must contain at least 2 characters."

        );



        return false;

    }



    if (!/^[A-Za-z]+$/.test(firstName)) {



        setError(

            firstNameInput,

            "firstNameError",

            "First name can contain only letters."

        );



        return false;

    }



    setSuccess(

        firstNameInput,

        "firstNameError"

    );



    return true;

}





/* LAST NAME */



function validateLastName() {



    const lastName =

        lastNameInput.value.trim();



    if (lastName === "") {



        setError(

            lastNameInput,

            "lastNameError",

            "Please enter your last name."

        );



        return false;

    }



    if (lastName.length < 2) {



        setError(

            lastNameInput,

            "lastNameError",

            "Last name must contain at least 2 characters."

        );



        return false;

    }



    if (!/^[A-Za-z]+$/.test(lastName)) {



        setError(

            lastNameInput,

            "lastNameError",

            "Last name can contain only letters."

        );



        return false;

    }



    setSuccess(

        lastNameInput,

        "lastNameError"

    );



    return true;

}





/* GENDER */



function validateGender() {



    if (genderInput.value === "") {



        setError(

            genderInput,

            "genderError",

            "Please select your gender."

        );



        return false;

    }



    setSuccess(

        genderInput,

        "genderError"

    );



    return true;

}





/* EMAIL */



function validateEmail() {



    const email =

        emailInput.value.trim();



    const emailPattern =

        /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;



    if (email === "") {



        setError(

            emailInput,

            "emailError",

            "Please enter your email."

        );



        return false;

    }



    if (!emailPattern.test(email)) {



        setError(

            emailInput,

            "emailError",

            "Please enter a valid email address."

        );



        return false;

    }



    setSuccess(

        emailInput,

        "emailError"

    );



    return true;

}





/* PHONE */



function validatePhone() {



    const phone =

        phoneInput.value.trim();



    if (phone === "") {



        setError(

            phoneInput,

            "phoneError",

            "Please enter your phone number."

        );



        return false;

    }



    if (!/^[0-9]{10}$/.test(phone)) {



        setError(

            phoneInput,

            "phoneError",

            "Phone number must contain exactly 10 digits."

        );



        return false;

    }



    setSuccess(

        phoneInput,

        "phoneError"

    );



    return true;

}





/* ADDRESS */



function validateAddress() {



    const address =

        addressInput.value.trim();



    if (address === "") {



        setError(

            addressInput,

            "addressError",

            "Please enter your address."

        );



        return false;

    }



    if (address.length < 10) {



        setError(

            addressInput,

            "addressError",

            "Address must contain at least 10 characters."

        );



        return false;

    }



    setSuccess(

        addressInput,

        "addressError"

    );



    return true;

}





/* USERNAME */



function validateUsername() {



    const username =

        usernameInput.value.trim();



    if (username === "") {



        setError(

            usernameInput,

            "usernameError",

            "Please enter a username."

        );



        return false;

    }



    if (username.length < 5 || username.length > 25) {



        setError(

            usernameInput,

            "usernameError",

            "Username must be between 5 and 25 characters."

        );



        return false;

    }



    if (!/^(?=.*[A-Za-z])[A-Za-z0-9\@.]+$/.test(username)) {



        setError(

            usernameInput,

            "usernameError",

            "Username must contain at least one letter and can contain letters, numbers, @ and ."

        );



        return false;

    }



    setSuccess(

        usernameInput,

        "usernameError"

    );



    return true;

}





/* PASSWORD */



function validatePassword() {



    const password =

        passwordInput.value;



    if (password === "") {



        setError(

            passwordInput,

            "passwordError",

            "Please enter a password."

        );



        return false;

    }



    if (password.length < 5 || password.length > 25) {



        setError(

            passwordInput,

            "passwordError",

            "Password must be between 5 and 25 characters."

        );



        return false;

    }



    if (!/^[A-Z][A-Za-z0-9\@.]*$/.test(password)) {



        setError(

            passwordInput,

            "passwordError",

            "Password must start with a capital letter and can contain letters, numbers, @ and . (No spaces allowed)"

        );



        return false;

    }



    setSuccess(

        passwordInput,

        "passwordError"

    );



    return true;

}





/* CONFIRM PASSWORD */



function validateConfirmPassword() {



    const confirmPassword =

        confirmPasswordInput.value;



    if (confirmPassword === "") {



        setError(

            confirmPasswordInput,

            "confirmPasswordError",

            "Please confirm your password."

        );



        return false;

    }



    if (confirmPassword.length < 5 || confirmPassword.length > 25) {



        setError(

            confirmPasswordInput,

            "confirmPasswordError",

            "Password must be between 5 and 25 characters."

        );



        return false;

    }



    if (!/^[A-Z][A-Za-z0-9\@.]*$/.test(confirmPassword)) {



        setError(

            confirmPasswordInput,

            "confirmPasswordError",

            "Password must start with a capital letter and can contain letters, numbers, @ and . (No spaces allowed)"

        );



        return false;

    }



    if (

        confirmPassword !==

        passwordInput.value

    ) {



        setError(

            confirmPasswordInput,

            "confirmPasswordError",

            "Passwords do not match."

        );



        return false;

    }



    setSuccess(

        confirmPasswordInput,

        "confirmPasswordError"

    );



    return true;

}





/* SHOW PASSWORD */



document

.getElementById("show")

.addEventListener("change", function () {



    const type =

        this.checked

        ? "text"

        : "password";



    passwordInput.type = type;



    confirmPasswordInput.type = type;



});





/* LIVE VALIDATION */



firstNameInput.addEventListener(

    "input",

    validateFirstName

);



lastNameInput.addEventListener(

    "input",

    validateLastName

);



genderInput.addEventListener(

    "change",

    validateGender

);



emailInput.addEventListener(

    "input",

    validateEmail

);



phoneInput.addEventListener(

    "input",

    validatePhone

);



addressInput.addEventListener(

    "input",

    validateAddress

);



usernameInput.addEventListener(

    "input",

    validateUsername

);



passwordInput.addEventListener(

    "input",

    validatePassword

);



confirmPasswordInput.addEventListener(

    "input",

    validateConfirmPassword

);





/* FINAL FORM VALIDATION */



form.addEventListener(

    "submit",

    function(event) {



        const firstNameValid = validateFirstName();

        const lastNameValid = validateLastName();

        const genderValid = validateGender();

        const emailValid = validateEmail();

        const phoneValid = validatePhone();

        const addressValid = validateAddress();

        const usernameValid = validateUsername();

        const passwordValid = validatePassword();

        const confirmPasswordValid = validateConfirmPassword();



        const valid =

            firstNameValid &&

            lastNameValid &&

            genderValid &&

            emailValid &&

            phoneValid &&

            addressValid &&

            usernameValid &&

            passwordValid &&

            confirmPasswordValid;



        if (!valid) {

            event.preventDefault();

        }



    }

);



</script>




<?php if ($registrationSuccess): ?>
<script>
    alert("Account created successfully!");
    window.location.href = "login_form.php";
</script>
<?php endif; ?>
</body>

</html>
