-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Dec 18, 2025 at 11:10 PM
-- Server version: 10.4.27-MariaDB
-- PHP Version: 8.2.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pegeditor`
--

-- --------------------------------------------------------

--
-- Table structure for table `capacities`
--

CREATE TABLE `capacities` (
  `ID` int(11) NOT NULL,
  `Capacity` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `capacities`
--

INSERT INTO `capacities` (`ID`, `Capacity`) VALUES
(1, '30TB'),
(2, '20TB'),
(4, '12TB'),
(5, '16TB');

-- --------------------------------------------------------

--
-- Table structure for table `peg_configs`
--

CREATE TABLE `peg_configs` (
  `id` int(11) NOT NULL,
  `capacity` varchar(50) NOT NULL,
  `interface` enum('sata','sas') NOT NULL,
  `condition_type` enum('new','used','recertified') NOT NULL,
  `inventory_mode` enum('balanced','low','overstocked','critical') DEFAULT 'balanced',
  `peg_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `peg_configs`
--

INSERT INTO `peg_configs` (`id`, `capacity`, `interface`, `condition_type`, `inventory_mode`, `peg_name`, `created_at`, `updated_at`) VALUES
(29, '20TB', 'sata', 'new', 'balanced', NULL, '2025-12-17 23:08:34', '2025-12-17 23:08:34');

-- --------------------------------------------------------

--
-- Table structure for table `peg_history`
--

CREATE TABLE `peg_history` (
  `id` int(11) NOT NULL,
  `config_id` int(11) NOT NULL,
  `capacity` varchar(50) NOT NULL,
  `interface` enum('sata','sas') NOT NULL,
  `condition_type` enum('new','used','recertified') NOT NULL,
  `peg_name` varchar(255) DEFAULT NULL,
  `base_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `adjusted_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `inventory_mode` enum('balanced','low','overstocked','critical') DEFAULT 'balanced',
  `saved_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `peg_history`
--

INSERT INTO `peg_history` (`id`, `config_id`, `capacity`, `interface`, `condition_type`, `peg_name`, `base_price`, `adjusted_price`, `inventory_mode`, `saved_at`) VALUES
(62, 29, '20TB', 'sata', 'new', NULL, '100.00', '100.00', 'balanced', '2025-12-17 23:08:34');

-- --------------------------------------------------------

--
-- Table structure for table `peg_modifiers`
--

CREATE TABLE `peg_modifiers` (
  `id` int(11) NOT NULL,
  `config_id` int(11) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `peg_modifiers`
--

INSERT INTO `peg_modifiers` (`id`, `config_id`, `label`, `amount`) VALUES
(2, 29, 'Modifier 1', '0.00');

-- --------------------------------------------------------

--
-- Table structure for table `peg_points`
--

CREATE TABLE `peg_points` (
  `id` int(11) NOT NULL,
  `config_id` int(11) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `channel` varchar(100) DEFAULT NULL,
  `url` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `weight` decimal(6,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `peg_points`
--

INSERT INTO `peg_points` (`id`, `config_id`, `label`, `channel`, `url`, `price`, `weight`, `created_at`) VALUES
(30, 29, 'Point 1', '', '', '100.00', '0.1000', '2025-12-17 23:08:34');

-- --------------------------------------------------------

--
-- Table structure for table `peg_point_history`
--

CREATE TABLE `peg_point_history` (
  `id` int(11) NOT NULL,
  `peg_point_id` int(11) NOT NULL,
  `day_date` date NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `peg_point_history`
--

INSERT INTO `peg_point_history` (`id`, `peg_point_id`, `day_date`, `price`, `created_at`) VALUES
(76, 30, '2025-12-18', '100.00', '2025-12-17 23:08:34');

-- --------------------------------------------------------

--
-- Table structure for table `sales_data`
--

CREATE TABLE `sales_data` (
  `id` int(11) NOT NULL,
  `config_id` int(11) NOT NULL,
  `capacity` varchar(50) NOT NULL,
  `day_label` varchar(20) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `market_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `volume` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_data`
--

INSERT INTO `sales_data` (`id`, `config_id`, `capacity`, `day_label`, `sale_price`, `market_price`, `volume`) VALUES
(484, 29, '20TB', 'Mon', '0.00', '0.00', 0),
(485, 29, '20TB', 'Tue', '0.00', '0.00', 0),
(486, 29, '20TB', 'Wed', '0.00', '0.00', 0),
(487, 29, '20TB', 'Thu', '0.00', '0.00', 0),
(488, 29, '20TB', 'Fri', '0.00', '0.00', 0),
(489, 29, '20TB', 'Sat', '0.00', '0.00', 0),
(490, 29, '20TB', 'Sun', '0.00', '0.00', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `capacities`
--
ALTER TABLE `capacities`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `peg_configs`
--
ALTER TABLE `peg_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cfg` (`capacity`,`interface`,`condition_type`);

--
-- Indexes for table `peg_history`
--
ALTER TABLE `peg_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_peg_history_config` (`config_id`);

--
-- Indexes for table `peg_modifiers`
--
ALTER TABLE `peg_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mod_cfg` (`config_id`);

--
-- Indexes for table `peg_points`
--
ALTER TABLE `peg_points`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_peg_points_config` (`config_id`);

--
-- Indexes for table `peg_point_history`
--
ALTER TABLE `peg_point_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_point_day_price` (`peg_point_id`,`day_date`,`price`);

--
-- Indexes for table `sales_data`
--
ALTER TABLE `sales_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sales_cfg` (`config_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `capacities`
--
ALTER TABLE `capacities`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `peg_configs`
--
ALTER TABLE `peg_configs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `peg_history`
--
ALTER TABLE `peg_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `peg_modifiers`
--
ALTER TABLE `peg_modifiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `peg_points`
--
ALTER TABLE `peg_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `peg_point_history`
--
ALTER TABLE `peg_point_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `sales_data`
--
ALTER TABLE `sales_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=491;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `peg_history`
--
ALTER TABLE `peg_history`
  ADD CONSTRAINT `fk_hist_cfg` FOREIGN KEY (`config_id`) REFERENCES `peg_configs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `peg_modifiers`
--
ALTER TABLE `peg_modifiers`
  ADD CONSTRAINT `fk_mod_cfg` FOREIGN KEY (`config_id`) REFERENCES `peg_configs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `peg_points`
--
ALTER TABLE `peg_points`
  ADD CONSTRAINT `fk_peg_points_config` FOREIGN KEY (`config_id`) REFERENCES `peg_configs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `peg_point_history`
--
ALTER TABLE `peg_point_history`
  ADD CONSTRAINT `fk_pph_point` FOREIGN KEY (`peg_point_id`) REFERENCES `peg_points` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_data`
--
ALTER TABLE `sales_data`
  ADD CONSTRAINT `fk_sales_cfg` FOREIGN KEY (`config_id`) REFERENCES `peg_configs` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
