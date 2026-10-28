/*
 * ShipStatic: Bricks query filters from the address on a static page.
 *
 * WordPress applies the filters in a link like ?brx_abc123=term while it
 * renders the page; a static copy can't, so it shows the unfiltered results.
 * This reads the same parameters once Bricks' filters have started, sets
 * each filter's value, and asks for the results as a back/forward step would
 * (no new history entry). Added by ShipStatic to exported pages that have
 * Bricks filters only.
 */
(function () {
	function run() {
		var data = window.bricksData;
		var utils = window.bricksUtils;
		if (!data || !data.filterInstances || !data.selectedFilters || !utils || typeof utils.fetchFilterResults !== 'function') {
			return;
		}
		var params = new URLSearchParams(window.location.search);
		var queries = {};

		Object.keys(data.filterInstances).forEach(function (filterId) {
			var filter = data.filterInstances[filterId];
			if (!filter || !filter.targetQueryId || filter.filterType === 'pagination') {
				return;
			}
			var key = filter.filterNiceName || 'brx_' + filterId;
			var values = params.getAll(key + '[]');
			if (!values.length) {
				values = params.getAll(key);
			}
			values = values.filter(function (v) {
				return v !== '';
			});
			if (!values.length) {
				return;
			}

			if (filter.filterType === 'range') {
				filter.currentValue = values.slice(0, 2).map(Number);
				filter.hasChanged = true;
			} else {
				filter.currentValue = Array.isArray(filter.currentValue) ? values : values[0];
			}

			var queryId = filter.targetQueryId;
			var selected = data.selectedFilters[queryId] || (data.selectedFilters[queryId] = {});
			var ids = Object.keys(selected).map(function (k) {
				return selected[k];
			});
			if (ids.indexOf(filterId) === -1) {
				selected[Object.keys(selected).length] = filterId;
			}

			// A search box keeps its own text (Bricks never redraws it).
			var input = filter.filterType === 'search' ? document.querySelector('input[data-brx-filter][name="form-field-' + filterId + '"]') : null;
			if (input) {
				input.value = values[0];
			}
			queries[queryId] = true;
		});

		Object.keys(queries).forEach(function (queryId) {
			utils.fetchFilterResults(queryId, true);
		});
	}

	// After Bricks' own DOMContentLoaded handlers have set up the filters.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			setTimeout(run, 0);
		});
	} else {
		setTimeout(run, 0);
	}
})();
