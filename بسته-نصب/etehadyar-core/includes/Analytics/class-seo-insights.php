<?php
/**
 * Honest SEO insights.
 *
 * @package Etehadyar\Analytics
 */

namespace Etehadyar\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the SEO panel with data that is either real or openly labelled as
 * unavailable.
 *
 * The legacy `EAIW_Oracle` invented its numbers with `rand()` and the template
 * above it was headed "real, from your site's data". Clicks, impressions, CTR
 * and position were all fabricated, and the dashboard fed the fake CTR into
 * the headline conversion figure. A site owner could have made decisions —
 * rewriting pages, buying ads — on numbers that were literally random.
 *
 * This class never invents a metric. Every result carries a `source` field:
 *
 *   'search_console' — measured by Google.
 *   'unavailable'    — not connected, or the API failed. No numbers at all.
 *
 * Where the plugin can still say something useful without measurement, it
 * returns a *content audit* instead: facts about the site's own posts (age,
 * length, missing excerpt) which are locally verifiable and never presented
 * as traffic data.
 */
class SEO_Insights {

	/**
	 * Build the insight payload.
	 *
	 * @param int $limit Maximum rows.
	 * @return array {
	 *     @type string $source  One of 'search_console', 'unavailable'.
	 *     @type array  $rows    Result rows (empty when unavailable).
	 *     @type string $notice  Human explanation for the UI.
	 *     @type string $error   Error detail, when relevant.
	 * }
	 */
	public static function get( $limit = 6 ) {
		if ( ! Search_Console::is_connected() ) {
			return array(
				'source' => 'unavailable',
				'rows'   => array(),
				'notice' => __( 'به سرچ کنسول گوگل وصل نیستید، بنابراین داده‌ای دربارهٔ کلیک، جایگاه یا نرخ کلیک در دست نیست. برای دیدن آمار واقعی، از تنظیمات به گوگل وصل شوید.', 'etehadyar-core' ),
				'error'  => '',
			);
		}

		$rows = Search_Console::pages();

		if ( is_wp_error( $rows ) ) {
			return array(
				'source' => 'unavailable',
				'rows'   => array(),
				'notice' => __( 'دریافت داده از سرچ کنسول ناموفق بود. تا رفع مشکل، عددی نمایش داده نمی‌شود.', 'etehadyar-core' ),
				'error'  => $rows->get_error_message(),
			);
		}

		$rows = array_slice( $rows, 0, max( 1, (int) $limit ) );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ] = array_merge( $row, self::assess( $row ) );
		}

		return array(
			'source' => 'search_console',
			'rows'   => $rows,
			'notice' => sprintf(
				/* translators: %s: human readable time difference. */
				__( 'داده‌های واقعی گوگل سرچ کنسول — ۲۸ روز گذشته. آخرین به‌روزرسانی: %s پیش.', 'etehadyar-core' ),
				human_time_diff( (int) get_option( Search_Console::OPTION_CONNECTED, time() ) )
			),
			'error'  => '',
		);
	}

	/**
	 * Derive a risk label from measured metrics.
	 *
	 * These thresholds are heuristics applied to real numbers, not
	 * predictions. The wording deliberately avoids promising a percentage
	 * uplift — the old code claimed "+30% growth with a meta rewrite", which
	 * nothing in the data supported.
	 *
	 * @param array $row Measured row.
	 * @return array
	 */
	protected static function assess( array $row ) {
		$ctr      = (float) ( $row['ctr'] ?? 0 );
		$position = (float) ( $row['position'] ?? 0 );

		if ( $position > 20 ) {
			return array(
				'risk'   => 'high',
				'advice' => __( 'جایگاه پایین‌تر از صفحهٔ دوم؛ محتوا نیاز به تقویت اساسی دارد.', 'etehadyar-core' ),
			);
		}

		// Ranking on page one but rarely clicked usually points at the title
		// and meta description rather than the content itself.
		if ( $position <= 10 && $ctr < 2 ) {
			return array(
				'risk'   => 'high',
				'advice' => __( 'در صفحهٔ اول هست ولی کلیک نمی‌گیرد؛ عنوان و توضیح متا را بازنویسی کنید.', 'etehadyar-core' ),
			);
		}

		if ( $position > 10 ) {
			return array(
				'risk'   => 'medium',
				'advice' => __( 'نزدیک صفحهٔ اول؛ با بهبود محتوا می‌تواند بالا بیاید.', 'etehadyar-core' ),
			);
		}

		return array(
			'risk'   => 'low',
			'advice' => __( 'عملکرد مناسب است.', 'etehadyar-core' ),
		);
	}

	/**
	 * A locally verifiable content audit, used when no traffic data exists.
	 *
	 * Every field here is a fact about the site's own database — nothing is
	 * estimated or presented as a traffic metric.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function content_audit( $limit = 6 ) {
		$posts = get_posts(
			array(
				'posts_per_page' => max( 1, (int) $limit ),
				'post_status'    => 'publish',
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);

		$out = array();

		foreach ( $posts as $post ) {
			$words   = self::word_count( wp_strip_all_tags( (string) $post->post_content ) );
			$age     = (int) floor( ( time() - get_post_timestamp( $post ) ) / DAY_IN_SECONDS );
			$issues  = array();

			if ( $words < 300 ) {
				$issues[] = __( 'متن کوتاه است', 'etehadyar-core' );
			}

			if ( '' === trim( (string) $post->post_excerpt ) ) {
				$issues[] = __( 'خلاصه ندارد', 'etehadyar-core' );
			}

			if ( $age > 365 ) {
				$issues[] = __( 'بیش از یک سال به‌روز نشده', 'etehadyar-core' );
			}

			$out[] = array(
				'url'        => get_permalink( $post ),
				'title'      => get_the_title( $post ),
				'words'      => $words,
				'age_days'   => $age,
				'issues'     => $issues,
				'issue_text' => $issues ? implode( '، ', $issues ) : __( 'مشکل آشکاری ندارد', 'etehadyar-core' ),
			);
		}

		return $out;
	}

	/**
	 * Word counter that handles Persian text.
	 *
	 * Mirrors Estimator::word_count() — `str_word_count()` returns zero for
	 * Persian, which is the bug fixed back in phase four.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	protected static function word_count( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

		if ( '' === $text ) {
			return 0;
		}

		return count( explode( ' ', $text ) );
	}
}
