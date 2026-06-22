<?php
/**
 * Full-bleed AI Shopper page template.
 *
 * Theme-free by design: we want a fast, full-viewport surface that frames the
 * fullscreen storefront embed, NOT the theme's header/footer chrome. We still
 * emit wp_head()/wp_footer() so SEO + analytics plugins fire and the URL stays
 * a normal, indexable 200 HTML page.
 *
 * The "Powered by xpay" footer is rendered HERE in PHP — outside the iframe —
 * so the backlink to xpay.sh passes real link equity (iframe content does not).
 *
 * @var string $embed   set below
 */

defined( 'ABSPATH' ) || exit;

$xpay_embed = Xpay_Shop_Assist_Page::embed_url();
$xpay_title = wp_strip_all_tags( get_the_title() );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<?php wp_head(); ?>
	<style>
		html, body { margin: 0; padding: 0; height: 100%; background: #05060c; }
		.xpay-shop-assist-wrap { position: fixed; inset: 0; display: flex; flex-direction: column; }
		.xpay-shop-assist-frame { flex: 1 1 auto; width: 100%; border: 0; display: block; }
		.xpay-shop-assist-footer {
			flex: 0 0 auto;
			text-align: center;
			font: 500 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			padding: 8px 12px;
			color: #8b8fa3;
			background: #05060c;
			border-top: 1px solid rgba(255,255,255,0.06);
		}
		.xpay-shop-assist-footer a { color: #a9b0ff; text-decoration: none; }
		.xpay-shop-assist-footer a:hover { text-decoration: underline; }
	</style>
</head>
<body <?php body_class( 'xpay-shop-assist' ); ?>>
	<div class="xpay-shop-assist-wrap">
		<iframe
			class="xpay-shop-assist-frame"
			src="<?php echo esc_url( $xpay_embed ); ?>"
			title="<?php echo esc_attr( $xpay_title ); ?>"
			allow="clipboard-write"
			referrerpolicy="strict-origin-when-cross-origin"
			loading="eager"
		></iframe>
		<footer class="xpay-shop-assist-footer">
			<?php
			printf(
				/* translators: %s: link to xpay Agentic Commerce. */
				wp_kses( __( 'AI shopping assistant powered by %s', 'agentic-commerce-for-woocommerce' ), array( 'a' => array( 'href' => array(), 'rel' => array(), 'target' => array() ) ) ),
				'<a href="' . esc_url( Xpay_Shop_Assist_Page::POWERED_BY_URL ) . '" rel="noopener">xpay Agentic Commerce</a>'
			);
			?>
		</footer>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
<?php
exit; // We own the full document — never let the theme append anything.
