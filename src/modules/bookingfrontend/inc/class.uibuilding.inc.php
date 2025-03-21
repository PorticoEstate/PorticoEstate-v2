<?php

use App\modules\phpgwapi\services\Translation;

phpgw::import_class('booking.uibuilding');

class bookingfrontend_uibuilding extends booking_uibuilding
{

	public $public_functions = array(
		'index' => true,
		'schedule' => true,
		'information_screen' => true,
		'extraschedule' => true,
		'show' => true,
		'toggle_show_inactive' => true,
		'find_buildings_used_by' => true,
	);
	protected $module;

	var $booking_bo, $resource_bo;

	public function __construct()
	{
		parent::__construct();
		$this->booking_bo = CreateObject('booking.bobooking');
		$this->resource_bo = CreateObject('booking.boresource');
		$this->module = "bookingfrontend";
	}

	public function information_screen()
	{
		$today = new DateTime(Sanitizer::get_var('date', 'string', 'GET'), new DateTimeZone('Europe/Oslo'));
		$date = $today;
		$currentday = $today; //Sigurd: Needed?

		$building_id = Sanitizer::get_var('id', 'int', 'GET');
		$building = $this->bo->read_single($building_id);
		$start = Sanitizer::get_var('start', 'int', 'GET');
		$end = Sanitizer::get_var('end', 'int', 'GET');
		$res = Sanitizer::get_var('res', 'int', 'GET');
		$resource_id = Sanitizer::get_var('resource_id', 'int', 'GET');
		$color = Sanitizer::get_var('color', 'string', 'GET');
		$color_back	 = Sanitizer::get_var('color_back', 'string', 'GET');
		$fontsize = Sanitizer::get_var('fontsize', 'int', 'GET');
		$weekend = Sanitizer::get_var('weekend', 'int', 'GET');


		if ($start)
		{
			$timestart = $start;
		}
		else
		{
			$timestart = 8;
		}

		if ($end)
		{
			$timeend = $end;
		}
		else
		{
			$timeend = 22;
		}

		$days = array(
			"Mon" => "Mandag",
			"Tue" => "Tirsdag",
			"Wed" => "Onsdag",
			"Thu" => "Torsdag",
			"Fri" => "Fredag",
			"Sat" => "Lørdag",
			"Sun" => "Søndag"
		);

		$bookings = $this->booking_bo->building_infoscreen_schedule($building_id, $date, $res, $resource_id);

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $bookings;
		}
		$from = clone $date;
		$from->setTime(0, 0, 0);
		// Make sure $from is a monday
		if ($from->format('w') != 1)
		{
			$from->modify('last monday');
			if ($weekend == 1)
			{
				$from->modify('next Saturday');
			}
			if ($weekend == 3)
			{
				$currentday = clone $date;
				$currentday->setTime(0, 0, 0);
				$from = $currentday;
			}
		}


		$from = $from->format('d.m.Y');


		$list1 = array(
			'Mon' => array(),
			'Tue' => array(),
			'Wed' => array(),
			'Thu' => array(),
			'Fri' => array(),
		);
		$list2 = array(
			'Sat' => array(),
			'Sun' => array()
		);
		$list3 = array(
			'Mon' => array(),
			'Tue' => array(),
			'Wed' => array(),
			'Thu' => array(),
			'Fri' => array(),
			'Sat' => array(),
			'Sun' => array()
		);
		if ($weekend == 1)
		{
			$list = $list2;
		}
		elseif ($weekend == 2)
		{
			$list = $list3;
		}
		elseif ($weekend == 3)
		{
			$day = $currentday->format('D');
			$list = array($day => array());
		}
		else
		{
			$list = $list1;
		}

		foreach ($list as $key => &$item)
		{
			$item = $bookings['results'][$key];
		}

		foreach ($list as $_day => $_resources)
		{
			foreach ($_resources as $_resource => $_resource_data)
			{
				foreach ($_resource_data as $_start_time => $_info)
				{
					$timestart = min($timestart, explode(':', $_start_time)[0]);
					$timeend = max($timeend, explode(':', $_info['to_'])[0]);
				}
			}
		}

		//		_debug_array($bookings);

		$timediff = $timeend - $timestart;
		$cellwidth = 88 / ($timediff * 2);

		$time = $timestart;
		$html = '<html><head><title>Kalender for ' . $building['name'] . '</title>';
		$html .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
		$html .= '<meta name="author" content="Aktiv Kommune">';
		$html .= '<style>';

		if ($color_back)
		{
			$html .= 'body { font-size: 12px; padding: 0px; border-spacing: 0px; background-color: #' . $color_back . ';} ';
		}
		else
		{
			$html .= 'body { font-size: 12px; padding: 0px; border-spacing: 0px;} ';
		}

		if ($fontsize != '')
		{
			$html .= 'table { font-family: Tahoma, Verdana, Helvetica; width: 100%; height: 100%; margin: 0px; font-size: ' . $fontsize . 'px; border-collapse: collapse;} ';
		}
		else
		{
			$html .= 'table { font-family: Tahoma, Verdana,Helvetica; width: 100%; height: 100%; margin: 0px; font-size: 12px; border-collapse: collapse;} ';
		}
		$html .= 'th { text-align: left; padding: 2px 8px; border: 1px solid black;} ';
		$html .= 'td { font-weight: bold; text-align: left; padding: 4px 8px; border: 1px solid black;} ';
		$html .= 'tr.header { background-color: #333; color: white; } ';
		if ($color != '')
		{
			$html .= 'td.data { background-color: #' . $color . '; } ';
		}
		else
		{
			$html .= 'td.data { background-color: #ccffff; } ';
		}
		$html .= '</style>';
		$html .= '</head><body style="color: black; margin: 8px; font-weight: bold;">';
		$html .= '<table class="calender">';
		$html .= '<thead>';
		$html .= '<tr>';
		$html .= '<th colspan="2" style="text-align: left; width: 12%;"></th>'; //Bane
		while ($time < $timeend)
		{
			$html .= '<th colspan="1" style="width: ' . $cellwidth . '%; text-align: left;">' . str_pad($time, 2, '0', STR_PAD_LEFT) . ':00</th>';
			$html .= '<th colspan="1" style="width: ' . $cellwidth . '%; text-align: left;">' . str_pad($time, 2, '0', STR_PAD_LEFT) . ':30</th>';
			$time += 1;
		}
		$html .= '</tr>';
		$html .= '</thead>';
		$html .= '<tbody>';
		$first = '';
		$len = (($timeend - $timestart) * 2) + 2;

		foreach ($list as $day => $resources)
		{
			if ($first != $day)
			{
				$first = $day;
				$html .= '<tr class="header">';
				$html .= '<td colspan="' . $len . '" width="12%">';
				$html .= $days[$day];
				$html .= " ";
				$html .= $from;
				$html .= '</td>';
				$html .= '</tr>';

				$from = date('d.m.Y', strtotime($from . ' 00:00:01 +1 day'));
			}

			foreach ($resources as $res => $booking)
			{
				$html .= '<tr>';
				$html .= '<td colspan="2">';
				$html .= $res;
				$html .= '</td>';

				// Initialize the current time position
				$currentPosition = $timestart;

				// Sort bookings by start time
				ksort($booking);

				foreach ($booking as $date => $value)
				{
					// Get exact time string without any alterations
					$fromTime = substr($value['from_'], -8);
					$toTime = substr($value['to_'], -8);

					// Extract hours and minutes directly
					list($fromHour, $fromMinute) = explode(':', $fromTime);
					list($toHour, $toMinute) = explode(':', $toTime);

					// Convert to decimal hours for calculations
					$startPosition = (int)$fromHour + ($fromMinute === '30' ? 0.5 : 0);
					$endPosition = (int)$toHour + ($toMinute === '30' ? 0.5 : 0);

					// Skip bookings entirely before our display window
					if ($endPosition <= $timestart)
					{
						continue;
					}

					// Handle bookings that start before our display window
					if ($startPosition < $timestart)
					{
						$startPosition = $timestart;
					}

					// Fill gap before this booking if needed
					if ($startPosition > $currentPosition)
					{
						$gapColspan = round(($startPosition - $currentPosition) * 2);
						if ($gapColspan > 0)
						{
							$html .= '<td colspan="' . $gapColspan . '">&nbsp;</td>';
						}
					}

					// Cap the end position to our display window
					if ($endPosition > $timeend)
					{
						$endPosition = $timeend;
					}

					// Calculate colspan for the booking
					$colspan = round(($endPosition - $startPosition) * 2);

					// Create the booking cell
					$html .= '<td colspan="' . $colspan . '" class="data">';
					$testlen = 12 * $colspan;
					if (strlen($value['name']) > $testlen)
					{
						$html .= $value['shortname'] . " ";
					}
					else
					{
						$html .= $value['name'] . " ";
					}
					$html .= '</td>';

					// Update the current position to the end of this booking
					$currentPosition = $endPosition;
				}

				// Fill any remaining space to the end of the display window
				if ($currentPosition < $timeend)
				{
					$finalColspan = round(($timeend - $currentPosition) * 2);
					if ($finalColspan > 0)
					{
						$html .= '<td colspan="' . $finalColspan . '">&nbsp;</td>';
					}
				}

				$html .= '</tr>';
			}
		}


		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</body></html>';

		header('Content-type: text/html');
		echo $html;
		$this->phpgwapi_common->phpgw_exit();
	}

	public function schedule()
	{
		$backend = Sanitizer::get_var('backend', 'bool', 'GET');
		$building = $this->bo->get_schedule(Sanitizer::get_var('id', 'int', 'GET'), 'bookingfrontend.uibuilding');
		if ($building['deactivate_application'] == 0)
		{
			$building['application_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uiapplication.add',
				'building_id' => $building['id'],
				'building_name' => $building['name'],
			));
		}
		else
		{
			$building['application_link'] = self::link(array(
				'menuaction' => 'bookingfrontend.uibuilding.schedule',
				'id' => $building['id']
			));
		}

		$building['endOfSeason'] = $this->bo->so->get_endOfSeason($building['id']) . " 23:59:59";
		if (strlen($building['endOfSeason']) < 18)
		{
			$building['endOfSeason'] = false;
		}
		$building['datasource_url'] = self::link(array(
			'menuaction' => 'bookingfrontend.uibooking.building_schedule',
			'building_id' => $building['id'],
			'phpgw_return_as' => 'json',
		));

		// the schedule can also be used from backend
		// if so we want to change default date shown in the calendar
		if ($backend)
		{
			$building['date'] = Sanitizer::get_var('date', 'string', 'GET');
		}

		self::add_javascript('bookingfrontend', 'base', 'schedule.js');
		phpgwapi_jquery::load_widget("datepicker");

		$building['picker_img'] = $this->phpgwapi_common->image('phpgwapi', 'cal');

		self::render_template_xsl('building_schedule', array(
			'building' => $building,
			'backend' => $backend
		));
	}

	public function extraschedule()
	{
		$backend = Sanitizer::get_var('backend', 'bool', 'GET');
		$building = $this->bo->get_schedule(Sanitizer::get_var('id', 'int', 'GET'), 'bookingfrontend.uibuilding');
		$building['application_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uibuilding.extraschedule',
			'id' => $building['id']
		));
		$building['datasource_url'] = self::link(array(
			'menuaction' => 'bookingfrontend.uibooking.building_extraschedule',
			'building_id' => $building['id'],
			'phpgw_return_as' => 'json',
		));

		// the schedule can also be used from backend
		// if so we want to change default date shown in the calendar
		if ($backend)
		{
			$building['date'] = Sanitizer::get_var('date', 'string', 'GET');
		}
		$building['deactivate_application'] = 1;
		self::add_javascript('bookingfrontend', 'base', 'schedule.js');
		phpgwapi_jquery::load_widget("datepicker");
		$building['picker_img'] = $this->phpgwapi_common->image('phpgwapi', 'cal');
		self::render_template_xsl('building_schedule', array(
			'building' => $building,
			'backend' => $backend
		));
	}

	public function show()
	{

		$config = CreateObject('phpgwapi.config', 'booking');
		$config->read();
		$this->check_active('booking.uibuilding.show');
		$building = $this->bo->read_single(Sanitizer::get_var('id', 'int', 'GET'));

		$building['contact_info'] = "";
		$contactdata = array();
		foreach (array('homepage', 'email', 'phone') as $field)
		{
			if (!empty(trim($building[$field])))
			{
				$value = trim($building[$field]);
				if ($field == 'homepage')
				{
					if (!preg_match("/^(http|https):\/\//", $value))
					{
						$value = 'http://' . $value;
					}
					$value = sprintf('<a href="%s" target="_blank">%s</a>', $value, lang('Link to website'));
				}
				if ($field == 'email')
				{
					$value = "<a href=\"mailto:{$value}\">{$value}</a>";
				}
				$contactdata[] = sprintf('%s: %s', lang($field), $value);
			}
		}
		if (!empty($contactdata))
		{
			$building['contact_info'] = sprintf('<p>%s</p>', join('<br/>', $contactdata));
		}
		//        _debug_array(json.encode());

		$translation = Translation::getInstance();
		$userlang = $translation->get_userlang();
		$building['description'] = !empty($building['description_json'][$userlang]) ? $building['description_json'][$userlang] : $building['description_json']['no'];

		$building['schedule_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uibuilding.schedule',
			'id' => $building['id']
		));
		$building['extra_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uibuilding.extraschedule',
			'id' => $building['id']
		));
		$building['message_link'] = self::link(array(
			'menuaction' => 'bookingfrontend.uisystem_message.edit',
			'building_id' => $building['id'],
			'building_name' => $building['name']
		));
		$building['start'] = self::link(array(
			'menuaction' => 'bookingfrontend.uisearch.index',
			'type' => "building"
		));
		$building['part_of_town'] = execMethod('property.solocation.get_part_of_town', $building['location_code'])['part_of_town'];

		if (trim($building['homepage']) != '' && !preg_match("/^http|https:\/\//", trim($building['homepage'])))
		{
			$building['homepage'] = 'http://' . $building['homepage'];
		}

		if ($this->userSettings['preferences']['common']['template_set'] == 'bookingfrontend_2')
		{
			phpgwapi_jquery::load_widget("datetimepicker");

			self::add_javascript('bookingfrontend', 'bookingfrontend_2', 'components/light-box.js', true);
			phpgwapi_css::getInstance()->add_external_file("bookingfrontend/js/bookingfrontend_2/components/light-box.css");
		}
		else
		{
			phpgwapi_js::getInstance()->add_external_file("phpgwapi/templates/bookingfrontend/js/build/aui/aui-min.js");
		}



		self::add_javascript('bookingfrontend', 'base', 'building.js', true);

		$template = 'building';
		self::add_external_css_with_search($template . '.css', false);

		self::render_template_xsl($template, array('building' => $building, 'config_data' => $config->config_data));
	}
}
