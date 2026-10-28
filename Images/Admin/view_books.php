<?php
/*
 * All Books page: loads book records from the existing `library` database.
 * The HTML/CSS and existing client-side search, filter, sort and pagination
 * have been retained. Year/location are shown when matching columns exist;
 * the uploaded schema does not define those columns, so they show as '-'.
 */
$dbErrorMessage = '';
$books = [];

function viewBooksEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    // Find Connection/db.php whether this page is in the project root or a subfolder.
    $connectionFile = null;
    $searchDirs = [
        __DIR__,
        dirname(__DIR__),
        dirname(dirname(__DIR__)),
        dirname(dirname(dirname(__DIR__)))
    ];
    foreach ($searchDirs as $searchDir) {
        $candidate = $searchDir . DIRECTORY_SEPARATOR . 'Connection' . DIRECTORY_SEPARATOR . 'db.php';
        if (is_file($candidate)) {
            $connectionFile = $candidate;
            break;
        }
    }

    if ($connectionFile === null) {
        throw new RuntimeException('Connection/db.php was not found relative to this page.');
    }

    require_once $connectionFile;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Connection/db.php must provide a MySQLi connection in $conn.');
    }

    $conn->set_charset('utf8mb4');

    // Identify optional columns without assuming they exist in every schema version.
    $columns = [];
    $columnResult = $conn->query('SHOW COLUMNS FROM `book`');
    while ($column = $columnResult->fetch_assoc()) {
        $columns[strtolower($column['Field'])] = $column['Field'];
    }
    $columnResult->free();

    $yearColumn = null;
    foreach (['release_year', 'year', 'publication_year'] as $candidate) {
        if (isset($columns[$candidate])) {
            $yearColumn = $columns[$candidate];
            break;
        }
    }

    $locationColumn = null;
    foreach (['shelf_no', 'book_location', 'location', 'location_no'] as $candidate) {
        if (isset($columns[strtolower($candidate)])) {
            $locationColumn = $columns[strtolower($candidate)];
            break;
        }
    }

    $yearExpression = $yearColumn !== null ? 'b.`' . str_replace('`', '``', $yearColumn) . '`' : 'NULL';
    $locationExpression = $locationColumn !== null ? 'b.`' . str_replace('`', '``', $locationColumn) . '`' : 'NULL';

    $sql = "SELECT
                b.`Book_id` AS book_id,
                b.`Title` AS title,
                b.`Author` AS author,
                b.`ISBN` AS isbn,
                b.`Category` AS category,
                b.`Publisher` AS publisher,
                {$yearExpression} AS book_year,
                b.`Quantity` AS quantity,
                b.`Status` AS status,
                {$locationExpression} AS book_location
            FROM `book` AS b
            ORDER BY b.`Book_id` ASC";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
    $result->free();
} catch (Throwable $e) {
    error_log('View Books page: ' . $e->getMessage());
    $dbErrorMessage = 'Unable to load books from the database. Check Connection/db.php and confirm that the library.book table exists.';
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>All Books</title>

    <style>

        body {
            margin: 0;
            font-family: Arial;
            background-color: #f2f2f2;
        }

        /* Sidebar - updated menu */

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

        /* Main */

        .main {
            margin-left: 220px;
            padding: 0 25px 25px 25px;
        }

        /* Page Header */

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
        }

        .top p {
            text-align: center;
            color: #666;
        }

        /* Search and Filter */

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

        .search input {
            width: 325px;
            padding: 12px;
            border: 1px solid #aaa;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }

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

        .search button:hover {
            background-color: #37434a;
        }

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

        .search-input-invalid {
            border: 2px solid red !important;
        }

        .filter-btn {
            background-color: #666 !important;
        }

        .filter-btn:hover {
            background-color: #555 !important;
        }

        /* Filter Popup */

        .filter-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .filter-popup {
            width: 620px;
            max-width: calc(100% - 40px);
            background: white;
            border-radius: 10px;
            padding: 32px;
            box-sizing: border-box;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.25);
        }

        .filter-popup h2 {
            margin: 0 0 28px 0;
            color: #465660;
            font-size: 30px;
        }

        .filter-group {
            margin-bottom: 22px;
        }

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

        .apply-filter {
            background-color: #465660;
            color: white;
        }

        .apply-filter:hover {
            background-color: #37434a;
        }

        .clear-filter {
            background-color: #ddd;
            color: #333;
        }

        .clear-filter:hover {
            background-color: #ccc;
        }

        @media (max-width: 800px) {

            .search {
                flex-wrap: wrap;
            }

            .search {
                flex-wrap: wrap;
            }

            .search input {
                width: min(100%, 325px);
            }

            .search button {
                padding: 11px 18px;
            }

            .search-validation {
                width: min(100%, 325px);
            }

            .filter-popup {
                padding: 24px;
            }

            .filter-popup h2 {
                font-size: 26px;
            }

            .filter-group label {
                font-size: 18px;
            }

            .filter-group select {
                font-size: 16px;
            }

            .filter-actions {
                flex-direction: column;
            }
        }

        /* Books */

        .books {
            background-color: white;
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #ddd;
        }

        .books h2 {
            color: #333;
            margin-top: 0;
        }

        /* Table area */

        .table-wrapper {
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

        th,
        td {
            padding: 12px;
            border: 1px solid #ccc;
            text-align: left;
            white-space: nowrap;
            height: 45px;
            box-sizing: border-box;
        }

        .empty-row td {
            color: transparent;
            background-color: white;
        }

        th {
            background-color: #eee;
            color: #333;
        }

        .available {
            color: green;
            font-weight: bold;
        }

        .issued {
            color: red;
            font-weight: bold;
        }

        .no-books-message {
            display: none;
            text-align: center;
            color: red;
            font-size: 17px;
            font-weight: bold;
            margin: 20px 0 5px;
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
            background-color: white;
            color: #333;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .pagination button:hover:not(:disabled) {
            background-color: #eee;
        }

        .pagination button.active {
            background-color: #465660;
            color: white;
            border-color: #465660;
        }

        .pagination button:disabled {
            color: #aaa;
            cursor: not-allowed;
            background-color: #f5f5f5;
        }

        /* Logout stays at the bottom of the sidebar */

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

        /* Mobile */

        @media (max-width: 800px) {

            .sidebar {
                width: 180px;
            }

            .logout {
                width: 180px;
            }

            .main {
                margin-left: 180px;
                padding: 0 15px 20px 15px;
            }

            .search input {
                width: min(100%, 325px);
            }

            .search button {
                padding: 11px 18px;
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

            <!-- Manage Books Menu -->
            <div class="manage" id="manageToggle" role="button" tabindex="0"
                 aria-expanded="false" aria-controls="submenu">
                Manage Books
                <span class="arrow" id="arrow">+</span>
            </div>

            <!-- Manage Books Submenu -->
            <div class="submenu" id="submenu">
                <a href="view_books.php" data-page="view_books.php">View All Books</a>
                <a href="add_book.php" data-page="add_book.php">Add Book</a>
                <a href="update_book.php" data-page="update_book.php">Update / Delete Book</a>
            </div>

            <a href="issue_book.php" data-page="issue_book.php">Issue Book</a>
            <a href="return_book.php" data-page="return_book.php">Return Book</a>
            <!-- Recent Returned Books is intentionally removed. -->
            <a href="members.php" data-page="members.php">Members Details</a>
            <a href="announcements.php" data-page="announcements.php">Announcements</a>
        </nav>

        <div class="logout">
            <a href="../login/login_form.php">Logout</a>
        </div>

    </div>

    <!-- Main Content -->

    <div class="main">

        <!-- Page Header -->

        <div class="top">

            <h1>All Books</h1>

            <p>
                View complete details of books in the library.
            </p>

            <!-- Search -->

            <div class="search-container">

                <div class="search">

                    <input
                        id="search"
                        type="text"
                        placeholder="Search by book ID, title or ISBN"
                    >

                    <button
                        type="button"
                        onclick="searchBook()"
                    >
                        Search
                    </button>

                    <button
                        type="button"
                        class="filter-btn"
                        onclick="openFilter()"
                    >
                        Filter
                    </button>

                </div>

                <div
                    id="searchValidation"
                    class="search-validation"
                ></div>

            </div>

        </div>

        <!-- Book Details -->

        <div class="books">

            <h2>Book Details</h2>

            <div class="table-wrapper">

                <table id="bookTable">

                    <tr>

                    <th>Book ID</th>

                    <th>Title</th>

                    <th>Author</th>

                    <th>ISBN</th>

                    <th>Category</th>

                    <th>Publisher</th>

                    <th>Year</th>

                    <th>Quantity</th>

                    <th>Status</th>

                    <th>Location</th>

                </tr>
                    <?php foreach ($books as $book): ?>
                    <?php
                        $statusText = trim((string)($book['status'] ?? ''));
                        $statusClass = strcasecmp($statusText, 'Available') === 0
                            ? 'available'
                            : (strcasecmp($statusText, 'Issued') === 0 ? 'issued' : '');
                        $yearText = ($book['book_year'] ?? '') !== '' && $book['book_year'] !== null
                            ? $book['book_year'] : '-';
                        $locationText = ($book['book_location'] ?? '') !== '' && $book['book_location'] !== null
                            ? $book['book_location'] : '-';
                    ?>
                    <tr>
                        <td><?= viewBooksEscape($book['book_id'] ?? '-') ?></td>
                        <td><?= viewBooksEscape($book['title'] ?? '-') ?></td>
                        <td><?= viewBooksEscape($book['author'] ?? '-') ?></td>
                        <td><?= viewBooksEscape($book['isbn'] ?? '-') ?></td>
                        <td><?= viewBooksEscape($book['category'] ?? '-') ?></td>
                        <td><?= viewBooksEscape($book['publisher'] ?? '-') ?></td>
                        <td><?= viewBooksEscape($yearText) ?></td>
                        <td><?= viewBooksEscape($book['quantity'] ?? '0') ?></td>
                        <td class="<?= viewBooksEscape($statusClass) ?>"><?= viewBooksEscape($statusText !== '' ? $statusText : '-') ?></td>
                        <td><?= viewBooksEscape($locationText) ?></td>
                    </tr>
                    <?php endforeach; ?>
</table>

            </div>

            <p
                id="noBooksMessage"
                class="no-books-message"
            >
                
            </p>

            <div
                id="pagination"
                class="pagination"
            ></div>


        </div>

    </div>

    <!-- Filter Popup -->

    <div
        class="filter-overlay"
        id="filterOverlay"
        onclick="closeFilterOnOverlay(event)"
    >

        <div
            class="filter-popup"
            onclick="event.stopPropagation()"
        >

            <h2>Filter Books</h2>

            <div class="filter-group">
                <label for="categoryFilter">Category</label>

                <select id="categoryFilter">
                    <option value="all">All Categories</option>
                    <option value="Fiction">Fiction</option>
                    <option value="Non-Fiction">Non-Fiction</option>
                    <option value="Science">Science</option>
                    <option value="Technology">Technology</option>
                    <option value="History">History</option>
                    <option value="Biography">Biography</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sortFilter">Sort By</label>

                <select id="sortFilter">
                    <option value="default">Default</option>
                    <option value="title_asc">Title A-Z</option>
                    <option value="title_desc">Title Z-A</option>
                    <option value="year_desc">Year Newest</option>
                    <option value="year_asc">Year Oldest</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="statusFilter">Status</label>

                <select id="statusFilter">
                    <option value="all">All Status</option>
                    <option value="Available">Available</option>
                    <option value="Issued">Issued</option>
                </select>
            </div>

            <div class="filter-actions">

                <button
                    type="button"
                    class="apply-filter"
                    onclick="applyFilter()"
                >
                    Apply Filter
                </button>

                <button
                    type="button"
                    class="clear-filter"
                    onclick="clearFilter()"
                >
                    Clear
                </button>

            </div>

        </div>

    </div>

    <!-- Toggle JavaScript -->

    <script>

        // Updated menu toggle, keyboard support, and active-page highlighting.
        const manageToggle = document.getElementById("manageToggle");
        const submenu = document.getElementById("submenu");
        const arrow = document.getElementById("arrow");

        function toggleMenu(forceOpen) {
            const shouldOpen = typeof forceOpen === "boolean"
                ? forceOpen
                : !submenu.classList.contains("open");

            submenu.classList.toggle("open", shouldOpen);
            manageToggle.setAttribute("aria-expanded", String(shouldOpen));
            arrow.textContent = shouldOpen ? "-" : "+";
        }

        manageToggle.addEventListener("click", () => toggleMenu());
        manageToggle.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                toggleMenu();
            }
        });

        const currentMenuPage = window.location.pathname.split("/").pop().toLowerCase();
        const menuLinks = document.querySelectorAll(".sidebar-menu a[data-page]");
        let activeIsBookSubpage = false;

        menuLinks.forEach((link) => {
            if (link.dataset.page.toLowerCase() === currentMenuPage) {
                link.classList.add("active");
                if (submenu.contains(link)) activeIsBookSubpage = true;
            }
        });

        if (activeIsBookSubpage) {
            toggleMenu(true);
            manageToggle.classList.add("active-parent");
        }

        function showSearchError(message) {

            var input =
                document.getElementById("search");

            var error =
                document.getElementById("searchValidation");

            error.textContent = message;
            error.style.display = "block";

            input.classList.add("search-input-invalid");

        }

        function clearSearchError() {

            var input =
                document.getElementById("search");

            var error =
                document.getElementById("searchValidation");

            error.textContent = "";
            error.style.display = "none";

            input.classList.remove("search-input-invalid");

        }

        function validateSearch() {

            var input =
                document.getElementById("search").value.trim();

            clearSearchError();

            /*
             * Empty search is allowed.
             */
            if (input === "") {
                return true;
            }

            /*
             * Only maximum length is validated.
             * Book titles and ISBNs may contain punctuation.
             */
            if (input.length > 100) {

                showSearchError(
                    "Search text cannot be more than 100 characters."
                );

                document.getElementById("search").focus();

                return false;
            }

            return true;

        }

        var currentPage = 1;
        var rowsPerPage = 8;

        function removeEmptyRows() {

            var emptyRows = document.querySelectorAll(
                "#bookTable tr.empty-row"
            );

            for (var i = 0; i < emptyRows.length; i++) {
                emptyRows[i].remove();
            }

        }

        function getBookRows() {

            return Array.from(
                document.querySelectorAll("#bookTable tr")
            ).filter(function(row) {
                return !row.classList.contains("empty-row");
            }).slice(1);

        }

        function addEmptyRows(displayedCount) {

            var table = document.getElementById("bookTable");
            var emptyCount = Math.max(0, rowsPerPage - displayedCount);

            for (var i = 0; i < emptyCount; i++) {

                var row = document.createElement("tr");
                row.className = "empty-row";

                for (var j = 0; j < 10; j++) {
                    var cell = document.createElement("td");
                    cell.innerHTML = "&nbsp;";
                    row.appendChild(cell);
                }

                table.appendChild(row);
            }

        }

        function searchBook() {

            if (!validateSearch()) {
                return;
            }

            var input =
                document.getElementById("search").value.trim().toLowerCase();

            var rows = getBookRows();

            currentPage = 1;

            for (var i = 0; i < rows.length; i++) {

                var cells = rows[i].children;

                var bookId =
                    (cells[0] ? cells[0].innerText : "")
                    .trim()
                    .toLowerCase();

                var title =
                    (cells[1] ? cells[1].innerText : "")
                    .trim()
                    .toLowerCase();

                var isbn =
                    (cells[3] ? cells[3].innerText : "")
                    .trim()
                    .toLowerCase();

                var matches =
                    input === "" ||
                    bookId.includes(input) ||
                    title.includes(input) ||
                    isbn.includes(input);

                rows[i].dataset.searchMatch =
                    matches ? "true" : "false";

            }

            renderTable();

            if (input === "") {
                hideNoBooksMessage();
            } else {
                updateNoBooksMessage(document.getElementById("search").value.trim());
            }

        }

        function handleSearchKey(event) {

            if (event.key === "Enter") {
                event.preventDefault();
                searchBook();
            }

        }

        function openFilter() {

            document.getElementById("filterOverlay").style.display = "flex";

        }

        function closeFilter() {

            document.getElementById("filterOverlay").style.display = "none";

        }

        function closeFilterOnOverlay(event) {

            if (event.target === document.getElementById("filterOverlay")) {
                closeFilter();
            }

        }

        function getCurrentSearchMatch(row) {

            if (row.dataset.searchMatch === undefined) {
                return true;
            }

            return row.dataset.searchMatch === "true";

        }

        function getCurrentFilterMatch(row) {

            var category =
                document.getElementById("categoryFilter").value;

            var status =
                document.getElementById("statusFilter").value;

            var categoryCell = row.children[4];
            var statusCell = row.children[8];

            var categoryText =
                categoryCell ? categoryCell.innerText.trim() : "";

            var statusText =
                statusCell ? statusCell.innerText.trim() : "";

            var categoryMatches =
                category === "all" ||
                categoryText === category;

            var statusMatches =
                status === "all" ||
                statusText === status;

            return getCurrentSearchMatch(row) &&
                   categoryMatches &&
                   statusMatches;

        }

        function updateRowVisibility(row) {

            row.dataset.filterMatch =
                getCurrentFilterMatch(row) ? "true" : "false";

        }

        function getFilteredRows() {

            var rows = getBookRows();
            var filtered = [];

            for (var i = 0; i < rows.length; i++) {

                updateRowVisibility(rows[i]);

                if (rows[i].dataset.filterMatch === "true") {
                    filtered.push(rows[i]);
                }

            }

            return filtered;

        }

        function renderTable() {

            removeEmptyRows();

            var rows = getBookRows();
            var filteredRows = getFilteredRows();
            var totalPages = Math.max(
                1,
                Math.ceil(filteredRows.length / rowsPerPage)
            );

            if (currentPage > totalPages) {
                currentPage = totalPages;
            }

            var start = (currentPage - 1) * rowsPerPage;
            var end = start + rowsPerPage;
            var displayedCount = Math.max(
                0,
                Math.min(end, filteredRows.length) - start
            );

            for (var i = 0; i < rows.length; i++) {
                rows[i].style.display = "none";
            }

            for (var j = start; j < end && j < filteredRows.length; j++) {
                filteredRows[j].style.display = "";
            }

            // Show only real matching book records; do not add blank placeholder rows.

            renderPagination(filteredRows.length);

        }

        function renderPagination(totalRows) {

            var pagination = document.getElementById("pagination");

            pagination.innerHTML = "";

            var totalPages = Math.ceil(totalRows / rowsPerPage);

            /*
             * Pagination is shown only when more than one page exists.
             * Each page contains exactly 8 book rows.
             */
            if (totalPages <= 1) {
                return;
            }

            var previousButton = document.createElement("button");
            previousButton.type = "button";
            previousButton.textContent = "Previous";
            previousButton.disabled = currentPage === 1;
            previousButton.onclick = function() {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            };
            pagination.appendChild(previousButton);

            for (let page = 1; page <= totalPages; page++) {
                let pageButton = document.createElement("button");
                pageButton.type = "button";
                pageButton.textContent = page;

                if (page === currentPage) {
                    pageButton.classList.add("active");
                }

                pageButton.onclick = function() {
                    currentPage = page;
                    renderTable();
                };

                pagination.appendChild(pageButton);
            }

            var nextButton = document.createElement("button");
            nextButton.type = "button";
            nextButton.textContent = "Next";
            nextButton.disabled = currentPage === totalPages;
            nextButton.onclick = function() {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            };
            pagination.appendChild(nextButton);

        }

        function updateNoBooksMessage(searchText = "") {

            var rows = getBookRows();
            var found = false;

            for (var i = 0; i < rows.length; i++) {
                if (getCurrentFilterMatch(rows[i])) {
                    found = true;
                    break;
                }
            }

            var message =
                document.getElementById("noBooksMessage");

            if (found) {
                message.style.display = "none";
                message.textContent = "";
                return;
            }

            if (searchText !== "") {

                message.textContent =
                    'No books found for "' +
                    searchText +
                    '". Please try another Book ID, Title, or ISBN.';

            } else {

                message.textContent =
                    "No books found matching the selected filters.";

            }

            message.style.display = "block";

        }

        function hideNoBooksMessage() {

            document.getElementById("noBooksMessage")
                .style.display = "none";

        }

        function validateFilters() {

            var category =
                document.getElementById("categoryFilter").value;

            var sort =
                document.getElementById("sortFilter").value;

            var status =
                document.getElementById("statusFilter").value;

            var validCategories = [
                "all",
                "Fiction",
                "Non-Fiction",
                "Science",
                "Technology",
                "History",
                "Biography"
            ];

            var validSorts = [
                "default",
                "title_asc",
                "title_desc",
                "year_desc",
                "year_asc"
            ];

            var validStatuses = [
                "all",
                "Available",
                "Issued"
            ];

            if (!validCategories.includes(category)) {
                alert("Please select a valid category.");
                return false;
            }

            if (!validSorts.includes(sort)) {
                alert("Please select a valid sorting option.");
                return false;
            }

            if (!validStatuses.includes(status)) {
                alert("Please select a valid status.");
                return false;
            }

            return true;

        }

        function applyFilter() {

            if (!validateFilters()) {
                return;
            }

            if (!validateSearch()) {
                return;
            }

            var sort =
                document.getElementById("sortFilter").value;

            sortRows(sort);

            currentPage = 1;
            renderTable();

            var currentSearch =
                document.getElementById("search").value.trim();

            updateNoBooksMessage(currentSearch);

            closeFilter();

        }

        function clearFilter() {

            document.getElementById("categoryFilter").value = "all";
            document.getElementById("sortFilter").value = "default";
            document.getElementById("statusFilter").value = "all";
            document.getElementById("search").value = "";

            var rows = getBookRows();

            for (var i = 0; i < rows.length; i++) {
                rows[i].dataset.searchMatch = "true";
                rows[i].dataset.filterMatch = "true";
            }

            currentPage = 1;
            renderTable();
            hideNoBooksMessage();
            closeFilter();

        }

        function sortRows(sortValue) {

            removeEmptyRows();

            var table =
                document.getElementById("bookTable");

            var rows =
                Array.from(table.querySelectorAll("tr:not(.empty-row)"));

            var header = rows.shift();

            if (sortValue === "default") {
                rows.forEach(function(row) {
                    table.appendChild(row);
                });
                table.insertBefore(header, table.firstChild);
                return;
            }

            rows.sort(function(a, b) {

                var titleA =
                    a.children[1]
                    ? a.children[1].innerText.trim().toLowerCase()
                    : "";

                var titleB =
                    b.children[1]
                    ? b.children[1].innerText.trim().toLowerCase()
                    : "";

                var yearA =
                    parseInt(
                        a.children[6]
                        ? a.children[6].innerText.trim()
                        : ""
                    ) || 0;

                var yearB =
                    parseInt(
                        b.children[6]
                        ? b.children[6].innerText.trim()
                        : ""
                    ) || 0;

                if (sortValue === "title_asc") {
                    return titleA.localeCompare(titleB);
                }

                if (sortValue === "title_desc") {
                    return titleB.localeCompare(titleA);
                }

                if (sortValue === "year_desc") {
                    return yearB - yearA;
                }

                if (sortValue === "year_asc") {
                    return yearA - yearB;
                }

                return 0;

            });

            table.innerHTML = "";
            table.appendChild(header);

            rows.forEach(function(row) {
                table.appendChild(row);
            });

        }

        document
            .getElementById("search")
            .addEventListener("input", function () {

                var value = this.value.trim();

                if (value === "") {
                    clearSearchError();
                    return;
                }

                if (value.length > 100) {
                    showSearchError(
                        "Search text cannot be more than 100 characters."
                    );
                    return;
                }

                clearSearchError();

            });

        document
            .getElementById("search")
            .addEventListener("keydown", function (event) {

                if (event.key === "Enter") {
                    event.preventDefault();
                    searchBook();
                }

            });

        window.BOOKS_LOAD_ERROR = <?php echo json_encode($dbErrorMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        if (window.BOOKS_LOAD_ERROR) {
            var dbMessage = document.getElementById("noBooksMessage");
            dbMessage.textContent = window.BOOKS_LOAD_ERROR;
            dbMessage.style.display = "block";
        } else if (getBookRows().length === 0) {
            var emptyMessage = document.getElementById("noBooksMessage");
            emptyMessage.textContent = "No books are currently available in the database.";
            emptyMessage.style.display = "block";
        } else {
            hideNoBooksMessage();
        }
        renderTable();

    </script>

</body>

</html>

