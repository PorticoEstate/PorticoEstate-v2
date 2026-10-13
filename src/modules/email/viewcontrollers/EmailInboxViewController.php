<?php

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailInboxViewController
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
		$user = (array) Settings::getInstance()->get('user');
		$emailPreferences = (array) ($user['preferences']['email'] ?? []);
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Inbox')]);

		$html = $this->twig->render('@views/inbox.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/inbox'),
			'compose_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uicompose.compose']),
			'folders_url' => \phpgw::link('/email/view/folders', array_filter([
				'fldball[acctnum]' => $request->getQueryParams()['fldball']['acctnum'] ?? null,
				'fldball[folder]' => $request->getQueryParams()['fldball']['folder'] ?? null,
			], static fn($value) => $value !== null && $value !== '')),
			'search_url' => \phpgw::link('/email/view/search', array_filter([
				'fldball[acctnum]' => $request->getQueryParams()['fldball']['acctnum'] ?? null,
				'fldball[folder]' => $request->getQueryParams()['fldball']['folder'] ?? null,
			], static fn($value) => $value !== null && $value !== '')),
			'filters_url' => \phpgw::link('/email/view/filters', array_filter([
				'fldball[acctnum]' => $request->getQueryParams()['fldball']['acctnum'] ?? null,
				'fldball[folder]' => $request->getQueryParams()['fldball']['folder'] ?? null,
			], static fn($value) => $value !== null && $value !== '')),
			'preferences_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uipreferences.preferences']),
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
			if (isset($query[$parameter])) $backParameters[$parameter] = $query[$parameter];
		}
		$composeParameters = [
			'msgball[acctnum]' => (int) $account,
			'msgball[folder]' => $folder,
			'msgball[msgnum]' => (int) $messageNumber,
		];
		foreach (['sort', 'order', 'start', 'click_history'] as $parameter)
		{
			if (isset($query[$parameter])) $composeParameters[$parameter] = $query[$parameter];
		}
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Message')]);
		$html = $this->twig->render('@views/message.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/message', [
				'msgball[acctnum]' => (int) $account,
				'msgball[folder]' => $folder,
				'msgball[msgnum]' => (int) $messageNumber,
			]),
			'back_url' => \phpgw::link('/email/view/inbox', $backParameters),
			'reply_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uicompose.compose', 'action' => 'reply'] + $composeParameters),
			'reply_all_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uicompose.compose', 'action' => 'replyall'] + $composeParameters),
			'forward_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uicompose.compose', 'action' => 'forward'] + $composeParameters),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['email', 'message']));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function folders(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$folderBall = (array) ($query['fldball'] ?? []);
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
			'api_url' => \phpgw::link('/email/api/folders', $apiParameters),
			'inbox_url' => \phpgw::link('/email/view/inbox', $backParameters),
			'manage_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uifolder.folder'] + $backParameters),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['email', 'folders']));
		return $response->withHeader('Content-Type', 'text/html');
	}

}
