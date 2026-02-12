# 04 – OpenAI Integration

## 1. API Choice

**Chat Completions API** (`POST /v1/chat/completions`)

Rationale:
- Stable, widely supported
- Structured input (system + user messages)
- Consistent interface across GPT-3.5, GPT-4, GPT-4o, GPT-4.1-mini, etc.
- JSON mode supported natively

We do NOT use the newer "Responses" API — Chat Completions is the established standard.

## 2. Configuration Parameters

| Parameter | Admin Setting | Default | Notes |
|-----------|--------------|---------|-------|
| API Key | `openai_api_key` | (empty) | Required. Masked in UI |
| Proxy URL | `openai_proxy_url` | (empty) | Optional. If set, requests go here instead of `api.openai.com` |
| Proxy Token | `openai_proxy_token` | (empty) | Optional. Bearer token for proxy |
| Model | `openai_model` | `gpt-4.1-mini` | Dropdown + custom text |
| Max Tokens | `openai_max_tokens` | `1024` | Max response tokens |
| Temperature | `openai_temperature` | `0.3` | Low = more deterministic |
| Timeout (s) | `openai_timeout` | `20` | HTTP request timeout |
| Retry Count | `openai_retry_count` | `1` | Number of retries on failure |

### 2.1 Model Selection

Admin UI provides a dropdown with common models + a "custom" text field:

```
Dropdown options:
- gpt-4.1-mini (recommended — fast, cheap, good quality)
- gpt-4.1-nano (fastest, cheapest)
- gpt-4.1 (best quality, slower)
- gpt-4o (legacy, good balance)
- gpt-4o-mini (legacy, fast)
- gpt-3.5-turbo (legacy, cheapest)
- (custom) → shows text input
```

### 2.2 Endpoint Resolution

```php
$endpoint = 'https://api.openai.com/v1/chat/completions';
$authHeader = 'Bearer ' . $config->get('openai_api_key');
```

## 3. Request Payload Structure

```json
{
  "model": "gpt-4.1-mini",
  "max_tokens": 1024,
  "temperature": 0.3,
  "response_format": { "type": "json_object" },
  "messages": [
    {
      "role": "system",
      "content": "<system prompt — see §4>"
    },
    {
      "role": "user",
      "content": "<context payload — see §5>"
    }
  ]
}
```

**Key decisions:**
- `response_format: json_object` — forces JSON output (supported on all GPT-4+ models)
- Single `user` message containing all context (not a multi-turn conversation)
- System prompt contains instructions; user message contains ticket data

## 4. System Prompt

```
You are an L1 support agent assistant for a helpdesk system.

RULES:
1. Respond ONLY in Serbian (ekavica, Latin script / latinica).
2. Use ONLY the information provided in the ticket context and knowledge base below.
3. Do NOT invent information, URLs, phone numbers, or procedures not present in the context.
4. NEVER ask the customer for passwords, credit card numbers, JMBG, or other sensitive personal data.
5. If you do not have enough information to provide a helpful answer, set "need_more_info" to true and list up to 3 specific, professional questions in the "questions" array.
6. Keep your tone professional, concise, and clear.
7. If the customer has shared sensitive data (flagged below), remind them not to share such information and suggest a secure alternative.

OUTPUT FORMAT:
You MUST respond with a valid JSON object with exactly this structure:
{
  "reply_subject": "Short subject line for the response",
  "reply_body": "Full response text in Serbian (ekavica, latinica). Use line breaks for readability.",
  "need_more_info": false,
  "questions": [],
  "suggested_tags": ["tag1", "tag2"],
  "confidence": 0.85
}

FIELD RULES:
- reply_subject: A brief, relevant subject line (max 100 chars)
- reply_body: The draft response. Professional, helpful, concise. May include numbered steps.
- need_more_info: true if the ticket lacks details for a proper answer
- questions: Array of 0-3 clarifying questions (only when need_more_info=true)
- suggested_tags: 0-3 tags that categorize this ticket (e.g., "password-reset", "network", "access")
- confidence: Float 0.0-1.0 — your confidence that reply_body fully addresses the issue
```

### 4.1 System Prompt — Language Configurability

The response language is a **configurable admin setting**:

```
Setting: response_language
Default: "Serbian (ekavica, Latin script)"
Type: free text — admin can type any language instruction
```

The system prompt template uses this value:
```
1. Respond ONLY in {response_language}.
```

This allows multi-language deployments (English, German, etc.) with zero code changes.

## 5. User Message (Context Payload)

The user message sent to the model is a structured text block:

```
=== TICKET CONTEXT ===

Ticket ID: {ticket_id}
Ticket Number: {ticket_number}
Subject: {subject}
Department: {department_name}
Priority: {priority_name}
Status: {status_name}
Customer: {user_name_or_alias}

=== SENSITIVE DATA FLAG ===
{sensitive_data_warning or "None detected."}

=== CONVERSATION HISTORY (last {N} messages) ===

[Customer] (2025-02-10 14:30):
{redacted message text}

[Agent] (2025-02-10 15:00):
{redacted message text}

[Customer] (2025-02-10 15:45):
{redacted message text}

=== KNOWLEDGE BASE ===
{kb_articles_text or "No relevant articles found."}

=== END CONTEXT ===
```

### 5.1 Context Size Management

- Default: Last **6** messages (configurable: `context_message_count`)
- Messages are oldest-first (chronological)
- Each message is truncated to **2000 characters** (configurable)
- Total context payload target: **< 8000 characters** before token counting
- If exceeding limit: reduce message count from oldest

### 5.2 Knowledge Base Section

**MVP approach (two options, both supported):**

**Option A: Static Mini-KB (Admin Config)**
- Admin has a textarea field `mini_kb_content` in plugin settings
- Max 4000 characters of FAQ-style content
- Included verbatim in every prompt
- Simple, no DB queries

**Option B: osTicket KB Search**
- Query osTicket's built-in FAQ/KB tables
- Search by keyword match: extract keywords from ticket subject + last message
- Return top 3 matching articles (title + truncated body)
- Requires osTicket KB module to be active

**Implementation**:
- If `mini_kb_content` is non-empty → use it (Option A)
- Else if osTicket KB is available → use keyword search (Option B)
- Else → include "No knowledge base configured." in context

## 6. Response Handling

### 6.1 Expected Response

```json
{
  "reply_subject": "Resetovanje lozinke",
  "reply_body": "Poštovani,\n\nZa resetovanje lozinke...",
  "need_more_info": false,
  "questions": [],
  "suggested_tags": ["password-reset", "account"],
  "confidence": 0.9
}
```

### 6.2 Validation Rules

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `reply_subject` | string | yes | Non-empty, max 200 chars |
| `reply_body` | string | yes | Non-empty, max 10000 chars |
| `need_more_info` | boolean | yes | Must be boolean |
| `questions` | array of strings | yes | 0-3 items, each max 500 chars |
| `suggested_tags` | array of strings | yes | 0-5 items, each max 50 chars |
| `confidence` | float | yes | 0.0 – 1.0 |

### 6.3 Fallback Parsing Strategy

```
1. Try json_decode() on raw response
   → Success? Validate schema → Use it

2. Try extracting JSON from markdown code block:
   ```json ... ``` or ``` ... ```
   → Found? json_decode() → Validate → Use it

3. Try extracting JSON between first { and last }
   → Found? json_decode() → Validate → Use it

4. All attempts failed:
   → Log error with response hash
   → Status = 'error'
   → Do NOT create internal note
   → Return gracefully
```

## 7. Error Handling

### 7.1 HTTP Errors

| HTTP Status | Action |
|-------------|--------|
| 200 | Parse response |
| 401 | Log "Invalid API key", no retry |
| 403 | Log "Access denied", no retry |
| 429 | Log "Rate limited by OpenAI", retry after delay |
| 500-503 | Log "OpenAI server error", retry |
| Timeout | Log "Request timeout", retry |
| Connection error | Log "Connection failed", retry |

### 7.2 Retry Logic

```
attempt = 0
while attempt <= max_retries:
    response = http_request(endpoint, payload, timeout)
    if response.success:
        return response
    if response.status in [401, 403]:
        break  // no retry for auth errors
    attempt++
    if attempt <= max_retries:
        sleep(2)  // brief delay before retry
return error
```

### 7.3 Token Usage Tracking

The response includes `usage` object:
```json
{
  "usage": {
    "prompt_tokens": 450,
    "completion_tokens": 280,
    "total_tokens": 730
  }
}
```

Extract and pass to LogWriter for the log table.

## 8. HTTP Client Implementation

Using PHP's `curl` (available in all osTicket environments):

```php
// Pseudocode
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: ' . $authHeader,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);
```

**No external dependencies** — pure PHP + curl. No Composer, no Guzzle.
This ensures maximum compatibility with osTicket environments.

## 9. Security Considerations

- API key stored in osTicket's plugin config (DB), encrypted at rest by osTicket's config system
- API key displayed as `****` in admin UI (last 4 chars only)
- No API key in logs, tracebacks, or error messages
- All HTTPS connections verify SSL certificates
- PII is redacted BEFORE the payload is constructed (never sent to OpenAI)
