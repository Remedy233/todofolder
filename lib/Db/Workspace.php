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
	/** Column new cards land in (the inbox); falls back to deckStackId when empty. */
	protected ?int $deckInboxStackId = null;
	/** Let the model pick a category column for each task. */
	protected bool $deckClassify = false;
	/** on_done | on_create | manual */
	protected string $deckClassifyTiming = 'on_done';
	/** category | fixed | in_place */
	protected string $deckDoneTarget = 'category';
	/** JSON list of stack ids the model may pick from; empty means every column. */
	protected ?string $deckCategoryStacks = null;
	/** JSON map stack id -> todo|in_progress|done|archive */
	protected ?string $deckStateMap = null;
	/** Column used when deckDoneTarget is "fixed". */
	protected ?int $deckCompletionStackId = null;
	protected int $deckReconcileInterval = 15;
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
		$this->addType('deckInboxStackId', 'integer');
		$this->addType('deckClassify', 'boolean');
		$this->addType('deckClassifyTiming', 'string');
		$this->addType('deckDoneTarget', 'string');
		$this->addType('deckCategoryStacks', 'string');
		$this->addType('deckStateMap', 'string');
		$this->addType('deckCompletionStackId', 'integer');
		$this->addType('deckReconcileInterval', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * Column ids the model may pick from; an empty list means the whole board.
	 *
	 * @return int[]
	 */
	public function getCategoryStackList(): array {
		$raw = (string)($this->deckCategoryStacks ?? '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_map('intval', array_filter($decoded, static fn ($v): bool => is_numeric($v))));
	}

	/**
	 * What each Deck column means for the task state.
	 *
	 * @return array<int, string> stack id => todo|in_progress|done|archive
	 */
	public function getStateMap(): array {
		$raw = (string)($this->deckStateMap ?? '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $stackId => $state) {
			if (is_numeric($stackId) && is_string($state) && $state !== '') {
				$out[(int)$stackId] = $state;
			}
		}
		return $out;
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
			'deckInboxStackId' => $this->getDeckInboxStackId(),
			'deckClassify' => $this->getDeckClassify(),
			'deckClassifyTiming' => $this->getDeckClassifyTiming(),
			'deckDoneTarget' => $this->getDeckDoneTarget(),
			'deckCategoryStacks' => $this->getCategoryStackList(),
			'deckStateMap' => $this->getStateMap(),
			'deckCompletionStackId' => $this->getDeckCompletionStackId(),
			'deckReconcileInterval' => $this->getDeckReconcileInterval(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
