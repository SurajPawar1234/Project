<?php
declare(strict_types=1);

/* Use the exact session keys created by login_form.php. */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');

/* Handle logout here so the session is actually destroyed. */
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }
    session_destroy();
    header('Location: ../Login/login_form.php');
    exit;
}

$sessionUserId = filter_var($_SESSION['User_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sessionRole = (string)($_SESSION['Role'] ?? $_SESSION['role'] ?? '');
if ($sessionUserId === false || $sessionUserId === null) {
    header('Location: ../Login/login_form.php');
    exit;
}
if ($sessionRole !== 'Member') {
    if ($sessionRole === 'Admin') {
        header('Location: ../Admin/Admin_Home.php');
    } else {
        header('Location: ../Login/login_form.php');
    }
    exit;
}

/* Database connection: supports Connection/db.php or db.php in the project. */
$conn = null;
$dbLoadError = '';
$dbCandidates = [
    __DIR__ . '/Connection/db.php',
    dirname(__DIR__) . '/Connection/db.php',
    dirname(__DIR__, 2) . '/Connection/db.php',
    __DIR__ . '/db.php',
    dirname(__DIR__) . '/db.php',
];
foreach (array_unique($dbCandidates) as $dbFile) {
    if (!is_file($dbFile)) continue;
    try {
        require_once $dbFile;
    } catch (Throwable $e) {
        $dbLoadError = 'Unable to load the database connection. Check Connection/db.php.';
        error_log('Member portal connection include failed: ' . $e->getMessage());
    }
    if (!($conn instanceof mysqli)) {
        foreach (['con', 'connection', 'mysqli'] as $connectionVar) {
            if (isset($$connectionVar) && $$connectionVar instanceof mysqli) {
                $conn = $$connectionVar;
                break;
            }
        }
    }
    if ($conn instanceof mysqli) break;
}
if (!($conn instanceof mysqli) && $dbLoadError === '') {
    $dbLoadError = 'Database connection was not found. Place this page where it can access Connection/db.php.';
}
if ($conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

function member_portal_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function member_portal_json($value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
}
function member_portal_date($value, bool $withTime = false): string {
    if ($value === null || trim((string)$value) === '') return '—';
    $timestamp = strtotime((string)$value);
    if ($timestamp === false) return '—';
    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
}
function member_portal_json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* Identify the member from the authenticated login session, not a URL parameter. */
$member = null;
if ($conn instanceof mysqli) {
    try {
        $memberSelect = "SELECT m.Member_id, m.User_id, m.First_name, m.Last_name, m.Email, m.Address, m.Phone_no, l.Username, l.Role
                         FROM member m
                         INNER JOIN login l ON l.User_id = m.User_id
                         WHERE m.User_id = ? AND l.User_id = ? AND l.Role = 'Member'
                         LIMIT 1";
        $stmt = $conn->prepare($memberSelect);
        $stmt->bind_param('ii', $sessionUserId, $sessionUserId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Member portal could not identify the session member: ' . $e->getMessage());
    }
}

$memberAuthMessage = '';
if (!($conn instanceof mysqli)) {
    $memberAuthMessage = $dbLoadError ?: 'The library database is unavailable. Please check the connection settings.';
} elseif (!$member) {
    $memberAuthMessage = 'No member record is linked to this login. Check that member.User_id matches login.User_id.';
}
$memberId = $member ? (int)$member['Member_id'] : 0;
$memberName = $member ? trim((string)$member['First_name'] . ' ' . (string)$member['Last_name']) : '';
$currentMemberData = [
    'id' => $member ? (string)$memberId : '',
    'userId' => $member ? (string)$member['User_id'] : '',
    'name' => $memberName,
    'username' => $member ? (string)$member['Username'] : '',
    'email' => $member ? (string)$member['Email'] : '',
    'mobile' => $member ? (string)$member['Phone_no'] : '',
    'address' => $member ? (string)$member['Address'] : '',
];

$books = [];
$issuedBooks = [];
$memberRequests = [];
$activeAnnouncements = [];
$libraryDetails = [
    'name' => '', 'address' => '', 'openingTime' => '', 'closingTime' => '',
    'workingDays' => '', 'holidaysInfo' => '', 'contactNumber' => '', 'email' => ''
];
$finePerDay = 0.0;
$loadWarnings = [];

if ($conn instanceof mysqli) {
    try {
        $bookSql = "SELECT b.Book_id, b.Title, b.Author, b.ISBN, b.Category, b.Status, b.Quantity,
                           GREATEST(COALESCE(b.Quantity, 0) - COALESCE(active.ActiveCount, 0), 0) AS AvailableCopies
                    FROM book b
                    LEFT JOIN (SELECT Book_id, COUNT(*) AS ActiveCount FROM issue_return WHERE Return_date IS NULL GROUP BY Book_id) active
                      ON active.Book_id = b.Book_id
                    ORDER BY b.Book_id ASC";
        $result = $conn->query($bookSql);
        while ($row = $result->fetch_assoc()) {
            $availableCopies = (int)$row['AvailableCopies'];
            $books[] = [
                'bookId' => (int)$row['Book_id'],
                'name' => (string)$row['Title'],
                'author' => (string)$row['Author'],
                'isbn' => (string)$row['ISBN'],
                'category' => (string)$row['Category'],
                'status' => $availableCopies > 0 ? 'Available' : 'Issued',
                'availableCopies' => $availableCopies,
            ];
        }
        $result->free();
    } catch (Throwable $e) {
        $loadWarnings[] = 'Books could not be loaded from the database.';
        error_log('Member portal book query failed: ' . $e->getMessage());
    }

    try {
        $fineResult = $conn->query('SELECT Fine_per_day FROM fine_setting ORDER BY Id LIMIT 1');
        if ($fineResult instanceof mysqli_result) {
            $fineRow = $fineResult->fetch_assoc();
            $finePerDay = $fineRow ? (float)$fineRow['Fine_per_day'] : 0.0;
            $fineResult->free();
        }
    } catch (Throwable $e) {
        error_log('Member portal fine setting query failed: ' . $e->getMessage());
    }

    if ($memberId > 0) {
        try {
            $stmt = $conn->prepare("SELECT ir.Issue_id, ir.Book_id, b.Title, b.Author, b.ISBN, ir.Issue_date, ir.Due_date, ir.Status, ir.Fine FROM issue_return ir INNER JOIN book b ON b.Book_id = ir.Book_id WHERE ir.Member_id = ? AND ir.Return_date IS NULL ORDER BY ir.Issue_date DESC, ir.Issue_id DESC");
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            $today = new DateTimeImmutable('today');
            while ($row = $result->fetch_assoc()) {
                $storedFine = (float)($row['Fine'] ?? 0);
                $due = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$row['Due_date']);
                $fine = $storedFine;
                $status = (string)($row['Status'] ?: 'Issued');
                if ($due instanceof DateTimeImmutable && $due < $today) {
                    $daysOverdue = (int)$due->diff($today)->days;
                    $fine = max($storedFine, $daysOverdue * $finePerDay);
                    $status = 'Overdue';
                }
                $issuedBooks[] = [
                    'issueId' => (int)$row['Issue_id'],
                    'bookId' => (int)$row['Book_id'],
                    'name' => (string)$row['Title'],
                    'author' => (string)$row['Author'],
                    'isbn' => (string)$row['ISBN'],
                    'issueDate' => member_portal_date($row['Issue_date']),
                    'dueDate' => member_portal_date($row['Due_date']),
                    'fine' => '₹' . number_format($fine, 2),
                    'fineAmount' => $fine,
                    'status' => $status,
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            $loadWarnings[] = 'Your current issued books could not be loaded.';
            error_log('Member portal issued books query failed: ' . $e->getMessage());
        }

        try {
            $stmt = $conn->prepare("SELECT r.Request_id, r.Book_id, b.Title, r.Request_date, r.Status FROM issue_request r INNER JOIN book b ON b.Book_id = r.Book_id WHERE r.Member_id = ? ORDER BY r.Request_date DESC, r.Request_id DESC LIMIT 5");
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $memberRequests[] = [
                    'requestId' => (int)$row['Request_id'],
                    'memberId' => (string)$memberId,
                    'bookId' => (int)$row['Book_id'],
                    'bookTitle' => (string)$row['Title'],
                    'bookName' => (string)$row['Title'],
                    'title' => (string)$row['Title'],
                    'requestDate' => member_portal_date($row['Request_date'], true),
                    'requestedAt' => (string)$row['Request_date'],
                    'status' => (string)$row['Status'],
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            $loadWarnings[] = 'Your requests could not be loaded.';
            error_log('Member portal requests query failed: ' . $e->getMessage());
        }
    }

    try {
        $result = $conn->query("SELECT title, message FROM announcements WHERE start_date <= NOW() AND end_date >= NOW() ORDER BY start_date ASC, announcement_id ASC");
        while ($row = $result->fetch_assoc()) {
            $activeAnnouncements[] = ['title' => (string)$row['title'], 'message' => (string)$row['message']];
        }
        $result->free();
    } catch (Throwable $e) {
        error_log('Member portal announcements query failed: ' . $e->getMessage());
    }

    try {
        $result = $conn->query('SELECT Library_name, Address, Opening_time, Closing_time, Working_days, Holidays_info, Contact_number, Email FROM library_details ORDER BY Id LIMIT 1');
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            if ($row) {
                $formatTime = static function ($value): string {
                    if ($value === null || trim((string)$value) === '') return '';
                    $timestamp = strtotime((string)$value);
                    return $timestamp === false ? (string)$value : date('h:i A', $timestamp);
                };
                $libraryDetails = [
                    'name' => (string)$row['Library_name'],
                    'address' => (string)$row['Address'],
                    'openingTime' => $formatTime($row['Opening_time']),
                    'closingTime' => $formatTime($row['Closing_time']),
                    'workingDays' => (string)$row['Working_days'],
                    'holidaysInfo' => (string)$row['Holidays_info'],
                    'contactNumber' => (string)$row['Contact_number'],
                    'email' => (string)$row['Email'],
                ];
            }
            $result->free();
        }
    } catch (Throwable $e) {
        error_log('Member portal library details query failed: ' . $e->getMessage());
    }
}

/* Same-page POST endpoint used by the member's Request Issue button. */
$portalAction = isset($_GET['action']) ? (string)$_GET['action'] : (isset($_POST['action']) ? (string)$_POST['action'] : '');
if ($portalAction === 'active_announcements') {
    if (!($conn instanceof mysqli)) member_portal_json_response(['success' => false, 'message' => 'Database connection is unavailable.'], 500);
    try {
        $result = $conn->query("SELECT title, message FROM announcements WHERE start_date <= NOW() AND end_date >= NOW() ORDER BY start_date ASC, announcement_id ASC");
        $items = [];
        while ($row = $result->fetch_assoc()) $items[] = ['title' => (string)$row['title'], 'message' => (string)$row['message']];
        $result->free();
        member_portal_json_response(['success' => true, 'announcements' => $items]);
    } catch (Throwable $e) {
        error_log('Member portal active announcement refresh failed: ' . $e->getMessage());
        member_portal_json_response(['success' => false, 'message' => 'Announcements could not be refreshed.'], 500);
    }
}
if ($portalAction === 'request_issue') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') member_portal_json_response(['success' => false, 'message' => 'Use the Request Issue button to send a request.'], 405);
    if (!($conn instanceof mysqli)) member_portal_json_response(['success' => false, 'message' => $dbLoadError ?: 'Database connection is unavailable.'], 500);
    if (!$member || $memberId <= 0) member_portal_json_response(['success' => false, 'message' => 'No member was identified. Open this page with a valid user_id in the URL.'], 401);
    // Require a same-host Origin header when the browser supplies one.
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
    if ($origin !== '') {
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $requestHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($originHost !== '' && $requestHost !== '' && $originHost !== $requestHost) {
            member_portal_json_response(['success' => false, 'message' => 'Cross-origin request rejected.'], 403);
        }
    }
    $bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$bookId) member_portal_json_response(['success' => false, 'message' => 'Please choose a valid book.'], 422);

    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare('SELECT Book_id, Title, Quantity FROM book WHERE Book_id = ? FOR UPDATE');
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $bookRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$bookRow) {
            $conn->rollback();
            member_portal_json_response(['success' => false, 'message' => 'That book was not found.'], 404);
        }

        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM issue_return WHERE Book_id = ? AND Return_date IS NULL');
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $activeCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        $availableCopies = (int)$bookRow['Quantity'] - $activeCount;
        if ($availableCopies <= 0) {
            $conn->rollback();
            member_portal_json_response(['success' => false, 'message' => 'This book is currently unavailable.'], 409);
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM issue_return WHERE Member_id = ? AND Book_id = ? AND Return_date IS NULL");
        $stmt->bind_param('ii', $memberId, $bookId);
        $stmt->execute();
        $alreadyIssued = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($alreadyIssued > 0) {
            $conn->rollback();
            member_portal_json_response(['success' => false, 'message' => 'You already have this book issued.'], 409);
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM issue_request WHERE Member_id = ? AND Book_id = ? AND Status IN ('Pending','Approved')");
        $stmt->bind_param('ii', $memberId, $bookId);
        $stmt->execute();
        $duplicate = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($duplicate > 0) {
            $conn->rollback();
            member_portal_json_response(['success' => false, 'message' => 'You already have an active request for this book.'], 409);
        }

        // Request_limit may not exist in older library_details schemas.
        // Keep the default limit instead of failing the entire request when the column is absent.
        $requestLimit = 3;
        try {
            $limitResult = $conn->query('SELECT Request_limit FROM library_details ORDER BY Id LIMIT 1');
            if ($limitResult instanceof mysqli_result) {
                $limitRow = $limitResult->fetch_assoc();
                if ($limitRow && isset($limitRow['Request_limit'])) {
                    $requestLimit = max(0, (int)$limitRow['Request_limit']);
                }
                $limitResult->free();
            }
        } catch (Throwable $limitError) {
            error_log('Library Request_limit could not be loaded; using default limit of 3: ' . $limitError->getMessage());
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM issue_request WHERE Member_id = ? AND Status IN ('Pending','Approved')");
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $activeRequestCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($activeRequestCount >= $requestLimit) {
            $conn->rollback();
            member_portal_json_response(['success' => false, 'message' => 'You have reached the library request limit (' . $requestLimit . '). Wait for an existing request to be processed before requesting another book.'], 409);
        }

        $stmt = $conn->prepare("INSERT INTO issue_request (Member_id, Book_id, Request_date, Status) VALUES (?, ?, NOW(), 'Pending')");
        $stmt->bind_param('ii', $memberId, $bookId);
        $stmt->execute();
        $requestId = (int)$conn->insert_id;
        $stmt->close();
        $conn->commit();
        member_portal_json_response(['success' => true, 'message' => 'Issue request submitted successfully.', 'requestId' => $requestId]);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Member portal request submission failed: ' . $e->getMessage());
        member_portal_json_response(['success' => false, 'message' => 'The request could not be saved. Please check the database connection and try again.'], 500);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Library - Member</title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            font-family: Arial, sans-serif;

            background-color: #f5f5f5;

            color: #333;
        }


        /* =================================================
           HEADER
        ================================================= */

        .header {

            height: 65px;

            background-color: #43515a;

            color: white;

            display: flex;

            align-items: center;

            padding: 0 25px;

            position: relative;

            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }


        .library-title {

            font-size: 22px;

            font-weight: bold;

            white-space: nowrap;
        }


        /* =================================================
           ANNOUNCEMENT
        ================================================= */

        .header-announcement {

            position: absolute;

            left: 50%;

            transform: translateX(-50%);

            width: 60%;

            display: flex;

            align-items: center;

            justify-content: center;

            overflow: hidden;

            white-space: nowrap;
        }


        .announcement-icon {

            font-size: 18px;

            margin-right: 8px;

            flex-shrink: 0;
        }


        .announcement-scroll {

            overflow: hidden;

            width: 100%;
        }


        .announcement-track {

            display: inline-flex;

            white-space: nowrap;

            animation: announcementMove 18s linear infinite;
        }


        .announcement-item {

            margin-right: 80px;

            font-size: 17px;

            color: white;
        }


        @keyframes announcementMove {

            0% {
                transform: translateX(100%);
            }

            100% {
                transform: translateX(-100%);
            }

        }


        /* =================================================
           HEADER RIGHT
        ================================================= */

        .header-right {

            margin-left: auto;

            display: flex;

            align-items: center;

            gap: 18px;
        }


        .profile-icon {

            width: 36px;

            height: 36px;

            border-radius: 50%;

            background-color: white;

            color: #43515a;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 20px;

            cursor: pointer;

            transition: 0.2s;
        }


        .profile-icon:hover {

            transform: scale(1.05);

            background-color: #eeeeee;
        }


        .logout {

            color: white;

            text-decoration: none;

            font-size: 14px;

            padding: 8px 12px;

            border-radius: 4px;

            transition: 0.2s;
        }


        .logout:hover {

            background-color: rgba(255,255,255,0.12);
        }


        /* =================================================
           MAIN
        ================================================= */

        main {

            width: 90%;

            max-width: 1300px;

            margin: 25px auto 40px auto;
        }


        /* =================================================
           WELCOME CARD
        ================================================= */

        .welcome-card {

            background-color: white;

            border: 1px solid #ddd;

            border-radius: 6px;

            padding: 22px;

            margin-bottom: 20px;

            text-align: center;
        }


        .welcome-card h2 {

            margin: 0;

            font-size: 24px;

            color: #43515a;
        }


        /* =================================================
           COMMON CARD
        ================================================= */

        .card {

            background-color: white;

            border: 1px solid #ddd;

            border-radius: 6px;

            padding: 20px;

            margin-bottom: 20px;

            overflow-x: auto;
        }


        .card h3 {

            margin: 0 0 18px 0;

            color: #43515a;

            font-size: 19px;
        }


        /* =================================================
           TABLE
        ================================================= */

        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 920px;
        }


        th {

            background-color: #43515a;

            color: white;

            padding: 12px;

            text-align: left;

            font-size: 14px;
        }


        td {

            padding: 12px;

            border-bottom: 1px solid #ddd;

            font-size: 14px;

            color: #444;
        }


        tr:last-child td {

            border-bottom: none;
        }


        .no-records {

            text-align: center;

            color: #777;

            padding: 25px;

            font-size: 14px;
        }

        /* Available-book request action */
        .request-btn {
            border: none;
            border-radius: 4px;
            background: #43515a;
            color: #fff;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .request-btn:hover {
            background: #344149;
        }

        .request-btn:disabled {
            background: #e6eaec;
            color: #59666d;
            cursor: not-allowed;
        }

        .unavailable-label {
            color: #777;
            font-size: 13px;
            white-space: nowrap;
        }

        .book-id-cell {
            color: #43515a;
            font-weight: 700;
            white-space: nowrap;
        }

        .issued-status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            background: #edf0f2;
            color: #43515a;
        }

        .issued-status.status-pending {
            background: #fff4d6;
            color: #7a5b14;
        }

        .issued-status.status-approved,
        .issued-status.status-approve {
            background: #e5f0ff;
            color: #285a92;
        }

        .issued-status.status-issued,
        .issued-status.status-returned {
            background: #e3f4e8;
            color: #21663a;
        }

        .issued-status.status-rejected,
        .issued-status.status-overdue {
            background: #fde8e7;
            color: #9b2925;
        }

        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding-top: 16px;
        }

        .pagination-summary {
            color: #68757c;
            font-size: 13px;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border: 1px solid #d3dadd;
            border-radius: 4px;
            background: #fff;
            color: #43515a;
            cursor: pointer;
            font-size: 13px;
        }

        .page-btn:hover:not(:disabled) {
            background: #eef1f2;
        }

        .page-btn.active {
            background: #43515a;
            border-color: #43515a;
            color: #fff;
            font-weight: 700;
        }

        .page-btn:disabled {
            color: #a4adb2;
            background: #f5f6f6;
            cursor: not-allowed;
        }

        .pagination-page-indicator {
            color: #43515a;
            font-size: 13px;
            white-space: nowrap;
            padding: 0 3px;
        }

        .pagination-page-indicator strong {
            color: #293941;
        }

        .pagination-jump-label {
            color: #68757c;
            font-size: 13px;
            white-space: nowrap;
        }

        .page-jump-input {
            width: 62px;
            height: 34px;
            padding: 5px 7px;
            border: 1px solid #d3dadd;
            border-radius: 4px;
            color: #43515a;
            font-size: 13px;
        }

        .page-jump-input:focus {
            outline: none;
            border-color: #43515a;
            box-shadow: 0 0 0 2px rgba(67, 81, 90, 0.12);
        }

        .page-go-btn {
            font-weight: 600;
        }

        @media (max-width: 650px) {
            .pagination-bar {
                align-items: flex-start;
                flex-direction: column;
            }

            .pagination-controls {
                width: 100%;
                gap: 5px;
            }

            .pagination-page-indicator {
                flex: 1 0 auto;
                text-align: center;
            }

            .page-jump-input {
                width: 54px;
            }
        }


        /* =================================================
           SEARCH + FILTER
        ================================================= */

        .books-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 12px;

            margin-bottom: 18px;
        }


        .books-header h3 {

            margin: 0;

            white-space: nowrap;
        }


        .search-filter {

            display: flex;

            align-items: center;

            gap: 8px;

            margin-left: auto;
        }


        .search-box {

            width: 230px;

            padding: 9px 11px;

            border: 1px solid #ccc;

            border-radius: 4px;

            outline: none;

            font-size: 14px;
        }


        .search-box:focus {

            border-color: #43515a;
        }


        .search-btn,
        .filter-btn {

            border: none;

            padding: 9px 15px;

            border-radius: 4px;

            cursor: pointer;

            font-size: 14px;
        }


        .search-btn {

            background-color: #43515a;

            color: white;
        }


        .filter-btn {

            background-color: #666;

            color: white;
        }


        .search-btn:hover {

            background-color: #344149;
        }


        .filter-btn:hover {

            background-color: #555;
        }


        /* =================================================
           FILTER MODAL
        ================================================= */

        .filter-overlay {

            display: none;

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background: rgba(0,0,0,0.45);

            z-index: 1000;

            justify-content: center;

            align-items: center;
        }


        .filter-popup {

            width: 430px;

            max-width: 92%;

            background-color: white;

            border-radius: 7px;

            padding: 22px;

            box-shadow: 0 5px 25px rgba(0,0,0,0.25);
        }


        .filter-popup h3 {

            margin: 0 0 20px 0;

            color: #43515a;
        }


        .filter-group {

            margin-bottom: 16px;
        }


        .filter-group label {

            display: block;

            font-size: 14px;

            margin-bottom: 7px;

            font-weight: bold;
        }


        .filter-group select {

            width: 100%;

            padding: 9px;

            border: 1px solid #ccc;

            border-radius: 4px;

            background: white;

            font-size: 14px;
        }


        .filter-actions {

            display: flex;

            gap: 10px;

            margin-top: 20px;
        }


        .apply-filter,
        .clear-filter {

            flex: 1;

            padding: 10px;

            border: none;

            border-radius: 4px;

            cursor: pointer;

            font-size: 14px;
        }


        .apply-filter {

            background-color: #43515a;

            color: white;
        }


        .clear-filter {

            background-color: #ddd;

            color: #333;
        }


        /* =================================================
           PROFILE MODAL
        ================================================= */

        .profile-overlay {

            display: none;

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background: rgba(0,0,0,0.45);

            z-index: 1100;

            justify-content: center;

            align-items: center;
        }


        .profile-popup {

            width: 450px;

            max-width: 92%;

            background-color: white;

            border-radius: 7px;

            box-shadow: 0 5px 25px rgba(0,0,0,0.25);

            overflow: hidden;
        }


        .profile-popup-header {

            background-color: #43515a;

            color: white;

            padding: 15px 18px;

            display: flex;

            align-items: center;

            justify-content: space-between;
        }


        .profile-popup-header h3 {

            margin: 0;

            font-size: 18px;
        }


        .close-profile {

            background: transparent;

            border: none;

            color: white;

            font-size: 25px;

            cursor: pointer;

            line-height: 1;
        }


        .profile-content {

            padding: 25px;
        }


        .profile-avatar {

            width: 75px;

            height: 75px;

            border-radius: 50%;

            background-color: #43515a;

            color: white;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 35px;

            margin: 0 auto 25px auto;
        }


        .profile-details {

            width: 100%;
        }


        .profile-row {

            display: flex;

            border-bottom: 1px solid #eee;

            padding: 13px 0;
        }


        .profile-row:last-child {

            border-bottom: none;
        }


        .profile-label {

            width: 40%;

            font-weight: bold;

            color: #555;

            font-size: 14px;
        }


        .profile-value {

            width: 60%;

            color: #777;

            font-size: 14px;
        }


        /* =================================================
           MEMBER NAVIGATION
        ================================================= */

        /* Integrated sticky member navigation */
        .member-navigation {
            position: sticky;
            top: 0;
            z-index: 900;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            width: 100%;
            min-height: 54px;
            padding: 7px 20px;
            overflow-x: auto;
            background: #fff;
            border-bottom: 1px solid #d9dfe2;
            box-shadow: 0 2px 5px rgba(32, 44, 51, 0.07);
            scrollbar-width: thin;
        }

        .member-navigation a {
            flex: 0 0 auto;
            color: #43515a;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 16px 9px;
            border: 0;
            border-bottom: 2px solid transparent;
            border-radius: 4px 4px 0 0;
            background: transparent;
            transition: background-color 0.18s ease, color 0.18s ease, border-color 0.18s ease;
            white-space: nowrap;
        }

        .member-navigation a:hover {
            background: #f1f4f5;
            color: #293941;
        }

        .member-navigation a.active {
            color: #293941;
            background: #f4f6f7;
            border-bottom-color: #43515a;
        }

        /* Keep target headings clear of the sticky navigation. */
        #home-section,
        #issued-books,
        #my-requests,
        #all-books,
        #library-details {
            scroll-margin-top: 66px;
        }

        html {
            scroll-behavior: smooth;
        }

        /* =================================================
           LIBRARY DETAILS
        ================================================= */

        .library-details {
            width: 92%;
            max-width: 1200px;
            margin: 20px auto 30px auto;
        }

        .library-details h3 {
            margin-top: 0;
            margin-bottom: 18px;
        }

        .library-detail-row {
            display: flex;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
        }

        .library-detail-row:last-child {
            border-bottom: none;
        }

        .library-detail-label {
            width: 30%;
            font-weight: bold;
            color: #555;
            font-size: 14px;
        }

        .library-detail-value {
            width: 70%;
            color: #777;
            font-size: 14px;
        }

        .footer {
            text-align: center;
            color: #777;
            font-size: 12px;
            padding: 0 20px 25px 20px;
        }


        /* =================================================
           RESPONSIVE
        ================================================= */

        @media (max-width: 900px) {

            .header-announcement {

                width: 50%;
            }

            .search-filter {

                flex-wrap: wrap;
            }

            .search-box {

                width: 180px;
            }

        }


        @media (max-width: 650px) {

            .member-navigation {
                justify-content: flex-start;
                padding: 7px 10px;
                gap: 2px;
            }

            .member-navigation a {
                padding: 9px 12px 8px;
                font-size: 13px;
            }

            .header {

                padding: 0 15px;
            }


            .header-announcement {

                display: none;
            }


            .library-title {

                font-size: 19px;
            }


            .header-right {

                gap: 8px;
            }


            main {

                width: 94%;

                margin-top: 18px;
            }


            .books-header {

                flex-direction: column;

                align-items: stretch;
            }


            .books-header h3 {

                margin-bottom: 5px;
            }


            .search-filter {

                width: 100%;

                margin-left: 0;
            }


            .search-box {

                flex: 1;

                width: auto;
            }


            .search-btn,
            .filter-btn {

                padding: 9px 11px;
            }


            .profile-row {

                display: block;
            }


            .profile-label,
            .profile-value {

                width: 100%;

                display: block;
            }


            .profile-value {

                margin-top: 5px;
            }

            .library-details,
            .bottom-navigation {
                width: 94%;
            }

            .library-detail-row {
                display: block;
            }

            .library-detail-label,
            .library-detail-value {
                width: 100%;
                display: block;
            }

            .library-detail-value {
                margin-top: 5px;
            }

        }

    

        /* PHP Desktop 130.1 workaround: use an in-page dialog instead of native JS confirm/alert. */
        .app-dialog-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 5000;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(0, 0, 0, 0.48);
        }
        .app-dialog {
            width: 430px;
            max-width: 100%;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #d9dfe2;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.28);
            padding: 22px;
        }
        .app-dialog h3 {
            margin: 0 0 12px;
            color: #43515a;
            font-size: 19px;
        }
        .app-dialog-message {
            margin: 0;
            color: #37434a;
            font-size: 14px;
            line-height: 1.55;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .app-dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
        }
        .app-dialog-actions button {
            min-width: 88px;
            border: 0;
            border-radius: 4px;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .app-dialog-cancel {
            background: #e8ecee;
            color: #334149;
        }
        .app-dialog-ok {
            background: #43515a;
            color: #fff;
        }
        .app-dialog-actions button:focus-visible {
            outline: 2px solid #2c719b;
            outline-offset: 2px;
        }

    </style>

</head>


<body id="top">


<!-- =================================================
     HEADER
================================================= -->

<header class="header">


    <div class="library-title">

        📚 Library

    </div>


    <!-- Announcement -->

    <div class="header-announcement">

        <div class="announcement-icon">

            📢

        </div>


        <div class="announcement-scroll">

            <div
                class="announcement-track"
                id="announcementTrack">

                <!-- Active announcements will appear here -->

            </div>

        </div>

    </div>


    <!-- Header Right -->

    <div class="header-right">


        <div
            class="profile-icon"
            onclick="openProfile()"
            title="Member Details">

            👤

        </div>


        <a
            href="?logout=1"
            class="logout">

            Logout

        </a>

    </div>

</header>


<!-- =================================================
     MEMBER NAVIGATION
================================================= -->

<nav class="member-navigation" aria-label="Member Navigation">
    <a href="#home-section" class="active">Home</a>
    <a href="#issued-books">My Issued Books</a>
    <a href="#my-requests">My Requests</a>
    <a href="#all-books">All Books</a>
    <a href="#library-details">About Library</a>
</nav>


<!-- =================================================
     MAIN CONTENT
================================================= -->

<main>

<?php if ($memberAuthMessage !== '' || $loadWarnings): ?>
    <div role="status" style="background:#fff8e8;border:1px solid #e6d5a8;color:#5f4b17;padding:12px 16px;border-radius:5px;margin-bottom:18px;font-size:14px;line-height:1.5;">
        <?php if ($memberAuthMessage !== ''): ?><?= member_portal_h($memberAuthMessage) ?><?php endif; ?>
        <?php if ($loadWarnings): ?><?= $memberAuthMessage !== '' ? '<br>' : '' ?><?= member_portal_h(implode(' ', $loadWarnings)) ?><?php endif; ?>
    </div>
<?php endif; ?>



    <!-- =================================================
         WELCOME
    ================================================= -->

    <div class="welcome-card" id="home-section">

        <h2>
            Welcome<?= $member ? ', ' . member_portal_h($memberName) : '' ?>
        </h2>

    </div>



    <!-- =================================================
         MY ISSUED BOOKS
    ================================================= -->

    <div class="card" id="issued-books">

        <h3>
            My Issued Books
        </h3>


        <table>

            <thead>

                <tr>
                    <th>Book ID</th>
                    <th>Book Name</th>
                    <th>Author</th>
                    <th>ISBN</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th>Fine</th>
                    <th>Status</th>
                </tr>

            </thead>


            <tbody id="issuedBooksTable">

                <!-- Real issued-book records will be loaded here -->

            </tbody>

        </table>

    </div>



    <!-- My Requests -->
    <div class="card" id="my-requests">
        <h3>My Requests</h3>
        <table>
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Book ID</th>
                    <th>Book Title</th>
                    <th>Request Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="myRequestsTable">
                <tr>
                    <td colspan="5" class="no-records">No book requests found.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- =================================================
         ALL BOOKS
    ================================================= -->

    <div class="card" id="all-books">


        <div class="books-header">


            <h3>
                All Books in Library
            </h3>


            <div class="search-filter">


                <input
                    type="text"
                    id="searchInput"
                    class="search-box"
                    placeholder="Search by Book ID, title, author or ISBN"
                    aria-label="Search books by ID, title, author or ISBN">


                <button
                    class="search-btn"
                    onclick="searchBooks()">

                    Search

                </button>


                <button
                    class="filter-btn"
                    onclick="openFilter()">

                    Filter

                </button>

            </div>

        </div>



        <table>

            <thead>

                <tr>
                    <th>Book ID</th>
                    <th>Book Title</th>
                    <th>Author</th>
                    <th>ISBN</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

            </thead>


            <tbody id="booksTable">

                <!-- Book records are rendered here -->

            </tbody>

        </table>

        <div class="pagination-bar" id="booksPagination" aria-label="Books pagination"></div>

    </div>


    <!-- =================================================
         LIBRARY DETAILS
    ================================================= -->

    <div class="card library-details" id="library-details">

        <h3>About Library</h3>

        <div class="library-detail-row">
            <div class="library-detail-label">Library Name</div>
            <div class="library-detail-value" id="libraryName"></div>
        </div>

        <div class="library-detail-row">
            <div class="library-detail-label">Address</div>
            <div class="library-detail-value" id="libraryAddress"></div>
        </div>

        <div class="library-detail-row">
            <div class="library-detail-label">Opening Time</div>
            <div class="library-detail-value" id="openingTime"></div>
        </div>

        <div class="library-detail-row">
            <div class="library-detail-label">Closing Time</div>
            <div class="library-detail-value" id="closingTime"></div>
        </div>

        <div class="library-detail-row">
            <div class="library-detail-label">Working Days</div>
            <div class="library-detail-value" id="workingDays"></div>
        </div>

        <div class="library-detail-row">
            <div class="library-detail-label">Sunday</div>
            <div class="library-detail-value" id="sundayInfo"></div>
        </div>

        <div class="library-detail-row">
            <div class="library-detail-label">Holidays</div>
            <div class="library-detail-value" id="holidaysInfo"></div>
        </div>

        <div class="library-detail-row">
            <div class="library-detail-label">Contact Number</div>
            <div class="library-detail-value" id="contactNumber"></div>
        </div>

    </div>


</main>



<!-- =================================================
     FILTER POPUP
================================================= -->

<div
    class="filter-overlay"
    id="filterOverlay">


    <div class="filter-popup">


        <h3>
            Filter Books
        </h3>


        <!-- Category -->

        <div class="filter-group">

            <label>
                Category
            </label>

            <select id="categoryFilter">

                <option value="">
                    All Categories
                </option>

            </select>

        </div>


        <!-- Sort -->

        <div class="filter-group">

            <label>
                Sort By
            </label>

            <select id="sortFilter">

                <option value="">
                    Default
                </option>

                <option value="nameAsc">
                    Book Name A-Z
                </option>

                <option value="nameDesc">
                    Book Name Z-A
                </option>

                <option value="authorAsc">
                    Author A-Z
                </option>

                <option value="authorDesc">
                    Author Z-A
                </option>

            </select>

        </div>


        <!-- Status -->

        <div class="filter-group">

            <label>
                Status
            </label>

            <select id="statusFilter">

                <option value="">
                    All Status
                </option>

                <option value="Available">
                    Available
                </option>

                <option value="Issued">
                    Issued
                </option>

            </select>

        </div>


        <!-- Buttons -->

        <div class="filter-actions">


            <button
                class="apply-filter"
                onclick="applyFilter()">

                Apply Filter

            </button>


            <button
                class="clear-filter"
                onclick="clearFilter()">

                Clear

            </button>

        </div>

    </div>

</div>



<!-- =================================================
     MEMBER DETAILS POPUP
================================================= -->

<div
    class="profile-overlay"
    id="profileOverlay">


    <div class="profile-popup">


        <!-- Profile Header -->

        <div class="profile-popup-header">


            <h3>
                Member Details
            </h3>


            <button
                class="close-profile"
                onclick="closeProfile()">

                ×

            </button>

        </div>



        <!-- Profile Content -->

        <div class="profile-content">


            <div class="profile-avatar">

                👤

            </div>


            <div class="profile-details">


                <!-- Username -->

                <div class="profile-row">

                    <div class="profile-label">
                        Username
                    </div>

                    <div
                        class="profile-value"
                        id="profileUsername">

                    </div>

                </div>


                <!-- Name -->

                <div class="profile-row">

                    <div class="profile-label">
                        Name
                    </div>

                    <div
                        class="profile-value"
                        id="profileName">

                    </div>

                </div>


                <!-- Email -->

                <div class="profile-row">

                    <div class="profile-label">
                        Email
                    </div>

                    <div
                        class="profile-value"
                        id="profileEmail">

                    </div>

                </div>


                <!-- Mobile Number -->

                <div class="profile-row">

                    <div class="profile-label">
                        Mobile Number
                    </div>

                    <div
                        class="profile-value"
                        id="profileMobile">

                    </div>

                </div>


                <!-- Address -->

                <div class="profile-row">

                    <div class="profile-label">
                        Address
                    </div>

                    <div
                        class="profile-value"
                        id="profileAddress">

                    </div>

                </div>


            </div>

        </div>

    </div>

</div>



<div class="footer">
    Library Management System &nbsp;|&nbsp; Member Portal
</div>



<!-- Custom confirmation/message dialog for PHP Desktop Chromium 130.1. -->
<div class="app-dialog-overlay" id="appDialogOverlay" aria-hidden="true">
    <section class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="appDialogTitle" aria-describedby="appDialogMessage">
        <h3 id="appDialogTitle">Library Management System</h3>
        <p class="app-dialog-message" id="appDialogMessage"></p>
        <div class="app-dialog-actions">
            <button type="button" class="app-dialog-cancel" id="appDialogCancel">Cancel</button>
            <button type="button" class="app-dialog-ok" id="appDialogOk">OK</button>
        </div>
    </section>
</div>

<script>


/* =================================================
   DATA
================================================= */

/* Demo records for the member-page UI. Replace with database data later. */
const books = <?= member_portal_json($books) ?>;
let issuedBooks = <?= member_portal_json($issuedBooks) ?>;
const memberRequests = <?= member_portal_json($memberRequests) ?>;
const currentMember = <?= member_portal_json($currentMemberData) ?>;

/* In-page dialogs: native JavaScript alert()/confirm() are broken in PHP Desktop 130.1. */
let appDialogResolver = null;
let appDialogConfirmMode = false;

function closeAppDialog(result) {
    const overlay = document.getElementById("appDialogOverlay");
    if (!overlay) return;
    overlay.style.display = "none";
    overlay.setAttribute("aria-hidden", "true");
    const resolver = appDialogResolver;
    appDialogResolver = null;
    if (resolver) resolver(Boolean(result));
}

function showAppDialog(message, confirmMode = false, title = "Library Management System") {
    const overlay = document.getElementById("appDialogOverlay");
    const titleElement = document.getElementById("appDialogTitle");
    const messageElement = document.getElementById("appDialogMessage");
    const cancelButton = document.getElementById("appDialogCancel");
    const okButton = document.getElementById("appDialogOk");
    if (!overlay || !titleElement || !messageElement || !cancelButton || !okButton) {
        // This should only happen if the dialog markup was removed from the page.
        console.error("The in-page dialog elements are missing.", message);
        return Promise.resolve(false);
    }
    if (appDialogResolver) closeAppDialog(false);
    appDialogConfirmMode = confirmMode;
    titleElement.textContent = title;
    messageElement.textContent = String(message ?? "");
    cancelButton.style.display = confirmMode ? "inline-block" : "none";
    okButton.textContent = confirmMode ? "Yes, continue" : "OK";
    overlay.style.display = "flex";
    overlay.setAttribute("aria-hidden", "false");
    return new Promise(resolve => {
        appDialogResolver = resolve;
        (confirmMode ? cancelButton : okButton).blur();
        okButton.focus();
    });
}

function appAlert(message) {
    return showAppDialog(message, false, "Library Management System");
}

function appConfirm(message) {
    return showAppDialog(message, true, "Confirm Action");
}

(function initialiseAppDialog() {
    const overlay = document.getElementById("appDialogOverlay");
    const cancelButton = document.getElementById("appDialogCancel");
    const okButton = document.getElementById("appDialogOk");
    if (!overlay || !cancelButton || !okButton) return;
    cancelButton.addEventListener("click", () => closeAppDialog(false));
    okButton.addEventListener("click", () => closeAppDialog(true));
    overlay.addEventListener("click", event => {
        if (event.target === overlay) closeAppDialog(false);
    });
    document.addEventListener("keydown", event => {
        if (overlay.style.display !== "flex") return;
        if (event.key === "Escape") {
            event.preventDefault();
            closeAppDialog(false);
        } else if (event.key === "Enter") {
            event.preventDefault();
            closeAppDialog(true);
        }
    });
})();

function getMemberIssueRequests() {
    return Array.isArray(memberRequests) ? memberRequests : [];
}

const BOOKS_PER_PAGE = 10;
let currentBooksPage = 1;
let currentBookList = [...books];



/* =================================================
   LOAD BOOKS + PAGINATION
================================================= */

function loadBooks(bookList = books) {
    currentBookList = [...bookList];
    currentBooksPage = 1;
    renderBooksPage();
}

function renderBooksPage() {
    const table = document.getElementById("booksTable");
    table.innerHTML = "";

    const totalBooks = currentBookList.length;
    const totalPages = Math.ceil(totalBooks / BOOKS_PER_PAGE);

    if (totalBooks === 0) {
        table.innerHTML = `
            <tr>
                <td colspan="7" class="no-records">No books available.</td>
            </tr>
        `;
        renderBooksPagination();
        return;
    }

    if (currentBooksPage > totalPages) {
        currentBooksPage = totalPages;
    }

    const firstIndex = (currentBooksPage - 1) * BOOKS_PER_PAGE;
    const visibleBooks = currentBookList.slice(firstIndex, firstIndex + BOOKS_PER_PAGE);

    visibleBooks.forEach(book => {
        const row = document.createElement("tr");
        const bookId = String(book.bookId ?? "");
        const isAvailable = String(book.status || "").toLowerCase() === "available";
        const hasActiveRequest = isActiveIssueRequest(bookId);

        let actionCell;
        if (!isAvailable) {
            actionCell = '<span class="unavailable-label">Unavailable</span>';
        } else if (hasActiveRequest) {
            actionCell = '<button type="button" class="request-btn" disabled>Requested</button>';
        } else {
            actionCell = `<button type="button" class="request-btn" onclick="requestBookIssue(${Number(book.bookId)}, this)">Request Issue</button>`;
        }

        row.innerHTML = `
            <td class="book-id-cell">${escapeHTML(bookId)}</td>
            <td>${escapeHTML(book.name || "")}</td>
            <td>${escapeHTML(book.author || "")}</td>
            <td>${escapeHTML(book.isbn || "")}</td>
            <td>${escapeHTML(book.category || "")}</td>
            <td>${escapeHTML(book.status || "")}</td>
            <td>${actionCell}</td>
        `;

        table.appendChild(row);
    });

    renderBooksPagination();
}

function renderBooksPagination() {
    const pagination = document.getElementById("booksPagination");
    const totalBooks = currentBookList.length;
    const totalPages = Math.ceil(totalBooks / BOOKS_PER_PAGE);

    if (totalBooks === 0) {
        pagination.innerHTML = "";
        return;
    }

    const first = (currentBooksPage - 1) * BOOKS_PER_PAGE + 1;
    const last = Math.min(currentBooksPage * BOOKS_PER_PAGE, totalBooks);

    pagination.innerHTML = `
        <div class="pagination-summary">Showing ${first}-${last} of ${totalBooks} books</div>
        <div class="pagination-controls">
            <button type="button" class="page-btn"
                onclick="changeBooksPage(${currentBooksPage - 1})"
                ${currentBooksPage === 1 ? "disabled" : ""}>Previous</button>

            <span class="pagination-page-indicator">
                Page <strong>${currentBooksPage}</strong> of <strong>${totalPages}</strong>
            </span>

            <label class="pagination-jump-label" for="booksPageJump">Go to</label>
            <input type="number" id="booksPageJump" class="page-jump-input"
                min="1" max="${totalPages}" step="1" inputmode="numeric"
                aria-label="Go to page number" value="${currentBooksPage}">

            <button type="button" class="page-btn page-go-btn"
                onclick="goToBooksPage()">Go</button>

            <button type="button" class="page-btn"
                onclick="changeBooksPage(${currentBooksPage + 1})"
                ${currentBooksPage === totalPages ? "disabled" : ""}>Next</button>
        </div>
    `;

    const jumpInput = document.getElementById("booksPageJump");
    jumpInput.addEventListener("keydown", function(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            goToBooksPage();
        }
    });
}

function goToBooksPage() {
    const input = document.getElementById("booksPageJump");
    if (!input) return;

    const requestedPage = Number(input.value);
    const totalPages = Math.ceil(currentBookList.length / BOOKS_PER_PAGE);

    if (!Number.isInteger(requestedPage) || requestedPage < 1 || requestedPage > totalPages) {
        input.setCustomValidity(`Enter a page number from 1 to ${totalPages}.`);
        input.reportValidity();
        input.setCustomValidity("");
        input.focus();
        return;
    }

    currentBooksPage = requestedPage;
    renderBooksPage();
}

function changeBooksPage(page) {
    const totalPages = Math.ceil(currentBookList.length / BOOKS_PER_PAGE);
    if (page < 1 || page > totalPages) return;
    currentBooksPage = page;
    renderBooksPage();
}

function getIssueRequests() {
    return getMemberIssueRequests();
}

function isActiveIssueRequest(bookId) {
    const activeStatuses = ["pending", "approve", "approved"];
    return getIssueRequests().some(request => {
        const requestMemberId = String(request.memberId ?? request.member_id ?? "");
        const requestBookId = String(request.bookId ?? request.book_id ?? "");
        const status = String(request.status || "Pending").toLowerCase();
        return requestMemberId === currentMember.id &&
            requestBookId === String(bookId) &&
            activeStatuses.includes(status);
    });
}

function memberPortalActionUrl(action) {
    // Keep the desktop app's current origin and any existing query parameters.
    // Do not hard-code localhost or navigate the top-level window to an endpoint.
    const url = new URL(window.location.href);
    url.hash = "";
    url.searchParams.set("action", action);
    return url.toString();
}

async function requestBookIssue(bookId, button = null) {
    const book = books.find(item => String(item.bookId) === String(bookId));

    if (!currentMember || !currentMember.id) {
        appAlert("Please log in with your Member account before requesting a book.");
        return;
    }
    if (!book || String(book.status).toLowerCase() !== "available" || Number(book.availableCopies) <= 0) {
        appAlert("This book is currently unavailable.");
        return;
    }

    const duplicate = getIssueRequests().some(request => {
        const requestMemberId = String(request.memberId ?? request.member_id ?? "");
        const requestBookId = String(request.bookId ?? request.book_id ?? "");
        const status = String(request.status || "Pending").toLowerCase();
        return requestMemberId === String(currentMember.id) &&
            requestBookId === String(book.bookId) &&
            ["pending", "approve", "approved"].includes(status);
    });
    if (duplicate) {
        appAlert("You already have an active request for this book.");
        return;
    }

    // Keep the confirmation in the current PHP Desktop window.
    if (!(await appConfirm(`Send an issue request for "${book.name}"?`))) return;

    const originalButtonText = button ? button.textContent : "Request Issue";
    if (button) {
        button.disabled = true;
        button.textContent = "Submitting...";
    }

    try {
        const body = new URLSearchParams();
        body.set("action", "request_issue");
        body.set("book_id", String(book.bookId));

        // POST through the same URL/origin as the page; never open a separate browser window.
        const response = await fetch(memberPortalActionUrl("request_issue"), {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
                "Accept": "application/json"
            },
            cache: "no-store",
            redirect: "error",
            body: body.toString()
        });

        const contentType = response.headers.get("content-type") || "";
        if (!contentType.toLowerCase().includes("application/json")) {
            throw new Error("The server did not return JSON. Check the PHP Desktop URL and PHP error log.");
        }

        const result = await response.json();
        if (!response.ok || !result.success) {
            appAlert(result.message || "The issue request could not be submitted.");
            if (button) {
                button.disabled = false;
                button.textContent = originalButtonText;
            }
            return;
        }

        // Update the current page without reloading/navigating away from the desktop app.
        const now = new Date();
        const requestDate = now.toLocaleString("en-IN", {
            day: "2-digit", month: "2-digit", year: "numeric",
            hour: "2-digit", minute: "2-digit", hour12: false
        });
        memberRequests.unshift({
            requestId: result.requestId ?? "-",
            memberId: String(currentMember.id),
            bookId: Number(book.bookId),
            bookTitle: String(book.name || ""),
            bookName: String(book.name || ""),
            title: String(book.name || ""),
            requestDate,
            requestedAt: now.toISOString(),
            status: "Pending"
        });

        loadMyRequests();
        renderBooksPage();
        appAlert(result.message || "Issue request submitted successfully.");
    } catch (error) {
        console.error("Issue request failed:", error);
        appAlert("Could not submit the request inside the desktop app. Check that Apache and MySQL are running, then inspect the PHP/Chromium console for the request error.");
        if (button) {
            button.disabled = false;
            button.textContent = originalButtonText;
        }
    }
}


/* =================================================
   LOAD ISSUED BOOKS
================================================= */

function loadIssuedBooks() {
    const table = document.getElementById("issuedBooksTable");
    table.innerHTML = "";

    if (issuedBooks.length === 0) {
        table.innerHTML = `
            <tr>
                <td colspan="8" class="no-records">No issued books.</td>
            </tr>
        `;
        return;
    }

    issuedBooks.forEach(book => {
        const row = document.createElement("tr");
        const status = String(book.status || "");

        row.innerHTML = `
            <td>${escapeHTML(book.bookId ?? book.book_id ?? book.id ?? "-")}</td>
            <td>${escapeHTML(book.name || book.title || "")}</td>
            <td>${escapeHTML(book.author || "-")}</td>
            <td>${escapeHTML(book.isbn || "-")}</td>
            <td>${escapeHTML(book.issueDate || book.issue_date || "-")}</td>
            <td>${escapeHTML(book.dueDate || book.due_date || "-")}</td>
            <td>${escapeHTML(book.fine ?? "-")}</td>
            <td><span class="issued-status status-${status.toLowerCase().replace(/[^a-z0-9]+/g, "-")}">${escapeHTML(status)}</span></td>
        `;

        table.appendChild(row);
    });
}

function loadMyRequests() {
    const table = document.getElementById("myRequestsTable");
    if (!table) return;

    table.innerHTML = "";
    const requests = getMemberIssueRequests();

    if (requests.length === 0) {
        table.innerHTML = `
            <tr>
                <td colspan="5" class="no-records">No book requests found.</td>
            </tr>
        `;
        return;
    }

    requests
        .slice()
        .sort((a, b) => {
            const dateA = new Date(a.requestedAt || a.createdAt || a.requestDate || 0).getTime() || 0;
            const dateB = new Date(b.requestedAt || b.createdAt || b.requestDate || 0).getTime() || 0;
            return dateB - dateA;
        })
        .forEach(request => {
            const bookId = String(request.bookId ?? request.book_id ?? "-");
            const title = String(
                request.bookTitle ?? request.bookName ?? request.title ?? request.book_name ?? ""
            );
            const requestId = String(request.requestId ?? request.id ?? "-");
            const requestDate = String(
                request.requestDate ?? (request.requestedAt ? new Date(request.requestedAt).toLocaleDateString("en-IN") : "-")
            );
            const status = String(request.status || "Pending");
            const row = document.createElement("tr");

            row.innerHTML = `
                <td>${escapeHTML(requestId)}</td>
                <td>${escapeHTML(bookId)}</td>
                <td>${escapeHTML(title)}</td>
                <td>${escapeHTML(requestDate || "-")}</td>
                <td><span class="issued-status status-${status.toLowerCase().replace(/[^a-z0-9]+/g, "-")}">${escapeHTML(status)}</span></td>
            `;

            table.appendChild(row);
        });
}

/* =================================================
   SEARCH BOOKS
================================================= */

function searchBooks() {

    const searchValue =
        document
        .getElementById("searchInput")
        .value
        .trim()
        .toLowerCase();


    if (!searchValue) {

        loadBooks();

        return;
    }


    const filteredBooks =
        books.filter(book =>

            String(book.bookId ?? "")
                .toLowerCase()
                .includes(searchValue)

            ||

            String(book.name || "")
                .toLowerCase()
                .includes(searchValue)

            ||

            String(book.author || "")
                .toLowerCase()
                .includes(searchValue)

            ||

            String(book.isbn || "")
                .toLowerCase()
                .includes(searchValue)

        );


    loadBooks(filteredBooks);

}



/* =================================================
   ENTER KEY SEARCH
================================================= */

document
    .getElementById("searchInput")
    .addEventListener("keydown", function(event) {

        if (event.key === "Enter") {

            searchBooks();

        }

    });



/* =================================================
   OPEN FILTER
================================================= */

function openFilter() {

    document
        .getElementById("filterOverlay")
        .style.display = "flex";

}



/* =================================================
   CLOSE FILTER
================================================= */

function closeFilter() {

    document
        .getElementById("filterOverlay")
        .style.display = "none";

}



/* =================================================
   LOAD CATEGORIES
================================================= */

function loadCategories() {

    const categorySelect =
        document.getElementById("categoryFilter");


    const categories =
        [...new Set(
            books
                .map(book => book.category)
                .filter(category => category)
        )];


    categorySelect.innerHTML = `

        <option value="">
            All Categories
        </option>

    `;


    categories.forEach(category => {

        const option =
            document.createElement("option");


        option.value = category;

        option.textContent = category;


        categorySelect.appendChild(option);

    });

}



/* =================================================
   APPLY FILTER
================================================= */

function applyFilter() {

    const category =
        document
        .getElementById("categoryFilter")
        .value;


    const status =
        document
        .getElementById("statusFilter")
        .value;


    const sort =
        document
        .getElementById("sortFilter")
        .value;


    let filteredBooks =
        [...books];


    /* Category */

    if (category) {

        filteredBooks =
            filteredBooks.filter(
                book => book.category === category
            );

    }


    /* Status */

    if (status) {

        filteredBooks =
            filteredBooks.filter(
                book => book.status === status
            );

    }


    /* Sorting */

    if (sort === "nameAsc") {

        filteredBooks.sort(
            (a, b) =>
                String(a.name || "")
                .localeCompare(String(b.name || ""))
        );

    }


    if (sort === "nameDesc") {

        filteredBooks.sort(
            (a, b) =>
                String(b.name || "")
                .localeCompare(String(a.name || ""))
        );

    }


    if (sort === "authorAsc") {

        filteredBooks.sort(
            (a, b) =>
                String(a.author || "")
                .localeCompare(String(b.author || ""))
        );

    }


    if (sort === "authorDesc") {

        filteredBooks.sort(
            (a, b) =>
                String(b.author || "")
                .localeCompare(String(a.author || ""))
        );

    }


    loadBooks(filteredBooks);


    /*
       Automatically close the filter popup
       after applying.
    */

    closeFilter();

}



/* =================================================
   CLEAR FILTER
================================================= */

function clearFilter() {

    document
        .getElementById("categoryFilter")
        .value = "";


    document
        .getElementById("sortFilter")
        .value = "";


    document
        .getElementById("statusFilter")
        .value = "";


    document
        .getElementById("searchInput")
        .value = "";


    loadBooks();


    closeFilter();

}



/* =================================================
   FILTER OUTSIDE CLICK
================================================= */

document
    .getElementById("filterOverlay")
    .addEventListener(
        "click",
        function(event) {

            if (event.target === this) {

                closeFilter();

            }

        }
    );



/* =================================================
   PROFILE
================================================= */

function openProfile() {
    document.getElementById("profileUsername").textContent = currentMember.username || "—";
    document.getElementById("profileName").textContent = currentMember.name || "—";
    document.getElementById("profileEmail").textContent = currentMember.email || "—";
    document.getElementById("profileMobile").textContent = currentMember.mobile || "—";
    document.getElementById("profileAddress").textContent = currentMember.address || "—";
    document.getElementById("profileOverlay").style.display = "flex";
}



function closeProfile() {

    document
        .getElementById("profileOverlay")
        .style.display = "none";

}



/* =================================================
   PROFILE OUTSIDE CLICK
================================================= */

document
    .getElementById("profileOverlay")
    .addEventListener(
        "click",
        function(event) {

            if (event.target === this) {

                closeProfile();

            }

        }
    );



/* =================================================
   ANNOUNCEMENTS
================================================= */

async function loadAnnouncements() {
    const track = document.getElementById("announcementTrack");
    try {
        const response = await fetch(memberPortalActionUrl("active_announcements"), {
            credentials: "same-origin",
            headers: { "Accept": "application/json" },
            cache: "no-store"
        });
        const result = await response.json();
        if (!response.ok || !result.success || !Array.isArray(result.announcements)) {
            track.innerHTML = "";
            return;
        }
        track.innerHTML = "";
        result.announcements.forEach(announcement => {
            const item = document.createElement("div");
            item.className = "announcement-item";
            item.textContent = `${announcement.title}: ${announcement.message}`;
            track.appendChild(item);
        });
    } catch (error) {
        track.innerHTML = "";
    }
}



/* =================================================
   LIBRARY DETAILS
================================================= */

const libraryDetails = <?= member_portal_json($libraryDetails) ?>;

function loadLibraryDetails() {
    document.getElementById("libraryName").textContent = libraryDetails.name || "—";
    document.getElementById("libraryAddress").textContent = libraryDetails.address || "—";
    document.getElementById("openingTime").textContent = libraryDetails.openingTime || "—";
    document.getElementById("closingTime").textContent = libraryDetails.closingTime || "—";
    document.getElementById("workingDays").textContent = libraryDetails.workingDays || "—";
    document.getElementById("sundayInfo").textContent = libraryDetails.holidaysInfo || "—";
    document.getElementById("holidaysInfo").textContent = libraryDetails.holidaysInfo || "—";
    document.getElementById("contactNumber").textContent = libraryDetails.contactNumber || "—";
}


/* =================================================
   ESCAPE HTML
================================================= */

function escapeHTML(value) {

    return String(value)

        .replace(/&/g, "&amp;")

        .replace(/</g, "&lt;")

        .replace(/>/g, "&gt;")

        .replace(/"/g, "&quot;")

        .replace(/'/g, "&#039;");

}



/* =================================================
   INITIAL LOAD
================================================= */

loadBooks();

loadIssuedBooks();
loadMyRequests();

document.addEventListener("visibilitychange", function() {
    if (!document.hidden) {
        loadAnnouncements();
    }
});

loadCategories();

loadAnnouncements();
loadLibraryDetails();


/*
   Refresh announcements every 30 seconds
   so expired/new announcements update automatically.
*/

setInterval(
    loadAnnouncements,
    30000
);



/* Keep the current section highlighted in the sticky navigation. */
(function setupMemberNavigation() {
    const nav = document.querySelector(".member-navigation");
    if (!nav) return;

    const navLinks = Array.from(nav.querySelectorAll("a"));
    const sections = [
        document.getElementById("home-section"),
        document.getElementById("issued-books"),
        document.getElementById("my-requests"),
        document.getElementById("all-books"),
        document.getElementById("library-details")
    ].filter(Boolean);

    navLinks.forEach(link => {
        link.addEventListener("click", function () {
            navLinks.forEach(item => item.classList.remove("active"));
            this.classList.add("active");
        });
    });

    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(entries => {
            const visible = entries
                .filter(entry => entry.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

            if (!visible) return;

            const activeLink = nav.querySelector(`a[href="#${visible.target.id}"]`);
            if (activeLink) {
                navLinks.forEach(item => item.classList.remove("active"));
                activeLink.classList.add("active");
            }
        }, {
            root: null,
            rootMargin: "-66px 0px -55% 0px",
            threshold: [0.05, 0.2, 0.5]
        });

        sections.forEach(section => observer.observe(section));
    }
})();

</script>


</body>

</html>