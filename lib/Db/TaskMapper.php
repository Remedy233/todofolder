<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'todofolder_tasks', Task::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Task[]
	 */
	public function findByWorkspace(int $workspaceId, ?string $status = null, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('folder_name', 'ASC')
			->setMaxResults($limit);
		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR)));
		}
		return $this->findEntities($qb);
	}

	public function findByWorkspaceAndPath(int $workspaceId, string $folderPath): ?Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('folder_path', $qb->createNamedParameter($folderPath, IQueryBuilder::PARAM_STR)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return Task[]
	 */
	/**
	 * Same folder, new name: a renamed or moved directory comes back with the same fileid, so
	 * this is how the scanner keeps the task instead of creating a second one.
	 */
	public function findByWorkspaceAndFileid(int $workspaceId, int $folderFileid): ?Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('folder_fileid', $qb->createNamedParameter($folderFileid, \PDO::PARAM_INT)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $this->mapRowToEntity($row);
	}

	public function findPending(int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Task::STATUS_PENDING, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countByWorkspace(int $workspaceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Tasks whose folder disappeared from disk, so the caller can drop them.
	 *
	 * @param string[] $presentPaths
	 * @return Task[]
	 */
	public function findMissingInWorkspace(int $workspaceId, array $presentPaths): array {
		$tasks = $this->findByWorkspace($workspaceId);
		$present = array_flip($presentPaths);
		return array_values(array_filter($tasks, static fn (Task $t): bool => !isset($present[$t->getFolderPath()])));
	}

	/**
	 * Drop every task of a workspace in one statement, used when the workspace goes away.
	 */
	public function deleteByWorkspace(int $workspaceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
