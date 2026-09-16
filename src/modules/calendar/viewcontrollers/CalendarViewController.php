<?php

namespace App\modules\calendar\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;

use App\modules\phpgwapi\security\Acl;

use App\modules\phpgwapi\controllers\Accounts\Accounts;
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

	private function plannerContext(Request $request): array
	{
		$query = $request->getQueryParams();
		$date = preg_replace('/[^0-9]/', '', (string)($query['date'] ?? date('Ymd')));
		if (strlen($date) !== 8)
		{
			$date = date('Ymd');
		}

		$calendar = \CreateObject('calendar.bocalendar', 1);
		$groupId = (int)($query['group'] ?? $calendar->prefs['calendar']['planner_start_with_group'] ?? 0);
		if ($groupId > 0 && empty($query['owner']))
		{
			$calendar->set_owner_to_group($groupId);
		}

		$monthCount = max(1, min(6, (int)($query['num_months'] ?? $calendar->num_months ?? 1)));
		$start = mktime(0, 0, 0, (int)substr($date, 4, 2), 1, (int)substr($date, 0, 4));
		$end = mktime(0, 0, 0, (int)date('n', $start) + $monthCount, 0, (int)date('Y', $start));
		$days = [];
		for ($day = $start; $day <= $end; $day = strtotime('+1 day', $day))
		{
			$days[] = [
				'key' => date('Ymd', $day),
				'label' => date('D j', $day),
				'month' => date('M Y', $day),
			];
		}

		$calendar->store_to_cache([
			'syear' => date('Y', $start),
			'smonth' => date('n', $start),
			'sday' => 1,
			'eyear' => date('Y', $end),
			'emonth' => date('n', $end),
			'eday' => date('j', $end),
		]);

		$owners = $calendar->is_group && $calendar->g_owner ? $calendar->g_owner : [$calendar->owner];
		$groups = [];
		$accounts = new Accounts();
		foreach ((array)Acl::getInstance()->get_ids_for_location('run', 1, 'calendar') as $accountId)
		{
			if ($accounts->get_type($accountId) === 'g')
			{
				$groups[] = [
					'id' => (int)$accountId,
					'name' => (new \phpgwapi_common())->grab_owner_name($accountId),
				];
			}
		}
		$ownerRows = [];
		foreach ($owners as $owner)
		{
			if ($calendar->check_perms(\ACL_READ, 0, $owner))
			{
				$ownerRows[(int)$owner] = [
					'id' => (int)$owner,
					'name' => $calendar->contacts->get_name_of_person_id($owner),
					'cells' => [],
				];
			}
		}

		$intervals = max(1, min(4, (int)($calendar->prefs['calendar']['planner_intervals_per_day'] ?? 4)));
		$boundaries = [0, 12, 18, 24];
		if ($intervals === 1)
		{
			$boundaries = [0, 24];
		}
		elseif ($intervals === 2)
		{
			$boundaries = [0, 12, 24];
		}
		elseif ($intervals === 4)
		{
			$boundaries = [0, 7, 12, 18, 24];
		}

		foreach ($days as $day)
		{
			$dayStart = strtotime(substr($day['key'], 0, 4) . '-' . substr($day['key'], 4, 2) . '-' . substr($day['key'], 6, 2));
			foreach ($ownerRows as &$row)
			{
				for ($slot = 0; $slot < $intervals; $slot++)
				{
					$row['cells'][$day['key']][$slot] = [];
				}
			}
			unset($row);

			foreach ((array)($calendar->cached_events[$day['key']] ?? []) as $event)
			{
				if (!$calendar->check_perms(\ACL_READ, $event) || $calendar->rejected_no_show($event))
				{
					continue;
				}
				$eventStart = $calendar->maketime($event['start']);
				$eventEnd = $calendar->maketime($event['end']);
				foreach ((array)($event['participants'] ?? []) as $owner => $status)
				{
					$owner = (int)$owner;
					if (!isset($ownerRows[$owner]) || $status === 'R')
					{
						continue;
					}
					for ($slot = 0; $slot < $intervals; $slot++)
					{
						$slotStart = $dayStart + ($boundaries[$slot] * 3600);
						$slotEnd = $dayStart + ($boundaries[$slot + 1] * 3600);
						if ($eventStart < $slotEnd && $eventEnd > $slotStart)
						{
							$ownerRows[$owner]['cells'][$day['key']][$slot][] = [
								'title' => (string)($event['title'] ?? lang('private')),
								'url' => \phpgw::link('/calendar/view/event/' . (int)$event['id'], ['date' => $day['key']]),
							];
						}
					}
				}
			}
		}

		return [
			'days' => $days,
			'owners' => array_values($ownerRows),
			'groups' => $groups,
			'selected_group' => $groupId,
			'date' => $date,
			'month_count' => $monthCount,
			'intervals' => $intervals,
			'boundaries' => $boundaries,
			'previous_url' => \phpgw::link('/calendar/view/planner', ['date' => date('Ym01', strtotime('-1 month', $start)), 'num_months' => $monthCount]),
			'next_url' => \phpgw::link('/calendar/view/planner', ['date' => date('Ym01', strtotime('+' . $monthCount . ' months', $start)), 'num_months' => $monthCount]),
		];
	}

	private function matrixContext(Request $request): array
	{
		$query = $request->getQueryParams();
		$body = (array)$request->getParsedBody();
		$date = preg_replace('/[^0-9]/', '', (string)($body['date'] ?? $query['date'] ?? date('Ymd')));
		if (strlen($date) !== 8)
		{
			$date = date('Ymd');
		}

		$calendar = \CreateObject('calendar.bocalendar', 1);
		$accounts = new Accounts();
		$groups = [];
		$users = [];
		foreach ((array)Acl::getInstance()->get_ids_for_location('run', 1, 'calendar') as $accountId)
		{
			if ($accounts->get_type($accountId) === 'g')
			{
				$groups[] = ['id' => 'g_' . (int)$accountId, 'name' => (new \phpgwapi_common())->grab_owner_name($accountId)];
			}
			else
			{
				$personId = (int)$calendar->contacts->is_contact($accountId);
				if ($personId && $calendar->check_perms(\ACL_READ, 0, $personId))
				{
					$users[] = ['id' => (string)$personId, 'name' => $calendar->contacts->get_name_of_person_id($personId)];
				}
			}
		}

		$selected = array_values(array_filter((array)($body['participants'] ?? $query['participants'] ?? []), 'is_string'));
		if (!$selected)
		{
			$selected = $groups ? [$groups[0]['id']] : ($users ? [$users[0]['id']] : []);
		}
		$participantIds = [];
		foreach ($selected as $participant)
		{
			if (str_starts_with($participant, 'g_'))
			{
				foreach ((array)$accounts->member((int)substr($participant, 2)) as $member)
				{
					$personId = (int)$calendar->contacts->is_contact($member['account_id'] ?? 0);
					if ($personId && $calendar->check_perms(\ACL_READ, 0, $personId))
					{
						$participantIds[$personId] = true;
					}
				}
			}
			elseif ($calendar->check_perms(\ACL_READ, 0, (int)$participant))
			{
				$participantIds[(int)$participant] = true;
			}
		}

		$timestamp = mktime(0, 0, 0, (int)substr($date, 4, 2), (int)substr($date, 6, 2), (int)substr($date, 0, 4));
		$calendar->store_to_cache([
			'syear' => date('Y', $timestamp), 'smonth' => date('n', $timestamp), 'sday' => date('j', $timestamp),
			'eyear' => date('Y', $timestamp), 'emonth' => date('n', $timestamp), 'eday' => date('j', $timestamp),
			'owner' => array_keys($participantIds),
		]);
		$increment = max(5, min(60, (int)($calendar->prefs['calendar']['interval'] ?? 15)));
		$slots = (int)(24 * 60 / $increment);
		$rows = [];
		foreach (array_keys($participantIds) as $participantId)
		{
			$busy = array_fill(0, $slots, false);
			foreach ((array)($calendar->cached_events[$date] ?? []) as $event)
			{
				if (!$calendar->check_perms(\ACL_READ, $event) || ($event['participants'][$participantId] ?? 'R') === 'R')
				{
					continue;
				}
				$eventStart = max($timestamp, $calendar->maketime($event['start']));
				$eventEnd = min($timestamp + 86400, $calendar->maketime($event['end']));
				$first = max(0, (int)floor(($eventStart - $timestamp) / ($increment * 60)));
				$last = min($slots, (int)ceil(($eventEnd - $timestamp) / ($increment * 60)));
				for ($slot = $first; $slot < $last; $slot++)
				{
					$busy[$slot] = true;
				}
			}
			$rows[] = ['name' => $calendar->contacts->get_name_of_person_id($participantId), 'busy' => $busy];
		}

		$labels = [];
		for ($slot = 0; $slot < $slots; $slot++)
		{
			$labels[] = date('H:i', $timestamp + $slot * $increment * 60);
		}
		return [
			'date' => $date, 'groups' => $groups, 'users' => $users, 'selected' => $selected,
			'rows' => $rows, 'labels' => $labels, 'increment' => $increment,
			'previous_url' => \phpgw::link('/calendar/view/matrix', ['date' => date('Ymd', strtotime('-1 day', $timestamp))]),
			'next_url' => \phpgw::link('/calendar/view/matrix', ['date' => date('Ymd', strtotime('+1 day', $timestamp))]),
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

	public function planner(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('Group Planner')]);
		$planner = $this->plannerContext($request);

		$html = $this->twig->render('@views/calendar/planner.twig', [
			'layout' => '@views/_bare.twig',
			'planner' => $planner,
			'calendar_url' => \phpgw::link('/calendar'),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function matrix(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('Calendar') . ' - ' . lang('Daily Matrix View')]);
		$matrix = $this->matrixContext($request);
		$html = $this->twig->render('@views/calendar/matrix.twig', [
			'layout' => '@views/_bare.twig', 'matrix' => $matrix,
			'calendar_url' => \phpgw::link('/calendar'),
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