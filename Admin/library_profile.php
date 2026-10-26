<?php
declare(strict_types=1);

/* PHP backend for Library Details, Borrowing Rules, and Fine & Payment Details. */
$libraryNoticeText = '';
$libraryNoticeType = '';
$libraryConnectionError = '';
$conn = null;

$libraryDaysOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$libraryDefaultDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$libraryForm = [
    'libraryName' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
    'openingTime' => '',
    'closingTime' => ''
];
$selectedDays = $libraryDefaultDays;
$borrowForm = ['borrowLimit' => '3', 'requestLimit' => '3'];
$fineForm = ['finePerDay' => '', 'upiId' => '', 'paymentNumber' => ''];
$fineQrBytes = '';
$fineQrMime = '';
$activeProfileSection = 'librarySection';

function lp_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lp_time_value($value): string
{
    $value = (string)($value ?? '');
    return strlen($value) >= 5 ? substr($value, 0, 5) : $value;
}

function lp_days_from_database($value, array $allowed, array $defaults): array
{
    $value = trim((string)($value ?? ''));
    if ($value === '' || strcasecmp($value, 'Monday to Saturday') === 0) {
        return $defaults;
    }
    $parts = preg_split('/\s*,\s*/', $value) ?: [];
    $valid = array_values(array_intersect($allowed, $parts));
    return $valid ?: $defaults;
}

function lp_image_mime(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (is_string($mime)) {
            return $mime;
        }
    }
    if (function_exists('getimagesizefromstring')) {
        $imageInfo = @getimagesizefromstring($bytes);
        return is_array($imageInfo) && isset($imageInfo['mime']) ? (string)$imageInfo['mime'] : '';
    }
    return '';
}

/* Locate the project's existing connection file; do not replace its credentials. */
if (!extension_loaded('mysqli')) {
    $libraryConnectionError = 'PHP MySQLi is not enabled. Enable the mysqli extension in php.ini and restart Apache.';
} else {
    $connectionCandidates = [
        __DIR__ . '/../Connection/db.php',
        __DIR__ . '/Connection/db.php',
        __DIR__ . '/../../Connection/db.php',
        __DIR__ . '/../connection/db.php'
    ];
    $connectionFile = null;
    foreach ($connectionCandidates as $candidate) {
        if (is_file($candidate)) {
            $connectionFile = $candidate;
            break;
        }
    }
    if ($connectionFile === null) {
        $libraryConnectionError = 'Cannot find Connection/db.php. Keep this page in a folder where it can access your project connection file.';
    } else {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            require_once $connectionFile;
            if (!isset($conn) || !($conn instanceof mysqli)) {
                throw new RuntimeException('Connection/db.php must create a MySQLi connection in $conn.');
            }
            $conn->set_charset('utf8mb4');
        } catch (Throwable $exception) {
            $conn = null;
            $libraryConnectionError = 'Could not connect to the library database. Check Connection/db.php and make sure the library database is running.';
        }
    }
}

/* Load the current Library Details values from the database. */
if ($conn instanceof mysqli) {
    try {
        $loadLibrary = $conn->prepare('SELECT Library_name, Address, Contact_number, Email, Opening_time, Closing_time, Working_days, Borrow_limit, Request_limit FROM library_details WHERE Id = 1 LIMIT 1');
        $loadLibrary->execute();
        $result = $loadLibrary->get_result();
        $databaseLibrary = $result->fetch_assoc();
        $loadLibrary->close();
        if ($databaseLibrary) {
            $libraryForm = [
                'libraryName' => (string)$databaseLibrary['Library_name'],
                'address' => (string)$databaseLibrary['Address'],
                'phone' => (string)($databaseLibrary['Contact_number'] ?? ''),
                'email' => (string)($databaseLibrary['Email'] ?? ''),
                'openingTime' => lp_time_value($databaseLibrary['Opening_time'] ?? ''),
                'closingTime' => lp_time_value($databaseLibrary['Closing_time'] ?? '')
            ];
            $selectedDays = lp_days_from_database($databaseLibrary['Working_days'] ?? '', $libraryDaysOrder, $libraryDefaultDays);
            $borrowForm = [
                'borrowLimit' => (string)($databaseLibrary['Borrow_limit'] ?? 3),
                'requestLimit' => (string)($databaseLibrary['Request_limit'] ?? 3)
            ];
        }
    } catch (Throwable $exception) {
        $libraryConnectionError = 'Could not load Library Details from the database. Check that the library_details table exists.';
        $libraryNoticeText = $libraryConnectionError;
        $libraryNoticeType = 'error';
    }

    /* Load the saved Fine & Payment Details, including the QR image stored as a BLOB. */
    try {
        $loadFine = $conn->prepare('SELECT Fine_per_day, Upi_id, Number, Upi_qr FROM fine_setting WHERE Id = 1 LIMIT 1');
        $loadFine->execute();
        $databaseFine = $loadFine->get_result()->fetch_assoc();
        $loadFine->close();
        if ($databaseFine) {
            $fineForm = [
                'finePerDay' => (string)$databaseFine['Fine_per_day'],
                'upiId' => (string)$databaseFine['Upi_id'],
                'paymentNumber' => (string)$databaseFine['Number']
            ];
            $fineQrBytes = (string)($databaseFine['Upi_qr'] ?? '');
            $fineQrMime = lp_image_mime($fineQrBytes);
            if (!in_array($fineQrMime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $fineQrMime = 'image/png';
            }
        }
    } catch (Throwable $exception) {
        $libraryNoticeText = 'Could not load Fine & Payment Details. Check that the fine_setting table exists.';
        $libraryNoticeType = 'error';
    }
}

/* Process the Library Details save. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['operation'] ?? '') === 'saveLibraryDetails') {
    $activeProfileSection = 'librarySection';
    foreach (['libraryName', 'address', 'phone', 'email', 'openingTime', 'closingTime'] as $key) {
        $value = $_POST[$key] ?? '';
        $libraryForm[$key] = is_string($value) ? trim($value) : '';
    }

    $postedDays = $_POST['workingDays'] ?? '';
    $postedDays = is_string($postedDays) ? $postedDays : '';
    $selectedDays = array_values(array_intersect($libraryDaysOrder, preg_split('/\s*,\s*/', trim($postedDays)) ?: []));
    $errors = [];
    $name = $libraryForm['libraryName'];
    $address = $libraryForm['address'];
    $phone = $libraryForm['phone'];
    $email = $libraryForm['email'];
    $opening = $libraryForm['openingTime'];
    $closing = $libraryForm['closingTime'];

    if (strlen($name) < 3 || strlen($name) > 100) {
        $errors[] = 'Library name must be between 3 and 100 characters.';
    } elseif (!preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N}\\s.'&()\\-]*$/u", $name)) {
        $errors[] = 'Use letters, numbers, spaces and common punctuation in the library name.';
    }
    if (strlen($address) < 10 || strlen($address) > 250) {
        $errors[] = 'Enter a complete address between 10 and 250 characters.';
    }
    if (!preg_match('/^[6-9][0-9]{9}$/', $phone)) {
        $errors[] = 'Enter a valid 10-digit Indian mobile number.';
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid email address or leave it blank.';
    }
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $opening)) {
        $errors[] = 'Select a valid opening time.';
    }
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $closing)) {
        $errors[] = 'Select a valid closing time.';
    } elseif (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $opening) && $closing <= $opening) {
        $errors[] = 'Closing time must be later than opening time.';
    }
    if (!$selectedDays) {
        $errors[] = 'Select at least one working day.';
    }
    if (!($conn instanceof mysqli)) {
        $errors[] = $libraryConnectionError !== '' ? $libraryConnectionError : 'Database connection is unavailable.';
    }

    if (!$errors) {
        try {
            $workingDays = implode(', ', $selectedDays);
            $emailValue = $email === '' ? null : $email;
            $saveLibrary = $conn->prepare(
                'INSERT INTO library_details (Id, Library_name, Address, Opening_time, Closing_time, Working_days, Contact_number, Email)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    Library_name = VALUES(Library_name),
                    Address = VALUES(Address),
                    Opening_time = VALUES(Opening_time),
                    Closing_time = VALUES(Closing_time),
                    Working_days = VALUES(Working_days),
                    Contact_number = VALUES(Contact_number),
                    Email = VALUES(Email)'
            );
            $saveLibrary->bind_param('sssssss', $name, $address, $opening, $closing, $workingDays, $phone, $emailValue);
            $saveLibrary->execute();
            $saveLibrary->close();
            $libraryNoticeText = 'Library details saved successfully.';
            $libraryNoticeType = 'success';
        } catch (Throwable $exception) {
            $libraryNoticeText = 'Could not save Library Details. Check the library_details table and database connection.';
            $libraryNoticeType = 'error';
        }
    } else {
        $libraryNoticeText = implode(' ', $errors);
        $libraryNoticeType = 'error';
    }
}


/* Process Borrowing Rules save/update. This only updates Borrow_limit and Request_limit. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['operation'] ?? '') === 'saveBorrowingRules') {
    $activeProfileSection = 'borrowSection';
    foreach (['borrowLimit', 'requestLimit'] as $key) {
        $value = $_POST[$key] ?? '';
        $borrowForm[$key] = is_string($value) ? trim($value) : '';
    }

    $errors = [];
    $borrowLimitText = $borrowForm['borrowLimit'];
    $requestLimitText = $borrowForm['requestLimit'];

    if ($borrowLimitText === '' || !ctype_digit($borrowLimitText) || (int)$borrowLimitText < 1 || (int)$borrowLimitText > 100) {
        $errors[] = 'Enter a valid borrow limit between 1 and 100.';
    }
    if ($requestLimitText === '' || !ctype_digit($requestLimitText) || (int)$requestLimitText < 0 || (int)$requestLimitText > 100) {
        $errors[] = 'Enter a valid request limit between 0 and 100.';
    }
    if (!($conn instanceof mysqli)) {
        $errors[] = $libraryConnectionError !== '' ? $libraryConnectionError : 'Database connection is unavailable.';
    }

    if (!$errors) {
        try {
            // Avoid inserting an incomplete library_details row because Library_name and Address are required.
            $checkLibrary = $conn->prepare('SELECT Id FROM library_details WHERE Id = 1 LIMIT 1');
            $checkLibrary->execute();
            $libraryExists = $checkLibrary->get_result()->fetch_assoc();
            $checkLibrary->close();

            if (!$libraryExists) {
                $errors[] = 'Save Library Details first, then save Borrowing Rules.';
            } else {
                $borrowLimit = (int)$borrowLimitText;
                $requestLimit = (int)$requestLimitText;
                $saveBorrowing = $conn->prepare('UPDATE library_details SET Borrow_limit = ?, Request_limit = ? WHERE Id = 1');
                $saveBorrowing->bind_param('ii', $borrowLimit, $requestLimit);
                $saveBorrowing->execute();
                $saveBorrowing->close();
                $borrowForm = [
                    'borrowLimit' => (string)$borrowLimit,
                    'requestLimit' => (string)$requestLimit
                ];
                $libraryNoticeText = 'Borrowing rules saved successfully.';
                $libraryNoticeType = 'success';
            }
        } catch (Throwable $exception) {
            $errors[] = 'Could not save Borrowing Rules. Check the library_details table and database connection.';
        }
    }

    if ($errors) {
        $libraryNoticeText = implode(' ', $errors);
        $libraryNoticeType = 'error';
    }
}

/* Process Fine & Payment Details save/update, preserving the existing QR unless a new one is uploaded. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['operation'] ?? '') === 'saveFinePayment') {
    $activeProfileSection = 'fineSection';
    foreach (['finePerDay', 'upiId', 'paymentNumber'] as $key) {
        $value = $_POST[$key] ?? '';
        $fineForm[$key] = is_string($value) ? trim($value) : '';
    }

    $errors = [];
    $fineText = $fineForm['finePerDay'];
    $upiId = $fineForm['upiId'];
    $paymentNumber = $fineForm['paymentNumber'];

    if (!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/', $fineText) || (float)$fineText <= 0 || (float)$fineText > 10000) {
        $errors[] = 'Enter a fine greater than ₹0 and no more than ₹10,000, using at most two decimal places.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z0-9.-]{2,64}$/', $upiId)) {
        $errors[] = 'Enter a valid UPI ID, for example name@bank.';
    }
    if (!preg_match('/^[6-9][0-9]{9}$/', $paymentNumber)) {
        $errors[] = 'Enter a valid 10-digit Indian mobile number for payments.';
    }
    if (!($conn instanceof mysqli)) {
        $errors[] = $libraryConnectionError !== '' ? $libraryConnectionError : 'Database connection is unavailable.';
    }

    $newQrBytes = $fineQrBytes;
    $newQrMime = $fineQrMime;
    $fileProvided = isset($_FILES['qrFile']) && (int)($_FILES['qrFile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($fileProvided) {
        $uploadError = (int)($_FILES['qrFile']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errors[] = 'The QR image upload failed. Please select the image again.';
        } else {
            $tmpPath = (string)($_FILES['qrFile']['tmp_name'] ?? '');
            $fileSize = (int)($_FILES['qrFile']['size'] ?? 0);
            if ($fileSize < 1 || $fileSize > 2 * 1024 * 1024) {
                $errors[] = 'The QR image must be no larger than 2 MB.';
            } elseif (!is_uploaded_file($tmpPath)) {
                $errors[] = 'The uploaded QR image could not be verified.';
            } else {
                $candidateBytes = file_get_contents($tmpPath);
                $candidateMime = is_string($candidateBytes) ? lp_image_mime($candidateBytes) : '';
                if (!is_string($candidateBytes) || $candidateBytes === '' || !in_array($candidateMime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    $errors[] = 'Upload a valid PNG, JPG, or WEBP QR image.';
                } else {
                    $newQrBytes = $candidateBytes;
                    $newQrMime = $candidateMime;
                }
            }
        }
    }
    if ($newQrBytes === '') {
        $errors[] = 'Upload the library UPI QR code before saving.';
    }

    if (!$errors) {
        try {
            $fineAmount = (float)$fineText;
            // The schema stores the QR image in fine_setting.Upi_qr (LONGBLOB).
            $saveFine = $conn->prepare(
                'INSERT INTO fine_setting (Id, Fine_per_day, Upi_id, Number, Upi_qr)
                 VALUES (1, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    Fine_per_day = VALUES(Fine_per_day),
                    Upi_id = VALUES(Upi_id),
                    Number = VALUES(Number),
                    Upi_qr = VALUES(Upi_qr)'
            );
            $qrParam = null;
            $saveFine->bind_param('dssb', $fineAmount, $upiId, $paymentNumber, $qrParam);
            $saveFine->send_long_data(3, $newQrBytes);
            $saveFine->execute();
            $saveFine->close();

            $fineQrBytes = $newQrBytes;
            $fineQrMime = $newQrMime !== '' ? $newQrMime : lp_image_mime($fineQrBytes);
            $fineForm['finePerDay'] = number_format($fineAmount, 2, '.', '');
            $fineForm['upiId'] = $upiId;
            $fineForm['paymentNumber'] = $paymentNumber;
            $libraryNoticeText = 'Fine & Payment Details saved successfully.';
            $libraryNoticeType = 'success';
        } catch (Throwable $exception) {
            $libraryNoticeText = 'Could not save Fine & Payment Details. Check the fine_setting table and database connection.';
            $libraryNoticeType = 'error';
        }
    } else {
        $libraryNoticeText = implode(' ', $errors);
        $libraryNoticeType = 'error';
    }
}

/* Keep database-loaded or submitted values in the existing page and JavaScript UI. */
$libraryJsValues = [
    'libraryName' => $libraryForm['libraryName'],
    'address' => $libraryForm['address'],
    'phone' => $libraryForm['phone'],
    'email' => $libraryForm['email'],
    'openingTime' => $libraryForm['openingTime'],
    'closingTime' => $libraryForm['closingTime'],
    'days' => $selectedDays
];
$borrowingJsValues = [
    'borrowLimit' => (int)$borrowForm['borrowLimit'],
    'requestLimit' => (int)$borrowForm['requestLimit']
];
$fineJsValues = [
    'finePerDay' => $fineForm['finePerDay'],
    'upiId' => $fineForm['upiId'],
    'paymentNumber' => $fineForm['paymentNumber'],
    'qrData' => $fineQrBytes !== '' ? 'data:' . ($fineQrMime !== '' ? $fineQrMime : 'image/png') . ';base64,' . base64_encode($fineQrBytes) : ''
];
?>

<!DOCTYPE html>

<html lang="en">



<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Library Profile - Library Management System</title>



    <style>

        * {

            box-sizing: border-box;

        }



        body {

            margin: 0;

            font-family: Arial, sans-serif;

            background-color: #f2f2f2;

            color: #333;

        }



        /* Left Sidebar */

        .sidebar {

            width: 220px;

            height: 100vh;

            background-color: #333;

            position: fixed;

            left: 0;

            top: 0;

            color: white;

            overflow: hidden;

        }



        .sidebar h2 {

            text-align: center;

            padding: 20px 0;

            margin: 0;

            background-color: #222;

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

            display: block;

            box-sizing: border-box;

            color: white;

            text-decoration: none;

            padding: 15px 20px;

            border-bottom: 1px solid #555;

            cursor: pointer;

        }



        .sidebar a:hover,

        .manage:hover {

            background-color: rgba(255, 255, 255, .11);

        }



        /* Active page indicator */

        .sidebar a.active,

        .manage.active-parent {

            background-color: #484848;

            box-shadow: inset 4px 0 0 #f2f2f2;

        }



        .arrow {

            float: right;

        }



        .submenu {

            display: none;

            background-color: #444;

        }



        .submenu.open {

            display: block;

        }



        .submenu a {

            padding-left: 40px;

        }



        .logout {

            position: absolute;

            bottom: 10px;

            left: 0;

            width: 100%;

        }



        .logout a {

            background-color: #222;

            text-align: center;

        }



        /* Main Content */

        .main {

            margin-left: 220px;

            padding: 25px;

            min-height: 100vh;

        }



        .top {

            background-color: white;

            padding: 20px;

            border: 1px solid #ddd;

            text-align: center;

            margin-bottom: 22px;

        }



        .top h1 {

            margin: 0;

            color: #333;

            font-size: 28px;

        }



        .tabs {

            display: flex;

            gap: 8px;

            flex-wrap: wrap;

            margin-bottom: 0;

        }



        .tab {

            padding: 12px 20px;

            border: 1px solid #ccc;

            border-bottom: 0;

            background: #e6e6e6;

            color: #333;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;

            border-radius: 5px 5px 0 0;

        }



        .tab.active {

            background: #333;

            color: white;

            border-color: #333;

        }



        .panel {

            background: white;

            border: 1px solid #ccc;

            padding: 18px;

            border-radius: 0 5px 5px 5px;

        }



        .notice {

            display: none;

            padding: 12px;

            margin-bottom: 15px;

            border: 1px solid;

            border-radius: 4px;

        }



        .notice.success {

            display: block;

            color: #176b35;

            background: #eef8f0;

            border-color: #9bd2aa;

        }



        .notice.error {

            display: block;

            color: #b00020;

            background: #fff0f0;

            border-color: #e4aaaa;

        }



        .sections {

            display: block;

        }



        .section {

            display: none;

            border: 1px solid #ddd;

            background: #fff;

            min-width: 0;

        }



        .section.active-section {

            display: block;

            max-width: 900px;

            margin: 0 auto;

        }



        .section-head {

            background: #eee;

            padding: 15px;

            border-bottom: 1px solid #ddd;

        }



        .section-head h2 {

            margin: 0 0 5px;

            font-size: 21px;

        }



        .section-body {

            padding: 16px;

        }



        .field {

            margin-bottom: 14px;

        }



        label.field-label {

            display: block;

            font-weight: bold;

            margin-bottom: 6px;

        }



        .required {

            color: red;

        }



        input,

        textarea {

            width: 100%;

            padding: 10px;

            border: 1px solid #999;

            font: 15px Arial, sans-serif;

            outline: none;

            background: white;

            color: #333;

        }



        textarea {

            min-height: 78px;

            resize: vertical;

        }



        input:focus,

        textarea:focus {

            border-color: #333;

            box-shadow: 0 0 0 1px #333;

        }



        .invalid {

            border: 2px solid red !important;

        }



        .error {

            display: block;

            color: red;

            font-size: 13px;

            margin-top: 4px;

            min-height: 15px;

        }



        .two-col {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 12px;

        }



        .days {

            display: grid;

            grid-template-columns: repeat(3, minmax(0, 1fr));

            gap: 7px;

        }



        .day {

            display: flex;

            align-items: center;

            gap: 7px;

            border: 1px solid #ccc;

            padding: 8px;

            font-weight: normal;

            cursor: pointer;

            font-size: 14px;

        }



        .day input {

            width: auto;

            margin: 0;

            accent-color: #333;

        }



        .qr-grid {

            display: block;

            max-width: 620px;

        }



        .qr-upload-card {

            border: 1px dashed #777;

            background: #fafafa;

            min-height: 0;

            padding: 10px 12px;

            display: grid;

            grid-template-columns: minmax(170px, 1fr) minmax(190px, 230px);

            align-items: center;

            gap: 12px;

            text-align: center;

        }



        .upload {

            display: flex;

            flex-direction: column;

            align-items: center;

            justify-content: center;

            cursor: pointer;

            width: 100%;

            min-height: 92px;

            padding: 6px;

        }



        .upload strong {

            margin: 5px 0;

        }



        .upload small {

            color: #666;

            font-size: 12px;

        }



        #qrFile {

            display: none;

        }



        .qr-upload-card img {

            width: 220px;

            height: 220px;

            max-width: 100%;

            object-fit: contain;

            display: none;

            grid-column: 2;

            grid-row: 1;

        }



        .qr-placeholder {

            width: 220px;

            height: 220px;

            max-width: 100%;

            border: 1px dashed #aaa;

            display: grid;

            place-items: center;

            color: #666;

            font-size: 12px;

            grid-column: 2;

            grid-row: 1;

        }



        #removeQr {

            display: none;

            grid-column: 2;

            grid-row: 2;

            justify-self: center;

        }



        @media(max-width:650px) {

            .qr-upload-card {

                grid-template-columns: 1fr;

                gap: 8px;

            }



            .qr-upload-card img,

            .qr-placeholder {

                grid-column: 1;

                grid-row: 2;

                width: 200px;

                height: 200px;

            }



            .upload {

                grid-column: 1;

                grid-row: 1;

            }



            #removeQr {

                grid-column: 1;

                grid-row: 3;

            }

        }



        button {

            font: 15px Arial, sans-serif;

            cursor: pointer;

            border: 0;

            padding: 11px 17px;

        }



        .primary {

            background: #333;

            color: white;

        }



        .primary:hover {

            background: #555;

        }



        .secondary {

            background: #e7e7e7;

            color: #333;

        }



        .outline {

            background: white;

            border: 1px solid #999;

            color: #333;

        }



        .actions {

            display: flex;

            align-items: center;

            gap: 10px;

            flex-wrap: wrap;

            border-top: 1px solid #ddd;

            padding-top: 15px;

            margin-top: 16px;

        }



        .spacer {

            flex: 1;

        }



        .view-mode {

            display: none;

        }



        .details-table {

            width: 100%;

            border-collapse: collapse;

            background: white;

        }



        .details-table th,

        .details-table td {

            padding: 12px;

            border: 1px solid #ccc;

            text-align: left;

            overflow-wrap: anywhere;

        }



        .details-table th {

            width: 32%;

            background: #eee;

        }



        .view-qr {

            max-width: 150px;

            max-height: 150px;

            object-fit: contain;

        }



        @media(max-width:760px) {

            .sidebar {

                width: 180px;

            }



            .logout {

                width: 180px;

            }



            .main {

                margin-left: 180px;

                padding: 14px;

            }



            .two-col,

            .qr-grid {

                grid-template-columns: 1fr;

            }



            .days {

                grid-template-columns: repeat(2, minmax(0, 1fr));

            }

        }



        @media(max-width:560px) {

            .sidebar {

                position: relative;

                width: 100%;

                height: auto;

                overflow: visible;

            }



            .sidebar h2 {

                padding: 14px;

            }



            .logout {

                position: static;

                width: 100%;

            }



            .main {

                margin-left: 0;

                padding: 12px;

            }



            .top h1 {

                font-size: 23px;

            }



            .panel {

                padding: 10px;

            }



            .section-body {

                padding: 12px;

            }

        }

    </style>

</head>



<body>



    <!-- Sidebar -->

    <div class="sidebar">

        <h2>Library</h2>



        <nav class="sidebar-menu" aria-label="Admin menu">

            <a href="admin_home.html" data-page="admin_home.html">Dashboard</a>

            <a href="my_profile.html" data-page="my_profile.html">My Profile</a>

            <a href="library_profile.html" data-page="library_profile.html">Library Profile</a>



            <!-- Manage Books Menu -->

            <div class="manage" id="manageToggle" role="button" tabindex="0"

                 aria-expanded="false" aria-controls="submenu">

                Manage Books

                <span class="arrow" id="arrow">+</span>

            </div>



            <!-- Manage Books Submenu -->

            <div class="submenu" id="submenu">

                <a href="view_books.html" data-page="view_books.html">View All Books</a>

                <a href="add_book.html" data-page="add_book.html">Add Book</a>

                <a href="update_book.html" data-page="update_book.html">Update/Delete Book</a>

            </div>



            <a href="issue_book.html" data-page="issue_book.html">Issue Book</a>

            <a href="return_book.html" data-page="return_book.html">Return Book</a>

            <a href="members.html" data-page="members.html">Members Details</a>

            <a href="announcement.html" data-page="announcement.html">Announcements</a>

        </nav>



        <div class="logout">

            <a href="login_form.html">Logout</a>

        </div>

    </div>



    <!-- Main Page -->

    <main class="main">

        <header class="top"><h1>Library Profile</h1></header>



        <div class="tabs">

            <button type="button" class="tab<?= $activeProfileSection === 'librarySection' ? ' active' : '' ?>" data-target="librarySection" role="tab" aria-selected="<?= $activeProfileSection === 'librarySection' ? 'true' : 'false' ?>">Library Details</button>

            <button type="button" class="tab<?= $activeProfileSection === 'borrowSection' ? ' active' : '' ?>" data-target="borrowSection" role="tab" aria-selected="<?= $activeProfileSection === 'borrowSection' ? 'true' : 'false' ?>">Borrowing Rules</button>

            <button type="button" class="tab<?= $activeProfileSection === 'fineSection' ? ' active' : '' ?>" data-target="fineSection" role="tab" aria-selected="<?= $activeProfileSection === 'fineSection' ? 'true' : 'false' ?>">Fine &amp; Payment Details</button>

        </div>



        <div class="panel">

            <div id="notice" class="notice<?= $libraryNoticeType !== '' ? ' ' . lp_h($libraryNoticeType) : '' ?>" role="status" aria-live="polite"><?= lp_h($libraryNoticeText) ?></div>



            <form id="settingsForm" method="post" enctype="multipart/form-data" novalidate>
                    <input type="hidden" id="operation" name="operation" value="">
                    <input type="hidden" id="workingDays" name="workingDays" value="">

                <div class="sections">

                    <section class="section<?= $activeProfileSection === 'librarySection' ? ' active-section' : '' ?>" id="librarySection">

                        <div class="section-head"><h2>Library Details</h2></div>

                        <div class="section-body">

                            <div class="field">

                                <label class="field-label" for="libraryName">Library Name <span class="required">*</span></label>

                                <input id="libraryName" name="libraryName" maxlength="100" value="<?= lp_h($libraryForm['libraryName']) ?>" placeholder="Enter library name">

                                <small class="error" id="libraryNameError"></small>

                            </div>

                            <div class="field">

                                <label class="field-label" for="address">Library Address <span class="required">*</span></label>

                                <textarea id="address" name="address" maxlength="250" placeholder="Enter complete address"><?= lp_h($libraryForm['address']) ?></textarea>

                                <small class="error" id="addressError"></small>

                            </div>

                            <div class="two-col">

                                <div class="field">

                                    <label class="field-label" for="phone">Contact Number <span class="required">*</span></label>

                                    <input id="phone" name="phone" inputmode="numeric" maxlength="10" value="<?= lp_h($libraryForm['phone']) ?>" placeholder="10-digit mobile number">

                                    <small class="error" id="phoneError"></small>

                                </div>

                                <div class="field">

                                    <label class="field-label" for="email">Email Address</label>

                                    <input id="email" name="email" type="email" maxlength="120" value="<?= lp_h($libraryForm['email']) ?>" placeholder="library@example.com">

                                    <small class="error" id="emailError"></small>

                                </div>

                            </div>

                            <div class="two-col">

                                <div class="field">

                                    <label class="field-label" for="openingTime">Opening Time <span class="required">*</span></label>

                                    <input id="openingTime" name="openingTime" type="time" value="<?= lp_h($libraryForm['openingTime']) ?>">

                                    <small class="error" id="openingTimeError"></small>

                                </div>

                                <div class="field">

                                    <label class="field-label" for="closingTime">Closing Time <span class="required">*</span></label>

                                    <input id="closingTime" name="closingTime" type="time" value="<?= lp_h($libraryForm['closingTime']) ?>">

                                    <small class="error" id="closingTimeError"></small>

                                </div>

                            </div>

                            <div class="field">

                                <label class="field-label">Working Days <span class="required">*</span></label>

                                <div class="days">

                                    <label class="day"><input type="checkbox" name="days" value="Monday" <?= in_array('Monday', $selectedDays, true) ? 'checked' : '' ?>> Monday</label>

                                    <label class="day"><input type="checkbox" name="days" value="Tuesday" <?= in_array('Tuesday', $selectedDays, true) ? 'checked' : '' ?>> Tuesday</label>

                                    <label class="day"><input type="checkbox" name="days" value="Wednesday" <?= in_array('Wednesday', $selectedDays, true) ? 'checked' : '' ?>> Wednesday</label>

                                    <label class="day"><input type="checkbox" name="days" value="Thursday" <?= in_array('Thursday', $selectedDays, true) ? 'checked' : '' ?>> Thursday</label>

                                    <label class="day"><input type="checkbox" name="days" value="Friday" <?= in_array('Friday', $selectedDays, true) ? 'checked' : '' ?>> Friday</label>

                                    <label class="day"><input type="checkbox" name="days" value="Saturday" <?= in_array('Saturday', $selectedDays, true) ? 'checked' : '' ?>> Saturday</label>

                                    <label class="day"><input type="checkbox" name="days" value="Sunday" <?= in_array('Sunday', $selectedDays, true) ? 'checked' : '' ?>> Sunday</label>

                                </div>

                                <small class="error" id="daysError"></small>

                            </div>

                        </div>

                    </section>



                    <section class="section<?= $activeProfileSection === 'borrowSection' ? ' active-section' : '' ?>" id="borrowSection">

                        <div class="section-head"><h2>Borrowing Rules</h2></div>

                        <div class="section-body">

                            <div class="two-col">

                                <div class="field">

                                    <label class="field-label" for="borrowLimit">Borrow Limit at a Time <span class="required">*</span></label>

                                    <input id="borrowLimit" name="borrowLimit" type="text" inputmode="numeric" maxlength="3" autocomplete="off" value="<?= lp_h($borrowForm['borrowLimit']) ?>" placeholder="e.g. 5">

                                    <small class="error" id="borrowLimitError"></small>

                                </div>

                                <div class="field">

                                    <label class="field-label" for="requestLimit">Request Limit at a Time <span class="required">*</span></label>

                                    <input id="requestLimit" name="requestLimit" type="text" inputmode="numeric" maxlength="3" autocomplete="off" value="<?= lp_h($borrowForm['requestLimit']) ?>" placeholder="e.g. 3">

                                    <small class="error" id="requestLimitError"></small>

                                </div>

                            </div>

                        </div>

                    </section>



                    <section class="section<?= $activeProfileSection === 'fineSection' ? ' active-section' : '' ?>" id="fineSection">

                        <div class="section-head"><h2>Fine &amp; Payment Details</h2></div>

                        <div class="section-body">

                            <div class="field">

                                <label class="field-label" for="finePerDay">Fine per Day (₹) <span class="required">*</span></label>

                                <input id="finePerDay" name="finePerDay" type="text" inputmode="decimal" maxlength="8" autocomplete="off" value="<?= lp_h($fineForm['finePerDay']) ?>" placeholder="e.g. 5">

                                <small class="error" id="finePerDayError"></small>

                            </div>

                            <div class="field">

                                <label class="field-label" for="upiId">UPI ID <span class="required">*</span></label>

                                <input id="upiId" name="upiId" maxlength="100" value="<?= lp_h($fineForm['upiId']) ?>" placeholder="e.g. library@upi">

                                <small class="error" id="upiIdError"></small>

                            </div>

                            <div class="field">

                                <label class="field-label" for="paymentNumber">Payment Number <span class="required">*</span></label>

                                <input id="paymentNumber" name="paymentNumber" inputmode="numeric" maxlength="10" value="<?= lp_h($fineForm['paymentNumber']) ?>" placeholder="10-digit mobile number">

                                <small class="error" id="paymentNumberError"></small>

                            </div>

                            <div class="field">

                                <label class="field-label">UPI QR Code <span class="required">*</span></label>

                                <div class="qr-grid">

                                    <div class="qr-upload-card">

                                        <label class="upload" for="qrFile">

                                            <span id="qrUploadIcon" style="font-size:28px">⇧</span>

                                            <strong id="qrUploadTitle">Upload QR Code</strong>

                                            <small>PNG, JPG or WEBP · Max 2 MB</small>

                                            <small id="fileName">No file selected</small>

                                        </label>

                                        <input type="file" id="qrFile" name="qrFile" accept="image/png,image/jpeg,image/webp">

                                        <div class="qr-placeholder" id="qrPlaceholder">QR preview appears here</div>

                                        <img id="qrImage" alt="UPI QR code preview">

                                        <button type="button" class="outline" id="removeQr">Remove QR</button>

                                    </div>

                                </div>

                                <small class="error" id="qrFileError"></small>

                            </div>

                        </div>

                    </section>

                </div>



                <div class="actions">

                    <button class="primary" type="submit">Save Details</button>

                    <button class="secondary" type="button" id="resetBtn">Reset Form</button>

                    <span class="spacer"></span>

                    <button class="outline" type="button" id="viewBtn">View Saved Details</button>

                </div>

            </form>



            <section class="view-mode" id="viewMode">

                <h2>Saved Library Profile</h2>

                <div id="savedDetails"></div>

                <div class="actions">

                    <button class="primary" type="button" id="editBtn">Edit Details</button>

                </div>

            </section>

        </div>

    </main>



    <script>

        const $ = id => document.getElementById(id);

        let qrData = '';



        // Tab switching logic

        document.querySelectorAll('.tab').forEach(tab => {

            tab.addEventListener('click', () => {

                document.querySelectorAll('.tab').forEach(t => {

                    t.classList.remove('active');

                    t.setAttribute('aria-selected', 'false');

                });

                document.querySelectorAll('.section').forEach(s => s.classList.remove('active-section'));



                tab.classList.add('active');

                tab.setAttribute('aria-selected', 'true');

                $(tab.dataset.target).classList.add('active-section');

            });

        });



        // Numeric fields input validation

        ['phone', 'paymentNumber'].forEach(id => {

            const field = $(id);

            field.addEventListener('input', () => {

                const cleaned = field.value.replace(/\D/g, '').slice(0, 10);

                if (field.value !== cleaned) field.value = cleaned;

            });

        });



        ['borrowLimit', 'requestLimit'].forEach(id => {

            const field = $(id);

            field.addEventListener('input', () => {

                const cleaned = field.value.replace(/\D/g, '').slice(0, 3);

                if (field.value !== cleaned) field.value = cleaned;

            });

        });



        $('finePerDay').addEventListener('input', () => {

            const field = $('finePerDay');

            let cleaned = field.value.replace(/[^\d.]/g, '');

            const dot = cleaned.indexOf('.');

            if (dot !== -1) {

                cleaned = cleaned.slice(0, dot + 1) + cleaned.slice(dot + 1).replace(/\./g, '');

                const parts = cleaned.split('.');

                cleaned = parts[0] + (parts.length > 1 ? '.' + parts[1].slice(0, 2) : '');

            }

            if (field.value !== cleaned) field.value = cleaned;

        });



        const fieldIds = ['libraryName', 'address', 'phone', 'email', 'openingTime', 'closingTime', 'borrowLimit', 'requestLimit', 'finePerDay', 'upiId', 'paymentNumber'];

        const errorIds = ['libraryName', 'address', 'phone', 'email', 'openingTime', 'closingTime', 'days', 'borrowLimit', 'requestLimit', 'finePerDay', 'upiId', 'paymentNumber', 'qrFile'];



        function error(id, msg) {

            $(id + 'Error').textContent = msg;

            const el = $(id);

            if (el && el.matches('input,textarea')) el.classList.toggle('invalid', !!msg);

        }



        function clearErrors() {

            errorIds.forEach(id => error(id, ''));

            fieldIds.forEach(id => $(id).classList.remove('invalid', 'valid'));

            $('notice').className = 'notice';

            $('notice').textContent = '';

        }



        function days() {

            return [...document.querySelectorAll('input[name="days"]:checked')].map(x => x.value);

        }



        function validate() {

            clearErrors();

            let ok = true;

            const fail = (id, msg) => { error(id, msg); ok = false; };



            const activeLibrary = $('librarySection').classList.contains('active-section');

            const activeBorrow = $('borrowSection').classList.contains('active-section');

            const activeFine = $('fineSection').classList.contains('active-section');



            if (activeLibrary) {

                const name = $('libraryName').value.trim(), address = $('address').value.trim(), phone = $('phone').value.trim(), email = $('email').value.trim(), open = $('openingTime').value, close = $('closingTime').value;

                if (name.length < 3) fail('libraryName', 'Library name is required (minimum 3 characters).');

                else if (!/^[\p{L}\p{N}][\p{L}\p{N}\s.'&()-]*$/u.test(name)) fail('libraryName', 'Use letters, numbers, spaces and common punctuation only.');

                if (address.length < 10) fail('address', 'Enter a complete address (minimum 10 characters).');

                if (!/^[6-9]\d{9}$/.test(phone)) fail('phone', 'Enter a valid 10-digit Indian mobile number.');

                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) fail('email', 'Enter a valid email address or leave it blank.');

                if (!open) fail('openingTime', 'Select opening time.');

                if (!close) fail('closingTime', 'Select closing time.');

                if (open && close && close <= open) fail('closingTime', 'Closing time must be later than opening time.');

                if (!days().length) { error('days', 'Select at least one working day.'); ok = false; }

            }



            if (activeBorrow) {

                const borrow = $('borrowLimit').value.trim(), request = $('requestLimit').value.trim();

                if (borrow === '' || !Number.isFinite(Number(borrow)) || Number(borrow) <= 0 || Number(borrow) > 100) fail('borrowLimit', 'Enter a valid borrow limit between 1 and 100.');

                if (request === '' || !Number.isFinite(Number(request)) || Number(request) < 0 || Number(request) > 100) fail('requestLimit', 'Enter a valid request limit between 0 and 100.');

            }



            if (activeFine) {

                const fine = $('finePerDay').value, upi = $('upiId').value.trim(), pay = $('paymentNumber').value.trim();

                if (fine === '' || !Number.isFinite(Number(fine)) || Number(fine) <= 0 || Number(fine) > 10000) fail('finePerDay', 'Enter a fine greater than ₹0 and no more than ₹10,000.');

                if (!/^[A-Za-z0-9.\_-]{2,256}@[A-Za-z0-9.-]{2,64}$/.test(upi)) fail('upiId', 'Enter a valid UPI ID, e.g. name@bank.');

                if (!/^[6-9]\d{9}$/.test(pay)) fail('paymentNumber', 'Enter a valid 10-digit Indian mobile number.');

                if (!qrData && !$('qrFile').files.length) { error('qrFile', 'Upload the library UPI QR code.'); ok = false; }

            }



            return ok;

        }



        function collect() {

            let old = {};

            try { old = JSON.parse(localStorage.getItem('lmsLibraryFineDetails') || '{}'); } catch (e) {}

            const d = { ...old };



            if ($('librarySection').classList.contains('active-section')) {

                Object.assign(d, {

                    libraryName: $('libraryName').value.trim(),

                    address: $('address').value.trim(),

                    phone: $('phone').value.trim(),

                    email: $('email').value.trim(),

                    openingTime: $('openingTime').value,

                    closingTime: $('closingTime').value,

                    days: days()

                });

            }

            if ($('borrowSection').classList.contains('active-section')) {

                Object.assign(d, {

                    borrowLimit: Number($('borrowLimit').value),

                    requestLimit: Number($('requestLimit').value)

                });

            }

            if ($('fineSection').classList.contains('active-section')) {

                Object.assign(d, {

                    finePerDay: Number($('finePerDay').value),

                    upiId: $('upiId').value.trim(),

                    paymentNumber: $('paymentNumber').value.trim(),

                    qrData

                });

            }

            return d;

        }



        function showQr() {

            if (qrData) {

                $('qrImage').src = qrData;

                $('qrImage').style.display = 'block';

                $('qrPlaceholder').style.display = 'none';

                $('qrUploadIcon').style.display = 'none';

                $('qrUploadTitle').textContent = 'Change QR Code';

                $('fileName').textContent = 'QR code uploaded';

                $('removeQr').style.display = 'inline-block';

            } else {

                $('qrImage').removeAttribute('src');

                $('qrImage').style.display = 'none';

                $('qrPlaceholder').style.display = 'grid';

                $('qrUploadIcon').style.display = 'block';

                $('qrUploadTitle').textContent = 'Upload QR Code';

                $('fileName').textContent = 'No file selected';

                $('removeQr').style.display = 'none';

            }

        }



        function fill(d) {

            if (!d) return;

            fieldIds.forEach(id => { if (d[id] !== undefined) $(id).value = d[id]; });

            document.querySelectorAll('input[name="days"]').forEach(x => x.checked = (d.days || []).includes(x.value));

            qrData = d.qrData || '';

            showQr();

        }



        try {

            const saved = JSON.parse(localStorage.getItem('lmsLibraryFineDetails'));

            if (saved) fill(saved);

        } catch (e) {}

        const libraryValuesFromPhp = <?= json_encode($libraryJsValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        $('libraryName').value = libraryValuesFromPhp.libraryName;
        $('address').value = libraryValuesFromPhp.address;
        $('phone').value = libraryValuesFromPhp.phone;
        $('email').value = libraryValuesFromPhp.email;
        $('openingTime').value = libraryValuesFromPhp.openingTime;
        $('closingTime').value = libraryValuesFromPhp.closingTime;
        document.querySelectorAll('input[name="days"]').forEach(day => {
            day.checked = libraryValuesFromPhp.days.includes(day.value);
        });
        const borrowingValuesFromPhp = <?= json_encode($borrowingJsValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        $('borrowLimit').value = String(borrowingValuesFromPhp.borrowLimit);
        $('requestLimit').value = String(borrowingValuesFromPhp.requestLimit);
        const fineValuesFromPhp = <?= json_encode($fineJsValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        $('finePerDay').value = fineValuesFromPhp.finePerDay;
        $('upiId').value = fineValuesFromPhp.upiId;
        $('paymentNumber').value = fineValuesFromPhp.paymentNumber;
        qrData = fineValuesFromPhp.qrData || '';
        showQr();


        $('settingsForm').addEventListener('submit', e => {

            e.preventDefault();

            if (!validate()) {

                $('notice').className = 'notice error';

                $('notice').textContent = 'Please correct the highlighted fields before saving.';

                document.querySelector('.invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' });

                return;

            }

            if ($('librarySection').classList.contains('active-section')) {
                $('operation').value = 'saveLibraryDetails';
                $('workingDays').value = days().join(', ');
                HTMLFormElement.prototype.submit.call($('settingsForm'));
                return;
            }

            if ($('borrowSection').classList.contains('active-section')) {
                $('operation').value = 'saveBorrowingRules';
                HTMLFormElement.prototype.submit.call($('settingsForm'));
                return;
            }
            if ($('fineSection').classList.contains('active-section')) {
                $('operation').value = 'saveFinePayment';
                HTMLFormElement.prototype.submit.call($('settingsForm'));
                return;
            }
            try {

                localStorage.setItem('lmsLibraryFineDetails', JSON.stringify(collect()));

                $('notice').className = 'notice success';

                $('notice').textContent = 'Details saved successfully in this browser.';

            } catch (err) {

                $('notice').className = 'notice error';

                $('notice').textContent = 'Could not save. Try a smaller QR image.';

            }

        });



        $('resetBtn').addEventListener('click', () => {

            $('settingsForm').reset();

            qrData = '';

            showQr();

            clearErrors();

        });



        $('qrFile').addEventListener('change', () => {

            error('qrFile', '');

            const f = $('qrFile').files[0];

            if (!f) return;

            if (!['image/png', 'image/jpeg', 'image/webp'].includes(f.type)) {

                error('qrFile', 'Please upload a valid PNG, JPG, or WEBP image.');
                $('qrFile').value = '';
                return;

            }

            if (f.size > 2 * 1024 * 1024) {

                error('qrFile', 'File size must be less than 2 MB.');
                $('qrFile').value = '';
                return;

            }

            const reader = new FileReader();

            reader.onload = e => {

                qrData = e.target.result;

                showQr();

            };

            reader.readAsDataURL(f);

        });



        $('removeQr').addEventListener('click', () => {

            qrData = '';

            $('qrFile').value = '';

            showQr();

        });



        // View Saved Details Mode

        $('viewBtn').addEventListener('click', () => {

            try {

                const d = JSON.parse(localStorage.getItem('lmsLibraryFineDetails') || '{}');
                Object.assign(d, libraryValuesFromPhp, borrowingValuesFromPhp, fineValuesFromPhp);

                let html = `<table class="details-table">

                    <tr><th>Library Name</th><td>${d.libraryName || '-'}</td></tr>

                    <tr><th>Address</th><td>${d.address || '-'}</td></tr>

                    <tr><th>Phone</th><td>${d.phone || '-'}</td></tr>

                    <tr><th>Email</th><td>${d.email || '-'}</td></tr>

                    <tr><th>Opening Time</th><td>${d.openingTime || '-'}</td></tr>

                    <tr><th>Closing Time</th><td>${d.closingTime || '-'}</td></tr>

                    <tr><th>Working Days</th><td>${(d.days || []).join(', ') || '-'}</td></tr>

                    <tr><th>Borrow Limit</th><td>${d.borrowLimit || '-'}</td></tr>

                    <tr><th>Request Limit</th><td>${d.requestLimit ?? '-'}</td></tr>

                    <tr><th>Fine Per Day</th><td>₹${d.finePerDay || '-'}</td></tr>

                    <tr><th>UPI ID</th><td>${d.upiId || '-'}</td></tr>

                    <tr><th>Payment Number</th><td>${d.paymentNumber || '-'}</td></tr>

                    <tr><th>UPI QR Code</th><td>${d.qrData ? `<img class="view-qr" src="${d.qrData}" alt="QR Code">` : 'No QR uploaded'}</td></tr>

                </table>`;

                $('savedDetails').innerHTML = html;

                $('settingsForm').style.display = 'none';

                document.querySelector('.tabs').style.display = 'none';

                $('viewMode').style.display = 'block';

            } catch (e) {

                alert('No saved details found.');

            }

        });



        $('editBtn').addEventListener('click', () => {

            $('viewMode').style.display = 'none';

            $('settingsForm').style.display = 'block';

            document.querySelector('.tabs').style.display = 'flex';

        });



        // Sidebar collapsible menu toggle & robust active item highlighter

        const manageToggle = document.getElementById('manageToggle');

        const submenu = document.getElementById('submenu');

        const arrow = document.getElementById('arrow');



        function toggleMenu(forceOpen) {

            const shouldOpen = typeof forceOpen === 'boolean'

                ? forceOpen

                : !submenu.classList.contains('open');



            submenu.classList.toggle('open', shouldOpen);

            manageToggle.setAttribute('aria-expanded', String(shouldOpen));

            arrow.textContent = shouldOpen ? '-' : '+';

        }



        manageToggle.addEventListener('click', () => toggleMenu());

        manageToggle.addEventListener('keydown', (event) => {

            if (event.key === 'Enter' || event.key === ' ') {

                event.preventDefault();

                toggleMenu();

            }

        });



        // Robust matching checking both pathname and full href URL for local/server compatibility

        const currentUrl = window.location.href.toLowerCase();

        const menuLinks = document.querySelectorAll('.sidebar-menu a[data-page]');

        let activeIsBookSubpage = false;



        menuLinks.forEach((link) => {

            const linkPage = link.dataset.page.toLowerCase();

            if (currentUrl.includes(linkPage)) {

                link.classList.add('active');

                if (submenu.contains(link)) {

                    activeIsBookSubpage = true;

                }

            }

        });



        if (activeIsBookSubpage) {

            toggleMenu(true);

            manageToggle.classList.add('active-parent');

        }

    </script>

</body>



</html>