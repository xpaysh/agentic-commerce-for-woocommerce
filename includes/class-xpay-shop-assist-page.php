<?php
/**
 * AI Shopper page — a full-page, human-facing shopping assistant published as a
 * real WordPress Page on the merchant's own domain (e.g. /ai-shopper).
 *
 * Why a real Page (not a virtual rewrite route): it lands in the site's nav +
 * sitemap, is indexable as the merchant's own URL, and lets us render a
 * server-side "Powered by xpay" backlink that passes real link equity (iframe
 * content passes none). The page itself is a theme-free full-bleed template
 * that frames the existing fullscreen storefront embed for speed + isolation.
 *
 * Source of truth for enable + slug is the dashboard widget-config
 * (shopAssistEnabled / shopAssistSlug), fetched via the shared public endpoint.
 * The page is reconciled idempotently: create/publish when entitled + enabled,
 * draft (never hard-delete) otherwise. Reconcile is throttled (hourly) on admin
 * loads + a WP-cron tick + a manual "Sync now" admin action, so dashboard
 * changes propagate without a settings save on the WP side.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Shop_Assist_Page {

	const EMBED_BASE      = 'https://widget.xpay.sh/embed/storefront/fullscreen';
	const POWERED_BY_URL  = 'https://www.xpay.sh/agentic-commerce/';
	const PAGE_ID_OPTION  = 'xpay_wc_shop_assist_page_id';
	const TEMPLATE_META   = '_xpay_shop_assist'; // post meta flag marking our page
	const WRITTEN_META    = '_xpay_shop_assist_written'; // the slug/title WE last wrote
	const RECONCILE_HOOK  = 'xpay_shop_assist_reconcile';
	const DEFAULT_SLUG    = 'ai-shopper';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Render our full-bleed template for the shopper page.
		add_filter( 'template_include', array( $this, 'maybe_use_template' ), 99 );
		// Keep canonical/SEO sane — it's a normal indexable page; nothing special.

		// Reconcile the page against dashboard config: cheap, admin-only,
		// throttled to once/hour so we never touch posts on every request.
		add_action( 'admin_init', array( $this, 'maybe_reconcile' ) );
		add_action( self::RECONCILE_HOOK, array( $this, 'reconcile' ) );
		add_action( 'init', array( $this, 'ensure_cron' ) );

		// Manual "Sync now" from the settings screen.
		add_action( 'admin_post_xpay_shop_assist_sync', array( $this, 'handle_manual_sync' ) );
		// One-click enable everything (storefront widget + shopper page) — no wp-config.
		add_action( 'admin_post_xpay_ai_enable', array( $this, 'handle_enable' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		// Re-evaluate immediately when the local entitlement toggle flips.
		//
		// ⛔ NOT `array( $this, 'reconcile' )` directly. WP fires this hook as
		// do_action( "update_option_{$option}", $old_value, $value, $option ), and a
		// callback registered with the default accepted_args=1 receives $old_value —
		// which would silently bind reconcile()'s $allow_unpublish to an option's
		// PREVIOUS value. That was harmless while reconcile() took no arguments; now
		// that its first parameter decides whether a merchant's live page may be
		// retracted, the intent has to be stated, not inherited from a hook signature.
		add_action(
			'update_option_xpay_wc_storefront_widget_enabled',
			function () {
				$this->reconcile( true );
			}
		);
	}

	public function ensure_cron() {
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::RECONCILE_HOOK );
		}
	}

	public static function clear_cron() {
		$ts = wp_next_scheduled( self::RECONCILE_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::RECONCILE_HOOK );
		}
	}

	/** Admin-load reconcile, throttled to once per hour via a transient. */
	public function maybe_reconcile() {
		if ( get_transient( 'xpay_wc_shop_assist_reconciled' ) ) {
			return;
		}
		set_transient( 'xpay_wc_shop_assist_reconciled', 1, HOUR_IN_SECONDS );
		$this->reconcile();
	}

	/**
	 * One-click enable from the settings screen: flips both local toggles
	 * (storefront widget + shopper page), busts the cached entitlement/config so
	 * the change shows immediately, and publishes the shopper page — no wp-config
	 * edits required.
	 */
	public function handle_enable() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_ai_enable' );
		update_option( 'xpay_wc_storefront_widget_enabled', 1 ); // local entitlement fallback
		update_option( 'xpay_wc_shop_assist_enabled', 1 );       // local shopper-page enable
		delete_transient( 'xpay_wc_storefront_entitlement' );    // re-fetch fresh (grant = yes)
		delete_transient( 'xpay_wc_widget_config' );
		$this->reconcile();
		wp_safe_redirect( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce&xpay_shop_assist=enabled' ) );
		exit;
	}

	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_shop_assist_sync' );
		delete_transient( 'xpay_wc_widget_config' ); // force a fresh config pull
		$this->reconcile();
		wp_safe_redirect( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce&xpay_shop_assist=synced' ) );
		exit;
	}

	/**
	 * Idempotently make the published shopper page match dashboard config.
	 *  - Not connected / not entitled / disabled → draft any existing page.
	 *  - Enabled → ensure a published Page exists at the configured slug, with
	 *    our template flag set.
	 *
	 * ⛔ NEVER RETRACT ON AMBIGUITY. Every "should this page exist?" input here can
	 * fail CLOSED — the entitlement fetch can time out, and widget_config() returns
	 * an empty array on a network error that is indistinguishable from a genuinely
	 * empty config. If we let either read as "disabled", a momentary backend blip
	 * drafts a merchant's live, indexed shopper page and breaks their own link.
	 * So: unpublish only when the backend AFFIRMATIVELY says no.
	 *
	 * @param bool $allow_unpublish Whether this caller may retract the page. FALSE
	 *        from the backend push, which exists to REPAIR a missing page — a push
	 *        must never be able to take one down. Retraction is left to the cron /
	 *        admin path, which re-derives state from a warm cache.
	 * @return bool Whether anything actually changed. Callers use this to decide
	 *        whether a cache purge is warranted — a no-op reconcile must not flush
	 *        a merchant's entire page cache.
	 */
	public function reconcile( $allow_unpublish = true ) {
		$slug = Xpay_Plugin::merchant_slug();
		$page = $this->existing_page();

		if ( '' === $slug || ! Xpay_Plugin::is_connected() ) {
			return $allow_unpublish ? $this->unpublish( $page ) : false;
		}

		$state = Xpay_Plugin::storefront_entitlement_state();
		if ( 'unknown' === $state ) {
			// We could not reach the entitlement API. That is not a "no".
			return false;
		}
		if ( 'no' === $state ) {
			return $allow_unpublish ? $this->unpublish( $page ) : false;
		}

		$cfg = Xpay_Storefront_Widget::widget_config( $slug );
		// An empty $cfg means the config fetch FAILED (widget_config() returns
		// array() on timeout/5xx/garbage). It does not mean "disabled" — so it is
		// never grounds to retract, only grounds to do nothing.
		$config_known = ! empty( $cfg );
		$enabled      = ( $config_known && ! empty( $cfg['shopAssistEnabled'] ) ) || self::local_enabled();
		if ( ! $enabled ) {
			return ( $allow_unpublish && $config_known ) ? $this->unpublish( $page ) : false;
		}

		$cfg_slug      = isset( $cfg['shopAssistSlug'] ) ? $cfg['shopAssistSlug'] : '';
		if ( '' === $cfg_slug ) {
			$cfg_slug = (string) get_option( 'xpay_wc_shop_assist_slug', '' );
		}
		$desired_slug  = $this->sanitize_slug( $cfg_slug );
		$desired_title = ! empty( $cfg['displayName'] ) ? wp_strip_all_tags( $cfg['displayName'] ) : __( 'AI Shopping Assistant', 'agentic-commerce-for-woocommerce' );

		if ( $page ) {
			// Reconcile status + slug + title drift only (cheap no-op when matched).
			//
			// ⛔ But the page is the MERCHANT'S, not ours. If they retitled it
			// ("Shop with AI") or moved its slug, blindly restoring the dashboard
			// value would silently revert their edit on every reconcile — breaking
			// their menu item, their inbound links and any indexed URL, with no
			// redirect left behind. So we compare against what WE last wrote: if
			// the current value differs from that, a human changed it, and it is
			// not ours to change back.
			$ours       = get_post_meta( $page->ID, self::WRITTEN_META, true );
			$ours       = is_array( $ours ) ? $ours : array();
			$our_slug   = isset( $ours['slug'] ) ? (string) $ours['slug'] : '';
			$our_title  = isset( $ours['title'] ) ? (string) $ours['title'] : '';
			$they_moved = '' !== $our_slug && $page->post_name !== $our_slug;
			$they_named = '' !== $our_title && $page->post_title !== $our_title;

			$patch = array( 'ID' => $page->ID );
			$dirty = false;
			if ( 'publish' !== $page->post_status ) {
				$patch['post_status'] = 'publish';
				$dirty                = true;
			}
			if ( ! $they_moved && $page->post_name !== $desired_slug ) {
				$patch['post_name'] = $desired_slug;
				$dirty              = true;
			}
			if ( ! $they_named && $page->post_title !== $desired_title ) {
				$patch['post_title'] = $desired_title;
				$dirty               = true;
			}
			if ( ! $dirty ) {
				// ⛔ Seed the baseline even when there is nothing to patch.
				//
				// Otherwise the "don't revert the merchant's rename" guard never arms
				// on an existing install: 0.6.0 reverted drift hourly, so EVERY store
				// upgrading to 0.6.1 already matches the dashboard values and lands
				// here with $dirty === false. WRITTEN_META would never be written, so
				// the merchant's FIRST rename after upgrading would still be reverted
				// (only the second would stick). Record what's on the page now — it is,
				// by definition, what we last wrote.
				if ( ! $ours ) {
					update_post_meta(
						$page->ID,
						self::WRITTEN_META,
						array(
							'slug'  => $page->post_name,
							'title' => $page->post_title,
						)
					);
				}
				return false;
			}
			wp_update_post( $patch );
			$this->remember_written( $page->ID, $patch, $page );
			return true;
		}

		// Create fresh.
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $desired_title,
				'post_name'      => $desired_slug,
				'post_content'   => '', // template owns all rendering
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'meta_input'     => array( self::TEMPLATE_META => 1 ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return false;
		}
		update_option( self::PAGE_ID_OPTION, (int) $id, false );
		update_post_meta(
			(int) $id,
			self::WRITTEN_META,
			array(
				'slug'  => $desired_slug,
				'title' => $desired_title,
			)
		);
		return true;
	}

	/** @return bool Whether the page actually changed. */
	private function unpublish( $page ) {
		if ( $page && 'draft' !== $page->post_status ) {
			wp_update_post( array( 'ID' => $page->ID, 'post_status' => 'draft' ) );
			return true;
		}
		return false;
	}

	/**
	 * Record the slug/title we just wrote, so a later reconcile can tell OUR value
	 * from one the merchant has since edited by hand. Only the keys we actually
	 * patched are updated — a title-only patch must not claim we wrote the slug.
	 */
	private function remember_written( $page_id, array $patch, $page ) {
		$prev = get_post_meta( $page_id, self::WRITTEN_META, true );
		$prev = is_array( $prev ) ? $prev : array();
		$next = array(
			'slug'  => isset( $patch['post_name'] ) ? $patch['post_name'] : ( isset( $prev['slug'] ) ? $prev['slug'] : $page->post_name ),
			'title' => isset( $patch['post_title'] ) ? $patch['post_title'] : ( isset( $prev['title'] ) ? $prev['title'] : $page->post_title ),
		);
		update_post_meta( $page_id, self::WRITTEN_META, $next );
	}

	/** The tracked page object (any status), or null. */
	private function existing_page() {
		$id = (int) get_option( self::PAGE_ID_OPTION, 0 );
		if ( ! $id ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			// Stale pointer (merchant deleted it) — forget it so a re-enable recreates.
			delete_option( self::PAGE_ID_OPTION );
			return null;
		}
		return $post;
	}

	/**
	 * Local enable fallback: the XPAY_WC_SHOP_ASSIST wp-config constant (dev /
	 * staging override) or the xpay_wc_shop_assist_enabled option. Used when the
	 * dashboard hasn't (or can't) set shopAssistEnabled.
	 */
	private static function local_enabled() {
		if ( defined( 'XPAY_WC_SHOP_ASSIST' ) ) {
			return (bool) XPAY_WC_SHOP_ASSIST;
		}
		return (bool) get_option( 'xpay_wc_shop_assist_enabled', 0 );
	}

	private function sanitize_slug( $raw ) {
		$slug = sanitize_title( (string) $raw );
		return '' !== $slug ? $slug : self::DEFAULT_SLUG;
	}

	/** Swap in our full-bleed template when the tracked page is being viewed. */
	public function maybe_use_template( $template ) {
		if ( is_admin() || ! is_page() ) {
			return $template;
		}
		$id = (int) get_option( self::PAGE_ID_OPTION, 0 );
		if ( ! $id || get_queried_object_id() !== $id ) {
			return $template;
		}
		$ours = XPAY_WC_PATH . 'templates/xpay-shop-assist.php';
		return file_exists( $ours ) ? $ours : $template;
	}

	/**
	 * Settings-screen notice: show the live shopper-page URL (or that it's not
	 * created yet) + a "Sync now" button that force-refreshes from the dashboard.
	 */
	public function admin_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'agentic-commerce-for-woocommerce' ) ) {
			return;
		}
		// Gate on connection only (NOT entitlement) so the one-click Enable button
		// always shows — it busts the entitlement cache itself.
		if ( ! Xpay_Plugin::is_connected() ) {
			return;
		}

		$flag = isset( $_GET['xpay_shop_assist'] ) ? sanitize_key( wp_unslash( $_GET['xpay_shop_assist'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'synced' === $flag || 'enabled' === $flag ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'AI Assistant updated. Clear your page cache and reload the storefront.', 'agentic-commerce-for-woocommerce' );
			echo '</p></div>';
		}

		$page = $this->existing_page();
		$live = $page && 'publish' === $page->post_status;

		echo '<div class="notice notice-info"><p><strong>';
		esc_html_e( 'AI Shopping Assistant', 'agentic-commerce-for-woocommerce' );
		echo '</strong> — ';

		if ( $live ) {
			$url = get_permalink( $page->ID );
			printf(
				/* translators: %s: page URL. */
				wp_kses( __( 'storefront widget + full-page shopper live at <a href="%1$s" target="_blank" rel="noopener">%1$s</a>. Add it to your menu.', 'agentic-commerce-for-woocommerce' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
				esc_url( $url )
			);
			echo ' <a class="button button-secondary" style="margin-left:8px" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xpay_shop_assist_sync' ), 'xpay_shop_assist_sync' ) ) . '">';
			esc_html_e( 'Re-sync', 'agentic-commerce-for-woocommerce' );
			echo '</a>';
		} else {
			esc_html_e( 'turn on the on-store chat widget and the full-page shopper in one click.', 'agentic-commerce-for-woocommerce' );
			echo ' <a class="button button-primary" style="margin-left:8px" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xpay_ai_enable' ), 'xpay_ai_enable' ) ) . '">';
			esc_html_e( 'Enable AI Assistant', 'agentic-commerce-for-woocommerce' );
			echo '</a>';
		}
		echo '</p></div>';
	}

	/** Absolute embed URL the template iframes. Static so the template can call it. */
	public static function embed_url() {
		$slug = Xpay_Plugin::merchant_slug();
		return add_query_arg(
			array(
				'slug'    => rawurlencode( $slug ),
				'track'   => 'merchant',
				'surface' => 'page',
			),
			self::EMBED_BASE
		);
	}
}
