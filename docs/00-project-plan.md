# AI Reply Assistant — Project Plan

## Plugin for osTicket 1.18.x

### Purpose

Automatically generate draft reply suggestions for incoming support tickets
using OpenAI, posted as Internal Notes for agent review.

**MVP scope**: Suggest only — never auto-send to customer.

**Developed by**: [BS Computer](https://bscomputer.com) & [BSC IT Solutions](https://bscsolutions.rs)  
**License**: GPL-2.0-or-later

---

## Documentation Index

| # | Document | Description |
|---|----------|-------------|
| 01 | [Architecture Overview](docs/01-architecture-overview.md) | Components, file structure, execution flow |
| 02 | [Events & Workflow](docs/02-events-and-workflow.md) | Signal handling, trigger conditions, timing |
| 03 | [Gating Logic](docs/03-gating-logic.md) | Allow/deny rules, rate limiting, config |
| 04 | [OpenAI Integration](docs/04-openai-integration.md) | API client, prompt design, response handling |
| 05 | [PII Redaction](docs/05-pii-redaction.md) | Patterns, masking, detection |
| 06 | [Admin Settings](docs/06-admin-settings.md) | UI layout, field definitions, validation |
| 07 | [Database Schema](docs/07-database-schema.md) | Log table, indexes, migration |
| 08 | [Test Plan](docs/08-test-plan.md) | 23 test cases + acceptance criteria |
| 09 | [Internal Note Format](docs/09-internal-note-format.md) | Note structure, HTML, poster identity |
| 10 | [Knowledge Base](docs/10-knowledge-base.md) | Static mini-KB and osTicket KB search |

---

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| OpenAI API | Chat Completions | Stable, supports JSON mode, wide model support |
| HTTP client | PHP curl | No external dependencies (no Composer) |
| Response format | JSON with `response_format` | Reliable structured output |
| Default model | gpt-4.1-mini | Best cost/quality balance |
| PII handling | Redact before sending | Privacy by design |
| Note poster | SYSTEM | No extra staff account setup needed |
| Processing mode | Synchronous | osTicket 1.18.x has no job queue |
| Rate limiting | DB-based via log table | Simple, no Redis needed |
| KB approach | Mini-KB + osTicket KB + Canned Responses | All three sources combined |
| API endpoint | Direct OpenAI only | No proxy layer |
| Response language | Configurable (default: empty = language of the customer's message) | Admin may override in settings |
| Plugin config storage | osTicket PluginConfig | Standard, no custom UI |

---

## Implementation Phases

### Phase 1: Foundation (must-have for MVP)
- [x] Plugin skeleton (`plugin.php`, `config.php`)
- [x] DB migration (log table)
- [x] Admin settings UI
- [x] Event router (signal handlers)

### Phase 2: Core Logic
- [x] Gating logic (all checks)
- [x] PII redactor
- [x] Context builder
- [x] Rate limiter

### Phase 3: AI Integration
- [x] OpenAI client (curl-based)
- [x] Prompt assembly (system + user messages)
- [x] Response parser (with fallback)
- [x] Note writer

### Phase 4: KB & Polish
- [x] Static mini-KB support
- [x] osTicket KB search (optional)
- [x] Canned Responses search
- [x] Log writer
- [x] Error handling review
- [ ] Testing against all 23 test cases

### Phase 5: Manual Trigger & UX
- [x] "AI Draft" button in ticket action bar
- [x] AJAX endpoint with staff authentication
- [x] Gating bypass for manual triggers

---

## Out of Scope (Post-MVP)

- Auto-send replies to customers
- RAG with vector embeddings
- One-click insert into reply editor
- Multi-language AI responses (configurable per department)
- Background/async processing
- Custom AI models (non-OpenAI)
- Attachment content analysis
- Agent feedback loop (thumbs up/down on drafts)
- Dashboard / analytics page for AI usage stats

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| OpenAI outage | No AI drafts | Graceful failure, logging, no user impact |
| Slow API responses | Delayed ticket creation | Timeout + retry, acceptable for MVP |
| PII leakage to OpenAI | Privacy violation | Regex redaction before every API call |
| Model hallucination | Incorrect draft | Confidence score + agent review + KB grounding |
| Plugin breaks osTicket | System down | All code in try/catch, defensive coding |
| High API costs | Budget overrun | Rate limiting, token limits, model selection |
| osTicket upgrade | Plugin incompatible | Pin to 1.18.x API, document assumptions |

---

## Technical Requirements

- **osTicket**: 1.18.x
- **PHP**: 8.1+ (compatible with osTicket requirements)
- **MySQL**: 5.7+ / MariaDB 10.3+
- **PHP Extensions**: curl, json, mbstring, pcre (standard)
- **External**: OpenAI API key (or compatible proxy)
- **No Composer**: Pure PHP, no external package manager

---

## File Structure

```
ai-reply-assistant/
├── plugin.php                  # Plugin registration & metadata
├── class.AiReplyPlugin.php     # Main plugin class (bootstrap, DB)
├── config.php                  # PluginConfig — admin settings
├── ajax.php                    # AJAX endpoint for manual AI Draft
├── .htaccess                   # Apache access rules for ajax.php
├── classes/
│   ├── EventRouter.php         # Signal listeners + UI button injection
│   ├── GatingLogic.php         # Allow/deny evaluation
│   ├── ContextBuilder.php      # Prompt assembly
│   ├── PiiRedactor.php         # PII pattern masking
│   ├── OpenAiClient.php        # HTTP client for OpenAI
│   ├── ResponseParser.php      # JSON response parsing
│   ├── NoteWriter.php          # Internal note creation
│   ├── LogWriter.php           # Database logging
│   ├── RateLimiter.php         # Rate limit checks
│   └── KbRetriever.php         # Knowledge base search
└── migrations/
    └── 001-create-log-table.sql
```
