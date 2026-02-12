<?php
/**
 * AI Reply Assistant — Plugin Configuration
 *
 * Defines all admin settings for the plugin. Extends osTicket's PluginConfig
 * system for seamless integration with the Admin Panel.
 *
 * Settings Groups:
 *   1. General (enable/disable, log level)
 *   2. OpenAI Connection (API key, model, tokens, temperature)
 *   3. Filtering Rules (departments, priorities, tags, statuses)
 *   4. Context & Prompt (message count, language, KB settings)
 *   5. Privacy & Security (PII redaction)
 *   6. Rate Limiting (per-ticket, global)
 *
 * @package AiReplyAssistant
 */

class AiReplyPluginConfig extends PluginConfig {

    /**
     * Define all plugin configuration options.
     *
     * osTicket calls this to render the admin settings form.
     *
     * @return array Form field definitions
     */
    function getOptions() {
        // Gather dynamic choices from osTicket
        $departmentChoices = self::getDepartmentChoices();
        $priorityChoices   = self::getPriorityChoices();
        $statusChoices     = self::getStatusChoices();

        return array(

            // ── Group 1: General ──────────────────────────────────────

            'general_section' => new SectionBreakField(array(
                'label' => 'General Settings',
                'hint'  => 'Master switch and logging configuration.',
            )),

            'enabled' => new BooleanField(array(
                'label'   => 'Enable Plugin',
                'default' => false,
                'hint'    => 'Master switch — when OFF, the plugin does nothing.',
            )),

            'log_level' => new ChoiceField(array(
                'label'   => 'Log Level',
                'default' => 'error',
                'choices' => array(
                    'off'   => 'OFF — No logging',
                    'error' => 'ERROR — Errors only',
                    'debug' => 'DEBUG — Verbose (for troubleshooting)',
                ),
                'hint' => 'Controls how much detail is written to the AI log table.',
            )),

            // ── Group 2: OpenAI Connection ────────────────────────────

            'openai_section' => new SectionBreakField(array(
                'label' => 'OpenAI Connection',
                'hint'  => 'Configure your OpenAI API credentials and model settings.',
            )),

            'openai_api_key' => new TextboxField(array(
                'label'         => 'API Key',
                'configuration' => array('size' => 80, 'length' => 200),
                'hint'          => 'Your OpenAI API key (starts with sk-proj-...).',
                'required'      => true,
            )),

            'openai_model' => new ChoiceField(array(
                'label'   => 'Model',
                'default' => 'gpt-4.1-mini',
                'choices' => array(
                    'gpt-4.1-mini'  => 'gpt-4.1-mini — Fast & affordable (recommended)',
                    'gpt-4.1-nano'  => 'gpt-4.1-nano — Fastest, cheapest',
                    'gpt-4.1'       => 'gpt-4.1 — Highest quality',
                    'gpt-4o'        => 'gpt-4o — Good balance (legacy)',
                    'gpt-4o-mini'   => 'gpt-4o-mini — Fast (legacy)',
                    'gpt-3.5-turbo' => 'gpt-3.5-turbo — Budget (legacy)',
                ),
                'hint' => 'Select the OpenAI model. For custom model names, use the field below.',
            )),

            'openai_model_custom' => new TextboxField(array(
                'label'         => 'Custom Model Name',
                'configuration' => array('size' => 40),
                'hint'          => 'If set, overrides the dropdown above. Use for fine-tuned or newer models.',
            )),

            'openai_max_tokens' => new TextboxField(array(
                'label'         => 'Max Tokens',
                'default'       => '1024',
                'configuration' => array('size' => 10),
                'hint'          => 'Maximum response tokens (100–4096).',
            )),

            'openai_temperature' => new TextboxField(array(
                'label'         => 'Temperature',
                'default'       => '0.3',
                'configuration' => array('size' => 10),
                'hint'          => 'Controls randomness (0.0 = deterministic, 2.0 = very creative). Recommended: 0.3.',
            )),

            'openai_timeout' => new TextboxField(array(
                'label'         => 'Request Timeout (seconds)',
                'default'       => '20',
                'configuration' => array('size' => 10),
                'hint'          => 'HTTP timeout for OpenAI API calls (5–60 seconds).',
            )),

            'openai_retry_count' => new TextboxField(array(
                'label'         => 'Retry Count',
                'default'       => '1',
                'configuration' => array('size' => 10),
                'hint'          => 'Number of retries on API failure (0–3). Auth errors are never retried.',
            )),

            // ── Group 3: Filtering Rules ──────────────────────────────

            'filtering_section' => new SectionBreakField(array(
                'label' => 'Filtering Rules',
                'hint'  => 'Control which tickets trigger AI draft generation.',
            )),

            'allowed_departments' => new ChoiceField(array(
                'label'         => 'Allowed Departments',
                'choices'       => $departmentChoices,
                'default'       => '0',
                'configuration' => array('multiselect' => true),
                'hint'          => 'Departments where AI drafts are generated. Select "All Departments" for no restriction.',
            )),

            'blocked_priorities' => new ChoiceField(array(
                'label'         => 'Blocked Priorities',
                'choices'       => $priorityChoices,
                'configuration' => array('multiselect' => true),
                'hint'          => 'Tickets with these priorities will NOT trigger AI drafts.',
            )),

            'blocked_tags' => new TextareaField(array(
                'label'         => 'Blocked Tags',
                'default'       => 'incident,security,legal,billing',
                'configuration' => array('rows' => 3, 'cols' => 60),
                'hint'          => 'Comma-separated tag names. Tickets with any of these tags are skipped.',
            )),

            'allowed_statuses' => new ChoiceField(array(
                'label'         => 'Allowed Statuses',
                'choices'       => $statusChoices,
                'default'       => 'open',
                'configuration' => array('multiselect' => true),
                'hint'          => 'Only tickets with these statuses trigger AI drafts.',
            )),

            'attachment_policy' => new ChoiceField(array(
                'label'   => 'Attachment Policy',
                'default' => 'ignore',
                'choices' => array(
                    'ignore' => 'Ignore — Process message text, skip attachments',
                    'deny'   => 'Deny — Skip AI if message has attachments',
                ),
                'hint' => 'How to handle messages that include file attachments.',
            )),

            // ── Group 4: Context & Prompt ─────────────────────────────

            'context_section' => new SectionBreakField(array(
                'label' => 'Context & Prompt Settings',
                'hint'  => 'Configure how ticket context is assembled for the AI model.',
            )),

            'context_message_count' => new TextboxField(array(
                'label'         => 'Message History Count',
                'default'       => '6',
                'configuration' => array('size' => 10),
                'hint'          => 'Number of recent thread messages to include (1–20).',
            )),

            'max_message_length' => new TextboxField(array(
                'label'         => 'Max Message Length',
                'default'       => '2000',
                'configuration' => array('size' => 10),
                'hint'          => 'Truncate each message to this many characters (100–10000).',
            )),

            'response_language' => new TextboxField(array(
                'label'         => 'Response Language',
                'default'       => 'Serbian (ekavica, Latin script)',
                'configuration' => array('size' => 60),
                'hint'          => 'Language instruction for the AI model (e.g., "English", "German", "Serbian (ekavica, Latin script)").',
            )),

            'mini_kb_content' => new TextareaField(array(
                'label'         => 'Mini Knowledge Base',
                'configuration' => array('rows' => 10, 'cols' => 80),
                'hint'          => 'Paste FAQ or reference content here (max 4000 chars). Included in every AI prompt. Leave empty to skip.',
            )),

            'use_osticket_kb' => new BooleanField(array(
                'label'   => 'Use osTicket Knowledge Base',
                'default' => false,
                'hint'    => 'Search osTicket\'s built-in FAQ module for relevant articles.',
            )),

            'use_canned_responses' => new BooleanField(array(
                'label'   => 'Use Canned Responses',
                'default' => true,
                'hint'    => 'Search osTicket\'s Canned Responses for relevant reply templates.',
            )),

            'kb_article_limit' => new TextboxField(array(
                'label'         => 'KB / Canned Article Limit',
                'default'       => '3',
                'configuration' => array('size' => 10),
                'hint'          => 'Maximum items to include per knowledge source (1–5).',
            )),

            // ── Group 5: Privacy & Security ───────────────────────────

            'privacy_section' => new SectionBreakField(array(
                'label' => 'Privacy & Security',
                'hint'  => 'Configure PII redaction before data is sent to OpenAI.',
            )),

            'pii_redaction_enabled' => new BooleanField(array(
                'label'   => 'PII Redaction',
                'default' => true,
                'hint'    => 'Mask emails, phone numbers, card numbers, and personal IDs before sending to OpenAI.',
            )),

            'redact_ip_addresses' => new BooleanField(array(
                'label'   => 'Redact IP Addresses',
                'default' => false,
                'hint'    => 'Also mask IP addresses. OFF by default — IPs are often needed for troubleshooting.',
            )),

            // ── Group 6: Rate Limiting ────────────────────────────────

            'rate_section' => new SectionBreakField(array(
                'label' => 'Rate Limiting',
                'hint'  => 'Prevent excessive AI API calls.',
            )),

            'rate_limit_per_ticket_seconds' => new TextboxField(array(
                'label'         => 'Per-Ticket Cooldown (seconds)',
                'default'       => '120',
                'configuration' => array('size' => 10),
                'hint'          => 'Minimum seconds between AI calls for the same ticket (0 = no limit).',
            )),

            'rate_limit_global_per_minute' => new TextboxField(array(
                'label'         => 'Global Limit (per minute)',
                'default'       => '60',
                'configuration' => array('size' => 10),
                'hint'          => 'Maximum AI calls per minute across all tickets (0 = no limit).',
            )),
        );
    }

    /**
     * Pre-save validation.
     *
     * @param  array &$errors Errors array (field => message)
     * @return bool True if valid
     */
    function pre_save(&$config, &$errors) {
        // Validate API key
        $apiKey = trim($config['openai_api_key'] ?? '');
        if ($config['enabled'] && empty($apiKey)) {
            $errors['err'] = 'OpenAI API Key is required when the plugin is enabled.';
            return false;
        }

        // Validate numeric fields
        $numericFields = array(
            'openai_max_tokens'             => array(100, 4096, 'Max Tokens'),
            'openai_timeout'                => array(5, 60, 'Timeout'),
            'openai_retry_count'            => array(0, 3, 'Retry Count'),
            'context_message_count'         => array(1, 20, 'Message History Count'),
            'max_message_length'            => array(100, 10000, 'Max Message Length'),
            'kb_article_limit'              => array(1, 5, 'KB Article Limit'),
            'rate_limit_per_ticket_seconds' => array(0, 3600, 'Per-Ticket Cooldown'),
            'rate_limit_global_per_minute'  => array(0, 1000, 'Global Limit'),
        );

        foreach ($numericFields as $key => $spec) {
            list($min, $max, $label) = $spec;
            $val = (int) ($config[$key] ?? $min);
            if ($val < $min || $val > $max) {
                $errors['err'] = sprintf('%s must be between %d and %d.', $label, $min, $max);
                return false;
            }
        }

        // Validate temperature
        $temp = (float) ($config['openai_temperature'] ?? 0.3);
        if ($temp < 0.0 || $temp > 2.0) {
            $errors['err'] = 'Temperature must be between 0.0 and 2.0.';
            return false;
        }

        // Trim mini KB content to 4000 chars
        if (!empty($config['mini_kb_content'])) {
            $config['mini_kb_content'] = mb_substr($config['mini_kb_content'], 0, 4000);
        }

        return true;
    }

    // ── Helper methods — dynamic choices from osTicket DB ────────

    /**
     * Get department list for multiselect.
     *
     * @return array [id => name]
     */
    static function getDepartmentChoices() {
        $choices = array('0' => '— All Departments —');
        // osTicket 1.18.x: Dept class or direct query
        $sql = "SELECT id, name FROM " . DEPT_TABLE . " WHERE flags & 1 = 0 ORDER BY name";
        $res = db_query($sql);
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $choices[$row['id']] = $row['name'];
            }
        }
        return $choices;
    }

    /**
     * Get priority list for multiselect.
     *
     * @return array [priority_id => priority_desc]
     */
    static function getPriorityChoices() {
        $choices = array();
        $sql = "SELECT priority_id, priority_desc FROM " . PRIORITY_TABLE . " ORDER BY priority_urgency DESC";
        $res = db_query($sql);
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $choices[$row['priority_id']] = $row['priority_desc'];
            }
        }
        return $choices;
    }

    /**
     * Get ticket status list for multiselect.
     *
     * @return array [id => name]
     */
    static function getStatusChoices() {
        $choices = array();
        $sql = "SELECT id, name FROM " . TABLE_PREFIX . "ticket_status ORDER BY sort";
        $res = db_query($sql);
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $choices[$row['id']] = $row['name'];
            }
        }
        // Fallback if query fails
        if (empty($choices)) {
            $choices = array(
                '1' => 'Open',
                '2' => 'Resolved',
                '3' => 'Closed',
                '4' => 'Archived',
                '5' => 'Deleted',
            );
        }
        return $choices;
    }
}
