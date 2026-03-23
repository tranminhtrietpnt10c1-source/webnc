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

// Xử lý AJAX requests
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        switch ($action) {
            case 'list':
                $search = $_GET['search'] ?? '';
                $status = $_GET['status'] ?? '';
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;
                
                $sql = "SELECT id, full_name, username, email, phone, address, birthday, register_date, role, status, last_login 
                        FROM users 
                        WHERE role = 'user'";
                $params = [];
                
                if ($search) {
                    $sql .= " AND (full_name LIKE :search OR username LIKE :search OR email LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                if ($status && in_array($status, ['active', 'locked'])) {
                    $sql .= " AND status = :status";
                    $params[':status'] = $status;
                }
                
                $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";
                $params[':limit'] = $limit;
                $params[':offset'] = $offset;
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    if (is_int($val)) $stmt->bindValue($key, $val, PDO::PARAM_INT);
                    else $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $users = $stmt->fetchAll();
                
                // Count query
                $countSql = "SELECT COUNT(*) as total FROM users WHERE role = 'user'";
                $countParams = [];
                if ($search) {
                    $countSql .= " AND (full_name LIKE :search OR username LIKE :search OR email LIKE :search)";
                    $countParams[':search'] = "%$search%";
                }
                if ($status && in_array($status, ['active', 'locked'])) {
                    $countSql .= " AND status = :status";
                    $countParams[':status'] = $status;
                }
                $countStmt = $pdo->prepare($countSql);
                foreach ($countParams as $key => $val) $countStmt->bindValue($key, $val);
                $countStmt->execute();
                $total = $countStmt->fetch()['total'];
                $totalPages = ceil($total / $limit);
                
                // Thống kê
                $statsStmt = $pdo->query("SELECT 
                                            COUNT(*) as total,
                                            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                                            SUM(CASE WHEN status = 'locked' THEN 1 ELSE 0 END) as locked
                                          FROM users WHERE role = 'user'");
                $stats = $statsStmt->fetch();
                
                echo json_encode([
                    'success' => true,
                    'data' => $users,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'total_records' => $total
                    ],
                    'stats' => $stats
                ]);
                break;
                
            case 'add':
                $full_name = trim($_POST['full_name'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $birthday = $_POST['birthday'] ?? null;
                
                if (empty($full_name)) throw new Exception('Vui lòng nhập họ tên');
                if (empty($username)) throw new Exception('Vui lòng nhập tên đăng nhập');
                if (empty($email)) throw new Exception('Vui lòng nhập email');
                
                // Kiểm tra username đã tồn tại
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) throw new Exception('Tên đăng nhập đã tồn tại');
                
                // Kiểm tra email đã tồn tại
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) throw new Exception('Email đã tồn tại');
                
                // Mật khẩu mặc định: user123
                $default_password = password_hash('user123', PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, phone, address, birthday, register_date, role, status, password) 
                                       VALUES (?, ?, ?, ?, ?, ?, NOW(), 'user', 'active', ?)");
                $stmt->execute([$full_name, $username, $email, $phone, $address, $birthday, $default_password]);
                
                echo json_encode(['success' => true, 'user_id' => $pdo->lastInsertId()]);
                break;
                
            case 'edit':
                $user_id = (int)($_POST['user_id'] ?? 0);
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $birthday = $_POST['birthday'] ?? null;
                $new_password = trim($_POST['new_password'] ?? '');
                $confirm_password = trim($_POST['confirm_password'] ?? '');
                
                if (!$user_id) throw new Exception('Thiếu ID người dùng');
                if (empty($full_name)) throw new Exception('Vui lòng nhập họ tên');
                if (empty($email)) throw new Exception('Vui lòng nhập email');
                
                // Kiểm tra email không trùng với user khác
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) throw new Exception('Email đã tồn tại');
                
                $sql = "UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?, birthday = ?";
                $params = [$full_name, $email, $phone, $address, $birthday];
                
                // Kiểm tra nếu có nhập mật khẩu mới
                if (!empty($new_password)) {
                    if ($new_password !== $confirm_password) {
                        throw new Exception('Mật khẩu xác nhận không khớp');
                    }
                    if (strlen($new_password) < 6) {
                        throw new Exception('Mật khẩu phải có ít nhất 6 ký tự');
                    }
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $sql .= ", password = ?";
                    $params[] = $hashed_password;
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $user_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'toggle_status':
                $user_id = (int)($_POST['user_id'] ?? 0);
                if (!$user_id) throw new Exception('Thiếu ID người dùng');
                
                $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                if (!$user) throw new Exception('Không tìm thấy người dùng');
                
                $new_status = $user['status'] == 'active' ? 'locked' : 'active';
                $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $user_id]);
                
                echo json_encode(['success' => true, 'new_status' => $new_status]);
                break;
                
            case 'get':
                $user_id = (int)($_GET['id'] ?? 0);
                if (!$user_id) throw new Exception('Thiếu ID người dùng');
                
                $stmt = $pdo->prepare("SELECT id, full_name, username, email, phone, address, birthday, register_date, status FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                if (!$user) throw new Exception('Không tìm thấy người dùng');
                
                echo json_encode(['success' => true, 'user' => $user]);
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

// Lấy dữ liệu thống kê ban đầu
$statsStmt = $pdo->query("SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                            SUM(CASE WHEN status = 'locked' THEN 1 ELSE 0 END) as locked
                          FROM users WHERE role = 'user'");
$stats = $statsStmt->fetch();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Người dùng - Feane Restaurant</title>
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
            border-radius: 10px;
            padding: 12px 20px;
            margin-bottom: 20px;
        }
        
        .card-custom {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,.1);
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
            }
            .sidebar .nav-link span {
                display: none;
            }
            .main-content {
                margin-left: 70px;
            }
            .toggle-sidebar {
                display: block;
            }
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-locked {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background-color: var(--primary-color);
            color: var(--dark-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            margin-right: 12px;
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
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,.1);
            border-top: 4px solid var(--primary-color);
        }
        
        .stat-card h3 {
            font-size: 2rem;
            margin: 10px 0;
            color: var(--secondary-color);
        }
        
        .search-filters {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .page-link {
            color: var(--secondary-color);
        }
        
        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: var(--dark-color);
        }
        
        .password-requirements {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .password-match-error {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }
        
        .password-match-success {
            color: #28a745;
            font-size: 12px;
            margin-top: 5px;
            display: none;
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
            <li class="nav-item"><a class="nav-link active" href="users.php"><i class="fas fa-users"></i> <span>Quản lý người dùng</span></a></li>
            <li class="nav-item"><a class="nav-link" href="categories.php"><i class="fas fa-tags"></i> <span>Loại sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link" href="products.php"><i class="fas fa-hamburger"></i> <span>Sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link" href="imports.php"><i class="fas fa-arrow-down"></i> <span>Nhập sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link" href="pricing.php"><i class="fas fa-dollar-sign"></i> <span>Giá bán</span></a></li>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Quản lý người dùng</h2>
            <button class="btn btn-custom" id="btn-add-user">
                <i class="fas fa-plus me-2"></i>Thêm người dùng
            </button>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-users fa-2x text-primary"></i>
                <h3 id="totalUsers"><?php echo $stats['total']; ?></h3>
                <p>Tổng số người dùng</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-check fa-2x text-success"></i>
                <h3 id="activeUsers"><?php echo $stats['active']; ?></h3>
                <p>Đang hoạt động</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-lock fa-2x text-danger"></i>
                <h3 id="lockedUsers"><?php echo $stats['locked']; ?></h3>
                <p>Tài khoản bị khóa</p>
            </div>
        </div>

        <!-- Search Filters -->
        <div class="search-filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="searchName">Tìm kiếm</label>
                    <input type="text" class="form-control" id="searchName" placeholder="Tên, tài khoản, email...">
                </div>
                <div class="filter-group">
                    <label for="searchStatus">Trạng thái</label>
                    <select class="form-select" id="searchStatus">
                        <option value="">Tất cả</option>
                        <option value="active">Đang hoạt động</option>
                        <option value="locked">Đã khóa</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="button" class="btn btn-custom w-100" id="searchButton">
                        <i class="fas fa-search me-2"></i>Tìm kiếm
                    </button>
                </div>
                <div class="filter-group">
                    <button type="button" class="btn btn-secondary w-100" id="resetButton">
                        <i class="fas fa-redo me-2"></i>Đặt lại
                    </button>
                </div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card card-custom">
            <div class="card-header">
                <h5 class="card-title mb-0">Danh sách người dùng</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            32
                                <th>ID</th>
                                <th>Thông tin</th>
                                <th>Email</th>
                                <th>Số điện thoại</th>
                                <th>Ngày đăng ký</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </thead>
                            <tbody id="userTableBody">
                                <td colspan="7" class="text-center">Đang tải...</td>
                            </tbody>
                        </table>
                    </div>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mt-4" id="paginationContainer"></ul>
                    </nav>
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

    <!-- Modal Thêm/Sửa người dùng -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">Thêm người dùng</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="userForm">
                        <input type="hidden" id="user_id" name="user_id">
                        <div class="mb-3">
                            <label class="form-label">Họ tên <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tên đăng nhập <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                            <small class="text-muted">Chỉ hiển thị khi thêm mới, không thể sửa</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Số điện thoại</label>
                            <input type="tel" class="form-control" id="phone" name="phone">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Địa chỉ</label>
                            <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ngày sinh</label>
                            <input type="date" class="form-control" id="birthday" name="birthday">
                        </div>
                        
                        <!-- Phần đặt lại mật khẩu (chỉ hiển thị khi sửa) -->
                        <div id="reset-password-section" style="display: none;">
                            <hr class="my-3">
                            <h6 class="mb-3"><i class="fas fa-key me-2"></i>Đặt lại mật khẩu</h6>
                            <div class="mb-3">
                                <label class="form-label">Mật khẩu mới</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Nhập mật khẩu mới">
                                <div class="password-requirements">Mật khẩu phải có ít nhất 6 ký tự</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Xác nhận mật khẩu</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Nhập lại mật khẩu mới">
                                <div id="password-match-error" class="password-match-error">
                                    <i class="fas fa-times-circle me-1"></i>Mật khẩu xác nhận không khớp
                                </div>
                                <div id="password-match-success" class="password-match-success">
                                    <i class="fas fa-check-circle me-1"></i>Mật khẩu khớp
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3" id="password-info">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Mật khẩu mặc định cho tài khoản mới: <strong>user123</strong>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-custom" id="saveUserBtn">Lưu</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPage = 1;
        let currentFilters = { search: '', status: '' };

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

        // Kiểm tra mật khẩu khớp
        function checkPasswordMatch() {
            const newPassword = $('#new_password').val();
            const confirmPassword = $('#confirm_password').val();
            
            if (newPassword === '' && confirmPassword === '') {
                $('#password-match-error').hide();
                $('#password-match-success').hide();
                return true;
            }
            
            if (newPassword === confirmPassword && newPassword !== '') {
                $('#password-match-error').hide();
                $('#password-match-success').show();
                return true;
            } else {
                $('#password-match-error').show();
                $('#password-match-success').hide();
                return false;
            }
        }
        
        // Theo dõi sự kiện nhập mật khẩu
        $('#new_password, #confirm_password').on('keyup', function() {
            checkPasswordMatch();
        });

        // Load users
        function loadUsers() {
            const params = {
                ajax: 1,
                action: 'list',
                page: currentPage,
                search: currentFilters.search,
                status: currentFilters.status
            };
            $.getJSON(window.location.href, params, function(response) {
                if (response.success) {
                    renderTable(response.data);
                    renderPagination(response.pagination);
                    updateStats(response.stats);
                } else {
                    alert('Lỗi tải dữ liệu: ' + response.error);
                }
            });
        }

        function renderTable(users) {
            const tbody = $('#userTableBody');
            if (!users.length) {
                tbody.html('运转<td colspan="7" class="text-center">Không có người dùng nào</td></tr>');
                return;
            }
            let html = '';
            users.forEach(user => {
                const statusClass = user.status === 'active' ? 'status-active' : 'status-locked';
                const statusText = user.status === 'active' ? 'Đang hoạt động' : 'Đã khóa';
                const toggleBtn = user.status === 'active' 
                    ? '<button class="btn btn-sm btn-warning toggle-status" data-id="' + user.id + '"><i class="fas fa-lock"></i> Khóa</button>'
                    : '<button class="btn btn-sm btn-success toggle-status" data-id="' + user.id + '"><i class="fas fa-unlock"></i> Mở khóa</button>';
                
                const nameParts = user.full_name.split(' ');
                const avatarText = nameParts.length > 1 
                    ? nameParts[0].charAt(0) + nameParts[nameParts.length - 1].charAt(0)
                    : (user.full_name.substring(0, 2) || user.username.substring(0, 2));
                
                html += `
                    <tr>
                        <td>${user.id}</td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="user-avatar">${avatarText.toUpperCase()}</div>
                                <div>
                                    <div class="fw-bold">${escapeHtml(user.full_name)}</div>
                                    <small class="text-muted">@${escapeHtml(user.username)}</small>
                                </div>
                            </div>
                        </td>
                        <td>${escapeHtml(user.email)}</td>
                        <td>${user.phone || '---'}</td>
                        <td>${new Date(user.register_date).toLocaleDateString('vi-VN')}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td class="action-buttons">
                            <button class="btn btn-sm btn-info edit-user" data-id="${user.id}"><i class="fas fa-edit"></i> Sửa</button>
                            ${toggleBtn}
                        </td>
                    </tr>
                `;
            });
            tbody.html(html);
        }

        function renderPagination(pagination) {
            const container = $('#paginationContainer');
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
                    loadUsers();
                }
            });
        }

        function updateStats(stats) {
            $('#totalUsers').text(stats.total || 0);
            $('#activeUsers').text(stats.active || 0);
            $('#lockedUsers').text(stats.locked || 0);
        }

        // Add user
        $('#btn-add-user').click(function() {
            $('#userModalTitle').text('Thêm người dùng');
            $('#userForm')[0].reset();
            $('#user_id').val('');
            $('#username').prop('disabled', false);
            $('#password-info').show();
            $('#reset-password-section').hide();
            $('#new_password').val('');
            $('#confirm_password').val('');
            $('#password-match-error').hide();
            $('#password-match-success').hide();
            $('#userModal').modal('show');
        });

        // Edit user
        $(document).on('click', '.edit-user', function() {
            const userId = $(this).data('id');
            $.getJSON(window.location.href, { ajax: 1, action: 'get', id: userId }, function(res) {
                if (res.success) {
                    const user = res.user;
                    $('#userModalTitle').text('Sửa người dùng');
                    $('#user_id').val(user.id);
                    $('#full_name').val(user.full_name);
                    $('#username').val(user.username).prop('disabled', true);
                    $('#email').val(user.email);
                    $('#phone').val(user.phone || '');
                    $('#address').val(user.address || '');
                    $('#birthday').val(user.birthday || '');
                    $('#password-info').hide();
                    $('#reset-password-section').show();
                    $('#new_password').val('');
                    $('#confirm_password').val('');
                    $('#password-match-error').hide();
                    $('#password-match-success').hide();
                    $('#userModal').modal('show');
                } else {
                    alert('Lỗi: ' + res.error);
                }
            });
        });

        // Save user
        $('#saveUserBtn').click(function() {
            const userId = $('#user_id').val();
            const action = userId ? 'edit' : 'add';
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', action);
            formData.append('full_name', $('#full_name').val());
            formData.append('email', $('#email').val());
            formData.append('phone', $('#phone').val());
            formData.append('address', $('#address').val());
            formData.append('birthday', $('#birthday').val());
            
            if (!userId) {
                formData.append('username', $('#username').val());
            } else {
                formData.append('user_id', userId);
                const newPassword = $('#new_password').val();
                const confirmPassword = $('#confirm_password').val();
                
                // Nếu có nhập mật khẩu mới
                if (newPassword !== '') {
                    if (newPassword !== confirmPassword) {
                        alert('Mật khẩu xác nhận không khớp');
                        return;
                    }
                    if (newPassword.length < 6) {
                        alert('Mật khẩu phải có ít nhất 6 ký tự');
                        return;
                    }
                    formData.append('new_password', newPassword);
                    formData.append('confirm_password', confirmPassword);
                }
            }
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#userModal').modal('hide');
                        loadUsers();
                    } else {
                        alert('Lỗi: ' + res.error);
                    }
                },
                error: function() {
                    alert('Có lỗi xảy ra');
                }
            });
        });

        // Toggle status (lock/unlock)
        $(document).on('click', '.toggle-status', function() {
            const userId = $(this).data('id');
            if (confirm('Bạn có chắc chắn muốn thay đổi trạng thái người dùng này?')) {
                $.post(window.location.href, { ajax: 1, action: 'toggle_status', user_id: userId }, function(res) {
                    if (res.success) {
                        loadUsers();
                    } else {
                        alert('Lỗi: ' + res.error);
                    }
                }, 'json');
            }
        });

        // Search
        $('#searchButton').click(function() {
            currentFilters.search = $('#searchName').val();
            currentFilters.status = $('#searchStatus').val();
            currentPage = 1;
            loadUsers();
        });
        
        $('#resetButton').click(function() {
            $('#searchName').val('');
            $('#searchStatus').val('');
            currentFilters = { search: '', status: '' };
            currentPage = 1;
            loadUsers();
        });
        
        $('#searchName').keypress(function(e) {
            if (e.which === 13) {
                $('#searchButton').click();
            }
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

        // Load initial data
        loadUsers();
    </script>
</body>
</html>