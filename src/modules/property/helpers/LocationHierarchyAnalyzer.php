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

	public function __construct()
	{
		$this->db = Db::getInstance();
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
		$this->buildBygningsnrToLoc2MapFromDatabase($filterLoc1);
		$this->buildStreetToLoc3MapFromLocation4();

		// 3. Build required loc2/loc3 sets
		$requiredLoc2 = []; // loc1 => bygningsnr => loc2
		$requiredLoc3 = []; // loc1 => loc2 => streetkey => loc3

		// Build maps for counting
		$bygningsnrIndex = []; // loc1 => [bygningsnr values]
		$bygningsnrCount = []; // loc1 => bygningsnr => count
		foreach ($this->locationData as $i => $row)
		{
			$loc1 = $row['loc1'];
			$bygningsnr = $row['bygningsnr'];
			if (!isset($bygningsnrIndex[$loc1])) $bygningsnrIndex[$loc1] = [];
			if (!in_array($bygningsnr, $bygningsnrIndex[$loc1])) $bygningsnrIndex[$loc1][] = $bygningsnr;
			
			// Count occurrences of each bygningsnr
			if (!isset($bygningsnrCount[$loc1][$bygningsnr])) $bygningsnrCount[$loc1][$bygningsnr] = 0;
			if (!empty($bygningsnr)) $bygningsnrCount[$loc1][$bygningsnr]++;
		}

		// Identify dominant bygningsnr in mixed loc2 values
		$dominantBygningsnrInLoc2 = []; // loc1 => loc2 => bygningsnr (the dominant one in mixed loc2)
		foreach ($this->loc2Refs as $loc1 => $loc2s)
		{
			foreach ($loc2s as $loc2 => $exists)
			{
				if (!$exists) continue;
				// Find which bygningsnr appears most in this loc2
				$maxCount = 0;
				$dominantBygningsnr = null;
				
				foreach ($this->locationData as $row)
				{
					if ($row['loc1'] === $loc1 && $row['loc2'] === $loc2 && !empty($row['bygningsnr']))
					{
						if (isset($bygningsnrCount[$loc1][$row['bygningsnr']]))
						{
							if ($bygningsnrCount[$loc1][$row['bygningsnr']] > $maxCount)
							{
								$maxCount = $bygningsnrCount[$loc1][$row['bygningsnr']];
								$dominantBygningsnr = $row['bygningsnr'];
							}
						}
					}
				}
				
				if ($dominantBygningsnr)
				{
					$dominantBygningsnrInLoc2[$loc1][$loc2] = $dominantBygningsnr;
				}
			}
		}

		// Assign loc2 for each bygningsnr
		foreach ($bygningsnrIndex as $loc1 => $bygningsnrs)
		{
			// Sort bygningsnr to ensure consistent ordering
			sort($bygningsnrs, SORT_NUMERIC);
			
			$nextLoc2Num = 1;
			$assignedLoc2s = []; // Track which loc2 we've assigned to avoid duplicates
			
			foreach ($bygningsnrs as $bygningsnr)
			{
				$loc2 = null;
				
				// First: check if this bygningsnr maps to an existing loc2 via street matching
				if (isset($this->bygningsnrToLoc2Map[$loc1][$bygningsnr]))
				{
					$loc2 = $this->bygningsnrToLoc2Map[$loc1][$bygningsnr];
				}
				// Second: if no street match, reuse existing unused loc2 values before creating new ones
				else
				{
					// Find the next existing loc2 that hasn't been assigned yet
					while ($nextLoc2Num <= 99)
					{
						$candidate_loc2 = str_pad($nextLoc2Num, 2, '0', STR_PAD_LEFT);
						$nextLoc2Num++;
						
					// Use this loc2 if it exists in database AND hasn't been assigned yet
					if (isset($this->loc2Refs[$loc1][$candidate_loc2]) && 
						!in_array($candidate_loc2, $assignedLoc2s))
					{
						$loc2 = $candidate_loc2;
						break;
					}
					}
					
					// Only create new loc2 if we've exhausted all existing ones
					if (!$loc2)
					{
						// Find next available loc2 number
						do {
							$loc2 = str_pad($nextLoc2Num, 2, '0', STR_PAD_LEFT);
							$nextLoc2Num++;
						} while (isset($this->loc2Refs[$loc1][$loc2]) || in_array($loc2, $assignedLoc2s));
					}
				}
				
				$requiredLoc2[$loc1][$bygningsnr] = $loc2;
				$assignedLoc2s[] = $loc2;
			}
		}

		// For each loc2, assign loc3 for each unique (street_id, street_number)
		$loc2StreetCombos = []; // loc1 => loc2 => [streetkey]
		foreach ($this->locationData as $i => $row)
		{
			$loc1 = $row['loc1'];
			$bygningsnr = $row['bygningsnr'];
			$loc2 = $requiredLoc2[$loc1][$bygningsnr];
			// Normalize street_number by trimming whitespace
			$streetkey = "{$row['street_id']}_" . trim($row['street_number']);
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
				// Track which loc3 values have been assigned within this loc2
				// to ensure each street gets a distinct loc3
				$assignedLoc3InLoc2 = [];
				
				foreach ($streetkeys as $streetkey)
				{
					$loc3 = null;
					
					// First priority: check if this street already has a loc3 in the database
					if (isset($this->streetToLoc3Map[$loc1][$loc2][$streetkey]))
					{
						$loc3 = $this->streetToLoc3Map[$loc1][$loc2][$streetkey];
					}
					else
					{
						// Second priority: find the next available existing loc3 under this loc2
						// that hasn't been assigned yet in this iteration
						for ($tryLoc3 = 1; $tryLoc3 <= 99; $tryLoc3++)
						{
							$try_loc3_str = str_pad($tryLoc3, 2, '0', STR_PAD_LEFT);
							
							// If this loc3 exists in the database and hasn't been assigned yet, use it
							if (isset($this->loc3Refs[$loc1][$loc2][$try_loc3_str]) && 
								!in_array($try_loc3_str, $assignedLoc3InLoc2))
							{
								$loc3 = $try_loc3_str;
								break;
							}
						}
						
						// If no available existing loc3, create a new one at the first available number
						if (!$loc3)
						{
							for ($newLoc3 = 1; $newLoc3 <= 99; $newLoc3++)
							{
								$new_loc3_str = str_pad($newLoc3, 2, '0', STR_PAD_LEFT);
								
								// Use the first loc3 that doesn't exist and hasn't been assigned yet
								if (!isset($this->loc3Refs[$loc1][$loc2][$new_loc3_str]) && 
									!in_array($new_loc3_str, $assignedLoc3InLoc2))
								{
									$loc3 = $new_loc3_str;
									break;
								}
							}
						}
					}
					
					// Track that we've assigned this loc3 for this loc2
					if ($loc3 && !in_array($loc3, $assignedLoc3InLoc2))
					{
						$assignedLoc3InLoc2[] = $loc3;
					}
					
					$requiredLoc3[$loc1][$loc2][$streetkey] = $loc3;
				}
			}
		}

		// 4. Check and create missing loc2/loc3
		$this->sqlStatements['missing_loc2'] = [];
		foreach ($requiredLoc2 as $loc1 => $bygningsnrs)
		{
			foreach ($bygningsnrs as $bygningsnr => $loc2)
			{
				if (empty($this->loc2Refs[$loc1][$loc2]))
				{
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

		// 5. Check if loc4 entries are misplaced and simulate moves
		$this->sqlStatements['location4_updates'] = [];
		$this->sqlStatements['corrections'] = [];
		foreach ($this->locationData as $i => $row)
		{
			$loc1 = $row['loc1'];
			$bygningsnr = $row['bygningsnr'];
			$loc2_expected = $requiredLoc2[$loc1][$bygningsnr];
			// Normalize street_number by trimming whitespace
			$streetkey = "{$row['street_id']}_" . trim($row['street_number']);
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
		$statistics = [
			'level1_count' => count(array_unique(array_column($this->locationData, 'loc1'))),
			'level2_count' => count(array_unique(array_map(fn($e) => "{$e['loc1']}-{$e['loc2']}", $this->locationData))),
			'level3_count' => count(array_unique(array_map(fn($e) => "{$e['loc1']}-{$e['loc2']}-{$e['loc3']}", $this->locationData))),
			'level4_count' => count($this->locationData),
			'unique_buildings' => count(array_unique(array_column($this->locationData, 'bygningsnr'))),
			// Normalize street_number by trimming when calculating unique addresses
			'unique_addresses' => count(array_unique(array_map(fn($e) => "{$e['street_id']}-" . trim($e['street_number']), $this->locationData))),
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

	/**
	 * Analyze all loc1 values separately and combine results.
	 */
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
		$sql = "SELECT DISTINCT loc1 FROM fm_location4 ORDER BY loc1";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record()) $loc1s[] = $this->db->f('loc1');
		return $loc1s;
	}

	/**
	 * Build bygningsnr to loc2 map by analyzing which loc2/loc3 combinations already exist in the database
	 * and matching them to bygningsnr based on street_id and street_number combinations.
	 * 
	 * Logic:
	 * 1. For each bygningsnr, determine which (street_id, street_number) combinations it has from fm_location4
	 * 2. Find which loc3 entries contain those same street combinations
	 * 3. Get the loc2 that contains those loc3 entries
	 * 4. Use that loc2 for the bygningsnr
	 */
	private function buildBygningsnrToLoc2MapFromDatabase($filterLoc1 = null)
	{
		$this->bygningsnrToLoc2Map = [];
		
		// Build map of which streets exist under each loc3 in fm_location4
		// This tells us which (street_id, street_number) are registered under which loc3
		$loc3ToStreets = []; // loc1 => loc2 => loc3 => [streetkeys]
		foreach ($this->locationData as $row)
		{
			$loc1 = $row['loc1'];
			$loc2 = $row['loc2'];
			$loc3 = $row['loc3'];
			$streetkey = "{$row['street_id']}_" . trim($row['street_number']);
			
			if (!isset($loc3ToStreets[$loc1][$loc2][$loc3]))
			{
				$loc3ToStreets[$loc1][$loc2][$loc3] = [];
			}
			if (!in_array($streetkey, $loc3ToStreets[$loc1][$loc2][$loc3]))
			{
				$loc3ToStreets[$loc1][$loc2][$loc3][] = $streetkey;
			}
		}
		
		// Build map of which streets each bygningsnr has
		$bygningsnrToStreets = []; // bygningsnr => [streetkeys]
		foreach ($this->locationData as $row)
		{
			$bygningsnr = $row['bygningsnr'];
			if (!empty($bygningsnr))
			{
				if (!isset($bygningsnrToStreets[$bygningsnr]))
				{
					$bygningsnrToStreets[$bygningsnr] = [];
				}
				$streetkey = "{$row['street_id']}_" . trim($row['street_number']);
				if (!in_array($streetkey, $bygningsnrToStreets[$bygningsnr]))
				{
					$bygningsnrToStreets[$bygningsnr][] = $streetkey;
				}
			}
		}
		
		// For each bygningsnr, find which existing loc2 should contain it
		// by matching its streets to existing loc3 street combinations
		foreach ($bygningsnrToStreets as $bygningsnr => $streets)
		{
			sort($streets);
			
			// Search for a loc3 that has exactly these streets
			foreach ($loc3ToStreets as $loc1 => $loc2s)
			{
				foreach ($loc2s as $loc2 => $loc3s)
				{
					foreach ($loc3s as $loc3 => $loc3Streets)
					{
						sort($loc3Streets);
						
						// If this loc3's streets match this bygningsnr's streets, use this loc2
						if ($streets === $loc3Streets)
						{
							// Only map if not already mapped (first match wins)
							if (!isset($this->bygningsnrToLoc2Map[$loc1][$bygningsnr]))
							{
								$this->bygningsnrToLoc2Map[$loc1][$bygningsnr] = $loc2;
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Build street to loc3 map by analyzing fm_location4 directly.
	 * This identifies which streets are currently assigned to each loc3,
	 * regardless of whether the data is misplaced or not.
	 */
	private function buildStreetToLoc3MapFromLocation4()
	{
		$this->streetToLoc3Map = [];
		
		// Query fm_location4 directly to find which streets are in each (loc1, loc2, loc3)
		$sql = "SELECT DISTINCT loc1, loc2, loc3, street_id, street_number FROM fm_location4";
		if ($this->currentFilterLoc1)
		{
			$sql .= " WHERE loc1 = '{$this->currentFilterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2, loc3, street_id, street_number";
		
		$this->db->query($sql, __LINE__, __FILE__);
		
		// Build map of (loc1, loc2, loc3) -> [streets]
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
		
		// Build reverse map: street -> loc3 for each loc2
		// For each street, map it to the minimum (first) loc3 where it appears
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
					sort($loc3Values, SORT_STRING);
					$this->streetToLoc3Map[$loc1][$loc2][$streetkey] = $loc3Values[0];
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
