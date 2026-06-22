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
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		// Re-evaluate immediately when the local entitlement toggle flips.
		add_action( 'update_option_xpay_wc_storefront_widget_enabled', array( $this, 'reconcile' ) );
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
	 */
	public function reconcile() {
		$slug = Xpay_Plugin::merchant_slug();
		$page = $this->existing_page();

		if ( '' === $slug || ! Xpay_Plugin::is_connected() || ! Xpay_Plugin::is_storefront_widget_entitled() ) {
			$this->unpublish( $page );
			return;
		}

		$cfg     = Xpay_Storefront_Widget::widget_config( $slug );
		// Enable via the dashboard config OR a local fallback (constant/option) —
		// mirrors the entitlement resolution so the page is controllable even when
		// the dashboard config is unreachable or not yet saved.
		$enabled = ! empty( $cfg['shopAssistEnabled'] ) || self::local_enabled();
		if ( ! $enabled ) {
			$this->unpublish( $page );
			return;
		}

		$cfg_slug      = isset( $cfg['shopAssistSlug'] ) ? $cfg['shopAssistSlug'] : '';
		if ( '' === $cfg_slug ) {
			$cfg_slug = (string) get_option( 'xpay_wc_shop_assist_slug', '' );
		}
		$desired_slug  = $this->sanitize_slug( $cfg_slug );
		$desired_title = ! empty( $cfg['displayName'] ) ? wp_strip_all_tags( $cfg['displayName'] ) : __( 'AI Shopping Assistant', 'agentic-commerce-for-woocommerce' );

		if ( $page ) {
			// Reconcile status + slug + title drift only (cheap no-op when matched).
			$patch = array( 'ID' => $page->ID );
			$dirty = false;
			if ( 'publish' !== $page->post_status ) {
				$patch['post_status'] = 'publish';
				$dirty                = true;
			}
			if ( $page->post_name !== $desired_slug ) {
				$patch['post_name'] = $desired_slug;
				$dirty              = true;
			}
			if ( $page->post_title !== $desired_title ) {
				$patch['post_title'] = $desired_title;
				$dirty               = true;
			}
			if ( $dirty ) {
				wp_update_post( $patch );
			}
			return;
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
		if ( ! is_wp_error( $id ) && $id ) {
			update_option( self::PAGE_ID_OPTION, (int) $id, false );
		}
	}

	private function unpublish( $page ) {
		if ( $page && 'draft' !== $page->post_status ) {
			wp_update_post( array( 'ID' => $page->ID, 'post_status' => 'draft' ) );
		}
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
		if ( ! Xpay_Plugin::is_storefront_widget_entitled() ) {
			return;
		}

		if ( isset( $_GET['xpay_shop_assist'] ) && 'synced' === $_GET['xpay_shop_assist'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'AI Shopper page synced.', 'agentic-commerce-for-woocommerce' );
			echo '</p></div>';
		}

		$page = $this->existing_page();
		$live = $page && 'publish' === $page->post_status;

		echo '<div class="notice notice-info"><p><strong>';
		esc_html_e( 'AI Shopper page', 'agentic-commerce-for-woocommerce' );
		echo '</strong> — ';
		if ( $live ) {
			$url = get_permalink( $page->ID );
			printf(
				/* translators: %s: page URL. */
				wp_kses( __( 'live at <a href="%1$s" target="_blank" rel="noopener">%1$s</a>. Add it to your menu to send shoppers there.', 'agentic-commerce-for-woocommerce' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
				esc_url( $url )
			);
		} else {
			esc_html_e( 'enable it from your xpay dashboard (Storefront Assistant → full-page shopper), then Sync.', 'agentic-commerce-for-woocommerce' );
		}
		echo ' <a class="button button-secondary" style="margin-left:8px" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xpay_shop_assist_sync' ), 'xpay_shop_assist_sync' ) ) . '">';
		esc_html_e( 'Sync now', 'agentic-commerce-for-woocommerce' );
		echo '</a></p></div>';
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
