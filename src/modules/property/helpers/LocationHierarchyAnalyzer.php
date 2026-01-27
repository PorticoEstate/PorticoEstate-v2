<?php

namespace App\modules\property\helpers;

use App\Database\Db;

/**
 * Location Hierarchy Analyzer - rewritten to strictly follow the rules.
 */
class LocationHierarchyAnalyzer
{
	private $db;
	private $locationData = [];
	private $loc2Refs = [];
	private $loc3Refs = [];
	private $issues = [];
	private $suggestions = [];
	private $sqlStatements = [];
	private $entryToBygningsnrMap = [];
	private $processedLocationCodes = [];
	private $currentFilterLoc1 = null;
	private $bygningsnrToLoc2Map = []; // loc1 => bygningsnr => loc2
	private $streetToLoc3Map = []; // loc1 => loc2 => streetkey => loc3
	private $debugMode = true;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	private function debug($msg)
	{
		if ($this->debugMode)
		{
			error_log("[LocationHierarchyAnalyzer] " . $msg);
		}
	}

	/**
	 * Main entry: analyze a single loc1 or all loc1s.
	 */
	public function analyze($filterLoc1 = null)
	{
		$this->resetState();
		$this->currentFilterLoc1 = $filterLoc1;
		$this->loadData($filterLoc1);

		// 1. Assign synthetic bygningsnr where missing
		$this->assignSyntheticBygningsnr();

		// 2. Build reverse maps from actual database data (more reliable than parsing names)
		// Build street-to-loc3 first so bygningsnr mapping can leverage it as a fallback
		$this->buildStreetToLoc3MapFromLocation4();
		$this->buildBygningsnrToLoc2MapFromDatabase($filterLoc1);

		// DEBUG: Log what we loaded
		$this->debug("streetToLoc3Map: " . json_encode($this->streetToLoc3Map));
		$this->debug("loc3Refs keys: " . json_encode(array_keys($this->loc3Refs)));
		foreach ($this->loc3Refs as $loc1 => $loc2s)
		{
			foreach ($loc2s as $loc2 => $loc3s)
			{
				$this->debug("  loc3Refs[$loc1][$loc2]: " . implode(", ", array_keys($loc3s)));
			}
		}

		// 3. Build required loc2/loc3 sets
		$requiredLoc2 = []; // loc1 => bygningsnr => loc2
		$requiredLoc3 = []; // loc1 => loc2 => streetkey => loc3

		// Build maps for counting
		$bygningsnrIndex = []; // loc1 => [bygningsnr values]
		$bygningsnrCount = []; // loc1 => bygningsnr => count
		foreach ($this->locationData as $row)
		{
			$loc1 = $row['loc1'];
			$bygningsnr = $row['bygningsnr'];
			if (!isset($bygningsnrIndex[$loc1]))
			{
				$bygningsnrIndex[$loc1] = [];
			}
			if (!in_array($bygningsnr, $bygningsnrIndex[$loc1]))
			{
				$bygningsnrIndex[$loc1][] = $bygningsnr;
			}
			if (!isset($bygningsnrCount[$loc1][$bygningsnr]))
			{
				$bygningsnrCount[$loc1][$bygningsnr] = 0;
			}
			if (!empty($bygningsnr))
			{
				$bygningsnrCount[$loc1][$bygningsnr]++;
			}
		}

		// Identify dominant bygningsnr in mixed loc2 values
		$dominantBygningsnrInLoc2 = []; // loc1 => loc2 => bygningsnr (the dominant one in mixed loc2)
		foreach ($this->loc2Refs as $loc1 => $loc2s)
		{
			foreach ($loc2s as $loc2 => $exists)
			{
				if (!$exists)
				{
					continue;
				}

				$maxCount = 0;
				$dominantBygningsnr = null;
				foreach ($this->locationData as $row)
				{
					if ($row['loc1'] === $loc1 && $row['loc2'] === $loc2 && !empty($row['bygningsnr']))
					{
						if (isset($bygningsnrCount[$loc1][$row['bygningsnr']]) && $bygningsnrCount[$loc1][$row['bygningsnr']] > $maxCount)
						{
							$maxCount = $bygningsnrCount[$loc1][$row['bygningsnr']];
							$dominantBygningsnr = $row['bygningsnr'];
						}
					}
				}

				if ($dominantBygningsnr)
				{
					$dominantBygningsnrInLoc2[$loc1][$loc2] = $dominantBygningsnr;
				}
			}
		}

		// Assign loc2 for each bygningsnr (one bygningsnr per loc2)
		$assignedLoc2PerLoc1 = []; // Track which loc2 are already assigned to avoid duplicates
		foreach ($bygningsnrIndex as $loc1 => $bygningsnrs)
		{
			if (!isset($assignedLoc2PerLoc1[$loc1]))
			{
				$assignedLoc2PerLoc1[$loc1] = [];
			}

			foreach ($bygningsnrs as $bygningsnr)
			{
				$loc2 = null;

				// 1) If database maps this bygningsnr to one or more loc2 values, try to reuse one (if not already claimed)
				if (isset($this->bygningsnrToLoc2Map[$loc1][$bygningsnr]))
				{
					$candidate_loc2s = $this->bygningsnrToLoc2Map[$loc1][$bygningsnr];
					// bygningsnrToLoc2Map now stores an array of loc2 values
					if (is_array($candidate_loc2s))
					{
						// Try each loc2 in order until we find one that's not claimed
						foreach ($candidate_loc2s as $candidate_loc2)
						{
							if (!in_array($candidate_loc2, $assignedLoc2PerLoc1[$loc1]))
							{
								$loc2 = $candidate_loc2;
								break;
							}
						}
					}
				}

				// 2) If this bygningsnr is dominant for an existing loc2, try to reuse that loc2 (if not already claimed)
				if (!$loc2 && isset($dominantBygningsnrInLoc2[$loc1]))
				{
					foreach ($dominantBygningsnrInLoc2[$loc1] as $loc2Candidate => $dominantBygningsnr)
					{
						if ($dominantBygningsnr === $bygningsnr && !in_array($loc2Candidate, $assignedLoc2PerLoc1[$loc1]))
						{
							$loc2 = $loc2Candidate;
							break;
						}
					}
				}

				// 3) Otherwise, allocate the first available loc2 number (each bygningsnr gets its own loc2)
				if (!$loc2)
				{
					for ($newLoc2 = 1; $newLoc2 <= 99; $newLoc2++)
					{
						$new_loc2_str = str_pad($newLoc2, 2, '0', STR_PAD_LEFT);
						// Use first loc2 that doesn't exist in database AND hasn't been assigned in this run
						if (!isset($this->loc2Refs[$loc1][$new_loc2_str]) && !in_array($new_loc2_str, $assignedLoc2PerLoc1[$loc1]))
						{
							$loc2 = $new_loc2_str;
							break;
						}
					}
				}

				$requiredLoc2[$loc1][$bygningsnr] = $loc2;
				if (!in_array($loc2, $assignedLoc2PerLoc1[$loc1]))
				{
					$assignedLoc2PerLoc1[$loc1][] = $loc2;
				}
			}
		}

		// For each loc2, assign loc3 for each unique (street_id, street_number)
		$loc2StreetCombos = []; // loc1 => loc2 => [streetkey]
		foreach ($this->locationData as $row)
		{
			$loc1 = $row['loc1'];
			$bygningsnr = $row['bygningsnr'];
			$loc2 = $requiredLoc2[$loc1][$bygningsnr];
			$streetkey = "{$row['street_id']}" . '_' . trim($row['street_number']);
			if (!isset($loc2StreetCombos[$loc1][$loc2]))
			{
				$loc2StreetCombos[$loc1][$loc2] = [];
			}
			if (!in_array($streetkey, $loc2StreetCombos[$loc1][$loc2]))
			{
				$loc2StreetCombos[$loc1][$loc2][] = $streetkey;
			}
		}

		$requiredLoc3 = []; // loc1 => loc2 => streetkey => loc3
		foreach ($loc2StreetCombos as $loc1 => $loc2s)
		{
			foreach ($loc2s as $loc2 => $streetkeys)
			{
				$assignedLoc3InLoc2 = [];
				
				$existingLoc3List = [];
				if (isset($this->loc3Refs[$loc1][$loc2]) && is_array($this->loc3Refs[$loc1][$loc2]))
				{
					$existingLoc3List = array_keys($this->loc3Refs[$loc1][$loc2]);
					sort($existingLoc3List, SORT_STRING);
				}

				foreach ($streetkeys as $streetkey)
				{
					$loc3 = null;

					// PRIORITY 1: Check streetToLoc3Map (from fm_location3)
					if (isset($this->streetToLoc3Map[$loc1][$loc2][$streetkey]))
					{
						$loc3 = $this->streetToLoc3Map[$loc1][$loc2][$streetkey];
					}
					// PRIORITY 2: Reuse existing loc3 that hasn't been assigned yet
					elseif (count($existingLoc3List) > 0)
					{
						foreach ($existingLoc3List as $existingLoc3)
						{
							if (!in_array($existingLoc3, $assignedLoc3InLoc2))
							{
								$loc3 = $existingLoc3;
								break;
							}
						}
					}

					if (!$loc3)
					{
						for ($newLoc3 = 1; $newLoc3 <= 99; $newLoc3++)
						{
							$new_loc3_str = str_pad($newLoc3, 2, '0', STR_PAD_LEFT);
							if (!isset($this->loc3Refs[$loc1][$loc2][$new_loc3_str]) &&
								!in_array($new_loc3_str, $assignedLoc3InLoc2))
							{
								$loc3 = $new_loc3_str;
								break;
							}
						}
					}

					if ($loc3 && !in_array($loc3, $assignedLoc3InLoc2))
					{
						$assignedLoc3InLoc2[] = $loc3;
					}

					$requiredLoc3[$loc1][$loc2][$streetkey] = $loc3;
				}
			}
		}

		// 4. Check and create missing loc2/loc3
		$this->debug("requiredLoc2: " . json_encode($requiredLoc2));
		$this->debug("requiredLoc3: " . json_encode($requiredLoc3));

		$this->sqlStatements['missing_loc2'] = [];
		foreach ($requiredLoc2 as $loc1 => $bygningsnrs)
		{
			foreach ($bygningsnrs as $bygningsnr => $loc2)
			{
				if (empty($this->loc2Refs[$loc1][$loc2]))
				{
					$this->debug("CREATING missing_loc2: loc1=$loc1, loc2=$loc2, bygningsnr=$bygningsnr (not found in loc2Refs)");
					$this->sqlStatements['missing_loc2'][] =
						"INSERT INTO fm_location2 (location_code, loc1, loc2, loc2_name) VALUES ('{$loc1}-{$loc2}', '{$loc1}', '{$loc2}', 'Bygningsnr:{$bygningsnr}') ON CONFLICT (loc1, loc2) DO NOTHING;";
					$this->issues[] = [
						'type' => 'missing_loc2',
						'loc1' => $loc1,
						'loc2' => $loc2,
						'bygningsnr' => $bygningsnr
					];
				}
			}
		}

		$this->sqlStatements['missing_loc3'] = [];
		foreach ($requiredLoc3 as $loc1 => $loc2s)
		{
			foreach ($loc2s as $loc2 => $streetkeys)
			{
				foreach ($streetkeys as $streetkey => $loc3)
				{
					if (empty($this->loc3Refs[$loc1][$loc2][$loc3]))
					{
						list($street_id, $street_number) = explode('_', $streetkey, 2);
						$street_name = $this->get_street_name($street_id);
						$loc3_name = "{$street_name} {$street_number}";
						$this->debug("CREATING missing_loc3: loc1=$loc1, loc2=$loc2, loc3=$loc3, streetkey=$streetkey (not found in loc3Refs)");
						$this->sqlStatements['missing_loc3'][] =
							"INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) VALUES ('{$loc1}-{$loc2}-{$loc3}', '{$loc1}', '{$loc2}', '{$loc3}', '{$loc3_name}') ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
						$this->issues[] = [
							'type' => 'missing_loc3',
							'loc1' => $loc1,
							'loc2' => $loc2,
							'loc3' => $loc3,
							'street_id' => $street_id,
							'street_number' => $street_number
						];
					}
				}
			}
		}

		// 5. Prepare location4 move statements
		$this->sqlStatements['location4_updates'] = [];
		$this->sqlStatements['corrections'] = [];
		foreach ($this->locationData as $row)
		{
			$loc1 = $row['loc1'];
			$bygningsnr = $row['bygningsnr'];
			$loc2_expected = $requiredLoc2[$loc1][$bygningsnr];
			$streetkey = "{$row['street_id']}" . '_' . trim($row['street_number']);
			$loc3_expected = $requiredLoc3[$loc1][$loc2_expected][$streetkey];

			$loc2_actual = $row['loc2'];
			$loc3_actual = $row['loc3'];
			$loc4 = $row['loc4'];
			$old_code = "{$loc1}-{$loc2_actual}-{$loc3_actual}-{$loc4}";
			$new_code = "{$loc1}-{$loc2_expected}-{$loc3_expected}-{$loc4}";
			if ($loc2_actual !== $loc2_expected || $loc3_actual !== $loc3_expected)
			{
				$this->sqlStatements['location4_updates'][] =
					"-- Move {$old_code} to {$new_code}\nUPDATE fm_location4 SET location_code='{$new_code}', loc2='{$loc2_expected}', loc3='{$loc3_expected}' WHERE location_code='{$old_code}';";
				$this->sqlStatements['corrections'][] =
					"INSERT INTO location_mapping (old_location_code, new_location_code, loc1, old_loc2, new_loc2, old_loc3, new_loc3, loc4, bygningsnr, street_id, street_number, change_type) VALUES ('{$old_code}', '{$new_code}', '{$loc1}', '{$loc2_actual}', '{$loc2_expected}', '{$loc3_actual}', '{$loc3_expected}', '{$loc4}', '{$bygningsnr}', '{$row['street_id']}', '{$row['street_number']}', 'location_hierarchy_update');";
				$this->issues[] = [
					'type' => 'misplaced_loc4',
					'loc1' => $loc1,
					'loc2' => $loc2_actual,
					'loc3' => $loc3_actual,
					'loc4' => $loc4,
					'expected_loc2' => $loc2_expected,
					'expected_loc3' => $loc3_expected
				];
			}
		}

		// Add update statements for all tables with location_code and loc1, loc2, loc3, loc4 columns
		$this->createUpdateStatements();

		// 6. Statistics
		$level1Values = array_values(array_unique(array_filter(array_map(fn($e) => trim((string)$e['loc1']), $this->locationData), fn($v) => $v !== '')));
		$statistics = [
			'level1_count' => count($level1Values),
			'level2_count' => count(array_unique(array_map(fn($e) => "{$e['loc1']}-{$e['loc2']}", $this->locationData))),
			'level3_count' => count(array_unique(array_map(fn($e) => "{$e['loc1']}-{$e['loc2']}-{$e['loc3']}", $this->locationData))),
			'level4_count' => count($this->locationData),
			'unique_buildings' => count(array_unique(array_column($this->locationData, 'bygningsnr'))),
			'unique_addresses' => count(array_unique(array_map(fn($e) => "{$e['street_id']}" . '-' . trim($e['street_number']), $this->locationData))),
			'total_issues' => count($this->issues),
			'issues_by_type' => array_count_values(array_column($this->issues, 'type')),
		];

		return [
			'statistics' => $statistics,
			'issues' => $this->issues,
			'suggestions' => $this->suggestions,
			'sql_statements' => $this->sqlStatements,
		];
	}

	public function analyzeAllLoc1Separately()
	{
		$loc1s = $this->getAllLoc1Values();
		$all = [
			'statistics' => [
				'level1_count' => count($loc1s),
				'level2_count' => 0,
				'level3_count' => 0,
				'level4_count' => 0,
				'unique_buildings' => 0,
				'unique_addresses' => 0,
				'total_issues' => 0,
				'issues_by_type' => [],
			],
			'issues' => [],
			'suggestions' => [],
			'sql_statements' => [
				'missing_loc2' => [],
				'missing_loc3' => [],
				'location4_updates' => [],
				'corrections' => [],
				],
		];
		foreach ($loc1s as $loc1)
		{
			$res = $this->analyze($loc1);
			foreach ($all['statistics'] as $k => $v)
			{
				if ($k === 'issues_by_type')
				{
					foreach ($res['statistics']['issues_by_type'] as $type => $cnt)
					{
						if (!isset($all['statistics']['issues_by_type'][$type]))
						{
							$all['statistics']['issues_by_type'][$type] = 0;
						}
						$all['statistics']['issues_by_type'][$type] += $cnt;
					}
				}
				elseif ($k === 'level1_count')
				{
					// already counted once as count($loc1s); per-loc1 analyze always returns 1, so skip summing
					continue;
				}
				else
				{
					$all['statistics'][$k] += $res['statistics'][$k];
				}
			}
			$all['issues'] = array_merge($all['issues'], $res['issues']);
			$all['suggestions'] = array_merge($all['suggestions'], $res['suggestions']);
			foreach ($all['sql_statements'] as $k => $v)
			{
				$all['sql_statements'][$k] = array_merge($all['sql_statements'][$k], $res['sql_statements'][$k] ?? []);
			}
		}
		$all['sql_statements']['update_location_from_mapping'] = $this->createUpdateStatements();
		return $all;
	}

	/**
	 * create update statements for all tables with location_code
	 * and loc1, loc2, loc3, loc4 columns using the mapping table
	 */
	private function createUpdateStatements()
	{
		$sqlStatements = []; // Removed static to fix caching bug
		$tables = $this->findLocationCodeTables();
		foreach ($tables as $table => $columns)
		{
			$sql = "UPDATE {$table} SET location_code = location_mapping.new_location_code";
			if (in_array('loc1', $columns))
			{
				$sql .= ", loc1 = location_mapping.loc1";
			}
			if (in_array('loc2', $columns))
			{
				$sql .= ", loc2 = location_mapping.new_loc2";
			}
			if (in_array('loc3', $columns))
			{
				$sql .= ", loc3 = location_mapping.new_loc3";
			}
			if (in_array('loc4', $columns))
			{
				$sql .= ", loc4 = location_mapping.loc4";
			}
			$sql .= " FROM location_mapping WHERE {$table}.location_code = location_mapping.old_location_code;";
			$sqlStatements[] = $sql;
		}
		$this->sqlStatements['update_location_from_mapping'] = $sqlStatements;
		return $sqlStatements; // Add explicit return
	}

	/**
	 * Assign synthetic bygningsnr for missing bygningsnr in loc4.
	 */
	private function assignSyntheticBygningsnr()
	{
		$synthetic = -1;
		$seen = [];
		foreach ($this->locationData as $i => &$row)
		{
			if (empty($row['bygningsnr']))
			{
				$key = "{$row['loc1']}_{$row['street_id']}_{$row['street_number']}";
				if (!isset($seen[$key]))
				{
					$seen[$key] = "synthetic_{$synthetic}";
					$synthetic--;
				}
				$row['bygningsnr'] = $seen[$key];
			}
			$this->entryToBygningsnrMap[$i] = $row['bygningsnr'];
		}
		unset($row);
	}

	/**
	 * Load all data for loc4, loc2, loc3.
	 */
	private function loadData($filterLoc1 = null)
	{
		$this->locationData = [];
		$this->loc2Refs = [];
		$this->loc3Refs = [];
		$sql = "SELECT loc1, loc2, loc3, loc4, bygningsnr, street_id, street_number FROM fm_location4";
		if ($filterLoc1) 
		{
			$sql .= " WHERE loc1 = '{$filterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc4, loc2, loc3";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$this->locationData[] = [
				'loc1' => $this->db->f('loc1'),
				'loc2' => $this->db->f('loc2'),
				'loc3' => $this->db->f('loc3'),
				'loc4' => $this->db->f('loc4'),
				'bygningsnr' => $this->db->f('bygningsnr'),
				'street_id' => $this->db->f('street_id'),
				'street_number' => $this->db->f('street_number'),
			];
		}
		$sql = "SELECT loc1, loc2 FROM fm_location2";
		if ($filterLoc1)
		{
			$sql .= " WHERE loc1 = '{$filterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$this->loc2Refs[$this->db->f('loc1')][$this->db->f('loc2')] = true;
		}
		$sql = "SELECT loc1, loc2, loc3 FROM fm_location3";
		if ($filterLoc1)
		{
			$sql .= " WHERE loc1 = '{$filterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2, loc3";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$this->loc3Refs[$this->db->f('loc1')][$this->db->f('loc2')][$this->db->f('loc3')] = true;
		}
	}

	/**
	 * Optionally create the location_mapping table if it does not exist.
	 */
	private function createLocationMappingTableIfNotExists()
	{
		$sql = "CREATE TABLE IF NOT EXISTS location_mapping (
			id SERIAL PRIMARY KEY,
			old_location_code VARCHAR(50),
			new_location_code VARCHAR(50),
			loc1 VARCHAR(6),
			old_loc2 VARCHAR(2),
			new_loc2 VARCHAR(2),
			old_loc3 VARCHAR(2),
			new_loc3 VARCHAR(2),
			loc4 VARCHAR(3),
			bygningsnr VARCHAR(15),
			street_id INTEGER,
			street_number VARCHAR(10),
			change_type VARCHAR(100),
			update_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		)";
		$this->db->query($sql, __LINE__, __FILE__);
	}

	/**
	 * Get all unique loc1 values.
	 */
	public function getAllLoc1Values()
	{
		$loc1s = [];
		$sql = "SELECT DISTINCT loc1 FROM fm_location4 WHERE loc1 IS NOT NULL AND trim(loc1) <> '' ORDER BY loc1";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$loc1_value = trim($this->db->f('loc1'));
			if ($loc1_value !== '')
			{
				$loc1s[] = $loc1_value;
			}
		}
		return $loc1s;
	}

	/**
	 * Build bygningsnr to loc2 map by analyzing which loc2/loc3 combinations already exist in the database
	 * and matching them to bygningsnr based on street_id and street_number combinations.
	 * 
	 * Logic:
	 * 1. First, check fm_location3 to see which bygningsnr are already correctly placed
	 * 2. For each bygningsnr in fm_location3, extract the streets from the loc3_name
	 * 3. Match those to bygningsnr from fm_location4 data
	 * 4. If a match is found, map the bygningsnr to that loc2
	 */
	private function buildBygningsnrToLoc2MapFromDatabase($filterLoc1 = null)
	{
		$this->bygningsnrToLoc2Map = [];
		
		// DEBUG: Show what bygningsnr exist in locationData
		$bygningsnrSample = [];
		foreach ($this->locationData as $row)
		{
			if (!empty($row['bygningsnr']) && count($bygningsnrSample) < 5)
			{
				$bygningsnrSample[] = $row['bygningsnr'];
			}
		}
		$this->debug("locationData sample bygningsnr: " . json_encode(array_unique($bygningsnrSample)));
		
		// First, try to infer bygningsnr from fm_location3 names
		// by matching streets in loc3_name to streets in fm_location4
		$bygningsnrFromLoc3 = []; // loc1 => loc2 => loc3 => [streetkeys that should be here]
		$sql = "SELECT loc1, loc2, loc3, loc3_name FROM fm_location3";
		if ($filterLoc1)
		{
			$sql .= " WHERE loc1 = '{$filterLoc1}'";
		}
		$this->db->query($sql, __LINE__, __FILE__);
		
		// Extract streets from loc3_name (format: "Street Number A" or similar)
		while ($this->db->next_record())
		{
			$loc1 = $this->db->f('loc1');
			$loc2 = $this->db->f('loc2');
			$loc3 = $this->db->f('loc3');
			$loc3_name = $this->db->f('loc3_name');
			
			// Try to extract street_number from loc3_name
			// Common patterns: "Street N A", "Street NA", etc.
			preg_match('/\s+([0-9]+\s*[A-Z]?)$/i', $loc3_name, $matches);
			if (!empty($matches[1]))
			{
				$street_number = trim($matches[1]);
				if (!isset($bygningsnrFromLoc3[$loc1][$loc2][$loc3]))
				{
					$bygningsnrFromLoc3[$loc1][$loc2][$loc3] = [];
				}
				$bygningsnrFromLoc3[$loc1][$loc2][$loc3][] = $street_number;
			}
		}
		
		// Now match these streets to bygningsnr in fm_location4
		// For each loc2 in fm_location3, find which bygningsnr has streets matching its loc3 entries
		foreach ($bygningsnrFromLoc3 as $loc1 => $loc2s)
		{
			foreach ($loc2s as $loc2 => $loc3s)
			{
				// Collect all street_numbers for this loc2
				$loc2StreetNumbers = [];
				foreach ($loc3s as $loc3 => $streetNumbers)
				{
					$loc2StreetNumbers = array_merge($loc2StreetNumbers, $streetNumbers);
				}
				
				// Find which bygningsnr in fm_location4 has these street_numbers
				if (!empty($loc2StreetNumbers))
				{
					foreach ($this->locationData as $row)
					{
						if ($row['loc1'] === $loc1 && !empty($row['bygningsnr']))
						{
							$street_number = trim($row['street_number']);
							// If this bygningsnr has a street_number that belongs in this loc2, map it there
							if (in_array($street_number, $loc2StreetNumbers))
							{
								if (!isset($this->bygningsnrToLoc2Map[$loc1]))
								{
									$this->bygningsnrToLoc2Map[$loc1] = [];
								}
								if (!isset($this->bygningsnrToLoc2Map[$loc1][$row['bygningsnr']]))
								{
									$this->bygningsnrToLoc2Map[$loc1][$row['bygningsnr']] = [];
								}
								// Add this loc2 to the list for this bygningsnr (if not already there)
								if (!in_array($loc2, $this->bygningsnrToLoc2Map[$loc1][$row['bygningsnr']]))
								{
									$this->bygningsnrToLoc2Map[$loc1][$row['bygningsnr']][] = $loc2;
								}
							}
						}
					}
				}
			}
		}
		
		// Fallback: if fm_location3 doesn't help, build map from fm_location4
		// Build map of ALL loc2 values where each bygningsnr currently exists
		$bygningsnrInLoc2 = []; // loc1 => bygningsnr => [loc2 values]
		foreach ($this->locationData as $row)
		{
			$loc1 = $row['loc1'];
			$loc2 = $row['loc2'];
			$bygningsnr = $row['bygningsnr'];
			
			if (!empty($bygningsnr))
			{
				// Only use fm_location4 mapping if not already found from fm_location3
				if (!isset($this->bygningsnrToLoc2Map[$loc1][$bygningsnr]) || empty($this->bygningsnrToLoc2Map[$loc1][$bygningsnr]))
				{
					if (!isset($bygningsnrInLoc2[$loc1][$bygningsnr]))
					{
						$bygningsnrInLoc2[$loc1][$bygningsnr] = [];
					}
					if (!in_array($loc2, $bygningsnrInLoc2[$loc1][$bygningsnr]))
					{
						$bygningsnrInLoc2[$loc1][$bygningsnr][] = $loc2;
					}
				}
			}
		}
		
		// Store fallback loc2 values (sorted) for each bygningsnr
		foreach ($bygningsnrInLoc2 as $loc1 => $bygningsnrs)
		{
			foreach ($bygningsnrs as $bygningsnr => $loc2Values)
			{
				// Sort to get consistent ordering
				sort($loc2Values, SORT_STRING);
				
				if (!isset($this->bygningsnrToLoc2Map[$loc1]))
				{
					$this->bygningsnrToLoc2Map[$loc1] = [];
				}
				$this->bygningsnrToLoc2Map[$loc1][$bygningsnr] = $loc2Values;
			}
		}
		
		// Ensure all entries in bygningsnrToLoc2Map are arrays and sorted
		foreach ($this->bygningsnrToLoc2Map as $loc1 => $bygningsnrs)
		{
			foreach ($bygningsnrs as $bygningsnr => $loc2Values)
			{
				if (!is_array($loc2Values))
				{
					$this->bygningsnrToLoc2Map[$loc1][$bygningsnr] = [$loc2Values];
				} else {
					// Sort to get consistent ordering (prefer lower loc2 numbers)
					sort($loc2Values, SORT_STRING);
					$this->bygningsnrToLoc2Map[$loc1][$bygningsnr] = $loc2Values;
				}
			}
		}

		// FALLBACK: If still not mapped, use streetToLoc3Map (from fm_location3) to choose the best loc2
		$bygningsnrIndexLocal = [];
		foreach ($this->locationData as $row)
		{
			$loc1 = $row['loc1'];
			$bygningsnr = $row['bygningsnr'];
			if (!isset($bygningsnrIndexLocal[$loc1]))
			{
				$bygningsnrIndexLocal[$loc1] = [];
			}
			if (!in_array($bygningsnr, $bygningsnrIndexLocal[$loc1]))
			{
				$bygningsnrIndexLocal[$loc1][] = $bygningsnr;
			}
		}

		foreach ($bygningsnrIndexLocal as $loc1 => $bygningsnrs)
		{
			foreach ($bygningsnrs as $bygningsnr)
			{
				// Collect streetkeys for this bygningsnr from locationData
				$streetkeysForBygning = [];
				$rowsFound = 0;
				$this->debug("Searching for bygningsnr={$bygningsnr} (type=" . gettype($bygningsnr) . ") in loc1={$loc1}");
				$this->debug("  locationData has " . count($this->locationData) . " rows");
				foreach ($this->locationData as $row)
				{
					if ($row['loc1'] == $loc1 && $row['bygningsnr'] == $bygningsnr)  // Use == for both to handle type differences
					{
						$rowsFound++;
						$sk = $row['street_id'] . '_' . trim($row['street_number']);
						$this->debug("  row matched: street_id={$row['street_id']}, street_number={$row['street_number']}, streetkey={$sk}");
						if (!empty($row['street_id']) && !empty($row['street_number']))
						{
							$streetkeysForBygning[] = $sk;
						}
					}
				}
				if ($rowsFound === 0)
				{
					$this->debug("  NO MATCHES for bygningsnr={$bygningsnr} in loc1={$loc1}. Checking first 3 rows:");
					$checkCount = 0;
					foreach ($this->locationData as $row)
					{
						if ($checkCount++ < 3)
						{
							$this->debug("    row loc1={$row['loc1']} bygningsnr={$row['bygningsnr']} (type=" . gettype($row['bygningsnr']) . ")");
						}
					}
				}
				$streetkeysForBygning = array_values(array_unique($streetkeysForBygning));
				$this->debug("bygningsnr={$bygningsnr} rowsFound={$rowsFound} streetkeys=" . json_encode($streetkeysForBygning));

				// Score each loc2 based on how many of these streetkeys exist in streetToLoc3Map.
				// Tie-breaker: prefer loc2 with fewer total streets (more specific group), then lower loc2 number.
				$bestLoc2 = null;
				$bestScore = 0;
				$bestTotalStreets = PHP_INT_MAX;
				if (isset($this->streetToLoc3Map[$loc1]))
				{
					foreach ($this->streetToLoc3Map[$loc1] as $loc2Candidate => $streets)
					{
						$score = 0;
						foreach ($streetkeysForBygning as $sk)
						{
							if (isset($streets[$sk]))
							{
								$score++;
							}
						}
						$totalStreetsHere = count($streets);
						$this->debug("  loc2Candidate={$loc2Candidate} score={$score} totalStreetsHere={$totalStreetsHere}");
						if ($score > $bestScore || ($score === $bestScore && $totalStreetsHere < $bestTotalStreets) || ($score === $bestScore && $totalStreetsHere === $bestTotalStreets && $loc2Candidate < $bestLoc2))
						{
							$bestScore = $score;
							$bestLoc2 = $loc2Candidate;
							$bestTotalStreets = $totalStreetsHere;
						}
					}
				}

				if ($bestLoc2 !== null && $bestScore > 0)
				{
					$this->debug("bygningsnrToLoc2 fallback matched bygningsnr={$bygningsnr} to loc2={$bestLoc2} (score={$bestScore}) via streetToLoc3Map");
					// Always prefer the bestLoc2 from fm_location3 street mapping
					$this->bygningsnrToLoc2Map[$loc1][$bygningsnr] = [$bestLoc2];
				}
			}
		}

		$this->debug("bygningsnrToLoc2Map: " . json_encode($this->bygningsnrToLoc2Map));
	}

	/**
	 * Build street to loc3 map by analyzing fm_location4 directly.
	 * This identifies which streets are currently assigned to each loc3,
	 * regardless of whether the data is misplaced or not.
	 */
	private function buildStreetToLoc3MapFromLocation4()
	{
		$this->streetToLoc3Map = [];
		
		// FIRST: Read fm_location3 to get the authoritative street-to-loc3 mapping
		// This tells us where streets SHOULD be placed
		$sql = "SELECT DISTINCT loc1, loc2, loc3, loc3_name FROM fm_location3";
		if ($this->currentFilterLoc1)
		{
			$sql .= " WHERE loc1 = '{$this->currentFilterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2, loc3";
		
		$this->db->query($sql, __LINE__, __FILE__);
		
		// Extract streets from loc3_name and build map
		while ($this->db->next_record())
		{
			$loc1 = $this->db->f('loc1');
			$loc2 = $this->db->f('loc2');
			$loc3 = $this->db->f('loc3');
			$loc3_name = $this->db->f('loc3_name');
			
			if (!isset($this->streetToLoc3Map[$loc1]))
			{
				$this->streetToLoc3Map[$loc1] = [];
			}
			if (!isset($this->streetToLoc3Map[$loc1][$loc2]))
			{
				$this->streetToLoc3Map[$loc1][$loc2] = [];
			}
			
			// Extract street information from loc3_name
			// Common patterns: "Street 8 A", "Street 8 B", etc.
			// We need to match this with fm_location4 street_number (e.g., "8 A", "8 B")
			preg_match('/\s+([0-9]+\s*[A-Z]?)$/i', $loc3_name, $matches);
			if (!empty($matches[1]))
			{
				$street_number = trim($matches[1]);
				
				// Build streetkey by finding the street_id from fm_location4 with this number
				// For now, store by street_number and we'll refine below
				$this->streetToLoc3Map[$loc1][$loc2][$street_number] = $loc3;
			}
		}
		
		// Now normalize streetkeys to match fm_location4 format (street_id_street_number)
		// by looking up actual street_ids used in fm_location4
		foreach ($this->locationData as $row)
		{
			$loc1 = $row['loc1'];
			$street_id = $row['street_id'];
			$street_number = trim($row['street_number']);
			$streetkey = "{$street_id}_{$street_number}";
			
			// If we found a mapping for this street_number from fm_location3, use it
			if (isset($this->streetToLoc3Map[$loc1]))
			{
				foreach ($this->streetToLoc3Map[$loc1] as $loc2 => $streets)
				{
					if (isset($streets[$street_number]))
					{
						// Map the full streetkey to this loc3
						$loc3 = $streets[$street_number];
						$this->streetToLoc3Map[$loc1][$loc2][$streetkey] = $loc3;
						// Remove the temporary street_number-only entry
						unset($this->streetToLoc3Map[$loc1][$loc2][$street_number]);
					}
				}
			}
		}
		
		// FALLBACK: If fm_location3 doesn't cover everything, fill gaps from fm_location4
		// Query fm_location4 for any streets not yet mapped
		$sql = "SELECT DISTINCT loc1, loc2, loc3, street_id, street_number FROM fm_location4";
		if ($this->currentFilterLoc1)
		{
			$sql .= " WHERE loc1 = '{$this->currentFilterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2, loc3, street_id, street_number";
		
		$this->db->query($sql, __LINE__, __FILE__);
		
		// Build fallback map from fm_location4
		$loc3StreetMap = []; // loc1 => loc2 => loc3 => [streetkeys]
		while ($this->db->next_record())
		{
			$loc1 = $this->db->f('loc1');
			$loc2 = $this->db->f('loc2');
			$loc3 = $this->db->f('loc3');
			$street_id = $this->db->f('street_id');
			$street_number = $this->db->f('street_number');
			
			if ($street_id && $street_number)
			{
				$streetkey = "{$street_id}_" . trim($street_number);
				
				// Only use fm_location4 if this street isn't already mapped from fm_location3
				if (!isset($this->streetToLoc3Map[$loc1][$loc2][$streetkey]))
				{
					if (!isset($loc3StreetMap[$loc1][$loc2][$loc3]))
					{
						$loc3StreetMap[$loc1][$loc2][$loc3] = [];
					}
					if (!in_array($streetkey, $loc3StreetMap[$loc1][$loc2][$loc3]))
					{
						$loc3StreetMap[$loc1][$loc2][$loc3][] = $streetkey;
					}
				}
			}
		}
		
		// Build reverse fallback map from fm_location4
		foreach ($loc3StreetMap as $loc1 => $loc2s)
		{
			foreach ($loc2s as $loc2 => $loc3s)
			{
				if (!isset($this->streetToLoc3Map[$loc1]))
				{
					$this->streetToLoc3Map[$loc1] = [];
				}
				if (!isset($this->streetToLoc3Map[$loc1][$loc2]))
				{
					$this->streetToLoc3Map[$loc1][$loc2] = [];
				}
				
				// Collect all loc3 values for each street in this loc2
				$streetToLoc3s = []; // streetkey => [loc3 values]
				foreach ($loc3s as $loc3 => $streetkeys)
				{
					foreach ($streetkeys as $streetkey)
					{
						if (!isset($streetToLoc3s[$streetkey]))
						{
							$streetToLoc3s[$streetkey] = [];
						}
						if (!in_array($loc3, $streetToLoc3s[$streetkey]))
						{
							$streetToLoc3s[$streetkey][] = $loc3;
						}
					}
				}
				
				// For each street, select the minimum loc3
				foreach ($streetToLoc3s as $streetkey => $loc3Values)
				{
					if (!isset($this->streetToLoc3Map[$loc1][$loc2][$streetkey]))
					{
						sort($loc3Values, SORT_STRING);
						$this->streetToLoc3Map[$loc1][$loc2][$streetkey] = $loc3Values[0];
					}
				}
			}
		}
	}

	/**
	 * Get street name from street_id.
	 */
	private function get_street_name($street_id)
	{
		static $cache = [];
		if (isset($cache[$street_id]))
		{
			return $cache[$street_id];
		}
		$sql = "SELECT descr FROM fm_streetaddress WHERE id = {$street_id}";
		$this->db->query($sql, __LINE__, __FILE__);
		$cache[$street_id] = $this->db->next_record() ? $this->db->f('descr') : 'Unknown Street';
		return $cache[$street_id];
	}

	/**
	 * Execute selected SQL statements.
	 */
	public function executeSqlStatements($loc1, $sqlTypes, $sqlStatements)
	{
		$results = [];

		// Only create location_mapping table if requested
		if (in_array('schema', $sqlTypes)) {
			$this->createLocationMappingTableIfNotExists();
			$results['schema'] = 'Location mapping table created.';
		}

		foreach ($sqlTypes as $sqlType)
		{
			if (!isset($sqlStatements[$sqlType]))
			{
				continue;
			}
			$count = 0;
			foreach ($sqlStatements[$sqlType] as $sql)
			{
				if (strpos($sql, '--') === 0) 
				{
					continue;
				}
				try
				{
					$this->db->query($sql, __LINE__, __FILE__);
					$count++;
				}
				catch (\Exception $e)
				{
					error_log("Error executing SQL: " . $e->getMessage());
				}
			}
			$results[$sqlType] = $count;
		}
		return $results;
	}

	/**
	 * Reset state for a new analysis.
	 */
	public function resetState()
	{
		$this->locationData = [];
		$this->loc2Refs = [];
		$this->loc3Refs = [];
		$this->issues = [];
		$this->suggestions = [];
		$this->sqlStatements = [];
		$this->entryToBygningsnrMap = [];
		$this->processedLocationCodes = [];
		$this->currentFilterLoc1 = null;
		$this->bygningsnrToLoc2Map = [];
		$this->streetToLoc3Map = [];
	}

	/**
	 * Find all tables that have a location_code column.
	 */
	private function findLocationCodeTables()
	{
		$tables = [];
		$sql = "SELECT table_name, column_name FROM information_schema.columns
		 WHERE column_name IN ('location_code', 'loc1', 'loc2', 'loc3', 'loc4')
		 AND table_name NOT IN ('fm_location4', 'fm_location3', 'fm_location2', 'fm_location1', 'location_mapping')";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$tables[$this->db->f('table_name')][] = $this->db->f('column_name');
		}
		return $tables;
	}
}
