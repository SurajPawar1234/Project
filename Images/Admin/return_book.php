<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = null;
$dbConnectionError = '';
$connectionCandidates = [];
$dir = __DIR__;
for ($level = 0; $level < 6; $level++) {
    $connectionCandidates[] = $dir . DIRECTORY_SEPARATOR . 'Connection' . DIRECTORY_SEPARATOR . 'db.php';
    $connectionCandidates[] = $dir . DIRECTORY_SEPARATOR . 'db.php';
    $parent = dirname($dir);
    if ($parent === $dir) break;
    $dir = $parent;
}
$connectionCandidates = array_values(array_unique($connectionCandidates));
$connectionFile = null;
foreach ($connectionCandidates as $candidate) {
    if (is_file($candidate)) {
        $connectionFile = $candidate;
        break;
    }
}

if ($connectionFile === null) {
    $dbConnectionError = 'Connection file not found. Expected Connection/db.php or db.php.';
} else {
    try {
        require_once $connectionFile;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $dbConnectionError = 'The database connection file must create a MySQLi connection named $conn.';
        } else {
            $conn->set_charset('utf8mb4');
        }
    } catch (Throwable $e) {
        $dbConnectionError = 'Could not connect to the library database. Check the settings in Connection/db.php.';
    }
}

function returnBookJsonResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function returnBookRequireConnection(?mysqli $conn, string $dbConnectionError): mysqli {
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException($dbConnectionError !== '' ? $dbConnectionError : 'Database connection is unavailable.');
    }
    return $conn;
}

function returnBookSettings(mysqli $conn): array {
    $settings = [
        'finePerDay' => 0.0,
        'upiId' => '',
        'number' => '',
        'upiQrData' => ''
    ];
    $result = $conn->query('SELECT Fine_per_day, Upi_id, Number, Upi_qr FROM fine_setting ORDER BY Id LIMIT 1');
    $row = $result->fetch_assoc();
    if ($row) {
        $settings['finePerDay'] = (float)($row['Fine_per_day'] ?? 0);
        $settings['upiId'] = (string)($row['Upi_id'] ?? '');
        $settings['number'] = (string)($row['Number'] ?? '');
        $qr = $row['Upi_qr'] ?? '';
        if (is_string($qr) && $qr !== '') {
            $settings['upiQrData'] = base64_encode($qr);
        }
    }
    return $settings;
}

function returnBookFetchRecords(mysqli $conn, bool $returned): array {
    $where = $returned ? 'ir.Return_date IS NOT NULL' : 'ir.Return_date IS NULL';
    $sql = "SELECT
                ir.Issue_id AS issueId,
                ir.Member_id AS memberId,
                CONCAT(m.First_name, ' ', m.Last_name) AS memberName,
                ir.Book_id AS bookId,
                b.Title AS title,
                b.Author AS author,
                b.ISBN AS isbn,
                COALESCE(b.Category, 'Other') AS category,
                b.Year AS year,
                ir.Issue_date AS issueDate,
                ir.Due_date AS dueDate,
                ir.Return_date AS returnDate,
                COALESCE(ir.Fine, 0) AS storedFine,
                ir.Payment_method AS payment,
                CASE
                    WHEN ir.Return_date IS NULL THEN GREATEST(DATEDIFF(CURDATE(), ir.Due_date), 0) * COALESCE(fs.Fine_per_day, 0)
                    ELSE COALESCE(ir.Fine, 0)
                END AS fineAmount
            FROM issue_return ir
            INNER JOIN member m ON m.Member_id = ir.Member_id
            INNER JOIN book b ON b.Book_id = ir.Book_id
            LEFT JOIN fine_setting fs ON fs.Id = 1
            WHERE $where
            ORDER BY ir.Issue_date DESC, ir.Issue_id DESC";
    $result = $conn->query($sql);
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $fineAmount = (float)($row['fineAmount'] ?? 0);
        $records[] = [
            'issueId' => (string)$row['issueId'],
            'memberId' => (string)$row['memberId'],
            'memberName' => (string)$row['memberName'],
            'id' => (string)$row['bookId'],
            'title' => (string)$row['title'],
            'author' => (string)$row['author'],
            'isbn' => (string)$row['isbn'],
            'category' => (string)($row['category'] ?? 'Other'),
            'year' => (string)($row['year'] ?? ''),
            'issueDate' => (string)$row['issueDate'],
            'dueDate' => (string)$row['dueDate'],
            'returnDate' => (string)($row['returnDate'] ?? ''),
            'fine' => '₹' . number_format($fineAmount, 2, '.', ''),
            'fineAmount' => $fineAmount,
            'payment' => (string)(($row['payment'] ?? '') !== '' ? $row['payment'] : '-')
        ];
    }
    return $records;
}

$action = (string)($_GET['action'] ?? '');
if ($action !== '') {
    try {
        $db = returnBookRequireConnection($conn, $dbConnectionError);
        if ($action === 'list_records' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            returnBookJsonResponse([
                'success' => true,
                'issuedRecords' => returnBookFetchRecords($db, false),
                'returnedBooks' => returnBookFetchRecords($db, true),
                'settings' => returnBookSettings($db)
            ]);
        }

        if ($action === 'return_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) {
                returnBookJsonResponse(['success' => false, 'message' => 'Invalid request data.'], 400);
            }
            $issueId = filter_var($input['issueId'] ?? null, FILTER_VALIDATE_INT);
            $paymentMethod = strtoupper(trim((string)($input['paymentMethod'] ?? '')));
            if (!$issueId || $issueId < 1) {
                returnBookJsonResponse(['success' => false, 'message' => 'Invalid issue record. Refresh the page and try again.'], 400);
            }

            $db->begin_transaction();
            try {
                $stmt = $db->prepare('SELECT Issue_id, Member_id, Due_date, Return_date FROM issue_return WHERE Issue_id = ? FOR UPDATE');
                $stmt->bind_param('i', $issueId);
                $stmt->execute();
                $issue = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$issue) {
                    $db->rollback();
                    returnBookJsonResponse(['success' => false, 'message' => 'Issue record not found. Refresh the page and try again.'], 404);
                }
                if (!empty($issue['Return_date'])) {
                    $db->rollback();
                    returnBookJsonResponse(['success' => false, 'message' => 'This book has already been returned. Refresh the page.'], 409);
                }

                $settings = returnBookSettings($db);
                $fineStmt = $db->prepare('SELECT GREATEST(DATEDIFF(CURDATE(), ?), 0) AS lateDays');
                $fineStmt->bind_param('s', $issue['Due_date']);
                $fineStmt->execute();
                $lateDays = (int)($fineStmt->get_result()->fetch_assoc()['lateDays'] ?? 0);
                $fineStmt->close();
                $fineAmount = round($lateDays * (float)$settings['finePerDay'], 2);

                $storedPayment = null;
                if ($fineAmount > 0) {
                    if (!in_array($paymentMethod, ['UPI', 'CASH'], true)) {
                        $db->rollback();
                        returnBookJsonResponse([
                            'success' => false,
                            'message' => 'A fine of ₹' . number_format($fineAmount, 2) . ' is due. Choose UPI or Cash and confirm payment before returning this book.'
                        ], 422);
                    }
                    $storedPayment = $paymentMethod === 'UPI' ? 'UPI' : 'Cash';
                }

                $update = $db->prepare("UPDATE issue_return SET Return_date = CURDATE(), Status = 'Returned', Fine = ?, Payment_method = ? WHERE Issue_id = ? AND Return_date IS NULL");
                $update->bind_param('dsi', $fineAmount, $storedPayment, $issueId);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    $update->close();
                    throw new RuntimeException('The issue record changed before it could be returned. Refresh and try again.');
                }
                $update->close();

                $memberId = (int)$issue['Member_id'];
                $memberUpdate = $db->prepare('UPDATE member SET Current_Issue = CASE WHEN Current_Issue > 0 THEN Current_Issue - 1 ELSE 0 END WHERE Member_id = ?');
                $memberUpdate->bind_param('i', $memberId);
                $memberUpdate->execute();
                $memberUpdate->close();

                $db->commit();
                returnBookJsonResponse([
                    'success' => true,
                    'message' => 'Book returned successfully.',
                    'fineAmount' => $fineAmount,
                    'paymentMethod' => $storedPayment
                ]);
            } catch (Throwable $e) {
                try { $db->rollback(); } catch (Throwable $ignored) {}
                throw $e;
            }
        }

        returnBookJsonResponse(['success' => false, 'message' => 'Unsupported action.'], 400);
    } catch (Throwable $e) {
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'A database error occurred. Check your database tables and Connection/db.php.';
        returnBookJsonResponse(['success' => false, 'message' => $message], 500);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Book</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background-color: #f2f2f2; }

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

        /* Main */
        .main { margin-left: 220px; padding: 0 25px 25px; }
        .top {
            background-color: white;
            padding: 20px;
            border: 1px solid #ddd;
            margin-top: 10px;
        }
        .top h1 { margin: 0; text-align: center; color: #333; }

        /* Search */
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
        .search button:hover { background-color: #37434a; }
        .filter-btn { background-color: #666 !important; }
        .filter-btn:hover { background-color: #555 !important; }
        .search-validation {
            display: none;
            width: 325px;
            align-self: center;
            margin-top: 6px;
            color: red;
            font-size: 14px;
            line-height: 1.4;
        }
        .search-input-invalid { border: 2px solid red !important; }

        /* Issued Books card */
        .books {
            background-color: white;
            margin-top: 10px;
            padding: 20px;
            border: 1px solid #ddd;
            min-height: calc(100vh - 170px);
        }
        .books-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
        }
        .books h2 { margin: 0; color: #333; }

        .table-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
        }
        table { width: max-content; min-width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px;
            border: 1px solid #ccc;
            text-align: left;
            white-space: nowrap;
            height: 45px;
            vertical-align: middle;
        }
        th { background-color: #eee; color: #333; }
        .available { color: green; font-weight: bold; }
        .fine { color: red; font-weight: bold; }
        .no-fine { color: green; font-weight: bold; }

        .action-buttons { display: flex; align-items: center; white-space: nowrap; }
        .return-btn {
            background-color: #333;
            color: white;
            border: none;
            padding: 8px 15px;
            cursor: pointer;
            border-radius: 3px;
        }
        .return-btn:hover { background-color: #555; }

        .empty-state {
            display: block;
            text-align: center;
            color: #666;
            font-size: 15px;
            padding: 22px 10px;
            border: 1px solid #ddd;
            border-top: 0;
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
        .pagination button:hover:not(:disabled) { background-color: #eee; }
        .pagination button.active { background-color: #465660; color: white; border-color: #465660; }
        .pagination button:disabled { color: #aaa; cursor: not-allowed; background-color: #f5f5f5; }

        /* Filter popup */
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
            box-shadow: 0 10px 35px rgba(0,0,0,0.25);
        }
        .filter-popup h2 { margin: 0 0 28px 0; color: #465660; font-size: 30px; }
        .filter-group { margin-bottom: 22px; }
        .filter-group label { display: block; font-size: 20px; font-weight: bold; margin-bottom: 10px; color: #333; }
        .filter-group select {
            width: 100%;
            padding: 14px 18px;
            font-size: 18px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: white;
        }
        .filter-actions { display: flex; gap: 15px; margin-top: 28px; }
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


        /* Returned Books card */
        .returned-books-card {
            background-color: white;
            margin-top: 38px;
            padding: 20px;
            border: 1px solid #ddd;
            min-height: 430px;
            box-sizing: border-box;
        }
        .returned-books-card.collapsed {
            min-height: 0;
        }
        .returned-books-header {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 22px;
            min-height: 40px;
        }
        .returned-books-header h2 {
            margin: 0;
            color: #333;
            white-space: nowrap;
        }
        .toggle-returned-btn {
            min-width: 42px;
            height: 36px;
            padding: 0 12px;
            border: 1px solid #ccc;
            background: white;
            color: #333;
            border-radius: 4px;
            cursor: pointer;
            font-size: 22px;
            line-height: 1;
            flex: 0 0 auto;
            margin-left: auto;
        }
        .toggle-returned-btn:hover { background-color: #eee; }
        .returned-search-container {
            display: flex;
            flex: 0 1 auto;
            flex-direction: column;
            align-items: center;
            margin: 0 auto;
            min-width: 0;
        }
        .returned-search {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            flex-wrap: nowrap;
        }
        .returned-search input {
            width: 325px;
            padding: 12px;
            border: 1px solid #aaa;
            border-radius: 4px;
            font-size: 16px;
        }
        .returned-search button {
            padding: 12px 22px;
            background-color: #465660;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .returned-search button:hover { background-color: #37434a; }
        .returned-search .filter-btn { background-color: #666 !important; }
        .returned-search .filter-btn:hover { background-color: #555 !important; }
        .returned-search-validation {
            display: none;
            width: 325px;
            margin-top: 6px;
            color: red;
            font-size: 14px;
            line-height: 1.4;
            text-align: left;
        }
        .returned-search-input-invalid { border: 2px solid red !important; }
        .returned-no-books-message {
            display: none;
            text-align: center;
            color: red;
            font-size: 17px;
            font-weight: bold;
            margin: 20px 0 5px;
        }
        .returned-pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 18px;
            min-height: 38px;
            flex-wrap: wrap;
        }
        .returned-pagination button {
            min-width: 38px;
            padding: 9px 12px;
            border: 1px solid #ccc;
            background-color: white;
            color: #333;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .returned-pagination button:hover:not(:disabled) { background-color: #eee; }
        .returned-pagination button.active { background-color: #465660; color: white; border-color: #465660; }
        .returned-pagination button:disabled { color: #aaa; cursor: not-allowed; background-color: #f5f5f5; }
        .returned-empty {
            text-align: center;
            color: #666;
            padding: 22px 10px;
            border: 1px solid #ddd;
        }


        /* Fine Payment Modal - redesigned like Library Fine Payment */
        .payment-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            align-items: center;
            justify-content: center;
            z-index: 1100;
            padding: 20px;
        }
        .payment-modal.show { display: flex; }
        .payment-box {
            width: 735px;
            max-width: 95%;
            max-height: 92vh;
            overflow-y: auto;
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.25);
        }
        .payment-title {
            text-align: center;
            margin: 0 0 28px;
            color: #333;
            font-size: 34px;
        }
        .payment-close {
            position: absolute;
            top: 18px;
            right: 22px;
            border: none;
            background: transparent;
            font-size: 30px;
            color: #555;
            cursor: pointer;
        }
        .payment-box { position: relative; }
        .fine-summary {
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 22px;
            margin-bottom: 26px;
        }
        .fine-summary p { margin: 9px 0; color: #333; font-size: 19px; }
        .fine-summary .fine-amount { color: red; font-size: 30px; font-weight: bold; margin-top: 18px; }
        .payment-subtitle { margin: 0 0 16px; color: #333; font-size: 28px; }
        .payment-options { display: flex; gap: 20px; margin-bottom: 22px; }
        .payment-method-card {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 24px;
            border: 1px solid #ccc;
            border-radius: 7px;
            background: white;
            cursor: pointer;
            font-size: 21px;
        }
        .payment-method-card.active { border-color: #465660; }
        .payment-method-card input { width: 20px; height: 20px; margin: 0; cursor: pointer; }
        .pay-fine-btn {
            width: 100%;
            padding: 17px;
            border: none;
            border-radius: 5px;
            background: #333;
            color: white;
            font-size: 22px;
            cursor: pointer;
        }
        .pay-fine-btn:hover { background: #222; }
        .back-return-btn {
            display: block;
            margin: 22px auto 0;
            border: none;
            background: transparent;
            color: #333;
            font-size: 18px;
            cursor: pointer;
        }

        /* UPI modal - redesigned like the supplied reference */
        .upi-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            align-items: center;
            justify-content: center;
            z-index: 1200;
            padding: 20px;
        }
        .upi-modal.show { display: flex; }
        .upi-box {
            width: 540px;
            max-width: 95%;
            background: white;
            border-radius: 12px;
            padding: 30px 34px 34px;
            position: relative;
            box-shadow: 0 10px 35px rgba(0,0,0,0.25);
        }
        .upi-box h2 {
            margin: 0 0 18px;
            text-align: center;
            color: #333;
            font-size: 30px;
        }
        .upi-close {
            position: absolute;
            top: 14px;
            right: 20px;
            border: none;
            background: transparent;
            color: #555;
            font-size: 28px;
            cursor: pointer;
        }
        .upi-intro {
            text-align: center;
            color: #333;
            font-size: 17px;
            margin-bottom: 18px;
        }
        .upi-qr {
            width: 190px;
            height: 190px;
            display: block;
            margin: 14px auto 18px;
            border: 1px solid #ddd;
        }
        .upi-id,
        .upi-number {
            text-align: center;
            font-size: 18px;
            margin: 8px 0;
            color: #333;
        }
        .upi-note {
            text-align: center;
            color: #666;
            font-size: 16px;
            line-height: 1.35;
            margin: 14px 0 22px;
        }
        .upi-paid-btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 5px;
            background: #333;
            color: white;
            font-size: 20px;
            cursor: pointer;
        }
        .upi-paid-btn:hover { background: #222; }

        @media (max-width: 800px) {
            .payment-options { flex-direction: column; }
            .payment-box { padding: 24px 20px; }
            .payment-title { font-size: 28px; }
            .payment-subtitle { font-size: 24px; }
            .fine-summary p { font-size: 17px; }
            .fine-summary .fine-amount { font-size: 26px; }
        }

        /* Mobile */
        @media (max-width: 800px) {
            .sidebar { width: 180px; }
            .main { margin-left: 180px; padding: 0 15px 20px; }
            .search { flex-wrap: wrap; }
            .search input { width: min(100%, 325px); }
            .search button { padding: 11px 18px; }
            .returned-books-header {
                flex-wrap: wrap;
            }
            .returned-books-header h2 {
                width: 100%;
            }
            .returned-search-container {
                width: 100%;
                order: 2;
            }
            .toggle-returned-btn {
                order: 3;
                margin-left: auto;
            }
            .returned-search { flex-wrap: wrap; }
            .returned-search input { width: min(100%, 325px); }
            .returned-search button { padding: 11px 18px; }
            .filter-popup { padding: 24px; }
            .filter-popup h2 { font-size: 26px; }
            .filter-group label { font-size: 18px; }
            .filter-group select { font-size: 16px; }
            .filter-actions { flex-direction: column; }
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
            <a href="return_book.php" class="active" aria-current="page">Return Book</a>
            <a href="members.php">Members Details</a>
            <a href="announcements.php">Announcements</a>
        </nav>
        <div class="logout"><a href="../login/login_form.php">Logout</a></div>
    </aside>

    <!-- Main -->
    <div class="main">
        <div class="top">
            <h1>Return Book</h1>
            <div class="search-container">
                <div class="search">
                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Search by Book ID, Title or ISBN"
                        maxlength="100"
                        autocomplete="off"
                    >
                    <button type="button" onclick="searchBooks()">Search</button>
                    <button type="button" class="filter-btn" onclick="openFilter()">Filter</button>
                </div>
                <div id="searchValidation" class="search-validation"></div>
            </div>
        </div>

        <div class="books">
            <div class="books-header">
                <h2>Issued Books</h2>
            </div>

            <div class="table-wrapper">
                <table id="bookTable">
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Member Name</th>
                            <th>Book ID</th>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>ISBN</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Fine</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="bookTableBody"></tbody>
                </table>
            </div>

            <div id="emptyState" class="empty-state">
                Search for an issued book by Book ID, Title, or ISBN to display records.
            </div>
            <p id="noBooksMessage" class="no-books-message"></p>
            <div id="pagination" class="pagination"></div>
        </div>


        <div class="returned-books-card" id="returnedBooksCard">
            <div class="returned-books-header">
                <h2>All Returned Books</h2>

                <div class="returned-search-container">
                    <div class="returned-search">
                        <input
                            type="text"
                            id="returnedSearchInput"
                            placeholder="Search by Member ID, Book ID, title or ISBN"
                            maxlength="100"
                            autocomplete="off"
                        >
                        <button type="button" onclick="searchReturnedBooks()">Search</button>
                        <button type="button" class="filter-btn" onclick="openReturnedFilter()">Filter</button>
                    </div>
                    <div id="returnedSearchValidation" class="returned-search-validation"></div>
                </div>

                <button type="button" class="toggle-returned-btn" id="toggleReturnedBtn" onclick="toggleReturnedBooks()">-</button>
            </div>

            <div id="returnedBooksContent">
                <div class="table-wrapper">
                    <table id="returnedBooksTable">
                        <thead>
                            <tr>
                                <th>Member ID</th>
                                <th>Member Name</th>
                                <th>Book ID</th>
                                <th>Book Title</th>
                                <th>Author</th>
                                <th>ISBN</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Return Date</th>
                                <th>Fine</th>
                                <th>Payment</th>
                            </tr>
                        </thead>
                        <tbody id="returnedBooksTableBody"></tbody>
                    </table>
                </div>
                <div id="returnedEmptyState" class="returned-empty" style="display:none;">
                    No returned books available.
                </div>
                <p id="returnedNoBooksMessage" class="returned-no-books-message"></p>
                <div id="returnedPagination" class="returned-pagination"></div>
            </div>
        </div>
    </div>

    <!-- Filter Popup: same categories/sorting as Update Book, without Status -->
    <div class="filter-overlay" id="filterOverlay" onclick="closeFilterOnOverlay(event)">
        <div class="filter-popup" onclick="event.stopPropagation()">
            <h2>Filter Books</h2>

            <div class="filter-group">
                <label for="categoryFilter">Category</label>
                <select id="categoryFilter">
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
                </select>
            </div>

            <div class="filter-group">
                <label for="dueDateFilter">Due Date</label>
                <select id="dueDateFilter">
                    <option value="with_due" selected>Show Records With Due Dates</option>
                    <option value="all_due">Show All Issued Records</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="fineFilter">Fine</label>
                <select id="fineFilter">
                    <option value="all">All</option>
                    <option value="with_fine">With Fine</option>
                    <option value="without_fine">Without Fine</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sortFilter">Sort By</label>
                <select id="sortFilter">
                    <option value="default">Default</option>
                    <option value="issue_asc">Issue Date Oldest to Newest</option>
                    <option value="issue_desc">Issue Date Newest to Oldest</option>
                    <option value="id_asc">Book ID Ascending</option>
                    <option value="id_desc">Book ID Descending</option>
                    <option value="title_asc">Title A-Z</option>
                    <option value="title_desc">Title Z-A</option>
                    <option value="year_desc">Release Year Newest</option>
                    <option value="year_asc">Release Year Oldest</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="button" class="apply-filter" onclick="applyFilter()">Apply Filter</button>
                <button type="button" class="clear-filter" onclick="clearFilter()">Clear</button>
            </div>
        </div>
    </div>


    <!-- Returned Books Filter Popup -->
    <div class="filter-overlay" id="returnedFilterOverlay" onclick="closeReturnedFilterOnOverlay(event)">
        <div class="filter-popup" onclick="event.stopPropagation()">
            <h2>Filter Returned Books</h2>

            <div class="filter-group">
                <label for="returnedCategoryFilter">Category</label>
                <select id="returnedCategoryFilter">
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
                </select>
            </div>

            <div class="filter-group">
                <label for="returnedFineFilter">Fine</label>
                <select id="returnedFineFilter">
                    <option value="all">All</option>
                    <option value="with_fine">With Fine</option>
                    <option value="without_fine">Without Fine</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="returnedPaymentFilter">Payment</label>
                <select id="returnedPaymentFilter">
                    <option value="all">All Payments</option>
                    <option value="UPI">UPI</option>
                    <option value="Cash">Cash</option>
                    <option value="none">No Fine / No Payment</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="returnedSortFilter">Sort By</label>
                <select id="returnedSortFilter">
                    <option value="issue_desc" selected>Issue Date Newest to Oldest</option>
                    <option value="issue_asc">Issue Date Oldest to Newest</option>
                    <option value="return_desc">Return Date Newest to Oldest</option>
                    <option value="return_asc">Return Date Oldest to Newest</option>
                    <option value="id_asc">Book ID Ascending</option>
                    <option value="id_desc">Book ID Descending</option>
                    <option value="title_asc">Title A-Z</option>
                    <option value="title_desc">Title Z-A</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="button" class="apply-filter" onclick="applyReturnedFilter()">Apply Filter</button>
                <button type="button" class="clear-filter" onclick="clearReturnedFilter()">Clear</button>
            </div>
        </div>
    </div>

    <!-- Fine Payment Modal -->
    <div class="payment-modal" id="paymentModal" onclick="closePaymentModalOnOverlay(event)">
        <div class="payment-box" onclick="event.stopPropagation()">
            <button type="button" class="payment-close" onclick="closePaymentModal()">&times;</button>
            <h1 class="payment-title">Library Fine Payment</h1>

            <div class="fine-summary">
                <p><strong>Member ID:</strong> <span id="fineMemberId">-</span></p>
                <p><strong>Member Name:</strong> <span id="fineMemberName">-</span></p>
                <p><strong>Book ID:</strong> <span id="fineBookId">-</span></p>
                <p><strong>Book Name:</strong> <span id="fineBookTitle">-</span></p>
                <p><strong>Due Date:</strong> <span id="fineDueDate">-</span></p>
                <p class="fine-amount">Fine Amount: ₹<span id="fineAmount">0</span></p>
            </div>

            <h2 class="payment-subtitle">Select Payment Method</h2>
            <div class="payment-options">
                <label class="payment-method-card active" id="upiCard">
                    <input type="radio" name="paymentMethod" value="upi" checked onchange="selectPaymentMethod('upi')">
                    <span>UPI</span>
                </label>
                <label class="payment-method-card" id="cashCard">
                    <input type="radio" name="paymentMethod" value="cash" onchange="selectPaymentMethod('cash')">
                    <span>Cash</span>
                </label>
            </div>

            <button type="button" class="pay-fine-btn" onclick="payFine()" id="payFineButton">Pay Fine ₹0</button>
            <button type="button" class="back-return-btn" onclick="closePaymentModal()">← Back to Return Book</button>
        </div>
    </div>

    <!-- UPI Payment Modal -->
    <div class="upi-modal" id="upiModal" onclick="closeUpiModalOnOverlay(event)">
        <div class="upi-box" onclick="event.stopPropagation()">
            <button type="button" class="upi-close" onclick="closeUpiModal()">&times;</button>
            <h2>Pay Using UPI</h2>
            <p class="upi-intro">Scan the QR code to pay the fine amount.</p>
            <img class="upi-qr" id="upiQrImage" alt="UPI QR Code" src="">
            <p class="upi-id"><strong>UPI ID:</strong> <span id="upiIdValue">Not configured</span></p>
            <p class="upi-number"><strong>Payment Number:</strong> <span id="upiNumberValue">Not configured</span></p>
            <p class="upi-note">Scan the QR code using any UPI app and pay the exact fine amount of ₹<span id="upiFineAmount">0</span>.</p>
            <button type="button" class="upi-paid-btn" onclick="confirmFinePayment('upi')">I Have Paid ₹<span id="upiPaidAmount">0</span></button>
        </div>
    </div>

    <script>
        function toggleMenu() {
            var menu = document.getElementById('submenu');
            var arrow = document.getElementById('arrow');
            var toggle = document.getElementById('manageBooksToggle');
            var willOpen = !menu.classList.contains('open');
            menu.classList.toggle('open', willOpen);
            arrow.textContent = willOpen ? '-' : '+';
            toggle.setAttribute('aria-expanded', String(willOpen));
        }

        document.getElementById('manageBooksToggle').addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleMenu();
            }
        });

        /* Records are loaded from the library database; no demo records are embedded. */
        var issuedRecords = [];
        var returnedBooks = [];

        var currentResults = [];
        var currentPage = 1;
        var rowsPerPage = 8;
        var returnedCurrentResults = [];
        var returnedCurrentPage = 1;
        var returnedRowsPerPage = 20;
        var returnedBooksVisible = true;
        document.getElementById('returnedSortFilter').value = 'issue_desc';
        var pendingReturnBookId = null;
        var selectedPaymentMethod = 'upi';

        function formatIndianDate(isoDate) {
            if (!isoDate || String(isoDate).trim() === '') return '-';
            var parts = String(isoDate).split('-');
            if (parts.length !== 3) return isoDate;
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }

        function todayISO() {
            var d = new Date();
            var y = d.getFullYear();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + day;
        }

        function showSearchError(message) {
            var input = document.getElementById('searchInput');
            var error = document.getElementById('searchValidation');
            error.textContent = message;
            error.style.display = 'block';
            input.classList.add('search-input-invalid');
        }

        function clearSearchError() {
            var input = document.getElementById('searchInput');
            var error = document.getElementById('searchValidation');
            error.textContent = '';
            error.style.display = 'none';
            input.classList.remove('search-input-invalid');
        }

        function validateSearch() {
            var value = document.getElementById('searchInput').value.trim();
            clearSearchError();
            if (value === '') return true;

            if (value.length > 100) {
                showSearchError('Search text cannot be more than 100 characters.');
                return false;
            }

            if (/^\d+$/.test(value)) {
                if (value.length !== 13 && value.length > 2) {
                    showSearchError('Numeric search must be a valid Book ID or exactly a 13-digit ISBN.');
                    return false;
                }
            }
            return true;
        }

        function getFilters() {
            return {
                category: document.getElementById('categoryFilter').value,
                dueDate: document.getElementById('dueDateFilter').value,
                fine: document.getElementById('fineFilter').value,
                sort: document.getElementById('sortFilter').value
            };
        }

        function validateFilters() {
            var f = getFilters();
            var categories = [
                'all','Adventure','Artificial Intelligence','Autobiography','Biography','Business','Children',
                'Computer Science','Cooking','Cybersecurity','Data Science','Database','Economics','Education',
                'Fantasy','Finance','Fiction','Health','History','Horror','Information Technology','Law',
                'Management','Mathematics','Medicine','Mystery','Networking','Non-Fiction','Other','Philosophy',
                'Poetry','Politics','Programming','Psychology','Religion','Romance','Science','Science Fiction',
                'Self-Help','Software Engineering','Thriller','Travel','Web Development'
            ];
            var dueDates = ['with_due','all_due'];
            var fines = ['all','with_fine','without_fine'];
            var sorts = ['default','issue_asc','issue_desc','id_asc','id_desc','title_asc','title_desc','year_desc','year_asc'];

            if (!categories.includes(f.category)) { alert('Please select a valid category.'); return false; }
            if (!dueDates.includes(f.dueDate)) { alert('Please select a valid due date filter.'); return false; }
            if (!fines.includes(f.fine)) { alert('Please select a valid fine filter.'); return false; }
            if (!sorts.includes(f.sort)) { alert('Please select a valid sorting option.'); return false; }
            return true;
        }

        function getSearchMatches() {
            var query = document.getElementById('searchInput').value.trim();
            if (query === '') return issuedRecords.slice();

            var lower = query.toLowerCase();
            if (/^\d+$/.test(query)) {
                if (query.length === 13) {
                    return issuedRecords.filter(function(record) { return record.isbn === query; });
                }
                return issuedRecords.filter(function(record) { return record.id === query; });
            }

            return issuedRecords.filter(function(record) {
                return record.title.toLowerCase().includes(lower) || record.isbn.toLowerCase().includes(lower);
            });
        }

        function applyFiltersToResults(results) {
            var f = getFilters();
            var filtered = results.filter(function(record) {
                if (f.category !== 'all' && record.category !== f.category) return false;
                if (f.dueDate === 'with_due' && String(record.dueDate).trim() === '') return false;
                if (f.fine === 'with_fine' && record.fineAmount <= 0) return false;
                if (f.fine === 'without_fine' && record.fineAmount > 0) return false;
                return true;
            });

            filtered.sort(function(a, b) {
                if (f.sort === 'issue_asc') return new Date(a.issueDate) - new Date(b.issueDate);
                if (f.sort === 'issue_desc') return new Date(b.issueDate) - new Date(a.issueDate);
                if (f.sort === 'id_asc') return Number(a.id) - Number(b.id);
                if (f.sort === 'id_desc') return Number(b.id) - Number(a.id);
                if (f.sort === 'title_asc') return a.title.localeCompare(b.title);
                if (f.sort === 'title_desc') return b.title.localeCompare(a.title);
                if (f.sort === 'year_desc') return Number(b.year) - Number(a.year);
                if (f.sort === 'year_asc') return Number(a.year) - Number(b.year);
                return Number(a.id) - Number(b.id);
            });
            return filtered;
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderResults() {
            var tbody = document.getElementById('bookTableBody');
            var emptyState = document.getElementById('emptyState');
            var message = document.getElementById('noBooksMessage');
            var pagination = document.getElementById('pagination');

            tbody.innerHTML = '';
            pagination.innerHTML = '';

            if (!currentResults.length) {
                emptyState.style.display = 'none';
                message.style.display = 'block';

                var query = document.getElementById('searchInput').value.trim();
                var filters = getFilters();
                var filtersUsed = filters.category !== 'all' || filters.dueDate !== 'with_due' || filters.fine !== 'all' || filters.sort !== 'default';

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

            var start = (currentPage - 1) * rowsPerPage;
            var pageRecords = currentResults.slice(start, start + rowsPerPage);

            pageRecords.forEach(function(record) {
                var row = document.createElement('tr');
                var fineClass = record.fineAmount > 0 ? 'fine' : 'no-fine';
                row.innerHTML =
                    '<td>' + escapeHtml(record.memberId) + '</td>' +
                    '<td>' + escapeHtml(record.memberName) + '</td>' +
                    '<td>' + escapeHtml(record.id) + '</td>' +
                    '<td>' + escapeHtml(record.title) + '</td>' +
                    '<td>' + escapeHtml(record.author) + '</td>' +
                    '<td>' + escapeHtml(record.isbn) + '</td>' +
                    '<td>' + escapeHtml(formatIndianDate(record.issueDate)) + '</td>' +
                    '<td>' + escapeHtml(formatIndianDate(record.dueDate)) + '</td>' +
                    '<td class="' + fineClass + '">' + escapeHtml(record.fine) + '</td>' +
                    '<td><div class="action-buttons">' +
                        '<button type="button" class="return-btn" onclick="returnBook(\'' + String(record.issueId).replace(/'/g, "\\'") + '\')">Return</button>' +
                    '</div></td>';
                tbody.appendChild(row);
            });

            renderPagination(totalPages);
        }

        function renderPagination(totalPages) {
            var pagination = document.getElementById('pagination');
            pagination.innerHTML = '';
            if (totalPages <= 1) return;

            var previous = document.createElement('button');
            previous.type = 'button';
            previous.textContent = 'Previous';
            previous.disabled = currentPage === 1;
            previous.onclick = function() {
                if (currentPage > 1) { currentPage--; renderResults(); }
            };
            pagination.appendChild(previous);

            for (var page = 1; page <= totalPages; page++) {
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = page;
                if (page === currentPage) button.classList.add('active');
                button.onclick = (function(selectedPage) {
                    return function() { currentPage = selectedPage; renderResults(); };
                })(page);
                pagination.appendChild(button);
            }

            var next = document.createElement('button');
            next.type = 'button';
            next.textContent = 'Next';
            next.disabled = currentPage === totalPages;
            next.onclick = function() {
                if (currentPage < totalPages) { currentPage++; renderResults(); }
            };
            pagination.appendChild(next);
        }

        function searchBooks() {
            if (!validateSearch()) return;
            currentResults = applyFiltersToResults(getSearchMatches());
            currentPage = 1;
            renderResults();
        }

        document.getElementById('searchInput').addEventListener('input', function() {
            var value = this.value.trim();
            clearSearchError();
            if (value === '') {
                currentResults = [];
                currentPage = 1;
                document.getElementById('bookTableBody').innerHTML = '';
                document.getElementById('pagination').innerHTML = '';
                document.getElementById('emptyState').style.display = 'block';
                document.getElementById('noBooksMessage').style.display = 'none';
                return;
            }
            if (value.length > 100) {
                showSearchError('Search text cannot be more than 100 characters.');
                return;
            }
            if (/^\d+$/.test(value) && value.length > 13) {
                showSearchError('Numeric search must be a valid Book ID or a 13-digit ISBN.');
            }
        });

        document.getElementById('searchInput').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchBooks();
            }
        });

        function openFilter() { document.getElementById('filterOverlay').style.display = 'flex'; }
        function closeFilter() { document.getElementById('filterOverlay').style.display = 'none'; }
        function closeFilterOnOverlay(event) {
            if (event.target === document.getElementById('filterOverlay')) closeFilter();
        }

        function applyFilter() {
            if (!validateFilters()) return;
            if (!validateSearch()) return;
            currentResults = applyFiltersToResults(getSearchMatches());
            currentPage = 1;
            renderResults();
            closeFilter();
        }

        function clearFilter() {
            document.getElementById('categoryFilter').value = 'all';
            document.getElementById('dueDateFilter').value = 'with_due';
            document.getElementById('fineFilter').value = 'all';
            document.getElementById('sortFilter').value = 'default';
            currentResults = applyFiltersToResults(getSearchMatches());
            currentPage = 1;
            renderResults();
            closeFilter();
        }

        function getReturnedFilters() {
            return {
                category: document.getElementById('returnedCategoryFilter').value,
                fine: document.getElementById('returnedFineFilter').value,
                payment: document.getElementById('returnedPaymentFilter').value,
                sort: document.getElementById('returnedSortFilter').value
            };
        }

        function validateReturnedSearch() {
            var value = document.getElementById('returnedSearchInput').value.trim();
            var input = document.getElementById('returnedSearchInput');
            var error = document.getElementById('returnedSearchValidation');
            error.textContent = '';
            error.style.display = 'none';
            input.classList.remove('returned-search-input-invalid');

            if (value === '') return true;
            if (value.length > 100) {
                error.textContent = 'Search text cannot be more than 100 characters.';
                error.style.display = 'block';
                input.classList.add('returned-search-input-invalid');
                return false;
            }
            if (/^\d+$/.test(value) && value.length > 13) {
                error.textContent = 'Numeric search must be a valid Book ID or exactly a 13-digit ISBN.';
                error.style.display = 'block';
                input.classList.add('returned-search-input-invalid');
                return false;
            }
            return true;
        }

        function validateReturnedFilters() {
            var f = getReturnedFilters();
            var categories = [
                'all','Adventure','Artificial Intelligence','Autobiography','Biography','Business','Children',
                'Computer Science','Cooking','Cybersecurity','Data Science','Database','Economics','Education',
                'Fantasy','Finance','Fiction','Health','History','Horror','Information Technology','Law',
                'Management','Mathematics','Medicine','Mystery','Networking','Non-Fiction','Other','Philosophy',
                'Poetry','Politics','Programming','Psychology','Religion','Romance','Science','Science Fiction',
                'Self-Help','Software Engineering','Thriller','Travel','Web Development'
            ];
            var fines = ['all','with_fine','without_fine'];
            var payments = ['all','UPI','Cash','none'];
            var sorts = ['issue_desc','issue_asc','return_desc','return_asc','id_asc','id_desc','title_asc','title_desc'];

            if (!categories.includes(f.category)) { alert('Please select a valid category.'); return false; }
            if (!fines.includes(f.fine)) { alert('Please select a valid fine filter.'); return false; }
            if (!payments.includes(f.payment)) { alert('Please select a valid payment filter.'); return false; }
            if (!sorts.includes(f.sort)) { alert('Please select a valid sorting option.'); return false; }
            return true;
        }

        function getReturnedSearchMatches() {
            var query = document.getElementById('returnedSearchInput').value.trim();
            if (query === '') return returnedBooks.slice();

            var lower = query.toLowerCase();
            if (/^\d+$/.test(query)) {
                if (query.length === 13) {
                    return returnedBooks.filter(function(record) { return record.isbn === query; });
                }
                return returnedBooks.filter(function(record) { return record.id === query || record.memberId === query; });
            }

            return returnedBooks.filter(function(record) {
                return record.memberId.toLowerCase().includes(lower) ||
                       record.memberName.toLowerCase().includes(lower) ||
                       record.title.toLowerCase().includes(lower) || record.isbn.toLowerCase().includes(lower);
            });
        }

        function applyReturnedFiltersToResults(results) {
            var f = getReturnedFilters();
            var filtered = results.filter(function(record) {
                if (f.category !== 'all' && record.category !== f.category) return false;
                if (f.fine === 'with_fine' && record.fineAmount <= 0) return false;
                if (f.fine === 'without_fine' && record.fineAmount > 0) return false;
                if (f.payment === 'UPI' && record.payment !== 'UPI') return false;
                if (f.payment === 'Cash' && record.payment !== 'Cash') return false;
                if (f.payment === 'none' && record.fineAmount > 0) return false;
                return true;
            });

            filtered.sort(function(a, b) {
                if (f.sort === 'issue_desc') return new Date(b.issueDate) - new Date(a.issueDate);
                if (f.sort === 'issue_asc') return new Date(a.issueDate) - new Date(b.issueDate);
                if (f.sort === 'return_desc') return new Date(b.returnDate) - new Date(a.returnDate);
                if (f.sort === 'return_asc') return new Date(a.returnDate) - new Date(b.returnDate);
                if (f.sort === 'id_asc') return Number(a.id) - Number(b.id);
                if (f.sort === 'id_desc') return Number(b.id) - Number(a.id);
                if (f.sort === 'title_asc') return a.title.localeCompare(b.title);
                if (f.sort === 'title_desc') return b.title.localeCompare(a.title);
                return 0;
            });
            return filtered;
        }

        function renderReturnedBooks() {
            var tbody = document.getElementById('returnedBooksTableBody');
            var empty = document.getElementById('returnedEmptyState');
            var message = document.getElementById('returnedNoBooksMessage');
            var pagination = document.getElementById('returnedPagination');

            tbody.innerHTML = '';
            pagination.innerHTML = '';

            if (!returnedCurrentResults.length) {
                empty.style.display = returnedBooks.length ? 'none' : 'block';
                if (returnedBooks.length) {
                    message.style.display = 'block';
                    var query = document.getElementById('returnedSearchInput').value.trim();
                    var f = getReturnedFilters();
                    var filtersUsed = f.category !== 'all' || f.fine !== 'all' || f.payment !== 'all' || f.sort !== 'issue_desc';
                    if (query !== '' && filtersUsed) {
                        message.textContent = 'No returned books found for "' + query + '" with the selected filters.';
                    } else if (query !== '') {
                        message.textContent = 'No returned books found for "' + query + '". Please check the Member ID, Book ID, Title, or ISBN.';
                    } else if (filtersUsed) {
                        message.textContent = 'No returned books found for the selected filters.';
                    } else {
                        message.textContent = 'No returned books available.';
                    }
                } else {
                    message.style.display = 'none';
                }
                return;
            }

            empty.style.display = 'none';
            message.style.display = 'none';

            var totalPages = Math.ceil(returnedCurrentResults.length / returnedRowsPerPage);
            if (returnedCurrentPage > totalPages) returnedCurrentPage = totalPages;

            var start = (returnedCurrentPage - 1) * returnedRowsPerPage;
            var pageRecords = returnedCurrentResults.slice(start, start + returnedRowsPerPage);

            pageRecords.forEach(function(record) {
                var row = document.createElement('tr');
                var fineClass = record.fineAmount > 0 ? 'fine' : 'no-fine';
                row.innerHTML =
                    '<td>' + escapeHtml(record.memberId) + '</td>' +
                    '<td>' + escapeHtml(record.memberName) + '</td>' +
                    '<td>' + escapeHtml(record.id) + '</td>' +
                    '<td>' + escapeHtml(record.title) + '</td>' +
                    '<td>' + escapeHtml(record.author) + '</td>' +
                    '<td>' + escapeHtml(record.isbn) + '</td>' +
                    '<td>' + escapeHtml(formatIndianDate(record.issueDate)) + '</td>' +
                    '<td>' + escapeHtml(formatIndianDate(record.dueDate)) + '</td>' +
                    '<td>' + escapeHtml(formatIndianDate(record.returnDate)) + '</td>' +
                    '<td class="' + fineClass + '">' + escapeHtml(record.fine) + '</td>' +
                    '<td>' + escapeHtml(record.payment) + '</td>';
                tbody.appendChild(row);
            });

            renderReturnedPagination(totalPages);
        }

        function renderReturnedPagination(totalPages) {
            var pagination = document.getElementById('returnedPagination');
            pagination.innerHTML = '';
            if (totalPages <= 1) return;

            var previous = document.createElement('button');
            previous.type = 'button';
            previous.textContent = 'Previous';
            previous.disabled = returnedCurrentPage === 1;
            previous.onclick = function() {
                if (returnedCurrentPage > 1) { returnedCurrentPage--; renderReturnedBooks(); }
            };
            pagination.appendChild(previous);

            for (var page = 1; page <= totalPages; page++) {
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = page;
                if (page === returnedCurrentPage) button.classList.add('active');
                button.onclick = (function(selectedPage) {
                    return function() { returnedCurrentPage = selectedPage; renderReturnedBooks(); };
                })(page);
                pagination.appendChild(button);
            }

            var next = document.createElement('button');
            next.type = 'button';
            next.textContent = 'Next';
            next.disabled = returnedCurrentPage === totalPages;
            next.onclick = function() {
                if (returnedCurrentPage < totalPages) { returnedCurrentPage++; renderReturnedBooks(); }
            };
            pagination.appendChild(next);
        }

        function searchReturnedBooks() {
            if (!validateReturnedSearch()) return;
            returnedCurrentResults = applyReturnedFiltersToResults(getReturnedSearchMatches());
            returnedCurrentPage = 1;
            if (!returnedBooksVisible) toggleReturnedBooks();
            renderReturnedBooks();
        }

        document.getElementById('returnedSearchInput').addEventListener('input', function() {
            var value = this.value.trim();
            var error = document.getElementById('returnedSearchValidation');
            error.textContent = '';
            error.style.display = 'none';
            this.classList.remove('returned-search-input-invalid');
            if (value === '') {
                returnedCurrentResults = applyReturnedFiltersToResults(returnedBooks.slice());
                returnedCurrentPage = 1;
                renderReturnedBooks();
                return;
            }
            if (value.length > 100) {
                validateReturnedSearch();
                return;
            }
            if (/^\d+$/.test(value) && value.length > 13) {
                validateReturnedSearch();
            }
        });

        document.getElementById('returnedSearchInput').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchReturnedBooks();
            }
        });

        function openReturnedFilter() { document.getElementById('returnedFilterOverlay').style.display = 'flex'; }
        function closeReturnedFilter() { document.getElementById('returnedFilterOverlay').style.display = 'none'; }
        function closeReturnedFilterOnOverlay(event) {
            if (event.target === document.getElementById('returnedFilterOverlay')) closeReturnedFilter();
        }

        function applyReturnedFilter() {
            if (!validateReturnedFilters()) return;
            if (!validateReturnedSearch()) return;
            returnedCurrentResults = applyReturnedFiltersToResults(getReturnedSearchMatches());
            returnedCurrentPage = 1;
            if (!returnedBooksVisible) toggleReturnedBooks();
            renderReturnedBooks();
            closeReturnedFilter();
        }

        function clearReturnedFilter() {
            document.getElementById('returnedCategoryFilter').value = 'all';
            document.getElementById('returnedFineFilter').value = 'all';
            document.getElementById('returnedPaymentFilter').value = 'all';
            document.getElementById('returnedSortFilter').value = 'issue_desc';
            document.getElementById('returnedSearchInput').value = '';
            document.getElementById('returnedSearchValidation').textContent = '';
            document.getElementById('returnedSearchValidation').style.display = 'none';
            document.getElementById('returnedSearchInput').classList.remove('returned-search-input-invalid');
            returnedCurrentResults = returnedBooks.slice();
            returnedCurrentPage = 1;
            if (!returnedBooksVisible) toggleReturnedBooks();
            renderReturnedBooks();
            closeReturnedFilter();
        }

        function toggleReturnedBooks() {
            returnedBooksVisible = !returnedBooksVisible;
            var content = document.getElementById('returnedBooksContent');
            var button = document.getElementById('toggleReturnedBtn');
            var card = document.getElementById('returnedBooksCard');
            var searchContainer = document.querySelector('.returned-search-container');
            content.style.display = returnedBooksVisible ? 'block' : 'none';
            if (searchContainer) {
                searchContainer.style.display = returnedBooksVisible ? 'flex' : 'none';
            }
            card.classList.toggle('collapsed', !returnedBooksVisible);
            button.textContent = returnedBooksVisible ? '-' : '+';
        }

        function openFinePaymentModal(record) {
            pendingReturnBookId = String(record.issueId);
            selectedPaymentMethod = 'upi';
            document.getElementById('fineMemberId').textContent = record.memberId;
            document.getElementById('fineMemberName').textContent = record.memberName;
            document.getElementById('fineBookId').textContent = record.id;
            document.getElementById('fineBookTitle').textContent = record.title;
            document.getElementById('fineDueDate').textContent = formatIndianDate(record.dueDate);
            document.getElementById('fineAmount').textContent = Number(record.fineAmount).toFixed(2);
            document.getElementById('payFineButton').textContent = 'Pay Fine ₹' + Number(record.fineAmount).toFixed(2);
            selectPaymentMethod('upi');
            document.getElementById('paymentModal').classList.add('show');
        }

        function selectPaymentMethod(method) {
            selectedPaymentMethod = method;
            document.getElementById('upiCard').classList.toggle('active', method === 'upi');
            document.getElementById('cashCard').classList.toggle('active', method === 'cash');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('show');
            pendingReturnBookId = null;
        }

        function closePaymentModalOnOverlay(event) {
            if (event.target === document.getElementById('paymentModal')) closePaymentModal();
        }

        function payFine() {
            if (!pendingReturnBookId) return;
            var record = issuedRecords.find(function(item) { return String(item.issueId) === pendingReturnBookId; });
            if (!record) { closePaymentModal(); return; }

            if (selectedPaymentMethod === 'upi') {
                document.getElementById('upiFineAmount').textContent = Number(record.fineAmount).toFixed(2);
                document.getElementById('upiPaidAmount').textContent = Number(record.fineAmount).toFixed(2);
                document.getElementById('upiModal').classList.add('show');
                return;
            }

            confirmFinePayment('cash');
        }

        function closeUpiModal() {
            document.getElementById('upiModal').classList.remove('show');
        }

        function closeUpiModalOnOverlay(event) {
            if (event.target === document.getElementById('upiModal')) closeUpiModal();
        }

        async function completeReturn(record, paymentMethod) {
            try {
                var response = await fetch(window.location.pathname + '?action=return_book', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ issueId: record.issueId, paymentMethod: paymentMethod || '' })
                });
                var payload = await response.json();
                if (!response.ok || !payload.success) {
                    alert(payload.message || 'The book could not be returned. Please try again.');
                    return false;
                }
                await loadRecordsFromDatabase();
                return true;
            } catch (error) {
                alert('Unable to save the return to the database. Check your PHP/MySQL connection and try again.');
                return false;
            }
        }

        async function confirmFinePayment(method) {
            if (!pendingReturnBookId) return;
            var record = issuedRecords.find(function(item) { return String(item.issueId) === pendingReturnBookId; });
            if (!record) {
                closeUpiModal();
                closePaymentModal();
                return;
            }

            var paymentLabel = method === 'upi' ? 'UPI' : 'Cash';
            var saved = await completeReturn(record, paymentLabel);
            if (!saved) return;

            closeUpiModal();
            closePaymentModal();
            alert('Fine of ₹' + Number(record.fineAmount).toFixed(2) + ' recorded as paid by ' + paymentLabel + '. Book returned successfully.');
        }

        async function returnBook(issueId) {
            var record = issuedRecords.find(function(item) { return String(item.issueId) === String(issueId); });
            if (!record) return;

            if (record.fineAmount > 0) {
                openFinePaymentModal(record);
                return;
            }

            var confirmed = confirm('Are you sure you want to return this book?');
            if (!confirmed) return;

            var saved = await completeReturn(record, '');
            if (saved) alert('Book returned successfully!');
        }

        async function loadRecordsFromDatabase() {
            var response = await fetch(window.location.pathname + '?action=list_records', {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            var payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Could not load library records.');
            }

            issuedRecords = Array.isArray(payload.issuedRecords) ? payload.issuedRecords : [];
            returnedBooks = Array.isArray(payload.returnedBooks) ? payload.returnedBooks : [];
            var settings = payload.settings || {};

            document.getElementById('upiIdValue').textContent = settings.upiId || 'Not configured';
            document.getElementById('upiNumberValue').textContent = settings.number || 'Not configured';
            var qrImage = document.getElementById('upiQrImage');
            if (settings.upiQrData) {
                qrImage.src = 'data:image/jpeg;base64,' + settings.upiQrData;
                qrImage.alt = 'Library UPI QR code';
                qrImage.style.display = 'block';
            } else {
                qrImage.removeAttribute('src');
                qrImage.alt = 'UPI QR code not configured in library settings';
                qrImage.style.display = 'none';
            }

            currentResults = applyFiltersToResults(getSearchMatches());
            currentPage = Math.min(currentPage, Math.max(1, Math.ceil(currentResults.length / rowsPerPage)));
            renderResults();

            returnedCurrentResults = applyReturnedFiltersToResults(getReturnedSearchMatches());
            returnedCurrentPage = Math.min(returnedCurrentPage, Math.max(1, Math.ceil(returnedCurrentResults.length / returnedRowsPerPage)));
            renderReturnedBooks();
        }

        /* Initial data is fetched from PHP/MySQL; there are no embedded demo records. */
        loadRecordsFromDatabase().catch(function(error) {
            document.getElementById('emptyState').style.display = 'block';
            document.getElementById('emptyState').textContent = 'Could not load issued books from the database. Open this page through XAMPP and check Connection/db.php.';
            document.getElementById('bookTableBody').innerHTML = '';
            document.getElementById('pagination').innerHTML = '';
            document.getElementById('returnedEmptyState').style.display = 'block';
            document.getElementById('returnedEmptyState').textContent = 'Could not load returned books from the database. Check your PHP/MySQL connection.';
            document.getElementById('returnedBooksTableBody').innerHTML = '';
            document.getElementById('returnedPagination').innerHTML = '';
            console.error(error);
        });
    </script>
</body>
</html>
