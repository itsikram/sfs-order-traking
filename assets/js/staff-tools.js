/**
 * Staff box on the front-end order page: saves the B2Sign order number and a tracking number through the
 * plugin's admin REST route (logged-in cookie + REST nonce), then reloads to show the result.
 */
(function () {
	'use strict';

	var config = window.sfsTrackingStaff;
	if (!config || !window.fetch) {
		return;
	}

	document.querySelectorAll('[data-sfs-staff]').forEach(function (form) {
		var msg = form.querySelector('[data-sfs-staff-msg]');
		var button = form.querySelector('button[type="submit"]');
		var say = function (text, isError) {
			msg.textContent = text;
			msg.classList.toggle('is-error', !!isError);
		};
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			button.disabled = true;
			say('Saving…', false);
			fetch(config.root + 'order-tracking', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
				body: JSON.stringify({
					order_id: Number(form.getAttribute('data-order')),
					b2_orders: form.elements.b2_orders.value,
					carrier: form.elements.carrier.value,
					tracking_number: form.elements.tracking_number.value
				})
			}).then(function (response) {
				return response.json().catch(function () {
					return {};
				}).then(function (data) {
					if (!response.ok) {
						throw new Error(data && data.message ? data.message : 'Could not save (' + response.status + ').');
					}
					return data;
				});
			}).then(function (data) {
				say(data.message || 'Saved.', false);
				window.setTimeout(function () {
					window.location.reload();
				}, 2500);
			}).catch(function (error) {
				say(error.message, true);
				button.disabled = false;
			});
		});
	});
})();
