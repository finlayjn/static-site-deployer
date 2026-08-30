/**
 * Regression tests for two crawler robustness fixes:
 *   1. The bare site root (home_url() has no trailing slash under a scoped base)
 *      must rewrite to "/" — not an empty, self-linking href that reloads the
 *      current page when the site title is clicked.
 *   2. Transient fetch failures must be retried and, when they persist, recorded
 *      as crawl errors — otherwise a flaky connection silently drops assets.
 *
 * Run with: node tests/test-crawler-fetch.mjs
 */
import assert from 'node:assert';
import { parseHTML } from 'linkedom';
import { crawlSite } from '../assets/crawler/crawler.js';

const parse = (html) => parseHTML(html).document;

function res({ url, ok = true, status = 200, contentType = 'text/html', body = '' }) {
	return {
		ok,
		status,
		url,
		headers: { get: (h) => (h.toLowerCase() === 'content-type' ? contentType : null) },
		arrayBuffer: async () => new TextEncoder().encode(body).buffer,
	};
}

const notFound = {
	ok: false,
	status: 404,
	url: '',
	headers: { get: () => null },
	arrayBuffer: async () => new ArrayBuffer(0),
};

async function testBareRootHomeLinkRewritesToSlash() {
	// A scoped base (as Playground serves) always has a trailing slash, but the
	// theme's site-title link uses home_url() which does not.
	const base = 'http://example.com/scope:abc/';
	const home =
		'<html><body>' +
		'<a href="http://example.com/scope:abc" class="site-name">Home</a>' +
		'</body></html>';
	const fetchImpl = async (href) => {
		const url = new URL(href);
		if (url.pathname === '/scope:abc/') {
			return res({ url: 'http://example.com/scope:abc/', body: home });
		}
		return notFound;
	};

	const { files } = await crawlSite(
		{ fetch: fetchImpl, parseHTML: parse },
		{ baseUrl: base, seedUrls: ['http://example.com/scope:abc/'] }
	);

	const html = files.get('index.html').text;
	assert.ok(
		html.includes('<a href="/" class="site-name">'),
		'bare site root must rewrite to "/", got: ' + (html.match(/<a href="[^"]*" class="site-name">/) || [''])[0]
	);
	assert.ok(!html.includes('href=""'), 'home link must not become an empty self-referencing href');
	console.log('  ok  bare site root rewrites to "/" under a scoped base');
}

async function testTransientFailureIsRetried() {
	const base = 'http://example.com/';
	let attempts = 0;
	const fetchImpl = async (href) => {
		const path = new URL(href).pathname;
		if (path === '/') {
			return res({
				url: 'http://example.com/',
				body: '<html><body><img src="/img.png"></body></html>',
			});
		}
		if (path === '/img.png') {
			attempts++;
			// Fail once (retryable 503), then succeed.
			if (attempts === 1) {
				return res({ url: href, ok: false, status: 503 });
			}
			return res({ url: href, contentType: 'image/png', body: 'PNGDATA' });
		}
		return notFound;
	};

	const { files, errors } = await crawlSite(
		{ fetch: fetchImpl, parseHTML: parse },
		{ baseUrl: base, retryDelay: 0 }
	);

	assert.strictEqual(attempts, 2, 'the transient 503 must be retried exactly once');
	assert.ok(files.has('img.png'), 'the asset must be present after a successful retry');
	assert.ok(!errors.some((e) => e.url === 'http://example.com/img.png'), 'a recovered fetch is not an error');
	console.log('  ok  a transient 5xx is retried and the asset recovered');
}

async function testPersistentFailureIsRecorded() {
	const base = 'http://example.com/';
	const fetchImpl = async (href) => {
		const path = new URL(href).pathname;
		if (path === '/') {
			return res({
				url: 'http://example.com/',
				body: '<html><body><img src="/broken.png"></body></html>',
			});
		}
		if (path === '/broken.png') {
			return res({ url: href, ok: false, status: 503 });
		}
		return notFound;
	};

	const { files, errors } = await crawlSite(
		{ fetch: fetchImpl, parseHTML: parse },
		{ baseUrl: base, retries: 1, retryDelay: 0 }
	);

	assert.ok(!files.has('broken.png'), 'a permanently failing asset is not written');
	assert.ok(
		errors.some((e) => e.url === 'http://example.com/broken.png' && /HTTP 503/.test(e.error)),
		'a persistent non-OK response must be recorded as a crawl error'
	);
	console.log('  ok  a persistent failure is recorded instead of silently dropped');
}

async function main() {
	console.log('==> crawler fetch + home-link handling');
	await testBareRootHomeLinkRewritesToSlash();
	await testTransientFailureIsRetried();
	await testPersistentFailureIsRecorded();
	console.log('All crawler fetch tests passed.');
}

main().catch((e) => {
	console.error(e);
	process.exit(1);
});
