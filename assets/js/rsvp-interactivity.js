/**
 * Wedding Party RSVP — Interactivity API store (WordPress 6.5+); watch() when available (WP 7+).
 *
 * @package Wedding_Party_RSVP
 */
import * as wpInteractivity from '@wordpress/interactivity';

const STORE_NS = 'wedding-party-rsvp/rsvp';

const emailDebounce = new Map();

/**
 * Render guest hub summary into the interactive region (text nodes only; data from same AJAX as form).
 *
 * @param {HTMLElement|null} root  .wgrsvp-rsvp-interactive
 * @param {Object}           hub   guest_hub payload
 * @param {Object}           i18n  context.i18n
 */
function mountGuestHubFromPayload( root, hub, i18n ) {
	if ( ! root || ! hub || ! Array.isArray( hub.guests ) ) {
		return;
	}
	const mount = root.querySelector( '[data-wgrsvp-guest-hub-root]' );
	if ( ! mount ) {
		return;
	}
	mount.replaceChildren();
	mount.hidden = false;

	const card = document.createElement( 'div' );
	card.className = 'wgrsvp-guest-hub wpr-guest-card';

	const h3 = document.createElement( 'h3' );
	h3.className = 'wgrsvp-guest-hub__heading';
	h3.textContent = i18n.hubTitle || '';
	card.appendChild( h3 );

	if ( hub.event_title ) {
		const p = document.createElement( 'p' );
		p.className = 'wgrsvp-guest-hub__event';
		const strong = document.createElement( 'strong' );
		strong.textContent = String( hub.event_title );
		p.appendChild( strong );
		card.appendChild( p );
	}
	if ( hub.event_start ) {
		const p = document.createElement( 'p' );
		p.className = 'wgrsvp-guest-hub__time';
		p.appendChild(
			document.createTextNode(
				( i18n.hubWhen || '' ) + ' ' + String( hub.event_start )
			)
		);
		card.appendChild( p );
	}
	if ( hub.event_location ) {
		const p = document.createElement( 'p' );
		p.className = 'wgrsvp-guest-hub__where';
		p.appendChild(
			document.createTextNode(
				( i18n.hubWhere || '' ) + ' ' + String( hub.event_location )
			)
		);
		card.appendChild( p );
	}
	if ( hub.maps_url ) {
		const p = document.createElement( 'p' );
		p.className = 'wgrsvp-guest-hub__maps';
		const a = document.createElement( 'a' );
		a.className = 'wpr-button';
		a.href = hub.maps_url;
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		a.textContent = i18n.hubMaps || '';
		p.appendChild( a );
		card.appendChild( p );
	}

	const schedule = Array.isArray( hub.schedule ) ? hub.schedule : [];
	if ( schedule.length ) {
		const schedHeading = document.createElement( 'h4' );
		schedHeading.className = 'wgrsvp-guest-hub__schedule-heading';
		schedHeading.textContent =
			hub.scheduleHeading || i18n.hubSchedule || 'Your schedule';
		card.appendChild( schedHeading );
		const sul = document.createElement( 'ul' );
		sul.className = 'wgrsvp-guest-hub__schedule';
		schedule.forEach( ( item ) => {
			if ( ! item || typeof item !== 'object' ) {
				return;
			}
			const li = document.createElement( 'li' );
			const title = document.createElement( 'strong' );
			title.textContent = item.title != null ? String( item.title ) : '';
			li.appendChild( title );
			if ( item.start ) {
				li.appendChild(
					document.createTextNode( ' — ' + String( item.start ) )
				);
			}
			if ( item.status ) {
				li.appendChild(
					document.createTextNode( ' (' + String( item.status ) + ')' )
				);
			}
			sul.appendChild( li );
		} );
		card.appendChild( sul );
	}

	const travel = hub.travel && typeof hub.travel === 'object' ? hub.travel : null;
	const travelHeading =
		( travel && travel.heading ) ||
		hub.travelHeading ||
		hub.hotel_name ||
		'';
	if (
		travel ||
		hub.hotel_url ||
		hub.hotel_name ||
		hub.travel_note
	) {
		const th = document.createElement( 'h4' );
		th.className = 'wgrsvp-guest-hub__travel-heading';
		th.textContent =
			travelHeading || i18n.hubTravel || 'Travel & lodging';
		card.appendChild( th );
		const hotelName =
			( travel && travel.hotelName ) || hub.hotel_name || '';
		const hotelUrl = ( travel && travel.hotelUrl ) || hub.hotel_url || '';
		const hotelCode =
			( travel && travel.hotelCode ) || hub.hotel_code || '';
		const cutoff =
			( travel && travel.cutoff ) || hub.hotel_cutoff || '';
		const note = ( travel && travel.note ) || hub.travel_note || '';
		if ( hotelName ) {
			const p = document.createElement( 'p' );
			p.className = 'wgrsvp-guest-hub__hotel-name';
			p.textContent = String( hotelName );
			card.appendChild( p );
		}
		if ( hotelUrl ) {
			const p = document.createElement( 'p' );
			const a = document.createElement( 'a' );
			a.className = 'wpr-button';
			a.href = String( hotelUrl );
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			a.textContent = i18n.hubHotelBook || 'Book lodging';
			p.appendChild( a );
			card.appendChild( p );
		}
		if ( hotelCode ) {
			const p = document.createElement( 'p' );
			p.className = 'wgrsvp-guest-hub__hotel-code';
			p.textContent =
				( i18n.hubHotelCode || 'Group code:' ) + ' ' + String( hotelCode );
			card.appendChild( p );
		}
		if ( cutoff ) {
			const p = document.createElement( 'p' );
			p.className = 'wgrsvp-guest-hub__hotel-cutoff';
			p.textContent = String( cutoff );
			card.appendChild( p );
		}
		if ( note ) {
			const p = document.createElement( 'p' );
			p.className = 'wgrsvp-guest-hub__travel-note';
			p.textContent = String( note );
			card.appendChild( p );
		}
	}

	const ul = document.createElement( 'ul' );
	ul.className = 'wgrsvp-guest-hub__guests';
	hub.guests.forEach( ( g ) => {
		if ( ! g || typeof g !== 'object' ) {
			return;
		}
		const li = document.createElement( 'li' );
		const name = document.createElement( 'strong' );
		name.textContent = g.name != null ? String( g.name ) : '';
		li.appendChild( name );
		li.appendChild(
			document.createTextNode( ' — ' + ( g.rsvp != null ? String( g.rsvp ) : '' ) )
		);
		const meal = g.meal != null ? String( g.meal ).trim() : '';
		if ( meal !== '' ) {
			li.appendChild( document.createElement( 'br' ) );
			const span = document.createElement( 'span' );
			span.className = 'wgrsvp-guest-hub__meal';
			span.textContent =
				( i18n.hubMeal || '' ) + ' ' + meal;
			li.appendChild( span );
		}
		const childMeal =
			g.child_meal != null ? String( g.child_meal ).trim() : '';
		if ( childMeal !== '' ) {
			li.appendChild( document.createElement( 'br' ) );
			const span = document.createElement( 'span' );
			span.className = 'wgrsvp-guest-hub__meal wgrsvp-guest-hub__child-meal';
			span.textContent =
				( i18n.hubChildMeal || '' ) + ' ' + childMeal;
			li.appendChild( span );
		}
		const appetizer =
			g.appetizer != null ? String( g.appetizer ).trim() : '';
		if ( appetizer !== '' ) {
			li.appendChild( document.createElement( 'br' ) );
			const span = document.createElement( 'span' );
			span.className = 'wgrsvp-guest-hub__course';
			span.textContent =
				( i18n.hubAppetizer || '' ) + ' ' + appetizer;
			li.appendChild( span );
		}
		const hors =
			g.hors_doeuvre != null ? String( g.hors_doeuvre ).trim() : '';
		if ( hors !== '' ) {
			li.appendChild( document.createElement( 'br' ) );
			const span = document.createElement( 'span' );
			span.className = 'wgrsvp-guest-hub__course';
			span.textContent = ( i18n.hubHors || '' ) + ' ' + hors;
			li.appendChild( span );
		}
		const dessert =
			g.dessert != null ? String( g.dessert ).trim() : '';
		if ( dessert !== '' ) {
			li.appendChild( document.createElement( 'br' ) );
			const span = document.createElement( 'span' );
			span.className = 'wgrsvp-guest-hub__course';
			span.textContent = ( i18n.hubDessert || '' ) + ' ' + dessert;
			li.appendChild( span );
		}
		const diet = g.dietary != null ? String( g.dietary ).trim() : '';
		const all = g.allergies != null ? String( g.allergies ).trim() : '';
		const note = [ diet, all ].filter( Boolean ).join( '; ' );
		if ( note !== '' ) {
			li.appendChild( document.createElement( 'br' ) );
			const span = document.createElement( 'span' );
			span.className = 'wgrsvp-guest-hub__diet';
			span.textContent = note;
			li.appendChild( span );
		}
		ul.appendChild( li );
	} );
	card.appendChild( ul );
	mount.appendChild( card );
}

const { state } = wpInteractivity.store( STORE_NS, {
	state: {
		status: 'idle',
		isSubmitting: false,
		feedback: '',
		feedbackVariant: 'success',
		showCalendar: false,
		calendarUrl: '',
	},
	actions: {
		/**
		 * Show entrée detail field when guest picks Vegetarian / Vegan.
		 *
		 * @param {Event} event Change event.
		 */
		onGuestMenuChange( event ) {
			const target = event.target;
			const v      = target && target.value ? String( target.value ) : '';
			const ctx    = wpInteractivity.getContext();
			ctx.showMenuDetail = v === 'Vegetarian' || v === 'Vegan';
		},

		/**
		 * Debounced email format hint (does not replace server validation).
		 *
		 * @param {Event} event Input event.
		 */
		onGuestEmailInput( event ) {
			const input = event.target;
			if ( ! ( input instanceof HTMLInputElement ) ) {
				return;
			}
			const ctx = wpInteractivity.getContext();
			const v   = input.value.trim();
			const prev = emailDebounce.get( input );
			if ( prev ) {
				window.clearTimeout( prev );
			}
			if ( v === '' ) {
				ctx.emailValid = '';
				emailDebounce.delete( input );
				return;
			}
			const t = window.setTimeout( () => {
				const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v );
				const i18n = ctx.i18n && typeof ctx.i18n === 'object' ? ctx.i18n : {};
				ctx.emailValid = ok
					? String( i18n.emailOk || '✓' )
					: String( i18n.emailBad || '✗' );
				emailDebounce.delete( input );
			}, 450 );
			emailDebounce.set( input, t );
		},

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
			const ctx = wpInteractivity.getContext();

			state.status = 'submitting';
			state.isSubmitting = true;
			state.feedbackVariant = 'info';
			state.feedback =
				ctx.i18n?.submitting || '';

			if (
				typeof window.wgrsvpRefreshRsvpNonces === 'function'
			) {
				await window.wgrsvpRefreshRsvpNonces();
			}

			const body = new FormData( form );
			body.set( 'action', 'wgrsvp_submit_rsvp' );
			body.set( 'wpr_submit_rsvp', '1' );

			try {
				const res = await fetch( ctx.ajaxUrl, {
					method: 'POST',
					body,
					credentials: 'same-origin',
				} );
				const json = await res.json();

				if ( json.success ) {
					state.feedbackVariant = 'success';
					state.feedback =
						json.data?.message || ctx.i18n?.success || '';
					state.status = 'success';

					if (
						json.data?.show_calendar &&
						json.data?.ics_url &&
						typeof json.data.ics_url === 'string'
					) {
						state.showCalendar = true;
						state.calendarUrl = json.data.ics_url;
					} else {
						state.showCalendar = false;
					}

					if (
						json.data?.redirect &&
						typeof json.data.redirect === 'string'
					) {
						window.location.assign( json.data.redirect );
						return;
					}

					const hp = json.data?.household_prompt;
					if ( hp && hp.show && hp.first_pending_guest_id ) {
						const pid = hp.first_pending_guest_id;
						const wrap =
							form.closest( '.wgrsvp-rsvp-interactive' ) ||
							form.parentElement;
						if (
							wrap &&
							! wrap.querySelector( '[data-wgrsvp-household-prompt]' )
						) {
							const p = document.createElement( 'div' );
							p.setAttribute( 'data-wgrsvp-household-prompt', '1' );
							p.setAttribute( 'role', 'status' );
							p.className =
								'wgrsvp-household-prompt notice notice-info';
							p.style.cssText = 'margin:1em 0;padding:12px;';
							const msg =
								ctx.i18n?.householdPrompt ||
								'Your party has more than one invitation. If anyone is still marked Pending, you can complete their RSVP on this same page.';
							const scrollLabel =
								ctx.i18n?.householdScroll ||
								'Scroll to next pending guest';
							const dismissLabel =
								ctx.i18n?.dismiss || 'Dismiss';

							const p1 = document.createElement( 'p' );
							p1.style.cssText = 'margin:0 0 8px 0;';
							p1.textContent = msg;

							const p2 = document.createElement( 'p' );
							p2.style.margin = '0';

							const btnScroll = document.createElement( 'button' );
							btnScroll.type = 'button';
							btnScroll.className = 'button';
							btnScroll.textContent = scrollLabel;

							const btnDismiss = document.createElement( 'button' );
							btnDismiss.type = 'button';
							btnDismiss.className = 'button-link';
							btnDismiss.textContent = dismissLabel;

							p2.appendChild( btnScroll );
							p2.appendChild( document.createTextNode( ' ' ) );
							p2.appendChild( btnDismiss );

							p.appendChild( p1 );
							p.appendChild( p2 );

							btnScroll.addEventListener( 'click', () => {
								const row = document.getElementById(
									'wgrsvp-guest-row-' + String( pid )
								);
								if ( row ) {
									row.scrollIntoView( {
										block: 'center',
										behavior: 'smooth',
									} );
									const first = row.querySelector(
										'select, input[type="radio"]'
									);
									if ( first ) {
										first.focus();
									}
								}
							} );
							btnDismiss.addEventListener( 'click', () => {
								p.remove();
							} );
							form.insertAdjacentElement( 'afterend', p );
						}
					}

					const iaRoot =
						form.closest( '.wgrsvp-rsvp-interactive' ) ||
						form.parentElement;
					if ( json.data?.guest_hub && iaRoot ) {
						const i18n =
							ctx.i18n && typeof ctx.i18n === 'object'
								? ctx.i18n
								: {};
						mountGuestHubFromPayload(
							iaRoot,
							json.data.guest_hub,
							i18n
						);
					}

					form.setAttribute( 'hidden', 'hidden' );
					form.setAttribute( 'aria-hidden', 'true' );
				} else {
					state.feedbackVariant = 'error';
					state.feedback =
						json.data?.message ||
						ctx.i18n?.error ||
						'Something went wrong.';
					state.status = 'error';
				}
			} catch ( e ) {
				state.feedbackVariant = 'error';
				state.feedback =
					ctx.i18n?.networkError || 'Network error.';
				state.status = 'error';
			} finally {
				state.isSubmitting = false;
			}
		},
	},
} );

if (
	typeof wpInteractivity.watch === 'function' &&
	state &&
	typeof state === 'object'
) {
	wpInteractivity.watch( () => {
		const busy = !! state.isSubmitting;
		const st   = state.status || '';
		document.querySelectorAll( '.wgrsvp-rsvp-interactive' ).forEach( ( root ) => {
			root.classList.toggle( 'wgrsvp-rsvp-is-busy', busy );
			if ( st ) {
				root.setAttribute( 'data-wgrsvp-rsvp-status', String( st ) );
			}
		} );
	} );
}
