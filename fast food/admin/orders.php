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

// Lấy thông tin admin
$admin_id = $_SESSION['admin_id'];
$stmt = $pdo->prepare("SELECT id, full_name, username, email, phone, address, birthday, register_date, role, status, last_login FROM users WHERE id = ?");
$stmt->execute([$admin_id]);
$admin_info = $stmt->fetch();

if (!$admin_info) {
    session_destroy();
    header('Location: adminlogin.php');
    exit;
}

// Xử lý AJAX
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];

    try {
        switch ($action) {
            case 'list':
                $search = $_GET['search'] ?? '';
                $start_date = $_GET['start_date'] ?? '';
                $end_date = $_GET['end_date'] ?? '';
                $status = $_GET['status'] ?? '';
                $sort_ward = isset($_GET['sort_ward']) && $_GET['sort_ward'] === 'true';
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;

                $sql = "SELECT o.id, o.order_code, o.customer_name, o.customer_address, o.order_date, 
                               o.total_amount, o.status, o.created_at
                        FROM orders o
                        WHERE 1=1";
                $params = [];

                if ($search) {
                    $sql .= " AND (o.order_code LIKE :search OR o.customer_name LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                if ($start_date) {
                    $sql .= " AND o.order_date >= :start_date";
                    $params[':start_date'] = $start_date;
                }
                if ($end_date) {
                    $sql .= " AND o.order_date <= :end_date";
                    $params[':end_date'] = $end_date;
                }
                if ($status && in_array($status, ['new', 'processing', 'shipped', 'cancelled'])) {
                    $sql .= " AND o.status = :status";
                    $params[':status'] = $status;
                }

                if ($sort_ward) {
                    $sql .= " ORDER BY SUBSTRING_INDEX(customer_address, ',', -1) ASC, o.order_date DESC";
                } else {
                    $sql .= " ORDER BY o.order_date DESC, o.id DESC";
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
                $orders = $stmt->fetchAll();

                $countSql = "SELECT COUNT(*) as total FROM orders o WHERE 1=1";
                $countParams = [];
                if ($search) {
                    $countSql .= " AND (o.order_code LIKE :search OR o.customer_name LIKE :search)";
                    $countParams[':search'] = "%$search%";
                }
                if ($start_date) {
                    $countSql .= " AND o.order_date >= :start_date";
                    $countParams[':start_date'] = $start_date;
                }
                if ($end_date) {
                    $countSql .= " AND o.order_date <= :end_date";
                    $countParams[':end_date'] = $end_date;
                }
                if ($status && in_array($status, ['new', 'processing', 'shipped', 'cancelled'])) {
                    $countSql .= " AND o.status = :status";
                    $countParams[':status'] = $status;
                }
                $countStmt = $pdo->prepare($countSql);
                foreach ($countParams as $key => $val) $countStmt->bindValue($key, $val);
                $countStmt->execute();
                $total = $countStmt->fetch()['total'];
                $totalPages = ceil($total / $limit);

                $statsSql = "SELECT 
                                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new,
                                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                                SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                             FROM orders";
                $stats = $pdo->query($statsSql)->fetch();

                echo json_encode([
                    'success' => true,
                    'data' => $orders,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'total_records' => $total
                    ],
                    'stats' => $stats
                ]);
                break;

            case 'update_status':
                $order_id = (int)($_POST['order_id'] ?? 0);
                $new_status = $_POST['status'] ?? '';
                // Admin chỉ được phép cập nhật thành new, processing, shipped (không được tự hủy)
                if (!in_array($new_status, ['new', 'processing', 'shipped'])) {
                    throw new Exception('Trạng thái không hợp lệ');
                }
                $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $order_id]);
                echo json_encode(['success' => true]);
                break;

            case 'get_details':
                $order_id = (int)($_GET['id'] ?? 0);
                if (!$order_id) throw new Exception('Missing order ID');

                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();
                if (!$order) throw new Exception('Order not found');

                $stmt = $pdo->prepare("SELECT od.*, p.name as product_name 
                                       FROM order_details od
                                       JOIN products p ON od.product_id = p.id
                                       WHERE od.order_id = ?");
                $stmt->execute([$order_id]);
                $items = $stmt->fetchAll();

                echo json_encode([
                    'success' => true,
                    'order' => $order,
                    'items' => $items
                ]);
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
    <title>Quản lý Đơn hàng - Feane Restaurant</title>
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
            min-height: 100vh;
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
            overflow: hidden;
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
        .bg-warning-custom {
            background-color: #ffc107 !important;
            color: #212529 !important;
        }
        .bg-warning-custom .stat-info h3,
        .bg-warning-custom .stat-info p,
        .bg-warning-custom .stat-icon {
            color: #212529 !important;
        }
        .bg-success-custom {
            background-color: #28a745 !important;
        }
        .bg-danger-custom {
            background-color: #dc3545 !important;
        }
        .order-link, .customer-link {
            color: var(--secondary-color);
            text-decoration: none;
            transition: color 0.3s;
        }
        .order-link:hover, .customer-link:hover {
            color: var(--primary-color);
        }
        .order-link {
            cursor: pointer;
        }

        /* ===== CẢI TIẾN GIAO DIỆN TRẠNG THÁI ===== */
        .status-update-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status-select {
            width: 140px;
            font-size: 0.85rem;
            padding: 5px 8px;
            border-radius: 20px;
            border: 1px solid #ced4da;
            background-color: #fff;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .status-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(255,190,51,0.25);
        }
        .status-select:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .btn-update-status {
            background-color: var(--primary-color);
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-color);
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-update-status:hover {
            background-color: #e6a500;
            transform: scale(1.05);
        }
        .btn-update-status:active {
            transform: scale(0.95);
        }
        .btn-update-status i {
            font-size: 14px;
        }
        .update-loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Thông báo toast */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 250px;
            background-color: #28a745;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        .toast-notification.error {
            background-color: #dc3545;
        }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        /* Kết thúc cải tiến */

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
                <li class="nav-item"><a class="nav-link active" href="orders.php"><i class="fas fa-shopping-cart"></i> <span>Đơn hàng</span></a></li>
                <li class="nav-item"><a class="nav-link" href="inventory.php"><i class="fas fa-boxes"></i> <span>Tồn kho</span></a></li>
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

            <div id="order-management-page" class="page-content">
                <h2 class="mb-4">Quản lý Đơn hàng</h2>

                <div class="filter-section">
                    <h3><i class="fas fa-filter me-2"></i>Bộ lọc đơn hàng</h3>
                    <form id="order-filter-form">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="start-date" class="form-label">Ngày bắt đầu</label>
                                <input type="date" class="form-control" id="start-date" name="start-date">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="end-date" class="form-label">Ngày kết thúc</label>
                                <input type="date" class="form-control" id="end-date" name="end-date">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="status-filter" class="form-label">Tình trạng</label>
                                <select class="form-select" id="status-filter" name="status">
                                    <option value="">-- Tất cả --</option>
                                    <option value="new">Mới đặt</option>
                                    <option value="processing">Đang xử lý</option>
                                    <option value="shipped">Đã giao</option>
                                    <option value="cancelled">Hủy</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sort-ward">
                                    <label class="form-check-label" for="sort-ward">Sắp xếp theo phường (địa chỉ)</label>
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <button type="submit" class="btn btn-dark"><i class="fas fa-search me-2"></i>Tìm kiếm</button>
                                <button type="reset" class="btn btn-outline-dark ms-2"><i class="fas fa-undo me-2"></i>Đặt lại</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="stats-row">
                    <div class="stat-card bg-primary-custom" data-status="new">
                        <div class="card-body">
                            <div class="stat-info"><h3 id="new-orders">0</h3><p>Đơn hàng mới</p></div>
                            <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                        </div>
                    </div>
                    <div class="stat-card bg-warning-custom" data-status="processing">
                        <div class="card-body">
                            <div class="stat-info"><h3 id="processing-orders">0</h3><p>Đang xử lý</p></div>
                            <div class="stat-icon"><i class="fas fa-cog"></i></div>
                        </div>
                    </div>
                    <div class="stat-card bg-success-custom" data-status="shipped">
                        <div class="card-body">
                            <div class="stat-info"><h3 id="shipped-orders">0</h3><p>Đã giao</p></div>
                            <div class="stat-icon"><i class="fas fa-truck"></i></div>
                        </div>
                    </div>
                    <div class="stat-card bg-danger-custom" data-status="cancelled">
                        <div class="card-body">
                            <div class="stat-info"><h3 id="cancelled-orders">0</h3><p>Đã hủy</p></div>
                            <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                        </div>
                    </div>
                </div>

                <div class="card card-custom">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Danh sách đơn hàng</h5>
                        <div class="search-box">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Tìm kiếm đơn hàng..." id="search-order">
                                <button class="btn btn-outline-secondary" type="button" id="search-order-btn"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <th>Mã đơn</th><th>Khách hàng</th><th>Ngày đặt</th><th>Tổng tiền</th><th>Trạng thái</th><th>Thao tác</th>
                                </thead>
                                <tbody id="orders-table-body">
                                    <td colspan="6" class="text-center">Đang tải...</td>
                                </tbody>
                            </table>
                        </div>
                        <nav aria-label="Page navigation"><ul class="pagination justify-content-center mt-4" id="pagination-container"></ul></nav>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-4">
                        <div class="card card-custom">
                            <div class="card-header"><h5 class="card-title mb-0">Sản phẩm bán chạy</h5></div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">Pizza Hải Sản<span class="badge bg-primary rounded-pill">156</span></li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">Burger Bò Phô Mai<span class="badge bg-primary rounded-pill">142</span></li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">Mỳ Ý Sốt Bò Bằm<span class="badge bg-primary rounded-pill">128</span></li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">Khoai Tây Chiên<span class="badge bg-primary rounded-pill">115</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card card-custom">
                            <div class="card-header"><h5 class="card-title mb-0">Đơn hàng gần đây</h5></div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead><th>Mã đơn</th><th>Khách hàng</th><th>Ngày đặt</th><th>Tổng tiền</th><th>Trạng thái</th></thead>
                                        <tbody id="recent-orders-body"></tbody>
                                    </table>
                                </div>
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

    <!-- Modal chi tiết đơn hàng -->
    <div class="modal fade" id="orderDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Chi tiết đơn hàng</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="order-detail-content"></div>
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
        let currentFilters = { search: '', start_date: '', end_date: '', status: '', sort_ward: false };

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

        function loadOrders() {
            const params = { action: 'list', page: currentPage, search: currentFilters.search, start_date: currentFilters.start_date, end_date: currentFilters.end_date, status: currentFilters.status, sort_ward: currentFilters.sort_ward };
            $.getJSON('orders.php', params, function(response) {
                if (response.success) {
                    renderTable(response.data);
                    renderPagination(response.pagination);
                    updateStats(response.stats);
                    updateRecentOrders(response.data.slice(0, 3));
                } else alert('Lỗi tải dữ liệu: ' + response.error);
            });
        }

        function renderTable(orders) {
            const tbody = $('#orders-table-body');
            if (!orders.length) { tbody.html('<tr><td colspan="6" class="text-center">Không có đơn hàng nào</td></tr>'); return; }
            let html = '';
            orders.forEach(order => {
                const totalAmount = new Intl.NumberFormat('vi-VN').format(order.total_amount);
                const isCancelled = order.status === 'cancelled';
                html += `<tr>
                            <td><a href="#" class="order-link" data-id="${order.id}">${escapeHtml(order.order_code)}</a></td>
                            <td>${escapeHtml(order.customer_name)}</td>
                            <td>${new Date(order.order_date).toLocaleDateString('vi-VN')}</td>
                            <td>${totalAmount} ₫</td>
                            <td>
                                <div class="status-update-form">
                                    <select class="status-select" data-order-id="${order.id}" ${isCancelled ? 'disabled' : ''}>
                                        <option value="new" ${order.status === 'new' ? 'selected' : ''}>Mới</option>
                                        <option value="processing" ${order.status === 'processing' ? 'selected' : ''}>Đang xử lý</option>
                                        <option value="shipped" ${order.status === 'shipped' ? 'selected' : ''}>Đã giao</option>
                                        ${order.status === 'cancelled' ? '<option value="cancelled" selected>Hủy</option>' : ''}
                                    </select>
                                    ${!isCancelled ? `<button class="btn-update-status" data-order-id="${order.id}"><i class="fas fa-save"></i></button>` : ''}
                                </div>
                            </td>
                            <td><button class="btn btn-sm btn-custom view-detail" data-id="${order.id}"><i class="fas fa-eye me-1"></i>Xem</button></td>
                         </tr>`;
            });
            tbody.html(html);

            // Gắn sự kiện cho nút cập nhật
            $('.btn-update-status').off('click').on('click', function() {
                const btn = $(this);
                const orderId = btn.data('order-id');
                const select = $(`.status-select[data-order-id="${orderId}"]`);
                const newStatus = select.val();
                updateOrderStatus(orderId, newStatus, btn);
            });
        }

        function renderPagination(pagination) {
            const container = $('#pagination-container');
            if (pagination.total_pages <= 1) { container.empty(); return; }
            let html = '';
            html += `<li class="page-item ${pagination.current_page === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${pagination.current_page - 1}">Trước</a></li>`;
            for (let i = 1; i <= pagination.total_pages; i++) html += `<li class="page-item ${i === pagination.current_page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
            html += `<li class="page-item ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${pagination.current_page + 1}">Tiếp</a></li>`;
            container.html(html);
            container.find('.page-link').click(function(e) { e.preventDefault(); const page = $(this).data('page'); if (page && page !== currentPage) { currentPage = page; loadOrders(); } });
        }

        function updateStats(stats) { $('#new-orders').text(stats.new || 0); $('#processing-orders').text(stats.processing || 0); $('#shipped-orders').text(stats.shipped || 0); $('#cancelled-orders').text(stats.cancelled || 0); }

        function updateRecentOrders(orders) {
            const tbody = $('#recent-orders-body');
            if (!orders.length) { tbody.html('<tr><td colspan="5" class="text-center">Chưa có đơn hàng</td></tr>'); return; }
            let html = '';
            orders.forEach(order => { const totalAmount = new Intl.NumberFormat('vi-VN').format(order.total_amount); html += `<tr><td><a href="#" class="order-link" data-id="${order.id}">${order.order_code}</a></td><td>${escapeHtml(order.customer_name)}</td><td>${new Date(order.order_date).toLocaleDateString('vi-VN')}</td><td>${totalAmount} ₫</td><td><span class="badge badge-status-${order.status}">${getStatusText(order.status)}</span></td></tr>`; });
            tbody.html(html);
        }

        function updateOrderStatus(orderId, newStatus, btn) {
            // Hiệu ứng loading
            btn.addClass('update-loading').html('<i class="fas fa-spinner fa-spin"></i>');
            $.post('orders.php', { action: 'update_status', order_id: orderId, status: newStatus }, function(res) {
                if (res.success) {
                    showNotification('Đã cập nhật trạng thái đơn hàng', 'success');
                    loadOrders(); // Tải lại danh sách sau khi cập nhật
                } else {
                    showNotification('Lỗi: ' + res.error, 'error');
                    btn.removeClass('update-loading').html('<i class="fas fa-save"></i>');
                }
            }, 'json').fail(function() {
                showNotification('Không thể kết nối máy chủ', 'error');
                btn.removeClass('update-loading').html('<i class="fas fa-save"></i>');
            });
        }

        function showNotification(message, type = 'success') {
            const toast = $(`<div class="toast-notification ${type}"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}</div>`);
            $('body').append(toast);
            setTimeout(() => toast.fadeOut(300, function() { $(this).remove(); }), 3000);
        }

        function viewOrderDetail(orderId) {
            $.getJSON('orders.php', { action: 'get_details', id: orderId }, function(res) {
                if (res.success) {
                    const order = res.order, items = res.items;
                    const formatMoney = (amount) => new Intl.NumberFormat('vi-VN').format(amount);
                    let html = `<div class="row mb-3"><div class="col-md-6"><div class="order-detail-card"><div class="order-detail-label">Mã đơn hàng</div><div class="order-detail-value">${order.order_code}</div></div></div><div class="col-md-6"><div class="order-detail-card"><div class="order-detail-label">Ngày đặt</div><div class="order-detail-value">${new Date(order.order_date).toLocaleDateString('vi-VN')}</div></div></div></div>`;
                    html += `<div class="row mb-3"><div class="col-md-6"><div class="order-detail-card"><div class="order-detail-label">Khách hàng</div><div class="order-detail-value">${escapeHtml(order.customer_name)}</div></div></div><div class="col-md-6"><div class="order-detail-card"><div class="order-detail-label">Số điện thoại</div><div class="order-detail-value">${order.customer_phone || '---'}</div></div></div></div>`;
                    html += `<div class="row mb-3"><div class="col-12"><div class="order-detail-card"><div class="order-detail-label">Địa chỉ giao hàng</div><div class="order-detail-value">${escapeHtml(order.customer_address || '---')}</div></div></div></div>`;
                    html += `<h5 class="mt-4 mb-3"><i class="fas fa-list-ul me-2"></i>Chi tiết sản phẩm</h5><div class="table-responsive"><table class="table table-bordered product-table"><thead><tr><th>Sản phẩm</th><th class="text-center">Số lượng</th><th class="text-end">Đơn giá</th><th class="text-end">Thành tiền</th></tr></thead><tbody>`;
                    items.forEach(item => { html += `<tr><td>${escapeHtml(item.product_name)}</td><td class="text-center">${item.quantity}</td><td class="text-end">${formatMoney(item.unit_price)} ₫</td><td class="text-end">${formatMoney(item.subtotal)} ₫</td></tr>`; });
                    html += `</tbody></table></div><div class="row mt-4 mb-3"><div class="col-12"><div class="order-summary-row"><div class="row text-center"><div class="col-4 order-summary-item"><div class="order-summary-label">Tạm tính</div><div class="order-summary-value">${formatMoney(order.total_amount)} ₫</div></div><div class="col-4 order-summary-item"><div class="order-summary-label">Phí vận chuyển</div><div class="order-summary-value">${formatMoney(order.shipping_fee)} ₫</div></div><div class="col-4 order-summary-item"><div class="order-summary-label">Giảm giá</div><div class="order-summary-value">${formatMoney(order.discount)} ₫</div></div></div><div class="row mt-2"><div class="col-12 text-center"><div class="order-summary-label">Thành tiền</div><div class="order-summary-value" style="font-size:1.5rem;">${formatMoney(order.final_amount)} ₫</div></div></div></div></div></div>`;
                    $('#order-detail-content').html(html);
                    $('#orderDetailModal').modal('show');
                } else alert('Lỗi tải chi tiết: ' + res.error);
            });
        }

        function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }
        function getStatusText(status) { switch(status) { case 'new': return 'Mới'; case 'processing': return 'Đang xử lý'; case 'shipped': return 'Đã giao'; case 'cancelled': return 'Hủy'; default: return 'Không xác định'; } }

        $(document).ready(function() {
            $('#order-filter-form').submit(function(e) { e.preventDefault(); currentFilters.start_date = $('#start-date').val(); currentFilters.end_date = $('#end-date').val(); currentFilters.status = $('#status-filter').val(); currentFilters.sort_ward = $('#sort-ward').is(':checked'); currentPage = 1; loadOrders(); });
            $('#order-filter-form button[type="reset"]').click(function() { $('#start-date').val(''); $('#end-date').val(''); $('#status-filter').val(''); $('#sort-ward').prop('checked', false); $('#search-order').val(''); currentFilters = { search: '', start_date: '', end_date: '', status: '', sort_ward: false }; currentPage = 1; loadOrders(); });
            $('#search-order-btn').click(function() { currentFilters.search = $('#search-order').val(); currentPage = 1; loadOrders(); });
            $('#search-order').keypress(function(e) { if (e.which === 13) { currentFilters.search = $(this).val(); currentPage = 1; loadOrders(); } });
            $('.stat-card').click(function() { const status = $(this).data('status'); $('#status-filter').val(status); $('#order-filter-form').submit(); });
            $(document).on('click', '.order-link', function(e) { e.preventDefault(); const orderId = $(this).data('id'); viewOrderDetail(orderId); });
            $(document).on('click', '.view-detail', function() { const orderId = $(this).data('id'); viewOrderDetail(orderId); });
            loadOrders();
        });
    </script>
</body>
</html>