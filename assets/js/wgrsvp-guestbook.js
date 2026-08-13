/**
 * Public guestbook form submit + captcha (reCAPTCHA v3 primary, Turnstile backup).
 */
(function () {
	'use strict';

	var captchaState = {
		provider: 'none',
		widgetId: null,
		ready: false,
		mode: 'none', // v3 | turnstile | none
	};

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function cfg() {
		return (typeof wgrsvpGuestbook !== 'undefined' && wgrsvpGuestbook.captcha) || {};
	}

	function getSlot(root) {
		return root.querySelector('.wgrsvp-guestbook__captcha-slot');
	}

	function markRecaptchaReady() {
		var c = cfg();
		if (!c.recaptchaSiteKey) {
			return false;
		}
		if (typeof window.grecaptcha === 'undefined' || typeof window.grecaptcha.execute !== 'function') {
			return false;
		}
		captchaState.provider = 'recaptcha';
		captchaState.mode = 'v3';
		captchaState.ready = true;
		return true;
	}

	function mountTurnstile(slot, siteKey) {
		if (!slot || !siteKey || typeof window.turnstile === 'undefined' || !window.turnstile.render) {
			return false;
		}
		try {
			slot.innerHTML = '';
			captchaState.widgetId = window.turnstile.render(slot, { sitekey: siteKey });
			captchaState.provider = 'turnstile';
			captchaState.mode = 'turnstile';
			captchaState.ready = true;
			return true;
		} catch (e) {
			return false;
		}
	}

	function tryPrepareProviders() {
		var c = cfg();
		if (captchaState.ready && captchaState.mode === 'v3') {
			return;
		}
		if (c.recaptchaSiteKey && markRecaptchaReady()) {
			return;
		}
		if (captchaState.ready) {
			return;
		}
		var roots = document.querySelectorAll('[data-wgrsvp-guestbook]');
		roots.forEach(function (root) {
			if (captchaState.ready) {
				return;
			}
			var slot = getSlot(root);
			if (c.turnstileSiteKey) {
				mountTurnstile(slot, c.turnstileSiteKey);
			}
		});
	}

	function pollCaptchaApis(attemptsLeft) {
		if ((captchaState.ready && captchaState.mode === 'v3') || attemptsLeft < 1) {
			if (!captchaState.ready) {
				tryPrepareProviders();
			}
			return;
		}
		tryPrepareProviders();
		if (!captchaState.ready || captchaState.mode !== 'v3') {
			setTimeout(function () {
				pollCaptchaApis(attemptsLeft - 1);
			}, 250);
		}
	}

	function executeRecaptchaV3() {
		var c = cfg();
		var siteKey = c.recaptchaSiteKey || '';
		var action = c.recaptchaAction || 'wgrsvp_guestbook';
		return new Promise(function (resolve, reject) {
			if (!siteKey || typeof window.grecaptcha === 'undefined') {
				reject(new Error('recaptcha_unavailable'));
				return;
			}
			window.grecaptcha.ready(function () {
				window.grecaptcha
					.execute(siteKey, { action: action })
					.then(resolve)
					.catch(reject);
			});
		});
	}

	function readTurnstileToken(form) {
		var input = form.querySelector('[name="cf-turnstile-response"]');
		if (input && input.value) {
			return input.value;
		}
		if (typeof window.turnstile !== 'undefined' && captchaState.widgetId !== null) {
			try {
				return window.turnstile.getResponse(captchaState.widgetId) || '';
			} catch (e) {
				return '';
			}
		}
		return '';
	}

	function resetCaptcha() {
		if (captchaState.mode === 'turnstile' && typeof window.turnstile !== 'undefined') {
			try {
				window.turnstile.reset(captchaState.widgetId);
			} catch (e) {
				/* ignore */
			}
		}
		var roots = document.querySelectorAll('[data-wgrsvp-guestbook]');
		roots.forEach(function (root) {
			var hidden = root.querySelector('input[name="g-recaptcha-response"]');
			if (hidden) {
				hidden.value = '';
			}
		});
	}

	function captchaRequired() {
		var c = cfg();
		return !!(c.recaptchaSiteKey || c.turnstileSiteKey);
	}

	function sendForm(form, status, extra) {
		if (status) {
			status.hidden = false;
			status.textContent = (wgrsvpGuestbook.i18n && wgrsvpGuestbook.i18n.working) || 'Sending…';
		}
		var data = new FormData(form);
		data.append('action', 'wgrsvp_guestbook_submit');
		data.append('nonce', wgrsvpGuestbook.nonce || '');
		if (extra && extra['g-recaptcha-response']) {
			data.set('g-recaptcha-response', extra['g-recaptcha-response']);
		}
		if (extra && extra['cf-turnstile-response']) {
			data.set('cf-turnstile-response', extra['cf-turnstile-response']);
		}
		fetch(wgrsvpGuestbook.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				if (!json || !json.success) {
					if (status) {
						status.textContent =
							(json && json.data && json.data.message) ||
							(wgrsvpGuestbook.i18n && wgrsvpGuestbook.i18n.error) ||
							'Error';
					}
					resetCaptcha();
					return;
				}
				form.reset();
				resetCaptcha();
				if (status) {
					status.textContent =
						(json.data && json.data.message) ||
						(wgrsvpGuestbook.i18n && wgrsvpGuestbook.i18n.success) ||
						'Thank you!';
				}
			})
			.catch(function () {
				if (status) {
					status.textContent =
						(wgrsvpGuestbook.i18n && wgrsvpGuestbook.i18n.error) || 'Error';
				}
				resetCaptcha();
			});
	}

	function submitWithCaptcha(form, status) {
		var c = cfg();
		tryPrepareProviders();

		if (c.recaptchaSiteKey && captchaState.mode === 'v3') {
			executeRecaptchaV3()
				.then(function (token) {
					var hidden = form.querySelector('input[name="g-recaptcha-response"]');
					if (hidden) {
						hidden.value = token;
					}
					sendForm(form, status, { 'g-recaptcha-response': token });
				})
				.catch(function () {
					// Fall back to Turnstile if mounted / available.
					if (c.turnstileSiteKey) {
						if (captchaState.mode !== 'turnstile') {
							var slot = getSlot(form.closest('[data-wgrsvp-guestbook]') || document);
							mountTurnstile(slot, c.turnstileSiteKey);
						}
						var tTok = readTurnstileToken(form);
						if (tTok) {
							sendForm(form, status, { 'cf-turnstile-response': tTok });
							return;
						}
					}
					if (status) {
						status.hidden = false;
						status.textContent =
							(wgrsvpGuestbook.i18n && wgrsvpGuestbook.i18n.captchaNeeded) ||
							'Please complete the security check.';
					}
				});
			return;
		}

		if (c.turnstileSiteKey) {
			if (captchaState.mode !== 'turnstile') {
				var slot2 = getSlot(form.closest('[data-wgrsvp-guestbook]') || document);
				mountTurnstile(slot2, c.turnstileSiteKey);
			}
			var token = readTurnstileToken(form);
			if (!token) {
				if (status) {
					status.hidden = false;
					status.textContent =
						(wgrsvpGuestbook.i18n && wgrsvpGuestbook.i18n.captchaNeeded) ||
						'Please complete the security check.';
				}
				return;
			}
			sendForm(form, status, { 'cf-turnstile-response': token });
			return;
		}

		sendForm(form, status, {});
	}

	ready(function () {
		var roots = document.querySelectorAll('[data-wgrsvp-guestbook]');
		if (!roots.length || typeof wgrsvpGuestbook === 'undefined') {
			return;
		}

		pollCaptchaApis(40);

		roots.forEach(function (root) {
			var form = root.querySelector('.wgrsvp-guestbook__form');
			var status = root.querySelector('.wgrsvp-guestbook__status');
			if (!form) {
				return;
			}
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!captchaRequired()) {
					sendForm(form, status, {});
					return;
				}
				submitWithCaptcha(form, status);
			});
		});
	});
})();
