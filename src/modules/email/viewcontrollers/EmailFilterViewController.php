<?php

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailFilterViewController
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
		$query = $request->getQueryParams();
		$account = is_numeric($query['fldball']['acctnum'] ?? null) ? (int) $query['fldball']['acctnum'] : 0;
		$folder = (string) ($query['fldball']['folder'] ?? 'INBOX');
		$baseParameters = ['fldball[acctnum]' => $account, 'fldball[folder]' => $folder];
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Filters')]);
		$html = $this->twig->render('@views/filters.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/filters'),
			'new_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uifilters.filters_edit', 'filter_num' => 'add_new']),
			'run_url' => \phpgw::link('/index.php', ['menuaction' => 'email.bofilters.do_filter']),
			'test_url' => \phpgw::link('/index.php', ['menuaction' => 'email.bofilters.do_filter', 'filter_test' => 1]),
			'inbox_url' => \phpgw::link('/email/view/inbox', $baseParameters),
			'legacy_list_url' => \phpgw::link('/index.php', ['menuaction' => 'email.uifilters.filters_list'] + $baseParameters),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['email', 'filters']));
		return $response->withHeader('Content-Type', 'text/html');
	}
}
