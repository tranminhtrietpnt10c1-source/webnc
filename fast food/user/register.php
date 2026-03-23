<?php
session_start();
require_once __DIR__ . '/../includes/db_connection.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$error = '';
$success = '';
$fullname = $email = $phone = $address = $birthday = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $birthday = trim($_POST['birthday'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm-password'] ?? '';
    $agree    = isset($_POST['agree-terms']);

    $errors = [];

    // Validate required fields
    if (empty($fullname)) $errors[] = 'Họ và tên không được để trống.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email không hợp lệ.';
    if (empty($phone)) $errors[] = 'Số điện thoại không được để trống.';
    if (empty($address)) $errors[] = 'Địa chỉ giao hàng không được để trống.';
    if (empty($birthday)) {
        $errors[] = 'Ngày sinh không được để trống.';
    } else {
        // Validate birthday format and age (must be at least 10 years old)
        $birthday_date = date_create($birthday);
        $today = new DateTime();
        $age = $today->diff($birthday_date)->y;
        if (!$birthday_date) {
            $errors[] = 'Ngày sinh không hợp lệ.';
        } elseif ($age < 10) {
            $errors[] = 'Bạn phải từ 10 tuổi trở lên để đăng ký tài khoản.';
        }
    }
    
    if (strlen($password) < 6) $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
    if ($password !== $confirm) $errors[] = 'Mật khẩu xác nhận không khớp.';
    if (!$agree) $errors[] = 'Bạn phải đồng ý điều khoản.';

    // Validate phone number (Vietnam phone format)
    if (!empty($phone) && !preg_match('/^(0[3|5|7|8|9])+([0-9]{8})$/', $phone)) {
        $errors[] = 'Số điện thoại không hợp lệ. Vui lòng nhập số điện thoại Việt Nam (10 số, bắt đầu bằng 03, 05, 07, 08, 09).';
    }

    if (empty($errors)) {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Email đã tồn tại.';
        }
    }
    
    // Check if phone already exists
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            $errors[] = 'Số điện thoại đã được đăng ký.';
        }
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        // Format birthday to Y-m-d for database
        $birthday_formatted = date('Y-m-d', strtotime($birthday));
        
        $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, phone, address, birthday, password, register_date, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'user', 'active')");
        $stmt->execute([$fullname, $email, $email, $phone, $address, $birthday_formatted, $hashed]);

        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['user_name'] = $fullname;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_phone'] = $phone;
        $_SESSION['user_address'] = $address;

        $success = "Đăng ký thành công! Chào mừng bạn đến với Feane.";
        
        // Redirect after 2 seconds
        header("refresh:2;url=../index.php");
    } else {
        $error = implode('<br>', $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <meta name="keywords" content="" />
  <meta name="description" content="" />
  <meta name="author" content="" />
  <link rel="shortcut icon" href="images/favicon.png" type="">

  <title>Đăng ký - Feane</title>

  <!-- Bootstrap core CSS -->
  <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" />

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    :root {
      --primary-color: #ffbe33;
      --secondary-color: #222831;
      --light-color: #ffffff;
    }

    body {
      font-family: 'Roboto', sans-serif;
      background-color: #f8f9fa;
      margin: 0;
      padding: 0;
    }

    .register-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)), url('https://images.unsplash.com/photo-1504674900247-0877df9cc836?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1170&q=80') no-repeat center center;
      background-size: cover;
    }

    .register-card {
      background-color: var(--light-color);
      border-radius: 10px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      width: 100%;
      max-width: 550px;
      padding: 40px 30px;
      position: relative;
    }

    .register-logo {
      text-align: center;
      margin-bottom: 30px;
    }

    .register-logo h2 {
      color: var(--secondary-color);
      font-weight: 700;
      margin-bottom: 5px;
    }

    .register-logo p {
      color: #666;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #333;
      font-weight: 500;
    }

    .form-group label .required {
      color: red;
      margin-left: 3px;
    }

    .form-control {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 5px;
      font-size: 16px;
      transition: all 0.3s;
    }

    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(255, 190, 51, 0.25);
      outline: none;
    }

    .btn-primary {
      background-color: var(--primary-color);
      color: white;
      border: none;
      padding: 14px 20px;
      border-radius: 5px;
      width: 100%;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: background-color 0.3s;
      margin-top: 10px;
      display: inline-block;
      text-align: center;
      text-decoration: none;
    }
    
    .btn-primary:hover {
      background-color: #e6a500;
    }

    .terms-check {
      margin: 20px 0;
    }

    .form-footer {
      text-align: center;
      margin-top: 25px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }

    .form-footer a {
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 500;
    }

    .form-footer a:hover {
      text-decoration: underline;
    }

    .back-to-home {
      position: absolute;
      top: 20px;
      left: 20px;
      color: var(--secondary-color);
      text-decoration: none;
      font-size: 16px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .back-to-home:hover {
      color: var(--primary-color);
    }

    .input-icon {
      position: relative;
    }

    .input-icon i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #777;
    }

    .input-icon .form-control {
      padding-left: 45px;
    }

    .terms-check {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-top: 15px;
    }

    .terms-check input {
      margin-top: 3px;
    }

    .terms-check label {
      font-size: 14px;
      color: #555;
      font-weight: normal;
    }

    .terms-check a {
      color: var(--primary-color);
      text-decoration: none;
    }

    .terms-check a:hover {
      text-decoration: underline;
    }

    @media (max-width: 576px) {
      .register-card {
        padding: 30px 20px;
      }
      
      .back-to-home {
        position: static;
        margin-bottom: 20px;
        display: inline-flex;
      }
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
    
    .success-message {
      text-align: center;
      padding: 30px;
    }
    
    .success-message i {
      font-size: 48px;
      color: #28a745;
      margin-bottom: 15px;
    }
    
    .success-message h3 {
      color: #155724;
      margin-bottom: 10px;
    }
    
    .success-message p {
      color: #666;
      margin-bottom: 20px;
    }

    .form-text {
      font-size: 12px;
      color: #6c757d;
      margin-top: 5px;
    }

    .form-text i {
      margin-right: 3px;
    }
  </style>
</head>

<body>
  <div class="register-container">
    <div class="register-card">
      <a href="../index.php" class="back-to-home">
        <i class="fas fa-arrow-left"></i> Trở về trang chủ
      </a>
      
      <div class="register-logo">
        <h2><i class="fas fa-utensils"></i> Feane</h2>
        <p>Tạo tài khoản mới</p>
      </div>

      <!-- Display success message -->
      <?php if (!empty($success)): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
      <?php endif; ?>

      <!-- Display error messages -->
      <?php if (!empty($error)): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
      <?php endif; ?>

      <!-- Registration Form -->
      <form method="POST" action="">
        <div class="form-group">
          <label for="fullname">Họ và tên <span class="required">*</span></label>
          <div class="input-icon">
            <i class="fas fa-user"></i>
            <input type="text" class="form-control" id="fullname" name="fullname" placeholder="Nhập họ và tên" value="<?php echo htmlspecialchars($fullname); ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label for="email">Email <span class="required">*</span></label>
          <div class="input-icon">
            <i class="fas fa-envelope"></i>
            <input type="email" class="form-control" id="email" name="email" placeholder="Nhập địa chỉ email" value="<?php echo htmlspecialchars($email); ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label for="phone">Số điện thoại <span class="required">*</span></label>
          <div class="input-icon">
            <i class="fas fa-phone"></i>
            <input type="tel" class="form-control" id="phone" name="phone" placeholder="Nhập số điện thoại (VD: 0987654321)" value="<?php echo htmlspecialchars($phone); ?>" required>
          </div>
          <div class="form-text">
            <i class="fas fa-info-circle"></i> Số điện thoại Việt Nam, bắt đầu bằng 03, 05, 07, 08, 09
          </div>
        </div>

        <div class="form-group">
          <label for="address">Địa chỉ giao hàng <span class="required">*</span></label>
          <div class="input-icon">
            <i class="fas fa-map-marker-alt"></i>
            <input type="text" class="form-control" id="address" name="address" placeholder="Nhập địa chỉ giao hàng (số nhà, đường, phường/xã, quận/huyện, tỉnh/thành)" value="<?php echo htmlspecialchars($address); ?>" required>
          </div>
          <div class="form-text">
            <i class="fas fa-truck"></i> Địa chỉ này sẽ được sử dụng để giao hàng
          </div>
        </div>

        <div class="form-group">
          <label for="birthday">Ngày sinh <span class="required">*</span></label>
          <div class="input-icon">
            <i class="fas fa-calendar-alt"></i>
            <input type="date" class="form-control" id="birthday" name="birthday" value="<?php echo htmlspecialchars($birthday); ?>" required>
          </div>
          <div class="form-text">
            <i class="fas fa-gift"></i> Bạn phải từ 10 tuổi trở lên để đăng ký tài khoản
          </div>
        </div>

        <div class="form-group">
          <label for="password">Mật khẩu <span class="required">*</span></label>
          <div class="input-icon">
            <i class="fas fa-lock"></i>
            <input type="password" class="form-control" id="password" name="password" placeholder="Nhập mật khẩu (tối thiểu 6 ký tự)" minlength="6" required>
          </div>
        </div>

        <div class="form-group">
          <label for="confirm-password">Xác nhận mật khẩu <span class="required">*</span></label>
          <div class="input-icon">
            <i class="fas fa-lock"></i>
            <input type="password" class="form-control" id="confirm-password" name="confirm-password" placeholder="Xác nhận mật khẩu" minlength="6" required>
          </div>
        </div>

        <div class="terms-check">
          <input type="checkbox" id="agree-terms" name="agree-terms" required>
          <label for="agree-terms">Tôi đồng ý với <a href="#">Điều khoản dịch vụ</a> và <a href="#">Chính sách bảo mật</a> của Feane</label>
        </div>

        <button type="submit" class="btn-primary">Đăng ký</button>

        <div class="form-footer">
          <p>Đã có tài khoản? <a href="login.php">Đăng nhập ngay</a></p>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Password confirmation validation
    document.getElementById('confirm-password').addEventListener('input', function() {
      var password = document.getElementById('password').value;
      var confirm = this.value;
      
      if (password !== confirm) {
        this.setCustomValidity('Mật khẩu xác nhận không khớp');
      } else {
        this.setCustomValidity('');
      }
    });
    
    document.getElementById('password').addEventListener('input', function() {
      var confirm = document.getElementById('confirm-password').value;
      if (confirm !== '') {
        if (this.value !== confirm) {
          document.getElementById('confirm-password').setCustomValidity('Mật khẩu xác nhận không khớp');
        } else {
          document.getElementById('confirm-password').setCustomValidity('');
        }
      }
    });
    
    // Validate age (at least 10 years old)
    document.getElementById('birthday').addEventListener('change', function() {
      var birthday = new Date(this.value);
      var today = new Date();
      var age = today.getFullYear() - birthday.getFullYear();
      var monthDiff = today.getMonth() - birthday.getMonth();
      
      if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthday.getDate())) {
        age--;
      }
      
      if (age < 10 && this.value !== '') {
        this.setCustomValidity('Bạn phải từ 10 tuổi trở lên để đăng ký tài khoản');
      } else {
        this.setCustomValidity('');
      }
    });
  </script>
</body>

</html>