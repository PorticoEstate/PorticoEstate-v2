<?php

namespace App\modules\calendar\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomFieldsViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->twig = new TwigHelper('calendar');
		$this->legacyView = new LegacyViewHelper();
	}

	public function index(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('Custom fields and sorting')]);
		$html = $this->twig->render('@views/custom_fields/index.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/calendar/custom-fields'),
			'admin_url' => \phpgw::link('/admin/'),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['calendar', 'custom_fields'], 'calendar::custom_fields'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}