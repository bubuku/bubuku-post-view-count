<?php
/**
 * AiCrawlerTracker Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.2
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Frontend;

use Bubuku\Plugins\PostViewCount\Admin\Settings;
use Bubuku\Plugins\PostViewCount\Core\AiCrawlers;
use Bubuku\Plugins\PostViewCount\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side AI-crawler counter (docs/ANALYTICS-PLAN.md, F6 "Rastreo por IA"). These
 * crawlers don't execute JavaScript, so `Api\RestApi` (fired by assets/js/common.js after
 * page load) never sees them — this class inspects the User-Agent directly on
 * `template_redirect` instead. Opt-in and disabled by default
 * (`Admin\Settings::ai_crawler_tracking()`): it adds a write on every matching crawler
 * request, which is not negligible on a site with heavy crawler traffic.
 */
class AiCrawlerTracker {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_record' ) );
	}

	/**
	 * @return void
	 */
	public function maybe_record() {
		if ( ! Settings::ai_crawler_tracking() ) {
			return;
		}

		if ( ! is_singular( Settings::enabled_post_types() ) ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$bot        = AiCrawlers::detect( $user_agent );

		if ( null === $bot ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( $post_id <= 0 ) {
			return;
		}

		( new Db() )->record_ai_crawl( $post_id, $bot );
	}
}
