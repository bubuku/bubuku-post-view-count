import { useRef, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../format';

const CHART_HEIGHT = 260;
const PADDING = { top: 20, right: 20, bottom: 30, left: 50 };

function formatBucket( bucket, granularity ) {
	const date = new Date( `${ bucket.slice( 0, 10 ) }T00:00:00Z` );
	const options = {
		month: granularity === 'month' ? 'long' : 'short',
		year: 'numeric',
		timeZone: 'UTC',
	};

	if ( granularity !== 'month' ) {
		options.day = '2-digit';
	}

	const label = new Intl.DateTimeFormat(
		document.documentElement.lang || 'es',
		options
	).format( date );

	return granularity === 'week'
		? sprintf(
				/* translators: %s: first day of the week. */
				__( 'Week of %s', 'bubuku-post-view-count' ),
				label
		  )
		: label;
}

function formatViews( views ) {
	return sprintf(
		/* translators: %s: number of views in the hovered bucket. */
		_n( '%s view', '%s views', views, 'bubuku-post-view-count' ),
		formatNumber( views )
	);
}

/**
 * TrendChart — lightweight Canvas 2D line chart for the aggregated daily,
 * weekly or monthly series. It paints markers as well as connecting lines so
 * a series containing a single bucket remains visible.
 *
 * The canvas fills its container's width (tracked with a ResizeObserver) at a
 * fixed CSS height, and is backed by `devicePixelRatio` pixels so it stays
 * sharp on high-density screens. Everything is drawn in CSS pixels.
 *
 * @param {Object}  props
 * @param {Array}   props.trend       Series to plot when `series` is omitted: [{ bucket: string, total_views: number }, ...].
 * @param {Array}   [props.series]    Several series to plot together: [{ key, label, color, trend }, ...], where
 *                                    `color` is a CSS custom property name (e.g. `--chart-claude`). Adds a legend.
 * @param {string}  props.granularity day, week or month.
 * @param {string}  props.noDataLabel Text shown when there is nothing to plot.
 * @param {boolean} [props.loading]   Shows "Loading data…" instead of the chart or `noDataLabel`.
 */
const TrendChart = ( {
	trend,
	series,
	granularity,
	noDataLabel,
	loading = false,
} ) => {
	const plotRef = useRef( null );
	const canvasRef = useRef( null );
	const bucketsRef = useRef( [] );
	const [ width, setWidth ] = useState( 0 );
	const [ hidden, setHidden ] = useState( [] );
	const [ tooltip, setTooltip ] = useState( null );

	const isMulti = Array.isArray( series );
	const lines = isMulti
		? series
		: [ { key: 'all', label: null, color: '--brand', trend: trend || [] } ];
	const visibleLines = lines.filter(
		( line ) => ! hidden.includes( line.key )
	);
	const seriesKey = lines.map( ( line ) => line.key ).join( '|' );

	useEffect( () => {
		const plot = plotRef.current;
		if ( ! plot ) {
			return;
		}

		setWidth( plot.clientWidth );

		if ( typeof window.ResizeObserver === 'undefined' ) {
			return;
		}

		const observer = new window.ResizeObserver( ( entries ) => {
			setWidth( Math.round( entries[ 0 ].contentRect.width ) );
		} );
		observer.observe( plot );

		return () => observer.disconnect();
	}, [] );

	// A new set of series (e.g. switching source) starts with every line visible.
	useEffect( () => {
		setHidden( [] );
	}, [ seriesKey ] );

	useEffect( () => {
		const canvas = canvasRef.current;
		if ( ! canvas || ! width ) {
			return;
		}

		const dpr = window.devicePixelRatio || 1;
		canvas.width = Math.round( width * dpr );
		canvas.height = Math.round( CHART_HEIGHT * dpr );

		const ctx = canvas.getContext( '2d' );
		ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );

		const height = CHART_HEIGHT;
		const styles = window.getComputedStyle( canvas );
		const cssColor = ( name, fallback ) =>
			styles.getPropertyValue( name ).trim() || fallback;
		const colorMuted = cssColor( '--text-muted', '#646970' );

		ctx.clearRect( 0, 0, width, height );
		bucketsRef.current = [];
		setTooltip( null );

		const buckets = Array.from(
			new Set(
				visibleLines.flatMap( ( line ) =>
					line.trend.map( ( row ) => row.bucket )
				)
			)
		).sort();

		if ( loading || ! buckets.length ) {
			ctx.fillStyle = colorMuted;
			ctx.font = '13px sans-serif';
			ctx.fillText(
				loading
					? __( 'Loading data…', 'bubuku-post-view-count' )
					: noDataLabel,
				20,
				height / 2
			);
			return;
		}

		const plotWidth = width - PADDING.left - PADDING.right;
		const plotHeight = height - PADDING.top - PADDING.bottom;
		const valuesByLine = visibleLines.map( ( line ) => {
			const values = new Map(
				line.trend.map( ( row ) => [ row.bucket, row.total_views ] )
			);
			return buckets.map( ( bucket ) => values.get( bucket ) || 0 );
		} );
		const maxViews = Math.max( 1, ...valuesByLine.flat() );
		const xFor = ( index ) =>
			buckets.length > 1
				? PADDING.left + ( plotWidth / ( buckets.length - 1 ) ) * index
				: PADDING.left + plotWidth / 2;
		const yFor = ( views ) =>
			PADDING.top + plotHeight - ( views / maxViews ) * plotHeight;

		ctx.strokeStyle = cssColor( '--border-strong', '#c3c4c7' );
		ctx.lineWidth = 1;
		ctx.beginPath();
		ctx.moveTo( PADDING.left, PADDING.top );
		ctx.lineTo( PADDING.left, PADDING.top + plotHeight );
		ctx.lineTo( PADDING.left + plotWidth, PADDING.top + plotHeight );
		ctx.stroke();

		ctx.fillStyle = colorMuted;
		ctx.font = '11px sans-serif';
		ctx.textAlign = 'left';
		ctx.fillText( formatNumber( maxViews ), 4, PADDING.top + 4 );
		ctx.fillText( '0', 4, PADDING.top + plotHeight );

		visibleLines.forEach( ( line, lineIndex ) => {
			const color = cssColor( line.color, '#2271b1' );
			const values = valuesByLine[ lineIndex ];

			ctx.strokeStyle = color;
			ctx.lineWidth = 2;
			ctx.beginPath();
			values.forEach( ( views, index ) => {
				if ( index === 0 ) {
					ctx.moveTo( xFor( index ), yFor( views ) );
				} else {
					ctx.lineTo( xFor( index ), yFor( views ) );
				}
			} );
			ctx.stroke();

			ctx.fillStyle = color;
			values.forEach( ( views, index ) => {
				if ( views > 0 ) {
					ctx.beginPath();
					ctx.arc( xFor( index ), yFor( views ), 4, 0, Math.PI * 2 );
					ctx.fill();
				}
			} );
		} );

		bucketsRef.current = buckets.map( ( bucket, index ) => ( {
			bucket,
			x: xFor( index ),
			values: visibleLines.map( ( line, lineIndex ) => ( {
				key: line.key,
				label: line.label,
				color: cssColor( line.color, '#2271b1' ),
				views: valuesByLine[ lineIndex ][ index ],
			} ) ),
		} ) );

		ctx.fillStyle = colorMuted;
		if ( buckets.length === 1 ) {
			ctx.textAlign = 'center';
			ctx.fillText(
				formatBucket( buckets[ 0 ], granularity ),
				PADDING.left + plotWidth / 2,
				height - 8
			);
		} else {
			ctx.textAlign = 'left';
			ctx.fillText(
				formatBucket( buckets[ 0 ], granularity ),
				PADDING.left,
				height - 8
			);
			ctx.textAlign = 'right';
			ctx.fillText(
				formatBucket( buckets[ buckets.length - 1 ], granularity ),
				width - PADDING.right,
				height - 8
			);
		}
		// visibleLines is derived from series/trend/hidden, all listed below.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ trend, series, hidden, granularity, noDataLabel, loading, width ] );

	// The tooltip follows the bucket closest to the pointer on the X axis and
	// lists every visible series in it, so overlapping points never hide values.
	const handlePointerMove = ( event ) => {
		const canvas = canvasRef.current;
		const buckets = bucketsRef.current;
		if ( ! canvas || ! buckets.length ) {
			setTooltip( null );
			return;
		}

		const rect = canvas.getBoundingClientRect();
		const pointerX = event.clientX - rect.left;
		const step =
			buckets.length > 1 ? buckets[ 1 ].x - buckets[ 0 ].x : rect.width;
		let nearest = null;
		let nearestDistance = Math.max( step / 2, 12 );

		buckets.forEach( ( item ) => {
			const distance = Math.abs( item.x - pointerX );

			if ( distance <= nearestDistance ) {
				nearest = item;
				nearestDistance = distance;
			}
		} );

		if ( ! nearest || nearest.values.every( ( v ) => v.views === 0 ) ) {
			setTooltip( null );
			return;
		}

		const maxViews = Math.max( ...nearest.values.map( ( v ) => v.views ) );
		const plotHeight = CHART_HEIGHT - PADDING.top - PADDING.bottom;
		const scaleMax = Math.max(
			1,
			...buckets.flatMap( ( item ) =>
				item.values.map( ( v ) => v.views )
			)
		);
		const top =
			PADDING.top + plotHeight - ( maxViews / scaleMax ) * plotHeight;
		const horizontalPosition = ( nearest.x / rect.width ) * 100;
		let align = 'center';

		if ( horizontalPosition < 15 ) {
			align = 'start';
		} else if ( horizontalPosition > 85 ) {
			align = 'end';
		}

		setTooltip( {
			left: horizontalPosition,
			top: ( top / CHART_HEIGHT ) * 100,
			align,
			date: formatBucket( nearest.bucket, granularity ),
			values: nearest.values,
		} );
	};

	const toggleLine = ( key ) =>
		setHidden( ( current ) =>
			current.includes( key )
				? current.filter( ( item ) => item !== key )
				: [ ...current, key ]
		);

	return (
		<>
			<div className="bbk-trend-chart__plot" ref={ plotRef }>
				<canvas
					ref={ canvasRef }
					className="bbk-trend-chart__canvas"
					onPointerMove={ handlePointerMove }
					onPointerLeave={ () => setTooltip( null ) }
				/>
				{ tooltip && (
					<div
						className={ `bbk-trend-chart__tooltip bbk-trend-chart__tooltip--${ tooltip.align }` }
						style={ {
							left: `${ tooltip.left }%`,
							top: `${ tooltip.top }%`,
						} }
						role="status"
					>
						<strong>{ tooltip.date }</strong>
						{ isMulti ? (
							tooltip.values.map( ( value ) => (
								<span
									key={ value.key }
									className="bbk-trend-chart__tooltip-row"
								>
									<i
										className="bbk-trend-chart__swatch"
										style={ { background: value.color } }
										aria-hidden="true"
									/>
									{ value.label }:{ ' ' }
									{ formatNumber( value.views ) }
								</span>
							) )
						) : (
							<span>
								{ formatViews( tooltip.values[ 0 ].views ) }
							</span>
						) }
					</div>
				) }
			</div>
			{ isMulti && ! loading && lines.length > 0 && (
				<ul className="bbk-trend-chart__legend">
					{ lines.map( ( line ) => (
						<li key={ line.key }>
							<button
								type="button"
								className="bbk-trend-chart__legend-item"
								aria-pressed={ ! hidden.includes( line.key ) }
								onClick={ () => toggleLine( line.key ) }
							>
								<i
									className="bbk-trend-chart__swatch"
									style={ {
										background: `var(${ line.color })`,
									} }
									aria-hidden="true"
								/>
								{ line.label }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</>
	);
};

export default TrendChart;
