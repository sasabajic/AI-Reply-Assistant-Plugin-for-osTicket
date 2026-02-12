# 09 – Internal Note Format

## 1. Note Appearance

When the AI generates a draft, it is posted as an **Internal Note** on the ticket.
This note is visible only to agents, never to the customer.

## 2. Note Title

```
[AI Draft] (confidence: 0.85)
```

Format: `[AI Draft] (confidence: {confidence_value})`

- Confidence from model response, formatted to 2 decimal places
- If confidence is missing/invalid: `[AI Draft] (confidence: N/A)`

## 3. Note Body Structure

```html
<div class="ai-draft-note">
  <p><strong>AI-Generated Draft Reply</strong></p>
  <p><em>This is an AI-generated suggestion. Please review before sending to the customer.</em></p>
  
  <hr>
  
  <p><strong>Suggested Subject:</strong> {reply_subject}</p>
  
  <div class="ai-draft-body">
    {reply_body — with line breaks converted to <br> or <p> tags}
  </div>
  
  <!-- Only if need_more_info = true -->
  <hr>
  <p><strong>Additional Information Needed:</strong></p>
  <ul>
    <li>{question_1}</li>
    <li>{question_2}</li>
    <li>{question_3}</li>
  </ul>
  
  <!-- Only if suggested_tags is non-empty -->
  <hr>
  <p><strong>Suggested Tags:</strong> {tag1}, {tag2}, {tag3}</p>
  
  <hr>
  <p style="font-size: 0.85em; color: #888;">
    Model: {model} | Tokens: {total_tokens} | Latency: {latency_ms}ms | 
    Confidence: {confidence}
  </p>
</div>
```

## 4. Example Rendered Note

---

**AI-Generated Draft Reply**

*This is an AI-generated suggestion. Please review before sending to the customer.*

---

**Suggested Subject:** Resetovanje lozinke

Poštovani,

Zahvaljujemo Vam se na obraćanju. Za resetovanje lozinke, molimo Vas da pratite sledeće korake:

1. Otvorite stranicu za prijavu
2. Kliknite na "Zaboravili ste lozinku?"
3. Unesite Vašu email adresu
4. Proverite inbox za link za resetovanje

Ukoliko imate dodatnih pitanja, slobodno se obratite.

Srdačan pozdrav

---

**Suggested Tags:** password-reset, account

---

<small>Model: gpt-4.1-mini | Tokens: 350 | Latency: 2100ms | Confidence: 0.90</small>

---

## 5. HTML Safety

- All AI-generated text is **HTML-escaped** before insertion (`htmlspecialchars()`)
- Line breaks in `reply_body` are converted to `<br>` after escaping
- No raw HTML from the model is rendered
- This prevents XSS if the model returns malicious HTML

## 6. Note Metadata

| Property | Value |
|----------|-------|
| Type | Internal Note (`N`) |
| Poster | System (or a configured "AI Agent" staff account) |
| Alert | OFF (no email notification for AI notes) |
| Auto-response | OFF |

### 6.1 Note Poster Identity

**Option A**: Post as "SYSTEM" (no specific staff user)
- Simplest, no extra setup
- Uses `ThreadEntry::create()` with `poster` = 'SYSTEM'

**Option B**: Create a dedicated "AI Assistant" staff account
- More traceable
- Requires manual setup (create staff user)
- Name shown in thread: "AI Assistant"

**Recommendation for MVP**: Option A (SYSTEM). Document Option B as advanced config.

## 7. Edge Cases

| Case | Handling |
|------|----------|
| Very long reply_body (>5000 chars) | Truncate at 5000 chars + add "... (truncated)" |
| Empty reply_body | Do not create note, log as error |
| HTML in model response | Escape all HTML entities |
| Unicode / special chars | UTF-8 encoding throughout |
| Questions array empty but need_more_info=true | Show "Additional information may be needed." |
