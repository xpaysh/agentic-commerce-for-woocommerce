<?php
/**
 * Ensure shopping-agent crawlers are explicitly allowed.
 *
 * We append explicit Allow blocks for each known agent UA. WP's robots_txt filter
 * runs on the dynamically generated robots.txt; this hook does not override a
 * physical robots.txt file on disk. If the merchant has a physical robots.txt,
 * we surface a warning on the admin page.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Robots {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const AGENT_UAS = array(
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'ClaudeBot',
		'Claude-User',
		'Claude-SearchBot',
		'PerplexityBot',
		'Perplexity-User',
		'Google-Extended',
		'Applebot-Extended',
		'CCBot',
	);

	private function __construct() {
		add_filter( 'robots_txt', array( $this, 'append_agent_allows' ), 20, 2 );
	}

	public function append_agent_allows( $output, $is_public ) {
		if ( ! $is_public ) {
			// Site is set to "Discourage search engines" — don't override that intent.
			return $output;
		}

		$existing = strtolower( $output );

		$blocks = array();
		foreach ( self::AGENT_UAS as $ua ) {
			$needle = 'user-agent: ' . strtolower( $ua );
			if ( false !== strpos( $existing, $needle ) ) {
				// Already mentioned (allow or disallow) — leave it alone so we don't
				// fight a merchant's deliberate config.
				continue;
			}
			$blocks[] = sprintf( "User-agent: %s\nAllow: /\n", $ua );
		}

		if ( empty( $blocks ) ) {
			return $output;
		}

		$header = "\n# xpay — shopping agents allowed\n";
		return rtrim( $output, "\n" ) . "\n" . $header . implode( "\n", $blocks );
	}

	/**
	 * Detect a physical robots.txt that overrides WP's dynamic one.
	 * Called from the settings page to surface a warning.
	 */
	public static function physical_robots_exists() {
		$path = untrailingslashit( ABSPATH ) . '/robots.txt';
		return file_exists( $path );
	}
}
