/**
 * Static Publish: form handling on the static site.
 *
 * This file is never served by the PHP site. The publish command copies it
 * into the static copy and adds one script tag to the pages that have a
 * form, so the site's own templates, CSS and JavaScript stay exactly as they
 * are.
 *
 * On the PHP site a form posts, the page reloads, and errors come back in
 * the session. A static page has no session, so the same form is sent with
 * fetch instead and Statamic answers in JSON: 200 with an optional redirect,
 * or 400 with a message per field.
 *
 * The markup added here is deliberately bare: two classes, sp-form-error on
 * a field message and sp-form-message on the one above the form. Styling
 * them belongs in the site's own stylesheet.
 */
(function () {
	'use strict';

	var script = document.currentScript;
	var successText = (script && script.dataset.success) || 'Tak for din besked.';
	var networkText = 'Beskeden kunne ikke sendes. Prøv igen om lidt.';

	document.addEventListener('submit', function (event) {
		var form = event.target;

		if (!(form instanceof HTMLFormElement)) {
			return;
		}

		if ((form.getAttribute('action') || '').indexOf('/!/forms/') === -1) {
			return;
		}

		event.preventDefault();
		send(form);
	});

	function send(form) {
		clear(form);
		buttons(form, true);

		fetch(form.action, {
			method: 'POST',
			body: new FormData(form),
			headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
		})
			.then(function (response) {
				return response
					.json()
					.catch(function () {
						return {};
					})
					.then(function (data) {
						return { status: response.status, data: data };
					});
			})
			.then(function (result) {
				if (result.status === 200 && result.data.success) {
					succeed(form, result.data.redirect);

					return;
				}

				buttons(form, false);

				if (result.status === 400) {
					fail(form, result.data);

					return;
				}

				message(form, result.data.error || networkText);
			})
			.catch(function () {
				buttons(form, false);
				message(form, networkText);
			});
	}

	/**
	 * Statamic decides where a submission goes: a form's own redirect, or the
	 * page it came from. With no redirect the form gives way to the success
	 * message, which is what a visitor on the PHP site would see after the
	 * reload.
	 */
	function succeed(form, redirect) {
		if (redirect) {
			window.location.assign(redirect);

			return;
		}

		var note = message(form, successText);

		form.hidden = true;
		note.focus();
	}

	/**
	 * A 400 carries `error` with one message per field handle and `errors`
	 * with the same messages as a flat list. Each message goes next to its
	 * own field, and the list above the form so a visitor who cannot see the
	 * fields still hears what is wrong.
	 */
	function fail(form, data) {
		var fields = data.error || {};
		var first = null;

		Object.keys(fields).forEach(function (handle) {
			var input = form.querySelector('[name="' + cssEscape(handle) + '"]');

			if (!input) {
				return;
			}

			var note = document.createElement('p');
			note.className = 'sp-form-error';
			note.textContent = fields[handle];

			var anchor = input.closest('label') || input;
			anchor.parentNode.insertBefore(note, anchor.nextSibling);

			input.setAttribute('aria-invalid', 'true');
			first = first || input;
		});

		var list = data.errors && data.errors.length ? data.errors : Object.keys(fields).map(function (handle) {
			return fields[handle];
		});

		var note = message(form, list.join(' '));

		(first || note).focus();
	}

	/** The one live region above the form, created on first use. */
	function message(form, text) {
		var note = form.previousElementSibling;

		if (!note || !note.classList.contains('sp-form-message')) {
			note = document.createElement('p');
			note.className = 'sp-form-message';
			note.setAttribute('role', 'alert');
			note.setAttribute('tabindex', '-1');
			form.parentNode.insertBefore(note, form);
		}

		note.textContent = text;

		return note;
	}

	function clear(form) {
		Array.prototype.forEach.call(form.querySelectorAll('.sp-form-error'), function (note) {
			note.remove();
		});

		Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (input) {
			input.removeAttribute('aria-invalid');
		});

		var note = form.previousElementSibling;

		if (note && note.classList.contains('sp-form-message')) {
			note.remove();
		}
	}

	function buttons(form, disabled) {
		Array.prototype.forEach.call(form.querySelectorAll('button, [type="submit"]'), function (button) {
			button.disabled = disabled;
		});
	}

	/** A field handle is a word, but quoting it costs nothing and is honest. */
	function cssEscape(value) {
		return window.CSS && window.CSS.escape ? window.CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
	}
})();
