<?php
/**
 * Builds the semantic index.
 *
 * @package Etehadyar\Brain
 */

namespace Etehadyar\Brain;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Re-indexes site content with real embeddings.
 *
 * Chunks are embedded in batches rather than one request per chunk: a site
 * with 500 posts produces several thousand chunks, and one HTTP round-trip
 * each would take hours and cost far more.
 */
class Indexer {

	/**
	 * Option holding the model used to build the current index.
	 */
	const OPTION_INDEX_MODEL = 'etehadyar_index_model';

	/**
	 * Option holding the dimension count of the current index.
	 */
	const OPTION_INDEX_DIMS = 'etehadyar_index_dims';

	/**
	 * Characters per chunk.
	 */
	const CHUNK_SIZE = 900;

	/**
	 * Overlap between neighbouring chunks, in characters.
	 *
	 * Hard-splitting every 900 characters cuts sentences in half, and a fact
	 * spanning the boundary becomes unfindable in either piece. A small
	 * overlap keeps boundary-spanning statements retrievable.
	 */
	const CHUNK_OVERLAP = 120;

	/**
	 * Split text into overlapping chunks.
	 *
	 * @param string $text Source text.
	 * @param int    $size Chunk size.
	 * @return array
	 */
	public static function chunk( $text, $size = self::CHUNK_SIZE ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );

		if ( '' === $text ) {
			return array();
		}

		$size = max( 100, (int) $size );
		$step = max( 1, $size - self::CHUNK_OVERLAP );
		$len  = mb_strlen( $text );

		if ( $len <= $size ) {
			return array( $text );
		}

		$chunks = array();
		$seen   = array();

		for ( $offset = 0; $offset < $len; $offset += $step ) {
			$piece = trim( mb_substr( $text, $offset, $size ) );

			// Highly repetitive text (navigation lists, tables of repeated
			// terms) yields byte-identical windows. Each duplicate would cost
			// another API call and another stored vector while adding no
			// retrievable information, so keep only the first.
			if ( '' !== $piece && ! isset( $seen[ $piece ] ) ) {
				$seen[ $piece ] = true;
				$chunks[]       = $piece;
			}

			// Without this the final overlapping window would repeat the tail
			// of the document as its own chunk.
			if ( $offset + $size >= $len ) {
				break;
			}
		}

		return $chunks;
	}

	/**
	 * Has the embedding model changed since the index was built?
	 *
	 * Vectors from different models are not comparable, so a mixed index
	 * returns nonsense. This also catches an index still holding the legacy
	 * md5 hash vectors.
	 *
	 * @return bool
	 */
	public static function index_is_stale() {
		$model = (string) get_option( self::OPTION_INDEX_MODEL, '' );
		$dims  = (int) get_option( self::OPTION_INDEX_DIMS, 0 );

		if ( '' === $model ) {
			// An index exists but was never stamped: it predates this code,
			// which means it holds hash vectors.
			return self::indexed_count() > 0;
		}

		return $model !== Embeddings::model() || $dims !== Embeddings::dimensions();
	}

	/**
	 * Count rows currently holding an embedding.
	 *
	 * @return int
	 */
	public static function indexed_count() {
		global $wpdb;

		$table = Schema::table( 'vectors' );

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE embedding IS NOT NULL AND embedding <> ''" );
	}

	/**
	 * Discard every stored vector.
	 *
	 * The text is kept so keyword search still works while re-indexing.
	 *
	 * @return int Rows cleared.
	 */
	public static function purge_vectors() {
		global $wpdb;

		$table = Schema::table( 'vectors' );

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$affected = (int) $wpdb->query( "UPDATE `{$table}` SET embedding = NULL WHERE embedding IS NOT NULL" );

		delete_option( self::OPTION_INDEX_MODEL );
		delete_option( self::OPTION_INDEX_DIMS );

		Audit::log(
			'brain.index_purged',
			array(
				'severity' => 'info',
				'context'  => array( 'rows' => $affected ),
			)
		);

		return $affected;
	}

	/**
	 * Embed a batch of stored chunks that have no vector yet.
	 *
	 * @param int $limit How many chunks to process.
	 * @return array|WP_Error Progress report.
	 */
	public static function embed_pending( $limit = 20 ) {
		global $wpdb;

		if ( ! Embeddings::is_available() ) {
			return new WP_Error(
				'etehadyar_embedding_unavailable',
				__( 'برای ساخت ایندکس معنایی، ابتدا کلید API را تنظیم کنید.', 'etehadyar-core' )
			);
		}

		$table = Schema::table( 'vectors' );

		if ( ! Schema::table_exists( $table ) ) {
			return new WP_Error( 'etehadyar_no_table', __( 'جدول بردارها وجود ندارد.', 'etehadyar-core' ) );
		}

		$limit = max( 1, min( Embeddings::MAX_BATCH, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, content FROM `{$table}` WHERE embedding IS NULL OR embedding = '' LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			// Stamp the index as complete so staleness checks work.
			update_option( self::OPTION_INDEX_MODEL, Embeddings::model(), false );
			update_option( self::OPTION_INDEX_DIMS, Embeddings::dimensions(), false );

			return array(
				'processed' => 0,
				'remaining' => 0,
				'done'      => true,
			);
		}

		$texts = array();

		foreach ( $rows as $row ) {
			$texts[] = (string) $row['content'];
		}

		$vectors = Embeddings::embed_batch( $texts );

		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		if ( count( $vectors ) !== count( $rows ) ) {
			// Storing a partial batch would pair vectors with the wrong rows.
			return new WP_Error(
				'etehadyar_embedding_count_mismatch',
				__( 'تعداد بردارهای بازگشتی با تعداد متن‌ها همخوانی ندارد.', 'etehadyar-core' )
			);
		}

		$processed = 0;

		foreach ( $rows as $i => $row ) {
			$wpdb->update(
				$table,
				array( 'embedding' => wp_json_encode( $vectors[ $i ] ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);

			$processed++;
		}

		update_option( self::OPTION_INDEX_MODEL, Embeddings::model(), false );
		update_option( self::OPTION_INDEX_DIMS, Embeddings::dimensions(), false );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE embedding IS NULL OR embedding = ''" );

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'done'      => 0 === $remaining,
		);
	}
}
