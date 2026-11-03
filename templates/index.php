<?php
/**
 * SPDX-FileCopyrightText: 2026 TodoFolder contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The app's page. The markup here is only a mount point: the UI is the Vue app in
 * src/App.vue, built by Vite into js/todofolder-main.mjs, which Util::addScript() finds
 * before any .js fallback. Inline <script> is blocked by the CSP, hence the file.
 *
 * No #app-content wrapper: the Vue root is NcAppNavigation + NcAppContent, which have to
 * be direct children of the server's #content for Nextcloud's layout to place them. The
 * script mounts on #content and replaces whatever is inside it.
 */

use OCA\TodoFolder\AppInfo\Application;

\OCP\Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
\OCP\Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');
?>

<div id="todofolder-app" data-testid="todofolder-app"></div>
<noscript>
	<p>待办夹需要 JavaScript。</p>
</noscript>
