<?php
/**
 * AiCrawlers Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.2
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Closed whitelist of known AI-crawler User-Agent signatures (docs/ANALYTICS-PLAN.md, F6
 * "Rastreo por IA"). Deliberately separate from `Admin\Settings::bot_signatures()`: that
 * list only decides whether to exclude a request from the *human* view count; this one
 * identifies *which* AI crawler made the request, for a dedicated, opt-in count that must
 * never mix with human traffic (`Frontend\AiCrawlerTracker`).
 */
class AiCrawlers {

	/**
	 * Canonical bot name => User-Agent substring to match (case-insensitive).
	 * Same crawlers named in docs/ANALYTICS-PLAN.md §F6: these crawlers train or power AI
	 * assistants and never execute JavaScript, so `Api\RestApi` (fired client-side) never
	 * sees them — only a server-side check on `template_redirect` can.
	 */
	const SIGNATURES = array(
		'GPTBot',
		'ClaudeBot',
		'Claude-User',
		'PerplexityBot',
		'CCBot',
		'Google-Extended',
		'Bytespider',
		'Amazonbot',
		'Applebot-Extended',
	);

	/**
	 * Identifies the AI crawler behind a User-Agent, if any.
	 *
	 * @param string $user_agent Raw User-Agent header value.
	 * @return string|null Canonical bot name from self::SIGNATURES, or null if none match.
	 */
	public static function detect( string $user_agent ): ?string {
		if ( '' === $user_agent ) {
			return null;
		}

		foreach ( self::SIGNATURES as $signature ) {
			if ( false !== stripos( $user_agent, $signature ) ) {
				return $signature;
			}
		}

		return null;
	}
}
