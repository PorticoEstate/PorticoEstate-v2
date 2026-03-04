<?php

namespace App\modules\booking\viewcontrollers;

use App\modules\booking\repositories\PermissionRepository;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\AuthorizationService;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class ConfigViewController
{
	protected AuthorizationService $authService;
	protected TwigHelper $twig;
	protected LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->authService = new AuthorizationService(new PermissionRepository());
		// LegacyViewHelper must be created BEFORE TwigHelper so that template_set
		// is resolved from user prefs before the DesignSystem singleton is created.
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('booking');
	}

	public function highlightedBuildings(Request $request, Response $response): Response
	{
		try {
			if (!$this->authService->isAdminForApp('booking')) {
				return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
			}

			$componentHtml = $this->twig->render('@views/config/highlighted_buildings/highlighted_buildings.twig', [
				'layout' => '@views/_bare.twig',
			]);

			$html = $this->legacyView->render($componentHtml, ['admin', 'bookingfrontend', 'bookingfrontend.highlighted_buildings']);
//			admin::bookingfrontend::multi_domain
			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading highlighted buildings config: ' . $e->getMessage()],
				500
			);
		}
	}
}
