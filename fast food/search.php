<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// 1. LẤY THAM SỐ TÌM KIẾM VÀ PHÂN TRANG
$search_query = isset($_GET['query']) ? trim($_GET['query']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 6;
$offset = ($page - 1) * $limit;

// 2. LẤY DANH SÁCH DANH MỤC CHO BỘ LỌC
$categories_stmt = $pdo->query("SELECT * FROM categories WHERE status = 'active' ORDER BY id ASC");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. XÂY DỰNG TRUY VẤN SQL
$where_clauses = ["p.status = 'active'"];
$params = [];

if (!empty($search_query)) {
    $where_clauses[] = "p.name LIKE ?";
    $params[] = "%$search_query%";
}

if ($category_filter !== 'all') {
    $where_clauses[] = "p.category_id = ?";
    $params[] = $category_filter;
}

// Công thức tính giá bán (Dựa trên menu.php)
$selling_price_sql = "(p.cost_price * (1 + p.profit_percentage/100))";

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

// Lấy danh sách sản phẩm với style giống menu.php
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
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title>Feane - Tìm kiếm món ăn</title>

  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  <link href="css/style.css" rel="stylesheet" />
  <link href="css/responsive.css" rel="stylesheet" />
  
  <style>
    /* Đồng bộ CSS từ menu.php */
    .hero_area { min-height: auto !important; }
    .cart-count {
      position: absolute; top: -8px; right: -8px; background: red; color: white;
      border-radius: 50%; width: 20px; height: 20px; font-size: 12px;
      display: flex; align-items: center; justify-content: center;
    }
    .cart_link { position: relative; }
    
    /* Sidebar Tìm kiếm tối ưu */
    .filter_sidebar { 
        background: #222; color: #fff; padding: 35px; border-radius: 15px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.3); margin-bottom: 30px;
    }
    .filter_sidebar h4 { color: #ffbe33; font-weight: bold; margin-bottom: 25px; text-transform: uppercase; text-align: center; }
    .filter_sidebar .form-control-lg { background: #333 !important; border: 1px solid #444 !important; color: white !important; height: 55px; font-size: 1rem; }
    .btn-filter { background: #ffbe33; color: #222; border: none; font-weight: bold; padding: 15px; font-size: 1.1rem; border-radius: 5px; transition: 0.3s; }
    .btn-filter:hover { background: #e69c00; transform: scale(1.02); }

    /* Style Box sản phẩm giống menu.php */
    .box { margin-bottom: 30px; transition: all 0.3s; border-radius: 15px; overflow: hidden; background: #222; }
    .box:hover { transform: translateY(-5px); }
    .box .img-box { background: #f1f2f3; padding: 25px; display: flex; justify-content: center; height: 215px; align-items: center; }
    .box .img-box img { max-width: 100%; max-height: 160px; transition: transform 0.3s; }
    .box:hover .img-box img { transform: scale(1.05); }
    .box .detail-box { padding: 20px; color: #fff; }
    .box .detail-box h5 { font-weight: bold; text-transform: capitalize; }
    .box .options { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; }
    .box .options h6 { color: #ffbe33; font-weight: bold; font-size: 18px; margin: 0; }
    
    .add-to-cart-btn, .cart-link-btn {
      background: #ffbe33; border: none; padding: 8px 15px; border-radius: 30px;
      color: white; font-weight: 500; font-size: 14px; transition: 0.3s; text-decoration: none;
    }
    .add-to-cart-btn:hover, .cart-link-btn:hover { background: #e69c00; color: white; }
    
    #toast-message { position: fixed; bottom: 20px; right: 20px; background: #28a745; color: #fff; padding: 12px 20px; border-radius: 5px; display: none; z-index: 10000; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
  </style>
</head>

<body class="sub_page">
  <div id="toast-message"></div>

  <div class="hero_area">
    <div class="bg-box"><img src="images/hero-bg.jpg" alt=""></div>
    <header class="header_section">
      <div class="container">
        <nav class="navbar navbar-expand-lg custom_nav-container">
          <a class="navbar-brand" href="index.php"><span>Feane</span></a>
          <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent">
            <span> </span>
          </button>

          <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav mx-auto">
              <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
              <li class="nav-item active"><a class="nav-link" href="menu.php">Menu</a></li>
              <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
              <li class="nav-item">
                <a class="nav-link" href="<?= $is_logged_in ? 'order_history.php' : 'user/login.php' ?>">Order history</a>
              </li>
            </ul>
            <div class="user_option">
              <a href="<?= $is_logged_in ? 'user/profile.php' : 'user/login.php' ?>" class="user_link">
                <i class="fa fa-user" aria-hidden="true"></i>
              </a>
              <a class="cart_link" href="cart.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029" width="20" fill="white">
                  <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/>
                  <path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/>
                  <path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/>
                </svg>
                <?php if ($cart_count > 0): ?>
                  <span class="cart-count"><?= $cart_count ?></span>
                <?php endif; ?>
              </a>
              <a href="search.php" class="btn nav_search-btn"><i class="fa fa-search"></i></a>
              <div class="order_online">
                <a href="<?= $is_logged_in ? 'user/logout.php' : 'user/login.php' ?>" style="color: white;">
                  <?= $is_logged_in ? 'Đăng xuất' : 'Đăng nhập/Đăng kí' ?>
                </a>
              </div>
            </div>
          </div>
        </nav>
      </div>
    </header>
  </div>

  <section class="food_section layout_padding">
    <div class="container">
      <div class="row">
        <div class="col-lg-4">
          <div class="filter_sidebar">
            <form action="search.php" method="GET">
              <h4>TÌM KIẾM</h4>
              <div class="form-group mb-4">
                <label>Tên món ăn:</label>
                <input type="text" name="query" class="form-control form-control-lg" placeholder="Ví dụ: Burger, Pizza..." value="<?= htmlspecialchars($search_query) ?>">
              </div>
              <div class="form-group mb-4">
                <label>Phân loại:</label>
                <select name="category" class="form-control form-control-lg">
                  <option value="all">Tất cả danh mục</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group mb-4">
                <label>Khoảng giá (VNĐ):</label>
                <div class="d-flex align-items-center">
                  <input type="number" name="min_price" class="form-control form-control-lg" placeholder="Từ" value="<?= htmlspecialchars($_GET['min_price'] ?? '') ?>">
                  <span class="mx-2" style="color: #ffbe33; font-weight: bold;">-</span>
                  <input type="number" name="max_price" class="form-control form-control-lg" placeholder="Đến" value="<?= htmlspecialchars($_GET['max_price'] ?? '') ?>">
                </div>
              </div>
              <button type="submit" class="btn btn-filter w-100"><i class="fa fa-filter"></i> LỌC MÓN ĂN</button>
              <?php if(!empty($_GET['query']) || !empty($_GET['min_price']) || $category_filter !== 'all'): ?>
                <a href="search.php" class="btn btn-link btn-sm d-block text-center mt-3" style="color: #bbb; text-decoration: none;"><i class="fa fa-refresh"></i> Thiết lập lại</a>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <div class="col-lg-8">
            <div class="row">
                <?php if (empty($products)): ?>
                    <div class="col-12 text-center mt-5"><h4 style="color: #666;">Không tìm thấy kết quả phù hợp.</h4></div>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <div class="col-sm-6 mb-4">
                            <div class="box">
                                <a href="product_detail.php?id=<?= $p['id'] ?>">
                                    <div class="img-box"><img src="<?= htmlspecialchars($p['image']) ?>" alt=""></div>
                                </a>
                                <div class="detail-box">
                                    <h5><?= htmlspecialchars($p['name']) ?></h5>
                                    <p style="font-size: 0.9rem; color: #bbb;"><?= htmlspecialchars(mb_strimwidth($p['description'], 0, 80, "...")) ?></p>
                                    <div class="options">
                                        <h6><?= number_format($p['selling_price'], 0, ',', '.') ?>đ</h6>
                                        <?php if ($is_logged_in): ?>
                                          <button class="add-to-cart-btn" data-id="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-price="<?= $p['selling_price'] ?>" data-image="<?= htmlspecialchars($p['image']) ?>">
                                            <i class="fa fa-shopping-cart"></i> Thêm
                                          </button>
                                        <?php else: ?>
                                          <a href="user/login.php" class="cart-link-btn"><i class="fa fa-shopping-cart"></i> Thêm</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
      </div>
    </div>
  </section>

  <script src="js/jquery-3.4.1.min.js"></script>
  <script src="js/bootstrap.js"></script>
  <script>
    function showToast(message) {
      const toast = $('#toast-message');
      toast.text(message).fadeIn().delay(2000).fadeOut();
    }

    $(document).on('click', '.add-to-cart-btn', function(e) {
      e.preventDefault();
      const id = $(this).data('id');
      const name = $(this).data('name');
      const price = $(this).data('id');
      const image = $(this).data('id');

      // Gửi request giống logic xử lý trong menu.php
      $.ajax({
          url: 'cart.php',
          method: 'POST',
          data: { add_to_cart: '1', product_id: id, name: name, price: price, image: image, quantity: 1 },
          success: function(response) {
              try {
                  const res = JSON.parse(response);
                  if(res.success) {
                      showToast('Đã thêm ' + name + ' vào giỏ hàng!');
                      if(res.cart_count) {
                        $('.cart_link').find('.cart-count').remove();
                        $('.cart_link').append('<span class="cart-count">'+res.cart_count+'</span>');
                      }
                  }
              } catch(e) { console.error("Lỗi xử lý JSON"); }
          }
      });
    });
  </script>
</body>
</html>