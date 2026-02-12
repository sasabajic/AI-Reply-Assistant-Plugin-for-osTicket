# 02 – Events & Workflow

## 1. osTicket Signal System (1.18.x)

osTicket uses a signal/slot mechanism (based on `Signal` class). Plugins connect
handler functions to named signals during `bootstrap()`.

### 1.1 Relevant Signals

| Signal | Emitted When | Data Available |
|--------|-------------|----------------|
| `ticket.created` | A new ticket is created | `Ticket` object |
| `threadentry.created` | A new thread entry is posted | `ThreadEntry` object |

### 1.2 Thread Entry Types

| Type Code | Meaning | AI Should Process? |
|-----------|---------|-------------------|
| `M` | **Message** – customer message | **YES** |
| `R` | **Response** – agent reply to customer | NO |
| `N` | **Note** – internal note | NO |

## 2. Event Routing Logic

### 2.1 Signal: `ticket.created`

```
ticket.created → handler receives Ticket object
  1. Get the initial thread entry (first message)
  2. Verify it is type 'M' (should always be for new tickets)
  3. Verify poster is the ticket owner / end-user (not staff)
  4. Pass to gating logic
```

### 2.2 Signal: `threadentry.created`

```
threadentry.created → handler receives ThreadEntry object
  1. Check entry type:
     - If type = 'M' (user message): proceed
     - If type = 'R' (agent reply): SKIP → log "skipped: agent reply"
     - If type = 'N' (internal note): SKIP → log "skipped: internal note"
  2. Check poster:
     - If poster is User (end-user): proceed
     - If poster is Staff: SKIP → log "skipped: staff message"
  3. Get parent Ticket object from ThreadEntry
  4. Pass to gating logic
```

### 2.3 Avoiding Infinite Loops

**Critical**: When the plugin creates an Internal Note (type N), osTicket will emit
`threadentry.created` again. The plugin MUST detect its own notes and skip them.

Strategies:
- **Check entry type**: The plugin writes type 'N' (note), and only processes type 'M'.
  This alone prevents the loop.
- **Belt-and-suspenders**: Additionally check if the poster is the system/plugin user.
- **Marker in note title**: Use a consistent prefix like `[AI Draft]` — if the note
  title starts with this prefix, skip processing.

**Recommended approach**: Type check (`M` only) is sufficient and simplest.

## 3. Detailed Workflow Diagrams

### 3.1 New Ticket Flow

```
Customer creates ticket
        │
        ▼
  ┌─────────────────┐
  │ ticket.created   │
  │ signal emitted   │
  └────────┬────────┘
           │
           ▼
  ┌─────────────────┐
  │ EventRouter:     │
  │ Is first entry   │    NO
  │ type 'M' from    │───────► SKIP (log)
  │ end-user?        │
  └────────┬────────┘
           │ YES
           ▼
  ┌─────────────────┐
  │ GatingLogic:     │
  │ evaluate()       │───────► DENY → SKIP (log reason)
  └────────┬────────┘
           │ ALLOW
           ▼
  ┌─────────────────┐
  │ ContextBuilder:  │
  │ build()          │
  └────────┬────────┘
           │
           ▼
  ┌─────────────────┐
  │ OpenAiClient:    │
  │ send()           │───────► ERROR → LOG + RETURN
  └────────┬────────┘
           │ OK
           ▼
  ┌─────────────────┐
  │ ResponseParser:  │
  │ parse()          │───────► INVALID → LOG + RETURN
  └────────┬────────┘
           │ VALID
           ▼
  ┌─────────────────┐
  │ NoteWriter:      │
  │ write()          │
  └────────┬────────┘
           │
           ▼
  ┌─────────────────┐
  │ LogWriter:       │
  │ log(generated)   │
  └─────────────────┘
```

### 3.2 Customer Reply Flow

```
Customer replies to existing ticket
        │
        ▼
  ┌──────────────────────┐
  │ threadentry.created   │
  │ signal emitted        │
  └────────┬─────────────┘
           │
           ▼
  ┌─────────────────┐
  │ EventRouter:     │
  │ Is entry type    │    NO (type R or N)
  │ 'M' from user?  │───────► SKIP
  └────────┬────────┘
           │ YES
           ▼
       (same as above from GatingLogic onward)
```

### 3.3 Agent Reply Flow (No-Op)

```
Agent replies to ticket
        │
        ▼
  ┌──────────────────────┐
  │ threadentry.created   │
  │ signal emitted        │
  └────────┬─────────────┘
           │
           ▼
  ┌─────────────────┐
  │ EventRouter:     │
  │ Entry type = 'R' │
  │ → SKIP           │
  └─────────────────┘
```

## 4. Timing Considerations

### 4.1 Synchronous vs. Asynchronous

osTicket 1.18.x does not have a built-in job queue. Plugin signal handlers run
**synchronously** within the HTTP request that creates the ticket/reply.

**Implications**:
- The OpenAI API call (up to 20s) will delay the HTTP response to the customer/agent
- This is acceptable for MVP because:
  - Customer submits via web form → response time is less critical
  - Email-originated tickets are processed by cron → no user-facing delay

**Mitigation strategies for MVP**:
- Set aggressive timeout (15-20s)
- Single retry with shorter timeout (10s)
- If timeout: skip gracefully, log, move on

**Post-MVP options**:
- Background processing via osTicket's cron/recurring tasks
- External queue (Redis, DB-based job table)

### 4.2 Cron-originated Tickets

When tickets arrive via email, osTicket's `cron.php` processes them. In this case,
the signal handler runs within the cron context — no user-facing delay. This is the
ideal scenario for the plugin.

### 4.3 Web-originated Tickets

When a customer creates a ticket via the web portal, the signal handler runs in the
HTTP request context. The customer will experience a slight delay. For MVP this is
acceptable (the customer sees a "ticket created" confirmation regardless).

## 5. Edge Cases

| Edge Case | Handling |
|-----------|----------|
| Ticket created by staff on behalf of user | Check poster — if staff, SKIP |
| Ticket created via API | Same logic — check thread entry type and poster |
| Ticket merged | New thread entries in target ticket — normal processing |
| Ticket transferred to different department | Re-evaluation on next user message only |
| Concurrent user messages (rapid-fire) | Rate limiter handles (2-min per-ticket cooldown) |
| Very long thread (100+ messages) | Only last N messages sent to model |
| Empty message body | Skip — nothing to respond to |
| Duplicate message (retry/refresh) | Rate limiter catches |

## 6. Manual Trigger — "AI Draft" Button

### 6.1 Overview

In addition to automatic triggers, agents can manually request an AI draft
for any existing ticket by clicking the **"⚡ AI Draft"** button in the
ticket action bar (positioned between Post Internal Note and Change Status).

### 6.2 Flow

```
Agent views ticket → clicks "AI Draft" button
        │
        ▼
  ┌──────────────────────┐
  │ AJAX POST to         │
  │ ajax.php             │
  │ (staff auth + CSRF)  │
  └────────┬─────────────┘
           │
           ▼
  ┌──────────────────────┐
  │ triggerForTicket():   │
  │ Find last customer   │    NO customer message found
  │ message (type 'M')   │──────────► Error response
  └────────┬─────────────┘
           │ FOUND
           ▼
  ┌──────────────────────┐
  │ processMessage()     │
  │ manual=true          │
  │ (gating BYPASSED)    │
  └────────┬─────────────┘
           │
           ▼
       (same pipeline: ContextBuilder → OpenAI → Parse → Note → Log)
           │
           ▼
  ┌──────────────────────┐
  │ JSON response to     │
  │ browser → page       │
  │ reloads with note    │
  └──────────────────────┘
```

### 6.3 Key Differences from Automatic Mode

| Aspect | Automatic | Manual |
|--------|-----------|--------|
| Trigger | Signal (ticket/threadentry created) | Agent click |
| Gating checks | Full evaluation | **Bypassed** |
| Rate limiting | Enforced | **Bypassed** |
| PII redaction | Active | Active (same) |
| Log reason_code | `ok` | `manual` |
| Authentication | N/A (server-side signal) | Staff session + CSRF |

### 6.4 AJAX Endpoint Security

- **Authentication**: Bootstraps via `staff.inc.php` (requires active staff session)
- **CSRF protection**: Validates `__CSRFToken__` from POST data
- **HTTP method**: POST only (GET returns 405)
- **Access control**: `.htaccess` allows only `ajax.php` (all other files blocked)
