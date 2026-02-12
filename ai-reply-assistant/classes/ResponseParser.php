<?php
/**
 * AI Reply Assistant — Response Parser
 *
 * Parses the raw OpenAI response text into a structured array.
 * Implements 3-level JSON extraction fallback:
 *   1. Direct json_decode
 *   2. Extract from markdown code block (```json ... ```)
 *   3. Extract between first { and last }
 *
 * Validates required fields and applies defaults for missing optional fields.
 *
 * @package AiReplyAssistant
 */

class AiReplyResponseParser {

    /** Maximum allowed confidence value */
    const MAX_CONFIDENCE = 1.0;

    /** Minimum allowed confidence value */
    const MIN_CONFIDENCE = 0.0;

    /** Maximum reply_subject length */
    const MAX_SUBJECT_LENGTH = 100;

    /** Maximum number of suggested tags */
    const MAX_TAGS = 3;

    /** Maximum number of clarifying questions */
    const MAX_QUESTIONS = 3;

    /**
     * Parse raw OpenAI response text into structured reply data.
     *
     * @param  string $rawText The raw response text from OpenAI
     * @return array {
     *     success: bool,
     *     data: array|null {
     *         reply_subject: string,
     *         reply_body: string,
     *         need_more_info: bool,
     *         questions: string[],
     *         suggested_tags: string[],
     *         confidence: float
     *     },
     *     error: string|null
     * }
     */
    public function parse($rawText) {
        if (empty(trim($rawText))) {
            return $this->errorResult('Empty response text from OpenAI.');
        }

        // Attempt JSON extraction with 3-level fallback
        $decoded = $this->extractJson($rawText);

        if ($decoded === null) {
            return $this->errorResult(
                'Failed to extract valid JSON from OpenAI response. '
                . 'Raw text (first 300 chars): ' . mb_substr($rawText, 0, 300)
            );
        }

        // Validate and normalize the decoded data
        return $this->validateAndNormalize($decoded);
    }

    /**
     * Extract JSON from raw text using 3-level fallback.
     *
     * @param  string $rawText
     * @return array|null  Decoded array or null on failure
     */
    private function extractJson($rawText) {
        $text = trim($rawText);

        // Level 1: Direct json_decode
        $decoded = json_decode($text, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Level 2: Extract from markdown code block ```json ... ```
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $text, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Level 3: Extract between first { and last }
        $firstBrace = strpos($text, '{');
        $lastBrace  = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonCandidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($jsonCandidate, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Validate required fields and normalize optional fields.
     *
     * @param  array $data Decoded JSON data
     * @return array
     */
    private function validateAndNormalize(array $data) {
        // Validate required field: reply_body
        if (empty($data['reply_body']) || !is_string($data['reply_body'])) {
            return $this->errorResult(
                'OpenAI response missing required field "reply_body" or it is empty.'
            );
        }

        // Normalize reply_subject
        $subject = isset($data['reply_subject']) && is_string($data['reply_subject'])
            ? $data['reply_subject']
            : 'AI Draft Reply';

        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            $subject = mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH);
        }

        // Normalize reply_body (trim whitespace)
        $body = trim($data['reply_body']);

        // Normalize need_more_info
        $needMoreInfo = false;
        if (isset($data['need_more_info'])) {
            $needMoreInfo = (bool) $data['need_more_info'];
        }

        // Normalize questions
        $questions = array();
        if (isset($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $q) {
                if (is_string($q) && !empty(trim($q))) {
                    $questions[] = trim($q);
                    if (count($questions) >= self::MAX_QUESTIONS) {
                        break;
                    }
                }
            }
        }

        // Normalize suggested_tags
        $tags = array();
        if (isset($data['suggested_tags']) && is_array($data['suggested_tags'])) {
            foreach ($data['suggested_tags'] as $tag) {
                if (is_string($tag) && !empty(trim($tag))) {
                    // Sanitize tags: lowercase, trim, limit length
                    $clean = mb_strtolower(trim($tag));
                    $clean = preg_replace('/[^a-z0-9\-_\x{0400}-\x{04FF}]/u', '', $clean);
                    if (!empty($clean)) {
                        $tags[] = $clean;
                    }
                    if (count($tags) >= self::MAX_TAGS) {
                        break;
                    }
                }
            }
        }

        // Normalize confidence
        $confidence = 0.5; // default
        if (isset($data['confidence'])) {
            $confidence = (float) $data['confidence'];
            $confidence = max(self::MIN_CONFIDENCE, min(self::MAX_CONFIDENCE, $confidence));
        }

        return array(
            'success' => true,
            'data'    => array(
                'reply_subject'  => $subject,
                'reply_body'     => $body,
                'need_more_info' => $needMoreInfo,
                'questions'      => $questions,
                'suggested_tags' => $tags,
                'confidence'     => round($confidence, 2),
            ),
            'error'   => null,
        );
    }

    /**
     * Build a standardized error result.
     *
     * @param  string $message
     * @return array
     */
    private function errorResult($message) {
        return array(
            'success' => false,
            'data'    => null,
            'error'   => $message,
        );
    }
}
