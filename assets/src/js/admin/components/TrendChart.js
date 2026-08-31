import { useRef, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

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

/**
 * TrendChart — lightweight Canvas 2D line chart for the aggregated daily,
 * weekly or monthly series. It paints markers as well as connecting lines so
 * a series containing a single bucket remains visible.
 *
 * @param {Object} props
 * @param {Array}  props.trend       Series to plot: [{ bucket: string, total_views: number }, ...].
 * @param {string} props.granularity day, week or month.
 * @param {string} props.noDataLabel Text shown when `trend` is empty.
 */
const TrendChart = ( { trend, granularity, noDataLabel } ) => {
	const canvasRef = useRef( null );
	const pointsRef = useRef( [] );
	const [ tooltip, setTooltip ] = useState( null );

	useEffect( () => {
		const canvas = canvasRef.current;
		if ( ! canvas ) {
			return;
		}

		const ctx = canvas.getContext( '2d' );
		const width = canvas.width;
		const height = canvas.height;
		const styles = window.getComputedStyle( canvas );
		const colorMuted =
			styles.getPropertyValue( '--text-muted' ).trim() || '#646970';
		const colorBorder =
			styles.getPropertyValue( '--border-strong' ).trim() || '#c3c4c7';
		const colorBrand =
			styles.getPropertyValue( '--brand' ).trim() || '#2271b1';

		ctx.clearRect( 0, 0, width, height );
		pointsRef.current = [];
		setTooltip( null );

		if ( ! trend || ! trend.length ) {
			ctx.fillStyle = colorMuted;
			ctx.font = '13px sans-serif';
			ctx.fillText( noDataLabel, 20, height / 2 );
			return;
		}

		const padding = { top: 20, right: 20, bottom: 30, left: 50 };
		const plotWidth = width - padding.left - padding.right;
		const plotHeight = height - padding.top - padding.bottom;
		let maxViews = Math.max( ...trend.map( ( row ) => row.total_views ) );
		maxViews = maxViews || 1;

		ctx.strokeStyle = colorBorder;
		ctx.beginPath();
		ctx.moveTo( padding.left, padding.top );
		ctx.lineTo( padding.left, padding.top + plotHeight );
		ctx.lineTo( padding.left + plotWidth, padding.top + plotHeight );
		ctx.stroke();

		ctx.fillStyle = colorMuted;
		ctx.font = '11px sans-serif';
		ctx.fillText( String( maxViews ), 4, padding.top + 4 );
		ctx.fillText( '0', 4, padding.top + plotHeight );

		const stepX = trend.length > 1 ? plotWidth / ( trend.length - 1 ) : 0;

		ctx.strokeStyle = colorBrand;
		ctx.lineWidth = 2;
		ctx.beginPath();
		const points = [];

		trend.forEach( ( row, index ) => {
			const x =
				trend.length > 1
					? padding.left + stepX * index
					: padding.left + plotWidth / 2;
			const y =
				padding.top +
				plotHeight -
				( row.total_views / maxViews ) * plotHeight;

			if ( index === 0 ) {
				ctx.moveTo( x, y );
			} else {
				ctx.lineTo( x, y );
			}

			points.push( { x, y, row } );
		} );

		ctx.stroke();
		ctx.fillStyle = colorBrand;
		points
			.filter( ( point ) => point.row.total_views > 0 )
			.forEach( ( point ) => {
				ctx.beginPath();
				ctx.arc( point.x, point.y, 4, 0, Math.PI * 2 );
				ctx.fill();
			} );
		pointsRef.current = points.filter(
			( point ) => point.row.total_views > 0
		);

		ctx.fillStyle = colorMuted;
		if ( trend.length === 1 ) {
			ctx.textAlign = 'center';
			ctx.fillText(
				formatBucket( trend[ 0 ].bucket, granularity ),
				padding.left + plotWidth / 2,
				height - 8
			);
		} else {
			ctx.textAlign = 'left';
			ctx.fillText(
				formatBucket( trend[ 0 ].bucket, granularity ),
				padding.left,
				height - 8
			);
			ctx.textAlign = 'right';
			ctx.fillText(
				formatBucket( trend[ trend.length - 1 ].bucket, granularity ),
				width - padding.right,
				height - 8
			);
		}
	}, [ trend, granularity, noDataLabel ] );

	const handlePointerMove = ( event ) => {
		const canvas = canvasRef.current;
		if ( ! canvas || ! pointsRef.current.length ) {
			setTooltip( null );
			return;
		}

		const rect = canvas.getBoundingClientRect();
		const scaleX = canvas.width / rect.width;
		const scaleY = canvas.height / rect.height;
		const pointerX = ( event.clientX - rect.left ) * scaleX;
		const pointerY = ( event.clientY - rect.top ) * scaleY;
		let nearest = null;
		let nearestDistance = 12 * Math.max( scaleX, scaleY );

		pointsRef.current.forEach( ( point ) => {
			const distance = Math.hypot(
				point.x - pointerX,
				point.y - pointerY
			);

			if ( distance <= nearestDistance ) {
				nearest = point;
				nearestDistance = distance;
			}
		} );

		if ( ! nearest ) {
			setTooltip( null );
			return;
		}

		const horizontalPosition = ( nearest.x / canvas.width ) * 100;
		let align = 'center';

		if ( horizontalPosition < 15 ) {
			align = 'start';
		} else if ( horizontalPosition > 85 ) {
			align = 'end';
		}

		setTooltip( {
			left: horizontalPosition,
			top: ( nearest.y / canvas.height ) * 100,
			align,
			date: formatBucket( nearest.row.bucket, granularity ),
			views: nearest.row.total_views,
		} );
	};

	return (
		<div className="bbk-trend-chart__plot">
			<canvas
				ref={ canvasRef }
				className="bbk-trend-chart__canvas"
				width="900"
				height="260"
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
					<span>
						{ sprintf(
							/* translators: %d: number of views in the hovered bucket. */
							_n(
								'%d view',
								'%d views',
								tooltip.views,
								'bubuku-post-view-count'
							),
							tooltip.views
						) }
					</span>
				</div>
			) }
		</div>
	);
};

export default TrendChart;
