/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLoggerBuilder } from '@nextcloud/logger'
import { createApp } from 'vue'
import AdminSettings from './AdminSettings.vue'

getLoggerBuilder()
	.setApp('todofolder')
	.detectUser()
	.build()

const el = document.getElementById('todofolder-admin-app')
if (el) {
	createApp(AdminSettings).mount(el)
}
