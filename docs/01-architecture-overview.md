# 01 – Architecture Overview

## 1. Plugin Identity

| Field | Value |
|-------|-------|
| Plugin Name | AI Reply Assistant |
| Internal ID | `ai-reply-assistant` |
| Target osTicket | **1.18.x** |
| PHP | Whatever ships with the osTicket host (8.1+ recommended) |
| License | GPL-2.0-or-later |
| Author | Sasa Bajic - BS Computer / BSC IT Solutions |
| Website | [bscomputer.com](https://bscomputer.com) / [bscsolutions.rs](https://bscsolutions.rs) |

## 2. High-Level Component Diagram

```
┌──────────────────────────────────────────────────────┐
│                    osTicket Core                     │
│                                                      │
│  ┌──────────┐   Signal    ┌──────────────────────┐   │
│  │ Ticket / │ ─────────►  │  AI Reply Assistant  │   │
│  │ Thread   │             │      Plugin          │   │
│  └──────────┘             │                      │   │
│                           │  ┌────────────────┐  │   │
│                           │  │  Event Router  │  │   │
│                           │  └───────┬────────┘  │   │
│                           │          │           │   │
│                           │  ┌───────▼────────┐  │   │
│                           │  │  Gating Logic  │  │   │
│                           │  └───────┬────────┘  │   │
│                           │          │           │   │
│                           │  ┌───────▼────────┐  │   │
│                           │  │ Context Builder │  │   │
│                           │  │ + PII Redactor  │  │   │
│                           │  └───────┬────────┘  │   │
│                           │          │           │   │
│                           │  ┌───────▼────────┐  │   │
│                           │  │  OpenAI Client │──┼──► OpenAI API
│                           │  └───────┬────────┘  │   │
│                           │          │           │   │
│                           │  ┌───────▼────────┐  │   │
│                           │  │ Response Parser │  │   │
│                           │  └───────┬────────┘  │   │
│                           │          │           │   │
│                           │  ┌───────▼────────┐  │   │
│                           │  │  Note Writer   │  │   │
│                           │  └───────┬────────┘  │   │
│                           │          │           │   │
│                           │  ┌───────▼────────┐  │   │
│                           │  │   Log Writer   │  │   │
│                           │  └────────────────┘  │   │
│                           └──────────────────────┘   │
└──────────────────────────────────────────────────────┘
```

## 3. Component Responsibilities

### 3.1 Event Router
- Listens to osTicket signals: `ticket.created`, `threadentry.created`
- Determines if event is user-originated (new ticket or customer reply)
- Ignores agent replies, internal notes, system events

### 3.2 Gating Logic
- Checks department allowlist
- Checks priority blocklist
- Checks tag blocklist
- Checks ticket status allowlist
- Checks for sensitive data patterns (credit cards, personal IDs)
- Checks attachment policy
- Checks rate limits (per-ticket, global)
- Returns ALLOW / DENY with reason

### 3.3 Context Builder + PII Redactor
- Collects ticket metadata (id, number, subject, department, priority, status)
- Collects last N thread messages with roles (customer/agent)
- Optionally retrieves KB articles (keyword match)
- Optionally retrieves matching Canned Responses (keyword match)
- Applies PII redaction (emails, phones, card numbers, personal IDs)
- Builds the final prompt payload

### 3.4 OpenAI Client
- Sends Chat Completions request to OpenAI API
- Handles timeout (configurable, default 20s)
- Handles retry (1 retry on failure)
- Tracks token usage
- Returns raw response or error

### 3.5 Response Parser
- Validates JSON response from model
- Extracts: reply_subject, reply_body, need_more_info, questions, suggested_tags, confidence
- Fallback: attempts lenient JSON parsing (extract from markdown code blocks, etc.)
- On total failure: returns error status, does not create note

### 3.6 Note Writer
- Creates an Internal Note on the ticket thread
- Title format: `[AI Draft] (confidence: X.XX)`
- Body: formatted reply + questions section + suggested tags
- Does NOT change ticket status
- Does NOT send email to customer

### 3.7 Log Writer
- Writes a row to `ost_ai_reply_log`
- Records: status, reason, model, tokens, latency, confidence
- Stores SHA-256 hashes of prompt and response (never raw content)
- Never stores PII

## 4. File Structure (Planned)

```
ai-reply-assistant/
├── plugin.php                  # Plugin registration & bootstrap
├── config.php                  # Admin settings form definition
├── classes/
│   ├── EventRouter.php         # Signal listeners
│   ├── GatingLogic.php         # Allow/deny logic
│   ├── ContextBuilder.php      # Prompt assembly + PII redaction
│   ├── PiiRedactor.php         # PII pattern matching & masking
│   ├── OpenAiClient.php        # HTTP client for OpenAI API
│   ├── ResponseParser.php      # JSON parsing & validation
│   ├── NoteWriter.php          # Internal note creation
│   ├── LogWriter.php           # DB logging
│   ├── RateLimiter.php         # Per-ticket & global rate limiting
│   └── KbRetriever.php         # Knowledge base search
├── migrations/
│   └── 001-create-log-table.sql
├── docs/                       # (symlink or separate — planning docs)
└── README.md                   # Install, configure, test
```

## 5. Execution Flow (Happy Path)

```
1. User posts a reply (or creates a new ticket)
2. osTicket emits signal
3. EventRouter receives signal
4. EventRouter determines: is this a user message? → YES
5. GatingLogic.evaluate(ticket, message)
   → Department in allowlist? YES
   → Priority not in blocklist? YES
   → Tags clean? YES
   → Status open? YES
   → No sensitive data? YES
   → Attachments? (skip if configured)
   → Rate limit OK? YES
   → Result: ALLOW
6. ContextBuilder.build(ticket, N_messages)
   → Collect metadata
   → Collect thread messages
   → Query KB (if enabled)
   → Apply PII redaction
   → Build prompt payload
7. OpenAiClient.send(payload)
   → HTTP POST to Chat Completions
   → Wait for response (timeout 20s)
   → On failure: retry once
8. ResponseParser.parse(raw_response)
   → Extract JSON
   → Validate schema
   → Return structured result
9. NoteWriter.write(ticket, parsed_result)
   → Create internal note with formatted draft
10. LogWriter.log(ticket, message, result, metrics)
    → Insert row into ost_ai_reply_log
```

## 6. Error Handling Philosophy

- **Never crash osTicket request**: All exceptions are caught at the top level
- **Fail silently**: If AI fails, the ticket is unaffected — agents work as usual
- **Log everything**: Errors are logged with context (without PII)
- **Defensive coding**: Every external input is validated; every API call has timeout

## 7. osTicket 1.18.x Plugin System Notes

- Plugins extend `Plugin` class
- Registration via `plugin.php` returning plugin metadata array
- Bootstrap: `bootstrap()` method connects signal handlers
- Config: extend `PluginConfig` class with `getOptions()` for admin form
- Signals of interest:
  - `ticket.created` — new ticket
  - `threadentry.created` — new thread entry (reply/note)
- Thread entries have a `type` field: **M** (message), **R** (response/reply), **N** (note)
- User messages = type **M**
- Agent replies = type **R**
- Internal notes = type **N**
- Plugin can create notes via `ThreadEntry::create()` or Ticket's `postNote()` method
