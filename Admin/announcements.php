<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

/** Emit a JSON response and terminate the request. */
function announcement_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Locate the project's existing MySQLi connection without requiring a fixed folder layout. */
function announcement_get_connection(): mysqli
{
    $dir = __DIR__;
    $candidates = [];
    for ($level = 0; $level < 6; $level++) {
        $candidates[] = $dir . DIRECTORY_SEPARATOR . 'Connection' . DIRECTORY_SEPARATOR . 'db.php';
        $candidates[] = $dir . DIRECTORY_SEPARATOR . 'db.php';
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }

    foreach (array_unique($candidates) as $file) {
        if (is_file($file)) {
            require_once $file;
            if (isset($conn) && $conn instanceof mysqli) {
                $conn->set_charset('utf8mb4');
                return $conn;
            }
        }
    }
    throw new RuntimeException('Could not find a db.php file that defines the MySQLi connection variable $conn.');
}

function announcement_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function announcement_parse_datetime($value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
    if ($date->format('Y-m-d\\TH:i') !== $value) return null;
    return $date;
}

function announcement_is_active(array $row): bool
{
    try {
        $now = new DateTimeImmutable('now');
        $start = new DateTimeImmutable((string)$row['start_date']);
        $end = new DateTimeImmutable((string)$row['end_date']);
        return $now >= $start && $now <= $end;
    } catch (Throwable $e) {
        return false;
    }
}

/* The API runs only when ?action=... is supplied; the normal request renders the page below. */
if (isset($_GET['action'])) {
    try {
        $conn = announcement_get_connection();
        $action = (string)$_GET['action'];

        if ($action === 'list') {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                announcement_json_response(405, ['ok' => false, 'message' => 'Use GET to load announcements.']);
            }
            $result = $conn->query('SELECT announcement_id, title, message, start_date, end_date, created_at FROM announcements ORDER BY created_at DESC, announcement_id DESC');
            $records = [];
            while ($row = $result->fetch_assoc()) {
                $records[] = [
                    'announcementId' => (int)$row['announcement_id'],
                    'title' => (string)$row['title'],
                    'message' => (string)$row['message'],
                    'startDate' => (new DateTimeImmutable((string)$row['start_date']))->format('Y-m-d\\TH:i'),
                    'endDate' => (new DateTimeImmutable((string)$row['end_date']))->format('Y-m-d\\TH:i'),
                    'createdAt' => (string)$row['created_at'],
                ];
            }
            announcement_json_response(200, ['ok' => true, 'announcements' => $records]);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            announcement_json_response(405, ['ok' => false, 'message' => 'Use POST for this operation.']);
        }
        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '', true);
        if (!is_array($input)) {
            announcement_json_response(400, ['ok' => false, 'message' => 'Invalid request data.']);
        }

        if ($action === 'create' || $action === 'update') {
            $title = trim((string)($input['title'] ?? ''));
            $message = trim((string)($input['message'] ?? ''));
            $startValue = $input['startDate'] ?? null;
            $endValue = $input['endDate'] ?? null;
            $startDate = announcement_parse_datetime($startValue);
            $endDate = announcement_parse_datetime($endValue);

            if ($title === '' || announcement_length($title) < 3 || announcement_length($title) > 100) {
                announcement_json_response(422, ['ok' => false, 'message' => 'Title must be 3–100 characters long.']);
            }
            if ($message === '' || announcement_length($message) < 10 || announcement_length($message) > 1000) {
                announcement_json_response(422, ['ok' => false, 'message' => 'Message must be 10–1000 characters long.']);
            }
            if (!$startDate || !$endDate) {
                announcement_json_response(422, ['ok' => false, 'message' => 'Enter valid start and end dates and times.']);
            }
            if ($endDate <= $startDate) {
                announcement_json_response(422, ['ok' => false, 'message' => 'End Date & Time must be after Start Date & Time.']);
            }

            $startSql = $startDate->format('Y-m-d H:i:s');
            $endSql = $endDate->format('Y-m-d H:i:s');

            if ($action === 'create') {
                $stmt = $conn->prepare('INSERT INTO announcements (title, message, start_date, end_date) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('ssss', $title, $message, $startSql, $endSql);
                $stmt->execute();
                announcement_json_response(201, ['ok' => true, 'message' => 'Announcement published successfully.', 'announcementId' => (int)$conn->insert_id]);
            }

            $id = filter_var($input['announcementId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) announcement_json_response(422, ['ok' => false, 'message' => 'Invalid announcement ID.']);
            $check = $conn->prepare('SELECT announcement_id, start_date, end_date FROM announcements WHERE announcement_id = ?');
            $check->bind_param('i', $id);
            $check->execute();
            $old = $check->get_result()->fetch_assoc();
            if (!$old) announcement_json_response(404, ['ok' => false, 'message' => 'Announcement not found. Refresh the list and try again.']);
            if (!announcement_is_active($old)) announcement_json_response(409, ['ok' => false, 'message' => 'This announcement can only be edited during its active period.']);

            $stmt = $conn->prepare('UPDATE announcements SET title = ?, message = ?, start_date = ?, end_date = ? WHERE announcement_id = ?');
            $stmt->bind_param('ssssi', $title, $message, $startSql, $endSql, $id);
            $stmt->execute();
            announcement_json_response(200, ['ok' => true, 'message' => 'Announcement updated successfully.']);
        }

        if ($action === 'delete') {
            $id = filter_var($input['announcementId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) announcement_json_response(422, ['ok' => false, 'message' => 'Invalid announcement ID.']);
            $check = $conn->prepare('SELECT announcement_id, start_date, end_date FROM announcements WHERE announcement_id = ?');
            $check->bind_param('i', $id);
            $check->execute();
            $old = $check->get_result()->fetch_assoc();
            if (!$old) announcement_json_response(404, ['ok' => false, 'message' => 'Announcement not found. It may already have been deleted.']);
            if (!announcement_is_active($old)) announcement_json_response(409, ['ok' => false, 'message' => 'This announcement can only be deleted during its active period.']);
            $stmt = $conn->prepare('DELETE FROM announcements WHERE announcement_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            announcement_json_response(200, ['ok' => true, 'message' => 'Announcement deleted successfully.']);
        }

        announcement_json_response(400, ['ok' => false, 'message' => 'Unknown announcement action.']);
    } catch (Throwable $e) {
        error_log('Announcements PHP error: ' . $e->getMessage());
        announcement_json_response(500, ['ok' => false, 'message' => 'Database request failed. Check that Connection/db.php defines $conn and that the announcements table exists.']);
    }
}
?>
<!DOCTYPE html>

<html>

<head>

    <title>Library Announcements</title>

    <style>

        body {

            margin: 0;

            font-family: Arial;

            background-color: #f2f2f2;

        }

        /* Shared latest admin sidebar menu */
        .sidebar {
            width: 220px;
            height: 100vh;
            background-color: #333;
            position: fixed;
            left: 0;
            top: 0;
            color: white;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            z-index: 500;
        }
        .sidebar h2 {
            flex: 0 0 auto;
            text-align: center;
            padding: 20px 0;
            margin: 0;
            background-color: #222;
        }
        .sidebar-menu {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: thin;
            scrollbar-color: #666 #333;
        }
        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 14px 20px;
            border-bottom: 1px solid #555;
        }
        .sidebar a:hover { background-color: #555; }
        .sidebar a.active {
            background-color: #4b4b4b;
            border-left: 4px solid #fff;
            padding-left: 16px;
        }
        .manage {
            padding: 14px 20px;
            border-bottom: 1px solid #555;
            cursor: pointer;
            user-select: none;
        }
        .manage:hover { background-color: #555; }
        .manage:focus-visible { outline: 2px solid white; outline-offset: -3px; }
        .arrow { float: right; }
        .submenu { display: none; background-color: #444; }
        .submenu.open { display: block; }
        .submenu a { padding-left: 40px; font-size: 13px; }
        .submenu a.active { padding-left: 36px; }
        .logout {
            flex: 0 0 auto;
            width: 100%;
            padding-top: 8px;
            background-color: #333;
        }
        .logout a { background-color: #222; text-align: center; margin: 0; }

        /* Main Content */

        .main {

            margin-left: 220px;

            padding: 25px;

        }

        /* Top Area */

        .top {

            background-color: white;

            padding: 20px;

            border: 1px solid #ddd;

        }

        .top h1 {

            margin-top: 0;

            margin-bottom: 0;

            text-align: center;

        }

        /* Announcement Form */

        .announcement-form {

            background-color: white;

            margin-top: 25px;

            padding: 25px;

            border: 1px solid #ddd;

        }

        .announcement-form h2 {

            margin-top: 0;

            margin-bottom: 20px;

        }

        .form-group {

            margin-bottom: 18px;

        }

        .form-group label {

            display: block;

            font-weight: bold;

            margin-bottom: 8px;

        }

        .form-group input,

        .form-group textarea {

            width: 100%;

            padding: 11px;

            box-sizing: border-box;

            border: 1px solid #ccc;

            font-family: Arial;

            font-size: 15px;

        }

        .form-group textarea {

            height: 120px;

            resize: vertical;

        }

        .required {
            color: #b30000;
            font-weight: bold;
        }
        .form-group input.invalid,
        .form-group textarea.invalid {
            border: 2px solid #c9302c !important;
            outline: none;
            box-shadow: none;
        }
        .form-error {
            display: none;
            color: #c9302c;
            font-size: 13px;
            line-height: 1.4;
            margin-top: 6px;
        }
        .form-error.show { display: block; }
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #465660;
            outline: none;
        }
        .form-group input.invalid:focus,
        .form-group textarea.invalid:focus {
            border-color: #c9302c;
        }


        /* Date and Time */

        .date-row {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 20px;

        }

        /* Buttons */

        .button-area {

            margin-top: 5px;

        }

        .btn {

            padding: 11px 22px;

            border: none;

            cursor: pointer;

            font-size: 15px;

            font-weight: bold;

            margin-right: 10px;

        }

        .publish-btn {

            background-color: #333;

            color: white;

        }

        .publish-btn:hover {

            background-color: #222;

        }

        .clear-btn {

            background-color: #ddd;

            color: #222;

        }

        .clear-btn:hover {

            background-color: #ccc;

        }

        /* Existing Announcements */

        .recent {

            background-color: white;

            margin-top: 25px;

            padding: 25px;

            border: 1px solid #ddd;

        }

        .recent h2 {

            margin-top: 0;

        }

        /* Table */

        .recent table {

            width: 100%;

            border-collapse: collapse;

        }

        .recent th,

        .recent td {

            padding: 12px;

            border: 1px solid #ccc;

            text-align: left;

        }

        .recent th {

            background-color: #eee;

        }

        .recent td {

            vertical-align: top;

        }

        /* Action Buttons */

        .edit-btn {

            background-color: #333;

            color: white;

            border: none;

            padding: 7px 13px;

            cursor: pointer;

            margin-right: 5px;

            margin-bottom: 5px;

        }

        .edit-btn:hover {

            background-color: #222;

        }

        .delete-btn {

            background-color: #b30000;

            color: white;

            border: none;

            padding: 7px 13px;

            cursor: pointer;

        }

        .delete-btn:hover {

            background-color: #8f0000;

        }

        /* Disabled Buttons */

        .disabled-btn {

            background-color: #ccc !important;

            color: #777 !important;

            cursor: not-allowed !important;

        }

        /* Status Button */

        .status-btn {

            background-color: #555;

            color: white;

            border: none;

            padding: 7px 13px;

            cursor: pointer;

            margin-top: 6px;

        }

        .status-btn:hover {

            background-color: #333;

        }

        /* Status */

        .scheduled {

            font-weight: bold;

            color: #555;

        }

        .published {

            font-weight: bold;

            color: #222;

        }

        .expired {

            font-weight: bold;

            color: #b30000;

        }

        /* No Data */

        .no-data {

            text-align: center;

            padding: 20px;

            color: #555;

        }

        /* Responsive */

        @media (max-width: 800px) {

            .sidebar {

                width: 180px;

            }

            .main {

                margin-left: 180px;

            }

            .date-row {

                grid-template-columns: 1fr;

            }

            .recent {

                overflow-x: auto;

            }

            .recent table {

                min-width: 900px;

            }

        }

    
        /* Shared content-card styling to match the other admin pages */
        .main {
            margin-left: 220px;
            padding: 0 25px 25px;
            min-width: 0;
        }
        .top {
            margin-top: 10px;
            background-color: #fff;
            padding: 20px;
            border: 1px solid #ddd;
        }
        .top h1 {
            margin: 0;
            text-align: center;
            color: #333;
            font-size: 36px;
        }
        .announcement-form,
        .recent {
            background-color: #fff;
            margin-top: 25px;
            padding: 25px;
            border: 1px solid #ddd;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
        }
        /* Keep the title card and Add New Announcement card visually connected.
           This is a spacing-only adjustment; original colors and card styling stay unchanged. */
        .announcement-form { margin-top: 12px; }
        .announcement-form h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #333;
            font-size: 24px;
            text-align: center;
        }
        .recent h2 {
            margin-top: 0;
            margin-bottom: 18px;
            color: #333;
            font-size: 24px;
        }
        .form-group { min-width: 0; margin-bottom: 18px; }
        .form-group label { color: #333; font-size: 15px; margin-bottom: 8px; }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 11px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 0;
            font-family: Arial, sans-serif;
            font-size: 15px;
            background: #fff;
            color: #333;
            box-shadow: none;
        }
        .form-group input { min-height: 42px; }
        .form-group textarea { min-height: 120px; height: 120px; resize: vertical; line-height: 1.45; }
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #465660;
            outline: none;
            box-shadow: none;
        }
        .form-group input.invalid,
        .form-group textarea.invalid,
        .form-group input.invalid:focus,
        .form-group textarea.invalid:focus {
            border: 2px solid #c9302c !important;
            outline: none;
            box-shadow: none;
        }
        .form-error { font-size: 13px; margin-top: 6px; }
        .date-row { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; }
        .button-area { display: block; margin-top: 5px; padding-top: 0; }
        .btn {
            min-height: auto;
            padding: 11px 22px;
            border: none;
            border-radius: 0;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
            margin-right: 10px;
        }
        .publish-btn { background-color: #333; color: #fff; }
        .publish-btn:hover { background-color: #222; }
        .clear-btn { background-color: #ddd; color: #222; }
        .clear-btn:hover { background-color: #ccc; }
        .table-wrap { width: 100%; max-width: 100%; overflow-x: auto; overflow-y: hidden; }
        .recent table { width: 100%; min-width: 900px; border-collapse: collapse; }
        .recent th,
        .recent td { padding: 12px; border: 1px solid #ccc; text-align: left; }
        .recent th { background-color: #eee; color: #333; white-space: nowrap; }
        .recent td { vertical-align: top; overflow-wrap: anywhere; }
        .edit-btn { border-radius: 0; padding: 7px 13px; font-size: 14px; }
        .delete-btn { border-radius: 0; padding: 7px 13px; font-size: 14px; }
        .scheduled,
        .published,
        .expired { display: inline; padding: 0; border-radius: 0; font-size: inherit; line-height: inherit; white-space: normal; background: transparent; }
        .scheduled { color: #555; }
        .published { color: #222; }
        .expired { color: #b30000; }
        .no-data { text-align: center; padding: 20px; color: #555; background: #fff; font-size: 15px; }

        @media (max-width: 800px) {
            .sidebar { width: 180px; }
            .main { margin-left: 180px; padding: 0 15px 20px; }
            .date-row { grid-template-columns: minmax(0, 1fr); gap: 0; }
            .recent { overflow: visible; }
            .table-wrap { overflow-x: auto; }
            .recent table { min-width: 900px; }
        }
        @media (max-width: 540px) {
            .sidebar { width: 150px; }
            .sidebar a, .manage { padding: 12px; font-size: 13px; }
            .sidebar h2 { font-size: 20px; }
            .main { margin-left: 150px; padding: 0 10px 15px; }
            .top, .announcement-form, .recent { padding: 14px; }
            .top h1 { font-size: 27px; }
            .button-area .btn { margin-bottom: 8px; }
            .logout { padding-top: 8px; }
        }


        /* In-app dialogs: avoid native JavaScript alert()/confirm() in PHP Desktop. */
        .app-dialog-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 5000;
            padding: 20px;
            background: rgba(0, 0, 0, 0.48);
            align-items: center;
            justify-content: center;
        }
        .app-dialog-overlay.open { display: flex; }
        .app-dialog-box {
            width: 460px;
            max-width: 100%;
            background: #fff;
            color: #333;
            border: 1px solid #ddd;
            box-shadow: 0 12px 38px rgba(0, 0, 0, 0.28);
            padding: 26px;
        }
        .app-dialog-box h2 {
            margin: 0 0 14px;
            color: #333;
            font-size: 22px;
            line-height: 1.3;
        }
        .app-dialog-box p {
            margin: 0;
            font-size: 15px;
            line-height: 1.55;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }
        .app-dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }
        .app-dialog-actions button {
            min-width: 90px;
            padding: 10px 17px;
            border: 0;
            border-radius: 0;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }
        .app-dialog-cancel { background: #ddd; color: #222; }
        .app-dialog-cancel:hover { background: #ccc; }
        .app-dialog-ok { background: #333; color: #fff; }
        .app-dialog-ok:hover { background: #222; }
        .app-dialog-ok.danger { background: #b30000; }
        .app-dialog-ok.danger:hover { background: #8f0000; }
        .app-dialog-actions button:focus-visible { outline: 2px solid #465660; outline-offset: 3px; }
        @media (max-width: 540px) {
            .app-dialog-box { padding: 20px; }
            .app-dialog-box h2 { font-size: 20px; }
        }

    </style>

</head>

<body>

    <!-- Shared latest admin sidebar menu -->
    <aside class="sidebar">
        <h2>Library</h2>
        <nav class="sidebar-menu" aria-label="Admin navigation">
            <a href="admin_home.php">Dashboard</a>
            <a href="my_profile.php">My Profile</a>
            <a href="library_profile.php">Library Profile</a>

            <div class="manage" id="manageBooksToggle" role="button" tabindex="0"
                 aria-expanded="false" aria-controls="submenu" onclick="toggleMenu()" onkeydown="handleMenuKeydown(event)">
                Manage Books <span class="arrow" id="arrow">+</span>
            </div>
            <div class="submenu" id="submenu">
                <a href="view_books.php">View All Books</a>
                <a href="add_book.php">Add Book</a>
                <a href="update_book.php">Update / Delete Book</a>
            </div>

            <a href="issue_book.php">Issue Book</a>
            <a href="return_book.php">Return Book</a>
            <a href="members.php">Members Details</a>
            <a href="announcements.php" class="active" aria-current="page">Announcements</a>
        </nav>
        <div class="logout"><a href="../login/login_form.php">Logout</a></div>
    </aside>

    <!-- Main Content -->

    <div class="main">

        <!-- Top -->

                <div class="top">
            <div class="page-heading">
                <h1>Library Announcements</h1>
            </div>
        </div>

        <!-- Add Announcement -->

        <div class="announcement-form">

            <h2 id="formTitle" style="text-align: center;">

                Add New Announcement

            </h2>

            <form id="announcementForm" novalidate>

                <!-- Announcement Title -->

                <div class="form-group">

                    <label for="title">Announcement Title <span class="required">*</span></label>

                    <input

                        type="text"

                        id="title"

                        placeholder="Enter announcement title"

                        required

                     maxlength="100" aria-describedby="titleError">
                    <div class="form-error" id="titleError" role="alert"></div>

                </div>

                <!-- Announcement Message -->

                <div class="form-group">

                    <label for="message">Announcement Message <span class="required">*</span></label>

                    <textarea
                        id="message"
                        placeholder="Enter announcement message..."
                        maxlength="1000"
                        aria-describedby="messageError"
                        required
                    ></textarea>
                    <div class="form-error" id="messageError" role="alert"></div>

                </div>

                <!-- Start and End Date -->

                <div class="date-row">

                    <!-- Start Date -->

                    <div class="form-group">

                        <label for="startDate">Start Date &amp; Time <span class="required">*</span></label>

                        <input

                            type="datetime-local"

                            id="startDate"

                            required

                         aria-describedby="startDateError">
                        <div class="form-error" id="startDateError" role="alert"></div>

                    </div>

                    <!-- End Date -->

                    <div class="form-group">

                        <label for="endDate">End Date &amp; Time <span class="required">*</span></label>

                        <input

                            type="datetime-local"

                            id="endDate"

                            required

                         aria-describedby="endDateError">
                        <div class="form-error" id="endDateError" role="alert"></div>

                    </div>

                </div>

                <!-- Buttons -->

                <div class="button-area">

                    <button

                        type="submit"

                        class="btn publish-btn"

                        id="submitButton"

                    >

                        Publish Announcement

                    </button>

                    <button

                        type="button"

                        class="btn clear-btn"

                        onclick="clearForm()"

                    >

                        Clear

                    </button>

                </div>

            </form>

        </div>

        <!-- Existing Announcements -->

        <div class="recent">

            <h2>

                Existing Announcements

            </h2>

            <!-- Announcement Table -->

            <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>

                            Title

                        </th>

                        <th>

                            Message

                        </th>

                        <th>

                            Start Date & Time

                        </th>

                        <th>

                            End Date & Time

                        </th>

                        <th>

                            Status

                        </th>

                        <th>

                            Action

                        </th>

                    </tr>

                </thead>

                <tbody id="announcementTable">

                </tbody>

            </table>

            </div>

        </div>

    </div>

    <!-- Custom dialog overlay stays inside the desktop app window. -->
    <div class="app-dialog-overlay" id="appDialogOverlay" aria-hidden="true">
        <section class="app-dialog-box" role="alertdialog" aria-modal="true"
                 aria-labelledby="appDialogTitle" aria-describedby="appDialogMessage">
            <h2 id="appDialogTitle">Notice</h2>
            <p id="appDialogMessage"></p>
            <div class="app-dialog-actions">
                <button type="button" id="appDialogCancel" class="app-dialog-cancel">Cancel</button>
                <button type="button" id="appDialogOk" class="app-dialog-ok">OK</button>
            </div>
        </section>
    </div>

    <script>

        /* ==========================================

           MANAGE BOOKS MENU

        ========================================== */

        /* Custom in-app message/confirmation dialogs for PHP Desktop. */
        var appDialogResolver = null;
        var appDialogPreviousFocus = null;

        function showAppDialog(message, options) {
            options = options || {};
            var overlay = document.getElementById("appDialogOverlay");
            var title = document.getElementById("appDialogTitle");
            var messageElement = document.getElementById("appDialogMessage");
            var cancelButton = document.getElementById("appDialogCancel");
            var okButton = document.getElementById("appDialogOk");
            var isConfirm = options.confirm === true;

            // Finish any prior dialog safely before opening another one.
            if (typeof appDialogResolver === "function") {
                var previousResolver = appDialogResolver;
                appDialogResolver = null;
                previousResolver(false);
            }

            title.textContent = options.title || (isConfirm ? "Please Confirm" : "Notice");
            messageElement.textContent = String(message == null ? "" : message);
            cancelButton.textContent = options.cancelText || "Cancel";
            cancelButton.style.display = isConfirm ? "inline-block" : "none";
            okButton.textContent = options.okText || (isConfirm ? "Continue" : "OK");
            okButton.classList.toggle("danger", options.danger === true);
            appDialogPreviousFocus = document.activeElement;
            overlay.classList.add("open");
            overlay.setAttribute("aria-hidden", "false");
            document.body.style.overflow = "hidden";

            return new Promise(function (resolve) {
                appDialogResolver = resolve;
                // Default to Cancel for confirmations to avoid accidental deletion on Enter.
                (isConfirm ? cancelButton : okButton).focus();
            });
        }

        function finishAppDialog(confirmed) {
            var overlay = document.getElementById("appDialogOverlay");
            overlay.classList.remove("open");
            overlay.setAttribute("aria-hidden", "true");
            document.body.style.overflow = "";
            var resolver = appDialogResolver;
            appDialogResolver = null;
            if (typeof resolver === "function") resolver(Boolean(confirmed));
            if (appDialogPreviousFocus && typeof appDialogPreviousFocus.focus === "function") {
                appDialogPreviousFocus.focus();
            }
            appDialogPreviousFocus = null;
        }

        function showAppMessage(message, title) {
            return showAppDialog(message, { title: title || "Notice" });
        }

        function showAppConfirm(message, title, options) {
            options = options || {};
            options.confirm = true;
            options.title = title || "Please Confirm";
            return showAppDialog(message, options);
        }

        document.getElementById("appDialogOk").addEventListener("click", function () {
            finishAppDialog(true);
        });
        document.getElementById("appDialogCancel").addEventListener("click", function () {
            finishAppDialog(false);
        });
        document.getElementById("appDialogOverlay").addEventListener("click", function (event) {
            if (event.target === this && document.getElementById("appDialogCancel").style.display !== "none") {
                finishAppDialog(false);
            }
        });
        document.addEventListener("keydown", function (event) {
            var overlay = document.getElementById("appDialogOverlay");
            if (!overlay.classList.contains("open")) return;
            if (event.key === "Escape") {
                event.preventDefault();
                finishAppDialog(false);
            } else if (event.key === "Enter" && event.target.tagName !== "TEXTAREA") {
                // Let the focused dialog button handle Enter; do not submit the underlying form.
                if (event.target === document.getElementById("appDialogOk")) {
                    event.preventDefault();
                    finishAppDialog(true);
                } else if (event.target === document.getElementById("appDialogCancel")) {
                    event.preventDefault();
                    finishAppDialog(false);
                }
            }
        });

        function toggleMenu() {
            var menu = document.getElementById("submenu");
            var arrow = document.getElementById("arrow");
            var toggle = document.getElementById("manageBooksToggle");
            var isOpen = menu.classList.toggle("open");
            arrow.textContent = isOpen ? "-" : "+";
            toggle.setAttribute("aria-expanded", String(isOpen));
        }

        function handleMenuKeydown(event) {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                toggleMenu();
            }
        }

        /* Announcement records are loaded from MySQL through this PHP page. */
        var announcements = [];
        var editIndex = -1;

        /* ==========================================

           SET DEFAULT DATE AND TIME

        ========================================== */

        function setDefaultDateTime() {
            var now = new Date();
            var year = now.getFullYear();
            var month = String(now.getMonth() + 1).padStart(2, "0");
            var day = String(now.getDate()).padStart(2, "0");
            var hours = String(now.getHours()).padStart(2, "0");
            var minutes = String(now.getMinutes()).padStart(2, "0");
            var currentDateTime = year + "-" + month + "-" + day + "T" + hours + ":" + minutes;

            // Keep Start Date & Time convenient, but let the librarian choose the End Date & Time.
            document.getElementById("startDate").value = currentDateTime;
            document.getElementById("endDate").value = "";
        }

        setDefaultDateTime();

        /* ==========================================

           GET ANNOUNCEMENT STATUS

        ========================================== */

        function getStatus(announcement) {

            var now =

                new Date();

            var start =

                new Date(

                    announcement.startDate

                );

            var end =

                new Date(

                    announcement.endDate

                );

            if (now < start) {

                return "Scheduled";

            }

            if (

                now >= start &&

                now <= end

            ) {

                return "Published";

            }

            if (now > end) {

                return "Expired";

            }

        }

        /* ==========================================

           DISPLAY ANNOUNCEMENTS

        ========================================== */

        function displayAnnouncements() {

            var table =

                document.getElementById(

                    "announcementTable"

                );

            table.innerHTML = "";

            if (announcements.length === 0) {

                table.innerHTML =

                    '<tr>' +

                    '<td colspan="6" class="no-data">' +

                    'No announcements found.' +

                    '</td>' +

                    '</tr>';

                return;

            }

            announcements.forEach(

                function (announcement, index) {

                    var status =

                        getStatus(

                            announcement

                        );

                    var statusClass =

                        "";

                    if (status === "Published") {

                        statusClass =

                            "published";

                    }

                    else if (

                        status === "Scheduled"

                    ) {

                        statusClass =

                            "scheduled";

                    }

                    else {

                        statusClass =

                            "expired";

                    }

                    /*

                     * Edit/Delete are available

                     * ONLY during the active

                     * announcement period.

                     */

                    var isActive =

                        status === "Published";

                    var actionButtons = "";

                    if (isActive) {

                        actionButtons = `

                            <button

                                class="edit-btn"

                                onclick="editAnnouncement(${index})"

                            >

                                Edit

                            </button>

                            <button

                                class="delete-btn"

                                onclick="deleteAnnouncement(${index})"

                            >

                                Delete

                            </button>

                        `;

                    }

                    else {

                        actionButtons = `

                            <button

                                class="edit-btn disabled-btn"

                                disabled

                                title="Edit is available only during the announcement period"

                            >

                                Edit

                            </button>

                            <button

                                class="delete-btn disabled-btn"

                                disabled

                                title="Delete is available only during the announcement period"

                            >

                                Delete

                            </button>

                        `;

                    }

                    var row =

                        document.createElement(

                            "tr"

                        );

                    row.innerHTML = `

                        <td>

                            <strong>

                                ${escapeHTML(

                                    announcement.title

                                )}

                            </strong>

                        </td>

                        <td>

                            ${escapeHTML(

                                announcement.message

                            )}

                        </td>

                        <td>

                            ${formatDateTime(

                                announcement.startDate

                            )}

                        </td>

                        <td>

                            ${formatDateTime(

                                announcement.endDate

                            )}

                        </td>

                        <td>

                            <span

                                class="${statusClass}"

                            >

                                ${status}

                            </span>

                        </td>

                        <td>

                            ${actionButtons}

                        </td>

                    `;

                    table.appendChild(row);

                }

            );

        }

        
        /* ANNOUNCEMENT FORM VALIDATION */
        function setFieldValidation(fieldId, errorId, message) {
            var field = document.getElementById(fieldId);
            var error = document.getElementById(errorId);
            field.classList.toggle("invalid", Boolean(message));
            field.setAttribute("aria-invalid", message ? "true" : "false");
            error.textContent = message || "";
            error.classList.toggle("show", Boolean(message));
        }

        function validateTitleField() {
            var value = document.getElementById("title").value.trim();
            var message = "";
            if (value === "") {
                message = "Please enter an announcement title.";
            } else if (value.length < 3) {
                message = "Title must contain at least 3 characters.";
            } else if (value.length > 100) {
                message = "Title cannot exceed 100 characters.";
            } else if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(value)) {
                message = "Title contains invalid control characters.";
            }
            setFieldValidation("title", "titleError", message);
            return message === "";
        }

        function validateMessageField() {
            var value = document.getElementById("message").value.trim();
            var message = "";
            if (value === "") {
                message = "Please enter the announcement message.";
            } else if (value.length < 10) {
                message = "Message must contain at least 10 characters.";
            } else if (value.length > 1000) {
                message = "Message cannot exceed 1000 characters.";
            } else if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(value)) {
                message = "Message contains invalid control characters.";
            }
            setFieldValidation("message", "messageError", message);
            return message === "";
        }

        function validateDateFields() {
            var startValue = document.getElementById("startDate").value;
            var endValue = document.getElementById("endDate").value;
            var startMessage = "";
            var endMessage = "";

            if (!startValue) {
                startMessage = "Please select the start date and time.";
            } else if (Number.isNaN(new Date(startValue).getTime())) {
                startMessage = "Please enter a valid start date and time.";
            }

            if (!endValue) {
                endMessage = "Please select the end date and time.";
            } else if (Number.isNaN(new Date(endValue).getTime())) {
                endMessage = "Please enter a valid end date and time.";
            }

            if (!startMessage && !endMessage && new Date(endValue) <= new Date(startValue)) {
                endMessage = "End date and time must be later than the start date and time.";
            }

            setFieldValidation("startDate", "startDateError", startMessage);
            setFieldValidation("endDate", "endDateError", endMessage);
            return startMessage === "" && endMessage === "";
        }

        function validateAnnouncementForm() {
            var titleValid = validateTitleField();
            var messageValid = validateMessageField();
            var datesValid = validateDateFields();

            if (!titleValid) {
                document.getElementById("title").focus();
            } else if (!messageValid) {
                document.getElementById("message").focus();
            } else if (!datesValid) {
                if (document.getElementById("startDate").classList.contains("invalid")) {
                    document.getElementById("startDate").focus();
                } else {
                    document.getElementById("endDate").focus();
                }
            }
            return titleValid && messageValid && datesValid;
        }

        function clearValidationErrors() {
            setFieldValidation("title", "titleError", "");
            setFieldValidation("message", "messageError", "");
            setFieldValidation("startDate", "startDateError", "");
            setFieldValidation("endDate", "endDateError", "");
        }

        ["title", "message"].forEach(function (fieldId) {
            var field = document.getElementById(fieldId);
            var validate = fieldId === "title" ? validateTitleField : validateMessageField;
            field.addEventListener("blur", validate);
            field.addEventListener("input", function () {
                if (field.classList.contains("invalid")) validate();
            });
        });

        ["startDate", "endDate"].forEach(function (fieldId) {
            var field = document.getElementById(fieldId);
            field.addEventListener("blur", validateDateFields);
            field.addEventListener("change", function () {
                if (field.classList.contains("invalid") ||
                    document.getElementById("startDate").classList.contains("invalid") ||
                    document.getElementById("endDate").classList.contains("invalid")) {
                    validateDateFields();
                }
            });
        });


        /* ==========================================
           ADD / UPDATE ANNOUNCEMENT (MySQL via PHP)
        ========================================== */
        document.getElementById("announcementForm").addEventListener("submit", async function (event) {
            event.preventDefault();
            if (!validateAnnouncementForm()) return;

            var title = document.getElementById("title").value.trim();
            var message = document.getElementById("message").value.trim();
            var startDate = document.getElementById("startDate").value;
            var endDate = document.getElementById("endDate").value;

            if (!title || !message || !startDate || !endDate) {
                await showAppMessage("Please fill all the fields.", "Incomplete form");
                return;
            }
            if (new Date(endDate) <= new Date(startDate)) {
                await showAppMessage("End Date & Time must be after Start Date & Time.", "Invalid date range");
                return;
            }

            var editing = editIndex !== -1;
            var oldAnnouncement = editing ? announcements[editIndex] : null;
            if (editing && !oldAnnouncement) {
                await showAppMessage("This announcement is no longer available. The list will be refreshed.", "Announcement unavailable");
                await loadAnnouncements(false);
                clearForm();
                return;
            }
            if (editing && getStatus(oldAnnouncement) !== "Published") {
                await showAppMessage("This announcement can no longer be edited because it is outside its active period.", "Announcement inactive");
                clearForm();
                return;
            }

            var payload = {
                title: title,
                message: message,
                startDate: startDate,
                endDate: endDate
            };
            if (editing) payload.announcementId = oldAnnouncement.announcementId;

            var submitButton = document.getElementById("submitButton");
            submitButton.disabled = true;
            try {
                await apiRequest(editing ? "update" : "create", payload);
                await showAppMessage(editing ? "Announcement updated successfully." : "Announcement published successfully.", "Success");
                await loadAnnouncements(false);
                clearForm();
            } catch (error) {
                await showAppMessage(error.message || "The announcement could not be saved. Please try again.", "Save failed");
            } finally {
                submitButton.disabled = false;
            }
        });

        /* ==========================================

           CLEAR FORM

        ========================================== */

        function clearForm() {

            document

                .getElementById(

                    "announcementForm"

                )

                .reset();

            clearValidationErrors();

            setDefaultDateTime();

            editIndex = -1;

            document.getElementById(

                "formTitle"

            ).innerText =

                "Add New Announcement";

            document.getElementById(

                "submitButton"

            ).innerText =

                "Publish Announcement";

        }

        /* ==========================================
           EDIT ANNOUNCEMENT
        ========================================== */
        function editAnnouncement(index) {
            var announcement = announcements[index];
            if (!announcement) return;

            if (getStatus(announcement) !== "Published") {
                showAppMessage("This announcement can only be edited during its active period.", "Announcement inactive");
                return;
            }

            document.getElementById("title").value = announcement.title;
            document.getElementById("message").value = announcement.message;
            document.getElementById("startDate").value = announcement.startDate;
            document.getElementById("endDate").value = announcement.endDate;
            clearValidationErrors();
            editIndex = index;
            document.getElementById("formTitle").innerText = "Update Announcement";
            document.getElementById("submitButton").innerText = "Update Announcement";
            window.scrollTo({ top: 0, behavior: "smooth" });
        }

        /* ==========================================
           DELETE ANNOUNCEMENT
        ========================================== */
        async function deleteAnnouncement(index) {
            var announcement = announcements[index];
            if (!announcement) return;

            if (getStatus(announcement) !== "Published") {
                showAppMessage("This announcement can only be deleted during its active period.", "Announcement inactive");
                return;
            }
            if (!(await showAppConfirm("Are you sure you want to delete this announcement?", "Delete announcement", { okText: "Delete", danger: true }))) return;

            try {
                await apiRequest("delete", { announcementId: announcement.announcementId });
                if (editIndex === index) clearForm();
                await loadAnnouncements(false);
                await showAppMessage("Announcement deleted successfully.", "Success");
            } catch (error) {
                await showAppMessage(error.message || "The announcement could not be deleted. Please try again.", "Delete failed");
            }
        }

        /* ==========================================
           PHP API HELPERS / LOAD DATABASE RECORDS
        ========================================== */
        async function apiRequest(action, payload) {
            var options = { method: payload ? "POST" : "GET", headers: { "Accept": "application/json", "Cache-Control": "no-cache" }, credentials: "same-origin", cache: "no-store" };
            if (payload) {
                options.headers["Content-Type"] = "application/json";
                options.body = JSON.stringify(payload);
            }
            var response = await fetch(window.location.pathname + "?action=" + encodeURIComponent(action), options);
            var data;
            try {
                data = await response.json();
            } catch (_) {
                throw new Error("The server returned an unexpected response. Check the PHP error log.");
            }
            if (!response.ok || !data || data.ok !== true) {
                throw new Error((data && data.message) || "The database request failed.");
            }
            return data;
        }

        async function loadAnnouncements(showError) {
            try {
                var result = await apiRequest("list");
                announcements = Array.isArray(result.announcements) ? result.announcements : [];
                displayAnnouncements();
            } catch (error) {
                var table = document.getElementById("announcementTable");
                table.innerHTML = '<tr><td colspan="6" class="no-data">' + escapeHTML(error.message || "Unable to load announcements from the database.") + '</td></tr>';
                if (showError) {
                    await showAppMessage("Could not load announcements from MySQL. Check Connection/db.php and the announcements table.\n\n" + (error.message || "Unknown error"), "Could not load announcements");
                }
            }
        }

        /* ==========================================

           FORMAT DATE & TIME

        ========================================== */

        function formatDateTime(

            dateTime

        ) {

            var date =

                new Date(

                    dateTime

                );

            return date.toLocaleString(

                "en-IN",

                {

                    day: "2-digit",

                    month: "2-digit",

                    year: "numeric",

                    hour: "2-digit",

                    minute: "2-digit"

                }

            );

        }

        /* ==========================================

           ESCAPE HTML

        ========================================== */

        function escapeHTML(text) {

            var div =

                document.createElement(

                    "div"

                );

            div.textContent =

                text;

            return div.innerHTML;

        }

        /* ==========================================

           INITIAL DISPLAY

        ========================================== */

        // Load the persisted records from MySQL on initial page startup.
        loadAnnouncements(true);

        /* ==========================================

           AUTO REFRESH STATUS

        ========================================== */

        setInterval(

            function () {

                displayAnnouncements();

            },

            30000

        );

    </script>

</body>

</html>