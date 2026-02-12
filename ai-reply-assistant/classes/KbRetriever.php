<?php
/**
 * AI Reply Assistant — Knowledge Base Retriever
 *
 * Gathers relevant knowledge from three sources:
 *   1. Static Mini-KB  — admin-configured FAQ text
 *   2. osTicket FAQ     — keyword search in FAQ tables
 *   3. Canned Responses — keyword search in canned response templates
 *
 * Each source has its own config toggle. Results are formatted as labeled
 * sections in the prompt context for the AI model.
 *
 * Stop-word lists are multilingual (Serbian, English, German, French, Spanish,
 * Italian, Turkish, etc.) to optimize keyword extraction for international
 * osTicket deployments.
 *
 * @package AiReplyAssistant
 */

class AiReplyKbRetriever {

    /** Maximum characters per KB article body */
    const MAX_ARTICLE_BODY = 500;

    /** Minimum keyword length (characters) to keep */
    const MIN_KEYWORD_LENGTH = 2;

    /** Maximum keywords extracted from ticket text */
    const MAX_KEYWORDS = 10;

    /** @var PluginConfig */
    private $config;

    /**
     * Multilingual stop-word list.
     *
     * Covers: Serbian (Latin + Cyrillic), English, German, French, Spanish,
     * Italian, Turkish, Portuguese, Dutch, Polish, Czech.
     * Users may have osTicket in any language — keeping this broad reduces
     * noise in keyword extraction across all deployments.
     *
     * @var array
     */
    private static $stopWords = array(
        // Serbian (Latin)
        'i', 'u', 'na', 'za', 'je', 'da', 'ne', 'se', 'sa', 'su',
        'od', 'do', 'ili', 'ali', 'ako', 'kad', 'kako', 'sto', 'sta',
        'taj', 'ta', 'to', 'ovaj', 'ova', 'ovo', 'sam', 'si', 'smo',
        'ste', 'bio', 'bila', 'bilo', 'biti', 'ima', 'iz', 'po',
        'vec', 'jos', 'sve', 'koji', 'koja', 'koje', 'kao', 'mi',
        'vi', 'oni', 'one', 'ona', 'on', 'ja', 'ti', 'nam', 'vam',
        'vas', 'nas', 'nje', 'njega', 'njoj', 'njima', 'neko',
        'nesto', 'neku', 'neki', 'neke', 'ova', 'moj', 'tvoj',
        // Serbian (Cyrillic equivalents - common words)
        'и', 'у', 'на', 'за', 'је', 'да', 'не', 'се', 'са', 'су',
        'од', 'до', 'или', 'али', 'ако', 'кад', 'како', 'што', 'шта',
        // English
        'the', 'is', 'at', 'to', 'and', 'or', 'of', 'a', 'an', 'in',
        'it', 'for', 'on', 'with', 'as', 'by', 'this', 'that', 'be',
        'are', 'was', 'were', 'been', 'have', 'has', 'had', 'do', 'did',
        'not', 'but', 'from', 'they', 'we', 'you', 'he', 'she', 'my',
        'your', 'our', 'his', 'her', 'its', 'can', 'will', 'would',
        'should', 'could', 'may', 'might', 'shall', 'must', 'am',
        'what', 'when', 'where', 'who', 'how', 'which', 'all', 'each',
        'than', 'then', 'so', 'if', 'no', 'yes', 'up', 'out', 'about',
        // German
        'der', 'die', 'das', 'ein', 'eine', 'und', 'ist', 'ich', 'du',
        'wir', 'sie', 'er', 'es', 'von', 'mit', 'den', 'dem', 'des',
        'auf', 'fur', 'nicht', 'sich', 'auch', 'noch', 'wie', 'nur',
        'aber', 'hat', 'war', 'bei', 'nach', 'aus', 'wenn', 'kann',
        'mir', 'dir', 'uns', 'ihr', 'was', 'zum', 'zur',
        // French
        'le', 'la', 'les', 'un', 'une', 'des', 'et', 'est', 'en',
        'que', 'qui', 'dans', 'ce', 'il', 'pas', 'ne', 'plus',
        'son', 'ses', 'par', 'sur', 'pour', 'au', 'aux', 'avec',
        'mais', 'ou', 'sont', 'nous', 'vous', 'ils', 'ont', 'tout',
        // Spanish
        'el', 'en', 'es', 'lo', 'los', 'las', 'del', 'al',
        'con', 'por', 'una', 'uno', 'nos', 'sin', 'sobre', 'hay',
        'sus', 'mas', 'pero', 'como', 'muy', 'bien', 'fue', 'ser',
        'esta', 'este', 'ese', 'esos', 'esa', 'esas', 'ya', 'hay',
        // Italian
        'il', 'lo', 'gli', 'di', 'del', 'dal', 'nel', 'con',
        'non', 'che', 'per', 'sono', 'una', 'suo', 'sua', 'loro',
        'anche', 'piu', 'tra', 'fra', 'dove', 'come', 'chi',
        // Turkish
        'bir', 'bu', 've', 'ile', 'ama', 'var', 'yok', 'ben',
        'sen', 'biz', 'siz', 'onlar', 'gibi', 'daha', 'icin',
        'olan', 'olarak',
        // Portuguese
        'que', 'nao', 'tem', 'uma', 'dos', 'das', 'nos',
        'como', 'mais', 'por', 'com', 'para', 'seu', 'sua',
        'muito', 'pode', 'esta', 'isso', 'foi',
        // Dutch
        'het', 'een', 'van', 'dat', 'ik', 'wat', 'maar',
        'hij', 'zij', 'wij', 'dit', 'ook', 'wel', 'nog',
        'aan', 'uit', 'als', 'dan',
        // Polish
        'nie', 'tak', 'jak', 'ale', 'czy', 'jest',
        'ten', 'tam', 'tym', 'tych', 'tej',
        // Czech
        'ten', 'ale', 'jak', 'tak', 'pak',
    );

    /**
     * @param PluginConfig $config
     */
    public function __construct(PluginConfig $config) {
        $this->config = $config;
    }

    /**
     * Retrieve all knowledge base content for the prompt.
     *
     * @param  Ticket      $ticket
     * @param  ThreadEntry $entry
     * @return string      Formatted KB sections for prompt inclusion
     */
    public function retrieve(Ticket $ticket, ThreadEntry $entry) {
        $sections = array();

        // Source 1: Static Mini-KB
        $miniKb = $this->getMiniKb();
        if (!empty($miniKb)) {
            $sections[] = $miniKb;
        }

        // Extract keywords from ticket for dynamic searches
        $subject     = $ticket->getSubject() ?: '';
        $lastMessage = $this->getEntryText($entry);
        $keywords    = $this->extractKeywords($subject, $lastMessage);

        // Source 2: osTicket FAQ articles
        if ($this->config->get('use_osticket_kb')) {
            $articles = $this->searchFaq($keywords);
            if (!empty($articles)) {
                $sections[] = $this->formatFaqSection($articles);
            }
        }

        // Source 3: Canned Responses
        if ($this->config->get('use_canned_responses')) {
            $deptId = 0;
            try {
                $dept = $ticket->getDept();
                if ($dept) {
                    $deptId = (int) $dept->getId();
                }
            } catch (\Exception $e) {}

            $canned = $this->searchCannedResponses($keywords, $deptId);
            if (!empty($canned)) {
                $sections[] = $this->formatCannedSection($canned);
            }
        }

        if (empty($sections)) {
            return '';
        }

        return implode("\n\n", $sections);
    }

    // ─── Source 1: Static Mini-KB ───

    /**
     * Get the static Mini-KB content from config.
     *
     * @return string Formatted section or empty
     */
    private function getMiniKb() {
        $content = trim($this->config->get('mini_kb_content') ?: '');

        if (empty($content)) {
            return '';
        }

        // Enforce max length
        if (mb_strlen($content) > 4000) {
            $content = mb_substr($content, 0, 4000) . "\n... (truncated)";
        }

        return "=== INTERNAL KNOWLEDGE BASE ===\n" . $content . "\n=== END INTERNAL KNOWLEDGE BASE ===";
    }

    // ─── Source 2: osTicket FAQ Search ───

    /**
     * Search osTicket FAQ tables for relevant articles.
     *
     * @param  array $keywords Extracted keywords
     * @return array Array of { title, body }
     */
    private function searchFaq(array $keywords) {
        if (empty($keywords)) {
            return array();
        }

        $limit = max(1, (int) ($this->config->get('kb_article_limit') ?: 3));

        try {
            // Build LIKE conditions for each keyword
            $conditions  = array();
            $scoreTerms  = array();

            foreach ($keywords as $kw) {
                $escaped = db_input($kw);
                $cond = "(f.question LIKE '%" . $escaped . "%' OR f.answer LIKE '%" . $escaped . "%')";
                $conditions[] = $cond;
                $scoreTerms[] = "IF({$cond}, 1, 0)";
            }

            // Determine table names — osTicket defines FAQ_TABLE constant
            $faqTable = defined('FAQ_TABLE') ? FAQ_TABLE : TABLE_PREFIX . 'faq';
            $catTable = defined('FAQ_CATEGORY_TABLE') ? FAQ_CATEGORY_TABLE : TABLE_PREFIX . 'faq_category';

            $sql = "SELECT f.faq_id, f.question, f.answer, "
                 . "(" . implode(' + ', $scoreTerms) . ") AS relevance "
                 . "FROM `{$faqTable}` f "
                 . "JOIN `{$catTable}` c ON f.category_id = c.category_id "
                 . "WHERE f.ispublished = 1 "
                 . "  AND c.ispublic = 1 "
                 . "  AND (" . implode(' OR ', $conditions) . ") "
                 . "ORDER BY relevance DESC "
                 . "LIMIT " . (int) $limit;

            $res = db_query($sql);
            if (!$res) {
                return array();
            }

            $results = array();
            while ($row = db_fetch_array($res)) {
                $body = strip_tags($row['answer']);
                $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
                $body = trim($body);

                if (mb_strlen($body) > self::MAX_ARTICLE_BODY) {
                    $body = mb_substr($body, 0, self::MAX_ARTICLE_BODY) . '...';
                }

                $results[] = array(
                    'title' => $row['question'],
                    'body'  => $body,
                );
            }

            return $results;

        } catch (\Exception $e) {
            error_log('[AI Reply Assistant] FAQ search error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Format FAQ articles into a prompt section.
     *
     * @param  array $articles
     * @return string
     */
    private function formatFaqSection(array $articles) {
        $section = "=== KNOWLEDGE BASE ARTICLES ===\n";

        foreach ($articles as $i => $article) {
            if ($i > 0) {
                $section .= "\n";
            }
            $section .= "Article: \"" . $article['title'] . "\"\n";
            $section .= "Content: " . $article['body'] . "\n";
        }

        $section .= "=== END KNOWLEDGE BASE ARTICLES ===";
        return $section;
    }

    // ─── Source 3: Canned Responses ───

    /**
     * Search osTicket canned responses by keyword match.
     *
     * @param  array $keywords Extracted keywords
     * @param  int   $deptId   Department ID (0 = match all-dept responses)
     * @return array Array of { title, body }
     */
    private function searchCannedResponses(array $keywords, $deptId = 0) {
        if (empty($keywords)) {
            return array();
        }

        $limit = max(1, (int) ($this->config->get('kb_article_limit') ?: 3));

        try {
            $conditions = array();
            $scoreTerms = array();

            foreach ($keywords as $kw) {
                $escaped = db_input($kw);
                $cond = "(c.title LIKE '%" . $escaped . "%' OR c.response LIKE '%" . $escaped . "%')";
                $conditions[] = $cond;
                $scoreTerms[] = "IF({$cond}, 1, 0)";
            }

            $cannedTable = defined('CANNED_TABLE') ? CANNED_TABLE : TABLE_PREFIX . 'canned_response';

            $sql = "SELECT c.canned_id, c.title, c.response, "
                 . "(" . implode(' + ', $scoreTerms) . ") AS relevance "
                 . "FROM `{$cannedTable}` c "
                 . "WHERE c.isenabled = 1 "
                 . "  AND (c.dept_id = 0 OR c.dept_id = " . (int) $deptId . ") "
                 . "  AND (" . implode(' OR ', $conditions) . ") "
                 . "ORDER BY relevance DESC "
                 . "LIMIT " . (int) $limit;

            $res = db_query($sql);
            if (!$res) {
                return array();
            }

            $results = array();
            while ($row = db_fetch_array($res)) {
                $body = strip_tags($row['response']);
                $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
                $body = trim($body);

                if (mb_strlen($body) > self::MAX_ARTICLE_BODY) {
                    $body = mb_substr($body, 0, self::MAX_ARTICLE_BODY) . '...';
                }

                $results[] = array(
                    'title' => $row['title'],
                    'body'  => $body,
                );
            }

            return $results;

        } catch (\Exception $e) {
            error_log('[AI Reply Assistant] Canned response search error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Format canned responses into a prompt section.
     *
     * @param  array $responses
     * @return string
     */
    private function formatCannedSection(array $responses) {
        $section = "=== CANNED RESPONSE TEMPLATES ===\n";

        foreach ($responses as $i => $resp) {
            if ($i > 0) {
                $section .= "\n";
            }
            $section .= "Template: \"" . $resp['title'] . "\"\n";
            $section .= "Content: " . $resp['body'] . "\n";
        }

        $section .= "=== END CANNED RESPONSE TEMPLATES ===";
        return $section;
    }

    // ─── Keyword Extraction ───

    /**
     * Extract meaningful keywords from ticket subject + message.
     *
     * Applies multilingual stop-word filtering and length constraints.
     *
     * @param  string $subject     Ticket subject
     * @param  string $lastMessage Last user message text
     * @return array  Unique, filtered keywords (max MAX_KEYWORDS)
     */
    private function extractKeywords($subject, $lastMessage) {
        $text = $subject . ' ' . $lastMessage;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Split into words
        $words = preg_split('/[\s\p{P}]+/u', mb_strtolower($text));

        // Build stop-word hash for O(1) lookup
        static $stopHash = null;
        if ($stopHash === null) {
            $stopHash = array_flip(self::$stopWords);
        }

        $keywords = array();
        foreach ($words as $word) {
            $word = trim($word);

            // Skip short words and stop words
            if (mb_strlen($word) <= self::MIN_KEYWORD_LENGTH) {
                continue;
            }
            if (isset($stopHash[$word])) {
                continue;
            }
            // Skip pure numbers
            if (preg_match('/^\d+$/', $word)) {
                continue;
            }

            $keywords[$word] = true; // dedup via keys

            if (count($keywords) >= self::MAX_KEYWORDS) {
                break;
            }
        }

        return array_keys($keywords);
    }

    /**
     * Extract plain text from a ThreadEntry body.
     *
     * @param  ThreadEntry $entry
     * @return string
     */
    private function getEntryText(ThreadEntry $entry) {
        try {
            $body = $entry->getBody();
            if ($body instanceof ThreadEntryBody) {
                $text = $body->getClean();
                if (empty($text)) {
                    $text = strip_tags((string) $body->display('html'));
                }
            } else {
                $text = strip_tags((string) $body);
            }

            return html_entity_decode(trim($text), ENT_QUOTES, 'UTF-8');

        } catch (\Exception $e) {
            return '';
        }
    }
}
