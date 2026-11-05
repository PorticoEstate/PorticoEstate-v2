<?php

namespace App\modules\bookingfrontend\viewcontrollers;

use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Translation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InformationScreenViewController
{
	public function picker(Request $request, Response $response): Response
	{
		$twig = new TwigHelper('bookingfrontend');
		$html = $twig->render('@views/information_screen/bookingfrontend_information_screen.twig', [
			'language' => Translation::getInstance()->get_userlang(),
			'building_search_url' => \phpgw::link('/bookingfrontend/buildings/search', [], false, false, false, true),
			'resource_list_url' => \phpgw::link('/bookingfrontend/buildings/__BUILDING_ID__/resources', [
				'results' => -1,
				'sort' => 'name',
				'short' => 1,
			], false, false, false, true),
			'information_screen_url' => \phpgw::link('/bookingfrontend/', [], false, false, false, true),
		]);

		$response->getBody()->write($html);
		return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
	}
}
