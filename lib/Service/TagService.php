<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCA\TodoFolder\Db\Task;
use OCA\TodoFolder\Db\TaskTag;
use OCA\TodoFolder\Db\TaskTagMapper;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Writes the AI's tags where a human can actually use them: Nextcloud system tags on
 * the task folder, which means Files shows them and can filter by them.
 *
 * Two records are kept per tag: a row in todofolder_task_tags (so the app can re-read and
 * re-write its own decisions) and an assignment in oc_systemtag_object_mapping on the
 * folder's fileid. Tags are created user-visible and user-assignable; an existing tag
 * with the same name is reused, never duplicated.
 *
 * The API here is the Nextcloud 33+ one: getTag(name, visible, assignable) that throws
 * TagNotFoundException, not the older getTagsByName() lookup.
 */
class TagService {
	private const OBJECT_TYPE = 'files';
	private const MAX_TAGS = 8;

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private TaskTagMapper $taskTags,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Replace the AI tags of a task — unless a human has taken over the tag list.
	 *
	 * Human edits win: once a task carries SOURCE_USER tags, re-analysing leaves them
	 * alone instead of silently overwriting what someone typed.
	 *
	 * @param mixed[] $names
	 * @return string[] the tag names that ended up attached
	 */
	public function replaceAiTags(Task $task, array $names, ?string $ownerId = null): array {
		if ($this->hasUserTags((int)$task->getId())) {
			return $this->namesForTask((int)$task->getId());
		}
		$this->clearTags($task, TaskTag::SOURCE_AI);
		return $this->add($task, $names, TaskTag::SOURCE_AI, $ownerId);
	}

	/**
	 * The human's tag list for a task: replaces everything, including earlier AI tags,
	 * and the result is marked as user-owned so analysis stops touching it.
	 *
	 * @param mixed[] $names
	 * @return string[]
	 */
	public function setUserTags(Task $task, array $names, ?string $ownerId = null): array {
		$this->clearTags($task, null);
		return $this->add($task, $names, TaskTag::SOURCE_USER, $ownerId);
	}

	/**
	 * Remove every tag of a task and unassign the matching system tags from its folder.
	 * Used when a task or a whole workspace is deleted.
	 */
	public function clearAllTags(Task $task): void {
		$this->clearTags($task, null);
	}

	public function hasUserTags(int $taskId): bool {
		foreach ($this->taskTags->findByTask($taskId) as $row) {
			if ($row->getSource() === TaskTag::SOURCE_USER) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drop tag rows and unassign the matching system tags from the folder.
	 *
	 * Deleting rows alone is not enough: the previous run also assigned those tags to the
	 * folder, and Files would keep showing them forever.
	 *
	 * @param string|null $source null removes every row, otherwise only that source
	 */
	private function clearTags(Task $task, ?string $source): void {
		$stale = [];
		foreach ($this->taskTags->findByTask((int)$task->getId()) as $row) {
			if ($source !== null && $row->getSource() !== $source) {
				continue;
			}
			$systemtagId = $row->getSystemtagId();
			if ($systemtagId !== null) {
				$stale[] = (string)$systemtagId;
			}
			$this->taskTags->delete($row);
		}

		$fileid = $task->getFolderFileid();
		if ($stale === [] || $fileid === null || $fileid <= 0) {
			return;
		}
		try {
			$this->tagMapper->unassignTags((string)$fileid, self::OBJECT_TYPE, $stale);
		} catch (\Throwable $e) {
			// A tag that is already gone is not a failure worth stopping anything.
			$this->logger->debug('todofolder: cannot unassign stale tags from ' . $fileid . ': ' . $e->getMessage());
		}
	}

	/**
	 * @param mixed[] $names
	 * @return string[]
	 */
	public function add(Task $task, array $names, string $source, ?string $ownerId = null): array {
		$clean = [];
		foreach ($names as $name) {
			if (!is_scalar($name)) {
				continue;
			}
			$name = trim(preg_replace('/\s+/u', ' ', (string)$name) ?? '');
			if ($name === '' || mb_strlen($name) > 64) {
				continue;
			}
			$clean[mb_strtolower($name)] = $name;
			if (count($clean) >= self::MAX_TAGS) {
				break;
			}
		}
		if ($clean === []) {
			return [];
		}

		$user = $ownerId === null ? null : $this->userManager->get($ownerId);
		$fileid = $task->getFolderFileid();
		$applied = [];
		foreach ($clean as $name) {
			$tag = $this->ensureTag($name, $user);
			if ($tag === null) {
				continue;
			}
			$applied[] = $name;

			try {
				$this->taskTags->insert($this->newRow((int)$task->getId(), $name, (int)$tag->getId(), $source));
			} catch (\Throwable) {
				// Unique (task_id, tag_name): an existing row is fine, not an error.
				$this->logger->debug('todofolder: tag row already present for ' . $name);
			}

			if ($fileid !== null && $fileid > 0) {
				try {
					$this->tagMapper->assignTags((string)$fileid, self::OBJECT_TYPE, [$tag->getId()]);
				} catch (\Throwable $e) {
					$this->logger->warning('todofolder: cannot assign tag ' . $name . ' to ' . $fileid . ': ' . $e->getMessage());
				}
			}
		}
		return $applied;
	}

	private function ensureTag(string $name, ?IUser $user): ?ISystemTag {
		try {
			return $this->tagManager->getTag($name, true, true);
		} catch (TagNotFoundException) {
			// Not there yet: create it below.
		} catch (\Throwable $e) {
			$this->logger->debug('todofolder: tag lookup failed for ' . $name . ': ' . $e->getMessage());
		}

		try {
			return $this->tagManager->createTag($name, true, true, $user);
		} catch (TagAlreadyExistsException) {
			// Raced with another request, or created by a parallel analysis: read it back.
			try {
				return $this->tagManager->getTag($name, true, true);
			} catch (\Throwable) {
				return null;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: cannot create tag ' . $name . ': ' . $e->getMessage());
			return null;
		}
	}

	private function newRow(int $taskId, string $name, int $systemtagId, string $source): TaskTag {
		$row = new TaskTag();
		$row->setTaskId($taskId);
		$row->setTagName($name);
		$row->setSystemtagId($systemtagId);
		$row->setSource($source);
		return $row;
	}

	/**
	 * Tags currently attached to a task, as plain names.
	 *
	 * @return string[]
	 */
	public function namesForTask(int $taskId): array {
		return array_map(static fn (TaskTag $t): string => $t->getTagName(), $this->taskTags->findByTask($taskId));
	}
}
