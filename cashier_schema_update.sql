CREATE TABLE IF NOT EXISTS `student_account` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` int(11) NOT NULL,
  `student_id` varchar(100) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `payment_method` varchar(20) NOT NULL DEFAULT 'Installment',
  `tuition_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `school_improvement` decimal(10,2) NOT NULL DEFAULT 0.00,
  `enrollment_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `monthly_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `installment_months` int(11) NOT NULL DEFAULT 0,
  `total_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_account_enrollment` (`enrollment_id`),
  KEY `idx_student_account_student_id` (`student_id`),
  KEY `idx_student_account_school_year` (`school_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `monthly_payment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_account_id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `student_id` varchar(100) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `month_label` varchar(50) NOT NULL,
  `payment_month` int(11) NOT NULL,
  `payment_year` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `amount_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `or_number` varchar(50) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `cashier_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_monthly_payment_account` (`student_account_id`),
  KEY `idx_monthly_payment_enrollment` (`enrollment_id`),
  KEY `idx_monthly_payment_student` (`student_id`),
  KEY `idx_monthly_payment_school_year` (`school_year`),
  KEY `idx_monthly_payment_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `backaccount_payment_records`
  ADD COLUMN IF NOT EXISTS `or_number` varchar(50) DEFAULT NULL AFTER `payment_amount`,
  ADD COLUMN IF NOT EXISTS `payment_method` varchar(20) DEFAULT NULL AFTER `or_number`,
  ADD COLUMN IF NOT EXISTS `school_year` varchar(20) DEFAULT NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `cashier_name` varchar(255) DEFAULT NULL AFTER `school_year`,
  ADD COLUMN IF NOT EXISTS `enrollment_id` int(11) DEFAULT NULL AFTER `cashier_name`;

ALTER TABLE `backaccount_payment_records`
  ADD KEY `idx_bapr_student_id` (`student_id`),
  ADD KEY `idx_bapr_school_year` (`school_year`),
  ADD KEY `idx_bapr_enrollment_id` (`enrollment_id`);