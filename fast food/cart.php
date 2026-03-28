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

// Lấy thông tin user cho dropdown
$user_info = null;
try {
    $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Bỏ qua
}

// Initialize cart from session if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ========== XỬ LÝ AJAX ==========
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // XỬ LÝ LƯU ĐỊA CHỈ TẠM (TỪ GIỎ HÀNG)
    if ($action === 'save_address') {
        header('Content-Type: application/json');
        
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập']);
            exit;
        }
        
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $ward = trim($_POST['ward'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($full_name) || empty($phone) || empty($address) || empty($city) || empty($district) || empty($ward)) {
            echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin']);
            exit;
        }
        
        if (!preg_match('/^[0-9]{10,11}$/', $phone)) {
            echo json_encode(['success' => false, 'message' => 'Số điện thoại không hợp lệ']);
            exit;
        }
        
        $_SESSION['temp_shipping_address'] = [
            'full_name' => $full_name,
            'phone' => $phone,
            'address' => $address,
            'city' => $city,
            'district' => $district,
            'ward' => $ward,
            'notes' => $notes,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode(['success' => true, 'message' => 'Đã cập nhật địa chỉ giao hàng']);
        exit;
    }
    
    // XỬ LÝ CẬP NHẬT SỐ LƯỢNG GIỎ HÀNG
    if ($action === 'update_quantity') {
        header('Content-Type: application/json');
        $product_id = $_POST['product_id'] ?? '';
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if ($product_id && isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] = max(1, $quantity);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Sản phẩm không tồn tại']);
        }
        exit;
    }
    
    // XỬ LÝ XÓA SẢN PHẨM KHỎI GIỎ HÀNG
    if ($action === 'remove_item') {
        header('Content-Type: application/json');
        $product_id = $_POST['product_id'] ?? '';
        
        if ($product_id && isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Sản phẩm không tồn tại']);
        }
        exit;
    }
    
    // XỬ LÝ THÊM SẢN PHẨM (AJAX)
    if (isset($_POST['add_to_cart'])) {
        $product_id = $_POST['product_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $price = $_POST['price'] ?? 0;
        $image = $_POST['image'] ?? '';
        $options = $_POST['options'] ?? '';
        $quantity = max(1, intval($_POST['quantity'] ?? 1));
        
        if ($product_id && $name && $price) {
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id]['quantity'] += $quantity;
            } else {
                $_SESSION['cart'][$product_id] = [
                    'id' => $product_id,
                    'name' => $name,
                    'price' => $price,
                    'quantity' => $quantity,
                    'image' => $image,
                    'options' => $options
                ];
            }
            $cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
            echo json_encode(['success' => true, 'cart_count' => $cart_count]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Thông tin sản phẩm không hợp lệ']);
        exit;
    }
}
// ========== KẾT THÚC XỬ LÝ AJAX ==========

// Handle adding product to cart (NON-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart']) && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $product_id = $_POST['product_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $price = $_POST['price'] ?? 0;
    $image = $_POST['image'] ?? '';
    $options = $_POST['options'] ?? '';
    $quantity = max(1, intval($_POST['quantity'] ?? 1));
    
    if ($product_id && $name && $price) {
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$product_id] = [
                'id' => $product_id,
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'image' => $image,
                'options' => $options
            ];
        }
        
        $_SESSION['cart_success'] = "Đã thêm " . $name . " vào giỏ hàng!";
        $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        header('Location: ' . $referer);
        exit;
    }
}

// Handle checkout
if (isset($_GET['checkout'])) {
    if (empty($_SESSION['cart'])) {
        $_SESSION['error'] = 'Giỏ hàng trống. Vui lòng thêm sản phẩm trước khi thanh toán.';
        header('Location: cart.php');
        exit;
    }
    
    try {
        $subtotal = 0;
        foreach ($_SESSION['cart'] as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        $shipping_fee = 30000;
        $discount = 0;
        $final_amount = $subtotal + $shipping_fee - $discount;
        
        // Generate order code
        $order_code = 'ORD' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $pdo->beginTransaction();
        
        // Insert order
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
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'pending', 'cash', ?, NOW(), NOW())");
        
        $stmt->execute([
            $order_code,
            $user_id,
            $user['full_name'],
            $user['phone'],
            $user['email'] ?? '',
            $user['address'],
            $subtotal,
            $shipping_fee,
            $discount,
            $final_amount,
            'Giao hàng tận nơi'
        ]);
        
        $order_id = $pdo->lastInsertId();
        
        // Insert order details
        $stmt = $pdo->prepare("INSERT INTO order_details (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        foreach ($_SESSION['cart'] as $item) {
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
        $_SESSION['order_success'] = "Đặt hàng thành công! Mã đơn hàng: " . $order_code;
        
        header('Location: checkout.php');
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Lỗi khi đặt hàng: " . $e->getMessage();
        header('Location: cart.php');
        exit;
    }
}

// Calculate cart totals
$subtotal = 0;
$cart_items = $_SESSION['cart'];
foreach ($cart_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping_fee = 30000;
$total = $subtotal + $shipping_fee;

// Get messages
$success_message = $_SESSION['cart_success'] ?? '';
unset($_SESSION['cart_success']);
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

// Get cart count
$cart_count = array_sum(array_column($cart_items, 'quantity'));

$is_logged_in = isset($_SESSION['user_id']);

// Lấy địa chỉ tạm từ session (đã lưu từ giỏ hàng)
$tempAddress = isset($_SESSION['temp_shipping_address']) ? $_SESSION['temp_shipping_address'] : null;
?>

<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Giỏ Hàng - Feane</title>
  
  <!-- Bootstrap core CSS -->
  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  <!-- Font Awesome -->
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  <!-- Custom styles -->
  <link href="css/style.css" rel="stylesheet" />
  <link href="css/responsive.css" rel="stylesheet" />
  
  <style>
    .cart_section {
      padding: 50px 0;
      background-color: #f8f9fa;
      min-height: calc(100vh - 300px);
    }
    .cart-item {
      border-bottom: 1px solid #eee;
      padding: 15px 0;
      background: #fff;
      transition: all 0.3s;
    }
    .cart-item:hover {
      background: #fef9e6;
    }
    .cart-item .img-box {
      width: 100px;
      height: 80px;
      overflow: hidden;
      border-radius: 8px;
    }
    .cart-item .img-box img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .quantity-control {
      display: flex;
      align-items: center;
    }
    .quantity-btn {
      width: 30px;
      height: 30px;
      border: 1px solid #ddd;
      background: #f8f9fa;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      border-radius: 5px;
      transition: all 0.3s;
    }
    .quantity-btn:hover {
      background: #ffbe33;
      color: white;
      border-color: #ffbe33;
    }
    .quantity-input {
      width: 50px;
      height: 30px;
      text-align: center;
      border: 1px solid #ddd;
      margin: 0 5px;
      border-radius: 5px;
    }
    .cart-summary {
      background: #fff;
      padding: 20px;
      border-radius: 5px;
      margin-top: 30px;
      border: 1px solid #ddd;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .empty-cart {
      text-align: center;
      padding: 80px 0;
      background: #fff;
      border-radius: 5px;
    }
    .empty-cart i {
      font-size: 80px;
      color: #ddd;
      margin-bottom: 20px;
    }
    .empty-cart h3 {
      color: #333;
      margin-bottom: 10px;
    }
    .empty-cart p {
      color: #666;
      margin-bottom: 20px;
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
    .btn-danger {
      background-color: #dc3545;
      border-color: #dc3545;
    }
    .price, .item-total {
      font-weight: bold;
      color: #ffbe33;
      font-size: 18px;
    }
    .alert {
      padding: 12px 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    .alert-error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    .btn-continue {
      background-color: #ffbe33;
      color: white;
      padding: 12px 30px;
      border-radius: 30px;
      font-weight: bold;
      text-decoration: none;
      display: inline-block;
      transition: all 0.3s;
    }
    .btn-continue:hover {
      background-color: #e69c00;
      color: white;
      text-decoration: none;
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
    
    @media (max-width: 768px) {
      .cart-item .img-box {
        width: 60px;
        height: 60px;
        margin-bottom: 10px;
      }
      .cart-item .row > div {
        text-align: center;
        margin-bottom: 10px;
      }
      .user-name {
        display: none;
      }
      .dropdown-menu-custom {
        right: -50px;
      }
    }
    
    /* Address update card styles */
    .address-update-card {
      background: #fff;
      border-radius: 10px;
      border: 1px solid #ddd;
      margin-bottom: 20px;
      overflow: hidden;
    }
    .address-update-header {
      background: #f8f9fa;
      border-bottom: 1px solid #ddd;
      padding: 12px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .address-update-header strong {
      color: #ffbe33;
      font-size: 16px;
    }
    .address-update-header button {
      background: none;
      border: none;
      color: #ffbe33;
      cursor: pointer;
    }
    .address-update-body {
      padding: 20px;
    }
    .address-display {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 8px;
    }
    .address-form-container {
      display: none;
      margin-top: 15px;
    }
    .form-group-custom {
      margin-bottom: 15px;
    }
    .form-group-custom label {
      display: block;
      margin-bottom: 5px;
      font-size: 14px;
      font-weight: 500;
      color: #333;
    }
    .form-group-custom input, 
    .form-group-custom select, 
    .form-group-custom textarea {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 5px;
      font-size: 14px;
    }
    .form-row-custom {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
    }
    .form-row-custom .form-group-custom {
      flex: 1;
      min-width: 150px;
    }
    .btn-save {
      background: #ffbe33;
      color: white;
      border: none;
      padding: 8px 20px;
      border-radius: 5px;
      cursor: pointer;
      margin-right: 10px;
    }
    .btn-save:hover {
      background: #e69c00;
    }
    .btn-cancel {
      background: #6c757d;
      color: white;
      border: none;
      padding: 8px 20px;
      border-radius: 5px;
      cursor: pointer;
    }
    .btn-update-address {
      background: #28a745;
      color: white;
      border: none;
      padding: 8px 20px;
      border-radius: 5px;
      cursor: pointer;
      margin-left: 10px;
    }
    .btn-update-address:hover {
      background: #218838;
    }
    .user-address-info {
      background: #e8f5e9;
      border-left: 4px solid #28a745;
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
              <?php if ($is_logged_in && $user_info): ?>
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
              <a href="search.php" class="btn my-2 my-sm-0 nav_search-btn">
                <i class="fa fa-search" aria-hidden="true"></i>
              </a>
              <div class="order_online">
                <?php if ($is_logged_in): ?>
                  <a href="user/logout.php" style="color: white;">
                    Đăng xuất
                  </a>
                <?php else: ?>
                  <a href="user/login.php" style="color: white;">
                    Đăng nhập/Đăng kí
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </nav>
      </div>
    </header>
    <!-- end header section -->
  </div>

  <!-- shopping cart section -->
  <section class="cart_section layout_padding">
    <div class="container">
      <div class="heading_container heading_center">
        <h2>
          Giỏ hàng của bạn
        </h2>
      </div>

      <?php if ($success_message): ?>
        <div class="alert alert-success">
          <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
      <?php endif; ?>

      <?php if ($error_message): ?>
        <div class="alert alert-error">
          <i class="fa fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
      <?php endif; ?>

      <?php if (empty($cart_items)): ?>
        <div class="empty-cart">
          <i class="fa fa-shopping-cart" aria-hidden="true"></i>
          <h3>Giỏ hàng trống</h3>
          <p>Bạn chưa có sản phẩm nào trong giỏ hàng.</p>
          <a href="menu.php" class="btn-continue">Tiếp tục mua hàng</a>
        </div>
      <?php else: ?>

        <!-- PHẦN HIỂN THỊ ĐỊA CHỈ - ƯU TIÊN ĐỊA CHỈ USER -->
        <div class="address-update-card">
          <div class="address-update-header">
            <strong><i class="fa fa-map-marker"></i> Địa chỉ giao hàng</strong>
            <div>
              <button type="button" class="btn-update-address" onclick="useUserAddress()" style="background:#28a745; color:white; border:none; padding:6px 15px; border-radius:5px; margin-right:10px;">
                <i class="fa fa-user"></i> Dùng địa chỉ tài khoản
              </button>
              <button type="button" onclick="toggleAddressForm()" style="background:#ffbe33; color:white; border:none; padding:6px 15px; border-radius:5px;">
                <i class="fa fa-edit"></i> Cập nhật địa chỉ mới
              </button>
            </div>
          </div>
          <div class="address-update-body">
            <!-- HIỂN THỊ ĐỊA CHỈ USER (MẶC ĐỊNH) -->
            <div id="userAddressDisplay" class="address-display user-address-info" style="background: #e8f5e9; border-left: 4px solid #28a745;">
              <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                  <strong><i class="fa fa-check-circle" style="color: #28a745;"></i> Địa chỉ tài khoản (mặc định)</strong>
                </div>
                <small class="text-muted">Đang sử dụng</small>
              </div>
              <div class="mt-2">
                <div><strong><?= htmlspecialchars($user['full_name']) ?></strong> | <?= htmlspecialchars($user['phone']) ?></div>
                <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($user['address']) ?></div>
              </div>
            </div>
            
            <!-- HIỂN THỊ ĐỊA CHỈ TẠM (NẾU CÓ) -->
            <div id="tempAddressDisplay" style="display: <?= ($tempAddress && !empty($tempAddress['address'])) ? 'block' : 'none' ?>; margin-top: 15px;">
              <div class="address-display" style="background: #fff3e0; border-left: 4px solid #ffbe33;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <div>
                    <strong><i class="fa fa-truck" style="color: #ffbe33;"></i> Địa chỉ tạm thời</strong>
                  </div>
                  <button type="button" onclick="useTempAddress()" class="btn btn-sm btn-warning" style="background:#ffbe33; border:none; padding:3px 10px; border-radius:3px;">
                    <i class="fa fa-check"></i> Sử dụng
                  </button>
                </div>
                <div class="mt-2">
                  <div><strong><?= htmlspecialchars($tempAddress['full_name'] ?? '') ?></strong> | <?= htmlspecialchars($tempAddress['phone'] ?? '') ?></div>
                  <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($tempAddress['address'] ?? '') ?>, <?= htmlspecialchars($tempAddress['ward'] ?? '') ?>, <?= htmlspecialchars($tempAddress['district'] ?? '') ?>, <?= htmlspecialchars($tempAddress['city'] ?? '') ?></div>
                  <?php if (!empty($tempAddress['notes'])): ?>
                    <div style="color: #999; font-size: 0.85rem; margin-top: 5px;"><i class="fa fa-info-circle"></i> <?= htmlspecialchars($tempAddress['notes']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            
            <!-- FORM NHẬP ĐỊA CHỈ MỚI -->
            <div id="addressFormContainer" class="address-form-container">
              <h5 style="margin-bottom: 15px;"><i class="fa fa-pencil-square-o"></i> Nhập địa chỉ giao hàng mới</h5>
              <div id="shippingAddressForm">
                <div class="form-row-custom">
                  <div class="form-group-custom">
                    <label>Họ tên người nhận *</label>
                    <input type="text" id="recv_name" value="<?= htmlspecialchars($tempAddress['full_name'] ?? $user['full_name']) ?>">
                  </div>
                  <div class="form-group-custom">
                    <label>Số điện thoại *</label>
                    <input type="tel" id="recv_phone" value="<?= htmlspecialchars($tempAddress['phone'] ?? $user['phone']) ?>">
                  </div>
                </div>
                
                <div class="form-group-custom">
                  <label>Địa chỉ chi tiết *</label>
                  <input type="text" id="recv_street" value="<?= htmlspecialchars($tempAddress['address'] ?? '') ?>" placeholder="Số nhà, tên đường">
                </div>
                
                <div class="form-row-custom">
                  <div class="form-group-custom">
                    <label>Tỉnh/Thành phố *</label>
                    <select id="recv_city">
                      <option value="">Chọn tỉnh/thành phố</option>
                      <option value="TP.HCM" <?= ($tempAddress['city'] ?? '') == 'TP.HCM' ? 'selected' : '' ?>>TP. Hồ Chí Minh</option>
                      <option value="Hà Nội" <?= ($tempAddress['city'] ?? '') == 'Hà Nội' ? 'selected' : '' ?>>Hà Nội</option>
                      <option value="Đà Nẵng" <?= ($tempAddress['city'] ?? '') == 'Đà Nẵng' ? 'selected' : '' ?>>Đà Nẵng</option>
                      <option value="Cần Thơ" <?= ($tempAddress['city'] ?? '') == 'Cần Thơ' ? 'selected' : '' ?>>Cần Thơ</option>
                      <option value="Hải Phòng" <?= ($tempAddress['city'] ?? '') == 'Hải Phòng' ? 'selected' : '' ?>>Hải Phòng</option>
                    </select>
                  </div>
                  <div class="form-group-custom">
                    <label>Quận/Huyện *</label>
                    <select id="recv_district">
                      <option value="">Chọn quận/huyện</option>
                    </select>
                  </div>
                  <div class="form-group-custom">
                    <label>Phường/Xã *</label>
                    <input type="text" id="recv_ward" value="<?= htmlspecialchars($tempAddress['ward'] ?? '') ?>" placeholder="Phường/Xã">
                  </div>
                </div>
                
                <div class="form-group-custom">
                  <label>Ghi chú (tùy chọn)</label>
                  <textarea id="recv_notes" rows="2" placeholder="Ghi chú cho người giao hàng..."><?= htmlspecialchars($tempAddress['notes'] ?? '') ?></textarea>
                </div>
                
                <div>
                  <button type="button" class="btn-save" onclick="saveShippingAddress()">💾 Lưu địa chỉ</button>
                  <button type="button" class="btn-cancel" onclick="cancelAddressForm()">❌ Hủy</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="cart-items">
          <?php foreach ($cart_items as $id => $item): ?>
          <div class="cart-item" data-id="<?php echo $id; ?>">
            <div class="row align-items-center">
              <div class="col-md-2">
                <div class="img-box">
                  <img src="<?php 
                    $image_path = $item['image'];
                    if (strpos($image_path, 'http') !== 0 && strpos($image_path, 'images/') !== 0) {
                        $image_path = 'images/' . $image_path;
                    }
                    echo htmlspecialchars($image_path); 
                  ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                </div>
              </div>
              <div class="col-md-4">
                <h5><?php echo htmlspecialchars($item['name']); ?></h5>
                <?php if (!empty($item['options'])): ?>
                <p class="text-muted"><?php echo htmlspecialchars($item['options']); ?></p>
                <?php endif; ?>
              </div>
              <div class="col-md-2">
                <span class="price"><?php echo number_format($item['price'], 0, ',', '.'); ?>đ</span>
              </div>
              <div class="col-md-2">
                <div class="quantity-control">
                  <button class="quantity-btn minus" data-id="<?php echo $id; ?>">-</button>
                  <input type="number" class="quantity-input" value="<?php echo $item['quantity']; ?>" min="1" data-id="<?php echo $id; ?>">
                  <button class="quantity-btn plus" data-id="<?php echo $id; ?>">+</button>
                </div>
              </div>
              <div class="col-md-2">
                <span class="item-total"><?php echo number_format($item['price'] * $item['quantity'], 0, ',', '.'); ?>đ</span>
              </div>
            </div>
            <div class="row mt-2">
              <div class="col-md-10 offset-md-2">
                <button class="btn btn-danger btn-sm remove-item" data-id="<?php echo $id; ?>">
                  <i class="fa fa-trash"></i> Xóa sản phẩm
                </button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="cart-summary">
          <div class="row">
            <div class="col-md-6">
              <h5>Tạm tính: <span id="subtotal"><?php echo number_format($subtotal, 0, ',', '.'); ?>đ</span></h5>
              <h5>Phí vận chuyển: <span id="shipping"><?php echo number_format($shipping_fee, 0, ',', '.'); ?>đ</span></h5>
              <h3 class="text-warning">Tổng cộng: <span id="total"><?php echo number_format($total, 0, ',', '.'); ?>đ</span></h3>
            </div>
            <div class="col-md-6 text-right">
              <a href="menu.php" class="btn btn-outline-primary mr-2">
                <i class="fa fa-shopping-bag"></i> Tiếp tục mua hàng
              </a>
              <a href="checkout.php?checkout=1" class="btn btn-primary">
                <i class="fa fa-credit-card"></i> Tiến hành thanh toán
              </a>
            </div>
          </div>
        </div>

      <?php endif; ?>
    </div>
  </section>
  <!-- end shopping cart section -->

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

  <!-- jQuery -->
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
    
    // Format VND function
    function formatVND(amount) {
      return new Intl.NumberFormat('vi-VN', { 
        style: 'currency', 
        currency: 'VND',
        minimumFractionDigits: 0
      }).format(amount);
    }

    function updateCartTotal() {
      let subtotal = 0;
      document.querySelectorAll('.cart-item').forEach(item => {
        const price = parseInt(item.querySelector('.price').textContent.replace(/\./g, '').replace('đ', ''));
        const quantity = parseInt(item.querySelector('.quantity-input').value);
        const total = price * quantity;
        item.querySelector('.item-total').textContent = formatVND(total);
        subtotal += total;
      });
      
      const shipping = 30000;
      const total = subtotal + shipping;
      
      document.getElementById('subtotal').textContent = formatVND(subtotal);
      document.getElementById('shipping').textContent = formatVND(shipping);
      document.getElementById('total').textContent = formatVND(total);
      
      updateCartCount();
    }

    function updateCartCount() {
      let totalItems = 0;
      document.querySelectorAll('.quantity-input').forEach(input => {
        totalItems += parseInt(input.value);
      });
      
      let cartCount = document.querySelector('.cart-count');
      if (totalItems > 0) {
        if (!cartCount) {
          cartCount = document.createElement('span');
          cartCount.className = 'cart-count';
          document.querySelector('.cart_link').style.position = 'relative';
          document.querySelector('.cart_link').appendChild(cartCount);
        }
        cartCount.textContent = totalItems;
      } else if (cartCount) {
        cartCount.remove();
      }
    }

    // AJAX functions for cart
    function updateQuantity(productId, quantity) {
      $.ajax({
        url: 'cart.php',
        type: 'POST',
        data: {
          action: 'update_quantity',
          product_id: productId,
          quantity: quantity
        },
        success: function(response) {
          if (response.success) {
            location.reload();
          }
        },
        error: function() {
          alert('Có lỗi xảy ra, vui lòng thử lại!');
        }
      });
    }

    function removeItem(productId) {
      if (confirm('Bạn có chắc muốn xóa sản phẩm này khỏi giỏ hàng?')) {
        $.ajax({
          url: 'cart.php',
          type: 'POST',
          data: {
            action: 'remove_item',
            product_id: productId
          },
          success: function(response) {
            if (response.success) {
              location.reload();
            }
          },
          error: function() {
            alert('Có lỗi xảy ra, vui lòng thử lại!');
          }
        });
      }
    }

    // Event listeners for cart
    if (document.querySelectorAll('.quantity-btn').length > 0) {
      document.querySelectorAll('.quantity-btn').forEach(button => {
        button.addEventListener('click', function() {
          const item = this.closest('.cart-item');
          const input = item.querySelector('.quantity-input');
          const productId = input.getAttribute('data-id');
          let value = parseInt(input.value);
          
          if(this.classList.contains('plus')) {
            value++;
          } else if(this.classList.contains('minus') && value > 1) {
            value--;
          }
          
          if (value !== parseInt(input.value)) {
            input.value = value;
            updateQuantity(productId, value);
          }
        });
      });

      document.querySelectorAll('.quantity-input').forEach(input => {
        input.addEventListener('change', function() {
          if(this.value < 1) this.value = 1;
          const productId = this.getAttribute('data-id');
          updateQuantity(productId, this.value);
        });
      });

      document.querySelectorAll('.remove-item').forEach(button => {
        button.addEventListener('click', function() {
          const productId = this.getAttribute('data-id');
          removeItem(productId);
        });
      });
    }

    // Display current year
    document.getElementById('displayYear').innerHTML = new Date().getFullYear();

    // ========== HÀM XỬ LÝ ĐỊA CHỈ ==========
    
    // Sử dụng địa chỉ từ tài khoản
    function useUserAddress() {
      // Xóa địa chỉ tạm khỏi session
      $.ajax({
        url: 'cart.php',
        type: 'POST',
        data: {
          action: 'use_user_address'
        },
        success: function() {
          location.reload();
        }
      });
    }
    
    // Sử dụng địa chỉ tạm
    function useTempAddress() {
      // Đã có sẵn trong session, chỉ cần reload để checkout sử dụng
      alert('Đã chọn địa chỉ tạm thời. Khi thanh toán bạn có thể chọn địa chỉ này.');
    }
    
    function toggleAddressForm() {
      const formContainer = document.getElementById('addressFormContainer');
      
      if (formContainer.style.display === 'none' || formContainer.style.display === '') {
        formContainer.style.display = 'block';
        loadDistrictsForCart();
      } else {
        formContainer.style.display = 'none';
      }
    }

    function cancelAddressForm() {
      document.getElementById('addressFormContainer').style.display = 'none';
    }

    function loadDistrictsForCart() {
      const city = document.getElementById('recv_city').value;
      const districtSelect = document.getElementById('recv_district');
      
      const districts = {
        'TP.HCM': ['Quận 1', 'Quận 2', 'Quận 3', 'Quận 4', 'Quận 5', 'Quận 6', 'Quận 7', 'Quận 8', 'Quận 9', 'Quận 10', 'Quận 11', 'Quận 12', 'Bình Thạnh', 'Gò Vấp', 'Tân Bình', 'Tân Phú', 'Phú Nhuận'],
        'Hà Nội': ['Quận Ba Đình', 'Quận Hoàn Kiếm', 'Quận Hai Bà Trưng', 'Quận Đống Đa', 'Quận Tây Hồ', 'Quận Cầu Giấy', 'Quận Thanh Xuân', 'Quận Hoàng Mai', 'Quận Long Biên'],
        'Đà Nẵng': ['Quận Hải Châu', 'Quận Thanh Khê', 'Quận Sơn Trà', 'Quận Ngũ Hành Sơn', 'Quận Liên Chiểu', 'Quận Cẩm Lệ'],
        'Cần Thơ': ['Quận Ninh Kiều', 'Quận Bình Thủy', 'Quận Cái Răng', 'Quận Ô Môn', 'Quận Thốt Nốt'],
        'Hải Phòng': ['Quận Hồng Bàng', 'Quận Ngô Quyền', 'Quận Lê Chân', 'Quận Hải An', 'Quận Kiến An']
      };
      
      districtSelect.innerHTML = '<option value="">Chọn quận/huyện</option>';
      
      if (districts[city]) {
        districts[city].forEach(district => {
          const option = document.createElement('option');
          option.value = district;
          option.textContent = district;
          districtSelect.appendChild(option);
        });
      }
    }

    function saveShippingAddress() {
      const recvName = document.getElementById('recv_name').value.trim();
      const recvPhone = document.getElementById('recv_phone').value.trim();
      const recvStreet = document.getElementById('recv_street').value.trim();
      const recvCity = document.getElementById('recv_city').value;
      const recvDistrict = document.getElementById('recv_district').value;
      const recvWard = document.getElementById('recv_ward').value.trim();
      
      if (!recvName) {
        alert('Vui lòng nhập họ tên người nhận');
        return;
      }
      if (!recvPhone || !/^[0-9]{10,11}$/.test(recvPhone)) {
        alert('Vui lòng nhập số điện thoại hợp lệ (10-11 số)');
        return;
      }
      if (!recvStreet) {
        alert('Vui lòng nhập địa chỉ chi tiết');
        return;
      }
      if (!recvCity) {
        alert('Vui lòng chọn tỉnh/thành phố');
        return;
      }
      if (!recvDistrict) {
        alert('Vui lòng chọn quận/huyện');
        return;
      }
      if (!recvWard) {
        alert('Vui lòng nhập phường/xã');
        return;
      }
      
      $.ajax({
        url: 'cart.php',
        type: 'POST',
        data: {
          action: 'save_address',
          full_name: recvName,
          phone: recvPhone,
          address: recvStreet,
          city: recvCity,
          district: recvDistrict,
          ward: recvWard,
          notes: document.getElementById('recv_notes').value
        },
        dataType: 'json',
        success: function(data) {
          if (data.success) {
            location.reload();
          } else {
            alert('Lỗi: ' + data.message);
          }
        },
        error: function() {
          alert('Có lỗi xảy ra, vui lòng thử lại');
        }
      });
    }

    document.getElementById('recv_city').addEventListener('change', loadDistrictsForCart);
    
    document.addEventListener('DOMContentLoaded', function() {
      const selectedCity = document.getElementById('recv_city').value;
      if (selectedCity) {
        loadDistrictsForCart();
      }
    });
  </script>

</body>
</html>