<?php
/**
 * AI Reply Assistant — AJAX Endpoint
 *
 * Handles manual "Generate AI Draft" requests from the ticket view button.
 * Bootstraps osTicket via staff.inc.php for authentication and CSRF.
 *
 * POST parameters:
 *   ticket_id     — The ticket to generate a draft for
 *   __CSRFToken__ — osTicket CSRF token (validated by staff.inc.php)
 *
 * @package AiReplyAssistant
 */

// ── Bootstrap osTicket staff panel ──────────────────────────────────────
// Path: include/plugins/ai-reply-assistant/ajax.php
// Target: scp/staff.inc.php (handles DB, session, auth, CSRF)

$scpDir = realpath(dirname(__FILE__) . '/../../../scp');
if ($scpDir === false || !file_exists($scpDir . DIRECTORY_SEPARATOR . 'staff.inc.php')) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(array('success' => false, 'error' => 'Cannot locate osTicket. Plugin path may be incorrect.')));
}

// Flag as AJAX so osTicket returns 403 instead of HTML redirect on auth failure
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

// Capture any output from bootstrap (headers, notices, whitespace)
ob_start();
require $scpDir . DIRECTORY_SEPARATOR . 'staff.inc.php';
ob_end_clean();

// ── Set response headers ────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// ── Verify staff authentication ─────────────────────────────────────────
// staff.inc.php should have set $thisstaff or exited with 403
if (!isset($thisstaff) || !$thisstaff || !$thisstaff->isAuthenticated()) {
    http_response_code(403);
    die(json_encode(array('success' => false, 'error' => 'Staff authentication required.')));
}

// ── Only accept POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(array('success' => false, 'error' => 'POST method required.')));
}

// ── Validate ticket ID ──────────────────────────────────────────────────
$ticketId = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
if ($ticketId <= 0) {
    http_response_code(400);
    die(json_encode(array('success' => false, 'error' => 'Missing or invalid ticket_id.')));
}

// ── Load ticket ─────────────────────────────────────────────────────────
$ticket = Ticket::lookup($ticketId);
if (!$ticket) {
    http_response_code(404);
    die(json_encode(array('success' => false, 'error' => 'Ticket not found.')));
}

// ── Load plugin ─────────────────────────────────────────────────────────
require_once dirname(__FILE__) . '/class.AiReplyPlugin.php';

$config = AiReplyPlugin::getActiveConfig();
if (!$config) {
    http_response_code(500);
    die(json_encode(array(
        'success' => false,
        'error'   => 'AI Reply Assistant plugin is not active or not configured.',
    )));
}

// ── Run AI pipeline ─────────────────────────────────────────────────────
$router = new AiReplyEventRouter($config);
$result = $router->triggerForTicket($ticket);

echo json_encode($result);
