<?php
declare(strict_types=1);

/*
 * My Profile page.
 * Uses the existing Authentication/auth.php and Connection/db.php files.
 * Expected location: Project/Admin/my_profile.php
 */
require_once __DIR__ . '/../Authentication/auth.php';

// auth.php should start the session and verify that a user is logged in.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userIdValue = $_SESSION['user_id'] ?? $_SESSION['User_id'] ?? $_SESSION['userid'] ?? null;
$userRole = (string)($_SESSION['role'] ?? $_SESSION['Role'] ?? '');

if (!is_scalar($userIdValue) || !ctype_digit((string)$userIdValue) || (int)$userIdValue < 1) {
    header('Location: ../Login/login_form.php');
    exit;
}

if (strcasecmp($userRole, 'Admin') !== 0) {
    header('Location: ../Member/member_home.html');
    exit;
}

$userId = (int)$userIdValue;

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$profile = [
    'Username' => '',
    'Name' => '',
    'Email' => '',
    'Phone_no' => '',
    'Addres' => ''
];
$pageError = '';
$fieldErrors = [];
$postedProfile = [];
$showEditPopup = false;
$profileFlash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Reuse the project's established DB connection. Expected variable: $conn.
    require_once __DIR__ . '/../Connection/db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        foreach (['con', 'connection', 'mysqli', 'db'] as $connectionVariable) {
            if (isset(${$connectionVariable}) && ${$connectionVariable} instanceof mysqli) {
                $conn = ${$connectionVariable};
                break;
            }
        }
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Connection/db.php did not provide a mysqli connection in $conn.');
    }
    $conn->set_charset('utf8mb4');

    // Confirm this session belongs to an Admin account in the database.
    $loginStatement = $conn->prepare(
        'SELECT User_id, Username, Role FROM login WHERE User_id = ? LIMIT 1'
    );
    $loginStatement->bind_param('i', $userId);
    $loginStatement->execute();
    $loginAccount = $loginStatement->get_result()->fetch_assoc();
    $loginStatement->close();

    if (!$loginAccount || strcasecmp((string)$loginAccount['Role'], 'Admin') !== 0) {
        http_response_code(403);
        $pageError = 'Access denied. Please sign in with an admin account.';
    } else {
        $profile['Username'] = (string)$loginAccount['Username'];

        // Ensure the admin.User_id link exists in the current schema.
        $columnResult = $conn->query("SHOW COLUMNS FROM `admin` LIKE 'User_id'");
        if ($columnResult->num_rows === 0) {
            $pageError = 'Database setup is incomplete: add admin.User_id and link it to login.User_id in phpMyAdmin.';
        } else {
            // SELECT the current admin's existing profile, never the first Admin arbitrarily.
            $profileStatement = $conn->prepare(
                'SELECT Admin_id, Name, Email, Phone_no, Addres FROM admin WHERE User_id = ? LIMIT 1'
            );
            $profileStatement->bind_param('i', $userId);
            $profileStatement->execute();
            $savedProfile = $profileStatement->get_result()->fetch_assoc();
            $profileStatement->close();

            if ($savedProfile) {
                $profile['Name'] = (string)($savedProfile['Name'] ?? '');
                $profile['Email'] = (string)($savedProfile['Email'] ?? '');
                $profile['Phone_no'] = (string)($savedProfile['Phone_no'] ?? '');
                $profile['Addres'] = (string)($savedProfile['Addres'] ?? '');
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $showEditPopup = true;
                $postedProfile = [
                    'name' => trim((string)($_POST['name'] ?? '')),
                    'email' => trim((string)($_POST['email'] ?? '')),
                    'phone' => trim((string)($_POST['phone'] ?? '')),
                    'address' => trim((string)($_POST['address'] ?? ''))
                ];

                $submittedToken = (string)($_POST['csrf_token'] ?? '');
                if (!hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
                    $pageError = 'Form security validation failed. Reload the page and try again.';
                } else {
                    $name = $postedProfile['name'];
                    $email = $postedProfile['email'];
                    $phone = $postedProfile['phone'];
                    $address = $postedProfile['address'];

                    if ($name === '') {
                        $fieldErrors['name'] = 'Please enter your name.';
                    } elseif (text_length($name) < 2 || text_length($name) > 50) {
                        $fieldErrors['name'] = 'Name must be between 2 and 50 characters.';
                    } elseif (!preg_match("/^[\\p{L}\\p{M} .'-]+$/u", $name)) {
                        $fieldErrors['name'] = 'Name may contain letters, spaces, apostrophes, periods and hyphens.';
                    }

                    if ($email === '') {
                        $fieldErrors['email'] = 'Please enter your email address.';
                    } elseif (text_length($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fieldErrors['email'] = 'Please enter a valid email address.';
                    }

                    if ($phone === '') {
                        $fieldErrors['phone'] = 'Please enter your phone number.';
                    } elseif (!preg_match('/^[6-9][0-9]{9}$/', $phone)) {
                        $fieldErrors['phone'] = 'Phone number must be 10 digits and start with 6, 7, 8 or 9.';
                    }

                    if ($address === '') {
                        $fieldErrors['address'] = 'Please enter your address.';
                    } elseif (text_length($address) < 5 || text_length($address) > 200) {
                        $fieldErrors['address'] = 'Address must be between 5 and 200 characters.';
                    }

                    if (!$fieldErrors && $pageError === '') {
                        // Use a transaction: update existing row, or create a new one.
                        $conn->begin_transaction();
                        try {
                            $findStatement = $conn->prepare(
                                'SELECT Admin_id FROM admin WHERE User_id = ? LIMIT 1 FOR UPDATE'
                            );
                            $findStatement->bind_param('i', $userId);
                            $findStatement->execute();
                            $existingAdmin = $findStatement->get_result()->fetch_assoc();
                            $findStatement->close();

                            if ($existingAdmin) {
                                $saveStatement = $conn->prepare(
                                    'UPDATE admin SET Name = ?, Email = ?, Phone_no = ?, Addres = ? WHERE User_id = ?'
                                );
                                $saveStatement->bind_param('ssssi', $name, $email, $phone, $address, $userId);
                                $saveStatement->execute();
                                $saveStatement->close();
                            } else {
                                // Admin_id is not AUTO_INCREMENT in the supplied SQL dump.
                                $idResult = $conn->query('SELECT COALESCE(MAX(Admin_id), 0) + 1 AS next_id FROM admin');
                                $nextAdminId = (int)$idResult->fetch_assoc()['next_id'];
                                $saveStatement = $conn->prepare(
                                    'INSERT INTO admin (Admin_id, User_id, Name, Email, Phone_no, Addres) VALUES (?, ?, ?, ?, ?, ?)'
                                );
                                $saveStatement->bind_param('iissss', $nextAdminId, $userId, $name, $email, $phone, $address);
                                $saveStatement->execute();
                                $saveStatement->close();
                            }

                            $conn->commit();
                            $_SESSION['profile_flash'] = [
                                'type' => 'success',
                                'message' => 'Profile updated successfully.'
                            ];
                            header('Location: my_profile.php');
                            exit;
                        } catch (Throwable $saveException) {
                            $conn->rollback();
                            throw $saveException;
                        }
                    }
                }
            }
        }
    }
} catch (Throwable $exception) {
    error_log('My Profile database error: ' . $exception->getMessage());
    if ($pageError === '') {
        $pageError = 'Could not load or save profile data. Check Connection/db.php and confirm the admin.User_id relationship exists in the library database.';
    }
}
?>
<!DOCTYPE html>

<html lang="en">



<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">



    <title>My Profile - Library Management System</title>



    <style>



        /* =========================================

           BODY

           ========================================= */



        body {

            margin: 0;

            font-family: Arial, sans-serif;

            background-color: #f2f2f2;

        }





        /* =========================================

           LEFT SIDEBAR

           ========================================= */



        .sidebar {

            width: 220px;

            height: 100vh;

            background-color: #333;

            position: fixed;

            left: 0;

            top: 0;

            color: white;

        }





        .sidebar h2 {

            text-align: center;

            padding: 20px 0;

            margin: 0;

            background-color: #222;

        }





        .sidebar a,

        .manage {

            display: block;

            color: white;

            text-decoration: none;

            padding: 15px 20px;

            border-bottom: 1px solid #555;

            cursor: pointer;

        }





        .sidebar a:hover,

        .manage:hover {

            background-color: rgba(255, 255, 255, 0.11);

        }





        .arrow {

            float: right;

        }





        .submenu {

            display: none;

            background-color: #444;

        }





        .submenu a {

            padding-left: 40px;

        }





        /* =========================================

           LOGOUT

           ========================================= */



        .logout {

            position: absolute;

            bottom: 10px;

            width: 100%;

        }





        .logout a {

            background-color: #222;

            text-align: center;

        }





        /* =========================================

           MAIN CONTENT

           ========================================= */



        .main {

            margin-left: 220px;

            padding: 25px;

        }





        /* =========================================

           TOP HEADER

           ========================================= */



        .top {

            background-color: white;

            padding: 20px;

            border: 1px solid #ddd;

            text-align: center;

        }





        .top h1 {

            margin: 0;

            color: #333;

        }





        /* =========================================

           PROFILE CARD

           ========================================= */



        .profile-card {

            max-width: 700px;

            margin: 25px auto;

            background-color: white;

            padding: 25px;

            border: 1px solid #ddd;

        }





        .profile-card h2 {

            margin-top: 0;

            color: #333;

            border-bottom: 1px solid #ddd;

            padding-bottom: 12px;

        }





        /* =========================================

           PROFILE TABLE

           ========================================= */



        .profile-table {

            width: 100%;

            border-collapse: collapse;

        }





        .profile-table th,

        .profile-table td {

            padding: 14px;

            border: 1px solid #ccc;

            text-align: left;

        }





        .profile-table th {

            width: 35%;

            background-color: #eee;

            color: #333;

        }





        /* =========================================

           UPDATE PROFILE BUTTON

           ========================================= */



        .edit-button {

            display: block;

            width: fit-content;

            margin: 20px auto 0;

            padding: 12px 25px;

            background-color: #333;

            color: white;

            border: none;

            cursor: pointer;

            font-size: 16px;

        }





        .edit-button:hover {

            background-color: #555;

        }





        /* =========================================

           UPDATE PROFILE POPUP OVERLAY

           ========================================= */



        .popup-overlay {

            display: none;

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background-color: rgba(0, 0, 0, 0.55);

            align-items: center;

            justify-content: center;

            z-index: 1000;

        }





        /* =========================================

           UPDATE PROFILE POPUP

           ========================================= */



        .popup-box {

            width: 430px;

            max-width: 90%;

            background-color: white;

            padding: 25px;

            border: 2px solid #333;

            box-sizing: border-box;

            position: relative;

        }





        .popup-box h2 {

            margin-top: 0;

            text-align: center;

            color: #333;

            border-bottom: 1px solid #ddd;

            padding-bottom: 12px;

        }





        /* =========================================

           CLOSE BUTTON

           ========================================= */



        .close-button {

            position: absolute;

            top: 8px;

            right: 15px;

            font-size: 28px;

            color: #333;

            cursor: pointer;

            border: none;

            background: none;

        }





        .close-button:hover {

            color: red;

        }





        /* =========================================

           FORM GROUP

           ========================================= */



        .form-group {

            margin-bottom: 14px;

        }





        .form-group label {

            display: block;

            margin-bottom: 5px;

            font-weight: bold;

            color: #333;

        }





        .required {

            color: red;

        }





        /* =========================================

           INPUTS

           ========================================= */



        .form-group input,

        .form-group textarea {

            width: 100%;

            padding: 10px;

            box-sizing: border-box;

            font-size: 15px;

            border: 1px solid #999;

            outline: none;

        }





        .form-group textarea {

            height: 90px;

            resize: vertical;

        }





        /* =========================================

           INVALID

           ========================================= */



        .invalid {

            border: 2px solid red !important;

        }





        /* =========================================

           VALID

           ========================================= */



        .valid {

            border: 2px solid green !important;

        }





        /* =========================================

           ERROR MESSAGE

           ========================================= */



        .error {

            display: block;

            color: red;

            font-size: 13px;

            margin-top: 4px;

            min-height: 16px;

        }





        /* =========================================

           UPDATE BUTTON

           ========================================= */



        .update-button {

            width: 100%;

            padding: 12px;

            margin-top: 8px;

            background-color: #333;

            color: white;

            border: none;

            cursor: pointer;

            font-size: 16px;

        }





        .update-button:hover {

            background-color: #555;

        }





        /* =========================================

           SUCCESS POPUP

           ========================================= */



        .success-overlay {

            display: none;

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background-color: rgba(0, 0, 0, 0.55);

            align-items: center;

            justify-content: center;

            z-index: 2000;

        }





        .success-box {

            width: 350px;

            max-width: 90%;

            background-color: white;

            padding: 30px;

            text-align: center;

            border: 2px solid #333;

        }





        .success-box h3 {

            margin-top: 0;

            color: #333;

        }





        .success-box p {

            color: #444;

            font-size: 16px;

        }





        .success-button {

            padding: 10px 25px;

            background-color: #333;

            color: white;

            border: none;

            cursor: pointer;

            font-size: 15px;

        }





        .success-button:hover {

            background-color: #555;

        }





        /* =========================================

           NO CHANGE POPUP

           ========================================= */



        .info-overlay {

            display: none;

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background-color: rgba(0, 0, 0, 0.55);

            align-items: center;

            justify-content: center;

            z-index: 2000;

        }





        .info-box {

            width: 350px;

            max-width: 90%;

            background-color: white;

            padding: 30px;

            text-align: center;

            border: 2px solid #333;

        }





        .info-box h3 {

            margin-top: 0;

            color: #333;

        }





        .info-box p {

            color: #444;

            font-size: 16px;

        }





        .info-button {

            padding: 10px 25px;

            background-color: #333;

            color: white;

            border: none;

            cursor: pointer;

            font-size: 15px;

        }





        .info-button:hover {

            background-color: #555;

        }





        /* =========================================

           MOBILE

           ========================================= */



        @media (max-width: 800px) {



            .sidebar {

                width: 180px;

            }



            .main {

                margin-left: 180px;

            }



        }



    

        .server-message {
            margin: 0 0 12px;
            padding: 12px 15px;
            border: 1px solid #b30000;
            background: #fff;
            color: #b30000;
            font-size: 14px;
        }

        /* Finalized admin menu and page spacing; original layout remains intact. */
        .sidebar {
            overflow: hidden;
        }

        .sidebar-menu {
            max-height: calc(100vh - 135px);
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: thin;
            scrollbar-color: #666 #333;
        }

        .sidebar a,
        .manage {
            box-sizing: border-box;
        }

        .sidebar a.active,
        .manage.active-parent {
            background-color: #484848;
            box-shadow: inset 4px 0 0 #f2f2f2;
        }

        .submenu.open {
            display: block;
        }

        .logout {
            left: 0;
        }

        .main {
            padding: 10px 25px 25px;
        }

        .top {
            position: relative;
            min-height: 100px;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .top h1 {
            width: 100%;
            box-sizing: border-box;
        }

        @media (max-width: 800px) {
            .sidebar-menu {
                max-height: calc(100vh - 135px);
            }
            .top h1 {
                font-size: 24px;
            }
        }

    </style>



</head>





<body>





        <!-- =========================================
         SIDEBAR
         ========================================= -->
    <div class="sidebar">
        <h2>Library</h2>

        <nav class="sidebar-menu" aria-label="Admin menu">
            <a href="Admin_Home.html" data-page="Admin_Home.html">Dashboard</a>
            <a href="my_profile.php" data-page="my_profile.php">My Profile</a>
            <a href="library_profile.html" data-page="library_profile.html">Library Profile</a>

            <!-- Manage Books -->
            <div class="manage" id="manageToggle" role="button" tabindex="0"
                 aria-expanded="false" aria-controls="submenu">
                Manage Books
                <span class="arrow" id="arrow">+</span>
            </div>

            <div class="submenu" id="submenu">
                <a href="view_books.html" data-page="view_books.html">View All Books</a>
                <a href="add_book.html" data-page="add_book.html">Add Book</a>
                <a href="update_book.html" data-page="update_book.html">Update/Delete Book</a>
            </div>

            <a href="issue_book.html" data-page="issue_book.html">Issue Book</a>
            <a href="return_book.html" data-page="return_book.html">Return Book</a>
            <!-- Recent Returned Books is intentionally removed. -->
            <a href="members.html" data-page="members.html">Members Details</a>
            <a href="announcement.html" data-page="announcement.html">Announcements</a>
        </nav>

        <div class="logout">
            <a href="logout.php">Logout</a>
        </div>
    </div>

<!-- =========================================

         MAIN CONTENT

         ========================================= -->



    <div class="main">
        <?php if ($pageError !== ''): ?>
            <div class="server-message" role="alert"><?= h($pageError) ?></div>
        <?php endif; ?>





        <div class="top">
            <h1>My Profile</h1>
        </div>







        <!-- PROFILE CARD -->



        <div class="profile-card">





            <h2>

                User Details

            </h2>





            <table class="profile-table">





                <!-- USERNAME -->



                <tr>



                    <th>

                        Username

                    </th>



                    <td id="displayUsername"><?= h($profile['Username'] ?? '') ?></td>



                </tr>





                <!-- NAME -->



                <tr>



                    <th>

                        Name

                    </th>



                    <td id="displayName" data-value="<?= h($profile['Name']) ?>"><?= $profile['Name'] !== '' ? h($profile['Name']) : '—' ?></td>



                </tr>





                <!-- EMAIL -->



                <tr>



                    <th>

                        Email Address

                    </th>



                    <td id="displayEmail" data-value="<?= h($profile['Email']) ?>"><?= $profile['Email'] !== '' ? h($profile['Email']) : '—' ?></td>



                </tr>





                <!-- PHONE -->



                <tr>



                    <th>

                        Phone Number

                    </th>



                    <td id="displayPhone" data-value="<?= h($profile['Phone_no']) ?>"><?= $profile['Phone_no'] !== '' ? h($profile['Phone_no']) : '—' ?></td>



                </tr>





                <!-- ADDRESS -->



                <tr>



                    <th>

                        Address

                    </th>



                    <td id="displayAddress" data-value="<?= h($profile['Addres']) ?>"><?= $profile['Addres'] !== '' ? h($profile['Addres']) : '—' ?></td>



                </tr>





            </table>





            <!-- UPDATE PROFILE BUTTON -->



            <button

                type="button"

                class="edit-button"

                onclick="openProfilePopup()"

            >



                Update Profile



            </button>





        </div>



    </div>







    <!-- =========================================

         UPDATE PROFILE POPUP

         ========================================= -->



    <div

        class="popup-overlay"

        id="profilePopup"

    >





        <div class="popup-box">





            <!-- CLOSE -->



            <button

                type="button"

                class="close-button"

                onclick="closeProfilePopup()"

            >



                &times;



            </button>





            <h2>

                Update Profile

            </h2>





            <form id="profileForm" method="post" action="my_profile.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">





                <!-- =================================

                     NAME

                     ================================= -->



                <div class="form-group">



                    <label for="name">

                        Name

                    </label>





                    <input

                        type="text"

                        id="name"
                        name="name"

                        maxlength="50"

                        placeholder="Enter Name"

                    >





                    <span

                        class="error"

                        id="nameError"

                    ></span>



                </div>







                <!-- =================================

                     EMAIL

                     ================================= -->



                <div class="form-group">



                    <label for="email">

                        Email Address

                    </label>





                    <input

                        type="email"

                        id="email"
                        name="email"

                        maxlength="100"

                        placeholder="Enter Email Address"

                    >





                    <span

                        class="error"

                        id="emailError"

                    ></span>



                </div>







                <!-- =================================

                     PHONE

                     ================================= -->



                <div class="form-group">



                    <label for="phone">

                        Phone Number

                    </label>





                    <input

                        type="text"

                        id="phone"
                        name="phone"

                        maxlength="10"

                        placeholder="Enter 10-digit phone number"

                    >





                    <span

                        class="error"

                        id="phoneError"

                    ></span>



                </div>







                <!-- =================================

                     ADDRESS

                     ================================= -->



                <div class="form-group">



                    <label for="address">

                        Address

                    </label>





                    <textarea

                        id="address"
                        name="address"

                        maxlength="200"

                        placeholder="Enter Address"

                    ></textarea>





                    <span

                        class="error"

                        id="addressError"

                    ></span>



                </div>







                <!-- =================================

                     UPDATE

                     ================================= -->



                <button

                    type="submit"

                    class="update-button"

                >



                    Update Profile



                </button>





            </form>





        </div>



    </div>







    <!-- =========================================

         SUCCESS POPUP

         ========================================= -->



    <div

        class="success-overlay"

        id="successPopup"

    >





        <div class="success-box">





            <h3>

                Update Successful

            </h3>





            <p>

                Profile updated successfully!

            </p>





            <button

                type="button"

                class="success-button"

                onclick="closeSuccessPopup()"

            >



                OK



            </button>





        </div>



    </div>







    <!-- =========================================

         NO CHANGE POPUP

         ========================================= -->



    <div

        class="info-overlay"

        id="infoPopup"

    >





        <div class="info-box">





            <h3>

                No Changes

            </h3>





            <p>

                No profile information was changed.

            </p>





            <button

                type="button"

                class="info-button"

                onclick="closeInfoPopup()"

            >



                OK



            </button>





        </div>



    </div>







    <script>





        /* =========================================

           SIDEBAR MENU

           ========================================= */



        const manageToggle = document.getElementById("manageToggle");
        const submenu = document.getElementById("submenu");
        const menuArrow = document.getElementById("arrow");

        function toggleMenu(forceOpen) {
            const shouldOpen = typeof forceOpen === "boolean"
                ? forceOpen
                : !submenu.classList.contains("open");

            submenu.classList.toggle("open", shouldOpen);
            manageToggle.setAttribute("aria-expanded", String(shouldOpen));
            menuArrow.textContent = shouldOpen ? "-" : "+";
        }

        manageToggle.addEventListener("click", () => toggleMenu());
        manageToggle.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                toggleMenu();
            }
        });

        // Highlight the current page, and expand Manage Books for its child pages.
        const currentPage = window.location.pathname.split("/").pop().toLowerCase();
        const menuLinks = document.querySelectorAll(".sidebar-menu a[data-page]");
        let activeIsBookSubpage = false;

        menuLinks.forEach((link) => {
            if (link.dataset.page.toLowerCase() === currentPage) {
                link.classList.add("active");
                if (submenu.contains(link)) activeIsBookSubpage = true;
            }
        });

        if (activeIsBookSubpage) {
            toggleMenu(true);
            manageToggle.classList.add("active-parent");
        }

        /* =========================================

           ELEMENTS

           ========================================= */



        const profilePopup =

            document.getElementById(

                "profilePopup"

            );





        const successPopup =

            document.getElementById(

                "successPopup"

            );





        const infoPopup =

            document.getElementById(

                "infoPopup"

            );





        const profileForm =

            document.getElementById(

                "profileForm"

            );





        const nameInput =

            document.getElementById(

                "name"

            );





        const emailInput =

            document.getElementById(

                "email"

            );





        const phoneInput =

            document.getElementById(

                "phone"

            );





        const addressInput =

            document.getElementById(

                "address"

            );





        let formSubmitted = false;





        /* =========================================

           ORIGINAL PROFILE VALUES

           ========================================= */



        let originalName = "";



        let originalEmail = "";



        let originalPhone = "";



        let originalAddress = "";







        /* =========================================

           OPEN PROFILE POPUP

           ========================================= */



        function openProfilePopup() {





            /*

             * Get the existing values from

             * the profile table.

             */



            originalName =

                document.getElementById(

                    "displayName"

                ).getAttribute("data-value") || "";





            originalEmail =

                document.getElementById(

                    "displayEmail"

                ).getAttribute("data-value") || "";





            originalPhone =

                document.getElementById(

                    "displayPhone"

                ).getAttribute("data-value") || "";





            originalAddress =

                document.getElementById(

                    "displayAddress"

                ).getAttribute("data-value") || "";





            /*

             * Put existing values into

             * the popup.

             */



            nameInput.value =

                originalName;





            emailInput.value =

                originalEmail;





            phoneInput.value =

                originalPhone;





            addressInput.value =

                originalAddress;





            /*

             * Reset validation.

             */



            formSubmitted = false;



            clearValidation();





            /*

             * Open popup.

             */



            profilePopup.style.display =

                "flex";



        }







        /* =========================================

           CLOSE PROFILE POPUP

           ========================================= */



        function closeProfilePopup() {



            profilePopup.style.display =

                "none";



        }







        /* =========================================

           SUCCESS POPUP

           ========================================= */



        function closeSuccessPopup() {



            successPopup.style.display =

                "none";



        }







        /* =========================================

           NO CHANGE POPUP

           ========================================= */



        function closeInfoPopup() {



            infoPopup.style.display =

                "none";



        }







        /* =========================================

           CLEAR VALIDATION

           ========================================= */



        function clearValidation() {





            nameInput.classList.remove(

                "invalid",

                "valid"

            );





            emailInput.classList.remove(

                "invalid",

                "valid"

            );





            phoneInput.classList.remove(

                "invalid",

                "valid"

            );





            addressInput.classList.remove(

                "invalid",

                "valid"

            );





            document.getElementById(

                "nameError"

            ).textContent = "";





            document.getElementById(

                "emailError"

            ).textContent = "";





            document.getElementById(

                "phoneError"

            ).textContent = "";





            document.getElementById(

                "addressError"

            ).textContent = "";



        }







        /* =========================================

           SHOW ERROR

           ========================================= */



        function showError(

            input,

            errorId,

            message

        ) {



            input.classList.add(

                "invalid"

            );





            input.classList.remove(

                "valid"

            );





            document.getElementById(

                errorId

            ).textContent = message;



        }







        /* =========================================

           SHOW VALID

           ========================================= */



        function showValid(

            input,

            errorId

        ) {



            input.classList.remove(

                "invalid"

            );





            input.classList.add(

                "valid"

            );





            document.getElementById(

                errorId

            ).textContent = "";



        }







        /* =========================================

           VALIDATE NAME

           ========================================= */



        function validateName() {



            const value =

                nameInput.value.trim();





            /*

             * If unchanged, it is valid.

             */



            if (

                value === originalName

            ) {



                showValid(

                    nameInput,

                    "nameError"

                );



                return true;



            }





            /*

             * Empty after changing.

             */



            if (value === "") {



                showError(

                    nameInput,

                    "nameError",

                    "Please enter your name."

                );



                return false;



            }





            /*

             * Length.

             */



            if (

                value.length < 2 ||

                value.length > 50

            ) {



                showError(

                    nameInput,

                    "nameError",

                    "Name must be between 2 and 50 characters."

                );



                return false;



            }





            /*

             * Letters and spaces only.

             */



            if (

                !/^[A-Za-z ]+$/.test(value)

            ) {



                showError(

                    nameInput,

                    "nameError",

                    "Name can contain only letters and spaces."

                );



                return false;



            }





            showValid(

                nameInput,

                "nameError"

            );



            return true;



        }







        /* =========================================

           VALIDATE EMAIL

           ========================================= */



        function validateEmail() {



            const value =

                emailInput.value.trim();





            /*

             * If unchanged, it is valid.

             */



            if (

                value === originalEmail

            ) {



                showValid(

                    emailInput,

                    "emailError"

                );



                return true;



            }





            /*

             * Empty after changing.

             */



            if (value === "") {



                showError(

                    emailInput,

                    "emailError",

                    "Please enter your email address."

                );



                return false;



            }





            /*

             * Email format.

             */



            if (

                !/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(value)

            ) {



                showError(

                    emailInput,

                    "emailError",

                    "Please enter a valid email address."

                );



                return false;



            }





            showValid(

                emailInput,

                "emailError"

            );



            return true;



        }







        /* =========================================

           VALIDATE PHONE

           ========================================= */



        function validatePhone() {



            const value =

                phoneInput.value.trim();





            /*

             * If unchanged, it is valid.

             */



            if (

                value === originalPhone

            ) {



                showValid(

                    phoneInput,

                    "phoneError"

                );



                return true;



            }





            /*

             * Empty after changing.

             */



            if (value === "") {



                showError(

                    phoneInput,

                    "phoneError",

                    "Please enter your phone number."

                );



                return false;



            }





            /*

             * Exactly 10 digits,

             * beginning with 6-9.

             */



            if (

                !/^[6-9][0-9]{9}$/.test(value)

            ) {



                showError(

                    phoneInput,

                    "phoneError",

                    "Phone number must be 10 digits and start with 6, 7, 8 or 9."

                );



                return false;



            }





            showValid(

                phoneInput,

                "phoneError"

            );



            return true;



        }







        /* =========================================

           VALIDATE ADDRESS

           ========================================= */



        function validateAddress() {



            const value =

                addressInput.value.trim();





            /*

             * If unchanged, it is valid.

             */



            if (

                value === originalAddress

            ) {



                showValid(

                    addressInput,

                    "addressError"

                );



                return true;



            }





            /*

             * Empty after changing.

             */



            if (value === "") {



                showError(

                    addressInput,

                    "addressError",

                    "Please enter your address."

                );



                return false;



            }





            /*

             * Length.

             */



            if (

                value.length < 5 ||

                value.length > 200

            ) {



                showError(

                    addressInput,

                    "addressError",

                    "Address must be between 5 and 200 characters."

                );



                return false;



            }





            showValid(

                addressInput,

                "addressError"

            );



            return true;



        }







        /* =========================================

           LIVE VALIDATION

           ONLY AFTER UPDATE CLICK

           ========================================= */



        nameInput.addEventListener(

            "input",

            function () {



                if (formSubmitted) {



                    validateName();



                }



            }

        );





        emailInput.addEventListener(

            "input",

            function () {



                if (formSubmitted) {



                    validateEmail();



                }



            }

        );





        phoneInput.addEventListener(

            "input",

            function () {



                if (formSubmitted) {



                    validatePhone();



                }



            }

        );





        addressInput.addEventListener(

            "input",

            function () {



                if (formSubmitted) {



                    validateAddress();



                }



            }

        );







        /* =========================================

           PHONE - NUMBERS ONLY

           ========================================= */



        phoneInput.addEventListener(

            "input",

            function () {



                this.value =

                    this.value.replace(

                        /[^0-9]/g,

                        ""

                    );



            }

        );







        /* =========================================

           FORM SUBMIT

           ========================================= */



        profileForm.addEventListener("submit", function (event) {
            event.preventDefault();
            formSubmitted = true;

            const changed =
                nameInput.value.trim() !== originalName ||
                emailInput.value.trim() !== originalEmail ||
                phoneInput.value.trim() !== originalPhone ||
                addressInput.value.trim() !== originalAddress;

            if (!changed) {
                closeProfilePopup();
                infoPopup.style.display = "flex";
                return;
            }

            const nameValid = validateName();
            const emailValid = validateEmail();
            const phoneValid = validatePhone();
            const addressValid = validateAddress();

            if (!nameValid || !emailValid || !phoneValid || !addressValid) {
                return;
            }

            // Submit to PHP only after client validation. PHP validates again
            // and writes the changes to MariaDB using a prepared statement.
            profileForm.submit();
        });



        /* =========================================

           CLICK OUTSIDE POPUP

           ========================================= */



        profilePopup.addEventListener(

            "click",

            function (event) {



                if (

                    event.target ===

                    profilePopup

                ) {



                    closeProfilePopup();



                }



            }

        );







        /* =========================================

           ESCAPE TO CLOSE

           ========================================= */



        document.addEventListener(

            "keydown",

            function (event) {



                if (

                    event.key === "Escape" &&

                    profilePopup.style.display ===

                    "flex"

                ) {



                    closeProfilePopup();



                }



            }

        );




        // Reopen the edit popup after server-side validation errors, keeping
        // the values the user submitted rather than discarding them.
        const serverFormState = <?= json_encode([
            'showPopup' => $showEditPopup,
            'values' => $postedProfile,
            'errors' => $fieldErrors,
            'showSuccess' => is_array($profileFlash) && (($profileFlash['type'] ?? '') === 'success')
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        if (serverFormState.showPopup) {
            openProfilePopup();
            nameInput.value = serverFormState.values.name || '';
            emailInput.value = serverFormState.values.email || '';
            phoneInput.value = serverFormState.values.phone || '';
            addressInput.value = serverFormState.values.address || '';
            formSubmitted = true;

            const errorBindings = {
                name: [nameInput, 'nameError'],
                email: [emailInput, 'emailError'],
                phone: [phoneInput, 'phoneError'],
                address: [addressInput, 'addressError']
            };
            Object.entries(serverFormState.errors || {}).forEach(([key, message]) => {
                if (errorBindings[key]) {
                    showError(errorBindings[key][0], errorBindings[key][1], message);
                }
            });
        }

        if (serverFormState.showSuccess) {
            successPopup.style.display = 'flex';
        }

    </script>



</body>



</html>