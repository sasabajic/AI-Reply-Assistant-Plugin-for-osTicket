# AI Reply Assistant — osTicket Plugin

> Automatically generates draft reply suggestions for support tickets using OpenAI.  
> Drafts are posted as Internal Notes for agent review — never sent to customers.

**Target**: osTicket 1.18.x | **Language**: PHP (no Composer) | **AI**: OpenAI Chat Completions API

Developed by **[BS Computer](https://bscomputer.com)** & **[BSC IT Solutions](https://bscsolutions.rs)**

## Status

**Implementation complete (MVP)** — see [Project Plan](docs/00-project-plan.md) for full documentation.

## Features (MVP)

- AI-generated draft replies as internal notes
- Trigger on new tickets and customer replies
- **Manual "AI Draft" button** in ticket action bar for existing tickets
- Department / priority / tag / status filtering
- PII redaction (emails, phones, cards, personal IDs, passwords)
- Configurable OpenAI model, tokens, temperature
- Rate limiting (per-ticket and global)
- Static mini-KB, osTicket KB search, and Canned Responses search
- Comprehensive logging (no PII in logs)
- Admin settings UI within osTicket

## Documentation

See the [docs/](docs/) folder:

- [00 - Project Plan](docs/00-project-plan.md) — Overview & implementation phases
- [01 - Architecture](docs/01-architecture-overview.md) — Components & execution flow
- [02 - Events & Workflow](docs/02-events-and-workflow.md) — Signal handling & timing
- [03 - Gating Logic](docs/03-gating-logic.md) — Allow/deny rules
- [04 - OpenAI Integration](docs/04-openai-integration.md) — API client & prompt design
- [05 - PII Redaction](docs/05-pii-redaction.md) — Privacy patterns & masking
- [06 - Admin Settings](docs/06-admin-settings.md) — Configuration UI
- [07 - Database Schema](docs/07-database-schema.md) — Log table & migration
- [08 - Test Plan](docs/08-test-plan.md) — 23 test scenarios
- [09 - Internal Note Format](docs/09-internal-note-format.md) — Draft note structure
- [10 - Knowledge Base](docs/10-knowledge-base.md) — KB retrieval options

## Requirements

- osTicket 1.18.x
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- PHP extensions: curl, json, mbstring
- OpenAI API key

## Installation

1. Copy `ai-reply-assistant/` to `include/plugins/`
2. Admin Panel → Manage → Plugins → **Add New Plugin**
3. Select "AI Reply Assistant" → **Install**
4. Click on the plugin → **Add New Instance**
5. Configure OpenAI API key, model, department filters, and other settings
6. Save and enable the instance

### Apache / Nginx Note

The plugin includes an `.htaccess` file to allow AJAX access for the manual
"AI Draft" button. For **Nginx**, add this to your server block:

```nginx
location ~ /include/plugins/ai-reply-assistant/ajax\.php$ {
    fastcgi_pass unix:/run/php/php-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## Usage

### Automatic Mode

The plugin automatically generates AI draft replies when:
- A new ticket is created by a customer
- A customer adds a new reply to an existing ticket

Drafts appear as **Internal Notes** (visible to staff only).

### Manual Mode

For existing tickets without an AI draft, agents can click the
**"⚡ AI Draft"** button in the ticket action bar (between Post Internal Note
and Change Status). This bypasses gating rules and generates a draft on demand.

## Plugin Structure

```
ai-reply-assistant/
├── plugin.php                  — Plugin metadata
├── class.AiReplyPlugin.php     — Main plugin class (bootstrap, DB setup)
├── config.php                  — Admin settings form (AiReplyPluginConfig)
├── ajax.php                    — AJAX endpoint for manual AI Draft button
├── .htaccess                   — Apache access rules for ajax.php
├── migrations/
│   └── 001-create-log-table.sql
└── classes/
    ├── EventRouter.php         — Signal handlers & UI button injection
    ├── GatingLogic.php         — 10-check allow/deny evaluation
    ├── PiiRedactor.php         — PII masking (email, phone, card, JMBG, etc.)
    ├── RateLimiter.php         — Per-ticket & global rate limiting
    ├── ContextBuilder.php      — Prompt assembly (metadata, history, KB)
    ├── OpenAiClient.php        — OpenAI Chat Completions HTTP client
    ├── ResponseParser.php      — JSON response parsing with 3-level fallback
    ├── NoteWriter.php          — Internal Note creation (formatted HTML)
    ├── LogWriter.php           — Audit logging (SHA-256 hashes, no PII)
    └── KbRetriever.php         — Mini-KB + FAQ + Canned Responses search
```

## License

This project is licensed under the **GNU General Public License v2.0 or later** (GPL-2.0-or-later).

See the [LICENSE](LICENSE) file for the full license text.

**Copyright © 2026 [BS Computer](https://bscomputer.com) / [BSC IT Solutions](https://bscsolutions.rs)**
