/**
 * Admin UI: confirm dialogs, select-on-click fields, print trigger (no inline handlers).
 *
 * Strings are provided via wp_localize_script as `wgrsvpAdminUi.strings`.
 */
(function () {
	'use strict';

	document.addEventListener(
		'click',
		function (e) {
			var target = e.target;
			if (!target || !target.closest) {
				return;
			}

			var printEl = target.closest('.wgrsvp-trigger-print');
			if (printEl) {
				e.preventDefault();
				window.print();
				return;
			}

			var selectEl = target.closest('.wgrsvp-select-on-click');
			if (selectEl && typeof selectEl.select === 'function') {
				selectEl.select();
				return;
			}

			var confirmEl = target.closest('.wgrsvp-admin-confirm');
			if (!confirmEl) {
				return;
			}

			var key = confirmEl.getAttribute('data-wgrsvp-confirm');
			if (!key) {
				return;
			}

			var pack = window.wgrsvpAdminUi;
			var msg = pack && pack.strings && pack.strings[key];
			if (typeof msg !== 'string') {
				return;
			}

			if (!window.confirm(msg)) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
		},
		true
	);
}());
