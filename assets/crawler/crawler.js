/**
 * Portable static-site crawler core.
 *
 * Renders a live WordPress site to a tree of static files by breadth-first
 * crawling from seed URLs. The crawl loop runs in JavaScript and holds no PHP
 * worker while awaiting, so it works inside WordPress Playground (same-origin
 * `fetch`) without the loopback deadlock that blocks PHP-side crawlers, and it
 * runs unchanged in Node (for local testing / CI) with an injected parser.
 *
 * Dependencies are injected so the core stays environment-agnostic:
 *   - `fetch`:     defaults to `globalThis.fetch`.
 *   - `parseHTML`: `(html) => Document`. In the browser this is
 *                  `(html) => new DOMParser().parseFromString(html, 'text/html')`;
 *                  in Node, linkedom's `(html) => parseHTML(html).document`.
 *
 * @typedef {Object} CrawlDeps
 * @property {typeof fetch} [fetch]
 * @property {(html: string) => Document} parseHTML
 *
 * @typedef {Object} CrawlOptions
 * @property {string}   baseUrl                 Site root, e.g. https://host/scope:xxxx/
 * @property {string[]} [seedUrls]              Extra URLs to seed (resolved against baseUrl).
 * @property {number}   [concurrency]           Parallel fetches (default 2; matches Playground pool).
 * @property {number}   [maxPages]              Safety cap on fetched resources (default 5000).
 * @property {number}   [retries]               Retries per URL on transient failures (default 2).
 * @property {number}   [retryDelay]            Base backoff in ms between retries (default 500).
 * @property {(url: URL) => boolean} [shouldVisit]
 * @property {(p: CrawlProgress) => void} [onProgress]
 *
 * @typedef {Object} CrawlFile
 * @property {Uint8Array} [bytes]
 * @property {string}     [text]
 * @property {string}     contentType
 *
 * @typedef {Object} CrawlProgress
 * @property {number} processed
 * @property {number} discovered
 * @property {number} pages
 * @property {number} assets
 * @property {number} errors
 * @property {string} currentUrl
 *
 * @typedef {Object} CrawlResult
 * @property {Map<string, CrawlFile>} files  Path (no leading slash) -> contents.
 * @property {number} pages
 * @property {number} assets
 * @property {Array<{url: string, error: string}>} errors
 */

const SKIP_SCHEME = /^(mailto:|tel:|javascript:|data:|blob:|#)/i;
const HAS_EXTENSION = /\.[^./]+$/;
const CSS_URL = /url\(\s*(['"]?)([^'")]+)\1\s*\)/gi;

/**
 * Paths that are dynamic or admin-only and never belong in a static export.
 * Matched against the base-stripped, leading-slash path. Mirrors Simply
 * Static's default crawl exclusions.
 */
const DEFAULT_EXCLUDE = [
	/^\/wp-admin(\/|$)/i,
	/^\/wp-login\.php/i,
	/^\/wp-cron\.php/i,
	/^\/wp-trackback\.php/i,
	/^\/xmlrpc\.php/i,
	/^\/wp-json(\/|$)/i,
	// Dynamic search-result feeds/pages are effectively infinite.
	/\/search\/feed(\/|$)/i,
];

/** Meta tags whose `content` is an asset URL worth capturing. */
const META_URL_PROPS = new Set([
	'og:image',
	'og:image:url',
	'og:image:secure_url',
	'twitter:image',
	'twitter:image:src',
	'msapplication-tileimage',
]);

/**
 * Crawls a site and returns the rendered static files.
 *
 * @param {CrawlDeps} deps
 * @param {CrawlOptions} options
 * @returns {Promise<CrawlResult>}
 */
export async function crawlSite(deps, options) {
	const fetchImpl = deps.fetch || globalThis.fetch;
	const parseHTML = deps.parseHTML;
	if (typeof parseHTML !== 'function') {
		throw new Error('crawlSite requires deps.parseHTML');
	}

	const {
		baseUrl,
		seedUrls = [],
		concurrency = 2,
		maxPages = 5000,
		credentials = 'omit',
		generate404 = true,
		retries = 2,
		retryDelay = 500,
		shouldVisit = () => true,
		onProgress = () => {},
	} = options;

	const base = new URL(baseUrl);
	if (!base.pathname.endsWith('/')) {
		base.pathname += '/';
	}

	/** @type {Map<string, CrawlFile>} */
	const files = new Map();
	/** @type {Array<{url: string, error: string}>} */
	const errors = [];
	const visited = new Set();
	const queue = [];
	let pages = 0;
	let assets = 0;
	let processed = 0;

	const decoder = new TextDecoder('utf-8');

	// Transient failures worth retrying: a bad connection (common when crawling
	// inside Playground) otherwise silently drops assets, leaving broken images
	// and half-populated srcsets in the export.
	const RETRYABLE_STATUS = new Set([408, 425, 429, 500, 502, 503, 504]);
	const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

	const fetchWithRetry = async (href) => {
		let lastError;
		for (let attempt = 0; ; attempt++) {
			try {
				const res = await fetchImpl(href, { credentials });
				if (res.ok || attempt >= retries || !RETRYABLE_STATUS.has(res.status)) {
					return res;
				}
			} catch (e) {
				lastError = e;
				if (attempt >= retries) {
					throw lastError;
				}
			}
			await sleep(retryDelay * (attempt + 1));
		}
	};

	const enqueue = (url) => {
		const key = url.origin + url.pathname + url.search;
		if (visited.has(key)) {
			return;
		}
		if (!inScope(url, base) || isExcluded(url, base) || !shouldVisit(url)) {
			return;
		}
		visited.add(key);
		queue.push(url);
	};

	// Fixed paths that exist but are rarely linked from page HTML, so BFS alone
	// would miss them. Mirrors Simply Static's inclusion of these files.
	const FIXED_SEEDS = ['/robots.txt', '/sitemap.xml', '/sitemap.xsl', '/wp-sitemap.xml'];

	for (const seed of ['/', ...FIXED_SEEDS, ...seedUrls]) {
		const abs = resolveUrl(seed, base.href);
		if (abs) {
			enqueue(abs);
		}
	}

	const processOne = async (url) => {
		const res = await fetchWithRetry(url.href);
		if (!res.ok) {
			// A retryable status here means retries were exhausted — a genuine
			// transient drop worth surfacing. Deterministic 404s/410s stay silent
			// (optional seeds like sitemap.xsl legitimately don't exist).
			if (RETRYABLE_STATUS.has(res.status)) {
				errors.push({ url: url.href, error: 'HTTP ' + res.status });
			}
			return;
		}

		// `fetch` transparently follows redirects, so the bytes may belong to a
		// different URL than requested. Key the output on the FINAL URL, not the
		// requested one — otherwise a redirect (e.g. a plugin sending `/` to a
		// post) would overwrite the wrong file, silently corrupting the export.
		const finalUrl = safeUrl(res.url, url);
		if (finalUrl.href !== url.href) {
			if (!inScope(finalUrl, base) || isExcluded(finalUrl, base)) {
				errors.push({
					url: url.href,
					error: 'redirected to a skipped URL: ' + finalUrl.href,
				});
				return;
			}
			// Don't re-fetch the redirect target if it's also discovered later.
			visited.add(finalUrl.origin + finalUrl.pathname + finalUrl.search);
		}
		const outPath = stripBase(finalUrl.pathname, base);

		const contentType = res.headers.get('content-type') || '';
		const buf = new Uint8Array(await res.arrayBuffer());

		if (isHtml(contentType)) {
			const doc = parseHTML(decoder.decode(buf));
			for (const found of rewriteDocument(doc, finalUrl, base)) {
				enqueue(found);
			}
			files.set(outputPathFor(outPath, 'index.html'), {
				text: relativizeOrigin('<!DOCTYPE html>\n' + doc.documentElement.outerHTML, base),
				contentType: 'text/html',
			});
			pages++;
		} else if (isXml(contentType) || finalUrl.pathname.endsWith('.xml')) {
			const raw = decoder.decode(buf);
			for (const found of extractXmlLinks(raw, finalUrl)) {
				enqueue(found);
			}
			const xmlKey = outputPathFor(outPath, 'index.xml');
			files.set(xmlKey, {
				text: relativizeOrigin(raw, base),
				contentType: contentType || 'application/xml',
			});
			// A directory-style feed URL (e.g. /feed/) resolves to index.html on
			// static hosts; emit a redirect stub so the pretty URL still works.
			if (xmlKey.endsWith('/index.xml')) {
				const htmlKey = xmlKey.slice(0, -'index.xml'.length) + 'index.html';
				if (!files.has(htmlKey)) {
					files.set(htmlKey, { text: xmlRedirectStub(), contentType: 'text/html' });
				}
			}
			assets++;
		} else if (contentType.includes('css') || finalUrl.pathname.endsWith('.css')) {
			const { css, urls } = rewriteCss(decoder.decode(buf), finalUrl, base);
			for (const found of urls) {
				enqueue(found);
			}
			files.set(outputPathFor(outPath, 'index.html'), {
				text: relativizeOrigin(css, base),
				contentType: 'text/css',
			});
			assets++;
		} else if (contentType.includes('text/') || finalUrl.pathname.endsWith('.txt')) {
			files.set(outputPathFor(outPath, 'index.html'), {
				text: relativizeOrigin(decoder.decode(buf), base),
				contentType: contentType || 'text/plain',
			});
			assets++;
		} else {
			files.set(outputPathFor(outPath, 'index.html'), {
				bytes: buf,
				contentType,
			});
			assets++;
		}
	};

	await runPool(queue, concurrency, maxPages, async (url) => {
		try {
			await processOne(url);
		} catch (e) {
			errors.push({ url: url.href, error: String(e && e.message ? e.message : e) });
		} finally {
			processed++;
			onProgress({
				processed,
				discovered: visited.size,
				pages,
				assets,
				errors: errors.length,
				currentUrl: url.href,
			});
		}
	});

	if (generate404) {
		await captureNotFound(fetchImpl, parseHTML, decoder, base, credentials, files);
	}

	return { files, pages, assets, errors };
}

/**
 * Renders the theme's 404 page (by requesting a URL that cannot exist) and
 * stores it as `404.html`, matching Simply Static and Cloudflare's not-found
 * convention.
 */
async function captureNotFound(fetchImpl, parseHTML, decoder, base, credentials, files) {
	try {
		const url = new URL(base.href);
		url.pathname = base.pathname + 'ssd-404-' + Date.now();
		const res = await fetchImpl(url.href, { credentials });
		const contentType = res.headers.get('content-type') || '';
		if (!isHtml(contentType)) {
			return;
		}
		const doc = parseHTML(decoder.decode(new Uint8Array(await res.arrayBuffer())));
		rewriteDocument(doc, url, base);
		files.set('404.html', {
			text: relativizeOrigin('<!DOCTYPE html>\n' + doc.documentElement.outerHTML, base),
			contentType: 'text/html',
		});
	} catch {
		// A missing 404 page is non-fatal.
	}
}

/**
 * Strips the source site's own origin (and Playground scope prefix) from a text
 * blob, turning leftover absolute URLs into root-relative ones. Catches URLs
 * the DOM-attribute rewriter can't reach: inline scripts, JSON-LD, meta og:url,
 * and feed/sitemap XML bodies. Handles plain, protocol-relative, and
 * JSON-escaped (`https:\/\/`) forms.
 *
 * @param {string} text
 * @param {URL} base
 * @returns {string}
 */
function relativizeOrigin(text, base) {
	const prefix = base.pathname === '/' ? '' : base.pathname.replace(/\/$/, '');
	const escPrefix = prefix.replace(/\//g, '\\/');

	// WordPress sometimes emits the host without its port (e.g. dns-prefetch
	// hints), so strip both the host (with port) and the bare hostname.
	const hosts = base.host === base.hostname ? [base.host] : [base.host, base.hostname];

	const needles = [];
	for (const host of hosts) {
		for (const scheme of ['https://', 'http://', '//']) {
			if (prefix) {
				needles.push(scheme + host + prefix);
			}
			needles.push(scheme + host);
		}
		for (const scheme of ['https:\\/\\/', 'http:\\/\\/', '\\/\\/']) {
			if (escPrefix) {
				needles.push(scheme + host + escPrefix);
			}
			needles.push(scheme + host);
		}
	}

	for (const needle of needles) {
		if (needle) {
			text = text.split(needle).join('');
		}
	}
	return text;
}

/**
 * Runs an async worker over a growing queue with bounded concurrency.
 *
 * @param {URL[]} queue
 * @param {number} concurrency
 * @param {number} maxItems
 * @param {(url: URL) => Promise<void>} worker
 */
function runPool(queue, concurrency, maxItems, worker) {
	return new Promise((resolve) => {
		let active = 0;
		let started = 0;

		const pump = () => {
			if (queue.length === 0 && active === 0) {
				resolve();
				return;
			}
			while (active < concurrency && queue.length > 0 && started < maxItems) {
				const url = queue.shift();
				active++;
				started++;
				worker(url).finally(() => {
					active--;
					pump();
				});
			}
			if (started >= maxItems && active === 0) {
				resolve();
			}
		};

		pump();
	});
}

/**
 * Rewrites in-scope URLs in a parsed HTML document to root-relative paths and
 * returns the absolute URLs discovered (for enqueueing).
 *
 * @param {Document} doc
 * @param {URL} pageUrl
 * @param {URL} base
 * @returns {URL[]}
 */
function rewriteDocument(doc, pageUrl, base) {
	const found = [];

	stripChrome(doc);

	const rewriteAttr = (el, attr) => {
		const rewritten = handleUrl(el.getAttribute(attr), pageUrl, base, found);
		if (rewritten !== null) {
			el.setAttribute(attr, rewritten);
		}
	};

	doc.querySelectorAll('a[href], area[href], link[href]').forEach((el) => rewriteAttr(el, 'href'));
	doc
		.querySelectorAll('img[src], script[src], source[src], video[src], audio[src], iframe[src], embed[src]')
		.forEach((el) => rewriteAttr(el, 'src'));

	doc.querySelectorAll('img[srcset], source[srcset]').forEach((el) => {
		const rewritten = rewriteSrcset(el.getAttribute('srcset'), pageUrl, base, found);
		if (rewritten !== null) {
			el.setAttribute('srcset', rewritten);
		}
	});

	doc.querySelectorAll('meta[content]').forEach((el) => {
		const prop = (el.getAttribute('property') || el.getAttribute('name') || '').toLowerCase();
		if (!META_URL_PROPS.has(prop)) {
			return;
		}
		const rewritten = handleUrl(el.getAttribute('content'), pageUrl, base, found);
		if (rewritten !== null) {
			el.setAttribute('content', rewritten);
		}
	});

	doc.querySelectorAll('[style]').forEach((el) => {
		el.setAttribute('style', rewriteCssText(el.getAttribute('style') || '', pageUrl, base, found));
	});
	doc.querySelectorAll('style').forEach((el) => {
		el.textContent = rewriteCssText(el.textContent || '', pageUrl, base, found);
	});

	return found;
}

/**
 * Removes logged-in chrome that a live crawl may capture: the WordPress admin
 * bar (and its margin bump), and this plugin's own crawler script. Removing the
 * plugin script is a safety net so a logged-in crawl (e.g. in Playground) can
 * never bake the localized Cloudflare token into exported pages.
 *
 * @param {Document} doc
 */
function stripChrome(doc) {
	const bar = doc.querySelector('#wpadminbar');
	if (bar) {
		bar.remove();
	}

	doc.querySelectorAll('style').forEach((el) => {
		const text = el.textContent || '';
		if (text.includes('#wpadminbar') || /html\s*\{\s*margin-top:\s*\d+px\s*!important/.test(text)) {
			el.remove();
		}
	});

	doc.querySelectorAll('link[href], script[src]').forEach((el) => {
		const url = el.getAttribute('href') || el.getAttribute('src') || '';
		if (/\/admin-bar(\.min)?\.(css|js)/.test(url) || /hoverintent/.test(url) || /static-site-deployer/.test(url)) {
			el.remove();
		}
	});

	doc.querySelectorAll('script:not([src])').forEach((el) => {
		const text = el.textContent || '';
		if (text.includes('SSD_CRAWLER')) {
			el.remove();
		}
	});

	const body = doc.querySelector('body');
	if (body && body.className) {
		body.className = body.className.replace(/\badmin-bar\b/g, '').replace(/\s+/g, ' ').trim();
	}
}

/**
 * Resolves a single attribute URL. Returns the root-relative rewrite, or null
 * to leave the original value untouched (empty, unsupported scheme, external).
 *
 * @param {string|null} value
 * @param {URL} from
 * @param {URL} base
 * @param {URL[]} found
 * @returns {string|null}
 */
function handleUrl(value, from, base, found) {
	const abs = resolveUrl(value, from.href);
	if (!abs) {
		return null;
	}
	if (!inScope(abs, base)) {
		return null;
	}
	found.push(abs);
	return prettyLink(abs, base);
}

/**
 * @param {string|null} value
 * @param {URL} from
 * @param {URL} base
 * @param {URL[]} found
 * @returns {string|null}
 */
function rewriteSrcset(value, from, base, found) {
	if (!value) {
		return null;
	}
	const entries = value
		.split(',')
		.map((entry) => entry.trim())
		.filter(Boolean)
		.map((entry) => {
			const parts = entry.split(/\s+/);
			const rewritten = handleUrl(parts[0], from, base, found);
			if (rewritten !== null) {
				parts[0] = rewritten;
			}
			return parts.join(' ');
		});
	return entries.join(', ');
}

/**
 * Rewrites `url(...)` references in a CSS string. Relative URLs resolve against
 * the stylesheet's own URL.
 *
 * @param {string} text
 * @param {URL} cssUrl
 * @param {URL} base
 * @returns {{css: string, urls: URL[]}}
 */
function rewriteCss(text, cssUrl, base) {
	const urls = [];
	const css = rewriteCssText(text, cssUrl, base, urls);
	return { css, urls };
}

/**
 * @param {string} text
 * @param {URL} from
 * @param {URL} base
 * @param {URL[]} found
 * @returns {string}
 */
function rewriteCssText(text, from, base, found) {
	return text.replace(CSS_URL, (match, quote, rawUrl) => {
		const rewritten = handleUrl(rawUrl, from, base, found);
		if (rewritten === null) {
			return match;
		}
		return 'url(' + quote + rewritten + quote + ')';
	});
}

/**
 * @param {string} contentType
 * @returns {boolean}
 */
function isHtml(contentType) {
	return contentType.includes('html');
}

/**
 * @param {string} contentType
 * @returns {boolean}
 */
function isXml(contentType) {
	return /xml|rss|atom/i.test(contentType);
}

/**
 * Extracts `<loc>` URLs from an XML sitemap so nested sitemaps and their pages
 * are discovered.
 *
 * @param {string} text
 * @param {URL} from
 * @returns {URL[]}
 */
function extractXmlLinks(text, from) {
	const found = [];
	const re = /<loc>\s*([^<\s]+)\s*<\/loc>/gi;
	let match;
	while ((match = re.exec(text)) !== null) {
		const abs = resolveUrl(decodeXmlEntities(match[1]), from.href);
		if (abs) {
			found.push(abs);
		}
	}
	return found;
}

/**
 * @param {string} value
 * @returns {string}
 */
function decodeXmlEntities(value) {
	return value
		.replace(/&amp;/g, '&')
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&quot;/g, '"')
		.replace(/&#0?39;|&apos;/g, "'");
}

/**
 * HTML that redirects to the sibling `index.xml`, so directory-style feed URLs
 * resolve on static hosts that look for `index.html`.
 *
 * @returns {string}
 */
function xmlRedirectStub() {
	return (
		'<!DOCTYPE html>\n<html><head><meta charset="utf-8">' +
		'<meta http-equiv="refresh" content="0;url=index.xml">' +
		'<title>Redirecting…</title></head>' +
		'<body><script>location.replace("index.xml")</script></body></html>'
	);
}

/**
 * @param {string|null|undefined} value
 * @param {string} fromHref
 * @returns {URL|null}
 */
function resolveUrl(value, fromHref) {
	if (!value) {
		return null;
	}
	const trimmed = value.trim();
	if (trimmed === '' || SKIP_SCHEME.test(trimmed)) {
		return null;
	}
	try {
		const url = new URL(trimmed, fromHref);
		url.hash = '';
		return url;
	} catch {
		return null;
	}
}

/**
 * Parses a URL string, falling back to a known-good URL when it's empty or
 * invalid. Some `fetch` implementations leave `Response.url` blank; in that case
 * the requested URL is the best available answer for the final location.
 *
 * @param {string} value
 * @param {URL} fallback
 * @returns {URL}
 */
function safeUrl(value, fallback) {
	if (!value) {
		return fallback;
	}
	try {
		const url = new URL(value);
		url.hash = '';
		return url;
	} catch {
		return fallback;
	}
}

/**
 * @param {URL} url
 * @param {URL} base
 * @returns {boolean}
 */
function inScope(url, base) {
	if (url.origin !== base.origin) {
		return false;
	}
	if (url.pathname.startsWith(base.pathname)) {
		return true;
	}
	// WordPress emits the site root without a trailing slash (home_url()), while
	// base always has one. Treat that bare root as in scope so the home/site-title
	// link resolves to "/" instead of being stripped to an empty (self-linking) href.
	return base.pathname.endsWith('/') && url.pathname === base.pathname.slice(0, -1);
}

/**
 * Whether an in-scope URL is a dynamic/admin path excluded from static output.
 *
 * @param {URL} url
 * @param {URL} base
 * @returns {boolean}
 */
function isExcluded(url, base) {
	if (url.searchParams.has('s')) {
		return true;
	}
	const path = stripBase(url.pathname, base);
	return DEFAULT_EXCLUDE.some((pattern) => pattern.test(path));
}

/**
 * Strips the base path prefix (e.g. the Playground `/scope:xxxx/`) so links and
 * files are rooted at the deploy root. Returns a leading-slash path.
 *
 * @param {string} pathname
 * @param {URL} base
 * @returns {string}
 */
function stripBase(pathname, base) {
	if (base.pathname !== '/') {
		if (pathname.startsWith(base.pathname)) {
			return '/' + pathname.slice(base.pathname.length);
		}
		// Bare site root (no trailing slash) maps to the deploy root.
		if (base.pathname.endsWith('/') && pathname === base.pathname.slice(0, -1)) {
			return '/';
		}
	}
	return pathname;
}

/** Root-relative link (pretty URL) for an in-scope absolute URL. */
function prettyLink(url, base) {
	return stripBase(url.pathname, base);
}

/**
 * Maps a pretty path to a static file path (no leading slash). Directory-style
 * and extensionless URLs become `.../<indexName>`.
 *
 * @param {string} prettyPath
 * @param {string} [indexName]
 * @returns {string}
 */
function outputPathFor(prettyPath, indexName = 'index.html') {
	let path = prettyPath || '/';
	if (path.endsWith('/')) {
		path += indexName;
	} else {
		const lastSegment = path.slice(path.lastIndexOf('/') + 1);
		if (!HAS_EXTENSION.test(lastSegment)) {
			path += '/' + indexName;
		}
	}
	return path.replace(/^\/+/, '');
}
