<?php

namespace App\modules\email\viewcontrollers;

use App\modules\email\services\EmailAccountsSerializer;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailInboxViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('email');
	}

	private function csrf(Request $request): array
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
		return ['name' => $name, 'value' => $value];
	}

	public function index(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$fakeFolderBall = trim((string) ($query['fldball_fake_uri'] ?? ''));
		if ($fakeFolderBall !== '')
		{
			parse_str(ltrim($fakeFolderBall, '&'), $selection);
			$account = filter_var($selection['acctnum'] ?? null, FILTER_VALIDATE_INT);
			$folder = $selection['folder'] ?? null;
			if ($account !== false && $account >= 0 && is_string($folder) && $folder !== '')
			{
				$query['fldball'] = ['acctnum' => $account, 'folder' => $folder];
				unset($query['fldball_fake_uri']);
				$location = \phpgw::link('/email/view/inbox', $query, true);
				return $response->withStatus(302)->withHeader('Location', $location);
			}
		}

		$user = (array) Settings::getInstance()->get('user');
		$emailPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);
		$accounts = EmailAccountsSerializer::serialize([
			0 => [
				'acctnum' => 0,
				'status' => 'enabled',
				'account_name' => $emailPreferences['account_name'] ?? '',
				'fullname' => $user['fullname'] ?? lang('Account') . ' 0',
			],
		] + (array) ($emailPreferences['ex_accounts'] ?? []));
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Inbox')]);

		$html = $this->twig->render('@views/inbox.twig', [
			'layout' => '@views/_bare.twig',
			'accounts' => array_values(array_filter($accounts, static fn($account) => $account['status'] === 'enabled')),
			'active_account' => (int) ($query['fldball']['acctnum'] ?? 0),
			'api_url' => \phpgw::link('/email/api/inbox', [], true),
			'folders_api_url' => \phpgw::link('/email/api/folders', [], true),
			'move_url' => \phpgw::link('/email/api/messages/move', [], true),
			'seen_url' => \phpgw::link('/email/api/messages/seen', [], true),
			'action_url' => \phpgw::link('/email/api/messages/action', [], true),
			'csrf' => $this->csrf($request),
			'compose_url' => \phpgw::link('/email/view/compose', [], true),
			'folders_url' => \phpgw::link('/email/view/folders', array_filter([
				'fldball[acctnum]' => $request->getQueryParams()['fldball']['acctnum'] ?? null,
				'fldball[folder]' => $request->getQueryParams()['fldball']['folder'] ?? null,
			], static fn($value) => $value !== null && $value !== ''), true),
			'search_url' => \phpgw::link('/email/view/search', array_filter([
				'fldball[acctnum]' => $request->getQueryParams()['fldball']['acctnum'] ?? null,
				'fldball[folder]' => $request->getQueryParams()['fldball']['folder'] ?? null,
			], static fn($value) => $value !== null && $value !== ''), true),
			'filters_url' => \phpgw::link('/email/view/filters', array_filter([
				'fldball[acctnum]' => $request->getQueryParams()['fldball']['acctnum'] ?? null,
				'fldball[folder]' => $request->getQueryParams()['fldball']['folder'] ?? null,
			], static fn($value) => $value !== null && $value !== ''), true),
			'preferences_url' => \phpgw::link('/email/view/preferences', [], true),
			'has_preferences' => !empty($emailPreferences),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['email', 'inbox']));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function message(Request $request, Response $response): Response
	{
		$msgball = (array) ($request->getQueryParams()['msgball'] ?? []);
		$account = $msgball['acctnum'] ?? null;
		$folder = $msgball['folder'] ?? null;
		$messageNumber = $msgball['msgnum'] ?? null;
		if (!is_numeric($account) || (int) $account < 0 || !is_string($folder) || $folder === '' || !is_numeric($messageNumber) || (int) $messageNumber < 0)
		{
			return $response->withStatus(400);
		}

		$backParameters = [
			'fldball[acctnum]' => (int) $account,
			'fldball[folder]' => $folder,
		];
		$query = $request->getQueryParams();
		foreach (['sort', 'order', 'start', 'click_history'] as $parameter)
		{
			if (isset($query[$parameter]))
            {
            	$backParameters[$parameter] = $query[$parameter];
            }
		}
		$composeParameters = [
			'msgball[acctnum]' => (int) $account,
			'msgball[folder]' => $folder,
			'msgball[msgnum]' => (int) $messageNumber,
		];
		foreach (['sort', 'order', 'start', 'click_history'] as $parameter)
		{
			if (isset($query[$parameter]))
            {
            	$composeParameters[$parameter] = $query[$parameter];
            }
		}
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Message')]);
		$html = $this->twig->render('@views/message.twig', [
			'layout' => '@views/_bare.twig',
			'action_url' => \phpgw::link('/email/api/messages/action', [], true),
			'folders_url' => \phpgw::link('/email/api/folders', ['fldball[acctnum]' => (int) $account], true),
			'edit_url' => \phpgw::link('/email/view/compose', ['action' => 'edit'] + $composeParameters, true),
			'seen_url' => \phpgw::link('/email/api/messages/seen', [], true),
			'csrf' => $this->csrf($request),
			'api_url' => \phpgw::link('/email/api/message', $composeParameters, true),
			'back_url' => \phpgw::link('/email/view/inbox', $backParameters, true),
			'reply_url' => \phpgw::link('/email/view/compose', ['action' => 'reply'] + $composeParameters, true),
			'reply_all_url' => \phpgw::link('/email/view/compose', ['action' => 'replyall'] + $composeParameters, true),
			'forward_url' => \phpgw::link('/email/view/compose', ['action' => 'forward'] + $composeParameters, true),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['email', 'message']));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function folders(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$folderBall = (array) ($query['fldball'] ?? []);
		$csrf = $this->csrf($request);
		$apiParameters = [];
		$backParameters = [];
		foreach (['acctnum', 'folder'] as $key)
		{
			if (isset($folderBall[$key]))
			{
				$apiParameters['fldball[' . $key . ']'] = $folderBall[$key];
				$backParameters['fldball[' . $key . ']'] = $folderBall[$key];
			}
		}
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Folders')]);
		$html = $this->twig->render('@views/folders.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/folders', $apiParameters, true),
			'action_url' => \phpgw::link('/email/api/folders/action', [], true),
			'csrf' => $csrf,
			'account' => (int) ($folderBall['acctnum'] ?? 0),
			'inbox_url' => \phpgw::link('/email/view/inbox', $backParameters, true),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['email', 'folders']));
		return $response->withHeader('Content-Type', 'text/html');
	}

}
