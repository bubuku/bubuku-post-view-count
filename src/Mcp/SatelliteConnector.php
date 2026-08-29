<?php
/**
 * SatelliteConnector Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Conector satélite del hub `bubuku-mcp-conex` (docs/ANALYTICS-PLAN.md §4.2). Detecta el
 * hub, declara el satélite, registra sus tools (carga perezosa) y aporta la entrada de
 * catálogo. El satélite nunca implementa OAuth, transporte ni logging — eso es del hub.
 *
 * Diverge del contrato genérico del skill `wp-mcp-conex` en dos puntos, deliberadamente
 * (ver ANALYTICS-PLAN.md §4.2): sin cabecera `Requires Plugins`, y sin admin notice cuando
 * el hub no está activo — este plugin es público de WordPress.org y el hub es opcional
 * para todos sus usuarios, así que ausencia de hub debe ser silenciosa, nunca un aviso.
 */
class SatelliteConnector {

	/**
	 * Configuración del satélite: slug, label, version, contract, namespace (prefijo de
	 * tools), text_domain, tools[] (FQCN) y catalog (discovery_description, capabilities).
	 *
	 * @var array<string, mixed>
	 */
	private $config;

	/**
	 * @param array<string, mixed> $config Ver la propiedad $config.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Cablea el satélite al hub. Si el hub no está presente, no hace nada — ni fatal ni
	 * aviso (a diferencia del contrato genérico, ver la nota de clase).
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->hub_is_active() ) {
			return;
		}

		add_filter( 'bubuku_conex_satellites', array( $this, 'declare_satellite' ) );
		add_action( 'bubuku_conex_register_tools', array( $this, 'register_tools' ) );
		add_filter( 'bubuku_conex_satellite_catalog', array( $this, 'declare_catalog_entry' ) );
	}

	/**
	 * Único punto de detección del hub.
	 *
	 * @return bool
	 */
	private function hub_is_active(): bool {
		return class_exists( '\BubukuConex\Registry' );
	}

	/**
	 * Declara el satélite: label, versión del plugin, versión de contrato y el namespace
	 * (prefijo) de sus tools.
	 *
	 * @param array<string, array<string, mixed>> $satellites Satélites ya declarados.
	 * @return array<string, array<string, mixed>>
	 */
	public function declare_satellite( array $satellites ): array {
		$satellites[ $this->config['slug'] ] = array(
			'label'     => $this->config['label'],
			'version'   => $this->config['version'],
			'contract'  => $this->config['contract'],
			'namespace' => $this->config['namespace'],
		);

		return $satellites;
	}

	/**
	 * Registra las tools en el registry del hub. Carga perezosa: las clases de tool
	 * (que extienden una clase del hub) solo se instancian aquí, con el hub ya presente.
	 *
	 * @param \BubukuConex\Registry $registry Registry del hub.
	 * @return void
	 */
	public function register_tools( $registry ) {
		foreach ( $this->config['tools'] as $tool_class ) {
			$registry->add( new $tool_class() );
		}
	}

	/**
	 * Aporta la entrada de catálogo en runtime: pisa la del JSON empaquetado del hub con
	 * la versión fresca. Solo existe mientras el plugin está instalado y activo — el
	 * descubrimiento con el satélite ausente se sirve del catálogo estático del hub.
	 *
	 * @param array<string, array<string, mixed>> $catalog Catálogo de satélites.
	 * @return array<string, array<string, mixed>>
	 */
	public function declare_catalog_entry( array $catalog ): array {
		$catalog[ $this->config['slug'] ] = array_merge(
			$this->config['catalog'],
			array(
				'label'   => $this->config['label'],
				'version' => $this->config['version'],
			)
		);

		return $catalog;
	}
}
