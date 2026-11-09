<!--
  SPDX-FileCopyrightText: 2026 TodoFolder contributors
  SPDX-License-Identifier: AGPL-3.0-or-later

  Admin settings. Values arrive as initial state (AdminSettings::getForm); the API key is
  write-only — it is sent but never rendered back, only a hint that it exists.

  Laid out with NcSettingsSection, the same component Nextcloud's own admin pages use, so
  the heading, description and spacing match the rest of the settings screen.
-->
<template>
	<NcSettingsSection :name="t('TodoFolder')"
		:description="t('Analyze the files inside each task folder, produce title, due date and tags, and sync them to Deck.')">
		<div class="todofolder-admin">
			<NcNoteCard type="info">
				{{ t('The model is called through an OpenAI-compatible API. Leave the endpoint empty to use the built-in mock, which exercises the whole chain without a real model.') }}
			</NcNoteCard>

			<NcNoteCard v-if="saved" type="success">{{ t('Saved') }}</NcNoteCard>
			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<NcTextField v-model="form.baseUrl"
				:label="t('Endpoint (base_url）')"
				placeholder="https://api.deepseek.com/v1" />
			<NcTextField v-model="form.model" :label="t('Model')" placeholder="deepseek-chat" />
			<NcTextField v-model="form.apiKey"
				type="password"
				:label="t('API key')"
				:helper-text="keyHint" />
			<div class="todofolder-admin__pair">
				<NcTextField v-model="form.timeout" type="number" :label="t('Timeout (seconds)')" />
				<NcTextField v-model="form.queueBatch" type="number" :label="t('Tasks analyzed per background run')" />
			</div>
			<NcCheckboxRadioSwitch v-model="form.deckEnabled" type="switch">
				{{ t('Enable Deck sync') }}
			</NcCheckboxRadioSwitch>

			<div class="todofolder-admin__buttons">
				<NcButton type="primary" :disabled="busy" @click="save">{{ t('Save') }}</NcButton>
				<NcButton :disabled="busy" @click="test">{{ t('Test endpoint') }}</NcButton>
				<span v-if="testResult" class="todofolder-admin__result">{{ testResult }}</span>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const BASE = generateUrl('/apps/todofolder')

function initialState() {
	try {
		return loadState('todofolder', 'config')
	} catch {
		return {}
	}
}

export default {
	name: 'AdminSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection, NcTextField },
	data() {
		const config = initialState()
		return {
			config,
			form: {
				baseUrl: config.baseUrl ?? '',
				model: config.model ?? 'deepseek-chat',
				apiKey: '',
				timeout: config.timeout ?? 60,
				queueBatch: config.queueBatch ?? 5,
				deckEnabled: config.deckEnabled ?? true,
			},
			busy: false,
			saved: false,
			error: '',
			testResult: '',
		}
	},
	computed: {
		keyHint() {
			return this.config?.apiKeySet
				? this.t('Set: {hint} (leave empty to keep it)', { hint: this.config.apiKeyHint })
				: this.t('Not set (using the mock)')
		},
	},
	methods: {
		t(text, values) {
			return translate('todofolder', text, values)
		},
		async save() {
			this.busy = true
			this.saved = false
			this.error = ''
			try {
				const { data } = await axios.put(`${BASE}/api/settings`, {
					baseUrl: this.form.baseUrl,
					model: this.form.model,
					apiKey: this.form.apiKey,
					timeout: Number(this.form.timeout),
					queueBatch: Number(this.form.queueBatch),
					deckEnabled: this.form.deckEnabled,
				})
				this.config = data
				this.form.apiKey = ''
				this.saved = true
			} catch (error) {
				this.error = error?.response?.data?.error ?? error.message
			} finally {
				this.busy = false
			}
		},
		async test() {
			this.busy = true
			this.testResult = this.t('Testing…')
			try {
				const { data } = await axios.post(`${BASE}/api/settings/test`)
				this.testResult = this.t('OK: model={model}, mock={mock}, {ms}ms', {
					model: data.model,
					mock: data.mock,
					ms: data.latencyMs,
				})
			} catch (error) {
				this.testResult = this.t('Failed: {reason}', { reason: error?.response?.data?.error ?? error.message })
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style>
/*
 * The navigation toggle (34×34) floats at the top-left of #app-content, so the settings
 * form starts after it instead of underneath it — same offset the app page uses.
 */
.todofolder-admin-root {
	padding-left: 48px;
}
</style>

<style scoped>
.todofolder-admin {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;
}

.todofolder-admin__pair {
	display: flex;
	gap: 12px;
}

.todofolder-admin__pair > * {
	flex: 1 1 0;
}

.todofolder-admin__buttons {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-top: 4px;
}

.todofolder-admin__result {
	color: var(--color-text-maxcontrast, #666);
	font-size: 13px;
}
</style>
