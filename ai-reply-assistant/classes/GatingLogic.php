<?php
/**
 * AI Reply Assistant — Gating Logic
 *
 * Evaluates whether a ticket + message combination should be sent to OpenAI
 * for draft generation. Returns ALLOW or DENY with a reason.
 *
 * Check order (short-circuit on first DENY):
 *   1. Plugin enabled?
 *   2. Message empty?
 *   3. Ticket status in allowlist?
 *   4. Department in allowlist?
 *   5. Priority not in blocklist?
 *   6. Tags clean?
 *   7. Attachment policy?
 *   8. Rate limit (per-ticket)?
 *   9. Rate limit (global)?
 *  10. Sensitive data detection (modifies prompt, does not block)
 *
 * @package AiReplyAssistant
 */

class AiReplyGatingLogic {

    /** @var PluginConfig */
    private $config;

    /** @var AiReplyRateLimiter */
    private $rateLimiter;

    /** @var AiReplyPiiRedactor */
    private $piiRedactor;

    /**
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config) {
        $this->config = $config;
        $this->rateLimiter = new AiReplyRateLimiter($config);
        $this->piiRedactor = new AiReplyPiiRedactor($config);
    }

    /**
     * Evaluate whether this ticket/message should be processed.
     *
     * @param  Ticket      $ticket
     * @param  ThreadEntry $entry
     * @return array {
     *     allowed: bool,
     *     reason: string,
     *     reason_code: string,
     *     sensitive_data_found: bool,
     *     sensitive_types: string[]
     * }
     */
    public function evaluate(Ticket $ticket, ThreadEntry $entry) {
        // Default result — ALLOW
        $result = array(
            'allowed'              => true,
            'reason'               => 'allowed',
            'reason_code'          => 'allowed',
            'sensitive_data_found' => false,
            'sensitive_types'      => array(),
        );

        // Check 1: Plugin enabled
        if (!$this->config->get('enabled')) {
            return $this->deny('plugin disabled', 'plugin_disabled');
        }

        // Check 2: Empty message
        $body = $this->getMessageText($entry);
        if (empty(trim($body))) {
            return $this->deny('empty message', 'empty_message');
        }

        // Check 3: Ticket status
        if (!$this->isStatusAllowed($ticket)) {
            return $this->deny('status not allowed', 'status_not_allowed');
        }

        // Check 4: Department
        if (!$this->isDepartmentAllowed($ticket)) {
            return $this->deny('department not allowed', 'department_not_allowed');
        }

        // Check 5: Priority
        if ($this->isPriorityBlocked($ticket)) {
            return $this->deny('priority blocked', 'priority_blocked');
        }

        // Check 6: Tags
        $blockedTag = $this->getBlockedTag($ticket);
        if ($blockedTag !== null) {
            return $this->deny('tag blocked: ' . $blockedTag, 'tag_blocked');
        }

        // Check 7: Attachment policy
        if ($this->isBlockedByAttachmentPolicy($entry)) {
            return $this->deny('has attachments', 'has_attachments');
        }

        // Check 8: Per-ticket rate limit
        $ticketId = $ticket->getId();
        if ($this->rateLimiter->isTicketRateLimited($ticketId)) {
            return $this->deny('rate limited (per-ticket)', 'rate_limit_ticket');
        }

        // Check 9: Global rate limit
        if ($this->rateLimiter->isGlobalRateLimited()) {
            return $this->deny('rate limited (global)', 'rate_limit_global');
        }

        // Check 10: Sensitive data detection (does NOT block — modifies prompt)
        if ($this->config->get('pii_redaction_enabled')) {
            $sensitiveTypes = $this->piiRedactor->detectSensitive($body);
            if (!empty($sensitiveTypes)) {
                $result['sensitive_data_found'] = true;
                $result['sensitive_types'] = $sensitiveTypes;
            }
        }

        return $result;
    }

    /**
     * Extract plain text from a thread entry body.
     *
     * @param  ThreadEntry $entry
     * @return string
     */
    private function getMessageText(ThreadEntry $entry) {
        $body = $entry->getBody();
        if ($body instanceof ThreadEntryBody) {
            $text = $body->getClean();
            if (empty($text)) {
                $text = $body->display('text');
            }
        } elseif (is_string($body)) {
            $text = $body;
        } else {
            $text = (string) $body;
        }
        // Strip HTML tags and decode entities
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        return trim($text);
    }

    /**
     * Check if ticket status is in the allowed list.
     *
     * @param  Ticket $ticket
     * @return bool
     */
    private function isStatusAllowed(Ticket $ticket) {
        $allowed = $this->config->get('allowed_statuses');
        if (empty($allowed)) {
            return true; // No restriction if not configured
        }

        $statusId = $ticket->getStatusId();

        // Handle both array and comma-separated string from config
        if (is_string($allowed)) {
            $allowed = array_map('trim', explode(',', $allowed));
        }
        if (!is_array($allowed)) {
            $allowed = array($allowed);
        }

        return in_array((string) $statusId, array_map('strval', $allowed));
    }

    /**
     * Check if department is in the allowed list.
     *
     * @param  Ticket $ticket
     * @return bool
     */
    private function isDepartmentAllowed(Ticket $ticket) {
        $allowed = $this->config->get('allowed_departments');
        if (empty($allowed)) {
            return true;
        }

        // Handle different config formats
        if (is_string($allowed)) {
            $allowed = array_map('trim', explode(',', $allowed));
        }
        if (!is_array($allowed)) {
            $allowed = array($allowed);
        }

        // '0' means all departments
        if (in_array('0', array_map('strval', $allowed))) {
            return true;
        }

        $deptId = $ticket->getDeptId();
        return in_array((string) $deptId, array_map('strval', $allowed));
    }

    /**
     * Check if ticket priority is in the blocked list.
     *
     * @param  Ticket $ticket
     * @return bool True if blocked
     */
    private function isPriorityBlocked(Ticket $ticket) {
        $blocked = $this->config->get('blocked_priorities');
        if (empty($blocked)) {
            return false;
        }

        if (is_string($blocked)) {
            $blocked = array_map('trim', explode(',', $blocked));
        }
        if (!is_array($blocked)) {
            $blocked = array($blocked);
        }

        $priorityId = $ticket->getPriorityId();
        return in_array((string) $priorityId, array_map('strval', $blocked));
    }

    /**
     * Check if any ticket tag is in the blocked list.
     *
     * @param  Ticket $ticket
     * @return string|null The first blocked tag found, or null
     */
    private function getBlockedTag(Ticket $ticket) {
        $blockedStr = $this->config->get('blocked_tags');
        if (empty($blockedStr)) {
            return null;
        }

        // Parse blocked tags from comma-separated config
        $blockedTags = array_map('trim', explode(',', $blockedStr));
        $blockedTags = array_filter($blockedTags);
        $blockedTags = array_map('mb_strtolower', $blockedTags);

        if (empty($blockedTags)) {
            return null;
        }

        // Get ticket tags — osTicket 1.18.x
        $ticketTags = array();
        try {
            // Try to get tags from the ticket object
            if (method_exists($ticket, 'getTags')) {
                $tags = $ticket->getTags();
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $ticketTags[] = mb_strtolower(is_object($tag) ? $tag->getName() : (string) $tag);
                    }
                }
            }

            // Alternative: query the DB directly
            if (empty($ticketTags)) {
                $sql = "SELECT t.name FROM " . TAG_TABLE . " t "
                     . "JOIN " . TABLE_PREFIX . "tag_link tl ON t.tag_id = tl.tag_id "
                     . "WHERE tl.object_type = 'T' AND tl.object_id = " . db_input($ticket->getId());
                $res = db_query($sql);
                if ($res) {
                    while ($row = db_fetch_array($res)) {
                        $ticketTags[] = mb_strtolower($row['name']);
                    }
                }
            }
        } catch (\Exception $e) {
            // If tag retrieval fails, skip this check
            return null;
        }

        // Check intersection
        foreach ($ticketTags as $tag) {
            if (in_array($tag, $blockedTags)) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Check attachment policy against message attachments.
     *
     * @param  ThreadEntry $entry
     * @return bool True if blocked by attachment policy
     */
    private function isBlockedByAttachmentPolicy(ThreadEntry $entry) {
        $policy = $this->config->get('attachment_policy') ?: 'ignore';
        if ($policy !== 'deny') {
            return false;
        }

        try {
            $attachments = $entry->getAttachments();
            if ($attachments) {
                // Check if there are any attachments
                $count = is_countable($attachments) ? count($attachments) : 0;
                if ($count === 0 && method_exists($attachments, 'count')) {
                    $count = $attachments->count();
                }
                return $count > 0;
            }
        } catch (\Exception $e) {
            // If we can't determine attachments, don't block
        }

        return false;
    }

    /**
     * Build a DENY result.
     *
     * @param  string $reason      Human-readable reason
     * @param  string $reasonCode  Machine-readable reason code
     * @return array
     */
    private function deny($reason, $reasonCode) {
        return array(
            'allowed'              => false,
            'reason'               => $reason,
            'reason_code'          => $reasonCode,
            'sensitive_data_found' => false,
            'sensitive_types'      => array(),
        );
    }
}
