(function () {
	'use strict';

	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	onReady(function () {
		document.querySelectorAll('[data-wgrsvp-copy]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var text = btn.getAttribute('data-wgrsvp-copy') || '';
				if (!text) {
					return;
				}
				var done = function () {
					btn.setAttribute('data-copied', '1');
					var prev = btn.textContent;
					btn.textContent = btn.getAttribute('data-wgrsvp-copied-label') || 'Copied';
					window.setTimeout(function () {
						btn.setAttribute('data-copied', '0');
						btn.textContent = prev;
					}, 1600);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done).catch(function () {
						window.prompt('Copy group code:', text);
					});
				} else {
					window.prompt('Copy group code:', text);
				}
			});
		});
	});
})();
