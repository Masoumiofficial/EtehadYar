<?php
/**
 * Semantic search over the indexed site content.
 *
 * @package Etehadyar\Brain
 */

namespace Etehadyar\Brain;

use Etehadyar\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Searches the vector table, and is explicit about how it did it.
 *
 * Every result set carries a `mode`:
 *
 *   'semantic' — real embeddings compared by cosine similarity.
 *   'keyword'  — plain SQL LIKE, because no embeddings were available.
 *
 * The legacy code silently degraded from one to the other. Since its vectors
 * were md5 hashes, the semantic branch essentially never matched anything and
 * the keyword fallback ran almost every time — while the UI called it
 * "semantic memory". Labelling the mode makes that visible instead of
 * hiding it.
 */
class Semantic_Search {

	/**
	 * Similarity floor.
	 *
	 * Cosine over these models rarely goes below zero for real text, so an
	 * unfiltered search always returns something that looks like a match. A
	 * floor keeps genuinely unrelated chunks out of an AI prompt, which is
	 * where they would turn into a confident hallucination.
	 */
	const MIN_SCORE = 0.25;

	/**
	 * How many rows to score in one pass.
	 */
	const SCAN_LIMIT = 2000;

	/**
	 * Run a search.
	 *
	 * @param string $query Search text.
	 * @param int    $top_k Maximum results.
	 * @return array {
	 *     @type string $mode    'semantic' or 'keyword'.
	 *     @type array  $results Scored rows.
	 *     @type string $note    Human explanation.
	 * }
	 */
	public static function search( $query, $top_k = 6 ) {
		$query = trim( (string) $query );
		$top_k = max( 1, (int) $top_k );

		if ( '' === $query ) {
			return array(
				'mode'    => 'keyword',
				'results' => array(),
				'note'    => __( 'عبارت جست‌وجو خالی است.', 'etehadyar-core' ),
			);
		}

		if ( Embeddings::is_available() ) {
			$vector = Embeddings::embed( $query );

			if ( ! is_wp_error( $vector ) ) {
				$hits = self::by_vector( $vector, $top_k );

				// An empty semantic result is a real answer: nothing in the
				// index is related. Falling back to LIKE here would resurrect
				// the old behaviour of always returning *something*.
				return array(
					'mode'    => 'semantic',
					'results' => $hits,
					'note'    => $hits
						? __( 'جست‌وجوی معنایی با بردارهای واقعی.', 'etehadyar-core' )
						: __( 'جست‌وجوی معنایی انجام شد؛ محتوای مرتبطی یافت نشد.', 'etehadyar-core' ),
				);
			}
		}

		return array(
			'mode'    => 'keyword',
			'results' => self::by_keyword( $query, $top_k ),
			'note'    => __( 'جست‌وجوی کلیدواژه‌ای — بردار معنایی در دسترس نیست، بنابراین نتایج فقط بر پایهٔ تطابق متنی است.', 'etehadyar-core' ),
		);
	}

	/**
	 * Score every stored vector against the query vector.
	 *
	 * @param array $vector Query embedding.
	 * @param int   $top_k  Maximum results.
	 * @return array
	 */
	public static function by_vector( array $vector, $top_k ) {
		global $wpdb;

		$table = Schema::table( 'vectors' );

		if ( ! Schema::table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE embedding IS NOT NULL AND embedding <> '' LIMIT %d",
				self::SCAN_LIMIT
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$scored = array();

		foreach ( $rows as $row ) {
			$stored = json_decode( (string) $row['embedding'], true );

			if ( ! is_array( $stored ) || ! $stored ) {
				continue;
			}

			// Skip vectors from a different model or dimension setting rather
			// than scoring them against a truncated copy, which would produce
			// a plausible-looking but meaningless number.
			if ( count( $stored ) !== count( $vector ) ) {
				continue;
			}

			$score = Embeddings::cosine( $vector, $stored );

			if ( $score < self::MIN_SCORE ) {
				continue;
			}

			$scored[] = array(
				'score' => round( $score, 4 ),
				'row'   => $row,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, $top_k );
	}

	/**
	 * Plain text fallback.
	 *
	 * @param string $query Search text.
	 * @param int    $top_k Maximum results.
	 * @return array
	 */
	public static function by_keyword( $query, $top_k ) {
		global $wpdb;

		$table = Schema::table( 'vectors' );

		if ( ! Schema::table_exists( $table ) ) {
			return array();
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE content LIKE %s LIMIT %d",
				$like,
				(int) $top_k
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				// No score is reported for keyword hits. The old code assigned
				// a flat 0.5 and displayed it next to real scores, which made
				// a text match look like a measured similarity.
				'score' => null,
				'row'   => $row,
			);
		}

		return $out;
	}
}
