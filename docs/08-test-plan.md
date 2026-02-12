# 08 – Test Plan

## 1. Overview

This document defines acceptance criteria and test scenarios for the AI Reply
Assistant plugin. Tests are manual (MVP) with clear pass/fail criteria.

## 2. Pre-Requisites

- osTicket 1.18.x installed and operational
- Plugin installed and enabled in Admin → Manage → Plugins
- Valid OpenAI API key configured
- At least one department in the allowlist
- Log level set to DEBUG for testing

## 3. Test Scenarios

---

### TC-01: New Ticket — Happy Path

**Scenario**: Customer creates a new ticket with a typical request.

**Steps**:
1. As end-user, create a new ticket in an allowed department
2. Subject: "Resetovanje lozinke" (Password reset)
3. Body: "Pozdrav, ne mogu da se prijavim na sistem. Možete li mi resetovati lozinku?"
4. Priority: Normal

**Expected**:
- [ ] Internal note is created on the ticket within 20 seconds
- [ ] Note title contains `[AI Draft]` and a confidence score
- [ ] Note body contains a Serbian response about password reset
- [ ] Note is type N (internal note), NOT visible to customer
- [ ] Log entry in `ost_ai_reply_log` with status = `generated`
- [ ] Log entry has token counts and latency_ms populated
- [ ] No email sent to customer

---

### TC-02: Customer Reply — Happy Path

**Scenario**: Customer replies to an existing open ticket.

**Steps**:
1. Open an existing ticket (status: Open)
2. As end-user, post a reply: "Hvala, ali i dalje ne radi. Pokušao sam na Firefox-u."

**Expected**:
- [ ] New internal note with AI draft appears
- [ ] Draft references the customer's additional information
- [ ] Log entry with status = `generated`

---

### TC-03: Agent Reply — No Processing

**Scenario**: Agent replies to a ticket.

**Steps**:
1. Open a ticket as staff/agent
2. Post a reply to the customer

**Expected**:
- [ ] NO internal note is created by the plugin
- [ ] No log entry (or log entry with reason "skipped: agent reply")

---

### TC-04: Internal Note — No Processing

**Scenario**: Agent posts an internal note.

**Steps**:
1. Open a ticket as staff/agent
2. Post an internal note

**Expected**:
- [ ] NO additional internal note is created by the plugin
- [ ] Plugin does not trigger (prevents infinite loop)

---

### TC-05: Blocked Tag — Skip

**Scenario**: Ticket has a blocked tag.

**Steps**:
1. Create/find a ticket with tag "billing"
2. As end-user, post a message

**Expected**:
- [ ] NO internal note created
- [ ] Log entry with status = `skipped`, reason contains "tag blocked: billing"

---

### TC-06: Blocked Priority — Skip

**Scenario**: Ticket has Emergency priority.

**Steps**:
1. Create a ticket with Emergency priority
2. As end-user, post a message

**Expected**:
- [ ] NO internal note created
- [ ] Log entry with status = `skipped`, reason = "priority blocked"

---

### TC-07: Wrong Department — Skip

**Scenario**: Ticket is in a department NOT in the allowlist.

**Steps**:
1. Configure allowlist to only include "IT Support" department
2. Create a ticket in "HR" department
3. As end-user, post a message

**Expected**:
- [ ] NO internal note created
- [ ] Log entry with status = `skipped`, reason = "department not allowed"

---

### TC-08: Sensitive Data — Credit Card

**Scenario**: Customer includes a credit card number in their message.

**Steps**:
1. As end-user, post a message containing: "Moj broj kartice je 4111-1111-1111-1111"

**Expected**:
- [ ] AI draft IS generated (not blocked)
- [ ] Draft does NOT repeat the card number
- [ ] Draft instructs customer not to share sensitive data
- [ ] Draft suggests a secure alternative for payment info
- [ ] In the prompt sent to OpenAI: card number is replaced with `[CARD_REDACTED]`
- [ ] Log entry has `confidence` field populated

---

### TC-09: Sensitive Data — JMBG

**Scenario**: Customer includes a personal ID number.

**Steps**:
1. As end-user, post: "Moj JMBG je 0101990123456"

**Expected**:
- [ ] AI draft IS generated
- [ ] JMBG is masked in prompt as `[PERSONAL_ID_REDACTED]`
- [ ] Draft advises against sharing personal identification

---

### TC-10: Sensitive Data — Password

**Scenario**: Customer shares a password.

**Steps**:
1. As end-user, post: "Moja lozinka je Test1234!"

**Expected**:
- [ ] AI draft IS generated
- [ ] Password is masked as `[PASSWORD_REDACTED]`
- [ ] Draft tells customer not to share passwords

---

### TC-11: OpenAI API Failure — Timeout

**Scenario**: OpenAI API is unreachable or times out.

**Steps**:
1. Set an invalid proxy URL or extremely short timeout (1s)
2. As end-user, create a ticket

**Expected**:
- [ ] NO internal note created
- [ ] osTicket request completes normally (no crash, no error page)
- [ ] Log entry with status = `error`, reason = "timeout" or "connection failed"
- [ ] Ticket is created successfully regardless

---

### TC-12: OpenAI API Failure — Invalid Key

**Scenario**: OpenAI API key is invalid.

**Steps**:
1. Set API key to "sk-invalid-key"
2. As end-user, create a ticket

**Expected**:
- [ ] NO internal note created
- [ ] Log entry with status = `error`, reason = "Invalid API key" (401)
- [ ] No retry (auth errors are not retried)

---

### TC-13: Rate Limiting — Per-Ticket

**Scenario**: Two messages on the same ticket within cooldown period.

**Steps**:
1. As end-user, post a message on a ticket (AI draft generated)
2. Wait 10 seconds
3. As end-user, post another message on the SAME ticket

**Expected**:
- [ ] First message: AI draft generated normally
- [ ] Second message: NO AI draft
- [ ] Second log entry: status = `skipped`, reason = "rate limited (per-ticket)"

---

### TC-14: Rate Limiting — Global

**Scenario**: Many tickets processed rapidly (exceeding global limit).

**Steps**:
1. Set global limit to 2 per minute
2. Generate AI drafts for 3 different tickets within 60 seconds

**Expected**:
- [ ] First 2: AI drafts generated
- [ ] Third: NO AI draft
- [ ] Third log: status = `skipped`, reason = "rate limited (global)"

---

### TC-15: Plugin Disabled

**Scenario**: Plugin is disabled in admin settings.

**Steps**:
1. Uncheck "Enable Plugin" in admin settings
2. As end-user, create a ticket

**Expected**:
- [ ] NO processing occurs
- [ ] No log entries created (plugin doesn't activate)

---

### TC-16: Malformed AI Response

**Scenario**: Model returns invalid JSON.

**Steps**:
1. Set temperature to 2.0 (maximum creativity/randomness)
2. Or: use a model known to sometimes produce non-JSON output
3. As end-user, create a ticket

**Expected**:
- [ ] If JSON parsing fails after all fallback attempts:
  - [ ] NO internal note created
  - [ ] Log entry with status = `error`, reason = "invalid response format"
- [ ] osTicket functions normally regardless

---

### TC-17: Empty Message

**Scenario**: Customer sends an empty or whitespace-only message.

**Steps**:
1. If possible, submit a message with only whitespace/HTML tags

**Expected**:
- [ ] NO AI processing
- [ ] Log entry with status = `skipped`, reason = "empty message"

---

### TC-18: Closed Ticket Status

**Scenario**: A reply arrives on a closed ticket.

**Steps**:
1. Close a ticket
2. As end-user, reply to the closed ticket (if allowed by osTicket config)

**Expected**:
- [ ] If "Closed" is not in allowed_statuses: NO AI draft, skip logged
- [ ] If "Closed" is in allowed_statuses: AI draft generated

---

### TC-19: Very Long Thread

**Scenario**: Ticket has 50+ messages.

**Steps**:
1. Create or find a ticket with many messages
2. As end-user, post a new message

**Expected**:
- [ ] Only last N messages sent to AI (per config)
- [ ] AI draft generated successfully
- [ ] No performance issues or timeouts from context size

---

### TC-20: PII in Email — Redaction

**Scenario**: Email addresses in message body.

**Steps**:
1. As end-user, post: "Pišite mi na john.doe@company.com ili na admin@test.org"

**Expected**:
- [ ] In prompt to AI: both emails replaced with `[EMAIL_REDACTED]`
- [ ] AI draft does not mention specific email addresses
- [ ] AI draft may advise customer to use registered email

---

### TC-21: Knowledge Base Retrieval

**Scenario**: Ticket matches a KB article.

**Steps**:
1. Populate Mini KB with FAQ about password resets
2. As end-user, create a ticket about password reset

**Expected**:
- [ ] AI draft includes information from the KB
- [ ] Draft is more specific/accurate than without KB

---

### TC-22: Attachment Policy — Deny

**Scenario**: Customer sends a message with attachment, policy = deny.

**Steps**:
1. Set attachment_policy to "deny"
2. As end-user, post a message with an attachment

**Expected**:
- [ ] NO AI draft generated
- [ ] Log entry with status = `skipped`, reason = "has attachments"

---

### TC-23: Attachment Policy — Ignore  

**Scenario**: Customer sends a message with attachment, policy = ignore.

**Steps**:
1. Set attachment_policy to "ignore" (default)
2. As end-user, post a message with an attachment

**Expected**:
- [ ] AI draft generated based on text content only
- [ ] Attachment content not sent to AI
- [ ] Draft may mention that an attachment was received but not analyzed

## 4. Non-Functional Tests

### NFT-01: Performance

- AI draft generation should complete in < 20 seconds (end-to-end)
- Plugin overhead (excluding OpenAI call) should be < 100ms
- Log table queries should execute in < 10ms

### NFT-02: Security

- API key is never visible in page source HTML
- API key is never logged
- PII is never present in `ost_ai_reply_log`
- HTTPS is used for all OpenAI API calls

### NFT-03: Reliability

- Plugin failure never causes osTicket error page (500)
- Plugin failure never prevents ticket creation or replies
- All exceptions are caught and logged

### NFT-04: Compatibility

- Plugin loads without errors on osTicket 1.18.x
- Plugin admin page renders correctly
- No PHP notices/warnings in error log during normal operation

## 5. Test Environment Checklist

```
[ ] osTicket 1.18.x clean install
[ ] PHP version: ________
[ ] MySQL version: ________
[ ] Plugin installed at: include/plugins/ai-reply-assistant/
[ ] Plugin enabled in Admin
[ ] OpenAI API key configured
[ ] Log level: DEBUG
[ ] Test department created and in allowlist
[ ] Test customer account created
[ ] Test agent account created
[ ] DB table ost_ai_reply_log created
```
