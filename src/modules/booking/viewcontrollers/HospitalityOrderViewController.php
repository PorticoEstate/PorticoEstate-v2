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

class HospitalityOrderViewController
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

	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing order ID'], 400);
		}

		try {
			$canWrite = $this->acl->check('.application', Acl::EDIT, 'booking');
			$userSettings = Settings::getInstance()->get('user');

			$flags = Settings::getInstance()->get('flags');
			$flags['app_header'] = lang('booking') . '::' . lang('booking.purchase_orders');
			Settings::getInstance()->set('flags', $flags);

			$componentHtml = $this->twig->render('@views/hospitality/order_show/order_show.twig', [
				'layout'     => '@views/_bare.twig',
				'order_id'   => $id,
				'can_write'  => $canWrite,
				'account_id' => (int)($userSettings['account_id'] ?? 0),
				'account_name' => $userSettings['fullname'] ?? ($userSettings['account_lid'] ?? 'Unknown'),
			]);

			$html = $this->legacyView->render($componentHtml, 'booking', 'booking::hospitality');

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading order page: ' . $e->getMessage()],
				500
			);
		}
	}
}
