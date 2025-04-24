<?php

namespace App\Modules\Property\Helpers;

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;

class LocationHierarchyDocumentAnalyzer
{
	private $db;
	private $locationId;
	private $basedir;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$serverSettings  = Settings::getInstance()->get('server');
		$this->basedir = $serverSettings['files_dir'];
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
		$i = 0;

		foreach ($mapping as $old_location_code   => $new_location_code)
		{
			if ($i > 10)
			{
				break;
			}
			$parts = explode('-', $old_location_code);
			$new_parts = explode('-', $new_location_code);
			$loc2_pattern = $parts[0] . '-' . $parts[1];
			$loc3_pattern = $parts[0] . '-' . $parts[1] . '-' . $parts[2];
			$loc4_pattern = $parts[0] . '-' . $parts[1] . '-' . $parts[2] . '-' . $parts[3];

			$new_loc2_pattern = $new_parts[0] . '-' . $new_parts[1];
			$new_loc3_pattern = $new_parts[0] . '-' . $new_parts[1] . '-' . $new_parts[2];
			$new_loc4_pattern = $new_parts[0] . '-' . $new_parts[1] . '-' . $new_parts[2] . '-' . $new_parts[3];


			$sql = "SELECT directory FROM phpgw_vfs 
			WHERE (directory like '%" . $loc2_pattern . "/%' AND directory not like '%" . $new_loc2_pattern . "/%')
			OR (directory like '%" . $loc3_pattern . "/%' AND directory not like '%" . $new_loc3_pattern . "/%')
			OR (directory like '%" . $loc4_pattern . "/%' AND directory not like '%" . $new_loc4_pattern . "/%')";
			$this->db->query($sql);
			while ($this->db->next_record())
			{

				$i++;
				$directory = $this->db->f('directory');
				//replace the old location code with the new location code for all patterns
				$new_directory = str_replace([$loc2_pattern, $loc3_pattern, $loc4_pattern], [$new_loc2_pattern, $new_loc3_pattern, $new_loc4_pattern], $directory);

				$found[$directory] = [$old_location_code, $new_location_code, $new_directory];
			}
		}
		return $found;
	}

	function update_directory($old_directory, $new_directory)
	{
		$sql = "UPDATE phpgw_vfs SET directory = '" . $new_directory . "' WHERE directory = '" . $old_directory . "'";
		$this->db->query($sql);
		if ($this->db->affected_rows() > 0)
		{
			return true;
		}
		return false;
	}
	/**
	 * Move all contents from old directory to new directory.
	 * Creates new directory if it does not exist.
	 * $oldDir, $newDir has to be absolute paths, starting with $this->basedir.
	 *
	 * @param string $oldDir
	 * @param string $newDir
	 * @return bool
	 */
	function moveDirectoryContents($oldDir, $newDir)
	{
//		$basedir = $this->basedir;

		// Ensure old directory exists
		if (!is_dir($oldDir))
		{
			return false;
		}

		// Create new directory if it doesn't exist
		if (!is_dir($newDir))
		{
			if (!mkdir($newDir, 0777, true))
			{
				return false;
			}
		}

		// Move files and subdirectories
		$items = scandir($oldDir);
		foreach ($items as $item)
		{
			if ($item === '.' || $item === '..')
			{
				continue;
			}
			$oldPath = $oldDir . DIRECTORY_SEPARATOR . $item;
			$newPath = $newDir . DIRECTORY_SEPARATOR . $item;

			if (is_dir($oldPath))
			{
				// Recursively move subdirectories
				$this->moveDirectoryContents($oldPath, $newPath);
				rmdir($oldPath);
			}
			else
			{
				rename($oldPath, $newPath);
			}
		}
		return true;
	}
}
