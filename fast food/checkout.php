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

// Handle order confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_order'])) {
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $notes = $_POST['order_notes'] ?? '';
    
    try {
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
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())");
        
        $stmt->execute([
            $order_code,
            $user_id,
            $user['full_name'],
            $user['phone'],
            $user['email'] ?? '',
            $user['address'],
            $subtotal,
            $shipping_fee,
            0, // discount
            $total,
            $payment_method,
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
        
        // Set success flag
        $order_success = true;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Lỗi khi đặt hàng: " . $e->getMessage();
    }
}
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
    
    /* Success Modal Styles */
    .success-modal {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      animation: fadeIn 0.3s ease;
    }
    
    .success-content {
      background: white;
      border-radius: 15px;
      padding: 40px;
      text-align: center;
      max-width: 500px;
      width: 90%;
      animation: slideUp 0.4s ease;
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
    
    .success-content h3 {
      color: #28a745;
      margin-bottom: 15px;
      font-size: 24px;
    }
    
    .success-content p {
      color: #666;
      margin-bottom: 10px;
    }
    
    .order-code {
      background: #f8f9fa;
      padding: 10px;
      border-radius: 5px;
      font-size: 18px;
      font-weight: bold;
      color: #ffbe33;
      margin: 15px 0;
    }
    
    .btn-home {
      background-color: #ffbe33;
      color: white;
      padding: 12px 30px;
      border-radius: 30px;
      text-decoration: none;
      display: inline-block;
      margin-top: 20px;
      transition: all 0.3s;
      font-weight: bold;
    }
    
    .btn-home:hover {
      background-color: #e69c00;
      color: white;
      text-decoration: none;
      transform: translateY(-2px);
    }
    
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    @keyframes slideUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
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
            <span>
              Feane
            </span>
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
              <li class="nav-item active">
                <a class="nav-link" href="cart.php">Giỏ Hàng <span class="sr-only">(current)</span></a>
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

      <?php if (!$order_success): ?>
      <div class="row">
        <div class="col-md-8">
          <!-- Thông tin giao hàng -->
          <div class="address-info">
            <h4>Thông tin giao hàng</h4>
            <p><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></p>
            <p><?php echo htmlspecialchars($user['address']); ?></p>
            <p>Điện thoại: <?php echo htmlspecialchars($user['phone']); ?></p>
            <a href="user/profile.php" class="btn btn-outline-secondary btn-sm">Thay đổi địa chỉ</a>
          </div>

          <!-- Phương thức thanh toán -->
          <div class="payment-method">
            <h4>Phương thức thanh toán</h4>
            
            <div class="payment-option active" id="cash-option" data-method="cash">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_method" id="cash" value="cash" checked>
                <label class="form-check-label" for="cash">
                  <strong>Thanh toán tiền mặt khi nhận hàng</strong>
                </label>
              </div>
              <p class="mb-0 mt-2">Bạn sẽ thanh toán bằng tiền mặt khi nhận được hàng</p>
            </div>
            
            <div class="payment-option" id="transfer-option" data-method="bank_transfer">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_method" id="transfer" value="bank_transfer">
                <label class="form-check-label" for="transfer">
                  <strong>Chuyển khoản ngân hàng</strong>
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
                <input class="form-check-input" type="radio" name="payment_method" id="online" value="online">
                <label class="form-check-label" for="online">
                  <strong>Thanh toán trực tuyến</strong>
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
            <label for="orderNotes"><strong>Ghi chú đơn hàng (tùy chọn)</strong></label>
            <textarea class="form-control" id="orderNotes" name="order_notes" rows="3" placeholder="Ghi chú về đơn hàng của bạn..."></textarea>
          </div>
        </div>
        
        <div class="col-md-4">
          <!-- Tóm tắt đơn hàng -->
          <div class="order-summary">
            <h4>Tóm tắt đơn hàng</h4>
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
            
            <form method="POST" action="">
              <input type="hidden" name="payment_method" id="selected_payment_method" value="cash">
              <input type="hidden" name="order_notes" id="selected_order_notes" value="">
              <button type="submit" name="confirm_order" class="btn btn-primary btn-block mt-3">Xác nhận thanh toán</button>
            </form>
            <a href="cart.php" class="btn btn-outline-secondary btn-block mt-2">Quay lại giỏ hàng</a>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <!-- end checkout section -->

  <!-- Success Modal -->
  <?php if ($order_success): ?>
  <div class="success-modal" id="successModal">
    <div class="success-content">
      <div class="success-icon">
        <i class="fa fa-check"></i>
      </div>
      <h3>Đặt hàng thành công!</h3>
      <p>Cảm ơn bạn đã đặt hàng tại Feane. Đơn hàng của bạn đã được ghi nhận.</p>
      <div class="order-code">
        <i class="fa fa-ticket"></i> Mã đơn hàng: <strong><?php echo $order_code; ?></strong>
      </div>
      <p>Chúng tôi sẽ liên hệ với bạn trong thời gian sớm nhất để xác nhận đơn hàng.</p>
      <a href="index.php" class="btn-home">
        <i class="fa fa-home"></i> Quay về trang chủ
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- footer section -->
  <footer class="footer_section">
    <div class="container">
      <div class="row">
        <div class="col-md-4 footer-col">
          <div class="footer_contact">
            <h4>
              Contact Us
            </h4>
            <div class="contact_link_box">
              <a href="contact.html">
                <i class="fa fa-map-marker" aria-hidden="true"></i>
                <span>
                  Location
                </span>
              </a>
              <a href="contact.html">
                <i class="fa fa-phone" aria-hidden="true"></i>
                <span>
                  Call +01 1234567890
                </span>
              </a>
              <a href="contact.html">
                <i class="fa fa-envelope" aria-hidden="true"></i>
                <span>
                  demo@gmail.com
                </span>
              </a>
            </div>
          </div>
        </div>
        <div class="col-md-4 footer-col">
          <div class="footer_detail">
            <a href="index.php" class="footer-logo">
              Feane
            </a>
            <p>
              Necessary, making this the first true generator on the Internet. It uses a dictionary of over 200 Latin words, combined with
            </p>
            <div class="footer_social">
              <a href="social.html">
                <i class="fa fa-facebook" aria-hidden="true"></i>
              </a>
              <a href="social.html">
                <i class="fa fa-twitter" aria-hidden="true"></i>
              </a>
              <a href="social.html">
                <i class="fa fa-linkedin" aria-hidden="true"></i>
              </a>
              <a href="social.html">
                <i class="fa fa-instagram" aria-hidden="true"></i>
              </a>
              <a href="social.html">
                <i class="fa fa-pinterest" aria-hidden="true"></i>
              </a>
            </div>
          </div>
        </div>
        <div class="col-md-4 footer-col">
          <h4>
            Opening Hours
          </h4>
          <p>
            Everyday
          </p>
          <p>
            10.00 Am -10.00 Pm
          </p>
        </div>
      </div>
      <div class="footer-info">
        <p>
          &copy; <span id="displayYear"></span> All Rights Reserved By
          <a href="https://html.design/">Free Html Templates</a><br><br>
          &copy; <span id="displayYear"></span> Distributed By
          <a href="https://themewagon.com/" target="_blank">ThemeWagon</a>
        </p>
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
    // Xử lý chọn phương thức thanh toán
    document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
      radio.addEventListener('change', function() {
        // Ẩn tất cả các chi tiết thanh toán
        document.querySelectorAll('.payment-details').forEach(detail => {
          detail.style.display = 'none';
        });
        
        // Xóa trạng thái active của tất cả các tùy chọn
        document.querySelectorAll('.payment-option').forEach(option => {
          option.classList.remove('active');
        });
        
        // Hiển thị chi tiết thanh toán tương ứng và thêm lớp active
        if (this.id === 'transfer') {
          document.getElementById('transfer-details').style.display = 'block';
          document.getElementById('transfer-option').classList.add('active');
        } else if (this.id === 'online') {
          document.getElementById('online-details').style.display = 'block';
          document.getElementById('online-option').classList.add('active');
        } else {
          document.getElementById('cash-option').classList.add('active');
        }
        
        // Cập nhật giá trị hidden input
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
          // Trigger change event
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