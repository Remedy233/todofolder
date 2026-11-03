<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One tag on one task. `systemtag_id` points into oc_systemtag when the tag was also
 * created as a Nextcloud system tag, which is what makes it visible in Files.
 *
 * @method int getTaskId()
 * @method void setTaskId(int $taskId)
 * @method string getTagName()
 * @method void setTagName(string $tagName)
 * @method ?int getSystemtagId()
 * @method void setSystemtagId(?int $systemtagId)
 * @method string getSource()
 * @method void setSource(string $source)
 */
class TaskTag extends Entity implements \JsonSerializable {
	public const SOURCE_AI = 'ai';
	public const SOURCE_USER = 'user';

	protected int $taskId = 0;
	protected string $tagName = '';
	protected ?int $systemtagId = null;
	protected string $source = self::SOURCE_AI;

	public function __construct() {
		$this->addType('taskId', 'integer');
		$this->addType('tagName', 'string');
		$this->addType('systemtagId', 'integer');
		$this->addType('source', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'taskId' => $this->getTaskId(),
			'tagName' => $this->getTagName(),
			'systemtagId' => $this->getSystemtagId(),
			'source' => $this->getSource(),
		];
	}
}
