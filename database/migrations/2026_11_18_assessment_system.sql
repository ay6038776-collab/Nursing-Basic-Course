-- Nursing Basic Education: additive assessment-system expansion.
-- Preserves the existing quizzes/questions/attempts and makes legacy question
-- rows available to the reusable question bank.
-- Targeted at the project's local MariaDB 10.4 installation.

-- Columns used by both existing API variants; keep canonical legacy fields intact.
ALTER TABLE quizzes
  ADD COLUMN IF NOT EXISTS title_ar VARCHAR(500) DEFAULT NULL AFTER title,
  ADD COLUMN IF NOT EXISTS title_en VARCHAR(500) DEFAULT NULL AFTER title_ar,
  ADD COLUMN IF NOT EXISTS description_ar TEXT DEFAULT NULL AFTER description,
  ADD COLUMN IF NOT EXISTS description_en TEXT DEFAULT NULL AFTER description_ar,
  ADD COLUMN IF NOT EXISTS is_published TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN IF NOT EXISTS pass_score DECIMAL(5,2) DEFAULT NULL AFTER pass_percent,
  ADD COLUMN IF NOT EXISTS shuffle_questions TINYINT(1) NOT NULL DEFAULT 0 AFTER randomize_questions,
  ADD COLUMN IF NOT EXISTS shuffle_options TINYINT(1) NOT NULL DEFAULT 0 AFTER randomize_options;

ALTER TABLE quizzes
  ADD COLUMN IF NOT EXISTS total_points DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER pass_percent,
  ADD COLUMN IF NOT EXISTS starts_at DATETIME DEFAULT NULL AFTER is_published,
  ADD COLUMN IF NOT EXISTS ends_at DATETIME DEFAULT NULL AFTER starts_at,
  ADD COLUMN IF NOT EXISTS negative_marking DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER ends_at,
  ADD COLUMN IF NOT EXISTS random_question_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER randomize_questions,
  ADD COLUMN IF NOT EXISTS auto_submit TINYINT(1) NOT NULL DEFAULT 1 AFTER randomize_options,
  ADD COLUMN IF NOT EXISTS navigation_mode ENUM('free','sequential') NOT NULL DEFAULT 'free' AFTER auto_submit,
  ADD COLUMN IF NOT EXISTS result_release ENUM('immediate','manual','scheduled') NOT NULL DEFAULT 'immediate' AFTER show_answers_after_submit,
  ADD COLUMN IF NOT EXISTS results_visible_at DATETIME DEFAULT NULL AFTER result_release,
  ADD COLUMN IF NOT EXISTS allowed_group_ids LONGTEXT DEFAULT NULL AFTER results_visible_at;

ALTER TABLE questions
  MODIFY COLUMN quiz_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN question_type ENUM('multiple_choice','mcq','multiple_select','true_false','short_answer','essay','matching','fill_blank','ordering','clinical_case','image_based') NOT NULL DEFAULT 'multiple_choice',
  ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED DEFAULT NULL AFTER quiz_id,
  ADD COLUMN IF NOT EXISTS course_id BIGINT UNSIGNED DEFAULT NULL AFTER created_by,
  ADD COLUMN IF NOT EXISTS specialty VARCHAR(160) DEFAULT NULL AFTER course_id,
  ADD COLUMN IF NOT EXISTS difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium' AFTER points,
  ADD COLUMN IF NOT EXISTS tags_json LONGTEXT DEFAULT NULL AFTER explanation,
  ADD COLUMN IF NOT EXISTS image_path VARCHAR(500) DEFAULT NULL AFTER tags_json,
  ADD COLUMN IF NOT EXISTS attachments_json LONGTEXT DEFAULT NULL AFTER image_path,
  ADD COLUMN IF NOT EXISTS answer_key_json LONGTEXT DEFAULT NULL AFTER attachments_json,
  ADD COLUMN IF NOT EXISTS grading_rubric TEXT DEFAULT NULL AFTER answer_key_json,
  ADD COLUMN IF NOT EXISTS status ENUM('draft','approved','archived') NOT NULL DEFAULT 'draft' AFTER grading_rubric,
  ADD COLUMN IF NOT EXISTS origin ENUM('manual','import','ai','legacy') NOT NULL DEFAULT 'manual' AFTER status;

UPDATE questions q
LEFT JOIN quizzes z ON z.id = q.quiz_id
SET q.created_by = COALESCE(q.created_by, z.created_by),
    q.course_id = COALESCE(q.course_id, z.course_id),
    q.status = CASE WHEN q.status = 'draft' AND q.origin = 'manual' THEN 'approved' ELSE q.status END,
    q.origin = CASE WHEN q.origin = 'manual' THEN 'legacy' ELSE q.origin END
WHERE q.quiz_id IS NOT NULL;

ALTER TABLE quiz_attempts
  MODIFY COLUMN status ENUM('in_progress','submitted','completed','graded','passed','failed','expired') NOT NULL DEFAULT 'in_progress',
  ADD COLUMN IF NOT EXISTS expires_at DATETIME DEFAULT NULL AFTER started_at,
  ADD COLUMN IF NOT EXISTS time_spent_seconds INT UNSIGNED DEFAULT NULL AFTER submitted_at,
  ADD COLUMN IF NOT EXISTS results_released_at DATETIME DEFAULT NULL AFTER time_spent_seconds,
  ADD COLUMN IF NOT EXISTS session_hash CHAR(64) DEFAULT NULL AFTER results_released_at,
  ADD COLUMN IF NOT EXISTS visibility_loss_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER session_hash,
  ADD COLUMN IF NOT EXISTS client_ip VARBINARY(16) DEFAULT NULL AFTER visibility_loss_count,
  ADD COLUMN IF NOT EXISTS client_user_agent VARCHAR(500) DEFAULT NULL AFTER client_ip;

ALTER TABLE quiz_answers
  ADD COLUMN IF NOT EXISTS answer_json LONGTEXT DEFAULT NULL AFTER answer_text,
  ADD COLUMN IF NOT EXISTS points_earned DECIMAL(8,2) DEFAULT NULL AFTER awarded_points,
  ADD COLUMN IF NOT EXISTS lecturer_feedback TEXT DEFAULT NULL AFTER points_earned,
  ADD COLUMN IF NOT EXISTS grading_status ENUM('auto_graded','needs_review','ai_suggested','approved') NOT NULL DEFAULT 'auto_graded' AFTER lecturer_feedback,
  ADD COLUMN IF NOT EXISTS graded_by BIGINT UNSIGNED DEFAULT NULL AFTER grading_status,
  ADD COLUMN IF NOT EXISTS graded_at DATETIME DEFAULT NULL AFTER graded_by;

ALTER TABLE quiz_options
  ADD COLUMN IF NOT EXISTS match_value VARCHAR(1000) DEFAULT NULL AFTER option_text,
  ADD COLUMN IF NOT EXISTS image_path VARCHAR(500) DEFAULT NULL AFTER match_value,
  ADD COLUMN IF NOT EXISTS metadata_json LONGTEXT DEFAULT NULL AFTER image_path;

CREATE TABLE IF NOT EXISTS quiz_question_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 1,
  points_override DECIMAL(8,2) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quiz_question (quiz_id, question_id),
  KEY idx_quiz_question_order (quiz_id, sort_order),
  CONSTRAINT fk_qqm_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_qqm_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  description VARCHAR(500) DEFAULT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assessment_group_course_name (course_id, name),
  KEY idx_assessment_group_owner (created_by, course_id),
  CONSTRAINT fk_ag_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ag_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_group_members (
  group_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, student_id),
  KEY idx_agm_student (student_id, group_id),
  CONSTRAINT fk_agm_group FOREIGN KEY (group_id) REFERENCES assessment_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_agm_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_group_map (
  quiz_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (quiz_id, group_id),
  CONSTRAINT fk_qgm_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_qgm_group FOREIGN KEY (group_id) REFERENCES assessment_groups(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempt_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 1,
  points DECIMAL(8,2) NOT NULL DEFAULT 1,
  marked_for_review TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt_question_snapshot (attempt_id, question_id),
  KEY idx_attempt_question_order (attempt_id, sort_order),
  CONSTRAINT fk_qaq_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_qaq_question FOREIGN KEY (question_id) REFERENCES questions(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE quiz_attempt_questions
  ADD COLUMN IF NOT EXISTS marked_for_review TINYINT(1) NOT NULL DEFAULT 0 AFTER points;

CREATE TABLE IF NOT EXISTS assessment_visibility_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('hidden','visible','blur','focus','reconnected') NOT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  details_json LONGTEXT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_visibility_attempt_time (attempt_id, occurred_at),
  CONSTRAINT fk_ave_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ave_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_ai_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  instructor_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED DEFAULT NULL,
  source_file_path VARCHAR(500) DEFAULT NULL,
  source_text LONGTEXT DEFAULT NULL,
  generation_config LONGTEXT NOT NULL,
  status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  error_message VARCHAR(1000) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_jobs_owner_status (instructor_id, status, created_at),
  CONSTRAINT fk_aij_instructor FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_aij_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_event_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED DEFAULT NULL,
  event_key VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED DEFAULT NULL,
  details_json LONGTEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assessment_log_entity (entity_type, entity_id, created_at),
  KEY idx_assessment_log_actor (actor_user_id, created_at),
  CONSTRAINT fk_ael_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill legacy quiz-bound questions into the reusable relationship table.
INSERT IGNORE INTO quiz_question_map (quiz_id, question_id, sort_order, points_override)
SELECT quiz_id, id, sort_order, points FROM questions WHERE quiz_id IS NOT NULL;

-- Normalize legacy publication/status flags without altering attempt records.
UPDATE quizzes SET title_ar = COALESCE(NULLIF(title_ar, ''), title),
                   title_en = COALESCE(NULLIF(title_en, ''), title),
                   description_ar = COALESCE(description_ar, description),
                   pass_score = COALESCE(pass_score, pass_percent),
                   shuffle_questions = randomize_questions,
                   shuffle_options = randomize_options,
                   is_published = IF(status = 'published', 1, 0);
UPDATE quizzes SET total_points = COALESCE((SELECT SUM(points) FROM questions WHERE questions.quiz_id = quizzes.id), 0)
WHERE total_points = 0;
UPDATE quiz_answers SET points_earned = awarded_points WHERE points_earned IS NULL AND awarded_points IS NOT NULL;

