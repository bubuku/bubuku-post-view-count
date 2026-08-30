const BkSaveBar = ( {
	onSave,
	onReset,
	saving,
	status,
	labelSave,
	labelSaving,
	labelReset,
} ) => {
	return (
		<div className="bk-save-bar">
			{ status && (
				<span className={ `bbk-notice bbk-notice--${ status.type }` }>
					{ status.message }
				</span>
			) }
			<div className="bk-save-bar__actions">
				<button
					type="button"
					className="bk-btn bk-btn-secondary"
					onClick={ onReset }
					disabled={ saving }
				>
					{ labelReset }
				</button>
				<button
					type="button"
					className="bk-btn bk-btn-primary"
					onClick={ onSave }
					disabled={ saving }
				>
					{ saving ? labelSaving : labelSave }
				</button>
			</div>
		</div>
	);
};

export default BkSaveBar;
