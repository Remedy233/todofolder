/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin wrapper over @nextcloud/axios: same-origin calls carry the request token
 * automatically, so state-changing routes keep their CSRF protection.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = generateUrl('/apps/todofolder')

async function request(path, options = {}) {
	try {
		const { data } = await axios({ url: base + path, ...options })
		return data
	} catch (error) {
		const message = error?.response?.data?.error
			?? error?.response?.data?.message
			?? error.message
		throw new Error(message)
	}
}

export const api = {
	workspaces: () => request('/api/workspaces'),
	createWorkspace: (name, folderPath) => request('/api/workspaces', { method: 'POST', data: { name, folderPath } }),
	updateWorkspace: (id, fields) => request(`/api/workspaces/${id}`, { method: 'PUT', data: fields }),
	deleteWorkspace: (id) => request(`/api/workspaces/${id}`, { method: 'DELETE' }),
	browse: (path) => request(`/api/fs?path=${encodeURIComponent(path ?? '')}`),
	tasks: (id) => request(`/api/workspaces/${id}/tasks`),
	scan: (id) => request(`/api/workspaces/${id}/scan`, { method: 'POST' }),
	analyze: (taskId) => request(`/api/tasks/${taskId}/analyze`, { method: 'POST' }),
	analyzePending: (id, limit = 5) => request(`/api/workspaces/${id}/analyze-pending?limit=${limit}`, { method: 'POST' }),
	updateTask: (id, fields) => request(`/api/tasks/${id}`, { method: 'PUT', data: fields }),
	syncDeck: (id) => request(`/api/tasks/${id}/deck`, { method: 'POST' }),
	boards: () => request('/api/deck/boards'),
	stacks: (boardId) => request(`/api/deck/boards/${boardId}/stacks`),
}
