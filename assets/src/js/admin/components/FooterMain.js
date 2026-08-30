import { __ } from '@wordpress/i18n';

const locale = ( document.documentElement.lang || 'en' ).toLowerCase();

/**
 * Build a URL with UTM parameters.
 *
 * @param {string} base    Target URL (no trailing query string required).
 * @param {string} content Value for utm_content (identifies the link).
 * @param {string} source  Value for utm_source (defaults to plugin slug).
 * @return {string} Full URL with UTM params appended.
 */
export function buildUtm( base, content, source = 'bubuku-post-view-count' ) {
	const params = new URLSearchParams( {
		utm_source: source,
		utm_medium: 'wp-admin-footer',
		utm_campaign: `${ source }-footer-links`,
		utm_content: `${ content }-${ locale }`,
	} );
	return base + ( base.includes( '?' ) ? '&' : '?' ) + params.toString();
}

const SERVICES = [
	{
		label: __(
			'Business-driven custom WordPress development',
			'bubuku-post-view-count'
		),
		icon: (
			<svg
				viewBox="0 0 24 24"
				aria-hidden="true"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.8"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
			</svg>
		),
	},
	{
		label: __(
			'Plugins and external systems integrations',
			'bubuku-post-view-count'
		),
		icon: (
			<svg
				viewBox="0 0 24 24"
				aria-hidden="true"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.8"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
				<line x1="7" y1="7" x2="7.01" y2="7" />
			</svg>
		),
	},
	{
		label: __(
			'Continuous maintenance and support',
			'bubuku-post-view-count'
		),
		icon: (
			<svg
				viewBox="0 0 24 24"
				aria-hidden="true"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.8"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<polyline points="23 4 23 10 17 10" />
				<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
			</svg>
		),
	},
];

function IconHeart() {
	return (
		<svg
			viewBox="0 0 24 24"
			aria-hidden="true"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
		</svg>
	);
}

function IconArrow() {
	return (
		<svg
			viewBox="0 0 24 24"
			aria-hidden="true"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<line x1="5" y1="12" x2="19" y2="12" />
			<polyline points="12 5 19 12 12 19" />
		</svg>
	);
}

const CTA_URL = buildUtm( 'https://www.bubuku.com/servicios/', 'cta-services' );

function FooterMain() {
	return (
		<footer className="bk-footer-main">
			<div className="bk-footer-main__inner bk-footer-main__inner--single">
				<div className="bk-footer-main__team">
					<div className="bk-footer-main__team-title">
						<div className="bk-footer-main__icon">
							<IconHeart />
						</div>
						<p className="bk-footer-main__team-heading">
							{ __(
								'Technical partner for your WordPress projects',
								'bubuku-post-view-count'
							) }
						</p>
					</div>

					<p className="bk-footer-main__team-description">
						{ __(
							'We work alongside marketing and business teams to improve the stability, performance, and evolution of your WordPress projects.',
							'bubuku-post-view-count'
						) }
					</p>

					<ul className="bk-footer-main__services">
						{ SERVICES.map( ( service, i ) => (
							<li key={ i }>
								{ service.icon }
								<span>{ service.label }</span>
							</li>
						) ) }
					</ul>

					<a
						href={ CTA_URL }
						target="_blank"
						rel="noopener noreferrer"
						className="bk-footer-main__cta"
					>
						{ __( 'See how we work', 'bubuku-post-view-count' ) }
						<IconArrow />
					</a>
				</div>
			</div>
		</footer>
	);
}

export default FooterMain;
