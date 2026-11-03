<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One workspace: a Files directory whose subfolders are tasks.
 *
 * Nullable columns carry nullable PHP properties ($folderFileid, $deckBoardId, ...):
 * a typed non-null property on a nullable column throws on read.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getFolderPath()
 * @method void setFolderPath(string $folderPath)
 * @method ?int getFolderFileid()
 * @method void setFolderFileid(?int $folderFileid)
 * @method ?int getDeckBoardId()
 * @method void setDeckBoardId(?int $deckBoardId)
 * @method ?int getDeckStackId()
 * @method void setDeckStackId(?int $deckStackId)
 * @method bool getAutoDeck()
 * @method void setAutoDeck(bool $autoDeck)
 * @method bool getScanEnabled()
 * @method void setScanEnabled(bool $scanEnabled)
 * @method int getScanInterval()
 * @method void setScanInterval(int $scanInterval)
 * @method ?int getLastScanAt()
 * @method void setLastScanAt(?int $lastScanAt)
 * @method ?string getAiExtraPrompt()
 * @method void setAiExtraPrompt(?string $aiExtraPrompt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Workspace extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected string $name = '';
	protected string $folderPath = '';
	protected ?int $folderFileid = null;
	protected ?int $deckBoardId = null;
	protected ?int $deckStackId = null;
	protected bool $autoDeck = false;
	protected bool $scanEnabled = true;
	protected int $scanInterval = 15;
	protected ?int $lastScanAt = null;
	protected ?string $aiExtraPrompt = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('name', 'string');
		$this->addType('folderPath', 'string');
		$this->addType('folderFileid', 'integer');
		$this->addType('deckBoardId', 'integer');
		$this->addType('deckStackId', 'integer');
		$this->addType('autoDeck', 'boolean');
		$this->addType('scanEnabled', 'boolean');
		$this->addType('scanInterval', 'integer');
		$this->addType('lastScanAt', 'integer');
		$this->addType('aiExtraPrompt', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'userId' => $this->getUserId(),
			'name' => $this->getName(),
			'folderPath' => $this->getFolderPath(),
			'folderFileid' => $this->getFolderFileid(),
			'deckBoardId' => $this->getDeckBoardId(),
			'deckStackId' => $this->getDeckStackId(),
			'autoDeck' => $this->getAutoDeck(),
			'scanEnabled' => $this->getScanEnabled(),
			'scanInterval' => $this->getScanInterval(),
			'lastScanAt' => $this->getLastScanAt(),
			'aiExtraPrompt' => $this->getAiExtraPrompt(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
