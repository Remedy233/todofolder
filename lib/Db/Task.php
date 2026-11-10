<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A task: one subfolder of a workspace, plus whatever the AI decided about it.
 *
 * Nullable columns carry nullable properties. `folder_name` is the on-disk name and
 * never changes; `title` is the AI (or human) title and may be empty until analysis.
 *
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method string getFolderPath()
 * @method void setFolderPath(string $folderPath)
 * @method ?int getFolderFileid()
 * @method void setFolderFileid(?int $folderFileid)
 * @method string getFolderName()
 * @method void setFolderName(string $folderName)
 * @method ?string getTitle()
 * @method void setTitle(?string $title)
 * @method ?string getSummary()
 * @method void setSummary(?string $summary)
 * @method ?string getDueDate()
 * @method void setDueDate(?string $dueDate)
 * @method ?string getReceivedAt()
 * @method void setReceivedAt(?string $receivedAt)
 * @method ?string getLockedFields()
 * @method void setLockedFields(?string $lockedFields)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getContentHash()
 * @method void setContentHash(?string $contentHash)
 * @method ?string getAiModel()
 * @method void setAiModel(?string $aiModel)
 * @method ?string getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method ?int getDeckCardId()
 * @method void setDeckCardId(?int $deckCardId)
 * @method ?int getAnalyzedAt()
 * @method void setAnalyzedAt(?int $analyzedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Task extends Entity implements \JsonSerializable {
	public const STATUS_PENDING = 'pending';
	public const STATUS_ANALYZING = 'analyzing';
	public const STATUS_READY = 'ready';
	public const STATUS_ERROR = 'error';
	public const STATUS_IGNORED = 'ignored';
	/**
	 * Set when the task's Deck card is finished: dragged out of the bound column, marked
	 * done or archived. Deck owns completion, so the sync writes this status and clears it
	 * again once the card is back in the bound column.
	 */
	public const STATUS_DONE = 'done';
	/**
	 * Started by a human (or dragged into the column mapped to in_progress). Sits between
	 * ready and done so a board shows what is being worked on right now.
	 */
	public const STATUS_IN_PROGRESS = 'in_progress';

	/** Column the card currently sits in; the reconciler writes the real value back. */
	protected ?int $deckStackId = null;
	/** How deckStackId was chosen: ai, manual, fallback, inbox. */
	protected ?string $stackSource = null;
	/** Column the model suggested while analysing; applied when the task is finished. */
	protected ?int $suggestedStackId = null;
	protected ?string $suggestedStackName = null;
	protected ?string $suggestedStackReason = null;
	/** First time a human started the task (unix time). */
	protected ?int $startedAt = null;

	protected int $workspaceId = 0;
	protected string $folderPath = '';
	protected ?int $folderFileid = null;
	protected string $folderName = '';
	protected ?string $title = null;
	protected ?string $summary = null;
	protected ?string $dueDate = null;
	/**
	 * The day the task folder first appeared (YYYY-MM-DD). Editable; maps to the Deck
	 * card's start date.
	 */
	protected ?string $receivedAt = null;
	/**
	 * Comma-separated column names a human edited ("title,due_date"). Analysis skips
	 * those, because a person who typed a deadline does not want a model to replace it.
	 */
	protected ?string $lockedFields = null;
	protected int $priority = 1;
	protected string $status = self::STATUS_PENDING;
	protected ?string $contentHash = null;
	protected ?string $aiModel = null;
	protected ?string $errorMessage = null;
	protected ?int $deckCardId = null;
	protected ?int $analyzedAt = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('workspaceId', 'integer');
		$this->addType('folderPath', 'string');
		$this->addType('folderFileid', 'integer');
		$this->addType('folderName', 'string');
		$this->addType('title', 'string');
		$this->addType('summary', 'string');
		$this->addType('dueDate', 'string');
		$this->addType('receivedAt', 'string');
		$this->addType('lockedFields', 'string');
		$this->addType('priority', 'integer');
		$this->addType('status', 'string');
		$this->addType('contentHash', 'string');
		$this->addType('aiModel', 'string');
		$this->addType('errorMessage', 'string');
		$this->addType('deckCardId', 'integer');
		$this->addType('analyzedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('deckStackId', 'integer');
		$this->addType('stackSource', 'string');
		$this->addType('suggestedStackId', 'integer');
		$this->addType('suggestedStackName', 'string');
		$this->addType('suggestedStackReason', 'string');
		$this->addType('startedAt', 'integer');
	}

	/**
	 * Fields a human edited. Analysis must not overwrite them.
	 *
	 * @return string[]
	 */
	public function getLockedFieldList(): array {
		$raw = (string)($this->lockedFields ?? '');
		return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $f): bool => $f !== ''));
	}

	public function isFieldLocked(string $field): bool {
		return in_array($field, $this->getLockedFieldList(), true);
	}

	public function lockField(string $field): void {
		$fields = $this->getLockedFieldList();
		if (!in_array($field, $fields, true)) {
			$fields[] = $field;
			$this->setLockedFields(implode(',', $fields));
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'workspaceId' => $this->getWorkspaceId(),
			'folderPath' => $this->getFolderPath(),
			'folderFileid' => $this->getFolderFileid(),
			'folderName' => $this->getFolderName(),
			'title' => $this->getTitle(),
			'summary' => $this->getSummary(),
			'dueDate' => $this->getDueDate(),
			'receivedAt' => $this->getReceivedAt(),
			'lockedFields' => $this->getLockedFields(),
			'priority' => $this->getPriority(),
			'status' => $this->getStatus(),
			'aiModel' => $this->getAiModel(),
			'errorMessage' => $this->getErrorMessage(),
			'deckCardId' => $this->getDeckCardId(),
			'deckStackId' => $this->getDeckStackId(),
			'stackSource' => $this->getStackSource(),
			'suggestedStackId' => $this->getSuggestedStackId(),
			'suggestedStackName' => $this->getSuggestedStackName(),
			'suggestedStackReason' => $this->getSuggestedStackReason(),
			'startedAt' => $this->getStartedAt(),
			'analyzedAt' => $this->getAnalyzedAt(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
