<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use PHPUnit\Framework\TestCase;

	class ProjectClientBoundaryTest extends TestCase
	{
		public function testProjectEditDoesNotUseLegacyMenuactionDataEndpoints(): void
		{
			$projectEditPath = __DIR__ . '/../../src/modules/property/js/base/project.edit.js';
			$contents = file_get_contents($projectEditPath);

			$this->assertIsString($contents);
			$contentsWithoutComments = (string)preg_replace('#/\*.*?\*/#s', '', $contents);
			$contentsWithoutComments = (string)preg_replace('#^\s*//.*$#m', '', $contentsWithoutComments);

			$forbiddenEndpoints = array(
				"menuaction: 'property.boworkorder.get_category'",
				"menuaction: 'property.uiworkorder.get_b_account'",
				"menuaction: 'property.uiproject.get_ecodimb'",
				"menuaction: 'property.notify.update_data'",
			);

			foreach ($forbiddenEndpoints as $forbidden)
			{
				$this->assertStringNotContainsString(
					$forbidden,
					$contentsWithoutComments,
					"Legacy data endpoint found in project client: {$forbidden}"
				);
			}
		}

		public function testUiprojectNotifyTableDoesNotUseNotifyUpdateDataMenuaction(): void
		{
			$uiProjectPath = __DIR__ . '/../../src/modules/property/inc/class.uiproject.inc.php';
			$contents = file_get_contents($uiProjectPath);

			$this->assertIsString($contents);
			$this->assertStringNotContainsString(
				"'menuaction'\t\t => 'property.notify.update_data'",
				$contents,
				'Notify table requestUrl should use REST endpoint, not property.notify.update_data menuaction'
			);
		}
	}
}
