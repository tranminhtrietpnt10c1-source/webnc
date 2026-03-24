<?php
session_start();
require_once 'includes/db_connection.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: user/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Kiểm tra có order_id không
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id <= 0) {
    header('Location: order_history.php');
    exit;
}

// Lấy thông tin đơn hàng
$order_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM order_details WHERE order_id = o.id) as item_count
        FROM orders o
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$order_id, $user_id]);
    $order_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Nếu không tìm thấy đơn hàng, chuyển hướng về lịch sử
    if (!$order_info) {
        header('Location: order_history.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: order_history.php');
    exit;
}

// Lấy chi tiết sản phẩm trong đơn hàng
$order_details = [];
try {
    $stmt = $pdo->prepare("
        SELECT od.*, p.name as product_name, p.image as product_image
        FROM order_details od
        LEFT JOIN products p ON od.product_id = p.id
        WHERE od.order_id = ?
        ORDER BY od.id ASC
    ");
    $stmt->execute([$order_id]);
    $order_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Bỏ qua
}

// Lấy thông tin user
$user_info = null;
try {
    $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Bỏ qua
}

// Hàm format tiền
function formatCurrency($amount) {
    return number_format($amount, 0, ',', '.') . ' VND';
}

function formatDate($date) {
    if (empty($date)) return '';
    return date('d/m/Y H:i', strtotime($date));
}

function getStatusInfo($status) {
    $statuses = [
        'pending' => ['class' => 'processing', 'text' => 'Chờ xử lý'],
        'processing' => ['class' => 'processing', 'text' => 'Đang xử lý'],
        'shipped' => ['class' => 'processing', 'text' => 'Đang giao hàng'],
        'delivered' => ['class' => 'delivered', 'text' => 'Đã giao'],
        'cancelled' => ['class' => 'cancelled', 'text' => 'Đã hủy'],
        'new' => ['class' => 'processing', 'text' => 'Mới'],
        'cash' => ['class' => 'processing', 'text' => 'Chờ thanh toán'],
        'bank_transfer' => ['class' => 'processing', 'text' => 'Chờ xác nhận']
    ];
    return $statuses[$status] ?? ['class' => 'processing', 'text' => ucfirst($status)];
}

function getPaymentMethodText($method) {
    $methods = [
        'cash' => 'Tiền mặt khi nhận hàng',
        'bank_transfer' => 'Chuyển khoản ngân hàng',
        'online' => 'Thanh toán trực tuyến',
        'momo' => 'Ví MoMo',
        'zalopay' => 'ZaloPay'
    ];
    return $methods[$method] ?? $method;
}

// Lấy số lượng giỏ hàng
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}

$status_info = getStatusInfo($order_info['status']);
$final_amount = $order_info['final_amount'] ?? ($order_info['total_amount'] + ($order_info['shipping_fee'] ?? 0));
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết đơn hàng #<?= htmlspecialchars($order_info['order_code']) ?> - Feane</title>
    <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
    <link href="css/font-awesome.min.css" rel="stylesheet" />
    <link href="css/style.css" rel="stylesheet" />
    <link href="css/responsive.css" rel="stylesheet" />
    <style>
        body {
            background: #f8f9fa;
        }
        
        .order-detail-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-top: 30px;
        }
        
        .order-header {
            background: linear-gradient(135deg, #ffbe33 0%, #e69c29 100%);
            color: white;
            padding: 25px 30px;
        }
        
        .order-header h3 {
            margin: 0;
            font-weight: bold;
        }
        
        .order-header p {
            margin: 5px 0 0;
            opacity: 0.9;
        }
        
        .order-status {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid #eee;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            display: inline-block;
        }
        
        .status-badge.processing {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-badge.delivered {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        
        .order-info-section {
            padding: 25px 30px;
            border-bottom: 1px solid #eee;
        }
        
        .info-title {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ffbe33;
            display: inline-block;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
            margin-bottom: 5px;
            font-size: 13px;
            text-transform: uppercase;
        }
        
        .info-value {
            color: #333;
            font-size: 16px;
        }
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .product-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #ffbe33;
        }
        
        .product-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        
        .product-table tr:hover {
            background: #f9f9f9;
        }
        
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .product-name {
            font-weight: 500;
            color: #333;
        }
        
        .summary-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .summary-row.total {
            border-bottom: none;
            font-size: 18px;
            font-weight: bold;
            color: #ffbe33;
            padding-top: 15px;
        }
        
        .btn-back {
            background-color: #ffbe33;
            color: white;
            padding: 10px 25px;
            border-radius: 30px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-back:hover {
            background-color: #e69c29;
            color: white;
            text-decoration: none;
        }
        
        .btn-print {
            background: transparent;
            border: 2px solid #ffbe33;
            color: #ffbe33;
            padding: 10px 25px;
            border-radius: 30px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-print:hover {
            background: #ffbe33;
            color: white;
            text-decoration: none;
        }
        
        .tracking-timeline {
            margin-top: 20px;
        }
        
        .timeline-item {
            display: flex;
            margin-bottom: 20px;
            position: relative;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            background: #f0f0f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            z-index: 1;
        }
        
        .timeline-icon.active {
            background: #ffbe33;
            color: white;
        }
        
        .timeline-icon.completed {
            background: #28a745;
            color: white;
        }
        
        .timeline-content {
            flex: 1;
            padding-bottom: 20px;
            border-left: 2px dashed #e0e0e0;
            padding-left: 20px;
        }
        
        .timeline-item:last-child .timeline-content {
            border-left: none;
        }
        
        .timeline-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .timeline-date {
            font-size: 12px;
            color: #999;
        }
        
        /* User dropdown styles - giống order_history */
        .user-dropdown { position: relative; display: inline-block; }
        .user-dropdown-btn { background: none; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; padding: 5px 10px; border-radius: 30px; }
        .user-icon { width: 32px; height: 32px; border-radius: 50%; background-color: #ffbe33; display: flex; align-items: center; justify-content: center; color: #222; font-weight: bold; }
        .user-name { color: white; font-size: 14px; font-weight: 500; }
        .dropdown-menu-custom { position: absolute; top: 45px; right: 0; background: white; min-width: 280px; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); z-index: 1000; display: none; }
        .dropdown-menu-custom.show { display: block; }
        .dropdown-header { padding: 15px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 12px; }
        .dropdown-header-icon { width: 45px; height: 45px; border-radius: 50%; background-color: #ffbe33; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; }
        .dropdown-header-info h6 { margin: 0; font-weight: 600; }
        .dropdown-header-info p { margin: 0; font-size: 12px; color: #666; }
        .dropdown-item-custom { padding: 12px 15px; display: flex; align-items: center; gap: 12px; color: #333; text-decoration: none; }
        .dropdown-item-custom:hover { background-color: #f5f5f5; text-decoration: none; color: #333; }
        .dropdown-item-custom i { width: 20px; color: #ffbe33; }
        .dropdown-divider { height: 1px; background-color: #eee; margin: 5px 0; }
        .cart-count { position: absolute; top: -8px; right: -8px; background: red; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 12px; display: flex; align-items: center; justify-content: center; }
        .cart_link { position: relative; }
        
        @media (max-width: 768px) {
            .order-header, .order-status, .order-info-section {
                padding: 20px;
            }
            .product-table th, .product-table td {
                padding: 10px;
                font-size: 14px;
            }
            .product-image {
                width: 40px;
                height: 40px;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media print {
            .header_section, .footer_section, .btn-back, .btn-print, .user_option {
                display: none !important;
            }
            .order-detail-container {
                box-shadow: none;
                margin: 0;
            }
            body {
                background: white;
                padding: 20px;
            }
        }
    </style>
</head>
<body class="sub_page">

<div class="hero_area">
    <div class="bg-box"><img src="images/hero-bg.jpg" alt=""></div>
    <header class="header_section">
        <div class="container">
            <nav class="navbar navbar-expand-lg custom_nav-container">
                <a class="navbar-brand" href="index.php"><span>Feane</span></a>
                <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent">
                    <span class=""> </span>
                </button>
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav mx-auto">
                        <li class="nav-item"><a class="nav-link" href="index.php">HOME</a></li>
                        <li class="nav-item"><a class="nav-link" href="menu.php">MENU</a></li>
                        <li class="nav-item"><a class="nav-link" href="about.php">ABOUT</a></li>
                        <li class="nav-item active"><a class="nav-link" href="order_history.php">ORDER HISTORY</a></li>
                    </ul>
                    <div class="user_option">
                        <?php if (isset($_SESSION['user_id']) && $user_info): ?>
                            <div class="user-dropdown">
                                <button class="user-dropdown-btn" id="userDropdownBtn">
                                    <div class="user-icon"><?= strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)) ?></div>
                                    <span class="user-name"><?= htmlspecialchars($user_info['full_name'] ?: $user_info['username']) ?></span>
                                    <i class="fa fa-chevron-down" style="color: white; font-size: 12px;"></i>
                                </button>
                                <div class="dropdown-menu-custom" id="userDropdownMenu">
                                    <div class="dropdown-header">
                                        <div class="dropdown-header-icon"><?= strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)) ?></div>
                                        <div class="dropdown-header-info">
                                            <h6><?= htmlspecialchars($user_info['full_name'] ?: $user_info['username']) ?></h6>
                                            <p><?= htmlspecialchars($user_info['email']) ?></p>
                                        </div>
                                    </div>
                                    <a href="user/profile.php" class="dropdown-item-custom"><i class="fa fa-user"></i><span>Thông tin tài khoản</span></a>
                                    <a href="order_history.php" class="dropdown-item-custom"><i class="fa fa-shopping-bag"></i><span>Lịch sử đơn hàng</span></a>
                                    <a href="cart.php" class="dropdown-item-custom"><i class="fa fa-shopping-cart"></i><span>Giỏ hàng</span></a>
                                    <div class="dropdown-divider"></div>
                                    <a href="user/logout.php" class="dropdown-item-custom text-danger"><i class="fa fa-sign-out-alt"></i><span>Đăng xuất</span></a>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="user/login.php" class="user_link"><i class="fa fa-user"></i></a>
                        <?php endif; ?>
                        <a class="cart_link" href="cart.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029" width="20">
                                <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/>
                                <path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/>
                                <path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/>
                            </svg>
                            <?php if ($cart_count > 0): ?>
                                <span class="cart-count"><?= $cart_count ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="search.php" class="btn nav_search-btn"><i class="fa fa-search"></i></a>
                        <div class="order_online"><a href="user/logout.php" style="color: white;">Đăng xuất</a></div>
                    </div>
                </div>
            </nav>
        </div>
    </header>
</div>

<section class="food_section layout_padding">
    <div class="container">
        <div class="order-detail-container">
            <!-- Header -->
            <div class="order-header">
                <h3>
                    <i class="fa fa-shopping-bag"></i> 
                    Đơn hàng #<?= htmlspecialchars($order_info['order_code']) ?>
                </h3>
                <p>Ngày đặt: <?= formatDate($order_info['order_date'] ?? $order_info['created_at']) ?></p>
            </div>
            
            <!-- Status -->
            <div class="order-status">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <span class="status <?= $status_info['class'] ?>">
                            <?= $status_info['text'] ?>
                        </span>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <a href="order_history.php" class="btn-view" style="background-color: #ffbe33; color: white; padding: 6px 15px; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fa fa-arrow-left"></i> Quay lại
                        </a>
                        <button onclick="window.print()" class="btn-view" style="background: transparent; border: 1px solid #ffbe33; color: #ffbe33; padding: 6px 15px; border-radius: 4px; cursor: pointer;">
                            <i class="fa fa-print"></i> In đơn
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Thông tin giao hàng -->
            <div class="order-info-section">
                <h4 class="info-title">
                    <i class="fa fa-truck"></i> Thông tin giao hàng
                </h4>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Người nhận</div>
                        <div class="info-value"><?= htmlspecialchars($order_info['customer_name'] ?? $user_info['full_name']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Số điện thoại</div>
                        <div class="info-value"><?= htmlspecialchars($order_info['customer_phone'] ?? '') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($user_info['email'] ?? '') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Địa chỉ giao hàng</div>
                        <div class="info-value"><?= htmlspecialchars($order_info['customer_address'] ?? '') ?></div>
                    </div>
                    <?php if (!empty($order_info['notes'])): ?>
                    <div class="info-item">
                        <div class="info-label">Ghi chú</div>
                        <div class="info-value"><?= htmlspecialchars($order_info['notes']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-label">Phương thức thanh toán</div>
                        <div class="info-value"><?= getPaymentMethodText($order_info['payment_method']) ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Chi tiết sản phẩm -->
            <div class="order-info-section">
                <h4 class="info-title">
                    <i class="fa fa-cutlery"></i> Chi tiết đơn hàng
                </h4>
                
                <div class="table-responsive">
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th>Đơn giá</th>
                                <th>Số lượng</th>
                                <th>Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order_details as $item): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($item['product_image'])): ?>
                                            <img src="<?= htmlspecialchars($item['product_image']) ?>" class="product-image mr-3" alt="<?= htmlspecialchars($item['product_name']) ?>">
                                        <?php endif; ?>
                                        <span class="product-name"><?= htmlspecialchars($item['product_name']) ?></span>
                                    </div>
                                </td>
                                <td><?= formatCurrency($item['unit_price'] ?? $item['price']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><?= formatCurrency($item['subtotal']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Tổng kết -->
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Tạm tính</span>
                        <span><?= formatCurrency($order_info['total_amount']) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Phí vận chuyển</span>
                        <span><?= formatCurrency($order_info['shipping_fee'] ?? 0) ?></span>
                    </div>
                    <?php if (($order_info['discount'] ?? 0) > 0): ?>
                    <div class="summary-row">
                        <span>Giảm giá</span>
                        <span>- <?= formatCurrency($order_info['discount']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="summary-row total">
                        <span>Tổng cộng</span>
                        <span><?= formatCurrency($final_amount) ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <a href="menu.php" class="btn-view" style="background-color: #ffbe33; color: white; padding: 10px 25px; border-radius: 5px; text-decoration: none; display: inline-block;">
                <i class="fa fa-shopping-bag"></i> Tiếp tục mua sắm
            </a>
        </div>
    </div>
</section>

<footer class="footer_section">
    <div class="container">
        <div class="row">
            <div class="col-md-4 footer-col">
                <div class="footer_contact">
                    <h4>Contact Us</h4>
                    <div class="contact_link_box">
                        <a href=""><i class="fa fa-map-marker"></i><span>Location</span></a>
                        <a href=""><i class="fa fa-phone"></i><span>Call +01 1234567890</span></a>
                        <a href=""><i class="fa fa-envelope"></i><span>demo@gmail.com</span></a>
                    </div>
                </div>
            </div>
            <div class="col-md-4 footer-col">
                <div class="footer_detail">
                    <a href="" class="footer-logo">Feane</a>
                    <p>Necessary, making this the first true generator on the Internet.</p>
                    <div class="footer_social">
                        <a href=""><i class="fa fa-facebook"></i></a>
                        <a href=""><i class="fa fa-twitter"></i></a>
                        <a href=""><i class="fa fa-linkedin"></i></a>
                        <a href=""><i class="fa fa-instagram"></i></a>
                    </div>
                </div>
            </div>
            <div class="col-md-4 footer-col">
                <h4>Opening Hours</h4>
                <p>Everyday</p>
                <p>10.00 Am -10.00 Pm</p>
            </div>
        </div>
        <div class="footer-info">
            <p>&copy; <span id="displayYear"></span> Feane Restaurant. All Rights Reserved.</p>
        </div>
    </div>
</footer>

<script src="js/jquery-3.4.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
<script src="js/bootstrap.js"></script>
<script src="js/custom.js"></script>

<script>
    var dropdownBtn = document.getElementById('userDropdownBtn');
    var dropdownMenu = document.getElementById('userDropdownMenu');
    if (dropdownBtn && dropdownMenu) {
        dropdownBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdownMenu.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                dropdownMenu.classList.remove('show');
            }
        });
    }
    document.getElementById('displayYear').innerHTML = new Date().getFullYear();
</script>

</body>
</html>