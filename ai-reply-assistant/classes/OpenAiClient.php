<?php
/**
 * AI Reply Assistant — OpenAI Client
 *
 * Handles HTTP communication with OpenAI Chat Completions API.
 * Uses PHP curl directly — no Composer, no external dependencies.
 *
 * Features:
 *   - Configurable model, temperature, max_tokens
 *   - JSON response_format enforcement
 *   - Timeout and retry with exponential backoff
 *   - Token usage tracking
 *   - No retry on auth errors (401/403)
 *
 * @package AiReplyAssistant
 */

class AiReplyOpenAiClient {

    /** API endpoint */
    const API_URL = 'https://api.openai.com/v1/chat/completions';

    /** Max retry attempts */
    const MAX_RETRIES = 2;

    /** Base retry delay in seconds */
    const RETRY_DELAY_BASE = 2;

    /** HTTP status codes that should NOT be retried */
    const NO_RETRY_CODES = array(401, 403, 400);

    /** @var PluginConfig */
    private $config;

    /**
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config) {
        $this->config = $config;
    }

    /**
     * Send a chat completion request to OpenAI.
     *
     * @param  string $systemPrompt  The system-level instructions
     * @param  string $userMessage   The user-level context payload
     * @param  string $model         Model name (e.g., gpt-4.1-mini)
     * @return array {
     *     success: bool,
     *     reply_text: string,        Raw text from the assistant
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     total_tokens: int,
     *     model: string,
     *     error: string|null,
     *     http_code: int
     * }
     */
    public function chatCompletion($systemPrompt, $userMessage, $model) {
        $apiKey = trim($this->config->get('openai_api_key') ?: '');
        if (empty($apiKey)) {
            return $this->errorResult('OpenAI API key is not configured.', 0);
        }

        // Debug: log key length and prefix (never log the full key)
        error_log('[AI Reply Assistant] API key length: ' . strlen($apiKey)
            . ', starts with: ' . substr($apiKey, 0, 8) . '...');

        $temperature = (float) ($this->config->get('openai_temperature') ?: 0.3);
        $maxTokens   = (int) ($this->config->get('openai_max_tokens') ?: 1500);
        $timeout      = (int) ($this->config->get('openai_timeout') ?: 30);

        // Clamp values
        $temperature = max(0.0, min(2.0, $temperature));
        $maxTokens   = max(100, min(4096, $maxTokens));
        $timeout      = max(10, min(120, $timeout));

        $payload = array(
            'model'           => $model,
            'messages'        => array(
                array('role' => 'system',  'content' => $systemPrompt),
                array('role' => 'user',    'content' => $userMessage),
            ),
            'temperature'     => $temperature,
            'max_tokens'      => $maxTokens,
            'response_format' => array('type' => 'json_object'),
        );

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            return $this->errorResult('Failed to encode request payload: ' . json_last_error_msg(), 0);
        }

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        );

        // Retry loop
        $lastError = '';
        $lastCode  = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = self::RETRY_DELAY_BASE * pow(2, $attempt - 1);
                sleep($delay);
            }

            $result = $this->curlRequest($jsonPayload, $headers, $timeout);

            // curl-level error
            if ($result['curl_error']) {
                $lastError = 'cURL error: ' . $result['curl_error'];
                $lastCode  = 0;
                continue; // retry on network errors
            }

            $httpCode = $result['http_code'];
            $lastCode = $httpCode;

            // Success
            if ($httpCode === 200) {
                return $this->parseResponse($result['body'], $model);
            }

            // Non-retryable HTTP errors
            if (in_array($httpCode, self::NO_RETRY_CODES)) {
                $errBody = $this->extractApiError($result['body']);
                return $this->errorResult(
                    "OpenAI API error (HTTP {$httpCode}): {$errBody}",
                    $httpCode
                );
            }

            // Rate limit (429) or server error (5xx) — retry
            $lastError = "OpenAI API error (HTTP {$httpCode}): " . $this->extractApiError($result['body']);
        }

        // All retries exhausted
        return $this->errorResult(
            "OpenAI request failed after " . (self::MAX_RETRIES + 1) . " attempts. Last error: {$lastError}",
            $lastCode
        );
    }

    /**
     * Execute the curl request.
     *
     * @param  string $jsonPayload
     * @param  array  $headers
     * @param  int    $timeout
     * @return array  { body: string, http_code: int, curl_error: string|null }
     */
    private function curlRequest($jsonPayload, array $headers, $timeout) {
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL            => self::API_URL,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch) ? curl_error($ch) : null;

        curl_close($ch);

        return array(
            'body'       => ($body !== false) ? $body : '',
            'http_code'  => $httpCode,
            'curl_error' => $curlErr,
        );
    }

    /**
     * Parse a successful OpenAI response.
     *
     * @param  string $body  Raw JSON response body
     * @param  string $model Requested model name
     * @return array
     */
    private function parseResponse($body, $model) {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $this->errorResult('Failed to decode OpenAI response JSON.', 200);
        }

        // Extract assistant reply text
        $replyText = '';
        if (isset($data['choices'][0]['message']['content'])) {
            $replyText = $data['choices'][0]['message']['content'];
        } elseif (isset($data['choices'][0]['text'])) {
            $replyText = $data['choices'][0]['text'];
        }

        if (empty(trim($replyText))) {
            return $this->errorResult('OpenAI returned an empty response.', 200);
        }

        // Extract token usage
        $promptTokens     = isset($data['usage']['prompt_tokens'])     ? (int) $data['usage']['prompt_tokens']     : 0;
        $completionTokens = isset($data['usage']['completion_tokens']) ? (int) $data['usage']['completion_tokens'] : 0;
        $totalTokens      = isset($data['usage']['total_tokens'])      ? (int) $data['usage']['total_tokens']      : 0;

        // Use the model from the response if available
        $actualModel = isset($data['model']) ? $data['model'] : $model;

        return array(
            'success'           => true,
            'reply_text'        => $replyText,
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $totalTokens,
            'model'             => $actualModel,
            'error'             => null,
            'http_code'         => 200,
        );
    }

    /**
     * Extract a human-readable error message from an OpenAI error response.
     *
     * @param  string $body
     * @return string
     */
    private function extractApiError($body) {
        if (empty($body)) {
            return '(empty response body)';
        }

        $data = json_decode($body, true);
        if (is_array($data) && isset($data['error']['message'])) {
            return $data['error']['message'];
        }

        // Truncate raw body for logging
        $truncated = mb_substr($body, 0, 300);
        return $truncated;
    }

    /**
     * Build a standardized error result.
     *
     * @param  string $message
     * @param  int    $httpCode
     * @return array
     */
    private function errorResult($message, $httpCode) {
        return array(
            'success'           => false,
            'reply_text'        => '',
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'total_tokens'      => 0,
            'model'             => '',
            'error'             => $message,
            'http_code'         => $httpCode,
        );
    }
}
