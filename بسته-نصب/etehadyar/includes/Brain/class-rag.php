<?php
defined('ABSPATH') || exit;

/**
 * Retrieval over the site's indexed content.
 *
 * The previous implementation built its query "embedding" from md5(). Measured
 * against that code, adding a single full stop to a sentence dropped cosine
 * similarity to -0.17 — exactly the score of a completely unrelated topic —
 * and the 64-value vector was really 32 values repeated twice. Hashes are
 * designed to destroy locality, so nothing about that was semantic. In
 * practice the LIKE fallback did all the work while the UI called it
 * "semantic memory".
 *
 * Retrieval now runs through the core plugin, which uses a real embeddings
 * API and reports whether a given result set was semantic or keyword-based.
 */
class EAIW_RAG {

    /**
     * Search the index.
     *
     * @param string $query Search text.
     * @param int    $top_k Maximum results.
     * @return array
     */
    public static function search($query, $top_k = 6){
        $data = self::search_detailed($query, $top_k);

        return $data['results'];
    }

    /**
     * Search, including how the results were obtained.
     *
     * @param string $query Search text.
     * @param int    $top_k Maximum results.
     * @return array{mode:string,results:array,note:string}
     */
    public static function search_detailed($query, $top_k = 6){
        $query = trim((string) $query);

        if ('' === $query) {
            return ['mode' => 'keyword', 'results' => [], 'note' => ''];
        }

        if (!class_exists('\\Etehadyar\\Brain\\Semantic_Search')) {
            return [
                'mode'    => 'keyword',
                'results' => [],
                'note'    => 'افزونهٔ هستهٔ اتحادیار فعال نیست.',
            ];
        }

        $found = \Etehadyar\Brain\Semantic_Search::search($query, $top_k);
        $out   = [];

        foreach ($found['results'] as $hit) {
            $r    = $hit['row'];
            $post = get_post($r['object_id']);

            $out[] = [
                // Null for keyword hits: the old code reported a flat 0.5 for
                // every text match, which displayed a guess as a measurement.
                'score'    => isset($hit['score']) ? $hit['score'] : null,
                'type'     => $r['object_type'],
                'id'       => $r['object_id'],
                'title'    => $post ? get_the_title($post) : $r['object_type'].' #'.$r['object_id'],
                'url'      => $post ? get_permalink($post) : '',
                'snippet'  => mb_substr($r['content'], 0, 180).'…',
                'edit_url' => $post ? get_edit_post_link($post->ID, '') : '',
            ];
        }

        return ['mode' => $found['mode'], 'results' => $out, 'note' => $found['note']];
    }

    /**
     * Is real semantic retrieval active?
     *
     * @return bool
     */
    public static function is_semantic(){
        return class_exists('\\Etehadyar\\Brain\\Embeddings')
            && \Etehadyar\Brain\Embeddings::is_available();
    }

    /**
     * Build grounding context for a prompt.
     *
     * @param string $query Search text.
     * @param int    $k     Maximum sources.
     * @return string
     */
    public static function context_for_prompt($query, $k = 4){
        $data = self::search_detailed($query, $k);
        $hits = $data['results'];

        // Semantic hits are already filtered by a similarity floor, so the old
        // keyword post-filter would only throw away correct matches that
        // happen to share no literal words with the query — precisely the
        // results a semantic search exists to find.
        if ('keyword' === $data['mode']) {
            $hits = array_filter($hits, function ($h) use ($query) {
                $words   = preg_split('/\s+/u', mb_strtolower(wp_strip_all_tags($query)));
                $text    = mb_strtolower(($h['title'] ?? '').' '.($h['snippet'] ?? ''));
                $matches = 0;

                foreach ($words as $word) {
                    if (mb_strlen($word) > 3 && mb_stripos($text, $word) !== false) {
                        $matches++;
                    }
                }

                return $matches > 0;
            });
        }

        if (!$hits) {
            return '';
        }

        // The model is told how these sources were found. Presenting keyword
        // matches as verified context is how a weak retrieval turns into a
        // confident wrong answer.
        $ctx = ('semantic' === $data['mode'])
            ? "منابع مرتبط از محتوای سایت (جست‌وجوی معنایی):\n"
            : "منابع احتمالی از محتوای سایت (تطابق متنی ساده — ممکن است دقیق نباشد):\n";

        $i = 0;
        foreach ($hits as $h) {
            $i++;
            $ctx .= sprintf("%d. [%s] %s — %s\n%s\n", $i, $h['type'], $h['title'], $h['url'], $h['snippet']);
        }

        return $ctx;
    }
}
