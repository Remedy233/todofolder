<!--
  SPDX-FileCopyrightText: 2026 TodoFolder contributors
  SPDX-License-Identifier: AGPL-3.0-or-later

  Official Nextcloud layout: NcAppNavigation on the left, NcAppContent on the right, and
  the platform's own list/empty-state/dialog components for everything else. The Vue app
  mounts straight into the server's #content element, so these two are its direct
  children — which is what the legacy (34) and the newer layout both expect.
-->
<template>
	<NcAppNavigation v-model:open="navOpen" class="todofolder-navigation" :aria-label="t('Workspace')">
		<template #list>
			<NcAppNavigationNew :text="t('New workspace')" @click="openWorkspaceDialog()" />
			<NcAppNavigationSearch v-model="search" :label="t('Search workspaces')" />
			<NcAppNavigationList>
				<NcAppNavigationItem v-for="workspace in filteredWorkspaces"
					:key="workspace.id"
					:name="workspace.name"
					:title="workspace.folderPath"
					:active="workspace.id === currentId"
					@click="selectWorkspace(workspace.id)">
					<template #icon>
						<NcIconSvgWrapper :svg="icons.folder" />
					</template>
					<template v-if="workspace.id === currentId && tasks.length" #counter>
						<NcCounterBubble :count="tasks.length" />
					</template>
					<template #actions>
						<NcActionButton v-if="!workspace.shared" @click.stop="removeWorkspace(workspace)">
							<template #icon>
								<NcIconSvgWrapper :svg="icons.trash" />
							</template>
							{{ t('Delete workspace') }}
						</NcActionButton>
						<NcActionButton @click.stop="prepareDeck">
							<template #icon>
								<NcIconSvgWrapper :svg="icons.deck" />
							</template>
							{{ t('Prepare board') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>
		</template>
	</NcAppNavigation>

	<NcAppContent class="todofolder-content">
		<div class="todofolder-head">
			<div class="todofolder-head__titles">
				<h2 :title="current ? current.folderPath : ''">
					{{ current ? current.name : t('TodoFolder') }}
				</h2>
				<p v-if="current" class="todofolder-head__path">{{ current.folderPath }}</p>
			</div>
			<div v-if="current" class="todofolder-head__actions">
				<NcButton :disabled="busy" @click="scan">
					<template #icon><NcLoadingIcon v-if="busy === 'scan'" :size="16" /><NcIconSvgWrapper v-else :svg="icons.refresh" /></template>
					{{ t('Scan folder') }}
				</NcButton>
				<NcButton type="primary" :disabled="busy" @click="analyzePending">
					<template #icon><NcLoadingIcon v-if="busy === 'analyze'" :size="16" /><NcIconSvgWrapper v-else :svg="icons.sparkles" /></template>
					{{ t('Analyze pending') }}
				</NcButton>
				<NcButton :disabled="busy" @click="openSettingsDialog()">
					<template #icon><NcIconSvgWrapper :svg="icons.cog" /></template>
					{{ t('Workspace settings') }}
				</NcButton>
			</div>
		</div>

		<NcEmptyContent v-if="!current"
			:name="t('No workspaces yet')"
			:description="t('Create a workspace that points at the folder holding your tasks: every subfolder becomes one task.')">
			<template #icon>
				<NcIconSvgWrapper :svg="icons.folder" />
			</template>
			<template #action>
				<NcButton type="primary" @click="openWorkspaceDialog()">{{ t('New workspace') }}</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="todofolder-body">
			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
			<NcNoteCard v-if="status" type="info">{{ status }}</NcNoteCard>

			<NcNoteCard v-if="!current.deckStackId" type="warning">
				{{ t('This workspace is not linked to a Deck board yet, so results will not become cards automatically.') }}
				<NcButton type="primary" :disabled="busy" @click="prepareDeck">
					<template #icon><NcLoadingIcon v-if="busy === 'deck'" :size="16" /></template>
					{{ t('Prepare board') }}
				</NcButton>
			</NcNoteCard>

			<NcEmptyContent v-if="tasks.length === 0"
				:name="t('No tasks yet')"
				:description="t('Use “Scan folder” to turn subfolders into tasks, then “Analyze pending” to let the model fill in title, dates and tags.')">
				<template #icon>
					<NcIconSvgWrapper :svg="icons.check" />
				</template>
			</NcEmptyContent>

			<ul v-else class="todofolder-tasks">
				<NcListItem v-for="task in tasks"
					:key="task.id"
					:class="{ 'todofolder-task--done': task.status === 'done' }"
					:name="task.title || task.folderName"
					:details="task.dueDate ? t('Due {date}', { date: task.dueDate }) : ''"
					:force-display-actions="true"
					@click="applyOverride(task)">
					<template #icon>
						<NcIconSvgWrapper :svg="statusIcon(task)" />
					</template>
					<template #subname>
						<span class="todofolder-task__meta">
							<span v-if="task.receivedAt">{{ t('Received {date}', { date: task.receivedAt }) }}</span>
							<span v-if="task.status !== 'ready'" class="todofolder-task__badge" :class="`todofolder-task__badge--${task.status}`">
								{{ statusLabel(task.status) }}
							</span>
							<span v-for="tag in task.tags" :key="tag.id" class="todofolder-task__tag">{{ tag.tagName }}</span>
							<span v-if="task.errorMessage" class="todofolder-task__error" :title="task.errorMessage">{{ task.errorMessage }}</span>
						</span>
					</template>
					<template #actions>
						<NcActionButton :disabled="busy" @click.stop="analyze(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.sparkles" /></template>
							{{ t('Analyze') }}
						</NcActionButton>
						<NcActionButton :disabled="busy" @click.stop="applyOverride(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.pencil" /></template>
							{{ t('Edit') }}
						</NcActionButton>
						<NcActionButton v-if="canStart(task)" :disabled="busy" @click.stop="lifecycle(task, 'start')">
							<template #icon><NcIconSvgWrapper :svg="icons.play" /></template>
							{{ t('Start') }}
						</NcActionButton>
						<NcActionButton v-if="canFinish(task)" :disabled="busy" @click.stop="lifecycle(task, 'finish')">
							<template #icon><NcIconSvgWrapper :svg="icons.check" /></template>
							{{ t('Finish') }}
						</NcActionButton>
						<NcActionButton v-if="task.status !== 'ignored'" :disabled="busy" @click.stop="lifecycle(task, 'ignore')">
							<template #icon><NcIconSvgWrapper :svg="icons.close" /></template>
							{{ t('Ignore') }}
						</NcActionButton>
						<NcActionButton v-else :disabled="busy" @click.stop="lifecycle(task, 'restore')">
							<template #icon><NcIconSvgWrapper :svg="icons.restore" /></template>
							{{ t('Restore') }}
						</NcActionButton>
						<NcActionButton v-if="current.deckStackId" :disabled="busy" @click.stop="syncDeck(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.deck" /></template>
							{{ task.deckCardId ? t('Update Deck card') : t('Create Deck card') }}
						</NcActionButton>
						<NcActionButton :disabled="busy" @click.stop="openFolder(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.folder" /></template>
							{{ t('Open folder') }}
						</NcActionButton>
						<NcActionButton v-if="task.deckCardId" @click.stop="openCard(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.deck" /></template>
							{{ t('Open Deck card') }}
						</NcActionButton>
						<NcActionButton :disabled="busy" @click.stop="removeTask(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.close" /></template>
							{{ t('Delete task record') }}
						</NcActionButton>
					</template>
				</NcListItem>
			</ul>
		</div>
	</NcAppContent>

	<!-- 新建 / 编辑工作区 -->
	<NcDialog :open="workspaceDialog" :name="dialogTitle" size="normal" @update:open="workspaceDialog = $event">
		<div class="todofolder-form">
			<NcTextField v-model="form.name" :label="t('Workspace name')" />
			<NcTextField v-model="form.folderPath" :label="t('Folder path')" :helper-text="t('Relative to your files root, for example Tasks/Acme')" />
			<NcButton @click="browse(form.folderPath)">{{ t('Browse folders') }}</NcButton>
			<ul v-if="folders.length" class="todofolder-form__folders">
				<li v-for="folder in folders" :key="folder.fileid">
					<NcButton variant="tertiary" @click="form.folderPath = folder.path; browse(folder.path)">
						{{ folder.name }}
					</NcButton>
				</li>
			</ul>
		</div>
		<template #actions>
			<NcDialogButton v-if="editing"
				variant="error"
				:label="t('Delete workspace')"
				:callback="deleteWorkspace" />
			<NcDialogButton :label="t('Cancel')" :callback="closeWorkspaceDialog" />
			<NcDialogButton variant="primary"
				:label="t('Save')"
				:disabled="!form.name || !form.folderPath"
				:callback="saveWorkspace" />
		</template>
	</NcDialog>

	<!-- 工作区设置 -->
	<NcDialog :open="settingsDialog"
		:name="t('Workspace settings')"
		size="normal"
		@update:open="settingsDialog = $event">
		<div class="todofolder-form">
			<div class="todofolder-form__field">
				<label class="todofolder-form__label">{{ t('Deck board') }}</label>
				<NcSelect v-model="deckBoard" :options="boardOptions" :clearable="true" :aria-label="t('Deck board')" />
			</div>
			<div class="todofolder-form__field">
				<label class="todofolder-form__label">{{ t('Deck column') }}</label>
				<NcSelect v-model="deckStack" :options="stackOptions" :clearable="true" :aria-label="t('Deck column')" />
			</div>
			<NcCheckboxRadioSwitch v-model="settings.autoDeck" type="switch">
				{{ t('Create cards automatically after analysis') }}
			</NcCheckboxRadioSwitch>
			<NcTextField v-model="settings.scanInterval" type="number" :label="t('Background scan interval (minutes)')" />
			<NcCheckboxRadioSwitch v-model="settings.scanEnabled" type="switch">
				{{ t('Allow scheduled background scans') }}
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcDialogButton :label="t('Cancel')" :callback="closeSettingsDialog" />
			<NcDialogButton :label="t('Prepare board')" :callback="prepareDeckFromDialog" />
			<NcDialogButton variant="primary" :label="t('Save')" :callback="saveSettings" />
		</template>
	</NcDialog>

	<!-- 修正任务 -->
	<NcDialog :open="taskDialog"
		:name="t('Fix task')"
		size="normal"
		@update:open="taskDialog = $event">
		<div v-if="taskForm" class="todofolder-form">
			<NcTextField v-model="taskForm.title" :label="t('Title')" />
			<NcTextField v-model="taskForm.receivedAt" type="date" :label="t('Received date')"
				:helper-text="t('Defaults to the day the folder first appeared; synced to the Deck card start date')" />
			<NcTextField v-model="taskForm.dueDate" type="date" :label="t('Due date')"
				:helper-text="t('Leave empty for no deadline; kept in sync with the Deck card due date')" />
			<div class="todofolder-form__field">
				<label class="todofolder-form__label">{{ t('Priority') }}</label>
				<NcSelect v-model="taskForm.priority" :options="priorityOptions" :aria-label="t('Priority')" />
			</div>
			<NcTextField v-model="taskForm.tags" :label="t('Tags')"
				:helper-text="t('Separate with commas or spaces; saved back as system tags and synced to Deck board labels')" />
			<NcNoteCard v-if="taskForm.summary" type="info">{{ taskForm.summary }}</NcNoteCard>
		</div>
		<template #actions>
			<NcDialogButton :label="t('Cancel')" :callback="closeTaskDialog" />
			<NcDialogButton variant="primary" :label="t('Save')" :callback="saveTask" />
		</template>
	</NcDialog>
	<!-- 完成并归列：空壳先验证挂载位置，内容下一步再填 -->
	<NcDialog :open="finishTask !== null"
		:name="t('Finish and file the card')"
		size="small"
		@update:open="finishTask = null">
		<p>{{ t('The card moves to the category you pick here.') }}</p>
		<template #actions>
			<NcDialogButton :label="t('Cancel')" :callback="() => { finishTask = null }" />
		</template>
	</NcDialog>

	<!-- 确认框：Nextcloud 不用 window.confirm，改成 NcDialog + NcDialogButton -->
	<NcDialog :open="confirmState.open"
		:name="confirmState.title"
		size="small"
		@update:open="confirmState.open = $event">
		<p class="todofolder-confirm__text">{{ confirmState.message }}</p>
		<template #actions>
			<NcDialogButton :label="t('Cancel')" :callback="() => resolveConfirm(false)" />
			<NcDialogButton variant="error" :label="confirmState.confirmLabel" :callback="() => resolveConfirm(true)" />
		</template>
	</NcDialog>
</template>

<script>
import {
	NcActionButton,
	NcAppContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppNavigationList,
	NcAppNavigationNew,
	NcAppNavigationSearch,
	NcButton,
	NcCheckboxRadioSwitch,
	NcCounterBubble,
	NcDialog,
	NcDialogButton,
	NcEmptyContent,
	NcIconSvgWrapper,
	NcListItem,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { getLoggerBuilder } from '@nextcloud/logger'
import axios from '@nextcloud/axios'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const BASE = generateUrl('/apps/todofolder')

async function request(path, options = {}) {
	try {
		const { data } = await axios({ url: BASE + path, ...options })
		return data
	} catch (error) {
		throw new Error(error?.response?.data?.error ?? error?.response?.data?.message ?? error.message)
	}
}

getLoggerBuilder().setApp('todofolder').detectUser().build()

// Inline MDI paths: one less dependency, and the icons match the platform's set.
const icons = {
	folder: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2Z"/></svg>',
	trash: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M19 4h-3.5l-1-1h-5l-1 1H5v2h14M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12Z"/></svg>',
	refresh: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M17.65 6.35A7.96 7.96 0 0 0 12 4a8 8 0 1 0 7.73 10h-2.08A6 6 0 1 1 12 6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35Z"/></svg>',
	sparkles: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="m19 9 1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25L19 9Zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12l-5.5-2.5ZM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25L19 15Z"/></svg>',
	cog: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 15.5A3.5 3.5 0 0 1 8.5 12A3.5 3.5 0 0 1 12 8.5a3.5 3.5 0 0 1 3.5 3.5a3.5 3.5 0 0 1-3.5 3.5m7.43-2.53c.04-.32.07-.64.07-.97 0-.33-.03-.66-.07-1l2.11-1.63c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.31-.61-.22l-2.49 1c-.52-.39-1.06-.73-1.69-.98l-.37-2.65A.506.506 0 0 0 14 2h-4c-.25 0-.46.18-.5.42l-.37 2.65c-.63.25-1.17.59-1.69.98l-2.49-1c-.22-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64L4.57 11c-.04.34-.07.67-.07 1 0 .33.03.65.07.97l-2.11 1.66c-.19.15-.25.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1.01c.52.4 1.06.74 1.69.99l.37 2.65c.04.24.25.42.5.42h4c.25 0 .46-.18.5-.42l.37-2.65c.63-.26 1.17-.59 1.69-.99l2.49 1.01c.22.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.66Z"/></svg>',
	deck: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M3 3h8v10H3V3Zm10 0h8v6h-8V3ZM3 15h8v6H3v-6Zm10-4h8v10h-8V11Z"/></svg>',
	pencil: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M20.71 7.04c.39-.39.39-1.04 0-1.41l-2.34-2.34a.996.996 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83ZM3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25Z"/></svg>',
	check: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="m10 17-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9Z"/></svg>',
	clock: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 20a8 8 0 0 0 8-8a8 8 0 0 0-8-8a8 8 0 0 0-8 8a8 8 0 0 0 8 8m0-18a10 10 0 0 1 10 10a10 10 0 0 1-10 10C6.47 22 2 17.5 2 12A10 10 0 0 1 12 2m.5 5v5.25l4.5 2.67l-.75 1.23L11 13V7h1.5Z"/></svg>',
	warning: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M13 14h-2V9h2m0 9h-2v-2h2M1 21h22L12 2 1 21Z"/></svg>',
	play: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M8 5v14l11-7L8 5Z"/></svg>',
	close: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41Z"/></svg>',
	restore: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M13 3a9 9 0 0 0-9 9H1l4 4 4-4H6a7 7 0 1 1 7 7 7 7 0 0 1-4.9-2.1l-1.4 1.4A9 9 0 1 0 13 3Z"/></svg>',
}

export default {
	name: 'App',
	components: {
		NcActionButton,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationList,
		NcAppNavigationNew,
		NcAppNavigationSearch,
		NcButton,
		NcCheckboxRadioSwitch,
		NcCounterBubble,
		NcDialog,
		NcDialogButton,
		NcEmptyContent,
		NcIconSvgWrapper,
		NcListItem,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			icons,
			navOpen: true,
			workspaces: [],
			tasks: [],
			currentId: null,
			search: '',
			busy: false,
			status: '',
			error: '',
			confirmState: { open: false, title: '', message: '', confirmLabel: '删除', resolve: null },
			finishTask: null,
			workspaceDialog: false,
			settingsDialog: false,
			taskDialog: false,
			editing: false,
			form: { id: null, name: '', folderPath: '' },
			folders: [],
			settings: { autoDeck: false, scanInterval: 15, scanEnabled: true, deckBoardId: null, deckStackId: null },
			taskForm: null,
			boards: [],
			stacks: [],
			priorityOptions: [
				{ id: 0, label: '低' },
				{ id: 1, label: '普通' },
				{ id: 2, label: '高' },
				{ id: 3, label: '紧急' },
			],
		}
	},
	computed: {
		current() {
			return this.workspaces.find((w) => w.id === this.currentId) ?? null
		},
		filteredWorkspaces() {
			const needle = this.search.trim().toLowerCase()
			if (needle === '') {
				return this.workspaces
			}
			return this.workspaces.filter((w) => `${w.name} ${w.folderPath}`.toLowerCase().includes(needle))
		},
		dialogTitle() {
			return this.editing ? this.t('Workspace settings') : this.t('New workspace')
		},
		boardOptions() {
			return this.boards.map((b) => ({ id: b.id, label: b.title }))
		},
		stackOptions() {
			return this.stacks.map((s) => ({ id: s.id, label: s.title }))
		},
		deckBoard: {
			get() {
				const id = this.settings.deckBoardId
				if (id === null || id === undefined) {
					return null
				}
				return this.boardOptions.find((b) => b.id === id) ?? { id, label: `#${id}` }
			},
			set(option) {
				this.settings.deckBoardId = option?.id ?? null
				this.settings.deckStackId = null
				this.stacks = []
				if (this.settings.deckBoardId !== null) {
					this.loadStacks(this.settings.deckBoardId)
				}
			},
		},
		deckStack: {
			get() {
				const id = this.settings.deckStackId
				if (id === null || id === undefined) {
					return null
				}
				return this.stackOptions.find((s) => s.id === id) ?? { id, label: `#${id}` }
			},
			set(option) {
				this.settings.deckStackId = option?.id ?? null
			},
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t(text, values) {
			// English source string -> the user's language, via the bundle main.js registers
			// from the l10n file the server loaded. Falls back to the source string.
			return translate('todofolder', text, values)
		},
		canStart(task) {
			return ['ready', 'done'].includes(task.status)
		},
		canFinish(task) {
			return ['ready', 'in_progress', 'done'].includes(task.status)
		},
		replaceTask(data) {
			const index = this.tasks.findIndex((row) => row.id === data.id)
			if (index >= 0) {
				this.tasks.splice(index, 1, data)
			}
		},
		async lifecycle(task, action) {
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl(`/apps/todofolder/api/tasks/${task.id}/${action}`))
				this.replaceTask(data)
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.busy = false
			}
		},
		openFinish(task) {
			this.finishTask = task
			this.finishStackId = task.suggestedStackId ?? null
		},
		async confirmFinish() {
			if (!this.finishTask) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/todofolder/api/tasks/${this.finishTask.id}/finish`),
					{ stackId: this.finishStackId },
				)
				this.replaceTask(data)
				this.finishTask = null
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.busy = false
			}
		},
		async openFolder(task) {
			try {
				const { data } = await axios.get(generateUrl(`/apps/todofolder/api/tasks/${task.id}/links`))
				if (data.folderUrl) {
					window.open(data.folderUrl, '_blank')
				}
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			}
		},
		async openCard(task) {
			try {
				const { data } = await axios.get(generateUrl(`/apps/todofolder/api/tasks/${task.id}/links`))
				if (data.cardUrl) {
					window.open(data.cardUrl, '_blank')
				}
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			}
		},
		async removeTask(task) {
			if (!window.confirm(this.t('Delete the task record of “{name}”? The folder stays where it is.', { name: task.title || task.folderName }))) {
				return
			}
			this.busy = true
			try {
				await axios.delete(generateUrl(`/apps/todofolder/api/tasks/${task.id}`))
				const index = this.tasks.findIndex((row) => row.id === task.id)
				if (index >= 0) {
					this.tasks.splice(index, 1)
				}
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.busy = false
			}
		},
		statusLabel(status) {
			return {
				pending: this.t('Pending'),
				analyzing: this.t('Analyzing'),
				in_progress: this.t('In progress'),
				ready: this.t('Ready'),
				error: this.t('Error'),
				ignored: this.t('Ignored'),
				done: this.t('Completed'),
			}[status] ?? status
		},
		statusIcon(task) {
			if (task.status === 'error') {
				return icons.warning
			}
			if (task.status === 'ready' || task.status === 'done') {
				return icons.check
			}
			return icons.clock
		},
		async load() {
			try {
				const data = await request('/api/workspaces')
				this.workspaces = data.workspaces
				if (this.currentId === null && this.workspaces.length) {
					this.currentId = this.workspaces[0].id
				}
				await this.loadTasks()
			} catch (error) {
				this.error = error.message
			}
		},
		async loadTasks() {
			if (this.currentId === null) {
				this.tasks = []
				return
			}
			const data = await request(`/api/workspaces/${this.currentId}/tasks`)
			this.tasks = data.tasks
		},
		async selectWorkspace(id) {
			this.currentId = id
			await this.loadTasks()
		},
		closeWorkspaceDialog() {
			this.workspaceDialog = false
			return true
		},
		closeSettingsDialog() {
			this.settingsDialog = false
			return true
		},
		closeTaskDialog() {
			this.taskDialog = false
			return true
		},
		/**
		 * Nextcloud's own apps do not use window.confirm(): destructive actions go through
		 * a NcDialog with an error-typed NcDialogButton.
		 */
		confirm({ title = '', message = '', confirmLabel = '删除' } = {}) {
			return new Promise((resolve) => {
				this.confirmState = { open: true, title, message, confirmLabel, resolve }
			})
		},
		resolveConfirm(value) {
			const resolve = this.confirmState.resolve
			this.confirmState = { open: false, title: '', message: '', confirmLabel: '删除', resolve: null }
			if (resolve) {
				resolve(value)
			}
			return true
		},
		openWorkspaceDialog() {
			this.editing = false
			this.form = { id: null, name: '', folderPath: '' }
			this.folders = []
			this.workspaceDialog = true
		},
		openSettingsDialog() {
			this.settings = {
				autoDeck: this.current.autoDeck,
				scanInterval: this.current.scanInterval,
				scanEnabled: this.current.scanEnabled,
				deckBoardId: this.current.deckBoardId ?? null,
				deckStackId: this.current.deckStackId ?? null,
			}
			this.stacks = []
			this.settingsDialog = true
			this.loadBoards()
		},
		async loadBoards() {
			try {
				const data = await request('/api/deck/boards')
				this.boards = data.boards
			} catch (error) {
				this.boards = []
			}
			if (this.settings.deckBoardId !== null) {
				await this.loadStacks(this.settings.deckBoardId)
			}
		},
		onBoardSelected(option) {
			if (option?.id) {
				this.loadStacks(option.id)
			}
		},
		async loadStacks(boardId) {
			if (!boardId) {
				this.stacks = []
				return
			}
			try {
				const data = await request(`/api/deck/boards/${boardId}/stacks`)
				this.stacks = data.stacks
			} catch (error) {
				this.stacks = []
			}
		},
		async browse(path) {
			try {
				const data = await request(`/api/fs?path=${encodeURIComponent(path ?? '')}`)
				this.folders = data.folders
			} catch (error) {
				this.folders = []
			}
		},
		async saveWorkspace() {
			try {
				if (this.editing) {
					await request(`/api/workspaces/${this.form.id}`, {
						method: 'PUT',
						data: { name: this.form.name, folderPath: this.form.folderPath },
					})
				} else {
					await request('/api/workspaces', {
						method: 'POST',
						data: { name: this.form.name, folderPath: this.form.folderPath },
					})
				}
				this.workspaceDialog = false
				await this.load()
			} catch (error) {
				this.error = error.message
			}
		},
		async deleteWorkspace() {
			const ok = await this.confirm({
				title: this.t('Delete workspace'),
				message: this.t('The task records of “{name}” are deleted too; the files are not touched.', { name: this.form.name }),
				confirmLabel: this.t('Delete'),
			})
			if (!ok) {
				return false
			}
			try {
				await request(`/api/workspaces/${this.form.id}`, { method: 'DELETE' })
				this.workspaceDialog = false
				this.currentId = null
				await this.load()
			} catch (error) {
				this.error = error.message
			}
			return true
		},
		async removeWorkspace(workspace) {
			const ok = await this.confirm({
				title: this.t('Delete workspace'),
				message: this.t('The task records of “{name}” are deleted too; the files are not touched.', { name: workspace.name }),
				confirmLabel: this.t('Delete'),
			})
			if (!ok) {
				return
			}
			try {
				await request(`/api/workspaces/${workspace.id}`, { method: 'DELETE' })
				if (this.currentId === workspace.id) {
					this.currentId = null
				}
				await this.load()
			} catch (error) {
				this.error = error.message
			}
		},
		async saveSettings() {
			try {
				await request(`/api/workspaces/${this.currentId}`, {
					method: 'PUT',
					data: {
						autoDeck: this.settings.autoDeck,
						scanInterval: Number(this.settings.scanInterval),
						scanEnabled: this.settings.scanEnabled,
						deckBoardId: this.settings.deckBoardId,
						deckStackId: this.settings.deckStackId,
					},
				})
				this.settingsDialog = false
				await this.load()
			} catch (error) {
				this.error = error.message
			}
		},
		async prepareDeck() {
			if (this.currentId === null) {
				return false
			}
			this.busy = 'deck'
			this.status = this.t('Preparing the Deck board…')
			try {
				const result = await request(`/api/workspaces/${this.currentId}/decksetup`, { method: 'POST' })
				await this.load()
				const parts = [
					result.createdBoard ? this.t('Created a new board') : this.t('Reused the existing board'),
					result.createdStack ? this.t('Created the “Todo” column') : this.t('Reused the “Todo” column'),
					this.t('{n} cards pushed', { n: result.synced }),
				]
				this.status = `${this.t('Deck ready')}：${parts.join('，')}`
				return true
			} catch (error) {
				this.error = `${this.t('Preparing the board failed')}：${error.message}`
				this.status = ''
				return false
			} finally {
				this.busy = false
			}
		},
		async prepareDeckFromDialog() {
			if (await this.prepareDeck()) {
				this.settingsDialog = false
			}
		},
		async scan() {
			this.busy = 'scan'
			this.status = this.t('Scanning…')
			try {
				const result = await request(`/api/workspaces/${this.currentId}/scan`, { method: 'POST' })
				await this.loadTasks()
				this.status = this.t('Scan finished: {created} added, {updated} updated, {removed} removed', {
					created: result.created,
					updated: result.updated,
					removed: result.removed,
				})
			} catch (error) {
				this.error = error.message
				this.status = ''
			} finally {
				this.busy = false
			}
		},
		async analyzePending() {
			this.busy = 'analyze'
			this.status = this.t('Analyzing… (5 per batch)')
			try {
				const result = await request(`/api/workspaces/${this.currentId}/analyze-pending?max=5`, { method: 'POST' })
				await this.loadTasks()
				this.status = this.t('{n} analyzed', { n: result.analyzed })
					+ (result.failed ? `，${this.t('{n} failed', { n: result.failed })}` : '')
			} catch (error) {
				this.error = error.message
				this.status = ''
			} finally {
				this.busy = false
			}
		},
		async analyze(task) {
			this.busy = true
			this.status = this.t('Analyzing “{name}”…', { name: task.folderName })
			try {
				await request(`/api/tasks/${task.id}/analyze`, { method: 'POST' })
				await this.loadTasks()
				this.status = this.t('Done')
			} catch (error) {
				this.error = error.message
			} finally {
				this.busy = false
			}
		},
		async syncDeck(task) {
			this.busy = true
			try {
				await request(`/api/tasks/${task.id}/deck`, { method: 'POST' })
				await this.loadTasks()
				this.status = this.t('Deck in sync')
			} catch (error) {
				this.error = error.message
			} finally {
				this.busy = false
			}
		},
		applyOverride(task) {
			this.taskForm = {
				id: task.id,
				title: task.title ?? task.folderName,
				receivedAt: task.receivedAt ?? '',
				dueDate: task.dueDate ?? '',
				priority: this.priorityOptions.find((p) => p.id === task.priority) ?? this.priorityOptions[1],
				tags: (task.tags ?? []).map((tag) => tag.tagName ?? tag).join(', '),
				summary: task.summary ?? '',
			}
			this.taskDialog = true
		},
		async saveTask() {
			const tags = this.taskForm.tags
				.split(/[,，、\s]+/)
				.map((name) => name.trim())
				.filter((name) => name !== '')
			try {
				const saved = await request(`/api/tasks/${this.taskForm.id}`, {
					method: 'PUT',
					data: {
						title: this.taskForm.title,
						receivedAt: this.taskForm.receivedAt === '' ? null : this.taskForm.receivedAt,
						dueDate: this.taskForm.dueDate === '' ? null : this.taskForm.dueDate,
						priority: this.taskForm.priority.id,
						tags,
					},
				})
				this.taskDialog = false
				await this.loadTasks()
				this.status = saved.deckSynced ? this.t('Saved, Deck cards updated too') : this.t('Saved')
			} catch (error) {
				this.error = error.message
			}
		},
	},
}
</script>

<style>
/*
 * Both containers are Nextcloud's own components, so they get Nextcloud's own surface
 * tokens. Without this, the app sits on the dashboard wallpaper: the legacy rules key on
 * #app-content, while NcAppContent renders .app-content.
 */
.app-todofolder .app-content,
.app-todofolder .app-navigation {
	background-color: var(--color-main-background, #fff);
}

/* The navigation keeps its own width; the content takes whatever is left. */
.app-todofolder .todofolder-navigation {
	flex: 0 0 300px;
	width: 300px;
	max-width: 300px;
}

.app-todofolder .app-content {
	flex: 1 1 auto;
	min-width: 0;
}
/* Finished in Deck: settled work, not pending work. Unscoped because the title element
   lives inside NcListItem, which has no scope id of this component. */

.todofolder-task--done .list-item-content__name {
	color: var(--color-text-maxcontrast, #666);
	text-decoration: line-through;
	text-decoration-thickness: 1px;
}
</style>

<style scoped>
/* #content is a flex row in 34; the content pane has to take the remaining width. */
.todofolder-content {
	min-width: 0;
}

.todofolder-head {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 12px;
	/* The navigation toggle floats at the top-left of the content (34×34 at x≈8), so the
	   heading starts after it instead of underneath it. */
	padding: 12px 16px 12px 48px;
	flex-wrap: wrap;
}

.todofolder-head__titles h2 {
	margin: 0;
}

.todofolder-head__path {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
	font-size: 13px;
}

.todofolder-head__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.todofolder-body {
	padding: 0 16px 16px;
}

.todofolder-tasks {
	list-style: none;
	margin: 0;
	padding: 0;
}

.todofolder-task__meta {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.todofolder-task__tag {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element-light, #e0f0f8);
	color: var(--color-main-text, #222);
}

.todofolder-task__badge {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-background-dark, #eee);
	color: var(--color-main-text, #222);
}

.todofolder-task__badge--error {
	background: var(--color-error, #c00);
	color: #fff;
}

/* Finished in Deck: it should read as settled work, not as something still pending.
   Explicit colours: the theme's success token is a pale tint that white text cannot sit on. */
.todofolder-task__badge--done {
	background: #2f9e44;
	color: #fff;
	font-weight: 600;
}



.todofolder-task__error {
	color: var(--color-error-text, #c00);
	max-width: 40ch;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.todofolder-confirm__text {
	margin: 0;
	color: var(--color-main-text, #222);
}

.todofolder-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 360px;
}

.todofolder-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.todofolder-form__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.todofolder-form__muted {
	color: var(--color-text-maxcontrast, #666);
	font-size: 12px;
}

.todofolder-form__folders {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 220px;
	overflow: auto;
}
</style>
