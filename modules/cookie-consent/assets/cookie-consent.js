/* ModulY cookie-consent: analytics only after "Accept all"; no external requests. Banner markup: views/moduly/cookie-consent/banner (slot footer). */
(function () {
	var cfg = window.modulyCookieConsent || {};
	var NAME = cfg.name || 'wpfasty_cookie_consent';
	var AGE = (cfg.maxAgeDays || 180) * 86400;
	var banner = document.getElementById('cookie-banner');
	if (!banner) { return; }
	function get() {
		var m = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function set(value) {
		if (value !== 'all' && value !== 'necessary') { return; }
		document.cookie = NAME + '=' + value + '; max-age=' + AGE + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		banner.hidden = true;
		document.dispatchEvent(new CustomEvent('wpfasty:consent', { detail: { analytics: value === 'all' } }));
	}
	if (!get()) { banner.hidden = false; }
	banner.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-cookie]');
		if (btn) { set(btn.getAttribute('data-cookie')); }
	});
	document.addEventListener('click', function (e) {
		if (e.target.closest('[data-cookie-open]')) { e.preventDefault(); banner.hidden = false; }
	});
})();
