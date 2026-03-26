-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Máy chủ: 127.0.0.1
-- Thời gian đã tạo: Th3 26, 2026 lúc 02:06 AM
-- Phiên bản máy phục vụ: 10.4.32-MariaDB
-- Phiên bản PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `fast_food`
--

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `session_id` varchar(100) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL CHECK (`quantity` > 0),
  `unit_price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `cart`
--

INSERT INTO `cart` (`id`, `user_id`, `session_id`, `product_id`, `quantity`, `unit_price`, `created_at`, `updated_at`) VALUES
(1, 2, 'session_abc123', 1, 2, 130000.00, '2026-03-21 05:55:08', '2026-03-21 05:55:08'),
(2, 2, 'session_abc123', 4, 1, 47250.00, '2026-03-21 05:55:08', '2026-03-21 05:55:08'),
(3, 3, 'session_def456', 5, 3, 43200.00, '2026-03-21 05:55:08', '2026-03-21 05:55:08'),
(4, 5, 'session_ghi789', 9, 2, 18000.00, '2026-03-22 06:20:18', '2026-03-22 06:20:18'),
(5, 5, 'session_ghi789', 8, 1, 84000.00, '2026-03-22 06:20:18', '2026-03-22 06:20:18');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`, `image`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Pizza', 'Đế bánh giòn tan, phủ phô mai béo ngậy, hương vị thơm ngon', 'images/f6.png', 'active', '2026-03-21 05:41:38', '2026-03-23 07:30:00'),
(2, 'Burger', 'Burger đa dạng loại thịt, sốt đặc biệt, rau tươi ngon', 'images/f7.png', 'active', '2026-03-21 05:41:38', '2026-03-23 07:30:00'),
(3, 'Pasta', 'Mì mềm mịn, sốt kem thơm ngon, hương vị Ý đặc trưng', 'images/f9.png', 'active', '2026-03-21 05:41:38', '2026-03-23 07:30:00'),
(4, 'Fries', 'Khoai tây chiên giòn rụm, vàng ươm, thơm ngon', 'images/f5.png', 'active', '2026-03-21 05:41:38', '2026-03-24 00:08:20');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `imports`
--

CREATE TABLE `imports` (
  `id` int(11) NOT NULL,
  `import_code` varchar(20) NOT NULL,
  `import_date` date NOT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `imports`
--

INSERT INTO `imports` (`id`, `import_code`, `import_date`, `supplier`, `total_amount`, `status`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'IMP20260321001', '2026-03-21', 'Công ty Thực phẩm ABC', 9900000.00, 'completed', 'Nhập hàng đợt 1 - Pizza', 1, '2026-03-21 05:52:57', '2026-03-21 05:52:57'),
(2, 'IMP20260321002', '2026-03-21', 'Công ty Thực phẩm XYZ', 3020000.00, 'completed', 'Nhập hàng đợt 2 - Burger', 1, '2026-03-21 05:52:57', '2026-03-21 05:52:57'),
(3, 'IMP20260322001', '2026-03-22', 'Công ty Thực phẩm DEF', 5160000.00, 'completed', 'Nhập hàng đợt 3 - Pasta, Fries và Pizza hải sản lần 2', 1, '2026-03-22 07:00:00', '2026-03-22 07:00:00'),
(4, 'IMP20260323001', '2026-03-23', 'Công ty Thực phẩm GHI', 4620000.00, 'completed', 'Nhập hàng đợt 4 - Pizza phô mai và Burger bò lần 2', 1, '2026-03-23 07:00:00', '2026-03-23 07:00:00'),
(5, 'IMP20260324001', '2026-03-24', 'Công ty Thực phẩm JKL', 5700000.00, 'completed', 'Nhập hàng đợt 5 - Pizza 3 vị, Burger ức gà, Burger gà chiên lần 2', 1, '2026-03-24 07:00:00', '2026-03-24 07:00:00'),
(6, 'IMP20260325001', '2026-03-25', 'Công ty Thực phẩm MNO', 4960000.00, 'completed', 'Nhập hàng đợt 6 - Pasta và Fries lần 2', 1, '2026-03-25 07:00:00', '2026-03-25 07:00:00'),
(7, 'PN-20260325-69C3EE39', '2026-03-25', 'Công ty TNHH Huỳnh Ngọc Quí', 1630000.00, 'pending', 'Đợt nhập 25/03/2026 15:16:25', 7, '2026-03-25 14:16:25', '2026-03-25 14:16:25');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `import_details`
--

CREATE TABLE `import_details` (
  `id` int(11) NOT NULL,
  `import_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL CHECK (`quantity` > 0),
  `unit_cost` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `import_details`
--

INSERT INTO `import_details` (`id`, `import_id`, `product_id`, `quantity`, `unit_cost`, `subtotal`) VALUES
(1, 1, 1, 50, 100000.00, 5000000.00),
(2, 1, 2, 30, 90000.00, 2700000.00),
(3, 1, 3, 20, 110000.00, 2200000.00),
(4, 2, 4, 40, 35000.00, 1400000.00),
(5, 2, 5, 30, 32000.00, 960000.00),
(6, 2, 6, 20, 33000.00, 660000.00),
(7, 3, 7, 35, 55000.00, 1925000.00),
(8, 3, 8, 25, 60000.00, 1500000.00),
(9, 3, 9, 100, 12000.00, 1200000.00),
(10, 3, 1, 20, 105000.00, 2100000.00),
(11, 4, 2, 25, 95000.00, 2375000.00),
(12, 4, 4, 30, 36000.00, 1080000.00),
(13, 4, 5, 15, 35000.00, 525000.00),
(14, 5, 3, 25, 115000.00, 2875000.00),
(15, 5, 6, 25, 34500.00, 862500.00),
(16, 5, 5, 25, 34000.00, 850000.00),
(17, 6, 7, 30, 58000.00, 1740000.00),
(18, 6, 8, 30, 62000.00, 1860000.00),
(19, 6, 9, 80, 12500.00, 1000000.00),
(20, 7, 6, 15, 32000.00, 480000.00),
(21, 7, 3, 10, 115000.00, 1150000.00);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `loyalty_points`
--

CREATE TABLE `loyalty_points` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `loyalty_points`
--

INSERT INTO `loyalty_points` (`id`, `user_id`, `points`, `created_at`, `updated_at`) VALUES
(1, 2, 25, '2026-03-21 05:53:40', '2026-03-21 05:53:40'),
(2, 3, 30, '2026-03-21 05:53:40', '2026-03-21 05:53:40'),
(3, 4, 0, '2026-03-21 05:53:40', '2026-03-21 05:53:40'),
(4, 5, 70, '2026-03-22 06:20:18', '2026-03-23 07:00:00'),
(5, 7, 10, '2026-03-23 07:00:00', '2026-03-23 07:00:00');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_code` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `customer_address` varchar(255) DEFAULT NULL,
  `order_date` date NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `shipping_fee` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `final_amount` decimal(10,2) NOT NULL,
  `status` enum('new','processing','shipped','cancelled') DEFAULT 'new',
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `orders`
--

INSERT INTO `orders` (`id`, `order_code`, `user_id`, `customer_name`, `customer_phone`, `customer_email`, `customer_address`, `order_date`, `total_amount`, `shipping_fee`, `discount`, `final_amount`, `status`, `payment_method`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'ORD001', 2, 'Nguyễn Văn A', '0912345678', 'nguyenvana@example.com', '12 Nguyễn Huệ, Quận 1, TP.HCM', '2026-03-21', 307250.00, 30000.00, 0.00, 337250.00, 'shipped', 'cash', 'Giao trước 18h', '2026-03-21 05:53:40', '2026-03-23 07:00:00'),
(2, 'ORD002', 3, 'Trần Thị B', '0987654321', 'tranthib@example.com', '45 Lê Lợi, Quận 3, TP.HCM', '2026-03-21', 129600.00, 30000.00, 0.00, 159600.00, 'shipped', 'transfer', 'Đã thanh toán qua chuyển khoản', '2026-03-21 05:53:40', '2026-03-23 07:00:00'),
(3, 'ORD003', 4, 'Lê Văn C', '0123456789', 'levanc@example.com', '78 Pasteur, Quận 1, TP.HCM', '2026-03-21', 215000.00, 30000.00, 10000.00, 235000.00, 'cancelled', 'cash', 'Khách hủy đơn', '2026-03-21 06:00:00', '2026-03-23 07:00:00'),
(4, 'ORD004', 5, 'Trần Minh Triết', '0339111480', 'triet@gmail.com', '123 Đường ABC, Quận 1, TP.HCM', '2026-03-22', 179000.00, 30000.00, 0.00, 209000.00, 'processing', 'cash', 'Giao hàng tận nơi', '2026-03-22 06:20:18', '2026-03-23 07:00:00'),
(5, 'ORD005', 5, 'Trần Minh Triết', '0339111480', 'triet@gmail.com', '123 Đường ABC, Quận 1, TP.HCM', '2026-03-22', 18000.00, 30000.00, 0.00, 48000.00, 'new', 'cash', 'Đơn hàng nhỏ', '2026-03-22 06:47:05', '2026-03-23 07:00:00'),
(6, 'ORD006', 5, 'Trần Minh Triết', '0339111480', 'triet@gmail.com', '123 Đường ABC, Quận 1, TP.HCM', '2026-03-22', 102000.00, 30000.00, 0.00, 132000.00, 'new', 'cash', 'Giao hàng tận nơi', '2026-03-22 06:47:26', '2026-03-23 07:00:00'),
(7, 'ORD007', 5, 'Trần Minh Triết', '0339111480', 'triet@gmail.com', '123 Đường ABC, Quận 1, TP.HCM', '2026-03-22', 105750.00, 30000.00, 0.00, 135750.00, 'processing', 'cash', '', '2026-03-22 06:50:26', '2026-03-23 07:00:00'),
(8, 'ORD008', 5, 'Trần Minh Triết', '0339111480', 'triet@gmail.com', '123 Đường ABC, Quận 1, TP.HCM', '2026-03-22', 179000.00, 30000.00, 0.00, 209000.00, 'shipped', 'cash', 'Đã giao thành công', '2026-03-22 06:54:46', '2026-03-23 07:00:00'),
(9, 'ORD009', 2, 'Nguyễn Văn A', '0912345678', 'nguyenvana@example.com', '12 Nguyễn Huệ, Quận 1, TP.HCM', '2026-03-23', 445500.00, 30000.00, 20000.00, 455500.00, 'new', 'transfer', 'Khách hàng thân thiết', '2026-03-23 08:00:00', '2026-03-23 08:00:00'),
(12, 'ORD202603265005', 8, 'khánh ly', '0123 456 789', 'ly@gmail.com', '123 đường A, phường B, quận C', '2026-03-26', 535396.11, 30000.00, 0.00, 565396.11, 'new', 'cash', '', '2026-03-26 01:01:44', '2026-03-26 01:01:44'),
(13, 'ORD202603268629', 10, 'hoang khoi', '0906985122', 'hoangkhoinpk@gmail.com', 'quận câm', '2026-03-26', 1090022.80, 30000.00, 0.00, 1120022.80, 'new', 'cash', '', '2026-03-26 01:04:30', '2026-03-26 01:04:30');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `order_details`
--

CREATE TABLE `order_details` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL CHECK (`quantity` > 0),
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `order_details`
--

INSERT INTO `order_details` (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES
(1, 1, 1, 2, 130000.00, 260000.00),
(2, 1, 4, 1, 47250.00, 47250.00),
(3, 2, 5, 3, 43200.00, 129600.00),
(4, 3, 2, 1, 117000.00, 117000.00),
(5, 3, 7, 1, 77000.00, 77000.00),
(6, 3, 9, 1, 18000.00, 18000.00),
(7, 4, 9, 1, 18000.00, 18000.00),
(8, 4, 8, 1, 84000.00, 84000.00),
(9, 4, 7, 1, 77000.00, 77000.00),
(10, 5, 9, 1, 18000.00, 18000.00),
(11, 6, 9, 1, 18000.00, 18000.00),
(12, 6, 8, 1, 84000.00, 84000.00),
(13, 7, 9, 1, 18000.00, 18000.00),
(14, 7, 6, 1, 44550.00, 44550.00),
(15, 7, 5, 1, 43200.00, 43200.00),
(16, 8, 9, 1, 18000.00, 18000.00),
(17, 8, 8, 1, 84000.00, 84000.00),
(18, 8, 7, 1, 77000.00, 77000.00),
(19, 9, 1, 2, 136500.00, 273000.00),
(20, 9, 4, 1, 47250.00, 47250.00),
(21, 9, 8, 1, 84000.00, 84000.00),
(31, 12, 8, 2, 85527.27, 171054.55),
(32, 12, 7, 1, 79153.84, 79153.84),
(33, 12, 6, 1, 45675.00, 45675.00),
(34, 12, 4, 1, 48182.86, 48182.86),
(35, 12, 5, 1, 44718.75, 44718.75),
(36, 12, 3, 1, 146611.11, 146611.11),
(37, 13, 8, 1, 85527.27, 85527.27),
(38, 13, 7, 1, 79153.84, 79153.84),
(39, 13, 6, 1, 45675.00, 45675.00),
(40, 13, 3, 6, 146611.11, 879666.68);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `pricing_log`
--

CREATE TABLE `pricing_log` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `old_cost_price` decimal(10,2) DEFAULT NULL,
  `new_cost_price` decimal(10,2) DEFAULT NULL,
  `old_selling_price` decimal(10,2) DEFAULT NULL,
  `new_selling_price` decimal(10,2) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `change_reason` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `pricing_log`
--

INSERT INTO `pricing_log` (`id`, `product_id`, `old_cost_price`, `new_cost_price`, `old_selling_price`, `new_selling_price`, `changed_by`, `change_reason`, `changed_at`) VALUES
(1, 1, NULL, 100000.00, NULL, 130000.00, 1, 'Nhập lần 1: 50 sp x 100,000 = 5,000,000 (Giá bán 30% lợi nhuận)', '2026-03-21 05:54:48'),
(2, 1, 100000.00, 101612.90, 130000.00, 132096.77, 1, 'Nhập lần 2: Tồn 50 sp, giá 100,000. Nhập thêm 20 sp x 105,000. Giá bq = (50*100k + 20*105k)/(70) = 101,612.90', '2026-03-22 07:00:00'),
(3, 2, NULL, 90000.00, NULL, 117000.00, 1, 'Nhập lần 1: 30 sp x 90,000 = 2,700,000 (Giá bán 30% lợi nhuận)', '2026-03-21 05:54:48'),
(4, 3, NULL, 110000.00, NULL, 143000.00, 1, 'Nhập lần 1: 20 sp x 110,000 = 2,200,000 (Giá bán 30% lợi nhuận)', '2026-03-21 05:54:48'),
(5, 4, NULL, 35000.00, NULL, 47250.00, 1, 'Nhập lần 1: 40 sp x 35,000 = 1,400,000 (Giá bán 35% lợi nhuận)', '2026-03-21 05:54:48'),
(6, 5, NULL, 32000.00, NULL, 43200.00, 1, 'Nhập lần 1: 30 sp x 32,000 = 960,000 (Giá bán 35% lợi nhuận)', '2026-03-21 05:54:48'),
(7, 6, NULL, 33000.00, NULL, 44550.00, 1, 'Nhập lần 1: 20 sp x 33,000 = 660,000 (Giá bán 35% lợi nhuận)', '2026-03-21 05:54:48'),
(8, 7, NULL, 55000.00, NULL, 77000.00, 1, 'Nhập lần 1: 35 sp x 55,000 = 1,925,000 (Giá bán 40% lợi nhuận)', '2026-03-21 05:54:48'),
(9, 8, NULL, 60000.00, NULL, 84000.00, 1, 'Nhập lần 1: 25 sp x 60,000 = 1,500,000 (Giá bán 40% lợi nhuận)', '2026-03-21 05:54:48'),
(10, 9, NULL, 12000.00, NULL, 18000.00, 1, 'Nhập lần 1: 100 sp x 12,000 = 1,200,000 (Giá bán 50% lợi nhuận)', '2026-03-21 05:54:48'),
(11, 2, 90000.00, 91785.71, 117000.00, 119321.43, 1, 'Nhập lần 2: Tồn 30 sp, giá 90,000. Nhập thêm 25 sp x 95,000. Giá bq = (30*90k + 25*95k)/(55) = 91,785.71', '2026-03-23 07:00:00'),
(12, 3, 110000.00, 112777.78, 143000.00, 146611.11, 1, 'Nhập lần 2: Tồn 20 sp, giá 110,000. Nhập thêm 25 sp x 115,000. Giá bq = (20*110k + 25*115k)/(45) = 112,777.78', '2026-03-24 07:00:00'),
(13, 4, 35000.00, 35428.57, 47250.00, 47828.57, 1, 'Nhập lần 2: Tồn 40 sp, giá 35,000. Nhập thêm 30 sp x 36,000. Giá bq = (40*35k + 30*36k)/(70) = 35,428.57', '2026-03-23 07:00:00'),
(14, 5, 32000.00, 32571.43, 43200.00, 43971.43, 1, 'Nhập lần 2: Tồn 30 sp, giá 32,000. Nhập thêm 15 sp x 35,000. Giá bq = (30*32k + 15*35k)/(45) = 32,571.43', '2026-03-23 07:00:00'),
(15, 5, 32571.43, 33125.00, 43971.43, 44718.75, 1, 'Nhập lần 3: Tồn 45 sp, giá 32,571.43. Nhập thêm 25 sp x 34,000. Giá bq = (45*32,571.43 + 25*34,000)/(70) = 33,125.00', '2026-03-24 07:00:00'),
(16, 6, 33000.00, 33833.33, 44550.00, 45675.00, 1, 'Nhập lần 2: Tồn 20 sp, giá 33,000. Nhập thêm 25 sp x 34,500. Giá bq = (20*33k + 25*34.5k)/(45) = 33,833.33', '2026-03-24 07:00:00'),
(17, 7, 55000.00, 56538.46, 77000.00, 79153.85, 1, 'Nhập lần 2: Tồn 35 sp, giá 55,000. Nhập thêm 30 sp x 58,000. Giá bq = (35*55k + 30*58k)/(65) = 56,538.46', '2026-03-25 07:00:00'),
(18, 8, 60000.00, 61090.91, 84000.00, 85527.27, 1, 'Nhập lần 2: Tồn 25 sp, giá 60,000. Nhập thêm 30 sp x 62,000. Giá bq = (25*60k + 30*62k)/(55) = 61,090.91', '2026-03-25 07:00:00'),
(19, 9, 12000.00, 12166.67, 18000.00, 18250.00, 1, 'Nhập lần 2: Tồn 100 sp, giá 12,000. Nhập thêm 80 sp x 12,500. Giá bq = (100*12k + 80*12.5k)/(180) = 12,166.67', '2026-03-25 07:00:00'),
(20, 4, NULL, NULL, 47828.57, 48000.00, 7, 'Cập nhật tỷ lệ lợi nhuận từ 35% lên 36%', '2026-03-25 14:14:26');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `stock_quantity` int(11) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `profit_percentage` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `products`
--

INSERT INTO `products` (`id`, `code`, `name`, `category_id`, `description`, `image`, `cost_price`, `selling_price`, `stock_quantity`, `status`, `created_at`, `updated_at`, `profit_percentage`) VALUES
(1, 'PZ001', 'Pizza hải sản', 1, 'Đế bánh giòn tan, phủ hải sản tươi ngon (tôm, mực, nghêu), phô mai béo mịn, sốt cà chua đậm đà', 'images/f1.png', 101612.90, 132096.77, 70, 'active', '2026-03-21 05:51:52', '2026-03-22 07:00:00', 30),
(2, 'PZ002', 'Pizza phô mai', 1, 'Đế giòn vàng, phô mai mozzarella béo ngậy tan chảy, sốt cà chua, hương vị thơm lừng', 'images/f3.png', 91785.71, 119321.43, 55, 'active', '2026-03-21 05:51:52', '2026-03-23 07:00:00', 30),
(3, 'PZ003', 'Pizza 3 vị', 1, 'Đế pizza giòn thơm, kết hợp ba hương vị: hải sản, phô mai, pepperoni, hấp dẫn mọi thực khách', 'images/f6.png', 112777.78, 146611.11, 45, 'active', '2026-03-21 05:51:52', '2026-03-24 07:00:00', 30),
(4, 'BG001', 'Burger bò', 2, 'Thịt bò Úc nướng than hoa, rau tươi, sốt đặc trưng, phô mai cheddar tan chảy', 'images/f2.png', 35428.57, 48000.00, 70, 'active', '2026-03-21 05:51:52', '2026-03-25 14:14:26', 36),
(5, 'BG002', 'Burger gà chiên', 2, 'Ức gà chiên giòn, sốt bơ tỏi, rau tươi, phô mai, bánh mì mềm', 'images/f7.png', 33125.00, 44718.75, 70, 'active', '2026-03-21 05:51:52', '2026-03-24 07:00:00', 35),
(6, 'BG003', 'Burger ức gà', 2, 'Burger ức gà mềm, sốt mật ong, rau tươi, phô mai, bánh mì đen', 'images/f8.png', 33833.33, 45675.00, 45, 'active', '2026-03-21 05:51:52', '2026-03-24 07:00:00', 35),
(7, 'PA001', 'Pasta rau củ', 3, 'Sợi mì spaghetti mềm mịn hòa quyện sốt kem béo ngậy, rau củ tươi (bông cải, cà rốt, nấm)', 'images/f4.png', 56538.46, 79153.85, 65, 'active', '2026-03-21 05:51:52', '2026-03-25 07:00:00', 40),
(8, 'PA002', 'Pasta phô mai', 3, 'Sợi mì fettuccine hòa quyện sốt kem phô mai Parmesan, thịt xông khói, hương vị Ý đậm đà', 'images/f9.png', 61090.91, 85527.27, 55, 'active', '2026-03-21 05:51:52', '2026-03-25 07:00:00', 40),
(9, 'FR001', 'Khoai tây chiên', 4, 'Khoai tây chiên vàng giòn, muối vừa, thơm ngon, chấm sốt cà chua hoặc sốt mayonnaise', 'images/f5.png', 12166.67, 18000.00, 180, 'inactive', '2026-03-21 05:51:52', '2026-03-25 14:17:34', 50);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `register_date` date NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `status` enum('active','locked','inactive') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `email`, `phone`, `password`, `address`, `birthday`, `register_date`, `role`, `status`, `notes`, `avatar`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'Admin Feane', 'admin', 'admin@feane.com', '0901234567', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '123 Đường Admin, Quận 1, TP.HCM', NULL, '2026-03-21', 'admin', 'active', 'Quản trị viên hệ thống', NULL, '2026-03-25 09:30:00', '2026-03-21 05:52:21', '2026-03-25 02:30:00'),
(2, 'Nguyễn Văn A', 'nguyenvana', 'nguyenvana@example.com', '0912345678', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '12 Nguyễn Huệ, Quận 1, TP.HCM', '1990-05-15', '2026-03-21', 'user', 'active', 'Khách hàng thân thiết', NULL, '2026-03-23 10:00:00', '2026-03-21 05:52:21', '2026-03-23 07:30:00'),
(3, 'Trần Thị B', 'tranthib', 'tranthib@example.com', '0987654321', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '45 Lê Lợi, Quận 3, TP.HCM', '1988-08-22', '2026-03-21', 'user', 'active', 'Khách hàng VIP', NULL, '2026-03-22 15:30:00', '2026-03-21 05:52:21', '2026-03-23 07:30:00'),
(4, 'Lê Văn C', 'levanc', 'levanc@example.com', '0123456789', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '78 Pasteur, Quận 1, TP.HCM', '1995-12-10', '2026-03-21', 'user', 'locked', 'Tài khoản bị khóa do vi phạm', NULL, '2026-03-20 08:00:00', '2026-03-21 05:52:21', '2026-03-23 07:30:00'),
(5, 'Trần Minh Triết', 'triet', 'triet@gmail.com', '0339111480', '$2y$10$eN/XTYaJJ.T8OcnygzYAmu/vVRn2pMdzUlV3T6MJWIvny39r5/B0y', '123 Đường ABC, Phường XYZ, Quận 1, TP.HCM', '2006-07-13', '2026-03-22', 'user', 'active', 'Khách hàng mới', '../images/about-img.png', '2026-03-23 12:00:00', '2026-03-22 02:27:33', '2026-03-23 07:30:00'),
(6, 'Phạm Thị D', 'phamthid', 'phamthid@gmail.com', '0909888777', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '222 Lý Tự Trọng, Quận 1, TP.HCM', '1992-03-18', '2026-03-23', 'user', 'locked', 'Khách hàng mới', NULL, NULL, '2026-03-23 07:30:00', '2026-03-24 00:07:07'),
(7, 'Lê Đỗ Hoà Thương', 'thuong', 'thuong@gmail.com', '0909876543', 'thuong123', '456 Đường X, Phường Y, Quận Z, TP.HCM', '1996-03-02', '2026-03-12', 'admin', 'active', 'Admin phụ trách marketing', NULL, '2026-03-26 08:04:54', '2026-03-23 01:22:11', '2026-03-26 01:04:54'),
(8, 'khánh ly', 'ly', 'ly@gmail.com', '0123 456 789', '$2y$10$oRy2FK/vJPbz.tFQHP8D2O7LOqy9tJWPrm2vzA.PKr5KpW88quhOe', '123 đường A, phường B, quận C', '2012-02-22', '2026-03-24', 'user', 'active', NULL, NULL, NULL, '2026-03-24 00:06:10', '2026-03-24 00:07:00'),
(9, 'nguyen thi t', 'nguyen@gmail.com', 'nguyen@gmail.com', '0395489754', '$2y$10$llY0kUa4v7CpQxmv5Jq93.BOlgZKG4a11KLJWYYCsoFEDT.7KarYm', '789 Đường DEF, Quận 3, TP.HCM', '2009-07-09', '2026-03-25', 'user', 'active', NULL, NULL, NULL, '2026-03-25 14:25:29', '2026-03-25 14:25:29'),
(10, 'hoang khoi', 'hoangkhoinpk@gmail.com', 'hoangkhoinpk@gmail.com', '0906985122', '$2y$10$bbqRO3dMeYg17Vj/l.T1pOh8JjOD./r/Zb6qpM30hRwhWr.1v16aa', 'quận câm', '2006-06-12', '2026-03-26', 'user', 'active', NULL, NULL, NULL, '2026-03-26 01:04:13', '2026-03-26 01:04:13');

--
-- Chỉ mục cho các bảng đã đổ
--

--
-- Chỉ mục cho bảng `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cart_item` (`user_id`,`session_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Chỉ mục cho bảng `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Chỉ mục cho bảng `imports`
--
ALTER TABLE `imports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `import_code` (`import_code`),
  ADD KEY `created_by` (`created_by`);

--
-- Chỉ mục cho bảng `import_details`
--
ALTER TABLE `import_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `import_id` (`import_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Chỉ mục cho bảng `loyalty_points`
--
ALTER TABLE `loyalty_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Chỉ mục cho bảng `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_code` (`order_code`),
  ADD KEY `user_id` (`user_id`);

--
-- Chỉ mục cho bảng `order_details`
--
ALTER TABLE `order_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Chỉ mục cho bảng `pricing_log`
--
ALTER TABLE `pricing_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Chỉ mục cho bảng `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `category_id` (`category_id`);

--
-- Chỉ mục cho bảng `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--

--
-- AUTO_INCREMENT cho bảng `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT cho bảng `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT cho bảng `imports`
--
ALTER TABLE `imports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT cho bảng `import_details`
--
ALTER TABLE `import_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT cho bảng `loyalty_points`
--
ALTER TABLE `loyalty_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT cho bảng `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT cho bảng `order_details`
--
ALTER TABLE `order_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT cho bảng `pricing_log`
--
ALTER TABLE `pricing_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT cho bảng `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT cho bảng `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Các ràng buộc cho các bảng đã đổ
--

--
-- Các ràng buộc cho bảng `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Các ràng buộc cho bảng `imports`
--
ALTER TABLE `imports`
  ADD CONSTRAINT `imports_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `import_details`
--
ALTER TABLE `import_details`
  ADD CONSTRAINT `import_details_ibfk_1` FOREIGN KEY (`import_id`) REFERENCES `imports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `import_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Các ràng buộc cho bảng `loyalty_points`
--
ALTER TABLE `loyalty_points`
  ADD CONSTRAINT `loyalty_points_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `order_details`
--
ALTER TABLE `order_details`
  ADD CONSTRAINT `order_details_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Các ràng buộc cho bảng `pricing_log`
--
ALTER TABLE `pricing_log`
  ADD CONSTRAINT `pricing_log_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pricing_log_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
