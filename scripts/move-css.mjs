/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vite writes the entry's CSS next to the entry (js/todofolder-main.css) because rollup
 * refuses an assetFileNames pattern that climbs out of outDir. Nextcloud expects it in
 * css/, which is where Util::addStyle() looks, so move it after the build.
 *
 * Anything left in js/ that is not the bundle is stale output from an earlier build; it
 * is removed here as well, so `js/` and `css/` only ever hold current artifacts.
 */

import { readdir, rename, rm, stat } from 'node:fs/promises'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const jsDir = join(root, 'js')
const cssDir = join(root, 'css')

let moved = 0
for (const entry of await readdir(jsDir)) {
	if (entry.endsWith('.css')) {
		await rename(join(jsDir, entry), join(cssDir, entry))
		moved++
	}
}

// The plain-JavaScript files of the early stages are superseded by the bundles.
for (const entry of await readdir(jsDir)) {
	if (entry.endsWith('.js')) {
		await rm(join(jsDir, entry), { force: true })
	}
}

const bundles = (await readdir(jsDir)).filter((f) => f.endsWith('.mjs'))
for (const bundle of bundles) {
	const size = (await stat(join(jsDir, bundle))).size
	console.log(`  ${bundle}  ${(size / 1024).toFixed(1)} kB`)
}
console.log(`build: ${bundles.length} bundle(s), ${moved} css file(s) moved to css/`)
