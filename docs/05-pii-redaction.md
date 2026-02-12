# 05 – PII Redaction

## 1. Purpose

Before sending any ticket content to OpenAI, all personally identifiable information
(PII) and sensitive data must be masked. This protects customer privacy and complies
with data handling best practices.

## 2. Redaction Strategy

- **Regex-based pattern matching** on plain text (after HTML stripping)
- **Replace matched values** with masked placeholders
- **Configurable**: Admin can enable/disable redaction (default: ON)
- **Runs on**: message bodies, customer name, subject line

## 3. Patterns & Masks

### 3.1 Email Addresses

```
Pattern:  [a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}
Mask:     [EMAIL_REDACTED]
Example:  john.doe@company.com → [EMAIL_REDACTED]
```

Alternative (partial mask): `j***@c***.com` — shows structure but hides value.

**Recommendation**: Use `[EMAIL_REDACTED]` for simplicity and safety.

### 3.2 Phone Numbers

```
Patterns (covering common formats):
  - International:    \+?\d{1,3}[-.\s]?\(?\d{1,4}\)?[-.\s]?\d{1,4}[-.\s]?\d{1,9}
  - Serbian mobile:   \b06[0-9][-/\s]?\d{3}[-/\s]?\d{3,4}\b
  - Serbian landline:  \b0[1-3][0-9][-/\s]?\d{5,7}\b
  - General (7+ digits with separators): \b\d[\d\s\-/.]{6,15}\d\b

Mask:     [PHONE_REDACTED]
Example:  +381 63 123 4567 → [PHONE_REDACTED]
          065/123-4567 → [PHONE_REDACTED]
```

**Note**: Phone regex can be overly aggressive (matching ticket numbers, etc.).
Mitigation: Apply phone redaction AFTER other patterns to avoid false positives.
Consider whitelisting ticket number format.

### 3.3 Credit Card Numbers

```
Patterns:
  - Visa:       \b4\d{3}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b
  - MasterCard: \b5[1-5]\d{2}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b
  - Amex:       \b3[47]\d{2}[-\s]?\d{6}[-\s]?\d{5}\b
  - Generic:    \b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b
  
Validation: Luhn check on extracted digits (reduces false positives)

Mask:     [CARD_REDACTED]
Example:  4111-2222-3333-4444 → [CARD_REDACTED]
```

### 3.4 JMBG (Serbian Personal ID Number)

```
Pattern:  \b\d{13}\b
Context:  Must be preceded/followed by non-digit, and optionally
          preceded by keywords: JMBG, jmbg, matični broj, lični broj

More precise regex (with date validation):
  \b[0-3]\d[01]\d[09]\d{2}[0-9]{6}\b
  (DDMMYYY + 6 digits)

Mask:     [PERSONAL_ID_REDACTED]
Example:  0101990123456 → [PERSONAL_ID_REDACTED]
```

**Note**: 13-digit numbers can match other things. Keyword proximity helps:
if within 50 chars of "JMBG", "matični", "lični" → more confident.

### 3.5 Passwords / Credentials

```
Patterns (keyword-based, not value-based):
  - /(?:password|lozinka|pass|pwd|šifra|sifra)\s*[:=]\s*\S+/i
  - Detects: "password: MySecret123" → masks the value part
  
Mask:     password: [PASSWORD_REDACTED]
Example:  "moja lozinka je Test1234!" → "moja lozinka je [PASSWORD_REDACTED]"
```

### 3.6 IP Addresses (Optional)

```
Pattern:  \b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b
Mask:     [IP_REDACTED]
```

**Recommendation**: OFF by default — IP addresses are often needed for troubleshooting.
Admin toggle: `redact_ip_addresses` (default: false).

### 3.7 URLs with Credentials (Optional)

```
Pattern:  https?://[^:]+:[^@]+@\S+
Mask:     [URL_WITH_CREDENTIALS_REDACTED]
Example:  https://admin:secret@server.com → [URL_WITH_CREDENTIALS_REDACTED]
```

## 4. Redaction Order

The order matters to avoid patterns interfering with each other:

```
1. URLs with credentials (before general URL processing)
2. Email addresses
3. Credit card numbers (with Luhn validation)
4. JMBG / personal ID numbers
5. Password/credential patterns
6. Phone numbers (last — most prone to false positives)
7. IP addresses (if enabled)
```

## 5. Redaction Scope

| Data Field | Redacted? | Notes |
|------------|-----------|-------|
| Message body (customer) | **YES** | All patterns applied |
| Message body (agent) | **YES** | All patterns applied |
| Ticket subject | **YES** | All patterns applied |
| Customer name | **PARTIAL** | Use first name or alias: "Customer" |
| Customer email | **YES** | Replaced with [EMAIL_REDACTED] |
| Department name | NO | Not PII |
| Priority | NO | Not PII |
| Tags | NO | Not PII |
| Ticket number | NO | Operational data |
| KB article content | **YES** | KB may contain examples with PII |

## 6. Implementation Design

```php
class PiiRedactor {
    
    private array $patterns;
    private bool $enabled;
    private bool $redactIp;
    
    public function __construct(array $config) {
        $this->enabled = $config['pii_redaction_enabled'] ?? true;
        $this->redactIp = $config['redact_ip_addresses'] ?? false;
        $this->patterns = $this->buildPatterns();
    }
    
    /**
     * Redact PII from text. Returns [redacted_text, detected_types[]]
     */
    public function redact(string $text): array {
        if (!$this->enabled) {
            return [$text, []];
        }
        
        $detectedTypes = [];
        
        foreach ($this->patterns as $name => $spec) {
            if (!$spec['enabled']) continue;
            
            $count = 0;
            $text = preg_replace(
                $spec['pattern'],
                $spec['replacement'],
                $text,
                -1,
                $count
            );
            
            if ($count > 0) {
                $detectedTypes[] = $name;
            }
        }
        
        return [$text, $detectedTypes];
    }
    
    /**
     * Check if text contains sensitive data (without redacting)
     */
    public function detectSensitive(string $text): array { ... }
}
```

## 7. Testing Considerations

### Test Cases

| Input | Expected Output | Type Detected |
|-------|----------------|---------------|
| `email: john@test.com` | `email: [EMAIL_REDACTED]` | email |
| `call 065/123-4567` | `call [PHONE_REDACTED]` | phone |
| `card: 4111-1111-1111-1111` | `card: [CARD_REDACTED]` | credit_card |
| `JMBG: 0101990123456` | `JMBG: [PERSONAL_ID_REDACTED]` | jmbg |
| `pass: MySecret123` | `pass: [PASSWORD_REDACTED]` | password |
| `ticket #123456` | `ticket #123456` (unchanged) | — |
| `Your order 12345678901` | Not matched as JMBG (no keyword) | — |

### False Positive Mitigation

- Ticket numbers: whitelist `#\d+` pattern before phone redaction
- Order numbers: typically shorter than 13 digits
- Luhn validation for credit cards reduces false positives significantly
- JMBG: require keyword proximity OR exact 13-digit standalone pattern

## 8. Performance

- All regex patterns are pre-compiled (via PHP's PCRE engine)
- Typical message: < 1ms processing time
- No external calls needed
- Memory: negligible (string operations)

## 9. Audit Trail

- PII redaction happens BEFORE any content leaves the server
- Log table stores `hash_prompt` (SHA-256 of redacted content), never raw PII
- The `detected_types` array is available to GatingLogic for the sensitive data flag
- No PII is ever stored in the log table
