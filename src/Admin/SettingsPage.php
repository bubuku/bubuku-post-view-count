<?php
/**
 * SettingsPage Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Admin;

use Bubuku\Plugins\PostViewCount\Core\Db;
use Bubuku\Plugins\PostViewCount\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > Post View Count admin page. Classic Settings API — no build
 * step, consistent with the rest of the plugin (see AGENTS.md).
 */
class SettingsPage {

	const OPTION_GROUP = 'bbk_postview_settings_group';
	const PAGE_SLUG    = 'bbk-postview-settings';
	const SECTION_ID   = 'bbk_postview_main';
	const RESET_ACTION = 'bbk_postview_reset_data';

	/**
	 * Hook suffix returned by add_options_page(), used to scope the stats
	 * script/style to this admin page only.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset_data' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_stats_assets' ) );
	}

	/**
	 * Registers the submenu page under Settings.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_options_page(
			__( 'Post View Count', 'bubuku-post-view-count' ),
			__( 'Post View Count', 'bubuku-post-view-count' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues the evolution chart script/style, only on this settings page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_stats_assets( string $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'bbk-postview-admin-stats',
			BBK_PLUGIN_ASSETS_URL . '/css/admin-stats.css',
			array(),
			BBK_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'bbk-postview-admin-stats',
			BBK_PLUGIN_ASSETS_URL . '/js/admin-stats.js',
			array(),
			BBK_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'bbk-postview-admin-stats',
			'bbk_postview_stats',
			array(
				'api_trends'     => rest_url( BBK_PLUGIN_ENDPOINTS_URL . '/trends' ),
				'api_momentum'   => rest_url( BBK_PLUGIN_ENDPOINTS_URL . '/trends/momentum' ),
				'api_dims'       => rest_url( BBK_PLUGIN_ENDPOINTS_URL . '/trends/dims' ),
				'api_ai_traffic' => rest_url( BBK_PLUGIN_ENDPOINTS_URL . '/trends/ai-traffic' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'i18n'           => array(
					'noData'        => __( 'Todavía no hay datos suficientes para dibujar la gráfica.', 'bubuku-post-view-count' ),
					'thisPeriod'    => __( 'Este periodo', 'bubuku-post-view-count' ),
					/* translators: %s: percentage change vs the previous period, e.g. "+12%". */
					'vsPrevious'    => __( '%s vs. periodo anterior', 'bubuku-post-view-count' ),
					'views'         => __( 'vistas', 'bubuku-post-view-count' ),
					'noMomentum'    => __( 'Sin cambios relevantes en este periodo.', 'bubuku-post-view-count' ),
					'noDims'        => __( 'Todavía no hay datos suficientes.', 'bubuku-post-view-count' ),
					'noAiReferrals' => __( 'Sin visitas procedentes de asistentes de IA en este periodo.', 'bubuku-post-view-count' ),
					'noAiCrawlers'  => __( 'Sin rastreo registrado.', 'bubuku-post-view-count' ),
					'aiTrackingOff' => __( 'El rastreo de bots de IA está desactivado en los ajustes.', 'bubuku-post-view-count' ),
				),
			)
		);
	}

	/**
	 * Registers the settings option, section and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION_KEY,
			array( 'sanitize_callback' => array( Settings::class, 'sanitize' ) )
		);

		add_settings_section( self::SECTION_ID, '', '__return_false', self::PAGE_SLUG );

		add_settings_field( 'post_types', __( 'Tipos de contenido', 'bubuku-post-view-count' ), array( $this, 'field_post_types' ), self::PAGE_SLUG, self::SECTION_ID );
		add_settings_field( 'excluded_roles', __( 'Roles excluidos', 'bubuku-post-view-count' ), array( $this, 'field_excluded_roles' ), self::PAGE_SLUG, self::SECTION_ID );
		add_settings_field( 'exclude_bots', __( 'Bots', 'bubuku-post-view-count' ), array( $this, 'field_exclude_bots' ), self::PAGE_SLUG, self::SECTION_ID );
		add_settings_field( 'ai_crawler_tracking', __( 'Rastreo de bots de IA', 'bubuku-post-view-count' ), array( $this, 'field_ai_crawler_tracking' ), self::PAGE_SLUG, self::SECTION_ID );
		add_settings_field( 'respect_dnt', __( 'Privacidad (DNT / GPC)', 'bubuku-post-view-count' ), array( $this, 'field_respect_dnt' ), self::PAGE_SLUG, self::SECTION_ID );
		add_settings_field( 'write_buffer', __( 'Buffer de escrituras', 'bubuku-post-view-count' ), array( $this, 'field_write_buffer' ), self::PAGE_SLUG, self::SECTION_ID );
		add_settings_field( 'retention_days', __( 'Retención del agregado diario', 'bubuku-post-view-count' ), array( $this, 'field_retention_days' ), self::PAGE_SLUG, self::SECTION_ID );
		add_settings_field( 'delete_data_on_uninstall', __( 'Al desinstalar', 'bubuku-post-view-count' ), array( $this, 'field_delete_on_uninstall' ), self::PAGE_SLUG, self::SECTION_ID );
	}

	/**
	 * Renders the page: the Settings API form, plus the standalone
	 * "delete all data now" form (§1.8/§3.5 of ANALYTICS-PLAN.md).
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'bubuku-post-view-count' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag to show a notice after the redirect from handle_delete_all(), which already verifies its own nonce; no data is processed here.
		if ( isset( $_GET['bbk_postview_reset'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Se han eliminado todas las vistas registradas.', 'bubuku-post-view-count' ) .
				'</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bubuku Post View Count', 'bubuku-post-view-count' ); ?></h1>

			<h2><?php esc_html_e( 'Evolución de vistas', 'bubuku-post-view-count' ); ?></h2>
			<div id="bbk-postview-stats" class="bbk-postview-stats">
				<p>
					<label for="bbk-postview-granularity">
						<?php esc_html_e( 'Agrupar por', 'bubuku-post-view-count' ); ?>
					</label>
					<select id="bbk-postview-granularity">
						<option value="day"><?php esc_html_e( 'Día', 'bubuku-post-view-count' ); ?></option>
						<option value="week"><?php esc_html_e( 'Semana', 'bubuku-post-view-count' ); ?></option>
						<option value="month"><?php esc_html_e( 'Mes', 'bubuku-post-view-count' ); ?></option>
					</select>
				</p>
				<p id="bbk-postview-comparison" class="description"></p>
				<canvas id="bbk-postview-chart" width="900" height="260"></canvas>
			</div>

			<h2><?php esc_html_e( 'En alza y en caída', 'bubuku-post-view-count' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Comparación entre los últimos 30 días y los 30 anteriores.', 'bubuku-post-view-count' ); ?></p>
			<div id="bbk-postview-momentum" class="bbk-postview-momentum">
				<div class="bbk-postview-momentum-column">
					<h3><?php esc_html_e( 'En alza', 'bubuku-post-view-count' ); ?></h3>
					<ul id="bbk-postview-momentum-rising"></ul>
				</div>
				<div class="bbk-postview-momentum-column">
					<h3><?php esc_html_e( 'En caída', 'bubuku-post-view-count' ); ?></h3>
					<ul id="bbk-postview-momentum-falling"></ul>
				</div>
			</div>

			<h2><?php esc_html_e( 'Dispositivo y procedencia', 'bubuku-post-view-count' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Últimos 3 meses.', 'bubuku-post-view-count' ); ?></p>
			<div id="bbk-postview-dims" class="bbk-postview-dims">
				<div class="bbk-postview-dims-column">
					<h3><?php esc_html_e( 'Dispositivo', 'bubuku-post-view-count' ); ?></h3>
					<ul id="bbk-postview-dims-viewport"></ul>
				</div>
				<div class="bbk-postview-dims-column">
					<h3><?php esc_html_e( 'Procedencia', 'bubuku-post-view-count' ); ?></h3>
					<ul id="bbk-postview-dims-referrer"></ul>
				</div>
			</div>

			<h2><?php esc_html_e( 'Tráfico de IA', 'bubuku-post-view-count' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Últimos 3 meses. Referidos: visitantes humanos llegados desde un asistente de IA (incluidos en el conteo de vistas). Rastreo: peticiones de bots de IA conocidos, contadas aparte.', 'bubuku-post-view-count' ); ?></p>
			<div id="bbk-postview-ai-traffic" class="bbk-postview-dims">
				<div class="bbk-postview-dims-column">
					<h3><?php esc_html_e( 'Referidos por IA', 'bubuku-post-view-count' ); ?></h3>
					<p id="bbk-postview-ai-referrals"></p>
				</div>
				<div class="bbk-postview-dims-column">
					<h3><?php esc_html_e( 'Rastreo de bots de IA', 'bubuku-post-view-count' ); ?></h3>
					<ul id="bbk-postview-ai-crawlers"></ul>
				</div>
			</div>

			<hr />

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Eliminar todos los datos', 'bubuku-post-view-count' ); ?></h2>
			<p><?php esc_html_e( 'Elimina inmediatamente todas las vistas registradas (tablas propias y post meta). Esta acción no se puede deshacer.', 'bubuku-post-view-count' ); ?></p>
			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php echo esc_js( __( '¿Seguro que quieres eliminar todas las vistas registradas? Esta acción no se puede deshacer.', 'bubuku-post-view-count' ) ); ?>');"
			>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>" />
				<?php wp_nonce_field( self::RESET_ACTION ); ?>
				<?php submit_button( __( 'Eliminar todos los datos ahora', 'bubuku-post-view-count' ), 'delete' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Field: post types allowed to count views.
	 *
	 * @return void
	 */
	public function field_post_types() {
		$settings = Settings::get_all();

		foreach ( Settings::selectable_post_types() as $post_type ) {
			printf(
				'<label style="display:block;"><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Settings::OPTION_KEY ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $settings['post_types'], true ), true, false ),
				esc_html( $post_type->labels->name )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Desmarcar un tipo de contenido detiene el conteo, pero no borra las visitas ya registradas. Volver a marcarlo reanuda el conteo sobre el total existente.', 'bubuku-post-view-count' )
		);
	}

	/**
	 * Field: roles whose views are never counted.
	 *
	 * @return void
	 */
	public function field_excluded_roles() {
		$settings = Settings::get_all();
		$roles    = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();

		foreach ( $roles as $slug => $role ) {
			printf(
				'<label style="display:block;"><input type="checkbox" name="%1$s[excluded_roles][]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Settings::OPTION_KEY ),
				esc_attr( $slug ),
				checked( in_array( $slug, $settings['excluded_roles'], true ), true, false ),
				esc_html( translate_user_role( $role['name'] ) )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Los usuarios logados con uno de estos roles no generan visitas al ver sus propios contenidos.', 'bubuku-post-view-count' )
		);
	}

	/**
	 * Field: exclude known bot user agents.
	 *
	 * @return void
	 */
	public function field_exclude_bots() {
		$settings = Settings::get_all();

		printf(
			'<label><input type="checkbox" name="%1$s[exclude_bots]" value="1" %2$s /> %3$s</label>',
			esc_attr( Settings::OPTION_KEY ),
			checked( $settings['exclude_bots'], true, false ),
			esc_html__( 'No contar visitas de user-agents de bots conocidos.', 'bubuku-post-view-count' )
		);

		printf(
			'<p class="description"><small>%s %s</small></p>',
			esc_html__( 'Incluye, entre otros:', 'bubuku-post-view-count' ),
			esc_html( implode( ', ', Settings::bot_signature_examples() ) )
		);
	}

	/**
	 * Field: retention window for the daily aggregate table.
	 *
	 * @return void
	 */
	public function field_retention_days() {
		$settings = Settings::get_all();

		printf(
			'<input type="number" min="1" name="%1$s[retention_days]" value="%2$d" class="small-text" /> %3$s',
			esc_attr( Settings::OPTION_KEY ),
			(int) $settings['retention_days'],
			esc_html__( 'días', 'bubuku-post-view-count' )
		);

		printf(
			'<p class="description"><small>%s</small></p>',
			esc_html__( 'Solo afecta a mostrar los datos diarios de cada contenido, no al total: solo dispondrás del historial de esos días. El total de vistas no se ve afectado y nunca se borra por esta retención.', 'bubuku-post-view-count' )
		);
	}

	/**
	 * Field: whether to delete all plugin data on uninstall.
	 *
	 * @return void
	 */
	public function field_delete_on_uninstall() {
		$settings = Settings::get_all();

		printf(
			'<label><input type="checkbox" name="%1$s[delete_data_on_uninstall]" value="1" %2$s /> %3$s</label>',
			esc_attr( Settings::OPTION_KEY ),
			checked( $settings['delete_data_on_uninstall'], true, false ),
			esc_html__( 'Eliminar todas las tablas, meta y opciones del plugin al desinstalarlo.', 'bubuku-post-view-count' )
		);
	}

	/**
	 * Field: opt-in server-side tracking of known AI crawlers (F6).
	 *
	 * @return void
	 */
	public function field_ai_crawler_tracking() {
		$settings = Settings::get_all();

		printf(
			'<label><input type="checkbox" name="%1$s[ai_crawler_tracking]" value="1" %2$s /> %3$s</label>',
			esc_attr( Settings::OPTION_KEY ),
			checked( $settings['ai_crawler_tracking'], true, false ),
			esc_html__( 'Contar las visitas de bots de IA conocidos (GPTBot, ClaudeBot, PerplexityBot, etc.) en una tabla propia, separada del conteo de visitantes humanos.', 'bubuku-post-view-count' )
		);

		printf(
			'<p class="description"><small>%s</small></p>',
			esc_html__( 'Desactivado por defecto: añade una escritura en cada petición de estos bots, lo que no es despreciable en un sitio con mucho tráfico de crawlers.', 'bubuku-post-view-count' )
		);
	}

	/**
	 * Field: whether to honor a visitor's DNT/Sec-GPC privacy signal (F7).
	 *
	 * @return void
	 */
	public function field_respect_dnt() {
		$settings = Settings::get_all();

		printf(
			'<label><input type="checkbox" name="%1$s[respect_dnt]" value="1" %2$s /> %3$s</label>',
			esc_attr( Settings::OPTION_KEY ),
			checked( $settings['respect_dnt'], true, false ),
			esc_html__( 'Respetar la señal "No rastrear" (DNT) y Global Privacy Control (Sec-GPC) del navegador.', 'bubuku-post-view-count' )
		);

		printf(
			'<p class="description"><small>%s</small></p>',
			esc_html__( 'El conteo de vistas se mantiene igual (ya es anónimo, sin IP ni user-agent almacenados): esta opción solo omite el dispositivo y la procedencia de esa visita.', 'bubuku-post-view-count' )
		);
	}

	/**
	 * Field: opt-in best-effort write buffer for high-traffic sites (F7).
	 *
	 * @return void
	 */
	public function field_write_buffer() {
		$settings = Settings::get_all();

		printf(
			'<label><input type="checkbox" name="%1$s[write_buffer]" value="1" %2$s /> %3$s</label>',
			esc_attr( Settings::OPTION_KEY ),
			checked( $settings['write_buffer'], true, false ),
			esc_html__( 'Acumular incrementos en memoria y escribirlos en la base de datos por lotes, cada minuto, en vez de en cada visita.', 'bubuku-post-view-count' )
		);

		printf(
			'<p class="description"><small>%s</small></p>',
			wp_using_ext_object_cache()
				? esc_html__( 'Este sitio tiene un object cache persistente activo: el buffer tendrá efecto.', 'bubuku-post-view-count' )
				: esc_html__( 'Este sitio no tiene un object cache persistente (Redis, Memcached...) activo: sin uno, esta opción no tiene ningún efecto — cada visita se sigue escribiendo de inmediato.', 'bubuku-post-view-count' )
		);
	}

	/**
	 * Handles the "delete all data now" form: drops and recreates the tables
	 * and removes the mirrored post meta, without uninstalling the plugin.
	 *
	 * @return void
	 */
	public function handle_reset_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'bubuku-post-view-count' ) );
		}

		check_admin_referer( self::RESET_ACTION );

		$db = new Db();
		$db->drop_tables();
		$db->remove_all_post_meta();

		// Recreate the (now empty) tables immediately — this is a reset, not an uninstall.
		( new Schema() )->activate( false );

		wp_safe_redirect(
			add_query_arg(
				'bbk_postview_reset',
				'1',
				admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}
}
