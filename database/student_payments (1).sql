-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 09, 2026 at 02:07 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `institute_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `student_payments`
--

CREATE TABLE `student_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `institute_id` int(11) NOT NULL,
  `receipt_no` int(10) UNSIGNED NOT NULL,
  `student_id` int(11) NOT NULL,
  `student_name` varchar(160) NOT NULL,
  `grade` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `paid_at` date NOT NULL,
  `method` varchar(20) NOT NULL DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `recorded_by_role` varchar(20) NOT NULL,
  `recorded_by_id` int(11) NOT NULL,
  `recorded_by_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `student_payments`
--
ALTER TABLE `student_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_receipt` (`institute_id`,`receipt_no`),
  ADD KEY `idx_student_grade` (`student_id`,`grade`),
  ADD KEY `idx_institute_date` (`institute_id`,`paid_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `student_payments`
--
ALTER TABLE `student_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
