<?php
session_start();
require_once __DIR__ . '/../includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch user information from database
try {
    $stmt = $pdo->prepare("SELECT id, full_name, username, email, phone, register_date, role, status, address, birthday FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header('Location: ../login.php');
        exit;
    }
    
    // Get order count
    $stmt = $pdo->prepare("SELECT COUNT(*) as order_count FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $order_count = $stmt->fetch(PDO::FETCH_ASSOC)['order_count'] ?? 0;
    
    // Get loyalty points (if exists)
    $loyalty_points = '0';
    try {
        $stmt = $pdo->prepare("SELECT points FROM loyalty_points WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $points_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $loyalty_points = $points_data ? number_format($points_data['points']) : '0';
    } catch (PDOException $e) {
        $loyalty_points = '0';
    }
    
    // Format register date
    $register_date = date('d/m/Y', strtotime($user['register_date']));
    
    // Format birthday if exists
    $birthday = !empty($user['birthday']) ? date('d/m/Y', strtotime($user['birthday'])) : 'Chưa cập nhật';
    
    // Address if exists
    $address = !empty($user['address']) ? $user['address'] : 'Chưa cập nhật';
    
} catch (PDOException $e) {
    $error = "Lỗi khi tải thông tin: " . $e->getMessage();
}

// Get current page for active menu
$current_page = 'profile';
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
  <link rel="shortcut icon" href="../images/favicon.png" type="">

  <title>Feane - Thông tin cá nhân</title>

  <!-- bootstrap core css -->
  <link rel="stylesheet" type="text/css" href="../css/bootstrap.css" />

  <!-- owl slider stylesheet -->
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css" />
  <!-- nice select -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/css/nice-select.min.css" />
  <!-- font awesome style -->
  <link href="../css/font-awesome.min.css" rel="stylesheet" />

  <!-- Custom styles for this template -->
  <link href="../css/style.css" rel="stylesheet" />
  <!-- responsive style -->
  <link href="../css/responsive.css" rel="stylesheet" />

  <style>
    .profile-section {
      padding: 50px 0;
      background-color: rgba(248, 249, 250, 0.95);
      position: relative;
      z-index: 1;
    }
    
    .profile-card {
      background: #fff;
      border-radius: 10px;
      padding: 30px;
      box-shadow: 0 0 15px rgba(0,0,0,0.1);
    }
    
    .profile-header {
      text-align: center;
      margin-bottom: 30px;
    }
    
    .profile-avatar {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      object-fit: cover;
      margin-bottom: 15px;
      border: 3px solid #ffbe33;
    }
    
    .info-group {
      margin-bottom: 20px;
    }
    
    .info-label {
      font-weight: bold;
      color: #333;
      margin-bottom: 5px;
    }
    
    .info-value {
      color: #666;
    }
    
    .btn-edit {
      background-color: #ffbe33;
      color: white;
      padding: 10px 30px;
      border-radius: 30px;
      border: none;
      font-weight: bold;
      transition: all 0.3s;
      text-decoration: none;
      display: inline-block;
      margin: 0 10px;
    }
    
    .btn-edit:hover {
      background-color: #e69c00;
      color: white;
      text-decoration: none;
    }
    
    .alert {
      padding: 12px 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    
    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    
    .alert-error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    
    .heading_container h2 {
      position: relative;
      margin-bottom: 30px;
    }
    
    .heading_container h2::after {
      content: "";
      position: absolute;
      bottom: -10px;
      left: 50%;
      transform: translateX(-50%);
      width: 60px;
      height: 3px;
      background-color: #ffbe33;
    }
  </style>
</head>

<body class="sub_page">

  <div class="hero_area">
    <div class="bg-box">
      <img src="../images/hero-bg.jpg" alt="Background">
    </div>
    
    <!-- header section starts -->
    <header class="header_section">
      <div class="container">
        <nav class="navbar navbar-expand-lg custom_nav-container">
          <a class="navbar-brand" href="../index.php">
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
                <a class="nav-link" href="../index.php">Home</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="../menu.php">Menu</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="../about.html">About</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="../ordershistory.html">Order history</a>
              </li>
            </ul>
            <div class="user_option">
              <a href="profile.php" class="user_link">
                <i class="fa fa-user" aria-hidden="true"></i>
              </a>
              <a class="cart_link" href="../shoppingcart.html">
                <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029">
                  <g>
                    <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/>
                    <path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/>
                    <path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/>
                  </g>
                </svg>
              </a>
              <form class="form-inline">
                <a href="../findproduct.html" class="btn my-2 my-sm-0 nav_search-btn">
                  <i class="fa fa-search" aria-hidden="true"></i>
                </a>
              </form>
              <div class="order_online">
                <a href="../logout.php" style="color: white;">
                  Đăng xuất
                </a>
              </div>
            </div>
          </div>
        </nav>
      </div>
    </header>
    <!-- end header section -->
  </div>

  <!-- profile section -->
  <section class="profile-section layout_padding">
    <div class="container">
      <div class="heading_container heading_center">
        <h2>
          Thông tin cá nhân
        </h2>
      </div>

      <div class="row justify-content-center">
        <div class="col-md-8">
          <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>
          
          <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
          <?php endif; ?>
          
          <div class="profile-card">
            <div class="profile-header">
              <img src="../images/about-img.png" alt="Avatar" class="profile-avatar">
              <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
              <p>Thành viên từ: <?php echo $register_date; ?></p>
            </div>
            
            <div class="row">
              <div class="col-md-6">
                <div class="info-group">
                  <div class="info-label">Họ và tên</div>
                  <div class="info-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="info-group">
                  <div class="info-label">Email</div>
                  <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                </div>
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-6">
                <div class="info-group">
                  <div class="info-label">Số điện thoại</div>
                  <div class="info-value"><?php echo htmlspecialchars($user['phone']); ?></div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="info-group">
                  <div class="info-label">Ngày sinh</div>
                  <div class="info-value"><?php echo $birthday; ?></div>
                </div>
              </div>
            </div>
            
            <div class="info-group">
              <div class="info-label">Địa chỉ</div>
              <div class="info-value"><?php echo htmlspecialchars($address); ?></div>
            </div>
            
            <div class="row">
              <div class="col-md-6">
                <div class="info-group">
                  <div class="info-label">Số đơn hàng</div>
                  <div class="info-value"><?php echo $order_count; ?></div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="info-group">
                  <div class="info-label">Điểm tích lũy</div>
                  <div class="info-value"><?php echo $loyalty_points; ?> điểm</div>
                </div>
              </div>
            </div>
            
            <div class="text-center mt-4">
              <a href="edit_profile.php" class="btn btn-edit">Sửa thông tin</a>
              <a href="../index.php" class="btn btn-edit">Quay lại</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
  <!-- end profile section -->

  <!-- footer section -->
  <footer class="footer_section">
    <div class="container">
      <div class="row">
        <div class="col-md-4 footer-col">
          <div class="footer_contact">
            <h4>Contact Us</h4>
            <div class="contact_link_box">
              <a href="">
                <i class="fa fa-map-marker" aria-hidden="true"></i>
                <span>Location</span>
              </a>
              <a href="">
                <i class="fa fa-phone" aria-hidden="true"></i>
                <span>Call +01 1234567890</span>
              </a>
              <a href="">
                <i class="fa fa-envelope" aria-hidden="true"></i>
                <span>demo@gmail.com</span>
              </a>
            </div>
          </div>
        </div>
        <div class="col-md-4 footer-col">
          <div class="footer_detail">
            <a href="" class="footer-logo">Feane</a>
            <p>Necessary, making this the first true generator on the Internet. It uses a dictionary of over 200 Latin words, combined with</p>
            <div class="footer_social">
              <a href=""><i class="fa fa-facebook" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-twitter" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-linkedin" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-instagram" aria-hidden="true"></i></a>
              <a href=""><i class="fa fa-pinterest" aria-hidden="true"></i></a>
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
        <p>
          &copy; <span id="displayYear"></span> All Rights Reserved By
          <a href="https://html.design/">Free Html Templates</a><br><br>
          &copy; <span id="displayYear"></span> Distributed By
          <a href="https://themewagon.com/" target="_blank">ThemeWagon</a>
        </p>
      </div>
    </div>
  </footer>

  <!-- jQuery -->
  <script src="../js/jquery-3.4.1.min.js"></script>
  <!-- bootstrap js -->
  <script src="../js/bootstrap.js"></script>
  <!-- owl slider -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>
  <!-- nice select -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/js/jquery.nice-select.min.js"></script>
  <!-- custom js -->
  <script src="../js/custom.js"></script>
  
  <script>
    document.getElementById('displayYear').innerHTML = new Date().getFullYear();
  </script>
</body>

</html>