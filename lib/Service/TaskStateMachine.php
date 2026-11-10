<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use InvalidArgumentException;
use OCA\TodoFolder\Db\Task;

/**
 * The one place that decides which task status changes are allowed.
 *
 * Before this existed every writer (controller, scanner, Deck sync) set whatever status it
 * liked, so a finished task could be pushed back to pending by a rescan. The table below is
 * the contract; a transition outside it is a bug, and callers get an exception instead of a
 * silently wrong row.
 */
class TaskStateMachine {

	/**
	 * from => allowed targets
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOWED = [
		Task::STATUS_PENDING => [Task::STATUS_ANALYZING, Task::STATUS_IGNORED],
		Task::STATUS_ANALYZING => [Task::STATUS_READY, Task::STATUS_ERROR, Task::STATUS_PENDING],
		Task::STATUS_ERROR => [Task::STATUS_PENDING, Task::STATUS_ANALYZING, Task::STATUS_IGNORED],
		Task::STATUS_READY => [
			Task::STATUS_IN_PROGRESS,
			Task::STATUS_DONE,
			Task::STATUS_IGNORED,
			Task::STATUS_ANALYZING,
		],
		Task::STATUS_IN_PROGRESS => [
			Task::STATUS_DONE,
			Task::STATUS_READY,
			Task::STATUS_IGNORED,
			Task::STATUS_ANALYZING,
		],
		Task::STATUS_DONE => [Task::STATUS_READY, Task::STATUS_IN_PROGRESS],
		Task::STATUS_IGNORED => [Task::STATUS_PENDING, Task::STATUS_READY],
	];

	public const ALL_STATUSES = [
		Task::STATUS_PENDING,
		Task::STATUS_ANALYZING,
		Task::STATUS_READY,
		Task::STATUS_IN_PROGRESS,
		Task::STATUS_DONE,
		Task::STATUS_ERROR,
		Task::STATUS_IGNORED,
	];

	/** Statuses a human acts on directly from the list. */
	public const ACTIONABLE = [
		Task::STATUS_READY,
		Task::STATUS_IN_PROGRESS,
		Task::STATUS_DONE,
		Task::STATUS_IGNORED,
	];

	public function can(string $from, string $to): bool {
		if ($from === $to) {
			return true;
		}
		return in_array($to, self::ALLOWED[$from] ?? [], true);
	}

	/**
	 * @throws InvalidArgumentException when the transition is not part of the contract
	 */
	public function assert(string $from, string $to): void {
		if (!$this->can($from, $to)) {
			throw new InvalidArgumentException('status ' . $from . ' cannot become ' . $to);
		}
	}

	/**
	 * Move a task, writing the side effects the status carries.
	 *
	 * @throws InvalidArgumentException
	 */
	public function transition(Task $task, string $to, int $now): Task {
		$from = (string)$task->getStatus();
		$this->assert($from, $to);

		if ($to === Task::STATUS_IN_PROGRESS && $task->getStartedAt() === null) {
			$task->setStartedAt($now);
		}

		$task->setStatus($to);
		$task->setUpdatedAt($now);
		if ($to !== Task::STATUS_ERROR) {
			$task->setErrorMessage(null);
		}
		return $task;
	}
}
