<?php

namespace App\modules\property\helpers;

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Vfs\Vfs;

class LocationHierarchyDocumentAnalyzer
{
	// Keep legacy files_moved values 0 (queued) and 1 (complete).
	private const MOVE_COMPLETE = 1;
	private const MOVE_CLEANUP = 2;
	private const MOVE_PREPARING = 3;
	private const MOVE_LOCK = 1846275207;

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
			$selections[] = [
				'old_location_code' => $oldCode,
				'new_location_code' => $this->db->f('new_location_code'),
				'files_moved' => (int) $this->db->f('files_moved'),
				'mapping_count' => (int) $this->db->f('mapping_count'),
				'selection_key' => $oldCode . '|' . $this->db->f('new_location_code'),
			];
		}

		foreach ($selections as &$selection)
		{
			$from_dir = $this->rootdir . '/' . $selection['old_location_code'];

			if ($this->vfs->file_exists(array(
				'string'	 => $from_dir,
				'relatives'	 => array(RELATIVE_NONE)
			)))
			{
				$selection['directories'] = [$from_dir];
				$selection['directory_count'] = 1;
			}
			else
			{
				$selection['directories'] = [];
				$selection['directory_count'] = 0;
			}
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
		// Keep in-progress moves selected, as well as completed history.
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

	public function executeMovesForSelectedMappings()
	{
		$results = [];
		$deadline = hrtime(true) + 10_000_000_000;
		if (!$this->moveSql('SELECT pg_try_advisory_lock(?)', [self::MOVE_LOCK])->fetchColumn())
		{
			throw new \DomainException('Another document move is running. Try again when it finishes.');
		}
		try
		{
			foreach ($this->getMappingsMarkedForMove() as $mapping)
			{
				if (hrtime(true) >= $deadline)
				{
					$results[] = $mapping + ['status' => 'paused', 'message' => 'Click Execute / Resume to continue.'];
					break;
				}
				try
				{
					$complete = $this->resumeMove($mapping['old_location_code'], $mapping['new_location_code'], $deadline);
					$results[] = $mapping + ['status' => $complete ? 'success' : 'paused',
						'message' => $complete ? 'Document move and cleanup completed.' : 'Click Execute / Resume to continue.'];
					if (!$complete)
					{
						break;
					}
				}
				catch (\DomainException $e)
				{
					$results[] = $mapping + ['status' => 'blocked', 'message' => $e->getMessage()];
				}
			}
		}
		finally
		{
			$this->moveSql('SELECT pg_advisory_unlock(?)', [self::MOVE_LOCK]);
		}
		return $results;
	}

	private function resumeMove(string $old, string $new, int $deadline): bool
	{
		foreach ([$old, $new] as $code)
		{
			if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $code))
			{
				throw new \DomainException('Unsupported location code for a document move.');
			}
		}
		$from = $this->rootdir . '/' . $old;
		$to = $this->rootdir . '/' . $new;
		$filesystem = (bool)$this->vfs->file_actions;
		$source = $this->basedir . $from;
		$target = $this->basedir . $to;
		if ($filesystem)
		{
			$base = realpath($this->basedir);
			$parent = realpath($this->basedir . $this->rootdir);
			if (!$base || $parent !== $base . $this->rootdir)
			{
				throw new \DomainException('The document root is missing or is a symbolic link.');
			}
		}
		foreach ([$from => $source, $to => $target] as $path => $physicalPath)
		{
			$parts = $this->vfs->path_parts(['string' => $path, 'relatives' => [RELATIVE_NONE]]);
			if ($parts->outside || $parts->real_full_path !== $physicalPath)
			{
				throw new \DomainException('Linked VFS locations cannot be moved by this tool.');
			}
		}

		$files = new LocationDocumentMoveFiles();
		$this->db->beginTransaction();
		try
		{
			$states = $this->moveSql('SELECT COALESCE(files_moved, 0) FROM location_mapping
				WHERE old_location_code = ? AND new_location_code = ? AND files_to_move = 1 FOR UPDATE',
				[$old, $new])->fetchAll(\PDO::FETCH_COLUMN);
			$unfinished = array_diff(array_map('intval', $states), [self::MOVE_COMPLETE]);
			if (!$unfinished)
			{
				$this->db->commit();
				return true;
			}
			$this->moveSql('LOCK TABLE phpgw_vfs IN SHARE ROW EXCLUSIVE MODE NOWAIT');
			if (in_array(self::MOVE_CLEANUP, $unfinished, true))
			{
				if ($filesystem && !$this->moveSql("SELECT 1 FROM phpgw_vfs
					WHERE directory = ? AND name = ? AND mime_type = 'Directory'", [$this->rootdir, $new])->fetchColumn())
				{
					throw new \DomainException("Destination VFS directory is missing: {$to}");
				}
				$complete = !$filesystem || $files->cleanup($source, $target, $deadline);
				if ($complete)
				{
					$this->setMoveState($old, $new, self::MOVE_COMPLETE);
				}
				$this->db->commit();
				return $complete;
			}
			if ($this->moveSql('SELECT 1 FROM location_mapping WHERE files_to_move = 1
				AND (old_location_code <> ? OR new_location_code <> ?)
				AND ((COALESCE(files_moved, 0) <> 1 AND (old_location_code IN (?, ?) OR new_location_code = ?))
					OR (files_moved IN (2, 3) AND
						(old_location_code IN (?, ?) OR new_location_code IN (?, ?))))',
				[$old, $new, $old, $new, $old, $old, $new, $old, $new])->fetchColumn())
			{
				throw new \DomainException('This move overlaps another selected move. Finish the active move or deselect conflicting mappings.');
			}
			$plan = $this->planVfsMove($old, $new);
			// Persist the preparation phase before the first filesystem change.
			if ($filesystem && !in_array(self::MOVE_PREPARING, $unfinished, true))
			{
				if (hrtime(true) >= $deadline)
				{
					$this->db->commit();
					return false;
				}
				$this->setMoveState($old, $new, self::MOVE_PREPARING);
				$this->db->commit();
				return $this->resumeMove($old, $new, $deadline);
			}
			if ($filesystem && !$files->prepare($source, $target, $deadline))
			{
				$this->db->commit();
				return false;
			}
			foreach ($plan as [$id, $directory, $name])
			{
				if ($directory === null)
				{
					$this->moveSql('DELETE FROM phpgw_vfs WHERE file_id = ?', [$id]);
				}
				else
				{
					$this->moveSql('UPDATE phpgw_vfs SET directory = ?, name = ? WHERE file_id = ?', [$directory, $name, $id]);
				}
			}
			$this->setMoveState($old, $new, $filesystem ? self::MOVE_CLEANUP : self::MOVE_COMPLETE);
			$this->db->commit();
			return !$filesystem || $this->resumeMove($old, $new, $deadline);
		}
		finally
		{
			if ($this->db->inTransaction())
			{
				$this->db->rollBack();
			}
		}
	}

	private function planVfsMove(string $old, string $new): array
	{
		$from = $this->rootdir . '/' . $old;
		$to = $this->rootdir . '/' . $new;
		$read = fn($code, $path) => $this->moveSql("SELECT file_id, directory, name, mime_type, link_directory, link_name
			FROM phpgw_vfs WHERE (directory = ? AND name = ?) OR directory = ? OR directory LIKE ? ESCAPE '!'",
			[$this->rootdir, $code, $path, str_replace('_', '!_', $path) . '/%'])->fetchAll(\PDO::FETCH_ASSOC);
		$existing = [];
		foreach ($read($new, $to) as $row)
		{
			if (!in_array($row['mime_type'], ['journal', 'journal-deleted'], true))
			{
				if ($row['link_directory'] || $row['link_name'])
				{
					throw new \DomainException("Linked destination VFS path: {$row['directory']}/{$row['name']}");
				}
				$existing[$row['directory'] . '/' . $row['name']][] = $row;
			}
		}
		$plan = [];
		$hasRoot = false;
		$seen = [];
		foreach ($read($old, $from) as $row)
		{
			$path = $row['directory'] . '/' . $row['name'];
			$isRoot = $path === $from;
			$isDirectory = $row['mime_type'] === 'Directory';
			$isJournal = in_array($row['mime_type'], ['journal', 'journal-deleted'], true);
			if ($isRoot && $isJournal)
			{
				continue;
			}
			if ($row['link_directory'] || $row['link_name'] || (!$isJournal && isset($seen[$path])))
			{
				throw new \DomainException("Linked or duplicate VFS path: {$path}");
			}
			if ($isRoot)
			{
				$hasRoot = $isDirectory;
			}
			$directory = $isRoot ? $this->rootdir : $to . substr($row['directory'], strlen($from));
			$name = $isRoot ? $new : $row['name'];
			if (!$isJournal)
			{
				$seen[$path] = true;
				$matches = $existing[$directory . '/' . $name] ?? [];
				if ($matches)
				{
					if (count($matches) !== 1 || !$isDirectory || $matches[0]['mime_type'] !== 'Directory'
						|| $matches[0]['link_directory'] || $matches[0]['link_name'])
					{
						throw new \DomainException("Destination VFS path conflict: {$directory}/{$name}");
					}
					$directory = null;
				}
				if (!$isDirectory && $this->vfs->file_actions)
				{
					$physical = $this->basedir . str_replace("''", "'", $path);
					if (!is_file($physical) || is_link($physical) || realpath($physical) !== realpath($this->basedir) . substr($physical, strlen($this->basedir)))
					{
						throw new \DomainException("VFS source file is missing or linked: {$path}. Check for an interrupted legacy move.");
					}
				}
			}
			$plan[] = [$row['file_id'], $directory, $name];
		}
		if (!$hasRoot)
		{
			throw new \DomainException("Source VFS directory is missing: {$from}. Check for an interrupted legacy move.");
		}
		return $plan;
	}

	private function moveSql(string $sql, array $params = []): \PDOStatement
	{
		$stmt = $this->db->prepare($sql);
		if (!$stmt || !$stmt->execute($params))
		{
			throw new \RuntimeException('Document move database operation failed.');
		}
		return $stmt;
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
		$sql = "SELECT old_location_code, new_location_code
			FROM location_mapping
			WHERE COALESCE(files_to_move, 0) = 1
				AND COALESCE(files_moved, 0) <> 1
				AND COALESCE(old_location_code, '') <> ''
				AND COALESCE(new_location_code, '') <> ''
				AND old_location_code <> new_location_code
			GROUP BY old_location_code, new_location_code
			ORDER BY MIN(CASE files_moved WHEN 2 THEN 0 WHEN 3 THEN 1 ELSE 2 END), old_location_code, new_location_code";

		return $this->moveSql($sql)->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function setMoveState(string $old, string $new, int $state): void
	{
		$this->moveSql('UPDATE location_mapping SET files_moved = ?, update_timestamp = NOW()
			WHERE old_location_code = ? AND new_location_code = ? AND files_to_move = 1 AND COALESCE(files_moved, 0) <> 1',
			[$state, $old, $new]);
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
