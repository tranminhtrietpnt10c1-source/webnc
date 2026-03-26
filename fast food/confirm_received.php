<?php
session_start();
require_once 'includes/db_connection.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: user/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id <= 0) {
    $_SESSION['error'] = 'Không tìm thấy đơn hàng.';
    header('Location: order_history.php');
    exit;
}

try {
    // Kiểm tra đơn hàng
    $stmt = $pdo->prepare("
        SELECT id, order_code, status 
        FROM orders 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $_SESSION['error'] = 'Không tìm thấy đơn hàng.';
        header('Location: order_history.php');
        exit;
    }
    
    // Chỉ cho phép xác nhận khi trạng thái là 'shipped' (Đơn hàng đang đến)
    if ($order['status'] != 'shipped') {
        $_SESSION['error'] = 'Không thể xác nhận đã nhận hàng ở trạng thái hiện tại.';
        header('Location: order_detail.php?id=' . $order_id);
        exit;
    }
    
    // Cập nhật trạng thái thành 'delivered' và ghi notes
    $notes = 'Đã giao hàng thành công đến khách hàng lúc ' . date('d/m/Y H:i:s');
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'delivered', 
            notes = CONCAT(IFNULL(notes, ''), ?),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute(["\n" . $notes, $order_id]);
    
    $_SESSION['success'] = 'Cảm ơn bạn đã xác nhận đã nhận hàng!';
    
    header('Location: order_detail.php?id=' . $order_id);
    exit;
    
} catch (PDOException $e) {
    $_SESSION['error'] = 'Có lỗi xảy ra: ' . $e->getMessage();
    header('Location: order_detail.php?id=' . $order_id);
    exit;
}
?>