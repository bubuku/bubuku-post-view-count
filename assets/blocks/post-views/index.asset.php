<?php
/**
 * Manual dependency manifest for index.js — the hand-written equivalent of what
 * @wordpress/scripts would generate from a build. Read automatically by
 * register_block_type() when resolving the "file:./index.js" editorScript.
 *
 * @package Bubuku Post View Count
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-server-side-render',
		'wp-i18n',
	),
	'version'      => defined( 'BBK_PLUGIN_VERSION' ) ? BBK_PLUGIN_VERSION : '1.0.0',
);
