<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// Get current page and category from URL
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$category_slug = isset($_GET['category']) ? $_GET['category'] : 'all';
$limit = 6;
$offset = ($page - 1) * $limit;

// Get all categories from database
$categories_stmt = $pdo->query("SELECT * FROM categories WHERE status = 'active' ORDER BY id ASC");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get category ID if slug is provided
$category_id = null;
$current_category_name = 'All';
if ($category_slug !== 'all') {
    foreach ($categories as $cat) {
        if (strtolower($cat['name']) === $category_slug) {
            $category_id = $cat['id'];
            $current_category_name = $cat['name'];
            break;
        }
    }
}

// Build query based on category
$where_clause = "WHERE p.status = 'active'";
$params = [];

if ($category_id !== null) {
    $where_clause .= " AND p.category_id = ?";
    $params[] = $category_id;
}

// Get total products count for current category
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products p $where_clause");
$count_stmt->execute($params);
$total_products = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_products / $limit));

// Adjust page if out of range
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// Get products for current page
$sql = "SELECT p.id, p.name, p.description, p.image, p.category_id,
               (p.cost_price * (1 + p.profit_percentage/100)) AS selling_price
        FROM products p
        $where_clause
        ORDER BY p.id DESC
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get cart count
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']);

// Lấy thông tin user cho dropdown (nếu đã đăng nhập)
$user_info = null;
if ($is_logged_in) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Bỏ qua
    }
}
?>

<!DOCTYPE html>
<html>

<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <meta name="keywords" content="" />
  <meta name="description" content="" />
  <meta name="author" content="" />
  <link rel="shortcut icon" href="images/favicon.png" type="">

  <title>Feane - Menu <?php echo $category_slug !== 'all' ? '- ' . $current_category_name : ''; ?></title>

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
    .hero_area {
      min-height: auto !important;
    }
    
    .pagination-container {
      display: flex;
      justify-content: center;
      margin-top: 40px;
    }
    
    .pagination {
      display: flex;
      list-style: none;
      padding: 0;
      flex-wrap: wrap;
      justify-content: center;
    }
    
    .pagination li {
      margin: 5px;
    }
    
    .pagination a {
      display: block;
      padding: 8px 15px;
      border: 1px solid #ffbe33;
      border-radius: 5px;
      text-decoration: none;
      color: #ffbe33;
      transition: all 0.3s;
      font-weight: 500;
    }
    
    .pagination a:hover,
    .pagination a.active {
      background-color: #ffbe33;
      color: white;
      border-color: #ffbe33;
    }
    
    .pagination a.disabled {
      pointer-events: none;
      opacity: 0.5;
      background-color: #f5f5f5;
      border-color: #ddd;
      color: #999;
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
    
    /* Add to Cart Button Styles */
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
    
    .view-more-container {
      display: flex;
      justify-content: center;
      margin-top: 40px;
    }
    
    .btn-view-more {
      background-color: #ffbe33;
      color: white;
      padding: 12px 30px;
      border-radius: 5px;
      text-decoration: none;
      font-weight: bold;
      transition: all 0.3s;
      display: inline-block;
    }
    
    .btn-view-more:hover {
      background-color: #e69c00;
      color: white;
      text-decoration: none;
    }
    
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
    
    /* Category filter styles */
    .filters_menu {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      list-style: none;
      padding: 0;
      margin-bottom: 30px;
    }
    
    .filters_menu li {
      margin: 0 10px 10px;
    }
    
    .filters_menu li a {
      display: inline-block;
      padding: 8px 20px;
      border-radius: 30px;
      background-color: #f5f5f5;
      color: #333;
      text-decoration: none;
      transition: all 0.3s;
      font-weight: 500;
    }
    
    .filters_menu li.active a,
    .filters_menu li a:hover {
      background-color: #ffbe33;
      color: white;
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
    
    /* Category info */
    .category-info {
      text-align: center;
      margin-bottom: 20px;
    }
    
    .category-info p {
      color: #666;
      font-size: 14px;
    }
    
    .result-count {
      text-align: center;
      margin-bottom: 20px;
      color: #888;
      font-size: 14px;
    }
    
    .page-info {
      text-align: center;
      margin-top: 15px;
      color: #888;
      font-size: 13px;
    }
    
    /* User dropdown styles - giống order_history */
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
    }

    /* Description styling */
    /* Description styling */
    .detail-box p {
      color: white !important;
      line-height: 1.5;
      margin-bottom: 10px;
      font-size: 14px;
    }
  </style>
</head>

<body class="sub_page">

<div id="loader" class="loader">
  <div class="spinner"></div>
</div>

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

<!-- food section -->
<section class="food_section layout_padding">
  <div class="container">
    <div class="heading_container heading_center">
      <h2>
        Our Menu
      </h2>
      <?php if ($category_slug !== 'all'): ?>
      <div class="category-info">
        <p>Danh mục: <strong><?php echo htmlspecialchars($current_category_name); ?></strong></p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Category Filter Menu from database -->
    <ul class="filters_menu">
      <li class="<?php echo $category_slug == 'all' ? 'active' : ''; ?>">
        <a href="menu.php?category=all&page=1">All</a>
      </li>
      <?php foreach ($categories as $cat): ?>
        <li class="<?php echo $category_slug == strtolower($cat['name']) ? 'active' : ''; ?>">
          <a href="menu.php?category=<?php echo strtolower($cat['name']); ?>&page=1">
            <?php echo htmlspecialchars($cat['name']); ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Result count -->
    <div class="result-count">
      <i class="fa fa-cutlery"></i> Hiển thị <?php echo count($products); ?> / <?php echo $total_products; ?> sản phẩm
    </div>

    <div class="filters-content">
      <div class="row grid" id="menu-items-container">
        <?php if (empty($products)): ?>
          <div class="col-12 text-center">
            <div style="padding: 60px 0;">
              <i class="fa fa-exclamation-circle" style="font-size: 48px; color: #ffbe33; margin-bottom: 20px;"></i>
              <h4>Không có sản phẩm nào</h4>
              <p>Danh mục <?php echo htmlspecialchars($current_category_name); ?> hiện chưa có sản phẩm.</p>
              <a href="menu.php?category=all&page=1" class="btn-view-more" style="display: inline-block; margin-top: 15px;">
                Xem tất cả sản phẩm
              </a>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($products as $product): ?>
            <div class="col-sm-6 col-lg-4">
              <div class="box">
                <a href="product_detail.php?id=<?php echo $product['id']; ?>">
                  <div class="img-box">
                    <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                  </div>
                </a>
                <div class="detail-box">
                  <h5><?php echo htmlspecialchars($product['name']); ?></h5>
                  <p><?php echo htmlspecialchars($product['description']); ?></p>
                  <div class="options">
                    <h6><?php echo number_format($product['selling_price'], 0, ',', '.'); ?>đ</h6>
                    <?php if ($is_logged_in): ?>
                      <button class="add-to-cart-btn" 
                              data-id="<?php echo $product['id']; ?>"
                              data-name="<?php echo htmlspecialchars($product['name']); ?>"
                              data-price="<?php echo $product['selling_price']; ?>"
                              data-image="<?php echo htmlspecialchars($product['image']); ?>">
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
    
    <!-- Pagination controls - chỉ hiển thị khi có nhiều hơn 1 trang -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination-container">
      <ul class="pagination">
        <!-- Nút Trang đầu -->
        <li>
          <?php if ($page > 1): ?>
            <a href="menu.php?category=<?php echo $category_slug; ?>&page=1" title="Trang đầu">
              <i class="fa fa-angle-double-left"></i>
            </a>
          <?php else: ?>
            <a href="#" class="disabled"><i class="fa fa-angle-double-left"></i></a>
          <?php endif; ?>
        </li>
        
        <!-- Nút Trước -->
        <li>
          <?php if ($page > 1): ?>
            <a href="menu.php?category=<?php echo $category_slug; ?>&page=<?php echo $page - 1; ?>" title="Trang trước">
              <i class="fa fa-angle-left"></i> Trước
            </a>
          <?php else: ?>
            <a href="#" class="disabled"><i class="fa fa-angle-left"></i> Trước</a>
          <?php endif; ?>
        </li>
        
        <!-- Các số trang - hiển thị thông minh -->
        <?php 
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        // Hiển thị trang 1 và dấu ... nếu cần
        if ($start_page > 1) {
            echo '<li><a href="menu.php?category=' . $category_slug . '&page=1">1</a></li>';
            if ($start_page > 2) {
                echo '<li><a href="#" class="disabled">...</a></li>';
            }
        }
        
        // Hiển thị các trang trong khoảng
        for ($i = $start_page; $i <= $end_page; $i++):
        ?>
          <li>
            <a href="menu.php?category=<?php echo $category_slug; ?>&page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
              <?php echo $i; ?>
            </a>
          </li>
        <?php endfor; 
        
        // Hiển thị dấu ... và trang cuối nếu cần
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo '<li><a href="#" class="disabled">...</a></li>';
            }
            echo '<li><a href="menu.php?category=' . $category_slug . '&page=' . $total_pages . '">' . $total_pages . '</a></li>';
        }
        ?>
        
        <!-- Nút Sau -->
        <li>
          <?php if ($page < $total_pages): ?>
            <a href="menu.php?category=<?php echo $category_slug; ?>&page=<?php echo $page + 1; ?>" title="Trang sau">
              Sau <i class="fa fa-angle-right"></i>
            </a>
          <?php else: ?>
            <a href="#" class="disabled">Sau <i class="fa fa-angle-right"></i></a>
          <?php endif; ?>
        </li>
        
        <!-- Nút Trang cuối -->
        <li>
          <?php if ($page < $total_pages): ?>
            <a href="menu.php?category=<?php echo $category_slug; ?>&page=<?php echo $total_pages; ?>" title="Trang cuối">
              <i class="fa fa-angle-double-right"></i>
            </a>
          <?php else: ?>
            <a href="#" class="disabled"><i class="fa fa-angle-double-right"></i></a>
          <?php endif; ?>
        </li>
      </ul>
    </div>
    
    <!-- Thông tin trang hiện tại -->
    <div class="page-info">
      <i class="fa fa-info-circle"></i> Trang <?php echo $page; ?> / <?php echo $total_pages; ?> | 
      <i class="fa fa-cutlery"></i> Tổng số: <?php echo $total_products; ?> sản phẩm
      <?php if ($category_slug !== 'all'): ?>
        | <i class="fa fa-tag"></i> Danh mục: <?php echo htmlspecialchars($current_category_name); ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="view-more-container">
      <a href="menu.php?category=all&page=1" class="btn-view-more">
        <i class="fa fa-list"></i> Xem tất cả sản phẩm
      </a>
    </div>
  </div>
</section>
<!-- end food section -->

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
  
  // Toast notification function
  function showToast(message) {
    var toast = document.getElementById('toast-message');
    toast.textContent = message;
    toast.style.display = 'block';
    setTimeout(function() {
      toast.style.display = 'none';
    }, 2000);
  }

  // Update cart count
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

  // Add to cart function
  function addToCart(productId, name, price, image) {
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
        showToast('Có lỗi xảy ra, vui lòng thử lại!');
      }
    })
    .catch(function(error) {
      console.error('Error:', error);
      showToast('Có lỗi xảy ra, vui lòng thử lại!');
    });
  }

  // Attach event listeners to add-to-cart buttons
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

  // Initialize on page load
  document.addEventListener('DOMContentLoaded', function() {
    attachCartEvents();
  });

  // Display current year
  document.getElementById('displayYear').innerHTML = new Date().getFullYear();
</script>

</body>
</html>