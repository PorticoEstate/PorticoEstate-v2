<?php

/**
 * EMail - Compose Views
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Renders the compose page with message context, CSRF tokens, and API endpoint URLs.
 */

namespace App\modules\email\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;

class EmailComposeViewController
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
	 * Normalize compose action and mailbox references from query parameters.
	 *
	 * @param array $query The query parameters containing compose action and mailbox references.
	 * @return array The compose context passed to the page and its API URLs.
	 */
	private function context(array $query): array
	{
		$context = [];
		foreach (['action', 'msgball', 'fldball', 'fwd_proc', 'sort', 'order', 'start'] as $key)
		{
			if (isset($query[$key]))
            {
            	$context[$key] = $query[$key];
            }
		}
		return $context;
	}

	/**
	 * Render the compose page with mailbox context and CSRF credentials.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The compose page wrapped in the legacy application layout.
	 */
	public function index(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$context = $this->context($query);
		$composeParameters = array_intersect_key($query, array_flip(['action', 'msgball', 'fldball', 'fwd_proc', 'sort', 'order', 'start', 'mailto', 'to', 'personal', 'cc', 'bcc', 'subject', 'body']));

		$returnParameters = [];
		$folderBall = (array) ($query['fldball'] ?? []);
		$messageBall = (array) ($query['msgball'] ?? []);
		if ($messageBall)
		{
			$returnParameters['fldball[acctnum]'] = $messageBall['acctnum'] ?? 0;
			$returnParameters['fldball[folder]'] = $messageBall['folder'] ?? 'INBOX';
		}
        else
		{
			$returnParameters['fldball[acctnum]'] = $folderBall['acctnum'] ?? 0;
			$returnParameters['fldball[folder]'] = $folderBall['folder'] ?? 'INBOX';
		}
		foreach (['sort', 'order', 'start', 'click_history'] as $key)
		{
			if (isset($query[$key]))
            {
            	$returnParameters[$key] = $query[$key];
            }
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('E-Mail') . ': ' . lang('Compose')]);
		$html = $this->twig->render('@views/compose.twig', [
			'layout' => '@views/_bare.twig',
			'compose_api_url' => \phpgw::link('/email/api/compose', $composeParameters, true),
			'spellcheck_url' => \phpgw::link('/email/api/spellcheck', [], true),
			'api_url' => \phpgw::link('/email/api/send', [], true),
			'draft_url' => \phpgw::link('/email/api/drafts', [], true),
			'attachments_api_url' => \phpgw::link('/email/api/attachments', [], true),
			'attachment_upload_url' => \phpgw::link('/email/api/attachments', [], true),
			'attachment_delete_url' => \phpgw::link('/email/api/attachments/delete', [], true),
			'contacts_api_url' => \phpgw::link('/email/api/addressbook', [], true),
			'return_url' => \phpgw::link('/email/view/inbox', $returnParameters, true),
			'context' => $context,
			'csrf' => $this->csrf($request),
			'to' => '',
			'cc' => '',
			'bcc' => '',
			'subject' => '',
			'body' => '',
			'has_signature' => false,
			'attach_signature' => false,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['email', 'compose']));
		return $response->withHeader('Content-Type', 'text/html');
	}

}
