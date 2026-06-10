/**
 * Wedding Party RSVP — confirm-before-action helper.
 *
 * Shows a confirm() prompt for any form, link, or button carrying a
 * data-wgrsvp-confirm attribute. Replaces inline on* handlers so no
 * JavaScript is printed inside admin page markup.
 *
 * @package Wedding_Party_RSVP
 */

( function () {
	'use strict';

	document.addEventListener(
		'submit',
		function ( e ) {
			var form = e.target;
			if (
				form &&
				form.hasAttribute &&
				form.hasAttribute( 'data-wgrsvp-confirm' ) &&
				! window.confirm( form.getAttribute( 'data-wgrsvp-confirm' ) )
			) {
				e.preventDefault();
			}
		},
		true
	);

	document.addEventListener( 'click', function ( e ) {
		var el =
			e.target && e.target.closest
				? e.target.closest(
						'a[data-wgrsvp-confirm], button[data-wgrsvp-confirm]'
				  )
				: null;
		if (
			el &&
			! window.confirm( el.getAttribute( 'data-wgrsvp-confirm' ) )
		) {
			e.preventDefault();
			e.stopImmediatePropagation();
		}
	} );
} )();
