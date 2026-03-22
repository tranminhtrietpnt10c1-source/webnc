<?php
// Định dạng tiền VNĐ
function format_vnd($amount) {
    return number_format($amount, 0, ',', '.') . 'đ';
}

// Tính giá bán từ giá vốn và tỷ lệ lợi nhuận
function selling_price($cost, $profit_percent) {
    return $cost * (1 + $profit_percent / 100);
}
?>