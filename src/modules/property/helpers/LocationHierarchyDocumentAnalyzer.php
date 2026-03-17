<?php

namespace App\modules\property\helpers;

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Vfs\Vfs;

class LocationHierarchyDocumentAnalyzer
{
	private $db;
	private $db2;
	private $basedir;
	private $vfs;
	private $rootdir;
	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->db2	 = new Db2();
		$serverSettings  = Settings::getInstance()->get('server');
		$this->basedir = isset($serverSettings['files_dir']) ? rtrim($serverSettings['files_dir'], '/\\') : '';
		$this->vfs = new Vfs();
		$this->rootdir	 = '/property/document';
	}

	/**
	 * Step 1: Analyze mapping table and find candidate directories that can be moved.
	 */
	public function analyzeCandidates()
	{
		$candidates = [];
		foreach ($this->getDistinctMappings() as $mapping)
		{
			$directories = $this->findDirectoriesForLocationCode($mapping['old_location_code']);
			$candidates[] = [
				'old_location_code' => $mapping['old_location_code'],
				'new_location_code' => $mapping['new_location_code'],
				'files_to_move' => (int) $mapping['files_to_move'],
				'files_moved' => (int) $mapping['files_moved'],
				'mapping_count' => (int) $mapping['mapping_count'],
				'directory_count' => count($directories),
				'directories' => $directories,
				'selection_key' => $mapping['old_location_code'] . '|' . $mapping['new_location_code'],
			];
		}

		return $candidates;
	}

	/**
	 * Get previously selected mappings (where files_to_move = 1).
	 */
	public function getPreviouslySelectionMappings()
	{
		$sql = "SELECT DISTINCT old_location_code, new_location_code,
			COALESCE(files_moved, 0) AS files_moved,
			COUNT(*) AS mapping_count
			FROM location_mapping
			WHERE COALESCE(old_location_code, '') <> ''
				AND COALESCE(new_location_code, '') <> ''
				AND old_location_code <> new_location_code
				AND COALESCE(files_to_move, 0) = 1
			GROUP BY old_location_code, new_location_code, files_moved
			ORDER BY old_location_code, new_location_code";

		$this->db->query($sql, __LINE__, __FILE__);
		$selections = [];
		while ($this->db->next_record())
		{
			$oldCode = $this->db->f('old_location_code');
			$directories = $this->findDirectoriesForLocationCode($oldCode);
			$selections[] = [
				'old_location_code' => $oldCode,
				'new_location_code' => $this->db->f('new_location_code'),
				'files_moved' => (int) $this->db->f('files_moved'),
				'mapping_count' => (int) $this->db->f('mapping_count'),
				'directory_count' => count($directories),
				'directories' => $directories,
				'selection_key' => $oldCode . '|' . $this->db->f('new_location_code'),
			];
		}
		return $selections;
	}

	/**
	 * Persist checkbox selection into location_mapping.files_to_move.
	 *
	 * @param array $selectedKeys values in format "old_location_code|new_location_code"
	 */
	public function updateFilesToMoveSelection(array $selectedKeys)
	{
		$selectedPairs = $this->parseSelectedMappingKeys($selectedKeys);

		// Reset current selection for mappings that are not already completed.
		// Keep files_to_move = 1 for files_moved = 1 rows so the history remains visible
		// when new items are added from a later analysis run.
		$sqlReset = "UPDATE location_mapping
			SET files_to_move = 0
			WHERE COALESCE(old_location_code, '') <> ''
				AND COALESCE(new_location_code, '') <> ''
				AND old_location_code <> new_location_code
				AND COALESCE(files_moved, 0) = 0";
		$this->db->query($sqlReset, __LINE__, __FILE__);

		foreach ($selectedPairs as $pair)
		{
			$old = addslashes($pair['old_location_code']);
			$new = addslashes($pair['new_location_code']);
			$sql = "UPDATE location_mapping
				SET files_to_move = 1
				WHERE old_location_code = '{$old}'
					AND new_location_code = '{$new}'";
			$this->db->query($sql, __LINE__, __FILE__);
		}
	}

	/**
	 * Step 2: Execute moves for all rows flagged with files_to_move = 1 and not moved yet.
	 * The process is isolated per location code pair in a dedicated DB transaction.
	 */
	public function executeMovesForSelectedMappings()
	{
		$results = [];
		$this->vfs->override_acl = 1;

		foreach ($this->getMappingsMarkedForMove() as $mapping)
		{
			$oldCode = $mapping['old_location_code'];
			$newCode = $mapping['new_location_code'];

			$from_dir = $this->rootdir . '/' . $oldCode;
			$to_dir = $this->rootdir . '/' . $newCode;


			if ($this->vfs->file_exists(array(
				'string'	 => $from_dir,
				'relatives'	 => array(RELATIVE_NONE)
			)))
			{
				$this->db->transaction_begin();
				$this->vfs->mv(
					array(
						'from'		 => $from_dir,
						'to'		 => $to_dir,
						'relatives'	 => array(RELATIVE_ALL, RELATIVE_ALL)
					)
				);	
		
				$this->markFilesMoved($oldCode, $newCode);
				$results[] = [
					'old_location_code' => $oldCode,
					'new_location_code' => $newCode,
					'status' => 'success',
					'message' => "Moved directory '{$from_dir}' to '{$to_dir}' using VFS",
				];
				$this->db->transaction_commit();
			}
			else
			{
				$results[] = [
					'old_location_code' => $oldCode,
					'new_location_code' => $newCode,
					'status' => 'skipped',
					'message' => "Source directory '{$from_dir}' does not exist, skipping move",
				];
			}
		}

		return $results;
	}

	/**
	 * Step 1 (dry-run): Analyze VFS paths and show missing directory definitions.
	 */
	public function analyzeMissingVfsDirectories()
	{
		$analysis = $this->buildMissingVfsDirectoryCandidates();

		return [
			'scanned_paths' => $analysis['scanned_paths'],
			'checked_directory_definitions' => $analysis['checked_directory_definitions'],
			'missing_count' => count($analysis['candidates']),
			'candidates' => $analysis['candidates'],
		];
	}

	/**
	 * Step 2: Execute the VFS directory repair by inserting missing Directory rows.
	 */
	public function repairMissingVfsDirectories()
	{
		$analysis = $this->buildMissingVfsDirectoryCandidates();
		$results = [];
		$inserted = 0;
		$skipped = 0;
		$failed = 0;

		if (empty($analysis['candidates']))
		{
			return [
				'scanned_paths' => $analysis['scanned_paths'],
				'checked_directory_definitions' => $analysis['checked_directory_definitions'],
				'candidate_count' => 0,
				'inserted_count' => 0,
				'skipped_count' => 0,
				'failed_count' => 0,
				'results' => [],
			];
		}

		$insertSql = "INSERT INTO phpgw_vfs (
			owner_id,
			createdby_id,
			created,
			size,
			mime_type,
			deleteable,
			app,
			directory,
			name,
			version
		) VALUES (
			:owner_id,
			:createdby_id,
			NOW(),
			:size,
			:mime_type,
			:deleteable,
			:app,
			:directory,
			:name,
			:version
		)";

		$insertStmt = $this->db->prepare($insertSql);

		$this->db->transaction_begin();
		try
		{
			foreach ($analysis['candidates'] as $candidate)
			{
				if ($this->directoryDefinitionExists($candidate['directory'], $candidate['name']))
				{
					$skipped++;
					$results[] = [
						'full_path' => $candidate['full_path'],
						'directory' => $candidate['directory'],
						'name' => $candidate['name'],
						'status' => 'skipped',
						'message' => 'Directory definition already exists',
					];
					continue;
				}

				$params = [
					':owner_id' => (int) $candidate['owner_id'],
					':createdby_id' => (int) $candidate['createdby_id'],
					':size' => 4096,
					':mime_type' => 'Directory',
					':deleteable' => 'Y',
					':app' => $candidate['app'],
					':directory' => $candidate['directory'],
					':name' => $candidate['name'],
					':version' => '0.0.0.1',
				];

				if (!$insertStmt->execute($params))
				{
					$failed++;
					$results[] = [
						'full_path' => $candidate['full_path'],
						'directory' => $candidate['directory'],
						'name' => $candidate['name'],
						'status' => 'failed',
						'message' => 'Insert execution failed',
					];
					continue;
				}

				$inserted++;
				$results[] = [
					'full_path' => $candidate['full_path'],
					'directory' => $candidate['directory'],
					'name' => $candidate['name'],
					'owner_id' => (int) $candidate['owner_id'],
					'app' => $candidate['app'],
					'status' => 'inserted',
					'message' => 'Directory definition inserted',
				];
			}

			$this->db->transaction_commit();
		}
		catch (\Throwable $e)
		{
			$this->db->transaction_abort();
			throw $e;
		}

		return [
			'scanned_paths' => $analysis['scanned_paths'],
			'checked_directory_definitions' => $analysis['checked_directory_definitions'],
			'candidate_count' => count($analysis['candidates']),
			'inserted_count' => $inserted,
			'skipped_count' => $skipped,
			'failed_count' => $failed,
			'results' => $results,
		];
	}

	private function getDistinctMappings()
	{
		$sql = "SELECT old_location_code, new_location_code,
			MAX(COALESCE(files_to_move, 0)) AS files_to_move,
			MAX(COALESCE(files_moved, 0)) AS files_moved,
			COUNT(*) AS mapping_count
			FROM location_mapping
			WHERE COALESCE(old_location_code, '') <> ''
				AND COALESCE(new_location_code, '') <> ''
				AND old_location_code <> new_location_code
			GROUP BY old_location_code, new_location_code
			ORDER BY old_location_code, new_location_code";

		$this->db->query($sql, __LINE__, __FILE__);
		$rows = [];
		while ($this->db->next_record())
		{
			$rows[] = [
				'old_location_code' => $this->db->f('old_location_code'),
				'new_location_code' => $this->db->f('new_location_code'),
				'files_to_move' => $this->db->f('files_to_move'),
				'files_moved' => $this->db->f('files_moved'),
				'mapping_count' => $this->db->f('mapping_count'),
			];
		}
		return $rows;
	}

	private function buildMissingVfsDirectoryCandidates()
	{
		$paths = $this->getDistinctVfsDirectories();
		$pathMap = [];

		foreach ($paths as $path)
		{
			foreach ($this->expandAncestorPaths($path) as $ancestorPath)
			{
				$pathMap[$ancestorPath] = true;
			}
		}

		$allPaths = array_keys($pathMap);
		usort($allPaths, function ($a, $b)
		{
			return substr_count($a, '/') <=> substr_count($b, '/');
		});

		$candidates = [];
		foreach ($allPaths as $fullPath)
		{
			$parts = $this->splitVfsPath($fullPath);
			if (empty($parts['name']))
			{
				continue;
			}

			if ($this->directoryDefinitionExists($parts['directory'], $parts['name']))
			{
				continue;
			}

			$meta = $this->resolveMetadataForPath($fullPath);
			$candidates[] = [
				'full_path' => $fullPath,
				'directory' => $parts['directory'],
				'name' => $parts['name'],
				'owner_id' => $meta['owner_id'],
				'createdby_id' => $meta['owner_id'],
				'app' => $meta['app'],
			];
		}

		return [
			'scanned_paths' => count($paths),
			'checked_directory_definitions' => count($allPaths),
			'candidates' => $candidates,
		];
	}

	private function getDistinctVfsDirectories()
	{
		$sql = "SELECT DISTINCT directory
			FROM phpgw_vfs
			WHERE COALESCE(directory, '') <> ''
				AND (mime_type IS NULL OR mime_type NOT IN ('journal', 'journal-deleted'))
			ORDER BY directory";

		$stmt = $this->db->prepare($sql);
		$stmt->execute();

		$paths = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$path = $this->normalizeVfsPath($row['directory']);
			if ($path !== '')
			{
				$paths[] = $path;
			}
		}

		return $paths;
	}

	private function expandAncestorPaths($path)
	{
		$path = $this->normalizeVfsPath($path);
		if ($path === '' || $path === '/')
		{
			return [];
		}

		$segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
		$ancestors = [];
		$current = '';

		foreach ($segments as $segment)
		{
			$current .= '/' . $segment;
			$ancestors[] = $current;
		}

		return $ancestors;
	}

	private function splitVfsPath($fullPath)
	{
		$normalized = $this->normalizeVfsPath($fullPath);
		if ($normalized === '' || $normalized === '/')
		{
			return ['directory' => '', 'name' => ''];
		}

		$trimmed = trim($normalized, '/');
		$segments = array_values(array_filter(explode('/', $trimmed), 'strlen'));
		$name = array_pop($segments);
		$directory = '/' . implode('/', $segments);

		if ($directory === '')
		{
			$directory = '/';
		}

		return [
			'directory' => $directory,
			'name' => $name,
		];
	}

	private function normalizeVfsPath($path)
	{
		$path = trim((string) $path);
		if ($path === '')
		{
			return '';
		}

		$path = str_replace('\\', '/', $path);
		$path = preg_replace('#/+#', '/', $path);
		if ($path[0] !== '/')
		{
			$path = '/' . $path;
		}

		return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
	}

	private function directoryDefinitionExists($directory, $name)
	{
		$sql = "SELECT 1
			FROM phpgw_vfs
			WHERE directory = :directory
				AND name = :name
				AND mime_type = 'Directory'
			LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':directory' => $directory,
			':name' => $name,
		]);

		return (bool) $stmt->fetchColumn();
	}

	private function resolveMetadataForPath($fullPath)
	{
		$sql = "SELECT owner_id, app
			FROM phpgw_vfs
			WHERE (
					directory = :full_path
					OR directory LIKE :prefix
				)
				AND (mime_type IS NULL OR mime_type NOT IN ('journal', 'journal-deleted'))
			ORDER BY CASE WHEN directory = :full_path_priority THEN 0 ELSE 1 END, file_id
			LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':full_path' => $fullPath,
			':prefix' => $fullPath . '/%',
			':full_path_priority' => $fullPath,
		]);

		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if ($row)
		{
			return [
				'owner_id' => (int) $row['owner_id'],
				'app' => !empty($row['app']) ? (string) $row['app'] : $this->defaultAppForPath($fullPath),
			];
		}

		return [
			'owner_id' => 6,
			'app' => $this->defaultAppForPath($fullPath),
		];
	}

	private function defaultAppForPath($fullPath)
	{
		if (strpos($fullPath, '/property/') === 0 || $fullPath === '/property')
		{
			return 'property';
		}

		return '';
	}

	private function getMappingsMarkedForMove()
	{
		$sql = "SELECT DISTINCT old_location_code, new_location_code
			FROM location_mapping
			WHERE COALESCE(files_to_move, 0) = 1
				AND COALESCE(files_moved, 0) = 0
				AND COALESCE(old_location_code, '') <> ''
				AND COALESCE(new_location_code, '') <> ''
				AND old_location_code <> new_location_code
			ORDER BY old_location_code, new_location_code";

		$this->db->query($sql, __LINE__, __FILE__);
		$rows = [];
		while ($this->db->next_record())
		{
			$rows[] = [
				'old_location_code' => $this->db->f('old_location_code'),
				'new_location_code' => $this->db->f('new_location_code'),
			];
		}
		return $rows;
	}

	private function markFilesMoved($oldLocationCode, $newLocationCode)
	{
		$old = addslashes($oldLocationCode);
		$new = addslashes($newLocationCode);
		$sql = "UPDATE location_mapping
			SET files_moved = 1,
				update_timestamp = NOW()
			WHERE old_location_code = '{$old}'
				AND new_location_code = '{$new}'
				AND COALESCE(files_to_move, 0) = 1";
		$this->db->query($sql, __LINE__, __FILE__);
	}

	private function findDirectoriesForLocationCode($oldLocationCode)
	{
		$oldEscaped = addslashes($oldLocationCode);
		$sql = "SELECT DISTINCT directory
			FROM phpgw_vfs
			WHERE directory LIKE '%/{$oldEscaped}/%'
				OR directory LIKE '%/{$oldEscaped}'
			ORDER BY directory";

		$this->db2->query($sql, __LINE__, __FILE__);
		$directories = [];
		while ($this->db2->next_record())
		{
			$directories[] = $this->db2->f('directory');
		}
		return $directories;
	}


	private function parseSelectedMappingKeys(array $selectedKeys)
	{
		$pairs = [];
		foreach ($selectedKeys as $key)
		{
			$parts = explode('|', (string) $key, 2);
			if (count($parts) !== 2)
			{
				continue;
			}

			$old = trim($parts[0]);
			$new = trim($parts[1]);
			if ($old === '' || $new === '' || $old === $new)
			{
				continue;
			}

			$pairs[] = [
				'old_location_code' => $old,
				'new_location_code' => $new,
			];
		}
		return $pairs;
	}

}
