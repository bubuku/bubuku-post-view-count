import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import StatCard from './StatCard';
import { bbkFetch } from '../App';
import { formatNumber } from '../format';

const ICON_EYE = (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		aria-hidden="true"
	>
		<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" />
		<circle cx="12" cy="12" r="3" />
	</svg>
);

const ICON_TRENDING = (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		aria-hidden="true"
	>
		<polyline points="23 6 13.5 15.5 8.5 10.5 1 18" />
		<polyline points="17 6 23 6 23 12" />
	</svg>
);

const ICON_AI = (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		aria-hidden="true"
	>
		<circle cx="12" cy="12" r="10" />
		<path d="M12 8v4l3 3" />
	</svg>
);

const ICON_BOT = (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		aria-hidden="true"
	>
		<rect x="3" y="9" width="18" height="11" rx="2" />
		<circle cx="8.5" cy="14.5" r="1.25" fill="currentColor" stroke="none" />
		<circle
			cx="15.5"
			cy="14.5"
			r="1.25"
			fill="currentColor"
			stroke="none"
		/>
		<path d="M12 9V5" />
		<circle cx="12" cy="3.5" r="1.5" />
	</svg>
);

/**
 * Formats the 30-day delta as "+123 (+4.5%)" / "-123 (-4.5%)", same convention
 * as the "Rising & falling" table in StatsPanel.
 *
 * @param {number}      delta    Signed view delta.
 * @param {number|null} deltaPct Signed percentage delta, or null.
 * @return {string} The formatted delta.
 */
function formatDelta( delta, deltaPct ) {
	const sign = delta >= 0 ? '+' : '';
	const pct =
		null !== deltaPct ? ` (${ sign }${ formatNumber( deltaPct ) }%)` : '';
	return `${ sign }${ formatNumber( delta ) }${ pct }`;
}

/**
 * 'up' when the 30-day comparison improved, 'down' when it worsened, null
 * when it didn't change — the trend StatCard colors its note by.
 *
 * @param {number} delta Signed view delta.
 * @return {'up'|'down'|null} The trend direction, or null when delta is 0.
 */
function trendDirection( delta ) {
	if ( 0 === delta ) {
		return null;
	}

	return delta > 0 ? 'up' : 'down';
}

/**
 * SummaryCards — four "at a glance" figures shown above the existing
 * DashboardCard sections in the Stats tab (docs/DASHBOARD-SUMMARY-PLAN.md §3).
 * One request to the new `/stats/overview` endpoint, loaded and failed
 * independently from the other Stats blocks so a slow/broken card never
 * blocks the rest of the tab.
 *
 * @param {Object} props
 * @param {Object} props.context GET /settings response (used for the AI
 *                               crawler tracking flag).
 */
const SummaryCards = ( { context } ) => {
	const [ overview, setOverview ] = useState( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		bbkFetch( '/stats/overview' )
			.then( ( body ) => setOverview( body ) )
			.catch( () => setFailed( true ) );
	}, [] );

	const loading = ! overview && ! failed;

	const totalNote = overview
		? sprintf(
				/* translators: %s: number of posts with at least one view */
				__( '%s posts with traffic', 'bubuku-post-view-count' ),
				formatNumber( overview.posts_with_traffic )
		  )
		: null;

	const currentNote = overview
		? formatDelta( overview.delta, overview.delta_pct )
		: null;
	const currentTrend = overview ? trendDirection( overview.delta ) : null;

	const aiCrawlingEnabled = context?.ai_crawler_tracking;

	return (
		<div className="bbk-summary-cards">
			<StatCard
				icon={ ICON_EYE }
				label={ __( 'Total views', 'bubuku-post-view-count' ) }
				value={ overview ? formatNumber( overview.total_views ) : null }
				note={ totalNote }
				loading={ loading }
			/>
			<StatCard
				icon={ ICON_TRENDING }
				label={ __( 'Last 30 days', 'bubuku-post-view-count' ) }
				value={
					overview ? formatNumber( overview.current_views ) : null
				}
				note={ currentNote }
				trend={ currentTrend }
				loading={ loading }
			/>
			<StatCard
				icon={ ICON_AI }
				label={ __( 'AI referrals', 'bubuku-post-view-count' ) }
				value={
					overview ? formatNumber( overview.ai_referral_views ) : null
				}
				note={ __( 'Last 3 months', 'bubuku-post-view-count' ) }
				loading={ loading }
			/>
			<StatCard
				icon={ ICON_BOT }
				label={ __( 'AI bot crawling', 'bubuku-post-view-count' ) }
				value={
					overview ? formatNumber( overview.ai_crawler_views ) : null
				}
				note={
					aiCrawlingEnabled
						? __( 'Last 3 months', 'bubuku-post-view-count' )
						: __( 'Disabled in settings', 'bubuku-post-view-count' )
				}
				loading={ loading }
			/>
		</div>
	);
};

export default SummaryCards;
