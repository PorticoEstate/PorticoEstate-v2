<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailFolderActions;
use PHPUnit\Framework\TestCase;

class EmailFolderActionsTest extends TestCase
{
	public function testCreatesTopLevelAndNestedFoldersWithoutNamespaceGuessing(): void
	{
		$mail = $this->mail(['INBOX', 'Projects']);
		$service = new EmailFolderActions();
		$result = $service->execute($mail, ['action' => 'create', 'target_fldball' => ['acctnum' => 0, 'folder' => 'Archive'], 'parent_folder' => '']);
		$this->assertSame('Archive', $result['folder']);
		$this->assertSame('Archive', $mail->created[0]['folder']);

		$mail = $this->mail(['INBOX', 'Projects']);
		$result = $service->execute($mail, ['action' => 'create', 'target_fldball' => ['acctnum' => 0, 'folder' => 'Reports'], 'parent_folder' => 'Projects']);
		$this->assertSame('Projects.Reports', $result['folder']);
		$this->assertSame('Projects.Reports', $mail->created[0]['folder']);
	}

	public function testRenamesNestedFolderWithinItsCurrentParent(): void
	{
		$mail = $this->mail(['INBOX', 'Projects', 'Projects.Old']);
		$result = (new EmailFolderActions())->execute($mail, [
			'action' => 'rename',
			'target_fldball' => ['acctnum' => 0, 'folder' => 'New'],
			'source_fldball' => ['acctnum' => 0, 'folder' => 'Projects.Old'],
		]);
		$this->assertSame('Projects.New', $result['folder']);
		$this->assertSame('Projects.Old', $mail->renamed[0]['folder']);
		$this->assertSame('Projects.New', $mail->renamed[1]['folder']);
	}

	public function testDeletesOnlyAnExistingFolder(): void
	{
		$mail = $this->mail(['INBOX', 'Archive']);
		$result = (new EmailFolderActions())->execute($mail, ['action' => 'delete', 'target_fldball' => ['acctnum' => 0, 'folder' => 'Archive']]);
		$this->assertSame('Archive', $result['folder']);
		$this->assertSame('Archive', $mail->deleted[0]['folder']);
	}

	public function testRejectsInvalidParentAndFolderPaths(): void
	{
		$mail = $this->mail(['INBOX', 'Projects']);
		$this->expectException(\InvalidArgumentException::class);
		(new EmailFolderActions())->execute($mail, ['action' => 'create', 'target_fldball' => ['acctnum' => 0, 'folder' => 'Child'], 'parent_folder' => 'Missing']);
	}

	private function mail(array $folders): object
	{
		return new class($folders)
		{
			public array $extra_and_default_acounts = [['acctnum' => 0, 'status' => 'enabled']];
			public array $created = [];
			public array $deleted = [];
			public array $renamed = [];
			public array $expired = [];
			public function __construct(private array $folders) {}
			public function get_folder_list($account, $refresh): array { return array_map(static fn($folder) => ['folder_long' => $folder], $this->folders); }
			public function get_arg_value($name, $account): string { return $name === 'mailsvr_delimiter' ? '.' : ''; }
			public function get_folder_long($folder): string { return 'INBOX.' . $folder; }
			public function prep_folder_out($folder): string { return $folder; }
			public function phpgw_createmailbox_ex($folder, $display): bool { $this->created[] = $folder; return true; }
			public function phpgw_deletemailbox_ex($folder, $display): bool { $this->deleted[] = $folder; return true; }
			public function phpgw_renamemailbox_ex($source, $target, $display): bool { $this->renamed = [$source, $target]; return true; }
			public function phpgw_server_last_error(): string { return ''; }
			public function expire_session_cache_item($key, $account): void { $this->expired[] = [$key, $account]; }
		};
	}
}