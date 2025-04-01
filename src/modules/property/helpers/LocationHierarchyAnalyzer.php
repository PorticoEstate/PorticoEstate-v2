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
	private $issues = [];
	private $statistics = [];
	private $suggestions = [];
	private $buildingMappings = []; // New property to store building mappings
	private $entranceMappings = []; // New property to store entrance mappings

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	/**
	 * Run all analysis checks
	 */
	public function analyzeAll()
	{
		$this->collectStatistics();
		$this->analyzeLocationStructure(); // New method to analyze the overall structure
		$this->checkLocationCodes();
		$this->checkMissingBuildings();
		$this->checkMissingEntrances();
		$this->checkIncorrectBuildingAssignments();
		$this->checkIncorrectEntranceAssignments();
		$this->checkOrphanedEntries();
		$this->generateSuggestions();

		$this->displayResults();
	}

	/**
	 * Analyze the overall location structure and create mappings
	 */
	private function analyzeLocationStructure()
	{
		// Analyze building to location2 mappings based on bygningsnr
		$sql = "WITH building_counts AS (
			SELECT 
				loc1, 
				bygningsnr,
				COUNT(*) AS apartment_count
			FROM fm_location4
			WHERE bygningsnr IS NOT NULL
			GROUP BY loc1, bygningsnr
			ORDER BY loc1, bygningsnr
		)
		SELECT
			bc.loc1,
			bc.bygningsnr,
			bc.apartment_count,
			ROW_NUMBER() OVER (PARTITION BY bc.loc1 ORDER BY bc.bygningsnr) AS seq_num
		FROM building_counts bc";
		
		$this->db->query($sql, __LINE__, __FILE__);
		
		while ($this->db->next_record()) {
			$loc1 = $this->db->f('loc1');
			$bygningsnr = $this->db->f('bygningsnr');
			$seq_num = $this->db->f('seq_num');
			
			// Store the correct building mapping
			$this->buildingMappings[$loc1][$bygningsnr] = [
				'loc2' => str_pad($seq_num, 2, '0', STR_PAD_LEFT),
				'apartment_count' => $this->db->f('apartment_count')
			];
		}
		
		// Analyze street address to location3 mappings
		$sql = "WITH address_counts AS (
			SELECT 
				loc1,
				bygningsnr,
				street_id,
				street_number,
				COUNT(*) AS apartment_count
			FROM fm_location4
			WHERE street_id IS NOT NULL AND street_number IS NOT NULL AND bygningsnr IS NOT NULL
			GROUP BY loc1, bygningsnr, street_id, street_number
			ORDER BY loc1, bygningsnr, street_id, street_number
		)
		SELECT
			ac.loc1,
			ac.bygningsnr,
			ac.street_id,
			ac.street_number,
			ac.apartment_count,
			ROW_NUMBER() OVER (
				PARTITION BY ac.loc1, ac.bygningsnr 
				ORDER BY ac.street_id, ac.street_number
			) AS seq_num
		FROM address_counts ac";
		
		$this->db->query($sql, __LINE__, __FILE__);
		
		while ($this->db->next_record()) {
			$loc1 = $this->db->f('loc1');
			$bygningsnr = $this->db->f('bygningsnr');
			$street_id = $this->db->f('street_id');
			$street_number = $this->db->f('street_number');
			$seq_num = $this->db->f('seq_num');
			
			// Get the loc2 for this building
			$loc2 = isset($this->buildingMappings[$loc1][$bygningsnr]) 
				? $this->buildingMappings[$loc1][$bygningsnr]['loc2'] 
				: null;
			
			if ($loc2) {
				// Store the correct entrance mapping
				$this->entranceMappings[$loc1][$loc2][$street_id][$street_number] = [
					'loc3' => str_pad($seq_num, 2, '0', STR_PAD_LEFT),
					'apartment_count' => $this->db->f('apartment_count')
				];
			}
		}
		
		// Calculate statistics on the mappings
		$this->statistics['required_buildings'] = array_sum(array_map('count', $this->buildingMappings));
		
		$entranceCount = 0;
		foreach ($this->entranceMappings as $loc1Data) {
			foreach ($loc1Data as $loc2Data) {
				foreach ($loc2Data as $streetData) {
					$entranceCount += count($streetData);
				}
			}
		}
		$this->statistics['required_entrances'] = $entranceCount;
	}

	/**
	 * Collect basic statistics about the location hierarchy
	 */
	private function collectStatistics()
	{
		// Count entries at each level
		$this->db->query("SELECT COUNT(*) AS count FROM fm_location1", __LINE__, __FILE__);
		$this->db->next_record();
		$this->statistics['level1_count'] = $this->db->f('count');

		$this->db->query("SELECT COUNT(*) AS count FROM fm_location2", __LINE__, __FILE__);
		$this->db->next_record();
		$this->statistics['level2_count'] = $this->db->f('count');

		$this->db->query("SELECT COUNT(*) AS count FROM fm_location3", __LINE__, __FILE__);
		$this->db->next_record();
		$this->statistics['level3_count'] = $this->db->f('count');

		$this->db->query("SELECT COUNT(*) AS count FROM fm_location4", __LINE__, __FILE__);
		$this->db->next_record();
		$this->statistics['level4_count'] = $this->db->f('count');

		// Count unique building numbers
		$this->db->query("SELECT COUNT(DISTINCT bygningsnr) AS count FROM fm_location4 WHERE bygningsnr IS NOT NULL", __LINE__, __FILE__);
		$this->db->next_record();
		$this->statistics['unique_buildings'] = $this->db->f('count');

		// Count unique street addresses - FIXED query for PostgreSQL
		$this->db->query("SELECT COUNT(*) AS count FROM (
			SELECT DISTINCT street_id, street_number 
			FROM fm_location4 
			WHERE street_id IS NOT NULL AND street_number IS NOT NULL
		) AS distinct_addresses", __LINE__, __FILE__);
		$this->db->next_record();
		$this->statistics['unique_addresses'] = $this->db->f('count');
	}

	/**
	 * Check if location codes are correctly formed
	 */
	private function checkLocationCodes()
	{
		// Check level 2 codes
		$sql = "SELECT l2.loc1, l2.loc2, l2.location_code, 
                CONCAT(l1.location_code, '-', l2.loc2) AS expected_code
                FROM fm_location2 l2
                JOIN fm_location1 l1 ON l2.loc1 = l1.loc1
                WHERE l2.location_code != CONCAT(l1.location_code, '-', l2.loc2)";
		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$this->issues[] = [
				'type' => 'invalid_location_code',
				'level' => 2,
				'loc1' => $this->db->f('loc1'),
				'loc2' => $this->db->f('loc2'),
				'current_code' => $this->db->f('location_code'),
				'expected_code' => $this->db->f('expected_code')
			];
		}

		// Check level 3 codes
		$sql = "SELECT l3.loc1, l3.loc2, l3.loc3, l3.location_code, 
                CONCAT(l2.location_code, '-', l3.loc3) AS expected_code
                FROM fm_location3 l3
                JOIN fm_location2 l2 ON l3.loc1 = l2.loc1 AND l3.loc2 = l2.loc2
                WHERE l3.location_code != CONCAT(l2.location_code, '-', l3.loc3)";
		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$this->issues[] = [
				'type' => 'invalid_location_code',
				'level' => 3,
				'loc1' => $this->db->f('loc1'),
				'loc2' => $this->db->f('loc2'),
				'loc3' => $this->db->f('loc3'),
				'current_code' => $this->db->f('location_code'),
				'expected_code' => $this->db->f('expected_code')
			];
		}

		// Check level 4 codes
		$sql = "SELECT l4.loc1, l4.loc2, l4.loc3, l4.loc4, l4.location_code, 
                CONCAT(l3.location_code, '-', l4.loc4) AS expected_code
                FROM fm_location4 l4
                JOIN fm_location3 l3 ON l4.loc1 = l3.loc1 AND l4.loc2 = l3.loc2 AND l4.loc3 = l3.loc3
                WHERE l4.location_code != CONCAT(l3.location_code, '-', l4.loc4)";
		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$this->issues[] = [
				'type' => 'invalid_location_code',
				'level' => 4,
				'loc1' => $this->db->f('loc1'),
				'loc2' => $this->db->f('loc2'),
				'loc3' => $this->db->f('loc3'),
				'loc4' => $this->db->f('loc4'),
				'current_code' => $this->db->f('location_code'),
				'expected_code' => $this->db->f('expected_code')
			];
		}
	}

	/**
	 * Check for missing buildings at level 2
	 */
	private function checkMissingBuildings()
	{
		// Find building numbers that should have their own level 2 entries
		$sql = "WITH building_counts AS (
			SELECT loc1, bygningsnr, COUNT(*) AS count
			FROM fm_location4
			WHERE bygningsnr IS NOT NULL
			GROUP BY loc1, bygningsnr
		)
		SELECT 
			bc.loc1, 
			bc.bygningsnr, 
			bc.count,
			CASE 
				WHEN l2_mapping.loc2 IS NULL THEN 'missing'
				ELSE 'mismatched'
			END AS status,
			l2_mapping.loc2
		FROM building_counts bc
		LEFT JOIN (
			SELECT DISTINCT l4.loc1, l4.bygningsnr, l2.loc2
			FROM fm_location4 l4
			JOIN fm_location2 l2 ON l4.loc1 = l2.loc1 AND l4.loc2 = l2.loc2
			WHERE l4.bygningsnr IS NOT NULL
		) AS l2_mapping ON bc.loc1 = l2_mapping.loc1 AND bc.bygningsnr = l2_mapping.bygningsnr";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$loc1 = $this->db->f('loc1');
			$bygningsnr = $this->db->f('bygningsnr');
			$status = $this->db->f('status');
			
			// Get the correct loc2 from our mapping
			$correct_loc2 = isset($this->buildingMappings[$loc1][$bygningsnr]) 
				? $this->buildingMappings[$loc1][$bygningsnr]['loc2'] 
				: null;
			
			if ($status === 'missing' && $correct_loc2) {
				$this->issues[] = [
					'type' => 'missing_building',
					'loc1' => $loc1,
					'bygningsnr' => $bygningsnr,
					'apartment_count' => $this->db->f('count'),
					'correct_loc2' => $correct_loc2
				];
			}
		}
	}

	/**
	 * Check for missing entrances at level 3
	 */
	private function checkMissingEntrances()
	{
		// Enhanced query to find missing entrances based on street_id and street_number
		$sql = "WITH address_counts AS (
			SELECT 
				l4.loc1, 
				l4.loc2, 
				l4.bygningsnr,
				l4.street_id, 
				l4.street_number, 
				COUNT(*) AS count
			FROM fm_location4 l4
			WHERE l4.street_id IS NOT NULL AND l4.street_number IS NOT NULL
			GROUP BY l4.loc1, l4.loc2, l4.bygningsnr, l4.street_id, l4.street_number
		)
		SELECT 
			ac.loc1, 
			ac.loc2, 
			ac.bygningsnr,
			ac.street_id, 
			ac.street_number, 
			ac.count,
			CASE 
				WHEN l3_mapping.loc3 IS NULL THEN 'missing'
				ELSE 'mismatched'
			END AS status,
			l3_mapping.loc3
		FROM address_counts ac
		LEFT JOIN (
			SELECT DISTINCT 
				l4.loc1, l4.loc2, l4.bygningsnr, l4.street_id, l4.street_number, l3.loc3
			FROM fm_location4 l4
			JOIN fm_location3 l3 
				ON l4.loc1 = l3.loc1 AND l4.loc2 = l3.loc2 AND l4.loc3 = l3.loc3
			WHERE l4.street_id IS NOT NULL AND l4.street_number IS NOT NULL
		) AS l3_mapping 
			ON ac.loc1 = l3_mapping.loc1 AND ac.loc2 = l3_mapping.loc2 
			AND ac.street_id = l3_mapping.street_id AND ac.street_number = l3_mapping.street_number";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$loc1 = $this->db->f('loc1');
			$loc2 = $this->db->f('loc2');
			$bygningsnr = $this->db->f('bygningsnr');
			$street_id = $this->db->f('street_id');
			$street_number = $this->db->f('street_number');
			$status = $this->db->f('status');
			
			// Get the correct loc2 from our building mapping
			$correct_loc2 = isset($this->buildingMappings[$loc1][$bygningsnr]) 
				? $this->buildingMappings[$loc1][$bygningsnr]['loc2'] 
				: $loc2;
			
			// Get the correct loc3 from our entrance mapping
			$correct_loc3 = isset($this->entranceMappings[$loc1][$correct_loc2][$street_id][$street_number]) 
				? $this->entranceMappings[$loc1][$correct_loc2][$street_id][$street_number]['loc3'] 
				: null;
			
			if ($status === 'missing' && $correct_loc3) {
				$this->issues[] = [
					'type' => 'missing_entrance',
					'loc1' => $loc1,
					'loc2' => $correct_loc2,
					'bygningsnr' => $bygningsnr,
					'street_id' => $street_id,
					'street_number' => $street_number,
					'apartment_count' => $this->db->f('count'),
					'correct_loc3' => $correct_loc3
				];
			}
		}
	}

	/**
	 * Check for incorrect building assignments
	 */
	private function checkIncorrectBuildingAssignments()
	{
		// Enhanced query to find apartments assigned to the wrong building
		$sql = "SELECT 
			l4.location_code, 
			l4.loc1, l4.loc2, l4.loc3, l4.loc4,
			l4.bygningsnr,
			l4.street_id,
			l4.street_number
		FROM fm_location4 l4
		WHERE l4.bygningsnr IS NOT NULL";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$loc1 = $this->db->f('loc1');
			$loc2 = $this->db->f('loc2');
			$loc3 = $this->db->f('loc3');
			$loc4 = $this->db->f('loc4');
			$bygningsnr = $this->db->f('bygningsnr');
			$street_id = $this->db->f('street_id');
			$street_number = $this->db->f('street_number');
			
			// Get the correct loc2 from our mapping
			$correct_loc2 = isset($this->buildingMappings[$loc1][$bygningsnr]) 
				? $this->buildingMappings[$loc1][$bygningsnr]['loc2'] 
				: $loc2;
			
			if ($loc2 !== $correct_loc2) {
				$this->issues[] = [
					'type' => 'incorrect_building',
					'location_code' => $this->db->f('location_code'),
					'loc1' => $loc1,
					'loc2' => $loc2,
					'loc3' => $loc3,
					'loc4' => $loc4,
					'bygningsnr' => $bygningsnr,
					'street_id' => $street_id,
					'street_number' => $street_number,
					'correct_loc2' => $correct_loc2
				];
			}
		}
	}

	/**
	 * Check for incorrect entrance assignments
	 */
	private function checkIncorrectEntranceAssignments()
	{
		// Enhanced query to find apartments assigned to the wrong entrance
		$sql = "SELECT 
			l4.location_code, 
			l4.loc1, l4.loc2, l4.loc3, l4.loc4,
			l4.bygningsnr,
			l4.street_id,
			l4.street_number
		FROM fm_location4 l4
		WHERE l4.street_id IS NOT NULL AND l4.street_number IS NOT NULL AND l4.bygningsnr IS NOT NULL";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$loc1 = $this->db->f('loc1');
			$loc2 = $this->db->f('loc2');
			$loc3 = $this->db->f('loc3');
			$loc4 = $this->db->f('loc4');
			$bygningsnr = $this->db->f('bygningsnr');
			$street_id = $this->db->f('street_id');
			$street_number = $this->db->f('street_number');
			
			// Get the correct loc2 from our building mapping
			$correct_loc2 = isset($this->buildingMappings[$loc1][$bygningsnr]) 
				? $this->buildingMappings[$loc1][$bygningsnr]['loc2'] 
				: $loc2;
			
			// Get the correct loc3 from our entrance mapping
			$correct_loc3 = isset($this->entranceMappings[$loc1][$correct_loc2][$street_id][$street_number]) 
				? $this->entranceMappings[$loc1][$correct_loc2][$street_id][$street_number]['loc3'] 
				: $loc3;
			
			// First check if the building is correct, if not this will be caught by incorrect_building
			if ($loc2 === $correct_loc2 && $loc3 !== $correct_loc3) {
				$this->issues[] = [
					'type' => 'incorrect_entrance',
					'location_code' => $this->db->f('location_code'),
					'loc1' => $loc1,
					'loc2' => $loc2,
					'loc3' => $loc3,
					'loc4' => $loc4,
					'bygningsnr' => $bygningsnr,
					'street_id' => $street_id,
					'street_number' => $street_number,
					'correct_loc3' => $correct_loc3
				];
			}
		}
	}

	/**
	 * Check for orphaned entries (locations without proper parents)
	 */
	private function checkOrphanedEntries()
	{
		// Check for level 2 entries without level 1 parents
		$sql = "SELECT l2.* FROM fm_location2 l2
                LEFT JOIN fm_location1 l1 ON l2.loc1 = l1.loc1
                WHERE l1.loc1 IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$this->issues[] = [
				'type' => 'orphaned_entry',
				'level' => 2,
				'location_code' => $this->db->f('location_code'),
				'loc1' => $this->db->f('loc1'),
				'loc2' => $this->db->f('loc2')
			];
		}

		// Check for level 3 entries without level 2 parents
		$sql = "SELECT l3.* FROM fm_location3 l3
                LEFT JOIN fm_location2 l2 ON l3.loc1 = l2.loc1 AND l3.loc2 = l2.loc2
                WHERE l2.loc2 IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$this->issues[] = [
				'type' => 'orphaned_entry',
				'level' => 3,
				'location_code' => $this->db->f('location_code'),
				'loc1' => $this->db->f('loc1'),
				'loc2' => $this->db->f('loc2'),
				'loc3' => $this->db->f('loc3')
			];
		}

		// Check for level 4 entries without level 3 parents
		$sql = "SELECT l4.* FROM fm_location4 l4
                LEFT JOIN fm_location3 l3 ON l4.loc1 = l3.loc1 AND l4.loc2 = l3.loc2 AND l4.loc3 = l3.loc3
                WHERE l3.loc3 IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$this->issues[] = [
				'type' => 'orphaned_entry',
				'level' => 4,
				'location_code' => $this->db->f('location_code'),
				'loc1' => $this->db->f('loc1'),
				'loc2' => $this->db->f('loc2'),
				'loc3' => $this->db->f('loc3'),
				'loc4' => $this->db->f('loc4')
			];
		}
	}

	/**
	 * Generate suggestions for fixing issues
	 */
	private function generateSuggestions()
	{
		// Create mapping table to track old and new values
		$this->suggestions[] = "
		-- Create mapping table to track old and new location values
		CREATE TABLE IF NOT EXISTS location_mapping (
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
		);
		";

		// Create missing buildings
		if ($this->getIssueCountByType('missing_building') > 0)
		{
			$this->suggestions[] = "
			-- Generate missing buildings based on bygningsnr:
			WITH needed_buildings AS (
				SELECT DISTINCT 
					l4.loc1, 
					l4.bygningsnr,
					CASE
						WHEN l1.loc1_name IS NULL THEN 'Property ' || l4.loc1
						ELSE l1.loc1_name
					END AS loc1_name,
					'" . date('Y-m-d') . "' AS entry_date
				FROM fm_location4 l4
				LEFT JOIN fm_location1 l1 ON l4.loc1 = l1.loc1
				WHERE l4.bygningsnr IS NOT NULL
				AND NOT EXISTS (
					SELECT 1 FROM fm_location2 l2 
					WHERE l2.loc1 = l4.loc1 AND l2.loc2 = (
						SELECT lm.loc2
						FROM location_mapping lm
						WHERE lm.loc1 = l4.loc1 AND lm.bygningsnr = l4.bygningsnr
						LIMIT 1
					)
				)
			)
			INSERT INTO fm_location2 (loc1, loc2, loc2_name, location_code, entry_date)
			SELECT 
				nb.loc1,
				lm.new_loc2,
				nb.loc1_name || ' - Bygg ' || nb.bygningsnr,
				l1.location_code || '-' || lm.new_loc2,
				nb.entry_date
			FROM needed_buildings nb
			JOIN fm_location1 l1 ON nb.loc1 = l1.loc1
			JOIN location_mapping lm ON nb.loc1 = lm.loc1 AND nb.bygningsnr = lm.bygningsnr
			WHERE lm.change_type = 'missing_building';
			";
			
			// Track the missing buildings
			$buildingSql = "INSERT INTO location_mapping 
				(loc1, bygningsnr, new_loc2, change_type) VALUES\n";
			
			$values = [];
			foreach ($this->issues as $issue) {
				if ($issue['type'] === 'missing_building') {
					$values[] = "('{$issue['loc1']}', {$issue['bygningsnr']}, '{$issue['correct_loc2']}', 'missing_building')";
				}
			}
			
			if (!empty($values)) {
				$this->suggestions[] = $buildingSql . implode(",\n", $values) . ";";
			}
		}

		// Create missing entrances
		if ($this->getIssueCountByType('missing_entrance') > 0)
		{
			$this->suggestions[] = "
			-- Generate missing entrances based on street addresses:
			WITH needed_entrances AS (
				SELECT DISTINCT 
					l4.loc1, 
					lm.new_loc2 AS loc2,
					l4.street_id,
					l4.street_number,
					(SELECT street_name FROM fm_street WHERE street_id = l4.street_id) AS street_name,
					l2.loc2_name,
					'" . date('Y-m-d') . "' AS entry_date
				FROM fm_location4 l4
				JOIN location_mapping lm ON l4.loc1 = lm.loc1 AND l4.bygningsnr = lm.bygningsnr
				JOIN fm_location2 l2 ON lm.new_loc2 = l2.loc2 AND l4.loc1 = l2.loc1
				WHERE l4.street_id IS NOT NULL AND l4.street_number IS NOT NULL
				AND NOT EXISTS (
					SELECT 1 FROM fm_location3 l3 
					WHERE l3.loc1 = l4.loc1 AND l3.loc2 = lm.new_loc2 AND l3.loc3 = (
						SELECT em.new_loc3
						FROM location_mapping em
						WHERE em.loc1 = l4.loc1 AND em.new_loc2 = lm.new_loc2 
						AND em.street_id = l4.street_id AND em.street_number = l4.street_number
						LIMIT 1
					)
				)
			)
			INSERT INTO fm_location3 (loc1, loc2, loc3, loc3_name, location_code, entry_date)
			SELECT 
				ne.loc1,
				ne.loc2,
				em.new_loc3,
				'Inngang ' || ne.street_name || ' ' || ne.street_number,
				l2.location_code || '-' || em.new_loc3,
				ne.entry_date
			FROM needed_entrances ne
			JOIN location_mapping em 
				ON ne.loc1 = em.loc1 AND ne.loc2 = em.new_loc2 
				AND ne.street_id = em.street_id AND ne.street_number = em.street_number
			JOIN fm_location2 l2 ON ne.loc1 = l2.loc1 AND ne.loc2 = l2.loc2
			WHERE em.change_type = 'missing_entrance';
			";
			
			// Track the missing entrances
			$entranceSql = "INSERT INTO location_mapping 
				(loc1, new_loc2, street_id, street_number, new_loc3, change_type) VALUES\n";
			
			$values = [];
			foreach ($this->issues as $issue) {
				if ($issue['type'] === 'missing_entrance') {
					$values[] = "('{$issue['loc1']}', '{$issue['loc2']}', {$issue['street_id']}, '{$issue['street_number']}', '{$issue['correct_loc3']}', 'missing_entrance')";
				}
			}
			
			if (!empty($values)) {
				$this->suggestions[] = $entranceSql . implode(",\n", $values) . ";";
			}
		}

		// Fix incorrect building and entrance assignments
		if ($this->getIssueCountByType('incorrect_building') > 0 || 
			$this->getIssueCountByType('incorrect_entrance') > 0)
		{
			// Track the location changes
			$locationSql = "INSERT INTO location_mapping 
				(old_location_code, new_location_code, loc1, old_loc2, new_loc2, old_loc3, new_loc3, loc4, bygningsnr, street_id, street_number, change_type) VALUES\n";
			
			$values = [];
			foreach ($this->issues as $issue) {
				if ($issue['type'] === 'incorrect_building') {
					// Get correct loc3
					$correct_loc3 = isset($this->entranceMappings[$issue['loc1']][$issue['correct_loc2']][$issue['street_id']][$issue['street_number']]) 
						? $this->entranceMappings[$issue['loc1']][$issue['correct_loc2']][$issue['street_id']][$issue['street_number']]['loc3'] 
						: $issue['loc3'];
					
					$old_code = $issue['location_code'];
					$new_code = $issue['loc1'] . '-' . $issue['correct_loc2'] . '-' . $correct_loc3 . '-' . $issue['loc4'];
					
					$values[] = "('{$old_code}', '{$new_code}', '{$issue['loc1']}', '{$issue['loc2']}', '{$issue['correct_loc2']}', '{$issue['loc3']}', '{$correct_loc3}', '{$issue['loc4']}', {$issue['bygningsnr']}, " . 
						($issue['street_id'] ? $issue['street_id'] : "NULL") . ", " . 
						($issue['street_number'] ? "'{$issue['street_number']}'" : "NULL") . ", 'incorrect_building')";
				}
				else if ($issue['type'] === 'incorrect_entrance') {
					$old_code = $issue['location_code'];
					$new_code = $issue['loc1'] . '-' . $issue['loc2'] . '-' . $issue['correct_loc3'] . '-' . $issue['loc4'];
					
					$values[] = "('{$old_code}', '{$new_code}', '{$issue['loc1']}', '{$issue['loc2']}', '{$issue['loc2']}', '{$issue['loc3']}', '{$issue['correct_loc3']}', '{$issue['loc4']}', {$issue['bygningsnr']}, {$issue['street_id']}, '{$issue['street_number']}', 'incorrect_entrance')";
				}
			}
			
			if (!empty($values)) {
				$this->suggestions[] = $locationSql . implode(",\n", $values) . ";";
				
				// Update the location codes
				$this->suggestions[] = "
				-- Update apartments with their correct locations:
				UPDATE fm_location4 l4
				SET 
					location_code = lm.new_location_code,
					loc2 = lm.new_loc2,
					loc3 = lm.new_loc3
				FROM location_mapping lm
				WHERE l4.location_code = lm.old_location_code
				AND (lm.change_type = 'incorrect_building' OR lm.change_type = 'incorrect_entrance');
				";
			}
		}
	}

	/**
	 * Count issues by type
	 */
	private function getIssueCountByType($type)
	{
		$count = 0;
		foreach ($this->issues as $issue)
		{
			if ($issue['type'] === $type)
			{
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Display analysis results
	 */
	private function displayResults()
	{
		$isWeb = isset($GLOBALS['phpgw_info']) && isset($_SERVER['REQUEST_METHOD']);
		$lineBreak = $isWeb ? "<br>\n" : "\n";

		echo "=========================================================={$lineBreak}";
		echo "LOCATION HIERARCHY ANALYSIS REPORT{$lineBreak}";
		echo "=========================================================={$lineBreak}{$lineBreak}";

		// Display statistics
		echo "STATISTICS:{$lineBreak}";
		echo "-----------{$lineBreak}";
		echo "Properties (Level 1): {$this->statistics['level1_count']}{$lineBreak}";
		echo "Buildings (Level 2): {$this->statistics['level2_count']}{$lineBreak}";
		echo "Entrances (Level 3): {$this->statistics['level3_count']}{$lineBreak}";
		echo "Apartments (Level 4): {$this->statistics['level4_count']}{$lineBreak}";
		echo "Unique Building Numbers: {$this->statistics['unique_buildings']}{$lineBreak}";
		echo "Unique Street Addresses: {$this->statistics['unique_addresses']}{$lineBreak}";
		echo "Required Buildings: {$this->statistics['required_buildings']}{$lineBreak}";
		echo "Required Entrances: {$this->statistics['required_entrances']}{$lineBreak}{$lineBreak}";

		// Display issues by type
		echo "ISSUES FOUND:{$lineBreak}";
		echo "-------------{$lineBreak}";

		$issuesByType = [
			'invalid_location_code' => 0,
			'missing_building' => 0,
			'missing_entrance' => 0,
			'incorrect_building' => 0,
			'incorrect_entrance' => 0,
			'orphaned_entry' => 0
		];

		foreach ($this->issues as $issue)
		{
			$issuesByType[$issue['type']]++;
		}

		foreach ($issuesByType as $type => $count)
		{
			echo ucfirst(str_replace('_', ' ', $type)) . ": $count{$lineBreak}";
		}

		// Display issue details
		if (!empty($this->issues))
		{
			echo "{$lineBreak}ISSUE DETAILS:{$lineBreak}";
			echo "-------------{$lineBreak}";

			foreach ($issuesByType as $type => $count)
			{
				if ($count > 0)
				{
					echo "{$lineBreak}" . ucfirst(str_replace('_', ' ', $type)) . " details:{$lineBreak}";

					$detailCount = 0;
					foreach ($this->issues as $issue)
					{
						if ($issue['type'] === $type)
						{
							$detailCount++;
							if ($detailCount <= 10)
							{ // Limit to 10 examples per type
								$this->displayIssueDetail($issue);
							}
						}
					}

					if ($detailCount > 10)
					{
						echo "... and " . ($detailCount - 10) . " more issues of this type.{$lineBreak}";
					}
				}
			}
		}

		// Display suggestions
		if (!empty($this->suggestions))
		{
			echo "{$lineBreak}SUGGESTED FIXES:{$lineBreak}";
			echo "---------------{$lineBreak}";

			foreach ($this->suggestions as $suggestion)
			{
				echo $suggestion . "{$lineBreak}";
			}
		}

		echo "{$lineBreak}=========================================================={$lineBreak}";
		echo "End of Analysis Report{$lineBreak}";
		echo "=========================================================={$lineBreak}";
	}

	/**
	 * Display details for a specific issue
	 */
	private function displayIssueDetail($issue)
	{
		$isWeb = isset($GLOBALS['phpgw_info']) && isset($_SERVER['REQUEST_METHOD']);
		$lineBreak = $isWeb ? "<br>\n" : "\n";

		switch ($issue['type'])
		{
			case 'invalid_location_code':
				echo "  Level {$issue['level']} location code invalid: {$issue['current_code']} should be {$issue['expected_code']}{$lineBreak}";
				break;

			case 'missing_building':
				echo "  Property {$issue['loc1']} needs a building for bygningsnr {$issue['bygningsnr']} (affects {$issue['apartment_count']} apartments){$lineBreak}";
				break;

			case 'missing_entrance':
				echo "  Building {$issue['loc1']}-{$issue['loc2']} needs an entrance for street address ID {$issue['street_id']}, number {$issue['street_number']} (affects {$issue['apartment_count']} apartments){$lineBreak}";
				break;

			case 'incorrect_building':
				echo "  Apartment {$issue['location_code']} with bygningsnr {$issue['bygningsnr']} is in building {$issue['loc2']} but should be in {$issue['correct_loc2']}{$lineBreak}";
				break;

			case 'incorrect_entrance':
				echo "  Apartment {$issue['location_code']} with street address {$issue['street_id']}/{$issue['street_number']} is in entrance {$issue['loc3']} but should be in {$issue['correct_loc3']}{$lineBreak}";
				break;

			case 'orphaned_entry':
				echo "  Level {$issue['level']} entry {$issue['location_code']} has no valid parent{$lineBreak}";
				break;
		}
	}
}

// Run the analysis
//$analyzer = new LocationHierarchyAnalyzer();
//$analyzer->analyzeAll();
