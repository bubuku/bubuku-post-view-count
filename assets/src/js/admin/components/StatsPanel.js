import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import DashboardCard from './DashboardCard';
import DataTable from './DataTable';
import TrendChart from './TrendChart';
import { bbkFetch } from '../App';

const COMPARISON_PERIOD_DAYS = 30;

const ICON_TREND = (
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
		<polyline points="23 6 13.5 15.5 8.5 10.5 1 18" />
		<polyline points="17 6 23 6 23 12" />
	</svg>
);

const ICON_DEVICE = (
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
		<rect x="4" y="2" width="16" height="20" rx="2" />
		<line x1="12" y1="18" x2="12.01" y2="18" />
	</svg>
);

const ICON_AI = (
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
		<circle cx="12" cy="12" r="10" />
		<path d="M12 8v4l3 3" />
	</svg>
);

function addDays( date, days ) {
	const result = new Date( date );
	result.setDate( result.getDate() + days );
	return result;
}

function toIsoDate( date ) {
	return date.toISOString().slice( 0, 10 );
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
 * @param {Object} props.context GET /settings response — only used here for
 *                                the `ai_crawler_tracking` flag.
 */
const StatsPanel = ( { context } ) => {
	const [ granularity, setGranularity ] = useState( 'day' );
	const [ trend, setTrend ] = useState( [] );
	const [ comparison, setComparison ] = useState( null );

	const [ momentum, setMomentum ] = useState( null );
	const [ dimsViewport, setDimsViewport ] = useState( null );
	const [ dimsReferrer, setDimsReferrer ] = useState( null );
	const [ aiTraffic, setAiTraffic ] = useState( null );

	useEffect( () => {
		bbkFetch( `/trends?granularity=${ granularity }` )
			.then( ( body ) => setTrend( body.trend || [] ) )
			.catch( () => setTrend( [] ) );
	}, [ granularity ] );

	useEffect( () => {
		const today = new Date();
		const currentStart = addDays( today, -COMPARISON_PERIOD_DAYS );
		const previousStart = addDays( today, -COMPARISON_PERIOD_DAYS * 2 );

		bbkFetch(
			`/trends?granularity=day&from=${ toIsoDate( previousStart ) }&to=${ toIsoDate( today ) }`
		)
			.then( ( body ) => {
				const cutoff = toIsoDate( currentStart );
				let current = 0;
				let previous = 0;

				( body.trend || [] ).forEach( ( row ) => {
					if ( row.bucket >= cutoff ) {
						current += row.total_views;
					} else {
						previous += row.total_views;
					}
				} );

				setComparison( { current, previous } );
			} )
			.catch( () => setComparison( null ) );
	}, [] );

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
				setAiTraffic( { referrals: { views: 0 }, crawlers: [], ai_crawler_tracking_enabled: false } )
			);
	}, [] );

	const dimsColumns = [
		{ key: 'value', label: __( 'Valor', 'bubuku-post-view-count' ) },
		{ key: 'views', label: __( 'Vistas', 'bubuku-post-view-count' ), numeric: true },
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
				const pct = null !== row.delta_pct ? ` (${ sign }${ row.delta_pct }%)` : '';
				return `${ sign }${ row.delta }${ pct }`;
			},
		},
	];

	const crawlerColumns = [
		{ key: 'bot', label: __( 'Bot', 'bubuku-post-view-count' ) },
		{ key: 'views', label: __( 'Vistas', 'bubuku-post-view-count' ), numeric: true },
	];

	let comparisonLabel = null;
	if ( comparison ) {
		comparisonLabel = sprintf(
			/* translators: %d: total views in the current 30-day period */
			__( 'Este periodo: %d vistas', 'bubuku-post-view-count' ),
			comparison.current
		);

		if ( comparison.previous > 0 ) {
			const change = Math.round(
				( ( comparison.current - comparison.previous ) / comparison.previous ) * 100
			);
			const sign = change >= 0 ? '+' : '';
			comparisonLabel += ' ' + sprintf(
				/* translators: %s: percentage change vs the previous period, e.g. "+12%". */
				__( '(%s vs. periodo anterior)', 'bubuku-post-view-count' ),
				`${ sign }${ change }%`
			);
		}
	}

	return (
		<div className="bbk-stats-panel">
			<DashboardCard
				icon={ ICON_TREND }
				title={ __( 'Evolución de vistas', 'bubuku-post-view-count' ) }
				claim={ __( 'Agrupado por día, semana o mes, con comparativa de los últimos 30 días.', 'bubuku-post-view-count' ) }
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
				{ comparisonLabel && <p className="bbk-trend-chart__comparison">{ comparisonLabel }</p> }
				<div className="bbk-trend-chart">
					<TrendChart
						trend={ trend }
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
				claim={ __( 'Comparación entre los últimos 30 días y los 30 anteriores.', 'bubuku-post-view-count' ) }
				animationDelay=".1s"
			>
				<div className="bbk-two-columns">
					<div>
						<h3>{ __( 'En alza', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ momentumColumns }
							rows={ momentum?.rising || [] }
							rowKey={ ( row ) => row.url }
							emptyLabel={ __( 'Sin cambios relevantes en este periodo.', 'bubuku-post-view-count' ) }
						/>
					</div>
					<div>
						<h3>{ __( 'En caída', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ momentumColumns }
							rows={ momentum?.falling || [] }
							rowKey={ ( row ) => row.url }
							emptyLabel={ __( 'Sin cambios relevantes en este periodo.', 'bubuku-post-view-count' ) }
						/>
					</div>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_DEVICE }
				title={ __( 'Dispositivo y procedencia', 'bubuku-post-view-count' ) }
				claim={ __( 'Últimos 3 meses.', 'bubuku-post-view-count' ) }
				animationDelay=".15s"
			>
				<div className="bbk-two-columns">
					<div>
						<h3>{ __( 'Dispositivo', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ dimsColumns }
							rows={ dimsViewport || [] }
							rowKey={ ( row ) => row.value }
							emptyLabel={ __( 'Todavía no hay datos suficientes.', 'bubuku-post-view-count' ) }
						/>
					</div>
					<div>
						<h3>{ __( 'Procedencia', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ dimsColumns }
							rows={ dimsReferrer || [] }
							rowKey={ ( row ) => row.value }
							emptyLabel={ __( 'Todavía no hay datos suficientes.', 'bubuku-post-view-count' ) }
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
						<h3>{ __( 'Referidos por IA', 'bubuku-post-view-count' ) }</h3>
						<p>
							{ aiTraffic?.referrals?.views
								? sprintf(
										/* translators: %d: number of views referred by an AI assistant */
										__( '%d vistas', 'bubuku-post-view-count' ),
										aiTraffic.referrals.views
								  )
								: __( 'Sin visitas procedentes de asistentes de IA en este periodo.', 'bubuku-post-view-count' ) }
						</p>
					</div>
					<div>
						<h3>{ __( 'Rastreo de bots de IA', 'bubuku-post-view-count' ) }</h3>
						<DataTable
							columns={ crawlerColumns }
							rows={ aiTraffic?.crawlers || [] }
							rowKey={ ( row ) => row.bot }
							emptyLabel={
								context?.ai_crawler_tracking
									? __( 'Sin rastreo registrado.', 'bubuku-post-view-count' )
									: __( 'El rastreo de bots de IA está desactivado en los ajustes.', 'bubuku-post-view-count' )
							}
						/>
					</div>
				</div>
			</DashboardCard>
		</div>
	);
};

export default StatsPanel;
