# 03 – Gating Logic

## 1. Overview

The gating module decides whether a given ticket + message combination should be
sent to OpenAI for draft generation. It returns **ALLOW** or **DENY** with a reason.

All checks are evaluated in order. The first DENY stops evaluation (short-circuit).

## 2. Check Order & Priority

```
1. [DENY] Is plugin enabled?            → if disabled: DENY "plugin disabled"
2. [DENY] Is message empty?             → if empty: DENY "empty message"
3. [DENY] Is ticket status allowed?     → if not in allowlist: DENY "status not allowed"
4. [DENY] Is department allowed?        → if not in allowlist: DENY "department not allowed"
5. [DENY] Is priority blocked?          → if in blocklist: DENY "priority blocked"
6. [DENY] Are tags clean?              → if any tag in blocklist: DENY "tag blocked: {tag}"
7. [DENY] Attachment check              → if has attachments & policy=deny: DENY "has attachments"
8. [DENY] Rate limit (per-ticket)       → if too recent: DENY "rate limited (per-ticket)"
9. [DENY] Rate limit (global)           → if too many: DENY "rate limited (global)"
10.[SPECIAL] Sensitive data check       → if detected: ALLOW but modify prompt (see §3)
11.[ALLOW] All checks passed
```

## 3. Detailed Check Specifications

### 3.1 Plugin Enabled

- Source: Admin setting `enabled` (boolean)
- Logic: `if (!$config->get('enabled')) → DENY`

### 3.2 Empty Message

- Source: Thread entry body text (stripped of HTML)
- Logic: `if (trim(strip_tags($body)) === '') → DENY`
- Note: Some messages may be HTML-only with images — treated as empty for text-based AI

### 3.3 Ticket Status Allowlist

- Source: Admin setting `allowed_statuses` (multi-select)
- Default: `['open']`
- Available statuses in osTicket 1.18.x: `open`, `closed`, `archived`, `deleted`
- Custom statuses: also supported — match by status ID or name
- Logic: `if (!in_array($ticket->getStatusId(), $allowedStatuses)) → DENY`

**Design Decision**: Use status ID (integer) for matching, display name in UI.

### 3.4 Department Allowlist

- Source: Admin setting `allowed_departments` (multi-select or comma-separated IDs)
- Special value: `*` or empty = all departments allowed
- Logic: `if ($allowlist !== '*' && !in_array($ticket->getDeptId(), $allowlist)) → DENY`

**Recommendation**: Default to `*` (all departments) for easy initial setup.
Admin can narrow down later.

### 3.5 Priority Blocklist

- Source: Admin setting `blocked_priorities` (multi-select)
- Default priorities in osTicket: Low (1), Normal (2), High (3), Emergency (4)
- Default blocklist: `['Emergency']` (ID 4)
- Logic: `if (in_array($ticket->getPriorityId(), $blocklist)) → DENY`

### 3.6 Tag Blocklist

- Source: Admin setting `blocked_tags` (comma-separated strings)
- Default: `incident, security, legal, billing`
- Logic:
  ```
  $ticketTags = $ticket->getTags();  // array of tag names
  $blockedTags = $config->get('blocked_tags');  // array from config
  $intersection = array_intersect($ticketTags, $blockedTags);
  if (!empty($intersection)) → DENY "tag blocked: {first_match}"
  ```

**Note on osTicket 1.18.x Tags**: Tickets may not always have tags. If the ticket
has no tags, this check passes automatically.

### 3.7 Attachment Check

- Source: Admin setting `attachment_policy` — values: `ignore`, `deny`, `include_metadata`
- Default: `ignore` (process message text, ignore attachments)
- Logic:
  - `deny`: If message has attachments → DENY
  - `ignore`: Process message text only, skip attachments (ALLOW)
  - `include_metadata`: (Post-MVP) Include attachment filenames/types in context

```
if ($policy === 'deny' && $entry->getAttachments()->count() > 0) → DENY
```

### 3.8 Rate Limit (Per-Ticket)

- Source: Admin setting `rate_limit_per_ticket_seconds` (integer, default: 120)
- Logic: Query `ost_ai_reply_log` for the most recent `generated` entry for this ticket
  ```sql
  SELECT created_at FROM ost_ai_reply_log
  WHERE ticket_id = ? AND status = 'generated'
  ORDER BY created_at DESC LIMIT 1
  ```
  If `NOW() - created_at < rate_limit_per_ticket_seconds` → DENY

### 3.9 Rate Limit (Global)

- Source: Admin setting `rate_limit_global_per_minute` (integer, default: 60)
- Logic: Count `generated` entries in the last 60 seconds
  ```sql
  SELECT COUNT(*) FROM ost_ai_reply_log
  WHERE status = 'generated'
  AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
  ```
  If count >= limit → DENY

### 3.10 Sensitive Data Detection

This is a **special check** — it does NOT deny processing, but modifies behavior.

- Source: Admin setting `pii_redaction_enabled` (boolean, default: ON)
- Patterns detected:
  - Credit card numbers (Visa, MC, Amex patterns)
  - JMBG / personal ID numbers (13-digit Serbian pattern)
  - Password-like patterns (`password:`, `lozinka:`, `pass:`)
  - SSN patterns (if configured)
- **If sensitive data is detected in user message**:
  - ALLOW processing (do not skip)
  - Set flag `sensitive_data_detected = true`
  - Pass flag to ContextBuilder → modifies prompt to instruct model:
    - "The customer has shared sensitive data. Do NOT request sensitive information."
    - "Suggest the customer use a secure channel or alternative verification."
  - PII Redactor still masks the actual values before sending to OpenAI

## 4. Gating Result Object

```php
class GatingResult {
    public bool $allowed;
    public string $reason;           // Human-readable reason
    public string $reasonCode;       // Machine-readable code for logging
    public bool $sensitiveDataFound; // Flag for prompt modification
    public array $sensitiveTypes;    // e.g. ['credit_card', 'jmbg']
}
```

### Result Codes

| Code | Meaning |
|------|---------|
| `plugin_disabled` | Plugin is turned off |
| `empty_message` | Message body has no text |
| `status_not_allowed` | Ticket status not in allowlist |
| `department_not_allowed` | Department not in allowlist |
| `priority_blocked` | Priority is in blocklist |
| `tag_blocked` | One or more tags in blocklist |
| `has_attachments` | Attachment policy = deny and message has attachments |
| `rate_limit_ticket` | Too soon since last AI generation for this ticket |
| `rate_limit_global` | Too many global AI generations per minute |
| `allowed` | All checks passed |

## 5. Configuration Defaults Summary

| Setting | Default | Notes |
|---------|---------|-------|
| `enabled` | `false` | Must be explicitly enabled |
| `allowed_statuses` | `['open']` | Only open tickets |
| `allowed_departments` | `*` | All departments |
| `blocked_priorities` | `[4]` (Emergency) | Skip emergencies |
| `blocked_tags` | `incident,security,legal,billing` | Common sensitive categories |
| `attachment_policy` | `ignore` | Process text, skip attachments |
| `rate_limit_per_ticket_seconds` | `120` | 2-minute cooldown per ticket |
| `rate_limit_global_per_minute` | `60` | Max 60 AI calls per minute |
| `pii_redaction_enabled` | `true` | Always redact PII before sending |

## 6. Extensibility Notes

The gating system is designed as a chain of checks. Post-MVP additions:
- Custom check plugins (hook into gating chain)
- Time-based rules (business hours only)
- Customer-tier checks (VIP vs. regular)
- Topic/help-topic based filtering
