<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCA\TodoFolder\Db\Task;
use OCA\TodoFolder\Db\TaskMapper;
use OCA\TodoFolder\Db\TaskTagMapper;
use OCA\TodoFolder\Db\Workspace;
use OCA\TodoFolder\Db\WorkspaceMapper;
use OCA\TodoFolder\Db\WorkspaceShare;
use OCA\TodoFolder\Db\WorkspaceShareMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use OCP\IL10N;

/**
 * Workspace lifecycle plus the permission rules every controller and job goes through.
 *
 * A workspace points at a folder inside its owner's files. A share grants a group read
 * or write access to the *records*; the folder itself is always resolved in the
 * owner's tree, because that is the only context where the path is stable.
 */
class WorkspaceService {
	public function __construct(
		private WorkspaceMapper $mapper,
		private WorkspaceShareMapper $shareMapper,
		private TaskMapper $tasks,
		private TaskTagMapper $taskTags,
		private TagService $tags,
		private IRootFolder $rootFolder,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
	}

	/**
	 * Everything a user may see, annotated with their permission.
	 *
	 * @return array<int, array{workspace: Workspace, permission: int, shared: bool}>
	 */
	public function findAccessible(string $userId): array {
		$out = [];
		foreach ($this->mapper->findForUser($userId) as $workspace) {
			$out[(int)$workspace->getId()] = [
				'workspace' => $workspace,
				'permission' => WorkspaceShare::PERM_WRITE,
				'shared' => false,
			];
		}

		$groups = $this->groupIdsOf($userId);
		foreach ($this->shareMapper->findByGroups($groups) as $share) {
			$wsId = $share->getWorkspaceId();
			if (isset($out[$wsId])) {
				continue;
			}
			try {
				$workspace = $this->mapper->find($wsId);
			} catch (\Throwable $e) {
				$this->logger->warning('todofolder: share points at a missing workspace ' . $wsId, ['exception' => $e]);
				continue;
			}
			$out[$wsId] = [
				'workspace' => $workspace,
				'permission' => $share->getPermission(),
				'shared' => true,
			];
		}

		uasort($out, static fn (array $a, array $b): int => strcmp($a['workspace']->getName(), $b['workspace']->getName()));
		return $out;
	}

	/**
	 * @throws NotFoundException when the workspace does not exist or is not visible
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function getForUser(int $workspaceId, string $userId, bool $needWrite = false): Workspace {
		$workspace = $this->mapper->find($workspaceId);
		if ($workspace->getUserId() === $userId) {
			return $workspace;
		}
		$permission = $this->sharePermission($workspaceId, $userId);
		if ($permission === 0) {
			throw new NotFoundException($this->l10n->t('Workspace %1$s is not visible to %2$s', [(string)$workspaceId, $userId]));
		}
		if ($needWrite && $permission < WorkspaceShare::PERM_WRITE) {
			throw new NotFoundException($this->l10n->t('Workspace %1$s is read-only for %2$s', [(string)$workspaceId, $userId]));
		}
		return $workspace;
	}

	public function sharePermission(int $workspaceId, string $userId): int {
		$best = 0;
		foreach ($this->shareMapper->findByGroups($this->groupIdsOf($userId)) as $share) {
			if ($share->getWorkspaceId() === $workspaceId && $share->getPermission() > $best) {
				$best = $share->getPermission();
			}
		}
		return $best;
	}

	/**
	 * Group ids of a user, or none when the account cannot be resolved.
	 *
	 * IUserManager is the authoritative lookup: IGroupManager::get() returns whatever the
	 * group backends happen to have cached and is null for a user that exists.
	 *
	 * @return string[]
	 */
	private function groupIdsOf(string $userId): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->logger->warning('todofolder: unknown user ' . $userId . ' when resolving groups');
			return [];
		}
		return $this->groupManager->getUserGroupIds($user);
	}

	/**
	 * @throws \InvalidArgumentException on bad input
	 */
	public function create(string $userId, string $name, string $folderPath): Workspace {
		$name = trim($name);
		$folderPath = trim($folderPath, '/');
		if ($name === '') {
			throw new \InvalidArgumentException('name is required');
		}
		if ($folderPath === '') {
			throw new \InvalidArgumentException('folderPath is required');
		}
		if ($this->mapper->findByUserAndPath($userId, $folderPath) !== null) {
			throw new \InvalidArgumentException('this folder already is a workspace');
		}
		$folder = $this->resolveFolderPath($userId, $folderPath);
		if ($folder === null) {
			throw new \InvalidArgumentException('folder not found in your files: ' . $folderPath);
		}

		$now = time();
		$workspace = new Workspace();
		$workspace->setUserId($userId);
		$workspace->setName($name);
		$workspace->setFolderPath($folderPath);
		$workspace->setFolderFileid($folder->getId());
		$workspace->setAutoDeck(false);
		$workspace->setScanEnabled(true);
		$workspace->setScanInterval(15);
		$workspace->setCreatedAt($now);
		$workspace->setUpdatedAt($now);
		return $this->mapper->insert($workspace);
	}

	public function update(Workspace $workspace, array $fields): Workspace {
		if (isset($fields['name'])) {
			$name = trim((string)$fields['name']);
			if ($name === '') {
				throw new \InvalidArgumentException('name must not be empty');
			}
			$workspace->setName($name);
		}
		if (array_key_exists('folderPath', $fields)) {
			$path = trim((string)$fields['folderPath'], '/');
			$folder = $this->resolveFolderPath($workspace->getUserId(), $path);
			if ($folder === null) {
				throw new \InvalidArgumentException('folder not found in the owner\'s files: ' . $path);
			}
			$workspace->setFolderPath($path);
			$workspace->setFolderFileid($folder->getId());
		}
		if (array_key_exists('deckBoardId', $fields)) {
			$workspace->setDeckBoardId($fields['deckBoardId'] === null ? null : (int)$fields['deckBoardId']);
		}
		if (array_key_exists('deckStackId', $fields)) {
			$workspace->setDeckStackId($fields['deckStackId'] === null ? null : (int)$fields['deckStackId']);
		}
		if (array_key_exists('autoDeck', $fields)) {
			$workspace->setAutoDeck((bool)$fields['autoDeck']);
		}
		if (array_key_exists('scanEnabled', $fields)) {
			$workspace->setScanEnabled((bool)$fields['scanEnabled']);
		}
		if (array_key_exists('scanInterval', $fields)) {
			$workspace->setScanInterval(max(1, (int)$fields['scanInterval']));
		}
		if (array_key_exists('aiExtraPrompt', $fields)) {
			$prompt = trim((string)$fields['aiExtraPrompt']);
			$workspace->setAiExtraPrompt($prompt === '' ? null : mb_substr($prompt, 0, 2000));
		}
		if (array_key_exists('deckInboxStackId', $fields)) {
			$inbox = $fields['deckInboxStackId'];
			$workspace->setDeckInboxStackId($inbox === null || $inbox === '' ? null : (int)$inbox);
		}
		if (array_key_exists('deckClassify', $fields)) {
			$workspace->setDeckClassify((bool)$fields['deckClassify']);
		}
		if (array_key_exists('deckClassifyTiming', $fields)) {
			$timing = (string)$fields['deckClassifyTiming'];
			if (!in_array($timing, ['on_done', 'on_create', 'manual'], true)) {
				throw new \InvalidArgumentException('deckClassifyTiming must be on_done, on_create or manual');
			}
			$workspace->setDeckClassifyTiming($timing);
		}
		if (array_key_exists('deckDoneTarget', $fields)) {
			$target = (string)$fields['deckDoneTarget'];
			if (!in_array($target, ['category', 'fixed', 'in_place'], true)) {
				throw new \InvalidArgumentException('deckDoneTarget must be category, fixed or in_place');
			}
			$workspace->setDeckDoneTarget($target);
		}
		if (array_key_exists('deckCategoryStacks', $fields)) {
			$stacks = $fields['deckCategoryStacks'];
			if ($stacks === null || $stacks === '') {
				$workspace->setDeckCategoryStacks(null);
			} elseif (is_array($stacks)) {
				$ids = array_values(array_map('intval', array_filter($stacks, static fn ($v): bool => is_numeric($v))));
				$workspace->setDeckCategoryStacks($ids === [] ? null : json_encode($ids, JSON_THROW_ON_ERROR));
			} else {
				throw new \InvalidArgumentException('deckCategoryStacks must be a list of column ids');
			}
		}
		if (array_key_exists('deckStateMap', $fields)) {
			$map = $fields['deckStateMap'];
			if ($map === null || $map === '') {
				$workspace->setDeckStateMap(null);
			} elseif (is_array($map)) {
				$clean = [];
				foreach ($map as $stackId => $state) {
					if (!is_numeric($stackId)) {
						continue;
					}
					if (!in_array((string)$state, ['todo', 'in_progress', 'done', 'archive'], true)) {
						throw new \InvalidArgumentException('deckStateMap values must be todo, in_progress, done or archive');
					}
					$clean[(string)(int)$stackId] = (string)$state;
				}
				$workspace->setDeckStateMap($clean === [] ? null : json_encode($clean, JSON_THROW_ON_ERROR));
			} else {
				throw new \InvalidArgumentException('deckStateMap must be a map of column id to state');
			}
		}
		if (array_key_exists('deckCompletionStackId', $fields)) {
			$done = $fields['deckCompletionStackId'];
			$workspace->setDeckCompletionStackId($done === null || $done === '' ? null : (int)$done);
		}
		if (array_key_exists('deckReconcileInterval', $fields)) {
			$workspace->setDeckReconcileInterval(max(1, (int)$fields['deckReconcileInterval']));
		}
		$workspace->setUpdatedAt(time());
		return $this->mapper->update($workspace);
	}

	/**
	 * Store the Deck binding decided by "一键准备看板" or the settings dialog.
	 */
	public function bindDeck(Workspace $workspace, int $boardId, int $stackId, bool $autoDeck = true): Workspace {
		$workspace->setDeckBoardId($boardId);
		$workspace->setDeckStackId($stackId);
		$workspace->setAutoDeck($autoDeck);
		$workspace->setUpdatedAt(time());
		return $this->mapper->update($workspace);
	}

	/**
	 * Delete a workspace and everything it owns.
	 *
	 * Not just the row: the tasks, their tag rows, and the system tags that were assigned
	 * to the folders would otherwise linger — Files would keep showing tags for tasks that
	 * no longer exist anywhere in the UI.
	 */
	public function delete(Workspace $workspace): void {
		$workspaceId = (int)$workspace->getId();
		foreach ($this->tasks->findByWorkspace($workspaceId, null, 10000) as $task) {
			$this->removeTask($task);
		}
		$this->tasks->deleteByWorkspace($workspaceId);
		$this->shareMapper->deleteByWorkspace($workspaceId);
		$this->mapper->delete($workspace);
	}

	public function removeTask(Task $task): void {
		$this->tags->clearAllTags($task);
		$this->taskTags->deleteByTask((int)$task->getId());
	}

	/**
	 * The workspace's folder, resolved in the owner's tree.
	 */
	public function resolveFolder(Workspace $workspace): ?Folder {
		return $this->resolveFolderPath($workspace->getUserId(), $workspace->getFolderPath());
	}

	/**
	 * A task's folder. Task rows store paths relative to the user's files root (the same
	 * coordinate system as the workspace), so the workspace prefix is removed here before
	 * descending from the workspace folder — otherwise every lookup doubles the prefix.
	 */
	public function resolveTaskFolder(Workspace $workspace, string $storedPath): ?Folder {
		$folder = $this->resolveFolder($workspace);
		if ($folder === null) {
			return null;
		}
		$prefix = trim($workspace->getFolderPath(), '/') . '/';
		$relative = str_starts_with($storedPath, $prefix)
			? substr($storedPath, strlen($prefix))
			: ltrim($storedPath, '/');
		if ($relative === '') {
			return null;
		}
		try {
			$node = $folder->get($relative);
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: cannot resolve task folder ' . $storedPath, ['exception' => $e]);
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	public function resolveFolderPath(string $userId, string $path): ?Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->error('todofolder: cannot open the files root of ' . $userId, ['exception' => $e]);
			return null;
		}
		try {
			$node = $path === '' ? $userFolder : $userFolder->get($path);
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: cannot resolve ' . $path, ['exception' => $e]);
			return null;
		}
		return $node instanceof Folder ? $node : null;
	}

	/**
	 * Subfolders of $path, for the folder picker. Files are not returned: a workspace
	 * is always a directory.
	 *
	 * @return array<int, array{name: string, path: string, fileid: int}>
	 */
	public function browse(string $userId, string $path): array {
		$folder = $this->resolveFolderPath($userId, trim($path, '/'));
		if ($folder === null) {
			return [];
		}
		$prefix = '/' . $userId . '/files/';
		$out = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			$full = $node->getPath();
			$relative = str_starts_with($full, $prefix) ? substr($full, strlen($prefix)) : $node->getName();
			$out[] = [
				'name' => $node->getName(),
				'path' => $relative,
				'fileid' => $node->getId(),
			];
		}
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		return $out;
	}
}
