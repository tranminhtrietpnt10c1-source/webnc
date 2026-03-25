<?php
session_start();
require_once __DIR__ . '/../includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$warning = '';

// Fetch current user information
try {
    $stmt = $pdo->prepare("SELECT id, full_name, username, email, phone, address, birthday, register_date, avatar FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
    
    // Get order count
    $stmt = $pdo->prepare("SELECT COUNT(*) as order_count FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $order_count = $stmt->fetch(PDO::FETCH_ASSOC)['order_count'] ?? 0;
    
    // Get loyalty points
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
    $birthday = !empty($user['birthday']) ? date('Y-m-d', strtotime($user['birthday'])) : '';
    $birthday_display = !empty($user['birthday']) ? date('d/m/Y', strtotime($user['birthday'])) : '';
    
    // Avatar path
    $avatar = !empty($user['avatar']) ? $user['avatar'] : '../images/about-img.png';
    
} catch (PDOException $e) {
    $error = "Lỗi khi tải thông tin: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $birthday_input = trim($_POST['birthday'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    // Validate required fields
    if (empty($full_name)) {
        $errors[] = 'Họ và tên không được để trống.';
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email không hợp lệ.';
    }
    
    if (empty($phone)) {
        $errors[] = 'Số điện thoại không được để trống.';
    } elseif (!preg_match('/^(0[3|5|7|8|9])+([0-9]{8})$/', $phone)) {
        $errors[] = 'Số điện thoại không hợp lệ. Vui lòng nhập số điện thoại Việt Nam (10 số, bắt đầu bằng 03, 05, 07, 08, 09).';
    }
    
    if (empty($address)) {
        $errors[] = 'Địa chỉ không được để trống.';
    }
    
    // Validate birthday
    if (!empty($birthday_input)) {
        $birthday_date = date_create($birthday_input);
        $today = new DateTime();
        $age = $today->diff($birthday_date)->y;
        if (!$birthday_date) {
            $errors[] = 'Ngày sinh không hợp lệ.';
        } elseif ($age < 10) {
            $errors[] = 'Bạn phải từ 10 tuổi trở lên.';
        }
        $birthday_formatted = date('Y-m-d', strtotime($birthday_input));
    } else {
        $birthday_formatted = null;
    }
    
    // Check if email already exists for another user
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Email đã được sử dụng bởi tài khoản khác.';
        }
    }
    
    // Check if phone already exists for another user
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
        $stmt->execute([$phone, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Số điện thoại đã được sử dụng bởi tài khoản khác.';
        }
    }
    
    // Handle password change
    if (!empty($new_password)) {
        // Verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($current_password, $user_data['password'])) {
            $errors[] = 'Mật khẩu hiện tại không đúng.';
        }
        
        if (strlen($new_password) < 6) {
            $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = 'Mật khẩu xác nhận không khớp.';
        }
    }
    
    // Handle avatar upload
    $avatar_path = $avatar;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['avatar']['type'];
        $file_size = $_FILES['avatar']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = 'Chỉ chấp nhận file ảnh (JPEG, PNG, GIF, WEBP).';
        }
        
        if ($file_size > 2 * 1024 * 1024) {
            $errors[] = 'Kích thước file không được vượt quá 2MB.';
        }
        
        if (empty($errors)) {
            $upload_dir = __DIR__ . '/../uploads/avatars/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_path)) {
                $avatar_path = 'uploads/avatars/' . $filename;
            } else {
                $errors[] = 'Lỗi khi tải ảnh lên.';
            }
        }
    }
    
    // Update database
    if (empty($errors)) {
        try {
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?, birthday = ?, avatar = ?, password = ? WHERE id = ?");
                $stmt->execute([$full_name, $email, $phone, $address, $birthday_formatted, $avatar_path, $hashed_password, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?, birthday = ?, avatar = ? WHERE id = ?");
                $stmt->execute([$full_name, $email, $phone, $address, $birthday_formatted, $avatar_path, $user_id]);
            }
            
            // Update session
            $_SESSION['user_name'] = $full_name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_phone'] = $phone;
            $_SESSION['user_address'] = $address;
            
            $success = "Thông tin đã được cập nhật thành công!";
            
            // Refresh user data
            $user['full_name'] = $full_name;
            $user['email'] = $email;
            $user['phone'] = $phone;
            $user['address'] = $address;
            $user['avatar'] = $avatar_path;
            if ($birthday_formatted) {
                $user['birthday'] = $birthday_formatted;
                $birthday_display = date('d/m/Y', strtotime($birthday_formatted));
            }
            
        } catch (PDOException $e) {
            $error = "Lỗi khi cập nhật: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
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
  <link rel="shortcut icon" href="../images/favicon.png" type="">

  <title> Feane - Chỉnh sửa thông tin </title>

  <!-- bootstrap core css -->
  <link rel="stylesheet" type="text/css" href="../css/bootstrap.css" />

  <!-- owl slider stylesheet -->
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css" />
  <!-- nice select  -->
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
      background-color: #f8f9fa;
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
      cursor: pointer;
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
    .info-input {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #ffbe33;
      border-radius: 5px;
      color: #333;
      background-color: #fff9e6;
      transition: all 0.3s;
      font-size: 14px;
      font-family: inherit;
    }
    .info-input:focus {
      outline: none;
      border-color: #e69c00;
      background-color: #fff;
      box-shadow: 0 0 5px rgba(255, 190, 51, 0.3);
    }
    .btn-save {
      background-color: #28a745;
      color: white;
      padding: 10px 30px;
      border-radius: 30px;
      border: none;
      font-weight: bold;
      transition: all 0.3s;
      margin: 5px;
      text-decoration: none;
      display: inline-block;
    }
    .btn-save:hover {
      background-color: #218838;
      color: white;
      text-decoration: none;
    }
    .btn-cancel {
      background-color: #6c757d;
      color: white;
      padding: 10px 30px;
      border-radius: 30px;
      border: none;
      font-weight: bold;
      transition: all 0.3s;
      margin: 5px;
      text-decoration: none;
      display: inline-block;
    }
    .btn-cancel:hover {
      background-color: #545b62;
      color: white;
      text-decoration: none;
    }
    .avatar-upload {
      display: none;
    }
    .avatar-upload-label {
      display: block;
      text-align: center;
      color: #ffbe33;
      cursor: pointer;
      margin-top: 10px;
      font-weight: bold;
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
    .alert-warning {
      background-color: #fff3cd;
      color: #856404;
      border: 1px solid #ffeaa7;
    }
    .header_section {
      background-color: #222831;
    }
    .footer_section {
      background-color: #222831;
      color: #fff;
      padding: 50px 0 20px;
    }
    .footer_section a {
      color: #ffbe33;
    }
    .password-section {
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }
    .password-section h4 {
      font-size: 18px;
      margin-bottom: 15px;
      color: #333;
    }
  </style>
</head>

<body class="sub_page">

  <div class="hero_area">
    <div class="bg-box">
      <img src="../images/hero-bg.jpg" alt="">
    </div>
    <!-- header section strats -->
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
                <a class="nav-link" href="../about.php">About</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="../order_history.php">Order history</a>
              </li>
            </ul>
            <div class="user_option">
              <a href="profile.php" class="user_link">
                <i class="fa fa-user" aria-hidden="true"></i>
              </a>
              <a class="cart_link" href="../cart.php">
                <svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 456.029 456.029" xml:space="preserve">
                  <g>
                    <g>
                      <path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z" />
                    </g>
                  </g>
                  <g>
                    <g>
                      <path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z" />
                    </g>
                  </g>
                  <g>
                    <g>
                      <path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z" />
                    </g>
                  </g>
                </svg>
              </a>
              <form class="form-inline">
                <a href="../search.php" class="btn my-2 my-sm-0 nav_search-btn">
                  <i class="fa fa-search" aria-hidden="true"></i>
                </a>
              </form>
              <div class="order_online">
                <a href="logout.php" style="color: white;">
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
          Chỉnh sửa thông tin cá nhân
        </h2>
      </div>

      <div class="row justify-content-center">
        <div class="col-md-8">
          <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>
          
          <?php if (!empty($success)): ?>
            <div class="alert alert-success">
              <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
            <script>
              setTimeout(function() {
                window.location.href = 'profile.php';
              }, 2000);
            </script>
          <?php endif; ?>
          
          <div class="profile-card">
            <form method="POST" action="" enctype="multipart/form-data">
              <div class="profile-header">
                <input type="file" id="avatar-upload" class="avatar-upload" name="avatar" accept="image/*">
                <img src="../<?php echo htmlspecialchars($avatar); ?>" alt="Avatar" class="profile-avatar" id="profile-avatar">
                <label for="avatar-upload" class="avatar-upload-label">Thay đổi ảnh đại diện</label>
                <h3 id="user-name-display"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p>Thành viên từ: <?php echo $register_date; ?></p>
              </div>
              
              <div class="row">
                <div class="col-md-6">
                  <div class="info-group">
                    <div class="info-label">Họ và tên <span style="color: red;">*</span></div>
                    <input type="text" class="info-input" id="full-name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="info-group">
                    <div class="info-label">Email <span style="color: red;">*</span></div>
                    <input type="email" class="info-input" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                  </div>
                </div>
              </div>
              
              <div class="row">
                <div class="col-md-6">
                  <div class="info-group">
                    <div class="info-label">Số điện thoại <span style="color: red;">*</span></div>
                    <input type="tel" class="info-input" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="info-group">
                    <div class="info-label">Ngày sinh <span style="color: red;">*</span></div>
                    <input type="date" class="info-input" id="birthday" name="birthday" value="<?php echo htmlspecialchars($birthday); ?>" required>
                  </div>
                </div>
              </div>
              
              <div class="info-group">
                <div class="info-label">Địa chỉ <span style="color: red;">*</span></div>
                <input type="text" class="info-input" id="address" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" required>
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

              <!-- Password Change Section -->
              <div class="password-section">
                <h4><i class="fa fa-lock"></i> Đổi mật khẩu</h4>
                <div class="row">
                  <div class="col-md-12">
                    <div class="info-group">
                      <div class="info-label">Mật khẩu hiện tại</div>
                      <input type="password" class="info-input" name="current_password" placeholder="Nhập mật khẩu hiện tại (để trống nếu không đổi)">
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-6">
                    <div class="info-group">
                      <div class="info-label">Mật khẩu mới</div>
                      <input type="password" class="info-input" name="new_password" placeholder="Nhập mật khẩu mới (tối thiểu 6 ký tự)">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group">
                      <div class="info-label">Xác nhận mật khẩu mới</div>
                      <input type="password" class="info-input" name="confirm_password" placeholder="Xác nhận mật khẩu mới">
                    </div>
                  </div>
                </div>
              </div>
              
              <div class="text-center mt-4">
                <button type="submit" class="btn-save">
                  <i class="fa fa-save"></i> Lưu thay đổi
                </button>
                <a href="profile.php" class="btn-cancel">
                  <i class="fa fa-times"></i> Hủy bỏ
                </a>
              </div>
            </form>
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

  <script>
    // Xử lý upload ảnh đại diện
    document.getElementById('avatar-upload').addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          document.getElementById('profile-avatar').src = e.target.result;
        }
        reader.readAsDataURL(file);
      }
    });

    // Focus vào ô đầu tiên khi trang load
    document.getElementById('full-name').focus();

    // Display current year
    document.getElementById('displayYear').innerHTML = new Date().getFullYear();
  </script>

</body>
</html>