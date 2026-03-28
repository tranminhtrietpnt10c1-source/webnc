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

if (!$admin_info) {
    session_destroy();
    header('Location: adminlogin.php');
    exit;
}

// Hàm định dạng tiền VNĐ
function formatVND($amount) {
    return number_format($amount, 0, ',', '.') . ' ₫';
}

// Xử lý AJAX request
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        switch ($action) {
            case 'updateProfit':
                $product_id = (int)($_POST['product_id'] ?? 0);
                $profit_percent = (float)($_POST['profit_percent'] ?? 0);
                
                if (!$product_id) throw new Exception('Thiếu ID sản phẩm');
                if ($profit_percent < 0) throw new Exception('Tỷ lệ lợi nhuận phải lớn hơn hoặc bằng 0');
                if ($profit_percent > 100) throw new Exception('Tỷ lệ lợi nhuận không được vượt quá 100%');
                
                // Lấy thông tin sản phẩm hiện tại
                $stmt = $pdo->prepare("SELECT cost_price, selling_price, profit_percentage FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Không tìm thấy sản phẩm');
                
                $old_selling_price = $product['selling_price'];
                $old_profit_rate = $product['profit_percentage'] ?? 0;
                
                // Tính giá bán mới
                $new_selling_price = $product['cost_price'] * (1 + $profit_percent / 100);
                $new_selling_price = round($new_selling_price / 1000) * 1000;
                
                // Cập nhật sản phẩm
                $stmt = $pdo->prepare("UPDATE products SET selling_price = ?, profit_percentage = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_selling_price, $profit_percent, $product_id]);
                
                // Ghi log vào pricing_log
                $user_id = $_SESSION['admin_id'];
                $change_reason = "Cập nhật tỷ lệ lợi nhuận từ " . $old_profit_rate . "% lên " . $profit_percent . "%";
                $stmt = $pdo->prepare("INSERT INTO pricing_log (product_id, old_selling_price, new_selling_price, changed_by, change_reason, changed_at) 
                                       VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$product_id, $old_selling_price, $new_selling_price, $user_id, $change_reason]);
                
                echo json_encode([
                    'success' => true, 
                    'new_selling_price' => $new_selling_price,
                    'new_selling_price_formatted' => formatVND($new_selling_price)
                ]);
                break;
                
            case 'getProfitStats':
                $stmt = $pdo->query("
                    SELECT 
                        MIN(((selling_price - cost_price) / cost_price * 100)) as min_profit,
                        MAX(((selling_price - cost_price) / cost_price * 100)) as max_profit,
                        AVG(((selling_price - cost_price) / cost_price * 100)) as avg_profit
                    FROM products WHERE status = 'active' AND cost_price > 0
                ");
                $stats = $stmt->fetch();
                echo json_encode(['success' => true, 'stats' => $stats]);
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

// Lấy danh sách sản phẩm với giá nhập bình quân, giá bán, tỷ lệ lợi nhuận
$stmt = $pdo->query("
    SELECT p.id, p.code, p.name, p.cost_price, p.selling_price, 
           p.profit_percentage, c.name as category_name, c.id as category_id
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY c.name, p.name
");
$products = $stmt->fetchAll();

// Lấy danh sách lô hàng nhập (import details)
$stmt = $pdo->query("
    SELECT d.id, d.import_id, d.product_id, d.quantity, d.unit_cost, d.subtotal,
           i.import_code, i.import_date, i.supplier,
           p.name as product_name, p.code as product_code, p.selling_price,
           c.name as category_name
    FROM import_details d
    JOIN imports i ON d.import_id = i.id
    JOIN products p ON d.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    WHERE i.status = 'completed'
    ORDER BY i.import_date DESC, d.id DESC
");
$import_lots = $stmt->fetchAll();

// Tính % lợi nhuận cho từng lô hàng
foreach ($import_lots as &$lot) {
    $lot['profit_percent'] = $lot['unit_cost'] > 0 ? 
        (($lot['selling_price'] - $lot['unit_cost']) / $lot['unit_cost'] * 100) : 0;
}

// Lấy danh sách categories cho dropdown
$stmt = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll();

// Lấy thống kê lợi nhuận
$stmt = $pdo->query("
    SELECT 
        MIN(((selling_price - cost_price) / cost_price * 100)) as min_profit,
        MAX(((selling_price - cost_price) / cost_price * 100)) as max_profit,
        AVG(((selling_price - cost_price) / cost_price * 100)) as avg_profit
    FROM products WHERE status = 'active' AND cost_price > 0
");
$profit_stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý giá bán - Feane Restaurant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Các style giữ nguyên như cũ */
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
            border-radius: 10px;
            padding: 12px 20px;
            margin-bottom: 20px;
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
        .modal-header {
            background-color: var(--secondary-color);
            color: white;
            border-bottom: none;
        }
        .modal-header .btn-close {
            filter: invert(1);
        }
        .profit-percentage {
            font-weight: bold;
            color: #28a745;
        }
        .cost-price {
            color: #6c757d;
            font-weight: 600;
        }
        .selling-price {
            font-weight: bold;
            color: #dc3545;
        }
        .global-search-container {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .search-input-container {
            flex-grow: 1;
            position: relative;
            min-width: 200px;
        }
        .search-input {
            padding-right: 45px;
        }
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        .nav-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: var(--dark-color);
            border-color: var(--primary-color);
        }
        .nav-tabs .nav-link {
            color: var(--secondary-color);
        }
        .table th {
            background-color: var(--secondary-color);
            color: var(--light-color);
            vertical-align: middle;
        }
        .table td {
            vertical-align: middle;
        }
        .edit-profit-btn {
            background-color: var(--primary-color);
            color: var(--dark-color);
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .edit-profit-btn:hover {
            background-color: #e6a500;
            transform: translateY(-1px);
        }
        .edit-form {
            display: inline-flex;
            gap: 5px;
            align-items: center;
        }
        .edit-input {
            width: 80px;
            padding: 4px 8px;
            border: 1px solid var(--primary-color);
            border-radius: 4px;
            text-align: center;
        }
        .save-profit-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
        }
        .cancel-profit-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
        }
        .stat-badge {
            background-color: var(--primary-color);
            color: var(--dark-color);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .filter-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .profit-range {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .profit-range input {
            width: 100px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .page-header h2 {
            margin: 0;
            color: var(--secondary-color);
        }
        .loading-spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #fff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.6s linear infinite;
            margin-right: 4px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .text-warning {
            color: #ffc107 !important;
            font-weight: bold;
        }
        .text-danger {
            color: #dc3545 !important;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
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
            <li class="nav-item"><a class="nav-link active" href="pricing.php"><i class="fas fa-dollar-sign"></i> <span>Giá bán</span></a></li>
            <li class="nav-item"><a class="nav-link" href="orders.php"><i class="fas fa-shopping-cart"></i> <span>Đơn hàng</span></a></li>
            <li class="nav-item"><a class="nav-link" href="inventory.php"><i class="fas fa-boxes"></i> <span>Tồn kho</span></a></li>
            <li class="nav-item mt-4"><a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Đăng xuất</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <nav class="navbar navbar-expand-lg navbar-custom">
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

        <!-- Page Header -->
        <div class="page-header">
            <h2><i class="fas fa-dollar-sign me-2"></i>Quản lý giá bán</h2>
        </div>

        <!-- Search Container -->
        <div class="global-search-container">
            <h5 class="mb-3"><i class="fas fa-search me-2"></i>Tra cứu sản phẩm</h5>
            <form class="search-form" id="global-search-form">
                <div class="search-input-container">
                    <input type="text" class="form-control search-input" id="global-search-input" placeholder="Nhập tên hoặc mã sản phẩm...">
                    <i class="fas fa-search search-icon"></i>
                </div>
                <div class="search-input-container">
                    <select class="form-select" id="category-filter">
                        <option value="">Tất cả loại</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-custom search-btn"><i class="fas fa-check me-2"></i>Tìm kiếm</button>
                <button type="button" class="btn btn-secondary" id="reset-search"><i class="fas fa-redo me-2"></i>Đặt lại</button>
            </form>
        </div>

        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs" id="pricingTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="product-tab" data-bs-toggle="tab" href="#product">Tỷ lệ lợi nhuận theo sản phẩm</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="import-tab" data-bs-toggle="tab" href="#import">Tra cứu theo lô hàng</a>
            </li>
        </ul>

        <div class="tab-content" id="pricingTabsContent">
            <!-- Tab 1: Product Profit -->
            <div class="tab-pane fade show active" id="product">
                <div class="card card-custom mt-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                        <h5 class="card-title mb-0">Tỷ lệ lợi nhuận theo sản phẩm</h5>
                        <div class="mt-2 mt-sm-0">
                            <span class="stat-badge">
                                📉 Thấp: <?php echo number_format($profit_stats['min_profit'] ?? 0, 2); ?>% | 
                                📊 TB: <?php echo number_format($profit_stats['avg_profit'] ?? 0, 2); ?>% | 
                                📈 Cao: <?php echo number_format($profit_stats['max_profit'] ?? 0, 2); ?>%
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th class="text-center">Mã SP</th>
                                        <th>Tên sản phẩm</th>
                                        <th>Loại</th>
                                        <th class="text-end">Giá vốn (VNĐ)</th>
                                        <th class="text-end">Giá bán (VNĐ)</th>
                                        <th class="text-center">Tỷ lệ lợi nhuận (%)</th>
                                        <th class="text-center">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody id="product-tbody">
                                    <?php foreach ($products as $product): ?>
                                    <tr data-id="<?php echo $product['id']; ?>" 
                                        data-cost="<?php echo $product['cost_price']; ?>"
                                        data-category-id="<?php echo $product['category_id']; ?>">
                                        <td class="text-center"><strong><?php echo htmlspecialchars($product['code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                        <td class="cost-price text-end"><?php echo formatVND($product['cost_price']); ?></td>
                                        <td class="selling-price text-end"><?php echo formatVND($product['selling_price']); ?></td>
                                        <td class="text-center">
                                            <div id="profit-cell-<?php echo $product['id']; ?>">
                                                <span class="profit-percentage"><?php echo $product['profit_percentage']; ?>%</span>
                                                <button class="edit-profit-btn ms-2" data-id="<?php echo $product['id']; ?>" data-profit="<?php echo $product['profit_percentage']; ?>">
                                                    <i class="fas fa-edit"></i> Sửa
                                                </button>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-info view-lots" data-id="<?php echo $product['id']; ?>" data-name="<?php echo htmlspecialchars($product['name']); ?>">
                                                <i class="fas fa-boxes"></i> Xem lô nhập
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Import Lots -->
            <div class="tab-pane fade" id="import">
                <div class="card card-custom mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Tra cứu giá vốn, % lợi nhuận theo lô hàng nhập</h5>
                    </div>
                    <div class="card-body">
                        <div class="filter-card">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Sản phẩm</label>
                                    <select id="product-filter-import" class="form-select">
                                        <option value="">Tất cả sản phẩm</option>
                                        <?php foreach ($products as $prod): ?>
                                            <option value="<?php echo $prod['id']; ?>"><?php echo htmlspecialchars($prod['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Từ ngày</label>
                                    <input type="date" id="import-date-from" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Đến ngày</label>
                                    <input type="date" id="import-date-to" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <button id="search-import-lots" class="btn btn-custom w-100"><i class="fas fa-search me-1"></i>Tìm</button>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <label class="form-label">Lọc theo % lợi nhuận</label>
                                    <div class="profit-range">
                                        <input type="number" id="min-profit" class="form-control" placeholder="Tối thiểu" step="5">
                                        <span>-</span>
                                        <input type="number" id="max-profit" class="form-control" placeholder="Tối đa" step="5">
                                        <button id="apply-profit-filter" class="btn btn-sm btn-secondary">Áp dụng</button>
                                        <button id="clear-profit-filter" class="btn btn-sm btn-outline-secondary">Xóa</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Mã phiếu</th>
                                        <th>Ngày nhập</th>
                                        <th>Sản phẩm</th>
                                        <th class="text-center">Số lượng</th>
                                        <th class="text-end">Giá nhập (VNĐ)</th>
                                        <th class="text-end">Giá bán hiện tại (VNĐ)</th>
                                        <th class="text-center">Lợi nhuận (%)</th>
                                    </tr>
                                </thead>
                                <tbody id="import-tbody">
                                    <?php foreach ($import_lots as $lot): ?>
                                    <tr data-product-id="<?php echo $lot['product_id']; ?>" data-date="<?php echo $lot['import_date']; ?>">
                                        <td><?php echo htmlspecialchars($lot['import_code']); ?></td>
                                        <td class="text-center"><?php echo date('d/m/Y', strtotime($lot['import_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($lot['product_name']); ?></td>
                                        <td class="text-center"><?php echo number_format($lot['quantity']); ?></td>
                                        <td class="cost-price text-end"><?php echo formatVND($lot['unit_cost']); ?></td>
                                        <td class="selling-price text-end"><?php echo formatVND($lot['selling_price']); ?></td>
                                        <td class="text-center">
                                            <?php 
                                            $profit_class = $lot['profit_percent'] >= 30 ? 'profit-percentage' : ($lot['profit_percent'] >= 15 ? 'text-warning' : 'text-danger');
                                            ?>
                                            <span class="<?php echo $profit_class; ?>"><?php echo number_format($lot['profit_percent'], 2); ?>%</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Thông tin cá nhân Admin -->
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar
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

        // Format tiền VNĐ
        function formatVND(amount) {
            return new Intl.NumberFormat('vi-VN').format(amount) + ' ₫';
        }

        // Hiển thị thông báo
        function showNotification(message, type = 'success') {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const alertDiv = $(`
                <div class="alert ${alertClass} alert-dismissible fade show position-fixed top-0 end-0 m-3" role="alert" style="z-index: 9999; min-width: 300px;">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `);
            $('body').append(alertDiv);
            setTimeout(() => alertDiv.fadeOut(() => alertDiv.remove()), 3000);
        }

        // Chỉnh sửa tỷ lệ lợi nhuận
        function editProfit(productId, currentProfit) {
            const profitCell = $(`#profit-cell-${productId}`);
            const originalHtml = profitCell.html();
            const row = $(`tr[data-id="${productId}"]`);
            const costPrice = parseFloat(row.data('cost'));
            
            const editForm = $('<div class="edit-form"></div>');
            const input = $('<input type="number" class="edit-input" step="0.5" min="0" max="100">').val(currentProfit);
            const saveBtn = $('<button class="save-profit-btn ms-1">Lưu</button>');
            const cancelBtn = $('<button class="cancel-profit-btn ms-1">Hủy</button>');
            
            saveBtn.click(function() {
                const newProfit = parseFloat(input.val());
                if (isNaN(newProfit) || newProfit < 0) {
                    showNotification('Vui lòng nhập tỷ lệ hợp lệ (>=0)', 'danger');
                    return;
                }
                if (newProfit > 100) {
                    showNotification('Tỷ lệ lợi nhuận không được vượt quá 100%', 'danger');
                    return;
                }
                
                saveBtn.prop('disabled', true);
                saveBtn.html('<span class="loading-spinner"></span> Đang lưu...');
                
                $.post(window.location.href, { 
                    ajax: 1, 
                    action: 'updateProfit', 
                    product_id: productId, 
                    profit_percent: newProfit 
                }, function(res) {
                    if (res.success) {
                        const newSellingFormatted = formatVND(res.new_selling_price);
                        row.find('.selling-price').text(newSellingFormatted);
                        
                        profitCell.html(`
                            <span class="profit-percentage">${newProfit}%</span>
                            <button class="edit-profit-btn ms-2" data-id="${productId}" data-profit="${newProfit}">
                                <i class="fas fa-edit"></i> Sửa
                            </button>
                        `);
                        
                        profitCell.find('.edit-profit-btn').click(function() { 
                            editProfit(productId, newProfit); 
                        });
                        
                        showNotification('Đã cập nhật tỷ lệ lợi nhuận thành công', 'success');
                    } else {
                        showNotification('Lỗi: ' + (res.error || 'Không xác định'), 'danger');
                        profitCell.html(originalHtml);
                        profitCell.find('.edit-profit-btn').click(function() { 
                            editProfit(productId, currentProfit); 
                        });
                    }
                }, 'json').fail(function() {
                    showNotification('Có lỗi xảy ra khi kết nối máy chủ', 'danger');
                    profitCell.html(originalHtml);
                    profitCell.find('.edit-profit-btn').click(function() { 
                        editProfit(productId, currentProfit); 
                    });
                }).always(function() {
                    saveBtn.prop('disabled', false);
                    saveBtn.html('Lưu');
                });
            });
            
            cancelBtn.click(function() { 
                profitCell.html(originalHtml); 
                profitCell.find('.edit-profit-btn').click(function() { 
                    editProfit(productId, currentProfit); 
                });
            });
            
            editForm.append(input, saveBtn, cancelBtn);
            profitCell.empty().append(editForm);
            input.focus();
        }

        // Lọc sản phẩm (Tab 1) - chỉ gọi khi bấm nút Tìm kiếm
        function filterProducts() {
            const searchTerm = $('#global-search-input').val().toLowerCase().trim();
            const categoryId = $('#category-filter').val();

            $('#product-tbody tr').each(function() {
                const $row = $(this);
                const code = $row.find('td:first').text().toLowerCase();
                const name = $row.find('td:eq(1)').text().toLowerCase();
                const rowCategoryId = $row.data('category-id');

                const matchesSearch = searchTerm === '' || code.includes(searchTerm) || name.includes(searchTerm);
                const matchesCategory = !categoryId || rowCategoryId == categoryId;

                if (matchesSearch && matchesCategory) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        }

        // Lọc lô hàng (Tab 2) - giữ nguyên
        function filterImportLots() {
            const productId = $('#product-filter-import').val();
            const dateFrom = $('#import-date-from').val();
            const dateTo = $('#import-date-to').val();
            const minProfit = $('#min-profit').val() ? parseFloat($('#min-profit').val()) : null;
            const maxProfit = $('#max-profit').val() ? parseFloat($('#max-profit').val()) : null;
            
            $('#import-tbody tr').each(function() {
                const $row = $(this);
                const lotProductId = $row.data('product-id');
                const lotDate = $row.data('date');
                const profitText = $row.find('td:last span').text().replace('%', '');
                const profit = parseFloat(profitText);
                
                let show = true;
                if (productId && lotProductId != productId) show = false;
                if (dateFrom && lotDate < dateFrom) show = false;
                if (dateTo && lotDate > dateTo) show = false;
                if (minProfit !== null && profit < minProfit) show = false;
                if (maxProfit !== null && profit > maxProfit) show = false;
                
                $row.toggle(show);
            });
        }

        // Gắn sự kiện cho nút Tìm kiếm (submit form)
        $('#global-search-form').submit(function(e) {
            e.preventDefault();
            filterProducts();
        });
        
        // Nút Đặt lại: xóa giá trị input và select, hiển thị lại toàn bộ
        $('#reset-search').click(function() {
            $('#global-search-input').val('');
            $('#category-filter').val('');
            $('#product-tbody tr').show();
        });
        
        // Lọc lô hàng
        $('#search-import-lots').click(filterImportLots);
        $('#apply-profit-filter').click(filterImportLots);
        $('#clear-profit-filter').click(function() {
            $('#min-profit').val('');
            $('#max-profit').val('');
            filterImportLots();
        });
        $('#product-filter-import').change(filterImportLots);
        $('#import-date-from, #import-date-to').on('change', filterImportLots);
        
        // Xem lô nhập của sản phẩm
        $(document).on('click', '.view-lots', function() {
            const productId = $(this).data('id');
            const productName = $(this).data('name');
            $('#import-tab').tab('show');
            $('#product-filter-import').val(productId);
            filterImportLots();
            showNotification(`Đang hiển thị lô nhập của sản phẩm: ${productName}`, 'success');
        });

        // Gắn sự kiện cho nút sửa tỷ lệ lợi nhuận
        $(document).on('click', '.edit-profit-btn', function() { 
            const productId = $(this).data('id'); 
            const currentProfit = $(this).data('profit'); 
            editProfit(productId, currentProfit); 
        });
    </script>
</body>
</html>