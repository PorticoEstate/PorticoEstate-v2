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

	private function formContext(Request $request, int $id = 0): array
	{
		$query = $request->getQueryParams();
		$date = preg_replace('/[^0-9]/', '', (string)($query['date'] ?? date('Ymd')));
		if (strlen($date) !== 8)
		{
			$date = date('Ymd');
		}

		$calendar = \CreateObject('calendar.bocalendar', 1);
		$event = $id ? (array)$calendar->read_entry($id) : [];
		$hour = (int)($query['hour'] ?? date('H'));
		$minute = (int)($query['minute'] ?? 0);
		$start = sprintf('%s-%s-%sT%02d:%02d', substr($date, 0, 4), substr($date, 4, 2), substr($date, 6, 2), $hour, $minute);
		$end = date('Y-m-d\TH:i', strtotime($start) + 3600);
		if (!empty($event['start']))
		{
			$start = date('Y-m-d\TH:i', $calendar->maketime($event['start']) - \phpgwapi_datetime::user_timezone());
		}
		if (!empty($event['end']))
		{
			$end = date('Y-m-d\TH:i', $calendar->maketime($event['end']) - \phpgwapi_datetime::user_timezone());
		}

		$categoryOptions = $calendar->cat->return_array('all', 0, false);
		$categoryOptions = is_array($categoryOptions) ? $categoryOptions : [];
		$participantCategories = \CreateObject('phpgwapi.categories');
		$participantCategories->app_name = 'addressbook';
		$participantCategoryOptions = $participantCategories->return_array('all', 0, false);
		$participantCategoryOptions = is_array($participantCategoryOptions) ? $participantCategoryOptions : [];
		$recurData = (int)($event['recur_data'] ?? 0);
		$recurDayOptions = [];
		foreach ((array)$calendar->rpt_day as $value => $label)
		{
			$recurDayOptions[] = [
				'value' => (int)$value,
				'label' => $label,
				'checked' => (bool)($recurData & (int)$value),
			];
		}
		$customFields = \CreateObject('calendar.bocustom_fields');
		$eventCustomFields = [];
		foreach ((array)$customFields->fields as $field => $data)
		{
			if (isset($customFields->stock_fields[$field]) || !empty($data['disabled']))
			{
				continue;
			}

			$name = ltrim((string)$field, '#');
			$eventCustomFields[] = [
				'id' => $field,
				'name' => $name,
				'label' => lang((string)($data['name'] ?? $name)),
				'length' => (int)($data['length'] ?? 255),
				'shown' => (int)($data['shown'] ?? 30),
				'title' => !empty($data['title']),
				'value' => (string)($event[$field] ?? ''),
			];
		}

		$participants = [];
		foreach ((array)($event['participants'] ?? []) as $participantId => $status)
		{
			if ((int)$participantId === (int)($event['owner'] ?? $calendar->owner))
			{
				continue;
			}
			$participants[] = [
				'id' => (string)$participantId,
				'name' => $calendar->contacts->get_name_of_person_id($participantId),
				'status' => (string)$status,
			];
		}

		return [
			'date' => $date,
			'calendar' => $calendar,
			'event' => $event,
			'categories' => $categoryOptions,
			'participant_categories' => $participantCategoryOptions,
			'custom_fields' => $eventCustomFields,
			'participants' => $participants,
			'recur_types' => $calendar->rpt_type,
			'recur_days' => $recurDayOptions,
			'values' => [
				'id' => $id,
				'title' => (string)($event['title'] ?? ''),
				'description' => (string)($event['description'] ?? ''),
				'location' => (string)($event['location'] ?? ''),
				'start' => $start,
				'end' => $end,
				'category' => array_filter(array_map('intval', explode(',', (string)($event['category'] ?? '')))),
				'priority' => (int)($event['priority'] ?? 2),
				'private' => isset($event['public']) ? !(bool)$event['public'] : false,
				'owner_participates' => !$id || isset($event['participants'][$event['owner'] ?? $calendar->owner]),
				'owner_name' => $calendar->contacts->get_name_of_person_id($event['owner'] ?? $calendar->owner),
				'recur_type' => (int)($event['recur_type'] ?? 0),
				'recur_interval' => (int)($event['recur_interval'] ?? 1),
				'recur_days' => $recurData,
				'recur_end' => !empty($event['recur_enddate']['year']) ? sprintf('%04d-%02d-%02d', $event['recur_enddate']['year'], $event['recur_enddate']['month'], $event['recur_enddate']['mday']) : '',
				'alarm_days' => $calendar->prefs['calendar']['default_email_days'] ?? 0,
				'alarm_hours' => $calendar->prefs['calendar']['default_email_hours'] ?? 0,
				'alarm_minutes' => $calendar->prefs['calendar']['default_email_min'] ?? 0,
			],
		];
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
		$context = $this->formContext($request);
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('Add')]);

		$html = $this->twig->render('@views/calendar/event_form.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/calendar/events'),
			'participants_url' => \phpgw::link('/calendar/participants'),
			'list_url' => \phpgw::link('/calendar/view/day', ['date' => $context['date']]),
			'is_edit' => false,
		] + $context);

		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		$context = $this->formContext($request, $id);
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('Edit')]);

		$html = $this->twig->render('@views/calendar/event_form.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/calendar/events/' . $id),
			'participants_url' => \phpgw::link('/calendar/participants'),
			'list_url' => \phpgw::link('/calendar/view/event/' . $id),
			'is_edit' => true,
		] + $context);

		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}