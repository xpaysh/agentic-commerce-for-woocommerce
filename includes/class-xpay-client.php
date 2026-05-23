<?php
/**
 * Thin HTTP client to api.xpay.sh. Adds the api key, sets timeouts, returns
 * decoded JSON or a WP_Error.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Client {

	public static function post( $path, $body = array(), $timeout = 8 ) {
		return self::request( 'POST', $path, $body, $timeout );
	}

	public static function patch( $path, $body = array(), $timeout = 8 ) {
		return self::request( 'PATCH', $path, $body, $timeout );
	}

	public static function get( $path, $query = array(), $timeout = 6 ) {
		if ( ! empty( $query ) ) {
			$path .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . http_build_query( $query );
		}
		return self::request( 'GET', $path, null, $timeout );
	}

	private static function request( $method, $path, $body, $timeout ) {
		$url  = trailingslashit( XPAY_WC_API_BASE ) . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'User-Agent'   => 'agentic-commerce-for-woocommerce/' . XPAY_WC_VERSION . '; ' . home_url( '/' ),
				'X-Xpay-Site'  => home_url( '/' ),
			),
		);

		$api_key = Xpay_Plugin::api_key();
		if ( $api_key ) {
			$args['headers']['Authorization'] = 'Bearer ' . $api_key;
		}

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = $raw ? json_decode( $raw, true ) : null;

		if ( $code >= 200 && $code < 300 ) {
			return $json;
		}

		return new WP_Error(
			'xpay_http_' . $code,
			isset( $json['error'] ) ? $json['error'] : 'xpay backend error',
			array(
				'status' => $code,
				'body'   => $json,
			)
		);
	}

	/**
	 * Verify a JWT signed by the xpay backend using the merchant's shared api_key.
	 * Returns the decoded payload array, or false on failure.
	 *
	 * Uses HS256. We intentionally avoid pulling in a JWT library.
	 */
	public static function verify_jwt( $jwt ) {
		$raw_key = Xpay_Plugin::api_key();
		if ( ! $raw_key || ! is_string( $jwt ) ) {
			return false;
		}
		// Backend signs with sha256(api_key); plugin derives the same secret here.
		// Documented in backend/wc-plugin-setup/README.md → v0.1 limitations.
		$secret = hash( 'sha256', $raw_key );
		$parts  = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		list( $h64, $p64, $s64 ) = $parts;

		$header = json_decode( self::b64url_decode( $h64 ), true );
		if ( ! is_array( $header ) || ( $header['alg'] ?? '' ) !== 'HS256' ) {
			return false;
		}

		$expected_sig = hash_hmac( 'sha256', $h64 . '.' . $p64, $secret, true );
		$got_sig      = self::b64url_decode( $s64 );
		if ( ! hash_equals( $expected_sig, $got_sig ) ) {
			return false;
		}

		$payload = json_decode( self::b64url_decode( $p64 ), true );
		if ( ! is_array( $payload ) ) {
			return false;
		}
		if ( isset( $payload['exp'] ) && time() >= (int) $payload['exp'] ) {
			return false;
		}
		if ( isset( $payload['merchant'] ) && Xpay_Plugin::merchant_slug() !== $payload['merchant'] ) {
			return false;
		}
		return $payload;
	}

	private static function b64url_decode( $s ) {
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		// JWT payload decode — base64 is the protocol, not obfuscation. phpcs false positive.
		return base64_decode( strtr( $s, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
