<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailSpellcheckService;
use PHPUnit\Framework\TestCase;

class EmailSpellcheckServiceTest extends TestCase
{
	public function testReturnsAvailabilityAndIssueEnvelope(): void
	{
		$result = (new EmailSpellcheckService())->check("A short sentence.\n> A quoted line", 'en');
		$this->assertSame(EmailSpellcheckService::available(), $result['available']);
		$this->assertIsArray($result['issues']);
		if (!$result['available']) $this->assertSame([], $result['issues']);
	}

	public function testSuggestsCorrectionsAndSkipsQuotedLinesWhenDictionaryExists(): void
	{
		if (!EmailSpellcheckService::available('en')) $this->markTestSkipped('English pspell dictionary is unavailable');
		$result = (new EmailSpellcheckService())->check("This has a mispeling.\n> Quoted mispeling", 'en');
		$this->assertTrue($result['available']);
		$this->assertCount(1, $result['issues']);
		$this->assertSame('mispeling', $result['issues'][0]['word']);
		$this->assertContains('misspelling', $result['issues'][0]['suggestions']);
	}
}