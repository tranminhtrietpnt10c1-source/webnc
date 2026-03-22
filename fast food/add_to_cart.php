<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $price = $_POST['price'] ?? 0;
    $image = $_POST['image'] ?? '';
    $options = $_POST['options'] ?? '';
    $quantity = $_POST['quantity'] ?? 1;
    
    if ($product_id && $name && $price) {
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$product_id] = [
                'id' => $product_id,
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'image' => $image,
                'options' => $options
            ];
        }
        
        $cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
        
        echo json_encode([
            'success' => true, 
            'message' => 'Đã thêm ' . $name . ' vào giỏ hàng!',
            'cart_count' => $cart_count
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Thông tin sản phẩm không hợp lệ']);
?>