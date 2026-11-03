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
 * @template-extends QBMapper<Workspace>
 */
class WorkspaceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'todofolder_workspaces', Workspace::class);
	}

	/**
	 * QBMapper has no find($id): every mapper declares its own.
	 *
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Workspace {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Every workspace, for the occ commands that operate instance-wide.
	 *
	 * @return Workspace[]
	 */
	public function findAllOrdered(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return Workspace[]
	 */
	public function findForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByUserAndPath(string $userId, string $folderPath): ?Workspace {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('folder_path', $qb->createNamedParameter($folderPath, IQueryBuilder::PARAM_STR)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Workspaces the scheduled scanner should look at: enabled, and last scanned longer
	 * ago than their own interval.
	 *
	 * The interval lives per row, so the arithmetic happens here rather than in SQL: an
	 * expression like `last_scan_at + scan_interval * 60 <= now` needs a function call
	 * that not every database backend of Nextcloud guarantees, and a workspace list is
	 * small enough that filtering it in PHP costs nothing.
	 *
	 * @return Workspace[]
	 */
	public function findDueForScan(int $now, int $limit = 20): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('scan_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->setMaxResults(200);
		$due = [];
		foreach ($this->findEntities($qb) as $workspace) {
			$last = $workspace->getLastScanAt();
			if ($last === null || ($last + $workspace->getScanInterval() * 60) <= $now) {
				$due[] = $workspace;
			}
		}
		usort($due, static fn (Workspace $a, Workspace $b): int => ($a->getLastScanAt() ?? 0) <=> ($b->getLastScanAt() ?? 0));
		return array_slice($due, 0, $limit);
	}

	public function touchScanned(int $id, int $when): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('last_scan_at', $qb->createNamedParameter($when, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
