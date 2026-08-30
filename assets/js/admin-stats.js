/**
 * Evolution chart + period-over-period comparison for the Post View Count
 * settings page. Plain JS, no charting library and no build step (see
 * AGENTS.md) — a hand-rolled Canvas 2D line chart is enough for one series.
 */
( function () {
	'use strict';

	var CONFIG = window.bbk_postview_stats;
	var COMPARISON_PERIOD_DAYS = 30;

	if ( ! CONFIG ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var select = document.getElementById( 'bbk-postview-granularity' );
		var canvas = document.getElementById( 'bbk-postview-chart' );

		if ( ! select || ! canvas ) {
			return;
		}

		loadChart( select.value );
		loadComparison();
		loadMomentum();

		select.addEventListener( 'change', function () {
			loadChart( select.value );
		} );
	} );

	/**
	 * @param {string} granularity One of 'day', 'week', 'month'.
	 */
	function loadChart( granularity ) {
		fetchTrends( { granularity: granularity } )
			.then( function ( trend ) {
				drawChart( trend );
			} )
			.catch( function () {
				drawEmptyState();
			} );
	}

	function loadComparison() {
		var today = new Date();
		var currentStart = addDays( today, -COMPARISON_PERIOD_DAYS );
		var previousStart = addDays( today, -COMPARISON_PERIOD_DAYS * 2 );

		fetchTrends( {
			granularity: 'day',
			from: toIsoDate( previousStart ),
			to: toIsoDate( today ),
		} )
			.then( function ( trend ) {
				renderComparison( trend, toIsoDate( currentStart ) );
			} )
			.catch( function () {
				/* Silently skip the comparison if the request fails; the chart still loads independently. */
			} );
	}

	/**
	 * @param {Object} params Query args appended to the trends endpoint.
	 * @return {Promise<Array>} Resolves with the `trend` array from the REST response.
	 */
	function fetchTrends( params ) {
		return fetchJson( CONFIG.api_trends, params ).then( function ( body ) {
			return body.trend || [];
		} );
	}

	/**
	 * @return {Promise<Object>} Resolves with the `{ rising, falling }` payload from the
	 *   momentum endpoint.
	 */
	function fetchMomentum() {
		return fetchJson( CONFIG.api_momentum, {} );
	}

	/**
	 * @param {string} endpoint Full REST URL.
	 * @param {Object} params   Query args to append.
	 * @return {Promise<Object>} Resolves with the parsed JSON response body.
	 */
	function fetchJson( endpoint, params ) {
		var url = new URL( endpoint );

		Object.keys( params ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );

		return fetch( url.toString(), {
			headers: { 'X-WP-Nonce': CONFIG.nonce },
			credentials: 'same-origin',
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'bbk_postview_stats: request failed' );
			}

			return response.json();
		} );
	}

	function loadMomentum() {
		var risingEl = document.getElementById( 'bbk-postview-momentum-rising' );
		var fallingEl = document.getElementById( 'bbk-postview-momentum-falling' );

		if ( ! risingEl || ! fallingEl ) {
			return;
		}

		fetchMomentum()
			.then( function ( body ) {
				renderMomentumList( risingEl, body.rising || [], 'is-up' );
				renderMomentumList( fallingEl, body.falling || [], 'is-down' );
			} )
			.catch( function () {
				/* Silently skip the momentum lists if the request fails; the rest of the page still loads. */
			} );
	}

	/**
	 * @param {HTMLElement} el        <ul> to fill.
	 * @param {Array}       items     Rows: [{ title, url, current_views, delta, delta_pct }, ...]
	 * @param {string}      className Row class ('is-up' or 'is-down').
	 */
	function renderMomentumList( el, items, className ) {
		el.innerHTML = '';

		if ( ! items.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'description';
			empty.textContent = CONFIG.i18n.noMomentum;
			el.appendChild( empty );
			return;
		}

		items.forEach( function ( item ) {
			var li = document.createElement( 'li' );
			li.className = className;

			var link = document.createElement( 'a' );
			link.href = item.url;
			link.textContent = item.title;

			var delta = document.createElement( 'span' );
			var sign = 0 <= item.delta ? '+' : '';
			var deltaText = sign + item.delta;

			if ( null !== item.delta_pct ) {
				deltaText += ' (' + sign + item.delta_pct + '%)';
			}

			delta.className = 'bbk-postview-momentum-delta';
			delta.textContent = ' ' + deltaText;

			li.appendChild( link );
			li.appendChild( delta );
			el.appendChild( li );
		} );
	}

	/**
	 * @param {Array}  trend      Full two-period series, bucketed by day.
	 * @param {string} cutoffDate ISO date (Y-m-d) where the current period starts.
	 */
	function renderComparison( trend, cutoffDate ) {
		var el = document.getElementById( 'bbk-postview-comparison' );

		if ( ! el ) {
			return;
		}

		var current = 0;
		var previous = 0;

		trend.forEach( function ( row ) {
			if ( row.bucket >= cutoffDate ) {
				current += row.total_views;
			} else {
				previous += row.total_views;
			}
		} );

		var label = CONFIG.i18n.thisPeriod + ': ' + current + ' ' + CONFIG.i18n.views;

		if ( previous > 0 ) {
			var change = Math.round( ( ( current - previous ) / previous ) * 100 );
			var sign = 0 <= change ? '+' : '';

			label += ' (' + CONFIG.i18n.vsPrevious.replace( '%s', sign + change + '%' ) + ')';
			el.className = 0 <= change ? 'description is-up' : 'description is-down';
		} else {
			el.className = 'description';
		}

		el.textContent = label;
	}

	/**
	 * @param {Array} trend Series to plot: [{ bucket: string, total_views: number }, ...]
	 */
	function drawChart( trend ) {
		var canvas = document.getElementById( 'bbk-postview-chart' );
		var ctx = canvas.getContext( '2d' );
		var width = canvas.width;
		var height = canvas.height;

		ctx.clearRect( 0, 0, width, height );

		if ( ! trend.length ) {
			drawEmptyState();
			return;
		}

		var padding = { top: 20, right: 20, bottom: 30, left: 50 };
		var plotWidth = width - padding.left - padding.right;
		var plotHeight = height - padding.top - padding.bottom;
		var maxViews = Math.max.apply(
			null,
			trend.map( function ( row ) {
				return row.total_views;
			} )
		);
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

		var stepX = trend.length > 1 ? plotWidth / ( trend.length - 1 ) : 0;

		ctx.strokeStyle = '#2271b1';
		ctx.lineWidth = 2;
		ctx.beginPath();

		trend.forEach( function ( row, index ) {
			var x = padding.left + stepX * index;
			var y = padding.top + plotHeight - ( row.total_views / maxViews ) * plotHeight;

			if ( 0 === index ) {
				ctx.moveTo( x, y );
			} else {
				ctx.lineTo( x, y );
			}
		} );

		ctx.stroke();

		ctx.fillStyle = '#646970';
		ctx.fillText( trend[ 0 ].bucket, padding.left, height - 8 );
		ctx.fillText( trend[ trend.length - 1 ].bucket, width - padding.right - 60, height - 8 );
	}

	function drawEmptyState() {
		var canvas = document.getElementById( 'bbk-postview-chart' );
		var ctx = canvas.getContext( '2d' );

		ctx.clearRect( 0, 0, canvas.width, canvas.height );
		ctx.fillStyle = '#646970';
		ctx.font = '13px sans-serif';
		ctx.fillText( CONFIG.i18n.noData, 20, canvas.height / 2 );
	}

	/**
	 * @param {Date} date  Base date.
	 * @param {number} days Days to add (negative to subtract).
	 * @return {Date}
	 */
	function addDays( date, days ) {
		var result = new Date( date );
		result.setDate( result.getDate() + days );
		return result;
	}

	/**
	 * @param {Date} date
	 * @return {string} Y-m-d.
	 */
	function toIsoDate( date ) {
		return date.toISOString().slice( 0, 10 );
	}
} )();
