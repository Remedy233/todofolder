<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Command;

use OCA\TodoFolder\Db\Workspace;
use OCA\TodoFolder\Db\WorkspaceMapper;
use OCA\TodoFolder\Service\Scanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ todofolder:scan [--workspace=ID] [--all]
 *
 * Scans one workspace, or every workspace when --all is given. The scheduled worker
 * does the same thing on a timer; this command exists for "do it now" and for scripts.
 */
class ScanCommand extends Command {
	public function __construct(
		private WorkspaceMapper $workspaces,
		private Scanner $scanner,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('todofolder:scan')
			->setDescription('扫描工作区目录，把子文件夹同步成任务（不调用模型）')
			->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, '只扫描这个工作区 id')
			->addOption('all', 'a', InputOption::VALUE_NONE, '扫描所有工作区，忽略各自的定时间隔');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$workspaceId = $input->getOption('workspace');
		$all = (bool)$input->getOption('all');

		$targets = [];
		if ($workspaceId !== null) {
			$targets[] = $this->workspaces->find((int)$workspaceId);
		} else {
			$targets = $all
				? $this->workspaces->findAllOrdered()
				: $this->workspaces->findDueForScan(time(), 50);
		}

		if ($targets === []) {
			$output->writeln('<comment>没有需要扫描的工作区</comment>');
			return 0;
		}

		$failed = 0;
		foreach ($targets as $workspace) {
			/** @var Workspace $workspace */
			try {
				$result = $this->scanner->scan($workspace);
				$output->writeln(sprintf(
					'%s（%s）：新增 %d，更新 %d，移除 %d，共 %d 个任务',
					$workspace->getName(),
					$workspace->getFolderPath(),
					$result['created'],
					$result['updated'],
					$result['removed'],
					$result['tasks'],
				));
			} catch (\Throwable $e) {
				$failed++;
				$output->writeln(sprintf('<error>%s 扫描失败：%s</error>', $workspace->getName(), $e->getMessage()));
			}
		}

		return $failed === 0 ? 0 : 1;
	}
}
