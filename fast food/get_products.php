<?php
require_once 'includes/db_connection.php';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 6;
$offset = ($page - 1) * $limit;

$totalStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
$totalProducts = $totalStmt->fetchColumn();
$totalPages = ceil($totalProducts / $limit);

// Tính selling_price trực tiếp trong SQL từ cost_price và profit_percentage
$stmt = $pdo->prepare("SELECT p.id, p.name, p.description, p.image, p.cost_price, p.profit_percentage,
                              (p.cost_price * (1 + p.profit_percentage/100)) AS selling_price
                       FROM products p
                       WHERE p.status = 'active'
                       ORDER BY p.id DESC
                       LIMIT ? OFFSET ?");
$stmt->bindParam(1, $limit, PDO::PARAM_INT);
$stmt->bindParam(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

// Làm tròn giá bán
foreach ($products as &$p) {
    $p['selling_price'] = round($p['selling_price']);
    unset($p['profit_percentage']); // Không cần gửi profit_percentage về client
}

echo json_encode([
    'products' => $products,
    'totalPages' => $totalPages,
    'currentPage' => $page
]);