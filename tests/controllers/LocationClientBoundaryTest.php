<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use PHPUnit\Framework\TestCase;

	class LocationClientBoundaryTest extends TestCase
	{
		public function testLocationEditUsesRestSaveEndpoints(): void
		{
			$path = __DIR__ . '/../../src/modules/property/js/base/location.edit.js';
			$contents = (string)file_get_contents($path);

			$this->assertStringContainsString('function createLocationApiClient(form)', $contents);
			$this->assertStringContainsString("requestUrl = isUpdate", $contents);
			$this->assertStringContainsString("? '/property/location/' + encodeURIComponent(originalLocationCode)", $contents);
			$this->assertStringContainsString(": '/property/location';", $contents);
			$this->assertStringContainsString("method: isUpdate ? 'PUT' : 'POST'", $contents);
			$this->assertStringContainsString('fetch(restRequest.url, {', $contents);

			$this->assertStringNotContainsString("menuaction: 'property.uilocation.save'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.bolocation.save'", $contents);
		}

		public function testLocationNavigationStillUsesLegacyMenuaction(): void
		{
			$editPath = __DIR__ . '/../../src/modules/property/js/base/location.edit.js';
			$editContents = (string)file_get_contents($editPath);
			$this->assertStringContainsString('createLocationNavigationClient(form)', $editContents);
			$this->assertStringContainsString('PorticoBoundaryClients.createLocationClients', $editContents);

			$boundaryPath = __DIR__ . '/../../src/modules/property/js/base/navigation-api-boundary.js';
			$boundaryContents = (string)file_get_contents($boundaryPath);
			$this->assertStringContainsString("menuaction: 'property.uilocation.edit'", $boundaryContents);
		}

		public function testLocationRoutesExposeRestSaveEndpoints(): void
		{
			$routesPath = __DIR__ . '/../../src/modules/property/routes/Routes.php';
			$contents = (string)file_get_contents($routesPath);

			$this->assertStringContainsString("\$group->post('/add', [\$controller, 'add']);", $contents);
			$this->assertStringContainsString("\$group->put('/{location_code:[^/]+}', [\$controller, 'save']);", $contents);
		}

		public function testUilocationEditFormActionUsesRestEndpoint(): void
		{
			$uiPath = __DIR__ . '/../../src/modules/property/inc/class.uilocation.inc.php';
			$contents = (string)file_get_contents($uiPath);

			$this->assertStringContainsString("rest-client-utils.js", $contents);
			$this->assertStringContainsString("\$rest_form_action = \$location_code", $contents);
			$this->assertStringContainsString("phpgw::link('/property/location/' . urlencode(\$location_code), \$rest_action_params)", $contents);
			$this->assertStringContainsString("phpgw::link('/property/location', \$rest_action_params)", $contents);
			$this->assertStringContainsString("'form_action'\t\t\t\t\t => \$rest_form_action", $contents);
		}
	}
}
