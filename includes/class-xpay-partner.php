<?php
/**
 * Partner (agency) attribution.
 *
 * Agencies that deploy this plugin across many client stores can attach a
 * partner referral code so each connected store is credited to them. The code
 * is read from a wp-config constant (XPAY_WC_PARTNER_CODE — the bulk-deploy
 * path) or a settings field, threaded into the connect handoff, and sent as a
 * header on every authenticated backend call.
 *
 * The "signals" below are store-level aggregates (counts and gateway ids only)
 * the backend uses for anti-fraud qualification and bounty tiering. No product,
 * customer or order content ever leaves the site.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Partner {

	const OPTION = 'xpay_wc_partner_code';

	/**
	 * Resolve the active partner code. A wp-config constant wins over the
	 * stored option so an agency can bake one code into a provisioning template
	 * and have every client install self-attribute with zero merchant action.
	 * Returns '' when no partner is set.
	 */
	public static function code() {
		if ( self::is_locked() ) {
			return self::sanitize( (string) XPAY_WC_PARTNER_CODE );
		}
		return self::sanitize( (string) get_option( self::OPTION, '' ) );
	}

	/** True when the code is pinned by a constant (the settings field is then read-only). */
	public static function is_locked() {
		return defined( 'XPAY_WC_PARTNER_CODE' ) && XPAY_WC_PARTNER_CODE;
	}

	public static function save_code( $code ) {
		update_option( self::OPTION, self::sanitize( (string) $code ) );
	}

	/**
	 * Conservative code charset: letters, digits, dash, underscore, dot,
	 * trimmed to 64 chars. Keeps the value safe to drop into a URL query param
	 * and an HTTP header without further encoding.
	 */
	public static function sanitize( $code ) {
		$code = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $code );
		return substr( (string) $code, 0, 64 );
	}

	/**
	 * Store-level aggregate signals for backend anti-fraud + tiering. Counts
	 * only — never any product, customer or order content. Computed lazily at
	 * connect time so they reflect the real store, not a sandbox snapshot.
	 *
	 * @return array{sku_count:int, order_count:int, live_gateways:string[]}
	 */
	public static function signals() {
		return array(
			'sku_count'     => self::sku_count(),
			'order_count'   => self::order_count(),
			'live_gateways' => self::live_gateways(),
		);
	}

	private static function sku_count() {
		$counts = wp_count_posts( 'product' );
		return ( $counts && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
	}

	private static function order_count() {
		// Completed + processing = demand that actually moved money through the
		// store. The anti-fraud gate cares that real orders exist, not drafts.
		if ( function_exists( 'wc_orders_count' ) ) {
			return (int) wc_orders_count( 'completed' ) + (int) wc_orders_count( 'processing' );
		}
		return 0;
	}

	/**
	 * IDs of enabled gateways that appear to be in LIVE (not test/sandbox)
	 * mode. A live card gateway is the single strongest "this is a real store"
	 * signal — dummy stores never wire up live processing. We report the raw
	 * gateway ids and leave the "which ids count as real" policy to the backend.
	 *
	 * @return string[]
	 */
	private static function live_gateways() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
			return array();
		}
		$mgr = WC()->payment_gateways();
		if ( ! $mgr ) {
			return array();
		}
		$gateways = $mgr->get_available_payment_gateways();
		if ( ! is_array( $gateways ) ) {
			return array();
		}
		$live = array();
		foreach ( $gateways as $gw_id => $gw ) {
			if ( self::gateway_is_live( $gw ) ) {
				$live[] = (string) $gw_id;
			}
		}
		return $live;
	}

	/**
	 * Best-effort live-vs-test detection. Most gateways expose a `testmode` (or
	 * `sandbox`) setting of 'yes'/'no'; when present and 'yes' the gateway is
	 * NOT live. Gateways with no test concept (bank transfer, COD) have no
	 * sandbox, so we report them — the backend decides whether they qualify.
	 */
	private static function gateway_is_live( $gw ) {
		if ( ! is_object( $gw ) || ! method_exists( $gw, 'get_option' ) ) {
			return false;
		}
		if ( 'yes' === $gw->get_option( 'testmode' ) ) {
			return false;
		}
		if ( 'yes' === $gw->get_option( 'sandbox' ) ) {
			return false;
		}
		return true;
	}
}
