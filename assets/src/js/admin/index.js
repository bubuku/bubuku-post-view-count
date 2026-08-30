import { createRoot } from '@wordpress/element';
import App from './App';

document.addEventListener( 'DOMContentLoaded', function () {
	const container = document.getElementById( 'bbk-postview-app' );
	if ( ! container ) return;

	const root = createRoot( container );
	root.render( <App /> );
} );
