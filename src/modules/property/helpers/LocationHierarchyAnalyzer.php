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
        $this->db->query($sql, __LINE__, __FILE__);
        while ($this->db->next_record())
        {
            $this->loc2References[$this->db->f('loc1')][$this->db->f('loc2')] = true;
        }

        // Load references for fm_location3
        $sql = "SELECT loc1, loc2, loc3 FROM fm_location3";
        $this->db->query($sql, __LINE__, __FILE__);
        while ($this->db->next_record())
        {
            $this->loc3References[$this->db->f('loc1')][$this->db->f('loc2')][$this->db->f('loc3')] = true;
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

        // Validate loc2 and loc3 references
        foreach ($this->locationData as $entry)
        {
            $loc1 = $entry['loc1'];
            $loc2 = $entry['loc2'];
            $loc3 = $entry['loc3'];
            $bygningsnr = $entry['bygningsnr'];
            $streetId = $entry['street_id'];
            $streetNumber = $entry['street_number'];

            // Check if loc2 exists in fm_location2
            if (!isset($this->loc2References[$loc1][$loc2]))
            {
                $this->issues[] = [
                    'type' => 'missing_loc2',
                    'loc1' => $loc1,
                    'loc2' => $loc2,
                    'bygningsnr' => $bygningsnr
                ];
            }

            // Check if loc3 exists in fm_location3
            if (!isset($this->loc3References[$loc1][$loc2][$loc3]))
            {
                $this->issues[] = [
                    'type' => 'missing_loc3',
                    'loc1' => $loc1,
                    'loc2' => $loc2,
                    'loc3' => $loc3,
                    'street_id' => $streetId,
                    'street_number' => $streetNumber
                ];
            }
        }

        // Enhanced validation for missing loc3 entries
        foreach ($this->locationData as $entry)
        {
            $loc1 = $entry['loc1'];
            $loc2 = $entry['loc2'];
            $loc3 = $entry['loc3'];
            $bygningsnr = $entry['bygningsnr'];
            $streetId = $entry['street_id'];
            $streetNumber = $entry['street_number'];

            // Check if loc3 exists in fm_location3
            if (!isset($this->loc3References[$loc1][$loc2][$loc3]))
            {
                $this->issues[] = [
                    'type' => 'missing_loc3',
                    'loc1' => $loc1,
                    'loc2' => $loc2,
                    'loc3' => $loc3,
                    'street_id' => $streetId,
                    'street_number' => $streetNumber
                ];
            }
        }

        // Force create at least one SQL statement for debugging
        if (empty($this->issues))
        {
            // Create a test entry to help diagnose the issue
            $this->issues[] = [
                'type' => 'debug_info',
                'message' => 'No issues found in the analysis'
            ];
        }
    }

    private function printFindings()
    {
        echo "Findings:\n";
        foreach ($this->issues as $issue)
        {
            if ($issue['type'] === 'missing_loc2')
            {
                echo "Missing loc2: loc1={$issue['loc1']}, loc2={$issue['loc2']}, bygningsnr={$issue['bygningsnr']}\n";
            }
            elseif ($issue['type'] === 'missing_loc3')
            {
                echo "Missing loc3: loc1={$issue['loc1']}, loc2={$issue['loc2']}, loc3={$issue['loc3']}, street_id={$issue['street_id']}, street_number={$issue['street_number']}\n";
            }
            elseif ($issue['type'] === 'conflicting_loc2')
            {
                echo "Conflicting loc2: loc1={$issue['loc1']}, bygningsnr={$issue['bygningsnr']}, loc2={$issue['loc2']}\n";
            }
            elseif ($issue['type'] === 'conflicting_loc3')
            {
                echo "Conflicting loc3: loc1={$issue['loc1']}, loc2={$issue['loc2']}, street_id={$issue['street_id']}, street_number={$issue['street_number']}, loc3={$issue['loc3']}\n";
            }
        }
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
        $sqlCorrections = [];
        $sqlSchema = [];

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

        $loc3_street_names = [];
        foreach ($this->issues as $issue)
        {
            if ($issue['type'] === 'missing_loc2')
            {
                $sqlLoc2[] = "INSERT INTO fm_location2 (loc1, loc2, bygningsnr) VALUES ('{$issue['loc1']}', '{$issue['loc2']}', '{$issue['bygningsnr']}');";
            }
            elseif ($issue['type'] === 'missing_loc3')
            {

                $loc3_street_name = $this->get_street_name((int)$issue['street_id']);

                $loc3_name = "{$loc3_street_name} {$issue['street_number']}";

                $location_code = "{$issue['loc1']}-{$issue['loc2']}-{$issue['loc3']}";
                $sqlLoc3[] = "-- Missing loc3 record in fm_location3 table";
                $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                              VALUES ('{$location_code}', '{$issue['loc1']}', '{$issue['loc2']}', '{$issue['loc3']}', 
                                     '{$loc3_name}');";
            }
            elseif ($issue['type'] === 'missing_loc2_assignment')
            {
                $sqlLoc2[] = "INSERT INTO fm_location2 (loc1, loc2, bygningsnr) VALUES ('{$issue['loc1']}', '{$issue['suggested_loc2']}', '{$issue['bygningsnr']}');";
            }
            elseif ($issue['type'] === 'multiple_buildings_in_loc2')
            {
                // For these, we need to lookup the suggested new loc2 values from suggestions
                $loc1 = $issue['loc1'];
                $loc2 = $issue['loc2'];
                $buildings = explode(', ', $issue['buildings']);

                // Skip the first building - it keeps its original loc2, no need for INSERT

                // For other buildings, find their new loc2 from suggestions and create new entries
                for ($i = 1; $i < count($buildings); $i++)
                {
                    $bygningsnr = $buildings[$i];
                    $newLoc2 = $this->findSuggestedLoc2($loc1, $bygningsnr);

                    if ($newLoc2)
                    {
                        $sqlLoc2[] = "-- Assign building {$bygningsnr} to new loc2";
                        $sqlLoc2[] = "INSERT INTO fm_location2 (loc1, loc2, bygningsnr) VALUES ('{$loc1}', '{$newLoc2}', '{$bygningsnr}');";

                    }
                }
            }
            elseif ($issue['type'] === 'non_sequential_loc3')
            {
                // We need to find the street_id/street_number for this loc1/loc2/loc3 combination
                foreach ($this->locationData as $entry)
                {
                    if (
                        $entry['loc1'] === $issue['loc1'] &&
                        $entry['loc2'] === $issue['loc2'] &&
                        $entry['loc3'] === $issue['actual_loc3']
                    )
                    {

                        $location_code = "{$issue['loc1']}-{$issue['loc2']}-{$issue['expected_loc3']}";
                        $loc3_street_name = $this->get_street_name((int)$entry['street_id']);
                        $loc3_name = "{$loc3_street_name} {$entry['street_number']}";
                        // Add SQL to insert into fm_location3 with the expected (correct) loc3 value
                        $sqlLoc3[] = "-- Fix non-sequential loc3: {$issue['actual_loc3']} should be {$issue['expected_loc3']}";
                        $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                                      VALUES ('{$location_code}', '{$issue['loc1']}', '{$issue['loc2']}', '{$issue['expected_loc3']}', '{$loc3_name}');";

                        break;
                    }
                }
            }
            elseif ($issue['type'] === 'insufficient_loc3')
            {
                // Find all street addresses in this loc1/loc2 that don't have proper loc3 assignments
                $loc1 = $issue['loc1'];
                $loc2 = $issue['loc2'];

                // Track all existing street addresses for this loc1/loc2
                $existingStreets = [];
                $existingLoc3Values = [];

                foreach ($this->locationData as $entry)
                {
                    if ($entry['loc1'] === $loc1 && $entry['loc2'] === $loc2)
                    {
                        $streetKey = "{$entry['street_id']}_{$entry['street_number']}";
                        $existingStreets[$streetKey] = [
                            'street_id' => $entry['street_id'],
                            'street_number' => $entry['street_number'],
                            'loc3' => $entry['loc3']
                        ];
                        $existingLoc3Values[$entry['loc3']] = true;
                    }
                }

                // Find the highest assigned loc3
                $highestLoc3 = 0;
                foreach (array_keys($existingLoc3Values) as $loc3)
                {
                    $highestLoc3 = max($highestLoc3, (int)$loc3);
                }

                // Assign sequential loc3 values for unique street combinations
                $nextLoc3 = 1;
                $processedStreets = [];

                foreach ($existingStreets as $streetKey => $streetData)
                {
                    if (!isset($processedStreets[$streetKey]))
                    {
                        $processedStreets[$streetKey] = true;

                        $loc3 = str_pad($nextLoc3, 2, '0', STR_PAD_LEFT);
                        $nextLoc3++;
                        $location_code = "{$loc1}-{$loc2}-{$loc3}";

                        // If the current loc3 doesn't match what it should be, create correction
                        if ($streetData['loc3'] !== $loc3)
                        {
                            $loc3_street_name = $this->get_street_name((int)$streetData['street_id']);
                            $loc3_name = "{$loc3_street_name} {$streetData['street_number']}";
                            $sqlLoc3[] = "-- Assign sequential loc3={$loc3} for street_id={$streetData['street_id']}, street_number={$streetData['street_number']}";
                            $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                                          VALUES ('{$location_code}', '{$loc1}', '{$loc2}', '{$loc3}', '{$loc3_name}');";
                        }
                    }
                }
            }
        }

        // Process non-sequential loc3 issues specifically
        $loc3ByStreetAddress = [];

        // First, group all street addresses by loc1/loc2
        foreach ($this->locationData as $entry)
        {
            $loc1 = $entry['loc1'];
            $loc2 = $entry['loc2'];
            $streetId = $entry['street_id'];
            $streetNumber = $entry['street_number'];

            if (!isset($loc3ByStreetAddress[$loc1][$loc2]))
            {
                $loc3ByStreetAddress[$loc1][$loc2] = [];
            }

            $key = "{$streetId}_{$streetNumber}";
            if (!isset($loc3ByStreetAddress[$loc1][$loc2][$key]))
            {
                $loc3ByStreetAddress[$loc1][$loc2][$key] = [
                    'street_id' => $streetId,
                    'street_number' => $streetNumber,
                    'entries' => []
                ];
            }

            $loc3ByStreetAddress[$loc1][$loc2][$key]['entries'][] = $entry;
        }

        // Generate correct sequential loc3 values and SQL statements
        foreach ($loc3ByStreetAddress as $loc1 => $loc2Data)
        {
            foreach ($loc2Data as $loc2 => $streets)
            {
                // Only process if we have multiple streets in this loc1/loc2
                if (count($streets) > 1)
                {
                    $sqlLoc3[] = "-- Generating sequential loc3 values for loc1={$loc1}, loc2={$loc2}";
                    $sqlLoc3[] = "-- Found " . count($streets) . " unique street addresses";

                    // Sort street addresses by some criteria (we'll use street_id and number)
                    ksort($streets);

                    // Assign sequential loc3 values
                    $loc3Value = 1;
                    foreach ($streets as $streetKey => $streetData)
                    {
                        $correctLoc3 = str_pad($loc3Value, 2, '0', STR_PAD_LEFT);
                        $loc3Value++;

                        // Get first entry to use as template
                        $entry = $streetData['entries'][0];
                        $currentLoc3 = $entry['loc3'];
                        $location_code = "{$loc1}-{$loc2}-{$correctLoc3}";

                        // Only generate SQL if the actual loc3 doesn't match what it should be
                        if ($currentLoc3 !== $correctLoc3)
                        {
                            $loc3_street_name = $this->get_street_name((int)$streetData['street_id']);
                            $loc3_name = "{$loc3_street_name} {$streetData['street_number']}";

                            $sqlLoc3[] = "-- Street {$streetData['street_id']}/{$streetData['street_number']} should have loc3={$correctLoc3} but has {$currentLoc3}";
                            $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                                          VALUES ('{$location_code}', '{$loc1}', '{$loc2}', '{$correctLoc3}', '{$loc3_name}');";
                        }
                    }
                }
            }
        }

        // Additional loop to find all unique loc1-loc2-street combinations with missing loc3
        $uniqueStreetsByLoc = [];
        foreach ($this->locationData as $entry)
        {
            $loc1 = $entry['loc1'];
            $loc2 = $entry['loc2'];
            $streetId = $entry['street_id'];
            $streetNumber = $entry['street_number'];

            $key = "{$loc1}_{$loc2}_{$streetId}_{$streetNumber}";
            if (!isset($uniqueStreetsByLoc[$key]))
            {
                $uniqueStreetsByLoc[$key] = [
                    'loc1' => $loc1,
                    'loc2' => $loc2,
                    'street_id' => $streetId,
                    'street_number' => $streetNumber,
                    'existing_loc3' => isset($this->loc3References[$loc1][$loc2]) ? array_keys($this->loc3References[$loc1][$loc2]) : []
                ];
            }
        }

        // Generate missing loc3 entries where none exists in fm_location3 for a street address
        foreach ($uniqueStreetsByLoc as $key => $data)
        {
            if (empty($data['existing_loc3']))
            {
                $loc1 = $data['loc1'];
                $loc2 = $data['loc2'];
                $streetId = $data['street_id'];
                $streetNumber = $data['street_number'];

                // Find appropriate loc3 for this street
                $loc3 = '01'; // Default to 01 if none exists
                $location_code = "{$loc1}-{$loc2}-{$loc3}";
                $loc3_street_name = $this->get_street_name((int)$streetId);
                $loc3_name = "{$loc3_street_name} {$streetNumber}";
                $sqlLoc3[] = "-- Missing loc3 entry for loc1={$loc1}, loc2={$loc2}, street_id={$streetId}, street_number={$streetNumber}";
                $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                              VALUES ('{$location_code}', '{$loc1}', '{$loc2}', '{$loc3}', '{$loc3_name}');";
            }
        }

        if ($returnAsArray)
        {
            return [
                'schema' => $sqlSchema,
                'missing_loc2' => $sqlLoc2,
                'missing_loc3' => $sqlLoc3,
                'corrections' => $sqlCorrections,
            ];
        }

        echo "\nSQL Statements for Schema:\n";
        echo implode("\n", $sqlSchema);

        echo "\nSQL Statements for Missing loc2:\n";
        echo implode("\n", $sqlLoc2);

        echo "\n\nSQL Statements for Missing loc3:\n";
        echo implode("\n", $sqlLoc3);

        echo "\n\nSQL Statements for Corrections:\n";
        echo implode("\n", $sqlCorrections);
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
