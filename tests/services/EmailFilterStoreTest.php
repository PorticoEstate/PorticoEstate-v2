<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailFilterStore;
use PHPUnit\Framework\TestCase;

class EmailFilterStoreTest extends TestCase
{
	public function testReadsFilterForEditorAndDecodesUserText(): void
	{
		$mail = $this->mail([[
			'filtername' => 'Invoices &amp; receipts',
			'source_accounts' => [['acctnum' => 1, 'folder' => 'INBOX']],
			'matches' => [['examine' => 'subject', 'comparator' => 'contains', 'matchthis' => 'R&amp;D']],
			'actions' => [['judgement' => 'fileinto', 'folder' => 'folder=Archive&acctnum=1', 'stop_filtering' => 'True']],
		]]);
		$filter = (new EmailFilterStore())->editor($mail, '0');
		$this->assertSame('Invoices & receipts', $filter['name']);
		$this->assertSame([1], $filter['sourceAccounts']);
		$this->assertSame('R&D', $filter['matches'][0]['matchthis']);
		$this->assertSame(['judgement' => 'fileinto', 'account' => 1, 'folder' => 'Archive', 'actiontext' => '', 'stopFiltering' => true], $filter['action']);
		$this->assertNull((new EmailFilterStore())->editor($mail, 'add_new'));
	}

	public function testSavesAndReordersFiltersUsingPreferencePaths(): void
	{
		$mail = $this->mail([]);
		$preferences = $this->preferences();
		$store = new EmailFilterStore();
		$id = $store->save($mail, [
			'name' => 'R&D',
			'sourceAccounts' => [0, 2],
			'matches' => [
				['examine' => 'subject', 'comparator' => 'contains', 'matchthis' => 'R&D', 'andor' => ''],
				['examine' => 'from', 'comparator' => 'notcontains', 'matchthis' => 'spam', 'andor' => 'and'],
				['examine' => 'to', 'comparator' => 'contains', 'matchthis' => 'ignored', 'andor' => 'ignore_me'],
			],
			'action' => ['account' => 2, 'folder' => 'Projects.Archive', 'actiontext' => '', 'stopFiltering' => true],
		], 'add_new', $preferences);
		$this->assertSame(0, $id);
		$this->assertContains(['add', 'email', '["filters"][0]["filtername"]', 'R&amp;D'], $preferences->writes);
		$this->assertContains(['add', 'email', '["filters"][0]["matches"][1]["andor"]', 'and'], $preferences->writes);
		$this->assertContains(['add', 'email', '["filters"][0]["actions"][0]["folder"]', 'folder=Projects.Archive&acctnum=2'], $preferences->writes);
		$this->assertSame(' ', $mail->so->invalidated);
		$editor = $store->editor($mail, (string) $id);
		$this->assertSame('R&D', $editor['name']);
		$this->assertSame('R&D', $editor['matches'][0]['matchthis']);
		$this->assertSame('Projects.Archive', $editor['action']['folder']);

		$mail->raw_filters = [
			['filtername' => 'One', 'source_accounts' => [[]], 'matches' => [], 'actions' => []],
			['filtername' => 'Two', 'source_accounts' => [[]], 'matches' => [], 'actions' => []],
			['filtername' => 'Three', 'source_accounts' => [[]], 'matches' => [], 'actions' => []],
		];
		$preferences = $this->preferences();
		$this->assertTrue($store->action($mail, 'move-up', 2, $preferences));
		$this->assertSame(['One', 'Three', 'Two'], $this->filterNames($preferences->writes));
		$preferences = $this->preferences();
		$this->assertTrue($store->action($mail, 'delete', 1, $preferences));
		$this->assertSame(['One', 'Two'], $this->filterNames($preferences->writes));
	}

	public function testRejectsMissingFilterAndOutOfRangeMoves(): void
	{
		$mail = $this->mail([]);
		$store = new EmailFilterStore();
		$this->assertFalse($store->action($mail, 'delete', 0, $this->preferences()));
		$mail->raw_filters = [['source_accounts' => [[]], 'filtername' => 'Only', 'matches' => [], 'actions' => []]];
		$this->assertFalse($store->action($mail, 'move-up', 0, $this->preferences()));
		$this->assertFalse($store->action($mail, 'move-down', 0, $this->preferences()));
	}

	private function mail(array $filters): object
	{
		return new class($filters)
		{
			public object $so;
			public function __construct(public array $raw_filters)
			{
				$this->so = new class { public string $invalidated = ''; public function so_appsession_passthru($key, $value): void { $this->invalidated = $value; } };
			}
			public function html_quotes_encode($text): string { return str_replace(['&', '"', "'"], ['&amp;', '&quot;', '&#039;'], $text); }
			public function html_quotes_decode($text): string { return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
		};
	}

	private function preferences(): object
	{
		return new class
		{
			public array $writes = [];
			public function delete_struct($app, $path): void { $this->writes[] = ['delete', $app, $path]; }
			public function add_struct($app, $path, $value): void { $this->writes[] = ['add', $app, $path, $value]; }
			public function save_repository(): void { $this->writes[] = ['save']; }
		};
	}

	private function filterNames(array $writes): array
	{
		$result = [];
		foreach ($writes as $write)
		{
			if (($write[0] ?? '') === 'add' && preg_match('/^\["filters"\]\[(\d+)\]\["filtername"\]$/', (string) ($write[2] ?? ''), $match))
			{
				$result[(int) $match[1]] = (string) $write[3];
			}
		}
		ksort($result);
		return array_values($result);
	}
}