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
use OCP\Files\Folder;
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
	) {
	}

	/**
	 * Analyse one task. Never throws for model/Deck failures: the task row records the
	 * error so the UI can show it, and the caller gets the row back either way.
	 */
	public function analyze(Task $task, Workspace $workspace): Task {
		$task->setStatus(Task::STATUS_ANALYZING);
		$task->setUpdatedAt(time());
		$this->tasks->update($task);

		try {
			$taskFolder = $this->workspaceService->resolveTaskFolder($workspace, $task->getFolderPath());
			if (!$taskFolder instanceof Folder) {
				throw new \RuntimeException('任务目录不存在：' . $task->getFolderPath());
			}

			$extract = $this->extractor->extract($taskFolder);
			$answer = $this->ai->chatJson(self::SYSTEM_PROMPT, $this->prompt($task, $workspace, $extract, $taskFolder));

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
			$task->setStatus(Task::STATUS_READY);
			$task->setAiModel($this->config->isMock() ? 'mock' : $this->config->model());
			$task->setContentHash($extract['hash']);
			$task->setErrorMessage(null);
			$task->setAnalyzedAt(time());
			$task->setUpdatedAt(time());
			$this->tasks->update($task);

			$tagNames = is_array($answer['tags'] ?? null) ? $answer['tags'] : [];
			$this->tags->replaceAiTags($task, $tagNames, $workspace->getUserId());

			if ($workspace->getAutoDeck()) {
				if ($workspace->getDeckStackId() === null) {
					// Silently doing nothing is what makes Deck sync look broken: say it on
					// the task instead, pointing at the one-click fix.
					$task->setErrorMessage('工作区还没绑定 Deck 看板/列，所以没有建卡。在工作区设置里点「一键准备看板」即可。');
					$task->setUpdatedAt(time());
					$this->tasks->update($task);
				} else {
					$this->syncDeck($task, $workspace);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: analysis failed for task ' . $task->getId() . ': ' . $e->getMessage());
			$task->setStatus(Task::STATUS_ERROR);
			$task->setErrorMessage(mb_substr($e->getMessage(), 0, 1000));
			$task->setUpdatedAt(time());
			$this->tasks->update($task);
		}

		return $task;
	}

	public function syncDeck(Task $task, Workspace $workspace): Task {
		try {
			// The tags become Deck labels; read them from the table rather than from the
			// model answer, so a human edit to the tags is what lands on the card.
			$cardId = $this->deck->sync($task, $workspace, $this->tags->namesForTask((int)$task->getId()));
			if ($cardId !== null) {
				$task->setDeckCardId($cardId);
				$task->setUpdatedAt(time());
				$this->tasks->update($task);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('todofolder: deck sync failed for task ' . $task->getId() . ': ' . $e->getMessage());
			$task->setErrorMessage('Deck 同步失败：' . mb_substr($e->getMessage(), 0, 300));
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
	private function prompt(Task $task, Workspace $workspace, array $extract, Folder $folder): string {
		$lines = [];
		$lines[] = '工作区：' . $workspace->getName();
		$lines[] = '文件夹名：' . $task->getFolderName();
		$lines[] = '文件数：' . count($extract['files']) . ($extract['truncated'] ? '（内容已截断）' : '');
		$lines[] = '';
		$lines[] = '文件清单：';
		foreach ($extract['files'] as $file) {
			$lines[] = sprintf(
				'- %s（%s，修改于 %s）',
				$file['name'],
				$this->humanSize($file['size']),
				date('Y-m-d', $file['mtime']),
			);
		}
		if ($extract['text'] !== '') {
			$lines[] = '';
			$lines[] = '文本内容：';
			$lines[] = $extract['text'];
		}
		$extra = $workspace->getAiExtraPrompt();
		if ($extra !== null && trim($extra) !== '') {
			$lines[] = '';
			$lines[] = '额外要求：' . $extra;
		}
		return implode("\n", $lines);
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
