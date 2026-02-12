<?php
/**
 * AI Reply Assistant — Note Writer
 *
 * Creates an Internal Note on the ticket thread containing the AI-drafted reply.
 * The note is formatted with a recognizable structure so agents can easily
 * identify AI suggestions and copy/edit the draft before sending.
 *
 * Features:
 *   - Posts as SYSTEM (no alert, no auto-response)
 *   - Structured HTML body with metadata header and draft content
 *   - Includes confidence score, suggested tags, and clarifying questions
 *   - "need_more_info" flag clearly indicated
 *
 * @package AiReplyAssistant
 */

class AiReplyNoteWriter {

    /** Note title prefix for easy identification */
    const NOTE_TITLE = '🤖 AI Draft Reply';

    /** @var PluginConfig */
    private $config;

    /**
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config) {
        $this->config = $config;
    }

    /**
     * Write the AI draft reply as an Internal Note on the ticket.
     *
     * @param  Ticket $ticket     The ticket to add the note to
     * @param  array  $replyData  Parsed response data from ResponseParser
     * @param  string $model      The model used for generation
     * @param  int    $totalTokens Token count from the API response
     * @return ThreadEntry|null   The created note entry, or null on failure
     */
    public function write(Ticket $ticket, array $replyData, $model = '', $totalTokens = 0) {
        try {
            $body = $this->formatNoteBody($replyData, $model, $totalTokens);

            // Prepare note data
            $vars = array(
                'title'   => self::NOTE_TITLE,
                'note'    => $body,
                'poster'  => 'SYSTEM',
            );

            // Post as internal note — no alerts
            $errors = array();
            $note = $ticket->postNote($vars, $errors, 'SYSTEM');

            if ($note instanceof ThreadEntry) {
                return $note;
            }

            // Log error if note creation failed
            if (!empty($errors)) {
                error_log('[AI Reply Assistant] Failed to post note: ' . implode(', ', $errors));
            }

            return null;

        } catch (\Exception $e) {
            error_log('[AI Reply Assistant] NoteWriter exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Format the note body as structured HTML.
     *
     * Layout per docs/09-internal-note-format.md:
     *   - Header bar with plugin branding
     *   - Confidence & model metadata
     *   - Draft reply body (the actual suggested text)
     *   - Clarifying questions (if need_more_info)
     *   - Suggested tags
     *   - Footer with disclaimer
     *
     * @param  array  $replyData
     * @param  string $model
     * @param  int    $totalTokens
     * @return string HTML body
     */
    private function formatNoteBody(array $replyData, $model, $totalTokens) {
        $confidence   = isset($replyData['confidence']) ? $replyData['confidence'] : 0;
        $needMoreInfo = !empty($replyData['need_more_info']);
        $questions    = isset($replyData['questions']) ? $replyData['questions'] : array();
        $tags         = isset($replyData['suggested_tags']) ? $replyData['suggested_tags'] : array();
        $subject      = isset($replyData['reply_subject']) ? $replyData['reply_subject'] : '';
        $body         = isset($replyData['reply_body']) ? $replyData['reply_body'] : '';

        $confidencePct   = round($confidence * 100);
        $confidenceColor = $this->getConfidenceColor($confidence);

        $html = '';

        // ── Header ──
        $html .= '<div style="border: 2px solid #4A90D9; border-radius: 6px; margin: 5px 0; font-family: Arial, sans-serif;">';
        $html .= '<div style="background: #4A90D9; color: #fff; padding: 8px 12px; font-size: 14px; font-weight: bold;">';
        $html .= '🤖 AI Reply Assistant — Draft Suggestion';
        $html .= '</div>';

        // ── Metadata bar ──
        $html .= '<div style="background: #f0f4f8; padding: 6px 12px; font-size: 12px; color: #555; border-bottom: 1px solid #ddd;">';
        $html .= 'Confidence: <strong style="color: ' . $confidenceColor . ';">' . $confidencePct . '%</strong>';
        if (!empty($model)) {
            $html .= ' &nbsp;|&nbsp; Model: <strong>' . htmlspecialchars($model) . '</strong>';
        }
        if ($totalTokens > 0) {
            $html .= ' &nbsp;|&nbsp; Tokens: ' . number_format($totalTokens);
        }
        if ($needMoreInfo) {
            $html .= ' &nbsp;|&nbsp; <span style="color: #e67e22; font-weight: bold;">⚠ More info needed</span>';
        }
        $html .= '</div>';

        // ── Subject ──
        if (!empty($subject)) {
            $html .= '<div style="padding: 8px 12px; font-size: 13px; color: #666; border-bottom: 1px solid #eee;">';
            $html .= '<strong>Suggested Subject:</strong> ' . htmlspecialchars($subject);
            $html .= '</div>';
        }

        // ── Draft body ──
        $html .= '<div style="padding: 12px; font-size: 14px; line-height: 1.6; color: #333;">';
        $html .= '<strong>📝 Draft Reply:</strong><br><br>';
        // Convert newlines to <br> for display, escape HTML
        $html .= nl2br(htmlspecialchars($body));
        $html .= '</div>';

        // ── Clarifying questions ──
        if ($needMoreInfo && !empty($questions)) {
            $html .= '<div style="padding: 10px 12px; background: #fff8e1; border-top: 1px solid #ffe082;">';
            $html .= '<strong style="color: #e67e22;">❓ Suggested questions to ask the customer:</strong>';
            $html .= '<ol style="margin: 5px 0 5px 20px; padding: 0;">';
            foreach ($questions as $q) {
                $html .= '<li style="margin: 3px 0;">' . htmlspecialchars($q) . '</li>';
            }
            $html .= '</ol>';
            $html .= '</div>';
        }

        // ── Suggested tags ──
        if (!empty($tags)) {
            $html .= '<div style="padding: 8px 12px; border-top: 1px solid #eee; font-size: 12px;">';
            $html .= '<strong>🏷 Suggested Tags:</strong> ';
            $tagHtml = array();
            foreach ($tags as $tag) {
                $tagHtml[] = '<span style="background: #e3edf7; color: #2c5282; padding: 2px 8px; '
                           . 'border-radius: 10px; font-size: 11px; margin: 0 2px;">'
                           . htmlspecialchars($tag) . '</span>';
            }
            $html .= implode(' ', $tagHtml);
            $html .= '</div>';
        }

        // ── Footer disclaimer ──
        $html .= '<div style="padding: 6px 12px; background: #f9f9f9; border-top: 1px solid #eee; '
                . 'font-size: 11px; color: #999; font-style: italic;">';
        $html .= '⚠ This is an AI-generated draft. Review carefully before sending to the customer. '
                . 'Do not send without verification.';
        $html .= '</div>';

        $html .= '</div>'; // Close outer container

        return $html;
    }

    /**
     * Get a color code based on confidence level.
     *
     * @param  float $confidence 0.0 - 1.0
     * @return string CSS color
     */
    private function getConfidenceColor($confidence) {
        if ($confidence >= 0.8) {
            return '#27ae60'; // green — high confidence
        } elseif ($confidence >= 0.5) {
            return '#e67e22'; // orange — medium
        } else {
            return '#e74c3c'; // red — low
        }
    }
}
