<!--
  SPDX-FileCopyrightText: 2026 TodoFolder contributors
  SPDX-License-Identifier: AGPL-3.0-or-later

  Official Nextcloud layout: NcAppNavigation on the left, NcAppContent on the right, and
  the platform's own list/empty-state/dialog components for everything else. The Vue app
  mounts straight into the server's #content element, so these two are its direct
  children — which is what the legacy (34) and the newer layout both expect.
-->
<template>
	<NcAppNavigation v-model:open="navOpen" class="todofolder-navigation" :aria-label="t('工作区')">
		<template #list>
			<NcAppNavigationNew :text="t('新建工作区')" @click="openWorkspaceDialog()" />
			<NcAppNavigationSearch v-model="search" :label="t('搜索工作区')" />
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
							{{ t('删除工作区') }}
						</NcActionButton>
						<NcActionButton @click.stop="prepareDeck">
							<template #icon>
								<NcIconSvgWrapper :svg="icons.deck" />
							</template>
							{{ t('一键准备看板') }}
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
					{{ current ? current.name : t('待办夹') }}
				</h2>
				<p v-if="current" class="todofolder-head__path">{{ current.folderPath }}</p>
			</div>
			<div v-if="current" class="todofolder-head__actions">
				<NcButton :disabled="busy" @click="scan">
					<template #icon><NcLoadingIcon v-if="busy === 'scan'" :size="16" /><NcIconSvgWrapper v-else :svg="icons.refresh" /></template>
					{{ t('扫描目录') }}
				</NcButton>
				<NcButton type="primary" :disabled="busy" @click="analyzePending">
					<template #icon><NcLoadingIcon v-if="busy === 'analyze'" :size="16" /><NcIconSvgWrapper v-else :svg="icons.sparkles" /></template>
					{{ t('分析未完成') }}
				</NcButton>
				<NcButton :disabled="busy" @click="openSettingsDialog()">
					<template #icon><NcIconSvgWrapper :svg="icons.cog" /></template>
					{{ t('工作区设置') }}
				</NcButton>
			</div>
		</div>

		<NcEmptyContent v-if="!current"
			:name="t('还没有工作区')"
			:description="t('新建一个工作区，指向存放任务的目录：它下面的每个子文件夹就是一个任务。')">
			<template #icon>
				<NcIconSvgWrapper :svg="icons.folder" />
			</template>
			<template #action>
				<NcButton type="primary" @click="openWorkspaceDialog()">{{ t('新建工作区') }}</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="todofolder-body">
			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
			<NcNoteCard v-if="status" type="info">{{ status }}</NcNoteCard>

			<NcNoteCard v-if="!current.deckStackId" type="warning">
				{{ t('这个工作区还没绑定 Deck 看板，分析结果不会自动变成卡片。') }}
				<NcButton type="primary" :disabled="busy" @click="prepareDeck">
					<template #icon><NcLoadingIcon v-if="busy === 'deck'" :size="16" /></template>
					{{ t('一键准备看板') }}
				</NcButton>
			</NcNoteCard>

			<NcEmptyContent v-if="tasks.length === 0"
				:name="t('还没有任务')"
				:description="t('点「扫描目录」把子文件夹同步成任务，再点「分析未完成」让模型补上标题、日期与标签。')">
				<template #icon>
					<NcIconSvgWrapper :svg="icons.check" />
				</template>
			</NcEmptyContent>

			<ul v-else class="todofolder-tasks">
				<NcListItem v-for="task in tasks"
					:key="task.id"
					:class="{ 'todofolder-task--done': task.status === 'done' }"
					:name="task.title || task.folderName"
					:details="task.dueDate ? t('截止 {date}', { date: task.dueDate }) : ''"
					:force-display-actions="true"
					@click="applyOverride(task)">
					<template #icon>
						<NcIconSvgWrapper :svg="statusIcon(task)" />
					</template>
					<template #subname>
						<span class="todofolder-task__meta">
							<span v-if="task.receivedAt">{{ t('收到 {date}', { date: task.receivedAt }) }}</span>
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
							{{ t('分析') }}
						</NcActionButton>
						<NcActionButton :disabled="busy" @click.stop="applyOverride(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.pencil" /></template>
							{{ t('编辑') }}
						</NcActionButton>
						<NcActionButton v-if="current.deckStackId" :disabled="busy" @click.stop="syncDeck(task)">
							<template #icon><NcIconSvgWrapper :svg="icons.deck" /></template>
							{{ task.deckCardId ? t('更新 Deck 卡') : t('建 Deck 卡') }}
						</NcActionButton>
					</template>
				</NcListItem>
			</ul>
		</div>
	</NcAppContent>

	<!-- 新建 / 编辑工作区 -->
	<NcDialog :open="workspaceDialog" :name="dialogTitle" size="normal" @update:open="workspaceDialog = $event">
		<div class="todofolder-form">
			<NcTextField v-model="form.name" :label="t('工作区名称')" />
			<NcTextField v-model="form.folderPath" :label="t('目录路径')" :helper-text="t('相对你的文件根目录，例如 Tasks/客户A')" />
			<NcButton @click="browse(form.folderPath)">{{ t('浏览子目录') }}</NcButton>
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
				:label="t('删除工作区')"
				:callback="deleteWorkspace" />
			<NcDialogButton :label="t('取消')" :callback="closeWorkspaceDialog" />
			<NcDialogButton variant="primary"
				:label="t('保存')"
				:disabled="!form.name || !form.folderPath"
				:callback="saveWorkspace" />
		</template>
	</NcDialog>

	<!-- 工作区设置 -->
	<NcDialog :open="settingsDialog"
		:name="t('工作区设置')"
		size="normal"
		@update:open="settingsDialog = $event">
		<div class="todofolder-form">
			<div class="todofolder-form__field">
				<label class="todofolder-form__label">{{ t('Deck 看板') }}</label>
				<NcSelect v-model="deckBoard" :options="boardOptions" :clearable="true" :aria-label="t('Deck 看板')" />
			</div>
			<div class="todofolder-form__field">
				<label class="todofolder-form__label">{{ t('Deck 列') }}</label>
				<NcSelect v-model="deckStack" :options="stackOptions" :clearable="true" :aria-label="t('Deck 列')" />
			</div>
			<NcCheckboxRadioSwitch v-model="settings.autoDeck" type="switch">
				{{ t('分析完成后自动建卡') }}
			</NcCheckboxRadioSwitch>
			<NcTextField v-model="settings.scanInterval" type="number" :label="t('后台扫描间隔（分钟）')" />
			<NcCheckboxRadioSwitch v-model="settings.scanEnabled" type="switch">
				{{ t('允许后台定时扫描') }}
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcDialogButton :label="t('取消')" :callback="closeSettingsDialog" />
			<NcDialogButton :label="t('一键准备看板')" :callback="prepareDeckFromDialog" />
			<NcDialogButton variant="primary" :label="t('保存')" :callback="saveSettings" />
		</template>
	</NcDialog>

	<!-- 修正任务 -->
	<NcDialog :open="taskDialog"
		:name="t('修正任务')"
		size="normal"
		@update:open="taskDialog = $event">
		<div v-if="taskForm" class="todofolder-form">
			<NcTextField v-model="taskForm.title" :label="t('标题')" />
			<NcTextField v-model="taskForm.receivedAt" type="date" :label="t('收到日期')"
				:helper-text="t('默认是文件夹第一次出现的那天；会同步成 Deck 卡片的开始日期')" />
			<NcTextField v-model="taskForm.dueDate" type="date" :label="t('截止日期')"
				:helper-text="t('留空表示没有期限；与 Deck 卡片的截止日期互通')" />
			<div class="todofolder-form__field">
				<label class="todofolder-form__label">{{ t('优先级') }}</label>
				<NcSelect v-model="taskForm.priority" :options="priorityOptions" :aria-label="t('优先级')" />
			</div>
			<NcTextField v-model="taskForm.tags" :label="t('标签')"
				:helper-text="t('用逗号或空格分隔；保存后写回系统标签并同步成 Deck 看板标签')" />
			<NcNoteCard v-if="taskForm.summary" type="info">{{ taskForm.summary }}</NcNoteCard>
		</div>
		<template #actions>
			<NcDialogButton :label="t('取消')" :callback="closeTaskDialog" />
			<NcDialogButton variant="primary" :label="t('保存')" :callback="saveTask" />
		</template>
	</NcDialog>

	<!-- 确认框：Nextcloud 不用 window.confirm，改成 NcDialog + NcDialogButton -->
	<NcDialog :open="confirmState.open"
		:name="confirmState.title"
		size="small"
		@update:open="confirmState.open = $event">
		<p class="todofolder-confirm__text">{{ confirmState.message }}</p>
		<template #actions>
			<NcDialogButton :label="t('取消')" :callback="() => resolveConfirm(false)" />
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
			return this.editing ? this.t('工作区设置') : this.t('新建工作区')
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
			// The build injects appName/appVersion; translations come from Nextcloud's
			// l10n in a later step, so plain substitution is enough here.
			return values
				? Object.entries(values).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
				: text
		},
		statusLabel(status) {
			return {
				pending: this.t('待分析'),
				analyzing: this.t('分析中'),
				ready: this.t('完成'),
				error: this.t('出错'),
				ignored: this.t('忽略'),
				done: this.t('已完成'),
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
				title: this.t('删除工作区'),
				message: this.t('「{name}」的任务记录会一起删除，文件不受影响。', { name: this.form.name }),
				confirmLabel: this.t('删除'),
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
				title: this.t('删除工作区'),
				message: this.t('「{name}」的任务记录会一起删除，文件不受影响。', { name: workspace.name }),
				confirmLabel: this.t('删除'),
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
			this.status = this.t('正在准备 Deck 看板…')
			try {
				const result = await request(`/api/workspaces/${this.currentId}/decksetup`, { method: 'POST' })
				await this.load()
				const parts = [
					result.createdBoard ? this.t('已新建看板') : this.t('已复用看板'),
					result.createdStack ? this.t('新建了「待办」列') : this.t('复用「待办」列'),
					this.t('推送 {n} 张卡', { n: result.synced }),
				]
				this.status = `${this.t('Deck 就绪')}：${parts.join('，')}`
				return true
			} catch (error) {
				this.error = `${this.t('准备看板失败')}：${error.message}`
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
			this.status = this.t('扫描中…')
			try {
				const result = await request(`/api/workspaces/${this.currentId}/scan`, { method: 'POST' })
				await this.loadTasks()
				this.status = this.t('扫描完成：新增 {created}，更新 {updated}，移除 {removed}', {
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
			this.status = this.t('分析中…（每批 5 个）')
			try {
				const result = await request(`/api/workspaces/${this.currentId}/analyze-pending?max=5`, { method: 'POST' })
				await this.loadTasks()
				this.status = this.t('已分析 {n} 个', { n: result.analyzed })
					+ (result.failed ? `，${this.t('失败 {n} 个', { n: result.failed })}` : '')
			} catch (error) {
				this.error = error.message
				this.status = ''
			} finally {
				this.busy = false
			}
		},
		async analyze(task) {
			this.busy = true
			this.status = this.t('分析「{name}」…', { name: task.folderName })
			try {
				await request(`/api/tasks/${task.id}/analyze`, { method: 'POST' })
				await this.loadTasks()
				this.status = this.t('完成')
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
				this.status = this.t('Deck 已同步')
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
				this.status = saved.deckSynced ? this.t('已保存，Deck 卡片也已更新') : this.t('已保存')
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
