/*
 * ShipStatic: keep Bricks' fresh nonces between static pages.
 *
 * An exported page carries the nonces from when it was rendered, which have
 * expired by the time most visitors arrive. Bricks copes — its first filter
 * or form request is refused, it asks WordPress for new nonces and sends
 * again — but on every page. This remembers the fresh ones (for 11 hours;
 * WordPress accepts a nonce for 12 to 24) and puts them into bricksData
 * before Bricks uses them, so only the first page of a visit pays for the
 * extra requests. A nonce that has gone stale anyway is just refreshed again.
 * Added by ShipStatic to exported pages with Bricks forms, filters or AJAX
 * loops only.
 */
(function () {
	var KEY = 'shipstatic:bricksNonces:v2';
	var MAX_AGE = 11 * 3600 * 1000;
	var FIELDS = ['nonce', 'wpRestNonce', 'formNonce'];
	var data = window.bricksData;
	if (!data) {
		return;
	}

	function valid(value) {
		return typeof value === 'string' && /^[a-f0-9]{10}$/.test(value);
	}

	function read() {
		try {
			return JSON.parse(window.localStorage.getItem(KEY) || 'null');
		} catch (e) {
			return null;
		}
	}

	// What the page uses now: each remembered nonce that is still recent
	// ({ nonce: { v, t }, … } — only ones Bricks refreshed, each with its time).
	var stored = read() || {};
	FIELDS.forEach(function (field) {
		var item = stored[field];
		if (item && valid(item.v) && typeof item.t === 'number' && Date.now() - item.t < MAX_AGE) {
			data[field] = item.v;
		}
	});
	var used = {};
	FIELDS.forEach(function (field) {
		used[field] = data[field];
	});

	// Bricks replaces one in bricksData after a refresh: remember that one.
	function save() {
		var record = read() || {};
		var changed = false;
		FIELDS.forEach(function (field) {
			if (valid(data[field]) && data[field] !== used[field]) {
				record[field] = { v: data[field], t: Date.now() };
				used[field] = data[field];
				changed = true;
			}
		});
		if (!changed) {
			return;
		}
		try {
			window.localStorage.setItem(KEY, JSON.stringify(record));
		} catch (e) {
			// Private mode or storage full: Bricks refreshes per page, as before.
		}
	}

	['bricks/ajax/query_result/completed', 'bricks/ajax/load_page/completed', 'bricks/ajax/popup/loaded', 'bricks/ajax/end', 'bricks/form/success', 'bricks/form/error'].forEach(function (name) {
		document.addEventListener(name, function () {
			setTimeout(save, 0);
		});
	});
	window.addEventListener('pagehide', save);
})();
