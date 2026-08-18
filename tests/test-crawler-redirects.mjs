/**
 * Regression tests for redirect-aware output keying in the crawler core.
 *
 * A redirect must never let the target's bytes be written under the requested
 * path: that is what turned a plugin redirect (`/` -> a private post) into a
 * corrupted homepage. Run with: node tests/test-crawler-redirects.mjs
 */
import assert from 'node:assert';
import { parseHTML } from 'linkedom';
import { crawlSite } from '../assets/crawler/crawler.js';

const parse = (html) => parseHTML(html).document;

/** Builds a minimal Response-like object with a (possibly redirected) URL. */
function res({ url, ok = true, contentType = 'text/html', body = '' }) {
	return {
		ok,
		url,
		headers: { get: (h) => (h.toLowerCase() === 'content-type' ? contentType : null) },
		arrayBuffer: async () => new TextEncoder().encode(body).buffer,
	};
}

const notFound = { ok: false, url: '', headers: { get: () => null }, arrayBuffer: async () => new ArrayBuffer(0) };

async function testRedirectToExcludedDoesNotOverwriteHome() {
	const base = 'http://example.com/';
	const fetchImpl = async (href) => {
		const path = new URL(href).pathname;
		// The home page redirects into an excluded path (as a misbehaving plugin
		// might). The fetched bytes belong to the redirect target.
		if (path === '/') {
			return res({ url: 'http://example.com/wp-json/secret', body: '<html><body>PRIVATE</body></html>' });
		}
		return notFound;
	};

	const { files, errors } = await crawlSite({ fetch: fetchImpl, parseHTML: parse }, { baseUrl: base });

	assert.ok(!files.has('index.html'), 'home must not be written from a redirect into an excluded path');
	assert.ok(
		errors.some((e) => e.url === 'http://example.com/' && /redirected to a skipped URL/.test(e.error)),
		'a skipped redirect must be recorded as an error'
	);
	console.log('  ok  redirect into excluded path does not corrupt index.html');
}

async function testRedirectKeysByFinalUrl() {
	const base = 'http://example.com/';
	const fetchImpl = async (href) => {
		const path = new URL(href).pathname;
		if (path === '/old/') {
			return res({ url: 'http://example.com/new/', body: '<html><body>MOVED</body></html>' });
		}
		return notFound;
	};

	const { files } = await crawlSite(
		{ fetch: fetchImpl, parseHTML: parse },
		{ baseUrl: base, seedUrls: ['/old/'] }
	);

	assert.ok(files.has('new/index.html'), 'content must be stored under the final URL path');
	assert.ok(!files.has('old/index.html'), 'content must not be stored under the requested (redirected) path');
	console.log('  ok  in-scope redirect is keyed by the final URL');
}

async function main() {
	console.log('==> crawler redirect handling');
	await testRedirectToExcludedDoesNotOverwriteHome();
	await testRedirectKeysByFinalUrl();
	console.log('All crawler redirect tests passed.');
}

main().catch((e) => {
	console.error(e);
	process.exit(1);
});
