<?php
/**
 * AI Reply Assistant — Main Plugin Class
 *
 * Handles plugin lifecycle: bootstrap, table creation, signal registration.
 * Extends osTicket's Plugin base class.
 *
 * @package    AiReplyAssistant
 * @author     Sasa Bajic - BS Computer / BSC IT Solutions
 * @link       https://bscomputer.com
 * @link       https://bscsolutions.rs
 * @license    GPL-2.0-or-later
 * @copyright  2026 BS Computer / BSC IT Solutions
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/classes/EventRouter.php';
require_once __DIR__ . '/classes/GatingLogic.php';
require_once __DIR__ . '/classes/PiiRedactor.php';
require_once __DIR__ . '/classes/ContextBuilder.php';
require_once __DIR__ . '/classes/RateLimiter.php';
require_once __DIR__ . '/classes/OpenAiClient.php';
require_once __DIR__ . '/classes/ResponseParser.php';
require_once __DIR__ . '/classes/NoteWriter.php';
require_once __DIR__ . '/classes/LogWriter.php';
require_once __DIR__ . '/classes/KbRetriever.php';

class AiReplyPlugin extends Plugin {

    /** @var string Plugin version for schema tracking */
    const VERSION = '1.0.0';

    /** @var string Schema version for migration tracking */
    const SCHEMA_VERSION = '001';

    /**
     * Plugin configuration class name.
     * osTicket uses this to instantiate the config form.
     *
     * @var string
     */
    var $config_class = 'AiReplyPluginConfig';

    /**
     * Bootstrap the plugin.
     *
     * Called by osTicket when the plugin is loaded. Registers signal handlers
     * and ensures the database table exists.
     *
     * @return void
     */
    function bootstrap() {
        // Only run if plugin is enabled
        $config = $this->getConfig();
        if (!$config) {
            return;
        }
        AiReplyLogger::setLevel($config->get('log_level'));
        if (!$config->get('enabled')) {
            return;
        }

        // Ensure log table exists (safe to call on every load — checks first)
        $this->ensureDbTable();

        // Register signal handlers for ticket events
        $router = new AiReplyEventRouter($config);
        $router->register();

        // Register UI hooks ("Generate AI Draft" button in ticket view)
        $router->registerUiHooks();

        // Register AJAX route through osTicket's built-in dispatcher.
        // This avoids .htaccess blocking issues with include/ directory.
        // The route becomes: scp/ajax.php/ai-reply/generate (POST)
        Signal::connect('ajax.scp', function($dispatcher) use ($config) {
            $dispatcher->append(
                url_post('^/ai-reply/generate$', function() use ($config) {
                    global $thisstaff;

                    header('Content-Type: application/json; charset=utf-8');

                    if (!$thisstaff || !$thisstaff->getId()) {
                        Http::response(403, json_encode(array(
                            'success' => false,
                            'error'   => 'Staff authentication required.',
                        )));
                        return;
                    }

                    $ticketId = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
                    if ($ticketId <= 0) {
                        echo json_encode(array('success' => false, 'error' => 'Missing or invalid ticket_id.'));
                        return;
                    }

                    $ticket = Ticket::lookup($ticketId);
                    if (!$ticket) {
                        echo json_encode(array('success' => false, 'error' => 'Ticket not found.'));
                        return;
                    }

                    // Verify staff has access to this ticket
                    if (!$ticket->checkStaffPerm($thisstaff)) {
                        echo json_encode(array('success' => false, 'error' => 'Access denied for this ticket.'));
                        return;
                    }

                    $router = new AiReplyEventRouter($config);
                    $result = $router->triggerForTicket($ticket);
                    echo json_encode($result);
                })
            );
        });

        AiReplyLogger::debug('bootstrap: handlers registered');
    }

    /**
     * Find the active plugin instance config.
     *
     * Used by the AJAX endpoint (ajax.php) which runs outside the normal
     * plugin lifecycle and needs to obtain the config independently.
     *
     * @return AiReplyPluginConfig|null
     */
    public static function getActiveConfig() {
        try {
            // Find our plugin + active instance in the database
            $sql = "SELECT pi.id "
                 . "FROM " . TABLE_PREFIX . "plugin p "
                 . "JOIN " . TABLE_PREFIX . "plugin_instance pi ON pi.plugin_id = p.id "
                 . "WHERE p.install_path = 'ai-reply-assistant' "
                 . "AND pi.isactive = 1 "
                 . "LIMIT 1";

            $res = db_query($sql);
            if ($res && ($row = db_fetch_array($res))) {
                return new AiReplyPluginConfig((int) $row['id']);
            }

            // Fallback: try plugin table directly (older osTicket schemas)
            $sql = "SELECT id FROM " . TABLE_PREFIX . "plugin "
                 . "WHERE install_path = 'ai-reply-assistant' "
                 . "AND isactive = 1 "
                 . "LIMIT 1";

            $res = db_query($sql);
            if ($res && ($row = db_fetch_array($res))) {
                return new AiReplyPluginConfig((int) $row['id']);
            }
        } catch (\Exception $e) {
            AiReplyLogger::error('getActiveConfig: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Ensure the AI reply log table exists in the database.
     *
     * Checks for the table and creates it if missing. Uses osTicket's
     * configured table prefix. Safe to call multiple times.
     *
     * @return void
     */
    private function ensureDbTable() {
        $tableName = TABLE_PREFIX . 'ai_reply_log';

        // Check if table already exists
        $sql = "SHOW TABLES LIKE '" . db_input($tableName) . "'";
        $res = db_query($sql);
        if ($res && db_num_rows($res) > 0) {
            return; // Table exists
        }

        // Create table
        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        db_query($sql);
    }
}
