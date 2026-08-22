-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 11, 2026 at 06:39 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dcvs_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(133, 5, 'submit_report', 'Submitted Stray report #23', '::1', '2026-07-03 02:29:24'),
(134, 13, 'Report Validated & Forwarded', 'Report #23 validated and forwarded to DCVS.', '::1', '2026-07-03 02:29:32'),
(135, 14, 'Appointment Updated', 'Updated appointment #APT-0011 to status: scheduled.', '::1', '2026-07-03 02:31:33'),
(136, 5, 'Adoption Appointment', 'Requested adoption appointment for pet ID: 30', NULL, '2026-07-03 03:04:20'),
(137, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-03 03:17:02'),
(138, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-03 06:28:30'),
(139, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-03 06:28:47'),
(140, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-03 07:30:14'),
(141, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-03 12:31:49'),
(142, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-03 12:32:06'),
(143, 5, 'submit_report', 'Submitted Rabies report #24', '::1', '2026-07-03 13:50:50'),
(144, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-04 03:49:30'),
(145, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-04 05:36:22'),
(146, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-04 15:05:59'),
(147, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-04 15:09:01'),
(148, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-05 04:07:11'),
(149, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-05 04:42:51'),
(150, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-05 06:12:15'),
(151, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-05 06:21:11'),
(152, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-06 03:05:00'),
(153, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-06 03:13:57'),
(154, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-06 04:41:47'),
(155, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-06 05:14:39'),
(156, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-06 12:28:13'),
(157, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-06 12:57:14'),
(158, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-06 13:08:54'),
(159, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-07 02:22:14'),
(160, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-07 02:35:48'),
(161, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-07 02:41:28'),
(162, 14, 'Appointment Updated', 'Updated appointment #APT-26SCRM to status: scheduled.', '::1', '2026-07-07 03:22:11'),
(163, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-07 03:22:20'),
(164, 14, 'Appointment Updated', 'Updated appointment #APT-VP35G5 to status: scheduled.', '::1', '2026-07-07 03:22:44'),
(165, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-07 06:28:15'),
(166, 14, 'Case Confirmed & Scheduled', 'Report #23 confirmed and scheduled on July 9, 2026 at 2:28 PM.', '::1', '2026-07-07 06:28:53'),
(167, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-07 06:40:21'),
(168, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-07 06:52:20'),
(169, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-07 09:49:56'),
(170, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-07 09:55:09'),
(171, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-07 10:20:15'),
(172, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-08 03:29:08'),
(173, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-08 03:29:33'),
(174, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-08 03:37:44'),
(175, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-08 12:48:17'),
(176, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-08 12:49:51'),
(177, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-08 12:50:37'),
(178, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 02:42:46'),
(179, 16, 'submit_report', 'Submitted Stray report #25', '::1', '2026-07-09 02:43:53'),
(180, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-09 02:44:24'),
(182, 18, 'Account Created', 'Barangay account registered.', '::1', '2026-07-09 02:58:01'),
(183, 18, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-09 02:58:09'),
(184, 18, 'Report Validated & Forwarded', 'Report #25 validated and forwarded to DCVS.', '::1', '2026-07-09 02:58:33'),
(185, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 02:58:48'),
(186, 14, 'Case Confirmed & Scheduled', 'Report #25 confirmed and scheduled on July 16, 2026 at 10:00 AM.', '::1', '2026-07-09 02:59:34'),
(187, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-09 03:03:46'),
(188, 13, 'Report Validated & Forwarded', 'Report #24 validated and forwarded to DCVS.', '::1', '2026-07-09 03:05:19'),
(189, 14, 'Case Confirmed & Scheduled', 'Report #24 confirmed and scheduled on July 9, 2026 at 1:05 PM.', '::1', '2026-07-09 03:05:41'),
(190, 16, 'submit_report', 'Submitted Dead report #26', '::1', '2026-07-09 03:14:43'),
(191, 18, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-09 03:14:54'),
(192, 18, 'Report Validated & Forwarded', 'Report #26 validated and forwarded to DCVS.', '::1', '2026-07-09 03:14:59'),
(193, 14, 'Case Confirmed & Scheduled', 'Report #26 confirmed and scheduled on July 16, 2026 at 11:15 AM.', '::1', '2026-07-09 03:15:21'),
(194, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 03:58:19'),
(195, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 04:30:48'),
(196, 19, 'submit_report', 'Submitted Injured report #27', '::1', '2026-07-09 04:56:09'),
(197, 20, 'Account Created', 'Barangay account registered.', '::1', '2026-07-09 04:57:22'),
(198, 20, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-09 04:57:30'),
(199, 20, 'Report Validated & Forwarded', 'Report #27 validated and forwarded to DCVS.', '::1', '2026-07-09 04:57:38'),
(200, 14, 'Case Confirmed & Scheduled', 'Report #27 confirmed and scheduled on September 23, 2026 at 12:59 PM.', '::1', '2026-07-09 04:58:03'),
(201, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 05:41:33'),
(202, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 05:42:48'),
(203, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 05:45:13'),
(204, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 05:55:29'),
(205, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-09 10:56:25'),
(206, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-09 11:19:24'),
(207, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-09 11:23:32'),
(208, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-10 02:49:46'),
(209, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-10 02:52:23'),
(210, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-10 02:56:29'),
(211, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-10 02:56:43'),
(212, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-10 03:21:16'),
(213, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-10 04:28:48'),
(214, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-11 02:54:50'),
(215, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-11 04:08:55'),
(216, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-11 11:45:04'),
(217, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-11 11:56:39'),
(218, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-11 11:58:18'),
(219, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-11 11:59:51'),
(220, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-11 13:41:54'),
(221, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-12 04:21:48'),
(222, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-12 04:23:26'),
(223, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-12 04:23:43'),
(224, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-12 06:06:56'),
(225, 15, 'Role Changed', 'Changed role of \'DCVS Dasmarinas\' from staff to admin', '::1', '2026-07-12 08:18:28'),
(226, 15, 'Role Changed', 'Changed role of \'DCVS Dasmarinas\' from admin to staff', '::1', '2026-07-12 08:22:39'),
(227, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-12 08:22:45'),
(228, 15, 'User Deactivated', 'Deactivated user \'Cahrlie Mhac Sixta\'', '::1', '2026-07-12 08:43:21'),
(229, 15, 'User Activated', 'Activated user \'Cahrlie Mhac Sixta\'', '::1', '2026-07-12 08:44:11'),
(230, 15, 'User Archived', 'Archived user \'Cahrlie Mhac Sixta\'', '::1', '2026-07-12 09:20:26'),
(231, 15, 'User Restored', 'Restored user \'Cahrlie Mhac Sixta\'', '::1', '2026-07-12 09:20:36'),
(232, 14, 'Case Resolved', 'Case #2 resolved successfully.', '::1', '2026-07-12 09:26:51'),
(233, 14, 'Case Resolved', 'Case #4 resolved successfully.', '::1', '2026-07-12 09:51:31'),
(234, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-12 09:57:48'),
(235, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-13 00:11:38'),
(236, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-13 00:12:51'),
(237, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-13 00:13:34'),
(238, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-13 00:23:03'),
(239, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-13 10:58:12'),
(240, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-13 10:58:58'),
(241, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-13 11:08:11'),
(242, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-14 08:39:57'),
(243, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-14 08:44:55'),
(244, 18, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-14 08:46:52'),
(245, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-14 08:48:41'),
(246, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-14 11:39:42'),
(247, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-14 11:47:34'),
(248, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-14 12:39:29'),
(249, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-15 00:26:00'),
(250, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-15 00:39:59'),
(251, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-15 12:24:08'),
(252, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-15 13:33:54'),
(253, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-16 11:27:24'),
(254, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-16 11:42:00'),
(255, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-16 11:48:39'),
(256, 5, 'Logout', 'Resident logged out', '::1', '2026-07-16 12:23:12'),
(257, 5, 'Logout', 'Resident logged out', '::1', '2026-07-16 12:27:53'),
(258, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-16 12:28:05'),
(259, 13, 'Logout', 'Barangay logged out', '::1', '2026-07-16 13:26:18'),
(260, 15, 'Logout', 'Admin logged out', '::1', '2026-07-16 13:26:22'),
(261, 5, 'Logout', 'Resident logged out', '::1', '2026-07-16 13:26:27'),
(262, 14, 'Logout', 'Staff logged out', '::1', '2026-07-16 13:44:37'),
(263, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-17 02:00:46'),
(264, 5, 'Logout', 'Resident logged out', '::1', '2026-07-17 02:02:18'),
(265, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-17 02:02:30'),
(266, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-17 02:03:43'),
(267, 5, 'submit_report', 'Submitted Stray report #28', '::1', '2026-07-17 02:33:05'),
(268, 5, 'submit_report', 'Submitted Rabies report #29', '::1', '2026-07-17 02:34:39'),
(269, 5, 'submit_report', 'Submitted Stray report #30', '::1', '2026-07-17 02:35:09'),
(270, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-17 02:39:44'),
(271, 14, 'Logout', 'Staff logged out', '::1', '2026-07-17 05:45:27'),
(272, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-17 05:45:43'),
(273, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-17 07:06:46'),
(274, 13, 'Logout', 'Barangay logged out', '::1', '2026-07-17 07:09:32'),
(275, 14, 'Logout', 'Staff logged out', '::1', '2026-07-17 07:10:59'),
(276, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-17 07:14:48'),
(277, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-17 11:59:39'),
(278, 14, 'Logout', 'Staff logged out', '::1', '2026-07-17 13:07:25'),
(279, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-17 13:08:52'),
(280, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-17 15:03:53'),
(281, 5, 'Logout', 'Resident logged out', '::1', '2026-07-17 15:12:52'),
(282, 14, 'Logout', 'Staff logged out', '::1', '2026-07-17 15:12:55'),
(283, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-17 15:13:11'),
(284, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-18 01:28:07'),
(285, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-18 01:32:49'),
(286, 14, 'Logout', 'Staff logged out', '::1', '2026-07-18 02:13:08'),
(287, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-18 02:13:13'),
(288, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-18 02:57:20'),
(289, 14, 'Logout', 'Staff logged out', '::1', '2026-07-18 03:06:44'),
(290, 13, 'Logout', 'Barangay logged out', '::1', '2026-07-18 03:06:51'),
(291, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-18 04:19:45'),
(292, 14, 'Logout', 'Staff logged out', '::1', '2026-07-18 04:20:31'),
(293, 5, 'Logout', 'Resident logged out', '::1', '2026-07-18 04:20:36'),
(294, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-18 10:04:51'),
(295, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-18 10:25:33'),
(296, 14, 'Logout', 'Staff logged out', '::1', '2026-07-18 12:34:45'),
(297, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-18 13:19:01'),
(298, 14, 'Logout', 'Staff logged out', '::1', '2026-07-18 13:32:47'),
(299, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-19 04:32:14'),
(300, 5, 'Logout', 'Resident logged out', '::1', '2026-07-19 07:24:30'),
(301, 14, 'Logout', 'Staff logged out', '::1', '2026-07-19 07:25:31'),
(302, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-20 00:22:12'),
(303, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-20 00:26:54'),
(304, 5, 'Logout', 'Resident logged out', '::1', '2026-07-20 01:52:47'),
(305, 14, 'Logout', 'Staff logged out', '::1', '2026-07-20 01:52:54'),
(306, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-20 09:38:23'),
(307, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-20 09:38:48'),
(308, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-20 09:42:00'),
(309, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-20 10:18:01'),
(310, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-20 10:18:26'),
(311, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-20 10:18:34'),
(312, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-20 10:18:44'),
(313, 15, 'User Created', 'Admin created user \'Test User 1784571524\' (testuser1784571524@example.com) as resident', '::1', '2026-07-20 10:18:44'),
(314, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-20 10:18:53'),
(315, 15, 'User Created', 'Admin created user \'Login Test 1784571534\' (logintest1784571534@example.com) as resident', '::1', '2026-07-20 10:18:53'),
(317, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-20 10:20:00'),
(318, 15, 'User Created', 'Admin created user \'Null Test 1784571601\' (nulltest1784571601@example.com) as resident', '::1', '2026-07-20 10:20:01'),
(319, 5, 'Logout', 'Resident logged out', '::1', '2026-07-20 13:04:43'),
(320, 14, 'Logout', 'Staff logged out', '::1', '2026-07-20 13:04:47'),
(321, 13, 'Logout', 'Barangay logged out', '::1', '2026-07-20 13:04:51'),
(322, 15, 'Logout', 'Admin logged out', '::1', '2026-07-20 13:04:54'),
(323, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-22 01:12:32'),
(324, 14, 'Appointment Updated', 'Updated appointment #APT-0013 to status: scheduled.', '::1', '2026-07-22 01:14:28'),
(325, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-23 14:42:40'),
(326, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-23 15:29:23'),
(327, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-23 15:33:21'),
(328, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-23 15:33:51'),
(329, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-23 15:37:51'),
(330, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-23 15:38:23'),
(331, 5, 'Pet Registered', 'Registered pet: Robin Padilla (dog, male)', '::1', '2026-07-23 15:39:27'),
(332, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-07-23 16:36:41'),
(333, 14, 'Logout', 'Staff logged out', '::1', '2026-07-23 16:43:11'),
(334, 13, 'Logout', 'Barangay logged out', '::1', '2026-07-23 16:43:13'),
(335, 5, 'Logout', 'Resident logged out', '::1', '2026-07-23 16:43:15'),
(336, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-24 02:20:19'),
(337, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-24 02:29:34'),
(338, 14, 'Logout', 'Staff logged out', '::1', '2026-07-24 02:33:08'),
(339, 5, 'Logout', 'Resident logged out', '::1', '2026-07-24 02:33:12'),
(340, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-24 02:37:28'),
(341, 5, 'Logout', 'Resident logged out', '::1', '2026-07-24 02:40:43'),
(342, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-24 02:54:52'),
(343, 5, 'Logout', 'Resident logged out', '::1', '2026-07-24 02:54:54'),
(344, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-24 02:55:46'),
(345, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-24 03:05:54'),
(346, 14, 'Logout', 'Staff logged out', '::1', '2026-07-24 03:28:03'),
(347, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-25 03:18:42'),
(348, 5, 'Logout', 'Resident logged out', '::1', '2026-07-25 06:06:06'),
(349, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-26 06:55:23'),
(350, 5, 'Logout', 'Resident logged out', '::1', '2026-07-26 06:55:36'),
(351, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:23:17'),
(352, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:25:18'),
(353, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:25:41'),
(354, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:35:04'),
(355, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:38:00'),
(356, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:38:10'),
(357, 5, 'Logout', 'Resident logged out', '::1', '2026-07-29 17:38:12'),
(358, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:38:22'),
(359, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:39:41'),
(360, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-29 17:40:12'),
(361, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:40:36'),
(362, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-29 17:45:57'),
(363, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-07-29 17:47:02'),
(364, 14, 'Logout', 'Staff logged out', '::1', '2026-07-29 17:47:18'),
(365, 15, 'Logout', 'Admin logged out', '::1', '2026-07-29 17:47:42'),
(366, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-29 17:49:57'),
(367, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-29 17:52:47'),
(368, 14, 'Logout', 'Staff logged out', '::1', '2026-07-29 17:53:53'),
(369, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-07-30 07:11:22'),
(370, 14, 'Logout', 'Staff logged out', '::1', '2026-07-30 08:11:41'),
(371, 5, 'Logout', 'Resident logged out', '::1', '2026-07-30 08:11:44'),
(372, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-31 13:32:37'),
(373, 5, 'Logout', 'Resident logged out', '::1', '2026-07-31 13:32:46'),
(374, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-07-31 13:32:54'),
(375, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-05 02:35:05'),
(376, 14, 'Logout', 'Staff logged out', '::1', '2026-08-05 04:32:05'),
(377, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-05 06:50:03'),
(378, 5, 'Logout', 'Resident logged out', '::1', '2026-08-05 06:52:02'),
(379, 14, 'Logout', 'Staff logged out', '::1', '2026-08-05 13:32:54'),
(380, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-08-05 13:33:30'),
(381, 5, 'Logout', 'Resident logged out', '::1', '2026-08-05 13:42:46'),
(382, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-07 02:55:29'),
(383, 14, 'Logout', 'Staff logged out', '::1', '2026-08-07 02:59:29'),
(384, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-07 04:48:40'),
(385, 14, 'Logout', 'Staff logged out', '::1', '2026-08-07 06:02:14'),
(386, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-08-10 10:33:46'),
(387, 5, 'Logout', 'Resident logged out', '::1', '2026-08-10 10:33:54'),
(388, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-10 12:51:29'),
(389, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-08-10 13:25:13'),
(390, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-10 14:28:13'),
(391, 14, 'Case Resolved', 'Case #3 resolved successfully.', '::1', '2026-08-10 15:40:28'),
(392, 14, 'Case Resolved', 'Case #6 resolved successfully.', '::1', '2026-08-10 15:48:30'),
(397, 5, 'Adoption Appointment', 'Requested adoption appointment for pet ID: 29', NULL, '2026-08-10 17:04:19'),
(398, 14, 'Appointment Updated', 'Updated appointment #APT-BT4JHX to status: scheduled.', '::1', '2026-08-10 17:14:29'),
(399, 13, 'Barangay Login', 'Barangay officer logged into portal.', '::1', '2026-08-10 17:27:22'),
(400, 13, 'Logout', 'Barangay logged out', '::1', '2026-08-10 17:53:25'),
(401, 5, 'Logout', 'Resident logged out', '::1', '2026-08-10 17:54:35'),
(402, 14, 'Logout', 'Staff logged out', '::1', '2026-08-10 17:54:38'),
(403, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-11 05:37:59'),
(404, 14, 'Logout', 'Staff logged out', '::1', '2026-08-11 05:39:19'),
(405, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-08-11 07:05:47'),
(406, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-11 07:05:55'),
(407, 15, 'Admin Login', 'Administrator logged into the system.', '::1', '2026-08-11 07:23:09'),
(408, 15, 'Logout', 'Admin logged out', '::1', '2026-08-11 07:23:44'),
(409, 14, 'Logout', 'Staff logged out', '::1', '2026-08-11 08:10:08'),
(410, 5, 'Logout', 'Resident logged out', '::1', '2026-08-11 08:10:11'),
(411, 5, 'Resident Login', 'Resident logged into the system.', '::1', '2026-08-11 10:23:35'),
(412, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-11 10:23:48'),
(413, 14, 'Logout', 'Staff logged out', '::1', '2026-08-11 10:31:07'),
(414, 14, 'Staff Login', 'Staff member logged into portal.', '::1', '2026-08-11 10:31:13');

-- --------------------------------------------------------

--
-- Table structure for table `adoption_applications`
--

CREATE TABLE `adoption_applications` (
  `application_id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `application_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `interview_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adoption_listings`
--

CREATE TABLE `adoption_listings` (
  `listing_id` int(11) NOT NULL,
  `pet_id` int(11) NOT NULL,
  `listed_by` int(11) NOT NULL,
  `source_listing_id` int(11) DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `adoption_fee` decimal(10,2) DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `status` enum('available','pending','adopted','withdrawn') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `adoption_listings`
--

INSERT INTO `adoption_listings` (`listing_id`, `pet_id`, `listed_by`, `source_listing_id`, `title`, `description`, `adoption_fee`, `requirements`, `status`, `created_at`, `updated_at`) VALUES
(4, 30, 5, NULL, 'Horsey', NULL, NULL, NULL, 'pending', '2026-07-03 03:04:05', '2026-07-03 03:04:20');

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `alert_id` int(11) NOT NULL,
  `alert_type` enum('rabies_case','stray_cluster','vaccination_reminder','emergency') NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `location_address` text DEFAULT NULL,
  `coordinates_lat` decimal(10,8) DEFAULT NULL,
  `coordinates_long` decimal(11,8) DEFAULT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `severity` enum('info','warning','critical') DEFAULT 'warning',
  `affected_radius_km` decimal(5,2) DEFAULT 1.00,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `category` enum('advisory','announcement') NOT NULL DEFAULT 'announcement',
  `announcement_type` enum('general','alert','rabies_outbreak','vaccination_drive') DEFAULT 'general',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `photo_url` varchar(255) DEFAULT NULL,
  `photo_hover_url` varchar(255) DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expiry_date` date DEFAULT NULL,
  `status` enum('draft','published') DEFAULT 'published',
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`announcement_id`, `title`, `content`, `category`, `announcement_type`, `priority`, `photo_url`, `photo_hover_url`, `link_url`, `barangay_id`, `created_by`, `created_at`, `updated_at`, `expiry_date`, `status`, `is_active`) VALUES
(4, 'free kapon', 'SANA MATAPOS NA TONG SYSTEM', 'announcement', 'general', 'medium', 'uploads/announcements/ann_photo_6a7afa8632cb1.jpg', NULL, '', NULL, 14, '2026-07-03 02:32:21', '2026-08-11 10:33:42', NULL, 'published', 1),
(5, 'FREE RABIES', 'DEPENDE KUNG 3 YAN', 'advisory', 'general', 'medium', 'uploads/announcements/ann_photo_6a7afa6d1570b.jpg', 'uploads/announcements/ann_hover_6a623cee009b3.jpg', '', NULL, 14, '2026-07-23 16:10:22', '2026-08-11 10:33:17', NULL, 'published', 1),
(6, 'OPLAN LIBRE SOPAS CHAMPORADO HAMONADO BULALO', 'mainit na sopas champorado hamonado bulalo sa bahay namin', 'announcement', 'general', 'medium', 'uploads/announcements/ann_photo_6a7af9b9ee2ec.jpg', NULL, 'https://www.facebook.com/ijustwannarolirolirprkrkfown', NULL, 14, '2026-08-11 10:30:17', '2026-08-11 10:30:17', NULL, 'published', 1);

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `reference_id` varchar(20) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `appointment_type` enum('adoption','kapon','vaccination','checkup') NOT NULL,
  `pet_id` int(11) DEFAULT NULL,
  `pet_name` varchar(100) DEFAULT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` time NOT NULL,
  `status` enum('pending','scheduled','completed','cancelled','no_show','rescheduled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `reference_id`, `user_id`, `appointment_type`, `pet_id`, `pet_name`, `preferred_date`, `preferred_time`, `status`, `notes`, `created_at`, `updated_at`, `archived_at`) VALUES
(11, '#APT-26SCRM', 5, 'kapon', 29, 'julya', '2026-07-10', '10:00:00', 'scheduled', '', '2026-07-03 02:30:47', '2026-08-10 17:03:12', NULL),
(12, '#APT-VP35G5', 5, 'adoption', 30, 'Horsey', '2026-07-03', '11:00:00', 'scheduled', '', '2026-07-03 03:04:20', '2026-08-10 17:03:12', NULL),
(13, '#APT-VNFFWY', 5, 'kapon', 30, 'Horsey', '2026-07-27', '14:00:00', 'scheduled', 'hehe', '2026-07-12 09:01:12', '2026-08-10 17:03:12', NULL),
(19, '#APT-BT4JHX', 5, 'adoption', 29, NULL, '2026-08-26', '14:00:00', 'scheduled', 'try lang ites', '2026-08-10 17:04:19', '2026-08-10 17:14:23', NULL),
(20, '#APT-PJ2B4A', 5, 'kapon', 31, NULL, '2026-08-16', '10:00:00', 'pending', 'para magtino yung utak', '2026-08-10 17:16:31', '2026-08-10 17:16:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `barangay_id` int(11) NOT NULL,
  `barangay_name` varchar(100) NOT NULL,
  `district` varchar(100) DEFAULT NULL,
  `coordinates_lat` decimal(10,8) DEFAULT NULL,
  `coordinates_long` decimal(11,8) DEFAULT NULL,
  `boundary_polygon` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`barangay_id`, `barangay_name`, `district`, `coordinates_lat`, `coordinates_long`, `boundary_polygon`) VALUES
(1, 'Burol', 'Dasmari±as', NULL, NULL, NULL),
(2, 'Burol I', 'Dasmari±as', NULL, NULL, NULL),
(3, 'Burol II', 'Dasmari±as', NULL, NULL, NULL),
(4, 'Burol III', 'Dasmari±as', NULL, NULL, NULL),
(5, 'Datu Esmael', 'Dasmari±as', NULL, NULL, NULL),
(6, 'Emmanuel Pelaez', 'Dasmari±as', NULL, NULL, NULL),
(7, 'Fatima I', 'Dasmari±as', NULL, NULL, NULL),
(8, 'Fatima II', 'Dasmari±as', NULL, NULL, NULL),
(9, 'Fatima III', 'Dasmari±as', NULL, NULL, NULL),
(10, 'H-95', 'Dasmari±as', NULL, NULL, NULL),
(11, 'Langkaan I', 'Dasmari±as', NULL, NULL, NULL),
(12, 'Langkaan II', 'Dasmari±as', NULL, NULL, NULL),
(13, 'Lumina I', 'Dasmari±as', NULL, NULL, NULL),
(14, 'Lumina II', 'Dasmari±as', NULL, NULL, NULL),
(15, 'Magdalo', 'Dasmari±as', NULL, NULL, NULL),
(16, 'Maliksi I', 'Dasmari±as', NULL, NULL, NULL),
(17, 'Maliksi II', 'Dasmari±as', NULL, NULL, NULL),
(18, 'Maliksi III', 'Dasmari±as', NULL, NULL, NULL),
(19, 'Maunlad I', 'Dasmari±as', NULL, NULL, NULL),
(20, 'Maunlad II', 'Dasmari±as', NULL, NULL, NULL),
(21, 'Maunlad III', 'Dasmari±as', NULL, NULL, NULL),
(22, 'Paliparan I', 'Dasmari±as', NULL, NULL, NULL),
(23, 'Paliparan II', 'Dasmari±as', NULL, NULL, NULL),
(24, 'Paliparan III', 'Dasmari±as', NULL, NULL, NULL),
(25, 'Pasong Camachile I', 'Dasmari±as', NULL, NULL, NULL),
(26, 'Pasong Camachile II', 'Dasmari±as', NULL, NULL, NULL),
(27, 'Pasong Kawayan I', 'Dasmari±as', NULL, NULL, NULL),
(28, 'Pasong Kawayan II', 'Dasmari±as', NULL, NULL, NULL),
(29, 'Sabang', 'Dasmari±as', NULL, NULL, NULL),
(30, 'Salawag', 'Dasmari±as', NULL, NULL, NULL),
(31, 'Salitran I', 'Dasmari±as', NULL, NULL, NULL),
(32, 'Salitran II', 'Dasmari±as', NULL, NULL, NULL),
(33, 'Salitran III', 'Dasmari±as', NULL, NULL, NULL),
(34, 'Salitran IV', 'Dasmari±as', NULL, NULL, NULL),
(35, 'Sampaloc I', 'Dasmari±as', NULL, NULL, NULL),
(36, 'Sampaloc II', 'Dasmari±as', NULL, NULL, NULL),
(37, 'Sampaloc III', 'Dasmari±as', NULL, NULL, NULL),
(38, 'Sampaloc IV', 'Dasmari±as', NULL, NULL, NULL),
(39, 'San Agustin I', 'Dasmari±as', NULL, NULL, NULL),
(40, 'San Agustin II', 'Dasmari±as', NULL, NULL, NULL),
(41, 'San Agustin III', 'Dasmari±as', NULL, NULL, NULL),
(42, 'San Andres I', 'Dasmari±as', NULL, NULL, NULL),
(43, 'San Andres II', 'Dasmari±as', NULL, NULL, NULL),
(44, 'San Isidro Labrador I', 'Dasmari±as', NULL, NULL, NULL),
(45, 'San Isidro Labrador II', 'Dasmari±as', NULL, NULL, NULL),
(46, 'San Jose', 'Dasmari±as', NULL, NULL, NULL),
(47, 'San Juan I', 'Dasmari±as', NULL, NULL, NULL),
(48, 'San Juan II', 'Dasmari±as', NULL, NULL, NULL),
(49, 'San Lorenzo Ruiz I', 'Dasmari±as', NULL, NULL, NULL),
(50, 'San Lorenzo Ruiz II', 'Dasmari±as', NULL, NULL, NULL),
(51, 'San Miguel', 'Dasmari±as', NULL, NULL, NULL),
(52, 'San Simon', 'Dasmari±as', NULL, NULL, NULL),
(53, 'Santa Cruz I', 'Dasmari±as', NULL, NULL, NULL),
(54, 'Santa Cruz II', 'Dasmari±as', NULL, NULL, NULL),
(55, 'Santa Fe I', 'Dasmari±as', NULL, NULL, NULL),
(56, 'Santa Fe II', 'Dasmari±as', NULL, NULL, NULL),
(57, 'Santo Cristo I', 'Dasmari±as', NULL, NULL, NULL),
(58, 'Santo Cristo II', 'Dasmari±as', NULL, NULL, NULL),
(59, 'Sto. Ni±o I', 'Dasmari±as', NULL, NULL, NULL),
(60, 'Sto. Ni±o II', 'Dasmari±as', NULL, NULL, NULL),
(61, 'Victoria I', 'Dasmari±as', NULL, NULL, NULL),
(62, 'Victoria II', 'Dasmari±as', NULL, NULL, NULL),
(63, 'Zone I (Pob.)', 'Dasmari±as', NULL, NULL, NULL),
(64, 'Zone II (Pob.)', 'Dasmari±as', NULL, NULL, NULL),
(65, 'Zone III (Pob.)', 'Dasmari±as', NULL, NULL, NULL),
(66, 'Zone IV (Pob.)', 'Dasmari±as', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `confirmed_cases`
--

CREATE TABLE `confirmed_cases` (
  `case_id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `case_type` enum('stray','rabies','injured','dead') NOT NULL,
  `location_address` text NOT NULL,
  `coordinates_lat` decimal(10,8) DEFAULT NULL,
  `coordinates_long` decimal(11,8) DEFAULT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `urgency_level` enum('low','medium','high','critical') DEFAULT NULL,
  `status` enum('active','resolved','monitoring') DEFAULT 'active',
  `confirmed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `confirmed_cases`
--

INSERT INTO `confirmed_cases` (`case_id`, `report_id`, `case_type`, `location_address`, `coordinates_lat`, `coordinates_long`, `barangay_id`, `urgency_level`, `status`, `confirmed_at`, `resolved_at`, `notes`) VALUES
(2, 23, 'stray', 'sa kabila', NULL, NULL, 22, 'medium', 'resolved', '2026-07-07 06:28:47', '2026-07-12 09:26:46', 'RESCUED'),
(3, 25, 'stray', 'sa may 7/11', NULL, NULL, 49, 'medium', 'resolved', '2026-07-09 02:59:30', '2026-08-10 15:40:22', 'TRY LANG ITO'),
(4, 24, 'rabies', 'sa kabilang kanto', NULL, NULL, 22, 'medium', 'resolved', '2026-07-09 03:05:37', '2026-07-12 09:51:26', 'okay po thanks'),
(5, 26, 'dead', 'sa may ilog', NULL, NULL, 49, 'medium', 'active', '2026-07-09 03:15:17', NULL, NULL),
(6, 27, 'injured', 'sa guevarra', NULL, NULL, 46, 'medium', 'resolved', '2026-07-09 04:57:59', '2026-08-10 15:48:25', 'TRY LANG ITES');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `log_id` int(11) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `subject` varchar(500) NOT NULL,
  `body` text DEFAULT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `error_detail` text DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sent_to_role` varchar(50) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT NULL,
  `portal_delivered` tinyint(1) DEFAULT 0,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_logs`
--

INSERT INTO `email_logs` (`log_id`, `recipient_email`, `recipient_name`, `subject`, `body`, `status`, `error_message`, `error_detail`, `sent_by`, `sent_to_role`, `message_type`, `portal_delivered`, `reference_type`, `reference_id`, `created_at`) VALUES
(1, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Follow-up on Your Report', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from DCVS Staff sent you the following message regarding <strong>Regarding Your Animal Report</strong>:</p>\n                    <div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">come backkk</div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into your DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— DCVS Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'report', 1, NULL, NULL, '2026-07-09 04:25:24'),
(4, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Action Needed on Your Report', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from DCVS Staff sent you the following message regarding <strong>Regarding Your Animal Report</strong>:</p>\n                    <div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">We will come to the given address that you reported. On the way</div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into your DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— DCVS Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'report', 1, NULL, NULL, '2026-07-09 04:35:35'),
(5, 'jcffornal@kld.edu.ph', 'Chloe Fornal', 'DCVS Report Scheduled for Action: RPT-1783572969-19 — Chloe Fornal', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Update on Your Animal Report: Scheduled for Action</h2><p class=\"greeting\">Dear Chloe Fornal,</p><p>Your report <strong>RPT-1783572969-19</strong> has been reviewed and updated by DCVS Dasmarinas (Staff). Here is the latest information:</p><div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:10px 16px;margin:12px 0;\"><table><tr><td>Reference ID</td><td>RPT-1783572969-19</td></tr><tr><td>Resident ID</td><td>#RES-2RTY93</td></tr><tr><td>Report Type</td><td>Injured Animal</td></tr><tr><td>Location</td><td>sa guevarra</td></tr><tr><td>Barangay</td><td>San Jose</td></tr><tr><td>Status</td><td>Scheduled for Action</td></tr><tr><td>Scheduled Action</td><td>September 23, 2026 at 12:59 PM</td></tr></table></div><p>DCVS has scheduled an intervention for <strong>September 23, 2026 at 12:59 PM</strong>. Please stay informed.</p><div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">HI BABY</div><p style=\"margin-top:16px;\">If you have any questions, please contact your barangay office or DCVS directly.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 19, 'resident', NULL, 0, NULL, NULL, '2026-07-09 04:58:03'),
(6, 'jcffornal@kld.edu.ph', 'Chloe Fornal', 'Update on Your Adoption Request', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Chloe Fornal,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from DCVS Staff sent you the following message regarding <strong>Regarding Your Adoption Request</strong>:</p>\n                    <div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">HI BABYYYY</div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into your DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— DCVS Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'adoption', 1, NULL, NULL, '2026-07-09 04:59:06'),
(7, 'mhacsixta@gmail.com', 'Cahrlie Mhac Sixta', 'Appointment Confirmation', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Cahrlie Mhac Sixta,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from DCVS Staff sent you the following message regarding <strong>Regarding Your Appointment</strong>:</p>\n                    <div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">Musta pre?</div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into your DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— DCVS Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'appointment', 1, NULL, NULL, '2026-07-09 10:57:45'),
(8, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Adoption Application Status', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:8px 32px 0;\">\n<div style=\"display:inline-block;max-width:520px;background:#e8f5e9;border-radius:18px 18px 18px 4px;padding:16px 20px;margin:12px 0 6px;font-size:15px;line-height:1.6;color:#1e293b;\">\n  \n                    <p style=\"margin:0 0 6px;font-size:14px;color:#1e293b;line-height:1.55;\">Dear Julia Billones,</p>\n                    <p style=\"margin:0 0 6px;font-size:14px;color:#1e293b;line-height:1.55;\"><strong>DCVS Dasmarinas</strong> from DCVS Staff wrote:</p>\n                    <div style=\"background:#fff;border-radius:12px;padding:12px 16px;margin:10px 0 6px;font-size:14px;line-height:1.55;color:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,0.06);\">WALA LANGGGG</div>\n                    <p style=\"margin:8px 0 0;font-size:13px;color:#64748b;\">Reply by logging into your DCVS resident portal.</p>\n                \n</div>\n</td></tr>\n<tr><td style=\"padding:2px 32px 12px;font-size:12px;color:#94a3b8;\">\n  <p style=\"margin:0;\"><em>— DCVS Staff</em></p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    You can reply to this message by logging into your DCVS resident portal.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'adoption', 1, NULL, NULL, '2026-07-09 11:15:15'),
(9, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Update on Your Adoption Request', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from DCVS Staff sent you the following message regarding <strong>Regarding Your Adoption Request</strong>:</p>\n                    <div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">ano na, tara?</div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into your DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— DCVS Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'adoption', 1, NULL, NULL, '2026-07-12 08:41:38'),
(10, 'mhacsixta@gmail.com', 'Cahrlie Mhac Sixta', 'Account Deactivated — Dasmariñas City Veterinary Services', '<p>Hello <strong>Cahrlie Mhac Sixta</strong>,</p><p>Your DCVS account has been <strong>deactivated</strong> by an administrator.</p><p>You will not be able to log in until your account is reactivated. Please contact the administrator for more information.</p>', 'sent', NULL, NULL, 15, 'resident', 'account_status', 0, NULL, NULL, '2026-07-12 08:43:26'),
(11, 'mhacsixta@gmail.com', 'Cahrlie Mhac Sixta', 'Account Archived — Dasmariñas City Veterinary Services', '<p>Hello <strong>Cahrlie Mhac Sixta</strong>,</p><p>Your DCVS account has been <strong>archived</strong> by an administrator.</p><p>You will not be able to log in until your account is restored. Please contact the administrator for more information.</p>', 'sent', NULL, NULL, 15, 'resident', 'account_status', 0, NULL, NULL, '2026-07-12 09:20:30'),
(12, 'mhacsixta@gmail.com', 'Cahrlie Mhac Sixta', 'Account Restored — Dasmariñas City Veterinary Services', '<p>Hello <strong>Cahrlie Mhac Sixta</strong>,</p><p>Your DCVS account has been <strong>restored</strong> by an administrator. You can now log in again.</p>', 'sent', NULL, NULL, 15, 'resident', 'account_status', 0, NULL, NULL, '2026-07-12 09:20:41'),
(13, 'rolitojr.billones@gmail.com', 'Julia Billones', 'DCVS Report Resolved: RPT-1783045764-5 — Julia Billones', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Update on Your Animal Report: Resolved</h2><p class=\"greeting\">Dear Julia Billones,</p><p>Your report <strong>RPT-1783045764-5</strong> has been reviewed and updated by DCVS Dasmarinas (Staff). Here is the latest information:</p><div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:10px 16px;margin:12px 0;\"><table><tr><td>Reference ID</td><td>RPT-1783045764-5</td></tr><tr><td>Resident ID</td><td>#RES-SKAQMG</td></tr><tr><td>Report Type</td><td>Stray Animal</td></tr><tr><td>Location</td><td>sa kabila</td></tr><tr><td>Barangay</td><td>Paliparan I</td></tr><tr><td>Status</td><td>Resolved</td></tr></table></div><p>Thank you for your patience. This case has been resolved by DCVS.</p><div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">Go to your respective barangay.\n\nRESCUED</div><p style=\"margin-top:16px;\">If you have any questions, please contact your barangay office or DCVS directly.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 5, 'resident', NULL, 0, NULL, NULL, '2026-07-12 09:26:51'),
(14, 'rolitojr.billones@gmail.com', 'Julia Billones', 'DCVS Report Resolved: RPT-1783086650-5 — Julia Billones', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Update on Your Animal Report: Resolved</h2><p class=\"greeting\">Dear Julia Billones,</p><p>Your report <strong>RPT-1783086650-5</strong> has been reviewed and updated by DCVS Dasmarinas (Staff). Here is the latest information:</p><div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:10px 16px;margin:12px 0;\"><table><tr><td>Reference ID</td><td>RPT-1783086650-5</td></tr><tr><td>Resident ID</td><td>#RES-SKAQMG</td></tr><tr><td>Report Type</td><td>Rabies Case</td></tr><tr><td>Location</td><td>sa kabilang kanto</td></tr><tr><td>Barangay</td><td>Paliparan I</td></tr><tr><td>Status</td><td>Resolved</td></tr></table></div><p>Thank you for your patience. This case has been resolved by DCVS.</p><div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">test test try\n\nokay po thanks</div><p style=\"margin-top:16px;\">If you have any questions, please contact your barangay office or DCVS directly.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 5, 'resident', NULL, 0, NULL, NULL, '2026-07-12 09:51:31'),
(15, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Your DCVS Password Reset Code', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Password Reset Request</h2><p>Dear <strong>Julia Billones</strong>,</p><p>We received a request to reset your DCVS account password. Use the verification code below to reset it:</p><div style=\"background:#f0fdf4;border:2px dashed #0E8131;border-radius:8px;padding:20px;text-align:center;margin:20px 0;\"><span style=\"font-size:36px;font-weight:800;letter-spacing:8px;color:#0E8131;font-family:monospace;\">533973</span></div><p>This code will expire in <strong>15 minutes</strong>.</p><p>If you did not request a password reset, please ignore this email.</p><p style=\"margin-top:16px;font-size:13px;color:#94a3b8;\">For security reasons, do not share this code with anyone.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-07-15 01:01:02'),
(16, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Your DCVS Password Reset Code', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Password Reset Request</h2><p>Dear <strong>Julia Billones</strong>,</p><p>We received a request to reset your DCVS account password. Use the verification code below to reset it:</p><div style=\"background:#f0fdf4;border:2px dashed #0E8131;border-radius:8px;padding:20px;text-align:center;margin:20px 0;\"><span style=\"font-size:36px;font-weight:800;letter-spacing:8px;color:#0E8131;font-family:monospace;\">757914</span></div><p>This code will expire in <strong>15 minutes</strong>.</p><p>If you did not request a password reset, please ignore this email.</p><p style=\"margin-top:16px;font-size:13px;color:#94a3b8;\">For security reasons, do not share this code with anyone.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-07-15 01:14:06'),
(17, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Your DCVS Password Reset Code', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Password Reset Request</h2><p>Dear <strong>Julia Billones</strong>,</p><p>We received a request to reset your DCVS account password. Use the verification code below to reset it:</p><div style=\"background:#f0fdf4;border:2px dashed #0E8131;border-radius:8px;padding:20px;text-align:center;margin:20px 0;\"><span style=\"font-size:36px;font-weight:800;letter-spacing:8px;color:#0E8131;font-family:monospace;\">772054</span></div><p>This code will expire in <strong>15 minutes</strong>.</p><p>If you did not request a password reset, please ignore this email.</p><p style=\"margin-top:16px;font-size:13px;color:#94a3b8;\">For security reasons, do not share this code with anyone.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-07-15 01:14:17'),
(18, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Your DCVS Password Reset Code', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Password Reset Request</h2><p>Dear <strong>Julia Billones</strong>,</p><p>We received a request to reset your DCVS account password. Use the verification code below to reset it:</p><div style=\"background:#f0fdf4;border:2px dashed #0E8131;border-radius:8px;padding:20px;text-align:center;margin:20px 0;\"><span style=\"font-size:36px;font-weight:800;letter-spacing:8px;color:#0E8131;font-family:monospace;\">352054</span></div><p>This code will expire in <strong>15 minutes</strong>.</p><p>If you did not request a password reset, please ignore this email.</p><p style=\"margin-top:16px;font-size:13px;color:#94a3b8;\">For security reasons, do not share this code with anyone.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-07-15 01:15:59'),
(19, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Your DCVS Password Reset Code', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Password Reset Request</h2><p>Dear <strong>Julia Billones</strong>,</p><p>We received a request to reset your DCVS account password. Use the verification code below to reset it:</p><div style=\"background:#f0fdf4;border:2px dashed #0E8131;border-radius:8px;padding:20px;text-align:center;margin:20px 0;\"><span style=\"font-size:36px;font-weight:800;letter-spacing:8px;color:#0E8131;font-family:monospace;\">597591</span></div><p>This code will expire in <strong>15 minutes</strong>.</p><p>If you did not request a password reset, please ignore this email.</p><p style=\"margin-top:16px;font-size:13px;color:#94a3b8;\">For security reasons, do not share this code with anyone.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-07-15 01:19:45'),
(20, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Ampunin mo na si leet', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from DCVS Staff sent you the following message regarding <strong>Regarding Your Adoption Request</strong>:</p>\n                    <div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">kawawa naman e</div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into your DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— DCVS Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'adoption', 1, NULL, NULL, '2026-07-19 06:37:42');
INSERT INTO `email_logs` (`log_id`, `recipient_email`, `recipient_name`, `subject`, `body`, `status`, `error_message`, `error_detail`, `sent_by`, `sent_to_role`, `message_type`, `portal_delivered`, `reference_type`, `reference_id`, `created_at`) VALUES
(21, 'rolitojr.billones@gmail.com', 'Julia Billones', 'DCVS Appointment Scheduled: #APT-0013 — Julia Billones', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  <h2>Important: Your Appointment Has Been Scheduled</h2><p class=\"greeting\">Dear Julia Billones,</p><p>The status of your appointment <strong>#APT-0013</strong> has been updated by DCVS Dasmarinas (Staff). Please review the details below.</p><div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:10px 16px;margin:12px 0;\"><table><tr><td>Reference ID</td><td>#APT-0013</td></tr><tr><td>Resident ID</td><td>#RES-SKAQMG</td></tr><tr><td>Service Type</td><td>Kapon (Spay/Neuter)</td></tr><tr><td>Pet</td><td>Horsey</td></tr><tr><td>Date</td><td>July 27, 2026</td></tr><tr><td>Time</td><td>2:00 PM</td></tr><tr><td>Status</td><td>Scheduled</td></tr></table></div><p>Your appointment has been confirmed and scheduled. Please arrive on time.</p><p style=\"margin-top:16px;\">If you have any questions, please contact your barangay office or DCVS directly.</p>\n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 5, 'resident', NULL, 0, NULL, NULL, '2026-07-22 01:14:28'),
(22, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Appointment Reminder', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from DCVS Staff sent you the following message regarding <strong>Regarding Your Appointment</strong>:</p>\n                    <div style=\"background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;border-radius:4px;padding:8px 14px;margin:10px 0;font-size:13px;color:#92400e;\">depende kung 3 yan</div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into your DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— DCVS Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'appointment', 1, NULL, NULL, '2026-07-23 14:43:19'),
(23, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Appointment Rescheduled', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>Regarding Your Appointment</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">tumabi ka nganiii</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'appointment', 1, NULL, NULL, '2026-07-23 14:51:56'),
(24, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Appointment Rescheduled', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td style=\"padding:20px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" align=\"center\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:6px;\">\n<tr><td style=\"text-align:center;padding:20px 32px 10px;\">\n  <p style=\"color:#1e293b;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin:0 0 2px;\">Republic of the Philippines</p>\n  <p style=\"color:#475569;font-size:13px;font-weight:500;margin:0 0 8px;\">City Government of Dasmari&ntilde;as</p>\n  <p style=\"color:#0E8131;font-size:13px;font-weight:700;margin:6px 0 0;letter-spacing:0.5px;\">City Veterinary Services</p>\n  <div style=\"height:3px;background:#0E8131;margin:10px 0 0;border-radius:2px;\"></div>\n</td></tr>\n<tr><td style=\"padding:18px 32px 6px;color:#1e293b;font-size:15px;line-height:1.55;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>Regarding Your Appointment</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">try</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n</td></tr>\n<tr><td style=\"background:#f8fafc;padding:14px 32px 20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;\">\n  <p style=\"margin:0 0 2px;\"><strong style=\"color:#64748b;\">Dasmari&ntilde;as City Veterinary Services</strong></p>\n  <p style=\"margin:0 0 6px;font-size:11px;color:#94a3b8;\">Animal Welfare Management System</p>\n  <p style=\"margin:6px 0 0;\"><a href=\"http://localhost/dcvs\" style=\"color:#0E8131;text-decoration:none;\">http://localhost/dcvs</a></p>\n  <p style=\"margin:8px 0 0;font-size:10px;color:#cbd5e1;\">\n    This is an automated notification from DCVS. Please do not reply directly.<br>\n    &copy; 2026 City Government of Dasmari&ntilde;as. All rights reserved.\n  </p>\n</td></tr>\n</table>\n</td></tr>\n</table>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'appointment', 1, NULL, NULL, '2026-08-05 03:19:08'),
(29, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Appointment Confirmation', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">Regarding Your Animal Report </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>Regarding Your Animal Report</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">weh</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'report', 1, NULL, NULL, '2026-08-10 14:05:34'),
(39, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Update on Your Adoption Request', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">Regarding Your Adoption Request </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>Regarding Your Adoption Request</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">kunin mo na sa office si leet</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'adoption', 1, NULL, NULL, '2026-08-10 15:35:16'),
(41, 'rolitojr.billones@gmail.com', 'Julia Billones', 'I MISS YOU PO', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">General Message </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>General Message</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">I MISS MY LALOVE</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', NULL, NULL, 14, 'resident', 'general', 1, NULL, NULL, '2026-08-10 15:38:22'),
(42, 'mhacsixta@gmail.com', 'Cahrlie Mhac Sixta', 'Stray Animal Report Resolved (RPT-1783565033-16)', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">Report Resolved </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  <p style=\"font-size:15px;color:#1e293b;margin:0 0 8px;\">Dear Cahrlie Mhac Sixta,</p><div style=\"margin-bottom:14px;\"><p style=\"margin:0;font-size:14px;color:#475569;\">Your <strong>Stray Animal</strong> report has been resolved by DCVS. Thank you for your patience.</p></div><div style=\"margin:18px 0 0;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin:10px 0 2px;\"><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Reference ID</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">RPT-1783565033-16</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Resident ID</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">#RES-EKQPDZ</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Report Category</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">Stray Animal</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Location</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">sa may 7/11</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Barangay</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">San Lorenzo Ruiz I</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Current Status</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">Resolved</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Submission Date</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">July 9, 2026</td></tr></table></div><div style=\"margin:20px 0;border-left:3px solid #0e8131;padding:2px 0 2px 20px;\"><div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:10.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8a93ab;margin-bottom:8px;\">Remarks from DCVS</div><div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#1e293b;\">check mo nga pre kung na receive sa email mo<br />\n<br />\nTRY LANG ITO</div></div><p style=\"margin-top:16px;font-size:13px;color:#64748b;\">If you have any questions, please contact your barangay office or DCVS directly.</p>\n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', NULL, NULL, 16, 'resident', NULL, 0, NULL, NULL, '2026-08-10 15:40:28'),
(43, 'jcffornal@kld.edu.ph', 'Chloe Fornal', 'Injured Animal Report Resolved (RPT-1783572969-19)', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">Report Resolved </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  <p style=\"font-size:15px;color:#1e293b;margin:0 0 8px;\">Dear Chloe Fornal,</p><div style=\"margin-bottom:14px;\"><p style=\"margin:0;font-size:14px;color:#475569;\">Your <strong>Injured Animal</strong> report has been resolved by DCVS. Thank you for your patience.</p></div><div style=\"margin:18px 0 0;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin:10px 0 2px;\"><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Reference ID</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">RPT-1783572969-19</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Resident ID</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">#RES-2RTY93</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Report Category</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">Injured Animal</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Location</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">sa guevarra</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Barangay</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">San Jose</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Current Status</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">Resolved</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Submission Date</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">July 19, 2026</td></tr></table></div><div style=\"margin:20px 0;border-left:3px solid #0e8131;padding:2px 0 2px 20px;\"><div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:10.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8a93ab;margin-bottom:8px;\">Remarks from DCVS</div><div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#1e293b;\">HI BABY<br />\n<br />\nTRY LANG ITES</div></div><p style=\"margin-top:16px;font-size:13px;color:#64748b;\">If you have any questions, please contact your barangay office or DCVS directly.</p>\n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', NULL, NULL, 19, 'resident', NULL, 0, NULL, NULL, '2026-08-10 15:48:30'),
(46, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Adoption Appointment Confirmed — julya (#APT-BT4JHX)', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">Appointment Confirmed </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  <p style=\"font-size:15px;color:#1e293b;margin:0 0 8px;\">Dear Julia Billones,</p><div style=\"margin-bottom:14px;\"><p style=\"margin:0;font-size:14px;color:#475569;\">Good news! Your <strong>Adoption</strong> appointment for <strong>julya</strong> has been confirmed and scheduled.</p></div><table cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:collapse;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin:14px 0;\"><tr><td style=\"padding:10px 16px;background:#0E8131;color:#fff;font-size:13px;font-weight:700;letter-spacing:0.3px;\">APPOINTMENT SUMMARY</td></tr><tr style=\"background:#ffffff;\"><td style=\"padding:7px 16px;font-weight:600;color:#475569;border-top:1px solid #eef1f6;font-size:12px;width:40%;white-space:nowrap;\">Pet Name</td><td style=\"padding:7px 16px;color:#1e293b;border-top:1px solid #eef1f6;font-size:13px;\">julya</td></tr><tr style=\"background:#fafcfc;\"><td style=\"padding:7px 16px;font-weight:600;color:#475569;border-top:1px solid #eef1f6;font-size:12px;width:40%;white-space:nowrap;\">Service Type</td><td style=\"padding:7px 16px;color:#1e293b;border-top:1px solid #eef1f6;font-size:13px;\">Adoption</td></tr><tr style=\"background:#ffffff;\"><td style=\"padding:7px 16px;font-weight:600;color:#475569;border-top:1px solid #eef1f6;font-size:12px;width:40%;white-space:nowrap;\">Appointment Date</td><td style=\"padding:7px 16px;color:#1e293b;border-top:1px solid #eef1f6;font-size:13px;\">August 26, 2026</td></tr><tr style=\"background:#fafcfc;\"><td style=\"padding:7px 16px;font-weight:600;color:#475569;border-top:1px solid #eef1f6;font-size:12px;width:40%;white-space:nowrap;\">Appointment Time</td><td style=\"padding:7px 16px;color:#1e293b;border-top:1px solid #eef1f6;font-size:13px;\">2:00 PM</td></tr><tr style=\"background:#ffffff;\"><td style=\"padding:7px 16px;font-weight:600;color:#475569;border-top:1px solid #eef1f6;font-size:12px;width:40%;white-space:nowrap;\">Status</td><td style=\"padding:7px 16px;color:#1e293b;border-top:1px solid #eef1f6;font-size:13px;\">Scheduled</td></tr></table><div style=\"margin:18px 0 0;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin:10px 0 2px;\"><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Reference ID</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">#APT-BT4JHX</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Resident ID</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">#RES-SKAQMG</td></tr><tr><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;\">Submission Date</td><td style=\"padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;\">August 11, 2026</td></tr></table></div><p style=\"margin-top:12px;font-size:14px;color:#475569;\">Please arrive on time at the DCVS office. Bring any necessary documents for your pet.</p><div style=\"margin:20px 0;border-left:3px solid #0e8131;padding:2px 0 2px 20px;\"><div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:10.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8a93ab;margin-bottom:8px;\">Note from DCVS Staff</div><div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#1e293b;\">try lang ites</div></div><p style=\"margin-top:16px;font-size:13px;color:#64748b;\">If you have any questions, please contact your barangay office or DCVS directly.</p>\n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', NULL, NULL, 5, 'resident', NULL, 0, NULL, NULL, '2026-08-10 17:14:29');

-- --------------------------------------------------------

--
-- Table structure for table `email_queue`
--

CREATE TABLE `email_queue` (
  `id` int(11) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `subject` varchar(500) NOT NULL,
  `body` text DEFAULT NULL,
  `status` enum('pending','processing','sent','failed') DEFAULT 'pending',
  `attempts` int(11) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `error_detail` text DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sent_to_role` varchar(50) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT NULL,
  `portal_delivered` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_queue`
--

INSERT INTO `email_queue` (`id`, `recipient_email`, `recipient_name`, `subject`, `body`, `status`, `attempts`, `error_message`, `error_detail`, `sent_by`, `sent_to_role`, `message_type`, `portal_delivered`, `created_at`, `processed_at`) VALUES
(3, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Appointment Confirmation', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">Regarding Your Animal Report </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>Regarding Your Animal Report</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">weh</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', 1, NULL, NULL, 14, 'resident', 'report', 1, '2026-08-10 13:21:45', '2026-08-10 22:05:34'),
(10, 'rolitojr.billones@gmail.com', 'Julia Billones', 'Update on Your Adoption Request', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">Regarding Your Adoption Request </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>Regarding Your Adoption Request</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">kunin mo na sa office si leet</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', 1, NULL, NULL, 14, 'resident', 'adoption', 1, '2026-08-10 15:25:58', '2026-08-10 23:35:16'),
(12, 'rolitojr.billones@gmail.com', 'Julia Billones', 'I MISS YOU PO', '<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n</head>\n<body style=\"margin:0;padding:0;background:#f4f5f7;font-family:Inter,\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;\">\n<center>\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"background:#f4f5f7;\">\n<tr><td align=\"center\" style=\"padding:32px 12px;\">\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;\">\n  <tr><td style=\"padding:36px 44px 0;\">\n    <div style=\"font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;\">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>\n    <div style=\"margin-top:6px;font-family:Georgia,\'Source Serif\',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;\">City Veterinary Services</div>\n  </td></tr>\n  <tr><td style=\"padding:30px 44px 6px;\">\n    <div style=\"font-family:Georgia,\'Source Serif\',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;\">General Message </div>\n  </td></tr>\n  <tr><td style=\"padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;\">\n  \n                    <p>Dear Julia Billones,</p>\n                    <p><strong>DCVS Dasmarinas</strong> from Dasmariñas City Veterinary Services sent you the following message regarding <strong>General Message</strong>:</p>\n                    <div style=\"margin:14px 0;\">\n                    <div style=\"border-top:1px solid #e2e8f0;margin-bottom:10px;\"></div>\n                    <p style=\"margin:0;font-size:14px;color:#1e293b;line-height:1.6;\">I MISS MY LALOVE</p>\n                    <div style=\"border-top:1px solid #e2e8f0;margin-top:10px;\"></div>\n                    </div>\n                    <p style=\"margin-top:16px;\">You can view this message and respond by logging into the DCVS resident portal.</p>\n                    <p style=\"margin-top:8px;\">— Dasmariñas City Veterinary Services</p>\n                \n  \n  </td></tr>\n  <tr><td style=\"padding:16px 44px 34px;\">\n    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\"><tr><td style=\"border-top:1px solid #eef1f6;font-size:0;line-height:0;\">&nbsp;</td></tr></table>\n    <div style=\"padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;\">\n      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href=\"http://localhost/dcvs\" style=\"color:#0e8131;text-decoration:none;\">http://localhost/dcvs</a>\n    </div>\n  </td></tr>\n</table>\n</td></tr>\n</table>\n</center>\n</body>\n</html>', 'sent', 1, NULL, NULL, 14, 'resident', 'general', 1, '2026-08-10 15:38:17', '2026-08-10 23:38:22');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `message_type` varchar(50) DEFAULT NULL,
  `reference_type` varchar(20) DEFAULT 'report',
  `reference_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `type`, `title`, `message`, `message_type`, `reference_type`, `reference_id`, `is_read`, `created_at`) VALUES
(12, 13, 'new_report', 'New Report Submitted', 'Julia Billones from Paliparan I submitted a Stray report.', NULL, 'report', 23, 0, '2026-07-03 02:29:24'),
(13, 5, 'report_validated', 'Report Validated by Barangay', 'Your report has been validated by Bords Bordagul and forwarded to DCVS for confirmation.', NULL, 'report', 23, 1, '2026-07-03 02:29:32'),
(14, 14, 'report_validated', 'New Validated Report from Barangay', 'Bords Bordagul validated and forwarded a report to DCVS.', NULL, 'report', 23, 0, '2026-07-03 02:29:32'),
(15, 14, 'adoption_request', 'New Adoption Registration', 'Horsey has been registered for adoption by a resident. Schedule an appointment to proceed.', NULL, 'pets', 30, 0, '2026-07-03 03:04:05'),
(16, 5, 'appointment_update', 'Adoption Appointment Requested', 'Your adoption appointment request has been submitted. Please wait for staff to schedule a pickup time.', NULL, 'appointments', 12, 1, '2026-07-03 03:04:20'),
(17, 14, 'adoption_appointment', 'New Adoption Appointment Request', ' has requested an adoption appointment. Schedule a pickup time.', NULL, 'appointments', 12, 0, '2026-07-03 03:04:20'),
(18, 13, 'new_report', 'New Report Submitted', 'Julia Billones from Paliparan I submitted a Rabies report.', NULL, 'report', 24, 0, '2026-07-03 13:50:50'),
(19, 5, 'appointment_update', 'Appointment Scheduled', 'Your kapon appointment is scheduled on 2026-07-10 at 10:00:00.\n\nStaff Message: Go to the DCVS Main Office', NULL, 'appointments', 11, 1, '2026-07-07 03:22:04'),
(20, 5, 'adoption_scheduled', 'Adoption Appointment Scheduled', 'Your adoption appointment is scheduled on 2026-07-03 at 11:00:00.\n\nStaff Message: Go to DCVS Main Office', NULL, 'appointments', 12, 1, '2026-07-07 03:22:39'),
(21, 5, 'report_confirmed', 'Report Scheduled', 'Your report has been confirmed by DCVS and is scheduled for action on July 9, 2026 at 2:28 PM. Notes: Go to your respective barangay.', NULL, 'report', 23, 1, '2026-07-07 06:28:47'),
(22, 13, 'report_confirmed', 'Report Scheduled by DCVS', 'The report from Paliparan I has been confirmed by DCVS and is scheduled for action on July 9, 2026 at 2:28 PM. Notes: Go to your respective barangay.', NULL, 'report', 23, 0, '2026-07-07 06:28:53'),
(23, 16, 'report_validated', 'Report Validated by Barangay', 'Your report has been validated by Chiclette Leet and forwarded to DCVS for confirmation.', NULL, 'report', 25, 0, '2026-07-09 02:58:33'),
(24, 14, 'report_validated', 'New Validated Report from Barangay', 'Chiclette Leet validated and forwarded a report to DCVS.', NULL, 'report', 25, 0, '2026-07-09 02:58:33'),
(25, 16, 'report_confirmed', 'Report Scheduled', 'Your report has been confirmed by DCVS and is scheduled for action on July 16, 2026 at 10:00 AM. Notes: check mo nga pre kung na receive sa email mo', NULL, 'report', 25, 0, '2026-07-09 02:59:30'),
(26, 18, 'report_confirmed', 'Report Scheduled by DCVS', 'The report from San Lorenzo Ruiz I has been confirmed by DCVS and is scheduled for action on July 16, 2026 at 10:00 AM. Notes: check mo nga pre kung na receive sa email mo', NULL, 'report', 25, 0, '2026-07-09 02:59:34'),
(27, 5, 'report_validated', 'Report Validated by Barangay', 'Your report has been validated by Bords Bordagul and forwarded to DCVS for confirmation.', NULL, 'report', 24, 1, '2026-07-09 03:05:19'),
(28, 14, 'report_validated', 'New Validated Report from Barangay', 'Bords Bordagul validated and forwarded a report to DCVS.', NULL, 'report', 24, 0, '2026-07-09 03:05:19'),
(29, 5, 'report_confirmed', 'Report Scheduled', 'Your report has been confirmed by DCVS and is scheduled for action on July 9, 2026 at 1:05 PM. Notes: test test try', NULL, 'report', 24, 1, '2026-07-09 03:05:37'),
(30, 13, 'report_confirmed', 'Report Scheduled by DCVS', 'The report from Paliparan I has been confirmed by DCVS and is scheduled for action on July 9, 2026 at 1:05 PM. Notes: test test try', NULL, 'report', 24, 0, '2026-07-09 03:05:41'),
(31, 18, 'new_report', 'New Report Submitted', 'Cahrlie Mhac Sixta from San Lorenzo Ruiz I submitted a Dead report.', NULL, 'report', 26, 0, '2026-07-09 03:14:43'),
(32, 14, 'new_report', 'New Report Submitted', 'Cahrlie Mhac Sixta from San Lorenzo Ruiz I submitted a Dead report.', NULL, 'report', 26, 0, '2026-07-09 03:14:43'),
(33, 16, 'report_validated', 'Report Validated by Barangay', 'Your report has been validated by Chiclette Leet and forwarded to DCVS for confirmation.', NULL, 'report', 26, 0, '2026-07-09 03:14:59'),
(34, 14, 'report_validated', 'New Validated Report from Barangay', 'Chiclette Leet validated and forwarded a report to DCVS.', NULL, 'report', 26, 0, '2026-07-09 03:14:59'),
(35, 16, 'report_confirmed', 'Report Scheduled', 'Your report has been confirmed by DCVS and is scheduled for action on July 16, 2026 at 11:15 AM. Notes: TEST TEST', NULL, 'report', 26, 0, '2026-07-09 03:15:17'),
(36, 18, 'report_confirmed', 'Report Scheduled by DCVS', 'The report from San Lorenzo Ruiz I has been confirmed by DCVS and is scheduled for action on July 16, 2026 at 11:15 AM. Notes: TEST TEST', NULL, 'report', 26, 0, '2026-07-09 03:15:21'),
(37, 5, 'staff_message', 'Message from Staff — Follow-up on Your Report', 'DCVS Dasmarinas wrote: come backkk', NULL, 'staff_message', 14, 1, '2026-07-09 04:25:19'),
(40, 5, 'staff_message', 'Message from Staff — Action Needed on Your Report', 'DCVS Dasmarinas wrote: We will come to the given address that you reported. On the way', NULL, 'staff_message', 14, 1, '2026-07-09 04:35:30'),
(41, 18, 'staff_message', 'Message from Staff — Appointment Rescheduled', 'DCVS Dasmarinas wrote: HELLO LEET', NULL, 'staff_message', 14, 0, '2026-07-09 04:36:19'),
(42, 14, 'new_report', 'New Report Submitted', 'Chloe Fornal from San Jose submitted a Injured report.', NULL, 'report', 27, 0, '2026-07-09 04:56:09'),
(43, 19, 'report_validated', 'Report Validated by Barangay', 'Your report has been validated by San Jose and forwarded to DCVS for confirmation.', NULL, 'report', 27, 0, '2026-07-09 04:57:38'),
(44, 14, 'report_validated', 'New Validated Report from Barangay', 'San Jose validated and forwarded a report to DCVS.', NULL, 'report', 27, 0, '2026-07-09 04:57:38'),
(45, 19, 'report_confirmed', 'Report Scheduled', 'Your report has been confirmed by DCVS and is scheduled for action on September 23, 2026 at 12:59 PM. Notes: HI BABY', NULL, 'report', 27, 0, '2026-07-09 04:57:59'),
(46, 20, 'report_confirmed', 'Report Scheduled by DCVS', 'The report from San Jose has been confirmed by DCVS and is scheduled for action on September 23, 2026 at 12:59 PM. Notes: HI BABY', NULL, 'report', 27, 0, '2026-07-09 04:58:03'),
(47, 19, 'staff_message', 'Message from Staff — Update on Your Adoption Request', 'DCVS Dasmarinas wrote: HI BABYYYY', NULL, 'staff_message', 14, 0, '2026-07-09 04:59:02'),
(48, 16, 'staff_message', 'Message from Staff — Appointment Confirmation', 'DCVS Dasmarinas wrote: Musta pre?', NULL, 'staff_message', 14, 0, '2026-07-09 10:57:37'),
(49, 5, 'staff_message', 'Message from Staff — Adoption Application Status', 'DCVS Dasmarinas wrote: WALA LANGGGG', NULL, 'staff_message', 14, 1, '2026-07-09 11:15:10'),
(50, 5, 'staff_message', 'Message from Staff — Update on Your Adoption Request', 'DCVS Dasmarinas wrote: ano na, tara?', NULL, 'staff_message', 14, 1, '2026-07-12 08:41:33'),
(51, 14, 'appointment_update', 'New Appointment Request', ' has requested a kapon appointment.', NULL, 'appointments', 13, 0, '2026-07-12 09:01:12'),
(52, 5, 'appointment_update', 'Appointment Requested', 'Your kapon appointment request has been submitted. Please wait for staff confirmation.', NULL, 'appointments', 13, 1, '2026-07-12 09:01:12'),
(55, 5, 'report_resolved', 'Report Resolved', 'Your report has been marked as resolved by DCVS. Notes: RESCUED', NULL, 'report', 23, 1, '2026-07-12 09:26:46'),
(56, 13, 'report_resolved', 'Case Resolved by DCVS', 'A case from your barangay has been resolved by DCVS. Notes: RESCUED', NULL, 'report', 23, 0, '2026-07-12 09:26:51'),
(57, 5, 'report_resolved', 'Report Resolved', 'Your report has been marked as resolved by DCVS. Notes: okay po thanks', NULL, 'report', 24, 1, '2026-07-12 09:51:26'),
(58, 13, 'report_resolved', 'Case Resolved by DCVS', 'A case from your barangay has been resolved by DCVS. Notes: okay po thanks', NULL, 'report', 24, 0, '2026-07-12 09:51:31'),
(70, 5, 'staff_message', 'Message from Staff — Ampunin mo na si leet', 'DCVS Dasmarinas wrote: kawawa naman e', NULL, 'staff_message', 14, 1, '2026-07-19 06:37:36'),
(71, 5, 'appointment_update', 'Appointment Scheduled', 'Your kapon appointment is scheduled on 2026-07-27 at 14:00:00.', NULL, 'appointments', 13, 0, '2026-07-22 01:14:24'),
(73, 5, 'staff_message', 'Message from Staff — Appointment Rescheduled', 'DCVS Dasmarinas wrote: tumabi ka nganiii', 'appointment', 'staff_message', 14, 0, '2026-07-23 14:51:52'),
(74, 5, 'staff_message', 'Message from Staff — Appointment Rescheduled', 'DCVS Dasmarinas wrote: try', 'appointment', 'staff_message', 14, 0, '2026-08-05 03:18:56'),
(75, 5, 'staff_message', 'Message from Staff — Appointment Confirmation', 'DCVS Dasmarinas wrote: weh', 'report', 'staff_message', 14, 0, '2026-08-10 13:21:45'),
(84, 5, 'staff_message', 'Message from Staff — Update on Your Adoption Request', 'DCVS Dasmarinas wrote: kunin mo na sa office si leet', 'adoption', 'staff_message', 14, 0, '2026-08-10 15:25:58'),
(86, 5, 'staff_message', 'Message from Staff — I MISS YOU PO', 'DCVS Dasmarinas wrote: I MISS MY LALOVE', 'general', 'staff_message', 14, 0, '2026-08-10 15:38:17'),
(87, 16, 'report_resolved', 'Report Resolved', 'Your report has been marked as resolved by DCVS. Notes: TRY LANG ITO', NULL, 'report', 25, 0, '2026-08-10 15:40:22'),
(88, 18, 'report_resolved', 'Case Resolved by DCVS', 'A case from your barangay has been resolved by DCVS. Notes: TRY LANG ITO', NULL, 'report', 25, 0, '2026-08-10 15:40:28'),
(89, 19, 'report_resolved', 'Report Resolved', 'Your report has been marked as resolved by DCVS. Notes: TRY LANG ITES', NULL, 'report', 27, 0, '2026-08-10 15:48:25'),
(90, 20, 'report_resolved', 'Case Resolved by DCVS', 'A case from your barangay has been resolved by DCVS. Notes: TRY LANG ITES', NULL, 'report', 27, 0, '2026-08-10 15:48:30'),
(97, 14, 'adoption_appointment', 'New Adoption Appointment Request', ' has requested a adoption appointment.', NULL, 'appointments', 19, 0, '2026-08-10 17:04:19'),
(98, 5, 'appointment_update', 'Adoption Appointment Requested', 'Your adoption appointment request has been submitted. Please wait for staff to schedule a pickup time.', NULL, 'appointments', 19, 0, '2026-08-10 17:04:19'),
(99, 5, 'adoption_scheduled', 'Adoption Appointment Scheduled', 'Your adoption appointment is scheduled on 2026-08-26 at 14:00:00.\n\nStaff Message: try lang ites', NULL, 'appointments', 19, 0, '2026-08-10 17:14:23'),
(100, 14, 'appointment_update', 'New Appointment Request', ' has requested a kapon appointment.', NULL, 'appointments', 20, 0, '2026-08-10 17:16:31'),
(101, 5, 'appointment_update', 'Appointment Requested', 'Your kapon appointment request has been submitted. Please wait for staff confirmation.', NULL, 'appointments', 20, 0, '2026-08-10 17:16:31');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `code` varchar(6) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `email`, `code`, `token`, `expires_at`, `used`, `created_at`) VALUES
(1, 'rolitojr.billones@gmail.com', '597591', '93c861c96d979bddaf3310d30dbf6d5e8d48e380688984760074e41e57cebf5e', '2026-07-15 09:34:40', 1, '2026-07-15 01:19:40');

-- --------------------------------------------------------

--
-- Table structure for table `pets`
--

CREATE TABLE `pets` (
  `pet_id` int(11) NOT NULL,
  `reference_id` varchar(20) DEFAULT NULL,
  `owner_id` int(11) NOT NULL,
  `pet_name` varchar(50) NOT NULL,
  `pet_type` enum('dog','cat','other') NOT NULL,
  `breed` varchar(50) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `gender` enum('male','female') DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `vaccination_status` text DEFAULT NULL,
  `microchip_id` varchar(50) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pets`
--

INSERT INTO `pets` (`pet_id`, `reference_id`, `owner_id`, `pet_name`, `pet_type`, `breed`, `age`, `weight`, `gender`, `color`, `vaccination_status`, `microchip_id`, `photo_url`, `created_at`, `updated_at`, `archived_at`) VALUES
(29, '#PET-XGAP46', 5, 'julya', 'cat', 'maganda', 2, 65.00, 'female', 'maputi', 'Not vaccinated', NULL, 'uploads/pets/pet_6a5df06d4d260_1784541293.jpg', '2026-07-03 02:30:03', '2026-07-20 10:09:36', NULL),
(30, '#PET-RTGEBU', 5, 'Horsey', 'dog', 'HORSE', 5, NULL, 'male', 'Golden', 'Fully vaccinated', NULL, 'uploads/pets/pet_photo_6a452aaf579ec.jpg', '2026-07-03 03:04:05', '2026-07-04 04:51:29', NULL),
(31, '#PET-YDJ7CQ', 5, 'Robin Padilla', 'dog', '8080', 5, 30.00, 'male', 'black', 'Fully vaccinated', NULL, '', '2026-07-23 15:39:27', '2026-07-23 15:39:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pet_listings`
--

CREATE TABLE `pet_listings` (
  `listing_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `species` varchar(20) NOT NULL,
  `breed` varchar(100) NOT NULL,
  `age` varchar(50) NOT NULL,
  `gender` varchar(20) NOT NULL,
  `weight` varchar(20) NOT NULL,
  `color` varchar(100) NOT NULL,
  `rescued_date` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `photo_url` varchar(500) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'available',
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pet_listings`
--

INSERT INTO `pet_listings` (`listing_id`, `name`, `species`, `breed`, `age`, `gender`, `weight`, `color`, `rescued_date`, `description`, `photo_url`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(9, 'Bella', 'Dog', 'NEGRA', '2', 'Female', '30', 'NEGRA', 'March 10, 2025', 'negraaa', 'uploads/pets/pet_photo_6a45255e78586.jpg', 'available', 7, '2026-07-01 22:34:06', '2026-07-01 22:34:06'),
(10, 'CHICLETTE', 'Dog', 'ASPIN', '6', 'Female', '65', 'WHITE', 'March 10, 2025', 'MABAITTT', 'uploads/pets/pet_photo_6a4526995e902.jpg', 'available', 7, '2026-07-01 22:39:21', '2026-07-01 23:06:22'),
(11, 'Horsey', 'Dog', 'HORSE', '5', 'Male', '65', 'Golden', 'March 10, 2025', 'HORSEYYY', 'uploads/pets/pet_photo_6a452aaf579ec.jpg', 'adopted', 7, '2026-07-01 22:56:47', '2026-07-06 12:57:58');

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `remember_tokens`
--

INSERT INTO `remember_tokens` (`id`, `user_id`, `token_hash`, `expires_at`, `created_at`) VALUES
(2, 5, 'a5169b17fd05ac9a20979fa0dcb8874daa46ea37facaed187e4c4e4ce4cbd7c1', '2026-08-23 04:55:46', '2026-07-24 02:55:46');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `report_id` int(11) NOT NULL,
  `reference_id` varchar(20) DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `report_type` enum('stray','rabies','injured','dead') NOT NULL,
  `description` text NOT NULL,
  `location_address` text NOT NULL,
  `coordinates_lat` decimal(10,8) DEFAULT NULL,
  `coordinates_long` decimal(11,8) DEFAULT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `barangay_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `urgency_level` enum('low','medium','high','critical') DEFAULT 'medium',
  `photo_url` varchar(255) DEFAULT NULL,
  `status` enum('pending','validated','rejected','confirmed','scheduled','resolved') NOT NULL DEFAULT 'pending',
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` timestamp NULL DEFAULT NULL,
  `barangay_clearance_issued` tinyint(1) DEFAULT 0,
  `clearance_issued_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `dcvs_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reports`
--

INSERT INTO `reports` (`report_id`, `reference_id`, `reporter_id`, `report_type`, `description`, `location_address`, `coordinates_lat`, `coordinates_long`, `barangay_id`, `barangay_notes`, `rejection_reason`, `urgency_level`, `photo_url`, `status`, `validated_by`, `validated_at`, `barangay_clearance_issued`, `clearance_issued_at`, `confirmed_by`, `confirmed_at`, `scheduled_at`, `dcvs_notes`, `created_at`, `updated_at`, `archived_at`) VALUES
(23, 'RPT-1783045764-5', 5, 'stray', 'mabait - aaaaaa', 'sa kabila', NULL, NULL, 22, '', NULL, 'medium', 'uploads/reports/1783045764_69cb245d19af53dee43910cd3cdb6edb.jpg', 'resolved', 13, '2026-07-03 02:29:32', 0, NULL, 14, '2026-07-07 06:28:47', '2026-07-09 14:28:00', 'Go to your respective barangay.', '2026-07-03 02:29:00', '2026-07-12 09:26:46', NULL),
(24, 'RPT-1783086650-5', 5, 'rabies', 'WILD - nakagat yung bata', 'sa kabilang kanto', NULL, NULL, 22, '', NULL, 'medium', 'uploads/reports/1783086650_b1a05b040f88dcaa7bece5c1769322be.jpg', 'resolved', 13, '2026-07-09 03:05:19', 0, NULL, 14, '2026-07-09 03:05:37', '2026-07-09 13:05:00', 'test test try', '2026-07-15 13:50:00', '2026-07-12 09:51:26', NULL),
(25, 'RPT-1783565033-16', 16, 'stray', 'Aggressive - Nakagat tropa ko ng aso', 'sa may 7/11', NULL, NULL, 49, '', NULL, 'medium', '', 'resolved', 18, '2026-07-09 02:58:33', 0, NULL, 14, '2026-07-09 02:59:30', '2026-07-16 10:00:00', 'check mo nga pre kung na receive sa email mo', '2026-07-09 02:45:00', '2026-08-10 15:40:22', NULL),
(26, 'RPT-1783566883-16', 16, 'dead', 'TEST - NAKAGAT AKO', 'sa may ilog', NULL, NULL, 49, '', NULL, 'medium', '', 'scheduled', 18, '2026-07-09 03:14:59', 0, NULL, 14, '2026-07-09 03:15:17', '2026-07-16 11:15:00', 'TEST TEST', '2026-07-09 03:14:00', '2026-07-09 03:15:17', NULL),
(27, 'RPT-1783572969-19', 19, 'injured', 'nabangga yung pusa :(( - binangga ng tricycle, kupal!', 'sa guevarra', NULL, NULL, 46, '', NULL, 'medium', '', 'resolved', 20, '2026-07-09 04:57:38', 0, NULL, 14, '2026-07-09 04:57:59', '2026-09-23 12:59:00', 'HI BABY', '2026-07-19 04:55:00', '2026-08-10 15:48:25', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `reference_id` varchar(20) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `role` enum('resident','barangay','staff','admin') NOT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `gender` enum('male','female','prefer_not') DEFAULT NULL,
  `address` text DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active',
  `password_updated_at` timestamp NULL DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `terms_agreed` tinyint(1) NOT NULL DEFAULT 0,
  `terms_agreed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `reference_id`, `username`, `password`, `email`, `full_name`, `first_name`, `last_name`, `role`, `barangay_id`, `phone`, `birthdate`, `gender`, `address`, `avatar_url`, `created_at`, `updated_at`, `status`, `password_updated_at`, `archived_at`, `terms_agreed`, `terms_agreed_at`) VALUES
(5, '#RES-SKAQMG', 'Rol', '$2y$10$xhHeMD7YlhXDyqPqKDl9g.uKP/GK4HOg61Ctw49ClLWIVLq2y6y5O', 'rolitojr.billones@gmail.com', 'Julia Billones', 'Julia', 'Billones', 'resident', 22, '09182121212', NULL, NULL, ', Paliparan I, Dasmariñas, Cavite', 'uploads/avatars/user_5_1785347450.jpg', '2026-06-24 08:06:32', '2026-07-29 17:50:50', 'active', '2026-07-15 01:20:13', NULL, 0, NULL),
(13, '#BRG-YM2RYH', 'Bords Bordagul', '$2y$10$E4jkii7bCtELg2AoWQmw/.XOiOOHd0vv3M/EoHMx3OJMks1Ip4cfu', 'bards@gmail.com', 'Bords Bordagul', 'Bords', 'Bordagul', 'barangay', 22, '09812122112', NULL, NULL, '', 'uploads/avatars/barangay_13_1783487004.png', '2026-07-03 02:22:51', '2026-07-08 05:03:24', 'active', NULL, NULL, 0, NULL),
(14, '#STF-5ZECV7', 'dcvsdasmarinas', '$2y$10$EJehJROBBZVK4eaIBoWL6Oq2gYSXV6Agda/eMMWkPrwe/H5SXiX1O', 'dcvsdasmarinas@gmail.com', 'DCVS Dasmarinas', 'DCVS', 'Dasmarinas', 'staff', 22, '09812122112', '2000-11-09', 'female', 'San Jose, Paliparan I, Dasmariñas, Cavite', 'uploads/avatars/user_14_1784442871.png', '2026-07-03 02:27:10', '2026-07-19 06:34:31', 'active', NULL, NULL, 0, NULL),
(15, NULL, 'admin@dcvs.gov.ph', '$2y$10$eAXq/ErUZSympvoIV2H9TeEcU25FTfhFMU0emeMxl.VgruE837HQW', 'admin@dcvs.gov.ph', 'System Administrator', 'System', 'Administrator', 'admin', NULL, '', NULL, NULL, '', 'uploads/avatars/admin_15_1783483520.jpg', '2026-07-05 06:11:00', '2026-07-08 04:05:20', 'active', NULL, NULL, 0, NULL),
(16, '#RES-EKQPDZ', 'Maki', '$2y$10$2OlvLjFaxmEC4ixBaoIaJ.j9AkctzXKote1xmKD7vuMASrkzLy5Ti', 'mhacsixta@gmail.com', 'Cahrlie Mhac Sixta', NULL, NULL, 'resident', 49, '09234192124', NULL, NULL, 'Malapit sa 7/11, San Lorenzo Ruiz I, Dasmariñas, Cavite', NULL, '2026-07-09 02:41:04', '2026-07-12 09:20:36', 'active', NULL, NULL, 1, '2026-07-09 10:41:04'),
(18, '#BRG-PSRKJK', 'Chiclette_Leet05', '$2y$10$MGvswIrbtNErkb46KBpj.etZY0Ov0eInPPBN5e3p4clEcEaXSX3Zi', 'chicletteleet@gmail.com', 'Chiclette Leet', NULL, NULL, 'barangay', 49, '09531192901', NULL, NULL, NULL, NULL, '2026-07-09 02:58:01', '2026-07-09 02:58:01', 'active', NULL, NULL, 1, '2026-07-09 10:58:01'),
(19, '#RES-2RTY93', 'Chloe_Fornal', '$2y$10$ilgL0gp2Mbyx5JBYxwoy4.MP88Awe6wzjMiowXh.qI.gfV6vJaIEu', 'jcffornal@kld.edu.ph', 'Chloe Fornal', NULL, NULL, 'resident', 46, '09531192901', NULL, NULL, 'St Marys, San Jose, Dasmariñas, Cavite', 'uploads/avatars/user_19_1783574468.jpg', '2026-07-09 04:54:13', '2026-07-09 05:21:08', 'active', NULL, NULL, 1, '2026-07-09 12:54:13'),
(20, '#BRG-AU47YC', 'Brgy_SanJose', '$2y$10$1TOeYYCM4lGnlot.yP9sT.UPWkhfWxBbckivFn8ZbKfrDQEk593Na', 'brgysanjose@gmail.com', 'San Jose', NULL, NULL, 'barangay', 46, '09734231212', NULL, NULL, NULL, NULL, '2026-07-09 04:57:22', '2026-07-09 04:57:22', 'active', NULL, NULL, 1, '2026-07-09 12:57:22');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `adoption_applications`
--
ALTER TABLE `adoption_applications`
  ADD PRIMARY KEY (`application_id`),
  ADD KEY `listing_id` (`listing_id`),
  ADD KEY `applicant_id` (`applicant_id`);

--
-- Indexes for table `adoption_listings`
--
ALTER TABLE `adoption_listings`
  ADD PRIMARY KEY (`listing_id`),
  ADD KEY `pet_id` (`pet_id`),
  ADD KEY `listed_by` (`listed_by`);

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD KEY `barangay_id` (`barangay_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `fk_announcement_barangay` (`barangay_id`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD UNIQUE KEY `reference_id` (`reference_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `pet_id` (`pet_id`);

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`barangay_id`);

--
-- Indexes for table `confirmed_cases`
--
ALTER TABLE `confirmed_cases`
  ADD PRIMARY KEY (`case_id`),
  ADD KEY `report_id` (`report_id`),
  ADD KEY `barangay_id` (`barangay_id`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_recipient` (`recipient_email`),
  ADD KEY `idx_sent_by` (`sent_by`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_message_type` (`message_type`);

--
-- Indexes for table `email_queue`
--
ALTER TABLE `email_queue`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_token` (`token`);

--
-- Indexes for table `pets`
--
ALTER TABLE `pets`
  ADD PRIMARY KEY (`pet_id`),
  ADD UNIQUE KEY `reference_id` (`reference_id`),
  ADD KEY `owner_id` (`owner_id`);

--
-- Indexes for table `pet_listings`
--
ALTER TABLE `pet_listings`
  ADD PRIMARY KEY (`listing_id`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_token_hash` (`token_hash`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`report_id`),
  ADD UNIQUE KEY `reference_id` (`reference_id`),
  ADD KEY `reporter_id` (`reporter_id`),
  ADD KEY `barangay_id` (`barangay_id`),
  ADD KEY `validated_by` (`validated_by`),
  ADD KEY `confirmed_by` (`confirmed_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `reference_id` (`reference_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=415;

--
-- AUTO_INCREMENT for table `adoption_applications`
--
ALTER TABLE `adoption_applications`
  MODIFY `application_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adoption_listings`
--
ALTER TABLE `adoption_listings`
  MODIFY `listing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `barangay_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `confirmed_cases`
--
ALTER TABLE `confirmed_cases`
  MODIFY `case_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `email_queue`
--
ALTER TABLE `email_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=102;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pets`
--
ALTER TABLE `pets`
  MODIFY `pet_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `pet_listings`
--
ALTER TABLE `pet_listings`
  MODIFY `listing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `adoption_applications`
--
ALTER TABLE `adoption_applications`
  ADD CONSTRAINT `adoption_applications_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `adoption_listings` (`listing_id`),
  ADD CONSTRAINT `adoption_applications_ibfk_2` FOREIGN KEY (`applicant_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `adoption_listings`
--
ALTER TABLE `adoption_listings`
  ADD CONSTRAINT `adoption_listings_ibfk_1` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`pet_id`),
  ADD CONSTRAINT `adoption_listings_ibfk_2` FOREIGN KEY (`listed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`),
  ADD CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_announcement_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`) ON DELETE SET NULL;

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`pet_id`) ON DELETE SET NULL;

--
-- Constraints for table `confirmed_cases`
--
ALTER TABLE `confirmed_cases`
  ADD CONSTRAINT `confirmed_cases_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `reports` (`report_id`),
  ADD CONSTRAINT `confirmed_cases_ibfk_2` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `pets`
--
ALTER TABLE `pets`
  ADD CONSTRAINT `pets_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`),
  ADD CONSTRAINT `reports_ibfk_3` FOREIGN KEY (`validated_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `reports_ibfk_4` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
