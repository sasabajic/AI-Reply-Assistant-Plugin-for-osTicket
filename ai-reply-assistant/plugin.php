<?php
/**
 * AI Reply Assistant Plugin for osTicket 1.18.x
 *
 * Automatically generates draft reply suggestions for incoming support tickets
 * using OpenAI Chat Completions API. Drafts are posted as Internal Notes for
 * agent review — never sent directly to customers.
 *
 * Developed by BS Computer (https://bscomputer.com)
 * and BSC IT Solutions (https://bscsolutions.rs)
 *
 * @package    AiReplyAssistant
 * @version    1.0.4
 * @author     Sasa Bajic - BS Computer / BSC IT Solutions
 * @link       https://bscomputer.com
 * @link       https://bscsolutions.rs
 * @license    GPL-2.0-or-later
 * @copyright  2026 BS Computer / BSC IT Solutions
 */

return array(
    'id'          => 'ai-reply-assistant',
    'version'     => '1.0.4',
    'name'        => 'AI Reply Assistant',
    'author'      => 'Sasa Bajic - BS Computer / BSC IT Solutions',
    'description' => 'Generates AI-powered draft replies as internal notes using OpenAI. '
                   . 'Supports PII redaction, department/priority filtering, rate limiting, '
                   . 'knowledge base integration, and manual AI draft generation. '
                   . 'Developed by BS Computer (bscomputer.com) & BSC IT Solutions (bscsolutions.rs).',
    'url'         => 'https://bscomputer.com',
    'plugin'      => 'class.AiReplyPlugin.php:AiReplyPlugin',
);
