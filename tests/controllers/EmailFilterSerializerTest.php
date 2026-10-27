<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	use App\modules\email\services\EmailFilterSerializer;
	use PHPUnit\Framework\TestCase;

	class EmailFilterSerializerTest extends TestCase
	{
		public function testSerializesOrderedFilterSummariesAndLegacyActionLinks(): void
		{
			$result = EmailFilterSerializer::serialize([[
				'filtername' => 'Invoices &amp; receipts',
				'source_accounts' => [['acctnum' => 0], ['acctnum' => 1]],
				'matches' => [['examine' => 'from']],
				'actions' => [['action' => 'move']],
			]]);

			$this->assertSame('Invoices & receipts', $result[0]['name']);
			$this->assertSame(0, $result[0]['id']);
			$this->assertSame(2, $result[0]['sourceCount']);
			$this->assertSame(1, $result[0]['matchCount']);
			$this->assertSame(1, $result[0]['actionCount']);
		}
	}
}
