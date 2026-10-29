/**
 * Live tracking for the Shipments card (templates/shipments.php renders the first paint).
 *
 * Polls POST /sfs-tracking/v1/track every 60 s while the tab is visible, pauses while hidden, refreshes right away
 * when the tab becomes visible again, backs off on errors and stops when the 2-hour token expires. Builds DOM with
 * textContent only.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-sfs-shipments]');
	if (!root || !window.fetch) {
		return;
	}

	var INTERVAL = 60000;
	var MAX_INTERVAL = 300000;
	var endpoint = root.getAttribute('data-endpoint');
	var token = root.getAttribute('data-token');
	var etag = root.getAttribute('data-etag') || '';
	var expires = Number(root.getAttribute('data-expires')) * 1000;
	var offset = Number(root.getAttribute('data-server-time')) * 1000 - Date.now();
	var interval = INTERVAL;
	var timer = 0;
	var inflight = false;
	var stopped = false;
	var lastFetch = Date.now();
	var list = root.querySelector('[data-sfs-list]');
	var live = root.querySelector('[data-sfs-live]');

	function serverNow() {
		return Date.now() + (isFinite(offset) ? offset : 0);
	}

	// Mirrors PublicView::updated_text().
	function updatedText(checked, carrierName, status, checkable) {
		if (!checkable) {
			return '';
		}
		if (!checked) {
			return status === 'delivered' ? '' : 'Waiting for the first ' + carrierName + ' scan';
		}
		var age = Math.max(0, Math.floor((serverNow() - checked * 1000) / 1000));
		if (age < 60) {
			return 'Updated just now';
		}
		if (age < 3600) {
			return 'Updated ' + Math.floor(age / 60) + ' min ago';
		}
		if (age < 86400) {
			var hours = Math.floor(age / 3600);
			return 'Updated ' + hours + (hours > 1 ? ' hours ago' : ' hour ago');
		}
		return 'Updated ' + new Date(checked * 1000).toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'America/Los_Angeles' });
	}

	function refreshAges() {
		root.querySelectorAll('[data-sfs-checked]').forEach(function (node) {
			var checked = Number(node.getAttribute('data-sfs-checked')) || 0;
			var carrierName = node.getAttribute('data-carrier-name') || '';
			var status = (node.closest('.sfs-package') || node).getAttribute('data-status') || '';
			var checkable = node.getAttribute('data-checkable') !== '0';
			if (checked) {
				node.textContent = updatedText(checked, carrierName, status, checkable);
			}
		});
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		return node;
	}

	function packageNode(item, openKeys) {
		var key = item.carrier + ':' + item.tracking_number;
		var li = el('li', 'sfs-package sfs-package--' + item.status);
		li.setAttribute('data-key', key);
		li.setAttribute('data-status', item.status);
		li.setAttribute('data-status-label', item.status_label);

		var head = el('div', 'sfs-package__head');
		head.appendChild(el('p', 'sfs-package__name', (item.count > 1 ? 'Package ' + item.index + ' of ' + item.count + ' · ' : '') + item.carrier_name));
		head.appendChild(el('span', 'order-badge order-badge--' + item.tone, item.status_label));
		li.appendChild(head);

		var number = el('p', 'sfs-package__number');
		number.appendChild(el('span', 'sfs-package__label', 'Tracking number'));
		number.appendChild(el('code', '', item.tracking_number));
		var copy = el('button', 'sfs-copy', 'Copy');
		copy.type = 'button';
		copy.setAttribute('data-sfs-copy', item.tracking_number);
		copy.setAttribute('aria-label', 'Copy tracking number ' + item.tracking_number);
		number.appendChild(copy);
		li.appendChild(number);

		if (item.delivered_at) {
			var delivered = el('p', 'sfs-package__eta');
			delivered.appendChild(el('strong', '', 'Delivered'));
			delivered.appendChild(document.createTextNode(' ' + item.delivered_at));
			li.appendChild(delivered);
		} else if (item.eta) {
			var eta = el('p', 'sfs-package__eta');
			eta.appendChild(el('strong', '', 'Estimated delivery'));
			eta.appendChild(document.createTextNode(' ' + item.eta.label + (item.eta.window ? ', ' + item.eta.window : '') + ' '));
			eta.appendChild(el('small', '', '(' + item.eta.source + ')'));
			li.appendChild(eta);
		}

		if (item.last_event) {
			var latest = el('p', 'sfs-package__latest');
			latest.appendChild(el('span', 'sfs-package__label', 'Latest'));
			latest.appendChild(document.createTextNode(' ' + [item.last_event.description, item.last_event.location, item.last_event.when].filter(Boolean).join(' · ')));
			li.appendChild(latest);
		}

		if (item.events && item.events.length > 1) {
			var details = el('details', 'sfs-package__history');
			if (openKeys[key]) {
				details.open = true;
			}
			details.appendChild(el('summary', '', 'Tracking history (' + item.events.length + ')'));
			var events = el('ol', 'sfs-events');
			item.events.forEach(function (event) {
				var row = el('li', 'sfs-event sfs-event--' + (event.kind || 'scan'));
				row.appendChild(el('span', 'sfs-event__when', event.when));
				var what = el('span', 'sfs-event__what', event.description);
				if (event.note) {
					what.appendChild(document.createTextNode(' '));
					what.appendChild(el('span', 'sfs-event__note', event.note));
				}
				row.appendChild(what);
				if (event.location) {
					row.appendChild(el('span', 'sfs-event__where', event.location));
				}
				events.appendChild(row);
			});
			details.appendChild(events);
			li.appendChild(details);
		}

		var foot = el('div', 'sfs-package__foot');
		var checkable = item.carrier === 'ups' || item.carrier === 'fedex';
		var updated = el('p', 'sfs-package__updated', updatedText(item.last_checked_at || 0, item.carrier_name, item.status, checkable));
		updated.setAttribute('data-sfs-checked', item.last_checked_at ? String(item.last_checked_at) : '');
		updated.setAttribute('data-carrier-name', item.carrier_name);
		updated.setAttribute('data-checkable', checkable ? '1' : '0');
		foot.appendChild(updated);
		if (item.tracking_url && /^https:\/\//.test(item.tracking_url)) {
			var link = el('a', 'sfs-package__carrier', 'Track on ' + item.carrier_name + ' ');
			link.href = item.tracking_url;
			link.target = '_blank';
			link.rel = 'noopener';
			var arrow = el('span', '', '→');
			arrow.setAttribute('aria-hidden', 'true');
			link.appendChild(arrow);
			link.appendChild(el('span', 'sfs-visually-hidden', ' (opens in a new tab)'));
			foot.appendChild(link);
		}
		li.appendChild(foot);
		return li;
	}

	function render(data) {
		var openKeys = {};
		var previous = {};
		list.querySelectorAll('.sfs-package').forEach(function (node) {
			var key = node.getAttribute('data-key');
			previous[key] = node.getAttribute('data-status-label');
			var details = node.querySelector('details');
			if (details && details.open) {
				openKeys[key] = true;
			}
		});

		var fragment = document.createDocumentFragment();
		var changes = [];
		data.shipments.forEach(function (item) {
			var key = item.carrier + ':' + item.tracking_number;
			if (previous[key] && previous[key] !== item.status_label) {
				changes.push((item.count > 1 ? 'Package ' + item.index + ': ' : '') + item.status_label);
			}
			fragment.appendChild(packageNode(item, openKeys));
		});
		list.textContent = '';
		list.appendChild(fragment);

		var note = root.querySelector('[data-sfs-note]');
		if (note) {
			note.textContent = data.order.note || '';
			note.hidden = !data.order.note;
		}
		if (changes.length && live) {
			live.textContent = 'Tracking updated: ' + changes.join('; ') + '.';
		}
	}

	function stop() {
		stopped = true;
		window.clearTimeout(timer);
		var expired = root.querySelector('[data-sfs-expired]');
		if (expired) {
			expired.hidden = false;
		}
	}

	function schedule() {
		window.clearTimeout(timer);
		if (stopped || document.visibilityState !== 'visible') {
			return;
		}
		timer = window.setTimeout(poll, interval);
	}

	function backOff() {
		interval = Math.min(interval * 2, MAX_INTERVAL);
	}

	function poll() {
		if (stopped || inflight || document.visibilityState !== 'visible') {
			return;
		}
		if (expires && serverNow() >= expires) {
			stop();
			return;
		}
		inflight = true;
		lastFetch = Date.now();
		var headers = { 'Content-Type': 'application/json' };
		if (etag) {
			headers['If-None-Match'] = etag;
		}
		fetch(endpoint, { method: 'POST', headers: headers, body: JSON.stringify({ t: token }), credentials: 'omit', cache: 'no-store' })
			.then(function (response) {
				if (response.status === 304) {
					interval = INTERVAL;
					return null;
				}
				if (response.status === 401 || response.status === 404) {
					stop();
					return null;
				}
				if (!response.ok) {
					backOff();
					return null;
				}
				etag = response.headers.get('ETag') || etag;
				interval = INTERVAL;
				return response.json();
			})
			.then(function (data) {
				if (data && data.shipments && data.order) {
					if (data.server_time) {
						offset = data.server_time * 1000 - Date.now();
					}
					if (data.expires_at) {
						expires = data.expires_at * 1000;
					}
					render(data);
				}
			})
			.catch(backOff)
			.then(function () {
				inflight = false;
				refreshAges();
				schedule();
			});
	}

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') {
			if (Date.now() - lastFetch > 10000) {
				poll();
			} else {
				schedule();
			}
		} else {
			window.clearTimeout(timer);
		}
	});

	root.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-sfs-copy]') : null;
		if (!button || !navigator.clipboard) {
			return;
		}
		navigator.clipboard.writeText(button.getAttribute('data-sfs-copy')).then(function () {
			button.textContent = 'Copied';
			window.setTimeout(function () {
				button.textContent = 'Copy';
			}, 2000);
		});
	});

	window.setInterval(function () {
		if (document.visibilityState === 'visible') {
			refreshAges();
		}
	}, 30000);

	schedule();
})();
