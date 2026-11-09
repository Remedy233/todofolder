<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TodoFolder\Controller;

use OCA\TodoFolder\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/**
 * Routes are declared as PHP attributes on the methods, which is the current idiom;
 * appinfo/routes.php still works and is what older apps use.
 *
 * Access is deny-by-default: without #[NoAdminRequired] a route is admin-only, and
 * every route requires a logged-in user unless it carries #[PublicPage].
 */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $url,
		private IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The app's page. #[NoCSRFRequired] is needed because the browser navigates here
	 * directly, without a token; never put it on a state-changing route.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// Nextcloud's tab icon comes from the theming app: it renders an .ico from the app
		// icon and caches it server side per (app, primary colour), under a URL that never
		// changes — so an icon update stays invisible for a long time. Declaring the icons
		// here gives them a URL that carries the app version, which both the browser and the
		// server cache respect. SVG first (crisp everywhere), PNG as the Safari/iOS fallback:
		// those never render an SVG favicon and would otherwise keep the themed one.
		$version = $this->appManager->getAppVersion(Application::APP_ID);
		Util::addHeader('link', [
			'rel' => 'icon',
			'type' => 'image/svg+xml',
			'sizes' => 'any',
			'href' => $this->url->linkTo(Application::APP_ID, 'img/favicon.svg') . '?v=' . $version,
		]);
		Util::addHeader('link', [
			'rel' => 'icon',
			'type' => 'image/png',
			'sizes' => '128x128',
			'href' => $this->url->linkTo(Application::APP_ID, 'img/favicon.png') . '?v=' . $version,
		]);
		Util::addHeader('link', [
			'rel' => 'apple-touch-icon',
			'sizes' => '128x128',
			'href' => $this->url->linkTo(Application::APP_ID, 'img/favicon.png') . '?v=' . $version,
		]);

		// The Vue bundle calls t() with English source strings; this loads l10n/<lang>.js
		// for the current user, so a Chinese user reads Chinese and everybody else English.
		Util::addTranslations(Application::APP_ID);

		return new TemplateResponse(Application::APP_ID, 'index');
	}

	/**
	 * A JSON route the page calls. Same-origin requests from the page carry the CSRF
	 * token automatically, so this one does not waive it.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/whoami')]
	public function whoami(): JSONResponse {
		$user = $this->userSession->getUser();
		return new JSONResponse([
			'user' => $user?->getUID(),
			'displayName' => $user?->getDisplayName(),
		]);
	}
}
