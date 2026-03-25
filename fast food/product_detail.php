<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// 1. Lấy ID sản phẩm từ URL
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id <= 0) {
    header("Location: menu.php");
    exit();
}

// 2. Truy vấn chi tiết sản phẩm
$sql = "SELECT p.*, c.name AS category_name,
               (p.cost_price * (1 + p.profit_percentage/100)) AS calculated_selling_price
        FROM products p 
        JOIN categories c ON p.category_id = c.id 
        WHERE p.id = ? AND p.status = 'active'";
$stmt = $pdo->prepare($sql);
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    die("Sản phẩm không tồn tại.");
}

$display_price = $product['calculated_selling_price'];

// --- PHẦN LOGIC ĐỂ GIỐNG MENU.PHP ---
$is_logged_in = isset($_SESSION['user_id']);
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}

// Lấy thông tin user cho dropdown giống menu.php
$user_info = null;
if ($is_logged_in) {
    try {
        $stmt_user = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
        $stmt_user->execute([$_SESSION['user_id']]);
        $user_info = $stmt_user->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <meta name="keywords" content="" />
  <meta name="description" content="" />
  <meta name="author" content="" />
  <link rel="shortcut icon" href="images/favicon.png" type="">
  
  <title><?php echo htmlspecialchars($product['name']); ?> - Feane</title>

  <!-- bootstrap core css -->
  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />

  <!-- owl slider stylesheet -->
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css" />
  <!-- nice select -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/css/nice-select.min.css" />
  <!-- font awesome style -->
  <link href="css/font-awesome.min.css" rel="stylesheet" />

  <!-- Custom styles for this template -->
  <link href="css/style.css" rel="stylesheet" />
  <!-- responsive style -->
  <link href="css/responsive.css" rel="stylesheet" />

  <style>
    /* CSS ĐỒNG BỘ TỪ MENU.PHP */
    .hero_area {
      min-height: auto !important;
    }
    
    /* Điều chỉnh để footer thấp xuống */
    body {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      margin: 0;
    }
    
    .product-detail-section {
      flex: 1;
      padding: 80px 0;
    }
    
    /* Làm to phần sản phẩm */
    .product-detail-section .container {
      max-width: 1400px;
      width: 95%;
    }
    
    .product-image-container {
      padding: 20px;
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .product-image {
      width: 100%;
      border-radius: 15px;
      transition: transform 0.3s;
    }
    
    .product-image:hover {
      transform: scale(1.02);
    }
    
    .product-info-container {
      padding: 20px 30px;
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.1);
      height: 100%;
    }
    
    .product-name {
      font-size: 2.5rem;
      font-weight: 700;
      color: #222;
      margin-bottom: 20px;
      border-bottom: 3px solid #ffbe33;
      display: inline-block;
      padding-bottom: 10px;
    }
    
    .product-category {
      display: inline-block;
      background: #f5f5f5;
      padding: 5px 15px;
      border-radius: 20px;
      font-size: 14px;
      color: #ffbe33;
      margin-bottom: 20px;
    }
    
    .product-price {
      font-size: 2.8rem;
      color: #ffbe33;
      font-weight: bold;
      margin: 20px 0;
      background: linear-gradient(135deg, #ffbe33, #ff8c33);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    
    .product-description {
      font-size: 1.1rem;
      line-height: 1.8;
      color: #555;
      margin: 25px 0;
      padding: 20px 0;
      border-top: 1px solid #eee;
      border-bottom: 1px solid #eee;
    }
    
    .quantity-section {
      display: flex;
      align-items: center;
      gap: 20px;
      margin: 30px 0;
    }
    
    .quantity-label {
      font-weight: 600;
      font-size: 1.1rem;
      color: #333;
    }
    
    .quantity-input {
      width: 100px;
      text-align: center;
      border: 2px solid #ffbe33;
      border-radius: 10px;
      padding: 10px;
      font-size: 1.1rem;
      font-weight: 600;
    }
    
    .action-buttons {
      display: flex;
      gap: 15px;
      margin-top: 30px;
      flex-wrap: wrap;
    }
    
    .add-to-cart-btn {
      background: linear-gradient(135deg, #ffbe33, #ff8c33);
      border: none;
      cursor: pointer;
      padding: 14px 35px;
      border-radius: 50px;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      color: white;
      font-weight: 600;
      font-size: 1.1rem;
      box-shadow: 0 5px 15px rgba(255, 190, 51, 0.3);
    }
    
    .add-to-cart-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(255, 190, 51, 0.4);
      color: white;
      text-decoration: none;
    }
    
    .btn-back {
      background-color: #222;
      color: white;
      padding: 14px 35px;
      border-radius: 50px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      transition: all 0.3s;
      font-weight: 600;
      font-size: 1.1rem;
    }
    
    .btn-back:hover {
      background-color: #ffbe33;
      color: white;
      transform: translateY(-3px);
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
    
    .toast-notification {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background-color: #28a745;
      color: white;
      padding: 12px 20px;
      border-radius: 5px;
      z-index: 10000;
      display: none;
      animation: slideIn 0.3s ease;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    
    @keyframes slideIn {
      from {
        transform: translateX(100%);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }
    
    /* User dropdown styles - giống menu.php */
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
      .user-name {
        display: none;
      }
      .dropdown-menu-custom {
        right: -50px;
      }
      
      .product-name {
        font-size: 1.8rem;
      }
      
      .product-price {
        font-size: 2rem;
      }
      
      .product-info-container {
        margin-top: 30px;
      }
      
      .action-buttons {
        flex-direction: column;
      }
      
      .add-to-cart-btn, .btn-back {
        width: 100%;
        justify-content: center;
      }
    }
    
    @media (min-width: 992px) {
      .product-detail-section .container {
        max-width: 1200px;
      }
    }
  </style>
</head>

<body class="sub_page">

<div id="toast-message" class="toast-notification"></div>

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
            <li class="nav-item active">
              <a class="nav-link" href="menu.php">Menu <span class="sr-only">(current)</span></a>
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
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029" xml:space="preserve">
                <g><path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/></g>
                <g><path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/></g>
                <g><path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/></g>
              </svg>
              <?php if ($cart_count > 0): ?>
              <span class="cart-count"><?php echo $cart_count; ?></span>
              <?php endif; ?>
            </a>
            <a href="search.php" class="btn nav_search-btn">
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

<section class="product-detail-section">
  <div class="container">
    <div class="row align-items-center">
      <div class="col-md-6">
        <div class="product-image-container">
          <img src="<?php echo htmlspecialchars($product['image']); ?>" class="product-image" alt="<?php echo htmlspecialchars($product['name']); ?>">
        </div>
      </div>
      <div class="col-md-6">
        <div class="product-info-container">
          <div class="product-category">
            <i class="fa fa-tag"></i> <?php echo htmlspecialchars($product['category_name']); ?>
          </div>
          <h1 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h1>
          <div class="product-price">
            <?php echo number_format($display_price, 0, ',', '.'); ?>đ
          </div>
          <div class="product-description">
            <?php echo nl2br(htmlspecialchars($product['description'])); ?>
          </div>
          
          <div class="quantity-section">
            <span class="quantity-label">
              <i class="fa fa-sort-numeric-asc"></i> Số lượng:
            </span>
            <input type="number" id="inputQty" value="1" min="1" class="quantity-input">
          </div>

          <div class="action-buttons">
            <?php if ($is_logged_in): ?>
              <button class="add-to-cart-btn" id="btnAddToCart"
                      data-id="<?= $product['id']; ?>"
                      data-name="<?= htmlspecialchars($product['name']); ?>"
                      data-price="<?= $display_price; ?>"
                      data-image="<?= htmlspecialchars($product['image']); ?>">
                <i class="fa fa-shopping-cart"></i> Thêm vào giỏ hàng
              </button>
            <?php else: ?>
              <a href="user/login.php" class="add-to-cart-btn">
                <i class="fa fa-shopping-cart"></i> Đăng nhập để mua
              </a>
            <?php endif; ?>
            
            <a href="menu.php" class="btn-back">
              <i class="fa fa-arrow-left"></i> Quay lại Menu
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

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
            <a href="">
              <i class="fa fa-map-marker" aria-hidden="true"></i>
              <span>
                Location
              </span>
            </a>
            <a href="">
              <i class="fa fa-phone" aria-hidden="true"></i>
              <span>
                Call +01 1234567890
              </span>
            </a>
            <a href="">
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
          <a href="" class="footer-logo">
            Feane
          </a>
          <p>
            Necessary, making this the first true generator on the Internet. It uses a dictionary of over 200 Latin words, combined with
          </p>
          <div class="footer_social">
            <a href="">
              <i class="fa fa-facebook" aria-hidden="true"></i>
            </a>
            <a href="">
              <i class="fa fa-twitter" aria-hidden="true"></i>
            </a>
            <a href="">
              <i class="fa fa-linkedin" aria-hidden="true"></i>
            </a>
            <a href="">
              <i class="fa fa-instagram" aria-hidden="true"></i>
            </a>
            <a href="">
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
<!-- owl slider -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>
<!-- nice select -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/js/jquery.nice-select.min.js"></script>
<!-- custom js -->
<script src="js/custom.js"></script>

<script>
  // User Dropdown functionality (giống menu.php)
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
  
  // Toast notification function (giống menu.php)
  function showToast(message) {
    var toast = document.getElementById('toast-message');
    toast.textContent = message;
    toast.style.display = 'block';
    setTimeout(function() {
      toast.style.display = 'none';
    }, 2000);
  }

  // Update cart count (giống menu.php)
  function updateCartCount(count) {
    var cartLink = document.querySelector('.cart_link');
    var existingCount = cartLink.querySelector('.cart-count');
    if (count > 0) {
      if (existingCount) {
        existingCount.textContent = count;
      } else {
        var newCount = document.createElement('span');
        newCount.className = 'cart-count';
        newCount.textContent = count;
        cartLink.style.position = 'relative';
        cartLink.appendChild(newCount);
      }
    } else {
      if (existingCount) {
        existingCount.remove();
      }
    }
  }

  // Add to cart function (giống menu.php)
  function addToCart(productId, name, price, image, quantity) {
    <?php if (!$is_logged_in): ?>
      if (confirm('Bạn cần đăng nhập để thêm sản phẩm vào giỏ hàng. Đăng nhập ngay?')) {
        window.location.href = 'user/login.php';
      }
      return;
    <?php endif; ?>
    
    var formData = new FormData();
    formData.append('add_to_cart', '1');
    formData.append('product_id', productId);
    formData.append('name', name);
    formData.append('price', price);
    formData.append('image', image);
    formData.append('quantity', quantity);
    
    fetch('cart.php', {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Đã thêm ' + name + ' vào giỏ hàng!');
        if (data.cart_count) {
          updateCartCount(data.cart_count);
        }
      } else {
        showToast('Có lỗi xảy ra, vui lòng thử lại!');
      }
    })
    .catch(function(error) {
      console.error('Error:', error);
      showToast('Có lỗi xảy ra, vui lòng thử lại!');
    });
  }

  // Add to cart button event
  var addToCartBtn = document.getElementById('btnAddToCart');
  if (addToCartBtn) {
    addToCartBtn.addEventListener('click', function(e) {
      e.preventDefault();
      var productId = this.getAttribute('data-id');
      var name = this.getAttribute('data-name');
      var price = this.getAttribute('data-price');
      var image = this.getAttribute('data-image');
      var quantity = document.getElementById('inputQty').value;
      addToCart(productId, name, price, image, quantity);
    });
  }

  // Display current year
  document.getElementById('displayYear').innerHTML = new Date().getFullYear();
</script>

</body>
</html>