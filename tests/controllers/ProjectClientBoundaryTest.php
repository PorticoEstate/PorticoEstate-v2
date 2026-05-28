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

		public function testUiprojectEditFormSaveActionDoesNotUseLegacySaveMenuaction(): void
		{
			$uiProjectPath = __DIR__ . '/../../src/modules/property/inc/class.uiproject.inc.php';
			$contents = file_get_contents($uiProjectPath);

			$this->assertIsString($contents);
			$this->assertStringNotContainsString(
				"'menuaction' => 'property.uiproject.save'",
				$contents,
				'Project edit form save action should use REST endpoint, not property.uiproject.save menuaction'
			);
		}

		public function testUiprojectIndexDownloadUsesRestReportEndpoint(): void
		{
			$uiProjectPath = __DIR__ . '/../../src/modules/property/inc/class.uiproject.inc.php';
			$contents = file_get_contents($uiProjectPath);

			$this->assertIsString($contents);
			$this->assertStringContainsString(
				"'/property/project/reports/download'",
				$contents,
				'Project index download action must use REST report endpoint'
			);
			$this->assertStringNotContainsString(
				"'menuaction'\t => 'property.uiproject.download'",
				$contents,
				'Project index download must not use legacy uiproject.download menuaction'
			);
		}

		public function testWorkorderAddInvoiceUsesProjectRestExternalProjectLookup(): void
		{
			$workorderAddInvoicePath = __DIR__ . '/../../src/modules/property/js/base/workorder.add_invoice.js';
			$contents = file_get_contents($workorderAddInvoicePath);

			$this->assertIsString($contents);
			$this->assertStringContainsString(
				"phpGWLink('property/project/external-project'",
				$contents,
				'Workorder add invoice must use project REST external-project lookup endpoint'
			);
			$this->assertStringNotContainsString(
				"menuaction: 'property.uiproject.get_external_project'",
				$contents,
				'Workorder add invoice must not call legacy uiproject.get_external_project menuaction'
			);
		}

		public function testUiprojectPublicFunctionsDisableMigratedDependencyEndpoints(): void
		{
			$uiProjectPath = __DIR__ . '/../../src/modules/property/inc/class.uiproject.inc.php';
			$contents = file_get_contents($uiProjectPath);

			$this->assertIsString($contents);

			$expectedDisabled = array(
				"'view_file'\t\t\t\t\t\t => false",
				"'view_image'\t\t\t\t\t => false",
				"'get_orders'\t\t\t\t\t => false",
				"'get_vouchers'\t\t\t\t\t => false",
				"'get_files'\t\t\t\t\t\t => false",
				"'update_file_data'\t\t\t\t => false",
				"'get_other_projects'\t\t\t => false",
				"'get_attachment'\t\t\t\t => false",
				"'check_missing_project_budget'\t => false",
				"'get_external_project'\t\t\t => false",
				"'get_ecodimb'\t\t\t\t\t => false",
			);

			foreach ($expectedDisabled as $needle)
			{
				$this->assertStringContainsString($needle, $contents, "Expected disabled public_functions entry missing: {$needle}");
			}
		}
	}
}
