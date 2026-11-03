<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Settings;

use OCA\TodoFolder\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The entry in the left-hand list of Administration settings. A section groups one or
 * more settings forms; reuse an existing section instead when your app belongs in one.
 */
class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('待办夹');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		// The settings sidebar is a light surface, so this is the dark-on-light variant —
		// the same file Nextcloud's own sections point at (app_api, logreader,
		// files_external, privacy…). The coloured app menu keeps img/app.svg, which is white.
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
