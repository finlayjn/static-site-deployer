/**
 * Static Site Deployer — Cloudflare API CORS relay.
 *
 * `api.cloudflare.com` does not send permissive CORS headers, so a browser
 * cannot call it directly. This tiny Worker forwards requests from the plugin
 * to the Cloudflare API and adds CORS headers, so the built-in crawler can
 * deploy from the browser.
 *
 * The caller's `Authorization: Bearer <token>` header is passed through
 * unchanged — this Worker stores no credentials. Lock it down to your own
 * site origin(s) via the ALLOWED_ORIGINS environment variable (space- or
 * comma-separated). If unset, all origins are allowed (NOT recommended).
 *
 * Deploy with wrangler (see README.md), then paste the Worker URL into the
 * plugin's "Cloudflare API relay URL" setting.
 */

const API_ORIGIN = 'https://api.cloudflare.com';

export default {
	/**
	 * @param {Request} request
	 * @param {{ ALLOWED_ORIGINS?: string }} env
	 */
	async fetch(request, env) {
		const origin = request.headers.get('Origin') || '';
		const allowed = isAllowedOrigin(origin, env.ALLOWED_ORIGINS);

		if (request.method === 'OPTIONS') {
			return new Response(null, { status: 204, headers: corsHeaders(origin, allowed) });
		}

		if (!allowed) {
			return new Response('Origin not allowed', { status: 403 });
		}

		const incoming = new URL(request.url);
		// Only proxy the Cloudflare API surface.
		if (!incoming.pathname.startsWith('/client/v4/')) {
			return new Response('Not found', { status: 404, headers: corsHeaders(origin, allowed) });
		}

		const target = API_ORIGIN + incoming.pathname + incoming.search;

		// Forward method, body, and the auth + content-type headers only.
		const headers = new Headers();
		const auth = request.headers.get('Authorization');
		const contentType = request.headers.get('Content-Type');
		if (auth) {
			headers.set('Authorization', auth);
		}
		if (contentType) {
			headers.set('Content-Type', contentType);
		}

		const init = { method: request.method, headers };
		if (request.method !== 'GET' && request.method !== 'HEAD') {
			init.body = await request.arrayBuffer();
		}

		const upstream = await fetch(target, init);
		const responseHeaders = corsHeaders(origin, allowed);
		const upstreamType = upstream.headers.get('Content-Type');
		if (upstreamType) {
			responseHeaders.set('Content-Type', upstreamType);
		}
		return new Response(upstream.body, { status: upstream.status, headers: responseHeaders });
	},
};

/**
 * @param {string} origin
 * @param {string|undefined} allowedList
 * @returns {boolean}
 */
function isAllowedOrigin(origin, allowedList) {
	if (!allowedList || allowedList.trim() === '') {
		return true;
	}
	const list = allowedList.split(/[\s,]+/).filter(Boolean);
	return list.includes(origin);
}

/**
 * @param {string} origin
 * @param {boolean} allowed
 * @returns {Headers}
 */
function corsHeaders(origin, allowed) {
	const headers = new Headers();
	headers.set('Access-Control-Allow-Origin', allowed && origin ? origin : '*');
	headers.set('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS');
	headers.set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
	headers.set('Access-Control-Max-Age', '86400');
	headers.set('Vary', 'Origin');
	return headers;
}
