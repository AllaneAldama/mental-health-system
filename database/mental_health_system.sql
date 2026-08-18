-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 18, 2026 at 10:21 AM
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
-- Database: `mental_health_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `assessment_data`
--

CREATE TABLE `assessment_data` (
  `assessment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `phq9_score` int(11) NOT NULL,
  `phq_level` varchar(50) DEFAULT NULL,
  `gad7_score` int(11) NOT NULL,
  `gad_level` varchar(50) DEFAULT NULL,
  `risk_level` varchar(50) NOT NULL,
  `assessment_date` datetime DEFAULT current_timestamp(),
  `status` enum('Pending','Reviewed','Monitoring','Referred') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assessment_data`
--

INSERT INTO `assessment_data` (`assessment_id`, `user_id`, `phq9_score`, `phq_level`, `gad7_score`, `gad_level`, `risk_level`, `assessment_date`, `status`) VALUES
(19, 23, 0, 'Minimal', 0, 'Minimal', 'Low', '2026-08-06 17:36:44', 'Pending'),
(20, 27, 19, 'Moderately Severe', 8, 'Mild', 'Low', '2026-08-06 20:17:55', 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `feedback_data`
--

CREATE TABLE `feedback_data` (
  `feedback_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `counselor_id` int(11) NOT NULL,
  `feedback` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_data`
--

CREATE TABLE `notification_data` (
  `notification_id` int(11) NOT NULL,
  `counselor_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `notification_status` enum('Unread','Read') DEFAULT 'Unread',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `question_data`
--

CREATE TABLE `question_data` (
  `question_id` int(11) NOT NULL,
  `assessment_type` enum('PHQ-9','GAD-7') NOT NULL,
  `question_number` int(11) NOT NULL,
  `question_text` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `question_data`
--

INSERT INTO `question_data` (`question_id`, `assessment_type`, `question_number`, `question_text`) VALUES
(1, 'PHQ-9', 1, 'Little interest or pleasure in doing things?'),
(2, 'PHQ-9', 2, 'Feeling down, depressed, or hopeless?'),
(3, 'PHQ-9', 3, 'Trouble falling or staying asleep, or sleeping too much?'),
(4, 'PHQ-9', 4, 'Feeling tired or having little energy?'),
(5, 'PHQ-9', 5, 'Poor appetite or overeating?'),
(6, 'PHQ-9', 6, 'Feeling bad about yourself or that you are a failure?'),
(7, 'PHQ-9', 7, 'Trouble concentrating on things?'),
(8, 'PHQ-9', 8, 'Moving or speaking so slowly that other people could have noticed, or the opposite — being fidgety or restless?'),
(9, 'PHQ-9', 9, 'Thoughts that you would be better off dead or of hurting yourself in some way?'),
(10, 'GAD-7', 1, 'Feeling nervous, anxious or on edge?'),
(11, 'GAD-7', 2, 'Not being able to stop or control worrying?'),
(12, 'GAD-7', 3, 'Worrying too much about different things?'),
(13, 'GAD-7', 4, 'Trouble relaxing?'),
(14, 'GAD-7', 5, 'Being so restless that it is hard to sit still?'),
(15, 'GAD-7', 6, 'Becoming easily annoyed or irritable?'),
(16, 'GAD-7', 7, 'Feeling afraid as if something awful might happen?');

-- --------------------------------------------------------

--
-- Table structure for table `referral_data`
--

CREATE TABLE `referral_data` (
  `referral_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `counselor_id` int(11) NOT NULL,
  `referral_status` enum('Pending','Accepted','Completed') DEFAULT 'Pending',
  `referral_date` datetime DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `response_data`
--

CREATE TABLE `response_data` (
  `response_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_score` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `response_data`
--

INSERT INTO `response_data` (`response_id`, `assessment_id`, `question_id`, `answer_score`) VALUES
(209, 19, 1, 0),
(210, 19, 2, 0),
(211, 19, 3, 0),
(212, 19, 4, 0),
(213, 19, 5, 0),
(214, 19, 6, 0),
(215, 19, 7, 0),
(216, 19, 8, 0),
(217, 19, 9, 0),
(218, 19, 10, 0),
(219, 19, 11, 0),
(220, 19, 12, 0),
(221, 19, 13, 0),
(222, 19, 14, 0),
(223, 19, 15, 0),
(224, 19, 16, 0),
(225, 20, 1, 1),
(226, 20, 2, 3),
(227, 20, 3, 3),
(228, 20, 4, 1),
(229, 20, 5, 1),
(230, 20, 6, 2),
(231, 20, 7, 3),
(232, 20, 8, 2),
(233, 20, 9, 3),
(234, 20, 10, 2),
(235, 20, 11, 3),
(236, 20, 12, 1),
(237, 20, 13, 2),
(238, 20, 14, 0),
(239, 20, 15, 0),
(240, 20, 16, 0);

-- --------------------------------------------------------

--
-- Table structure for table `schedule_data`
--

CREATE TABLE `schedule_data` (
  `schedule_id` int(11) NOT NULL,
  `referral_id` int(11) NOT NULL,
  `meeting_date` date DEFAULT NULL,
  `meeting_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Completed','Cancelled') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_data`
--

CREATE TABLE `user_data` (
  `user_id` int(11) NOT NULL,
  `student_number` varchar(20) DEFAULT NULL,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_type` enum('admin','counselor','student') NOT NULL,
  `program` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `alias` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_data`
--

INSERT INTO `user_data` (`user_id`, `student_number`, `fullname`, `email`, `password_hash`, `role_type`, `program`, `year_level`, `alias`, `status`, `created_at`, `updated_at`) VALUES
(1, NULL, 'System Administrator', 'admin@ptc.edu.ph', '$2y$10$ffv6aVPnmO9kOEOZucRUc.YPSk0wvNOLHRd1g23aj0Rp6zjMpnn8.', 'admin', NULL, NULL, NULL, 'active', '2026-08-03 06:18:35', '2026-08-03 06:20:02'),
(21, '23BSIT-0092', 'Kairo Mendez', 'kaimendez@paterostechnologicalcollege.edu.ph', '$2y$10$dXgCWyVQjLO0nYz2bXAfgOOSM/d3itvWxsRn/FzQByDCacXmr8T4C', 'student', 'BSIT', '2nd Year', 'Kiro', 'active', '2026-08-05 14:59:15', '2026-08-05 14:59:15'),
(23, '23BSIT-0027', 'Allane Leinard Cervantes Aldama', 'acaldama@paterostechnologicalcollege.edu.ph', '$2y$10$K7qKgyUSp2pVLzqIh8w9UOdC8eHqcatv3ZmxJSA9.wdopG573hHvC', 'student', 'BSIT', '4th Year', 'AL', 'active', '2026-08-06 09:36:29', '2026-08-06 09:36:29'),
(24, '23BSIT-0029', 'Kairo Mendez', 'kaiko@ptc.edu.ph', '$2y$10$O74CwJD3umntehU0R52kguaf.jjm0OUVtm0X6UHn8fD/qZWcpiKC.', 'student', 'BSIT', '4th Year', 'Kiro', 'active', '2026-08-06 12:14:47', '2026-08-06 12:14:47'),
(25, '23BSIT-0091', 'Kairo Mendez', 'adsad@paterostech', '$2y$10$bJZQBdlNz2E/gzr7qF7YVekp7n4oL/VV20FOPKJL0.etoMJbRGZDW', 'student', 'BSIT', '4th Year', 'Kiro', 'active', '2026-08-06 12:16:16', '2026-08-06 12:16:16'),
(26, '23BSIT-0093', 'Kairo Mendez', 'adsada@paterostech', '$2y$10$klzutIMKuC0haQ/tXw3iaemEfRPB7n6w615sppJljxNptWeW..VnW', 'student', 'BSIT', '4th Year', 'Kiro', 'active', '2026-08-06 12:16:37', '2026-08-06 12:16:37'),
(27, '23BSIT-0097', 'Kairo Mendez', 'adsadaa@paterostech', '$2y$10$ZPJ.pfUyesDzGt/bUm2bhuzzsjeVJq2Ht1WRH5zGtY8H0G7ppvCgW', 'student', 'BSIT', '4th Year', 'Kiro', 'active', '2026-08-06 12:17:03', '2026-08-06 12:17:03');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assessment_data`
--
ALTER TABLE `assessment_data`
  ADD PRIMARY KEY (`assessment_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `feedback_data`
--
ALTER TABLE `feedback_data`
  ADD PRIMARY KEY (`feedback_id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `counselor_id` (`counselor_id`);

--
-- Indexes for table `notification_data`
--
ALTER TABLE `notification_data`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `counselor_id` (`counselor_id`),
  ADD KEY `assessment_id` (`assessment_id`);

--
-- Indexes for table `question_data`
--
ALTER TABLE `question_data`
  ADD PRIMARY KEY (`question_id`);

--
-- Indexes for table `referral_data`
--
ALTER TABLE `referral_data`
  ADD PRIMARY KEY (`referral_id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `counselor_id` (`counselor_id`);

--
-- Indexes for table `response_data`
--
ALTER TABLE `response_data`
  ADD PRIMARY KEY (`response_id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `schedule_data`
--
ALTER TABLE `schedule_data`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `referral_id` (`referral_id`);

--
-- Indexes for table `user_data`
--
ALTER TABLE `user_data`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `student_number` (`student_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assessment_data`
--
ALTER TABLE `assessment_data`
  MODIFY `assessment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `feedback_data`
--
ALTER TABLE `feedback_data`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_data`
--
ALTER TABLE `notification_data`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `question_data`
--
ALTER TABLE `question_data`
  MODIFY `question_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `referral_data`
--
ALTER TABLE `referral_data`
  MODIFY `referral_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `response_data`
--
ALTER TABLE `response_data`
  MODIFY `response_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=241;

--
-- AUTO_INCREMENT for table `schedule_data`
--
ALTER TABLE `schedule_data`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_data`
--
ALTER TABLE `user_data`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assessment_data`
--
ALTER TABLE `assessment_data`
  ADD CONSTRAINT `assessment_data_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user_data` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback_data`
--
ALTER TABLE `feedback_data`
  ADD CONSTRAINT `feedback_data_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessment_data` (`assessment_id`),
  ADD CONSTRAINT `feedback_data_ibfk_2` FOREIGN KEY (`counselor_id`) REFERENCES `user_data` (`user_id`);

--
-- Constraints for table `notification_data`
--
ALTER TABLE `notification_data`
  ADD CONSTRAINT `notification_data_ibfk_1` FOREIGN KEY (`counselor_id`) REFERENCES `user_data` (`user_id`),
  ADD CONSTRAINT `notification_data_ibfk_2` FOREIGN KEY (`assessment_id`) REFERENCES `assessment_data` (`assessment_id`);

--
-- Constraints for table `referral_data`
--
ALTER TABLE `referral_data`
  ADD CONSTRAINT `referral_data_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessment_data` (`assessment_id`),
  ADD CONSTRAINT `referral_data_ibfk_2` FOREIGN KEY (`counselor_id`) REFERENCES `user_data` (`user_id`);

--
-- Constraints for table `response_data`
--
ALTER TABLE `response_data`
  ADD CONSTRAINT `response_data_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessment_data` (`assessment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `response_data_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `question_data` (`question_id`);

--
-- Constraints for table `schedule_data`
--
ALTER TABLE `schedule_data`
  ADD CONSTRAINT `schedule_data_ibfk_1` FOREIGN KEY (`referral_id`) REFERENCES `referral_data` (`referral_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
