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

            case 'get_import_transactions':
                $product_id = (int)($_GET['product_id'] ?? 0);
                $date_from = $_GET['date_from'] ?? '';
                $date_to = $_GET['date_to'] ?? '';

                if (!$product_id) throw new Exception('Vui lòng chọn sản phẩm');

                $stmt = $pdo->prepare("SELECT id, name FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if (!$product) throw new Exception('Sản phẩm không tồn tại');

                // Import transactions
                $importSql = "SELECT i.import_date, d.quantity, d.unit_cost, d.subtotal, i.invoice_number, s.name as supplier_name
                              FROM import_details d
                              JOIN imports i ON d.import_id = i.id
                              LEFT JOIN suppliers s ON i.supplier_id = s.id
                              WHERE d.product_id = :product_id AND i.status = 'completed'
                              AND (:date_from = '' OR i.import_date >= :date_from)
                              AND (:date_to = '' OR i.import_date <= :date_to)
                              ORDER BY i.import_date DESC";
                $importStmt = $pdo->prepare($importSql);
                $importStmt->execute([':product_id' => $product_id, ':date_from' => $date_from, ':date_to' => $date_to]);
                $imports = $importStmt->fetchAll();

                $total_import = array_sum(array_column($imports, 'quantity'));
                $total_value = array_sum(array_column($imports, 'subtotal'));

                echo json_encode([
                    'success' => true,
                    'product' => $product,
                    'transactions' => $imports,
                    'total_import' => $total_import,
                    'total_value' => $total_value
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
    <title>Tra cứu nhập kho - Feane Restaurant</title>
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
        .detail-search-result {
            margin-top: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        .transaction-table th {
            background-color: #e9ecef;
        }
        .format-money {
            font-family: monospace;
            font-weight: 500;
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

            <h2 class="mb-4">Tra cứu nhập kho</h2>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs stock-tabs" id="stockTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory.php">Danh sách tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory_detail.php">Tra cứu tồn kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" href="inventory_import.php">Tra cứu nhập kho</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="inventory_export.php">Tra cứu xuất kho</a>
                </li>
            </ul>

            <!-- Tra cứu nhập kho -->
            <div class="search-section">
                <h5><i class="fas fa-arrow-down me-2"></i>Tra cứu nhập kho</h5>
                <form id="import-search-form">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="import-product" class="form-label">Chọn sản phẩm</label>
                            <select class="form-select" id="import-product" name="import-product">
                                <option value="">-- Chọn sản phẩm --</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="import-date-from" class="form-label">Từ ngày</label>
                            <input type="date" class="form-control" id="import-date-from" name="import-date-from">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="import-date-to" class="form-label">Đến ngày</label>
                            <input type="date" class="form-control" id="import-date-to" name="import-date-to">
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-dark w-100"><i class="fas fa-search me-2"></i>Tra cứu</button>
                        </div>
                    </div>
                </form>
                <div id="import-search-result" class="detail-search-result" style="display: none;">
                    <h5>Kết quả tra cứu nhập kho</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="summary-card import">
                                <i class="fas fa-arrow-down fa-2x"></i>
                                <h4 id="total-import">0</h4>
                                <p>Tổng số lượng nhập</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="summary-card import" style="background-color: #28a745;">
                                <i class="fas fa-dollar-sign fa-2x"></i>
                                <h4 id="total-value">0</h4>
                                <p>Tổng giá trị nhập</p>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered transaction-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Ngày nhập</th>
                                    <th>Hóa đơn</th>
                                    <th>Nhà cung cấp</th>
                                    <th>Số lượng</th>
                                    <th>Đơn giá</th>
                                    <th>Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody id="import-result-body"></tbody>
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
            $.getJSON('inventory_import.php', { action: 'get_products' }, function(products) {
                let options = '<option value="">-- Chọn sản phẩm --</option>';
                products.forEach(p => options += `<option value="${p.id}">${p.name}</option>`);
                $('#import-product').html(options);
            });
        }

        function formatMoney(amount) {
            return new Intl.NumberFormat('vi-VN').format(amount) + ' ₫';
        }

        $('#import-search-form').submit(function(e) {
            e.preventDefault();
            const productId = $('#import-product').val();
            const dateFrom = $('#import-date-from').val();
            const dateTo = $('#import-date-to').val();
            if (!productId) { alert('Vui lòng chọn sản phẩm'); return; }
            
            $.getJSON('inventory_import.php', { action: 'get_import_transactions', product_id: productId, date_from: dateFrom, date_to: dateTo }, function(res) {
                if (res.success) {
                    $('#total-import').text(res.total_import);
                    $('#total-value').text(formatMoney(res.total_value));
                    const tbody = $('#import-result-body');
                    tbody.empty();
                    if (res.transactions.length === 0) {
                        tbody.html('应用<td colspan="6" class="text-center">Không có giao dịch nhập kho</td>应用');
                    } else {
                        res.transactions.forEach(t => {
                            tbody.append(`
                                <tr>
                                    <td>${new Date(t.import_date).toLocaleDateString('vi-VN')}</td>
                                    <td>${t.invoice_number || '---'}</td>
                                    <td>${t.supplier_name || '---'}</td>
                                    <td class="text-center">${t.quantity}</td>
                                    <td class="text-end">${formatMoney(t.unit_cost)}</td>
                                    <td class="text-end">${formatMoney(t.subtotal)}</td>
                                </tr>
                            `);
                        });
                    }
                    $('#import-search-result').show();
                } else {
                    alert('Lỗi: ' + res.error);
                }
            });
        });

        const today = new Date();
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(today.getDate() - 30);
        const formatDate = (date) => date.toISOString().slice(0,10);
        $('#import-date-from').val(formatDate(thirtyDaysAgo));
        $('#import-date-to').val(formatDate(today));

        loadProductSelect();
    </script>
</body>
</html>