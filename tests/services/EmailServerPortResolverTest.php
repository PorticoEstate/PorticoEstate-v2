<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailServerPortResolver;
use PHPUnit\Framework\TestCase;

class EmailServerPortResolverTest extends TestCase
{
	public function testAccountPortTakesPrecedenceOverProfileAndProtocolDefaults(): void
	{
		$this->assertSame(1143, EmailServerPortResolver::resolve('imap', '1143', 2993));
		$this->assertSame(9995, EmailServerPortResolver::resolve('pop3s', '9995', null));
	}

	public function testImapProfilePortIsUsedWhenAccountPortIsBlankOrInvalid(): void
	{
		$this->assertSame(2993, EmailServerPortResolver::resolve('imaps', '', '2993'));
		$this->assertSame(2993, EmailServerPortResolver::resolve('imap', '70000', '2993'));
	}

	public function testInvalidPortsFallBackToProtocolDefaults(): void
	{
		$this->assertSame(143, EmailServerPortResolver::resolve('imap', '0', null));
		$this->assertSame(995, EmailServerPortResolver::resolve('pop3s', 'abc', 2993));
		$this->assertSame(119, EmailServerPortResolver::resolve('nntp', null, null));
	}
}
