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
            case 'get_products':
                $stmt = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");
                $products = $stmt->fetchAll();
                echo json_encode($products);
                break;

            case 'get_stock_at_date':
                $product_id = (int)($_GET['product_id'] ?? 0);
                $date = $_GET['date'] ?? '';
                if (!$date) throw new Exception('Vui lòng chọn ngày');
                
                // Kiểm tra ngày không được vượt quá ngày hiện tại
                $current_date = date('Y-m-d');
                if ($date > $current_date) {
                    throw new Exception('Không thể tra cứu tồn kho trong tương lai. Vui lòng chọn ngày ≤ ' . date('d/m/Y', strtotime($current_date)));
                }

                // Nếu có chọn sản phẩm cụ thể
                if ($product_id > 0) {
                    $stmt = $pdo->prepare("SELECT SUM(d.quantity) as total_import
                                           FROM import_details d
                                           JOIN imports i ON d.import_id = i.id
                                           WHERE d.product_id = ? AND i.status = 'completed' AND i.import_date <= ?");
                    $stmt->execute([$product_id, $date]);
                    $total_import = $stmt->fetch()['total_import'] ?? 0;

                    $stmt = $pdo->prepare("SELECT SUM(od.quantity) as total_export
                                           FROM order_details od
                                           JOIN orders o ON od.order_id = o.id
                                           WHERE od.product_id = ? AND o.status != 'cancelled' AND o.status != 'new' AND o.order_date <= ?");
                    $stmt->execute([$product_id, $date]);
                    $total_export = $stmt->fetch()['total_export'] ?? 0;

                    $stock = $total_import - $total_export;
                    
                    $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                    $stmt->execute([$product_id]);
                    $product_name = $stmt->fetchColumn();
                    
                    echo json_encode([
                        'success' => true,
                        'type' => 'single',
                        'product_id' => $product_id,
                        'product_name' => $product_name,
                        'date' => $date,
                        'stock' => $stock,
                        'current_date' => $current_date
                    ]);
                } 
                // Nếu không chọn sản phẩm, lấy tất cả sản phẩm
                else {
                    // Lấy tất cả sản phẩm active kèm mã và loại
                    $stmt = $pdo->query("SELECT p.id, p.code, p.name, p.category_id, c.name as category_name 
                                         FROM products p 
                                         LEFT JOIN categories c ON p.category_id = c.id 
                                         WHERE p.status = 'active' 
                                         ORDER BY p.name");
                    $all_products = $stmt->fetchAll();
                    
                    $products_stock = [];
                    foreach ($all_products as $product) {
                        // Tính tổng nhập
                        $stmt = $pdo->prepare("SELECT SUM(d.quantity) as total_import
                                               FROM import_details d
                                               JOIN imports i ON d.import_id = i.id
                                               WHERE d.product_id = ? AND i.status = 'completed' AND i.import_date <= ?");
                        $stmt->execute([$product['id'], $date]);
                        $total_import = $stmt->fetch()['total_import'] ?? 0;
                        
                        // Tính tổng xuất
                        $stmt = $pdo->prepare("SELECT SUM(od.quantity) as total_export
                                               FROM order_details od
                                               JOIN orders o ON od.order_id = o.id
                                               WHERE od.product_id = ? AND o.status != 'cancelled' AND o.status != 'new' AND o.order_date <= ?");
                        $stmt->execute([$product['id'], $date]);
                        $total_export = $stmt->fetch()['total_export'] ?? 0;
                        
                        $stock = $total_import - $total_export;
                        
                        $products_stock[] = [
                            'id' => $product['id'],
                            'code' => $product['code'],
                            'name' => $product['name'],
                            'category_name' => $product['category_name'] ?? 'Chưa phân loại',
                            'stock' => $stock
                        ];
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'type' => 'all',
                        'date' => $date,
                        'products' => $products_stock,
                        'current_date' => $current_date
                    ]);
                }
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
    <title>Tra cứu tồn kho - Feane Restaurant</title>
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
        .result-card {
            background-color: #fff;
            border-radius: 12px;
            padding: 35px 20px;
            margin-top: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
        }
        .result-product-name {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .result-date-info {
            font-size: 0.95rem;
            color: #6c757d;
            margin-bottom: 25px;
            font-weight: normal;
        }
        .stock-value {
            font-size: 2.5rem;
            font-weight: 700;
            display: inline-block;
        }
        .stock-unit {
            font-size: 1.1rem;
            font-weight: normal;
            margin-left: 8px;
            color: #6c757d;
        }
        .date-warning {
            font-size: 0.75rem;
            margin-top: 5px;
            color: #6c757d;
        }
        .stock-container {
            margin-top: 10px;
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
        .btn-dark {
            border-radius: 8px;
            padding: 8px 16px;
        }
        .stock-high {
            color: #28a745;
        }
        .stock-medium {
            color: #ffc107;
        }
        .stock-low {
            color: #dc3545;
        }
        /* Table styles for all products */
        .products-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .products-table th,
        .products-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        .products-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--secondary-color);
        }
        .products-table tr:hover {
            background-color: #f8f9fa;
        }
        .stock-badge {
            font-size: 1rem;
            font-weight: 600;
        }
        .table-container {
            overflow-x: auto;
        }
        .text-center-cell {
            text-align: center;
            vertical-align: middle;
        }
        .low-stock-row {
            background-color: #fff3e0;
        }
        .out-stock-row {
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
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: 15px 20px;
        }
        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--secondary-color);
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

            <h2 class="mb-4">Tra cứu tồn kho</h2>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs stock-tabs" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory.php">Danh sách tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" href="inventory_detail.php">Tra cứu tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory_import.php">Tra cứu nhập kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory_export.php">Tra cứu xuất kho</a>
                </li>
            </ul>

            <!-- Tra cứu tồn tại thời điểm -->
            <div class="search-section">
                <h5><i class="fas fa-calendar-day me-2"></i>Tra cứu tồn kho tại thời điểm</h5>
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">Chọn sản phẩm <small class="text-muted">(Để trống để xem tất cả)</small></label>
                        <select id="stock-date-product" class="form-select">
                            <option value="">-- Tất cả sản phẩm --</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Chỉ có thể tra cứu đến ngày <?php echo date('d/m/Y'); ?></label>
                        <input type="date" id="stock-date" class="form-control" max="<?php echo date('Y-m-d'); ?>">
                        <div class="date-warning">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button id="check-stock-date" class="btn btn-dark w-100">Tra cứu</button>
                    </div>
                </div>
                <div id="stock-date-result" class="alert alert-info mt-3" style="display: none;"></div>
            </div>

            <!-- Kết quả chi tiết (cho 1 sản phẩm) -->
            <div id="detail-result" class="result-card" style="display: none;">
                <div class="result-product-name" id="result-product-name"></div>
                <div class="result-date-info">
                    <i class="fas fa-calendar-alt me-2"></i>Tồn kho tại ngày <strong id="result-date"></strong>
                </div>
                <div class="stock-container">
                    <span class="stock-value" id="result-stock"></span>
                    <span class="stock-unit">sản phẩm</span>
                </div>
            </div>

            <!-- Kết quả bảng (cho tất cả sản phẩm) -->
            <div id="all-products-result" class="card card-custom" style="display: none;">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>Danh sách tồn kho tất cả sản phẩm tại ngày <strong id="all-products-date"></strong>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover products-table">
                            <thead>
                                
                                    <th>Mã SP</th>
                                    <th>Tên sản phẩm</th>
                                    <th>Loại</th>
                                    <th class="text-center">Số lượng tồn</th>
                                
                            </thead>
                            <tbody id="all-products-table-body">
                                <tr>
                                    <td colspan="4" class="text-center">Đang tải...</td>
                                </tr>
                            </tbody>
                        </table>
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

        function loadProductSelect() {
            $.getJSON('inventory_detail.php', { action: 'get_products' }, function(products) {
                let options = '<option value="">-- Tất cả sản phẩm --</option>';
                products.forEach(p => {
                    options += `<option value="${p.id}">${escapeHtml(p.name)}</option>`;
                });
                $('#stock-date-product').html(options);
            });
        }

        $('#check-stock-date').click(function() {
            const productId = $('#stock-date-product').val();
            const date = $('#stock-date').val();
            
            if (!date) { alert('Vui lòng chọn ngày'); return; }
            
            // Ẩn các kết quả cũ
            $('#detail-result').hide();
            $('#all-products-result').hide();
            
            // Hiển thị loading
            $('#stock-date-result').show().removeClass('alert-danger alert-info').addClass('alert-info').html('<div class="text-center"><i class="fas fa-spinner fa-spin me-2"></i>Đang tra cứu...</div>');
            
            $.getJSON('inventory_detail.php', { action: 'get_stock_at_date', product_id: productId, date: date }, function(res) {
                if (res.success) {
                    if (res.type === 'single') {
                        // Hiển thị kết quả cho 1 sản phẩm
                        $('#result-product-name').text(res.product_name);
                        $('#result-product-name').css('color', '#222831');
                        $('#result-date').text(new Date(res.date).toLocaleDateString('vi-VN'));
                        
                        // Xác định màu sắc cho số lượng tồn
                        let stockColor = '';
                        if (res.stock <= 0) {
                            stockColor = '#dc3545';
                        } else if (res.stock <= 10) {
                            stockColor = '#ffc107';
                        } else {
                            stockColor = '#28a745';
                        }
                        
                        // Hiển thị số lượng với màu sắc tương ứng
                        $('#result-stock').html(`<span style="color: ${stockColor};">${res.stock}</span>`);
                        
                        $('#detail-result').show();
                        $('#all-products-result').hide();
                        $('#stock-date-result').hide();
                    } else if (res.type === 'all') {
                        // Hiển thị bảng tất cả sản phẩm
                        $('#all-products-date').text(new Date(res.date).toLocaleDateString('vi-VN'));
                        
                        let tableHtml = '';
                        res.products.forEach(product => {
                            let stockValue = product.stock;
                            let rowClass = '';
                            let stockClass = '';
                            
                            // Xác định class cho hàng và số lượng
                            if (stockValue <= 0) {
                                rowClass = 'out-stock-row';
                                stockClass = 'status-danger';
                            } else if (stockValue <= 10) {
                                rowClass = 'low-stock-row';
                                stockClass = 'status-warning';
                            } else {
                                stockClass = 'status-success';
                            }
                            
                            tableHtml += `
                                <tr class="${rowClass}">
                                    <td class="align-middle">${escapeHtml(product.code)}</td>
                                    <td class="align-middle"><strong>${escapeHtml(product.name)}</strong></td>
                                    <td class="align-middle">${escapeHtml(product.category_name)}</td>
                                    <td class="text-center align-middle">
                                        <strong class="${stockClass}">${stockValue}</strong>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        if (res.products.length === 0) {
                            tableHtml = '<tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-box-open me-2"></i>Không có sản phẩm nào</td></tr>';
                        }
                        
                        $('#all-products-table-body').html(tableHtml);
                        $('#all-products-result').show();
                        $('#detail-result').hide();
                        $('#stock-date-result').hide();
                    }
                } else {
                    $('#stock-date-result').show().removeClass('alert-info').addClass('alert-danger').html('<i class="fas fa-exclamation-triangle me-2"></i>' + (res.error || 'Không thể tra cứu'));
                }
            }).fail(function() {
                $('#stock-date-result').show().removeClass('alert-info').addClass('alert-danger').html('<i class="fas fa-exclamation-triangle me-2"></i>Không thể kết nối đến máy chủ');
            });
        });

        const today = new Date();
        const formatDate = (date) => date.toISOString().slice(0,10);
        $('#stock-date').val(formatDate(today));

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        loadProductSelect();
    </script>
</body>
</html>