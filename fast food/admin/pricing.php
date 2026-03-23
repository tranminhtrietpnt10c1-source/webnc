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
            case 'getProducts':
                $search = $_GET['search'] ?? '';
                $category_id = $_GET['category_id'] ?? '';
                $min_cost = $_GET['min_cost'] ?? null;
                $max_cost = $_GET['max_cost'] ?? null;
                $min_profit_percent = $_GET['min_profit_percent'] ?? null;
                $max_profit_percent = $_GET['max_profit_percent'] ?? null;
                $min_selling = $_GET['min_selling'] ?? null;
                $max_selling = $_GET['max_selling'] ?? null;
                $sort_by = $_GET['sort_by'] ?? 'name';
                $sort_order = $_GET['sort_order'] ?? 'ASC';

                $sql = "SELECT p.id, p.code, p.name, p.cost_price, p.selling_price,
                               c.name as category_name, c.id as category_id,
                               ((p.selling_price - p.cost_price) / p.cost_price * 100) as profit_percent
                        FROM products p
                        JOIN categories c ON p.category_id = c.id
                        WHERE 1=1";
                $params = [];

                if ($search) { $sql .= " AND (p.name LIKE :search OR p.code LIKE :search)"; $params[':search'] = "%$search%"; }
                if ($category_id) { $sql .= " AND p.category_id = :category_id"; $params[':category_id'] = $category_id; }
                if ($min_cost !== null) { $sql .= " AND p.cost_price >= :min_cost"; $params[':min_cost'] = (float)$min_cost; }
                if ($max_cost !== null) { $sql .= " AND p.cost_price <= :max_cost"; $params[':max_cost'] = (float)$max_cost; }
                if ($min_profit_percent !== null) { $sql .= " AND ((p.selling_price - p.cost_price) / p.cost_price * 100) >= :min_profit_percent"; $params[':min_profit_percent'] = (float)$min_profit_percent; }
                if ($max_profit_percent !== null) { $sql .= " AND ((p.selling_price - p.cost_price) / p.cost_price * 100) <= :max_profit_percent"; $params[':max_profit_percent'] = (float)$max_profit_percent; }
                if ($min_selling !== null) { $sql .= " AND p.selling_price >= :min_selling"; $params[':min_selling'] = (float)$min_selling; }
                if ($max_selling !== null) { $sql .= " AND p.selling_price <= :max_selling"; $params[':max_selling'] = (float)$max_selling; }

                $sql .= " ORDER BY $sort_by $sort_order";
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) $stmt->bindValue($key, $val);
                $stmt->execute();
                $products = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $products]);
                break;

            case 'getCategories':
                $sql = "SELECT c.id, c.name,
                               AVG(p.cost_price) as avg_cost_price,
                               AVG(p.selling_price) as avg_selling_price,
                               AVG((p.selling_price - p.cost_price) / p.cost_price * 100) as avg_profit_percent,
                               COUNT(p.id) as product_count
                        FROM categories c
                        LEFT JOIN products p ON c.id = p.category_id
                        GROUP BY c.id, c.name
                        ORDER BY c.name";
                $stmt = $pdo->query($sql);
                $categories = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $categories]);
                break;

            case 'updateProfit':
                $product_id = (int)($_POST['product_id'] ?? 0);
                $profit_percent = (float)($_POST['profit_percent'] ?? 0);
                if (!$product_id) throw new Exception('Missing product ID');

                $stmt = $pdo->prepare("SELECT cost_price, selling_price FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Product not found');

                $new_selling_price = $product['cost_price'] * (1 + $profit_percent / 100);
                $new_selling_price = round($new_selling_price / 1000) * 1000;

                $stmt = $pdo->prepare("UPDATE products SET selling_price = ? WHERE id = ?");
                $stmt->execute([$new_selling_price, $product_id]);

                echo json_encode(['success' => true, 'new_selling_price' => $new_selling_price]);
                break;

            case 'getImportLots':
                $product_id = $_GET['product_id'] ?? null;
                $sql = "SELECT d.id, d.import_id, d.product_id, d.quantity, d.unit_cost, d.subtotal,
                               i.import_date, i.import_code, p.name as product_name
                        FROM import_details d
                        JOIN imports i ON d.import_id = i.id
                        JOIN products p ON d.product_id = p.id
                        WHERE 1=1";
                $params = [];
                if ($product_id) { $sql .= " AND d.product_id = :product_id"; $params[':product_id'] = $product_id; }
                $sql .= " ORDER BY i.import_date DESC, d.id DESC";
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) $stmt->bindValue($key, $val);
                $stmt->execute();
                $lots = $stmt->fetchAll();

                $selling_prices = [];
                if ($product_id) {
                    $stmt = $pdo->prepare("SELECT id, selling_price FROM products WHERE id = ?");
                    $stmt->execute([$product_id]);
                    $prod = $stmt->fetch();
                    $selling_prices[$product_id] = $prod['selling_price'];
                } else {
                    $stmt = $pdo->query("SELECT id, selling_price FROM products");
                    $all = $stmt->fetchAll();
                    foreach ($all as $p) $selling_prices[$p['id']] = $p['selling_price'];
                }

                foreach ($lots as &$lot) {
                    $sp = $selling_prices[$lot['product_id']] ?? 0;
                    $lot['selling_price'] = $sp;
                    $lot['profit_percent'] = $lot['unit_cost'] > 0 ? (($sp - $lot['unit_cost']) / $lot['unit_cost'] * 100) : 0;
                }
                echo json_encode(['success' => true, 'data' => $lots]);
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

// Lấy danh sách categories cho dropdown
$stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll();
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
        .profit-percentage { font-weight: bold; color: #28a745; }
        .cost-price { color: #6c757d; font-weight: 600; }
        .selling-price { font-weight: bold; color: #dc3545; }
        .editable-field { cursor: pointer; padding: 5px; border-radius: 3px; transition: background-color 0.2s; }
        .editable-field:hover { background-color: #f8f9fa; }
        .editable-input { width: 80px; padding: 4px 8px; border: 1px solid #ffbe33; border-radius: 3px; font-size: 14px; text-align: center; }
        .save-btn { background-color: #28a745; color: white; border: none; border-radius: 3px; padding: 5px 10px; font-size: 12px; cursor: pointer; margin-top: 5px; }
        .save-btn:hover { background-color: #218838; }
        .cancel-btn { background-color: #6c757d; color: white; border: none; border-radius: 3px; padding: 5px 10px; font-size: 12px; cursor: pointer; margin-top: 5px; margin-left: 5px; }
        .cancel-btn:hover { background-color: #5a6268; }
        .edit-form { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; }
        .profit-cell { display: flex; align-items: center; gap: 10px; }
        .global-search-container { background-color: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .search-form { display: flex; gap: 10px; align-items: center; }
        .search-input-container { flex-grow: 1; position: relative; }
        .search-input { padding-right: 45px; }
        .search-icon { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #6c757d; }
        .nav-tabs .nav-link.active { background-color: var(--primary-color); color: var(--dark-color); border-color: var(--primary-color); }
        .nav-tabs .nav-link { color: var(--secondary-color); }
        .table th { background-color: var(--secondary-color); color: var(--light-color); }
        .avatar-btn { background: transparent; border: none; cursor: pointer; padding: 0; transition: transform 0.2s; }
        .avatar-btn:hover { transform: scale(1.05); }
        .avatar-btn i { font-size: 2rem; color: var(--primary-color); }
        .profile-avatar { width: 100px; height: 100px; background-color: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .profile-avatar i { font-size: 3rem; color: var(--dark-color); }
        .profile-info-item { padding: 10px 0; border-bottom: 1px solid #eee; }
        .profile-info-item:last-child { border-bottom: none; }
        .profile-info-label { font-weight: 600; color: var(--secondary-color); width: 120px; display: inline-block; }
        .profile-info-value { color: #555; }
        .modal-header { background-color: var(--secondary-color); color: var(--light-color); border-bottom: none; }
        .modal-header .btn-close { filter: invert(1); }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="p-3"><h4 class="text-center mb-4"><i class="fas fa-utensils"></i> Feane Admin</h4></div>
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

    <div class="main-content">
        <nav class="navbar navbar-expand-lg navbar-custom mb-4">
            <div class="container-fluid">
                <button class="btn toggle-sidebar" id="toggle-sidebar"><i class="fas fa-bars"></i></button>
                <div class="d-flex align-items-center ms-auto">
                    <span class="navbar-text me-3">Xin chào, <strong><?php echo htmlspecialchars($admin_info['full_name'] ?: $admin_info['username']); ?></strong></span>
                    <button class="avatar-btn" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fas fa-user-circle fa-2x"></i></button>
                </div>
            </div>
        </nav>

        <div id="pricing-page" class="page-content">
            <h2 class="mb-4">Quản lý giá bán</h2>

            <div class="global-search-container">
                <h5 class="mb-3"><i class="fas fa-search me-2"></i>Tra cứu</h5>
                <form class="search-form" id="global-search-form">
                    <div class="search-input-container">
                        <input type="text" class="form-control search-input" id="global-search-input" placeholder="Nhập tên hoặc mã sản phẩm...">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                    <button type="submit" class="btn btn-custom search-btn"><i class="fas fa-check me-2"></i>OK</button>
                </form>
            </div>

            <ul class="nav nav-tabs" id="pricingTabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" id="category-tab" data-bs-toggle="tab" href="#category">Tỷ lệ lợi nhuận theo loại</a></li>
                <li class="nav-item"><a class="nav-link" id="product-tab" data-bs-toggle="tab" href="#product">Tỷ lệ lợi nhuận theo sản phẩm</a></li>
                <li class="nav-item"><a class="nav-link" id="import-tab" data-bs-toggle="tab" href="#import">Tra cứu theo lô hàng</a></li>
            </ul>

            <div class="tab-content" id="pricingTabsContent">
                <div class="tab-pane fade show active" id="category">
                    <div class="card card-custom mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Tỷ lệ lợi nhuận theo loại sản phẩm</h5>
                            <div class="dropdown filter-dropdown">
                                <button class="btn btn-custom dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="fas fa-filter me-2"></i>Lọc & Tra cứu</button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item filter-option" href="#" data-filter-type="cost-price-cat"><i class="fas fa-money-bill-wave me-2"></i>Tra cứu giá vốn</a></li>
                                    <li><a class="dropdown-item filter-option" href="#" data-filter-type="profit-cat"><i class="fas fa-chart-line me-2"></i>Tra cứu lợi nhuận</a></li>
                                    <li><a class="dropdown-item filter-option" href="#" data-filter-type="selling-price-cat"><i class="fas fa-tag me-2"></i>Tra cứu giá bán</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="category-table">
                                    <thead><th>Mã loại</th><th>Tên loại</th><th>Giá vốn TB (VNĐ)</th><th>Giá bán TB (VNĐ)</th><th>Số lượng SP</th><th>Tỷ lệ lợi nhuận TB (%)</th></thead>
                                    <tbody id="category-tbody"><tr><td colspan="6" class="text-center">Đang tải...</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="product">
                    <div class="card card-custom mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Tỷ lệ lợi nhuận theo sản phẩm</h5>
                            <div class="dropdown filter-dropdown">
                                <button class="btn btn-custom dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="fas fa-filter me-2"></i>Lọc & Tra cứu</button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item filter-option" href="#" data-filter-type="cost-price-prod"><i class="fas fa-money-bill-wave me-2"></i>Tra cứu giá vốn</a></li>
                                    <li><a class="dropdown-item filter-option" href="#" data-filter-type="profit-prod"><i class="fas fa-chart-line me-2"></i>Tra cứu lợi nhuận</a></li>
                                    <li><a class="dropdown-item filter-option" href="#" data-filter-type="selling-price-prod"><i class="fas fa-tag me-2"></i>Tra cứu giá bán</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="product-table">
                                    <thead><th>Mã SP</th><th>Tên sản phẩm</th><th>Loại</th><th>Giá vốn (VNĐ)</th><th>Giá bán (VNĐ)</th><th>Tỷ lệ lợi nhuận (%)</th><th>Thao tác</th></thead>
                                    <tbody id="product-tbody"><tr><td colspan="7" class="text-center">Đang tải...</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="import">
                    <div class="card card-custom mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Tra cứu giá vốn, % lợi nhuận theo lô hàng nhập</h5>
                            <select id="product-filter-import" class="form-select" style="width: 250px;">
                                <option value="">Tất cả sản phẩm</option>
                                <?php foreach ($categories as $cat): ?>
                                    <optgroup label="<?= htmlspecialchars($cat['name']) ?>">
                                        <?php $stmt = $pdo->prepare("SELECT id, name FROM products WHERE category_id = ? ORDER BY name"); $stmt->execute([$cat['id']]); $prods = $stmt->fetchAll(); foreach ($prods as $prod): ?>
                                            <option value="<?= $prod['id'] ?>"><?= htmlspecialchars($prod['name']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="import-table">
                                    <thead><th>Mã phiếu</th><th>Ngày nhập</th><th>Sản phẩm</th><th>Số lượng</th><th>Giá nhập (VNĐ)</th><th>Giá bán hiện tại (VNĐ)</th><th>Lợi nhuận (%)</th></thead>
                                    <tbody id="import-tbody"><tr><td colspan="7" class="text-center">Đang tải...</td></tr></tbody>
                                </table>
                            </div>
                        </div>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let productsList = [];

        $('#toggle-sidebar').click(function() {
            const sidebar = $('.sidebar');
            const mainContent = $('.main-content');
            if (sidebar.width() === 70) { sidebar.width(250); mainContent.css('margin-left', '250px'); $('.sidebar .nav-link span').show(); }
            else { sidebar.width(70); mainContent.css('margin-left', '70px'); $('.sidebar .nav-link span').hide(); }
        });

        function adjustSidebar() {
            if (window.innerWidth <= 768) { $('.sidebar').width(70); $('.main-content').css('margin-left', '70px'); $('.sidebar .nav-link span').hide(); }
            else { $('.sidebar').width(250); $('.main-content').css('margin-left', '250px'); $('.sidebar .nav-link span').show(); }
        }
        adjustSidebar();
        $(window).resize(adjustSidebar);

        function loadCategories() {
            $.getJSON('pricing.php', { action: 'getCategories' }, function(res) {
                if (res.success) renderCategories(res.data);
                else $('#category-tbody').html('<tr><td colspan="6" class="text-center text-danger">Lỗi tải dữ liệu</td></tr>');
            });
        }

        function renderCategories(data) {
            if (!data.length) { $('#category-tbody').html('<tr><td colspan="6" class="text-center">Không có dữ liệu</td></tr>'); return; }
            let html = '';
            data.forEach(cat => {
                const avgCost = new Intl.NumberFormat('vi-VN').format(cat.avg_cost_price || 0);
                const avgSelling = new Intl.NumberFormat('vi-VN').format(cat.avg_selling_price || 0);
                const avgProfit = (cat.avg_profit_percent || 0).toFixed(2);
                html += `<tr><td>CAT${String(cat.id).padStart(3, '0')}</td><td>${escapeHtml(cat.name)}</td><td class="cost-price">${avgCost}</td><td class="selling-price">${avgSelling}</td><td>${cat.product_count || 0}</td><td><span class="profit-percentage">${avgProfit}%</span></td></tr>`;
            });
            $('#category-tbody').html(html);
        }

        function loadProducts(filters = {}) {
            $.getJSON('pricing.php', { action: 'getProducts', ...filters }, function(res) {
                if (res.success) renderProducts(res.data);
                else $('#product-tbody').html('<tr><td colspan="7" class="text-center text-danger">Lỗi tải dữ liệu</td></tr>');
            });
        }

        function renderProducts(products) {
            if (!products.length) { $('#product-tbody').html('<tr><td colspan="7" class="text-center">Không có sản phẩm nào</td></tr>'); return; }
            let html = '';
            products.forEach(p => {
                const cost = new Intl.NumberFormat('vi-VN').format(p.cost_price);
                const selling = new Intl.NumberFormat('vi-VN').format(p.selling_price);
                const profitPercent = p.profit_percent ? p.profit_percent.toFixed(2) : '0.00';
                html += `<tr data-id="${p.id}"><td>${escapeHtml(p.code)}</td><td>${escapeHtml(p.name)}</td><td>${escapeHtml(p.category_name)}</td><td class="cost-price">${cost}</td><td class="selling-price">${selling}</td><td><div class="profit-cell" id="profit-cell-${p.id}"><span class="profit-percentage">${profitPercent}%</span><button class="btn btn-sm btn-custom edit-profit" data-id="${p.id}" data-profit="${profitPercent}"><i class="fas fa-edit"></i> Sửa</button></div></td><td><button class="btn btn-sm btn-info view-lots" data-id="${p.id}"><i class="fas fa-boxes"></i> Xem lô nhập</button></td></tr>`;
            });
            $('#product-tbody').html(html);
        }

        function editProfit(productId, currentProfit) {
            const profitCell = $(`#profit-cell-${productId}`);
            const originalHtml = profitCell.html();
            const editForm = $('<div class="edit-form"></div>');
            const input = $('<input type="number" class="editable-input" step="0.5" min="0" max="100">').val(currentProfit);
            const actions = $('<div class="edit-actions"></div>');
            const saveBtn = $('<button class="save-btn">Lưu</button>');
            const cancelBtn = $('<button class="cancel-btn">Hủy</button>');
            saveBtn.click(function() {
                const newProfit = parseFloat(input.val());
                if (isNaN(newProfit) || newProfit < 0) { alert('Vui lòng nhập tỷ lệ hợp lệ (>=0)'); return; }
                $.post('pricing.php', { action: 'updateProfit', product_id: productId, profit_percent: newProfit }, function(res) {
                    if (res.success) {
                        const newSelling = new Intl.NumberFormat('vi-VN').format(res.new_selling_price);
                        $(`tr[data-id="${productId}"] .selling-price`).text(newSelling);
                        profitCell.html(`<span class="profit-percentage">${newProfit}%</span> <button class="btn btn-sm btn-custom edit-profit" data-id="${productId}" data-profit="${newProfit}"><i class="fas fa-edit"></i> Sửa</button>`);
                        profitCell.find('.edit-profit').click(function() { editProfit(productId, newProfit); });
                        showNotification('Đã cập nhật tỷ lệ lợi nhuận');
                    } else alert('Lỗi: ' + res.error);
                }, 'json');
            });
            cancelBtn.click(function() { profitCell.html(originalHtml); profitCell.find('.edit-profit').click(function() { editProfit(productId, currentProfit); }); });
            actions.append(saveBtn, cancelBtn);
            editForm.append(input, actions);
            profitCell.empty().append(editForm);
            input.focus();
        }

        function loadImportLots(filters = {}) {
            $.getJSON('pricing.php', { action: 'getImportLots', ...filters }, function(res) {
                if (res.success) renderImportLots(res.data);
                else $('#import-tbody').html('<tr><td colspan="7" class="text-center text-danger">Lỗi tải dữ liệu</td></tr>');
            });
        }

        function renderImportLots(lots) {
            if (!lots.length) { $('#import-tbody').html('<tr><td colspan="7" class="text-center">Không có dữ liệu lô nhập</td></tr>'); return; }
            let html = '';
            lots.forEach(lot => {
                const profitPercent = lot.profit_percent.toFixed(2);
                html += `<tr><td>${escapeHtml(lot.import_code)}</td><td>${new Date(lot.import_date).toLocaleDateString('vi-VN')}</td><td>${escapeHtml(lot.product_name)}</td><td>${lot.quantity}</td><td class="cost-price">${new Intl.NumberFormat('vi-VN').format(lot.unit_cost)}</td><td class="selling-price">${new Intl.NumberFormat('vi-VN').format(lot.selling_price)}</td><td><span class="profit-percentage">${profitPercent}%</span></td></tr>`;
            });
            $('#import-tbody').html(html);
        }

        $('#global-search-form').submit(function(e) {
            e.preventDefault();
            const searchTerm = $('#global-search-input').val().trim();
            $('#product-tab').tab('show');
            loadProducts({ search: searchTerm });
        });

        $('.filter-option').click(function(e) {
            e.preventDefault();
            const type = $(this).data('filter-type');
            if (type === 'cost-price-prod' || type === 'cost-price-cat') { alert('Tính năng đang phát triển - Vui lòng nhập trực tiếp vào ô tìm kiếm'); }
            else if (type === 'profit-prod' || type === 'profit-cat') { alert('Tính năng đang phát triển - Vui lòng nhập trực tiếp vào ô tìm kiếm'); }
            else if (type === 'selling-price-prod' || type === 'selling-price-cat') { alert('Tính năng đang phát triển - Vui lòng nhập trực tiếp vào ô tìm kiếm'); }
        });

        $('#product-filter-import').change(function() {
            const productId = $(this).val();
            if (productId) loadImportLots({ product_id: productId });
            else loadImportLots();
        });

        $(document).on('click', '.edit-profit', function() { const productId = $(this).data('id'); const currentProfit = $(this).data('profit'); editProfit(productId, currentProfit); });
        $(document).on('click', '.view-lots', function() { const productId = $(this).data('id'); $('#import-tab').tab('show'); loadImportLots({ product_id: productId }); });

        function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }
        function showNotification(message) { const alertDiv = $('<div class="alert alert-success alert-dismissible fade show" role="alert">' + message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'); $('.page-content').prepend(alertDiv); setTimeout(() => alertDiv.remove(), 3000); }

        loadCategories();
        loadProducts();
        loadImportLots();
    </script>
</body>
</html>