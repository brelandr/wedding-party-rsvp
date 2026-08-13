/**
 * Collapsible groups under Wedding RSVP admin menu.
 *
 * @package Wedding_Party_RSVP
 */
( function () {
	'use strict';

	var cfg = window.wgrsvpAdminMenuGroups;
	if ( ! cfg || ! cfg.groups || ! cfg.groups.length ) {
		return;
	}

	/**
	 * @param {string} href
	 * @returns {string}
	 */
	function slugFromHref( href ) {
		if ( ! href ) {
			return '';
		}
		try {
			var url = new URL( href, window.location.origin );
			var page = url.searchParams.get( 'page' );
			if ( page ) {
				return page;
			}
			var postType = url.searchParams.get( 'post_type' );
			if ( postType ) {
				return postType;
			}
		} catch ( e ) {
			/* ignore */
		}
		var m = String( href ).match( /[?&]page=([^&#]+)/ );
		if ( m && m[1] ) {
			return decodeURIComponent( m[1] );
		}
		m = String( href ).match( /[?&]post_type=([^&#]+)/ );
		if ( m && m[1] ) {
			return decodeURIComponent( m[1] );
		}
		return '';
	}

	/**
	 * @returns {Object}
	 */
	function readStorage() {
		try {
			var raw = window.localStorage.getItem( cfg.storageKey || 'wgrsvp_admin_menu_groups' );
			if ( ! raw ) {
				return {};
			}
			var parsed = JSON.parse( raw );
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch ( e ) {
			return {};
		}
	}

	/**
	 * @param {Object} state
	 */
	function writeStorage( state ) {
		try {
			window.localStorage.setItem(
				cfg.storageKey || 'wgrsvp_admin_menu_groups',
				JSON.stringify( state )
			);
		} catch ( e ) {
			/* ignore quota / private mode */
		}
	}

	/**
	 * @returns {string}
	 */
	function currentPageSlug() {
		try {
			var params = new URLSearchParams( window.location.search );
			return params.get( 'page' ) || params.get( 'post_type' ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function init() {
		var top = document.getElementById( cfg.toplevelId || 'toplevel_page_wedding-rsvp-main' );
		if ( ! top ) {
			return;
		}
		var submenu = top.querySelector( '.wp-submenu' );
		if ( ! submenu ) {
			return;
		}

		var pinned = {};
		( cfg.pinnedSlugs || [] ).forEach( function ( s ) {
			pinned[ s ] = true;
		} );

		/** @type {Object.<string, HTMLElement>} */
		var bySlug = {};
		Array.prototype.forEach.call( submenu.children, function ( li ) {
			if ( ! li || li.tagName !== 'LI' ) {
				return;
			}
			var a = li.querySelector( 'a' );
			if ( ! a ) {
				return;
			}
			var slug = slugFromHref( a.getAttribute( 'href' ) || '' );
			if ( slug && ! bySlug[ slug ] ) {
				bySlug[ slug ] = li;
			}
		} );

		var stored = readStorage();
		var activeSlug = currentPageSlug();

		cfg.groups.forEach( function ( group ) {
			if ( ! group || ! group.id || ! group.slugs || ! group.slugs.length ) {
				return;
			}

			var items = [];
			group.slugs.forEach( function ( slug ) {
				if ( pinned[ slug ] ) {
					return;
				}
				var li = bySlug[ slug ];
				if ( li && items.indexOf( li ) === -1 ) {
					items.push( li );
				}
			} );
			if ( ! items.length ) {
				return;
			}

			var header = document.createElement( 'li' );
			header.className = 'wgrsvp-admin-menu-group';
			header.setAttribute( 'data-wgrsvp-group', group.id );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'wgrsvp-admin-menu-group__toggle';
			btn.setAttribute( 'aria-controls', 'wgrsvp-menu-group-' + group.id );

			var labelSpan = document.createElement( 'span' );
			labelSpan.className = 'wgrsvp-admin-menu-group__label';
			labelSpan.textContent = group.label || group.id;

			var chevron = document.createElement( 'span' );
			chevron.className = 'wgrsvp-admin-menu-group__chevron';
			chevron.setAttribute( 'aria-hidden', 'true' );
			chevron.textContent = '\u25B6';

			btn.appendChild( labelSpan );
			btn.appendChild( chevron );
			header.appendChild( btn );

			var anchor = items[0];
			submenu.insertBefore( header, anchor );

			var hasCurrent = false;
			var pendingTotal = 0;
			var prev = header;
			items.forEach( function ( li ) {
				li.classList.add( 'wgrsvp-admin-menu-group__item' );
				li.setAttribute( 'data-wgrsvp-group', group.id );
				if ( li.classList.contains( 'current' ) ) {
					hasCurrent = true;
				}
				var a = li.querySelector( 'a' );
				if ( a && activeSlug && slugFromHref( a.getAttribute( 'href' ) || '' ) === activeSlug ) {
					hasCurrent = true;
				}
				var pendingEl = li.querySelector( '.awaiting-mod .pending-count' );
				if ( pendingEl ) {
					var n = parseInt( pendingEl.textContent, 10 );
					if ( ! isNaN( n ) && n > 0 ) {
						pendingTotal += n;
					}
				}
				if ( li.parentNode ) {
					li.parentNode.insertBefore( li, prev.nextSibling );
				}
				prev = li;
			} );

			if ( pendingTotal > 0 ) {
				var badge = document.createElement( 'span' );
				badge.className = 'awaiting-mod count-' + pendingTotal;
				badge.innerHTML =
					'<span class="pending-count" aria-hidden="true">' +
					String( pendingTotal ) +
					'</span>';
				btn.insertBefore( badge, chevron );
			}

			var open = hasCurrent;
			if ( ! hasCurrent && Object.prototype.hasOwnProperty.call( stored, group.id ) ) {
				open = !! stored[ group.id ];
			}

			/**
			 * @param {boolean} isOpen
			 * @param {boolean} persist
			 */
			function setOpen( isOpen, persist ) {
				btn.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
				items.forEach( function ( li ) {
					if ( isOpen ) {
						li.classList.remove( 'is-collapsed' );
					} else {
						li.classList.add( 'is-collapsed' );
					}
				} );
				if ( persist ) {
					var next = readStorage();
					next[ group.id ] = isOpen;
					writeStorage( next );
				}
			}

			setOpen( open, false );

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				var expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
				setOpen( ! expanded, true );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
