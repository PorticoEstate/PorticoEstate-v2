<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use Kigkonsult\Icalcreator\Vcalendar;
use App\modules\bookingfrontend\helpers\UserHelper;


phpgw::import_class('booking.uibooking');

class bookingfrontend_uibooking extends booking_uibooking
{

	public $public_functions = array(
		'building_schedule' => true,
		'building_schedule_pe' => true,
		'building_extraschedule' => true,
		'resource_schedule' => true,
		'organization_schedule' => true,
		'info' => true,
		'info_json' => true,
		'add' => true,
		'show' => true,
		'edit' => true,
		'report_numbers' => true,
		'massupdate' => true,
		'cancel' => true,
		'get_freetime' => true,
		'get_freetime_limit' => true,
		'ical' => true
	);

	var $organization_bo, $system_message_bo;

	public function __construct()
	{
		parent::__construct();
		$this->group_bo = CreateObject('booking.bogroup');
		$this->resource_bo = CreateObject('booking.boresource');
		$this->allocation_bo = CreateObject('booking.boallocation');
		$this->season_bo = CreateObject('booking.boseason');
		$this->building_bo = CreateObject('booking.bobuilding');
		$this->system_message_bo = CreateObject('booking.bosystem_message');
		$this->organization_bo = CreateObject('booking.boorganization');
	}

	private function item_link(&$item, $key)
	{
		if (isset($item['type']) && in_array($item['type'], array('allocation', 'booking', 'event')))
		{
			$item['info_url'] = $this->link(array(
				'menuaction' => 'bookingfrontend.ui' . $item['type'] . '.info',
				'id' => $item['id']
			));
		}
	}

	public function get_freetime()
	{
		$building_id = Sanitizer::get_var('building_id', 'int');
		$resource_id = Sanitizer::get_var('resource_id', 'int');

		$start_date = Sanitizer::get_var('start_date', 'date');
		$end_date = Sanitizer::get_var('end_date', 'date');
		
		// Get the detailed_overlap parameter (default to false)
		$detailed_overlap = Sanitizer::get_var('detailed_overlap', 'bool', 'REQUEST', false);

		$weekdays = array();
		$timezone = !empty($this->userSettings['preferences']['common']['timezone']) ? $this->userSettings['preferences']['common']['timezone'] : 'UTC';

		try
		{
			$DateTimeZone = new DateTimeZone($timezone);
		} catch (Exception $ex)
		{
			throw $ex;
		}

		try
		{
			$freetime = $this->bo->get_free_events($building_id, $resource_id, new DateTime(date('Y-m-d', $start_date), $DateTimeZone), new DateTime(date('Y-m-d', $end_date), $DateTimeZone), $weekdays, false, false, $detailed_overlap);
		} catch (Exception $exc)
		{
			return "booking_bobooking::get_free_events() - " . $exc->getMessage();
		}

		return $freetime;
	}

	public function get_freetime_limit()
	{
		$building_id = Sanitizer::get_var('building_id', 'int');
		$resource_id = Sanitizer::get_var('resource_id', 'int');
		$all_simple_bookings = Sanitizer::get_var('all_simple_bookings', 'bool');
		$_ids = explode(',', Sanitizer::get_var('resource_ids', 'string', 'REQUEST', ''));
		$resource_ids = array();
		foreach ($_ids as $id)
		{
			$resource_ids[] = (int)$id;
		}
		if ($resource_id)
			$resource_ids[] = $resource_id;


		$start_date = Sanitizer::get_var('start_date', 'date');
		$end_date = Sanitizer::get_var('end_date', 'date');
		
		// Get the detailed_overlap parameter (default to false)
		$detailed_overlap = Sanitizer::get_var('detailed_overlap', 'bool', 'REQUEST', false);

		$weekdays = array();

		try
		{
			$freetime = $this->bo->get_free_events($building_id, $resource_ids, new DateTime(date('Y-m-d', $start_date)), new DateTime(date('Y-m-d', $end_date)), $weekdays, true, $all_simple_bookings, $detailed_overlap);
		} catch (Exception $exc)
		{
			return "booking_bobooking::get_free_events() - " . $exc->getMessage();
		}

		return $freetime;
	}

	public function building_schedule_pe()
	{
		$dates = Sanitizer::get_var('dates');
		$dates_csv = Sanitizer::get_var('dates_csv', 'string', 'REQUEST', '');

		if (!empty($dates_csv))
		{
			// Split the comma-separated string into an array
			$dates = explode(',', $dates_csv);
		} elseif (!$dates || !is_array($dates))
		{
			$dates = array(Sanitizer::get_var('date', 'string', 'REQUEST', ''));
		}


		// Remove any empty values
		$dates = array_filter($dates);

		// Initialize arrays for results, resources, and seasons
		$results = array();
		$allResources = array();
		$allSeasons = array();

		// Filter out dates to ensure one fetch per week
		$uniqueWeeks = array();
		foreach ($dates as $date)
		{
			$date = trim($date); // Trim any whitespace
			if (!empty($date))
			{
				$_date = new DateTime($date);
				// Adjust to the start of the week, considering Monday as the first day of the week
				$_date->modify('Monday this week');
				$weekStart = $_date->format('Y-m-d');
				if (!in_array($weekStart, $uniqueWeeks))
				{
					$uniqueWeeks[] = $weekStart;
				}
			}
		}

		// Process each unique week
		foreach ($uniqueWeeks as $weekStart)
		{
			$_date = new DateTime($weekStart);
			$bookings = $this->bo->building_schedule_pe(Sanitizer::get_var('building_id', 'int'), $_date);
			if (isset($bookings['results']['schedule']) && is_array($bookings['results']['schedule']))
			{
				$results = array_merge($results, $bookings['results']['schedule']);
			}
			if (isset($bookings['results']['resources']) && is_array($bookings['results']['resources']))
			{
				foreach ($bookings['results']['resources'] as $id => $resource)
				{
					$allResources[$id] = $resource;
				}
			}
			if (isset($bookings['results']['seasons']) && is_array($bookings['results']['seasons']))
			{
				foreach ($bookings['results']['seasons'] as $season)
				{
					$uniqueKey = sprintf('%s-%d-%s-%s', $season['id'], $season['wday'], $season['from_'], $season['to_']);
					$allSeasons[$uniqueKey] = $season;
				}
			}
		}

		// Prepare the final data structure
		$data = array(
			'dates' => $uniqueWeeks,
			'ResultSet' => array(
				"totalResultsAvailable" => count($results),
				"Result" => array(
					"total_records" => count($results),
					"results" => array(
						"schedule" => $results,
						"resources" => $allResources,
						"seasons" => array_values($allSeasons)
					)
				)
			)
		);

		return $data;
	}


	public function building_schedule()
	{
		$dates = Sanitizer::get_var('dates');
		if (!$dates || !is_array($dates))
		{
			$dates = array(Sanitizer::get_var('date'));
		}

		$results = array();

		foreach ($dates as $date)
		{
			$_date = new DateTime($date);
			$bookings = $this->bo->building_schedule(Sanitizer::get_var('building_id', 'int'), $_date);
			foreach ($bookings['results'] as &$booking)
			{
				$booking['resource_link'] = $this->link(array(
					'menuaction' => 'bookingfrontend.uiresource.schedule',
					'id' => $booking['resource_id']
				));
				$booking['link'] = $this->link(array(
					'menuaction' => 'bookingfrontend.uibooking.show',
					'id' => $booking['id']
				));
				array_walk($booking, array($this, 'item_link'));

				$results[] = $booking;
			}
		}
		$data = array(
			'ResultSet' => array(
				"totalResultsAvailable" => count($results),
				"Result" => $results
			)
		);
		return $data;
	}

	public function building_extraschedule()
	{
		$date = new DateTime(Sanitizer::get_var('date'));
		$bookings = $this->bo->building_extraschedule(Sanitizer::get_var('building_id', 'int'), $date);
		foreach ($bookings['results'] as &$row)
		{
			$row['resource_link'] = $this->link(array(
				'menuaction' => 'bookingfrontend.uiresource.schedule',
				'id' => $row['resource_id']
			));
			array_walk($row, array($this, 'item_link'));
		}
		$data = array(
			'ResultSet' => array(
				"totalResultsAvailable" => $bookings['total_records'],
				"Result" => $bookings['results']
			)
		);
		return $data;
	}

	public function resource_schedule()
	{
		$dates = Sanitizer::get_var('dates');
		if (!$dates || !is_array($dates))
		{
			$dates = array(Sanitizer::get_var('date'));
		}

		$results = array();

		foreach ($dates as $date)
		{
			$_date = new DateTime($date);
			$bookings = $this->bo->resource_schedule(Sanitizer::get_var('resource_id', 'int'), $_date);
			foreach ($bookings['results'] as &$booking)
			{
				$booking['link'] = $this->link(array(
					'menuaction' => 'bookingfrontend.uibooking.show',
					'id' => $booking['id']
				));
				array_walk($booking, array($this, 'item_link'));

				$results[] = $booking;
			}
		}

		$data = array(
			'ResultSet' => array(
				"totalResultsAvailable" => count($results),
				"Result" => $results
			)
		);

		return $data;
	}

	public function organization_schedule()
	{
		$date = new DateTime(Sanitizer::get_var('date'));
		$organization_id = Sanitizer::get_var('organization_id');

		$_building_ids = $this->building_bo->find_buildings_used_by($organization_id)['results'];

		$building_ids = array();
		foreach ($_building_ids as $building)
		{
			$building_ids[] = $building['id'];
		}

		$groups = $this->group_bo->so->read(array('filters' => array(
			'organization_id' => $organization_id,
			'active' => 1
		), 'results' => -1));

		$group_ids = array();
		foreach ($groups['results'] as $group)
		{
			$group_ids[] = $group['id'];
		}

		$bookings = $this->bo->organization_schedule($date, $organization_id, $building_ids, $group_ids);

		$results = array();
		$resources = array();

		foreach ($bookings['results'] as &$booking)
		{
			$booking['link'] = $this->link(array(
				'menuaction' => 'bookingfrontend.uibooking.show',
				'id' => $booking['id']
			));
			array_walk($booking, array($this, 'item_link'));

			$results[] = $booking;
			if (isset($booking['resource_id']) && !isset($resources[$booking['resource_id']]))
			{
				$resources[$booking['resource_id']] = array(
					'id' => $booking['resource_id'],
					'name' => $booking['resource'],
					'building_name' => $booking['building_name'],
					'building_id' => $booking['building_id']
				);
			}
		}
		if (!empty($resources))
		{
			$towns = $this->building_bo->get_towns_for_buildings(array_column($resources, 'building_id'));
		} else
		{
			$towns = array();
		}
		$data = array(
			'ResultSet' => array(
				"totalResultsAvailable" => count($results),
				"Result" => $results
			),
			'resources' => array_values($resources),
			'towns' => $towns

		);

		return $data;
	}


	public function add()
	{
		$errors = array();
		$booking = array();
		$booking['building_id'] = Sanitizer::get_var('building_id', 'int');
		$from_org = Sanitizer::get_var('from_org', 'boolean', "REQUEST", false);
		$allocation_id = Sanitizer::get_var('allocation_id', 'int');
		#The string replace is a workaround for a problem at Bergen Kommune

		$timezone = !empty($this->userSettings['preferences']['common']['timezone']) ?
			$this->userSettings['preferences']['common']['timezone'] : 'UTC';

		$booking['from_'] = str_replace('%3A', ':', Sanitizer::get_var('from_', 'string', 'GET'));
		$booking['to_'] = str_replace('%3A', ':', Sanitizer::get_var('to_', 'string', 'GET'));

		// Add Unix timestamp support with timezone
		if (is_numeric($booking['from_']) && strlen($booking['from_']) === 10)
		{
			$dt = new DateTime();
			$dt->setTimestamp((int)$booking['from_']);
			$dt->setTimezone(new DateTimeZone($timezone));
			$booking['from_'] = $dt->format('Y-m-d H:i:s');
		}
		if (is_numeric($booking['to_']) && strlen($booking['to_']) === 10)
		{
			$dt = new DateTime();
			$dt->setTimestamp((int)$booking['to_']);
			$dt->setTimezone(new DateTimeZone($timezone));
			$booking['to_'] = $dt->format('Y-m-d H:i:s');
		}
		foreach ($booking['from_'] as $k => $v)
		{
			$booking['from_'][$k] = pretty_timestamp($booking['from_'][$k]);
			$booking['to_'][$k] = pretty_timestamp($booking['to_'][$k]);
		}

		$time_from = explode(" ", Sanitizer::get_var('from_', 'string', 'GET'));
		$time_to = explode(" ", Sanitizer::get_var('to_', 'string', 'GET'));

		$step = Sanitizer::get_var('step', 'string', 'REQUEST', 1);
		$invalid_dates = array();
		$valid_dates = array();

		if ($allocation_id)
		{
			$allocation = $this->allocation_bo->read_single($allocation_id);
			$boapplication = CreateObject('booking.boapplication');
			$application = $boapplication->read_single($allocation['application_id']);
			$activity_id = $application['activity_id'];
			$season = $this->season_bo->read_single($allocation['season_id']);
			$building = $this->building_bo->read_single($season['building_id']);
			$booking['season_id'] = $season['id'];
			$booking['building_id'] = $building['id'];
			$booking['building_name'] = $building['name'];
			$booking['allocation_id'] = $allocation_id;
			$booking['application_id'] = $allocation['application_id'];
			array_set_default($booking, 'resources', array(Sanitizer::get_var('resource')));
			array_set_default($booking, 'resource_ids', Sanitizer::get_var('resource_ids'));
		} else
		{
			$season = $this->season_bo->read_single($_POST['season_id']);
		}
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{

			$today = getdate();
			$booking = extract_values($_POST, $this->fields);

			$booking['application_id'] = $allocation['application_id'];

			$timestamp = phpgwapi_datetime::date_to_timestamp($booking['from_']);
			$booking['from_'] = date("Y-m-d H:i:s", $timestamp);
			$timestamp = phpgwapi_datetime::date_to_timestamp($booking['to_']);
			$booking['to_'] = date("Y-m-d H:i:s", $timestamp);

			if (strlen($_POST['from_']) < 6)
			{
				$date_from = array($time_from[0], $_POST['from_']);
				$booking['from_'] = join(" ", $date_from);
				$_POST['from_'] = join(" ", $date_from);
				$date_to = array($time_to[0], $_POST['to_']);
				$booking['to_'] = join(" ", $date_to);
				$_POST['to_'] = join(" ", $date_to);
			}
			$booking['building_name'] = $building['name'];
			$booking['building_id'] = $building['id'];
			$booking['active'] = '1';
			$booking['cost'] = 0;
			$booking['completed'] = '0';
			$booking['reminder'] = '1';
			$booking['secret'] = $this->generate_secret();
			array_set_default($booking, 'audience', array());
			array_set_default($booking, 'agegroups', array());
			array_set_default($_POST, 'resources', array());
			$this->agegroup_bo->extract_form_data($booking);

			$errors = $this->bo->validate($booking);


			if (!$season['id'] && $_POST['outseason'] == 'on')
			{
				$errors['booking'] = lang('This booking is not connected to a season');
			}

			if (!$errors)
			{
				$step++;
			}

			if ($errors && Sanitizer::get_var('repeat_until', 'bool'))
			{
				$_POST['repeat_until'] = date("Y-m-d", phpgwapi_datetime::date_to_timestamp($_POST['repeat_until']));
			}
			if (!$errors && $_POST['recurring'] != 'on' && $_POST['outseason'] != 'on')
			{
				$receipt = $this->bo->add($booking);

				if ($from_org && $allocation_id)
				{
					self::redirect(array(
						'menuaction' => 'bookingfrontend.uiorganization.show',
						'id' => $allocation['organization_id'],
						'date' => date("Y-m-d", strtotime($booking['from_']))
					));
				} else
				{
					self::redirect(array(
						'menuaction' => 'bookingfrontend.uibuilding.show',
						'id' => $booking['building_id'],
						'date' => date("Y-m-d", strtotime($booking['from_']))
					));
				}
			} else if (($_POST['recurring'] == 'on' || $_POST['outseason'] == 'on') && !$errors && $step > 1)
			{
				if (Sanitizer::get_var('repeat_until', 'bool'))
				{
					$repeat_until = phpgwapi_datetime::date_to_timestamp($_POST['repeat_until']) + 60 * 60 * 24;
					/*hack to preserve dateformat for next step*/
					$_POST['repeat_until'] = date("Y-m-d", phpgwapi_datetime::date_to_timestamp($_POST['repeat_until']));
				} else
				{
					$repeat_until = strtotime($season['to_']) + 60 * 60 * 24;
					$_POST['repeat_until'] = $season['to_'];
				}

				$max_dato = phpgwapi_datetime::date_to_timestamp($_POST['to_']); // highest date from input
				$interval = $_POST['field_interval'] * 60 * 60 * 24 * 7; // weeks in seconds
				$i = 0;
				// calculating valid and invalid dates from the first booking's to-date to the repeat_until date is reached
				// the form from step 1 should validate and if we encounter any errors they are caused by double bookings.
				while (($max_dato + ($interval * $i)) <= $repeat_until)
				{
					$fromdate = date('Y-m-d H:i', phpgwapi_datetime::date_to_timestamp($_POST['from_']) + ($interval * $i));
					$todate = date('Y-m-d H:i', phpgwapi_datetime::date_to_timestamp($_POST['to_']) + ($interval * $i));
					$booking['from_'] = $fromdate;
					$booking['to_'] = $todate;
					$fromdate = pretty_timestamp($fromdate);
					$todate = pretty_timestamp($todate);

					$err = $this->bo->validate($booking);

					if ($err)
					{
						$invalid_dates[$i]['from_'] = $fromdate;
						$invalid_dates[$i]['to_'] = $todate;
					} else
					{
						$valid_dates[$i]['from_'] = $fromdate;
						$valid_dates[$i]['to_'] = $todate;
						if ($step == 3)
						{
							$booking['secret'] = $this->generate_secret();
							$receipt = $this->bo->add($booking);
						}
					}
					$i++;
				}
				if ($step == 3)
				{
					if ($from_org && $allocation_id)
					{
						self::redirect(array(
							'menuaction' => 'bookingfrontend.uiorganization.show',
							'id' => $allocation['organization_id'],
							'date' => date("Y-m-d", strtotime($booking['from_']))
						));
					} else
					{
						self::redirect(array(
							'menuaction' => 'bookingfrontend.uibuilding.show',
							'id' => $booking['building_id'],
							'date' => date("Y-m-d", strtotime($booking['from_']))
						));
					}
				}
			}
		}

		$this->flash_form_errors($errors);

		array_set_default($booking, 'resources', array());
		array_set_default($booking, 'resource_ids', array());
		array_set_default($booking, 'audience', array());


		if (!$activity_id)
		{
			$activity_id = Sanitizer::get_var('activity_id', 'int', 'REQUEST', -1);
		}
		$booking['activity_id'] = $activity_id;

		$activity_path = $this->activity_bo->get_path($activity_id);
		$top_level_activity = $activity_path ? $activity_path[0]['id'] : -1;

		$booking['resources_json'] = json_encode(array_map('intval', $booking['resources']));
		$booking['resource_ids_json'] = json_encode(array_map('intval', $booking['resource_ids']));

		if ($from_org && $allocation_id)
		{
			$booking['cancel_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uiorganization.show',
				'id' => $allocation['organization_id'],
				'date' => date("Y-m-d", strtotime($booking['from_']))
			));
		} else
		{
			$booking['cancel_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uibuilding.show',
				'id' => $booking['building_id'],
				'date' => date("Y-m-d", strtotime($booking['from_']))
			));
		}

		$agegroups = $this->agegroup_bo->fetch_age_groups($top_level_activity);
		$agegroups = $agegroups['results'];
		$booking['agegroups_json'] = json_encode($booking['agegroups']);
		$audience = $this->audience_bo->fetch_target_audience($top_level_activity);
		$audience = $audience['results'];
		$booking['audience_json'] = json_encode(array_map('intval', (array)$booking['audience']));
		$activities = $this->activity_bo->fetch_activities();
		$activities = $activities['results'];
		$groups = $this->group_bo->so->read(array('filters' => array(
			'organization_id' => $allocation['organization_id'],
			'active' => 1
		), 'results' => -1));
		$groups = $groups['results'];
		$booking['organization_name'] = $allocation['organization_name'];
		$resources_full = $this->resource_bo->so->read(array('filters' => array(
			'id' => $booking['resources']
		), 'sort' => 'name'));
		$res_names = array();
		foreach ($resources_full['results'] as $res)
		{
			$res_names[] = array('id' => $res['id'], 'name' => $res['name']);
		}

		$booking['from_'] = pretty_timestamp($booking['from_']);
		$booking['to_'] = pretty_timestamp($booking['to_']);

		$jqcal2 = createObject('phpgwapi.jqcal2');
		$jqcal2->add_listener('field_from', 'datetime', phpgwapi_datetime::date_to_timestamp($booking['from_']));
		$jqcal2->add_listener('field_to', 'datetime', phpgwapi_datetime::date_to_timestamp($booking['to_']));
		$jqcal2->add_listener('field_repeat_until', 'date');

		phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date',
			'security',
			'file'
		), 'booking_form');

		if ($step < 2)
		{
			self::add_javascript('bookingfrontend', 'base', 'booking.js');
			phpgwapi_jquery::load_widget('daterangepicker');

			self::render_template_xsl(
				'booking_new',
				array(
					'booking' => $booking,
					'activities' => $activities,
					'agegroups' => $agegroups,
					'audience' => $audience,
					'groups' => $groups,
					'step' => $step,
					'interval' => $_POST['field_interval'],
					'repeat_until' => pretty_timestamp($_POST['repeat_until']),
					'recurring' => $_POST['recurring'],
					'outseason' => $_POST['outseason'],
					'date_from' => $time_from[0],
					'date_to' => $time_to[0],
					'res_names' => $res_names
				)
			);
		} else if ($step == 2)
		{
			self::render_template_xsl(
				'booking_new_preview',
				array(
					'booking' => $booking,
					'activities' => $activities,
					'agegroups' => $agegroups,
					'audience' => $audience,
					'step' => $step,
					'recurring' => $_POST['recurring'],
					'outseason' => $_POST['outseason'],
					'interval' => $_POST['field_interval'],
					'repeat_until' => pretty_timestamp($_POST['repeat_until']),
					'from_date' => $_POST['from_'],
					'to_date' => $_POST['to_'],
					'valid_dates' => $valid_dates,
					'invalid_dates' => $invalid_dates,
					'groups' => $groups
				)
			);
		}
	}

	public function report_numbers()
	{
		$step = 1;
		$id = Sanitizer::get_var('id', 'int');
		$booking = $this->bo->read_single($id);

		$activity_path = $this->activity_bo->get_path($booking['activity_id']);
		$top_level_activity = $activity_path ? $activity_path[0]['id'] : -1;

		$agegroups = $this->agegroup_bo->fetch_age_groups($top_level_activity);
		$agegroups = $agegroups['results'];
		$building = $this->building_bo->read_single($booking['building_id']);

		$interval = (new DateTime($booking['from_']))->diff(new DateTime($booking['to_']));
		$when = "";
		if ($interval->days > 0)
		{
			$when = pretty_timestamp($booking['from_']) . ' - ' . pretty_timestamp($booking['to_']);
		} else
		{
			$end = new DateTime($booking['to_']);
			$when = pretty_timestamp($booking['from_']) . ' - ' . $end->format('H:i');
		}
		$booking['when'] = $when;
		if ($booking['secret'] != Sanitizer::get_var('secret', 'string'))
		{
			$step = -1; // indicates that an error message should be displayed in the template
			self::render_template_xsl('report_numbers', array(
				'event_object' => $booking,
				'agegroups' => $agegroups,
				'building' => $building,
				'step' => $step
			));
			return false;
		}

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			//reformatting the post variable to fit the booking object
			$temp_agegroup = array();
			$sexes = array('male', 'female');
			foreach ($sexes as $sex)
			{
				$i = 0;
				foreach (Sanitizer::get_var($sex, 'string', 'POST') as $agegroup_id => $value)
				{
					$temp_agegroup[$i]['agegroup_id'] = $agegroup_id;
					$temp_agegroup[$i][$sex] = $value;
					$i++;
				}
			}

			$booking['agegroups'] = $temp_agegroup;
			$booking['reminder'] = 2; // status set to delivered
			$errors = $this->bo->validate($booking);
			if (!$errors)
			{
				$receipt = $this->bo->update($booking);
				$step++;
			}
		}
		self::render_template_xsl('report_numbers', array(
			'event_object' => $booking,
			'agegroups' => $agegroups,
			'building' => $building,
			'step' => $step
		));
	}

	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		$from_org = Sanitizer::get_var('from_org', 'boolean', 'REQUEST', false);
		$booking = $this->bo->read_single($id);
		$booking['building'] = $this->building_bo->so->read_single($booking['building_id']);
		$booking['building_name'] = $booking['building']['name'];
		$group = $this->group_bo->read_single($booking['group_id']);
		$errors = array();
		$update_count = 0;
		$today = getdate();
		$step = Sanitizer::get_var('step', 'int');
		array_set_default($booking, 'resource_ids', Sanitizer::get_var('resource_ids'));

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$_POST['from_'] = ($_POST['from_']) ? date("Y-m-d H:i:s", phpgwapi_datetime::date_to_timestamp($_POST['from_'])) : "";
			$_POST['to_'] = ($_POST['to_']) ? date("Y-m-d H:i:s", phpgwapi_datetime::date_to_timestamp($_POST['to_'])) : "";
			$_POST['repeat_until'] = ($_POST['repeat_until']) ? date("Y-m-d H:i:s", phpgwapi_datetime::date_to_timestamp($_POST['repeat_until'])) : "";

			if (!($_POST['recurring'] == 'on' || $_POST['outseason'] == 'on'))
			{

				array_set_default($_POST, 'resources', array());
				$booking = array_merge($booking, extract_values($_POST, $this->fields));
				$this->agegroup_bo->extract_form_data($booking);
				$errors = $this->bo->validate($booking);

				#					if (strtotime($_POST['from_']) < ($today[0]-60*60*24*7*2))
				#					{
				#						$errors['booking'] = lang('You cant edit a booking that is older than 2 weeks');
				#					}
				$temp_date = date_format(date_create($_POST['from_']), "Y-m-d");
				if (!$errors)
				{
					$receipt = $this->bo->update($booking);

					if ($from_org)
					{
						self::redirect(array(
							'menuaction' => 'bookingfrontend.uiorganization.show',
							'id' => $_POST['organization_id'],
							'date' => $temp_date
						));
					} else
					{
						self::redirect(array(
							'menuaction' => 'bookingfrontend.uibuilding.show',
							'id' => $booking['building_id'],
							'date' => $temp_date
						));
					}
				}
			} else
			{
				$step++;

				if (strtotime($_POST['from_']) < ($today[0] - 60 * 60 * 24 * 7 * 2) && $step != 3)
				{
					$errors['booking'] = lang('You cant update bookings that is older than 2 weeks');
				}

				if (!$errors)
				{

					$season = $this->season_bo->read_single($booking['season_id']);

					if ($_POST['recurring'] == 'on')
					{
						$repeat_until = strtotime($_POST['repeat_until']) + 60 * 60 * 24;
					} else
					{
						$repeat_until = strtotime($season['to_']) + 60 * 60 * 24;
						$_POST['repeat_until'] = $season['to_'];
					}

					$where_clauses[] = sprintf("bb_booking.from_ >= '%s 00:00:00'", date('Y-m-d', strtotime($booking['from_'])));
					if ($_POST['recurring'] == 'on')
					{
						$where_clauses[] = sprintf("bb_booking.to_ < '%s 00:00:00'", date('Y-m-d', $repeat_until));
					}
					$where_clauses[] = sprintf("EXTRACT(DOW FROM bb_booking.from_) in (%s)", date('w', strtotime($booking['from_'])));
					$where_clauses[] = sprintf("EXTRACT(HOUR FROM bb_booking.from_) = %s", date('H', strtotime($booking['from_'])));
					$where_clauses[] = sprintf("EXTRACT(MINUTE FROM bb_booking.from_) = %s", date('i', strtotime($booking['from_'])));
					$where_clauses[] = sprintf("EXTRACT(HOUR FROM bb_booking.to_) = %s", date('H', strtotime($booking['to_'])));
					$where_clauses[] = sprintf("EXTRACT(MINUTE FROM bb_booking.to_) = %s", date('i', strtotime($booking['to_'])));
					$params['sort'] = 'from_';
					$params['filters']['where'] = $where_clauses;
					$params['filters']['season_id'] = $booking['season_id'];
					$params['filters']['group_id'] = $booking['group_id'];
					$bookings = $this->bo->so->read($params);

					if ($step == 2)
					{
						$_SESSION['audience'] = $_POST['audience'];
						$_SESSION['male'] = $_POST['male'];
						$_SESSION['female'] = $_POST['female'];
						$_SESSION['from'] = mb_strcut($_POST['from_'], 11, strlen($_POST['from_']));
						$_SESSION['to'] = mb_strcut($_POST['to_'], 11, strlen($_POST['to_']));
					}
					if ($step == 3)
					{
						foreach ($bookings['results'] as $b)
						{
							//reformatting the post variable to fit the booking object
							$temp_agegroup = array();
							$sexes = array('male', 'female');
							foreach ($sexes as $sex)
							{
								$i = 0;
								foreach ($_SESSION[$sex] as $agegroup_id => $value)
								{
									$temp_agegroup[$i]['agegroup_id'] = $agegroup_id;
									$temp_agegroup[$i][$sex] = $value;
									$i++;
								}
							}
							$b['agegroups'] = $temp_agegroup;
							$b['audience'] = $_SESSION['audience'];
							$b['group_id'] = $_POST['group_id'];
							$b['activity_id'] = $_POST['activity_id'];
							$b['from_'] = mb_strcut($b['from_'], 0, 11) . $_SESSION['from'];
							$b['to_'] = mb_strcut($b['to_'], 0, 11) . $_SESSION['to'];
							$errors = $this->bo->validate($b);
							if (!$errors)
							{
								$receipt = $this->bo->update($b);
								$update_count++;
							}
						}
						unset($_SESSION['female']);
						unset($_SESSION['male']);
						unset($_SESSION['audience']);
					}
				}
			}
		}
		$this->flash_form_errors($errors);

		if ($step < 2)
		{
			self::add_javascript('bookingfrontend', 'base', 'booking.js');
			phpgwapi_jquery::load_widget('daterangepicker');
			$booking['resources_json'] = json_encode(array_map('intval', $booking['resources']));
			$booking['resource_ids_json'] = json_encode(array_map('intval', $booking['resource_ids']));
			$booking['organization_name'] = $group['organization_name'];
			$booking['organization_id'] = $group['organization_id'];
		}

		$activity_path = $this->activity_bo->get_path($booking['activity_id']);
		$top_level_activity = $activity_path ? $activity_path[0]['id'] : -1;
		$agegroups = $this->agegroup_bo->fetch_age_groups($top_level_activity);
		$agegroups = $agegroups['results'];
		$audience = $this->audience_bo->fetch_target_audience($top_level_activity);
		$audience = $audience['results'];
		$activities = $this->activity_bo->fetch_activities();
		$activities = $activities['results'];
		$booking['audience_json'] = json_encode(array_map('intval', (array)$booking['audience']));
		$booking['agegroups_json'] = json_encode($booking['agegroups']);
		$groups = $this->group_bo->so->read(array('filters' => array(
			'organization_id' => $group['organization_id'],
			'active' => 1
		)));
		$groups = $groups['results'];

		if ($from_org)
		{
			$booking['cancel_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uiorganization.show',
				'id' => $group['organization_id'],
				'date' => date("Y-m-d", strtotime($booking['from_']))
			));
		} else
		{
			$booking['cancel_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uibuilding.show',
				'id' => $booking['building_id'],
				'date' => date("Y-m-d", strtotime($booking['from_']))
			));
		}

		$booking['from_'] = pretty_timestamp($booking['from_']);
		$booking['to_'] = pretty_timestamp($booking['to_']);

		$jqcal2 = createObject('phpgwapi.jqcal2');

		$jqcal2->add_listener('field_from', 'datetime', phpgwapi_datetime::date_to_timestamp($booking['from_']));
		$jqcal2->add_listener('field_to', 'datetime', phpgwapi_datetime::date_to_timestamp($booking['to_']));

		$jqcal2->add_listener('field_repeat_until', 'date');


		foreach ($bookings['results'] as &$b)
		{
			$b['from_'] = pretty_timestamp($b['from_']);
			$b['to_'] = pretty_timestamp($b['to_']);
		}

		phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date',
			'security',
			'file'
		), 'booking_form');

		if ($step < 2)
		{
			self::render_template_xsl(
				'booking_edit',
				array(
					'booking' => $booking,
					'activities' => $activities,
					'agegroups' => $agegroups,
					'audience' => $audience,
					'groups' => $groups,
					'step' => $step,
					'repeat_until' => $_POST['repeat_until'],
					'recurring' => $_POST['recurring'],
					'outseason' => $_POST['outseason'],
				)
			);
		} else if ($step >= 2)
		{
			self::render_template_xsl(
				'booking_edit_preview',
				array(
					'booking' => $booking,
					'bookings' => $bookings,
					'agegroups' => $agegroups,
					'audience' => $audience,
					'groups' => $groups,
					'activities' => $activities,
					'step' => $step,
					'repeat_until' => pretty_timestamp($_POST['repeat_until']),
					'recurring' => $_POST['recurring'],
					'outseason' => $_POST['outseason'],
					'group_id' => $_POST['group_id'],
					'activity_id' => $_POST['activity_id'],
					'update_count' => $update_count
				)
			);
		}
	}

	public function massupdate()
	{
		$id = Sanitizer::get_var('id', 'int');
		$booking = $this->bo->read_single($id);
		$booking['building'] = $this->building_bo->so->read_single($booking['building_id']);
		$booking['building_name'] = $booking['building']['name'];
		$allocation = $this->allocation_bo->read_single($booking['allocation_id']);
		$errors = array();
		$update_count = 0;
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$step = Sanitizer::get_var('step', 'int', 'POST');
			$step++;

			$season = $this->season_bo->read_single($booking['season_id']);

			$where_clauses[] = sprintf("EXTRACT(DOW FROM bb_booking.from_) in (%s)", date('w', strtotime($booking['from_'])));
			$where_clauses[] = sprintf("EXTRACT(HOUR FROM bb_booking.from_) = %s", date('H', strtotime($booking['from_'])));
			$where_clauses[] = sprintf("EXTRACT(MINUTE FROM bb_booking.from_) = %s", date('i', strtotime($booking['from_'])));
			$where_clauses[] = sprintf("EXTRACT(HOUR FROM bb_booking.to_) = %s", date('H', strtotime($booking['to_'])));
			$where_clauses[] = sprintf("EXTRACT(MINUTE FROM bb_booking.to_) = %s", date('i', strtotime($booking['to_'])));
			$where_clauses[] = sprintf("bb_booking.from_ >= '%s 00:00:00'", date('Y-m-d'));
			$params['sort'] = 'from_';
			$params['filters']['where'] = $where_clauses;
			$params['filters']['season_id'] = $booking['season_id'];
			$params['filters']['group_id'] = $booking['group_id'];
			$booking = $this->bo->so->read($params);

			if ($step == 2)
			{
				$_SESSION['audience'] = $_POST['audience'];
				$_SESSION['male'] = $_POST['male'];
				$_SESSION['female'] = $_POST['female'];
			}

			if ($step == 3)
			{
				foreach ($booking['results'] as $b)
				{
					//reformatting the post variable to fit the booking object
					$temp_agegroup = array();
					$sexes = array('male', 'female');
					foreach ($sexes as $sex)
					{
						$i = 0;
						foreach ($_SESSION[$sex] as $agegroup_id => $value)
						{
							$temp_agegroup[$i]['agegroup_id'] = $agegroup_id;
							$temp_agegroup[$i][$sex] = $value;
							$i++;
						}
					}

					$b['agegroups'] = $temp_agegroup;
					$b['audience'] = $_SESSION['audience'];
					$b['group_id'] = $_POST['group_id'];
					$b['activity_id'] = $_POST['activity_id'];
					$errors = $this->bo->validate($b);
					if (!$errors)
					{
						$receipt = $this->bo->update($b);
						$update_count++;
					}
				}
				unset($_SESSION['female']);
				unset($_SESSION['male']);
				unset($_SESSION['audience']);
			}
			foreach ($booking['results'] as &$b)
			{
				$b['from_'] = pretty_timestamp($b['from_']);
				$b['to_'] = pretty_timestamp($b['to_']);
			}
		}

		$this->flash_form_errors($errors);

		$activity_path = $this->activity_bo->get_path($booking['activity_id']);
		$top_level_activity = $activity_path ? $activity_path[0]['id'] : -1;

		$agegroups = $this->agegroup_bo->fetch_age_groups($top_level_activity);
		$agegroups = $agegroups['results'];
		$audience = $this->audience_bo->fetch_target_audience($top_level_activity);
		$audience = $audience['results'];
		$booking['audience_json'] = json_encode(array_map('intval', (array)$booking['audience']));

		$group = $this->group_bo->so->read_single($booking['group_id']);
		$groups = $this->group_bo->so->read(array('filters' => array(
			'organization_id' => $group['organization_id'],
			'active' => 1
		)));
		$groups = $groups['results'];

		$activities = $this->activity_bo->fetch_activities();
		$activities = $activities['results'];

		self::add_javascript('bookingfrontend', 'base', 'booking_massupdate.js');

		phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date',
			'security',
			'file'
		), 'booking_form');

		self::render_template_xsl(
			'booking_massupdate',
			array(
				'booking' => $booking,
				'agegroups' => $agegroups,
				'audience' => $audience,
				'groups' => $groups,
				'activities' => $activities,
				'step' => $step,
				'group_id' => $_POST['group_id'],
				'activity_id' => $_POST['activity_id'],
				'update_count' => $update_count,
			)
		);
	}

	public function building_users($building_id, $group_id, $type = false, $activities = array())
	{
		$contacts = array();
		$organizations = $this->organization_bo->find_building_users($building_id, $type, $activities);
		foreach ($organizations['results'] as $org)
		{
			if ($org['email'] != '' && strstr($org['email'], '@'))
			{
				if (!in_array($org['email'], $contacts))
				{
					$contacts[] = $org['email'];
				}
			}
			if ($org['contacts'][0]['email'] != '' && strstr($org['contacts'][0]['email'], '@'))
			{
				if (!in_array($org['contacts'][0]['email'], $contacts))
				{
					$contacts[] = $org['contacts'][0]['email'];
				}
			}
			if ($org['contacts'][1]['email'] != '' && strstr($org['contacts'][1]['email'], '@'))
			{
				if (!in_array($org['contacts'][1]['email'], $contacts))
				{
					$contacts[] = $org['contacts'][1]['email'];
				}
			}
			$grp_con = $this->bo->so->get_group_contacts_of_organization($org['id']);
			foreach ($grp_con as $grp)
			{
				if ($grp['email'] != '' && strstr($grp['email'], '@') && $grp['group_id'] != $group_id)
				{
					if (!in_array($grp['email'], $contacts))
					{
						$contacts[] = $grp['email'];
					}
				}
			}
		}
		return $contacts;
	}

	public function resource_users($resources, $group_id)
	{
		$contacts = array();
		$orglist = '';
		foreach ($resources as $res)
		{
			$cres = $this->resource_bo->read_single($res);
			if ($cres['organizations_ids'] != '')
			{
				$orglist .= $cres['organizations_ids'] . ',';
			}
		}
		$orgs = explode(",", rtrim($orglist, ","));


		$organizations = $this->organization_bo->so->read(array(
			'filters' => array('id' => $orgs),
			'sort' => 'name'
		));
		foreach ($organizations['results'] as $org)
		{
			if ($org['email'] != '' && strstr($org['email'], '@'))
			{
				if (!in_array($org['email'], $contacts))
				{
					$contacts[] = $org['email'];
				}
			}
			if ($org['contacts'][0]['email'] != '' && strstr($org['contacts'][0]['email'], '@'))
			{
				if (!in_array($org['contacts'][0]['email'], $contacts))
				{
					$contacts[] = $org['contacts'][0]['email'];
				}
			}
			if ($org['contacts'][1]['email'] != '' && strstr($org['contacts'][1]['email'], '@'))
			{
				if (!in_array($org['contacts'][1]['email'], $contacts))
				{
					$contacts[] = $org['contacts'][1]['email'];
				}
			}
			$grp_con = $this->bo->so->get_group_contacts_of_organization($org['id']);
			foreach ($grp_con as $grp)
			{
				if ($grp['email'] != '' && strstr($grp['email'], '@') && $grp['group_id'] != $group_id)
				{
					if (!in_array($grp['email'], $contacts))
					{
						$contacts[] = $grp['email'];
					}
				}
			}
		}
		return $contacts;
	}

	public function organization_users($group_id)
	{

		$contacts = array();
		$groups = $this->bo->so->get_all_group_of_organization_from_groupid($group_id);
		foreach ($groups as $grp)
		{
			if ($grp['email'] != '' && strstr($grp['email'], '@') && $grp['group_id'] != $group_id)
			{
				if (!in_array($grp['email'], $contacts))
				{
					$contacts[] = $grp['email'];
				}
			}
		}
		return $contacts;
	}

	public function cancel()
	{
		$config = CreateObject('phpgwapi.config', 'booking');
		$config->read();
		$id = Sanitizer::get_var('id', 'int');
		$original_from = null;

		$from_org = Sanitizer::get_var('from_org', 'boolean', 'REQUEST', false);

		if ($config->config_data['user_can_delete_bookings'] != 'yes')
		{
			Cache::message_set('user can not delete bookings', 'error');

			$booking = $this->bo->read_single($id);
			$original_from = $booking['from_'];
			$errors = array();
			if ($_SERVER['REQUEST_METHOD'] == 'POST')
			{
				$_POST['from_'] = date("Y-m-d H:i:s", phpgwapi_datetime::date_to_timestamp($_POST['from_']));
				$_POST['to_'] = date("Y-m-d H:i:s", phpgwapi_datetime::date_to_timestamp($_POST['to_']));
				$_POST['repeat_until'] = isset($_POST['repeat_until']) && $_POST['repeat_until'] ? date("Y-m-d", phpgwapi_datetime::date_to_timestamp($_POST['repeat_until'])) : false;

				$from = $_POST['from_'];
				$to = $_POST['to_'];
				$organization_id = $_POST['organization_id'];
				$outseason = $_POST['outseason'];
				$recurring = $_POST['recurring'];
				$repeat_until = $_POST['repeat_until'];
				$field_interval = $_POST['field_interval'];
				$delete_allocation = $_POST['delete_allocation'];
				$allocation = $this->allocation_bo->read_single($booking['allocation_id']);

				$maildata = array();
				$maildata['outseason'] = $outseason;
				$maildata['recurring'] = $recurring;
				$maildata['repeat_until'] = $repeat_until;
				$maildata['delete_allocation'] = $delete_allocation;


				date_default_timezone_set("Europe/Oslo");
				$date = new DateTime(Sanitizer::get_var('date'));
				$system_message = array();
				$system_message['building_id'] = intval($booking['building_id']);
				$system_message['building_name'] = $this->bo->so->get_building($system_message['building_id']);
				$system_message['created'] = $date->format('Y-m-d  H:m');
				//					$system_message = array_merge($system_message, extract_values($_POST, array('message')));
				$system_message['message'] = Sanitizer::get_var('message', 'html');
				$system_message['type'] = 'cancelation';
				$system_message['status'] = 'NEW';
				$system_message['name'] = $booking['group_name'];
				$system_message['phone'] = ' ';
				$system_message['email'] = ' ';
				$system_message['title'] = lang('Cancelation of booking from') . " " . $booking['group_name'];

				$link = self::link(array(
					'menuaction' => 'booking.uibooking.delete',
					'id' => $booking['id'],
					'outseason' => $outseason,
					'recurring' => $recurring,
					'repeat_until' => $repeat_until,
					'field_interval' => $field_interval,
					'delete_allocation' => $delete_allocation
				));
				$link = mb_strcut($link, 16, strlen($link));
				$system_message['message'] = $system_message['message'] . "\n\n" . lang('To cancel booking use this link') . " - <a href='" . $link . "'>" . lang('Delete') . "</a>";
				$this->bo->send_admin_notification($booking, $maildata, $system_message, $allocation);
				$receipt = $this->system_message_bo->add($system_message);

				if ($from_org)
				{
					self::redirect(array(
						'menuaction' => 'bookingfrontend.uiorganization.show',
						'id' => $organization_id,
						'date' => date("Y-m-d", strtotime($original_from))
					));
				} else
				{
					self::redirect(array(
						'menuaction' => 'bookingfrontend.uibuilding.show',
						'id' => $system_message['building_id'],
						'date' => date("Y-m-d", strtotime($original_from))
					));
				}
			}

			$booking['cancel_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uibuilding.show',
				'id' => $booking['building_id'],
				'date' => date("Y-m-d", strtotime($original_from))
			));
			$booking['from_'] = pretty_timestamp($booking['from_']);
			$booking['to_'] = pretty_timestamp($booking['to_']);

			$jqcal2 = createObject('phpgwapi.jqcal2');

			$jqcal2->add_listener('field_repeat_until', 'date');
			$booking['resources_json'] = json_encode(array_map('intval', $booking['resources']));

			$this->flash_form_errors($errors);

			self::rich_text_editor('field-message');
			self::render_template_xsl('booking_cancel', array('booking' => $booking));
		} else
		{
			$outseason = Sanitizer::get_var('outseason', 'string');
			$recurring = Sanitizer::get_var('recurring', 'string');
			$repeat_until = Sanitizer::get_var('repeat_until', 'string');
			$field_interval = Sanitizer::get_var('field_interval', 'int');
			$delete_allocation = Sanitizer::get_var('delete_allocation');
			$booking = $this->bo->read_single($id);
			$original_from = $booking['from_'];
			$allocation = $this->allocation_bo->read_single($booking['allocation_id']);
			$season = $this->season_bo->read_single($booking['season_id']);
			$step = Sanitizer::get_var('step', 'int', 'POST');
			if ($step)
			{
				$step = 1;
			}
			$errors = array();
			$invalid_dates = array();
			$valid_dates = array();
			$allocation_delete = array();
			$allocation_keep = array();

			if ($config->config_data['split_pool'] == 'yes')
			{
				$split = 1;
			} else
			{
				$split = 0;
			}
			$resources = $booking['resources'];
			$activity = $this->organization_bo->so->get_resource_activity($resources);
			$mailadresses = $this->building_users($booking['building_id'], $booking['group_id'], $split, $activity);

			$extra_mailadresses = $this->resource_users($resources, $booking['group_id']);
			$mailadresses = array_merge($mailadresses, $extra_mailadresses);

			$maildata = array();
			$maildata['outseason'] = $outseason;
			$maildata['recurring'] = $recurring;
			$maildata['repeat_until'] = $repeat_until;
			$maildata['delete_allocation'] = $delete_allocation;

			if ($_SERVER['REQUEST_METHOD'] == 'POST')
			{
				$_POST['from_'] = date("Y-m-d H:i:s", phpgwapi_datetime::date_to_timestamp($_POST['from_']));
				$_POST['to_'] = date("Y-m-d H:i:s", phpgwapi_datetime::date_to_timestamp($_POST['to_']));
				$_POST['repeat_until'] = isset($_POST['repeat_until']) && $_POST['repeat_until'] ? date("Y-m-d", phpgwapi_datetime::date_to_timestamp($_POST['repeat_until'])) : false;

				$from_date = $_POST['from_'];
				$to_date = $_POST['to_'];
				$info_deleted = '';
				$inf_del = '';

				if ($_POST['recurring'] != 'on' && $_POST['outseason'] != 'on')
				{

					if ($_POST['delete_allocation'] != 'on')
					{

						$inf_del = "Booking";
						$maildata['allocation'] = 0;
						$this->bo->so->delete_booking($id);
					} else
					{
						$allocation_id = $booking['allocation_id'];
						$this->bo->so->delete_booking($id);
						$err = $this->allocation_bo->so->check_for_booking($allocation_id);
						if ($err)
						{
							$inf_del = "Booking";
							$maildata['allocation'] = 0;
							$errors['booking'] = lang('Could not delete allocation due to a booking still use it');
						} else
						{
							$inf_del = "Booking and allocation";
							$maildata['allocation'] = 1;
							$err = $this->allocation_bo->so->delete_allocation($allocation_id);
						}
					}

					$res_names = '';
					date_default_timezone_set("Europe/Oslo");
					$date = new DateTime(Sanitizer::get_var('date'));
					$system_message = array();
					$system_message['building_id'] = intval($booking['building_id']);
					$system_message['building_name'] = $this->bo->so->get_building($system_message['building_id']);
					$system_message['created'] = $date->format('Y-m-d  H:m');
					//						$system_message = array_merge($system_message, extract_values($_POST, array('message')));
					$system_message['message'] = Sanitizer::get_var('message', 'html');
					$system_message['type'] = 'cancelation';
					$system_message['status'] = 'NEW';
					$system_message['name'] = $booking['group_name'];
					$system_message['phone'] = ' ';
					$system_message['email'] = ' ';
					$system_message['title'] = lang("Cancelation of " . $inf_del . " from") . " " . $this->bo->so->get_organization($booking['group_id']) . " - " . $booking['group_name'];
					foreach ($booking['resources'] as $res)
					{
						$res_names = $res_names . $this->bo->so->get_resource($res) . " ";
					}
					$info_deleted = lang("Deleted on") . " " . $system_message['building_name'] . ":<br />" . $res_names . " - " . pretty_timestamp($booking['from_']) . " - " . pretty_timestamp($booking['to_']);
					$this->bo->send_admin_notification($booking, $maildata, $system_message, $allocation);
					$this->bo->send_notification($booking, $allocation, $maildata, $mailadresses);
					$system_message['message'] = $system_message['message'] . "<br />" . $info_deleted;
					$receipt = $this->system_message_bo->add($system_message);


					if ($from_org && $allocation !== null)
					{
						self::redirect(array(
							'menuaction' => 'bookingfrontend.uiorganization.show',
							'id' => $allocation['organization_id'],
							'date' => date("Y-m-d", strtotime($original_from))
						));
					} else
					{
						self::redirect(array(
							'menuaction' => 'bookingfrontend.uibuilding.show',
							'id' => $booking['building_id'],
							'date' => date("Y-m-d", strtotime($original_from))
						));
					}
				} else
				{
					$step++;
					if ($_POST['recurring'] == 'on')
					{
						$repeat_until = strtotime($_POST['repeat_until']) + 60 * 60 * 24;
					} else
					{
						$repeat_until = strtotime($season['to_']) + 60 * 60 * 24;
						$_POST['repeat_until'] = $season['to_'];
					}

					$max_dato = strtotime($_POST['to_']); // highest date from input
					$interval = $_POST['field_interval'] * 60 * 60 * 24 * 7; // weeks in seconds
					$i = 0;
					// calculating valid and invalid dates from the first booking's to-date to the repeat_until date is reached
					// the form from step 1 should validate and if we encounter any errors they are caused by double bookings.

					while (($max_dato + ($interval * $i)) <= $repeat_until)
					{
						$fromdate = date('Y-m-d H:i', strtotime($_POST['from_']) + ($interval * $i));
						$todate = date('Y-m-d H:i', strtotime($_POST['to_']) + ($interval * $i));
						$booking['from_'] = $fromdate;
						$booking['to_'] = $todate;
						$info_deleted = '';
						$inf_del = '';

						$fromdate = pretty_timestamp($fromdate);
						$todate = pretty_timestamp($todate);

						$id = $this->bo->so->get_booking_id($booking);
						if ($id)
						{
							$aid = $this->bo->so->check_allocation($id);
						} else
						{
							$aid = $this->bo->so->check_for_booking($booking);
						}

						if ($id)
						{
							$valid_dates[$i]['from_'] = $fromdate;
							$valid_dates[$i]['to_'] = $todate;
							if ($step == 3)
							{
								$inf_del = "Bookings";
								$stat = $this->bo->so->delete_booking($id);
							}
						}
						if ($_POST['delete_allocation'] == 'on')
						{
							if (!$aid)
							{
								$allocation_keep[$i]['from_'] = $fromdate;
								$allocation_keep[$i]['to_'] = $todate;
							} else
							{
								$allocation_delete[$i]['from_'] = $fromdate;
								$allocation_delete[$i]['to_'] = $todate;
								if ($step == 3)
								{
									$inf_del = "Bookings and allocations";
									$stat = $this->bo->so->delete_allocation($aid);
								}
							}
						}
						$i++;
					}
					if ($step == 3)
					{
						$maildata = array();
						$maildata['outseason'] = Sanitizer::get_var('outseason', 'string');
						$maildata['recurring'] = Sanitizer::get_var('recurring', 'string');
						$maildata['repeat_until'] = Sanitizer::get_var('repeat_until', 'string');
						$maildata['delete_allocation'] = Sanitizer::get_var('delete_allocation');
						$maildata['keep'] = $allocation_keep;
						$maildata['delete'] = $allocation_delete;

						$res_names = '';
						date_default_timezone_set("Europe/Oslo");
						$date = new DateTime(Sanitizer::get_var('date'));
						$system_message = array();
						$system_message['building_id'] = intval($booking['building_id']);
						$system_message['building_name'] = $this->bo->so->get_building($system_message['building_id']);
						$system_message['created'] = $date->format('Y-m-d  H:m');
						//							$system_message = array_merge($system_message, extract_values($_POST, array('message')));
						$system_message['message'] = Sanitizer::get_var('message', 'html');
						$system_message['type'] = 'cancelation';
						$system_message['status'] = 'NEW';
						$system_message['name'] = $booking['group_name'];
						$system_message['phone'] = ' ';
						$system_message['email'] = ' ';
						$system_message['title'] = lang("Cancelation of " . $inf_del . " from") . " " . $this->bo->so->get_organization($booking['group_id']) . " - " . $booking['group_name'];
						foreach ($booking['resources'] as $res)
						{
							$res_names = $res_names . $this->bo->so->get_resource($res) . " ";
						}
						$info_deleted = lang($inf_del . " deleted on") . " " . $system_message['building_name'] . ":<br />";
						foreach ($valid_dates as $valid_date)
						{
							$info_deleted = $info_deleted . "<br />" . $res_names . " - " . pretty_timestamp($valid_date['from_']) . " - " . pretty_timestamp($valid_date['to_']);
						}
						$system_message['message'] = $system_message['message'] . "<br />" . $info_deleted;
						$this->bo->send_admin_notification($booking, $maildata, $system_message, $allocation, $valid_dates);
						$this->bo->send_notification($booking, $allocation, $maildata, $mailadresses, $valid_dates);
						$receipt = $this->system_message_bo->add($system_message);

						if ($from_org)
						{
							self::redirect(array(
								'menuaction' => 'bookingfrontend.uiorganization.show',
								'id' => $allocation['organization_id'],
								'date' => date("Y-m-d", strtotime($original_from))
							));
						} else
						{
							self::redirect(array(
								'menuaction' => 'bookingfrontend.uibuilding.show',
								'id' => $allocation['building_id'],
								'date' => date("Y-m-d", strtotime($original_from))
							));
						}
					}
				}
			}

			$this->flash_form_errors($errors);
			if ($config->config_data['user_can_delete_allocations'] != 'yes')
			{
				$user_can_delete_allocations = 0;
			} else
			{
				$user_can_delete_allocations = 1;
			}

			$booking['from_'] = pretty_timestamp($booking['from_']);
			$booking['to_'] = pretty_timestamp($booking['to_']);

			//				self::add_javascript('booking', 'base', 'booking.js');
			$booking['resources_json'] = json_encode(array_map('intval', $booking['resources']));
			#				$booking['cancel_link'] = self::link(array('menuaction' => 'bookingfrontend.uibooking.show', 'id' => $booking['id']));

			if ($from_org && $allocation !== null)
			{
				$booking['cancel_link'] = self::link(array(
					'menuaction' => 'bookingfrontend.uiorganization.show',
					'id' => $allocation['organization_id'],
					'date' => date("Y-m-d", strtotime($original_from))
				));
			} else
			{
				$booking['cancel_link'] = self::link(array(
					'menuaction' => 'bookingfrontend.uibuilding.show',
					'id' => $booking['building_id'],
					'date' => date("Y-m-d", strtotime($original_from))
				));
			}

			$booking['booking_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uibooking.show',
				'id' => $booking['id']
			));

			$jqcal2 = createObject('phpgwapi.jqcal2');

			$jqcal2->add_listener('field_repeat_until', 'date');

			if ($step < 2)
			{
				self::rich_text_editor('field-message');
				self::render_template_xsl('booking_delete', array(
					'booking' => $booking,
					'recurring' => $recurring,
					'outseason' => $outseason,
					'interval' => $field_interval,
					'repeat_until' => $repeat_until,
					'delete_allocation' => $delete_allocation,
					'user_can_delete_allocations' => $user_can_delete_allocations
				));
			} elseif ($step == 2)
			{
				self::render_template_xsl('booking_delete_preview', array(
					'booking' => $booking,
					'step' => $step,
					'recurring' => $_POST['recurring'],
					'outseason' => $_POST['outseason'],
					'interval' => $_POST['field_interval'],
					'repeat_until' => pretty_timestamp($_POST['repeat_until']),
					'from_date' => pretty_timestamp($from_date),
					'to_date' => pretty_timestamp($to_date),
					'delete_allocation' => $_POST['delete_allocation'],
					'user_can_delete_allocations' => $user_can_delete_allocations,
					'allocation_keep' => $allocation_keep,
					'allocation_delete' => $allocation_delete,
					'message' => $_POST['message'],
					'valid_dates' => $valid_dates,
					'invalid_dates' => $invalid_dates
				));
			}
		}
	}

	public function info()
	{
		$config = CreateObject('phpgwapi.config', 'booking')->read();
		if ($config['user_can_delete_bookings'] != 'yes')
		{
			$user_can_delete_bookings = 0;
		} else
		{
			$user_can_delete_bookings = 1;
		}
		$from_org = Sanitizer::get_var('from_org', 'boolean', 'REQUEST', false);
		$booking = $this->bo->read_single(Sanitizer::get_var('id', 'int'));
		$booking['group'] = $this->group_bo->read_single($booking['group_id']);
		$resources = $this->resource_bo->so->read(array(
			'filters' => array('id' => $booking['resources']),
			'sort' => 'name'
		));
		$booking['resources'] = $resources['results'];
		$res_names = array();
		$res_ids = array();
		foreach ($booking['resources'] as $res)
		{
			$res_names[] = $res['name'];
			$res_ids[] = $res['id'];
		}
		$booking['resource_info'] = join(', ', $res_names);
		$booking['resource_ids'] = $res_ids;
		$booking['building_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uibuilding.show',
			'id' => $booking['building_id']
		));
		$booking['org_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uiorganization.show',
			'id' => $booking['group']['organization_id']
		));
		$booking['group_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uigroup.show',
			'id' => $booking['group']['id']
		));

		$bouser = new UserHelper();
		if ($bouser->is_group_admin($booking['group_id']))
		{
			if ($booking['from_'] > Date('Y-m-d H:i:s'))
			{
				$booking['edit_link'] = self::link(array(
					'menuaction' => 'bookingfrontend.uibooking.edit',
					'id' => $booking['id'],
					'resource_ids' => $booking['resource_ids'],
					'from_org' => $from_org
				));

				$booking['cancel_link'] = self::link(array(
					'menuaction' => 'bookingfrontend.uibooking.cancel',
					'id' => $booking['id'],
					'resource_ids' => $booking['resource_ids'],
					'from_org' => $from_org
				));
			}


			if ($booking['application_id'] != null)
			{
				$booking['copy_link'] = self::link(array(
					'menuaction' => 'bookingfrontend.uiapplication.add',
					'application_id' => $booking['application_id']
				));
			}
		}
		$interval = (new DateTime($booking['from_']))->diff(new DateTime($booking['to_']));
		$when = "";
		if ($interval->days > 0)
		{
			$when = pretty_timestamp($booking['from_']) . ' - ' . pretty_timestamp($booking['to_']);
		} else
		{
			$end = new DateTime($booking['to_']);
			$when = pretty_timestamp($booking['from_']) . ' - ' . $end->format('H:i');
		}
		$booking['when'] = $when;
		$booking['show_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uibooking.show',
			'id' => $booking['id']
		));

		$booking['ical_link'] = self::link(array('menuaction' => 'bookingfrontend.uiparticipant.ical', 'reservation_type' => 'booking', 'reservation_id' => $booking['id']));

		$resource_participant_limit_gross = $this->resource_bo->so->get_participant_limit($booking['resources'], true);

		if (!empty($resource_participant_limit_gross['results'][0]['quantity']))
		{
			$resource_participant_limit = $resource_participant_limit_gross['results'][0]['quantity'];
		}

		if (!$booking['participant_limit'])
		{
			$booking['participant_limit'] = $resource_participant_limit ? $resource_participant_limit : (int)$config['participant_limit'];
		}

		$booking['participant_limit'] = $booking['participant_limit'] ? $booking['participant_limit'] : (int)$config['participant_limit'];

		self::render_template_xsl('booking_info', array('booking' => $booking, 'user_can_delete_bookings' => $user_can_delete_bookings));
		phpgwapi_xslttemplates::getInstance()->set_output('wml'); // Evil hack to disable page chrome
	}

	public function info_json()
	{
		$config = CreateObject('phpgwapi.config', 'booking')->read();
		$user_can_delete_bookings = $config['user_can_delete_bookings'] === 'yes' ? 1 : 0;

		// Retrieve multiple booking IDs
		$ids = Sanitizer::get_var('ids', 'string');

		if (is_array($ids))
		{
			// ids is already an array, keep as is
		} elseif ($ids)
		{
			// Convert comma-separated string to array
			$ids = explode(',', $ids);
		} else
		{
			// No ids provided, try single id
			$ids = array(Sanitizer::get_var('id'));
		}

		// Filter out empty values and ensure we have at least one valid ID
		$ids = array_filter($ids);
		if (empty($ids))
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$bookings_info = [];
		foreach ($ids as $id)
		{
			$booking = $this->bo->read_single($id);
			if (!$booking)
			{
				continue;
			}
			$booking['info_group'] = $this->group_bo->read_single($booking['group_id']);
			$booking['info_resource_info'] = $this->calculate_resource_info($booking['resources']);
			$booking['info_building_link'] = self::link([
				'menuaction' => 'bookingfrontend.uibuilding.show',
				'id' => $booking['building_id']
			]);
			$booking['info_group_link'] = self::link([
				'menuaction' => 'bookingfrontend.uigroup.show',
				'id' => $booking['group']['id']
			]);
			$booking['info_when'] = $this->info_format_booking_time($booking['from_'], $booking['to_']);
			$booking['info_participant_limit'] = $this->info_calculate_participant_limit($booking, $config);
			$booking['info_edit_link'] = $this->info_determine_edit_link($booking, $user_can_delete_bookings);
			$booking['info_cancel_link'] = $this->info_determine_cancel_link($booking, $user_can_delete_bookings);
			$booking['info_ical_link'] = self::link([
				'menuaction' => 'bookingfrontend.uiparticipant.ical',
				'reservation_type' => 'booking',
				'reservation_id' => $booking['id']
			]);

			$booking['info_show_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uibooking.show',
				'id' => $booking['id']
			));
			$bookings_info[$id] = $booking;
		}

		return ['bookings' => $bookings_info, 'info_user_can_delete_bookings' => $user_can_delete_bookings];
	}


	private function calculate_resource_info($resourceIds)
	{
		$resources = $this->resource_bo->so->read([
			'filters' => ['id' => $resourceIds],
			'sort' => 'name'
		]);
		$resNames = array_map(function ($res)
		{
			return $res['name'];
		}, $resources['results']);

		return join(', ', $resNames);
	}


	private function info_format_booking_time($from, $to)
	{
		$interval = (new DateTime($from))->diff(new DateTime($to));
		$when = "";
		if ($interval->days > 0)
		{
			$when = pretty_timestamp($from) . ' - ' . pretty_timestamp($to);
		} else
		{
			$end = new DateTime($to);
			$when = pretty_timestamp($from) . ' - ' . $end->format('H:i');
		}
		return $when;
	}

	private function info_calculate_participant_limit($booking, $config)
	{
		$resource_participant_limit_gross = $this->resource_bo->so->get_participant_limit($booking['resources'], true);
		$resource_participant_limit = !empty($resource_participant_limit_gross['results'][0]['quantity']) ? $resource_participant_limit_gross['results'][0]['quantity'] : 0;
		return !$booking['participant_limit'] ? ($resource_participant_limit ?: (int)$config['participant_limit']) : $booking['participant_limit'];
	}

	private function info_determine_edit_link($booking)
	{
		// Check if user is logged in AND is a group admin for this booking
		$bouser = new UserHelper();

		if ($bouser->is_logged_in() && $bouser->is_group_admin($booking['group_id']))
		{
			if ($booking['from_'] > Date('Y-m-d H:i:s'))
			{
				return self::link([
					'menuaction' => 'bookingfrontend.uibooking.edit',
					'id' => $booking['id'],
					'resource_ids' => $booking['resource_ids'],
					'from_org' => Sanitizer::get_var('from_org', 'boolean', 'REQUEST', false)
				]);
			}
		}
		return null; // No edit link if condition not met
	}

	private function info_determine_cancel_link($booking, $user_can_delete_bookings)
	{
		// Check if user is logged in AND is a group admin for this booking
		$bouser = new UserHelper();

		if ($bouser->is_logged_in() && $bouser->is_group_admin($booking['group_id']))
		{
			if ($booking['from_'] > Date('Y-m-d H:i:s') && $user_can_delete_bookings)
			{
				return self::link([
					'menuaction' => 'bookingfrontend.uibooking.cancel',
					'id' => $booking['id'],
					'resource_ids' => $booking['resource_ids'],
					'from_org' => Sanitizer::get_var('from_org', 'boolean', 'REQUEST', false)

				]);
			}
		}
		return null; // No cancel link if condition not met
	}


	/**
	 * https://github.com/iCalcreator/iCalcreator/blob/master/docs/demoUsage.md
	 */
	function ical()
	{
		$booking = $this->bo->read_single(Sanitizer::get_var('id', 'int'));
		Settings::getInstance()->update('flags', ['noheader' => true, 'nofooter' => true, 'xslt_app' => false]);

		$start = $booking['from_'];
		$end = $booking['to_'];

		$cal_name = !empty($this->serverSettings['site_title']) ? $this->serverSettings['site_title'] : $this->serverSettings['system_name'];

		$timezone = !empty($this->userSettings['preferences']['common']['timezone']) ? $this->userSettings['preferences']['common']['timezone'] : 'UTC';
		$unique_id = !empty($this->serverSettings['site_title']) ? $this->serverSettings['site_title'] : $this->serverSettings['system_name'];

		$vcalendar = Vcalendar::factory([Vcalendar::UNIQUE_ID => $unique_id,])
			// with calendaring info
			->setMethod(Vcalendar::PUBLISH)
			->setXprop(Vcalendar::X_WR_TIMEZONE, $timezone)
			->setXprop(Vcalendar::X_WR_CALNAME, $cal_name);

		// if ($cal_desc)
		// {
		// 	$ical->setXprop(Vcalendar::X_WR_CALDESC, $cal_desc);
		// }


		$xprop = $vcalendar->getXprop(Vcalendar::X_WR_TIMEZONE);
		$timezone = $xprop[1];

		$event1 = $vcalendar->newVevent()
			->setTransp(Vcalendar::OPAQUE)
			->setClass(Vcalendar::P_BLIC)
			->setSequence(1)
			// describe the event
			->setSummary('Kalenderoppføring fra Aktiv kommune')
			->setDescription(
				$booking['building_name']
			)
			->setComment($booking['season_name'])
			// place the event
			->setLocation($booking['building_name'])
			//				->setGeo('59.32206', '18.12485')
			->setDtstart(
				new DateTime(
					$start,
					new DateTimezone($timezone)
				)
			)
			->setDtend(
				new DateTime(
					$end,
					new DateTimezone($timezone)
				)

			);

		// add alarm for the event
		$alarm = $event1->newValarm()
			->setAction(Vcalendar::DISPLAY)
			// copy description from event
			->setDescription($event1->getDescription())
			// fire off the alarm before
			->setTrigger("-PT1H");

		$vcalendarString = // apply appropriate Vtimezone with Standard/DayLight components
			$vcalendar->vtimezonePopulate()
				// and create the (string) calendar
				->createCalendar();

		$filesize = filesize($vcalendarString);
		$filename = 'cal.ics';
		$browser = CreateObject('phpgwapi.browser');
		$browser->content_header($filename, 'text/calendar', $filesize);
		echo $vcalendarString;
	}

	public function show()
	{
		$config = CreateObject('phpgwapi.config', 'booking')->read();
		if ($config['user_can_delete_bookings'] != 'yes')
		{
			$user_can_delete_bookings = 0;
		} else
		{
			$user_can_delete_bookings = 1;
		}
		$booking = $this->bo->read_single(Sanitizer::get_var('id', 'int'));
		$booking['group'] = $this->group_bo->read_single($booking['group_id']);
		$resources = $this->resource_bo->so->read(array(
			'filters' => array('id' => $booking['resources']),
			'sort' => 'name'
		));
		$booking['resources'] = $resources['results'];
		$res_names = array();
		foreach ($booking['resources'] as $res)
		{
			$res_names[] = $res['name'];
		}
		$booking['resource_info'] = join(', ', $res_names);

		$interval = (new DateTime($booking['from_']))->diff(new DateTime($booking['to_']));
		$when = "";
		if ($interval->days > 0)
		{
			$when = pretty_timestamp($booking['from_']) . ' - ' . pretty_timestamp($booking['to_']);
		} else
		{
			$end = new DateTime($booking['to_']);
			$when = pretty_timestamp($booking['from_']) . ' - ' . $end->format('H:i');
		}
		$booking['when'] = $when;

		$number_of_participants = createObject('booking.boparticipant')->get_number_of_participants('booking', $booking['id']);

		$booking['number_of_participants'] = $number_of_participants;

		$external_site_address = !empty($config['external_site_address']) ? $config['external_site_address'] : $this->serverSettings['webserver_url'];

		$participant_registration_link = $external_site_address
			. "/bookingfrontend/?menuaction=bookingfrontend.uiparticipant.add"
			. "&reservation_type=booking"
			. "&reservation_id={$booking['id']}";

		$booking['participant_registration_link'] = $participant_registration_link;

		$resource_participant_limit_gross = $this->resource_bo->so->get_participant_limit($booking['resources'], true);

		if (!empty($resource_participant_limit_gross['results'][0]['quantity']))
		{
			$resource_participant_limit = $resource_participant_limit_gross['results'][0]['quantity'];
		}

		if (!$booking['participant_limit'])
		{
			$booking['participant_limit'] = $resource_participant_limit ? $resource_participant_limit : (int)$config['participant_limit'];
		}

		$booking['participant_limit'] = $booking['participant_limit'] ? $booking['participant_limit'] : (int)$config['participant_limit'];

		$booking['participanttext'] = !empty($config['participanttext']) ? $config['participanttext'] : '';

		phpgw::import_class('phpgwapi.phpqrcode');
		$code_text = $participant_registration_link;
		$filename = $this->serverSettings['temp_dir'] . '/' . md5($code_text) . '.png';
		QRcode::png($code_text, $filename);
		$booking['encoded_qr'] = 'data:image/png;base64,' . base64_encode(file_get_contents($filename));

		$get_participants_link = phpgw::link('/index.php', array(
			'menuaction' => 'booking.uiparticipant.index',
			'filter_reservation_id' => $booking['id'],
			'filter_reservation_type' => 'booking',
		));

		$booking['get_participants_link'] = $get_participants_link;

		$datatable_def = array();
		if ((new UserHelper())->is_logged_in())
		{
			$datatable_def[] = array(
				'container' => 'datatable-container_0',
				'requestUrl' => json_encode(self::link(array(
					'menuaction' => 'bookingfrontend.uiparticipant.index',
					'filter_reservation_id' => $booking['id'],
					'filter_reservation_type' => 'booking',
					'phpgw_return_as' => 'json'
				))),
				'ColumnDefs' => array(
					array(
						'key' => 'phone',
						'label' => lang('participants'),
						'sortable' => true,
					),
					array(
						'key' => 'quantity',
						'label' => lang('quantity'),
						'sortable' => true,
					)
				),
				'data' => json_encode(array()),
				'config' => array(
					array('disableFilter' => true),
					array('disablePagination' => true)
				)
			);
		}


		self::render_template_xsl(array(
			'booking_show',
			'datatable_inline'
		), array(
			'booking' => $booking,
			'user_can_delete_bookings' => $user_can_delete_bookings,
			'datatable_def' => $datatable_def
		));
	}
}
