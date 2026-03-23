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

// Hàm định dạng tiền VNĐ
function formatVND($amount) {
    return number_format($amount, 0, ',', '.') . ' ₫';
}

// Lấy dữ liệu thống kê từ database
// 1. Tổng số đơn hàng mới (status = 'new')
$stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE status = 'new'");
$total_new_orders = $stmt->fetch()['count'];

// 2. Tổng số khách hàng (users có role = 'user')
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$total_customers = $stmt->fetch()['count'];

// 3. Tổng doanh thu tháng này
$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT SUM(final_amount) as total FROM orders WHERE status = 'shipped' AND DATE_FORMAT(order_date, '%Y-%m') = ?");
$stmt->execute([$current_month]);
$total_revenue_month = $stmt->fetch()['total'] ?? 0;

// 4. Số sản phẩm sắp hết (stock_quantity <= 10)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE stock_quantity <= 10 AND status = 'active'");
$low_stock_products = $stmt->fetch()['count'];

// Lấy 5 đơn hàng gần đây nhất từ database
$stmt = $pdo->query("SELECT id, order_code, customer_name, order_date, total_amount, final_amount, status 
                     FROM orders 
                     ORDER BY order_date DESC, id DESC 
                     LIMIT 5");
$recent_orders = $stmt->fetchAll();

// Lấy 5 sản phẩm bán chạy nhất (dựa vào order_details)
$stmt = $pdo->query("SELECT p.id, p.name, SUM(od.quantity) as total_sold
                     FROM products p
                     JOIN order_details od ON p.id = od.product_id
                     JOIN orders o ON od.order_id = o.id
                     WHERE o.status != 'cancelled'
                     GROUP BY p.id, p.name
                     ORDER BY total_sold DESC
                     LIMIT 5");
$top_products = $stmt->fetchAll();

// Nếu không có dữ liệu bán chạy, dùng dữ liệu mẫu
if (empty($top_products)) {
    $top_products = [
        ['name' => 'Pizza Hải Sản', 'total_sold' => 156],
        ['name' => 'Burger Bò Phô Mai', 'total_sold' => 142],
        ['name' => 'Mỳ Ý Sốt Bò Bằm', 'total_sold' => 128],
        ['name' => 'Khoai Tây Chiên', 'total_sold' => 115],
    ];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Feane Restaurant</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
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
        
        .sidebar .nav-link:hover, 
        .sidebar .nav-link.active {
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
        
        .stat-card {
            text-align: center;
            padding: 20px;
            cursor: default;
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
        
        .dashboard-stats .col-md-3 {
            margin-bottom: 20px;
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
        
        /* Modal Profile Styles */
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
        
        .modal-content {
            border-radius: 15px;
        }
        
        .modal-header {
            background-color: var(--secondary-color);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
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
        
        .avatar-btn:focus {
            outline: none;
        }
        
        .avatar-btn i {
            font-size: 2rem;
            color: var(--primary-color);
        }
        
        .stat-display-card {
            cursor: default;
        }
        
        .stat-display-card:hover {
            transform: translateY(-5px);
        }
        
        .order-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
        }
        
        .order-link:hover {
            text-decoration: underline;
            color: #e6a500;
        }
        
        /* Modal Order Detail Styles */
        .order-detail-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }
        
        .order-detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .order-detail-value {
            font-size: 1rem;
            font-weight: 500;
            color: #212529;
            word-break: break-word;
        }
        
        .order-summary-row {
            background-color: #fff3cd;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
        }
        
        .order-summary-item {
            text-align: center;
            border-right: 1px solid #dee2e6;
        }
        
        .order-summary-item:last-child {
            border-right: none;
        }
        
        .order-summary-label {
            font-size: 0.8rem;
            color: #856404;
        }
        
        .order-summary-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #856404;
        }
        
        .product-table th {
            background-color: #f2f2f2;
            border-top: none;
        }
    </style>
</head>
<body>
    <!-- Trang quản trị -->
    <div id="admin-page">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="p-3">
                <h4 class="text-center mb-4"><i class="fas fa-utensils"></i> Feane Admin</h4>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" href="admin.php">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users"></i> <span>Quản lý người dùng</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="categories.php">
                        <i class="fas fa-tags"></i> <span>Loại sản phẩm</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="products.php">
                        <i class="fas fa-hamburger"></i> <span>Sản phẩm</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="imports.php">
                        <i class="fas fa-arrow-down"></i> <span>Nhập sản phẩm</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="pricing.php">
                        <i class="fas fa-dollar-sign"></i> <span>Giá bán</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="orders.php">
                        <i class="fas fa-shopping-cart"></i> <span>Đơn hàng</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="inventory.php">
                        <i class="fas fa-boxes"></i> <span>Tồn kho</span>
                    </a>
                </li>
                <li class="nav-item mt-4">
                    <a class="nav-link" href="logout.php">
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
                    <div class="d-flex align-items-center ms-auto">
                        <span class="navbar-text me-3">
                            Xin chào, <strong><?php echo htmlspecialchars($admin_info['full_name'] ?: $admin_info['username']); ?></strong>
                        </span>
                        <button class="avatar-btn" id="avatarBtn" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </button>
                    </div>
                </div>
            </nav>

            <!-- Dashboard Content -->
            <div id="dashboard-page" class="page-content">
                <h2 class="mb-4">Dashboard</h2>
                
                <!-- Statistics Cards -->
                <div class="row dashboard-stats">
                    <div class="col-md-3">
                        <div class="card card-custom bg-primary-custom stat-display-card">
                            <div class="card-body stat-card">
                                <i class="fas fa-shopping-cart"></i>
                                <h3><?php echo number_format($total_new_orders); ?></h3>
                                <p>Đơn hàng mới</p>
                            </div>
                        </div>            
                    </div>
                    <div class="col-md-3">
                        <div class="card card-custom bg-primary-custom stat-display-card"> 
                            <div class="card-body stat-card">
                                <i class="fas fa-users"></i>
                                <h3><?php echo number_format($total_customers); ?></h3>
                                <p>Người dùng</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card card-custom bg-primary-custom stat-display-card">
                            <div class="card-body stat-card">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h3><?php echo number_format($low_stock_products); ?></h3>
                                <p>Sản phẩm sắp hết</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card card-custom stat-display-card" style="background-color: #28a745; color: white;">
                            <div class="card-body stat-card">
                                <i class="fas fa-dollar-sign"></i>
                                <h3><?php echo formatVND($total_revenue_month); ?></h3>
                                <p>Doanh thu tháng</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Orders - Lấy từ database -->
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="card card-custom">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Đơn hàng gần đây</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            
                                                <th>Mã đơn</th>
                                                <th>Khách hàng</th>
                                                <th>Ngày đặt</th>
                                                <th>Tổng tiền</th>
                                                <th>Trạng thái</th>
                                            </thead>
                                        <tbody>
                                            <?php if (empty($recent_orders)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">Chưa có đơn hàng nào</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recent_orders as $order): ?>
                                                <tr>
                                                    <td>
                                                        <a href="#" class="order-link" data-order-id="<?php echo $order['id']; ?>">
                                                            <?php echo htmlspecialchars($order['order_code'] ?? '#' . $order['id']); ?>
                                                        </a>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                                    <td><?php echo date('d/m/Y', strtotime($order['order_date'])); ?></td>
                                                    <td><?php echo formatVND($order['final_amount'] ?? $order['total_amount']); ?></td>
                                                    <td>
                                                        <?php
                                                        $status_class = '';
                                                        $status_text = '';
                                                        switch ($order['status']) {
                                                            case 'new':
                                                                $status_class = 'badge-status-new';
                                                                $status_text = 'Mới';
                                                                break;
                                                            case 'processing':
                                                                $status_class = 'badge-status-processing';
                                                                $status_text = 'Đang xử lý';
                                                                break;
                                                            case 'shipped':
                                                                $status_class = 'badge-status-shipped';
                                                                $status_text = 'Đã giao';
                                                                break;
                                                            case 'cancelled':
                                                                $status_class = 'badge-status-cancelled';
                                                                $status_text = 'Đã hủy';
                                                                break;
                                                            default:
                                                                $status_class = 'badge-status-new';
                                                                $status_text = 'Mới';
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card card-custom">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Sản phẩm bán chạy</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($top_products as $product): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?php echo htmlspecialchars($product['name']); ?></span>
                                        <span class="badge bg-primary rounded-pill"><?php echo number_format($product['total_sold']); ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Thông tin cá nhân -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="fas fa-user-circle me-2"></i> Thông tin cá nhân
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="profile-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-user me-2"></i> Họ tên:
                        </span>
                        <span class="profile-info-value">
                            <?php echo htmlspecialchars($admin_info['full_name'] ?: 'Chưa cập nhật'); ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-at me-2"></i> Tên đăng nhập:
                        </span>
                        <span class="profile-info-value">
                            <?php echo htmlspecialchars($admin_info['username']); ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-envelope me-2"></i> Email:
                        </span>
                        <span class="profile-info-value">
                            <?php echo htmlspecialchars($admin_info['email']); ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-phone me-2"></i> Điện thoại:
                        </span>
                        <span class="profile-info-value">
                            <?php echo htmlspecialchars($admin_info['phone'] ?: 'Chưa cập nhật'); ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-map-marker-alt me-2"></i> Địa chỉ:
                        </span>
                        <span class="profile-info-value">
                            <?php echo htmlspecialchars($admin_info['address'] ?: 'Chưa cập nhật'); ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-calendar-alt me-2"></i> Ngày sinh:
                        </span>
                        <span class="profile-info-value">
                            <?php echo $admin_info['birthday'] && $admin_info['birthday'] !== '0000-00-00' ? date('d/m/Y', strtotime($admin_info['birthday'])) : 'Chưa cập nhật'; ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-calendar-plus me-2"></i> Ngày đăng ký:
                        </span>
                        <span class="profile-info-value">
                            <?php echo date('d/m/Y', strtotime($admin_info['register_date'])); ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-shield-alt me-2"></i> Vai trò:
                        </span>
                        <span class="profile-info-value">
                            <?php 
                            $role_text = $admin_info['role'] === 'admin' ? 'Quản trị viên' : 'Người dùng';
                            echo $role_text;
                            ?>
                        </span>
                    </div>
                    
                    <div class="profile-info-item">
                        <span class="profile-info-label">
                            <i class="fas fa-clock me-2"></i> Lần đăng nhập cuối:
                        </span>
                        <span class="profile-info-value">
                            <?php echo $admin_info['last_login'] ? date('d/m/Y H:i:s', strtotime($admin_info['last_login'])) : 'Chưa có dữ liệu'; ?>
                        </span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Chi tiết đơn hàng -->
    <div class="modal fade" id="orderDetailModal" tabindex="-1" aria-labelledby="orderDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderDetailModalLabel">
                        <i class="fas fa-shopping-cart me-2"></i> Chi tiết đơn hàng
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="order-detail-content">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Đang tải...</span>
                        </div>
                        <p class="mt-2">Đang tải thông tin đơn hàng...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Xử lý toggle sidebar trên mobile
            const toggleSidebarBtn = document.getElementById('toggle-sidebar');
            if (toggleSidebarBtn) {
                toggleSidebarBtn.addEventListener('click', function() {
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
            }
            
            // Tự động điều chỉnh sidebar trên mobile khi load
            function adjustSidebar() {
                if (window.innerWidth <= 768) {
                    const sidebar = document.querySelector('.sidebar');
                    const mainContent = document.querySelector('.main-content');
                    if (sidebar && mainContent) {
                        sidebar.style.width = '70px';
                        mainContent.style.marginLeft = '70px';
                        const navTexts = document.querySelectorAll('.sidebar .nav-link span');
                        navTexts.forEach(text => text.style.display = 'none');
                    }
                } else {
                    const sidebar = document.querySelector('.sidebar');
                    const mainContent = document.querySelector('.main-content');
                    if (sidebar && mainContent) {
                        sidebar.style.width = '250px';
                        mainContent.style.marginLeft = '250px';
                        const navTexts = document.querySelectorAll('.sidebar .nav-link span');
                        navTexts.forEach(text => text.style.display = 'inline');
                    }
                }
            }
            
            adjustSidebar();
            window.addEventListener('resize', adjustSidebar);
        });

        // Xử lý xem chi tiết đơn hàng bằng AJAX
        $(document).ready(function() {
            // Hàm định dạng tiền VNĐ
            function formatVND(amount) {
                return new Intl.NumberFormat('vi-VN').format(amount) + ' ₫';
            }

            // Hàm lấy trạng thái đơn hàng
            function getStatusText(status) {
                switch(status) {
                    case 'new': return 'Mới';
                    case 'processing': return 'Đang xử lý';
                    case 'shipped': return 'Đã giao';
                    case 'cancelled': return 'Đã hủy';
                    default: return 'Không xác định';
                }
            }

            function getStatusClass(status) {
                switch(status) {
                    case 'new': return 'badge-status-new';
                    case 'processing': return 'badge-status-processing';
                    case 'shipped': return 'badge-status-shipped';
                    case 'cancelled': return 'badge-status-cancelled';
                    default: return 'badge-status-new';
                }
            }

            // Xử lý click vào mã đơn hàng
            $(document).on('click', '.order-link', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                
                if (!orderId) {
                    alert('Không tìm thấy mã đơn hàng');
                    return;
                }
                
                // Hiển thị modal và loading
                $('#orderDetailModal').modal('show');
                $('#order-detail-content').html(`
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Đang tải...</span>
                        </div>
                        <p class="mt-2">Đang tải thông tin đơn hàng...</p>
                    </div>
                `);
                
                // Gọi AJAX để lấy chi tiết đơn hàng
                $.ajax({
                    url: 'orders.php',
                    type: 'GET',
                    data: {
                        action: 'get_details',
                        id: orderId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            const order = response.order;
                            const items = response.items;
                            
                            let html = `
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Mã đơn hàng</div>
                                            <div class="order-detail-value">${escapeHtml(order.order_code || '#' + order.id)}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Ngày đặt</div>
                                            <div class="order-detail-value">${new Date(order.order_date).toLocaleDateString('vi-VN')}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Khách hàng</div>
                                            <div class="order-detail-value">${escapeHtml(order.customer_name)}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Số điện thoại</div>
                                            <div class="order-detail-value">${order.customer_phone || '---'}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Địa chỉ giao hàng</div>
                                            <div class="order-detail-value">${escapeHtml(order.customer_address || '---')}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Phương thức thanh toán</div>
                                            <div class="order-detail-value">${order.payment_method === 'cash' ? 'Tiền mặt' : (order.payment_method === 'transfer' ? 'Chuyển khoản' : (order.payment_method || '---'))}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Trạng thái</div>
                                            <div class="order-detail-value"><span class="badge ${getStatusClass(order.status)}">${getStatusText(order.status)}</span></div>
                                        </div>
                                    </div>
                                </div>
                                ${order.notes ? `
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <div class="order-detail-card">
                                            <div class="order-detail-label">Ghi chú</div>
                                            <div class="order-detail-value">${escapeHtml(order.notes)}</div>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <h5 class="mt-4 mb-3"><i class="fas fa-list-ul me-2"></i>Chi tiết sản phẩm</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered product-table">
                                        <thead>
                                            <tr>
                                                <th>Sản phẩm</th>
                                                <th class="text-center">Số lượng</th>
                                                <th class="text-end">Đơn giá</th>
                                                <th class="text-end">Thành tiền</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;
                            
                            if (items.length === 0) {
                                html += '<tr><td colspan="4" class="text-center">Không có sản phẩm nào</td></tr>';
                            } else {
                                items.forEach(item => {
                                    html += `
                                        <tr>
                                            <td>${escapeHtml(item.product_name)}</td>
                                            <td class="text-center">${item.quantity}</td>
                                            <td class="text-end">${formatVND(item.unit_price)}</td>
                                            <td class="text-end">${formatVND(item.subtotal)}</td>
                                        </tr>
                                    `;
                                });
                            }
                            
                            html += `
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="row mt-4 mb-3">
                                    <div class="col-12">
                                        <div class="order-summary-row">
                                            <div class="row text-center">
                                                <div class="col-4 order-summary-item">
                                                    <div class="order-summary-label">Tạm tính</div>
                                                    <div class="order-summary-value">${formatVND(order.total_amount)}</div>
                                                </div>
                                                <div class="col-4 order-summary-item">
                                                    <div class="order-summary-label">Phí vận chuyển</div>
                                                    <div class="order-summary-value">${formatVND(order.shipping_fee || 0)}</div>
                                                </div>
                                                <div class="col-4 order-summary-item">
                                                    <div class="order-summary-label">Giảm giá</div>
                                                    <div class="order-summary-value">${formatVND(order.discount || 0)}</div>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-12 text-center">
                                                    <div class="order-summary-label">Thành tiền</div>
                                                    <div class="order-summary-value" style="font-size:1.5rem;">${formatVND(order.final_amount)}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `;
                            
                            $('#order-detail-content').html(html);
                        } else {
                            $('#order-detail-content').html(`
                                <div class="alert alert-danger text-center">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    ${response.error || 'Không thể tải thông tin đơn hàng'}
                                </div>
                            `);
                        }
                    },
                    error: function() {
                        $('#order-detail-content').html(`
                            <div class="alert alert-danger text-center">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Có lỗi xảy ra khi kết nối đến máy chủ
                            </div>
                        `);
                    }
                });
            });
            
            // Hàm escape HTML
            function escapeHtml(str) {
                if (!str) return '';
                return str.replace(/[&<>]/g, function(m) {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    return m;
                });
            }
        });
    </script>
</body>
</html>