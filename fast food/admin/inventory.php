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

// Xử lý AJAX request
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];

    try {
        switch ($action) {
            case 'search_suggestions':
                $search = $_GET['search'] ?? '';
                if (strlen($search) < 1) {
                    echo json_encode([]);
                    break;
                }
                $stmt = $pdo->prepare("SELECT id, code, name FROM products WHERE status = 'active' AND (name LIKE :search OR code LIKE :search) LIMIT 10");
                $stmt->execute([':search' => "%$search%"]);
                $suggestions = $stmt->fetchAll();
                echo json_encode($suggestions);
                break;

            case 'list':
                $search = $_GET['search'] ?? '';
                $max_stock = isset($_GET['max_stock']) && $_GET['max_stock'] !== '' ? (int)$_GET['max_stock'] : null;
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;

                // Lấy tất cả sản phẩm active
                $sql = "SELECT p.id, p.code, p.name, p.category_id,
                               c.name as category_name,
                               COALESCE((
                                   SELECT SUM(d.quantity)
                                   FROM import_details d
                                   JOIN imports i ON d.import_id = i.id
                                   WHERE d.product_id = p.id AND i.status = 'completed'
                               ), 0) AS total_import,
                               COALESCE((
                                   SELECT SUM(od.quantity)
                                   FROM order_details od
                                   JOIN orders o ON od.order_id = o.id
                                   WHERE od.product_id = p.id AND o.status != 'cancelled' AND o.status != 'new'
                               ), 0) AS total_export
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.id
                        WHERE p.status = 'active'";
                $params = [];

                if ($search) {
                    $sql .= " AND (p.name LIKE :search OR p.code LIKE :search)";
                    $params[':search'] = "%$search%";
                }

                $sql .= " ORDER BY p.name";

                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $all_products = $stmt->fetchAll();

                // Cập nhật stock_quantity từ dữ liệu nhập/xuất thực tế và lọc
                $filtered_products = [];
                foreach ($all_products as $product) {
                    $actual_stock = $product['total_import'] - $product['total_export'];
                    $product['stock_quantity'] = $actual_stock;
                    
                    // Lọc theo số lượng tồn <= max_stock (nếu có)
                    if ($max_stock !== null && $max_stock > 0) {
                        if ($actual_stock <= $max_stock) {
                            $filtered_products[] = $product;
                        }
                    } else {
                        $filtered_products[] = $product;
                    }
                }

                // Tính tổng số bản ghi sau khi lọc
                $total_records = count($filtered_products);
                $total_pages = ceil($total_records / $limit);
                
                // Lấy dữ liệu cho trang hiện tại
                $products = array_slice($filtered_products, $offset, $limit);

                echo json_encode([
                    'success' => true,
                    'data' => $products,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $total_pages,
                        'total_records' => $total_records
                    ]
                ]);
                break;

            case 'get_products':
                $stmt = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");
                $products = $stmt->fetchAll();
                echo json_encode($products);
                break;

            case 'get_product_info':
                $product_id = (int)($_GET['id'] ?? 0);
                if (!$product_id) throw new Exception('Missing product ID');
                
                $stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.category_id, c.name as category_name, 
                                              p.cost_price, p.selling_price, p.stock_quantity,
                                              COALESCE((SELECT SUM(d.quantity) FROM import_details d JOIN imports i ON d.import_id = i.id WHERE d.product_id = p.id AND i.status = 'completed'), 0) as total_import,
                                              COALESCE((SELECT SUM(od.quantity) FROM order_details od JOIN orders o ON od.order_id = o.id WHERE od.product_id = p.id AND o.status != 'cancelled' AND o.status != 'new'), 0) as total_export
                                       FROM products p 
                                       LEFT JOIN categories c ON p.category_id = c.id 
                                       WHERE p.id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Product not found');
                
                $product['stock_quantity'] = $product['total_import'] - $product['total_export'];
                
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
            background-color: #e9ecef;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid var(--primary-color);
        }
        .filter-section h3 {
            margin-bottom: 20px;
            color: var(--dark-color);
            font-size: 1.3rem;
        }
        .stock-tabs {
            margin-bottom: 25px;
            border-bottom: 2px solid #e9ecef;
        }
        .stock-tabs .nav-link {
            color: var(--secondary-color);
            font-weight: 500;
            padding: 12px 24px;
            margin-bottom: -2px;
            border: none;
            border-radius: 0;
        }
        .stock-tabs .nav-link:hover {
            color: var(--primary-color);
        }
        .stock-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background-color: transparent;
        }
        .low-stock {
            background-color: #fff3e0;
        }
        .out-stock {
            background-color: #ffe0e0;
        }
        .status-warning {
            color: #ffc107;
            font-weight: bold;
        }
        .status-danger {
            color: #dc3545;
            font-weight: bold;
        }
        .status-success {
            color: #28a745;
            font-weight: bold;
        }
        .product-link {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
            cursor: pointer;
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
        .info-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .info-value {
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--secondary-color);
        }
        .stock-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .stock-summary-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .stock-summary-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--secondary-color);
        }
        .btn-primary-custom {
            background-color: var(--primary-color);
            color: var(--dark-color);
            border: none;
        }
        .btn-primary-custom:hover {
            background-color: #e6a500;
            color: var(--dark-color);
        }
        .text-center-cell {
            text-align: center;
            vertical-align: middle;
        }
        .pagination-wrapper {
            margin-top: 20px;
        }
        
        /* Autocomplete styles */
        .autocomplete-container {
            position: relative;
        }
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 5px 5px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: none;
        }
        .autocomplete-suggestions.show {
            display: block;
        }
        .autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s;
        }
        .autocomplete-item:hover {
            background-color: #fff3e0;
        }
        .autocomplete-item strong {
            color: var(--primary-color);
        }
        .autocomplete-item .product-code {
            font-size: 0.85rem;
            color: #6c757d;
            margin-left: 10px;
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

            <h2 class="mb-4">Quản lý Tồn kho</h2>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs stock-tabs" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" href="inventory.php">Danh sách tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory_detail.php">Tra cứu tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="import_export.php">Tra cứu nhập - xuất kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="out_of_stock.php">Sản phẩm sắp hết hàng</a>
                </li>
            </ul>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3><i class="fas fa-filter me-2"></i>Bộ lọc tồn kho</h3>
                <form id="stock-filter-form">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="search-name" class="form-label">Tìm sản phẩm</label>
                            <div class="autocomplete-container">
                                <input type="text" class="form-control" id="search-name" name="search" placeholder="Nhập tên hoặc mã sản phẩm..." autocomplete="off">
                                <div class="autocomplete-suggestions" id="autocomplete-suggestions"></div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="max-stock" class="form-label">Lọc tồn kho ≤</label>
                            <input type="number" class="form-control" id="max-stock" name="max-stock" placeholder="Nhập số lượng tối đa">
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-primary-custom flex-grow-1"><i class="fas fa-search me-2"></i>Tìm kiếm</button>
                                <button type="reset" class="btn btn-outline-secondary flex-grow-1"><i class="fas fa-undo me-2"></i>Đặt lại</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bảng tồn kho -->
            <div class="card card-custom">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Danh sách tồn kho</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Mã SP</th>
                                    <th>Tên sản phẩm</th>
                                    <th>Loại</th>
                                    <th class="text-center">Số lượng tồn</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody id="stock-table-body">
                                <tr><td colspan="5" class="text-center">Đang tải...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-wrapper">
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center" id="pagination-container"></ul>
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
        let currentFilters = { search: '', max_stock: '' };
        let searchTimeout = null;

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

        // Autocomplete functionality
        $('#search-name').on('input', function() {
            const searchText = $(this).val().trim();
            
            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            if (searchText.length < 1) {
                $('#autocomplete-suggestions').removeClass('show').empty();
                return;
            }
            
            // Debounce search
            searchTimeout = setTimeout(function() {
                $.ajax({
                    url: 'inventory.php',
                    method: 'GET',
                    data: {
                        action: 'search_suggestions',
                        search: searchText
                    },
                    dataType: 'json',
                    success: function(suggestions) {
                        renderSuggestions(suggestions, searchText);
                    },
                    error: function() {
                        console.error('Failed to load suggestions');
                    }
                });
            }, 300);
        });
        
        function renderSuggestions(suggestions, searchText) {
            const container = $('#autocomplete-suggestions');
            
            if (!suggestions.length) {
                container.removeClass('show').empty();
                return;
            }
            
            let html = '';
            suggestions.forEach(item => {
                // Highlight matching text
                const nameHighlighted = item.name.replace(new RegExp(`(${escapeRegExp(searchText)})`, 'gi'), '<strong>$1</strong>');
                const codeHighlighted = item.code.replace(new RegExp(`(${escapeRegExp(searchText)})`, 'gi'), '<strong>$1</strong>');
                
                html += `
                    <div class="autocomplete-item" data-id="${item.id}" data-name="${escapeHtml(item.name)}" data-code="${item.code}">
                        <i class="fas fa-box me-2 text-muted"></i>
                        ${nameHighlighted}
                        <span class="product-code">(${codeHighlighted})</span>
                    </div>
                `;
            });
            
            container.html(html).addClass('show');
            
            // Add click event to suggestions
            $('.autocomplete-item').off('click').on('click', function() {
                const productName = $(this).data('name');
                $('#search-name').val(productName);
                container.removeClass('show');
                // Auto search when selecting suggestion
                currentFilters.search = productName;
                currentPage = 1;
                loadStock();
            });
        }
        
        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
        // Close suggestions when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.autocomplete-container').length) {
                $('#autocomplete-suggestions').removeClass('show').empty();
            }
        });

        function loadStock() {
            const params = {
                action: 'list',
                page: currentPage,
                search: currentFilters.search,
                max_stock: currentFilters.max_stock !== '' ? currentFilters.max_stock : null
            };
            
            $('#stock-table-body').html('<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Đang tải...</td></tr>');
            
            $.getJSON('inventory.php', params, function(response) {
                if (response.success) {
                    renderStockTable(response.data);
                    renderPagination(response.pagination);
                } else {
                    $('#stock-table-body').html('<tr><td colspan="5" class="text-center text-danger">Lỗi: ' + (response.error || 'Không thể tải dữ liệu') + '</td></tr>');
                }
            }).fail(function() {
                $('#stock-table-body').html('<tr><td colspan="5" class="text-center text-danger">Không thể kết nối đến máy chủ</td></tr>');
            });
        }

        function getStockStatus(stock) {
            if (stock <= 0) {
                return {
                    text: 'Hết hàng',
                    class: 'status-danger',
                    icon: 'fa-times-circle'
                };
            } else if (stock <= 10) {
                return {
                    text: 'Sắp hết hàng',
                    class: 'status-warning',
                    icon: 'fa-exclamation-triangle'
                };
            } else {
                return {
                    text: 'Đủ hàng',
                    class: 'status-success',
                    icon: 'fa-check-circle'
                };
            }
        }

        function renderStockTable(products) {
            const tbody = $('#stock-table-body');
            if (!products.length) {
                tbody.html('<tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-box-open me-2"></i>Không tìm thấy sản phẩm nào</td></tr>');
                return;
            }
            let html = '';
            products.forEach(p => {
                const status = getStockStatus(p.stock_quantity);
                const rowClass = p.stock_quantity <= 0 ? 'out-stock' : (p.stock_quantity <= 10 ? 'low-stock' : '');
                html += `
                    <tr class="${rowClass}">
                        <td class="align-middle">${escapeHtml(p.code)}</td>
                        <td class="align-middle"><a href="#" class="product-link view-detail" data-id="${p.id}">${escapeHtml(p.name)}</a></td>
                        <td class="align-middle">${escapeHtml(p.category_name || '')}</td>
                        <td class="text-center align-middle"><strong>${p.stock_quantity}</strong></td>
                        <td class="align-middle"><span class="${status.class}"><i class="fas ${status.icon} me-1"></i>${status.text}</span></td>
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
                        <a class="page-link" href="#" data-page="${pagination.current_page - 1}">« Trước</a>
                     </li>`;
            
            // Hiển thị tối đa 5 số trang
            let startPage = Math.max(1, pagination.current_page - 2);
            let endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
            
            if (startPage > 1) {
                html += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
                if (startPage > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<li class="page-item ${i === pagination.current_page ? 'active' : ''}">
                            <a class="page-link" href="#" data-page="${i}">${i}</a>
                         </li>`;
            }
            
            if (endPage < pagination.total_pages) {
                if (endPage < pagination.total_pages - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                html += `<li class="page-item"><a class="page-link" href="#" data-page="${pagination.total_pages}">${pagination.total_pages}</a></li>`;
            }
            
            html += `<li class="page-item ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${pagination.current_page + 1}">Sau »</a>
                     </li>`;
            
            container.html(html);
            container.find('.page-link').click(function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                if (page && page !== currentPage) {
                    currentPage = page;
                    loadStock();
                    $('html, body').animate({ scrollTop: $('#stock-table-body').offset().top - 100 }, 300);
                }
            });
        }

        $('#stock-filter-form').submit(function(e) {
            e.preventDefault();
            currentFilters.search = $('#search-name').val().trim();
            currentFilters.max_stock = $('#max-stock').val();
            currentPage = 1;
            loadStock();
            // Close suggestions after search
            $('#autocomplete-suggestions').removeClass('show');
        });
        
        $('#stock-filter-form button[type="reset"]').click(function() {
            $('#search-name').val('');
            $('#max-stock').val('');
            currentFilters = { search: '', max_stock: '' };
            currentPage = 1;
            loadStock();
            $('#autocomplete-suggestions').removeClass('show').empty();
        });

        function showProductDetail(productId) {
            $('#product-detail-content').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="mt-2 text-muted">Đang tải thông tin...</div></div>');
            $('#productDetailModal').modal('show');

            $.getJSON('inventory.php', { action: 'get_product_info', id: productId })
                .done(function(productRes) {
                    if (!productRes.success) {
                        $('#product-detail-content').html('<div class="alert alert-danger">' + (productRes.error || 'Không tìm thấy sản phẩm') + '</div>');
                        return;
                    }
                    const product = productRes.product;
                    
                    const formatMoney = (amount) => new Intl.NumberFormat('vi-VN').format(amount);
                    const status = getStockStatus(product.stock_quantity);

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
                            <div class="col-md-6"><div class="info-card"><div class="info-label">Tồn kho hiện tại</div><div class="info-value"><span class="${status.class}">${product.stock_quantity}</span></div></div></div>
                        </div>
                        <div class="stock-summary mb-4"><div class="row text-center"><div class="col-6"><div class="stock-summary-label">Tổng nhập</div><div class="stock-summary-value">${product.total_import}</div></div><div class="col-6"><div class="stock-summary-label">Tổng xuất</div><div class="stock-summary-value">${product.total_export}</div></div></div></div>
                    `;
                    $('#product-detail-content').html(html);
                }).fail(function() {
                    $('#product-detail-content').html('<div class="alert alert-danger">Không thể tải thông tin sản phẩm</div>');
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

        // Khởi tạo
        loadStock();
    </script>
</body>
</html>