<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Controller;

use OCA\TodoFolder\AppInfo\Application;
use OCA\TodoFolder\Service\AiClient;
use OCA\TodoFolder\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\IL10N;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only settings. No #[NoAdminRequired] anywhere in this class: the
 * deny-by-default model already makes every route admin-only.
 *
 * The API key is write-only: it goes in, only a masked hint comes back.
 */
class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private ConfigService $config,
		private AiClient $ai,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/settings')]
	public function show(): JSONResponse {
		return new JSONResponse($this->config->describeForAdmin());
	}

	#[FrontpageRoute(verb: 'PUT', url: '/api/settings')]
	public function update(
		?string $baseUrl = null,
		?string $model = null,
		?string $apiKey = null,
		?int $timeout = null,
		?bool $deckEnabled = null,
		?int $queueBatch = null,
	): JSONResponse {
		if ($baseUrl !== null) {
			$baseUrl = trim($baseUrl);
			if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
				return new JSONResponse(['error' => $this->l10n->t('The endpoint must start with http:// or https://')], Http::STATUS_BAD_REQUEST);
			}
			$this->config->setBaseUrl($baseUrl);
		}
		if ($model !== null && trim($model) !== '') {
			$this->config->setModel($model);
		}
		if ($apiKey !== null && trim($apiKey) !== '') {
			$this->config->setApiKey($apiKey);
		}
		if ($timeout !== null) {
			$this->config->setTimeout($timeout);
		}
		if ($deckEnabled !== null) {
			$this->config->setDeckEnabled($deckEnabled);
		}
		if ($queueBatch !== null) {
			$this->config->setQueueBatch($queueBatch);
		}
		return new JSONResponse($this->config->describeForAdmin());
	}

	/**
	 * One round trip against the configured endpoint, with a prompt small enough to
	 * be free and an answer that proves the JSON mode is on.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/api/settings/test')]
	public function test(): JSONResponse {
		try {
			$result = $this->ai->ping();
		} catch (\Throwable $e) {
			return new JSONResponse(['ok' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
		return new JSONResponse(['ok' => true, ...$result]);
	}
}
