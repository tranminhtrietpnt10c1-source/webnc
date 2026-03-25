<?php
session_start();
require_once 'includes/db_connection.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: user/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Kiểm tra có mã đơn hàng không
$order_code = isset($_GET['code']) ? $_GET['code'] : null;

if (!$order_code) {
    header('Location: order_history.php');
    exit;
}

// Lấy thông tin đơn hàng từ database
$order_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM order_details WHERE order_id = o.id) as item_count
        FROM orders o
        WHERE o.order_code = ? AND o.user_id = ?
    ");
    $stmt->execute([$order_code, $user_id]);
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

// Lấy thông tin user
$user_info = null;
try {
    $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Bỏ qua
}

// Lấy số lượng giỏ hàng
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}

function formatCurrency($amount) {
    return number_format($amount, 0, ',', '.') . ' VND';
}

function getStatusInfo($status) {
    $statuses = [
        'pending' => ['class' => 'processing', 'text' => 'Chờ xử lý'],
        'processing' => ['class' => 'processing', 'text' => 'Đang xử lý'],
        'shipped' => ['class' => 'processing', 'text' => 'Đang giao hàng'],
        'delivered' => ['class' => 'delivered', 'text' => 'Đã giao'],
        'cancelled' => ['class' => 'cancelled', 'text' => 'Đã hủy']
    ];
    return $statuses[$status] ?? ['class' => 'processing', 'text' => 'Chờ xử lý'];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt hàng thành công - Feane</title>
    <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
    <link href="css/font-awesome.min.css" rel="stylesheet" />
    <link href="css/style.css" rel="stylesheet" />
    <link href="css/responsive.css" rel="stylesheet" />
    <style>
        body {
            background: #f8f9fa;
        }
        
        .success-container {
            max-width: 700px;
            margin: 50px auto;
            text-align: center;
            background: white;
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0,0,0,0.1);
            padding: 40px;
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: #28a745;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .success-icon i {
            font-size: 40px;
            color: white;
        }
        
        .order-info-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 25px 0;
            text-align: left;
        }
        
        .order-info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .order-info-row:last-child {
            border-bottom: none;
        }
        
        .order-info-label {
            font-weight: 600;
            color: #555;
        }
        
        .order-info-value {
            color: #333;
        }
        
        .order-code {
            background: #fff3cd;
            padding: 12px;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            color: #856404;
            margin: 15px 0;
            text-align: center;
        }
        
        .btn-custom {
            background-color: #ffbe33;
            color: #222;
            padding: 12px 25px;
            border-radius: 30px;
            text-decoration: none;
            display: inline-block;
            margin: 10px;
            font-weight: bold;
            transition: all 0.3s;
            border: none;
        }
        
        .btn-custom:hover {
            background-color: #e69c29;
            color: #222;
            text-decoration: none;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid #ffbe33;
            color: #ffbe33;
        }
        
        .btn-outline:hover {
            background: #ffbe33;
            color: #222;
        }
        
        h2 {
            color: #28a745;
            margin-bottom: 20px;
        }
        
        .status {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            display: inline-block;
        }
        
        .status.delivered {
            background: #d4edda;
            color: #155724;
        }
        
        .status.processing {
            background: #fff3cd;
            color: #856404;
        }
        
        .status.cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* User dropdown styles */
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
            .success-container { margin: 20px; padding: 25px; }
            .btn-custom { padding: 10px 20px; margin: 5px; font-size: 14px; }
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
        <div class="success-container">
            <div class="success-icon">
                <i class="fa fa-check"></i>
            </div>
            <h2>Đặt hàng thành công!</h2>
            <p>Cảm ơn bạn <strong><?= htmlspecialchars($user_info['full_name'] ?: $user_info['username']) ?></strong> đã đặt hàng tại <strong>Feane</strong></p>
            <p>Chúng tôi đã nhận được đơn hàng của bạn và sẽ xử lý trong thời gian sớm nhất.</p>
            
            <div class="order-code">
                <i class="fa fa-ticket"></i> Mã đơn hàng: <strong><?= htmlspecialchars($order_info['order_code']) ?></strong>
            </div>
            
            <div class="order-info-box">
                <div class="order-info-row">
                    <span class="order-info-label"><i class="fa fa-calendar"></i> Ngày đặt:</span>
                    <span class="order-info-value"><?= date('d/m/Y H:i', strtotime($order_info['order_date'] ?? $order_info['created_at'])) ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><i class="fa fa-user"></i> Người nhận:</span>
                    <span class="order-info-value"><?= htmlspecialchars($order_info['customer_name'] ?? $user_info['full_name']) ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><i class="fa fa-phone"></i> Số điện thoại:</span>
                    <span class="order-info-value"><?= htmlspecialchars($order_info['customer_phone'] ?? '') ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><i class="fa fa-map-marker"></i> Địa chỉ:</span>
                    <span class="order-info-value"><?= htmlspecialchars($order_info['customer_address'] ?? '') ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><i class="fa fa-shopping-bag"></i> Tổng tiền hàng:</span>
                    <span class="order-info-value"><?= formatCurrency($order_info['total_amount']) ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><i class="fa fa-truck"></i> Phí vận chuyển:</span>
                    <span class="order-info-value"><?= formatCurrency($order_info['shipping_fee'] ?? 0) ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><strong>Thành tiền:</strong></span>
                    <span class="order-info-value"><strong><?= formatCurrency($order_info['final_amount'] ?? ($order_info['total_amount'] + ($order_info['shipping_fee'] ?? 0))) ?></strong></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label"><i class="fa fa-info-circle"></i> Trạng thái:</span>
                    <span class="order-info-value">
                        <span class="status <?= getStatusInfo($order_info['status'])['class'] ?>">
                            <?= getStatusInfo($order_info['status'])['text'] ?>
                        </span>
                    </span>
                </div>
            </div>
            
            <p class="text-muted">
                <i class="fa fa-envelope"></i> Chi tiết đơn hàng đã được gửi đến email: <strong><?= htmlspecialchars($user_info['email']) ?></strong>
            </p>
            
            <div>
                <a href="order_detail.php?id=<?= $order_info['id'] ?>" class="btn-custom">
                    <i class="fa fa-eye"></i> Xem chi tiết đơn hàng
                </a>
                <a href="order_history.php" class="btn-custom btn-outline">
                    <i class="fa fa-list"></i> Lịch sử đơn hàng
                </a>
                <a href="menu.php" class="btn-custom btn-outline">
                    <i class="fa fa-shopping-bag"></i> Tiếp tục mua sắm
                </a>
            </div>
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
    <div><i class="fa fa-map-marker"></i><span>Location</span></div>
    <div><i class="fa fa-phone"></i><span>Call +01 1234567890</span></div>
    <div><i class="fa fa-envelope"></i><span>demo@gmail.com</span></div>
</div>
                </div>
            </div>
            <div class="col-md-4 footer-col">
                <div class="footer_detail">
                    <a href="" class="footer-logo">Feane</a>
                    <p>Necessary, making this the first true generator on the Internet.</p>
                   
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