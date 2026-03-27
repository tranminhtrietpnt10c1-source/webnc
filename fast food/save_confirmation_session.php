<?php
session_start();

if (isset($_POST['order_id'])) {
    $order_id = (int)$_POST['order_id'];
    
    if (!isset($_SESSION['confirmed_orders'])) {
        $_SESSION['confirmed_orders'] = [];
    }
    
    if (!in_array($order_id, $_SESSION['confirmed_orders'])) {
        $_SESSION['confirmed_orders'][] = $order_id;
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
?>