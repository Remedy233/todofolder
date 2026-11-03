<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TaskTag>
 */
class TaskTagMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'todofolder_task_tags', TaskTag::class);
	}

	/**
	 * @return TaskTag[]
	 */
	public function findByTask(int $taskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->orderBy('tag_name', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * All tags for the given tasks, grouped by task id.
	 *
	 * @param int[] $taskIds
	 * @return array<int, TaskTag[]>
	 */
	public function findByTasks(array $taskIds): array {
		if ($taskIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('task_id', $qb->createNamedParameter($taskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('tag_name', 'ASC');
		$grouped = [];
		foreach ($this->findEntities($qb) as $tag) {
			$grouped[$tag->getTaskId()][] = $tag;
		}
		return $grouped;
	}

	public function deleteByTask(int $taskId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
