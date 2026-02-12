# 10 – Knowledge Base Retrieval

## 1. Overview

The plugin can include relevant knowledge base (KB) articles in the prompt context
to help the AI model provide more accurate, factual responses.

Three knowledge sources are supported (all can be used simultaneously):

1. **Static Mini-KB** — Admin-configured FAQ text in plugin settings
2. **osTicket KB Search** — Query osTicket's built-in FAQ module
3. **Canned Responses** — osTicket's built-in response templates

## 2. Static Mini-KB (Option A)

### 2.1 How It Works

- Admin pastes FAQ-style content into the `mini_kb_content` textarea in plugin settings
- This content is included verbatim in every AI prompt
- Maximum 4000 characters
- No database queries needed

### 2.2 Recommended Format

```
=== PASSWORD RESET ===
To reset a password:
1. Go to login page
2. Click "Forgot password"
3. Enter email address
4. Check inbox for reset link
If link expired, request a new one.

=== VPN ACCESS ===
VPN access requires:
- Active directory account
- VPN client installed (Cisco AnyConnect)
- Connection profile: vpn.company.com
Contact IT if VPN client is not installed.

=== EMAIL SETUP ===
Email configuration:
- IMAP: imap.company.com:993 (SSL)
- SMTP: smtp.company.com:587 (TLS)
- Use full email address as username
```

### 2.3 Pros & Cons

| Pro | Con |
|-----|-----|
| Zero setup beyond pasting text | Limited to 4000 chars |
| Always included, no DB query | Same content for all tickets |
| Works without osTicket KB module | Manual updates required |
| Fast — no search overhead | No relevance filtering |

## 3. osTicket KB Search (Option B)

### 3.1 How It Works

1. Extract keywords from ticket subject + last user message
2. Search osTicket's FAQ tables for matching articles
3. Return top N articles (default: 3)
4. Include article title + truncated body in prompt

### 3.2 osTicket FAQ Table Structure (1.18.x)

```
ost_faq              — FAQ articles
├── faq_id           — Primary key
├── category_id      — FK to ost_faq_category
├── question         — Article title/question
├── answer           — Article body (HTML)
├── ispublished      — 1 = published
└── created, updated — timestamps

ost_faq_category     — FAQ categories
├── category_id
├── ispublic
└── name
```

### 3.3 Search Strategy (Simple Keyword Match) example with only english/serbian stopwords but it should be optimized for multilingual usage, as users can have osTickets in many languages.

```php
function searchKB(string $subject, string $lastMessage, int $limit = 3): array {
    // 1. Extract keywords (simple approach)
    $text = $subject . ' ' . $lastMessage;
    $text = strip_tags($text);
    
    // Remove common stop words (Serbian + English)
    $stopWords = ['i', 'u', 'na', 'za', 'je', 'da', 'ne', 'se', 'sa',
                  'the', 'is', 'at', 'to', 'and', 'or', 'of', 'a'];
    
    $words = preg_split('/\s+/', mb_strtolower($text));
    $keywords = array_filter($words, function($w) use ($stopWords) {
        return mb_strlen($w) > 2 && !in_array($w, $stopWords);
    });
    $keywords = array_unique(array_slice($keywords, 0, 10));
    
    // 2. Build SQL LIKE query
    $conditions = [];
    foreach ($keywords as $kw) {
        $kw = db_input($kw);
        $conditions[] = "(f.question LIKE '%{$kw}%' OR f.answer LIKE '%{$kw}%')";
    }
    
    if (empty($conditions)) return [];
    
    // 3. Query — score by number of keyword matches
    $sql = "SELECT f.faq_id, f.question, f.answer,
                   (" . implode(' + ', array_map(function($c) {
                       return "IF({$c}, 1, 0)";
                   }, $conditions)) . ") as relevance
            FROM " . FAQ_TABLE . " f
            JOIN " . FAQ_CATEGORY_TABLE . " c ON f.category_id = c.category_id
            WHERE f.ispublished = 1
              AND c.ispublic = 1
              AND (" . implode(' OR ', $conditions) . ")
            ORDER BY relevance DESC
            LIMIT " . (int)$limit;
    
    // 4. Format results
    $results = [];
    $res = db_query($sql);
    while ($row = db_fetch_array($res)) {
        $results[] = [
            'title' => $row['question'],
            'body'  => mb_substr(strip_tags($row['answer']), 0, 500),
        ];
    }
    
    return $results;
}
```

### 3.4 KB Content in Prompt

```
=== KNOWLEDGE BASE ===

Article: "How to reset your password"
Content: To reset your password, navigate to the login page and click
"Forgot password". Enter your registered email address...

Article: "Account lockout policy"  
Content: After 5 failed login attempts, accounts are automatically
locked for 30 minutes...

=== END KNOWLEDGE BASE ===
```

### 3.5 Pros & Cons

| Pro | Con |
|-----|-----|
| Dynamic — adapts to ticket topic | Requires osTicket KB to be populated |
| Scales with KB growth | LIKE queries can be slow on large KBs |
| No manual content management | Simple keyword match (no semantic search) |
| Leverages existing KB investment | May return irrelevant articles |

## 4. Canned Responses (Option C)

### 4.1 What Are Canned Responses?

osTicket's Canned Responses are pre-written reply templates that agents use to
quickly respond to common questions. They often contain variable placeholders
(e.g., `%{ticket.name}`, `%{ticket.number}`) that get replaced at send time.

For AI context, we include the **raw template text** — the model can understand
the template structure and use the information to craft a more accurate draft.

### 4.2 osTicket Canned Response Table Structure (1.18.x)

```
ost_canned_response         — Canned response templates
├── canned_id                — Primary key
├── dept_id                  — Department (0 = all departments)
├── isenabled                — 1 = active
├── title                    — Template title/name
├── response                 — Template body (may contain HTML + variables)
├── lang                     — Language code
├── notes                    — Internal notes about the template
└── created, updated         — Timestamps
```

### 4.3 Search Strategy example with only english stopwords but it should be optimized for multilingual usage, as users can have osTickets in many languages.

```php
function searchCannedResponses(string $subject, string $lastMessage, 
                                int $deptId, int $limit = 3): array {
    // 1. Extract keywords from ticket
    $text = $subject . ' ' . $lastMessage;
    $text = strip_tags($text);
    
    $stopWords = ['i', 'u', 'na', 'za', 'je', 'da', 'ne', 'se', 'sa',
                  'the', 'is', 'at', 'to', 'and', 'or', 'of', 'a'];
    
    $words = preg_split('/\s+/', mb_strtolower($text));
    $keywords = array_filter($words, function($w) use ($stopWords) {
        return mb_strlen($w) > 2 && !in_array($w, $stopWords);
    });
    $keywords = array_unique(array_slice($keywords, 0, 10));
    
    // 2. Build query — match canned responses by title/body keywords
    //    Filter by department (dept_id = 0 means all depts)
    $conditions = [];
    foreach ($keywords as $kw) {
        $kw = db_input($kw);
        $conditions[] = "(c.title LIKE '%{$kw}%' OR c.response LIKE '%{$kw}%')";
    }
    
    if (empty($conditions)) return [];
    
    $sql = "SELECT c.canned_id, c.title, c.response,
                   (" . implode(' + ', array_map(function($c) {
                       return "IF({$c}, 1, 0)";
                   }, $conditions)) . ") as relevance
            FROM " . CANNED_TABLE . " c
            WHERE c.isenabled = 1
              AND (c.dept_id = 0 OR c.dept_id = " . (int)$deptId . ")
              AND (" . implode(' OR ', $conditions) . ")
            ORDER BY relevance DESC
            LIMIT " . (int)$limit;
    
    $results = [];
    $res = db_query($sql);
    while ($row = db_fetch_array($res)) {
        $results[] = [
            'title' => $row['title'],
            'body'  => mb_substr(strip_tags($row['response']), 0, 500),
        ];
    }
    
    return $results;
}
```

### 4.4 Canned Responses in Prompt

```
=== CANNED RESPONSE TEMPLATES ===

Template: "Password Reset Instructions"
Content: Dear customer, to reset your password please follow these steps:
1. Navigate to the login page...

Template: "VPN Setup Guide"
Content: Thank you for contacting us. Here are the VPN setup instructions...

=== END CANNED RESPONSES ===
```

### 4.5 Pros & Cons

| Pro | Con |
|-----|-----|
| Uses existing agent knowledge | Templates may contain osTicket variables |
| Department-scoped (relevant results) | Simple keyword match |
| Already maintained by agents | May overlap with KB articles |
| Includes real response patterns | HTML formatting needs stripping |

## 5. Combined Decision Logic

All three sources are gathered and included in the prompt. Each source is
controlled by its own toggle.

```
kb_sections = []

// Source 1: Static Mini-KB (always if configured)
if (config.mini_kb_content is not empty) {
    kb_sections[] = mini_kb_content
}

// Source 2: osTicket FAQ articles
if (config.use_osticket_kb) {
    articles = searchKB(subject, lastMessage, kb_article_limit)
    if (articles not empty) {
        kb_sections[] = format_articles(articles)
    }
}

// Source 3: Canned Responses
if (config.use_canned_responses) {
    canned = searchCannedResponses(subject, lastMessage, deptId, kb_article_limit)
    if (canned not empty) {
        kb_sections[] = format_canned(canned)
    }
}

if (kb_sections is empty) {
    prompt_kb = "No knowledge base content available."
} else {
    prompt_kb = join(kb_sections)
}
```

**All three sources can be active simultaneously.** They are concatenated in the
prompt under separate headers so the model knows the source of each piece.

## 6. Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| `mini_kb_content` | (empty) | Static FAQ text (max 4000 chars) |
| `use_osticket_kb` | `false` | Enable osTicket FAQ search |
| `use_canned_responses` | `true` | Enable Canned Responses search |
| `kb_article_limit` | `3` | Max items per source to include |

## 7. Post-MVP: Vector Search / RAG

For better relevance:
- Embed KB articles + canned responses using OpenAI embeddings
- Store embeddings in a vector table or external service
- At query time: embed the ticket text, find nearest matches
- Much higher quality matches than keyword LIKE search

This is explicitly out of scope for MVP.
