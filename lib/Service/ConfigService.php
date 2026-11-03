<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Service;

use OCA\TodoFolder\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Every tunable in one place. Values live in appconfig, which is what occ writes and
 * what the admin settings form edits; nothing here reads the environment.
 *
 * An empty base_url means "no model configured" and switches the analysis pipeline to
 * the deterministic MockAiClient, so the whole chain can be verified before an API key
 * exists.
 */
class ConfigService {
	public const KEY_BASE_URL = 'ai.base_url';
	public const KEY_MODEL = 'ai.model';
	public const KEY_API_KEY = 'ai.api_key';
	public const KEY_TIMEOUT = 'ai.timeout';
	public const KEY_MAX_FILES = 'limits.max_files';
	public const KEY_MAX_FILE_BYTES = 'limits.max_file_bytes';
	public const KEY_MAX_CHARS = 'limits.max_chars';
	public const KEY_DECK_ENABLED = 'deck.enabled';
	public const KEY_DECK_LABELS = 'deck.sync_labels';
	public const KEY_DECK_ASSIGN = 'deck.assign_owner';
	public const KEY_DECK_LINKS = 'deck.link_materials';
	public const KEY_DECK_ATTACH = 'deck.attach_folder';
	public const KEY_DECK_PRUNE = 'deck.prune_labels';
	public const KEY_QUEUE_BATCH = 'queue.batch';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function baseUrl(): string {
		return rtrim($this->appConfig->getValueString(Application::APP_ID, self::KEY_BASE_URL, ''), '/');
	}

	public function model(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_MODEL, 'deepseek-chat');
	}

	public function apiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_API_KEY, '');
	}

	public function timeout(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_TIMEOUT, 60);
	}

	public function maxFiles(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_MAX_FILES, 30);
	}

	public function maxFileBytes(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_MAX_FILE_BYTES, 262144);
	}

	public function maxChars(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_MAX_CHARS, 12000);
	}

	public function deckEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DECK_ENABLED, true);
	}

	/**
	 * The AI tags are mirrored as Deck labels on the card's board.
	 */
	public function deckSyncLabels(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DECK_LABELS, true);
	}

	/**
	 * The workspace owner is assigned to the card.
	 */
	public function deckAssignOwner(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DECK_ASSIGN, true);
	}

	/**
	 * Labels whose tag disappeared from the task are removed from the card again.
	 */
	public function deckPruneLabels(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DECK_PRUNE, true);
	}

	/**
	 * The task folder is attached to the card as a Deck file attachment (a DECK share).
	 */
	public function deckAttachFolder(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DECK_ATTACH, true);
	}

	/**
	 * The task folder and its files are linked in the card description.
	 */
	public function deckLinkMaterials(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DECK_LINKS, true);
	}

	public function queueBatch(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_QUEUE_BATCH, 5);
	}

	/**
	 * No endpoint configured: use the mock so the pipeline still works end to end.
	 */
	public function isMock(): bool {
		return $this->baseUrl() === '';
	}

	public function setBaseUrl(string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_BASE_URL, trim($value));
	}

	public function setModel(string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_MODEL, trim($value));
	}

	public function setApiKey(string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_API_KEY, trim($value));
	}

	public function setTimeout(int $value): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_TIMEOUT, max(5, $value));
	}

	public function setDeckEnabled(bool $value): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_DECK_ENABLED, $value);
	}

	public function setQueueBatch(int $value): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_QUEUE_BATCH, max(1, $value));
	}

	/**
	 * What the settings form may see: the key itself never leaves the server.
	 */
	public function describeForAdmin(): array {
		$key = $this->apiKey();
		return [
			'baseUrl' => $this->baseUrl(),
			'model' => $this->model(),
			'apiKeySet' => $key !== '',
			'apiKeyHint' => $key === '' ? '' : substr($key, 0, 4) . '…' . substr($key, -4),
			'timeout' => $this->timeout(),
			'maxFiles' => $this->maxFiles(),
			'maxFileBytes' => $this->maxFileBytes(),
			'maxChars' => $this->maxChars(),
			'deckEnabled' => $this->deckEnabled(),
			'queueBatch' => $this->queueBatch(),
			'mock' => $this->isMock(),
		];
	}
}
