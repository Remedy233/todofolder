/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLoggerBuilder } from '@nextcloud/logger'
import { register } from '@nextcloud/l10n'
import { createApp } from 'vue'
import App from './App.vue'

// @nextcloud/vue logs an error on every page that boots without an app name. This is the
// documented way to tell the library who is speaking.
getLoggerBuilder()
	.setApp('todofolder')
	.detectUser()
	.build()

// The bundle calls t() with English source strings. @nextcloud/l10n keeps its own registry
// and does not read OC.L10N, so hand it the bundle the server already loaded for this user
// (Util::addTranslations emits l10n/<language>.js, which fills OC.L10N).
function adoptServerTranslations() {
	const bundle = window._oc_l10n_registry_translations?.todofolder
	if (bundle && Object.keys(bundle).length > 0) {
		register('todofolder', bundle)
		return true
	}
	return false
}

if (!adoptServerTranslations()) {
	// The l10n script may still be running when this module executes; retry once the
	// document is parsed. Missing translations are not fatal: the English source shows.
	document.addEventListener('DOMContentLoaded', () => {
		adoptServerTranslations()
	})
}

// NcAppNavigation and NcAppContent must be direct children of the server's #content so
// Nextcloud's layout (flex row: navigation on the left, content on the right) applies.
// Mounting there replaces the placeholder from the template.
const container = document.getElementById('content') ?? document.getElementById('todofolder-app')
if (container) {
	createApp(App).mount(container)
}
