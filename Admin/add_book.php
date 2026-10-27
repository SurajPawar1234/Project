<?php
declare(strict_types=1);

/* Add Book page: database processing only; existing page design is retained. */
$conn = null;
$dbLoadError = '';
$pageAlert = '';

$connectionCandidates = [
    __DIR__ . '/Connection/db.php',
    __DIR__ . '/../Connection/db.php',
    __DIR__ . '/../../Connection/db.php',
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
];

$connectionFile = null;
foreach ($connectionCandidates as $candidate) {
    if (is_file($candidate)) {
        $connectionFile = $candidate;
        break;
    }
}

if ($connectionFile !== null) {
    try {
        require_once $connectionFile;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $dbLoadError = 'The database file did not provide a mysqli connection named $conn.';
        }
    } catch (Throwable $e) {
        $dbLoadError = 'Could not connect to the database. Check Connection/db.php.';
    }
} else {
    $dbLoadError = 'Connection/db.php was not found. Put this page in your project and check the connection path.';
}

if (isset($_GET['action']) && $_GET['action'] === 'next_book_id') {
    header('Content-Type: application/json; charset=utf-8');
    if (!($conn instanceof mysqli)) {
        http_response_code(500);
        echo json_encode(['error' => $dbLoadError !== '' ? $dbLoadError : 'Database connection is unavailable.']);
        exit;
    }

    try {
        $result = $conn->query('SELECT COALESCE(MAX(`Book_id`), 0) + 1 AS next_book_id FROM `book`');
        $row = $result->fetch_assoc();
        echo json_encode(['next_book_id' => (int)($row['next_book_id'] ?? 1)]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to retrieve the next Book ID. Check the book table.']);
    }
    exit;
}

function addBookLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function addBookColumnLimit(array $columns, string $columnName, int $fallback): int
{
    $column = $columns[strtolower($columnName)] ?? null;
    if (is_array($column) && preg_match('/varchar\((\d+)\)/i', (string)($column['Type'] ?? ''), $matches)) {
        return (int)$matches[1];
    }
    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!($conn instanceof mysqli)) {
        $pageAlert = $dbLoadError !== '' ? $dbLoadError : 'Database connection is unavailable.';
    } else {
        try {
            $columns = [];
            $columnResult = $conn->query('SHOW COLUMNS FROM `book`');
            while ($column = $columnResult->fetch_assoc()) {
                $columns[strtolower((string)$column['Field'])] = $column;
            }

            $requiredColumns = ['book_id', 'title', 'author', 'isbn', 'category', 'publisher', 'year', 'quantity', 'price', 'status', 'location'];
            $missingColumns = array_values(array_filter($requiredColumns, static function ($name) use ($columns) {
                return !array_key_exists($name, $columns);
            }));
            if ($missingColumns) {
                throw new RuntimeException('The book table is missing these required columns: ' . implode(', ', $missingColumns) . '.');
            }

            $bookIdRaw = trim((string)($_POST['Book_id'] ?? ''));
            $title = trim((string)($_POST['Title'] ?? ''));
            $author = trim((string)($_POST['Author'] ?? ''));
            $isbn = trim((string)($_POST['ISBN'] ?? ''));
            $categoryChoice = trim((string)($_POST['Category'] ?? ''));
            $otherCategory = trim((string)($_POST['Other_Category'] ?? ''));
            $category = $categoryChoice === 'Other' ? $otherCategory : $categoryChoice;
            $publisherInput = trim((string)($_POST['Publisher'] ?? ''));
            $yearRaw = trim((string)($_POST['Release_Year'] ?? ''));
            $quantityRaw = trim((string)($_POST['Quantity'] ?? ''));
            $priceRaw = trim((string)($_POST['Price'] ?? ''));
            $location = preg_replace('/\s+/', ' ', trim((string)($_POST['Book_Location'] ?? ''))) ?? '';

            $problems = [];

            if ($bookIdRaw === '' || !preg_match('/^[1-9]\d*$/', $bookIdRaw)) {
                $problems[] = 'Book ID must be a positive whole number.';
            } elseif ((float)$bookIdRaw > 2147483647) {
                $problems[] = 'Book ID is too large.';
            }

            if ($title === '') {
                $problems[] = 'Please enter book title.';
            } elseif (addBookLength($title) > 100) {
                $problems[] = 'Book title cannot exceed 100 characters.';
            }

            $authorLimit = addBookColumnLimit($columns, 'Author', 100);
            if ($author === '') {
                $problems[] = 'Please enter author name.';
            } elseif (addBookLength($author) < 2 || addBookLength($author) > $authorLimit) {
                $problems[] = 'Author name must be between 2 and ' . $authorLimit . ' characters, matching the database column.';
            } elseif (!preg_match("/^[A-Za-z][A-Za-z .'-]*$/", $author)) {
                $problems[] = 'Author name can contain only letters, spaces, apostrophe, dot and hyphen.';
            }

            if (!preg_match('/^\d{13}$/', $isbn)) {
                $problems[] = 'ISBN must contain exactly 13 digits.';
            }

            if ($category === '') {
                $problems[] = 'Please select a category.';
            } elseif ($categoryChoice === 'Other' && (addBookLength($category) < 2 || addBookLength($category) > 50 || !preg_match('/^[A-Za-z]+(?: [A-Za-z]+)*$/', $category))) {
                $problems[] = 'Other category must contain 2–50 letters and spaces only.';
            } elseif (addBookLength($category) > 50) {
                $problems[] = 'Category cannot exceed 50 characters.';
            }

            $publisher = $publisherInput === '' ? null : $publisherInput;
            if ($publisher !== null) {
                if (addBookLength($publisher) < 2 || addBookLength($publisher) > 100) {
                    $problems[] = 'Publisher name must be between 2 and 100 characters.';
                } elseif (!preg_match("/^[A-Za-z][A-Za-z &'.,()\\-]*$/", $publisher)) {
                    $problems[] = 'Publisher name contains unsupported characters.';
                }
            }

            $currentYear = (int)date('Y');
            if (!preg_match('/^\d{4}$/', $yearRaw) || (int)$yearRaw < 1000 || (int)$yearRaw > $currentYear) {
                $problems[] = 'Release year must be a four-digit year between 1000 and ' . $currentYear . '.';
            }

            if (!preg_match('/^[1-9]\d*$/', $quantityRaw) || (float)$quantityRaw > 2147483647) {
                $problems[] = 'Quantity must be a positive whole number.';
            }

            $priceType = strtolower((string)($columns['price']['Type'] ?? 'int'));
            $price = null;
            if ($priceRaw !== '') {
                if (!preg_match('/^\d+(\.\d+)?$/', $priceRaw)) {
                    $problems[] = 'Price must be a non-negative number and may contain decimals.';
                } elseif (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $priceType) && str_contains($priceRaw, '.')) {
                    $problems[] = 'Your database Price column is an integer type, so it cannot store decimal prices. Change Price to DECIMAL(10,2) to use decimal values.';
                } else {
                    $price = $priceRaw;
                }
            }

            if ($location === '') {
                $problems[] = 'Please enter book location.';
            } elseif (addBookLength($location) > 50) {
                $problems[] = 'Book location cannot exceed 50 characters.';
            } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 \/_-]*$/', $location)) {
                $problems[] = 'Book location can contain letters, numbers, spaces, /, _ and -.';
            }

            if ($problems) {
                throw new RuntimeException(implode("\n", $problems));
            }

            $bookId = (int)$bookIdRaw;
            $year = (int)$yearRaw;
            $quantity = (int)$quantityRaw;
            $status = 'Available';
            $priceIsInteger = (bool)preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $priceType);
            $priceForBinding = $price === null ? null : ($priceIsInteger ? (int)$price : (float)$price);
            $priceBindType = $priceIsInteger ? 'i' : 'd';

            $sql = 'INSERT INTO `book` (`Book_id`, `Title`, `Author`, `ISBN`, `Category`, `Publisher`, `Year`, `Quantity`, `Price`, `Status`, `Location`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $conn->prepare($sql);
            $types = 'isssssi' . 'i' . $priceBindType . 'ss';
            $stmt->bind_param($types, $bookId, $title, $author, $isbn, $category, $publisher, $year, $quantity, $priceForBinding, $status, $location);
            $stmt->execute();
            $stmt->close();

            $pageAlert = 'Book added successfully.';
        } catch (Throwable $e) {
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
                $pageAlert = 'This Book ID or another unique value already exists. Check the entered details and try again.';
            } else {
                $message = trim($e->getMessage());
                $pageAlert = $message !== '' ? 'Unable to add book: ' . $message : 'Unable to add book. Please check the database table and connection.';
            }
        }
    }
}
?>

<!DOCTYPE html>

<html>



<head>

    <title>Add Book</title>



    <style>

        body {

            margin: 0;

            font-family: Arial;

            background-color: #f2f2f2;

        }



        /* Sidebar - latest shared menu */

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

            padding: 25px;

        }



        /* Top */

        .top {

            background-color: white;

            padding: 20px;

            border: 1px solid #ddd;

        }



        .top h1 {

            text-align: center;

            margin: 0;

            color: #333;

        }



        .top p {

            text-align: center;

            color: #666;

        }



        /* View Books-style content cards */

        .top {

            background-color: white;

            padding: 20px;

            border: 1px solid #ddd;

            box-sizing: border-box;

        }



        .top h1 {

            margin: 0;

            text-align: center;

            color: #333;

        }



        .top p {

            text-align: center;

            color: #666;

            margin: 8px 0 0;

        }



        .books {

            background-color: white;

            margin-top: 25px;

            padding: 20px;

            border: 1px solid #ddd;

            box-sizing: border-box;

        }



        .books h2 {

            color: #333;

            margin-top: 0;

            margin-bottom: 20px;

        }



        /* Form Layout - 5 rows x 2 columns */

        .book-form-grid {

            display: grid;

            grid-template-columns: 1fr 1fr;

            column-gap: 24px;

            row-gap: 0;

            align-items: start;

        }



        .form-group {

            margin-bottom: 14px;

            min-width: 0;

        }



        .form-group label {

            display: block;

            margin-bottom: 6px;

            color: #333;

            font-weight: bold;

        }



        .form-group input,

        .form-group select,

        .form-group textarea {

            width: 100%;

            padding: 11px;

            border: 1px solid #aaa;

            border-radius: 3px;

            box-sizing: border-box;

            font-size: 15px;

            font-family: Arial;

        }



        .form-group input:focus,

        .form-group select:focus,

        .form-group textarea:focus {

            outline: none;

            border-color: #555;

        }



        .book-location-textarea {

            min-height: 40px;

            resize: none;

            height:40px;

        }



        .form-actions {

            grid-column: 1 / -1;

        }



        /* Category + Other input on the same line */

        .category-row {

            display: flex;

            align-items: flex-start;

            gap: 14px;

            width: 100%;

        }



        .category-row > select {

            width: 100%;

            min-width: 0;

            box-sizing: border-box;

        }



        .other-category {

            display: none;

            min-width: 0;

            margin: 0 !important;

            padding: 0;

        }



        .other-category input {

            width: 100%;

            box-sizing: border-box;

        }



        /* When Other is selected: exact same top line as Category.

           The first column is 42% and the remaining space goes to Other. */

        .category-row\.other-open {

            display: grid;

            grid-template-columns: 42% minmax(0, 1fr);

            gap: 14px;

            align-items: start;

        }



        .category-row\.other-open > select {

            width: 100%;

        }



        .category-row\.other-open .other-category {

            display: block;

            width: 100%;

            margin-top: 0 !important;

            align-self: start;

        }



        @media (max-width: 850px) {

            .book-form-grid {

                grid-template-columns: 1fr;

            }



            .form-actions {

                grid-column: 1;

            }



            .category-row,

            .category-row\.other-open {

                display: flex;

                flex-direction: column;

                gap: 10px;

            }



            .category-row > select,

            .category-row\.other-open > select,

            .category-row\.other-open .other-category {

                width: 100%;

            }

        }



        /* Validation */

        .error {

            display: block;

            color: red;

            font-size: 14px;

            margin-top: 5px;

            min-height: 0;

        }



        .required-star {

            color: red;

            font-weight: bold;

            margin-left: 3px;

        }



        .invalid {

            border: 2px solid red !important;

        }



        .valid {

            border: 2px solid green !important;

        }



        /* Book ID */

        #book_id {

            background-color: #eeeeee;

            cursor: not-allowed;

        }



        /* Buttons */

        .buttons {

            text-align: center;

            margin-top: 20px;

        }



        .buttons input {

            padding: 10px 25px;

            border: none;

            color: white;

            cursor: pointer;

        }



        .add {

            background-color: #333;

        }



        .clear {

            background-color: #777;

            margin-left: 10px;

        }



        .buttons input:hover {

            opacity: 0.8;

        }



        /* Logout */

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

    <!-- Main -->

    <div class="main">



        <!-- Page Header - same structure as View Books -->

        <div class="top">

            <h1>Add a Book</h1>

        </div>



        <!-- Book Details / Form Card -->

        <div class="books">

            <h2>Book Details</h2>

            <form action="add_book.php" method="post" id="addBookForm" novalidate>



                <div class="book-form-grid">



                <!-- Book ID -->

                <div class="form-group">

                    <label for="book_id">Book ID</label>



                    <input

                        type="text"

                        id="book_id"

                        name="Book_id"

                        placeholder="Retrieving book ID..."

                        readonly

                        required

                    >



                    <span class="error" id="bookIdError"></span>

                </div>



                <!-- Book Title -->

                <div class="form-group">

                    <label for="title">Book Title</label>



                    <input

                        type="text"

                        id="title"

                        name="Title"

                        placeholder="Enter book title"

                        maxlength="100"

                        required

                    >



                    <span class="error" id="titleError"></span>

                </div>



                <!-- Author -->

                <div class="form-group">

                    <label for="author">Author</label>



                    <input

                        type="text"

                        id="author"

                        name="Author"

                        placeholder="Enter author name"

                        maxlength="100"

                        required

                    >



                    <span class="error" id="authorError"></span>

                </div>



                <!-- ISBN -->

                <div class="form-group">

                    <label for="isbn">ISBN</label>



                    <input

                        type="text"

                        id="isbn"

                        name="ISBN"

                        placeholder="Enter 13 digit ISBN"

                        maxlength="13"

                        minlength="13"

                        inputmode="numeric"

                        autocomplete="off"

                        required

                    >



                    <span class="error" id="isbnError"></span>

                </div>



                <!-- Category -->

                <div class="form-group">

                    <label for="category">Category</label>



                    <div class="category-row" id="categoryRow">

                        <select

                            id="category"

                            name="Category"

                            required

                        >

                            <option value="" selected disabled>

                                Select Category

                            </option>

                            <option value="Adventure">Adventure</option>

                            <option value="Artificial Intelligence">Artificial Intelligence</option>

                            <option value="Autobiography">Autobiography</option>

                            <option value="Biography">Biography</option>

                            <option value="Business">Business</option>

                            <option value="Children">Children</option>

                            <option value="Computer Science">Computer Science</option>

                            <option value="Cooking">Cooking</option>

                            <option value="Cybersecurity">Cybersecurity</option>

                            <option value="Data Science">Data Science</option>

                            <option value="Database">Database</option>

                            <option value="Economics">Economics</option>

                            <option value="Education">Education</option>

                            <option value="Fantasy">Fantasy</option>

                            <option value="Finance">Finance</option>

                            <option value="Fiction">Fiction</option>

                            <option value="Health">Health</option>

                            <option value="History">History</option>

                            <option value="Horror">Horror</option>

                            <option value="Information Technology">Information Technology</option>

                            <option value="Law">Law</option>

                            <option value="Management">Management</option>

                            <option value="Mathematics">Mathematics</option>

                            <option value="Medicine">Medicine</option>

                            <option value="Mystery">Mystery</option>

                            <option value="Networking">Networking</option>

                            <option value="Non-Fiction">Non-Fiction</option>

                            <option value="Other">Other</option>

                            <option value="Philosophy">Philosophy</option>

                            <option value="Poetry">Poetry</option>

                            <option value="Politics">Politics</option>

                            <option value="Programming">Programming</option>

                            <option value="Psychology">Psychology</option>

                            <option value="Religion">Religion</option>

                            <option value="Romance">Romance</option>

                            <option value="Science">Science</option>

                            <option value="Science Fiction">Science Fiction</option>

                            <option value="Self-Help">Self-Help</option>

                            <option value="Software Engineering">Software Engineering</option>

                            <option value="Thriller">Thriller</option>

                            <option value="Travel">Travel</option>

                            <option value="Web Development">Web Development</option>

                        </select>



                        <div class="other-category" id="otherCategoryGroup">

                            <input

                                type="text"

                                id="otherCategory"

                                name="Other_Category"

                                placeholder="Enter new category"

                                maxlength="50"

                            >

                            <span class="error" id="otherCategoryError"></span>

                        </div>

                    </div>



                    <span class="error" id="categoryError"></span>

                </div>



                <!-- Publisher -->

                <div class="form-group">

                    <label for="publisher">Publisher</label>



                    <input

                        type="text"

                        id="publisher"

                        name="Publisher"

                        placeholder="Enter publisher name"

                        maxlength="100"

                    >



                    <span class="error" id="publisherError"></span>

                </div>



                <!-- Release Year -->

                <div class="form-group">

                    <label for="release_year">Release Year</label>



                    <input

                        type="text"

                        id="release_year"

                        name="Release_Year"

                        placeholder="Enter release year"

                        maxlength="4"

                        inputmode="numeric"

                        autocomplete="off"

                        required

                    >



                    <span class="error" id="releaseYearError"></span>

                </div>



                <!-- Quantity -->

                <div class="form-group">

                    <label for="quantity">Quantity</label>



                    <input

                        type="text"

                        id="quantity"

                        name="Quantity"

                        placeholder="Enter quantity"

                        inputmode="numeric"

                        maxlength="6"

                        required

                    >



                    <span class="error" id="quantityError"></span>

                </div>



                <!-- Price -->

                <div class="form-group">

                    <label for="price">Price</label>



                    <input

                        type="text"

                        id="price"

                        name="Price"

                        placeholder="Enter book price"

                        inputmode="decimal"

                        maxlength="15"

                    >



                    <span class="error" id="priceError"></span>

                </div>



                <!-- Book Location -->

                <div class="form-group">

                    <label for="book_location">Book Location</label>



                    <textarea

                        id="book_location"

                        name="Book_Location"

                        class="book-location-textarea"

                        rows="3"

                        maxlength="50"

                        placeholder="Example: Floor 2, Rack A, Shelf 3"

                    ></textarea>



                    <span class="error" id="bookLocationError"></span>

                </div>



                <!-- Buttons -->

                <div class="buttons form-actions">

                    <input

                        type="submit"

                        value="Add Book"

                        class="add"

                    >



                    <input

                        type="reset"

                        value="Clear"

                        class="clear"

                    >

                </div>



                </div>



            </form>

        </div>

    </div>



    <!-- Toggle Menu + Client-side Validation -->

    <script>

        // Sidebar submenu toggle, keyboard support, and current-page highlighting.
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

        const form = document.getElementById("addBookForm");



        const bookIdInput = document.getElementById("book_id");

        const titleInput = document.getElementById("title");

        const authorInput = document.getElementById("author");

        const isbnInput = document.getElementById("isbn");

        const categoryInput = document.getElementById("category");

        const otherCategoryGroup = document.getElementById("otherCategoryGroup");

        const categoryRow = document.getElementById("categoryRow");

        const otherCategoryInput = document.getElementById("otherCategory");

        const publisherInput = document.getElementById("publisher");

        const releaseYearInput = document.getElementById("release_year");

        const quantityInput = document.getElementById("quantity");

        const priceInput = document.getElementById("price");

        const bookLocationInput = document.getElementById("book_location");



        function setError(input, errorId, message) {

            document.getElementById(errorId).textContent = message;

            input.classList.add("invalid");

            input.classList.remove("valid");

        }



        function setSuccess(input, errorId) {

            document.getElementById(errorId).textContent = "";

            input.classList.remove("invalid");

            input.classList.add("valid");

            removeRequiredStar(input);

        }



        function clearFieldError(input, errorId) {

            document.getElementById(errorId).textContent = "";

            input.classList.remove("invalid");

            removeRequiredStar(input);

        }



        function getFieldLabel(input) {

            return document.querySelector(

                'label[for="' + input.id + '"]'

            );

        }



        function addRequiredStar(input) {

            const label = getFieldLabel(input);



            if (!label) {

                return;

            }



            if (!label.querySelector(".required-star")) {

                const star = document.createElement("span");

                star.className = "required-star";

                star.textContent = "*";

                label.appendChild(star);

            }

        }



        function removeRequiredStar(input) {

            const label = getFieldLabel(input);



            if (!label) {

                return;

            }



            const star = label.querySelector(".required-star");



            if (star) {

                star.remove();

            }

        }



        function setRequiredError(input, errorId, message) {

            /*

             * Empty required fields are NOT given a red border.

             * Instead, a red * is shown beside the label.

             */

            removeInvalidStateOnly(input);

            addRequiredStar(input);

            document.getElementById(errorId).textContent = message;

        }



        function removeInvalidStateOnly(input) {

            input.classList.remove("invalid");

            input.classList.remove("valid");

        }



        /*

         * BOOK ID

         *

         * This page contains no PHP/database code.

         * It requests the next ID from an existing endpoint.

         * Expected JSON response:

         * { "next_book_id": 10 }

         *

         * Create/use your existing endpoint at:

         * get_next_book_id.php

         */

        async function loadNextBookId() {

            bookIdInput.value = "Loading...";

            bookIdInput.readOnly = true;

            bookIdInput.dataset.loaded = "false";

            bookIdInput.dataset.manualAllowed = "false";



            try {

                const response = await fetch("add_book.php?action=next_book_id", {

                    method: "GET",

                    headers: {

                        "Accept": "application/json"

                    },

                    cache: "no-store"

                });



                if (!response.ok) {

                    throw new Error("Unable to retrieve Book ID.");

                }



                const data = await response.json();

                const nextId = Number(data.next_book_id);



                if (!Number.isInteger(nextId) || nextId < 1) {

                    throw new Error("Invalid Book ID received.");

                }



                bookIdInput.value = nextId;

                bookIdInput.readOnly = true;

                bookIdInput.dataset.loaded = "true";

                bookIdInput.dataset.manualAllowed = "false";



                clearFieldError(bookIdInput, "bookIdError");



                document.getElementById("bookIdError").textContent = "";



            } catch (error) {



                /*

                 * Database retrieval failed.

                 * Only now is manual Book ID entry enabled.

                 */

                bookIdInput.value = "";

                bookIdInput.readOnly = false;

                bookIdInput.placeholder = "Enter Book ID manually";

                bookIdInput.dataset.loaded = "false";

                bookIdInput.dataset.manualAllowed = "true";



                document.getElementById("bookIdError").textContent =

                    "Automatic Book ID retrieval failed. Enter the Book ID manually.";



                bookIdInput.classList.remove("invalid", "valid");

            }

        }



        bookIdInput.addEventListener("input", function() {



            /*

             * This listener matters only when manual entry has been

             * enabled because database retrieval failed.

             */

            if (bookIdInput.dataset.manualAllowed !== "true") {

                return;

            }



            this.value = this.value.replace(/\D/g, "");



            if (this.value !== "") {

                clearFieldError(

                    bookIdInput,

                    "bookIdError"

                );

            }



        });



        /* TITLE */

        function validateTitle() {

            const value = titleInput.value.trim();



            if (value === "") {

                setRequiredError(

                    titleInput,

                    "titleError",

                    "Please enter book title."

                );

                return false;

            }



            if (value.length > 100) {

                setError(titleInput, "titleError", "Book title cannot exceed 100 characters.");

                return false;

            }



            setSuccess(titleInput, "titleError");

            return true;

        }



        /* AUTHOR */

        function validateAuthor() {

            const value = authorInput.value.trim();



            if (value === "") {

                setRequiredError(

                    authorInput,

                    "authorError",

                    "Please enter author name."

                );

                return false;

            }



            if (value.length < 2 || value.length > 100) {

                setError(authorInput, "authorError", "Author name must be between 2 and 100 characters.");

                return false;

            }



            if (!/^[A-Za-z][A-Za-z .'-]*$/.test(value)) {

                setError(

                    authorInput,

                    "authorError",

                    "Author name can contain only letters, spaces, apostrophe, dot and hyphen."

                );

                return false;

            }



            setSuccess(authorInput, "authorError");

            return true;

        }



        /* ISBN - EXACTLY 13 DIGITS */

        function validateISBN() {

            const value = isbnInput.value.trim();



            if (value === "") {

                setRequiredError(

                    isbnInput,

                    "isbnError",

                    "Please enter ISBN."

                );

                return false;

            }



            if (!/^\d{13}$/.test(value)) {

                setError(isbnInput, "isbnError", "ISBN must contain exactly 13 digits.");

                return false;

            }



            setSuccess(isbnInput, "isbnError");

            return true;

        }



        function validateCategory() {

            const value = categoryInput.value;



            if (value === "") {

                setRequiredError(

                    categoryInput,

                    "categoryError",

                    "Please select a category."

                );

                return false;

            }



            if (value === "Other") {

                return validateOtherCategory();

            }



            setSuccess(categoryInput, "categoryError");

            clearFieldError(otherCategoryInput, "otherCategoryError");

            return true;

        }



        function validateOtherCategory() {

            const value = otherCategoryInput.value.trim();



            if (value === "") {

                setRequiredError(

                    otherCategoryInput,

                    "otherCategoryError",

                    "Please enter a category."

                );

                return false;

            }



            if (value.length < 2 || value.length > 50) {

                setError(

                    otherCategoryInput,

                    "otherCategoryError",

                    "Category must be between 2 and 50 characters."

                );

                return false;

            }



            /* Only letters and spaces. No numbers or special characters. */

            if (!/^[A-Za-z]+(?: [A-Za-z]+)*$/.test(value)) {

                setError(

                    otherCategoryInput,

                    "otherCategoryError",

                    "Category can contain only letters and spaces."

                );

                return false;

            }



            setSuccess(otherCategoryInput, "otherCategoryError");

            return true;

        }



        categoryInput.addEventListener("change", function() {

            const isOther = this.value === "Other";



            categoryRow.classList.toggle("other-open", isOther);

            otherCategoryGroup.style.display = isOther ? "block" : "none";



            if (isOther) {

                clearFieldError(categoryInput, "categoryError");

                otherCategoryInput.focus();

            } else {

                otherCategoryInput.value = "";

                clearFieldError(otherCategoryInput, "otherCategoryError");

                clearFieldError(categoryInput, "categoryError");

            }



            validateCategory();

        });



        /* PUBLISHER */

        function validatePublisher() {

            const value = publisherInput.value.trim();



            /* Publisher is optional in the existing form. */

            if (value === "") {

                clearFieldError(publisherInput, "publisherError");

                return true;

            }



            if (value.length < 2 || value.length > 100) {

                setError(

                    publisherInput,

                    "publisherError",

                    "Publisher name must be between 2 and 100 characters."

                );

                return false;

            }



            /*

             * Publisher names must contain letters.

             * Numbers are not allowed.

             * Common publisher-name punctuation is allowed:

             * spaces, &, apostrophe, dot, comma, hyphen,

             * and parentheses.

             */

            if (!/^[A-Za-z][A-Za-z &'.,()\-]*$/.test(value)) {

                setError(

                    publisherInput,

                    "publisherError",

                    "Publisher name can contain only letters, spaces, &, apostrophe, dot, comma, hyphen and parentheses."

                );

                return false;

            }



            setSuccess(publisherInput, "publisherError");

            return true;

        }



        /* RELEASE YEAR - 4 DIGITS, NOT IN THE FUTURE */

        function validateReleaseYear() {

            const value = releaseYearInput.value.trim();



            if (value === "") {

                setRequiredError(

                    releaseYearInput,

                    "releaseYearError",

                    "Please enter release year."

                );

                return false;

            }



            if (!/^\d{4}$/.test(value)) {

                setError(

                    releaseYearInput,

                    "releaseYearError",

                    "Release year must contain exactly 4 digits."

                );

                return false;

            }



            const year = Number(value);

            const currentYear = new Date().getFullYear();



            if (year < 1000 || year > currentYear) {

                setError(

                    releaseYearInput,

                    "releaseYearError",

                    "Release year must be between 1000 and " + currentYear + "."

                );

                return false;

            }



            setSuccess(releaseYearInput, "releaseYearError");

            return true;

        }





        /* QUANTITY - POSITIVE INTEGER ONLY */

        function validateQuantity() {

            const value = quantityInput.value.trim();



            if (value === "") {

                setRequiredError(

                    quantityInput,

                    "quantityError",

                    "Please enter quantity."

                );

                return false;

            }



            if (!/^[1-9]\d*$/.test(value)) {

                setError(

                    quantityInput,

                    "quantityError",

                    "Quantity must be a positive whole number only."

                );

                return false;

            }



            setSuccess(quantityInput, "quantityError");

            return true;

        }



        /* PRICE - NON-NEGATIVE INTEGER OR DECIMAL */

        function validatePrice() {

            const value = priceInput.value.trim();



            /* Price was optional in the original form. */

            if (value === "") {

                clearFieldError(priceInput, "priceError");

                return true;

            }



            if (!/^\d+(\.\d+)?$/.test(value)) {

                setError(

                    priceInput,

                    "priceError",

                    "Price must be a non-negative number and may contain decimals."

                );

                return false;

            }



            if (Number(value) < 0) {

                setError(priceInput, "priceError", "Price cannot be negative.");

                return false;

            }



            setSuccess(priceInput, "priceError");

            return true;

        }



        /* BOOK LOCATION - MAXIMUM 50 CHARACTERS */

        function validateBookLocation() {

            const value = bookLocationInput.value

                .replace(/\s+/g, " ")

                .trim();



            if (value === "") {

                setRequiredError(

                    bookLocationInput,

                    "bookLocationError",

                    "Please enter book location."

                );

                return false;

            }



            if (value.length > 50) {

                setError(bookLocationInput, "bookLocationError", "Book location cannot exceed 50 characters.");

                return false;

            }



            if (!/^[A-Za-z0-9][A-Za-z0-9 /_-]*$/.test(value)) {

                setError(

                    bookLocationInput,

                    "bookLocationError",

                    "Book location can contain letters, numbers, spaces, /, _ and -."

                );

                return false;

            }



            setSuccess(bookLocationInput, "bookLocationError");

            return true;

        }



        /* INPUT CLEANING */

        isbnInput.addEventListener("input", function() {

            this.value = this.value.replace(/\D/g, "").slice(0, 13);

            validateISBN();

        });



        releaseYearInput.addEventListener("input", function() {

            this.value = this.value.replace(/\D/g, "").slice(0, 4);

            validateReleaseYear();

        });





        quantityInput.addEventListener("input", function() {

            this.value = this.value.replace(/\D/g, "").replace(/^0+/, "");

            validateQuantity();

        });



        priceInput.addEventListener("input", function() {

            this.value = this.value.replace(/[^0-9.]/g, "");



            const firstDot = this.value.indexOf(".");

            if (firstDot !== -1) {

                this.value =

                    this.value.substring(0, firstDot + 1) +

                    this.value.substring(firstDot + 1).replace(/\./g, "");

            }



            validatePrice();

        });



        bookLocationInput.addEventListener("input", function() {

            if (this.value.length > 50) {

                this.value = this.value.slice(0, 50);

            }

            validateBookLocation();

        });



        [titleInput, authorInput, isbnInput, publisherInput, releaseYearInput, quantityInput, priceInput, bookLocationInput, otherCategoryInput].forEach(

            function(input) {

                input.addEventListener("input", function() {

                    removeRequiredStar(input);

                });

            }

        );



        categoryInput.addEventListener("change", function() {

            removeRequiredStar(categoryInput);

        });



        authorInput.addEventListener("input", validateAuthor);

        titleInput.addEventListener("input", validateTitle);

        publisherInput.addEventListener("input", validatePublisher);

        otherCategoryInput.addEventListener("input", validateOtherCategory);



        /* ENTER KEY: MOVE TO NEXT INPUT WITHOUT SUBMITTING */

        form.addEventListener("keydown", function(event) {



            if (event.key !== "Enter") {

                return;

            }



            const target = event.target;



            /*

             * Never allow Enter to submit the form.

             */

            if (target.tagName === "TEXTAREA") {

                return;

            }



            if (

                target.tagName === "BUTTON" ||

                (

                    target.tagName === "INPUT" &&

                    (

                        target.type === "submit" ||

                        target.type === "reset"

                    )

                )

            ) {

                event.preventDefault();

                return;

            }



            event.preventDefault();



            const fields = Array.from(

                form.querySelectorAll(

                    'input:not([type="hidden"]):not([type="submit"]):not([type="reset"]), select, textarea'

                )

            ).filter(function(field) {

                return !field.disabled &&

                       field.offsetParent !== null;

            });



            const currentIndex = fields.indexOf(target);



            if (currentIndex !== -1 &&

                currentIndex < fields.length - 1) {



                fields[currentIndex + 1].focus();



            } else {



                document.querySelector(

                    '#addBookForm input[type="submit"]'

                ).focus();



            }



        });





        /* SUBMIT */

        form.addEventListener("submit", function(event) {



            /*

             * Show * beside every required field that is empty.

             * This runs before individual validation so all missing

             * required fields are marked in the same submission.

             */

            const requiredFields = [

                titleInput,

                authorInput,

                isbnInput,

                categoryInput,

                releaseYearInput,

                quantityInput,

                bookLocationInput

            ];



            requiredFields.forEach(function(input) {

                const value =

                    input.tagName === "SELECT"

                    ? input.value

                    : input.value.trim();



                if (value === "") {

                    addRequiredStar(input);

                } else {

                    removeRequiredStar(input);

                }

            });



            if (

                categoryInput.value === "Other" &&

                otherCategoryInput.value.trim() === ""

            ) {

                addRequiredStar(otherCategoryInput);

            }



            let bookIdValid = true;



            /*

             * When the database provided the ID, it remains readonly

             * and is accepted as valid.

             *

             * Manual entry is permitted only when database retrieval

             * previously failed.

             */

            if (bookIdInput.dataset.loaded === "true") {



                bookIdValid =

                    /^\d+$/.test(bookIdInput.value.trim());



            } else if (

                bookIdInput.dataset.manualAllowed === "true"

            ) {



                const manualBookId =

                    bookIdInput.value.trim();



                if (manualBookId === "") {



                    bookIdValid = false;



                    bookIdInput.classList.add("invalid");



                    document.getElementById("bookIdError").textContent =

                        "Please enter the Book ID manually.";



                } else if (!/^\d+$/.test(manualBookId)) {



                    bookIdValid = false;



                    bookIdInput.classList.add("invalid");



                    document.getElementById("bookIdError").textContent =

                        "Book ID must contain numbers only.";



                } else if (Number(manualBookId) < 1) {



                    bookIdValid = false;



                    bookIdInput.classList.add("invalid");



                    document.getElementById("bookIdError").textContent =

                        "Book ID must be greater than 0.";



                } else {



                    clearFieldError(

                        bookIdInput,

                        "bookIdError"

                    );



                }



            } else {



                bookIdValid = false;



                document.getElementById("bookIdError").textContent =

                    "Book ID is not available.";

            }



            const titleValid = validateTitle();

            const authorValid = validateAuthor();

            const isbnValid = validateISBN();

            const categoryValid = validateCategory();

            const publisherValid = validatePublisher();

            const releaseYearValid = validateReleaseYear();

            const quantityValid = validateQuantity();

            const priceValid = validatePrice();

            const bookLocationValid = validateBookLocation();



            const valid =

                bookIdValid &&

                titleValid &&

                authorValid &&

                isbnValid &&

                categoryValid &&

                publisherValid &&

                releaseYearValid &&

                quantityValid &&

                priceValid &&

                bookLocationValid;



            if (!valid) {

                event.preventDefault();

                return;

            }



            /* PHP handles the validated POST and database insert. */

        });



        /* CLEAR BUTTON */

        form.addEventListener("reset", function() {

            setTimeout(function() {

                document.querySelectorAll(".error").forEach(function(error) {

                    error.textContent = "";

                });



                document.querySelectorAll("input, select").forEach(function(input) {

                    input.classList.remove("invalid", "valid");

                    removeRequiredStar(input);

                });



                otherCategoryGroup.style.display = "none";

                categoryRow.classList.remove("other-open");



                categoryInput.selectedIndex = 0;



                bookIdInput.placeholder = "Retrieving book ID...";



                /*

                 * After reset, try the database again.

                 * Manual entry is enabled only if retrieval fails.

                 */

                loadNextBookId();

            }, 0);

        });



        /* Load Book ID automatically when the page opens. */

        loadNextBookId();

    </script>




<?php if ($pageAlert !== ''): ?>
<script>
    alert(<?= json_encode($pageAlert, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
</script>
<?php endif; ?>

</body>



</html>
