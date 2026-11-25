-- Nursing Basic Course: additive LMS schema migration
-- Safe intent: CREATE TABLE IF NOT EXISTS only; does not DROP or replace existing tables/data.
-- Target: MySQL 8+ / MariaDB 10.4+; database nursing_basic_course, utf8mb4.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS `profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `display_name` VARCHAR(150) DEFAULT NULL,
  `headline` VARCHAR(200) DEFAULT NULL,
  `locale` VARCHAR(10) NOT NULL DEFAULT 'ar',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
  `preferences_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_profiles_user` (`user_id`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('admin','instructor','student','support') NOT NULL,
  `assigned_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_user_role` (`user_id`,`role`), KEY `idx_user_roles_role` (`role`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_instructors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `instructor_id` BIGINT UNSIGNED NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_course_instructor` (`course_id`,`instructor_id`),
  KEY `idx_course_instructors_instructor` (`instructor_id`),
  CONSTRAINT `fk_course_instructors_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_course_instructors_user` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_course_instructors_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_sections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_sections_course_order` (`course_id`,`sort_order`),
  CONSTRAINT `fk_sections_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapping table lets existing legacy lessons remain intact while assigning them to new sections.
CREATE TABLE IF NOT EXISTS `course_section_lessons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id` BIGINT UNSIGNED NOT NULL,
  `lesson_source` ENUM('lessons','course_lessons') NOT NULL,
  `lesson_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_section_lesson_source` (`section_id`,`lesson_source`,`lesson_id`),
  KEY `idx_section_lesson_lookup` (`lesson_source`,`lesson_id`),
  CONSTRAINT `fk_section_lesson_section` FOREIGN KEY (`section_id`) REFERENCES `course_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `enrollment_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'EGP',
  `payment_method` ENUM('manual_transfer','instapay','wallet','card','bank_transfer','other') NOT NULL DEFAULT 'manual_transfer',
  `status` ENUM('pending','under_review','confirmed','rejected','refunded','failed') NOT NULL DEFAULT 'pending',
  `transaction_reference` VARCHAR(190) DEFAULT NULL,
  `proof_path` VARCHAR(1000) DEFAULT NULL,
  `provider_reference` VARCHAR(190) DEFAULT NULL,
  `reviewed_by` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_note` VARCHAR(1000) DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_payments_student_status` (`student_id`,`status`),
  KEY `idx_payments_course_status` (`course_id`,`status`), KEY `idx_payments_created` (`created_at`),
  UNIQUE KEY `uq_payment_provider_reference` (`provider_reference`),
  CONSTRAINT `fk_payments_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(80) NOT NULL,
  `old_status` VARCHAR(30) DEFAULT NULL,
  `new_status` VARCHAR(30) DEFAULT NULL,
  `actor_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `provider_event_id` VARCHAR(190) DEFAULT NULL,
  `payload_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_payment_events_payment` (`payment_id`,`created_at`),
  UNIQUE KEY `uq_payment_provider_event` (`provider_event_id`),
  CONSTRAINT `fk_payment_events_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_events_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quizzes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `section_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `time_limit_minutes` SMALLINT UNSIGNED DEFAULT NULL,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `pass_percent` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  `randomize_questions` TINYINT(1) NOT NULL DEFAULT 0,
  `randomize_options` TINYINT(1) NOT NULL DEFAULT 0,
  `show_answers_after_submit` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_quizzes_course_status` (`course_id`,`status`),
  CONSTRAINT `fk_quizzes_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_quizzes_section` FOREIGN KEY (`section_id`) REFERENCES `course_sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_quizzes_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_id` BIGINT UNSIGNED NOT NULL,
  `question_text` TEXT NOT NULL,
  `question_type` ENUM('multiple_choice','true_false','short_answer') NOT NULL DEFAULT 'multiple_choice',
  `points` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  `explanation` TEXT DEFAULT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_questions_quiz_order` (`quiz_id`,`sort_order`),
  CONSTRAINT `fk_questions_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quiz_options` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `option_text` TEXT NOT NULL,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), KEY `idx_quiz_options_question` (`question_id`,`sort_order`),
  CONSTRAINT `fk_quiz_options_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quiz_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_id` BIGINT UNSIGNED NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` DATETIME DEFAULT NULL,
  `score` DECIMAL(8,2) DEFAULT NULL,
  `max_score` DECIMAL(8,2) DEFAULT NULL,
  `percent_score` DECIMAL(5,2) DEFAULT NULL,
  `passed` TINYINT(1) DEFAULT NULL,
  `status` ENUM('in_progress','submitted','graded','expired') NOT NULL DEFAULT 'in_progress',
  PRIMARY KEY (`id`), UNIQUE KEY `uq_quiz_student_attempt` (`quiz_id`,`student_id`,`attempt_number`),
  KEY `idx_attempt_student_status` (`student_id`,`status`),
  CONSTRAINT `fk_attempts_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attempts_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quiz_answers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attempt_id` BIGINT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `selected_option_id` BIGINT UNSIGNED DEFAULT NULL,
  `answer_text` TEXT DEFAULT NULL,
  `is_correct` TINYINT(1) DEFAULT NULL,
  `awarded_points` DECIMAL(8,2) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_attempt_question` (`attempt_id`,`question_id`),
  CONSTRAINT `fk_answers_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_answers_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_answers_option` FOREIGN KEY (`selected_option_id`) REFERENCES `quiz_options` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `certificate_number` VARCHAR(100) NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `enrollment_id` BIGINT UNSIGNED DEFAULT NULL,
  `student_name_snapshot` VARCHAR(150) NOT NULL,
  `course_title_snapshot` VARCHAR(200) NOT NULL,
  `issuer_name` VARCHAR(200) NOT NULL DEFAULT 'Nursing Basic Course',
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME DEFAULT NULL,
  `verification_token_hash` CHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_certificate_number` (`certificate_number`),
  UNIQUE KEY `uq_certificate_enrollment` (`enrollment_id`),
  KEY `idx_certificates_student` (`student_id`),
  CONSTRAINT `fk_certificates_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_certificates_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_certificates_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,
  `target_role` ENUM('all','admin','instructor','student') NOT NULL DEFAULT 'all',
  `course_id` BIGINT UNSIGNED DEFAULT NULL,
  `publish_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_announcements_target_status` (`target_role`,`status`,`publish_at`),
  CONSTRAINT `fk_announcements_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_announcements_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `announcement_id` BIGINT UNSIGNED DEFAULT NULL,
  `type` VARCHAR(80) NOT NULL DEFAULT 'system',
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `action_url` VARCHAR(1000) DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_notifications_user_read` (`user_id`,`read_at`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(40) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `assigned_to` BIGINT UNSIGNED DEFAULT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `category` ENUM('account','course','payment','technical','other') NOT NULL DEFAULT 'other',
  `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status` ENUM('open','in_progress','waiting_user','resolved','closed') NOT NULL DEFAULT 'open',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ticket_number` (`ticket_number`),
  KEY `idx_tickets_user_status` (`user_id`,`status`), KEY `idx_tickets_assignee_status` (`assigned_to`,`status`),
  CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `message` TEXT NOT NULL,
  `attachment_path` VARCHAR(1000) DEFAULT NULL,
  `is_internal_note` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_support_messages_ticket` (`ticket_id`,`created_at`),
  CONSTRAINT `fk_support_messages_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_support_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(120) NOT NULL,
  `entity_type` VARCHAR(100) NOT NULL,
  `entity_id` VARCHAR(100) DEFAULT NULL,
  `ip_address` VARBINARY(16) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `details_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_audit_actor_date` (`actor_user_id`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional operational tables required by the full LMS brief.
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_password_reset_token` (`token_hash`),
  KEY `idx_password_reset_user_expiry` (`user_id`,`expires_at`),
  CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_verification_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_email_verification_token` (`token_hash`),
  KEY `idx_email_verification_user` (`user_id`),
  CONSTRAINT `fk_email_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_coupons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `course_id` BIGINT UNSIGNED DEFAULT NULL,
  `discount_type` ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL,
  `max_redemptions` INT UNSIGNED DEFAULT NULL,
  `redeemed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `starts_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_coupon_code` (`code`),
  CONSTRAINT `fk_coupon_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `payment_id` BIGINT UNSIGNED DEFAULT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_coupon_user_payment` (`coupon_id`,`user_id`,`payment_id`),
  CONSTRAINT `fk_coupon_redemptions_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `course_coupons` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_redemptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_redemptions_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `section_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `instructions` TEXT NOT NULL,
  `due_at` DATETIME DEFAULT NULL,
  `max_points` DECIMAL(8,2) NOT NULL DEFAULT 100.00,
  `status` ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_assignments_course_status` (`course_id`,`status`),
  CONSTRAINT `fk_assignments_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_section` FOREIGN KEY (`section_id`) REFERENCES `course_sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assignment_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` BIGINT UNSIGNED NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `submission_text` LONGTEXT DEFAULT NULL,
  `file_path` VARCHAR(1000) DEFAULT NULL,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `grade` DECIMAL(8,2) DEFAULT NULL,
  `feedback` TEXT DEFAULT NULL,
  `graded_by` BIGINT UNSIGNED DEFAULT NULL,
  `graded_at` DATETIME DEFAULT NULL,
  `status` ENUM('submitted','late','graded','returned') NOT NULL DEFAULT 'submitted',
  PRIMARY KEY (`id`), UNIQUE KEY `uq_assignment_student` (`assignment_id`,`student_id`),
  KEY `idx_submissions_student` (`student_id`,`submitted_at`),
  CONSTRAINT `fk_submissions_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_grader` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `live_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `meeting_url` VARCHAR(1000) DEFAULT NULL,
  `status` ENUM('scheduled','live','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_live_sessions_course_start` (`course_id`,`starts_at`),
  CONSTRAINT `fk_live_sessions_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_live_sessions_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `live_session_id` BIGINT UNSIGNED NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('present','late','absent','excused') NOT NULL DEFAULT 'present',
  `joined_at` DATETIME DEFAULT NULL,
  `left_at` DATETIME DEFAULT NULL,
  `recorded_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_attendance_session_student` (`live_session_id`,`student_id`),
  CONSTRAINT `fk_attendance_session` FOREIGN KEY (`live_session_id`) REFERENCES `live_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nursing_skill_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name_ar` VARCHAR(160) NOT NULL,
  `name_en` VARCHAR(160) DEFAULT NULL,
  `slug` VARCHAR(180) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_skill_category_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nursing_skills` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `title_ar` VARCHAR(200) NOT NULL,
  `title_en` VARCHAR(200) DEFAULT NULL,
  `slug` VARCHAR(220) NOT NULL,
  `objectives` TEXT DEFAULT NULL,
  `tools_required` TEXT DEFAULT NULL,
  `procedure_steps` LONGTEXT DEFAULT NULL,
  `safety_precautions` TEXT DEFAULT NULL,
  `common_errors` TEXT DEFAULT NULL,
  `review_questions_json` JSON DEFAULT NULL,
  `reviewed_by` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_nursing_skill_slug` (`slug`),
  KEY `idx_nursing_skills_category_status` (`category_id`,`status`),
  CONSTRAINT `fk_nursing_skills_category` FOREIGN KEY (`category_id`) REFERENCES `nursing_skill_categories` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_nursing_skills_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nursing_skills_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nursing_skill_resources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skill_id` BIGINT UNSIGNED NOT NULL,
  `resource_type` ENUM('image','video','document','link') NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `resource_path` VARCHAR(1000) NOT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_skill_resources_skill` (`skill_id`),
  CONSTRAINT `fk_skill_resources_skill` FOREIGN KEY (`skill_id`) REFERENCES `nursing_skills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_settings` (
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `is_sensitive` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`),
  CONSTRAINT `fk_platform_settings_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `enrollment_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `enrollment_id` BIGINT UNSIGNED NOT NULL,
  `old_status` VARCHAR(30) DEFAULT NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `actor_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `reason` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_enrollment_events_enrollment` (`enrollment_id`,`created_at`),
  CONSTRAINT `fk_enrollment_events_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollment_events_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Extend existing tables in place (MariaDB 10.4 supports ADD COLUMN IF NOT EXISTS).
-- These additions preserve existing columns and rows.
ALTER TABLE `courses`
  ADD COLUMN IF NOT EXISTS `title_ar` VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `title_en` VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `description_en` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `discounted_price` DECIMAL(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `duration_minutes` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `prerequisites` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `learning_outcomes` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `starts_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ends_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_seats` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `completion_rules_json` JSON DEFAULT NULL;

ALTER TABLE `lessons`
  ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `content` LONGTEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `duration_minutes` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_preview` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `status` ENUM('draft','published') NOT NULL DEFAULT 'draft',
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `enrollments`
  ADD COLUMN IF NOT EXISTS `starts_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `expires_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `access_status` ENUM('allowed','blocked','expired') NOT NULL DEFAULT 'allowed',
  ADD COLUMN IF NOT EXISTS `cancelled_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_by` BIGINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `lesson_progress`
  ADD COLUMN IF NOT EXISTS `last_position_seconds` INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `course_media`
  ADD COLUMN IF NOT EXISTS `access_level` ENUM('public','enrolled','instructors_admin') NOT NULL DEFAULT 'enrolled';

-- Backfill bilingual fields from the existing single-language values.
UPDATE `courses`
SET `title_ar` = COALESCE(`title_ar`, `title`),
    `description_en` = `description`
WHERE `title_ar` IS NULL OR `description_en` IS NULL;

-- Backfill role/profile relationships from the existing users table without deleting or changing users.
INSERT IGNORE INTO `user_roles` (`user_id`, `role`)
SELECT `id`, `role` FROM `users`;

INSERT IGNORE INTO `profiles` (`user_id`, `display_name`, `locale`)
SELECT `id`, `full_name`, 'ar' FROM `users`;

INSERT IGNORE INTO `course_instructors` (`course_id`, `instructor_id`, `is_primary`)
SELECT `id`, `instructor_id`, 1 FROM `courses`;

-- Existing application uses both `lessons` and `course_lessons`. Preserve both.
-- Create one starter section for courses only when no section exists yet.
INSERT INTO `course_sections` (`course_id`, `title`, `description`, `sort_order`, `status`)
SELECT c.`id`, 'الوحدة الأولى', 'قسم افتراضي لنقل الدروس تدريجيًا دون حذف البيانات القديمة.', 1, 'draft'
FROM `courses` c
WHERE NOT EXISTS (SELECT 1 FROM `course_sections` s WHERE s.`course_id` = c.`id`);
