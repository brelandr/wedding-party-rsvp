/**
 * Guest list DataViews (admin): mount on #wgrsvp-dataviews-spike-root.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import App from './app';
import './style.scss';

domReady( () => {
	const el = document.getElementById( 'wgrsvp-dataviews-spike-root' );
	if ( ! el ) {
		return;
	}
	el.classList.add( 'wgrsvp-guest-dataviews-root' );
	createRoot( el ).render( <App /> );
} );
