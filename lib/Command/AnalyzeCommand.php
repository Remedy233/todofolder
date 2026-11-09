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
use OCP\IL10N;

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
		private IL10N $l10n,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('todofolder:analyze')
			->setDescription($this->l10n->t('Run the model over pending tasks and store title, due date and tags'))
			->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, $this->l10n->t('only handle this workspace id'))
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, $this->l10n->t('maximum number of tasks to handle'), '5')
			->addOption('status', 's', InputOption::VALUE_REQUIRED, $this->l10n->t('only handle tasks with this status'), Task::STATUS_PENDING);
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
					$output->writeln($this->l10n->t('  %s → %s (due %s, priority %s)', [
						$result->getFolderName(),
						$result->getTitle() ?? $this->l10n->t('(no title)'),
						$result->getDueDate() ?? $this->l10n->t('not set'),
						(string)$result->getPriority(),
					]));
				} elseif ($result->getStatus() === Task::STATUS_DONE) {
					// The task is finished on the Deck side; that is a result, not a failure.
					$output->writeln('  ' . $this->l10n->t('%s → already finished, left alone', [$result->getFolderName()]));
				} else {
					$failed++;
					$output->writeln('  <error>' . $this->l10n->t('%s failed: %s', [
						$result->getFolderName(),
						$result->getErrorMessage() ?? $this->l10n->t('Unknown error'),
					]) . '</error>');
				}
			}
		}

		$output->writeln($this->l10n->t('processed %d, failed %d', [$processed, $failed]));
		return $failed === 0 ? 0 : 1;
	}
}
