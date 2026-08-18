#!/usr/bin/env node
/**
 * Local test harness for the portable crawler core.
 *
 * Crawls a running site (e.g. a Playground zip served via
 * ../wordpress-to-playground/bin/serve-playground.sh) and writes the rendered
 * static files to a directory. This exercises the exact core that ships in the
 * browser admin bundle, using linkedom to stand in for the browser's DOMParser.
 *
 * Usage: node bin/crawl.mjs <baseUrl> [outDir]
 *   node bin/crawl.mjs http://localhost:9500/ crawl-out
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { parseHTML } from 'linkedom';
import { crawlSite } from '../assets/crawler/crawler.js';

const baseUrl = process.argv[2];
const outDir = process.argv[3] || 'crawl-out';

if (!baseUrl) {
	console.error('Usage: node bin/crawl.mjs <baseUrl> [outDir]');
	process.exit(1);
}

const result = await crawlSite(
	{
		fetch: globalThis.fetch,
		parseHTML: (html) => parseHTML(html).document,
	},
	{
		baseUrl,
		onProgress: (p) => {
			process.stdout.write(
				`\r  processed ${p.processed}/${p.discovered}  pages ${p.pages}  assets ${p.assets}  errors ${p.errors}   `
			);
		},
	}
);

for (const [path, file] of result.files) {
	const full = join(outDir, path);
	await mkdir(dirname(full), { recursive: true });
	await writeFile(full, file.bytes ?? file.text);
}

console.log(`\n\nDone. Wrote ${result.files.size} files to ${outDir}/`);
console.log(`  pages:  ${result.pages}`);
console.log(`  assets: ${result.assets}`);
console.log(`  errors: ${result.errors.length}`);
for (const err of result.errors.slice(0, 10)) {
	console.log(`    ! ${err.url} — ${err.error}`);
}
