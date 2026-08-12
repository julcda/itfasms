-- =====================================================================
-- ITFA I-SMS  ·  LMS (Classroom) tables — ONLINE migration
-- Creates the 21 classroom_* tables in the live thrgd534_grading DB.
-- Safe to re-run (CREATE TABLE IF NOT EXISTS). No Laravel migrations row.
-- Generated 2026-07-29. Run in phpMyAdmin (SQL tab) on the live DB.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `classroom_lessons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `grading_period_id` int(11) DEFAULT NULL,
  `week_number` smallint(5) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `learning_competency` text DEFAULT NULL,
  `objectives` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `publish_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_lessons_class_id_status_index` (`class_id`,`status`),
  KEY `classroom_lessons_class_id_sort_order_index` (`class_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_lesson_resources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lesson_id` bigint(20) unsigned NOT NULL,
  `type` enum('video_upload','video_youtube','video_vimeo','video_gdrive','document','image','link') NOT NULL,
  `title` varchar(255) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_lesson_resources_lesson_id_sort_order_index` (`lesson_id`,`sort_order`),
  CONSTRAINT `classroom_lesson_resources_lesson_id_foreign` FOREIGN KEY (`lesson_id`) REFERENCES `classroom_lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_lesson_progress` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lesson_id` bigint(20) unsigned NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
  `progress_percent` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_viewed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classroom_lesson_progress_lesson_id_student_id_unique` (`lesson_id`,`student_id`),
  CONSTRAINT `classroom_lesson_progress_lesson_id_foreign` FOREIGN KEY (`lesson_id`) REFERENCES `classroom_lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `lesson_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `max_score` smallint(5) unsigned NOT NULL DEFAULT 100,
  `allow_late` tinyint(1) NOT NULL DEFAULT 1,
  `submission_mode` enum('individual','group') NOT NULL DEFAULT 'individual',
  `require_file` tinyint(1) NOT NULL DEFAULT 1,
  `require_text` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_assignments_lesson_id_foreign` (`lesson_id`),
  KEY `classroom_assignments_class_id_status_index` (`class_id`,`status`),
  CONSTRAINT `classroom_assignments_lesson_id_foreign` FOREIGN KEY (`lesson_id`) REFERENCES `classroom_lessons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_assignment_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint(20) unsigned NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_assignment_attachments_assignment_id_foreign` (`assignment_id`),
  CONSTRAINT `classroom_assignment_attachments_assignment_id_foreign` FOREIGN KEY (`assignment_id`) REFERENCES `classroom_assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_assignment_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint(20) unsigned NOT NULL,
  `student_id` int(11) NOT NULL,
  `text_answer` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','late','missing','returned','graded') NOT NULL DEFAULT 'draft',
  `score` decimal(6,2) DEFAULT NULL,
  `teacher_comment` text DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cas_assignment_student_unique` (`assignment_id`,`student_id`),
  CONSTRAINT `classroom_assignment_submissions_assignment_id_foreign` FOREIGN KEY (`assignment_id`) REFERENCES `classroom_assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_assignment_submission_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_assignment_submission_files_submission_id_foreign` (`submission_id`),
  CONSTRAINT `classroom_assignment_submission_files_submission_id_foreign` FOREIGN KEY (`submission_id`) REFERENCES `classroom_assignment_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_quizzes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `lesson_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `time_limit_minutes` smallint(5) unsigned DEFAULT NULL,
  `passing_score` decimal(6,2) DEFAULT NULL,
  `shuffle_questions` tinyint(1) NOT NULL DEFAULT 0,
  `shuffle_choices` tinyint(1) NOT NULL DEFAULT 0,
  `max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `show_score_immediately` tinyint(1) NOT NULL DEFAULT 1,
  `show_correct_answers` tinyint(1) NOT NULL DEFAULT 0,
  `available_from` datetime DEFAULT NULL,
  `available_until` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `auto_submit` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_quizzes_lesson_id_foreign` (`lesson_id`),
  KEY `classroom_quizzes_class_id_status_index` (`class_id`,`status`),
  CONSTRAINT `classroom_quizzes_lesson_id_foreign` FOREIGN KEY (`lesson_id`) REFERENCES `classroom_lessons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_quiz_questions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `quiz_id` bigint(20) unsigned NOT NULL,
  `type` enum('mcq','multi_select','true_false','identification','short_answer','essay','matching','ordering','fill_blank') NOT NULL,
  `question_text` text NOT NULL,
  `points` decimal(6,2) NOT NULL DEFAULT 1.00,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_quiz_questions_quiz_id_sort_order_index` (`quiz_id`,`sort_order`),
  CONSTRAINT `classroom_quiz_questions_quiz_id_foreign` FOREIGN KEY (`quiz_id`) REFERENCES `classroom_quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_quiz_choices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `question_id` bigint(20) unsigned NOT NULL,
  `choice_text` varchar(255) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `match_key` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_quiz_choices_question_id_sort_order_index` (`question_id`,`sort_order`),
  CONSTRAINT `classroom_quiz_choices_question_id_foreign` FOREIGN KEY (`question_id`) REFERENCES `classroom_quiz_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_quiz_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `quiz_id` bigint(20) unsigned NOT NULL,
  `student_id` int(11) NOT NULL,
  `attempt_number` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `started_at` datetime NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `is_auto_submitted` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('in_progress','submitted','graded') NOT NULL DEFAULT 'in_progress',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_quiz_attempts_quiz_id_student_id_index` (`quiz_id`,`student_id`),
  CONSTRAINT `classroom_quiz_attempts_quiz_id_foreign` FOREIGN KEY (`quiz_id`) REFERENCES `classroom_quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_quiz_answers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attempt_id` bigint(20) unsigned NOT NULL,
  `question_id` bigint(20) unsigned NOT NULL,
  `answer_text` text DEFAULT NULL,
  `selected_choice_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`selected_choice_ids`)),
  `is_correct` tinyint(1) DEFAULT NULL,
  `points_awarded` decimal(6,2) DEFAULT NULL,
  `teacher_feedback` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classroom_quiz_answers_attempt_id_question_id_unique` (`attempt_id`,`question_id`),
  KEY `classroom_quiz_answers_question_id_foreign` (`question_id`),
  CONSTRAINT `classroom_quiz_answers_attempt_id_foreign` FOREIGN KEY (`attempt_id`) REFERENCES `classroom_quiz_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `classroom_quiz_answers_question_id_foreign` FOREIGN KEY (`question_id`) REFERENCES `classroom_quiz_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_announcements_class_id_index` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_announcement_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` bigint(20) unsigned NOT NULL,
  `type` enum('file','image','link') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_announcement_attachments_announcement_id_foreign` (`announcement_id`),
  CONSTRAINT `classroom_announcement_attachments_announcement_id_foreign` FOREIGN KEY (`announcement_id`) REFERENCES `classroom_announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_discussion_threads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `lesson_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `author_role` enum('teacher','student') NOT NULL,
  `author_id` int(11) NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_discussion_threads_class_id_is_pinned_index` (`class_id`,`is_pinned`),
  KEY `classroom_discussion_threads_lesson_id_index` (`lesson_id`),
  CONSTRAINT `classroom_discussion_threads_lesson_id_foreign` FOREIGN KEY (`lesson_id`) REFERENCES `classroom_lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_discussion_replies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` bigint(20) unsigned NOT NULL,
  `body` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `author_role` enum('teacher','student') NOT NULL,
  `author_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classroom_discussion_replies_thread_id_index` (`thread_id`),
  CONSTRAINT `classroom_discussion_replies_thread_id_foreign` FOREIGN KEY (`thread_id`) REFERENCES `classroom_discussion_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_discussion_likes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reply_id` bigint(20) unsigned NOT NULL,
  `author_role` enum('teacher','student') NOT NULL,
  `author_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cdl_reply_author_unique` (`reply_id`,`author_role`,`author_id`),
  CONSTRAINT `classroom_discussion_likes_reply_id_foreign` FOREIGN KEY (`reply_id`) REFERENCES `classroom_discussion_replies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `class_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `classroom_notifications_type_index` (`type`),
  KEY `classroom_notifications_class_id_index` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_notification_reads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` bigint(20) unsigned NOT NULL,
  `recipient_role` enum('teacher','student') NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `classroom_notification_reads_notification_id_foreign` (`notification_id`),
  KEY `cnr_recipient_read_idx` (`recipient_role`,`recipient_id`,`read_at`),
  CONSTRAINT `classroom_notification_reads_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `classroom_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_grade_integration` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `grading_period_id` int(11) NOT NULL,
  `source_type` enum('assignment','quiz','performance_task') NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `score` decimal(6,2) NOT NULL,
  `max_score` decimal(6,2) NOT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `synced_to_student_grade` tinyint(1) NOT NULL DEFAULT 0,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cgi_source_student_unique` (`source_type`,`source_id`,`student_id`),
  KEY `cgi_class_student_period_idx` (`class_id`,`student_id`,`grading_period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `classroom_sso_tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket` varchar(64) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `redirect_path` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `classroom_sso_tickets_ticket_unique` (`ticket`),
  KEY `classroom_sso_tickets_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
