<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Tests\Unit\Service;

use OCA\TodoFolder\Service\ConfigService;
use OCA\TodoFolder\Service\ContentExtractor;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The scanner's rules about what counts as a task folder, and the change signal that
 * decides whether a folder needs analysing again. Both are pure: no file system here.
 */
class ContentExtractorTest extends TestCase {
	private ContentExtractor $extractor;

	protected function setUp(): void {
		$config = new ConfigService($this->createMock(IAppConfig::class));
		$this->extractor = new ContentExtractor($config, $this->createMock(LoggerInterface::class));
	}

	public function testTaskFoldersSkipDotAndUnderscoreConventions(): void {
		// The two conventions that exist in real folders: editor scratch dirs and
		// "_templates" / "_archive" style helpers.
		$this->assertTrue($this->extractor->isIgnoredFolderName('.git'));
		$this->assertTrue($this->extractor->isIgnoredFolderName('.hidden'));
		$this->assertTrue($this->extractor->isIgnoredFolderName('_templates'));
		$this->assertTrue($this->extractor->isIgnoredFolderName('_archive'));
		$this->assertTrue($this->extractor->isIgnoredFolderName(''));
	}

	public function testOrdinaryFoldersAreTasks(): void {
		foreach (['客户A', '报价单', '2026-Q4', 'report-2026', 'Notes'] as $name) {
			$this->assertFalse($this->extractor->isIgnoredFolderName($name), $name . ' should be a task folder');
		}
	}

	public function testHashChangesWithAnyInputThatMatters(): void {
		$base = [['name' => 'a.md', 'size' => 10, 'mtime' => 1000, 'text' => true]];

		$h1 = $this->extractor->hashOf('报价单', $base);
		$this->assertSame($h1, $this->extractor->hashOf('报价单', $base), 'same input, same hash');

		$this->assertNotSame($h1, $this->extractor->hashOf('合同评审', $base), 'folder rename moves the task');
		$this->assertNotSame($h1, $this->extractor->hashOf('报价单', [['name' => 'a.md', 'size' => 11, 'mtime' => 1000, 'text' => true]]), 'size counts');
		$this->assertNotSame($h1, $this->extractor->hashOf('报价单', [['name' => 'a.md', 'size' => 10, 'mtime' => 1001, 'text' => true]]), 'mtime counts');
		$this->assertNotSame($h1, $this->extractor->hashOf('报价单', [
			['name' => 'a.md', 'size' => 10, 'mtime' => 1000, 'text' => true],
			['name' => 'b.txt', 'size' => 1, 'mtime' => 1000, 'text' => true],
		]), 'an added file counts');
	}
}
