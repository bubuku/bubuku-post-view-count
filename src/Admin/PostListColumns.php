<?php
/**
 * PostListColumns Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Admin;

use Bubuku\Plugins\PostViewCount\Core\Schema;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Adds sortable "Views" / "Last view" columns to the admin post list of every
 * currently enabled content type (docs/ANALYTICS-PLAN.md §3.5). Values are read
 * from the post meta mirror (already primed in bulk by WP's own list query, so
 * no extra query per row); sorting instead joins the plugin's own table directly
 * — an indexed `BIGINT`/`DATETIME` comparison, not the `CAST` over `postmeta`
 * that would have been needed before the table existed.
 */
class PostListColumns {

	/**
	 * Sort key currently active for the main query, if any ('bbk_views' or
	 * 'bbk_last_viewed'). Empty when the current list isn't sorted by either.
	 *
	 * @var string
	 */
	private $sort_column = '';

	/**
	 * Sort direction for the active sort key.
	 *
	 * @var string
	 */
	private $sort_order = 'DESC';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_post_type_hooks' ) );
		add_action( 'pre_get_posts', array( $this, 'maybe_sort_by_views' ) );
	}

	/**
	 * Registers the column hooks for every post type currently enabled — never
	 * for a disabled one, even if it was enabled in the past.
	 *
	 * @return void
	 */
	public function register_post_type_hooks() {
		foreach ( Settings::enabled_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'add_sortable_columns' ) );
		}
	}

	/**
	 * @param array<string, string> $columns Existing list table columns.
	 * @return array<string, string>
	 */
	public function add_columns( array $columns ): array {
		$columns['bbk_views']       = __( 'Views', 'bubuku-post-view-count' );
		$columns['bbk_last_viewed'] = __( 'Last view', 'bubuku-post-view-count' );

		return $columns;
	}

	/**
	 * @param array<string, string> $columns Columns already marked sortable.
	 * @return array<string, string>
	 */
	public function add_sortable_columns( array $columns ): array {
		$columns['bbk_views']       = 'bbk_views';
		$columns['bbk_last_viewed'] = 'bbk_last_viewed';

		return $columns;
	}

	/**
	 * Renders one cell. Reads the post meta mirror (`views`/`views_last`),
	 * already bulk-loaded by WP's own list query — no per-row query here.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ) {
		if ( 'bbk_views' === $column ) {
			echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, 'views', true ) ) );

			return;
		}

		if ( 'bbk_last_viewed' === $column ) {
			$last_viewed_at = get_post_meta( $post_id, 'views_last', true );

			echo $last_viewed_at
				? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_viewed_at ) )
				: '—';
		}
	}

	/**
	 * Joins and orders by the plugin's own table when the list is sorted by
	 * either custom column. `WP_Query::get( 'order' )` is already normalized by
	 * core, so this never reads `$_GET` directly.
	 *
	 * @param WP_Query $query The main query, before it runs.
	 * @return void
	 */
	public function maybe_sort_by_views( WP_Query $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( ! in_array( $orderby, array( 'bbk_views', 'bbk_last_viewed' ), true ) ) {
			return;
		}

		$this->sort_column = $orderby;
		$this->sort_order  = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';

		add_filter( 'posts_join', array( $this, 'join_views_table' ) );
		add_filter( 'posts_orderby', array( $this, 'orderby_views_table' ) );
	}

	/**
	 * @param string $join Existing SQL JOIN clause.
	 * @return string
	 */
	public function join_views_table( string $join ): string {
		global $wpdb;

		$views_table = Schema::table_views();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (Schema::table_views()), never user input; standard posts_join filter pattern, no $wpdb->prepare() placeholders apply here.
		return $join . " LEFT JOIN {$views_table} bbk_v ON bbk_v.post_id = {$wpdb->posts}.ID";
	}

	/**
	 * @param string $orderby Existing SQL ORDER BY clause (discarded — this sort takes over).
	 * @return string
	 */
	public function orderby_views_table( string $orderby ): string {
		unset( $orderby );

		$column = 'bbk_last_viewed' === $this->sort_column ? 'bbk_v.last_viewed_at' : 'bbk_v.views';

		return "{$column} {$this->sort_order}";
	}
}
