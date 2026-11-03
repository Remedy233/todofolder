<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A workspace shared with a group. permission 1 = read, 2 = read-write.
 *
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method string getGroupId()
 * @method void setGroupId(string $groupId)
 * @method int getPermission()
 * @method void setPermission(int $permission)
 */
class WorkspaceShare extends Entity implements \JsonSerializable {
	public const PERM_READ = 1;
	public const PERM_WRITE = 2;

	protected int $workspaceId = 0;
	protected string $groupId = '';
	protected int $permission = self::PERM_READ;

	public function __construct() {
		$this->addType('workspaceId', 'integer');
		$this->addType('groupId', 'string');
		$this->addType('permission', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'workspaceId' => $this->getWorkspaceId(),
			'groupId' => $this->getGroupId(),
			'permission' => $this->getPermission(),
		];
	}
}
