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
    private $newLoc2Assignments = [];

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

        // Load references for fm_location3 - include loc3_name now
        $sql = "SELECT loc1, loc2, loc3, loc3_name FROM fm_location3";
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
                    // ...existing logic for assigning new loc2 values...
                }
            }
        }

        // Store assignments for SQL generation
        $this->newLoc2Assignments = $newLoc2Assignments;
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
        $sqlLoc4 = [];
        $sqlCorrections = [];
        $sqlSchema = [];

        // Track processed location codes to prevent duplicates
        $processedLocationCodes = [];

        // Track created loc2 entries to ensure they have corresponding loc3 entries
        $createdLoc2Entries = [];

        // Track street addresses by building number for loc3 creation
        $buildingToStreetMap = [];

        // First, build a mapping of buildings to their street addresses
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

        $loc3_street_names = [];
        foreach ($this->issues as $issue)
        {
            if ($issue['type'] === 'missing_loc2')
            {
                $loc1 = $issue['loc1'];
                $loc2 = $issue['loc2'];
                $bygningsnr = $issue['bygningsnr'];

                $sqlLoc2[] = "INSERT INTO fm_location2 (loc1, loc2, bygningsnr) VALUES ('{$loc1}', '{$loc2}', '{$bygningsnr}');";

                // Track this loc2 with its building for later loc3 generation
                $createdLoc2Entries[$loc1][$loc2] = $bygningsnr;
            }
            elseif ($issue['type'] === 'missing_loc2_assignment')
            {
                $loc1 = $issue['loc1'];
                $loc2 = $issue['suggested_loc2'];
                $bygningsnr = $issue['bygningsnr'];

                $sqlLoc2[] = "INSERT INTO fm_location2 (loc1, loc2, bygningsnr) VALUES ('{$loc1}', '{$loc2}', '{$bygningsnr}');";

                // Track this loc2 with its building for later loc3 generation
                $createdLoc2Entries[$loc1][$loc2] = $bygningsnr;
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

                        // Track this loc2 with its building for later loc3 generation
                        $createdLoc2Entries[$loc1][$newLoc2] = $bygningsnr;
                    }
                }
            }
            elseif ($issue['type'] === 'conflicting_loc3')
            {
                $loc1 = $issue['loc1'];
                $loc2 = $issue['loc2'];
                $incorrectLoc3 = $issue['loc3'];
                $expectedLoc3 = $issue['expected_loc3'];
                $streetId = $issue['street_id'];
                $streetNumber = $issue['street_number'];

                // Find entries with this street address that have the incorrect loc3
                foreach ($this->locationData as $entry)
                {
                    if (
                        $entry['loc1'] === $loc1 &&
                        $entry['loc2'] === $loc2 &&
                        $entry['street_id'] === $streetId &&
                        $entry['street_number'] === $streetNumber &&
                        $entry['loc3'] === $incorrectLoc3
                    )
                    {

                        $loc4 = $entry['loc4'];
                        $bygningsnr = $entry['bygningsnr'];
                        $oldLocationCode = "{$loc1}-{$loc2}-{$incorrectLoc3}-{$loc4}";
                        $newLocationCode = "{$loc1}-{$loc2}-{$expectedLoc3}-{$loc4}";

                        // Track this update
                        $sqlLoc4[] = "-- Update location code: conflicting_loc3_correction";
                        $sqlLoc4[] = "UPDATE fm_location4 
                                      SET location_code = '{$newLocationCode}', loc3 = '{$expectedLoc3}' 
                                      WHERE location_code = '{$oldLocationCode}';";

                        // Add entry to location_mapping for tracking
                        $sqlCorrections[] = "INSERT INTO location_mapping (old_location_code, new_location_code, loc1, old_loc2, new_loc2, old_loc3, new_loc3, loc4, bygningsnr, street_id, street_number, change_type) 
                                            VALUES ('{$oldLocationCode}', '{$newLocationCode}', '{$loc1}', '{$loc2}', '{$loc2}', '{$incorrectLoc3}', '{$expectedLoc3}', '{$loc4}', {$bygningsnr}, {$streetId}, '{$streetNumber}', 'conflicting_loc3_correction');";
                    }
                }
            }
            elseif ($issue['type'] === 'insufficient_loc3')
            {
                $loc1 = $issue['loc1'];
                $loc2 = $issue['loc2'];

                // Find all street addresses in this loc1/loc2 with incorrect loc3 values
                $correctLoc3Assignments = [];
                $loc3Counter = 1;

                // Get all unique street addresses for this loc1/loc2
                $uniqueStreetAddresses = [];

                foreach ($this->locationData as $entry)
                {
                    if ($entry['loc1'] === $loc1 && $entry['loc2'] === $loc2)
                    {
                        $streetKey = "{$entry['street_id']}_{$entry['street_number']}";
                        if (!isset($uniqueStreetAddresses[$streetKey]))
                        {
                            $uniqueStreetAddresses[$streetKey] = [
                                'street_id' => $entry['street_id'],
                                'street_number' => $entry['street_number']
                            ];
                        }
                    }
                }

                // Assign sequential loc3 values
                foreach ($uniqueStreetAddresses as $streetKey => $data)
                {
                    $correctLoc3Assignments[$streetKey] = str_pad($loc3Counter++, 2, '0', STR_PAD_LEFT);
                }

                // Now check for entries that need updating
                foreach ($this->locationData as $entry)
                {
                    if ($entry['loc1'] === $loc1 && $entry['loc2'] === $loc2)
                    {
                        $streetKey = "{$entry['street_id']}_{$entry['street_number']}";
                        $currentLoc3 = $entry['loc3'];
                        $correctLoc3 = $correctLoc3Assignments[$streetKey];

                        if ($currentLoc3 !== $correctLoc3)
                        {
                            $loc4 = $entry['loc4'];
                            $bygningsnr = $entry['bygningsnr'];
                            $streetId = $entry['street_id'];
                            $streetNumber = $entry['street_number'];

                            $oldLocationCode = "{$loc1}-{$loc2}-{$currentLoc3}-{$loc4}";
                            $newLocationCode = "{$loc1}-{$loc2}-{$correctLoc3}-{$loc4}";

                            // Track this update
                            $sqlLoc4[] = "-- Update location code: insufficient_loc3_correction";
                            $sqlLoc4[] = "UPDATE fm_location4 
                                          SET location_code = '{$newLocationCode}', loc3 = '{$correctLoc3}' 
                                          WHERE location_code = '{$oldLocationCode}';";

                            // Add entry to location_mapping for tracking
                            $sqlCorrections[] = "INSERT INTO location_mapping (old_location_code, new_location_code, loc1, old_loc2, new_loc2, old_loc3, new_loc3, loc4, bygningsnr, street_id, street_number, change_type) 
                                                VALUES ('{$oldLocationCode}', '{$newLocationCode}', '{$loc1}', '{$loc2}', '{$loc2}', '{$currentLoc3}', '{$correctLoc3}', '{$loc4}', {$bygningsnr}, {$streetId}, '{$streetNumber}', 'insufficient_loc3_correction');";
                        }
                    }
                }
            }
        }

        // Generate loc3 entries for newly created loc2 entries
        foreach ($createdLoc2Entries as $loc1 => $loc2Array)
        {
            foreach ($loc2Array as $loc2 => $bygningsnr)
            {
                // Find all street addresses for this building
                if (isset($buildingToStreetMap[$bygningsnr]))
                {
                    $streetAddresses = $buildingToStreetMap[$bygningsnr];

                    // Create loc3 entries for each unique street address, starting from '01'
                    $loc3Index = 1;
                    foreach ($streetAddresses as $streetKey => $streetData)
                    {
                        $loc3 = str_pad($loc3Index++, 2, '0', STR_PAD_LEFT);
                        $streetName = $this->get_street_name($streetData['street_id']);
                        $fullAddress = "{$streetName} {$streetData['street_number']}";
                        $locationCode = "{$loc1}-{$loc2}-{$loc3}";

                        $sqlLoc3[] = "-- Create loc3 entry for new loc2='{$loc2}', address={$fullAddress}";
                        $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                                      VALUES ('{$locationCode}', '{$loc1}', '{$loc2}', '{$loc3}', '{$fullAddress}')
                                      ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
                    }

                    // If no street addresses found, still create at least one loc3 entry with a generic name
                    if (empty($streetAddresses))
                    {
                        $loc3 = "01";
                        $locationCode = "{$loc1}-{$loc2}-{$loc3}";

                        $sqlLoc3[] = "-- Create default loc3 entry for new loc2='{$loc2}'";
                        $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                                      VALUES ('{$locationCode}', '{$loc1}', '{$loc2}', '{$loc3}', 'Default entrance')
                                      ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
                    }
                }
                else
                {
                    // No street addresses found for this building, still create at least one loc3 entry
                    $loc3 = "01";
                    $locationCode = "{$loc1}-{$loc2}-{$loc3}";

                    $sqlLoc3[] = "-- Create default loc3 entry for new loc2='{$loc2}'";
                    $sqlLoc3[] = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name) 
                                  VALUES ('{$locationCode}', '{$loc1}', '{$loc2}', '{$loc3}', 'Default entrance')
                                  ON CONFLICT (loc1, loc2, loc3) DO NOTHING;";
                }
            }
        }

        // Add SQL for reassigning entries to new loc2 values
        if (!empty($this->newLoc2Assignments))
        {
            $sqlLoc4[] = "-- SQL statements for reassigning entries to new loc2 values";
            $sqlLoc4[] = "-- Each loc2 should have only one unique street address";

            // Create a lookup to avoid duplicate fm_location2 entries
            $processedLoc2 = [];

            foreach ($this->newLoc2Assignments as $assignment)
            {
                $loc1 = $assignment['loc1'];
                $oldLoc2 = $assignment['old_loc2'];
                $newLoc2 = $assignment['new_loc2'];
                $loc3 = $assignment['loc3'];
                $loc4 = $assignment['loc4'];
                $bygningsnr = $assignment['bygningsnr'];
                $streetId = $assignment['street_id'];
                $streetNumber = $assignment['street_number'];
                $oldLocationCode = $assignment['location_code'];
                $newLocationCode = "{$loc1}-{$newLoc2}-{$loc3}-{$loc4}";

                // Skip if we've already processed this location code
                if (isset($processedLocationCodes[$oldLocationCode]))
                {
                    continue;
                }
                $processedLocationCodes[$oldLocationCode] = true;

                $streetName = $this->get_street_name($streetId);
                $fullAddress = "{$streetName} {$streetNumber}";

                // Update fm_location4 entry
                $sqlLoc4[] = "-- Update entry in fm_location4 to use new loc2='{$newLoc2}'";
                $sqlLoc4[] = "UPDATE fm_location4 
                              SET location_code = '{$newLocationCode}', loc2 = '{$newLoc2}' 
                              WHERE location_code = '{$oldLocationCode}' AND street_id = {$streetId} AND street_number = '{$streetNumber}';";

                // Add to location_mapping for tracking
                $sqlCorrections[] = "INSERT INTO location_mapping (old_location_code, new_location_code, loc1, old_loc2, new_loc2, old_loc3, new_loc3, loc4, bygningsnr, street_id, street_number, change_type) 
                                     VALUES ('{$oldLocationCode}', '{$newLocationCode}', '{$loc1}', '{$oldLoc2}', '{$newLoc2}', '{$loc3}', '{$loc3}', '{$loc4}', {$bygningsnr}, {$streetId}, '{$streetNumber}', 'loc2_split_by_street');";
            }
        }

        if ($returnAsArray)
        {
            return [
                'schema' => $sqlSchema,
                'missing_loc2' => $sqlLoc2,
                'missing_loc3' => $sqlLoc3,
                'corrections' => $sqlCorrections,
                'location4_updates' => $sqlLoc4,
            ];
        }

        echo "\nSQL Statements for Schema:\n";
        echo implode("\n", $sqlSchema);

        echo "\nSQL Statements for Missing loc2:\n";
        echo implode("\n", $sqlLoc2);

        echo "\n\nSQL Statements for Missing loc3:\n";
        echo implode("\n", $sqlLoc3);

        echo "\n\nSQL Statements for fm_location4 updates:\n";
        echo implode("\n", $sqlLoc4);

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
