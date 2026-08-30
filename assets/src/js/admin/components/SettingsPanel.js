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
 * SettingsPanel — the 8 settings fields (docs/PENDING-ADMIN-UI-REACT.md Fase 5),
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
					message: __(
						'Ajustes guardados.',
						'bubuku-post-view-count'
					),
				} );
			} )
			.catch( () => {
				setSaving( false );
				setStatus( {
					type: 'error',
					message: __(
						'No se han podido guardar los ajustes.',
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
				'¿Seguro que quieres eliminar todas las vistas registradas? Esta acción no se puede deshacer.',
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
						'Se han eliminado todas las vistas registradas.',
						'bubuku-post-view-count'
					),
				} );
			} )
			.catch( () => {
				setDeleting( false );
				setStatus( {
					type: 'error',
					message: __(
						'No se han podido eliminar los datos.',
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
				title={ __( 'Qué se cuenta', 'bubuku-post-view-count' ) }
				claim={ __(
					'Tipos de contenido y visitantes que generan una vista.',
					'bubuku-post-view-count'
				) }
			>
				<div className="bbk-field-group">
					<p className="bbk-field-group__label">
						{ __( 'Tipos de contenido', 'bubuku-post-view-count' ) }
					</p>
					{ Object.entries( postTypes ).map( ( [ slug, label ] ) => (
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
							{ label }
						</label>
					) ) }
					<p className="bbk-field-group__help">
						{ __(
							'Desmarcar un tipo de contenido detiene el conteo, pero no borra las visitas ya registradas. Volver a marcarlo reanuda el conteo sobre el total existente.',
							'bubuku-post-view-count'
						) }
					</p>
				</div>

				<div className="bbk-field-group">
					<p className="bbk-field-group__label">
						{ __( 'Roles excluidos', 'bubuku-post-view-count' ) }
					</p>
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
							{ label }
						</label>
					) ) }
					<p className="bbk-field-group__help">
						{ __(
							'Los usuarios logados con uno de estos roles no generan visitas al ver sus propios contenidos.',
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
							'No contar visitas de user-agents de bots conocidos.',
							'bubuku-post-view-count'
						) }
					</label>
					<p className="bbk-field-group__help">
						{ __(
							'Incluye, entre otros:',
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
				title={ __( 'IA y privacidad', 'bubuku-post-view-count' ) }
				claim={ __(
					'Rastreo de bots de IA y señales de privacidad del visitante.',
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
							'Contar las visitas de bots de IA conocidos (GPTBot, ClaudeBot, PerplexityBot, etc.) en una tabla propia, separada del conteo de visitantes humanos.',
							'bubuku-post-view-count'
						) }
					</label>
					<p className="bbk-field-group__help">
						{ __(
							'Desactivado por defecto: añade una escritura en cada petición de estos bots, lo que no es despreciable en un sitio con mucho tráfico de crawlers.',
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
							'Respetar la señal "No rastrear" (DNT) y Global Privacy Control (Sec-GPC) del navegador.',
							'bubuku-post-view-count'
						) }
					</label>
					<p className="bbk-field-group__help">
						{ __(
							'El conteo de vistas se mantiene igual (ya es anónimo, sin IP ni user-agent almacenados): esta opción solo omite el dispositivo y la procedencia de esa visita.',
							'bubuku-post-view-count'
						) }
					</p>
				</div>
			</DashboardCard>

			<DashboardCard
				icon={ ICON_SETTINGS }
				title={ __(
					'Rendimiento y retención',
					'bubuku-post-view-count'
				) }
				claim={ __(
					'Escrituras en base de datos e histórico diario.',
					'bubuku-post-view-count'
				) }
				animationDelay=".15s"
			>
				<div className="bbk-field-group">
					<label className="bbk-checkbox" htmlFor="bbk-write-buffer">
						<input
							id="bbk-write-buffer"
							type="checkbox"
							checked={ !! settings.write_buffer }
							onChange={ ( e ) =>
								update( 'write_buffer', e.target.checked )
							}
						/>
						{ __(
							'Acumular incrementos en memoria y escribirlos en la base de datos por lotes, cada minuto, en vez de en cada visita.',
							'bubuku-post-view-count'
						) }
					</label>
					<p className="bbk-field-group__help">
						{ context.has_object_cache
							? __(
									'Este sitio tiene un object cache persistente activo: el buffer tendrá efecto.',
									'bubuku-post-view-count'
							  )
							: __(
									'Este sitio no tiene un object cache persistente (Redis, Memcached…) activo: sin uno, esta opción no tiene ningún efecto — cada visita se sigue escribiendo de inmediato.',
									'bubuku-post-view-count'
							  ) }
					</p>
				</div>

				<div className="bbk-field-group">
					<label
						className="bbk-field-group__label"
						htmlFor="bbk-retention-days"
					>
						{ __(
							'Retención del agregado diario',
							'bubuku-post-view-count'
						) }
					</label>
					<div className="bbk-input-wrap">
						<input
							id="bbk-retention-days"
							type="number"
							min="1"
							className="bbk-input"
							value={ settings.retention_days ?? '' }
							onChange={ ( e ) =>
								update(
									'retention_days',
									Number( e.target.value )
								)
							}
						/>
						<span>{ __( 'días', 'bubuku-post-view-count' ) }</span>
					</div>
					<p className="bbk-field-group__help">
						{ __(
							'Solo afecta a mostrar los datos diarios de cada contenido, no al total: solo dispondrás del historial de esos días. El total de vistas no se ve afectado y nunca se borra por esta retención.',
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
				labelSave={ __( 'Guardar ajustes', 'bubuku-post-view-count' ) }
				labelSaving={ __( 'Guardando…', 'bubuku-post-view-count' ) }
				labelReset={ __(
					'Descartar cambios',
					'bubuku-post-view-count'
				) }
			/>

			<DashboardCard
				icon={ ICON_DANGER }
				iconVariant="icon-warning"
				title={ __(
					'Eliminar todos los datos',
					'bubuku-post-view-count'
				) }
				claim={ __(
					'Elimina inmediatamente todas las vistas registradas (tablas propias y post meta). Esta acción no se puede deshacer.',
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
							'Eliminar todas las tablas, meta y opciones del plugin al desinstalarlo.',
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
							? __( 'Eliminando…', 'bubuku-post-view-count' )
							: __(
									'Eliminar todos los datos ahora',
									'bubuku-post-view-count'
							  ) }
					</button>
				</div>
			</DashboardCard>
		</div>
	);
};

export default SettingsPanel;
