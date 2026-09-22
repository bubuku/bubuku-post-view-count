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

		$dot   = '<span class="bbk-widget-dot"></span>';
		$title = $dot . esc_html__( 'Post views summary', 'bubuku-post-view-count' );

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
			<div class="bbk-widget-stats">
				<div class="bbk-widget-stat">
					<span class="bbk-widget-stat-label"><?php esc_html_e( 'Total views', 'bubuku-post-view-count' ); ?></span>
					<span class="bbk-widget-stat-value"><?php echo esc_html( $snapshot['total_views_formatted'] ); ?></span>
				</div>
				<div class="bbk-widget-stat">
					<span class="bbk-widget-stat-label"><?php esc_html_e( 'Last 30 days', 'bubuku-post-view-count' ); ?></span>
					<span class="bbk-widget-stat-value">
						<?php echo esc_html( $snapshot['current_views_formatted'] ); ?>
						<?php echo wp_kses( $this->delta_badge( $snapshot['delta'], $snapshot['delta_pct'] ), array( 'span' => array( 'class' => array() ) ) ); ?>
					</span>
				</div>
				<div class="bbk-widget-stat">
					<span class="bbk-widget-stat-label"><?php esc_html_e( 'AI referrals', 'bubuku-post-view-count' ); ?></span>
					<span class="bbk-widget-stat-value"><?php echo esc_html( $snapshot['ai_referral_views_formatted'] ); ?></span>
				</div>
				<div class="bbk-widget-stat">
					<span class="bbk-widget-stat-label"><?php esc_html_e( 'AI bot crawling', 'bubuku-post-view-count' ); ?></span>
					<span class="bbk-widget-stat-value">
						<?php
						$crawler_display = $snapshot['ai_crawler_tracking_enabled']
							? $snapshot['ai_crawler_views_formatted']
							: '—';
						echo esc_html( $crawler_display );
						?>
					</span>
				</div>
			</div>

			<div class="bbk-widget-section">
				<h3><?php esc_html_e( 'Last 7 days', 'bubuku-post-view-count' ); ?></h3>
				<?php echo $this->render_sparkline( $snapshot['last_7_days'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_sparkline() escapes every attribute itself; see its own docblock. ?>
			</div>

			<?php if ( ! empty( $snapshot['warming'] ) ) : ?>
				<p class="bbk-widget-warming"><?php esc_html_e( 'Calculating the summary for the first time — refresh shortly.', 'bubuku-post-view-count' ); ?></p>
			<?php endif; ?>

			<div class="bbk-widget-footer">
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
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . $this->plugin_name ) ); ?>">
					<?php esc_html_e( 'See stats', 'bubuku-post-view-count' ); ?> &rsaquo;
				</a>
			</div>
		</div>
		<?php
		// All HTML above is either translator-controlled strings passed through
		// esc_html()/esc_url()/wp_kses(), or attributes escaped in render_sparkline().
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ob_get_clean();
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
	 * Dependency-free 7-day sparkline: a single SVG polyline with a filled area under
	 * it, normalized to the series' own max so it always fills the available height.
	 * `viewBox` + `preserveAspectRatio="none"` let the CSS scale it to the postbox
	 * width without any JS. Every coordinate is a plain float formatted by PHP, so
	 * `esc_attr()` is enough — there is no user input on this path.
	 *
	 * @param array<int, array{day:string,views:int}> $days Exactly 7 entries, oldest first.
	 * @return string
	 */
	private function render_sparkline( array $days ): string {
		$total = array_sum( array_column( $days, 'views' ) );

		if ( 0 === $total ) {
			return '<p class="bbk-widget-spark-empty">' . esc_html__( 'No views recorded in the last 7 days.', 'bubuku-post-view-count' ) . '</p>';
		}

		$width  = 100;
		$height = 30;
		$max    = max( array_column( $days, 'views' ) );
		$count  = count( $days );
		$step   = $count > 1 ? $width / ( $count - 1 ) : 0;

		$points = array();
		foreach ( array_values( $days ) as $index => $day ) {
			$x        = round( $index * $step, 2 );
			$y        = $max > 0 ? round( $height - ( ( (int) $day['views'] / $max ) * $height ), 2 ) : $height;
			$points[] = "{$x},{$y}";
		}

		$line_points = implode( ' ', $points );
		$area_points = "0,{$height} {$line_points} {$width},{$height}";

		return sprintf(
			'<svg class="bbk-widget-spark" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" aria-hidden="true"><polygon class="bbk-widget-spark-area" points="%3$s" /><polyline class="bbk-widget-spark-line" points="%4$s" /></svg>',
			esc_attr( (string) $width ),
			esc_attr( (string) $height ),
			esc_attr( $area_points ),
			esc_attr( $line_points )
		);
	}
}
