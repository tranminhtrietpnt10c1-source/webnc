<?php
session_start();
require_once 'includes/db_connection.php';

$page = 1;
$limit = 6;
$offset = 0;

$totalStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
$totalProducts = $totalStmt->fetchColumn();
$totalPages = ceil($totalProducts / $limit);

// SỬA: Chỉ dùng 1 truy vấn tính selling_price từ cost_price và profit_percentage
$stmt = $pdo->prepare("SELECT p.id, p.name, p.description, p.image, p.cost_price, p.profit_percentage,
                              (p.cost_price * (1 + p.profit_percentage/100)) AS selling_price
                       FROM products p
                       WHERE p.status = 'active'
                       ORDER BY p.id DESC
                       LIMIT ? OFFSET ?");
$stmt->bindParam(1, $limit, PDO::PARAM_INT);
$stmt->bindParam(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$featured = $stmt->fetchAll();

// Get cart count for display
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
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <link rel="shortcut icon" href="images/favicon.png" type="">
  <title>Feane</title>
  <link rel="stylesheet" type="text/css" href="css/bootstrap.css" />
  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/css/nice-select.min.css" />
  <link href="css/font-awesome.min.css" rel="stylesheet" />
  <link href="css/style.css" rel="stylesheet" />
  <link href="css/responsive.css" rel="stylesheet" />
  <style>
    .hero_area { min-height: auto !important; }
    .view-more-container { display: flex; justify-content: center; margin-top: 40px; }
    .btn-view-more { background-color: #ffbe33; color: white; padding: 12px 30px; border-radius: 5px; text-decoration: none; font-weight: bold; transition: all 0.3s; }
    .btn-view-more:hover { background-color: #e69c00; }
    .pagination .page-link { color: #8B8000; background-color: #fff; border-color: #FFD700; }
    .pagination .page-link:hover { color: #fff; background-color: #FFD700; border-color: #FFD700; }
    .pagination .page-item.active .page-link { color: #fff; background-color: #FFD700; border-color: #FFD700; }
    .pagination .page-item.disabled .page-link { color: #ccc; background-color: #fff; border-color: #eee; }
    #product-grid { transition: opacity 0.4s ease; }
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
    /* Toast notification styles */
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
    .add-to-cart-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: 0;
    }
    .add-to-cart-btn svg {
      transition: transform 0.2s;
    }
    .add-to-cart-btn:hover svg {
      transform: scale(1.1);
    }
  </style>
</head>
<body>

<div id="loader" class="loader">
  <div class="spinner"></div>
</div>

<div id="toast-message" class="toast-notification"></div>

<div class="hero_area">
  <div class="bg-box"><img src="images/hero-bg.jpg" alt=""></div>
  <header class="header_section">
    <div class="container">
      <nav class="navbar navbar-expand-lg custom_nav-container">
        <a class="navbar-brand" href="index.php"><span>Feane</span></a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
          <span class=""> </span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
          <ul class="navbar-nav mx-auto">
            <li class="nav-item active"><a class="nav-link" href="index.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="menu.php">Menu</a></li>
            <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
            <li class="nav-item">
              <?php if (isset($_SESSION['user_id'])): ?>
                <a class="nav-link" href="order_history.php">Order history</a>
              <?php else: ?>
                <a class="nav-link" href="user/login.php">Order history</a>
              <?php endif; ?>
            </li>
          </ul>
          <div class="user_option">
            <?php if (isset($_SESSION['user_id'])): ?>
              <a href="user/profile.php" class="user_link"><i class="fa fa-user" aria-hidden="true"></i></a>
            <?php else: ?>
              <a href="user/login.php" class="user_link"><i class="fa fa-user" aria-hidden="true"></i></a>
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
            <a href="search.php" class="btn nav_search-btn"><i class="fa fa-search" aria-hidden="true"></i></a>
            <div class="order_online">
              <?php if (isset($_SESSION['user_id'])): ?>
                <a href="user/logout.php" style="color: white;">Đăng xuất</a>
              <?php else: ?>
                <a href="user/login.php" style="color: white;">Đăng nhập/Đăng kí</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <section class="slider_section">
    <div id="customCarousel1" class="carousel slide" data-ride="carousel">
      <div class="carousel-inner">
        <div class="carousel-item active">
          <div class="container">
            <div class="row">
              <div class="col-md-7 col-lg-6">
                <div class="detail-box">
                  <h1>Fast Food</h1>
                  <div class="btn-box">
                    <a href="<?= isset($_SESSION['user_id']) ? 'menu.php' : 'user/login.php' ?>" class="btn1">Order Now</a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<div class="main-content">

  <!-- offer section -->
  <section class="offer_section layout_padding-bottom">
    <div class="offer_container">
      <div class="container">
        <div class="row">
          <div class="col-md-6">
            <div class="box">
              <div class="img-box"><img src="images/o1.jpg" alt=""></div>
              <div class="detail-box">
                <h5>Tasty Thursdays</h5>
                <h6><span>20%</span> Off</h6>
                <a href="<?= isset($_SESSION['user_id']) ? 'cart.php' : 'user/login.php' ?>">Order Now</a>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="box">
              <div class="img-box"><img src="images/o2.jpg" alt=""></div>
              <div class="detail-box">
                <h5>Pizza Days</h5>
                <h6><span>15%</span> Off</h6>
                <a href="<?= isset($_SESSION['user_id']) ? 'cart.php' : 'user/login.php' ?>">Order Now</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- food section -->
  <section class="food_section layout_padding">
    <div class="container">
      <div class="heading_container heading_center">
        <h2>Our Menu</h2>
      </div>
      <div class="filters-content">
        <div class="row grid" id="product-grid">
          <?php if (empty($featured)): ?>
            <div class="col-12 text-center">Chưa có sản phẩm nào.</div>
          <?php else: ?>
            <?php foreach ($featured as $prod): ?>
              <div class="col-sm-6 col-lg-4">
                <div class="box">
                  <a href="product_detail.php?id=<?= $prod['id'] ?>">
                    <div class="img-box"><img src="<?= htmlspecialchars($prod['image']) ?>" alt="<?= htmlspecialchars($prod['name']) ?>"></div>
                  </a>
                  <div class="detail-box">
                    <h5><?= htmlspecialchars($prod['name']) ?></h5>
                    <p><?= htmlspecialchars($prod['description']) ?></p>
                    <div class="options">
                      <h6><?= number_format($prod['selling_price']) ?>đ</h6>
                      <button class="add-to-cart-btn" 
                              data-id="<?= $prod['id'] ?>"
                              data-name="<?= htmlspecialchars($prod['name']) ?>"
                              data-price="<?= $prod['selling_price'] ?>"
                              data-image="<?= htmlspecialchars($prod['image']) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029" xml:space="preserve">
                          <g><path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/></g>
                          <g><path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/></g>
                          <g><path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/></g>
                        </svg>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Pagination do JS render -->
      <div id="pagination-container"></div>

      <div class="view-more-container">
        <a href="menu.php" class="btn-view-more">Xem thêm</a>
      </div>
    </div>
  </section>

</div>

<footer class="footer_section">
  <div class="container">
    <div class="row">
      <div class="col-md-4 footer-col">
        <div class="footer_contact">
          <h4>Contact Us</h4>
          <div class="contact_link_box">
            <a href=""><i class="fa fa-map-marker" aria-hidden="true"></i><span>Location</span></a>
            <a href=""><i class="fa fa-phone" aria-hidden="true"></i><span>Call +01 1234567890</span></a>
            <a href=""><i class="fa fa-envelope" aria-hidden="true"></i><span>demo@gmail.com</span></a>
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
      <p>&copy; <span id="displayYear"></span> All Rights Reserved By <a href="https://html.design/">Free Html Templates</a><br><br>
      &copy; <span id="displayYear"></span> Distributed By <a href="https://themewagon.com/" target="_blank">ThemeWagon</a></p>
    </div>
  </div>
</footer>

<script src="js/jquery-3.4.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
<script src="js/bootstrap.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-nice-select/1.1.0/js/jquery.nice-select.min.js"></script>
<script src="js/custom.js"></script>

<script>
  var currentPage = 1;
  var totalPages = <?= $totalPages ?>;
  var grid = document.getElementById('product-grid');
  var loader = document.getElementById('loader');

  var cartSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 456.029 456.029" xml:space="preserve">'
    + '<g><path d="M345.6,338.862c-29.184,0-53.248,23.552-53.248,53.248c0,29.184,23.552,53.248,53.248,53.248c29.184,0,53.248-23.552,53.248-53.248C398.336,362.926,374.784,338.862,345.6,338.862z"/></g>'
    + '<g><path d="M439.296,84.91c-1.024,0-2.56-0.512-4.096-0.512H112.64l-5.12-34.304C104.448,27.566,84.992,10.67,61.952,10.67H20.48C9.216,10.67,0,19.886,0,31.15c0,11.264,9.216,20.48,20.48,20.48h41.472c2.56,0,4.608,2.048,5.12,4.608l31.744,216.064c4.096,27.136,27.648,47.616,55.296,47.616h212.992c26.624,0,49.664-18.944,55.296-45.056l33.28-166.4C457.728,97.71,450.56,86.958,439.296,84.91z"/></g>'
    + '<g><path d="M215.04,389.55c-1.024-28.16-24.576-50.688-52.736-50.688c-29.696,1.536-52.224,26.112-51.2,55.296c1.024,28.16,24.064,50.688,52.224,50.688h1.024C193.536,443.31,216.576,418.734,215.04,389.55z"/></g>'
    + '</svg>';

  function renderPagination(page, total) {
    if (total <= 1) { 
      document.getElementById('pagination-container').innerHTML = ''; 
      return; 
    }
    var html = '<nav><ul class="pagination justify-content-center mt-4">';
    html += '<li class="page-item ' + (page == 1 ? 'disabled' : '') + '">'
          + '<a class="page-link" href="#" onclick="loadProducts(' + (page-1) + ');return false;">Trước</a></li>';
    for (var i = 1; i <= total; i++) {
      html += '<li class="page-item ' + (i == page ? 'active' : '') + '">'
            + '<a class="page-link" href="#" onclick="loadProducts(' + i + ');return false;">' + i + '</a></li>';
    }
    html += '<li class="page-item ' + (page == total ? 'disabled' : '') + '">'
          + '<a class="page-link" href="#" onclick="loadProducts(' + (page+1) + ');return false;">Tiếp</a></li>';
    html += '</ul></nav>';
    document.getElementById('pagination-container').innerHTML = html;
  }

  function loadProducts(page) {
    if (page < 1 || page > totalPages) return;
    
    // Hiển thị loader và fade out
    if (loader) loader.classList.add('show');
    grid.style.opacity = '0';
    grid.style.transition = 'opacity 0.3s ease';

    fetch('get_products.php?page=' + page)
      .then(function(res) { return res.json(); })
      .then(function(data) {
        var html = '';
        data.products.forEach(function(p) {
          var price = parseInt(p.selling_price).toLocaleString('vi-VN');
          html += '<div class="col-sm-6 col-lg-4"><div class="box">'
                + '<a href="product_detail.php?id=' + p.id + '"><div class="img-box"><img src="' + p.image + '" alt="' + p.name + '"></div></a>'
                + '<div class="detail-box"><h5>' + p.name + '</h5><p>' + p.description + '</p>'
                + '<div class="options"><h6>' + price + 'đ</h6>'
                + '<button class="add-to-cart-btn" data-id="' + p.id + '" data-name="' + p.name + '" data-price="' + p.selling_price + '" data-image="' + p.image + '">' + cartSvg + '</button>'
                + '</div></div></div></div>';
        });

        grid.innerHTML = html;
        currentPage = data.currentPage;
        totalPages = data.totalPages;
        
        // Re-attach event listeners to new buttons
        attachCartEvents();

        // Fade in và ẩn loader
        setTimeout(function() { 
          grid.style.opacity = '1';
          if (loader) loader.classList.remove('show');
        }, 200);

        // Cập nhật pagination
        renderPagination(data.currentPage, data.totalPages);

        // Scroll mượt xuống menu
        document.querySelector('.food_section').scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function(error) {
        console.error('Lỗi tải sản phẩm:', error);
        grid.style.opacity = '1';
        if (loader) loader.classList.remove('show');
        alert('Có lỗi xảy ra khi tải sản phẩm. Vui lòng thử lại.');
      });
  }

  // Function to show toast notification
  function showToast(message) {
    var toast = document.getElementById('toast-message');
    toast.textContent = message;
    toast.style.display = 'block';
    setTimeout(function() {
      toast.style.display = 'none';
    }, 2000);
  }

  // Function to update cart count in header
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

  // Function to add product to cart via AJAX
  function addToCart(productId, name, price, image) {
    // Check if user is logged in
    <?php if (!isset($_SESSION['user_id'])): ?>
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
        showToast('Đã thêm ' + name + ' vào giỏ hàng!');
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
      // Remove existing listener to avoid duplicates
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

  // Render pagination lần đầu và attach events
  renderPagination(currentPage, totalPages);
  attachCartEvents();
  
  // Display current year
  document.getElementById('displayYear').innerHTML = new Date().getFullYear();
</script>

</body>
</html>