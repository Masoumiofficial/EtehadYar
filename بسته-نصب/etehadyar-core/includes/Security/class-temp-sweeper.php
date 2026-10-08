<?php
/**
 * Removes abandoned temporary directories left by the legacy video builder.
 *
 * @package Etehadyar\Security
 */

namespace Etehadyar\Security;

use Etehadyar\Core\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * `EAIW_Video_Studio_Pro::tmp_dir()` creates
 * `wp-content/uploads/eaiw-video-tmp-{time}-{rand}/` for every render and
 * never deletes it. The directories hold extracted frames, downloaded audio
 * and intermediate MP4s, so a busy site accumulates gigabytes of files that
 * are also publicly reachable over HTTP.
 *
 * Rather than patch the legacy renderer mid-flight — where deleting too early
 * would break a job still running — this sweeps directories that are old
 * enough that no plausible render is still using them.
 */
class Temp_Sweeper {

	/**
	 * Directory name prefix written by the legacy builder.
	 */
	const PREFIX = 'eaiw-video-tmp-';

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		add_action( 'etehadyar_daily_maintenance', array( __CLASS__, 'sweep' ) );
	}

	/**
	 * Delete temp directories older than the cutoff.
	 *
	 * @param int $max_age_hours Age threshold in hours.
	 * @return int Directories removed.
	 */
	public static function sweep( $max_age_hours = 6 ) {
		$max_age_hours = max( 1, (int) $max_age_hours );

		$uploads = wp_upload_dir();

		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			return 0;
		}

		$base    = untrailingslashit( $uploads['basedir'] );
		$cutoff  = time() - ( $max_age_hours * HOUR_IN_SECONDS );
		$entries = glob( $base . '/' . self::PREFIX . '*', GLOB_ONLYDIR );

		if ( ! is_array( $entries ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( $entries as $dir ) {
			// Refuse to touch anything that escaped the uploads directory —
			// a symlink or a crafted name must never turn this into an
			// arbitrary delete.
			$real = realpath( $dir );

			if ( ! $real || 0 !== strpos( $real, realpath( $base ) . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			if ( filemtime( $real ) > $cutoff ) {
				continue;
			}

			if ( self::delete_tree( $real ) ) {
				$removed++;
			}
		}

		if ( $removed > 0 ) {
			Audit::log(
				'maintenance.temp_swept',
				array(
					'severity' => 'info',
					'context'  => array( 'removed' => $removed ),
				)
			);
		}

		return $removed;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute path, already validated.
	 * @return bool
	 */
	protected static function delete_tree( $dir ) {
		$items = scandir( $dir );

		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;

			// Never follow a symlink into someone else's filesystem; unlink
			// the link itself instead.
			if ( is_link( $path ) ) {
				wp_delete_file( $path );
				continue;
			}

			if ( is_dir( $path ) ) {
				self::delete_tree( $path );
				continue;
			}

			wp_delete_file( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- no WP API for removing a directory.
		return @rmdir( $dir );
	}
}
