<?php
/* Database-connected Issue Book page. Keeps the existing page layout and client-side navigation. */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$conn = null;
$dbLoadError = '';
$dbCandidates = [
    __DIR__ . '/Connection/db.php',
    dirname(__DIR__) . '/Connection/db.php',
    dirname(__DIR__, 2) . '/Connection/db.php',
    __DIR__ . '/db.php',
    dirname(__DIR__) . '/db.php',
];
foreach ($dbCandidates as $dbFile) {
    if (is_file($dbFile)) {
        try {
            require_once $dbFile;
            if (isset($conn) && $conn instanceof mysqli) {
                break;
            }
        } catch (Throwable $e) {
            $dbLoadError = 'Unable to connect to the library database. Check Connection/db.php.';
        }
    }
}
if (!($conn instanceof mysqli)) {
    if ($dbLoadError === '') {
        $dbLoadError = 'Database connection was not found. Place this page where it can access Connection/db.php.';
    }
}
if ($conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

function issueBookJson(array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function issueBookPostString(string $key): string {
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

/* Same-page JSON API for requests and issue submission. */
$apiAction = isset($_GET['action']) ? (string)$_GET['action'] : (isset($_POST['action']) ? (string)$_POST['action'] : '');
if ($apiAction !== '') {
    if (!($conn instanceof mysqli)) {
        issueBookJson(['success' => false, 'message' => $dbLoadError ?: 'Database connection failed.'], 500);
    }

    try {
        if ($apiAction === 'list_requests') {
            $sql = "SELECT r.Request_id, r.Member_id, r.Book_id, r.Request_date, r.Status,
                           CONCAT(m.First_name, ' ', m.Last_name) AS Member_name,
                           b.Title AS Book_title
                    FROM issue_request r
                    INNER JOIN member m ON m.Member_id = r.Member_id
                    INNER JOIN book b ON b.Book_id = r.Book_id
                    ORDER BY r.Request_date DESC, r.Request_id DESC";
            $result = $conn->query($sql);
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $requests[] = [
                    'requestId' => (int)$row['Request_id'],
                    'memberId' => (int)$row['Member_id'],
                    'memberName' => (string)$row['Member_name'],
                    'bookId' => (int)$row['Book_id'],
                    'bookName' => (string)$row['Book_title'],
                    'status' => (string)$row['Status'],
                    'requestDate' => (string)$row['Request_date'],
                ];
            }
            issueBookJson(['success' => true, 'requests' => $requests]);
        }

        if ($apiAction === 'approve_request' || $apiAction === 'reject_request') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                issueBookJson(['success' => false, 'message' => 'POST is required for this action.'], 405);
            }
            $requestId = filter_var(issueBookPostString('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$requestId) {
                issueBookJson(['success' => false, 'message' => 'Invalid request ID.'], 400);
            }
            $newStatus = $apiAction === 'approve_request' ? 'Approved' : 'Rejected';
            if ($apiAction === 'approve_request') {
                $stmt = $conn->prepare("UPDATE issue_request SET Status='Approved' WHERE Request_id=? AND Status IN ('Pending','Approved')");
            } else {
                $stmt = $conn->prepare("UPDATE issue_request SET Status='Rejected' WHERE Request_id=? AND Status IN ('Pending','Approved')");
            }
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $check = $conn->prepare('SELECT Status FROM issue_request WHERE Request_id=?');
                $check->bind_param('i', $requestId);
                $check->execute();
                $statusRow = $check->get_result()->fetch_assoc();
                if (!$statusRow) {
                    issueBookJson(['success' => false, 'message' => 'Request was not found.'], 404);
                }
                if ($statusRow['Status'] !== $newStatus) {
                    issueBookJson(['success' => false, 'message' => 'This request can no longer be changed.'], 409);
                }
            }
            issueBookJson(['success' => true, 'message' => 'Request ' . strtolower($newStatus) . ' successfully.']);
        }

        if ($apiAction === 'issue_book') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                issueBookJson(['success' => false, 'message' => 'POST is required for this action.'], 405);
            }
            $memberId = filter_var(issueBookPostString('member_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $bookId = filter_var(issueBookPostString('book_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $issueDate = issueBookPostString('issue_date');
            $dueDate = issueBookPostString('due_date');
            $requestRaw = issueBookPostString('request_id');
            $requestId = 0;
            if ($requestRaw !== '') {
                $validatedRequestId = filter_var($requestRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($validatedRequestId === false) {
                    issueBookJson(['success' => false, 'message' => 'Invalid request ID. Refresh the request list and try again.'], 400);
                }
                $requestId = (int)$validatedRequestId;
            }
            $validDate = static function (string $date): bool {
                $parsed = DateTime::createFromFormat('!Y-m-d', $date);
                return $parsed instanceof DateTime && $parsed->format('Y-m-d') === $date;
            };
            if (!$memberId || !$bookId || !$validDate($issueDate) || !$validDate($dueDate)) {
                issueBookJson(['success' => false, 'message' => 'Select a member, a book, and valid issue/due dates.'], 400);
            }
            if ($dueDate < $issueDate) {
                issueBookJson(['success' => false, 'message' => 'Due date cannot be before the issue date.'], 400);
            }
            if ($issueDate < date('Y-m-d')) {
                issueBookJson(['success' => false, 'message' => 'Issue date cannot be in the past.'], 400);
            }

            $conn->begin_transaction();
            try {
                $memberStmt = $conn->prepare('SELECT Member_id, Current_Issue FROM member WHERE Member_id=? FOR UPDATE');
                $memberStmt->bind_param('i', $memberId);
                $memberStmt->execute();
                $member = $memberStmt->get_result()->fetch_assoc();
                if (!$member) {
                    throw new RuntimeException('Selected member does not exist.');
                }

                $bookStmt = $conn->prepare('SELECT Book_id, Title, Quantity FROM book WHERE Book_id=? FOR UPDATE');
                $bookStmt->bind_param('i', $bookId);
                $bookStmt->execute();
                $book = $bookStmt->get_result()->fetch_assoc();
                if (!$book) {
                    throw new RuntimeException('Selected book does not exist.');
                }
                if ((int)$book['Quantity'] < 1) {
                    throw new RuntimeException('No copies of this book are recorded in stock.');
                }

                $activeBookStmt = $conn->prepare("SELECT COUNT(*) AS active_count FROM issue_return WHERE Book_id=? AND Return_date IS NULL AND Status <> 'Returned'");
                $activeBookStmt->bind_param('i', $bookId);
                $activeBookStmt->execute();
                $activeBookCount = (int)$activeBookStmt->get_result()->fetch_assoc()['active_count'];
                if ($activeBookCount >= (int)$book['Quantity']) {
                    throw new RuntimeException('No available copies remain for this book.');
                }

                $activeMemberStmt = $conn->prepare("SELECT COUNT(*) AS active_count FROM issue_return WHERE Member_id=? AND Return_date IS NULL AND Status <> 'Returned'");
                $activeMemberStmt->bind_param('i', $memberId);
                $activeMemberStmt->execute();
                $activeMemberCount = (int)$activeMemberStmt->get_result()->fetch_assoc()['active_count'];

                $limitResult = $conn->query('SELECT Borrow_limit FROM library_details ORDER BY Id LIMIT 1');
                $limitRow = $limitResult ? $limitResult->fetch_assoc() : null;
                $borrowLimit = $limitRow ? (int)$limitRow['Borrow_limit'] : 3;
                if ($borrowLimit < 1) $borrowLimit = 3;
                if ($activeMemberCount >= $borrowLimit) {
                    throw new RuntimeException('This member has reached the library borrowing limit of ' . $borrowLimit . ' active book(s).');
                }

                if ($requestId) {
                    $requestStmt = $conn->prepare("SELECT Request_id FROM issue_request WHERE Request_id=? AND Member_id=? AND Book_id=? AND Status='Approved' FOR UPDATE");
                    $requestStmt->bind_param('iii', $requestId, $memberId, $bookId);
                    $requestStmt->execute();
                    if (!$requestStmt->get_result()->fetch_assoc()) {
                        throw new RuntimeException('The linked request must be approved and match the selected member and book before issuing.');
                    }
                }

                $insert = $conn->prepare("INSERT INTO issue_return (Member_id, Book_id, Issue_date, Due_date, Return_date, Status, Fine) VALUES (?, ?, ?, ?, NULL, 'Issued', 0.00)");
                $insert->bind_param('iiss', $memberId, $bookId, $issueDate, $dueDate);
                $insert->execute();
                $newIssueId = (int)$conn->insert_id;

                $newCurrentIssueCount = $activeMemberCount + 1;
                $memberUpdate = $conn->prepare('UPDATE member SET Current_Issue=?, Total_issue=Total_issue+1 WHERE Member_id=?');
                $memberUpdate->bind_param('ii', $newCurrentIssueCount, $memberId);
                $memberUpdate->execute();

                if ($requestId) {
                    $requestUpdate = $conn->prepare("UPDATE issue_request SET Status='Issued' WHERE Request_id=? AND Status='Approved'");
                    $requestUpdate->bind_param('i', $requestId);
                    $requestUpdate->execute();
                }

                $conn->commit();
                issueBookJson(['success' => true, 'message' => 'Book issued successfully.', 'issue_id' => $newIssueId, 'member_name' => '', 'book_title' => (string)$book['Title']]);
            } catch (Throwable $e) {
                $conn->rollback();
                if ($e instanceof RuntimeException) {
                    issueBookJson(['success' => false, 'message' => $e->getMessage()], 400);
                }
                throw $e;
            }
        }

        issueBookJson(['success' => false, 'message' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        error_log('Issue Book page error: ' . $e->getMessage());
        issueBookJson(['success' => false, 'message' => 'A database error occurred. Check the table structure and PHP error log.'], 500);
    }
}

$issuePageMembers = [];
$issuePageBooks = [];
if ($conn instanceof mysqli) {
    try {
        $membersResult = $conn->query("SELECT Member_id, First_name, Last_name FROM member ORDER BY First_name, Last_name");
        while ($row = $membersResult->fetch_assoc()) $issuePageMembers[] = $row;
        $booksResult = $conn->query("SELECT Book_id, Title, Quantity FROM book ORDER BY Title");
        while ($row = $booksResult->fetch_assoc()) $issuePageBooks[] = $row;
    } catch (Throwable $e) {
        $dbLoadError = 'Unable to load members or books. Check the member and book table structure.';
        error_log('Issue Book page loading error: ' . $e->getMessage());
    }
}
?>

<!DOCTYPE html>

<html>

<head>

    <title>Issue Book</title>

    <style>

        body {

            margin: 0;

            font-family: Arial, sans-serif;

            background-color: #fff;

        }

        /* =========================

           ORIGINAL SIDEBAR

        ========================== */

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
            overflow-y: auto;
            box-sizing: border-box;
            z-index: 1100;
        }

        .sidebar h2 {

            text-align: center;

            padding: 20px 0;

            margin: 0;

            background-color: #222;

        }

        .sidebar a {

            display: block;

            color: white;

            text-decoration: none;

            padding: 14px 20px;

            border-bottom: 1px solid #555;

        }

        .sidebar a:hover {

            background-color: #555;

        }

        .sidebar a.active {
            background-color: #555;
            border-left: 3px solid #fff;
            padding-left: 17px;
        }

        .sidebar-navigation {
            flex: 0 0 auto;
            width: 100%;
        }

        .manage {

            padding: 14px 20px;

            border-bottom: 1px solid #555;

            cursor: pointer;

        }

        .manage:hover {

            background-color: #555;

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

            font-size: 13px;

        }

        .logout {
            position: sticky;
            bottom: 0;
            width: 100%;
            margin-top: auto;
            flex: 0 0 auto;
            padding-top: 10px;
            background-color: #333;
        }

        .logout a {

            background-color: #222;

            text-align: center;

        }

        /* =========================

           MAIN CONTENT

        ========================== */

        .main {

            margin-left: 220px;

            padding: 0 25px 30px;

            min-height: 100vh;

            background: #fff;

            box-sizing: border-box;

        }

        /* =========================

           FORM CONTAINER

        ========================== */

        .form-container {

            background-color: #fff;

            width: 100%;

            max-width: 1100px;

            box-sizing: border-box;

            margin: 0 auto;

            padding: 36px 40px;

            border: 1px solid #dedede;

            box-shadow: 0 3px 14px rgba(0, 0, 0, 0.045);

        }

        #issueForm {

            display: grid;

            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);

            column-gap: 28px;

            align-items: start;

        }

        #issueForm > .form-group,

        #issueForm > .date-row {

            min-width: 0;

        }

        #issueForm > .button-area {

            grid-column: 1 / -1;

        }

        .form-container h1 {

            font-size: 28px;

        }

        .form-subtitle {

            font-size: 14px;

            margin-bottom: 32px;

        }

        .form-container h1 {

            text-align: center;

            margin-top: 0;

            margin-bottom: 8px;

            color: #333;

        }

        .form-subtitle {

            text-align: center;

            color: #777;

            margin-bottom: 30px;

            font-size: 14px;

        }

        /* =========================

           FORM

        ========================== */

        .form-group {

            margin-bottom: 20px;

        }

        .form-group label {

            display: block;

            margin-bottom: 7px;

            font-weight: bold;

            color: #333;

        }

        .required-mark {

            color: #d93025;

            font-weight: 700;

            margin-left: 3px;

        }

        .form-group input,

        .form-group select {

            width: 100%;

            padding: 11px;

            border: 1px solid #aaa;

            box-sizing: border-box;

            font-family: Arial;

            font-size: 14px;

            background-color: white;

        }

        .form-group input:focus,

        .form-group select:focus {

            outline: none;

            border-color: #555;

        }

        /* =========================

           INFORMATION BOX

        ========================== */

        .info-box {

            background-color: #f5f5f5;

            border-left: 4px solid #555;

            padding: 12px 15px;

            margin-bottom: 25px;

            font-size: 13px;

            color: #555;

            line-height: 1.5;

        }

        /* =========================

           TWO COLUMN DATES

        ========================== */

        .date-row {

            display: flex;

            gap: 15px;

            grid-column: 1 / -1;

        }

        .date-row .form-group {

            width: 50%;

        }

/* =========================

           BUTTONS

        ========================== */

        .button-area {

            text-align: center;

            margin-top: 28px;

            padding-top: 20px;

            border-top: 1px solid #ddd;

        }

        .issue-btn {

            background-color: #333;

            color: white;

            border: none;

            padding: 11px 28px;

            font-size: 15px;

            cursor: pointer;

        }

        .issue-btn:hover {

            background-color: #555;

        }

        .clear-btn {

            background-color: #777;

            color: white;

            border: none;

            padding: 11px 28px;

            font-size: 15px;

            cursor: pointer;

            margin-left: 10px;

        }

        .clear-btn:hover {

            background-color: #555;

        }

        /* =========================

           RESPONSIVE

        ========================== */

        @media (max-width: 900px) {

            .main {

                margin-left: 220px;

                padding: 15px;

            }

            .form-container {

                width: auto;

            }

        }

        @media (max-width: 600px) {

            .date-row {

                display: block;

            }

            .date-row .form-group {

                width: 100%;

            }

        }

        /* Compact in-page navigation aligned to the top-right of the content. */

        .top-navbar {

            position: static;

            z-index: auto;

            display: flex;

            align-items: center;

            justify-content: flex-end;

            gap: 10px;

            width: 100%;

            min-height: 54px;

            margin: 0 0 28px;

            padding: 8px 0;

            box-sizing: border-box;

            background: #fff;

            border-bottom: 1px solid #ededed;

        }

        .topbar-row {

            order: 2;

            display: flex;

            align-items: center;

            justify-content: flex-end;

            flex: 0 0 auto;

            margin-left: 0;

        }

        .topnav-toggle {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            align-self: center;

            flex: 0 0 36px;

            width: 36px;

            height: 36px;

            padding: 0;

            border: 1px solid #d3d3d3;

            border-radius: 5px;

            background: #fff;

            color: #333;

            cursor: pointer;

            font: 20px Arial, sans-serif;

            transition: *background* .15s ease, border-color .15s ease;

        }

        .topnav-toggle:hover { background: #f0f0f0; border-color: #bdbdbd; }

        .topnav-links {

            order: 1;

            display: flex;

            align-items: stretch;

            justify-content: flex-end;

            flex-direction: row;

            flex-wrap: wrap;

            gap: 8px;

            min-width: 0;

            margin: 0 0 0 auto;

            padding: 0;

            border: 0;

            border-radius: 0;

            background: transparent;

            box-shadow: none;

        }

        /* When collapsed, only the toggle symbol remains visible at the same top-right position. */

        .top-navbar.is-collapsed {

            min-height: 36px;

            margin: 0 0 28px;

            padding: 0;

            background: transparent;

            border: 0;

        }

        .top-navbar.is-collapsed .topbar-row { margin-left: auto; }

        .top-navbar.is-collapsed .topnav-toggle { background: #fff; }

        .topnav-links a {

            display: inline-flex;

            align-items: center;

            min-height: 53px;

            padding: 0 14px;

            text-decoration: none;

            color: #555;

            font-size: 13px;

            font-weight: 600;

            border: 0;

            border-bottom: 3px solid transparent;

            border-radius: 0;

            background: transparent;

            white-space: nowrap;

            transition: color .15s ease, *background* .15s ease, border-color .15s ease;

        }

        .topnav-links a:hover { color: #222; background: #f1f1f1; }

        .topnav-links a.current {

            color: #222;

            background: transparent;

            border-bottom-color: #444;

        }

        [hidden] { display: none !important; }

        /* Leave enough room for the sticky navigation when jumping to a section. */

        #issueSection, #requestsContainer, #allRequestsContainer {

            scroll-margin-top: 20px;

        }

        /* Selected request remains visible until the issue is completed or rejected */

        .form-container, #requestsContainer, #allRequestsContainer { scroll-margin-top: 20px; }

        /* Clearly highlight an approved/selected request without using yellow. */

        .request-table tbody tr.selected-request td {

            background: #eaf2ff !important;

            border-top: 1px solid #9bbdf7;

            border-bottom: 1px solid #9bbdf7;

        }

        .request-table tbody tr.selected-request td:first-child {

            box-shadow: inset 4px 0 0 #2864c5;

        }

        .request-table tbody tr.selected-request td:last-child {

            border-right: 1px solid #9bbdf7;

        }

        .request-action.approve:disabled { opacity: .82; cursor: pointer; }

        .all-requests-container { margin: 26px auto 0; }

        .all-requests-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; }

        .all-requests-heading h2 { margin: 0; color: #333; font-size: 21px; }

        .all-requests-content { margin-top: 2px; }

        .status-pill { display: inline-block; padding: 5px 9px; border-radius: 14px; font-size: 12px; font-weight: 700; white-space: nowrap; }

        .status-pill.pending { background: #fff; color: #555; border: 1px solid #ddd; }

        .status-pill.approved { background: #e5edff; color: #234b99; }

        .status-pill.rejected { background: #fbe8e8; color: #a12f2f; }

        .status-pill.issued { background: #e3f4e9; color: #22663c; }

        .all-requests-empty { padding: 22px; color: #666; text-align: center; }

        .pagination { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 13px 0 0; color: #555; font-size: 13px; }

        .pagination button { padding: 8px 12px; border: 1px solid #bbb; border-radius: 4px; background: #fff; color: #333; cursor: pointer; }

        .pagination button:disabled { color: #aaa; background: #f4f4f4; cursor: not-allowed; }

        /* ISSUE REQUESTS: only the request table, refresh control and empty message */

        .requests-container {

            width: 100%;

            max-width: 1100px;

            margin: 26px auto 0;

            padding: 20px 22px;

            box-sizing: border-box;

            background: #fff;

            border: 1px solid #dedede;

            box-shadow: 0 3px 14px rgba(0, 0, 0, 0.035);

        }

        .requests-heading {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 16px;

            margin-bottom: 0;

        }

        .requests-heading h2 { margin: 0; color: #333; font-size: 21px; }

        .request-heading-actions { display: flex; align-items: center; gap: 9px; }

        .request-toggle {

            width: 38px;

            height: 38px;

            border: 1px solid #aaa;

            border-radius: 4px;

            background: #fff;

            color: #333;

            font-size: 23px;

            line-height: 1;

            cursor: pointer;

        }

        .request-toggle:hover { background: #f2f2f2; }

        .request-refresh {

            flex: 0 0 auto;

            padding: 10px 14px;

            border: 1px solid #777;

            background: #f7f7f7;

            color: #333;

            border-radius: 4px;

            cursor: pointer;

            font-family: Arial, sans-serif;

            font-size: 13px;

        }

        .request-refresh:hover { background: #ececec; }

        .request-table-wrap { width: 100%; overflow-x: auto; margin-top: 17px; border: 1px solid #e1e1e1; }

        .request-table { width: 100%; min-width: 690px; border-collapse: collapse; font-size: 13px; }

        .request-table th { padding: 13px 15px; text-align: left; color: #444; background: #f4f4f4; border-bottom: 1px solid #ddd; white-space: nowrap; }

        .request-table td { padding: 13px 15px; border-bottom: 1px solid #eee; color: #444; vertical-align: middle; }

        .request-table tbody tr:last-child td { border-bottom: 0; }

        .request-table tbody tr:hover { background: #fafafa; }

        .request-actions { display: flex; align-items: center; gap: 7px; white-space: nowrap; }

        .request-action { padding: 8px 12px; border: 1px solid #aaa; border-radius: 4px; background: #fff; color: #333; cursor: pointer; font-family: Arial, sans-serif; font-size: 12px; }

        .request-action.approve { background: #333; color: #fff; border-color: #333; }

        .request-action.approve:hover { background: #505050; }

        .request-action.reject:hover { background: #f9eaea; border-color: #c99; }

        .request-empty { padding: 24px 10px 6px; text-align: center; color: #666; font-size: 14px; }

        /* Searchable dropdowns: type a name/title and choose a matching result */

        .search-select { position: relative; width: 100%; }

        .search-select-input {

            display: block;

            width: 100%;

            min-height: 48px;

            padding: 13px 44px 13px 14px;

            border: 1px solid #c8c8c8;

            border-radius: 6px;

            box-sizing: border-box;

            font-family: Arial, sans-serif;

            font-size: 14px;

            color: #292929;

            background: #fff;

            transition: border-color .15s ease, box-shadow .15s ease;

        }

        .search-select-input::placeholder { color: #858585; }

        .search-select-input:focus { outline: none; border-color: #565656; box-shadow: 0 0 0 3px rgba(55,55,55,.09); }

        .search-select::after {

            content: '⌄';

            position: absolute;

            top: 9px;

            right: 14px;

            color: #555;

            font-size: 21px;

            pointer-events: none;

        }

        .search-select-options {

            display: none;

            position: absolute;

            z-index: 30;

            top: calc(100% + 4px);

            left: 0;

            right: 0;

            max-height: 250px;

            overflow-y: auto;

            padding: 6px;

            border: 1px solid #d5d5d5;

            border-radius: 7px;

            background: #fff;

            box-shadow: 0 10px 24px rgba(0,0,0,.14);

        }

        .search-select.open .search-select-options { display: block; }

        .search-option {

            display: flex;

            align-items: center;

            gap: 12px;

            width: 100%;

            min-height: 44px;

            padding: 9px 10px;

            border: 0;

            border-radius: 5px;

            background: #fff;

            color: #333;

            text-align: left;

            font-family: Arial, sans-serif;

            font-size: 14px;

            cursor: pointer;

        }

        .search-option:hover, .search-option:focus { background: #f5f5f5; outline: none; }

        .search-option-id {

            display: inline-flex;

            flex: 0 0 auto;

            min-width: 38px;

            justify-content: center;

            padding: 5px 7px;

            border: 1px solid #e1e1e1;

            border-radius: 4px;

            background: #f5f5f5;

            color: #4b4b4b;

            font-size: 12px;

            font-weight: 700;

        }

        .search-option-label { min-width: 0; overflow-wrap: anywhere; }

        .search-no-result { padding: 12px 10px; color: #777; font-size: 13px; }

        .search-select-native { display: none; }

        @media (max-width: 900px) {

            .main { padding: 0 18px 24px; }

            .top-navbar { width: 100%; margin: 0 0 22px; padding: 8px 0; }

            .top-navbar.is-collapsed { padding: 0; }

            .form-container { padding: 28px; }

            #issueForm { column-gap: 20px; }

        }

        @media (max-width: 650px) {

            .top-navbar { align-items: center; justify-content: flex-end; gap: 7px; min-height: 48px; padding: 6px 0; }

            .top-navbar.is-collapsed { min-height: 36px; padding: 0; }

            .topnav-links { gap: 0; flex: 0 1 auto; }

            .topnav-links a { min-height: 40px; padding: 0 9px; font-size: 12px; }

            .topnav-toggle { flex-basis: 34px; width: 34px; height: 34px; }

            .all-requests-heading h2 { font-size: 19px; }

            .pagination { justify-content: center; flex-wrap: wrap; }

            .requests-container { padding: 15px; }

            .form-container { padding: 22px 18px; }

            #issueForm { display: block; }

            .date-row { display: block; }

            .date-row .form-group { width: 100%; }

            .requests-heading { align-items: flex-start; }

            .request-table { min-width: 650px; }

        }

        /* Temporarily hide the in-page top navigation; keep the main sidebar navigation. */
        .top-navbar {
            display: none !important;
        }

    </style>

</head>

<body>

    <!-- =========================

         ORIGINAL SIDEBAR

    ========================== -->

    <div class="sidebar">
        <h2>Library</h2>

        <div class="sidebar-navigation">
            <a href="admin_home.php">Dashboard</a>
            <a href="my_profile.php">My Profile</a>
            <a href="library_profile.php">Library Profile</a>

            <div class="manage" id="manageBooksToggle" onclick="toggleMenu()" role="button" tabindex="0" aria-expanded="false" aria-controls="submenu">
                Manage Books
                <span class="arrow" id="arrow">+</span>
            </div>

            <div class="submenu" id="submenu">
                <a href="view_books.php">View All Books</a>
                <a href="add_book.php">Add Book</a>
                <a href="update_book.php">Update / Delete Book</a>
            </div>

            <a href="issue_book.php" class="active" aria-current="page">Issue Book</a>
            <a href="return_book.php">Return Book</a>
            <a href="members.php">Members Details</a>
            <a href="announcement.php">Announcements</a>
        </div>

        <div class="logout">
            <a href="../login/login_form.php">Logout</a>
        </div>
    </div>

    <!-- =========================

         MAIN CONTENT

    ========================== -->

    <div class="main">

        <nav class="top-navbar" aria-label="Page navigation">

            <div class="topbar-row">

                <button type="button" class="topnav-toggle" id="toggleTopNav" aria-label="Hide navigation" title="Hide navigation" aria-expanded="true" aria-controls="topNavLinks">☰</button>

            </div>

            <div class="topnav-links" id="topNavLinks">

                <a href="#issueSection" class="current">Issue Book</a>

                <a href="#requestsContainer">Issue Requests</a>

                <a href="#allRequestsContainer">All Requests</a>

            </div>

        </nav>

        <?php if ($dbLoadError !== ''): ?>
        <div role="status" style="margin:0 0 18px;padding:12px 15px;border:1px solid #d9b2b2;background:#fff7f7;color:#8b2d2d;font-size:13px;">
            <?= htmlspecialchars($dbLoadError, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="form-container" id="issueSection">

            <h1>

                Issue Book

            </h1>

            <div class="form-subtitle">

                Record a new book issue for a library member

            </div>

            <form id="issueForm">

                <!-- MEMBER -->

                <div class="form-group">

                    <label for="member">Member <span class="required-mark">*</span></label>

                    <select id="member" required>
                        <option value="">Select Member</option>
                        <?php foreach ($issuePageMembers as $memberRow):
                            $memberIdOption = (int)$memberRow['Member_id'];
                            $memberNameOption = trim((string)$memberRow['First_name'] . ' ' . (string)$memberRow['Last_name']);
                            $memberSearchOption = $memberIdOption . ' ' . $memberNameOption;
                        ?>
                        <option value="<?= $memberIdOption ?>" data-id="<?= $memberIdOption ?>" data-search="<?= htmlspecialchars($memberSearchOption, ENT_QUOTES, 'UTF-8') ?>"><?= $memberIdOption ?> — <?= htmlspecialchars($memberNameOption, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <!-- BOOK -->

                <div class="form-group">

                    <label for="book">Book <span class="required-mark">*</span></label>

                    <select id="book" required>
                        <option value="">Select Book</option>
                        <?php foreach ($issuePageBooks as $bookRow):
                            $bookIdOption = (int)$bookRow['Book_id'];
                            $bookTitleOption = (string)$bookRow['Title'];
                            $bookSearchOption = $bookIdOption . ' ' . $bookTitleOption;
                        ?>
                        <option value="<?= $bookIdOption ?>" data-id="<?= $bookIdOption ?>" data-search="<?= htmlspecialchars($bookSearchOption, ENT_QUOTES, 'UTF-8') ?>"><?= $bookIdOption ?> — <?= htmlspecialchars($bookTitleOption, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <!-- DATES -->

                <div class="date-row">

                    <div class="form-group">

                        <label for="issueDate">Issue Date <span class="required-mark">*</span></label>

                        <input

                            type="date"

                            id="issueDate"

                            required

                        >

                    </div>

                    <div class="form-group">

                        <label for="dueDate">Due Date <span class="required-mark">*</span></label>

                        <input

                            type="date"

                            id="dueDate"

                            required

                        >

                    </div>

                </div>

                <!-- BUTTONS -->

                <div class="button-area">

                    <button

                        type="submit"

                        class="issue-btn"

                    >

                        Issue Book

                    </button>

                    <button

                        type="reset"

                        class="clear-btn"

                    >

                        Clear

                    </button>

                </div>

            </form>

        </div>

        <div class="requests-container" id="requestsContainer">

            <div class="requests-heading">

                <h2>Issue Requests</h2>

                <div class="request-heading-actions">

                    <button type="button" class="request-toggle" id="toggleIssueRequests" aria-label="Collapse issue requests" aria-expanded="true">−</button>

                    <button type="button" class="request-refresh" id="refreshIssueRequests">Refresh Requests</button>

                </div>

            </div>

            <div id="requestContent">

                <div class="request-table-wrap" id="requestTableWrap" style="display:none;">

                    <table class="request-table">

                        <thead>

                            <tr>

                                <th>Member ID</th>

                                <th>Member Name</th>

                                <th>Book ID</th>

                                <th>Book Title</th>

                                <th>Action</th>

                            </tr>

                        </thead>

                        <tbody id="issueRequestRows"></tbody>

                    </table>

                </div>

                <div class="request-empty" id="issueRequestEmpty">No issue requests found</div>

            </div>

        </div>

        <div class="requests-container all-requests-container" id="allRequestsContainer">

            <div class="all-requests-heading">

                <h2>All Requests</h2>

                <button type="button" class="request-toggle" id="toggleAllRequests" aria-label="Collapse all requests" aria-expanded="true">−</button>

            </div>

            <div class="all-requests-content" id="allRequestsContent">

                <div class="request-table-wrap">

                    <table class="request-table">

                        <thead>

                            <tr><th>Member ID</th><th>Member Name</th><th>Book ID</th><th>Book Title</th><th>Status</th></tr>

                        </thead>

                        <tbody id="allRequestRows"></tbody>

                    </table>

                </div>

                <div class="all-requests-empty" id="allRequestsEmpty" style="display:none;">No requests found</div>

                <div class="pagination" id="allRequestsPagination">

                    <button type="button" id="allRequestsPrev" disabled>Previous</button>

                    <span id="allRequestsPageLabel">Page 1 of 1</span>

                    <button type="button" id="allRequestsNext" disabled>Next</button>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================

         JAVASCRIPT

    ========================== -->

    <script>

        /* =========================
           SIDEBAR MENU
        ========================== */
        function setManageMenuState(expanded) {
            var menu = document.getElementById("submenu");
            var arrow = document.getElementById("arrow");
            var toggle = document.getElementById("manageBooksToggle");
            if (!menu || !arrow || !toggle) return;
            menu.style.display = expanded ? "block" : "none";
            arrow.textContent = expanded ? "−" : "+";
            toggle.setAttribute("aria-expanded", String(expanded));
        }

        function toggleMenu() {
            var menu = document.getElementById("submenu");
            setManageMenuState(!menu || menu.style.display !== "block");
        }

        // Highlight only the current page. Automatically expand Manage Books
        // for a submenu page without highlighting the Manage Books heading.
        document.addEventListener("DOMContentLoaded", function () {
            var currentPage = window.location.pathname.split("/").pop().toLowerCase();
            var sidebarLinks = document.querySelectorAll(".sidebar-navigation a");
            var activeSubmenuPage = false;

            sidebarLinks.forEach(function (link) {
                var linkPage = link.getAttribute("href").split("/").pop().toLowerCase();
                if (currentPage && linkPage === currentPage) {
                    sidebarLinks.forEach(function (item) {
                        item.classList.remove("active");
                        item.removeAttribute("aria-current");
                    });
                    link.classList.add("active");
                    link.setAttribute("aria-current", "page");
                    if (link.closest(".submenu")) activeSubmenuPage = true;
                }
            });

            setManageMenuState(activeSubmenuPage);

            var manageToggle = document.getElementById("manageBooksToggle");
            if (manageToggle) {
                manageToggle.addEventListener("keydown", function (event) {
                    if (event.key === "Enter" || event.key === " ") {
                        event.preventDefault();
                        toggleMenu();
                    }
                });
            }
        });

        /* =========================

           SET TODAY'S DATE

        ========================== */

        window.onload = function() {

            var today =

                new Date().toISOString().split("T")[0];

            document.getElementById("issueDate").value =

                today;

            document.getElementById("issueDate").min =

                today;

        };

        /* =========================

           DUE DATE VALIDATION

        ========================== */

        document

            .getElementById("issueDate")

            .addEventListener("change", function() {

                document

                    .getElementById("dueDate")

                    .min = this.value;

            });

        /* =========================
           DATABASE-BACKED ISSUE FORM AND REQUESTS
        ========================== */

        let issueRequests = [];
        let activeIssueRequestId = null;
        let allRequestsPage = 1;
        const ALL_REQUESTS_PAGE_SIZE = 10;

        function escapeRequestText(value) {
            return String(value ?? "").replace(/[&<>"']/g, function (char) {
                return {"&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"}[char];
            });
        }

        function requestStatus(raw) {
            const value = String(raw || "Pending").toLowerCase();
            if (value.includes("reject") || value.includes("declin")) return "Rejected";
            if (value.includes("approv")) return "Approved";
            if (value.includes("issued") || value === "complete" || value === "completed") return "Issued";
            if (value.includes("cancel")) return "Cancelled";
            return "Pending";
        }

        async function callIssueBookApi(action, payload = null) {
            const url = window.location.pathname + "?action=" + encodeURIComponent(action);
            const options = { method: payload ? "POST" : "GET", headers: { "Accept": "application/json" }, cache: "no-store" };
            if (payload) {
                options.headers["Content-Type"] = "application/x-www-form-urlencoded;charset=UTF-8";
                options.body = new URLSearchParams(payload).toString();
            }
            const response = await fetch(url, options);
            let data;
            try {
                data = await response.json();
            } catch (error) {
                throw new Error("The server returned an unreadable response. Check the PHP error log.");
            }
            if (!response.ok || !data.success) {
                throw new Error(data && data.message ? data.message : "The requested operation failed.");
            }
            return data;
        }

        async function loadIssueRequests() {
            const emptyMessage = document.getElementById("issueRequestEmpty");
            try {
                const data = await callIssueBookApi("list_requests");
                document.getElementById("issueRequestEmpty").textContent = "No issue requests found";
                document.getElementById("allRequestsEmpty").textContent = "No requests found";
                issueRequests = (Array.isArray(data.requests) ? data.requests : []).map(raw => ({
                    requestId: Number(raw.requestId),
                    memberId: String(raw.memberId ?? "-"),
                    memberName: String(raw.memberName ?? "-"),
                    bookId: String(raw.bookId ?? "-"),
                    bookName: String(raw.bookName ?? "-"),
                    status: requestStatus(raw.status),
                    requestDate: String(raw.requestDate ?? "")
                }));
                renderIssueRequests();
            } catch (error) {
                issueRequests = [];
                renderIssueRequests();
                emptyMessage.textContent = "Unable to load issue requests. " + error.message;
                document.getElementById("allRequestsEmpty").textContent = "Unable to load requests. " + error.message;
                document.getElementById("allRequestsEmpty").style.display = "block";
            }
        }

        function renderIssueRequests() {
            const activeRequests = issueRequests.filter(request => request.status === "Pending" || request.status === "Approved");
            const tbody = document.getElementById("issueRequestRows");
            tbody.innerHTML = activeRequests.map(request => {
                const selected = request.requestId === activeIssueRequestId || request.status === "Approved";
                const action = '<div class="request-actions">' +
                    '<button type="button" class="request-action approve" onclick="approveIssueRequest(' + request.requestId + ')">Approve</button>' +
                    '<button type="button" class="request-action reject" onclick="rejectIssueRequest(' + request.requestId + ')">Reject</button>' +
                    '</div>';
                return '<tr class="' + (selected ? 'selected-request' : '') + '"><td>' + escapeRequestText(request.memberId) + '</td>' +
                    '<td>' + escapeRequestText(request.memberName) + '</td>' +
                    '<td>' + escapeRequestText(request.bookId) + '</td>' +
                    '<td>' + escapeRequestText(request.bookName) + '</td>' +
                    '<td>' + action + '</td></tr>';
            }).join("");
            const hasRequests = activeRequests.length > 0;
            document.getElementById("requestTableWrap").style.display = hasRequests ? "block" : "none";
            document.getElementById("issueRequestEmpty").style.display = hasRequests ? "none" : "block";
            if (!document.getElementById("issueRequestEmpty").textContent.startsWith("Unable to load issue requests.")) {
                document.getElementById("issueRequestEmpty").textContent = "No issue requests found";
            }
            renderAllRequests();
        }

        function renderAllRequests() {
            const tbody = document.getElementById("allRequestRows");
            const totalPages = Math.max(1, Math.ceil(issueRequests.length / ALL_REQUESTS_PAGE_SIZE));
            allRequestsPage = Math.min(Math.max(1, allRequestsPage), totalPages);
            const start = (allRequestsPage - 1) * ALL_REQUESTS_PAGE_SIZE;
            const pageItems = issueRequests.slice(start, start + ALL_REQUESTS_PAGE_SIZE);
            tbody.innerHTML = pageItems.map(request => {
                const statusClass = request.status.toLowerCase();
                return '<tr><td>' + escapeRequestText(request.memberId) + '</td>' +
                    '<td>' + escapeRequestText(request.memberName) + '</td>' +
                    '<td>' + escapeRequestText(request.bookId) + '</td>' +
                    '<td>' + escapeRequestText(request.bookName) + '</td>' +
                    '<td><span class="status-pill ' + escapeRequestText(statusClass) + '">' + escapeRequestText(request.status) + '</span></td></tr>';
            }).join("");
            const hasRequests = issueRequests.length > 0;
            document.getElementById("allRequestsEmpty").style.display = hasRequests ? "none" : "block";
            if (hasRequests) document.getElementById("allRequestsEmpty").textContent = "No requests found";
            document.getElementById("allRequestsPagination").style.display = issueRequests.length > ALL_REQUESTS_PAGE_SIZE ? "flex" : "none";
            document.getElementById("allRequestsPageLabel").textContent = "Page " + allRequestsPage + " of " + totalPages;
            document.getElementById("allRequestsPrev").disabled = allRequestsPage <= 1;
            document.getElementById("allRequestsNext").disabled = allRequestsPage >= totalPages;
        }

        function setSelectValue(selectId, value) {
            const select = document.getElementById(selectId);
            if (!select || value === null || value === undefined || value === "") return false;
            const normalized = String(value).trim().toLowerCase();
            const found = Array.from(select.options).find(option =>
                option.value.toLowerCase() === normalized ||
                (option.dataset.id || "").toLowerCase() === normalized
            );
            if (!found) return false;
            select.value = found.value;
            if (window.searchableSelectSync && window.searchableSelectSync[selectId]) {
                window.searchableSelectSync[selectId]();
            }
            return true;
        }

        async function approveIssueRequest(requestId) {
            const request = issueRequests.find(item => item.requestId === Number(requestId));
            if (!request || (request.status !== "Pending" && request.status !== "Approved")) return;
            try {
                if (request.status === "Pending") {
                    await callIssueBookApi("approve_request", { request_id: request.requestId });
                }
                activeIssueRequestId = request.requestId;
                setSelectValue("member", request.memberId);
                setSelectValue("book", request.bookId);
                document.getElementById("issueDate").value = "";
                document.getElementById("dueDate").value = "";
                document.getElementById("dueDate").min = "";
                await loadIssueRequests();
                document.querySelector(".form-container").scrollIntoView({ behavior: "smooth", block: "start" });
                window.setTimeout(() => document.getElementById("issueDate").focus({ preventScroll: true }), 350);
            } catch (error) {
                alert(error.message);
            }
        }

        async function rejectIssueRequest(requestId) {
            const request = issueRequests.find(item => item.requestId === Number(requestId));
            if (!request || (request.status !== "Pending" && request.status !== "Approved")) return;
            if (!confirm('Reject the request from "' + request.memberName + '" for book title "' + request.bookName + '"?')) return;
            try {
                await callIssueBookApi("reject_request", { request_id: request.requestId });
                if (activeIssueRequestId === request.requestId) activeIssueRequestId = null;
                await loadIssueRequests();
            } catch (error) {
                alert(error.message);
            }
        }

        document.getElementById("issueForm").addEventListener("submit", async function(event) {
            event.preventDefault();
            const memberId = document.getElementById("member").value;
            const bookId = document.getElementById("book").value;
            const issueDate = document.getElementById("issueDate").value;
            const dueDate = document.getElementById("dueDate").value;
            const memberOption = document.getElementById("member").selectedOptions[0];
            const bookOption = document.getElementById("book").selectedOptions[0];
            const memberLabel = memberOption ? memberOption.textContent.trim() : memberId;
            const bookLabel = bookOption ? bookOption.textContent.trim() : bookId;

            if (!memberId || !bookId) {
                alert("Please select a member and a book from the dropdown lists.");
                return;
            }
            if (dueDate < issueDate) {
                alert("Due Date cannot be before Issue Date.");
                return;
            }
            if (!window.confirm("Confirm issuing this book?\n\nMember: " + memberLabel +
                "\nBook Title: " + bookLabel + "\nIssue Date: " + issueDate + "\nDue Date: " + dueDate)) {
                return;
            }

            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            try {
                const payload = {
                    member_id: memberId,
                    book_id: bookId,
                    issue_date: issueDate,
                    due_date: dueDate,
                    request_id: activeIssueRequestId === null ? "" : String(activeIssueRequestId)
                };
                const result = await callIssueBookApi("issue_book", payload);
                alert(result.message + "\n\nMember: " + memberLabel + "\nBook: " + bookLabel + "\nDue Date: " + dueDate);
                activeIssueRequestId = null;
                this.reset();
                const today = new Date().toISOString().split("T")[0];
                document.getElementById("issueDate").value = today;
                document.getElementById("issueDate").min = today;
                document.getElementById("dueDate").min = today;
                if (window.searchableSelectSync) {
                    window.searchableSelectSync.member();
                    window.searchableSelectSync.book();
                }
                await loadIssueRequests();
            } catch (error) {
                alert(error.message);
            } finally {
                submitButton.disabled = false;
            }
        });

        /* Searchable dropdown enhancement for the original Member and Book fields. */

        function makeSearchableSelect(selectId, placeholder, singularLabel) {

            const select = document.getElementById(selectId);

            const wrapper = document.createElement("div");

            wrapper.className = "search-select";

            const input = document.createElement("input");

            input.type = "text";

            input.id = selectId + "Lookup";

            input.className = "search-select-input";

            input.placeholder = placeholder;

            input.autocomplete = "off";

            input.required = true;

            input.setAttribute("aria-label", singularLabel);

            input.setAttribute("aria-haspopup", "listbox");

            input.setAttribute("aria-expanded", "false");

            const optionsPanel = document.createElement("div");

            optionsPanel.className = "search-select-options";

            optionsPanel.setAttribute("role", "listbox");

            select.parentNode.insertBefore(wrapper, select);

            wrapper.appendChild(input);

            wrapper.appendChild(optionsPanel);

            wrapper.appendChild(select);

            select.classList.add("search-select-native");

            select.removeAttribute("required");

            function closeOptions() {

                wrapper.classList.remove("open");

                input.setAttribute("aria-expanded", "false");

            }

            function chooseOption(option) {

                select.value = option.value;

                input.value = option.value;

                closeOptions();

                select.dispatchEvent(new Event("change", { bubbles: true }));

            }

            function drawOptions(query) {

                const term = (query || "").trim().toLowerCase();

                const choices = Array.from(select.options).filter(option => option.value !== "" &&

                    (option.textContent.trim().toLowerCase().includes(term) ||

                     option.value.toLowerCase().includes(term) ||

                     (option.dataset.id || "").toLowerCase().includes(term) ||

                     (option.dataset.search || "").toLowerCase().includes(term)));

                optionsPanel.innerHTML = "";

                if (!choices.length) {

                    const noMatch = document.createElement("div");

                    noMatch.className = "search-no-result";

                    noMatch.textContent = "No matching " + singularLabel.toLowerCase() + " found";

                    optionsPanel.appendChild(noMatch);

                } else {

                    choices.forEach(option => {

                        const item = document.createElement("button");

                        item.type = "button";

                        item.className = "search-option";

                        item.setAttribute("role", "option");

                        item.dataset.value = option.value;

                        const idBadge = document.createElement("span");

                        idBadge.className = "search-option-id";

                        idBadge.textContent = "ID " + (option.dataset.id || "—");

                        const label = document.createElement("span");

                        label.className = "search-option-label";

                        label.textContent = option.textContent.trim();

                        item.appendChild(idBadge);

                        item.appendChild(label);

                        item.addEventListener("mousedown", event => event.preventDefault());

                        item.addEventListener("click", () => chooseOption(option));

                        optionsPanel.appendChild(item);

                    });

                }

                wrapper.classList.add("open");

                input.setAttribute("aria-expanded", "true");

            }

            input.addEventListener("focus", () => drawOptions(input.value === select.options[select.selectedIndex]?.textContent.trim() ? "" : input.value));

            input.addEventListener("input", () => {

                const term = input.value.trim().toLowerCase();

                const exact = Array.from(select.options).find(option => option.value !== "" &&

                    (option.value.toLowerCase() === term ||

                     option.textContent.trim().toLowerCase() === term ||

                     (option.dataset.id || "").toLowerCase() === term));

                select.value = exact ? exact.value : "";

                drawOptions(input.value);

            });

            input.addEventListener("keydown", event => {

                if (event.key === "Escape") closeOptions();

                if (event.key === "ArrowDown") {

                    const first = optionsPanel.querySelector(".search-option");

                    if (first) { event.preventDefault(); first.focus(); }

                }

                if (event.key === "Enter" && wrapper.classList.contains("open")) {

                    const first = optionsPanel.querySelector(".search-option");

                    if (first) { event.preventDefault(); chooseOption(Array.from(select.options).find(option => option.value === first.dataset.value)); }

                }

            });

            document.addEventListener("click", event => {

                if (!wrapper.contains(event.target)) closeOptions();

            });

            function syncFromSelect() {

                const selected = select.options[select.selectedIndex];

                input.value = selected && selected.value !== "" ? selected.textContent.trim() : "";

            }

            window.searchableSelectSync = window.searchableSelectSync || {};

            window.searchableSelectSync[selectId] = syncFromSelect;

            select.addEventListener("change", syncFromSelect);

        }

        makeSearchableSelect("member", "Search member by ID or name...", "Member");

        makeSearchableSelect("book", "Search book by ID or title...", "Book");

        // Keep explicit validation for the searchable controls while preserving the original issue workflow.

        document.getElementById("issueForm").addEventListener("submit", function (event) {

            const member = document.getElementById("member");

            const book = document.getElementById("book");

            if (!member.value) {

                event.preventDefault();

                event.stopImmediatePropagation();

                alert("Please select a member from the dropdown list.");

                document.getElementById("memberLookup").focus();

            } else if (!book.value) {

                event.preventDefault();

                event.stopImmediatePropagation();

                alert("Please select a book from the dropdown list.");

                document.getElementById("bookLookup").focus();

            }

        }, true);

        document.getElementById("issueForm").addEventListener("reset", function () {

            activeIssueRequestIndex = null;

            window.setTimeout(function () {

                if (window.searchableSelectSync) {

                    window.searchableSelectSync.member();

                    window.searchableSelectSync.book();

                }

            }, 0);

        });

        document.getElementById("refreshIssueRequests").addEventListener("click", loadIssueRequests);

        document.getElementById("toggleTopNav").addEventListener("click", function () {

            const links = document.getElementById("topNavLinks");

            const navbar = this.closest(".top-navbar");

            const willExpand = links.hidden;

            links.hidden = !willExpand;

            navbar.classList.toggle("is-collapsed", !willExpand);

            this.setAttribute("aria-expanded", String(willExpand));

            this.setAttribute("aria-label", willExpand ? "Hide navigation" : "Show navigation");

            this.setAttribute("title", willExpand ? "Hide navigation" : "Show navigation");

        });

        document.querySelectorAll("#topNavLinks a").forEach(function (link) {

            link.addEventListener("click", function () {

                // Keep navigation visible after a section jump so it remains useful while scrolling.

                document.querySelectorAll("#topNavLinks a").forEach(function (item) {

                    item.classList.toggle("current", item === link);

                    if (item === link) item.setAttribute("aria-current", "location");

                    else item.removeAttribute("aria-current");

                });

            });

        });

        document.getElementById("toggleAllRequests").addEventListener("click", function () {

            const content = document.getElementById("allRequestsContent");

            const isExpanded = content.style.display !== "none";

            content.style.display = isExpanded ? "none" : "block";

            this.textContent = isExpanded ? "+" : "−";

            this.setAttribute("aria-expanded", String(!isExpanded));

            this.setAttribute("aria-label", isExpanded ? "Expand all requests" : "Collapse all requests");

        });

        document.getElementById("allRequestsPrev").addEventListener("click", function () {

            if (allRequestsPage > 1) { allRequestsPage--; renderAllRequests(); }

        });

        document.getElementById("allRequestsNext").addEventListener("click", function () {

            const totalPages = Math.max(1, Math.ceil(issueRequests.length / ALL_REQUESTS_PAGE_SIZE));

            if (allRequestsPage < totalPages) { allRequestsPage++; renderAllRequests(); }

        });

        document.getElementById("toggleIssueRequests").addEventListener("click", function () {

            const content = document.getElementById("requestContent");

            const isExpanded = content.style.display !== "none";

            content.style.display = isExpanded ? "none" : "block";

            this.textContent = isExpanded ? "+" : "−";

            this.setAttribute("aria-expanded", String(!isExpanded));

            this.setAttribute("aria-label", isExpanded ? "Expand issue requests" : "Collapse issue requests");

        });

        loadIssueRequests();

    </script>

</body>

</html>