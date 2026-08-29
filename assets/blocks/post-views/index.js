/**
 * Editor script for the `bubuku/post-views` block.
 *
 * Hand-written, plain JS (no JSX, no build step — see AGENTS.md). Dependencies
 * for this file are declared in the sibling index.asset.php, the manual
 * equivalent of what @wordpress/scripts would otherwise generate.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	registerBlockType( 'bubuku/post-views', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				wp.element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Ajustes de vistas', 'bubuku-post-view-count' ) },
						el( ToggleControl, {
							label: __( 'Mostrar la fecha de la última visita', 'bubuku-post-view-count' ),
							checked: !! attributes.showLastViewed,
							onChange: function ( value ) {
								setAttributes( { showLastViewed: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'bubuku/post-views',
					attributes: attributes,
				} )
			);
		},
		save: function () {
			// Server-rendered block (see render.php): nothing to save in post_content.
			return null;
		},
	} );
} )( window.wp );
