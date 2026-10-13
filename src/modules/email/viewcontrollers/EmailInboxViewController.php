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
			'folders_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uifolder.folder']),
			'search_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uisearch.form']),
			'filters_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uifilters.filters_list']),
			'preferences_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uipreferences.preferences']),
			'has_preferences' => !empty($emailPreferences),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['email', 'inbox']));
		return $response->withHeader('Content-Type', 'text/html');
	}
}
