<?php
/**
 * AI Reply Assistant — Context Builder
 *
 * Assembles the full prompt payload for OpenAI, including:
 *   - System prompt (with configurable language)
 *   - Ticket metadata
 *   - Conversation history (last N messages, PII-redacted)
 *   - Knowledge base content (Mini-KB + osTicket KB + Canned Responses)
 *   - Sensitive data warning flag
 *
 * @package AiReplyAssistant
 */

class AiReplyContextBuilder {

    /** @var PluginConfig */
    private $config;

    /** @var AiReplyPiiRedactor */
    private $redactor;

    /** @var AiReplyKbRetriever */
    private $kbRetriever;

    /**
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config) {
        $this->config      = $config;
        $this->redactor    = new AiReplyPiiRedactor($config);
        $this->kbRetriever = new AiReplyKbRetriever($config);
    }

    /**
     * Build the full prompt payload for OpenAI.
     *
     * @param  Ticket      $ticket       The ticket being processed
     * @param  ThreadEntry $entry        The triggering user message
     * @param  array       $gatingResult Result from GatingLogic (contains sensitive_data flags)
     * @return array {
     *     system_prompt: string,
     *     user_message: string,
     *     prompt_text: string   (combined system + user for hashing)
     * }
     */
    public function build(Ticket $ticket, ThreadEntry $entry, array $gatingResult) {
        $systemPrompt = $this->buildSystemPrompt($gatingResult);
        $userMessage  = $this->buildUserMessage($ticket, $entry, $gatingResult);

        return array(
            'system_prompt' => $systemPrompt,
            'user_message'  => $userMessage,
            'prompt_text'   => $systemPrompt . "\n\n" . $userMessage,
        );
    }

    /**
     * Build the system prompt with rules and output format.
     *
     * @param  array $gatingResult
     * @return string
     */
    private function buildSystemPrompt(array $gatingResult) {
        $language = $this->config->get('response_language') ?: 'Serbian (ekavica, Latin script)';

        $sensitiveWarning = '';
        if (!empty($gatingResult['sensitive_data_found'])) {
            $sensitiveWarning = "\n8. The customer has shared sensitive data (flagged in context). "
                . "Remind them NOT to share such information via ticket. "
                . "Suggest a secure alternative (e.g., in-person verification, secure portal).";
        }

        $prompt = <<<SYSTEM
You are an L1 support agent assistant for a helpdesk system.

RULES:
1. Respond ONLY in {$language}.
2. Use ONLY the information provided in the ticket context and knowledge base below.
3. Do NOT invent information, URLs, phone numbers, or procedures not present in the context.
4. NEVER ask the customer for passwords, credit card numbers, JMBG, or other sensitive personal data.
5. If you do not have enough information to provide a helpful answer, set "need_more_info" to true and list up to 3 specific, professional questions in the "questions" array.
6. Keep your tone professional, concise, and clear.
7. If the customer has shared sensitive data (flagged below), remind them not to share such information and suggest a secure alternative.{$sensitiveWarning}

OUTPUT FORMAT:
You MUST respond with a valid JSON object with exactly this structure:
{
  "reply_subject": "Short subject line for the response",
  "reply_body": "Full response text. Use line breaks for readability.",
  "need_more_info": false,
  "questions": [],
  "suggested_tags": ["tag1", "tag2"],
  "confidence": 0.85
}

FIELD RULES:
- reply_subject: A brief, relevant subject line (max 100 chars)
- reply_body: The draft response. Professional, helpful, concise. May include numbered steps.
- need_more_info: true if the ticket lacks details for a proper answer
- questions: Array of 0-3 clarifying questions (only when need_more_info=true)
- suggested_tags: 0-3 tags that categorize this ticket (e.g., "password-reset", "network")
- confidence: Float 0.0-1.0 — your confidence that reply_body fully addresses the issue
SYSTEM;

        return $prompt;
    }

    /**
     * Build the user message containing ticket context.
     *
     * @param  Ticket      $ticket
     * @param  ThreadEntry $entry
     * @param  array       $gatingResult
     * @return string
     */
    private function buildUserMessage(Ticket $ticket, ThreadEntry $entry, array $gatingResult) {
        $parts = array();

        // Section 1: Ticket metadata
        $parts[] = $this->buildMetadataSection($ticket);

        // Section 2: Sensitive data flag
        $parts[] = $this->buildSensitiveSection($gatingResult);

        // Section 3: Conversation history
        $parts[] = $this->buildConversationSection($ticket);

        // Section 4: Knowledge base
        $parts[] = $this->buildKnowledgeSection($ticket, $entry);

        return implode("\n\n", $parts) . "\n\n=== END CONTEXT ===";
    }

    /**
     * Build ticket metadata section.
     *
     * @param  Ticket $ticket
     * @return string
     */
    private function buildMetadataSection(Ticket $ticket) {
        $userName = 'Customer';
        try {
            $user = $ticket->getUser();
            if ($user) {
                $name = $user->getFirstName();
                if (empty($name)) {
                    $name = $user->getName();
                }
                if (!empty($name)) {
                    // Redact the name (replace with first name or generic)
                    list($redactedName, ) = $this->redactor->redact((string) $name);
                    $userName = $redactedName;
                }
            }
        } catch (\Exception $e) {
            // Keep default "Customer"
        }

        // Redact subject
        list($subject, ) = $this->redactor->redact($ticket->getSubject() ?: '(no subject)');

        // Get department, priority, status names safely
        $deptName = 'Unknown';
        try {
            $dept = $ticket->getDept();
            if ($dept) {
                $deptName = $dept->getName();
            }
        } catch (\Exception $e) {}

        $priorityName = 'Normal';
        try {
            $priority = $ticket->getPriority();
            if ($priority) {
                $priorityName = $priority->getDesc();
            }
        } catch (\Exception $e) {}

        $statusName = 'Open';
        try {
            $status = $ticket->getStatus();
            if ($status) {
                $statusName = $status->getName();
            }
        } catch (\Exception $e) {}

        return "=== TICKET CONTEXT ===\n"
             . "Ticket ID: " . $ticket->getId() . "\n"
             . "Ticket Number: " . $ticket->getNumber() . "\n"
             . "Subject: " . $subject . "\n"
             . "Department: " . $deptName . "\n"
             . "Priority: " . $priorityName . "\n"
             . "Status: " . $statusName . "\n"
             . "Customer: " . $userName;
    }

    /**
     * Build sensitive data warning section.
     *
     * @param  array $gatingResult
     * @return string
     */
    private function buildSensitiveSection(array $gatingResult) {
        $section = "=== SENSITIVE DATA FLAG ===\n";

        if (!empty($gatingResult['sensitive_data_found'])) {
            $types = implode(', ', $gatingResult['sensitive_types']);
            $section .= "WARNING: Customer message contains sensitive data types: {$types}.\n"
                      . "Do NOT request any sensitive information. Suggest secure alternatives.";
        } else {
            $section .= "None detected.";
        }

        return $section;
    }

    /**
     * Build conversation history section from thread entries.
     *
     * @param  Ticket $ticket
     * @return string
     */
    private function buildConversationSection(Ticket $ticket) {
        $maxMessages  = max(1, (int) $this->config->get('context_message_count') ?: 6);
        $maxMsgLength = max(100, (int) $this->config->get('max_message_length') ?: 2000);

        $section = "=== CONVERSATION HISTORY (last {$maxMessages} messages) ===";

        try {
            $thread = $ticket->getThread();
            if (!$thread) {
                return $section . "\n(No thread available)";
            }

            // Get thread entries — messages (M) and responses (R), skip notes (N)
            $entries = $thread->getEntries(array(
                'order' => 'DESC',
                'limit' => $maxMessages,
            ));

            if (!$entries) {
                return $section . "\n(No messages)";
            }

            // Collect entries (they come DESC, we need ASC for chronological order)
            $messages = array();
            foreach ($entries as $e) {
                $type = $e->getType();
                // Only include customer messages (M) and agent replies (R)
                if ($type !== 'M' && $type !== 'R') {
                    continue;
                }

                $role = ($type === 'M') ? 'Customer' : 'Agent';

                // Get message body text
                $body = $e->getBody();
                if ($body instanceof ThreadEntryBody) {
                    $text = $body->getClean();
                    if (empty($text)) {
                        $text = strip_tags((string) $body->display('html'));
                    }
                } else {
                    $text = strip_tags((string) $body);
                }

                $text = html_entity_decode(trim($text), ENT_QUOTES, 'UTF-8');

                // Truncate long messages
                if (mb_strlen($text) > $maxMsgLength) {
                    $text = mb_substr($text, 0, $maxMsgLength) . '... (truncated)';
                }

                // PII redaction
                list($text, ) = $this->redactor->redact($text);

                // Get timestamp
                $created = $e->getCreateDate();
                if ($created instanceof DateTime) {
                    $timestamp = $created->format('Y-m-d H:i');
                } else {
                    $timestamp = (string) $created;
                }

                $messages[] = "\n[{$role}] ({$timestamp}):\n{$text}";
            }

            // Reverse to chronological order (was DESC)
            $messages = array_reverse($messages);

            if (empty($messages)) {
                return $section . "\n(No messages)";
            }

            return $section . implode("\n", $messages);

        } catch (\Exception $e) {
            return $section . "\n(Error retrieving conversation history)";
        }
    }

    /**
     * Build knowledge base section.
     *
     * Combines: static Mini-KB + osTicket FAQ + Canned Responses.
     *
     * @param  Ticket      $ticket
     * @param  ThreadEntry $entry
     * @return string
     */
    private function buildKnowledgeSection(Ticket $ticket, ThreadEntry $entry) {
        $kbContent = $this->kbRetriever->retrieve($ticket, $entry);

        if (empty(trim($kbContent))) {
            return "=== KNOWLEDGE BASE ===\nNo knowledge base content available.";
        }

        return $kbContent;
    }
}
