<?php
/**
 * AI Reply Assistant — Event Router
 *
 * Listens to osTicket signals and routes user-originated messages to the
 * AI processing pipeline. Ignores agent replies, internal notes, and
 * system events.
 *
 * Signals handled:
 *   - ticket.created      → New ticket with initial customer message
 *   - threadentry.created  → New thread entry (filters to user messages only)
 *
 * @package AiReplyAssistant
 */

class AiReplyEventRouter {

    /** @var PluginConfig Plugin configuration instance */
    private $config;

    /**
     * @param PluginConfig $config Plugin configuration instance
     */
    public function __construct(PluginConfig $config) {
        $this->config = $config;
    }

    /**
     * Register signal handlers with osTicket.
     *
     * @return void
     */
    public function register() {
        // Listen for new tickets
        Signal::connect('ticket.created', array($this, 'onTicketCreated'));

        // Listen for new thread entries (replies, notes)
        Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));
    }

    /**
     * Register UI hooks for ticket view ("Generate AI Draft" button).
     *
     * @return void
     */
    public function registerUiHooks() {
        Signal::connect('ticket.view.more', array($this, 'onTicketViewMore'));
    }

    /**
     * Inject "Generate AI Draft" button into ticket view.
     *
     * The ticket.view.more signal fires inside the <ul> of the "More" dropdown
     * in osTicket's ticket view (ticket-view.inc.php). We output:
     *   1. A <li> menu item in the "More" dropdown (always visible, 100% reliable)
     *   2. A <script> that adds a standalone visible button in the top action bar
     *
     * @param Ticket $ticket The ticket being viewed
     * @return void
     */
    public function onTicketViewMore($ticket) {
        if (!($ticket instanceof Ticket)) {
            return;
        }

        $ticketId = (int) $ticket->getId();

        // Build AJAX endpoint URL — route through osTicket's scp/ajax.php dispatcher
        // (avoids include/.htaccess blocking; auth + session handled by osTicket)
        $ajaxUrl = 'ajax.php/ai-reply/generate';

        // 1. Output a <li> menu item in the "More" dropdown (direct, reliable)
        echo '<li><a class="ai-reply-generate" href="#" data-ticket-id="'
            . $ticketId . '"><i class="icon-bolt"></i> '
            . 'Generate AI Draft</a></li>';

        // 2. Output JavaScript for AJAX handling + standalone button in action bar
        static $jsRendered = false;
        if ($jsRendered) {
            return;
        }
        $jsRendered = true;

        echo '<script type="text/javascript">
        (function($) {
            console.log("[AI Reply Assistant] Script loaded for ticket #' . $ticketId . '");

            // ── Create standalone "AI Draft" button for the top action bar ──
            function injectAiButton() {
                // The server-rendered "More" menu item exists only on a real ticket
                // view. If it is missing (e.g. PJAX navigated to the ticket list),
                // remove any leftover standalone button and stop.
                var $item = $("a.ai-reply-generate").not(".ai-reply-btn-standalone").first();
                if (!$item.length) {
                    $(".ai-reply-btn-standalone").remove();
                    return;
                }
                var ticketId = $item.attr("data-ticket-id");

                // Skip if already injected for this same ticket; replace if stale
                var $existing = $(".ai-reply-btn-standalone");
                if ($existing.length) {
                    if ($existing.attr("data-ticket-id") === ticketId) {
                        console.log("[AI Reply Assistant] Button already exists, skipping");
                        return;
                    }
                    $existing.remove();
                }

                var $aiBtn = $("<a/>", {
                    "href": "#",
                    "class": "action-button pull-right ai-reply-btn-standalone ai-reply-generate",
                    "data-ticket-id": ticketId,
                    "data-placement": "bottom",
                    "data-toggle": "tooltip",
                    "title": "Generate AI Draft Reply"
                }).html("<i class=\"icon-bolt\"></i> AI Draft").css({
                    "background-color": "#5b7fb5",
                    "color": "#fff",
                    "border-color": "#4a6d9e"
                }).hover(
                    function() { $(this).css("background-color", "#4a6d9e"); },
                    function() { $(this).css("background-color", "#5b7fb5"); }
                );

                var inserted = false;

                // Strategy 1: Insert before the "More" dropdown button
                var $moreBtn = $(\'span[data-dropdown="#action-dropdown-more"]\');
                if ($moreBtn.length) {
                    $moreBtn.before($aiBtn);
                    inserted = true;
                    console.log("[AI Reply Assistant] Button injected before More button");
                }

                // Strategy 2: Insert before the Edit button
                if (!inserted) {
                    var $editBtn = $("a.action-button[href*=\\"a=edit\\"]");
                    if ($editBtn.length) {
                        $editBtn.before($aiBtn);
                        inserted = true;
                        console.log("[AI Reply Assistant] Button injected before Edit button");
                    }
                }

                // Strategy 3: Insert before the Print dropdown
                if (!inserted) {
                    var $printBtn = $(\'span[data-dropdown="#action-dropdown-print"]\');
                    if ($printBtn.length) {
                        $printBtn.before($aiBtn);
                        inserted = true;
                        console.log("[AI Reply Assistant] Button injected before Print button");
                    }
                }

                // Strategy 4: Append to the action bar container
                if (!inserted) {
                    var $actionBar = $("div.sticky.bar.opaque .pull-right, div.sticky.bar .flush-right").first();
                    if ($actionBar.length) {
                        $actionBar.prepend($aiBtn);
                        inserted = true;
                        console.log("[AI Reply Assistant] Button prepended to action bar");
                    }
                }

                if (!inserted) {
                    console.log("[AI Reply Assistant] Could not inject standalone button — menu item in More dropdown is still available");
                }
            }

            // ── AJAX handler for both menu item and standalone button ──
            $(document).off("click.aireply").on("click.aireply", ".ai-reply-generate", function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $btn = $(this);
                if ($btn.hasClass("disabled")) return;

                var tid = $btn.attr("data-ticket-id");
                var origHtml = $btn.html();

                $btn.addClass("disabled")
                    .css({"opacity": "0.6", "cursor": "wait"})
                    .html("<i class=\"icon-spinner icon-spin\"></i> Generating...");

                // Close the More dropdown if open
                $(".action-dropdown").hide();

                var csrfToken = $("input[name=__CSRFToken__]").val()
                             || $("meta[name=csrf_token]").attr("content")
                             || "";

                $.ajax({
                    url: "' . $ajaxUrl . '",
                    type: "POST",
                    data: {
                        ticket_id: tid,
                        __CSRFToken__: csrfToken
                    },
                    dataType: "json",
                    timeout: 90000,
                    success: function(resp) {
                        if (resp.success) {
                            $btn.css({"opacity": "1", "background-color": "#27ae60"})
                                .html("<i class=\"icon-ok\"></i> Done!");
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            alert("AI Draft Error: " + (resp.error || "Unknown error"));
                            $btn.removeClass("disabled").css({"opacity": "1", "cursor": "pointer"}).html(origHtml);
                        }
                    },
                    error: function(xhr) {
                        var msg = "Failed to contact AI Draft endpoint.";
                        if (xhr.status === 403) msg = "Access denied. Please log in again.";
                        if (xhr.status === 404) msg = "AJAX endpoint not found. Check plugin installation path.";
                        if (xhr.status === 500) msg = "Server error. Check PHP error log.";
                        alert("AI Draft Error: " + msg + " (HTTP " + xhr.status + ")");
                        $btn.removeClass("disabled").css({"opacity": "1", "cursor": "pointer"}).html(origHtml);
                    }
                });
            });

            // Run injection on DOM ready
            $(function() {
                injectAiButton();
            });

            // Also run on PJAX completion (osTicket uses PJAX for navigation)
            $(document).off("pjax:end.aireply").on("pjax:end.aireply", function() {
                injectAiButton();
            });

        })(jQuery);
        </script>';
    }

    /**
     * Manually trigger AI draft generation for an existing ticket.
     *
     * Called from the AJAX endpoint when agent clicks "Generate AI Draft".
     * Finds the last customer message and runs the pipeline with gating bypassed.
     *
     * @param  Ticket $ticket
     * @return array  { success: bool, error?: string, confidence?: float, message?: string }
     */
    public function triggerForTicket(Ticket $ticket) {
        try {
            $thread = $ticket->getThread();
            if (!$thread) {
                return array('success' => false, 'error' => 'Ticket has no thread.');
            }

            // Find the most recent customer message (type 'M')
            // Use getEntries() without params, then iterate all to find last 'M'
            $entries = $thread->getEntries();
            $customerEntry = null;

            if ($entries) {
                foreach ($entries as $e) {
                    if ($e->getType() === 'M') {
                        $customerEntry = $e;
                        // Don't break — keep iterating to find the LAST message
                    }
                }
            }

            if (!$customerEntry) {
                return array('success' => false, 'error' => 'No customer message found on this ticket.');
            }

            // Run pipeline with manual=true (bypass gating)
            return $this->processMessage($ticket, $customerEntry, true);

        } catch (\Exception $e) {
            return array('success' => false, 'error' => 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle new ticket creation.
     *
     * Extracts the initial message and processes it if it's from an end-user.
     *
     * @param Ticket $ticket The newly created ticket
     * @return void
     */
    public function onTicketCreated($ticket) {
        try {
            if (!($ticket instanceof Ticket)) {
                return;
            }

            // Get the first thread entry (initial message)
            $thread = $ticket->getThread();
            if (!$thread) {
                return;
            }

            $entries = $thread->getEntries();

            if (!$entries) {
                return;
            }

            // Get the first entry (initial message)
            $entry = null;
            foreach ($entries as $e) {
                $entry = $e;
                break;
            }

            if (!$entry) {
                return;
            }

            // Only process user messages (type 'M')
            if ($entry->getType() !== 'M') {
                $this->logDebug($ticket, 'skipped: initial entry is not a user message');
                return;
            }

            // Verify the poster is not staff
            if ($this->isStaffPoster($entry)) {
                $this->logDebug($ticket, 'skipped: ticket created by staff');
                return;
            }

            // Route to AI processing pipeline
            $this->processMessage($ticket, $entry);

        } catch (\Exception $e) {
            // Never crash osTicket — log and return silently
            $this->logError('onTicketCreated exception: ' . $e->getMessage());
        }
    }

    /**
     * Handle new thread entry creation.
     *
     * Filters to user messages only (type 'M'). Ignores agent replies (type 'R')
     * and internal notes (type 'N') to prevent infinite loops and unnecessary processing.
     *
     * @param ThreadEntry $entry The newly created thread entry
     * @return void
     */
    public function onThreadEntryCreated($entry) {
        try {
            if (!($entry instanceof ThreadEntry)) {
                return;
            }

            // Only process user messages (type 'M')
            // Type 'R' = agent reply, type 'N' = internal note
            // This also prevents infinite loops when the plugin creates a note
            $type = $entry->getType();
            if ($type !== 'M') {
                return; // Silent skip — this is normal for agent replies and notes
            }

            // Verify the poster is not staff
            if ($this->isStaffPoster($entry)) {
                return;
            }

            // Get the parent ticket
            $thread = $entry->getThread();
            if (!$thread) {
                return;
            }

            $ticket = $thread->getObject();
            if (!($ticket instanceof Ticket)) {
                return;
            }

            // Route to AI processing pipeline
            $this->processMessage($ticket, $entry);

        } catch (\Exception $e) {
            // Never crash osTicket — log and return silently
            $this->logError('onThreadEntryCreated exception: ' . $e->getMessage());
        }
    }

    /**
     * Main AI processing pipeline.
     *
     * Orchestrates the full flow: gating → context building → OpenAI call →
     * response parsing → note writing → logging.
     *
     * @param Ticket      $ticket The ticket being processed
     * @param ThreadEntry $entry  The user message that triggered processing
     * @param bool        $manual If true, bypass gating (manual trigger from UI)
     * @return array      { success: bool, error?: string, confidence?: float, message?: string }
     */
    private function processMessage(Ticket $ticket, ThreadEntry $entry, $manual = false) {
        $logWriter = new AiReplyLogWriter();
        $ticketId  = (int) $ticket->getId();
        $entryId   = (int) $entry->getId();
        $modelName = $this->getModelName();

        try {
            // Step 1: Gating — should we process this ticket?
            if (!$manual) {
                $gating = new AiReplyGatingLogic($this->config);
                $gatingResult = $gating->evaluate($ticket, $entry);

                if (!$gatingResult['allowed']) {
                    $logWriter->logSkipped($ticketId, $entryId, $gatingResult['reason_code']);
                    $this->logDebug($ticket, 'skipped: ' . $gatingResult['reason']);
                    return array('success' => false, 'error' => $gatingResult['reason']);
                }
            } else {
                // Manual trigger: bypass gating, but still flag for context building
                $gatingResult = array(
                    'allowed'             => true,
                    'sensitive_data_found' => false,
                    'sensitive_types'      => array(),
                );
            }

            // Step 2: Build context (includes PII redaction and KB retrieval)
            $contextBuilder = new AiReplyContextBuilder($this->config);
            $payload = $contextBuilder->build($ticket, $entry, $gatingResult);

            // Step 3: Call OpenAI
            $client   = new AiReplyOpenAiClient($this->config);
            $response = $client->chatCompletion(
                $payload['system_prompt'],
                $payload['user_message'],
                $modelName
            );

            if (!$response['success']) {
                $logWriter->logError($ticketId, $entryId, 'openai_error', $modelName);
                $this->logError('OpenAI call failed for ticket #'
                    . $ticket->getNumber() . ': ' . $response['error']);
                return array('success' => false, 'error' => 'OpenAI error: ' . $response['error']);
            }

            // Step 4: Parse AI response
            $parser = new AiReplyResponseParser();
            $parsed = $parser->parse($response['reply_text']);

            if (!$parsed['success']) {
                $logWriter->logError($ticketId, $entryId, 'parse_error', $modelName);
                $this->logError('Response parse failed for ticket #'
                    . $ticket->getNumber() . ': ' . $parsed['error']);
                return array('success' => false, 'error' => 'Failed to parse AI response.');
            }

            // Step 5: Create internal note
            $noteWriter = new AiReplyNoteWriter($this->config);
            $noteEntry  = $noteWriter->write(
                $ticket,
                $parsed['data'],
                $response['model'],
                $response['total_tokens']
            );

            if (!$noteEntry) {
                $logWriter->logError($ticketId, $entryId, 'note_write_error', $modelName);
                $this->logError('Failed to write internal note for ticket #'
                    . $ticket->getNumber());
                return array('success' => false, 'error' => 'Failed to create internal note.');
            }

            // Step 6: Log success
            $logWriter->logGenerated(
                $ticketId,
                $entryId,
                $response['model'],
                $payload['prompt_text'],
                $response['reply_text'],
                $response['prompt_tokens'],
                $response['completion_tokens'],
                $response['total_tokens'],
                $parsed['data']['confidence'],
                $manual ? 'manual' : 'ok'
            );

            $this->logDebug($ticket, 'AI draft generated'
                . ($manual ? ' (manual)' : '')
                . ', confidence: ' . $parsed['data']['confidence']
                . ', tokens: ' . $response['total_tokens']);

            return array(
                'success'    => true,
                'message'    => 'AI draft reply generated successfully.',
                'confidence' => $parsed['data']['confidence'],
            );

        } catch (\Exception $e) {
            $logWriter->logError($ticketId, $entryId, 'exception', $modelName);
            $this->logError('processMessage exception for ticket #'
                . $ticket->getNumber() . ': ' . $e->getMessage());
            return array('success' => false, 'error' => 'Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Get the configured model name (custom overrides dropdown).
     *
     * @return string
     */
    private function getModelName() {
        $custom = trim($this->config->get('openai_model_custom') ?? '');
        if (!empty($custom)) {
            return $custom;
        }
        return $this->config->get('openai_model') ?: 'gpt-4.1-mini';
    }

    /**
     * Check if the thread entry poster is a staff member.
     *
     * @param ThreadEntry $entry
     * @return bool
     */
    private function isStaffPoster(ThreadEntry $entry) {
        // In osTicket, getUserId() returns the user (customer) ID
        // getStaffId() returns the staff ID if posted by staff
        $staffId = $entry->getStaffId();
        return !empty($staffId) && $staffId > 0;
    }

    /**
     * Log a debug message (only if log level is DEBUG).
     *
     * @param Ticket $ticket
     * @param string $message
     * @return void
     */
    private function logDebug($ticket, $message) {
        if ($this->config->get('log_level') !== 'debug') {
            return;
        }
        error_log('[AI Reply Assistant] [DEBUG] Ticket #'
            . ($ticket ? $ticket->getNumber() : '?') . ': ' . $message);
    }

    /**
     * Log an error message (if log level is ERROR or DEBUG).
     *
     * @param string $message
     * @return void
     */
    private function logError($message) {
        $level = $this->config->get('log_level');
        if ($level === 'off') {
            return;
        }
        error_log('[AI Reply Assistant] [ERROR] ' . $message);
    }
}
