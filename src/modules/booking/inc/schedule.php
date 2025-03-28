<?php
phpgw::import_class('booking.sobuilding');

/**
	 * Convert a week's worth of booking-like arrays into a JQuery DataTable
	 * compatible format.
	 */
	function build_schedule_table( $bookings, $resources )
	{
		$data = array();
		$t = '00:00';


		if (!function_exists('get_from'))
		{
			function get_from( $a )
			{
				return $a['from_'];
			}
		}

		if (!function_exists('get_to'))
		{
			function get_to( $a )
			{
				return $a['to_'];
			}
		}

		if (!function_exists('cmp_from'))
		{
			function cmp_from( $a, $b )
			{
				return strcmp($a['from_'], $b['from_']);
			}
		}

		if (!function_exists('cmp_to'))
		{
			function cmp_to( $a, $b )
			{
				return strcmp($a['to_'], $b['to_']);
			}
		}

		while (true)
		{
			usort($bookings, 'cmp_from');
			// No bookings left
			if (count($bookings) == 0)
			{
				if ($t != '23:59')
				{
					$data[] = array(
						'time' => $t . '-24:00',
						'_from' => $t,
						'_to' => '24:00'
					);
				}
				break;
			}
			// No bookings yet
			else if ($bookings[0]['from_'] > $t)
			{
				$next_t = $bookings[0]['from_'];
				$data[] = array(
					'time' => $t . '-' . $next_t,
					'_from' => $t,
					'_to' => $next_t
				);
				$t = $next_t;
				continue;
			}
			// Bookings found
			else
			{
				/**
				 * create_function will be deprecated from PHP 7.2
				 */
//				$next = array_filter(array_merge(array_map('get_from', $bookings), array_map('get_to', $bookings)), create_function('$a', "return \$a > '$t';"));
				$next = array();
				$next_candidates = array_merge(array_map('get_from', $bookings), array_map('get_to', $bookings));

				if($next_candidates)
				{
					foreach ($next_candidates as $next_candidate)
					{
						if($next_candidate > $t)
						{
							$next[] = $next_candidate;
						}
					}
				}

				if (!$next)
				{
					break;
				}
				$next_t = min($next);

				$first_row = true;
				foreach ($resources as $res)
				{
					$row = array('resource' => $res['name'], 'resource_id' => $res['id']);
					if ($first_row)
					{
						$tmp_t = $next_t == '23:59' ? '24:00' : $next_t;
						$row['time'] = $t . '-' . $tmp_t;
					}

					$row['_from'] = $t;
					$row['_to'] = $tmp_t;
					$empty = true;
					$tempbooking = array();
					foreach ($bookings as $booking)
					{
						if ($booking['from_'] > $t)
						{
							break;
						}
						if (in_array($res['id'], $booking['resources']))
						{
							if (!(($tempbooking[$booking['wday']]['from_'] <= $booking['from_']) 
								and ( $tempbooking[$booking['wday']]['to_'] == $booking['to_'])
								and ( $tempbooking[$booking['wday']]['allocation_id'] == $booking['id'])
								and ( $booking['type'] == 'allocation'))
								)
							{
								$empty = false;

								if(empty($row[$booking['wday']]['type']) || $row[$booking['wday']]['type'] != 'booking')
								{
									$row[$booking['wday']] = $booking;
								}
							}
							if ($booking['type'] == 'booking')
							{
								$tempbooking[$booking['wday']] = $booking;
							}
						}
					}
					if (!$empty)
					{
						$data[] = $row;
						$first_row = false;
					}
				}
				$t = $next_t;
				usort($bookings, 'cmp_to');
				while (count($bookings) > 0 && $bookings[0]['to_'] == $t)
				{
					array_shift($bookings);
				}
			}
		}
		return $data;
	}

function build_organization_schedule_table( $bookings, $resources )
{
	$data = array();
	$t = '00:00';


	if (!function_exists('get_from'))
	{
		function get_from( $a )
		{
			return $a['from_'];
		}
	}

	if (!function_exists('get_to'))
	{
		function get_to( $a )
		{
			return $a['to_'];
		}
	}

	if (!function_exists('cmp_from'))
	{
		function cmp_from( $a, $b )
		{
			return strcmp($a['from_'], $b['from_']);
		}
	}

	if (!function_exists('cmp_to'))
	{
		function cmp_to( $a, $b )
		{
			return strcmp($a['to_'], $b['to_']);
		}
	}

	while (true)
	{
		usort($bookings, 'cmp_from');
		// No bookings left
		if (count($bookings) == 0)
		{
			if ($t != '23:59')
			{
				$data[] = array(
					'time' => $t . '-24:00',
					'_from' => $t,
					'_to' => '24:00'
				);
			}
			break;
		}
		// No bookings yet
		else if ($bookings[0]['from_'] > $t)
		{
			$next_t = $bookings[0]['from_'];
			$data[] = array(
				'time' => $t . '-' . $next_t,
				'_from' => $t,
				'_to' => $next_t
			);
			$t = $next_t;
			continue;
		}
		// Bookings found
		else
		{
			/**
			 * create_function will be deprecated from PHP 7.2
			 */
//				$next = array_filter(array_merge(array_map('get_from', $bookings), array_map('get_to', $bookings)), create_function('$a', "return \$a > '$t';"));
			$next = array();
			$next_candidates = array_merge(array_map('get_from', $bookings), array_map('get_to', $bookings));

			if($next_candidates)
			{
				foreach ($next_candidates as $next_candidate)
				{
					if($next_candidate > $t)
					{
						$next[] = $next_candidate;
					}
				}
			}

			if (!$next)
			{
				break;
			}
			$next_t = min($next);

			$first_row = true;
			foreach ($resources as $res)
			{
				$row = array('resource' => $res['name'], 'resource_id' => $res['id']);
				if ($first_row)
				{
					$tmp_t = $next_t == '23:59' ? '24:00' : $next_t;
					$row['time'] = $t . '-' . $tmp_t;
				}

				$row['_from'] = $t;
				$row['_to'] = $tmp_t;
				$empty = true;
				$tempbooking = array();
				foreach ($bookings as $booking)
				{
					if ($booking['from_'] > $t)
					{
						break;
					}
					if (in_array($res['id'], $booking['resources']))
					{
						if (!(($tempbooking[$booking['wday']]['from_'] <= $booking['from_'])
							and ( $tempbooking[$booking['wday']]['to_'] == $booking['to_'])
							and ( $tempbooking[$booking['wday']]['allocation_id'] == $booking['id'])
							and ( $booking['type'] == 'allocation'))
						)
						{
							$empty = false;

							if(empty($row[$booking['wday']]['type']) || $row[$booking['wday']]['type'] != 'booking')
							{
								$row[$booking['wday']] = $booking;
							}
						}
						if ($booking['type'] == 'booking')
						{
							$tempbooking[$booking['wday']] = $booking;
						}
					}
				}
				if (!$empty)
				{
					$building_name = (new booking_sobuilding)->get_building_names(array($res['building_id']));
					$row['building_name'] = $building_name[$res['building_id']]['name'];
					$row['building_id'] = $res['building_id'];
					$data[] = $row;
					$first_row = false;
				}
			}
			$t = $next_t;
			usort($bookings, 'cmp_to');
			while (count($bookings) > 0 && $bookings[0]['to_'] == $t)
			{
				array_shift($bookings);
			}
		}
	}
	return $data;
}
