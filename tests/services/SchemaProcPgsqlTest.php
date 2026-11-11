<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\services\SchemaProc\SchemaProcPgsql;
use PHPUnit\Framework\TestCase;

class SchemaProcPgsqlTest extends TestCase
{
	public function testBlobMapsToByteaInBothDirections(): void
	{
		$schema = new SchemaProcPgsql();
		$this->assertSame('bytea', $schema->TranslateType('blob'));
		$this->assertSame("'type' => 'blob'", $schema->rTranslateType('bytea'));
	}

	public function testTextMappingIsUnchanged(): void
	{
		$schema = new SchemaProcPgsql();
		$this->assertSame('text', $schema->TranslateType('text'));
		$this->assertSame("'type' => 'text'", $schema->rTranslateType('text'));
	}
}