<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\services\ScimFilterParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScimFilterParserTest extends TestCase
{
	public function testParsesSupportedEqualityFilter(): void
	{
		$result = (new ScimFilterParser())->parse('userName eq "ola.nordmann"');

		$this->assertSame(['attribute' => 'userName', 'value' => 'ola.nordmann'], $result);
	}

	public function testEmptyFilterReturnsNull(): void
	{
		$this->assertNull((new ScimFilterParser())->parse(null));
	}

	public function testUnsupportedFilterIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		(new ScimFilterParser())->parse('userName co "ola"');
	}
}