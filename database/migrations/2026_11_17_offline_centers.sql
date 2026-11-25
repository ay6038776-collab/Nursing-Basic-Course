SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `offline_centers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(190) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `address` VARCHAR(500) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `whatsapp` VARCHAR(40) DEFAULT NULL,
  `map_url` VARCHAR(1000) DEFAULT NULL,
  `cover_image` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offline_centers_status` (`status`),
  KEY `idx_offline_centers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `offline_center_media` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `center_id` BIGINT UNSIGNED NOT NULL,
  `media_type` ENUM('file', 'video') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offline_center_media_center` (`center_id`),
  CONSTRAINT `fk_offline_center_media_center`
    FOREIGN KEY (`center_id`) REFERENCES `offline_centers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `offline_center_slots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `center_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `booking_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('open', 'closed') NOT NULL DEFAULT 'open',
  `notes` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offline_center_slots_center_date` (`center_id`, `booking_date`),
  KEY `idx_offline_center_slots_status` (`status`),
  CONSTRAINT `fk_offline_center_slots_center`
    FOREIGN KEY (`center_id`) REFERENCES `offline_centers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
