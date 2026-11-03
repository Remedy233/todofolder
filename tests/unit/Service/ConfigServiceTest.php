<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Tests\Unit\Service;

use OCA\TodoFolder\Service\ConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The settings boundary: an empty endpoint means mock mode, and the API key never comes
 * back out of the server. Both are asserted here because both are easy to break by
 * accident while "just adding one more field".
 */
class ConfigServiceTest extends TestCase {
	private function configWith(array $values): ConfigService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $values[$key] ?? $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $values[$key] ?? $default
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $values[$key] ?? $default
		);
		return new ConfigService($appConfig);
	}

	public function testEmptyEndpointMeansMock(): void {
		$config = $this->configWith([]);
		$this->assertTrue($config->isMock());
		$this->assertSame('mock', $config->describeForAdmin()['mock'] ? 'mock' : 'real');
	}

	public function testConfiguredEndpointTurnsMockOff(): void {
		$config = $this->configWith([ConfigService::KEY_BASE_URL => 'https://api.deepseek.com/v1']);
		$this->assertFalse($config->isMock());
	}

	public function testTrailingSlashIsStrippedFromTheEndpoint(): void {
		// Otherwise the client would request //chat/completions.
		$config = $this->configWith([ConfigService::KEY_BASE_URL => 'https://example.test/v1/']);
		$this->assertSame('https://example.test/v1', $config->baseUrl());
	}

	public function testTheApiKeyNeverLeavesTheServer(): void {
		$config = $this->configWith([ConfigService::KEY_API_KEY => 'sk-1234567890abcdef']);
		$described = $config->describeForAdmin();

		$this->assertTrue($described['apiKeySet']);
		$this->assertArrayNotHasKey('apiKey', $described, 'the raw key must not be part of the payload');
		$this->assertStringNotContainsString('1234567890', $described['apiKeyHint']);
		$this->assertSame('sk-1…cdef', $described['apiKeyHint']);
	}

	public function testNoKeyMeansNoHint(): void {
		$config = $this->configWith([]);
		$described = $config->describeForAdmin();
		$this->assertFalse($described['apiKeySet']);
		$this->assertSame('', $described['apiKeyHint']);
	}

	public function testDefaultsAreSaneForAFreshInstall(): void {
		$config = $this->configWith([]);
		$this->assertSame('deepseek-chat', $config->model());
		$this->assertSame(60, $config->timeout());
		$this->assertSame(30, $config->maxFiles());
		$this->assertTrue($config->deckEnabled());
	}
}
