/**
 * Scroll/focus mailing address when opened from a details/nudge link.
 *
 * Localized as wgrsvpAddressFocus: { banner }.
 *
 * @package Wedding_Party_RSVP
 */
( function () {
	'use strict';

	var cfg = window.wgrsvpAddressFocus || {};
	var bannerText = cfg.banner || '';
	var tries = 0;

	function run() {
		var form = document.querySelector( '.wpr-wrapper form,form.wpr-rsvp-form,form' );
		var el = document.querySelector(
			'.wgrsvp-mailing-address,textarea[name*="[address]"],input[name*="[address]"]'
		);
		if ( ! el ) {
			if ( tries++ < 40 ) {
				window.setTimeout( run, 150 );
			}
			return;
		}
		if ( form && ! document.querySelector( '.wgrsvp-details-banner' ) ) {
			var b = document.createElement( 'div' );
			b.className = 'wgrsvp-details-banner';
			b.setAttribute( 'role', 'status' );
			b.style.cssText =
				'margin:0 0 12px;padding:10px 12px;border-left:4px solid #2271b1;background:#f0f6fc;';
			b.textContent = bannerText;
			form.insertBefore( b, form.firstChild );
		}
		el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		try {
			el.focus();
		} catch ( e ) {
			// Ignore focus failures.
		}
		el.style.outline = '2px solid #2271b1';
		el.style.outlineOffset = '2px';
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
