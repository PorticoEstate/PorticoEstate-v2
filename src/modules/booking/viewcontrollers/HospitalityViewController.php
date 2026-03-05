<?php

namespace App\modules\booking\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class HospitalityViewController
{
	protected Acl $acl;
	protected TwigHelper $twig;
	protected LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->acl = Acl::getInstance();
		// LegacyViewHelper must be created BEFORE TwigHelper so that template_set
		// is resolved from user prefs before the DesignSystem singleton is created.
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('booking');
	}

	public function index(Request $request, Response $response): Response
	{
		try {
			$permissions = [
				'read'   => $this->acl->check('.application', Acl::READ, 'booking'),
				'create' => $this->acl->check('.application', Acl::ADD, 'booking'),
				'write'  => $this->acl->check('.application', Acl::EDIT, 'booking'),
				'delete' => $this->acl->check('.application', Acl::DELETE, 'booking'),
			];

			$flags = Settings::getInstance()->get('flags');
			$flags['app_header'] = lang('booking') . '::' . lang('Hospitality');
			Settings::getInstance()->set('flags', $flags);

			$componentHtml = $this->twig->render('@views/hospitality/list/hospitality_list.twig', [
				'layout'      => '@views/_bare.twig',
				'permissions' => $permissions,
			]);

			$html = $this->legacyView->render($componentHtml, 'booking', 'booking::hospitality');

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading hospitality list: ' . $e->getMessage()],
				500
			);
		}
	}

	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing hospitality ID'], 400);
		}

		try {
			$canWrite = $this->acl->check('.application', Acl::EDIT, 'booking');
			$userSettings = Settings::getInstance()->get('user');

			$flags = Settings::getInstance()->get('flags');
			$flags['app_header'] = lang('booking') . '::' . lang('Hospitality');
			Settings::getInstance()->set('flags', $flags);

			$componentHtml = $this->twig->render('@views/hospitality/show/hospitality_show.twig', [
				'layout'         => '@views/_bare.twig',
				'hospitality_id' => $id,
				'can_write'      => $canWrite,
				'account_id'     => (int)($userSettings['account_id'] ?? 0),
				'account_name'   => $userSettings['fullname'] ?? ($userSettings['account_lid'] ?? 'Unknown'),
			]);

			$html = $this->legacyView->render($componentHtml, 'booking', 'booking::hospitality');

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading hospitality page: ' . $e->getMessage()],
				500
			);
		}
	}
}
