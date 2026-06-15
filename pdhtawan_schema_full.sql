/*
  Full pdhtawan schema for PDH Telemed.
  Includes Thailand Post integration columns/tables/views.
  Target: MySQL 8.x, utf8mb4.
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `pdhtawan`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

USE `pdhtawan`;

DROP VIEW IF EXISTS `vw_thailand_post_summary`;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id` int NOT NULL,
  `page_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`role_id`, `page_name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=Dynamic;

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `role_id` int NOT NULL,
  `role_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`role_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=Dynamic;

DROP TABLE IF EXISTS `telemed_delivery`;
CREATE TABLE `telemed_delivery` (
  `hn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL COMMENT 'ที่อยู่สำหรับจัดส่ง',
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT 'เบอร์โทรศัพท์ติดต่อ',
  `pickup_self` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT 'ผู้ที่อัปเดตข้อมูลล่าสุด',
  `update_time` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`hn`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=Dynamic;

DROP TABLE IF EXISTS `telemed_patient_notes`;
CREATE TABLE `telemed_patient_notes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `hn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `regdate` date NULL DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_hn_regdate` (`hn`, `regdate`) USING BTREE,
  KEY `idx_created_at` (`created_at`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic;

DROP TABLE IF EXISTS `telemed_patient_status`;
CREATE TABLE `telemed_patient_status` (
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

DROP TABLE IF EXISTS `telemed_clinic_status_options`;
CREATE TABLE `telemed_clinic_status_options` (
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

DROP TABLE IF EXISTS `telemed_tracking`;
CREATE TABLE `telemed_tracking` (
  `hn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `regdate` date NOT NULL,
  `tracking_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT 'เลขพัสดุไปรษณีย์',
  `send_date` date NULL DEFAULT NULL COMMENT 'วันที่ส่งยา',
  `receive_date` date NULL DEFAULT NULL COMMENT 'วันที่คนไข้รับยา',
  `followup_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT 'รอจัดส่ง' COMMENT 'สถานะ: รอจัดส่ง, ส่งแล้ว, ได้รับแล้ว, ติดต่อไม่ได้',
  `follower_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT 'ชื่อเภสัชกร/ผู้ติดตาม',
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL COMMENT 'หมายเหตุการโทรติดตาม',
  `tracking_status` json NULL COMMENT 'Normalized Thailand Post tracking status',
  `last_sync` datetime NULL COMMENT 'Last Thailand Post sync time',
  `sync_data` json NULL COMMENT 'Raw Thailand Post API response',
  `webhook_data` json NULL COMMENT 'Raw Thailand Post webhook payload',
  `update_time` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`hn`, `regdate`) USING BTREE,
  KEY `idx_tracking_no` (`tracking_no`) USING BTREE,
  KEY `idx_sync_date` (`last_sync`) USING BTREE,
  KEY `idx_followup_status` (`followup_status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=Dynamic;

DROP TABLE IF EXISTS `thailand_post_sync_logs`;
CREATE TABLE `thailand_post_sync_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `tracking_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'sync_success, sync_failed, webhook_received',
  `response_data` json NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_tracking_no` (`tracking_no`) USING BTREE,
  KEY `idx_created_at` (`created_at`) USING BTREE,
  KEY `idx_action` (`action`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic;

DROP TABLE IF EXISTS `user_logs`;
CREATE TABLE `user_logs` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `action` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL,
  `log_datetime` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=Dynamic;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `fullname` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `role_id` int NOT NULL,
  `is_active` int NULL DEFAULT 0 COMMENT '0=รออนุมัติ, 1=อนุมัติแล้ว',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `username` (`username`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=Dynamic;

CREATE OR REPLACE VIEW `vw_thailand_post_summary` AS
SELECT
  `t`.`hn` AS `hn`,
  `t`.`regdate` AS `regdate`,
  `t`.`tracking_no` AS `tracking_no`,
  `t`.`followup_status` AS `followup_status`,
  `t`.`last_sync` AS `last_sync`,
  JSON_UNQUOTE(JSON_EXTRACT(`t`.`tracking_status`, '$.status')) AS `thailand_post_status`,
  JSON_UNQUOTE(JSON_EXTRACT(`t`.`tracking_status`, '$.location')) AS `thailand_post_location`,
  JSON_UNQUOTE(JSON_EXTRACT(`t`.`tracking_status`, '$.date')) AS `thailand_post_date`,
  CASE
    WHEN `t`.`last_sync` IS NULL THEN 'ยังไม่เคยซิงก์'
    WHEN `t`.`last_sync` >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 'ซิงก์ภายใน 1 ชั่วโมง'
    WHEN `t`.`last_sync` >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 'ซิงก์ภายใน 1 วัน'
    ELSE 'ควรซิงก์ใหม่'
  END AS `sync_status`,
  TIMESTAMPDIFF(HOUR, `t`.`last_sync`, NOW()) AS `hours_since_sync`
FROM `telemed_tracking` `t`
WHERE `t`.`tracking_no` IS NOT NULL AND `t`.`tracking_no` <> '';

SET FOREIGN_KEY_CHECKS = 1;
