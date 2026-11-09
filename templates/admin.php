<?php
/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin settings mount point. Values are provided as initial state by
 * Settings\AdminSettings; the form itself is src/AdminSettings.vue.
 */

use OCA\TodoFolder\AppInfo\Application;

\OCP\Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
\OCP\Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');
\OCP\Util::addTranslations(Application::APP_ID);
?>

<div id="todofolder-admin-app" class="todofolder-admin-root" data-testid="todofolder-admin"></div>
