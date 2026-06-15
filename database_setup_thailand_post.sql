-- Thailand Post Integration Database Migration
-- Safe for existing pdhtawan data on MySQL 8.x.

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS add_column_if_missing $$
CREATE PROCEDURE add_column_if_missing(
    IN p_table_name varchar(64),
    IN p_column_name varchar(64),
    IN p_column_sql text
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN ', p_column_sql);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DROP PROCEDURE IF EXISTS add_index_if_missing $$
CREATE PROCEDURE add_index_if_missing(
    IN p_table_name varchar(64),
    IN p_index_name varchar(64),
    IN p_index_sql text
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table_name, '` ADD INDEX `', p_index_name, '` ', p_index_sql);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

CALL add_column_if_missing('telemed_tracking', 'tracking_status', '`tracking_status` JSON NULL COMMENT ''Normalized Thailand Post tracking status'' AFTER `note`');
CALL add_column_if_missing('telemed_tracking', 'last_sync', '`last_sync` DATETIME NULL COMMENT ''Last Thailand Post sync time'' AFTER `tracking_status`');
CALL add_column_if_missing('telemed_tracking', 'sync_data', '`sync_data` JSON NULL COMMENT ''Raw Thailand Post API response'' AFTER `last_sync`');
CALL add_column_if_missing('telemed_tracking', 'webhook_data', '`webhook_data` JSON NULL COMMENT ''Raw Thailand Post webhook payload'' AFTER `sync_data`');

CALL add_index_if_missing('telemed_tracking', 'idx_tracking_no', '(`tracking_no`)');
CALL add_index_if_missing('telemed_tracking', 'idx_sync_date', '(`last_sync`)');
CALL add_index_if_missing('telemed_tracking', 'idx_followup_status', '(`followup_status`)');

CREATE TABLE IF NOT EXISTS `thailand_post_sync_logs` (
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

CALL add_index_if_missing('thailand_post_sync_logs', 'idx_action', '(`action`)');

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

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;
