<?php

namespace App\modules\calendar\controllers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CalendarImportController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->twig = new TwigHelper('calendar');
		$this->legacyView = new LegacyViewHelper();
	}

	public function import(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$errorMessage = '';
		$uploadedFiles = $request->getUploadedFiles();
		$uploadedFile = $uploadedFiles['uploadedfile'] ?? null;

		if ($request->getMethod() === 'POST')
		{
			if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK)
			{
				$errorMessage = lang('You must select a [iv]Cal. (*.[iv]cs)');
			}
			else
			{
				$contents = $uploadedFile->getStream()->getContents();
				$boicalendar = \CreateObject('calendar.boicalendar');
				$eventId = $boicalendar->import(
					preg_split('/\r\n|\r|\n/', $contents),
					true
				);

				if ($eventId)
				{
					return $response
						->withHeader('Location', \phpgw::link('/calendar/view/event/' . (int)$eventId))
						->withStatus(303);
				}

				$errorMessage = lang('The iCalendar file could not be imported.');
			}
		}

		$html = $this->twig->render('@views/calendar/import.twig', [
			'layout' => '@views/_bare.twig',
			'action_url' => \phpgw::link('/calendar/view/import'),
			'error_message' => $errorMessage,
			'show_file_error' => strtoupper((string)($query['action'] ?? '')) === 'GETFILE',
		]);

		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}
