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
    
    /* Add to Cart Button Styles - Giống index.php */
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
    
    .add-to-cart-btn:active {
      transform: translateY(0);
    }
    
    /* Force override any conflicting styles */
    .box .options .add-to-cart-btn,
    .box .options button.add-to-cart-btn {
      background: #ffbe33 !important;
      border: none !important;
      padding: 8px 18px !important;
      border-radius: 25px !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 6px !important;
      color: #ffffff !important;
      font-weight: 600 !important;
      font-size: 13px !important;
      text-decoration: none !important;
      font-family: inherit !important;
      cursor: pointer !important;
      min-width: 70px !important;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1) !important;
    }
    
    .box .options .add-to-cart-btn i,
    .box .options button.add-to-cart-btn i {
      font-size: 12px !important;
      color: #ffffff !important;
    }
    
    .box .options .add-to-cart-btn:hover,
    .box .options button.add-to-cart-btn:hover {
      background: #e69c00 !important;
      transform: translateY(-2px) !important;
      color: #ffffff !important;
      text-decoration: none !important;
    }
    
    /* Cart link button for non-logged in users */
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
    
    .cart-link-btn i {
      font-size: 12px;
    }
    
    .cart-link-btn:hover {
      background: #e69c00;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(255, 190, 51, 0.4);
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
    
    /* Enhanced Filter Sidebar Styles - Thiết kế mới */
    .filter_sidebar { 
      background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%);
      color: #ffffff; 
      padding: 35px 30px; 
      border-radius: 20px; 
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
      margin-bottom: 30px;
      border: 1px solid rgba(255, 190, 51, 0.2);
      transition: all 0.3s ease;
    }
    
    .filter_sidebar:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 40px rgba(0,0,0,0.4);
      border-color: rgba(255, 190, 51, 0.4);
    }
    
    .filter-header {
      text-align: center;
      margin-bottom: 35px;
      position: relative;
    }
    
    .filter-header i {
      font-size: 32px;
      color: #ffbe33;
      display: block;
      margin-bottom: 12px;
    }
    
    .filter-header h4 { 
      color: #ffbe33; 
      font-weight: bold; 
      margin: 0;
      text-transform: uppercase;
      font-size: 20px;
      letter-spacing: 1px;
      position: relative;
      display: inline-block;
      padding-bottom: 10px;
    }
    
    .filter-header h4:after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 50px;
      height: 3px;
      background: #ffbe33;
    }
    
    .filter_sidebar .form-group {
      margin-bottom: 25px;
    }
    
    .filter_sidebar label {
      color: #ffffff;
      margin-bottom: 12px;
      font-weight: 600;
      display: block;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .filter_sidebar label i {
      color: #ffbe33;
      margin-right: 10px;
      font-size: 14px;
    }
    
    .input-icon {
      position: relative;
      width: 100%;
    }
    
    .input-icon i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #ffbe33;
      font-size: 14px;
      z-index: 1;
      pointer-events: none;
    }
    
    .input-icon input {
      width: 100%;
      background: #ffffff !important;
      border: 1px solid #333 !important;
      color: #000000 !important;
      height: 52px;
      font-size: 1rem;
      border-radius: 12px;
      padding: 10px 15px 10px 45px;
      transition: all 0.3s ease;
    }
    
    .input-icon input:focus {
      border-color: #ffbe33 !important;
      box-shadow: 0 0 0 3px rgba(255, 190, 51, 0.2);
      outline: none;
    }
    
    /* Select box styling - cùng kích thước với input và font-size 17px */
    .select-wrapper {
      position: relative;
      width: 100%;
    }
    
    .select-wrapper select {
      width: 100%;
      background: #ffffff !important;
      border: 1px solid #333 !important;
      color: #000000 !important;
      height: 52px;
      font-size: 17px !important;
      border-radius: 12px;
      padding: 10px 40px 10px 15px;
      appearance: none;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .select-wrapper select option {
      font-size: 17px !important;
    }
    
    .select-wrapper select:focus {
      border-color: #ffbe33 !important;
      box-shadow: 0 0 0 3px rgba(255, 190, 51, 0.2);
      outline: none;
    }
    
    .select-wrapper i {
      position: absolute;
      right: 18px;
      top: 50%;
      transform: translateY(-50%);
      color: #ffbe33;
      font-size: 14px;
      pointer-events: none;
    }
    
    /* Price range styling - cải thiện giao diện */
    .price-range-wrapper {
      width: 100%;
    }
    
    .price-range-label {
      display: block;
      margin-bottom: 15px;
      color: #ffbe33;
      font-weight: 500;
      font-size: 13px;
    }
    
    .price-input-group {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 20px;
    }
    
    .price-input-group .input-icon {
      flex: 1;
    }
    
    .price-input-group .input-icon input {
      height: 52px;
      padding: 10px 15px 10px 45px;
    }
    
    .price-dash {
      color: #ffbe33;
      font-weight: bold;
      font-size: 18px;
      background: rgba(255, 190, 51, 0.1);
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
    }
    
    .price-range-slider {
      margin: 20px 0;
      height: 4px;
      background: #333;
      border-radius: 4px;
      position: relative;
    }
    
    .price-range-track {
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, #ffbe33, #ffbe33);
      border-radius: 4px;
      position: relative;
    }
    
    .price-unit {
      display: block;
      text-align: center;
      font-size: 12px;
      color: #aaa;
      margin-top: 15px;
      padding: 8px;
      background: rgba(255, 190, 51, 0.05);
      border-radius: 8px;
    }
    
    .price-unit i {
      margin-right: 6px;
      color: #ffbe33;
    }
    
    .btn-filter { 
      background: linear-gradient(135deg, #ffbe33 0%, #e69c00 100%);
      color: #222; 
      border: none; 
      font-weight: bold; 
      padding: 15px; 
      font-size: 1rem; 
      border-radius: 12px; 
      transition: all 0.3s ease;
      margin-top: 15px;
      cursor: pointer;
      width: 100%;
      text-transform: uppercase;
      letter-spacing: 1px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }
    
    .btn-filter:hover { 
      background: linear-gradient(135deg, #e69c00 0%, #ffbe33 100%);
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(255, 190, 51, 0.3);
    }
    
    .btn-reset {
      background: transparent;
      color: #ffbe33;
      border: 1px solid #ffbe33;
      padding: 13px;
      font-size: 0.95rem;
      border-radius: 12px;
      transition: all 0.3s ease;
      margin-top: 15px;
      width: 100%;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      text-decoration: none;
    }
    
    .btn-reset:hover {
      background: #ffbe33;
      color: #222;
      text-decoration: none;
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(255, 190, 51, 0.2);
    }
    
    /* Suggestion Box - Thiết kế mới */
    .suggestion-box {
      background: linear-gradient(135deg, rgba(255, 190, 51, 0.08) 0%, rgba(255, 190, 51, 0.02) 100%);
      padding: 25px;
      border-radius: 20px;
      margin-top: 25px;
      border: 1px solid rgba(255, 190, 51, 0.15);
      backdrop-filter: blur(10px);
    }
    
    .suggestion-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 18px;
      padding-bottom: 12px;
      border-bottom: 1px solid rgba(255, 190, 51, 0.2);
    }
    
    .suggestion-header i {
      font-size: 20px;
      color: #ffbe33;
    }
    
    .suggestion-header span {
      color: #ffbe33;
      font-weight: bold;
      font-size: 14px;
      letter-spacing: 1px;
    }
    
    .suggestion-list {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    
    .suggestion-item {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      font-size: 13px;
      line-height: 1.5;
    }
    
    .suggestion-item i {
      color: #ffbe33;
      font-size: 12px;
      margin-top: 2px;
      flex-shrink: 0;
    }
    
    .suggestion-item span {
      color: #000000 !important;
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
        padding: 20px;
        margin-bottom: 20px;
      }
      .add-to-cart-btn,
      .cart-link-btn {
        padding: 6px 12px !important;
        font-size: 11px !important;
        min-width: 60px !important;
      }
      .add-to-cart-btn i,
      .cart-link-btn i {
        font-size: 10px !important;
      }
      .price-input-group {
        gap: 10px;
      }
      .price-dash {
        width: 35px;
        height: 35px;
        font-size: 16px;
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
      <i class="fa fa-cutlery"></i> Tìm thấy <strong style="color: #ffbe33;"><?php echo $total_products; ?></strong> sản phẩm 
      <?php if ($total_products > 0): ?>
        (Hiển thị <?php echo count($products); ?> sản phẩm)
      <?php endif; ?>
    </div>

    <div class="row">
      <!-- Khung tìm kiếm nâng cao - Thiết kế mới -->
      <div class="col-lg-4">
        <div class="filter_sidebar">
          <div class="filter-header">
            <i class="fa fa-sliders-h"></i>
            <h4>BỘ LỌC NÂNG CAO</h4>
          </div>
          <form action="search.php" method="GET" id="advancedSearchForm">
            <!-- Tiêu chí 1: Tên món ăn -->
            <div class="form-group">
              <label><i class="fa fa-cutlery"></i> TÊN MÓN ĂN</label>
              <div class="input-icon">
                <i class="fa fa-search"></i>
                <input type="text" name="query" class="form-control form-control-lg" 
                       placeholder="Nhập tên món ăn..." 
                       value="<?= htmlspecialchars($search_query) ?>">
              </div>
            </div>
            
            <!-- Tiêu chí 2: Phân loại danh mục -->
            <div class="form-group" style="margin-bottom: 30px;">
              <label><i class="fa fa-tags"></i> DANH MỤC</label>
              <div class="select-wrapper">
                <select name="category" class="form-control form-control-lg">
                  <option value="all">-- Tất cả danh mục --</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                
              </div>
            </div>
            
            <!-- Tiêu chí 3: Khoảng giá -->
            <div class="form-group">
              <label><i class="fa fa-money"></i> KHOẢNG GIÁ</label>
              <div class="price-range-wrapper"></i>
                <div class="price-input-group"></i>
                  <div class="input-icon">
                    <i class="fa fa-dollar"></i>
                    <input type="number" name="min_price" class="form-control form-control-lg" 
                           placeholder="Từ" value="<?= htmlspecialchars($display_min_price) ?>" step="1" min="0">
                  </div>
                  <span class="price-dash">-</span>
                  <div class="input-icon">
                    <i class="fa fa-dollar"></i>
                    <input type="number" name="max_price" class="form-control form-control-lg" 
                           placeholder="Đến" value="<?= htmlspecialchars($display_max_price) ?>" step="1" min="0">
                  </div>
                </div>
                <div class="price-range-slider">
                  <div class="price-range-track"></div>
                </div>
                <small class="price-unit">
                  <i class="fa fa-info-circle"></i> Đơn vị: nghìn đồng (VD: 50 = 50.000đ)
                </small>
              </div>
            </div>
            
            <!-- Nút tìm kiếm -->
            <button type="submit" class="btn-filter">
              <i class="fa fa-search"></i> TÌM KIẾM
            </button>
            
            <!-- Nút đặt lại bộ lọc -->
            <a href="search.php" class="btn-reset">
              <i class="fa fa-refresh"></i> ĐẶT LẠI
            </a>
          </form>
        </div>
        
        <!-- Gợi ý tìm kiếm -->
        <div class="suggestion-box">
          <div class="suggestion-header">
            <i class="fa fa-lightbulb-o"></i>
            <span>GỢI Ý TÌM KIẾM</span>
          </div>
          <div class="suggestion-list">
            <div class="suggestion-item">
              <i class="fa fa-angle-right"></i>
              <span>Tìm kiếm nhanh: Nhập tên món vào thanh tìm kiếm phía trên</span>
            </div>
            <div class="suggestion-item">
              <i class="fa fa-angle-right"></i>
              <span>Tìm kiếm nâng cao: Kết hợp tên món + danh mục + khoảng giá</span>
            </div>
            <div class="suggestion-item">
              <i class="fa fa-angle-right"></i>
              <span>Giá nhập theo nghìn đồng, hệ thống sẽ tự động chuyển đổi</span>
            </div>
            <div class="suggestion-item">
              <i class="fa fa-angle-right"></i>
              <span>Để trống giá trị để bỏ qua bộ lọc giá</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Kết quả tìm kiếm -->
      <div class="col-lg-8">
        <div class="filters-content">
          <div class="row grid" id="menu-items-container">
            <?php if (empty($products)): ?>
              <div class="col-12">
                <div class="empty-state">
                  <i class="fa fa-exclamation-circle"></i>
                  <h4>Không tìm thấy sản phẩm</h4>
                  <p>Không có kết quả phù hợp với tiêu chí tìm kiếm của bạn.</p>
                  <p style="color: #888; font-size: 14px;">Hãy thử thay đổi từ khóa hoặc khoảng giá khác.</p>
                  <a href="search.php" class="btn-view-more" style="display: inline-block; margin-top: 20px;">
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
          <h4>
            Contact Us
          </h4>
          <div class="contact_link_box">
            <div><i class="fa fa-map-marker"></i><span>Location</span></div>
            <div><i class="fa fa-phone"></i><span>Call +01 1234567890</span></div>
            <div><i class="fa fa-envelope"></i><span>demo@gmail.com</span></div>
          </div>
        </div>
      </div>
      <div class="col-md-4 footer-col">
        <div class="footer_detail">
          <a href="" class="footer-logo">
            Feane
          </a>
          <p>
            Delicious fast food made with love. Quality ingredients, great taste, and fast delivery.
          </p>
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
          10:00 AM - 10:00 PM
        </p>
      </div>
    </div>
    <div class="footer-info">
      <p>
        &copy; <span id="displayYear"></span> Feane Restaurant. All Rights Reserved.
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