<?php
/**
 * Settings Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes and exposes the plugin settings option. The table is the
 * source of truth for view data (see Core\Db); this class is only concerned
 * with the `bbk_postview_settings` option that controls what gets counted.
 */
class Settings {

	const OPTION_KEY = 'bbk_postview_settings';

	/**
	 * Default settings, used when the option does not exist yet or a key is
	 * missing from a stored value (e.g. after an upgrade adds a new field).
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'post_types'               => array( 'post' ),
			'excluded_roles'           => self::default_excluded_roles(),
			'exclude_bots'             => false,
			'retention_days'           => 400,
			'delete_data_on_uninstall' => true,
			'ai_crawler_tracking'      => false,
			'respect_dnt'              => true,
		);
	}

	/**
	 * Current settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get_all(): array {
		$saved = get_option( self::OPTION_KEY, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Public post types a site admin can choose to count views for, excluding
	 * `attachment` ("Media"): attachments are not standalone content a visitor
	 * browses to like a post or page, and WordPress core marks them `public`
	 * regardless, so they would otherwise show up as a selectable content type.
	 * Single source of truth for both the settings page checkboxes
	 * (`SettingsPage::field_post_types()`) and the `sanitize()` whitelist below.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function selectable_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		unset( $post_types['attachment'] );

		return apply_filters( 'bbk_postview_selectable_post_types', $post_types );
	}

	/**
	 * Post types that currently count views. The single source of truth for
	 * both `Frontend\Assets` (whether to enqueue the script) and
	 * `Api\RestApi` (whether to accept a view for a given post_id) — see
	 * docs/ANALYTICS-PLAN.md §3.1.
	 *
	 * @return string[]
	 */
	public static function enabled_post_types(): array {
		$post_types = apply_filters( 'bbk_postview_enabled_post_types', self::get_all()['post_types'] );

		return array_values( array_unique( array_map( 'sanitize_key', (array) $post_types ) ) );
	}

	/**
	 * Role slugs whose views are never counted.
	 *
	 * @return string[]
	 */
	public static function excluded_roles(): array {
		return self::get_all()['excluded_roles'];
	}

	/**
	 * Whether known bot user agents should be excluded from the count.
	 *
	 * @return bool
	 */
	public static function exclude_bots(): bool {
		return (bool) self::get_all()['exclude_bots'];
	}

	/**
	 * Whether server-side AI-crawler tracking (F6) is enabled. Disabled by default: it adds
	 * a write on every matching crawler request, which is not negligible on a site with
	 * heavy crawler traffic (docs/ANALYTICS-PLAN.md §F6).
	 *
	 * @return bool
	 */
	public static function ai_crawler_tracking(): bool {
		return (bool) self::get_all()['ai_crawler_tracking'];
	}

	/**
	 * Whether the plugin should honor a visitor's DNT/Sec-GPC privacy signal
	 * (docs/ANALYTICS-PLAN.md §F7). Enabled by default: it never blocks the
	 * view count itself (already anonymous, no IP/UA stored), it only skips
	 * the optional session dimensions (F5) for that visit — see
	 * `RestApi::valid_dims()`.
	 *
	 * @return bool
	 */
	public static function respect_dnt(): bool {
		return (bool) self::get_all()['respect_dnt'];
	}

	/**
	 * Whether the currently logged-in user belongs to an excluded role.
	 * Logged-out visitors are never excluded by this check.
	 *
	 * @return bool
	 */
	public static function is_current_user_excluded(): bool {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return false;
		}

		$user = wp_get_current_user();

		if ( empty( $user->roles ) ) {
			return false;
		}

		return (bool) array_intersect( $user->roles, self::excluded_roles() );
	}

	/**
	 * Whether the given User-Agent matches a known bot signature.
	 *
	 * @param string $user_agent Raw User-Agent header value.
	 * @return bool
	 */
	public static function is_bot_user_agent( string $user_agent ): bool {
		if ( '' === $user_agent ) {
			return false;
		}

		foreach ( self::bot_signatures() as $signature ) {
			if ( false !== stripos( $user_agent, $signature ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Human-readable examples of what the `exclude_bots` matching actually
	 * catches — for display in the settings page only. The real matching
	 * uses the broader substrings in bot_signatures() (e.g. "bot", "spider"),
	 * which already cover all of these; this list exists so the admin can
	 * see what "known bots" means without reading the substring list.
	 *
	 * @return string[]
	 */
	public static function bot_signature_examples(): array {
		return apply_filters(
			'bbk_postview_bot_signature_examples',
			array(
				'Googlebot',
				'Bingbot',
				'GPTBot',
				'ClaudeBot',
				'PerplexityBot',
				'AhrefsBot',
				'SemrushBot',
				'YandexBot',
				'DuckDuckBot',
				'facebookexternalhit',
				'WhatsApp',
				'curl',
				'wget',
			)
		);
	}

	/**
	 * Sanitize a settings array coming from the settings form (`register_setting`
	 * callback). Never trusts the input: post types and roles are intersected
	 * against what actually exists on the site.
	 *
	 * @param mixed $input Raw value submitted by the settings form.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$valid_post_types = array_keys( self::selectable_post_types() );
		$post_types       = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$post_types       = array_values( array_intersect( array_map( 'sanitize_key', $post_types ), $valid_post_types ) );

		$valid_roles    = function_exists( 'get_editable_roles' ) ? array_keys( get_editable_roles() ) : array();
		$excluded_roles = isset( $input['excluded_roles'] ) ? (array) $input['excluded_roles'] : array();
		$excluded_roles = array_values( array_intersect( array_map( 'sanitize_key', $excluded_roles ), $valid_roles ) );

		$retention_days = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : $defaults['retention_days'];

		return array(
			'post_types'               => empty( $post_types ) ? $defaults['post_types'] : $post_types,
			'excluded_roles'           => $excluded_roles,
			'exclude_bots'             => ! empty( $input['exclude_bots'] ),
			'retention_days'           => max( 1, $retention_days ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
			'ai_crawler_tracking'      => ! empty( $input['ai_crawler_tracking'] ),
			'respect_dnt'              => ! empty( $input['respect_dnt'] ),
		);
	}

	/**
	 * Default excluded roles: whichever roles currently carry `edit_posts`,
	 * matching the capability check this setting replaces.
	 *
	 * @return string[]
	 */
	private static function default_excluded_roles(): array {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			return array( 'administrator', 'editor', 'author', 'contributor' );
		}

		$roles = array();

		foreach ( get_editable_roles() as $slug => $role ) {
			if ( ! empty( $role['capabilities']['edit_posts'] ) ) {
				$roles[] = $slug;
			}
		}

		return $roles;
	}

	/**
	 * Known bot/crawler User-Agent substrings, filterable for site-specific needs.
	 *
	 * @return string[]
	 */
	private static function bot_signatures(): array {
		return apply_filters(
			'bbk_postview_bot_signatures',
			array(
				'bot',
				'spider',
				'crawl',
				'slurp',
				'facebookexternalhit',
				'whatsapp',
				'ahrefs',
				'semrush',
				'mj12bot',
				'dotbot',
				'petalbot',
				'curl',
				'wget',
				'python-requests',
				'go-http-client',
				'headlesschrome',
			)
		);
	}
}
