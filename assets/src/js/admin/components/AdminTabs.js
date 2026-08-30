/**
 * AdminTabs — underline-style tab bar for the admin page.
 *
 * UI-only: the parent owns the active-tab state and the panel it renders.
 *
 * @param {Object}   props
 * @param {Array}    props.tabs        - `[{ id, label, icon }]`.
 * @param {string}   props.activeTab   - Currently active tab id.
 * @param {Function} props.onChange    - `( id ) => void`, called when a tab is clicked.
 * @param {string}   [props.ariaLabel] - Accessible label for the tablist.
 */
const AdminTabs = ( { tabs, activeTab, onChange, ariaLabel } ) => {
	return (
		<div className="bbk-admin-tabs" role="tablist" aria-label={ ariaLabel }>
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.id }
					type="button"
					role="tab"
					aria-selected={ activeTab === tab.id }
					className={ `bbk-admin-tab ${
						activeTab === tab.id ? 'is-active' : ''
					}` }
					onClick={ () => onChange( tab.id ) }
				>
					{ tab.icon }
					{ tab.label }
				</button>
			) ) }
		</div>
	);
};

export default AdminTabs;
