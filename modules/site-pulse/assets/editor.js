/* SitePulse editor sidebar (ModulY site-pulse). No build step: core wp-* globals only.
 * Requests go to this site's REST proxy (/wp-json/sitepulse/v1/*, wp_rest nonce via apiFetch); the SitePulse
 * secret key never reaches the browser. Highlights use the CSS Custom Highlight API and never edit content. */
(function (wp) {
	'use strict';
	var cfg = window.sitePulseEditor || {};
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	var PluginSidebar = (wp.editor && wp.editor.PluginSidebar) || (wp.editPost && wp.editPost.PluginSidebar);
	var MoreMenuItem = (wp.editor && wp.editor.PluginSidebarMoreMenuItem) || (wp.editPost && wp.editPost.PluginSidebarMoreMenuItem);
	if (!registerPlugin || !PluginSidebar) { return; }
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var C = wp.components;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var SIDEBAR = 'sitepulse-sidebar';
	var HL = 'sitepulse';
	var GROUPS = [
		['fail', __('Problems', 'moduly'), true],
		['warn', __('Warnings', 'moduly'), true],
		['pass', __('Passed', 'moduly'), false],
		['not-measured', __('Not measured', 'moduly'), false]
	];

	/* ---------- Model: the block ids diagd's textcheck.ParseHTML assigns, mapped to editor blocks ----------
	 * diagd wraps the html as <title>{title}</title><meta name="description" content="{description}"> + body and
	 * numbers blocks with one counter in document order: title, meta, h1–h6, p, li, td/th ("td"), img alt ("alt").
	 * Span offsets are UTF-16 code units in the whitespace-collapsed block text (JS string indices). */
	function norm(s) { return String(s || '').replace(/\s+/g, ' ').trim(); }

	function buildModel(blocks, title, description) {
		var seq = 0;
		var model = { html: '', blocks: {} };
		function add(type, prefix, text, clientId) {
			seq += 1;
			var id = prefix + '-' + seq;
			model.blocks[id] = { id: id, type: type, text: text, clientId: clientId };
		}
		if (norm(title) !== '') { add('title', 'title', norm(title), null); }
		if (description !== '') { add('meta', 'meta', norm(description), null); }
		var parts = [];
		var parser = new window.DOMParser();
		blocks.forEach(function (block) {
			var html = '';
			try { html = wp.blocks.getBlockContent(block) || ''; } catch (e) { html = ''; }
			parts.push(html);
			var doc = parser.parseFromString('<!doctype html><html><body>' + html + '</body></html>', 'text/html');
			walk(doc.body, block.clientId);
		});
		function walk(node, clientId) {
			for (var c = node.firstElementChild; c; c = c.nextElementSibling) {
				var tag = c.tagName.toLowerCase();
				if (tag === 'script' || tag === 'style' || tag === 'noscript' || tag === 'svg' || tag === 'title' || tag === 'meta') { continue; }
				if (/^h[1-6]$/.test(tag) || tag === 'p' || tag === 'li' || tag === 'td' || tag === 'th') {
					var t = norm(c.textContent);
					if (t !== '') {
						var type = tag === 'th' ? 'td' : tag;
						add(type, type, t, clientId);
					}
					continue;
				}
				if (tag === 'img') {
					var alt = c.getAttribute('alt') || '';
					if (alt !== '') { add('alt', 'alt', alt, clientId); }
					continue;
				}
				walk(c, clientId);
			}
		}
		model.html = parts.join('\n');
		return model;
	}

	/* ---------- Highlighting in the editor canvas (iframe or not) ---------- */
	function canvas() {
		var frame = document.querySelector('iframe[name="editor-canvas"]');
		if (frame && frame.contentDocument) { return { doc: frame.contentDocument, win: frame.contentWindow }; }
		return { doc: document, win: window };
	}

	function clearHighlight() {
		[window, (canvas().win)].forEach(function (w) {
			try { if (w.CSS && w.CSS.highlights) { w.CSS.highlights.delete(HL); } } catch (e) {}
		});
	}

	function ensureStyle(doc) {
		if (doc.getElementById('sitepulse-highlight-style')) { return; }
		var st = doc.createElement('style');
		st.id = 'sitepulse-highlight-style';
		st.textContent = '::highlight(' + HL + '){background-color:rgba(255,190,0,.5);color:inherit}' +
			'.sitepulse-flash{outline:2px solid #f0b400 !important;outline-offset:2px}';
		(doc.head || doc.documentElement).appendChild(st);
	}

	/* Whitespace-collapsed text of an element with a map back to (text node, offset). */
	function textMap(root, win) {
		var out = '';
		var map = [];
		var walker = root.ownerDocument.createTreeWalker(root, win.NodeFilter.SHOW_TEXT, null);
		for (var n = walker.nextNode(); n; n = walker.nextNode()) {
			var p = n.parentNode && n.parentNode.nodeName;
			if (p === 'SCRIPT' || p === 'STYLE') { continue; }
			var s = n.nodeValue;
			for (var i = 0; i < s.length; i++) {
				var ch = s.charAt(i);
				if (/\s/.test(ch)) {
					if (out !== '' && out.charAt(out.length - 1) !== ' ') { out += ' '; map.push({ node: n, offset: i }); }
				} else {
					out += ch;
					map.push({ node: n, offset: i });
				}
			}
		}
		if (out.charAt(out.length - 1) === ' ') { out = out.slice(0, -1); map.pop(); }
		return { text: out, map: map };
	}

	function candidates(root, type) {
		var sel = { p: 'p', li: 'li', td: 'td,th', alt: 'img', title: '*' }[type] || type;
		var list = [];
		if (root.matches && root.matches(sel)) { list.push(root); }
		root.querySelectorAll(sel).forEach(function (n) { list.push(n); });
		return list;
	}

	function locate(model, block) {
		var cv = canvas();
		var root;
		if (block.type === 'title') {
			root = cv.doc.querySelector('.editor-post-title__input, .editor-post-title, .wp-block-post-title');
			return root ? { el: root, cv: cv } : null;
		}
		if (!block.clientId) { return null; }
		root = cv.doc.querySelector('[data-block="' + block.clientId + '"]');
		if (!root) { return null; }
		// nth element of the same kind and text inside this top-level block (duplicates keep their order)
		var nth = 0;
		Object.keys(model.blocks).some(function (id) {
			var b = model.blocks[id];
			if (b === block) { return true; }
			if (b.clientId === block.clientId && b.type === block.type && b.text === block.text) { nth += 1; }
			return false;
		});
		var found = candidates(root, block.type).filter(function (n) {
			return block.type === 'alt' ? n.getAttribute('alt') === block.text : norm(n.textContent) === block.text;
		});
		return { el: found[nth] || root, exact: !!found[nth], cv: cv };
	}

	function showSpan(model, span, selectBlock) {
		clearHighlight();
		var block = model && model.blocks[span.block_id];
		if (!block) { return false; }
		if (block.clientId) { selectBlock(block.clientId); }
		var hit = locate(model, block);
		if (!hit) { return false; }
		ensureStyle(hit.cv.doc);
		hit.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
		var win = hit.cv.win;
		if (block.type !== 'alt' && (hit.exact || block.type === 'title') && win.CSS && win.CSS.highlights && win.Highlight) {
			var tm = textMap(hit.el, win);
			if (tm.text === block.text && span.end > span.start && span.end <= tm.map.length) {
				var a = tm.map[span.start];
				var b = tm.map[span.end - 1];
				var range = hit.cv.doc.createRange();
				range.setStart(a.node, a.offset);
				range.setEnd(b.node, b.offset + 1);
				win.CSS.highlights.set(HL, new win.Highlight(range));
				return true;
			}
		}
		hit.el.classList.add('sitepulse-flash');
		setTimeout(function () { hit.el.classList.remove('sitepulse-flash'); }, 1600);
		return true;
	}

	function spanText(model, span) {
		var b = model && model.blocks[span.block_id];
		return b ? b.text.slice(span.start, span.end) : '';
	}

	/* ---------- UI ---------- */
	function scoreClass(s) { return s >= 80 ? 'good' : (s >= 60 ? 'ok' : 'bad'); }

	function Issue(props) {
		var f = props.finding;
		return el('div', { className: 'sitepulse-issue' },
			el('div', { className: 'sitepulse-issue__title' }, f.title || f.rule_id,
				f.severity ? el('span', { className: 'sitepulse-badge' }, f.severity) : null),
			f.recommendation ? el('p', { className: 'sitepulse-issue__rec' }, f.recommendation) : null,
			f.spans && f.spans.length ? el('div', { className: 'sitepulse-issue__spans' }, f.spans.slice(0, 8).map(function (s, i) {
				var txt = spanText(props.model, s);
				return el(C.Button, {
					key: i, variant: 'secondary', size: 'small', className: 'sitepulse-issue__span',
					onClick: function () { if (!showSpan(props.model, s, props.selectBlock)) { props.onMissing(); } },
					label: __('Show in the text', 'moduly')
				}, txt ? '«' + (txt.length > 40 ? txt.slice(0, 40) + '…' : txt) + '»' : __('Show', 'moduly'));
			})) : null
		);
	}

	function Insights(props) {
		var st = useState(null), data = st[0], setData = st[1];
		var ld = useState(false), loading = ld[0], setLoading = ld[1];
		var er = useState(''), error = er[0], setError = er[1];
		function load() {
			setLoading(true); setError('');
			wp.apiFetch({ path: '/sitepulse/v1/insights/' + props.postId }).then(setData)
				.catch(function (e) { setError((e && e.message) || 'error'); })
				.then(function () { setLoading(false); });
		}
		return el(C.PanelBody, { title: __('In SitePulse', 'moduly'), initialOpen: false },
			el(C.Button, { variant: 'secondary', onClick: load, isBusy: loading, disabled: loading || !props.postId },
				__('Load history and link ideas', 'moduly')),
			error ? el(C.Notice, { status: 'error', isDismissible: false }, error) : null,
			data && !data.found ? el('p', { className: 'sitepulse-muted' }, __('This post has not been imported into SitePulse yet (the studio imports it via wp-json).', 'moduly')) : null,
			data && data.found ? el(Fragment, null,
				el('h3', null, __('Score history', 'moduly')),
				el('ul', { className: 'sitepulse-list' }, (data.history || []).slice(-10).reverse().map(function (h) {
					return el('li', { key: h.version }, 'v' + h.version + ': ' + (h.score === null ? '—' : h.score) + ' ',
						el('span', { className: 'sitepulse-muted' }, h.analyzed_at));
				})),
				el('h3', null, __('Internal link ideas', 'moduly')),
				(data.suggestions || []).length ? el('ul', { className: 'sitepulse-list' }, data.suggestions.map(function (s, i) {
					return el('li', { key: i }, el(C.ExternalLink, { href: s.url }, s.title || s.url),
						s.anchor ? el('div', { className: 'sitepulse-muted' }, '«' + s.anchor + '»' + (s.reason ? ' — ' + s.reason : '')) : null);
				})) : el('p', { className: 'sitepulse-muted' }, __('No suggestions.', 'moduly'))
			) : null
		);
	}

	function Sidebar() {
		var post = useSelect(function (select) {
			var ed = select('core/editor');
			return {
				id: ed.getCurrentPostId(),
				title: ed.getEditedPostAttribute('title') || '',
				excerpt: ed.getEditedPostAttribute('excerpt') || '',
				meta: ed.getEditedPostAttribute('meta') || {},
				blocks: select('core/block-editor').getBlocks()
			};
		}, []);
		var editPost = useDispatch('core/editor').editPost;
		var selectBlock = useDispatch('core/block-editor').selectBlock;
		var r = useState(null), result = r[0], setResult = r[1];
		var m = useState(null), model = m[0], setModel = m[1];
		var b = useState(false), busy = b[0], setBusy = b[1];
		var e = useState(null), error = e[0], setError = e[1];
		var w = useState(''), warning = w[0], setWarning = w[1];
		var seq = useRef(0);
		var keywords = Array.isArray(post.meta[cfg.metaKey]) ? post.meta[cfg.metaKey] : [];

		function analyze() {
			if (!cfg.configured || !post.id) { return; }
			var mdl = buildModel(post.blocks, post.title, norm(post.excerpt));
			if (norm(mdl.html.replace(/<[^>]*>/g, ' ')) === '') { setError({ message: __('There is no content to analyze yet.', 'moduly') }); return; }
			var my = ++seq.current;
			setBusy(true); setError(null); setWarning('');
			wp.apiFetch({
				path: '/sitepulse/v1/analyze', method: 'POST',
				data: { post_id: post.id, html: mdl.html, title: post.title, description: norm(post.excerpt), keywords: keywords }
			}).then(function (res) {
				if (my !== seq.current) { return; }
				clearHighlight(); setResult(res); setModel(mdl);
			}).catch(function (err) {
				if (my === seq.current) { setError(err || { message: 'error' }); }
			}).then(function () { if (my === seq.current) { setBusy(false); } });
		}

		useEffect(function () {
			if (!cfg.autoAnalyze || !cfg.configured) { return undefined; }
			var t = setTimeout(analyze, 2500);
			return function () { clearTimeout(t); };
		}, [post.blocks, post.title, post.excerpt, keywords.join('\n')]);
		useEffect(function () { return clearHighlight; }, []);

		var body;
		if (!cfg.configured) {
			body = el(C.Notice, { status: 'warning', isDismissible: false },
				__('SitePulse is not configured yet.', 'moduly'), ' ',
				cfg.settingsUrl ? el('a', { href: cfg.settingsUrl }, __('Open Settings > SitePulse', 'moduly')) : __('Ask an administrator to connect it.', 'moduly'));
		}
		return el(Fragment, null,
			MoreMenuItem ? el(MoreMenuItem, { target: SIDEBAR, icon: 'chart-line' }, 'SitePulse') : null,
			el(PluginSidebar, { name: SIDEBAR, title: 'SitePulse', icon: 'chart-line' },
				el(C.PanelBody, { title: __('Analysis', 'moduly'), initialOpen: true },
					body || null,
					el(C.FormTokenField, {
						label: __('Focus keywords (up to 5, first = primary)', 'moduly'),
						value: keywords,
						maxLength: cfg.maxKeywords || 5,
						__experimentalExpandOnFocus: false,
						__next40pxDefaultSize: true,
						onChange: function (tokens) {
							var meta = {};
							meta[cfg.metaKey] = tokens.map(function (t) { return norm(typeof t === 'string' ? t : t.value); })
								.filter(Boolean).slice(0, cfg.maxKeywords || 5);
							editPost({ meta: meta });
						}
					}),
					el(C.Button, { variant: 'primary', onClick: analyze, isBusy: busy, disabled: busy || !cfg.configured, __next40pxDefaultSize: true },
						busy ? __('Analyzing…', 'moduly') : __('Analyze', 'moduly')),
					cfg.autoAnalyze ? el('p', { className: 'sitepulse-muted' }, __('Re-analyzes automatically after you stop typing.', 'moduly')) : null,
					error ? el(C.Notice, { status: 'error', isDismissible: false }, error.message || String(error.code || 'error'),
						error.code === 'sitepulse_not_configured' && cfg.settingsUrl ? el('a', { href: cfg.settingsUrl }, ' ' + __('Settings', 'moduly')) : null) : null,
					warning ? el(C.Notice, { status: 'info', isDismissible: true, onRemove: function () { setWarning(''); } }, warning) : null,
					result ? el('div', { className: 'sitepulse-score sitepulse-score--' + scoreClass(result.score) },
						el('span', { className: 'sitepulse-score__value' }, String(result.score)),
						el('span', null, __('of 100', 'moduly'),
							result.metrics && result.metrics.word_count ? el('span', { className: 'sitepulse-muted' }, ' · ' + Math.round(result.metrics.word_count) + ' ' + __('words', 'moduly')) : null)) : null,
					result && result.lost && result.lost.length ? el('ul', { className: 'sitepulse-list sitepulse-muted' }, result.lost.slice(0, 6).map(function (l, i) { return el('li', { key: i }, l); })) : null
				),
				result ? GROUPS.map(function (g) {
					var list = (result.groups && result.groups[g[0]]) || [];
					if (!list.length) { return null; }
					return el(C.PanelBody, { key: g[0], title: g[1] + ' (' + list.length + ')', initialOpen: g[2] },
						list.map(function (f, i) {
							return el(Issue, {
								key: f.rule_id + i, finding: f, model: model, selectBlock: selectBlock,
								onMissing: function () { setWarning(__('That text is no longer in the editor; analyze again.', 'moduly')); }
							});
						}));
				}) : null,
				cfg.configured ? el(Insights, { postId: post.id }) : null
			)
		);
	}

	// Read-only internals for tests and integrations (no state, no secrets).
	window.SitePulseEditor = { buildModel: buildModel, textMap: textMap, spanText: spanText };
	registerPlugin('sitepulse', { render: Sidebar, icon: 'chart-line' });
})(window.wp);
