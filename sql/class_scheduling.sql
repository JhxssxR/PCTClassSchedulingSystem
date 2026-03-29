-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 29, 2026 at 09:10 PM
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
-- Database: `class_scheduling`
--

-- --------------------------------------------------------

--
-- Table structure for table `classrooms`
--

CREATE TABLE `classrooms` (
  `id` int(11) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `capacity` int(11) NOT NULL,
  `building` varchar(50) NOT NULL,
  `room_type` enum('lecture','laboratory','conference') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classrooms`
--

INSERT INTO `classrooms` (`id`, `room_number`, `capacity`, `building`, `room_type`, `created_at`, `status`) VALUES
(4, '104', 30, 'Main Building', 'lecture', '2025-06-03 10:49:39', 'active'),
(6, '106', 30, 'Main Building', 'lecture', '2025-06-03 12:36:52', 'active'),
(7, '102', 30, 'Main Building', 'lecture', '2025-06-03 12:47:04', 'active'),
(8, '203', 3, 'Main Building', 'lecture', '2025-06-03 16:43:25', 'active'),
(9, '107', 30, 'Main Building', 'lecture', '2025-06-03 18:19:15', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `credits` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `units` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `course_code`, `course_name`, `description`, `credits`, `created_at`, `units`) VALUES
(2, 'IT101', 'Introduction to Computing', NULL, 3, '2025-06-03 09:01:22', 3),
(4, 'HRT101', 'Introduction to Hospitality', NULL, 3, '2025-06-03 10:56:21', 0),
(6, 'TECH101', 'Introduction to Technology', NULL, 3, '2025-06-03 13:16:35', 0),
(10, 'BTLED', 'Bachelor of Technology & Livelihood Education', NULL, 0, '2026-03-27 14:30:27', 1),
(11, 'BEED', 'Bachelor of Elementary Education', NULL, 0, '2026-03-27 14:30:27', 6),
(12, 'BSED-ENG', 'Bachelor of Secondary Education major in English', NULL, 0, '2026-03-27 14:30:27', 4),
(13, 'BSED-MATH', 'Bachelor of Secondary Education major in Mathematics', NULL, 0, '2026-03-27 14:30:27', 1),
(14, 'TCP', 'Teacher Certificate Program', NULL, 0, '2026-03-27 14:30:27', 1),
(15, 'BSCA', 'Bachelor of Science in Custom Administration', NULL, 0, '2026-03-27 14:30:27', 1),
(16, 'BSBAA', 'Bachelor of Science in Business Accountancy', NULL, 0, '2026-03-27 14:30:27', 1),
(17, 'BSBA-FM', 'Bachelor of Science in Business Administration major in Financial Management', NULL, 0, '2026-03-27 14:30:27', 1),
(18, 'BSBA-HRDM', 'Bachelor of Science in Business Administration major in Human Resource Development Management', NULL, 0, '2026-03-27 14:30:27', 1),
(19, 'BSBA-OM', 'Bachelor of Science in Business Administration major in Operations Management', NULL, 0, '2026-03-27 14:30:27', 1),
(20, 'BSBA-DM', 'Bachelor of Science in Business Administration major in Digital marketing', NULL, 0, '2026-03-27 14:30:27', 1),
(21, 'BSBA-CM', 'Bachelor of Science in Business Administration major in Cooperative Management', NULL, 0, '2026-03-27 14:30:27', 1),
(22, 'BSIT', 'Bachelor of Science in Information Technology', NULL, 0, '2026-03-27 14:30:27', 1),
(23, 'DIT', 'Diploma in Information Technology', NULL, 0, '2026-03-27 14:30:27', 1),
(25, 'BSHM', 'Bachelor of Science in Hospitality Management', NULL, 0, '2026-03-27 14:30:27', 1),
(26, 'BSTM', 'Bachelor of Science in Tourism Management', NULL, 0, '2026-03-27 14:30:27', 1),
(27, 'DAET', 'Diploma in Automotive Engineering Technology', NULL, 0, '2026-03-27 14:30:27', 1),
(28, 'DHRT', 'Diploma in Hotel & Restaurant Technology', NULL, 0, '2026-03-27 14:30:27', 1),
(29, 'PN', 'Practical Nursing', NULL, 0, '2026-03-27 14:30:27', 1),
(30, 'HRS', 'Hotel and Restaurant Services', NULL, 0, '2026-03-27 14:30:27', 1),
(31, 'TM', 'Tourism Management', NULL, 0, '2026-03-27 14:30:27', 1),
(32, 'HCS', 'Health Care Services', NULL, 0, '2026-03-27 14:30:27', 1),
(33, 'TM1', 'Trainers Methodology I', NULL, 0, '2026-03-27 14:30:27', 1),
(34, 'JLP', 'Japanese Language Program', NULL, 0, '2026-03-27 14:30:27', 1),
(35, 'IELTS', 'IELTS', NULL, 0, '2026-03-27 14:30:27', 1),
(36, 'TESOL', 'TESOL', NULL, 0, '2026-03-27 14:30:27', 1),
(37, 'ESL', 'ESL', NULL, 0, '2026-03-27 14:30:27', 1);

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `enrollment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `enrolled_at` datetime DEFAULT NULL,
  `dropped_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`id`, `student_id`, `schedule_id`, `enrollment_date`, `status`, `enrolled_at`, `dropped_at`, `rejected_at`) VALUES
(32, 47, 17, '2026-03-28 18:21:34', 'approved', '2026-03-29 20:49:16', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notification_state`
--

CREATE TABLE `notification_state` (
  `user_id` int(11) NOT NULL,
  `notif_seen_at` datetime DEFAULT NULL,
  `notif_cleared_at` datetime DEFAULT NULL,
  `registrar_notif_seen_at` datetime DEFAULT NULL,
  `registrar_notif_cleared_at` datetime DEFAULT NULL,
  `admin_notif_seen_at` datetime DEFAULT NULL,
  `admin_notif_cleared_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_state`
--

INSERT INTO `notification_state` (`user_id`, `notif_seen_at`, `notif_cleared_at`, `registrar_notif_seen_at`, `registrar_notif_cleared_at`, `admin_notif_seen_at`, `admin_notif_cleared_at`, `updated_at`) VALUES
(1, '2026-03-29 17:14:38', NULL, NULL, NULL, '2026-03-29 01:32:57', NULL, '2026-03-29 09:14:38'),
(22, '2026-03-29 02:27:18', NULL, '2026-03-29 01:28:03', '2026-03-29 01:28:03', NULL, NULL, '2026-03-28 18:27:18'),
(36, '2026-03-29 20:49:59', NULL, NULL, NULL, NULL, NULL, '2026-03-29 12:49:59'),
(37, '2026-03-29 17:16:13', NULL, NULL, NULL, NULL, NULL, '2026-03-29 09:16:13'),
(58, '2026-03-29 21:01:52', NULL, NULL, NULL, NULL, NULL, '2026-03-29 13:01:52');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `instructor_id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `day_of_week` enum('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Monday-Friday') NOT NULL,
  `start_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `semester` varchar(20) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `year_level` tinyint(4) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `slot_name` varchar(50) DEFAULT NULL,
  `max_students` int(11) DEFAULT 30,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`id`, `course_id`, `subject_id`, `instructor_id`, `classroom_id`, `day_of_week`, `start_time`, `created_at`, `semester`, `academic_year`, `year_level`, `start_date`, `end_date`, `slot_name`, `max_students`, `status`) VALUES
(17, 23, 35, 36, 7, 'Monday', '10:00:00', '2026-03-28 11:21:19', '1st Semester', '2026-2027', 3, NULL, NULL, NULL, 30, 'inactive'),
(19, 23, 37, 37, 6, 'Wednesday', '10:30:00', '2026-03-29 12:51:30', '1st Semester', '2026-2027', 3, NULL, NULL, NULL, 30, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `schedule_templates`
--

CREATE TABLE `schedule_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `course_id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `day_of_week` varchar(20) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `key`, `value`, `created_at`, `updated_at`) VALUES
(1, 'max_enrollments', '6', '2026-03-25 14:00:29', '2026-03-25 14:00:29'),
(2, 'enrollment_approval', '1', '2026-03-25 14:00:29', '2026-03-25 14:00:29'),
(3, 'default_class_duration', '120', '2026-03-25 14:00:29', '2026-03-25 14:00:29'),
(4, 'break_time', '15', '2026-03-25 14:00:29', '2026-03-25 14:00:29'),
(5, 'email_notifications', '1', '2026-03-25 14:00:29', '2026-03-25 14:00:29'),
(6, 'notification_days', '1', '2026-03-25 14:00:29', '2026-03-25 14:00:29'),
(331, 'school_name', 'Philippine College of Technology', '2026-03-25 14:15:23', '2026-03-25 14:15:23'),
(332, 'school_short_name', 'PCT', '2026-03-25 14:15:23', '2026-03-25 14:15:23'),
(333, 'school_address', 'Davao City, Philippines', '2026-03-25 14:15:23', '2026-03-25 14:15:23'),
(334, 'contact_email', 'registrar@pct.edu.ph', '2026-03-25 14:15:23', '2026-03-25 14:15:23'),
(335, 'contact_phone', '+63 82 123 4567', '2026-03-25 14:15:23', '2026-03-25 14:15:23'),
(336, 'school_website', 'https://pct.edu.ph', '2026-03-25 14:15:23', '2026-03-25 14:15:23'),
(337, 'school_description', 'Philippine College of Technology is a leading institution in Davao City offering quality education in technology and engineering.', '2026-03-25 14:15:23', '2026-03-25 14:15:23');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `subject_name` varchar(255) NOT NULL,
  `year_level` tinyint(4) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `subject_code`, `subject_name`, `year_level`, `created_at`) VALUES
(1, 'GE1', 'Understanding the Self', 1, '2026-03-27 14:06:59'),
(2, 'GE2', 'Reading Philippine History', 1, '2026-03-27 14:06:59'),
(3, 'GE3', 'Contemporary World', 1, '2026-03-27 14:06:59'),
(4, 'CC101', 'Introduction to Computing', 1, '2026-03-27 14:06:59'),
(5, 'CC102', 'Fundamentals of Programming 1', 1, '2026-03-27 14:06:59'),
(6, 'NSTP 1', 'National Service Training Program', 1, '2026-03-27 14:06:59'),
(7, 'PATHFit1', 'Movement Competency Training', 1, '2026-03-27 14:06:59'),
(8, 'GE4', 'Mathematics in the Modern World', 1, '2026-03-27 14:06:59'),
(9, 'GE5', 'Purposive Communication', 1, '2026-03-27 14:06:59'),
(10, 'GE6', 'Art Appreciation', 1, '2026-03-27 14:06:59'),
(11, 'CC103', 'Intermediate Programming', 1, '2026-03-27 14:06:59'),
(12, 'MS101', 'Discrete Mathematics', 1, '2026-03-27 14:06:59'),
(13, 'HCI101', 'Human Computer Interaction', 1, '2026-03-27 14:06:59'),
(14, 'NSTP 2', 'National Service Training Program', 1, '2026-03-27 14:06:59'),
(15, 'PATHFit2', 'Exercise-based Fitness Activities', 1, '2026-03-27 14:06:59'),
(16, 'GE7', 'Science Technology and Society', 2, '2026-03-27 14:06:59'),
(17, 'CC104', 'Data Structure & Algorithms', 2, '2026-03-27 14:06:59'),
(18, 'IPT101', 'Integrative Programming Technologies', 2, '2026-03-27 14:06:59'),
(19, 'MS102', 'Quantitative Methods (inc. Modeling/Sim)', 2, '2026-03-27 14:06:59'),
(20, 'IOT', 'Internet of Things', 2, '2026-03-27 14:06:59'),
(21, 'ML', 'Machine Learning', 2, '2026-03-27 14:06:59'),
(22, 'Elec 1', 'Elective 1 (Integrative Programming Tech 2)', 2, '2026-03-27 14:06:59'),
(23, 'PATHFit3', 'Martial Arts', 2, '2026-03-27 14:06:59'),
(24, 'GE8', 'Ethics', 2, '2026-03-27 14:06:59'),
(25, 'CC105', 'Information Management', 2, '2026-03-27 14:06:59'),
(26, 'IAS101', 'Information Assurance & Security', 2, '2026-03-27 14:06:59'),
(27, 'NET101', 'Networking', 2, '2026-03-27 14:06:59'),
(28, 'ID', 'Introduction to Data Science', 2, '2026-03-27 14:06:59'),
(29, 'AMP', 'Advance Mobile Programming', 2, '2026-03-27 14:06:59'),
(30, 'Elec 2', 'Elective 2 (Platform Technologies 1)', 2, '2026-03-27 14:06:59'),
(31, 'PATHFit4', 'Group Exercises, Aerobics, Yoga', 2, '2026-03-27 14:06:59'),
(32, 'GE9', 'Life and Works of Rizal', 3, '2026-03-27 14:06:59'),
(33, 'GE10', 'Living in the IT Era', 3, '2026-03-27 14:06:59'),
(34, 'NET102', 'Networking', 3, '2026-03-27 14:06:59'),
(35, 'SIA101', 'System Integration and Architecture', 3, '2026-03-27 14:06:59'),
(36, 'IM101', 'Fundamentals of Database Systems', 3, '2026-03-27 14:06:59'),
(37, 'Elec 3', 'Elective 3 (Web System Technologies)', 3, '2026-03-27 14:06:59'),
(38, 'GE11', 'The Entrepreneurial Mind', 3, '2026-03-27 14:06:59'),
(39, 'GE12', 'Gender and Society', 3, '2026-03-27 14:06:59'),
(40, 'CC106', 'App Development & Emerging Tech', 3, '2026-03-27 14:06:59'),
(41, 'SP101', 'Social Professional Issues', 3, '2026-03-27 14:06:59'),
(42, 'FC', 'Fundamentals of Cybersecurity', 3, '2026-03-27 14:06:59'),
(43, 'CC', 'Cloud Computing', 3, '2026-03-27 14:06:59'),
(44, 'Elec 4', 'Elective 4 (System Integration & Architecture 2)', 3, '2026-03-27 14:07:00'),
(45, 'CAP101', 'Capstone Project 1 (Research)', 3, '2026-03-27 14:07:00'),
(46, 'IAS102', 'Information Assurance & Security', 3, '2026-03-27 14:07:00'),
(47, 'CAP102', 'Capstone Project 2 (Research)', 3, '2026-03-27 14:07:00'),
(48, 'SA101', 'System Administration & Maintenance', 3, '2026-03-27 14:07:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('super_admin','admin','registrar','instructor','student') NOT NULL DEFAULT 'student',
  `year_level` tinyint(4) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `student_id` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `year_level`, `first_name`, `last_name`, `created_at`, `student_id`) VALUES
(1, 'admin', '$2y$10$cZWlm33cAzgGPQHn7KQNTeir8cyZhP3dcCSV1QJ6cvT0LB6I.dRa.', 'admin@pct.edu', 'super_admin', NULL, 'System', 'Administrator', '2025-06-03 08:34:06', NULL),
(22, 'registrar', '$2y$10$NIU0m5S61A4mIWeODBfBn.44KZA4opwv3I0ZrZLPn2Zb.3LlCrH7m', 'registrar@pct.edu', 'registrar', NULL, 'Jane', 'Smith', '2025-06-03 17:27:22', NULL),
(36, 'EdwinAmaga', '$2y$10$zLhgEjI7ErlqJPJDuydyV.8J1IveMzRT2xyDVwOSaMEVxdDA4pgya', 'Edwin.Amaga@pctdavao.edu.ph', 'instructor', NULL, 'Edwin', 'Amaga', '2026-03-28 17:18:43', NULL),
(37, 'JeffreyOñas', '$2y$10$Al7bV5c9Tmpu0k.UiNwhPeoTCVGtTE0n5bSMY/5ecJlp/1YybC63K', 'Jeffrey.Onas@pctdavao.edu.ph', 'instructor', NULL, 'Jeffrey', 'Oñas', '2026-03-28 17:34:47', NULL),
(38, 'ChristianGregorio', '$2y$10$b9.xHapnDaCxFT4E0uiWw.FY279Un8YSLBUqgdRh8.YMlqXK8dnw6', 'Christian.Gregorio@pctdavao.edu.ph', 'instructor', NULL, 'Christian', 'Gregorio', '2026-03-28 17:36:10', NULL),
(39, 'JohnRayJosol', '$2y$10$GERFil6m2Up6nZaUkOuGNu/RnEgRUvGWZumUjVM2t.G4F2tte6hP6', 'JohnRay.Josol@pctdavao.edu.ph', 'instructor', NULL, 'John Ray', 'Josol', '2026-03-28 17:37:28', NULL),
(40, 'MarissaAntig', '$2y$10$nnkbTcgSLo.4/d3cpfX.aOfiK6hA55wT36hXHn7UzzUJRf0ZKvMU.', 'Marissa.Antig@pctdavaoedu.ph', 'instructor', NULL, 'Marissa', 'Antig', '2026-03-28 17:39:56', NULL),
(42, 'NelsonBonifacio', '$2y$10$h9tx01hsHZxu4yX11KUwcuLATd2hG8PvOWV56MG4J2rFIAc/XFc8C', 'Nelson.Bonifacio@pctdavao.edu.ph', 'instructor', NULL, 'Nelson', 'Bonifacio', '2026-03-28 17:42:01', NULL),
(43, 'BryanJonesAntigua', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'BryanJones.Antigua@pctdavao.edu.ph', 'student', 3, 'Bryan Jones', 'Antigua', '2026-03-28 17:54:18', 'STU20260001'),
(44, 'DaphneShaneAvestruz', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'DaphneShane.Avestruz@pctdavao.edu.ph', 'student', 3, 'Daphne Shane', 'Avestruz', '2026-03-28 17:54:18', 'STU20260002'),
(45, 'DerickNathanBaguio', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'DerickNathan.Baguio@pctdavao.edu.ph', 'student', 3, 'Derick Nathan', 'Baguio', '2026-03-28 17:54:18', 'STU20260003'),
(46, 'GraceBalco', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Grace.Balco@pctdavao.edu.ph', 'student', 3, 'Grace', 'Balco', '2026-03-28 17:54:18', 'STU20260004'),
(47, 'JayveeBurla', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Jayvee.Burla@pctdavao.edu.ph', 'student', 3, 'Jayvee', 'Burla', '2026-03-28 17:54:18', 'STU20260005'),
(48, 'CarlitoJrCabusora', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'CarlitoJr.Cabusora@pctdavao.edu.ph', 'student', 3, 'Carlito Jr.', 'Cabusora', '2026-03-28 17:54:18', 'STU20260006'),
(49, 'CrizylCullamat', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Crizyl.Cullamat@pctdavao.edu.ph', 'student', 3, 'Crizyl', 'Cullamat', '2026-03-28 17:54:18', 'STU20260007'),
(50, 'MikaelaDelaCerna', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'MikaelaDela.Cerna@pctdavao.edu.ph', 'student', 3, 'Mikaela Dela', 'Cerna', '2026-03-28 17:54:18', 'STU20260008'),
(51, 'KateDumapas', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Kate.Dumapas@pctdavao.edu.ph', 'student', 3, 'Kate', 'Dumapas', '2026-03-28 17:54:18', 'STU20260009'),
(52, 'DenzielDhanEspejo', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'DenzielDhan.Espejo@pctdavao.edu.ph', 'student', 3, 'Denziel Dhan', 'Espejo', '2026-03-28 17:54:18', 'STU20260010'),
(53, 'AnalynGualdo', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Analyn.Gualdo@pctdavao.edu.ph', 'student', 3, 'Analyn', 'Gualdo', '2026-03-28 17:54:18', 'STU20260011'),
(54, 'AlvinDanielJundit', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'AlvinDaniel.Jundit@pctdavao.edu.ph', 'student', 3, 'Alvin Daniel', 'Jundit', '2026-03-28 17:54:18', 'STU20260012'),
(55, 'PatriciaPagilo', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Patricia.Pagilo@pctdavao.edu.ph', 'student', 3, 'Patricia', 'Pagilo', '2026-03-28 17:54:18', 'STU20260013'),
(56, 'ZhylKhyranRecana', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'ZhylKhyran.Recana@pctdavao.edu.ph', 'student', 3, 'Zhyl Khyran', 'Recaña', '2026-03-28 17:54:18', 'STU20260014'),
(57, 'ClarenceDaleRodriguez', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'ClarenceDale.Rodriguez@pctdavao.edu.ph', 'student', 3, 'Clarence Dale', 'Rodriguez', '2026-03-28 17:54:18', 'STU20260015'),
(58, 'PrincessAnnSaga', '$2y$10$bItkQMKCKBk8vZWFxILuKeZ1JscmnhFCpsyXokM3W7dqxySuEr6qm', 'PrincessAnn.Saga@pctdavao.edu.ph', 'student', 3, 'Princess Ann', 'Saga', '2026-03-28 17:54:18', 'STU20260016'),
(59, 'ShoaibaSahidjuan', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Shoaiba.Sahidjuan@pctdavao.edu.ph', 'student', 3, 'Shoaiba', 'Sahidjuan', '2026-03-28 17:54:18', 'STU20260017'),
(60, 'MudznaSalahi', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Mudzna.Salahi@pctdavao.edu.ph', 'student', 3, 'Mudzna', 'Salahi', '2026-03-28 17:54:18', 'STU20260018'),
(61, 'CedricSanJuan', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'Cedric.SanJuan@pctdavao.edu.ph', 'student', 3, 'Cedric', 'San Juan', '2026-03-28 17:54:18', 'STU20260019'),
(62, 'CesarIanSuela', '$2y$10$rjwBF/Jj5C.Pt0pt9PZH5Oija4dItZ7M3enltQ3meK2cZH7X9r4TG', 'CesarIan.Suela@pctdavao.edu.ph', 'student', 3, 'Cesar Ian', 'Suela', '2026-03-28 17:54:18', 'STU20260020');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `classrooms`
--
ALTER TABLE `classrooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `room_number` (`room_number`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `course_code` (`course_code`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`student_id`,`schedule_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `notification_state`
--
ALTER TABLE `notification_state`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `instructor_id` (`instructor_id`),
  ADD KEY `classroom_id` (`classroom_id`);

--
-- Indexes for table `schedule_templates`
--
ALTER TABLE `schedule_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `instructor_id` (`instructor_id`),
  ADD KEY `classroom_id` (`classroom_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_settings_key` (`key`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subject_code` (`subject_code`),
  ADD UNIQUE KEY `uq_subject_code` (`subject_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `classrooms`
--
ALTER TABLE `classrooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `schedule_templates`
--
ALTER TABLE `schedule_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30249;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=224;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`);

--
-- Constraints for table `notification_state`
--
ALTER TABLE `notification_state`
  ADD CONSTRAINT `notification_state_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`),
  ADD CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `schedules_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`);

--
-- Constraints for table `schedule_templates`
--
ALTER TABLE `schedule_templates`
  ADD CONSTRAINT `schedule_templates_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`),
  ADD CONSTRAINT `schedule_templates_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `schedule_templates_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`),
  ADD CONSTRAINT `schedule_templates_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
