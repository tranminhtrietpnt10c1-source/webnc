<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// 1. Lấy ID sản phẩm từ URL
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id <= 0) {
    header("Location: menu.php");
    exit();
}

// 2. Truy vấn thông tin chi tiết sản phẩm và tên danh mục
$sql = "SELECT p.*, c.name AS category_name 
        FROM products p 
        JOIN categories c ON p.category_id = c.id 
        WHERE p.id = ? AND p.status = 'active'";
$stmt = $pdo->prepare($sql);
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

// Nếu không tìm thấy sản phẩm
if (!$product) {
    die("Sản phẩm không tồn tại hoặc đã bị ngừng kinh doanh.");
}

// 3. Kiểm tra trạng thái đăng nhập
$is_logged_in = isset($_SESSION['user_id']);

// 4. Lấy số lượng giỏ hàng để hiển thị icon
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
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <link rel="shortcut icon" href="images/favicon.png" type="">

  <title><?php echo htmlspecialchars($product['name']); ?> - Feane</title>

  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  <link href="css/style.css" rel="stylesheet" />
  <link href="css/responsive.css" rel="stylesheet" />

  <style>
    .product-detail-section { padding: 50px 0; }
    .product-image { border-radius: 10px; overflow: hidden; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); }
    .product-image img { width: 100%; height: auto; }
    .product-title { font-size: 2.5rem; color: #222; margin-bottom: 15px; }
    .product-price { font-size: 1.8rem; color: #ffbe33; font-weight: bold; margin-bottom: 20px; }
    .product-description { font-size: 1.1rem; line-height: 1.6; color: #555; margin-bottom: 25px; }
    
    .add-to-cart-btn {
      background-color: #ffbe33; color: white; border: none; padding: 12px 30px;
      border-radius: 5px; font-size: 1.1rem; font-weight: bold; cursor: pointer; transition: 0.3s;
    }
    .add-to-cart-btn:hover { background-color: #e69c00; color: white; text-decoration: none; }
    
    .btn-back {
      background-color: #2e2c28; color: white; border: none; padding: 12px 30px;
      border-radius: 5px; font-size: 1.1rem; font-weight: bold; margin-left: 10px; transition: 0.3s;
    }
    .btn-back:hover { background-color: #000; color: white; text-decoration: none; }

    .cart-count {
      position: absolute; top: -8px; right: -8px; background: red; color: white;
      border-radius: 50%; width: 20px; height: 20px; font-size: 12px;
      display: flex; align-items: center; justify-content: center;
    }
    .cart_link { position: relative; }
  </style>
</head>

<body class="sub_page">
  <div class="hero_area">
    <div class="bg-box">
      <img src="images/hero-bg.jpg" alt="">
    </div>
    <header class="header_section">
      <div class="container">
        <nav class="navbar navbar-expand-lg custom_nav-container">
          <a class="navbar-brand" href="index.php"><span>Feane</span></a>
          <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav mx-auto">
              <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
              <li class="nav-item active"><a class="nav-link" href="menu.php">Menu</a></li>
              <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
            </ul>
            <div class="user_option">
              <?php if ($is_logged_in): ?>
                <a href="user/profile.php" class="user_link"><i class="fa fa-user"></i></a>
              <?php else: ?>
                <a href="user/login.php" class="user_link"><i class="fa fa-user"></i></a>
              <?php endif; ?>
              
              <a class="cart_link" href="cart.php">
                <svg viewBox="0 0 456.029 456.029" width="20"><path fill="currentColor" d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/></svg>
                <?php if ($cart_count > 0): ?>
                  <span class="cart-count"><?php echo $cart_count; ?></span>
                <?php endif; ?>
              </a>
              <div class="order_online">
                <?php if ($is_logged_in): ?>
                  <a href="user/logout.php" style="color: white;">Đăng xuất</a>
                <?php else: ?>
                  <a href="user/login.php" style="color: white;">Đăng nhập</a>
                <?php endif; ?>
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
          <div class="product-image">
            <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
          </div>
        </div>
        <div class="col-md-6">
          <div class="product-info">
            <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>
            <div class="mb-2">
                <span class="badge badge-warning"><?php echo htmlspecialchars($product['category_name']); ?></span>
            </div>
            <div class="product-price"><?php echo number_format($product['selling_price'], 0, ',', '.'); ?>đ</div>
            <div class="product-description">
              <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
            </div>
            
            <div class="quantity-selector d-flex align-items-center mb-4">
                <label class="mr-3">Số lượng:</label>
                <input type="number" id="quantity" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>" class="form-control" style="width: 80px;">
                <small class="ml-2 text-muted">(Còn lại: <?php echo $product['stock_quantity']; ?>)</small>
            </div>

            <?php if ($is_logged_in): ?>
              <button onclick="addToCart(<?php echo $product['id']; ?>)" class="add-to-cart-btn">
                 Thêm vào giỏ hàng
              </button>
            <?php else: ?>
              <a href="user/login.php" class="add-to-cart-btn">Đăng nhập để mua</a>
            <?php endif; ?>

            <a href="menu.php" class="btn-back">Quay lại</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <script src="js/jquery-3.4.1.min.js"></script>
  <script>
    function addToCart(productId) {
        const qty = document.getElementById('quantity').value;
        const formData = new FormData();
        formData.append('add_to_cart', '1');
        formData.append('product_id', productId);
        formData.append('quantity', qty);

        fetch('cart.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                alert('Đã thêm vào giỏ hàng!');
                location.reload(); // Cập nhật lại số icon giỏ hàng
            } else {
                alert('Lỗi: ' + (data.message || 'Không thể thêm sản phẩm'));
            }
        });
    }
  </script>
</body>
</html>