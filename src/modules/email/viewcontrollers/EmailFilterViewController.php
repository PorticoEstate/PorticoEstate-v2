<?php

/**
 * EMail - Filter Views
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Renders the filter list and editor pages with account context and API configuration.
 */

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailFilterViewController
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
		$account = is_numeric($query['fldball']['acctnum'] ?? null) ? (int) $query['fldball']['acctnum'] : 0;
		$folder = (string) ($query['fldball']['folder'] ?? 'INBOX');
		$baseParameters = ['fldball[acctnum]' => $account, 'fldball[folder]' => $folder];
		$csrf = $this->csrf($request);
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Filters')]);
		$html = $this->twig->render('@views/filters.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/filters', [], true),
			'action_url' => \phpgw::link('/email/api/filters/action', [], true),
			'run_url' => \phpgw::link('/email/api/filters/run', [], true),
			'csrf' => $csrf,
			'new_url' => \phpgw::link('/email/view/filter', ['filter_num' => 'add_new'], true),
			'inbox_url' => \phpgw::link('/email/view/inbox', $baseParameters, true),
			'legacy_list_url' => \phpgw::link('/email/view/filters', $baseParameters, true),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['email', 'filters']));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function edit(Request $request, Response $response): Response
	{
		$filterNum = (string) ($request->getQueryParams()['filter_num'] ?? 'add_new');
		$csrf = $this->csrf($request);
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Edit Filter')]);
		$html = $this->twig->render('@views/filter-edit.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/filters/editor', ['filter_num' => $filterNum], true),
			'folders_api_url' => \phpgw::link('/email/api/folders', [], true),
			'save_url' => \phpgw::link('/email/api/filters/save', [], true),
			'run_url' => \phpgw::link('/email/api/filters/run', [], true),
			'csrf' => $csrf,
			'filters_url' => \phpgw::link('/email/view/filters', [], true),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['email', 'filters']));
		return $response->withHeader('Content-Type', 'text/html');
	}
}
