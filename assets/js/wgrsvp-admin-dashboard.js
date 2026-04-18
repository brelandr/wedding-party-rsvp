/**
 * Guest list: copy public RSVP link to clipboard (URLs from data-* attributes).
 */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var t = e.target.closest('.wgrsvp-copy-rsvp');
		if (!t || !t.dataset.url) {
			return;
		}
		e.preventDefault();
		var u = t.dataset.url;
		var ok = t.dataset.copied;
		var l = t.dataset.label;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(u).then(function () {
				t.textContent = ok;
				setTimeout(function () {
					t.textContent = l;
				}, 1800);
			});
		}
	});
}());
