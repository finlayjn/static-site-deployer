/**
 * Cloudflare Workers static-assets uploader (browser port).
 *
 * Mirrors the server-side {@see \SSD\CloudflareAssetsDeployer} protocol so the
 * built-in crawler can deploy from the browser:
 *   1. Start an assets upload session with a manifest of {hash, size}.
 *   2. Upload each returned bucket of files (base64 multipart).
 *   3. Deploy the Worker script referencing the completed upload.
 *
 * `api.cloudflare.com` does not send permissive CORS headers, so requests are
 * routed through a relay Worker (see assets/crawler/worker/relay.js). Pass its
 * origin as `relayUrl`; when empty, requests go directly (works only where CORS
 * is permitted).
 *
 * @typedef {Object} DeployOptions
 * @property {typeof fetch} [fetch]
 * @property {string} accountId
 * @property {string} scriptName
 * @property {string} token
 * @property {string} [relayUrl]
 * @property {Map<string, {bytes?: Uint8Array, text?: string}>} files
 * @property {(percent: number, message: string) => void} [onProgress]
 */

const DIRECT_API_BASE = 'https://api.cloudflare.com/client/v4';

const EXT_MIME = {
	html: 'text/html',
	xml: 'application/xml',
	css: 'text/css',
	js: 'text/javascript',
	json: 'application/json',
	svg: 'image/svg+xml',
	png: 'image/png',
	jpg: 'image/jpeg',
	jpeg: 'image/jpeg',
	gif: 'image/gif',
	webp: 'image/webp',
	avif: 'image/avif',
	ico: 'image/x-icon',
	woff: 'font/woff',
	woff2: 'font/woff2',
	ttf: 'font/ttf',
	otf: 'font/otf',
	eot: 'application/vnd.ms-fontobject',
	txt: 'text/plain',
	pdf: 'application/pdf',
	mp4: 'video/mp4',
	webm: 'video/webm',
	mp3: 'audio/mpeg',
};

/**
 * Deploys crawled files to a Cloudflare Worker's static assets.
 *
 * @param {DeployOptions} options
 */
export async function deployToCloudflare(options) {
	const fetchImpl = options.fetch || globalThis.fetch;
	const onProgress = options.onProgress || (() => {});
	const apiBase = options.relayUrl
		? options.relayUrl.replace(/\/+$/, '') + '/client/v4'
		: DIRECT_API_BASE;

	onProgress(5, 'Preparing files…');
	const { manifest, byHash } = await buildManifest(options.files);

	onProgress(10, 'Starting upload…');
	const session = await apiRequest(fetchImpl, 'POST', apiBase, options.token, {
		path: `/accounts/${options.accountId}/workers/scripts/${options.scriptName}/assets-upload-session`,
		json: { manifest },
	});

	const jwt = session?.result?.jwt ?? null;
	const buckets = session?.result?.buckets ?? [];

	let completionToken = jwt || '';
	if (jwt && buckets.length > 0) {
		let done = 0;
		for (const bucket of buckets) {
			completionToken = await uploadBucket(
				fetchImpl,
				apiBase,
				options.accountId,
				completionToken,
				bucket,
				byHash
			);
			done++;
			onProgress(
				Math.round(10 + (done / buckets.length) * 80),
				`Uploading files (${done}/${buckets.length})…`
			);
		}
	} else if (jwt && buckets.length === 0) {
		// Session returned a token but nothing to upload.
	} else if (!jwt && buckets.length > 0) {
		throw new Error('Cloudflare returned buckets without an upload token.');
	}

	onProgress(95, 'Deploying…');
	await deployAssets(fetchImpl, apiBase, options, completionToken);
	onProgress(100, 'Deployed.');
}

/**
 * @param {Map<string, {bytes?: Uint8Array, text?: string}>} files
 */
async function buildManifest(files) {
	const encoder = new TextEncoder();
	const manifest = {};
	const byHash = new Map();

	for (const [path, file] of files) {
		const bytes = file.bytes ? file.bytes : encoder.encode(file.text || '');
		const hash = await sha256Hex(bytes);
		const short = hash.slice(0, 32);
		manifest['/' + path] = { hash: short, size: bytes.length };
		byHash.set(short, { bytes, path });
	}

	if (Object.keys(manifest).length === 0) {
		throw new Error('No files to deploy.');
	}
	return { manifest, byHash };
}

/**
 * Uploads one bucket of files (by hash) and returns the continuation token.
 *
 * @param {typeof fetch} fetchImpl
 * @param {string} apiBase
 * @param {string} accountId
 * @param {string} jwt
 * @param {string[]} hashes
 * @param {Map<string, {bytes: Uint8Array, path: string}>} byHash
 * @returns {Promise<string>}
 */
async function uploadBucket(fetchImpl, apiBase, accountId, jwt, hashes, byHash) {
	const boundary = '----SSDBoundary' + Math.random().toString(36).slice(2);
	let body = '';
	for (const hash of hashes) {
		const entry = byHash.get(hash);
		if (!entry) {
			throw new Error(`Missing file for hash ${hash}.`);
		}
		body += `--${boundary}\r\n`;
		body += `Content-Disposition: form-data; name="${hash}"\r\n`;
		body += 'Content-Transfer-Encoding: base64\r\n';
		body += `Content-Type: ${mimeFor(entry.path)}\r\n\r\n`;
		body += uint8ToBase64(entry.bytes) + '\r\n';
	}
	body += `--${boundary}--\r\n`;

	const decoded = await apiRequest(fetchImpl, 'POST', apiBase, jwt, {
		path: `/accounts/${accountId}/workers/assets/upload?base64=true`,
		contentType: `multipart/form-data; boundary=${boundary}`,
		body,
	});

	if (decoded?.result?.jwt) {
		return decoded.result.jwt;
	}
	if (decoded?.success) {
		return jwt;
	}
	throw new Error('Upload response missing continuation token.');
}

/**
 * @param {typeof fetch} fetchImpl
 * @param {string} apiBase
 * @param {DeployOptions} options
 * @param {string} completionToken
 */
async function deployAssets(fetchImpl, apiBase, options, completionToken) {
	const assets = {
		config: {
			html_handling: 'auto-trailing-slash',
			not_found_handling: '404-page',
		},
	};
	if (completionToken !== '') {
		assets.jwt = completionToken;
	}
	const metadata = {
		assets,
		compatibility_date: new Date().toISOString().slice(0, 10),
	};

	const boundary = '----SSDBoundary' + Math.random().toString(36).slice(2);
	let body = `--${boundary}\r\n`;
	body += 'Content-Disposition: form-data; name="metadata"\r\n\r\n';
	body += JSON.stringify(metadata) + '\r\n';
	body += `--${boundary}--\r\n`;

	await apiRequest(fetchImpl, 'PUT', apiBase, options.token, {
		path: `/accounts/${options.accountId}/workers/scripts/${options.scriptName}`,
		contentType: `multipart/form-data; boundary=${boundary}`,
		body,
	});
}

/**
 * Performs an authenticated Cloudflare API request and returns parsed JSON.
 *
 * @param {typeof fetch} fetchImpl
 * @param {string} method
 * @param {string} apiBase
 * @param {string} bearer
 * @param {{path?: string, rawUrl?: string, json?: any, body?: string, contentType?: string}} opts
 */
async function apiRequest(fetchImpl, method, apiBase, bearer, opts) {
	const url = apiBase + opts.path;
	const headers = { Authorization: 'Bearer ' + bearer };
	let body;
	if (opts.json !== undefined) {
		headers['Content-Type'] = 'application/json';
		body = JSON.stringify(opts.json);
	} else if (opts.body !== undefined) {
		headers['Content-Type'] = opts.contentType;
		body = opts.body;
	}

	const res = await fetchImpl(url, { method, headers, body });
	const text = await res.text();
	if (!res.ok) {
		throw new Error(`Cloudflare API error (HTTP ${res.status}): ${text.slice(0, 500)}`);
	}
	try {
		return text ? JSON.parse(text) : {};
	} catch {
		return {};
	}
}

/**
 * @param {Uint8Array} bytes
 * @returns {Promise<string>}
 */
async function sha256Hex(bytes) {
	const digest = await crypto.subtle.digest('SHA-256', bytes);
	return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function uint8ToBase64(bytes) {
	let binary = '';
	const chunk = 0x8000;
	for (let i = 0; i < bytes.length; i += chunk) {
		binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
	}
	return btoa(binary);
}

/**
 * @param {string} path
 * @returns {string}
 */
function mimeFor(path) {
	const ext = path.slice(path.lastIndexOf('.') + 1).toLowerCase();
	return EXT_MIME[ext] || 'application/octet-stream';
}
