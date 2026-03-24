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

// Xử lý AJAX requests
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        switch ($action) {
            case 'list':
                $search = $_GET['search'] ?? '';
                $category_id = $_GET['category_id'] ?? '';
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;
                
                $sql = "SELECT p.*, c.name as category_name 
                        FROM products p 
                        LEFT JOIN categories c ON p.category_id = c.id 
                        WHERE 1=1";
                $params = [];
                
                if ($search) {
                    $sql .= " AND (p.name LIKE :search OR p.code LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                if ($category_id) {
                    $sql .= " AND p.category_id = :category_id";
                    $params[':category_id'] = $category_id;
                }
                
                $sql .= " ORDER BY p.id DESC LIMIT :limit OFFSET :offset";
                $params[':limit'] = $limit;
                $params[':offset'] = $offset;
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    if (is_int($val)) $stmt->bindValue($key, $val, PDO::PARAM_INT);
                    else $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $products = $stmt->fetchAll();
                
                // Count query
                $countSql = "SELECT COUNT(*) as total FROM products p WHERE 1=1";
                $countParams = [];
                if ($search) {
                    $countSql .= " AND (p.name LIKE :search OR p.code LIKE :search)";
                    $countParams[':search'] = "%$search%";
                }
                if ($category_id) {
                    $countSql .= " AND p.category_id = :category_id";
                    $countParams[':category_id'] = $category_id;
                }
                $countStmt = $pdo->prepare($countSql);
                foreach ($countParams as $key => $val) $countStmt->bindValue($key, $val);
                $countStmt->execute();
                $total = $countStmt->fetch()['total'];
                $totalPages = ceil($total / $limit);
                
                echo json_encode([
                    'success' => true,
                    'data' => $products,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'total_records' => $total
                    ]
                ]);
                break;
                
            case 'get_product_by_code':
                $code = trim($_GET['code'] ?? '');
                if (empty($code)) {
                    echo json_encode(['success' => true, 'exists' => false]);
                    break;
                }
                
                $stmt = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.code = ?");
                $stmt->execute([$code]);
                $product = $stmt->fetch();
                
                if ($product) {
                    echo json_encode(['success' => true, 'exists' => true, 'product' => $product]);
                } else {
                    echo json_encode(['success' => true, 'exists' => false]);
                }
                break;
                
            case 'add':
                $code = trim($_POST['code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $category_id = (int)($_POST['category_id'] ?? 0);
                $description = trim($_POST['description'] ?? '');
                $unit = trim($_POST['unit'] ?? 'cái');
                $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
                $profit_percentage = (int)($_POST['profit_percentage'] ?? 30);
                $status = $_POST['status'] ?? 'active';
                $cost_price = (float)($_POST['cost_price'] ?? 0);
                
                if (empty($code)) throw new Exception('Vui lòng nhập mã sản phẩm');
                if (empty($name)) throw new Exception('Vui lòng nhập tên sản phẩm');
                if (!$category_id) throw new Exception('Vui lòng chọn loại sản phẩm');
                if ($cost_price <= 0) throw new Exception('Vui lòng nhập giá nhập');
                
                // Kiểm tra mã sản phẩm đã tồn tại
                $stmt = $pdo->prepare("SELECT id FROM products WHERE code = ?");
                $stmt->execute([$code]);
                if ($stmt->fetch()) throw new Exception('Mã sản phẩm đã tồn tại');
                
                // Tính giá bán dựa trên tỷ lệ lợi nhuận
                $selling_price = $cost_price * (1 + $profit_percentage / 100);
                $selling_price = round($selling_price / 1000) * 1000;
                
                // Xử lý upload ảnh
                $image_path = '';
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../images/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (!in_array($file_extension, $allowed_extensions)) {
                        throw new Exception('Chỉ chấp nhận file ảnh (jpg, jpeg, png, gif, webp)');
                    }
                    
                    $filename = 'product_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        $image_path = 'images/' . $filename;
                    } else {
                        throw new Exception('Không thể upload ảnh');
                    }
                }
                
                $stmt = $pdo->prepare("INSERT INTO products (code, name, category_id, description, image, cost_price, selling_price, stock_quantity, status, profit_percentage, created_at, updated_at) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$code, $name, $category_id, $description, $image_path, $cost_price, $selling_price, $stock_quantity, $status, $profit_percentage]);
                
                echo json_encode(['success' => true, 'product_id' => $pdo->lastInsertId()]);
                break;
                
            case 'edit':
                $product_id = (int)($_POST['product_id'] ?? 0);
                $code = trim($_POST['code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $category_id = (int)($_POST['category_id'] ?? 0);
                $description = trim($_POST['description'] ?? '');
                $unit = trim($_POST['unit'] ?? 'cái');
                $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
                $profit_percentage = (int)($_POST['profit_percentage'] ?? 30);
                $status = $_POST['status'] ?? 'active';
                $cost_price = (float)($_POST['cost_price'] ?? 0);
                $reset_image = isset($_POST['reset_image']) && $_POST['reset_image'] == '1';
                
                if (!$product_id) throw new Exception('Thiếu ID sản phẩm');
                if (empty($code)) throw new Exception('Vui lòng nhập mã sản phẩm');
                if (empty($name)) throw new Exception('Vui lòng nhập tên sản phẩm');
                if (!$category_id) throw new Exception('Vui lòng chọn loại sản phẩm');
                if ($cost_price <= 0) throw new Exception('Vui lòng nhập giá nhập');
                
                // Kiểm tra mã sản phẩm không trùng
                $stmt = $pdo->prepare("SELECT id FROM products WHERE code = ? AND id != ?");
                $stmt->execute([$code, $product_id]);
                if ($stmt->fetch()) throw new Exception('Mã sản phẩm đã tồn tại');
                
                // Lấy ảnh cũ
                $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $old_product = $stmt->fetch();
                $image_path = $old_product['image'] ?? '';
                
                // Tính giá bán mới
                $selling_price = $cost_price * (1 + $profit_percentage / 100);
                $selling_price = round($selling_price / 1000) * 1000;
                
                // Xử lý upload ảnh mới
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../images/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (!in_array($file_extension, $allowed_extensions)) {
                        throw new Exception('Chỉ chấp nhận file ảnh (jpg, jpeg, png, gif, webp)');
                    }
                    
                    $filename = 'product_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        if ($image_path && file_exists('../' . $image_path)) {
                            unlink('../' . $image_path);
                        }
                        $image_path = 'images/' . $filename;
                    } else {
                        throw new Exception('Không thể upload ảnh');
                    }
                } elseif ($reset_image) {
                    if ($image_path && file_exists('../' . $image_path)) {
                        unlink('../' . $image_path);
                    }
                    $image_path = '';
                }
                
                $stmt = $pdo->prepare("UPDATE products SET code = ?, name = ?, category_id = ?, description = ?, image = ?, cost_price = ?, selling_price = ?, stock_quantity = ?, status = ?, profit_percentage = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$code, $name, $category_id, $description, $image_path, $cost_price, $selling_price, $stock_quantity, $status, $profit_percentage, $product_id]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'delete':
                $product_id = (int)($_POST['product_id'] ?? 0);
                if (!$product_id) throw new Exception('Thiếu ID sản phẩm');
                
                // Kiểm tra xem sản phẩm đã từng được nhập hàng chưa
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM import_details WHERE product_id = ?");
                $stmt->execute([$product_id]);
                $import_count = $stmt->fetch()['count'];
                
                if ($import_count > 0) {
                    // Đã từng nhập hàng -> chỉ đánh dấu ẩn
                    $stmt = $pdo->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$product_id]);
                    echo json_encode(['success' => true, 'type' => 'hide']);
                } else {
                    // Chưa từng nhập hàng -> xóa hẳn
                    $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
                    $stmt->execute([$product_id]);
                    $product = $stmt->fetch();
                    
                    if ($product && $product['image'] && file_exists('../' . $product['image'])) {
                        unlink('../' . $product['image']);
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                    $stmt->execute([$product_id]);
                    echo json_encode(['success' => true, 'type' => 'delete']);
                }
                break;
                
            case 'get':
                $product_id = (int)($_GET['id'] ?? 0);
                if (!$product_id) throw new Exception('Thiếu ID sản phẩm');
                
                $stmt = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Không tìm thấy sản phẩm');
                
                echo json_encode(['success' => true, 'product' => $product]);
                break;
                
            case 'get_categories':
                $stmt = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
                $categories = $stmt->fetchAll();
                echo json_encode($categories);
                break;
                
            case 'get_all_codes':
                $stmt = $pdo->query("SELECT code, name, description, category_id FROM products WHERE status = 'active' ORDER BY code");
                $products = $stmt->fetchAll();
                echo json_encode($products);
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
$stmt = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll();

// Lấy danh sách mã sản phẩm hiện có
$stmt = $pdo->query("SELECT code, name FROM products WHERE status = 'active' ORDER BY code");
$existing_products = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý sản phẩm - Feane Restaurant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #ffbe33;
            --secondary-color: #222831;
            --light-color: #ffffff;
            --dark-color: #121618;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table th {
            background-color: var(--secondary-color);
            color: var(--light-color);
        }
        
        .product-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
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
        
        .badge-active {
            background-color: #d4edda;
            color: #155724;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .badge-inactive {
            background-color: #f8d7da;
            color: #721c24;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .image-preview {
            max-width: 100%;
            max-height: 150px;
            object-fit: contain;
            border-radius: 8px;
            margin-top: 10px;
            background: #f8f9fa;
            padding: 10px;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        .code-select-group {
            position: relative;
        }
        
        .code-select-group .form-select {
            width: 100%;
        }
        
        .new-code-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed #dee2e6;
        }
        
        .info-alert {
            background-color: #e7f3ff;
            border-left: 4px solid var(--primary-color);
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
            }
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
            <li class="nav-item"><a class="nav-link active" href="products.php"><i class="fas fa-hamburger"></i> <span>Sản phẩm</span></a></li>
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
        <div class="page-header">
            <h2><i class="fas fa-hamburger me-2"></i>Quản lý sản phẩm</h2>
            <button class="btn btn-custom" id="btn-add-product">
                <i class="fas fa-plus me-2"></i>Thêm sản phẩm
            </button>
        </div>

        <!-- Search and Filter -->
        <div class="search-filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="searchInput">Tìm kiếm</label>
                    <input type="text" class="form-control" id="searchInput" placeholder="Nhập tên hoặc mã sản phẩm...">
                </div>
                <div class="filter-group">
                    <label for="categoryFilter">Lọc theo loại</label>
                    <select class="form-select" id="categoryFilter">
                        <option value="">Tất cả loại</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
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

        <!-- Products Table -->
        <div class="card card-custom">
            <div class="card-header">
                <h5 class="card-title mb-0">Danh sách sản phẩm</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            
                                <th>Mã SP</th>
                                <th>Tên sản phẩm</th>
                                <th>Loại</th>
                                <th>Hình ảnh</th>
                                <th>Giá nhập</th>
                                <th>Giá bán</th>
                                <th>Tồn kho</th>
                                <th>Lợi nhuận</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </thead>
                            <tbody id="productsTableBody">
                                <td colspan="10" class="text-center">Đang tải...<\/td>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-container" id="paginationContainer"></div>
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

    <!-- Modal Thêm/Sửa sản phẩm -->
    <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="productModalTitle">Thêm sản phẩm</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="productForm" enctype="multipart/form-data">
                        <input type="hidden" id="product_id" name="product_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Chọn mã sản phẩm có sẵn <span class="text-muted">(hoặc nhập mã mới bên dưới)</span></label>
                            <select class="form-select" id="select_code">
                                <option value="">-- Chọn mã sản phẩm hiện có --</option>
                                <?php foreach ($existing_products as $prod): ?>
                                    <option value="<?php echo htmlspecialchars($prod['code']); ?>"><?php echo htmlspecialchars($prod['code']) . ' - ' . htmlspecialchars($prod['name']); ?></option>
                                <?php endforeach; ?>
                                <option value="new">+ Thêm mã sản phẩm mới</option>
                            </select>
                        </div>
                        
                        <div class="info-alert" id="productInfoAlert" style="display: none;">
                            <i class="fas fa-info-circle me-2"></i>
                            <span id="productInfoText"></span>
                        </div>
                        
                        <div class="new-code-section" id="newCodeSection" style="display: none;">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Mã sản phẩm mới <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="code" name="code" placeholder="VD: PZ001">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row" id="productFields">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Tên sản phẩm <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Loại sản phẩm <span class="text-danger">*</span></label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value="">-- Chọn loại --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Đơn vị tính</label>
                                <input type="text" class="form-control" id="unit" name="unit" value="cái">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Mô tả</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Giá nhập (VNĐ) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="cost_price" name="cost_price" step="1000" min="0" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tỷ lệ lợi nhuận (%)</label>
                                <input type="number" class="form-control" id="profit_percentage" name="profit_percentage" min="0" max="100" value="30">
                                <small class="text-muted">Giá bán = Giá nhập × (100% + tỷ lệ)</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Số lượng ban đầu</label>
                                <input type="number" class="form-control" id="stock_quantity" name="stock_quantity" min="0" value="0">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Trạng thái</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active">Đang bán</option>
                                    <option value="inactive">Ẩn (không bán)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Giá bán dự kiến</label>
                                <input type="text" class="form-control" id="estimated_price" readonly disabled>
                                <small class="text-muted">Tự động tính theo giá nhập và tỷ lệ lợi nhuận</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Hình ảnh</label>
                            <input type="file" class="form-control" id="image" name="image" accept="image/*">
                            <div id="imagePreviewContainer" style="display: none; margin-top: 10px;">
                                <img id="imagePreview" class="image-preview" alt="Xem trước ảnh">
                            </div>
                            <div id="resetImageContainer" style="display: none; margin-top: 10px;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="reset_image" name="reset_image" value="1">
                                    <label class="form-check-label" for="reset_image">Xóa ảnh hiện tại</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-custom" id="saveProductBtn">Lưu</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Xác nhận xóa -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Xác nhận xóa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Bạn có chắc chắn muốn xóa sản phẩm "<strong id="deleteProductName"></strong>" không?</p>
                    <p id="deleteWarning" class="text-warning" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>Sản phẩm đã từng được nhập hàng, sẽ chỉ bị ẩn khỏi danh sách bán.
                    </p>
                    <p class="text-danger">Hành động này không thể hoàn tác!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Xóa</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPage = 1;
        let currentSearch = '';
        let currentCategory = '';
        let deleteId = null;
        let currentImagePath = '';
        let isEditing = false;

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

        // Tính giá bán dự kiến
        function calculateEstimatedPrice() {
            const costPrice = parseFloat($('#cost_price').val()) || 0;
            const profitPercent = parseFloat($('#profit_percentage').val()) || 0;
            const estimated = costPrice * (1 + profitPercent / 100);
            const rounded = Math.round(estimated / 1000) * 1000;
            $('#estimated_price').val(formatVND(rounded));
        }

        $('#cost_price, #profit_percentage').on('input', calculateEstimatedPrice);

        // Xử lý chọn mã sản phẩm từ dropdown
        $('#select_code').on('change', function() {
            const selectedCode = $(this).val();
            
            if (selectedCode === 'new') {
                // Hiển thị phần nhập mã mới
                $('#newCodeSection').show();
                $('#code').prop('required', true);
                $('#productInfoAlert').hide();
                // Reset các trường
                $('#name').val('');
                $('#category_id').val('');
                $('#description').val('');
                $('#cost_price').val('');
                $('#profit_percentage').val('30');
                $('#stock_quantity').val('0');
                $('#status').val('active');
                calculateEstimatedPrice();
                // Enable các trường nhập
                $('#name, #category_id, #description, #cost_price, #profit_percentage, #stock_quantity, #status').prop('disabled', false);
                return;
            }
            
            if (selectedCode && selectedCode !== '') {
                // Gọi AJAX để lấy thông tin sản phẩm
                $.getJSON(window.location.href, { ajax: 1, action: 'get_product_by_code', code: selectedCode }, function(res) {
                    if (res.success && res.exists) {
                        const p = res.product;
                        // Ẩn phần nhập mã mới
                        $('#newCodeSection').hide();
                        $('#code').prop('required', false);
                        // Hiển thị thông tin
                        $('#productInfoAlert').show();
                        $('#productInfoText').html(`Đã tìm thấy sản phẩm: <strong>${escapeHtml(p.name)}</strong> - Loại: ${escapeHtml(p.category_name || 'Chưa xác định')}`);
                        // Tự động điền thông tin
                        $('#name').val(p.name);
                        $('#category_id').val(p.category_id);
                        $('#description').val(p.description || '');
                        $('#cost_price').val(p.cost_price);
                        $('#profit_percentage').val(p.profit_percentage || 30);
                        $('#stock_quantity').val(p.stock_quantity);
                        $('#status').val(p.status);
                        calculateEstimatedPrice();
                        // Disable các trường không nên sửa khi đã có sẵn
                        $('#name, #category_id, #description').prop('disabled', true);
                        $('#cost_price, #profit_percentage, #stock_quantity, #status').prop('disabled', false);
                    }
                });
            } else {
                // Reset form
                $('#newCodeSection').hide();
                $('#productInfoAlert').hide();
                $('#name').val('');
                $('#category_id').val('');
                $('#description').val('');
                $('#cost_price').val('');
                $('#profit_percentage').val('30');
                $('#stock_quantity').val('0');
                $('#status').val('active');
                calculateEstimatedPrice();
                $('#name, #category_id, #description, #cost_price, #profit_percentage, #stock_quantity, #status').prop('disabled', false);
                $('#code').prop('required', false);
            }
        });

        // Load products
        function loadProducts() {
            const params = {
                ajax: 1,
                action: 'list',
                page: currentPage,
                search: currentSearch,
                category_id: currentCategory
            };
            $.getJSON(window.location.href, params, function(response) {
                if (response.success) {
                    renderProducts(response.data);
                    renderPagination(response.pagination);
                } else {
                    $('#productsTableBody').html('<tr><td colspan="10" class="text-center text-danger">Lỗi tải dữ liệu: ' + response.error + '</td></tr>');
                }
            }).fail(function() {
                $('#productsTableBody').html('<tr><td colspan="10" class="text-center text-danger">Lỗi kết nối máy chủ</td></tr>');
            });
        }

        function renderProducts(products) {
            const tbody = $('#productsTableBody');
            if (!products.length) {
                tbody.html('<tr><td colspan="10" class="text-center">Không có sản phẩm nào</td></tr>');
                return;
            }
            
            let html = '';
            products.forEach(p => {
                const imageUrl = p.image && p.image !== '' ? '../' + p.image : '../images/placeholder.png';
                const statusClass = p.status === 'active' ? 'badge-active' : 'badge-inactive';
                const statusText = p.status === 'active' ? 'Đang bán' : 'Ẩn';
                
                html += `
                    <tr>
                        <td>${escapeHtml(p.code)}</td>
                        <td><strong>${escapeHtml(p.name)}</strong></td>
                        <td>${escapeHtml(p.category_name || '---')}</td>
                        <td><img src="${imageUrl}" class="product-img" onerror="this.src='../images/placeholder.png'"></td>
                        <td>${formatVND(p.cost_price)}</td>
                        <td>${formatVND(p.selling_price)}</td>
                        <td>${p.stock_quantity}</td>
                        <td>${p.profit_percentage || 0}%</td>
                        <td><span class="${statusClass}">${statusText}</span></td>
                        <td class="action-buttons">
                            <button class="btn btn-sm btn-warning edit-product" data-id="${p.id}"><i class="fas fa-edit"></i> Sửa</button>
                            <button class="btn btn-sm btn-danger delete-product" data-id="${p.id}" data-name="${escapeHtml(p.name)}"><i class="fas fa-trash"></i> Xóa</button>
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
                    loadProducts();
                }
            });
        }

        function openAddModal() {
            isEditing = false;
            $('#productModalTitle').text('Thêm sản phẩm');
            $('#productForm')[0].reset();
            $('#product_id').val('');
            $('#imagePreviewContainer').hide();
            $('#resetImageContainer').hide();
            $('#newCodeSection').hide();
            $('#productInfoAlert').hide();
            $('#select_code').val('');
            $('#name, #category_id, #description, #cost_price, #profit_percentage, #stock_quantity, #status').prop('disabled', false);
            $('#code').prop('required', false);
            currentImagePath = '';
            calculateEstimatedPrice();
            $('#productModal').modal('show');
        }

        function openEditModal(id) {
            isEditing = true;
            $.getJSON(window.location.href, { ajax: 1, action: 'get', id: id }, function(res) {
                if (res.success) {
                    const p = res.product;
                    $('#productModalTitle').text('Sửa sản phẩm');
                    $('#product_id').val(p.id);
                    $('#select_code').val('');
                    $('#newCodeSection').show();
                    $('#productInfoAlert').hide();
                    $('#code').val(p.code).prop('required', true);
                    $('#name').val(p.name);
                    $('#category_id').val(p.category_id);
                    $('#unit').val(p.unit || 'cái');
                    $('#description').val(p.description || '');
                    $('#cost_price').val(p.cost_price);
                    $('#profit_percentage').val(p.profit_percentage || 30);
                    $('#stock_quantity').val(p.stock_quantity);
                    $('#status').val(p.status);
                    currentImagePath = p.image || '';
                    calculateEstimatedPrice();
                    $('#name, #category_id, #description, #cost_price, #profit_percentage, #stock_quantity, #status').prop('disabled', false);
                    
                    if (currentImagePath) {
                        const previewUrl = '../' + currentImagePath;
                        $('#imagePreview').attr('src', previewUrl);
                        $('#imagePreviewContainer').show();
                        $('#resetImageContainer').show();
                    } else {
                        $('#imagePreviewContainer').hide();
                        $('#resetImageContainer').hide();
                    }
                    $('#productModal').modal('show');
                } else {
                    alert('Lỗi: ' + res.error);
                }
            });
        }

        // Preview image khi chọn file
        $('#image').on('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    $('#imagePreview').attr('src', event.target.result);
                    $('#imagePreviewContainer').show();
                };
                reader.readAsDataURL(file);
            } else if (currentImagePath) {
                $('#imagePreview').attr('src', '../' + currentImagePath);
                $('#imagePreviewContainer').show();
            } else {
                $('#imagePreviewContainer').hide();
            }
        });

        // Save product
        $('#saveProductBtn').click(function() {
            const productId = $('#product_id').val();
            const action = productId ? 'edit' : 'add';
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', action);
            
            // Lấy mã sản phẩm
            const selectedCode = $('#select_code').val();
            if (selectedCode === 'new' || !selectedCode || productId) {
                formData.append('code', $('#code').val());
            } else if (selectedCode && selectedCode !== '') {
                formData.append('code', selectedCode);
            }
            
            formData.append('name', $('#name').val());
            formData.append('category_id', $('#category_id').val());
            formData.append('description', $('#description').val());
            formData.append('unit', $('#unit').val());
            formData.append('stock_quantity', $('#stock_quantity').val());
            formData.append('profit_percentage', $('#profit_percentage').val());
            formData.append('status', $('#status').val());
            formData.append('cost_price', $('#cost_price').val());
            
            const fileInput = $('#image')[0].files[0];
            if (fileInput) {
                formData.append('image', fileInput);
            }
            if (productId) {
                formData.append('product_id', productId);
                if ($('#reset_image').is(':checked')) {
                    formData.append('reset_image', '1');
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
                        $('#productModal').modal('hide');
                        loadProducts();
                        showNotification('Đã lưu thành công');
                    } else {
                        alert('Lỗi: ' + res.error);
                    }
                },
                error: function(xhr) {
                    let errorMsg = 'Có lỗi xảy ra';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    }
                    alert(errorMsg);
                }
            });
        });

        // Delete product
        $(document).on('click', '.delete-product', function() {
            deleteId = $(this).data('id');
            const name = $(this).data('name');
            $('#deleteProductName').text(name);
            $('#deleteWarning').hide();
            $('#deleteModal').modal('show');
        });

        $('#confirmDeleteBtn').click(function() {
            if (!deleteId) return;
            $.post(window.location.href, { ajax: 1, action: 'delete', product_id: deleteId }, function(res) {
                if (res.success) {
                    $('#deleteModal').modal('hide');
                    loadProducts();
                    if (res.type === 'hide') {
                        showNotification('Sản phẩm đã được ẩn (đã từng nhập hàng)');
                    } else {
                        showNotification('Đã xóa thành công');
                    }
                } else {
                    alert('Lỗi: ' + res.error);
                }
            }, 'json');
        });

        // Edit product
        $(document).on('click', '.edit-product', function() {
            const id = $(this).data('id');
            openEditModal(id);
        });

        // Search
        $('#searchButton').click(function() {
            currentSearch = $('#searchInput').val();
            currentCategory = $('#categoryFilter').val();
            currentPage = 1;
            loadProducts();
        });
        
        $('#resetButton').click(function() {
            $('#searchInput').val('');
            $('#categoryFilter').val('');
            currentSearch = '';
            currentCategory = '';
            currentPage = 1;
            loadProducts();
        });
        
        $('#searchInput').keypress(function(e) {
            if (e.which === 13) {
                $('#searchButton').click();
            }
        });

        $('#btn-add-product').click(function() {
            openAddModal();
        });

        function showNotification(message) {
            const alertDiv = $('<div class="alert alert-success alert-dismissible fade show position-fixed top-0 end-0 m-3" role="alert" style="z-index: 9999;">' + message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
            $('body').append(alertDiv);
            setTimeout(() => alertDiv.fadeOut(() => alertDiv.remove()), 3000);
        }

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
        loadProducts();
    </script>
</body>
</html>