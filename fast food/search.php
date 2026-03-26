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

  <title>Feane - Tìm kiếm nâng cao</title>

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
    
    /* ========== THANH TÌM KIẾM NÂNG CAO ========== */
    .advanced-search-section {
      background: linear-gradient(135deg, #1a1a2e 0%, #0f0f1a 100%);
      border-radius: 24px;
      padding: 35px;
      margin-bottom: 40px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
      border: 1px solid rgba(255, 190, 51, 0.2);
      position: relative;
      overflow: hidden;
    }
    
    .advanced-search-section::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #ffbe33, #e69c00, #ffbe33);
    }
    
    .search-header {
      text-align: center;
      margin-bottom: 35px;
    }
    
    .search-header h3 {
      color: #ffbe33;
      font-weight: 700;
      font-size: 28px;
      margin-bottom: 12px;
      letter-spacing: 1px;
    }
    
    .search-header h3 i {
      margin-right: 10px;
    }
    
    .search-header p {
      color: #aaa;
      font-size: 14px;
      margin: 0;
    }
    
    .search-header p i {
      color: #ffbe33;
      margin-right: 6px;
    }
    
    .search-row {
      display: flex;
      flex-wrap: wrap;
      gap: 25px;
      margin-bottom: 30px;
    }
    
    .search-group {
      flex: 1;
      min-width: 220px;
    }
    
    .search-group label {
      display: block;
      color: #ffbe33;
      font-weight: 700;
      margin-bottom: 12px;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    
    .search-group label i {
      margin-right: 8px;
      font-size: 14px;
    }
    
    /* Style cho input và select giống nhau */
    .search-input-wrapper {
      position: relative;
      width: 100%;
    }
    
    .search-input-wrapper i.input-icon {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: #ffbe33;
      font-size: 16px;
      z-index: 1;
      pointer-events: none;
    }
    
    .search-input-wrapper input,
    .search-input-wrapper select {
      width: 100%;
      background: #1e1e2a;
      border: 1px solid #2a2a3a;
      color: #fff;
      height: 54px;
      border-radius: 14px;
      padding: 0 16px 0 48px;
      font-size: 15px;
      transition: all 0.3s ease;
    }
    
    .search-input-wrapper select {
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
    }
    
    .search-input-wrapper input:hover,
    .search-input-wrapper select:hover {
      border-color: #ffbe33;
      background: #252535;
    }
    
    .search-input-wrapper input:focus,
    .search-input-wrapper select:focus {
      border-color: #ffbe33;
      outline: none;
      box-shadow: 0 0 0 3px rgba(255, 190, 51, 0.2);
      background: #252535;
    }
    
    .search-input-wrapper input::placeholder {
      color: #666;
    }
    
    .search-input-wrapper select option {
      background: #1e1e2a;
      color: #fff;
      padding: 12px;
    }
    
    .search-input-wrapper select option:hover,
    .search-input-wrapper select option:checked {
      background: #ffbe33;
      color: #1e1e2a;
    }
    
    /* Mũi tên cho select - style giống input */
    .select-arrow {
      position: absolute;
      right: 18px;
      top: 50%;
      transform: translateY(-50%);
      color: #ffbe33;
      pointer-events: none;
      font-size: 14px;
      transition: transform 0.3s ease;
    }
    
    .search-input-wrapper select:focus + .select-arrow,
    .search-input-wrapper select:hover + .select-arrow {
      transform: translateY(-50%) rotate(180deg);
    }
    
    /* Price Range Styles */
    .price-range-container {
      display: flex;
      gap: 15px;
      align-items: center;
    }
    
    .price-input {
      flex: 1;
      position: relative;
    }
    
    .price-input input {
      width: 100%;
      background: #1e1e2a;
      border: 1px solid #2a2a3a;
      color: #fff;
      height: 54px;
      border-radius: 14px;
      padding: 0 16px 0 45px;
      font-size: 15px;
      transition: all 0.3s ease;
    }
    
    .price-input input:focus {
      border-color: #ffbe33;
      outline: none;
      box-shadow: 0 0 0 3px rgba(255, 190, 51, 0.2);
    }
    
    .price-input input:hover {
      border-color: #ffbe33;
    }
    
    .price-input i {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: #ffbe33;
    }
    
    .price-separator {
      color: #ffbe33;
      font-weight: bold;
      font-size: 18px;
      background: rgba(255, 190, 51, 0.15);
      width: 44px;
      height: 44px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
    }
    
    .price-hint {
      margin-top: 10px;
      font-size: 11px;
      color: #888;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    
    .price-hint i {
      color: #ffbe33;
      font-size: 11px;
    }
    
    /* Search Actions */
    .search-actions {
      display: flex;
      gap: 18px;
      margin-top: 15px;
    }
    
    .btn-search-advanced {
      background: linear-gradient(135deg, #ffbe33 0%, #e69c00 100%);
      border: none;
      padding: 14px 35px;
      border-radius: 14px;
      color: #1a1a2e;
      font-weight: 700;
      font-size: 16px;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      flex: 1;
      justify-content: center;
    }
    
    .btn-search-advanced:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(255, 190, 51, 0.4);
    }
    
    .btn-reset-advanced {
      background: transparent;
      border: 1px solid #ffbe33;
      padding: 14px 30px;
      border-radius: 14px;
      color: #ffbe33;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
    }
    
    .btn-reset-advanced:hover {
      background: rgba(255, 190, 51, 0.1);
      transform: translateY(-2px);
      text-decoration: none;
      color: #ffbe33;
    }
    
    /* Active Filters Tags */
    .active-filters {
      margin-top: 28px;
      padding-top: 25px;
      border-top: 1px solid rgba(255, 190, 51, 0.2);
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
    }
    
    .active-filters-label {
      color: #aaa;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    
    .active-filters-label i {
      color: #ffbe33;
    }
    
    .filter-tag {
      background: rgba(255, 190, 51, 0.12);
      border: 1px solid rgba(255, 190, 51, 0.3);
      border-radius: 30px;
      padding: 6px 14px;
      font-size: 12px;
      color: #ffbe33;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    
    .filter-tag i {
      font-size: 11px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .filter-tag i:hover {
      color: #fff;
    }
    
    .clear-all-filters {
      background: rgba(220, 53, 69, 0.15);
      border: 1px solid rgba(220, 53, 69, 0.4);
      border-radius: 30px;
      padding: 6px 14px;
      font-size: 12px;
      color: #dc3545;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: all 0.2s;
    }
    
    .clear-all-filters:hover {
      background: rgba(220, 53, 69, 0.25);
      text-decoration: none;
      color: #dc3545;
    }
    
    /* ========== KẾT THÚC THANH TÌM KIẾM ========== */
    
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
      padding: 8px 18px;
      border-radius: 25px;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      color: #ffffff;
      font-weight: 600;
      font-size: 13px;
      text-decoration: none;
      font-family: inherit;
      line-height: 1;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
      min-width: 70px;
    }
    
    .add-to-cart-btn i {
      font-size: 12px;
      color: #ffffff;
    }
    
    .add-to-cart-btn:hover {
      background: #e69c00;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(255, 190, 51, 0.4);
      color: #ffffff;
      text-decoration: none;
    }
    
    .cart-link-btn {
      background: #ffbe33;
      border: none;
      padding: 8px 18px;
      border-radius: 25px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      color: #ffffff;
      font-weight: 600;
      font-size: 13px;
      text-decoration: none;
      transition: all 0.3s ease;
      min-width: 70px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .cart-link-btn:hover {
      background: #e69c00;
      transform: translateY(-2px);
      color: white;
      text-decoration: none;
    }
    
    .result-count {
      text-align: center;
      margin-bottom: 25px;
      color: #888;
      font-size: 14px;
    }
    
    .page-info {
      text-align: center;
      margin-top: 15px;
      color: #888;
      font-size: 13px;
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
    
    .detail-box p {
      color: white !important;
      line-height: 1.5;
      margin-bottom: 10px;
      font-size: 14px;
    }
    
    /* Empty State Styles */
    .empty-state {
      text-align: center;
      padding: 60px 20px;
    }
    
    .empty-state i {
      font-size: 64px;
      color: #ffbe33;
      margin-bottom: 20px;
      opacity: 0.7;
    }
    
    .empty-state h4 {
      color: #ffbe33;
      margin-bottom: 15px;
    }
    
    .empty-state p {
      color: #aaa;
      margin-bottom: 10px;
    }
    
    .btn-view-more {
      background: #ffbe33;
      color: #222;
      padding: 12px 30px;
      border-radius: 25px;
      text-decoration: none;
      font-weight: bold;
      transition: all 0.3s;
      display: inline-block;
    }
    
    .btn-view-more:hover {
      background: #e69c00;
      color: #222;
      text-decoration: none;
      transform: translateY(-2px);
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
      .search-row {
        flex-direction: column;
        gap: 20px;
      }
      .search-actions {
        flex-direction: column;
      }
      .advanced-search-section {
        padding: 20px;
      }
      .user-name {
        display: none;
      }
      .price-range-container {
        flex-direction: column;
      }
      .price-separator {
        display: none;
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
                  <div class="dropdown-divider"></div>
                  <a href="user/logout.php" class="dropdown-item-custom text-danger">
                    <i class="fa fa-sign-out"></i>
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

<!-- food section -->
<section class="food_section layout_padding">
  <div class="container">
    <div class="heading_container heading_center">
      <h2>
        Tìm kiếm nâng cao
      </h2>
    </div>

    <!-- ========== THANH TÌM KIẾM NÂNG CAO ========== -->
    <div class="advanced-search-section">
      <div class="search-header">
        <h3><i class="fa fa-sliders-h"></i> Bộ lọc tìm kiếm</h3>
        <p><i class="fa fa-info-circle"></i> Kết hợp nhiều tiêu chí để tìm kiếm chính xác món ăn bạn yêu thích</p>
      </div>
      
      <form action="search.php" method="GET" id="advancedSearchForm">
        <div class="search-row">
          <!-- Tiêu chí 1: Tên món ăn -->
          <div class="search-group">
            <label><i class="fa fa-cutlery"></i> TÊN MÓN ĂN</label>
            <div class="search-input-wrapper">
              <i class="fa fa-search input-icon"></i>
              <input type="text" name="query" placeholder="Nhập tên món ăn..." value="<?= htmlspecialchars($search_query) ?>">
            </div>
          </div>
          
          <!-- Tiêu chí 2: Danh mục - ĐÃ CHỈNH SỬA GIỐNG PHẦN TÊN MÓN ĂN -->
          <div class="search-group">
            <label><i class="fa fa-tags"></i> DANH MỤC</label>
            <div class="search-input-wrapper">
              
              <select name="category">
                <option value="all">-- Tất cả danh mục --</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              
            </div>
          </div>
        </div>
        
        <!-- Tiêu chí 3: Khoảng giá -->
        <div class="search-row">
          <div class="search-group">
            <label><i class="fa fa-money"></i> KHOẢNG GIÁ (NGHÌN ĐỒNG)</label>
            <div class="price-range-container">
              <div class="price-input">
                <i class="fa fa-dollar"></i>
                <input type="number" name="min_price" placeholder="Từ" value="<?= htmlspecialchars($display_min_price) ?>" step="1" min="0">
              </div>
              <span class="price-separator">—</span>
              <div class="price-input">
                <i class="fa fa-dollar"></i>
                <input type="number" name="max_price" placeholder="Đến" value="<?= htmlspecialchars($display_max_price) ?>" step="1" min="0">
              </div>
            </div>
            <div class="price-hint">
              <i class="fa fa-info-circle"></i>
              <span>Ví dụ: 50 = 50.000đ, 100 = 100.000đ</span>
            </div>
          </div>
        </div>
        
        <!-- Nút hành động -->
        <div class="search-actions">
          <button type="submit" class="btn-search-advanced">
            <i class="fa fa-search"></i> TÌM KIẾM
          </button>
          <a href="search.php" class="btn-reset-advanced">
            <i class="fa fa-refresh"></i> ĐẶT LẠI
          </a>
        </div>
        
        <!-- Hiển thị bộ lọc đang áp dụng -->
        <?php if ($is_advanced_search): ?>
        <div class="active-filters">
          <span class="active-filters-label">
            <i class="fa fa-filter"></i> Đang áp dụng:
          </span>
          
          <?php if (!empty($search_query)): ?>
          <span class="filter-tag">
            <i class="fa fa-cutlery"></i> Từ khóa: "<?= htmlspecialchars($search_query) ?>"
            <a href="javascript:void(0)" onclick="removeFilter('query')"><i class="fa fa-times-circle"></i></a>
          </span>
          <?php endif; ?>
          
          <?php if ($category_filter !== 'all'): 
            $cat_name = '';
            foreach ($categories as $cat) {
                if ($cat['id'] == $category_filter) {
                    $cat_name = $cat['name'];
                    break;
                }
            }
          ?>
          <span class="filter-tag">
            <i class="fa fa-tags"></i> Danh mục: <?= htmlspecialchars($cat_name) ?>
            <a href="javascript:void(0)" onclick="removeFilter('category')"><i class="fa fa-times-circle"></i></a>
          </span>
          <?php endif; ?>
          
          <?php if ($display_min_price !== '' || $display_max_price !== ''): ?>
          <span class="filter-tag">
            <i class="fa fa-money"></i> Giá: 
            <?php 
            if ($display_min_price !== '' && $display_max_price !== '') {
                echo number_format($display_min_price, 0, ',', '.') . 'k - ' . number_format($display_max_price, 0, ',', '.') . 'k';
            } elseif ($display_min_price !== '') {
                echo '≥ ' . number_format($display_min_price, 0, ',', '.') . 'k';
            } elseif ($display_max_price !== '') {
                echo '≤ ' . number_format($display_max_price, 0, ',', '.') . 'k';
            }
            ?>
            <a href="javascript:void(0)" onclick="removeFilter('price')"><i class="fa fa-times-circle"></i></a>
          </span>
          <?php endif; ?>
          
          <a href="search.php" class="clear-all-filters">
            <i class="fa fa-trash-o"></i> Xóa tất cả
          </a>
        </div>
        <?php endif; ?>
      </form>
    </div>
    <!-- ========== KẾT THÚC THANH TÌM KIẾM ========== -->

    <!-- Result count -->
    <div class="result-count">
      <i class="fa fa-cutlery"></i> Tìm thấy <strong style="color: #ffbe33;"><?php echo $total_products; ?></strong> sản phẩm 
      <?php if ($total_products > 0): ?>
        (Hiển thị <?php echo count($products); ?> sản phẩm)
      <?php endif; ?>
    </div>

    <div class="row">
      <!-- Kết quả tìm kiếm (full width) -->
      <div class="col-lg-12">
        <div class="filters-content">
          <div class="row grid" id="menu-items-container">
            <?php if (empty($products)): ?>
              <div class="col-12">
                <div class="empty-state">
                  <i class="fa fa-exclamation-circle"></i>
                  <h4>Không tìm thấy sản phẩm</h4>
                  <p>Không có kết quả phù hợp với tiêu chí tìm kiếm của bạn.</p>
                  <p style="color: #888; font-size: 14px;">Hãy thử thay đổi từ khóa hoặc khoảng giá khác.</p>
                  <a href="search.php" class="btn-view-more">
                    <i class="fa fa-refresh"></i> Xóa bộ lọc tìm kiếm
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
        
        <!-- Pagination controls -->
        <?php if ($total_pages > 1 && !empty($products)): ?>
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
        showToast('✓ Đã thêm ' + name + ' vào giỏ hàng!');
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
  
  // Remove filter function
  function removeFilter(filterType) {
    var url = new URL(window.location.href);
    if (filterType === 'query') {
      url.searchParams.delete('query');
    } else if (filterType === 'category') {
      url.searchParams.set('category', 'all');
    } else if (filterType === 'price') {
      url.searchParams.delete('min_price');
      url.searchParams.delete('max_price');
    }
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
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