<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Command;

use OCA\TodoFolder\Db\Task;
use OCA\TodoFolder\Db\TaskMapper;
use OCA\TodoFolder\Db\Workspace;
use OCA\TodoFolder\Db\WorkspaceMapper;
use OCA\TodoFolder\Service\AnalysisService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ todofolder:analyze [--workspace=ID] [--limit=N] [--status=pending|error]
 *
 * The command form of the page's "分析未完成的任务" button: run the model now, see the
 * result in the terminal, instead of waiting for cron.
 */
class AnalyzeCommand extends Command {
	public function __construct(
		private TaskMapper $tasks,
		private WorkspaceMapper $workspaces,
		private AnalysisService $analysis,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('todofolder:analyze')
			->setDescription('对未完成的任务调用模型，写入标题、截止时间与标签')
			->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, '只处理这个工作区 id')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, '最多处理多少个任务', '5')
			->addOption('status', 's', InputOption::VALUE_REQUIRED, '只处理这个状态的任务', Task::STATUS_PENDING);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, (int)$input->getOption('limit'));
		$status = (string)$input->getOption('status');
		$workspaceId = $input->getOption('workspace');

		$workspaces = [];
		if ($workspaceId !== null) {
			$workspaces[] = $this->workspaces->find((int)$workspaceId);
		} else {
			$workspaces = $this->workspaces->findAllOrdered();
		}

		$processed = 0;
		$failed = 0;
		foreach ($workspaces as $workspace) {
			/** @var Workspace $workspace */
			if ($processed >= $limit) {
				break;
			}
			foreach ($this->tasks->findByWorkspace((int)$workspace->getId(), $status) as $task) {
				if ($processed >= $limit) {
					break;
				}
				$processed++;
				$result = $this->analysis->analyze($task, $workspace);
				if ($result->getStatus() === Task::STATUS_READY) {
					$output->writeln(sprintf(
						'  %s → %s（截止 %s，优先级 %s）',
						$result->getFolderName(),
						$result->getTitle() ?? '（无标题）',
						$result->getDueDate() ?? '未定',
						(string)$result->getPriority(),
					));
				} else {
					$failed++;
					$output->writeln(sprintf(
						'  <error>%s 失败：%s</error>',
						$result->getFolderName(),
						$result->getErrorMessage() ?? '未知错误',
					));
				}
			}
		}

		$output->writeln(sprintf('处理 %d 个，失败 %d 个', $processed, $failed));
		return $failed === 0 ? 0 : 1;
	}
}
