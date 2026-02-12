<?php
/**
 * AI Reply Assistant — PII Redactor
 *
 * Masks personally identifiable information (PII) in text before
 * sending to OpenAI. Supports emails, phone numbers, credit cards,
 * JMBG/personal IDs, passwords, and optionally IP addresses.
 *
 * Redaction order (to prevent pattern interference):
 *   1. URLs with credentials
 *   2. Email addresses
 *   3. Credit card numbers (with Luhn validation)
 *   4. JMBG / personal ID numbers
 *   5. Passwords / credentials
 *   6. Phone numbers
 *   7. IP addresses (if enabled)
 *
 * @package AiReplyAssistant
 */

class AiReplyPiiRedactor {

    /** @var bool Whether redaction is enabled */
    private $enabled;

    /** @var bool Whether to redact IP addresses */
    private $redactIp;

    /** @var array Compiled pattern definitions */
    private $patterns;

    /**
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config) {
        $this->enabled  = (bool) $config->get('pii_redaction_enabled');
        $this->redactIp = (bool) $config->get('redact_ip_addresses');
        $this->patterns = $this->buildPatterns();
    }

    /**
     * Redact PII from text.
     *
     * @param  string $text Input text
     * @return array  [0 => redacted_text, 1 => detected_type_names[]]
     */
    public function redact($text) {
        if (!$this->enabled || empty($text)) {
            return array($text, array());
        }

        $detectedTypes = array();

        foreach ($this->patterns as $name => $spec) {
            if (!$spec['enabled']) {
                continue;
            }

            $count = 0;

            if (isset($spec['callback'])) {
                // Use callback for complex patterns (credit cards with Luhn)
                $text = preg_replace_callback(
                    $spec['pattern'],
                    $spec['callback'],
                    $text,
                    -1,
                    $count
                );
            } else {
                $text = preg_replace(
                    $spec['pattern'],
                    $spec['replacement'],
                    $text,
                    -1,
                    $count
                );
            }

            if ($count > 0) {
                $detectedTypes[] = $name;
            }
        }

        return array($text, $detectedTypes);
    }

    /**
     * Detect sensitive data types without redacting.
     *
     * @param  string $text
     * @return array  List of detected type names (e.g., ['credit_card', 'email'])
     */
    public function detectSensitive($text) {
        if (empty($text)) {
            return array();
        }

        $detected = array();

        foreach ($this->patterns as $name => $spec) {
            if (!$spec['enabled']) {
                continue;
            }
            if (preg_match($spec['pattern'], $text)) {
                $detected[] = $name;
            }
        }

        return $detected;
    }

    /**
     * Build the ordered pattern definitions.
     *
     * @return array
     */
    private function buildPatterns() {
        return array(

            // 1. URLs with embedded credentials (https://user:pass@host)
            'url_credentials' => array(
                'enabled'     => true,
                'pattern'     => '#https?://[^:\s]+:[^@\s]+@\S+#i',
                'replacement' => '[URL_WITH_CREDENTIALS_REDACTED]',
            ),

            // 2. Email addresses
            'email' => array(
                'enabled'     => true,
                'pattern'     => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/u',
                'replacement' => '[EMAIL_REDACTED]',
            ),

            // 3. Credit card numbers — with Luhn validation to reduce false positives
            'credit_card' => array(
                'enabled'  => true,
                'pattern'  => '/\b(\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4})\b/',
                'callback' => function ($matches) {
                    $digits = preg_replace('/\D/', '', $matches[0]);
                    if (strlen($digits) >= 13 && strlen($digits) <= 19 && self::luhnCheck($digits)) {
                        return '[CARD_REDACTED]';
                    }
                    return $matches[0]; // Not a valid card number
                },
            ),

            // 3b. Amex format (15 digits: 4-6-5)
            'credit_card_amex' => array(
                'enabled'  => true,
                'pattern'  => '/\b(3[47]\d{2}[\s\-]?\d{6}[\s\-]?\d{5})\b/',
                'callback' => function ($matches) {
                    $digits = preg_replace('/\D/', '', $matches[0]);
                    if (self::luhnCheck($digits)) {
                        return '[CARD_REDACTED]';
                    }
                    return $matches[0];
                },
            ),

            // 4. JMBG — Serbian personal ID number (13 digits)
            //    Contextual: keyword proximity increases confidence, but we also match
            //    standalone 13-digit numbers in a cautious context
            'jmbg' => array(
                'enabled'     => true,
                'pattern'     => '/(?:(?:JMBG|jmbg|matičn[ia]\s*broj|lični\s*broj|jedinstveni\s*matični)\s*[:=]?\s*)(\d{13})\b/u',
                'replacement' => '[PERSONAL_ID_REDACTED]',
            ),

            // 4b. Standalone 13-digit number that looks like JMBG (date-prefixed)
            'jmbg_standalone' => array(
                'enabled'     => true,
                'pattern'     => '/\b([0-3]\d[01]\d[09]\d{2}\d{6})\b/',
                'replacement' => '[PERSONAL_ID_REDACTED]',
            ),

            // 5. Passwords / credentials (keyword:value patterns)
            'password' => array(
                'enabled'     => true,
                'pattern'     => '/(?:password|lozinka|pass|pwd|šifra|sifra|passw|passwd)\s*[:=]\s*\S+/iu',
                'replacement' => '[PASSWORD_REDACTED]',
            ),

            // 6. Phone numbers — Serbian formats + international
            'phone_serbian' => array(
                'enabled'     => true,
                'pattern'     => '/\b0[6][0-9][\s\/\-]?\d{3}[\s\/\-]?\d{3,4}\b/',
                'replacement' => '[PHONE_REDACTED]',
            ),

            'phone_international' => array(
                'enabled'     => true,
                'pattern'     => '/\+\d{1,3}[\s\-]?\(?\d{1,4}\)?[\s\-]?\d{2,4}[\s\-]?\d{2,4}[\s\-]?\d{0,4}\b/',
                'replacement' => '[PHONE_REDACTED]',
            ),

            'phone_landline' => array(
                'enabled'     => true,
                'pattern'     => '/\b0[1-3][0-9][\s\/\-]?\d{5,7}\b/',
                'replacement' => '[PHONE_REDACTED]',
            ),

            // 7. IP addresses (optional — off by default)
            'ip_address' => array(
                'enabled'     => $this->redactIp,
                'pattern'     => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
                'replacement' => '[IP_REDACTED]',
            ),
        );
    }

    /**
     * Luhn algorithm check for credit card number validation.
     *
     * @param  string $number Digits only
     * @return bool   True if valid Luhn
     */
    private static function luhnCheck($number) {
        $number = strrev($number);
        $sum = 0;
        $len = strlen($number);

        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $number[$i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return ($sum % 10) === 0;
    }
}
