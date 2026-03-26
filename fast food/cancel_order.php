<?php
session_start();
require_once 'includes/db_connection.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập']);
        exit;
    }
    header('Location: user/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Hỗ trợ cả GET và POST
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$cancel_reason = isset($_GET['reason']) ? trim($_GET['reason']) : (isset($_POST['reason']) ? trim($_POST['reason']) : '');

if ($order_id <= 0) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy đơn hàng']);
        exit;
    }
    $_SESSION['cancel_error'] = 'Không tìm thấy đơn hàng.';
    header('Location: order_history.php');
    exit;
}

try {
    // Kiểm tra đơn hàng
    $stmt = $pdo->prepare("
        SELECT id, order_code, status, notes
        FROM orders 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy đơn hàng']);
            exit;
        }
        $_SESSION['cancel_error'] = 'Không tìm thấy đơn hàng.';
        header('Location: order_history.php');
        exit;
    }
    
    // Kiểm tra trạng thái hiện tại
    $current_status = $order['status'];
    
    // Cho phép hủy khi trạng thái là 'pending' (chờ xử lý) hoặc 'new' (mới đặt)
    $allowed_statuses = ['pending', 'new'];
    
    if (!in_array($current_status, $allowed_statuses)) {
        $message = 'Đơn hàng không thể hủy ở trạng thái hiện tại ("' . $current_status . '"). Chỉ có thể hủy khi đơn hàng ở trạng thái "Chờ xử lý" hoặc "Mới đặt".';
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        $_SESSION['cancel_error'] = $message;
        header('Location: order_detail.php?id=' . $order_id);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Tạo nội dung ghi chú hủy
    $current_notes = $order['notes'] ?? '';
    $cancel_note = "\n========== HỦY ĐƠN HÀNG ==========\n";
    $cancel_note .= "Thời gian hủy: " . date('Y-m-d H:i:s') . "\n";
    $cancel_note .= "Lý do hủy: " . $cancel_reason . "\n";
    $cancel_note .= "==================================\n";
    $new_notes = $current_notes . $cancel_note;
    
    // Cập nhật trạng thái thành 'cancelled' và ghi lý do hủy vào notes
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'cancelled', 
            notes = ?
        WHERE id = ?
    ");
    $result = $stmt->execute([$new_notes, $order_id]);
    
    if (!$result) {
        throw new Exception('Không thể cập nhật trạng thái đơn hàng');
    }
    
    $pdo->commit();
    
    // Lưu thông tin vào session để hiển thị thông báo
    $_SESSION['cancel_success'] = 'Đơn hàng #' . $order['order_code'] . ' đã được hủy thành công.';
    
    // Trả về JSON cho AJAX request
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Đơn hàng đã được hủy thành công',
            'order_id' => $order_id,
            'order_code' => $order['order_code']
        ]);
        exit;
    }
    
    // Chuyển đến trang chi tiết đơn hàng đã hủy
    header('Location: cancelled_order_detail.php?id=' . $order_id);
    exit;
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $error_message = 'Có lỗi xảy ra khi hủy đơn hàng: ' . $e->getMessage();
    
    // Ghi log lỗi
    error_log($error_message);
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $error_message]);
        exit;
    }
    
    $_SESSION['cancel_error'] = $error_message;
    header('Location: order_detail.php?id=' . $order_id);
    exit;
}
?>