/**
 * Wedding Party RSVP — Interactivity API store (WordPress 6.5+).
 *
 * @package Wedding_Party_RSVP
 */
import { store, getContext } from '@wordpress/interactivity';

const STORE_NS = 'wedding-party-rsvp/rsvp';

store(
	STORE_NS,
	{
		state: {
			status: 'idle',
			isSubmitting: false,
			feedback: '',
			feedbackVariant: 'success',
		},
		actions: {
			/**
			 * Submit RSVP via admin-ajax (same rules as classic POST).
			 *
			 * @param {Event} event Submit event.
			 */
			async submitRsvp( event ) {
				event.preventDefault();
				const form = event.target;
				if ( ! ( form instanceof HTMLFormElement ) ) {
					return;
				}
				const ctx = getContext();

				this.state.status          = 'submitting';
				this.state.isSubmitting    = true;
				this.state.feedbackVariant = 'info';
				this.state.feedback        = ctx.i18n ? .submitting || '';

				const body = new FormData( form );
				body.set( 'action', 'wgrsvp_submit_rsvp' );
				body.set( 'wpr_submit_rsvp', '1' );

				try {
					const res  = await fetch(
						ctx.ajaxUrl,
						{
							method: 'POST',
							body,
							credentials: 'same-origin',
						}
					);
					const json = await res.json();

					if ( json.success ) {
						this.state.feedbackVariant = 'success';
						this.state.feedback        =
							json.data ? .message || ctx.i18n ? .success || '';
						this.state.status          = 'success';
						if (
							json.data ? .redirect &&
							typeof json.data.redirect === 'string'
						) {
							window.location.assign( json.data.redirect );
							return;
						}
						form.setAttribute( 'hidden', 'hidden' );
						form.setAttribute( 'aria-hidden', 'true' );
					} else {
						this.state.feedbackVariant = 'error';
						this.state.feedback        =
						json.data ? .message ||
						ctx.i18n ? .error ||
						'Something went wrong.';
						this.state.status          = 'error';
					}
				} catch ( e ) {
					this.state.feedbackVariant = 'error';
					this.state.feedback        =
					ctx.i18n ? .networkError || 'Network error.';
					this.state.status          = 'error';
				} finally {
					this.state.isSubmitting = false;
				}
			},
		},
	}
);
