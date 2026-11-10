<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Controller;

use OCA\TodoFolder\AppInfo\Application;
use OCA\TodoFolder\Db\Task;
use OCA\TodoFolder\Db\TaskMapper;
use OCA\TodoFolder\Db\TaskTagMapper;
use OCA\TodoFolder\Service\AnalysisService;
use OCA\TodoFolder\Service\DeckBridge;
use OCA\TodoFolder\Service\Scanner;
use OCA\TodoFolder\Service\TagService;
use OCA\TodoFolder\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\IL10N;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Tasks, scanning and Deck. Every route resolves its workspace through
 * WorkspaceService first, so an id from another user's account is a 404, not a leak.
 */
class TaskController extends Controller {
	public function __construct(
		IRequest $request,
		private TaskMapper $tasks,
		private TaskTagMapper $taskTags,
		private WorkspaceService $workspaces,
		private Scanner $scanner,
		private AnalysisService $analysis,
		private DeckBridge $deck,
		private TagService $tags,
		private ?string $userId,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/workspaces/{id}/tasks')]
	public function index(int $id, ?string $status = null): JSONResponse {
		try {
			$this->workspaces->getForUser($id, (string)$this->userId);
		} catch (\Throwable) {
			return new JSONResponse(['error' => $this->l10n->t('Workspace not found')], Http::STATUS_NOT_FOUND);
		}

		$rows = $this->tasks->findByWorkspace($id, $status);
		$tags = $this->taskTags->findByTasks(array_map(static fn (Task $t): int => (int)$t->getId(), $rows));

		$out = [];
		foreach ($rows as $task) {
			$out[] = [
				...$task->jsonSerialize(),
				'tags' => $tags[(int)$task->getId()] ?? [],
			];
		}
		return new JSONResponse(['tasks' => $out]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/workspaces/{id}/scan')]
	public function scan(int $id): JSONResponse {
		try {
			$workspace = $this->workspaces->getForUser($id, (string)$this->userId, true);
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: scan refused for workspace ' . $id . ': ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => $this->l10n->t('Workspace not found')], Http::STATUS_NOT_FOUND);
		}
		$result = $this->scanner->scan($workspace);
		return new JSONResponse($result);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/analyze')]
	public function analyze(int $id): JSONResponse {
		try {
			$task = $this->tasks->find($id);
			$workspace = $this->workspaces->getForUser($task->getWorkspaceId(), (string)$this->userId, true);
		} catch (\Throwable) {
			return new JSONResponse(['error' => $this->l10n->t('Task not found')], Http::STATUS_NOT_FOUND);
		}
		$task = $this->analysis->analyze($task, $workspace);
		return new JSONResponse([
			...$task->jsonSerialize(),
			'tags' => array_map(static fn ($t) => $t->jsonSerialize(), $this->taskTags->findByTask($id)),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/workspaces/{id}/analyze-pending')]
	public function analyzePending(int $id): JSONResponse {
		try {
			$this->workspaces->getForUser($id, (string)$this->userId, true);
		} catch (\Throwable) {
			return new JSONResponse(['error' => $this->l10n->t('Workspace not found')], Http::STATUS_NOT_FOUND);
		}

		// `limit` is a *reserved* request parameter in Nextcloud: the dispatcher validates it
		// against 1..500 for any request that carries it and answers 500 when it is outside
		// that range — before this code runs. So the API parameter is `max`, our own clamp
		// (1..20) is what applies, and `limit` is still honoured when it is in range.
		$raw = $this->request->getParam('max', $this->request->getParam('limit', 5));
		return new JSONResponse($this->analysis->analyzePending($id, max(1, min(20, (int)$raw))));
	}

	/**
	 * A human correcting the machine: title, due date and priority are editable, and a
	 * manual edit marks the task ready so the scanner does not re-queue it until the
	 * folder content actually changes.
	 */
	/**
	 * A human correcting the machine. Editable: title, due date, received date, priority,
	 * status and the tag list. An explicit null clears a date, so "no deadline" is
	 * expressible — that is why the fields are read from the raw parameters instead of
	 * typed arguments, where "absent" and "null" look the same.
	 *
	 * The edit also marks the task ready (the scanner will not re-queue it until the
	 * folder content changes) and, when the workspace is bound to Deck, re-syncs the card
	 * so title/date/labels/start date never drift apart from the task.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/tasks/{id}')]
	public function update(int $id): JSONResponse {
		try {
			$task = $this->tasks->find($id);
			$workspace = $this->workspaces->getForUser($task->getWorkspaceId(), (string)$this->userId, true);
		} catch (\Throwable) {
			return new JSONResponse(['error' => $this->l10n->t('Task not found')], Http::STATUS_NOT_FOUND);
		}

		$params = $this->request->getParams();

		if (array_key_exists('title', $params)) {
			$title = trim((string)$params['title']);
			if ($title === '') {
				return new JSONResponse(['error' => $this->l10n->t('The title cannot be empty')], Http::STATUS_BAD_REQUEST);
			}
			$task->setTitle(mb_substr($title, 0, 255));
			$task->lockField('title');
		}
		if (array_key_exists('dueDate', $params)) {
			$task->setDueDate($params['dueDate'] === null || $params['dueDate'] === ''
				? null
				: $this->analysis->normaliseDate($params['dueDate']));
			$task->lockField('due_date');
		}
		if (array_key_exists('receivedAt', $params)) {
			$task->setReceivedAt($params['receivedAt'] === null || $params['receivedAt'] === ''
				? null
				: $this->analysis->normaliseDate($params['receivedAt']));
		}
		if (array_key_exists('priority', $params)) {
			$task->setPriority(max(0, min(3, (int)$params['priority'])));
			$task->lockField('priority');
		}
		if (array_key_exists('status', $params)) {
			$status = (string)$params['status'];
			$allowed = [Task::STATUS_PENDING, Task::STATUS_READY, Task::STATUS_IGNORED];
			if (!in_array($status, $allowed, true)) {
				return new JSONResponse(['error' => $this->l10n->t('Unsupported status')], Http::STATUS_BAD_REQUEST);
			}
			$task->setStatus($status);
		}

		$task->setUpdatedAt(time());
		$this->tasks->update($task);

		if (array_key_exists('tags', $params) && is_array($params['tags'])) {
			$this->tags->setUserTags($task, $params['tags'], $workspace->getUserId());
		}

		$synced = false;
		if ($workspace->getDeckStackId() !== null) {
			$this->analysis->syncDeck($task, $workspace);
			$synced = true;
		}

		return new JSONResponse([
			...$task->jsonSerialize(),
			'tags' => array_map(static fn ($t) => $t->jsonSerialize(), $this->taskTags->findByTask($id)),
			'deckSynced' => $synced,
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/deck')]
	public function deck(int $id): JSONResponse {
		try {
			$task = $this->tasks->find($id);
			$workspace = $this->workspaces->getForUser($task->getWorkspaceId(), (string)$this->userId, true);
		} catch (\Throwable) {
			return new JSONResponse(['error' => $this->l10n->t('Task not found')], Http::STATUS_NOT_FOUND);
		}
		if ($workspace->getDeckStackId() === null) {
			return new JSONResponse(['error' => $this->l10n->t('This workspace is not linked to a Deck board and column yet')], Http::STATUS_BAD_REQUEST);
		}
		$task = $this->analysis->syncDeck($task, $workspace);
		return new JSONResponse($task->jsonSerialize());
	}

	/**
	 * "一键准备看板": reuse the workspace's board (or one with the same title, or create
	 * one), make sure it has a column, store the binding, and optionally push the tasks
	 * that already have an answer.
	 */
	/**
	 * Load a task plus the workspace it belongs to, or answer with the error to return.
	 *
	 * @return array{0: Task, 1: \OCA\TodoFolder\Db\Workspace}|JSONResponse
	 */
	private function load(int $id, bool $needWrite = true): array|JSONResponse {
		try {
			$task = $this->tasks->find($id);
			$workspace = $this->workspaces->getForUser($task->getWorkspaceId(), (string)$this->userId, $needWrite);
		} catch (\Throwable) {
			return new JSONResponse(['error' => $this->l10n->t('Task not found')], Http::STATUS_NOT_FOUND);
		}
		return [$task, $workspace];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(Task $task): array {
		return [
			...$task->jsonSerialize(),
			'tags' => array_map(static fn ($t) => $t->jsonSerialize(), $this->taskTags->findByTask((int)$task->getId())),
		];
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/start')]
	public function start(int $id): JSONResponse {
		$loaded = $this->load($id);
		if ($loaded instanceof JSONResponse) {
			return $loaded;
		}
		[$task, $workspace] = $loaded;
		try {
			return new JSONResponse($this->payload($this->analysis->startTask($task, $workspace)));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/finish')]
	public function finish(int $id, ?int $stackId = null): JSONResponse {
		$loaded = $this->load($id);
		if ($loaded instanceof JSONResponse) {
			return $loaded;
		}
		[$task, $workspace] = $loaded;
		try {
			return new JSONResponse($this->payload($this->analysis->finishTask($task, $workspace, $stackId)));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/ignore')]
	public function ignore(int $id): JSONResponse {
		$loaded = $this->load($id);
		if ($loaded instanceof JSONResponse) {
			return $loaded;
		}
		[$task, $workspace] = $loaded;
		try {
			return new JSONResponse($this->payload($this->analysis->ignoreTask($task, $workspace)));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/restore')]
	public function restore(int $id): JSONResponse {
		$loaded = $this->load($id);
		if ($loaded instanceof JSONResponse) {
			return $loaded;
		}
		[$task, $workspace] = $loaded;
		try {
			return new JSONResponse($this->payload($this->analysis->restoreTask($task, $workspace)));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/tasks/{id}/stack')]
	public function stack(int $id, int $stackId = 0): JSONResponse {
		$loaded = $this->load($id);
		if ($loaded instanceof JSONResponse) {
			return $loaded;
		}
		[$task, $workspace] = $loaded;
		if ($stackId <= 0) {
			return new JSONResponse(['error' => $this->l10n->t('A column must be given')], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->payload($this->analysis->setCategory($task, $workspace, $stackId)));
	}

	/**
	 * Links a user needs next to a task: the folder in Files, the card in Deck.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tasks/{id}/links')]
	public function links(int $id): JSONResponse {
		$loaded = $this->load($id, false);
		if ($loaded instanceof JSONResponse) {
			return $loaded;
		}
		[$task, $workspace] = $loaded;
		$folderUrl = \OCP\Server::get(\OCP\IURLGenerator::class)->linkTo(
			'files',
			'',
			['dir' => $task->getFolderPath()],
		);
		$cardUrl = null;
		if ($task->getDeckCardId() !== null && $workspace->getDeckBoardId() !== null) {
			$cardUrl = \OCP\Server::get(\OCP\IURLGenerator::class)->linkTo(
				'deck',
				'board',
				[],
			) . '#' . $workspace->getDeckBoardId() . '/' . $task->getDeckCardId();
		}
		return new JSONResponse([
			'folderUrl' => $folderUrl,
			'cardUrl' => $cardUrl,
			'folderPath' => $task->getFolderPath(),
			'cardId' => $task->getDeckCardId(),
			'boardId' => $workspace->getDeckBoardId(),
			'stackId' => $task->getDeckStackId(),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/tasks/{id}')]
	public function destroy(int $id, string $card = 'keep'): JSONResponse {
		$loaded = $this->load($id);
		if ($loaded instanceof JSONResponse) {
			return $loaded;
		}
		[$task, $workspace] = $loaded;
		$cardDeleted = false;
		if ($card === 'delete') {
			$cardDeleted = $this->deck->deleteCard($task, $workspace);
		}
		$this->taskTags->deleteByTask($id);
		$this->tasks->delete($task);
		return new JSONResponse(['removed' => true, 'cardDeleted' => $cardDeleted]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/workspaces/{id}/decksetup')]
	public function deckSetup(int $id, bool $syncTasks = true): JSONResponse {
		try {
			$workspace = $this->workspaces->getForUser($id, (string)$this->userId, true);
		} catch (\Throwable) {
			return new JSONResponse(['error' => $this->l10n->t('Workspace not found')], Http::STATUS_NOT_FOUND);
		}

		try {
			$binding = $this->deck->ensureBoardAndStack(
				$workspace->getUserId(),
				$workspace->getName(),
				$this->l10n->t('Todo'),
				$workspace->getDeckBoardId(),
			);
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: deck setup failed: ' . $e->getMessage());
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}

		$workspace = $this->workspaces->bindDeck($workspace, $binding['boardId'], $binding['stackId'], true);

		$synced = 0;
		if ($syncTasks) {
			foreach ($this->tasks->findByWorkspace($id) as $task) {
				if ($task->getStatus() !== Task::STATUS_READY) {
					continue;
				}
				$this->analysis->syncDeck($task, $workspace);
				$synced++;
			}
		}

		return new JSONResponse([
			...$binding,
			'synced' => $synced,
			'workspace' => $workspace->jsonSerialize(),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/deck/boards')]
	public function boards(): JSONResponse {
		return new JSONResponse(['boards' => $this->deck->boards((string)$this->userId)]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/deck/boards/{boardId}/stacks')]
	public function stacks(int $boardId): JSONResponse {
		return new JSONResponse(['stacks' => $this->deck->stacks((string)$this->userId, $boardId)]);
	}
}
