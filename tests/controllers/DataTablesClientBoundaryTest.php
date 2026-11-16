<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use PHPUnit\Framework\TestCase;

	class DataTablesClientBoundaryTest extends TestCase
	{
		public function testApplicationScriptsDoNotRetrieveTablesWithLegacyJqueryApi(): void
		{
			$paths = glob(
				__DIR__ . '/../../src/modules/*/js/{base,jquery,activitycalendar}/*.js',
				GLOB_BRACE
			);

			$this->assertNotEmpty($paths);
			foreach ($paths as $path)
			{
				if (str_ends_with($path, '.min.js'))
				{
					continue;
				}

				$contents = file_get_contents($path);
				$this->assertIsString($contents);
				$this->assertDoesNotMatchRegularExpression(
					'/\.\s*dataTable\s*\(\s*\)/',
					$contents,
					"Use new DataTable(selector) to retrieve the table API in {$path}"
				);
			}
		}
	}
}
