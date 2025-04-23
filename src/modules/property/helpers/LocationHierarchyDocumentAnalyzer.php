<?php

namespace App\Modules\Property\Helpers;

use App\Database\Db;

class LocationHierarchyDocumentAnalyzer
{
	private $db;
	private $locationId;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	public function analyze()
	{
		$mapping = $this->get_mapping();


		//	_debug_array($mapping);

		$matches = $this->find_matching_directories($mapping);
		_debug_array($matches);
	}

	function get_mapping()
	{
		//get the old and new location_code from location_mapping
		$sql = "SELECT old_location_code, new_location_code FROM location_mapping";

		$this->db->query($sql);
		$mapping = [];
		while ($this->db->next_record())
		{
			$old_location_code = $this->db->f('old_location_code');
			$new_location_code = $this->db->f('new_location_code');
			$mapping[$old_location_code] = $new_location_code;
		}

		return $mapping;
	}

	/**
	 * Find if any original location codes are present as directories in phpgw_vfs
	 * Returns an array of found directories keyed by location code
	 */
	function find_matching_directories($mapping)
	{
		$found = [];
		foreach (array_keys($mapping) as $old_location_code)
		{
			$parts = explode('-', $old_location_code);
			if (count($parts) < 3)
			{
				continue; // skip invalid codes
			}
			$dir_path = '/property/document/' . $parts[0] . '-' . $parts[1] . '-' . $parts[2];

			$sql = "SELECT file_id FROM phpgw_vfs WHERE directory = " . $this->db->quote($dir_path);
			$this->db->query($sql);
			if ($this->db->next_record())
			{
				$found[$old_location_code] = $dir_path;
			}
		}
		return $found;
	}
}
