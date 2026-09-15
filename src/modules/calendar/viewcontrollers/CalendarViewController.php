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
				'add' => \phpgw::link('/calendar/view/event/new'),
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

	public function add(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$date = preg_replace('/[^0-9]/', '', (string)($query['date'] ?? date('Ymd')));
		if (strlen($date) !== 8)
		{
			$date = date('Ymd');
		}

		$hour = (int)($query['hour'] ?? date('H'));
		$minute = (int)($query['minute'] ?? 0);
		$start = sprintf('%s-%s-%sT%02d:%02d', substr($date, 0, 4), substr($date, 4, 2), substr($date, 6, 2), $hour, $minute);
		$endTimestamp = strtotime($start) + 3600;
		$end = date('Y-m-d\TH:i', $endTimestamp);
		$calendar = \CreateObject('calendar.bocalendar', 1);
		$categoryOptions = $calendar->cat->return_array('all', 0, false);
		$categoryOptions = is_array($categoryOptions) ? $categoryOptions : [];
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('Add')]);

		$html = $this->twig->render('@views/calendar/event_form.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/calendar/events'),
			'list_url' => \phpgw::link('/calendar/view/day', ['date' => $date]),
			'categories' => $categoryOptions,
			'recur_types' => $calendar->rpt_type,
			'recur_days' => $calendar->rpt_day,
			'values' => [
				'start' => $start,
				'end' => $end,
				'owner_name' => $calendar->contacts->get_name_of_person_id($calendar->owner),
				'alarm_days' => $calendar->prefs['calendar']['default_email_days'] ?? 0,
				'alarm_hours' => $calendar->prefs['calendar']['default_email_hours'] ?? 0,
				'alarm_minutes' => $calendar->prefs['calendar']['default_email_min'] ?? 0,
			],
		]);

		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}