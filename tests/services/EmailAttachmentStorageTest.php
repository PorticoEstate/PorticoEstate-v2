<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailAttachmentStorage;
use PHPUnit\Framework\TestCase;

class EmailAttachmentStorageTest extends TestCase
{
	private string $directory;
	private EmailAttachmentStorage $storage;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/email-attachment-test-' . bin2hex(random_bytes(8));
		$this->storage = new EmailAttachmentStorage($this->directory);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->directory . '/*') ?: [] as $path) unlink($path);
		rmdir($this->directory);
	}

	public function testListsAttachmentsFromPerSessionMetadata(): void
	{
		$id = str_repeat('a', 32);
		file_put_contents($this->directory . '/' . $id, 'attachment bytes');
		file_put_contents($this->directory . '/' . $id . '.info', "text/plain\nreport.txt\n");
		file_put_contents($this->directory . '/' . str_repeat('b', 32) . '.info', "text/plain\norphan.txt\n");
		file_put_contents($this->directory . '/invalid.info', "text/plain\nignored.txt\n");
		$this->assertSame([['id' => $id, 'name' => 'report.txt', 'mimeType' => 'text/plain', 'size' => 16]], $this->storage->list());
	}

	public function testDeletesAttachmentAndMetadataIdempotently(): void
	{
		$id = str_repeat('c', 32);
		file_put_contents($this->directory . '/' . $id, 'attachment');
		file_put_contents($this->directory . '/' . $id . '.info', "text/plain\nreport.txt\n");
		$this->assertSame([], $this->storage->delete([$id]));
		$this->assertSame([], $this->storage->delete([$id]));
		$this->assertFileDoesNotExist($this->directory . '/' . $id);
		$this->assertFileDoesNotExist($this->directory . '/' . $id . '.info');
	}

	public function testRejectsAttachmentPathTraversal(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->storage->delete(['../outside']);
	}
}