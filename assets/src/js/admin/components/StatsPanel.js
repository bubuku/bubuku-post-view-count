import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import ExpandableViewsTable from './ExpandableViewsTable';
import DashboardCard from './DashboardCard';
import DataTable from './DataTable';
import SummaryCards from './SummaryCards';
import TrendChart from './TrendChart';
import CrawlerCategoryDot from './CrawlerCategoryDot';
import CrawlerCategoryLegend from './CrawlerCategoryLegend';
import { bbkFetch } from '../App';
import { formatNumber } from '../format';

const AI_ASSISTANT_LABELS = {
	chatgpt: __( 'ChatGPT', 'bubuku-post-view-count' ),
	claude: __( 'Claude', 'bubuku-post-view-count' ),
	perplexity: __( 'Perplexity', 'bubuku-post-view-count' ),
	copilot: __( 'Copilot', 'bubuku-post-view-count' ),
	gemini: __( 'Gemini', 'bubuku-post-view-count' ),
	other: __( 'Other AI assistant', 'bubuku-post-view-count' ),
	// Views recorded before per-assistant tracking existed — the specific
	// assistant was never stored, so there is nothing to expand into.
	unknown: __( 'Older views (assistant unknown)', 'bubuku-post-view-count' ),
};

// Fixed colour per assistant (CSS custom properties from config/_aliases.scss),
// so a line keeps its colour whatever its position in the response.
const AI_ASSISTANT_COLORS = {
	chatgpt: '--chart-chatgpt',
	claude: '--chart-claude',
	perplexity: '--chart-perplexity',
	copilot: '--chart-copilot',
	gemini: '--chart-gemini',
	other: '--chart-other',
};

const ICON_TREND = (
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

const ICON_DEVICE = (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		aria-hidden="true"
	>
		<rect x="4" y="2" width="16" height="20" rx="2" />
		<line x1="12" y1="18" x2="12.01" y2="18" />
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

function parseIsoDate( value ) {
	return new Date( `${ value.slice( 0, 10 ) }T00:00:00Z` );
}

function toIsoDate( date ) {
	return date.toISOString().slice( 0, 10 );
}

function bucketStart( date, granularity ) {
	const result = new Date( date );

	if ( granularity === 'week' ) {
		const daysSinceMonday = ( result.getUTCDay() + 6 ) % 7;
		result.setUTCDate( result.getUTCDate() - daysSinceMonday );
	} else if ( granularity === 'month' ) {
		result.setUTCDate( 1 );
	}

	return result;
}

function nextBucket( date, granularity ) {
	const result = new Date( date );

	if ( granularity === 'day' ) {
		result.setUTCDate( result.getUTCDate() + 1 );
	} else if ( granularity === 'week' ) {
		result.setUTCDate( result.getUTCDate() + 7 );
	} else {
		result.setUTCMonth( result.getUTCMonth() + 1 );
	}

	return result;
}

/**
 * Adds zero-value buckets so horizontal spacing reflects elapsed time rather
 * than the number of database rows. Dates before daily collection started are
 * not invented as zeroes.
 *
 * @param {Array}       rows          Sparse API series.
 * @param {Object|null} range         Inclusive API range (`from` / `to`).
 * @param {string}      granularity   day, week or month.
 * @param {string|null} dataAvailable First date with daily data.
 * @return {Array} Continuous series for the canvas.
 */
function completeTrend( rows, range, granularity, dataAvailable ) {
	if ( ! rows.length || ! range?.from || ! range?.to ) {
		return rows;
	}

	const availableDay = dataAvailable?.slice( 0, 10 ) || range.from;
	const effectiveFrom = availableDay > range.from ? availableDay : range.from;
	const values = new Map(
		rows.map( ( row ) => [ row.bucket.slice( 0, 10 ), row.total_views ] )
	);
	const series = [];
	let cursor = bucketStart( parseIsoDate( effectiveFrom ), granularity );
	const end = bucketStart( parseIsoDate( range.to ), granularity );

	while ( cursor <= end ) {
		const bucket = toIsoDate( cursor );
		series.push( {
			bucket,
			total_views: Number( values.get( bucket ) || 0 ),
		} );
		cursor = nextBucket( cursor, granularity );
	}

	return series;
}

/**
 * Length in whole days of an inclusive `{from, to}` API range, or null when
 * either bound is missing — used to describe the "Rising & falling" card's
 * actually-compared period, which `Query::momentum()` shortens on a site
 * whose daily history is younger than 2x the requested period_days (see
 * docs/PENDING-1.3.1-FIXES.md §3).
 *
 * @param {Object|null} range Inclusive range with `from`/`to` (Y-m-d).
 * @return {number|null} Number of days, or null.
 */
function periodLengthDays( range ) {
	if ( ! range?.from || ! range?.to ) {
		return null;
	}

	const days = Math.round(
		( parseIsoDate( range.to ) - parseIsoDate( range.from ) ) / 86400000
	);

	return days + 1;
}

function formatDate( value ) {
	return new Intl.DateTimeFormat( document.documentElement.lang || 'es', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	} ).format( parseIsoDate( value ) );
}

const SOURCE_OPTIONS = [
	{ value: 'all', label: __( 'All views', 'bubuku-post-view-count' ) },
	{
		value: 'ai_referrals',
		label: __( 'AI referrals', 'bubuku-post-view-count' ),
	},
];

const GRANULARITY_OPTIONS = [
	{ value: 'day', label: __( 'Day', 'bubuku-post-view-count' ) },
	{ value: 'week', label: __( 'Week', 'bubuku-post-view-count' ) },
	{ value: 'month', label: __( 'Month', 'bubuku-post-view-count' ) },
];

/**
 * StatsPanel — the 4 read-only stats sections (docs/PENDING-ADMIN-UI-REACT.md
 * Fase 6), each loading and failing independently, over the existing
 * Api\TrendsApi endpoints. No new backend for this panel.
 *
 * @param {Object} props
 * @param {Object} props.context GET /settings response, including the first
 *                               available daily date and AI tracking flag.
 */
const StatsPanel = ( { context } ) => {
	const [ granularity, setGranularity ] = useState( 'day' );
	const [ source, setSource ] = useState( 'all' );
	const [ trend, setTrend ] = useState( [] );
	const [ trendSeries, setTrendSeries ] = useState( null );
	const [ trendRange, setTrendRange ] = useState( null );
	const [ trendLoading, setTrendLoading ] = useState( true );

	const [ momentum, setMomentum ] = useState( null );
	const [ dimsViewport, setDimsViewport ] = useState( null );
	const [ dimsReferrer, setDimsReferrer ] = useState( null );
	const [ viewportCoverage, setViewportCoverage ] = useState( null );
	const [ referrerCoverage, setReferrerCoverage ] = useState( null );
	const [ aiTraffic, setAiTraffic ] = useState( null );

	useEffect( () => {
		const complete = ( rows, range ) =>
			completeTrend(
				rows,
				range,
				granularity,
				context?.daily_data_since || null
			);

		setTrendLoading( true );
		bbkFetch( `/trends?granularity=${ granularity }&source=${ source }` )
			.then( ( body ) => {
				const range = body.range || null;

				setTrendRange( range );
				setTrend( complete( body.trend || [], range ) );
				setTrendSeries(
					'ai_referrals' === source
						? ( body.series || [] ).map( ( item ) => ( {
								key: item.assistant,
								label:
									AI_ASSISTANT_LABELS[ item.assistant ] ||
									item.assistant,
								color:
									AI_ASSISTANT_COLORS[ item.assistant ] ||
									AI_ASSISTANT_COLORS.other,
								trend: complete( item.trend || [], range ),
						  } ) )
						: null
				);
			} )
			.catch( () => {
				setTrend( [] );
				setTrendSeries( 'ai_referrals' === source ? [] : null );
				setTrendRange( null );
			} )
			.finally( () => setTrendLoading( false ) );
	}, [ granularity, source, context?.daily_data_since ] );

	useEffect( () => {
		bbkFetch( '/trends/momentum' )
			.then( ( body ) => setMomentum( body ) )
			.catch( () => setMomentum( { rising: [], falling: [] } ) );
	}, [] );

	useEffect( () => {
		bbkFetch( '/trends/dims?dimension=viewport' )
			.then( ( body ) => {
				setDimsViewport( body.breakdown || [] );
				setViewportCoverage( body.coverage || null );
			} )
			.catch( () => setDimsViewport( [] ) );

		bbkFetch( '/trends/dims?dimension=referrer' )
			.then( ( body ) => {
				setDimsReferrer( body.breakdown || [] );
				setReferrerCoverage( body.coverage || null );
			} )
			.catch( () => setDimsReferrer( [] ) );
	}, [] );

	useEffect( () => {
		bbkFetch( '/trends/ai-traffic' )
			.then( ( body ) => setAiTraffic( body ) )
			.catch( () =>
				setAiTraffic( {
					referrals: { views: 0 },
					crawlers: [],
					ai_crawler_tracking_enabled: false,
				} )
			);
	}, [] );

	const dimsColumns = [
		{ key: 'value', label: __( 'Value', 'bubuku-post-view-count' ) },
		{
			key: 'views',
			label: __( 'Views', 'bubuku-post-view-count' ),
			numeric: true,
		},
	];
	const coverageLabel = ( coverage, loading ) => {
		if ( loading ) {
			return __( 'Loading data…', 'bubuku-post-view-count' );
		}

		if (
			coverage?.percentage === null ||
			coverage?.percentage === undefined
		) {
			return __(
				'Coverage is not available yet.',
				'bubuku-post-view-count'
			);
		}

		return sprintf(
			/* translators: 1: percentage, 2: classified views, 3: total accepted views */
			__(
				'Coverage: %1$s%% (%2$s of %3$s accepted views). Categories are approximate.',
				'bubuku-post-view-count'
			),
			formatNumber( coverage.percentage ),
			formatNumber( coverage.tracked_views ),
			formatNumber( coverage.total_views )
		);
	};

	const momentumColumns = [
		{
			key: 'title',
			label: __( 'Content', 'bubuku-post-view-count' ),
			render: ( row ) => (
				<a href={ row.url } target="_blank" rel="noopener noreferrer">
					{ row.title }
				</a>
			),
		},
		{
			key: 'delta',
			label: __( 'Change', 'bubuku-post-view-count' ),
			numeric: true,
			render: ( row ) => {
				const sign = row.delta >= 0 ? '+' : '';
				const pct =
					null !== row.delta_pct
						? ` (${ sign }${ formatNumber( row.delta_pct ) }%)`
						: '';
				return `${ sign }${ formatNumber( row.delta ) }${ pct }`;
			},
		},
	];

	const totalLabel = __( 'Total', 'bubuku-post-view-count' );

	const crawlerCatalog = context?.ai_crawler_catalog || {};
	const crawlerCategories = crawlerCatalog.categories || {};
	const crawlerCategoryByBot = ( crawlerCatalog.crawlers || [] ).reduce(
		( map, crawler ) => {
			map[ crawler.name ] = crawler.category;
			return map;
		},
		{}
	);

	const trendTotal = trend.reduce(
		( total, row ) => total + row.total_views,
		0
	);
	const granularityLabel = GRANULARITY_OPTIONS.find(
		( option ) => option.value === granularity
	)?.label.toLocaleLowerCase();
	const availableDay = context?.daily_data_since?.slice( 0, 10 );
	const hasPartialHistory =
		trendRange && availableDay && availableDay > trendRange.from;
	let trendLabel = null;
	let trendRangeLabel = null;

	if ( trendRange && ! trendLoading ) {
		const displayedFrom = hasPartialHistory
			? availableDay
			: trendRange.from;
		trendRangeLabel = sprintf(
			/* translators: 1: first displayed date, 2: last displayed date. */
			__( 'Period shown: from %1$s to %2$s.', 'bubuku-post-view-count' ),
			formatDate( displayedFrom ),
			formatDate( trendRange.to )
		);
		if ( 'ai_referrals' === source ) {
			trendLabel = hasPartialHistory
				? sprintf(
						/* translators: 1: number of views, 2: first available date, 3: grouping unit. */
						_n(
							'%1$s view from AI assistants since %2$s, grouped by %3$s.',
							'%1$s views from AI assistants since %2$s, grouped by %3$s.',
							trendTotal,
							'bubuku-post-view-count'
						),
						formatNumber( trendTotal ),
						formatDate( availableDay ),
						granularityLabel
				  )
				: sprintf(
						/* translators: 1: number of views, 2: grouping unit. */
						_n(
							'%1$s view from AI assistants in the last 3 months, grouped by %2$s.',
							'%1$s views from AI assistants in the last 3 months, grouped by %2$s.',
							trendTotal,
							'bubuku-post-view-count'
						),
						formatNumber( trendTotal ),
						granularityLabel
				  );
		} else {
			trendLabel = hasPartialHistory
				? sprintf(
						/* translators: 1: number of views, 2: first available date, 3: grouping unit. */
						_n(
							'%1$s view received since %2$s, grouped by %3$s.',
							'%1$s views received since %2$s, grouped by %3$s.',
							trendTotal,
							'bubuku-post-view-count'
						),
						formatNumber( trendTotal ),
						formatDate( availableDay ),
						granularityLabel
				  )
				: sprintf(
						/* translators: 1: number of views, 2: grouping unit. */
						_n(
							'%1$s view received in the last 3 months, grouped by %2$s.',
							'%1$s views received in the last 3 months, grouped by %2$s.',
							trendTotal,
							'bubuku-post-view-count'
						),
						formatNumber( trendTotal ),
						granularityLabel
				  );
		}
	}

	// "Rising & falling" card: Query::momentum() shortens the comparison when the
	// daily aggregate doesn't yet cover two full requested periods (e.g. a recently
	// updated site), instead of comparing against an all-zero "previous" period —
	// see docs/PENDING-1.3.1-FIXES.md §3. Reflect the period actually compared, and
	// explain the shortfall instead of showing a bare, misleading "no changes".
	const momentumDays = periodLengthDays( momentum?.period?.current );
	const momentumClaim =
		momentumDays && ! momentum?.insufficient_history
			? sprintf(
					/* translators: 1: number of days compared (used for both the current and the previous period). */
					__(
						'Comparison between the last %1$s days and the previous %1$s.',
						'bubuku-post-view-count'
					),
					formatNumber( momentumDays )
			  )
			: __(
					'Comparison between the last 30 days and the previous 30.',
					'bubuku-post-view-count'
			  );
	let momentumHistoryNote = null;

	if ( momentum?.insufficient_history ) {
		const historySince = momentum.history_since?.slice( 0, 10 );

		momentumHistoryNote =
			momentumDays && momentumDays > 1 && historySince
				? sprintf(
						/* translators: 1: shortened comparison length in days (used for both periods), 2: first date with daily history, 3: originally requested comparison length in days (used for both periods). */
						__(
							'Comparison shortened to %1$s vs %1$s days: daily history is only available since %2$s. A full %3$s-vs-%3$s comparison needs more time yet.',
							'bubuku-post-view-count'
						),
						formatNumber( momentumDays ),
						formatDate( historySince ),
						formatNumber( momentum.requested_period_days )
				  )
				: __(
						'Not enough daily history yet to compare two periods — check back once the site has a few more days of data.',
						'bubuku-post-view-count'
				  );
	}

	const momentumEmptyLabel = momentum?.insufficient_history
		? __(
				'Not enough data yet for this comparison.',
				'bubuku-post-view-count'
		  )
		: __( 'No relevant changes in this period.', 'bubuku-post-view-count' );

	return (
		<div className="bbk-stats-panel">
			<SummaryCards context={ context } />

			<DashboardCard
				icon={ ICON_TREND }
				title={ __( 'Views over time', 'bubuku-post-view-count' ) }
				claim={ __(
					'Grouping only changes how the trend is displayed.',
					'bubuku-post-view-count'
				) }
				headerMeta={
					<>
						<select
							className="bbk-input"
							value={ source }
							aria-label={ __(
								'Source',
								'bubuku-post-view-count'
							) }
							onChange={ ( e ) => setSource( e.target.value ) }
						>
							{ SOURCE_OPTIONS.map( ( option ) => (
								<option
									key={ option.value }
									value={ option.value }
								>
									{ option.label }
								</option>
							) ) }
						</select>
						<select
							className="bbk-input"
							value={ granularity }
							aria-label={ __(
								'Grouping',
								'bubuku-post-view-count'
							) }
							onChange={ ( e ) =>
								setGranularity( e.target.value )
							}
						>
							{ GRANULARITY_OPTIONS.map( ( option ) => (
								<option
									key={ option.value }
									value={ option.value }
								>
									{ option.label }
								</option>
							) ) }
						</select>
					</>
				}
			>
				{ trendLabel && (
					<p className="bbk-trend-chart__summary">{ trendLabel }</p>
				) }
				{ trendRangeLabel && (
					<p className="bbk-trend-chart__range">
						{ trendRangeLabel }
					</p>
				) }
				<div className="bbk-trend-chart">
					<TrendChart
						trend={ trend }
						series={ trendSeries || undefined }
						granularity={ granularity }
						loading={ trendLoading }
						noDataLabel={
							'ai_referrals' === source
								? __(
										'No views from AI assistants in this period.',
										'bubuku-post-view-count'
								  )
								: __(
										'Not enough data yet to draw the chart.',
										'bubuku-post-view-count'
								  )
						}
					/>
				</div>
				{ 'ai_referrals' === source && ! trendLoading && (
					<p className="bbk-trend-chart__range">
						{ __(
							'AI-referred views recorded before per-assistant tracking existed are not part of these lines.',
							'bubuku-post-view-count'
						) }
					</p>
				) }
			</DashboardCard>

			<DashboardCard
				icon={ ICON_TREND }
				title={ __( 'Rising & falling', 'bubuku-post-view-count' ) }
				claim={ momentumClaim }
				animationDelay=".1s"
			>
				{ momentumHistoryNote && (
					<p className="bbk-trend-chart__range">
						{ momentumHistoryNote }
					</p>
				) }
				<div className="bbk-two-columns">
					<div>
						<h3>{ __( 'Rising', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ momentumColumns }
							rows={ momentum?.rising || [] }
							loading={ null === momentum }
							rowKey={ ( row ) => row.url }
							emptyLabel={ momentumEmptyLabel }
						/>
					</div>
					<div>
						<h3>{ __( 'Falling', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ momentumColumns }
							rows={ momentum?.falling || [] }
							loading={ null === momentum }
							rowKey={ ( row ) => row.url }
							emptyLabel={ momentumEmptyLabel }
						/>
					</div>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_DEVICE }
				title={ __( 'Device & referrer', 'bubuku-post-view-count' ) }
				claim={ __( 'Last 3 months.', 'bubuku-post-view-count' ) }
				animationDelay=".15s"
			>
				<div className="bbk-two-columns">
					<div>
						<h3>{ __( 'Device', 'bubuku-post-view-count' ) }</h3>
						<p>
							{ coverageLabel(
								viewportCoverage,
								null === dimsViewport
							) }
						</p>
						<DataTable
							columns={ dimsColumns }
							rows={ dimsViewport || [] }
							loading={ null === dimsViewport }
							rowKey={ ( row ) => row.value }
							emptyLabel={ __(
								'Not enough data yet.',
								'bubuku-post-view-count'
							) }
							showTotal
							totalLabel={ totalLabel }
						/>
					</div>
					<div>
						<h3>{ __( 'Referrer', 'bubuku-post-view-count' ) }</h3>
						<p>
							{ coverageLabel(
								referrerCoverage,
								null === dimsReferrer
							) }
						</p>
						<DataTable
							columns={ dimsColumns }
							rows={ dimsReferrer || [] }
							loading={ null === dimsReferrer }
							rowKey={ ( row ) => row.value }
							emptyLabel={ __(
								'Not enough data yet.',
								'bubuku-post-view-count'
							) }
							showTotal
							totalLabel={ totalLabel }
						/>
					</div>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_AI }
				title={ __( 'AI traffic', 'bubuku-post-view-count' ) }
				claim={ __(
					'Last 3 months. Referrals: human visitors arriving from an AI assistant (included in the view count). Crawling: requests from known AI bots, counted separately.',
					'bubuku-post-view-count'
				) }
				animationDelay=".2s"
			>
				<div className="bbk-two-columns">
					<div>
						<h3>
							{ __( 'AI referrals', 'bubuku-post-view-count' ) }
						</h3>
						<ExpandableViewsTable
							rows={ aiTraffic?.referrals?.by_assistant || [] }
							loading={ null === aiTraffic }
							labelKey="assistant"
							headerLabel={ __( 'AI', 'bubuku-post-view-count' ) }
							labels={ AI_ASSISTANT_LABELS }
							totalLabel={ totalLabel }
							emptyLabel={ __(
								'No views from AI assistants in this period.',
								'bubuku-post-view-count'
							) }
							renderIndicator={ () => (
								<CrawlerCategoryDot
									category="assistant"
									label={
										crawlerCategories.assistant ||
										__(
											'AI assistant',
											'bubuku-post-view-count'
										)
									}
								/>
							) }
						/>
					</div>
					<div>
						<h3>
							{ __(
								'AI bot crawling',
								'bubuku-post-view-count'
							) }
						</h3>
						<ExpandableViewsTable
							rows={ aiTraffic?.crawlers || [] }
							loading={ null === aiTraffic }
							labelKey="bot"
							headerLabel={ __(
								'Bot',
								'bubuku-post-view-count'
							) }
							totalLabel={ totalLabel }
							emptyLabel={
								context?.ai_crawler_tracking
									? __(
											'No crawling recorded.',
											'bubuku-post-view-count'
									  )
									: __(
											'AI bot tracking is disabled in settings.',
											'bubuku-post-view-count'
									  )
							}
							renderIndicator={ ( bot ) => {
								const category = crawlerCategoryByBot[ bot ];
								return category ? (
									<CrawlerCategoryDot
										category={ category }
										label={
											crawlerCategories[ category ] ||
											category
										}
									/>
								) : null;
							} }
						/>
					</div>
				</div>
				{ ( ( aiTraffic?.referrals?.by_assistant || [] ).length > 0 ||
					( aiTraffic?.crawlers || [] ).length > 0 ) && (
					<CrawlerCategoryLegend categories={ crawlerCategories } />
				) }
			</DashboardCard>
		</div>
	);
};

export default StatsPanel;
