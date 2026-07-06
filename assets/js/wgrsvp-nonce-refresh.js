/**
 * Replace stale RSVP form nonces after page load (host/CDN HTML cache).
 *
 * @package Wedding_Party_RSVP
 */
( function () {
	'use strict';

	var cfg = window.wgrsvpNonceRefresh;
	if ( ! cfg || ! cfg.ajaxUrl || ! cfg.action ) {
		return;
	}

	var RSVP_NONCE_INPUT_SELECTOR =
		'[name="_wpnonce"], [name="wgrsvp_front_party_login_nonce"], [name="wpr_pro_front_party_login_nonce"], [name="wpr_pro_front_name_lookup_nonce"], [name="wpr_pro_front_pick_party_nonce"]';

	/**
	 * @returns {HTMLFormElement[]}
	 */
	function getRsvpForms() {
		var forms = [];
		document.querySelectorAll( 'form' ).forEach( function ( form ) {
			if ( !( form instanceof HTMLFormElement ) ) {
				return;
			}
			if ( form.querySelector( RSVP_NONCE_INPUT_SELECTOR ) ) {
				forms.push( form );
			}
		} );
		return forms;
	}

	/**
	 * @param {Record<string, string>} nonces
	 */
	function applyNonces( nonces ) {
		if ( ! nonces || typeof nonces !== 'object' ) {
			return;
		}

		Object.keys( nonces ).forEach( function ( fieldName ) {
			var val = nonces[ fieldName ];
			if ( typeof val !== 'string' || ! val ) {
				return;
			}

			if ( 'wpr_pro_rsvp_chat' === fieldName ) {
				var chatEl = document.getElementById( 'wpr-pro-rsvp-chat-nonce' );
				if ( chatEl instanceof HTMLInputElement ) {
					chatEl.value = val;
				}
				return;
			}

			document.querySelectorAll( '[name="' + fieldName + '"]' ).forEach( function ( el ) {
				if ( el instanceof HTMLInputElement ) {
					el.value = val;
				}
			} );
		} );
	}

	function detectPartyId() {
		var input = document.querySelector( 'input[name="party_id"]' );
		if ( input instanceof HTMLInputElement && input.value ) {
			return input.value;
		}
		try {
			var params = new URL( window.location.href ).searchParams;
			var fromUrl = params.get( 'party_id' );
			if ( typeof fromUrl === 'string' && fromUrl ) {
				return fromUrl;
			}
		} catch ( e ) {
			// Ignore malformed location.
		}
		return '';
	}

	/**
	 * @returns {Promise<boolean>}
	 */
	function fetchNonces() {
		var url = new URL( cfg.ajaxUrl, window.location.origin );
		url.searchParams.set( 'action', cfg.action );

		var partyId = detectPartyId();
		if ( partyId ) {
			url.searchParams.set( 'party_id', partyId );
		}

		return fetch( url.toString(), {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				Accept: 'application/json',
			},
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.nonces ) {
					applyNonces( json.data.nonces );
					getRsvpForms().forEach( function ( form ) {
						form.setAttribute( 'data-wgrsvp-nonce-ready', '1' );
					} );
					return true;
				}
				return false;
			} )
			.catch( function () {
				return false;
			} );
	}

	function bindSubmitGuard() {
		getRsvpForms().forEach( function ( form ) {
			if ( form.getAttribute( 'data-wgrsvp-nonce-guard' ) === '1' ) {
				return;
			}
			form.setAttribute( 'data-wgrsvp-nonce-guard', '1' );

			form.addEventListener(
				'submit',
				function ( ev ) {
					if ( form.getAttribute( 'data-wgrsvp-nonce-ready' ) === '1' ) {
						return;
					}
					ev.preventDefault();
					fetchNonces().then( function () {
						form.setAttribute( 'data-wgrsvp-nonce-ready', '1' );
						if ( typeof form.requestSubmit === 'function' ) {
							form.requestSubmit();
						} else {
							form.submit();
						}
					} );
				},
				true
			);
		} );
	}

	function init() {
		bindSubmitGuard();
		fetchNonces();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.addEventListener( 'pageshow', function ( ev ) {
		if ( ev.persisted ) {
			getRsvpForms().forEach( function ( form ) {
				form.removeAttribute( 'data-wgrsvp-nonce-ready' );
			} );
			fetchNonces();
		}
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' ) {
			fetchNonces();
		}
	} );

	window.wgrsvpRefreshRsvpNonces = fetchNonces;
} )();
