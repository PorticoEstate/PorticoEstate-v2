<?php

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailPreferencesViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('email');
	}

	public function index(Request $request, Response $response): Response
	{
		return $this->render($request, $response, '@views/preferences.twig', [
			'api_url' => \phpgw::link('/email/api/preferences', [], true),
			'save_url' => \phpgw::link('/email/api/preferences', [], true),
			'oauth_url' => $this->oauthConfigured()
				? \phpgw::link('/email/oauth/microsoft/authorize', ['acctnum' => 0])
				: '',
		]);
	}

	public function accounts(Request $request, Response $response): Response
	{
		return $this->render($request, $response, '@views/email-accounts.twig', [
			'api_url' => \phpgw::link('/email/api/preferences/accounts', [], true),
			'action_url' => \phpgw::link('/email/api/preferences/accounts/delete', [], true),
			'edit_url' => \phpgw::link('/email/view/account', [], true),
			'new_url' => \phpgw::link('/email/view/account', ['ex_acctnum' => 'add_new'], true),
		]);
	}

	public function account(Request $request, Response $response): Response
	{
		$account = (string) ($request->getQueryParams()['ex_acctnum'] ?? 'add_new');
		return $this->render($request, $response, '@views/email-account-edit.twig', [
			'api_url' => \phpgw::link('/email/api/preferences/account', ['ex_acctnum' => $account], true),
			'save_url' => \phpgw::link('/email/api/preferences/account', [], true),
			'accounts_url' => \phpgw::link('/email/view/accounts', [], true),
			'account_mode' => true,
			'oauth_url' => ctype_digit($account) && (int) $account > 0 && $this->oauthConfigured()
				? \phpgw::link('/email/oauth/microsoft/authorize', ['acctnum' => (int) $account])
				: '',
		]);
	}

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

	private function oauthConfigured(): bool
	{
		$profile = \CreateObject('emailadmin.emailadmin_bo', true)->getResolvedProfileData('felamimail');
		return is_array($profile) && \App\modules\email\services\MicrosoftImapOAuth::isConfigured($profile);
	}
}
