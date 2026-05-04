<?php

namespace App\modules\booking\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

/**
 * Renders the application show page shell.
 *
 * The ViewController NEVER fetches data — it only renders the template shell
 * with the application ID. All data loading happens client-side via JS → REST API.
 *
 * Access control: The AccessVerifier middleware on the /booking group already
 * ensures the user has 'run' permission on the booking module.
 */
class ApplicationViewController
{
	protected TwigHelper $twig;
	protected LegacyViewHelper $legacyView;

	public function __construct()
	{
		// LegacyViewHelper must be created BEFORE TwigHelper so that template_set
		// is resolved from user prefs before the DesignSystem singleton is created.
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('booking');
	}

	/**
	 * GET /booking/view/applications/{id}
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		$serverSettings = Settings::getInstance()->get('server');
		$templateSet = $serverSettings['template_set'] ?? 'digdir';
		if ($templateSet !== 'digdir') {
			$legacyUrl = '/?menuaction=booking.uiapplication.show&id=' . $id;
			return $response->withHeader('Location', $legacyUrl)->withStatus(302);
		}

		try {
			$componentHtml = $this->twig->render('@views/application/show/application_show.twig', [
				'layout'         => '@views/_bare.twig',
				'application_id' => $id,
			]);

			$html = $this->legacyView->render(
				$componentHtml,
				['booking', 'applications']
			);

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading application page: ' . $e->getMessage()],
				500
			);
		}
	}
}
