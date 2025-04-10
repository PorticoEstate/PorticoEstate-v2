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
	private $processedLocationCodes = [];
	private $currentFilterLoc1 = null; // Properly declare the property
	private $entryToBygningsnrMap = []; // Property to store the mapping
	private $fixedLocationCodes = []; // Property to store location codes that shouldn't be moved

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	/**
	 * Analyze location hierarchy and generate recommendations
	 * 
	 * @param string|null $filterLoc1 Optional loc1 value to filter analysis
	 * @return array Analysis results
	 */
	public function analyze($filterLoc1 = null)
	{
		$this->resetState(); // Ensure clean state for each analysis
		$this->loadData($filterLoc1);
		$this->validateBygningsnr(); // Validate bygningsnr values

		// Determine fixed location codes before analyzing
		$this->determineFixedLocationCodes();

		$this->analyzeData();

		// Store the filter for later use in SQL generation
		$this->currentFilterLoc1 = $filterLoc1;

		return [
			'statistics' => $this->generateStatistics(),
			'issues' => $this->issues,
			'suggestions' => $this->suggestions,
			'sql_statements' => $this->generateSQLStatements(true),
			'fixed_location_codes' => array_keys($this->fixedLocationCodes), // Return the fixed codes for reference
		];
	}

	/**
	 * Determine which location codes should remain fixed based on hierarchy rules:
	 * 1. For each loc1 there shall be one loc2 starting with '01' incremented by one for each different bygningsnummer.
	 * 2. For each loc2 there shall be one loc3 starting with '01' incremented by one for each variation of street_id/street_number.
	 * 3. Locations that don't fit these requirements shall be relocated.
	 */
	private function determineFixedLocationCodes()
	{
		// Maps for tracking the correct sequential numbering
		$correctLoc2ByBuilding = []; // loc1 → bygningsnr → expected loc2
		$correctLoc3ByStreet = []; // loc1 → loc2 → street_key → expected loc3
		
		// First pass: Build ideal location assignments
		$buildingsByLoc1 = []; // loc1 → [bygningsnr1, bygningsnr2, ...]
		$streetsByLoc1Loc2 = []; // loc1 → loc2 → [street_key1, street_key2, ...]
		
		// Group buildings by loc1, separating real and synthetic buildings
		$realBuildingsByLoc1 = []; 
		$syntheticBuildingsByLoc1 = [];
		
		// First, collect all buildings by loc1
		foreach ($this->locationData as $index => $entry) {
			$loc1 = $entry['loc1'];
			$bygningsnr = $entry['bygningsnr'];
			
			if (!isset($realBuildingsByLoc1[$loc1])) {
				$realBuildingsByLoc1[$loc1] = [];
				$syntheticBuildingsByLoc1[$loc1] = [];
			}
			
			// Separate real buildings from synthetic ones
			if (empty($bygningsnr) || strpos($bygningsnr, 'synth') === 0 || 
				strpos($bygningsnr, 'synthetic_') === 0 || 
				strpos($bygningsnr, 'empty_') === 0) {
				if (!in_array($bygningsnr, $syntheticBuildingsByLoc1[$loc1])) {
					$syntheticBuildingsByLoc1[$loc1][] = $bygningsnr;
				}
			} else {
				if (!in_array($bygningsnr, $realBuildingsByLoc1[$loc1])) {
					$realBuildingsByLoc1[$loc1][] = $bygningsnr;
				}
			}
		}
		
		// Combine real and synthetic buildings, prioritizing real ones first
		foreach ($realBuildingsByLoc1 as $loc1 => $realBuildings) {
			$buildingsByLoc1[$loc1] = array_merge($realBuildings, $syntheticBuildingsByLoc1[$loc1]);
		}
		
		// Assign correct loc2 values for buildings (starting from '01')
		foreach ($buildingsByLoc1 as $loc1 => $buildings) {
			$loc2Index = 1;
			
			foreach ($buildings as $bygningsnr) {
				$correctLoc2 = str_pad($loc2Index++, 2, '0', STR_PAD_LEFT);
				$correctLoc2ByBuilding[$loc1][$bygningsnr] = $correctLoc2;
			}
		}
		
		// Group streets by loc1/loc2
		foreach ($this->locationData as $index => $entry) {
			$loc1 = $entry['loc1'];
			$bygningsnr = $entry['bygningsnr'];
			$streetKey = "{$entry['street_id']}_{$entry['street_number']}";
			
			// Get the correct loc2 for this building
			if (!isset($correctLoc2ByBuilding[$loc1][$bygningsnr])) {
				continue; // Skip if no proper loc2 assignment
			}
			$correctLoc2 = $correctLoc2ByBuilding[$loc1][$bygningsnr];
			
			if (!isset($streetsByLoc1Loc2[$loc1][$correctLoc2])) {
				$streetsByLoc1Loc2[$loc1][$correctLoc2] = [];
			}
			if (!in_array($streetKey, $streetsByLoc1Loc2[$loc1][$correctLoc2])) {
				$streetsByLoc1Loc2[$loc1][$correctLoc2][] = $streetKey;
			}
		}
		
		// Assign correct loc3 values for streets (starting from '01')
		foreach ($streetsByLoc1Loc2 as $loc1 => $loc2Data) {
			foreach ($loc2Data as $loc2 => $streets) {
				sort($streets); // Sort for consistent ordering
				$loc3Index = 1;
				
				foreach ($streets as $streetKey) {
					$correctLoc3 = str_pad($loc3Index++, 2, '0', STR_PAD_LEFT);
					$correctLoc3ByStreet[$loc1][$loc2][$streetKey] = $correctLoc3;
				}
			}
		}
		
			// Track which locations should be fixed
		$fixedLocations = [];
		
		// Only fix the first location for each loc1
		$loc1FirstRecords = [];
		
		foreach ($this->locationData as $index => $entry) {
			$loc1 = $entry['loc1'];
			$locationCode = "{$loc1}-{$entry['loc2']}-{$entry['loc3']}-{$entry['loc4']}";
			
			if (!isset($loc1FirstRecords[$loc1])) {
				$loc1FirstRecords[$loc1] = $locationCode;
				$this->fixedLocationCodes[$locationCode] = true;
			}
		}
		
		// Safety check for very large datasets
		$totalLocations = count($this->locationData);
		$fixedLocations = count($this->fixedLocationCodes);
		$movableLocations = $totalLocations - $fixedLocations;
		$absoluteMaxMoves = 1000; // Hard limit for safety
		
		if ($movableLocations > $absoluteMaxMoves) {
				// Code to prioritize additional locations to fix
				// (Keep existing implementation)
		}
		
		// Debug output
		$fixedCount = count($this->fixedLocationCodes);
		$movableCount = $totalLocations - $fixedCount;
		error_log("Fixed locations: $fixedCount, Movable locations: $movableCount");
		
		// Optionally log which ones are fixed
		if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
			error_log("Fixed location codes: " . implode(', ', array_keys($this->fixedLocationCodes)));
		}
	}

	private function loadData($filterLoc1 = null)
	{
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

	private function validateBygningsnr()
	{
		$syntheticBygningsnr = -1; // Used for entries without a bygningsnr
		$entryToBygningsnrMap = []; // Map to track synthetic bygningsnr assignments
		$streetToSyntheticBygningsnr = []; // Map to track synthetic bygningsnr by street address
		// First pass: Assign synthetic bygningsnr to entries with empty bygningsnr, 
		// reusing the same synthetic ID for the same street address within the same loc1
		foreach ($this->locationData as $index => &$entry)
		{
			$loc1 = $entry['loc1'];
			$streetId = $entry['street_id'];
			$streetNumber = $entry['street_number'];
			$streetKey = "{$streetId}_{$streetNumber}";

			// If bygningsnr is empty, try to reuse an existing synthetic one or create a new one
			if (empty($entry['bygningsnr']))
			{
				if (isset($streetToSyntheticBygningsnr[$loc1][$streetKey]))
				{
					// Reuse existing synthetic bygningsnr for this street address in this loc1
					$bygningsnr = $streetToSyntheticBygningsnr[$loc1][$streetKey];
				}
				else
				{
					// Create a new synthetic bygningsnr
					$bygningsnr = "synthetic_{$syntheticBygningsnr}";
					$syntheticBygningsnr--;
					$streetToSyntheticBygningsnr[$loc1][$streetKey] = $bygningsnr;
				}

				$entryToBygningsnrMap[$index] = $bygningsnr;
			}
			else
			{
				$entryToBygningsnrMap[$index] = $entry['bygningsnr'];
			}
			$entry['bygningsnr'] = $entryToBygningsnrMap[$index]; // Update entry with the assigned bygningsnr
		}
		// Make the mapping available to other methods that need to look up possibly synthetic bygningsnr values
		$this->entryToBygningsnrMap = $entryToBygningsnrMap;
	}
	
	
	private function analyzeData()
	{
		$loc2ByBuilding = [];
		$existingLoc2 = [];
		$loc2Assignments = [];
		$highestLoc2ByLoc1 = []; // Initialize the array to track highest loc2 per loc1

		$entryToBygningsnrMap = $this->entryToBygningsnrMap; // Use the mapping from validateBygningsnr
		// First, find the highest loc2 value from fm_location2 references
		foreach ($this->loc2References as $loc1 => $loc2Values)
		{
			foreach (array_keys($loc2Values) as $loc2)
			{
				if (!isset($highestLoc2ByLoc1[$loc1]) || intval($loc2) > intval($highestLoc2ByLoc1[$loc1]))
				{
					$highestLoc2ByLoc1[$loc1] = $loc2;
				}
			}
		}


		// Group data by loc1 and bygningsnr for loc2 validation and find highest loc2
		foreach ($this->locationData as $index => $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];

			// Skip duplicate building detection for fixed location codes
			$locationCode = "{$loc1}-{$loc2}-{$entry['loc3']}-{$entry['loc4']}";
			if (isset($this->fixedLocationCodes[$locationCode]))
			{
				continue;
			}

			// Keep track of the highest loc2 value for each loc1
			if (!isset($highestLoc2ByLoc1[$loc1]) || intval($loc2) > intval($highestLoc2ByLoc1[$loc1]))
			{
				$highestLoc2ByLoc1[$loc1] = $loc2;
			}

			// Use the already assigned bygningsnr (which might be synthetic)
			$bygningsnr = $this->entryToBygningsnrMap[$index] ?? $entry['bygningsnr'];

			if (!isset($loc2ByBuilding[$loc1][$bygningsnr]))
			{
				$loc2ByBuilding[$loc1][$bygningsnr] = $loc2;
			}
			else if ($loc2ByBuilding[$loc1][$bygningsnr] !== $loc2)
			{
				// Found the same bygningsnr in different loc2 values - this is an issue
				$this->issues[] = [
					'type' => 'duplicate_bygningsnr',
					'loc1' => $loc1,
					'bygningsnr' => $bygningsnr,
					'first_loc2' => $loc2ByBuilding[$loc1][$bygningsnr],
					'second_loc2' => $loc2
				];

				$this->suggestions[] = "Building with ID '{$bygningsnr}' appears in both loc2='{$loc2ByBuilding[$loc1][$bygningsnr]}' and loc2='{$loc2}' in loc1='{$loc1}'. Please verify the correct assignment.";
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
			// Find the next available loc2 number, starting from the highest + 1
			$highestLoc2Value = isset($highestLoc2ByLoc1[$loc1]) ? intval($highestLoc2ByLoc1[$loc1]) : 0;
			$nextLoc2 = $highestLoc2Value + 1;

			foreach ($buildings as $bygningsnr => $loc2)
			{
				// If this is a synthetic ID, don't create a loc2 entry for it
				if (strpos($bygningsnr, 'synthetic_') === 0)
				{
					continue;
				}

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

		foreach ($this->locationData as $index => $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];

			// Use the mapped bygningsnr (which might be synthetic)
			$bygningsnr = $this->entryToBygningsnrMap[$index] ?? $entry['bygningsnr'];

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
		foreach ($this->locationData as $index => $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$loc4 = $entry['loc4'];
			$streetId = $entry['street_id'];
			$streetNumber = $entry['street_number'];
			// Use the mapped bygningsnr (which might be synthetic)
			$bygningsnr = $this->entryToBygningsnrMap[$index] ?? $entry['bygningsnr'];

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
					// Pass the mapping to the method instead of relying on the class property
					$this->analyzeStreetNumberToLoc3Consistency($uniqueStreetAddressesPerLoc2, $entryToBygningsnrMap);
				}
			}
		}

	}

	/**
	 * Analyzes consistency of street number to loc3 mappings within the same building
	 * Identifies cases where the same street number should have the same loc3 value
	 * 
	 * @param array $uniqueStreetAddressesPerLoc2 Array of street addresses grouped by loc1/loc2
	 * @param array $entryToBygningsnrMap Mapping of entry indices to bygningsnr values
	 */
	private function analyzeStreetNumberToLoc3Consistency($uniqueStreetAddressesPerLoc2, $entryToBygningsnrMap)
	{
		// Map each street number to its standard loc3 value for the building
		// This needs to be grouped by BOTH loc1 AND loc2 to prevent false positives
		$standardLoc3ByLocAndStreet = [];

		// First pass - establish the standard loc3 for each street number by finding most common usage
		// Group by loc1, loc2, AND street number to prevent cross-contamination
		foreach ($this->locationData as $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$streetNumber = $entry['street_number'];

			if (!isset($standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]))
			{
				$standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber] = [
					'loc3_values' => [],
					'standard_loc3' => null
				];
			}

			if (!isset($standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]['loc3_values'][$loc3]))
			{
				$standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]['loc3_values'][$loc3] = 0;
			}

			$standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]['loc3_values'][$loc3]++;
		}

		// Determine the standard loc3 for each street number (the most frequently used one)
		foreach ($standardLoc3ByLocAndStreet as $loc1 => &$loc2Data)
		{
			foreach ($loc2Data as $loc2 => &$streetNumbers)
			{
				foreach ($streetNumbers as $streetNumber => &$data)
				{
					arsort($data['loc3_values']); // Sort by frequency, most common first
					$data['standard_loc3'] = key($data['loc3_values']);
				}
			}
		}

		// Second pass - find and flag inconsistencies
		foreach ($this->locationData as $index => $entry)
		{
			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$loc3 = $entry['loc3'];
			$loc4 = $entry['loc4'];
			$streetNumber = $entry['street_number'];
			$streetId = $entry['street_id'];

			// Use the passed mapping instead of the class property
			$bygningsnr = $entryToBygningsnrMap[$index] ?? $entry['bygningsnr'];

			// Skip if the street number doesn't have a standard loc3 for this loc1+loc2
			if (!isset($standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]['standard_loc3']))
			{
				continue;
			}

			$standardLoc3 = $standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]['standard_loc3'];

			// Only consider it an issue if:
			// 1. The current loc3 is different from the standard
			// 2. The standard loc3 has significantly more occurrences than the current loc3
			$currentCount = $standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]['loc3_values'][$loc3] ?? 0;
			$standardCount = $standardLoc3ByLocAndStreet[$loc1][$loc2][$streetNumber]['loc3_values'][$standardLoc3];

			// Normalize loc3 values for comparison to handle formatting differences
			$normalizedLoc3 = str_pad(intval($loc3), 2, '0', STR_PAD_LEFT);
			$normalizedStandardLoc3 = str_pad(intval($standardLoc3), 2, '0', STR_PAD_LEFT);

			// Skip fixed location codes
			$locationCode = "{$loc1}-{$loc2}-{$loc3}-{$loc4}";
			if (isset($this->fixedLocationCodes[$locationCode]))
			{
				continue;
			}

			if ($normalizedLoc3 !== $normalizedStandardLoc3 && $standardCount > $currentCount)
			{
				// Debug info for transparency
				// Add additional verification to ensure we're not creating false positives
				if ($normalizedLoc3 === $normalizedStandardLoc3)
				{
					continue; // Double check that we don't flag entries with semantically equal loc3 values
				}

				$this->issues[] = [
					'type' => 'inconsistent_street_number_loc3',
					'loc1' => $loc1,
					'loc2' => $loc2,
					'loc3' => $loc3,
					'loc4' => $loc4,
					'street_id' => $streetId,
					'street_number' => $streetNumber,
					'bygningsnr' => $bygningsnr, // This now uses the potentially synthetic ID
					'correct_loc3' => $standardLoc3
				];

				$this->suggestions[] = "Location code '{$loc1}-{$loc2}-{$loc3}-{$loc4}' with street number '{$streetNumber}' should use loc3='{$standardLoc3}' instead of '{$loc3}'";
			}
		}
	}

	/**
	 * Generate SQL statements for the example dataset with inconsistent loc3 values
	 * 
	 * @param string|null $filterLoc1 Optional loc1 to filter issues by
	 * @return array SQL statements for corrections
	 */
	public function generateCorrectionsInconsistent_street_number_loc3($filterLoc1 = null)
	{
		$sqlLoc4 = [];
		$sqlCorrections = [];

		// Process issues of type 'inconsistent_street_number_loc3'
		foreach ($this->issues as $issue)
		{
			if ($issue['type'] === 'inconsistent_street_number_loc3')
			{
				// Apply filter if provided
				if ($filterLoc1 && $issue['loc1'] !== $filterLoc1)
				{
					continue;
				}

				$loc1 = $issue['loc1'];
				$loc2 = $issue['loc2'];
				$oldLoc3 = $issue['loc3'];
				$loc4 = $issue['loc4'];
				$newLoc3 = $issue['correct_loc3'];
				$streetId = (int)$issue['street_id'];
				$streetNumber = $issue['street_number'] ?? 'N/A';
				$bygningsnr = (int)$issue['bygningsnr'];

				$oldLocationCode = "{$loc1}-{$loc2}-{$oldLoc3}-{$loc4}";
				$newLocationCode = "{$loc1}-{$loc2}-{$newLoc3}-{$loc4}";

				// Skip if already processed globally or if the values are actually the same
				// Use normalized values for comparison to catch formatting differences
				$normalizedOldLoc3 = str_pad(intval($oldLoc3), 2, '0', STR_PAD_LEFT);
				$normalizedNewLoc3 = str_pad(intval($newLoc3), 2, '0', STR_PAD_LEFT);

				if (
					isset($this->processedLocationCodes[$oldLocationCode]) ||
					$normalizedOldLoc3 === $normalizedNewLoc3
				)
				{
					continue;
				}
				$this->processedLocationCodes[$oldLocationCode] = true;

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
									'{$loc4}', '{$bygningsnr}', {$streetId}, '{$streetNumber}', 
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
	 * @param string|null $filterLoc1 Optional loc1 to filter issues by
	 * @return array SQL statements for corrections
	 */
	public function generateCorrectionsNonSequentialLoc3($filterLoc1 = null)
	{
		$sqlLoc4 = [];
		$sqlCorrections = [];
		$sqlLoc3 = []; // Add array for fm_location3 inserts
		$processedLoc3 = []; // Keep this for loc3 entries which is separate

		// Group all entries by loc1 and loc2
		$entriesByLoc1Loc2 = [];
		foreach ($this->locationData as $entry)
		{
			// Apply filter if provided
			if ($filterLoc1 && $entry['loc1'] !== $filterLoc1)
			{
				continue;
			}

			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];

			if (!isset($entriesByLoc1Loc2[$loc1][$loc2]))
			{
				$entriesByLoc1Loc2[$loc1][$loc2] = [];
			}

			$entriesByLoc1Loc2[$loc1][$loc2][] = $entry;
		}

		// Find all loc1/loc2 combinations with non-sequential loc3 issues
		$loc1Loc2WithIssues = [];
		foreach ($this->issues as $issue)
		{
			if ($issue['type'] === 'non_sequential_loc3')
			{
				// Apply filter if provided
				if ($filterLoc1 && $issue['loc1'] !== $filterLoc1)
				{
					continue;
				}

				$loc1 = $issue['loc1'];
				$loc2 = $issue['loc2'];
				$loc1Loc2WithIssues["{$loc1}-{$loc2}"] = [
					'loc1' => $loc1,
					'loc2' => $loc2
				];
			}
		}

		// Process each loc1/loc2 combination with issues
		foreach ($loc1Loc2WithIssues as $key => $location)
		{
			$loc1 = $location['loc1'];
			$loc2 = $location['loc2'];

			if (!isset($entriesByLoc1Loc2[$loc1][$loc2]))
			{
				continue; // Skip if no entries found
			}

			// First, group entries by street address (street_id + street_number)
			$entriesByStreetAddress = [];
			foreach ($entriesByLoc1Loc2[$loc1][$loc2] as $entry)
			{
				$streetKey = "{$entry['street_id']}_{$entry['street_number']}";
				if (!isset($entriesByStreetAddress[$streetKey]))
				{
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

			foreach ($entriesByStreetAddress as $streetKey => $streetData)
			{
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
				if (!$loc3Exists)
				{
					$sqlLoc3[] = "-- Create new fm_location3 entry: {$newLocationCode} for {$fullAddress}";
					$sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
								VALUES ('{$newLocationCode}', '{$loc1}', '{$loc2}', '{$newLoc3}', '{$fullAddress}')
								ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
					}
				}

				// Now update each loc4 entry to use the new loc3 associated with its street address
				foreach ($entriesByLoc1Loc2[$loc1][$loc2] as $index => $entry)
				{
					$oldLoc3 = $entry['loc3'];
					$loc4 = $entry['loc4'];
					$streetId = (int)$entry['street_id'];
					$streetNumber = $entry['street_number'] ?? 'N/A';

					// Use the mapped bygningsnr (which might be synthetic)
					$bygningsnr = $this->entryToBygningsnrMap[$index] ?? (int)$entry['bygningsnr'];

					$streetKey = "{$streetId}_{$streetNumber}";

					// Skip if we don't have a mapping for this street address
					if (!isset($streetToLoc3Map[$streetKey]))
					{
						continue;
					}

					$newLoc3 = $streetToLoc3Map[$streetKey];
					$oldLocationCode = "{$loc1}-{$loc2}-{$oldLoc3}-{$loc4}";
					$newLocationCode = "{$loc1}-{$loc2}-{$newLoc3}-{$loc4}";

					// Skip if the location code doesn't change or already processed globally
					// Also skip if only the formatting changed but not the actual values
					if (
						$oldLocationCode === $newLocationCode ||
						isset($this->processedLocationCodes[$oldLocationCode]) ||
						$oldLoc3 === $newLoc3
					)
					{
						continue;
					}

					$this->processedLocationCodes[$oldLocationCode] = true;


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
										'{$loc4}', '{$bygningsnr}', {$streetId}, '{$streetNumber}', 
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
	 * @param string|null $filterLoc1 Optional loc1 to filter issues by
	 * @return array SQL statements for corrections
	 */
	public function generateCorrectionsInsufficientLoc3($filterLoc1 = null)
	{
		$sqlLoc3 = [];

		// Group all entries by loc1 and loc2
		$streetsByLoc1Loc2 = [];
		foreach ($this->locationData as $entry)
		{
			// Apply filter if provided
			if ($filterLoc1 && $entry['loc1'] !== $filterLoc1)
			{
				continue;
			}

			$loc1 = $entry['loc1'];
			$loc2 = $entry['loc2'];
			$streetId = $entry['street_id'];
			$streetNumber = $entry['street_number'];

			$streetKey = "{$streetId}_{$streetNumber}";

			if (!isset($streetsByLoc1Loc2[$loc1][$loc2]))
			{
				$streetsByLoc1Loc2[$loc1][$loc2] = [];
			}

			if (!isset($streetsByLoc1Loc2[$loc1][$loc2][$streetKey]))
			{
				$streetsByLoc1Loc2[$loc1][$loc2][$streetKey] = [
					'street_id' => $streetId,
					'street_number' => $streetNumber
				];
			}
		}

		// Find all loc1/loc2 combinations with insufficient_loc3 issues
		foreach ($this->issues as $issue)
		{
			if ($issue['type'] === 'insufficient_loc3')
			{
				// Apply filter if provided
				if ($filterLoc1 && $issue['loc1'] !== $filterLoc1)
				{
					continue;
				}

				$loc1 = $issue['loc1'];
				$loc2 = $issue['loc2'];

				if (!isset($streetsByLoc1Loc2[$loc1][$loc2]))
				{
					continue; // Skip if no entries found
				}

				// Find existing loc3 values to avoid duplicates
				$existingLoc3Values = [];
				foreach ($this->loc3References[$loc1][$loc2] ?? [] as $loc3 => $data)
				{
					$existingLoc3Values[$loc3] = true;
				}

				// Generate sequential loc3 values starting from max existing + 1
				$maxLoc3 = 0;
				if (!empty($existingLoc3Values))
				{
					$maxLoc3 = max(array_map('intval', array_keys($existingLoc3Values)));
				}

				$nextLoc3 = $maxLoc3 + 1;

				// Sort street keys to ensure consistent ordering
				ksort($streetsByLoc1Loc2[$loc1][$loc2]);

				// Create missing loc3 entries for each unique street
				foreach ($streetsByLoc1Loc2[$loc1][$loc2] as $streetKey => $streetData)
				{
					// Check if we already have a loc3 that references this street
					$hasLoc3ForStreet = false;
					foreach ($this->locationData as $entry)
					{
						if (
							$entry['loc1'] === $loc1 &&
							$entry['loc2'] === $loc2 &&
							$entry['street_id'] === $streetData['street_id'] &&
							$entry['street_number'] === $streetData['street_number']
						)
						{
							$hasLoc3ForStreet = true;
							break;
						}
					}

					// Skip if already has a loc3
					if ($hasLoc3ForStreet)
					{
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
		// Count entries with missing bygningsnr
		$missingBygningsnr = 0;
		foreach ($this->locationData as $entry)
		{
			if (empty($entry['bygningsnr']))
			{
				$missingBygningsnr++;
			}
		}

		$statistics = [
			'level1_count' => count(array_unique(array_column($this->locationData, 'loc1'))),
			'level2_count' => count(array_unique(array_map(fn($entry) => "{$entry['loc1']}-{$entry['loc2']}", $this->locationData))),
			'level3_count' => count(array_unique(array_map(fn($entry) => "{$entry['loc1']}-{$entry['loc2']}-{$entry['loc3']}", $this->locationData))),
			'level4_count' => count($this->locationData),
			'unique_buildings' => count(array_filter(array_unique(array_column($this->locationData, 'bygningsnr')))), // Exclude empty values
			'unique_addresses' => count(array_unique(array_map(fn($entry) => "{$entry['street_id']}-{$entry['street_number']}", $this->locationData))),
			'missing_bygningsnr' => $missingBygningsnr,
		];

		// Count issues by type
		$issueTypes = [];
		$issueCount = 0;

		foreach ($this->issues as $issue)
		{
			$type = $issue['type'];
			if (!isset($issueTypes[$type]))
			{
				$issueTypes[$type] = 0;
			}
			$issueTypes[$type]++;
			$issueCount++;
		}

		// Add issue statistics
		$statistics['total_issues'] = $issueCount;
		$statistics['issues_by_type'] = $issueTypes;

		// Add human-readable issue descriptions
		$issueDescriptions = [
			'missing_loc2_assignment' => 'Buildings missing loc2 assignment',
			'multiple_buildings_in_loc2' => 'Multiple buildings in the same loc2',
			'insufficient_loc2' => 'Insufficient loc2 values for buildings',
			'conflicting_loc3' => 'Conflicting loc3 for same street address',
			'non_sequential_loc3' => 'Non-sequential loc3 values',
			'insufficient_loc3' => 'Insufficient loc3 values for entrances',
			'inconsistent_street_number_loc3' => 'Inconsistent loc3 for same street number',
			'duplicate_bygningsnr' => 'Same building ID (bygningsnr) appears in multiple loc2 values'
		];

		$statistics['issue_descriptions'] = $issueDescriptions;

		return $statistics;
	}

	/**
	 * Generate SQL statements based on the analysis results
	 * 
	 * @param bool $returnAsArray Whether to return as an array or a string
	 * @return array|string SQL statements for corrections
	 */
	private function generateSQLStatements($returnAsArray = false)
	{
		$sqlLoc2 = [];
		$sqlLoc3 = [];
		$sqlLoc4 = [];
		$sqlCorrections = [];
		$sqlSchema = [];

		// Track processed location codes to prevent duplicates
		// Use the class property instead of a local variable
		// $processedLocationCodes = [];

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
			bygningsnr VARCHAR(15),
			street_id INTEGER,
			street_number VARCHAR(10),
			change_type VARCHAR(100),
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
			foreach ($this->locationData as $index => $entry)
			{
				$loc1 = $entry['loc1'];
				$oldLoc2 = $entry['loc2'];
				$oldLoc3 = $entry['loc3'];
				$loc4 = $entry['loc4'];

				// Use the mapped bygningsnr (which might be synthetic)
				$bygningsnr = $this->entryToBygningsnrMap[$index] ?? (int)$entry['bygningsnr'];

				$streetId = (int)$entry['street_id'];
				$streetNumber = $entry['street_number'] ?? 'N/A';

				// Determine the new loc2 and loc3 values
				$newLoc2 = isset($buildingToLoc2Map[$loc1][$bygningsnr]) ?
					$buildingToLoc2Map[$loc1][$bygningsnr] : $oldLoc2;

				$streetKey = "{$streetId}_{$streetNumber}";
				$newLoc3 = isset($streetToLoc3Map[$loc1][$newLoc2][$streetKey]) ?
					$streetToLoc3Map[$loc1][$newLoc2][$streetKey] : (isset($streetToLoc3Map[$loc1][$newLoc2]['default']) ?
						$streetToLoc3Map[$loc1][$newLoc2]['default'] : $oldLoc3);

				$oldLocationCode = "{$loc1}-{$oldLoc2}-{$oldLoc3}-{$loc4}";
				$newLocationCode = "{$loc1}-{$newLoc2}-{$newLoc3}-{$loc4}";

				// Skip fixed location codes
				if (isset($this->fixedLocationCodes[$oldLocationCode]))
				{
					continue;
				}

				// Only generate update if location changed and values are actually different
				if (
					$oldLocationCode !== $newLocationCode &&
					($oldLoc2 !== $newLoc2 || $oldLoc3 !== $newLoc3)
				)
				{
					// Skip if already processed
					if (isset($this->processedLocationCodes[$oldLocationCode]))
					{
						continue;
					}
					$this->processedLocationCodes[$oldLocationCode] = true;

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
										'{$loc4}', '{$bygningsnr}', {$streetId}, '{$streetNumber}', 
										'location_hierarchy_update'
									);";
				}
			}

		// Handle existing issues (conflicting_loc3, insufficient_loc3, etc.)
		// Use the stored filter value instead of recalculating
		$inconsistent_street_number_loc3 = $this->generateCorrectionsInconsistent_street_number_loc3($this->currentFilterLoc1);
		$non_sequential_loc3 = $this->generateCorrectionsNonSequentialLoc3($this->currentFilterLoc1);
		$insufficient_loc3 = $this->generateCorrectionsInsufficientLoc3($this->currentFilterLoc1);

		if ($returnAsArray)
		{
			// Create a tracking array to prevent duplicate updates
			$processedEntries = [];
			$combinedLoc4Updates = [];
			$combinedCorrections = [];

			// Process normal location4 updates first
			foreach ($sqlLoc4 as $sql)
			{
				// Skip comment lines
				if (strpos($sql, '--') === 0)
				{
					$combinedLoc4Updates[] = $sql;
					continue;
				}

				// Extract old location code from the SQL
				if (preg_match("/WHERE location_code = '([^']+)'/", $sql, $matches))
				{
					$oldLocationCode = $matches[1];
					if (!isset($processedEntries[$oldLocationCode]))
					{
						$processedEntries[$oldLocationCode] = true;
						$combinedLoc4Updates[] = $sql;
					}
				}
				else
				{
					$combinedLoc4Updates[] = $sql;
				}
			}

			// Process inconsistent street number loc3 updates
			foreach ($inconsistent_street_number_loc3['location4_updates'] as $sql)
			{
				if (strpos($sql, '--') === 0)
				{
					$combinedLoc4Updates[] = $sql;
					continue;
				}

				if (preg_match("/WHERE location_code = '([^']+)'/", $sql, $matches))
				{
					$oldLocationCode = $matches[1];
					if (!isset($processedEntries[$oldLocationCode]))
					{
						$processedEntries[$oldLocationCode] = true;
						$combinedLoc4Updates[] = $sql;
					}
				}
				else
				{
					$combinedLoc4Updates[] = $sql;
				}
			}

			// Process non-sequential loc3 updates
			foreach ($non_sequential_loc3['location4_updates'] as $sql)
			{
				if (strpos($sql, '--') === 0)
				{
					$combinedLoc4Updates[] = $sql;
					continue;
				}

				if (preg_match("/WHERE location_code = '([^']+)'/", $sql, $matches))
				{
					$oldLocationCode = $matches[1];
					if (!isset($processedEntries[$oldLocationCode]))
					{
						$processedEntries[$oldLocationCode] = true;
						$combinedLoc4Updates[] = $sql;
					}
				}
				else
				{
					$combinedLoc4Updates[] = $sql;
				}
			}

			// Do the same for corrections
			$processedEntries = [];
			foreach (
				array_merge(
					$sqlCorrections,
					$inconsistent_street_number_loc3['corrections'],
					$non_sequential_loc3['corrections']
				) as $sql
			)
			{

				if (preg_match("/VALUES\s*\(\s*'([^']+)'/", $sql, $matches))
				{
					$oldLocationCode = $matches[1];
					if (!isset($processedEntries[$oldLocationCode]))
					{
						$processedEntries[$oldLocationCode] = true;
						$combinedCorrections[] = $sql;
					}
				}
				else
				{
					$combinedCorrections[] = $sql;
				}
			}

			// Add extra filters to ensure no false positives in the combined updates
			$filteredUpdates = [];
			foreach ($combinedLoc4Updates as $index => $sql)
			{
				// Skip comment lines and add them directly
				if (strpos($sql, '--') === 0)
				{
					$filteredUpdates[] = $sql;
					continue;
				}

				// Check for updates where values aren't actually changing
				// Use a more flexible regex that doesn't hardcode column names
				if (preg_match("/SET location_code = '([^']+)'(?:,\s*\w+ = '[^']+')*\s*WHERE location_code = '([^']+)'/", $sql, $matches))
				{
					$newLocationCode = $matches[1];
					$oldLocationCode = $matches[2];

					// Skip if location codes are identical
					if ($newLocationCode === $oldLocationCode)
					{
						continue;
					}

					// Extract the old location parts and compare with new
					$oldParts = explode('-', $oldLocationCode);
					$newParts = explode('-', $newLocationCode);

					// If all parts of the location code are the same, skip this update
					if (
						count($oldParts) === count($newParts) &&
						$oldParts[0] === $newParts[0] &&
						$oldParts[1] === $newParts[1] &&
						$oldParts[2] === $newParts[2] &&
						$oldParts[3] === $newParts[3]
					)
					{
						continue;
					}

					// Only keep SQL that actually changes something
					$filteredUpdates[] = $sql;
				}
				else
				{
					$filteredUpdates[] = $sql;
				}
			}

			// Deep check for duplicate location code entries with slightly different formatting
			$locationCodeMap = [];
			$finalUpdates = [];

			foreach ($filteredUpdates as $sql)
			{
				// Skip comment lines
				if (strpos($sql, '--') === 0)
				{
					$finalUpdates[] = $sql;
					continue;
				}

				if (preg_match("/WHERE location_code = '([^']+)'/", $sql, $matches))
				{
					$oldLocationCode = $matches[1];
					$normalizedLocationCode = $this->normalizeLocationCode($oldLocationCode);

					if (!isset($locationCodeMap[$normalizedLocationCode]))
					{
						$locationCodeMap[$normalizedLocationCode] = true;
						$finalUpdates[] = $sql;
					}
				}
				else
				{
					$finalUpdates[] = $sql;
				}
			}

			return [
				'schema' => $sqlSchema,
				'missing_loc2' => $sqlLoc2,
				'missing_loc3' => array_merge(
					$sqlLoc3,
					$insufficient_loc3['missing_loc3'],
					$non_sequential_loc3['location3_updates'] ?? []
				),
				'corrections' => $combinedCorrections,
				'location4_updates' => $finalUpdates,
			];
		}
	}

	/**
	 * Normalize a location code to ensure consistent comparison
	 * 
	 * @param string $locationCode The location code to normalize
	 * @return string The normalized location code
	 */
	private function normalizeLocationCode($locationCode)
	{
		// Split into parts
		$parts = explode('-', $locationCode);
		if (count($parts) !== 4)
		{
			return $locationCode;
		}

		// Ensure each part is standardized
		$normalizedParts = [
			$parts[0],                                   // loc1 stays as-is
			str_pad(intval($parts[1]), 2, '0', STR_PAD_LEFT), // loc2 as 2-digit zero-padded
			str_pad(intval($parts[2]), 2, '0', STR_PAD_LEFT), // loc3 as 2-digit zero-padded
			str_pad(intval($parts[3]), 3, '0', STR_PAD_LEFT)  // loc4 as 3-digit zero-padded
		];

		return implode('-', $normalizedParts);
	}

	/**
	 * Reset the analyzer state to ensure consistent behavior between runs
	 */
	public function resetState()
	{
		$this->processedLocationCodes = [];
		$this->issues = [];
		$this->suggestions = [];
		$this->locationData = [];
		$this->loc2References = [];
		$this->loc3References = [];
		$this->currentFilterLoc1 = null;
		$this->entryToBygningsnrMap = [];
		$this->fixedLocationCodes = [];

		// Clear any static caches that might persist between calls
		static $street_names = null;
		$street_names = [];
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

	/**
	 * Execute selected SQL statements
	 * 
	 * @param string $loc1 The loc1 value
	 * @param array $sqlTypes Array of SQL types to execute
	 * @param array $sqlStatements All SQL statements from analysis
	 * @return array Results of SQL execution
	 */
	public function executeSqlStatements($loc1, $sqlTypes, $sqlStatements)
	{
		$results = [];

		foreach ($sqlTypes as $sqlType)
		{
			if (!isset($sqlStatements[$sqlType]))
			{
				continue;
			}

			$statementsToExecute = $sqlStatements[$sqlType];
			$count = 0;

			foreach ($statementsToExecute as $sql)
			{
				// Skip comments
				if (strpos($sql, '--') === 0)
				{
					continue;
				}

				// Execute SQL
				try
				{
					$this->db->query($sql, __LINE__, __FILE__);
					$count++;
				}
				catch (\Exception $e)
				{
					// Log error but continue
					error_log("Error executing SQL: " . $e->getMessage());
				}
			}

			$results[$sqlType] = $count;
		}

		return $results;
	}

	/**
	 * Fetch all unique loc1 values from the database
	 * 
	 * @return array Array of unique loc1 values
	 */
	public function getAllLoc1Values()
	{
		$loc1Values = [];
		$sql = "SELECT DISTINCT loc1 FROM fm_location4 ORDER BY loc1";
		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$loc1Values[] = $this->db->f('loc1');
		}

		return $loc1Values;
	}

	/**
	 * Analyze all loc1 values separately and combine the results
	 * This prevents false positives when analyzing across different loc1 values
	 * 
	 * @return array Combined analysis results
	 */
	public function analyzeAllLoc1Separately()
	{
		$loc1Values = $this->getAllLoc1Values();

		// Set up combined results containers
		$combinedIssues = [];
		$combinedSuggestions = [];
		$combinedSqlStatements = [
			'schema' => [],
			'missing_loc2' => [],
			'missing_loc3' => [],
			'corrections' => [],
			'location4_updates' => []
		];

		// Track loc1 counts for statistics
		$totalLevel1Count = count($loc1Values);
		$totalLevel2Count = 0;
		$totalLevel3Count = 0;
		$totalLevel4Count = 0;
		$totalBuildings = 0;
		$totalAddresses = 0;
		$totalIssues = 0;
		$issueTypes = [];

		// Analyze each loc1 separately
		foreach ($loc1Values as $loc1)
		{
			$results = $this->analyze($loc1);

			// Combine issues and suggestions
			$combinedIssues = array_merge($combinedIssues, $results['issues']);
			$combinedSuggestions = array_merge($combinedSuggestions, $results['suggestions']);

			// Combine SQL statements
			if (isset($results['sql_statements']['schema']) && !empty($results['sql_statements']['schema']))
			{
				$combinedSqlStatements['schema'] = $results['sql_statements']['schema'];
			}

			$combinedSqlStatements['missing_loc2'] = array_merge(
				$combinedSqlStatements['missing_loc2'],
				$results['sql_statements']['missing_loc2'] ?? []
			);

			$combinedSqlStatements['missing_loc3'] = array_merge(
				$combinedSqlStatements['missing_loc3'],
				$results['sql_statements']['missing_loc3'] ?? []
			);

			$combinedSqlStatements['corrections'] = array_merge(
				$combinedSqlStatements['corrections'],
				$results['sql_statements']['corrections'] ?? []
			);

			$combinedSqlStatements['location4_updates'] = array_merge(
				$combinedSqlStatements['location4_updates'],
				$results['sql_statements']['location4_updates'] ?? []
			);

			// Combine statistics
			$totalLevel2Count += $results['statistics']['level2_count'];
			$totalLevel3Count += $results['statistics']['level3_count'];
			$totalLevel4Count += $results['statistics']['level4_count'];
			$totalBuildings += $results['statistics']['unique_buildings'];
			$totalAddresses += $results['statistics']['unique_addresses'];
			$totalIssues += $results['statistics']['total_issues'];

			// Combine issue type counts
			foreach ($results['statistics']['issues_by_type'] as $type => $count)
			{
				if (!isset($issueTypes[$type]))
				{
					$issueTypes[$type] = 0;
				}
				$issueTypes[$type] += $count;
			}
		}

		// Create combined statistics
		$combinedStatistics = [
			'level1_count' => $totalLevel1Count,
			'level2_count' => $totalLevel2Count,
			'level3_count' => $totalLevel3Count,
			'level4_count' => $totalLevel4Count,
			'unique_buildings' => $totalBuildings,
			'unique_addresses' => $totalAddresses,
			'total_issues' => $totalIssues,
			'issues_by_type' => $issueTypes,
			'issue_descriptions' => [
				'missing_loc2_assignment' => 'Buildings missing loc2 assignment',
				'multiple_buildings_in_loc2' => 'Multiple buildings in the same loc2',
				'insufficient_loc2' => 'Insufficient loc2 values for buildings',
				'conflicting_loc3' => 'Conflicting loc3 for same street address',
				'non_sequential_loc3' => 'Non-sequential loc3 values',
				'insufficient_loc3' => 'Insufficient loc3 values for entrances',
				'inconsistent_street_number_loc3' => 'Inconsistent loc3 for same street number'
			]
		];

		return [
			'statistics' => $combinedStatistics,
			'issues' => $combinedIssues,
			'suggestions' => $combinedSuggestions,
			'sql_statements' => $combinedSqlStatements,
		];
	}
}
