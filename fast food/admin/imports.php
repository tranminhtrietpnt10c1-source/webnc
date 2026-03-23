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
                $date = $_GET['date'] ?? '';
                $status = $_GET['status'] ?? '';
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;

                $sql = "SELECT i.id, i.import_code, i.import_date, i.status,
                               COUNT(d.id) AS product_count,
                               SUM(d.quantity) AS total_quantity,
                               SUM(d.subtotal) AS total_value
                        FROM imports i
                        LEFT JOIN import_details d ON i.id = d.import_id
                        WHERE 1=1";
                $params = [];

                if ($search) {
                    $sql .= " AND (i.import_code LIKE :search OR EXISTS (
                                SELECT 1 FROM import_details d2
                                JOIN products p ON d2.product_id = p.id
                                WHERE d2.import_id = i.id AND p.name LIKE :search2
                            ))";
                    $params[':search'] = "%$search%";
                    $params[':search2'] = "%$search%";
                }
                if ($date) {
                    $sql .= " AND DATE(i.import_date) = :date";
                    $params[':date'] = $date;
                }
                if ($status && in_array($status, ['pending', 'completed'])) {
                    $sql .= " AND i.status = :status";
                    $params[':status'] = $status;
                }

                $sql .= " GROUP BY i.id ORDER BY i.import_date DESC, i.id DESC LIMIT :limit OFFSET :offset";
                $params[':limit'] = $limit;
                $params[':offset'] = $offset;

                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    if (is_int($val)) $stmt->bindValue($key, $val, PDO::PARAM_INT);
                    else $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $imports = $stmt->fetchAll();

                // Count query
                $countSql = "SELECT COUNT(DISTINCT i.id) as total FROM imports i
                             LEFT JOIN import_details d ON i.id = d.import_id WHERE 1=1";
                $countParams = [];
                if ($search) {
                    $countSql .= " AND (i.import_code LIKE :search OR EXISTS (
                                    SELECT 1 FROM import_details d2
                                    JOIN products p ON d2.product_id = p.id
                                    WHERE d2.import_id = i.id AND p.name LIKE :search2
                                ))";
                    $countParams[':search'] = "%$search%";
                    $countParams[':search2'] = "%$search%";
                }
                if ($date) {
                    $countSql .= " AND DATE(i.import_date) = :date";
                    $countParams[':date'] = $date;
                }
                if ($status && in_array($status, ['pending', 'completed'])) {
                    $countSql .= " AND i.status = :status";
                    $countParams[':status'] = $status;
                }
                $countStmt = $pdo->prepare($countSql);
                foreach ($countParams as $key => $val) {
                    $countStmt->bindValue($key, $val);
                }
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
                exit;

            case 'get':
                $import_id = (int)($_GET['id'] ?? 0);
                if (!$import_id) throw new Exception('Missing import ID');

                $stmt = $pdo->prepare("SELECT id, import_code, import_date, status FROM imports WHERE id = ?");
                $stmt->execute([$import_id]);
                $import = $stmt->fetch();
                if (!$import) throw new Exception('Import not found');

                $stmt = $pdo->prepare("SELECT d.id, d.product_id, d.quantity, d.unit_cost, d.subtotal, p.name AS product_name
                                       FROM import_details d
                                       JOIN products p ON d.product_id = p.id
                                       WHERE d.import_id = ?");
                $stmt->execute([$import_id]);
                $details = $stmt->fetchAll();

                echo json_encode([
                    'success' => true,
                    'import' => $import,
                    'details' => $details
                ]);
                exit;

            case 'add':
                $import_date = $_POST['import_date'] ?? date('Y-m-d');
                $products = $_POST['products'] ?? [];
                $quantities = $_POST['quantities'] ?? [];
                $prices = $_POST['prices'] ?? [];

                if (empty($products)) throw new Exception('Phải có ít nhất một sản phẩm');

                $import_code = 'PN-' . date('Ymd') . '-' . strtoupper(uniqid());
                $stmt = $pdo->prepare("SELECT id FROM imports WHERE import_code = ?");
                $stmt->execute([$import_code]);
                if ($stmt->fetch()) {
                    $import_code .= '-' . rand(100,999);
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO imports (import_code, import_date, status) VALUES (?, ?, 'pending')");
                $stmt->execute([$import_code, $import_date]);
                $import_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO import_details (import_id, product_id, quantity, unit_cost, subtotal) VALUES (?, ?, ?, ?, ?)");
                foreach ($products as $idx => $prod_id) {
                    if ($prod_id && $quantities[$idx] > 0) {
                        $subtotal = $quantities[$idx] * $prices[$idx];
                        $stmt->execute([$import_id, $prod_id, $quantities[$idx], $prices[$idx], $subtotal]);
                    }
                }

                $stmt = $pdo->prepare("UPDATE imports SET total_amount = (SELECT SUM(subtotal) FROM import_details WHERE import_id = ?) WHERE id = ?");
                $stmt->execute([$import_id, $import_id]);

                $pdo->commit();
                echo json_encode(['success' => true, 'import_id' => $import_id]);
                exit;

            case 'edit':
                $import_id = (int)($_POST['import_id'] ?? 0);
                $import_date = $_POST['import_date'] ?? date('Y-m-d');
                $products = $_POST['products'] ?? [];
                $quantities = $_POST['quantities'] ?? [];
                $prices = $_POST['prices'] ?? [];

                $stmt = $pdo->prepare("SELECT status FROM imports WHERE id = ?");
                $stmt->execute([$import_id]);
                $import = $stmt->fetch();
                if (!$import || $import['status'] != 'pending') {
                    throw new Exception('Chỉ có thể sửa phiếu nhập chưa hoàn thành');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE imports SET import_date = ? WHERE id = ?");
                $stmt->execute([$import_date, $import_id]);

                $stmt = $pdo->prepare("DELETE FROM import_details WHERE import_id = ?");
                $stmt->execute([$import_id]);

                $stmt = $pdo->prepare("INSERT INTO import_details (import_id, product_id, quantity, unit_cost, subtotal) VALUES (?, ?, ?, ?, ?)");
                foreach ($products as $idx => $prod_id) {
                    if ($prod_id && $quantities[$idx] > 0) {
                        $subtotal = $quantities[$idx] * $prices[$idx];
                        $stmt->execute([$import_id, $prod_id, $quantities[$idx], $prices[$idx], $subtotal]);
                    }
                }

                $stmt = $pdo->prepare("UPDATE imports SET total_amount = (SELECT SUM(subtotal) FROM import_details WHERE import_id = ?) WHERE id = ?");
                $stmt->execute([$import_id, $import_id]);

                $pdo->commit();
                echo json_encode(['success' => true]);
                exit;

            case 'delete':
                $import_id = (int)($_POST['import_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT status FROM imports WHERE id = ?");
                $stmt->execute([$import_id]);
                $import = $stmt->fetch();
                if (!$import || $import['status'] != 'pending') {
                    throw new Exception('Chỉ có thể xóa phiếu nhập chưa hoàn thành');
                }
                $stmt = $pdo->prepare("DELETE FROM imports WHERE id = ?");
                $stmt->execute([$import_id]);
                echo json_encode(['success' => true]);
                exit;

            case 'complete':
                $import_id = (int)($_POST['import_id'] ?? 0);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT status FROM imports WHERE id = ?");
                $stmt->execute([$import_id]);
                $import = $stmt->fetch();
                if (!$import || $import['status'] != 'pending') {
                    throw new Exception('Không thể hoàn thành phiếu nhập này');
                }

                $stmt = $pdo->prepare("SELECT product_id, quantity FROM import_details WHERE import_id = ?");
                $stmt->execute([$import_id]);
                $details = $stmt->fetchAll();

                foreach ($details as $det) {
                    $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                    $stmt->execute([$det['quantity'], $det['product_id']]);
                }

                $stmt = $pdo->prepare("UPDATE imports SET status = 'completed' WHERE id = ?");
                $stmt->execute([$import_id]);

                $pdo->commit();
                echo json_encode(['success' => true]);
                exit;

            case 'getProducts':
                $stmt = $pdo->query("SELECT id, name FROM products ORDER BY name");
                $products = $stmt->fetchAll();
                echo json_encode($products);
                exit;

            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Nhập sản phẩm - Feane Restaurant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        .table th {
            background-color: var(--secondary-color);
            color: var(--light-color);
        }
        .badge-status-pending {
            background-color: #ffc107;
            color: #212529;
        }
        .badge-status-completed {
            background-color: #28a745;
            color: white;
        }
        .status-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .status-btn {
            padding: 8px 16px;
            background-color: #e9ecef;
            color: #495057;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
        }
        .status-btn:hover, .status-btn.active {
            background-color: var(--primary-color);
            color: var(--dark-color);
        }
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .page-link {
            color: var(--secondary-color);
        }
        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: var(--dark-color);
        }
        .product-row {
            background-color: #fef9e6;
            transition: all 0.2s;
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 8px;
        }
        .product-row:hover {
            background-color: #fff3d1;
        }
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }
        .select2-container--default .select2-dropdown {
            z-index: 1060 !important;
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
        .toggle-sidebar {
            display: none;
        }
        @media (max-width: 768px) {
            .toggle-sidebar {
                display: block;
            }
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
        .modal-header.bg-dark {
            background-color: var(--secondary-color) !important;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="p-3">
            <h4 class="text-center mb-4"><i class="fas fa-utensils"></i> Feane Admin</h4>
        </div>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="admin.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="users.php"><i class="fas fa-users"></i> <span>Quản lý người dùng</span></a></li>
            <li class="nav-item"><a class="nav-link" href="categories.php"><i class="fas fa-tags"></i> <span>Loại sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link" href="products.php"><i class="fas fa-hamburger"></i> <span>Sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="imports.php"><i class="fas fa-arrow-down"></i> <span>Nhập sản phẩm</span></a></li>
            <li class="nav-item"><a class="nav-link" href="pricing.php"><i class="fas fa-dollar-sign"></i> <span>Giá bán</span></a></li>
            <li class="nav-item"><a class="nav-link" href="orders.php"><i class="fas fa-shopping-cart"></i> <span>Đơn hàng</span></a></li>
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

        <div id="imports-page">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Quản lý Nhập sản phẩm</h2>
                <button type="button" class="btn btn-custom" id="btn-add-import">
                    <i class="fas fa-plus me-2"></i>Thêm phiếu nhập
                </button>
            </div>

            <div class="card card-custom mb-4">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Tìm kiếm</label>
                            <input type="text" id="search-input" class="form-control" placeholder="Mã phiếu hoặc tên sản phẩm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ngày nhập</label>
                            <input type="date" id="date-input" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
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

            <div class="card card-custom">
                <div class="card-header"><h5 class="card-title mb-0">Danh sách phiếu nhập</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="imports-table">
                            <thead>
                                <tr><th>Mã phiếu</th><th>Ngày nhập</th><th>Số sản phẩm</th><th>Tổng số lượng</th><th>Tổng giá trị</th><th>Trạng thái</th><th>Thao tác</th></tr>
                            </thead>
                            <tbody id="imports-tbody">
                                <tr><td colspan="7" class="text-center">Đang tải...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-container" id="pagination-container"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Thêm/Sửa -->
    <div class="modal fade" id="importFormModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="formModalTitle">Thêm phiếu nhập</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="import-form">
                        <input type="hidden" id="import_id" name="import_id">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Ngày nhập</label>
                            <input type="date" name="import_date" id="import_date" class="form-control" required>
                        </div>
                        <div class="mb-2 fw-bold">Danh sách sản phẩm</div>
                        <div id="product-rows-container"></div>
                        <div class="mt-3">
                            <button type="button" id="add-product-row" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-plus me-1"></i> Thêm sản phẩm
                            </button>
                        </div>
                        <div class="mt-4 d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-custom px-4">Lưu</button>
                            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Hủy</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Chi tiết -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Chi tiết phiếu nhập</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detail-content"></div>
            </div>
        </div>
    </div>

    <!-- Modal Thông tin cá nhân -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        let currentPage = 1;
        let currentFilters = { search: '', date: '', status: '' };
        let productsList = [];

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function loadImports() {
            const params = {
                ajax: 1,
                action: 'list',
                page: currentPage,
                search: currentFilters.search,
                date: currentFilters.date,
                status: currentFilters.status
            };
            $.getJSON(window.location.href, params, function(response) {
                if (response.success) {
                    renderTable(response.data);
                    renderPagination(response.pagination);
                } else {
                    alert('Lỗi tải dữ liệu: ' + response.error);
                }
            });
        }

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
                html += `
                    <tr>
                        <td><a href="#" class="view-detail" data-id="${item.id}">${escapeHtml(item.import_code)}</a></td>
                        <td>${new Date(item.import_date).toLocaleDateString('vi-VN')}</td>
                        <td>${item.product_count || 0}</td>
                        <td>${item.total_quantity || 0}</td>
                        <td>${totalValue} ₫</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <div class="action-buttons">
                                ${item.status === 'pending' ? `
                                    <button class="btn btn-sm btn-warning edit-import" data-id="${item.id}"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-success complete-import" data-id="${item.id}"><i class="fas fa-check-circle"></i></button>
                                    <button class="btn btn-sm btn-danger delete-import" data-id="${item.id}"><i class="fas fa-trash"></i></button>
                                ` : `
                                    <button class="btn btn-sm btn-info view-detail" data-id="${item.id}"><i class="fas fa-eye"></i></button>
                                `}
                            </div>
                        </td>
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

        function loadProducts() {
            $.getJSON(window.location.href, { ajax: 1, action: 'getProducts' }, function(data) {
                productsList = data;
            });
        }

        function addProductRow(selectedProductId = '', quantity = '', price = '') {
            const rowHtml = `
                <div class="product-row row g-2 align-items-end mb-3 p-2 border rounded">
                    <div class="col-md-5">
                        <label class="form-label small text-muted">Sản phẩm</label>
                        <select name="products[]" class="form-select product-select" required>
                            <option value="">-- Chọn sản phẩm --</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Số lượng</label>
                        <input type="number" name="quantities[]" class="form-control" placeholder="SL" min="1" value="${quantity}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Giá nhập (VNĐ)</label>
                        <input type="number" name="prices[]" class="form-control" placeholder="Giá" step="10000" min="0" value="${price}" required>
                    </div>
                    <div class="col-md-1 text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-row mt-2">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
            $('#product-rows-container').append(rowHtml);
            const $select = $('#product-rows-container .product-select').last();
            $select.select2({
                width: '100%',
                placeholder: '-- Chọn sản phẩm --',
                allowClear: true,
                dropdownParent: $('#importFormModal')
            });
            productsList.forEach(p => {
                $select.append(`<option value="${p.id}" ${p.id == selectedProductId ? 'selected' : ''}>${escapeHtml(p.name)}</option>`);
            });
        }

        function openFormModal(importId = null) {
            const isEdit = !!importId;
            $('#formModalTitle').text(isEdit ? 'Sửa phiếu nhập' : 'Thêm phiếu nhập');
            $('#import_id').val(importId || '');
            $('#import_date').val(new Date().toISOString().slice(0,10));
            $('#product-rows-container').empty();
            addProductRow();
            if (isEdit) {
                $.getJSON(window.location.href, { ajax: 1, action: 'get', id: importId }, function(res) {
                    if (res.success) {
                        $('#import_date').val(res.import.import_date);
                        $('#product-rows-container').empty();
                        res.details.forEach(detail => {
                            addProductRow(detail.product_id, detail.quantity, detail.unit_cost);
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

        $('#import-form').submit(function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const importId = $('#import_id').val();
            const action = importId ? 'edit' : 'add';
            formData.append('action', action);
            formData.append('ajax', 1);
            if (importId) formData.append('import_id', importId);
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#importFormModal').modal('hide');
                        loadImports();
                    } else {
                        alert('Lỗi: ' + res.error);
                    }
                },
                error: function() {
                    alert('Có lỗi xảy ra khi gửi dữ liệu');
                }
            });
        });

        $(document).on('click', '.delete-import', function() {
            const id = $(this).data('id');
            if (confirm('Bạn có chắc chắn muốn xóa phiếu nhập này?')) {
                $.post(window.location.href, { ajax: 1, action: 'delete', import_id: id }, function(res) {
                    if (res.success) loadImports();
                    else alert('Lỗi: ' + res.error);
                }, 'json');
            }
        });

        $(document).on('click', '.complete-import', function() {
            const id = $(this).data('id');
            if (confirm('Hoàn thành phiếu nhập này? Hàng sẽ được cập nhật vào kho và không thể sửa sau.')) {
                $.post(window.location.href, { ajax: 1, action: 'complete', import_id: id }, function(res) {
                    if (res.success) loadImports();
                    else alert('Lỗi: ' + res.error);
                }, 'json');
            }
        });

        $(document).on('click', '.view-detail', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            $.getJSON(window.location.href, { ajax: 1, action: 'get', id: id }, function(res) {
                if (res.success) {
                    let detailHtml = `
                        <div class="mb-3">
                            <strong>Mã phiếu:</strong> ${escapeHtml(res.import.import_code)}<br>
                            <strong>Ngày nhập:</strong> ${new Date(res.import.import_date).toLocaleDateString('vi-VN')}<br>
                            <strong>Trạng thái:</strong> <span class="badge ${res.import.status === 'completed' ? 'badge-status-completed' : 'badge-status-pending'}">${res.import.status === 'completed' ? 'Đã hoàn thành' : 'Chờ xử lý'}</span>
                        </div>
                        <table class="table table-bordered">
                            <thead><tr><th>Sản phẩm</th><th class="text-center">SL</th><th class="text-end">Giá</th><th class="text-end">Thành tiền</th></tr></thead>
                            <tbody>
                    `;
                    let totalQty = 0, totalValue = 0;
                    res.details.forEach(d => {
                        totalQty += d.quantity;
                        totalValue += d.subtotal;
                        detailHtml += `
                            <tr>
                                <td>${escapeHtml(d.product_name)}</td>
                                <td class="text-center">${d.quantity}</td>
                                <td class="text-end">${new Intl.NumberFormat('vi-VN').format(d.unit_cost)} ₫</td>
                                <td class="text-end">${new Intl.NumberFormat('vi-VN').format(d.subtotal)} ₫</td>
                            </tr>
                        `;
                    });
                    detailHtml += `
                            </tbody>
                            <tfoot>
                                <tr><th colspan="2" class="text-end">Tổng:</th><th class="text-end">${totalQty} sản phẩm</th><th class="text-end">${new Intl.NumberFormat('vi-VN').format(totalValue)} ₫</th></tr>
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
        
        $('#btn-add-import').click(function() {
            openFormModal();
        });
        
        $(document).on('click', '.edit-import', function() {
            const id = $(this).data('id');
            openFormModal(id);
        });
        
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
        
        loadProducts();
        loadImports();
    </script>
</body>
</html>