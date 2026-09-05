import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import DashboardCard from './DashboardCard';
import BkSaveBar from './SaveBar';
import { bbkFetch } from '../App';

const ICON_SETTINGS = (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		aria-hidden="true"
	>
		<circle cx="12" cy="12" r="3" />
		<path d="M12 1v6m0 10v6m11-7h-6M7 12H1m16.24-6.24L13 10m0 4l4.24 4.24M6.76 5.76L11 10m0 4l-4.24 4.24" />
	</svg>
);

const ICON_DANGER = (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		aria-hidden="true"
	>
		<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z" />
	</svg>
);

/**
 * SettingsPanel — plugin settings (docs/PENDING-ADMIN-UI-REACT.md Fase 5),
 * plus the "delete all data" action. Reads its initial state from `context`
 * (the GET /settings response App.js already fetched) and saves/deletes over
 * the same REST namespace via `bbkFetch`.
 *
 * @param {Object}   props
 * @param {Object}   props.context         GET /settings response.
 * @param {Function} props.onContextChange Called with the updated context after a successful save.
 */
const SettingsPanel = ( { context, onContextChange } ) => {
	const [ settings, setSettings ] = useState( { ...context } );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );

	const update = ( key, value ) => {
		setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	const toggleInList = ( key, value ) => {
		setSettings( ( prev ) => {
			const list = prev[ key ] || [];
			const next = list.includes( value )
				? list.filter( ( item ) => item !== value )
				: [ ...list, value ];
			return { ...prev, [ key ]: next };
		} );
	};

	const handleSave = () => {
		setSaving( true );
		setStatus( null );

		bbkFetch( '/settings', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( settings ),
		} )
			.then( ( saved ) => {
				const merged = { ...context, ...saved };
				setSettings( merged );
				onContextChange( merged );
				setSaving( false );
				setStatus( {
					type: 'green-500',
					message: __( 'Settings saved.', 'bubuku-post-view-count' ),
				} );
			} )
			.catch( () => {
				setSaving( false );
				setStatus( {
					type: 'error',
					message: __(
						'Settings could not be saved.',
						'bubuku-post-view-count'
					),
				} );
			} );
	};

	const handleReset = () => {
		setSettings( { ...context } );
		setStatus( null );
	};

	const handleDeleteData = () => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Are you sure you want to delete all recorded views? This action cannot be undone.',
				'bubuku-post-view-count'
			)
		);

		if ( ! confirmed ) {
			return;
		}

		setDeleting( true );
		setStatus( null );

		bbkFetch( '/settings/data', { method: 'DELETE' } )
			.then( () => {
				setDeleting( false );
				setStatus( {
					type: 'green-500',
					message: __(
						'All recorded views have been deleted.',
						'bubuku-post-view-count'
					),
				} );
			} )
			.catch( () => {
				setDeleting( false );
				setStatus( {
					type: 'error',
					message: __(
						'Data could not be deleted.',
						'bubuku-post-view-count'
					),
				} );
			} );
	};

	const postTypes = context.available_post_types || {};
	const roles = context.available_roles || {};

	return (
		<div className="bbk-settings-panel">
			<DashboardCard
				icon={ ICON_SETTINGS }
				title={ __( 'What gets counted', 'bubuku-post-view-count' ) }
				claim={ __(
					'Content types and visitors that generate a view.',
					'bubuku-post-view-count'
				) }
			>
				<div className="bbk-field-group">
					<p className="bbk-field-group__label">
						{ __( 'Content types', 'bubuku-post-view-count' ) }
					</p>
					<div className="bbk-checkbox-list">
						{ Object.entries( postTypes ).map(
							( [ slug, label ] ) => (
								<label
									key={ slug }
									className="bbk-checkbox"
									htmlFor={ `bbk-post-type-${ slug }` }
								>
									<input
										id={ `bbk-post-type-${ slug }` }
										type="checkbox"
										checked={ (
											settings.post_types || []
										).includes( slug ) }
										onChange={ () =>
											toggleInList( 'post_types', slug )
										}
									/>
									<span>{ label }</span>
								</label>
							)
						) }
					</div>
					<p className="bbk-field-group__help">
						{ __(
							'Unchecking a content type stops counting, but does not delete views already recorded. Checking it again resumes counting on top of the existing total.',
							'bubuku-post-view-count'
						) }
					</p>
				</div>

				<div className="bbk-field-group">
					<p className="bbk-field-group__label">
						{ __( 'Excluded roles', 'bubuku-post-view-count' ) }
					</p>
					<div className="bbk-checkbox-list">
						{ Object.entries( roles ).map( ( [ slug, label ] ) => (
							<label
								key={ slug }
								className="bbk-checkbox"
								htmlFor={ `bbk-role-${ slug }` }
							>
								<input
									id={ `bbk-role-${ slug }` }
									type="checkbox"
									checked={ (
										settings.excluded_roles || []
									).includes( slug ) }
									onChange={ () =>
										toggleInList( 'excluded_roles', slug )
									}
								/>
								<span>{ label }</span>
							</label>
						) ) }
					</div>
					<p className="bbk-field-group__help">
						{ __(
							'Logged-in users with one of these roles do not generate views when viewing their own content.',
							'bubuku-post-view-count'
						) }
					</p>
				</div>

				<div className="bbk-field-group">
					<label className="bbk-checkbox" htmlFor="bbk-exclude-bots">
						<input
							id="bbk-exclude-bots"
							type="checkbox"
							checked={ !! settings.exclude_bots }
							onChange={ ( e ) =>
								update( 'exclude_bots', e.target.checked )
							}
						/>
						{ __(
							'Do not count views from known bot user agents.',
							'bubuku-post-view-count'
						) }
					</label>
					<p className="bbk-field-group__help">
						{ __(
							'Includes, among others:',
							'bubuku-post-view-count'
						) }{ ' ' }
						{ ( context.bot_signature_examples || [] ).join(
							', '
						) }
					</p>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_SETTINGS }
				title={ __( 'AI & privacy', 'bubuku-post-view-count' ) }
				claim={ __(
					'AI bot tracking and visitor privacy signals.',
					'bubuku-post-view-count'
				) }
				animationDelay=".1s"
			>
				<div className="bbk-field-group">
					<label
						className="bbk-checkbox"
						htmlFor="bbk-ai-crawler-tracking"
					>
						<input
							id="bbk-ai-crawler-tracking"
							type="checkbox"
							checked={ !! settings.ai_crawler_tracking }
							onChange={ ( e ) =>
								update(
									'ai_crawler_tracking',
									e.target.checked
								)
							}
						/>
						{ __(
							'Count visits from known AI bots (GPTBot, ClaudeBot, PerplexityBot, etc.) in their own table, separate from human visitor counts.',
							'bubuku-post-view-count'
						) }
					</label>
					<p className="bbk-field-group__help">
						{ __(
							'Disabled by default: it adds a write on every request from these bots, which is not negligible on a site with heavy crawler traffic.',
							'bubuku-post-view-count'
						) }
					</p>
				</div>

				<div className="bbk-field-group">
					<label className="bbk-checkbox" htmlFor="bbk-respect-dnt">
						<input
							id="bbk-respect-dnt"
							type="checkbox"
							checked={ !! settings.respect_dnt }
							onChange={ ( e ) =>
								update( 'respect_dnt', e.target.checked )
							}
						/>
						{ __(
							'Respect the browser\'s "Do Not Track" (DNT) and Global Privacy Control (Sec-GPC) signal.',
							'bubuku-post-view-count'
						) }
					</label>
					<p className="bbk-field-group__help">
						{ __(
							'The view count stays the same (it is already anonymous, with no IP or user agent stored): this option only omits the device and referrer for that visit.',
							'bubuku-post-view-count'
						) }
					</p>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_SETTINGS }
				title={ __(
					'Performance & retention',
					'bubuku-post-view-count'
				) }
				claim={ __(
					'Database writes and daily history.',
					'bubuku-post-view-count'
				) }
				animationDelay=".15s"
			>
				<div className="bbk-field-group">
					<label
						className="bbk-field-group__label"
						htmlFor="bbk-retention-days"
					>
						{ __(
							'Daily aggregate retention',
							'bubuku-post-view-count'
						) }
					</label>
					<div className="bbk-input-wrap">
						<input
							id="bbk-retention-days"
							type="number"
							min="1"
							max="3650"
							className="bbk-input"
							value={ settings.retention_days ?? '' }
							onChange={ ( e ) =>
								update(
									'retention_days',
									Number( e.target.value )
								)
							}
						/>
						<span>{ __( 'days', 'bubuku-post-view-count' ) }</span>
					</div>
					<p className="bbk-field-group__help">
						{ __(
							'This only affects how many days of daily history are shown for each content item, not the total: you will only have history for that many days. The total view count is unaffected and is never deleted by this retention.',
							'bubuku-post-view-count'
						) }
					</p>
				</div>
			</DashboardCard>

			<BkSaveBar
				onSave={ handleSave }
				onReset={ handleReset }
				saving={ saving }
				status={ status }
				labelSave={ __( 'Save settings', 'bubuku-post-view-count' ) }
				labelSaving={ __( 'Saving…', 'bubuku-post-view-count' ) }
				labelReset={ __( 'Discard changes', 'bubuku-post-view-count' ) }
			/>

			<DashboardCard
				icon={ ICON_DANGER }
				iconVariant="icon-warning"
				title={ __( 'Delete all data', 'bubuku-post-view-count' ) }
				claim={ __(
					'Immediately deletes all recorded views (own tables and post meta). This action cannot be undone.',
					'bubuku-post-view-count'
				) }
				animationDelay=".2s"
			>
				<div className="bbk-field-group">
					<label
						className="bbk-checkbox"
						htmlFor="bbk-delete-on-uninstall"
					>
						<input
							id="bbk-delete-on-uninstall"
							type="checkbox"
							checked={ !! settings.delete_data_on_uninstall }
							onChange={ ( e ) =>
								update(
									'delete_data_on_uninstall',
									e.target.checked
								)
							}
						/>
						{ __(
							'Delete all plugin tables, meta, and options on uninstall.',
							'bubuku-post-view-count'
						) }
					</label>
				</div>

				<div className="bk-card-footer">
					<div className="bk-card-footer__info" />
					<button
						type="button"
						className="bk-btn bk-btn-secondary"
						onClick={ handleDeleteData }
						disabled={ deleting }
					>
						{ deleting
							? __( 'Deleting…', 'bubuku-post-view-count' )
							: __(
									'Delete all data now',
									'bubuku-post-view-count'
							  ) }
					</button>
				</div>
			</DashboardCard>
		</div>
	);
};

export default SettingsPanel;
