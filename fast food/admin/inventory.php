<?php
session_start();

// Kiểm tra đăng nhập
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: adminlogin.php');
    exit;
}

// Kết nối database
$host = 'localhost';
$dbname = 'fast_food';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Lấy thông tin admin từ database
$admin_id = $_SESSION['admin_id'];
$stmt = $pdo->prepare("SELECT id, full_name, username, email, phone, address, birthday, register_date, role, status, last_login FROM users WHERE id = ?");
$stmt->execute([$admin_id]);
$admin_info = $stmt->fetch();

// Nếu không tìm thấy admin, đăng xuất
if (!$admin_info) {
    session_destroy();
    header('Location: adminlogin.php');
    exit;
}

// Xử lý AJAX request
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];

    try {
        switch ($action) {
            case 'list':
                $search = $_GET['search'] ?? '';
                $type = $_GET['type'] ?? '';
                $stock_status = $_GET['stock_status'] ?? '';
                $sort = $_GET['sort'] ?? '';
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;
                $threshold = (int)($_GET['threshold'] ?? 10);

                $sql = "SELECT p.id, p.code, p.name, p.category_id, p.stock_quantity,
                               c.name as category_name
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.id
                        WHERE p.status = 'active'";
                $params = [];

                if ($search) {
                    $sql .= " AND (p.name LIKE :search OR p.code LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                if ($type) {
                    $sql .= " AND c.name = :type";
                    $params[':type'] = $type;
                }

                $sql .= " ORDER BY ";
                switch ($sort) {
                    case 'name': $sql .= "p.name"; break;
                    case 'stock': $sql .= "p.stock_quantity"; break;
                    case 'type': $sql .= "c.name"; break;
                    default: $sql .= "p.id";
                }

                $sql .= " LIMIT :limit OFFSET :offset";
                $params[':limit'] = $limit;
                $params[':offset'] = $offset;

                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    if (is_int($val)) $stmt->bindValue($key, $val, PDO::PARAM_INT);
                    else $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $products = $stmt->fetchAll();

                if ($stock_status) {
                    if ($stock_status == 'low') {
                        $products = array_filter($products, function($p) use ($threshold) {
                            return $p['stock_quantity'] <= $threshold;
                        });
                    } elseif ($stock_status == 'adequate') {
                        $products = array_filter($products, function($p) use ($threshold) {
                            return $p['stock_quantity'] > $threshold;
                        });
                    }
                    $products = array_values($products);
                }

                $countSql = "SELECT COUNT(*) as total FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status = 'active'";
                $countParams = [];
                if ($search) {
                    $countSql .= " AND (p.name LIKE :search OR p.code LIKE :search)";
                    $countParams[':search'] = "%$search%";
                }
                if ($type) {
                    $countSql .= " AND c.name = :type";
                    $countParams[':type'] = $type;
                }
                $countStmt = $pdo->prepare($countSql);
                foreach ($countParams as $key => $val) $countStmt->bindValue($key, $val);
                $countStmt->execute();
                $total = $countStmt->fetch()['total'];
                $totalPages = ceil($total / $limit);

                $statsSql = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN stock_quantity <= :threshold THEN 1 ELSE 0 END) as low_stock,
                                SUM(CASE WHEN stock_quantity > :threshold THEN 1 ELSE 0 END) as adequate
                             FROM products WHERE status = 'active'";
                $statsStmt = $pdo->prepare($statsSql);
                $statsStmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
                $statsStmt->execute();
                $stats = $statsStmt->fetch();

                echo json_encode([
                    'success' => true,
                    'data' => $products,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'total_records' => $total
                    ],
                    'stats' => $stats,
                    'threshold' => $threshold
                ]);
                break;

            // Các case khác giữ nguyên
            case 'get_transactions':
                $product_id = (int)($_GET['product_id'] ?? 0);
                $date_from = $_GET['date_from'] ?? '';
                $date_to = $_GET['date_to'] ?? '';

                if (!$product_id) throw new Exception('Vui lòng chọn sản phẩm');

                $stmt = $pdo->prepare("SELECT id, name FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Sản phẩm không tồn tại');

                $importSql = "SELECT id, import_date, quantity, unit_cost, subtotal
                              FROM import_details d
                              JOIN imports i ON d.import_id = i.id
                              WHERE d.product_id = :product_id AND i.status = 'completed'
                              AND (:date_from = '' OR i.import_date >= :date_from)
                              AND (:date_to = '' OR i.import_date <= :date_to)
                              ORDER BY i.import_date";
                $importStmt = $pdo->prepare($importSql);
                $importStmt->execute([':product_id' => $product_id, ':date_from' => $date_from, ':date_to' => $date_to]);
                $imports = $importStmt->fetchAll();

                $exportSql = "SELECT od.quantity, o.order_date
                              FROM order_details od
                              JOIN orders o ON od.order_id = o.id
                              WHERE od.product_id = :product_id AND o.status != 'cancelled'
                              AND (:date_from = '' OR o.order_date >= :date_from)
                              AND (:date_to = '' OR o.order_date <= :date_to)
                              ORDER BY o.order_date";
                $exportStmt = $pdo->prepare($exportSql);
                $exportStmt->execute([':product_id' => $product_id, ':date_from' => $date_from, ':date_to' => $date_to]);
                $exports = $exportStmt->fetchAll();

                $total_import = array_sum(array_column($imports, 'quantity'));
                $total_export = array_sum(array_column($exports, 'quantity'));

                $startStock = 0;
                if ($date_from) {
                    $stmt = $pdo->prepare("SELECT SUM(d.quantity) as total_import_before
                                           FROM import_details d
                                           JOIN imports i ON d.import_id = i.id
                                           WHERE d.product_id = ? AND i.status = 'completed' AND i.import_date < ?");
                    $stmt->execute([$product_id, $date_from]);
                    $importBefore = $stmt->fetch()['total_import_before'] ?? 0;

                    $stmt = $pdo->prepare("SELECT SUM(od.quantity) as total_export_before
                                           FROM order_details od
                                           JOIN orders o ON od.order_id = o.id
                                           WHERE od.product_id = ? AND o.status != 'cancelled' AND o.order_date < ?");
                    $stmt->execute([$product_id, $date_from]);
                    $exportBefore = $stmt->fetch()['total_export_before'] ?? 0;

                    $startStock = $importBefore - $exportBefore;
                } else {
                    $stmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ?");
                    $stmt->execute([$product_id]);
                    $endStock = $stmt->fetchColumn();
                }

                $endStock = $startStock + $total_import - $total_export;

                $transactions = [];
                foreach ($imports as $imp) {
                    $transactions[] = [
                        'date' => $imp['import_date'],
                        'type' => 'import',
                        'quantity' => $imp['quantity'],
                        'unit' => 'cái',
                        'note' => 'Nhập hàng, giá ' . number_format($imp['unit_cost']) . ' VNĐ'
                    ];
                }
                foreach ($exports as $exp) {
                    $transactions[] = [
                        'date' => $exp['order_date'],
                        'type' => 'export',
                        'quantity' => $exp['quantity'],
                        'unit' => 'cái',
                        'note' => 'Xuất bán'
                    ];
                }
                usort($transactions, function($a, $b) {
                    return strtotime($a['date']) - strtotime($b['date']);
                });

                echo json_encode([
                    'success' => true,
                    'product' => $product,
                    'transactions' => $transactions,
                    'total_import' => $total_import,
                    'total_export' => $total_export,
                    'start_stock' => $startStock,
                    'end_stock' => $endStock
                ]);
                break;

            case 'get_stock_at_date':
                $product_id = (int)($_GET['product_id'] ?? 0);
                $date = $_GET['date'] ?? '';
                if (!$product_id) throw new Exception('Vui lòng chọn sản phẩm');
                if (!$date) throw new Exception('Vui lòng chọn ngày');

                $stmt = $pdo->prepare("SELECT SUM(d.quantity) as total_import
                                       FROM import_details d
                                       JOIN imports i ON d.import_id = i.id
                                       WHERE d.product_id = ? AND i.status = 'completed' AND i.import_date <= ?");
                $stmt->execute([$product_id, $date]);
                $total_import = $stmt->fetch()['total_import'] ?? 0;

                $stmt = $pdo->prepare("SELECT SUM(od.quantity) as total_export
                                       FROM order_details od
                                       JOIN orders o ON od.order_id = o.id
                                       WHERE od.product_id = ? AND o.status != 'cancelled' AND o.order_date <= ?");
                $stmt->execute([$product_id, $date]);
                $total_export = $stmt->fetch()['total_export'] ?? 0;

                $stock = $total_import - $total_export;
                echo json_encode([
                    'success' => true,
                    'product_id' => $product_id,
                    'date' => $date,
                    'stock' => $stock
                ]);
                break;

            case 'get_categories':
                $stmt = $pdo->query("SELECT name FROM categories WHERE status = 'active' ORDER BY name");
                $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode($categories);
                break;

            case 'get_products':
                $stmt = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");
                $products = $stmt->fetchAll();
                echo json_encode($products);
                break;

            case 'get_product_info':
                $product_id = (int)($_GET['id'] ?? 0);
                if (!$product_id) throw new Exception('Missing product ID');
                $stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.category_id, c.name as category_name, p.cost_price, p.selling_price, p.stock_quantity FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Product not found');
                echo json_encode(['success' => true, 'product' => $product]);
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
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Tồn kho - Feane Restaurant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #ffbe33;
            --secondary-color: #222831;
            --light-color: #ffffff;
            --dark-color: #121618;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            overflow-x: hidden;
        }
        .sidebar {
            min-height: 100vh;
            background-color: var(--secondary-color);
            color: var(--light-color);
            transition: all 0.3s;
            position: fixed;
            z-index: 100;
            width: 250px;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1rem;
            margin: 0.25rem 0;
            border-radius: 0.25rem;
            transition: all 0.2s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: var(--primary-color);
            color: var(--dark-color);
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        .navbar-custom {
            background-color: var(--light-color);
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        .card-custom {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,.1);
            transition: transform 0.3s;
        }
        .card-custom:hover {
            transform: translateY(-5px);
        }
        .btn-custom {
            background-color: var(--primary-color);
            color: var(--dark-color);
            border: none;
            border-radius: 5px;
            padding: 8px 15px;
            transition: all 0.3s;
        }
        .btn-custom:hover {
            background-color: #e6a500;
            transform: translateY(-2px);
        }
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
        .filter-section {
            background-color: var(--primary-color);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .filter-section h3 {
            margin-bottom: 20px;
            color: var(--dark-color);
        }
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            flex: 1;
            min-width: 180px;
            border-radius: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            color: white;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        .stat-card .card-body {
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
            color: white;
        }
        .stat-info p {
            margin: 5px 0 0;
            font-weight: 500;
            color: rgba(255,255,255,0.9);
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.9;
            color: white;
        }
        .bg-primary-custom {
            background-color: #007bff !important;
        }
        .bg-success-custom {
            background-color: #28a745 !important;
        }
        .bg-danger-custom {
            background-color: #dc3545 !important;
        }
        .badge-status-pending {
            background-color: #ffc107;
            color: #212529;
        }
        .badge-status-completed {
            background-color: #28a745;
            color: white;
        }
        .low-stock {
            background-color: #ffdddd;
        }
        .warning {
            color: #dc3545;
            font-weight: bold;
        }
        .product-link {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }
        .product-link:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }
        .modal-header {
            background-color: var(--secondary-color);
            color: var(--light-color);
            border-bottom: none;
        }
        .modal-header .btn-close {
            filter: invert(1);
        }
        .avatar-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            transition: transform 0.2s;
        }
        .avatar-btn:hover {
            transform: scale(1.05);
        }
        .avatar-btn i {
            font-size: 2rem;
            color: var(--primary-color);
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            background-color: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .profile-avatar i {
            font-size: 3rem;
            color: var(--dark-color);
        }
        .profile-info-item {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .profile-info-item:last-child {
            border-bottom: none;
        }
        .profile-info-label {
            font-weight: 600;
            color: var(--secondary-color);
            width: 120px;
            display: inline-block;
        }
        .profile-info-value {
            color: #555;
        }
        .detail-search-section {
            background-color: #e9ecef;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid var(--primary-color);
        }
        .detail-search-result {
            display: none;
            margin-top: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        .summary-card {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            color: white;
            margin-bottom: 15px;
        }
        .summary-card.import {
            background-color: #17a2b8;
        }
        .summary-card.export {
            background-color: #28a745;
        }
        .summary-card.stock {
            background-color: #6c757d;
        }
    </style>
</head>
<body>
    <div id="admin-page">
        <div class="sidebar">
            <div class="p-3">
                <h4 class="text-center mb-4"><i class="fas fa-utensils"></i> Feane Admin</h4>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link" href="admin.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a class="nav-link" href="users.php"><i class="fas fa-users"></i> <span>Quản lý người dùng</span></a></li>
                <li class="nav-item"><a class="nav-link" href="categories.php"><i class="fas fa-tags"></i> <span>Loại sản phẩm</span></a></li>
                <li class="nav-item"><a class="nav-link" href="products.php"><i class="fas fa-hamburger"></i> <span>Sản phẩm</span></a></li>
                <li class="nav-item"><a class="nav-link" href="imports.php"><i class="fas fa-arrow-down"></i> <span>Nhập sản phẩm</span></a></li>
                <li class="nav-item"><a class="nav-link" href="pricing.php"><i class="fas fa-dollar-sign"></i> <span>Giá bán</span></a></li>
                <li class="nav-item"><a class="nav-link" href="orders.php"><i class="fas fa-shopping-cart"></i> <span>Đơn hàng</span></a></li>
                <li class="nav-item"><a class="nav-link active" href="inventory.php"><i class="fas fa-boxes"></i> <span>Tồn kho</span></a></li>
                <li class="nav-item mt-4"><a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Đăng xuất</span></a></li>
            </ul>
        </div>

        <div class="main-content">
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="btn toggle-sidebar" id="toggle-sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center ms-auto">
                        <span class="navbar-text me-3">
                            Xin chào, <strong><?php echo htmlspecialchars($admin_info['full_name'] ?: $admin_info['username']); ?></strong>
                        </span>
                        <button class="avatar-btn" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </button>
                    </div>
                </div>
            </nav>

            <div id="stock-management-page" class="page-content">
                <h2 class="mb-4">Quản lý Tồn kho</h2>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h3><i class="fas fa-filter me-2"></i>Bộ lọc tồn kho</h3>
                    <form id="stock-filter-form">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="search-name" class="form-label">Tìm sản phẩm</label>
                                <input type="text" class="form-control" id="search-name" name="search" placeholder="Nhập tên sản phẩm...">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="filter-type" class="form-label">Loại sản phẩm</label>
                                <select class="form-select" id="filter-type" name="type">
                                    <option value="">Tất cả</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="stock-status" class="form-label">Trạng thái tồn kho</label>
                                <select class="form-select" id="stock-status" name="stock-status">
                                    <option value="">Tất cả</option>
                                    <option value="low">Sắp hết hàng</option>
                                    <option value="adequate">Đủ hàng</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="sort-by" class="form-label">Sắp xếp theo</label>
                                <select class="form-select" id="sort-by" name="sort">
                                    <option value="name">Tên sản phẩm</option>
                                    <option value="stock">Số lượng tồn</option>
                                    <option value="type">Loại sản phẩm</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-dark"><i class="fas fa-search me-2"></i>Tìm kiếm</button>
                                <button type="reset" class="btn btn-outline-dark ms-2"><i class="fas fa-undo me-2"></i>Đặt lại</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-row">
                    <div class="stat-card bg-primary-custom" data-status="total">
                        <div class="card-body">
                            <div class="stat-info">
                                <h3 id="total-products">0</h3>
                                <p>Tổng sản phẩm</p>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-boxes"></i>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card bg-success-custom" data-status="adequate">
                        <div class="card-body">
                            <div class="stat-info">
                                <h3 id="in-stock-products">0</h3>
                                <p>Sản phẩm đủ hàng</p>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card bg-danger-custom" data-status="low">
                        <div class="card-body">
                            <div class="stat-info">
                                <h3 id="low-stock-products">0</h3>
                                <p>Sản phẩm sắp hết</p>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tra cứu tồn tại thời điểm -->
                <div class="detail-search-section mb-3">
                    <h3><i class="fas fa-calendar-day me-2"></i>Tra cứu tồn kho tại thời điểm</h3>
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label">Chọn sản phẩm</label>
                            <select id="stock-date-product" class="form-select">
                                <option value="">-- Chọn sản phẩm --</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ngày</label>
                            <input type="date" id="stock-date" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3 d-flex align-items-end">
                            <button id="check-stock-date" class="btn btn-dark w-100">Tra cứu</button>
                        </div>
                    </div>
                    <div id="stock-date-result" class="alert alert-info mt-2" style="display: none;"></div>
                </div>

                <!-- Báo cáo nhập xuất -->
                <div class="detail-search-section">
                    <h3><i class="fas fa-chart-line me-2"></i>Tra cứu chi tiết nhập - xuất - tồn</h3>
                    <form id="detail-search-form">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="detail-product" class="form-label">Chọn sản phẩm</label>
                                <select class="form-select" id="detail-product" name="detail-product">
                                    <option value="">-- Chọn sản phẩm --</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="detail-date-from" class="form-label">Từ ngày</label>
                                <input type="date" class="form-control" id="detail-date-from" name="detail-date-from">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="detail-date-to" class="form-label">Đến ngày</label>
                                <input type="date" class="form-control" id="detail-date-to" name="detail-date-to">
                            </div>
                            <div class="col-md-2 mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-dark w-100"><i class="fas fa-search me-2"></i>Tra cứu</button>
                            </div>
                        </div>
                    </form>
                    <div id="detail-search-result" class="detail-search-result">
                        <h5>Kết quả tra cứu</h5>
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="summary-card import">
                                    <i class="fas fa-arrow-down fa-2x"></i>
                                    <h4 id="total-import">0</h4>
                                    <p>Tổng nhập</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="summary-card export">
                                    <i class="fas fa-arrow-up fa-2x"></i>
                                    <h4 id="total-export">0</h4>
                                    <p>Tổng xuất</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="summary-card stock">
                                    <i class="fas fa-boxes fa-2x"></i>
                                    <h4 id="total-stock">0</h4>
                                    <p>Tồn cuối kỳ</p>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <th>Ngày</th><th>Loại giao dịch</th><th>Số lượng</th><th>Đơn vị</th><th>Ghi chú</th>
                                </thead>
                                <tbody id="detail-result-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Bảng tồn kho -->
                <div class="card card-custom">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Danh sách tồn kho</h5>
                        <div class="search-box">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Tìm kiếm sản phẩm..." id="search-stock-input">
                                <button class="btn btn-outline-secondary" id="search-stock-btn"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <th>Mã SP</th><th>Tên sản phẩm</th><th>Loại</th><th>Số lượng nhập</th><th>Số lượng xuất</th><th>Số lượng tồn</th><th>Trạng thái</th><th>Thao tác</th>
                                </thead>
                                <tbody id="stock-table-body">
                                    <td colspan="8" class="text-center">Đang tải...</td>
                                </tbody>
                            </table>
                        </div>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center mt-4" id="pagination-container"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Thông tin cá nhân -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i> Thông tin cá nhân</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="profile-avatar"><i class="fas fa-user-circle"></i></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-user me-2"></i> Họ tên:</span><span class="profile-info-value"><?php echo htmlspecialchars($admin_info['full_name'] ?: 'Chưa cập nhật'); ?></span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-at me-2"></i> Tên đăng nhập:</span><span class="profile-info-value"><?php echo htmlspecialchars($admin_info['username']); ?></span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-envelope me-2"></i> Email:</span><span class="profile-info-value"><?php echo htmlspecialchars($admin_info['email']); ?></span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-phone me-2"></i> Điện thoại:</span><span class="profile-info-value"><?php echo htmlspecialchars($admin_info['phone'] ?: 'Chưa cập nhật'); ?></span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-map-marker-alt me-2"></i> Địa chỉ:</span><span class="profile-info-value"><?php echo htmlspecialchars($admin_info['address'] ?: 'Chưa cập nhật'); ?></span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-calendar-alt me-2"></i> Ngày sinh:</span><span class="profile-info-value"><?php echo $admin_info['birthday'] && $admin_info['birthday'] !== '0000-00-00' ? date('d/m/Y', strtotime($admin_info['birthday'])) : 'Chưa cập nhật'; ?></span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-calendar-plus me-2"></i> Ngày đăng ký:</span><span class="profile-info-value"><?php echo date('d/m/Y', strtotime($admin_info['register_date'])); ?></span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-shield-alt me-2"></i> Vai trò:</span><span class="profile-info-value">Quản trị viên</span></div>
                    <div class="profile-info-item"><span class="profile-info-label"><i class="fas fa-clock me-2"></i> Lần đăng nhập cuối:</span><span class="profile-info-value"><?php echo $admin_info['last_login'] ? date('d/m/Y H:i:s', strtotime($admin_info['last_login'])) : 'Chưa có dữ liệu'; ?></span></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
            </div>
        </div>
    </div>

    <!-- Modal chi tiết sản phẩm -->
    <div class="modal fade" id="productDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Chi tiết sản phẩm</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="product-detail-content"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPage = 1;
        let currentFilters = { search: '', type: '', stock_status: '', sort: '' };
        let threshold = 10;

        $('#toggle-sidebar').click(function() {
            const sidebar = $('.sidebar');
            const mainContent = $('.main-content');
            if (sidebar.width() === 70) {
                sidebar.width(250);
                mainContent.css('margin-left', '250px');
                $('.sidebar .nav-link span').show();
            } else {
                sidebar.width(70);
                mainContent.css('margin-left', '70px');
                $('.sidebar .nav-link span').hide();
            }
        });

        function adjustSidebar() {
            if (window.innerWidth <= 768) {
                $('.sidebar').width(70);
                $('.main-content').css('margin-left', '70px');
                $('.sidebar .nav-link span').hide();
            } else {
                $('.sidebar').width(250);
                $('.main-content').css('margin-left', '250px');
                $('.sidebar .nav-link span').show();
            }
        }

        adjustSidebar();
        $(window).resize(adjustSidebar);

        function loadCategories() {
            $.getJSON('inventory.php', { action: 'get_categories' }, function(data) {
                let options = '<option value="">Tất cả</option>';
                data.forEach(cat => options += `<option value="${cat}">${cat}</option>`);
                $('#filter-type').html(options);
            });
        }

        function loadProductSelects() {
            $.getJSON('inventory.php', { action: 'get_products' }, function(products) {
                let options = '<option value="">-- Chọn sản phẩm --</option>';
                products.forEach(p => options += `<option value="${p.id}">${p.name}</option>`);
                $('#detail-product').html(options);
                $('#stock-date-product').html(options);
            });
        }

        function loadStock() {
            const params = {
                action: 'list',
                page: currentPage,
                search: currentFilters.search,
                type: currentFilters.type,
                stock_status: currentFilters.stock_status,
                sort: currentFilters.sort,
                threshold: threshold
            };
            $.getJSON('inventory.php', params, function(response) {
                if (response.success) {
                    renderStockTable(response.data);
                    renderPagination(response.pagination);
                    updateStats(response.stats);
                } else {
                    alert('Lỗi tải dữ liệu: ' + response.error);
                }
            });
        }

        function renderStockTable(products) {
            const tbody = $('#stock-table-body');
            if (!products.length) {
                tbody.html('运转<td colspan="8" class="text-center">Không có sản phẩm nào</td>');
                return;
            }
            let html = '';
            products.forEach(p => {
                const isLow = p.stock_quantity <= threshold;
                const rowClass = isLow ? 'low-stock' : '';
                const statusHtml = isLow ? '<span class="warning">Sắp hết hàng!</span>' : '';
                html += `
                    <tr class="${rowClass}">
                        <td>${p.code}</td>
                        <td><a href="#" class="product-link view-detail" data-id="${p.id}">${escapeHtml(p.name)}</a></td>
                        <td>${p.category_name || ''}</td>
                        <td>--</td>
                        <td>--</td>
                        <td>${p.stock_quantity}</td>
                        <td>${statusHtml}</td>
                        <td><button class="btn btn-sm btn-custom view-detail" data-id="${p.id}"><i class="fas fa-eye me-1"></i>Xem</button></td>
                    </tr>
                `;
            });
            tbody.html(html);
        }

        function renderPagination(pagination) {
            const container = $('#pagination-container');
            if (pagination.total_pages <= 1) {
                container.empty();
                return;
            }
            let html = '';
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
            container.html(html);
            container.find('.page-link').click(function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                if (page && page !== currentPage) {
                    currentPage = page;
                    loadStock();
                }
            });
        }

        function updateStats(stats) {
            $('#total-products').text(stats.total || 0);
            $('#in-stock-products').text(stats.adequate || 0);
            $('#low-stock-products').text(stats.low_stock || 0);
        }

        $('#check-stock-date').click(function() {
            const productId = $('#stock-date-product').val();
            const date = $('#stock-date').val();
            if (!productId) { alert('Vui lòng chọn sản phẩm'); return; }
            if (!date) { alert('Vui lòng chọn ngày'); return; }
            $.getJSON('inventory.php', { action: 'get_stock_at_date', product_id: productId, date: date }, function(res) {
                if (res.success) {
                    $('#stock-date-result').html(`Tồn kho ngày ${new Date(res.date).toLocaleDateString('vi-VN')}: <strong>${res.stock}</strong> sản phẩm`).show();
                } else {
                    alert('Lỗi: ' + res.error);
                }
            });
        });

        $('#detail-search-form').submit(function(e) {
            e.preventDefault();
            const productId = $('#detail-product').val();
            const dateFrom = $('#detail-date-from').val();
            const dateTo = $('#detail-date-to').val();
            if (!productId) { alert('Vui lòng chọn sản phẩm'); return; }
            $.getJSON('inventory.php', { action: 'get_transactions', product_id: productId, date_from: dateFrom, date_to: dateTo }, function(res) {
                if (res.success) {
                    $('#total-import').text(res.total_import);
                    $('#total-export').text(res.total_export);
                    $('#total-stock').text(res.end_stock);
                    const tbody = $('#detail-result-body');
                    tbody.empty();
                    if (res.transactions.length === 0) {
                        tbody.html('<tr><td colspan="5" class="text-center">Không có giao dịch</td></tr>');
                    } else {
                        res.transactions.forEach(t => {
                            const typeText = t.type === 'import' ? 'Nhập hàng' : 'Xuất hàng';
                            const typeClass = t.type === 'import' ? 'text-success' : 'text-danger';
                            tbody.append(`
                                <tr>
                                    <td>${new Date(t.date).toLocaleDateString('vi-VN')}</td>
                                    <td><span class="${typeClass}">${typeText}</span></td>
                                    <td>${t.quantity}</td>
                                    <td>${t.unit}</td>
                                    <td>${t.note}</td>
                                </tr>
                            `);
                        });
                    }
                    $('#detail-search-result').show();
                } else {
                    alert('Lỗi: ' + res.error);
                }
            });
        });

        $('#stock-filter-form').submit(function(e) {
            e.preventDefault();
            currentFilters.search = $('#search-name').val();
            currentFilters.type = $('#filter-type').val();
            currentFilters.stock_status = $('#stock-status').val();
            currentFilters.sort = $('#sort-by').val();
            currentPage = 1;
            loadStock();
        });
        
        $('#stock-filter-form button[type="reset"]').click(function() {
            $('#search-name').val('');
            $('#filter-type').val('');
            $('#stock-status').val('');
            $('#sort-by').val('');
            currentFilters = { search: '', type: '', stock_status: '', sort: '' };
            currentPage = 1;
            loadStock();
        });

        $('#search-stock-btn').click(function() {
            currentFilters.search = $('#search-stock-input').val();
            currentPage = 1;
            loadStock();
        });
        
        $('#search-stock-input').keypress(function(e) {
            if (e.which === 13) {
                currentFilters.search = $(this).val();
                currentPage = 1;
                loadStock();
            }
        });

        $('.stat-card').click(function() {
            const status = $(this).data('status');
            if (status === 'adequate' || status === 'low') {
                $('#stock-status').val(status);
                $('#stock-filter-form').submit();
            }
        });

        function showProductDetail(productId) {
            $.getJSON('inventory.php', { action: 'get_product_info', id: productId })
                .done(function(productRes) {
                    if (!productRes.success) {
                        alert('Lỗi tải thông tin sản phẩm: ' + productRes.error);
                        return;
                    }
                    const product = productRes.product;
                    $.getJSON('inventory.php', { action: 'get_transactions', product_id: productId })
                        .done(function(transRes) {
                            if (!transRes.success) {
                                alert('Lỗi tải lịch sử giao dịch: ' + transRes.error);
                                return;
                            }
                            const transactions = transRes.transactions;
                            const totalImport = transRes.total_import;
                            const totalExport = transRes.total_export;
                            const endStock = transRes.end_stock;

                            const formatMoney = (amount) => new Intl.NumberFormat('vi-VN').format(amount);

                            let html = `
                                <div class="row mb-3">
                                    <div class="col-md-6"><div class="info-card"><div class="info-label">Mã sản phẩm</div><div class="info-value">${product.code}</div></div></div>
                                    <div class="col-md-6"><div class="info-card"><div class="info-label">Tên sản phẩm</div><div class="info-value">${escapeHtml(product.name)}</div></div></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6"><div class="info-card"><div class="info-label">Loại sản phẩm</div><div class="info-value">${escapeHtml(product.category_name || 'Không xác định')}</div></div></div>
                                    <div class="col-md-6"><div class="info-card"><div class="info-label">Giá vốn (VNĐ)</div><div class="info-value">${formatMoney(product.cost_price)}</div></div></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6"><div class="info-card"><div class="info-label">Giá bán (VNĐ)</div><div class="info-value">${formatMoney(product.selling_price)}</div></div></div>
                                    <div class="col-md-6"><div class="info-card"><div class="info-label">Tồn kho hiện tại</div><div class="info-value">${product.stock_quantity}</div></div></div>
                                </div>
                                <div class="stock-summary mb-4"><div class="row text-center"><div class="col-4"><div class="stock-summary-label">Tổng nhập</div><div class="stock-summary-value">${totalImport}</div></div><div class="col-4"><div class="stock-summary-label">Tổng xuất</div><div class="stock-summary-value">${totalExport}</div></div><div class="col-4"><div class="stock-summary-label">Tồn cuối kỳ</div><div class="stock-summary-value">${endStock}</div></div></div></div>
                                <h5 class="mt-3 mb-3"><i class="fas fa-history me-2"></i>Lịch sử nhập - xuất</h5>
                                <div class="table-responsive"><table class="table table-bordered transaction-table"><thead><tr><th>Ngày</th><th>Loại giao dịch</th><th>Số lượng</th><th>Đơn vị</th><th>Ghi chú</th></tr></thead><tbody>
                            `;
                            if (transactions.length === 0) {
                                html += '<tr><td colspan="5" class="text-center">Không có giao dịch nào</td></tr>';
                            } else {
                                transactions.forEach(t => {
                                    const typeText = t.type === 'import' ? 'Nhập hàng' : 'Xuất hàng';
                                    const typeClass = t.type === 'import' ? 'text-success' : 'text-danger';
                                    html += `<tr><td>${new Date(t.date).toLocaleDateString('vi-VN')}</td><td><span class="${typeClass}">${typeText}</span></td><td>${t.quantity}</td><td>${t.unit}</td><td>${t.note}</td></tr>`;
                                });
                            }
                            html += `</tbody></table></div>`;
                            $('#product-detail-content').html(html);
                            $('#productDetailModal').modal('show');
                        });
                });
        }

        $(document).on('click', '.view-detail', function(e) {
            e.preventDefault();
            const productId = $(this).data('id');
            if (productId) showProductDetail(productId);
        });

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        loadCategories();
        loadProductSelects();
        loadStock();
        
        const today = new Date();
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(today.getDate() - 30);
        const formatDate = (date) => date.toISOString().slice(0,10);
        $('#detail-date-from').val(formatDate(thirtyDaysAgo));
        $('#detail-date-to').val(formatDate(today));
        $('#stock-date').val(formatDate(today));
    </script>
</body>
</html>