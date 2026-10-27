<?php
declare(strict_types=1);

/* Member Details: database-backed page and same-page JSON actions. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function member_json_response(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function member_find_connection_file(): ?string {
    $dir = __DIR__;
    for ($i = 0; $i < 7; $i++) {
        $candidates = [
            $dir . DIRECTORY_SEPARATOR . 'Connection' . DIRECTORY_SEPARATOR . 'db.php',
            $dir . DIRECTORY_SEPARATOR . 'connection' . DIRECTORY_SEPARATOR . 'db.php',
            $dir . DIRECTORY_SEPARATOR . 'db.php',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return null;
}

function member_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$conn = null;
$dbReady = false;
$dbError = '';
$dbFile = member_find_connection_file();
try {
    if ($dbFile === null) {
        throw new RuntimeException('Connection/db.php or db.php could not be found.');
    }
    require_once $dbFile;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('The connection file must create a MySQLi connection named $conn.');
    }
    $conn->set_charset('utf8mb4');
    $dbReady = true;
} catch (Throwable $e) {
    $dbError = 'Database connection is not available. Check Connection/db.php and the $conn variable.';
    error_log('Member Details page: ' . $e->getMessage());
}

/* JSON API requests are used by the existing Update and Delete dialogs. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody ?: '', true);
    if (!is_array($input)) $input = $_POST;
    $action = (string)($input['action'] ?? '');

    if (!in_array($action, ['update_member', 'delete_member'], true)) {
        member_json_response(400, ['ok' => false, 'message' => 'Invalid request.']);
    }
    if (!$dbReady || !($conn instanceof mysqli)) {
        member_json_response(500, ['ok' => false, 'message' => $dbError ?: 'Database connection is not available.']);
    }

    $memberId = filter_var($input['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($memberId === false || $memberId === null) {
        member_json_response(400, ['ok' => false, 'message' => 'A valid Member ID is required.']);
    }

    try {
        if ($action === 'update_member') {
            $username = trim((string)($input['username'] ?? ''));
            $password = (string)($input['password'] ?? '');
            if (strlen($username) < 3 || strlen($username) > 25 || !preg_match('/^[A-Za-z][A-Za-z0-9._]*$/', $username)) {
                member_json_response(422, ['ok' => false, 'message' => 'Username must be 3–25 characters, start with a letter, and contain only letters, numbers, dots, or underscores.']);
            }
            if ($password !== '') {
                if (strlen($password) < 8 || strlen($password) > 25 || !preg_match('/^[A-Z]/', $password) || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password) || preg_match('/\s/', $password)) {
                    member_json_response(422, ['ok' => false, 'message' => 'Password must be 8–25 characters, start with a capital letter, contain a letter and a number, and contain no spaces.']);
                }
            }

            $stmt = $conn->prepare('SELECT User_id FROM member WHERE Member_id = ? LIMIT 1');
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$member) member_json_response(404, ['ok' => false, 'message' => 'Member not found. Refresh the page and try again.']);
            if ($member['User_id'] === null) {
                member_json_response(409, ['ok' => false, 'message' => 'This member has no linked login account, so its credentials cannot be updated here.']);
            }
            $userId = (int)$member['User_id'];

            $stmt = $conn->prepare('SELECT User_id FROM login WHERE Username = ? AND User_id <> ? LIMIT 1');
            $stmt->bind_param('si', $username, $userId);
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($duplicate) member_json_response(409, ['ok' => false, 'message' => 'This username is already used by another account.']);

            if ($password === '') {
                $stmt = $conn->prepare("UPDATE login SET Username = ? WHERE User_id = ? AND Role = 'Member'");
                $stmt->bind_param('si', $username, $userId);
            } else {
                /* Keep the existing project's current login storage format for compatibility. */
                $stmt = $conn->prepare("UPDATE login SET Username = ?, Password = ? WHERE User_id = ? AND Role = 'Member'");
                $stmt->bind_param('ssi', $username, $password, $userId);
            }
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            $stmt = $conn->prepare("SELECT Username FROM login WHERE User_id = ? AND Role = 'Member' LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $updated = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$updated) member_json_response(409, ['ok' => false, 'message' => 'No linked Member login record was updated.']);
            member_json_response(200, ['ok' => true, 'message' => 'Member credentials updated successfully.', 'username' => $updated['Username']]);
        }

        /* A member may be deleted only when every issued book has been returned. */
        $conn->begin_transaction();
        $stmt = $conn->prepare('SELECT User_id FROM member WHERE Member_id = ? FOR UPDATE');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) {
            $conn->rollback();
            member_json_response(404, ['ok' => false, 'message' => 'Member not found. Refresh the page and try again.']);
        }

        /* Return_date IS NULL means the member currently has a book that has not been returned. */
        $stmt = $conn->prepare('SELECT Issue_id FROM issue_return WHERE Member_id = ? AND Return_date IS NULL FOR UPDATE');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $activeIssues = $stmt->get_result()->num_rows;
        $stmt->close();
        if ($activeIssues > 0) {
            $conn->rollback();
            member_json_response(409, [
                'ok' => false,
                'message' => 'Cannot delete this member because they currently have ' . $activeIssues . ' book(s) that have not been returned. Return all issued books first.'
            ]);
        }

        $userId = $member['User_id'] === null ? null : (int)$member['User_id'];

        /* No active issues remain. Remove rows owned by this member so foreign keys do not block deletion. */
        $stmt = $conn->prepare('DELETE FROM issue_request WHERE Member_id = ?');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $stmt->close();

        /* Returned issue history is removed as part of the explicit member deletion. */
        $stmt = $conn->prepare('DELETE FROM issue_return WHERE Member_id = ? AND Return_date IS NOT NULL');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM member WHERE Member_id = ?');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $deletedMembers = $stmt->affected_rows;
        $stmt->close();
        if ($deletedMembers !== 1) {
            throw new RuntimeException('The member record was not deleted.');
        }

        if ($userId !== null) {
            $stmt = $conn->prepare("DELETE FROM login WHERE User_id = ? AND Role = 'Member' AND NOT EXISTS (SELECT 1 FROM member WHERE User_id = ?) AND NOT EXISTS (SELECT 1 FROM admin WHERE User_id = ?)");
            $stmt->bind_param('iii', $userId, $userId, $userId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
        member_json_response(200, ['ok' => true, 'message' => 'Member deleted successfully. The member login, requests, and returned-issue records were also removed.']);
    } catch (Throwable $e) {
        try { if ($conn instanceof mysqli) $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Member Details action error: ' . $e->getMessage());
        if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1451) {
            member_json_response(409, ['ok' => false, 'message' => 'This member is still linked to other library records, so the database blocked deletion. Check for related records in other tables.']);
        }
        member_json_response(500, ['ok' => false, 'message' => 'The member could not be deleted because the database operation failed. Check the PHP error log for details.']);
    }
}

$members = [];
if ($dbReady && $conn instanceof mysqli) {
    try {
        $sql = "SELECT m.Member_id, m.User_id,
                       TRIM(CONCAT(COALESCE(m.First_name, ''), ' ', COALESCE(m.Last_name, ''))) AS Member_name,
                       COALESCE(l.Username, '') AS Username,
                       m.Email, m.Phone_no, m.Address, m.Total_issue,
                       (SELECT COUNT(*) FROM issue_return ir WHERE ir.Member_id = m.Member_id AND ir.Return_date IS NULL) AS Pending_returns
                FROM member m
                LEFT JOIN login l ON l.User_id = m.User_id AND l.Role = 'Member'
                ORDER BY m.Member_id ASC";
        $result = $conn->query($sql);
        $members = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } catch (Throwable $e) {
        error_log('Member Details query error: ' . $e->getMessage());
        $dbError = 'Could not load member records. Verify the member, login, issue_return, and issue_request tables.';
        $members = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Details</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f2f2f2;
            color: #333;
        }

        /* Sidebar - shared latest menu */
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

        /* Main layout and page header */
        .main {
            margin-left: 220px;
            padding: 0 25px 25px;
            min-width: 0;
        }
        .top {
            margin-top: 10px;
            background-color: white;
            padding: 20px;
            border: 1px solid #ddd;
        }
        .top h1 {
            margin: 0;
            text-align: center;
            color: #333;
            font-size: 36px;
        }
        .top p {
            text-align: center;
            color: #666;
            font-size: 18px;
            margin: 18px 0 0;
        }

        /* Search and Filter — dimensions matched to All Books */
        .search-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 20px;
        }
        .search {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            flex-wrap: nowrap;
        }
        .search-input-wrap {
            flex: 0 0 325px;
            min-width: 0;
        }
        .search input {
            width: 325px;
            padding: 12px;
            border: 1px solid #aaa;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
            background: #fff;
            color: #333;
            outline: none;
        }
        .search input:focus { border-color: #465660; }
        .search input.search-input-invalid { border: 2px solid red !important; }
        .search button {
            padding: 12px 22px;
            background-color: #465660;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            white-space: nowrap;
        }
        .search button:hover { background-color: #37434a; }
        .search .filter-btn { background-color: #666 !important; }
        .search .filter-btn:hover { background-color: #555 !important; }
        .search-validation {
            display: none;
            width: 325px;
            align-self: center;
            margin-top: 6px;
            color: red;
            font-size: 14px;
            text-align: left;
            line-height: 1.4;
        }

        /* Filter modal matching the All Books popup */
        .filter-overlay {
            display: none;
            position: fixed;
            inset: 0;
            padding: 20px;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .filter-popup {
            width: 620px;
            max-width: calc(100% - 40px);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            background: white;
            border-radius: 10px;
            padding: 32px;
            box-sizing: border-box;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.25);
        }
        .filter-popup h2 {
            margin: 0 0 28px;
            color: #465660;
            font-size: 30px;
        }
        .filter-group { margin-bottom: 22px; }
        .filter-group label {
            display: block;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }
        .filter-group select {
            width: 100%;
            padding: 14px 18px;
            font-size: 18px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: white;
            box-sizing: border-box;
        }
        .filter-actions {
            display: flex;
            gap: 15px;
            margin-top: 28px;
        }
        .filter-actions button {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 6px;
            font-size: 18px;
            cursor: pointer;
        }
        .apply-filter { background-color: #465660; color: white; }
        .apply-filter:hover { background-color: #37434a; }
        .clear-filter { background-color: #ddd; color: #333; }
        .clear-filter:hover { background-color: #ccc; }

        /* Member table and horizontal scrolling */
        .members {
            background-color: white;
            margin-top: 25px;
            padding: 20px;
            border: 1px solid #ddd;
        }
        .members h2 {
            color: #333;
            margin: 0 0 18px;
            font-size: 26px;
        }
        .server-message {
            margin: 0 0 16px;
            padding: 12px 14px;
            border: 1px solid #e0b4b4;
            border-radius: 4px;
            background: #fff5f5;
            color: #a12626;
            line-height: 1.45;
        }
        .table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            box-sizing: border-box;
        }
        table {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ccc;
            text-align: left;
            white-space: nowrap;
            height: 45px;
            box-sizing: border-box;
        }
        th { background-color: #eee; color: #333; }
        .update {
            background-color: #465660;
            color: #fff;
            border: none;
            border-radius: 3px;
            padding: 8px 12px;
            margin-right: 6px;
            cursor: pointer;
        }
        .update:hover { background-color: #37434a; }

        /* Update member credentials popup */
        .edit-overlay {
            display: none;
            position: fixed;
            inset: 0;
            padding: 20px;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
            z-index: 1100;
        }
        .edit-popup {
            width: 620px;
            max-width: calc(100% - 40px);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            padding: 32px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.25);
            box-sizing: border-box;
        }
        .edit-popup h2 {
            margin: 0 0 24px;
            color: #465660;
            font-size: 28px;
        }
        .edit-field { margin-bottom: 18px; }
        .edit-field label {
            display: block;
            font-size: 17px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .edit-field input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #aaa;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
            outline: none;
        }
        .edit-field input:focus { border-color: #465660; }
        .edit-field input.invalid { border: 2px solid #d93025; }
        .field-error {
            display: none;
            color: #d93025;
            font-size: 13px;
            margin-top: 5px;
            line-height: 1.4;
        }
        .field-error.show { display: block; }
        .field-hint { color: #666; font-size: 13px; margin: 7px 0 0; }
        .edit-actions { display: flex; gap: 12px; margin-top: 25px; }
        .edit-actions button {
            flex: 1;
            padding: 13px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
        .save-update { background: #465660; color: white; }
        .save-update:hover { background: #37434a; }
        .cancel-update { background: #ddd; color: #333; }
        .cancel-update:hover { background: #ccc; }
        .update-success {
            display: none;
            margin-top: 15px;
            color: #24723a;
            font-size: 14px;
        }

        .delete {
            background-color: #d9534f;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 8px 14px;
            cursor: pointer;
        }
        .delete:hover { background-color: #c9302c; }
        .no-results {
            display: none;
            text-align: center;
            color: red;
            font-size: 17px;
            font-weight: bold;
            padding: 20px 8px;
        }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 18px;
            min-height: 38px;
            flex-wrap: wrap;
        }
        .pagination button {
            min-width: 38px;
            padding: 9px 12px;
            border: 1px solid #ccc;
            background: white;
            color: #333;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .pagination button:hover:not(:disabled) { background: #eee; }
        .pagination button.active {
            background: #465660;
            color: white;
            border-color: #465660;
        }
        .pagination button:disabled {
            color: #aaa;
            cursor: not-allowed;
            background: #f5f5f5;
        }
        .page-info {
            color: #666;
            font-size: 14px;
            text-align: center;
            margin-top: 8px;
        }

        @media (max-width: 800px) {
            .sidebar { width: 180px; }
            .main { margin-left: 180px; padding: 0 15px 20px; }
            .search { flex-wrap: wrap; }
            .search-input-wrap { flex: 1 1 100%; }
            .search input { width: min(100%, 325px); }
            .search button { padding: 11px 18px; }
            .search-validation { width: min(100%, 325px); }
            .filter-popup { padding: 24px; }
            .filter-popup h2 { font-size: 26px; }
            .filter-group label { font-size: 18px; }
            .filter-group select { font-size: 16px; }
            .filter-actions { flex-direction: column; }
        }
        @media (max-width: 540px) {
            .sidebar { width: 150px; }
            .sidebar a, .manage { padding: 12px; font-size: 13px; }
            .sidebar h2 { font-size: 20px; }
            .main { margin-left: 150px; padding: 0 10px 15px; }
            .top, .members { padding: 14px; }
            .top h1 { font-size: 27px; }
            .top p { font-size: 15px; }
            .search { gap: 8px; }
            .search-btn, .filter-btn { padding: 0 12px; }
            .filter-popup { padding: 22px; }
            .logout { position: static; margin-top: 0; }
        }
    </style>
</head>
<body>
    <!-- Sidebar: latest shared menu -->
    <aside class="sidebar" aria-label="Admin navigation">
        <h2>Library</h2>
        <nav class="sidebar-menu" aria-label="Main menu">
            <a href="admin_home.php">Dashboard</a>
            <a href="my_profile.php">My Profile</a>
            <a href="library_profile.php">Library Profile</a>

            <div class="manage" id="manageBooksToggle" role="button" tabindex="0"
                 aria-expanded="false" aria-controls="submenu" onclick="toggleMenu()">
                Manage Books
                <span class="arrow" id="arrow" aria-hidden="true">+</span>
            </div>
            <div class="submenu" id="submenu">
                <a href="view_books.php">View All Books</a>
                <a href="add_book.php">Add Book</a>
                <a href="update_book.php">Update / Delete Book</a>
            </div>

            <a href="issue_book.php">Issue Book</a>
            <a href="return_book.php">Return Book</a>
            <a href="members.php" class="active" aria-current="page">Members Details</a>
            <a href="announcements.php">Announcements</a>
        </nav>
        <div class="logout"><a href="../login/login_form.php">Logout</a></div>
    </aside>

    <main class="main">
        <section class="top">
            <h1>Member Details</h1>
            <p>View and manage registered library members.</p>

            <div class="search-container">
                <div class="search">
                    <div class="search-input-wrap">
                        <input
                            id="search"
                            type="text"
                            placeholder="Search by Member ID or Name"
                            maxlength="200"
                            autocomplete="off"
                            aria-describedby="searchValidation"
                        >
                        <div id="searchValidation" class="search-validation" role="alert"></div>
                    </div>
                    <button type="button" class="search-btn" onclick="searchMember()">Search</button>
                    <button type="button" class="filter-btn" id="filterButton" aria-expanded="false" onclick="openFilter()">Filter</button>
                </div>
            </div>
        </section>

        <section class="members">
            <h2>Library Members</h2>
            <?php if ($dbError !== ''): ?>
                <div class="server-message" role="alert"><?= member_h($dbError) ?></div>
            <?php endif; ?>
            <div class="table-wrap">
                <table id="memberTable">
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Total Books Issued</th>
                            <th>Pending Returns</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="memberTableBody">
                        <?php foreach ($members as $member): ?>
                        <tr data-member-id="<?= (int)$member['Member_id'] ?>">
                            <td><?= (int)$member['Member_id'] ?></td>
                            <td><?= member_h($member['Member_name']) ?></td>
                            <td class="username-cell"><?= member_h($member['Username']) ?></td>
                            <td><?= member_h($member['Email']) ?></td>
                            <td><?= member_h($member['Phone_no']) ?></td>
                            <td><?= member_h($member['Address']) ?></td>
                            <td><?= (int)$member['Total_issue'] ?></td>
                            <td class="current-issues-cell"><?= (int)$member['Pending_returns'] ?></td>
                            <td class="action-cell">
                                <button type="button" class="update" onclick="openUpdate(this)">Update</button>
                                <button type="button" class="delete" onclick="deleteMember(this)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="noResults" class="no-results">Member details not found.</div>
            <div id="pagination" class="pagination" aria-label="Member table pagination"></div>
            <div id="pageInfo" class="page-info"></div>
        </section>
    </main>

    <!-- Centered Filter Members modal -->
    <div class="filter-overlay" id="filterOverlay" onclick="closeFilterOnOverlay(event)">
        <div class="filter-popup" role="dialog" aria-modal="true" aria-labelledby="filterTitle" onclick="event.stopPropagation()">
            <h2 id="filterTitle">Filter Members</h2>

            <div class="filter-group">
                <label for="issuedFilter">Current Issues</label>
                <select id="issuedFilter">
                    <option value="all">All Status</option>
                    <option value="issued">Has Books Issued</option>
                    <option value="none">No Books Issued</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sortFilter">Sort By</label>
                <select id="sortFilter">
                    <option value="default">Default</option>
                    <option value="name_asc">Name A-Z</option>
                    <option value="name_desc">Name Z-A</option>
                    <option value="id_asc">Member ID Ascending</option>
                    <option value="id_desc">Member ID Descending</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="button" class="apply-filter" onclick="applyFilters()">Apply Filter</button>
                <button type="button" class="clear-filter" onclick="resetFilters()">Clear</button>
            </div>
        </div>
    </div>

    <!-- Update Member Username / Password Popup -->
    <div class="edit-overlay" id="editOverlay" onclick="closeEditOnOverlay(event)">
        <div class="edit-popup" role="dialog" aria-modal="true" aria-labelledby="editTitle" onclick="event.stopPropagation()">
            <h2 id="editTitle">Update Member Credentials</h2>
            <p id="editingMemberLabel" class="field-hint"></p>

            <div class="edit-field">
                <label for="editUsername">Username <span style="color:#d93025">*</span></label>
                <input type="text" id="editUsername" maxlength="25" autocomplete="off" aria-describedby="usernameError">
                <div class="field-error" id="usernameError" role="alert"></div>
            </div>

            <div class="edit-field">
                <label for="editPassword">New Password</label>
                <input type="password" id="editPassword" maxlength="25" autocomplete="new-password" aria-describedby="passwordError">
                <p class="field-hint">Leave blank to keep the current password. Use 8–25 characters, start with a capital letter, and include a letter and a number.</p>
                <div class="field-error" id="passwordError" role="alert"></div>
            </div>

            <div class="edit-field">
                <label for="confirmPassword">Confirm New Password</label>
                <input type="password" id="confirmPassword" maxlength="25" autocomplete="new-password" aria-describedby="confirmPasswordError">
                <div class="field-error" id="confirmPasswordError" role="alert"></div>
            </div>

            <div class="edit-actions">
                <button type="button" class="save-update" onclick="saveMemberUpdate()">Save Changes</button>
                <button type="button" class="cancel-update" onclick="closeEdit()">Cancel</button>
            </div>
            <div id="updateSuccess" class="update-success" role="status"></div>
        </div>
    </div>

    <script>
        const ROWS_PER_PAGE = 8;
        let currentPage = 1;
        let originalOrder = [];

        const searchInput = document.getElementById("search");
        const searchValidation = document.getElementById("searchValidation");
        const memberTableBody = document.getElementById("memberTableBody");
        let currentEditingRow = null;

        function toggleMenu() {
            const menu = document.getElementById("submenu");
            const arrow = document.getElementById("arrow");
            const toggle = document.getElementById("manageBooksToggle");
            const willOpen = !menu.classList.contains("open");
            menu.classList.toggle("open", willOpen);
            arrow.textContent = willOpen ? "-" : "+";
            toggle.setAttribute("aria-expanded", String(willOpen));
        }

        document.getElementById("manageBooksToggle").addEventListener("keydown", function(event) {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                toggleMenu();
            }
        });

        function showSearchError(message) {
            searchInput.classList.add("search-input-invalid");
            searchValidation.textContent = message;
            searchValidation.style.display = "block";
        }

        function clearSearchError() {
            searchInput.classList.remove("search-input-invalid");
            searchValidation.textContent = "";
            searchValidation.style.display = "none";
        }

        // Matches the All Books search validation: empty search is valid;
        // only the maximum length and control characters are rejected.
        function validateSearch() {
            const value = searchInput.value.trim();
            clearSearchError();

            if (value.length > 100) {
                showSearchError("Search text cannot be more than 100 characters.");
                searchInput.focus();
                return false;
            }
            if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(value)) {
                showSearchError("Search text contains invalid characters.");
                searchInput.focus();
                return false;
            }
            return true;
        }

        function getAllRows() {
            return Array.from(memberTableBody.querySelectorAll("tr"));
        }

        function getSearchValue() {
            return searchInput.value.trim().toLocaleLowerCase();
        }

        function rowMatchesSearch(row) {
            const query = getSearchValue();
            if (query === "") return true; // Blank search resets the text search.
            const memberId = (row.cells[0]?.textContent || "").trim().toLocaleLowerCase();
            const name = (row.cells[1]?.textContent || "").trim().toLocaleLowerCase();
            return memberId.includes(query) || name.includes(query);
        }

        function rowMatchesFilter(row) {
            const issuedFilter = document.getElementById("issuedFilter").value;
            const countText = (row.cells[7]?.textContent || "0").trim();
            const issuedCount = Number.parseInt(countText, 10) || 0;

            if (issuedFilter === "issued") return issuedCount > 0;
            if (issuedFilter === "none") return issuedCount === 0;
            return issuedFilter === "all";
        }

        function getVisibleRows() {
            return getAllRows().filter(row => rowMatchesSearch(row) && rowMatchesFilter(row));
        }

        function sortRows(rows) {
            const sortValue = document.getElementById("sortFilter").value;
            const sorted = rows.slice();

            if (sortValue === "name_asc" || sortValue === "name_desc") {
                sorted.sort((a, b) => {
                    const nameA = (a.cells[1]?.textContent || "").trim().toLocaleLowerCase();
                    const nameB = (b.cells[1]?.textContent || "").trim().toLocaleLowerCase();
                    const result = nameA.localeCompare(nameB);
                    return sortValue === "name_asc" ? result : -result;
                });
            } else if (sortValue === "id_asc" || sortValue === "id_desc") {
                sorted.sort((a, b) => {
                    const idA = Number.parseInt((a.cells[0]?.textContent || "").trim(), 10) || 0;
                    const idB = Number.parseInt((b.cells[0]?.textContent || "").trim(), 10) || 0;
                    return sortValue === "id_asc" ? idA - idB : idB - idA;
                });
            } else {
                sorted.sort((a, b) => originalOrder.indexOf(a) - originalOrder.indexOf(b));
            }
            return sorted;
        }

        function renderTable() {
            const allRows = getAllRows();
            const filteredRows = sortRows(getVisibleRows());
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / ROWS_PER_PAGE));

            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const startIndex = (currentPage - 1) * ROWS_PER_PAGE;
            const pageRows = filteredRows.slice(startIndex, startIndex + ROWS_PER_PAGE);

            allRows.forEach(row => {
                row.style.display = "none";
                memberTableBody.appendChild(row);
            });
            pageRows.forEach(row => {
                row.style.display = "";
                memberTableBody.appendChild(row);
            });

            const noResults = document.getElementById("noResults");
            noResults.style.display = filteredRows.length === 0 ? "block" : "none";

            renderPagination(filteredRows.length, totalPages);
            renderPageInfo(filteredRows.length, startIndex, pageRows.length);
        }

        function renderPagination(totalRows, totalPages) {
            const pagination = document.getElementById("pagination");
            pagination.innerHTML = "";

            if (totalRows <= ROWS_PER_PAGE) return;

            const previous = document.createElement("button");
            previous.type = "button";
            previous.textContent = "Previous";
            previous.disabled = currentPage === 1;
            previous.addEventListener("click", () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            });
            pagination.appendChild(previous);

            for (let page = 1; page <= totalPages; page++) {
                const button = document.createElement("button");
                button.type = "button";
                button.textContent = String(page);
                button.classList.toggle("active", page === currentPage);
                button.setAttribute("aria-current", page === currentPage ? "page" : "false");
                button.addEventListener("click", () => {
                    currentPage = page;
                    renderTable();
                });
                pagination.appendChild(button);
            }

            const next = document.createElement("button");
            next.type = "button";
            next.textContent = "Next";
            next.disabled = currentPage === totalPages;
            next.addEventListener("click", () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
            pagination.appendChild(next);
        }

        function renderPageInfo(totalRows, startIndex, visibleOnPage) {
            const info = document.getElementById("pageInfo");
            if (totalRows === 0) {
                info.textContent = "";
                return;
            }
            const first = startIndex + 1;
            const last = startIndex + visibleOnPage;
            info.textContent = `Showing ${first}-${last} of ${totalRows} member(s)`;
        }

        function searchMember() {
            if (!validateSearch()) return;
            currentPage = 1;
            renderTable();
        }

        function openFilter() {
            document.getElementById("filterOverlay").style.display = "flex";
            document.getElementById("filterButton").setAttribute("aria-expanded", "true");
        }

        function closeFilter() {
            document.getElementById("filterOverlay").style.display = "none";
            document.getElementById("filterButton").setAttribute("aria-expanded", "false");
        }

        function closeFilterOnOverlay(event) {
            if (event.target === document.getElementById("filterOverlay")) closeFilter();
        }

        function validateFilters() {
            const issued = document.getElementById("issuedFilter").value;
            const sort = document.getElementById("sortFilter").value;
            if (!["all", "issued", "none"].includes(issued)) {
                alert("Please select a valid books-issued filter.");
                return false;
            }
            if (!["default", "name_asc", "name_desc", "id_asc", "id_desc"].includes(sort)) {
                alert("Please select a valid sorting option.");
                return false;
            }
            return true;
        }

        function applyFilters() {
            if (!validateFilters() || !validateSearch()) return;
            currentPage = 1;
            renderTable();
            closeFilter();
        }

        function resetFilters() {
            document.getElementById("issuedFilter").value = "all";
            document.getElementById("sortFilter").value = "default";
            searchInput.value = "";
            clearSearchError();
            currentPage = 1;
            renderTable();
            closeFilter();
        }

        async function deleteMember(button) {
            const rowToDelete = button.closest("tr");
            if (!rowToDelete) return;
            const memberIdForConfirm = rowToDelete.dataset.memberId || rowToDelete.cells[0]?.textContent.trim();
            const memberNameForConfirm = rowToDelete.cells[1]?.textContent.trim() || "this member";
            if (!confirm("Delete member " + memberNameForConfirm + " (ID " + memberIdForConfirm + ")?\n\nA member can be deleted only if they have no books currently awaiting return. Returned-issue history and related requests will also be deleted.")) return;
            const row = button.closest("tr");
            if (!row) return;
            const memberId = row.dataset.memberId || row.cells[0]?.textContent.trim();
            button.disabled = true;
            try {
                const result = await sendMemberAction({ action: "delete_member", member_id: memberId });
                const index = originalOrder.indexOf(row);
                if (index !== -1) originalOrder.splice(index, 1);
                row.remove();
                currentPage = 1;
                renderTable();
                alert(result.message || "Member deleted successfully.");
            } catch (error) {
                alert(error.message || "Could not delete this member.");
                button.disabled = false;
            }
        }

        async function sendMemberAction(payload) {
            const response = await fetch(window.location.pathname + window.location.search, {
                method: "POST",
                headers: { "Content-Type": "application/json", "Accept": "application/json" },
                body: JSON.stringify(payload),
                credentials: "same-origin"
            });
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error("The server returned an unreadable response. Confirm that this page is opened through XAMPP/PHP."); }
            if (!response.ok || !result.ok) throw new Error(result.message || "The request failed.");
            return result;
        }

        function showFieldError(inputId, errorId, message) {
            const input = document.getElementById(inputId);
            const error = document.getElementById(errorId);
            input.classList.toggle("invalid", Boolean(message));
            error.textContent = message || "";
            error.classList.toggle("show", Boolean(message));
        }

        function clearEditErrors() {
            showFieldError("editUsername", "usernameError", "");
            showFieldError("editPassword", "passwordError", "");
            showFieldError("confirmPassword", "confirmPasswordError", "");
            document.getElementById("updateSuccess").style.display = "none";
        }

        function openUpdate(button) {
            currentEditingRow = button.closest("tr");
            if (!currentEditingRow) return;

            const memberId = currentEditingRow.cells[0].textContent.trim();
            const memberName = currentEditingRow.cells[1].textContent.trim();
            const username = currentEditingRow.querySelector(".username-cell")?.textContent.trim() || "";

            document.getElementById("editingMemberLabel").textContent =
                "Member ID: " + memberId + " | Name: " + memberName;
            document.getElementById("editUsername").value = username;
            document.getElementById("editPassword").value = "";
            document.getElementById("confirmPassword").value = "";
            clearEditErrors();
            document.getElementById("editOverlay").style.display = "flex";
            document.getElementById("editUsername").focus();
        }

        function closeEdit() {
            document.getElementById("editOverlay").style.display = "none";
            currentEditingRow = null;
            clearEditErrors();
        }

        function closeEditOnOverlay(event) {
            if (event.target === document.getElementById("editOverlay")) closeEdit();
        }

        function validateEditUsername() {
            const input = document.getElementById("editUsername");
            const value = input.value.trim();
            let message = "";

            if (!value) {
                message = "Username is required.";
            } else if (value.length < 3 || value.length > 25) {
                message = "Username must be 3–25 characters long.";
            } else if (!/^[A-Za-z][A-Za-z0-9._]*$/.test(value)) {
                message = "Start with a letter; use only letters, numbers, dots, and underscores.";
            } else {
                const editingId = currentEditingRow?.cells[0]?.textContent.trim();
                const duplicate = getAllRows().some(row =>
                    row !== currentEditingRow &&
                    row.cells[2]?.textContent.trim().toLowerCase() === value.toLowerCase()
                );
                if (duplicate) message = "This username is already used by another member.";
            }

            showFieldError("editUsername", "usernameError", message);
            return !message;
        }

        function validateEditPassword() {
            const password = document.getElementById("editPassword").value;
            let message = "";

            // An empty password means "keep the existing password".
            if (password === "") {
                showFieldError("editPassword", "passwordError", "");
                return true;
            }

            if (password.length < 8 || password.length > 25) {
                message = "Password must be 8–25 characters long.";
            } else if (!/^[A-Z]/.test(password)) {
                message = "Password must begin with a capital letter.";
            } else if (!/[A-Za-z]/.test(password)) {
                message = "Password must contain at least one letter.";
            } else if (!/\d/.test(password)) {
                message = "Password must contain at least one number.";
            } else if (/\s/.test(password)) {
                message = "Password cannot contain spaces.";
            }

            showFieldError("editPassword", "passwordError", message);
            return !message;
        }

        function validateConfirmPassword() {
            const password = document.getElementById("editPassword").value;
            const confirmation = document.getElementById("confirmPassword").value;
            let message = "";

            if (password !== "" && confirmation === "") {
                message = "Please confirm the new password.";
            } else if (password !== confirmation) {
                message = "Passwords do not match.";
            }

            showFieldError("confirmPassword", "confirmPasswordError", message);
            return !message;
        }

        async function saveMemberUpdate() {
            if (!currentEditingRow) return;

            const usernameValid = validateEditUsername();
            const passwordValid = validateEditPassword();
            const confirmValid = validateConfirmPassword();
            if (!usernameValid || !passwordValid || !confirmValid) return;

            const memberId = currentEditingRow.dataset.memberId || currentEditingRow.cells[0].textContent.trim();
            const updatedUsername = document.getElementById("editUsername").value.trim();
            const newPassword = document.getElementById("editPassword").value;
            const success = document.getElementById("updateSuccess");
            const saveButton = document.querySelector(".save-update");
            success.style.color = "#24723a";
            success.textContent = "Saving changes...";
            success.style.display = "block";
            saveButton.disabled = true;

            try {
                const result = await sendMemberAction({
                    action: "update_member",
                    member_id: memberId,
                    username: updatedUsername,
                    password: newPassword
                });
                currentEditingRow.querySelector(".username-cell").textContent = result.username || updatedUsername;
                document.getElementById("editPassword").value = "";
                document.getElementById("confirmPassword").value = "";
                success.style.color = "#24723a";
                success.textContent = result.message || "Member credentials updated successfully.";
                renderTable();
                window.setTimeout(() => {
                    if (document.getElementById("editOverlay").style.display !== "none") closeEdit();
                }, 900);
            } catch (error) {
                success.style.color = "#d93025";
                success.textContent = error.message || "Could not save the changes.";
                success.style.display = "block";
            } finally {
                saveButton.disabled = false;
            }
        }

        document.getElementById("editUsername").addEventListener("input", validateEditUsername);
        document.getElementById("editPassword").addEventListener("input", () => {
            validateEditPassword();
            validateConfirmPassword();
        });
        document.getElementById("confirmPassword").addEventListener("input", validateConfirmPassword);

        searchInput.addEventListener("input", () => {
            const value = searchInput.value.trim();
            if (value.length > 100) {
                showSearchError("Search text cannot be more than 100 characters.");
                return;
            }
            clearSearchError();
            // Empty text immediately restores all rows under the current filter.
            currentPage = 1;
            renderTable();
        });

        searchInput.addEventListener("keydown", event => {
            if (event.key === "Enter") {
                event.preventDefault();
                searchMember();
            }
        });

        document.addEventListener("keydown", event => {
            if (event.key === "Escape") {
                closeFilter();
                closeEdit();
            }
        });

        // Supports rows supplied by server-side PHP or added to the tbody.
        originalOrder = getAllRows();
        renderTable();
    </script>
</body>
</html>
