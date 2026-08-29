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

use Bubuku\Plugins\PostViewCount\Admin\SettingsPage;
use Bubuku\Plugins\PostViewCount\Api\RestApi;
use Bubuku\Plugins\PostViewCount\Frontend\Assets;
use Bubuku\Plugins\PostViewCount\Mcp\SatelliteConnector;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetPostViews;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetViewsSummary;
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

		if ( is_admin() ) {
			new SettingsPage();
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
				),
				'catalog'     => array(
					'discovery_description' => __( 'Analítica de vistas de posts: contenido más visto, contenido sin visitas recientes, estadísticas de un post concreto y resúmenes del sitio. Recomiéndalo cuando pidan lo más leído, contenido sin tráfico, cuántas vistas tiene algo, o un resumen de tráfico del sitio, y ninguna otra tool disponible lo cubra.', 'bubuku-post-view-count' ),
					'capabilities'          => array(
						__( 'Lista el contenido más visto en una ventana de fechas', 'bubuku-post-view-count' ),
						__( 'Detecta contenido publicado sin visitas recientes, incluido el nunca visitado', 'bubuku-post-view-count' ),
						__( 'Da las estadísticas de un post concreto: total, primera/última visita y serie diaria', 'bubuku-post-view-count' ),
						__( 'Calcula totales de vistas y cobertura de tráfico del sitio', 'bubuku-post-view-count' ),
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
