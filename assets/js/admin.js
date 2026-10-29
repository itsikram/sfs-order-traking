/**
 * Staff buttons on the order screen (Shipments box) and on Orders → Tracking sync. Calls the plugin's admin REST
 * routes with the logged-in cookie and the REST nonce; the server checks capabilities per order.
 */
(function () {
	'use strict';

	// Quick Edit on the order list: fill the B2Sign order number field from the row's Shipment column.
	if (window.inlineEditPost && typeof window.inlineEditPost.edit === 'function') {
		var edit = window.inlineEditPost.edit;
		window.inlineEditPost.edit = function (id) {
			edit.apply(this, arguments);
			var postId = typeof id === 'object' ? this.getId(id) : id;
			var data = document.querySelector('#post-' + postId + ' .sfs-quick-data');
			var field = document.querySelector('#edit-' + postId + ' input[name="sfs_b2_orders"]');
			if (data && field) {
				field.value = data.getAttribute('data-b2') || '';
			}
		};
	}

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

	// Review queue bulk actions: each bar works on the checkboxes in the table or list right after it.
	document.querySelectorAll('[data-sfs-bulk]').forEach(function (bar) {
		var table = bar.nextElementSibling;
		if (!table || !table.hasAttribute('data-sfs-bulk-table')) {
			return;
		}
		var all = table.querySelector('[data-sfs-select-all]');
		var count = bar.querySelector('[data-sfs-bulk-count]');
		var boxes = function () {
			return Array.prototype.slice.call(table.querySelectorAll('[data-sfs-select]'));
		};
		var chosen = function () {
			return boxes().filter(function (box) {
				return box.checked;
			});
		};
		var refresh = function () {
			var n = chosen().length;
			count.textContent = n + ' selected';
			boxes().forEach(function (box) {
				var row = box.closest('tr');
				if (row) {
					row.classList.toggle('is-selected', box.checked);
				}
			});
			if (all) {
				all.checked = n > 0 && n === boxes().length;
				all.indeterminate = n > 0 && n < boxes().length;
			}
		};
		table.addEventListener('change', function (event) {
			if (all && event.target === all) {
				boxes().forEach(function (box) {
					box.checked = all.checked;
				});
			}
			refresh();
		});
		bar.querySelector('[data-sfs-bulk-apply]').addEventListener('click', function () {
			var action = bar.querySelector('select').value;
			var ids = chosen().map(function (box) {
				return Number(box.value);
			});
			if (!action) {
				message(bar, 'Choose a bulk action first.', true);
				return;
			}
			if (!ids.length) {
				message(bar, 'Select at least one item.', true);
				return;
			}
			if (action === 'confirm') {
				var none = chosen().filter(function (box) {
					return box.hasAttribute('data-no-suggestion');
				}).length;
				var text = 'Link ' + ids.length + ' supplier item(s) to their suggested orders?' + (none ? ' ' + none + ' without a suggestion will be skipped.' : '') + ' Orders whose items have all shipped are marked Shipped and emailed (unless dry run is on).';
				if (!window.confirm(text)) {
					return;
				}
			}
			if (action === 'delete' && !window.confirm('Delete ' + ids.length + ' supplier item(s) from this list? If B2Sign still lists an item, the next daily sync adds it back. Use "Mark not ours" to hide an item for good.')) {
				return;
			}
			var apply = bar.querySelector('[data-sfs-bulk-apply]');
			apply.disabled = true;
			message(bar, 'Working on ' + ids.length + ' item(s)…', false);
			call('inbox/bulk', 'POST', { action: action, ids: ids }).then(function (data) {
				message(bar, data.message || 'Done.', false);
				window.setTimeout(function () {
					window.location.reload();
				}, data.skipped ? 4000 : 1500);
			}).catch(function (error) {
				message(bar, error.message, true);
				apply.disabled = false;
			});
		});
		refresh();
	});

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
