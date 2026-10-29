/**
 * Staff buttons on the order screen (Shipments box) and on Orders → Tracking sync. Calls the plugin's admin REST
 * routes with the logged-in cookie and the REST nonce; the server checks capabilities per order.
 */
(function () {
	'use strict';

	var config = window.sfsTrackingAdmin;
	if (!config || !window.fetch) {
		return;
	}

	function message(scope, text, isError) {
		var box = scope.closest('.sfs-box, .sfs-admin');
		var node = box ? box.querySelector('[data-sfs-msg]') : null;
		if (!node) {
			return;
		}
		node.textContent = text;
		node.classList.toggle('is-error', !!isError);
	}

	function call(path, method, body) {
		return fetch(config.root + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!response.ok) {
					throw new Error(data && data.message ? data.message : 'Request failed (' + response.status + ').');
				}
				return data;
			});
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-sfs-action]') : null;
		if (!button) {
			return;
		}
		event.preventDefault();
		var action = button.getAttribute('data-sfs-action');
		var method = button.getAttribute('data-method') || 'POST';
		var body = {};
		var reload = true;

		if (button.hasAttribute('data-confirm') && !window.confirm(button.getAttribute('data-confirm'))) {
			return;
		}
		if (button.hasAttribute('data-order')) {
			body.order_id = Number(button.getAttribute('data-order'));
		}
		if (button.hasAttribute('data-key')) {
			body.key = button.getAttribute('data-key');
		}
		if (button.hasAttribute('data-id')) {
			body.id = Number(button.getAttribute('data-id'));
		}
		if (action === 'check-now') {
			reload = false;
		}
		if (action === 'shipment' && method === 'POST') {
			var box = button.closest('.sfs-box');
			body.carrier = box.querySelector('#sfs-add-carrier').value;
			body.tracking_number = box.querySelector('#sfs-add-number').value;
		}
		if (action === 'inbox/confirm') {
			var source = button.getAttribute('data-order-from');
			body.order_number = source ? (document.querySelector(source) || {}).value || '' : button.getAttribute('data-order-number') || '';
			if (!body.order_number) {
				message(button, 'Type our order number first.', true);
				return;
			}
		}

		button.disabled = true;
		message(button, 'Working…', false);
		call(action, method, body).then(function (data) {
			message(button, data.message || 'Done.', false);
			if (reload) {
				window.setTimeout(function () {
					window.location.reload();
				}, 1200);
			} else {
				button.disabled = false;
			}
		}).catch(function (error) {
			message(button, error.message, true);
			button.disabled = false;
		});
	});
})();
