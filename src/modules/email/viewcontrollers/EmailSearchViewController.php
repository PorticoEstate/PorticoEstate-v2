<?php

/**
 * EMail - Search Views
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Renders the mailbox search page with account selection, CSRF tokens, and search API URLs.
 */

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailSearchViewController
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
	 * Read request CSRF credentials, creating them when necessary.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @return array The CSRF token name and value used by the rendered form.
	 */
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

	/**
	 * Render the mailbox search page and its account-scoped API URLs.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The search-page HTML response.
	 */
	public function index(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$folderBall = (array) ($query['fldball'] ?? []);
		$account = isset($folderBall['acctnum']) && is_numeric($folderBall['acctnum']) ? (int) $folderBall['acctnum'] : 0;
		$folder = (string) ($folderBall['folder'] ?? 'INBOX');
		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Search')]);

		$html = $this->twig->render('@views/search.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/email/api/search', [], true),
			'folders_api_url' => \phpgw::link('/email/api/folders', ['fldball[acctnum]' => $account], true),
			'inbox_url' => \phpgw::link('/email/view/inbox', ['fldball[acctnum]' => $account, 'fldball[folder]' => $folder], true),
			'account' => $account,
			'preferred_folder' => $folder,
			'csrf' => $this->csrf($request),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['email', 'search']));
		return $response->withHeader('Content-Type', 'text/html');
	}
}
