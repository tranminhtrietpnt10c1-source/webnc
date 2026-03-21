<<<<<<< Updated upstream
<?php
// imports.php
session_start();
require_once 'db_connection.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: adminlogin.html');
    exit;
}

// Xử lý AJAX request (nếu có action)
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];
    
    try {
        switch ($action) {
            case 'list':
                // Lấy danh sách phiếu nhập
                $search = $_GET['search'] ?? '';
                $date = $_GET['date'] ?? '';
                $status = $_GET['status'] ?? '';
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;
                
                $sql = "SELECT i.import_id, i.import_date, i.status,
                               COUNT(d.detail_id) AS product_count,
                               SUM(d.quantity) AS total_quantity,
                               SUM(d.total) AS total_value
                        FROM imports i
                        LEFT JOIN import_details d ON i.import_id = d.import_id
                        WHERE 1=1";
                $params = [];
                
                if ($search) {
                    $sql .= " AND (i.import_id LIKE :search OR EXISTS (
                                SELECT 1 FROM import_details d2
                                JOIN products p ON d2.product_id = p.product_id
                                WHERE d2.import_id = i.import_id AND p.product_name LIKE :search2
                            ))";
                    $params[':search'] = "%$search%";
                    $params[':search2'] = "%$search%";
                }
                if ($date) {
                    $sql .= " AND i.import_date = :date";
                    $params[':date'] = $date;
                }
                if ($status && in_array($status, ['pending', 'completed'])) {
                    $sql .= " AND i.status = :status";
                    $params[':status'] = $status;
                }
                
                $sql .= " GROUP BY i.import_id ORDER BY i.import_date DESC, i.import_id DESC LIMIT :limit OFFSET :offset";
                $params[':limit'] = $limit;
                $params[':offset'] = $offset;
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    if (is_int($val)) $stmt->bindValue($key, $val, PDO::PARAM_INT);
                    else $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $imports = $stmt->fetchAll();
                
                // Đếm tổng số bản ghi
                $countSql = "SELECT COUNT(DISTINCT i.import_id) as total FROM imports i
                             LEFT JOIN import_details d ON i.import_id = d.import_id
                             WHERE 1=1";
                $countParams = [];
                if ($search) {
                    $countSql .= " AND (i.import_id LIKE :search OR EXISTS (
                                SELECT 1 FROM import_details d2
                                JOIN products p ON d2.product_id = p.product_id
                                WHERE d2.import_id = i.import_id AND p.product_name LIKE :search2
                            ))";
                    $countParams[':search'] = "%$search%";
                    $countParams[':search2'] = "%$search%";
                }
                if ($date) {
                    $countSql .= " AND i.import_date = :date";
                    $countParams[':date'] = $date;
                }
                if ($status && in_array($status, ['pending', 'completed'])) {
                    $countSql .= " AND i.status = :status";
                    $countParams[':status'] = $status;
                }
                $countStmt = $pdo->prepare($countSql);
                foreach ($countParams as $key => $val) $countStmt->bindValue($key, $val);
                $countStmt->execute();
                $total = $countStmt->fetch()['total'];
                $totalPages = ceil($total / $limit);
                
                echo json_encode([
                    'success' => true,
                    'data' => $imports,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'total_records' => $total
                    ]
                ]);
                break;
                
            case 'get':
                // Lấy thông tin một phiếu nhập (cho edit hoặc detail)
                $import_id = (int)($_GET['id'] ?? 0);
                if (!$import_id) throw new Exception('Missing import ID');
                
                $stmt = $pdo->prepare("SELECT import_id, import_date, status FROM imports WHERE import_id = ?");
                $stmt->execute([$import_id]);
                $import = $stmt->fetch();
                if (!$import) throw new Exception('Import not found');
                
                $stmt = $pdo->prepare("SELECT d.detail_id, d.product_id, d.quantity, d.price, d.total, p.product_name
                                       FROM import_details d
                                       JOIN products p ON d.product_id = p.product_id
                                       WHERE d.import_id = ?");
                $stmt->execute([$import_id]);
                $details = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'import' => $import,
                    'details' => $details
                ]);
                break;
                
            case 'add':
                // Thêm phiếu nhập mới
                $import_date = $_POST['import_date'] ?? date('Y-m-d');
                $products = $_POST['products'] ?? [];
                $quantities = $_POST['quantities'] ?? [];
                $prices = $_POST['prices'] ?? [];
                
                if (empty($products)) throw new Exception('Phải có ít nhất một sản phẩm');
                foreach ($products as $idx => $prod_id) {
                    if ($prod_id && ($quantities[$idx] <= 0 || $prices[$idx] < 0)) {
                        throw new Exception('Số lượng và giá nhập không hợp lệ');
                    }
                }
                
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO imports (import_date, status) VALUES (?, 'pending')");
                $stmt->execute([$import_date]);
                $import_id = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("INSERT INTO import_details (import_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                foreach ($products as $idx => $prod_id) {
                    if ($prod_id && $quantities[$idx] > 0) {
                        $stmt->execute([$import_id, $prod_id, $quantities[$idx], $prices[$idx]]);
                    }
                }
                $pdo->commit();
                
                echo json_encode(['success' => true]);
                break;
                
            case 'edit':
                // Sửa phiếu nhập (chỉ pending)
                $import_id = (int)($_POST['import_id'] ?? 0);
                $import_date = $_POST['import_date'] ?? date('Y-m-d');
                $products = $_POST['products'] ?? [];
                $quantities = $_POST['quantities'] ?? [];
                $prices = $_POST['prices'] ?? [];
                
                // Kiểm tra trạng thái
                $stmt = $pdo->prepare("SELECT status FROM imports WHERE import_id = ?");
                $stmt->execute([$import_id]);
                $import = $stmt->fetch();
                if (!$import || $import['status'] != 'pending') {
                    throw new Exception('Chỉ có thể sửa phiếu nhập chưa hoàn thành');
                }
                
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE imports SET import_date = ? WHERE import_id = ?");
                $stmt->execute([$import_date, $import_id]);
                
                // Xóa details cũ
                $stmt = $pdo->prepare("DELETE FROM import_details WHERE import_id = ?");
                $stmt->execute([$import_id]);
                
                // Thêm details mới
                $stmt = $pdo->prepare("INSERT INTO import_details (import_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                foreach ($products as $idx => $prod_id) {
                    if ($prod_id && $quantities[$idx] > 0) {
                        $stmt->execute([$import_id, $prod_id, $quantities[$idx], $prices[$idx]]);
                    }
                }
                $pdo->commit();
                
                echo json_encode(['success' => true]);
                break;
                
            case 'delete':
                $import_id = (int)($_POST['import_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT status FROM imports WHERE import_id = ?");
                $stmt->execute([$import_id]);
                $import = $stmt->fetch();
                if (!$import || $import['status'] != 'pending') {
                    throw new Exception('Chỉ có thể xóa phiếu nhập chưa hoàn thành');
                }
                $stmt = $pdo->prepare("DELETE FROM imports WHERE import_id = ?");
                $stmt->execute([$import_id]);
                echo json_encode(['success' => true]);
                break;
                
            case 'complete':
                $import_id = (int)($_POST['import_id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE imports SET status = 'completed' WHERE import_id = ? AND status = 'pending'");
                $stmt->execute([$import_id]);
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception('Không thể hoàn thành phiếu nhập này');
                }
                break;
                
            case 'getProducts':
                $stmt = $pdo->query("SELECT product_id, product_name FROM products ORDER BY product_name");
                $products = $stmt->fetchAll();
                echo json_encode($products);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>

=======
>>>>>>> Stashed changes
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Nhập sản phẩm - Feane Restaurant</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
<<<<<<< Updated upstream
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
=======
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
>>>>>>> Stashed changes
    <style>
        :root {
            --primary-color: #ffbe33;
            --secondary-color: #222831;
            --light-color: #ffffff;
            --dark-color: #121618;
        }
<<<<<<< Updated upstream
=======
        
>>>>>>> Stashed changes
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            overflow-x: hidden;
        }
<<<<<<< Updated upstream
=======
        
>>>>>>> Stashed changes
        .sidebar {
            min-height: 100vh;
            background-color: var(--secondary-color);
            color: var(--light-color);
            transition: all 0.3s;
            position: fixed;
            z-index: 100;
            width: 250px;
        }
<<<<<<< Updated upstream
=======
        
>>>>>>> Stashed changes
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1rem;
            margin: 0.25rem 0;
            border-radius: 0.25rem;
            transition: all 0.2s;
        }
<<<<<<< Updated upstream
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: var(--primary-color);
            color: var(--dark-color);
        }
=======
        
        .sidebar .nav-link:hover, 
        .sidebar .nav-link.active {
            background-color: var(--primary-color);
            color: var(--dark-color);
        }
        
>>>>>>> Stashed changes
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
<<<<<<< Updated upstream
=======
        
>>>>>>> Stashed changes
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
<<<<<<< Updated upstream
=======
        
>>>>>>> Stashed changes
        .navbar-custom {
            background-color: var(--light-color);
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
<<<<<<< Updated upstream
=======
        
>>>>>>> Stashed changes
        .card-custom {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,.1);
            transition: transform 0.3s;
        }
<<<<<<< Updated upstream
        .card-custom:hover {
            transform: translateY(-5px);
        }
=======
        
        .card-custom:hover {
            transform: translateY(-5px);
        }
        
        .stat-card {
            text-align: center;
            padding: 20px;
        }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .bg-primary-custom {
            background-color: var(--primary-color);
            color: var(--dark-color);
        }
        
        .bg-secondary-custom {
            background-color: var(--secondary-color);
            color: var(--light-color);
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table th {
            background-color: var(--secondary-color);
            color: var(--light-color);
        }
        
>>>>>>> Stashed changes
        .btn-custom {
            background-color: var(--primary-color);
            color: var(--dark-color);
            border: none;
            border-radius: 5px;
            padding: 8px 15px;
            transition: all 0.3s;
        }
<<<<<<< Updated upstream
=======
        
>>>>>>> Stashed changes
        .btn-custom:hover {
            background-color: #e6a500;
            transform: translateY(-2px);
        }
<<<<<<< Updated upstream
        .badge-status-pending {
            background-color: #6c757d;
        }
        .badge-status-completed {
            background-color: #28a745;
        }
=======
        
        .toggle-sidebar {
            display: none;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                text-align: center;
            }
            
            .sidebar .nav-link span {
                display: none;
            }
            
            .sidebar .nav-link i {
                margin-right: 0;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .toggle-sidebar {
                display: block;
            }
        }
        
        .dashboard-stats .col-md-3 {
            margin-bottom: 20px;
        }
        
        .alert-warning-custom {
            background-color: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }
        
        .badge-status-new {
            background-color: #17a2b8;
        }
        
        .badge-status-processing {
            background-color: #ffc107;
            color: #212529;
        }
        
        .badge-status-shipped {
            background-color: #28a745;
        }
        
        .badge-status-cancelled {
            background-color: #dc3545;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box input {
            padding-right: 40px;
        }
        
        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            cursor: pointer;
        }
        
        .action-buttons .btn {
            margin-right: 5px;
        }
        
        .badge-status-pending {
            background-color: #6c757d;
        }
        
        .badge-status-completed {
            background-color: #28a745;
        }
        
        /* CSS cho phần tìm kiếm theo ngày */
        .date-search-box {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .date-search-box .form-control {
            flex: 1;
        }
        
        @media (max-width: 768px) {
            .date-search-box {
                flex-direction: column;
            }
        }
        
        /* CSS cho phần lọc trạng thái */
>>>>>>> Stashed changes
        .status-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
<<<<<<< Updated upstream
=======

>>>>>>> Stashed changes
        .status-btn {
            padding: 8px 16px;
            background-color: #e9ecef;
            color: #495057;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
<<<<<<< Updated upstream
            cursor: pointer;
            border: none;
        }
=======
        }

>>>>>>> Stashed changes
        .status-btn:hover, .status-btn.active {
            background-color: var(--primary-color);
            color: var(--dark-color);
        }
<<<<<<< Updated upstream
=======
        
        /* CSS cho phần phân trang */
>>>>>>> Stashed changes
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
<<<<<<< Updated upstream
        .page-link {
            color: var(--secondary-color);
            cursor: pointer;
        }
=======
        
        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
        }
        
        .page-item {
            margin: 0 5px;
        }
        
        .page-link {
            display: block;
            padding: 8px 15px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            color: var(--secondary-color);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .page-link:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
        }
        
>>>>>>> Stashed changes
        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: var(--dark-color);
        }
<<<<<<< Updated upstream
        .product-row {
            margin-bottom: 15px;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }
        .action-buttons .btn {
            margin-right: 5px;
=======
        
        .page-item.disabled .page-link {
            color: #6c757d;
            pointer-events: none;
            background-color: #fff;
            border-color: #dee2e6;
>>>>>>> Stashed changes
        }
    </style>
</head>
<body>
<<<<<<< Updated upstream
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="p-3">
            <h4 class="text-center mb-4"><i class="fas fa-utensils"></i> Feane Admin</h4>
        </div>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="admin.html"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="users.html"><i class="fas fa-users"></i> <span>Quản lý người dùng</span></a></li>
            <li class="nav-item"><a class="nav-link" href="categories.html"><i class="fas fa-tags"></i> <span>Loại sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link" href="products.html"><i class="fas fa-hamburger"></i> <span>Sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="imports.php"><i class="fas fa-arrow-down"></i> <span>Nhập sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link" href="pricing.html"><i class="fas fa-dollar-sign"></i> <span>Giá bán</span></a></li>
            <li class="nav-item"><a class="nav-link" href="orders.html"><i class="fas fa-shopping-cart"></i> <span>Đơn hàng</span></a></li>
            <li class="nav-item"><a class="nav-link" href="inventory.html"><i class="fas fa-boxes"></i> <span>Tồn kho</span></a></li>
            <li class="nav-item mt-4"><a class="nav-link" href="adminlogin.html" id="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Đăng xuất</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <nav class="navbar navbar-expand-lg navbar-custom mb-4">
            <div class="container-fluid">
                <button class="btn toggle-sidebar" id="toggle-sidebar"><i class="fas fa-bars"></i></button>
                <div class="d-flex align-items-center">
                    <span class="navbar-text me-3">Xin chào, <strong>Admin</strong></span>
                    <div class="dropdown">
                        <button class="btn" type="button" data-bs-toggle="dropdown"><i class="fas fa-user-circle fa-lg"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.html"><i class="fas fa-user me-2"></i> Hồ sơ</a></li>
                            <li><a class="dropdown-item" href="settings.html"><i class="fas fa-cog me-2"></i> Cài đặt</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="adminlogin.html"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <div id="imports-page">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Quản lý Nhập sản phẩm</h2>
                <button type="button" class="btn btn-custom" id="btn-add-import">
                    <i class="fas fa-plus me-2"></i>Thêm phiếu nhập
                </button>
            </div>

            <!-- Bộ lọc -->
            <div class="card card-custom mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <input type="text" id="search-input" class="form-control" placeholder="Tìm kiếm theo mã phiếu hoặc tên sản phẩm">
                        </div>
                        <div class="col-md-4">
                            <input type="date" id="date-input" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <button id="btn-search" class="btn btn-custom w-100"><i class="fas fa-search"></i> Tìm kiếm</button>
                        </div>
                    </div>
                    <div class="status-filter mt-3">
                        <button class="status-btn active" data-status="">Tất cả</button>
                        <button class="status-btn" data-status="completed">Đã hoàn thành</button>
                        <button class="status-btn" data-status="pending">Chờ xử lý</button>
                    </div>
                </div>
            </div>

            <!-- Bảng danh sách -->
            <div class="card card-custom">
                <div class="card-header"><h5 class="card-title mb-0">Danh sách phiếu nhập</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="imports-table">
                            <thead>
                                <tr>
                                    <th>Mã phiếu</th>
                                    <th>Ngày nhập</th>
                                    <th>Số sản phẩm</th>
                                    <th>Tổng số lượng</th>
                                    <th>Tổng giá trị</th>
                                    <th>Trạng thái</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="imports-tbody">
                                <tr><td colspan="7" class="text-center">Đang tải...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-container" id="pagination-container"></div>
=======
    <!-- Trang quản trị -->
    <div id="admin-page">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="p-3">
                <h4 class="text-center mb-4"><i class="fas fa-utensils"></i> Feane Admin</h4>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="admin.html">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users.html">
                        <i class="fas fa-users"></i> <span>Quản lý người dùng</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="categories.html">
                        <i class="fas fa-tags"></i> <span>Loại sản phẩm</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="products.html">
                        <i class="fas fa-hamburger"></i> <span>Sản phẩm</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="imports.html">
                        <i class="fas fa-arrow-down"></i> <span>Nhập sản phẩm</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="pricing.html">
                        <i class="fas fa-dollar-sign"></i> <span>Giá bán</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="orders.html">
                        <i class="fas fa-shopping-cart"></i> <span>Đơn hàng</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="inventory.html">
                        <i class="fas fa-boxes"></i> <span>Tồn kho</span>
                    </a>
                </li>
                <li class="nav-item mt-4">
                    <a class="nav-link" href="adminlogin.html" id="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> <span>Đăng xuất</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navbar -->
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="btn toggle-sidebar" id="toggle-sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <span class="navbar-text me-3">
                            Xin chào, <strong>Admin</strong>
                        </span>
                        <div class="dropdown">
                            <button class="btn" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle fa-lg"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                                <li><a class="dropdown-item" href="profile.html"><i class="fas fa-user me-2"></i> Hồ sơ</a></li>
                                <li><a class="dropdown-item" href="settings.html"><i class="fas fa-cog me-2"></i> Cài đặt</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="adminlogin.html" id="nav-logout-btn"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Import Management Content -->
            <div id="imports-page" class="page-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Quản lý Nhập sản phẩm</h2>
                    <a href="importsadd.html" class="btn btn-custom">
                        <i class="fas fa-plus me-2"></i>Thêm phiếu nhập
                    </a>
                </div>
                
                <!-- Thanh lọc trạng thái -->
                <div class="status-filter">
                    <a href="imports.html" class="status-btn active">Tất cả</a>
                    <a href="importscomplete.html" class="status-btn">Đã hoàn thành</a>
                    <a href="importspending.html" class="status-btn">Chờ xử lý</a>
                </div>
                
                <!-- Search and Filter -->
                <div class="card card-custom mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="search-box">
                                    <input type="text" class="form-control" id="searchInput" placeholder="Tìm kiếm phiếu nhập...">
                                    <i class="fas fa-search" id="searchIcon"></i>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="date-search-box">
                                    <input type="date" class="form-control" id="dateSearchInput" placeholder="Tìm kiếm theo ngày...">
                                    <button class="btn btn-custom" id="dateSearchIcon">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Import List -->
                <div class="card card-custom">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Danh sách phiếu nhập</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mã phiếu</th>
                                        <th>Tên sản phẩm</th>
                                        <th>Ngày nhập</th>
                                        <th>Số sản phẩm</th>
                                        <th>Tổng số lượng</th>
                                        <th>Tổng giá trị</th>
                                        <th>Trạng thái</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody id="importsTableBody">
                                    <!-- Dữ liệu sẽ được thêm bằng JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Phân trang -->
                        <div class="pagination-container">
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center" id="pagination">
                                    <!-- Phân trang sẽ được thêm bằng JavaScript -->
                                </ul>
                            </nav>
                        </div>
                    </div>
>>>>>>> Stashed changes
                </div>
            </div>
        </div>
    </div>

<<<<<<< Updated upstream
    <!-- Modal thêm/sửa phiếu nhập -->
    <div class="modal fade" id="importFormModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="formModalTitle">Thêm phiếu nhập</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="import-form">
                        <input type="hidden" id="import_id" name="import_id">
                        <div class="mb-3">
                            <label class="form-label">Ngày nhập</label>
                            <input type="date" name="import_date" id="import_date" class="form-control" required>
                        </div>
                        <div id="product-rows-container">
                            <!-- Các dòng sản phẩm sẽ được thêm động bằng JS -->
                        </div>
                        <div class="mt-2">
                            <button type="button" id="add-product-row" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> Thêm sản phẩm</button>
                        </div>
                        <div class="mt-4">
                            <button type="submit" class="btn btn-custom">Lưu</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal xem chi tiết -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Chi tiết phiếu nhập</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detail-content">
                    <!-- Nội dung chi tiết sẽ được tải bằng AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Biến toàn cục
        let currentPage = 1;
        let currentFilters = { search: '', date: '', status: '' };
        let productsList = [];

        // Hàm tải danh sách phiếu nhập và vẽ bảng
        function loadImports() {
            $.getJSON('imports.php', {
                action: 'list',
                page: currentPage,
                search: currentFilters.search,
                date: currentFilters.date,
                status: currentFilters.status
            }, function(response) {
                if (response.success) {
                    renderTable(response.data);
                    renderPagination(response.pagination);
                } else {
                    alert('Lỗi tải dữ liệu: ' + response.error);
                }
            });
        }

        // Hàm vẽ bảng
        function renderTable(imports) {
            const tbody = $('#imports-tbody');
            if (!imports.length) {
                tbody.html('<tr><td colspan="7" class="text-center">Không có phiếu nhập nào</td></tr>');
                return;
            }
            let html = '';
            imports.forEach(item => {
                const statusClass = item.status === 'completed' ? 'badge-status-completed' : 'badge-status-pending';
                const statusText = item.status === 'completed' ? 'Đã hoàn thành' : 'Chờ xử lý';
                const totalValue = new Intl.NumberFormat('vi-VN').format(item.total_value || 0);
                const importCode = `#PN-${String(item.import_id).padStart(3, '0')}`;
                html += `
                    <tr>
                        <td><a href="#" class="view-detail" data-id="${item.import_id}">${importCode}</a></td>
                        <td>${new Date(item.import_date).toLocaleDateString('vi-VN')}</td>
                        <td>${item.product_count || 0}</td>
                        <td>${item.total_quantity || 0}</td>
                        <td>${totalValue} VND</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <div class="action-buttons">
                                ${item.status === 'pending' ? `
                                    <button class="btn btn-sm btn-warning edit-import" data-id="${item.import_id}"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-success complete-import" data-id="${item.import_id}"><i class="fas fa-check-circle"></i></button>
                                    <button class="btn btn-sm btn-danger delete-import" data-id="${item.import_id}"><i class="fas fa-trash"></i></button>
                                ` : `
                                    <button class="btn btn-sm btn-info view-detail" data-id="${item.import_id}"><i class="fas fa-eye"></i></button>
                                `}
                            </div>
                        </td>
                    </tr>
                `;
            });
            tbody.html(html);
        }

        // Phân trang
        function renderPagination(pagination) {
            const container = $('#pagination-container');
            if (pagination.total_pages <= 1) {
                container.empty();
                return;
            }
            let html = '<nav><ul class="pagination">';
            html += `<li class="page-item ${pagination.current_page === 1 ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${pagination.current_page - 1}">Trước</a>
                     </li>`;
            for (let i = 1; i <= pagination.total_pages; i++) {
                html += `<li class="page-item ${i === pagination.current_page ? 'active' : ''}">
                            <a class="page-link" href="#" data-page="${i}">${i}</a>
                         </li>`;
            }
            html += `<li class="page-item ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${pagination.current_page + 1}">Tiếp</a>
                     </li>`;
            html += '</ul></nav>';
            container.html(html);

            container.find('.page-link').click(function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                if (page && page !== currentPage) {
                    currentPage = page;
                    loadImports();
                }
            });
        }

        // Mở modal thêm/sửa
        function openFormModal(importId = null) {
            const isEdit = !!importId;
            $('#formModalTitle').text(isEdit ? 'Sửa phiếu nhập' : 'Thêm phiếu nhập');
            $('#import_id').val(importId || '');
            $('#import_date').val(new Date().toISOString().slice(0,10));
            $('#product-rows-container').empty();
            // Thêm một dòng mặc định
            addProductRow();

            if (isEdit) {
                // Tải dữ liệu phiếu nhập lên form
                $.getJSON('imports.php', { action: 'get', id: importId }, function(res) {
                    if (res.success) {
                        $('#import_date').val(res.import.import_date);
                        $('#product-rows-container').empty();
                        res.details.forEach(detail => {
                            addProductRow(detail.product_id, detail.quantity, detail.price);
                        });
                        $('#importFormModal').modal('show');
                    } else {
                        alert('Lỗi tải dữ liệu: ' + res.error);
                    }
                });
            } else {
                $('#importFormModal').modal('show');
            }
        }

        // Thêm dòng sản phẩm mới vào form
        function addProductRow(selectedProductId = '', quantity = '', price = '') {
            const rowIndex = $('#product-rows-container .product-row').length;
            const rowHtml = `
                <div class="product-row row align-items-end mb-2" data-index="${rowIndex}">
                    <div class="col-md-5">
                        <select name="products[]" class="form-select product-select" required>
                            <option value="">-- Chọn sản phẩm --</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="quantities[]" class="form-control" placeholder="Số lượng" min="1" value="${quantity}" required>
                    </div>
                    <div class="col-md-3">
                        <input type="number" name="prices[]" class="form-control" placeholder="Giá nhập" step="1000" min="0" value="${price}" required>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            `;
            $('#product-rows-container').append(rowHtml);
            const $select = $('#product-rows-container .product-select').last();
            $select.select2({ width: '100%', placeholder: '-- Chọn sản phẩm --' });
            // Fill options
            if (productsList.length) {
                productsList.forEach(p => {
                    $select.append(`<option value="${p.product_id}" ${p.product_id == selectedProductId ? 'selected' : ''}>${p.product_name}</option>`);
                });
            } else {
                $.getJSON('imports.php', { action: 'getProducts' }, function(data) {
                    productsList = data;
                    $select.empty();
                    $select.append('<option value="">-- Chọn sản phẩm --</option>');
                    data.forEach(p => {
                        $select.append(`<option value="${p.product_id}" ${p.product_id == selectedProductId ? 'selected' : ''}>${p.product_name}</option>`);
                    });
                    $select.trigger('change');
                });
            }
        }

        // Xử lý submit form thêm/sửa
        $('#import-form').submit(function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const importId = $('#import_id').val();
            const action = importId ? 'edit' : 'add';
            formData.append('action', action);
            if (importId) formData.append('import_id', importId);

            $.ajax({
                url: 'imports.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#importFormModal').modal('hide');
                        loadImports(); // reload danh sách
                    } else {
                        alert('Lỗi: ' + res.error);
                    }
                },
                error: function() {
                    alert('Có lỗi xảy ra khi gửi dữ liệu');
                }
            });
        });

        // Xóa phiếu nhập
        $(document).on('click', '.delete-import', function() {
            const id = $(this).data('id');
            if (confirm('Bạn có chắc chắn muốn xóa phiếu nhập này?')) {
                $.post('imports.php', { action: 'delete', import_id: id }, function(res) {
                    if (res.success) loadImports();
                    else alert('Lỗi: ' + res.error);
                }, 'json');
            }
        });

        // Hoàn thành phiếu nhập
        $(document).on('click', '.complete-import', function() {
            const id = $(this).data('id');
            if (confirm('Hoàn thành phiếu nhập này? Không thể sửa sau khi hoàn thành.')) {
                $.post('imports.php', { action: 'complete', import_id: id }, function(res) {
                    if (res.success) loadImports();
                    else alert('Lỗi: ' + res.error);
                }, 'json');
            }
        });

        // Xem chi tiết
        $(document).on('click', '.view-detail', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            $.getJSON('imports.php', { action: 'get', id: id }, function(res) {
                if (res.success) {
                    const importCode = `#PN-${String(res.import.import_id).padStart(3, '0')}`;
                    let detailHtml = `
                        <div class="mb-3">
                            <strong>Mã phiếu:</strong> ${importCode}<br>
                            <strong>Ngày nhập:</strong> ${new Date(res.import.import_date).toLocaleDateString('vi-VN')}<br>
                            <strong>Trạng thái:</strong> <span class="badge ${res.import.status === 'completed' ? 'badge-status-completed' : 'badge-status-pending'}">${res.import.status === 'completed' ? 'Đã hoàn thành' : 'Chờ xử lý'}</span>
                        </div>
                        <table class="table table-bordered">
                            <thead><tr><th>Sản phẩm</th><th class="text-center">Số lượng</th><th class="text-end">Giá nhập</th><th class="text-end">Thành tiền</th></tr></thead>
                            <tbody>
                    `;
                    let totalQty = 0, totalValue = 0;
                    res.details.forEach(d => {
                        totalQty += d.quantity;
                        totalValue += d.total;
                        detailHtml += `
                            <tr>
                                <td>${d.product_name}</td>
                                <td class="text-center">${d.quantity}</td>
                                <td class="text-end">${new Intl.NumberFormat('vi-VN').format(d.price)}</td>
                                <td class="text-end">${new Intl.NumberFormat('vi-VN').format(d.total)}</td>
                            </tr>
                        `;
                    });
                    detailHtml += `
                            </tbody>
                            <tfoot>
                                <tr><th colspan="2" class="text-end">Tổng:</th><th class="text-end">${totalQty} sản phẩm</th><th class="text-end">${new Intl.NumberFormat('vi-VN').format(totalValue)} VND</th></tr>
                            </tfoot>
                        </table>
                    `;
                    $('#detail-content').html(detailHtml);
                    $('#detailModal').modal('show');
                } else {
                    alert('Lỗi tải chi tiết: ' + res.error);
                }
            });
        });

        // Bộ lọc tìm kiếm
        $('#btn-search').click(function() {
            currentFilters.search = $('#search-input').val();
            currentFilters.date = $('#date-input').val();
            currentPage = 1;
            loadImports();
        });
        $('.status-btn').click(function() {
            $('.status-btn').removeClass('active');
            $(this).addClass('active');
            currentFilters.status = $(this).data('status');
            currentPage = 1;
            loadImports();
        });

        // Thêm dòng sản phẩm trong modal
        $('#add-product-row').click(function() {
            addProductRow();
        });
        $(document).on('click', '.remove-row', function() {
            if ($('.product-row').length > 1) {
                $(this).closest('.product-row').remove();
            } else {
                alert('Phải có ít nhất một sản phẩm');
            }
        });

        // Nút thêm phiếu nhập
        $('#btn-add-import').click(function() {
            openFormModal();
        });
        // Sửa phiếu nhập
        $(document).on('click', '.edit-import', function() {
            const id = $(this).data('id');
            openFormModal(id);
        });

        // Toggle sidebar
        $('#toggle-sidebar').click(function() {
            const sidebar = $('.sidebar');
            const mainContent = $('.main-content');
            if (sidebar.width() === 70) {
                sidebar.css('width', '250px');
                mainContent.css('margin-left', '250px');
                $('.sidebar .nav-link span').show();
            } else {
                sidebar.css('width', '70px');
                mainContent.css('margin-left', '70px');
                $('.sidebar .nav-link span').hide();
            }
        });

        // Khởi tạo
        loadImports();
        // Tải danh sách sản phẩm để dùng cho select2
        $.getJSON('imports.php', { action: 'getProducts' }, function(data) {
            productsList = data;
=======
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Xử lý toggle sidebar trên mobile
            document.getElementById('toggle-sidebar').addEventListener('click', function() {
                const sidebar = document.querySelector('.sidebar');
                const mainContent = document.querySelector('.main-content');
                
                if (sidebar.style.width === '70px' || sidebar.style.width === '') {
                    sidebar.style.width = '250px';
                    mainContent.style.marginLeft = '250px';
                    
                    // Hiển thị text trong sidebar
                    const navTexts = document.querySelectorAll('.sidebar .nav-link span');
                    navTexts.forEach(text => text.style.display = 'inline');
                } else {
                    sidebar.style.width = '70px';
                    mainContent.style.marginLeft = '70px';
                    
                    // Ẩn text trong sidebar
                    const navTexts = document.querySelectorAll('.sidebar .nav-link span');
                    navTexts.forEach(text => text.style.display = 'none');
                }
            });

            // Lấy các phần tử tìm kiếm
            const searchInput = document.getElementById('searchInput');
            const searchIcon = document.getElementById('searchIcon');
            const dateSearchInput = document.getElementById('dateSearchInput');
            const dateSearchIcon = document.getElementById('dateSearchIcon');

            // Hàm thực hiện tìm kiếm phiếu nhập
            function performSearch() {
                const searchTerm = searchInput.value.trim();
                // Luôn chuyển hướng đến importsfind.html, có hoặc không có từ khóa
                if (searchTerm) {
                    window.location.href = `importsfind.html?search=${encodeURIComponent(searchTerm)}`;
                } else {
                    window.location.href = 'importsfind.html';
                }
            }

            // Hàm thực hiện tìm kiếm theo ngày
            function performDateSearch() {
                const dateValue = dateSearchInput.value;
                // Luôn chuyển hướng đến importsfind2.html, có hoặc không có ngày
                if (dateValue) {
                    window.location.href = `importsfind2.html?date=${encodeURIComponent(dateValue)}`;
                } else {
                    window.location.href = 'importsfind2.html';
                }
            }

            // Sự kiện khi nhấn Enter trong ô tìm kiếm phiếu nhập
            searchInput.addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    performSearch();
                }
            });

            // Sự kiện khi click vào biểu tượng kính lúp tìm kiếm phiếu nhập
            searchIcon.addEventListener('click', function() {
                performSearch();
            });

            // Sự kiện khi nhấn Enter trong ô tìm kiếm theo ngày
            dateSearchInput.addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    performDateSearch();
                }
            });

            // Sự kiện khi click vào nút tìm kiếm theo ngày
            dateSearchIcon.addEventListener('click', function() {
                performDateSearch();
            });

            // Sự kiện khi thay đổi giá trị trong ô tìm kiếm theo ngày (chọn ngày xong)
            dateSearchInput.addEventListener('change', function() {
                performDateSearch();
            });

            // Dữ liệu mẫu cho phiếu nhập
            const importsData = [
                {
                    id: '#PN-001',
                    products: 'Burger gà chiên,Pizza hải sản,Pasta rau củ',
                    date: '15/05/2023',
                    productCount: 3,
                    totalQuantity: 120,
                    totalValue: '4,450,000 VND',
                    status: 'completed'
                },
                {
                    id: '#PN-002',
                    products: 'Pizza hải sản,Burger gà chiên',
                    date: '10/05/2023',
                    productCount: 2,
                    totalQuantity: 140,
                    totalValue: '3,750,000 VND',
                    status: 'completed'
                },
                {
                    id: '#PN-003',
                    products: 'Pasta rau củ,Khoai tây chiên',
                    date: '18/05/2023',
                    productCount: 2,
                    totalQuantity: 85,
                    totalValue: '2,250,000 VND',
                    status: 'pending'
                },
                {
                    id: '#PN-004',
                    products: 'Burger bò,Pasta rau củ,Pizza ba vị',
                    date: '15/05/2023',
                    productCount: 3,
                    totalQuantity: 120,
                    totalValue: '2,450,000 VND',
                    status: 'completed'
                },
                {
                    id: '#PN-005',
                    products: 'Pizza ba vị,Burger ức gà',
                    date: '10/05/2023',
                    productCount: 2,
                    totalQuantity: 140,
                    totalValue: '3,000,000 VND',
                    status: 'pending'
                },
                {
                    id: '#PN-006',
                    products: 'Pasta phô mai,Khoai tây chiên phô mai',
                    date: '18/05/2023',
                    productCount: 2,
                    totalQuantity: 85,
                    totalValue: '2,150,000 VND',
                    status: 'pending'
                },
                {
                    id: '#PN-007',
                    products: 'Burger gà chiên,Pizza hải sản',
                    date: '20/05/2023',
                    productCount: 2,
                    totalQuantity: 100,
                    totalValue: '3,200,000 VND',
                    status: 'completed'
                },
                {
                    id: '#PN-008',
                    products: 'Pasta rau củ,Khoai tây chiên',
                    date: '22/05/2023',
                    productCount: 2,
                    totalQuantity: 90,
                    totalValue: '2,100,000 VND',
                    status: 'pending'
                },
                {
                    id: '#PN-009',
                    products: 'Burger bò,Pizza ba vị',
                    date: '25/05/2023',
                    productCount: 2,
                    totalQuantity: 110,
                    totalValue: '3,500,000 VND',
                    status: 'completed'
                },
                {
                    id: '#PN-010',
                    products: 'Pasta phô mai,Khoai tây chiên phô mai',
                    date: '28/05/2023',
                    productCount: 2,
                    totalQuantity: 95,
                    totalValue: '2,300,000 VND',
                    status: 'pending'
                },
                {
                    id: '#PN-011',
                    products: 'Burger gà chiên,Pizza hải sản,Pasta rau củ',
                    date: '01/06/2023',
                    productCount: 3,
                    totalQuantity: 130,
                    totalValue: '4,800,000 VND',
                    status: 'completed'
                },
                {
                    id: '#PN-012',
                    products: 'Pizza hải sản,Burger gà chiên',
                    date: '05/06/2023',
                    productCount: 2,
                    totalQuantity: 120,
                    totalValue: '3,900,000 VND',
                    status: 'pending'
                }
            ];

            // Cài đặt phân trang
            const itemsPerPage = 5; // Số mục trên mỗi trang
            let currentPage = 1; // Trang hiện tại

            // Hàm hiển thị dữ liệu cho trang hiện tại
            function displayImportsForPage(page) {
                const tableBody = document.getElementById('importsTableBody');
                tableBody.innerHTML = '';

                // Tính toán chỉ số bắt đầu và kết thúc
                const startIndex = (page - 1) * itemsPerPage;
                const endIndex = startIndex + itemsPerPage;
                const itemsToDisplay = importsData.slice(startIndex, endIndex);

                // Tạo HTML cho từng mục
                itemsToDisplay.forEach(item => {
                    const statusClass = item.status === 'completed' ? 'badge-status-completed' : 'badge-status-pending';
                    const statusText = item.status === 'completed' ? 'Đã hoàn thành' : 'Chờ xử lý';
                    
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><a href="importsdetail.html">${item.id}</a></td>
                        <td>${item.products}</td>
                        <td>${item.date}</td>
                        <td>${item.productCount}</td>
                        <td>${item.totalQuantity}</td>
                        <td>${item.totalValue}</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <div class="action-buttons">
                                <a href="importsxoa.html" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i>
                                </a>
                                ${item.status === 'pending' ? 
                                    `<a href="importsedit.html" class="btn btn-sm btn-warning">
                                        <i class="fas fa-edit"></i>
                                    </a>` : 
                                    ''
                                }
                            </div>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
            }

            // Hàm tạo phân trang
            function setupPagination() {
                const paginationElement = document.getElementById('pagination');
                paginationElement.innerHTML = '';

                const totalPages = Math.ceil(importsData.length / itemsPerPage);

                // Nút Trước
                const prevLi = document.createElement('li');
                prevLi.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
                prevLi.innerHTML = `
                    <a class="page-link" href="#" data-page="${currentPage - 1}" ${currentPage === 1 ? 'tabindex="-1"' : ''}>
                        Trước
                    </a>
                `;
                prevLi.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (currentPage > 1) {
                        currentPage--;
                        displayImportsForPage(currentPage);
                        setupPagination();
                    }
                });
                paginationElement.appendChild(prevLi);

                // Các nút số trang
                for (let i = 1; i <= totalPages; i++) {
                    const li = document.createElement('li');
                    li.className = `page-item ${i === currentPage ? 'active' : ''}`;
                    li.innerHTML = `<a class="page-link" href="#" data-page="${i}">${i}</a>`;
                    
                    li.addEventListener('click', (e) => {
                        e.preventDefault();
                        currentPage = i;
                        displayImportsForPage(currentPage);
                        setupPagination();
                    });
                    
                    paginationElement.appendChild(li);
                }

                // Nút Tiếp
                const nextLi = document.createElement('li');
                nextLi.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
                nextLi.innerHTML = `
                    <a class="page-link" href="#" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'tabindex="-1"' : ''}>
                        Tiếp
                    </a>
                `;
                nextLi.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (currentPage < totalPages) {
                        currentPage++;
                        displayImportsForPage(currentPage);
                        setupPagination();
                    }
                });
                paginationElement.appendChild(nextLi);
            }

            // Khởi tạo hiển thị
            displayImportsForPage(currentPage);
            setupPagination();
>>>>>>> Stashed changes
        });
    </script>
</body>
</html>