/* SitePulse consent loader (ModulY site-pulse): adds the sitepulse.js tag only after analytics consent.
 * Signals: window.SitePulseConsent = true (before or after this script), document event "sitepulse:consent"
 * (detail.analytics !== false), ModulY cookie-consent / wp-fasty ("wpfasty_cookie_consent=all" cookie and
 * "wpfasty:consent" event), WP Consent API (wp_has_consent('statistics') / "wp_listen_for_consent_change"),
 * Cookiebot (Cookiebot.consent.statistics / "CookiebotOnAccept"). Nothing is requested or stored before that. */
(function () {
	'use strict';
	var cfg = window.sitePulseTracker || {};
	if (!cfg.src || !cfg.site) { return; }
	var started = false;
	function start() {
		if (started) { return; }
		started = true;
		var s = document.createElement('script');
		s.src = cfg.src;
		s.setAttribute('data-site', cfg.site);
		s.defer = true;
		(document.head || document.documentElement).appendChild(s);
	}
	function cookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function granted() {
		if (window.SitePulseConsent === true) { return true; }
		if (cookie('wpfasty_cookie_consent') === 'all') { return true; }
		try {
			if (typeof window.wp_has_consent === 'function' && window.wp_has_consent('statistics')) { return true; }
		} catch (e) {}
		var cb = window.Cookiebot;
		return !!(cb && cb.consent && cb.consent.statistics);
	}
	if (granted()) { start(); return; }
	document.addEventListener('sitepulse:consent', function (e) {
		if (!e.detail || e.detail.analytics !== false) { start(); }
	});
	document.addEventListener('wpfasty:consent', function (e) {
		if (e.detail && e.detail.analytics === true) { start(); }
	});
	document.addEventListener('wp_listen_for_consent_change', function (e) {
		if (e.detail && e.detail.statistics === 'allow') { start(); }
	});
	window.addEventListener('CookiebotOnAccept', function () {
		if (granted()) { start(); }
	});
	try {
		Object.defineProperty(window, 'SitePulseConsent', {
			configurable: true,
			get: function () { return started; },
			set: function (v) { if (v === true) { start(); } }
		});
	} catch (e) {}
})();
