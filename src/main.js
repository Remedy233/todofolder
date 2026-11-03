/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLoggerBuilder } from '@nextcloud/logger'
import { createApp } from 'vue'
import App from './App.vue'

// @nextcloud/vue logs an error on every page that boots without an app name. This is the
// documented way to tell the library who is speaking.
getLoggerBuilder()
	.setApp('todofolder')
	.detectUser()
	.build()

// NcAppNavigation and NcAppContent must be direct children of the server's #content so
// Nextcloud's layout (flex row: navigation on the left, content on the right) applies.
// Mounting there replaces the placeholder from the template.
const container = document.getElementById('content') ?? document.getElementById('todofolder-app')
if (container) {
	createApp(App).mount(container)
}
