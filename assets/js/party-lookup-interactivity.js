/**
 * Party ID lookup hint (debounced REST preview) — Interactivity API.
 *
 * @package Wedding_Party_RSVP
 */
import { store, getContext } from '@wordpress/interactivity';

const STORE_NS = 'wedding-party-rsvp/party-lookup';

let debounceTimer = 0;

const { state, actions } = store( STORE_NS, {
	state: {
		hint: '',
		hintVisible: false,
	},
	actions: {
		/**
		 * @param {Event} event Input event.
		 */
		onPartyInput( event ) {
			const input = event.target;
			const raw   = input && input.value ? String( input.value ).trim() : '';
			const ctx   = getContext();

			window.clearTimeout( debounceTimer );

			if ( raw.length < 2 ) {
				state.hint        = '';
				state.hintVisible = false;
				return;
			}

			state.hint        = ctx.i18n?.loading || '';
			state.hintVisible = true;

			debounceTimer = window.setTimeout( () => {
				actions.fetchPreview( raw, ctx );
			}, 450 );
		},

		/**
		 * @param {string} partyId Party ID.
		 * @param {Object} ctx     Interactivity context.
		 */
		async fetchPreview( partyId, ctx ) {
			try {
				const base = ctx.restUrl || '';
				const url  = new URL( base, window.location.origin );
				url.searchParams.set( 'party_id', partyId );

				const res  = await fetch( url.toString(), { credentials: 'same-origin' } );
				const data = await res.json();

				if ( ! res.ok ) {
					state.hint        = ctx.i18n?.rateLimited || ctx.i18n?.notFound || '';
					state.hintVisible = '' !== state.hint;
					return;
				}

				const found = data && data.found;
				const count = data && data.guest_count ? parseInt( data.guest_count, 10 ) : 0;
				const names = data && Array.isArray( data.preview_names ) ? data.preview_names.join( ', ' ) : '';

				if ( found ) {
					let msg = ctx.i18n?.foundSummary || '';
					msg     = msg.replace( '%1$d', String( count ) ).replace( '%2$s', names );
					state.hint        = msg;
					state.hintVisible = true;
				} else {
					state.hint        = ctx.i18n?.notFound || '';
					state.hintVisible = true;
				}
			} catch ( e ) {
				state.hint        = '';
				state.hintVisible = false;
			}
		},
	},
} );
