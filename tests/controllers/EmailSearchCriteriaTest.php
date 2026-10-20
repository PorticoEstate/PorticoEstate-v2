<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\email\services\EmailSearchCriteria;
	use PHPUnit\Framework\TestCase;

	class EmailSearchCriteriaTest extends TestCase
	{
		public function testBuildsEscapedCriteriaFromSupportedSearchFields(): void
		{
			$criteria = EmailSearchCriteria::normalize([
				'fldball' => ['acctnum' => '0'],
				'folder_list' => ['INBOX', 'Sent', 'INBOX'],
				'search_subject' => "release \"notes\"\nnext",
				'search_from' => 'sender@example.test',
				'flags' => ['flg_unseen', 'unsupported'],
				'date_after' => '2026-01-05',
			]);
			$this->assertNotNull($criteria);
			$this->assertSame(['INBOX', 'Sent'], $criteria['folders']);
			$this->assertSame('SUBJECT "release \\"notes\\" next" FROM "sender@example.test" UNSEEN SINCE "01/05/2026"', EmailSearchCriteria::build($criteria));
		}

		public function testEmptyCriteriaSearchesAllMessagesInSelectedFolder(): void
		{
			$criteria = EmailSearchCriteria::normalize([
				'fldball' => ['acctnum' => 0],
				'folder_list' => ['INBOX'],
				'flags' => [],
				'start' => 0,
				'search_subject' => '',
				'search_body' => '',
				'search_from' => '',
				'search_to' => '',
				'search_cc' => '',
				'search_bcc' => '',
				'search_keyword' => '',
				'date_on' => '',
				'date_before' => '',
				'date_after' => '',
			]);
			$this->assertNotNull($criteria);
			$this->assertSame(['INBOX'], $criteria['folders']);
			$this->assertSame('ALL', EmailSearchCriteria::build($criteria));
		}

		public function testRejectsMissingFoldersAndInvalidDates(): void
		{
			$this->assertNull(EmailSearchCriteria::normalize(['fldball' => ['acctnum' => 0], 'folder_list' => []]));
			$this->assertNull(EmailSearchCriteria::normalize([
				'fldball' => ['acctnum' => 0],
				'folder_list' => ['INBOX'],
				'date_on' => '2026-02-30',
			]));
		}
	}
}
