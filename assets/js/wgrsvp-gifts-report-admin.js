/**
 * Gifts report admin: bulk "select all" checkboxes.
 *
 * @package Wedding_Party_RSVP
 */
( function () {
	'use strict';

	var all = document.getElementById( 'wgrsvp-gifts-select-all' );
	if ( ! all ) {
		return;
	}
	all.addEventListener( 'change', function () {
		document.querySelectorAll( '.wgrsvp-gifts-bulk-cb' ).forEach( function ( c ) {
			c.checked = all.checked;
		} );
	} );
}() );
