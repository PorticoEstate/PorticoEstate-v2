<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailFilterMatcher;
use App\modules\email\services\EmailFilterRunner;
use PHPUnit\Framework\TestCase;

class EmailFilterRunnerTest extends TestCase
{
	public function testMatcherPreservesLegacyTwoAndThreeRowGrouping(): void
	{
		$matcher = new EmailFilterMatcher();
		$headers = ['subject' => 'invoice', 'from' => 'buyer@example.test'];
		$rows = [
			['examine' => 'subject', 'comparator' => 'contains', 'matchthis' => 'invoice'],
			['examine' => 'from', 'comparator' => 'contains', 'matchthis' => 'boss@example.test', 'andor' => 'or'],
			['examine' => 'to', 'comparator' => 'contains', 'matchthis' => 'nobody@example.test', 'andor' => 'or'],
		];
		$this->assertTrue($matcher->matches(['matches' => $rows], $headers));
		$rows[1]['andor'] = 'and';
		$rows[2]['andor'] = 'or';
		$this->assertFalse($matcher->matches(['matches' => $rows], $headers));
		$rows = array_slice($rows, 0, 2);
		$this->assertFalse($matcher->matches(['matches' => $rows], $headers));
	}

	public function testTestModeReportsMatchesWithoutMutatingMessages(): void
	{
		$mail = new class
		{
			public array $extra_and_default_acounts = [['acctnum' => 0, 'status' => 'enabled']];
			public int $headerFetches = 0;
			public function get_folder_list($account, $refresh): array { return [['folder_long' => 'INBOX'], ['folder_long' => 'Archive']]; }
			public function prep_folder_out($folder): string { return urlencode($folder); }
			public function get_msgball_list_oldschool($account, $folder, $cache, $display): array { return [['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 7]]; }
			public function phpgw_fetchheader($message, $display): string { $this->headerFetches++; return "Subject: invoice\r\nFrom: buyer@example.test\r\nTo: accounts@example.test\r\n\r\n"; }
			public function decode_rfc_header_glob($headers): string { return $headers; }
			public function html_quotes_decode($value): string { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
			public function prep_folder_in($folder, $account): string { return urldecode($folder); }
		};
		$filters = [[
			'filtername' => 'Invoice',
			'source_accounts' => [['acctnum' => 0, 'folder' => 'INBOX']],
			'matches' => [['examine' => 'subject', 'comparator' => 'contains', 'matchthis' => 'invoice']],
			'actions' => [['judgement' => 'fileinto', 'folder' => 'folder=Archive&acctnum=0']],
		]];
		$result = (new EmailFilterRunner())->run($mail, $filters, true);
		$this->assertSame(1, $result['items'][0]['matchCount']);
		$this->assertTrue($result['tested']);
		$this->assertFalse($result['didExpunge']);
		$this->assertSame('Invoice: 1 matches', $result['matchReport']);
		$this->assertSame(1, $mail->headerFetches);
	}

	public function testApplyModeSendsOnlyMatchedMessagesToMoveExecutor(): void
	{
		$mail = new class
		{
			public array $extra_and_default_acounts = [['acctnum' => 0, 'status' => 'enabled']];
			public function get_folder_list($account, $refresh): array { return [['folder_long' => 'INBOX'], ['folder_long' => 'Archive']]; }
			public function prep_folder_out($folder): string { return urlencode($folder); }
			public function get_msgball_list_oldschool($account, $folder, $cache, $display): array
			{
				return [
					['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 4],
					['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 5],
				];
			}
			public function phpgw_fetchheader($message, $display): string
			{
				return $message['msgnum'] === 4 ? "Subject: invoice\r\n\r\n" : "Subject: newsletter\r\n\r\n";
			}
			public function decode_rfc_header_glob($headers): string { return $headers; }
			public function html_quotes_decode($value): string { return $value; }
			public function prep_folder_in($folder, $account): string { return urldecode($folder); }
	};
		$filters = [[
			'filtername' => 'Invoices',
			'source_accounts' => [['acctnum' => 0, 'folder' => 'INBOX']],
			'matches' => [['examine' => 'subject', 'comparator' => 'contains', 'matchthis' => 'invoice']],
			'actions' => [['judgement' => 'fileinto', 'folder' => 'folder=Archive&acctnum=0']],
		]];
		$moveRequests = [];
		$runner = new EmailFilterRunner(new EmailFilterMatcher(), static function ($mail, array $request) use (&$moveRequests): array {
			$moveRequests[] = $request;
			return ['completed' => $request['messages'], 'failed' => []];
		});
		$result = $runner->run($mail, $filters, false);
		$this->assertCount(1, $moveRequests);
		$this->assertSame([['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 4]], $moveRequests[0]['messages']);
		$this->assertSame(['acctnum' => 0, 'folder' => 'Archive'], $moveRequests[0]['target']);
		$this->assertTrue($result['didExpunge']);
		$this->assertFalse($result['tested']);
	}
}