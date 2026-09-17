<?php

/**************************************************************************\
 * phpGroupWare - Holiday                                                   *
 * http://www.phpgroupware.org                                              *
 * Written by Mark Peters <skeeter@phpgroupware.org>                        *
 * --------------------------------------------                             *
 *  This program is free software; you can redistribute it and/or modify it *
 *  under the terms of the GNU General Public License as published by the   *
 *  Free Software Foundation; either version 2 of the License, or (at your  *
 *  option) any later version.                                              *
  \**************************************************************************/

/* $Id$ */

use App\Database\Db;
use App\traits\DbRowTrait;


class calendar_soholiday
{
	use DbRowTrait;
	var $debug = False;
	var $db;

	function __construct()
	{
		$this->db = Db::getInstance();
	}

	/* Begin Holiday functions */
	function save_holiday($holiday)
	{
		if (!empty($holiday['hol_id']))
		{
			if ($this->debug)
			{
				echo "Updating LOCALE='" . $holiday['locale'] . "' NAME='" . $holiday['name'] . "' extra=(" . $holiday['mday'] . '/' . $holiday['month_num'] . '/' . $holiday['occurence'] . '/' . $holiday['dow'] . '/' . $holiday['observance_rule'] . ")<br />\n";
			}
		}
		else
		{
			if ($this->debug)
			{
				echo "Inserting LOCALE='" . $holiday['locale'] . "' NAME='" . $holiday['name'] . "' extra=(" . $holiday['mday'] . '/' . $holiday['month_num'] . '/' . $holiday['occurence'] . '/' . $holiday['dow'] . '/' . $holiday['observance_rule'] . ")<br />\n";
			}
		}
		if (!empty($holiday['hol_id']))
		{
			$stmt = $this->db->prepare('UPDATE phpgw_cal_holidays SET name=:name, mday=:mday, month_num=:month_num, occurence=:occurence, dow=:dow, observance_rule=:observance_rule WHERE hol_id=:hol_id');
			$stmt->execute([':name' => $holiday['name'], ':mday' => (int)$holiday['mday'], ':month_num' => (int)$holiday['month_num'], ':occurence' => (int)$holiday['occurence'], ':dow' => (int)$holiday['dow'], ':observance_rule' => !empty($holiday['observance_rule']) ? 1 : 0, ':hol_id' => (int)$holiday['hol_id']]);
		}
		else
		{
			$stmt = $this->db->prepare('INSERT INTO phpgw_cal_holidays(locale,name,mday,month_num,occurence,dow,observance_rule) VALUES(:locale,:name,:mday,:month_num,:occurence,:dow,:observance_rule)');
			$stmt->execute([':locale' => strtoupper((string)$holiday['locale']), ':name' => $holiday['name'], ':mday' => (int)$holiday['mday'], ':month_num' => (int)$holiday['month_num'], ':occurence' => (int)$holiday['occurence'], ':dow' => (int)$holiday['dow'], ':observance_rule' => !empty($holiday['observance_rule']) ? 1 : 0]);
		}
	}

	function store_to_array(&$holidays)
	{
		while ($this->db->next_record())
		{
			$holidays[] = array(
				'index'			=> $this->db->f('hol_id'),
				'locale'		=> $this->db->f('locale'),
				'name'			=> phpgw::strip_html($this->dbStrip($this->db->f('name'))),
				'day'			=> intval($this->db->f('mday')),
				'month'			=> intval($this->db->f('month_num')),
				'occurence'		=> intval($this->db->f('occurence')),
				'dow'			=> intval($this->db->f('dow')),
				'observance_rule'	=> $this->db->f('observance_rule')
			);
			if ($this->debug)
			{
				echo 'Holiday ID: ' . $this->db->f('hol_id') . '<br />' . "\n";
			}
		}
	}

	function read_holidays($locales = '', $query = '', $order = '', $year = 0)
	{
		$holidays = array();

		if ($locales == '')
		{
			return $holidays;
		}

		$locales = is_array($locales) ? $locales : array($locales);
		$placeholders = array_map(static function ($index) { return ':locale' . $index; }, array_keys($locales));
		$params = array_combine($placeholders, array_values($locales));
		$sql = 'SELECT * FROM phpgw_cal_holidays WHERE locale IN (' . implode(',', $placeholders) . ')';
		if ($query !== '')
		{
			$sql .= ' AND name LIKE :query';
			$params[':query'] = '%' . $query . '%';
		}
		if ((int)$year > 1900)
		{
			$sql .= ' AND (occurence < 1900 OR occurence = :year)';
			$params[':year'] = (int)$year;
		}
		$allowedOrder = ['month_num', 'mday', 'name', 'occurence', 'dow'];
		$orderParts = array_intersect(array_map('trim', explode(',', (string)$order)), $allowedOrder);
		$sql .= ' ORDER BY ' . ($orderParts ? implode(',', $orderParts) : 'month_num,mday');

		if ($this->debug)
		{
			echo 'Read Holidays : ' . $sql . '<br />' . "\n";
		}

		$this->db->limit_query_with_params($sql, $params, 0, __LINE__, __FILE__, null);
		$this->store_to_array($holidays);
		return $holidays;
	}

	function read_holiday($id)
	{
		$holidays = array();
		if ($this->debug)
		{
			echo 'Reading Holiday ID : ' . $id . '<br />' . "\n";
		}
		$this->db->limit_query_with_params('SELECT * FROM phpgw_cal_holidays WHERE hol_id=:hol_id', [':hol_id' => (int)$id], 0, __LINE__, __FILE__, null);
		$this->store_to_array($holidays);
		@reset($holidays);
		return $holidays[0];
	}

	function delete_holiday($id)
	{
		$stmt = $this->db->prepare('DELETE FROM phpgw_cal_holidays WHERE hol_id=:hol_id');
		$stmt->execute([':hol_id' => (int)$id]);
	}

	function delete_locale($locale)
	{
		$stmt = $this->db->prepare('DELETE FROM phpgw_cal_holidays WHERE locale=:locale');
		$stmt->execute([':locale' => strtoupper((string)$locale)]);
	}

	/* Private functions */
	function get_locale_list($sort = '', $order = '', $query = '')
	{
		$querymethod = '';
		$params = [];
		if ($query)
		{
			$querymethod .= ' WHERE locale LIKE :query';
			$params[':query'] = '%' . $query . '%';
		}

		if ($order)
		{
			$allowedOrder = ['locale'];
			$querymethod .= ' ORDER BY ' . (in_array($order, $allowedOrder, true) ? $order : 'locale');
		}
		$this->db->limit_query_with_params('SELECT DISTINCT locale FROM phpgw_cal_holidays' . $querymethod, $params, 0, __LINE__, __FILE__, null);
		$locale = false;
		while ($this->db->next_record())
		{
			$locale[] = $this->db->f('locale');
		}
		return $locale;
	}

	function holiday_total($locale, $query = '', $year = 0)
	{
		$querymethod = '';
		$params = [':locale' => (string)$locale];
		if ($query)
		{
			$querymethod = ' AND name LIKE :query';
			$params[':query'] = '%' . $query . '%';
		}
		if (intval($year) >= 1900)
		{
			$querymethod .= ' AND (occurence < 1900 OR occurence = :year)';
			$params[':year'] = (int)$year;
		}
		$sql = 'SELECT count(*) as cnt FROM phpgw_cal_holidays WHERE locale=:locale' . $querymethod;
		if ($this->debug)
		{
			echo 'HOLIDAY_TOTAL : ' . $sql . '<br />' . "\n";
		}

		$this->db->limit_query_with_params($sql, $params, 0, __LINE__, __FILE__, null);
		$this->db->next_record();
		$retval = intval($this->db->f('cnt'));
		if ($this->debug)
		{
			echo 'Total Holidays for : ' . $locale . ' : ' . $retval . "<br />\n";
		}
		return $retval;
	}
}
