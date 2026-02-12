# 07 – Database Schema

## 1. Overview

The plugin requires one custom database table for logging AI operations.
All plugin configuration is stored in osTicket's native `ost_config` table
(handled automatically by the PluginConfig system).

**Table prefix**: Uses osTicket's configured table prefix (default: `ost_`).

## 2. Table: `{prefix}ai_reply_log`

### 2.1 Schema

```sql
CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%ai_reply_log` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ticket_id`           INT UNSIGNED    NOT NULL,
    `message_id`          INT UNSIGNED    DEFAULT NULL COMMENT 'Thread entry ID of the triggering user message',
    `note_id`             INT UNSIGNED    DEFAULT NULL COMMENT 'Thread entry ID of the created AI note (if any)',
    `status`              ENUM('generated','skipped','error') NOT NULL,
    `reason`              VARCHAR(255)    DEFAULT NULL COMMENT 'Reason for skip/error (no PII)',
    `model`               VARCHAR(100)    DEFAULT NULL COMMENT 'OpenAI model used',
    `prompt_tokens`       INT UNSIGNED    DEFAULT NULL,
    `completion_tokens`   INT UNSIGNED    DEFAULT NULL,
    `total_tokens`        INT UNSIGNED    DEFAULT NULL,
    `latency_ms`          INT UNSIGNED    DEFAULT NULL COMMENT 'API call duration in milliseconds',
    `confidence`          DECIMAL(3,2)    DEFAULT NULL COMMENT 'AI confidence score 0.00-1.00',
    `hash_prompt`         CHAR(64)        DEFAULT NULL COMMENT 'SHA-256 hash of the sent prompt (no raw content)',
    `hash_response`       CHAR(64)        DEFAULT NULL COMMENT 'SHA-256 hash of the AI response (no raw content)',
    
    PRIMARY KEY (`id`),
    INDEX `idx_ticket_id`   (`ticket_id`),
    INDEX `idx_created_at`  (`created_at`),
    INDEX `idx_status`      (`status`),
    INDEX `idx_ticket_created` (`ticket_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.2 Column Details

| Column | Purpose | PII Safe? |
|--------|---------|-----------|
| `id` | Auto-increment primary key | Yes |
| `created_at` | Timestamp of the log entry | Yes |
| `ticket_id` | Reference to `ost_ticket.ticket_id` | Yes (internal ID) |
| `message_id` | Thread entry that triggered AI processing | Yes (internal ID) |
| `note_id` | Thread entry for the AI-generated internal note | Yes (internal ID) |
| `status` | Outcome: generated, skipped, or error | Yes |
| `reason` | Why processing was skipped or failed | Yes (no PII in reasons) |
| `model` | Which OpenAI model was used | Yes |
| `prompt_tokens` | Token count for the prompt | Yes |
| `completion_tokens` | Token count for the response | Yes |
| `total_tokens` | Total tokens consumed | Yes |
| `latency_ms` | API call duration | Yes |
| `confidence` | Model's self-reported confidence | Yes |
| `hash_prompt` | SHA-256 of the prompt (not the prompt itself) | Yes |
| `hash_response` | SHA-256 of the response (not the response itself) | Yes |

### 2.3 Why Hashes Instead of Content?

- **Privacy**: No PII can leak from the log table
- **Debugging**: Hashes allow correlation ("was this the same prompt?") without storing content
- **Compliance**: Minimal data retention
- **Forensics**: If needed, the hash can be compared against a regenerated prompt

## 3. Indexes & Query Patterns

### 3.1 Rate Limiting Queries

**Per-ticket cooldown** (most frequent query):
```sql
SELECT created_at FROM {prefix}ai_reply_log
WHERE ticket_id = ?
  AND status = 'generated'
ORDER BY created_at DESC
LIMIT 1;
```
→ Uses `idx_ticket_created` index.

**Global rate limit**:
```sql
SELECT COUNT(*) FROM {prefix}ai_reply_log
WHERE status = 'generated'
  AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND);
```
→ Uses `idx_created_at` index.

### 3.2 Admin/Reporting Queries

**Recent activity**:
```sql
SELECT * FROM {prefix}ai_reply_log
ORDER BY created_at DESC
LIMIT 50;
```

**Stats by status**:
```sql
SELECT status, COUNT(*) as count,
       AVG(latency_ms) as avg_latency,
       SUM(total_tokens) as total_tokens_used
FROM {prefix}ai_reply_log
WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY status;
```

**Error investigation**:
```sql
SELECT * FROM {prefix}ai_reply_log
WHERE status = 'error'
  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY created_at DESC;
```

## 4. Data Retention

### 4.1 MVP Approach

No automatic cleanup in MVP. Log table grows indefinitely.

### 4.2 Post-MVP: Cleanup Cron

Recommended addition:
```sql
-- Delete logs older than 90 days
DELETE FROM {prefix}ai_reply_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

Could be triggered by osTicket's cron system or a config setting:
`log_retention_days` (default: 90, 0 = keep forever)

## 5. Migration Script

### 5.1 File: `migrations/001-create-log-table.sql`

```sql
-- AI Reply Assistant Plugin - Migration 001
-- Creates the AI reply log table
-- Run once during plugin installation
--
-- Replace %TABLE_PREFIX% with your osTicket table prefix (default: ost_)

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%ai_reply_log` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ticket_id`           INT UNSIGNED    NOT NULL,
    `message_id`          INT UNSIGNED    DEFAULT NULL,
    `note_id`             INT UNSIGNED    DEFAULT NULL,
    `status`              ENUM('generated','skipped','error') NOT NULL,
    `reason`              VARCHAR(255)    DEFAULT NULL,
    `model`               VARCHAR(100)    DEFAULT NULL,
    `prompt_tokens`       INT UNSIGNED    DEFAULT NULL,
    `completion_tokens`   INT UNSIGNED    DEFAULT NULL,
    `total_tokens`        INT UNSIGNED    DEFAULT NULL,
    `latency_ms`          INT UNSIGNED    DEFAULT NULL,
    `confidence`          DECIMAL(3,2)    DEFAULT NULL,
    `hash_prompt`         CHAR(64)        DEFAULT NULL,
    `hash_response`       CHAR(64)        DEFAULT NULL,
    
    PRIMARY KEY (`id`),
    INDEX `idx_ticket_id`       (`ticket_id`),
    INDEX `idx_created_at`      (`created_at`),
    INDEX `idx_status`          (`status`),
    INDEX `idx_ticket_created`  (`ticket_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.2 Installation Approach

The plugin should auto-create the table during first activation:

```php
class AiReplyPlugin extends Plugin {
    function bootstrap() {
        $this->ensureTable();
        // ... register signal handlers
    }
    
    private function ensureTable() {
        // Check if table exists
        $sql = "SHOW TABLES LIKE '" . TABLE_PREFIX . "ai_reply_log'";
        $res = db_query($sql);
        if (db_num_rows($res) === 0) {
            // Read and execute migration SQL
            $sql = file_get_contents(__DIR__ . '/migrations/001-create-log-table.sql');
            $sql = str_replace('%TABLE_PREFIX%', TABLE_PREFIX, $sql);
            db_query($sql);
        }
    }
}
```

## 6. Foreign Keys

**Not using foreign keys** in MVP. Reasons:
- osTicket core tables may use MyISAM (doesn't support FK)
- Plugin should not create dependencies on core table structure
- Ticket deletion: orphaned log rows are acceptable (they contain no PII)

If needed post-MVP: Add FK on `ticket_id` → `ost_ticket.ticket_id` with `ON DELETE CASCADE`.

## 7. Schema Versioning

For future migrations:

```
migrations/
├── 001-create-log-table.sql
├── 002-add-column-xyz.sql    (future)
└── ...
```

Track applied migrations in a simple meta row:
```sql
-- Store in ost_config or in a dedicated version field
-- Key: 'ai_reply_assistant.schema_version', Value: '001'
```

The `ensureTable()` method can be extended to check schema version and apply
pending migrations automatically.
