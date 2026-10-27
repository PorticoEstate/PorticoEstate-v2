<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\email\services\EmailFolderSerializer;
	use PHPUnit\Framework\TestCase;

	class EmailFolderSerializerTest extends TestCase
	{
		public function testSerializesFolderNamesAndNumericCountsOnly(): void
		{
			$this->assertSame([[
				'name' => 'Sent',
				'longName' => 'user/Sent',
				'folderParam' => 'user%2FSent',
				'account' => 2,
				'unseen' => 1,
				'total' => 8,
			]], EmailFolderSerializer::serialize([[
				'folder_short' => 'Sent',
				'folder_long' => 'user/Sent',
				'folder_param' => 'user%2FSent',
				'acctnum' => '2',
				'unseen_count' => '1',
				'total_count' => '8',
				'folder_link' => '/index.php?menuaction=email.uiindex.index',
			]]));
		}
	}
}