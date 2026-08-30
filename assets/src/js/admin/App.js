import { Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import HeaderMain from './components/HeaderMain';
import FooterMain from './components/FooterMain';
import AdminTabs from './components/AdminTabs';
import SettingsPanel from './components/SettingsPanel';
import StatsPanel from './components/StatsPanel';

const TAB_SETTINGS = 'settings';
const TAB_STATS = 'stats';

const apiUrl = ( window.BbkPostViewCount?.api_url || '' ).replace( /\/$/, '' );
const restNonce = window.BbkPostViewCount?.rest_nonce || '';

/**
 * Shared fetch helper for the plugin's own REST namespace — always sends the
 * X-WP-Nonce header, always parses JSON.
 *
 * @param {string} path   Path under the plugin's REST namespace, e.g. '/settings'.
 * @param {Object} [init] Extra `fetch()` options (method, body, ...).
 * @return {Promise<Object>} Resolves with the parsed JSON response body.
 */
export function bbkFetch( path, init = {} ) {
	return fetch( `${ apiUrl }${ path }`, {
		credentials: 'same-origin',
		...init,
		headers: {
			'X-WP-Nonce': restNonce,
			...( init.headers || {} ),
		},
	} ).then( ( response ) => {
		if ( ! response.ok ) {
			throw new Error( 'bbk-postview: request failed' );
		}

		return response.json();
	} );
}

function App() {
	const [ activeTab, setActiveTab ] = useState( TAB_STATS );
	const [ context, setContext ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		bbkFetch( '/settings' )
			.then( ( data ) => {
				setContext( data );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__(
						'No se han podido cargar los ajustes.',
						'bubuku-post-view-count'
					)
				);
				setLoading( false );
			} );
	}, [] );

	const tabs = [
		{
			id: TAB_SETTINGS,
			label: __( 'Ajustes', 'bubuku-post-view-count' ),
			icon: (
				<svg
					aria-hidden="true"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="1.5"
				>
					<path
						strokeLinecap="round"
						strokeLinejoin="round"
						d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
					/>
					<path
						strokeLinecap="round"
						strokeLinejoin="round"
						d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
					/>
				</svg>
			),
		},
		{
			id: TAB_STATS,
			label: __( 'Estadísticas', 'bubuku-post-view-count' ),
			icon: (
				<svg
					aria-hidden="true"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="1.5"
				>
					<path
						strokeLinecap="round"
						strokeLinejoin="round"
						d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"
					/>
				</svg>
			),
		},
	];

	return (
		<Fragment>
			<div className="bk-app">
				<HeaderMain />

				<AdminTabs
					tabs={ tabs }
					activeTab={ activeTab }
					onChange={ setActiveTab }
					ariaLabel={ __(
						'Secciones de Post View Count',
						'bubuku-post-view-count'
					) }
				/>

				{ loading && (
					<p className="bk-loading">
						{ __( 'Cargando…', 'bubuku-post-view-count' ) }
					</p>
				) }

				{ error && (
					<div className="bbk-notice bbk-notice--error">
						{ error }
					</div>
				) }

				{ ! loading && ! error && activeTab === TAB_SETTINGS && (
					<SettingsPanel
						context={ context }
						onContextChange={ setContext }
					/>
				) }

				{ ! loading && ! error && activeTab === TAB_STATS && (
					<StatsPanel context={ context } />
				) }

				<FooterMain />
			</div>
		</Fragment>
	);
}

export default App;
