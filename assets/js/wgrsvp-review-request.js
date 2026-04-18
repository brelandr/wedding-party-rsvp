/**
 * Review prompt: dismiss / track choice via admin-ajax (nonces from PHP).
 */
(function () {
	'use strict';
	var notice = document.getElementById('wgrsvp-review-request-notice');
	if (!notice) {
		return;
	}
	var cfg = window.wgrsvpReviewRequest || {};
	if (!cfg.ajaxUrl || !cfg.action) {
		return;
	}
	notice.addEventListener('click', function (e) {
		var btn = e.target.closest('.wgrsvp-review-btn');
		if (!btn) {
			return;
		}
		e.preventDefault();
		var action = btn.getAttribute('data-action');
		var nonce = btn.getAttribute('data-nonce');
		var url = action === 'yes' ? btn.getAttribute('data-review-url') : (action === 'no' ? btn.getAttribute('data-support-url') : null);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) {
				return;
			}
			try {
				var res = JSON.parse(xhr.responseText);
				if (res.success) {
					notice.style.opacity = '0';
					notice.style.transition = 'opacity 0.2s';
					setTimeout(function () {
						notice.remove();
					}, 250);
					if (url) {
						window.open(url, '_blank');
					}
				}
			} catch (err) {
				// Ignore parse errors.
			}
		};
		var body = new URLSearchParams();
		body.set('action', cfg.action);
		body.set('nonce', nonce);
		body.set('choice', action);
		xhr.send(body.toString());
	});
}());
