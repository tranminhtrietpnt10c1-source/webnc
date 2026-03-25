<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

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

// Get cart count
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}
?>

<!DOCTYPE html>
<html>

<head>
  <!-- Basic -->
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <!-- Mobile Metas -->
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <!-- Site Metas -->
  <meta name="keywords" content="" />
  <meta name="description" content="" />
  <meta name="author" content="" />
  <link rel="shortcut icon" href="images/favicon.png" type="">

  <title>Feane - About Us</title>

  <!-- bootstrap core css -->
  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />

  <!--owl slider stylesheet -->
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css" />
  <!-- nice select  -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/css/nice-select.min.css" integrity="sha512-CruCP+TD3yXzlvvijET8wV5WxxEh5H8P4cmz0RFbKK6FlZ2sYl3AEsKlLPHbniXKSrDdFewhbmBK5skbdsASbQ==" crossorigin="anonymous" />
  <!-- font awesome style -->
  <link href="css/font-awesome.min.css" rel="stylesheet" />

  <!-- Custom styles for this template -->
  <link href="css/style.css" rel="stylesheet" />
  <!-- responsive style -->
  <link href="css/responsive.css" rel="stylesheet" />

  <style>
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
    
    @media (max-width: 768px) {
      .user-name {
        display: none;
      }
      .dropdown-menu-custom {
        right: -50px;
      }
    }
    
    /* About section styles */
    .about_section {
      background: #fff;
    }
    
    .about_section .img-box img {
      width: 100%;
      border-radius: 15px;
      box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    }
    
    .about_section .detail-box {
      padding: 20px;
    }
    
    .about_section .detail-box p {
      font-size: 16px;
      line-height: 1.8;
      color: #555;
      text-align: justify;
    }
    
    .about_section .detail-box h2 {
      color: #ffbe33;
      margin-bottom: 20px;
    }
    
    @media (max-width: 768px) {
      .about_section .detail-box {
        text-align: center;
      }
      .about_section .detail-box p {
        text-align: center;
      }
    }
  </style>
</head>

<body class="sub_page">

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
              <li class="nav-item">
                <a class="nav-link" href="menu.php">Menu</a>
              </li>
              <li class="nav-item active">
                <a class="nav-link" href="about.php">About <span class="sr-only">(current)</span></a>
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
                <svg version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 456.029 456.029" style="enable-background:new 0 0 456.029 456.029;" xml:space="preserve">
                  <g>
                    <g>
                      <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248
                   c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z" />
                    </g>
                  </g>
                  <g>
                    <g>
                      <path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48
                   C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064
                   c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4
                   C457.728,97.71,450.56,86.958,439.296,84.91z" />
                    </g>
                  </g>
                  <g>
                    <g>
                      <path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296
                   c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z" />
                    </g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                  <g>
                  </g>
                </svg>
                <?php if ($cart_count > 0): ?>
                  <span class="cart-count"><?php echo $cart_count; ?></span>
                <?php endif; ?>
              </a>
              <a href="search.php" class="btn my-2 my-sm-0 nav_search-btn">
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

  <!-- about section -->
  <section class="about_section layout_padding">
    <div class="container">
      <div class="row">
        <div class="col-md-6">
          <div class="img-box">
            <img src="images/about-img.png" alt="Feane Restaurant">
          </div>
        </div>
        <div class="col-md-6">
          <div class="detail-box">
            <div class="heading_container">
              <h2>
                Feane - Thiên Đường Ăn Vặt Cho Tín Đồ Đồ Tây
              </h2>
            </div>
            <p>
              Bạn đang thèm một chiếc Burger thơm nức với nhân thịt bò dày dặn? Hay một chiếc Pizza giòn tan phủ đầy phô mai? Feane chính là điểm đến lý tưởng dành cho bạn!
            </p>
            <p>
              Tại Feane, chúng tôi không chỉ có Burger "gây nghiện", Pasta Ý sốt kem béo ngậy mà còn có những chiếc Pizza vàng ươm và khoai tây chiên giòn rụm. Tất cả đều được chế biến từ những nguyên liệu tươi ngon nhất, mang đến cho bạn một bữa ăn nhanh nhưng chất lượng tuyệt đối không "nhanh" chút nào.
            </p>
            <p>
              Hãy đến Feane và "xử đẹp" cơn thèm ngay hôm nay!
            </p>
            <div class="mt-4">
              <a href="menu.php" class="btn btn-primary" style="background-color: #ffbe33; border-color: #ffbe33; color: #222; border-radius: 30px; padding: 10px 25px;">
                <i class="fa fa-shopping-bag"></i> Khám phá thực đơn
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
  <!-- end about section -->

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
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous">
  </script>
  <!-- bootstrap js -->
  <script src="js/bootstrap.js"></script>
  <!-- owl slider -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js">
  </script>
  <!-- isotope js -->
  <script src="https://unpkg.com/isotope-layout@3.0.4/dist/isotope.pkgd.min.js"></script>
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
    
    // Display current year
    document.getElementById('displayYear').innerHTML = new Date().getFullYear();
  </script>

</body>

</html>