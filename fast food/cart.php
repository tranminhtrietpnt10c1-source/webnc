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

// Initialize cart from session if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle adding product to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            
            $_SESSION['cart_success'] = "Đã thêm " . $name . " vào giỏ hàng!";
            
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['success' => true, 'cart_count' => array_sum(array_column($_SESSION['cart'], 'quantity'))]);
                exit;
            }
            
            $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
            header('Location: ' . $referer);
            exit;
        }
    }
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $action = $_POST['action'] ?? '';
        $product_id = $_POST['product_id'] ?? '';
        $quantity = $_POST['quantity'] ?? 1;
        
        $response = ['success' => false];
        
        if ($action === 'update_quantity' && $product_id && isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] = max(1, intval($quantity));
            $response['success'] = true;
            $response['cart'] = $_SESSION['cart'];
        } elseif ($action === 'remove_item' && $product_id) {
            unset($_SESSION['cart'][$product_id]);
            $response['success'] = true;
            $response['cart'] = $_SESSION['cart'];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
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
        
        // Insert order details with correct column names
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
    .address-info {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-top: 10px;
    }
    .address-info p {
      margin-bottom: 5px;
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
                <a class="nav-link" href="order_history.php">Order history</a>
              </li>
            </ul>
            <div class="user_option">
              <a href="user/profile.php" class="user_link">
                <i class="fa fa-user" aria-hidden="true"></i>
              </a>
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

        <!-- Thông tin giao hàng -->
        <div class="address-section mt-4" style="background: #fff; padding: 20px; border-radius: 5px; border: 1px solid #ddd;">
          <h4><i class="fa fa-truck"></i> Thông tin giao hàng</h4>
          <div class="address-info">
            <p><strong><i class="fa fa-user"></i> Người nhận:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
            <p><strong><i class="fa fa-phone"></i> Số điện thoại:</strong> <?php echo htmlspecialchars($user['phone']); ?></p>
            <p><strong><i class="fa fa-envelope"></i> Email:</strong> <?php echo htmlspecialchars($user['email'] ?? 'Chưa cập nhật'); ?></p>
            <p><strong><i class="fa fa-map-marker"></i> Địa chỉ giao hàng:</strong> <?php echo htmlspecialchars($user['address']); ?></p>
            <p class="text-muted mt-2">
              <i class="fa fa-info-circle"></i> Địa chỉ này sẽ được sử dụng để giao hàng. 
              <a href="user/profile.php" class="text-warning">Cập nhật địa chỉ</a>
            </p>
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
              <a href=""><i class="fa fa-map-marker" aria-hidden="true"></i><span>Location</span></a>
              <a href=""><i class="fa fa-phone" aria-hidden="true"></i><span>Call +01 1234567890</span></a>
              <a href=""><i class="fa fa-envelope" aria-hidden="true"></i><span>demo@gmail.com</span></a>
            </div>
          </div>
        </div>
        <div class="col-md-4 footer-col">
          <div class="footer_detail">
            <a href="" class="footer-logo">Feane</a>
            <p>Necessary, making this the first true generator on the Internet. It uses a dictionary of over 200 Latin words, combined with</p>
            <div class="footer_social">
              <a href=""><i class="fa fa-facebook" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-twitter" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-linkedin" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-instagram" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-pinterest" aria-hidden="true"></i></a>
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
        <p>&copy; <span id="displayYear"></span> All Rights Reserved By <a href="https://html.design/">Free Html Templates</a><br><br>
        &copy; <span id="displayYear"></span> Distributed By <a href="https://themewagon.com/" target="_blank">ThemeWagon</a></p>
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
    // Format VND function
    function formatVND(amount) {
      return new Intl.NumberFormat('vi-VN', { 
        style: 'currency', 
        currency: 'VND',
        minimumFractionDigits: 0
      }).format(amount);
    }

    // Parse VND string to number
    function parseVND(vndString) {
      return parseInt(vndString.replace(/\./g, '').replace('đ', ''));
    }

    function updateCartTotal() {
      let subtotal = 0;
      document.querySelectorAll('.cart-item').forEach(item => {
        const price = parseInt(item.querySelector('.price').textContent.replace(/\./g, ''));
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

    // AJAX functions
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

    // Event listeners
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
  </script>

</body>
</html>