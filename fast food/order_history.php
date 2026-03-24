<?php
session_start();
require_once 'includes/db_connection.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: user/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Lấy thông tin user
$user_info = null;
try {
    $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Bỏ qua
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Đếm tổng số đơn hàng
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
$countStmt->execute([$user_id]);
$totalOrders = $countStmt->fetchColumn();
$totalPages = ceil($totalOrders / $limit);

// Lấy danh sách đơn hàng
$sql = "
    SELECT o.*, 
           (SELECT COUNT(*) FROM order_details WHERE order_id = o.id) as item_count
    FROM orders o
    WHERE o.user_id = ?
    ORDER BY o.order_date DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: In ra số lượng đơn hàng để kiểm tra
// echo "<!-- Total orders: " . $totalOrders . " -->";

// Hàm format tiền
function formatCurrency($amount) {
    return number_format($amount, 0, ',', '.') . ' VND';
}

function formatDate($date) {
    if (empty($date)) return '';
    return date('d/m/Y H:i', strtotime($date));
}

function getStatusInfo($status) {
    $statuses = [
        'pending' => ['class' => 'processing', 'text' => 'Chờ xử lý'],
        'processing' => ['class' => 'processing', 'text' => 'Đang xử lý'],
        'shipped' => ['class' => 'processing', 'text' => 'Đang giao hàng'],
        'delivered' => ['class' => 'delivered', 'text' => 'Đã giao'],
        'cancelled' => ['class' => 'cancelled', 'text' => 'Đã hủy'],
        'new' => ['class' => 'processing', 'text' => 'Mới'],
        'cash' => ['class' => 'processing', 'text' => 'Chờ thanh toán'],
        'bank_transfer' => ['class' => 'processing', 'text' => 'Chờ xác nhận']
    ];
    return $statuses[$status] ?? ['class' => 'processing', 'text' => ucfirst($status)];
}

// Lấy số lượng giỏ hàng
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
  <link rel="shortcut icon" href="images/favicon.png" type="image/x-icon">
  <title>Feane - Lịch sử đơn hàng</title>
  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  <link href="css/style.css" rel="stylesheet" />
  <link href="css/responsive.css" rel="stylesheet" />
  <style>
    .order-history-container { margin-top: 30px; }
    .order-table { width: 100%; border-collapse: collapse; margin-top: 20px; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
    .order-table th { background-color: #ffbe33; color: white; padding: 12px 15px; text-align: left; }
    .order-table td { padding: 12px 15px; border-bottom: 1px solid #eee; }
    .order-table tr:hover { background-color: #f9f9f9; }
    .status { padding: 5px 15px; border-radius: 20px; font-size: 14px; font-weight: bold; display: inline-block; }
    .status.delivered { background: #d4edda; color: #155724; }
    .status.processing { background: #fff3cd; color: #856404; }
    .status.cancelled { background: #f8d7da; color: #721c24; }
    .empty-order { text-align: center; padding: 80px 0; background: white; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
    .empty-order i { font-size: 80px; color: #ddd; margin-bottom: 20px; }
    .btn-view { background-color: #ffbe33; color: white; border: none; padding: 6px 12px; border-radius: 4px; text-decoration: none; display: inline-block; }
    .btn-view:hover { background-color: #e69c29; color: white; text-decoration: none; }
    .order-id-link { color: #ffbe33; font-weight: bold; text-decoration: none; }
    .order-id-link:hover { color: #e69c29; text-decoration: underline; }
    .pagination .page-link { color: #8B8000; background-color: #fff; border-color: #FFD700; }
    .pagination .page-link:hover { color: #fff; background-color: #FFD700; border-color: #FFD700; }
    .pagination .page-item.active .page-link { color: #fff; background-color: #FFD700; border-color: #FFD700; }
    .back-to-menu { text-align: center; margin-top: 30px; margin-bottom: 20px; }
    .back-to-menu .btn { background-color: #ffbe33; color: white; padding: 10px 25px; border-radius: 5px; font-weight: bold; text-decoration: none; display: inline-block; }
    .back-to-menu .btn:hover { background-color: #e69c29; color: white; }
    .user-dropdown { position: relative; display: inline-block; }
    .user-dropdown-btn { background: none; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; padding: 5px 10px; border-radius: 30px; }
    .user-icon { width: 32px; height: 32px; border-radius: 50%; background-color: #ffbe33; display: flex; align-items: center; justify-content: center; color: #222; font-weight: bold; }
    .user-name { color: white; font-size: 14px; font-weight: 500; }
    .dropdown-menu-custom { position: absolute; top: 45px; right: 0; background: white; min-width: 280px; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); z-index: 1000; display: none; }
    .dropdown-menu-custom.show { display: block; }
    .dropdown-header { padding: 15px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 12px; }
    .dropdown-header-icon { width: 45px; height: 45px; border-radius: 50%; background-color: #ffbe33; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; }
    .dropdown-header-info h6 { margin: 0; font-weight: 600; }
    .dropdown-header-info p { margin: 0; font-size: 12px; color: #666; }
    .dropdown-item-custom { padding: 12px 15px; display: flex; align-items: center; gap: 12px; color: #333; text-decoration: none; }
    .dropdown-item-custom:hover { background-color: #f5f5f5; }
    .dropdown-item-custom i { width: 20px; color: #ffbe33; }
    .dropdown-divider { height: 1px; background-color: #eee; margin: 5px 0; }
    .cart-count { position: absolute; top: -8px; right: -8px; background: red; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 12px; display: flex; align-items: center; justify-content: center; }
    .cart_link { position: relative; }
    @media (max-width: 768px) { .order-table th, .order-table td { padding: 8px 10px; font-size: 14px; } .status { padding: 3px 10px; font-size: 12px; } }
  </style>
</head>
<body class="sub_page">

<div class="hero_area">
  <div class="bg-box"><img src="images/hero-bg.jpg" alt=""></div>
  <header class="header_section">
    <div class="container">
      <nav class="navbar navbar-expand-lg custom_nav-container">
        <a class="navbar-brand" href="index.php"><span>Feane</span></a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent">
          <span class=""> </span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
          <ul class="navbar-nav mx-auto">
            <li class="nav-item"><a class="nav-link" href="index.php">HOME</a></li>
            <li class="nav-item"><a class="nav-link" href="menu.php">MENU</a></li>
            <li class="nav-item"><a class="nav-link" href="about.php">ABOUT</a></li>
            <li class="nav-item active"><a class="nav-link" href="order_history.php">ORDER HISTORY</a></li>
          </ul>
          <div class="user_option">
            <?php if (isset($_SESSION['user_id']) && $user_info): ?>
              <div class="user-dropdown">
                <button class="user-dropdown-btn" id="userDropdownBtn">
                  <div class="user-icon"><?= strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)) ?></div>
                  <span class="user-name"><?= htmlspecialchars($user_info['full_name'] ?: $user_info['username']) ?></span>
                  <i class="fa fa-chevron-down" style="color: white; font-size: 12px;"></i>
                </button>
                <div class="dropdown-menu-custom" id="userDropdownMenu">
                  <div class="dropdown-header">
                    <div class="dropdown-header-icon"><?= strtoupper(substr($user_info['full_name'] ?? $user_info['username'], 0, 1)) ?></div>
                    <div class="dropdown-header-info">
                      <h6><?= htmlspecialchars($user_info['full_name'] ?: $user_info['username']) ?></h6>
                      <p><?= htmlspecialchars($user_info['email']) ?></p>
                    </div>
                  </div>
                  <a href="user/profile.php" class="dropdown-item-custom"><i class="fa fa-user"></i><span>Thông tin tài khoản</span></a>
                  <a href="order_history.php" class="dropdown-item-custom"><i class="fa fa-shopping-bag"></i><span>Lịch sử đơn hàng</span></a>
                  <a href="cart.php" class="dropdown-item-custom"><i class="fa fa-shopping-cart"></i><span>Giỏ hàng</span></a>
                  <div class="dropdown-divider"></div>
                  <a href="user/logout.php" class="dropdown-item-custom text-danger"><i class="fa fa-sign-out-alt"></i><span>Đăng xuất</span></a>
                </div>
              </div>
            <?php else: ?>
              <a href="user/login.php" class="user_link"><i class="fa fa-user"></i></a>
            <?php endif; ?>
            <a class="cart_link" href="cart.php">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029" width="20">
                <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/>
                <path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/>
                <path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/>
              </svg>
              <?php if ($cart_count > 0): ?>
                <span class="cart-count"><?= $cart_count ?></span>
              <?php endif; ?>
            </a>
            <a href="search.php" class="btn nav_search-btn"><i class="fa fa-search"></i></a>
            <div class="order_online"><a href="user/logout.php" style="color: white;">Đăng xuất</a></div>
          </div>
        </div>
      </nav>
    </div>
  </header>
</div>

<section class="food_section layout_padding">
  <div class="container">
    <div class="heading_container heading_center">
      <h2>Lịch sử đơn hàng</h2>
      <p>Xem lại các đơn hàng bạn đã đặt tại Feane</p>
    </div>

    <div class="order-history-container">
      <?php if (empty($orders)): ?>
        <div class="empty-order">
          <i class="fa fa-shopping-basket" aria-hidden="true"></i>
          <h3>Bạn chưa có đơn hàng nào</h3>
          <p>Hãy đặt món ngay để trải nghiệm ẩm thực tuyệt vời tại Feane!</p>
          <a href="menu.php" class="btn btn-primary" style="background-color: #ffbe33; border-color: #ffbe33; color: #222;">Xem thực đơn</a>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="order-table">
            <thead>
              <tr>
                <th>Mã đơn hàng</th>
                <th>Ngày đặt</th>
                <th>Số lượng SP</th>
                <th>Tổng tiền</th>
                <th>Phí vận chuyển</th>
                <th>Thành tiền</th>
                <th>Trạng thái</th>
                <th>Chi tiết</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $order): 
                $status_info = getStatusInfo($order['status']);
                $final_amount = $order['final_amount'] ?? ($order['total_amount'] + ($order['shipping_fee'] ?? 0));
              ?>
              <tr>
                <td><a href="order_detail.php?id=<?= $order['id'] ?>" class="order-id-link"><?= htmlspecialchars($order['order_code'] ?? '#' . $order['id']) ?></a></td>
                <td><?= formatDate($order['order_date']) ?></td>
                <td><?= $order['item_count'] ?? 0 ?></td>
                <td><?= formatCurrency($order['total_amount']) ?></td>
                <td><?= formatCurrency($order['shipping_fee'] ?? 0) ?></td>
                <td><strong><?= formatCurrency($final_amount) ?></strong></td>
                <td><span class="status <?= $status_info['class'] ?>"><?= $status_info['text'] ?></span></td>
                <td><a href="order_detail.php?id=<?= $order['id'] ?>" class="btn-view">Xem chi tiết</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination-container">
          <nav>
            <ul class="pagination justify-content-center mt-4">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>">Trước</a>
              </li>
              <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                  <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>">Tiếp</a>
              </li>
            </ul>
          </nav>
        </div>
        <?php endif; ?>
        
        <div class="back-to-menu">
          <a href="menu.php" class="btn">Tiếp tục mua hàng</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<footer class="footer_section">
  <div class="container">
    <div class="row">
      <div class="col-md-4 footer-col">
        <div class="footer_contact">
          <h4>Contact Us</h4>
          <div class="contact_link_box">
            <a href=""><i class="fa fa-map-marker"></i><span>Location</span></a>
            <a href=""><i class="fa fa-phone"></i><span>Call +01 1234567890</span></a>
            <a href=""><i class="fa fa-envelope"></i><span>demo@gmail.com</span></a>
          </div>
        </div>
      </div>
      <div class="col-md-4 footer-col">
        <div class="footer_detail">
          <a href="" class="footer-logo">Feane</a>
          <p>Necessary, making this the first true generator on the Internet.</p>
          <div class="footer_social">
            <a href=""><i class="fa fa-facebook"></i></a>
            <a href=""><i class="fa fa-twitter"></i></a>
            <a href=""><i class="fa fa-linkedin"></i></a>
            <a href=""><i class="fa fa-instagram"></i></a>
          </div>
        </div>
      </div>
      <div class="col-md-4 footer-col">
        <h4>Opening Hours</h4>
        <p>Everyday</p>
        <p>10.00 Am -10.00 Pm</p>
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
<script src="js/custom.js"></script>

<script>
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
  document.getElementById('displayYear').innerHTML = new Date().getFullYear();
</script>

</body>
</html>