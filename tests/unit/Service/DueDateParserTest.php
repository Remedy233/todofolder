<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Tests\Unit\Service;

use OCA\TodoFolder\Service\DueDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The deadline is the one field where guessing is worse than admitting ignorance, so it
 * gets its own table of cases. "Today" is pinned, so the expectations never drift.
 */
class DueDateParserTest extends TestCase {
	private DueDateParser $parser;
	private \DateTimeImmutable $today;

	protected function setUp(): void {
		// A Wednesday, so "next Friday" and "this Friday" are different answers.
		$this->today = new \DateTimeImmutable('2026-11-04');
		$this->parser = new DueDateParser();
	}

	/**
	 * @return array<string, array{0: mixed, 1: ?string}>
	 */
	public static function dates(): array {
		return [
			'iso date' => ['2026-11-09', '2026-11-09'],
			'slashes' => ['2026/11/09', '2026-11-09'],
			'dots' => ['2026.11.09', '2026-11-09'],
			'chinese date' => ['2026年11月9日', '2026-11-09'],
			'padded' => [' 2026-11-09 ', '2026-11-09'],
			'month and day, this year' => ['12月01日', '2026-12-01'],
			'month and day already past' => ['03月01日', '2027-03-01'],
			'today' => ['今天', '2026-11-04'],
			'tomorrow' => ['明天', '2026-11-05'],
			'day after tomorrow' => ['后天', '2026-11-06'],
			'this friday' => ['本周五', '2026-11-06'],
			'next friday' => ['下周五', '2026-11-13'],
			'next monday' => ['下周一', '2026-11-09'],
			'weekday shorthand' => ['周五前发出', '2026-11-06'],
			'end of month' => ['本月底', '2026-11-30'],
			'next month' => ['下月初', '2026-12-01'],
			'in three days' => ['3天后', '2026-11-07'],
			'null literal' => ['null', null],
			'empty string' => ['', null],
			'blank' => ['   ', null],
			'garbage' => ['尽快', null],
			'impossible date' => ['2026-02-30', null],
			'non-string' => [42, null],
			'explicit null' => [null, null],
		];
	}

	#[DataProvider('dates')]
	public function testParse(mixed $value, ?string $expected): void {
		$this->assertSame($expected, $this->parser->parse($value, $this->today));
	}

	public function testModelNoiseAroundDateIsTolerated(): void {
		// Some models answer "2026-11-09（下周一）"; the date still has to come out.
		$this->assertSame('2026-11-09', $this->parser->parse('2026-11-09（下周一）', $this->today));
	}
}
