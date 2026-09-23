<?php

namespace App\modules\calendar\controllers;

use App\helpers\ResponseHelper;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CalendarController
{
	private const STATUS_REJECTED = 0;
	private const STATUS_TENTATIVE = 2;
	private const STATUS_ACCEPTED = 3;

	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
	private array $views = ['day', 'week', 'week-new', 'month', 'year'];

	public function __construct()
	{
		$this->twig = new TwigHelper('calendar');
		$this->legacyView = new LegacyViewHelper();
	}

	private function calendar(): object
	{
		return \CreateObject('calendar.bocalendar', 1);
	}

	private function customFields(): object
	{
		return \CreateObject('calendar.bocustom_fields');
	}

	private function formatDateTimeLocal(object $calendar, array $time): string
	{
		return date('Y-m-d\TH:i', $calendar->maketime($time) - \phpgwapi_datetime::user_timezone());
	}

	private function normalizeView(string $view): string
	{
		return in_array($view, $this->views, true) ? $view : 'month';
	}

	private function ymdToParts(string $date): array
	{
		$date = preg_replace('/[^0-9]/', '', $date);
		if (strlen($date) !== 8)
		{
			$date = date('Ymd');
		}

		return [
			'year' => (int)substr($date, 0, 4),
			'month' => (int)substr($date, 4, 2),
			'day' => (int)substr($date, 6, 2),
		];
	}

	private function dateTimeToParts(string $value): array
	{
		$timestamp = strtotime($value);
		if (!$timestamp)
		{
			$timestamp = time();
		}

		return [
			'year' => (int)date('Y', $timestamp),
			'month' => (int)date('m', $timestamp),
			'day' => (int)date('d', $timestamp),
			'hour' => (int)date('H', $timestamp),
			'min' => (int)date('i', $timestamp),
		];
	}

	private function input(array $body): array
	{
		return [
			'title' => trim((string)($body['title'] ?? '')),
			'description' => trim((string)($body['description'] ?? '')),
			'location' => trim((string)($body['location'] ?? '')),
			'start' => (string)($body['start'] ?? ''),
			'end' => (string)($body['end'] ?? ''),
			'category' => array_map('intval', (array)($body['category'] ?? [])),
			'priority' => max(1, min(3, (int)($body['priority'] ?? 2))),
			'private' => !empty($body['private']),
			'owner_participates' => !empty($body['owner_participates']),
			'participants' => (array)($body['participants'] ?? []),
			'alarm_days' => max(0, (int)($body['alarm_days'] ?? 0)),
			'alarm_hours' => max(0, (int)($body['alarm_hours'] ?? 0)),
			'alarm_minutes' => max(0, (int)($body['alarm_minutes'] ?? 0)),
			'recur_type' => (int)($body['recur_type'] ?? 0),
			'recur_interval' => max(1, (int)($body['recur_interval'] ?? 1)),
			'recur_end' => (string)($body['recur_end'] ?? ''),
			'recur_days' => array_map('intval', (array)($body['recur_days'] ?? [])),
			'custom_fields' => (array)($body['custom_fields'] ?? []),
		];
	}

	private function mapParticipant(array $participant, string $type): array
	{
		if ($type === 'group')
		{
			$name = trim((string)($participant['per_first_name'] ?? '') . ' ' . (string)($participant['per_last_name'] ?? ''));
			return [
				'id' => (string)($participant['contact_id'] ?? ''),
				'name' => $name,
				'type' => 'group',
			];
		}

		if ($type === 'organization')
		{
			return [
				'id' => (string)($participant['contact_id'] ?? ''),
				'name' => (string)($participant['org_name'] ?? ''),
				'type' => 'organization',
			];
		}

		$name = trim((string)($participant['per_first_name'] ?? '') . ' ' . (string)($participant['per_last_name'] ?? ''));
		return [
			'id' => (string)($participant['contact_id'] ?? ''),
			'name' => $name,
			'type' => 'person',
		];
	}

	private function rangeFor(string $view, string $date): array
	{
		$parts = $this->ymdToParts($date);
		$timestamp = mktime(0, 0, 0, $parts['month'], $parts['day'], $parts['year']);

		switch ($view)
		{
			case 'day':
				$start = $end = $timestamp;
				break;
			case 'week':
			case 'week-new':
				$start = \phpgwapi_datetime::get_weekday_start($parts['year'], $parts['month'], $parts['day']);
				$end = strtotime('+6 days', $start);
				break;
			case 'year':
				$start = mktime(0, 0, 0, 1, 1, $parts['year']);
				$end = mktime(0, 0, 0, 12, 31, $parts['year']);
				break;
			case 'month':
			default:
				$start = mktime(0, 0, 0, $parts['month'], 1, $parts['year']);
				$end = mktime(0, 0, 0, $parts['month'] + 1, 0, $parts['year']);
				break;
		}

		return [
			'syear' => (int)date('Y', $start),
			'smonth' => (int)date('m', $start),
			'sday' => (int)date('d', $start),
			'eyear' => (int)date('Y', $end),
			'emonth' => (int)date('m', $end),
			'eday' => (int)date('d', $end),
			'start' => date('Y-m-d', $start),
			'end' => date('Y-m-d', $end),
		];
	}

	private function mapEvent(object $calendar, array $event, string $date): array
	{
		$userTimezone = \phpgwapi_datetime::user_timezone();
		$start = $calendar->maketime($event['start']) - $userTimezone;
		$end = $calendar->maketime($event['end']) - $userTimezone;
		$isPrivate = !$event['public'] || !$calendar->check_perms(\ACL_READ, $event);

		return [
			'id' => (int)($event['id'] ?? 0),
			'date' => $date,
			'title' => $isPrivate ? lang('private') : (string)($event['title'] ?? ''),
			'description' => $isPrivate ? '' : (string)($event['description'] ?? ''),
			'location' => $isPrivate ? '' : (string)($event['location'] ?? ''),
			'start' => date('c', $start),
			'end' => date('c', $end),
			'start_time' => date('H:i', $start),
			'end_time' => date('H:i', $end),
			'owner' => (int)($event['owner'] ?? 0),
			'recurring' => !empty($event['recur_type']) ? 1 : 0,
			'private' => $isPrivate ? 1 : 0,
			'view_url' => \phpgw::link('/calendar/view/event/' . (int)($event['id'] ?? 0), ['date' => $date]),
		];
	}

	private function mapEventDetails(object $calendar, array $event): array
	{
		$userTimezone = \phpgwapi_datetime::user_timezone();
		$start = $calendar->maketime($event['start']) - $userTimezone;
		$end = $calendar->maketime($event['end']) - $userTimezone;
		$isPrivate = !$event['public'] || !$calendar->check_perms(\ACL_READ, $event);
		$fields = [];
		$responseActions = [];

		if (!$isPrivate)
		{
			foreach ((array)$calendar->event2array($event) as $key => $field)
			{
				$data = $field['data'] ?? '';
				if (is_array($data))
				{
					$data = implode("\n", $data);
				}

				$fields[] = [
					'id' => (string)$key,
					'label' => (string)($field['field'] ?? $key),
					'value' => (string)$data,
				];
			}

			$currentParticipant = (int)$calendar->owner;
			if (array_key_exists($currentParticipant, (array)$event['participants']) && $calendar->check_perms(\ACL_EDIT, $event))
			{
				$responseActions = [
					['status' => self::STATUS_ACCEPTED, 'label' => lang('Accept')],
					['status' => self::STATUS_REJECTED, 'label' => lang('Reject')],
					['status' => self::STATUS_TENTATIVE, 'label' => lang('Tentative')],
				];
			}
		}

		return [
			'id' => (int)($event['id'] ?? 0),
			'title' => $isPrivate ? lang('private') : (string)($event['title'] ?? ''),
			'description' => $isPrivate ? '' : (string)($event['description'] ?? ''),
			'location' => $isPrivate ? '' : (string)($event['location'] ?? ''),
			'start' => date('c', $start),
			'end' => date('c', $end),
			'start_label' => date('Y-m-d H:i', $start),
			'end_label' => date('Y-m-d H:i', $end),
			'owner' => (int)($event['owner'] ?? 0),
			'recurring' => !empty($event['recur_type']) ? 1 : 0,
			'private' => $isPrivate ? 1 : 0,
			'fields' => $fields,
			'edit_url' => \phpgw::link('/calendar/view/event/' . (int)($event['id'] ?? 0) . '/edit'),
			'delete_url' => \phpgw::link('/calendar/events/' . (int)($event['id'] ?? 0)),
			'export_url' => \phpgw::link('/calendar/events/' . (int)($event['id'] ?? 0) . '/export'),
			'alarms_url' => $calendar->check_perms(\ACL_EDIT, $event)
				? \phpgw::link('/calendar/events/' . (int)($event['id'] ?? 0) . '/alarms') : '',
			'response_url' => \phpgw::link('/calendar/events/' . (int)($event['id'] ?? 0) . '/response'),
			'response_actions' => $responseActions,
		];
	}

	public function events(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$view = $this->normalizeView((string)($query['view'] ?? 'month'));
		$date = (string)($query['date'] ?? date('Ymd'));
		$calendar = $this->calendar();
		$range = $this->rangeFor($view, $date);

		$cached = (array)$calendar->store_to_cache($range);
		$events = [];
		foreach ($cached as $eventDate => $dayEvents)
		{
			foreach ((array)$dayEvents as $event)
			{
				if (!$calendar->rejected_no_show($event))
				{
					$events[] = $this->mapEvent($calendar, (array)$event, (string)$eventDate);
				}
			}
		}

		return ResponseHelper::sendJSONResponse([
			'view' => $view,
			'date' => $date,
			'range' => $range,
			'data' => $events,
		]);
	}

	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Invalid entry id.')], 400);
		}

		$calendar = $this->calendar();
		if (!$calendar->check_perms(\ACL_READ, $id))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('You do not have permission to read this record!')], 403);
		}

		$event = $calendar->read_entry($id);
		if (!is_array($event) || empty($event['id']))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Sorry, this event does not exist')], 404);
		}

		return ResponseHelper::sendJSONResponse(['data' => $this->mapEventDetails($calendar, $event)]);
	}

	public function export(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Invalid entry id.')], 400);
		}

		$calendar = $this->calendar();
		if (!$calendar->check_perms(\ACL_READ, $id))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('You do not have permission to read this record!')], 403);
		}

		$content = \ExecMethod('calendar.boicalendar.export', [
			'l_event_id' => $id,
			'chunk_split' => false,
		]);

		$response->getBody()->write((string)$content);
		return $response
			->withHeader('Content-Type', 'text/calendar')
			->withHeader('Content-Disposition', 'attachment; filename="phpgw-cal-' . $id . '.ics"');
	}

	public function alarms(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		$calendar = $this->calendar();
		$event = $id > 0 ? $calendar->read_entry($id) : [];
		if (!is_array($event) || empty($event['id']))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Sorry, this event does not exist')], 404);
		}
		if (!$calendar->check_perms(\ACL_EDIT, $event))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('You do not have permission to edit this record!')], 403);
		}

		$alarmBo = \CreateObject('calendar.boalarm', 1);
		$alarmBo->cal_id = $id;
		$body = (array)$request->getParsedBody();
		$selected = (array)($body['alarm'] ?? []);
		if ($request->getMethod() === 'POST')
		{
			if (!empty($body['delete']) && $selected)
			{
				$alarmBo->delete($selected);
			}
			elseif (!empty($body['enable']) && $selected)
			{
				$alarmBo->enable($selected, true);
			}
			elseif (!empty($body['disable']) && $selected)
			{
				$alarmBo->enable($selected, false);
			}
			elseif (!empty($body['add']))
			{
				$time = max(0, (int)($body['days'] ?? 0)) * \phpgwapi_datetime::SECONDS_IN_DAY
					+ max(0, (int)($body['hours'] ?? 0)) * \phpgwapi_datetime::SECONDS_IN_HOUR
					+ max(0, (int)($body['minutes'] ?? 0)) * 60;
				$owner = (int)($body['owner'] ?? 0);
				if ($time > 0 && $owner > 0)
				{
					$alarmBo->add($event, $time, $owner);
				}
			}
			$event = $calendar->read_entry($id);
		}

		$html = $this->twig->render('@views/calendar/alarms.twig', [
			'layout' => '@views/_bare.twig',
			'event' => $event,
			'alarms' => (array)($event['alarm'] ?? []),
			'participants' => (array)$alarmBo->participants($event),
			'back_url' => \phpgw::link('/calendar/view/event/' . $id),
			'alarms_url' => \phpgw::link('/calendar/events/' . $id . '/alarms'),
		]);
		$response->getBody()->write($this->legacyView->render($html, ['calendar'], 'calendar'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	private function saveEvent(Request $request, Response $response, int $id = 0): Response
	{
		$body = json_decode($request->getBody()->getContents(), true) ?: [];
		$values = $this->input((array)$body);
		if ($values['title'] === '')
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Title is required')], 422);
		}
		$calendar = $this->calendar();
		if (!$calendar->check_perms(\ACL_ADD))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('You do not have permission to add entries!')], 403);
		}
		if ($id && !$calendar->check_perms(\ACL_EDIT, $id))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('You do not have permission to edit this entry!')], 403);
		}

		$start = $this->dateTimeToParts($values['start']);
		$end = $this->dateTimeToParts($values['end']);
		if ($id)
		{
			$event = $calendar->read_entry($id);
			if (!is_array($event) || empty($event['id']))
			{
				return ResponseHelper::sendErrorResponse(['error' => lang('Sorry, this event does not exist')], 404);
			}
			$calendar->so->cal->event = $event;
		}
		else
		{
			$calendar->event_init();
			$calendar->add_attribute('id', 0);
			$calendar->add_attribute('owner', $calendar->owner);
			$calendar->add_attribute('reference', 0);
		}
		$calendar->set_start($start['year'], $start['month'], $start['day'], $start['hour'], $start['min'], 0);
		$calendar->set_end($end['year'], $end['month'], $end['day'], $end['hour'], $end['min'], 0);
		$calendar->set_title($values['title']);
		$calendar->set_description($values['description']);
		$calendar->add_attribute('location', $values['location']);
		$calendar->set_category(implode(',', array_filter($values['category'])));
		$calendar->add_attribute('priority', $values['priority']);
		$calendar->set_class(!$values['private']);
		if ($values['owner_participates'])
		{
			$calendar->add_attribute('participants', 'A', $calendar->owner);
		}
		foreach ($values['participants'] as $participant)
		{
			$participant = is_array($participant) ? $participant : ['id' => $participant, 'status' => 'A'];
			$id = (string)($participant['id'] ?? '');
			$status = (string)($participant['status'] ?? 'A');
			$status = in_array($status, ['A', 'R', 'T', 'U'], true) ? $status : 'A';
			if ($id === '')
			{
				continue;
			}

			if (substr($id, 0, 2) === 'g_')
			{
				$members = (new \App\modules\phpgwapi\controllers\Accounts\Accounts())->member((int)substr($id, 2));
				foreach ((array)$members as $member)
				{
					$contactId = (int)$calendar->contacts->is_contact($member['account_id'] ?? 0);
					if ($contactId)
					{
						$calendar->add_attribute('participants', $status, $contactId);
					}
				}
				continue;
			}

			$calendar->add_attribute('participants', $status, (int)$id);
		}

		$customFields = $this->customFields();
		$configuredFields = [];
		foreach ((array)$customFields->fields as $configuredField => $fieldConfig)
		{
			$configuredFields['#' . ltrim((string)$configuredField, '#')] = $fieldConfig;
		}
		foreach ($values['custom_fields'] as $field => $value)
		{
			$field = '#' . ltrim(trim((string)$field), '#');
			if (isset($configuredFields[$field]) && empty($configuredFields[$field]['disabled']))
			{
				$calendar->add_attribute($field, trim((string)$value));
			}
		}

		$alarmSeconds = ($values['alarm_days'] * \phpgwapi_datetime::SECONDS_IN_DAY) + ($values['alarm_hours'] * \phpgwapi_datetime::SECONDS_IN_HOUR) + ($values['alarm_minutes'] * 60);
		if ($alarmSeconds > 0)
		{
			$calendar->set_alarm([
				'time' => $calendar->maketime($calendar->get_cached_event()['start']) - $alarmSeconds,
				'owner' => $calendar->owner,
				'enabled' => 1,
			]);
		}

		$recurEnd = $values['recur_end'] ? $this->dateTimeToParts($values['recur_end']) : $end;
		switch ($values['recur_type'])
		{
			case \MCAL_RECUR_DAILY:
				$calendar->set_recur_daily($recurEnd['year'], $recurEnd['month'], $recurEnd['day'], $values['recur_interval']);
				break;
			case \MCAL_RECUR_WEEKLY:
				$calendar->set_recur_weekly($recurEnd['year'], $recurEnd['month'], $recurEnd['day'], $values['recur_interval'], array_sum($values['recur_days']));
				break;
			case \MCAL_RECUR_MONTHLY_MDAY:
				$calendar->set_recur_monthly_mday($recurEnd['year'], $recurEnd['month'], $recurEnd['day'], $values['recur_interval']);
				break;
			case \MCAL_RECUR_MONTHLY_WDAY:
				$calendar->set_recur_monthly_wday($recurEnd['year'], $recurEnd['month'], $recurEnd['day'], $values['recur_interval']);
				break;
			case \MCAL_RECUR_YEARLY:
				$calendar->set_recur_yearly($recurEnd['year'], $recurEnd['month'], $recurEnd['day'], $values['recur_interval']);
				break;
			default:
				$calendar->set_recur_none();
		}

		$event = $calendar->get_cached_event();
		$errorCode = $calendar->validate_update($event);
		if ($errorCode)
		{
			return ResponseHelper::sendErrorResponse(['error' => (new \phpgwapi_common())->check_code($errorCode)], 422);
		}

		$overlappingEvents = $calendar->overlap(
			$calendar->maketime($event['start']),
			$calendar->maketime($event['end']),
			(array)($event['participants'] ?? []),
			(int)($event['owner'] ?? $calendar->owner),
			$id ? [$id] : []
		);
		if ($overlappingEvents)
		{
			return ResponseHelper::sendErrorResponse([
				'error' => lang('The event overlaps with another calendar entry.'),
				'conflicts' => array_values(array_map('intval', (array)$overlappingEvents)),
			], 409);
		}

		$calendar->so->add_entry($event);
		$saved = $calendar->get_cached_event();
		$id = (int)($saved['id'] ?? $event['id'] ?? 0);

		return ResponseHelper::sendJSONResponse([
			'message' => lang('Entry saved'),
			'id' => $id,
			'view_url' => \phpgw::link('/calendar/view/event/' . $id),
		], $id ? 200 : 201);
	}

	public function store(Request $request, Response $response): Response
	{
		return $this->saveEvent($request, $response);
	}

	public function update(Request $request, Response $response, array $args): Response
	{
		return $this->saveEvent($request, $response, (int)($args['id'] ?? 0));
	}

	public function response(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		$body = json_decode($request->getBody()->getContents(), true) ?: [];
		$query = $request->getQueryParams();
		$hasStatus = array_key_exists('status', (array)$body) || array_key_exists('status', $query);
		$status = (int)($body['status'] ?? $query['status'] ?? -1);
		$allowed = [self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_TENTATIVE];

		if ($id <= 0)
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Invalid entry id.')], 400);
		}
		if (!$hasStatus || !in_array($status, $allowed, true))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Invalid status')], 400);
		}

		$calendar = $this->calendar();
		$event = $calendar->read_entry($id);
		if (!is_array($event) || empty($event['id']))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Sorry, this event does not exist')], 404);
		}

		if (!array_key_exists((int)$calendar->owner, (array)$event['participants']))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('The user %1 is not participating in this event!', $calendar->contacts->get_name_of_person_id($calendar->owner))], 403);
		}

		if (!$calendar->check_perms(\ACL_EDIT, $event))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('You do not have permission to edit this entry!')], 403);
		}

		if (!$calendar->set_status($id, $status))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Unable to update status')], 422);
		}
		if ($request->getMethod() === 'GET')
		{
			return $response
				->withHeader('Location', \phpgw::link('/calendar/view/event/' . $id))
				->withStatus(302);
		}

		$event = $calendar->read_entry($id);
		return ResponseHelper::sendJSONResponse([
			'message' => lang('Status updated'),
			'data' => $this->mapEventDetails($calendar, (array)$event),
		]);
	}

	public function participants(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$lookup = trim((string)($query['lookup'] ?? ''));
		if (strlen($lookup) < 3 && $lookup !== '*')
		{
			return ResponseHelper::sendJSONResponse(['data' => []]);
		}

		$search = $lookup === '*' ? '%' : $lookup;
		$type = (string)($query['type'] ?? 'person');
		$categoryId = (int)($query['cat_id'] ?? $query['category_id'] ?? 0);
		$calendar = $this->calendar();

		switch ($type)
		{
			case 'group':
				$items = (array)$calendar->get_groups($search);
				$type = 'group';
				break;
			case 'organization':
				$items = (array)$calendar->get_org_contacts($search, $categoryId);
				$type = 'organization';
				break;
			case 'person':
			default:
				$items = (array)$calendar->get_per_contacts($search, $categoryId);
				$type = 'person';
		}

		$data = array_values(array_filter(array_map(function ($participant) use ($type) {
			$mapped = $this->mapParticipant((array)$participant, $type);
			return $mapped['id'] && $mapped['name'] ? $mapped : null;
		}, $items)));

		return ResponseHelper::sendJSONResponse(['data' => $data]);
	}

	public function destroy(Request $request, Response $response, array $args): Response
	{
		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Invalid entry id.')], 400);
		}

		$calendar = $this->calendar();
		if (!$calendar->check_perms(\ACL_DELETE, $id))
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('You do not have permission to delete this entry!')], 403);
		}

		$calendar->delete_entry($id);
		$calendar->expunge();

		return ResponseHelper::sendJSONResponse(['message' => lang('Entry deleted')]);
	}
}