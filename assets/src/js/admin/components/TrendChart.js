import { useRef, useEffect } from '@wordpress/element';

/**
 * TrendChart — hand-rolled Canvas 2D line chart, ported verbatim from the
 * pre-React assets/js/admin-stats.js (docs/PENDING-ADMIN-UI-REACT.md Fase 6):
 * same axes, scaling, stepX and labels. Only the trigger changed — a React
 * effect repaints on `trend`/size change instead of a one-off DOMContentLoaded call.
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

		ctx.clearRect( 0, 0, width, height );

		if ( ! trend || ! trend.length ) {
			ctx.fillStyle = '#646970';
			ctx.font = '13px sans-serif';
			ctx.fillText( noDataLabel, 20, height / 2 );
			return;
		}

		const padding = { top: 20, right: 20, bottom: 30, left: 50 };
		const plotWidth = width - padding.left - padding.right;
		const plotHeight = height - padding.top - padding.bottom;
		let maxViews = Math.max( ...trend.map( ( row ) => row.total_views ) );
		maxViews = maxViews || 1;

		ctx.strokeStyle = '#c3c4c7';
		ctx.beginPath();
		ctx.moveTo( padding.left, padding.top );
		ctx.lineTo( padding.left, padding.top + plotHeight );
		ctx.lineTo( padding.left + plotWidth, padding.top + plotHeight );
		ctx.stroke();

		ctx.fillStyle = '#646970';
		ctx.font = '11px sans-serif';
		ctx.fillText( String( maxViews ), 4, padding.top + 4 );
		ctx.fillText( '0', 4, padding.top + plotHeight );

		const stepX = trend.length > 1 ? plotWidth / ( trend.length - 1 ) : 0;

		ctx.strokeStyle = '#2271b1';
		ctx.lineWidth = 2;
		ctx.beginPath();

		trend.forEach( ( row, index ) => {
			const x = padding.left + stepX * index;
			const y =
				padding.top +
				plotHeight -
				( row.total_views / maxViews ) * plotHeight;

			if ( index === 0 ) {
				ctx.moveTo( x, y );
			} else {
				ctx.lineTo( x, y );
			}
		} );

		ctx.stroke();

		ctx.fillStyle = '#646970';
		ctx.fillText( trend[ 0 ].bucket, padding.left, height - 8 );
		ctx.fillText(
			trend[ trend.length - 1 ].bucket,
			width - padding.right - 60,
			height - 8
		);
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
