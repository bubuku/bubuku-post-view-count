import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import DashboardCard from './DashboardCard';
import DataTable from './DataTable';
import TrendChart from './TrendChart';
import { bbkFetch } from '../App';

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

function formatDate( value ) {
	return new Intl.DateTimeFormat( document.documentElement.lang || 'es', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	} ).format( parseIsoDate( value ) );
}

const GRANULARITY_OPTIONS = [
	{ value: 'day', label: __( 'Día', 'bubuku-post-view-count' ) },
	{ value: 'week', label: __( 'Semana', 'bubuku-post-view-count' ) },
	{ value: 'month', label: __( 'Mes', 'bubuku-post-view-count' ) },
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
	const [ trend, setTrend ] = useState( [] );
	const [ trendRange, setTrendRange ] = useState( null );

	const [ momentum, setMomentum ] = useState( null );
	const [ dimsViewport, setDimsViewport ] = useState( null );
	const [ dimsReferrer, setDimsReferrer ] = useState( null );
	const [ aiTraffic, setAiTraffic ] = useState( null );

	useEffect( () => {
		bbkFetch( `/trends?granularity=${ granularity }` )
			.then( ( body ) => {
				setTrendRange( body.range || null );
				setTrend(
					completeTrend(
						body.trend || [],
						body.range || null,
						granularity,
						context?.daily_data_since || null
					)
				);
			} )
			.catch( () => {
				setTrend( [] );
				setTrendRange( null );
			} );
	}, [ granularity, context?.daily_data_since ] );

	useEffect( () => {
		bbkFetch( '/trends/momentum' )
			.then( ( body ) => setMomentum( body ) )
			.catch( () => setMomentum( { rising: [], falling: [] } ) );
	}, [] );

	useEffect( () => {
		bbkFetch( '/trends/dims?dimension=viewport' )
			.then( ( body ) => setDimsViewport( body.breakdown || [] ) )
			.catch( () => setDimsViewport( [] ) );

		bbkFetch( '/trends/dims?dimension=referrer' )
			.then( ( body ) => setDimsReferrer( body.breakdown || [] ) )
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
		{ key: 'value', label: __( 'Valor', 'bubuku-post-view-count' ) },
		{
			key: 'views',
			label: __( 'Vistas', 'bubuku-post-view-count' ),
			numeric: true,
		},
	];

	const momentumColumns = [
		{
			key: 'title',
			label: __( 'Contenido', 'bubuku-post-view-count' ),
			render: ( row ) => (
				<a href={ row.url } target="_blank" rel="noopener noreferrer">
					{ row.title }
				</a>
			),
		},
		{
			key: 'delta',
			label: __( 'Cambio', 'bubuku-post-view-count' ),
			numeric: true,
			render: ( row ) => {
				const sign = row.delta >= 0 ? '+' : '';
				const pct =
					null !== row.delta_pct
						? ` (${ sign }${ row.delta_pct }%)`
						: '';
				return `${ sign }${ row.delta }${ pct }`;
			},
		},
	];

	const crawlerColumns = [
		{ key: 'bot', label: __( 'Bot', 'bubuku-post-view-count' ) },
		{
			key: 'views',
			label: __( 'Vistas', 'bubuku-post-view-count' ),
			numeric: true,
		},
	];
	const aiReferralColumns = [
		{
			key: 'title',
			label: __( 'Contenido', 'bubuku-post-view-count' ),
			render: ( row ) => (
				<a href={ row.url } target="_blank" rel="noopener noreferrer">
					{ row.title }
				</a>
			),
		},
		{
			key: 'views',
			label: __( 'Vistas', 'bubuku-post-view-count' ),
			numeric: true,
		},
	];

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

	if ( trendRange ) {
		const displayedFrom = hasPartialHistory
			? availableDay
			: trendRange.from;
		trendRangeLabel = sprintf(
			/* translators: 1: first displayed date, 2: last displayed date. */
			__(
				'Periodo mostrado: del %1$s al %2$s.',
				'bubuku-post-view-count'
			),
			formatDate( displayedFrom ),
			formatDate( trendRange.to )
		);
		trendLabel = hasPartialHistory
			? sprintf(
					/* translators: 1: number of views, 2: first available date, 3: grouping unit. */
					_n(
						'%1$d visita recibida desde el %2$s, agrupada por %3$s.',
						'%1$d visitas recibidas desde el %2$s, agrupadas por %3$s.',
						trendTotal,
						'bubuku-post-view-count'
					),
					trendTotal,
					formatDate( availableDay ),
					granularityLabel
			  )
			: sprintf(
					/* translators: 1: number of views, 2: grouping unit. */
					_n(
						'%1$d visita recibida en los últimos 3 meses, agrupada por %2$s.',
						'%1$d visitas recibidas en los últimos 3 meses, agrupadas por %2$s.',
						trendTotal,
						'bubuku-post-view-count'
					),
					trendTotal,
					granularityLabel
			  );
	}

	return (
		<div className="bbk-stats-panel">
			<DashboardCard
				icon={ ICON_TREND }
				title={ __( 'Evolución de vistas', 'bubuku-post-view-count' ) }
				claim={ __(
					'La agrupación solo cambia cómo se representa la evolución.',
					'bubuku-post-view-count'
				) }
				headerMeta={
					<select
						className="bbk-input"
						value={ granularity }
						onChange={ ( e ) => setGranularity( e.target.value ) }
					>
						{ GRANULARITY_OPTIONS.map( ( option ) => (
							<option key={ option.value } value={ option.value }>
								{ option.label }
							</option>
						) ) }
					</select>
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
						granularity={ granularity }
						noDataLabel={ __(
							'Todavía no hay datos suficientes para dibujar la gráfica.',
							'bubuku-post-view-count'
						) }
					/>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_TREND }
				title={ __( 'En alza y en caída', 'bubuku-post-view-count' ) }
				claim={ __(
					'Comparación entre los últimos 30 días y los 30 anteriores.',
					'bubuku-post-view-count'
				) }
				animationDelay=".1s"
			>
				<div className="bbk-two-columns">
					<div>
						<h3>{ __( 'En alza', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ momentumColumns }
							rows={ momentum?.rising || [] }
							rowKey={ ( row ) => row.url }
							emptyLabel={ __(
								'Sin cambios relevantes en este periodo.',
								'bubuku-post-view-count'
							) }
						/>
					</div>
					<div>
						<h3>{ __( 'En caída', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ momentumColumns }
							rows={ momentum?.falling || [] }
							rowKey={ ( row ) => row.url }
							emptyLabel={ __(
								'Sin cambios relevantes en este periodo.',
								'bubuku-post-view-count'
							) }
						/>
					</div>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_DEVICE }
				title={ __(
					'Dispositivo y procedencia',
					'bubuku-post-view-count'
				) }
				claim={ __( 'Últimos 3 meses.', 'bubuku-post-view-count' ) }
				animationDelay=".15s"
			>
				<div className="bbk-two-columns">
					<div>
						<h3>
							{ __( 'Dispositivo', 'bubuku-post-view-count' ) }
						</h3>
						<DataTable
							columns={ dimsColumns }
							rows={ dimsViewport || [] }
							rowKey={ ( row ) => row.value }
							emptyLabel={ __(
								'Todavía no hay datos suficientes.',
								'bubuku-post-view-count'
							) }
						/>
					</div>
					<div>
						<h3>
							{ __( 'Procedencia', 'bubuku-post-view-count' ) }
						</h3>
						<DataTable
							columns={ dimsColumns }
							rows={ dimsReferrer || [] }
							rowKey={ ( row ) => row.value }
							emptyLabel={ __(
								'Todavía no hay datos suficientes.',
								'bubuku-post-view-count'
							) }
						/>
					</div>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_AI }
				title={ __( 'Tráfico de IA', 'bubuku-post-view-count' ) }
				claim={ __(
					'Últimos 3 meses. Referidos: visitantes humanos llegados desde un asistente de IA (incluidos en el conteo de vistas). Rastreo: peticiones de bots de IA conocidos, contadas aparte.',
					'bubuku-post-view-count'
				) }
				animationDelay=".2s"
			>
				<div className="bbk-two-columns">
					<div>
						<h3>
							{ __(
								'Referidos por IA',
								'bubuku-post-view-count'
							) }
						</h3>
						<p>
							{ aiTraffic?.referrals?.views
								? sprintf(
										/* translators: %d: number of views referred by an AI assistant */
										__(
											'%d vistas',
											'bubuku-post-view-count'
										),
										aiTraffic.referrals.views
								  )
								: __(
										'Sin visitas procedentes de asistentes de IA en este periodo.',
										'bubuku-post-view-count'
								  ) }
						</p>
						{ aiTraffic?.referrals?.views > 0 && (
							<DataTable
								columns={ aiReferralColumns }
								rows={ aiTraffic?.referrals?.posts || [] }
								rowKey={ ( row ) => row.id }
								emptyLabel={ __(
									'No hay páginas disponibles para este periodo.',
									'bubuku-post-view-count'
								) }
							/>
						) }
					</div>
					<div>
						<h3>
							{ __(
								'Rastreo de bots de IA',
								'bubuku-post-view-count'
							) }
						</h3>
						<DataTable
							columns={ crawlerColumns }
							rows={ aiTraffic?.crawlers || [] }
							rowKey={ ( row ) => row.bot }
							emptyLabel={
								context?.ai_crawler_tracking
									? __(
											'Sin rastreo registrado.',
											'bubuku-post-view-count'
									  )
									: __(
											'El rastreo de bots de IA está desactivado en los ajustes.',
											'bubuku-post-view-count'
									  )
							}
						/>
					</div>
				</div>
			</DashboardCard>
		</div>
	);
};

export default StatsPanel;
