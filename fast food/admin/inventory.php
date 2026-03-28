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

// Lấy ngưỡng sắp hết hàng từ database (mặc định 10 nếu chưa có)
$low_stock_threshold = 10;
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'low_stock_threshold'");
$stmt->execute();
$threshold_row = $stmt->fetch();
if ($threshold_row) {
    $low_stock_threshold = (int)$threshold_row['setting_value'];
}

// Xử lý cập nhật ngưỡng sắp hết hàng
if (isset($_POST['update_threshold'])) {
    $new_threshold = (int)$_POST['low_stock_threshold'];
    if ($new_threshold >= 0) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                                VALUES ('low_stock_threshold', :value, NOW())
                                ON DUPLICATE KEY UPDATE setting_value = :value, updated_at = NOW()");
        $stmt->execute([':value' => $new_threshold]);
        $low_stock_threshold = $new_threshold;
        $_SESSION['success'] = "Đã cập nhật ngưỡng sắp hết hàng thành " . $new_threshold;
        header('Location: inventory.php');
        exit;
    }
}

// Xử lý ẩn sản phẩm (cập nhật status thành inactive)
if (isset($_POST['hide_product'])) {
    $product_id = (int)$_POST['product_id'];
    $stmt = $pdo->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$product_id]);
    $_SESSION['success'] = "Đã ẩn sản phẩm thành công";
    header('Location: inventory.php');
    exit;
}

// Xử lý hiển thị lại sản phẩm (cập nhật status thành active)
if (isset($_POST['show_product'])) {
    $product_id = (int)$_POST['product_id'];
    $stmt = $pdo->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$product_id]);
    $_SESSION['success'] = "Đã hiển thị lại sản phẩm";
    header('Location: inventory.php');
    exit;
}

// Lấy thông báo từ session
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success']);
unset($_SESSION['error']);

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
                $stmt = $pdo->prepare("SELECT id, code, name FROM products WHERE (name LIKE :search OR code LIKE :search) LIMIT 10");
                $stmt->execute([':search' => "%$search%"]);
                $suggestions = $stmt->fetchAll();
                echo json_encode($suggestions);
                break;

            case 'list':
                $search = $_GET['search'] ?? '';
                $max_stock = isset($_GET['max_stock']) && $_GET['max_stock'] !== '' ? (int)$_GET['max_stock'] : null;
                $stock_status = $_GET['stock_status'] ?? 'all'; // all, sufficient, low, out
                $page = (int)($_GET['page'] ?? 1);
                $limit = 5;
                $offset = ($page - 1) * $limit;
                
                // Lấy ngưỡng hiện tại
                $threshold = $low_stock_threshold;

                // Lấy tất cả sản phẩm
                $sql = "SELECT p.id, p.code, p.name, p.category_id, p.status,
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
                        LEFT JOIN categories c ON p.category_id = c.id";
                $params = [];

                if ($search) {
                    $sql .= " WHERE (p.name LIKE :search OR p.code LIKE :search)";
                    $params[':search'] = "%$search%";
                }

                $sql .= " ORDER BY p.name";

                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $all_products = $stmt->fetchAll();

                // Cập nhật stock_quantity và lọc theo trạng thái
                $filtered_products = [];
                foreach ($all_products as $product) {
                    $actual_stock = $product['total_import'] - $product['total_export'];
                    $product['stock_quantity'] = $actual_stock;
                    
                    // Xác định trạng thái tồn kho
                    if ($product['status'] === 'inactive') {
                        $product['stock_status'] = 'inactive';
                    } elseif ($actual_stock <= 0) {
                        $product['stock_status'] = 'out';
                    } elseif ($actual_stock <= $threshold) {
                        $product['stock_status'] = 'low';
                    } else {
                        $product['stock_status'] = 'sufficient';
                    }
                    
                    // Lọc theo trạng thái
                    $include = true;
                    if ($stock_status !== 'all') {
                        if ($stock_status === 'sufficient' && $product['stock_status'] !== 'sufficient') $include = false;
                        if ($stock_status === 'low' && $product['stock_status'] !== 'low') $include = false;
                        if ($stock_status === 'out' && $product['stock_status'] !== 'out') $include = false;
                    }
                    
                    // Lọc theo số lượng tồn <= max_stock (nếu có)
                    if ($include && $max_stock !== null && $max_stock > 0) {
                        if ($actual_stock > $max_stock) $include = false;
                    }
                    
                    if ($include) {
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
                    ],
                    'threshold' => $threshold
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
                
                $stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.category_id, p.status, c.name as category_name, 
                                              p.cost_price, p.selling_price,
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

            case 'get_threshold':
                echo json_encode(['success' => true, 'threshold' => $low_stock_threshold]);
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
        .status-inactive {
            color: #6c757d;
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
        .threshold-section {
            background: linear-gradient(135deg, #fff8e7 0%, #fff3e0 100%);
            border-left: 5px solid var(--primary-color);
            margin-bottom: 20px;
            padding: 15px 20px;
            border-radius: 10px;
        }
        .threshold-label {
            font-weight: 600;
            color: var(--secondary-color);
            margin-right: 10px;
        }
        .threshold-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #ff8c00;
            background: #fff;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }
        .btn-threshold {
            background-color: var(--primary-color);
            color: var(--dark-color);
            border: none;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .btn-threshold:hover {
            background-color: #e6a500;
            transform: scale(1.02);
        }
        .warning-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
        }
        .warning-box i {
            color: #ffc107;
            margin-right: 8px;
        }
        .warning-text {
            color: #856404;
            font-size: 0.85rem;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
            min-width: 100px;
        }
        .btn-hide {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            min-width: 85px;
        }
        .btn-hide:hover {
            background-color: #c82333;
            transform: scale(1.02);
        }
        .btn-show {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            min-width: 85px;
        }
        .btn-show:hover {
            background-color: #218838;
            transform: scale(1.02);
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
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-badge.inactive {
            background: #e2e3e5;
            color: #383d41;
        }
        
        /* ========== BỐ CỤC BẢNG CÂN XỨNG ========== */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            min-width: 100%;
            width: 100%;
            table-layout: fixed;
            margin-bottom: 0;
        }
        
        /* Đặt độ rộng cố định cho các cột */
        .table thead th:nth-child(1) { width: 10%; }  /* Mã SP */
        .table thead th:nth-child(2) { width: 18%; }  /* Tên sản phẩm */
        .table thead th:nth-child(3) { width: 10%; }  /* Loại */
        .table thead th:nth-child(4) { width: 10%; }  /* Số lượng tồn */
        .table thead th:nth-child(5) { width: 12%; }  /* Trạng thái */
        .table thead th:nth-child(6) { width: 27%; }  /* Cảnh báo */
        .table thead th:nth-child(7) { width: 13%; }  /* Thao tác */
        
        /* Căn chỉnh nội dung các ô */
        .table td {
            vertical-align: middle;
            word-wrap: break-word;
            word-break: break-word;
        }
        
        /* Đảm bảo cột cảnh báo có chiều cao tối thiểu */
        .table td:nth-child(6) {
            min-height: 60px;
        }
        
        /* Style cho cảnh báo khi có nội dung */
        .warning-message {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        /* Style cho ô trống - hiển thị dấu gạch ngang để giữ khoảng trống đồng đều */
        .table td:nth-child(6):empty::before,
        .table td:nth-child(6):contains("")::before {
            content: "—";
            color: #dee2e6;
            display: inline-block;
            text-align: center;
            width: 100%;
        }
        
        /* Đảm bảo các nút thao tác có kích thước đồng nhất */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
            min-width: 100px;
        }
        
        /* Đảm bảo cột trạng thái có độ rộng ổn định */
        .status-danger, .status-warning, .status-success, .status-inactive {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
            min-width: 95px;
            justify-content: center;
        }
        
        /* Đảm bảo cột số lượng tồn căn giữa đẹp */
        .table td:nth-child(4) {
            text-align: center !important;
        }
        
        /* Style cho select status filter */
        .status-select {
            background-color: white;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 8px 12px;
            width: 100%;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .status-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(255, 190, 51, 0.25);
        }
        
        /* Responsive: trên màn hình nhỏ, cho phép cuộn ngang */
        @media (max-width: 992px) {
            .table thead th:nth-child(1) { width: 12%; }
            .table thead th:nth-child(2) { width: 20%; }
            .table thead th:nth-child(3) { width: 12%; }
            .table thead th:nth-child(4) { width: 10%; }
            .table thead th:nth-child(5) { width: 12%; }
            .table thead th:nth-child(6) { width: 24%; }
            .table thead th:nth-child(7) { width: 10%; }
            
            .status-danger, .status-warning, .status-success, .status-inactive {
                white-space: normal;
                min-width: 70px;
                font-size: 11px;
                padding: 4px 8px;
            }
            
            .btn-hide, .btn-show {
                padding: 4px 8px;
                font-size: 11px;
                min-width: 70px;
            }
            
            .action-buttons {
                min-width: 80px;
            }
        }
        
        @media (max-width: 768px) {
            .table thead th:nth-child(1) { width: 12%; }
            .table thead th:nth-child(2) { width: 22%; }
            .table thead th:nth-child(3) { width: 12%; }
            .table thead th:nth-child(4) { width: 10%; }
            .table thead th:nth-child(5) { width: 12%; }
            .table thead th:nth-child(6) { width: 22%; }
            .table thead th:nth-child(7) { width: 10%; }
        }
        
        @media (max-width: 576px) {
            .table thead th {
                font-size: 12px;
                padding: 8px 4px;
            }
            .table td {
                font-size: 12px;
                padding: 8px 4px;
            }
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
            </ul>

            <!-- Threshold Setting Section -->
            <div class="threshold-section">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div class="d-flex align-items-center mb-2 mb-md-0">
                        <i class="fas fa-sliders-h me-2" style="color: var(--primary-color); font-size: 1.2rem;"></i>
                        <span class="threshold-label">Ngưỡng cảnh báo sắp hết hàng:</span>
                        <span class="threshold-value ms-2" id="threshold-display">≤ <?php echo $low_stock_threshold; ?></span>
                    </div>
                    <button class="btn-threshold" id="btnUpdateThreshold">
                        <i class="fas fa-edit me-1"></i> Cập nhật ngưỡng
                    </button>
                </div>
                <div class="warning-box mt-2">
                    <i class="fas fa-info-circle"></i>
                    <span class="warning-text">Sản phẩm có số lượng tồn ≤ <strong id="threshold-value-text"><?php echo $low_stock_threshold; ?></strong> sẽ được đánh dấu là <strong class="status-warning">"Sắp hết hàng"</strong>. Sản phẩm có số lượng tồn = 0 sẽ được đánh dấu là <strong class="status-danger">"Hết hàng"</strong> và có thể ẩn khỏi menu.</span>
                </div>
            </div>

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
                        <div class="col-md-3 mb-3">
                            <label for="max-stock" class="form-label">Lọc tồn kho ≤</label>
                            <input type="number" class="form-control" id="max-stock" name="max-stock" placeholder="Nhập số lượng tối đa">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="stock-status" class="form-label">Lọc theo trạng thái</label>
                            <select class="status-select" id="stock-status" name="stock_status">
                                <option value="all"> Tất cả</option>
                                <option value="sufficient"> Đủ hàng</option>
                                <option value="low"> Sắp hết hàng</option>
                                <option value="out"> Hết hàng</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-primary-custom w-100"><i class="fas fa-search me-2"></i>Tìm kiếm</button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 d-flex justify-content-end">
                            <button type="reset" class="btn btn-outline-secondary"><i class="fas fa-undo me-2"></i>Đặt lại</button>
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
                                    <th>Cảnh báo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="stock-table-body">
                                <tr><td colspan="7" class="text-center">Đang tải...</td></tr>
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

    <!-- Modal cập nhật ngưỡng sắp hết hàng -->
    <div class="modal fade" id="thresholdModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sliders-h me-2"></i> Cập nhật ngưỡng sắp hết hàng</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="low_stock_threshold" class="form-label">Nhập ngưỡng số lượng tồn:</label>
                            <input type="number" class="form-control" id="low_stock_threshold" name="low_stock_threshold" value="<?php echo $low_stock_threshold; ?>" min="0" required>
                            <div class="form-text">Sản phẩm có số lượng tồn ≤ ngưỡng này sẽ được đánh dấu là "Sắp hết hàng".</div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Lưu ý: Sản phẩm có số lượng tồn = 0 sẽ được đánh dấu là "Hết hàng" và có thể ẩn khỏi menu.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" name="update_threshold" class="btn btn-primary-custom">Cập nhật</button>
                    </div>
                </form>
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
        let currentFilters = { search: '', max_stock: '', stock_status: 'all' };
        let searchTimeout = null;
        let currentThreshold = <?php echo $low_stock_threshold; ?>;

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

        // Modal cập nhật ngưỡng
        $('#btnUpdateThreshold').click(function() {
            $('#thresholdModal').modal('show');
        });

        // Autocomplete functionality
        $('#search-name').on('input', function() {
            const searchText = $(this).val().trim();
            
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            if (searchText.length < 1) {
                $('#autocomplete-suggestions').removeClass('show').empty();
                return;
            }
            
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
            
            $('.autocomplete-item').off('click').on('click', function() {
                const productName = $(this).data('name');
                $('#search-name').val(productName);
                container.removeClass('show');
                currentFilters.search = productName;
                currentPage = 1;
                loadStock();
            });
        }
        
        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
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
                max_stock: currentFilters.max_stock !== '' ? currentFilters.max_stock : null,
                stock_status: currentFilters.stock_status
            };
            
            $('#stock-table-body').html('<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Đang tải...</td></tr>');
            
            $.getJSON('inventory.php', params, function(response) {
                if (response.success) {
                    if (response.threshold) {
                        currentThreshold = response.threshold;
                        $('#threshold-display').text('≤ ' + currentThreshold);
                        $('#threshold-value-text').text(currentThreshold);
                    }
                    renderStockTable(response.data);
                    renderPagination(response.pagination);
                } else {
                    $('#stock-table-body').html('<tr><td colspan="7" class="text-center text-danger">Lỗi: ' + (response.error || 'Không thể tải dữ liệu') + '</td></tr>');
                }
            }).fail(function() {
                $('#stock-table-body').html('<tr><td colspan="7" class="text-center text-danger">Không thể kết nối đến máy chủ</td></tr>');
            });
        }

        function getStockStatus(stock, productStatus = 'active') {
            if (productStatus === 'inactive') {
                return {
                    text: 'Đã ẩn',
                    class: 'status-inactive',
                    icon: 'fa-eye-slash'
                };
            }
            if (stock <= 0) {
                return {
                    text: 'Hết hàng',
                    class: 'status-danger',
                    icon: 'fa-times-circle'
                };
            } else if (stock <= currentThreshold) {
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

        function getWarningMessage(stock, productStatus) {
            if (productStatus === 'inactive') {
                return '<span class="text-muted"><i class="fas fa-eye-slash me-1"></i>Sản phẩm đã bị ẩn khỏi menu</span>';
            }
            if (stock <= 0) {
                return '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Sản phẩm đã hết hàng! Vui lòng nhập kho hoặc ẩn sản phẩm.</span>';
            } else if (stock <= currentThreshold) {
                return '<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Cần nhập hàng ngay! Tồn kho chỉ còn ' + stock + ' sản phẩm.</span>';
            }
            return '—';
        }

        function getActionButtons(product) {
            const stock = product.stock_quantity;
            const productStatus = product.status || 'active';
            
            if (productStatus === 'inactive') {
                return `
                    <div class="action-buttons">
                        <form method="POST" style="display: inline-block;" onsubmit="return confirm('Bạn có chắc muốn hiển thị lại sản phẩm này?');">
                            <input type="hidden" name="show_product" value="1">
                            <input type="hidden" name="product_id" value="${product.id}">
                            <button type="submit" class="btn-show"><i class="fas fa-eye me-1"></i>Hiển thị</button>
                        </form>
                    </div>
                `;
            }
            
            if (stock <= 0) {
                return `
                    <div class="action-buttons">
                        <form method="POST" style="display: inline-block;" onsubmit="return confirm('Bạn có chắc muốn ẩn sản phẩm này khỏi menu?');">
                            <input type="hidden" name="hide_product" value="1">
                            <input type="hidden" name="product_id" value="${product.id}">
                            <button type="submit" class="btn-hide"><i class="fas fa-eye-slash me-1"></i>Ẩn sản phẩm</button>
                        </form>
                    </div>
                `;
            }
            
            return '<div class="action-buttons">—</div>';
        }

        function renderStockTable(products) {
            const tbody = $('#stock-table-body');
            if (!products.length) {
                tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-box-open me-2"></i>Không tìm thấy sản phẩm nào</td></tr>');
                return;
            }
            let html = '';
            products.forEach(p => {
                const status = getStockStatus(p.stock_quantity, p.status);
                const warningMsg = getWarningMessage(p.stock_quantity, p.status);
                const actionBtns = getActionButtons(p);
                const rowClass = p.status === 'inactive' ? '' : (p.stock_quantity <= 0 ? 'out-stock' : (p.stock_quantity <= currentThreshold ? 'low-stock' : ''));
                
                html += `
                    <tr class="${rowClass}">
                        <td class="align-middle">${escapeHtml(p.code)}</td>
                        <td class="align-middle">
                            <a href="#" class="product-link view-detail" data-id="${p.id}">${escapeHtml(p.name)}</a>
                            ${p.status === 'inactive' ? '<span class="status-badge inactive ms-2"><i class="fas fa-eye-slash"></i> Đã ẩn</span>' : ''}
                        </td>
                        <td class="align-middle">${escapeHtml(p.category_name || '')}</td>
                        <td class="text-center align-middle"><strong>${p.stock_quantity}</strong></td>
                        <td class="align-middle">
                            <span class="${status.class}">
                                <i class="fas ${status.icon} me-1"></i>${status.text}
                            </span>
                        </td>
                        <td class="align-middle">${warningMsg}</td>
                        <td class="align-middle">${actionBtns}</td>
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
            currentFilters.stock_status = $('#stock-status').val();
            currentPage = 1;
            loadStock();
            $('#autocomplete-suggestions').removeClass('show');
        });
        
        $('#stock-filter-form button[type="reset"]').click(function() {
            $('#search-name').val('');
            $('#max-stock').val('');
            $('#stock-status').val('all');
            currentFilters = { search: '', max_stock: '', stock_status: 'all' };
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
                    const status = getStockStatus(product.stock_quantity, product.status);

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
                        <div class="row mb-3">
                            <div class="col-md-6"><div class="info-card"><div class="info-label">Trạng thái sản phẩm</div><div class="info-value">${product.status === 'active' ? '<span class="status-success">Đang bán</span>' : '<span class="status-inactive">Đã ẩn</span>'}</div></div></div>
                            <div class="col-md-6"><div class="info-card"><div class="info-label">Ngưỡng cảnh báo</div><div class="info-value">≤ ${currentThreshold}</div></div></div>
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