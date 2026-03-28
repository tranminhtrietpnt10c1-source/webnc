<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: user/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$order_success = false;
$order_code = '';

// Fetch user information
try {
    $stmt = $pdo->prepare("SELECT id, full_name, phone, address, birthday, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user has complete information
    if (empty($user['address']) || empty($user['birthday'])) {
        $_SESSION['warning'] = 'Vui lòng cập nhật đầy đủ thông tin cá nhân (địa chỉ và ngày sinh) trước khi đặt hàng.';
        header('Location: user/profile.php');
        exit;
    }
} catch (PDOException $e) {
    $error = "Lỗi khi tải thông tin người dùng";
}

// Lấy địa chỉ tạm từ giỏ hàng (nếu có)
$tempAddress = isset($_SESSION['temp_shipping_address']) ? $_SESSION['temp_shipping_address'] : null;

// Get cart from session
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit;
}

$cart_items = $_SESSION['cart'];

// Calculate cart totals
$subtotal = 0;
foreach ($cart_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping_fee = 30000;
$total = $subtotal + $shipping_fee;

// Get cart count
$cart_count = array_sum(array_column($cart_items, 'quantity'));

// Lấy thông tin user cho dropdown
$user_info = null;
try {
    $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Bỏ qua
}

// Handle order confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_order'])) {
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $notes = $_POST['order_notes'] ?? '';
    $addr_option = $_POST['addr_option'] ?? 'account';
    
    // Xử lý chọn địa chỉ
    if ($addr_option === 'temp' && $tempAddress && !empty($tempAddress['address'])) {
        $customer_name = $tempAddress['full_name'];
        $customer_phone = $tempAddress['phone'];
        $customer_address = $tempAddress['address'] . ', ' . $tempAddress['ward'] . ', ' . $tempAddress['district'] . ', ' . $tempAddress['city'];
        if (!empty($tempAddress['notes'])) {
            $notes = $tempAddress['notes'] . "\n" . $notes;
        }
        $selected_address_type = 'temp';
        $selected_address_data = $tempAddress;
    } else {
        $customer_name = $user['full_name'];
        $customer_phone = $user['phone'];
        $customer_address = $user['address'];
        $selected_address_type = 'account';
        $selected_address_data = [
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'address' => $user['address']
        ];
    }
    
    // Map payment method to database values
    $payment_method_map = [
        'cash' => 'cash',
        'bank_transfer' => 'bank_transfer',
        'online' => 'zalopay'
    ];
    $db_payment_method = $payment_method_map[$payment_method] ?? 'cash';
    
    try {
        // Generate order code
        $order_code = 'ORD' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $pdo->beginTransaction();
        
        // Set order status to 'pending' (chờ xử lý)
        $order_status = 'pending';
        
        // Insert order with status 'pending'
        $stmt = $pdo->prepare("INSERT INTO orders (
            order_code, 
            user_id, 
            customer_name, 
            customer_phone, 
            customer_email, 
            customer_address, 
            order_date, 
            total_amount, 
            shipping_fee, 
            discount, 
            final_amount, 
            status, 
            payment_method, 
            notes,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        $stmt->execute([
            $order_code,
            $user_id,
            $customer_name,
            $customer_phone,
            $user['email'] ?? '',
            $customer_address,
            $subtotal,
            $shipping_fee,
            0, // discount
            $total,
            $order_status,
            $db_payment_method,
            $notes
        ]);
        
        $order_id = $pdo->lastInsertId();
        
        // Insert order details
        $stmt = $pdo->prepare("INSERT INTO order_details (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        foreach ($cart_items as $item) {
            $subtotal_item = $item['price'] * $item['quantity'];
            $stmt->execute([
                $order_id, 
                $item['id'], 
                $item['quantity'], 
                $item['price'],
                $subtotal_item
            ]);
        }
        
        $pdo->commit();
        
        // Clear cart
        $_SESSION['cart'] = [];
        
        // ========== LƯU ĐỊA CHỈ ĐÃ CHỌN VÀO SESSION ==========
        if ($selected_address_type === 'temp') {
            $_SESSION['used_shipping_address'] = [
                'type' => 'temp',
                'full_name' => $selected_address_data['full_name'],
                'phone' => $selected_address_data['phone'],
                'address' => $selected_address_data['address'] . ', ' . $selected_address_data['ward'] . ', ' . $selected_address_data['district'] . ', ' . $selected_address_data['city'],
                'notes' => $selected_address_data['notes'] ?? ''
            ];
        } else {
            $_SESSION['used_shipping_address'] = [
                'type' => 'account',
                'full_name' => $selected_address_data['full_name'],
                'phone' => $selected_address_data['phone'],
                'address' => $selected_address_data['address'],
                'notes' => ''
            ];
        }
        
        // Xóa địa chỉ tạm sau khi đã lưu
        unset($_SESSION['temp_shipping_address']);
        // ========== KẾT THÚC ==========
        
        // Log successful order creation
        error_log("Order created successfully - Order Code: $order_code, User ID: $user_id, Status: $order_status");
        
        // Redirect to order_success.php
        header('Location: order_success.php?code=' . urlencode($order_code));
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Lỗi khi đặt hàng: " . $e->getMessage();
        error_log("Order creation failed - User ID: $user_id, Error: " . $e->getMessage());
    }
}

// Define status labels for display
$status_labels = [
    'pending' => 'Chờ xử lý',
    'processing' => 'Đang xử lý',
    'shipped' => 'Đã giao hàng',
    'delivered' => 'Đã nhận hàng',
    'cancelled' => 'Đã hủy',
    'transfer' => 'Đang chuyển khoản'
];

// Define status colors for display
$status_colors = [
    'pending' => '#ffc107',
    'processing' => '#17a2b8',
    'shipped' => '#28a745',
    'delivered' => '#28a745',
    'cancelled' => '#dc3545',
    'transfer' => '#fd7e14'
];
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Thanh Toán - Feane</title>
    
    <!-- Bootstrap core CSS -->
    <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
    <!-- Font Awesome -->
    <link href="css/font-awesome.min.css" rel="stylesheet" />
    <!-- Custom styles -->
    <link href="css/style.css" rel="stylesheet" />
    <link href="css/responsive.css" rel="stylesheet" />
    
    <style>
        .checkout_section {
            padding: 50px 0;
        }
        .order-summary {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 30px;
            background: #fff;
        }
        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .payment-method {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 30px;
            background: #fff;
        }
        .payment-option {
            margin-bottom: 15px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .payment-option:hover {
            border-color: #ffbe33;
        }
        .payment-option.active {
            border-color: #ffbe33;
            background-color: #fff9f0;
        }
        .payment-details {
            margin-top: 15px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
            display: none;
        }
        .address-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
            border: 1px solid #ddd;
        }
        .address-option {
            background: #fff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            cursor: pointer;
            transition: all 0.3s;
        }
        .address-option:hover {
            border-color: #ffbe33;
        }
        .address-option.active {
            border-color: #ffbe33;
            background-color: #fff9f0;
        }
        .btn-primary {
            background-color: #ffbe33;
            border-color: #ffbe33;
            color: #fff;
        }
        .btn-primary:hover {
            background-color: #e69c00;
            border-color: #e69c00;
        }
        .btn-outline-primary {
            border-color: #ffbe33;
            color: #ffbe33;
        }
        .btn-outline-primary:hover {
            background-color: #ffbe33;
            border-color: #ffbe33;
            color: #fff;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .cart-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: red;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cart_link {
            position: relative;
        }
        .payment-icons img {
            margin-right: 10px;
        }
        
        /* User dropdown styles */
        .user-dropdown {
            position: relative;
            display: inline-block;
        }
        .user-dropdown-btn {
            background: none;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 10px;
            border-radius: 30px;
            transition: all 0.3s;
        }
        .user-dropdown-btn:hover {
            background-color: rgba(255,255,255,0.1);
        }
        .user-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #ffbe33;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #222;
            font-weight: bold;
            font-size: 14px;
        }
        .user-name {
            color: white;
            font-size: 14px;
            font-weight: 500;
        }
        .dropdown-menu-custom {
            position: absolute;
            top: 45px;
            right: 0;
            background: white;
            min-width: 280px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            display: none;
            animation: fadeIn 0.2s ease;
        }
        .dropdown-menu-custom.show {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dropdown-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .dropdown-header-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: #ffbe33;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            color: #222;
        }
        .dropdown-header-info h6 {
            margin: 0;
            font-weight: 600;
            color: #333;
        }
        .dropdown-header-info p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }
        .dropdown-item-custom {
            padding: 12px 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #333;
            text-decoration: none;
            transition: background 0.2s;
        }
        .dropdown-item-custom:hover {
            background-color: #f5f5f5;
            color: #333;
            text-decoration: none;
        }
        .dropdown-item-custom i {
            width: 20px;
            color: #ffbe33;
        }
        .dropdown-divider {
            height: 1px;
            background-color: #eee;
            margin: 5px 0;
        }
        .text-danger {
            color: #dc3545 !important;
        }
        .text-danger:hover {
            background-color: #fff5f5 !important;
        }
        
        /* Status badge styles */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-align: center;
        }
        
        .temp-address-badge {
            background: #ffbe33;
            color: #222;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 8px;
            display: inline-block;
        }
        
        @media (max-width: 768px) {
            .user-name {
                display: none;
            }
            .dropdown-menu-custom {
                right: -50px;
            }
        }
    </style>
</head>
<body class="sub_page">

<div class="hero_area">
    <div class="bg-box">
        <img src="images/hero-bg.jpg" alt="">
    </div>
    <!-- header section strats -->
    <header class="header_section">
        <div class="container">
            <nav class="navbar navbar-expand-lg custom_nav-container">
                <a class="navbar-brand" href="index.php">
                    <span>Feane</span>
                </a>

                <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                    <span class=""> </span>
                </button>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav mx-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="menu.php">Menu</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="about.php">About</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="order_history.php">Order History</a>
                        </li>
                    </ul>
                    <div class="user_option">
                        <?php if (isset($_SESSION['user_id']) && $user_info): ?>
                            <div class="user-dropdown">
                                <button class="user-dropdown-btn" id="userDropdownBtn">
                                    <div class="user-icon">
                                        <?= strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)) ?>
                                    </div>
                                    <span class="user-name">
                                        <?= htmlspecialchars($user_info['full_name'] ?: $user_info['username']) ?>
                                    </span>
                                    <i class="fa fa-chevron-down" style="color: white; font-size: 12px;"></i>
                                </button>
                                <div class="dropdown-menu-custom" id="userDropdownMenu">
                                    <div class="dropdown-header">
                                        <div class="dropdown-header-icon">
                                            <?= strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)) ?>
                                        </div>
                                        <div class="dropdown-header-info">
                                            <h6><?= htmlspecialchars($user_info['full_name'] ?: $user_info['username']) ?></h6>
                                            <p><?= htmlspecialchars($user_info['email']) ?></p>
                                        </div>
                                    </div>
                                    <a href="user/profile.php" class="dropdown-item-custom">
                                        <i class="fa fa-user"></i>
                                        <span>Thông tin tài khoản</span>
                                    </a>
                                    <a href="order_history.php" class="dropdown-item-custom">
                                        <i class="fa fa-shopping-bag"></i>
                                        <span>Lịch sử đơn hàng</span>
                                    </a>
                                    <a href="cart.php" class="dropdown-item-custom">
                                        <i class="fa fa-shopping-cart"></i>
                                        <span>Giỏ hàng của tôi</span>
                                        <?php if ($cart_count > 0): ?>
                                            <span class="badge" style="background: #ffbe33; color: #222; margin-left: auto;"><?= $cart_count ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a href="user/logout.php" class="dropdown-item-custom text-danger">
                                        <i class="fa fa-sign-out-alt"></i>
                                        <span>Đăng xuất</span>
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="user/login.php" class="user_link">
                                <i class="fa fa-user" aria-hidden="true"></i>
                            </a>
                        <?php endif; ?>
                        
                        <a class="cart_link" href="cart.php">
                            <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029">
                                <g>
                                    <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/>
                                    <path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/>
                                    <path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/>
                                </g>
                            </svg>
                            <?php if ($cart_count > 0): ?>
                                <span class="cart-count"><?php echo $cart_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <form class="form-inline">
                            <a href="search.php" class="btn my-2 my-sm-0 nav_search-btn">
                                <i class="fa fa-search" aria-hidden="true"></i>
                            </a>
                        </form>
                        <div class="order_online">
                            <a href="user/logout.php" style="color: white;">
                                Đăng xuất
                            </a>
                        </div>
                    </div>
                </div>
            </nav>
        </div>
    </header>
    <!-- end header section -->
</div>

<!-- checkout section -->
<section class="checkout_section layout_padding">
    <div class="container">
        <div class="heading_container heading_center">
            <h2>
                Thanh Toán
            </h2>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="checkoutForm">
            <input type="hidden" name="payment_method" id="selected_payment_method" value="cash">
            <input type="hidden" name="order_notes" id="selected_order_notes" value="">
            <input type="hidden" name="addr_option" id="selected_addr_option" value="account">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Thông tin giao hàng -->
                    <div class="address-info">
                        <h4><i class="fa fa-truck"></i> Chọn địa chỉ giao hàng</h4>
                        
                        <!-- Địa chỉ từ tài khoản -->
                        <div class="address-option active" id="addr-account" data-value="account">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="addr_option_radio" id="addr_account" value="account" checked>
                                <label class="form-check-label" for="addr_account">
                                    <strong><i class="fa fa-user-circle"></i> Địa chỉ tài khoản</strong>
                                </label>
                            </div>
                            <div class="mt-2 pl-4">
                                <p class="mb-1"><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></p>
                                <p class="mb-1"><?php echo htmlspecialchars($user['address']); ?></p>
                                <p class="mb-0">Điện thoại: <?php echo htmlspecialchars($user['phone']); ?></p>
                            </div>
                        </div>
                        
                        <!-- Địa chỉ từ giỏ hàng (nếu có) -->
                        <?php if ($tempAddress && !empty($tempAddress['address'])): ?>
                        <div class="address-option" id="addr-temp" data-value="temp">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="addr_option_radio" id="addr_temp" value="temp">
                                <label class="form-check-label" for="addr_temp">
                                    <strong><i class="fa fa-truck"></i> Địa chỉ từ giỏ hàng</strong>
                                    <span class="temp-address-badge">Tạm thời</span>
                                </label>
                            </div>
                            <div class="mt-2 pl-4">
                                <p class="mb-1"><strong><?php echo htmlspecialchars($tempAddress['full_name']); ?></strong></p>
                                <p class="mb-1"><?php echo htmlspecialchars($tempAddress['address']); ?>, <?php echo htmlspecialchars($tempAddress['ward']); ?>, <?php echo htmlspecialchars($tempAddress['district']); ?>, <?php echo htmlspecialchars($tempAddress['city']); ?></p>
                                <p class="mb-0">Điện thoại: <?php echo htmlspecialchars($tempAddress['phone']); ?></p>
                                <?php if (!empty($tempAddress['notes'])): ?>
                                    <p class="mb-0 text-muted small mt-1"><i class="fa fa-info-circle"></i> Ghi chú: <?php echo htmlspecialchars($tempAddress['notes']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <a href="user/profile.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fa fa-edit"></i> Cập nhật địa chỉ tài khoản
                            </a>
                            <a href="cart.php" class="btn btn-outline-secondary btn-sm ml-2">
                                <i class="fa fa-shopping-cart"></i> Cập nhật địa chỉ giỏ hàng
                            </a>
                        </div>
                    </div>

                    <!-- Phương thức thanh toán -->
                    <div class="payment-method">
                        <h4><i class="fa fa-credit-card"></i> Phương thức thanh toán</h4>
                        
                        <div class="payment-option active" id="cash-option" data-method="cash">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method_radio" id="cash" value="cash" checked>
                                <label class="form-check-label" for="cash">
                                    <strong>💵 Thanh toán tiền mặt khi nhận hàng</strong>
                                </label>
                            </div>
                            <p class="mb-0 mt-2">Bạn sẽ thanh toán bằng tiền mặt khi nhận được hàng</p>
                        </div>
                        
                        <div class="payment-option" id="transfer-option" data-method="bank_transfer">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method_radio" id="transfer" value="bank_transfer">
                                <label class="form-check-label" for="transfer">
                                    <strong>🏦 Chuyển khoản ngân hàng</strong>
                                </label>
                            </div>
                            <div class="payment-details" id="transfer-details">
                                <p>Vui lòng chuyển khoản đến tài khoản sau:</p>
                                <p><strong>Ngân hàng: ABC Bank</strong></p>
                                <p><strong>Số tài khoản: 123456789</strong></p>
                                <p><strong>Chủ tài khoản: CÔNG TY TNHH FEANE</strong></p>
                                <p><strong>Nội dung chuyển khoản: Mã đơn hàng + Số điện thoại</strong></p>
                                <p>Sau khi chuyển khoản, vui lòng gửi xác nhận đến email: thanhtoan@feane.com</p>
                            </div>
                        </div>
                        
                        <div class="payment-option" id="online-option" data-method="online">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method_radio" id="online" value="online">
                                <label class="form-check-label" for="online">
                                    <strong>💻 Thanh toán trực tuyến</strong>
                                </label>
                            </div>
                            <div class="payment-details" id="online-details">
                                <p>Bạn sẽ được chuyển hướng đến cổng thanh toán an toàn</p>
                                <div class="payment-icons">
                                    <img src="https://cdn-icons-png.flaticon.com/128/179/179457.png" width="40" class="mr-2" alt="PayPal">
                                    <img src="https://cdn-icons-png.flaticon.com/128/196/196578.png" width="40" class="mr-2" alt="Visa">
                                    <img src="https://cdn-icons-png.flaticon.com/128/196/196561.png" width="40" class="mr-2" alt="Mastercard">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ghi chú đơn hàng -->
                    <div class="form-group">
                        <label for="orderNotes"><strong><i class="fa fa-comment"></i> Ghi chú đơn hàng (tùy chọn)</strong></label>
                        <textarea class="form-control" id="orderNotes" name="order_notes_input" rows="3" placeholder="Ghi chú về đơn hàng của bạn..."></textarea>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <!-- Tóm tắt đơn hàng -->
                    <div class="order-summary">
                        <h4><i class="fa fa-shopping-cart"></i> Tóm tắt đơn hàng</h4>
                        <div id="order-items-container">
                            <?php foreach ($cart_items as $item): ?>
                            <div class="order-item">
                                <div>
                                    <p class="mb-1"><strong><?php echo htmlspecialchars($item['name']); ?></strong></p>
                                    <p class="mb-0 text-muted">Số lượng: <?php echo $item['quantity']; ?></p>
                                </div>
                                <span><?php echo number_format($item['price'] * $item['quantity'], 0, ',', '.'); ?>đ</span>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="order-item">
                                <div>Tạm tính</div>
                                <span><?php echo number_format($subtotal, 0, ',', '.'); ?>đ</span>
                            </div>
                            
                            <div class="order-item">
                                <div>Phí vận chuyển</div>
                                <span><?php echo number_format($shipping_fee, 0, ',', '.'); ?>đ</span>
                            </div>
                            
                            <div class="order-item" style="border-bottom: none;">
                                <div><strong>Tổng cộng</strong></div>
                                <span><strong><?php echo number_format($total, 0, ',', '.'); ?>đ</strong></span>
                            </div>
                        </div>
                        
                        <div class="mt-3 p-2 bg-light rounded">
                            <small class="text-muted">
                                <i class="fa fa-info-circle"></i> 
                                Trạng thái đơn hàng: <span class="status-badge" style="background-color: #ffc107; color: #000;">Chờ xử lý</span>
                            </small>
                            <p class="small text-muted mt-1 mb-0">
                                <i class="fa fa-clock-o"></i> Đơn hàng của bạn đang được xử lý, vui lòng chờ xác nhận từ nhà hàng
                            </p>
                        </div>
                        
                        <button type="submit" name="confirm_order" class="btn btn-primary btn-block mt-3" onclick="return confirm('Xác nhận đặt hàng?')">
                            <i class="fa fa-check-circle"></i> Xác nhận thanh toán
                        </button>
                        <a href="cart.php" class="btn btn-outline-secondary btn-block mt-2">
                            <i class="fa fa-arrow-left"></i> Quay lại giỏ hàng
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>
<!-- end checkout section -->

<!-- footer section -->
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
                    <p>Necessary, making this the first true generator on the Internet. It uses a dictionary of over 200 Latin words, combined with</p>
                </div>
            </div>
            <div class="col-md-4 footer-col">
                <h4>Opening Hours</h4>
                <p>Everyday</p>
                <p>10.00 Am -10.00 Pm</p>
            </div>
        </div>
        <div class="footer-info">
            <p>&copy; <span id="displayYear"></span> All Rights Reserved By <a href="https://html.design/">Free Html Templates</a></p>
        </div>
    </div>
</footer>
<!-- footer section -->

<!-- jQery -->
<script src="js/jquery-3.4.1.min.js"></script>
<!-- popper js -->
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
<!-- bootstrap js -->
<script src="js/bootstrap.js"></script>
<!-- custom js -->
<script src="js/custom.js"></script>

<script>
    // User Dropdown functionality
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
    
    // Xử lý chọn địa chỉ
    document.querySelectorAll('.address-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.address-option').forEach(opt => {
                opt.classList.remove('active');
            });
            this.classList.add('active');
            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                document.getElementById('selected_addr_option').value = radio.value;
            }
        });
    });
    
    // Xử lý chọn phương thức thanh toán
    document.querySelectorAll('input[name="payment_method_radio"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.payment-details').forEach(detail => {
                detail.style.display = 'none';
            });
            document.querySelectorAll('.payment-option').forEach(option => {
                option.classList.remove('active');
            });
            
            if (this.id === 'transfer') {
                document.getElementById('transfer-details').style.display = 'block';
                document.getElementById('transfer-option').classList.add('active');
            } else if (this.id === 'online') {
                document.getElementById('online-details').style.display = 'block';
                document.getElementById('online-option').classList.add('active');
            } else {
                document.getElementById('cash-option').classList.add('active');
            }
            
            document.getElementById('selected_payment_method').value = this.value;
        });
    });
    
    // Xử lý ghi chú đơn hàng
    const orderNotes = document.getElementById('orderNotes');
    if (orderNotes) {
        orderNotes.addEventListener('input', function() {
            document.getElementById('selected_order_notes').value = this.value;
        });
    }
    
    // Click vào payment-option để chọn radio
    document.querySelectorAll('.payment-option').forEach(option => {
        option.addEventListener('click', function() {
            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                const event = new Event('change');
                radio.dispatchEvent(event);
            }
        });
    });
    
    // Display current year
    document.getElementById('displayYear').innerHTML = new Date().getFullYear();
</script>

</body>
</html>