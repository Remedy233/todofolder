<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Controller;

use OCA\TodoFolder\AppInfo\Application;
use OCA\TodoFolder\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Workspace CRUD. Every route here is user-scoped through WorkspaceService, which
 * refuses a workspace the caller may not see, so an id alone never grants access.
 */
class WorkspaceController extends Controller {
	public function __construct(
		IRequest $request,
		private WorkspaceService $workspaces,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/workspaces')]
	public function index(): JSONResponse {
		$out = [];
		foreach ($this->workspaces->findAccessible((string)$this->userId) as $entry) {
			$out[] = [
				...$entry['workspace']->jsonSerialize(),
				'permission' => $entry['permission'],
				'shared' => $entry['shared'],
			];
		}
		return new JSONResponse(['workspaces' => $out]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/workspaces')]
	public function create(string $name = '', string $folderPath = ''): JSONResponse {
		try {
			$workspace = $this->workspaces->create((string)$this->userId, $name, $folderPath);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($workspace->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * Partial update: only the keys actually present in the body are applied, and an
	 * explicit null clears a Deck binding. Reading the raw parameters is what makes that
	 * distinction possible — a typed method signature would turn "absent" into null.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/workspaces/{id}')]
	public function update(int $id, array $fields = []): JSONResponse {
		$raw = $this->request->getParams();
		$allowed = ['name', 'folderPath', 'deckBoardId', 'deckStackId', 'autoDeck', 'scanEnabled', 'scanInterval', 'aiExtraPrompt'];
		$fields = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $raw)) {
				$fields[$key] = $raw[$key];
			}
		}
		if ($fields === []) {
			return new JSONResponse(['error' => '没有要更新的字段'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$workspace = $this->workspaces->getForUser($id, (string)$this->userId, true);
			// A share grants work on the tasks, not a say in where the workspace points:
			// renaming or moving it changes the meaning for everyone else.
			$structural = array_intersect(array_keys($fields), ['name', 'folderPath']);
			if ($structural !== [] && $workspace->getUserId() !== (string)$this->userId) {
				return new JSONResponse(['error' => '只有工作区所有者可以改名或更换目录'], Http::STATUS_FORBIDDEN);
			}
			$workspace = $this->workspaces->update($workspace, $fields);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable) {
			return new JSONResponse(['error' => '工作区不存在'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($workspace->jsonSerialize());
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/workspaces/{id}')]
	public function destroy(int $id): JSONResponse {
		try {
			$workspace = $this->workspaces->getForUser($id, (string)$this->userId, true);
			if ($workspace->getUserId() !== $this->userId) {
				// Only the owner may drop a workspace: a share grants task access, not deletion.
				return new JSONResponse(['error' => '只有工作区所有者可以删除工作区'], Http::STATUS_FORBIDDEN);
			}
			$this->workspaces->delete($workspace);
		} catch (\Throwable) {
			return new JSONResponse(['error' => '工作区不存在'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse(['deleted' => $id]);
	}

	/**
	 * Folder picker: subfolders of a path in the caller's own files.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/fs')]
	public function browse(string $path = ''): JSONResponse {
		return new JSONResponse(['folders' => $this->workspaces->browse((string)$this->userId, $path)]);
	}
}
