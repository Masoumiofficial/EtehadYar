<?php
/**
 * Encrypted storage for third-party credentials.
 *
 * SMS panel passwords and API keys are stored encrypted rather than as plain
 * options, so a database dump or a leaky backup does not immediately hand an
 * attacker the ability to burn the site's SMS credit.
 *
 * The encryption key is derived from the site's own WordPress salts. That
 * means secrets are tied to the installation: restoring a database onto a
 * different site with different salts will simply fail to decrypt rather
 * than silently exposing the values.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Secret vault.
 */
class Secrets {

	const OPTION_PREFIX = 'etehadyar_secret_';
	const CIPHER        = 'aes-256-gcm';

	/**
	 * Derive the encryption key from WordPress salts.
	 *
	 * @return string 32 raw bytes.
	 */
	protected static function key() {
		$material = '';

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= constant( $constant );
			}
		}

		if ( '' === $material ) {
			// A site with no salts defined is misconfigured, but we still must
			// not fall back to a constant that is identical across every
			// installation.
			$material = get_option( 'siteurl', 'etehadyar' ) . ABSPATH;
		}

		return hash( 'sha256', 'etehadyar-secrets|' . $material, true );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plain Plaintext.
	 * @return string Base64 payload, or empty string.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$iv  = openssl_random_pseudo_bytes( 12 );
		$tag = '';

		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher ) {
			return '';
		}

		return base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt a value.
	 *
	 * @param string $payload Base64 payload.
	 * @return string Plaintext, or empty string on failure.
	 */
	public static function decrypt( $payload ) {
		if ( ! $payload || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $payload, true );

		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );

		$plain = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Persist a secret.
	 *
	 * @param string $name  Secret name.
	 * @param string $value Plaintext value.
	 * @return bool
	 */
	public static function set( $name, $value ) {
		$name = sanitize_key( $name );

		if ( ! $name ) {
			return false;
		}

		if ( '' === (string) $value ) {
			return delete_option( self::OPTION_PREFIX . $name );
		}

		return update_option( self::OPTION_PREFIX . $name, self::encrypt( $value ), false );
	}

	/**
	 * Read a secret.
	 *
	 * @param string $name Secret name.
	 * @return string
	 */
	public static function get( $name ) {
		$name = sanitize_key( $name );

		if ( ! $name ) {
			return '';
		}

		return self::decrypt( get_option( self::OPTION_PREFIX . $name, '' ) );
	}

	/**
	 * Whether a secret is present.
	 *
	 * @param string $name Secret name.
	 * @return bool
	 */
	public static function has( $name ) {
		return '' !== self::get( $name );
	}

	/**
	 * Remove a secret.
	 *
	 * @param string $name Secret name.
	 * @return bool
	 */
	public static function delete( $name ) {
		return delete_option( self::OPTION_PREFIX . sanitize_key( $name ) );
	}
}
