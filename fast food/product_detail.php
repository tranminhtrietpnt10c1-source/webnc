<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// 1. Lấy ID sản phẩm từ URL
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id <= 0) {
    header("Location: menu.php");
    exit();
}

// 2. Truy vấn chi tiết sản phẩm - Đồng bộ công thức tính giá với menu.php
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
$is_logged_in = isset($_SESSION['user_id']);

// 3. Tính tổng số lượng trong giỏ hàng để hiển thị lên icon
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title><?php echo htmlspecialchars($product['name']); ?> - Feane</title>

  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  <link href="css/style.css" rel="stylesheet" />
  <link href="css/responsive.css" rel="stylesheet" />

  <style>
    .product-detail-section { padding: 50px 0; }
    .product-price { font-size: 1.8rem; color: #ffbe33; font-weight: bold; margin-bottom: 20px; }
    
    /* Nút Thêm vào giỏ hàng - Bo tròn và màu vàng giống menu.php */
    .add-to-cart-btn {
      display: inline-flex; align-items: center; gap: 8px;
      background-color: #ffbe33; color: #ffffff; border: none;
      padding: 10px 25px; border-radius: 30px; font-weight: 500;
      transition: all 0.3s; cursor: pointer; text-decoration: none;
    }
    .add-to-cart-btn:hover { background-color: #e69c00; transform: translateY(-2px); color: white; }
    
    .btn-back {
      background-color: #2e2c28; color: white; padding: 10px 25px;
      border-radius: 30px; margin-left: 10px; text-decoration: none; display: inline-block;
    }

    /* Định dạng Icon giỏ hàng màu trắng và thông báo số lượng */
    .user_option .cart_link svg { fill: #ffffff !important; }
    .cart_link { position: relative; display: inline-block; }
    .cart-count {
      position: absolute; top: -8px; right: -8px; background: red; color: white;
      border-radius: 50%; width: 20px; height: 20px; font-size: 12px;
      display: flex; align-items: center; justify-content: center;
    }
  </style>
</head>

<body class="sub_page">
  <div class="hero_area">
    <div class="bg-box"><img src="images/hero-bg.jpg" alt=""></div>
    <header class="header_section">
      <div class="container">
        <nav class="navbar navbar-expand-lg custom_nav-container">
          <a class="navbar-brand" href="index.php"><span>Feane</span></a>
          <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav mx-auto">
              <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
              <li class="nav-item active"><a class="nav-link" href="menu.php">Menu</a></li>
              <li class="nav-item"><a class="nav-link" href="order_history.php">Order history</a></li>
            </ul>
            <div class="user_option">
              <a href="user/profile.php" class="user_link"><i class="fa fa-user"></i></a>
              <a class="cart_link" href="cart.php">
                <svg viewBox="0 0 456.029 456.029" width="20">
                    <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/>
                </svg>
                <span class="cart-count" id="cartCountDisplay"><?php echo $cart_count; ?></span>
              </a>
              <div class="order_online">
                <a href="<?php echo $is_logged_in ? 'user/logout.php' : 'user/login.php'; ?>" style="color: white;">
                  <?php echo $is_logged_in ? 'Đăng xuất' : 'Đăng nhập'; ?>
                </a>
              </div>
            </div>
          </div>
        </nav>
      </div>
    </header>
  </div>

  <section class="product-detail-section">
    <div class="container">
      <div class="row">
        <div class="col-md-6">
            <img src="<?php echo htmlspecialchars($product['image']); ?>" class="img-fluid" alt="">
        </div>
        <div class="col-md-6">
            <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>
            <div class="product-price"><?php echo number_format($display_price, 0, ',', '.'); ?>đ</div>
            <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
            
            <div class="d-flex align-items-center mb-4">
                <label class="mr-3">Số lượng:</label>
                <input type="number" id="inputQty" value="1" min="1" class="form-control" style="width: 80px;">
            </div>

            <?php if ($is_logged_in): ?>
              <button class="add-to-cart-btn" id="btnAddToCart"
                      data-id="<?php echo $product['id']; ?>"
                      data-name="<?php echo htmlspecialchars($product['name']); ?>"
                      data-price="<?php echo $display_price; ?>"
                      data-image="<?php echo htmlspecialchars($product['image']); ?>">
                <i class="fa fa-shopping-cart"></i> Thêm vào giỏ hàng
              </button>
            <?php else: ?>
              <a href="user/login.php" class="add-to-cart-btn"><i class="fa fa-shopping-cart"></i> Đăng nhập để mua</a>
            <?php endif; ?>
            <a href="menu.php" class="btn-back">Quay lại</a>
        </div>
      </div>
    </div>
  </section>

  <script src="js/jquery-3.4.1.min.js"></script>
  <script>
    // Hàm cập nhật con số trên icon giỏ hàng
    function updateHeaderCartCount(count) {
        const display = document.getElementById('cartCountDisplay');
        if (display) display.innerText = count;
    }

    document.getElementById('btnAddToCart')?.addEventListener('click', function() {
        const btn = this;
        const formData = new FormData();
        formData.append('add_to_cart', '1');
        formData.append('product_id', btn.getAttribute('data-id'));
        formData.append('name', btn.getAttribute('data-name'));
        formData.append('price', btn.getAttribute('data-price'));
        formData.append('image', btn.getAttribute('data-image'));
        formData.append('quantity', document.getElementById('inputQty').value);

        // Gửi yêu cầu AJAX đến cart.php
        fetch('cart.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Đã thêm sản phẩm vào giỏ hàng!');
                if (data.cart_count) updateHeaderCartCount(data.cart_count);
            } else {
                alert('Lỗi: ' + (data.message || 'Không thể thêm vào giỏ hàng'));
            }
        })
        .catch(err => alert('Có lỗi xảy ra khi kết nối.'));
    });
  </script>
</body>
</html>