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
 * @template-extends QBMapper<WorkspaceShare>
 */
class WorkspaceShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'todofolder_workspace_shares', WorkspaceShare::class);
	}

	/**
	 * @return WorkspaceShare[]
	 */
	public function findByWorkspace(int $workspaceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Shares visible to a user through one of their groups.
	 *
	 * @param string[] $groupIds
	 * @return WorkspaceShare[]
	 */
	public function findByGroups(array $groupIds): array {
		if ($groupIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('group_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)));
		return $this->findEntities($qb);
	}

	public function deleteByWorkspace(int $workspaceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByWorkspaceAndGroup(int $workspaceId, string $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}
}
