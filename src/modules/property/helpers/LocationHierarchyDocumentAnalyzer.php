<?php

namespace App\modules\property\helpers;

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;

class LocationHierarchyDocumentAnalyzer
{
	private $db;
	private $basedir;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$serverSettings  = Settings::getInstance()->get('server');
		$this->basedir = isset($serverSettings['files_dir']) ? rtrim($serverSettings['files_dir'], '/\\') : '';
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
	 * Persist checkbox selection into location_mapping.files_to_move.
	 *
	 * @param array $selectedKeys values in format "old_location_code|new_location_code"
	 */
	public function updateFilesToMoveSelection(array $selectedKeys)
	{
		$selectedPairs = $this->parseSelectedMappingKeys($selectedKeys);

		// Reset current selection for all relevant mappings first.
		$sqlReset = "UPDATE location_mapping
			SET files_to_move = 0
			WHERE COALESCE(old_location_code, '') <> ''
				AND COALESCE(new_location_code, '') <> ''
				AND old_location_code <> new_location_code";
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
		foreach ($this->getMappingsMarkedForMove() as $mapping)
		{
			$oldCode = $mapping['old_location_code'];
			$newCode = $mapping['new_location_code'];
			$directories = $this->findDirectoriesForLocationCode($oldCode);

			$this->db->transaction_begin();
			try
			{
				$updatedRows = 0;
				$movedDirectories = 0;

				foreach ($directories as $directory)
				{
					$newDirectory = str_replace($oldCode, $newCode, $directory);
					if ($newDirectory === $directory)
					{
						continue;
					}

					$oldAbsolute = $this->toAbsolutePath($directory);
					$newAbsolute = $this->toAbsolutePath($newDirectory);

					$filesystemMoveSucceeded = false;
					$filesystemPathExists = is_dir($oldAbsolute) || is_file($oldAbsolute);

					if ($filesystemPathExists)
					{
						if (!$this->movePath($oldAbsolute, $newAbsolute))
						{
							throw new \RuntimeException("Failed to move path '{$oldAbsolute}' to '{$newAbsolute}'");
						}
						$movedDirectories++;
						$filesystemMoveSucceeded = true;
					}

					// Only update VFS if filesystem move succeeded or no filesystem path existed
					if ($filesystemMoveSucceeded || !$filesystemPathExists)
					{
						if (!$this->updateDirectory($directory, $newDirectory))
						{
							throw new \RuntimeException("Failed to update VFS directory '{$directory}'");
						}
						$updatedRows++;
					}
				}

				$this->markFilesMoved($oldCode, $newCode);

				if ($this->db->get_transaction())
				{
					$this->db->transaction_commit();
				}

				$results[] = [
					'old_location_code' => $oldCode,
					'new_location_code' => $newCode,
					'status' => 'success',
					'updated_rows' => $updatedRows,
					'moved_directories' => $movedDirectories,
					'message' => $updatedRows ? 'Location code move completed' : 'No matching directories found; mapping marked as moved',
				];
			}
			catch (\Throwable $e)
			{
				$this->db->transaction_abort();
				$results[] = [
					'old_location_code' => $oldCode,
					'new_location_code' => $newCode,
					'status' => 'error',
					'updated_rows' => 0,
					'moved_directories' => 0,
					'message' => $e->getMessage(),
				];
			}
		}

		return $results;
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

		$this->db->query($sql, __LINE__, __FILE__);
		$directories = [];
		while ($this->db->next_record())
		{
			$directories[] = $this->db->f('directory');
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

	private function toAbsolutePath($vfsDirectory)
	{
		if (!$this->basedir)
		{
			return $vfsDirectory;
		}

		$relative = ltrim((string) $vfsDirectory, '/\\');
		return $this->basedir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
	}

	private function movePath($oldPath, $newPath)
	{
		$newParent = dirname($newPath);
		if (!is_dir($newParent) && !mkdir($newParent, 0777, true))
		{
			return false;
		}

		if (!file_exists($newPath))
		{
			return @rename($oldPath, $newPath);
		}

		if (is_dir($oldPath) && is_dir($newPath))
		{
			if (!$this->moveDirectoryContents($oldPath, $newPath))
			{
				return false;
			}
			@rmdir($oldPath);
			return true;
		}

		return false;
	}

	private function updateDirectory($oldDirectory, $newDirectory)
	{
		$oldEscaped = addslashes($oldDirectory);
		$newEscaped = addslashes($newDirectory);

		$sql = "UPDATE phpgw_vfs
			SET directory = '{$newEscaped}'
			WHERE directory = '{$oldEscaped}'";
		$this->db->query($sql, __LINE__, __FILE__);

		return $this->db->affected_rows() > 0;
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
	private function moveDirectoryContents($oldDir, $newDir)
	{
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
				if (!$this->moveDirectoryContents($oldPath, $newPath))
				{
					return false;
				}
				if (is_dir($oldPath) && !@rmdir($oldPath))
				{
					return false;
				}
			}
			else
			{
				if (!@rename($oldPath, $newPath))
				{
					return false;
				}
			}
		}
		return true;
	}
}
