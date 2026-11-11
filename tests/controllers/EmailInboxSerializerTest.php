<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\email\services\InboxSerializer;
	use PHPUnit\Framework\TestCase;

	class EmailInboxSerializerTest extends TestCase
	{
		public function testSerializesInboxSummariesWithoutLeakingUnmappedFields(): void
		{
			$result = InboxSerializer::serialize([
				'folder_info' => [
					'folder_checked' => 'INBOX',
					'number_new' => 2,
					'number_all' => 7,
				],
				'current_start' => 5,
				'msg_list_dsp' => [[
					'acctnum' => '1',
					'folder' => 'INBOX',
					'msgnum' => '42',
					'subject' => 'Hello',
					'from_name' => 'Example Sender',
					'display_address_from' => 'sender@example.test',
					'msg_date' => '10:30',
					'size' => '2 KB',
					'is_unseen' => true,
					'has_attachment' => true,
					'subject_link' => '/index.php?menuaction=email.uimessage.message',
					'raw_headers' => ['secret' => 'not exposed'],
				]],
			]);

			$this->assertSame('INBOX', $result['folder']);
			$this->assertSame(2, $result['unseen']);
			$this->assertSame(7, $result['total']);
			$this->assertSame(5, $result['start']);
			$this->assertArrayNotHasKey('raw_headers', $result['items'][0]);
			$this->assertSame(42, $result['items'][0]['messageNumber']);
			$this->assertTrue($result['items'][0]['unseen']);
			$this->assertTrue($result['items'][0]['hasAttachments']);
		}

		public function testSerializesMissingInboxDataAsAnEmptyPage(): void
		{
			$this->assertSame([
				'items' => [],
				'folder' => '',
				'unseen' => 0,
				'total' => 0,
				'start' => 0,
				'error' => '',
			], InboxSerializer::serialize([]));
		}
	}
}