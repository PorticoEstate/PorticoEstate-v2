<?php

namespace App\modules\calendar\controllers;

use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CalendarController
{
	private array $views = ['day', 'week', 'week-new', 'month', 'year'];

	private function calendar(): object
	{
		return \CreateObject('calendar.bocalendar', 1);
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
			'edit_url' => \phpgw::link('/index.php', ['menuaction' => 'calendar.uicalendar.edit', 'cal_id' => (int)($event['id'] ?? 0)]),
			'delete_url' => \phpgw::link('/calendar/events/' . (int)($event['id'] ?? 0)),
			'export_url' => \phpgw::link('/index.php', ['menuaction' => 'calendar.uicalendar.export', 'cal_id' => (int)($event['id'] ?? 0)]),
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