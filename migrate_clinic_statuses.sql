USE `pdhtawan`;

CREATE TABLE IF NOT EXISTS `telemed_patient_status` (
  `hn` varchar(20) NOT NULL,
  `regdate` date NOT NULL,
  `status_type` varchar(30) NOT NULL,
  `status_detail` varchar(255) DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`hn`,`regdate`),
  KEY `idx_status_type` (`status_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telemed_clinic_status_options` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `clinic_code` varchar(20) NOT NULL,
  `status_name` varchar(100) NOT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clinic_status_name` (`clinic_code`,`status_name`),
  KEY `idx_clinic_active` (`clinic_code`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @pickup_self_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'pdhtawan'
    AND TABLE_NAME = 'telemed_delivery'
    AND COLUMN_NAME = 'pickup_self'
);

SET @pickup_self_sql = IF(
  @pickup_self_exists = 0,
  'ALTER TABLE `telemed_delivery` ADD COLUMN `pickup_self` tinyint(1) NOT NULL DEFAULT 0 AFTER `phone`',
  'SELECT 1'
);

PREPARE pickup_self_stmt FROM @pickup_self_sql;
EXECUTE pickup_self_stmt;
DEALLOCATE PREPARE pickup_self_stmt;
