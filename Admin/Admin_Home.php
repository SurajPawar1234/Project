<?php
/* Library Admin Dashboard - database-connected PHP page. */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');

$conn = null;
$connectionLoaded = false;
$connectionSearchDir = __DIR__;
for ($depth = 0; $depth < 6; $depth++) {
    $candidates = [
        $connectionSearchDir . DIRECTORY_SEPARATOR . 'Connection' . DIRECTORY_SEPARATOR . 'db.php',
        $connectionSearchDir . DIRECTORY_SEPARATOR . 'db.php',
    ];
    foreach (array_unique($candidates) as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        try {
            require_once $candidate;
        } catch (Throwable $e) {
            error_log('Admin dashboard could not load DB connection file: ' . $e->getMessage());
        }
        if (isset($conn) && $conn instanceof mysqli) {
            $connectionLoaded = true;
            break 2;
        }
    }
    $parentDir = dirname($connectionSearchDir);
    if ($parentDir === $connectionSearchDir) {
        break;
    }
    $connectionSearchDir = $parentDir;
}

function dashboard_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashboard_date($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '—';
    }
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? '—' : date('d/m/Y', $timestamp);
}

function dashboard_scalar(mysqli $connection, string $sql, bool &$queryError): ?float
{
    try {
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            $queryError = true;
            error_log('Admin dashboard metric query failed: ' . $connection->error);
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return $row && isset($row['value']) ? (float)$row['value'] : 0.0;
    } catch (Throwable $e) {
        $queryError = true;
        error_log('Admin dashboard metric query exception: ' . $e->getMessage());
        return null;
    }
}

$loggedInUsername = '';
foreach (['username', 'Username', 'loggedInUsername', 'admin_username'] as $sessionKey) {
    if (isset($_SESSION[$sessionKey]) && is_string($_SESSION[$sessionKey]) && trim($_SESSION[$sessionKey]) !== '') {
        $loggedInUsername = trim($_SESSION[$sessionKey]);
        break;
    }
}
if ($loggedInUsername === '') {
    $loggedInUsername = 'Admin';
}

$databaseWarning = false;
$queryError = false;
$totalBooks = null;
$issuedBooks = null;
$availableBooks = null;
$totalMembers = null;
$membersWithDueDate = null;
$totalFineToCollect = null;
$recentIssues = [];

if ($connectionLoaded && isset($conn) && $conn instanceof mysqli) {
    $totalBooks = dashboard_scalar($conn, 'SELECT COALESCE(SUM(COALESCE(`Quantity`, 0)), 0) AS `value` FROM `book`', $queryError);
    $issuedBooks = dashboard_scalar($conn, 'SELECT COUNT(*) AS `value` FROM `issue_return` WHERE `Return_date` IS NULL', $queryError);
    $totalMembers = dashboard_scalar($conn, 'SELECT COUNT(*) AS `value` FROM `member`', $queryError);
    $membersWithDueDate = dashboard_scalar(
        $conn,
        'SELECT COUNT(DISTINCT `Member_id`) AS `value` FROM `issue_return` WHERE `Return_date` IS NULL AND `Due_date` < CURDATE()',
        $queryError
    );
    $totalFineToCollect = dashboard_scalar(
        $conn,
        'SELECT COALESCE(SUM(COALESCE(`Fine`, 0)), 0) AS `value` FROM `issue_return` WHERE `Return_date` IS NULL',
        $queryError
    );
    if ($totalBooks !== null && $issuedBooks !== null) {
        $availableBooks = max(0, $totalBooks - $issuedBooks);
    }

    try {
        $recentResult = $conn->query(
            'SELECT `ir`.`Issue_id`, `ir`.`Issue_date`, `ir`.`Due_date`, '
            . 'TRIM(CONCAT(COALESCE(`m`.`First_name`, \'\'), \' \', COALESCE(`m`.`Last_name`, \'\'))) AS `MemberName`, '
            . 'COALESCE(NULLIF(`b`.`Title`, \'\'), \'—\') AS `BookName` '
            . 'FROM `issue_return` AS `ir` '
            . 'LEFT JOIN `member` AS `m` ON `m`.`Member_id` = `ir`.`Member_id` '
            . 'LEFT JOIN `book` AS `b` ON `b`.`Book_id` = `ir`.`Book_id` '
            . 'ORDER BY `ir`.`Issue_date` DESC, `ir`.`Issue_id` DESC LIMIT 3'
        );
        if ($recentResult instanceof mysqli_result) {
            while ($row = $recentResult->fetch_assoc()) {
                $recentIssues[] = $row;
            }
            $recentResult->free();
        } else {
            $queryError = true;
            error_log('Admin dashboard recent issue query failed: ' . $conn->error);
        }
    } catch (Throwable $e) {
        $queryError = true;
        error_log('Admin dashboard recent issue query exception: ' . $e->getMessage());
    }
    $databaseWarning = $queryError;
} else {
    $databaseWarning = true;
}

function dashboard_count_text(?float $value): string
{
    return $value === null ? '—' : number_format($value, 0, '.', ',');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Admin Home</title>

    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f2f2f2;
        }

        /* Left Sidebar: original width, colours and item sizing retained */
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

        /* This area scrolls if the expanded menu is taller than the screen. */
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

        /* Active page indicator without changing menu item dimensions. */
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

        /* Main Content: original margins and layout retained */
        .main {
            margin-left: 220px;
            /* Match the tighter top spacing in the reference screenshot. */
            padding: 10px 25px 25px;
        }

        /* Top Area */
        .top {
            background-color: white;
            padding: 20px;
            border: 1px solid #ddd;
            position: relative;
        }

        .top h1 {
            margin-top: 0;
            text-align: center;
        }

        .top h3 {
            text-align: right;
            margin-bottom: 5px;
        }

        /* Profile icon links to the existing profile page. */
        .profile-link {
            position: absolute;
            top: 12px;
            right: 30px;
            width: 48px;
            height: 48px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 1px solid #ccc;
            border-radius: 50%;
            background: #f2f2f2;
            color: #333;
            text-decoration: none;
        }

        .profile-link:hover {
            background: #e5e5e5;
        }

        .profile-link svg {
            width: 27px;
            height: 27px;
        }

        /* Login username displayed directly below the profile icon. */
        .profile-username {
            position: absolute;
            top: 64px;
            right: 4px;
            width: 100px;
            text-align: center;
            font-size: 12px;
            line-height: 16px;
            color: #555;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Dashboard */
        .dashboard {
            margin-top: 25px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .card {
            background-color: white;
            padding: 20px;
            border: 1px solid #ddd;
            text-align: center;
        }

        .card h3 {
            margin: 5px 0 15px;
            color: #555;
        }

        .card p {
            font-size: 28px;
            font-weight: bold;
            margin: 0;
            color: #222;
        }

        .fine {
            color: #b30000 !important;
        }

        /* Recent Activity */
        .recent {
            background-color: white;
            margin-top: 25px;
            padding: 20px;
            border: 1px solid #ddd;
        }

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

        @media (max-width: 800px) {
            .sidebar {
                width: 180px;
            }

            .sidebar-menu {
                max-height: calc(100vh - 135px);
            }

            .main {
                margin-left: 180px;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .top h1 {
                padding-right: 95px;
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

    <!-- Sidebar -->
    <div class="sidebar">
        <h2>Library</h2>

        <nav class="sidebar-menu" aria-label="Admin menu">
            <a href="admin_home.php" data-page="admin_home.php">Dashboard</a>
            <a href="my_profile.php" data-page="my_profile.php">My Profile</a>
            <a href="library_profile.php" data-page="library_profile.php">Library Profile</a>

            <div class="manage" id="manageToggle" role="button" tabindex="0"
                 aria-expanded="false" aria-controls="submenu">
                Manage Books
                <span class="arrow" id="arrow">+</span>
            </div>

            <div class="submenu" id="submenu">
                <a href="view_books.php" data-page="view_books.php">View All Books</a>
                <a href="add_book.php" data-page="add_book.php">Add Book</a>
                <a href="update_book.php" data-page="update_book.php">Update/Delete Book</a>
            </div>

            <a href="issue_book.php" data-page="issue_book.php">Issue Book</a>
            <a href="return_book.php" data-page="return_book.php">Return Book</a>
            <a href="members.php" data-page="members.php">Members Details</a>
            <a href="announcements.php" data-page="announcements.php">Announcements</a>
        </nav>

        <div class="logout">
            <a href="../login/login_form.php">Logout</a>
        </div>
    </div>

    <!-- Main Page -->
    <div class="main">
        <!-- Top Section -->
        <div class="top">
            <h1>Library Admin Dashboard</h1>
            <a class="profile-link" href="my_profile.php" aria-label="Open My Profile" title="My Profile">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M4.5 21a7.5 7.5 0 0 1 15 0"></path>
                </svg>
            </a>
            <span class="profile-username" id="loggedInUsername" title="Logged-in username"><?= dashboard_escape($loggedInUsername) ?></span>
        </div>

        <?php if ($databaseWarning): ?>
        <div role="status" style="margin-top:15px;padding:10px 12px;background:#fff;border:1px solid #ddd;color:#555;font-size:13px;">
            Dashboard data could not be fully loaded. Check your database connection and the book, member, and issue_return tables.
        </div>
        <?php endif; ?>

        <!-- Dashboard Details -->
        <div class="dashboard">
            <div class="cards">
                <div class="card">
                    <h3>Total Books</h3>
                    <p><?= dashboard_count_text($totalBooks) ?></p>
                </div>
                <div class="card">
                    <h3>Issued Books</h3>
                    <p><?= dashboard_count_text($issuedBooks) ?></p>
                </div>
                <div class="card">
                    <h3>Available Books</h3>
                    <p><?= dashboard_count_text($availableBooks) ?></p>
                </div>
                <div class="card">
                    <h3>Total Members</h3>
                    <p><?= dashboard_count_text($totalMembers) ?></p>
                </div>
                <div class="card">
                    <h3>Members With Due Date</h3>
                    <p><?= dashboard_count_text($membersWithDueDate) ?></p>
                </div>
                <div class="card">
                    <h3>Total Fine To Collect</h3>
                    <p class="fine"><?php if ($totalFineToCollect === null) { echo '—'; } else { echo dashboard_escape(number_format($totalFineToCollect, 2, '.', ',')); } ?></p>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="recent">
            <h2>Recent Book Issue Details</h2>
            <table>
                <thead>
                    <tr>
                        <th>Member Name</th>
                        <th>Book Name</th>
                        <th>Issue Date</th>
                        <th>Due Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recentIssues): ?>
                        <tr><td colspan="4" style="text-align:center;color:#666;">No issue records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentIssues as $issue): ?>
                            <tr>
                                <td><?= dashboard_escape(trim((string)($issue['MemberName'] ?? '')) !== '' ? $issue['MemberName'] : '—') ?></td>
                                <td><?= dashboard_escape($issue['BookName'] ?? '—') ?></td>
                                <td><?= dashboard_escape(dashboard_date($issue['Issue_date'] ?? null)) ?></td>
                                <td><?= dashboard_escape(dashboard_date($issue['Due_date'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
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

        // Highlight the current PHP page; compare filenames case-insensitively.
        const currentPage = window.location.pathname.split('/').pop().toLowerCase();
        const menuLinks = document.querySelectorAll('.sidebar-menu a[data-page]');
        let activeIsBookSubpage = false;

        menuLinks.forEach((link) => {
            const linkPage = link.dataset.page.toLowerCase();
            if (linkPage === currentPage) {
                link.classList.add('active');
                link.setAttribute('aria-current', 'page');
                if (submenu.contains(link)) {
                    activeIsBookSubpage = true;
                }
            }
        });

        if (activeIsBookSubpage) {
            toggleMenu(true);
        }
    </script>

</body>
</html>
