/**
 * Settings: AI wording assistant (WordPress AI Client when available).
 *
 * @package Wedding_Party_RSVP
 */
(function ( $ ) {
	'use strict';

	function wgrsvpSetFieldValue( $t, val ) {
		if ( ! $t.length ) {
			return;
		}
		$t.val( val );
	}

	$( document ).on( 'click', '.wgrsvp-ai-wording-btn', function () {
		var $btn = $( this );
		if ( typeof wgrsvpAiWording === 'undefined' ) {
			return;
		}
		var ctx = $btn.data( 'wgrsvp-ai-context' ) || '';
		var sel = $btn.data( 'wgrsvp-ai-target' ) || '';
		if ( ! wgrsvpAiWording.has_ai_client ) {
			window.alert( wgrsvpAiWording.i18n.need_wp7 );
			return;
		}
		var goals = window.prompt( wgrsvpAiWording.i18n.promptGoals, '' );
		if ( goals === null ) {
			return;
		}
		$btn.prop( 'disabled', true );
		$.post( wgrsvpAiWording.ajaxUrl, {
			action: wgrsvpAiWording.action,
			nonce: wgrsvpAiWording.nonce,
			context: ctx,
			goals: goals
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data && typeof res.data.text === 'string' ) {
					wgrsvpSetFieldValue( $( sel ), res.data.text );
					if ( window.wp && wp.a11y && wp.a11y.speak ) {
						wp.a11y.speak( wgrsvpAiWording.i18n.done );
					}
					return;
				}
				var msg = wgrsvpAiWording.i18n.ajax_failed;
				if ( res && res.data && res.data.message ) {
					msg = String( res.data.message );
				}
				window.alert( msg );
			} )
			.fail( function ( xhr ) {
				var msg = wgrsvpAiWording.i18n.ajax_failed;
				if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					msg = String( xhr.responseJSON.data.message );
				}
				window.alert( msg );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );
})( jQuery );
