<?php
/**
 * AI Reply Assistant — Log Writer
 *
 * Writes structured log entries to the ost_ai_reply_log table.
 * Stores SHA-256 hashes only — never raw PII, prompts, or responses.
 *
 * Used for:
 *   - Audit trail of AI actions
 *   - Rate limiter queries (per-ticket, global)
 *   - Admin diagnostics and monitoring
 *
 * @package AiReplyAssistant
 */

class AiReplyLogWriter {

    /** @var string Database table name (set in constructor with TABLE_PREFIX) */
    private $table;

    /**
     * Constructor — resolves table name.
     */
    public function __construct() {
        $this->table = TABLE_PREFIX . 'ai_reply_log';
    }

    /**
     * Log a "generated" event — AI successfully produced a draft.
     *
     * @param  int    $ticketId       Ticket ID
     * @param  int    $entryId        Triggering ThreadEntry ID
     * @param  string $model          Model used (e.g., gpt-4.1-mini)
     * @param  string $promptText     Raw prompt text (hashed, not stored)
     * @param  string $responseText   Raw response text (hashed, not stored)
     * @param  int    $promptTokens   Prompt token count
     * @param  int    $completionTokens Completion token count
     * @param  int    $totalTokens    Total token count
     * @param  float  $confidence     AI confidence score
     * @param  string $reasonCode     Reason code (e.g., 'ok')
     * @return bool
     */
    public function logGenerated(
        $ticketId,
        $entryId,
        $model,
        $promptText,
        $responseText,
        $promptTokens,
        $completionTokens,
        $totalTokens,
        $confidence,
        $reasonCode = 'ok'
    ) {
        return $this->insert(array(
            'ticket_id'         => (int) $ticketId,
            'entry_id'          => (int) $entryId,
            'event_type'        => 'generated',
            'model'             => $this->sanitize($model, 64),
            'prompt_hash'       => $this->hash($promptText),
            'response_hash'     => $this->hash($responseText),
            'prompt_tokens'     => (int) $promptTokens,
            'completion_tokens' => (int) $completionTokens,
            'total_tokens'      => (int) $totalTokens,
            'confidence'        => round((float) $confidence, 2),
            'reason_code'       => $this->sanitize($reasonCode, 64),
        ));
    }

    /**
     * Log a "skipped" event — gating logic blocked AI processing.
     *
     * @param  int    $ticketId   Ticket ID
     * @param  int    $entryId    Triggering ThreadEntry ID
     * @param  string $reasonCode The gating reason code (e.g., 'dept_blocked')
     * @return bool
     */
    public function logSkipped($ticketId, $entryId, $reasonCode) {
        return $this->insert(array(
            'ticket_id'   => (int) $ticketId,
            'entry_id'    => (int) $entryId,
            'event_type'  => 'skipped',
            'reason_code' => $this->sanitize($reasonCode, 64),
        ));
    }

    /**
     * Log an "error" event — something failed in the pipeline.
     *
     * @param  int    $ticketId   Ticket ID
     * @param  int    $entryId    Triggering ThreadEntry ID
     * @param  string $reasonCode Error classification (e.g., 'openai_error', 'parse_error')
     * @param  string $model      Model attempted (if available)
     * @return bool
     */
    public function logError($ticketId, $entryId, $reasonCode, $model = '') {
        return $this->insert(array(
            'ticket_id'   => (int) $ticketId,
            'entry_id'    => (int) $entryId,
            'event_type'  => 'error',
            'model'       => $this->sanitize($model, 64),
            'reason_code' => $this->sanitize($reasonCode, 64),
        ));
    }

    /**
     * Insert a log row.
     *
     * @param  array $data Column => value pairs
     * @return bool
     */
    private function insert(array $data) {
        try {
            // Build column list and placeholders
            $columns      = array();
            $placeholders = array();
            $values       = array();

            foreach ($data as $col => $val) {
                $columns[]      = '`' . $col . '`';
                $placeholders[] = '?';
                $values[]       = $val;
            }

            $sql = 'INSERT INTO `' . $this->table . '` ('
                 . implode(', ', $columns)
                 . ') VALUES ('
                 . implode(', ', $placeholders)
                 . ')';

            // Use osTicket's DB layer
            $result = db_query($sql, $values);

            return ($result !== false);

        } catch (\Exception $e) {
            error_log('[AI Reply Assistant] LogWriter insert error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * SHA-256 hash a string for storage (no raw content in DB).
     *
     * @param  string $text
     * @return string 64-char hex hash
     */
    private function hash($text) {
        if (empty($text)) {
            return '';
        }
        return hash('sha256', $text);
    }

    /**
     * Sanitize a string for DB storage: trim and limit length.
     *
     * @param  string $value
     * @param  int    $maxLen
     * @return string
     */
    private function sanitize($value, $maxLen = 255) {
        $value = trim((string) $value);
        if (mb_strlen($value) > $maxLen) {
            $value = mb_substr($value, 0, $maxLen);
        }
        return $value;
    }
}
