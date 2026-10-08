<?php
defined('ABSPATH') || exit;
class EAIW_Site_Brain {
    // ایندکس دسته‌ای — 20 محتوا در هر درخواست AJAX
    public static function index_batch($offset=0, $limit=20){
        $q = new WP_Query([
            'post_type' => ['post','page','product'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        $indexed=0; $errors=[];

        // Chunks are embedded in batches. One HTTP request per chunk would
        // mean hundreds of round-trips for a 20-post batch, which exceeds the
        // PHP time limit on ordinary shared hosting long before it finishes.
        $pending = [];
        foreach($q->posts as $post){
            $content = $post->post_title . "\n\n" . $post->post_content;
            // Knowledge Hub — یادداشت‌ها را هم اضافه کن (اگر جدول دارد)
            $chunks = EAIW_Vector_Store::chunk_text($content, 900);
            foreach($chunks as $i=>$chunk){
                $pending[] = ['type'=>$post->post_type, 'id'=>$post->ID, 'index'=>$i, 'text'=>$chunk];
            }
            $indexed++;
        }

        $vectors = self::embed_many(array_column($pending, 'text'));

        foreach($pending as $n=>$item){
            EAIW_Vector_Store::upsert(
                $item['type'],
                $item['id'],
                $item['index'],
                $item['text'],
                isset($vectors[$n]) ? $vectors[$n] : null
            );
        }
        $total = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
        if (class_exists('WooCommerce')) $total += wp_count_posts('product')->publish;
        update_option('eaiw_site_brain_last_index', time());
        return [
            'indexed' => $indexed,
            'offset' => $offset + $limit,
            'has_more' => $q->post_count === $limit,
            'total_estimate' => $total,
        ];
    }
    /**
     * Embed many chunks, preserving input order.
     *
     * A failed batch yields nulls rather than an exception: the text is still
     * stored and findable by keyword, and the admin screen reports the index
     * as incomplete so the gap is visible.
     *
     * @param array $texts Chunk texts.
     * @return array Map of input index => vector or null.
     */
    private static function embed_many($texts){
        $out = [];

        if (!$texts || !class_exists('\\Etehadyar\\Brain\\Embeddings')) {
            return $out;
        }

        if (!\Etehadyar\Brain\Embeddings::is_available()) {
            return $out;
        }

        $size = \Etehadyar\Brain\Embeddings::MAX_BATCH;

        // array_chunk with preserve_keys keeps each chunk's original position
        // so a vector is never attached to the wrong text.
        foreach (array_chunk($texts, $size, true) as $batch) {
            $vectors = \Etehadyar\Brain\Embeddings::embed_batch(array_values($batch));

            if (is_wp_error($vectors)) {
                continue;
            }

            foreach (array_keys($batch) as $position => $original_index) {
                if (isset($vectors[$position])) {
                    $out[$original_index] = $vectors[$position];
                }
            }
        }

        return $out;
    }

    /**
     * Build a real embedding for a chunk.
     *
     * This used to return a vector derived from md5(). A hash deliberately
     * destroys locality, so two nearly identical sentences produced unrelated
     * vectors: measured on the original code, adding one full stop dropped
     * similarity to -0.17, the same as a completely unrelated topic. The
     * 64-value vector was also just 32 values repeated twice.
     *
     * Returning null when no key is configured is deliberate. The chunk is
     * still stored and remains findable by keyword search, and the admin
     * screen reports the index as incomplete — which is honest, unlike a
     * fake vector that makes the feature look functional.
     *
     * @param string $text Chunk text.
     * @return array|null
     */
    private static function try_embedding($text){
        if (!class_exists('\\Etehadyar\\Brain\\Embeddings')) {
            return null;
        }

        if (!\Etehadyar\Brain\Embeddings::is_available()) {
            return null;
        }

        $vector = \Etehadyar\Brain\Embeddings::embed($text);

        return is_wp_error($vector) ? null : $vector;
    }
    public static function stats(){
        global $wpdb;
        $t=$wpdb->prefix.'eaiw_vectors';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $t");
        $last = get_option('eaiw_site_brain_last_index',0);
        return ['count'=>$count?:0,'last_index'=>$last?human_time_diff($last):'هرگز'];
    }
}
