<?php
/**
 * AI Reply Assistant — Rate Limiter
 *
 * Prevents excessive OpenAI API calls by enforcing:
 *   - Per-ticket cooldown (default: 120 seconds)
 *   - Global rate limit (default: 60 per minute)
 *
 * Reads from the ost_ai_reply_log table to determine recent activity.
 *
 * @package AiReplyAssistant
 */

class AiReplyRateLimiter {

    /** @var int Minimum seconds between AI calls for the same ticket */
    private $perTicketSeconds;

    /** @var int Maximum AI calls per minute globally */
    private $globalPerMinute;

    /** @var string Full table name with prefix */
    private $tableName;

    /**
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config) {
        $this->perTicketSeconds = max(0, (int) $config->get('rate_limit_per_ticket_seconds'));
        $this->globalPerMinute  = max(0, (int) $config->get('rate_limit_global_per_minute'));
        $this->tableName        = TABLE_PREFIX . 'ai_reply_log';
    }

    /**
     * Check if a specific ticket is rate-limited.
     *
     * @param  int  $ticketId
     * @return bool True if the ticket should be skipped
     */
    public function isTicketRateLimited($ticketId) {
        if ($this->perTicketSeconds <= 0) {
            return false; // No per-ticket limit
        }

        $sql = sprintf(
            "SELECT created_at FROM `%s`
             WHERE ticket_id = %d
               AND status = 'generated'
             ORDER BY created_at DESC
             LIMIT 1",
            $this->tableName,
            (int) $ticketId
        );

        $res = db_query($sql);
        if (!$res || db_num_rows($res) === 0) {
            return false; // No previous generation
        }

        $row = db_fetch_array($res);
        $lastTime = strtotime($row['created_at']);
        $elapsed  = time() - $lastTime;

        return $elapsed < $this->perTicketSeconds;
    }

    /**
     * Check if the global rate limit has been exceeded.
     *
     * @return bool True if global limit reached
     */
    public function isGlobalRateLimited() {
        if ($this->globalPerMinute <= 0) {
            return false; // No global limit
        }

        $sql = sprintf(
            "SELECT COUNT(*) AS cnt FROM `%s`
             WHERE status = 'generated'
               AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
            $this->tableName
        );

        $res = db_query($sql);
        if (!$res) {
            return false; // On query failure, don't block
        }

        $row = db_fetch_array($res);
        return ((int) $row['cnt']) >= $this->globalPerMinute;
    }
}
