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

// Xử lý AJAX requests
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        switch ($action) {
            case 'list':
                $page = (int)($_GET['page'] ?? 1);
                $limit = 6;
                $offset = ($page - 1) * $limit;
                
                $sql = "SELECT c.*, COUNT(p.id) as product_count 
                        FROM categories c 
                        LEFT JOIN products p ON c.id = p.category_id AND p.status = 'active'
                        WHERE c.status = 'active'
                        GROUP BY c.id ORDER BY c.id DESC LIMIT :limit OFFSET :offset";
                
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $categories = $stmt->fetchAll();
                
                $countStmt = $pdo->query("SELECT COUNT(*) as total FROM categories WHERE status = 'active'");
                $total = $countStmt->fetch()['total'];
                $totalPages = ceil($total / $limit);
                
                echo json_encode([
                    'success' => true,
                    'data' => $categories,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'total_records' => $total
                    ]
                ]);
                break;
                
            case 'add':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) throw new Exception('Vui lòng nhập tên loại sản phẩm');
                
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
                    
                    $filename = 'category_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        $image_path = 'images/' . $filename;
                    } else {
                        throw new Exception('Không thể upload ảnh');
                    }
                }
                
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND status = 'active'");
                $stmt->execute([$name]);
                if ($stmt->fetch()) throw new Exception('Tên loại sản phẩm đã tồn tại');
                
                $stmt = $pdo->prepare("INSERT INTO categories (name, description, image, status, created_at, updated_at) 
                                       VALUES (?, ?, ?, 'active', NOW(), NOW())");
                $stmt->execute([$name, $description, $image_path]);
                
                echo json_encode(['success' => true, 'category_id' => $pdo->lastInsertId()]);
                break;
                
            case 'edit':
                $category_id = (int)($_POST['category_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (!$category_id) throw new Exception('Thiếu ID loại sản phẩm');
                if (empty($name)) throw new Exception('Vui lòng nhập tên loại sản phẩm');
                
                $stmt = $pdo->prepare("SELECT image FROM categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $old_category = $stmt->fetch();
                $image_path = $old_category['image'] ?? '';
                
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
                    
                    $filename = 'category_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        if ($image_path && file_exists('../' . $image_path)) {
                            unlink('../' . $image_path);
                        }
                        $image_path = 'images/' . $filename;
                    } else {
                        throw new Exception('Không thể upload ảnh');
                    }
                }
                
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ? AND status = 'active'");
                $stmt->execute([$name, $category_id]);
                if ($stmt->fetch()) throw new Exception('Tên loại sản phẩm đã tồn tại');
                
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ?, image = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$name, $description, $image_path, $category_id]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'delete':
                $category_id = (int)($_POST['category_id'] ?? 0);
                if (!$category_id) throw new Exception('Thiếu ID loại sản phẩm');
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ? AND status = 'active'");
                $stmt->execute([$category_id]);
                $product_count = $stmt->fetch()['count'];
                
                if ($product_count > 0) {
                    throw new Exception('Không thể xóa loại sản phẩm này vì còn ' . $product_count . ' sản phẩm đang thuộc loại này');
                }
                
                $stmt = $pdo->prepare("SELECT image FROM categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $category = $stmt->fetch();
                
                if ($category && $category['image'] && file_exists('../' . $category['image'])) {
                    unlink('../' . $category['image']);
                }
                
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$category_id]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'get':
                $category_id = (int)($_GET['id'] ?? 0);
                if (!$category_id) throw new Exception('Thiếu ID loại sản phẩm');
                
                $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $category = $stmt->fetch();
                if (!$category) throw new Exception('Không tìm thấy loại sản phẩm');
                
                echo json_encode(['success' => true, 'category' => $category]);
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

// Mảng ảnh mặc định cho từng loại (theo tên file chính xác)
$default_images = [
    'pizza' => '../images/f6.png',
    'burger' => '../images/f7.png',
    'pasta' => '../images/f9.png',
    'fries' => '../images/f5.png',
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Loại sản phẩm - Feane Restaurant</title>
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
        
        .categories-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .category-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            transition: all 0.3s;
        }
        
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0,0,0,.12);
        }
        
        .category-img-wrapper {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f5f5f5 0%, #e8e8e8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .category-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }
        
        .category-card:hover .category-image {
            transform: scale(1.05);
        }
        
        .category-content {
            padding: 16px;
        }
        
        .category-content h4 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 8px;
        }
        
        .category-content p {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 12px;
            line-height: 1.4;
        }
        
        .product-count {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .action-buttons .btn {
            flex: 1;
            padding: 6px 12px;
            font-size: 0.85rem;
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
        
        .image-preview {
            max-width: 100%;
            max-height: 150px;
            object-fit: contain;
            border-radius: 8px;
            margin-top: 10px;
            background: #f8f9fa;
            padding: 10px;
        }
        
        @media (max-width: 768px) {
            .categories-container {
                grid-template-columns: 1fr;
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
            <li class="nav-item"><a class="nav-link active" href="categories.php"><i class="fas fa-tags"></i> <span>Loại sản phẩm</span></a></li>
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
        <div class="page-header">
            <h2><i class="fas fa-tags me-2"></i>Quản lý loại sản phẩm</h2>
            <button class="btn btn-custom" id="btn-add-category">
                <i class="fas fa-plus me-2"></i>Thêm loại sản phẩm
            </button>
        </div>

        <!-- Categories Container -->
        <div class="categories-container" id="categoriesContainer">
            <div class="text-center w-100 py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Đang tải...</span>
                </div>
                <p class="mt-2 text-muted">Đang tải dữ liệu...</p>
            </div>
        </div>

        <!-- Pagination -->
        <div class="pagination-container" id="paginationContainer"></div>
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

    <!-- Modal Thêm/Sửa loại sản phẩm -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalTitle">Thêm loại sản phẩm</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="categoryForm" enctype="multipart/form-data">
                        <input type="hidden" id="category_id" name="category_id">
                        <div class="mb-3">
                            <label class="form-label">Tên loại <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mô tả</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Hình ảnh</label>
                            <input type="file" class="form-control" id="image" name="image" accept="image/*">
                            <small class="text-muted">Chọn file ảnh (jpg, jpeg, png, gif, webp)</small>
                            <div id="imagePreviewContainer" style="display: none; margin-top: 10px;">
                                <img id="imagePreview" class="image-preview" alt="Xem trước ảnh">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-custom" id="saveCategoryBtn">Lưu</button>
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
                    <p>Bạn có chắc chắn muốn xóa loại sản phẩm "<strong id="deleteCategoryName"></strong>" không?</p>
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
        let deleteId = null;
        let currentImagePath = '';

        // Mảng ảnh mặc định cho từng loại (theo tên file chính xác)
        const defaultImages = {
            'pizza': '../images/f6.png',
            'burger': '../images/f7.png',
            'pasta': '../images/f9.png',
            'fries': '../images/f5.png',
        };

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

        // Lấy đường dẫn ảnh thông minh
        function getImageUrl(imagePath, categoryName) {
            // Nếu có ảnh trong database
            if (imagePath && imagePath !== '') {
                // Xử lý đường dẫn
                let url = imagePath;
                if (!url.startsWith('http://') && !url.startsWith('https://') && !url.startsWith('../')) {
                    if (url.startsWith('images/')) {
                        url = '../' + url;
                    } else {
                        url = '../images/' + url;
                    }
                }
                return url;
            }
            
            // Nếu không có ảnh, dùng ảnh mặc định theo tên loại
            if (categoryName) {
                const lowerName = categoryName.toLowerCase();
                if (defaultImages[lowerName]) {
                    return defaultImages[lowerName];
                }
            }
            
            return defaultImages.default;
        }

        // Load categories
        function loadCategories() {
            const params = { ajax: 1, action: 'list', page: currentPage };
            $.getJSON(window.location.href, params, function(response) {
                if (response.success) {
                    renderCategories(response.data);
                    renderPagination(response.pagination);
                } else {
                    $('#categoriesContainer').html('<div class="text-center w-100 py-5 text-danger">Lỗi tải dữ liệu: ' + (response.error || 'Không xác định') + '</div>');
                }
            }).fail(function() {
                $('#categoriesContainer').html('<div class="text-center w-100 py-5 text-danger">Lỗi kết nối máy chủ</div>');
            });
        }

        function renderCategories(categories) {
            const container = $('#categoriesContainer');
            if (!categories.length) {
                container.html(`
                    <div class="text-center w-100 py-5">
                        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Không có loại sản phẩm nào</p>
                        <button class="btn btn-custom" id="btn-add-empty">+ Thêm loại sản phẩm</button>
                    </div>
                `);
                $('#btn-add-empty').click(function() { openAddModal(); });
                return;
            }
            
            let html = '';
            categories.forEach(cat => {
                const imageUrl = getImageUrl(cat.image, cat.name);
                html += `
                    <div class="category-card" data-id="${cat.id}">
                        <div class="category-img-wrapper">
                            <img src="${imageUrl}" class="category-image" alt="${escapeHtml(cat.name)}" 
                                 onerror="this.onerror=null; this.src='${defaultImages.default}';">
                        </div>
                        <div class="category-content">
                            <h4>${escapeHtml(cat.name)}</h4>
                            <p>${escapeHtml(cat.description || 'Chưa có mô tả')}</p>
                            <div class="product-count">
                                <i class="fas fa-box me-1"></i>${cat.product_count || 0} sản phẩm
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-warning edit-category" data-id="${cat.id}">
                                    <i class="fas fa-edit me-1"></i>Sửa
                                </button>
                                <button class="btn btn-danger delete-category" data-id="${cat.id}" data-name="${escapeHtml(cat.name)}">
                                    <i class="fas fa-trash-alt me-1"></i>Xóa
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            container.html(html);
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
                    loadCategories();
                }
            });
        }

        function openAddModal() {
            $('#categoryModalTitle').text('Thêm loại sản phẩm');
            $('#categoryForm')[0].reset();
            $('#category_id').val('');
            $('#imagePreviewContainer').hide();
            currentImagePath = '';
            $('#categoryModal').modal('show');
        }

        function openEditModal(id) {
            $.getJSON(window.location.href, { ajax: 1, action: 'get', id: id }, function(res) {
                if (res.success) {
                    const cat = res.category;
                    $('#categoryModalTitle').text('Sửa loại sản phẩm');
                    $('#category_id').val(cat.id);
                    $('#name').val(cat.name);
                    $('#description').val(cat.description || '');
                    currentImagePath = cat.image || '';
                    if (currentImagePath) {
                        const previewUrl = getImageUrl(currentImagePath, cat.name);
                        $('#imagePreview').attr('src', previewUrl);
                        $('#imagePreviewContainer').show();
                    } else {
                        $('#imagePreviewContainer').hide();
                    }
                    $('#categoryModal').modal('show');
                } else {
                    alert('Lỗi: ' + (res.error || 'Không thể tải dữ liệu'));
                }
            }).fail(function() {
                alert('Lỗi kết nối khi tải dữ liệu');
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
                const previewUrl = getImageUrl(currentImagePath, $('#name').val());
                $('#imagePreview').attr('src', previewUrl);
                $('#imagePreviewContainer').show();
            } else {
                $('#imagePreviewContainer').hide();
            }
        });

        // Save category
        $('#saveCategoryBtn').click(function() {
            const categoryId = $('#category_id').val();
            const action = categoryId ? 'edit' : 'add';
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', action);
            formData.append('name', $('#name').val());
            formData.append('description', $('#description').val());
            
            const fileInput = $('#image')[0].files[0];
            if (fileInput) {
                formData.append('image', fileInput);
            }
            if (categoryId) formData.append('category_id', categoryId);
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#categoryModal').modal('hide');
                        currentPage = 1;
                        loadCategories();
                        showNotification('Đã lưu thành công');
                    } else {
                        alert('Lỗi: ' + (res.error || 'Không xác định'));
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

        // Delete category
        $(document).on('click', '.delete-category', function() {
            deleteId = $(this).data('id');
            const name = $(this).data('name');
            $('#deleteCategoryName').text(name);
            $('#deleteModal').modal('show');
        });

        $('#confirmDeleteBtn').click(function() {
            if (!deleteId) return;
            $.post(window.location.href, { ajax: 1, action: 'delete', category_id: deleteId }, function(res) {
                if (res.success) {
                    $('#deleteModal').modal('hide');
                    loadCategories();
                    showNotification('Đã xóa thành công');
                } else {
                    alert('Lỗi: ' + (res.error || 'Không thể xóa'));
                }
            }, 'json');
        });

        // Edit category
        $(document).on('click', '.edit-category', function() {
            const id = $(this).data('id');
            openEditModal(id);
        });

        $('#btn-add-category').click(function() {
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
        loadCategories();
    </script>
</body>
</html>