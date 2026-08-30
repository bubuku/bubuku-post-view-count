import { useRef, useEffect } from '@wordpress/element';

/**
 * TrendChart — lightweight Canvas 2D line chart for the aggregated daily,
 * weekly or monthly series. It paints markers as well as connecting lines so
 * a series containing a single bucket remains visible.
 *
 * @param {Object} props
 * @param {Array}  props.trend       Series to plot: [{ bucket: string, total_views: number }, ...].
 * @param {string} props.noDataLabel Text shown when `trend` is empty.
 */
const TrendChart = ( { trend, noDataLabel } ) => {
	const canvasRef = useRef( null );

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

			points.push( { x, y } );
		} );

		ctx.stroke();
		ctx.fillStyle = colorBrand;
		points.forEach( ( point ) => {
			ctx.beginPath();
			ctx.arc(
				point.x,
				point.y,
				trend.length === 1 ? 4 : 2.5,
				0,
				Math.PI * 2
			);
			ctx.fill();
		} );

		const formatDate = ( bucket ) =>
			new Intl.DateTimeFormat( document.documentElement.lang || 'es', {
				day: '2-digit',
				month: 'short',
				year: 'numeric',
				timeZone: 'UTC',
			} ).format( new Date( `${ bucket.slice( 0, 10 ) }T00:00:00Z` ) );

		ctx.fillStyle = colorMuted;
		if ( trend.length === 1 ) {
			ctx.textAlign = 'center';
			ctx.fillText(
				formatDate( trend[ 0 ].bucket ),
				padding.left + plotWidth / 2,
				height - 8
			);
		} else {
			ctx.textAlign = 'left';
			ctx.fillText(
				formatDate( trend[ 0 ].bucket ),
				padding.left,
				height - 8
			);
			ctx.textAlign = 'right';
			ctx.fillText(
				formatDate( trend[ trend.length - 1 ].bucket ),
				width - padding.right,
				height - 8
			);
		}
	}, [ trend, noDataLabel ] );

	return (
		<canvas
			ref={ canvasRef }
			className="bbk-trend-chart__canvas"
			width="900"
			height="260"
		/>
	);
};

export default TrendChart;
