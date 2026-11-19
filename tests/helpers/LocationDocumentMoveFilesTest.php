<?php

use App\modules\property\helpers\LocationDocumentMoveFiles;
use PHPUnit\Framework\TestCase;

final class LocationDocumentMoveFilesTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/portico-move-' . bin2hex(random_bytes(8));
		mkdir($this->root . '/old/sub', 0700, true);
		file_put_contents($this->root . '/old/a', 'first');
		file_put_contents($this->root . '/old/sub/b', 'second');
	}

	protected function tearDown(): void
	{
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $entry)
		{
			$entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($this->root);
	}

	public function testInterruptedPreparationAndCleanupCanBeRetried(): void
	{
		$old = $this->root . '/old';
		$new = $this->root . '/new';
		mkdir($new);
		link($old . '/a', $new . '/a');
		$mover = new LocationDocumentMoveFiles();
		$this->assertTrue($mover->prepare($old, $new, PHP_INT_MAX));
		$this->assertFileExists($old . '/sub/b');
		$this->assertSame('second', file_get_contents($new . '/sub/b'));

		unlink($old . '/a');
		$this->assertTrue((new LocationDocumentMoveFiles())->cleanup($old, $new, PHP_INT_MAX));
		$this->assertDirectoryDoesNotExist($old);
		$this->assertSame('first', file_get_contents($new . '/a'));
		$this->assertTrue($mover->cleanup($old, $new, PHP_INT_MAX));
	}

	public function testConflictNeverOverwritesDestinationOrDeletesSource(): void
	{
		$old = $this->root . '/old';
		$new = $this->root . '/new';
		mkdir($new);
		file_put_contents($new . '/a', 'unrelated');
		$mover = new LocationDocumentMoveFiles();
		foreach (['prepare', 'cleanup'] as $phase)
		{
			try
			{
				$mover->$phase($old, $new, PHP_INT_MAX);
				$this->fail('A conflicting file must block the move.');
			}
			catch (DomainException $e)
			{
				$this->assertStringContainsString('conflict', $e->getMessage());
			}
		}
		$this->assertSame('first', file_get_contents($old . '/a'));
		$this->assertSame('unrelated', file_get_contents($new . '/a'));
	}

	public function testExpiredRequestPausesBeforeChangingFiles(): void
	{
		$old = $this->root . '/old';
		$new = $this->root . '/new';
		$mover = new LocationDocumentMoveFiles();
		$this->assertFalse($mover->prepare($old, $new, 0));
		$this->assertDirectoryDoesNotExist($new);
		$this->assertTrue($mover->prepare($old, $new, PHP_INT_MAX));
		$this->assertFalse($mover->cleanup($old, $new, 0));
		$this->assertFileExists($old . '/a');
		$this->assertFileExists($new . '/a');
	}
}
