<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\BackgroundJob;

use OCA\TodoFolder\Db\WorkspaceMapper;
use OCA\TodoFolder\Service\AnalysisService;
use OCA\TodoFolder\Service\ConfigService;
use OCA\TodoFolder\Service\Scanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The scheduled half of the app: every five minutes, look at the workspaces whose own
 * interval has elapsed, scan them, and analyse a few of the resulting pending tasks.
 *
 * Deliberately timid — a handful of workspaces and a handful of tasks per run, because
 * every analysis is a paid HTTP request and a long cron blocks the others.
 */
class QueueWorker extends TimedJob {
	public const INTERVAL_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private WorkspaceMapper $workspaces,
		private Scanner $scanner,
		private AnalysisService $analysis,
		private ConfigService $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	protected function run($argument): void {
		$scanned = [];
		foreach ($this->workspaces->findDueForScan(time(), 10) as $workspace) {
			try {
				$result = $this->scanner->scan($workspace);
				$this->logger->info(sprintf(
					'todofolder: scanned workspace %d (%s): +%d ~%d -%d',
					$workspace->getId(),
					$workspace->getName(),
					$result['created'],
					$result['updated'],
					$result['removed'],
				));
				$scanned[] = $workspace;
			} catch (\Throwable $e) {
				$this->logger->error('todofolder: scan failed for workspace ' . $workspace->getId() . ': ' . $e->getMessage());
			}
		}

		$budget = $this->config->queueBatch();
		foreach ($scanned as $workspace) {
			if ($budget <= 0) {
				break;
			}
			try {
				$result = $this->analysis->analyzePending((int)$workspace->getId(), $budget);
				$budget -= $result['analyzed'] + $result['failed'];
			} catch (\Throwable $e) {
				$this->logger->error('todofolder: queued analysis failed for workspace ' . $workspace->getId() . ': ' . $e->getMessage());
			}
		}
	}
}
