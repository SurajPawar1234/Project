<?php
declare(strict_types=1);

$conn = null;
$dbLoadError = '';
$initialBooks = [];

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
        $dbLoadError = 'Could not connect to the library database. Check Connection/db.php and your MySQL settings.';
    }
} else {
    $dbLoadError = 'Connection/db.php was not found. Check the page location and connection path.';
}

function updateBookJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function updateBookNormalizeRecord(array $row): array
{
    return [
        'id' => (string)($row['Book_id'] ?? $row['id'] ?? ''),
        'title' => (string)($row['Title'] ?? $row['title'] ?? ''),
        'author' => (string)($row['Author'] ?? $row['author'] ?? ''),
        'isbn' => (string)($row['ISBN'] ?? $row['isbn'] ?? ''),
        'category' => (string)($row['Category'] ?? $row['category'] ?? ''),
        'publisher' => (string)($row['Publisher'] ?? $row['publisher'] ?? ''),
        'year' => ($row['Year'] ?? $row['year'] ?? '') === null ? '' : (string)($row['Year'] ?? $row['year'] ?? ''),
        'quantity' => (string)($row['Quantity'] ?? $row['quantity'] ?? ''),
        'price' => ($row['Price'] ?? $row['price'] ?? '') === null ? '' : (string)($row['Price'] ?? $row['price'] ?? ''),
        'location' => (string)($row['Location'] ?? $row['location'] ?? ''),
        'status' => (string)($row['Status'] ?? $row['status'] ?? 'Available'),
    ];
}

function updateBookLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function updateBookValidatedData(array $input): array
{
    $idRaw = trim((string)($input['id'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    $author = trim((string)($input['author'] ?? ''));
    $isbn = trim((string)($input['isbn'] ?? ''));
    $category = trim((string)($input['category'] ?? ''));
    $publisherRaw = trim((string)($input['publisher'] ?? ''));
    $yearRaw = trim((string)($input['year'] ?? ''));
    $quantityRaw = trim((string)($input['quantity'] ?? ''));
    $priceRaw = trim((string)($input['price'] ?? ''));
    $location = preg_replace('/\s+/', ' ', trim((string)($input['location'] ?? ''))) ?? '';

    $errors = [];
    if ($idRaw === '' || !preg_match('/^[1-9]\d*$/', $idRaw) || (float)$idRaw > 2147483647) {
        $errors[] = 'Invalid Book ID.';
    }
    if ($title === '') {
        $errors[] = 'Please enter book title.';
    } elseif (updateBookLength($title) > 100) {
        $errors[] = 'Book title cannot exceed 100 characters.';
    }
    if (updateBookLength($author) < 2 || updateBookLength($author) > 100) {
        $errors[] = 'Author name must be between 2 and 100 characters.';
    } elseif (!preg_match("/^[A-Za-z][A-Za-z .'-]*$/", $author)) {
        $errors[] = 'Author name can contain only letters, spaces, apostrophe, dot and hyphen.';
    }
    if (!preg_match('/^\d{13}$/', $isbn)) {
        $errors[] = 'ISBN must contain exactly 13 digits.';
    }
    if ($category === '') {
        $errors[] = 'Please select a category.';
    } elseif (updateBookLength($category) > 50) {
        $errors[] = 'Category cannot exceed 50 characters.';
    }
    $publisher = $publisherRaw === '' ? null : $publisherRaw;
    if ($publisher !== null) {
        if (updateBookLength($publisher) < 2 || updateBookLength($publisher) > 100) {
            $errors[] = 'Publisher name must be between 2 and 100 characters.';
        } elseif (!preg_match("/^[A-Za-z][A-Za-z &'.,()\\-]*$/", $publisher)) {
            $errors[] = 'Publisher name contains unsupported characters.';
        }
    }
    $currentYear = (int)date('Y');
    if (!preg_match('/^\d{4}$/', $yearRaw) || (int)$yearRaw < 1000 || (int)$yearRaw > $currentYear) {
        $errors[] = 'Release year must be a four-digit year between 1000 and ' . $currentYear . '.';
    }
    if (!preg_match('/^[1-9]\d*$/', $quantityRaw) || (float)$quantityRaw > 2147483647) {
        $errors[] = 'Quantity must be a positive whole number.';
    }
    $price = null;
    if ($priceRaw !== '') {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $priceRaw)) {
            $errors[] = 'Price must be a non-negative number with up to 2 decimal places.';
        } elseif ((float)$priceRaw > 99999999.99) {
            $errors[] = 'Price is too large for the DECIMAL(10,2) database column.';
        } else {
            $price = $priceRaw;
        }
    }
    if ($location === '') {
        $errors[] = 'Please enter book location.';
    } elseif (updateBookLength($location) > 50) {
        $errors[] = 'Book location cannot exceed 50 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ,\/_-]*$/', $location)) {
        $errors[] = 'Book location can contain letters, numbers, spaces, comma, /, _ and -.';
    }

    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    return [
        'id' => (int)$idRaw,
        'title' => $title,
        'author' => $author,
        'isbn' => $isbn,
        'category' => $category,
        'publisher' => $publisher,
        'year' => (int)$yearRaw,
        'quantity' => (int)$quantityRaw,
        'price' => $price === null ? null : (float)$price,
        'location' => $location,
    ];
}

/* AJAX actions for updating, deleting and undoing the last book action. */
$isJsonRequest = isset($_SERVER['CONTENT_TYPE']) && stripos((string)$_SERVER['CONTENT_TYPE'], 'application/json') !== false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $isJsonRequest) {
    if (!($conn instanceof mysqli)) {
        updateBookJsonResponse(['success' => false, 'message' => $dbLoadError ?: 'Database connection is unavailable.'], 500);
    }

    $requestData = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($requestData)) {
        updateBookJsonResponse(['success' => false, 'message' => 'Invalid request data.'], 400);
    }

    $action = (string)($requestData['action'] ?? '');
    try {
        if ($action === 'update') {
            $data = updateBookValidatedData(is_array($requestData['book'] ?? null) ? $requestData['book'] : []);
            $id = $data['id'];
            $check = $conn->prepare('SELECT `Book_id` FROM `book` WHERE `Book_id` = ?');
            $check->bind_param('i', $id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();
            if (!$exists) {
                updateBookJsonResponse(['success' => false, 'message' => 'This book record no longer exists. Refresh the page and try again.'], 404);
            }

            $stmt = $conn->prepare('UPDATE `book` SET `Title` = ?, `Author` = ?, `ISBN` = ?, `Category` = ?, `Publisher` = ?, `Year` = ?, `Quantity` = ?, `Price` = ?, `Location` = ? WHERE `Book_id` = ?');
            $title = $data['title'];
            $author = $data['author'];
            $isbn = $data['isbn'];
            $category = $data['category'];
            $publisher = $data['publisher'];
            $year = $data['year'];
            $quantity = $data['quantity'];
            $price = $data['price'];
            $location = $data['location'];
            $stmt->bind_param('sssssiidsi', $title, $author, $isbn, $category, $publisher, $year, $quantity, $price, $location, $id);
            $stmt->execute();
            $stmt->close();
            $read = $conn->prepare('SELECT `Book_id`, `Title`, `Author`, `ISBN`, `Category`, `Publisher`, `Year`, `Quantity`, `Price`, `Location`, `Status` FROM `book` WHERE `Book_id` = ?');
            $read->bind_param('i', $id);
            $read->execute();
            $row = $read->get_result()->fetch_assoc();
            $read->close();
            updateBookJsonResponse(['success' => true, 'message' => 'Book updated successfully.', 'book' => updateBookNormalizeRecord($row ?: [])]);
        }

        if ($action === 'delete') {
            $idRaw = trim((string)($requestData['id'] ?? ''));
            if (!preg_match('/^[1-9]\d*$/', $idRaw) || (float)$idRaw > 2147483647) {
                updateBookJsonResponse(['success' => false, 'message' => 'Invalid Book ID.'], 400);
            }
            $id = (int)$idRaw;
            $stmt = $conn->prepare('DELETE FROM `book` WHERE `Book_id` = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted < 1) {
                updateBookJsonResponse(['success' => false, 'message' => 'The book was not found or was already deleted.'], 404);
            }
            updateBookJsonResponse(['success' => true, 'message' => 'Book deleted successfully.']);
        }

        if ($action === 'restore') {
            $mode = (string)($requestData['mode'] ?? '');
            $input = is_array($requestData['book'] ?? null) ? $requestData['book'] : [];
            $record = updateBookNormalizeRecord($input);
            if (!preg_match('/^[1-9]\d*$/', $record['id']) || trim($record['title']) === '' || trim($record['author']) === '' || trim($record['isbn']) === '' || trim($record['category']) === '' || !preg_match('/^[1-9]\d*$/', $record['quantity'])) {
                updateBookJsonResponse(['success' => false, 'message' => 'Cannot undo because the saved book details are incomplete.'], 400);
            }
            $id = (int)$record['id'];
            $title = $record['title'];
            $author = $record['author'];
            $isbn = $record['isbn'];
            $category = $record['category'];
            $publisher = $record['publisher'] === '' ? null : $record['publisher'];
            $year = $record['year'] === '' ? null : (int)$record['year'];
            $quantity = (int)$record['quantity'];
            $price = $record['price'] === '' ? null : (float)$record['price'];
            $location = $record['location'] === '' ? null : $record['location'];
            $status = $record['status'] === '' ? 'Available' : $record['status'];

            if ($mode === 'update') {
                $stmt = $conn->prepare('UPDATE `book` SET `Title` = ?, `Author` = ?, `ISBN` = ?, `Category` = ?, `Publisher` = ?, `Year` = ?, `Quantity` = ?, `Price` = ?, `Status` = ?, `Location` = ? WHERE `Book_id` = ?');
                $stmt->bind_param('sssssiidssi', $title, $author, $isbn, $category, $publisher, $year, $quantity, $price, $status, $location, $id);
                $stmt->execute();
                $updated = $stmt->affected_rows;
                $stmt->close();
                if ($updated < 0) {
                    updateBookJsonResponse(['success' => false, 'message' => 'Could not undo the update.'], 500);
                }
            } elseif ($mode === 'delete') {
                $stmt = $conn->prepare('INSERT INTO `book` (`Book_id`, `Title`, `Author`, `ISBN`, `Category`, `Publisher`, `Year`, `Quantity`, `Price`, `Status`, `Location`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssssiidss', $id, $title, $author, $isbn, $category, $publisher, $year, $quantity, $price, $status, $location);
                $stmt->execute();
                $stmt->close();
            } else {
                updateBookJsonResponse(['success' => false, 'message' => 'Unknown undo action.'], 400);
            }
            updateBookJsonResponse(['success' => true, 'message' => 'Last action undone.']);
        }

        updateBookJsonResponse(['success' => false, 'message' => 'Unknown request action.'], 400);
    } catch (Throwable $e) {
        error_log('Update Book page error: ' . $e->getMessage());
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'The database operation failed. Check your table structure and any related records.';
        updateBookJsonResponse(['success' => false, 'message' => $message], 400);
    }
}

if ($conn instanceof mysqli) {
    try {
        $result = $conn->query('SELECT `Book_id`, `Title`, `Author`, `ISBN`, `Category`, `Publisher`, `Year`, `Quantity`, `Price`, `Location`, `Status` FROM `book` ORDER BY `Book_id` ASC');
        while ($row = $result->fetch_assoc()) {
            $initialBooks[] = updateBookNormalizeRecord($row);
        }
        $result->free();
    } catch (Throwable $e) {
        $dbLoadError = 'Unable to load book records from the book table. Check that the displayed column names match your database.';
        error_log('Update Book load error: ' . $e->getMessage());
    }
}

$booksJson = json_encode($initialBooks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($booksJson === false) {
    $booksJson = '[]';
}
?>

<!DOCTYPE html>

<html>

<head>

    <title>Update Book Record</title>

    <style>

        * { box-sizing: border-box; }

        body { margin: 0; font-family: Arial; background-color: #f2f2f2; }

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

        /* Only the selected destination link is highlighted; Manage Books is never active-highlighted. */
        .sidebar a.active {
            background-color: #484848;
            box-shadow: inset 4px 0 0 #f2f2f2;
        }

        .arrow { float: right; }

        .submenu { display: none; background-color: #444; }
        .submenu.open { display: block; }
        .submenu a { padding-left: 40px; font-size: 13px; }

        .logout { position: absolute; bottom: 10px; width: 100%; }
        .logout a { background: #222; text-align: center; }




        .main { margin-left:220px; padding:0 25px 25px; }

        .top { background:#fff; padding:20px; border:1px solid #ddd; margin-top:10px; }

        .top h1 { margin:0; text-align:center; color:#333; }

        .search-container { display:flex; flex-direction:column; align-items:center; margin-top:20px; }

        .search { display:flex; align-items:center; justify-content:center; gap:10px; width:100%; flex-wrap:nowrap; }

        .search input { width:325px; padding:12px; border:1px solid #aaa; border-radius:4px; font-size:16px; }

        .search button { padding:12px 22px; background:#465660; color:#fff; border:0; border-radius:4px; cursor:pointer; font-size:16px; white-space:nowrap; }

        .search button:hover { background:#37434a; }

        .filter-btn { background:#666 !important; }

        .filter-btn:hover { background:#555 !important; }

        .search-validation { display:none; width:325px; align-self:center; margin-top:6px; color:red; font-size:14px; line-height:1.4; }

        .search-input-invalid { border:2px solid red !important; }



        .books { background:#fff; margin-top:10px; padding:20px; border:1px solid #ddd; min-height:calc(100vh - 170px); }

        .books-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }

        .books h2 { margin:0; color:#333; }

        .undo-btn { padding:9px 18px; border:1px solid #aaa; background:#777; color:#fff; border-radius:4px; cursor:pointer; font-size:14px; }

        .undo-btn:hover { background:#666; }

        .table-wrapper { width:100%; max-width:100%; overflow-x:auto; overflow-y:hidden; }

        table { width:max-content; min-width:100%; border-collapse:collapse; }

        th,td { padding:12px; border:1px solid #ccc; text-align:left; white-space:nowrap; height:45px; }

        th { background:#eee; color:#333; }

        .action-buttons { display:flex; align-items:center; gap:8px; white-space:nowrap; }

        .update-btn,.delete-btn { border:0; color:#fff; padding:8px 15px; cursor:pointer; border-radius:3px; }

        .update-btn { background:#333; }

        .update-btn:hover { background:#555; }

        .delete-btn { background:#c0392b; }

        .delete-btn:hover { background:#a93226; }

        .empty-state { display:block; text-align:center; color:#666; font-size:15px; padding:22px 10px; border:1px solid #ddd; border-top:0; }

        .no-books-message { display:none; text-align:center; color:red; font-size:17px; font-weight:bold; margin:20px 0 5px; }

        .pagination { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:18px; min-height:38px; flex-wrap:wrap; }

        .pagination button { min-width:38px; padding:9px 12px; border:1px solid #ccc; background:#fff; color:#333; border-radius:4px; cursor:pointer; font-size:14px; }

        .pagination button:hover:not(:disabled) { background:#eee; }

        .pagination button.active { background:#465660; color:#fff; border-color:#465660; }

        .pagination button:disabled { color:#aaa; cursor:not-allowed; background:#f5f5f5; }



        .filter-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); align-items:center; justify-content:center; z-index:900; }

        .filter-popup { width:620px; max-width:calc(100% - 40px); background:#fff; border-radius:10px; padding:32px; box-shadow:0 10px 35px rgba(0,0,0,.25); }

        .filter-popup h2 { margin:0 0 28px; color:#465660; font-size:30px; }

        .filter-group { margin-bottom:22px; }

        .filter-group label { display:block; font-size:20px; font-weight:bold; margin-bottom:10px; color:#333; }

        .filter-group select { width:100%; padding:14px 18px; font-size:18px; border:1px solid #ccc; border-radius:6px; background:#fff; }

        .filter-actions { display:flex; gap:15px; margin-top:28px; }

        .filter-actions button { flex:1; padding:14px; border:0; border-radius:6px; font-size:18px; cursor:pointer; }

        .apply-filter { background:#465660; color:#fff; }

        .clear-filter { background:#ddd; color:#333; }



        .modal { display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.55); align-items:center; justify-content:center; padding:20px; }

        .modal.show { display:flex; }

        .modal-box { width:950px; max-width:96%; max-height:92vh; overflow-y:auto; background:#fff; padding:25px 30px 30px; border:1px solid #ddd; }

        .modal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid #ddd; }

        .modal-header h2 { margin:0; color:#333; }

        .close-btn { border:0; background:transparent; font-size:28px; color:#555; cursor:pointer; line-height:1; }

        .book-form-grid { display:grid; grid-template-columns:1fr 1fr; column-gap:24px; row-gap:0; align-items:start; }

        .form-group { margin-bottom:14px; min-width:0; }

        .form-group label { display:block; margin-bottom:6px; color:#333; font-weight:bold; }

        .form-group input,.form-group select,.form-group textarea { width:100%; padding:11px; border:1px solid #aaa; border-radius:3px; font-size:15px; font-family:Arial; }

        .form-group input:focus,.form-group select:focus,.form-group textarea:focus { outline:none; border-color:#555; }

        .form-group input[readonly] { background:#eee; }

        .book-location-textarea { height:60px; min-height:60px; resize:vertical; }

        .category-row { display:flex; align-items:flex-start; gap:14px; width:100%; }

        .category-row > select { width:100%; min-width:0; }

        .category-row.other-open { display:grid; grid-template-columns:42% minmax(0,1fr); gap:14px; align-items:start; }

        .other-category { display:none; min-width:0; margin:0 !important; padding:0; }

        .other-category input { width:100%; }

        .category-row.other-open .other-category { display:block; width:100%; margin-top:0 !important; }

        .error { display:block; color:red; font-size:14px; margin-top:5px; line-height:1.3; }

        .required-star { color:red; font-weight:bold; margin-left:3px; }

        .invalid { border:2px solid red !important; }

        .valid { border:2px solid green !important; }

        .form-buttons { grid-column:1/-1; text-align:center; margin-top:20px; }

        .save-btn,.cancel-btn { color:#fff; padding:10px 22px; border:0; cursor:pointer; }

        .save-btn { background:#333; }

        .cancel-btn { background:#777; margin-left:10px; }



        @media(max-width:900px){ .main{margin-left:220px;padding:0 15px 20px;} .search{flex-wrap:wrap;} }

        @media(max-width:850px){ .modal-box{padding:20px;} .book-form-grid{grid-template-columns:1fr;} .form-buttons{grid-column:1;} .category-row,.category-row.other-open{display:flex;flex-direction:column;gap:10px;} .category-row>select,.category-row.other-open>select,.category-row.other-open .other-category{width:100%;} }

        @media(max-width:800px){ .filter-popup{padding:24px;} .filter-popup h2{font-size:26px;} .filter-group label{font-size:18px;} .filter-group select{font-size:16px;} .filter-actions{flex-direction:column;} }

    </style>

</head>

<body>



    <div class="sidebar">
        <h2>Library</h2>

        <nav class="sidebar-menu" aria-label="Admin menu">
            <a href="admin_home.html" data-page="admin_home.html">Dashboard</a>
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
                <a href="update_book.php" data-page="update_book.php">Update / Delete Book</a>
            </div>

            <a href="issue_book.html" data-page="issue_book.html">Issue Book</a>
            <a href="return_book.html" data-page="return_book.html">Return Book</a>
            <a href="members.html" data-page="members.html">Members Details</a>
            <a href="announcement.html" data-page="announcement.html">Announcements</a>
        </nav>

        <div class="logout">
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="main">
        <?php if ($dbLoadError !== ''): ?>
            <div role="alert" style="margin: 10px 0; padding: 12px 15px; color: #8a1f11; background: #fff0ee; border: 1px solid #e0a39b; border-radius: 4px;">
                <?php echo htmlspecialchars($dbLoadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="top">

            <h1>Update Book Record</h1>

            <div class="search-container">

                <div class="search">

                    <input type="text" id="searchInput" placeholder="Search by Book ID, Title or ISBN" maxlength="100" autocomplete="off">

                    <button type="button" onclick="searchBooks()">Search</button>

                    <button type="button" class="filter-btn" onclick="openFilter()">Filter</button>

                </div>

                <div id="searchValidation" class="search-validation"></div>

            </div>

        </div>



        <div class="books">

            <div class="books-header">

                <h2>Book Details</h2>

                <button type="button" class="undo-btn" id="undoBtn" onclick="undoLastAction()" disabled>Undo</button>

            </div>

            <div class="table-wrapper">

                <table id="bookTable">

                    <thead><tr><th>Book ID</th><th>Book Title</th><th>Author</th><th>ISBN</th><th>Category</th><th>Publisher</th><th>Release Year</th><th>Quantity</th><th>Price</th><th>Location</th><th>Action</th></tr></thead>

                    <tbody id="bookTableBody"></tbody>

                </table>

            </div>

            <div id="emptyState" class="empty-state">Search for a Book ID, Title or ISBN to display matching records.</div>

            <p id="noBooksMessage" class="no-books-message"></p>

            <div id="pagination" class="pagination"></div>

        </div>

    </div>



    <div class="filter-overlay" id="filterOverlay" onclick="closeFilterOnOverlay(event)">

        <div class="filter-popup" onclick="event.stopPropagation()">

            <h2>Filter Books</h2>

            <div class="filter-group"><label for="categoryFilter">Category</label><select id="categoryFilter">

                <option value="all">All Categories</option>

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

            </select></div>

            <div class="filter-group"><label for="sortFilter">Sort By</label><select id="sortFilter">

                <option value="default">Default</option>

                <option value="id_asc">Book ID Ascending</option>

                <option value="id_desc">Book ID Descending</option>

                <option value="title_asc">Title A-Z</option>

                <option value="title_desc">Title Z-A</option>

                <option value="year_desc">Release Year Newest</option>

                <option value="year_asc">Release Year Oldest</option>

            </select></div>

            <div class="filter-group"><label for="statusFilter">Status</label><select id="statusFilter"><option value="all">All Status</option><option value="Available">Available</option><option value="Issued">Issued</option></select></div>

            <div class="filter-actions"><button type="button" class="apply-filter" onclick="applyFilter()">Apply Filter</button><button type="button" class="clear-filter" onclick="clearFilter()">Clear</button></div>

        </div>

    </div>



    <div class="modal" id="updateModal" onclick="closeFormOnOverlay(event)">

        <div class="modal-box" onclick="event.stopPropagation()">

            <div class="modal-header"><h2>Update Book</h2><button type="button" class="close-btn" onclick="closeForm()">×</button></div>

            <form id="updateBookForm" novalidate>

                <div class="book-form-grid">

                    <div class="form-group"><label for="editBookId">Book ID</label><input type="text" id="editBookId" readonly><span class="error" id="editBookIdError"></span></div>

                    <div class="form-group"><label for="editTitle">Book Title</label><input type="text" id="editTitle" maxlength="100" placeholder="Enter book title"><span class="error" id="editTitleError"></span></div>

                    <div class="form-group"><label for="editAuthor">Author</label><input type="text" id="editAuthor" maxlength="100" placeholder="Enter author name"><span class="error" id="editAuthorError"></span></div>

                    <div class="form-group"><label for="editISBN">ISBN</label><input type="text" id="editISBN" maxlength="13" inputmode="numeric" autocomplete="off" placeholder="Enter 13 digit ISBN"><span class="error" id="editISBNError"></span></div>

                    <div class="form-group"><label for="editCategory">Category</label><div class="category-row" id="editCategoryRow"><select id="editCategory"><option value="" selected disabled>Select Category</option><option value="Adventure">Adventure</option><option value="Artificial Intelligence">Artificial Intelligence</option><option value="Autobiography">Autobiography</option><option value="Biography">Biography</option><option value="Business">Business</option><option value="Children">Children</option><option value="Computer Science">Computer Science</option><option value="Cooking">Cooking</option><option value="Cybersecurity">Cybersecurity</option><option value="Data Science">Data Science</option><option value="Database">Database</option><option value="Economics">Economics</option><option value="Education">Education</option><option value="Fantasy">Fantasy</option><option value="Finance">Finance</option><option value="Fiction">Fiction</option><option value="Health">Health</option><option value="History">History</option><option value="Horror">Horror</option><option value="Information Technology">Information Technology</option><option value="Law">Law</option><option value="Management">Management</option><option value="Mathematics">Mathematics</option><option value="Medicine">Medicine</option><option value="Mystery">Mystery</option><option value="Networking">Networking</option><option value="Non-Fiction">Non-Fiction</option><option value="Other">Other</option><option value="Philosophy">Philosophy</option><option value="Poetry">Poetry</option><option value="Politics">Politics</option><option value="Programming">Programming</option><option value="Psychology">Psychology</option><option value="Religion">Religion</option><option value="Romance">Romance</option><option value="Science">Science</option><option value="Science Fiction">Science Fiction</option><option value="Self-Help">Self-Help</option><option value="Software Engineering">Software Engineering</option><option value="Thriller">Thriller</option><option value="Travel">Travel</option><option value="Web Development">Web Development</option></select><div class="other-category" id="editOtherCategoryGroup"><input type="text" id="editOtherCategory" maxlength="50" placeholder="Enter new category"><span class="error" id="editOtherCategoryError"></span></div></div><span class="error" id="editCategoryError"></span></div>

                    <div class="form-group"><label for="editPublisher">Publisher</label><input type="text" id="editPublisher" maxlength="100" placeholder="Enter publisher name"><span class="error" id="editPublisherError"></span></div>

                    <div class="form-group"><label for="editReleaseYear">Release Year</label><input type="text" id="editReleaseYear" maxlength="4" inputmode="numeric" autocomplete="off" placeholder="Enter release year"><span class="error" id="editReleaseYearError"></span></div>

                    <div class="form-group"><label for="editQuantity">Quantity</label><input type="text" id="editQuantity" maxlength="6" inputmode="numeric" placeholder="Enter quantity"><span class="error" id="editQuantityError"></span></div>

                    <div class="form-group"><label for="editPrice">Price</label><input type="text" id="editPrice" maxlength="15" inputmode="decimal" placeholder="Enter book price"><span class="error" id="editPriceError"></span></div>

                    <div class="form-group"><label for="editLocation">Book Location</label><textarea id="editLocation" class="book-location-textarea" rows="2" maxlength="50" placeholder="Example: Floor 2, Rack A, Shelf 3"></textarea><span class="error" id="editLocationError"></span></div>

                    <div class="form-buttons"><button type="submit" class="save-btn">Update Book</button><button type="button" class="cancel-btn" onclick="closeForm()">Cancel</button></div>

                </div>

            </form>

        </div>

    </div>



    <script>

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

        // Expand the submenu for submenu destinations, but never highlight Manage Books itself.
        if (activeIsBookSubpage) toggleMenu(true);



        /* Records are loaded by PHP from the library.book table. */

        var bookRecords = <?php echo $booksJson; ?>;

        var currentResults=[],currentPage=1,rowsPerPage=8,selectedRecord=null,undoSnapshot=null;



        function showSearchError(m){var i=document.getElementById('searchInput'),e=document.getElementById('searchValidation');e.textContent=m;e.style.display='block';i.classList.add('search-input-invalid');}

        function clearSearchError(){var i=document.getElementById('searchInput'),e=document.getElementById('searchValidation');e.textContent='';e.style.display='none';i.classList.remove('search-input-invalid');}

        function validateSearch(){

            var v=document.getElementById('searchInput').value.trim();

            clearSearchError();

            if(v==='') return true;

            if(v.length>100){

                showSearchError('Search text cannot be more than 100 characters.');

                document.getElementById('searchInput').focus();

                return false;

            }

            if(/^\d+$/.test(v) && /^(978|979)/.test(v) && v.length!==13){

                showSearchError('ISBN must contain exactly 13 digits.');

                document.getElementById('searchInput').focus();

                return false;

            }

            return true;

        }

        function getFilters(){return{category:document.getElementById('categoryFilter').value,sort:document.getElementById('sortFilter').value,status:document.getElementById('statusFilter').value};}

        function validateFilters(){

            var f=getFilters();

            var c=['all','Adventure','Artificial Intelligence','Autobiography','Biography','Business','Children','Computer Science','Cooking','Cybersecurity','Data Science','Database','Economics','Education','Fantasy','Finance','Fiction','Health','History','Horror','Information Technology','Law','Management','Mathematics','Medicine','Mystery','Networking','Non-Fiction','Other','Philosophy','Poetry','Politics','Programming','Psychology','Religion','Romance','Science','Science Fiction','Self-Help','Software Engineering','Thriller','Travel','Web Development'];

            var s=['default','id_asc','id_desc','title_asc','title_desc','year_desc','year_asc'];

            var st=['all','Available','Issued'];

            if(!c.includes(f.category)){alert('Please select a valid category.');return false;}

            if(!s.includes(f.sort)){alert('Please select a valid sorting option.');return false;}

            if(!st.includes(f.status)){alert('Please select a valid status.');return false;}

            return true;

        }

        function getSearchMatches(){

            var q=document.getElementById('searchInput').value.trim();

            if(q==='') return bookRecords.slice();

            var lower=q.toLowerCase();

            if(/^\d+$/.test(q)){

                if(q.length===13) return bookRecords.filter(function(b){return b.isbn===q;});

                return bookRecords.filter(function(b){return b.id===q;});

            }

            return bookRecords.filter(function(b){return b.title.toLowerCase().includes(lower);});

        }

        function applyFiltersToResults(results){

            var f=getFilters(),o=results.filter(function(b){

                var cm=f.category==='all'||b.category===f.category;

                var sm=f.status==='all'||b.status===f.status;

                return cm&&sm;

            });

            o.sort(function(a,b){

                if(f.sort==='id_asc')return Number(a.id)-Number(b.id);

                if(f.sort==='id_desc')return Number(b.id)-Number(a.id);

                if(f.sort==='title_asc')return a.title.localeCompare(b.title);

                if(f.sort==='title_desc')return b.title.localeCompare(a.title);

                if(f.sort==='year_desc')return Number(b.year)-Number(a.year);

                if(f.sort==='year_asc')return Number(a.year)-Number(b.year);

                return Number(a.id)-Number(b.id);

            });

            return o;

        }

        function escapeHtml(v){return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}



        function renderResults() {
            var tbody = document.getElementById('bookTableBody');
            var emptyState = document.getElementById('emptyState');
            var message = document.getElementById('noBooksMessage');
            tbody.innerHTML = '';
            document.getElementById('pagination').innerHTML = '';

            if (!currentResults.length) {
                emptyState.style.display = 'none';
                message.style.display = 'block';
                var query = document.getElementById('searchInput').value.trim();
                var filters = getFilters();
                var filtersUsed = filters.category !== 'all' || filters.sort !== 'default' || filters.status !== 'all';
                if (query === '' && filtersUsed) {
                    message.textContent = 'No books found for the selected filters.';
                } else if (query !== '' && filtersUsed) {
                    message.textContent = 'No books found for "' + query + '" with the selected filters.';
                } else if (query !== '') {
                    message.textContent = 'No books found for "' + query + '". Please check the Book ID, Title, or ISBN.';
                } else {
                    message.textContent = 'No books found.';
                }
                return;
            }

            emptyState.style.display = 'none';
            message.style.display = 'none';
            var totalPages = Math.ceil(currentResults.length / rowsPerPage);
            if (currentPage > totalPages) currentPage = totalPages;
            var startIndex = (currentPage - 1) * rowsPerPage;
            var visibleRecords = currentResults.slice(startIndex, startIndex + rowsPerPage);

            visibleRecords.forEach(function (book) {
                var row = document.createElement('tr');
                [book.id, book.title, book.author, book.isbn, book.category,
                 book.publisher || '-', book.year || '-', book.quantity,
                 book.price || '-', book.location || '-'].forEach(function (value) {
                    var cell = document.createElement('td');
                    cell.textContent = value == null || value === '' ? '-' : String(value);
                    row.appendChild(cell);
                });

                var actionCell = document.createElement('td');
                var actionGroup = document.createElement('div');
                actionGroup.className = 'action-buttons';
                var updateButton = document.createElement('button');
                updateButton.type = 'button';
                updateButton.className = 'update-btn';
                updateButton.textContent = 'Update';
                updateButton.addEventListener('click', function () { showForm(String(book.id)); });
                var deleteButton = document.createElement('button');
                deleteButton.type = 'button';
                deleteButton.className = 'delete-btn';
                deleteButton.textContent = 'Delete';
                deleteButton.addEventListener('click', function () { deleteBook(String(book.id)); });
                actionGroup.appendChild(updateButton);
                actionGroup.appendChild(deleteButton);
                actionCell.appendChild(actionGroup);
                row.appendChild(actionCell);
                tbody.appendChild(row);
            });
            renderPagination(totalPages);
        }

        function renderPagination(total){var p=document.getElementById('pagination');p.innerHTML='';if(total<=1)return;var prev=document.createElement('button');prev.type='button';prev.textContent='Previous';prev.disabled=currentPage===1;prev.onclick=function(){if(currentPage>1){currentPage--;renderResults();}};p.appendChild(prev);for(var i=1;i<=total;i++){var b=document.createElement('button');b.type='button';b.textContent=i;if(i===currentPage)b.classList.add('active');b.onclick=(function(page){return function(){currentPage=page;renderResults();};})(i);p.appendChild(b);}var next=document.createElement('button');next.type='button';next.textContent='Next';next.disabled=currentPage===total;next.onclick=function(){if(currentPage<total){currentPage++;renderResults();}};p.appendChild(next);}

        function searchBooks(){

            if(!validateSearch()) return;

            var q=document.getElementById('searchInput').value.trim();

            currentResults=applyFiltersToResults(getSearchMatches());

            currentPage=1;

            renderResults();

            if(q==='') clearSearchError();

        }

        document.getElementById('searchInput').addEventListener('input',function(){

            var v=this.value.trim();

            clearSearchError();

            if(v==='') return;

            if(v.length>100){showSearchError('Search text cannot be more than 100 characters.');return;}

            if(/^\d+$/.test(v) && /^(978|979)/.test(v) && v.length!==13){showSearchError('ISBN must contain exactly 13 digits.');}

        });

        document.getElementById('searchInput').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();searchBooks();}});



        function openFilter(){document.getElementById('filterOverlay').style.display='flex';}

        function closeFilter(){document.getElementById('filterOverlay').style.display='none';}

        function closeFilterOnOverlay(e){if(e.target===document.getElementById('filterOverlay'))closeFilter();}

        function applyFilter(){

            if(!validateFilters()) return;

            if(!validateSearch()) return;

            currentResults=applyFiltersToResults(getSearchMatches());

            currentPage=1;

            renderResults();

            closeFilter();

        }

        function clearFilter(){

            document.getElementById('categoryFilter').value='all';

            document.getElementById('sortFilter').value='default';

            document.getElementById('statusFilter').value='all';

            clearSearchError();

            currentResults=applyFiltersToResults(getSearchMatches());

            currentPage=1;

            renderResults();

            closeFilter();

        }



        /* Update form validation: same rules as Add Book. */

        var form=document.getElementById('updateBookForm'),editBookId=document.getElementById('editBookId'),editTitle=document.getElementById('editTitle'),editAuthor=document.getElementById('editAuthor'),editISBN=document.getElementById('editISBN'),editCategory=document.getElementById('editCategory'),editCategoryRow=document.getElementById('editCategoryRow'),editOtherCategoryGroup=document.getElementById('editOtherCategoryGroup'),editOtherCategory=document.getElementById('editOtherCategory'),editPublisher=document.getElementById('editPublisher'),editReleaseYear=document.getElementById('editReleaseYear'),editQuantity=document.getElementById('editQuantity'),editPrice=document.getElementById('editPrice'),editLocation=document.getElementById('editLocation');

        function fieldLabel(i){return document.querySelector('label[for="'+i.id+'"]');}

        function addRequiredStar(i){var l=fieldLabel(i);if(l&&!l.querySelector('.required-star')){var s=document.createElement('span');s.className='required-star';s.textContent='*';l.appendChild(s);}}

        function removeRequiredStar(i){var l=fieldLabel(i),s=l&&l.querySelector('.required-star');if(s)s.remove();}

        function removeInvalidStateOnly(i){i.classList.remove('invalid','valid');}

        function setError(i,id,m){document.getElementById(id).textContent=m;i.classList.add('invalid');i.classList.remove('valid');}

        function setSuccess(i,id){document.getElementById(id).textContent='';i.classList.remove('invalid');i.classList.add('valid');removeRequiredStar(i);}

        function clearFieldError(i,id){document.getElementById(id).textContent='';i.classList.remove('invalid','valid');removeRequiredStar(i);}

        function setRequiredError(i,id,m){removeInvalidStateOnly(i);addRequiredStar(i);document.getElementById(id).textContent=m;}

        function validateEditTitle(){var v=editTitle.value.trim();if(v===''){setRequiredError(editTitle,'editTitleError','Please enter book title.');return false;}if(v.length>100){setError(editTitle,'editTitleError','Book title cannot exceed 100 characters.');return false;}setSuccess(editTitle,'editTitleError');return true;}

        function validateEditAuthor(){var v=editAuthor.value.trim();if(v===''){setRequiredError(editAuthor,'editAuthorError','Please enter author name.');return false;}if(v.length<2||v.length>100){setError(editAuthor,'editAuthorError','Author name must be between 2 and 100 characters.');return false;}if(!/^[A-Za-z][A-Za-z .'-]*$/.test(v)){setError(editAuthor,'editAuthorError','Author name can contain only letters, spaces, apostrophe, dot and hyphen.');return false;}setSuccess(editAuthor,'editAuthorError');return true;}

        function validateEditISBN(){var v=editISBN.value.trim();if(v===''){setRequiredError(editISBN,'editISBNError','Please enter ISBN.');return false;}if(!/^\d{13}$/.test(v)){setError(editISBN,'editISBNError','ISBN must contain exactly 13 digits.');return false;}setSuccess(editISBN,'editISBNError');return true;}

        function validateEditCategory(){var v=editCategory.value;if(v===''){setRequiredError(editCategory,'editCategoryError','Please select a category.');return false;}if(v==='Other')return validateEditOtherCategory();setSuccess(editCategory,'editCategoryError');clearFieldError(editOtherCategory,'editOtherCategoryError');return true;}

        function validateEditOtherCategory(){var v=editOtherCategory.value.trim();if(v===''){setRequiredError(editOtherCategory,'editOtherCategoryError','Please enter a category.');return false;}if(v.length<2||v.length>50){setError(editOtherCategory,'editOtherCategoryError','Category must be between 2 and 50 characters.');return false;}if(!/^[A-Za-z]+(?: [A-Za-z]+)*$/.test(v)){setError(editOtherCategory,'editOtherCategoryError','Category can contain only letters and spaces.');return false;}setSuccess(editOtherCategory,'editOtherCategoryError');return true;}

        editCategory.addEventListener('change',function(){var other=this.value==='Other';editCategoryRow.classList.toggle('other-open',other);editOtherCategoryGroup.style.display=other?'block':'none';if(other){clearFieldError(editCategory,'editCategoryError');editOtherCategory.focus();}else{editOtherCategory.value='';clearFieldError(editOtherCategory,'editOtherCategoryError');}validateEditCategory();});

        function validateEditPublisher(){var v=editPublisher.value.trim();if(v===''){clearFieldError(editPublisher,'editPublisherError');return true;}if(v.length<2||v.length>100){setError(editPublisher,'editPublisherError','Publisher name must be between 2 and 100 characters.');return false;}if(!/^[A-Za-z][A-Za-z &'.,()-]*$/.test(v)){setError(editPublisher,'editPublisherError','Publisher name can contain only letters, spaces, &, apostrophe, dot, comma, hyphen and parentheses.');return false;}setSuccess(editPublisher,'editPublisherError');return true;}

        function validateEditReleaseYear(){var v=editReleaseYear.value.trim();if(v===''){setRequiredError(editReleaseYear,'editReleaseYearError','Please enter release year.');return false;}if(!/^\d{4}$/.test(v)){setError(editReleaseYear,'editReleaseYearError','Release year must contain exactly 4 digits.');return false;}var y=Number(v),cy=new Date().getFullYear();if(y<1000||y>cy){setError(editReleaseYear,'editReleaseYearError','Release year must be between 1000 and '+cy+'.');return false;}setSuccess(editReleaseYear,'editReleaseYearError');return true;}

        function validateEditQuantity(){var v=editQuantity.value.trim();if(v===''){setRequiredError(editQuantity,'editQuantityError','Please enter quantity.');return false;}if(!/^[1-9]\d*$/.test(v)){setError(editQuantity,'editQuantityError','Quantity must be a positive whole number only.');return false;}setSuccess(editQuantity,'editQuantityError');return true;}

        function validateEditPrice(){var v=editPrice.value.trim();if(v===''){clearFieldError(editPrice,'editPriceError');return true;}if(!/^\d+(\.\d+)?$/.test(v)){setError(editPrice,'editPriceError','Price must be a non-negative number and may contain decimals.');return false;}if(Number(v)<0){setError(editPrice,'editPriceError','Price cannot be negative.');return false;}setSuccess(editPrice,'editPriceError');return true;}

        function validateEditLocation(){var v=editLocation.value.replace(/\s+/g,' ').trim();if(v===''){setRequiredError(editLocation,'editLocationError','Please enter book location.');return false;}if(v.length>50){setError(editLocation,'editLocationError','Book location cannot exceed 50 characters.');return false;}if(!/^[A-Za-z0-9][A-Za-z0-9 ,/_-]*$/.test(v)){setError(editLocation,'editLocationError','Book location can contain letters, numbers, spaces, comma, /, _ and -.');return false;}setSuccess(editLocation,'editLocationError');return true;}

        editISBN.addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').slice(0,13);validateEditISBN();});

        editReleaseYear.addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').slice(0,4);validateEditReleaseYear();});

        editQuantity.addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').replace(/^0+/,'');validateEditQuantity();});

        editPrice.addEventListener('input',function(){this.value=this.value.replace(/[^0-9.]/g,'');var d=this.value.indexOf('.');if(d!==-1)this.value=this.value.substring(0,d+1)+this.value.substring(d+1).replace(/\./g,'');validateEditPrice();});

        editLocation.addEventListener('input',function(){if(this.value.length>50)this.value=this.value.slice(0,50);validateEditLocation();});

        editTitle.addEventListener('input',validateEditTitle);editAuthor.addEventListener('input',validateEditAuthor);editPublisher.addEventListener('input',validateEditPublisher);editOtherCategory.addEventListener('input',validateEditOtherCategory);

        [editTitle,editAuthor,editISBN,editPublisher,editReleaseYear,editQuantity,editPrice,editLocation,editOtherCategory].forEach(function(i){i.addEventListener('input',function(){removeRequiredStar(i);});});

        editCategory.addEventListener('change',function(){removeRequiredStar(editCategory);});

        form.addEventListener('keydown',function(e){if(e.key!=='Enter')return;var t=e.target;if(t.tagName==='TEXTAREA')return;if(t.tagName==='BUTTON'){e.preventDefault();return;}e.preventDefault();var fields=Array.from(form.querySelectorAll('input:not([type="submit"]),select,textarea')).filter(function(f){return !f.disabled&&f.offsetParent!==null;});var idx=fields.indexOf(t);if(idx>=0&&idx<fields.length-1)fields[idx+1].focus();else form.querySelector('button[type="submit"]').focus();});

        function clearUpdateErrors(){document.querySelectorAll('#updateBookForm .error').forEach(function(e){e.textContent='';});document.querySelectorAll('#updateBookForm input,#updateBookForm select,#updateBookForm textarea').forEach(function(i){i.classList.remove('invalid','valid');removeRequiredStar(i);});}

        function resetBookView(){

            document.getElementById('searchInput').value='';

            document.getElementById('categoryFilter').value='all';

            document.getElementById('sortFilter').value='default';

            document.getElementById('statusFilter').value='all';

            clearSearchError();

            currentResults=[];

            currentPage=1;

            renderResults();

        }



        function saveUndoSnapshot(){

            undoSnapshot={

                records:JSON.parse(JSON.stringify(bookRecords)),

                search:document.getElementById('searchInput').value,

                category:document.getElementById('categoryFilter').value,

                sort:document.getElementById('sortFilter').value,

                status:document.getElementById('statusFilter').value,

                page:currentPage

            };

            var u=document.getElementById('undoBtn');

            if(u)u.disabled=false;

        }



        async function undoLastAction() {
            if (!undoSnapshot) return;
            var snapshot = undoSnapshot;
            var undoButton = document.getElementById('undoBtn');
            if (undoButton) undoButton.disabled = true;
            try {
                await sendBookAction({ action: 'restore', mode: snapshot.kind, book: snapshot.previousRecord });
                bookRecords = snapshot.records;
                document.getElementById('searchInput').value = snapshot.search;
                document.getElementById('categoryFilter').value = snapshot.category;
                document.getElementById('sortFilter').value = snapshot.sort;
                document.getElementById('statusFilter').value = snapshot.status;
                clearSearchError();
                currentPage = snapshot.page;
                currentResults = applyFiltersToResults(getSearchMatches());
                renderResults();
                undoSnapshot = null;
                if (undoButton) undoButton.disabled = true;
                alert('Last action undone.');
            } catch (error) {
                if (undoButton) undoButton.disabled = false;
                alert(error.message || 'Could not undo the last action.');
            }
        }



        function showForm(bookId){selectedRecord=bookRecords.find(function(b){return b.id===String(bookId);});if(!selectedRecord)return;clearUpdateErrors();editBookId.value=selectedRecord.id;editTitle.value=selectedRecord.title;editAuthor.value=selectedRecord.author;editISBN.value=selectedRecord.isbn;editPublisher.value=selectedRecord.publisher||'';editReleaseYear.value=selectedRecord.year||'';editQuantity.value=selectedRecord.quantity;editPrice.value=selectedRecord.price||'';editLocation.value=selectedRecord.location||'';var exists=Array.from(editCategory.options).some(function(o){return o.value===selectedRecord.category;});if(exists){editCategory.value=selectedRecord.category;editOtherCategory.value='';editCategoryRow.classList.remove('other-open');editOtherCategoryGroup.style.display='none';}else{editCategory.value='Other';editCategoryRow.classList.add('other-open');editOtherCategoryGroup.style.display='block';editOtherCategory.value=selectedRecord.category;}document.getElementById('updateModal').classList.add('show');}

        function closeForm(){document.getElementById('updateModal').classList.remove('show');selectedRecord=null;}

        function closeFormOnOverlay(e){if(e.target===document.getElementById('updateModal'))closeForm();}

        async function sendBookAction(payload) {
            const response = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error('The server returned an unreadable response.'); }
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'The database operation failed.');
            }
            return result;
        }

        function makeUndoSnapshot(kind, beforeRecords, previousRecord) {
            undoSnapshot = {
                records: beforeRecords,
                search: document.getElementById('searchInput').value,
                category: document.getElementById('categoryFilter').value,
                sort: document.getElementById('sortFilter').value,
                status: document.getElementById('statusFilter').value,
                page: currentPage,
                kind: kind,
                previousRecord: JSON.parse(JSON.stringify(previousRecord))
            };
            var undoButton = document.getElementById('undoBtn');
            if (undoButton) undoButton.disabled = false;
        }

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            if (!selectedRecord) return;
            var ok = validateEditTitle() && validateEditAuthor() && validateEditISBN() &&
                validateEditCategory() && validateEditPublisher() && validateEditReleaseYear() &&
                validateEditQuantity() && validateEditPrice() && validateEditLocation();
            if (!ok) return;

            var beforeRecords = JSON.parse(JSON.stringify(bookRecords));
            var previousRecord = JSON.parse(JSON.stringify(selectedRecord));
            var updatedBook = {
                id: selectedRecord.id,
                title: editTitle.value.trim(),
                author: editAuthor.value.trim(),
                isbn: editISBN.value.trim(),
                category: editCategory.value === 'Other' ? editOtherCategory.value.trim() : editCategory.value,
                publisher: editPublisher.value.trim(),
                year: editReleaseYear.value.trim(),
                quantity: editQuantity.value.trim(),
                price: editPrice.value.trim(),
                location: editLocation.value.replace(/\s+/g, ' ').trim(),
                status: selectedRecord.status
            };
            var submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            try {
                var result = await sendBookAction({ action: 'update', book: updatedBook });
                var index = bookRecords.findIndex(function(b) { return String(b.id) === String(updatedBook.id); });
                if (index !== -1) bookRecords[index] = result.book;
                makeUndoSnapshot('update', beforeRecords, previousRecord);
                currentResults = applyFiltersToResults(getSearchMatches());
                currentPage = Math.min(currentPage, Math.max(1, Math.ceil(currentResults.length / rowsPerPage)));
                renderResults();
                closeForm();
                alert(result.message || 'Book updated successfully.');
            } catch (error) {
                alert(error.message || 'Could not update the book.');
            } finally {
                submitButton.disabled = false;
            }
        });

        async function deleteBook(bookId) {
            var book = bookRecords.find(function(x) { return String(x.id) === String(bookId); });
            if (!book) return;
            if (!confirm('Are you sure you want to delete Book ID ' + book.id + ' - ' + book.title + '?')) return;
            var beforeRecords = JSON.parse(JSON.stringify(bookRecords));
            var previousRecord = JSON.parse(JSON.stringify(book));
            try {
                var result = await sendBookAction({ action: 'delete', id: book.id });
                bookRecords = bookRecords.filter(function(x) { return String(x.id) !== String(bookId); });
                makeUndoSnapshot('delete', beforeRecords, previousRecord);
                currentResults = applyFiltersToResults(getSearchMatches());
                currentPage = Math.min(currentPage, Math.max(1, Math.ceil(currentResults.length / rowsPerPage)));
                renderResults();
                alert(result.message || 'Book deleted successfully.');
            } catch (error) {
                alert(error.message || 'Could not delete the book.');
            }
        }

    </script>

</body>

</html>