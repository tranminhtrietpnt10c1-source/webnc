<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// Function to sanitize and validate input
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Get base URL for assets
function base_url($path = '') {
    // Detect base URL dynamically
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script_name = dirname($_SERVER['SCRIPT_NAME']);
    
    // Remove trailing slash if exists
    $base = rtrim($protocol . $host . $script_name, '/');
    
    if ($path) {
        return $base . '/' . ltrim($path, '/');
    }
    return $base;
}

// Get and validate current page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1

// Get and validate category slug
$category_slug = isset($_GET['category']) ? trim($_GET['category']) : 'all';
// Only allow alphanumeric, hyphens, and underscores
if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $category_slug) && $category_slug !== 'all') {
    $category_slug = 'all';
}

$limit = 6;
$offset = ($page - 1) * $limit;

try {
    // Get all categories from database with caching
    if (!isset($_SESSION['categories_cache']) || !isset($_SESSION['categories_cache_time']) || 
        (time() - $_SESSION['categories_cache_time'] > 3600)) {
        
        $categories_stmt = $pdo->query("SELECT id, name, status FROM categories WHERE status = 'active' ORDER BY id ASC");
        $_SESSION['categories_cache'] = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
        $_SESSION['categories_cache_time'] = time();
    }
    $categories = $_SESSION['categories_cache'];
} catch (PDOException $e) {
    error_log("Database error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Get category ID if slug is provided
$category_id = null;
$current_category_name = 'All';
$valid_category = true;

if ($category_slug !== 'all') {
    $found = false;
    foreach ($categories as $cat) {
        if (strtolower($cat['name']) === $category_slug) {
            $category_id = $cat['id'];
            $current_category_name = $cat['name'];
            $found = true;
            break;
        }
    }
    
    // If category not found, redirect to all products
    if (!$found) {
        header('Location: menu.php?category=all&page=1');
        exit;
    }
}

// Build query based on category
$where_clause = "WHERE p.status = 'active'";
$params = [];

if ($category_id !== null) {
    $where_clause .= " AND p.category_id = ?";
    $params[] = $category_id;
}

try {
    // Get total products count for current category
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products p $where_clause");
    $count_stmt->execute($params);
    $total_products = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_products / $limit));
    
    // Adjust page if out of range
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
        $offset = ($page - 1) * $limit;
    }
    
    // Get products for current page with safe LIMIT and OFFSET
    $sql = "SELECT p.id, p.name, p.description, p.image, p.category_id,
                   ROUND(p.cost_price * (1 + COALESCE(p.profit_percentage, 0)/100), 0) AS selling_price
            FROM products p
            $where_clause
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    
    // Bind category parameter if exists
    foreach ($params as $key => $value) {
        $stmt->bindValue($key + 1, $value);
    }
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error fetching products: " . $e->getMessage());
    $total_products = 0;
    $total_pages = 1;
    $products = [];
    
    // Show user-friendly error message
    $db_error = true;
}

// Get cart count efficiently
$cart_count = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    // Store cart count in session to avoid recalculation
    if (isset($_SESSION['cart_count'])) {
        $cart_count = $_SESSION['cart_count'];
    } else {
        $cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
        $_SESSION['cart_count'] = $cart_count;
    }
}

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']);

// Function to safely display product image
function getProductImage($image_path) {
    if (empty($image_path)) {
        return 'images/default-product.jpg';
    }
    
    // Check if file exists
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $image_path;
    if (!file_exists($full_path)) {
        return 'images/default-product.jpg';
    }
    
    return $image_path;
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
  <base href="<?php echo base_url(); ?>/">
  <link rel="shortcut icon" href="images/favicon.png" type="">
  
  <title>Feane - Menu <?php echo $category_slug !== 'all' ? '- ' . sanitizeInput($current_category_name) : ''; ?></title>

  <!-- bootstrap core css -->
  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  
  <!-- Ensure CSS files exist -->
  <?php if (!file_exists('css/bootstrap.css')): ?>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
  <?php endif; ?>

  <!-- owl slider stylesheet -->
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css" />
  <!-- nice select -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/css/nice-select.min.css" />
  <!-- font awesome style -->
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">

  <!-- Custom styles for this template -->
  <link href="css/style.css" rel="stylesheet" />
  <!-- responsive style -->
  <link href="css/responsive.css" rel="stylesheet" />

  <style>
    /* Fallback styles in case CSS doesn't load */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Open Sans', sans-serif;
      background-color: #f9f9f9;
    }
    
    .hero_area {
      min-height: auto !important;
      position: relative;
    }
    
    .bg-box {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: -1;
    }
    
    .bg-box img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    
    .header_section {
      background-color: #222831;
      padding: 15px 0;
    }
    
    .navbar-brand span {
      font-size: 24px;
      font-weight: bold;
      color: #ffbe33;
    }
    
    .nav-link {
      color: #fff !important;
      padding: 10px 15px !important;
    }
    
    .nav-link:hover {
      color: #ffbe33 !important;
    }
    
    .user_option {
      display: flex;
      align-items: center;
      gap: 15px;
    }
    
    .user_link, .cart_link {
      color: #fff;
      font-size: 20px;
    }
    
    .cart_link {
      position: relative;
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
    
    .order_online a {
      color: #fff;
      text-decoration: none;
      padding: 8px 20px;
      background-color: #ffbe33;
      border-radius: 30px;
    }
    
    .food_section {
      padding: 60px 0;
    }
    
    .heading_container {
      text-align: center;
      margin-bottom: 40px;
    }
    
    .heading_container h2 {
      font-size: 36px;
      font-weight: bold;
      color: #222831;
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
    
    .add-to-cart-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
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
    
    .box {
      background: white;
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
    
    .box .img-box {
      overflow: hidden;
      height: 200px;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: #f8f8f8;
    }
    
    .box .img-box img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.3s;
    }
    
    .box:hover .img-box img {
      transform: scale(1.05);
    }
    
    .box .detail-box {
      padding: 15px;
    }
    
    .box .detail-box h5 {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 10px;
    }
    
    .box .detail-box p {
      color: #666;
      font-size: 14px;
      line-height: 1.5;
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
    
    .error-message {
      background-color: #f8d7da;
      color: #721c24;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
      border: 1px solid #f5c6cb;
    }
    
    .btn-retry {
      background-color: #ffbe33;
      color: white;
      padding: 8px 20px;
      border-radius: 5px;
      text-decoration: none;
      display: inline-block;
      margin-top: 10px;
    }
    
    .btn-retry:hover {
      background-color: #e69c00;
      color: white;
    }
    
    .footer_section {
      background-color: #222831;
      color: #fff;
      padding: 60px 0 20px;
    }
    
    .footer_section h4 {
      margin-bottom: 20px;
    }
    
    .footer_section a {
      color: #fff;
      text-decoration: none;
    }
    
    .footer_section a:hover {
      color: #ffbe33;
    }
    
    @media (max-width: 768px) {
      .navbar-nav {
        text-align: center;
      }
      
      .user_option {
        justify-content: center;
        margin-top: 15px;
      }
      
      .box .img-box {
        height: 180px;
      }
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
    <img src="images/hero-bg.jpg" alt="Hero background" onerror="this.style.display='none'">
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
          <span class="navbar-toggler-icon">☰</span>
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
              <?php if ($is_logged_in): ?>
                <a class="nav-link" href="order_history.php">Order history</a>
              <?php else: ?>
                <a class="nav-link" href="user/login.php">Order history</a>
              <?php endif; ?>
            </li>
          </ul>
          <div class="user_option">
            <?php if ($is_logged_in): ?>
              <a href="user/profile.php" class="user_link">
                <i class="fa fa-user" aria-hidden="true"></i>
              </a>
            <?php else: ?>
              <a href="user/login.php" class="user_link">
                <i class="fa fa-user" aria-hidden="true"></i>
              </a>
            <?php endif; ?>
            <a class="cart_link" href="cart.php">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029" xml:space="preserve" width="20" height="20">
                <g><path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z" fill="white"/></g>
                <g><path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z" fill="white"/></g>
                <g><path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z" fill="white"/></g>
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
        <p>Danh mục: <strong><?php echo sanitizeInput($current_category_name); ?></strong></p>
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
          <a href="menu.php?category=<?php echo urlencode(strtolower($cat['name'])); ?>&page=1">
            <?php echo sanitizeInput($cat['name']); ?>
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
        <?php if (isset($db_error)): ?>
          <div class="col-12 text-center">
            <div class="error-message">
              <i class="fa fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 20px;"></i>
              <h4>Có lỗi xảy ra khi tải dữ liệu</h4>
              <p>Vui lòng thử lại sau hoặc liên hệ quản trị viên.</p>
              <a href="menu.php?category=<?php echo urlencode($category_slug); ?>&page=<?php echo $page; ?>" class="btn-retry">
                <i class="fa fa-refresh"></i> Thử lại
              </a>
            </div>
          </div>
        <?php elseif (empty($products)): ?>
          <div class="col-12 text-center">
            <div style="padding: 60px 0;">
              <i class="fa fa-exclamation-circle" style="font-size: 48px; color: #ffbe33; margin-bottom: 20px;"></i>
              <h4>Không có sản phẩm nào</h4>
              <p>Danh mục <?php echo sanitizeInput($current_category_name); ?> hiện chưa có sản phẩm.</p>
              <a href="menu.php?category=all&page=1" class="btn-view-more" style="display: inline-block; margin-top: 15px;">
                Xem tất cả sản phẩm
              </a>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($products as $product): ?>
            <div class="col-sm-6 col-lg-4">
              <div class="box">
                <a href="product_detail.php?id=<?php echo (int)$product['id']; ?>">
                  <div class="img-box">
                    <img src="<?php echo sanitizeInput(getProductImage($product['image'])); ?>" 
                         alt="<?php echo sanitizeInput($product['name']); ?>"
                         onerror="this.src='images/default-product.jpg'">
                  </div>
                </a>
                <div class="detail-box">
                  <h5><?php echo sanitizeInput($product['name']); ?></h5>
                  <p><?php echo sanitizeInput(substr($product['description'], 0, 100)) . (strlen($product['description']) > 100 ? '...' : ''); ?></p>
                  <div class="options">
                    <h6><?php echo number_format($product['selling_price'], 0, ',', '.'); ?>đ</h6>
                    <?php if ($is_logged_in): ?>
                      <button class="add-to-cart-btn" 
                              data-id="<?php echo (int)$product['id']; ?>"
                              data-name="<?php echo sanitizeInput($product['name']); ?>"
                              data-price="<?php echo (float)$product['selling_price']; ?>"
                              data-image="<?php echo sanitizeInput($product['image']); ?>">
                        <i class="fa fa-shopping-cart"></i> Thêm
                      </button>
                    <?php else: ?>
                      <a href="user/login.php?redirect=menu.php&category=<?php echo urlencode($category_slug); ?>&page=<?php echo $page; ?>" class="cart-link-btn">
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
    <?php if ($total_pages > 1 && !isset($db_error)): ?>
    <div class="pagination-container">
      <ul class="pagination">
        <!-- Nút Trang đầu -->
        <li>
          <?php if ($page > 1): ?>
            <a href="menu.php?category=<?php echo urlencode($category_slug); ?>&page=1" title="Trang đầu">
              <i class="fa fa-angle-double-left"></i>
            </a>
          <?php else: ?>
            <a href="#" class="disabled"><i class="fa fa-angle-double-left"></i></a>
          <?php endif; ?>
        </li>
        
        <!-- Nút Trước -->
        <li>
          <?php if ($page > 1): ?>
            <a href="menu.php?category=<?php echo urlencode($category_slug); ?>&page=<?php echo $page - 1; ?>" title="Trang trước">
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
            echo '<li><a href="menu.php?category=' . urlencode($category_slug) . '&page=1">1</a></li>';
            if ($start_page > 2) {
                echo '<li><a href="#" class="disabled">...</a></li>';
            }
        }
        
        // Hiển thị các trang trong khoảng
        for ($i = $start_page; $i <= $end_page; $i++):
        ?>
          <li>
            <a href="menu.php?category=<?php echo urlencode($category_slug); ?>&page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
              <?php echo $i; ?>
            </a>
          </li>
        <?php endfor; 
        
        // Hiển thị dấu ... và trang cuối nếu cần
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo '<li><a href="#" class="disabled">...</a></li>';
            }
            echo '<li><a href="menu.php?category=' . urlencode($category_slug) . '&page=' . $total_pages . '">' . $total_pages . '</a></li>';
        }
        ?>
        
        <!-- Nút Sau -->
        <li>
          <?php if ($page < $total_pages): ?>
            <a href="menu.php?category=<?php echo urlencode($category_slug); ?>&page=<?php echo $page + 1; ?>" title="Trang sau">
              Sau <i class="fa fa-angle-right"></i>
            </a>
          <?php else: ?>
            <a href="#" class="disabled">Sau <i class="fa fa-angle-right"></i></a>
          <?php endif; ?>
        </li>
        
        <!-- Nút Trang cuối -->
        <li>
          <?php if ($page < $total_pages): ?>
            <a href="menu.php?category=<?php echo urlencode($category_slug); ?>&page=<?php echo $total_pages; ?>" title="Trang cuối">
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
        | <i class="fa fa-tag"></i> Danh mục: <?php echo sanitizeInput($current_category_name); ?>
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
            <a href="#">
              <i class="fa fa-map-marker" aria-hidden="true"></i>
              <span>
                Location
              </span>
            </a>
            <a href="#">
              <i class="fa fa-phone" aria-hidden="true"></i>
              <span>
                Call +01 1234567890
              </span>
            </a>
            <a href="#">
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
            <a href="#">
              <i class="fa fa-facebook" aria-hidden="true"></i>
            </a>
            <a href="#">
              <i class="fa fa-twitter" aria-hidden="true"></i>
            </a>
            <a href="#">
              <i class="fa fa-linkedin" aria-hidden="true"></i>
            </a>
            <a href="#">
              <i class="fa fa-instagram" aria-hidden="true"></i>
            </a>
            <a href="#">
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
<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
<!-- popper js -->
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
<!-- bootstrap js -->
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
<!-- owl slider -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>
<!-- nice select -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/js/jquery.nice-select.min.js"></script>
<!-- font awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

<script>
// Toast notification function
function showToast(message, isError = false) {
    var toast = document.getElementById('toast-message');
    toast.textContent = message;
    
    // Change style for error messages
    if (isError) {
        toast.style.backgroundColor = '#dc3545';
    } else {
        toast.style.backgroundColor = '#28a745';
    }
    
    toast.style.display = 'block';
    setTimeout(function() {
        toast.style.display = 'none';
        // Reset color
        toast.style.backgroundColor = '#28a745';
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

// Show/hide loader
function showLoader(show) {
    var loader = document.getElementById('loader');
    if (show) {
        loader.classList.add('show');
    } else {
        loader.classList.remove('show');
    }
}

// Add to cart function
function addToCart(productId, name, price, image) {
    <?php if (!$is_logged_in): ?>
        if (confirm('Bạn cần đăng nhập để thêm sản phẩm vào giỏ hàng. Đăng nhập ngay?')) {
            var redirectUrl = 'user/login.php?redirect=menu.php&category=<?php echo urlencode($category_slug); ?>&page=<?php echo $page; ?>';
            window.location.href = redirectUrl;
        }
        return;
    <?php endif; ?>
    
    showLoader(true);
    
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
    .then(function(response) { 
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json(); 
    })
    .then(function(data) {
        showLoader(false);
        if (data.success) {
            showToast('Đã thêm ' + name + ' vào giỏ hàng!');
            if (data.cart_count !== undefined) {
                updateCartCount(data.cart_count);
            }
        } else {
            showToast(data.message || 'Có lỗi xảy ra, vui lòng thử lại!', true);
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        showLoader(false);
        showToast('Có lỗi xảy ra, vui lòng thử lại sau!', true);
    });
}

// Attach event listeners to add-to-cart buttons
function attachCartEvents() {
    var buttons = document.querySelectorAll('.add-to-cart-btn');
    buttons.forEach(function(button) {
        // Remove existing event listeners by cloning
        var newButton = button.cloneNode(true);
        button.parentNode.replaceChild(newButton, button);
        
        newButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Disable button temporarily to prevent double clicks
            this.disabled = true;
            
            var productId = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');
            var price = this.getAttribute('data-price');
            var image = this.getAttribute('data-image');
            
            addToCart(productId, name, price, image);
            
            // Re-enable button after 1 second
            setTimeout(function() {
                if (newButton) newButton.disabled = false;
            }, 1000);
        });
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    attachCartEvents();
    
    // Add smooth scroll for pagination links
    document.querySelectorAll('.pagination a').forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (!this.classList.contains('disabled')) {
                showLoader(true);
            }
        });
    });
    
    // Handle filter menu clicks
    document.querySelectorAll('.filters_menu a').forEach(function(link) {
        link.addEventListener('click', function(e) {
            showLoader(true);
        });
    });
    
    // Initialize Bootstrap dropdowns if needed
    if (typeof $ !== 'undefined') {
        $('.nice-select').niceSelect();
    }
});

// Display current year
if (document.getElementById('displayYear')) {
    document.getElementById('displayYear').innerHTML = new Date().getFullYear();
}
</script>

</body>
</html>