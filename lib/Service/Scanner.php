<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCA\TodoFolder\Db\Task;
use OCA\TodoFolder\Db\TaskMapper;
use OCA\TodoFolder\Db\Workspace;
use OCA\TodoFolder\Db\WorkspaceMapper;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Walks a workspace folder and keeps the task table in sync with what is on disk.
 *
 * The rule is the product rule: every subfolder is a task. Folders starting with a dot
 * or an underscore are skipped (editor scratch, "_templates", "_archive"), and files at
 * the workspace root are ignored — the root holds material that belongs to no task.
 */
class Scanner {
	public function __construct(
		private TaskMapper $tasks,
		private WorkspaceMapper $workspaces,
		private WorkspaceService $workspaceService,
		private ContentExtractor $extractor,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{created: int, updated: int, removed: int, tasks: int}
	 */
	public function scan(Workspace $workspace): array {
		$folder = $this->workspaceService->resolveFolder($workspace);
		if ($folder === null) {
			$this->logger->warning('todofolder: workspace folder is gone: ' . $workspace->getFolderPath());
			return ['created' => 0, 'updated' => 0, 'removed' => 0, 'tasks' => 0];
		}

		$now = time();
		$created = 0;
		$updated = 0;
		$present = [];

		foreach ($this->extractor->taskFolders($folder) as $taskFolder) {
			$path = $this->relativePath($workspace, $taskFolder);
			$present[] = $path;
			$hash = $this->folderHash($taskFolder);

			$task = $this->tasks->findByWorkspaceAndPath((int)$workspace->getId(), $path);
			if ($task === null) {
				$task = new Task();
				$task->setWorkspaceId((int)$workspace->getId());
				$task->setFolderPath($path);
				$task->setFolderName($taskFolder->getName());
				$task->setFolderFileid($taskFolder->getId());
				$task->setStatus(Task::STATUS_PENDING);
				$task->setContentHash($hash);
				// The received date is the day the folder first showed up; a human can
				// correct it later, and it is not touched again by a rescan.
				$task->setReceivedAt(date('Y-m-d', $now));
				$task->setCreatedAt($now);
				$task->setUpdatedAt($now);
				$this->tasks->insert($task);
				$created++;
				continue;
			}

			$changed = false;
			if ($task->getFolderFileid() !== $taskFolder->getId()) {
				$task->setFolderFileid($taskFolder->getId());
				$changed = true;
			}
			if ($task->getFolderName() !== $taskFolder->getName()) {
				$task->setFolderName($taskFolder->getName());
				$changed = true;
			}
			if ($task->getContentHash() !== $hash) {
				$task->setContentHash($hash);
				// Content changed: the old answer may be stale, so queue it again — but
				// keep the previous title until the new analysis lands.
				$task->setStatus(Task::STATUS_PENDING);
				$task->setErrorMessage(null);
				$changed = true;
			}
			if ($changed) {
				$task->setUpdatedAt($now);
				$this->tasks->update($task);
				$updated++;
			}
		}

		$removed = 0;
		foreach ($this->tasks->findMissingInWorkspace((int)$workspace->getId(), $present) as $stale) {
			$this->tasks->delete($stale);
			$removed++;
		}

		$this->workspaces->touchScanned((int)$workspace->getId(), $now);

		return [
			'created' => $created,
			'updated' => $updated,
			'removed' => $removed,
			'tasks' => count($present),
		];
	}

	public function folderHash(Folder $folder): string {
		$extract = $this->extractor->extract($folder);
		return $extract['hash'];
	}

	/**
	 * Path relative to the user's files root, e.g. "Tasks/客户A/报价单".
	 */
	private function relativePath(Workspace $workspace, Folder $folder): string {
		$root = '/' . trim($workspace->getFolderPath(), '/');
		$full = $folder->getPath();
		$prefix = '/' . ltrim($full, '/');
		// getPath() on a user folder starts with "/<user>/files"; cut everything up to
		// the workspace folder so the stored path stays portable.
		$needle = '/files/' . ltrim($root, '/');
		$position = strpos($prefix, $needle);
		if ($position === false) {
			return ltrim($folder->getName(), '/');
		}
		return trim(substr($prefix, $position + strlen('/files/')), '/');
	}
}
