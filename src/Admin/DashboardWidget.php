<?php
/**
 * DashboardWidget Class.
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
 * "Post views summary" widget on wp-admin/index.php (docs/DASHBOARD-SUMMARY-PLAN.md §5).
 * Entirely server-rendered from `OverviewSnapshot::get_cached()` — no REST call, no JS,
 * so the dashboard's own request never pays for `Core\Query`. The 7-day trend is an
 * inline SVG built in PHP, same technique as `Bubuku-Media-Library`'s donut widget
 * (`BML_widget_dashboard.php`): no charting library needed for a shape this simple.
 */
class DashboardWidget {

	/**
	 * Icon paths, same visual language as the admin app's SummaryCards
	 * (assets/src/js/admin/components/SummaryCards.js) so the widget and the
	 * Stats tab read as one system.
	 */
	const ICON_EYE   = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" /><circle cx="12" cy="12" r="3" />';
	const ICON_TREND = '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18" /><polyline points="17 6 23 6 23 12" />';
	const ICON_AI    = '<circle cx="12" cy="12" r="10" /><path d="M12 8v4l3 3" />';
	const ICON_BOT   = '<rect x="3" y="9" width="18" height="11" rx="2" /><circle cx="8.5" cy="14.5" r="1.25" fill="currentColor" stroke="none" /><circle cx="15.5" cy="14.5" r="1.25" fill="currentColor" stroke="none" /><path d="M12 9V5" /><circle cx="12" cy="3.5" r="1.5" />';

	/**
	 * @var string
	 */
	private $plugin_name;

	public function __construct( string $plugin_name ) {
		$this->plugin_name = $plugin_name;
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
	}

	/**
	 * Same capability as `Api\TrendsApi::check_permission()` — whoever can already
	 * see the Stats tab sees this widget; subscribers never do.
	 *
	 * @return void
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Wrapped in a single <span>: WP core's `.postbox-header .hndle` is itself
		// `display: flex; justify-content: space-between;` (to make room for a
		// widget's optional "Configure" link on the right). Two loose children —
		// this dot span and the following bare text — would be read as two flex
		// items and get pushed to opposite ends; one wrapper makes it a single
		// item, which space-between keeps at the start.
		$dot   = '<span class="bbk-widget-dot"></span>';
		$title = '<span>' . $dot . esc_html__( 'Post views summary', 'bubuku-post-view-count' ) . '</span>';

		wp_add_dashboard_widget( 'bbk-postview-dashboard-summary', $title, array( $this, 'render' ) );
	}

	/**
	 * @return void
	 */
	public function render() {
		$snapshot = OverviewSnapshot::get_cached();

		ob_start();
		?>
		<div class="bbk-widget">
			<div class="bbk-widget-hero">
				<div class="bbk-widget-icon bbk-widget-icon--brand"><?php echo $this->icon( self::ICON_EYE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() only ever wraps the fixed SVG path constants above, no dynamic input. ?></div>
				<div class="bbk-widget-hero-body">
					<span class="bbk-widget-hero-label"><?php esc_html_e( 'Total views', 'bubuku-post-view-count' ); ?></span>
					<span class="bbk-widget-hero-value"><?php echo esc_html( $snapshot['total_views_formatted'] ); ?></span>
				</div>
			</div>

			<p class="bbk-widget-hero-trend">
				<?php echo wp_kses( $this->delta_badge( $snapshot['delta'], $snapshot['delta_pct'] ), array( 'span' => array( 'class' => array() ) ) ); ?>
				<span class="bbk-widget-hero-trend-note">
					<?php
					printf(
						/* translators: %s: view count over the last 30 days */
						esc_html__( '%s views in the last 30 days', 'bubuku-post-view-count' ),
						esc_html( $snapshot['current_views_formatted'] )
					);
					?>
				</span>
			</p>

			<div class="bbk-widget-metrics">
				<div class="bbk-widget-metric">
					<div class="bbk-widget-icon bbk-widget-icon--violet"><?php echo $this->icon( self::ICON_AI ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<div class="bbk-widget-metric-body">
						<span class="bbk-widget-metric-label"><?php esc_html_e( 'AI referrals', 'bubuku-post-view-count' ); ?></span>
						<span class="bbk-widget-metric-value"><?php echo esc_html( $snapshot['ai_referral_views_formatted'] ); ?></span>
					</div>
				</div>
				<div class="bbk-widget-metric">
					<div class="bbk-widget-icon bbk-widget-icon--teal"><?php echo $this->icon( self::ICON_BOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<div class="bbk-widget-metric-body">
						<span class="bbk-widget-metric-label"><?php esc_html_e( 'AI bot crawling', 'bubuku-post-view-count' ); ?></span>
						<span class="bbk-widget-metric-value">
							<?php
							$crawler_display = $snapshot['ai_crawler_tracking_enabled']
								? $snapshot['ai_crawler_views_formatted']
								: '—';
							echo esc_html( $crawler_display );
							?>
						</span>
					</div>
				</div>
			</div>

			<div class="bbk-widget-section">
				<div class="bbk-widget-section-header">
					<div class="bbk-widget-icon bbk-widget-icon--sm bbk-widget-icon--brand"><?php echo $this->icon( self::ICON_TREND ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<h3><?php esc_html_e( 'Last 7 days', 'bubuku-post-view-count' ); ?></h3>
					<span class="bbk-widget-section-total">
						<?php
						printf(
							/* translators: %s: total view count over the last 7 days */
							esc_html__( '%s total', 'bubuku-post-view-count' ),
							esc_html( number_format_i18n( array_sum( array_column( $snapshot['last_7_days'], 'views' ) ) ) )
						);
						?>
					</span>
				</div>
				<?php echo $this->render_sparkline( $snapshot['last_7_days'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_sparkline() escapes every attribute itself; see its own docblock. ?>
			</div>

			<?php if ( ! empty( $snapshot['warming'] ) ) : ?>
				<p class="bbk-widget-warming"><?php esc_html_e( 'Calculating the summary for the first time — refresh shortly.', 'bubuku-post-view-count' ); ?></p>
			<?php endif; ?>

			<div class="bbk-widget-footer">
				<span class="bbk-widget-footer-total">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: total view count */
							__( 'Total: <strong>%s</strong> views tracked', 'bubuku-post-view-count' ),
							$snapshot['total_views_formatted']
						),
						array( 'strong' => array() )
					);
					?>
					<?php if ( empty( $snapshot['warming'] ) && ! empty( $snapshot['generated_at'] ) ) : ?>
						<span class="bbk-widget-footer-updated">
							<?php
							printf(
								/* translators: %s: human-readable time since the summary was last calculated, e.g. "5 minutes" */
								esc_html__( '· Updated %s ago', 'bubuku-post-view-count' ),
								esc_html( human_time_diff( (int) $snapshot['generated_at'] ) )
							);
							?>
						</span>
					<?php endif; ?>
				</span>
				<a class="bbk-widget-footer-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . $this->plugin_name ) ); ?>">
					<?php esc_html_e( 'See stats', 'bubuku-post-view-count' ); ?>
					<span aria-hidden="true">&rsaquo;</span>
				</a>
			</div>
		</div>
		<?php
		// All HTML above is either translator-controlled strings passed through
		// esc_html()/esc_url()/wp_kses(), or attributes escaped in icon()/render_sparkline().
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ob_get_clean();
	}

	/**
	 * Wraps one of the ICON_* path constants above in a common 14x14 SVG shell —
	 * same monoline style as the admin app's icons. Never takes dynamic input,
	 * so nothing here needs escaping beyond the fixed constants themselves.
	 *
	 * @param string $paths One of the ICON_* constants (raw <path>/<circle>/... markup).
	 * @return string
	 */
	private function icon( string $paths ): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $paths . '</svg>';
	}

	/**
	 * "+123 (+4.5%)" / "-123 (-4.5%)" badge for the 30-day comparison, styled by sign.
	 * Returned as a string of pre-escaped markup — every dynamic value passes through
	 * esc_html()/number_format_i18n() before concatenation, so the caller only needs
	 * to allow the wrapping <span>.
	 *
	 * @param int        $delta     Signed view delta vs. the previous period.
	 * @param float|null $delta_pct Signed percentage delta, or null if the previous period was 0.
	 * @return string
	 */
	private function delta_badge( int $delta, ?float $delta_pct ): string {
		if ( 0 === $delta ) {
			return '';
		}

		$modifier = $delta > 0 ? 'up' : 'down';
		$sign     = $delta > 0 ? '+' : '';
		$text     = $sign . number_format_i18n( $delta );

		if ( null !== $delta_pct ) {
			$text .= ' (' . $sign . number_format_i18n( $delta_pct, 1 ) . '%)';
		}

		return sprintf( '<span class="bbk-widget-delta bbk-widget-delta--%1$s">%2$s</span>', esc_attr( $modifier ), esc_html( $text ) );
	}

	/**
	 * Dependency-free 7-day sparkline: an SVG line with a gradient fill under it,
	 * normalized to the series' own max so it always fills the available height,
	 * plus a highlighted marker on today's value. `viewBox` + a fixed aspect
	 * ratio let the CSS scale it to the postbox width without any JS. Every
	 * coordinate is a plain float formatted by PHP, so `esc_attr()` is enough —
	 * there is no user input on this path.
	 *
	 * @param array<int, array{day:string,views:int}> $days Exactly 7 entries, oldest first.
	 * @return string
	 */
	private function render_sparkline( array $days ): string {
		$total = array_sum( array_column( $days, 'views' ) );

		if ( 0 === $total ) {
			return '<p class="bbk-widget-spark-empty">' . esc_html__( 'No views recorded in the last 7 days.', 'bubuku-post-view-count' ) . '</p>';
		}

		// A few units of vertical padding keep the line and its end marker from
		// clipping against the viewBox edges at the series' min/max.
		$width   = 100;
		$height  = 40;
		$padding = 4;
		$plot    = $height - ( $padding * 2 );
		$max     = max( array_column( $days, 'views' ) );
		$count   = count( $days );
		$step    = $count > 1 ? $width / ( $count - 1 ) : 0;

		$points = array();
		foreach ( array_values( $days ) as $index => $day ) {
			$x        = round( $index * $step, 2 );
			$y        = $max > 0 ? round( $padding + $plot - ( ( (int) $day['views'] / $max ) * $plot ), 2 ) : $height - $padding;
			$points[] = array( $x, $y );
		}

		$line_points = implode(
			' ',
			array_map(
				static function ( array $point ): string {
					return "{$point[0]},{$point[1]}";
				},
				$points
			)
		);
		$area_points = "0,{$height} {$line_points} {$width},{$height}";
		$end_point   = $points[ count( $points ) - 1 ];

		return sprintf(
			'<svg class="bbk-widget-spark" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" aria-hidden="true">' .
				'<defs><linearGradient id="bbk-widget-spark-fill" x1="0" y1="0" x2="0" y2="1">' .
				'<stop offset="0%%" stop-color="var(--brand)" stop-opacity="0.35" />' .
				'<stop offset="100%%" stop-color="var(--brand)" stop-opacity="0" />' .
				'</linearGradient></defs>' .
				'<polygon class="bbk-widget-spark-area" points="%3$s" />' .
				'<polyline class="bbk-widget-spark-line" points="%4$s" />' .
				'<circle class="bbk-widget-spark-dot" cx="%5$s" cy="%6$s" r="2.2" />' .
				'</svg>',
			esc_attr( (string) $width ),
			esc_attr( (string) $height ),
			esc_attr( $area_points ),
			esc_attr( $line_points ),
			esc_attr( (string) $end_point[0] ),
			esc_attr( (string) $end_point[1] )
		);
	}
}
