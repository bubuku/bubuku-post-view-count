import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Ajustes de vistas', 'bubuku-post-view-count' ) }>
						<ToggleControl
							label={ __( 'Mostrar la fecha de la última visita', 'bubuku-post-view-count' ) }
							checked={ !! attributes.showLastViewed }
							onChange={ ( value ) => setAttributes( { showLastViewed: value } ) }
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block={ metadata.name } attributes={ attributes } />
			</>
		);
	},
	// Server-rendered block (see render.php): nothing to save in post_content.
	save() {
		return null;
	},
} );
