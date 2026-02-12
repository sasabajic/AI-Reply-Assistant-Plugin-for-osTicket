-- AI Reply Assistant Plugin - Migration 001
-- Creates the AI reply log table
-- Run once during plugin installation
--
-- This file is provided for manual execution if needed.
-- The plugin auto-creates the table on first activation via class.AiReplyPlugin.php.
--
-- Replace %TABLE_PREFIX% with your osTicket table prefix (default: ost_)

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%ai_reply_log` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ticket_id`           INT UNSIGNED    NOT NULL,
    `message_id`          INT UNSIGNED    DEFAULT NULL
        COMMENT 'Thread entry ID of the triggering user message',
    `note_id`             INT UNSIGNED    DEFAULT NULL
        COMMENT 'Thread entry ID of the created AI note (if any)',
    `status`              ENUM('generated','skipped','error') NOT NULL,
    `reason`              VARCHAR(255)    DEFAULT NULL
        COMMENT 'Reason for skip/error (no PII)',
    `model`               VARCHAR(100)    DEFAULT NULL
        COMMENT 'OpenAI model used',
    `prompt_tokens`       INT UNSIGNED    DEFAULT NULL,
    `completion_tokens`   INT UNSIGNED    DEFAULT NULL,
    `total_tokens`        INT UNSIGNED    DEFAULT NULL,
    `latency_ms`          INT UNSIGNED    DEFAULT NULL
        COMMENT 'API call duration in milliseconds',
    `confidence`          DECIMAL(3,2)    DEFAULT NULL
        COMMENT 'AI confidence score 0.00-1.00',
    `hash_prompt`         CHAR(64)        DEFAULT NULL
        COMMENT 'SHA-256 hash of the sent prompt (no raw content)',
    `hash_response`       CHAR(64)        DEFAULT NULL
        COMMENT 'SHA-256 hash of the AI response (no raw content)',

    PRIMARY KEY (`id`),
    INDEX `idx_ticket_id`       (`ticket_id`),
    INDEX `idx_created_at`      (`created_at`),
    INDEX `idx_status`          (`status`),
    INDEX `idx_ticket_created`  (`ticket_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
