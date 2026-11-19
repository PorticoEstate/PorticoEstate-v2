<?php

use App\Database\Db;
use App\modules\property\helpers\LocationHierarchyDocumentAnalyzer;
use PHPUnit\Framework\TestCase;

final class LocationDocumentMoveTest extends TestCase
{
	public function testRetryAfterMetadataRollbackAndResumeCommittedCleanup(): void
	{
		$domain = getenv('BOEI_TEST_DOMAIN');
		if (!$domain)
		{
			$this->markTestSkipped('Set BOEI_TEST_DOMAIN to run against temporary PostgreSQL tables.');
		}
		$settings = require __DIR__ . '/../../config/header.inc.php';
		$config = $settings['phpgw_domain'][$domain];
		$db = new PDO(Db::CreateDsn($config), $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$db->exec("SET search_path = pg_temp;
			CREATE TEMP TABLE location_mapping (old_location_code text, new_location_code text,
				files_to_move smallint, files_moved smallint, update_timestamp timestamp,
				CONSTRAINT simulate_failure CHECK (files_moved <> 2));
			CREATE TEMP TABLE phpgw_vfs (file_id int PRIMARY KEY, directory text, name text,
				mime_type text, link_directory text, link_name text);
			INSERT INTO location_mapping VALUES ('old', 'new', 1, 0);
			INSERT INTO phpgw_vfs VALUES
				(1, '/property/document', 'old', 'Directory'),
				(2, '/property/document/old', 'sub', 'Directory'),
				(3, '/property/document/old/sub', 'a', 'text/plain'),
				(4, '/property/document', 'new', 'Directory'),
				(5, '/property/document/new', 'sub', 'Directory')");

		$root = sys_get_temp_dir() . '/portico-vfs-' . bin2hex(random_bytes(8));
		$source = $root . '/property/document/old/sub/a';
		$target = $root . '/property/document/new/sub/a';
		mkdir(dirname($source), 0700, true);
		mkdir(dirname($target), 0700, true);
		file_put_contents($source, 'document');
		try
		{
			if (!defined('RELATIVE_NONE'))
			{
				define('RELATIVE_NONE', 32);
			}
			$vfs = new class($root) {
				public bool $file_actions = true;
				public function __construct(private string $root) {}
				public function path_parts(array $data): object
				{
					return (object)['outside' => false, 'real_full_path' => $this->root . $data['string']];
				}
			};
			$type = new ReflectionClass(LocationHierarchyDocumentAnalyzer::class);
			$analyzer = $type->newInstanceWithoutConstructor();
			foreach (['db' => $db, 'vfs' => $vfs, 'basedir' => $root, 'rootdir' => '/property/document'] as $name => $value)
			{
				$type->getProperty($name)->setValue($analyzer, $value);
			}

			try
			{
				$analyzer->executeMovesForSelectedMappings();
				$this->fail('The simulated metadata commit failure must surface.');
			}
			catch (PDOException $e)
			{
				$this->assertSame('23514', $e->getCode());
			}
			$this->assertSame(3, (int)$db->query('SELECT files_moved FROM location_mapping')->fetchColumn());
			$this->assertSame('/property/document/old/sub', $db->query('SELECT directory FROM phpgw_vfs WHERE file_id = 3')->fetchColumn());
			$this->assertFileExists($source);
			$this->assertSame('document', file_get_contents($target));

			$db->exec('ALTER TABLE location_mapping DROP CONSTRAINT simulate_failure,
				ADD CONSTRAINT simulate_cleanup_failure CHECK (files_moved <> 1)');
			try
			{
				$analyzer->executeMovesForSelectedMappings();
				$this->fail('The simulated cleanup checkpoint failure must surface.');
			}
			catch (PDOException $e)
			{
				$this->assertSame('23514', $e->getCode());
			}
			$this->assertSame(2, (int)$db->query('SELECT files_moved FROM location_mapping')->fetchColumn());
			$this->assertSame('/property/document/new/sub', $db->query('SELECT directory FROM phpgw_vfs WHERE file_id = 3')->fetchColumn());
			$this->assertSame([3, 4, 5], array_map('intval', $db->query('SELECT file_id FROM phpgw_vfs ORDER BY file_id')->fetchAll(PDO::FETCH_COLUMN)));

			$db->exec('ALTER TABLE location_mapping DROP CONSTRAINT simulate_cleanup_failure');
			$this->assertSame('success', $analyzer->executeMovesForSelectedMappings()[0]['status']);
			$this->assertSame(1, (int)$db->query('SELECT files_moved FROM location_mapping')->fetchColumn());
			$this->assertFileDoesNotExist($source);
			$this->assertSame('document', file_get_contents($target));
			$this->assertSame([], $analyzer->executeMovesForSelectedMappings());
		}
		finally
		{
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST) as $entry)
			{
				$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
			}
			rmdir($root);
		}
	}
}
