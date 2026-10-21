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
}