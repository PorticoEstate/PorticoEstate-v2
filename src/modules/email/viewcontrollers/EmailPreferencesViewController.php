<?php

/**
 * EMail - Preference Views
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Renders main and extra-account settings pages and exposes available provider connection links.
 */

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailPreferencesViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	/**
	 * Initialize Twig rendering and the legacy page-layout integration.
	 */
	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('email');
	}

	/**
	 * Render main-account preferences and available provider connection links.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The main email preferences HTML response.
	 */
	public function index(Request $request, Response $response): Response
	{
		$emailPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);
		return $this->render($request, $response, '@views/preferences.twig', [
			'api_url' => \phpgw::link('/email/api/preferences', [], true),
			'save_url' => \phpgw::link('/email/api/preferences', [], true),
			'oauth_url' => $this->oauthConfigured($emailPreferences)
				? \phpgw::link('/email/oauth/microsoft/authorize', ['acctnum' => 0])
				: '',
			'google_oauth_url' => $this->googleOauthConfigured($emailPreferences)
				? \phpgw::link('/email/oauth/google/authorize', ['acctnum' => 0])
				: '',
		]);
	}

	/**
	 * Render the extra-account list page with its management API URLs.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The extra-account list HTML response.
	 */
	public function accounts(Request $request, Response $response): Response
	{
		return $this->render($request, $response, '@views/email-accounts.twig', [
			'api_url' => \phpgw::link('/email/api/preferences/accounts', [], true),
			'action_url' => \phpgw::link('/email/api/preferences/accounts/delete', [], true),
			'new_url' => \phpgw::link('/email/view/account', ['ex_acctnum' => 'add_new'], true),
		]);
	}

	/**
	 * Render an extra-account editor and available OAuth connection links.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The extra-account editor HTML response.
	 */
	public function account(Request $request, Response $response): Response
	{
		$account = (string) ($request->getQueryParams()['ex_acctnum'] ?? 'add_new');
		$emailPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);
		$accountPreferences = ctype_digit($account) ? (array) ($emailPreferences['ex_accounts'][(int) $account] ?? []) : [];
		return $this->render($request, $response, '@views/email-account-edit.twig', [
			'api_url' => \phpgw::link('/email/api/preferences/account', ['ex_acctnum' => $account], true),
			'save_url' => \phpgw::link('/email/api/preferences/account', [], true),
			'accounts_url' => \phpgw::link('/email/view/accounts', [], true),
			'account_mode' => true,
			'oauth_url' => ctype_digit($account) && (int) $account > 0 && $this->oauthConfigured($accountPreferences)
				? \phpgw::link('/email/oauth/microsoft/authorize', ['acctnum' => (int) $account])
				: '',
			'google_oauth_url' => ctype_digit($account) && (int) $account > 0 && $this->googleOauthConfigured($accountPreferences)
				? \phpgw::link('/email/oauth/google/authorize', ['acctnum' => (int) $account])
				: '',
		]);
	}

	/**
	 * Render a preferences template with shared navigation and CSRF data.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @param string $template The Twig template to render.
	 * @param array $data The response payload or template variables to render.
	 * @return Response The rendered HTML wrapped in the legacy application layout.
	 */
	private function render(Request $request, Response $response, string $template, array $data): Response
	{
		$name = (string) ($request->getAttribute('csrf_name') ?? '');
		$value = (string) ($request->getAttribute('csrf_value') ?? '');
		if ($name === '' || $value === '')
		{
			$responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
			$csrfStorage = null;
			$guard = new Guard($responseFactory, 'csrf', $csrfStorage, null, 200, 16, true);
			$request = $guard->appendNewTokenToRequest($request);
			$name = (string) $request->getAttribute('csrf_name');
			$value = (string) $request->getAttribute('csrf_value');
		}
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Preferences')]);
		$html = $this->twig->render($template, array_merge($data, [
			'layout' => '@views/_bare.twig',
			'csrf_name' => $name,
			'csrf_value' => $value,
			'extra_accounts_url' => \phpgw::link('/email/view/accounts', [], true),
			'preferences_url' => \phpgw::link('/email/view/preferences', [], true),
			'inbox_url' => \phpgw::link('/email/view/inbox', [], true),
		]));
		$response->getBody()->write($this->legacyView->render($html, ['email', 'preferences']));
		return $response->withHeader('Content-Type', 'text/html');
	}

	/**
	 * Check whether effective Microsoft account or profile credentials permit authorization.
	 *
	 * @param array $accountPreferences The selected account's saved provider settings and optional client overrides.
	 * @return bool Whether the account has a usable Microsoft OAuth configuration.
	 */
	private function oauthConfigured(array $accountPreferences): bool
	{
		$profile = \CreateObject('emailadmin.emailadmin_bo', true)->getResolvedProfileData('email');
		$profile = is_array($profile) ? $profile : [];
		if (trim((string) ($profile['microsoft_redirect_uri'] ?? '')) === '')
		{
			$profile['microsoft_redirect_uri'] = strtok(\phpgw::link('/email/oauth/microsoft/callback', [], false, true, true, true), '?');
		}
		return \App\modules\email\services\MicrosoftImapOAuth::isConfigured($profile, $accountPreferences);
	}

	/**
	 * Check whether effective Google account or profile credentials permit authorization.
	 *
	 * @param array $accountPreferences The selected account's saved provider settings and optional client overrides.
	 * @return bool Whether the account has a usable Google OAuth configuration.
	 */
	private function googleOauthConfigured(array $accountPreferences): bool
	{
		$profile = \CreateObject('emailadmin.emailadmin_bo', true)->getResolvedProfileData('email');
		if (!is_array($profile))
        {
        	return false;
        }
		if (trim((string) ($profile['google_redirect_uri'] ?? '')) === '')
		{
			$profile['google_redirect_uri'] = strtok(\phpgw::link('/email/oauth/google/callback', [], false, true, true, true), '?');
		}
		return \App\modules\email\services\GoogleMailOAuth::isConfigured($profile, $accountPreferences);
	}
}
