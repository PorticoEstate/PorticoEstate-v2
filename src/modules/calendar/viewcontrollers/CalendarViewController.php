<?php

namespace App\modules\calendar\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CalendarViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
	private array $views = ['day', 'week', 'week-new', 'month', 'year'];

	public function __construct()
	{
		$this->twig = new TwigHelper('calendar');
		$this->legacyView = new LegacyViewHelper();
	}

	private function resolveView(Request $request, array $args): string
	{
		$view = (string)($args['view'] ?? '');
		if (!$view && preg_match('#/calendar/view/([^/?]+)#', $request->getUri()->getPath(), $matches))
		{
			$view = $matches[1];
		}

		return in_array($view, $this->views, true) ? $view : 'month';
	}

	public function index(Request $request, Response $response, array $args = []): Response
	{
		$view = $this->resolveView($request, $args);
		$date = (string)($request->getQueryParams()['date'] ?? date('Ymd'));
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar')]);

		$html = $this->twig->render('@views/calendar/index.twig', [
			'layout' => '@views/_bare.twig',
			'view' => $view,
			'view_label' => lang($view === 'week-new' ? 'Week Detailed' : ucfirst($view)),
			'date' => $date,
			'api_url' => \phpgw::link('/calendar/events'),
			'links' => [
				'day' => \phpgw::link('/calendar/view/day'),
				'week' => \phpgw::link('/calendar/view/week'),
				'week_new' => \phpgw::link('/calendar/view/week-new'),
				'month' => \phpgw::link('/calendar/view/month'),
				'year' => \phpgw::link('/calendar/view/year'),
				'add' => \phpgw::link('/index.php', ['menuaction' => 'calendar.uicalendar.add']),
			],
		]);

		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function event(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		$date = (string)($request->getQueryParams()['date'] ?? date('Ymd'));
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('View')]);

		$html = $this->twig->render('@views/calendar/event.twig', [
			'layout' => '@views/_bare.twig',
			'event_id' => $id,
			'date' => $date,
			'api_url' => \phpgw::link('/calendar/events/' . $id),
			'list_url' => \phpgw::link('/calendar/view/month', ['date' => $date]),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}