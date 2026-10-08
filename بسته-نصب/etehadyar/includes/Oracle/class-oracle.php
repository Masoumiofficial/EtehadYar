<?php
defined('ABSPATH') || exit;

/**
 * SEO insight provider.
 *
 * This class used to invent its numbers. `mock_gsc()` generated clicks,
 * impressions, CTR and position with rand(), and the template above it was
 * headed "real, from your site's data". A site owner could rewrite pages or
 * buy ads based on figures that were literally random, and the dashboard also
 * fed the fabricated CTR into its headline conversion rate.
 *
 * It now reports only what can be measured. When the site is connected to
 * Google Search Console the numbers are real; when it is not, no traffic
 * numbers are produced at all and the caller is told why.
 */
class EAIW_Oracle {

    /**
     * Full payload: source, rows and an explanation.
     *
     * @return array
     */
    public static function insights(){
        if (class_exists('\\Etehadyar\\Analytics\\SEO_Insights')) {
            return \Etehadyar\Analytics\SEO_Insights::get(6);
        }

        return [
            'source' => 'unavailable',
            'rows'   => [],
            'notice' => 'افزونهٔ هستهٔ اتحادیار فعال نیست، بنابراین داده‌ای در دسترس نیست.',
            'error'  => '',
        ];
    }

    /**
     * Measured rows only. Returns an empty array when nothing is measured.
     *
     * Kept for backward compatibility with existing templates. Callers must
     * handle an empty array rather than assuming six rows exist — that
     * assumption is exactly what the old mock data was hiding.
     *
     * @return array
     */
    public static function predict(){
        $data = self::insights();

        return isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
    }

    /**
     * Is real measured data available right now?
     *
     * @return bool
     */
    public static function has_real_data(){
        $data = self::insights();

        return 'search_console' === ($data['source'] ?? '') && !empty($data['rows']);
    }

    /**
     * Locally verifiable facts about the site's own content.
     *
     * Used when there is no traffic data. Nothing here is a traffic metric —
     * it is word counts and modification dates read from the database, so it
     * stays honest without a Google connection.
     *
     * @return array
     */
    public static function content_audit(){
        if (class_exists('\\Etehadyar\\Analytics\\SEO_Insights')) {
            return \Etehadyar\Analytics\SEO_Insights::content_audit(6);
        }

        return [];
    }
}
