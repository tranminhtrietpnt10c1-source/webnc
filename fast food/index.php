<?php
session_start();
require_once 'includes/db_connection.php';

// Lấy thông tin user nếu đã đăng nhập
$user_info = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, full_name, email, phone, role, status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Kiểm tra tài khoản có bị khóa không
        if ($user_info && $user_info['status'] === 'inactive') {
            session_destroy();
            header('Location: user/login.php?error=account_locked');
            exit;
        }
    } catch (PDOException $e) {
        // Bỏ qua lỗi nếu không có bảng users
    }
}

$page = 1;
$limit = 6;
$offset = 0;

// Đếm tổng số sản phẩm
$totalStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
$totalProducts = $totalStmt->fetchColumn();
$totalPages = ceil($totalProducts / $limit);

// Lấy sản phẩm cho trang hiện tại
$stmt = $pdo->prepare("SELECT p.id, p.name, p.description, p.image, p.cost_price, p.profit_percentage,
                              (p.cost_price * (1 + p.profit_percentage/100)) AS selling_price
                       FROM products p
                       WHERE p.status = 'active'
                       ORDER BY p.id DESC
                       LIMIT ? OFFSET ?");
$stmt->bindParam(1, $limit, PDO::PARAM_INT);
$stmt->bindParam(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$featured = $stmt->fetchAll();

// Lấy số lượng sản phẩm trong giỏ hàng
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <link rel="shortcut icon" href="images/favicon.png" type="">
  <title>Feane - Fast Food Restaurant</title>
  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/css/nice-select.min.css" />
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  <link href="css/style.css" rel="stylesheet" />
  <link href="css/responsive.css" rel="stylesheet" />
  <style>
    .hero_area { min-height: auto !important; }
    .view-more-container { display: flex; justify-content: center; margin-top: 40px; }
    .btn-view-more { background-color: #ffbe33; color: white; padding: 12px 30px; border-radius: 5px; text-decoration: none; font-weight: bold; transition: all 0.3s; }
    .btn-view-more:hover { background-color: #e69c00; color: white; }
    .pagination .page-link { color: #8B8000; background-color: #fff; border-color: #FFD700; }
    .pagination .page-link:hover { color: #fff; background-color: #FFD700; border-color: #FFD700; }
    .pagination .page-item.active .page-link { color: #fff; background-color: #FFD700; border-color: #FFD700; }
    .pagination .page-item.disabled .page-link { color: #ccc; background-color: #fff; border-color: #eee; }
    #product-grid { transition: opacity 0.4s ease; }
    
    /* Loader styles */
    .loader {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 9999;
      visibility: hidden;
      opacity: 0;
      transition: 0.3s;
    }
    .loader.show {
      visibility: visible;
      opacity: 1;
    }
    .spinner {
      width: 50px;
      height: 50px;
      border: 5px solid #f3f3f3;
      border-top: 5px solid #ffbe33;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    /* Toast notification styles */
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
    
    /* Cart count styles */
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
    
    /* Add to Cart Button Styles - Giống menu.php */
    .add-to-cart-btn {
      background: #ffbe33;
      border: none;
      cursor: pointer;
      padding: 10px 15px;
      border-radius: 30px;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: white;
      font-weight: 500;
      font-size: 14px;
    }
    
    .add-to-cart-btn i {
      font-size: 14px;
    }
    
    .add-to-cart-btn:hover {
      background: #e69c00;
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(255, 190, 51, 0.3);
    }
    
    .add-to-cart-btn:active {
      transform: translateY(0);
    }
    
    /* Cart link button for non-logged in users */
    .cart-link-btn {
      background: #ffbe33;
      border: none;
      padding: 10px 15px;
      border-radius: 30px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: white;
      font-weight: 500;
      font-size: 14px;
      text-decoration: none;
      transition: all 0.3s;
    }
    
    .cart-link-btn:hover {
      background: #e69c00;
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(255, 190, 51, 0.3);
      color: white;
      text-decoration: none;
    }
    
    /* Product card styles */
    .box {
      margin-bottom: 30px;
      transition: all 0.3s;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    .box:hover {
      transform: translateY(-5px);
      box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    }
    
    .box .img-box img {
      width: 100%;
      transition: transform 0.3s;
    }
    
    .box:hover .img-box img {
      transform: scale(1.05);
    }
    
    .box .detail-box {
      padding: 15px;
    }
    
    .box .options {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 15px;
    }
    
    .box .options h6 {
      color: #ffbe33;
      font-weight: bold;
      font-size: 18px;
      margin: 0;
    }
    
    /* Description styling - màu trắng giống menu */
    .detail-box p {
      color: white !important;
      line-height: 1.5;
      margin-bottom: 10px;
      font-size: 14px;
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
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
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
    .dropdown-header-info .role-badge {
      display: inline-block;
      background: #ffbe33;
      color: #222;
      font-size: 10px;
      padding: 2px 8px;
      border-radius: 20px;
      margin-top: 5px;
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
    
    /* Responsive */
    @media (max-width: 768px) {
      .user-name {
        display: none;
      }
      .dropdown-menu-custom {
        right: -50px;
      }
      .user-icon {
        width: 28px;
        height: 28px;
        font-size: 12px;
      }
    }
  </style>
</head>
<body>

<div id="loader" class="loader">
  <div class="spinner"></div>
</div>

<div id="toast-message" class="toast-notification"></div>

<div class="hero_area">
  <div class="bg-box"><img src="images/hero-bg.jpg" alt=""></div>
  <header class="header_section">
    <div class="container">
      <nav class="navbar navbar-expand-lg custom_nav-container">
        <a class="navbar-brand" href="index.php"><span>Feane</span></a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
          <span class=""> </span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
          <ul class="navbar-nav mx-auto">
            <li class="nav-item active"><a class="nav-link" href="index.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="menu.php">Menu</a></li>
            <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
            <li class="nav-item">
              <?php if (isset($_SESSION['user_id'])): ?>
                <a class="nav-link" href="order_history.php">Order History</a>
              <?php else: ?>
                <a class="nav-link" href="user/login.php">Order History</a>
              <?php endif; ?>
            </li>
          </ul>
          <div class="user_option">
            <?php if (isset($_SESSION['user_id']) && $user_info): ?>
              <!-- User Dropdown khi đã đăng nhập -->
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
                      <?php if ($user_info['role'] === 'admin'): ?>
                        <span class="role-badge">Quản trị viên</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <a href="user/profile.php" class="dropdown-item-custom">
                    <i class="fa fa-user"></i>
                    <span>Thông tin tài khoản</span>
                  </a>
                
                </div>
              </div>
            <?php else: ?>
              <!-- Nút đăng nhập khi chưa đăng nhập -->
              <a href="user/login.php" class="user_link">
                <i class="fa fa-user" aria-hidden="true"></i>
              </a>
            <?php endif; ?>
            
            <!-- Icon giỏ hàng -->
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
              <?php if (isset($_SESSION['user_id'])): ?>
                <a href="user/logout.php" style="color: white;">Đăng xuất</a>
              <?php else: ?>
                <a href="user/login.php" style="color: white;">Đăng nhập/Đăng kí</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <section class="slider_section">
    <div id="customCarousel1" class="carousel slide" data-ride="carousel">
      <div class="carousel-inner">
        <div class="carousel-item active">
          <div class="container">
            <div class="row">
              <div class="col-md-7 col-lg-6">
                <div class="detail-box">
                  <h1>Fast Food</h1>
                  <p>
                    Delicious food delivered to your doorstep. Enjoy the best burgers, pizzas, and more at Feane!
                  </p>
                  <div class="btn-box">
                    <a href="<?= isset($_SESSION['user_id']) ? 'menu.php' : 'user/login.php' ?>" class="btn1">Order Now</a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<div class="main-content">

  <!-- offer section -->
  <section class="offer_section layout_padding-bottom">
    <div class="offer_container">
      <div class="container">
        <div class="row">
          <div class="col-md-6">
            <div class="box">
              <div class="img-box"><img src="images/o1.jpg" alt=""></div>
              <div class="detail-box">
                <h5>Tasty Thursdays</h5>
                <h6><span>20%</span> Off</h6>
                <a href="<?= isset($_SESSION['user_id']) ? 'menu.php' : 'user/login.php' ?>">Order Now</a>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="box">
              <div class="img-box"><img src="images/o2.jpg" alt=""></div>
              <div class="detail-box">
                <h5>Pizza Days</h5>
                <h6><span>15%</span> Off</h6>
                <a href="<?= isset($_SESSION['user_id']) ? 'menu.php' : 'user/login.php' ?>">Order Now</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- food section -->
  <section class="food_section layout_padding">
    <div class="container">
      <div class="heading_container heading_center">
        <h2>Our Menu</h2>
      </div>
      <div class="filters-content">
        <div class="row grid" id="product-grid">
          <?php if (empty($featured)): ?>
            <div class="col-12 text-center">Chưa có sản phẩm nào.</div>
          <?php else: ?>
            <?php foreach ($featured as $prod): ?>
              <div class="col-sm-6 col-lg-4">
                <div class="box">
                  <a href="product_detail.php?id=<?= $prod['id'] ?>">
                    <div class="img-box"><img src="<?= htmlspecialchars($prod['image']) ?>" alt="<?= htmlspecialchars($prod['name']) ?>"></div>
                  </a>
                  <div class="detail-box">
                    <h5><?= htmlspecialchars($prod['name']) ?></h5>
                    <p><?= htmlspecialchars($prod['description']) ?></p>
                    <div class="options">
                      <h6><?= number_format($prod['selling_price']) ?>đ</h6>
                      <?php if ($is_logged_in): ?>
                        <button class="add-to-cart-btn" 
                                data-id="<?= $prod['id'] ?>"
                                data-name="<?= htmlspecialchars($prod['name']) ?>"
                                data-price="<?= $prod['selling_price'] ?>"
                                data-image="<?= htmlspecialchars($prod['image']) ?>">
                          <i class="fa fa-shopping-cart"></i> Thêm
                        </button>
                      <?php else: ?>
                        <a href="user/login.php" class="cart-link-btn">
                          <i class="fa fa-shopping-cart"></i> Thêm
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Pagination do JS render -->
      <div id="pagination-container"></div>

      <div class="view-more-container">
        <a href="menu.php" class="btn-view-more">Xem thêm</a>
      </div>
    </div>
  </section>

</div>

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
          <p>Delicious fast food made with love. Quality ingredients, great taste, and fast delivery.</p>
          
        </div>
      </div>
      <div class="col-md-4 footer-col">
        <h4>Opening Hours</h4>
        <p>Everyday</p>
        <p>10:00 AM - 10:00 PM</p>
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/js/jquery.nice-select.min.js"></script>
<script src="js/custom.js"></script>

<script>
  var currentPage = 1;
  var totalPages = <?= $totalPages ?>;
  var grid = document.getElementById('product-grid');
  var loader = document.getElementById('loader');
  var isLoggedIn = <?= json_encode($is_logged_in) ?>;

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

  function renderPagination(page, total) {
    if (total <= 1) { 
      var paginationContainer = document.getElementById('pagination-container');
      if (paginationContainer) paginationContainer.innerHTML = ''; 
      return; 
    }
    var html = '<nav><ul class="pagination justify-content-center mt-4">';
    html += '<li class="page-item ' + (page == 1 ? 'disabled' : '') + '">'
          + '<a class="page-link" href="#" onclick="loadProducts(' + (page-1) + ');return false;">Trước</a></li>';
    for (var i = 1; i <= total; i++) {
      html += '<li class="page-item ' + (i == page ? 'active' : '') + '">'
            + '<a class="page-link" href="#" onclick="loadProducts(' + i + ');return false;">' + i + '</a></li>';
    }
    html += '<li class="page-item ' + (page == total ? 'disabled' : '') + '">'
          + '<a class="page-link" href="#" onclick="loadProducts(' + (page+1) + ');return false;">Tiếp</a></li>';
    html += '</ul></nav>';
    document.getElementById('pagination-container').innerHTML = html;
  }

  function loadProducts(page) {
    if (page < 1 || page > totalPages) return;
    
    if (loader) loader.classList.add('show');
    grid.style.opacity = '0';
    grid.style.transition = 'opacity 0.3s ease';

    fetch('get_products.php?page=' + page)
      .then(function(res) { return res.json(); })
      .then(function(data) {
        var html = '';
        if (data.products && data.products.length > 0) {
          data.products.forEach(function(p) {
            var price = parseInt(p.selling_price).toLocaleString('vi-VN');
            html += '<div class="col-sm-6 col-lg-4"><div class="box">'
                  + '<a href="product_detail.php?id=' + p.id + '"><div class="img-box"><img src="' + p.image + '" alt="' + p.name + '"></div></a>'
                  + '<div class="detail-box"><h5>' + p.name + '</h5><p>' + (p.description || '') + '</p>'
                  + '<div class="options"><h6>' + price + 'đ</h6>';
            
            if (isLoggedIn) {
              html += '<button class="add-to-cart-btn" data-id="' + p.id + '" data-name="' + p.name.replace(/'/g, "\\'") + '" data-price="' + p.selling_price + '" data-image="' + p.image + '"><i class="fa fa-shopping-cart"></i> Thêm</button>';
            } else {
              html += '<a href="user/login.php" class="cart-link-btn"><i class="fa fa-shopping-cart"></i> Thêm</a>';
            }
            
            html += '</div></div></div></div>';
          });
        } else {
          html = '<div class="col-12 text-center">Không có sản phẩm nào.</div>';
        }

        grid.innerHTML = html;
        currentPage = data.currentPage;
        totalPages = data.totalPages;
        
        attachCartEvents();

        setTimeout(function() { 
          grid.style.opacity = '1';
          if (loader) loader.classList.remove('show');
        }, 200);

        renderPagination(data.currentPage, data.totalPages);

        var foodSection = document.querySelector('.food_section');
        if (foodSection) foodSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function(error) {
        console.error('Lỗi tải sản phẩm:', error);
        grid.style.opacity = '1';
        if (loader) loader.classList.remove('show');
        alert('Có lỗi xảy ra khi tải sản phẩm. Vui lòng thử lại.');
      });
  }

  function showToast(message) {
    var toast = document.getElementById('toast-message');
    toast.textContent = message;
    toast.style.display = 'block';
    setTimeout(function() {
      toast.style.display = 'none';
    }, 2000);
  }

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

  function addToCart(productId, name, price, image) {
    <?php if (!isset($_SESSION['user_id'])): ?>
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
    formData.append('quantity', 1);
    
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
        showToast(data.message || 'Có lỗi xảy ra, vui lòng thử lại!');
      }
    })
    .catch(function(error) {
      console.error('Error:', error);
      showToast('Có lỗi xảy ra, vui lòng thử lại!');
    });
  }

  function attachCartEvents() {
    var buttons = document.querySelectorAll('.add-to-cart-btn');
    buttons.forEach(function(button) {
      var newButton = button.cloneNode(true);
      button.parentNode.replaceChild(newButton, button);
      
      newButton.addEventListener('click', function(e) {
        e.preventDefault();
        var productId = this.getAttribute('data-id');
        var name = this.getAttribute('data-name');
        var price = this.getAttribute('data-price');
        var image = this.getAttribute('data-image');
        addToCart(productId, name, price, image);
      });
    });
  }

  renderPagination(currentPage, totalPages);
  attachCartEvents();
  
  var yearSpan = document.getElementById('displayYear');
  if (yearSpan) yearSpan.innerHTML = new Date().getFullYear();
</script>

</body>
</html>