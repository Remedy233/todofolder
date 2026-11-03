<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

/**
 * Turns whatever a model said about a deadline into YYYY-MM-DD, or null.
 *
 * Kept as a pure function of (value, today): no services, no database, no clock beyond
 * the one handed in. That is what makes it testable without booting Nextcloud, and the
 * date is the one field where a wrong guess is worse than no answer.
 */
class DueDateParser {
	private const RELATIVE_DAYS = [
		'今天' => 0,
		'今日' => 0,
		'明天' => 1,
		'明日' => 1,
		'后天' => 2,
	];

	private const WEEKDAYS = [
		'一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '日' => 7, '天' => 7,
	];

	public function parse(mixed $value, ?\DateTimeImmutable $today = null): ?string {
		$today ??= new \DateTimeImmutable('today');

		if ($value === null || $value === false || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			return null;
		}

		$value = trim($value);
		if ($value === '' || strcasecmp($value, 'null') === 0 || strcasecmp($value, 'none') === 0) {
			return null;
		}

		// 2026-11-09, 2026/11/09, 2026.11.09 — '#' as delimiter, the value contains '/'.
		if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})#', $value, $m) === 1) {
			return $this->build((int)$m[1], (int)$m[2], (int)$m[3]);
		}
		if (preg_match('#^(\d{4})年(\d{1,2})月(\d{1,2})日#u', $value, $m) === 1) {
			return $this->build((int)$m[1], (int)$m[2], (int)$m[3]);
		}
		// 11月9日 / 11-09: a date in the current year, unless it already passed.
		if (preg_match('#^(\d{1,2})月(\d{1,2})日?#u', $value, $m) === 1) {
			$candidate = $this->build((int)$today->format('Y'), (int)$m[1], (int)$m[2]);
			if ($candidate === null) {
				return null;
			}
			if ($candidate < $today->format('Y-m-d')) {
				$candidate = $this->build((int)$today->format('Y') + 1, (int)$m[1], (int)$m[2]);
			}
			return $candidate;
		}

		return $this->relative($value, $today);
	}

	private function build(int $year, int $month, int $day): ?string {
		if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
			return null;
		}
		if (!checkdate($month, $day, $year)) {
			return null;
		}
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	private function relative(string $value, \DateTimeImmutable $today): ?string {
		foreach (self::RELATIVE_DAYS as $word => $offset) {
			if (str_contains($value, $word)) {
				return $today->modify('+' . $offset . ' day')->format('Y-m-d');
			}
		}
		if (str_contains($value, '月底') || str_contains($value, '月末')) {
			return $today->modify('last day of this month')->format('Y-m-d');
		}
		if (str_contains($value, '下个月') || str_contains($value, '下月')) {
			return $today->modify('first day of next month')->format('Y-m-d');
		}
		if (str_contains($value, '周末')) {
			return $today->modify('next sunday')->format('Y-m-d');
		}
		if (preg_match('/(下|本|这)?(个)?(周|星期|礼拜)([一二三四五六日天])/u', $value, $m) === 1) {
			$prefix = $m[1] ?? '';
			$target = self::WEEKDAYS[$m[4]] ?? null;
			if ($target === null) {
				return null;
			}
			$current = (int)$today->format('N');
			$delta = $target - $current;
			if ($prefix === '下') {
				$delta += 7;
			} elseif ($delta < 0) {
				$delta += 7;
			}
			return $today->modify('+' . $delta . ' day')->format('Y-m-d');
		}
		if (preg_match('/^(\d{1,2})\s*(天|日)\s*(后|内|之内)$/u', $value, $m) === 1) {
			return $today->modify('+' . (int)$m[1] . ' day')->format('Y-m-d');
		}
		return null;
	}
}
