<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Settings;

use OCA\TodoFolder\AppInfo\Application;
use OCA\TodoFolder\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin form. Values travel to the frontend as initial state (never printed into the
 * HTML); saving goes through SettingsController, which is admin-only by default.
 */
class AdminSettings implements ISettings {
	public const INITIAL_STATE = 'config';

	public function __construct(
		private IInitialState $initialState,
		private ConfigService $config,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState(self::INITIAL_STATE, $this->config->describeForAdmin());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
