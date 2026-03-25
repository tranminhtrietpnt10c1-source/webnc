<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// 1. LẤY THAM SỐ TÌM KIẾM VÀ PHÂN TRANG
$search_query = isset($_GET['query']) ? trim($_GET['query']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] * 1000 : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] * 1000 : null;

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 6;
$offset = ($page - 1) * $limit;

// 2. LẤY DANH SÁCH DANH MỤC CHO BỘ LỌC
$categories_stmt = $pdo->query("SELECT * FROM categories WHERE status = 'active' ORDER BY id ASC");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. XÂY DỰNG TRUY VẤN SQL - KẾT HỢP NHIỀU TIÊU CHÍ
$where_clauses = ["p.status = 'active'"];
$params = [];

// Tìm kiếm theo tên sản phẩm (cơ bản)
if (!empty($search_query)) {
    $where_clauses[] = "p.name LIKE ?";
    $params[] = "%$search_query%";
}

// Lọc theo danh mục (nâng cao)
if ($category_filter !== 'all') {
    $where_clauses[] = "p.category_id = ?";
    $params[] = $category_filter;
}

// Công thức tính giá bán
$selling_price_sql = "(p.cost_price * (1 + p.profit_percentage/100))";

// Lọc theo khoảng giá (nâng cao) - đã chuyển đổi từ nghìn đồng sang VNĐ
if ($min_price !== null) {
    $where_clauses[] = "$selling_price_sql >= ?";
    $params[] = $min_price;
}
if ($max_price !== null) {
    $where_clauses[] = "$selling_price_sql <= ?";
    $params[] = $max_price;
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Tính tổng sản phẩm
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products p $where_sql");
$count_stmt->execute($params);
$total_products = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_products / $limit));

// Lấy danh sách sản phẩm
$sql = "SELECT p.id, p.name, p.description, p.image, p.category_id,
               $selling_price_sql AS selling_price 
        FROM products p 
        $where_sql 
        ORDER BY p.id DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Kiểm tra trạng thái đăng nhập và giỏ hàng
$is_logged_in = isset($_SESSION['user_id']);
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) { $cart_count += $item['quantity']; }
}

// Lấy thông tin user cho dropdown
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

// Xác định có đang áp dụng bộ lọc nâng cao không
$has_filters = ($category_filter !== 'all') || ($min_price !== null) || ($max_price !== null);
$is_advanced_search = !empty($search_query) || $has_filters;

// Lấy giá trị hiển thị cho input (theo nghìn đồng)
$display_min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : '';
$display_max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : '';
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

  <title>Feane - Tìm kiếm món ăn</title>

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
    /* Đồng bộ hoàn toàn CSS từ menu.php */
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
    
    /* Product card styles */
    .box {
      margin-bottom: 30px;
      transition: all 0.3s;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      background: #222;
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
      color: #fff;
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
    
    /* Quick Search Bar Styles */
    .quick-search-bar {
      max-width: 600px;
      margin: 20px auto 30px;
      position: relative;
    }
    
    .quick-search-bar .input-group {
      background: white;
      border-radius: 50px;
      overflow: hidden;
      box-shadow: 0 5px 20px rgba(0,0,0,0.1);
      transition: all 0.3s;
    }
    
    .quick-search-bar .input-group:hover {
      box-shadow: 0 8px 25px rgba(255, 190, 51, 0.2);
      transform: translateY(-2px);
    }
    
    .quick-search-bar .form-control {
      border: none;
      padding: 15px 25px;
      font-size: 16px;
      height: auto;
      background: white;
      color: #333;
    }
    
    .quick-search-bar .form-control:focus {
      box-shadow: none;
      outline: none;
    }
    
    .quick-search-bar .form-control::placeholder {
      color: #aaa;
      font-style: italic;
    }
    
    .quick-search-bar .btn-quick-search {
      background: #ffbe33;
      border: none;
      padding: 0 30px;
      color: white;
      font-weight: bold;
      transition: all 0.3s;
      border-radius: 0 50px 50px 0;
    }
    
    .quick-search-bar .btn-quick-search:hover {
      background: #e69c00;
      transform: scale(1.02);
    }
    
    .quick-search-bar .btn-quick-search i {
      font-size: 18px;
    }
    
    /* Filter Sidebar Styles - NỀN ĐEN, INPUT CHỮ ĐEN */
    .filter_sidebar { 
        background: #000000; 
        color: #ffffff; 
        padding: 35px 30px; 
        border-radius: 15px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.5); 
        margin-bottom: 30px;
        border: 1px solid #333;
    }
    
    .filter_sidebar h4 { 
        color: #ffbe33; 
        font-weight: bold; 
        margin-bottom: 25px; 
        text-transform: uppercase; 
        text-align: center; 
        border-bottom: 2px solid #ffbe33;
        display: inline-block;
        width: auto;
        padding-bottom: 10px;
    }
    
    /* TĂNG KHOẢNG CÁCH GIỮA CÁC FORM GROUP */
    .filter_sidebar .form-group {
        margin-bottom: 35px;
    }
    
    /* ĐẶC BIỆT CHO FORM GROUP CUỐI CÙNG (KHOẢNG GIÁ) */
    .filter_sidebar .form-group:last-of-type {
        margin-bottom: 25px;
    }
    
    .filter_sidebar label {
        color: #ffffff;
        margin-bottom: 12px;
        font-weight: 500;
        display: block;
        font-size: 14px;
    }
    
    /* INPUT VÀ SELECT - CHỮ MÀU ĐEN, NỀN TRẮNG - CĂN CHỈNH ĐỀU */
    .filter_sidebar .form-control-lg { 
        background: #ffffff !important;
        border: 1px solid #ddd !important;
        color: #000000 !important;
        height: 50px; 
        width: 100%;
        font-size: 0.95rem; 
        border-radius: 8px;
        padding: 12px 15px;
        box-sizing: border-box;
    }
    
    .filter_sidebar .form-control-lg:focus {
        border-color: #ffbe33 !important;
        box-shadow: 0 0 0 0.2rem rgba(255, 190, 51, 0.25);
    }
    
    .filter_sidebar .form-control-lg::placeholder {
        color: #999;
    }
    
    .filter_sidebar select.form-control-lg {
        cursor: pointer;
        background: #ffffff !important;
        color: #000000 !important;
        padding: 12px 15px;
        width: 100%;
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%23333" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
        background-repeat: no-repeat;
        background-position: right 15px center;
        background-size: 14px;
    }
    
    .filter_sidebar select.form-control-lg option {
        background: #ffffff;
        color: #000000;
    }
    
    /* Price Range Styles - Hiển thị theo cột dọc với khoảng cách đẹp */
    .price-range-wrapper {
        display: flex;
        flex-direction: column;
        gap: 15px;
        width: 100%;
        margin-bottom: 5px;
    }
    
    .price-input-group {
        width: 100%;
    }
    
    .price-input-group input {
        width: 100%;
        text-align: left;
    }
    
    .price-separator {
        text-align: center;
        position: relative;
        margin: 5px 0;
    }
    
    .price-separator span {
        background: #ffbe33;
        color: #000;
        padding: 4px 20px;
        border-radius: 25px;
        font-size: 13px;
        font-weight: 600;
        display: inline-block;
        letter-spacing: 1px;
    }
    
    .price-unit {
        font-size: 11px;
        color: #888;
        margin-top: 12px;
        display: block;
        text-align: center;
    }
    
    .btn-filter { 
        background: #ffbe33; 
        color: #222; 
        border: none; 
        font-weight: bold; 
        padding: 14px; 
        font-size: 1rem; 
        border-radius: 8px; 
        transition: 0.3s; 
        margin-top: 10px;
        cursor: pointer;
    }
    
    .btn-filter:hover { 
        background: #e69c00; 
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(255, 190, 51, 0.3);
    }
    
    .btn-reset {
        background: transparent;
        color: #ffbe33;
        border: 1px solid #ffbe33;
        padding: 10px;
        font-size: 0.9rem;
        border-radius: 8px;
        transition: 0.3s;
        margin-top: 15px;
        width: 100%;
        text-align: center;
        display: inline-block;
        text-decoration: none;
    }
    
    .btn-reset:hover {
        background: #ffbe33;
        color: #222;
        text-decoration: none;
    }
    
    .search-info {
        background: rgba(255, 190, 51, 0.1);
        padding: 12px 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        text-align: center;
        border-left: 4px solid #ffbe33;
    }
    
    .search-info p {
        margin: 0;
        color: #ddd;
        font-size: 14px;
    }
    
    .search-info strong {
        color: #ffbe33;
    }
    
    .detail-box p {
      color: white !important;
      line-height: 1.5;
      margin-bottom: 10px;
      font-size: 14px;
    }
    
    @media (max-width: 768px) {
      .user-name {
        display: none;
      }
      .dropdown-menu-custom {
        right: -50px;
      }
      .price-range-wrapper {
        gap: 12px;
      }
      .quick-search-bar {
        margin: 15px 20px 25px;
      }
      .quick-search-bar .form-control {
        padding: 12px 20px;
        font-size: 14px;
      }
      .quick-search-bar .btn-quick-search {
        padding: 0 20px;
      }
      .filter_sidebar {
        padding: 25px 20px;
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
    <img src="images/hero-bg.jpg" alt="">
  </div>
  <!-- header section strats - GIỐNG MENU.PHP -->
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
        Tìm kiếm món ăn
      </h2>
    </div>

    <!-- THANH TÌM KIẾM NHANH -->
    <div class="quick-search-bar">
      <form action="search.php" method="GET" id="quickSearchForm">
        <div class="input-group">
          <input type="text" name="query" class="form-control" 
                 placeholder="🔍 Nhập tên món ăn bạn muốn tìm..." 
                 value="<?= htmlspecialchars($search_query) ?>">
          <button class="btn-quick-search" type="submit">
            <i class="fa fa-search"></i> Tìm
          </button>
        </div>
      </form>
    </div>

    <!-- Hiển thị thông tin tìm kiếm đang áp dụng -->
    <?php if ($is_advanced_search): ?>
    <div class="search-info">
      <p>
        <i class="fa fa-search" style="color: #ffbe33;"></i> 
        Kết quả tìm kiếm cho: 
        <?php if (!empty($search_query)): ?>
          <strong>"<?php echo htmlspecialchars($search_query); ?>"</strong>
        <?php endif; ?>
        <?php if ($category_filter !== 'all'): ?>
          <?php 
            $cat_name = '';
            foreach ($categories as $cat) {
                if ($cat['id'] == $category_filter) {
                    $cat_name = $cat['name'];
                    break;
                }
            }
          ?>
          <strong><?php echo htmlspecialchars($cat_name); ?></strong>
        <?php endif; ?>
        <?php if ($min_price !== null || $max_price !== null): ?>
          <strong>
            <?php 
            if ($min_price !== null && $max_price !== null) {
                echo number_format($min_price/1000, 0, ',', '.') . 'k - ' . number_format($max_price/1000, 0, ',', '.') . 'k';
            } elseif ($min_price !== null) {
                echo '≥ ' . number_format($min_price/1000, 0, ',', '.') . 'k';
            } elseif ($max_price !== null) {
                echo '≤ ' . number_format($max_price/1000, 0, ',', '.') . 'k';
            }
            ?>
          </strong>
        <?php endif; ?>
      </p>
    </div>
    <?php endif; ?>

    <!-- Result count -->
    <div class="result-count">
      <i class="fa fa-cutlery"></i> Tìm thấy <?php echo $total_products; ?> sản phẩm 
      <?php if ($total_products > 0): ?>
        (Hiển thị <?php echo count($products); ?> sản phẩm)
      <?php endif; ?>
    </div>

    <div class="row">
      <!-- Khung tìm kiếm nâng cao - NỀN ĐEN CHỮ TRẮNG, INPUT CHỮ ĐEN -->
      <div class="col-lg-4">
        <div class="filter_sidebar">
          <div style="text-align: center;">
            <h4>TÌM KIẾM NÂNG CAO</h4>
          </div>
          <form action="search.php" method="GET" id="advancedSearchForm">
            <!-- Tiêu chí 1: Tên món ăn -->
            <div class="form-group">
              <label><i class="fa fa-cutlery"></i> Tên món ăn</label>
              <input type="text" name="query" class="form-control form-control-lg" 
                     placeholder="Nhập tên món ăn..." 
                     value="<?= htmlspecialchars($search_query) ?>">
            </div>
            
            <!-- Tiêu chí 2: Phân loại danh mục -->
            <div class="form-group">
              <label><i class="fa fa-tags"></i> Phân loại</label>
              <select name="category" class="form-control form-control-lg">
                <option value="all">-- Tất cả danh mục --</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <!-- Tiêu chí 3: Khoảng giá -->
            <div class="form-group">
              <label><i class="fa fa-money"></i> Khoảng giá</label>
              <div class="price-range-wrapper">
                <div class="price-input-group">
                  <input type="number" name="min_price" class="form-control form-control-lg" 
                         placeholder="Giá từ (nghìn đồng)" value="<?= htmlspecialchars($display_min_price) ?>" step="1" min="0">
                </div>
                <div class="price-separator">
                  <span>đến</span>
                </div>
                <div class="price-input-group">
                  <input type="number" name="max_price" class="form-control form-control-lg" 
                         placeholder="Giá đến (nghìn đồng)" value="<?= htmlspecialchars($display_max_price) ?>" step="1" min="0">
                </div>
              </div>
              <small class="price-unit">
                <i class="fa fa-info-circle"></i> Nhập giá theo nghìn đồng (Ví dụ: 50 = 50.000đ)
              </small>
            </div>
            
            <!-- Nút tìm kiếm -->
            <button type="submit" class="btn btn-filter w-100">
              <i class="fa fa-search"></i> TÌM KIẾM
            </button>
            
            <!-- Nút đặt lại bộ lọc -->
            <a href="search.php" class="btn-reset">
              <i class="fa fa-refresh"></i> Đặt lại tất cả
            </a>
          </form>
        </div>
        
        <!-- Gợi ý tìm kiếm -->
        <div style="background: rgba(255, 190, 51, 0.05); padding: 15px; border-radius: 10px; margin-top: 15px;">
          <p style="color: #ffbe33; margin-bottom: 10px; font-size: 13px;">
            <i class="fa fa-lightbulb-o"></i> <strong>Gợi ý:</strong>
          </p>
          <p style="color: #aaa; font-size: 12px; margin-bottom: 5px;">
            • Tìm kiếm nhanh: Nhập tên món vào thanh tìm kiếm phía trên
          </p>
          <p style="color: #aaa; font-size: 12px; margin-bottom: 5px;">
            • Tìm kiếm nâng cao: Kết hợp tên món + phân loại + khoảng giá
          </p>
          <p style="color: #aaa; font-size: 12px;">
            • Giá nhập theo nghìn đồng, hệ thống sẽ tự động chuyển đổi
          </p>
        </div>
      </div>

      <!-- Kết quả tìm kiếm -->
      <div class="col-lg-8">
        <div class="filters-content">
          <div class="row grid" id="menu-items-container">
            <?php if (empty($products)): ?>
              <div class="col-12 text-center">
                <div style="padding: 60px 0;">
                  <i class="fa fa-exclamation-circle" style="font-size: 48px; color: #ffbe33; margin-bottom: 20px;"></i>
                  <h4>Không tìm thấy sản phẩm</h4>
                  <p>Không có kết quả phù hợp với tiêu chí tìm kiếm của bạn.</p>
                  <p style="color: #888; font-size: 14px;">Hãy thử thay đổi từ khóa hoặc khoảng giá khác.</p>
                  <a href="search.php" class="btn-view-more" style="display: inline-block; margin-top: 15px;">
                    <i class="fa fa-refresh"></i> Xóa bộ lọc tìm kiếm
                  </a>
                </div>
              </div>
            <?php else: ?>
              <?php foreach ($products as $product): ?>
                <div class="col-sm-6 col-lg-6">
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
        
        <!-- Pagination controls -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
          <ul class="pagination">
            <li>
              <?php if ($page > 1): ?>
                <a href="search.php?query=<?php echo urlencode($search_query); ?>&category=<?php echo $category_filter; ?>&min_price=<?php echo $display_min_price; ?>&max_price=<?php echo $display_max_price; ?>&page=1" title="Trang đầu">
                  <i class="fa fa-angle-double-left"></i>
                </a>
              <?php else: ?>
                <a href="#" class="disabled"><i class="fa fa-angle-double-left"></i></a>
              <?php endif; ?>
            </li>
            
            <li>
              <?php if ($page > 1): ?>
                <a href="search.php?query=<?php echo urlencode($search_query); ?>&category=<?php echo $category_filter; ?>&min_price=<?php echo $display_min_price; ?>&max_price=<?php echo $display_max_price; ?>&page=<?php echo $page - 1; ?>" title="Trang trước">
                  <i class="fa fa-angle-left"></i> Trước
                </a>
              <?php else: ?>
                <a href="#" class="disabled"><i class="fa fa-angle-left"></i> Trước</a>
              <?php endif; ?>
            </li>
            
            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            if ($start_page > 1) {
                echo '<li><a href="search.php?query=' . urlencode($search_query) . '&category=' . $category_filter . '&min_price=' . $display_min_price . '&max_price=' . $display_max_price . '&page=1">1</a></li>';
                if ($start_page > 2) {
                    echo '<li><a href="#" class="disabled">...</a></li>';
                }
            }
            
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
              <li>
                <a href="search.php?query=<?php echo urlencode($search_query); ?>&category=<?php echo $category_filter; ?>&min_price=<?php echo $display_min_price; ?>&max_price=<?php echo $display_max_price; ?>&page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                  <?php echo $i; ?>
                </a>
              </li>
            <?php endfor; 
            
            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) {
                    echo '<li><a href="#" class="disabled">...</a></li>';
                }
                echo '<li><a href="search.php?query=' . urlencode($search_query) . '&category=' . $category_filter . '&min_price=' . $display_min_price . '&max_price=' . $display_max_price . '&page=' . $total_pages . '">' . $total_pages . '</a></li>';
            }
            ?>
            
            <li>
              <?php if ($page < $total_pages): ?>
                <a href="search.php?query=<?php echo urlencode($search_query); ?>&category=<?php echo $category_filter; ?>&min_price=<?php echo $display_min_price; ?>&max_price=<?php echo $display_max_price; ?>&page=<?php echo $page + 1; ?>" title="Trang sau">
                  Sau <i class="fa fa-angle-right"></i>
                </a>
              <?php else: ?>
                <a href="#" class="disabled">Sau <i class="fa fa-angle-right"></i></a>
              <?php endif; ?>
            </li>
            
            <li>
              <?php if ($page < $total_pages): ?>
                <a href="search.php?query=<?php echo urlencode($search_query); ?>&category=<?php echo $category_filter; ?>&min_price=<?php echo $display_min_price; ?>&max_price=<?php echo $display_max_price; ?>&page=<?php echo $total_pages; ?>" title="Trang cuối">
                  <i class="fa fa-angle-double-right"></i>
                </a>
              <?php else: ?>
                <a href="#" class="disabled"><i class="fa fa-angle-double-right"></i></a>
              <?php endif; ?>
            </li>
          </ul>
        </div>
        
        <div class="page-info">
          <i class="fa fa-info-circle"></i> Trang <?php echo $page; ?> / <?php echo $total_pages; ?> | 
          <i class="fa fa-cutlery"></i> Tổng số: <?php echo $total_products; ?> sản phẩm
        </div>
        <?php endif; ?>
      </div>
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