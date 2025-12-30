<?php
// =========================================
// 1. 數據庫連接配置
// =========================================
session_start();

$db_host = "localhost";
$db_user = "root";      // 請根據您的設置修改
$db_pass = "";          // 請根據您的設置修改
$db_name = "library_system";

// 創建數據庫連接
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// 檢查連接
if ($conn->connect_error) {
    die("數據庫連接失敗: " . $conn->connect_error);
}

// 如果數據庫不存在，創建它
$conn->query("CREATE DATABASE IF NOT EXISTS $db_name");
$conn->select_db($db_name);

// =========================================
// 2. 創建表格（如果不存在）
// =========================================
$tables = [
    "CREATE TABLE IF NOT EXISTS books (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        author VARCHAR(255) NOT NULL,
        category ENUM('Scientific', 'Literature', 'Technology', 'Philosophy', 'Arts') NOT NULL,
        status ENUM('Available', 'Issued') DEFAULT 'Available',
        borrower VARCHAR(255) DEFAULT NULL,
        due_date DATE DEFAULT NULL,
        added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS loan_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        book_id INT NOT NULL,
        book_title VARCHAR(255) NOT NULL,
        requester VARCHAR(255) NOT NULL,
        request_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS system_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        event_type VARCHAR(50) NOT NULL,
        description TEXT NOT NULL,
        user_context VARCHAR(255) DEFAULT 'System'
    )"
];

foreach ($tables as $sql) {
    $conn->query($sql);
}

// =========================================
// 3. 插入示例數據（如果表格是空的）
// =========================================
$checkBooks = $conn->query("SELECT COUNT(*) as count FROM books");
$row = $checkBooks->fetch_assoc();
if ($row['count'] == 0) {
    $sampleBooks = [
        "(101, 'The Pragmatic Programmer', 'Andrew Hunt', 'Technology', 'Available', NULL, NULL)",
        "(102, 'Sapiens: A Brief History', 'Yuval Noah Harari', 'Philosophy', 'Issued', 'Alexander Pierce', '2025-12-15')",
        "(103, 'Dune', 'Frank Herbert', 'Literature', 'Available', NULL, NULL)",
        "(104, 'Clean Architecture', 'Robert C. Martin', 'Technology', 'Available', NULL, NULL)",
        "(105, 'Meditations', 'Marcus Aurelius', 'Philosophy', 'Issued', 'Alexander Pierce', '2025-12-10')"
    ];
    
    foreach ($sampleBooks as $book) {
        $conn->query("INSERT INTO books (id, title, author, category, status, borrower, due_date) VALUES $book");
    }
}

// =========================================
// 4. 處理POST請求（來自AJAX）
// =========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知操作'];
    
    switch ($action) {
        case 'add_book':
            $title = $conn->real_escape_string($_POST['title']);
            $author = $conn->real_escape_string($_POST['author']);
            $category = $conn->real_escape_string($_POST['category']);
            
            $sql = "INSERT INTO books (title, author, category, status) VALUES ('$title', '$author', '$category', 'Available')";
            
            if ($conn->query($sql)) {
                $response = ['success' => true, 'message' => '書籍添加成功'];
                logEvent("BOOK_ADDED", "添加新書籍: $title");
            } else {
                $response = ['success' => false, 'message' => '添加失敗: ' . $conn->error];
            }
            break;
            
        case 'request_loan':
            $book_id = intval($_POST['book_id']);
            $book_result = $conn->query("SELECT title FROM books WHERE id = $book_id");
            if ($book_row = $book_result->fetch_assoc()) {
                $sql = "INSERT INTO loan_requests (book_id, book_title, requester) VALUES ($book_id, '{$book_row['title']}', 'Alexander Pierce')";
                if ($conn->query($sql)) {
                    $response = ['success' => true, 'message' => 'Accepted'];
                    logEvent("LOAN_REQUEST", "Requested: {$book_row['title']}");
                }
            }
            break;
            
        case 'approve_request':
            $request_id = intval($_POST['request_id']);
            $request_result = $conn->query("SELECT * FROM loan_requests WHERE id = $request_id");
            if ($request_row = $request_result->fetch_assoc()) {
                // 更新書籍狀態
                $due_date = date('Y-m-d', strtotime('+14 days'));
                $conn->query("UPDATE books SET status = 'Issued', borrower = 'Alexander Pierce', due_date = '$due_date' WHERE id = {$request_row['book_id']}");
                
                // 更新請求狀態
                $conn->query("UPDATE loan_requests SET status = 'Approved' WHERE id = $request_id");
                
                $response = ['success' => true, 'message' => 'Approved'];
                logEvent("REQUEST_APPROVED", "message:success {$request_row['book_title']}");
            }
            break;
            
        case 'process_return':
            $book_id = intval($_POST['book_id']);
            $conn->query("UPDATE books SET status = 'Available', borrower = NULL, due_date = NULL WHERE id = $book_id");
            $response = ['success' => true, 'message' => '書籍歸還成功'];
            logEvent("BOOK_RETURNED", "書籍歸還: ID $book_id");
            break;
            
        case 'process_manual_issue':
            $book_id = intval($_POST['book_id']);
            $borrower = $conn->real_escape_string($_POST['borrower']);
            $due_date = date('Y-m-d', strtotime('+14 days'));
            
            $conn->query("UPDATE books SET status = 'Issued', borrower = '$borrower', due_date = '$due_date' WHERE id = $book_id");
            $response = ['success' => true, 'message' => "書籍已借給 $borrower"];
            logEvent("MANUAL_ISSUE", "手動借出書籍給: $borrower");
            break;
            
        case 'get_stats':
            $total = $conn->query("SELECT COUNT(*) as count FROM books")->fetch_assoc()['count'];
            $loaned = $conn->query("SELECT COUNT(*) as count FROM books WHERE status = 'Issued'")->fetch_assoc()['count'];
            $pending = $conn->query("SELECT COUNT(*) as count FROM loan_requests WHERE status = 'Pending'")->fetch_assoc()['count'];
            
            $response = [
                'success' => true,
                'stats' => [
                    'total' => $total,
                    'loaned' => $loaned,
                    'pending' => $pending,
                    'fines' => '0'
                ]
            ];
            break;
            
        case 'reset_system':
            $conn->query("DELETE FROM books");
            $conn->query("DELETE FROM loan_requests");
            $conn->query("DELETE FROM system_logs");
            
            // 重新插入示例數據
            foreach ($sampleBooks as $book) {
                $conn->query("INSERT INTO books (id, title, author, category, status, borrower, due_date) VALUES $book");
            }
            
            $response = ['success' => true, 'message' => '系統數據已重置'];
            logEvent("SYSTEM_RESET", "系統數據已清除");
            break;
    }
    
    echo json_encode($response);
    exit;
}

// =========================================
// 5. 輔助函數
// =========================================
function logEvent($type, $description) {
    global $conn;
    $desc = $conn->real_escape_string($description);
    $conn->query("INSERT INTO system_logs (event_type, description) VALUES ('$type', '$desc')");
}

function getBooks() {
    global $conn;
    $result = $conn->query("SELECT * FROM books ORDER BY id DESC");
    $books = [];
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
    return $books;
}

function getLoanRequests() {
    global $conn;
    $result = $conn->query("SELECT * FROM loan_requests WHERE status = 'Pending' ORDER BY request_time DESC");
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    return $requests;
}

function getSystemLogs() {
    global $conn;
    $result = $conn->query("SELECT * FROM system_logs ORDER BY timestamp DESC LIMIT 8");
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    return $logs;
}

// =========================================
// 6. 獲取數據用於頁面加載
// =========================================
$books = getBooks();
$loanRequests = getLoanRequests();
$systemLogs = getSystemLogs();

$totalBooks = count($books);
$loanedBooks = count(array_filter($books, function($book) {
    return $book['status'] === 'Issued';
}));
$pendingRequests = count($loanRequests);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library System - PHP MySQL Edition</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --parchment: #fdf5e6;
            --parchment-border: #d3c4a0;
            --table-header: #5d4037;
            --blue-grad: linear-gradient(135deg, #4a90e2, #357abd);
            --green-grad: linear-gradient(135deg, #2ecc71, #27ae60);
            --yellow-grad: linear-gradient(135deg, #f1c40f, #f39c12);
            --orange-grad: linear-gradient(135deg, #e67e22, #d35400);
            --purple-grad: linear-gradient(135deg, #9b59b6, #8e44ad);
            --red-grad: linear-gradient(135deg, #e74c3c, #c0392b);
            --dark-bg: #2c3e50;
        }

        * { box-sizing: border-box; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }

        body {
            margin: 0; padding: 20px;
            min-height: 100vh;
            background: linear-gradient(rgba(0,0,0,0.85), rgba(0,0,0,0.85)), 
                        url('https://images.unsplash.com/photo-1507842217343-583bb7270b66?auto=format&fit=crop&q=80&w=2000');
            background-size: cover; background-position: center; background-attachment: fixed;
            display: flex; flex-direction: column; align-items: center; color: #333;
        }

        h1 { color: white; text-shadow: 0 4px 8px rgba(0,0,0,0.5); margin-bottom: 25px; font-weight: 300; letter-spacing: 1px; }

        /* Navigation Bar */
        .nav-bar { display: flex; gap: 20px; margin-bottom: 40px; flex-wrap: wrap; justify-content: center; }
        .nav-btn {
            padding: 14px 35px; border: none; border-radius: 50px;
            color: white; font-weight: 600; cursor: pointer; font-size: 1rem;
            box-shadow: 0 6px 15px rgba(0,0,0,0.4); transition: all 0.3s ease;
            display: flex; align-items: center; gap: 12px;
        }
        .nav-btn:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.5); }
        .btn-admin { background: var(--blue-grad); }
        .btn-user { background: var(--green-grad); }
        .btn-catalog { background: var(--yellow-grad); }

        /* Panels */
        .panel { 
            display: none; width: 100%; max-width: 1000px;
            background: var(--parchment); padding: 40px;
            border-radius: 15px; border: 1px solid var(--parchment-border);
            box-shadow: inset 0 0 100px rgba(139, 69, 19, 0.05), 0 30px 60px rgba(0,0,0,0.7);
            animation: fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .panel.active { display: block; }

        h2 { border-bottom: 3px solid #d3c4a0; padding-bottom: 15px; color: #5d4037; margin-top: 0; font-variant: small-caps; }

        /* Sub-views */
        .sub-view { display: none; }
        .sub-view.active { display: block; animation: slideIn 0.4s ease; }

        /* Grids and Layouts */
        .menu-grid { display: grid; grid-template-columns: 1fr; gap: 15px; }
        .grid-2 { grid-template-columns: 1fr 1fr; } 

        .menu-item {
            width: 100%; padding: 18px 25px; border: none; border-radius: 10px;
            color: white; font-size: 1.05rem; font-weight: 600; cursor: pointer;
            display: flex; justify-content: space-between; align-items: center;
            transition: all 0.2s;
        }
        .menu-item:hover { filter: brightness(1.1); transform: scale(1.01); }

        /* Traditional Table Styling - Strictly Rows/Columns */
        .table-container { width: 100%; overflow-x: auto; margin-top: 20px; background: white; border-radius: 8px; border: 2px solid var(--parchment-border); }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th { 
            background: var(--table-header); color: white; padding: 18px 15px; 
            text-align: left; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px;
            border: 1px solid #4e342e;
        }
        td { 
            padding: 15px; border: 1px solid #e0e0e0; font-size: 0.95rem; 
            vertical-align: middle; color: #444; 
        }
        tr:nth-child(even) { background-color: #fcfaf5; }
        tr:hover { background: #f1f8ff; }
        
        .status-pill { padding: 6px 14px; border-radius: 20px; font-size: 0.7rem; font-weight: 800; color: white; text-transform: uppercase; }
        .status-issued { background: #e74c3c; }
        .status-available { background: #2ecc71; }
        .status-pending { background: #f1c40f; }

        /* Form Controls */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #5d4037; }
        input, select {
            width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px;
            background: white; font-size: 1rem;
        }
        input:focus { outline: 2px solid #4a90e2; border-color: transparent; }

        .action-btn { padding: 10px 18px; border: none; border-radius: 6px; color: white; cursor: pointer; transition: 0.2s; font-weight: 600; font-size: 0.85rem; }
        .action-btn:active { transform: scale(0.95); }
        .back-btn { background: #546e7a; margin-bottom: 20px; color: white; border-radius: 4px; display: inline-flex; align-items: center; gap: 8px; border: none; padding: 10px 20px; cursor: pointer; }

        /* Reports Enhancement */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; border-top: 4px solid var(--table-header); text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .stat-card span { display: block; font-size: 2rem; font-weight: 800; color: #2c3e50; }
        .stat-card label { font-size: 0.8rem; color: #7f8c8d; font-weight: 600; }

        /* ID Card System */
        .id-card-wrap { display: flex; flex-direction: column; align-items: center; gap: 15px; margin: 30px 0; }
        .id-card {
            width: 380px; height: 220px; background: linear-gradient(135deg, #1a2a6c, #b21f1f, #fdbb2d);
            color: white; padding: 25px; border-radius: 20px; position: relative;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3); overflow: hidden;
        }
        .id-card::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: rgba(255,255,255,0.1); transform: rotate(30deg); }
        .id-card h4 { margin: 0; font-size: 0.9rem; letter-spacing: 3px; opacity: 0.9; }
        .user-name { font-size: 1.8rem; margin: 20px 0 5px; font-weight: bold; }
        .user-info { font-size: 0.85rem; opacity: 0.8; }
        .qr-code { position: absolute; right: 25px; bottom: 25px; font-size: 4rem; opacity: 0.6; }

        /* Toast Notifications */
        #notification-container { position: fixed; top: 30px; right: 30px; z-index: 10000; display: flex; flex-direction: column; gap: 15px; }
        .toast {
            background: white; padding: 18px 25px; border-radius: 10px; min-width: 320px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); border-left: 8px solid #3498db;
            display: flex; align-items: center; gap: 20px;
            animation: toastIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }
        .toast.success { border-left-color: #2ecc71; }
        .toast.error { border-left-color: #e74c3c; }

        /* Charts */
        .chart-container { background: white; padding: 20px; border-radius: 10px; margin-top: 20px; height: 300px; }

        /* Search Styles */
        .search-box { position: relative; margin-bottom: 25px; }
        .search-box i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #999; }
        .search-box input { padding-left: 45px; }

        @keyframes toastIn { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }

        /* Background Utils */
        .bg-blue { background: var(--blue-grad); }
        .bg-green { background: var(--green-grad); }
        .bg-yellow { background: var(--yellow-grad); }
        .bg-orange { background: var(--orange-grad); }
        .bg-purple { background: var(--purple-grad); }
        .bg-red { background: var(--red-grad); }
        
        /* Database Status */
        .db-status {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: rgba(46, 204, 113, 0.9);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

    <div id="notification-container"></div>
    <div class="db-status">
        <i class="fas fa-database"></i> MySQL Connected
    </div>

    <h1><i class="fas fa-university"></i> METROPOLITAN LIBRARY SYSTEM</h1>

    <div class="nav-bar">
        <button class="nav-btn btn-admin" onclick="showPanel('admin-panel')"><i class="fas fa-shield-alt"></i> Management</button>
        <button class="nav-btn btn-user" onclick="showPanel('user-panel')"><i class="fas fa-user-circle"></i> Member Area</button>
        <button class="nav-btn btn-catalog" onclick="showPanel('catalog-panel')"><i class="fas fa-atlas"></i> Digital Catalog</button>
    </div>

    <div id="admin-panel" class="panel active">
        <div id="admin-menu" class="sub-view active">
            <h2>Administrative Dashboard</h2>
            
            <div class="stats-row">
                <div class="stat-card"><label>Total Books</label><span id="stat-total"><?php echo $totalBooks; ?></span></div>
                <div class="stat-card"><label>On Loan</label><span id="stat-loan"><?php echo $loanedBooks; ?></span></div>
                <div class="stat-card"><label>Pending Reqs</label><span id="stat-req" style="color:var(--red-grad)"><?php echo $pendingRequests; ?></span></div>
                <div class="stat-card"><label>Fines Due</label><span id="stat-fines">$0</span></div>
            </div>

            <div class="menu-grid grid-2">
                <button class="menu-item bg-blue" onclick="showSubView('admin-add-book')">
                    <span><i class="fas fa-plus-square"></i> Catalog New Entry</span> <i class="fas fa-arrow-right"></i>
                </button>
                <button class="menu-item bg-orange" onclick="showSubView('admin-requests-view'); loadRequests();">
                    <span><i class="fas fa-bell"></i> Loan Requests <b id="req-badge"><?php echo $pendingRequests > 0 ? "($pendingRequests)" : ""; ?></b></span> <i class="fas fa-arrow-right"></i>
                </button>
                <button class="menu-item bg-green" onclick="showSubView('admin-issue-view'); loadBooks();">
                    <span><i class="fas fa-exchange-alt"></i> Outbound Logistics</span> <i class="fas fa-arrow-right"></i>
                </button>
                <button class="menu-item bg-yellow" onclick="showSubView('admin-return-view'); loadReturns();">
                    <span><i class="fas fa-undo"></i> Inbound Returns</span> <i class="fas fa-arrow-right"></i>
                </button>
                <button class="menu-item bg-purple" onclick="showSubView('admin-reports-view'); loadReports();">
                    <span><i class="fas fa-chart-pie"></i> Analytics & Logs</span> <i class="fas fa-arrow-right"></i>
                </button>
                <button class="menu-item bg-red" onclick="resetSystemData()">
                    <span><i class="fas fa-trash-alt"></i> Clear All Data</span> <i class="fas fa-exclamation-triangle"></i>
                </button>
            </div>
        </div>

        <div id="admin-add-book" class="sub-view">
            <button class="back-btn" onclick="showSubView('admin-menu')"><i class="fas fa-chevron-left"></i> Return to Menu</button>
            <h2 id="form-mode-title">Register New Asset</h2>
            <div class="form-group">
                <label>Book Title / Asset Name</label>
                <input type="text" id="input-title" placeholder="e.g. Advanced Quantum Mechanics">
            </div>
            <div class="form-group">
                <label>Primary Author</label>
                <input type="text" id="input-author" placeholder="Full Name">
            </div>
            <div class="form-group">
                <label>Classification</label>
                <select id="input-category">
                    <option value="Scientific">Scientific</option>
                    <option value="Literature">Literature</option>
                    <option value="Technology">Technology</option>
                    <option value="Philosophy">Philosophy</option>
                    <option value="Arts">Arts</option>
                </select>
            </div>
            <button class="menu-item bg-blue" style="justify-content:center" onclick="handleBookSave()">
                <i class="fas fa-save"></i> &nbsp; Commit to Database
            </button>
        </div>

        <div id="admin-requests-view" class="sub-view">
            <button class="back-btn" onclick="showSubView('admin-menu')"><i class="fas fa-chevron-left"></i> Return to Menu</button>
            <h2>Incoming Loan Requests</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Req ID</th>
                            <th>Asset Title</th>
                            <th>Applicant</th>
                            <th>Time Received</th>
                            <th>Decision</th>
                        </tr>
                    </thead>
                    <tbody id="table-requests-body">
                        <?php foreach ($loanRequests as $index => $request): ?>
                        <tr id="request-<?php echo $request['id']; ?>">
                            <td>REQ-<?php echo $request['id']; ?></td>
                            <td><?php echo htmlspecialchars($request['book_title']); ?></td>
                            <td><?php echo htmlspecialchars($request['requester']); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($request['request_time'])); ?></td>
                            <td><button class="action-btn bg-green" onclick="approveRequest(<?php echo $request['id']; ?>)">Approve</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="admin-return-view" class="sub-view">
            <button class="back-btn" onclick="showSubView('admin-menu')"><i class="fas fa-chevron-left"></i> Return to Menu</button>
            <h2>Process Returns (Active Loans)</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Book ID</th>
                            <th>Borrower</th>
                            <th>Title</th>
                            <th>Due Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="table-returns-body">
                        <?php foreach ($books as $book): ?>
                            <?php if ($book['status'] === 'Issued'): ?>
                            <tr id="return-<?php echo $book['id']; ?>">
                                <td>#<?php echo $book['id']; ?></td>
                                <td><b><?php echo htmlspecialchars($book['borrower']); ?></b></td>
                                <td><?php echo htmlspecialchars($book['title']); ?></td>
                                <td><span style="color:red"><?php echo $book['due_date']; ?></span></td>
                                <td><button class="action-btn bg-yellow" onclick="processReturn(<?php echo $book['id']; ?>)">Receive</button></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="admin-issue-view" class="sub-view">
            <button class="back-btn" onclick="showSubView('admin-menu')"><i class="fas fa-chevron-left"></i> Return to Menu</button>
            <h2>Manual Issuance Portal</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Status</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Issue Action</th>
                        </tr>
                    </thead>
                    <tbody id="table-issue-body">
                        <?php foreach ($books as $book): ?>
                        <tr id="book-<?php echo $book['id']; ?>">
                            <td><?php echo $book['id']; ?></td>
                            <td><span class="status-pill <?php echo $book['status'] === 'Available' ? 'status-available' : 'status-issued'; ?>"><?php echo $book['status']; ?></span></td>
                            <td><?php echo htmlspecialchars($book['title']); ?></td>
                            <td><?php echo htmlspecialchars($book['author']); ?></td>
                            <td>
                                <button class="action-btn bg-green" <?php echo $book['status'] !== 'Available' ? 'disabled style="opacity:0.5"' : ''; ?> onclick="processManualIssue(<?php echo $book['id']; ?>)">
                                    Dispatch
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="admin-reports-view" class="sub-view">
            <button class="back-btn" onclick="showSubView('admin-menu')"><i class="fas fa-chevron-left"></i> Return to Menu</button>
            <h2>System Activity Analytics</h2>
            <div class="chart-container"><canvas id="activityChart"></canvas></div>
            <h3 style="margin-top:30px">Security Audit Log</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Event Type</th>
                            <th>Details</th>
                            <th>User Context</th>
                        </tr>
                    </thead>
                    <tbody id="table-logs-body">
                        <?php foreach ($systemLogs as $log): ?>
                        <tr>
                            <td><small><?php echo date('H:i:s', strtotime($log['timestamp'])); ?></small></td>
                            <td><b><?php echo htmlspecialchars($log['event_type']); ?></b></td>
                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                            <td><?php echo htmlspecialchars($log['user_context']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="user-panel" class="panel">
        <div id="user-menu" class="sub-view active">
            <h2>Member Dashboard</h2>
            <div class="id-card-wrap">
                <div id="id-card-canvas" class="id-card">
                    <h4>METRO LIBRARY PASS</h4>
                    <div class="user-name">Alexander Pierce</div>
                    <div class="user-info">Member ID: MLP-9920-X1</div>
                    <div class="user-info" style="margin-top:10px">Status: Premium Subscriber</div>
                    <i class="fas fa-qrcode qr-code"></i>
                </div>
                <button class="action-btn bg-purple" onclick="downloadIDCard()"><i class="fas fa-cloud-download-alt"></i> Export Digital Pass</button>
            </div>

            <div class="menu-grid">
                <button class="menu-item bg-green" onclick="showPanel('catalog-panel')">
                    <span><i class="fas fa-search"></i> Browse Collections</span> <i class="fas fa-external-link-alt"></i>
                </button>
                <button class="menu-item bg-orange" onclick="showSubView('user-loans-view'); renderUserLoans();">
                    <span><i class="fas fa-book"></i> Current Personal Loans</span> <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>

        <div id="user-loans-view" class="sub-view">
            <button class="back-btn" onclick="showSubView('user-menu')"><i class="fas fa-chevron-left"></i> Dashboard</button>
            <h2>Your Account: Details & Fines</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Ref ID</th>
                            <th>Book Title</th>
                            <th>Due Date</th>
                            <th>Fine</th>
                            <th>Slip Action</th>
                        </tr>
                    </thead>
                    <tbody id="table-user-loans">
                        <?php 
                        $userLoans = array_filter($books, function($book) {
                            return $book['borrower'] === 'Alexander Pierce' && $book['status'] === 'Issued';
                        });
                        foreach ($userLoans as $book): ?>
                        <tr>
                            <td>REF-<?php echo $book['id']; ?>X</td>
                            <td><?php echo htmlspecialchars($book['title']); ?></td>
                            <td><?php echo $book['due_date']; ?></td>
                            <td><b>$0.00</b></td>
                            <td><button class="action-btn bg-purple" onclick="printReceipt(<?php echo $book['id']; ?>)">Print Receipt</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="catalog-panel" class="panel">
        <div id="catalog-main" class="sub-view active">
            <h2>Digital Library Catalog</h2>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="cat-search" placeholder="Search by title, author, or category..." onkeyup="filterCatalog()">
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Preview</th>
                            <th>Title & Author</th>
                            <th>Classification</th>
                            <th>Availability</th>
                            <th>Procurement</th>
                        </tr>
                    </thead>
                    <tbody id="table-catalog-body">
                        <?php foreach ($books as $book): ?>
                        <tr>
                            <td><i class="fas fa-book" style="color:#d3c4a0"></i></td>
                            <td><strong><?php echo htmlspecialchars($book['title']); ?></strong><br><small><?php echo htmlspecialchars($book['author']); ?></small></td>
                            <td><?php echo $book['category']; ?></td>
                            <td><span class="status-pill <?php echo $book['status'] === 'Available' ? 'status-available' : 'status-issued'; ?>"><?php echo $book['status']; ?></span></td>
                            <td>
                                <button class="action-btn bg-blue" onclick="requestLoan(<?php echo $book['id']; ?>)" <?php echo $book['status'] !== 'Available' ? 'disabled style="opacity:0.5"' : ''; ?>>
                                    Request Digital Loan
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        /* =========================================
           1. AJAX 功能函數
           ========================================= */
        function sendRequest(action, data = {}) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('action', action);
                
                for (const key in data) {
                    formData.append(key, data[key]);
                }
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        resolve(result);
                    } else {
                        reject(result.message);
                    }
                })
                .catch(error => {
                    reject('Network error: ' + error);
                });
            });
        }

        /* =========================================
           2. 導航邏輯
           ========================================= */
        function showPanel(id) {
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            document.getElementById(id).classList.add('active');
        }

        function showSubView(id) {
            const panel = document.getElementById(id).parentElement;
            panel.querySelectorAll('.sub-view').forEach(v => v.classList.remove('active'));
            document.getElementById(id).classList.add('active');
            if (id === 'admin-reports-view') initChart();
        }

        function notify(msg, type = 'success') {
            const container = document.getElementById('notification-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> <div>${msg}</div>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 4000);
        }

        /* =========================================
           3. 核心功能
           ========================================= */
        async function handleBookSave() {
            const title = document.getElementById('input-title').value;
            const author = document.getElementById('input-author').value;
            const category = document.getElementById('input-category').value;
            
            if (!title || !author) {
                notify("Fields cannot be empty", "error");
                return;
            }
            
            try {
                const result = await sendRequest('add_book', { title, author, category });
                notify(result.message);
                showSubView('admin-menu');
                // 刷新頁面以顯示新數據
                setTimeout(() => location.reload(), 1000);
            } catch (error) {
                notify(error, "error");
            }
        }

        async function requestLoan(bookId) {
            try {
                const result = await sendRequest('request_loan', { book_id: bookId });
                notify(result.message);
                // 刷新頁面
                setTimeout(() => location.reload(), 1000);
            } catch (error) {
                notify(error, "error");
            }
        }

        async function approveRequest(requestId) {
            try {
                const result = await sendRequest('approve_request', { request_id: requestId });
                notify(result.message);
                // 移除請求行
                document.getElementById(`request-${requestId}`).remove();
                // 刷新統計
                updateStats();
            } catch (error) {
                notify(error, "error");
            }
        }

        async function processReturn(bookId) {
            try {
                const result = await sendRequest('process_return', { book_id: bookId });
                notify(result.message);
                // 移除返回行
                document.getElementById(`return-${bookId}`).remove();
                // 刷新統計
                updateStats();
            } catch (error) {
                notify(error, "error");
            }
        }

        async function processManualIssue(bookId) {
            const borrower = prompt("Member Name:");
            if (!borrower) return;
            
            try {
                const result = await sendRequest('process_manual_issue', { 
                    book_id: bookId, 
                    borrower: borrower 
                });
                notify(result.message);
                // 更新按鈕狀態
                const button = document.querySelector(`#book-${bookId} button`);
                button.disabled = true;
                button.style.opacity = '0.5';
                // 更新狀態標籤
                const statusSpan = document.querySelector(`#book-${bookId} .status-pill`);
                statusSpan.className = 'status-pill status-issued';
                statusSpan.textContent = 'Issued';
            } catch (error) {
                notify(error, "error");
            }
        }

        async function updateStats() {
            try {
                const result = await sendRequest('get_stats');
                const stats = result.stats;
                
                document.getElementById('stat-total').innerText = stats.total;
                document.getElementById('stat-loan').innerText = stats.loaned;
                document.getElementById('stat-req').innerText = stats.pending;
                
                const badge = document.getElementById('req-badge');
                badge.innerText = stats.pending > 0 ? `(${stats.pending})` : '';
                badge.style.display = stats.pending > 0 ? 'inline' : 'none';
            } catch (error) {
                console.error('Failed to update stats:', error);
            }
        }

        async function resetSystemData() {
            if (confirm("Wipe all data? This cannot be undone!")) {
                try {
                    const result = await sendRequest('reset_system');
                    notify(result.message);
                    setTimeout(() => location.reload(), 1500);
                } catch (error) {
                    notify(error, "error");
                }
            }
        }

        /* =========================================
           4. 其他功能
           ========================================= */
        function printReceipt(bookId) {
            const bookRow = document.querySelector(`#table-user-loans tr:has(button[onclick*="${bookId}"])`);
            const title = bookRow.children[1].textContent;
            const dueDate = bookRow.children[2].textContent;
            
            const content = `
                METROPOLITAN LIBRARY SYSTEM
                ---------------------------
                LOAN RECEIPT
                Date: ${new Date().toLocaleDateString()}
                Asset: ${title}
                Borrower: Alexander Pierce
                Due Date: ${dueDate}
                ---------------------------
                Please return on time to avoid fines.
            `;
            
            const blob = new Blob([content], { type: 'text/plain' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `Receipt_${bookId}.txt`;
            link.click();
            notify("Receipt Generated Successfully");
        }

        function filterCatalog() {
            const q = document.getElementById('cat-search').value.toLowerCase();
            const rows = document.querySelectorAll('#table-catalog-body tr');
            rows.forEach(r => r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none');
        }

        function downloadIDCard() {
            html2canvas(document.getElementById('id-card-canvas')).then(canvas => {
                const link = document.createElement('a');
                link.download = 'Library_Pass.png';
                link.href = canvas.toDataURL();
                link.click();
                notify("Exporting Digital Pass...");
            });
        }

        function initChart() {
            const ctx = document.getElementById('activityChart').getContext('2d');
            if (window.chartInstance) {
                window.chartInstance.destroy();
            }
            window.chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{ 
                        label: 'Circulation', 
                        data: [5, 12, 8, 15, 10, 2, 7], 
                        borderColor: '#357abd', 
                        tension: 0.4, 
                        fill: true, 
                        backgroundColor: 'rgba(74,144,226,0.1)' 
                    }]
                },
                options: { 
                    maintainAspectRatio: false,
                    responsive: true
                }
            });
        }

        /* =========================================
           5. 頁面加載函數
           ========================================= */
        function loadRequests() {
            // 請求已經在PHP中加載
            console.log('Requests loaded from database');
        }

        function loadBooks() {
            // 書籍已經在PHP中加載
            console.log('Books loaded from database');
        }

        function loadReturns() {
            // 返回數據已經在PHP中加載
            console.log('Returns loaded from database');
        }

        function loadReports() {
            // 報告數據已經在PHP中加載
            console.log('Reports loaded from database');
        }

        function renderUserLoans() {
            // 用戶貸款已經在PHP中加載
            console.log('User loans loaded from database');
        }

        /* =========================================
           6. 頁面初始化
           ========================================= */
        window.onload = () => {
            notify("System Online - Connected to MySQL Database");
            updateStats();
        };
    </script>
</body>
</html>