/* SitePulse settings page: "Test connection" → GET /wp-json/sitepulse/v1/status (manage_options, wp_rest nonce). */
(function () {
	'use strict';
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
