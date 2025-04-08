<?php

/**
 * phpGroupWare - property: Location Hierarchy Analysis
 *
 * @author GitHub Copilot
 * @copyright Copyright (C) 2025 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package property
 * @subpackage helpers
 */

namespace App\modules\property\helpers;

use App\Database\Db;

/**
 * Class to analyze location hierarchy quality
 */
class LocationHierarchyAnalyzer
{
	private $db;
	private $locationData = [];
	private $loc2References = [];
	private $loc3References = [];
	private $issues = [];
	private $suggestions = [];

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	public function analyze($filterLoc1 = null)
	{
		$this->loadData($filterLoc1);
		$this->analyzeData();

		return [
			'statistics' => $this->generateStatistics(),
			'issues' => $this->issues,
			'suggestions' => $this->suggestions,
			'sql_statements' => $this->generateSQLStatements(true),
		];
	}

	private function loadData($filterLoc1 = null)
	{
		$sql = "SELECT loc1, loc2, loc3, loc4, bygningsnr, street_id, street_number FROM fm_location4";
		if ($filterLoc1)
		{
			$sql .= " WHERE loc1 = '{$filterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2, loc3, loc4";
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
				'street_number' => $this->db->f('street_number')
			];
		}

		// Load references for fm_location2
		$sql = "SELECT loc1, loc2 FROM fm_location2";
		if ($filterLoc1)
		{
			$sql .= " WHERE loc1 = '{$filterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$this->loc2References[$this->db->f('loc1')][$this->db->f('loc2')] = true;
		}

		// Load references for fm_location3 - include loc3_name now
		$sql = "SELECT loc1, loc2, loc3, loc3_name FROM fm_location3";
		if ($filterLoc1)
		{
			$sql .= " WHERE loc1 = '{$filterLoc1}'";
		}
		$sql .= " ORDER BY loc1, loc2, loc3";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$this->loc3References[$this->db->f('loc1')][$this->db->f('loc2')][$this->db->f('loc3')] = [
				'loc3_name' => $this->db->f('loc3_name')
			];
		}
	}

	private function analyzeData()
	{
		$loc2ByBuilding = [];
		$existingLoc2 = [];
		$loc2Assignments = [];

		// Group data by loc1 and bygningsnr for loc2 validation
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$bygningsnr = $entry['bygningsnr'];

			if (!isset($loc2ByBuilding[$loc1][$bygningsnr]))
			{
				$loc2ByBuilding[$loc1][$bygningsnr] = $loc2;
			}

			// Track existing loc2 values
			if (!isset($existingLoc2[$loc1]))
			{
				$existingLoc2[$loc1] = [];
			}
			$existingLoc2[$loc1][$loc2] = true;
		}

		// Assign loc2 values incrementally for missing entries
		foreach ($loc2ByBuilding as $loc1 => $buildings)
		{
			$nextLoc2 = max(array_map('intval', array_keys($existingLoc2[$loc1]))) + 1;

			foreach ($buildings as $bygningsnr => $loc2)
			{
				if (!isset($existingLoc2[$loc1][$loc2]))
				{
					$loc2Assignments[$loc1][$bygningsnr] = str_pad($nextLoc2, 2, '0', STR_PAD_LEFT);
					$existingLoc2[$loc1][$loc2Assignments[$loc1][$bygningsnr]] = true;
					$nextLoc2++;
				}
			}
		}

		// Validate loc2 assignments
		foreach ($loc2Assignments as $loc1 => $assignments)
		{
			foreach ($assignments as $bygningsnr => $newLoc2)
			{
				$this->issues[] = [
					'type' => 'missing_loc2_assignment',
					'loc1' => $loc1,
					'bygningsnr' => $bygningsnr,
					'suggested_loc2' => $newLoc2
				];
				$this->suggestions[] = "Assign loc2='{$newLoc2}' for loc1='{$loc1}' and bygningsnr='{$bygningsnr}'";
			}
		}

		// Count unique bygningsnr per loc1
		$uniqueBygningsnrPerLoc1 = [];
		$loc2ToBuildings = [];

		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$bygningsnr = $entry['bygningsnr'];

			if (!isset($uniqueBygningsnrPerLoc1[$loc1]))
			{
				$uniqueBygningsnrPerLoc1[$loc1] = [];
			}
			$uniqueBygningsnrPerLoc1[$loc1][$bygningsnr] = true;

			if (!isset($loc2ToBuildings[$loc1][$loc2]))
			{
				$loc2ToBuildings[$loc1][$loc2] = [];
			}
			$loc2ToBuildings[$loc1][$loc2][$bygningsnr] = true;
		}

		// Check if each loc2 has exactly one bygningsnr
		foreach ($loc2ToBuildings as $loc1 => $loc2Data)
		{
			foreach ($loc2Data as $loc2 => $buildings)
			{
				if (count($buildings) > 1)
				{
					$this->issues[] = [
						'type' => 'multiple_buildings_in_loc2',
						'loc1' => $loc1,
						'loc2' => $loc2,
						'building_count' => count($buildings),
						'buildings' => implode(', ', array_keys($buildings))
					];

					// Suggest new loc2 assignments
					$usedLoc2 = isset($existingLoc2[$loc1]) ? array_keys($existingLoc2[$loc1]) : ['01'];
					$nextLoc2 = (int)max($usedLoc2) + 1;

					$i = 0;
					foreach ($buildings as $bygningsnr => $true)
					{
						// Keep the first building with the original loc2
						if ($i++ === 0) continue;

						$newLoc2 = str_pad($nextLoc2++, 2, '0', STR_PAD_LEFT);
						$existingLoc2[$loc1][$newLoc2] = true;
						$this->suggestions[] = "Assign loc2='{$newLoc2}' for loc1='{$loc1}' and bygningsnr='{$bygningsnr}' (currently in loc2='{$loc2}')";
					}
				}
			}
		}

		// Check total loc2 count vs unique bygningsnr count
		foreach ($uniqueBygningsnrPerLoc1 as $loc1 => $buildings)
		{
			$uniqueBygningsnrCount = count($buildings);
			$uniqueLoc2Count = isset($existingLoc2[$loc1]) ? count($existingLoc2[$loc1]) : 0;

			if ($uniqueLoc2Count < $uniqueBygningsnrCount)
			{
				$this->issues[] = [
					'type' => 'insufficient_loc2',
					'loc1' => $loc1,
					'bygningsnr_count' => $uniqueBygningsnrCount,
					'loc2_count' => $uniqueLoc2Count,
					'missing_count' => $uniqueBygningsnrCount - $uniqueLoc2Count
				];
			}
		}

		// Validate loc3 assignments - each unique street_id/street_number within a loc2 should have a unique loc3
		$streetsByLoc1Loc2 = [];
		$existingLoc3 = [];
		$loc3Assignments = [];

		// Group data by loc1, loc2, and street_id/street_number for loc3 validation
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$streetId = $entry['street_id'];
			$streetNumber = $entry['street_number'];

			$streetKey = "{$streetId}_{$streetNumber}";

			if (!isset($streetsByLoc1Loc2[$loc1][$loc2]))
			{
				$streetsByLoc1Loc2[$loc1][$loc2] = [];
				$existingLoc3[$loc1][$loc2] = [];
			}

			if (!isset($streetsByLoc1Loc2[$loc1][$loc2][$streetKey]))
			{
				$streetsByLoc1Loc2[$loc1][$loc2][$streetKey] = $loc3;
			}
			elseif ($streetsByLoc1Loc2[$loc1][$loc2][$streetKey] !== $loc3)
			{
				$this->issues[] = [
					'type' => 'conflicting_loc3',
					'loc1' => $loc1,
					'loc2' => $loc2,
					'street_id' => $streetId,
					'street_number' => $streetNumber,
					'loc3' => $loc3,
					'expected_loc3' => $streetsByLoc1Loc2[$loc1][$loc2][$streetKey]
				];
			}

			$existingLoc3[$loc1][$loc2][$loc3] = true;
		}

		// Check for proper sequential loc3 assignments starting from '01'
		foreach ($streetsByLoc1Loc2 as $loc1 => $loc2Data)
		{
			foreach ($loc2Data as $loc2 => $streets)
			{
				// Verify that loc3 values start from '01' and are sequential
				$expectedLoc3 = 1;
				$loc3Values = array_values($streets);
				sort($loc3Values);

				foreach ($loc3Values as $loc3)
				{
					$expectedLoc3Str = str_pad($expectedLoc3, 2, '0', STR_PAD_LEFT);
					if ($loc3 !== $expectedLoc3Str)
					{
						// Found a gap or non-sequential value - add an issue
						$this->issues[] = [
							'type' => 'non_sequential_loc3',
							'loc1' => $loc1,
							'loc2' => $loc2,
							'expected_loc3' => $expectedLoc3Str,
							'actual_loc3' => $loc3
						];

						$this->suggestions[] = "Loc3 values in loc1='{$loc1}', loc2='{$loc2}' should be sequential starting from '01', found '{$loc3}' where '{$expectedLoc3Str}' was expected";
					}
					$expectedLoc3++;
				}

				// Assign loc3 values incrementally for missing entries
				$nextLoc3 = count($streets) + 1;

				// Check if the count of unique streets matches the highest loc3 value
				$highestLoc3 = count($streets);
				$uniqueStreetCount = count($streets);

				if ($highestLoc3 < $uniqueStreetCount)
				{
					$this->issues[] = [
						'type' => 'insufficient_loc3',
						'loc1' => $loc1,
						'loc2' => $loc2,
						'street_count' => $uniqueStreetCount,
						'loc3_count' => $highestLoc3,
						'missing_count' => $uniqueStreetCount - $highestLoc3
					];
				}
			}
		}

		// Check for multiple unique street addresses in a single loc2 that should be separated
		$uniqueStreetAddressesPerLoc2 = [];
		$streetNumbersByLoc3 = [];
		$newLoc2Assignments = [];

		// First, map street numbers to loc3 values to check if structure is already correct
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$streetNumber = $entry['street_number'];

			if (!isset($streetNumbersByLoc3[$loc1][$loc2][$loc3]))
			{
				$streetNumbersByLoc3[$loc1][$loc2][$loc3] = [];
			}

			if (!in_array($streetNumber, $streetNumbersByLoc3[$loc1][$loc2][$loc3]))
			{
				$streetNumbersByLoc3[$loc1][$loc2][$loc3][] = $streetNumber;
			}
		}

		// Group street addresses by loc1/loc2 combination
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$streetId = $entry['street_id'];
			$streetNumber = $entry['street_number'];
			$bygningsnr = $entry['bygningsnr'];

			$streetKey = "{$streetId}_{$streetNumber}";

			if (!isset($uniqueStreetAddressesPerLoc2[$loc1][$loc2]))
			{
				$uniqueStreetAddressesPerLoc2[$loc1][$loc2] = [];
			}

			if (!isset($uniqueStreetAddressesPerLoc2[$loc1][$loc2][$streetKey]))
			{
				$uniqueStreetAddressesPerLoc2[$loc1][$loc2][$streetKey] = [
					'street_id' => $streetId,
					'street_number' => $streetNumber,
					'loc3' => $loc3,
					'bygningsnr' => $bygningsnr,
					'entries' => []
				];
			}

			$uniqueStreetAddressesPerLoc2[$loc1][$loc2][$streetKey]['entries'][] = $entry;
		}

		// Check each loc2 for appropriate organization
		foreach ($uniqueStreetAddressesPerLoc2 as $loc1 => $loc2Data)
		{
			foreach ($loc2Data as $loc2 => $streets)
			{
				// Count unique street numbers
				$uniqueStreetNumbers = [];
				foreach ($streets as $streetKey => $streetData)
				{
					if (!in_array($streetData['street_number'], $uniqueStreetNumbers))
					{
						$uniqueStreetNumbers[] = $streetData['street_number'];
					}
				}

				// Check if structure is already correct - each unique street number has its own loc3
				$isStructureCorrect = true;
				$loc3Count = isset($streetNumbersByLoc3[$loc1][$loc2]) ? count($streetNumbersByLoc3[$loc1][$loc2]) : 0;

				// If we have the same number of unique loc3 values as unique street numbers,
				// and each loc3 has only one unique street number, the structure is correct
				if ($loc3Count == count($uniqueStreetNumbers))
				{
					foreach ($streetNumbersByLoc3[$loc1][$loc2] as $loc3 => $streetNumbers)
					{
						if (count($streetNumbers) > 1)
						{
							$isStructureCorrect = false;
							break;
						}
					}
				}
				else
				{
					$isStructureCorrect = false;
				}

				// If the structure is already correct, don't suggest changes
				if ($isStructureCorrect)
				{
					continue;
				}

				// If structure needs fixing, proceed with the existing logic
				$numStreets = count($streets);
				if ($numStreets > 1)
				{
					// Check for inconsistent street number to loc3 mappings within the same loc1-loc2
					$this->analyzeStreetNumberToLoc3Consistency($uniqueStreetAddressesPerLoc2);
				}
			}
		}
	}

	/**
	 * Analyzes consistency of street number to loc3 mappings within the same building
	 * Identifies cases where the same street number should have the same loc3 value
	 * 
	 * @param array $uniqueStreetAddressesPerLoc2 Array of street addresses grouped by loc1/loc2
	 */
	private function analyzeStreetNumberToLoc3Consistency($uniqueStreetAddressesPerLoc2)
	{
		// Map each street number to its standard loc3 value for the building
		$streetNumberToStandardLoc3 = [];

		// First pass - establish the standard loc3 for each street number by finding most common usage
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$streetNumber = $entry['street_number'];

			if (!isset($streetNumberToStandardLoc3[$loc1][$streetNumber]))
			{
				$streetNumberToStandardLoc3[$loc1][$streetNumber] = [
					'loc3_values' => [],
					'standard_loc3' => null
				];
			}

			if (!isset($streetNumberToStandardLoc3[$loc1][$streetNumber]['loc3_values'][$loc3]))
			{
				$streetNumberToStandardLoc3[$loc1][$streetNumber]['loc3_values'][$loc3] = 0;
			}

			$streetNumberToStandardLoc3[$loc1][$streetNumber]['loc3_values'][$loc3]++;
		}

		// Determine the standard loc3 for each street number (the most frequently used one)
		foreach ($streetNumberToStandardLoc3 as $loc1 => &$streetNumbers)
		{
			foreach ($streetNumbers as $streetNumber => &$data)
			{
				arsort($data['loc3_values']); // Sort by frequency, most common first
				$data['standard_loc3'] = key($data['loc3_values']);
			}
		}

		// Second pass - find and flag inconsistencies
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$loc4 = $entry['loc4'];
			$streetNumber = $entry['street_number'];
			$streetId = $entry['street_id'];
			$bygningsnr = $entry['bygningsnr'];

			$standardLoc3 = $streetNumberToStandardLoc3[$loc1][$streetNumber]['standard_loc3'];

			if ($loc3 !== $standardLoc3)
			{
				$this->issues[] = [
					'type' => 'inconsistent_street_number_loc3',
					'loc1' => $loc1,
					'loc2' => $loc2,
					'loc3' => $loc3,
					'loc4' => $loc4,
					'street_id' => $streetId,
					'street_number' => $streetNumber,
					'bygningsnr' => $bygningsnr,
					'correct_loc3' => $standardLoc3
				];

				$this->suggestions[] = "Location code '{$loc1}-{$loc2}-{$loc3}-{$loc4}' with street number '{$streetNumber}' should use loc3='{$standardLoc3}' instead of '{$loc3}'";
			}
		}
	}

	/**
	 * Generate SQL statements for the example dataset with inconsistent loc3 values
	 * 
	 * @return array SQL statements for corrections
	 */
	public function generateCorrectionsInconsistent_street_number_loc3()
	{
		$sqlLoc4 = [];
		$sqlCorrections = [];

		// Process issues of type 'inconsistent_street_number_loc3'
		$processedLocationCodes = [];

		foreach ($this->issues as $issue)
		{
			if ($issue['type'] === 'inconsistent_street_number_loc3')
			{
				$loc1 = $issue['loc1'];
				$loc2 = $issue['loc2'];
				$oldLoc3 = $issue['loc3'];
				$loc4 = $issue['loc4'];
				$newLoc3 = $issue['correct_loc3'];
				$streetId = $issue['street_id'];
				$streetNumber = $issue['street_number'];
				$bygningsnr = $issue['bygningsnr'];

				$oldLocationCode = "{$loc1}-{$loc2}-{$oldLoc3}-{$loc4}";
				$newLocationCode = "{$loc1}-{$loc2}-{$newLoc3}-{$loc4}";

				// Skip if already processed
				if (isset($processedLocationCodes[$oldLocationCode])) continue;
				$processedLocationCodes[$oldLocationCode] = true;

				// Generate SQL for fm_location4 update
				$sqlLoc4[] = "-- Update fm_location4 entry: {$oldLocationCode} -> {$newLocationCode}";
				$sqlLoc4[] = "UPDATE fm_location4 
							SET location_code = '{$newLocationCode}', 
								loc3 = '{$newLoc3}' 
							WHERE location_code = '{$oldLocationCode}';";

				// Generate SQL for location_mapping
				$sqlCorrections[] = "INSERT INTO location_mapping (
									old_location_code, new_location_code, loc1, 
									old_loc2, new_loc2, old_loc3, new_loc3, 
									loc4, bygningsnr, street_id, street_number, change_type
								) VALUES (
									'{$oldLocationCode}', '{$newLocationCode}', '{$loc1}', 
									'{$loc2}', '{$loc2}', '{$oldLoc3}', '{$newLoc3}', 
									'{$loc4}', {$bygningsnr}, {$streetId}, '{$streetNumber}', 
									'location_hierarchy_update'
								);";
			}
		}

		return [
			'location4_updates' => $sqlLoc4,
			'corrections' => $sqlCorrections
		];
	}

	/**
	 * Generate SQL statements for fixing non-sequential loc3 values
	 * 
	 * @return array SQL statements for corrections
	 */
	public function generateCorrectionsNonSequentialLoc3()
	{
		$sqlLoc4 = [];
		$sqlCorrections = [];
		$sqlLoc3 = []; // Add array for fm_location3 inserts
		$processedLocationCodes = [];
		$processedLoc3 = []; // Track processed loc3 entries
		
		// Group all entries by loc1 and loc2
		$entriesByLoc1Loc2 = [];
		foreach ($this->locationData as $entry) {
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			
			if (!isset($entriesByLoc1Loc2[$loc1][$loc2])) {
				$entriesByLoc1Loc2[$loc1][$loc2] = [];
			}
			
			$entriesByLoc1Loc2[$loc1][$loc2][] = $entry;
		}
		
		// Find all loc1/loc2 combinations with non-sequential loc3 issues
		$loc1Loc2WithIssues = [];
		foreach ($this->issues as $issue) {
			if ($issue['type'] === 'non_sequential_loc3') {
				$loc1 = $issue['loc1'];
				$loc2 = $issue['loc2'];
				$loc1Loc2WithIssues["{$loc1}-{$loc2}"] = [
					'loc1' => $loc1,
					'loc2' => $loc2
				];
			}
		}
		
		// Process each loc1/loc2 combination with issues
		foreach ($loc1Loc2WithIssues as $key => $location) {
			$loc1 = $location['loc1'];
			$loc2 = $location['loc2'];
			
			if (!isset($entriesByLoc1Loc2[$loc1][$loc2])) {
				continue; // Skip if no entries found
			}
			
			 // First, group entries by street address (street_id + street_number)
			$entriesByStreetAddress = [];
			foreach ($entriesByLoc1Loc2[$loc1][$loc2] as $entry) {
				$streetKey = "{$entry['street_id']}_{$entry['street_number']}";
				if (!isset($entriesByStreetAddress[$streetKey])) {
					$entriesByStreetAddress[$streetKey] = [
						'street_id' => $entry['street_id'],
						'street_number' => $entry['street_number'],
						'entries' => []
					];
				}
				$entriesByStreetAddress[$streetKey]['entries'][] = $entry;
			}
			
			// Sort street keys for consistent ordering
			ksort($entriesByStreetAddress);
			
			// Now assign sequential loc3 values to each unique street address
			$newLoc3Index = 1;
			$streetToLoc3Map = []; // Map each street address to its new loc3 value
			
			foreach ($entriesByStreetAddress as $streetKey => $streetData) {
				$newLoc3 = str_pad($newLoc3Index++, 2, '0', STR_PAD_LEFT);
				$streetToLoc3Map[$streetKey] = $newLoc3;
				
				// Get street details for the loc3 name
				$streetId = $streetData['street_id'];
				$streetNumber = $streetData['street_number'];
				$streetName = $this->get_street_name($streetId);
				$fullAddress = "{$streetName} {$streetNumber}";
				$newLocationCode = "{$loc1}-{$loc2}-{$newLoc3}";
				
				// Skip if already processed
				if (isset($processedLoc3[$newLocationCode])) continue;
				$processedLoc3[$newLocationCode] = true;
				
				 // Check if this loc3 already exists in the database
				$loc3Exists = isset($this->loc3References[$loc1][$loc2][$newLoc3]);
				
				// Only create new loc3 entries if they don't already exist
				if (!$loc3Exists) {
					$sqlLoc3[] = "-- Create new fm_location3 entry: {$newLocationCode} for {$fullAddress}";
					$sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
								VALUES ('{$newLocationCode}', '{$loc1}', '{$loc2}', '{$newLoc3}', '{$fullAddress}')
								ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
				}
			 }
			
			// Now update each loc4 entry to use the new loc3 associated with its street address
			foreach ($entriesByLoc1Loc2[$loc1][$loc2] as $entry) {
				$oldLoc3 = $entry['loc3'];
				$loc4 = $entry['loc4'];
				 $streetId = $entry['street_id'];
				$streetNumber = $entry['street_number'];
				$bygningsnr = $entry['bygningsnr'];
				
				$streetKey = "{$streetId}_{$streetNumber}";
				
				// Skip if we don't have a mapping for this street address
				if (!isset($streetToLoc3Map[$streetKey])) {
					continue;
				}
				
				$newLoc3 = $streetToLoc3Map[$streetKey];
				$oldLocationCode = "{$loc1}-{$loc2}-{$oldLoc3}-{$loc4}";
				$newLocationCode = "{$loc1}-{$loc2}-{$newLoc3}-{$loc4}";
				
				// Skip if the location code doesn't change or already processed
				if ($oldLocationCode === $newLocationCode || isset($processedLocationCodes[$oldLocationCode])) {
					continue;
				}
				
				$processedLocationCodes[$oldLocationCode] = true;
				
				// Generate SQL for fm_location4 update
				$sqlLoc4[] = "-- Update fm_location4 entry: {$oldLocationCode} -> {$newLocationCode}";
				$sqlLoc4[] = "UPDATE fm_location4 
							SET location_code = '{$newLocationCode}', 
								loc3 = '{$newLoc3}' 
							WHERE location_code = '{$oldLocationCode}';";
				
				// Generate SQL for location_mapping
				$sqlCorrections[] = "INSERT INTO location_mapping (
									old_location_code, new_location_code, loc1, 
									old_loc2, new_loc2, old_loc3, new_loc3, 
									loc4, bygningsnr, street_id, street_number, change_type
								) VALUES (
									'{$oldLocationCode}', '{$newLocationCode}', '{$loc1}', 
									'{$loc2}', '{$loc2}', '{$oldLoc3}', '{$newLoc3}', 
									'{$loc4}', {$bygningsnr}, {$streetId}, '{$streetNumber}', 
									'location_hierarchy_update'
								);";
			}
		}
		
		return [
			'location4_updates' => $sqlLoc4,
			'corrections' => $sqlCorrections,
			'location3_updates' => $sqlLoc3
		];
	}

	/**
	 * Generate SQL statements for fixing insufficient loc3 values
	 * 
	 * @return array SQL statements for creating missing loc3 entries
	 */
	public function generateCorrectionsInsufficientLoc3()
	{
		$sqlLoc3 = [];
		
		// Group all entries by loc1 and loc2
		$streetsByLoc1Loc2 = [];
		foreach ($this->locationData as $entry) {
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$streetId = $entry['street_id'];
			$streetNumber = $entry['street_number'];
			
			$streetKey = "{$streetId}_{$streetNumber}";
			
			if (!isset($streetsByLoc1Loc2[$loc1][$loc2])) {
				$streetsByLoc1Loc2[$loc1][$loc2] = [];
			}
			
			if (!isset($streetsByLoc1Loc2[$loc1][$loc2][$streetKey])) {
				$streetsByLoc1Loc2[$loc1][$loc2][$streetKey] = [
					'street_id' => $streetId,
					'street_number' => $streetNumber
				];
			}
		}
		
		// Find all loc1/loc2 combinations with insufficient_loc3 issues
		foreach ($this->issues as $issue) {
			if ($issue['type'] === 'insufficient_loc3') {
				$loc1 = $issue['loc1'];
				$loc2 = $issue['loc2'];
				
				if (!isset($streetsByLoc1Loc2[$loc1][$loc2])) {
					continue; // Skip if no entries found
				}
				
				// Find existing loc3 values to avoid duplicates
				$existingLoc3Values = [];
				foreach ($this->loc3References[$loc1][$loc2] ?? [] as $loc3 => $data) {
					$existingLoc3Values[$loc3] = true;
				}
				
				// Generate sequential loc3 values starting from max existing + 1
				$maxLoc3 = 0;
				if (!empty($existingLoc3Values)) {
					$maxLoc3 = max(array_map('intval', array_keys($existingLoc3Values)));
				}
				
				$nextLoc3 = $maxLoc3 + 1;
				
				// Sort street keys to ensure consistent ordering
				ksort($streetsByLoc1Loc2[$loc1][$loc2]);
				
				// Create missing loc3 entries for each unique street
				foreach ($streetsByLoc1Loc2[$loc1][$loc2] as $streetKey => $streetData) {
					// Check if we already have a loc3 that references this street
					$hasLoc3ForStreet = false;
					foreach ($this->locationData as $entry) {
						if ($entry['loc1'] === $loc1 && 
							$entry['loc2'] === $loc2 && 
							$entry['street_id'] === $streetData['street_id'] && 
							$entry['street_number'] === $streetData['street_number']) {
							$hasLoc3ForStreet = true;
							break;
						}
					}
					
					// Skip if already has a loc3
					if ($hasLoc3ForStreet) {
						continue;
					}
					
					$newLoc3 = str_pad($nextLoc3++, 2, '0', STR_PAD_LEFT);
					$streetId = $streetData['street_id'];
					$streetNumber = $streetData['street_number'];
					$streetName = $this->get_street_name($streetId);
					$fullAddress = "{$streetName} {$streetNumber}";
					$locationCode = "{$loc1}-{$loc2}-{$newLoc3}";
					
					// Generate SQL for fm_location3 insert
					$sqlLoc3[] = "-- Create missing loc3 entry for {$loc1}-{$loc2}, street: {$fullAddress}";
					$sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
								VALUES ('{$locationCode}', '{$loc1}', '{$loc2}', '{$newLoc3}', '{$fullAddress}')
								ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
				}
			}
		}
		
		return [
			'missing_loc3' => $sqlLoc3
		];
	}

	private function generateStatistics()
	{
		$statistics = [
			'level1_count' => count(array_unique(array_column($this->locationData, 'loc1'))),
			'level2_count' => count(array_unique(array_map(fn($entry) => "{$entry['loc1']}-{$entry['loc2']}", $this->locationData))),
			'level3_count' => count(array_unique(array_map(fn($entry) => "{$entry['loc1']}-{$entry['loc2']}-{$entry['loc3']}", $this->locationData))),
			'level4_count' => count($this->locationData),
			'unique_buildings' => count(array_unique(array_column($this->locationData, 'bygningsnr'))),
			'unique_addresses' => count(array_unique(array_map(fn($entry) => "{$entry['street_id']}-{$entry['street_number']}", $this->locationData))),
		];

		return $statistics;
	}

	private function generateSQLStatements($returnAsArray = false)
	{
		$sqlLoc2 = [];
		$sqlLoc3 = [];
		$sqlLoc4 = [];
		$sqlCorrections = [];
		$sqlSchema = [];

		// Track processed location codes to prevent duplicates
		$processedLocationCodes = [];

		// Track created loc2 entries to ensure they have corresponding loc3 entries
		$createdLoc2Entries = [];

		// Track location assignments - which building goes to which loc2, which street goes to which loc3
		$buildingToLoc2Map = [];
		$streetToLoc3Map = [];

		// First, build a mapping of buildings to their street addresses
		$buildingToStreetMap = [];
		foreach ($this->locationData as $entry)
		{
			$bygningsnr = $entry['bygningsnr'];
			if (!isset($buildingToStreetMap[$bygningsnr]))
			{
				$buildingToStreetMap[$bygningsnr] = [];
			}

			$streetKey = "{$entry['street_id']}_{$entry['street_number']}";
			if (!isset($buildingToStreetMap[$bygningsnr][$streetKey]))
			{
				$buildingToStreetMap[$bygningsnr][$streetKey] = [
					'street_id' => $entry['street_id'],
					'street_number' => $entry['street_number']
				];
			}
		}

		// Add table creation statement
		$sqlSchema[] = "CREATE TABLE IF NOT EXISTS location_mapping (
			id SERIAL PRIMARY KEY,
			old_location_code VARCHAR(50),
			new_location_code VARCHAR(50),
			loc1 VARCHAR(6),
			old_loc2 VARCHAR(2),
			new_loc2 VARCHAR(2),
			old_loc3 VARCHAR(2),
			new_loc3 VARCHAR(2),
			loc4 VARCHAR(3),
			bygningsnr INTEGER,
			street_id INTEGER,
			street_number VARCHAR(10),
			change_type VARCHAR(20),
			update_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		)";

		// First pass: Analyze and prepare all loc2 and loc3 assignments
		// This is needed to ensure consistent numbering
		foreach ($this->issues as $issue)
		{
			if ($issue['type'] === 'missing_loc2' || $issue['type'] === 'missing_loc2_assignment')
			{
				$loc1 = isset($issue['loc1']) ? $issue['loc1'] : null;
				$loc2 = isset($issue['loc2']) ? $issue['loc2'] : $issue['suggested_loc2'];
				$bygningsnr = isset($issue['bygningsnr']) ? $issue['bygningsnr'] : null;

				if ($loc1 && $loc2 && $bygningsnr)
				{
					// Track building to loc2 assignment
					$buildingToLoc2Map[$loc1][$bygningsnr] = $loc2;
					$createdLoc2Entries[$loc1][$loc2] = $bygningsnr;
				}
			}
			else if ($issue['type'] === 'multiple_buildings_in_loc2')
			{
				// For these, we need to lookup the suggested new loc2 values from suggestions
				$loc1 = $issue['loc1'];
				$loc2 = $issue['loc2'];
				$buildings = explode(', ', $issue['buildings']);

				// Keep the first building with original loc2
				$buildingToLoc2Map[$loc1][$buildings[0]] = $loc2;

				// For other buildings, find their new loc2 from suggestions and create new entries
				for ($i = 1; $i < count($buildings); $i++)
				{
					$bygningsnr = $buildings[$i];
					$newLoc2 = $this->findSuggestedLoc2($loc1, $bygningsnr);

					if ($newLoc2)
					{
						$buildingToLoc2Map[$loc1][$bygningsnr] = $newLoc2;
						$createdLoc2Entries[$loc1][$newLoc2] = $bygningsnr;
					}
				}
			}
		}

		// For each building, assign proper loc3 values to its street addresses
		foreach ($createdLoc2Entries as $loc1 => $loc2Array)
		{
			foreach ($loc2Array as $loc2 => $bygningsnr)
			{
				if (isset($buildingToStreetMap[$bygningsnr]))
				{
					$streetAddresses = $buildingToStreetMap[$bygningsnr];

					// Assign sequential loc3 values to streets
					$loc3Index = 1;
					foreach ($streetAddresses as $streetKey => $streetData)
					{
						$loc3 = str_pad($loc3Index++, 2, '0', STR_PAD_LEFT);
						$streetToLoc3Map[$loc1][$loc2][$streetKey] = $loc3;
					}
				}
				else
				{
					// No streets found - still create a default loc3
					$streetToLoc3Map[$loc1][$loc2]['default'] = '01';
				}
			}
		}

		// Generate SQL for loc2 entries
		foreach ($buildingToLoc2Map as $loc1 => $buildings)
		{
			foreach ($buildings as $bygningsnr => $loc2)
			{
				// Only create if it's a new assignment
				if (isset($createdLoc2Entries[$loc1][$loc2]) && $createdLoc2Entries[$loc1][$loc2] == $bygningsnr)
				{
					$sqlLoc2[] = "INSERT INTO fm_location2 (loc1, loc2, bygningsnr) 
								VALUES ('{$loc1}', '{$loc2}', '{$bygningsnr}')
								ON CONFLICT (loc1, loc2) DO NOTHING;";
				}
			}
		}

		// Generate SQL for loc3 entries
		foreach ($streetToLoc3Map as $loc1 => $loc2Data)
		{
			foreach ($loc2Data as $loc2 => $streets)
			{
				foreach ($streets as $streetKey => $loc3)
				{
					if ($streetKey === 'default')
					{
						$locationCode = "{$loc1}-{$loc2}-{$loc3}";
						$sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
									VALUES ('{$locationCode}', '{$loc1}', '{$loc2}', '{$loc3}', 'Default entrance')
									ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
					}
					else
					{
						list($streetId, $streetNumber) = explode('_', $streetKey);
						$streetName = $this->get_street_name($streetId);
						$fullAddress = "{$streetName} {$streetNumber}";
						$locationCode = "{$loc1}-{$loc2}-{$loc3}";

						$sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
									VALUES ('{$locationCode}', '{$loc1}', '{$loc2}', '{$loc3}', '{$fullAddress}')
									ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
					}
				}
			}
		}

		// Now, generate SQL for updating fm_location4 entries
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$oldLoc2 = $entry['loc2'];
			$oldLoc3 = $entry['loc3'];
			$loc4 = $entry['loc4'];
			$bygningsnr = $entry['bygningsnr'];
			$streetId = $entry['street_id'];
			$streetNumber = $entry['street_number'];

			// Determine the new loc2 and loc3 values
			$newLoc2 = isset($buildingToLoc2Map[$loc1][$bygningsnr]) ?
				$buildingToLoc2Map[$loc1][$bygningsnr] : $oldLoc2;

			$streetKey = "{$streetId}_{$streetNumber}";
			$newLoc3 = isset($streetToLoc3Map[$loc1][$newLoc2][$streetKey]) ?
				$streetToLoc3Map[$loc1][$newLoc2][$streetKey] : (isset($streetToLoc3Map[$loc1][$newLoc2]['default']) ?
					$streetToLoc3Map[$loc1][$newLoc2]['default'] : $oldLoc3);

			$oldLocationCode = "{$loc1}-{$oldLoc2}-{$oldLoc3}-{$loc4}";
			$newLocationCode = "{$loc1}-{$newLoc2}-{$newLoc3}-{$loc4}";

			// Only generate update if location changed
			if ($oldLocationCode !== $newLocationCode)
			{
				// Skip if already processed
				if (isset($processedLocationCodes[$oldLocationCode]))
				{
					continue;
				}
				$processedLocationCodes[$oldLocationCode] = true;

				$sqlLoc4[] = "-- Update fm_location4 entry: {$oldLocationCode} -> {$newLocationCode}";
				$sqlLoc4[] = "UPDATE fm_location4 
							SET location_code = '{$newLocationCode}', 
								loc2 = '{$newLoc2}', 
								loc3 = '{$newLoc3}' 
							WHERE location_code = '{$oldLocationCode}';";

				$sqlCorrections[] = "INSERT INTO location_mapping (
									old_location_code, new_location_code, loc1, 
									old_loc2, new_loc2, old_loc3, new_loc3, 
									loc4, bygningsnr, street_id, street_number, change_type
								) VALUES (
									'{$oldLocationCode}', '{$newLocationCode}', '{$loc1}', 
									'{$oldLoc2}', '{$newLoc2}', '{$oldLoc3}', '{$newLoc3}', 
									'{$loc4}', {$bygningsnr}, {$streetId}, '{$streetNumber}', 
									'location_hierarchy_update'
								);";
			}
		}

		// Handle existing issues (conflicting_loc3, insufficient_loc3, etc.)
		$inconsistent_street_number_loc3 = $this->generateCorrectionsInconsistent_street_number_loc3();
		$non_sequential_loc3 = $this->generateCorrectionsNonSequentialLoc3();
		$insufficient_loc3 = $this->generateCorrectionsInsufficientLoc3();

		if ($returnAsArray)
		{
			return [
				'schema' => $sqlSchema,
				'missing_loc2' => $sqlLoc2,
				'missing_loc3' => array_merge(
					$sqlLoc3,
					$insufficient_loc3['missing_loc3'],
					$non_sequential_loc3['location3_updates'] ?? [] // Include loc3 updates for non-sequential issues
				),
				'corrections' => array_merge($sqlCorrections, 
					$inconsistent_street_number_loc3['corrections'],
					$non_sequential_loc3['corrections']),
				'location4_updates' => array_merge($sqlLoc4, 
					$inconsistent_street_number_loc3['location4_updates'],
					$non_sequential_loc3['location4_updates']),
			];
		}
	}

	/**
	 * Helper function to find suggested loc2 for a given loc1 and bygningsnr
	 */
	private function findSuggestedLoc2($loc1, $bygningsnr)
	{
		foreach ($this->suggestions as $suggestion)
		{
			if (
				strpos($suggestion, "Assign loc2='") === 0 &&
				strpos($suggestion, "for loc1='{$loc1}'") !== false &&
				strpos($suggestion, "bygningsnr='{$bygningsnr}'") !== false
			)
			{

				preg_match("/Assign loc2='(\d+)'/", $suggestion, $matches);
				if (isset($matches[1]))
				{
					return $matches[1];
				}
			}
		}
		return false;
	}

	/**
	 * Helper function to get street name from street_id
	 */
	private function get_street_name($street_id)
	{
		static $street_names = [];
		if (isset($street_names[$street_id]))
		{
			return $street_names[$street_id];
		}
		// Fetch street name from database
		$sql = "SELECT descr FROM fm_streetaddress WHERE id = {$street_id}";
		$this->db->query($sql, __LINE__, __FILE__);
		if ($this->db->next_record())
		{
			$street_names[$street_id] = $this->db->f('descr');
			return $street_names[$street_id];
		}
		return 'Unknown Street';
	}
}
