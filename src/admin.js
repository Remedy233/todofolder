/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLoggerBuilder } from '@nextcloud/logger'
import { register } from '@nextcloud/l10n'
import { createApp } from 'vue'
import AdminSettings from './AdminSettings.vue'

getLoggerBuilder()
	.setApp('todofolder')
	.detectUser()
	.build()

// Same as the main bundle: adopt the bundle the server loaded for this user.
const serverBundle = window._oc_l10n_registry_translations?.todofolder
if (serverBundle && Object.keys(serverBundle).length > 0) {
	register('todofolder', serverBundle)
}

const el = document.getElementById('todofolder-admin-app')
if (el) {
	createApp(AdminSettings).mount(el)
}
