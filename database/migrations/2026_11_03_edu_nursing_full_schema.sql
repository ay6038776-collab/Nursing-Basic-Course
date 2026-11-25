-- =========================================================================
-- Edu Nursing – Medical Education Academy (أكاديمية د. خالد عبد الناصر)
-- Database Migration: 2026_11_03_edu_nursing_full_schema.sql
-- =========================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1) Books Table (المكتبة الطبية)
CREATE TABLE IF NOT EXISTS `books` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title_ar` VARCHAR(255) NOT NULL,
  `title_en` VARCHAR(255) NOT NULL,
  `author` VARCHAR(190) NOT NULL DEFAULT 'د. خالد عبد الناصر محمد المرسي',
  `description_ar` TEXT NOT NULL,
  `description_en` TEXT DEFAULT NULL,
  `cover_image` VARCHAR(500) DEFAULT NULL,
  `preview_images_json` JSON DEFAULT NULL,
  `type` ENUM('printed', 'digital', 'both') NOT NULL DEFAULT 'both',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_price` DECIMAL(10,2) DEFAULT NULL,
  `stock` INT NOT NULL DEFAULT 50,
  `pages_count` INT UNSIGNED DEFAULT 150,
  `pdf_path` VARCHAR(1000) DEFAULT NULL,
  `preview_pdf_path` VARCHAR(1000) DEFAULT NULL,
  `is_downloadable` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published',
  `created_by` BIGINT UNSIGNED DEFAULT 10,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_books_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Orders Table (سلة الشراء والطلبات الموحدة)
CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number` VARCHAR(50) NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `shipping_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'EGP',
  `payment_method` ENUM('vodafone_cash', 'instapay', 'card', 'manual_transfer') NOT NULL DEFAULT 'vodafone_cash',
  `status` ENUM('pending_payment', 'pending_review', 'paid', 'in_preparation', 'shipped', 'delivered', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending_payment',
  `transaction_reference` VARCHAR(190) DEFAULT NULL,
  `proof_path` VARCHAR(1000) DEFAULT NULL,
  `shipping_name` VARCHAR(190) DEFAULT NULL,
  `shipping_phone` VARCHAR(30) DEFAULT NULL,
  `shipping_address` TEXT DEFAULT NULL,
  `shipping_city` VARCHAR(100) DEFAULT NULL,
  `tracking_number` VARCHAR(100) DEFAULT NULL,
  `admin_notes` TEXT DEFAULT NULL,
  `reviewed_by` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_number` (`order_number`),
  KEY `idx_orders_student` (`student_id`),
  KEY `idx_orders_status` (`status`),
  CONSTRAINT `fk_orders_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Order Items Table
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `item_type` ENUM('course', 'book') NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `title_snapshot` VARCHAR(255) NOT NULL,
  `price_snapshot` DECIMAL(10,2) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Invoices Table (الفواتير الاحترافية)
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(50) NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'EGP',
  `status` ENUM('paid', 'refunded', 'cancelled') NOT NULL DEFAULT 'paid',
  `invoice_date` DATE NOT NULL,
  `invoice_html` MEDIUMTEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`invoice_number`),
  KEY `idx_invoices_order` (`order_id`),
  KEY `idx_invoices_student` (`student_id`),
  CONSTRAINT `fk_invoices_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoices_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Book Access Table (مكتبة الطالب الرقمية)
CREATE TABLE IF NOT EXISTS `book_access` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `book_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED DEFAULT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_book` (`student_id`, `book_id`),
  CONSTRAINT `fk_book_access_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_book_access_book` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Reviews Table (التقييمات المعتمدة)
CREATE TABLE IF NOT EXISTS `reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `item_type` ENUM('course', 'book') NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `comment` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_item` (`item_type`, `item_id`),
  KEY `idx_reviews_status` (`status`),
  CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Homepage CMS Sections (إدارة أقسام وتخطيط الصفحة الرئيسية)
CREATE TABLE IF NOT EXISTS `homepage_sections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_key` VARCHAR(80) NOT NULL,
  `title_ar` VARCHAR(255) NOT NULL,
  `title_en` VARCHAR(255) DEFAULT NULL,
  `subtitle_ar` TEXT DEFAULT NULL,
  `subtitle_en` TEXT DEFAULT NULL,
  `content_json` JSON DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_section_key` (`section_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Policy Pages (إدارة صفحات السياسات)
CREATE TABLE IF NOT EXISTS `policy_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(60) NOT NULL,
  `title_ar` VARCHAR(200) NOT NULL,
  `title_en` VARCHAR(200) NOT NULL,
  `content_ar` LONGTEXT NOT NULL,
  `content_en` LONGTEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_policy_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) Seed Platform Settings with Edu Nursing Identity
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `is_sensitive`, `updated_at`)
VALUES
  ('academy_name', 'Edu Nursing', 0, NOW()),
  ('academy_name_ar', 'أكاديمية إيديو نيرسنج للتعليم الطبي', 0, NOW()),
  ('founder_name', 'د. خالد عبد الناصر محمد المرسي', 0, NOW()),
  ('founder_title', 'محاضر دولي معتمد – Certified International Lecturer', 0, NOW()),
  ('contact_phone', '01289492477', 0, NOW()),
  ('whatsapp_number', '01289492477', 0, NOW()),
  ('payment_vodafone', '01061709999', 0, NOW()),
  ('payment_instapay', '01061709999', 0, NOW()),
  ('currency', 'EGP', 0, NOW()),
  ('payment_instructions_ar', 'قم بتحويل المبلغ عبر Vodafone Cash أو InstaPay على الرقم 01061709999، ثم ارفع صورة الإيصال ورقم المعاملة لإتمام التفعيل الفوري بعد المراجعة.', 0, NOW()),
  ('theme_primary', '#0d9488', 0, NOW()),
  ('theme_navy', '#0a1f3d', 0, NOW()),
  ('theme_emerald', '#059669', 0, NOW())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW();

-- 10) Seed Initial Published Courses with Instructor ID 10
INSERT INTO `courses` (`id`, `instructor_id`, `slug`, `title`, `title_ar`, `title_en`, `description`, `price`, `status`, `level`, `duration_minutes`, `created_at`)
VALUES
  (101, 10, 'nursing-fundamentals', 'أساسيات التمريض السريري الشامل', 'أساسيات التمريض السريري الشامل', 'Comprehensive Clinical Nursing Fundamentals', 'دورة شاملة ومكثفة تغطي أساسيات المهنة من قياس العلامات الحيوية، التعقيم ومكافحة العدوى، إعطاء الأدوية، والرعاية السريرية المتقدمة.', 450.00, 'published', 'beginner', 960, NOW()),
  (102, 10, 'advanced-icu-nursing', 'تمريض العناية المركزة ICU المتقدم', 'تمريض العناية المركزة ICU المتقدم', 'Advanced ICU & Critical Care Nursing', 'تدريب احترافي متقدم على أجهزة التنفس الصناعي، مراقبة الغازات الشريانية ABG، الصدمات، وإنعاش القلب والرئتين CPR.', 750.00, 'published', 'advanced', 1200, NOW()),
  (103, 10, 'operating-room-nursing', 'تمريض العمليات والتعقيم الجراحي OR', 'تمريض العمليات والتعقيم الجراحي OR', 'Operating Room & Surgical Sterilization Nursing', 'إتقان إجراءات التعقيم الجراحي، التعامل مع الآلات الجراحية، بروتوكولات الأمان داخل غرف العمليات، ورعاية ما بعد التخدير.', 600.00, 'published', 'intermediate', 840, NOW()),
  (104, 10, 'nicu-neonatal-nursing', 'تمريض الحضانات والمبتسرين NICU', 'تمريض الحضانات والمبتسرين NICU', 'Neonatal Intensive Care (NICU) Nursing', 'رعاية حديثي الولادة والمبتسرين، التعامل مع الحضانات وأجهزة الضغط الموجب، والتغذية الوريدية للأطفال.', 550.00, 'published', 'intermediate', 720, NOW()),
  (105, 10, 'emergency-pharmacology', 'الطوارئ وأدوية الطوارئ الحرجة', 'الطوارئ وأدوية الطوارئ الحرجة', 'Emergency Nursing & Critical Pharmacology', 'التشخيص والتدخل السريع لحالات الطوارئ، الحوادث، حساب وتخفيف أدوية الطوارئ، وإنقاذ الحياة وفق أحدث الإرشادات الدولية.', 500.00, 'published', 'intermediate', 600, NOW()),
  (106, 10, 'psychiatric-addiction-nursing', 'التمريض النفسي وعلاج الإدمان', 'التمريض النفسي وعلاج الإدمان', 'Psychiatric Nursing & Addiction Recovery', 'إدارة الحالات النفسية الحادة، مهارات التواصل العلاجي، وبروتوكولات سحب السموم وإعادة التأهيل.', 450.00, 'published', 'intermediate', 540, NOW()),
  (107, 10, 'medical-interview-mastery', 'أسئلة ومقابلات الانترفيو الطبي والتمريضي', 'أسئلة ومقابلات الانترفيو الطبي والتمريضي', 'Medical & Nursing Interview Mastery', 'دليل النجاح في مقابلات كبرى المستشفيات والهيئات الطبية المحلية والدولية، مع بنك أسئلة واختبارات حقيقية.', 350.00, 'published', 'beginner', 480, NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `description` = VALUES(`description`), `price` = VALUES(`price`), `status` = 'published', `instructor_id` = 10;

-- 11) Seed Initial Medical Books
INSERT INTO `books` (`id`, `title_ar`, `title_en`, `author`, `description_ar`, `description_en`, `type`, `price`, `discount_price`, `stock`, `pages_count`, `status`, `created_by`)
VALUES
  (1, 'كتاب أساسيات تمريض عملي ونظري', 'Clinical & Practical Nursing Fundamentals', 'د. خالد عبد الناصر محمد المرسي', 'المرجع السريري الأوسع انتشاراً لتطبيق أساسيات التمريض بدقة واحترافية مدعماً بالصور والشروحات التوضيحية.', 'Comprehensive textbook covering foundational clinical nursing skills and modern protocols.', 'both', 350.00, 290.00, 85, 320, 'published', 10),
  (2, 'كتاب العناية المركزة ICU وطوارئ التمريض', 'ICU & Critical Care Nursing Handbook', 'د. خالد عبد الناصر محمد المرسي', 'دليل سريري شامل لبروتوكولات العناية المركزة، قراءة رسم القلب ECG، وغازات الدم الشرياني والعلاجات الوريدية المركزة.', 'Pocket guide and clinical reference for ICU, mechanical ventilation, and emergency pharmacology.', 'both', 420.00, 360.00, 60, 280, 'published', 10),
  (3, 'كتاب بنك أسئلة الانترفيو والمقابلات الطبية', 'Medical & Nursing Interview Question Bank', 'د. خالد عبد الناصر محمد المرسي', 'أكثر من 1000 سؤال وإجابة نموذجية للمقابلات الشخصية في الهيئات الصحية الكبرى واختبارات البرومترك وسوق العمل الطبي.', 'Over 1000 verified interview questions and answers for hospital recruitment and licensing.', 'both', 280.00, 220.00, 110, 240, 'published', 10)
ON DUPLICATE KEY UPDATE `title_ar` = VALUES(`title_ar`), `price` = VALUES(`price`), `status` = 'published';

-- 12) Seed Initial Policy Pages
INSERT INTO `policy_pages` (`slug`, `title_ar`, `title_en`, `content_ar`, `content_en`, `updated_at`)
VALUES
  ('privacy', 'سياسة الخصوصية', 'Privacy Policy', 'نلتزم في منصة Edu Nursing بحماية خصوصية جميع الطلاب والمحاضرين. لا تتم مشاركة أي بيانات شخصية أو معلومات مالية مع أي طرف ثالث، وتُحفظ كافة السجلات وفق معايير أمان وتشفير عالية.', 'Edu Nursing Academy is committed to protecting student and instructor privacy. No personal or financial data is shared with third parties.', NOW()),
  ('terms', 'الشروط والأحكام', 'Terms & Conditions', 'جميع حقوق الملكية الفكرية للكورسات والكتب والمحاضرات محفوظة لمنصة Edu Nursing وبإشراف د. خالد عبد الناصر محمد. يُمنع منعاً باتاً تسجيل أو تسريب أو إعادة بيع أي محتوى تدريبي دون إذن كتابي رسمي.', 'All intellectual property rights of courses, books, and materials belong to Edu Nursing. Unauthorized redistribution or screen recording is strictly prohibited.', NOW()),
  ('refund', 'سياسة الاسترجاع والاستبدال', 'Refund & Return Policy', 'يُتاح طلب استرجاع رسوم الكورسات خلال 48 ساعة من تاريخ الشراء بشرط عدم مشاهدة أكثر من 15% من محتوى الكورس. بالنسبة للكتب المطبوعة، يُتاح الاستبدال في حال وجود أي عيب طباعة خلال 7 أيام من الاستلام.', 'Refund requests for courses are accepted within 48 hours of purchase if less than 15% of the course was accessed.', NOW())
ON DUPLICATE KEY UPDATE `content_ar` = VALUES(`content_ar`), `updated_at` = NOW();
