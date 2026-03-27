<?php
session_start();
require_once __DIR__ . '/../includes/db_connection.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$error = '';
$email = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    $errors = [];

    if (empty($email)) {
        $errors[] = 'Email không được để trống.';
    }
    if (empty($password)) {
        $errors[] = 'Mật khẩu không được để trống.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id, full_name, email, phone, address, birthday, password, status FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Check account status
                if ($user['status'] !== 'active') {
                    $errors[] = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ admin.';
                } else {
                    // Set session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_phone'] = $user['phone'];
                    $_SESSION['user_address'] = $user['address'];
                    $_SESSION['user_birthday'] = $user['birthday'];

                    // Remember me (30 days)
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                        
                        $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, token_expires = ? WHERE id = ?");
                        $stmt->execute([$token, $expires, $user['id']]);
                        
                        setcookie('remember_token', $token, time() + (86400 * 30), "/");
                    }

                    // Check if user has complete information
                    if (empty($user['address']) || empty($user['birthday'])) {
                        $_SESSION['warning'] = 'Vui lòng cập nhật đầy đủ thông tin cá nhân (địa chỉ và ngày sinh) để có thể đặt hàng.';
                        header('Location: profile.php');
                        exit;
                    }

                    // Merge cart from session if exists
                    if (isset($_SESSION['temp_cart']) && !empty($_SESSION['temp_cart'])) {
                        $_SESSION['cart'] = $_SESSION['temp_cart'];
                        unset($_SESSION['temp_cart']);
                    }

                    $_SESSION['success'] = 'Đăng nhập thành công! Chào mừng ' . $user['full_name'] . ' trở lại.';
                    header('Location: ../index.php');
                    exit;
                }
            } else {
                $errors[] = 'Email hoặc mật khẩu không đúng.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

// Handle remember me cookie
if (isset($_COOKIE['remember_token']) && !isset($_SESSION['user_id'])) {
    $token = $_COOKIE['remember_token'];
    try {
        $stmt = $pdo->prepare("SELECT id, full_name, email, phone, address, birthday FROM users WHERE remember_token = ? AND token_expires > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_phone'] = $user['phone'];
            $_SESSION['user_address'] = $user['address'];
            $_SESSION['user_birthday'] = $user['birthday'];
            
            header('Location: ../index.php');
            exit;
        }
    } catch (PDOException $e) {
        // Token invalid, ignore
    }
}

// Get warning message
$warning = $_SESSION['warning'] ?? '';
unset($_SESSION['warning']);
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
  <link rel="shortcut icon" href="../images/favicon.png" type="">

  <title>Đăng nhập - Feane</title>

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

    .login-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), url('https://images.unsplash.com/photo-1504674900247-0877df9cc836?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1170&q=80') no-repeat center center;
      background-size: cover;
    }

    .login-card {
      background-color: var(--light-color);
      border-radius: 15px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
      width: 100%;
      max-width: 450px;
      padding: 40px 35px;
      position: relative;
      animation: fadeInUp 0.6s ease;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .login-logo {
      text-align: center;
      margin-bottom: 30px;
    }

    .login-logo h2 {
      color: var(--secondary-color);
      font-weight: 700;
      margin-bottom: 5px;
      font-size: 32px;
    }

    .login-logo h2 i {
      color: var(--primary-color);
      margin-right: 8px;
    }

    .login-logo p {
      color: #666;
      font-size: 14px;
    }

    .form-group {
      margin-bottom: 25px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #333;
      font-weight: 500;
      font-size: 14px;
    }

    .form-control {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 8px;
      font-size: 15px;
      transition: all 0.3s;
    }

    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(255, 190, 51, 0.25);
      outline: none;
    }

    .input-icon {
      position: relative;
    }

    .input-icon i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #999;
      font-size: 16px;
    }

    .input-icon .form-control {
      padding-left: 45px;
      padding-right: 45px;
    }

    /* Style for password toggle button */
    .password-toggle {
      position: absolute;
      right: 15px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: #999;
      font-size: 16px;
      z-index: 10;
      background: transparent;
      border: none;
      padding: 0;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .password-toggle:hover {
      color: var(--primary-color);
    }

    .btn-login {
      background-color: var(--primary-color);
      color: white;
      border: none;
      padding: 14px 20px;
      border-radius: 8px;
      width: 100%;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      margin-top: 10px;
    }

    .btn-login:hover {
      background-color: #e6a500;
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(255, 190, 51, 0.3);
    }

    .checkbox-group {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 20px 0;
    }

    .checkbox-group label {
      margin-bottom: 0;
      font-size: 14px;
      font-weight: normal;
      cursor: pointer;
    }

    .checkbox-group input {
      margin-right: 8px;
      cursor: pointer;
    }

    .forgot-password {
      color: var(--primary-color);
      text-decoration: none;
      font-size: 14px;
      transition: all 0.3s;
    }

    .forgot-password:hover {
      text-decoration: underline;
      color: #e6a500;
    }

    .form-footer {
      text-align: center;
      margin-top: 25px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }

    .form-footer p {
      margin-bottom: 0;
      color: #666;
      font-size: 14px;
    }

    .form-footer a {
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s;
    }

    .form-footer a:hover {
      text-decoration: underline;
      color: #e6a500;
    }

    .back-to-home {
      position: absolute;
      top: 20px;
      left: 20px;
      color: #666;
      text-decoration: none;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 5px;
      transition: all 0.3s;
    }

    .back-to-home:hover {
      color: var(--primary-color);
    }

    .alert {
      padding: 12px 15px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: 14px;
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

    .social-login {
      margin-top: 25px;
      text-align: center;
    }

    .social-login p {
      color: #999;
      font-size: 13px;
      margin-bottom: 15px;
      position: relative;
    }

    .social-login p::before,
    .social-login p::after {
      content: "";
      position: absolute;
      top: 50%;
      width: 30%;
      height: 1px;
      background-color: #ddd;
    }

    .social-login p::before {
      left: 0;
    }

    .social-login p::after {
      right: 0;
    }

    .social-buttons {
      display: flex;
      gap: 15px;
      justify-content: center;
    }

    .social-btn {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition: all 0.3s;
      border: 1px solid #ddd;
      color: #666;
    }

    .social-btn:hover {
      transform: translateY(-3px);
    }


    @media (max-width: 576px) {
      .login-card {
        padding: 30px 25px;
      }
      
      .back-to-home {
        position: static;
        margin-bottom: 20px;
        display: inline-flex;
      }
    }
  </style>
</head>

<body>
  <div class="login-container">
    <div class="login-card">
      <a href="../index.php" class="back-to-home">
        <i class="fas fa-arrow-left"></i> Trở về trang chủ
      </a>
      
      <div class="login-logo">
        <h2><i class="fas fa-utensils"></i> Feane</h2>
        <p>Đăng nhập để đặt món và nhận ưu đãi</p>
      </div>

      <!-- Display warning message -->
      <?php if (!empty($warning)): ?>
        <div class="alert alert-warning">
          <i class="fas fa-exclamation-triangle"></i> <?php echo $warning; ?>
        </div>
      <?php endif; ?>

      <!-- Display error messages -->
      <?php if (!empty($error)): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form method="POST" action="">
        <div class="form-group">
          <label for="email">Email</label>
          <div class="input-icon">
            <i class="fas fa-envelope"></i>
            <input type="email" class="form-control" id="email" name="email" placeholder="Nhập địa chỉ email" value="<?php echo htmlspecialchars($email); ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label for="password">Mật khẩu</label>
          <div class="input-icon" id="password-wrapper">
            <i class="fas fa-lock"></i>
            <input type="password" class="form-control" id="password" name="password" placeholder="Nhập mật khẩu" required>
            <button type="button" class="password-toggle" id="togglePassword">
              <i class="fas fa-eye-slash"></i>
            </button>
          </div>
        </div>

        <div class="checkbox-group">
          <label>
            <input type="checkbox" name="remember"> Ghi nhớ đăng nhập
          </label>
          
        </div>

        <button type="submit" class="btn-login">
          <i class="fas fa-sign-in-alt"></i> Đăng nhập
        </button>

        
      </form>
    </div>
  </div>

  <script>
    // Password visibility toggle with correct positioning
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    togglePassword.addEventListener('click', function() {
      // Toggle the type attribute
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      
      // Toggle the icon
      const icon = this.querySelector('i');
      icon.classList.toggle('fa-eye-slash');
      icon.classList.toggle('fa-eye');
    });
  </script>
</body>

</html>