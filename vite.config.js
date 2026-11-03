/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Two entries, one per script the server loads: js/todofolder-main.mjs and
 * js/todofolder-admin.mjs, plus css/todofolder-<entry>.css — the names Util::addScript() and
 * Util::addStyle() resolve. `npm run build` empties js/ first, so the plain-JavaScript
 * files from the early stages are replaced by these bundles.
 *
 * Written explicitly instead of using @nextcloud/vite-config: that preset hung on
 * Windows while building this app (no output, gigabytes of memory), and nothing here
 * needs its defaults.
 */

import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	// @nextcloud/vue reads these as bare globals (`appName`, `appVersion`) and logs an
	// error when they are undefined; @nextcloud/vite-config normally injects them.
	define: {
		appName: JSON.stringify('todofolder'),
		appVersion: JSON.stringify('1.0.0'),
	},
	resolve: {
		alias: {
			'@': fileURLToPath(new URL('./src', import.meta.url)),
		},
	},
	build: {
		outDir: 'js',
		emptyOutDir: true,
		// One CSS file per entry, not one per chunk. With code splitting on, the styles of
		// @nextcloud/vue components that end up in a lazy chunk (NcAppNavigationItem,
		// NcListItem, …) only arrive when that chunk is imported — so the navigation and
		// the list rendered unstyled on first paint. Util::addStyle() loads one file, so
		// everything has to be in it.
		cssCodeSplit: false,
		sourcemap: false,
		rollupOptions: {
			input: {
				main: fileURLToPath(new URL('./src/main.js', import.meta.url)),
				admin: fileURLToPath(new URL('./src/admin.js', import.meta.url)),
			},
			output: {
				entryFileNames: 'todofolder-[name].mjs',
				chunkFileNames: 'todofolder-[name]-[hash].mjs',
				// CSS lands in js/ first; scripts/move-css.mjs then moves it into css/, where
				// Utility::addStyle() looks. rollup refuses a pattern that climbs out of outDir.
				// With cssCodeSplit off there is exactly one stylesheet for both entries, named
				// after whichever chunk Vite picks — force the name Util::addStyle() resolves.
				assetFileNames: (assetInfo) => (assetInfo.name ?? '').endsWith('.css')
					? 'todofolder-main[extname]'
					: 'todofolder-[name][extname]',
			},
		},
	},
})
