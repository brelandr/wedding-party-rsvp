/**
 * Settings: Pro teaser tabs + upgrade modal (free plugin).
 */
(function () {
	'use strict';

	var doc       = document;
	var root      = doc.documentElement;
	var lastFocus = null;

	function getConfig() {
		if (typeof window.wgrsvpProTeaser === 'undefined') {
			return null;
		}
		return window.wgrsvpProTeaser;
	}

	function buildUpgradeUrl(feature) {
		var cfg = getConfig();
		if ( ! cfg || ! cfg.upgradeUrl) {
			return '#';
		}
		try {
			var u = new URL( cfg.upgradeUrl, window.location.href );
			u.searchParams.set( 'utm_content', feature );
			return u.toString();
		} catch (e) {
			return cfg.upgradeUrl;
		}
	}

	function initTabs(wrap) {
		var tabs   = wrap.querySelectorAll( '.wgrsvp-pro-teaser-tab' );
		var panels = wrap.querySelectorAll( '.wgrsvp-pro-teaser-panel' );

		function activate(targetId) {
			var i;
			for (i = 0; i < tabs.length; i++) {
				var t     = tabs[i];
				var isSel = t.getAttribute( 'data-wgrsvp-target' ) === targetId;
				t.classList.toggle( 'nav-tab-active', isSel );
				t.setAttribute( 'aria-selected', isSel ? 'true' : 'false' );
			}
			for (i = 0; i < panels.length; i++) {
				var p     = panels[i];
				var match = p.id === targetId;
				p.toggleAttribute( 'hidden', ! match );
				p.classList.toggle( 'is-active', match );
			}
		}

		tabs.forEach(
			function (tab) {
				tab.addEventListener(
					'click',
					function (ev) {
						ev.preventDefault();
						var tid = tab.getAttribute( 'data-wgrsvp-target' );
						if (tid) {
							activate( tid );
						}
					}
				);
			}
		);
	}

	function openModal(feature) {
		var cfg   = getConfig();
		var modal = doc.getElementById( 'wgrsvp-pro-teaser-modal' );
		if ( ! modal || ! cfg || ! cfg.i18n || ! cfg.i18n[feature]) {
			return;
		}

		lastFocus = doc.activeElement;

		var copy    = cfg.i18n[feature];
		var titleEl = modal.querySelector( '[data-wgrsvp-modal-title]' );
		var bodyEl  = modal.querySelector( '[data-wgrsvp-modal-body]' );
		var ctaEl   = modal.querySelector( '[data-wgrsvp-modal-cta]' );

		if (titleEl) {
			titleEl.textContent = copy.title || '';
		}
		if (bodyEl) {
			bodyEl.textContent = copy.body || '';
		}
		if (ctaEl) {
			ctaEl.textContent = copy.cta || '';
			ctaEl.setAttribute( 'href', buildUpgradeUrl( feature ) );
		}

		modal.removeAttribute( 'hidden' );
		modal.classList.add( 'is-open' );
		modal.setAttribute( 'aria-hidden', 'false' );
		root.classList.add( 'wgrsvp-pro-teaser-modal-open' );

		var closeBtn = modal.querySelector( '.wgrsvp-pro-teaser-modal__close' );
		if (closeBtn) {
			closeBtn.focus();
		}
	}

	function closeModal() {
		var modal = doc.getElementById( 'wgrsvp-pro-teaser-modal' );
		if ( ! modal) {
			return;
		}
		modal.setAttribute( 'hidden', 'hidden' );
		modal.classList.remove( 'is-open' );
		modal.setAttribute( 'aria-hidden', 'true' );
		root.classList.remove( 'wgrsvp-pro-teaser-modal-open' );

		if (lastFocus && typeof lastFocus.focus === 'function') {
			lastFocus.focus();
		}
		lastFocus = null;
	}

	function initModal() {
		var modal = doc.getElementById( 'wgrsvp-pro-teaser-modal' );
		if ( ! modal) {
			return;
		}

		modal.querySelector( '.wgrsvp-pro-teaser-modal__backdrop' ).addEventListener( 'click', closeModal );

		modal.querySelector( '.wgrsvp-pro-teaser-modal__close' ).addEventListener( 'click', closeModal );

		doc.addEventListener(
			'keydown',
			function (ev) {
				if (ev.key === 'Escape' && modal.classList.contains( 'is-open' )) {
					ev.preventDefault();
					closeModal();
				}
			}
		);

		doc.querySelectorAll( '[data-wgrsvp-pro-feature]' ).forEach(
			function (el) {
				el.addEventListener(
					'click',
					function (ev) {
						ev.preventDefault();
						var feat = el.getAttribute( 'data-wgrsvp-pro-feature' );
						if (feat) {
							openModal( feat );
						}
					}
				);
			}
		);
	}

	function boot() {
		var wrap = doc.querySelector( '.wgrsvp-pro-teaser-wrap' );
		if (wrap) {
			initTabs( wrap );
		}
		initModal();
	}

	if (doc.readyState === 'loading') {
		doc.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
