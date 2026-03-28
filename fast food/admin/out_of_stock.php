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

// Lấy tổng số sản phẩm hết hàng và sắp hết hàng từ database (không phụ thuộc tìm kiếm)
$stmt = $pdo->prepare("SELECT 
    SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_count,
    SUM(CASE WHEN stock_quantity BETWEEN 1 AND 10 THEN 1 ELSE 0 END) as low_stock_count
    FROM products");
$stmt->execute();
$stats = $stmt->fetch();

$total_out_of_stock = $stats['out_of_stock_count'] ?? 0;
$total_low_stock = $stats['low_stock_count'] ?? 0;

// Xử lý AJAX request
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];

    try {
        switch ($action) {
            case 'get_out_of_stock_products':
                $search = $_GET['search'] ?? '';
                $status_filter = $_GET['status_filter'] ?? 'all';
                
                // Chỉ lấy sản phẩm hết hàng hoặc sắp hết hàng (tồn kho <= 10)
                $sql = "SELECT p.*, c.name as category_name 
                        FROM products p 
                        LEFT JOIN categories c ON p.category_id = c.id 
                        WHERE p.stock_quantity <= 10";
                $params = [];
                
                if (!empty($search)) {
                    $sql .= " AND (p.name LIKE :search OR p.code LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                
                if ($status_filter == 'out_of_stock') {
                    $sql .= " AND p.stock_quantity = 0";
                } elseif ($status_filter == 'low_stock') {
                    $sql .= " AND p.stock_quantity BETWEEN 1 AND 10";
                }
                
                $sql .= " ORDER BY p.stock_quantity ASC";
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->execute();
                $products = $stmt->fetchAll();
                
                // Xử lý thêm thông tin trạng thái
                foreach ($products as &$product) {
                    if ($product['stock_quantity'] == 0) {
                        $product['stock_status'] = 'out_of_stock';
                        $product['status_text'] = 'Hết hàng';
                        $product['status_class'] = 'danger';
                    } elseif ($product['stock_quantity'] <= 10) {
                        $product['stock_status'] = 'low_stock';
                        $product['status_text'] = 'Sắp hết hàng';
                        $product['status_class'] = 'warning';
                    }
                }
                
                echo json_encode(['success' => true, 'products' => $products]);
                break;
                
            case 'update_product_status':
                $product_id = (int)$_POST['product_id'];
                $new_status = $_POST['status'];
                
                // Kiểm tra sản phẩm có tồn tại và hết hàng không
                $stmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                
                if (!$product) {
                    throw new Exception('Sản phẩm không tồn tại');
                }
                
                if ($product['stock_quantity'] != 0) {
                    throw new Exception('Chỉ có thể ẩn sản phẩm khi đã hết hàng');
                }
                
                // Cập nhật trạng thái sản phẩm
                $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $product_id]);
                
                echo json_encode(['success' => true, 'message' => 'Cập nhật trạng thái sản phẩm thành công']);
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
    <title>Sản phẩm sắp hết hàng - Feane Restaurant</title>
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
        .search-section {
            background-color: #e9ecef;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid var(--primary-color);
        }
        .product-table {
            margin-top: 20px;
        }
        .product-table th {
            background-color: #e9ecef;
            font-weight: 600;
        }
        .badge-out-of-stock {
            background-color: #dc3545;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: 500;
        }
        .badge-low-stock {
            background-color: #ffc107;
            color: #856404;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: 500;
        }
        .status-text-danger {
            color: #dc3545;
            font-weight: 600;
        }
        .status-text-warning {
            color: #ffc107;
            font-weight: 600;
        }
        .btn-hide-product {
            background-color: #6c757d;
            color: white;
            transition: all 0.3s;
        }
        .btn-hide-product:hover {
            background-color: #5a6268;
            color: white;
        }
        .warning-icon {
            margin-right: 5px;
        }
        .stats-card {
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card.out-of-stock {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        .stats-card.low-stock {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
        }
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .card-active {
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            transform: scale(1.02);
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

            <h2 class="mb-4">Quản lý tồn kho</h2>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs stock-tabs" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory.php">Danh sách tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory_detail.php">Tra cứu tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="import_export.php">Tra cứu nhập - xuất kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" href="out_of_stock.php">Sản phẩm sắp hết hàng</a>
                </li>
            </ul>

            <!-- Stats Cards - Fixed numbers from database -->
            <div class="row" id="stats-cards">
                <div class="col-md-6 mb-3">
                    <div class="stats-card out-of-stock" id="out-of-stock-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-2">Sản phẩm hết hàng</h6>
                                <div class="stats-number" id="out-of-stock-count"><?php echo $total_out_of_stock; ?></div>
                            </div>
                            <i class="fas fa-box-open fa-3x opacity-50"></i>
                        </div>
                        <small class="mt-2 d-block">Cần cập nhật trạng thái ẩn</small>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="stats-card low-stock" id="low-stock-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-2">Sản phẩm sắp hết hàng</h6>
                                <div class="stats-number" id="low-stock-count"><?php echo $total_low_stock; ?></div>
                            </div>
                            <i class="fas fa-exclamation-triangle fa-3x opacity-50"></i>
                        </div>
                        <small class="mt-2 d-block">Cần nhập hàng ngay</small>
                    </div>
                </div>
            </div>

            <!-- Search Section -->
            <div class="search-section">
                <h5 class="mb-3"><i class="fas fa-search me-2"></i> Tra cứu sản phẩm</h5>
                <form id="search-form">
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label">Tìm kiếm theo tên hoặc mã</label>
                            <input type="text" class="form-control" id="search-input" name="search" placeholder="Nhập tên sản phẩm hoặc mã...">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-control" id="status-filter" name="status_filter">
                                <option value="all">Tất cả</option>
                                <option value="out_of_stock">Hết hàng</option>
                                <option value="low_stock">Sắp hết hàng</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-search me-1"></i> Tra cứu
                            </button>
                            <button type="button" class="btn btn-secondary" id="reset-btn">
                                <i class="fas fa-undo me-1"></i> Làm mới
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Loading Spinner -->
            <div class="loading-spinner" id="loading-spinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Đang tải dữ liệu...</p>
            </div>

            <!-- Products Table -->
            <div class="table-responsive product-table">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Mã sản phẩm</th>
                            <th>Tên sản phẩm</th>
                            <th>Danh mục</th>
                            <th class="text-center">Số lượng tồn</th>
                            <th>Trạng thái</th>
                            <th>Cảnh báo</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="product-table-body">
                        <tr>
                            <td colspan="7" class="text-center text-muted">Vui lòng nhập điều kiện tìm kiếm</td>
                        </tr>
                    </tbody>
                </table>
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

    <!-- Modal Xác nhận ẩn sản phẩm -->
    <div class="modal fade" id="hideProductModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye-slash me-2"></i> Xác nhận ẩn sản phẩm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Bạn có chắc chắn muốn ẩn sản phẩm <strong id="hide-product-name"></strong> không?</p>
                    <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i> Lưu ý: Sản phẩm sẽ không hiển thị trên website và người dùng sẽ không thể đặt hàng.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-danger" id="confirm-hide-btn">Xác nhận ẩn</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            let currentProductId = null;
            let currentProductName = null;

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

            // Load products (chỉ cập nhật bảng, không cập nhật số liệu thống kê)
            function loadProducts(search = '', statusFilter = 'all') {
                $('#loading-spinner').show();
                $('#product-table-body').html('<tr><td colspan="7" class="text-center">Đang tải dữ liệu...</td></tr>');

                $.ajax({
                    url: window.location.href,
                    type: 'GET',
                    data: {
                        action: 'get_out_of_stock_products',
                        search: search,
                        status_filter: statusFilter
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loading-spinner').hide();
                        
                        if (response.success) {
                            const products = response.products;
                            
                            if (products.length === 0) {
                                $('#product-table-body').html('<tr><td colspan="7" class="text-center text-muted">Không tìm thấy sản phẩm nào</td></tr>');
                            } else {
                                let html = '';
                                products.forEach(product => {
                                    html += `
                                        <tr>
                                            <td><strong>${product.code}</strong></td>
                                            <td>${product.name}</td>
                                            <td>${product.category_name || 'Chưa phân loại'}</td>
                                            <td class="text-center"><strong>${product.stock_quantity}</strong></td>
                                            <td>
                                                <span class="status-text-${product.status_class}">
                                                    <i class="fas ${product.stock_quantity == 0 ? 'fa-times-circle' : 'fa-exclamation-triangle'} me-1"></i>
                                                    ${product.status_text}
                                                </span>
                                            </td>
                                            <td>
                                    `;
                                    
                                    if (product.stock_quantity == 0) {
                                        html += `
                                            <span class="text-danger">
                                                <i class="fas fa-ban warning-icon"></i>
                                                Sản phẩm đã hết hàng! Vui lòng cập nhật trạng thái ẩn.
                                            </span>
                                        `;
                                    } else if (product.stock_quantity <= 10) {
                                        html += `
                                            <span class="text-warning">
                                                <i class="fas fa-truck warning-icon"></i>
                                                Cần nhập hàng ngay! Tồn kho chỉ còn ${product.stock_quantity} sản phẩm.
                                            </span>
                                        `;
                                    }
                                    
                                    html += `</td><td>`;
                                    
                                    if (product.stock_quantity == 0 && product.status == 'active') {
                                        html += `
                                            <button class="btn btn-sm btn-hide-product" onclick="showHideModal(${product.id}, '${product.name.replace(/'/g, "\\'")}')">
                                                <i class="fas fa-eye-slash me-1"></i> Ẩn sản phẩm
                                            </button>
                                        `;
                                    } else if (product.stock_quantity == 0 && product.status == 'inactive') {
                                        html += `
                                            <span class="text-muted">
                                                <i class="fas fa-check-circle me-1"></i> Đã ẩn
                                            </span>
                                        `;
                                    } else {
                                        html += `<span class="text-muted">Không thể ẩn</span>`;
                                    }
                                    
                                    html += `</td></tr>`;
                                });
                                
                                $('#product-table-body').html(html);
                            }
                        } else {
                            $('#product-table-body').html('<tr><td colspan="7" class="text-center text-danger">Lỗi: ' + response.error + '</td></tr>');
                        }
                    },
                    error: function() {
                        $('#loading-spinner').hide();
                        $('#product-table-body').html('<tr><td colspan="7" class="text-center text-danger">Lỗi kết nối đến server</td></tr>');
                    }
                });
            }

            // Search form submit - chỉ cập nhật bảng, không cập nhật 2 ô thống kê
            $('#search-form').submit(function(e) {
                e.preventDefault();
                const search = $('#search-input').val();
                const statusFilter = $('#status-filter').val();
                loadProducts(search, statusFilter);
            });

            // Reset button - chỉ reset bảng về hiển thị tất cả sản phẩm hết/sắp hết
            $('#reset-btn').click(function() {
                $('#search-input').val('');
                $('#status-filter').val('all');
                loadProducts('', 'all');
            });

            // Click on stats cards - lọc bảng theo trạng thái tương ứng
            $('#out-of-stock-card').click(function() {
                $('#status-filter').val('out_of_stock');
                $('#search-input').val('');
                loadProducts('', 'out_of_stock');
            });
            
            $('#low-stock-card').click(function() {
                $('#status-filter').val('low_stock');
                $('#search-input').val('');
                loadProducts('', 'low_stock');
            });

            // Show hide modal
            window.showHideModal = function(productId, productName) {
                currentProductId = productId;
                currentProductName = productName;
                $('#hide-product-name').text(productName);
                $('#hideProductModal').modal('show');
            };

            // Confirm hide product
            $('#confirm-hide-btn').click(function() {
                if (!currentProductId) return;
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'update_product_status',
                        product_id: currentProductId,
                        status: 'inactive'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#hideProductModal').modal('hide');
                            
                            // Hiển thị thông báo thành công
                            const toastHtml = `
                                <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
                                    <div class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
                                        <div class="toast-header bg-success text-white">
                                            <i class="fas fa-check-circle me-2"></i>
                                            <strong class="me-auto">Thành công</strong>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                                        </div>
                                        <div class="toast-body">
                                            ${response.message}
                                        </div>
                                    </div>
                                </div>
                            `;
                            $('body').append(toastHtml);
                            setTimeout(() => $('.toast').toast('hide'), 3000);
                            
                            // Reload lại dữ liệu bảng
                            const search = $('#search-input').val();
                            const statusFilter = $('#status-filter').val();
                            loadProducts(search, statusFilter);
                            
                            // Reload lại trang để cập nhật số liệu thống kê (hoặc có thể gọi AJAX riêng)
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            alert('Lỗi: ' + response.error);
                        }
                    },
                    error: function() {
                        alert('Lỗi kết nối đến server');
                    }
                });
            });

            // Load initial data - chỉ tải bảng, số liệu thống kê đã có sẵn từ PHP
            loadProducts('', 'all');
        });
    </script>
</body>
</html>