# 06 – Admin Settings UI

## 1. Overview

The plugin settings are managed through osTicket's standard plugin configuration
system. The admin accesses settings via:

**Admin Panel → Manage → Plugins → AI Reply Assistant → Settings**

All settings are stored in osTicket's `ost_config` table (plugin namespace).

## 2. Settings Groups & Fields

### Group 1: General

| Field | Type | Key | Default | Description |
|-------|------|-----|---------|-------------|
| Enable Plugin | checkbox | `enabled` | `false` | Master on/off switch |
| Log Level | dropdown | `log_level` | `error` | OFF / ERROR / DEBUG |

### Group 2: OpenAI Connection

| Field | Type | Key | Default | Description |
|-------|------|-----|---------|-------------|
| API Key | password | `openai_api_key` | (empty) | OpenAI API key. Displayed as `****` + last 4 chars |
| Model | dropdown+text | `openai_model` | `gpt-4.1-mini` | Model selection (see §2.1) |
| Max Tokens | number | `openai_max_tokens` | `1024` | Max response tokens (100–4096) |
| Temperature | number | `openai_temperature` | `0.3` | Temperature (0.0–2.0, step 0.1) |
| Request Timeout (s) | number | `openai_timeout` | `20` | HTTP timeout in seconds (5–60) |
| Retry Count | number | `openai_retry_count` | `1` | Number of retries (0–3) |

#### 2.1 Model Dropdown Options

```
gpt-4.1-mini       — Fast & affordable (recommended)
gpt-4.1-nano       — Fastest, cheapest
gpt-4.1            — Highest quality
gpt-4o             — Good balance (legacy)
gpt-4o-mini        — Fast (legacy)
gpt-3.5-turbo      — Budget (legacy)
custom              → reveals text input for arbitrary model name
```

### Group 3: Filtering Rules

| Field | Type | Key | Default | Description |
|-------|------|-----|---------|-------------|
| Allowed Departments | multi-select | `allowed_departments` | `*` (all) | Departments where AI is active |
| Blocked Priorities | multi-select | `blocked_priorities` | `Emergency` | Priorities that skip AI |
| Blocked Tags | textarea | `blocked_tags` | `incident,security,legal,billing` | Comma-separated tag names |
| Allowed Statuses | multi-select | `allowed_statuses` | `Open` | Ticket statuses that allow AI |
| Attachment Policy | dropdown | `attachment_policy` | `ignore` | ignore / deny |

**Note**: Multi-select fields for departments, priorities, and statuses should
dynamically populate from osTicket's current configuration.

### Group 4: Context & Prompt

| Field | Type | Key | Default | Description |
|-------|------|-----|---------|-------------|
| Message History Count | number | `context_message_count` | `6` | Last N messages to include (1–20) |
| Max Message Length | number | `max_message_length` | `2000` | Truncate each message to N chars |
| Response Language | text | `response_language` | `Serbian (ekavica, Latin script)` | Language instruction for AI model |
| Mini Knowledge Base | textarea | `mini_kb_content` | (empty) | Static FAQ content (max 4000 chars) |
| Use osTicket KB | checkbox | `use_osticket_kb` | `false` | Search osTicket's built-in KB |
| Use Canned Responses | checkbox | `use_canned_responses` | `true` | Search osTicket's Canned Responses |
| KB Article Limit | number | `kb_article_limit` | `3` | Max items per source to include (1–5) |

### Group 5: Privacy & Security

| Field | Type | Key | Default | Description |
|-------|------|-----|---------|-------------|
| PII Redaction | checkbox | `pii_redaction_enabled` | `true` | Redact PII before sending to AI |
| Redact IP Addresses | checkbox | `redact_ip_addresses` | `false` | Also redact IP addresses |
| Custom PII Patterns | textarea | `custom_pii_patterns` | (empty) | Additional regex patterns (advanced) |

### Group 6: Rate Limiting

| Field | Type | Key | Default | Description |
|-------|------|-----|---------|-------------|
| Per-Ticket Cooldown (s) | number | `rate_limit_per_ticket_seconds` | `120` | Min seconds between AI calls per ticket |
| Global Limit (per min) | number | `rate_limit_global_per_minute` | `60` | Max AI calls per minute globally |

## 3. UI Implementation Notes

### 3.1 osTicket PluginConfig

osTicket plugins define settings by extending `PluginConfig` and implementing
`getOptions()` that returns an array of form field definitions.

```php
class AiReplyConfig extends PluginConfig {
    function getOptions() {
        return [
            'enabled' => new BooleanField([
                'label' => 'Enable Plugin',
                'default' => false,
                'hint' => 'Master switch for AI Reply Assistant',
            ]),
            // ... more fields
        ];
    }
}
```

### 3.2 Field Types Available in osTicket 1.18.x

| osTicket Type | Maps To |
|---------------|---------|
| `TextboxField` | text input |
| `TextareaField` | textarea |
| `BooleanField` | checkbox |
| `ChoiceField` | dropdown / multi-select |
| `PasswordField` | password input (masked) |
| `SectionBreakField` | visual separator / group header |

### 3.3 Password Field for API Key

osTicket's `PluginConfig` does not have a native "password" field that masks display.
Solutions:
- **Option A**: Use `TextboxField` with `'configuration' => ['type' => 'password']`
- **Option B**: Custom render — store full key in DB, display `****XXXX` in form
- **Option C**: Override `renderField()` or `getConfigurationForm()` for the key field

**Recommendation**: Use Option B — implement a custom save/display cycle:
- On save: store real key in DB config
- On display: show `••••` + last 4 characters
- If field is re-submitted empty: keep existing key (don't overwrite with blank)

### 3.4 Dynamic Dropdowns (Departments, Priorities)

These must query osTicket's DB at render time:

```php
// Departments
'allowed_departments' => new ChoiceField([
    'label' => 'Allowed Departments',
    'choices' => self::getDepartmentChoices(), // queries ost_department
    'configuration' => ['multiselect' => true],
    'default' => '*',
]),
```

Helper functions:
```php
static function getDepartmentChoices() {
    $depts = Dept::getDepartments();
    return ['*' => '-- All Departments --'] + $depts;
}

static function getPriorityChoices() {
    return Priority::getPriorities();
}

static function getStatusChoices() {
    // TicketStatus or TicketState
    return ['open' => 'Open', 'closed' => 'Closed', ...];
}
```

## 4. Validation Rules

| Field | Validation |
|-------|-----------|
| `openai_api_key` | Required. Must start with `sk-` |
| `openai_proxy_url` | If set, must be valid URL (https://) |
| `openai_max_tokens` | Integer, 100–4096 |
| `openai_temperature` | Float, 0.0–2.0 |
| `openai_timeout` | Integer, 5–60 |
| `openai_retry_count` | Integer, 0–3 |
| `context_message_count` | Integer, 1–20 |
| `max_message_length` | Integer, 100–10000 |
| `rate_limit_per_ticket_seconds` | Integer, 0–3600 (0 = no limit) |
| `rate_limit_global_per_minute` | Integer, 0–1000 (0 = no limit) |
| `mini_kb_content` | Max 4000 characters |

## 5. Settings Page Layout (Visual)

```
┌─────────────────────────────────────────────────────────┐
│  AI Reply Assistant — Settings                          │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ── General ──────────────────────────────────────────  │
│  [✓] Enable Plugin                                      │
│  Log Level: [ERROR ▼]                                   │
│                                                         │
│  ── OpenAI Connection ────────────────────────────────  │
│  API Key: [••••••••abcd          ]                      │
│  Model: [gpt-4.1-mini ▼        ]                       │
│  Max Tokens: [1024 ]                                    │
│  Temperature: [0.3  ]                                   │
│  Timeout (seconds): [20  ]                              │
│  Retries: [1  ]                                         │
│                                                         │
│  ── Filtering Rules ──────────────────────────────────  │
│  Allowed Departments: [✓ All ▼ multi]                   │
│  Blocked Priorities: [✓ Emergency ▼ multi]              │
│  Blocked Tags: [incident,security,legal,billing      ]  │
│  Allowed Statuses: [✓ Open ▼ multi]                     │
│  Attachment Policy: [Ignore ▼]                          │
│                                                         │
│  ── Context & Prompt ─────────────────────────────────  │
│  Message History Count: [6   ]                          │
│  Max Message Length: [2000  ]                            │
│  Response Language: [Serbian (ekavica, Latin script)  ]  │
│  Mini Knowledge Base:                                   │
│  [                                                   ]  │
│  [  (paste FAQ content here, max 4000 chars)         ]  │
│  [                                                   ]  │
│  [✓] Use osTicket Knowledge Base                        │
│  [✓] Use Canned Responses                               │
│  KB Article Limit: [3  ]                                │
│                                                         │
│  ── Privacy & Security ───────────────────────────────  │
│  [✓] PII Redaction                                      │
│  [ ] Redact IP Addresses                                │
│  Custom PII Patterns: [                              ]  │
│                                                         │
│  ── Rate Limiting ────────────────────────────────────  │
│  Per-Ticket Cooldown: [120 ] seconds                    │
│  Global Limit: [60  ] per minute                        │
│                                                         │
│                              [Save Changes]             │
└─────────────────────────────────────────────────────────┘
```

## 6. Configuration Access in Code

```php
// In any plugin class:
$config = $this->getConfig();  // or pass config instance

$apiKey = $config->get('openai_api_key');
$model = $config->get('openai_model');
$isEnabled = (bool) $config->get('enabled');
$allowedDepts = $config->get('allowed_departments'); // array
```

## 7. Config Migration / Upgrade Path

If settings schema changes between plugin versions:
- New fields get their defaults automatically
- Removed fields are ignored (left in DB but not displayed)
- No manual migration needed for simple additions

For breaking changes: document in CHANGELOG and README.
