/* SitePulse settings page: region presets; "Test connection" → GET /wp-json/sitepulse/v1/status (manage_options, wp_rest nonce). */
(function () {
	'use strict';
	// Region presets: the URL fields apply to "Custom" only (the server ignores them for presets).
	var region = document.getElementById('sitepulse-region');
	function sync() {
		var r = region && region.querySelector('input:checked');
		var custom = !r || r.value === 'custom';
		document.querySelectorAll('.sitepulse-custom input').forEach(function (i) { i.disabled = !custom; });
	}
	if (region) { region.addEventListener('change', sync); sync(); }
	var btn = document.getElementById('sitepulse-test');
	var out = document.getElementById('sitepulse-test-result');
	if (!btn || !out || !window.wp || !window.wp.apiFetch) { return; }
	btn.addEventListener('click', function () {
		btn.disabled = true;
		out.textContent = '…';
		window.wp.apiFetch({ path: '/sitepulse/v1/status' }).then(function (r) {
			out.textContent = '✓ ' + (r && r.site_id ? r.site_id : 'ok');
		}).catch(function (err) {
			out.textContent = '✗ ' + ((err && err.message) || 'error');
		}).then(function () { btn.disabled = false; });
	});
})();
