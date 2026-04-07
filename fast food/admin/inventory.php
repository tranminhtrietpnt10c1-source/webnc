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

// Hàm tính tồn kho thực tế từ import_details và order_details
function getActualStock($pdo, $product_id, $as_of_date = null) {
    if ($as_of_date) {
        // Tính tổng nhập đến ngày
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.quantity), 0) as total_import
                               FROM import_details d
                               JOIN imports i ON d.import_id = i.id
                               WHERE d.product_id = ? AND i.status = 'completed' AND DATE(i.import_date) <= ?");
        $stmt->execute([$product_id, $as_of_date]);
        $total_import = $stmt->fetch()['total_import'];

        // Tính tổng xuất đến ngày (chỉ đơn chưa hủy)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(od.quantity), 0) as total_export
                               FROM order_details od
                               JOIN orders o ON od.order_id = o.id
                               WHERE od.product_id = ? AND o.status != 'cancelled' AND DATE(o.order_date) <= ?");
        $stmt->execute([$product_id, $as_of_date]);
        $total_export = $stmt->fetch()['total_export'];
    } else {
        // Tính tổng nhập
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.quantity), 0) as total_import
                               FROM import_details d
                               JOIN imports i ON d.import_id = i.id
                               WHERE d.product_id = ? AND i.status = 'completed'");
        $stmt->execute([$product_id]);
        $total_import = $stmt->fetch()['total_import'];

        // Tính tổng xuất (chỉ đơn chưa hủy)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(od.quantity), 0) as total_export
                               FROM order_details od
                               JOIN orders o ON od.order_id = o.id
                               WHERE od.product_id = ? AND o.status != 'cancelled'");
        $stmt->execute([$product_id]);
        $total_export = $stmt->fetch()['total_export'];
    }
    
    return $total_import - $total_export;
}

// Hàm tự động cập nhật trạng thái sản phẩm dựa trên tồn kho thực tế
function autoUpdateProductStatus($pdo, $product_id) {
    $current_stock = getActualStock($pdo, $product_id);
    $new_status = ($current_stock <= 0) ? 'inactive' : 'active';
    
    $stmt = $pdo->prepare("UPDATE products SET status = :status, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':status' => $new_status, ':id' => $product_id]);
    
    // Cũng cập nhật stock_quantity trong bảng products để đồng bộ
    $stmt = $pdo->prepare("UPDATE products SET stock_quantity = :stock WHERE id = :id");
    $stmt->execute([':stock' => $current_stock, ':id' => $product_id]);
    
    return ['stock' => $current_stock, 'status' => $new_status];
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

// Lấy ngưỡng sắp hết hàng từ database
$low_stock_threshold = 10;
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'low_stock_threshold'");
$stmt->execute();
$threshold_row = $stmt->fetch();
if ($threshold_row) {
    $low_stock_threshold = (int)$threshold_row['setting_value'];
}

// Lấy ngày nhập kho đầu tiên
$first_import_date = '2026-03-21';
$stmt = $pdo->prepare("SELECT MIN(DATE(import_date)) as first_date FROM imports WHERE status = 'completed'");
$stmt->execute();
$result = $stmt->fetch();
if ($result && $result['first_date']) {
    $first_import_date = $result['first_date'];
}

// Lấy ngày hiện tại
date_default_timezone_set('Asia/Ho_Chi_Minh');
$current_date = date('Y-m-d');

// Xử lý cập nhật ngưỡng
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

// Xử lý ẩn sản phẩm thủ công
if (isset($_POST['hide_product'])) {
    $product_id = (int)$_POST['product_id'];
    $stmt = $pdo->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$product_id]);
    $_SESSION['success'] = "Đã ẩn sản phẩm thành công";
    header('Location: inventory.php');
    exit;
}

// Xử lý hiển thị lại sản phẩm thủ công
if (isset($_POST['show_product'])) {
    $product_id = (int)$_POST['product_id'];
    $stmt = $pdo->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$product_id]);
    $_SESSION['success'] = "Đã hiển thị lại sản phẩm";
    header('Location: inventory.php');
    exit;
}

$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success']);
unset($_SESSION['error']);

// Xử lý AJAX
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];

    try {
        switch ($action) {
            case 'search_products':
                $search = $_GET['search'] ?? '';
                $page = (int)($_GET['page'] ?? 1);
                $pageSize = (int)($_GET['pageSize'] ?? 10);
                $offset = ($page - 1) * $pageSize;
                
                $whereClause = '';
                $params = [];
                
                if (!empty($search)) {
                    $whereClause = "WHERE (p.name LIKE :search OR p.code LIKE :search)";
                    $params[':search'] = "%$search%";
                }
                
                $countSql = "SELECT COUNT(*) as total FROM products p $whereClause";
                $stmt = $pdo->prepare($countSql);
                foreach ($params as $key => $val) {
                    $stmt->bindValue($key, $val);
                }
                $stmt->execute();
                $total = $stmt->fetch()['total'];
                
                $sql = "SELECT p.id, p.code, p.name, p.status
                        FROM products p
                        $whereClause
                        ORDER BY p.name
                        LIMIT :offset, :pageSize";
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    $stmt->bindValue($key, $val);
                }
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
                $stmt->execute();
                $products = $stmt->fetchAll();
                
                foreach ($products as &$product) {
                    $product['stock_quantity'] = getActualStock($pdo, $product['id']);
                    $product['text'] = $product['name'] . ' (' . $product['code'] . ')';
                }
                
                echo json_encode([
                    'success' => true,
                    'results' => $products,
                    'pagination' => [
                        'more' => ($page * $pageSize) < $total
                    ]
                ]);
                break;

            case 'list':
                $search = $_GET['search'] ?? '';
                $selected_product_id = isset($_GET['selected_product_id']) && $_GET['selected_product_id'] !== '' ? (int)$_GET['selected_product_id'] : null;
                $max_stock = isset($_GET['max_stock']) && $_GET['max_stock'] !== '' ? (int)$_GET['max_stock'] : null;
                $stock_status = $_GET['stock_status'] ?? 'all';
                $stock_date = $_GET['stock_date'] ?? null;
                $page = (int)($_GET['page'] ?? 1);
                $limit = 10;
                $offset = ($page - 1) * $limit;
                
                $threshold = $low_stock_threshold;
                $is_past_date = false;
                
                if ($stock_date && $stock_date !== '' && $stock_date < $current_date) {
                    $is_past_date = true;
                }

                $sql = "SELECT p.id, p.code, p.name, p.category_id, p.status,
                               c.name as category_name
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.id";
                $params = [];

                if ($selected_product_id) {
                    $sql .= " WHERE p.id = :selected_id";
                    $params[':selected_id'] = $selected_product_id;
                } elseif ($search) {
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

                $filtered_products = [];
                foreach ($all_products as $product) {
                    // Tính tồn kho thực tế dựa trên ngày được chọn
                    if ($stock_date && $stock_date !== '') {
                        $actual_stock = getActualStock($pdo, $product['id'], $stock_date);
                    } else {
                        $actual_stock = getActualStock($pdo, $product['id']);
                    }
                    $product['stock_quantity'] = $actual_stock;
                    
                    // Xác định trạng thái tồn kho dựa trên tồn thực tế
                    if ($product['status'] === 'inactive') {
                        $product['stock_status'] = 'inactive';
                    } elseif ($actual_stock <= 0) {
                        $product['stock_status'] = 'out';
                    } elseif ($actual_stock <= $threshold) {
                        $product['stock_status'] = 'low';
                    } else {
                        $product['stock_status'] = 'sufficient';
                    }
                    
                    $include = true;
                    if ($stock_status !== 'all') {
                        if ($stock_status === 'sufficient' && $product['stock_status'] !== 'sufficient') $include = false;
                        if ($stock_status === 'low' && $product['stock_status'] !== 'low') $include = false;
                        if ($stock_status === 'out' && $product['stock_status'] !== 'out') $include = false;
                    }
                    
                    if ($include && $max_stock !== null && $max_stock > 0) {
                        if ($actual_stock > $max_stock) $include = false;
                    }
                    
                    if ($include) {
                        $filtered_products[] = $product;
                    }
                }

                $total_records = count($filtered_products);
                $total_pages = ceil($total_records / $limit);
                $products = array_slice($filtered_products, $offset, $limit);

                echo json_encode([
                    'success' => true,
                    'data' => $products,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $total_pages,
                        'total_records' => $total_records
                    ],
                    'threshold' => $threshold,
                    'stock_date' => $stock_date,
                    'is_past_date' => $is_past_date,
                    'selected_product_id' => $selected_product_id,
                    'date_range' => [
                        'min' => $first_import_date,
                        'max' => $current_date
                    ]
                ]);
                break;

            case 'get_product_info':
                $product_id = (int)($_GET['id'] ?? 0);
                if (!$product_id) throw new Exception('Missing product ID');
                
                $stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.category_id, p.status, c.name as category_name, 
                                              p.cost_price, p.selling_price
                                       FROM products p 
                                       LEFT JOIN categories c ON p.category_id = c.id 
                                       WHERE p.id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Product not found');
                
                $product['stock_quantity'] = getActualStock($pdo, $product_id);
                $product['total_import'] = getTotalImport($pdo, $product_id);
                $product['total_export'] = getTotalExport($pdo, $product_id);
                
                echo json_encode(['success' => true, 'product' => $product]);
                break;

            case 'get_threshold':
                echo json_encode(['success' => true, 'threshold' => $low_stock_threshold]);
                break;
                
            case 'get_date_range':
                echo json_encode([
                    'success' => true, 
                    'min_date' => $first_import_date,
                    'max_date' => $current_date
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

// Hàm helper để lấy tổng nhập
function getTotalImport($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.quantity), 0) as total
                           FROM import_details d
                           JOIN imports i ON d.import_id = i.id
                           WHERE d.product_id = ? AND i.status = 'completed'");
    $stmt->execute([$product_id]);
    return $stmt->fetch()['total'];
}

// Hàm helper để lấy tổng xuất
function getTotalExport($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(od.quantity), 0) as total
                           FROM order_details od
                           JOIN orders o ON od.order_id = o.id
                           WHERE od.product_id = ? AND o.status != 'cancelled'");
    $stmt->execute([$product_id]);
    return $stmt->fetch()['total'];
}

// Tự động đồng bộ trạng thái và tồn kho cho tất cả sản phẩm khi load trang
$stmt = $pdo->prepare("SELECT id FROM products");
$stmt->execute();
$all_products = $stmt->fetchAll();
foreach ($all_products as $product) {
    autoUpdateProductStatus($pdo, $product['id']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 0.375rem;
            border-color: #dee2e6;
        }
        .select2-container--bootstrap-5 .select2-selection--single {
            padding: 0.375rem 0.75rem;
            min-height: 38px;
        }
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            padding: 0;
        }
        .select2-container--bootstrap-5 .select2-dropdown {
            border-color: #dee2e6;
            border-radius: 0.375rem;
        }
        .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-color);
            color: var(--dark-color);
        }
        .select2-container--bootstrap-5 .select2-search__field:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 190, 51, 0.25);
        }
        .product-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .product-option-name {
            font-weight: 500;
        }
        .product-option-code {
            font-size: 0.8rem;
            color: #6c757d;
            margin-left: 10px;
        }
        .product-option-status {
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 12px;
            margin-left: 8px;
        }
        .product-option-status.active {
            background-color: #d4edda;
            color: #155724;
        }
        .product-option-status.inactive {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .date-filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 5px 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            position: relative;
        }
        .date-filter-group label {
            margin: 0;
            font-weight: 500;
            color: var(--secondary-color);
        }
        .date-filter-group input {
            border: none;
            padding: 8px 0;
            outline: none;
            cursor: pointer;
            background: transparent;
            min-width: 150px;
        }
        .date-filter-group input:focus {
            box-shadow: none;
        }
        .date-range-info {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 5px;
            margin-left: 5px;
        }
        .date-range-info i {
            margin-right: 3px;
        }
        .current-date-info {
            font-size: 0.75rem;
            color: var(--primary-color);
            margin-top: 5px;
            margin-left: 5px;
        }
        
        .flatpickr-day.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        .flatpickr-day.disabled:hover {
            background: transparent;
        }
        
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
        
        .table thead th {
            vertical-align: middle;
            background-color: #f8f9fa;
        }
        
        .table thead th:nth-child(1) { width: 8%; }
        .table thead th:nth-child(2) { width: 20%; }
        .table thead th:nth-child(3) { width: 10%; }
        .table thead th:nth-child(4) { width: 12%; }
        .table thead th:nth-child(5) { width: 27%; }
        .table thead th:nth-child(6) { width: 13%; }
        
        .table td {
            vertical-align: middle;
            word-wrap: break-word;
            word-break: break-word;
        }
        
        .status-cell {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }
        
        .date-filter-row {
            display: flex;
            align-items: flex-end;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .stock-date-badge {
            background-color: var(--primary-color);
            color: var(--dark-color);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
            margin-left: 10px;
        }
        
        .auto-status-note {
            background-color: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
        }
        .auto-status-note i {
            color: #2196F3;
            margin-right: 8px;
        }
        
        @media (max-width: 992px) {
            .table thead th:nth-child(1) { width: 10%; }
            .table thead th:nth-child(2) { width: 22%; }
            .table thead th:nth-child(3) { width: 10%; }
            .table thead th:nth-child(4) { width: 12%; }
            .table thead th:nth-child(5) { width: 24%; }
            .table thead th:nth-child(6) { width: 12%; }
        }
        
        @media (max-width: 768px) {
            .table thead th {
                font-size: 12px;
                padding: 8px 4px;
            }
            .table td {
                font-size: 12px;
                padding: 8px 4px;
            }
            .status-cell {
                padding: 3px 8px;
                font-size: 10px;
            }
            .btn-hide, .btn-show {
                padding: 3px 8px;
                font-size: 10px;
                min-width: 65px;
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

            <ul class="nav nav-tabs stock-tabs" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" href="inventory.php">Danh sách tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="import_export.php">Tra cứu nhập - xuất kho</a>
                </li>
            </ul>

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
                
                <div class="auto-status-note mt-2">
                    <i class="fas fa-robot"></i>
                    <span class="warning-text"><strong>Tự động:</strong> Sản phẩm hết hàng (tồn kho = 0) sẽ tự động ẩn khỏi menu. Khi nhập hàng mới, sản phẩm sẽ tự động hiển thị lại.</span>
                </div>
            </div>

            <div class="filter-section">
                <h3><i class="fas fa-filter me-2"></i>Bộ lọc tồn kho</h3>
                <form id="stock-filter-form">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="product-search" class="form-label">Tìm kiếm sản phẩm</label>
                            <select class="form-select" id="product-search" name="product_id" style="width: 100%;">
                                <option value="">-- Tìm kiếm và chọn sản phẩm --</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="max-stock" class="form-label">Lọc tồn kho ≤</label>
                            <input type="number" class="form-control" id="max-stock" name="max-stock" placeholder="Nhập số lượng tối đa">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="stock-status" class="form-label">Lọc theo trạng thái</label>
                            <select class="form-select" id="stock-status" name="stock_status">
                                <option value="all">Tất cả</option>
                                <option value="sufficient">Đủ hàng</option>
                                <option value="low">Sắp hết hàng</option>
                                <option value="out">Hết hàng</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary-custom w-100"><i class="fas fa-search me-2"></i>Tìm kiếm</button>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <div class="date-filter-row">
                                <div class="date-filter-group">
                                    <i class="fas fa-calendar-alt text-muted"></i>
                                    <label>Tra cứu theo ngày:</label>
                                    <input type="text" id="stock-date" placeholder="Chọn ngày" autocomplete="off">
                                </div>
                                <button type="reset" class="btn btn-outline-secondary"><i class="fas fa-undo me-2"></i>Đặt lại</button>
                            </div>
                            <div class="date-range-info" id="date-range-info">
                                <i class="fas fa-info-circle"></i>
                                <span>Khoảng ngày tra cứu: <strong id="min-date-display"><?php echo date('d/m/Y', strtotime($first_import_date)); ?></strong> → <strong id="max-date-display"><?php echo date('d/m/Y', strtotime($current_date)); ?></strong></span>
                            </div>
                            <div class="current-date-info" id="current-date-info">
                                <i class="fas fa-calendar-day me-1"></i>
                                <span>Ngày hiện tại trên máy chủ: <strong><?php echo date('d/m/Y', strtotime($current_date)); ?></strong></span>
                            </div>
                            <small class="text-muted mt-2 d-block" id="date-hint">
                                <i class="fas fa-info-circle me-1"></i>Để trống để xem tồn kho hiện tại. Chọn ngày trong khoảng cho phép để xem tồn kho tại thời điểm đó.
                            </small>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card card-custom">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-boxes me-2"></i>Danh sách tồn kho
                        <span id="stock-date-badge" class="stock-date-badge ms-2" style="display: none;"></span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th class="text-center">Mã SP</th>
                                    <th>Tên sản phẩm</th>
                                    <th>Loại</th>
                                    <th class="text-center">Số lượng tồn</th>
                                    <th>Cảnh báo</th>
                                    <th class="text-center">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="stock-table-body">
                                <tr><td colspan="6" class="text-center">Đang tải...</td></tr>
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

    <!-- Modal cập nhật ngưỡng -->
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
                            Lưu ý: Sản phẩm có số lượng tồn = 0 sẽ tự động ẩn khỏi menu. Khi nhập hàng mới, sản phẩm sẽ tự động hiển thị lại.
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/vn.js"></script>
    <script>
        let currentPage = 1;
        let currentFilters = { product_id: '', search: '', max_stock: '', stock_status: 'all', stock_date: '' };
        let currentThreshold = <?php echo $low_stock_threshold; ?>;
        let selectedProductId = null;
        let isPastDate = false;
        
        let minDate = '<?php echo $first_import_date; ?>';
        let maxDate = '<?php echo $current_date; ?>';
        let currentDate = '<?php echo $current_date; ?>';

        const datePicker = flatpickr("#stock-date", {
            dateFormat: "Y-m-d",
            locale: "vn",
            allowInput: false,
            disableMobile: true,
            minDate: minDate,
            maxDate: maxDate,
            onChange: function(selectedDates, dateStr, instance) {
                if (dateStr) {
                    currentFilters.stock_date = dateStr;
                    if (dateStr < currentDate) {
                        isPastDate = true;
                    } else {
                        isPastDate = false;
                    }
                } else {
                    currentFilters.stock_date = '';
                    isPastDate = false;
                }
                currentPage = 1;
                loadStock();
            }
        });
        
        function formatDateDisplay(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split('-');
            if (parts.length === 3) {
                return `${parts[2]}/${parts[1]}/${parts[0]}`;
            }
            return dateStr;
        }
        
        $('#min-date-display').text(formatDateDisplay(minDate));
        $('#max-date-display').text(formatDateDisplay(maxDate));
        
        $.getJSON('inventory.php', { action: 'get_date_range' }, function(response) {
            if (response.success) {
                minDate = response.min_date;
                maxDate = response.max_date;
                $('#min-date-display').text(formatDateDisplay(minDate));
                $('#max-date-display').text(formatDateDisplay(maxDate));
                datePicker.set('minDate', minDate);
                datePicker.set('maxDate', maxDate);
            }
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

        $('#btnUpdateThreshold').click(function() {
            $('#thresholdModal').modal('show');
        });

        $('#product-search').select2({
            theme: 'bootstrap-5',
            placeholder: '-- Tìm kiếm và chọn sản phẩm --',
            allowClear: true,
            ajax: {
                url: 'inventory.php',
                dataType: 'json',
                delay: 300,
                data: function(params) {
                    return {
                        action: 'search_products',
                        search: params.term || '',
                        page: params.page || 1,
                        pageSize: 10
                    };
                },
                processResults: function(data, params) {
                    if (!data.success) {
                        return { results: [] };
                    }
                    params.page = params.page || 1;
                    return {
                        results: data.results.map(function(product) {
                            let stockStatus = '';
                            if (product.stock_quantity <= 0) {
                                stockStatus = ' <span class="text-danger">(Hết hàng)</span>';
                            } else if (product.stock_quantity <= currentThreshold) {
                                stockStatus = ' <span class="text-warning">(Sắp hết)</span>';
                            }
                            return {
                                id: product.id,
                                text: product.name + ' (' + product.code + ')' + stockStatus,
                                name: product.name,
                                code: product.code,
                                stock: product.stock_quantity,
                                status: product.status
                            };
                        }),
                        pagination: {
                            more: data.pagination && data.pagination.more
                        }
                    };
                },
                cache: true
            },
            templateResult: formatProductResult,
            templateSelection: formatProductSelection,
            minimumInputLength: 0
        });

        function formatProductResult(product) {
            if (product.loading) return product.text;
            var $container = $('<div class="product-option"><div><span class="product-option-name">' + product.name + '</span><span class="product-option-code">(' + product.code + ')</span></div></div>');
            if (product.stock <= 0) {
                $container.append('<div class="text-danger small">Hết hàng</div>');
            } else if (product.stock <= currentThreshold) {
                $container.append('<div class="text-warning small">Sắp hết hàng (còn ' + product.stock + ')</div>');
            }
            return $container;
        }
        
        function formatProductSelection(product) {
            if (!product.id) return product.text;
            return product.name + ' (' + product.code + ')';
        }

        $('#product-search').on('select2:select', function(e) {
            selectedProductId = e.params.data.id;
            currentFilters.product_id = selectedProductId;
        });
        
        $('#product-search').on('select2:clear', function(e) {
            selectedProductId = null;
            currentFilters.product_id = '';
        });

        function loadStock() {
            const params = {
                action: 'list',
                page: currentPage,
                search: currentFilters.search,
                selected_product_id: currentFilters.product_id || null,
                max_stock: currentFilters.max_stock !== '' ? currentFilters.max_stock : null,
                stock_status: currentFilters.stock_status,
                stock_date: currentFilters.stock_date
            };
            
            $('#stock-table-body').html('<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Đang tải...</td></tr>');
            
            $.getJSON('inventory.php', params, function(response) {
                if (response.success) {
                    if (response.threshold) {
                        currentThreshold = response.threshold;
                        $('#threshold-display').text('≤ ' + currentThreshold);
                    }
                    isPastDate = response.is_past_date || false;
                    
                    if (response.date_range) {
                        minDate = response.date_range.min;
                        maxDate = response.date_range.max;
                        $('#min-date-display').text(formatDateDisplay(minDate));
                        $('#max-date-display').text(formatDateDisplay(maxDate));
                        datePicker.set('minDate', minDate);
                        datePicker.set('maxDate', maxDate);
                    }
                    
                    if (response.stock_date && response.stock_date !== '') {
                        $('#stock-date-badge').html(`<i class="fas fa-calendar-alt me-1"></i>Tồn tại ngày: ${formatDateDisplay(response.stock_date)}`).show();
                    } else {
                        $('#stock-date-badge').hide();
                    }
                    
                    renderStockTable(response.data);
                    renderPagination(response.pagination);
                } else {
                    $('#stock-table-body').html('<tr><td colspan="6" class="text-center text-danger">Lỗi: ' + (response.error || 'Không thể tải dữ liệu') + '</td></tr>');
                }
            }).fail(function() {
                $('#stock-table-body').html('<tr><td colspan="6" class="text-center text-danger">Không thể kết nối đến máy chủ</td></tr>');
            });
        }

        function getStockStatus(stock, productStatus = 'active') {
            if (productStatus === 'inactive') {
                return { text: 'Đã ẩn', class: 'status-inactive', icon: 'fa-eye-slash' };
            }
            if (stock <= 0) {
                return { text: 'Hết hàng', class: 'status-danger', icon: 'fa-times-circle' };
            } else if (stock <= currentThreshold) {
                return { text: 'Sắp hết hàng', class: 'status-warning', icon: 'fa-exclamation-triangle' };
            } else {
                return { text: 'Đủ hàng', class: 'status-success', icon: 'fa-check-circle' };
            }
        }

        function getWarningMessage(stock, productStatus) {
            if (isPastDate) {
                return '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>Chỉ xem tồn kho</span>';
            }
            if (productStatus === 'inactive') {
                if (stock <= 0) {
                    return '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Sản phẩm đã hết hàng và tự động ẩn. Vui lòng nhập kho để hiển thị lại.</span>';
                }
                return '<span class="text-muted"><i class="fas fa-eye-slash me-1"></i>Sản phẩm đã bị ẩn thủ công</span>';
            }
            if (stock <= 0) {
                return '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Sản phẩm đã hết hàng! Sẽ tự động ẩn. Vui lòng nhập kho để hiển thị lại.</span>';
            } else if (stock <= currentThreshold) {
                return '<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Cần nhập hàng ngay! Tồn kho chỉ còn ' + stock + ' sản phẩm.</span>';
            }
            return '—';
        }

        function getActionButtons(product) {
            if (isPastDate) return '<div class="action-buttons">—</div>';
            const stock = product.stock_quantity;
            const productStatus = product.status || 'active';
            
            if (productStatus === 'inactive') {
                return `<div class="action-buttons">
                            <form method="POST" style="display: inline-block;" onsubmit="return confirm('Bạn có chắc muốn hiển thị lại sản phẩm này?');">
                                <input type="hidden" name="show_product" value="1">
                                <input type="hidden" name="product_id" value="${product.id}">
                                <button type="submit" class="btn-show"><i class="fas fa-eye me-1"></i>Hiển thị</button>
                            </form>
                        </div>`;
            }
            return '<div class="action-buttons">—</div>';
        }

        function renderStockTable(products) {
            const tbody = $('#stock-table-body');
            if (!products.length) {
                tbody.html('<tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-box-open me-2"></i>Không tìm thấy sản phẩm nào</td></tr>');
                return;
            }
            let html = '';
            products.forEach(p => {
                const status = getStockStatus(p.stock_quantity, p.status);
                const warningMsg = getWarningMessage(p.stock_quantity, p.status);
                const actionBtns = getActionButtons(p);
                const rowClass = p.status === 'inactive' ? '' : (p.stock_quantity <= 0 ? 'out-stock' : (p.stock_quantity <= currentThreshold ? 'low-stock' : ''));
                
                html += `<tr class="${rowClass}">
                            <td class="text-center align-middle">${escapeHtml(p.code)}</td>
                            <td class="align-middle">
                                <a href="#" class="product-link view-detail" data-id="${p.id}">${escapeHtml(p.name)}</a>
                                ${p.status === 'inactive' ? '<span class="status-badge inactive ms-2"><i class="fas fa-eye-slash"></i> Đã ẩn</span>' : ''}
                            </td>
                            <td class="align-middle">${escapeHtml(p.category_name || '')}</td>
                            <td class="text-center align-middle"><strong>${p.stock_quantity}</strong></td>
                            <td class="align-middle">${warningMsg}</td>
                            <td class="text-center align-middle">${actionBtns}</td>
                         </tr>`;
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
            for (let i = 1; i <= pagination.total_pages; i++) {
                html += `<li class="page-item ${i === pagination.current_page ? 'active' : ''}">
                            <a class="page-link" href="#" data-page="${i}">${i}</a>
                         </li>`;
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
            currentFilters.max_stock = $('#max-stock').val();
            currentFilters.stock_status = $('#stock-status').val();
            if (selectedProductId) {
                currentFilters.product_id = selectedProductId;
            } else {
                currentFilters.product_id = '';
            }
            currentPage = 1;
            loadStock();
        });
        
        $('#stock-filter-form button[type="reset"]').click(function() {
            $('#max-stock').val('');
            $('#stock-status').val('all');
            datePicker.clear();
            currentFilters.stock_date = '';
            currentFilters.max_stock = '';
            currentFilters.stock_status = 'all';
            isPastDate = false;
            selectedProductId = null;
            currentFilters.product_id = '';
            $('#product-search').val(null).trigger('change');
            currentPage = 1;
            loadStock();
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
                        <div class="alert alert-info">
                            <i class="fas fa-robot me-2"></i>
                            <strong>Lưu ý:</strong> Sản phẩm sẽ tự động ẩn khỏi menu khi tồn kho = 0 và tự động hiển thị lại khi được nhập hàng.
                        </div>
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

        loadStock();
    </script>
</body>
</html>