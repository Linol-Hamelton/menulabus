-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Фев 08 2026 г., 01:22
-- Версия сервера: 8.0.45-0ubuntu0.22.04.1
-- Версия PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `menu_labus`
--

-- --------------------------------------------------------

--
-- Структура таблицы `auth_tokens`
--

CREATE TABLE `auth_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `selector` varchar(24) NOT NULL,
  `hashed_validator` varchar(255) NOT NULL,
  `expires_at` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `auth_tokens`
--

INSERT INTO `auth_tokens` (`id`, `user_id`, `selector`, `hashed_validator`, `expires_at`, `created_at`) VALUES
(1, 6, 'ed86a40c9646df6dd2ceabd7', '$2y$10$.Tb7n9HAgQN68aOf4SG7o.s/vKQlNdAxCNJzZ/ZLbgju4q7uYpFR2', 1786865961, '2025-08-16 07:39:21'),
(2, 13, 'fc5a003a2c94537f44e8bb41', '$2y$10$vQH87N207be1I1YUl4w8zuvjJBiGUVGXStfLVzBl3Dt2e4A.CLhC.', 1786867986, '2025-08-16 08:13:06'),
(3, 6, 'aae96d7734d78a421eae6d09', '$2y$10$/f14IGpBn1kFWNSruDmWRuZUNCj3PTMf/JnDZnMln2eHvxYx4gBKy', 1786974318, '2025-08-17 13:45:18'),
(6, 6, '91a523fde52666b71d4a4513', '$2y$10$cGJLRZjv7ZqWEnKzZr78nOYnBUu3NDR3oAjKXsXL0Fn8p2qaFL016', 1787130141, '2025-08-19 09:02:21'),
(7, 6, '757413017912522df970b520', '$2y$10$FdC/XKmpF0GEVrE7.tLqLevW4hERnvlWpFmulvkkNOYM5NZYWxmOW', 1787185803, '2025-08-20 00:30:03'),
(8, 6, 'e6b47797fcd2af2bfc956348', '$2y$10$qe35PJS53VYZ7DMLpml/ZeL9s47eWMEgrnbcyJ/qqueRP/3WaZNY2', 1787185915, '2025-08-20 00:31:55'),
(9, 6, '213412900eab0fa4b19a4499', '$2y$10$TGfIlrhyeqIxCgC92rpdPuaEAN.I7OowsIWeoI9be6WeTmStGwUP.', 1787242962, '2025-08-20 16:22:42'),
(10, 13, 'd6a9f6e927bf5793a0beab6a', '$2y$10$gLH/tbiuLOMa6/IzM7XMN.UQ12KR4p6RKpeUZLI8wpw6dgkk7y7WG', 1787250389, '2025-08-20 18:26:29'),
(12, 6, '06cf4f917802f88f681f72c3', '$2y$10$DToNRTIIkmO6naGra9BR6earMy6vofYbhRKcScYxo.BrheosNuqIa', 1787294902, '2025-08-21 06:48:22'),
(13, 14, '2b7dce155342d4707218f510', '$2y$10$OK5iY..h8EdA1E21XILO6ObYlUeQc3PpX.pYC8E9bEuMWLFezCsIK', 1787402216, '2025-08-22 12:36:56'),
(14, 6, '346252e59f92f799d68192a4', '$2y$10$bu4A.pwxJz0O88mtZ281yerE5q21/WeUgh7mKdsI5CtiUaJpxqncq', 1787405184, '2025-08-22 13:26:24'),
(15, 6, '2c048c6533fdc2e84e62906a', '$2y$10$FPB52dNuRutZWOE3JotVNuY0LN8Ij31/uTZ91ZvnT74QRAWv/UQ.G', 1787405244, '2025-08-22 13:27:24'),
(18, 6, '33ed6a19d6552b925bcd1883', '$2y$10$VyhJvGnZU3Og.aq2ruOxXe/YY7GM/FVFGnxcrr6SqwtScH7.AXMzC', 1787431747, '2025-08-22 20:49:07'),
(20, 6, '03a37fe0f7dd061d7205016a', '$2y$10$acVfVYvdRgIQEmiyK6U7e.Ng4LIsNFliwxWTfBa.mUfnrouz.IJGG', 1787436729, '2025-08-22 22:12:09'),
(21, 6, '15875750d27caf42eb763523', '$2y$10$q.Nis3tfBb82Pk1TIllG5uUohc3XT8.d80pxkH5.FAJEgUz.Ou8mS', 1787436792, '2025-08-22 22:13:12'),
(22, 6, 'e22a014a47884a3393d44916', '$2y$10$cNKhwyWRApSTGrIFtHE8OO9jfNa0YVfYZF7QZIpqcNze5yDOCUb0m', 1787436814, '2025-08-22 22:13:34'),
(25, 6, '827bf67441614f205940726b', '$2y$10$9iJcOvwOZjoIl3thOwwj9OsPliAgbxr32e6/3O1CegQMjYQYCTD3S', 1787459429, '2025-08-23 04:30:29'),
(26, 6, '566e664a976a2ec29f8dd71b', '$2y$10$yYrriJBBPtMPfmK9.6T.OOrTrA/APajQ0YaweegU1FswTr/au8dbm', 1787460894, '2025-08-23 04:54:54'),
(27, 6, '2b2b2720ad22f38c8af38330', '$2y$10$hILzaEO29wmLmmXCJ4nm/OrabZUTx14fKqyiiurVUjYTEV0Yrt5gC', 1787461031, '2025-08-23 04:57:11'),
(28, 6, 'b59844f28053ad96aeefc67b', '$2y$10$v.t36nCVzWT9Px.vlAEQ1eOJjDONDG4PlVm9t4QnW9EcBfXUQHGzu', 1787461943, '2025-08-23 05:12:23'),
(29, 6, '66ce452e8281db4a82b4f111', '$2y$10$6H3Cr/cDxiEJN91M0ieJ3u.MlgrP97a4MwCc87vlqfRUfRkbjyqF6', 1787471695, '2025-08-23 07:54:55'),
(30, 6, '2c598c6e31406309ec37a96a', '$2y$10$RYYdBFZ6UtRUdPZtFCQSEeTYne4t4AGkSp3Z7m7vk0Zyu60hHWQWG', 1787624176, '2025-08-25 02:16:16'),
(31, 6, 'a26fe66118210d6966ca1a80', '$2y$10$HyXsSFWj4MWYL8ZEwwHbQOzEkCH7IeAh3.YWliZEsNBx9ICOZo0qa', 1787624205, '2025-08-25 02:16:45'),
(32, 6, 'ee705b89bf5675972994e19e', '$2y$10$ITqxAVAQZrdk3AuqEKXFHOJ6ALefTZmajzkw38Jd6eUqp.8BPAc6W', 1787646389, '2025-08-25 08:26:29'),
(33, 6, '4e2c4752173a71361c092093', '$2y$10$zemjo6z6g7v5J6U8r0Evn.e1mJAh.XKS5llfnJ2twVWoEfIDqdvJi', 1787646554, '2025-08-25 08:29:14'),
(34, 6, '709fd93d65a215b85529fc14', '$2y$10$Fe2Go/nx3saoCr6Q/dYgEuB2DgaOpjc/3NLz0w.FMxVv7l91gWP6C', 1787646816, '2025-08-25 08:33:36'),
(37, 6, '5bd0f3947ef0574d6497743c', '$2y$10$tOCSua2mJdnhjUvpnlaEduVPLFvfQpH90ht2rgs1pREku7hTTaaXy', 1787649983, '2025-08-25 09:26:23'),
(38, 6, '2a4e4a946243ef86a10ca28e', '$2y$10$3r2wNCNpyDLMd2kPVKqAxOmr5acO5ItZ26HktZcX6YGhjxanlWDc2', 1787690600, '2025-08-25 20:43:20'),
(39, 6, '586ff30c70378ba5e286bf55', '$2y$10$hn0KxCtEZt1flP86eMZJ.OO8oU8cELEfBpwrHb9kgszUsvgkE.ohe', 1787690764, '2025-08-25 20:46:04'),
(40, 6, '2803354d1d581cf93b08dbad', '$2y$10$AltTild5ohXyFy0V4r.H.eqOKR/Ra7XUF.sPKuFpkflxEx8ba8gLW', 1787752497, '2025-08-26 13:54:57'),
(41, 14, 'c3e2bffe9b6e87051f22907a', '$2y$10$s.FGbybTnnsP2A.ACZ9tEOD3835F/JN0VV9fbKohe7kMELHg1km8u', 1787754562, '2025-08-26 14:29:22'),
(42, 6, '6a45ad4e32369da20322d2ac', '$2y$10$xZATthXhJlxv8vRdUlYnnuobCkK2UyGFzi9t/tw4uhVB.xK4Khb/K', 1787769241, '2025-08-26 18:34:01'),
(43, 6, '9e6b554c2e37ab11f6023229', '$2y$10$z6abWF2YwcAW6B/cpn6SNOWApM5k5Ij5NsDO67aFWKSatNNzGRdhS', 1787789610, '2025-08-27 00:13:30'),
(45, 6, '035c6182e9cf0e783078d4cd', '$2y$10$oj7UChdV4IdolhL0XatZv.5c7gylCaM8AQzsHOILpa3avjVzDT7Ma', 1787887457, '2025-08-28 03:24:17'),
(46, 6, '62141ea71fed9afb87fe0938', '$2y$10$.pfhqxAwxpsCnKXkp/HoW.6bv2dU.OokVx5HjtHnbl/IZFgAQtkiC', 1787887677, '2025-08-28 03:27:57'),
(47, 6, 'c3e3f998aa38771877246070', '$2y$10$CninHU.YINZRUaCNJI6.NeuThGRKJDrqLtbY7XMvLkkTyzmabxIca', 1787887912, '2025-08-28 03:31:52'),
(48, 6, '14d6a04dbed3e59b90edf6b4', '$2y$10$23MjsW6mdMNJ.6UasUkxXOaEIjXE.q2zqEbokxHqQIGxxDhL0ZZ1G', 1787888122, '2025-08-28 03:35:22'),
(49, 13, '0e42e30908f354754d96e415', '$2y$10$DUqlPObYUisoM27RmfawXefLS8vtk1/bHlL8xl0O9vikX8Un3ZkTq', 1787938496, '2025-08-28 17:34:56'),
(50, 6, 'e79101a3e0896dec8147d91c', '$2y$10$ZdKUzj.rkUc/jQLdC0nRg.AuJOXUeZlvvWMyqMjOngbEL3Ce5ltVO', 1788011321, '2025-08-29 13:48:41'),
(51, 14, 'f326c74b35e0ba1c1c7ebe96', '$2y$10$pUs.hMDDdbjLMe0hzYH61uDXxS4UxAy8b3C2YKTJ9Ym9b/4u9arI.', 1788012482, '2025-08-29 14:08:02'),
(53, 6, '483f2d28002f8476767b79d3', '$2y$10$mZm/JfUHzBeWfT8799RNn.92bR5NpkMZtkZRBmWlRcbb6/clQ5XaS', 1788194621, '2025-08-31 16:43:41'),
(57, 6, 'db7ce3975b044a368954a8ac', '$2y$10$Jcq4k/oMcFQ.M1yqXB8cNetSrI6tDM5gQ9nfxSAuCohwqrOUFmvBW', 1788216547, '2025-08-31 22:49:07'),
(58, 14, 'be35866e3cbc65e1f31ead0a', '$2y$10$e3wadAyJ6RRiqDGrQsx9z.VXDUIx8Y0/0xS4S7esn1/2VAhhIEaGW', 1788249225, '2025-09-01 07:53:45'),
(59, 6, '7a766e2b9af4aa60aa8a40f2', '$2y$10$.r8722MkAIVWqC9zRy4QL.oGmtaiodwOM5sfn8eNu2W0XvvpnH2Y2', 1788268126, '2025-09-01 13:08:46'),
(60, 6, '418a1dad91ab1d54a4cf2e6c', '$2y$10$zP.0p8eUB/XNvVhZOfxysOb9KtLGaaHrjZJwcRNnwvi8Z4FuNAWWG', 1788284811, '2025-09-01 17:46:51'),
(61, 6, 'fd795d320677c64f40664791', '$2y$10$kgkNoS9xEpY7eVgUS6XS8OBImh0DYaDMEmtejj9qMtMGYTPSkXohS', 1788479021, '2025-09-03 23:43:41'),
(62, 14, '17f505a519dfb54cfe4d6ba4', '$2y$10$anyefq0uKY8eomRUPO5Ppez.xWcM3jOpUuXXMIq36F6MvA7UjxU7i', 1788511053, '2025-09-04 08:37:33'),
(63, 6, 'bee74fd13fa685826a163f36', '$2y$10$ljz5f4SWJ1Yzy7RnSInVUOAzd7LDOQWLi1E0llqF0fJu0.L3Z9SL6', 1788606604, '2025-09-05 11:10:04'),
(64, 6, '0f4185974723bb79b0d901a7', '$2y$10$Vf/Mm838MOVEhbVZYRxB5.ZQmNBE2bpgKMVDvXHQBy1wRRoRKhG9O', 1788614614, '2025-09-05 13:23:34'),
(65, 6, '43738152bccb52b99e8829a9', '$2y$10$1QdaMYa9P3rWPAeonxIJfu31wPBR1s1C2zCIHnQ2Vy8eojUVmxiR2', 1790032999, '2025-09-21 23:23:20'),
(66, 6, '8ea7b07a97182dab8c5c863a', '$2y$10$d1ouG.eaSUqS4etUx/uVBOet2vq95WU5su6kbsKxmGAnTQqM2R4JO', 1772787833, '2026-02-04 09:03:53');

-- --------------------------------------------------------

--
-- Структура таблицы `menu_items`
--

CREATE TABLE `menu_items` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `composition` text,
  `price` decimal(10,2) NOT NULL,
  `cost` decimal(10,2) DEFAULT '0.00',
  `image` varchar(255) DEFAULT NULL,
  `calories` int DEFAULT NULL,
  `protein` int DEFAULT NULL,
  `fat` int DEFAULT NULL,
  `carbs` int DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `available` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `menu_items`
--

INSERT INTO `menu_items` (`id`, `name`, `description`, `composition`, `price`, `cost`, `image`, `calories`, `protein`, `fat`, `carbs`, `category`, `created_at`, `available`) VALUES
(1, 'Куринная с грибами', '1000 гр', 'Тесто, начинка, и, т.д.', 1110.00, 600.00, './images/Pizza/kurinaya.jpg', 365, 20, 80, 120, 'Пицца', '2025-08-07 06:07:30', 1),
(2, 'Пицца Маргарита', '100 гр', '', 3890.00, 300.00, './images/Pizza/margarita.jpg', 0, 0, 0, 0, 'Пицца', '2025-08-07 06:07:30', 1),
(3, 'Мясная пицца', '100 гр', '', 290.00, 100.00, './images/Pizza/misnaya.jpg', 0, 0, 0, 0, 'Пицца', '2025-08-07 06:07:30', 1),
(4, 'Пицца Пипперони', '100 гр', '', 890.00, 0.00, './images/Pizza/piperoni.jpg', 0, 0, 0, 0, 'Пицца', '2025-08-07 06:07:30', 1),
(5, 'Кровавая Мери', '100 гр', '', 890.00, 0.00, './images/Coctal/blodymary.jpg', 0, 0, 0, 0, 'Коктели', '2025-08-07 06:07:30', 1),
(6, 'Насьональ', '100 гр', '', 890.00, 0.00, './images/Coctal/national.jpg', 0, 0, 0, 0, 'Коктели', '2025-08-07 06:07:30', 1),
(7, 'Пламя2', '100 гр', '', 2890.00, 0.00, './images/Coctal/plamia.jpg', 0, 0, 0, 0, 'Коктели', '2025-08-07 06:07:30', 1),
(8, 'Дайкири', '100 гр', '', 890.00, 0.00, './images/Coctal/daikiri.jpg', 0, 0, 0, 0, 'Коктели', '2025-08-07 06:07:30', 1),
(9, 'Мохито', '100 гр', '', 890.00, 0.00, './images/Coctal/mohito.jpg', 0, 0, 0, 0, 'Коктели', '2025-08-07 06:07:30', 1),
(10, 'Блэк Джэк', '1000 гр', 'Все, подряд', 190.00, 110.00, './images/Coctal/blackjack.jpg', 120, 12, 1, 100, 'Коктели', '2025-08-07 06:07:30', 1),
(11, 'Баварский сет', '100 гр', '', 890.00, 0.00, './images/Sets/bavarskiy.jpg', 0, 0, 0, 0, 'Сеты', '2025-08-07 06:07:30', 1),
(12, 'Сет мангал', '100 гр', '', 322.00, 0.00, './images/Sets/mangal.jpg', 0, 0, 0, 0, 'Сеты', '2025-08-07 06:07:30', 1),
(13, 'Пивной сет', '100 гр', '', 890.00, 0.00, './images/Sets/pivnoy.jpg', 0, 0, 0, 0, 'Сеты', '2025-08-07 06:07:30', 1),
(14, 'Нут с мясом', '100 гр', '', 890.00, 0.00, './images/Snaks/meatnut.jpg', 0, 0, 0, 0, 'Снеки', '2025-08-07 06:07:30', 1),
(15, 'Тушенное мясо', '100 гр', '', 890.00, 0.00, './images/Snaks/meat.jpg', 0, 0, 0, 0, 'Снеки', '2025-08-07 06:07:30', 1),
(16, 'Чипсы пипперони', '100 гр', '', 890.00, 0.00, './images/Snaks/piperonichips.jpg', 0, 0, 0, 0, 'Снеки', '2025-08-07 06:07:30', 1),
(62, 'Тест', '200 гр', 'Хлеб, масло', 100.00, 0.00, './images/Pizza/kurinaya.jpg', 300, 30, 30, 30, 'Тест', '2025-08-13 16:19:33', 1),
(63, 'Бутерброд с семгой', '223', 'салат, курица, сухарики, соус, цезарь', 320.00, 0.00, './images/Pizza/kurinaya.jpg', 350, 25, 15, 20, 'Тест', '2025-08-13 16:56:38', 1),
(64, 'бутерброд с курицей', '333', 'паста, бекон, сливки, яйца, пармезан', 380.00, 0.00, './images/Pizza/kurinaya.jpg', 650, 30, 45, 60, 'Тест', '2025-08-13 16:56:38', 1),
(65, 'Бутерброд2', '223', 'салат, курица, сухарики, соус, цезарь', 2320.00, 0.00, './images/Snaks/meat.jpg', 350, 25, 15, 20, 'Тест', '2025-08-13 17:02:06', 1),
(66, 'Бутерброд3', '333', 'паста, бекон, сливки, яйца, пармезан', 1380.00, 0.00, './images/Snaks/meat.jpg', 650, 30, 45, 60, 'Тест', '2025-08-13 17:02:06', 1),
(77, 'Бутерброд1', '123', 'тесто, томаты, сыр, моцарелла', 3450.00, 0.00, './images/Snaks/meat.jpg', 800, 35, 40, 90, 'Тест', '2025-08-13 17:25:05', 1),
(80, 'Блэк Джэк2', 'Сноссит', 'Химия', 1111.00, 0.00, './images/Coctal/daikiri.jpg', 9991, 22, 2, 2, 'Коктели', '2025-08-19 07:09:06', 1),
(81, 'Блэк Джэк3', '300 гр', 'Все, подряд, и, еще, немного', 1890.00, 0.00, './images/Coctal/blackjack.jpg', 120, 12, 1, 100, 'Коктели', '2025-08-19 07:10:53', 1),
(103, 'Тест-мест', '2000 гр', 'Хлеб, масло', 100.00, 0.00, './images/Pizza/kurinaya.jpg', 300, 30, 30, 30, 'Тест', '2025-08-19 07:10:53', 1),
(154, 'Арбузы', '100', 'Семечки', 100.00, 0.00, './images/Pizza/kurinaya.jpg', 1, 1, 11, 1, 'Арбузы', '2025-08-25 15:04:10', 0);

-- --------------------------------------------------------

--
-- Структура таблицы `orders`
--

CREATE TABLE `orders` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `items` json NOT NULL COMMENT 'Массив товаров в формате JSON',
  `total` decimal(10,2) NOT NULL,
  `status` enum('Приём','готовим','доставляем','завершён','отказ') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_type` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'bar',
  `delivery_details` varchar(255) DEFAULT '',
  `last_updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `items_count` int GENERATED ALWAYS AS (json_length(`items`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Дамп данных таблицы `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `items`, `total`, `status`, `delivery_type`, `delivery_details`, `last_updated_by`, `created_at`, `updated_at`) VALUES
(1, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./Pizza/margarita.jpg\", \"price\": \"3890.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 6180.00, 'завершён', 'bar', '', 6, '2025-08-09 13:27:25', '2025-08-31 13:57:47'),
(2, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./Pizza/margarita.jpg\", \"price\": \"3890.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 2}]', 7070.00, 'завершён', 'bar', '', 6, '2025-08-09 13:27:41', '2025-08-31 13:57:51'),
(4, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 3}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 4}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 8960.00, 'завершён', 'bar', '', 6, '2025-08-10 03:31:51', '2025-09-03 14:09:34'),
(5, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 3}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 4}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 8960.00, 'завершён', 'bar', '', 6, '2025-08-10 04:22:41', '2025-09-03 13:33:25'),
(6, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./Coctal/national.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"7\", \"name\": \"Пламя\", \"image\": \"./Coctal/plamia.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"11\", \"name\": \"Баварский сет\", \"image\": \"./Sets/bavarskiy.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"13\", \"name\": \"Пивной сет\", \"image\": \"./Sets/pivnoy.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"15\", \"name\": \"Тушенное мясо\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"16\", \"name\": \"Чипсы пипперони\", \"image\": \"./Snaks/piperonichips.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 7120.00, 'завершён', 'bar', '', 6, '2025-08-10 04:23:18', '2025-08-16 15:16:50'),
(7, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'bar', '', 6, '2025-08-10 16:53:34', '2025-08-16 06:59:33'),
(8, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'bar', '', 6, '2025-08-10 16:54:01', '2025-09-03 14:09:57'),
(9, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'bar', '', 6, '2025-08-10 17:21:25', '2025-08-14 16:02:38'),
(10, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'bar', '', 6, '2025-08-10 17:21:34', '2025-08-16 15:16:44'),
(11, 6, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'bar', '', 6, '2025-08-10 17:21:40', '2025-08-16 15:16:33'),
(12, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 1780.00, 'завершён', 'bar', '', 6, '2025-08-20 21:25:03', '2025-08-31 14:11:49'),
(13, 6, '[{\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./Coctal/national.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"7\", \"name\": \"Пламя\", \"image\": \"./Coctal/plamia.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"9\", \"name\": \"Мохито\", \"image\": \"./Coctal/mohito.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 2670.00, 'завершён', 'bar', '', 6, '2025-08-10 21:26:15', '2025-08-16 15:02:56'),
(14, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 5}, {\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": 890, \"quantity\": 2}, {\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./Coctal/national.jpg\", \"price\": 890, \"quantity\": 4}, {\"id\": \"7\", \"name\": \"Пламя\", \"image\": \"./Coctal/plamia.jpg\", \"price\": 890, \"quantity\": 2}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": 890, \"quantity\": 1}]', 13560.00, 'завершён', 'bar', '', 6, '2025-08-11 10:30:14', '2025-09-03 13:21:07'),
(15, 13, '[{\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"13\", \"name\": \"Пивной сет\", \"image\": \"./Sets/pivnoy.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"16\", \"name\": \"Чипсы пипперони\", \"image\": \"./Snaks/piperonichips.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 2960.00, 'завершён', 'bar', '', 6, '2025-08-15 11:41:12', '2025-08-31 14:11:30'),
(16, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 2}]', 1780.00, 'завершён', 'bar', '', 6, '2025-08-03 15:05:07', '2025-08-31 14:11:19'),
(17, 17, '[{\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./Pizza/margarita.jpg\", \"price\": \"3890.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"4\", \"name\": \"Пицца Пипперони\", \"image\": \"./Pizza/piperoni.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"9\", \"name\": \"Мохито\", \"image\": \"./Coctal/mohito.jpg\", \"price\": \"890.00\", \"quantity\": 3}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 10410.00, 'завершён', 'bar', '', 6, '2025-08-11 15:47:57', '2025-09-03 13:19:36'),
(18, 18, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./Pizza/margarita.jpg\", \"price\": \"3890.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"4\", \"name\": \"Пицца Пипперони\", \"image\": \"./Pizza/piperoni.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./Coctal/national.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"7\", \"name\": \"Пламя\", \"image\": \"./Coctal/plamia.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"9\", \"name\": \"Мохито\", \"image\": \"./Coctal/mohito.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"11\", \"name\": \"Баварский сет\", \"image\": \"./Sets/bavarskiy.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"12\", \"name\": \"Сет мангал\", \"image\": \"./Sets/mangal.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"13\", \"name\": \"Пивной сет\", \"image\": \"./Sets/pivnoy.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"14\", \"name\": \"Нут с мясом\", \"image\": \"./Snaks/meatnut.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"15\", \"name\": \"Тушенное мясо\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"16\", \"name\": \"Чипсы пипперони\", \"image\": \"./Snaks/piperonichips.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 16860.00, 'завершён', 'bar', '', 6, '2025-08-11 15:59:43', '2025-09-03 14:13:27'),
(19, 19, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'bar', '', 13, '2025-08-11 17:24:16', '2025-08-14 16:02:38'),
(20, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"9\", \"name\": \"Мохито\", \"image\": \"./Coctal/mohito.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 4450.00, 'завершён', 'bar', '', 6, '2025-08-11 17:47:31', '2025-08-14 16:02:38'),
(21, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 2670.00, 'завершён', 'bar', '', 13, '2025-08-11 17:47:58', '2025-08-14 16:02:38'),
(22, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 2670.00, 'завершён', 'bar', '', 6, '2025-08-11 17:49:37', '2025-09-03 14:11:15'),
(23, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'table', '', 6, '2025-08-11 17:53:51', '2025-09-03 13:29:59'),
(24, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 4}]', 3560.00, 'отказ', 'delivery', '', 6, '2025-08-11 21:27:21', '2025-08-16 15:19:02'),
(25, 13, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 2}]', 2670.00, 'завершён', 'delivery', '', 6, '2025-08-11 21:46:21', '2025-09-03 12:56:33'),
(26, 13, '[{\"id\": \"14\", \"name\": \"Нут с мясом\", \"image\": \"./Snaks/meatnut.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'отказ', 'delivery', '', 6, '2025-08-11 21:47:32', '2025-08-14 15:36:47'),
(27, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"7\", \"name\": \"Пламя\", \"image\": \"./Coctal/plamia.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 3560.00, 'отказ', 'delivery', '', 6, '2025-08-12 00:15:02', '2025-08-14 15:36:21'),
(28, 6, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 2}]', 1780.00, 'завершён', 'bar', '', 6, '2025-08-12 02:48:46', '2025-09-03 12:55:32'),
(29, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'завершён', 'bar', '', 6, '2025-08-12 04:04:48', '2025-09-03 12:55:22'),
(31, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./Coctal/blodymary.jpg\", \"price\": \"890.00\", \"quantity\": 7}, {\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./Coctal/national.jpg\", \"price\": \"890.00\", \"quantity\": 3}, {\"id\": \"7\", \"name\": \"Пламя\", \"image\": \"./Coctal/plamia.jpg\", \"price\": \"890.00\", \"quantity\": 2}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 6}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 18690.00, 'завершён', 'bar', '', 6, '2025-08-13 07:13:08', '2025-09-03 13:28:41'),
(32, 6, '[{\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./Coctal/national.jpg\", \"price\": \"890.00\", \"quantity\": 2}]', 1780.00, 'отказ', 'bar', '', 6, '2025-08-13 11:44:28', '2025-08-17 08:46:47'),
(33, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 2}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 2}]', 4960.00, 'завершён', 'bar', '', 6, '2025-08-13 15:17:59', '2025-08-31 13:57:53'),
(34, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"11\", \"name\": \"Баварский сет\", \"image\": \"./Sets/bavarskiy.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"12\", \"name\": \"Сет мангал\", \"image\": \"./Sets/mangal.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"13\", \"name\": \"Пивной сет\", \"image\": \"./Sets/pivnoy.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 5850.00, 'завершён', 'bar', '', 6, '2025-08-13 20:12:43', '2025-08-19 23:42:56'),
(35, 6, '[{\"id\": \"77\", \"name\": \"Бутерброд1\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"3450.00\", \"quantity\": 1}]', 3450.00, 'завершён', 'bar', '', 6, '2025-08-13 20:47:42', '2025-08-14 16:02:38'),
(36, 6, '[{\"id\": \"77\", \"name\": \"Бутерброд1\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"3450.00\", \"quantity\": 1}]', 3450.00, 'завершён', 'bar', '', 6, '2025-08-25 21:00:41', '2025-09-03 00:27:58'),
(37, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}, {\"id\": \"77\", \"name\": \"Бутерброд1\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"3450.00\", \"quantity\": 1}]', 3770.00, 'завершён', 'bar', '', 6, '2025-08-24 21:01:36', '2025-08-31 13:50:25'),
(38, 6, '[{\"id\": \"62\", \"name\": \"Тест\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"100.00\", \"quantity\": 2}, {\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 1}]', 580.00, 'завершён', 'bar', '', 6, '2025-08-13 21:05:06', '2025-08-31 13:57:55'),
(39, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 2}, {\"id\": \"11\", \"name\": \"Баварский сет\", \"image\": \"./Sets/bavarskiy.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"12\", \"name\": \"Сет мангал\", \"image\": \"./Sets/mangal.jpg\", \"price\": \"890.00\", \"quantity\": 1}, {\"id\": \"13\", \"name\": \"Пивной сет\", \"image\": \"./Sets/pivnoy.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 7630.00, 'завершён', 'delivery', '', 6, '2025-08-13 21:08:40', '2025-08-31 13:57:56'),
(40, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}]', 320.00, 'завершён', 'delivery', '', 6, '2025-08-13 21:16:23', '2025-09-03 12:52:24'),
(41, 6, '[{\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 2}]', 760.00, 'завершён', 'takeaway', '', 6, '2025-08-13 21:17:10', '2025-09-03 12:57:14'),
(42, 6, '[{\"id\": \"62\", \"name\": \"Тест\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"100.00\", \"quantity\": 1}, {\"id\": \"66\", \"name\": \"Бутерброд3\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"1380.00\", \"quantity\": 1}]', 1480.00, 'завершён', 'table', '', 6, '2025-08-13 21:17:51', '2025-08-31 13:57:58'),
(43, 6, '[{\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 1}]', 380.00, 'завершён', 'bar', '', 6, '2025-08-13 21:19:10', '2025-09-03 12:50:02'),
(45, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}, {\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 1}]', 700.00, 'завершён', 'delivery', '', 6, '2025-08-13 21:28:01', '2025-09-03 12:49:17'),
(46, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"price\": \"320.00\", \"quantity\": 1}, {\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"price\": \"380.00\", \"quantity\": 1}]', 700.00, 'завершён', 'takeaway', '', 6, '2025-08-13 21:32:38', '2025-09-03 12:03:34'),
(47, 6, '[{\"id\": \"77\", \"name\": \"Бутерброд1\", \"price\": \"3450.00\", \"quantity\": 3}]', 10350.00, 'отказ', 'takeaway', '', 6, '2025-08-13 21:33:25', '2025-08-16 15:03:17'),
(48, 6, '[{\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"price\": \"380.00\", \"quantity\": 3}]', 1140.00, 'завершён', 'takeaway', '', 6, '2025-08-13 21:34:01', '2025-08-16 14:50:00'),
(49, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"price\": \"320.00\", \"quantity\": 3}]', 960.00, 'завершён', 'delivery', '', 6, '2025-08-13 21:34:53', '2025-08-19 07:38:08'),
(50, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 3}]', 960.00, 'завершён', 'bar', '', 6, '2025-08-13 21:35:24', '2025-09-03 12:02:44'),
(51, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 3}]', 960.00, 'завершён', 'delivery', '', 6, '2025-08-13 21:40:21', '2025-09-03 11:28:24'),
(52, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 2}]', 640.00, 'завершён', 'takeaway', '', 6, '2025-08-13 21:40:52', '2025-09-03 11:05:56'),
(53, 6, '[{\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 3}]', 1140.00, 'завершён', 'bar', '', 6, '2025-08-13 21:41:37', '2025-09-03 00:59:24'),
(54, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 3}]', 960.00, 'завершён', 'takeaway', '', 6, '2025-08-13 21:53:03', '2025-09-03 11:05:38'),
(55, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 2}]', 640.00, 'завершён', 'bar', '', 6, '2025-08-06 21:54:12', '2025-08-31 13:57:49'),
(56, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}]', 320.00, 'отказ', 'delivery', '', 6, '2025-08-06 21:54:30', '2025-08-31 13:55:54'),
(57, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 3}, {\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 3}]', 2100.00, 'завершён', 'delivery', '', 13, '2025-08-08 23:43:35', '2025-08-31 13:55:17'),
(58, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}]', 320.00, 'завершён', 'delivery', '', 6, '2025-08-07 23:56:13', '2025-08-31 13:54:26'),
(59, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}]', 320.00, 'отказ', 'delivery', '', 6, '2025-08-06 23:58:05', '2025-08-31 13:54:15'),
(60, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 2}]', 640.00, 'завершён', 'table', '', 6, '2025-08-06 00:00:09', '2025-08-31 13:54:08'),
(61, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}]', 320.00, 'отказ', 'delivery', '', 6, '2025-08-05 00:12:53', '2025-08-31 13:54:01'),
(62, 6, '[{\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 1}]', 380.00, 'завершён', 'delivery', 'Буровая 3', 6, '2025-08-04 00:18:17', '2025-08-31 13:53:32'),
(63, 6, '[{\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 1}]', 380.00, 'отказ', 'delivery', 'Буровая 3', 6, '2025-08-03 00:23:02', '2025-08-31 13:53:26'),
(64, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}]', 320.00, 'завершён', 'bar', '', 6, '2025-08-02 00:23:30', '2025-08-31 13:53:19'),
(65, 6, '[{\"id\": \"64\", \"name\": \"бутерброд с курицей\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"380.00\", \"quantity\": 3}]', 1140.00, 'завершён', 'table', '21', 6, '2025-08-01 00:24:39', '2025-08-31 13:53:13'),
(66, 6, '[{\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"320.00\", \"quantity\": 1}, {\"id\": \"65\", \"name\": \"Бутерброд2\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"2320.00\", \"quantity\": 1}]', 2640.00, 'отказ', 'delivery', 'Гамидова 20 а 5 подъезд 72 квартира', 6, '2025-08-24 00:25:52', '2025-08-31 13:53:06'),
(67, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 1}]', 890.00, 'отказ', 'delivery', 'Буровая 3', 6, '2025-08-23 15:40:12', '2025-08-31 13:53:00'),
(70, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 8}]', 8880.00, 'завершён', 'delivery', 'вфывфыв', 6, '2025-08-16 00:22:24', '2025-08-16 15:23:43'),
(73, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 5}]', 5550.00, 'завершён', 'delivery', 'Буровая 3', 6, '2025-08-16 07:41:27', '2025-09-03 00:28:00'),
(74, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}]', 1110.00, 'отказ', 'table', '5 Зал N4', 6, '2025-08-16 07:44:10', '2025-08-16 15:19:48'),
(75, 13, '[{\"id\": \"7\", \"name\": \"Пламя\", \"image\": \"./Coctal/plamia.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 2670.00, 'завершён', 'bar', '', 6, '2025-08-16 08:15:15', '2025-09-03 00:27:56'),
(76, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 3}]', 3330.00, 'завершён', 'table', '5 2-ой этаж', 6, '2025-08-16 13:04:50', '2025-09-03 00:27:54'),
(77, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}]', 1110.00, 'завершён', 'delivery', 'Ирчи Казака 76 кв 47', 6, '2025-08-16 13:06:16', '2025-09-03 00:27:53'),
(78, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 4}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 3}]', 5310.00, 'завершён', 'delivery', 'Буровая 3', 6, '2025-08-16 16:40:22', '2025-09-03 00:27:51'),
(79, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 4}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 3}]', 5310.00, 'завершён', 'bar', '', 6, '2025-08-16 16:41:58', '2025-08-26 03:49:14'),
(86, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 1}, {\"id\": \"77\", \"name\": \"Бутерброд1\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"3450.00\", \"quantity\": 1}]', 4850.00, 'завершён', 'takeaway', '', 6, '2025-08-17 08:45:13', '2025-08-17 08:46:36'),
(87, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 1}]', 1110.00, 'завершён', 'bar', '', 6, '2025-08-17 09:28:08', '2025-08-26 03:48:54'),
(88, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 4}]', 4440.00, 'завершён', 'delivery', '\' OR 1=1 --', 6, '2025-08-17 15:56:53', '2025-08-17 20:41:01'),
(92, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 2}]', 2220.00, 'завершён', 'delivery', 'Гамидова 20 а 5 подъезд 72 квартира', 6, '2025-08-17 23:58:41', '2025-08-19 07:37:35'),
(93, 6, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 5}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 4}]', 8010.00, 'завершён', 'delivery', 'Буровая 3', 6, '2025-08-19 05:09:18', '2025-08-31 13:57:59'),
(94, 6, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 5}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 7120.00, 'завершён', 'bar', '', 6, '2025-08-19 06:42:34', '2025-08-19 06:43:59'),
(95, 6, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 5}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 7120.00, 'отказ', 'bar', '', 6, '2025-08-19 07:45:30', '2025-08-26 03:02:56'),
(96, 6, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"890.00\", \"quantity\": 5}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"890.00\", \"quantity\": 3}]', 7120.00, 'завершён', 'bar', '', 6, '2025-08-19 07:50:16', '2025-08-31 13:58:01'),
(97, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"1890.00\", \"quantity\": 4}]', 7560.00, 'завершён', 'bar', '', 6, '2025-08-19 07:50:37', '2025-08-20 00:29:02'),
(98, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 5}]', 5550.00, 'отказ', 'delivery', 'Олега Кошевого', 6, '2025-08-19 12:40:07', '2025-08-19 23:43:26'),
(99, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 4}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"1890.00\", \"quantity\": 5}]', 13890.00, 'завершён', 'delivery', 'Гамидова 20 кв 3', 6, '2025-08-19 23:44:34', '2025-08-20 00:22:44'),
(100, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": \"1110.00\", \"quantity\": 23}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./Pizza/misnaya.jpg\", \"price\": \"290.00\", \"quantity\": 18}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"1890.00\", \"quantity\": 2}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"1111.00\", \"quantity\": 3}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./Coctal/blackjack.jpg\", \"price\": \"1890.00\", \"quantity\": 3}]', 43533.00, 'отказ', 'delivery', 'gdfgfgfd', 6, '2025-08-20 15:17:52', '2025-08-26 02:58:56'),
(101, 6, '[{\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./Coctal/daikiri.jpg\", \"price\": \"1111.00\", \"quantity\": 1}]', 1111.00, 'отказ', 'takeaway', '', 6, '2025-08-20 21:22:06', '2025-08-26 02:59:17'),
(102, 6, '[{\"id\": \"15\", \"name\": \"Тушенное мясо\", \"image\": \"./Snaks/meat.jpg\", \"price\": \"890.00\", \"quantity\": 6}]', 5340.00, 'завершён', 'bar', '', 6, '2025-08-24 08:21:48', '2025-09-03 00:27:50'),
(103, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 5}]', 5550.00, 'завершён', 'delivery', 'Гамидова 100', 6, '2025-08-21 22:37:27', '2025-08-31 13:58:02'),
(104, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 4}]', 4440.00, 'завершён', 'takeaway', '', 6, '2025-08-21 22:58:12', '2025-08-26 03:06:16'),
(105, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 3}]', 3330.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-08-21 23:32:23', '2025-08-22 02:38:07'),
(106, 23, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./image/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./image/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 3}]', 3903.00, 'завершён', 'delivery', 'ул Гамида Далгата 6 кв 5', 6, '2025-08-25 13:00:07', '2025-09-03 00:27:47'),
(107, 13, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./image/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./image/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./image/Pizza/misnaya.jpg\", \"price\": 290, \"quantity\": 1}, {\"id\": \"4\", \"name\": \"Пицца Пипперони\", \"image\": \"./image/Pizza/piperoni.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./image/Coctal/blodymary.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./image/Coctal/national.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"7\", \"name\": \"Пламя2\", \"image\": \"./image/Coctal/plamia.jpg\", \"price\": 2890, \"quantity\": 1}, {\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./image/Coctal/daikiri.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"9\", \"name\": \"Мохито\", \"image\": \"./image/Coctal/mohito.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./image/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./image/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./image/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 15821.00, 'завершён', 'delivery', 'Гайдара 37', 6, '2025-08-23 13:12:03', '2025-08-31 13:58:04'),
(108, 6, '[{\"id\": \"4\", \"name\": \"Пицца Пипперони\", \"image\": \"./images/Pizza/piperoni.jpg\", \"price\": 890, \"quantity\": 2}, {\"id\": \"9\", \"name\": \"Мохито\", \"image\": \"./images/Coctal/mohito.jpg\", \"price\": 890, \"quantity\": 1}]', 2670.00, 'завершён', 'delivery', 'ул Гайдара 37', 6, '2025-08-24 17:32:52', '2025-09-03 00:27:49'),
(109, 6, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./images/Coctal/blodymary.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 4271.00, 'завершён', 'bar', 'bar', 6, '2025-08-25 11:58:24', '2025-08-31 13:51:28'),
(110, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-08-26 12:00:16', '2025-08-31 13:51:22'),
(111, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 2}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./images/Pizza/misnaya.jpg\", \"price\": 290, \"quantity\": 2}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"15\", \"name\": \"Тушенное мясо\", \"image\": \"./images/Snaks/meat.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"63\", \"name\": \"Бутерброд с семгой\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 320, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 9201.00, 'завершён', 'delivery', 'Кавказзская', 6, '2025-08-28 03:53:16', '2025-08-31 13:51:10'),
(112, 6, '[{\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 2}]', 2222.00, 'завершён', 'bar', 'bar', 6, '2025-07-30 18:51:01', '2025-07-30 18:55:21'),
(113, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-10-30 18:51:23', '2025-10-30 18:57:10'),
(114, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"11\", \"name\": \"Баварский сет\", \"image\": \"./images/Sets/bavarskiy.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2970.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-29 18:56:54', '2025-08-31 13:51:02'),
(115, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'bar', 'bar', 6, '2025-08-31 16:09:55', '2025-08-31 16:10:17'),
(116, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 3}]', 3523.00, 'завершён', 'bar', 'bar', 6, '2025-09-02 23:39:10', '2025-09-03 00:26:47'),
(117, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 3}]', 3713.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 00:57:12', '2025-09-03 01:16:17'),
(118, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 01:17:59', '2025-09-03 11:05:04'),
(119, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 12:58:11', '2025-09-03 12:58:46'),
(120, 24, '[{\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 1890.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 13:00:04', '2025-09-03 13:01:50'),
(121, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 13:03:11', '2025-09-03 13:03:52'),
(122, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 13:07:02', '2025-09-03 13:10:01'),
(123, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}]', 380.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 14:15:35', '2025-09-03 14:20:02'),
(124, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 14:20:49', '2025-09-03 14:22:54'),
(125, 24, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./images/Coctal/blodymary.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 4081.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 14:28:53', '2025-09-03 14:31:13'),
(126, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 14:30:50', '2025-09-03 14:37:01'),
(127, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 14:39:51', '2025-09-03 14:41:07'),
(128, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 3}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 5603.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 14:42:29', '2025-09-03 15:28:20'),
(129, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 14:46:04', '2025-09-03 14:51:17'),
(130, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 15:28:52', '2025-09-03 16:13:23'),
(131, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 15:29:05', '2025-09-03 15:52:48'),
(132, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 16:24:38', '2025-09-03 17:05:56'),
(133, 24, '[{\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 1890.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 16:25:00', '2025-09-03 17:05:07'),
(134, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 17:11:01', '2025-09-03 17:12:20'),
(135, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 17:34:29', '2025-09-03 20:05:50'),
(136, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 19:54:42', '2025-09-03 20:04:08'),
(137, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 19:55:06', '2025-09-03 20:04:47'),
(138, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 3}]', 3523.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 20:00:30', '2025-09-03 20:03:04'),
(139, 24, '[{\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 1890.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 20:06:07', '2025-09-03 20:10:06'),
(140, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 20:06:21', '2025-09-03 20:06:43'),
(141, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 3}]', 3523.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 20:16:15', '2025-09-03 20:16:46'),
(142, 24, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./images/Coctal/blodymary.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 4081.00, 'отказ', 'bar', 'bar', 6, '2025-09-03 20:17:04', '2025-09-03 20:17:51'),
(143, 24, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./images/Coctal/blodymary.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 4081.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 20:21:23', '2025-09-03 20:28:28'),
(144, 24, '[{\"id\": \"5\", \"name\": \"Кровавая Мери\", \"image\": \"./images/Coctal/blodymary.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 4081.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 20:29:30', '2025-09-10 00:23:40'),
(145, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 3}]', 3523.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 20:29:46', '2025-09-10 00:13:48'),
(146, 24, '[{\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 1890.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 20:30:09', '2025-09-10 00:14:19'),
(147, 24, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-03 20:31:00', '2025-09-10 01:01:30'),
(148, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 2080.00, 'завершён', 'bar', 'bar', 6, '2025-09-03 20:32:55', '2025-09-10 01:01:27'),
(149, 6, '[{\"id\": \"6\", \"name\": \"Насьональ\", \"image\": \"./images/Coctal/national.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"9\", \"name\": \"Мохито\", \"image\": \"./images/Coctal/mohito.jpg\", \"price\": 890, \"quantity\": 1}]', 1780.00, 'завершён', 'delivery', 'ул Муртазаева 3 кв 12', 6, '2025-09-03 23:44:22', '2025-09-10 00:14:11'),
(150, 6, '[{\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 1111.00, 'завершён', 'bar', 'bar', 6, '2025-09-04 00:07:29', '2025-09-10 00:13:16'),
(151, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}]', 380.00, 'завершён', 'bar', 'bar', 6, '2025-09-04 00:35:37', '2025-09-10 00:37:24'),
(152, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}, {\"id\": \"11\", \"name\": \"Баварский сет\", \"image\": \"./images/Sets/bavarskiy.jpg\", \"price\": 890, \"quantity\": 2}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 2}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 2}]', 9272.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-04 09:10:56', '2025-09-10 00:23:36'),
(153, 6, '[{\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 2}]', 3780.00, 'завершён', 'delivery', 'пр-т Гамидова', 6, '2025-09-09 23:38:55', '2025-09-10 00:12:47'),
(154, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'bar', 'bar', 6, '2025-09-10 00:45:24', '2025-09-10 00:59:17');
INSERT INTO `orders` (`id`, `user_id`, `items`, `total`, `status`, `delivery_type`, `delivery_details`, `last_updated_by`, `created_at`, `updated_at`) VALUES
(155, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-10 01:02:01', '2025-09-10 01:03:07'),
(156, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'bar', 'bar', 6, '2025-09-10 01:03:39', '2025-09-10 01:04:07'),
(157, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-10 01:21:50', '2025-09-10 01:22:55'),
(158, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'bar', 'bar', 6, '2025-09-10 01:33:46', '2025-09-10 01:38:04'),
(159, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'bar', 'bar', 6, '2025-09-10 01:42:16', '2025-09-10 01:46:04'),
(160, 6, '[{\"id\": 10, \"fat\": 1, \"name\": \"Блэк Джэк\", \"carbs\": 100, \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"protein\": 12, \"calories\": 120, \"quantity\": 3, \"line_total\": 570}]', 570.00, '', 'bar', 'bar', NULL, '2025-09-21 23:42:29', '2025-09-21 23:42:29'),
(161, 6, '[{\"id\": 10, \"fat\": 1, \"name\": \"Блэк Джэк\", \"carbs\": 100, \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"protein\": 12, \"calories\": 120, \"quantity\": 3, \"line_total\": 570}]', 570.00, '', 'bar', 'bar', NULL, '2025-09-21 23:44:05', '2025-09-21 23:44:05'),
(162, 6, '[{\"id\": 10, \"fat\": 1, \"name\": \"Блэк Джэк\", \"carbs\": 100, \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"protein\": 12, \"calories\": 120, \"quantity\": 3, \"line_total\": 570}]', 570.00, '', 'bar', 'bar', NULL, '2025-09-21 23:45:44', '2025-09-21 23:45:44'),
(163, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}]', 570.00, '', 'bar', 'bar', NULL, '2025-09-22 01:07:02', '2025-09-22 01:07:02'),
(164, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}]', 570.00, '', 'bar', 'bar', NULL, '2025-09-22 01:15:30', '2025-09-22 01:15:30'),
(165, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}]', 570.00, '', 'takeaway', 'takeaway', NULL, '2025-09-22 01:21:48', '2025-09-22 01:21:48'),
(166, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}]', 570.00, 'завершён', 'bar', 'bar', 6, '2025-09-22 01:47:10', '2025-09-22 02:42:26'),
(167, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}]', 570.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-22 01:51:09', '2025-09-22 02:37:24'),
(168, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 3381.00, 'завершён', 'bar', 'bar', 6, '2025-09-22 02:29:00', '2025-09-22 02:36:32'),
(169, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 7080.00, 'завершён', 'takeaway', 'takeaway', 6, '2025-09-22 02:47:16', '2025-09-22 02:47:45'),
(170, 6, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 3}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./images/Pizza/misnaya.jpg\", \"price\": 290, \"quantity\": 2}, {\"id\": \"11\", \"name\": \"Баварский сет\", \"image\": \"./images/Sets/bavarskiy.jpg\", \"price\": 890, \"quantity\": 2}]', 5690.00, 'готовим', 'table', '5 Гамидова 20 Зал Красный', 6, '2026-01-15 16:10:17', '2026-01-15 16:11:21'),
(177, 999999, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 2}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 3901.00, 'доставляем', 'bar', 'bar; Телефон: +79034981641', 6, '2026-02-04 07:09:06', '2026-02-07 17:32:56'),
(178, 999999, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 3}]', 570.00, 'доставляем', 'delivery', 'Махачкала; Телефон: +79640010071', 6, '2026-02-04 07:14:57', '2026-02-04 08:58:05'),
(179, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'завершён', 'delivery', 'Rodeo Drive', 6, '2026-02-04 08:50:33', '2026-02-07 08:50:06'),
(180, 999999, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 2}]', 380.00, 'готовим', 'delivery', 'Rodeo Drive; Телефон: +79034981642', 6, '2026-02-04 08:58:42', '2026-02-04 08:59:20'),
(181, 999999, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 1301.00, 'готовим', 'takeaway', 'takeaway; Телефон: +79034981642', 6, '2026-02-04 09:01:21', '2026-02-04 09:01:36'),
(182, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 4}, {\"id\": \"14\", \"name\": \"Нут с мясом\", \"image\": \"./images/Snaks/meatnut.jpg\", \"price\": 890, \"quantity\": 2}, {\"id\": \"15\", \"name\": \"Тушенное мясо\", \"image\": \"./images/Snaks/meat.jpg\", \"price\": 890, \"quantity\": 1}]', 3430.00, 'завершён', 'takeaway', 'takeaway', 6, '2026-02-04 09:04:08', '2026-02-07 08:50:03'),
(183, 6, '[{\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 1111.00, 'доставляем', 'takeaway', 'takeaway', 6, '2026-02-04 09:29:29', '2026-02-04 09:44:46'),
(184, 6, '[{\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 1111.00, 'завершён', 'takeaway', 'takeaway', 6, '2026-02-04 09:32:28', '2026-02-04 09:44:53'),
(185, 999999, '[{\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 3001.00, 'завершён', 'delivery', 'Даггеомониторинг, 46А, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия; Телефон: +79034981642', 6, '2026-02-04 10:32:49', '2026-02-04 10:48:18'),
(186, 999999, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 1301.00, 'доставляем', 'delivery', 'Даггеомониторинг, 46А, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия; Телефон: +79034981642', 6, '2026-02-04 10:51:04', '2026-02-07 05:44:18'),
(187, 999999, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'доставляем', 'delivery', 'Даггеомониторинг, 46А, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия; Телефон: +79034981642', 6, '2026-02-04 10:53:47', '2026-02-07 05:57:17'),
(188, 999999, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 190.00, 'доставляем', 'delivery', 'Даггеомониторинг, 46А, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия; Телефон: +79034981642', 6, '2026-02-04 14:24:47', '2026-02-04 14:26:08'),
(189, 999999, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 1301.00, 'доставляем', 'takeaway', 'takeaway; Телефон: +79034981642', 6, '2026-02-04 14:27:29', '2026-02-07 08:49:51'),
(190, 999999, '[{\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"81\", \"name\": \"Блэк Джэк3\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 1890, \"quantity\": 1}]', 5780.00, 'доставляем', 'delivery', 'Даггеомониторинг, 46А, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия; Телефон: +79094800825', 6, '2026-02-04 15:30:13', '2026-02-07 04:19:55'),
(191, 19, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}, {\"id\": \"2\", \"name\": \"Пицца Маргарита\", \"image\": \"./images/Pizza/margarita.jpg\", \"price\": 3890, \"quantity\": 1}, {\"id\": \"3\", \"name\": \"Мясная пицца\", \"image\": \"./images/Pizza/misnaya.jpg\", \"price\": 290, \"quantity\": 1}]', 5290.00, 'завершён', 'delivery', 'Типография, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия', 6, '2026-02-04 15:34:46', '2026-02-07 05:44:53'),
(192, 999999, '[{\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 2}]', 2222.00, 'завершён', 'delivery', 'Даггеомониторинг, 46А, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия; Телефон: +79034981642', 6, '2026-02-04 17:53:45', '2026-02-07 04:36:24'),
(193, 6, '[{\"id\": \"8\", \"name\": \"Дайкири\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 890, \"quantity\": 1}, {\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}]', 1080.00, 'завершён', 'delivery', 'Даггеомониторинг, 46А, улица Олега Кошевого, 2-й Юго-западный микрорайон, Ленинский район, Махачкала, городской округ Махачкала, Дагестан, Северо-Кавказский федеральный округ, 367000, Россия', 6, '2026-02-04 17:54:34', '2026-02-07 08:49:46'),
(194, 999999, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}]', 1110.00, 'завершён', 'delivery', '42.955845, 47.510515; Телефон: +79034981642', 6, '2026-02-06 02:37:09', '2026-02-07 05:58:07'),
(195, 999999, '[{\"id\": \"1\", \"name\": \"Куринная с грибами\", \"image\": \"./images/Pizza/kurinaya.jpg\", \"price\": 1110, \"quantity\": 1}]', 1110.00, 'завершён', 'delivery', '42.953594, 47.511234; Телефон: +79034981642', 6, '2026-02-06 20:49:17', '2026-02-07 05:44:29'),
(196, 6, '[{\"id\": \"10\", \"name\": \"Блэк Джэк\", \"image\": \"./images/Coctal/blackjack.jpg\", \"price\": 190, \"quantity\": 1}, {\"id\": \"80\", \"name\": \"Блэк Джэк2\", \"image\": \"./images/Coctal/daikiri.jpg\", \"price\": 1111, \"quantity\": 1}]', 1301.00, 'завершён', 'bar', 'bar', 6, '2026-02-06 20:50:27', '2026-02-07 04:19:10');

-- --------------------------------------------------------

--
-- Структура таблицы `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `status` varchar(50) NOT NULL,
  `changed_by` int NOT NULL,
  `changed_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Дамп данных таблицы `order_status_history`
--

INSERT INTO `order_status_history` (`id`, `order_id`, `status`, `changed_by`, `changed_at`) VALUES
(1, 45, 'Приём', 6, '2025-08-14 00:28:01'),
(2, 46, 'Приём', 6, '2025-08-14 00:32:38'),
(3, 47, 'Приём', 6, '2025-08-14 00:33:25'),
(4, 48, 'Приём', 6, '2025-08-14 00:34:01'),
(5, 49, 'Приём', 6, '2025-08-14 00:34:53'),
(6, 50, 'Приём', 6, '2025-08-14 00:35:24'),
(7, 51, 'Приём', 6, '2025-08-14 00:40:21'),
(8, 52, 'Приём', 6, '2025-08-14 00:40:52'),
(9, 53, 'Приём', 6, '2025-08-14 00:41:37'),
(10, 54, 'Приём', 6, '2025-08-14 00:53:03'),
(11, 55, 'Приём', 6, '2025-08-14 00:54:12'),
(12, 56, 'Приём', 6, '2025-08-14 00:54:30'),
(13, 57, 'Приём', 6, '2025-08-14 02:43:35'),
(14, 58, 'Приём', 6, '2025-08-14 02:56:13'),
(15, 59, 'Приём', 6, '2025-08-14 02:58:05'),
(16, 60, 'Приём', 6, '2025-08-14 03:00:09'),
(17, 61, 'Приём', 6, '2025-08-14 03:12:53'),
(18, 62, 'Приём', 6, '2025-08-14 03:18:17'),
(19, 63, 'Приём', 6, '2025-08-14 03:23:02'),
(20, 64, 'Приём', 6, '2025-08-14 03:23:30'),
(21, 65, 'Приём', 6, '2025-08-14 03:24:39'),
(22, 66, 'Приём', 6, '2025-08-14 03:25:52'),
(23, 67, 'Приём', 6, '2025-08-14 18:40:12'),
(26, 70, 'Приём', 6, '2025-08-16 03:22:24'),
(28, 48, 'готовим', 6, '2025-08-16 10:09:48'),
(29, 48, 'доставляем', 6, '2025-08-16 10:09:55'),
(39, 73, 'Приём', 6, '2025-08-16 10:41:27'),
(40, 74, 'Приём', 6, '2025-08-16 10:44:10'),
(41, 75, 'Приём', 13, '2025-08-16 11:15:15'),
(42, 76, 'Приём', 6, '2025-08-16 16:04:50'),
(43, 77, 'Приём', 6, '2025-08-16 16:06:16'),
(44, 74, 'готовим', 6, '2025-08-16 17:49:54'),
(45, 48, 'завершён', 6, '2025-08-16 17:50:00'),
(46, 13, 'завершён', 6, '2025-08-16 18:02:56'),
(47, 47, 'отказ', 6, '2025-08-16 18:03:17'),
(49, 63, 'отказ', 6, '2025-08-16 18:04:21'),
(50, 11, 'завершён', 6, '2025-08-16 18:16:33'),
(51, 10, 'завершён', 6, '2025-08-16 18:16:44'),
(52, 6, 'завершён', 6, '2025-08-16 18:16:50'),
(53, 15, 'завершён', 6, '2025-08-16 18:18:54'),
(54, 24, 'отказ', 6, '2025-08-16 18:19:02'),
(55, 74, 'отказ', 6, '2025-08-16 18:19:48'),
(56, 65, 'завершён', 6, '2025-08-16 18:23:26'),
(57, 70, 'доставляем', 6, '2025-08-16 18:23:37'),
(58, 70, 'завершён', 6, '2025-08-16 18:23:43'),
(59, 67, 'отказ', 6, '2025-08-16 18:23:57'),
(60, 64, 'доставляем', 6, '2025-08-16 18:26:06'),
(61, 64, 'завершён', 6, '2025-08-16 18:26:55'),
(62, 78, 'Приём', 6, '2025-08-16 19:40:22'),
(63, 79, 'Приём', 6, '2025-08-16 19:41:58'),
(76, 59, 'отказ', 6, '2025-08-16 21:17:49'),
(78, 32, 'доставляем', 6, '2025-08-16 21:18:12'),
(79, 86, 'Приём', 6, '2025-08-17 11:45:13'),
(80, 86, 'готовим', 6, '2025-08-17 11:46:20'),
(81, 86, 'доставляем', 6, '2025-08-17 11:46:30'),
(82, 86, 'завершён', 6, '2025-08-17 11:46:36'),
(83, 32, 'отказ', 6, '2025-08-17 11:46:47'),
(84, 87, 'Приём', 6, '2025-08-17 12:28:08'),
(85, 88, 'Приём', 6, '2025-08-17 18:56:53'),
(86, 88, 'готовим', 6, '2025-08-17 23:40:39'),
(87, 88, 'доставляем', 6, '2025-08-17 23:40:46'),
(88, 88, 'завершён', 6, '2025-08-17 23:41:01'),
(89, 61, 'отказ', 6, '2025-08-17 23:41:25'),
(90, 56, 'отказ', 6, '2025-08-17 23:41:29'),
(91, 34, 'доставляем', 6, '2025-08-17 23:41:50'),
(95, 92, 'Приём', 6, '2025-08-18 02:58:41'),
(96, 92, 'готовим', 6, '2025-08-18 02:58:53'),
(97, 93, 'Приём', 6, '2025-08-19 08:09:18'),
(98, 94, 'Приём', 6, '2025-08-19 09:42:34'),
(99, 94, 'готовим', 6, '2025-08-19 09:43:48'),
(100, 94, 'доставляем', 6, '2025-08-19 09:43:55'),
(101, 94, 'завершён', 6, '2025-08-19 09:43:59'),
(102, 93, 'готовим', 6, '2025-08-19 10:37:24'),
(103, 92, 'доставляем', 6, '2025-08-19 10:37:29'),
(104, 92, 'завершён', 6, '2025-08-19 10:37:35'),
(105, 49, 'готовим', 6, '2025-08-19 10:38:01'),
(106, 49, 'доставляем', 6, '2025-08-19 10:38:05'),
(107, 49, 'завершён', 6, '2025-08-19 10:38:08'),
(108, 95, 'Приём', 6, '2025-08-19 10:45:30'),
(109, 96, 'Приём', 6, '2025-08-19 10:50:16'),
(110, 97, 'Приём', 6, '2025-08-19 10:50:37'),
(111, 98, 'Приём', 6, '2025-08-19 15:40:07'),
(112, 34, 'завершён', 6, '2025-08-20 02:42:56'),
(113, 98, 'готовим', 6, '2025-08-20 02:43:12'),
(114, 98, 'доставляем', 6, '2025-08-20 02:43:16'),
(115, 98, 'отказ', 6, '2025-08-20 02:43:26'),
(116, 99, 'Приём', 6, '2025-08-20 02:44:34'),
(117, 99, 'готовим', 6, '2025-08-20 03:22:26'),
(118, 99, 'доставляем', 6, '2025-08-20 03:22:35'),
(119, 99, 'завершён', 6, '2025-08-20 03:22:44'),
(120, 97, 'готовим', 6, '2025-08-20 03:28:50'),
(121, 97, 'доставляем', 6, '2025-08-20 03:28:55'),
(122, 97, 'завершён', 6, '2025-08-20 03:29:02'),
(123, 100, 'Приём', 6, '2025-08-20 18:17:52'),
(124, 101, 'Приём', 6, '2025-08-21 00:22:06'),
(125, 102, 'Приём', 6, '2025-08-21 11:21:48'),
(126, 103, 'Приём', 6, '2025-08-22 01:37:27'),
(127, 104, 'Приём', 6, '2025-08-22 01:58:12'),
(128, 105, 'Приём', 6, '2025-08-22 02:32:23'),
(129, 105, 'готовим', 6, '2025-08-22 05:37:26'),
(130, 105, 'доставляем', 6, '2025-08-22 05:37:34'),
(131, 105, 'завершён', 6, '2025-08-22 05:38:07'),
(132, 12, 'доставляем', 6, '2025-08-22 05:39:02'),
(133, 93, 'доставляем', 6, '2025-08-22 05:39:09'),
(134, 39, 'доставляем', 6, '2025-08-22 05:39:19'),
(135, 42, 'доставляем', 6, '2025-08-22 05:40:44'),
(136, 104, 'готовим', 6, '2025-08-22 05:40:48'),
(137, 103, 'готовим', 6, '2025-08-22 21:09:38'),
(138, 102, 'готовим', 6, '2025-08-22 21:11:35'),
(139, 100, 'готовим', 6, '2025-08-22 21:13:00'),
(140, 95, 'готовим', 6, '2025-08-22 21:13:03'),
(141, 95, 'доставляем', 6, '2025-08-22 21:13:09'),
(142, 87, 'готовим', 13, '2025-08-23 03:00:15'),
(143, 57, 'готовим', 13, '2025-08-23 03:00:26'),
(144, 57, 'доставляем', 13, '2025-08-23 03:00:33'),
(145, 57, 'завершён', 13, '2025-08-23 03:00:53'),
(146, 101, 'готовим', 6, '2025-08-23 11:34:13'),
(151, 79, 'готовим', 6, '2025-08-23 11:35:23'),
(152, 12, 'завершён', 6, '2025-08-24 23:25:52'),
(153, 106, 'Приём', 23, '2025-08-25 16:00:07'),
(154, 16, 'доставляем', 6, '2025-08-26 05:33:33'),
(155, 100, 'доставляем', 6, '2025-08-26 05:37:20'),
(156, 101, 'доставляем', 6, '2025-08-26 05:40:18'),
(157, 100, 'отказ', 6, '2025-08-26 05:58:56'),
(158, 101, 'отказ', 6, '2025-08-26 05:59:17'),
(159, 95, 'отказ', 6, '2025-08-26 06:02:56'),
(160, 104, 'доставляем', 6, '2025-08-26 06:05:42'),
(161, 103, 'доставляем', 6, '2025-08-26 06:05:58'),
(162, 104, 'завершён', 6, '2025-08-26 06:06:16'),
(163, 87, 'доставляем', 6, '2025-08-26 06:36:48'),
(164, 96, 'готовим', 6, '2025-08-26 06:36:51'),
(165, 79, 'доставляем', 6, '2025-08-26 06:37:10'),
(166, 38, 'доставляем', 6, '2025-08-26 06:48:38'),
(167, 87, 'завершён', 6, '2025-08-26 06:48:54'),
(168, 79, 'завершён', 6, '2025-08-26 06:49:14'),
(169, 102, 'доставляем', 6, '2025-08-26 07:01:02'),
(170, 96, 'доставляем', 6, '2025-08-26 15:50:42'),
(171, 37, 'доставляем', 6, '2025-08-26 16:05:39'),
(172, 37, 'завершён', 6, '2025-08-26 16:05:44'),
(173, 36, 'доставляем', 6, '2025-08-26 16:07:49'),
(174, 33, 'доставляем', 6, '2025-08-26 16:07:54'),
(175, 53, 'готовим', 6, '2025-08-26 16:08:00'),
(176, 52, 'готовим', 6, '2025-08-26 16:08:03'),
(177, 107, 'Приём', 13, '2025-08-26 16:12:03'),
(178, 107, 'готовим', 6, '2025-08-26 16:12:15'),
(179, 107, 'доставляем', 6, '2025-08-26 16:12:50'),
(180, 106, 'готовим', 13, '2025-08-26 16:26:56'),
(181, 16, 'завершён', 6, '2025-08-26 17:01:04'),
(182, 108, 'Приём', 6, '2025-08-28 20:32:52'),
(183, 109, 'Приём', 6, '2025-08-29 14:58:24'),
(184, 109, 'готовим', 6, '2025-08-29 14:58:39'),
(185, 109, 'доставляем', 6, '2025-08-29 14:58:45'),
(186, 109, 'завершён', 6, '2025-08-29 14:58:51'),
(187, 110, 'Приём', 6, '2025-08-29 15:00:16'),
(188, 110, 'готовим', 6, '2025-08-29 15:03:39'),
(189, 110, 'доставляем', 6, '2025-08-29 15:03:45'),
(190, 110, 'завершён', 6, '2025-08-29 15:03:50'),
(191, 111, 'Приём', 6, '2025-08-30 06:53:16'),
(192, 111, 'готовим', 6, '2025-08-30 06:53:39'),
(193, 111, 'доставляем', 6, '2025-08-30 06:53:57'),
(194, 111, 'завершён', 6, '2025-08-30 06:54:12'),
(195, 112, 'Приём', 6, '2025-08-30 21:51:01'),
(196, 113, 'Приём', 6, '2025-08-30 21:51:23'),
(197, 112, 'готовим', 6, '2025-08-30 21:51:39'),
(198, 113, 'готовим', 6, '2025-08-30 21:51:42'),
(199, 112, 'доставляем', 6, '2025-08-30 21:51:59'),
(200, 112, 'завершён', 6, '2025-08-30 21:55:21'),
(201, 113, 'доставляем', 6, '2025-08-30 21:55:28'),
(202, 114, 'Приём', 6, '2025-08-30 21:56:54'),
(203, 113, 'завершён', 6, '2025-08-30 21:57:10'),
(204, 114, 'готовим', 6, '2025-08-30 21:57:35'),
(205, 114, 'доставляем', 6, '2025-08-30 21:59:25'),
(206, 114, 'завершён', 6, '2025-08-30 21:59:38'),
(207, 1, 'готовим', 6, '2025-08-31 16:57:30'),
(208, 55, 'готовим', 6, '2025-08-31 16:57:33'),
(209, 2, 'готовим', 6, '2025-08-31 16:57:35'),
(210, 2, 'доставляем', 6, '2025-08-31 16:57:38'),
(211, 55, 'доставляем', 6, '2025-08-31 16:57:40'),
(212, 1, 'доставляем', 6, '2025-08-31 16:57:41'),
(213, 1, 'завершён', 6, '2025-08-31 16:57:47'),
(214, 55, 'завершён', 6, '2025-08-31 16:57:49'),
(215, 2, 'завершён', 6, '2025-08-31 16:57:51'),
(216, 33, 'завершён', 6, '2025-08-31 16:57:53'),
(217, 38, 'завершён', 6, '2025-08-31 16:57:55'),
(218, 39, 'завершён', 6, '2025-08-31 16:57:56'),
(219, 42, 'завершён', 6, '2025-08-31 16:57:58'),
(220, 93, 'завершён', 6, '2025-08-31 16:57:59'),
(221, 96, 'завершён', 6, '2025-08-31 16:58:01'),
(222, 103, 'завершён', 6, '2025-08-31 16:58:02'),
(223, 107, 'завершён', 6, '2025-08-31 16:58:04'),
(224, 115, 'Приём', 6, '2025-08-31 19:09:55'),
(225, 115, 'готовим', 6, '2025-08-31 19:10:09'),
(226, 115, 'доставляем', 6, '2025-08-31 19:10:13'),
(227, 115, 'завершён', 6, '2025-08-31 19:10:17'),
(228, 108, 'готовим', 6, '2025-09-03 02:07:48'),
(229, 78, 'готовим', 6, '2025-09-03 02:08:07'),
(230, 77, 'готовим', 6, '2025-09-03 02:09:23'),
(231, 76, 'готовим', 6, '2025-09-03 02:09:33'),
(232, 75, 'готовим', 6, '2025-09-03 02:09:39'),
(233, 73, 'готовим', 6, '2025-09-03 02:10:09'),
(234, 54, 'готовим', 6, '2025-09-03 02:10:31'),
(235, 51, 'готовим', 6, '2025-09-03 02:10:53'),
(236, 50, 'готовим', 6, '2025-09-03 02:11:20'),
(237, 106, 'доставляем', 6, '2025-09-03 02:11:56'),
(238, 46, 'готовим', 6, '2025-09-03 02:12:11'),
(239, 108, 'доставляем', 6, '2025-09-03 02:12:31'),
(240, 78, 'доставляем', 6, '2025-09-03 02:13:29'),
(241, 77, 'доставляем', 6, '2025-09-03 02:15:10'),
(242, 76, 'доставляем', 6, '2025-09-03 02:15:19'),
(243, 75, 'доставляем', 6, '2025-09-03 02:16:07'),
(244, 73, 'доставляем', 6, '2025-09-03 02:16:33'),
(245, 116, 'Приём', 24, '2025-09-03 02:39:10'),
(246, 116, 'готовим', 6, '2025-09-03 02:40:27'),
(247, 45, 'готовим', 6, '2025-09-03 02:40:49'),
(248, 43, 'готовим', 6, '2025-09-03 02:41:05'),
(249, 4, 'готовим', 6, '2025-09-03 02:41:28'),
(250, 116, 'доставляем', 6, '2025-09-03 02:46:12'),
(251, 41, 'готовим', 6, '2025-09-03 03:23:27'),
(252, 40, 'готовим', 6, '2025-09-03 03:25:25'),
(253, 25, 'готовим', 6, '2025-09-03 03:25:59'),
(254, 116, 'завершён', 6, '2025-09-03 03:26:47'),
(255, 23, 'готовим', 6, '2025-09-03 03:27:11'),
(256, 106, 'завершён', 6, '2025-09-03 03:27:47'),
(257, 108, 'завершён', 6, '2025-09-03 03:27:49'),
(258, 102, 'завершён', 6, '2025-09-03 03:27:50'),
(259, 78, 'завершён', 6, '2025-09-03 03:27:51'),
(260, 77, 'завершён', 6, '2025-09-03 03:27:53'),
(261, 76, 'завершён', 6, '2025-09-03 03:27:54'),
(262, 75, 'завершён', 6, '2025-09-03 03:27:56'),
(263, 36, 'завершён', 6, '2025-09-03 03:27:58'),
(264, 73, 'завершён', 6, '2025-09-03 03:28:00'),
(265, 54, 'доставляем', 6, '2025-09-03 03:28:18'),
(266, 53, 'доставляем', 6, '2025-09-03 03:28:47'),
(267, 18, 'готовим', 6, '2025-09-03 03:34:50'),
(268, 117, 'Приём', 24, '2025-09-03 03:57:12'),
(269, 117, 'готовим', 6, '2025-09-03 03:57:25'),
(270, 117, 'доставляем', 6, '2025-09-03 03:59:13'),
(271, 53, 'завершён', 6, '2025-09-03 03:59:24'),
(272, 5, 'готовим', 6, '2025-09-03 03:59:52'),
(273, 117, 'завершён', 6, '2025-09-03 04:16:17'),
(274, 118, 'Приём', 24, '2025-09-03 04:17:59'),
(275, 118, 'готовим', 6, '2025-09-03 04:18:09'),
(276, 118, 'доставляем', 6, '2025-09-03 04:18:27'),
(277, 14, 'готовим', 6, '2025-09-03 13:55:47'),
(278, 8, 'готовим', 6, '2025-09-03 13:56:20'),
(279, 52, 'доставляем', 6, '2025-09-03 14:02:48'),
(280, 51, 'доставляем', 6, '2025-09-03 14:02:53'),
(281, 50, 'доставляем', 6, '2025-09-03 14:03:02'),
(282, 46, 'доставляем', 6, '2025-09-03 14:03:24'),
(283, 118, 'завершён', 6, '2025-09-03 14:05:04'),
(284, 54, 'завершён', 6, '2025-09-03 14:05:38'),
(285, 52, 'завершён', 6, '2025-09-03 14:05:56'),
(286, 51, 'завершён', 6, '2025-09-03 14:28:24'),
(287, 50, 'завершён', 6, '2025-09-03 15:02:44'),
(288, 46, 'завершён', 6, '2025-09-03 15:03:34'),
(289, 45, 'доставляем', 6, '2025-09-03 15:49:16'),
(290, 45, 'завершён', 6, '2025-09-03 15:49:17'),
(291, 43, 'доставляем', 6, '2025-09-03 15:50:01'),
(292, 43, 'завершён', 6, '2025-09-03 15:50:02'),
(293, 41, 'доставляем', 6, '2025-09-03 15:51:59'),
(294, 40, 'доставляем', 6, '2025-09-03 15:52:23'),
(295, 40, 'завершён', 6, '2025-09-03 15:52:24'),
(296, 31, 'доставляем', 6, '2025-09-03 15:53:04'),
(297, 29, 'доставляем', 6, '2025-09-03 15:55:19'),
(298, 29, 'завершён', 6, '2025-09-03 15:55:22'),
(299, 28, 'доставляем', 6, '2025-09-03 15:55:32'),
(300, 28, 'завершён', 6, '2025-09-03 15:55:32'),
(301, 25, 'доставляем', 6, '2025-09-03 15:56:32'),
(302, 25, 'завершён', 6, '2025-09-03 15:56:33'),
(303, 41, 'завершён', 6, '2025-09-03 15:57:14'),
(304, 23, 'доставляем', 6, '2025-09-03 15:57:18'),
(305, 22, 'доставляем', 6, '2025-09-03 15:57:26'),
(306, 18, 'доставляем', 6, '2025-09-03 15:57:38'),
(307, 119, 'Приём', 24, '2025-09-03 15:58:11'),
(308, 119, 'готовим', 6, '2025-09-03 15:58:26'),
(309, 119, 'доставляем', 6, '2025-09-03 15:58:37'),
(310, 119, 'завершён', 6, '2025-09-03 15:58:46'),
(311, 120, 'Приём', 24, '2025-09-03 16:00:04'),
(312, 120, 'готовим', 6, '2025-09-03 16:00:31'),
(313, 120, 'доставляем', 6, '2025-09-03 16:00:33'),
(314, 120, 'завершён', 6, '2025-09-03 16:01:50'),
(315, 121, 'Приём', 24, '2025-09-03 16:03:11'),
(316, 121, 'готовим', 6, '2025-09-03 16:03:16'),
(317, 121, 'доставляем', 6, '2025-09-03 16:03:26'),
(318, 121, 'завершён', 6, '2025-09-03 16:03:52'),
(319, 122, 'Приём', 24, '2025-09-03 16:07:02'),
(320, 122, 'готовим', 6, '2025-09-03 16:07:27'),
(321, 122, 'доставляем', 6, '2025-09-03 16:09:48'),
(322, 122, 'завершён', 6, '2025-09-03 16:10:01'),
(323, 17, 'доставляем', 6, '2025-09-03 16:19:35'),
(324, 17, 'завершён', 6, '2025-09-03 16:19:36'),
(325, 14, 'доставляем', 6, '2025-09-03 16:20:56'),
(326, 14, 'завершён', 6, '2025-09-03 16:21:07'),
(327, 8, 'доставляем', 6, '2025-09-03 16:27:17'),
(328, 31, 'завершён', 6, '2025-09-03 16:28:41'),
(329, 23, 'завершён', 6, '2025-09-03 16:29:59'),
(330, 5, 'доставляем', 6, '2025-09-03 16:33:09'),
(331, 5, 'завершён', 6, '2025-09-03 16:33:25'),
(332, 4, 'доставляем', 6, '2025-09-03 17:09:28'),
(333, 4, 'завершён', 6, '2025-09-03 17:09:34'),
(334, 8, 'завершён', 6, '2025-09-03 17:09:57'),
(335, 22, 'завершён', 6, '2025-09-03 17:11:15'),
(336, 18, 'завершён', 6, '2025-09-03 17:13:27'),
(337, 123, 'Приём', 24, '2025-09-03 17:15:35'),
(338, 123, 'готовим', 6, '2025-09-03 17:15:51'),
(339, 123, 'доставляем', 6, '2025-09-03 17:18:11'),
(340, 123, 'завершён', 6, '2025-09-03 17:20:02'),
(341, 124, 'Приём', 24, '2025-09-03 17:20:49'),
(342, 124, 'готовим', 6, '2025-09-03 17:21:15'),
(343, 124, 'доставляем', 6, '2025-09-03 17:21:18'),
(344, 124, 'завершён', 6, '2025-09-03 17:22:54'),
(345, 125, 'Приём', 24, '2025-09-03 17:28:53'),
(346, 125, 'готовим', 6, '2025-09-03 17:29:35'),
(347, 125, 'доставляем', 6, '2025-09-03 17:29:36'),
(348, 126, 'Приём', 24, '2025-09-03 17:30:50'),
(349, 125, 'завершён', 6, '2025-09-03 17:31:13'),
(350, 126, 'готовим', 6, '2025-09-03 17:32:28'),
(351, 126, 'доставляем', 6, '2025-09-03 17:32:47'),
(352, 126, 'завершён', 6, '2025-09-03 17:37:01'),
(353, 127, 'Приём', 24, '2025-09-03 17:39:51'),
(354, 127, 'готовим', 6, '2025-09-03 17:40:13'),
(355, 127, 'доставляем', 6, '2025-09-03 17:40:16'),
(356, 127, 'завершён', 6, '2025-09-03 17:41:07'),
(357, 128, 'Приём', 6, '2025-09-03 17:42:29'),
(358, 129, 'Приём', 24, '2025-09-03 17:46:04'),
(359, 129, 'готовим', 6, '2025-09-03 17:46:32'),
(360, 129, 'доставляем', 6, '2025-09-03 17:48:10'),
(361, 129, 'завершён', 6, '2025-09-03 17:51:17'),
(362, 128, 'готовим', 6, '2025-09-03 17:51:49'),
(363, 128, 'доставляем', 6, '2025-09-03 17:56:01'),
(364, 128, 'завершён', 6, '2025-09-03 18:28:20'),
(365, 130, 'Приём', 24, '2025-09-03 18:28:52'),
(366, 131, 'Приём', 24, '2025-09-03 18:29:05'),
(367, 131, 'готовим', 6, '2025-09-03 18:29:50'),
(368, 130, 'готовим', 6, '2025-09-03 18:30:57'),
(369, 131, 'доставляем', 6, '2025-09-03 18:52:28'),
(370, 131, 'завершён', 6, '2025-09-03 18:52:48'),
(371, 130, 'доставляем', 6, '2025-09-03 19:13:21'),
(372, 130, 'завершён', 6, '2025-09-03 19:13:23'),
(373, 132, 'Приём', 24, '2025-09-03 19:24:38'),
(374, 133, 'Приём', 24, '2025-09-03 19:25:00'),
(375, 133, 'готовим', 6, '2025-09-03 20:03:34'),
(376, 133, 'доставляем', 6, '2025-09-03 20:03:36'),
(377, 132, 'готовим', 6, '2025-09-03 20:04:21'),
(378, 132, 'доставляем', 6, '2025-09-03 20:04:44'),
(379, 133, 'завершён', 6, '2025-09-03 20:05:07'),
(380, 132, 'завершён', 6, '2025-09-03 20:05:56'),
(381, 134, 'Приём', 24, '2025-09-03 20:11:01'),
(382, 134, 'готовим', 6, '2025-09-03 20:11:13'),
(383, 134, 'доставляем', 6, '2025-09-03 20:11:19'),
(384, 134, 'завершён', 6, '2025-09-03 20:12:20'),
(385, 135, 'Приём', 24, '2025-09-03 20:34:29'),
(386, 135, 'готовим', 6, '2025-09-03 21:45:18'),
(387, 136, 'Приём', 24, '2025-09-03 22:54:42'),
(388, 137, 'Приём', 24, '2025-09-03 22:55:06'),
(389, 138, 'Приём', 24, '2025-09-03 23:00:30'),
(390, 138, 'готовим', 6, '2025-09-03 23:00:54'),
(391, 137, 'готовим', 6, '2025-09-03 23:01:41'),
(392, 138, 'доставляем', 6, '2025-09-03 23:02:11'),
(393, 137, 'доставляем', 6, '2025-09-03 23:02:47'),
(394, 138, 'завершён', 6, '2025-09-03 23:03:04'),
(395, 136, 'готовим', 6, '2025-09-03 23:03:36'),
(396, 136, 'доставляем', 6, '2025-09-03 23:04:04'),
(397, 136, 'завершён', 6, '2025-09-03 23:04:08'),
(398, 135, 'доставляем', 6, '2025-09-03 23:04:24'),
(399, 137, 'завершён', 6, '2025-09-03 23:04:47'),
(400, 135, 'завершён', 6, '2025-09-03 23:05:50'),
(401, 139, 'Приём', 24, '2025-09-03 23:06:07'),
(402, 140, 'Приём', 24, '2025-09-03 23:06:21'),
(403, 140, 'готовим', 6, '2025-09-03 23:06:32'),
(404, 140, 'доставляем', 6, '2025-09-03 23:06:37'),
(405, 140, 'завершён', 6, '2025-09-03 23:06:43'),
(406, 139, 'готовим', 6, '2025-09-03 23:09:44'),
(407, 139, 'доставляем', 6, '2025-09-03 23:09:54'),
(408, 139, 'завершён', 6, '2025-09-03 23:10:06'),
(409, 141, 'Приём', 24, '2025-09-03 23:16:15'),
(410, 141, 'готовим', 6, '2025-09-03 23:16:32'),
(411, 141, 'доставляем', 6, '2025-09-03 23:16:38'),
(412, 141, 'завершён', 6, '2025-09-03 23:16:46'),
(413, 142, 'Приём', 24, '2025-09-03 23:17:04'),
(414, 142, 'отказ', 6, '2025-09-03 23:17:51'),
(415, 143, 'Приём', 24, '2025-09-03 23:21:23'),
(416, 143, 'готовим', 6, '2025-09-03 23:28:07'),
(417, 143, 'доставляем', 6, '2025-09-03 23:28:26'),
(418, 143, 'завершён', 6, '2025-09-03 23:28:28'),
(419, 144, 'Приём', 24, '2025-09-03 23:29:30'),
(420, 145, 'Приём', 24, '2025-09-03 23:29:46'),
(421, 146, 'Приём', 24, '2025-09-03 23:30:09'),
(422, 146, 'готовим', 6, '2025-09-03 23:30:16'),
(423, 147, 'Приём', 24, '2025-09-03 23:31:00'),
(424, 148, 'Приём', 6, '2025-09-03 23:32:55'),
(425, 148, 'готовим', 6, '2025-09-04 00:26:39'),
(426, 146, 'доставляем', 6, '2025-09-04 00:26:59'),
(427, 147, 'готовим', 6, '2025-09-04 00:27:42'),
(428, 149, 'Приём', 6, '2025-09-04 02:44:22'),
(429, 150, 'Приём', 6, '2025-09-04 03:07:29'),
(430, 151, 'Приём', 6, '2025-09-04 03:35:37'),
(431, 152, 'Приём', 6, '2025-09-04 12:10:56'),
(432, 152, 'готовим', 6, '2025-09-04 12:11:52'),
(433, 153, 'Приём', 6, '2025-09-10 02:38:55'),
(434, 153, 'готовим', 6, '2025-09-10 03:11:36'),
(435, 151, 'готовим', 6, '2025-09-10 03:12:42'),
(436, 153, 'доставляем', 6, '2025-09-10 03:12:45'),
(437, 153, 'завершён', 6, '2025-09-10 03:12:47'),
(438, 150, 'готовим', 6, '2025-09-10 03:13:05'),
(439, 150, 'доставляем', 6, '2025-09-10 03:13:11'),
(440, 150, 'завершён', 6, '2025-09-10 03:13:16'),
(441, 145, 'готовим', 6, '2025-09-10 03:13:32'),
(442, 145, 'доставляем', 6, '2025-09-10 03:13:38'),
(443, 145, 'завершён', 6, '2025-09-10 03:13:48'),
(444, 149, 'готовим', 6, '2025-09-10 03:14:00'),
(445, 149, 'доставляем', 6, '2025-09-10 03:14:06'),
(446, 149, 'завершён', 6, '2025-09-10 03:14:11'),
(447, 146, 'завершён', 6, '2025-09-10 03:14:19'),
(448, 144, 'готовим', 6, '2025-09-10 03:14:24'),
(449, 144, 'доставляем', 6, '2025-09-10 03:14:27'),
(450, 152, 'доставляем', 6, '2025-09-10 03:23:19'),
(451, 151, 'доставляем', 6, '2025-09-10 03:23:22'),
(452, 152, 'завершён', 6, '2025-09-10 03:23:36'),
(453, 144, 'завершён', 6, '2025-09-10 03:23:40'),
(454, 151, 'завершён', 6, '2025-09-10 03:37:24'),
(455, 148, 'доставляем', 6, '2025-09-10 03:39:24'),
(456, 147, 'доставляем', 6, '2025-09-10 03:39:27'),
(457, 154, 'Приём', 6, '2025-09-10 03:45:24'),
(458, 154, 'готовим', 6, '2025-09-10 03:58:43'),
(459, 154, 'доставляем', 6, '2025-09-10 03:58:58'),
(460, 154, 'завершён', 6, '2025-09-10 03:59:17'),
(461, 148, 'завершён', 6, '2025-09-10 04:01:27'),
(462, 147, 'завершён', 6, '2025-09-10 04:01:30'),
(463, 155, 'Приём', 6, '2025-09-10 04:02:01'),
(464, 155, 'готовим', 6, '2025-09-10 04:02:11'),
(465, 155, 'доставляем', 6, '2025-09-10 04:02:15'),
(466, 155, 'завершён', 6, '2025-09-10 04:03:07'),
(467, 156, 'Приём', 6, '2025-09-10 04:03:39'),
(468, 156, 'готовим', 6, '2025-09-10 04:04:01'),
(469, 156, 'доставляем', 6, '2025-09-10 04:04:04'),
(470, 156, 'завершён', 6, '2025-09-10 04:04:07'),
(471, 157, 'Приём', 6, '2025-09-10 04:21:50'),
(472, 157, 'готовим', 6, '2025-09-10 04:22:46'),
(473, 157, 'доставляем', 6, '2025-09-10 04:22:50'),
(474, 157, 'завершён', 6, '2025-09-10 04:22:55'),
(475, 158, 'Приём', 6, '2025-09-10 04:33:46'),
(476, 158, 'готовим', 6, '2025-09-10 04:35:34'),
(477, 158, 'доставляем', 6, '2025-09-10 04:37:03'),
(478, 158, 'завершён', 6, '2025-09-10 04:38:04'),
(479, 159, 'Приём', 6, '2025-09-10 04:42:16'),
(480, 159, 'готовим', 6, '2025-09-10 04:42:59'),
(481, 159, 'доставляем', 6, '2025-09-10 04:45:09'),
(482, 159, 'завершён', 6, '2025-09-10 04:46:04'),
(483, 160, 'Принят', 6, '2025-09-22 02:42:29'),
(484, 161, 'Принят', 6, '2025-09-22 02:44:05'),
(485, 162, 'Принят', 6, '2025-09-22 02:45:44'),
(486, 163, 'Р СџРЎР‚Р С‘РЎвЂР С', 6, '2025-09-22 04:07:02'),
(487, 164, 'Принят', 6, '2025-09-22 04:15:30'),
(488, 165, 'Принят', 6, '2025-09-22 04:21:48'),
(489, 166, 'Приём', 6, '2025-09-22 04:47:10'),
(490, 167, 'Приём', 6, '2025-09-22 04:51:09'),
(491, 168, 'Приём', 6, '2025-09-22 05:29:00'),
(492, 168, 'готовим', 6, '2025-09-22 05:29:14'),
(493, 168, 'доставляем', 6, '2025-09-22 05:29:19'),
(494, 167, 'готовим', 6, '2025-09-22 05:32:31'),
(495, 166, 'готовим', 6, '2025-09-22 05:32:42'),
(496, 167, 'доставляем', 6, '2025-09-22 05:32:52'),
(497, 166, 'доставляем', 6, '2025-09-22 05:32:58'),
(498, 168, 'завершён', 6, '2025-09-22 05:36:32'),
(499, 167, 'завершён', 6, '2025-09-22 05:37:24'),
(500, 166, 'завершён', 6, '2025-09-22 05:42:26'),
(501, 169, 'Приём', 6, '2025-09-22 05:47:16'),
(502, 169, 'готовим', 6, '2025-09-22 05:47:34'),
(503, 169, 'доставляем', 6, '2025-09-22 05:47:40'),
(504, 169, 'завершён', 6, '2025-09-22 05:47:45'),
(505, 170, 'Приём', 6, '2026-01-15 19:10:17'),
(506, 170, 'готовим', 6, '2026-01-15 19:11:21'),
(507, 177, 'Приём', 999999, '2026-02-04 10:09:06'),
(508, 178, 'Приём', 999999, '2026-02-04 10:14:57'),
(509, 179, 'Приём', 6, '2026-02-04 11:50:33'),
(510, 179, 'готовим', 6, '2026-02-04 11:51:02'),
(511, 178, 'готовим', 6, '2026-02-04 11:51:09'),
(512, 177, 'готовим', 6, '2026-02-04 11:51:24'),
(513, 179, 'доставляем', 6, '2026-02-04 11:53:35'),
(514, 178, 'доставляем', 6, '2026-02-04 11:58:05'),
(515, 180, 'Приём', 999999, '2026-02-04 11:58:42'),
(516, 180, 'готовим', 6, '2026-02-04 11:59:20'),
(517, 181, 'Приём', 999999, '2026-02-04 12:01:21'),
(518, 181, 'готовим', 6, '2026-02-04 12:01:36'),
(519, 182, 'Приём', 6, '2026-02-04 12:04:08'),
(520, 182, 'готовим', 6, '2026-02-04 12:04:59'),
(521, 183, 'Приём', 6, '2026-02-04 12:29:29'),
(522, 183, 'готовим', 6, '2026-02-04 12:29:53'),
(523, 184, 'Приём', 6, '2026-02-04 12:32:28'),
(524, 184, 'готовим', 6, '2026-02-04 12:32:39'),
(525, 184, 'доставляем', 6, '2026-02-04 12:34:54'),
(526, 183, 'доставляем', 6, '2026-02-04 12:44:46'),
(527, 184, 'завершён', 6, '2026-02-04 12:44:53'),
(528, 185, 'Приём', 999999, '2026-02-04 13:32:49'),
(529, 185, 'готовим', 6, '2026-02-04 13:34:32'),
(530, 185, 'доставляем', 6, '2026-02-04 13:47:33'),
(531, 185, 'завершён', 6, '2026-02-04 13:48:18'),
(532, 186, 'Приём', 999999, '2026-02-04 13:51:04'),
(533, 186, 'готовим', 6, '2026-02-04 13:51:44'),
(534, 187, 'Приём', 999999, '2026-02-04 13:53:47'),
(535, 187, 'готовим', 6, '2026-02-04 13:54:03'),
(536, 188, 'Приём', 999999, '2026-02-04 17:24:47'),
(537, 188, 'готовим', 6, '2026-02-04 17:25:41'),
(538, 188, 'доставляем', 6, '2026-02-04 17:26:08'),
(539, 189, 'Приём', 999999, '2026-02-04 17:27:29'),
(540, 189, 'готовим', 6, '2026-02-04 17:27:37'),
(541, 190, 'Приём', 999999, '2026-02-04 18:30:13'),
(542, 191, 'Приём', 19, '2026-02-04 18:34:46'),
(543, 192, 'Приём', 999999, '2026-02-04 20:53:45'),
(544, 192, 'готовим', 6, '2026-02-04 20:54:10'),
(545, 193, 'Приём', 6, '2026-02-04 20:54:34'),
(546, 193, 'готовим', 6, '2026-02-04 20:54:45'),
(547, 191, 'готовим', 6, '2026-02-04 20:55:04'),
(548, 194, 'Приём', 999999, '2026-02-06 05:37:09'),
(549, 195, 'Приём', 999999, '2026-02-06 23:49:17'),
(550, 196, 'Приём', 6, '2026-02-06 23:50:27'),
(551, 196, 'готовим', 6, '2026-02-07 06:30:32'),
(552, 195, 'готовим', 6, '2026-02-07 07:19:03'),
(553, 196, 'доставляем', 6, '2026-02-07 07:19:07'),
(554, 196, 'завершён', 6, '2026-02-07 07:19:10'),
(555, 195, 'доставляем', 6, '2026-02-07 07:19:23'),
(556, 194, 'готовим', 6, '2026-02-07 07:19:32'),
(557, 194, 'доставляем', 6, '2026-02-07 07:19:38'),
(558, 193, 'доставляем', 6, '2026-02-07 07:19:48'),
(559, 190, 'готовим', 6, '2026-02-07 07:19:52'),
(560, 190, 'доставляем', 6, '2026-02-07 07:19:55'),
(561, 192, 'доставляем', 6, '2026-02-07 07:36:15'),
(562, 192, 'завершён', 6, '2026-02-07 07:36:24'),
(563, 191, 'доставляем', 6, '2026-02-07 08:44:11'),
(564, 186, 'доставляем', 6, '2026-02-07 08:44:18'),
(565, 195, 'завершён', 6, '2026-02-07 08:44:29'),
(566, 191, 'завершён', 6, '2026-02-07 08:44:53'),
(567, 187, 'доставляем', 6, '2026-02-07 08:57:17'),
(568, 194, 'завершён', 6, '2026-02-07 08:58:07'),
(569, 193, 'завершён', 6, '2026-02-07 11:49:46'),
(570, 189, 'доставляем', 6, '2026-02-07 11:49:51'),
(571, 182, 'доставляем', 6, '2026-02-07 11:49:55'),
(572, 182, 'завершён', 6, '2026-02-07 11:50:03'),
(573, 179, 'завершён', 6, '2026-02-07 11:50:06'),
(574, 177, 'доставляем', 6, '2026-02-07 20:32:56');

-- --------------------------------------------------------

--
-- Структура таблицы `push_subscriptions`
--

CREATE TABLE `push_subscriptions` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `order_id` int DEFAULT NULL,
  `endpoint` varchar(500) NOT NULL,
  `p256dh` varchar(100) NOT NULL,
  `auth` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `selector` varchar(24) NOT NULL,
  `hashed_validator` varchar(255) NOT NULL,
  `expires_at` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `settings`
--

CREATE TABLE `settings` (
  `id` bigint UNSIGNED NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` json DEFAULT NULL,
  `updated_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Дамп данных таблицы `settings`
--

INSERT INTO `settings` (`id`, `key`, `value`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'color_primary-color', '\"#cd1719\"', 6, '2026-02-04 01:01:36', '2026-02-07 05:41:25'),
(2, 'color_secondary-color', '\"#121212\"', 6, '2026-02-04 01:01:36', '2026-02-07 05:41:25'),
(3, 'color_primary-dark', '\"#000000\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36'),
(4, 'color_accent-color', '\"#db3a34\"', 6, '2026-02-04 01:01:36', '2026-02-07 05:41:25'),
(5, 'color_text-color', '\"#333333\"', 6, '2026-02-04 01:01:36', '2026-02-07 05:41:25'),
(6, 'color_acception', '\"#2c83c2\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36'),
(7, 'color_light-text', '\"#555555\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36'),
(8, 'color_bg-light', '\"#f9f9f9\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36'),
(9, 'color_white', '\"#ffffff\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36'),
(10, 'color_agree', '\"#4caf50\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36'),
(11, 'color_procces', '\"#ff9321\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36'),
(12, 'color_brown', '\"#712121\"', 6, '2026-02-04 01:01:36', '2026-02-04 01:01:36');

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '0',
  `verification_token` varchar(64) DEFAULT NULL,
  `verification_token_expires_at` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `role` enum('customer','employee','admin','owner') NOT NULL DEFAULT 'customer',
  `menu_view` varchar(20) NOT NULL DEFAULT 'default'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `name`, `phone`, `is_active`, `verification_token`, `verification_token_expires_at`, `email_verified_at`, `reset_token`, `reset_token_expires_at`, `created_at`, `updated_at`, `role`, `menu_view`) VALUES
(6, 'fruslanj@gmail.com', '$2y$10$CPdaxRCdH5pnlAIBmuSE9eJbDQSFnMLqNYvkBhtjlARkTy0FxD592', 'Руслан Фоменко', '+79034981642', 1, NULL, NULL, NULL, 'bd5036d54662379b2b7abd3ab44465bdc006bfa6776faeb8590e22bcbfcefff5', '2025-08-13 22:12:29', '2025-08-07 18:21:59', '2026-02-03 01:08:26', 'owner', 'default'),
(13, 'mna-2000@yandex.ru', '$2y$10$y/QCs.nrGeR8KgJMQInp4.JWFOh.gekqOQN4C785jE9R5mvXnR35O', 'Нурия', '+79640010071', 1, NULL, NULL, '2025-08-11 14:20:54', NULL, NULL, '2025-08-11 11:20:41', '2026-02-04 04:47:32', 'owner', 'alt'),
(14, 'mukailoff.nabi@yandex.ru', '$2y$10$/wxUmDdJq4vPf7ilWeNuf.r4c0NNKct8IkpWu2wyEKBvVVrbHYIbO', 'Хаджимуса', NULL, 1, NULL, NULL, NULL, NULL, NULL, '2025-08-11 13:56:10', '2025-10-14 15:29:18', 'owner', 'default'),
(15, 'djamilya.design@gmail.com', '$2y$10$SNtGk4BeEGMEpd1V7Yzzd.vFrsJE40gqJ3.lKV0MrLIo3LazS2wee', 'Djamilya', '89289504148', 1, NULL, NULL, NULL, NULL, NULL, '2025-08-11 14:31:12', '2025-08-11 14:54:48', 'customer', 'default'),
(16, 'jamalala1999@mail.ru', '$2y$10$TY7LENwzyu0Maeo3488ht.r5X9O1JwWMAxvES.mhmAYxHFQjrU1ey', 'Агабекова Микаиловна Джамиля', '9887932367', 1, NULL, NULL, NULL, NULL, NULL, '2025-08-11 14:33:41', '2025-08-11 14:53:49', 'customer', 'default'),
(17, 'jjjr85@mail.ru', '$2y$10$cFVuydV/VVMZoeLWLK31J.IJQK6mMd25N0YcluKDQ0IADyPfX2PHS', 'Ибрагим', '89882294054', 1, NULL, NULL, NULL, NULL, NULL, '2025-08-11 15:43:55', '2025-08-11 15:46:04', 'customer', 'default'),
(18, 'beduin05dag@yandex.ru', '$2y$10$R/VeVQqv1C4/A97t5eZHqOnOzpKACV9ZX4HX//McGz6DXl.SUSxR6', 'Хусейн', NULL, 1, NULL, NULL, NULL, NULL, NULL, '2025-08-11 15:52:46', '2025-08-11 15:53:27', 'customer', 'default'),
(19, 'r.rabadanov@gmail.com', '$2y$10$by7zL4pzcJVa6mdtMYXl/e1OQ./yM5VpKhuw.cUHiiw0mBTCyu2Na', 'Rabadan', NULL, 1, NULL, NULL, NULL, NULL, NULL, '2025-08-11 16:34:32', '2025-08-11 17:23:02', 'customer', 'default'),
(21, 'ahmed61738405@gmail.com', '$2y$10$fyBAexaAGm53Da0ntjtwcOTPD22mlIHcLesepFfBLzWplFwKqDEL6', 'Ахмед', '+7 918 734 75 95', 1, NULL, NULL, '2025-08-12 17:11:04', NULL, NULL, '2025-08-12 14:10:26', '2025-08-12 14:11:04', 'customer', 'default'),
(22, 'afkadimov@gmail.com', '$2y$10$.GjPbg3g4hzUK4GWU3SWpOizgIV8farDXfYqQ7TBps16soncuw62a', 'Артур', NULL, 1, NULL, NULL, '2025-08-20 17:37:33', NULL, NULL, '2025-08-20 14:37:11', '2025-08-20 14:38:03', 'customer', 'alt'),
(23, 'k240em@yandex.ru', '$2y$10$oOnp05zBolPTHgYEpJnLBOVN5LyqzElu3STd358ZZJBhNo99jr.lm', 'Verdihan', NULL, 1, NULL, NULL, '2025-08-25 15:57:38', NULL, NULL, '2025-08-25 12:57:00', '2025-08-25 12:58:18', 'customer', 'alt'),
(24, 'fruslanj@yandex.ru', '$2y$10$VbT4L9YIaGoNlRE5sj8.XOYCvKCRR.InkGaWqPtdkZ5Jo7IJNHGMS', 'Руслан Фоменко', '+79034981642', 1, NULL, NULL, '2025-08-25 16:05:46', NULL, NULL, '2025-08-25 13:04:30', '2025-09-03 00:24:51', 'customer', 'default'),
(25, 'mm4776013@gmail.com', '$2y$10$e5fYFZ4Tb8Rh62UcWgCcteUc15p.Q5w4lBayDf53UUtiEYKeww4nO', 'Магомедов Магомед Заурбекович', '+79894421046', 1, NULL, NULL, '2026-01-16 17:15:48', 'c5c48ad03e78739f60246bb93340252e', '2026-01-16 18:17:01', '2026-01-16 14:15:32', '2026-01-16 14:17:01', 'customer', 'default'),
(999999, 'guest@system.local', '', 'Гость', '', 0, NULL, NULL, NULL, NULL, NULL, '2026-02-04 07:09:06', '2026-02-04 07:09:06', '', 'default');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `selector` (`selector`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_selector_expires` (`selector`,`expires_at`);

--
-- Индексы таблицы `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`),
  ADD KEY `idx_menu_items_category` (`category`,`available`),
  ADD KEY `idx_menu_items_price` (`price`),
  ADD KEY `idx_category_available` (`category`,`available`),
  ADD KEY `idx_available` (`available`);

--
-- Индексы таблицы `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orders_user_status` (`user_id`,`status`),
  ADD KEY `idx_orders_created` (`created_at` DESC),
  ADD KEY `idx_status_created` (`status`,`created_at`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_delivery_type` (`delivery_type`),
  ADD KEY `idx_reports` (`created_at`,`status`,`delivery_type`),
  ADD KEY `idx_user_created` (`user_id`,`created_at` DESC),
  ADD KEY `idx_updated` (`updated_at`),
  ADD KEY `idx_items_count` (`items_count`);

--
-- Индексы таблицы `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_changed` (`order_id`,`changed_at`),
  ADD KEY `idx_changed_by` (`changed_by`);

--
-- Индексы таблицы `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user` (`user_id`,`endpoint`),
  ADD UNIQUE KEY `unique_guest` (`phone`,`order_id`,`endpoint`);

--
-- Индексы таблицы `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `selector` (`selector`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_key` (`key`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_active` (`is_active`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_role_active` (`role`,`is_active`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `auth_tokens`
--
ALTER TABLE `auth_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT для таблицы `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=413;

--
-- AUTO_INCREMENT для таблицы `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=197;

--
-- AUTO_INCREMENT для таблицы `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=575;

--
-- AUTO_INCREMENT для таблицы `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1000000;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD CONSTRAINT `auth_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `order_status_history_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
