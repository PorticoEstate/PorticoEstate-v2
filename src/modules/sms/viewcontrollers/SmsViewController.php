<?php

namespace App\modules\sms\viewcontrollers;

use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use Slim\Csrf\Guard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SmsViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
	private $menuSelection = 'sms';

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('sms');
	}

	private function rowsPerPage(): int
	{
		$user = Settings::getInstance()->get('user');
		return isset($user['preferences']['common']['maxmatchs']) && (int) $user['preferences']['common']['maxmatchs'] > 0
			? (int) $user['preferences']['common']['maxmatchs']
			: 10;
	}

	private function denyAccess(Response $response): Response
	{
		$response->getBody()->write(lang('Access not permitted'));
		return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
	}

	/**
	 * GET /sms/view/inbox
	 */
	public function inbox(Request $request, Response $response): Response
	{
		$this->menuSelection = 'sms::inbox';
		if (!Acl::getInstance()->check('.inbox', Acl::READ, 'sms'))
		{
			return $this->denyAccess($response);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . lang('inbox') . ': ' . lang('list inbox')]);

		$rowsPerPage = $this->rowsPerPage();

		return $this->render($request, $response, '@views/inbox/sms_inbox.twig', [
			'api_url' => \phpgw::link('/sms/inbox'),
			'send_url' => \phpgw::link('/sms/view/send', ['from' => 'inbox']),
			// redirect=true avoids the HTML-entity encoded '&amp;' between query params, since
			// this URL is consumed by JavaScript (window.open), not embedded as raw HTML markup.
			'reply_url_template' => \phpgw::link('/sms/view/send', ['from' => 'inbox', 'p_num' => '__SENDER__'], true),
			'delete_url_template' => \phpgw::link('/sms/view/inbox/__MESSAGE_ID__/delete'),
			'can_delete' => (bool) Acl::getInstance()->check('.inbox', Acl::DELETE, 'sms'),
			'can_send' => (bool) Acl::getInstance()->check('.inbox', Acl::ADD, 'sms'),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3],
		]);
	}

	/**
	 * GET /sms/view/outbox
	 */
	public function outbox(Request $request, Response $response): Response
	{
		$this->menuSelection = 'sms::outbox';
		if (!Acl::getInstance()->check('.outbox', Acl::READ, 'sms'))
		{
			return $this->denyAccess($response);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . lang('outbox') . ': ' . lang('list outbox')]);

		$rowsPerPage = $this->rowsPerPage();

		return $this->render($request, $response, '@views/outbox/sms_outbox.twig', [
			'api_url' => \phpgw::link('/sms/outbox'),
			'send_url' => \phpgw::link('/sms/view/send', ['from' => 'outbox']),
			'delete_url_template' => \phpgw::link('/sms/view/outbox/__MESSAGE_ID__/delete'),
			'can_delete' => (bool) Acl::getInstance()->check('.outbox', Acl::DELETE, 'sms'),
			'can_send' => (bool) Acl::getInstance()->check('.outbox', Acl::ADD, 'sms'),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3],
		]);
	}

	/**
	 * GET /sms/view/send
	 */
	public function send(Request $request, Response $response): Response
	{
		$this->menuSelection = 'sms::outbox';
		if (!Acl::getInstance()->check('.outbox', Acl::ADD, 'sms'))
		{
			return $this->denyAccess($response);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . lang('send sms')]);

		$query = $request->getQueryParams();
		$from = (string) ($query['from'] ?? 'inbox') === 'outbox' ? 'outbox' : 'inbox';
		$pNum = (string) ($query['p_num'] ?? '');

		$locationObj = new Locations();
		$locationId = $locationObj->get_id('sms', 'run');
		$config = \CreateObject('admin.soconfig', $locationId);
		$gatewayNumber = (string) ($config->config_data['common']['gateway_number'] ?? '');

		return $this->render($request, $response, '@views/send/sms_send.twig', [
			'gateway_number' => $gatewayNumber,
			'p_num' => $pNum,
			'max_length' => 804,
			'back_url' => \phpgw::link('/sms/view/' . $from),
		]);
	}

	/**
	 * GET /sms/view/inbox/{id}/delete
	 */
	public function deleteInbox(Request $request, Response $response, array $args): Response
	{
		$this->menuSelection = 'sms::inbox';
		if (!Acl::getInstance()->check('.inbox', Acl::DELETE, 'sms'))
		{
			return $this->denyAccess($response);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . lang('inbox') . ': ' . lang('delete')]);

		$id = (int) ($args['id'] ?? 0);

		return $this->render($request, $response, '@views/delete/sms_delete.twig', [
			'api_url' => \phpgw::link('/sms/inbox/' . $id),
			'list_url' => \phpgw::link('/sms/view/inbox'),
			'message_id' => $id,
		]);
	}

	/**
	 * GET /sms/view/outbox/{id}/delete
	 */
	public function deleteOutbox(Request $request, Response $response, array $args): Response
	{
		$this->menuSelection = 'sms::outbox';
		if (!Acl::getInstance()->check('.outbox', Acl::DELETE, 'sms'))
		{
			return $this->denyAccess($response);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . lang('outbox') . ': ' . lang('delete')]);

		$id = (int) ($args['id'] ?? 0);

		return $this->render($request, $response, '@views/delete/sms_delete.twig', [
			'api_url' => \phpgw::link('/sms/outbox/' . $id),
			'list_url' => \phpgw::link('/sms/view/outbox'),
			'message_id' => $id,
		]);
	}

	/**
	 * Render a Twig template through the legacy view wrapper, ensuring a CSRF token is present.
	 */
	private function render(Request $request, Response $response, string $template, array $data): Response
	{
		$csrfName = (string) ($request->getAttribute('csrf_name') ?? '');
		$csrfValue = (string) ($request->getAttribute('csrf_value') ?? '');
		if ($csrfName === '' || $csrfValue === '')
		{
			$csrfStorage = null;
			$csrfGuard = new Guard(new \Slim\Psr7\Factory\ResponseFactory(), 'csrf', $csrfStorage, null, 200, 16, true);
			$request = $csrfGuard->appendNewTokenToRequest($request);
			$csrfName = (string) $request->getAttribute('csrf_name');
			$csrfValue = (string) $request->getAttribute('csrf_value');
		}

		$html = $this->legacyView->render($this->twig->render($template, array_merge($data, [
			'layout' => '@views/_bare.twig',
			'csrf' => [
				'name' => $csrfName,
				'value' => $csrfValue,
			],
		])), ['sms'], $this->menuSelection);

		$response->getBody()->write($html);
		return $response->withHeader('Content-Type', 'text/html');
	}
}
