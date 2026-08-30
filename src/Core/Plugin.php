<?php
/**
 * Plugin Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

use Bubuku\Plugins\PostViewCount\Admin\Admin as AdminController;
use Bubuku\Plugins\PostViewCount\Admin\PostListColumns;
use Bubuku\Plugins\PostViewCount\Api\RestApi;
use Bubuku\Plugins\PostViewCount\Api\SettingsApi;
use Bubuku\Plugins\PostViewCount\Api\TrendsApi;
use Bubuku\Plugins\PostViewCount\Frontend\AiCrawlerTracker;
use Bubuku\Plugins\PostViewCount\Frontend\Assets;
use Bubuku\Plugins\PostViewCount\Frontend\Block;
use Bubuku\Plugins\PostViewCount\Frontend\Shortcode;
use Bubuku\Plugins\PostViewCount\Mcp\SatelliteConnector;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetAiTraffic;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetContentTrends;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetDimsBreakdown;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetPostViews;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetViewsSummary;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\ListMomentum;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\ListMostViewed;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\ListStaleContent;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * @var Schema
	 */
	private $schema;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->schema = new Schema();
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		new Assets();
		new RestApi();
		new TrendsApi();
		new SettingsApi();
		new Shortcode();
		new Block();
		new AiCrawlerTracker();

		if ( is_admin() ) {
			( new AdminController( 'bubuku-post-view-count', BBK_PLUGIN_VERSION ) )->register();
			new PostListColumns();
		}

		add_action( 'init', array( $this, 'init_mcp_satellite' ) );
	}

	/**
	 * Wires this plugin as a satellite of the `bubuku-mcp-conex` hub (docs/ANALYTICS-PLAN.md
	 * §4.2). No-op if the hub isn't active — `SatelliteConnector::init()` detects that on
	 * its own. Deferred to `init` (rather than running directly from init(), above, which
	 * is itself hooked to `plugins_loaded`): the config below calls `__()` eagerly, and
	 * WordPress only allows text domain loading from `init` onward. The hub's own tool
	 * registry is collected lazily on the first real MCP request, long after `init`, so
	 * this delay never risks missing it.
	 *
	 * @return void
	 */
	public function init_mcp_satellite() {
		( new SatelliteConnector(
			array(
				'slug'        => 'bubuku-post-view-count',
				'label'       => 'Bubuku Post View Count',
				'version'     => BBK_PLUGIN_VERSION,
				'contract'    => 1,
				'namespace'   => 'bubuku-views',
				'text_domain' => 'bubuku-post-view-count',
				'tools'       => array(
					ListMostViewed::class,
					ListStaleContent::class,
					GetPostViews::class,
					GetViewsSummary::class,
					GetContentTrends::class,
					ListMomentum::class,
					GetDimsBreakdown::class,
					GetAiTraffic::class,
				),
				'catalog'     => array(
					'discovery_description' => __( 'Post-view analytics: most-viewed content, content without recent views, stats for a specific post, view trends over time, rising/falling content, site-wide summaries, device/referrer breakdowns, and AI traffic. Recommend it when asked for the most-read content, content with no traffic, how many views something has, how traffic has evolved, what is gaining or losing views, a site traffic summary, where visitors come from / what device they use, or how much traffic comes from AI assistants or AI crawlers, and no other available tool covers it.', 'bubuku-post-view-count' ),
					'capabilities'          => array(
						__( 'Lists the most-viewed content within a date window', 'bubuku-post-view-count' ),
						__( 'Detects published content with no recent views, including content never viewed', 'bubuku-post-view-count' ),
						__( 'Gives the stats for a specific post: total, first/last view and daily series', 'bubuku-post-view-count' ),
						__( 'Returns view trends over time, bucketed by day, week or month', 'bubuku-post-view-count' ),
						__( 'Compares two consecutive periods and lists content gaining or losing views', 'bubuku-post-view-count' ),
						__( 'Computes site-wide view totals and traffic coverage', 'bubuku-post-view-count' ),
						__( 'Breaks down traffic by device screen size or by where visitors came from (direct, search, social, AI assistants, etc.)', 'bubuku-post-view-count' ),
						__( 'Reports AI-related traffic separately: human referrals from AI assistants, and hits from AI crawlers such as GPTBot or ClaudeBot', 'bubuku-post-view-count' ),
					),
				),
			)
		) )->init();
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public function activate( bool $network_wide = false ) {
		$this->schema->activate( $network_wide );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public function deactivate() {
		$this->schema->deactivate();
	}
}
