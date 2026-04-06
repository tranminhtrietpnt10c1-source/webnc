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

            case 'get_in_out_transactions':
                $product_id = (int)($_GET['product_id'] ?? 0);
                $date_from = $_GET['date_from'] ?? '';
                $date_to = $_GET['date_to'] ?? '';

                if (!$product_id) throw new Exception('Vui lòng chọn sản phẩm');
                if (!$date_from || !$date_to) throw new Exception('Vui lòng chọn khoảng thời gian');

                // Kiểm tra sản phẩm tồn tại
                $stmt = $pdo->prepare("SELECT id, name FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Sản phẩm không tồn tại');

                // Lấy giao dịch NHẬP kho - chỉ lấy status = 'completed'
                $importSql = "SELECT 
                                i.id as transaction_id,
                                i.import_code as code,
                                i.import_date as transaction_date,
                                i.supplier as supplier_name,
                                i.total_amount as total_amount,
                                i.status,
                                i.notes,
                                d.quantity,
                                d.unit_cost,
                                d.subtotal,
                                'import' as type,
                                i.import_code as display_code
                              FROM import_details d
                              JOIN imports i ON d.import_id = i.id
                              WHERE d.product_id = :product_id 
                                AND i.status = 'completed'
                                AND i.import_date BETWEEN :date_from AND :date_to
                              ORDER BY i.import_date DESC, i.id DESC";
                $importStmt = $pdo->prepare($importSql);
                $importStmt->execute([
                    ':product_id' => $product_id,
                    ':date_from' => $date_from,
                    ':date_to' => $date_to
                ]);
                $imports = $importStmt->fetchAll();

                // Lấy giao dịch XUẤT kho - chỉ lấy status = 'shipped' (đã giao)
                $exportSql = "SELECT 
                                o.id as transaction_id,
                                o.order_code as code,
                                o.order_date as transaction_date,
                                o.customer_name as customer_name,
                                o.customer_phone as customer_phone,
                                o.final_amount as total_amount,
                                o.status as order_status,
                                o.notes,
                                od.quantity,
                                od.unit_price as unit_price,
                                od.subtotal,
                                'export' as type,
                                o.order_code as display_code
                              FROM order_details od
                              JOIN orders o ON od.order_id = o.id
                              WHERE od.product_id = :product_id 
                                AND o.status = 'shipped'
                                AND o.order_date BETWEEN :date_from AND :date_to
                              ORDER BY o.order_date DESC, o.id DESC";
                $exportStmt = $pdo->prepare($exportSql);
                $exportStmt->execute([
                    ':product_id' => $product_id,
                    ':date_from' => $date_from,
                    ':date_to' => $date_to
                ]);
                $exports = $exportStmt->fetchAll();

                // Gộp và sắp xếp theo ngày
                $allTransactions = array_merge($imports, $exports);
                usort($allTransactions, function($a, $b) {
                    return strtotime($b['transaction_date']) - strtotime($a['transaction_date']);
                });

                // Tính tổng số lượng nhập và xuất
                $total_import_qty = array_sum(array_column($imports, 'quantity'));
                $total_import_value = array_sum(array_column($imports, 'subtotal'));
                $total_export_qty = array_sum(array_column($exports, 'quantity'));
                $total_export_value = array_sum(array_column($exports, 'subtotal'));

                echo json_encode([
                    'success' => true,
                    'product' => $product,
                    'transactions' => $allTransactions,
                    'imports' => $imports,
                    'exports' => $exports,
                    'summary' => [
                        'import_qty' => $total_import_qty,
                        'import_value' => $total_import_value,
                        'export_qty' => $total_export_qty,
                        'export_value' => $total_export_value
                    ]
                ]);
                break;

            case 'get_import_detail':
                $import_id = (int)($_GET['import_id'] ?? 0);
                if (!$import_id) throw new Exception('Không tìm thấy phiếu nhập');

                $sql = "SELECT i.*, 
                               u.full_name as created_by_name
                        FROM imports i
                        LEFT JOIN users u ON i.created_by = u.id
                        WHERE i.id = :import_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':import_id' => $import_id]);
                $import = $stmt->fetch();
                
                if (!$import) throw new Exception('Phiếu nhập không tồn tại');

                // Lấy chi tiết sản phẩm trong phiếu nhập
                $detailSql = "SELECT d.*, p.name as product_name, p.code as product_code
                              FROM import_details d
                              JOIN products p ON d.product_id = p.id
                              WHERE d.import_id = :import_id";
                $detailStmt = $pdo->prepare($detailSql);
                $detailStmt->execute([':import_id' => $import_id]);
                $details = $detailStmt->fetchAll();

                echo json_encode([
                    'success' => true,
                    'import' => $import,
                    'details' => $details
                ]);
                break;

            case 'get_order_detail':
                $order_id = (int)($_GET['order_id'] ?? 0);
                if (!$order_id) throw new Exception('Không tìm thấy đơn hàng');

                $sql = "SELECT o.*, u.full_name as user_name
                        FROM orders o
                        LEFT JOIN users u ON o.user_id = u.id
                        WHERE o.id = :order_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':order_id' => $order_id]);
                $order = $stmt->fetch();
                
                if (!$order) throw new Exception('Đơn hàng không tồn tại');

                // Lấy chi tiết sản phẩm trong đơn hàng
                $detailSql = "SELECT od.*, p.name as product_name, p.code as product_code
                              FROM order_details od
                              JOIN products p ON od.product_id = p.id
                              WHERE od.order_id = :order_id";
                $detailStmt = $pdo->prepare($detailSql);
                $detailStmt->execute([':order_id' => $order_id]);
                $details = $detailStmt->fetchAll();

                echo json_encode([
                    'success' => true,
                    'order' => $order,
                    'details' => $details
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
    <title>Tra cứu nhập - xuất kho - Feane Restaurant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
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
        .summary-card {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            color: white;
            margin-bottom: 15px;
        }
        .summary-card.import {
            background-color: #17a2b8;
        }
        .summary-card.export {
            background-color: #fd7e14;
        }
        .detail-search-result {
            margin-top: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        .transaction-table {
            width: 100%;
            white-space: nowrap;
        }
        .transaction-table th, 
        .transaction-table td {
            vertical-align: middle;
            white-space: nowrap;
            padding: 12px 8px;
        }
        .transaction-table th {
            background-color: #e9ecef;
            font-weight: 600;
        }
        .format-money {
            font-family: monospace;
            font-weight: 500;
        }
        .badge-import {
            background-color: #17a2b8;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .badge-export {
            background-color: #fd7e14;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .code-link {
            color: #007bff;
            text-decoration: none;
            cursor: pointer;
            font-weight: 500;
            white-space: nowrap;
        }
        .code-link:hover {
            text-decoration: underline;
        }
        .modal-lg-custom {
            max-width: 900px;
        }
        .detail-table th {
            background-color: #f8f9fa;
            width: 180px;
        }
        .transaction-row:hover {
            background-color: #f8f9fa;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .text-center-col {
            text-align: center;
        }
        .text-end-col {
            text-align: right;
        }
        @media (max-width: 1200px) {
            .transaction-table {
                white-space: normal;
            }
            .transaction-table th, 
            .transaction-table td {
                white-space: normal;
            }
        }
        /* Select2 custom styling */
        .select2-container--bootstrap-5 .select2-selection {
            min-height: 38px;
            border-radius: 0.375rem;
        }
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
        }
        .select2-container--bootstrap-5 .select2-dropdown {
            border-color: #ced4da;
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

            <h2 class="mb-4">Tra cứu nhập - xuất kho</h2>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs stock-tabs" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory.php">Danh sách tồn kho</a>
                </li>
                
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" href="import_export.php">Tra cứu nhập - xuất kho</a>
                </li>
                
            </ul>

            <!-- Tra cứu nhập - xuất kho -->
            <div class="search-section">
                <h5><i class="fas fa-exchange-alt me-2"></i>Tra cứu nhập - xuất kho</h5>
                <form id="inout-search-form">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="inout-product" class="form-label">Chọn sản phẩm <span class="text-danger">*</span></label>
                            <select class="form-select" id="inout-product" name="inout-product" style="width: 100%;" required>
                                <option value="">-- Chọn sản phẩm --</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="inout-date-from" class="form-label">Từ ngày <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="inout-date-from" name="inout-date-from" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="inout-date-to" class="form-label">Đến ngày <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="inout-date-to" name="inout-date-to" required>
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-dark w-100"><i class="fas fa-search me-2"></i>Tra cứu</button>
                        </div>
                    </div>
                </form>
                <div id="inout-search-result" class="detail-search-result" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Kết quả tra cứu: <span id="selected-product-name"></span></h5>
                        <button class="btn btn-sm btn-outline-secondary" id="clear-result"><i class="fas fa-times"></i> Xóa kết quả</button>
                    </div>
                    
                    <!-- Thống kê tổng hợp -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="summary-card import">
                                <i class="fas fa-arrow-down fa-2x"></i>
                                <h4 id="total-import-qty">0</h4>
                                <p>Tổng số lượng nhập</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-card import" style="background-color: #28a745;">
                                <i class="fas fa-dollar-sign fa-2x"></i>
                                <h4 id="total-import-value">0</h4>
                                <p>Tổng giá trị nhập</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-card export">
                                <i class="fas fa-arrow-up fa-2x"></i>
                                <h4 id="total-export-qty">0</h4>
                                <p>Tổng số lượng xuất</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-card export" style="background-color: #dc3545;">
                                <i class="fas fa-dollar-sign fa-2x"></i>
                                <h4 id="total-export-value">0</h4>
                                <p>Tổng giá trị xuất</p>
                            </div>
                        </div>
                    </div>

                    <!-- Danh sách giao dịch -->
                    <div class="table-responsive">
                        <table class="table table-bordered transaction-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 100px">Ngày giao dịch</th>
                                    <th style="width: 100px">Loại</th>
                                    <th style="width: 140px">Mã đơn</th>
                                    <th style="min-width: 180px">Đối tác/Khách hàng</th>
                                    <th style="width: 80px; text-align: center">Số lượng</th>
                                    <th style="width: 130px; text-align: right">Đơn giá</th>
                                    <th style="width: 150px; text-align: right">Thành tiền</th>
                                    <th style="width: 100px; text-align: center">Chi tiết</th>
                                </tr>
                            </thead>
                            <tbody id="inout-result-body"></tbody>
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

    <!-- Modal Chi tiết đơn nhập -->
    <div class="modal fade" id="importDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-lg-custom">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #17a2b8; color: white;">
                    <h5 class="modal-title"><i class="fas fa-arrow-down me-2"></i> Chi tiết phiếu nhập</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="import-detail-content">
                    <div class="text-center py-4">Đang tải...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Chi tiết đơn xuất -->
    <div class="modal fade" id="orderDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-lg-custom">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #fd7e14; color: white;">
                    <h5 class="modal-title"><i class="fas fa-arrow-up me-2"></i> Chi tiết đơn hàng</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="order-detail-content">
                    <div class="text-center py-4">Đang tải...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Loading functions
        function showLoading() {
            $('#loadingOverlay').css('display', 'flex');
        }
        function hideLoading() {
            $('#loadingOverlay').hide();
        }

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

        // Format money
        function formatMoney(amount) {
            return new Intl.NumberFormat('vi-VN').format(amount) + ' ₫';
        }

        // Khởi tạo Select2
        let productSelect = null;
        
        function initSelect2() {
            if (productSelect) {
                productSelect.destroy();
            }
            
            productSelect = $('#inout-product').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: '-- Chọn sản phẩm --',
                allowClear: true,
                language: {
                    noResults: function() {
                        return 'Không tìm thấy sản phẩm';
                    },
                    searching: function() {
                        return 'Đang tìm kiếm...';
                    }
                }
            });
        }

        // Load products for select
        function loadProductSelect() {
            $.getJSON(window.location.pathname, { action: 'get_products' }, function(products) {
                let options = '<option value="">-- Chọn sản phẩm --</option>';
                products.forEach(p => options += `<option value="${p.id}">${p.name}</option>`);
                $('#inout-product').html(options);
                
                // Khởi tạo Select2 sau khi có dữ liệu
                initSelect2();
            }).fail(function() {
                console.error('Failed to load products');
                // Vẫn khởi tạo Select2 nếu lỗi
                initSelect2();
            });
        }

        // Date handling
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);
        
        const formatDate = (date) => date.toISOString().slice(0, 10);
        
        // Set max date for "Đến ngày" = today (không được chọn tương lai)
        const maxDateTo = formatDate(today);
        
        // Set max date for "Từ ngày" = yesterday (không được chọn hôm nay)
        const maxDateFrom = formatDate(yesterday);
        
        // Set default values: from = 30 days ago, to = today
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(today.getDate() - 30);
        
        $('#inout-date-from').val(formatDate(thirtyDaysAgo));
        $('#inout-date-to').val(maxDateTo);
        
        // Set max attributes
        $('#inout-date-from').attr('max', maxDateFrom);
        $('#inout-date-to').attr('max', maxDateTo);
        
        // Date validation
        $('#inout-date-from').on('change', function() {
            const dateFrom = $(this).val();
            const dateTo = $('#inout-date-to').val();
            
            // Check if from date > yesterday
            if (dateFrom && dateFrom > maxDateFrom) {
                alert('"Từ ngày" không thể chọn ngày hôm nay hoặc tương lai. Vui lòng chọn ngày trước hôm nay.');
                $(this).val(maxDateFrom);
            }
            
            // Check if from date > to date
            const newFrom = $(this).val();
            if (newFrom && dateTo && newFrom > dateTo) {
                alert('"Từ ngày" không thể lớn hơn "Đến ngày".');
                $(this).val(dateTo);
            }
        });
        
        $('#inout-date-to').on('change', function() {
            const dateTo = $(this).val();
            const dateFrom = $('#inout-date-from').val();
            
            // Check if to date > today
            if (dateTo && dateTo > maxDateTo) {
                alert('"Đến ngày" không thể chọn ngày trong tương lai.');
                $(this).val(maxDateTo);
            }
            
            // Check if to date < from date
            const newTo = $(this).val();
            if (dateFrom && newTo && dateFrom > newTo) {
                alert('"Đến ngày" không thể nhỏ hơn "Từ ngày".');
                $(this).val(dateFrom);
            }
        });

        // Clear result
        $('#clear-result').click(function() {
            $('#inout-search-result').hide();
            // Reset Select2
            if (productSelect) {
                productSelect.val(null).trigger('change');
            } else {
                $('#inout-product').val('');
            }
            $('#inout-date-from').val(formatDate(thirtyDaysAgo));
            $('#inout-date-to').val(maxDateTo);
        });

        // Search form submit
        $('#inout-search-form').submit(function(e) {
            e.preventDefault();
            // Lấy giá trị từ Select2
            const productId = $('#inout-product').val();
            const dateFrom = $('#inout-date-from').val();
            const dateTo = $('#inout-date-to').val();
            
            if (!productId) {
                alert('Vui lòng chọn sản phẩm');
                return;
            }
            if (!dateFrom || !dateTo) {
                alert('Vui lòng chọn khoảng thời gian');
                return;
            }
            
            showLoading();
            
            $.getJSON(window.location.pathname, {
                action: 'get_in_out_transactions',
                product_id: productId,
                date_from: dateFrom,
                date_to: dateTo
            }, function(res) {
                hideLoading();
                if (res.success) {
                    $('#selected-product-name').text(res.product.name);
                    
                    // Update summary
                    $('#total-import-qty').text(res.summary.import_qty);
                    $('#total-import-value').text(formatMoney(res.summary.import_value));
                    $('#total-export-qty').text(res.summary.export_qty);
                    $('#total-export-value').text(formatMoney(res.summary.export_value));
                    
                    // Render transactions
                    const tbody = $('#inout-result-body');
                    tbody.empty();
                    
                    if (res.transactions.length === 0) {
                        tbody.html('<tr><td colspan="8" class="text-center py-4">Không có giao dịch nhập/xuất trong khoảng thời gian này</td></tr>');
                    } else {
                        res.transactions.forEach(t => {
                            const transactionDate = new Date(t.transaction_date).toLocaleDateString('vi-VN');
                            const isImport = t.type === 'import';
                            const badgeClass = isImport ? 'badge-import' : 'badge-export';
                            const badgeIcon = isImport ? '<i class="fas fa-arrow-down"></i>' : '<i class="fas fa-arrow-up"></i>';
                            const badgeText = isImport ? 'NHẬP KHO' : 'XUẤT KHO';
                            const unitPrice = isImport ? t.unit_cost : t.unit_price;
                            const customerOrSupplier = isImport ? (t.supplier_name || '---') : (t.customer_name || '---');
                            const detailLink = isImport ? 
                                `<a href="javascript:void(0)" class="code-link" onclick="viewImportDetail(${t.transaction_id})"><i class="fas fa-eye me-1"></i>Xem</a>` :
                                `<a href="javascript:void(0)" class="code-link" onclick="viewOrderDetail(${t.transaction_id})"><i class="fas fa-eye me-1"></i>Xem</a>`;
                            
                            tbody.append(`
                                <tr class="transaction-row">
                                    <td>${transactionDate}</td>
                                    <td><span class="${badgeClass}">${badgeIcon} ${badgeText}</span></td>
                                    <td><strong>${t.display_code}</strong></td>
                                    <td>${customerOrSupplier}</td>
                                    <td class="text-center">${t.quantity}</td>
                                    <td class="text-end">${formatMoney(unitPrice)}</td>
                                    <td class="text-end">${formatMoney(t.subtotal)}</td>
                                    <td class="text-center">${detailLink}</td>
                                </tr>
                            `);
                        });
                    }
                    
                    $('#inout-search-result').show();
                } else {
                    alert('Lỗi: ' + res.error);
                }
            }).fail(function() {
                hideLoading();
                alert('Có lỗi xảy ra khi kết nối server');
            });
        });

        // View import detail
        function viewImportDetail(importId) {
            showLoading();
            $.getJSON(window.location.pathname, {
                action: 'get_import_detail',
                import_id: importId
            }, function(res) {
                hideLoading();
                if (res.success) {
                    const imp = res.import;
                    const details = res.details;
                    
                    let detailHtml = `
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <strong>Thông tin phiếu nhập</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr><th style="width: 100px">Mã phiếu:</th><td><strong>${imp.import_code}</strong></td></tr>
                                            <tr><th>Ngày nhập:</th><td>${new Date(imp.import_date).toLocaleDateString('vi-VN')}</td></tr>
                                            <tr><th>Nhà cung cấp:</th><td>${imp.supplier || '---'}</td></tr>
                                            <tr><th>Trạng thái:</th><td><span class="badge bg-success">Đã hoàn thành</span></td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr><th style="width: 100px">Tổng tiền:</th><td class="text-danger fw-bold">${formatMoney(imp.total_amount)}</td></tr>
                                            <tr><th>Người tạo:</th><td>${imp.created_by_name || '---'}</td></tr>
                                            <tr><th>Ghi chú:</th><td>${imp.notes || '---'}</td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header bg-light">
                                <strong>Chi tiết sản phẩm</strong>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Mã SP</th>
                                            <th>Tên sản phẩm</th>
                                            <th class="text-center">Số lượng</th>
                                            <th class="text-end">Đơn giá</th>
                                            <th class="text-end">Thành tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    
                    details.forEach(d => {
                        detailHtml += `
                            <tr>
                                <td>${d.product_code}</td>
                                <td>${d.product_name}</td>
                                <td class="text-center">${d.quantity}</td>
                                <td class="text-end">${formatMoney(d.unit_cost)}</td>
                                <td class="text-end">${formatMoney(d.subtotal)}</td>
                            </tr>
                        `;
                    });
                    
                    detailHtml += `
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="4" class="text-end fw-bold">Tổng cộng:</td>
                                            <td class="text-end fw-bold text-danger">${formatMoney(imp.total_amount)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    `;
                    
                    $('#import-detail-content').html(detailHtml);
                    $('#importDetailModal').modal('show');
                } else {
                    alert('Lỗi: ' + res.error);
                }
            }).fail(function() {
                hideLoading();
                alert('Có lỗi xảy ra khi tải chi tiết phiếu nhập');
            });
        }
        
        // View order detail
        function viewOrderDetail(orderId) {
            showLoading();
            $.getJSON(window.location.pathname, {
                action: 'get_order_detail',
                order_id: orderId
            }, function(res) {
                hideLoading();
                if (res.success) {
                    const order = res.order;
                    const details = res.details;
                    
                    const statusMap = {
                        'pending': 'Chờ xác nhận',
                        'confirmed': 'Đã xác nhận',
                        'shipped': 'Đã giao hàng',
                        'cancelled': 'Đã hủy'
                    };
                    
                    const statusClass = {
                        'pending': 'bg-warning',
                        'confirmed': 'bg-info',
                        'shipped': 'bg-success',
                        'cancelled': 'bg-danger'
                    };
                    
                    let detailHtml = `
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <strong>Thông tin đơn hàng</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr><th style="width: 100px">Mã đơn:</th><td><strong>${order.order_code}</strong></td></tr>
                                            <tr><th>Ngày đặt:</th><td>${new Date(order.order_date).toLocaleDateString('vi-VN')}</td></tr>
                                            <tr><th>Khách hàng:</th><td>${order.customer_name || '---'}</td></tr>
                                            <tr><th>Số điện thoại:</th><td>${order.customer_phone || '---'}</td></tr>
                                            <tr><th>Email:</th><td>${order.customer_email || '---'}</td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr><th style="width: 100px">Địa chỉ:</th><td>${order.customer_address || '---'}</td></tr>
                                            <tr><th>Trạng thái:</th><td><span class="badge ${statusClass[order.status]}">${statusMap[order.status] || order.status}</span></td></tr>
                                            <tr><th>Phương thức TT:</th><td>${order.payment_method || '---'}</td></tr>
                                            <tr><th>Phí vận chuyển:</th><td>${formatMoney(order.shipping_fee)}</td></tr>
                                            <tr><th>Giảm giá:</th><td>${formatMoney(order.discount)}</td></tr>
                                        </table>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-12">
                                        <table class="table table-sm table-borderless">
                                            <tr><th style="width: 100px">Ghi chú:</th><td colspan="3">${order.notes || '---'}</td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header bg-light">
                                <strong>Chi tiết sản phẩm</strong>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Mã SP</th>
                                            <th>Tên sản phẩm</th>
                                            <th class="text-center">Số lượng</th>
                                            <th class="text-end">Đơn giá</th>
                                            <th class="text-end">Thành tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    
                    details.forEach(d => {
                        detailHtml += `
                            <tr>
                                <td>${d.product_code}</td>
                                <td>${d.product_name}</td>
                                <td class="text-center">${d.quantity}</td>
                                <td class="text-end">${formatMoney(d.unit_price)}</td>
                                <td class="text-end">${formatMoney(d.subtotal)}</td>
                            </tr>
                        `;
                    });
                    
                    detailHtml += `
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="3"></td>
                                            <td class="text-end fw-bold">Tạm tính:</td>
                                            <td class="text-end">${formatMoney(order.total_amount)}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3"></td>
                                            <td class="text-end fw-bold">Phí ship:</td>
                                            <td class="text-end">${formatMoney(order.shipping_fee)}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3"></td>
                                            <td class="text-end fw-bold">Giảm giá:</td>
                                            <td class="text-end">${formatMoney(order.discount)}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3"></td>
                                            <td class="text-end fw-bold">Tổng thanh toán:</td>
                                            <td class="text-end fw-bold text-danger">${formatMoney(order.final_amount)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    `;
                    
                    $('#order-detail-content').html(detailHtml);
                    $('#orderDetailModal').modal('show');
                } else {
                    alert('Lỗi: ' + res.error);
                }
            }).fail(function() {
                hideLoading();
                alert('Có lỗi xảy ra khi tải chi tiết đơn hàng');
            });
        }

        // Load products on page load
        loadProductSelect();
    </script>
</body>
</html>