<?php
/**
 * Real text embeddings.
 *
 * @package Etehadyar\Brain
 */

namespace Etehadyar\Brain;

use Etehadyar\Core\Audit;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Produces genuine semantic vectors from an embeddings API.
 *
 * The legacy implementation derived its "embedding" from md5(), which has two
 * fatal properties measured directly against the original code:
 *
 *   - Adding a single full stop to a sentence drops similarity to -0.17,
 *     identical to the score of a completely unrelated topic. A hash is
 *     designed to destroy locality; two nearly identical inputs produce
 *     unrelated digests. That is the opposite of what an embedding needs.
 *   - The 64-dimension vector was 32 values repeated twice, because the loop
 *     indexed the 32-character digest with `$i % 32`. Half the vector carried
 *     no information at all.
 *
 * The net effect: "semantic search" only ever matched byte-identical text, so
 * the LIKE fallback in EAIW_RAG was doing all the real work.
 *
 * This class calls a real embeddings endpoint. When no API key is configured
 * it returns an error rather than a fake vector — the caller then falls back
 * to keyword search and, crucially, *says so*.
 */
class Embeddings {

	/**
	 * Default model. 1536 dimensions, cheap, and strong on Persian.
	 */
	const DEFAULT_MODEL = 'text-embedding-3-small';

	/**
	 * Dimension count requested from the API.
	 *
	 * 512 rather than the full 1536: the store keeps vectors as JSON in a
	 * MySQL column and scores them in PHP, so every extra dimension costs
	 * storage on every chunk and CPU on every query. These models are trained
	 * with Matryoshka representation learning, so a truncated vector keeps
	 * most of its quality.
	 */
	const DIMENSIONS = 512;

	/**
	 * Option names.
	 */
	const OPTION_MODEL    = 'etehadyar_embedding_model';
	const OPTION_ENDPOINT = 'etehadyar_embedding_endpoint';
	const OPTION_DIMS     = 'etehadyar_embedding_dims';

	/**
	 * Maximum inputs per request.
	 *
	 * The API accepts more, but a larger batch means a bigger payload and a
	 * longer request — on shared hosting with a 30 second PHP limit that is
	 * how an indexing run dies halfway through.
	 */
	const MAX_BATCH = 32;

	/**
	 * Resolve the configured API key.
	 *
	 * Reads the legacy vault so an existing install keeps working without
	 * re-entering credentials.
	 *
	 * @return string
	 */
	public static function api_key() {
		/**
		 * Filters the embeddings API key.
		 *
		 * @param string $key Key.
		 */
		$key = (string) apply_filters( 'etehadyar_embedding_api_key', '' );

		if ( '' !== $key ) {
			return $key;
		}

		if ( class_exists( '\\EAIW_Vault' ) ) {
			foreach ( array( 'openai', 'gapgpt' ) as $provider ) {
				$candidate = (string) \EAIW_Vault::get_key( $provider );

				if ( '' !== $candidate ) {
					return $candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Is real embedding available right now?
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::api_key();
	}

	/**
	 * Configured model name.
	 *
	 * @return string
	 */
	public static function model() {
		$model = (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );

		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Configured dimension count.
	 *
	 * @return int
	 */
	public static function dimensions() {
		$dims = (int) get_option( self::OPTION_DIMS, self::DIMENSIONS );

		return $dims > 0 ? $dims : self::DIMENSIONS;
	}

	/**
	 * API endpoint.
	 *
	 * Configurable because Iranian sites commonly route OpenAI traffic
	 * through a compatible proxy such as GapGPT or AvalAI.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$url = (string) get_option( self::OPTION_ENDPOINT, 'https://api.openai.com/v1/embeddings' );

		return '' !== $url ? $url : 'https://api.openai.com/v1/embeddings';
	}

	/**
	 * Embed one string.
	 *
	 * @param string $text Input text.
	 * @return array|WP_Error Vector of floats.
	 */
	public static function embed( $text ) {
		$result = self::embed_batch( array( $text ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result[0] ) ? $result[0] : new WP_Error(
			'etehadyar_embedding_empty',
			__( 'سرویس embedding پاسخ خالی داد.', 'etehadyar-core' )
		);
	}

	/**
	 * Embed several strings in one request.
	 *
	 * @param array $texts Input strings.
	 * @return array|WP_Error List of vectors, in input order.
	 */
	public static function embed_batch( array $texts ) {
		$key = self::api_key();

		if ( '' === $key ) {
			return new WP_Error(
				'etehadyar_embedding_unavailable',
				__( 'کلید API برای ساخت بردار معنایی تنظیم نشده است.', 'etehadyar-core' )
			);
		}

		$clean = array();

		foreach ( $texts as $text ) {
			$text = trim( (string) $text );

			// The API rejects empty strings outright, and one bad entry would
			// fail the whole batch.
			if ( '' === $text ) {
				continue;
			}

			$clean[] = $text;
		}

		if ( ! $clean ) {
			return new WP_Error(
				'etehadyar_embedding_no_input',
				__( 'متنی برای پردازش وجود ندارد.', 'etehadyar-core' )
			);
		}

		if ( count( $clean ) > self::MAX_BATCH ) {
			return new WP_Error(
				'etehadyar_embedding_batch_too_large',
				sprintf(
					/* translators: %d: maximum batch size. */
					__( 'حداکثر %d متن در هر درخواست مجاز است.', 'etehadyar-core' ),
					self::MAX_BATCH
				)
			);
		}

		$body = array(
			'model' => self::model(),
			'input' => $clean,
		);

		// `dimensions` only exists on text-embedding-3 and later. Sending it
		// to an older model (or some proxies) is an immediate 400.
		if ( 0 === strpos( self::model(), 'text-embedding-3' ) ) {
			$body['dimensions'] = self::dimensions();
		}

		$response = wp_remote_post(
			self::endpoint(),
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'etehadyar_embedding_http', $response->get_error_message() );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $parsed['error']['message'] )
				? (string) $parsed['error']['message']
				: sprintf( 'HTTP %d', $code );

			Audit::log(
				'brain.embedding_failed',
				array(
					'severity' => 'warning',
					'context'  => array( 'status' => $code ),
				)
			);

			return new WP_Error( 'etehadyar_embedding_api', $message );
		}

		if ( ! isset( $parsed['data'] ) || ! is_array( $parsed['data'] ) ) {
			return new WP_Error(
				'etehadyar_embedding_bad_response',
				__( 'ساختار پاسخ سرویس embedding نامعتبر بود.', 'etehadyar-core' )
			);
		}

		// The API documents that `data` may come back out of order, so the
		// index field is authoritative. Zipping blindly would attach the
		// wrong vector to the wrong chunk — a silent, near-undebuggable
		// corruption of the whole index.
		$vectors = array();

		foreach ( $parsed['data'] as $item ) {
			if ( ! isset( $item['embedding'] ) || ! is_array( $item['embedding'] ) ) {
				continue;
			}

			$index             = isset( $item['index'] ) ? (int) $item['index'] : count( $vectors );
			$vectors[ $index ] = array_map( 'floatval', $item['embedding'] );
		}

		ksort( $vectors );

		return array_values( $vectors );
	}

	/**
	 * Cosine similarity between two vectors.
	 *
	 * @param array $a First vector.
	 * @param array $b Second vector.
	 * @return float Between -1 and 1.
	 */
	public static function cosine( array $a, array $b ) {
		// Comparing vectors of different lengths by truncating to the shorter
		// one — as the legacy store did — silently produces a meaningless
		// score when the model or dimension setting changes.
		if ( count( $a ) !== count( $b ) || ! $a ) {
			return 0.0;
		}

		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;

		foreach ( $a as $i => $value ) {
			$value = (float) $value;
			$other = (float) $b[ $i ];

			$dot += $value * $other;
			$na  += $value * $value;
			$nb  += $other * $other;
		}

		if ( $na <= 0.0 || $nb <= 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}
}
