/**
 * Browser entry for the built-in crawler export source.
 *
 * Reads config localized as `window.SSD_CRAWLER`, fetches the seed URL list
 * from WordPress, runs the portable crawler core against the live site using
 * same-origin `fetch`, and either records the outcome ("Publish now") or
 * packages the result as a downloadable ZIP ("Download ZIP", a debug aid for
 * comparing against other exporters). Bound to the settings-page buttons and
 * the admin-toolbar "Publish to Cloudflare" node.
 */
import { crawlSite } from './crawler.js';
import { zipSync, strToU8 } from './vendor/fflate.js';
import { deployToCloudflare } from './cloudflare.js';

const cfg = (typeof window !== 'undefined' && window.SSD_CRAWLER) || null;
if (cfg) {
	main();
}

function main() {
	const els = {
		progress: document.getElementById('ssd-crawler-progress'),
		state: document.getElementById('ssd-status-state'),
		bar: document.getElementById('ssd-status-bar'),
		message: document.getElementById('ssd-status-message'),
	};
	const adminBarItem = document.querySelector('#wp-admin-bar-ssd-publish .ab-item');
	const adminBarLabel = adminBarItem ? adminBarItem.textContent : '';
	let adminBarRevert = null;
	let running = false;

	const colorFor = (state) =>
		state === 'error' ? '#d63638' : state === 'success' ? '#00a32a' : '#2271b1';

	const updateAdminBar = (state, percent) => {
		if (!adminBarItem) {
			return;
		}
		if (adminBarRevert) {
			clearTimeout(adminBarRevert);
			adminBarRevert = null;
		}
		if (state === 'running') {
			adminBarItem.textContent =
				adminBarLabel + (percent !== null ? ' — ' + percent + '%' : '…');
		} else if (state === 'success') {
			adminBarItem.textContent = adminBarLabel + ' ✓';
			adminBarRevert = setTimeout(() => (adminBarItem.textContent = adminBarLabel), 6000);
		} else if (state === 'error') {
			adminBarItem.textContent = adminBarLabel + ' ✗';
			adminBarRevert = setTimeout(() => (adminBarItem.textContent = adminBarLabel), 6000);
		}
	};

	// Updates the shared Deployment status block and the admin-bar label, so
	// progress shows live without a page refresh. Pass percent = null to leave
	// the bar width unchanged (e.g. on error).
	const report = (state, percent, message) => {
		if (els.progress) {
			els.progress.textContent = message;
		}
		if (els.state) {
			els.state.textContent = state;
		}
		if (els.message) {
			els.message.textContent = message;
		}
		if (els.bar) {
			if (percent !== null) {
				els.bar.style.width = percent + '%';
			}
			els.bar.style.background = colorFor(state);
		}
		updateAdminBar(state, percent);
	};

	const crawl = async (scale) => {
		report('running', 2, 'Collecting URLs…');
		const seedUrls = await fetchSeeds();

		report('running', 5, 'Crawling…');
		return crawlSite(
			{
				fetch: (url, init) => window.fetch(url, init),
				parseHTML: (html) => new DOMParser().parseFromString(html, 'text/html'),
			},
			{
				baseUrl: cfg.baseUrl,
				seedUrls,
				onProgress: (p) => {
					const frac = p.discovered ? p.processed / p.discovered : 0;
					report(
						'running',
						scale(frac),
						`Crawling… ${p.processed}/${p.discovered} — ${p.pages} pages, ${p.assets} assets`
					);
				},
			}
		);
	};

	const guard = (action) => async (event) => {
		if (event) {
			event.preventDefault();
		}
		if (running) {
			return;
		}
		running = true;
		try {
			await action();
		} catch (e) {
			report('error', null, 'Error: ' + (e && e.message ? e.message : String(e)));
		} finally {
			running = false;
		}
	};

	const publish = guard(async () => {
		if (!cfg.canDeploy) {
			report('error', null, 'Add your Cloudflare account ID, worker name, and relay URL in settings to deploy.');
			return;
		}
		const token = getToken();
		if (!token) {
			report('error', null, 'A Cloudflare API token is required to deploy.');
			return;
		}

		// In the block editor, block further saves and show a notice until the
		// deploy finishes.
		const lock = editorLock();
		editorNotice('info', 'Deploying to Cloudflare…');
		try {
			// Crawl fills the first half of the bar, deploy the second half.
			const result = await crawl((frac) => Math.round(frac * 50));
			await deployToCloudflare({
				fetch: (url, init) => window.fetch(url, init),
				accountId: cfg.accountId,
				scriptName: cfg.scriptName,
				token,
				relayUrl: cfg.relayUrl,
				files: result.files,
				onProgress: (percent, message) =>
					report('running', 50 + Math.round(percent / 2), message),
			});
			report(
				'success',
				100,
				`Deployed to Cloudflare — ${result.pages} pages, ${result.assets} assets, ${result.errors.length} crawl errors.`
			);
			await record('success', `Deployed ${result.files.size} files to Cloudflare.`);
			editorNotice('success', 'Deployed to Cloudflare.');
		} catch (e) {
			const msg = e && e.message ? e.message : String(e);
			report('error', null, 'Deploy failed: ' + msg);
			await record('error', 'Deploy failed: ' + msg);
			editorNotice('error', 'Deploy failed: ' + msg);
		} finally {
			if (lock) {
				lock.unlock();
			}
		}
	});

	const downloadZip = guard(async () => {
		const result = await crawl((frac) => Math.round(frac * 90));
		report('running', 95, 'Packaging ZIP…');
		triggerDownload(buildZip(result), 'ssd-crawler-export.zip');
		report(
			'success',
			100,
			`Downloaded ssd-crawler-export.zip — ${result.files.size} files (${result.pages} pages, ${result.assets} assets).`
		);
	});

	bind('ssd-crawler-publish', publish);
	bind('ssd-crawler-zip', downloadZip);

	const toolbarLink = document.querySelector('#wp-admin-bar-ssd-publish a');
	if (toolbarLink) {
		toolbarLink.addEventListener('click', publish);
	}

	if (new URLSearchParams(location.search).get('ssd_start') === 'crawler') {
		publish();
	}

	// A content change queued a deploy while no browser was available; run it now.
	if (cfg.pending && cfg.canDeploy) {
		publish();
	}

	// Block editor: deploy automatically once a (non-autosave) save completes.
	if (cfg.canDeploy && window.wp && window.wp.data) {
		const editor = window.wp.data.select('core/editor');
		if (editor) {
			let wasSaving = false;
			window.wp.data.subscribe(() => {
				const ed = window.wp.data.select('core/editor');
				if (!ed) {
					return;
				}
				const saving = ed.isSavingPost() && !ed.isAutosavingPost();
				if (wasSaving && !saving) {
					publish();
				}
				wasSaving = saving;
			});
		}
	}
}

function bind(id, handler) {
	const el = document.getElementById(id);
	if (el) {
		el.addEventListener('click', handler);
	}
}

// A token entered at publish time (when none is stored) is kept in memory for
// this page session only, never persisted.
let sessionToken = '';

/**
 * Resolves the Cloudflare API token: the stored one if present, otherwise a
 * one-time token entered by the user (cached for this session, not stored).
 *
 * @returns {string}
 */
function getToken() {
	if (cfg.token) {
		return cfg.token;
	}
	if (sessionToken) {
		return sessionToken;
	}
	const entered = window.prompt('Enter your Cloudflare API token (used once for this deploy, not stored):');
	sessionToken = entered ? entered.trim() : '';
	return sessionToken;
}

/**
 * In the block editor, prevents further saves until unlocked. Returns an
 * unlock handle, or null outside the editor.
 *
 * @returns {{unlock: () => void}|null}
 */
function editorLock() {
	if (!(window.wp && window.wp.data && window.wp.data.select('core/editor'))) {
		return null;
	}
	const dispatch = window.wp.data.dispatch('core/editor');
	if (!dispatch || !dispatch.lockPostSaving) {
		return null;
	}
	dispatch.lockPostSaving('ssd-deploy');
	return {
		unlock: () => dispatch.unlockPostSaving('ssd-deploy'),
	};
}

/**
 * Shows a deploy notice in the block editor, replacing any prior one.
 *
 * @param {'info'|'success'|'error'} type
 * @param {string} message
 */
function editorNotice(type, message) {
	if (!(window.wp && window.wp.data && window.wp.data.dispatch('core/notices'))) {
		return;
	}
	const notices = window.wp.data.dispatch('core/notices');
	if (notices.removeNotice) {
		notices.removeNotice('ssd-deploy');
	}
	const options = { id: 'ssd-deploy', isDismissible: type !== 'info' };
	if (type === 'success') {
		notices.createSuccessNotice(message, options);
	} else if (type === 'error') {
		notices.createErrorNotice(message, options);
	} else {
		notices.createInfoNotice(message, options);
	}
}

/**
 * Packs the crawl result into a ZIP archive.
 *
 * @param {{files: Map<string, {bytes?: Uint8Array, text?: string}>}} result
 * @returns {Uint8Array}
 */
function buildZip(result) {
	const entries = {};
	for (const [path, file] of result.files) {
		entries[path] = file.bytes ? file.bytes : strToU8(file.text || '');
	}
	return zipSync(entries, { level: 6 });
}

function triggerDownload(bytes, filename) {
	const blob = new Blob([bytes], { type: 'application/zip' });
	const url = URL.createObjectURL(blob);
	const link = document.createElement('a');
	link.href = url;
	link.download = filename;
	document.body.appendChild(link);
	link.click();
	link.remove();
	setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function fetchSeeds() {
	const body = new URLSearchParams({ action: cfg.seedsAction, nonce: cfg.nonce });
	const res = await fetch(cfg.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body,
	});
	const json = await res.json();
	return json && json.success && json.data && json.data.seeds ? json.data.seeds : [];
}

async function record(status, message) {
	const body = new URLSearchParams({
		action: cfg.recordAction,
		nonce: cfg.nonce,
		status,
		message,
	});
	await fetch(cfg.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body,
	});
	await refreshHistory();
}

/** Rebuilds the settings-page History table from the server, without a reload. */
async function refreshHistory() {
	const wrap = document.getElementById('ssd-history');
	if (!wrap || !cfg.statusAction) {
		return;
	}
	try {
		const url =
			cfg.ajaxUrl +
			'?action=' +
			encodeURIComponent(cfg.statusAction) +
			'&nonce=' +
			encodeURIComponent(cfg.statusNonce);
		const res = await fetch(url, { credentials: 'same-origin' });
		const json = await res.json();
		if (!json || !json.success) {
			return;
		}
		renderHistory((json.data && json.data.history) || []);
	} catch {
		// Non-fatal: the table just keeps its current rows.
	}
}

function renderHistory(history) {
	const table = document.getElementById('ssd-history-table');
	const empty = document.getElementById('ssd-history-empty');
	const body = document.getElementById('ssd-history-body');
	if (!table || !empty || !body) {
		return;
	}
	if (!history.length) {
		table.style.display = 'none';
		empty.style.display = '';
		return;
	}
	empty.style.display = 'none';
	table.style.display = '';
	body.textContent = '';
	for (const entry of history) {
		const tr = document.createElement('tr');
		for (const key of ['when', 'status', 'message']) {
			const td = document.createElement('td');
			td.textContent = entry[key] || '';
			tr.appendChild(td);
		}
		body.appendChild(tr);
	}
}
