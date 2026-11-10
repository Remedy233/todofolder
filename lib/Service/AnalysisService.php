<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCA\TodoFolder\Db\Task;
use OCA\TodoFolder\Db\TaskMapper;
use OCA\TodoFolder\Db\Workspace;
use OCA\TodoFolder\Db\WorkspaceMapper;
use OCA\TodoFolder\AppInfo\Application;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * The one place that turns a task folder into a decision: title, summary, due date,
 * priority, tags — and, if the workspace is wired to Deck, a card.
 *
 * Everything it needs is injected, so the same code path runs from the page, from occ
 * and from the background job; the only difference is who is logged in.
 */
class AnalysisService {
	private const SYSTEM_PROMPT = <<<'PROMPT'
你是任务整理助手。用户把一个文件夹当作一个任务，里面是这件事的材料。
根据文件夹名、文件清单和文本内容，判断这个任务到底是什么，输出一个 JSON 对象，字段：

- title: 任务标题，中文，不超过 30 字，动宾结构，不要照抄文件夹名，也不要带日期
- summary: 任务摘要，中文，不超过 200 字，说明要做什么、涉及谁、当前进展到哪
- due_date: 截止日期，格式 YYYY-MM-DD；材料里没有明确时间线索时填 null，不要瞎猜
- priority: low | normal | high | urgent 之一，依据是否有明确时间压力、是否是阻塞别人的事
- tags: 3 到 6 个中文短标签，每个不超过 8 字，用于在文件列表里筛选（例如「合同」「待回复」「Q4」）；不要输出日期或人名以外的废话
- reason: 一句话说明你凭什么这么判断，不超过 60 字

只输出 JSON，不要解释、不要代码块。
PROMPT;

	/**
	 * The same request for everybody else: the model answers in English, so an instance
	 * whose users read English gets English titles, summaries and tags instead of Chinese
	 * ones. The language follows the acting user, not the server locale.
	 */
	private const SYSTEM_PROMPT_EN = <<<'PROMPT'
You organise tasks. The user treats one folder as one task, and the folder holds the material for it.
Read the folder name, the file list and the text content, work out what the task actually is, and
answer with a single JSON object:

- title: task title, English, at most 60 characters, verb first, do not copy the folder name, no dates
- summary: what has to be done, who is involved and how far along it is, English, at most 400 characters
- due_date: deadline as YYYY-MM-DD; null when the material carries no clear date, do not guess
- priority: one of low | normal | high | urgent, based on time pressure and whether others are blocked
- tags: 3 to 6 short English labels, at most 20 characters each, used to filter the file list (for example "contract", "awaiting-reply", "Q4"); no dates and no filler
- reason: one sentence on why you decided this, at most 120 characters

Answer with JSON only, no prose and no code fences.
PROMPT;

	public function __construct(
		private TaskMapper $tasks,
		private WorkspaceMapper $workspaces,
		private WorkspaceService $workspaceService,
		private ContentExtractor $extractor,
		private AiClient $ai,
		private TagService $tags,
		private DeckBridge $deck,
		private ConfigService $config,
		private DueDateParser $dates,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IFactory $l10nFactory,
		private IConfig $systemConfig,
		private TaskStateMachine $states,
	) {
	}

	/**
	 * The language the analysis runs in. Without a session (occ, the background job) the
	 * injected IL10N falls back to the server default, which would hand a Chinese user English
	 * titles; the workspace owner's language is the one that matters here.
	 */
	private function l10nForUser(string $userId): IL10N {
		$language = $this->systemConfig->getUserValue($userId, 'core', 'lang', '');
		return $language === '' ? $this->l10n : $this->l10nFactory->get(Application::APP_ID, $language);
	}

	/**
	 * Chinese users get the Chinese prompt, everybody else the English one.
	 */
	private function systemPrompt(IL10N $l10n): string {
		return str_starts_with($l10n->getLanguageCode(), 'zh')
			? self::SYSTEM_PROMPT
			: self::SYSTEM_PROMPT_EN;
	}

	/**
	 * Analyse one task. Never throws for model/Deck failures: the task row records the
	 * error so the UI can show it, and the caller gets the row back either way.
	 */
	public function analyze(Task $task, Workspace $workspace): Task {
		// done / ignored tasks are reopened first: a human asking for a fresh analysis of a
		// finished task means "look at it again", not "stay finished".
		if (!$this->states->can((string)$task->getStatus(), Task::STATUS_ANALYZING)) {
			$this->states->transition($task, Task::STATUS_READY, time());
		}
		$this->states->transition($task, Task::STATUS_ANALYZING, time());
		$this->tasks->update($task);

		try {
			$taskFolder = $this->workspaceService->resolveTaskFolder($workspace, $task->getFolderPath());
			if (!$taskFolder instanceof Folder) {
				throw new \RuntimeException('任务目录不存在：' . $task->getFolderPath());
			}

			$extract = $this->extractor->extract($taskFolder);
			$l10n = $this->l10nForUser($workspace->getUserId());
			$answer = $this->ai->chatJson($this->systemPrompt($l10n), $this->prompt($task, $workspace, $extract, $taskFolder, $l10n));

			// A human who edited a field wins over the model: those columns are locked.
			if (!$task->isFieldLocked('title')) {
				$task->setTitle($this->cleanText($answer['title'] ?? null, 255) ?? $task->getFolderName());
			}
			$task->setSummary($this->cleanText($answer['summary'] ?? null, 2000));
			if (!$task->isFieldLocked('due_date')) {
				$task->setDueDate($this->normaliseDate($answer['due_date'] ?? null));
			}
			if (!$task->isFieldLocked('priority')) {
				$task->setPriority($this->normalisePriority($answer['priority'] ?? null));
			}
			$this->states->transition($task, Task::STATUS_READY, time());
			$task->setAiModel($this->config->isMock() ? 'mock' : $this->config->model());
			$task->setContentHash($extract['hash']);
			$task->setAnalyzedAt(time());
			$task->setUpdatedAt(time());
			$this->tasks->update($task);

			$tagNames = is_array($answer['tags'] ?? null) ? $answer['tags'] : [];
			$this->tags->replaceAiTags($task, $tagNames, $workspace->getUserId());

			// Category: remember the model's pick now, apply it when the task is finished
			// (deck_classify_timing=on_done, the default) or right away when the workspace
			// prefers cards filed from the start.
			$this->applyCategory($task, $workspace, $answer);

			if ($workspace->getAutoDeck()) {
				if (($workspace->getDeckInboxStackId() ?? $workspace->getDeckStackId()) === null) {
					// Silently doing nothing is what makes Deck sync look broken: say it on
					// the task instead, pointing at the one-click fix.
					$task->setErrorMessage($this->l10n->t('This workspace has no Deck board or column yet, so no card was created. Use “Prepare board” in the workspace settings.'));
					$task->setUpdatedAt(time());
					$this->tasks->update($task);
				} else {
					$this->syncDeck($task, $workspace);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: analysis failed for task ' . $task->getId() . ': ' . $e->getMessage());
			if ($this->states->can((string)$task->getStatus(), Task::STATUS_ERROR)) {
				$this->states->transition($task, Task::STATUS_ERROR, time());
			} else {
				$task->setStatus(Task::STATUS_ERROR);
				$task->setUpdatedAt(time());
			}
			$task->setErrorMessage(mb_substr($e->getMessage(), 0, 1000));
			$this->tasks->update($task);
		}

		return $task;
	}

	/**
	 * Store the model's category as a suggestion, and — when the workspace files cards from
	 * the start — use it for this task's position right away.
	 *
	 * @param array<string, mixed> $answer
	 */
	private function applyCategory(Task $task, Workspace $workspace, array $answer): void {
		if (!$workspace->getDeckClassify()) {
			return;
		}
		$resolved = $this->resolveCategory($workspace, $answer['stack'] ?? null);
		if ($resolved !== null) {
			$task->setSuggestedStackId($resolved['id']);
			$task->setSuggestedStackName($resolved['name']);
			$task->setSuggestedStackReason($this->cleanText($answer['stack_reason'] ?? null, 255));
			if ($workspace->getDeckClassifyTiming() === 'on_create') {
				$task->setDeckStackId($resolved['id']);
				$task->setStackSource('ai');
			}
			$this->tasks->update($task);
			return;
		}
		$asked = $answer['stack'] ?? null;
		if (is_string($asked) && trim($asked) !== '') {
			// The model named something the board does not have: keep the name for the
			// detail panel, and let the card fall back to the inbox column.
			$task->setSuggestedStackName($this->cleanText($asked, 64));
			$task->setStackSource('fallback');
			$this->tasks->update($task);
		}
	}

	/**
	 * The column a finished task belongs in, according to the workspace settings.
	 * Returns null when the card should stay where it is.
	 */
	public function completionStackFor(Task $task, Workspace $workspace, ?int $overrideStackId = null): ?int {
		if ($overrideStackId !== null) {
			return $overrideStackId;
		}
		if (!$workspace->getDeckClassify()) {
			return null;
		}
		$target = $workspace->getDeckDoneTarget();
		if ($target === 'in_place') {
			return null;
		}
		if ($target === 'fixed') {
			return $workspace->getDeckCompletionStackId() ?? $workspace->getDeckStackId();
		}
		return $task->getSuggestedStackId() ?? $workspace->getDeckCompletionStackId() ?? $workspace->getDeckStackId();
	}

	/**
	 * Start a task: ready → in_progress, and the card moves to the column mapped to that state
	 * (or stays when the workspace has no such column).
	 */
	public function startTask(Task $task, Workspace $workspace): Task {
		$this->states->transition($task, Task::STATUS_IN_PROGRESS, time());
		$this->tasks->update($task);
		$stackId = $this->stateStack($workspace, 'in_progress');
		if ($stackId !== null) {
			$this->moveCardTo($task, $workspace, $stackId);
		}
		return $task;
	}

	/**
	 * Finish a task: → done, then the card goes to its category column (the model's
	 * suggestion unless the caller overrides it).
	 */
	public function finishTask(Task $task, Workspace $workspace, ?int $overrideStackId = null): Task {
		$this->states->transition($task, Task::STATUS_DONE, time());
		$stackId = $this->completionStackFor($task, $workspace, $overrideStackId);
		if ($overrideStackId !== null) {
			// A human picked the category: remember it as theirs, so re-analysis keeps it.
			$task->setDeckStackId($overrideStackId);
			$task->setSuggestedStackId($overrideStackId);
			$task->setStackSource('manual');
			$this->tasks->update($task);
		}
		$this->tasks->update($task);
		if ($stackId !== null) {
			$this->moveCardTo($task, $workspace, $stackId);
		}
		return $task;
	}

	public function ignoreTask(Task $task, Workspace $workspace): Task {
		$this->states->transition($task, Task::STATUS_IGNORED, time());
		$this->tasks->update($task);
		return $task;
	}

	public function restoreTask(Task $task, Workspace $workspace): Task {
		// A finished task comes back as ready (its card is still there), an ignored one goes
		// back to the queue so the next scan can look at it again.
		$target = $task->getStatus() === Task::STATUS_DONE ? Task::STATUS_READY : Task::STATUS_PENDING;
		$this->states->transition($task, $target, time());
		$task->setErrorMessage(null);
		$this->tasks->update($task);
		// A reopened task belongs in the inbox again, not in the column it was filed into.
		$inbox = $workspace->getDeckInboxStackId();
		if ($inbox !== null && $task->getDeckCardId() !== null && $task->getDeckStackId() !== $inbox) {
			$this->moveCardTo($task, $workspace, $inbox);
		}
		return $task;
	}

	/** Manual category change; the card follows immediately. */
	public function setCategory(Task $task, Workspace $workspace, int $stackId): Task {
		$task->setSuggestedStackId($stackId);
		$task->setDeckStackId($stackId);
		$task->setStackSource('manual');
		$task->setUpdatedAt(time());
		$this->tasks->update($task);
		$this->moveCardTo($task, $workspace, $stackId);
		return $task;
	}

	private function moveCardTo(Task $task, Workspace $workspace, int $stackId): void {
		try {
			$this->deck->moveCard($task, $workspace, $stackId);
			$task->setDeckStackId($stackId);
			$task->setUpdatedAt(time());
			$this->tasks->update($task);
		} catch (\Throwable $e) {
			// A missing card or no Deck at all must not fail the status change: the task is
			// the record of truth, the card follows on the next sync.
			$this->logger->info('todofolder: could not move card of task ' . $task->getId() . ' to stack ' . $stackId . ': ' . $e->getMessage());
		}
	}

	/** The column mapped to a state, if the workspace defined one. */
	private function stateStack(Workspace $workspace, string $state): ?int {
		foreach ($workspace->getStateMap() as $stackId => $mapped) {
			if ($mapped === $state) {
				return (int)$stackId;
			}
		}
		return null;
	}

	public function syncDeck(Task $task, Workspace $workspace): Task {
		try {
			// The tags become Deck labels; read them from the table rather than from the
			// model answer, so a human edit to the tags is what lands on the card.
			$sync = $this->deck->sync($task, $workspace, $this->tags->namesForTask((int)$task->getId()));
			if ($sync !== null) {
				$task->setDeckCardId($sync['cardId']);
				if (isset($sync['stackId'])) {
					// Remember where the card really is: that is the reference point for
					// "did a human move it".
					$task->setDeckStackId((int)$sync['stackId']);
				}
				// Deck owns completion: a card that was dragged out of its column, marked
				// done or archived marks the task as done, and a card that is back clears it.
				if ($sync['finished'] && $task->getStatus() !== Task::STATUS_DONE) {
					if ($this->states->can((string)$task->getStatus(), Task::STATUS_DONE)) {
						$this->states->transition($task, Task::STATUS_DONE, time());
					}
				} elseif (!$sync['finished'] && $task->getStatus() === Task::STATUS_DONE) {
					$this->states->transition($task, Task::STATUS_READY, time());
				}
				$task->setUpdatedAt(time());
				$this->tasks->update($task);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: deck sync failed for task ' . $task->getId() . ': ' . $e->getMessage());
			$task->setErrorMessage($this->l10n->t('Deck sync failed: ') . mb_substr($e->getMessage(), 0, 300));
			$task->setUpdatedAt(time());
			$this->tasks->update($task);
		}
		return $task;
	}

	/**
	 * @return array{analyzed: int, failed: int}
	 */
	public function analyzePending(int $workspaceId, int $limit = 5): array {
		$workspace = null;
		$analyzed = 0;
		$failed = 0;
		foreach ($this->tasks->findByWorkspace($workspaceId) as $task) {
			if ($analyzed + $failed >= $limit) {
				break;
			}
			if ($task->getStatus() !== Task::STATUS_PENDING) {
				continue;
			}
			try {
				$workspace ??= $this->workspaces->find($workspaceId);
				$result = $this->analyze($task, $workspace);
				$result->getStatus() === Task::STATUS_READY ? $analyzed++ : $failed++;
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->error('todofolder: pending analysis crashed: ' . $e->getMessage());
			}
		}
		return ['analyzed' => $analyzed, 'failed' => $failed];
	}

	/**
	 * @param array{files: array<int, array{name: string, size: int, mtime: int, text: bool}>, text: string, hash: string, truncated: bool} $extract
	 */
	private function prompt(Task $task, Workspace $workspace, array $extract, Folder $folder, IL10N $l10n): string {
		$lines = [];
		$lines[] = $l10n->t('Workspace: ') . $workspace->getName();
		$lines[] = $l10n->t('Folder: ') . $task->getFolderName();
		$lines[] = $l10n->t('File count: ') . count($extract['files']) . ($extract['truncated'] ? $l10n->t('(content truncated)') : '');
		$lines[] = '';
		$lines[] = $l10n->t('Files: ');
		foreach ($extract['files'] as $file) {
			// The placeholder arguments go through t() itself: Nextcloud runs the translated
			// string through vsprintf, so a "%s" left without arguments throws a ValueError.
			$lines[] = $l10n->t('- %s (%s, modified %s)', [
				$file['name'],
				$this->humanSize($file['size']),
				date('Y-m-d', $file['mtime']),
			]);
		}
		if ($extract['text'] !== '') {
			$lines[] = '';
			$lines[] = $l10n->t('Text: ');
			$lines[] = $extract['text'];
		}
		$extra = $workspace->getAiExtraPrompt();
		if ($extra !== null && trim($extra) !== '') {
			$lines[] = '';
			$lines[] = $l10n->t('Extra requirements: ') . $extra;
		}

		// The Deck columns are the category list: hand the model the allowed names so it can
		// pick one. The answer stays a suggestion until the task is marked done.
		$categories = $this->categoryNames($workspace);
		if ($categories !== []) {
			$lines[] = '';
			$lines[] = $l10n->t('Categories (pick exactly one, return the name unchanged): ') . implode(' / ', $categories);
			$lines[] = $l10n->t('Also return "stack" with that name and "stack_reason" with one short sentence.');
		}

		return implode("\n", $lines);
	}

	/**
	 * Column names the model may choose from: the ones the workspace marked as categories, or
	 * — when it marked none — every column except the inbox and the ones mapped to a state.
	 *
	 * @return list<string>
	 */
	private function categoryNames(Workspace $workspace): array {
		if (!$workspace->getDeckClassify() || $workspace->getDeckBoardId() === null) {
			return [];
		}
		$stacks = $this->deck->stacks($workspace->getUserId(), (int)$workspace->getDeckBoardId());
		if ($stacks === []) {
			return [];
		}
		$allowed = $workspace->getCategoryStackList();
		$stateMap = $workspace->getStateMap();
		$inbox = $workspace->getDeckInboxStackId();
		$names = [];
		foreach ($stacks as $stack) {
			$id = (int)$stack['id'];
			if ($allowed !== []) {
				if (!in_array($id, $allowed, true)) {
					continue;
				}
			} else {
				if ($inbox !== null && $id === $inbox) {
					continue;
				}
				if (isset($stateMap[$id]) && $stateMap[$id] !== 'done') {
					continue;
				}
			}
			$names[] = (string)$stack['title'];
		}
		return $names;
	}

	/**
	 * Turn the model's category name into a stack id. Exact name first, then a normalised
	 * compare, then "one name contains the other" — models like to add a suffix. Nothing
	 * matches: the caller keeps its fallback column.
	 *
	 * @return array{id: int, name: string}|null
	 */
	private function resolveCategory(Workspace $workspace, mixed $answerName): ?array {
		if (!is_string($answerName) || trim($answerName) === '' || $workspace->getDeckBoardId() === null) {
			return null;
		}
		$wanted = trim($answerName);
		$stacks = $this->deck->stacks($workspace->getUserId(), (int)$workspace->getDeckBoardId());
		$normalise = static fn (string $s): string => mb_strtolower(preg_replace('/\s+/u', '', $s) ?? '');
		foreach ($stacks as $stack) {
			if ($stack['title'] === $wanted) {
				return ['id' => (int)$stack['id'], 'name' => (string)$stack['title']];
			}
		}
		$normalised = $normalise($wanted);
		foreach ($stacks as $stack) {
			if ($normalise((string)$stack['title']) === $normalised) {
				return ['id' => (int)$stack['id'], 'name' => (string)$stack['title']];
			}
		}
		foreach ($stacks as $stack) {
			$title = $normalise((string)$stack['title']);
			if ($title !== '' && $normalised !== '' && (str_contains($title, $normalised) || str_contains($normalised, $title))) {
				return ['id' => (int)$stack['id'], 'name' => (string)$stack['title']];
			}
		}
		return null;
	}

	private function humanSize(int $bytes): string {
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		if ($bytes < 1024 * 1024) {
			return number_format((float)$bytes / 1024.0, 1) . ' KB';
		}
		return number_format((float)$bytes / 1048576.0, 1) . ' MB';
	}

	private function cleanText(mixed $value, int $maxLength): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
		if ($value === '') {
			return null;
		}
		return mb_substr($value, 0, $maxLength);
	}

	/**
	 * The model is asked for YYYY-MM-DD, but models answer "下周五" and "月底" too.
	 * Anything we cannot pin to a real date becomes null: a wrong deadline is worse
	 * than no deadline. The parsing itself lives in DueDateParser, where it is unit-tested.
	 */
	public function normaliseDate(mixed $value): ?string {
		return $this->dates->parse($value);
	}

	private function normalisePriority(mixed $value): int {
		$map = ['low' => 0, 'normal' => 1, 'high' => 2, 'urgent' => 3];
		if (is_string($value)) {
			$key = strtolower(trim($value));
			if (isset($map[$key])) {
				return $map[$key];
			}
		}
		if (is_int($value)) {
			return max(0, min(3, $value));
		}
		return 1;
	}
}
